<?php
session_start();

// Increase execution time for large reports
set_time_limit(180); // 3 minutes

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
require_once 'license_functions.php'; // Include license functions

// Get company ID from session
$compID = $_SESSION['CompID'];

// Get company's license type and available classes
$license_type = getCompanyLicenseType($compID, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
if (is_array($available_classes) && !empty($available_classes)) {
    foreach ($available_classes as $class) {
        if (is_array($class) && isset($class['SGROUP'])) {
            $allowed_classes[] = $class['SGROUP'];
        } elseif (is_string($class)) {
            $allowed_classes[] = $class;
        }
    }
}

// Default values
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// Validate date range
if (strtotime($from_date) > strtotime($to_date)) {
    $from_date = $to_date;
}

// Fetch company name and license number
$companyName = "Digvijay WINE SHOP";
$licenseNo = "3";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
if ($companyStmt) {
    $companyStmt->bind_param("i", $compID);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    if ($row = $companyResult->fetch_assoc()) {
        $companyName = $row['COMP_NAME'];
        $licenseNo = $row['COMP_FLNO'] ? $row['COMP_FLNO'] : $licenseNo;
    }
    $companyStmt->close();
}

// Function to get base size (from excise_register)
function getBaseSize($size) {
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize);
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize);
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize);
    return trim($baseSize);
}

// Define size columns for each liquor type (from excise_register)
$size_columns_s = [
    '2000 ML Pet (6)', '2000 ML(4)', '2000 ML(6)', '1000 ML(Pet)', '1000 ML',
    '750 ML(6)', '750 ML (Pet)', '750 ML', '700 ML', '700 ML(6)',
    '375 ML (12)', '375 ML', '375 ML (Pet)', '350 ML (12)', '275 ML(24)',
    '200 ML (48)', '200 ML (24)', '200 ML (30)', '200 ML (12)', '180 ML(24)',
    '180 ML (Pet)', '180 ML', '170 ML (48)', '90 ML(100)', '90 ML (Pet)-100', '90 ML (Pet)-96',
    '90 ML-(96)', '90 ML', '60 ML', '60 ML (75)', '50 ML(120)', '50 ML (180)',
    '50 ML (24)', '50 ML (192)'
];
$size_columns_w = ['750 ML(6)', '750 ML', '650 ML', '375 ML', '330 ML', '180 ML'];
$size_columns_fb = ['650 ML', '500 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];
$size_columns_mb = ['650 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];

// Group sizes by base size
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

// Display sizes for each liquor type
$display_sizes_spirit = ['2000 ML', '1000 ML', '750 ML', '700 ML', '500 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_wine = ['750 ML', '375 ML', '180 ML', '90 ML'];
$display_sizes_beer = ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];

// Fetch class data to map liquor types
$classData = [];
$classQuery = "SELECT SGROUP, `DESC`, LIQ_FLAG FROM tblclass";
$classStmt = $conn->prepare($classQuery);
if ($classStmt) {
    $classStmt->execute();
    $classResult = $classStmt->get_result();
    while ($row = $classResult->fetch_assoc()) {
        $classData[$row['SGROUP']] = $row;
    }
    $classStmt->close();
}

// Fetch item master data - FILTERED BY LICENSE TYPE
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster WHERE CLASS IN ($class_placeholders)";
    
    $itemStmt = $conn->prepare($itemQuery);
    if ($itemStmt) {
        $itemStmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        
        while ($row = $itemResult->fetch_assoc()) {
            $items[$row['CODE']] = $row;
        }
        $itemStmt->close();
    }
}

// Function to determine liquor type - EXACT LOGIC FROM EXCISE REGISTER
function getLiquorType($class, $liq_flag, $mode = 'Foreign Liquor', $desc = '') {
    if ($mode == 'Country Liquor') {
        return 'Country Liquor';
    }

    if ($liq_flag == 'F') {
        switch ($class) {
            case 'I':
                return 'Imported Spirit';
            case 'W':
                if (stripos($desc, 'Wine') !== false || stripos($desc, 'Imp') !== false) {
                    return 'Wine Imp';
                } else {
                    return 'Spirits';
                }
            case 'V':
                return 'Wines';
            case 'F':
                return 'Fermented Beer';
            case 'M':
                return 'Mild Beer';
            case 'G':
            case 'D':
            case 'K':
            case 'R':
            case 'O':
                return 'Spirits';
            default:
                return 'Spirits';
        }
    }
    return 'Spirits'; // Default for non-F items
}

// Map to FLR Datewise categories
function mapToFLRCategories($liquor_type) {
    switch ($liquor_type) {
        case 'Spirits':
        case 'Imported Spirit':
            return 'Spirit';
        case 'Wines':
        case 'Wine Imp':
            return 'Wine';
        case 'Fermented Beer':
            return 'Fermented Beer';
        case 'Mild Beer':
            return 'Mild Beer';
        default:
            return 'Spirit';
    }
}

// Map database sizes to display sizes
$size_mapping = [
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '170 ML' => '180 ML',
    '90 ML' => '90 ML',
    '90 ML-100' => '90 ML',
    '90 ML-96' => '90 ML',
    '2000 ML' => '2000 ML',
    '2000 ML Pet' => '2000 ML',
    '1000 ML' => '1000 ML',
    '1000 ML(Pet)' => '1000 ML',
    '700 ML' => '700 ML',
    '500 ML' => '500 ML',
    '650 ML' => '650 ML',
    '330 ML' => '330 ML',
    '275 ML' => '275 ML',
    '250 ML' => '250 ML',
    '200 ML' => '200 ML',
    '180 ML' => '180 ML',
    '60 ML' => '60 ML',
    '50 ML' => '50 ML',
    '500 ML (CAN)' => '500 ML',
    '330 ML (CAN)' => '330 ML'
];

// Function to get grouped size
function getGroupedSize($size, $liquor_type) {
    global $grouped_sizes_s, $grouped_sizes_w, $grouped_sizes_fb, $grouped_sizes_mb;
    
    $baseSize = getBaseSize($size);
    
    switch ($liquor_type) {
        case 'Spirits':
        case 'Imported Spirit':
            if (in_array($baseSize, array_keys($grouped_sizes_s))) {
                return $baseSize;
            }
            break;
        case 'Wines':
        case 'Wine Imp':
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
    
    return $baseSize;
}

// Function to get table name for a specific date
function getTableForDate($conn, $compID, $date) {
    $current_month = date('Y-m');
    $target_month = date('Y-m', strtotime($date));
    
    // If current month, use main table
    if ($target_month == $current_month) {
        $tableName = "tbldailystock_" . $compID;
    } else {
        // For previous months, use archive table format
        $month = date('m', strtotime($date));
        $year = date('y', strtotime($date));
        $tableName = "tbldailystock_" . $compID . "_" . $month . "_" . $year;
    }
    
    // Check if table exists
    $tableCheckQuery = "SHOW TABLES LIKE '$tableName'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult && $tableCheckResult->num_rows == 0) {
        // If archive table doesn't exist, fall back to main table
        $tableName = "tbldailystock_" . $compID;
        
        // Check if main table exists
        $tableCheckQuery2 = "SHOW TABLES LIKE '$tableName'";
        $tableCheckResult2 = $conn->query($tableCheckQuery2);
        if ($tableCheckResult2 && $tableCheckResult2->num_rows == 0) {
            $tableName = "tbldailystock_1";
        }
    }
    
    return $tableName;
}

// Function to check if table has specific day columns
function tableHasDayColumns($conn, $tableName, $day) {
    $day_padded = sprintf('%02d', $day);
    
    $columns_to_check = [
        "DAY_{$day_padded}_OPEN",
        "DAY_{$day_padded}_PURCHASE", 
        "DAY_{$day_padded}_SALES",
        "DAY_{$day_padded}_CLOSING"
    ];
    
    foreach ($columns_to_check as $column) {
        $checkColumnQuery = "SHOW COLUMNS FROM $tableName LIKE '$column'";
        $columnResult = $conn->query($checkColumnQuery);
        if ($columnResult && $columnResult->num_rows == 0) {
            return false;
        }
    }
    
    return true;
}

// Initialize report data structure
$dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// Initialize daily data structure for each date - using 4 categories
$daily_data = [];

// Initialize totals
$totals = [
    'Spirit' => [
        'purchase' => array_fill_keys($display_sizes_spirit, 0),
        'sales' => array_fill_keys($display_sizes_spirit, 0),
        'closing' => array_fill_keys($display_sizes_spirit, 0)
    ],
    'Wine' => [
        'purchase' => array_fill_keys($display_sizes_wine, 0),
        'sales' => array_fill_keys($display_sizes_wine, 0),
        'closing' => array_fill_keys($display_sizes_wine, 0)
    ],
    'Fermented Beer' => [
        'purchase' => array_fill_keys($display_sizes_beer, 0),
        'sales' => array_fill_keys($display_sizes_beer, 0),
        'closing' => array_fill_keys($display_sizes_beer, 0)
    ],
    'Mild Beer' => [
        'purchase' => array_fill_keys($display_sizes_beer, 0),
        'sales' => array_fill_keys($display_sizes_beer, 0),
        'closing' => array_fill_keys($display_sizes_beer, 0)
    ]
];

// Initialize opening balance data
$opening_balance_data = [
    'Spirit' => array_fill_keys($display_sizes_spirit, 0),
    'Wine' => array_fill_keys($display_sizes_wine, 0),
    'Fermented Beer' => array_fill_keys($display_sizes_beer, 0),
    'Mild Beer' => array_fill_keys($display_sizes_beer, 0)
];

// Process each date in the range
foreach ($dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    $day_padded = sprintf('%02d', $day);
    
    // Get appropriate table for this date
    $dailyStockTable = getTableForDate($conn, $compID, $date);
    
    // Check if table has columns for this day
    if (!tableHasDayColumns($conn, $dailyStockTable, $day)) {
        // Initialize empty data for this date
        $daily_data[$date] = [
            'Spirit' => [
                'purchase' => array_fill_keys($display_sizes_spirit, 0),
                'sales' => array_fill_keys($display_sizes_spirit, 0),
                'closing' => array_fill_keys($display_sizes_spirit, 0)
            ],
            'Wine' => [
                'purchase' => array_fill_keys($display_sizes_wine, 0),
                'sales' => array_fill_keys($display_sizes_wine, 0),
                'closing' => array_fill_keys($display_sizes_wine, 0)
            ],
            'Fermented Beer' => [
                'purchase' => array_fill_keys($display_sizes_beer, 0),
                'sales' => array_fill_keys($display_sizes_beer, 0),
                'closing' => array_fill_keys($display_sizes_beer, 0)
            ],
            'Mild Beer' => [
                'purchase' => array_fill_keys($display_sizes_beer, 0),
                'sales' => array_fill_keys($display_sizes_beer, 0),
                'closing' => array_fill_keys($display_sizes_beer, 0)
            ]
        ];
        continue;
    }
    
    // Initialize daily data for this date
    $daily_data[$date] = [
        'Spirit' => [
            'purchase' => array_fill_keys($display_sizes_spirit, 0),
            'sales' => array_fill_keys($display_sizes_spirit, 0),
            'closing' => array_fill_keys($display_sizes_spirit, 0)
        ],
        'Wine' => [
            'purchase' => array_fill_keys($display_sizes_wine, 0),
            'sales' => array_fill_keys($display_sizes_wine, 0),
            'closing' => array_fill_keys($display_sizes_wine, 0)
        ],
        'Fermented Beer' => [
            'purchase' => array_fill_keys($display_sizes_beer, 0),
            'sales' => array_fill_keys($display_sizes_beer, 0),
            'closing' => array_fill_keys($display_sizes_beer, 0)
        ],
        'Mild Beer' => [
            'purchase' => array_fill_keys($display_sizes_beer, 0),
            'sales' => array_fill_keys($display_sizes_beer, 0),
            'closing' => array_fill_keys($display_sizes_beer, 0)
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
    if ($stockStmt) {
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
            $desc = isset($classData[$class]['DESC']) ? $classData[$class]['DESC'] : '';
            
            // Determine liquor type using EXACT LOGIC FROM EXCISE REGISTER
            $detailed_liquor_type = getLiquorType($class, $liq_flag, 'Foreign Liquor', $desc);
            
            // Map to FLR Datewise categories
            $flr_category = mapToFLRCategories($detailed_liquor_type);
            
            // Map database size to display size
            $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
            
            // Get grouped size for display
            $grouped_size = getGroupedSize($excel_size, $detailed_liquor_type);
            
            // Determine which display sizes to use based on FLR category
            $target_sizes = [];
            switch ($flr_category) {
                case 'Spirit':
                    $target_sizes = $display_sizes_spirit;
                    break;
                case 'Wine':
                    $target_sizes = $display_sizes_wine;
                    break;
                case 'Fermented Beer':
                case 'Mild Beer':
                    $target_sizes = $display_sizes_beer;
                    break;
                default:
                    $target_sizes = $display_sizes_spirit;
            }
            
            // Add to daily data and totals
            if (in_array($grouped_size, $target_sizes)) {
                // For opening balance (first date only)
                if ($date == $from_date) {
                    $opening_balance_data[$flr_category][$grouped_size] += $row['opening'];
                }
                
                // Daily data
                $daily_data[$date][$flr_category]['purchase'][$grouped_size] += $row['purchase'];
                $daily_data[$date][$flr_category]['sales'][$grouped_size] += $row['sales'];
                $daily_data[$date][$flr_category]['closing'][$grouped_size] += $row['closing'];
                
                // Totals
                $totals[$flr_category]['purchase'][$grouped_size] += $row['purchase'];
                $totals[$flr_category]['sales'][$grouped_size] += $row['sales'];
                $totals[$flr_category]['closing'][$grouped_size] += $row['closing'];
            }
        }
        
        $stockStmt->close();
    }
}

// Calculate total columns count for table formatting
$total_columns_per_section = count($display_sizes_spirit) + count($display_sizes_wine) + (count($display_sizes_beer) * 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FLR 1A/2A/3A Datewise Register - liqoursoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
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
    .license-info {
        background-color: #d1ecf1;
        border: 1px solid #bee5eb;
        border-radius: 5px;
        padding: 10px;
        margin-bottom: 15px;
    }
    .classification-note {
        background-color: #fff3cd;
        border: 1px solid #ffeeba;
        border-radius: 5px;
        padding: 8px;
        margin-bottom: 10px;
        font-size: 11px;
    }

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
      <h3 class="mb-4">FLR 1A/2A/3A Datewise Register</h3>

      <!-- License Restriction Info -->
      <div class="license-info no-print">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
              <?php 
              if (!empty($available_classes)) {
                  $class_names = [];
                  foreach ($available_classes as $class) {
                      if (is_array($class) && isset($class['DESC']) && isset($class['SGROUP'])) {
                          $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                      }
                  }
                  echo implode(', ', $class_names);
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
      </div>

      <!-- Classification Note (Based on Excise Register logic) -->
      <div class="classification-note no-print">
          <strong>Classification Logic (as per Excise Register):</strong><br>
          • <strong>Spirit:</strong> Classes I (Imported Spirit), W (if not Wine), G, D, K, R, O<br>
          • <strong>Wine:</strong> Class V (Wines) and Class W (Wine Imp)<br>
          • <strong>Fermented Beer:</strong> Class F<br>
          • <strong>Mild Beer:</strong> Class M<br>
      </div>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
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
                <i class="fas fa-cog me-1"></i> Generate
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
              </button>
              <button type="button" class="btn btn-info" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-1"></i> Export to Excel
              </button>
              <button type="button" class="btn btn-warning" onclick="exportToCSV()">
                <i class="fas fa-file-csv me-1"></i> Export to CSV
              </button>
              <button type="button" class="btn btn-danger" onclick="exportToPDF()">
                <i class="fas fa-file-pdf me-1"></i> Export to PDF
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
          <h1>Form F.L.R. 1A/2A/3A (See Rule 15)</h1>
          <h5>REGISTER OF TRANSACTION OF FOREIGN LIQUOR EFFECTED BY HOLDER OF VENDOR'S/HOTEL/CLUB LICENCE</h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table" id="flr-datewise-table">
            <thead>
              <tr>
                <th rowspan="3" class="date-col">Date</th>
                <th rowspan="3" class="permit-col">Permit No</th>
                <th colspan="<?= $total_columns_per_section ?>">Received</th>
                <th colspan="<?= $total_columns_per_section ?>">Sold</th>
                <th colspan="<?= $total_columns_per_section ?>">Closing Balance</th>
                <th rowspan="3" class="signature-col">Signature</th>
              </tr>
              <tr>
                <th colspan="<?= count($display_sizes_spirit) ?>">Spirit</th>
                <th colspan="<?= count($display_sizes_wine) ?>">Wine</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_spirit) ?>">Spirit</th>
                <th colspan="<?= count($display_sizes_wine) ?>">Wine</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_spirit) ?>">Spirit</th>
                <th colspan="<?= count($display_sizes_wine) ?>">Wine</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Mild Beer</th>
              </tr>
              <tr>
                <!-- Received - Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Received - Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Received - Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Received - Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Sold - Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Sold - Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Sold - Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Sold - Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Closing - Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Closing - Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Closing - Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Closing - Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dates as $date): ?>
                <?php if (!isset($daily_data[$date])) continue; ?>
                <tr>
                  <td class="date-col"><?= date('d-M', strtotime($date)) ?></td>
                  <td class="permit-col"></td>
                  
                  <!-- Received - Spirit -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['purchase'][$size]) && $daily_data[$date]['Spirit']['purchase'][$size] > 0 ? $daily_data[$date]['Spirit']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Received - Wine -->
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['purchase'][$size]) && $daily_data[$date]['Wine']['purchase'][$size] > 0 ? $daily_data[$date]['Wine']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Received - Fermented Beer -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['purchase'][$size]) && $daily_data[$date]['Fermented Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Received - Mild Beer -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['purchase'][$size]) && $daily_data[$date]['Mild Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Mild Beer']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Sold - Spirit -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['sales'][$size]) && $daily_data[$date]['Spirit']['sales'][$size] > 0 ? $daily_data[$date]['Spirit']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Sold - Wine -->
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['sales'][$size]) && $daily_data[$date]['Wine']['sales'][$size] > 0 ? $daily_data[$date]['Wine']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Sold - Fermented Beer -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['sales'][$size]) && $daily_data[$date]['Fermented Beer']['sales'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Sold - Mild Beer -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['sales'][$size]) && $daily_data[$date]['Mild Beer']['sales'][$size] > 0 ? $daily_data[$date]['Mild Beer']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Closing - Spirit -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['closing'][$size]) && $daily_data[$date]['Spirit']['closing'][$size] > 0 ? $daily_data[$date]['Spirit']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Closing - Wine -->
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['closing'][$size]) && $daily_data[$date]['Wine']['closing'][$size] > 0 ? $daily_data[$date]['Wine']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Closing - Fermented Beer -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['closing'][$size]) && $daily_data[$date]['Fermented Beer']['closing'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- Closing - Mild Beer -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['closing'][$size]) && $daily_data[$date]['Mild Beer']['closing'][$size] > 0 ? $daily_data[$date]['Mild Beer']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <td class="signature-col"></td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Summary rows - Opening Balance -->
              <tr class="summary-row">
                <td>Opening Balance</td>
                <td></td>

                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Closing Balance Section - Show opening balance -->
                <!-- Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= isset($opening_balance_data['Spirit'][$size]) && $opening_balance_data['Spirit'][$size] > 0 ? $opening_balance_data['Spirit'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= isset($opening_balance_data['Wine'][$size]) && $opening_balance_data['Wine'][$size] > 0 ? $opening_balance_data['Wine'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($opening_balance_data['Fermented Beer'][$size]) && $opening_balance_data['Fermented Beer'][$size] > 0 ? $opening_balance_data['Fermented Beer'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($opening_balance_data['Mild Beer'][$size]) && $opening_balance_data['Mild Beer'][$size] > 0 ? $opening_balance_data['Mild Beer'][$size] : '' ?></td>
                <?php endforeach; ?>

                <td></td>
              </tr>

              <!-- Summary rows - Total Received -->
              <tr class="summary-row">
                <td>Total Received</td>
                <td></td>

                <!-- Received Section - Show purchase totals -->
                <!-- Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= isset($totals['Spirit']['purchase'][$size]) && $totals['Spirit']['purchase'][$size] > 0 ? $totals['Spirit']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= isset($totals['Wine']['purchase'][$size]) && $totals['Wine']['purchase'][$size] > 0 ? $totals['Wine']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($totals['Fermented Beer']['purchase'][$size]) && $totals['Fermented Beer']['purchase'][$size] > 0 ? $totals['Fermented Beer']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($totals['Mild Beer']['purchase'][$size]) && $totals['Mild Beer']['purchase'][$size] > 0 ? $totals['Mild Beer']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Closing Balance Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <td></td>
              </tr>

              <!-- Summary rows - Total Sold -->
              <tr class="summary-row">
                <td>Total Sold</td>
                <td></td>

                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Sold Section - Show sales totals -->
                <!-- Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= isset($totals['Spirit']['sales'][$size]) && $totals['Spirit']['sales'][$size] > 0 ? $totals['Spirit']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= isset($totals['Wine']['sales'][$size]) && $totals['Wine']['sales'][$size] > 0 ? $totals['Wine']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($totals['Fermented Beer']['sales'][$size]) && $totals['Fermented Beer']['sales'][$size] > 0 ? $totals['Fermented Beer']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($totals['Mild Beer']['sales'][$size]) && $totals['Mild Beer']['sales'][$size] > 0 ? $totals['Mild Beer']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Closing Balance Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <td></td>
              </tr>

              <!-- Summary rows - Grand Total (Last Day Closing) -->
              <?php 
              $last_date = end($dates);
              reset($dates);
              ?>
              <tr class="summary-row">
                <td>Grand Total</td>
                <td></td>

                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Closing Balance Section - Show last date's closing -->
                <!-- Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= isset($daily_data[$last_date]['Spirit']['closing'][$size]) && $daily_data[$last_date]['Spirit']['closing'][$size] > 0 ? $daily_data[$last_date]['Spirit']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= isset($daily_data[$last_date]['Wine']['closing'][$size]) && $daily_data[$last_date]['Wine']['closing'][$size] > 0 ? $daily_data[$last_date]['Wine']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($daily_data[$last_date]['Fermented Beer']['closing'][$size]) && $daily_data[$last_date]['Fermented Beer']['closing'][$size] > 0 ? $daily_data[$last_date]['Fermented Beer']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($daily_data[$last_date]['Mild Beer']['closing'][$size]) && $daily_data[$last_date]['Mild Beer']['closing'][$size] > 0 ? $daily_data[$last_date]['Mild Beer']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>

                <td></td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <p>Note: This is a computer generated report and does not require signature.</p>
          <p>Generated on: <?= date('d-M-Y h:i A') ?> | Total Days: <?= count($dates) ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportToExcel() {
    var table = document.getElementById('flr-datewise-table');
    var wb = XLSX.utils.book_new();
    var tableClone = table.cloneNode(true);
    var ws = XLSX.utils.table_to_sheet(tableClone);
    XLSX.utils.book_append_sheet(wb, ws, 'FLR Datewise');
    var fileName = 'FLR_Datewise_<?= date('Y-m-d') ?>.xlsx';
    XLSX.writeFile(wb, fileName);
}

function exportToCSV() {
    var table = document.getElementById('flr-datewise-table');
    var ws = XLSX.utils.table_to_sheet(table);
    var fileName = 'FLR_Datewise_<?= date('Y-m-d') ?>.csv';
    XLSX.writeFile(ws, fileName);
}

function exportToPDF() {
    const element = document.querySelector('.print-section');
    const opt = {
        margin: 0.5,
        filename: 'FLR_Datewise_<?= date('Y-m-d') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
    };
    html2pdf().set(opt).from(element).save();
}

// Load XLSX library dynamically
if (typeof XLSX === 'undefined') {
    var script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    document.head.appendChild(script);
}

// Load html2pdf library dynamically
if (typeof html2pdf === 'undefined') {
    var script2 = document.createElement('script');
    script2.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
    document.head.appendChild(script2);
}

// Auto-submit form when dates change
document.querySelectorAll('input[type="date"]').forEach(input => {
  input.addEventListener('change', function() {
    document.querySelector('.report-filters').submit();
  });
});
</script>
</body>
</html>