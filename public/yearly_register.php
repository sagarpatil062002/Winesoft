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

// Function to extract ML value from size string
function extractMLFromSize($size) {
    preg_match('/(\d+)/', $size, $matches);
    return isset($matches[1]) ? (int)$matches[1] : 0;
}

// Get financial year info from session
$fin_year_id = $_SESSION['FIN_YEAR_ID'];

// Fetch financial year dates
$finYearQuery = "SELECT START_DATE, END_DATE FROM tblfinyear WHERE ID = ? AND ACTIVE = 1";
$finYearStmt = $conn->prepare($finYearQuery);
$finYearStmt->bind_param("i", $fin_year_id);
$finYearStmt->execute();
$finYearResult = $finYearStmt->get_result();
$finYearRow = $finYearResult->fetch_assoc();
$finYearStmt->close();

if (!$finYearRow) {
    // Fallback to default if no active financial year
    $finYearRow = ['START_DATE' => date('Y-04-01 00:00:00'), 'END_DATE' => date('Y-03-31 23:59:59', strtotime('+1 year'))];
}

$fin_start_date = date('Y-m-d', strtotime($finYearRow['START_DATE']));
$fin_end_date = date('Y-m-d', strtotime($finYearRow['END_DATE']));
$fin_year_display = date('Y', strtotime($fin_start_date)) . '-' . date('Y', strtotime($fin_end_date));

// Get selected year from request (optional override)
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Fetch company name and license number
$companyName = "";
$licenseNo = "";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
if ($companyStmt) {
    $companyStmt->bind_param("i", $compID);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    if ($row = $companyResult->fetch_assoc()) {
        $companyName = $row['COMP_NAME'];
        $licenseNo = $row['COMP_FLNO'] ? $row['COMP_FLNO'] : '3';
    }
    $companyStmt->close();
}

// Define display categories for main table (EXCLUDING MML)
$main_display_categories = [
    'IMFL',
    'IMPORTED', 
    'INDIAN WINE',
    'IMPORTED WINE',
    'FERMENTED BEER',
    'MILD BEER'
];

$category_display_names = [
    'IMFL' => 'IMFL',
    'IMPORTED' => 'IMPORTED',
    'INDIAN WINE' => 'INDIAN WINE',
    'IMPORTED WINE' => 'IMPORTED WINE',
    'FERMENTED BEER' => 'FERMENTED BEER',
    'MILD BEER' => 'MILD BEER'
];

// MML specific categories for second table
$mml_categories = ['MML', 'WINE MML'];
$mml_display_names = [
    'MML' => 'Spirit MML',
    'WINE MML' => 'Wine MML'
];

// Define size columns for each category
$spirit_sizes = [
    '50 ML', '60 ML', '90 ML', '180 ML', '200 ML', '275 ML', '330 ML', 
    '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML', '2000 ML'
];

$wine_sizes = [
    '90 ML', '180 ML', '275 ML', '330 ML', '375 ML', '500 ML', '650 ML', '750 ML', '1000 ML'
];

$beer_sizes = [
    '250 ML', '275 ML', '330 ML', '375 ML', '500 ML', '650 ML', '750 ML', '1000 ML'
];

$size_columns = [
    'IMFL' => $spirit_sizes,
    'IMPORTED' => $spirit_sizes,
    'MML' => $spirit_sizes,
    'INDIAN WINE' => $wine_sizes,
    'IMPORTED WINE' => $wine_sizes,
    'WINE MML' => $wine_sizes,
    'FERMENTED BEER' => $beer_sizes,
    'MILD BEER' => $beer_sizes
];

// Function to get table name for a specific month (OPTIMIZED)
function getTableForMonth($conn, $compID, $year, $month) {
    $current_year = date('Y');
    $current_month = date('m');
    
    // Format: tbldailystock_compID_MM_YY for archive, tbldailystock_compID for current
    if ($year == $current_year && $month == $current_month) {
        $tableName = "tbldailystock_" . $compID;
    } else {
        $short_year = substr($year, -2);
        $tableName = "tbldailystock_" . $compID . "_" . $month . "_" . $short_year;
    }
    
    // Quick existence check (cached in memory for this request)
    static $table_cache = [];
    if (!isset($table_cache[$tableName])) {
        $checkQuery = "SHOW TABLES LIKE '$tableName'";
        $checkResult = $conn->query($checkQuery);
        $table_cache[$tableName] = ($checkResult && $checkResult->num_rows > 0);
    }
    
    if (!$table_cache[$tableName]) {
        // Fallback to main table
        $tableName = "tbldailystock_" . $compID;
        if (!isset($table_cache[$tableName])) {
            $checkQuery = "SHOW TABLES LIKE '$tableName'";
            $checkResult = $conn->query($checkQuery);
            $table_cache[$tableName] = ($checkResult && $checkResult->num_rows > 0);
        }
        if (!$table_cache[$tableName]) {
            $tableName = "tbldailystock_1";
        }
    }
    
    return $tableName;
}

/**
 * Function to get column names for a specific month table
 * This checks which day columns actually exist to avoid SQL errors
 */
function getAvailableDayColumns($conn, $tableName, $month_year) {
    $days_in_month = date('t', strtotime($month_year . '-01'));
    
    // Check a few sample columns to see the pattern
    $checkQuery = "SHOW COLUMNS FROM $tableName LIKE 'DAY_01_PURCHASE'";
    $checkResult = $conn->query($checkQuery);
    
    if (!$checkResult || $checkResult->num_rows == 0) {
        return ['max_days' => 0, 'purchase_cols' => [], 'sales_cols' => []];
    }
    
    $purchase_cols = [];
    $sales_cols = [];
    $max_days = 0;
    
    // Check up to 31 days
    for ($day = 1; $day <= 31; $day++) {
        $day_padded = sprintf('%02d', $day);
        
        // Check if purchase column exists
        $checkQuery = "SHOW COLUMNS FROM $tableName LIKE 'DAY_{$day_padded}_PURCHASE'";
        $checkResult = $conn->query($checkQuery);
        if ($checkResult && $checkResult->num_rows > 0) {
            $purchase_cols[] = "DAY_{$day_padded}_PURCHASE";
            $sales_cols[] = "DAY_{$day_padded}_SALES";
            $max_days = $day;
        } else {
            // Once we hit a missing column, stop checking further days
            break;
        }
    }
    
    return [
        'max_days' => $max_days,
        'purchase_cols' => $purchase_cols,
        'sales_cols' => $sales_cols
    ];
}

// Fetch item master data with hierarchy information (ONCE)
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
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
            
            // Pre-calculate size label and matching
            $volume_label = getVolumeLabel($hierarchy['ml_volume']);
            
            $items[$row['CODE']] = [
                'code' => $row['CODE'],
                'details' => $row['DETAILS'],
                'details2' => $row['DETAILS2'],
                'class' => $row['CLASS'],
                'liq_flag' => $row['LIQ_FLAG'],
                'hierarchy' => $hierarchy,
                'volume_label' => $volume_label
            ];
        }
        $itemStmt->close();
    }
}

// Pre-calculate size matching for all items (OPTIMIZATION)
foreach ($items as $code => &$item) {
    $display_type = $item['hierarchy']['display_type'];
    $volume_label = $item['volume_label'];
    
    $matched_size = null;
    if (isset($size_columns[$display_type])) {
        if (in_array($volume_label, $size_columns[$display_type])) {
            $matched_size = $volume_label;
        } else {
            // Try numeric matching once
            foreach ($size_columns[$display_type] as $size_col) {
                preg_match('/(\d+\.?\d*)\s*(ML|L)/i', $volume_label, $vol_parts);
                preg_match('/(\d+\.?\d*)\s*(ML|L)/i', $size_col, $col_parts);
                
                if (isset($vol_parts[1]) && isset($col_parts[1])) {
                    $vol_num = floatval($vol_parts[1]);
                    $col_num = floatval($col_parts[1]);
                    
                    $vol_unit = strtoupper($vol_parts[2] ?? 'ML');
                    $col_unit = strtoupper($col_parts[2] ?? 'ML');
                    
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
    
    $item['matched_size'] = $matched_size;
    $item['is_mml'] = in_array($display_type, $mml_categories);
    $item['is_main'] = in_array($display_type, $main_display_categories);
}
unset($item);

// Generate list of months in financial year (Apr to Mar)
$fin_start_parts = explode('-', $fin_start_date);
$fin_end_parts = explode('-', $fin_end_date);

$start_year = $fin_start_parts[0];
$start_month = $fin_start_parts[1];
$end_year = $fin_end_parts[0];
$end_month = $fin_end_parts[1];

$months = [];
$current_year = $start_year;
$current_month = $start_month;

while (
    ($current_year < $end_year) || 
    ($current_year == $end_year && $current_month <= $end_month)
) {
    $month_key = $current_year . '-' . str_pad($current_month, 2, '0', STR_PAD_LEFT);
    $month_name = date('F', strtotime($current_year . '-' . $current_month . '-01'));
    $months[$month_key] = $month_name;
    
    $current_month++;
    if ($current_month > 12) {
        $current_month = 1;
        $current_year++;
    }
}

// Initialize data structures
$main_monthly_data = [];
$main_yearly_totals = [];
$main_opening_balance = [];

foreach ($main_display_categories as $category) {
    $main_yearly_totals[$category] = [
        'purchase' => array_fill_keys($size_columns[$category], 0),
        'sales' => array_fill_keys($size_columns[$category], 0),
        'closing' => array_fill_keys($size_columns[$category], 0)
    ];
    $main_opening_balance[$category] = array_fill_keys($size_columns[$category], 0);
}

$mml_monthly_data = [];
$mml_yearly_totals = [];
$mml_opening_balance = [];

foreach ($mml_categories as $category) {
    $mml_yearly_totals[$category] = [
        'purchase' => array_fill_keys($size_columns[$category], 0),
        'sales' => array_fill_keys($size_columns[$category], 0),
        'closing' => array_fill_keys($size_columns[$category], 0)
    ];
    $mml_opening_balance[$category] = array_fill_keys($size_columns[$category], 0);
}

// Initialize monthly data arrays
foreach ($months as $month_key => $month_name) {
    $main_monthly_data[$month_key] = [];
    $mml_monthly_data[$month_key] = [];
    
    foreach ($main_display_categories as $category) {
        $main_monthly_data[$month_key][$category] = [
            'purchase' => array_fill_keys($size_columns[$category], 0),
            'sales' => array_fill_keys($size_columns[$category], 0),
            'closing' => array_fill_keys($size_columns[$category], 0)
        ];
    }
    
    foreach ($mml_categories as $category) {
        $mml_monthly_data[$month_key][$category] = [
            'purchase' => array_fill_keys($size_columns[$category], 0),
            'sales' => array_fill_keys($size_columns[$category], 0),
            'closing' => array_fill_keys($size_columns[$category], 0)
        ];
    }
}

// OPTIMIZED DATA FETCHING - Process each month with dynamic queries
foreach ($months as $month_key => $month_name) {
    list($year, $month_num) = explode('-', $month_key);
    
    // Get the appropriate table for this month
    $tableName = getTableForMonth($conn, $compID, $year, $month_num);
    
    // Get available columns for this table
    $columns = getAvailableDayColumns($conn, $tableName, $month_key);
    
    if ($columns['max_days'] == 0) {
        // No valid columns found, skip this month
        continue;
    }
    
    // Build dynamic SUM queries based on available columns
    $purchase_sum = implode(' + ', $columns['purchase_cols']);
    $sales_sum = implode(' + ', $columns['sales_cols']);
    
    // Get the last day's closing column
    $last_day = $columns['max_days'];
    $last_day_padded = sprintf('%02d', $last_day);
    $closing_column = "DAY_{$last_day_padded}_CLOSING";
    
    // Check if closing column exists (it should if purchase/sales exist)
    $checkClosingQuery = "SHOW COLUMNS FROM $tableName LIKE '$closing_column'";
    $checkClosingResult = $conn->query($checkClosingQuery);
    if (!$checkClosingResult || $checkClosingResult->num_rows == 0) {
        // If closing column doesn't exist, use the last available day's closing
        // This is a fallback - try to find any closing column
        $closing_column = "DAY_{$last_day_padded}_CLOSING"; // Keep as is, will be NULL if not exists
    }
    
    // Fetch all stock data for this month in one query
    $stockQuery = "SELECT ITEM_CODE, 
                   COALESCE($purchase_sum, 0) as total_purchase,
                   COALESCE($sales_sum, 0) as total_sales,
                   DAY_01_OPEN as opening,
                   $closing_column as closing
                   FROM $tableName 
                   WHERE STK_MONTH = ?
                   GROUP BY ITEM_CODE";
    
    $stockStmt = $conn->prepare($stockQuery);
    if ($stockStmt) {
        $stockStmt->bind_param("s", $month_key);
        $stockStmt->execute();
        $stockResult = $stockStmt->get_result();
        
        while ($row = $stockResult->fetch_assoc()) {
            $item_code = $row['ITEM_CODE'];
            
            if (!isset($items[$item_code])) continue;
            
            $item = $items[$item_code];
            $display_type = $item['hierarchy']['display_type'];
            $matched_size = $item['matched_size'];
            $is_mml = $item['is_mml'];
            $is_main = $item['is_main'];
            
            if (!$matched_size) continue;
            
            // Track opening balance from first month (April)
            if ($month_key == $fin_start_date) {
                if ($is_main) {
                    $main_opening_balance[$display_type][$matched_size] += (int)$row['opening'];
                } elseif ($is_mml) {
                    $mml_opening_balance[$display_type][$matched_size] += (int)$row['opening'];
                }
            }
            
            if ($is_main) {
                // Add to monthly data
                $main_monthly_data[$month_key][$display_type]['purchase'][$matched_size] += (int)$row['total_purchase'];
                $main_monthly_data[$month_key][$display_type]['sales'][$matched_size] += (int)$row['total_sales'];
                $main_monthly_data[$month_key][$display_type]['closing'][$matched_size] += (int)$row['closing'];
                
                // Add to yearly totals
                $main_yearly_totals[$display_type]['purchase'][$matched_size] += (int)$row['total_purchase'];
                $main_yearly_totals[$display_type]['sales'][$matched_size] += (int)$row['total_sales'];
                $main_yearly_totals[$display_type]['closing'][$matched_size] += (int)$row['closing'];
                
            } elseif ($is_mml) {
                // Add to monthly data for MML
                $mml_monthly_data[$month_key][$display_type]['purchase'][$matched_size] += (int)$row['total_purchase'];
                $mml_monthly_data[$month_key][$display_type]['sales'][$matched_size] += (int)$row['total_sales'];
                $mml_monthly_data[$month_key][$display_type]['closing'][$matched_size] += (int)$row['closing'];
                
                // Add to yearly totals for MML
                $mml_yearly_totals[$display_type]['purchase'][$matched_size] += (int)$row['total_purchase'];
                $mml_yearly_totals[$display_type]['sales'][$matched_size] += (int)$row['total_sales'];
                $mml_yearly_totals[$display_type]['closing'][$matched_size] += (int)$row['closing'];
            }
        }
        $stockStmt->close();
    }
}

// Calculate summary in liters for main categories
$summary_liters_main = [];
foreach ($main_display_categories as $category) {
    $summary_liters_main[$category] = [
        'opening' => 0,
        'received' => 0,
        'sold' => 0,
        'closing' => 0
    ];
    
    foreach ($size_columns[$category] as $size) {
        $ml = extractMLFromSize($size);
        $liters_factor = $ml / 1000;
        
        $summary_liters_main[$category]['opening'] += ($main_opening_balance[$category][$size] ?? 0) * $liters_factor;
        $summary_liters_main[$category]['received'] += ($main_yearly_totals[$category]['purchase'][$size] ?? 0) * $liters_factor;
        $summary_liters_main[$category]['sold'] += ($main_yearly_totals[$category]['sales'][$size] ?? 0) * $liters_factor;
        $summary_liters_main[$category]['closing'] += ($main_yearly_totals[$category]['closing'][$size] ?? 0) * $liters_factor;
    }
}

// Calculate summary in liters for MML categories
$summary_liters_mml = [];
foreach ($mml_categories as $category) {
    $summary_liters_mml[$category] = [
        'opening' => 0,
        'received' => 0,
        'sold' => 0,
        'closing' => 0
    ];
    
    foreach ($size_columns[$category] as $size) {
        $ml = extractMLFromSize($size);
        $liters_factor = $ml / 1000;
        
        $summary_liters_mml[$category]['opening'] += ($mml_opening_balance[$category][$size] ?? 0) * $liters_factor;
        $summary_liters_mml[$category]['received'] += ($mml_yearly_totals[$category]['purchase'][$size] ?? 0) * $liters_factor;
        $summary_liters_mml[$category]['sold'] += ($mml_yearly_totals[$category]['sales'][$size] ?? 0) * $liters_factor;
        $summary_liters_mml[$category]['closing'] += ($mml_yearly_totals[$category]['closing'][$size] ?? 0) * $liters_factor;
    }
}

// Calculate total columns for main table
$total_main_columns = 0;
foreach ($main_display_categories as $category) {
    $total_main_columns += count($size_columns[$category]);
}

// Calculate total columns for MML table
$total_mml_columns = 0;
foreach ($mml_categories as $category) {
    $total_mml_columns += count($size_columns[$category]);
}

// Get last month key
$last_month_key = array_key_last($months);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FLR 1A/2A/3A Yearly Register (Financial Year) - liqoursoft</title>
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
      min-width: 25px;
      max-width: 25px;
      width: 25px;
      font-size: 9px;
      line-height: 1.1;
      font-weight: bold;
    }
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
    }
    .closing-balance {
      font-weight: bold !important;
      color: #000 !important;
      background-color: #d3d3d3 !important;
    }
    .double-line-right {
      border-right: 3px double #000 !important;
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
    .month-col {
      width: 45px;
      min-width: 45px;
      font-weight: bold;
    }
    .permit-col, .signature-col {
      width: 50px;
      min-width: 50px;
    }
    .size-col {
      width: 25px;
      min-width: 25px;
      max-width: 25px;
    }
    .description-col {
      width: 120px;
      min-width: 120px;
      text-align: left !important;
      font-weight: bold;
    }
    .summary-table {
      margin-top: 20px;
      margin-bottom: 30px;
      width: 100%;
    }
    .summary-table th {
      background-color: #e9ecef;
    }
    .mml-section {
      margin-top: 30px;
      page-break-before: always;
    }
    .mml-header {
      background-color: #d4edda;
      color: #155724;
      padding: 10px;
      margin-bottom: 15px;
      border-radius: 5px;
      font-weight: bold;
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
        font-size: 7px !important;
        table-layout: fixed;
        border-collapse: collapse;
        page-break-inside: avoid;
      }
      
      .report-table th, .report-table td {
        padding: 2px 1px !important;
        line-height: 1;
        height: 16px;
        min-width: 20px;
        max-width: 22px;
        font-size: 7px !important;
        border: 1px solid #000 !important;
      }
      
      .report-table th {
        background-color: #f0f0f0 !important;
        padding: 3px 1px !important;
        font-weight: bold;
      }
      
      .vertical-text {
        writing-mode: vertical-lr;
        transform: rotate(180deg);
        text-align: center;
        white-space: nowrap;
        padding: 2px !important;
        font-size: 6px !important;
        min-width: 18px;
        max-width: 20px;
        width: 20px !important;
        line-height: 1;
        height: auto !important;
      }
      
      .month-col {
        width: 35px !important;
        min-width: 35px !important;
        max-width: 35px !important;
        font-weight: bold;
      }
      
      .permit-col, .signature-col {
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
        font-size: 7px;
        page-break-before: avoid;
      }
      
      tr {
        page-break-inside: avoid;
        page-break-after: auto;
      }
      
      .summary-table {
        page-break-inside: avoid;
      }
      
      .mml-section {
        margin-top: 20px;
        page-break-before: always;
      }
      
      .mml-header {
        background-color: #d4edda !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
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
      <h3 class="mb-4">FLR 1A/2A/3A Yearly Register (Financial Year)</h3>

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

      <!-- Classification Note -->
      <div class="classification-note no-print">
          <strong>Classification Logic (as per Excise Register):</strong><br>
          • <strong>IMFL:</strong> Indian Made Foreign Liquor - Spirits<br>
          • <strong>IMPORTED:</strong> Imported Spirits<br>
          • <strong>INDIAN WINE:</strong> Indian Made Wine<br>
          • <strong>IMPORTED WINE:</strong> Imported Wine<br>
          • <strong>FERMENTED BEER:</strong> Fermented Beer (Class F)<br>
          • <strong>MILD BEER:</strong> Mild Beer (Class M)<br>
          • <strong>MML Items (Spirit & Wine):</strong> Shown in separate table below
      </div>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-4">
                <label class="form-label">Financial Year:</label>
                <select name="fin_year" class="form-control" disabled>
                  <option value="<?= $fin_year_id ?>" selected><?= $fin_year_display ?></option>
                </select>
                <small class="text-muted">Using active financial year from settings</small>
              </div>
              <div class="col-md-3">
                <label class="form-label">Period:</label>
                <div class="form-control-plaintext">
                  <strong><?= date('d-M-Y', strtotime($fin_start_date)) ?> to <?= date('d-M-Y', strtotime($fin_end_date)) ?></strong>
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

      <!-- Main Report Table (Without MML) - MONTH WISE -->
      <div class="print-section">
        <div class="company-header">
          <h1>Form F.L.R. 1A/2A/3A (See Rule 15)</h1>
          <h5>YEARLY REGISTER OF TRANSACTION OF FOREIGN LIQUOR EFFECTED BY HOLDER OF VENDOR'S/HOTEL/CLUB LICENCE</h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>Financial Year : <?= $fin_year_display ?> (<?= date('d-M-Y', strtotime($fin_start_date)) ?> to <?= date('d-M-Y', strtotime($fin_end_date)) ?>)</h6>
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table" id="flr-yearly-table">
            <thead>
              <tr>
                <th rowspan="3" class="month-col">Month</th>
                <th rowspan="3" class="permit-col">Permit No</th>
                <th colspan="<?= $total_main_columns ?>">Received</th>
                <th colspan="<?= $total_main_columns ?>">Sold</th>
                <th colspan="<?= $total_main_columns ?>">Closing Balance</th>
                <th rowspan="3" class="signature-col">Signature</th>
              </tr>
              <tr>
                <?php foreach ($main_display_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
                <?php foreach ($main_display_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
                <?php foreach ($main_display_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
              <tr>
                <!-- Received sizes -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- Sold sizes -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- Closing sizes -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($months as $month_key => $month_name): ?>
                <?php if (!isset($main_monthly_data[$month_key])) continue; ?>
                <tr>
                  <td class="month-col"><?= $month_name ?></td>
                  <td class="permit-col"></td>
                  
                  <!-- Received -->
                  <?php foreach ($main_display_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                        <?= isset($main_monthly_data[$month_key][$category]['purchase'][$size]) && $main_monthly_data[$month_key][$category]['purchase'][$size] > 0 ? $main_monthly_data[$month_key][$category]['purchase'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>

                  <!-- Sold -->
                  <?php foreach ($main_display_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                        <?= isset($main_monthly_data[$month_key][$category]['sales'][$size]) && $main_monthly_data[$month_key][$category]['sales'][$size] > 0 ? $main_monthly_data[$month_key][$category]['sales'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>

                  <!-- Closing -->
                  <?php foreach ($main_display_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?> <?= ($size_index == $last_index) ? 'closing-balance' : '' ?>">
                        <?= isset($main_monthly_data[$month_key][$category]['closing'][$size]) && $main_monthly_data[$month_key][$category]['closing'][$size] > 0 ? $main_monthly_data[$month_key][$category]['closing'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                  
                  <td class="signature-col"></td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Opening Balance Row -->
              <tr class="summary-row">
                <td>Opening Balance</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Opening Balance values in Closing section -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_opening_balance[$category][$size]) && $main_opening_balance[$category][$size] > 0 ? $main_opening_balance[$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>

              <!-- Total Received Row -->
              <tr class="summary-row">
                <td>Total Received</td>
                <td></td>
                
                <!-- Received totals -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_yearly_totals[$category]['purchase'][$size]) && $main_yearly_totals[$category]['purchase'][$size] > 0 ? $main_yearly_totals[$category]['purchase'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Closing -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <td></td>
              </tr>

              <!-- Total Sold Row -->
              <tr class="summary-row">
                <td>Total Sold</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Sold totals -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_yearly_totals[$category]['sales'][$size]) && $main_yearly_totals[$category]['sales'][$size] > 0 ? $main_yearly_totals[$category]['sales'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <!-- Empty for Closing -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <td></td>
              </tr>

              <!-- Year End Closing Row -->
              <tr class="summary-row">
                <td>Year End Closing</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Last month closing in Closing section -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_monthly_data[$last_month_key][$category]['closing'][$size]) && $main_monthly_data[$last_month_key][$category]['closing'][$size] > 0 ? $main_monthly_data[$last_month_key][$category]['closing'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>

              <!-- Verification Row -->
              <tr class="summary-row">
                <td>Verification (O+R-S)</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Calculated Closing -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                    $opening = isset($main_opening_balance[$category][$size]) ? $main_opening_balance[$category][$size] : 0;
                    $received = isset($main_yearly_totals[$category]['purchase'][$size]) ? $main_yearly_totals[$category]['purchase'][$size] : 0;
                    $sold = isset($main_yearly_totals[$category]['sales'][$size]) ? $main_yearly_totals[$category]['sales'][$size] : 0;
                    $calculated = $opening + $received - $sold;
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= $calculated > 0 ? $calculated : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Summary in Liters Table -->
        <div class="summary-table">
          <h5 class="text-center mb-3">YEARLY SUMMARY (IN LITERS)</h5>
          <table class="report-table">
            <thead>
              <tr>
                <th>Description</th>
                <?php foreach ($main_display_categories as $category): ?>
                  <th><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="text-start fw-bold">Opening Balance (Liters)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['opening'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="text-start fw-bold">Received (Liters)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['received'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="text-start fw-bold">Sold (Liters)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['sold'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr class="summary-row">
                <td class="text-start fw-bold">Closing Balance (Liters)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['closing'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <p>Note: This is a computer generated yearly report based on financial year (Apr-Mar).</p>
          <p>Generated on: <?= date('d-M-Y h:i A') ?> | Financial Year: <?= $fin_year_display ?></p>
        </div>
      </div>

      <!-- MML Section - Second Table -->
      <div class="mml-section print-section">
        <div class="mml-header">
          <h4 class="mb-0">MML Yearly Report (Spirit MML & Wine MML Only) - Month Wise</h4>
        </div>
        
        <div class="company-header">
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>Financial Year : <?= $fin_year_display ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table" id="mml-yearly-table">
            <thead>
              <tr>
                <th rowspan="3" class="month-col">Month</th>
                <th rowspan="3" class="permit-col">Permit No</th>
                <th colspan="<?= $total_mml_columns ?>">Received</th>
                <th colspan="<?= $total_mml_columns ?>">Sold</th>
                <th colspan="<?= $total_mml_columns ?>">Closing Balance</th>
                <th rowspan="3" class="signature-col">Signature</th>
              </tr>
              <tr>
                <?php foreach ($mml_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $mml_display_names[$category] ?></th>
                <?php endforeach; ?>
                <?php foreach ($mml_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $mml_display_names[$category] ?></th>
                <?php endforeach; ?>
                <?php foreach ($mml_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $mml_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
              <tr>
                <!-- Received sizes -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- Sold sizes -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- Closing sizes -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($months as $month_key => $month_name): ?>
                <?php if (!isset($mml_monthly_data[$month_key])) continue; ?>
                <tr>
                  <td class="month-col"><?= $month_name ?></td>
                  <td class="permit-col"></td>
                  
                  <!-- Received -->
                  <?php foreach ($mml_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                        <?= isset($mml_monthly_data[$month_key][$category]['purchase'][$size]) && $mml_monthly_data[$month_key][$category]['purchase'][$size] > 0 ? $mml_monthly_data[$month_key][$category]['purchase'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>

                  <!-- Sold -->
                  <?php foreach ($mml_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                        <?= isset($mml_monthly_data[$month_key][$category]['sales'][$size]) && $mml_monthly_data[$month_key][$category]['sales'][$size] > 0 ? $mml_monthly_data[$month_key][$category]['sales'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>

                  <!-- Closing -->
                  <?php foreach ($mml_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?> <?= ($size_index == $last_index) ? 'closing-balance' : '' ?>">
                        <?= isset($mml_monthly_data[$month_key][$category]['closing'][$size]) && $mml_monthly_data[$month_key][$category]['closing'][$size] > 0 ? $mml_monthly_data[$month_key][$category]['closing'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                  
                  <td class="signature-col"></td>
                </tr>
              <?php endforeach; ?>
              
              <!-- MML Opening Balance Row -->
              <tr class="summary-row">
                <td>Opening Balance</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Opening Balance values in Closing section -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_opening_balance[$category][$size]) && $mml_opening_balance[$category][$size] > 0 ? $mml_opening_balance[$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>

              <!-- MML Total Received Row -->
              <tr class="summary-row">
                <td>Total Received (MML)</td>
                <td></td>
                
                <!-- Received totals -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_yearly_totals[$category]['purchase'][$size]) && $mml_yearly_totals[$category]['purchase'][$size] > 0 ? $mml_yearly_totals[$category]['purchase'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Closing -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <td></td>
              </tr>

              <!-- MML Total Sold Row -->
              <tr class="summary-row">
                <td>Total Sold (MML)</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Sold totals -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_yearly_totals[$category]['sales'][$size]) && $mml_yearly_totals[$category]['sales'][$size] > 0 ? $mml_yearly_totals[$category]['sales'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <!-- Empty for Closing -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <td></td>
              </tr>

              <!-- MML Year End Closing Row -->
              <tr class="summary-row">
                <td>Year End Closing (MML)</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Last month closing -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_monthly_data[$last_month_key][$category]['closing'][$size]) && $mml_monthly_data[$last_month_key][$category]['closing'][$size] > 0 ? $mml_monthly_data[$last_month_key][$category]['closing'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>

              <!-- MML Verification Row -->
              <tr class="summary-row">
                <td>Verification</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Calculated Closing -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                    $opening = isset($mml_opening_balance[$category][$size]) ? $mml_opening_balance[$category][$size] : 0;
                    $received = isset($mml_yearly_totals[$category]['purchase'][$size]) ? $mml_yearly_totals[$category]['purchase'][$size] : 0;
                    $sold = isset($mml_yearly_totals[$category]['sales'][$size]) ? $mml_yearly_totals[$category]['sales'][$size] : 0;
                    $calculated = $opening + $received - $sold;
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= $calculated > 0 ? $calculated : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- MML Summary in Liters -->
        <div class="summary-table mt-3">
          <h5 class="text-center mb-3">MML YEARLY SUMMARY (IN LITERS)</h5>
          <table class="report-table">
            <thead>
              <tr>
                <th>Description</th>
                <?php foreach ($mml_categories as $category): ?>
                  <th><?= $mml_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="text-start fw-bold">Opening Balance (Liters)</td>
                <?php foreach ($mml_categories as $category): ?>
                  <td><?= number_format($summary_liters_mml[$category]['opening'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="text-start fw-bold">Received (Liters)</td>
                <?php foreach ($mml_categories as $category): ?>
                  <td><?= number_format($summary_liters_mml[$category]['received'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="text-start fw-bold">Sold (Liters)</td>
                <?php foreach ($mml_categories as $category): ?>
                  <td><?= number_format($summary_liters_mml[$category]['sold'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr class="summary-row">
                <td class="text-start fw-bold">Closing Balance (Liters)</td>
                <?php foreach ($mml_categories as $category): ?>
                  <td><?= number_format($summary_liters_mml[$category]['closing'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info mt-3">
          <p><strong>MML Yearly Summary:</strong> 
             Spirit MML Total Received: <?= array_sum($mml_yearly_totals['MML']['purchase'] ?? []) ?> | 
             Spirit MML Total Sold: <?= array_sum($mml_yearly_totals['MML']['sales'] ?? []) ?> | 
             Spirit MML Closing: <?= array_sum($mml_monthly_data[$last_month_key]['MML']['closing'] ?? []) ?><br>
             Wine MML Total Received: <?= array_sum($mml_yearly_totals['WINE MML']['purchase'] ?? []) ?> | 
             Wine MML Total Sold: <?= array_sum($mml_yearly_totals['WINE MML']['sales'] ?? []) ?> | 
             Wine MML Closing: <?= array_sum($mml_monthly_data[$last_month_key]['WINE MML']['closing'] ?? []) ?>
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportToExcel() {
    var mainTable = document.getElementById('flr-yearly-table');
    var mmlTable = document.getElementById('mml-yearly-table');
    var wb = XLSX.utils.book_new();
    
    // Add main table
    var mainClone = mainTable.cloneNode(true);
    var ws1 = XLSX.utils.table_to_sheet(mainClone);
    XLSX.utils.book_append_sheet(wb, ws1, 'FLR Yearly');
    
    // Add MML table
    var mmlClone = mmlTable.cloneNode(true);
    var ws2 = XLSX.utils.table_to_sheet(mmlClone);
    XLSX.utils.book_append_sheet(wb, ws2, 'MML Yearly');
    
    var fileName = 'FLR_Yearly_<?= $fin_year_display ?>.xlsx';
    XLSX.writeFile(wb, fileName);
}

function exportToCSV() {
    var table = document.getElementById('flr-yearly-table');
    var ws = XLSX.utils.table_to_sheet(table);
    var fileName = 'FLR_Yearly_<?= $fin_year_display ?>.csv';
    XLSX.writeFile(ws, fileName);
}

function exportToPDF() {
    const element = document.querySelector('.print-section');
    const opt = {
        margin: 0.5,
        filename: 'FLR_Yearly_<?= $fin_year_display ?>.pdf',
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
</script>
</body>
</html>
<?php $conn->close(); ?>