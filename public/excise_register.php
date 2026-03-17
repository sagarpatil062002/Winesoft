<?php
session_start();
require_once 'components/financial_year_init.php';// Ensure user is logged in and company is selected

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
include_once "components/financial_year.php";
require_once 'license_functions.php'; // Add license functions

// Get company ID from session
$compID = $_SESSION['CompID'];

// Get company's license type and available classes
$license_type = getCompanyLicenseType($compID, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Cache for hierarchy data (same as opening_balance.php)
$hierarchy_cache = [];

/**
 * Get complete hierarchy information for an item (copied from opening_balance.php)
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
        'display_type' => 'OTHER',
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
                
                // Map category name to display category
                $category_name = strtoupper($row['CATEGORY_NAME'] ?? '');
                
                if ($category_name == 'SPIRIT') {
                    // Determine spirit type based on class name
                    $class_name_upper = strtoupper($row['CLASS_NAME'] ?? '');
                    if (strpos($class_name_upper, 'IMPORTED') !== false || strpos($class_name_upper, 'IMP') !== false) {
                        $hierarchy['display_type'] = 'IMPORTED';
                    } elseif (strpos($class_name_upper, 'MML') !== false) {
                        $hierarchy['display_type'] = 'MML';
                    } else {
                        $hierarchy['display_type'] = 'IMFL';
                    }
                } elseif ($category_name == 'WINE') {
                    // Determine wine type based on class name
                    $class_name_upper = strtoupper($row['CLASS_NAME'] ?? '');
                    if (strpos($class_name_upper, 'IMPORTED') !== false || strpos($class_name_upper, 'IMP') !== false) {
                        $hierarchy['display_type'] = 'IMPORTED WINE';
                    } elseif (strpos($class_name_upper, 'MML') !== false) {
                        $hierarchy['display_type'] = 'WINE MML';
                    } else {
                        $hierarchy['display_type'] = 'INDIAN WINE';
                    }
                } elseif ($category_name == 'FERMENTED BEER') {
                    $hierarchy['display_type'] = 'FERMENTED BEER';
                } elseif ($category_name == 'MILD BEER') {
                    $hierarchy['display_type'] = 'MILD BEER';
                } elseif ($category_name == 'COUNTRY LIQUOR') {
                    $hierarchy['display_type'] = 'COUNTRY LIQUOR';
                }
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
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Validate date range
if (strtotime($from_date) > strtotime($to_date)) {
    $from_date = $to_date;
}

// Fetch company name and license number
$companyName = "";
$licenseNo = "";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
    $licenseNo = $row['COMP_FLNO'] ?? '';
}
$companyStmt->close();

// Define display categories based on mode
if ($mode == 'Country Liquor') {
    $display_categories = ['COUNTRY LIQUOR'];
    $category_display_names = ['COUNTRY LIQUOR' => 'COUNTRY LIQUOR'];
} else {
    $display_categories = [
        'IMFL', 'IMPORTED', 'MML',
        'INDIAN WINE', 'IMPORTED WINE', 'WINE MML',
        'FERMENTED BEER', 'MILD BEER'
    ];
    $category_display_names = [
        'IMFL' => 'IMFL',
        'IMPORTED' => 'IMPORTED',
        'MML' => 'MML',
        'INDIAN WINE' => 'INDIAN WINE',
        'IMPORTED WINE' => 'IMPORTED WINE',
        'WINE MML' => 'WINE MML',
        'FERMENTED BEER' => 'FERMENTED BEER',
        'MILD BEER' => 'MILD BEER'
    ];
}

// Define size columns
$spirit_sizes = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

$size_columns = [
    'IMFL' => $spirit_sizes,
    'IMPORTED' => $spirit_sizes,
    'MML' => $spirit_sizes,
    'INDIAN WINE' => $spirit_sizes,
    'IMPORTED WINE' => $spirit_sizes,
    'WINE MML' => $spirit_sizes,
    'FERMENTED BEER' => $spirit_sizes,
    'MILD BEER' => $spirit_sizes,
    'COUNTRY LIQUOR' => $spirit_sizes
];

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

// Function to get table name for a specific date
function getTableForDate($conn, $compID, $date) {
    $current_month = date('Y-m');
    $target_month = date('Y-m', strtotime($date));
    
    if ($target_month == $current_month) {
        $tableName = "tbldailystock_" . $compID;
    } else {
        $month = date('m', strtotime($date));
        $year = date('y', strtotime($date));
        $tableName = "tbldailystock_" . $compID . "_" . $month . "_" . $year;
    }
    
    $tableCheckQuery = "SHOW TABLES LIKE '$tableName'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult->num_rows == 0) {
        $tableName = "tbldailystock_" . $compID;
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
            return false;
        }
    }
    
    return true;
}

// ============================================================================
// STEP 1: Get the dates the user wants to DISPLAY
// ============================================================================
$display_dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $display_dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// ============================================================================
// STEP 2: Get ALL dates from April 1st to To Date for CALCULATIONS
// ============================================================================
$financial_year = date('Y', strtotime($from_date));
$april_first = $financial_year . '-04-01';
$calculation_dates = [];

$current_date = $april_first;
while (strtotime($current_date) <= strtotime($to_date)) {
    $calculation_dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// ============================================================================
// STEP 3: Fetch item master data
// ============================================================================
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    if ($mode == 'Country Liquor') {
        $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, LIQ_FLAG 
                      FROM tblitemmaster 
                      WHERE CLASS IN ($class_placeholders) AND LIQ_FLAG = 'C'";
    } else {
        $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, LIQ_FLAG 
                      FROM tblitemmaster 
                      WHERE CLASS IN ($class_placeholders)";
    }
    
    $itemStmt = $conn->prepare($itemQuery);
    $itemStmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    
    while ($row = $itemResult->fetch_assoc()) {
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

// ============================================================================
// STEP 4: Fetch T.P. Nos for display dates only
// ============================================================================
$tp_nos_data = [];
foreach ($display_dates as $date) {
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

// ============================================================================
// STEP 5: Initialize data structures for ALL calculation dates
// ============================================================================
$all_daily_data = [];
foreach ($calculation_dates as $date) {
    $all_daily_data[$date] = [];
    foreach ($display_categories as $category) {
        $all_daily_data[$date][$category] = [
            'opening' => array_fill_keys($size_columns[$category], 0),
            'purchase' => array_fill_keys($size_columns[$category], 0),
            'sales' => array_fill_keys($size_columns[$category], 0),
            'closing' => array_fill_keys($size_columns[$category], 0)
        ];
    }
}

// ============================================================================
// STEP 6: Fetch raw data for ALL calculation dates
// ============================================================================
foreach ($calculation_dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    
    $dailyStockTable = getTableForDate($conn, $compID, $date);
    
    if (!tableHasDayColumns($conn, $dailyStockTable, $day)) {
        continue;
    }
    
    $day_padded = sprintf('%02d', $day);
    
    $stockQuery = "SELECT ITEM_CODE,
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
        
        if (!isset($items[$item_code])) continue;
        
        $item = $items[$item_code];
        $hierarchy = $item['hierarchy'];
        $display_type = $hierarchy['display_type'];
        
        if ($mode == 'Country Liquor') {
            $display_type = 'COUNTRY LIQUOR';
        }
        
        if (!in_array($display_type, $display_categories)) {
            continue;
        }
        
        $volume_label = getVolumeLabel($hierarchy['ml_volume']);
        
        // Find matching size column
        $matched_size = null;
        if (isset($size_columns[$display_type])) {
            if (in_array($volume_label, $size_columns[$display_type])) {
                $matched_size = $volume_label;
            } else {
                foreach ($size_columns[$display_type] as $size_col) {
                    preg_match('/(\d+\.?\d*)\s*(ML|L)/i', $volume_label, $vol_parts);
                    preg_match('/(\d+\.?\d*)\s*(ML|L)/i', $size_col, $col_parts);
                    
                    if (isset($vol_parts[1]) && isset($col_parts[1])) {
                        $vol_num = floatval($vol_parts[1]);
                        $col_num = floatval($col_parts[1]);
                        
                        $vol_unit = strtoupper($vol_parts[2]);
                        $col_unit = strtoupper($col_parts[2]);
                        
                        if ($vol_unit == 'L' && $col_unit == 'ML') {
                            $vol_num *= 1000;
                        } elseif ($vol_unit == 'ML' && $col_unit == 'L') {
                            $col_num *= 1000;
                        }
                        
                        if (abs($vol_num - $col_num) < 1) {
                            $matched_size = $size_col;
                            break;
                        }
                    }
                }
            }
        }
        
        if (!$matched_size && !empty($size_columns[$display_type])) {
            $matched_size = $size_columns[$display_type][0];
        }
        
        if ($matched_size && isset($all_daily_data[$date][$display_type])) {
            $all_daily_data[$date][$display_type]['opening'][$matched_size] += (int)$row['opening'];
            $all_daily_data[$date][$display_type]['purchase'][$matched_size] += (int)$row['purchase'];
            $all_daily_data[$date][$display_type]['sales'][$matched_size] += (int)$row['sales'];
            // Don't set closing from DB - we'll calculate it
        }
    }
    
    $stockStmt->close();
}

// ============================================================================
// STEP 7: Calculate running balances from April 1st through To Date
// ============================================================================
$running_closing = [];

foreach ($calculation_dates as $index => $date) {
    // For each category and size
    foreach ($display_categories as $category) {
        if (!isset($all_daily_data[$date][$category])) continue;
        
        foreach ($size_columns[$category] as $size) {
            // Get opening balance
            if ($index == 0) {
                // First day (April 1st) - use database opening
                $opening = $all_daily_data[$date][$category]['opening'][$size] ?? 0;
            } else {
                // Subsequent days - use previous day's closing
                $opening = $running_closing[$category][$size] ?? 0;
            }
            
            $purchase = $all_daily_data[$date][$category]['purchase'][$size] ?? 0;
            $sales = $all_daily_data[$date][$category]['sales'][$size] ?? 0;
            
            // Calculate closing
            $closing = $opening + $purchase - $sales;
            $closing = max(0, $closing);
            
            // Update the data array
            $all_daily_data[$date][$category]['opening'][$size] = $opening;
            $all_daily_data[$date][$category]['closing'][$size] = $closing;
            
            // Store for next day
            if (!isset($running_closing[$category])) {
                $running_closing[$category] = [];
            }
            $running_closing[$category][$size] = $closing;
        }
    }
}

// ============================================================================
// STEP 8: Filter to only include display dates
// ============================================================================
$daily_data = [];
foreach ($display_dates as $date) {
    if (isset($all_daily_data[$date])) {
        $daily_data[$date] = $all_daily_data[$date];
    }
}

// Calculate total columns count
$total_columns = 0;
foreach ($display_categories as $category) {
    $total_columns += count($size_columns[$category]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Excise Register (FLR-3) - liqoursoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  
  <style>
    body { font-size: 12px; background-color: #f8f9fa; }
    .company-header { text-align: center; margin-bottom: 15px; padding: 10px; }
    .company-header h1 { font-size: 18px; font-weight: bold; margin-bottom: 5px; }
    .company-header h5 { font-size: 14px; margin-bottom: 3px; }
    .company-header h6 { font-size: 12px; margin-bottom: 5px; }
    .report-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10px; }
    .report-table th, .report-table td { border: 1px solid #000; padding: 4px; text-align: center; white-space: nowrap; overflow: hidden; line-height: 1.2; }
    .report-table th { background-color: #f0f0f0; font-weight: bold; padding: 6px 3px; }
    .vertical-text-full { writing-mode: vertical-lr; transform: rotate(180deg); text-align: center; white-space: nowrap; padding: 8px 2px; min-width: 25px; max-width: 25px; width: 25px; font-size: 9px; line-height: 1.1; font-weight: bold; }
    .double-line-right { border-right: 3px double #000 !important; }
    .filter-card { background-color: #f8f9fa; }
    .table-responsive { overflow-x: auto; max-width: 100%; }
    .action-controls { display: flex; gap: 10px; align-items: center; }
    .no-print { display: block; }
    .tp-nos { font-size: 8px; line-height: 1.1; text-align: left; padding: 2px; }
    .tp-nos span { display: inline-block; margin-right: 3px; }
    .date-col { width: 30px; min-width: 30px; }
    .tp-col { width: 50px; min-width: 50px; }
    .type-col { width: 40px; min-width: 40px; }
    .size-col { width: 25px; min-width: 25px; max-width: 25px; }
    .date-display { display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100%; line-height: 1; }
    .date-display span { display: block; line-height: 1; margin: 0; padding: 0; }
    
    @media print {
      @page { size: legal landscape; margin: 0.2in; }
      body { margin: 0; padding: 0; font-size: 8px; background: white; }
      .no-print { display: none !important; }
      .print-section * { visibility: visible; }
      .print-section { position: absolute; left: 0; top: 0; width: 100%; }
      .report-table { font-size: 7px !important; }
      .report-table th, .report-table td { padding: 2px 1px !important; }
      .vertical-text-full { font-size: 6px !important; min-width: 18px; max-width: 20px; }
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
      </div>

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
                  <small class="text-muted">Selected: <?= count($display_dates) ?> day(s)</small>
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
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
          <h6><em>Opening balances carried forward from <?= date('d-M-Y', strtotime($april_first)) ?></em></h6>
        </div>
        
        <?php if (empty($display_dates) || empty($daily_data)): ?>
          <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No data available for the selected date range.
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="report-table" id="excise-register-table">
              <thead>
                <tr>
                  <th rowspan="2" class="date-col">Date</th>
                  <th rowspan="2" class="tp-col">T. P. Nos</th>
                  <th rowspan="2" class="type-col">Type</th>
                  
                  <?php foreach ($display_categories as $category): ?>
                    <th colspan="<?= count($size_columns[$category]) ?>"><?= $category_display_names[$category] ?></th>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <?php foreach ($display_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <th class="size-col vertical-text-full <?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php 
                $date_count = 0;
                $first_displayed = false;
                
                foreach ($display_dates as $date): 
                  if (!isset($daily_data[$date])) continue;
                  
                  // Check if there's any data to show
                  $has_data = false;
                  foreach ($display_categories as $cat) {
                      if (isset($daily_data[$date][$cat])) {
                          $cat_data = $daily_data[$date][$cat];
                          foreach ($size_columns[$cat] as $size) {
                              if (($cat_data['purchase'][$size] ?? 0) > 0 || 
                                  ($cat_data['sales'][$size] ?? 0) > 0 || 
                                  ($cat_data['closing'][$size] ?? 0) > 0) {
                                  $has_data = true;
                                  break;
                              }
                          }
                      }
                      if ($has_data) break;
                  }
                  
                  // For first date, also check opening
                  if (!$first_displayed && !$has_data) {
                      foreach ($display_categories as $cat) {
                          if (isset($daily_data[$date][$cat])) {
                              $cat_data = $daily_data[$date][$cat];
                              foreach ($size_columns[$cat] as $size) {
                                  if (($cat_data['opening'][$size] ?? 0) > 0) {
                                      $has_data = true;
                                      break;
                                  }
                              }
                          }
                          if ($has_data) break;
                      }
                  }
                  
                  if (!$has_data) continue;
                  
                  $day_num = date('d', strtotime($date));
                  $month_num = date('m', strtotime($date));
                  $year_num = date('y', strtotime($date));
                  $tp_nos = $tp_nos_data[$date] ?? [];
                  $date_count++;
                  
                  $is_first_displayed = !$first_displayed;
                  
                  if ($is_first_displayed): 
                      $first_displayed = true;
                ?>
                  <!-- First displayed date - Show all 4 rows -->
                  <tr>
                    <td rowspan="4" class="date-col">
                      <div class="date-display">
                        <span><?= $day_num ?></span>
                        <span><?= $month_num ?></span>
                        <span><?= $year_num ?></span>
                      </div>
                    </td>
                    <td rowspan="4" class="tp-nos">
                      <?php if (!empty($tp_nos)): ?>
                        <?php foreach ($tp_nos as $tp_no): ?>
                          <span><?= $tp_no ?></span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        &nbsp;
                      <?php endif; ?>
                    </td>
                    <td>Op.</td>
                    
                    <?php foreach ($display_categories as $cat_index => $category): ?>
                      <?php 
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                      ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                          <?= $daily_data[$date][$category]['opening'][$size] > 0 ? $daily_data[$date][$category]['opening'][$size] : '' ?>
                        </td>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tr>
                  
                  <tr>
                    <td>Rec.</td>
                    <?php foreach ($display_categories as $cat_index => $category): ?>
                      <?php 
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                      ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                          <?= $daily_data[$date][$category]['purchase'][$size] > 0 ? $daily_data[$date][$category]['purchase'][$size] : '' ?>
                        </td>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tr>
                  
                  <tr>
                    <td>Sale</td>
                    <?php foreach ($display_categories as $cat_index => $category): ?>
                      <?php 
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                      ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                          <?= $daily_data[$date][$category]['sales'][$size] > 0 ? $daily_data[$date][$category]['sales'][$size] : '' ?>
                        </td>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tr>
                  
                  <tr>
                    <td>Clo.</td>
                    <?php foreach ($display_categories as $cat_index => $category): ?>
                      <?php 
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                      ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                          <?= $daily_data[$date][$category]['closing'][$size] > 0 ? $daily_data[$date][$category]['closing'][$size] : '' ?>
                        </td>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tr>
                  
                <?php else: ?>
                  <!-- Subsequent displayed dates - Show only 3 rows -->
                  <tr>
                    <td rowspan="3" class="date-col">
                      <div class="date-display">
                        <span><?= $day_num ?></span>
                        <span><?= $month_num ?></span>
                        <span><?= $year_num ?></span>
                      </div>
                    </td>
                    <td rowspan="3" class="tp-nos">
                      <?php if (!empty($tp_nos)): ?>
                        <?php foreach ($tp_nos as $tp_no): ?>
                          <span><?= $tp_no ?></span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        &nbsp;
                      <?php endif; ?>
                    </td>
                    <td>Rec.</td>
                    
                    <?php foreach ($display_categories as $cat_index => $category): ?>
                      <?php 
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                      ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                          <?= $daily_data[$date][$category]['purchase'][$size] > 0 ? $daily_data[$date][$category]['purchase'][$size] : '' ?>
                        </td>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tr>
                  
                  <tr>
                    <td>Sale</td>
                    
                    <?php foreach ($display_categories as $cat_index => $category): ?>
                      <?php 
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                      ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                          <?= $daily_data[$date][$category]['sales'][$size] > 0 ? $daily_data[$date][$category]['sales'][$size] : '' ?>
                        </td>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tr>
                  
                  <tr>
                    <td>Clo.</td>
                    
                    <?php foreach ($display_categories as $cat_index => $category): ?>
                      <?php 
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                      ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                          <?= $daily_data[$date][$category]['closing'][$size] > 0 ? $daily_data[$date][$category]['closing'][$size] : '' ?>
                        </td>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tr>
                <?php endif; ?>
                
                <?php endforeach; ?>
                
                <?php if ($date_count == 0): ?>
                  <tr>
                    <td colspan="<?= 3 + $total_columns ?>" class="text-center">No data available for the selected date range.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          
          <div class="footer-info">
            <p>Generated on: <?= date('d-M-Y h:i A') ?> | Total Days: <?= $date_count ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportToExcel() {
  var table = document.getElementById('excise-register-table');
  var wb = XLSX.utils.book_new();
  var tableClone = table.cloneNode(true);
  var ws = XLSX.utils.table_to_sheet(tableClone);
  XLSX.utils.book_append_sheet(wb, ws, 'Excise Register');
  var fileName = 'Excise_Register_<?= date('Y-m-d') ?>.xlsx';
  XLSX.writeFile(wb, fileName);
}

if (typeof XLSX === 'undefined') {
  var script = document.createElement('script');
  script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
  document.head.appendChild(script);
}
</script>
<?php require_once 'components/financial_year_footer.php'; ?>

</body>
</html>