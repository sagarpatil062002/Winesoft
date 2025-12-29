<?php
session_start();
require_once 'drydays_functions.php'; // Single include
require_once 'license_functions.php'; // ADDED: Include license 
require_once 'cash_memo_functions.php'; // ADDED: Include cash memo functions

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/sales_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Function to log array data in a readable format
function logArray($data, $title = 'Array data') {
    ob_start();
    print_r($data);
    $output = ob_get_clean();
    logMessage("$title:\n$output");
}

// DEBUG: Log page access and basic info
logMessage("=== PAGE ACCESS ===");
logMessage("Request method: " . $_SERVER['REQUEST_METHOD']);
logMessage("Search term: '" . ($_GET['search'] ?? '') . "'");
logMessage("Current session ID: " . session_id());

// Function to clear session quantities
function clearSessionQuantities() {
    if (isset($_SESSION['sale_quantities'])) {
        unset($_SESSION['sale_quantities']);
        logMessage("Session quantities cleared");
    }
}

// Enhanced stock validation function
function validateStock($current_stock, $requested_qty, $item_code) {
    if ($requested_qty <= 0) return true;
    
    if ($requested_qty > $current_stock) {
        logMessage("Stock validation failed for item $item_code: Available: $current_stock, Requested: $requested_qty", 'WARNING');
        return false;
    }
    
    // Additional safety check - prevent negative values
    if ($current_stock - $requested_qty < 0) {
        logMessage("Negative closing balance prevented for item $item_code", 'WARNING');
        return false;
    }
    
    return true;
}

// Function to get the correct daily stock table name
function getDailyStockTableName($conn, $date, $comp_id) {
    $current_month = date('Y-m'); // Current month in "YYYY-MM" format
    $date_month = date('Y-m', strtotime($date)); // Date month in "YYYY-MM" format
    
    if ($date_month === $current_month) {
        // Use current month table (no suffix)
        return "tbldailystock_" . $comp_id;
    } else {
        // Use archived month table (with suffix)
        $month_suffix = date('m_y', strtotime($date)); // e.g., "09_25"
        return "tbldailystock_" . $comp_id . "_" . $month_suffix;
    }
}

// Function to get stock as of a specific date from daily stock tables
function getStockAsOfDate($conn, $item_code, $date, $comp_id) {
    try {
        // Get the correct table name
        $daily_stock_table = getDailyStockTableName($conn, $date, $comp_id);
        
        // Check if the table exists
        $check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
        $table_result = $conn->query($check_table_query);
        
        if ($table_result->num_rows == 0) {
            logMessage("Daily stock table '$daily_stock_table' not found for date $date", 'WARNING');
            return 0; // Table doesn't exist, return 0 stock
        }
        
        // Extract day number from date (e.g., 2024-09-05 → day 05)
        $day_num = date('d', strtotime($date));
        $day_column = "DAY_" . str_pad($day_num, 2, '0', STR_PAD_LEFT) . "_CLOSING"; // Changed from _OPEN
        
        // Check if the column exists in the table
        $check_column_query = "SHOW COLUMNS FROM $daily_stock_table LIKE '$day_column'";
        $column_result = $conn->query($check_column_query);
        
        if ($column_result->num_rows == 0) {
            logMessage("Column '$day_column' not found in table '$daily_stock_table' for date $date", 'WARNING');
            return 0; // Column doesn't exist, return 0 stock
        }
        
        // Get the month for the date (YYYY-MM format)
        $month_year = date('Y-m', strtotime($date));
        
        // Query to get the closing stock for the specific day
        $stock_query = "SELECT $day_column as stock_value 
                       FROM $daily_stock_table 
                       WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("ss", $item_code, $month_year);
        $stock_stmt->execute();
        $stock_result = $stock_stmt->get_result();
        
        if ($stock_result->num_rows > 0) {
            $stock_data = $stock_result->fetch_assoc();
            $stock_value = $stock_data['stock_value'] ?? 0;
            $stock_stmt->close();
            
            logMessage("Stock for item $item_code on $date: $stock_value (from $day_column in $daily_stock_table)");
            return floatval($stock_value);
        } else {
            $stock_stmt->close();
            logMessage("No stock record found for item $item_code in table '$daily_stock_table' for date $date", 'WARNING');
            return 0; // No record found, return 0 stock
        }
        
    } catch (Exception $e) {
        logMessage("Error getting stock for item $item_code on $date: " . $e->getMessage(), 'ERROR');
        return 0; // Return 0 on error
    }
}

// ============================================================================
// DATE AVAILABILITY FUNCTIONS
// ============================================================================

// Function to get all dates between start and end date
function getDatesBetween($start_date, $end_date) {
    $dates = [];
    $current = strtotime($start_date);
    $end = strtotime($end_date);
    
    while ($current <= $end) {
        $dates[] = date('Y-m-d', $current);
        $current = strtotime('+1 day', $current);
    }
    
    return $dates;
}

// Function to get item's latest sale date
function getItemLatestSaleDate($conn, $item_code, $comp_id) {
    $query = "SELECT MAX(BILL_DATE) as latest_sale_date 
              FROM tblsaleheader sh
              JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO
              WHERE sd.ITEM_CODE = ? AND sh.COMP_ID = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $item_code, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['latest_sale_date'];
    }
    
    return null;
}

// Function to get dry days for a date range
function getDryDaysForDateRange($conn, $start_date, $end_date) {
    $dry_days = [];
    
    try {
        // First check if the table exists
        $check_table = "SHOW TABLES LIKE 'tbldrydays'";
        $table_result = $conn->query($check_table);
        
        if ($table_result->num_rows == 0) {
            // Table doesn't exist, return empty array
            logMessage("tbldrydays table not found, returning empty dry days array", 'INFO');
            return [];
        }
        
        // Query to get dry days in the date range
        $query = "SELECT DRY_DATE FROM tbldrydays 
                  WHERE DRY_DATE BETWEEN ? AND ? 
                  ORDER BY DRY_DATE";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $dry_days[] = $row['DRY_DATE'];
        }
        
        $stmt->close();
        logMessage("Found " . count($dry_days) . " dry days between $start_date and $end_date");
        
    } catch (Exception $e) {
        logMessage("Error getting dry days: " . $e->getMessage(), 'ERROR');
        return []; // Return empty array on error
    }
    
    return $dry_days;
}

// Function to check item's date availability
function getItemDateAvailability($conn, $item_code, $start_date, $end_date, $comp_id) {
    $all_dates = getDatesBetween($start_date, $end_date);
    $available_dates = [];
    $blocked_dates = [];
    
    // Get item's latest sale date
    $latest_sale_date = getItemLatestSaleDate($conn, $item_code, $comp_id);
    
    // Get dry days for the date range
    $dry_days = getDryDaysForDateRange($conn, $start_date, $end_date);
    
    foreach ($all_dates as $date) {
        $is_available = true;
        $block_reason = "";
        
        // Check 1: Date must be after latest sale date
        if ($latest_sale_date && $date <= $latest_sale_date) {
            $is_available = false;
            $block_reason = "Sale already recorded on " . date('d-M-Y', strtotime($latest_sale_date));
        }
        
        // Check 2: Date must not be a dry day
        if (in_array($date, $dry_days)) {
            $is_available = false;
            $block_reason = "Dry day";
        }
        
        // Check 3: Date must not be in the future
        if (strtotime($date) > strtotime(date('Y-m-d'))) {
            $is_available = false;
            $block_reason = "Future date";
        }
        
        if ($is_available) {
            $available_dates[$date] = true;
        } else {
            $blocked_dates[$date] = $block_reason;
        }
    }
    
    return [
        'available_dates' => $available_dates,
        'blocked_dates' => $blocked_dates,
        'latest_sale_date' => $latest_sale_date,
        'total_dates' => count($all_dates),
        'available_count' => count($available_dates),
        'blocked_count' => count($blocked_dates)
    ];
}

// Function to distribute sales only across available dates
function distributeSalesAcrossAvailableDates($total_qty, $date_array, $available_dates) {
    if ($total_qty <= 0) {
        return array_fill_keys($date_array, 0);
    }
    
    // Filter to get only available dates from the date array
    $available_dates_in_range = array_intersect($date_array, array_keys($available_dates));
    
    if (empty($available_dates_in_range)) {
        // If no dates available, return all zeros
        return array_fill_keys($date_array, 0);
    }
    
    $available_count = count($available_dates_in_range);
    $base_qty = floor($total_qty / $available_count);
    $remainder = $total_qty % $available_count;
    
    // Initialize distribution with zeros for all dates
    $distribution = array_fill_keys($date_array, 0);
    
    // Distribute base quantity to available dates
    foreach ($available_dates_in_range as $date) {
        $distribution[$date] = $base_qty;
    }
    
    // Distribute remainder to first few available dates
    $available_dates_list = array_values($available_dates_in_range);
    for ($i = 0; $i < $remainder; $i++) {
        $distribution[$available_dates_list[$i]]++;
    }
    
    // Shuffle the distribution among available dates
    $available_dates_keys = array_keys($available_dates_in_range);
    shuffle($available_dates_keys);
    
    $shuffled_distribution = array_fill_keys($date_array, 0);
    $available_values = [];
    
    foreach ($available_dates_keys as $date) {
        $available_values[] = $distribution[$date];
    }
    
    // Assign shuffled values back to available dates
    foreach ($available_dates_keys as $index => $date) {
        $shuffled_distribution[$date] = $available_values[$index];
    }
    
    return $shuffled_distribution;
}

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    logMessage('User not logged in, redirecting to index.php', 'WARNING');
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    logMessage('Company or financial year not set, redirecting to index.php', 'WARNING');
    header("Location: index.php");
    exit;
}

include_once "../config/db.php"; // MySQLi connection in $conn

// ============================================================================
// LICENSE-BASED FILTERING - ADDED FROM ITEM_MASTER.PHP
// ============================================================================

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// ============================================================================
// PERFORMANCE OPTIMIZATION: DATABASE INDEXING
// ============================================================================
$index_queries = [
    "CREATE INDEX IF NOT EXISTS idx_itemmaster_liq_flag ON tblitemmaster(LIQ_FLAG)",
    "CREATE INDEX IF NOT EXISTS idx_itemmaster_code ON tblitemmaster(CODE)", 
    "CREATE INDEX IF NOT EXISTS idx_item_stock_item_code ON tblitem_stock(ITEM_CODE)",
    "CREATE INDEX IF NOT EXISTS idx_itemmaster_details ON tblitemmaster(DETAILS)",
    "CREATE INDEX IF NOT EXISTS idx_itemmaster_class ON tblitemmaster(CLASS)"
];

foreach ($index_queries as $query) {
    try {
        $conn->query($query);
    } catch (Exception $e) {
        // Index might already exist, continue
    }
}

// Include volume limit utilities
include_once "volume_limit_utils.php";
include_once "stock_functions.php";

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Sequence type selection (default user_defined)
$sequence_type = isset($_GET['sequence_type']) ? $_GET['sequence_type'] : 'user_defined';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Date range selection (default to current day only)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get company ID
$comp_id = $_SESSION['CompID'];

// Validate that end date is not in the future
if (strtotime($end_date) > strtotime(date('Y-m-d'))) {
    $end_date = date('Y-m-d');
    logMessage("End date adjusted to today's date as future date was selected", 'WARNING');
}

// Get day number for end date
$end_day = date('d', strtotime($end_date));
$end_day_column = "DAY_" . str_pad($end_day, 2, '0', STR_PAD_LEFT) . "_CLOSING"; // Changed from _OPEN

// Get the correct daily stock table for end date
$daily_stock_table = getDailyStockTableName($conn, $end_date, $comp_id);

// Check if the daily stock table exists
$check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
$table_result = $conn->query($check_table_query);
$table_exists = ($table_result->num_rows > 0);

if (!$table_exists) {
    logMessage("Daily stock table '$daily_stock_table' not found for end date $end_date", 'ERROR');
    $error_message = "Daily stock data not available for the selected end date ($end_date). Please select a different date range.";
}

// Build the order clause based on sequence type
$order_clause = "";
if ($sequence_type === 'system_defined') {
    $order_clause = "ORDER BY im.CODE ASC";
} elseif ($sequence_type === 'group_defined') {
    $order_clause = "ORDER BY im.DETAILS2 ASC, im.DETAILS ASC";
} else {
    // User defined (default)
    $order_clause = "ORDER BY im.DETAILS ASC";
}

// ============================================================================
// PERFORMANCE OPTIMIZATION: PAGINATION WITH LICENSE FILTERING
// ============================================================================
$items_per_page = 50; // Adjust based on your needs
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// MODIFIED: Get total count for pagination with license filtering AND stock > 0 filter
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $count_query = "SELECT COUNT(DISTINCT im.CODE) as total 
                    FROM tblitemmaster im 
                    LEFT JOIN $daily_stock_table dst ON im.CODE = dst.ITEM_CODE 
                    WHERE im.LIQ_FLAG = ? 
                    AND im.CLASS IN ($class_placeholders)
                    AND dst.STK_MONTH = ?
                    AND dst.$end_day_column > 0";
    
    $count_params = array_merge([$mode], $allowed_classes, [date('Y-m', strtotime($end_date))]);
    $count_types = str_repeat('s', count($allowed_classes) + 2); // +2 for mode and stk_month
} else {
    // If no classes allowed or table doesn't exist, show empty result
    $count_query = "SELECT COUNT(*) as total FROM tblitemmaster im WHERE 1 = 0";
    $count_params = [];
    $count_types = "";
}

if ($search !== '' && $table_exists) {
    $count_query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_types .= "ss";
}

$total_items = 0;
if ($table_exists) {
    $count_stmt = $conn->prepare($count_query);
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_items = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
}

// MODIFIED: Main query with pagination, license filtering AND stock > 0 filter
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     COALESCE(dst.$end_day_column, 0) as CURRENT_STOCK
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table dst ON im.CODE = dst.ITEM_CODE 
              WHERE im.LIQ_FLAG = ? 
              AND im.CLASS IN ($class_placeholders)
              AND dst.STK_MONTH = ?
              AND dst.$end_day_column > 0";
    
    $params = array_merge([$mode], $allowed_classes, [date('Y-m', strtotime($end_date))]);
    $types = str_repeat('s', count($allowed_classes) + 2); // +2 for mode and stk_month
} else {
    // If no classes allowed or table doesn't exist, show empty result
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     0 as CURRENT_STOCK
              FROM tblitemmaster im 
              WHERE 1 = 0";
    $params = [];
    $types = "";
}

if ($search !== '' && $table_exists) {
    $query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " " . $order_clause . " LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= "ii";

$items = [];
if ($table_exists) {
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Calculate total pages
$total_pages = ceil($total_items / $items_per_page);

// ============================================================================
// FIXED: SESSION QUANTITY PRESERVATION WITH PAGINATION
// ============================================================================

// Initialize session if not exists
if (!isset($_SESSION['sale_quantities'])) {
    $_SESSION['sale_quantities'] = [];
}

// ============================================================================
// DATE AVAILABILITY CALCULATION FOR ALL ITEMS
// ============================================================================
$all_dates = getDatesBetween($start_date, $end_date);
$date_array = $all_dates; // Keep for backward compatibility
$days_count = count($all_dates);

// Store date availability information for each item
$item_date_availability = [];
$availability_summary = [
    'fully_available' => 0,
    'partially_available' => 0,
    'not_available' => 0
];

foreach ($items as $item) {
    $item_code = $item['CODE'];
    $availability = getItemDateAvailability($conn, $item_code, $start_date, $end_date, $comp_id);
    $item_date_availability[$item_code] = $availability;
    
    // Update availability summary
    if ($availability['available_count'] == $days_count) {
        $availability_summary['fully_available']++;
    } elseif ($availability['available_count'] > 0) {
        $availability_summary['partially_available']++;
    } else {
        $availability_summary['not_available']++;
    }
}

// Handle form submission to update session quantities
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sale_qty'])) {
    foreach ($_POST['sale_qty'] as $item_code => $qty) {
        $qty_val = intval($qty);
        if ($qty_val > 0) {
            $_SESSION['sale_quantities'][$item_code] = $qty_val;
        } else {
            // Remove zero quantities to keep session clean
            unset($_SESSION['sale_quantities'][$item_code]);
        }
    }
    
    // Log the update
    logMessage("Session quantities updated from POST: " . count($_SESSION['sale_quantities']) . " items");
}

// MODIFIED: Get ALL items data for JavaScript from ALL modes for Total Sales Summary
// This now uses the daily stock table for the end date
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS, im.LIQ_FLAG, im.RPRICE,
                               COALESCE(dst.$end_day_column, 0) as CURRENT_STOCK
                        FROM tblitemmaster im
                        LEFT JOIN $daily_stock_table dst ON im.CODE = dst.ITEM_CODE 
                        WHERE im.CLASS IN ($class_placeholders)
                        AND dst.STK_MONTH = ?
                        AND dst.$end_day_column > 0"; // REMOVED mode filter
    $all_items_stmt = $conn->prepare($all_items_query);
    $all_items_params = array_merge($allowed_classes, [date('Y-m', strtotime($end_date))]); // REMOVED mode parameter
    $all_items_types = str_repeat('s', count($all_items_params));
    $all_items_stmt->bind_param($all_items_types, ...$all_items_params);
} else {
    $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS, im.LIQ_FLAG, im.RPRICE,
                               0 as CURRENT_STOCK
                        FROM tblitemmaster im
                        WHERE 1 = 0";
    $all_items_stmt = $conn->prepare($all_items_query);
}

$all_items_stmt->execute();
$all_items_result = $all_items_stmt->get_result();
$all_items_data = [];
while ($row = $all_items_result->fetch_assoc()) {
    $all_items_data[$row['CODE']] = $row;
}
$all_items_stmt->close();

// Function to update item stock
function updateItemStock($conn, $item_code, $qty, $current_stock_column, $opening_stock_column, $fin_year_id) {
    // Check if record exists first
    $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_stock_query);
    $check_stmt->bind_param("s", $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $stock_exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($stock_exists) {
        $stock_query = "UPDATE tblitem_stock SET $current_stock_column = $current_stock_column - ? WHERE ITEM_CODE = ?";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("ds", $qty, $item_code);
        $stock_stmt->execute();
        $stock_stmt->close();
    } else {
        $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_stock_column, $current_stock_column) 
                               VALUES (?, ?, ?, ?)";
        $insert_stock_stmt = $conn->prepare($insert_stock_query);
        $current_stock = -$qty; // Negative since we're deducting
        $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $current_stock, $current_stock);
        $insert_stock_stmt->execute();
        $insert_stock_stmt->close();
    }
}

// ENHANCED: Function to update daily stock table with support for both archived and current tables
function updateDailyStock($conn, $item_code, $sale_date, $qty, $comp_id) {
    // Determine the correct table name based on sale date
    $current_month = date('Y-m'); // Current month in "YYYY-MM" format
    $sale_month = date('Y-m', strtotime($sale_date)); // Sale month in "YYYY-MM" format
    
    if ($sale_month === $current_month) {
        // Use current month table (no suffix)
        $sale_daily_stock_table = "tbldailystock_" . $comp_id;
    } else {
        // Use archived month table (with suffix)
        $sale_month_year = date('m_y', strtotime($sale_date)); // e.g., "09_25"
        $sale_daily_stock_table = "tbldailystock_" . $comp_id . "_" . $sale_month_year;
    }
    
    // Current active table (without month suffix) - for updating current month when sale is in archived month
    $current_daily_stock_table = "tbldailystock_" . $comp_id;
    
    // Extract day number from date (e.g., 2025-09-27 → day 27)
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $sales_column = "DAY_{$day_num}_SALES";
    $closing_column = "DAY_{$day_num}_CLOSING";
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    
    $month_year_full = date('Y-m', strtotime($sale_date)); // e.g., "2025-09"
    
    // ============================================================================
    // STEP 1: UPDATE THE CORRECT STOCK TABLE (CURRENT OR ARCHIVED)
    // ============================================================================
    
    // First, check if the required table exists
    $check_table_query = "SHOW TABLES LIKE '$sale_daily_stock_table'";
    $table_result = $conn->query($check_table_query);
    
    if ($table_result->num_rows == 0) {
        throw new Exception("Stock table '$sale_daily_stock_table' not found for item $item_code on date $sale_date");
    }
    
    // Check if record exists for this month and item
    $check_query = "SELECT $closing_column, $opening_column, $purchase_column, $sales_column 
                    FROM $sale_daily_stock_table 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $month_year_full, $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $check_stmt->close();
        throw new Exception("No stock record found for item $item_code in table $sale_daily_stock_table for date $sale_date");
    }
    
    $current_values = $check_result->fetch_assoc();
    $check_stmt->close();
    
    $current_closing = $current_values[$closing_column] ?? 0;
    $current_opening = $current_values[$opening_column] ?? 0;
    $current_purchase = $current_values[$purchase_column] ?? 0;
    $current_sales = $current_values[$sales_column] ?? 0;
    
    // Validate closing stock is sufficient for the sale quantity
    if ($current_closing < $qty) {
        throw new Exception("Insufficient closing stock for item $item_code on $sale_date. Available: $current_closing, Requested: $qty");
    }
    
    // Calculate new sales and closing
    $new_sales = $current_sales + $qty;
    $new_closing = $current_opening + $current_purchase - $new_sales;
    
    // Update existing record with correct closing calculation
    $update_query = "UPDATE $sale_daily_stock_table 
                     SET $sales_column = ?, 
                         $closing_column = ?,
                         LAST_UPDATED = CURRENT_TIMESTAMP 
                     WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("ddss", $new_sales, $new_closing, $month_year_full, $item_code);
    $update_stmt->execute();
    
    if ($update_stmt->affected_rows === 0) {
        $update_stmt->close();
        throw new Exception("Failed to update daily stock for item $item_code on $sale_date in table $sale_daily_stock_table");
    }
    $update_stmt->close();
    
    // Update next day's opening stock if it exists (and if we're not at month end)
    $next_day = intval($day_num) + 1;
    if ($next_day <= 31) {
        $next_day_num = sprintf('%02d', $next_day);
        $next_opening_column = "DAY_{$next_day_num}_OPEN";
        
        // Check if next day exists in the table
        $check_next_day_query = "SHOW COLUMNS FROM $sale_daily_stock_table LIKE '$next_opening_column'";
        $next_day_result = $conn->query($check_next_day_query);
        
        if ($next_day_result->num_rows > 0) {
            // Update next day's opening to match current day's closing
            $update_next_query = "UPDATE $sale_daily_stock_table 
                                 SET $next_opening_column = ?,
                                     LAST_UPDATED = CURRENT_TIMESTAMP 
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_next_stmt = $conn->prepare($update_next_query);
            $update_next_stmt->bind_param("dss", $new_closing, $month_year_full, $item_code);
            $update_next_stmt->execute();
            $update_next_stmt->close();
        }
    }
    
    // ============================================================================
    // STEP 2: UPDATE CURRENT ACTIVE TABLE IF SALE DATE IS IN ARCHIVED MONTH
    // ============================================================================
    
    // Check if sale date is in a different (archived) month than current month
    if ($sale_month < $current_month) {
        // Sale is for archived month, update current active table
        
        // Check if current active table exists
        $check_current_table = "SHOW TABLES LIKE '$current_daily_stock_table'";
        $current_table_result = $conn->query($check_current_table);
        
        if ($current_table_result->num_rows > 0) {
            // Get current month's data
            $current_stk_month = date('Y-m');
            
            // Check if item exists in current table
            $check_current_item = "SELECT COUNT(*) as count FROM $current_daily_stock_table 
                                  WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $check_current_stmt = $conn->prepare($check_current_item);
            $check_current_stmt->bind_param("ss", $item_code, $current_stk_month);
            $check_current_stmt->execute();
            $check_current_result = $check_current_stmt->get_result();
            $item_exists = $check_current_result->fetch_assoc()['count'] > 0;
            $check_current_stmt->close();
            
            if ($item_exists) {
                // Adjust current month's opening stock by deducting the sale quantity
                $update_current_opening = "UPDATE $current_daily_stock_table 
                                          SET DAY_01_OPEN = DAY_01_OPEN - ?,
                                              LAST_UPDATED = CURRENT_TIMESTAMP 
                                          WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                $update_current_stmt = $conn->prepare($update_current_opening);
                $update_current_stmt->bind_param("dss", $qty, $item_code, $current_stk_month);
                $update_current_stmt->execute();
                
                if ($update_current_stmt->affected_rows === 0) {
                    logMessage("Warning: Failed to update current table opening stock for item $item_code", 'WARNING');
                }
                $update_current_stmt->close();
                
                // Recalculate closing balances for all days in current month
                recalculateCurrentMonthStock($conn, $current_daily_stock_table, $item_code, $current_stk_month);
            }
        }
    }
    
    logMessage("Daily stock updated successfully for item $item_code on $sale_date in table $sale_daily_stock_table: Sales=$new_sales, Closing=$new_closing");
}
// Helper function to recalculate current month's stock
function recalculateCurrentMonthStock($conn, $table_name, $item_code, $stk_month) {
    // Start from day 1 and recalculate all days
    for ($day = 1; $day <= 31; $day++) {
        $day_num = sprintf('%02d', $day);
        $opening_column = "DAY_{$day_num}_OPEN";
        $purchase_column = "DAY_{$day_num}_PURCHASE";
        $sales_column = "DAY_{$day_num}_SALES";
        $closing_column = "DAY_{$day_num}_CLOSING";
        
        // Check if day columns exist
        $check_columns = "SHOW COLUMNS FROM $table_name LIKE '$opening_column'";
        $column_result = $conn->query($check_columns);
        
        if ($column_result->num_rows == 0) {
            continue; // Day doesn't exist in table
        }
        
        // Get current values for this day
        $day_query = "SELECT $opening_column, $purchase_column, $sales_column 
                      FROM $table_name 
                      WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $day_stmt = $conn->prepare($day_query);
        $day_stmt->bind_param("ss", $item_code, $stk_month);
        $day_stmt->execute();
        $day_result = $day_stmt->get_result();
        
        if ($day_result->num_rows > 0) {
            $day_values = $day_result->fetch_assoc();
            $opening = $day_values[$opening_column] ?? 0;
            $purchase = $day_values[$purchase_column] ?? 0;
            $sales = $day_values[$sales_column] ?? 0;
            
            // Calculate closing using the same formula
            $closing = $opening + $purchase - $sales;
            
            // Update closing
            $update_query = "UPDATE $table_name 
                            SET $closing_column = ?,
                                LAST_UPDATED = CURRENT_TIMESTAMP 
                            WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("dss", $closing, $item_code, $stk_month);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Set next day's opening to this day's closing
            $next_day = $day + 1;
            if ($next_day <= 31) {
                $next_day_num = sprintf('%02d', $next_day);
                $next_opening_column = "DAY_{$next_day_num}_OPEN";
                
                // Check if next day exists
                $check_next = "SHOW COLUMNS FROM $table_name LIKE '$next_opening_column'";
                $next_result = $conn->query($check_next);
                
                if ($next_result->num_rows > 0) {
                    $update_next_query = "UPDATE $table_name 
                                         SET $next_opening_column = ?,
                                             LAST_UPDATED = CURRENT_TIMESTAMP 
                                         WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                    $update_next_stmt = $conn->prepare($update_next_query);
                    $update_next_stmt->bind_param("dss", $closing, $item_code, $stk_month);
                    $update_next_stmt->execute();
                    $update_next_stmt->close();
                }
            }
        }
        $day_stmt->close();
    }
}

// FIXED: Function to get next bill number with proper zero-padding
// UPDATED: Function to get next bill number with proper zero-padding AND CompID consideration
function getNextBillNumber($conn, $comp_id) {
    logMessage("Getting next bill number for CompID: $comp_id");
    
    // Use transaction for atomic operation
    $conn->begin_transaction();
    
    try {
        // Get the maximum numeric part of bill numbers FOR THIS COMPANY
        $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader WHERE COMP_ID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $comp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $next_bill = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
        $stmt->close();
        
        // Double-check this bill number doesn't exist FOR THIS COMPANY (prevent race conditions)
        $check_query = "SELECT COUNT(*) as count FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
        $check_stmt = $conn->prepare($check_query);
        $bill_no_to_check = "BL" . str_pad($next_bill, 4, '0', STR_PAD_LEFT);
        $check_stmt->bind_param("si", $bill_no_to_check, $comp_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $exists = $check_result->fetch_assoc()['count'] > 0;
        $check_stmt->close();
        
        if ($exists) {
            // If it exists, increment and check again
            $next_bill++;
        }
        
        $conn->commit();
        logMessage("Next bill number for CompID $comp_id: $next_bill");
        
        return $next_bill;
        
    } catch (Exception $e) {
        $conn->rollback();
        logMessage("Error getting next bill number for CompID $comp_id: " . $e->getMessage(), 'ERROR');
        
        // Fallback method
        $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader WHERE COMP_ID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $comp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
    }
}

// ============================================================================
// PERFORMANCE OPTIMIZATION: BULK OPERATION HANDLING
// ============================================================================

// Handle form submission for sales update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ============================================================================
    // PERFORMANCE OPTIMIZATION - PREVENT TIMEOUT FOR LARGE OPERATIONS
    // ============================================================================
    set_time_limit(0); // No time limit
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '1024M'); // 1GB memory
    
    // Database optimizations
    $conn->query("SET SESSION wait_timeout = 28800");
    $conn->query("SET autocommit = 0");
    
    // Check if this is a bulk operation
    $bulk_operation = (count($_SESSION['sale_quantities'] ?? []) > 100);
    
    if ($bulk_operation) {
        logMessage("Starting bulk sales operation with " . count($_SESSION['sale_quantities']) . " items - Performance mode enabled");
    }
    
    // Check if this is a duplicate submission
    if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 5) {
        $error_message = "Duplicate submission detected. Please wait a few seconds before trying again.";
        logMessage("Duplicate submission prevented for user " . $_SESSION['user_id'], 'WARNING');
    } else {
        $_SESSION['last_submission'] = time();
        
        if (isset($_POST['update_sales'])) {
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $comp_id = $_SESSION['CompID'];
            $user_id = $_SESSION['user_id'];
            $fin_year_id = $_SESSION['FIN_YEAR_ID'];
            
            // MODIFIED: Get ALL items from database for validation WITHOUT mode filtering
            // Now using daily stock table for end date
            $end_day = date('d', strtotime($end_date));
            $end_day_column = "DAY_" . str_pad($end_day, 2, '0', STR_PAD_LEFT) . "_CLOSING"; // Changed from _OPEN
            $daily_stock_table = getDailyStockTableName($conn, $end_date, $comp_id);
            
            if (!empty($allowed_classes)) {
                $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
                $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, im.LIQ_FLAG,
                                           COALESCE(dst.$end_day_column, 0) as CURRENT_STOCK
                                    FROM tblitemmaster im
                                    LEFT JOIN $daily_stock_table dst ON im.CODE = dst.ITEM_CODE 
                                    WHERE im.CLASS IN ($class_placeholders)
                                    AND dst.STK_MONTH = ?
                                    AND dst.$end_day_column > 0"; // REMOVED mode filter
                $all_items_stmt = $conn->prepare($all_items_query);
                $all_items_params = array_merge($allowed_classes, [date('Y-m', strtotime($end_date))]); // REMOVED mode parameter
                $all_items_types = str_repeat('s', count($all_items_params));
                $all_items_stmt->bind_param($all_items_types, ...$all_items_params);
            } else {
                $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, im.LIQ_FLAG,
                                           0 as CURRENT_STOCK
                                    FROM tblitemmaster im
                                    WHERE 1 = 0";
                $all_items_stmt = $conn->prepare($all_items_query);
            }
            
            $all_items_stmt->execute();
            $all_items_result = $all_items_stmt->get_result();
            $all_items = [];
            while ($row = $all_items_result->fetch_assoc()) {
                $all_items[$row['CODE']] = $row;
            }
            $all_items_stmt->close();
            
            // Enhanced stock validation before transaction
            $stock_errors = [];
            if (isset($_SESSION['sale_quantities'])) {
                $item_count = 0;
                foreach ($_SESSION['sale_quantities'] as $item_code => $total_qty) {
                    $item_count++;
                    // Reduce logging frequency for bulk operations
                    if ($bulk_operation && $item_count % 50 == 0) {
                        logMessage("Stock validation progress: $item_count/" . count($_SESSION['sale_quantities']) . " items checked");
                    }
                    
                    if ($total_qty > 0 && isset($all_items[$item_code])) {
                        $current_stock = $all_items[$item_code]['CURRENT_STOCK'];
                        
                        // Enhanced stock validation
                        if (!validateStock($current_stock, $total_qty, $item_code)) {
                            $stock_errors[] = "Item {$item_code}: Available stock {$current_stock}, Requested {$total_qty}";
                        }
                    }
                }
            }
            
            // If stock errors found, stop processing
            if (!empty($stock_errors)) {
                $error_message = "Stock validation failed:<br>" . implode("<br>", array_slice($stock_errors, 0, 5));
                if (count($stock_errors) > 5) {
                    $error_message .= "<br>... and " . (count($stock_errors) - 5) . " more errors";
                }
            } else {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    $total_amount = 0;
                    $items_data = [];
                    $daily_sales_data = [];
                    
                    // Process ALL session quantities > 0 (from ALL modes)
                    if (isset($_SESSION['sale_quantities'])) {
                        $item_count = 0;
                        foreach ($_SESSION['sale_quantities'] as $item_code => $total_qty) {
                            $item_count++;
                            // Reduce logging frequency for bulk operations
                            if ($bulk_operation && $item_count % 50 == 0) {
                                logMessage("Processing progress: $item_count/" . count($_SESSION['sale_quantities']) . " items processed");
                            }
                            
                            if ($total_qty > 0 && isset($all_items[$item_code])) {
                                $item = $all_items[$item_code];
                                
                                // NEW: Get date availability for this item
                                $availability = getItemDateAvailability($conn, $item_code, $start_date, $end_date, $comp_id);
                                
                                // NEW: Check if item has any available dates
                                if ($availability['available_count'] == 0) {
                                    throw new Exception("Item {$item_code} has no available dates for sale. Latest sale recorded on " . 
                                        ($availability['latest_sale_date'] ? date('d-M-Y', strtotime($availability['latest_sale_date'])) : 'unknown date'));
                                }
                                
                                // NEW: Generate distribution only across available dates
                                $daily_sales = distributeSalesAcrossAvailableDates($total_qty, $all_dates, $availability['available_dates']);
                                $daily_sales_data[$item_code] = $daily_sales;
                                
                                // Store item data
                                $items_data[$item_code] = [
                                    'name' => $item['DETAILS'],
                                    'rate' => $item['RPRICE'],
                                    'total_qty' => $total_qty,
                                    'mode' => $item['LIQ_FLAG'] // Use item's actual mode
                                ];
                            }
                        }
                    }
                    
                    // Only proceed if we have items with quantities
                    if (!empty($items_data)) {
                        $bills = generateBillsWithLimits($conn, $items_data, $all_dates, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
                        
                        // Get stock column names
                        $current_stock_column = "Current_Stock" . $comp_id;
                        $opening_stock_column = "Opening_Stock" . $comp_id;
                        
                        // Get next bill number once to ensure proper numerical order
                        $next_bill_number = getNextBillNumber($conn, $comp_id);
                        
                        // Process each bill in chronological AND numerical order
                        usort($bills, function($a, $b) {
                            return strtotime($a['bill_date']) - strtotime($b['bill_date']);
                        });
                        
                        // Process each bill with proper zero-padded bill numbers
                        $bill_count = 0;
                        $total_bills = count($bills);
                        
                        foreach ($bills as $bill) {
                            $bill_count++;
                            if ($bulk_operation && $bill_count % 10 == 0) {
                                logMessage("Bill generation progress: $bill_count/$total_bills bills created");
                            }
                            
                            $padded_bill_no = "BL" . str_pad($next_bill_number++, 4, '0', STR_PAD_LEFT);
                            
                            // Insert sale header
                            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                             VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                            $header_stmt = $conn->prepare($header_query);
                            $header_stmt->bind_param("ssddssi", $padded_bill_no, $bill['bill_date'], $bill['total_amount'], 
                                                    $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
                            $header_stmt->execute();
                            $header_stmt->close();
                            
                            // Insert sale details for each item in the bill
                            foreach ($bill['items'] as $item) {
                                $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                                $detail_stmt = $conn->prepare($detail_query);
                                $detail_stmt->bind_param("ssddssi", $padded_bill_no, $item['code'], $item['qty'], 
                                                        $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                                $detail_stmt->execute();
                                $detail_stmt->close();
                                
                                // Update stock
                                updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);

                                // Update daily stock with cascading logic
                                updateCascadingDailyStock($conn, $item['code'], $bill['bill_date'], $comp_id, 'sale', $item['qty']);
                            }
                            
                            $total_amount += $bill['total_amount'];
                        }

                        // ============================================================================
                        // OPTIMIZED CASH MEMO GENERATION - PERFORMANCE SAFE
                        // ============================================================================
                        $cash_memos_generated = 0;
                        $cash_memo_errors = [];

                        if (count($bills) > 0) {
                            logMessage("Starting optimized cash memo generation for " . count($bills) . " bills");
                            
                            $cash_memo_start_time = time();
                            $MAX_CASH_MEMO_TIME = 20; // seconds - safety limit
                            $cash_memo_count = 0;
                            
                            foreach ($bills as $bill_index => $bill) {
                                // SAFETY: Break if cash memo generation takes too long
                                if ((time() - $cash_memo_start_time) > $MAX_CASH_MEMO_TIME) {
                                    logMessage("Cash memo generation timeout after $MAX_CASH_MEMO_TIME seconds - skipping remaining bills", 'WARNING');
                                    break;
                                }
                                
                                $cash_memo_count++;
                                $padded_bill_no = "BL" . str_pad(($next_bill_number - count($bills) + $bill_index), 4, '0', STR_PAD_LEFT);
                                
                                try {
                                    if (autoGenerateCashMemoForBill($conn, $padded_bill_no, $comp_id, $_SESSION['user_id'])) {
                                        $cash_memos_generated++;
                                        logMessage("Cash memo generated for bill: $padded_bill_no");
                                    } else {
                                        $cash_memo_errors[] = $padded_bill_no;
                                        logMessage("Failed to generate cash memo for bill: $padded_bill_no", 'WARNING');
                                    }
                                } catch (Exception $e) {
                                    $cash_memo_errors[] = $padded_bill_no;
                                    logMessage("Exception generating cash memo for $padded_bill_no: " . $e->getMessage(), 'ERROR');
                                    // CONTINUE with next bill - don't stop entire process
                                }
                                
                                // Small delay for large batches to prevent database overload
                                if (count($bills) > 50 && $cash_memo_count % 10 == 0) {
                                    usleep(100000); // 0.1 second delay
                                }
                            }
                            
                            logMessage("Cash memo generation completed: $cash_memos_generated successful, " . count($cash_memo_errors) . " failed");
                        }

                        // Commit transaction
                        $conn->commit();

                        // CLEAR SESSION QUANTITIES AFTER SUCCESS
                        clearSessionQuantities();

                        $success_message = "Sales distributed successfully! Generated " . count($bills) . " bills. Total Amount: ₹" . number_format($total_amount, 2);

                        // Add cash memo info to success message
                        if ($cash_memos_generated > 0) {
                            $success_message .= " | Cash Memos Generated: " . $cash_memos_generated;
                        }

                        if (!empty($cash_memo_errors)) {
                            $success_message .= " | Failed to generate cash memos for bills: " . implode(", ", array_slice($cash_memo_errors, 0, 5));
                            if (count($cash_memo_errors) > 5) {
                                $success_message .= " and " . (count($cash_memo_errors) - 5) . " more";
                            }
                        }

                        // Clean up memory
                        unset($all_items);
                        unset($items_data);
                        unset($daily_sales_data);
                        unset($bills);
                        gc_collect_cycles();
                        
                        if ($bulk_operation) {
                            logMessage("Bulk sales operation completed successfully");
                        }

                        // Redirect to retail_sale.php
                        header("Location: retail_sale.php?success=" . urlencode($success_message));
                        exit;
                    } else {
                        $error_message = "No quantities entered for any items.";
                    }
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $conn->rollback();
                    $error_message = "Error updating sales: " . $e->getMessage();
                    logMessage("Transaction rolled back: " . $e->getMessage(), 'ERROR');
                }
            }
        }
    }
    
    // Re-enable database constraints
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
}

// Check for success message in URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// Log final state for debugging
logMessage("Final session quantities count: " . count($_SESSION['sale_quantities']));
logMessage("Items in current view: " . count($items));

// Debug info
$debug_info = [
    'total_items' => $total_items,
    'current_page' => $current_page,
    'total_pages' => $total_pages,
    'session_quantities_count' => count($_SESSION['sale_quantities']),
    'post_quantities_count' => ($_SERVER['REQUEST_METHOD'] === 'POST') ? count($_POST['sale_qty'] ?? []) : 0,
    'date_range' => "$start_date to $end_date",
    'days_count' => $days_count,
    'user_id' => $_SESSION['user_id'],
    'comp_id' => $comp_id,
    'license_type' => $license_type, // ADDED: License info in debug
    'allowed_classes' => $allowed_classes, // ADDED: Allowed classes in debug
    'end_day_column' => $end_day_column, // ADDED: Which column we're using
    'daily_stock_table' => $daily_stock_table, // ADDED: Which table we're using
    'table_exists' => $table_exists, // ADDED: Whether table exists
    'availability_summary' => $availability_summary // ADDED: Date availability summary
];
logArray($debug_info, "Sales Page Load Debug Info");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales by Date Range - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .ajax-loader {
      display: none;
      text-align: center;
      padding: 10px;
    }
    .loader {
      border: 5px solid #f3f3f3;
      border-top: 5px solid #3498db;
      border-radius: 50%;
      width: 30px;
      height: 30px;
      animation: spin 1s linear infinite;
      margin: 0 auto;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
   
    /* Remove spinner arrows from number inputs */
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type="number"] {
      -moz-appearance: textfield;
    }
    
    /* Highlight current row */
    .highlight-row {
      background-color: #f8f9fa !important;
      box-shadow: 0 0 5px rgba(0,0,0,0.1);
    }
    
    /* Volume limit info */
    .volume-limit-info {
      background-color: #e9ecef;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
    }

    /* Closing balance warning styles */
.text-warning {
    color: #ffc107 !important;
}

.fw-bold {
    font-weight: bold !important;
}

/* Negative closing balance (should never happen with validation) */
.text-danger {
    color: #dc3545 !important;
    background-color: #f8d7da;
}

/* Reduce space between table rows */
.styled-table tbody tr {
    height: 35px !important; /* Reduced from default */
    line-height: 1.2 !important;
}

.styled-table tbody td {
    padding: 4px 8px !important; /* Reduced padding */
}

/* Reduce input field height */
.qty-input {
    height: 30px !important;
    padding: 2px 6px !important;
}

/* Reduce button size */
.btn-sm {
    padding: 2px 6px !important;
    font-size: 12px !important;
}

/* Highlight rows with quantities */
tr.has-quantity {
    background-color: #e8f5e8 !important; /* Light green background */
    border-left: 3px solid #28a745 !important;
}

/* Make the highlight more noticeable */
tr.has-quantity td {
    font-weight: 500;
}

/* Enhanced validation styles */
.is-invalid {
    border-color: #dc3545 !important;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
}

.quantity-saving {
    background-color: #e8f5e8 !important;
    transition: background-color 0.3s ease;
}

.quantity-error {
    background-color: #f8d7da !important;
    transition: background-color 0.3s ease;
}

/* Button loading state */
.btn-loading {
    position: relative;
    color: transparent !important;
}

.btn-loading:after {
    content: '';
    position: absolute;
    left: 50%;
    top: 50%;
    margin-left: -10px;
    margin-top: -10px;
    width: 20px;
    height: 20px;
    border: 2px solid #ffffff;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spin 1s linear infinite;
}

/* Enhanced Pagination Styles */
.pagination {
    margin: 15px 0;
    justify-content: center;
    flex-wrap: wrap;
}

.pagination .page-item .page-link {
    color: #007bff;
    border: 1px solid #dee2e6;
    padding: 6px 12px;
    font-size: 14px;
    margin: 2px;
}

.pagination .page-item.active .page-link {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
}

.pagination .page-item.disabled .page-link {
    color: #6c757d;
    pointer-events: none;
    background-color: #fff;
    border-color: #dee2e6;
}

.pagination .page-link:hover {
    background-color: #e9ecef;
    border-color: #dee2e6;
}

.pagination-info {
    text-align: center;
    margin: 10px 0;
    color: #6c757d;
    font-size: 14px;
}

/* Smart pagination - show limited pages */
.pagination-smart .page-item {
    display: none;
}

.pagination-smart .page-item:first-child,
.pagination-smart .page-item:last-child,
.pagination-smart .page-item.active,
.pagination-smart .page-item:nth-child(2),
.pagination-smart .page-item:nth-last-child(2) {
    display: block;
}

/* Show ellipsis for hidden pages */
.pagination-ellipsis {
    display: inline-block;
    padding: 6px 12px;
    margin: 2px;
    color: #6c757d;
}

/* Total Sales Summary Table Styles */
#totalSalesTable th {
    font-size: 11px;
    padding: 4px 2px;
    text-align: center;
    white-space: nowrap;
}

#totalSalesTable td {
    font-size: 11px;
    padding: 4px 2px;
    text-align: center;
}

.table-responsive {
    max-height: 600px;
    overflow: auto;
}

.table-success {
    background-color: #d1edff !important;
    font-weight: bold;
}

/* Client-side validation styles */
.validation-alert {
    display: none;
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    max-width: 500px;
}

.stock-checking {
    background-color: #fff3cd !important;
}

/* Stock info note */
.stock-info-note {
    background-color: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 10px;
    margin: 10px 0;
    font-size: 0.9em;
}

/* NEW: Date Availability Styles */
.availability-info {
    background-color: #f8f9fa;
    border-left: 4px solid #0dcaf0;
    padding: 15px;
    margin-bottom: 20px;
}

.availability-summary {
    display: flex;
    gap: 15px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.availability-card {
    flex: 1;
    min-width: 150px;
    padding: 10px;
    border-radius: 5px;
    text-align: center;
}

.availability-card.fully {
    background-color: #d1e7dd;
    border: 1px solid #badbcc;
}

.availability-card.partially {
    background-color: #fff3cd;
    border: 1px solid #ffecb5;
}

.availability-card.none {
    background-color: #f8d7da;
    border: 1px solid #f5c2c7;
}

.availability-count {
    font-size: 24px;
    font-weight: bold;
    display: block;
}

.availability-label {
    font-size: 12px;
    color: #666;
}

/* Date availability badges */
.date-availability-badge {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 5px;
}

.badge-fully {
    background-color: #198754;
    color: white;
}

.badge-partial {
    background-color: #ffc107;
    color: #000;
}

.badge-none {
    background-color: #dc3545;
    color: white;
}

/* Date distribution cell styles */
.date-available {
    background-color: #d1e7dd !important;
    color: #0f5132;
    font-weight: bold;
}

.date-blocked {
    background-color: #f8d7da !important;
    color: #842029;
    text-align: center;
}

.date-available-zero {
    background-color: #e8f5e9 !important;
    color: #2e7d32;
    text-align: center;
}

.date-blocked-icon {
    color: #dc3545;
    font-size: 14px;
}

/* Item availability status */
.item-unavailable {
    opacity: 0.6;
}

.item-unavailable .qty-input {
    background-color: #f8d7da;
    cursor: not-allowed;
}

.availability-tooltip {
    position: relative;
    display: inline-block;
}

.availability-tooltip .tooltip-text {
    visibility: hidden;
    width: 200px;
    background-color: #333;
    color: #fff;
    text-align: center;
    border-radius: 4px;
    padding: 5px;
    position: absolute;
    z-index: 1;
    bottom: 125%;
    left: 50%;
    margin-left: -100px;
    opacity: 0;
    transition: opacity 0.3s;
    font-size: 12px;
}

.availability-tooltip:hover .tooltip-text {
    visibility: visible;
    opacity: 1;
}

  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Sales by Date Range</h3>

      <!-- NEW: Date Availability Information Banner -->
      <div class="availability-info mb-4">
          <h5><i class="fas fa-calendar-check"></i> Date Availability System</h5>
          <p class="mb-2">Sales can only be recorded on dates that are:</p>
          <ul class="mb-3">
              <li><strong>After the item's latest sale date</strong> (prevents duplicate sales on same day)</li>
              <li><strong>Not on dry days</strong> (as per government regulations)</li>
              <li><strong>Not in the future</strong> (only current/past dates allowed)</li>
          </ul>
          
          <!-- Availability Summary Cards -->
          <div class="availability-summary">
              <div class="availability-card fully">
                  <span class="availability-count"><?= $availability_summary['fully_available'] ?></span>
                  <span class="availability-label">Fully Available<br>(All <?= $days_count ?> dates)</span>
              </div>
              <div class="availability-card partially">
                  <span class="availability-count"><?= $availability_summary['partially_available'] ?></span>
                  <span class="availability-label">Partially Available<br>(Some dates blocked)</span>
              </div>
              <div class="availability-card none">
                  <span class="availability-count"><?= $availability_summary['not_available'] ?></span>
                  <span class="availability-label">Not Available<br>(All dates blocked)</span>
              </div>
          </div>
      </div>

      <!-- ADDED: License Restriction Info -->
      <div class="alert alert-info mb-3">
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

      <!-- Stock Info Note -->
      <div class="stock-info-note mb-3">
          <strong><i class="fas fa-info-circle"></i> Stock Information:</strong>
          <p class="mb-0">Showing stock as of <strong><?= date('d-M-Y', strtotime($end_date)) ?></strong> (DAY_<?= $end_day ?>_CLOSING from <?= $daily_stock_table ?> table). Only items with stock > 0 are displayed.</p>
      </div>

      <!-- Success/Error Messages -->
      <?php if (isset($success_message)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <?php if (!$table_exists): ?>
      <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle"></i> Daily stock table '<?= $daily_stock_table ?>' not found for the selected end date. Please select a different date range.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <!-- Client-side Validation Alert -->
      <div class="alert alert-warning validation-alert" id="clientValidationAlert">
        <i class="fas fa-exclamation-triangle"></i>
        <span id="validationMessage"></span>
      </div>

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>

      <!-- Sequence Type Selector -->
      <div class="mb-3">
        <label class="form-label">Sequence Type:</label>
        <div class="btn-group" role="group">
          <a href="?mode=<?= $mode ?>&sequence_type=user_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'user_defined' ? 'sequence-active' : '' ?>">
            User Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=system_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'system_defined' ? 'sequence-active' : '' ?>">
            System Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=group_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'group_defined' ? 'sequence-active' : '' ?>">
                         Group Defined
          </a>
        </div>
      </div>

      <!-- Date Range Selection -->
      <div class="date-range-container mb-4">
        <form method="GET" class="row g-3 align-items-end">
          <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
          <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
          <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>">
          <input type="hidden" name="page" value="1">
          
          <div class="col-md-3">
            <label for="start_date" class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" 
                   value="<?= htmlspecialchars($start_date); ?>" required>
          </div>
          
          <div class="col-md-3">
            <label for="end_date" class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" 
                   value="<?= htmlspecialchars($end_date); ?>" 
                   max="<?= date('Y-m-d'); ?>" required>
          </div>
          
          <div class="col-md-4">
            <label class="form-label">Date Range: 
              <span class="fw-bold">
<?= date('d-M-Y', strtotime($start_date)) . " to " . date('d-M-Y', strtotime($end_date)) ?>
                (<?= $days_count ?> days)
              </span>
            </label>
            <div class="text-muted small">
              Stock shown: As of <?= date('d-M-Y', strtotime($end_date)) ?> closing
            </div>
          </div>
          
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Apply Date Range</button>
          </div>
        </form>
      </div>

      <!-- Search -->
      <div class="row mb-3">
        <div class="col-md-6">
          <form method="GET" class="search-control">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
            <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
            <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">
            <input type="hidden" name="page" value="1">
            <div class="input-group">
              <input type="text" name="search" class="form-control"
                     placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
              <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
              <?php if ($search !== ''): ?>
                <a href="?mode=<?= $mode ?>&sequence_type=<?= $sequence_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1" class="btn btn-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
        <div class="col-md-6 text-end">
          <div class="text-muted">
            Total Items: <?= $total_items ?> | Page: <?= $current_page ?> of <?= $total_pages ?>
            <?php if (count($_SESSION['sale_quantities']) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['sale_quantities']) ?> items with quantities</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Sales Form -->
      <form method="POST" id="salesForm">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
        <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">
        <input type="hidden" name="update_sales" value="1">

        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-3 flex-wrap">
          <button type="button" id="shuffleBtn" class="btn btn-warning btn-action">
            <i class="fas fa-random"></i> Shuffle All
          </button>
          
          <!-- Single Button with Dual Functionality -->
          <button type="button" id="generateBillsBtn" class="btn btn-success btn-action" <?= !$table_exists ? 'disabled' : '' ?>>
            <i class="fas fa-save"></i> Generate Bills
          </button>
          
          <!-- Clear Session Button -->
          <button type="button" id="clearSessionBtn" class="btn btn-danger" <?= !$table_exists ? 'disabled' : '' ?>>
            <i class="fas fa-trash"></i> Clear All Quantities
          </button>
          
          <!-- Sales Log Button -->
          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#salesLogModal" onclick="loadSalesLog()" <?= !$table_exists ? 'disabled' : '' ?>>
              <i class="fas fa-file-alt"></i> View Sales Log
          </button>

          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#totalSalesModal" <?= !$table_exists ? 'disabled' : '' ?>>
              <i class="fas fa-chart-bar"></i> View Total Sales Summary
          </button>
          
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>

        <!-- Items Table with Integrated Distribution Preview -->
        <div class="table-container">
          <table class="styled-table table-striped" id="itemsTable">
            <thead class="table-header">
              <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Rate (₹)</th>
                <th>Closing Stock<br><small>As of <?= date('d-M-Y', strtotime($end_date)) ?></small></th>
                <th>Total Sale Qty</th>
                <th class="closing-balance-header">Closing Balance</th>
                <th class="action-column">Action</th>
                
                <!-- Date Distribution Headers (will be populated by JavaScript) -->
                
                <th class="hidden-columns">Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
<?php if (!empty($items) && $table_exists): ?>
    <?php foreach ($items as $item): 
        $item_code = $item['CODE'];
        $item_qty = isset($_SESSION['sale_quantities'][$item_code]) ? $_SESSION['sale_quantities'][$item_code] : 0;
        $item_total = $item_qty * $item['RPRICE'];
        $closing_balance = $item['CURRENT_STOCK'] - $item_qty;
        
        // Extract size from item details
        $size = 0;
        if (preg_match('/(\d+)\s*ML\b/i', $item['DETAILS'], $matches)) {
            $size = $matches[1];
        }
        
        // Get class code - now available from the query
        $class_code = $item['CLASS'] ?? 'O'; // Default to 'O' if not set
        
        // Get date availability for this item
        $availability = $item_date_availability[$item_code] ?? [
            'available_dates' => [],
            'blocked_dates' => [],
            'available_count' => 0,
            'blocked_count' => $days_count
        ];
        
        // Determine availability badge
        if ($availability['available_count'] == $days_count) {
            $availability_badge = '<span class="date-availability-badge badge-fully">All dates</span>';
        } elseif ($availability['available_count'] > 0) {
            $availability_badge = '<span class="date-availability-badge badge-partial">' . $availability['available_count'] . '/' . $days_count . ' dates</span>';
        } else {
            $availability_badge = '<span class="date-availability-badge badge-none">No dates</span>';
        }
        
        // Determine if item is unavailable (all dates blocked)
        $is_unavailable = ($availability['available_count'] == 0);
    ?>
        <tr data-class="<?= htmlspecialchars($class_code) ?>" 
    data-details="<?= htmlspecialchars($item['DETAILS']) ?>" 
    data-details2="<?= htmlspecialchars($item['DETAILS2']) ?>"
    data-availability='<?= json_encode($availability) ?>'
    class="<?= $item_qty > 0 ? 'has-quantity' : '' ?> <?= $is_unavailable ? 'item-unavailable' : '' ?>">
            <td>
                <?= htmlspecialchars($item_code); ?>
                <?= $availability_badge ?>
            </td>
            <td><?= htmlspecialchars($item['DETAILS']); ?></td>
            <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
            <td><?= number_format($item['RPRICE'], 2); ?></td>
            <td>
                <span class="stock-info"><?= number_format($item['CURRENT_STOCK'], 3); ?></span>
            </td>
            <td>
    <input type="number" name="sale_qty[<?= htmlspecialchars($item_code); ?>]" 
           class="form-control qty-input" min="0" 
           max="<?= floor($item['CURRENT_STOCK']); ?>" 
           step="1" value="<?= $item_qty ?>" 
           data-rate="<?= $item['RPRICE'] ?>"
           data-code="<?= htmlspecialchars($item_code); ?>"
           data-stock="<?= $item['CURRENT_STOCK'] ?>"
           data-size="<?= $size ?>"
           data-available-count="<?= $availability['available_count'] ?>"
           oninput="validateQuantity(this)"
           <?= (!$table_exists || $is_unavailable) ? 'disabled' : '' ?>>
</td>
            <td class="closing-balance-cell" id="closing_<?= htmlspecialchars($item_code); ?>">
                <?= number_format($closing_balance, 3) ?>
            </td>
            <td class="action-column">
                <button type="button" class="btn btn-sm btn-outline-secondary btn-shuffle-item" 
                        data-code="<?= htmlspecialchars($item_code); ?>"
                        <?= (!$table_exists || $is_unavailable) ? 'disabled' : '' ?>>
                    <i class="fas fa-random"></i> Shuffle
                </button>
            </td>
            
            <!-- Date distribution cells will be inserted here by JavaScript -->
            
            <td class="amount-cell hidden-columns" id="amount_<?= htmlspecialchars($item_code); ?>">
                <?= number_format($item_qty * $item['RPRICE'], 2) ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php elseif (!$table_exists): ?>
    <tr>
        <td colspan="9" class="text-center text-warning">
            <i class="fas fa-exclamation-triangle"></i> Daily stock table not found for the selected end date. Please select a different date range.
        </td>
    </tr>
<?php else: ?>
    <tr>
        <td colspan="9" class="text-center text-muted">No items found with stock > 0 for the selected date range.</td>
    </tr>
<?php endif; ?>
</tbody>
            </tfoot>
          </table>
        </div>
        
        <!-- Pagination Controls -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <!-- Previous Button -->
                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                
                <?php
                // Smart pagination - show limited pages
                $show_pages = 5; // Number of page links to show
                $start_page = max(1, $current_page - floor($show_pages / 2));
                $end_page = min($total_pages, $start_page + $show_pages - 1);
                
                // Adjust if we're near the end
                if ($end_page - $start_page < $show_pages - 1) {
                    $start_page = max(1, $end_page - $show_pages + 1);
                }
                
                // Always show first page
                if ($start_page > 1): ?>
                    <li class="page-item <?= 1 == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                    </li>
                    <?php if ($start_page > 2): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                    <?php endif;
                endif;
                
                // Show page numbers
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                <?php endfor;
                
                // Always show last page
                if ($end_page < $total_pages): ?>
                    <?php if ($end_page < $total_pages - 1): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                    <?php endif; ?>
                    <li class="page-item <?= $total_pages == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
                    </li>
                <?php endif; ?>
                
                <!-- Next Button -->
                <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
        <div class="pagination-info">
            Showing <?= count($items) ?> of <?= $total_items ?> items (Page <?= $current_page ?> of <?= $total_pages ?>)
            <?php if (count($_SESSION['sale_quantities']) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['sale_quantities']) ?> items with quantities across all pages</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Ajax Loader -->
        <div id="ajaxLoader" class="ajax-loader">
          <div class="loader"></div>
          <p>Calculating distribution...</p>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Sales Log Modal -->
<div class="modal fade" id="salesLogModal" tabindex="-1" aria-labelledby="salesLogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
                        <div class="modal-header">
                <h5 class="modal-title" id="salesLogModalLabel">Sales Log - Foreign Export</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="salesLogContent" style="max-height: 400px; overflow-y: auto;">
                    <!-- Sales log content will be loaded here -->
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading sales log...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printSalesLog()">Print</button>
            </div>
        </div>
    </div>
</div>

<!-- Total Sales Modal -->
<div class="modal fade" id="totalSalesModal" tabindex="-1" aria-labelledby="totalSalesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="totalSalesModalLabel">Total Sales Summary</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="totalSalesTable">
                        <thead class="table-light">
                            <tr>
                                <th>Category</th>
                                <!-- ML Sizes -->
                                <th>50 ML</th>
                                <th>60 ML</th>
                                <th>90 ML</th>
                                <th>170 ML</th>
                                <th>180 ML</th>
                                <th>200 ML</th>
                                <th>250 ML</th>
                                <th>275 ML</th>
                                <th>330 ML</th>
                                <th>355 ML</th>
                                <th>375 ML</th>
                                <th>500 ML</th>
                                <th>650 ML</th>
                                <th>700 ML</th>
                                <th>750 ML</th>
                                <th>1000 ML</th>
                                <!-- Liter Sizes -->
                                <th>1.5L</th>
                                <th>1.75L</th>
                                <th>2L</th>
                                <th>3L</th>
                                <th>4.5L</th>
                                <th>15L</th>
                                <th>20L</th>
                                <th>30L</th>
                                <th>50L</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Rows will be populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global variables
const dateArray = <?= json_encode($date_array) ?>;
const daysCount = <?= $days_count ?>;
// Pass ALL session quantities to JavaScript
const allSessionQuantities = <?= json_encode($_SESSION['sale_quantities'] ?? []) ?>;
// NEW: Pass ALL items data to JavaScript for Total Sales Summary (ALL modes)
const allItemsData = <?= json_encode($all_items_data) ?>;
// Table exists flag
const tableExists = <?= $table_exists ? 'true' : 'false' ?>;
// Pass date availability data to JavaScript
const itemDateAvailability = <?= json_encode($item_date_availability) ?>;

// NEW: Function to check stock availability via AJAX before submission
function checkStockAvailabilityBeforeSubmit() {
    return new Promise((resolve, reject) => {
        // Check if table exists
        if (!tableExists) {
            reject('Daily stock table not available for the selected date range.');
            return;
        }
        
        // Check if we have any quantities > 0
        let hasQuantity = false;
        for (const itemCode in allSessionQuantities) {
            if (allSessionQuantities[itemCode] > 0) {
                hasQuantity = true;
                break;
            }
        }
        
        if (!hasQuantity) {
            reject('Please enter quantities for at least one item.');
            return;
        }

        // NEW: Check date availability for all items with quantities
        let dateAvailabilityErrors = [];
        for (const itemCode in allSessionQuantities) {
            const qty = allSessionQuantities[itemCode];
            if (qty > 0) {
                const availability = itemDateAvailability[itemCode];
                if (!availability || availability.available_count === 0) {
                    dateAvailabilityErrors.push(`Item ${itemCode} has no available dates for sale`);
                }
            }
        }
        
        if (dateAvailabilityErrors.length > 0) {
            reject('Date availability issues:\n' + dateAvailabilityErrors.slice(0, 3).join('\n') + 
                  (dateAvailabilityErrors.length > 3 ? '\n... and ' + (dateAvailabilityErrors.length - 3) + ' more' : ''));
            return;
        }

        // Show checking state
        $('#generateBillsBtn').prop('disabled', true).addClass('btn-loading');
        $('tr.has-quantity').addClass('stock-checking');

        // Prepare data for AJAX check
        const checkData = {
            start_date: '<?= $start_date ?>',
            end_date: '<?= $end_date ?>',
            mode: '<?= $mode ?>',
            comp_id: '<?= $comp_id ?>',
            quantities: allSessionQuantities
        };

        $.ajax({
            url: 'check_stock_availability.php',
            type: 'POST',
            data: JSON.stringify(checkData),
            contentType: 'application/json',
            success: function(response) {
                $('#generateBillsBtn').prop('disabled', false).removeClass('btn-loading');
                $('tr.has-quantity').removeClass('stock-checking');
                
                try {
                    const result = JSON.parse(response);
                    if (result.success) {
                        resolve(true);
                    } else {
                        showClientValidationAlert(result.message);
                        reject(result.message);
                    }
                } catch (e) {
                    showClientValidationAlert('Error checking stock availability. Please try again.');
                    reject('Error checking stock availability.');
                }
            },
            error: function() {
                $('#generateBillsBtn').prop('disabled', false).removeClass('btn-loading');
                $('tr.has-quantity').removeClass('stock-checking');
                showClientValidationAlert('Error connecting to server. Please try again.');
                reject('Connection error');
            }
        });
    });
}

// NEW: Function to show client-side validation alert
function showClientValidationAlert(message) {
    $('#validationMessage').text(message);
    $('#clientValidationAlert').fadeIn();
    
    // Auto-hide after 10 seconds
    setTimeout(() => {
        $('#clientValidationAlert').fadeOut();
    }, 10000);
}

// Function to clear session quantities via AJAX
function clearSessionQuantities() {
    // Check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot clear quantities.');
        return;
    }
    
    $.ajax({
        url: 'clear_session_quantities.php',
        type: 'POST',
        success: function(response) {
            console.log('Session quantities cleared');
            // Reload the page to reflect changes
            location.reload();
        },
        error: function() {
            console.log('Error clearing session quantities');
            alert('Error clearing quantities. Please try again.');
        }
    });
}

// Enhanced quantity validation function with date availability check
function validateQuantity(input) {
    // Check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot update quantities.');
        $(input).val(0);
        return false;
    }
    
    const itemCode = $(input).data('code');
    const currentStock = parseFloat($(input).data('stock'));
    let enteredQty = parseInt($(input).val()) || 0;
    const availableCount = parseInt($(input).data('available-count') || 0);
    
    // NEW: Check if item has available dates
    if (availableCount === 0 && enteredQty > 0) {
        alert('This item has no available dates for sale. Please check date availability.');
        $(input).val(0);
        enteredQty = 0;
    }
    
    // Validate input
    if (isNaN(enteredQty) || enteredQty < 0) {
        enteredQty = 0;
        $(input).val(0);
    }
    
    // Prevent exceeding stock with better feedback
    if (enteredQty > currentStock) {
        const maxAllowed = Math.floor(currentStock);
        enteredQty = maxAllowed;
        $(input).val(maxAllowed);
        
        // Show warning but don't prevent operation
        $(input).addClass('is-invalid');
        setTimeout(() => $(input).removeClass('is-invalid'), 2000);
    } else {
        $(input).removeClass('is-invalid');
    }
    
    // Update UI immediately
    updateItemUI(itemCode, enteredQty, currentStock);
    
    // Save to session via AJAX to prevent data loss
    saveQuantityToSession(itemCode, enteredQty);
    
    return true;
}

// New function to update all UI elements for an item
function updateItemUI(itemCode, qty, currentStock) {
    const rate = parseFloat($(`input[name="sale_qty[${itemCode}]"]`).data('rate'));
    const closingBalance = currentStock - qty;
    const amount = qty * rate;
    
    // Update all related UI elements
    $(`#closing_${itemCode}`).text(closingBalance.toFixed(3));
    $(`#amount_${itemCode}`).text(amount.toFixed(2));
    
    // Update row styling
    const row = $(`input[name="sale_qty[${itemCode}]"]`).closest('tr');
    row.toggleClass('has-quantity', qty > 0);
    
    // Update closing balance styling
    const closingCell = $(`#closing_${itemCode}`);
    closingCell.removeClass('text-warning text-danger fw-bold');
    
    if (closingBalance < 0) {
        closingCell.addClass('text-danger fw-bold');
    } else if (closingBalance < (currentStock * 0.1)) {
        closingCell.addClass('text-warning fw-bold');
    }
}

// New function to save quantity to session via AJAX
function saveQuantityToSession(itemCode, qty) {
    // Check if table exists
    if (!tableExists) return;
    
    // Debounce to prevent too many requests
    if (typeof saveQuantityToSession.debounce === 'undefined') {
        saveQuantityToSession.debounce = null;
    }
    
    clearTimeout(saveQuantityToSession.debounce);
    saveQuantityToSession.debounce = setTimeout(() => {
        $.ajax({
            url: 'update_session_quantity.php',
            type: 'POST',
            data: {
                item_code: itemCode,
                quantity: qty
            },
            success: function(response) {
                console.log('Quantity saved to session:', itemCode, qty);
                // Update global session quantities object
                allSessionQuantities[itemCode] = qty;
            },
            error: function() {
                console.error('Failed to save quantity to session');
            }
        });
    }, 200);
}

// Function to validate all quantities before form submission
function validateAllQuantities() {
    // Check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot generate bills.');
        return false;
    }
    
    let isValid = true;
    let errorItems = [];
    
    // Validate ONLY session quantities > 0 (optimization)
    for (const itemCode in allSessionQuantities) {
        const qty = allSessionQuantities[itemCode];
        if (qty > 0) {
            // Find the stock data from the input field or use a default
            const inputField = $(`input[name="sale_qty[${itemCode}]"]`);
            let currentStock;
            
            if (inputField.length > 0) {
                currentStock = parseFloat(inputField.data('stock'));
            } else {
                // If item not in current view, get from allItemsData
                if (allItemsData[itemCode]) {
                    currentStock = parseFloat(allItemsData[itemCode].CURRENT_STOCK);
                } else {
                    continue; // Skip if we can't validate
                }
            }
            
            const closingBalance = currentStock - qty;
            
            if (closingBalance < 0) {
                isValid = false;
                errorItems.push({
                    code: itemCode,
                    stock: currentStock,
                    qty: qty
                });
            }
            
            // NEW: Check date availability
            const availability = itemDateAvailability[itemCode];
            if (!availability || availability.available_count === 0) {
                isValid = false;
                errorItems.push({
                    code: itemCode,
                    error: 'No available dates for sale'
                });
            }
        }
    }
    
    if (!isValid) {
        let errorMessage = "The following items have issues:\n\n";
        errorItems.forEach(item => {
            if (item.stock !== undefined) {
                errorMessage += `• Item ${item.code}: Insufficient stock (Available: ${item.stock.toFixed(3)}, Requested: ${item.qty})\n`;
            } else {
                errorMessage += `• Item ${item.code}: ${item.error}\n`;
            }
        });
        errorMessage += "\nPlease adjust quantities to resolve these issues.";
        alert(errorMessage);
    }
    
    return isValid;
}

// NEW: Function to distribute sales only across available dates (client-side version)
function distributeSalesAcrossAvailableDates(total_qty, date_array, available_dates) {
    if (total_qty <= 0) {
        return Object.fromEntries(date_array.map(date => [date, 0]));
    }
    
    // Filter to get only available dates from the date array
    const available_dates_in_range = date_array.filter(date => available_dates[date] === true);
    
    if (available_dates_in_range.length === 0) {
        // If no dates available, return all zeros
        return Object.fromEntries(date_array.map(date => [date, 0]));
    }
    
    const available_count = available_dates_in_range.length;
    const base_qty = Math.floor(total_qty / available_count);
    const remainder = total_qty % available_count;
    
    // Initialize distribution with zeros for all dates
    const distribution = {};
    date_array.forEach(date => {
        distribution[date] = 0;
    });
    
    // Distribute base quantity to available dates
    available_dates_in_range.forEach(date => {
        distribution[date] = base_qty;
    });
    
    // Distribute remainder to first few available dates
    for (let i = 0; i < remainder; i++) {
        distribution[available_dates_in_range[i]]++;
    }
    
    // Shuffle the distribution among available dates
    const shuffled_available_dates = [...available_dates_in_range];
    for (let i = shuffled_available_dates.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [shuffled_available_dates[i], shuffled_available_dates[j]] = [shuffled_available_dates[j], shuffled_available_dates[i]];
    }
    
    // Create shuffled distribution
    const shuffled_distribution = {};
    date_array.forEach(date => {
        shuffled_distribution[date] = 0;
    });
    
    const available_values = shuffled_available_dates.map(date => distribution[date]);
    
    // Assign shuffled values back to available dates
    shuffled_available_dates.forEach((date, index) => {
        shuffled_distribution[date] = available_values[index];
    });
    
    return shuffled_distribution;
}

// OPTIMIZED: Function to update the distribution preview ONLY for items with qty > 0
function updateDistributionPreview(itemCode, totalQty) {
    // Check if table exists
    if (!tableExists) return;
    
    if (totalQty <= 0) {
        // Remove distribution cells if quantity is 0
        $(`input[name="sale_qty[${itemCode}]"]`).closest('tr').find('.date-distribution-cell').remove();
        return;
    }
    
    // NEW: Get date availability for this item
    const availability = itemDateAvailability[itemCode];
    if (!availability || availability.available_count === 0) {
        alert(`Item ${itemCode} has no available dates for sale.`);
        $(`input[name="sale_qty[${itemCode}]"]`).val(0);
        return;
    }
    
    // NEW: Use date availability for distribution
    const dailySales = distributeSalesAcrossAvailableDates(totalQty, dateArray, availability.available_dates);
    const rate = parseFloat($(`input[name="sale_qty[${itemCode}]"]`).data('rate'));
    const itemRow = $(`input[name="sale_qty[${itemCode}]"]`).closest('tr');
    
    // Remove any existing distribution cells
    itemRow.find('.date-distribution-cell').remove();
    
    // Add date distribution cells after the action column
    let totalDistributed = 0;
    dateArray.forEach((date, index) => {
        const qty = dailySales[date] || 0;
        totalDistributed += qty;
        
        // Determine cell class based on availability and quantity
        let cellClass = 'date-distribution-cell';
        let cellContent = qty;
        let cellTitle = date;
        
        if (availability.available_dates[date]) {
            if (qty > 0) {
                cellClass += ' date-available';
                cellTitle += '\nAvailable date: ' + qty + ' units';
            } else {
                cellClass += ' date-available-zero';
                cellTitle += '\nAvailable date: 0 units';
            }
        } else {
            cellClass += ' date-blocked';
            cellContent = '<i class="fas fa-ban date-blocked-icon"></i> Blocked';
            cellTitle += '\n' + (availability.blocked_dates[date] || 'Not available');
        }
        
        // Create cell with tooltip
        const cell = $(`<td class="${cellClass}" title="${cellTitle}">${cellContent}</td>`);
        cell.insertAfter(itemRow.find('.action-column').eq(index));
    });
    
    // Update closing balance
    const currentStock = parseFloat($(`input[name="sale_qty[${itemCode}]"]`).data('stock'));
    const closingBalance = currentStock - totalDistributed;
    $(`#closing_${itemCode}`).text(closingBalance.toFixed(3));
    
    // Update amount
    const amount = totalDistributed * rate;
    $(`#amount_${itemCode}`).text(amount.toFixed(2));
    
    // Show date columns if they're hidden
    $('.date-header, .date-distribution-cell').show();
    
    return dailySales;
}

// Function to calculate total amount
function calculateTotalAmount() {
    let total = 0;
    $('.amount-cell').each(function() {
        total += parseFloat($(this).text()) || 0;
    });
    $('#totalAmount').text(total.toFixed(2));
}

// Function to initialize date headers and closing balance column
function initializeTableHeaders() {
    // Remove existing date headers if any
    $('.date-header').remove();
    
    // Add date headers after the action column header
    dateArray.forEach(date => {
        const dateObj = new Date(date);
        const day = dateObj.getDate();
        const month = dateObj.toLocaleString('default', { month: 'short' });
        
        // Insert date headers after the action column header
        $(`<th class="date-header" title="${date}" style="display: none;">${day}<br>${month}</th>`).insertAfter($('.table-header tr th.action-column'));
    });
}

// Function to handle row navigation with arrow keys
function setupRowNavigation() {
    const qtyInputs = $('input.qty-input');
    let currentRowIndex = -1;
    
    // Highlight row when input is focused
    $(document).on('focus', 'input.qty-input', function() {
        // Remove highlight from all rows
        $('tr').removeClass('highlight-row');
        
        // Add highlight to current row
        $(this).closest('tr').addClass('highlight-row');
        
        // Update current row index
        currentRowIndex = qtyInputs.index(this);
    });
    
    // Handle arrow key navigation
    $(document).on('keydown', 'input.qty-input', function(e) {
        // Only handle arrow keys
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        
        e.preventDefault(); // Prevent default scrolling behavior
        
        // Calculate new row index
        let newIndex;
        if (e.key === 'ArrowUp') {
            newIndex = currentRowIndex - 1;
        } else { // ArrowDown
            newIndex = currentRowIndex + 1;
        }
        
        // Check if new index is valid
        if (newIndex >= 0 && newIndex < qtyInputs.length) {
            // Focus the input in the new row
            $(qtyInputs[newIndex]).focus().select();
        }
    });
}

// UPDATED: Function to generate bills immediately with client-side validation
function generateBills() {
    // First check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot generate bills.');
        return false;
    }
    
    // Then validate basic quantities including date availability
    if (!validateAllQuantities()) {
        return false;
    }
    
    // Then check stock availability via AJAX
    checkStockAvailabilityBeforeSubmit()
        .then(() => {
            // If validation passes, submit the form
            $('#ajaxLoader').show();
            document.getElementById('salesForm').submit();
        })
        .catch((error) => {
            // Validation failed, don't submit
            console.log('Client-side validation failed:', error);
        });
}

// Function to save to pending sales via AJAX
function saveToPendingSales() {
    // First check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot save to pending.');
        return false;
    }
    
    // Then validate basic quantities
    if (!validateAllQuantities()) {
        return false;
    }
    
    // Then check stock availability via AJAX
    checkStockAvailabilityBeforeSubmit()
        .then(() => {
            // Show loader and disable button
            $('#ajaxLoader').show();
            $('#generateBillsBtn').prop('disabled', true).addClass('btn-loading');
            
            // Collect all the data
            const formData = new FormData();
            formData.append('save_pending', 'true');
            formData.append('start_date', '<?= $start_date ?>');
            formData.append('end_date', '<?= $end_date ?>');
            formData.append('mode', '<?= $mode ?>');
            
            // Add each item's quantity from session (not just visible ones)
            for (const itemCode in allSessionQuantities) {
                const qty = allSessionQuantities[itemCode];
                if (qty > 0) {
                    formData.append(`sale_qty[${itemCode}]`, qty);
                }
            }
            
            // Send AJAX request
            $.ajax({
                url: 'save_pending_sales.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    $('#ajaxLoader').hide();
                    $('#generateBillsBtn').prop('disabled', false).removeClass('btn-loading');
                    
                    try {
                        const result = JSON.parse(response);
                        if (result.success) {
                            // Clear session quantities
                            clearSessionQuantities();
                            alert('Sales data saved to pending successfully! You can generate bills later from the "Post Daily Sales" page.');
                            window.location.href = 'retail_sale.php?success=' + encodeURIComponent(result.message);
                        } else {
                            alert('Error: ' + result.message);
                        }
                    } catch (e) {
                        alert('Error processing response: ' + response);
                    }
                },
                error: function() {
                    $('#ajaxLoader').hide();
                    $('#generateBillsBtn').prop('disabled', false).removeClass('btn-loading');
                    alert('Error saving data to pending. Please try again.');
                }
            });
        })
        .catch((error) => {
            // Validation failed, don't proceed
            console.log('Client-side validation failed for pending sales:', error);
        });
}

// Single button with dual functionality
function handleGenerateBills() {
    // First check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Please select a different date range.');
        return false;
    }
    
    // Check if we have any quantities > 0 (optimized check)
    let hasQuantity = false;
    for (const itemCode in allSessionQuantities) {
        if (allSessionQuantities[itemCode] > 0) {
            hasQuantity = true;
            break;
        }
    }
    
    if (!hasQuantity) {
        alert('Please enter quantities for at least one item.');
        return false;
    }
    
    // NEW: Check date availability for all items with quantities
    let dateAvailabilityWarnings = [];
    for (const itemCode in allSessionQuantities) {
        const qty = allSessionQuantities[itemCode];
        if (qty > 0) {
            const availability = itemDateAvailability[itemCode];
            if (!availability || availability.available_count === 0) {
                dateAvailabilityWarnings.push(`Item ${itemCode} has no available dates`);
            }
        }
    }
    
    if (dateAvailabilityWarnings.length > 0) {
        alert('Cannot proceed: Some items have no available dates:\n\n' + 
              dateAvailabilityWarnings.slice(0, 5).join('\n') + 
              (dateAvailabilityWarnings.length > 5 ? '\n... and ' + (dateAvailabilityWarnings.length - 5) + ' more' : ''));
        return false;
    }
    
    // Show confirmation dialog with two options
    const userChoice = confirm(
        "Generate Bills Options:\n\n" +
        "Click OK to generate bills immediately (will update stock and create actual sales).\n\n" +
        "Click Cancel to save to pending sales (will save for later processing, no stock update)."
    );
    
    if (userChoice === true) {
        // User clicked OK - Generate bills immediately
        generateBills();
    } else {
        // User clicked Cancel - Save to pending sales
        saveToPendingSales();
    }
}

// Function to load sales log content
function loadSalesLog() {
    // Show loading state
    $('#salesLogContent').html(`
        <div class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading sales log...</p>
        </div>
    `);
    
    // Load sales log content via AJAX
    $.ajax({
        url: 'sales_log_ajax.php',
        type: 'GET',
        dataType: 'html',
        success: function(response) {
            $('#salesLogContent').html(response);
        },
        error: function() {
            $('#salesLogContent').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load sales log. Please try again.
                </div>
            `);
        }
    });
}

// Function to print sales log
function printSalesLog() {
    const printContent = $('#salesLogContent').html();
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Sales Log - Foreign Export</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f8f9fa; }
                .text-center { text-align: center; }
                .no-print { display: none; }
            </style>
        </head>
        <body>
            <h2 class="text-center">Sales Log - Foreign Export</h2>
            ${printContent}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
}

// Function to get item data from ALL items data (not just visible rows)
function getItemData(itemCode) {
    // First try to get from visible row
    const inputField = $(`input[name="sale_qty[${itemCode}]"]`);
    if (inputField.length > 0) {
        const itemRow = inputField.closest('tr');
        return {
            classCode: itemRow.data('class'),
            details: itemRow.data('details'),
            details2: itemRow.data('details2'),
            quantity: parseInt(inputField.val()) || 0
        };
    } else {
        // If not in current view, get from allItemsData (now includes all modes)
        if (allItemsData[itemCode]) {
            return {
                classCode: allItemsData[itemCode].CLASS,
                details: allItemsData[itemCode].DETAILS,
                details2: allItemsData[itemCode].DETAILS2,
                quantity: allSessionQuantities[itemCode] || 0
            };
        }
    }
    return null;
}

// UPDATED: Function to classify product type from class code - ADDED COUNTRY LIQUOR
function getProductType(classCode) {
    const spirits = ['W', 'G', 'D', 'K', 'R', 'O'];
    if (spirits.includes(classCode)) return 'SPIRITS';
    if (classCode === 'V') return 'WINE';
    if (classCode === 'F') return 'FERMENTED BEER';
    if (classCode === 'M') return 'MILD BEER';
    if (classCode === 'L') return 'COUNTRY LIQUOR'; // ADDED COUNTRY LIQUOR
    return 'OTHER';
}

// Function to extract volume from details
function extractVolume(details, details2) {
    // Priority: details2 column first
    if (details2) {
        // Handle liter sizes with decimal points (1.5L, 2.0L, etc.)
        const literMatch = details2.match(/(\d+\.?\d*)\s*L\b/i);
        if (literMatch) {
            let volume = parseFloat(literMatch[1]);
            return Math.round(volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        const mlMatch = details2.match(/(\d+)\s*ML\b/i);
        if (mlMatch) {
            return parseInt(mlMatch[1]);
        }
    }
    
    // Fallback: parse details column
    if (details) {
        // Handle special cases
        if (details.includes('QUART')) return 750;
        if (details.includes('PINT')) return 375;
        if (details.includes('NIP')) return 90;
        
        // Handle liter sizes with decimal points
        const literMatch = details.match(/(\d+\.?\d*)\s*L\b/i);
        if (literMatch) {
            let volume = parseFloat(literMatch[1]);
            return Math.round(volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        const mlMatch = details.match(/(\d+)\s*ML\b/i);
        if (mlMatch) {
            return parseInt(mlMatch[1]);
        }
    }
    
    return 0; // Unknown volume
}

// Function to map volume to column - UPDATED WITH ALL SIZES
function getVolumeColumn(volume) {
    const volumeMap = {
        // ML sizes
        50: '50 ML',
        60: '60 ML', 
        90: '90 ML',
        170: '170 ML',
        180: '180 ML',
        200: '200 ML',
        250: '250 ML',
        275: '275 ML',
        330: '330 ML',
        355: '355 ML',
        375: '375 ML',
        500: '500 ML',
        650: '650 ML',
        700: '700 ML',
        750: '750 ML',
        1000: '1000 ML',
        
        // Liter sizes (converted to ML for consistency)
        1500: '1.5L',    // 1.5L = 1500ML
        1750: '1.75L',   // 1.75L = 1750ML
        2000: '2L',      // 2L = 2000ML
        3000: '3L',      // 3L = 3000ML
        4500: '4.5L',    // 4.5L = 4500ML
        15000: '15L',    // 15L = 15000ML
        20000: '20L',    // 20L = 20000ML
        30000: '30L',    // 30L = 30000ML
        50000: '50L'     // 50L = 50000ML
    };
    
    return volumeMap[volume] || null;
}

// UPDATED: Function to update total sales module - PROCESS ALL ITEMS FROM ALL MODES
function updateTotalSalesModule() {
    console.log('updateTotalSalesModule called - Processing ALL items from ALL modes');
    
    // Check if table exists
    if (!tableExists) {
        console.log('Table does not exist, skipping total sales update');
        return;
    }
    
    // Initialize empty summary object with ALL sizes
    const allSizes = [
        '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML', 
        '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
        '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
    ];
    
    // UPDATED: Added COUNTRY LIQUOR category in the requested order
    const salesSummary = {
        'SPIRITS': {},
        'WINE': {},
        'FERMENTED BEER': {},
        'MILD BEER': {},
        'COUNTRY LIQUOR': {} // ADDED COUNTRY LIQUOR AT THE END
    };
    
    // Initialize all sizes to 0 for each category
    Object.keys(salesSummary).forEach(category => {
        allSizes.forEach(size => {
            salesSummary[category][size] = 0;
        });
    });

    console.log('Processing ALL session quantities from ALL modes:', allSessionQuantities);

    // Process ALL session quantities > 0 (from ALL modes)
    let processedItems = 0;
    for (const itemCode in allSessionQuantities) {
        const quantity = allSessionQuantities[itemCode];
        if (quantity > 0) {
            // Get item data from ALL items data (works for items from all modes)
            const itemData = getItemData(itemCode);
            if (itemData) {
                const productType = getProductType(itemData.classCode);
                const volume = extractVolume(itemData.details, itemData.details2);
                const volumeColumn = getVolumeColumn(volume);
                
                console.log(`Item ${itemCode}: Class=${itemData.classCode}, ProductType=${productType}, Volume=${volume}, VolumeColumn=${volumeColumn}, Quantity=${quantity}`);
                
                if (volumeColumn && salesSummary[productType]) {
                    salesSummary[productType][volumeColumn] += quantity;
                    processedItems++;
                }
            }
        }
    }

    console.log(`Processed ${processedItems} items with quantities from ALL modes`);
    console.log('Final sales summary:', salesSummary);

    // Update the modal table
    updateSalesModalTable(salesSummary, allSizes);
}

// UPDATED: Function to update modal table with calculated values - ADDED COUNTRY LIQUOR ROW
function updateSalesModalTable(salesSummary, allSizes) {
    const tbody = $('#totalSalesTable tbody');
    tbody.empty();
    
    console.log('Updating modal table with categories:', Object.keys(salesSummary));
    
    // UPDATED: Added COUNTRY LIQUOR in the requested order
    const categories = ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER', 'COUNTRY LIQUOR'];
    
    categories.forEach(category => {
        const row = $('<tr>');
        row.append($('<td>').text(category));
        
        allSizes.forEach(size => {
            const value = salesSummary[category] ? (salesSummary[category][size] || 0) : 0;
            const cell = $('<td>').text(value > 0 ? value : '');
            
            // Add subtle highlighting for non-zero values
            if (value > 0) {
                cell.addClass('table-success');
            }
            
            row.append(cell);
        });
        
        tbody.append(row);
    });
    
    console.log('Modal table updated successfully with data from ALL modes');
}

// Print function
function printSalesSummary() {
    const printContent = document.getElementById('totalSalesModuleContainer').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = printContent;
    window.print();
    document.body.innerHTML = originalContent;
    
    // Re-initialize any necessary scripts
    location.reload();
}

// OPTIMIZED: Document ready - Only process items with quantities > 0
$(document).ready(function() {
    console.log('Document ready - Initializing...');
    console.log('Date availability data:', itemDateAvailability);
    
    // Check if table exists
    if (!tableExists) {
        console.log('Daily stock table not found, disabling functionality');
        return;
    }
    
    // Initialize table headers and columns
    initializeTableHeaders();
    
    // Set up row navigation with arrow keys
    setupRowNavigation();
    
    // Initialize quantities in visible inputs from session
    initializeQuantitiesFromSession();
    
    // Clear session button click event
    $('#clearSessionBtn').click(function() {
        if (confirm('Are you sure you want to clear all quantities? This action cannot be undone.')) {
            clearSessionQuantities();
        }
    });
    
    // Single button with dual functionality
    $('#generateBillsBtn').click(function(e) {
        e.preventDefault();
        handleGenerateBills();
    });
    
    // OPTIMIZED: Event delegation with debouncing
    let quantityTimeout;
    $(document).off('input', 'input.qty-input').on('input', 'input.qty-input', function(e) {
        clearTimeout(quantityTimeout);
        quantityTimeout = setTimeout(() => {
            validateQuantity(this);
        }, 200);
    });
    
    // Quantity input change event
    $(document).on('change', 'input[name^="sale_qty"]', function() {
        // First validate the quantity
        if (!validateQuantity(this)) {
            return; // Stop if validation fails
        }
        
        const itemCode = $(this).data('code');
        const totalQty = parseInt($(this).val()) || 0;
        
        // Only update distribution if quantity > 0 (optimization)
        if (totalQty > 0) {
            updateDistributionPreview(itemCode, totalQty);
        } else {
            // Remove distribution cells if quantity is 0
            $(`input[name="sale_qty[${itemCode}]"]`).closest('tr').find('.date-distribution-cell').remove();
            
            // Reset closing balance and amount
            const currentStock = parseFloat($(this).data('stock'));
            $(`#closing_${itemCode}`).text(currentStock.toFixed(3));
            $(`#amount_${itemCode}`).text('0.00');
            
            // Hide date columns if no items have quantity
            if ($('input[name^="sale_qty"]').filter(function() { 
                return parseInt($(this).val()) > 0; 
            }).length === 0) {
                $('.date-header, .date-distribution-cell').hide();
            }
        }

        // Also update total sales module if modal is open
        if ($('#totalSalesModal').hasClass('show')) {
            console.log('Modal is open, updating total sales module with ALL modes data...');
            updateTotalSalesModule();
        }
        
        // Update total amount
        calculateTotalAmount();
    });
    
    // OPTIMIZED: Shuffle all button click event - Only shuffle items with qty > 0
    $('#shuffleBtn').off('click').on('click', function() {
        $('input.qty-input').each(function() {
            const itemCode = $(this).data('code');
            const totalQty = parseInt($(this).val()) || 0;
            
            // Only shuffle if quantity > 0 and visible (optimization)
            if (totalQty > 0 && $(this).is(':visible')) {
                updateDistributionPreview(itemCode, totalQty);
            }
        });
        
        // Update total amount
        calculateTotalAmount();
    });
    
    // Individual shuffle button click event
    $(document).on('click', '.btn-shuffle-item', function() {
        const itemCode = $(this).data('code');
        const totalQty = parseInt($(`input[name="sale_qty[${itemCode}]"]`).val()) || 0;
        
        // Only shuffle if quantity > 0
        if (totalQty > 0) {
            updateDistributionPreview(itemCode, totalQty);
            
            // Update total amount
            calculateTotalAmount();
        }
    });
    
    // Auto-load sales log when modal is shown
    $('#salesLogModal').on('shown.bs.modal', function() {
        loadSalesLog();
    });
    
    // Update total sales module when modal is shown - FIXED: Use 'show.bs.modal' instead of 'shown.bs.modal'
    $('#totalSalesModal').on('show.bs.modal', function() {
        console.log('Total Sales Modal opened - updating data from ALL modes...');
        updateTotalSalesModule();
    });
    
    // Also update when modal is already shown but data changes
    $('#totalSalesModal').on('shown.bs.modal', function() {
        console.log('Total Sales Modal shown - refreshing data from ALL modes...');
        updateTotalSalesModule();
    });
});

// NEW FUNCTION: Initialize input values from session on page load
function initializeQuantitiesFromSession() {
    $('input[name^="sale_qty"]').each(function() {
        const itemCode = $(this).data('code');
        if (allSessionQuantities[itemCode] !== undefined) {
            const sessionQty = allSessionQuantities[itemCode];
            $(this).val(sessionQty);
            
            // Update UI for this item
            const currentStock = parseFloat($(this).data('stock'));
            updateItemUI(itemCode, sessionQty, currentStock);
            
            // Show distribution if quantity > 0 (optimization)
            if (sessionQty > 0) {
                updateDistributionPreview(itemCode, sessionQty);
            }
        }
    });
    
    // Show date headers if any items have quantities > 0
    const hasQuantities = Object.values(allSessionQuantities).some(qty => qty > 0);
    if (hasQuantities) {
        $('.date-header').show();
    }
}
</script>
</body>
</html>