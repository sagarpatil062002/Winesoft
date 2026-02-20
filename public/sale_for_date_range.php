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

// ============================================================================
// ENHANCED CHRONOLOGICAL INTEGRITY CHECK: GLOBAL BLOCKING
// ============================================================================

/**
 * Check if ANY sales exist for ANY item within or after the given date range
 * Returns array with allowed dates (after latest global sale)
 */
function checkGlobalBackdatedSales($conn, $start_date, $end_date, $comp_id) {
    // Query to get all sales in or after the date range for ANY item
    $query = "SELECT DISTINCT sh.BILL_DATE
              FROM tblsaleheader sh
              WHERE sh.BILL_DATE >= ? 
              AND sh.COMP_ID = ?
              ORDER BY sh.BILL_DATE ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $start_date, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $existing_dates = [];
    while ($row = $result->fetch_assoc()) {
        $existing_dates[] = $row['BILL_DATE'];
    }
    $stmt->close();
    
    // Create date range array
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day'); // Include end date
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($begin, $interval, $end);
    
    $all_dates = [];
    foreach ($date_range as $date) {
        $all_dates[] = $date->format("Y-m-d");
    }
    
    if (!empty($existing_dates)) {
        // Find the latest existing sale date
        $latest_existing = max($existing_dates);
        $latest_existing_date = new DateTime($latest_existing);
        
        // Determine which dates are available (after latest sale date)
        $available_dates = [];
        $unavailable_dates = [];
        
        foreach ($all_dates as $date) {
            $current_date = new DateTime($date);
            if ($current_date > $latest_existing_date) {
                $available_dates[] = $date;
            } else {
                $unavailable_dates[] = $date;
            }
        }
        
        logMessage("GLOBAL CHECK: Latest existing sale: $latest_existing", 'INFO');
        logMessage("Available dates: " . implode(', ', $available_dates), 'INFO');
        logMessage("Unavailable dates (has existing sales): " . implode(', ', $unavailable_dates), 'INFO');
        
        return [
            'restricted' => !empty($unavailable_dates), // Restricted if ANY dates are unavailable
            'latest_existing_sale' => $latest_existing,
            'available_dates' => $available_dates,
            'unavailable_dates' => $unavailable_dates,
            'all_existing_dates' => $existing_dates,
            'message' => !empty($unavailable_dates) ? 
                "Global sales exist on: " . implode(', ', $unavailable_dates) . ". Available dates: " . implode(', ', $available_dates) :
                "No sales restrictions"
        ];
    }
    
    return [
        'restricted' => false,
        'latest_existing_sale' => null,
        'available_dates' => $all_dates, // All dates available if no existing sales
        'unavailable_dates' => [],
        'all_existing_dates' => [],
        'message' => "No global sales restrictions"
    ];
}

// ============================================================================
// DRY DAY VALIDATION
// ============================================================================

/**
 * Check if any dry days fall within the date range
 */
function checkDryDaysInRange($conn, $start_date, $end_date) {
    $dryDaysManager = new DryDaysManager($conn);
    $dry_days = $dryDaysManager->getDryDaysInRange($start_date, $end_date);
    
    if (!empty($dry_days)) {
        logMessage("DRY DAYS FOUND: " . implode(', ', array_keys($dry_days)), 'INFO');
    }
    
    return [
        'has_dry_days' => !empty($dry_days),
        'dry_days' => $dry_days,
        'dry_dates' => array_keys($dry_days),
        'message' => !empty($dry_days) ? 
            "Dry days found: " . implode(', ', array_keys($dry_days)) : 
            "No dry days in selected range"
    ];
}

/**
 * Validate both global sales and dry days restrictions
 */
function validateDateRangeRestrictions($conn, $start_date, $end_date, $comp_id) {
    // Check global sales restrictions
    $global_check = checkGlobalBackdatedSales($conn, $start_date, $end_date, $comp_id);
    
    // Check dry days
    $dry_days_check = checkDryDaysInRange($conn, $start_date, $end_date);
    
    // Combine restrictions - a date is unavailable if it has sales OR is a dry day
    $all_unavailable_dates = array_merge(
        $global_check['unavailable_dates'],
        $dry_days_check['dry_dates']
    );
    
    // Remove duplicates
    $all_unavailable_dates = array_unique($all_unavailable_dates);
    sort($all_unavailable_dates);
    
    // Calculate available dates (all dates minus unavailable)
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($begin, $interval, $end);
    
    $all_dates = [];
    foreach ($date_range as $date) {
        $all_dates[] = $date->format("Y-m-d");
    }
    
    $available_dates = array_diff($all_dates, $all_unavailable_dates);
    $available_dates = array_values($available_dates); // Re-index
    
    // Prepare messages
    $messages = [];
    if ($global_check['restricted']) {
        $messages[] = "Existing sales on: " . implode(', ', $global_check['unavailable_dates']);
    }
    if ($dry_days_check['has_dry_days']) {
        $messages[] = "Dry days: " . implode(', ', $dry_days_check['dry_dates']);
    }
    
    return [
        'restricted' => !empty($all_unavailable_dates),
        'global_restricted' => $global_check['restricted'],
        'has_dry_days' => $dry_days_check['has_dry_days'],
        'latest_existing_sale' => $global_check['latest_existing_sale'],
        'available_dates' => $available_dates,
        'unavailable_dates' => $all_unavailable_dates,
        'unavailable_sales_dates' => $global_check['unavailable_dates'],
        'dry_dates' => $dry_days_check['dry_dates'],
        'dry_days_info' => $dry_days_check['dry_days'],
        'message' => !empty($messages) ? implode(' | ', $messages) : "No restrictions",
        'full_message' => !empty($messages) ? 
            "<strong>Date Range Restrictions:</strong><br>" . implode('<br>', $messages) . 
            "<br><strong>Available dates:</strong> " . (empty($available_dates) ? 'None' : implode(', ', $available_dates)) :
            "No date range restrictions"
    ];
}

/**
 * NEW: Get unavailable dates due to global sales and dry days
 */
function getUnavailableDates($conn, $start_date, $end_date, $comp_id) {
    $restrictions = validateDateRangeRestrictions($conn, $start_date, $end_date, $comp_id);
    return $restrictions['unavailable_dates'];
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
$current_stock_column = "Current_Stock" . $comp_id;
$opening_stock_column = "Opening_Stock" . $comp_id;

// Check if the stock columns exist, if not create them
// Cache this check in session to avoid repeated queries
if (!isset($_SESSION['stock_columns_checked'])) {
    $check_column_query = "SHOW COLUMNS FROM tblitem_stock LIKE '$current_stock_column'";
    $column_result = $conn->query($check_column_query);

    if ($column_result->num_rows == 0) {
        $alter_query = "ALTER TABLE tblitem_stock 
                        ADD COLUMN $opening_stock_column DECIMAL(10,3) DEFAULT 0.000,
                        ADD COLUMN $current_stock_column DECIMAL(10,3) DEFAULT 0.000";
        if (!$conn->query($alter_query)) {
            die("Error creating stock columns: " . $conn->error);
        }
    }
    $_SESSION['stock_columns_checked'] = true;
}

// NEW: Calculate the day number for the end_date to get closing balance
$end_date_day = date('d', strtotime($end_date));
$closing_column = "DAY_" . sprintf('%02d', $end_date_day) . "_CLOSING";
$end_date_month = date('Y-m', strtotime($end_date));

// NEW: Determine which daily stock table to use based on end_date
$current_month = date('Y-m'); // Current month in "YYYY-MM" format
$end_date_month_year = date('m_Y', strtotime($end_date)); // e.g., "12_2025"

if ($end_date_month === $current_month) {
    // Use current month table (no suffix)
    $daily_stock_table = "tbldailystock_" . $comp_id;
    $table_suffix = "";
} else {
    // Use archived month table (with suffix mm_yyyy)
    $end_date_month_short = date('m', strtotime($end_date)); // e.g., "12"
    $end_date_year_short = date('y', strtotime($end_date)); // e.g., "25"
    $daily_stock_table = "tbldailystock_" . $comp_id . "_" . $end_date_month_short . "_" . $end_date_year_short;
    $table_suffix = "_" . $end_date_month_short . "_" . $end_date_year_short;
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

// Check if the required daily stock table exists
$check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
$table_result = $conn->query($check_table_query);
$table_exists = $table_result->num_rows > 0;

if (!$table_exists) {
    // Table doesn't exist, create it with proper structure
    $create_table_query = "CREATE TABLE IF NOT EXISTS $daily_stock_table (
        ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ITEM_CODE VARCHAR(50) NOT NULL,
        STK_MONTH VARCHAR(7) NOT NULL,
        DAY_01_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_SALES DECimal(10,3) DEFAULT 0.000,
        DAY_17_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        LAST_UPDATED TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_item_month (ITEM_CODE, STK_MONTH),
        KEY idx_item_code (ITEM_CODE),
        KEY idx_stk_month (STK_MONTH)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($create_table_query)) {
        logMessage("Created daily stock table: $daily_stock_table");
        $table_exists = true;
        
        // Initialize the table with items from current month if it's an archive table
        if ($end_date_month !== $current_month) {
            initializeArchiveTable($conn, $comp_id, $end_date_month, $daily_stock_table);
        }
    } else {
        logMessage("Failed to create daily stock table: " . $conn->error, 'ERROR');
    }
}

// MODIFIED: Get total count for pagination with license filtering AND stock > 0 condition
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // UPDATED: Count query that checks for stock > 0 - CORRECTED FILTER
    $count_query = "SELECT COUNT(DISTINCT im.CODE) as total 
                    FROM tblitemmaster im
                    LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                        AND ds.STK_MONTH = ?
                    WHERE im.LIQ_FLAG = ? 
                    AND im.CLASS IN ($class_placeholders)
                    AND COALESCE(ds.$closing_column, 0) > 0"; // CHANGED: Only show items with stock > 0
    
    $count_params = array_merge([$end_date_month, $mode], $allowed_classes);
    $count_types = str_repeat('s', count($count_params));
} else {
    // If no classes allowed or table doesn't exist, show empty result
    $count_query = "SELECT COUNT(*) as total FROM tblitemmaster im WHERE 1 = 0";
    $count_params = [];
    $count_types = "";
}

if ($search !== '') {
    $count_query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_types .= "ss";
}

$count_stmt = $conn->prepare($count_query);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_items = $count_result->fetch_assoc()['total'];
$count_stmt->close();

// MODIFIED: Main query with pagination, license filtering AND stock > 0 condition
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // UPDATED: Query that fetches closing balance from daily stock table for end_date - WITH stock > 0 filter
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                     ds.STK_MONTH as stock_month
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                  AND ds.STK_MONTH = ?
              WHERE im.LIQ_FLAG = ? 
              AND im.CLASS IN ($class_placeholders)
              AND COALESCE(ds.$closing_column, 0) > 0"; // CHANGED: Only show items with stock > 0
    
    $params = array_merge([$end_date_month, $mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
    // If no classes allowed or table doesn't exist, show empty result
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                     ds.STK_MONTH as stock_month
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                  AND ds.STK_MONTH = ?
              WHERE 1 = 0";
    $params = [$end_date_month];
    $types = "s";
}

if ($search !== '') {
    $query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " " . $order_clause . " LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate total pages
$total_pages = ceil($total_items / $items_per_page);

// ============================================================================
// FIXED: SESSION QUANTITY PRESERVATION WITH PAGINATION
// ============================================================================

// Initialize session if not exists
if (!isset($_SESSION['sale_quantities'])) {
    $_SESSION['sale_quantities'] = [];
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

// Function to initialize archive table with data from current month
function initializeArchiveTable($conn, $comp_id, $archive_month, $archive_table) {
    $current_table = "tbldailystock_" . $comp_id;
    
    // Check if current table exists
    $check_current = "SHOW TABLES LIKE '$current_table'";
    $current_exists = $conn->query($check_current)->num_rows > 0;
    
    if (!$current_exists) {
        logMessage("Current table $current_table doesn't exist, cannot initialize archive", 'WARNING');
        return false;
    }
    
    // Copy structure and data from current table for the previous month end
    $prev_month = date('Y-m', strtotime($archive_month . ' -1 month'));
    
    // Get items from current table that have stock
    $copy_query = "INSERT INTO $archive_table (ITEM_CODE, STK_MONTH, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING)
                   SELECT ITEM_CODE, ?, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING 
                   FROM $current_table 
                   WHERE STK_MONTH = ?";
    
    $copy_stmt = $conn->prepare($copy_query);
    $copy_stmt->bind_param("ss", $archive_month, $prev_month);
    
    if ($copy_stmt->execute()) {
        $affected_rows = $copy_stmt->affected_rows;
        logMessage("Initialized archive table $archive_table with $affected_rows items from $prev_month");
        $copy_stmt->close();
        return true;
    } else {
        logMessage("Failed to initialize archive table: " . $copy_stmt->error, 'ERROR');
        $copy_stmt->close();
        return false;
    }
}

// MODIFIED: Get ALL items data for JavaScript from ALL modes for Total Sales Summary
// NEW: Also get stock from daily stock table for end_date - WITH stock > 0 filter
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS, im.LIQ_FLAG, im.RPRICE,
                               COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                               ds.STK_MONTH as stock_month
                        FROM tblitemmaster im
                        LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                            AND ds.STK_MONTH = ?
                        WHERE im.CLASS IN ($class_placeholders) 
                        AND COALESCE(ds.$closing_column, 0) > 0"; // CHANGED: Only include items with stock > 0
    
    $all_items_stmt = $conn->prepare($all_items_query);
    $all_items_params = array_merge([$end_date_month], $allowed_classes);
    $all_items_types = str_repeat('s', count($all_items_params));
    $all_items_stmt->bind_param($all_items_types, ...$all_items_params);
} else {
    $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS, im.LIQ_FLAG, im.RPRICE,
                               COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                               ds.STK_MONTH as stock_month
                        FROM tblitemmaster im
                        LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                            AND ds.STK_MONTH = ?
                        WHERE 1 = 0";
    
    $all_items_stmt = $conn->prepare($all_items_query);
    $all_items_params = [$end_date_month];
    $all_items_types = "s";
    $all_items_stmt->bind_param($all_items_types, ...$all_items_params);
}

$all_items_stmt->execute();
$all_items_result = $all_items_stmt->get_result();
$all_items_data = [];
while ($row = $all_items_result->fetch_assoc()) {
    $all_items_data[$row['CODE']] = $row;
}
$all_items_stmt->close();

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

// ============================================================================
// NEW: GET UNAVAILABLE DATES (GLOBAL SALES + DRY DAYS)
// ============================================================================
$restrictions = validateDateRangeRestrictions($conn, $start_date, $end_date, $comp_id);
$unavailable_dates_global = $restrictions['unavailable_dates'];
$dry_dates = $restrictions['dry_dates'];
$has_restrictions = $restrictions['restricted'];
$available_dates_global = $restrictions['available_dates'];

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

// ============================================================================
// NEW: ENHANCED DISTRIBUTION LOGIC WITH GLOBAL BLOCKING AND DRY DAYS
// ============================================================================

/**
 * Enhanced distribution function that handles global restrictions
 * Distributes only across available dates (after latest global sale, excluding dry days)
 */
function distributeSalesWithGlobalRestrictions($total_qty, $available_dates) {
    if ($total_qty <= 0 || empty($available_dates)) return [];
    
    $available_days_count = count($available_dates);
    
    // Distribute across available dates
    $base_qty = floor($total_qty / $available_days_count);
    $remainder = $total_qty % $available_days_count;
    
    $distribution = array_fill(0, $available_days_count, $base_qty);
    
    // Distribute remainder evenly
    for ($i = 0; $i < $remainder; $i++) {
        $distribution[$i]++;
    }
    
    // Shuffle the distribution to make it look more natural
    shuffle($distribution);
    
    return $distribution;
}

/**
 * Get final distribution array for all dates (with zeros for unavailable dates)
 */
function getFullDistribution($total_qty, $date_array, $available_dates) {
    $full_distribution = array_fill(0, count($date_array), 0);
    
    if ($total_qty <= 0 || empty($available_dates)) {
        return $full_distribution;
    }
    
    // Create date index map
    $date_index_map = [];
    foreach ($date_array as $index => $date) {
        $date_index_map[$date] = $index;
    }
    
    // Get distribution for available dates
    $distribution = distributeSalesWithGlobalRestrictions($total_qty, $available_dates);
    
    // Fill in the distribution
    foreach ($available_dates as $i => $date) {
        $index = $date_index_map[$date] ?? null;
        if ($index !== null) {
            $full_distribution[$index] = $distribution[$i] ?? 0;
        }
    }
    
    return $full_distribution;
}

// Function to get the correct daily stock table for a specific date with validation
function getDailyStockTableForDate($conn, $comp_id, $date) {
    $current_date = new DateTime();
    $sale_date = new DateTime($date);
    
    // If sale date is in the future, use current month table
    if ($sale_date > $current_date) {
        logMessage("Sale date $date is in future, using current month table", 'WARNING');
        return "tbldailystock_" . $comp_id;
    }
    
    $current_month = $current_date->format('Y-m'); // Current month in "YYYY-MM" format
    $date_month = $sale_date->format('Y-m'); // Date month in "YYYY-MM" format
    
    if ($date_month === $current_month) {
        // Use current month table (no suffix)
        return "tbldailystock_" . $comp_id;
    } else {
        // Use archived month table (with suffix mm_yy)
        $date_month_short = $sale_date->format('m'); // e.g., "12"
        $date_year_short = $sale_date->format('y'); // e.g., "25"
        return "tbldailystock_" . $comp_id . "_" . $date_month_short . "_" . $date_year_short;
    }
}

// ENHANCED: Function to recalculate daily stock from a specific day onward with proper rollover
function recalculateDailyStockFromDay($conn, $table_name, $item_code, $stk_month, $start_day = 1) {
    logMessage("Recalculating stock from day $start_day for item $item_code in $stk_month in table $table_name", 'INFO');
    
    // Get the current date to know if we're dealing with current or future month
    $current_date = new DateTime();
    $table_month = new DateTime($stk_month . '-01');
    
    // Get last day of this month
    $last_day_of_month = date('t', strtotime($stk_month . '-01'));
    
    // Start from the specified day and recalculate forward
    for ($day = $start_day; $day <= 31; $day++) {
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
            
            // Calculate closing using the formula: Closing = Opening + Purchase - Sales
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
            
            // Set next day's opening to this day's closing (but only within same month)
            $next_day = $day + 1;
            if ($next_day <= $last_day_of_month && $next_day <= 31) {
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
            
            logMessage("Day $day: Opening=$opening, Purchase=$purchase, Sales=$sales, Closing=$closing", 'DEBUG');
        }
        $day_stmt->close();
        
        // Break if we've processed all days of the month
        if ($day >= $last_day_of_month) {
            break;
        }
    }
    
    // Handle month-to-month rollover
    if ($start_day == 1) {
        // If we're recalculating from day 1, we need to ensure consistency with previous month
        $prev_month = date('Y-m', strtotime($stk_month . '-01 -1 month'));
        if ($prev_month) {
            $prev_table = getDailyStockTableForDate($conn, $_SESSION['CompID'], $prev_month . '-01');
            
            // Check if previous month table exists
            $check_prev_table = "SHOW TABLES LIKE '$prev_table'";
            if ($conn->query($check_prev_table)->num_rows > 0) {
                // Get last day of previous month
                $prev_last_day = date('d', strtotime('last day of ' . $prev_month));
                $prev_closing_column = "DAY_" . sprintf('%02d', $prev_last_day) . "_CLOSING";
                
                // Get previous month's closing
                $prev_query = "SELECT $prev_closing_column FROM $prev_table 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prev_stmt = $conn->prepare($prev_query);
                $prev_stmt->bind_param("ss", $prev_month, $item_code);
                if ($prev_stmt->execute()) {
                    $prev_result = $prev_stmt->get_result();
                    if ($prev_result->num_rows > 0) {
                        $prev_row = $prev_result->fetch_assoc();
                        $prev_closing = $prev_row[$prev_closing_column] ?? 0;
                        
                        // Update current month's day 1 opening to match previous month's closing
                        $update_opening_query = "UPDATE $table_name 
                                                SET DAY_01_OPEN = ?,
                                                    LAST_UPDATED = CURRENT_TIMESTAMP 
                                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                        $update_opening_stmt = $conn->prepare($update_opening_query);
                        $update_opening_stmt->bind_param("dss", $prev_closing, $stk_month, $item_code);
                        $update_opening_stmt->execute();
                        $update_opening_stmt->close();
                        
                        logMessage("Updated DAY_01_OPEN to $prev_closing based on previous month's closing", 'INFO');
                    }
                }
                $prev_stmt->close();
            }
        }
    }
    
    logMessage("Completed recalculating stock for $stk_month from day $start_day", 'INFO');
}

// ============================================================================
// FIXED: ENHANCED DAILY STOCK UPDATE WITH PROPER CASCADING TO TODAY'S DATE
// ============================================================================

// ENHANCED: Function to update daily stock table with MULTI-MONTH cascading updates
function updateDailyStock($conn, $item_code, $sale_date, $qty, $comp_id) {
    logMessage("Starting daily stock update for item $item_code sold on $sale_date (Qty: $qty)", 'INFO');
    
    // Get the correct table for the sale date
    $sale_daily_stock_table = getDailyStockTableForDate($conn, $comp_id, $sale_date);
    
    // Extract day number from date (e.g., 2025-09-27 → day 27)
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $sales_column = "DAY_{$day_num}_SALES";
    $closing_column = "DAY_{$day_num}_CLOSING";
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    
    $month_year_full = date('Y-m', strtotime($sale_date)); // e.g., "2025-09"
    $sale_date_obj = new DateTime($sale_date);
    $current_date = new DateTime();
    $current_month = $current_date->format('Y-m');
    
    // ============================================================================
    // STEP 1: UPDATE THE STOCK TABLE FOR THE SALE DATE
    // ============================================================================
    
    // First, check if the required table exists
    $check_table_query = "SHOW TABLES LIKE '$sale_daily_stock_table'";
    $table_result = $conn->query($check_table_query);
    
    if ($table_result->num_rows == 0) {
        // Table doesn't exist, create it
        createDailyStockTable($conn, $sale_daily_stock_table);
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
        // Record doesn't exist, create it with initial values
        $check_stmt->close();
        
        // Get previous month's closing if available
        $prev_month = date('Y-m', strtotime($month_year_full . '-01 -1 month'));
        $prev_table = getDailyStockTableForDate($conn, $comp_id, $prev_month . '-01');
        
        $prev_closing = 0;
        if ($prev_month) {
            // Get last day of previous month
            $prev_last_day = date('d', strtotime('last day of ' . $prev_month));
            $prev_closing_column = "DAY_" . sprintf('%02d', $prev_last_day) . "_CLOSING";
            
            $prev_query = "SELECT $prev_closing_column FROM $prev_table 
                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $prev_stmt = $conn->prepare($prev_query);
            $prev_stmt->bind_param("ss", $prev_month, $item_code);
            if ($prev_stmt->execute()) {
                $prev_result = $prev_stmt->get_result();
                if ($prev_result->num_rows > 0) {
                    $prev_row = $prev_result->fetch_assoc();
                    $prev_closing = $prev_row[$prev_closing_column] ?? 0;
                }
            }
            $prev_stmt->close();
        }
        
        // Insert new record
        $insert_query = "INSERT INTO $sale_daily_stock_table 
                        (ITEM_CODE, STK_MONTH, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                        VALUES (?, ?, ?, 0, 0, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssdd", $item_code, $month_year_full, $prev_closing, $prev_closing);
        $insert_stmt->execute();
        $insert_stmt->close();
        
        // Now get the record
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ss", $month_year_full, $item_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
    }
    
    $current_values = $check_result->fetch_assoc();
    $check_stmt->close();
    
    $current_closing = $current_values[$closing_column] ?? 0;
    $current_opening = $current_values[$opening_column] ?? 0;
    $current_purchase = $current_values[$purchase_column] ?? 0;
    $current_sales = $current_values[$sales_column] ?? 0;
    
    // Validate closing stock is sufficient for the sale quantity
    if ($current_closing < $qty) {
        // Try to get stock from another source or calculate from opening + purchase
        $available_stock = $current_opening + $current_purchase - $current_sales;
        if ($available_stock < $qty) {
            throw new Exception("Insufficient closing stock for item $item_code on $sale_date. Available: $available_stock, Requested: $qty");
        }
        // If we got here, use the calculated available stock
        $current_closing = $available_stock;
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
    
    // ============================================================================
    // STEP 2: CASCADE UPDATES TO SUBSEQUENT DAYS IN THE SAME MONTH
    // ============================================================================
    
    recalculateDailyStockFromDay($conn, $sale_daily_stock_table, $item_code, $month_year_full, $day_num);
    
    // ============================================================================
    // STEP 3: CASCADE TO CURRENT MONTH (CRITICAL FIX - THIS WAS MISSING)
    // ============================================================================
    
    logMessage("Starting cascading to current month for item $item_code sold on $sale_date", 'INFO');
    
    // If sale is not in current month, we need to cascade to current month
    if ($month_year_full < $current_month) {
        logMessage("Sale in archived month $month_year_full, cascading to current month $current_month", 'INFO');
        
        // Create a month iterator starting from sale month
        $current_month_obj = new DateTime($month_year_full . '-01');
        
        while (true) {
            // Move to next month
            $current_month_obj->modify('+1 month');
            $next_month = $current_month_obj->format('Y-m');
            
            // Stop if we've reached beyond current month
            if ($next_month > $current_month) {
                logMessage("Reached month $next_month which is beyond current month $current_month, stopping cascade", 'INFO');
                break;
            }
            
            // Get the table for this month
            $next_month_table = getDailyStockTableForDate($conn, $comp_id, $next_month . '-01');
            
            // Check if table exists
            $check_table = "SHOW TABLES LIKE '$next_month_table'";
            if ($conn->query($check_table)->num_rows == 0) {
                // Create the table
                createDailyStockTable($conn, $next_month_table);
                logMessage("Created table $next_month_table for cascading", 'INFO');
            }
            
            // Get previous month's closing
            $prev_month = date('Y-m', strtotime($next_month . '-01 -1 month'));
            $prev_table = getDailyStockTableForDate($conn, $comp_id, $prev_month . '-01');
            $prev_last_day = date('d', strtotime('last day of ' . $prev_month));
            $prev_closing_column = "DAY_" . sprintf('%02d', $prev_last_day) . "_CLOSING";
            
            // Get previous month's closing
            $prev_closing = 0;
            if ($prev_month) {
                $prev_query = "SELECT $prev_closing_column FROM $prev_table 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prev_stmt = $conn->prepare($prev_query);
                $prev_stmt->bind_param("ss", $prev_month, $item_code);
                if ($prev_stmt->execute()) {
                    $prev_result = $prev_stmt->get_result();
                    if ($prev_result->num_rows > 0) {
                        $prev_row = $prev_result->fetch_assoc();
                        $prev_closing = $prev_row[$prev_closing_column] ?? 0;
                    } else {
                        // If no record in previous month, use 0
                        $prev_closing = 0;
                    }
                }
                $prev_stmt->close();
            }
            
            // Update or create record in next month
            $check_record = "SELECT DAY_01_OPEN FROM $next_month_table 
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $check_stmt = $conn->prepare($check_record);
            $check_stmt->bind_param("ss", $next_month, $item_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows == 0) {
                // Create new record
                $check_stmt->close();
                $insert_query = "INSERT INTO $next_month_table 
                                (ITEM_CODE, STK_MONTH, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                                VALUES (?, ?, ?, 0, 0, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("ssdd", $item_code, $next_month, $prev_closing, $prev_closing);
                $insert_stmt->execute();
                $insert_stmt->close();
                logMessage("Inserted record for $item_code in $next_month with opening $prev_closing", 'INFO');
            } else {
                // Update existing record
                $check_stmt->close();
                $update_query = "UPDATE $next_month_table 
                               SET DAY_01_OPEN = ?,
                                   LAST_UPDATED = CURRENT_TIMESTAMP 
                               WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("dss", $prev_closing, $next_month, $item_code);
                $update_stmt->execute();
                $update_stmt->close();
                logMessage("Updated opening for $item_code in $next_month to $prev_closing", 'INFO');
            }
            
            // Recalculate the entire month
            recalculateDailyStockFromDay($conn, $next_month_table, $item_code, $next_month, 1);
            
            logMessage("Completed cascading for month $next_month", 'INFO');
            
            // Break after updating current month
            if ($next_month >= $current_month) {
                logMessage("Reached current month $current_month, stopping cascading", 'INFO');
                break;
            }
        }
    }
    
    // ============================================================================
    // STEP 4: UPDATE CURRENT MONTH'S STOCK IF SALE DATE IS IN ARCHIVED MONTH
    // ============================================================================
    
    // Get current month table
    $current_daily_stock_table = "tbldailystock_" . $comp_id;
    
    // If sale is in archived month, update current month's stock
    if ($month_year_full < $current_month) {
        logMessage("Sale in archived month, updating current month's stock", 'INFO');
        
        // Get previous month (the month before current month)
        $prev_month_of_current = date('Y-m', strtotime($current_month . '-01 -1 month'));
        
        if ($prev_month_of_current == $month_year_full) {
            // Sale was in the month just before current month, update current month's opening
            $current_record_check = "SELECT DAY_01_OPEN FROM $current_daily_stock_table 
                                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $current_check_stmt = $conn->prepare($current_record_check);
            $current_check_stmt->bind_param("ss", $current_month, $item_code);
            $current_check_stmt->execute();
            $current_check_result = $current_check_stmt->get_result();
            
            if ($current_check_result->num_rows > 0) {
                // Update current month's opening (deduct the sale)
                $update_current_query = "UPDATE $current_daily_stock_table 
                                        SET DAY_01_OPEN = DAY_01_OPEN - ?,
                                            LAST_UPDATED = CURRENT_TIMESTAMP 
                                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_current_stmt = $conn->prepare($update_current_query);
                $update_current_stmt->bind_param("dss", $qty, $current_month, $item_code);
                $update_current_stmt->execute();
                $update_current_stmt->close();
                
                // Recalculate current month
                recalculateDailyStockFromDay($conn, $current_daily_stock_table, $item_code, $current_month, 1);
                logMessage("Updated current month's opening by deducting $qty", 'INFO');
            }
            $current_check_stmt->close();
        }
    }
    
    logMessage("Daily stock updated successfully for item $item_code on $sale_date in table $sale_daily_stock_table: Sales=$new_sales, Closing=$new_closing", 'INFO');
    
    return true;
}

// Helper function to create daily stock table
function createDailyStockTable($conn, $table_name) {
    $create_query = "CREATE TABLE IF NOT EXISTS $table_name (
        ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ITEM_CODE VARCHAR(50) NOT NULL,
        STK_MONTH VARCHAR(7) NOT NULL,
        DAY_01_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_SALES DECimal(10,3) DEFAULT 0.000,
        DAY_08_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_PURCHASE DECimal(10,3) DEFAULT 0.000,
        DAY_18_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_PURCHASE DECimal(10,3) DEFAULT 0.000,
        DAY_29_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        LAST_UPDATED TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_item_month (ITEM_CODE, STK_MONTH),
        KEY idx_item_code (ITEM_CODE),
        KEY idx_stk_month (STK_MONTH)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($create_query)) {
        logMessage("Created daily stock table: $table_name", 'INFO');
        return true;
    } else {
        logMessage("Failed to create daily stock table: " . $conn->error, 'ERROR');
        return false;
    }
}

// FIXED: Function to get next bill number with proper zero-padding
// UPDATED: Function to get next bill number with proper zero-padding AND CompID consideration
function getNextBillNumber($conn, $comp_id) {
    logMessage("Getting next bill number for CompID: $comp_id", 'INFO');
    
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
        logMessage("Next bill number for CompID $comp_id: $next_bill", 'INFO');
        
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
        logMessage("Starting bulk sales operation with " . count($_SESSION['sale_quantities']) . " items - Performance mode enabled", 'INFO');
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
            
            // NEW: Enhanced global backdated sales check with dry days
            $restrictions = validateDateRangeRestrictions($conn, $start_date, $end_date, $comp_id);
            
            if ($restrictions['restricted'] && empty($restrictions['available_dates'])) {
                $error_message = "<strong>Cannot enter sales in the selected date range:</strong><br><br>";
                
                if (!empty($restrictions['unavailable_sales_dates'])) {
                    $error_message .= "<div class='mb-2'>";
                    $error_message .= "<strong>Existing Sales Dates:</strong> " . implode(', ', $restrictions['unavailable_sales_dates']) . "<br>";
                    $error_message .= "</div>";
                }
                
                if (!empty($restrictions['dry_dates'])) {
                    $dryDaysManager = new DryDaysManager($conn);
                    $dry_days_info = $restrictions['dry_days_info'];
                    
                    $error_message .= "<div class='mb-2'>";
                    $error_message .= "<strong>Dry Days in Range:</strong><br>";
                    foreach ($restrictions['dry_dates'] as $dry_date) {
                        $description = $dry_days_info[$dry_date] ?? 'Dry Day';
                        $error_message .= "<span class='text-danger'>• $dry_date - $description</span><br>";
                    }
                    $error_message .= "</div>";
                }
                
                $error_message .= "<br><div class='alert alert-warning mt-2'>";
                $error_message .= "<strong>Latest Global Sale:</strong> " . ($restrictions['latest_existing_sale'] ?? 'None') . "<br>";
                $error_message .= "<strong>Available Dates:</strong> " . (empty($restrictions['available_dates']) ? 'None' : implode(', ', $restrictions['available_dates']));
                $error_message .= "</div>";
                
                logMessage("Global backdated/dry day sales prevented - No available dates", 'WARNING');
                
                // Skip further processing
                goto render_page;
            }
            
            // NEW: Get closing column for end_date
            $end_date_day = date('d', strtotime($end_date));
            $closing_column = "DAY_" . sprintf('%02d', $end_date_day) . "_CLOSING";
            $end_date_month = date('Y-m', strtotime($end_date));
            
            // MODIFIED: Get ALL items from database for validation WITHOUT mode filtering
            // NEW: Get stock from daily stock table for end_date - WITH stock > 0 filter
            if (!empty($allowed_classes)) {
                $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
                $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, im.LIQ_FLAG,
                                           COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                                           ds.STK_MONTH as stock_month
                                    FROM tblitemmaster im
                                    LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                                        AND ds.STK_MONTH = ?
                                    WHERE im.CLASS IN ($class_placeholders)
                                    AND COALESCE(ds.$closing_column, 0) > 0"; // CHANGED: Only get items with stock > 0
                
                $all_items_stmt = $conn->prepare($all_items_query);
                $all_items_params = array_merge([$end_date_month], $allowed_classes);
                $all_items_types = str_repeat('s', count($all_items_params));
                $all_items_stmt->bind_param($all_items_types, ...$all_items_params);
            } else {
                $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, im.LIQ_FLAG,
                                           COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                                           ds.STK_MONTH as stock_month
                                    FROM tblitemmaster im
                                    LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                                        AND ds.STK_MONTH = ?
                                    WHERE 1 = 0";
                
                $all_items_stmt = $conn->prepare($all_items_query);
                $all_items_params = [$end_date_month];
                $all_items_types = "s";
                $all_items_stmt->bind_param($all_items_types, ...$all_items_params);
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
                        logMessage("Stock validation progress: $item_count/" . count($_SESSION['sale_quantities']) . " items checked", 'INFO');
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
                                logMessage("Processing progress: $item_count/" . count($_SESSION['sale_quantities']) . " items processed", 'INFO');
                            }
                            
                            if ($total_qty > 0 && isset($all_items[$item_code])) {
                                $item = $all_items[$item_code];
                                
                                // NEW: Use global restrictions for distribution
                                $full_distribution = getFullDistribution($total_qty, $date_array, $available_dates_global);
                                $daily_sales_data[$item_code] = $full_distribution;
                                
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
                        // FIXED: Use volume_limit_utils.php function for bill generation
                        $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
                        
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
                                logMessage("Bill generation progress: $bill_count/$total_bills bills created", 'INFO');
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
                                
                                // Update item stock
                                updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);

                                // Update daily stock with cascading logic - USING THE FIXED FUNCTION
                                updateDailyStock($conn, $item['code'], $bill['bill_date'], $item['qty'], $comp_id);
                            }
                            
                            $total_amount += $bill['total_amount'];
                        }

                        // ============================================================================
                        // OPTIMIZED CASH MEMO GENERATION - PERFORMANCE SAFE
                        // ============================================================================
                        $cash_memos_generated = 0;
                        $cash_memo_errors = [];

                        if (count($bills) > 0) {
                            logMessage("Starting optimized cash memo generation for " . count($bills) . " bills", 'INFO');
                            
                            $cash_memo_start_time = time();
                            $MAX_CASH_MEMO_TIME = 30; // seconds - safety limit (increased for cascading updates)
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
                                        logMessage("Cash memo generated for bill: $padded_bill_no", 'INFO');
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
                            
                            logMessage("Cash memo generation completed: $cash_memos_generated successful, " . count($cash_memo_errors) . " failed", 'INFO');
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
                            logMessage("Bulk sales operation completed successfully", 'INFO');
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

render_page:

// Check for success message in URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// Log final state for debugging
logMessage("Final session quantities count: " . count($_SESSION['sale_quantities']), 'INFO');
logMessage("Items in current view: " . count($items), 'INFO');

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
    'end_date_day' => $end_date_day, // NEW: Added end date info
    'closing_column' => $closing_column, // NEW: Added closing column info
    'end_date_month' => $end_date_month, // NEW: Added month info
    'stock_filter' => '> 0', // NEW: Added stock filter info
    'daily_stock_table' => $daily_stock_table, // NEW: Added table name info
    'table_suffix' => $table_suffix, // NEW: Added table suffix info
    'current_month' => date('Y-m'), // NEW: Added current month info
    'has_restrictions' => $has_restrictions, // NEW: Global restriction info
    'available_dates' => $available_dates_global, // NEW: Available dates
    'unavailable_dates' => $unavailable_dates_global, // NEW: Unavailable dates
    'dry_dates' => $dry_dates, // NEW: Dry dates
    'latest_global_sale' => $restrictions['latest_existing_sale'] ?? null // NEW: Latest global sale
];
logArray($debug_info, "Sales Page Load Debug Info");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales by Date Range - liqoursoft</title>
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

/* Global restriction styles */
tr.global-restriction {
    background-color: #f8d7da !important;
    border-left: 4px solid #dc3545 !important;
}

tr.global-restriction td {
    color: #721c24 !important;
    font-weight: 600;
}

tr.global-restriction .qty-input {
    background-color: #f5c6cb !important;
    border-color: #f5c6cb !important;
    color: #721c24 !important;
    cursor: not-allowed !important;
}

/* Dry day specific styling */
.dry-date {
    background-color: #fff3cd !important;
    border-left: 3px solid #ffc107 !important;
}

.dry-date td {
    color: #856404 !important;
}

.dry-date .qty-input {
    background-color: #fff3cd !important;
    border-color: #ffc107 !important;
    color: #856404 !important;
    cursor: not-allowed !important;
}

.badge.bg-danger {
    font-size: 10px;
    padding: 3px 8px;
    max-width: 200px;
    white-space: normal;
    text-align: left;
}

.badge.bg-warning {
    font-size: 10px;
    padding: 3px 8px;
    max-width: 200px;
    white-space: normal;
    text-align: left;
}

/* Tooltip for restriction information */
.restriction-tooltip {
    max-width: 350px !important;
    white-space: normal !important;
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

/* Stock status indicator */
.stock-status {
    font-size: 11px;
    padding: 1px 4px;
    border-radius: 3px;
    margin-left: 5px;
}

.stock-available {
    background-color: #d4edda;
    color: #155724;
}

.stock-low {
    background-color: #fff3cd;
    color: #856404;
}

.stock-out {
    background-color: #f8d7da;
    color: #721c24;
}

/* Table source indicator */
.table-source-indicator {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 3px;
    background-color: #e9ecef;
    color: #495057;
    margin-left: 5px;
}

.table-current {
    background-color: #d1ecf1 !important;
    color: #0c5460 !important;
}

.table-archive {
    background-color: #f8d7da !important;
    color: #721c24 !important;
}

/* Simplified stock display - hide decimal points */
.stock-integer {
    font-weight: bold;
}

.stock-decimal {
    display: none !important;
}

/* Compact info display */
.compact-info {
    font-size: 11px;
    color: #6c757d;
}

/* Special styling for items with partial date distribution due to global restrictions */
.global-restriction-item {
    background-color: #f8d7da !important;
    border-left: 3px solid #dc3545 !important;
}

.global-restriction-item td {
    color: #721c24 !important;
}

/* Enhanced styling for unavailable date cells */
.unavailable-date-cell {
    background-color: #f8d7da !important;
    color: #721c24 !important;
    text-align: center;
    position: relative;
    font-weight: bold;
}

.unavailable-date-cell span {
    font-size: 14px;
    display: block;
}

.unavailable-date-cell .small-icon {
    font-size: 12px;
    display: block;
    margin-top: 2px;
}

/* Dry day cell styling */
.dry-date-cell {
    background-color: #fff3cd !important;
    color: #856404 !important;
    text-align: center;
    font-weight: bold;
    border-left: 2px solid #ffc107 !important;
}

.dry-date-cell .small-icon {
    font-size: 12px;
    display: block;
    margin-top: 2px;
}

/* Available date cell with new sales */
.available-date-cell {
    background-color: #d4edda !important;
    color: #155724 !important;
    text-align: center;
    font-weight: bold;
}

/* Partial distribution item row styling */
.partial-distribution-item {
    background-color: #fff3cd !important;
    border-left: 3px solid #ffc107 !important;
}

.partial-distribution-item td {
    color: #856404 !important;
}

/* Distribution column styles */
.date-distribution-cell {
    text-align: center !important;
    font-weight: bold !important;
    font-size: 12px !important;
    padding: 3px 5px !important;
    min-width: 35px !important;
    border-left: 1px solid #dee2e6 !important;
    border-right: 1px solid #dee2e6 !important;
}

.date-distribution-cell.zero-distribution {
    color: #6c757d !important;
    font-weight: normal !important;
}

.date-distribution-cell.non-zero-distribution {
    color: #198754 !important;
    background-color: rgba(25, 135, 84, 0.1) !important;
}

.date-distribution-cell.global-unavailable-date {
    background-color: #f8d7da !important;
    color: #721c24 !important;
}

.date-distribution-cell.dry-unavailable-date {
    background-color: #fff3cd !important;
    color: #856404 !important;
}

.date-header {
    text-align: center !important;
    font-size: 11px !important;
    padding: 4px 6px !important;
    min-width: 40px !important;
    background-color: #f8f9fa !important;
    border-left: 1px solid #dee2e6 !important;
    border-right: 1px solid #dee2e6 !important;
    font-weight: bold !important;
    vertical-align: middle !important;
}

/* Make sure the table headers and columns are visible */
.date-header, .date-distribution-cell {
    display: table-cell !important;
    visibility: visible !important;
}

/* Action column adjustment */
.action-column {
    width: 120px !important;
    min-width: 120px !important;
}

/* Ensure the table layout doesn't break with date columns */
.table-container {
    overflow-x: auto;
    max-width: 100%;
}

.styled-table {
    min-width: 1200px; /* Minimum width to ensure all columns are visible */
}

/* Style for Shuffle button in action column */
.btn-shuffle-item {
    font-size: 11px !important;
    padding: 2px 8px !important;
}

/* ENHANCED: Date distribution cell styling for better visual representation */
.date-distribution-cell.available-date-cell {
    background-color: #d4edda !important;
    color: #155724 !important;
    font-weight: bold;
    position: relative;
}

.date-distribution-cell.available-date-cell:after {
    content: "✓";
    position: absolute;
    top: 0;
    right: 2px;
    font-size: 10px;
    color: #28a745;
}

.date-distribution-cell.unavailable-date-cell {
    background-color: #f8d7da !important;
    color: #721c24 !important;
}

.date-distribution-cell.unavailable-date-cell span {
    display: block;
    font-size: 14px;
}

.date-distribution-cell.dry-date-cell {
    background-color: #fff3cd !important;
    color: #856404 !important;
}

.date-distribution-cell.dry-date-cell span {
    display: block;
    font-size: 14px;
}

.date-distribution-cell.non-zero-distribution {
    background-color: #cce5ff !important;
    color: #004085 !important;
    font-weight: bold;
}

.date-distribution-cell.zero-distribution {
    color: #6c757d !important;
    background-color: #f8f9fa !important;
}

/* Enhanced tooltip for date cells */
.date-distribution-cell {
    position: relative;
    cursor: help;
}

/* Visual indicator for partial date items */
.partial-distribution-item {
    background-color: #fff3cd !important;
    border-left: 3px solid #ffc107 !important;
}

.partial-distribution-item td {
    color: #856404 !important;
}

/* FIXED: Special styling for unavailable dates (global sales) */
.unavailable-date {
    background-color: #f8d7da !important;
    color: #721c24 !important;
    font-weight: bold;
    position: relative;
}

/* FIXED: Dry date styling */
.dry-date {
    background-color: #fff3cd !important;
    color: #856404 !important;
    font-weight: bold;
    position: relative;
}

/* FIXED: Available date styling */
.available-date-with-sales {
    background-color: #d4edda !important;
    color: #155724 !important;
    font-weight: bold;
}

/* Restriction info banner */
.restriction-banner {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.dry-day-banner {
    background-color: #fff3cd;
    border: 1px solid #ffeeba;
    color: #856404;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 15px;
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

      <!-- SIMPLIFIED License Restriction Info -->
      <div class="alert alert-info mb-3 py-2">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0 compact-info">Showing items with available stock > 0</p>
      </div>

      <!-- NEW: Global Restriction Banner -->
      <?php if ($has_restrictions): ?>
        <div class="restriction-banner mb-3">
          <strong><i class="fas fa-exclamation-triangle"></i> Date Range Restrictions:</strong><br>
          <?php if (!empty($restrictions['unavailable_sales_dates'])): ?>
            <span class="badge bg-danger">Existing Sales: <?= implode(', ', $restrictions['unavailable_sales_dates']) ?></span><br>
          <?php endif; ?>
          <?php if (!empty($dry_dates)): ?>
            <span class="badge bg-warning">Dry Days: 
              <?php 
              $dryDaysManager = new DryDaysManager($conn);
              $dry_days_info = $restrictions['dry_days_info'];
              foreach ($dry_dates as $dry_date): 
                $description = $dry_days_info[$dry_date] ?? 'Dry Day';
              ?>
                <span title="<?= htmlspecialchars($description) ?>"><?= $dry_date ?></span><?= !next($dry_dates) ? '' : ', ' ?>
              <?php endforeach; ?>
            </span><br>
          <?php endif; ?>
          <?php if (!empty($available_dates_global)): ?>
            <span class="badge bg-success">Available Dates: <?= implode(', ', $available_dates_global) ?></span>
          <?php else: ?>
            <span class="badge bg-danger">No available dates in selected range!</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>

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
                   value="<?= htmlspecialchars($end_date); ?>" required>
          </div>
          
          <div class="col-md-4">
            <label class="form-label">Date Range: 
              <span class="fw-bold">
<?= date('d-M-Y', strtotime($start_date)) . " to " . date('d-M-Y', strtotime($end_date)) ?>
                (<?= $days_count ?> days)
              </span>
              <?php if ($has_restrictions): ?>
                <span class="badge bg-warning"><?= count($available_dates_global) ?> available</span>
              <?php endif; ?>
            </label>
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
            Total Items with Stock: <?= $total_items ?> | Page: <?= $current_page ?> of <?= $total_pages ?>
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
          <button type="button" id="shuffleBtn" class="btn btn-warning btn-action" <?= empty($available_dates_global) ? 'disabled' : '' ?>>
            <i class="fas fa-random"></i> Shuffle All
          </button>
          
          <!-- Single Button with Dual Functionality -->
          <button type="button" id="generateBillsBtn" class="btn btn-success btn-action" <?= empty($available_dates_global) ? 'disabled' : '' ?>>
            <i class="fas fa-save"></i> Generate Bills
          </button>
          
          <!-- Clear Session Button -->
          <button type="button" id="clearSessionBtn" class="btn btn-danger">
            <i class="fas fa-trash"></i> Clear All Quantities
          </button>
          
          <!-- Sales Log Button -->
          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#salesLogModal" onclick="loadSalesLog()">
              <i class="fas fa-file-alt"></i> View Sales Log
          </button>

          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#totalSalesModal">
              <i class="fas fa-chart-bar"></i> View Total Sales Summary
          </button>
          
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>

        <?php if (empty($available_dates_global) && $has_restrictions): ?>
          <div class="alert alert-danger mb-3">
            <i class="fas fa-exclamation-circle"></i>
            <strong>No available dates in selected range!</strong><br>
            Please select a different date range.
          </div>
        <?php endif; ?>

        <!-- Items Table with Integrated Distribution Preview -->
        <div class="table-container">
          <table class="styled-table table-striped" id="itemsTable">
            <thead class="table-header">
              <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Rate (₹)</th>
                <th>Available Stock</th>
                <th>Sale Qty (Auto-calculated)</th>
                <th class="closing-balance-header">Enter Closing Balance</th>
                <th class="action-column">Action</th>
                
                <!-- Date Distribution Headers (will be populated by JavaScript) -->
                
                <th>Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
<?php if (!empty($items)): ?>
    <?php foreach ($items as $item): 
        $item_code = $item['CODE'];
        $item_qty = isset($_SESSION['sale_quantities'][$item_code]) ? $_SESSION['sale_quantities'][$item_code] : 0;
        $item_total = $item_qty * $item['RPRICE'];
        $closing_balance = $item['CURRENT_STOCK'] - $item_qty;
        
        // Format numbers to remove decimals for display
        $display_stock = floor($item['CURRENT_STOCK']);
        $display_rate = intval($item['RPRICE']);
        $display_closing = floor($closing_balance);
        $display_amount = intval($item_total);
        
        // Extract size from item details
        $size = 0;
        if (preg_match('/(\d+)\s*ML\b/i', $item['DETAILS'], $matches)) {
            $size = $matches[1];
        }
        
        // Get class code - now available from the query
        $class_code = $item['CLASS'] ?? 'O'; // Default to 'O' if not set
        
        // Determine stock status for styling
        $stock_status_class = '';
        if ($item['CURRENT_STOCK'] <= 0) {
            $stock_status_class = 'stock-out';
        } elseif ($item['CURRENT_STOCK'] < 10) {
            $stock_status_class = 'stock-low';
        } else {
            $stock_status_class = 'stock-available';
        }
        
        // Check if there are any available dates
        $has_available_dates = !empty($available_dates_global);
        
        // Disable input if NO available dates
        $should_disable_input = !$has_available_dates;
        
        $restriction_class = $should_disable_input ? 'global-restriction' : '';
        $restriction_title = $should_disable_input ? 
            "No available dates in selected range due to existing sales or dry days." : 
            '';
    ?>
        <tr data-class="<?= htmlspecialchars($class_code) ?>" 
    data-details="<?= htmlspecialchars($item['DETAILS']) ?>" 
    data-details2="<?= htmlspecialchars($item['DETAILS2']) ?>"
    class="<?= $item_qty > 0 ? 'has-quantity' : '' ?> <?= $restriction_class ?>"
    data-has-global-restriction='<?= $should_disable_input ? 'true' : 'false' ?>'
    data-available-dates='<?= json_encode($available_dates_global) ?>'
    data-unavailable-dates='<?= json_encode($unavailable_dates_global) ?>'
    data-dry-dates='<?= json_encode($dry_dates) ?>'
    data-latest-global-sale='<?= json_encode($restrictions['latest_existing_sale'] ?? '') ?>'>
            <td><?= htmlspecialchars($item_code); ?></td>
            <td><?= htmlspecialchars($item['DETAILS']); ?></td>
            <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
            <td class="stock-integer"><?= number_format($display_rate); ?></td>
            <td>
                <span class="stock-info">
                    <span class="stock-integer"><?= number_format($display_stock); ?></span>
                    <span class="stock-status <?= $stock_status_class ?>">
                        <?php if ($item['CURRENT_STOCK'] <= 0): ?>Out
                        <?php elseif ($item['CURRENT_STOCK'] < 10): ?>Low
                        <?php else: ?>Available<?php endif; ?>
                    </span>
                    <?php if ($has_restrictions && $has_available_dates): ?>
                        <span class="badge bg-warning" data-bs-toggle="tooltip" 
                              title="Only <?= count($available_dates_global) ?> of <?= $days_count ?> dates are available due to existing sales or dry days.">
                            <i class="fas fa-calendar-day"></i> Partial Range
                        </span>
                    <?php endif; ?>
                </span>
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
                       data-has-global-restriction="<?= $should_disable_input ? 'true' : 'false' ?>"
                       data-available-dates='<?= htmlspecialchars(json_encode($available_dates_global)) ?>'
                       data-unavailable-dates='<?= htmlspecialchars(json_encode($unavailable_dates_global)) ?>'
                       data-dry-dates='<?= htmlspecialchars(json_encode($dry_dates)) ?>'
                       oninput="validateQuantity(this)"
                       <?= $should_disable_input ? 'disabled title="' . htmlspecialchars($restriction_title) . '"' : '' ?>>
            </td>
            <td class="closing-balance-cell" id="closing_<?= htmlspecialchars($item_code); ?>">
                <span class="stock-integer"><?= number_format($display_closing) ?></span>
                <!-- Show stock status for closing balance too -->
                <?php if ($closing_balance <= 0): ?>
                    <br><span class="stock-status stock-out">Out</span>
                <?php elseif ($closing_balance < 10): ?>
                    <br><span class="stock-status stock-low">Low</span>
                <?php endif; ?>
            </td>
            <td class="action-column">
                <?php if ($should_disable_input): ?>
                    <span class="badge bg-danger" data-bs-toggle="tooltip" 
                          title="<?= htmlspecialchars($restriction_title) ?>">
                        <i class="fas fa-calendar-times"></i> No Available Dates
                    </span>
                <?php elseif ($has_restrictions && $has_available_dates): ?>
                    <span class="badge bg-warning" data-bs-toggle="tooltip" 
                          title="Only <?= count($available_dates_global) ?> of <?= $days_count ?> dates are available due to existing sales or dry days.">
                        <i class="fas fa-calendar-day"></i> Available: <?= count($available_dates_global) ?> dates
                    </span>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-shuffle-item" 
                            data-code="<?= htmlspecialchars($item_code); ?>">
                        <i class="fas fa-random"></i> Shuffle
                    </button>
                <?php endif; ?>
            </td>
            
            <!-- Date distribution cells will be inserted here by JavaScript -->
            
            <td class="amount-cell" id="amount_<?= htmlspecialchars($item_code); ?>">
                <span class="stock-integer"><?= number_format($display_amount) ?></span>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="9" class="text-center text-muted">
            <div class="py-4">
                <i class="fas fa-box-open fa-2x mb-3 text-muted"></i>
                <h5>No items found with available stock</h5>
                <?php if ($search !== ''): ?>
                    <p class="mb-1">Try a different search term</p>
                <?php endif; ?>
                <p class="mb-0"><small>Note: Only items with stock > 0 are shown</small></p>
            </td>
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
            Showing <?= count($items) ?> of <?= $total_items ?> items with stock > 0 (Page <?= $current_page ?> of <?= $total_pages ?>)
            <?php if (count($_SESSION['sale_quantities']) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['sale_quantities']) ?> items with quantities across all pages</span>
            <?php endif; ?>
            <?php if ($has_restrictions): ?>
              | <span class="text-warning"><?= count($available_dates_global) ?> available dates</span>
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

// NEW: Global restriction variables
const globalAvailableDates = <?= json_encode($available_dates_global) ?>;
const globalUnavailableDates = <?= json_encode($unavailable_dates_global) ?>;
const globalDryDates = <?= json_encode($dry_dates) ?>;
const hasGlobalRestrictions = <?= json_encode($has_restrictions) ?>;
const latestGlobalSale = <?= json_encode($restrictions['latest_existing_sale'] ?? null) ?>;

// NEW: Dry days info
const dryDaysInfo = <?= json_encode($restrictions['dry_days_info'] ?? []) ?>;

// FIXED: Enhanced distribution function that correctly handles global restrictions and dry days
function distributeSalesWithGlobalRestrictions(totalQty, availableDates, dryDates, unavailableDates) {
    if (totalQty <= 0) return new Array(daysCount).fill(0);
    
    // Create a map of date to index in the dateArray
    const dateIndexMap = {};
    dateArray.forEach((date, index) => {
        dateIndexMap[date] = index;
    });
    
    // Create distribution array
    const distribution = new Array(daysCount).fill(0);
    
    // If no available dates, return zeros
    if (availableDates.length === 0) {
        console.log(`No available dates for distribution`);
        return distribution;
    }
    
    // Distribute quantity only on available dates
    const availableDaysCount = availableDates.length;
    const baseQty = Math.floor(totalQty / availableDaysCount);
    const remainder = totalQty % availableDaysCount;
    
    const dailySales = new Array(availableDaysCount).fill(baseQty);
    
    // Distribute remainder
    for (let i = 0; i < remainder; i++) {
        dailySales[i]++;
    }
    
    // Shuffle the distribution
    for (let i = dailySales.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [dailySales[i], dailySales[j]] = [dailySales[j], dailySales[i]];
    }
    
    // Place the distributed quantities in the correct date positions
    availableDates.forEach((date, index) => {
        const dateIndex = dateIndexMap[date];
        if (dateIndex !== undefined) {
            distribution[dateIndex] = dailySales[index];
        }
    });
    
    console.log(`Distributing ${totalQty} on ${availableDaysCount} available dates:`, availableDates);
    console.log(`Distribution:`, distribution);
    
    return distribution;
}

// Function to validate global restrictions before submission
function checkGlobalRestrictionsBeforeSubmit() {
    return new Promise((resolve, reject) => {
        // Check if there are any available dates
        if (globalAvailableDates.length === 0) {
            const errorMessage = "No available dates in the selected range due to existing sales or dry days.";
            showClientValidationAlert(errorMessage);
            reject(errorMessage);
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
            const errorMessage = "Please enter quantities for at least one item.";
            showClientValidationAlert(errorMessage);
            reject(errorMessage);
            return;
        }

        resolve(true);
    });
}

// NEW: Function to check stock availability via AJAX before submission
function checkStockAvailabilityBeforeSubmit() {
    return new Promise((resolve, reject) => {
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

        // Show checking state
        $('#generateBillsBtn').prop('disabled', true).addClass('btn-loading');
        $('tr.has-quantity').addClass('stock-checking');

        // Prepare data for AJAX check
        const checkData = {
            start_date: '<?= $start_date ?>',
            end_date: '<?= $end_date ?>',
            mode: '<?= $mode ?>',
            comp_id: '<?= $comp_id ?>',
            quantities: allSessionQuantities,
            daily_stock_table: '<?= $daily_stock_table ?>',
            end_date_month: '<?= $end_date_month ?>'
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

// Enhanced quantity validation function
function validateQuantity(input) {
    const itemCode = $(input).data('code');
    const currentStock = parseFloat($(input).data('stock'));
    let enteredQty = parseInt($(input).val()) || 0;
    
    // If input is disabled due to global restrictions, don't validate
    if ($(input).prop('disabled')) {
        return false;
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
    
    // Update distribution preview with global restrictions
    updateDistributionPreviewWithGlobalRestrictions(itemCode, enteredQty);
    
    return true;
}

// New function to update all UI elements for an item
function updateItemUI(itemCode, qty, currentStock) {
    const rate = parseFloat($(`input[name="sale_qty[${itemCode}]"]`).data('rate'));
    const closingBalance = currentStock - qty;
    const amount = qty * rate;
    
    // Format to remove decimals for display
    const displayClosing = Math.floor(closingBalance);
    const displayAmount = Math.floor(amount);
    
    // Update all related UI elements
    $(`#closing_${itemCode}`).html(`<span class="stock-integer">${displayClosing}</span>`);
    $(`#amount_${itemCode}`).html(`<span class="stock-integer">${displayAmount}</span>`);
    
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
        }
    }
    
    if (!isValid) {
        let errorMessage = "The following items have insufficient stock:\n\n";
        errorItems.forEach(item => {
            errorMessage += `• Item ${item.code}: Stock ${Math.floor(item.stock)}, Quantity ${item.qty}\n`;
        });
        errorMessage += "\nPlease adjust quantities to avoid negative closing balance.";
        alert(errorMessage);
    }
    
    return isValid;
}

// FIXED: Enhanced function to update distribution preview - correctly shows available dates with global restrictions
function updateDistributionPreviewWithGlobalRestrictions(itemCode, totalQty) {
    console.log(`DEBUG: updateDistributionPreviewWithGlobalRestrictions called for ${itemCode} with qty ${totalQty}`);
    const inputField = $(`input[name="sale_qty[${itemCode}]"]`);
    const itemRow = inputField.closest('tr');

    if (totalQty <= 0) {
        // Remove distribution cells if quantity is 0
        itemRow.find('.date-distribution-cell').remove();

        // Reset closing balance and amount
        const currentStock = parseFloat(inputField.data('stock'));
        const displayClosing = Math.floor(currentStock);
        $(`#closing_${itemCode}`).html(`<span class="stock-integer">${displayClosing}</span>`);
        $(`#amount_${itemCode}`).html('<span class="stock-integer">0</span>');

        // Hide date columns if no items have quantity
        if ($('input[name^="sale_qty"]').filter(function() {
            return parseInt($(this).val()) > 0 && !$(this).prop('disabled');
        }).length === 0) {
            $('.date-header, .date-distribution-cell').hide();
        }

        return;
    }

    // Get global available/unavailable dates
    const hasGlobalRestriction = inputField.data('has-global-restriction');
    const availableDates = inputField.data('available-dates') || [];
    const unavailableDates = inputField.data('unavailable-dates') || [];
    const dryDates = inputField.data('dry-dates') || [];

    console.log(`DEBUG: ${itemCode} - hasGlobalRestriction: ${hasGlobalRestriction}`);
    console.log(`DEBUG: ${itemCode} - availableDates:`, availableDates);
    console.log(`DEBUG: ${itemCode} - unavailableDates:`, unavailableDates);
    console.log(`DEBUG: ${itemCode} - dryDates:`, dryDates);
    
    // Create date index map
    const dateIndexMap = {};
    dateArray.forEach((date, index) => {
        dateIndexMap[date] = index;
    });

    console.log(`DEBUG: ${itemCode} - dateIndexMap:`, dateIndexMap);

    // Calculate distribution based on global availability
    let distribution = new Array(daysCount).fill(0);

    if (hasGlobalRestriction && availableDates.length > 0) {
        console.log(`DEBUG: ${itemCode} - Has global restriction with ${availableDates.length} available dates`);
        // Distribute only on available dates
        const availableDaysCount = availableDates.length;
        const baseQty = Math.floor(totalQty / availableDaysCount);
        const remainder = totalQty % availableDaysCount;

        const dailySalesForAvailableDates = new Array(availableDaysCount).fill(baseQty);

        // Distribute remainder
        for (let i = 0; i < remainder; i++) {
            dailySalesForAvailableDates[i]++;
        }

        // Shuffle the distribution
        for (let i = dailySalesForAvailableDates.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [dailySalesForAvailableDates[i], dailySalesForAvailableDates[j]] =
            [dailySalesForAvailableDates[j], dailySalesForAvailableDates[i]];
        }

        // Place the distributed quantities in the correct date positions
        availableDates.forEach((date, index) => {
            const dateIndex = dateIndexMap[date];
            if (dateIndex !== undefined) {
                distribution[dateIndex] = dailySalesForAvailableDates[index];
            }
        });

        console.log(`Item ${itemCode}: Distributing ${totalQty} on ${availableDaysCount} available dates:`, availableDates);
        console.log(`Distribution array:`, distribution);

        // Add special class to row to indicate partial distribution
        itemRow.addClass('partial-distribution-item');
        itemRow.attr('title', `Only ${availableDates.length} of ${daysCount} dates are available. New sales will be distributed only on available dates.`);
    } else if (!hasGlobalRestriction || availableDates.length === daysCount) {
        console.log(`DEBUG: ${itemCode} - No restrictions or all dates available`);
        // No restrictions - distribute across all dates
        const baseQty = Math.floor(totalQty / daysCount);
        const remainder = totalQty % daysCount;

        distribution = new Array(daysCount).fill(baseQty);

        // Distribute remainder evenly across days
        for (let i = 0; i < remainder; i++) {
            distribution[i]++;
        }

        // Shuffle the distribution to make it look more natural
        for (let i = distribution.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [distribution[i], distribution[j]] = [distribution[j], distribution[i]];
        }

        itemRow.removeClass('partial-distribution-item');
        console.log(`Item ${itemCode}: Distributing ${totalQty} across all ${daysCount} dates`);
    } else {
        console.log(`DEBUG: ${itemCode} - No available dates for distribution`);
        // No available dates - all zeros
        console.log(`Item ${itemCode}: No available dates for distribution`);
        itemRow.addClass('partial-distribution-item');
        itemRow.attr('title', 'No available dates in selected range');
    }

    console.log(`DEBUG: ${itemCode} - Final distribution array:`, distribution);
    
    // Remove any existing distribution cells
    itemRow.find('.date-distribution-cell').remove();

    console.log(`DEBUG: ${itemCode} - Creating cells for ${distribution.length} dates`);

    // Add date distribution cells with proper styling
    distribution.forEach((qty, index) => {
        const date = dateArray[index];
        const cell = $(`<td class="date-distribution-cell"></td>`);

        // Check if this date is unavailable due to global sales
        const isGlobalUnavailable = unavailableDates.length > 0 && unavailableDates.includes(date);
        
        // Check if this date is a dry day
        const isDryDate = dryDates.length > 0 && dryDates.includes(date);
        
        // Check if this date is available
        const isAvailable = availableDates.length > 0 && availableDates.includes(date);

        console.log(`DEBUG: ${itemCode} - Date ${date} (index ${index}): qty=${qty}, isGlobalUnavailable=${isGlobalUnavailable}, isDryDate=${isDryDate}, isAvailable=${isAvailable}`);

        // Apply styling and content based on availability and quantity
        if (isGlobalUnavailable && !isDryDate) {
            // Date has existing global sales - show ✗
            cell.addClass('global-unavailable-date');
            cell.html('<span class="text-danger">✗</span><span class="small-icon">(sale)</span>');
            cell.attr('title', `Sales already exist on ${date} - No new sales allowed`);
            console.log(`DEBUG: ${itemCode} - Setting cell for ${date} to GLOBAL UNAVAILABLE (✗)`);

        } else if (isDryDate) {
            // Date is a dry day - show 🌙
            cell.addClass('dry-unavailable-date');
            cell.html('<span class="text-warning">🌙</span><span class="small-icon">(dry day)</span>');
            
            // Get dry day description
            const dryDescription = dryDaysInfo[date] || 'Dry Day';
            cell.attr('title', `${dryDescription} - ${date} (Dry Day - No sales allowed)`);
            console.log(`DEBUG: ${itemCode} - Setting cell for ${date} to DRY DAY (🌙)`);

        } else if (isAvailable && qty > 0) {
            // Date is available and has new sales
            cell.addClass('available-date-with-sales');
            cell.text(qty);
            cell.attr('title', `${qty} units scheduled for ${date} (available date)`);
            console.log(`DEBUG: ${itemCode} - Setting cell for ${date} to AVAILABLE WITH SALES (${qty})`);

        } else if (qty > 0) {
            // Normal distribution (no restrictions)
            cell.addClass('non-zero-distribution');
            cell.text(qty);
            cell.attr('title', `${qty} units scheduled for ${date}`);
            console.log(`DEBUG: ${itemCode} - Setting cell for ${date} to NON-ZERO (${qty})`);

        } else {
            // Zero quantity
            cell.addClass('zero-distribution');
            cell.text('0');
            console.log(`DEBUG: ${itemCode} - Setting cell for ${date} to ZERO DISTRIBUTION`);

            if (isAvailable) {
                cell.attr('title', `Date ${date} is available but has 0 units assigned`);
            } else if (!hasGlobalRestriction) {
                cell.attr('title', `Date ${date} has 0 units assigned`);
            }
        }

        // Insert distribution cells after the action column
        cell.insertAfter(itemRow.find('.action-column'));
    });

    console.log(`DEBUG: ${itemCode} - Finished creating distribution cells`);
    
    // Update total distribution count
    const totalDistributed = distribution.reduce((sum, qty) => sum + qty, 0);
    if (totalDistributed !== totalQty) {
        console.warn(`Warning: Total distributed (${totalDistributed}) doesn't match input (${totalQty}) for item ${itemCode}`);
    }
    
    // Show date columns
    $('.date-header, .date-distribution-cell').show();
}

// FIXED: Enhanced distribution function for shuffle that correctly handles global restrictions
function shuffleDistributionForItem(itemCode, totalQty) {
    console.log(`DEBUG: shuffleDistributionForItem called for ${itemCode} with qty ${totalQty}`);
    const inputField = $(`input[name="sale_qty[${itemCode}]"]`);
    const hasGlobalRestriction = inputField.data('has-global-restriction') === 'true';
    const availableDatesJson = inputField.data('available-dates');
    const unavailableDatesJson = inputField.data('unavailable-dates');
    const dryDatesJson = inputField.data('dry-dates');

    let availableDates = [];
    let unavailableDates = [];
    let dryDates = [];
    
    try {
        availableDates = availableDatesJson || [];
        unavailableDates = unavailableDatesJson || [];
        dryDates = dryDatesJson || [];
    } catch (e) {
        console.error('Error parsing date arrays:', e);
    }

    console.log(`DEBUG: shuffle ${itemCode} - hasGlobalRestriction: ${hasGlobalRestriction}`);
    console.log(`DEBUG: shuffle ${itemCode} - availableDates:`, availableDates);
    console.log(`DEBUG: shuffle ${itemCode} - unavailableDates:`, unavailableDates);
    console.log(`DEBUG: shuffle ${itemCode} - dryDates:`, dryDates);
    
    // Create date index map
    const dateIndexMap = {};
    dateArray.forEach((date, index) => {
        dateIndexMap[date] = index;
    });

    let distribution = new Array(daysCount).fill(0);

    if (hasGlobalRestriction && availableDates.length > 0) {
        console.log(`DEBUG: shuffle ${itemCode} - Distributing on ${availableDates.length} available dates`);
        // Distribute only on available dates
        const availableDaysCount = availableDates.length;
        const baseQty = Math.floor(totalQty / availableDaysCount);
        const remainder = totalQty % availableDaysCount;

        const dailySalesForAvailableDates = new Array(availableDaysCount).fill(baseQty);

        // Distribute remainder
        for (let i = 0; i < remainder; i++) {
            dailySalesForAvailableDates[i]++;
        }

        // Shuffle the distribution
        for (let i = dailySalesForAvailableDates.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [dailySalesForAvailableDates[i], dailySalesForAvailableDates[j]] =
            [dailySalesForAvailableDates[j], dailySalesForAvailableDates[i]];
        }

        // Place the distributed quantities in the correct date positions
        availableDates.forEach((date, index) => {
            const dateIndex = dateIndexMap[date];
            if (dateIndex !== undefined) {
                distribution[dateIndex] = dailySalesForAvailableDates[index];
            }
        });

        console.log(`Shuffled ${itemCode}: Distributing ${totalQty} on ${availableDaysCount} available dates`);
        console.log(`DEBUG: shuffle ${itemCode} - distribution:`, distribution);
    } else if (!hasGlobalRestriction || availableDates.length === daysCount) {
        console.log(`DEBUG: shuffle ${itemCode} - Distributing across all dates`);
        // Distribute across all dates
        const baseQty = Math.floor(totalQty / daysCount);
        const remainder = totalQty % daysCount;

        distribution = new Array(daysCount).fill(baseQty);

        // Distribute remainder
        for (let i = 0; i < remainder; i++) {
            distribution[i]++;
        }

        // Shuffle the distribution
        for (let i = distribution.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [distribution[i], distribution[j]] = [distribution[j], distribution[i]];
        }

        console.log(`Shuffled ${itemCode}: Distributing ${totalQty} across all ${daysCount} dates`);
        console.log(`DEBUG: shuffle ${itemCode} - distribution:`, distribution);
    } else {
        console.log(`DEBUG: shuffle ${itemCode} - No available dates, returning zeros`);
    }

    console.log(`DEBUG: shuffle ${itemCode} - returning distribution:`, distribution);
    return distribution;
}

// Function to calculate total amount
function calculateTotalAmount() {
    let total = 0;
    $('.amount-cell').each(function() {
        total += parseFloat($(this).text()) || 0;
    });
    $('#totalAmount').text(Math.floor(total));
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
        
        // Add tooltip to show if date is a dry day or has sales
        let title = date;
        let headerClass = '';
        
        if (globalDryDates.includes(date)) {
            const dryDescription = dryDaysInfo[date] || 'Dry Day';
            title = `${date} - DRY DAY: ${dryDescription}`;
            headerClass = 'dry-date-header';
        } else if (globalUnavailableDates.includes(date) && !globalDryDates.includes(date)) {
            title = `${date} - Has existing sales`;
            headerClass = 'unavailable-date-header';
        } else {
            title = `${date} - Available for new sales`;
        }
        
        // Insert date headers after the action column header
        $(`<th class="date-header ${headerClass}" title="${title}">${day}<br>${month}</th>`).insertAfter($('.table-header tr th.action-column'));
    });
}

// Function to handle row navigation with arrow keys
function setupRowNavigation() {
    const qtyInputs = $('input.qty-input:enabled');
    let currentRowIndex = -1;
    
    // Highlight row when input is focused
    $(document).on('focus', 'input.qty-input:enabled', function() {
        // Remove highlight from all rows
        $('tr').removeClass('highlight-row');
        
        // Add highlight to current row
        $(this).closest('tr').addClass('highlight-row');
        
        // Update current row index
        currentRowIndex = qtyInputs.index(this);
    });
    
    // Handle arrow key navigation
    $(document).on('keydown', 'input.qty-input:enabled', function(e) {
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

// UPDATED: Function to generate bills immediately with enhanced client-side validation
function generateBills() {
    // First check global restrictions
    checkGlobalRestrictionsBeforeSubmit()
        .then(() => {
            // Then validate basic quantities
            if (!validateAllQuantities()) {
                throw new Error('Quantity validation failed');
            }
            
            // Then check stock availability
            return checkStockAvailabilityBeforeSubmit();
        })
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
    // First check global restrictions
    checkGlobalRestrictionsBeforeSubmit()
        .then(() => {
            // Then validate basic quantities
            if (!validateAllQuantities()) {
                throw new Error('Quantity validation failed');
            }
            
            // Then check stock availability
            return checkStockAvailabilityBeforeSubmit();
        })
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
    // Check if there are any available dates
    if (globalAvailableDates.length === 0) {
        alert('No available dates in the selected range due to existing sales or dry days.');
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

// Initialize enhanced tooltips
function initializeRestrictionTooltips() {
    $('[data-bs-toggle="tooltip"]').tooltip({
        placement: 'top',
        trigger: 'hover',
        container: 'body',
        template: '<div class="tooltip restriction-tooltip" role="tooltip"><div class="tooltip-arrow"></div><div class="tooltip-inner"></div></div>'
    });
}

// Function to initialize distribution preview for all items with quantities
function initializeDistributionPreview() {
    console.log('Initializing distribution preview for items with quantities...');
    
    let itemsWithQuantity = 0;
    $('input[name^="sale_qty"]').each(function() {
        const itemCode = $(this).data('code');
        const totalQty = parseInt($(this).val()) || 0;
        
        if (totalQty > 0 && !$(this).prop('disabled')) {
            updateDistributionPreviewWithGlobalRestrictions(itemCode, totalQty);
            itemsWithQuantity++;
        }
    });
    
    console.log(`Initialized distribution preview for ${itemsWithQuantity} items with quantities`);
    
    // Show date headers if we have any items with quantity
    if (itemsWithQuantity > 0) {
        $('.date-header, .date-distribution-cell').show();
    }
}

// FIXED: Individual shuffle button click event - correctly handles global restrictions
$(document).on('click', '.btn-shuffle-item', async function() {
    const itemCode = $(this).data('code');
    console.log(`DEBUG: Individual shuffle clicked for ${itemCode}`);
    const inputField = $(`input[name="sale_qty[${itemCode}]"]`);
    const totalQty = parseInt(inputField.val()) || 0;

    console.log(`DEBUG: Individual shuffle ${itemCode} - totalQty: ${totalQty}, disabled: ${inputField.prop('disabled')}`);

    // Only shuffle if quantity > 0 and not disabled
    if (totalQty > 0 && !inputField.prop('disabled')) {
        // Get new distribution based on global restrictions
        const newDistribution = shuffleDistributionForItem(itemCode, totalQty);

        // Update the distribution cells
        const itemRow = inputField.closest('tr');
        const dateCells = itemRow.find('.date-distribution-cell');

        console.log(`DEBUG: Individual shuffle ${itemCode} - found ${dateCells.length} date cells`);

        // Get global available/unavailable dates
        const hasGlobalRestriction = inputField.data('has-global-restriction');
        const availableDates = inputField.data('available-dates') || [];
        const unavailableDates = inputField.data('unavailable-dates') || [];
        const dryDates = inputField.data('dry-dates') || [];

        console.log(`DEBUG: Individual shuffle ${itemCode} - availableDates:`, availableDates);
        console.log(`DEBUG: Individual shuffle ${itemCode} - unavailableDates:`, unavailableDates);
        console.log(`DEBUG: Individual shuffle ${itemCode} - dryDates:`, dryDates);

        // Create date index map
        const dateIndexMap = {};
        dateArray.forEach((date, index) => {
            dateIndexMap[date] = index;
        });

        newDistribution.forEach((qty, index) => {
            if (dateCells.eq(index).length) {
                const cell = dateCells.eq(index);
                const date = dateArray[index];

                console.log(`DEBUG: Individual shuffle ${itemCode} - updating cell ${index} for date ${date} with qty ${qty}`);

                // Update styling and content based on value and availability
                cell.removeClass('zero-distribution non-zero-distribution global-unavailable-date dry-unavailable-date available-date-with-sales');

                // Check if this date is unavailable due to global sales
                const isGlobalUnavailable = unavailableDates.length > 0 && unavailableDates.includes(date);
                
                // Check if this date is a dry day
                const isDryDate = dryDates.length > 0 && dryDates.includes(date);

                // Check if this date is available
                const isAvailable = availableDates.length > 0 && availableDates.includes(date);

                console.log(`DEBUG: Individual shuffle ${itemCode} - date ${date}: isGlobalUnavailable=${isGlobalUnavailable}, isDryDate=${isDryDate}, isAvailable=${isAvailable}`);

                if (isGlobalUnavailable && !isDryDate) {
                    // Date has existing global sales - show ✗
                    cell.addClass('global-unavailable-date');
                    cell.html('<span class="text-danger">✗</span><span class="small-icon">(sale)</span>');
                    cell.attr('title', `Sales already exist on ${date} - No new sales allowed`);
                    console.log(`DEBUG: Individual shuffle ${itemCode} - set cell ${index} to GLOBAL UNAVAILABLE`);

                } else if (isDryDate) {
                    // Date is a dry day - show 🌙
                    cell.addClass('dry-unavailable-date');
                    cell.html('<span class="text-warning">🌙</span><span class="small-icon">(dry day)</span>');
                    
                    // Get dry day description
                    const dryDescription = dryDaysInfo[date] || 'Dry Day';
                    cell.attr('title', `${dryDescription} - ${date} (Dry Day - No sales allowed)`);
                    console.log(`DEBUG: Individual shuffle ${itemCode} - set cell ${index} to DRY DAY`);

                } else if (isAvailable && qty > 0) {
                    // Date is available and has new sales
                    cell.addClass('available-date-with-sales');
                    cell.text(qty);
                    cell.attr('title', `${qty} units scheduled for ${date} (available date)`);
                    console.log(`DEBUG: Individual shuffle ${itemCode} - set cell ${index} to AVAILABLE WITH SALES`);

                } else if (qty > 0) {
                    cell.addClass('non-zero-distribution');
                    cell.text(qty);
                    cell.attr('title', `${qty} units scheduled for ${date}`);
                    console.log(`DEBUG: Individual shuffle ${itemCode} - set cell ${index} to NON-ZERO`);

                } else {
                    cell.addClass('zero-distribution');
                    cell.text('0');
                    cell.attr('title', `Date ${date} has 0 units assigned`);
                    console.log(`DEBUG: Individual shuffle ${itemCode} - set cell ${index} to ZERO`);
                }
            }
        });

        console.log(`Shuffled distribution for item ${itemCode}:`, newDistribution);
    } else {
        console.log(`DEBUG: Individual shuffle ${itemCode} - not shuffling (qty=${totalQty}, disabled=${inputField.prop('disabled')})`);
    }
});

// FIXED: Shuffle all button click event - correctly handles global restrictions
$('#shuffleBtn').off('click').on('click', async function() {
    console.log('DEBUG: Shuffle all button clicked');
    // Show loader
    $('#ajaxLoader').show();

    // Process all items with quantities
    const itemsToShuffle = [];
    $('input.qty-input').each(function() {
        const itemCode = $(this).data('code');
        const totalQty = parseInt($(this).val()) || 0;

        // Only shuffle if quantity > 0, visible, and not disabled
        if (totalQty > 0 && $(this).is(':visible') && !$(this).prop('disabled')) {
            itemsToShuffle.push({ itemCode, totalQty });
        }
    });

    console.log(`DEBUG: Shuffle all - found ${itemsToShuffle.length} items to shuffle:`, itemsToShuffle);

    // Shuffle each item using global restrictions
    for (const item of itemsToShuffle) {
        console.log(`DEBUG: Shuffle all - processing item ${item.itemCode}`);
        const newDistribution = shuffleDistributionForItem(item.itemCode, item.totalQty);

        // Update the distribution cells
        const inputField = $(`input[name="sale_qty[${item.itemCode}]"]`);
        const itemRow = inputField.closest('tr');
        const dateCells = itemRow.find('.date-distribution-cell');

        console.log(`DEBUG: Shuffle all - ${item.itemCode} has ${dateCells.length} date cells`);

        // Get global available/unavailable dates
        const hasGlobalRestriction = inputField.data('has-global-restriction');
        const availableDates = inputField.data('available-dates') || [];
        const unavailableDates = inputField.data('unavailable-dates') || [];
        const dryDates = inputField.data('dry-dates') || [];

        console.log(`DEBUG: Shuffle all - ${item.itemCode} availableDates:`, availableDates);
        console.log(`DEBUG: Shuffle all - ${item.itemCode} unavailableDates:`, unavailableDates);
        console.log(`DEBUG: Shuffle all - ${item.itemCode} dryDates:`, dryDates);

        newDistribution.forEach((qty, index) => {
            if (dateCells.eq(index).length) {
                const cell = dateCells.eq(index);
                const date = dateArray[index];

                console.log(`DEBUG: Shuffle all - ${item.itemCode} updating cell ${index} for date ${date} with qty ${qty}`);

                // Update styling and content based on value and availability
                cell.removeClass('zero-distribution non-zero-distribution global-unavailable-date dry-unavailable-date available-date-with-sales');

                // Check if this date is unavailable due to global sales
                const isGlobalUnavailable = unavailableDates.length > 0 && unavailableDates.includes(date);
                
                // Check if this date is a dry day
                const isDryDate = dryDates.length > 0 && dryDates.includes(date);

                // Check if this date is available
                const isAvailable = availableDates.length > 0 && availableDates.includes(date);

                console.log(`DEBUG: Shuffle all - ${item.itemCode} date ${date}: isGlobalUnavailable=${isGlobalUnavailable}, isDryDate=${isDryDate}, isAvailable=${isAvailable}`);

                if (isGlobalUnavailable && !isDryDate) {
                    // Date has existing global sales - show ✗
                    cell.addClass('global-unavailable-date');
                    cell.html('<span class="text-danger">✗</span><span class="small-icon">(sale)</span>');
                    cell.attr('title', `Sales already exist on ${date} - No new sales allowed`);
                    console.log(`DEBUG: Shuffle all - ${item.itemCode} set cell ${index} to GLOBAL UNAVAILABLE`);

                } else if (isDryDate) {
                    // Date is a dry day - show 🌙
                    cell.addClass('dry-unavailable-date');
                    cell.html('<span class="text-warning">🌙</span><span class="small-icon">(dry day)</span>');
                    
                    // Get dry day description
                    const dryDescription = dryDaysInfo[date] || 'Dry Day';
                    cell.attr('title', `${dryDescription} - ${date} (Dry Day - No sales allowed)`);
                    console.log(`DEBUG: Shuffle all - ${item.itemCode} set cell ${index} to DRY DAY`);

                } else if (isAvailable && qty > 0) {
                    // Date is available and has new sales
                    cell.addClass('available-date-with-sales');
                    cell.text(qty);
                    cell.attr('title', `${qty} units scheduled for ${date} (available date)`);
                    console.log(`DEBUG: Shuffle all - ${item.itemCode} set cell ${index} to AVAILABLE WITH SALES`);

                } else if (qty > 0) {
                    cell.addClass('non-zero-distribution');
                    cell.text(qty);
                    cell.attr('title', `${qty} units scheduled for ${date}`);
                    console.log(`DEBUG: Shuffle all - ${item.itemCode} set cell ${index} to NON-ZERO`);

                } else {
                    cell.addClass('zero-distribution');
                    cell.text('0');
                    cell.attr('title', `Date ${date} has 0 units assigned`);
                    console.log(`DEBUG: Shuffle all - ${item.itemCode} set cell ${index} to ZERO`);
                }
            }
        });
    }

    // Hide loader
    $('#ajaxLoader').hide();

    // Update total amount
    calculateTotalAmount();
    console.log('DEBUG: Shuffle all completed');
});

// OPTIMIZED: Document ready - Only process items with quantities > 0
$(document).ready(function() {
    console.log('Document ready - Initializing...');
    
    // Initialize table headers and columns
    initializeTableHeaders();
    
    // Set up row navigation with arrow keys
    setupRowNavigation();
    
    // Initialize quantities in visible inputs from session
    initializeQuantitiesFromSession();
    
    // Initialize distribution preview for items with quantities
    initializeDistributionPreview();
    
    // Initialize enhanced tooltips
    initializeRestrictionTooltips();
    
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
        // The validateQuantity function now handles distribution updates
        validateQuantity(this);
        
        // Update total amount
        calculateTotalAmount();
        
        // Also update total sales module if modal is open
        if ($('#totalSalesModal').hasClass('show')) {
            console.log('Modal is open, updating total sales module with ALL modes data...');
            updateTotalSalesModule();
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
        }
    });
    
    // Show date headers if any items have quantities > 0 and are not disabled
    const hasQuantities = $('input[name^="sale_qty"]').filter(function() { 
        return parseInt($(this).val()) > 0 && !$(this).prop('disabled');
    }).length > 0;
    
    if (hasQuantities) {
        $('.date-header').show();
    }
}
</script>
</body>
</html>