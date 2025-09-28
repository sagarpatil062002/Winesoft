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

// Default values
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

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

// Determine which daily stock table to use based on company ID
$dailyStockTable = "tbldailystock_" . $compID;

// Check if the table exists, if not use default tbldailystock_1
$tableCheckQuery = "SHOW TABLES LIKE '$dailyStockTable'";
$tableCheckResult = $conn->query($tableCheckQuery);
if ($tableCheckResult->num_rows == 0) {
    $dailyStockTable = "tbldailystock_1";
}

// Check if the table has DAY_31 columns (for months with less than 31 days)
$checkDay31Query = "SHOW COLUMNS FROM $dailyStockTable LIKE 'DAY_31_OPEN'";
$day31Result = $conn->query($checkDay31Query);
$hasDay31Columns = ($day31Result->num_rows > 0);

// Function to group sizes by base size (remove suffixes after ML and trim)
function getBaseSize($size) {
    // Extract the base size (everything before any special characters after ML)
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize); // Remove trailing - numbers
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize); // Remove trailing (numbers)
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize); // Remove anything in parentheses
    return trim($baseSize);
}

// Function to extract brand name from item details
function getBrandName($details) {
    // Remove size patterns (ML, CL, L, etc. with numbers)
    $brandName = preg_replace('/\s*\d+\s*(ML|CL|L).*$/i', '', $details);
    $brandName = preg_replace('/\s*\([^)]*\)\s*$/', '', $brandName); // Remove trailing parentheses
    $brandName = preg_replace('/\s*-\s*\d+$/', '', $brandName); // Remove trailing - numbers
    return trim($brandName);
}

// Define size columns for each liquor type exactly as they appear in Excel
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

// Get display sizes (base sizes) for each liquor type - NEW ORDER: Spirit, Wine, Fermented Beer, Mild Beer
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

// Fetch item master data with size information and extract brand names
$items = [];
$itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster";
$itemStmt = $conn->prepare($itemQuery);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();
while ($row = $itemResult->fetch_assoc()) {
    $items[$row['CODE']] = $row;
}
$itemStmt->close();

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
    
    // Check if this base size exists in the appropriate group - NEW ORDER
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
    
    // Fermented Beer
    '650 ML Bottle' => '650 ML',
    '500 ML Bottle' => '500 ML',
    '500 ML Can' => '500 ML (CAN)',
    '330 ML Bottle' => '330 ML',
    '330 ML Can' => '330 ML (CAN)',
    
    // Mild Beer
    '650 ML Bottle' => '650 ML',
    '500 ML Can' => '500 ML (CAN)',
    '330 ML Bottle' => '330 ML',
    '330 ML Can' => '330 ML (CAN)'
];

// NEW: Restructure data by liquor type -> brand
$brand_data_by_category = [
    'Spirits' => [],
    'Wines' => [],
    'Fermented Beer' => [],
    'Mild Beer' => []
];

// Initialize grand totals
$grand_totals = [
    'Spirits' => [
        'purchase' => array_fill_keys($display_sizes_s, 0),
        'sales' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0),
        'opening' => array_fill_keys($display_sizes_s, 0)
    ],
    'Wines' => [
        'purchase' => array_fill_keys($display_sizes_w, 0),
        'sales' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0),
        'opening' => array_fill_keys($display_sizes_w, 0)
    ],
    'Fermented Beer' => [
        'purchase' => array_fill_keys($display_sizes_fb, 0),
        'sales' => array_fill_keys($display_sizes_fb, 0),
        'closing' => array_fill_keys($display_sizes_fb, 0),
        'opening' => array_fill_keys($display_sizes_fb, 0)
    ],
    'Mild Beer' => [
        'purchase' => array_fill_keys($display_sizes_mb, 0),
        'sales' => array_fill_keys($display_sizes_mb, 0),
        'closing' => array_fill_keys($display_sizes_mb, 0),
        'opening' => array_fill_keys($display_sizes_mb, 0)
    ]
];

// Process each date in the range with month-aware logic
$dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// NEW: Store the latest stock data for each item
$latest_stock_data = [];

foreach ($dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    $days_in_month = date('t', strtotime($date));
    
    // Skip if day exceeds month length AND table doesn't have DAY_31 columns
    if ($day > $days_in_month && !$hasDay31Columns) {
        continue;
    }
    
    // Fetch all stock data for this month
    $stockQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                  DAY_{$day}_OPEN as opening, 
                  DAY_{$day}_PURCHASE as purchase, 
                  DAY_{$day}_SALES as sales, 
                  DAY_{$day}_CLOSING as closing 
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
        
        // Store the latest data for each item (will be overwritten with each subsequent date)
        $latest_stock_data[$item_code] = $row;
    }
    
    $stockStmt->close();
}

// NEW: Process only the latest stock data (last date in the range)
foreach ($latest_stock_data as $item_code => $row) {
    $item_details = $items[$item_code];
    $size = $item_details['DETAILS2'];
    $class = $item_details['CLASS'];
    $liq_flag = $item_details['LIQ_FLAG'];
    
    // Extract brand name
    $brandName = getBrandName($item_details['DETAILS']);
    if (empty($brandName)) continue;
    
    // Determine liquor type
    $liquor_type = getLiquorType($class, $liq_flag);
    
    // Map database size to Excel size
    $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
    
    // Get grouped size for display
    $grouped_size = getGroupedSize($excel_size, $liquor_type);
    
    // Initialize brand data if not exists
    if (!isset($brand_data_by_category[$liquor_type][$brandName])) {
        $brand_data_by_category[$liquor_type][$brandName] = [
            'Spirits' => [
                'purchase' => array_fill_keys($display_sizes_s, 0),
                'sales' => array_fill_keys($display_sizes_s, 0),
                'closing' => array_fill_keys($display_sizes_s, 0),
                'opening' => array_fill_keys($display_sizes_s, 0)
            ],
            'Wines' => [
                'purchase' => array_fill_keys($display_sizes_w, 0),
                'sales' => array_fill_keys($display_sizes_w, 0),
                'closing' => array_fill_keys($display_sizes_w, 0),
                'opening' => array_fill_keys($display_sizes_w, 0)
            ],
            'Fermented Beer' => [
                'purchase' => array_fill_keys($display_sizes_fb, 0),
                'sales' => array_fill_keys($display_sizes_fb, 0),
                'closing' => array_fill_keys($display_sizes_fb, 0),
                'opening' => array_fill_keys($display_sizes_fb, 0)
            ],
            'Mild Beer' => [
                'purchase' => array_fill_keys($display_sizes_mb, 0),
                'sales' => array_fill_keys($display_sizes_mb, 0),
                'closing' => array_fill_keys($display_sizes_mb, 0),
                'opening' => array_fill_keys($display_sizes_mb, 0)
            ]
        ];
    }
    
    // Add to brand data and grand totals based on liquor type and grouped size
    // NOW USING ONLY THE LATEST DATA (not cumulative)
    switch ($liquor_type) {
        case 'Spirits':
            if (in_array($grouped_size, $display_sizes_s)) {
                $brand_data_by_category[$liquor_type][$brandName]['Spirits']['purchase'][$grouped_size] += $row['purchase'];
                $brand_data_by_category[$liquor_type][$brandName]['Spirits']['sales'][$grouped_size] += $row['sales'];
                $brand_data_by_category[$liquor_type][$brandName]['Spirits']['closing'][$grouped_size] = $row['closing'];
                $brand_data_by_category[$liquor_type][$brandName]['Spirits']['opening'][$grouped_size] += $row['opening'];
                
                $grand_totals['Spirits']['purchase'][$grouped_size] += $row['purchase'];
                $grand_totals['Spirits']['sales'][$grouped_size] += $row['sales'];
                $grand_totals['Spirits']['closing'][$grouped_size] += $row['closing'];
                $grand_totals['Spirits']['opening'][$grouped_size] += $row['opening'];
            }
            break;
            
        case 'Wines':
            if (in_array($grouped_size, $display_sizes_w)) {
                $brand_data_by_category[$liquor_type][$brandName]['Wines']['purchase'][$grouped_size] += $row['purchase'];
                $brand_data_by_category[$liquor_type][$brandName]['Wines']['sales'][$grouped_size] += $row['sales'];
                $brand_data_by_category[$liquor_type][$brandName]['Wines']['closing'][$grouped_size] = $row['closing'];
                $brand_data_by_category[$liquor_type][$brandName]['Wines']['opening'][$grouped_size] += $row['opening'];
                
                $grand_totals['Wines']['purchase'][$grouped_size] += $row['purchase'];
                $grand_totals['Wines']['sales'][$grouped_size] += $row['sales'];
                $grand_totals['Wines']['closing'][$grouped_size] += $row['closing'];
                $grand_totals['Wines']['opening'][$grouped_size] += $row['opening'];
            }
            break;
            
        case 'Fermented Beer':
            if (in_array($grouped_size, $display_sizes_fb)) {
                $brand_data_by_category[$liquor_type][$brandName]['Fermented Beer']['purchase'][$grouped_size] += $row['purchase'];
                $brand_data_by_category[$liquor_type][$brandName]['Fermented Beer']['sales'][$grouped_size] += $row['sales'];
                $brand_data_by_category[$liquor_type][$brandName]['Fermented Beer']['closing'][$grouped_size] = $row['closing'];
                $brand_data_by_category[$liquor_type][$brandName]['Fermented Beer']['opening'][$grouped_size] += $row['opening'];
                
                $grand_totals['Fermented Beer']['purchase'][$grouped_size] += $row['purchase'];
                $grand_totals['Fermented Beer']['sales'][$grouped_size] += $row['sales'];
                $grand_totals['Fermented Beer']['closing'][$grouped_size] += $row['closing'];
                $grand_totals['Fermented Beer']['opening'][$grouped_size] += $row['opening'];
            }
            break;
            
        case 'Mild Beer':
            if (in_array($grouped_size, $display_sizes_mb)) {
                $brand_data_by_category[$liquor_type][$brandName]['Mild Beer']['purchase'][$grouped_size] += $row['purchase'];
                $brand_data_by_category[$liquor_type][$brandName]['Mild Beer']['sales'][$grouped_size] += $row['sales'];
                $brand_data_by_category[$liquor_type][$brandName]['Mild Beer']['closing'][$grouped_size] = $row['closing'];
                $brand_data_by_category[$liquor_type][$brandName]['Mild Beer']['opening'][$grouped_size] += $row['opening'];
                
                $grand_totals['Mild Beer']['purchase'][$grouped_size] += $row['purchase'];
                $grand_totals['Mild Beer']['sales'][$grouped_size] += $row['sales'];
                $grand_totals['Mild Beer']['closing'][$grouped_size] += $row['closing'];
                $grand_totals['Mild Beer']['opening'][$grouped_size] += $row['opening'];
            }
            break;
    }
}

// Calculate total columns count for table formatting
$total_columns = count($display_sizes_s) + count($display_sizes_w) + count($display_sizes_fb) + count($display_sizes_mb);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FLR-3A Brandwise Register - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Include shortcuts functionality -->
<script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
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
    .category-header {
      background-color: #d1ecf1;
      font-weight: bold;
      text-align: left;
      padding-left: 10px;
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

    /* Print styles - MODIFIED TO REMOVE PAGE BREAKS */
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
        position: relative;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 0;
        page-break-inside: avoid;
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
        page-break-inside: avoid;
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
      
      /* ONLY INCREASE HEIGHT FOR SIZE COLUMNS */
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
      
      .date-col, .permit-col, .signature-col {
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
      
      .category-header {
        background-color: #d1ecf1 !important;
        font-weight: bold;
        page-break-before: avoid;
      }
      
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 6px;
        page-break-before: avoid;
      }
      
      /* Ensure no page breaks within the table */
      tr {
        page-break-inside: avoid;
        page-break-after: auto;
      }
      
      /* Specifically prevent breaks after category headers */
      .category-header + tr {
        page-break-before: avoid;
      }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">FLR-3A Brandwise Register</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">From Date:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
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
          <h1>Form F.L.R. 3A - Brandwise Register (See Rule 15)</h1>
          <h5>REGISTER OF TRANSACTION OF FOREIGN LIQUOR EFFECTED BY HOLDER OF VENDOR'S/HOTEL/CLUB LICENCE</h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table">
            <thead>
              <tr>
                <th rowspan="3" class="date-col">Sr. No.</th>
                <th rowspan="3" class="permit-col">TP NO</th>
                <th rowspan="3" class="permit-col">Brand Name</th>
                <th colspan="<?= $total_columns ?>">Received</th>
                <th colspan="<?= $total_columns ?>">Sold</th>
                <th colspan="<?= $total_columns ?>">Closing Balance</th>
                <th rowspan="3" class="signature-col">Total</th>
              </tr>
              <tr>
                <!-- NEW ORDER: Spirit, Wine, Fermented Beer, Mild Beer -->
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wines</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wines</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wines</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
              </tr>
              <tr>
                <!-- Spirits Received - Vertical text for print -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Received -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Received -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Received -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Closing Balance -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Closing Balance -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Closing Balance -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing Balance -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php $sr_no = 1; ?>
              
              <!-- Spirits Section -->
              <tr class="category-header">
                <td colspan="<?= ($total_columns * 3) + 4 ?>">SPIRITS</td>
              </tr>
              <?php foreach ($brand_data_by_category['Spirits'] as $brand => $brand_categories): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td></td> <!-- TP NO -->
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  
                  <!-- Received Section - NEW ORDER -->
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - NEW ORDER -->
                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section - NEW ORDER -->
                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Total column for the brand -->
                  <td>
                    <?php
                    $brand_total = 0;
                    foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                        $brand_total += array_sum($brand_categories[$category]['closing']);
                    }
                    echo $brand_total;
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Wines Section -->
              <tr class="category-header">
                <td colspan="<?= ($total_columns * 3) + 4 ?>">WINES</td>
              </tr>
              <?php foreach ($brand_data_by_category['Wines'] as $brand => $brand_categories): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td></td> <!-- TP NO -->
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  
                  <!-- Received Section - NEW ORDER -->
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - NEW ORDER -->
                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section - NEW ORDER -->
                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Total column for the brand -->
                  <td>
                    <?php
                    $brand_total = 0;
                    foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                        $brand_total += array_sum($brand_categories[$category]['closing']);
                    }
                    echo $brand_total;
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Fermented Beer Section -->
              <tr class="category-header">
                <td colspan="<?= ($total_columns * 3) + 4 ?>">FERMENTED BEER</td>
              </tr>
              <?php foreach ($brand_data_by_category['Fermented Beer'] as $brand => $brand_categories): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td></td> <!-- TP NO -->
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  
                  <!-- Received Section - NEW ORDER -->
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - NEW ORDER -->
                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section - NEW ORDER -->
                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Total column for the brand -->
                  <td>
                    <?php
                    $brand_total = 0;
                    foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                        $brand_total += array_sum($brand_categories[$category]['closing']);
                    }
                    echo $brand_total;
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Mild Beer Section -->
              <tr class="category-header">
                <td colspan="<?= ($total_columns * 3) + 4 ?>">MILD BEER</td>
              </tr>
              <?php foreach ($brand_data_by_category['Mild Beer'] as $brand => $brand_categories): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td></td> <!-- TP NO -->
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  
                  <!-- Received Section - NEW ORDER -->
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - NEW ORDER -->
                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section - NEW ORDER -->
                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Total column for the brand -->
                  <td>
                    <?php
                    $brand_total = 0;
                    foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                        $brand_total += array_sum($brand_categories[$category]['closing']);
                    }
                    echo $brand_total;
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Grand Total row -->
              <tr class="summary-row">
                <td colspan="3">Grand Total</td>
                
                <!-- Received Section - NEW ORDER -->
                <!-- Spirits Received Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $grand_totals['Spirits']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Received Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $grand_totals['Wines']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Received Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $grand_totals['Fermented Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Received Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $grand_totals['Mild Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Sold Section - NEW ORDER -->
                <!-- Spirits Sold Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $grand_totals['Spirits']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Sold Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $grand_totals['Wines']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Sold Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $grand_totals['Fermented Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $grand_totals['Mild Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Closing Balance Section - NEW ORDER -->
                <!-- Spirits Closing Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $grand_totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $grand_totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Closing Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $grand_totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $grand_totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Grand Total column -->
                <td>
                  <?php
                  $grand_total = 0;
                  foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                      $grand_total += array_sum($grand_totals[$category]['closing']);
                  }
                  echo $grand_total;
                  ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <div class="row mt-4">
            <div class="col-md-4">
              <p>Prepared By: ____________________</p>
            </div>
            <div class="col-md-4">
              <p>Verified By: ____________________</p>
            </div>
            <div class="col-md-4">
              <p>Date: <?= date('d/m/Y') ?></p>
            </div>
          </div>
          <p class="mt-3">Note: This register is maintained under FLR-3A format for excise compliance.</p>
          <p>Generated by WineSoft on <?= date('d-M-Y h:i A') ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>