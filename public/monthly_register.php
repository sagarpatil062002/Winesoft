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
    
    return $tableName;
}

// Function to get available months from all tables
function getAvailableMonths($conn, $compID) {
    $available_months = [];
    $tablePrefix = "tbldailystock_" . $compID;
    
    // Check main table first
    $mainTableExists = false;
    $checkMainQuery = "SHOW TABLES LIKE '$tablePrefix'";
    $mainResult = $conn->query($checkMainQuery);
    if ($mainResult && $mainResult->num_rows > 0) {
        $mainTableExists = true;
        // Get months from main table
        $monthQuery = "SELECT DISTINCT STK_MONTH FROM $tablePrefix WHERE STK_MONTH IS NOT NULL AND STK_MONTH != '' ORDER BY STK_MONTH DESC";
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
    
    // Check for archive tables
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
                
                // Get the STK_MONTH from the table to verify
                $monthQuery = "SELECT DISTINCT STK_MONTH FROM $tableName WHERE STK_MONTH IS NOT NULL AND STK_MONTH != '' LIMIT 1";
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
    
    // Remove duplicates and sort descending
    $available_months = array_unique($available_months);
    rsort($available_months);
    
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

// Get all available months
$available_months = getAvailableMonths($conn, $compID);

// Default values - Monthly register
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// If selected month is not in available months, use the most recent
if (!empty($available_months) && !in_array($selected_month, $available_months)) {
    $selected_month = $available_months[0];
}

// Parse selected month
$month_name = date('F Y', strtotime($selected_month . '-01'));
$days_in_month = date('t', strtotime($selected_month . '-01'));
$last_day = $days_in_month;
$first_date = date('Y-m-d', strtotime($selected_month . '-01'));
$last_date = date('Y-m-d', strtotime($selected_month . '-01 + ' . ($days_in_month - 1) . ' days'));

// Extract year and month
list($year, $month_num) = explode('-', $selected_month);

// Check if this is the current month
$is_current_month = ($year == date('Y') && $month_num == date('m'));

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

// Define display categories for main table (NOW INCLUDING MML)
$main_display_categories = [
    'IMFL',
    'IMPORTED', 
    'MML',                    // Added MML here
    'INDIAN WINE',
    'IMPORTED WINE',
    'WINE MML',               // Added Wine MML here
    'FERMENTED BEER',
    'MILD BEER'
];

$category_display_names = [
    'IMFL' => 'IMFL',
    'IMPORTED' => 'IMPORTED',
    'MML' => 'SPIRIT MML',    // Display name for MML
    'INDIAN WINE' => 'INDIAN WINE',
    'IMPORTED WINE' => 'IMPORTED WINE',
    'WINE MML' => 'WINE MML', // Display name for Wine MML
    'FERMENTED BEER' => 'FERMENTED BEER',
    'MILD BEER' => 'MILD BEER'
];

// Define size columns for each category (including MML)
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
}
unset($item);

// Initialize monthly data structure for main categories (including MML)
$main_monthly_data = [
    'opening' => [],
    'received' => [],
    'sold' => [],
    'breakages' => [],
    'closing_calculated' => [] // New array for calculated closing
];

foreach ($main_display_categories as $category) {
    $main_monthly_data['opening'][$category] = array_fill_keys($size_columns[$category], 0);
    $main_monthly_data['received'][$category] = array_fill_keys($size_columns[$category], 0);
    $main_monthly_data['sold'][$category] = array_fill_keys($size_columns[$category], 0);
    $main_monthly_data['breakages'][$category] = array_fill_keys($size_columns[$category], 0);
    $main_monthly_data['closing_calculated'][$category] = array_fill_keys($size_columns[$category], 0);
}

// Get table name for selected month
$tableName = getTableForMonth($conn, $compID, $year, $month_num);

// Check if table exists
$tableExists = false;
$checkTableQuery = "SHOW TABLES LIKE '$tableName'";
$tableCheckResult = $conn->query($checkTableQuery);
if ($tableCheckResult && $tableCheckResult->num_rows > 0) {
    $tableExists = true;
}

// ============================================
// PART 1: Get OPENING BALANCE from first day of the month (DAY_01_OPEN)
// ============================================
if ($tableExists) {
    $checkColumnQuery = "SHOW COLUMNS FROM $tableName LIKE 'DAY_01_OPEN'";
    $checkResult = $conn->query($checkColumnQuery);
    if ($checkResult && $checkResult->num_rows > 0) {
        $openingQuery = "SELECT ITEM_CODE, DAY_01_OPEN as opening FROM $tableName WHERE STK_MONTH = ?";
        $openingStmt = $conn->prepare($openingQuery);
        if ($openingStmt) {
            $openingStmt->bind_param("s", $selected_month);
            $openingStmt->execute();
            $openingResult = $openingStmt->get_result();
            
            while ($row = $openingResult->fetch_assoc()) {
                $item_code = $row['ITEM_CODE'];
                
                if (!isset($items[$item_code])) continue;
                
                $item = $items[$item_code];
                $display_type = $item['hierarchy']['display_type'];
                $matched_size = $item['matched_size'];
                
                if (!$matched_size) continue;
                
                $opening_qty = (int)$row['opening'];
                
                if (in_array($display_type, $main_display_categories)) {
                    $main_monthly_data['opening'][$display_type][$matched_size] += $opening_qty;
                }
            }
            $openingStmt->close();
        }
    }
}

// ============================================
// PART 2: Get TOTAL RECEIVED and SOLD for the month (sum of all DAY_xx_PURCHASE and DAY_xx_SALES)
// ============================================
if ($tableExists) {
    // Build dynamic column list for this table
    $purchase_cols = [];
    $sales_cols = [];

    // Check which day columns exist (up to days_in_month)
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

    if (!empty($purchase_cols) || !empty($sales_cols)) {
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
            $stockStmt->bind_param("s", $selected_month);
            $stockStmt->execute();
            $stockResult = $stockStmt->get_result();
            
            while ($row = $stockResult->fetch_assoc()) {
                $item_code = $row['ITEM_CODE'];
                
                if (!isset($items[$item_code])) continue;
                
                $item = $items[$item_code];
                $display_type = $item['hierarchy']['display_type'];
                $matched_size = $item['matched_size'];
                
                if (!$matched_size) continue;
                
                $purchase_qty = (int)$row['total_purchase'];
                $sales_qty = (int)$row['total_sales'];
                
                if (in_array($display_type, $main_display_categories)) {
                    $main_monthly_data['received'][$display_type][$matched_size] += $purchase_qty;
                    $main_monthly_data['sold'][$display_type][$matched_size] += $sales_qty;
                }
            }
            $stockStmt->close();
        }
    }
}

// ============================================
// PART 3: Get BREAKAGES for the month from tblbreakages
// ============================================
$month_start = $selected_month . '-01';
$month_end = $selected_month . '-' . sprintf('%02d', $last_day);

$breakagesQuery = "SELECT b.Code, b.BRK_Qty 
                   FROM tblbreakages b 
                   WHERE b.CompID = ? AND DATE(b.BRK_Date) BETWEEN ? AND ?";
$breakagesStmt = $conn->prepare($breakagesQuery);
if ($breakagesStmt) {
    $breakagesStmt->bind_param("iss", $compID, $month_start, $month_end);
    $breakagesStmt->execute();
    $breakagesResult = $breakagesStmt->get_result();
    
    while ($row = $breakagesResult->fetch_assoc()) {
        $item_code = $row['Code'];
        
        if (!isset($items[$item_code])) continue;
        
        $item = $items[$item_code];
        $display_type = $item['hierarchy']['display_type'];
        $matched_size = $item['matched_size'];
        
        if (!$matched_size) continue;
        
        $breakage_qty = (int)$row['BRK_Qty'];
        
        if (in_array($display_type, $main_display_categories)) {
            $main_monthly_data['breakages'][$display_type][$matched_size] += $breakage_qty;
        }
    }
    $breakagesStmt->close();
}

// ============================================
// PART 4: Calculate CLOSING BALANCE (Opening + Received - Sold - Breakages)
// ============================================
foreach ($main_display_categories as $category) {
    foreach ($size_columns[$category] as $size) {
        $opening = $main_monthly_data['opening'][$category][$size] ?? 0;
        $received = $main_monthly_data['received'][$category][$size] ?? 0;
        $sold = $main_monthly_data['sold'][$category][$size] ?? 0;
        $breakages = $main_monthly_data['breakages'][$category][$size] ?? 0;
        
        // Calculate closing: opening + received - sold - breakages
        $calculated_closing = $opening + $received - $sold - $breakages;
        
        // Ensure non-negative
        if ($calculated_closing < 0) {
            $calculated_closing = 0;
        }
        
        $main_monthly_data['closing_calculated'][$category][$size] = $calculated_closing;
    }
}

// Calculate summary in liters for main categories (including MML)
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
        
        $opening = $main_monthly_data['opening'][$category][$size] ?? 0;
        $received = $main_monthly_data['received'][$category][$size] ?? 0;
        $sold = $main_monthly_data['sold'][$category][$size] ?? 0;
        $breakages = $main_monthly_data['breakages'][$category][$size] ?? 0;
        $closing = $main_monthly_data['closing_calculated'][$category][$size] ?? 0;
        
        $summary_liters_main[$category]['opening'] += $opening * $liters_factor;
        $summary_liters_main[$category]['received'] += $received * $liters_factor;
        $summary_liters_main[$category]['sold'] += $sold * $liters_factor;
        $summary_liters_main[$category]['closing'] += $closing * $liters_factor;
        $summary_liters_main[$category]['breakages'] += $breakages * $liters_factor;
    }
}

// Keep raw values for summary (format only when displaying)
// The formatting will be done at display time to avoid double-formatting issues

// Group available months by year for dropdown display
$years_with_months = [];
foreach ($available_months as $avail_month) {
    $year = date('Y', strtotime($avail_month . '-01'));
    $month_name = date('F', strtotime($avail_month . '-01'));
    
    if (!isset($years_with_months[$year])) {
        $years_with_months[$year] = [];
    }
    $years_with_months[$year][] = [
        'value' => $avail_month,
        'name' => $month_name
    ];
}

// Get all available years
$available_years = array_keys($years_with_months);
rsort($available_years); // Show newest years first

// Determine current year for dropdown
$current_year_for_dropdown = $year;
if (!in_array($current_year_for_dropdown, $available_years) && !empty($available_years)) {
    $current_year_for_dropdown = $available_years[0];
}

// Determine closing date display text
$closing_display_text = $is_current_month ? 
    "as on Latest Available Date" : 
    "as on " . date('d-M-Y', strtotime($last_date));
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FLR 1A/2A/3A Monthly Register - liqoursoft</title>
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
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
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
      width: 200px;
      min-width: 200px;
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
    .mml-highlight {
      background-color: #e0e0e0 !important; /* Grey highlight */
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
    .calculated-closing {
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
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">FLR 1A/2A/3A Monthly Register</h3>

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
                <label class="form-label">Year:</label>
                <select name="year" class="form-control" id="yearSelect">
                  <?php
                  if (empty($available_years)) {
                      echo '<option value="">No data available</option>';
                  } else {
                      foreach ($available_years as $avail_year) {
                          $selected = ($current_year_for_dropdown == $avail_year) ? 'selected' : '';
                          echo "<option value=\"$avail_year\" $selected>$avail_year</option>";
                      }
                  }
                  ?>
                </select>
              </div>
              
              <div class="col-md-4">
                <label class="form-label">Month:</label>
                <select name="month" class="form-control" id="monthSelect">
                  <?php
                  if (isset($years_with_months[$current_year_for_dropdown])) {
                      foreach ($years_with_months[$current_year_for_dropdown] as $month_info) {
                          $selected = ($selected_month == $month_info['value']) ? 'selected' : '';
                          echo "<option value=\"{$month_info['value']}\" $selected>{$month_info['name']} {$current_year_for_dropdown}</option>";
                      }
                  } elseif (!empty($available_months)) {
                      // Fallback: show all available months
                      foreach ($available_months as $avail_month) {
                          $selected = ($selected_month == $avail_month) ? 'selected' : '';
                          $month_name = date('F Y', strtotime($avail_month . '-01'));
                          echo "<option value=\"$avail_month\" $selected>$month_name</option>";
                      }
                  } else {
                      echo '<option value="">No months available</option>';
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

      <!-- Main Report Table (WITH MML Included) -->
      <div class="print-section">
        <div class="company-header">
          <h1>Form F.L.R. 1A/2A/3A (See Rule 15)</h1>
          <h5>MONTHLY REGISTER OF TRANSACTION OF FOREIGN LIQUOR EFFECTED BY HOLDER OF VENDOR'S/HOTEL/CLUB LICENCE</h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>Month : <?= $month_name ?> 
            <?php if ($is_current_month): ?>
              <span class="month-status">(Current Month - Partial Data)</span>
            <?php endif; ?>
          </h6>
          <h6>Closing Balance <?= $closing_display_text ?> (Calculated as Opening + Received - Sold - Breakages)</h6>
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
          <h6><span class="badge bg-secondary">MML Categories Included: Spirit MML & Wine MML (Grey Highlighted)</span></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table" id="flr-monthly-table">
            <thead>
              <tr>
                <th rowspan="2" class="description-col">Description</th>
                <?php foreach ($main_display_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>" class="category-header <?= in_array($category, ['MML', 'WINE MML']) ? 'mml-highlight' : '' ?>"><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
              <tr>
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?> <?= in_array($category, ['MML', 'WINE MML']) ? 'mml-highlight' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <!-- Opening Balance Row -->
              <tr>
                <td class="description-col">Opening Balance of the Beginning of the Month (<?= date('d-M-Y', strtotime($first_date)) ?>) : -</td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_monthly_data['opening'][$category][$size]) && $main_monthly_data['opening'][$category][$size] > 0 ? $main_monthly_data['opening'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- Received during Month -->
              <tr>
                <td class="description-col">Received during the Current Month : -</td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_monthly_data['received'][$category][$size]) && $main_monthly_data['received'][$category][$size] > 0 ? $main_monthly_data['received'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- Sold during Month -->
              <tr>
                <td class="description-col">Sold during the Current Month : -</td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_monthly_data['sold'][$category][$size]) && $main_monthly_data['sold'][$category][$size] > 0 ? $main_monthly_data['sold'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- Breakages during Month -->
              <tr>
                <td class="description-col">Breakages during the Current Month : -</td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_monthly_data['breakages'][$category][$size]) && $main_monthly_data['breakages'][$category][$size] > 0 ? $main_monthly_data['breakages'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

              <!-- Closing Balance Row (Calculated) -->
              <tr class="summary-row">
                <td class="description-col">Closing Balance at the End of the Month <?= $closing_display_text ?> : -</td>
                
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="calculated-closing <?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_monthly_data['closing_calculated'][$category][$size]) && $main_monthly_data['closing_calculated'][$category][$size] > 0 ? $main_monthly_data['closing_calculated'][$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>

            </tbody>
          </table>
        </div>

        <!-- Summary in Liters Table (WITH MML Included) -->
        <div class="summary-table">
          <h5 class="text-center mb-3">MONTHLY SUMMARY (IN LITERS) - ALL CATEGORIES INCLUDING MML</h5>
          <table class="report-table">
            <thead>
              <tr>
                <th>Description</th>
                <?php foreach ($main_display_categories as $category): ?>
                  <th <?= in_array($category, ['MML', 'WINE MML']) ? 'class="mml-highlight"' : '' ?>><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="text-start fw-bold">Op. Stk. (Ltrs.)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['opening'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="text-start fw-bold">Receipts (Ltrs.)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['received'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="text-start fw-bold">Sold (Ltrs.)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['sold'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr>
                <td class="text-start fw-bold">Breakages (Ltrs.)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['breakages'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
              <tr class="summary-row">
                <td class="text-start fw-bold">Cl. Stk. (Ltrs.)</td>
                <?php foreach ($main_display_categories as $category): ?>
                  <td><?= number_format($summary_liters_main[$category]['closing'] ?? 0, 2) ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <p>Note: This is a computer generated monthly report. Closing balance is calculated as Opening + Received - Sold - Breakages.</p>
          <p><?= $is_current_month ? 'Current month data is partial and subject to change. ' : '' ?></p>
          <p><strong>MML Summary:</strong> Spirit MML Total: <?= array_sum($main_monthly_data['received']['MML'] ?? []) + array_sum($main_monthly_data['sold']['MML'] ?? []) + array_sum($main_monthly_data['breakages']['MML'] ?? []) + array_sum($main_monthly_data['closing_calculated']['MML'] ?? []) ?> bottles | Wine MML Total: <?= array_sum($main_monthly_data['received']['WINE MML'] ?? []) + array_sum($main_monthly_data['sold']['WINE MML'] ?? []) + array_sum($main_monthly_data['breakages']['WINE MML'] ?? []) + array_sum($main_monthly_data['closing_calculated']['WINE MML'] ?? []) ?> bottles</p>
          <p>Generated on: <?= date('d-M-Y h:i A') ?> | Month: <?= $month_name ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Month data from PHP
const monthData = <?= json_encode($years_with_months) ?>;
let formSubmitted = false;
let currentMonth = '<?= $selected_month ?>';

function updateMonthOptions() {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const selectedYear = yearSelect.value;
    
    // Clear current options
    monthSelect.innerHTML = '';
    
    if (monthData[selectedYear] && monthData[selectedYear].length > 0) {
        // Add options for selected year
        monthData[selectedYear].forEach(month => {
            const option = document.createElement('option');
            option.value = month.value;
            option.textContent = month.name + ' ' + selectedYear;
            monthSelect.appendChild(option);
        });
        
        // Try to select current month if it exists in this year
        const currentMonthInYear = Array.from(monthSelect.options).find(opt => opt.value === currentMonth);
        if (currentMonthInYear) {
            monthSelect.value = currentMonth;
        } else if (monthSelect.options.length > 0) {
            // Otherwise select first month
            monthSelect.selectedIndex = 0;
        }
    } else {
        // No months for this year
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No months available';
        monthSelect.appendChild(option);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const reportForm = document.getElementById('reportForm');
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    
    // Initial setup - update month options
    updateMonthOptions();
    
    // Handle form submission
    reportForm.addEventListener('submit', function(e) {
        // Ensure month has a value before submitting
        if (!monthSelect.value) {
            e.preventDefault();
            if (monthSelect.options.length > 0) {
                monthSelect.selectedIndex = 0;
                setTimeout(() => reportForm.submit(), 50);
            }
            return;
        }
        formSubmitted = true;
    });
    
    // Handle year change - update month options
    yearSelect.addEventListener('change', function() {
        updateMonthOptions();
        
        // Update URL without page reload
        const url = new URL(window.location.href);
        url.searchParams.set('year', this.value);
        if (monthSelect.value) {
            url.searchParams.set('month', monthSelect.value);
        }
        window.history.replaceState({}, '', url);
    });
    
    // Handle month change - update URL only, don't submit
    monthSelect.addEventListener('change', function() {
        if (this.value) {
            const url = new URL(window.location.href);
            url.searchParams.set('month', this.value);
            url.searchParams.set('year', yearSelect.value);
            window.history.replaceState({}, '', url);
        }
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>