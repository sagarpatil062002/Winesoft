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

include_once "../config/db.php";
include_once "components/financial_year_auto.php";
require_once 'license_functions.php';

$compID = $_SESSION['CompID'];

// Get company's license type and available classes
$license_type = getCompanyLicenseType($compID, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Get financial year dates from session
$fin_year_start = $_SESSION['FIN_YEAR_START'] ?? date('Y-04-01');
$fin_year_end = $_SESSION['FIN_YEAR_END'] ?? date('Y-03-31');

// Cache for hierarchy data
$hierarchy_cache = [];

/**
 * Get complete hierarchy information for an item
 */
function getItemHierarchy($class_code, $subclass_code, $size_code, $conn) {
    global $hierarchy_cache;
    
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
                
                $category_name = strtoupper($row['CATEGORY_NAME'] ?? '');
                
                if ($category_name == 'SPIRIT') {
                    $class_name_upper = strtoupper($row['CLASS_NAME'] ?? '');
                    if (strpos($class_name_upper, 'IMPORTED') !== false || strpos($class_name_upper, 'IMP') !== false) {
                        $hierarchy['display_type'] = 'IMPORTED';
                    } elseif (strpos($class_name_upper, 'MML') !== false) {
                        $hierarchy['display_type'] = 'MML';
                    } else {
                        $hierarchy['display_type'] = 'IMFL';
                    }
                } elseif ($category_name == 'WINE') {
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

/**
 * Convert size string to milliliters for sorting
 */
function getSizeVolumeInML($size_str) {
    if (preg_match('/(\d+(?:\.\d+)?)\s*(ML|L)/i', $size_str, $matches)) {
        $value = (float)$matches[1];
        $unit = strtoupper($matches[2]);
        if ($unit == 'L') {
            return $value * 1000;
        }
        return $value;
    }
    return 0;
}

/**
 * Get standardized size label from volume in ML
 */
function getSizeLabelFromML($volume) {
    if ($volume >= 1000) {
        $liters = $volume / 1000;
        if ($liters == intval($liters)) {
            return intval($liters) . 'L';
        }
        return rtrim(rtrim(number_format($liters, 1), '0'), '.') . 'L';
    }
    return $volume . ' ML';
}

// Default values with financial year constraints
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Validate dates are within financial year
if (strtotime($from_date) < strtotime($fin_year_start)) {
    $from_date = $fin_year_start;
}
if (strtotime($to_date) > strtotime($fin_year_end)) {
    $to_date = $fin_year_end;
}
if (strtotime($from_date) > strtotime($to_date)) {
    $to_date = $from_date;
}

// Add pagination - limit number of days to process at once
$max_days_per_request = 31;
$date_diff = floor((strtotime($to_date) - strtotime($from_date)) / (60 * 60 * 24));

if ($date_diff > $max_days_per_request) {
    $to_date = date('Y-m-d', strtotime($from_date . ' + ' . $max_days_per_request . ' days'));
    $range_limited = true;
} else {
    $range_limited = false;
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

// Define ALL possible categories (will be filtered later)
$all_display_categories = [];
$all_category_display_names = [];

if ($mode == 'Country Liquor') {
    $all_display_categories = ['COUNTRY LIQUOR'];
    $all_category_display_names = ['COUNTRY LIQUOR' => 'COUNTRY LIQUOR'];
} else {
    $all_display_categories = [
        'IMFL', 'IMPORTED', 'MML',
        'INDIAN WINE', 'IMPORTED WINE', 'WINE MML',
        'FERMENTED BEER', 'MILD BEER'
    ];
    $all_category_display_names = [
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

// Define all possible sizes with STANDARDIZED labels
$all_possible_sizes = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1L',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

// Function to standardize size label
function standardizeSizeLabel($size) {
    if ($size == '1000 ML') return '1L';
    return $size;
}

// Function to get table name for a specific date
function getTableForDate($conn, $compID, $date) {
    static $table_cache = [];
    
    $current_month = date('Y-m');
    $target_month = date('Y-m', strtotime($date));
    $cache_key = $compID . '_' . $target_month;
    
    if (isset($table_cache[$cache_key])) {
        return $table_cache[$cache_key];
    }
    
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
    
    $table_cache[$cache_key] = $tableName;
    return $tableName;
}

// Function to check if table has specific day columns
function tableHasDayColumns($conn, $tableName, $day) {
    static $column_cache = [];
    
    $cache_key = $tableName . '_' . $day;
    
    if (isset($column_cache[$cache_key])) {
        return $column_cache[$cache_key];
    }
    
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
            $column_cache[$cache_key] = false;
            return false;
        }
    }
    
    $column_cache[$cache_key] = true;
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
$size_mapping = [];

if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    if ($mode == 'Country Liquor') {
        $itemQuery = "SELECT CODE, DETAILS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE 
                      FROM tblitemmaster 
                      WHERE CLASS IN ($class_placeholders) AND LIQ_FLAG = 'C'";
    } else {
        $itemQuery = "SELECT CODE, DETAILS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE 
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
        
        $size_ml = $hierarchy['ml_volume'];
        $size_label = getSizeLabelFromML($size_ml);
        $size_label = standardizeSizeLabel($size_label);
        
        $items[$row['CODE']] = [
            'code' => $row['CODE'],
            'details' => $row['DETAILS'],
            'hierarchy' => $hierarchy,
            'size_label' => $size_label,
            'size_ml' => $size_ml
        ];
        
        $size_mapping[$row['CODE']] = $size_label;
    }
    $itemStmt->close();
}

// ============================================================================
// STEP 4: Fetch T.P. Nos for display dates only
// ============================================================================
$tp_nos_data = [];
foreach ($display_dates as $date) {
    $tpQuery = "SELECT DISTINCT TPNO FROM tblpurchases WHERE DATE = ? AND CompID = ? AND TPNO IS NOT NULL AND TPNO != ''";
    $tpStmt = $conn->prepare($tpQuery);
    $tpStmt->bind_param("si", $date, $compID);
    $tpStmt->execute();
    $tpResult = $tpStmt->get_result();
    
    $tp_nos = [];
    while ($row = $tpResult->fetch_assoc()) {
        $tp_nos[] = $row['TPNO'];
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
    foreach ($all_display_categories as $category) {
        $all_daily_data[$date][$category] = [];
    }
}

// ============================================================================
// STEP 6: Fetch raw data for ALL calculation dates
// ============================================================================
$dates_by_month = [];
foreach ($calculation_dates as $date) {
    $month = date('Y-m', strtotime($date));
    if (!isset($dates_by_month[$month])) {
        $dates_by_month[$month] = [];
    }
    $dates_by_month[$month][] = $date;
}

foreach ($dates_by_month as $month => $dates) {
    $table_name = getTableForDate($conn, $compID, $dates[0]);
    
    $valid_dates = [];
    $day_columns = [];
    foreach ($dates as $date) {
        $day = date('d', strtotime($date));
        if (tableHasDayColumns($conn, $table_name, $day)) {
            $valid_dates[] = $date;
            $day_padded = sprintf('%02d', $day);
            $day_columns[] = "DAY_{$day_padded}_OPEN as open_$day";
            $day_columns[] = "DAY_{$day_padded}_PURCHASE as purchase_$day";
            $day_columns[] = "DAY_{$day_padded}_SALES as sales_$day";
        }
    }
    
    if (empty($valid_dates)) continue;
    
    $columns_sql = implode(', ', $day_columns);
    $stockQuery = "SELECT ITEM_CODE, $columns_sql FROM $table_name WHERE STK_MONTH = ?";
    
    $stockStmt = $conn->prepare($stockQuery);
    $stockStmt->bind_param("s", $month);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    
    while ($row = $stockResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        if (!isset($items[$item_code])) continue;
        
        $item = $items[$item_code];
        $display_type = $item['hierarchy']['display_type'];
        
        if ($mode == 'Country Liquor') {
            $display_type = 'COUNTRY LIQUOR';
        }
        
        if (!in_array($display_type, $all_display_categories)) {
            continue;
        }
        
        $size_label = $item['size_label'];
        
        foreach ($valid_dates as $date) {
            $day = date('d', strtotime($date));
            
            $opening = (int)($row["open_$day"] ?? 0);
            $purchase = (int)($row["purchase_$day"] ?? 0);
            $sales = (int)($row["sales_$day"] ?? 0);
            
            if ($opening == 0 && $purchase == 0 && $sales == 0) {
                continue;
            }
            
            if (!isset($all_daily_data[$date][$display_type][$size_label])) {
                $all_daily_data[$date][$display_type][$size_label] = [
                    'opening' => 0,
                    'purchase' => 0,
                    'sales' => 0,
                    'closing' => 0
                ];
            }
            
            $all_daily_data[$date][$display_type][$size_label]['opening'] += $opening;
            $all_daily_data[$date][$display_type][$size_label]['purchase'] += $purchase;
            $all_daily_data[$date][$display_type][$size_label]['sales'] += $sales;
        }
    }
    $stockStmt->close();
}

// ============================================================================
// STEP 7: Calculate running balances and track active categories & sizes
// ============================================================================
$running_closing = [];
$active_sizes_by_category = [];
$active_categories = [];

foreach ($all_display_categories as $category) {
    $active_sizes_by_category[$category] = [];
}

foreach ($calculation_dates as $index => $date) {
    foreach ($all_display_categories as $category) {
        if (!isset($all_daily_data[$date][$category])) continue;
        
        foreach ($all_daily_data[$date][$category] as $size => &$data) {
            // Get opening balance
            if ($index == 0) {
                $opening = $data['opening'];
            } else {
                $opening = $running_closing[$category][$size] ?? 0;
            }
            
            $purchase = $data['purchase'];
            $sales = $data['sales'];
            
            // Calculate closing
            $closing = $opening + $purchase - $sales;
            $closing = max(0, $closing);
            
            // Update data
            $data['opening'] = $opening;
            $data['closing'] = $closing;
            
            // Track active sizes AND categories
            if ($opening > 0 || $purchase > 0 || $sales > 0 || $closing > 0) {
                $active_sizes_by_category[$category][$size] = true;
                $active_categories[$category] = true;
            }
            
            // Store for next day
            if (!isset($running_closing[$category])) {
                $running_closing[$category] = [];
            }
            $running_closing[$category][$size] = $closing;
        }
    }
}

// ============================================================================
// STEP 8: Filter categories to ONLY those with active data
// ============================================================================
$display_categories = [];
$category_display_names = [];

foreach ($all_display_categories as $category) {
    if (isset($active_categories[$category]) && $active_categories[$category] === true) {
        $display_categories[] = $category;
        $category_display_names[$category] = $all_category_display_names[$category];
    }
}

// ============================================================================
// STEP 9: Build size_columns based on active sizes (sorted largest to smallest)
// ============================================================================
$size_columns = [];
foreach ($display_categories as $category) {
    $size_columns[$category] = [];
    
    // Get active sizes for this category
    $active_sizes = array_keys($active_sizes_by_category[$category] ?? []);
    
    if (!empty($active_sizes)) {
        // Filter all_possible_sizes to only include active sizes
        foreach ($all_possible_sizes as $size) {
            $standard_size = standardizeSizeLabel($size);
            if (in_array($standard_size, $active_sizes)) {
                $size_columns[$category][] = $standard_size;
            }
        }
    }
    
    // Remove duplicates and sort from largest to smallest
    $size_columns[$category] = array_unique($size_columns[$category]);
    usort($size_columns[$category], function($a, $b) {
        $vol_a = getSizeVolumeInML($a);
        $vol_b = getSizeVolumeInML($b);
        if ($vol_a == $vol_b) return 0;
        return ($vol_a > $vol_b) ? -1 : 1;
    });
    
    $size_columns[$category] = array_values($size_columns[$category]);
}

// ============================================================================
// STEP 10: Filter to only include display dates and ensure all sizes exist
// ============================================================================
$daily_data = [];
foreach ($display_dates as $date) {
    if (isset($all_daily_data[$date])) {
        $daily_data[$date] = [];
        foreach ($display_categories as $category) {
            $daily_data[$date][$category] = [];
            
            // Initialize all size columns with zeros
            foreach ($size_columns[$category] as $size) {
                $daily_data[$date][$category][$size] = [
                    'opening' => 0,
                    'purchase' => 0,
                    'sales' => 0,
                    'closing' => 0
                ];
            }
            
            // Fill in actual data where available
            if (isset($all_daily_data[$date][$category])) {
                foreach ($all_daily_data[$date][$category] as $size => $data) {
                    if (isset($daily_data[$date][$category][$size])) {
                        $daily_data[$date][$category][$size] = $data;
                    }
                }
            }
        }
    }
}

// Calculate total columns count
$total_columns = 0;
foreach ($display_categories as $category) {
    $total_columns += count($size_columns[$category]);
}

// Determine if report should be shown
$show_report = isset($_GET['generate']) || (!empty($display_dates) && !empty($daily_data) && !empty($display_categories));
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
    .fin-year-info { background-color: #d1ecf1; border-left: 4px solid #0c5460; padding: 8px; margin-bottom: 15px; font-size: 0.9em; }
    .range-warning { background-color: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 10px; margin-bottom: 15px; border-radius: 5px; }
    .size-info-note { background-color: #f0f7ff; border-left: 4px solid #0066cc; padding: 8px; margin: 10px 0; font-size: 0.9em; }
    
    @media print {
      @page { size: legal landscape; margin: 0.2in; }
      body { margin: 0; padding: 0; font-size: 8px; background: white; }
      .no-print { display: none !important; }
      .print-section * { visibility: visible; }
      .print-section { position: absolute; left: 0; top: 0; width: 100%; }
      .report-table { font-size: 7px !important; }
      .report-table th, .report-table td { padding: 2px 1px !important; }
      .vertical-text-full { font-size: 6px !important; min-width: 18px; max-width: 20px; }
      .size-info-note { display: none; }
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

      <div class="fin-year-info no-print">
        <strong><i class="fas fa-calendar-alt"></i> Financial Year:</strong> 
        <?= date('d-m-Y', strtotime($fin_year_start)) ?> to <?= date('d-m-Y', strtotime($fin_year_end)) ?>
      </div>

      <div class="license-info no-print">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
              <?php 
              if (!empty($available_classes)) {
                  $class_names = [];
                  foreach ($available_classes as $class) {
                      $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                  }
                  echo htmlspecialchars(implode(', ', $class_names));
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
      </div>

      <?php if ($range_limited): ?>
      <div class="range-warning no-print">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Note:</strong> Date range too large. Showing only first <?= $max_days_per_request ?> days 
        (<?= date('d-m-Y', strtotime($from_date)) ?> to <?= date('d-m-Y', strtotime($to_date)) ?>). 
      </div>
      <?php endif; ?>

      <?php if ($show_report && !empty($display_categories)): ?>
      <div class="size-info-note no-print">
        <strong><i class="fas fa-flask"></i> Note:</strong> Only categories and sizes with data are displayed.
        <br><strong>Categories with data:</strong> <?= implode(', ', $display_categories) ?>
        <?php foreach ($display_categories as $category): ?>
          <?php if (!empty($size_columns[$category])): ?>
            <br><strong><?= $category_display_names[$category] ?> sizes:</strong> <?= implode(', ', $size_columns[$category]) ?>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters" id="reportForm">
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
                <input type="date" name="from_date" class="form-control" 
                       value="<?= htmlspecialchars($from_date) ?>"
                       min="<?= htmlspecialchars($fin_year_start) ?>" 
                       max="<?= htmlspecialchars($fin_year_end) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date:</label>
                <input type="date" name="to_date" class="form-control" 
                       value="<?= htmlspecialchars($to_date) ?>"
                       min="<?= htmlspecialchars($fin_year_start) ?>" 
                       max="<?= htmlspecialchars($fin_year_end) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date Range Info:</label>
                <div class="form-control-plaintext">
                  <small class="text-muted">Selected: <?= count($display_dates) ?> day(s)</small>
                </div>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary" onclick="return validateDates()">
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

      <div class="print-section">
        <div class="company-header">
          <h1>Excise Register (FLR-3)</h1>
          <h5>Mode: <?= htmlspecialchars($mode) ?></h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
          <h6>Financial Year: <?= date('d-m-Y', strtotime($fin_year_start)) ?> to <?= date('d-m-Y', strtotime($fin_year_end)) ?></h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
          <h6><em>Opening balances carried forward from <?= date('d-M-Y', strtotime($april_first)) ?></em></h6>
        </div>
        
        <?php if (empty($display_dates) || empty($daily_data) || empty($display_categories)): ?>
          <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No data available for the selected date range.
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="report-table" id="excise-register-table">
              <thead>
                  报
                  <th rowspan="2" class="date-col">Date</th>
                  <th rowspan="2" class="tp-col">T. P. Nos</th>
                  <th rowspan="2" class="type-col">Type</th>
                  
                  <?php foreach ($display_categories as $category): ?>
                    <?php if (!empty($size_columns[$category])): ?>
                      <th colspan="<?= count($size_columns[$category]) ?>"><?= $category_display_names[$category] ?></th>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  </tr>
                  <tr>
                  <?php 
                  $cat_index = 0;
                  $total_categories = count($display_categories);
                  foreach ($display_categories as $category): 
                    if (empty($size_columns[$category])) {
                        $cat_index++;
                        continue;
                    }
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                  ?>
                      <th class="size-col vertical-text-full <?= ($size_index == $last_index && $cat_index < $total_categories - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                  <?php 
                    $cat_index++;
                  endforeach; 
                  ?>
                  </tr>
              </thead>
              <tbody>
                <?php 
                $date_count = 0;
                $first_displayed = false;
                
                foreach ($display_dates as $date): 
                  if (!isset($daily_data[$date])) continue;
                  
                  // Check if there's any data to show for this date
                  $has_data = false;
                  foreach ($display_categories as $cat) {
                      if (isset($daily_data[$date][$cat])) {
                          foreach ($size_columns[$cat] as $size) {
                              $data = $daily_data[$date][$cat][$size] ?? null;
                              if ($data) {
                                  if (($first_displayed && ($data['purchase'] > 0 || $data['sales'] > 0 || $data['closing'] > 0)) ||
                                      (!$first_displayed && ($data['opening'] > 0 || $data['purchase'] > 0 || $data['sales'] > 0 || $data['closing'] > 0))) {
                                      $has_data = true;
                                      break;
                                  }
                              }
                          }
                      }
                      if ($has_data) break;
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
                        <?php foreach (array_slice($tp_nos, 0, 3) as $tp_no): ?>
                          <span><?= htmlspecialchars($tp_no) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($tp_nos) > 3): ?>
                          <span>+<?= count($tp_nos) - 3 ?> more</span>
                        <?php endif; ?>
                      <?php else: ?>
                        &nbsp;
                      <?php endif; ?>
                      </td>
                    <td>Op.</td>
                    
                    <?php 
                    $cat_index = 0;
                    foreach ($display_categories as $category): 
                      if (empty($size_columns[$category])) {
                          $cat_index++;
                          continue;
                      }
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                    ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < $total_categories - 1) ? 'double-line-right' : '' ?>">
                          <?= isset($daily_data[$date][$category][$size]['opening']) && $daily_data[$date][$category][$size]['opening'] > 0 ? $daily_data[$date][$category][$size]['opening'] : '' ?>
                          </td>
                      <?php endforeach; ?>
                    <?php 
                      $cat_index++;
                    endforeach; 
                    ?>
                  </tr>
                  
                  <tr>
                    <td>Rec.</td>
                    <?php 
                    $cat_index = 0;
                    foreach ($display_categories as $category): 
                      if (empty($size_columns[$category])) {
                          $cat_index++;
                          continue;
                      }
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                    ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < $total_categories - 1) ? 'double-line-right' : '' ?>">
                          <?= isset($daily_data[$date][$category][$size]['purchase']) && $daily_data[$date][$category][$size]['purchase'] > 0 ? $daily_data[$date][$category][$size]['purchase'] : '' ?>
                          </td>
                      <?php endforeach; ?>
                    <?php 
                      $cat_index++;
                    endforeach; 
                    ?>
                  </tr>
                  
                  <tr>
                    <td>Sale</td>
                    <?php 
                    $cat_index = 0;
                    foreach ($display_categories as $category): 
                      if (empty($size_columns[$category])) {
                          $cat_index++;
                          continue;
                      }
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                    ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < $total_categories - 1) ? 'double-line-right' : '' ?>">
                          <?= isset($daily_data[$date][$category][$size]['sales']) && $daily_data[$date][$category][$size]['sales'] > 0 ? $daily_data[$date][$category][$size]['sales'] : '' ?>
                          </td>
                      <?php endforeach; ?>
                    <?php 
                      $cat_index++;
                    endforeach; 
                    ?>
                  </tr>
                  
                  <tr>
                    <td>Clo.</td>
                    <?php 
                    $cat_index = 0;
                    foreach ($display_categories as $category): 
                      if (empty($size_columns[$category])) {
                          $cat_index++;
                          continue;
                      }
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                    ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < $total_categories - 1) ? 'double-line-right' : '' ?>">
                          <?= isset($daily_data[$date][$category][$size]['closing']) && $daily_data[$date][$category][$size]['closing'] > 0 ? $daily_data[$date][$category][$size]['closing'] : '' ?>
                          </td>
                      <?php endforeach; ?>
                    <?php 
                      $cat_index++;
                    endforeach; 
                    ?>
                  </tr>
                  
                <?php else: ?>
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
                        <?php foreach (array_slice($tp_nos, 0, 3) as $tp_no): ?>
                          <span><?= htmlspecialchars($tp_no) ?></span>
                        <?php endforeach; ?>
                        <?php if (count($tp_nos) > 3): ?>
                          <span>+<?= count($tp_nos) - 3 ?> more</span>
                        <?php endif; ?>
                      <?php else: ?>
                        &nbsp;
                      <?php endif; ?>
                      </td>
                    <td>Rec.</td>
                    
                    <?php 
                    $cat_index = 0;
                    foreach ($display_categories as $category): 
                      if (empty($size_columns[$category])) {
                          $cat_index++;
                          continue;
                      }
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                    ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < $total_categories - 1) ? 'double-line-right' : '' ?>">
                          <?= isset($daily_data[$date][$category][$size]['purchase']) && $daily_data[$date][$category][$size]['purchase'] > 0 ? $daily_data[$date][$category][$size]['purchase'] : '' ?>
                          </td>
                      <?php endforeach; ?>
                    <?php 
                      $cat_index++;
                    endforeach; 
                    ?>
                  </tr>
                  
                  <tr>
                    <td>Sale</td>
                    <?php 
                    $cat_index = 0;
                    foreach ($display_categories as $category): 
                      if (empty($size_columns[$category])) {
                          $cat_index++;
                          continue;
                      }
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                    ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < $total_categories - 1) ? 'double-line-right' : '' ?>">
                          <?= isset($daily_data[$date][$category][$size]['sales']) && $daily_data[$date][$category][$size]['sales'] > 0 ? $daily_data[$date][$category][$size]['sales'] : '' ?>
                          </td>
                      <?php endforeach; ?>
                    <?php 
                      $cat_index++;
                    endforeach; 
                    ?>
                  </tr>
                  
                  <tr>
                    <td>Clo.</td>
                    <?php 
                    $cat_index = 0;
                    foreach ($display_categories as $category): 
                      if (empty($size_columns[$category])) {
                          $cat_index++;
                          continue;
                      }
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size): 
                    ?>
                        <td class="<?= ($size_index == $last_index && $cat_index < $total_categories - 1) ? 'double-line-right' : '' ?>">
                          <?= isset($daily_data[$date][$category][$size]['closing']) && $daily_data[$date][$category][$size]['closing'] > 0 ? $daily_data[$date][$category][$size]['closing'] : '' ?>
                          </td>
                      <?php endforeach; ?>
                    <?php 
                      $cat_index++;
                    endforeach; 
                    ?>
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
            <p>Generated on: <?= date('d-M-Y h:i A') ?> | Total Days: <?= $date_count ?> | Sizes sorted: Largest to Smallest</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function validateDates() {
    const fromDate = document.querySelector('input[name="from_date"]').value;
    const toDate = document.querySelector('input[name="to_date"]').value;
    const finYearStart = '<?= $fin_year_start ?>';
    const finYearEnd = '<?= $fin_year_end ?>';
    
    if (fromDate && toDate) {
        const from = new Date(fromDate);
        const to = new Date(toDate);
        const start = new Date(finYearStart);
        const end = new Date(finYearEnd);
        
        if (from < start || from > end) {
            alert('From Date must be within the financial year');
            return false;
        }
        if (to < start || to > end) {
            alert('To Date must be within the financial year');
            return false;
        }
        if (from > to) {
            alert('From Date cannot be after To Date');
            return false;
        }
        
        const diffTime = Math.abs(to - from);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays > <?= $max_days_per_request ?>) {
            return confirm('Date range is large (' + diffDays + ' days). This may take some time to load. Continue?');
        }
    }
    return true;
}

function exportToExcel() {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    btn.disabled = true;
    
    setTimeout(function() {
        var table = document.getElementById('excise-register-table');
        var wb = XLSX.utils.book_new();
        var tableClone = table.cloneNode(true);
        var ws = XLSX.utils.table_to_sheet(tableClone);
        XLSX.utils.book_append_sheet(wb, ws, 'Excise Register');
        var fileName = 'Excise_Register_<?= date('Y-m-d') ?>.xlsx';
        XLSX.writeFile(wb, fileName);
        
        btn.innerHTML = originalText;
        btn.disabled = false;
    }, 100);
}

if (typeof XLSX === 'undefined') {
    var script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    document.head.appendChild(script);
}

document.addEventListener('DOMContentLoaded', function() {
    const fromInput = document.querySelector('input[name="from_date"]');
    const toInput = document.querySelector('input[name="to_date"]');
    const finYearEnd = '<?= $fin_year_end ?>';
    
    if (fromInput && toInput) {
        fromInput.max = finYearEnd;
        toInput.max = finYearEnd;
    }
});
</script>
<?php require_once 'components/financial_year_footer.php'; ?>

</body>
</html>