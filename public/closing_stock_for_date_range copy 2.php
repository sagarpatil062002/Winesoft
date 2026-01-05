<?php
session_start();
require_once 'drydays_functions.php'; // Single include
require_once 'license_functions.php'; // ADDED: Include license 
require_once 'cash_memo_functions.php'; // ADDED: Include cash memo functions

// ============================================================================
// PERFORMANCE OPTIMIZATION - PREVENT TIMEOUT FOR LARGE OPERATIONS
// ============================================================================
set_time_limit(0); // No time limit
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M'); // 1GB memory

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/closing_' . date('Y-m-d') . '.log';
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

// Function to log bill generation step with detailed information
function logBillGenerationStep($step, $details = [], $level = 'INFO') {
    $logMessage = "=== BILL GENERATION STEP: $step ===" . PHP_EOL;
    
    if (!empty($details)) {
        foreach ($details as $key => $value) {
            if (is_array($value)) {
                $logMessage .= "$key: " . json_encode($value) . PHP_EOL;
            } else {
                $logMessage .= "$key: $value" . PHP_EOL;
            }
        }
    }
    
    logMessage($logMessage, $level);
}

// OPTIMIZATION: Reduce logging frequency - only log errors and important events
// logMessage("=== PAGE ACCESS ===");
// logMessage("Request method: " . $_SERVER['REQUEST_METHOD']);
// logMessage("Search term: '" . ($_GET['search'] ?? '') . "'");
// logMessage("Current session ID: " . session_id());

// Function to clear session quantities
function clearSessionQuantities() {
    if (isset($_SESSION['sale_quantities'])) {
        unset($_SESSION['sale_quantities']);
        logMessage("Session quantities cleared");
    }
}

// ============================================================================
// NEW: DATE AVAILABILITY FUNCTIONS
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

// NEW: Check if isDryDay function exists, provide fallback
if (!function_exists('isDryDay')) {
    function isDryDay($date, $conn, $comp_id = null) {
        try {
            $formatted_date = date('Y-m-d', strtotime($date));
            
            $query = "SELECT COUNT(*) as count FROM tbldrydays WHERE DRY_DATE = ?";
            $params = [$formatted_date];
            $types = "s";
            
            // Use session CompID if not provided
            if ($comp_id === null && isset($_SESSION['CompID'])) {
                $comp_id = $_SESSION['CompID'];
            }
            
            if ($comp_id) {
                $query .= " AND COMP_ID = ?";
                $params[] = $comp_id;
                $types .= "i";
            }
            
            $stmt = $conn->prepare($query);
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $is_dry = $row['count'] > 0;
                $stmt->close();
                return $is_dry;
            }
            
            $stmt->close();
            return false;
            
        } catch (Exception $e) {
            error_log("Error in isDryDay: " . $e->getMessage());
            return false;
        }
    }
}

// NEW: Function to check item date availability based on latest sale date
function getItemDateAvailability($conn, $item_code, $start_date, $end_date, $comp_id) {
    $available_dates = [];
    $dates = getDatesBetween($start_date, $end_date);
    
    // Get the latest sale date for this item
    $latest_sale_query = "SELECT MAX(BILL_DATE) as latest_sale_date 
                         FROM tblsaleheader sh
                         JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO
                         WHERE sd.ITEM_CODE = ? AND sh.COMP_ID = ?";
    $latest_sale_stmt = $conn->prepare($latest_sale_query);
    $latest_sale_stmt->bind_param("si", $item_code, $comp_id);
    $latest_sale_stmt->execute();
    $latest_sale_result = $latest_sale_stmt->get_result();
    
    $latest_sale_date = null;
    if ($latest_sale_result->num_rows > 0) {
        $row = $latest_sale_result->fetch_assoc();
        $latest_sale_date = $row['latest_sale_date'];
    }
    $latest_sale_stmt->close();
    
    foreach ($dates as $date) {
        $is_available = true;
        
        // Rule 1: Check if date is after latest sale date
        if ($latest_sale_date && $date <= $latest_sale_date) {
            $is_available = false;
        }
        
        // Rule 2: Check if it's a dry day (with fallback if function doesn't exist)
        if (function_exists('isDryDay')) {
            if (isDryDay($date, $conn, $comp_id)) {
                $is_available = false;
            }
        } else {
            // Fallback: Check dry days table directly
            $dry_day_query = "SELECT COUNT(*) as count FROM tbldrydays 
                             WHERE DRY_DATE = ? AND COMP_ID = ?";
            $dry_day_stmt = $conn->prepare($dry_day_query);
            $dry_day_stmt->bind_param("si", $date, $comp_id);
            $dry_day_stmt->execute();
            $dry_day_result = $dry_day_stmt->get_result();
            
            if ($dry_day_result->num_rows > 0) {
                $dry_row = $dry_day_result->fetch_assoc();
                if ($dry_row['count'] > 0) {
                    $is_available = false;
                }
            }
            $dry_day_stmt->close();
        }
        
        // Rule 3: Check if date is in the future (should already be prevented)
        if ($date > date('Y-m-d')) {
            $is_available = false;
        }
        
        $available_dates[$date] = $is_available;
    }
    
    return $available_dates;
}

// NEW: Function to distribute sales across available dates only
function distributeSalesAcrossAvailableDates($total_qty, $dates, $date_availability) {
    // Filter available dates
    $available_dates = [];
    foreach ($dates as $date) {
        if ($date_availability[$date]) {
            $available_dates[] = $date;
        }
    }
    
    $available_days_count = count($available_dates);
    
    if ($available_days_count == 0 || $total_qty <= 0) {
        // Return array with all dates as 0
        $distribution = [];
        foreach ($dates as $date) {
            $distribution[$date] = 0;
        }
        return $distribution;
    }
    
    // Calculate base distribution
    $base_qty = floor($total_qty / $available_days_count);
    $remainder = $total_qty % $available_days_count;
    
    // Initialize distribution
    $distribution = [];
    foreach ($dates as $date) {
        if ($date_availability[$date]) {
            $distribution[$date] = $base_qty;
        } else {
            $distribution[$date] = 0;
        }
    }
    
    // Distribute remainder across available dates
    $available_date_keys = array_keys(array_filter($date_availability));
    for ($i = 0; $i < $remainder; $i++) {
        $date_index = $i % $available_days_count;
        $date = $available_date_keys[$date_index];
        $distribution[$date]++;
    }
    
    return $distribution;
}

// NEW: Function to get the correct daily stock table name
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

// NEW: Date-wise stock validation function
function validateDateWiseStock($conn, $item_code, $sale_date, $qty, $comp_id) {
    if ($qty <= 0) return true;
    
    // Determine the correct table name based on sale date
    $current_month = date('Y-m');
    $sale_month = date('Y-m', strtotime($sale_date));
    
    if ($sale_month === $current_month) {
        $daily_stock_table = "tbldailystock_" . $comp_id;
    } else {
        $sale_month_year = date('m_y', strtotime($sale_date));
        $daily_stock_table = "tbldailystock_" . $comp_id . "_" . $sale_month_year;
    }
    
    // Check if table exists
    $check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
    $table_result = $conn->query($check_table_query);
    
    if ($table_result->num_rows == 0) {
        logMessage("Stock table '$daily_stock_table' not found for date $sale_date", 'WARNING');
        return true; // If table doesn't exist, assume stock is available
    }
    
    // Extract day number
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $closing_column = "DAY_{$day_num}_CLOSING";
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    $sales_column = "DAY_{$day_num}_SALES";
    
    $month_year_full = date('Y-m', strtotime($sale_date));
    
    // Check if record exists
    $check_query = "SELECT $closing_column, $opening_column, $purchase_column, $sales_column 
                    FROM $daily_stock_table 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $month_year_full, $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $check_stmt->close();
        logMessage("No stock record found for item $item_code on date $sale_date", 'WARNING');
        return true; // If no record, assume stock is available
    }
    
    $current_values = $check_result->fetch_assoc();
    $check_stmt->close();
    
    $current_closing = $current_values[$closing_column] ?? 0;
    $current_opening = $current_values[$opening_column] ?? 0;
    $current_purchase = $current_values[$purchase_column] ?? 0;
    $current_sales = $current_values[$sales_column] ?? 0;
    
    // Calculate available stock for this date
    $available_stock = $current_opening + $current_purchase - $current_sales;
    
    if ($qty > $available_stock) {
        logMessage("Date-wise stock validation failed for item $item_code on $sale_date: Available: $available_stock, Requested: $qty", 'WARNING');
        return false;
    }
    
    return true;
}

// NEW: Function to validate all date-wise stocks before transaction
function validateAllDateWiseStocks($conn, $daily_sales_data, $comp_id) {
    $stock_errors = [];
    
    foreach ($daily_sales_data as $item_code => $daily_sales) {
        foreach ($daily_sales as $date_index => $qty) {
            if ($qty > 0) {
                $sale_date = $GLOBALS['date_array'][$date_index];
                if (!validateDateWiseStock($conn, $item_code, $sale_date, $qty, $comp_id)) {
                    $stock_errors[] = "Item $item_code: Insufficient stock on $sale_date (Requested: $qty)";
                }
            }
        }
    }
    
    return $stock_errors;
}

// ============================================================================
// VOLUME-BASED BILL GENERATION FUNCTIONS (FROM sale_for_date_range.php)
// ============================================================================

// Function to extract bottle size from item details
function extractBottleSize($details, $details2) {
    // Priority: details2 column first
    if ($details2) {
        // Handle liter sizes with decimal points (1.5L, 2.0L, etc.)
        $literMatch = preg_match('/(\d+\.?\d*)\s*L\b/i', $details2, $matches);
        if ($literMatch) {
            $volume = floatval($matches[1]);
            return round($volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        $mlMatch = preg_match('/(\d+)\s*ML\b/i', $details2, $matches);
        if ($mlMatch) {
            return intval($matches[1]);
        }
    }
    
    // Fallback: parse details column
    if ($details) {
        // Handle special cases
        if (stripos($details, 'QUART') !== false) return 750;
        if (stripos($details, 'PINT') !== false) return 375;
        if (stripos($details, 'NIP') !== false) return 90;
        
        // Handle liter sizes with decimal points
        $literMatch = preg_match('/(\d+\.?\d*)\s*L\b/i', $details, $matches);
        if ($literMatch) {
            $volume = floatval($matches[1]);
            return round($volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        $mlMatch = preg_match('/(\d+)\s*ML\b/i', $details, $matches);
        if ($mlMatch) {
            return intval($matches[1]);
        }
    }
    
    return 0; // Unknown size
}

// Function to calculate volume limits based on license type
function getVolumeLimits($license_type) {
    $limits = [
        'FL' => ['max_bottles' => 12, 'max_volume' => 9000], // Foreign Liquor
        'CL' => ['max_bottles' => 6, 'max_volume' => 4500],  // Country Liquor  
        'BEER' => ['max_bottles' => 12, 'max_volume' => 9000], // Beer
        'WINE' => ['max_bottles' => 12, 'max_volume' => 9000], // Wine
        'DEFAULT' => ['max_bottles' => 12, 'max_volume' => 9000]
    ];
    
    return $limits[$license_type] ?? $limits['DEFAULT'];
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
// LICENSE RESTRICTIONS - APPLIED FROM ITEM_MASTER.PHP
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

// Log the date selection
logBillGenerationStep('DATE_SELECTION', [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'comp_id' => $comp_id,
    'mode' => $mode,
    'sequence_type' => $sequence_type,
    'search' => $search
]);

// Validate that end date is not in the future
if (strtotime($end_date) > strtotime(date('Y-m-d'))) {
    $end_date = date('Y-m-d');
    logMessage("End date adjusted to today's date as future date was selected", 'WARNING');
}

// Get day number for end date - CHANGED FROM OPEN TO CLOSING
$end_day = date('d', strtotime($end_date));
$end_day_column = "DAY_" . str_pad($end_day, 2, '0', STR_PAD_LEFT) . "_CLOSING"; // CHANGED: OPEN → CLOSING

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

// Log table information
logBillGenerationStep('TABLE_SELECTION', [
    'end_date' => $end_date,
    'end_day' => $end_day,
    'end_day_column' => $end_day_column,
    'daily_stock_table' => $daily_stock_table,
    'table_exists' => $table_exists ? 'YES' : 'NO'
]);

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
// PERFORMANCE OPTIMIZATION: PAGINATION
// ============================================================================
$items_per_page = 50; // Adjust based on your needs
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// ============================================================================
// MODIFIED: LICENSE-BASED ITEM FILTERING WITH DAILY STOCK > 0 FILTER
// ============================================================================

// Get total count for pagination with license filtering AND stock > 0 filter
$total_items = 0;
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
$items = [];
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

// Log items loaded
logBillGenerationStep('ITEMS_LOADED', [
    'total_items' => $total_items,
    'items_on_page' => count($items),
    'current_page' => $current_page,
    'items_per_page' => $items_per_page
]);

// Calculate total pages
$total_pages = ceil($total_items / $items_per_page);

// ============================================================================
// NEW: DATE AVAILABILITY CHECKING FOR EACH ITEM
// ============================================================================
$item_availability = [];
$availability_summary = [
    'fully_available' => 0,
    'partially_available' => 0,
    'not_available' => 0,
    'total_items' => count($items)
];

// Create date range array
$begin = new DateTime($start_date);
$end = new DateTime($end_date);
$end = $end->modify('+1 day'); // Include end date

$interval = new DateInterval('P1D');
$date_range = new DatePeriod($begin, $interval, $end);

$date_array = [];
foreach ($date_range as $date) {
    $date_array[] = $date->format("Y-m-d");
}
$days_count = count($date_array);

// Check availability for each item
foreach ($items as $item) {
    $item_code = $item['CODE'];
    $availability = getItemDateAvailability($conn, $item_code, $start_date, $end_date, $comp_id);
    $item_availability[$item_code] = $availability;
    
    // Count available dates
    $available_dates_count = count(array_filter($availability));
    
    // Update summary
    if ($available_dates_count == 0) {
        $availability_summary['not_available']++;
    } elseif ($available_dates_count == $days_count) {
        $availability_summary['fully_available']++;
    } else {
        $availability_summary['partially_available']++;
    }
}

// Log availability summary
logBillGenerationStep('AVAILABILITY_CHECK', [
    'date_range' => $start_date . ' to ' . $end_date,
    'days_count' => $days_count,
    'fully_available' => $availability_summary['fully_available'],
    'partially_available' => $availability_summary['partially_available'],
    'not_available' => $availability_summary['not_available']
]);

// ============================================================================
// FIXED: SESSION QUANTITY PRESERVATION WITH PAGINATION
// ============================================================================

// Initialize session if not exists
if (!isset($_SESSION['sale_quantities'])) {
    $_SESSION['sale_quantities'] = [];
}

// Handle form submission to update session quantities
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['closing_balance'])) {
    logBillGenerationStep('FORM_SUBMISSION_RECEIVED', [
        'post_data_count' => count($_POST['closing_balance']),
        'action' => isset($_POST['update_sales']) ? 'update_sales' : 'unknown'
    ]);
    
    foreach ($_POST['closing_balance'] as $item_code => $closing_balance) {
        $closing_val = floatval($closing_balance);
        $current_stock = 0;
        
        // Find current stock for this item
        foreach ($items as $item) {
            if ($item['CODE'] == $item_code) {
                $current_stock = $item['CURRENT_STOCK'];
                break;
            }
        }
        
        // Calculate sale quantity: sale_qty = current_stock - closing_balance
        $sale_qty = $current_stock - $closing_val;
        
        logBillGenerationStep('ITEM_QUANTITY_CALCULATION', [
            'item_code' => $item_code,
            'closing_balance' => $closing_val,
            'current_stock' => $current_stock,
            'sale_qty' => $sale_qty
        ]);
        
        if ($sale_qty > 0) {
            $_SESSION['sale_quantities'][$item_code] = $sale_qty;
        } else {
            // Remove zero quantities to keep session clean
            unset($_SESSION['sale_quantities'][$item_code]);
        }
    }
    
    // Log session update
    logBillGenerationStep('SESSION_UPDATE', [
        'session_items_count' => count($_SESSION['sale_quantities']),
        'items_with_positive_qty' => array_sum($_SESSION['sale_quantities']) > 0 ? 'YES' : 'NO'
    ]);
}

// MODIFIED: Get ALL items data for JavaScript from ALL modes for Total Sales Summary
// This now uses the daily stock table for the end date
$all_items_data = [];
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
    $current_daily_stock_table = "tbldailystock_" . $comp_id; // FIXED: Removed extra double quote
    
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
// PERFORMANCE OPTIMIZED: MAIN UPDATE PROCESSING
// ============================================================================

// Handle form submission for sales update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Log POST request
    logBillGenerationStep('POST_REQUEST_RECEIVED', [
        'post_keys' => array_keys($_POST),
        'has_update_sales' => isset($_POST['update_sales']) ? 'YES' : 'NO',
        'has_closing_balance' => isset($_POST['closing_balance']) ? count($_POST['closing_balance']) : 0
    ]);
    
    // Check if this is a duplicate submission
    if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 5) {
        $error_message = "Duplicate submission detected. Please wait a few seconds before trying again.";
        logMessage("Duplicate submission prevented for user " . $_SESSION['user_id'], 'WARNING');
    } else {
        $_SESSION['last_submission'] = time();
        
        if (isset($_POST['update_sales'])) {
            logBillGenerationStep('BILL_GENERATION_STARTED', [
                'user_id' => $_SESSION['user_id'],
                'comp_id' => $_SESSION['CompID'],
                'start_date' => $_POST['start_date'] ?? '',
                'end_date' => $_POST['end_date'] ?? ''
            ]);
            
            // ============================================================================
            // PERFORMANCE OPTIMIZATION - DATABASE OPTIMIZATIONS
            // ============================================================================
            $conn->query("SET SESSION wait_timeout = 28800");
            $conn->query("SET autocommit = 0");
            
            // Reduce logging frequency
            $bulk_operation = (count($_SESSION['sale_quantities'] ?? []) > 100);
            
            if ($bulk_operation) {
                logMessage("Starting bulk sales operation with " . count($_SESSION['sale_quantities']) . " items - Performance mode enabled");
            }
            
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $comp_id = $_SESSION['CompID'];
            $user_id = $_SESSION['user_id'];
            $fin_year_id = $_SESSION['FIN_YEAR_ID'];
            
            logBillGenerationStep('PROCESSING_PARAMETERS', [
                'start_date' => $start_date,
                'end_date' => $end_date,
                'comp_id' => $comp_id,
                'user_id' => $user_id,
                'fin_year_id' => $fin_year_id,
                'session_items_count' => count($_SESSION['sale_quantities'] ?? [])
            ]);
            
            // ============================================================================
            // MODIFIED: Get ALL items from ALL modes for validation (not filtered by current mode)
            // Now using daily stock table for end date
            // ============================================================================
            $end_day = date('d', strtotime($end_date));
            $end_day_column = "DAY_" . str_pad($end_day, 2, '0', STR_PAD_LEFT) . "_CLOSING"; // CHANGED: OPEN → CLOSING
            $daily_stock_table = getDailyStockTableName($conn, $end_date, $comp_id);
            
            logBillGenerationStep('STOCK_TABLE_INFO', [
                'end_day' => $end_day,
                'end_day_column' => $end_day_column,
                'daily_stock_table' => $daily_stock_table
            ]);
            
            $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, im.LIQ_FLAG,
                                       COALESCE(dst.$end_day_column, 0) as CURRENT_STOCK
                                FROM tblitemmaster im
                                LEFT JOIN $daily_stock_table dst ON im.CODE = dst.ITEM_CODE 
                                WHERE dst.STK_MONTH = ?
                                AND dst.$end_day_column > 0";
            $all_items_stmt = $conn->prepare($all_items_query);
            $all_items_stmt->bind_param("s", date('Y-m', strtotime($end_date)));
            $all_items_stmt->execute();
            $all_items_result = $all_items_stmt->get_result();
            $all_items = [];
            while ($row = $all_items_result->fetch_assoc()) {
                $all_items[$row['CODE']] = $row;
            }
            $all_items_stmt->close();
            
            logBillGenerationStep('ALL_ITEMS_LOADED', [
                'items_count' => count($all_items),
                'sample_items' => array_slice(array_keys($all_items), 0, 5)
            ]);
            
            // NEW: Check item availability before processing
            $availability_errors = [];
            $all_available_dates = [];
            foreach ($all_items as $item_code => $item) {
                $availability = getItemDateAvailability($conn, $item_code, $start_date, $end_date, $comp_id);
                $all_available_dates[$item_code] = $availability;
                
                // Check if item has any available dates
                $available_dates = array_filter($availability);
                if (isset($_SESSION['sale_quantities'][$item_code]) && 
                    $_SESSION['sale_quantities'][$item_code] > 0 && 
                    count($available_dates) == 0) {
                    $availability_errors[] = "Item {$item_code} ({$item['DETAILS']}) has no available dates in the selected range";
                }
            }
            
            logBillGenerationStep('AVAILABILITY_CHECK_COMPLETE', [
                'items_checked' => count($all_available_dates),
                'availability_errors_count' => count($availability_errors)
            ]);
            
            if (!empty($availability_errors)) {
                $error_message = "Date availability check failed:<br>" . implode("<br>", array_slice($availability_errors, 0, 5));
                if (count($availability_errors) > 5) {
                    $error_message .= "<br>... and " . (count($availability_errors) - 5) . " more items";
                }
                logMessage("Date availability check failed: " . implode("; ", $availability_errors), 'ERROR');
                logBillGenerationStep('AVAILABILITY_CHECK_FAILED', [
                    'errors' => $availability_errors,
                    'error_count' => count($availability_errors)
                ]);
            } else {
                // Enhanced stock validation before transaction
                $stock_errors = [];
                if (isset($_SESSION['sale_quantities'])) {
                    foreach ($_SESSION['sale_quantities'] as $item_code => $total_qty) {
                        if ($total_qty > 0 && isset($all_items[$item_code])) {
                            $current_stock = $all_items[$item_code]['CURRENT_STOCK'];
                            
                            logBillGenerationStep('ITEM_STOCK_VALIDATION', [
                                'item_code' => $item_code,
                                'current_stock' => $current_stock,
                                'requested_qty' => $total_qty,
                                'closing_balance' => $current_stock - $total_qty
                            ]);
                            
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
                    logMessage("Stock validation failed: " . implode("; ", $stock_errors), 'ERROR');
                    logBillGenerationStep('STOCK_VALIDATION_FAILED', [
                        'errors' => $stock_errors,
                        'error_count' => count($stock_errors)
                    ]);
                } else {
                    // Start transaction
                    $conn->begin_transaction();
                    logBillGenerationStep('TRANSACTION_STARTED', [
                        'transaction_id' => 'TRX_' . time() . '_' . $user_id,
                        'items_to_process' => count($_SESSION['sale_quantities'] ?? [])
                    ]);
                    
                    try {
                        $total_amount = 0;
                        $items_data = [];
                        $daily_sales_data = [];
                        
                        logBillGenerationStep('PREPARING_ITEM_DATA', [
                            'session_items_count' => count($_SESSION['sale_quantities'] ?? [])
                        ]);
                        
                        // ============================================================================
                        // PERFORMANCE OPTIMIZATION: BATCH PROCESSING WITH PROGRESS
                        // ============================================================================
                        $batch_size = 50;
                        $session_items = array_keys($_SESSION['sale_quantities']);
                        $total_session_items = count($session_items);
                        $processed_items = 0;
                        
                        // Cache item data to avoid repeated database lookups
                        $item_cache = [];
                        foreach ($all_items as $item_code => $item) {
                            $item_cache[$item_code] = [
                                'stock' => $item['CURRENT_STOCK'],
                                'rate' => $item['RPRICE'],
                                'details' => $item['DETAILS']
                            ];
                        }
                        
                        // MySQL Performance Tweaks
                        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                        $conn->query("SET UNIQUE_CHECKS = 0");
                        $conn->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");
                        
                        // Process ONLY session quantities > 0 (from ALL modes) in batches
                        for ($i = 0; $i < $total_session_items; $i += $batch_size) {
                            $batch_items = array_slice($session_items, $i, $batch_size);
                            
                            foreach ($batch_items as $item_code) {
                                $processed_items++;
                                $total_qty = $_SESSION['sale_quantities'][$item_code];
                                
                                logBillGenerationStep('PROCESSING_ITEM', [
                                    'item_number' => $processed_items,
                                    'total_items' => $total_session_items,
                                    'item_code' => $item_code,
                                    'quantity' => $total_qty
                                ]);
                                
                                if ($total_qty > 0 && isset($all_items[$item_code])) {
                                    $item = $all_items[$item_code];
                                    
                                    // NEW: Generate distribution based on date availability
                                    $availability = $all_available_dates[$item_code];
                                    $daily_sales = distributeSalesAcrossAvailableDates($total_qty, $date_array, $availability);
                                    $daily_sales_data[$item_code] = $daily_sales;
                                    
                                    // Store item data
                                    $items_data[$item_code] = [
                                        'name' => $item['DETAILS'],
                                        'rate' => $item['RPRICE'],
                                        'total_qty' => $total_qty,
                                        'mode' => $item['LIQ_FLAG'] // Use item's actual mode, not current page mode
                                    ];
                                    
                                    logBillGenerationStep('ITEM_DISTRIBUTION_CREATED', [
                                        'item_code' => $item_code,
                                        'total_qty' => $total_qty,
                                        'daily_sales' => $daily_sales,
                                        'available_dates' => array_sum($availability),
                                        'item_mode' => $item['LIQ_FLAG']
                                    ]);
                                }
                            }
                            
                            // Optional: Add small delay to prevent server overload
                            if ($bulk_operation) {
                                usleep(100000); // 0.1 second
                            }
                        }
                        
                        logBillGenerationStep('ALL_ITEMS_PROCESSED', [
                            'total_processed' => $processed_items,
                            'items_with_data' => count($items_data),
                            'daily_sales_items' => count($daily_sales_data)
                        ]);
                        
                        // NEW: Date-wise stock validation before proceeding
                        logBillGenerationStep('DATE_WISE_STOCK_VALIDATION_START', [
                            'items_to_validate' => count($daily_sales_data)
                        ]);
                        
                        $date_wise_errors = validateAllDateWiseStocks($conn, $daily_sales_data, $comp_id);
                        if (!empty($date_wise_errors)) {
                            logBillGenerationStep('DATE_WISE_STOCK_VALIDATION_FAILED', [
                                'errors' => $date_wise_errors,
                                'error_count' => count($date_wise_errors)
                            ]);
                            throw new Exception("Date-wise stock validation failed:\n" . implode("\n", array_slice($date_wise_errors, 0, 10)));
                        }
                        
                        logBillGenerationStep('DATE_WISE_STOCK_VALIDATION_PASSED', [
                            'validated_items' => count($daily_sales_data)
                        ]);
                        
                        // Only proceed if we have items with quantities
                        if (!empty($items_data)) {
                            logBillGenerationStep('BILL_GENERATION_START', [
                                'total_items' => count($items_data),
                                'total_quantity' => array_sum(array_column($items_data, 'total_qty'))
                            ]);
                            
                            // ============================================================================
                            // VOLUME-BASED BILL GENERATION (FROM sale_for_date_range.php)
                            // ============================================================================
                            logBillGenerationStep('CALLING_GENERATE_BILLS_FUNCTION', [
                                'function' => 'generateBillsWithLimits',
                                'items_count' => count($items_data),
                                'date_array_count' => count($date_array),
                                'mode' => $mode
                            ]);
                            
                            $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
                            
                            logBillGenerationStep('BILLS_GENERATED', [
                                'bills_count' => count($bills),
                                'sample_bills' => array_slice($bills, 0, 3)
                            ]);
                            
                            // Get stock column names
                            $current_stock_column = "Current_Stock" . $comp_id;
                            $opening_stock_column = "Opening_Stock" . $comp_id;
                            
                            // Get next bill number once to ensure proper numerical order FOR THIS COMPANY
                            $next_bill_number = getNextBillNumber($conn, $comp_id);
                            logBillGenerationStep('NEXT_BILL_NUMBER', [
                                'next_bill_number' => $next_bill_number,
                                'comp_id' => $comp_id
                            ]);
                            
                            // Process each bill in chronological AND numerical order
                            usort($bills, function($a, $b) {
                                return strtotime($a['bill_date']) - strtotime($b['bill_date']);
                            });
                            
                            // Process each bill with proper zero-padded bill numbers
                            $bill_numbers = []; // Store bill numbers for cash memo generation
                            $bill_counter = 0;
                            
                            foreach ($bills as $bill) {
                                $bill_counter++;
                                $padded_bill_no = "BL" . str_pad($next_bill_number++, 4, '0', STR_PAD_LEFT);
                                $bill_numbers[] = $padded_bill_no; // Store for cash memo generation
                                
                                logBillGenerationStep('PROCESSING_BILL', [
                                    'bill_number' => $bill_counter,
                                    'total_bills' => count($bills),
                                    'bill_no' => $padded_bill_no,
                                    'bill_date' => $bill['bill_date'],
                                    'bill_mode' => $bill['mode'],
                                    'items_count' => count($bill['items']),
                                    'total_amount' => $bill['total_amount']
                                ]);
                                
                                // Insert sale header
                                $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                                 VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                                $header_stmt = $conn->prepare($header_query);
                                $header_stmt->bind_param("ssddssi", $padded_bill_no, $bill['bill_date'], $bill['total_amount'], 
                                                        $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
                                $header_stmt->execute();
                                $header_stmt->close();
                                
                                logBillGenerationStep('BILL_HEADER_INSERTED', [
                                    'bill_no' => $padded_bill_no,
                                    'table' => 'tblsaleheader',
                                    'bill_date' => $bill['bill_date'],
                                    'amount' => $bill['total_amount']
                                ]);
                                
                                // Insert sale details for each item in the bill
                                foreach ($bill['items'] as $item) {
                                    $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                                     VALUES (?, ?, ?, ?, ?, ?, ?)";
                                    $detail_stmt = $conn->prepare($detail_query);
                                    $detail_stmt->bind_param("ssddssi", $padded_bill_no, $item['code'], $item['qty'], 
                                                            $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                                    $detail_stmt->execute();
                                    $detail_stmt->close();
                                    
                                    logBillGenerationStep('BILL_DETAIL_INSERTED', [
                                        'bill_no' => $padded_bill_no,
                                        'item_code' => $item['code'],
                                        'quantity' => $item['qty'],
                                        'rate' => $item['rate'],
                                        'amount' => $item['amount'],
                                        'table' => 'tblsaledetails'
                                    ]);
                                    
                                    // Update stock
                                    updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
                                    
                                    logBillGenerationStep('ITEM_STOCK_UPDATED', [
                                        'item_code' => $item['code'],
                                        'quantity_deducted' => $item['qty'],
                                        'table' => 'tblitem_stock',
                                        'column' => $current_stock_column
                                    ]);
                                    
                                    // ENHANCED: Update daily stock with cascading logic
                                    updateCascadingDailyStock($conn, $item['code'], $bill['bill_date'], $comp_id, 'sale', $item['qty']);
                                    
                                    logBillGenerationStep('DAILY_STOCK_UPDATED', [
                                        'item_code' => $item['code'],
                                        'sale_date' => $bill['bill_date'],
                                        'quantity' => $item['qty'],
                                        'operation' => 'sale'
                                    ]);
                                }
                                
                                $total_amount += $bill['total_amount'];
                            }
                            
                            logBillGenerationStep('ALL_BILLS_PROCESSED', [
                                'total_bills' => count($bills),
                                'total_amount' => $total_amount,
                                'bill_numbers' => $bill_numbers
                            ]);
                            
                            // Commit transaction
                            $conn->commit();
                            logBillGenerationStep('TRANSACTION_COMMITTED', [
                                'transaction_success' => 'YES',
                                'total_amount' => $total_amount,
                                'bills_created' => count($bills)
                            ]);
                            
                            // CLEAR SESSION QUANTITIES AFTER SUCCESS
                            clearSessionQuantities();
                            
                            $success_message = "Sales distributed successfully! Generated " . count($bills) . " bills. Total Amount: ₹" . number_format($total_amount, 2);
                            
                            logBillGenerationStep('SUCCESS_MESSAGE', [
                                'message' => $success_message,
                                'bills_count' => count($bills),
                                'total_amount' => $total_amount
                            ]);
                            
                            // ============================================================================
                            // ADD AUTOMATIC CASH MEMO GENERATION (FROM OLD VERSION)
                            // ============================================================================
                            $cash_memos_generated = 0;
                            $cash_memo_errors = [];

                            logBillGenerationStep('CASH_MEMO_GENERATION_START', [
                                'bill_count' => count($bill_numbers),
                                'bill_numbers' => $bill_numbers
                            ]);

                            // Generate cash memos for all created bills
                            foreach ($bill_numbers as $bill_no) {
                                if (autoGenerateCashMemoForBill($conn, $bill_no, $comp_id, $_SESSION['user_id'])) {
                                    $cash_memos_generated++;
                                    logCashMemoGeneration($bill_no, true);
                                    logBillGenerationStep('CASH_MEMO_SUCCESS', [
                                        'bill_no' => $bill_no,
                                        'status' => 'SUCCESS'
                                    ]);
                                } else {
                                    $cash_memo_errors[] = $bill_no;
                                    logCashMemoGeneration($bill_no, false, "Cash memo generation failed");
                                    logBillGenerationStep('CASH_MEMO_FAILED', [
                                        'bill_no' => $bill_no,
                                        'status' => 'FAILED'
                                    ]);
                                }
                            }

                            logBillGenerationStep('CASH_MEMO_GENERATION_COMPLETE', [
                                'successful' => $cash_memos_generated,
                                'failed' => count($cash_memo_errors),
                                'failed_bills' => $cash_memo_errors
                            ]);

                            // Update success message to include cash memo info
                            if ($cash_memos_generated > 0) {
                                $success_message .= " | Cash Memos Generated: " . $cash_memos_generated;
                            }

                            if (!empty($cash_memo_errors)) {
                                $success_message .= " | Failed to generate cash memos for bills: " . implode(", ", array_slice($cash_memo_errors, 0, 5));
                                if (count($cash_memo_errors) > 5) {
                                    $success_message .= " and " . (count($cash_memo_errors) - 5) . " more";
                                }
                            }

                            // Redirect to retail_sale.php
                            logBillGenerationStep('REDIRECTING_TO_RETAIL_SALE', [
                                'redirect_url' => 'retail_sale.php',
                                'success_message' => $success_message
                            ]);
                            
                            header("Location: retail_sale.php?success=" . urlencode($success_message));
                            exit;
                        } else {
                            $error_message = "No quantities entered for any items.";
                            logBillGenerationStep('NO_QUANTITIES_ERROR', [
                                'error' => $error_message,
                                'session_items' => count($_SESSION['sale_quantities'] ?? [])
                            ]);
                        }
                    } catch (Exception $e) {
                        // Rollback transaction on error
                        $conn->rollback();
                        $error_message = "Error updating sales: " . $e->getMessage();
                        logMessage("Transaction rolled back: " . $e->getMessage(), 'ERROR');
                        logBillGenerationStep('TRANSACTION_ROLLBACK', [
                            'error' => $e->getMessage(),
                            'error_code' => $e->getCode(),
                            'trace' => $e->getTraceAsString()
                        ], 'ERROR');
                    }
                    
                    // ============================================================================
                    // PERFORMANCE OPTIMIZATION: CLEANUP
                    // ============================================================================
                    
                    // Re-enable database constraints
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    $conn->query("SET UNIQUE_CHECKS = 1");
                    
                    // Clean up memory
                    if (isset($all_items)) unset($all_items);
                    if (isset($items_data)) unset($items_data); 
                    if (isset($daily_sales_data)) unset($daily_sales_data);
                    gc_collect_cycles();
                    
                    if ($bulk_operation) {
                        logMessage("Bulk sales operation completed - Processed $processed_items items");
                    }
                }
            }
        }
    }
}

// Check for success message in URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// OPTIMIZATION: Reduce logging frequency
// logMessage("Final session quantities count: " . count($_SESSION['sale_quantities']));
// logMessage("Items in current view: " . count($items));

// Debug info
$debug_info = [
    'total_items' => $total_items,
    'current_page' => $current_page,
    'total_pages' => $total_pages,
    'session_quantities_count' => count($_SESSION['sale_quantities']),
    'post_quantities_count' => ($_SERVER['REQUEST_METHOD'] === 'POST') ? count($_POST['closing_balance'] ?? []) : 0,
    'date_range' => "$start_date to $end_date",
    'days_count' => $days_count,
    'user_id' => $_SESSION['user_id'],
    'comp_id' => $comp_id,
    'license_type' => $license_type,
    'allowed_classes' => $allowed_classes,
    'end_day_column' => $end_day_column, // ADDED: Which column we're using
    'daily_stock_table' => $daily_stock_table, // ADDED: Which table we're using
    'table_exists' => $table_exists, // ADDED: Whether table exists
    'availability_summary' => $availability_summary // NEW: Added availability summary
];
logArray($debug_info, "Sales by Closing Balance Page Load Debug Info");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales by Closing Balance - WineSoft</title>
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

    /* License info banner */
    .license-info-banner {
        background: linear-gradient(45deg, #ff6b6b, #ee5a24);
        color: white;
        padding: 10px 15px;
        border-radius: 5px;
        margin-bottom: 15px;
        font-weight: bold;
        border-left: 5px solid #ff9ff3;
    }

    /* Stock info note */
    .stock-info-note {
        background-color: #e7f3ff;
        border-left: 4px solid #007bff;
        padding: 10px;
        margin: 10px 0;
        font-size: 0.9em;
    }

    /* NEW: Date availability info banner */
    .date-availability-banner {
        background-color: #e7f3ff;
        border-left: 4px solid #007bff;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
    }

    .availability-explanation {
        font-size: 0.9em;
        color: #666;
        margin-top: 10px;
    }

    .availability-explanation ul {
        margin-bottom: 0;
        padding-left: 20px;
    }

    /* NEW: Availability summary cards */
    .availability-card {
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        text-align: center;
    }

    .availability-card-fully {
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
    }

    .availability-card-partial {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
    }

    .availability-card-none {
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
    }

    .availability-count {
        font-size: 24px;
        font-weight: bold;
        margin-bottom: 5px;
    }

    .availability-label {
        font-size: 14px;
        color: #666;
    }

    /* NEW: Availability badge styles */
    .availability-badge {
        font-size: 0.7em;
        padding: 2px 6px;
        border-radius: 10px;
        margin-left: 5px;
    }

    .availability-fully {
        background-color: #d4edda;
        color: #155724;
    }

    .availability-partial {
        background-color: #fff3cd;
        color: #856404;
    }

    .availability-none {
        background-color: #f8d7da;
        color: #721c24;
    }

    /* NEW: Date cell styles */
    .date-cell-available {
        background-color: #d4edda !important;
        color: #155724 !important;
        font-weight: bold;
    }

    .date-cell-unavailable {
        background-color: #f8d9db !important;
        color: #721c24 !important;
        text-align: center;
        font-weight: bold;
    }

    .date-cell-unavailable::after {
        content: "⛔";
        display: block;
        font-size: 12px;
    }

    .date-cell-has-sales {
        background-color: #cce5ff !important;
        color: #004085 !important;
        font-weight: bold;
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

/* Date distribution column styles */
.date-header {
    min-width: 60px;
    text-align: center;
    font-size: 11px;
    padding: 4px !important;
    background-color: #f8f9fa;
}

.date-distribution-cell {
    text-align: center;
    font-size: 11px;
    padding: 4px !important;
    background-color: #f8fff8;
}

.date-distribution-cell.highlight {
    background-color: #e8f5e8;
    font-weight: bold;
}

/* Date headers styling */
.date-header-cell {
    min-width: 50px;
    text-align: center;
    font-size: 11px;
    padding: 2px !important;
}

/* Action column */
.action-column {
    min-width: 100px;
}

/* Date cells in table */
.date-cell {
    min-width: 50px;
    text-align: center;
    font-size: 11px;
    padding: 2px !important;
}

/* Hide certain columns by default */
.hidden-columns {
    display: none;
}

/* Sales distribution preview styles */
.distribution-preview {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin-top: 10px;
}

.distribution-day {
    display: inline-block;
    width: 30px;
    height: 30px;
    line-height: 30px;
    text-align: center;
    margin: 2px;
    border-radius: 3px;
    background-color: #e9ecef;
    font-size: 11px;
}

.distribution-day.has-qty {
    background-color: #d4edda;
    color: #155724;
    font-weight: bold;
}

/* Modal styles */
.modal-xl {
    max-width: 90%;
}

/* Client-side validation alert */
.validation-alert {
    display: none;
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    max-width: 500px;
}

/* Stock checking animation */
.stock-checking {
    background-color: #fff3cd !important;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.8; }
    100% { opacity: 1; }
}

/* Date range info */
.date-range-info {
    background-color: #e7f3ff;
    padding: 8px 12px;
    border-radius: 5px;
    margin-bottom: 15px;
    border-left: 4px solid #007bff;
}

/* Table responsive */
.table-container {
    overflow-x: auto;
    max-height: 600px;
    overflow-y: auto;
}

/* Fixed header for table */
.styled-table thead th {
    position: sticky;
    top: 0;
    background-color: #f8f9fa;
    z-index: 10;
}
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Sales by Closing Balance</h3>

      <!-- NEW: Date Availability Info Banner -->
      <div class="date-availability-banner mb-3">
          <strong><i class="fas fa-calendar-check"></i> Date Availability Information:</strong>
          <p class="mb-2">Dates are automatically blocked based on:
            <span class="text-success"><i class="fas fa-check-circle"></i> Previous sales dates</span> | 
            <span class="text-danger"><i class="fas fa-ban"></i> Dry days</span> | 
            <span class="text-warning"><i class="fas fa-calendar-times"></i> Future dates</span>
          </p>
          
          <!-- Availability Summary Cards -->
          <div class="row mb-2">
              <div class="col-md-4">
                  <div class="availability-card availability-card-fully">
                      <div class="availability-count"><?= $availability_summary['fully_available'] ?></div>
                      <div class="availability-label">Fully Available<br><small>All <?= $days_count ?> dates</small></div>
                  </div>
              </div>
              <div class="col-md-4">
                  <div class="availability-card availability-card-partial">
                      <div class="availability-count"><?= $availability_summary['partially_available'] ?></div>
                      <div class="availability-label">Partially Available<br><small>Some dates blocked</small></div>
                  </div>
              </div>
              <div class="col-md-4">
                  <div class="availability-card availability-card-none">
                      <div class="availability-count"><?= $availability_summary['not_available'] ?></div>
                      <div class="availability-label">Not Available<br><small>All dates blocked</small></div>
                  </div>
              </div>
          </div>
          
          <div class="availability-explanation">
              <strong>How availability works:</strong>
              <ul>
                  <li><strong>Fully Available:</strong> Item can be sold on all <?= $days_count ?> dates in the range</li>
                  <li><strong>Partially Available:</strong> Some dates are blocked (after last sale date or dry days)</li>
                  <li><strong>Not Available:</strong> All dates are blocked (item sold after <?= $end_date ?>)</li>
              </ul>
              <p class="mb-0"><small><i class="fas fa-info-circle"></i> Sales will only be distributed to available dates. Blocked dates show as grayed out with ⛔ symbol.</small></p>
          </div>
      </div>

      <!-- License Information Banner -->
      <div class="license-info-banner">
        <i class="fas fa-id-card"></i> License Type: <?= htmlspecialchars($license_type) ?> | 
        Available Classes: <?= htmlspecialchars(implode(', ', $allowed_classes)) ?>
      </div>

      <!-- Stock Info Note - UPDATED TEXT FOR CLOSING STOCK -->
      <div class="stock-info-note mb-3">
          <strong><i class="fas fa-info-circle"></i> Stock Information:</strong>
          <p class="mb-0">Showing stock as of <strong><?= date('d-M-Y', strtotime($end_date)) ?></strong> (DAY_<?= $end_day ?>_CLOSING from <?= $daily_stock_table ?> table). Only items with stock > 0 are displayed.</p>
      </div>

      <!-- Date Range Info -->
      <div class="date-range-info mb-3">
          <strong><i class="fas fa-calendar-alt"></i> Date Range:</strong>
          <?= date('d-M-Y', strtotime($start_date)) ?> to <?= date('d-M-Y', strtotime($end_date)) ?>
          (<?= $days_count ?> days) | 
          <strong>Total Items:</strong> <?= $total_items ?> | 
          <strong>Items with Quantities:</strong> <span id="itemsWithQtyCount"><?= count($_SESSION['sale_quantities']) ?></span>
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
          <button type="button" id="shuffleBtn" class="btn btn-warning btn-action" <?= !$table_exists ? 'disabled' : '' ?>>
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
                <th>Current Stock<br><small>As of <?= date('d-M-Y', strtotime($end_date)) ?> Closing</small></th>
                <th class="closing-balance-header">Closing Balance</th>
                <th>Total Sale Qty</th>
                <th class="action-column">Action</th>
                
                <!-- Date Distribution Headers -->
                <?php foreach ($date_array as $date): 
                    $date_obj = new DateTime($date);
                    $day = $date_obj->format('d');
                    $month = $date_obj->format('M');
                ?>
                <th class="date-header date-header-cell" data-date="<?= $date ?>">
                    <?= $day ?><br><?= $month ?>
                </th>
                <?php endforeach; ?>
                
                <th class="hidden-columns">Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
<?php if (!empty($items) && $table_exists): 
    $item_counter = 0;
    foreach ($items as $item): 
        $item_code = $item['CODE'];
        $item_qty = isset($_SESSION['sale_quantities'][$item_code]) ? $_SESSION['sale_quantities'][$item_code] : 0;
        $item_total = $item_qty * $item['RPRICE'];
        $closing_balance = $item['CURRENT_STOCK'] - $item_qty;
        
        // Format values as integers (no decimals)
        $current_stock_int = (int)$item['CURRENT_STOCK']; // Cast to integer
        $closing_balance_int = (int)$closing_balance; // Cast to integer
        $item_qty_int = (int)$item_qty; // Cast to integer
        
        // Extract size from item details
        $size = 0;
        if (preg_match('/(\d+)\s*ML\b/i', $item['DETAILS'], $matches)) {
            $size = $matches[1];
        }
        
        // Get class code - now available from the query
        $class_code = $item['CLASS'] ?? 'O'; // Default to 'O' if not set
        
        // NEW: Get availability for this item
        $availability = $item_availability[$item_code] ?? [];
        $available_dates = array_filter($availability);
        $availability_count = count($available_dates);
        
        // Determine availability badge
        if ($availability_count == 0) {
            $availability_class = 'availability-none';
            $availability_text = 'No dates';
        } elseif ($availability_count == $days_count) {
            $availability_class = 'availability-fully';
            $availability_text = 'All dates';
        } else {
            $availability_class = 'availability-partial';
            $availability_text = $availability_count . '/' . $days_count . ' dates';
        }
        
        $item_counter++;
    ?>
        <tr data-class="<?= htmlspecialchars($class_code) ?>" 
            data-details="<?= htmlspecialchars($item['DETAILS']) ?>" 
            data-details2="<?= htmlspecialchars($item['DETAILS2']) ?>"
            data-availability='<?= json_encode($availability) ?>'
            class="<?= $item_qty > 0 ? 'has-quantity' : '' ?>"
            id="item-row-<?= $item_counter ?>">
            <td>
                <?= htmlspecialchars($item_code); ?>
                <!-- NEW: Availability badge -->
                <span class="availability-badge <?= $availability_class ?>" 
                      title="Available on <?= $availability_text ?> in the selected date range">
                    <?= $availability_text ?>
                </span>
            </td>
            <td><?= htmlspecialchars($item['DETAILS']); ?></td>
            <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
            <td><?= number_format($item['RPRICE'], 2); ?></td>
            <td>
                <span class="stock-info"><?= number_format($current_stock_int, 0, '.', ','); ?></span>
            </td>
            <td>
                <input type="number" name="closing_balance[<?= htmlspecialchars($item_code); ?>]" 
                       class="form-control qty-input" min="0" 
                       max="<?= $item['CURRENT_STOCK']; ?>" 
                       step="1" value="<?= number_format($closing_balance_int, 0) ?>" 
                       data-rate="<?= $item['RPRICE'] ?>"
                       data-code="<?= htmlspecialchars($item_code); ?>"
                       data-stock="<?= $item['CURRENT_STOCK'] ?>"
                       data-size="<?= $size ?>"
                       data-counter="<?= $item_counter ?>"
                       data-availability='<?= json_encode($availability) ?>'
                       oninput="validateClosingBalance(this)"
                       id="closing-input-<?= $item_counter ?>"
                       <?= !$table_exists ? 'disabled' : '' ?>
                       <?= ($availability_count == 0) ? 'disabled title="No available dates for this item in the selected range"' : '' ?>>
            </td>
            <td class="sale-qty-cell" id="sale_qty_<?= htmlspecialchars($item_code); ?>">
                <?= number_format($item_qty_int, 0) ?>
            </td>
            <td class="action-column">
                <button type="button" class="btn btn-sm btn-outline-secondary btn-shuffle-item" 
                        data-code="<?= htmlspecialchars($item_code); ?>"
                        data-counter="<?= $item_counter ?>"
                        <?= !$table_exists ? 'disabled' : '' ?>
                        <?= ($availability_count == 0) ? 'disabled title="No available dates for this item"' : '' ?>>
                    <i class="fas fa-random"></i> Shuffle
                </button>
            </td>
            
            <!-- Date distribution cells -->
            <?php foreach ($date_array as $date_index => $date): 
                $date_id = str_replace('-', '', $date);
                // Check if date is available
                $is_available = $availability[$date] ?? false;
                $cell_class = $is_available ? 'date-cell-available' : 'date-cell-unavailable';
            ?>
            <td class="date-distribution-cell date-cell <?= $cell_class ?>" 
                data-date="<?= $date ?>"
                data-item="<?= htmlspecialchars($item_code); ?>"
                data-available="<?= $is_available ? 'true' : 'false' ?>"
                id="date-cell-<?= $item_counter ?>-<?= $date_id ?>"
                title="<?= $is_available ? 'Available date' : 'Not available (blocked)' ?>">
                <span class="date-qty-display" id="date-qty-<?= $item_counter ?>-<?= $date_id ?>">0</span>
            </td>
            <?php endforeach; ?>
            
            <td class="amount-cell hidden-columns" id="amount_<?= htmlspecialchars($item_code); ?>">
                <?= number_format($item_qty * $item['RPRICE'], 2) ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php elseif (!$table_exists): ?>
    <tr>
        <td colspan="<?= 9 + count($date_array) ?>" class="text-center text-warning">
            <i class="fas fa-exclamation-triangle"></i> Daily stock table not found for the selected end date. Please select a different date range.
        </td>
    </tr>
<?php else: ?>
    <tr>
        <td colspan="<?= 9 + count($date_array) ?>" class="text-center text-muted">No items found with stock > 0 for the selected date range.</td>
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
              | <span class="text-success"><?= count($_SESSION['sale_quantities']) ?> items with quantities across all pages (ALL MODES)</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Total Amount Summary -->
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="alert alert-info">
                    <strong>Total Sale Quantity:</strong> <span id="totalSaleQty">0</span> units
                </div>
            </div>
            <div class="col-md-6">
                <div class="alert alert-success">
                    <strong>Total Amount:</strong> ₹<span id="totalAmount">0.00</span>
                </div>
            </div>
        </div>
        
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
                <h5 class="modal-title" id="salesLogModalLabel">Sales Log</h5>
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
                <h5 class="modal-title" id="totalSalesModalLabel">Total Sales Summary (ALL MODES)</h5>
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

<!-- Bill Progress Modal -->
<div class="modal fade" id="billProgressModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-spinner fa-spin me-2"></i>
                    Generating Bills...
                </h5>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <h6 class="mt-3 text-muted" id="progressStatus">Processing your request...</h6>
                </div>

                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold">Progress</span>
                        <span class="text-muted small" id="overallProgressText">0%</span>
                    </div>
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                             id="billProgressBar" style="width: 0%"></div>
                    </div>
                </div>

                <div class="alert alert-light">
                    <small class="text-muted">Please do not close this window or refresh the page while bills are being generated.</small>
                </div>
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
// Pass ALL items data to JavaScript for Total Sales Summary (ALL modes)
const allItemsData = <?= json_encode($all_items_data) ?>;
// Table exists flag
const tableExists = <?= $table_exists ? 'true' : 'false' ?>;

// NEW: Function to get availability for an item
function getItemAvailability(itemCode, itemCounter) {
    const inputField = $(`#closing-input-${itemCounter}`);
    if (inputField.length > 0) {
        const availabilityData = inputField.data('availability');
        if (availabilityData) {
            return availabilityData;
        }
    }
    
    // If not found in input field, return all dates as available (default)
    const defaultAvailability = {};
    dateArray.forEach(date => {
        defaultAvailability[date] = true;
    });
    return defaultAvailability;
}

// NEW: Function to count available dates for an item
function countAvailableDates(itemCode, itemCounter) {
    const availability = getItemAvailability(itemCode, itemCounter);
    return Object.values(availability).filter(isAvailable => isAvailable).length;
}

// NEW: Function to distribute sales across available dates only (client-side)
function distributeSalesAcrossAvailableDatesClient(totalQty, availability) {
    // Filter available dates
    const availableDates = [];
    dateArray.forEach(date => {
        if (availability[date]) {
            availableDates.push(date);
        }
    });
    
    const availableDaysCount = availableDates.length;
    
    if (availableDaysCount === 0 || totalQty <= 0) {
        // Return array with all dates as 0
        const distribution = {};
        dateArray.forEach(date => {
            distribution[date] = 0;
        });
        return distribution;
    }
    
    // Calculate base distribution
    const baseQty = Math.floor(totalQty / availableDaysCount);
    const remainder = totalQty % availableDaysCount;
    
    // Initialize distribution
    const distribution = {};
    dateArray.forEach(date => {
        if (availability[date]) {
            distribution[date] = baseQty;
        } else {
            distribution[date] = 0;
        }
    });
    
    // Distribute remainder across available dates
    const availableDateKeys = Object.keys(availability).filter(date => availability[date]);
    for (let i = 0; i < remainder; i++) {
        const dateIndex = i % availableDaysCount;
        const date = availableDateKeys[dateIndex];
        distribution[date]++;
    }
    
    return distribution;
}

// NEW: Enhanced closing balance validation function with date availability
function validateClosingBalance(input) {
    // Check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot update quantities.');
        $(input).val(0);
        return false;
    }
    
    const itemCode = $(input).data('code');
    const currentStock = parseFloat($(input).data('stock'));
    let enteredClosingBalance = parseFloat($(input).val()) || 0;
    const itemCounter = $(input).data('counter');
    
    // NEW: Check if item has available dates
    const availability = $(input).data('availability');
    const availableDates = Object.values(availability).filter(isAvailable => isAvailable);
    
    if (availableDates.length === 0 && enteredClosingBalance < currentStock) {
        alert('This item has no available dates in the selected range. Please select a different date range.');
        $(input).val(currentStock);
        enteredClosingBalance = currentStock;
    }
    
    // Validate input
    if (isNaN(enteredClosingBalance) || enteredClosingBalance < 0) {
        enteredClosingBalance = 0;
        $(input).val(0);
    }
    
    // NEW: Prevent closing balance exceeding current stock
    if (enteredClosingBalance > currentStock) {
        enteredClosingBalance = currentStock;
        $(input).val(currentStock.toFixed(0)); // Changed to 0 decimal places
        
        // Show warning but don't prevent operation
        $(input).addClass('is-invalid');
        setTimeout(() => $(input).removeClass('is-invalid'), 2000);
    } else {
        $(input).removeClass('is-invalid');
    }
    
    // NEW: Calculate sale quantity: sale_qty = current_stock - closing_balance
    const saleQty = currentStock - enteredClosingBalance;
    
    // Update UI immediately
    updateItemUI(itemCode, saleQty, currentStock, enteredClosingBalance);
    
    // Update distribution preview with date availability
    updateDistributionPreview(itemCode, saleQty, itemCounter, availability);
    
    // Update total amounts
    updateTotalAmounts();
    
    // Save to session via AJAX to prevent data loss
    saveQuantityToSession(itemCode, saleQty);
    
    return true;
}

// Function to update distribution preview with date availability
function updateDistributionPreview(itemCode, totalQty, itemCounter, availability) {
    // Check if table exists
    if (!tableExists) return;
    
    if (totalQty <= 0) {
        // Clear all date cells
        dateArray.forEach((date, index) => {
            const dateId = date.replace(/-/g, '');
            $(`#date-qty-${itemCounter}-${dateId}`).text('0');
            $(`#date-cell-${itemCounter}-${dateId}`).removeClass('date-cell-has-sales');
        });
        return;
    }
    
    // Use availability-aware distribution
    const dailySales = distributeSalesAcrossAvailableDatesClient(totalQty, availability);
    
    // Update UI
    let totalDistributed = 0;
    dateArray.forEach((date, index) => {
        const dateId = date.replace(/-/g, '');
        const qty = dailySales[date] || 0;
        const cell = $(`#date-cell-${itemCounter}-${dateId}`);
        const qtyDisplay = $(`#date-qty-${itemCounter}-${dateId}`);
        
        qtyDisplay.text(qty);
        totalDistributed += qty;
        
        // Style cell based on availability and quantity
        cell.removeClass('date-cell-has-sales date-cell-available date-cell-unavailable');
        
        if (!availability[date]) {
            cell.addClass('date-cell-unavailable');
            qtyDisplay.text(''); // Clear quantity for unavailable dates
            cell.attr('title', 'Date not available (blocked)');
        } else if (qty > 0) {
            cell.addClass('date-cell-has-sales');
            cell.attr('title', `${qty} units on ${date}`);
        } else {
            cell.addClass('date-cell-available');
            cell.attr('title', 'Available date (no sales)');
        }
    });
    
    // Update sale quantity display (should match totalDistributed)
    $(`#sale_qty_${itemCode}`).text(totalDistributed.toFixed(0));
    
    return dailySales;
}

// UPDATED: Function to update all UI elements for an item
function updateItemUI(itemCode, saleQty, currentStock, closingBalance = null) {
    const rate = parseFloat($(`input[name="closing_balance[${itemCode}]"]`).data('rate'));
    const amount = saleQty * rate;
    
    // If closingBalance is not provided, calculate it
    if (closingBalance === null) {
        closingBalance = currentStock - saleQty;
    }
    
    // Update all related UI elements
    $(`#sale_qty_${itemCode}`).text(saleQty.toFixed(0));
    $(`#amount_${itemCode}`).text(amount.toFixed(2));
    
    // Update row styling
    const row = $(`input[name="closing_balance[${itemCode}]"]`).closest('tr');
    row.toggleClass('has-quantity', saleQty > 0);
    
    // Update closing balance input styling
    const closingInput = $(`input[name="closing_balance[${itemCode}]"]`);
    closingInput.removeClass('text-warning text-danger fw-bold');
    
    if (closingBalance < 0) {
        closingInput.addClass('text-danger fw-bold');
    } else if (closingBalance < (currentStock * 0.1)) {
        closingInput.addClass('text-warning fw-bold');
    }
}

// Function to save quantity to session via AJAX
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
                // Update items with quantities count
                updateItemsWithQtyCount();
            },
            error: function() {
                console.error('Failed to save quantity to session');
            }
        });
    }, 200);
}

// Function to update items with quantities count
function updateItemsWithQtyCount() {
    let count = 0;
    for (const itemCode in allSessionQuantities) {
        if (allSessionQuantities[itemCode] > 0) {
            count++;
        }
    }
    $('#itemsWithQtyCount').text(count);
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
            const inputField = $(`input[name="closing_balance[${itemCode}]"]`);
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
        }
    }
    
    if (!isValid) {
        let errorMessage = "The following items have insufficient stock:\n\n";
        errorItems.forEach(item => {
            errorMessage += `• Item ${item.code}: Stock ${item.stock.toFixed(0)}, Quantity ${item.qty}\n`;
        });
        errorMessage += "\nPlease adjust quantities to avoid negative closing balance.";
        alert(errorMessage);
    }
    
    return isValid;
}

// Function to update total amounts
function updateTotalAmounts() {
    let totalSaleQty = 0;
    let totalAmount = 0;
    
    // Calculate from all session quantities
    for (const itemCode in allSessionQuantities) {
        const qty = allSessionQuantities[itemCode];
        if (qty > 0) {
            totalSaleQty += qty;
            
            // Get rate from input or allItemsData
            const inputField = $(`input[name="closing_balance[${item_code}]"]`);
            let rate = 0;
            
            if (inputField.length > 0) {
                rate = parseFloat(inputField.data('rate'));
            } else if (allItemsData[itemCode]) {
                rate = parseFloat(allItemsData[itemCode].RPRICE);
            }
            
            totalAmount += qty * rate;
        }
    }
    
    $('#totalSaleQty').text(totalSaleQty.toFixed(0));
    $('#totalAmount').text(totalAmount.toFixed(2));
}

// Function to calculate total amount from visible rows
function calculateTotalAmountFromVisibleRows() {
    let totalSaleQty = 0;
    let totalAmount = 0;
    
    $('.amount-cell').each(function() {
        const amount = parseFloat($(this).text()) || 0;
        totalAmount += amount;
    });
    
    $('.sale-qty-cell').each(function() {
        const qty = parseFloat($(this).text()) || 0;
        totalSaleQty += qty;
    });
    
    $('#totalSaleQty').text(totalSaleQty.toFixed(0));
    $('#totalAmount').text(totalAmount.toFixed(2));
}

// Function to setup row navigation with arrow keys
function setupRowNavigation() {
    const closingInputs = $('input.qty-input');
    let currentRowIndex = -1;
    
    // Highlight row when input is focused
    $(document).on('focus', 'input.qty-input', function() {
        // Remove highlight from all rows
        $('tr').removeClass('highlight-row');
        
        // Add highlight to current row
        $(this).closest('tr').addClass('highlight-row');
        
        // Update current row index
        currentRowIndex = closingInputs.index(this);
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
        if (newIndex >= 0 && newIndex < closingInputs.length) {
            // Focus the input in the new row
            $(closingInputs[newIndex]).focus().select();
        }
    });
}

// Function to clear session quantities via AJAX
function clearSessionQuantities() {
    // Check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot clear quantities.');
        return;
    }
    
    if (!confirm('Are you sure you want to clear all quantities? This action cannot be undone.')) {
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

// Function to show client-side validation alert
function showClientValidationAlert(message) {
    $('#validationMessage').text(message);
    $('#clientValidationAlert').fadeIn();
    
    // Auto-hide after 10 seconds
    setTimeout(() => {
        $('#clientValidationAlert').fadeOut();
    }, 10000);
}

// Function to check stock availability via AJAX before submission
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
            reject('Please enter closing balances for at least one item.');
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

// UPDATED: Function to generate bills immediately with client-side validation and date availability check
function generateBills() {
    // First check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot generate bills.');
        return false;
    }
    
    // NEW: Check date availability for items with quantities
    let availabilityErrors = [];
    $('input.qty-input').each(function() {
        const itemCode = $(this).data('code');
        const itemCounter = $(this).data('counter');
        const currentStock = parseFloat($(this).data('stock'));
        const closingBalance = parseFloat($(this).val()) || 0;
        const saleQty = currentStock - closingBalance;
        
        if (saleQty > 0) {
            const availableDatesCount = countAvailableDates(itemCode, itemCounter);
            if (availableDatesCount === 0) {
                const itemName = allItemsData[itemCode]?.DETAILS || itemCode;
                availabilityErrors.push(`${itemName} (${itemCode})`);
            }
        }
    });
    
    if (availabilityErrors.length > 0) {
        alert('The following items have no available dates in the selected range:\n\n' + 
              availabilityErrors.join('\n') + 
              '\n\nPlease adjust quantities or select a different date range.');
        return false;
    }
    
    // Then validate basic quantities
    if (!validateAllQuantities()) {
        return false;
    }
    
    // Then check stock availability via AJAX
    checkStockAvailabilityBeforeSubmit()
        .then(() => {
            // If validation passes, show progress modal and submit
            $('#billProgressModal').modal('show');
            
            // Simulate progress (in real scenario, this would be server-side)
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += 10;
                $('#billProgressBar').css('width', progress + '%');
                $('#overallProgressText').text(progress + '%');
                
                if (progress >= 100) {
                    clearInterval(progressInterval);
                    // Submit the form
                    document.getElementById('salesForm').submit();
                }
            }, 300);
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
    let totalItems = 0;
    for (const itemCode in allSessionQuantities) {
        if (allSessionQuantities[itemCode] > 0) {
            hasQuantity = true;
            totalItems++;
        }
    }

    if (!hasQuantity) {
        alert('Please enter closing balances for at least one item.');
        return false;
    }

    // Show confirmation dialog with bill preview
    const userChoice = confirm(
        "Generate Bills:\n\n" +
        "Found " + totalItems + " items with quantities.\n\n" +
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
            <title>Sales Log</title>
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
            <h2 class="text-center">Sales Log</h2>
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
    const inputField = $(`input[name="closing_balance[${itemCode}]"]`);
    if (inputField.length > 0) {
        const itemRow = inputField.closest('tr');
        const currentStock = parseFloat(inputField.data('stock'));
        const closingBalance = parseFloat(inputField.val()) || 0;
        const saleQty = currentStock - closingBalance;
        
        return {
            classCode: itemRow.data('class'),
            details: itemRow.data('details'),
            details2: itemRow.data('details2'),
            quantity: saleQty
        };
    } else {
        // If not in current view, get from allItemsData
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

// Function to classify product type from class code
function getProductType(classCode) {
    const spirits = ['W', 'G', 'D', 'K', 'R', 'O'];
    if (spirits.includes(classCode)) return 'SPIRITS';
    if (classCode === 'V') return 'WINE';
    if (classCode === 'F') return 'FERMENTED BEER';
    if (classCode === 'M') return 'MILD BEER';
    if (classCode === 'L') return 'COUNTRY LIQUOR';
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

// OPTIMIZED: Function to update total sales module - PROCESS ONLY ITEMS WITH QTY > 0 FROM ALL MODES
function updateTotalSalesModule() {
    // Initialize empty summary object with ALL sizes
    const allSizes = [
        '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML', 
        '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
        '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
    ];
    
    const salesSummary = {
        'SPIRITS': {},
        'WINE': {},
        'FERMENTED BEER': {},
        'MILD BEER': {},
        'COUNTRY LIQUOR': {}
    };
    
    // Initialize all sizes to 0 for each category
    Object.keys(salesSummary).forEach(category => {
        allSizes.forEach(size => {
            salesSummary[category][size] = 0;
        });
    });

    // Process ONLY session quantities > 0 (optimization) - FROM ALL MODES
    for (const itemCode in allSessionQuantities) {
        const quantity = allSessionQuantities[itemCode];
        if (quantity > 0) {
            // Get item data from ALL items data (works for items not in current view)
            const itemData = getItemData(itemCode);
            if (itemData) {
                const productType = getProductType(itemData.classCode);
                const volume = extractVolume(itemData.details, itemData.details2);
                const volumeColumn = getVolumeColumn(volume);
                
                if (volumeColumn && salesSummary[productType]) {
                    salesSummary[productType][volumeColumn] += quantity;
                }
            }
        }
    }

    // Update the modal table
    updateSalesModalTable(salesSummary, allSizes);
}

// Function to update modal table with calculated values
function updateSalesModalTable(salesSummary, allSizes) {
    const tbody = $('#totalSalesTable tbody');
    tbody.empty();
    
    ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER', 'COUNTRY LIQUOR'].forEach(category => {
        const row = $('<tr>');
        row.append($('<td>').text(category));
        
        allSizes.forEach(size => {
            const value = salesSummary[category][size] || 0;
            const cell = $('<td>').text(value > 0 ? value.toFixed(0) : '');
            
            // Add subtle highlighting for non-zero values
            if (value > 0) {
                cell.addClass('table-success');
            }
            
            row.append(cell);
        });
        
        tbody.append(row);
    });
}

// Print function
function printSalesSummary() {
    const printContent = document.getElementById('totalSalesModal').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = printContent;
    window.print();
    document.body.innerHTML = originalContent;
}

// NEW FUNCTION: Initialize input values from session on page load
function initializeQuantitiesFromSession() {
    $('input[name^="closing_balance"]').each(function() {
        const itemCode = $(this).data('code');
        const currentStock = parseFloat($(this).data('stock'));
        const itemCounter = $(this).data('counter');
        const availability = $(this).data('availability');
        
        if (allSessionQuantities[itemCode] !== undefined) {
            const sessionQty = allSessionQuantities[itemCode];
            // Calculate closing balance: closing_balance = current_stock - sale_qty
            const closingBalance = currentStock - sessionQty;
            $(this).val(closingBalance.toFixed(0));
            
            // Update UI for this item
            updateItemUI(itemCode, sessionQty, currentStock, closingBalance);
            
            // Update distribution if quantity > 0
            if (sessionQty > 0) {
                updateDistributionPreview(itemCode, sessionQty, itemCounter, availability);
            }
        }
    });
    
    // Calculate initial total amounts
    calculateTotalAmountFromVisibleRows();
    updateItemsWithQtyCount();
}

// OPTIMIZED: Document ready
$(document).ready(function() {
    console.log('Document ready - Initializing Sales by Closing Balance...');
    
    // Check if table exists
    if (!tableExists) {
        console.log('Daily stock table not found, disabling functionality');
        $('input.qty-input, button.btn-action, #shuffleBtn, #generateBillsBtn, #clearSessionBtn').prop('disabled', true);
        return;
    }
    
    // Set up row navigation with arrow keys
    setupRowNavigation();
    
    // Initialize quantities in visible inputs from session
    initializeQuantitiesFromSession();
    
    // Clear session button click event
    $('#clearSessionBtn').click(function() {
        clearSessionQuantities();
    });
    
    // Single button with dual functionality
    $('#generateBillsBtn').click(function(e) {
        e.preventDefault();
        handleGenerateBills();
    });
    
    // OPTIMIZED: Event delegation with debouncing for closing balance input
    let closingBalanceTimeout;
    $(document).off('input', 'input.qty-input').on('input', 'input.qty-input', function(e) {
        clearTimeout(closingBalanceTimeout);
        closingBalanceTimeout = setTimeout(() => {
            validateClosingBalance(this);
        }, 200);
    });
    
    // Closing balance input change event
    $(document).on('change', 'input[name^="closing_balance"]', function() {
        // Validate the closing balance
        validateClosingBalance(this);
    });
    
    // OPTIMIZED: Shuffle all button click event - Only shuffle items with qty > 0
    $('#shuffleBtn').off('click').on('click', function() {
        $('input.qty-input').each(function() {
            const itemCode = $(this).data('code');
            const currentStock = parseFloat($(this).data('stock'));
            const closingBalance = parseFloat($(this).val()) || 0;
            const totalQty = currentStock - closingBalance;
            const itemCounter = $(this).data('counter');
            const availability = $(this).data('availability');
            
            // Only shuffle if quantity > 0 and visible
            if (totalQty > 0 && $(this).is(':visible')) {
                updateDistributionPreview(itemCode, totalQty, itemCounter, availability);
            }
        });
        
        // Update total amount
        calculateTotalAmountFromVisibleRows();
    });
    
    // Individual shuffle button click event
    $(document).on('click', '.btn-shuffle-item', function() {
        const itemCode = $(this).data('code');
        const itemCounter = $(this).data('counter');
        const currentStock = parseFloat($(`#closing-input-${itemCounter}`).data('stock'));
        const closingBalance = parseFloat($(`#closing-input-${itemCounter}`).val()) || 0;
        const totalQty = currentStock - closingBalance;
        const availability = $(`#closing-input-${itemCounter}`).data('availability');
        
        // Only shuffle if quantity > 0
        if (totalQty > 0) {
            updateDistributionPreview(itemCode, totalQty, itemCounter, availability);
            
            // Update total amount
            calculateTotalAmountFromVisibleRows();
        }
    });
    
    // Auto-load sales log when modal is shown
    $('#salesLogModal').on('shown.bs.modal', function() {
        loadSalesLog();
    });
    
    // Update total sales module when modal is shown
    $('#totalSalesModal').on('show.bs.modal', function() {
        console.log('Total Sales Modal opened - updating data from ALL modes...');
        updateTotalSalesModule();
    });
    
    // Also update when modal is already shown but data changes
    $('#totalSalesModal').on('shown.bs.modal', function() {
        console.log('Total Sales Modal shown - refreshing data from ALL modes...');
        updateTotalSalesModule();
    });
    
    console.log('Sales by Closing Balance initialized successfully');
});
</script>
</body>
</html>