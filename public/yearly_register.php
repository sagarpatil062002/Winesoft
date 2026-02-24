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

// Function to get table name for a specific month
function getTableForMonth($conn, $compID, $year, $month) {
    $current_year = date('Y');
    $current_month = date('m');
    $tablePrefix = "tbldailystock_" . $compID;
    
    // For current month, use main table
    if ($year == $current_year && $month == $current_month) {
        $tableName = $tablePrefix;
    } else {
        // For archive months, try both formats: MM_YY and MM_YYYY
        $short_year = substr($year, -2);
        $tableName = $tablePrefix . "_" . $month . "_" . $short_year;
        
        // Check if table exists
        $checkQuery = "SHOW TABLES LIKE '$tableName'";
        $checkResult = $conn->query($checkQuery);
        
        if (!$checkResult || $checkResult->num_rows == 0) {
            // Try with full year
            $altTableName = $tablePrefix . "_" . $month . "_" . $year;
            $checkQuery = "SHOW TABLES LIKE '$altTableName'";
            $checkResult = $conn->query($checkQuery);
            
            if ($checkResult && $checkResult->num_rows > 0) {
                $tableName = $altTableName;
            } else {
                // Fallback to main table
                $tableName = $tablePrefix;
            }
        }
    }
    
    // Final check if table exists
    static $table_cache = [];
    if (!isset($table_cache[$tableName])) {
        $checkQuery = "SHOW TABLES LIKE '$tableName'";
        $checkResult = $conn->query($checkQuery);
        $table_cache[$tableName] = ($checkResult && $checkResult->num_rows > 0);
    }
    
    if (!$table_cache[$tableName]) {
        // Ultimate fallback
        $tableName = "tbldailystock_1";
    }
    
    return $tableName;
}

// Function to get available months from all tables
function getAvailableMonthsInYear($conn, $compID, $year) {
    $available_months = [];
    $tablePrefix = "tbldailystock_" . $compID;
    
    // Check main table first
    $mainTableExists = false;
    $checkMainQuery = "SHOW TABLES LIKE '$tablePrefix'";
    $mainResult = $conn->query($checkMainQuery);
    if ($mainResult && $mainResult->num_rows > 0) {
        $mainTableExists = true;
        // Get months from main table for the specified year
        $monthQuery = "SELECT DISTINCT STK_MONTH FROM $tablePrefix 
                       WHERE STK_MONTH IS NOT NULL AND STK_MONTH != '' 
                       AND STK_MONTH LIKE '$year-%'
                       ORDER BY STK_MONTH DESC";
        $monthResult = $conn->query($monthQuery);
        if ($monthResult) {
            while ($monthRow = $monthResult->fetch_assoc()) {
                $stk_month = trim($monthRow['STK_MONTH']);
                if (preg_match('/^\d{4}-\d{2}$/', $stk_month)) {
                    $available_months[] = $stk_month;
                }
            }
        }
    }
    
    // Check for archive tables for this year
    $archiveQuery = "SHOW TABLES LIKE '{$tablePrefix}_%'";
    $archiveResult = $conn->query($archiveQuery);
    
    if ($archiveResult) {
        while ($row = $archiveResult->fetch_array()) {
            $tableName = $row[0];
            
            // Extract month and year from table name
            if (preg_match('/_(\d{2})_(\d{2,4})$/', $tableName, $matches)) {
                $month_num = $matches[1];
                $year_part = $matches[2];
                
                if (strlen($year_part) == 2) {
                    $year_full = '20' . $year_part;
                } else {
                    $year_full = $year_part;
                }
                
                // Only include if year matches
                if ($year_full == $year) {
                    // Get the STK_MONTH from the table to verify
                    $monthQuery = "SELECT DISTINCT STK_MONTH FROM $tableName 
                                   WHERE STK_MONTH IS NOT NULL AND STK_MONTH != '' 
                                   LIMIT 1";
                    $monthResult = $conn->query($monthQuery);
                    if ($monthResult && $monthRow = $monthResult->fetch_assoc()) {
                        $stk_month = trim($monthRow['STK_MONTH']);
                        if (preg_match('/^\d{4}-\d{2}$/', $stk_month)) {
                            $available_months[] = $stk_month;
                        }
                    } else {
                        // If no data, construct from table name
                        $available_months[] = $year_full . '-' . $month_num;
                    }
                }
            }
        }
    }
    
    // Remove duplicates and sort
    $available_months = array_unique($available_months);
    sort($available_months); // Sort ascending for year view
    
    return $available_months;
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

// Parse financial year
$fin_start_year = date('Y', strtotime($fin_start_date));
$fin_start_month = date('m', strtotime($fin_start_date));
$fin_end_year = date('Y', strtotime($fin_end_date));
$fin_end_month = date('m', strtotime($fin_end_date));

// Current date for closing balance
$current_date = date('Y-m-d');
$current_day = (int)date('d');
$current_month = date('Y-m');
$current_year = date('Y');
$current_month_num = date('m');

// Check if current date is within financial year
$is_current_date_in_fin_year = ($current_date >= $fin_start_date && $current_date <= $fin_end_date);

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
    'MML' => 'SPIRIT MML',
    'WINE MML' => 'WINE MML'
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

// Fetch item master data with hierarchy information
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

// Pre-calculate size matching for all items
foreach ($items as $code => &$item) {
    $display_type = $item['hierarchy']['display_type'];
    $volume_label = $item['volume_label'];
    
    $matched_size = null;
    if (isset($size_columns[$display_type])) {
        if (in_array($volume_label, $size_columns[$display_type])) {
            $matched_size = $volume_label;
        } else {
            // Try numeric matching
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

// Initialize yearly data structure for main categories
$main_yearly_data = [
    'opening' => [],
    'received' => [],
    'sold' => [],
    'closing' => [],
    'breakages' => []
];

foreach ($main_display_categories as $category) {
    $main_yearly_data['opening'][$category] = array_fill_keys($size_columns[$category], 0);
    $main_yearly_data['received'][$category] = array_fill_keys($size_columns[$category], 0);
    $main_yearly_data['sold'][$category] = array_fill_keys($size_columns[$category], 0);
    $main_yearly_data['closing'][$category] = array_fill_keys($size_columns[$category], 0);
    $main_yearly_data['breakages'][$category] = array_fill_keys($size_columns[$category], 0);
}

// Initialize yearly data structure for MML categories
$mml_yearly_data = [
    'opening' => [],
    'received' => [],
    'sold' => [],
    'closing' => [],
    'breakages' => []
];

foreach ($mml_categories as $category) {
    $mml_yearly_data['opening'][$category] = array_fill_keys($size_columns[$category], 0);
    $mml_yearly_data['received'][$category] = array_fill_keys($size_columns[$category], 0);
    $mml_yearly_data['sold'][$category] = array_fill_keys($size_columns[$category], 0);
    $mml_yearly_data['closing'][$category] = array_fill_keys($size_columns[$category], 0);
    $mml_yearly_data['breakages'][$category] = array_fill_keys($size_columns[$category], 0);
}

// ============================================
// PART 1: Get OPENING BALANCE from first day of financial year (DAY_01_OPEN of first month)
// ============================================
$first_month_key = $fin_start_year . '-' . $fin_start_month;
$first_month_table = getTableForMonth($conn, $compID, $fin_start_year, $fin_start_month);

// Check if DAY_01_OPEN exists in the first month's table
$checkColumnQuery = "SHOW COLUMNS FROM $first_month_table LIKE 'DAY_01_OPEN'";
$checkResult = $conn->query($checkColumnQuery);
if ($checkResult && $checkResult->num_rows > 0) {
    $openingQuery = "SELECT ITEM_CODE, DAY_01_OPEN as opening FROM $first_month_table WHERE STK_MONTH = ?";
    $openingStmt = $conn->prepare($openingQuery);
    if ($openingStmt) {
        $openingStmt->bind_param("s", $first_month_key);
        $openingStmt->execute();
        $openingResult = $openingStmt->get_result();
        
        while ($row = $openingResult->fetch_assoc()) {
            $item_code = $row['ITEM_CODE'];
            
            if (!isset($items[$item_code])) continue;
            
            $item = $items[$item_code];
            $display_type = $item['hierarchy']['display_type'];
            $matched_size = $item['matched_size'];
            $is_mml = $item['is_mml'];
            $is_main = $item['is_main'];
            
            if (!$matched_size) continue;
            
            $opening_qty = (int)$row['opening'];
            
            if ($is_main) {
                $main_yearly_data['opening'][$display_type][$matched_size] += $opening_qty;
            } elseif ($is_mml) {
                $mml_yearly_data['opening'][$display_type][$matched_size] += $opening_qty;
            }
        }
        $openingStmt->close();
    }
}

// ============================================
// PART 2: Get TOTAL RECEIVED and SOLD for entire financial year
// Generate list of months in financial year
// ============================================
$months_in_year = [];
$current_timestamp = strtotime($fin_start_date);
$end_timestamp = strtotime($fin_end_date);

while ($current_timestamp <= $end_timestamp) {
    $year = date('Y', $current_timestamp);
    $month = date('m', $current_timestamp);
    $month_key = $year . '-' . $month;
    $months_in_year[$month_key] = [
        'year' => $year,
        'month' => $month,
        'name' => date('F Y', $current_timestamp)
    ];
    $current_timestamp = strtotime('+1 month', $current_timestamp);
}

// Process each month for received and sold
foreach ($months_in_year as $month_key => $month_info) {
    $year = $month_info['year'];
    $month_num = $month_info['month'];
    
    // Get the appropriate table for this month
    $tableName = getTableForMonth($conn, $compID, $year, $month_num);
    
    // Check if table exists and has data for this month
    $checkTableQuery = "SHOW TABLES LIKE '$tableName'";
    $tableCheckResult = $conn->query($checkTableQuery);
    if (!$tableCheckResult || $tableCheckResult->num_rows == 0) {
        continue; // Skip if table doesn't exist
    }
    
    // Build dynamic column list for this table
    $purchase_cols = [];
    $sales_cols = [];
    
    // Get days in this month
    $days_in_month = date('t', strtotime($month_key . '-01'));
    
    // Check which day columns exist
    for ($day = 1; $day <= $days_in_month; $day++) {
        $day_padded = sprintf('%02d', $day);
        $checkPurchaseQuery = "SHOW COLUMNS FROM $tableName LIKE 'DAY_{$day_padded}_PURCHASE'";
        $checkPurchaseResult = $conn->query($checkPurchaseQuery);
        if ($checkPurchaseResult && $checkPurchaseResult->num_rows > 0) {
            $purchase_cols[] = "DAY_{$day_padded}_PURCHASE";
        }
        
        $checkSalesQuery = "SHOW COLUMNS FROM $tableName LIKE 'DAY_{$day_padded}_SALES'";
        $checkSalesResult = $conn->query($checkSalesQuery);
        if ($checkSalesResult && $checkSalesResult->num_rows > 0) {
            $sales_cols[] = "DAY_{$day_padded}_SALES";
        }
    }
    
    if (empty($purchase_cols) && empty($sales_cols)) {
        continue; // Skip month if no data columns
    }
    
    $purchase_sum = !empty($purchase_cols) ? implode(' + ', $purchase_cols) : '0';
    $sales_sum = !empty($sales_cols) ? implode(' + ', $sales_cols) : '0';
    
    $stockQuery = "SELECT ITEM_CODE, 
                   COALESCE($purchase_sum, 0) as total_purchase,
                   COALESCE($sales_sum, 0) as total_sales
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
            
            $purchase_qty = (int)$row['total_purchase'];
            $sales_qty = (int)$row['total_sales'];
            
            if ($is_main) {
                $main_yearly_data['received'][$display_type][$matched_size] += $purchase_qty;
                $main_yearly_data['sold'][$display_type][$matched_size] += $sales_qty;
            } elseif ($is_mml) {
                $mml_yearly_data['received'][$display_type][$matched_size] += $purchase_qty;
                $mml_yearly_data['sold'][$display_type][$matched_size] += $sales_qty;
            }
        }
        $stockStmt->close();
    }
}

// ============================================
// PART 3: Get CLOSING BALANCE from current date or last day of financial year
// ============================================
$closing_date = $current_date;
$closing_year = $current_year;
$closing_month = $current_month_num;
$closing_day = $current_day;

// If current date is beyond financial year, use last day of financial year
if (!$is_current_date_in_fin_year) {
    $closing_date = $fin_end_date;
    $closing_year = $fin_end_year;
    $closing_month = $fin_end_month;
    $closing_day = (int)date('d', strtotime($fin_end_date));
}

$closing_month_key = $closing_year . '-' . sprintf('%02d', $closing_month);
$closing_table = getTableForMonth($conn, $compID, $closing_year, sprintf('%02d', $closing_month));

// Check if the closing day column exists
$day_padded = sprintf('%02d', $closing_day);
$checkColumnQuery = "SHOW COLUMNS FROM $closing_table LIKE 'DAY_{$day_padded}_CLOSING'";
$checkResult = $conn->query($checkColumnQuery);

if ($checkResult && $checkResult->num_rows > 0) {
    $closingQuery = "SELECT ITEM_CODE, DAY_{$day_padded}_CLOSING as closing FROM $closing_table WHERE STK_MONTH = ?";
    $closingStmt = $conn->prepare($closingQuery);
    if ($closingStmt) {
        $closingStmt->bind_param("s", $closing_month_key);
        $closingStmt->execute();
        $closingResult = $closingStmt->get_result();
        
        while ($row = $closingResult->fetch_assoc()) {
            $item_code = $row['ITEM_CODE'];
            
            if (!isset($items[$item_code])) continue;
            
            $item = $items[$item_code];
            $display_type = $item['hierarchy']['display_type'];
            $matched_size = $item['matched_size'];
            $is_mml = $item['is_mml'];
            $is_main = $item['is_main'];
            
            if (!$matched_size) continue;
            
            $closing_qty = (int)$row['closing'];
            
            if ($is_main) {
                $main_yearly_data['closing'][$display_type][$matched_size] += $closing_qty;
            } elseif ($is_mml) {
                $mml_yearly_data['closing'][$display_type][$matched_size] += $closing_qty;
            }
        }
        $closingStmt->close();
    }
} else {
    // If specific day column doesn't exist, try to get the latest available closing for that month
    for ($day = $closing_day; $day >= 1; $day--) {
        $day_padded = sprintf('%02d', $day);
        $checkColumnQuery = "SHOW COLUMNS FROM $closing_table LIKE 'DAY_{$day_padded}_CLOSING'";
        $checkResult = $conn->query($checkColumnQuery);
        
        if ($checkResult && $checkResult->num_rows > 0) {
            $closingQuery = "SELECT ITEM_CODE, DAY_{$day_padded}_CLOSING as closing FROM $closing_table WHERE STK_MONTH = ?";
            $closingStmt = $conn->prepare($closingQuery);
            if ($closingStmt) {
                $closingStmt->bind_param("s", $closing_month_key);
                $closingStmt->execute();
                $closingResult = $closingStmt->get_result();
                
                while ($row = $closingResult->fetch_assoc()) {
                    $item_code = $row['ITEM_CODE'];
                    
                    if (!isset($items[$item_code])) continue;
                    
                    $item = $items[$item_code];
                    $display_type = $item['hierarchy']['display_type'];
                    $matched_size = $item['matched_size'];
                    $is_mml = $item['is_mml'];
                    $is_main = $item['is_main'];
                    
                    if (!$matched_size) continue;
                    
                    $closing_qty = (int)$row['closing'];
                    
                    if ($is_main) {
                        $main_yearly_data['closing'][$display_type][$matched_size] += $closing_qty;
                    } elseif ($is_mml) {
                        $mml_yearly_data['closing'][$display_type][$matched_size] += $closing_qty;
                    }
                }
                $closingStmt->close();
                break; // Found data, exit loop
            }
        }
    }
}

// ============================================
// PART 4: Get BREAKAGES for entire financial year from tblbreakages
// ============================================
$breakagesQuery = "SELECT b.Code, b.BRK_Qty 
                   FROM tblbreakages b 
                   WHERE b.CompID = ? AND DATE(b.BRK_Date) BETWEEN ? AND ?";
$breakagesStmt = $conn->prepare($breakagesQuery);
if ($breakagesStmt) {
    $breakagesStmt->bind_param("iss", $compID, $fin_start_date, $fin_end_date);
    $breakagesStmt->execute();
    $breakagesResult = $breakagesStmt->get_result();
    
    while ($row = $breakagesResult->fetch_assoc()) {
        $item_code = $row['Code'];
        
        if (!isset($items[$item_code])) continue;
        
        $item = $items[$item_code];
        $display_type = $item['hierarchy']['display_type'];
        $matched_size = $item['matched_size'];
        $is_mml = $item['is_mml'];
        $is_main = $item['is_main'];
        
        if (!$matched_size) continue;
        
        $breakage_qty = (int)$row['BRK_Qty'];
        
        if ($is_main) {
            $main_yearly_data['breakages'][$display_type][$matched_size] += $breakage_qty;
        } elseif ($is_mml) {
            $mml_yearly_data['breakages'][$display_type][$matched_size] += $breakage_qty;
        }
    }
    $breakagesStmt->close();
}

// Calculate summary in liters for main categories
$summary_liters_main = [];
foreach ($main_display_categories as $category) {
    $summary_liters_main[$category] = [
        'opening' => 0,
        'received' => 0,
        'sold' => 0,
        'closing' => 0,
        'breakages' => 0
    ];
    
    foreach ($size_columns[$category] as $size) {
        $ml = extractMLFromSize($size);
        $liters_factor = $ml / 1000;
        
        $summary_liters_main[$category]['opening'] += ($main_yearly_data['opening'][$category][$size] ?? 0) * $liters_factor;
        $summary_liters_main[$category]['received'] += ($main_yearly_data['received'][$category][$size] ?? 0) * $liters_factor;
        $summary_liters_main[$category]['sold'] += ($main_yearly_data['sold'][$category][$size] ?? 0) * $liters_factor;
        $summary_liters_main[$category]['closing'] += ($main_yearly_data['closing'][$category][$size] ?? 0) * $liters_factor;
        $summary_liters_main[$category]['breakages'] += ($main_yearly_data['breakages'][$category][$size] ?? 0) * $liters_factor;
    }
}

// Calculate summary in liters for MML categories
$summary_liters_mml = [];
foreach ($mml_categories as $category) {
    $summary_liters_mml[$category] = [
        'opening' => 0,
        'received' => 0,
        'sold' => 0,
        'closing' => 0,
        'breakages' => 0
    ];
    
    foreach ($size_columns[$category] as $size) {
        $ml = extractMLFromSize($size);
        $liters_factor = $ml / 1000;
        
        $summary_liters_mml[$category]['opening'] += ($mml_yearly_data['opening'][$category][$size] ?? 0) * $liters_factor;
        $summary_liters_mml[$category]['received'] += ($mml_yearly_data['received'][$category][$size] ?? 0) * $liters_factor;
        $summary_liters_mml[$category]['sold'] += ($mml_yearly_data['sold'][$category][$size] ?? 0) * $liters_factor;
        $summary_liters_mml[$category]['closing'] += ($mml_yearly_data['closing'][$category][$size] ?? 0) * $liters_factor;
        $summary_liters_mml[$category]['breakages'] += ($mml_yearly_data['breakages'][$category][$size] ?? 0) * $liters_factor;
    }
}

// Format liters to 2 decimal places
foreach ($summary_liters_main as $category => $data) {
    foreach ($data as $key => $value) {
        $summary_liters_main[$category][$key] = number_format($value, 2);
    }
}

foreach ($summary_liters_mml as $category => $data) {
    foreach ($data as $key => $value) {
        $summary_liters_mml[$category][$key] = number_format($value, 2);
    }
}

// Get all available months in the financial year for display
$available_months = getAvailableMonthsInYear($conn, $compID, $fin_start_year);
$available_months = array_merge($available_months, getAvailableMonthsInYear($conn, $compID, $fin_end_year));
$available_months = array_unique($available_months);
sort($available_months);

// Determine closing date display text
$closing_display_text = $is_current_date_in_fin_year ? 
    "as on " . date('d-M-Y', strtotime($current_date)) : 
    "as on End of Financial Year (" . date('d-M-Y', strtotime($fin_end_date)) . ")";
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FLR 1A/2A/3A Yearly Register - liqoursoft</title>
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
    .category-header {
      text-align: center;
      font-weight: bold;
      background-color: #e0e0e0;
    }
    .closing-balance {
      font-weight: bold !important;
      color: #000 !important;
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
    .description-col {
      width: 180px;
      min-width: 180px;
      text-align: left !important;
      font-weight: bold;
    }
    .size-col {
      width: 25px;
      min-width: 25px;
      max-width: 25px;
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
    .current-date-note {
      font-size: 11px;
      color: #666;
      font-style: italic;
      margin-top: 5px;
    }
    .month-status {
      font-size: 10px;
      color: #856404;
      background-color: #fff3cd;
      border: 1px solid #ffeeba;
      border-radius: 3px;
      padding: 2px 5px;
      display: inline-block;
      margin-left: 10px;
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
      
      .description-col {
        width: 150px !important;
        min-width: 150px !important;
        max-width: 150px !important;
        text-align: left !important;
        font-size: 7px !important;
      }
      
      .size-col {
        width: 18px !important;
        min-width: 18px !important;
        max-width: 18px !important;
      }
      
      .category-header {
        background-color: #e0e0e0 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
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
      <h3 class="mb-4">FLR 1A/2A/3A Yearly Register</h3>

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

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters" id="reportForm">
            <div class="row mb-3">
              <div class="col-md-4">
                <label class="form-label">Financial Year:</label>
                <select name="fin_year" class="form-control" id="finYearSelect">
                  <?php
                  // Get all financial years for dropdown (you might want to fetch from database)
                  $current_fin_year = $fin_year_display;
                  $prev_fin_year = (date('Y', strtotime($fin_start_date)) - 1) . '-' . date('Y', strtotime($fin_end_date));
                  $next_fin_year = date('Y', strtotime('+1 year', strtotime($fin_start_date))) . '-' . date('Y', strtotime('+1 year', strtotime($fin_end_date)));
                  ?>
                  <option value="<?= $fin_year_id ?>" selected><?= $fin_year_display ?></option>
                </select>
                <small class="text-muted">Currently showing active financial year</small>
              </div>
              <div class="col-md-4">
                <label class="form-label">Period:</label>
                <div class="form-control-plaintext">
                  <strong><?= date('d-M-Y', strtotime($fin_start_date)) ?> to <?= date('d-M-Y', strtotime($fin_end_date)) ?></strong>
                </div>
              </div>
              <div class="col-md-4">
                <label class="form-label">Closing as on:</label>
                <div class="form-control-plaintext">
                  <strong><?= date('d-M-Y') ?></strong>
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
              <a href="dashboard.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-times me-1"></i> Exit
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Main Report Table (Without MML) -->
      <div class="print-section">
        <div class="company-header">
          <h1>Form F.L.R. 1A/2A/3A (See Rule 15)</h1>
          <h5>YEARLY REGISTER OF TRANSACTION OF FOREIGN LIQUOR EFFECTED BY HOLDER OF VENDOR'S/HOTEL/CLUB LICENCE</h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>Financial Year : <?= $fin_year_display ?> (<?= date('d-M-Y', strtotime($fin_start_date)) ?> to <?= date('d-M-Y', strtotime($fin_end_date)) ?>)</h6>
          <h6>Closing Balance <?= $closing_display_text ?></h6>
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table" id="flr-yearly-table">
            <thead>
              <tr>
                <th rowspan="2" class="description-col">Description</th>
                <?php foreach ($main_display_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>" class="category-header"><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
              <tr>
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
              <!-- Opening Balance Row -->
              <tr>
                <td class="description-col">Opening Balance of the Beginning of the Year (<?= date('d-M-Y', strtotime($fin_start_date)) ?>)</td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_yearly_data['opening'][$category][$size]) && $main_yearly_data['opening'][$category][$size] > 0 ? $main_yearly_data['opening'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- Received during Year -->
              <tr>
                <td class="description-col">Received during the Year</td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_yearly_data['received'][$category][$size]) && $main_yearly_data['received'][$category][$size] > 0 ? $main_yearly_data['received'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- Sold during Year -->
              <tr>
                <td class="description-col">Sold during the Year</td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_yearly_data['sold'][$category][$size]) && $main_yearly_data['sold'][$category][$size] > 0 ? $main_yearly_data['sold'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- Breakages during Year -->
              <tr>
                <td class="description-col">Breakages during the Year</td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_yearly_data['breakages'][$category][$size]) && $main_yearly_data['breakages'][$category][$size] > 0 ? $main_yearly_data['breakages'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- Closing Balance Row -->
              <tr>
                <td class="description-col">Closing Balance <?= $closing_display_text ?></td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="closing-balance <?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_yearly_data['closing'][$category][$size]) && $main_yearly_data['closing'][$category][$size] > 0 ? $main_yearly_data['closing'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
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
              <tr>
                <td class="text-start fw-bold">Breakages (Liters)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['breakages'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="text-start fw-bold">Closing Balance (Liters) <?= $closing_display_text ?></td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['closing'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <p>Note: This is a computer generated yearly report. Received, Sold and Breakages are for the full financial year (<?= $fin_year_display ?>). Closing Balance is <?= $closing_display_text ?>.</p>
          <p>Generated on: <?= date('d-M-Y h:i A') ?> | Financial Year: <?= $fin_year_display ?></p>
        </div>
      </div>

      <!-- MML Section - Second Table -->
      <div class="mml-section print-section">
        <div class="mml-header">
          <h4 class="mb-0">MML Yearly Summary Report (Spirit MML & Wine MML Only)</h4>
        </div>
        
        <div class="company-header">
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>Financial Year : <?= $fin_year_display ?></h6>
          <h6>Closing Balance <?= $closing_display_text ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table" id="mml-yearly-table">
            <thead>
              <tr>
                <th rowspan="2" class="description-col">Description (MML)</th>
                <?php foreach ($mml_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>" class="category-header"><?= $mml_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
              <tr>
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
              <!-- MML Opening Balance Row -->
              <tr>
                <td class="description-col">Opening Balance of the Beginning of the Year (<?= date('d-M-Y', strtotime($fin_start_date)) ?>)</td>
                
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_yearly_data['opening'][$category][$size]) && $mml_yearly_data['opening'][$category][$size] > 0 ? $mml_yearly_data['opening'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- MML Received during Year -->
              <tr>
                <td class="description-col">Received during the Year</td>
                
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_yearly_data['received'][$category][$size]) && $mml_yearly_data['received'][$category][$size] > 0 ? $mml_yearly_data['received'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- MML Sold during Year -->
              <tr>
                <td class="description-col">Sold during the Year</td>
                
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_yearly_data['sold'][$category][$size]) && $mml_yearly_data['sold'][$category][$size] > 0 ? $mml_yearly_data['sold'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- MML Breakages during Year -->
              <tr>
                <td class="description-col">Breakages during the Year</td>
                
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_yearly_data['breakages'][$category][$size]) && $mml_yearly_data['breakages'][$category][$size] > 0 ? $mml_yearly_data['breakages'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- MML Closing Balance Row -->
              <tr>
                <td class="description-col">Closing Balance <?= $closing_display_text ?></td>
                
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="closing-balance <?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_yearly_data['closing'][$category][$size]) && $mml_yearly_data['closing'][$category][$size] > 0 ? $mml_yearly_data['closing'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
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
              <tr>
                <td class="text-start fw-bold">Breakages (Liters)</td>
                <?php foreach ($mml_categories as $category): ?>
                  <td><?= number_format($summary_liters_mml[$category]['breakages'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="text-start fw-bold">Closing Balance (Liters) <?= $closing_display_text ?></td>
                <?php foreach ($mml_categories as $category): ?>
                  <td><?= number_format($summary_liters_mml[$category]['closing'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info mt-3">
          <p><strong>MML Yearly Summary:</strong> 
             Spirit MML Total Received: <?= array_sum($mml_yearly_data['received']['MML'] ?? []) ?> | 
             Spirit MML Total Sold: <?= array_sum($mml_yearly_data['sold']['MML'] ?? []) ?> | 
             Spirit MML Breakages: <?= array_sum($mml_yearly_data['breakages']['MML'] ?? []) ?> |
             Spirit MML Closing <?= $closing_display_text ?>: <?= array_sum($mml_yearly_data['closing']['MML'] ?? []) ?><br>
             Wine MML Total Received: <?= array_sum($mml_yearly_data['received']['WINE MML'] ?? []) ?> | 
             Wine MML Total Sold: <?= array_sum($mml_yearly_data['sold']['WINE MML'] ?? []) ?> |
             Wine MML Breakages: <?= array_sum($mml_yearly_data['breakages']['WINE MML'] ?? []) ?> |
             Wine MML Closing <?= $closing_display_text ?>: <?= array_sum($mml_yearly_data['closing']['WINE MML'] ?? []) ?>
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Month data from PHP for potential future enhancements
const monthData = <?= json_encode($months_in_year) ?>;
</script>
</body>
</html>
<?php $conn->close(); ?>