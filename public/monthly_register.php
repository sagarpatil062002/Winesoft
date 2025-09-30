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

// Default values - Monthly register (current month)
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

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

// Function to group sizes by base size (remove suffixes after ML and trim)
function getBaseSize($size) {
    // Extract the base size (everything before any special characters after ML)
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize); // Remove trailing - numbers
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize); // Remove trailing (numbers)
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize); // Remove anything in parentheses
    return trim($baseSize);
}

// Define size columns exactly as they appear in FLR Datewise
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

// Initialize monthly data structure - ORDER: Spirit, Wine, Fermented Beer, Mild Beer
$monthly_data = [
    'Spirits' => [
        'opening' => array_fill_keys($display_sizes_s, 0),
        'received' => array_fill_keys($display_sizes_s, 0),
        'total' => array_fill_keys($display_sizes_s, 0),
        'sold' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0),
        'breakages' => array_fill_keys($display_sizes_s, 0)
    ],
    'Wines' => [
        'opening' => array_fill_keys($display_sizes_w, 0),
        'received' => array_fill_keys($display_sizes_w, 0),
        'total' => array_fill_keys($display_sizes_w, 0),
        'sold' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0),
        'breakages' => array_fill_keys($display_sizes_w, 0)
    ],
    'Fermented Beer' => [
        'opening' => array_fill_keys($display_sizes_fb, 0),
        'received' => array_fill_keys($display_sizes_fb, 0),
        'total' => array_fill_keys($display_sizes_fb, 0),
        'sold' => array_fill_keys($display_sizes_fb, 0),
        'closing' => array_fill_keys($display_sizes_fb, 0),
        'breakages' => array_fill_keys($display_sizes_fb, 0)
    ],
    'Mild Beer' => [
        'opening' => array_fill_keys($display_sizes_mb, 0),
        'received' => array_fill_keys($display_sizes_mb, 0),
        'total' => array_fill_keys($display_sizes_mb, 0),
        'sold' => array_fill_keys($display_sizes_mb, 0),
        'closing' => array_fill_keys($display_sizes_mb, 0),
        'breakages' => array_fill_keys($display_sizes_mb, 0)
    ]
];

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

// Process monthly data
$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$days_in_month = date('t', strtotime($month_start));

// Fetch all stock data for this month
for ($day = 1; $day <= $days_in_month; $day++) {
    $padded_day = str_pad($day, 2, '0', STR_PAD_LEFT);
    
    $stockQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                  DAY_{$padded_day}_OPEN as opening, 
                  DAY_{$padded_day}_PURCHASE as purchase, 
                  DAY_{$padded_day}_SALES as sales, 
                  DAY_{$padded_day}_CLOSING as closing 
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
        
        // Add to monthly data based on liquor type and grouped size
        switch ($liquor_type) {
            case 'Spirits':
                if (in_array($grouped_size, $display_sizes_s)) {
                    // Opening balance (only on first day - DAY_1_OPEN)
                    if ($day == 1) {
                        $monthly_data['Spirits']['opening'][$grouped_size] += $row['opening'];
                    }
                    
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Spirits']['received'][$grouped_size] += $row['purchase'];
                    
                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Spirits']['sold'][$grouped_size] += $row['sales'];
                    
                    // Closing balance (last day - DAY_LAST_CLOSING)
                    if ($day == $days_in_month) {
                        $monthly_data['Spirits']['closing'][$grouped_size] += $row['closing'];
                    }
                }
                break;
                
            case 'Wines':
                if (in_array($grouped_size, $display_sizes_w)) {
                    // Opening balance (only on first day - DAY_1_OPEN)
                    if ($day == 1) {
                        $monthly_data['Wines']['opening'][$grouped_size] += $row['opening'];
                    }
                    
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Wines']['received'][$grouped_size] += $row['purchase'];
                    
                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Wines']['sold'][$grouped_size] += $row['sales'];
                    
                    // Closing balance (last day - DAY_LAST_CLOSING)
                    if ($day == $days_in_month) {
                        $monthly_data['Wines']['closing'][$grouped_size] += $row['closing'];
                    }
                }
                break;
                
            case 'Fermented Beer':
                if (in_array($grouped_size, $display_sizes_fb)) {
                    // Opening balance (only on first day - DAY_1_OPEN)
                    if ($day == 1) {
                        $monthly_data['Fermented Beer']['opening'][$grouped_size] += $row['opening'];
                    }
                    
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Fermented Beer']['received'][$grouped_size] += $row['purchase'];
                    
                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Fermented Beer']['sold'][$grouped_size] += $row['sales'];
                    
                    // Closing balance (last day - DAY_LAST_CLOSING)
                    if ($day == $days_in_month) {
                        $monthly_data['Fermented Beer']['closing'][$grouped_size] += $row['closing'];
                    }
                }
                break;
                
            case 'Mild Beer':
                if (in_array($grouped_size, $display_sizes_mb)) {
                    // Opening balance (only on first day - DAY_1_OPEN)
                    if ($day == 1) {
                        $monthly_data['Mild Beer']['opening'][$grouped_size] += $row['opening'];
                    }
                    
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Mild Beer']['received'][$grouped_size] += $row['purchase'];
                    
                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Mild Beer']['sold'][$grouped_size] += $row['sales'];
                    
                    // Closing balance (last day - DAY_LAST_CLOSING)
                    if ($day == $days_in_month) {
                        $monthly_data['Mild Beer']['closing'][$grouped_size] += $row['closing'];
                    }
                }
                break;
        }
    }
    
    $stockStmt->close();
}

// Fetch breakages data for the selected month
$breakages_data = [
    'Spirits' => array_fill_keys($display_sizes_s, 0),
    'Wines' => array_fill_keys($display_sizes_w, 0),
    'Fermented Beer' => array_fill_keys($display_sizes_fb, 0),
    'Mild Beer' => array_fill_keys($display_sizes_mb, 0)
];

$breakagesQuery = "SELECT b.Code, b.BRK_Qty, i.DETAILS2, i.CLASS, i.LIQ_FLAG 
                   FROM tblbreakages b 
                   JOIN tblitemmaster i ON b.Code = i.CODE 
                   WHERE b.CompID = ? AND DATE_FORMAT(b.BRK_Date, '%Y-%m') = ?";
$breakagesStmt = $conn->prepare($breakagesQuery);
$breakagesStmt->bind_param("is", $compID, $month);
$breakagesStmt->execute();
$breakagesResult = $breakagesStmt->get_result();

while ($row = $breakagesResult->fetch_assoc()) {
    $item_code = $row['Code'];
    
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
    
    // Add to breakages data based on liquor type and grouped size
    switch ($liquor_type) {
        case 'Spirits':
            if (in_array($grouped_size, $display_sizes_s)) {
                $breakages_data['Spirits'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Spirits']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;
            
        case 'Wines':
            if (in_array($grouped_size, $display_sizes_w)) {
                $breakages_data['Wines'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Wines']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;
            
        case 'Fermented Beer':
            if (in_array($grouped_size, $display_sizes_fb)) {
                $breakages_data['Fermented Beer'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Fermented Beer']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;
            
        case 'Mild Beer':
            if (in_array($grouped_size, $display_sizes_mb)) {
                $breakages_data['Mild Beer'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Mild Beer']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;
    }
}
$breakagesStmt->close();

// Calculate totals (Opening + Received)
foreach ($display_sizes_s as $size) {
    $monthly_data['Spirits']['total'][$size] = 
        $monthly_data['Spirits']['opening'][$size] + 
        $monthly_data['Spirits']['received'][$size];
}

foreach ($display_sizes_w as $size) {
    $monthly_data['Wines']['total'][$size] = 
        $monthly_data['Wines']['opening'][$size] + 
        $monthly_data['Wines']['received'][$size];
}

foreach ($display_sizes_fb as $size) {
    $monthly_data['Fermented Beer']['total'][$size] = 
        $monthly_data['Fermented Beer']['opening'][$size] + 
        $monthly_data['Fermented Beer']['received'][$size];
}

foreach ($display_sizes_mb as $size) {
    $monthly_data['Mild Beer']['total'][$size] = 
        $monthly_data['Mild Beer']['opening'][$size] + 
        $monthly_data['Mild Beer']['received'][$size];
}

// Calculate summary totals
$summary_totals = [
    'opening' => 0,
    'received' => 0,
    'total' => 0,
    'sold' => 0,
    'breakages' => 0,
    'closing' => 0
];

// Calculate totals for each category
foreach ($monthly_data as $category => $data) {
    foreach ($data['opening'] as $size => $value) {
        $summary_totals['opening'] += $value;
    }
    foreach ($data['received'] as $size => $value) {
        $summary_totals['received'] += $value;
    }
    foreach ($data['total'] as $size => $value) {
        $summary_totals['total'] += $value;
    }
    foreach ($data['sold'] as $size => $value) {
        $summary_totals['sold'] += $value;
    }
    foreach ($data['breakages'] as $size => $value) {
        $summary_totals['breakages'] += $value;
    }
    foreach ($data['closing'] as $size => $value) {
        $summary_totals['closing'] += $value;
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
  <title>Monthly Register (FLR-4) - WineSoft</title>
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
      
      .description-col {
        width: 120px !important;
        min-width: 120px !important;
        max-width: 120px !important;
        text-align: left !important;
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
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Monthly Register (FLR-4)</h3>

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
                <label class="form-label">Month:</label>
                <select name="month" class="form-control">
                  <?php
                  $months = [
                    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
                    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
                    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                  ];
                  foreach ($months as $key => $name) {
                    $selected = ($month == date('Y') . '-' . $key) ? 'selected' : '';
                    echo "<option value=\"" . date('Y') . "-$key\" $selected>$name</option>";
                  }
                  ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Year:</label>
                <select name="year" class="form-control">
                  <?php
                  $current_year = date('Y');
                  for ($y = $current_year - 5; $y <= $current_year + 1; $y++) {
                    $selected = ($year == $y) ? 'selected' : '';
                    echo "<option value=\"$y\" $selected>$y</option>";
                  }
                  ?>
                </select>
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
          <h5>License to <?= htmlspecialchars($companyName) ?> (<?= $year ?> - <?= $year + 1 ?>), Pune.</h5>
          <h5>[Monthly Register]</h5>
          <h6><?= htmlspecialchars($companyName) ?></h6>
          <h6>LICENCE NO. :- <?= htmlspecialchars($licenseNo) ?> | MORTIF.: | SPRINT 3</h6>
          <h6>Month: <?= date('F Y', strtotime($month . '-01')) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table">
            <thead>
              <tr>
                <th rowspan="3" class="description-col">Description</th>
                <th colspan="<?= count($display_sizes_s) ?>">SPIRITS</th>
                <th colspan="<?= count($display_sizes_w) ?>">WINES</th>
                <th colspan="<?= count($display_sizes_fb) ?>">FERMENTED BEER</th>
                <th colspan="<?= count($display_sizes_mb) ?>">MILD BEER</th>
              </tr>
              <tr>
                <!-- Spirits Size Columns -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Size Columns -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Size Columns -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Size Columns -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
              <tr>
                <!-- SOL D IND. OF BOTTLES (in min.) header -->
                <th colspan="<?= $total_columns ?>">SOL D IND. OF BOTTLES (in min.)</th>
              </tr>
            </thead>
            <tbody>
              <!-- Opening Balance -->
              <tr>
                <td class="description-col">Opening Balance of the Beginning of the Month :-</td>
                
                <!-- Spirits Opening -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Opening -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Opening -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Opening -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Received during the Current Month -->
              <tr>
                <td class="description-col">Received during the Current Month :-</td>
                
                <!-- Spirits Received -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['received'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Received -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['received'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Received -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['received'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Received -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['received'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Total -->
              <tr>
                <td class="description-col">Total :-</td>
                
                <!-- Spirits Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['total'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['total'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['total'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['total'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Sold during the Current Month -->
              <tr>
                <td class="description-col">Sold during the Current Month :-</td>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['sold'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['sold'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['sold'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['sold'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Breakages during the Current Month -->
              <tr>
                <td class="description-col">Breakages during the Current Month :-</td>
                
                <!-- Spirits Breakages -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['breakages'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Breakages -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['breakages'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Breakages -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['breakages'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Breakages -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['breakages'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Closing Balance at the End of the Month -->
              <tr>
                <td class="description-col">Closing Balance at the End of the Month :-</td>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Summary Row -->
              <tr class="summary-row">
                <td class="description-col">Total :-</td>
                
                <!-- Spirits Summary -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['opening'][$size] + 
                           $monthly_data['Spirits']['received'][$size] + 
                           $monthly_data['Spirits']['sold'][$size] + 
                           $monthly_data['Spirits']['breakages'][$size] + 
                           $monthly_data['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Summary -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['opening'][$size] + 
                           $monthly_data['Wines']['received'][$size] + 
                           $monthly_data['Wines']['sold'][$size] + 
                           $monthly_data['Wines']['breakages'][$size] + 
                           $monthly_data['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Summary -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['opening'][$size] + 
                           $monthly_data['Fermented Beer']['received'][$size] + 
                           $monthly_data['Fermented Beer']['sold'][$size] + 
                           $monthly_data['Fermented Beer']['breakages'][$size] + 
                           $monthly_data['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Summary -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['opening'][$size] + 
                           $monthly_data['Mild Beer']['received'][$size] + 
                           $monthly_data['Mild Beer']['sold'][$size] + 
                           $monthly_data['Mild Beer']['breakages'][$size] + 
                           $monthly_data['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        
        <!-- Summary Section -->
        <div class="table-responsive mt-3">
          <table class="report-table">
            <thead>
              <tr>
                <th colspan="6" style="text-align: center;">SUMMARY</th>
              </tr>
              <tr>
                <th>Opening Balance</th>
                <th>Received</th>
                <th>Total</th>
                <th>Sold</th>
                <th>Breakages</th>
                <th>Closing Balance</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><?= $summary_totals['opening'] ?></td>
                <td><?= $summary_totals['received'] ?></td>
                <td><?= $summary_totals['total'] ?></td>
                <td><?= $summary_totals['sold'] ?></td>
                <td><?= $summary_totals['breakages'] ?></td>
                <td><?= $summary_totals['closing'] ?></td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <p>Generated on: <?= date('d/m/Y h:i A') ?> | User: <?= $_SESSION['user_name'] ?? 'System' ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>