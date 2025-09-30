<?php
session_start();

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php"; // MySQLi connection in $conn

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values - Set to single date by default
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Validate date range
if (strtotime($from_date) > strtotime($to_date)) {
    $from_date = $to_date; // Ensure from_date is not after to_date
}

// Fetch company name and license number
$companyName = "DIAMOND WINE SHOP";
$licenseNo = "3";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
    $licenseNo = $row['COMP_FLNO'] ? $row['COMP_FLNO'] : $licenseNo;
}
$companyStmt->close();

// Function to group sizes by base size (remove suffixes after ML and trim)
function getBaseSize($size) {
    // Extract the base size (everything before any special characters after ML)
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize); // Remove trailing - numbers
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize); // Remove trailing (numbers)
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize); // Remove anything in parentheses
    return trim($baseSize);
}

// Define size columns for each liquor type exactly as they appear in FLR Datewise
$size_columns_s = [
    '2000 ML Pet (6)', '2000 ML(4)', '2000 ML(6)', '1000 ML(Pet)', '1000 ML',
    '750 ML(6)', '750 ML (Pet)', '750 ML', '700 ML', '700 ML(6)',
    '375 ML (12)', '375 ML', '375 ML (Pet)', '350 ML (12)', '275 ML(24)',
    '200 ML (48)', '200 ML (24)', '200 ML (30)', '200 ML (12)', '180 ML(24)',
    '180 ML (Pet)', '180 ML', '90 ML(100)', '90 ML (Pet)-100', '90 ML (Pet)-96', 
    '90 ML-(96)', '90 ML', '60 ML', '60 ML (75)', '50 ML(120)', '50 ML (180)', 
    '50 ML (24)', '50 ML (192)'
];
$size_columns_w = ['750 ML(6)', '750 ML', '650 ML', '375 ML', '330 ML', '180 ML'];
$size_columns_fb = ['650 ML', '500 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];
$size_columns_mb = ['650 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];

// Group sizes by base size for each liquor type
function groupSizes($sizes) {
    $grouped = [];
    foreach ($sizes as $size) {
        $baseSize = getBaseSize($size);
        if (!isset($grouped[$baseSize])) {
            $grouped[$baseSize] = [];
        }
        $grouped[$baseSize][] = $size;
    }
    return $grouped;
}

$grouped_sizes_s = groupSizes($size_columns_s);
$grouped_sizes_w = groupSizes($size_columns_w);
$grouped_sizes_fb = groupSizes($size_columns_fb);
$grouped_sizes_mb = groupSizes($size_columns_mb);

// Get display sizes (base sizes) for each liquor type - ORDER: Spirit, Wine, Fermented Beer, Mild Beer
$display_sizes_s = array_keys($grouped_sizes_s);
$display_sizes_w = array_keys($grouped_sizes_w);
$display_sizes_fb = array_keys($grouped_sizes_fb);
$display_sizes_mb = array_keys($grouped_sizes_mb);

// Fetch class data to map liquor types
$classData = [];
$classQuery = "SELECT SGROUP, `DESC`, LIQ_FLAG FROM tblclass";
$classStmt = $conn->prepare($classQuery);
$classStmt->execute();
$classResult = $classStmt->get_result();
while ($row = $classResult->fetch_assoc()) {
    $classData[$row['SGROUP']] = $row;
}
$classStmt->close();

// Fetch item master data with size information
$items = [];
$itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster";
$itemStmt = $conn->prepare($itemQuery);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();
while ($row = $itemResult->fetch_assoc()) {
    $items[$row['CODE']] = $row;
}
$itemStmt->close();

// Initialize report data structure
$dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// Initialize daily data structure for each date - ORDER: Spirit, Wine, Fermented Beer, Mild Beer
$daily_data = [];

// Initialize T.P. Nos data
$tp_nos_data = [];

// Map database sizes to Excel column sizes
$size_mapping = [
    // Spirits
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '90 ML' => '90 ML',
    '90 ML-100' => '90 ML',
    '90 ML-96' => '90 ML',
    '2000 ML' => '2000 ML',
    '2000 ML Pet' => '2000 ML',
    
    // Wines
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '650 ML' => '650 ML',
    '330 ML' => '330 ML',
    '180 ML' => '180 ML',
    
    // Fermented Beer
    '650 ML' => '650 ML',
    '500 ML' => '500 ML',
    '500 ML (CAN)' => '500 ML (CAN)',
    '330 ML' => '330 ML',
    '330 ML (CAN)' => '330 ML (CAN)',
    
    // Mild Beer
    '650 ML' => '650 ML',
    '500 ML (CAN)' => '500 ML (CAN)',
    '330 ML' => '330 ML',
    '330 ML (CAN)' => '330 ML (CAN)'
];

// Function to determine liquor type based on CLASS and LIQ_FLAG
function getLiquorType($class, $liq_flag) {
    if ($liq_flag == 'F') {
        switch ($class) {
            case 'F': return 'Fermented Beer';
            case 'M': return 'Mild Beer';
            case 'V': return 'Wines';
            default: return 'Spirits';
        }
    }
    return 'Spirits'; // Default for non-F items
}

// Function to get base size for grouping
function getGroupedSize($size, $liquor_type) {
    global $grouped_sizes_s, $grouped_sizes_w, $grouped_sizes_fb, $grouped_sizes_mb;
    
    $baseSize = getBaseSize($size);
    
    // Check if this base size exists in the appropriate group
    switch ($liquor_type) {
        case 'Spirits':
            if (in_array($baseSize, array_keys($grouped_sizes_s))) {
                return $baseSize;
            }
            break;
        case 'Wines':
            if (in_array($baseSize, array_keys($grouped_sizes_w))) {
                return $baseSize;
            }
            break;
        case 'Fermented Beer':
            if (in_array($baseSize, array_keys($grouped_sizes_fb))) {
                return $baseSize;
            }
            break;
        case 'Mild Beer':
            if (in_array($baseSize, array_keys($grouped_sizes_mb))) {
                return $baseSize;
            }
            break;
    }
    
    return $baseSize; // Return base size even if not found in predefined groups
}

// Function to get table name for a specific date
function getTableForDate($conn, $compID, $date) {
    $current_month = date('Y-m');
    $target_month = date('Y-m', strtotime($date));
    
    // If current month, use main table
    if ($target_month == $current_month) {
        $tableName = "tbldailystock_" . $compID;
    } else {
        // For previous months, use archive table format: tbldailystock_compID_MM_YY
        $month = date('m', strtotime($date));
        $year = date('y', strtotime($date));
        $tableName = "tbldailystock_" . $compID . "_" . $month . "_" . $year;
    }
    
    // Check if table exists
    $tableCheckQuery = "SHOW TABLES LIKE '$tableName'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult->num_rows == 0) {
        // If archive table doesn't exist, fall back to main table
        $tableName = "tbldailystock_" . $compID;
        
        // Check if main table exists, if not use default
        $tableCheckQuery2 = "SHOW TABLES LIKE '$tableName'";
        $tableCheckResult2 = $conn->query($tableCheckQuery2);
        if ($tableCheckResult2->num_rows == 0) {
            $tableName = "tbldailystock_1";
        }
    }
    
    return $tableName;
}

// Function to check if table has specific day columns
function tableHasDayColumns($conn, $tableName, $day) {
    $day_padded = sprintf('%02d', $day);
    
    // Check if all required columns for this day exist
    $columns_to_check = [
        "DAY_{$day_padded}_OPEN",
        "DAY_{$day_padded}_PURCHASE", 
        "DAY_{$day_padded}_SALES",
        "DAY_{$day_padded}_CLOSING"
    ];
    
    foreach ($columns_to_check as $column) {
        $checkColumnQuery = "SHOW COLUMNS FROM $tableName LIKE '$column'";
        $columnResult = $conn->query($checkColumnQuery);
        if ($columnResult->num_rows == 0) {
            return false; // Column doesn't exist
        }
    }
    
    return true; // All columns exist
}

// Fetch T.P. Nos from tblpurchases for each date
foreach ($dates as $date) {
    $tpQuery = "SELECT DISTINCT TPNO FROM tblpurchases WHERE DATE = ? AND CompID = ?";
    $tpStmt = $conn->prepare($tpQuery);
    $tpStmt->bind_param("si", $date, $compID);
    $tpStmt->execute();
    $tpResult = $tpStmt->get_result();
    
    $tp_nos = [];
    while ($row = $tpResult->fetch_assoc()) {
        if (!empty($row['TPNO'])) {
            $tp_nos[] = $row['TPNO'];
        }
    }
    
    $tp_nos_data[$date] = $tp_nos;
    $tpStmt->close();
}

// Process each date in the range with month-aware logic
foreach ($dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    
    // Get appropriate table for this date
    $dailyStockTable = getTableForDate($conn, $compID, $date);
    
    // Check if this specific table has columns for this specific day
    if (!tableHasDayColumns($conn, $dailyStockTable, $day)) {
        // Skip this date as the table doesn't have columns for this day
        continue;
    }
    
    $day_padded = sprintf('%02d', $day);
    
    // Initialize daily data for this date
    $daily_data[$date] = [
        'Spirits' => [
            'opening' => array_fill_keys($display_sizes_s, 0),
            'purchase' => array_fill_keys($display_sizes_s, 0),
            'sales' => array_fill_keys($display_sizes_s, 0),
            'closing' => array_fill_keys($display_sizes_s, 0)
        ],
        'Wines' => [
            'opening' => array_fill_keys($display_sizes_w, 0),
            'purchase' => array_fill_keys($display_sizes_w, 0),
            'sales' => array_fill_keys($display_sizes_w, 0),
            'closing' => array_fill_keys($display_sizes_w, 0)
        ],
        'Fermented Beer' => [
            'opening' => array_fill_keys($display_sizes_fb, 0),
            'purchase' => array_fill_keys($display_sizes_fb, 0),
            'sales' => array_fill_keys($display_sizes_fb, 0),
            'closing' => array_fill_keys($display_sizes_fb, 0)
        ],
        'Mild Beer' => [
            'opening' => array_fill_keys($display_sizes_mb, 0),
            'purchase' => array_fill_keys($display_sizes_mb, 0),
            'sales' => array_fill_keys($display_sizes_mb, 0),
            'closing' => array_fill_keys($display_sizes_mb, 0)
        ]
    ];
    
    // Fetch stock data for this specific day
    $stockQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                  DAY_{$day_padded}_OPEN as opening,
                  DAY_{$day_padded}_PURCHASE as purchase, 
                  DAY_{$day_padded}_SALES as sales, 
                  DAY_{$day_padded}_CLOSING as closing 
                  FROM $dailyStockTable 
                  WHERE STK_MONTH = ?";
    
    $stockStmt = $conn->prepare($stockQuery);
    $stockStmt->bind_param("s", $month);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    
    while ($row = $stockResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        // Skip if item not found in master
        if (!isset($items[$item_code])) continue;
        
        $item_details = $items[$item_code];
        $size = $item_details['DETAILS2'];
        $class = $item_details['CLASS'];
        $liq_flag = $item_details['LIQ_FLAG'];
        
        // Determine liquor type
        $liquor_type = getLiquorType($class, $liq_flag);
        
        // Map database size to Excel size
        $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
        
        // Get grouped size for display
        $grouped_size = getGroupedSize($excel_size, $liquor_type);
        
        // Add to daily data based on liquor type and grouped size
        switch ($liquor_type) {
            case 'Spirits':
                if (in_array($grouped_size, $display_sizes_s)) {
                    $daily_data[$date]['Spirits']['opening'][$grouped_size] += $row['opening'];
                    $daily_data[$date]['Spirits']['purchase'][$grouped_size] += $row['purchase'];
                    $daily_data[$date]['Spirits']['sales'][$grouped_size] += $row['sales'];
                    $daily_data[$date]['Spirits']['closing'][$grouped_size] += $row['closing'];
                }
                break;
                
            case 'Wines':
                if (in_array($grouped_size, $display_sizes_w)) {
                    $daily_data[$date]['Wines']['opening'][$grouped_size] += $row['opening'];
                    $daily_data[$date]['Wines']['purchase'][$grouped_size] += $row['purchase'];
                    $daily_data[$date]['Wines']['sales'][$grouped_size] += $row['sales'];
                    $daily_data[$date]['Wines']['closing'][$grouped_size] += $row['closing'];
                }
                break;
                
            case 'Fermented Beer':
                if (in_array($grouped_size, $display_sizes_fb)) {
                    $daily_data[$date]['Fermented Beer']['opening'][$grouped_size] += $row['opening'];
                    $daily_data[$date]['Fermented Beer']['purchase'][$grouped_size] += $row['purchase'];
                    $daily_data[$date]['Fermented Beer']['sales'][$grouped_size] += $row['sales'];
                    $daily_data[$date]['Fermented Beer']['closing'][$grouped_size] += $row['closing'];
                }
                break;
                
            case 'Mild Beer':
                if (in_array($grouped_size, $display_sizes_mb)) {
                    $daily_data[$date]['Mild Beer']['opening'][$grouped_size] += $row['opening'];
                    $daily_data[$date]['Mild Beer']['purchase'][$grouped_size] += $row['purchase'];
                    $daily_data[$date]['Mild Beer']['sales'][$grouped_size] += $row['sales'];
                    $daily_data[$date]['Mild Beer']['closing'][$grouped_size] += $row['closing'];
                }
                break;
        }
    }
    
    $stockStmt->close();
}

// Calculate total columns count for table formatting
$total_columns = count($display_sizes_s) + count($display_sizes_w) + count($display_sizes_fb) + count($display_sizes_mb);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Excise Register (FLR-3) - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  
  <style>
    /* Screen styles */
    body {
      font-size: 12px;
      background-color: #f8f9fa;
    }
    .company-header {
      text-align: center;
      margin-bottom: 15px;
      padding: 10px;
    }
    .company-header h1 {
      font-size: 18px;
      font-weight: bold;
      margin-bottom: 5px;
    }
    .company-header h5 {
      font-size: 14px;
      margin-bottom: 3px;
    }
    .company-header h6 {
      font-size: 12px;
      margin-bottom: 5px;
    }
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 15px;
      font-size: 10px;
    }
    .report-table th, .report-table td {
      border: 1px solid #000;
      padding: 4px;
      text-align: center;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.2;
    }
    .report-table th {
      background-color: #f0f0f0;
      font-weight: bold;
      padding: 6px 3px;
    }
    .vertical-text {
      writing-mode: vertical-lr;
      transform: rotate(180deg);
      text-align: center;
      white-space: nowrap;
      padding: 8px 2px;
      min-width: 20px;
    }
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
    }
    .filter-card {
      background-color: #f8f9fa;
    }
    .table-responsive {
      overflow-x: auto;
      max-width: 100%;
    }
    .action-controls {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .no-print {
      display: block;
    }
    .tp-nos {
      font-size: 8px;
      line-height: 1.1;
      text-align: left;
      padding: 2px;
    }
    .tp-nos span {
      display: inline-block;
      margin-right: 3px;
    }
    .empty-row td {
      height: 10px;
      background-color: #f9f9f9;
    }

  /* Print styles */
    @media print {
      @page {
        size: legal landscape;
        margin: 0.2in;
      }
      
      body {
        margin: 0;
        padding: 0;
        font-size: 8px;
        line-height: 1;
        background: white;
        width: 100%;
        height: 100%;
        transform: scale(0.8);
        transform-origin: 0 0;
        width: 125%;
      }
      
      .no-print {
        display: none !important;
      }
      
      body * {
        visibility: hidden;
      }
      
      .print-section, .print-section * {
        visibility: visible;
      }
      
      .print-section {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
      }
      
      .company-header {
        text-align: center;
        margin-bottom: 5px;
        padding: 2px;
        page-break-after: avoid;
      }
      
      .company-header h1 {
        font-size: 12px !important;
        margin-bottom: 1px !important;
      }
      
      .company-header h5 {
        font-size: 9px !important;
        margin-bottom: 1px !important;
      }
      
      .company-header h6 {
        font-size: 8px !important;
        margin-bottom: 2px !important;
      }
      
      .table-responsive {
        overflow: visible;
        width: 100%;
        height: auto;
      }
      
      .report-table {
        width: 100% !important;
        font-size: 6px !important;
        table-layout: fixed;
        border-collapse: collapse;
        page-break-inside: avoid;
      }
      
      .report-table th, .report-table td {
        padding: 1px !important;
        line-height: 1;
        height: 14px;
        min-width: 18px;
        max-width: 22px;
        font-size: 6px !important;
        border: 0.5px solid #000 !important;
      }
      
      .report-table th {
        background-color: #f0f0f0 !important;
        padding: 2px 1px !important;
        font-weight: bold;
      }
      
      .vertical-text {
        writing-mode: vertical-lr;
        transform: rotate(180deg);
        text-align: center;
        white-space: nowrap;
        padding: 1px !important;
        font-size: 5px !important;
        min-width: 15px;
        max-width: 18px;
        line-height: 1;
        height: 25px !important;
      }
      
      .date-col, .permit-col {
        width: 25px !important;
        min-width: 25px !important;
        max-width: 25px !important;
      }
      
      .size-col {
        width: 18px !important;
        min-width: 18px !important;
        max-width: 18px !important;
      }
      
      .summary-row {
        background-color: #f8f9fa !important;
        font-weight: bold;
      }
      
      .tp-nos {
        font-size: 5px !important;
        line-height: 1;
        padding: 1px !important;
      }
      
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 6px;
        page-break-before: avoid;
      }
      
      tr {
        page-break-inside: avoid;
        page-break-after: auto;
      }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Excise Register (FLR-3) Printing Module</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-control">
                  <option value="Foreign Liquor" <?= $mode == 'Foreign Liquor' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="Country Liquor" <?= $mode == 'Country Liquor' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">From Date:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>" max="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>" max="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date Range Info:</label>
                <div class="form-control-plaintext">
                  <small class="text-muted">Selected: <?= count($dates) ?> day(s)</small>
                </div>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate Report
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
              </button>
              <button type="button" class="btn btn-info" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-1"></i> Export to Excel
              </button>
              <a href="dashboard.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-times me-1"></i> Exit
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Report Results -->
      <div class="print-section">
        <div class="company-header">
          <h1>Excise Register (FLR-3)</h1>
          <h5>Mode: <?= htmlspecialchars($mode) ?></h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
        </div>
        
        <?php if (empty($dates) || (count($dates) == 1 && !isset($daily_data[$dates[0]]))): ?>
          <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No data available for the selected date range.
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="report-table" id="excise-register-table">
              <thead>
                <tr>
                  <th rowspan="3" class="date-col">Date</th>
                  <th rowspan="3" class="permit-col">T. P. Nos</th>
                  <th colspan="<?= count($display_sizes_s) ?>">SPIRIT S</th>
                  <th colspan="<?= count($display_sizes_w) ?>">WINE</th>
                  <th colspan="<?= count($display_sizes_fb) ?>">FERMENTED BEER</th>
                  <th colspan="<?= count($display_sizes_mb) ?>">MILD BEER</th>
                </tr>
                <tr>
                  <!-- Spirits Sizes -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <th class="size-col vertical-text"><?= $size ?></th>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sizes -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <th class="size-col vertical-text"><?= $size ?></th>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sizes -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <th class="size-col vertical-text"><?= $size ?></th>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sizes -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <th class="size-col vertical-text"><?= $size ?></th>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <!-- Spirits Sizes (Second row for headers) -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <th class="size-col"><?= $size ?></th>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sizes -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <th class="size-col"><?= $size ?></th>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sizes -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <th class="size-col"><?= $size ?></th>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sizes -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <th class="size-col"><?= $size ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <!-- For each date in the range -->
                <?php 
                $date_count = 0;
                foreach ($dates as $date): 
                  // Skip if this date was not processed due to missing columns
                  if (!isset($daily_data[$date])) continue;
                  
                  $day_num = date('d', strtotime($date));
                  $tp_nos = $tp_nos_data[$date];
                  $date_count++;
                ?>
                  <!-- First row for the date with T.P. Nos and Opening Stock -->
                  <tr>
                    <td><?= $day_num ?></td>
                    <td class="tp-nos">
                      <?php if (!empty($tp_nos)): ?>
                        <?php foreach ($tp_nos as $tp_no): ?>
                          <span><?= $tp_no ?></span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        &nbsp;
                      <?php endif; ?>
                    </td>
                    
                    <!-- Spirits Opening Stock -->
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td><?= $daily_data[$date]['Spirits']['opening'][$size] > 0 ? $daily_data[$date]['Spirits']['opening'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Wines Opening Stock -->
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td><?= $daily_data[$date]['Wines']['opening'][$size] > 0 ? $daily_data[$date]['Wines']['opening'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Fermented Beer Opening Stock -->
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td><?= $daily_data[$date]['Fermented Beer']['opening'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['opening'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Mild Beer Opening Stock -->
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td><?= $daily_data[$date]['Mild Beer']['opening'][$size] > 0 ? $daily_data[$date]['Mild Beer']['opening'][$size] : '' ?></td>
                    <?php endforeach; ?>
                  </tr>
                  
                  <!-- Second row for Received (Purchase) -->
                  <tr>
                    <td></td>
                    <td>Rec.</td>
                    
                    <!-- Spirits Received -->
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td><?= $daily_data[$date]['Spirits']['purchase'][$size] > 0 ? $daily_data[$date]['Spirits']['purchase'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Wines Received -->
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td><?= $daily_data[$date]['Wines']['purchase'][$size] > 0 ? $daily_data[$date]['Wines']['purchase'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Fermented Beer Received -->
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td><?= $daily_data[$date]['Fermented Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['purchase'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Mild Beer Received -->
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td><?= $daily_data[$date]['Mild Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Mild Beer']['purchase'][$size] : '' ?></td>
                    <?php endforeach; ?>
                  </tr>
                  
                  <!-- Third row for Issued (Sales) -->
                  <tr>
                    <td></td>
                    <td>Iss.</td>
                    
                    <!-- Spirits Issued -->
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td><?= $daily_data[$date]['Spirits']['sales'][$size] > 0 ? $daily_data[$date]['Spirits']['sales'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Wines Issued -->
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td><?= $daily_data[$date]['Wines']['sales'][$size] > 0 ? $daily_data[$date]['Wines']['sales'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Fermented Beer Issued -->
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td><?= $daily_data[$date]['Fermented Beer']['sales'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['sales'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Mild Beer Issued -->
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td><?= $daily_data[$date]['Mild Beer']['sales'][$size] > 0 ? $daily_data[$date]['Mild Beer']['sales'][$size] : '' ?></td>
                    <?php endforeach; ?>
                  </tr>
                  
                  <!-- Fourth row for Closing Stock -->
                  <tr>
                    <td></td>
                    <td>Cl. Stk.</td>
                    
                    <!-- Spirits Closing Stock -->
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td><?= $daily_data[$date]['Spirits']['closing'][$size] > 0 ? $daily_data[$date]['Spirits']['closing'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Wines Closing Stock -->
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td><?= $daily_data[$date]['Wines']['closing'][$size] > 0 ? $daily_data[$date]['Wines']['closing'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Fermented Beer Closing Stock -->
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td><?= $daily_data[$date]['Fermented Beer']['closing'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['closing'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    
                    <!-- Mild Beer Closing Stock -->
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td><?= $daily_data[$date]['Mild Beer']['closing'][$size] > 0 ? $daily_data[$date]['Mild Beer']['closing'][$size] : '' ?></td>
                    <?php endforeach; ?>
                  </tr>
                  
                  <!-- Empty row for spacing -->
                  <tr class="empty-row">
                    <td colspan="<?= 2 + $total_columns ?>">&nbsp;</td>
                  </tr>
                <?php endforeach; ?>
                
                <!-- Summary row -->
                <?php if ($date_count > 0): ?>
                  <tr class="summary-row">
                    <td colspan="2">TOTAL</td>
                    
                    <!-- Spirits Totals -->
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td>
                        <?php
                        $total = 0;
                        foreach ($dates as $date) {
                          if (isset($daily_data[$date])) {
                            $total += $daily_data[$date]['Spirits']['sales'][$size];
                          }
                        }
                        echo $total > 0 ? $total : '';
                        ?>
                      </td>
                    <?php endforeach; ?>
                    
                    <!-- Wines Totals -->
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td>
                        <?php
                        $total = 0;
                        foreach ($dates as $date) {
                          if (isset($daily_data[$date])) {
                            $total += $daily_data[$date]['Wines']['sales'][$size];
                          }
                        }
                        echo $total > 0 ? $total : '';
                        ?>
                      </td>
                    <?php endforeach; ?>
                    
                    <!-- Fermented Beer Totals -->
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td>
                        <?php
                        $total = 0;
                        foreach ($dates as $date) {
                          if (isset($daily_data[$date])) {
                            $total += $daily_data[$date]['Fermented Beer']['sales'][$size];
                          }
                        }
                        echo $total > 0 ? $total : '';
                        ?>
                      </td>
                    <?php endforeach; ?>
                    
                    <!-- Mild Beer Totals -->
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td>
                        <?php
                        $total = 0;
                        foreach ($dates as $date) {
                          if (isset($daily_data[$date])) {
                            $total += $daily_data[$date]['Mild Beer']['sales'][$size];
                          }
                        }
                        echo $total > 0 ? $total : '';
                        ?>
                      </td>
                    <?php endforeach; ?>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          
          <div class="footer-info">
            <p>Generated on: <?= date('d-M-Y H:i:s') ?> | Total Records: <?= $date_count ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportToExcel() {
  // Get the current date range for filename
  const fromDate = "<?= $from_date ?>";
  const toDate = "<?= $to_date ?>";
  const mode = "<?= $mode ?>";
  
  // Create a filename
  const filename = `FLR3_${mode}_${fromDate}_to_${toDate}.xlsx`;
  
  // Create a temporary table for export (without print-specific styles)
  const originalTable = document.getElementById('excise-register-table');
  const tempTable = originalTable.cloneNode(true);
  
  // Remove print-specific classes and styles
  tempTable.classList.remove('report-table');
  tempTable.querySelectorAll('.vertical-text').forEach(cell => {
    cell.style.writingMode = 'horizontal-tb';
    cell.style.transform = 'none';
    cell.classList.remove('vertical-text');
  });
  
  // Create HTML content for export
  const htmlContent = `
    <html>
    <head>
      <meta charset="UTF-8">
      <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid black; padding: 4px; text-align: center; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .summary-row { background-color: #e9ecef; font-weight: bold; }
      </style>
    </head>
    <body>
      <h2>Excise Register (FLR-3)</h2>
      <h3>Mode: ${mode}</h3>
      <h4><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h4>
      <h4>From Date: <?= date('d-M-Y', strtotime($from_date)) ?> To Date: <?= date('d-M-Y', strtotime($to_date)) ?></h4>
      ${tempTable.outerHTML}
      <p>Generated on: <?= date('d-M-Y H:i:s') ?></p>
    </body>
    </html>
  `;
  
  // Create blob and download
  const blob = new Blob([htmlContent], { type: 'application/vnd.ms-excel' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
</script>
</body>
</html>