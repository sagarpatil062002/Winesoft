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

// Cache for hierarchy data
$hierarchy_cache = [];

/**
 * Get complete hierarchy information for an item
 */
function getItemHierarchy($class_code, $subclass_code, $size_code, $conn) {
    global $hierarchy_cache;
    
    // Create cache key
    $cache_key = $class_code . '|' . $subclass_code . '|' . $size_code;
    
    if (isset($hierarchy_cache[$cache_key])) {
        return $hierarchy_cache[$cache_key];
    }
    
    $hierarchy = [
        'class_code' => $class_code,
        'class_name' => '',
        'subclass_code' => $subclass_code,
        'subclass_name' => '',
        'category_code' => '',
        'category_name' => '',
        'display_category' => 'OTHER',
        'display_type' => 'Spirit', // Default to Spirit for FLR Datewise
        'size_code' => $size_code,
        'size_desc' => '',
        'ml_volume' => 0,
        'full_hierarchy' => ''
    ];
    
    try {
        // Get class and category information
        if (!empty($class_code)) {
            $query = "SELECT cn.CLASS_NAME, cn.CATEGORY_CODE, cat.CATEGORY_NAME 
                      FROM tblclass_new cn
                      LEFT JOIN tblcategory cat ON cn.CATEGORY_CODE = cat.CATEGORY_CODE
                      WHERE cn.CLASS_CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $class_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $hierarchy['class_name'] = $row['CLASS_NAME'];
                $hierarchy['category_code'] = $row['CATEGORY_CODE'];
                $hierarchy['category_name'] = $row['CATEGORY_NAME'] ?? '';
                
                // Map category name to FLR Datewise display categories
                $category_name = strtoupper($row['CATEGORY_NAME'] ?? '');
                
                if ($category_name == 'SPIRIT') {
                    $hierarchy['display_type'] = 'Spirit';
                } elseif ($category_name == 'WINE') {
                    $hierarchy['display_type'] = 'Wine';
                } elseif ($category_name == 'FERMENTED BEER') {
                    $hierarchy['display_type'] = 'Fermented Beer';
                } elseif ($category_name == 'MILD BEER') {
                    $hierarchy['display_type'] = 'Mild Beer';
                } elseif ($category_name == 'COUNTRY LIQUOR') {
                    $hierarchy['display_type'] = 'Spirit'; // Map Country Liquor to Spirit for FLR
                } else {
                    $hierarchy['display_type'] = 'Spirit';
                }
                
                $hierarchy['display_category'] = $hierarchy['display_type'];
            }
            $stmt->close();
        }
        
        // Get subclass information
        if (!empty($subclass_code)) {
            $query = "SELECT SUBCLASS_NAME FROM tblsubclass_new WHERE SUBCLASS_CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $subclass_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $hierarchy['subclass_name'] = $row['SUBCLASS_NAME'];
            }
            $stmt->close();
        }
        
        // Get size information
        if (!empty($size_code)) {
            $query = "SELECT SIZE_DESC, ML_VOLUME FROM tblsize WHERE SIZE_CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $size_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $hierarchy['size_desc'] = $row['SIZE_DESC'];
                $hierarchy['ml_volume'] = (int)($row['ML_VOLUME'] ?? 0);
            }
            $stmt->close();
        }
        
    } catch (Exception $e) {
        error_log("Error in getItemHierarchy: " . $e->getMessage());
    }
    
    $hierarchy_cache[$cache_key] = $hierarchy;
    return $hierarchy;
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

// Function to get volume label
function getVolumeLabel($volume) {
    static $volume_label_cache = [];
    
    if (isset($volume_label_cache[$volume])) {
        return $volume_label_cache[$volume];
    }
    
    if ($volume >= 1000) {
        $liters = $volume / 1000;
        if ($liters == intval($liters)) {
            $label = intval($liters) . 'L';
        } else {
            $label = rtrim(rtrim(number_format($liters, 1), '0'), '.') . 'L';
        }
    } else {
        $label = $volume . ' ML';
    }
    
    $volume_label_cache[$volume] = $label;
    return $label;
}

// Display sizes for each liquor type - keeping original FLR Datewise layout
$display_sizes_spirit = ['2000 ML', '1000 ML', '750 ML', '700 ML', '500 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_wine = ['750 ML', '375 ML', '180 ML', '90 ML'];
$display_sizes_beer = ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];

// Fetch item master data - FILTERED BY LICENSE TYPE using new hierarchy
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // Updated query to use new hierarchy fields
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, LIQ_FLAG 
                  FROM tblitemmaster 
                  WHERE CLASS IN ($class_placeholders)";
    
    $itemStmt = $conn->prepare($itemQuery);
    if ($itemStmt) {
        $itemStmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        
        while ($row = $itemResult->fetch_assoc()) {
            // Get hierarchy information
            $hierarchy = getItemHierarchy(
                $row['CLASS_CODE_NEW'], 
                $row['SUBCLASS_CODE_NEW'], 
                $row['SIZE_CODE'], 
                $conn
            );
            
            $items[$row['CODE']] = [
                'code' => $row['CODE'],
                'details' => $row['DETAILS'],
                'details2' => $row['DETAILS2'],
                'class' => $row['CLASS'],
                'class_code_new' => $row['CLASS_CODE_NEW'],
                'subclass_code_new' => $row['SUBCLASS_CODE_NEW'],
                'size_code' => $row['SIZE_CODE'],
                'liq_flag' => $row['LIQ_FLAG'],
                'hierarchy' => $hierarchy
            ];
        }
        $itemStmt->close();
    }
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
    
    // Check if table has columns for this day
    if (!tableHasDayColumns($conn, $dailyStockTable, $day)) {
        continue;
    }
    
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
            
            $item = $items[$item_code];
            $hierarchy = $item['hierarchy'];
            $liquor_type = $hierarchy['display_type']; // Spirit, Wine, Fermented Beer, Mild Beer
            
            // Get volume
            $volume = $hierarchy['ml_volume'];
            
            // Skip if volume is 0
            if ($volume <= 0) continue;
            
            // Determine which display sizes to use based on liquor type
            $target_display_sizes = [];
            switch ($liquor_type) {
                case 'Spirit':
                    $target_display_sizes = $display_sizes_spirit;
                    break;
                case 'Wine':
                    $target_display_sizes = $display_sizes_wine;
                    break;
                case 'Fermented Beer':
                case 'Mild Beer':
                    $target_display_sizes = $display_sizes_beer;
                    break;
                default:
                    $target_display_sizes = $display_sizes_spirit;
            }
            
            // Find closest matching display size based on volume
            $matched_size = null;
            $closest_size = null;
            $closest_diff = PHP_INT_MAX;
            
            foreach ($target_display_sizes as $display_size) {
                // Extract numeric value from display size
                preg_match('/(\d+\.?\d*)/', $display_size, $display_matches);
                if (isset($display_matches[1])) {
                    $display_volume = floatval($display_matches[1]);
                    
                    // Convert to ML if needed
                    if (strpos($display_size, 'L') !== false && strpos($display_size, 'ML') === false) {
                        $display_volume *= 1000;
                    }
                    
                    // Calculate absolute difference
                    $diff = abs($volume - $display_volume);
                    
                    // If this is closer than previous closest, update
                    if ($diff < $closest_diff) {
                        $closest_diff = $diff;
                        $closest_size = $display_size;
                    }
                    
                    // Exact match found
                    if ($diff == 0) {
                        $matched_size = $display_size;
                        break;
                    }
                }
            }
            
            // If no exact match found, use the closest size
            if (!$matched_size && $closest_size) {
                $matched_size = $closest_size;
                
                // Log for debugging (optional)
                error_log("Item {$item_code} ({$item['details']}) with volume {$volume} ML matched to closest size {$closest_size} (diff: {$closest_diff})");
            }
            
            // If still no match, skip this item
            if (!$matched_size) {
                error_log("Item {$item_code} with volume {$volume} ML could not be matched to any size in category {$liquor_type}");
                continue;
            }
            
            // For opening balance (first date only)
            if ($date == $from_date) {
                $opening_balance_data[$liquor_type][$matched_size] += $row['opening'];
            }
            
            // Daily data
            $daily_data[$date][$liquor_type]['purchase'][$matched_size] += $row['purchase'];
            $daily_data[$date][$liquor_type]['sales'][$matched_size] += $row['sales'];
            $daily_data[$date][$liquor_type]['closing'][$matched_size] += $row['closing'];
            
            // Totals
            $totals[$liquor_type]['purchase'][$matched_size] += $row['purchase'];
            $totals[$liquor_type]['sales'][$matched_size] += $row['sales'];
            $totals[$liquor_type]['closing'][$matched_size] += $row['closing'];
        }
        
        $stockStmt->close();
    }
}

// Calculate total columns count for table formatting - Original FLR Datewise layout
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
                      $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                  }
                  echo implode(', ', $class_names);
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
          <p class="mb-0 mt-2"><strong>Spirit Sizes:</strong> <?= implode(', ', $display_sizes_spirit) ?></p>
          <p class="mb-0"><strong>Wine Sizes:</strong> <?= implode(', ', $display_sizes_wine) ?></p>
          <p class="mb-0"><strong>Beer Sizes:</strong> <?= implode(', ', $display_sizes_beer) ?></p>
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