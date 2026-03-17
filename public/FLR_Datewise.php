<?php
session_start();
require_once 'components/financial_year_init.php';// Ensure user is logged in and company is selected

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
include_once "components/financial_year.php";
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

// Define categories and size columns for FLR Datewise
$display_categories = ['Spirit', 'Wine', 'Fermented Beer', 'Mild Beer'];
$size_columns = [
    'Spirit' => $display_sizes_spirit,
    'Wine' => $display_sizes_wine,
    'Fermented Beer' => $display_sizes_beer,
    'Mild Beer' => $display_sizes_beer
];

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

// Cache for table structures
$table_columns_cache = [];
$table_exists_cache = [];

// Function to get table name for a specific date
function getTableForDate($conn, $compID, $date, &$table_exists_cache) {
    global $table_columns_cache;
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
    
    // Check if table exists (use cache)
    if (!isset($table_exists_cache[$tableName])) {
        $tableCheckQuery = "SHOW TABLES LIKE '$tableName'";
        $tableCheckResult = $conn->query($tableCheckQuery);
        $table_exists_cache[$tableName] = ($tableCheckResult && $tableCheckResult->num_rows > 0);
    }
    
    if (!$table_exists_cache[$tableName]) {
        // If archive table doesn't exist, fall back to main table
        $tableName = "tbldailystock_" . $compID;
        
        // Check if main table exists (use cache)
        if (!isset($table_exists_cache[$tableName])) {
            $tableCheckQuery2 = "SHOW TABLES LIKE '$tableName'";
            $tableCheckResult2 = $conn->query($tableCheckQuery2);
            $table_exists_cache[$tableName] = ($tableCheckResult2 && $tableCheckResult2->num_rows > 0);
        }
        
        if (!$table_exists_cache[$tableName]) {
            $tableName = "tbldailystock_1";
        }
    }
    
    return $tableName;
}

// Function to check if table has specific day columns (cached)
function tableHasDayColumns($conn, $tableName, $day, &$table_columns_cache) {
    // Use cache
    if (isset($table_columns_cache[$tableName])) {
        $day_padded = sprintf('%02d', $day);
        $columnName = "DAY_{$day_padded}_OPEN";
        return isset($table_columns_cache[$tableName][$columnName]);
    }
    
    // Cache all day columns for this table (check first 31 days)
    $table_columns_cache[$tableName] = [];
    
    for ($d = 1; $d <= 31; $d++) {
        $day_padded = sprintf('%02d', $d);
        $columns_to_check = [
            "DAY_{$day_padded}_OPEN",
            "DAY_{$day_padded}_PURCHASE", 
            "DAY_{$day_padded}_SALES",
            "DAY_{$day_padded}_CLOSING"
        ];
        
        foreach ($columns_to_check as $column) {
            $checkColumnQuery = "SHOW COLUMNS FROM $tableName LIKE '$column'";
            $columnResult = $conn->query($checkColumnQuery);
            if ($columnResult && $columnResult->num_rows > 0) {
                $table_columns_cache[$tableName][$column] = true;
            }
        }
    }
    
    // Now check for the requested day
    $day_padded = sprintf('%02d', $day);
    $columnName = "DAY_{$day_padded}_OPEN";
    return isset($table_columns_cache[$tableName][$columnName]);
}

// Function to check if table has closing column for specific day
function tableHasClosingColumn($conn, $tableName, $day, &$table_columns_cache) {
    // Ensure columns are cached
    if (!isset($table_columns_cache[$tableName])) {
        // Cache all day columns for this table
        for ($d = 1; $d <= 31; $d++) {
            $day_padded = sprintf('%02d', $d);
            $columns_to_check = [
                "DAY_{$day_padded}_OPEN",
                "DAY_{$day_padded}_PURCHASE", 
                "DAY_{$day_padded}_SALES",
                "DAY_{$day_padded}_CLOSING"
            ];
            
            foreach ($columns_to_check as $column) {
                $checkColumnQuery = "SHOW COLUMNS FROM $tableName LIKE '$column'";
                $columnResult = $conn->query($checkColumnQuery);
                if ($columnResult && $columnResult->num_rows > 0) {
                    $table_columns_cache[$tableName][$column] = true;
                }
            }
        }
    }
    
    // Check for the requested day CLOSING column
    $day_padded = sprintf('%02d', $day);
    $columnName = "DAY_{$day_padded}_CLOSING";
    return isset($table_columns_cache[$tableName][$columnName]);
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
// STEP 2: Get ALL dates from start of month to To Date for CALCULATIONS
// ============================================================================
$month_start = date('Y-m-01', strtotime($from_date));
$calculation_dates = [];

$current_date = $month_start;
while (strtotime($current_date) <= strtotime($to_date)) {
    $calculation_dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// ============================================================================
// STEP 3: Initialize data structures for ALL calculation dates
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
// STEP 4: Fetch raw data for ALL calculation dates
// ============================================================================
foreach ($calculation_dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    $day_padded = sprintf('%02d', $day);
    
    // Get appropriate table for this date
    $dailyStockTable = getTableForDate($conn, $compID, $date, $table_exists_cache);
    
    // Check if table has columns for this day (using cache)
    if (!tableHasDayColumns($conn, $dailyStockTable, $day, $table_columns_cache)) {
        continue;
    }
    
    // Fetch stock data for this specific day
    $stockQuery = "SELECT ITEM_CODE,
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
            
            // Skip if not in display categories
            if (!in_array($liquor_type, $display_categories)) continue;
            
            // Get volume
            $volume = $hierarchy['ml_volume'];
            
            // Skip if volume is 0
            if ($volume <= 0) continue;
            
            // Determine which display sizes to use based on liquor type
            $target_display_sizes = $size_columns[$liquor_type];
            
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
            
            // Add data to all_daily_data
            if (isset($all_daily_data[$date][$liquor_type])) {
                $all_daily_data[$date][$liquor_type]['opening'][$matched_size] += (int)$row['opening'];
                $all_daily_data[$date][$liquor_type]['purchase'][$matched_size] += (int)$row['purchase'];
                $all_daily_data[$date][$liquor_type]['sales'][$matched_size] += (int)$row['sales'];
                // Don't set closing from DB - we'll calculate it
            }
        }
        
        $stockStmt->close();
    }
}

// ============================================================================
// STEP 5: Get previous month's closing if needed (for opening balance on first day of month)
// ============================================================================
$prev_month_closing = [];

// Check if selected from_date is the first day of month
$is_first_day_of_month = ($from_date == $month_start);

if ($is_first_day_of_month) {
    // We need previous month's last day closing
    $prev_month_date = date('Y-m-01', strtotime($from_date . ' -1 month'));
    $prev_month_last_day = date('t', strtotime($prev_month_date)); // Last day of previous month
    $prev_month = date('Y-m', strtotime($prev_month_date));
    $prev_day_padded = sprintf('%02d', $prev_month_last_day);
    
    // Try to get from previous month's archive table
    $prevStockTable = getTableForDate($conn, $compID, $prev_month_date, $table_exists_cache);
    
    // Check for CLOSING column
    if (tableHasClosingColumn($conn, $prevStockTable, $prev_month_last_day, $table_columns_cache)) {
        $prevStockQuery = "SELECT ITEM_CODE, DAY_{$prev_day_padded}_CLOSING as closing 
                          FROM $prevStockTable 
                          WHERE STK_MONTH = ?
                          LIMIT 500";
        
        $prevStockStmt = $conn->prepare($prevStockQuery);
        $prevStockStmt->bind_param("s", $prev_month);
        $prevStockStmt->execute();
        $prevStockResult = $prevStockStmt->get_result();
        
        while ($row = $prevStockResult->fetch_assoc()) {
            $item_code = $row['ITEM_CODE'];
            if (!isset($items[$item_code])) continue;
            
            $item = $items[$item_code];
            $hierarchy = $item['hierarchy'];
            $display_type = $hierarchy['display_type'];
            
            if (!in_array($display_type, $display_categories)) continue;
            
            $volume = $hierarchy['ml_volume'];
            if ($volume <= 0) continue;
            
            $target_display_sizes = $size_columns[$display_type];
            
            // Find matching size
            $matched_size = null;
            foreach ($target_display_sizes as $display_size) {
                preg_match('/(\d+\.?\d*)/', $display_size, $display_matches);
                if (isset($display_matches[1])) {
                    $display_volume = floatval($display_matches[1]);
                    if (strpos($display_size, 'L') !== false && strpos($display_size, 'ML') === false) {
                        $display_volume *= 1000;
                    }
                    if (abs($volume - $display_volume) < 1) {
                        $matched_size = $display_size;
                        break;
                    }
                }
            }
            
            if ($matched_size) {
                if (!isset($prev_month_closing[$display_type])) {
                    $prev_month_closing[$display_type] = array_fill_keys($target_display_sizes, 0);
                }
                $prev_month_closing[$display_type][$matched_size] += (int)$row['closing'];
            }
        }
        $prevStockStmt->close();
    }
    
    // Fallback: Try current month table
    if (empty($prev_month_closing)) {
        $currentMonthTable = "tbldailystock_" . $compID;
        if (tableHasClosingColumn($conn, $currentMonthTable, $prev_month_last_day, $table_columns_cache)) {
            $prevStockQuery = "SELECT ITEM_CODE, DAY_{$prev_day_padded}_CLOSING as closing 
                              FROM $currentMonthTable 
                              WHERE STK_MONTH = ?
                              LIMIT 500";
            
            $prevStockStmt = $conn->prepare($prevStockQuery);
            $prevStockStmt->bind_param("s", $prev_month);
            $prevStockStmt->execute();
            $prevStockResult = $prevStockStmt->get_result();
            
            while ($row = $prevStockResult->fetch_assoc()) {
                $item_code = $row['ITEM_CODE'];
                if (!isset($items[$item_code])) continue;
                
                $item = $items[$item_code];
                $hierarchy = $item['hierarchy'];
                $display_type = $hierarchy['display_type'];
                
                if (!in_array($display_type, $display_categories)) continue;
                
                $volume = $hierarchy['ml_volume'];
                if ($volume <= 0) continue;
                
                $target_display_sizes = $size_columns[$display_type];
                
                $matched_size = null;
                foreach ($target_display_sizes as $display_size) {
                    preg_match('/(\d+\.?\d*)/', $display_size, $display_matches);
                    if (isset($display_matches[1])) {
                        $display_volume = floatval($display_matches[1]);
                        if (strpos($display_size, 'L') !== false && strpos($display_size, 'ML') === false) {
                            $display_volume *= 1000;
                        }
                        if (abs($volume - $display_volume) < 1) {
                            $matched_size = $display_size;
                            break;
                        }
                    }
                }
                
                if ($matched_size) {
                    if (!isset($prev_month_closing[$display_type])) {
                        $prev_month_closing[$display_type] = array_fill_keys($target_display_sizes, 0);
                    }
                    $prev_month_closing[$display_type][$matched_size] += (int)$row['closing'];
                }
            }
            $prevStockStmt->close();
        }
    }
}

// ============================================================================
// STEP 6: Calculate running balances from month start through To Date
// ============================================================================
$running_closing = [];

foreach ($calculation_dates as $index => $date) {
    // For each category and size
    foreach ($display_categories as $category) {
        if (!isset($all_daily_data[$date][$category])) continue;
        
        foreach ($size_columns[$category] as $size) {
            // Get opening balance
            if ($index == 0) {
                // First day (month start)
                if ($is_first_day_of_month && !empty($prev_month_closing)) {
                    // Use previous month's closing if we're on first day and have previous month data
                    $opening = $prev_month_closing[$category][$size] ?? $all_daily_data[$date][$category]['opening'][$size] ?? 0;
                } else {
                    // Use database opening
                    $opening = $all_daily_data[$date][$category]['opening'][$size] ?? 0;
                }
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
// STEP 7: Filter to only include display dates
// ============================================================================
$daily_data = [];
foreach ($display_dates as $date) {
    if (isset($all_daily_data[$date])) {
        $daily_data[$date] = $all_daily_data[$date];
    }
}

// Calculate total columns count for table formatting
$total_columns_per_section = count($display_sizes_spirit) + count($display_sizes_wine) + (count($display_sizes_beer) * 2);

// Calculate totals for summary rows
$totals = [
    'Spirit' => [
        'purchase' => array_fill_keys($display_sizes_spirit, 0),
        'sales' => array_fill_keys($display_sizes_spirit, 0)
    ],
    'Wine' => [
        'purchase' => array_fill_keys($display_sizes_wine, 0),
        'sales' => array_fill_keys($display_sizes_wine, 0)
    ],
    'Fermented Beer' => [
        'purchase' => array_fill_keys($display_sizes_beer, 0),
        'sales' => array_fill_keys($display_sizes_beer, 0)
    ],
    'Mild Beer' => [
        'purchase' => array_fill_keys($display_sizes_beer, 0),
        'sales' => array_fill_keys($display_sizes_beer, 0)
    ]
];

// Initialize opening_balance_data
$opening_balance_data = [
    'Spirit' => array_fill_keys($display_sizes_spirit, 0),
    'Wine' => array_fill_keys($display_sizes_wine, 0),
    'Fermented Beer' => array_fill_keys($display_sizes_beer, 0),
    'Mild Beer' => array_fill_keys($display_sizes_beer, 0)
];

// Set opening balance from first day
if (!empty($display_dates)) {
    $first_date = $display_dates[0];
    if (isset($daily_data[$first_date])) {
        foreach ($display_categories as $category) {
            if (isset($daily_data[$first_date][$category]['opening'])) {
                foreach ($size_columns[$category] as $size) {
                    $opening_balance_data[$category][$size] = $daily_data[$first_date][$category]['opening'][$size] ?? 0;
                }
            }
        }
    }
}

// Calculate totals from all display dates
foreach ($display_dates as $date) {
    if (!isset($daily_data[$date])) continue;
    
    foreach ($display_categories as $category) {
        if (!isset($daily_data[$date][$category])) continue;
        
        foreach ($size_columns[$category] as $size) {
            $totals[$category]['purchase'][$size] += $daily_data[$date][$category]['purchase'][$size] ?? 0;
            $totals[$category]['sales'][$size] += $daily_data[$date][$category]['sales'][$size] ?? 0;
        }
    }
}

// Calculate closing balance (Opening + Total Received - Total Sold)
$closing_balance = [
    'Spirit' => array_fill_keys($display_sizes_spirit, 0),
    'Wine' => array_fill_keys($display_sizes_wine, 0),
    'Fermented Beer' => array_fill_keys($display_sizes_beer, 0),
    'Mild Beer' => array_fill_keys($display_sizes_beer, 0)
];

foreach ($display_categories as $category) {
    foreach ($size_columns[$category] as $size) {
        $opening = $opening_balance_data[$category][$size] ?? 0;
        $received = $totals[$category]['purchase'][$size] ?? 0;
        $sold = $totals[$category]['sales'][$size] ?? 0;
        $closing_balance[$category][$size] = $opening + $received - $sold;
    }
}
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
    .date-col {
      width: 30px;
      min-width: 30px;
    }
    .permit-col {
      width: 50px;
      min-width: 50px;
    }
    .type-col {
      width: 40px;
      min-width: 40px;
      font-weight: bold;
    }
    .size-col {
      width: 25px;
      min-width: 25px;
      max-width: 25px;
    }
    .date-display {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100%;
      line-height: 1;
    }
    .date-display span {
      display: block;
      line-height: 1;
      margin: 0;
      padding: 0;
    }
    .row-label {
      font-weight: bold;
      background-color: #f5f5f5;
    }
    .double-line-right {
      border-right: 3px double #000 !important;
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
      
      .date-col, .permit-col, .type-col {
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
                  <small class="text-muted">Selected: <?= count($display_dates) ?> day(s)</small>
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
                <th rowspan="3" class="type-col">Type</th>
                <th colspan="<?= $total_columns_per_section ?>">Received</th>
                <th colspan="<?= $total_columns_per_section ?>">Sold</th>
                <th colspan="<?= $total_columns_per_section ?>">Closing Balance</th>
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
                <?php foreach ($display_sizes_beer as $index => $size): ?>
                  <th class="size-col vertical-text <?= ($index == count($display_sizes_beer) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Received - Mild Beer -->
                <?php foreach ($display_sizes_beer as $index => $size): ?>
                  <th class="size-col vertical-text <?= ($index == count($display_sizes_beer) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
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
                <?php foreach ($display_sizes_beer as $index => $size): ?>
                  <th class="size-col vertical-text <?= ($index == count($display_sizes_beer) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Sold - Mild Beer -->
                <?php foreach ($display_sizes_beer as $index => $size): ?>
                  <th class="size-col vertical-text <?= ($index == count($display_sizes_beer) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
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
                <?php foreach ($display_sizes_beer as $index => $size): ?>
                  <th class="size-col vertical-text <?= ($index == count($display_sizes_beer) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Closing - Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php 
              $first_displayed = false;
              $date_count = 0;
              
              foreach ($display_dates as $date): 
                if (!isset($daily_data[$date])) continue;
                
                // Check if there's any data to show
                $has_data = false;
                foreach ($display_categories as $category) {
                    if (isset($daily_data[$date][$category])) {
                        foreach ($size_columns[$category] as $size) {
                            if (($daily_data[$date][$category]['purchase'][$size] ?? 0) > 0 || 
                                ($daily_data[$date][$category]['sales'][$size] ?? 0) > 0 || 
                                ($daily_data[$date][$category]['closing'][$size] ?? 0) > 0) {
                                $has_data = true;
                                break 2;
                            }
                        }
                    }
                }
                
                // For first date, also check opening
                if (!$first_displayed && !$has_data) {
                    foreach ($display_categories as $category) {
                        if (isset($daily_data[$date][$category])) {
                            foreach ($size_columns[$category] as $size) {
                                if (($daily_data[$date][$category]['opening'][$size] ?? 0) > 0) {
                                    $has_data = true;
                                    break 2;
                                }
                            }
                        }
                    }
                }
                
                if (!$has_data) continue;
                
                $day_num = date('d', strtotime($date));
                $month_num = date('m', strtotime($date));
                $year_num = date('y', strtotime($date));
                $date_count++;
                
                $is_first_displayed = !$first_displayed;
                
                if ($is_first_displayed): 
                    $first_displayed = true;
              ?>
                <!-- First displayed date - Show all 4 rows (Opening, Received, Sold, Closing) -->
                <tr>
                  <td rowspan="4" class="date-col">
                    <div class="date-display">
                      <span><?= $day_num ?></span>
                      <span><?= $month_num ?></span>
                      <span><?= $year_num ?></span>
                    </div>
                  </td>
                  <td rowspan="4" class="permit-col"></td>
                  <td class="type-col row-label">Op.</td>
                  
                  <!-- Received Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Sold Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Closing Section - Show Opening Balance -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['opening'][$size]) && $daily_data[$date]['Spirit']['opening'][$size] > 0 ? $daily_data[$date]['Spirit']['opening'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['opening'][$size]) && $daily_data[$date]['Wine']['opening'][$size] > 0 ? $daily_data[$date]['Wine']['opening'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['opening'][$size]) && $daily_data[$date]['Fermented Beer']['opening'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['opening'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['opening'][$size]) && $daily_data[$date]['Mild Beer']['opening'][$size] > 0 ? $daily_data[$date]['Mild Beer']['opening'][$size] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
                
                <tr>
                  <td class="type-col row-label">Rec.</td>
                  
                  <!-- Received Section - Show purchase data -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['purchase'][$size]) && $daily_data[$date]['Spirit']['purchase'][$size] > 0 ? $daily_data[$date]['Spirit']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['purchase'][$size]) && $daily_data[$date]['Wine']['purchase'][$size] > 0 ? $daily_data[$date]['Wine']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['purchase'][$size]) && $daily_data[$date]['Fermented Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['purchase'][$size]) && $daily_data[$date]['Mild Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Mild Beer']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Closing Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                </tr>
                
                <tr>
                  <td class="type-col row-label">Sold</td>
                  
                  <!-- Received Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Sold Section - Show sales data -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['sales'][$size]) && $daily_data[$date]['Spirit']['sales'][$size] > 0 ? $daily_data[$date]['Spirit']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['sales'][$size]) && $daily_data[$date]['Wine']['sales'][$size] > 0 ? $daily_data[$date]['Wine']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['sales'][$size]) && $daily_data[$date]['Fermented Beer']['sales'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['sales'][$size]) && $daily_data[$date]['Mild Beer']['sales'][$size] > 0 ? $daily_data[$date]['Mild Beer']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                </tr>
                
                <tr>
                  <td class="type-col row-label">Clo.</td>
                  
                  <!-- Received Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Sold Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Closing Section - Show calculated closing -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['closing'][$size]) && $daily_data[$date]['Spirit']['closing'][$size] > 0 ? $daily_data[$date]['Spirit']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['closing'][$size]) && $daily_data[$date]['Wine']['closing'][$size] > 0 ? $daily_data[$date]['Wine']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['closing'][$size]) && $daily_data[$date]['Fermented Beer']['closing'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['closing'][$size]) && $daily_data[$date]['Mild Beer']['closing'][$size] > 0 ? $daily_data[$date]['Mild Beer']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
                
              <?php else: ?>
                <!-- Subsequent displayed dates - Show only 3 rows (Received, Sold, Closing) -->
                <tr>
                  <td rowspan="3" class="date-col">
                    <div class="date-display">
                      <span><?= $day_num ?></span>
                      <span><?= $month_num ?></span>
                      <span><?= $year_num ?></span>
                    </div>
                  </td>
                  <td rowspan="3" class="permit-col"></td>
                  <td class="type-col row-label">Rec.</td>
                  
                  <!-- Received Section - Show purchase data -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['purchase'][$size]) && $daily_data[$date]['Spirit']['purchase'][$size] > 0 ? $daily_data[$date]['Spirit']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['purchase'][$size]) && $daily_data[$date]['Wine']['purchase'][$size] > 0 ? $daily_data[$date]['Wine']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['purchase'][$size]) && $daily_data[$date]['Fermented Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['purchase'][$size]) && $daily_data[$date]['Mild Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Mild Beer']['purchase'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Closing Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                </tr>
                
                <tr>
                  <td class="type-col row-label">Sold</td>
                  
                  <!-- Received Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Sold Section - Show sales data -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['sales'][$size]) && $daily_data[$date]['Spirit']['sales'][$size] > 0 ? $daily_data[$date]['Spirit']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['sales'][$size]) && $daily_data[$date]['Wine']['sales'][$size] > 0 ? $daily_data[$date]['Wine']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['sales'][$size]) && $daily_data[$date]['Fermented Beer']['sales'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['sales'][$size]) && $daily_data[$date]['Mild Beer']['sales'][$size] > 0 ? $daily_data[$date]['Mild Beer']['sales'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                </tr>
                
                <tr>
                  <td class="type-col row-label">Clo.</td>
                  
                  <!-- Received Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Sold Section - Empty -->
                  <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                    <td></td>
                  <?php endfor; ?>
                  
                  <!-- Closing Section - Show calculated closing -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= isset($daily_data[$date]['Spirit']['closing'][$size]) && $daily_data[$date]['Spirit']['closing'][$size] > 0 ? $daily_data[$date]['Spirit']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= isset($daily_data[$date]['Wine']['closing'][$size]) && $daily_data[$date]['Wine']['closing'][$size] > 0 ? $daily_data[$date]['Wine']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Fermented Beer']['closing'][$size]) && $daily_data[$date]['Fermented Beer']['closing'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>
                  
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= isset($daily_data[$date]['Mild Beer']['closing'][$size]) && $daily_data[$date]['Mild Beer']['closing'][$size] > 0 ? $daily_data[$date]['Mild Beer']['closing'][$size] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endif; ?>
              <?php endforeach; ?>
              
              <?php if ($date_count == 0): ?>
                <tr>
                  <td colspan="<?= 3 + ($total_columns_per_section * 3) ?>" class="text-center">No data available for the selected date range.</td>
                </tr>
              <?php endif; ?>
              
              <!-- Summary rows -->
              <tr class="summary-row">
                <td colspan="3">Total Received</td>
                
                <!-- Received Section - Show purchase totals -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= isset($totals['Spirit']['purchase'][$size]) && $totals['Spirit']['purchase'][$size] > 0 ? $totals['Spirit']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= isset($totals['Wine']['purchase'][$size]) && $totals['Wine']['purchase'][$size] > 0 ? $totals['Wine']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($totals['Fermented Beer']['purchase'][$size]) && $totals['Fermented Beer']['purchase'][$size] > 0 ? $totals['Fermented Beer']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($totals['Mild Beer']['purchase'][$size]) && $totals['Mild Beer']['purchase'][$size] > 0 ? $totals['Mild Beer']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Closing Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>
              </tr>
              
              <tr class="summary-row">
                <td colspan="3">Total Sold</td>
                
                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Sold Section - Show sales totals -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= isset($totals['Spirit']['sales'][$size]) && $totals['Spirit']['sales'][$size] > 0 ? $totals['Spirit']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= isset($totals['Wine']['sales'][$size]) && $totals['Wine']['sales'][$size] > 0 ? $totals['Wine']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($totals['Fermented Beer']['sales'][$size]) && $totals['Fermented Beer']['sales'][$size] > 0 ? $totals['Fermented Beer']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($totals['Mild Beer']['sales'][$size]) && $totals['Mild Beer']['sales'][$size] > 0 ? $totals['Mild Beer']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <!-- Closing Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>
              </tr>
              
              <tr class="summary-row">
                <td colspan="3">Closing Balance</td>
                
                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns_per_section; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Closing Section - Show calculated closing balance -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= isset($closing_balance['Spirit'][$size]) && $closing_balance['Spirit'][$size] > 0 ? $closing_balance['Spirit'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= isset($closing_balance['Wine'][$size]) && $closing_balance['Wine'][$size] > 0 ? $closing_balance['Wine'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($closing_balance['Fermented Beer'][$size]) && $closing_balance['Fermented Beer'][$size] > 0 ? $closing_balance['Fermented Beer'][$size] : '' ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= isset($closing_balance['Mild Beer'][$size]) && $closing_balance['Mild Beer'][$size] > 0 ? $closing_balance['Mild Beer'][$size] : '' ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <p>Note: This is a computer generated report and does not require signature.</p>
          <p>Generated on: <?= date('d-M-Y h:i A') ?> | Total Days: <?= $date_count ?></p>
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
<?php require_once 'components/financial_year_footer.php'; ?>

</body>
</html>