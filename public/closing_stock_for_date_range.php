<?php
session_start();
require_once 'drydays_functions.php';
require_once 'license_functions.php';
require_once 'cash_memo_functions.php';

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/closing_stock_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

function logArray($data, $title = 'Array data') {
    ob_start();
    print_r($data);
    $output = ob_get_clean();
    logMessage("$title:\n$output");
}

logMessage("=== PAGE ACCESS ===");
logMessage("Request method: " . $_SERVER['REQUEST_METHOD']);
logMessage("Search term: '" . ($_GET['search'] ?? '') . "'");
logMessage("Current session ID: " . session_id());

function clearSessionQuantities() {
    if (isset($_SESSION['sale_quantities'])) {
        unset($_SESSION['sale_quantities']);
        logMessage("Session quantities cleared");
    }
}

function clearSessionClosingBalances() {
    if (isset($_SESSION['closing_balances'])) {
        unset($_SESSION['closing_balances']);
        logMessage("Session closing balances cleared");
    }
}

function validateClosingBalance($current_stock, $closing_balance, $item_code) {
    if ($closing_balance < 0) {
        logMessage("Negative closing balance for item $item_code: $closing_balance", 'WARNING');
        return false;
    }
    
    if ($closing_balance > $current_stock) {
        logMessage("Closing balance ($closing_balance) exceeds current stock ($current_stock) for item $item_code", 'WARNING');
        return false;
    }
    
    return true;
}

function checkBackdatedSalesForItem($conn, $item_code, $start_date, $end_date, $comp_id) {
    $query = "SELECT sh.BILL_DATE
              FROM tblsaleheader sh
              JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO 
                AND sh.COMP_ID = sd.COMP_ID
              WHERE sd.ITEM_CODE = ? 
              AND sh.BILL_DATE >= ? 
              AND sh.COMP_ID = ?
              ORDER BY sh.BILL_DATE ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssi", $item_code, $start_date, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $existing_dates = [];
    while ($row = $result->fetch_assoc()) {
        $existing_dates[] = $row['BILL_DATE'];
    }
    $stmt->close();
    
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($begin, $interval, $end);
    
    $all_dates = [];
    foreach ($date_range as $date) {
        $all_dates[] = $date->format("Y-m-d");
    }
    
    if (!empty($existing_dates)) {
        $latest_existing = max($existing_dates);
        $latest_existing_date = new DateTime($latest_existing);
        
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
        
        logMessage("Item $item_code: Latest existing sale: $latest_existing", 'INFO');
        logMessage("Available dates: " . implode(', ', $available_dates), 'INFO');
        logMessage("Unavailable dates (has existing sales): " . implode(', ', $unavailable_dates), 'INFO');
        
        return [
            'restricted' => !empty($unavailable_dates),
            'latest_existing_sale' => $latest_existing,
            'available_dates' => $available_dates,
            'unavailable_dates' => $unavailable_dates,
            'all_existing_dates' => $existing_dates,
            'message' => !empty($unavailable_dates) ? 
                "Sales exist on: " . implode(', ', $unavailable_dates) . ". Available dates: " . implode(', ', $available_dates) :
                "No sales restrictions for this item"
        ];
    }
    
    return [
        'restricted' => false,
        'latest_existing_sale' => null,
        'available_dates' => $all_dates,
        'unavailable_dates' => [],
        'all_existing_dates' => [],
        'message' => "No sales restrictions for this item"
    ];
}

function checkItemsBackdatedForDateRange($conn, $items_with_dates, $comp_id) {
    if (empty($items_with_dates)) return [];
    
    $restricted_items = [];
    
    foreach ($items_with_dates as $item_code => $date_range) {
        $start_date = $date_range['start_date'];
        $end_date = $date_range['end_date'];
        
        $result = checkBackdatedSalesForItem($conn, $item_code, $start_date, $end_date, $comp_id);
        
        if ($result['restricted'] && empty($result['available_dates'])) {
            $restricted_items[$item_code] = [
                'code' => $item_code,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'latest_existing_sale' => $result['latest_existing_sale'],
                'available_dates' => $result['available_dates'],
                'unavailable_dates' => $result['unavailable_dates'],
                'message' => $result['message']
            ];
        }
    }
    
    return $restricted_items;
}


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

include_once "../config/db.php";

$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

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

include_once "volume_limit_utils.php";
include_once "stock_functions.php";

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
$sequence_type = isset($_GET['sequence_type']) ? $_GET['sequence_type'] : 'user_defined';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$comp_id = $_SESSION['CompID'];
$current_stock_column = "Current_Stock" . $comp_id;
$opening_stock_column = "Opening_Stock" . $comp_id;

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

$end_date_day = date('d', strtotime($end_date));
$closing_column = "DAY_" . sprintf('%02d', $end_date_day) . "_CLOSING";
$end_date_month = date('Y-m', strtotime($end_date));

$current_month = date('Y-m');
$end_date_month_year = date('m_Y', strtotime($end_date));

if ($end_date_month === $current_month) {
    $daily_stock_table = "tbldailystock_" . $comp_id;
    $table_suffix = "";
} else {
    $end_date_month_short = date('m', strtotime($end_date));
    $end_date_year_short = date('y', strtotime($end_date));
    $daily_stock_table = "tbldailystock_" . $comp_id . "_" . $end_date_month_short . "_" . $end_date_year_short;
    $table_suffix = "_" . $end_date_month_short . "_" . $end_date_year_short;
}

$order_clause = "";
if ($sequence_type === 'system_defined') {
    $order_clause = "ORDER BY im.CODE ASC";
} elseif ($sequence_type === 'group_defined') {
    $order_clause = "ORDER BY im.DETAILS2 ASC, im.DETAILS ASC";
} else {
    $order_clause = "ORDER BY im.DETAILS ASC";
}

$items_per_page = 50;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

$check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
$table_result = $conn->query($check_table_query);
$table_exists = $table_result->num_rows > 0;

if (!$table_exists) {
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
        DAY_17_SALES DECIMAL(10,3) DEFAULT 0.000,
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
    } else {
        logMessage("Failed to create daily stock table: " . $conn->error, 'ERROR');
    }
}

if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $count_query = "SELECT COUNT(DISTINCT im.CODE) as total 
                    FROM tblitemmaster im
                    LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                        AND ds.STK_MONTH = ?
                    WHERE im.LIQ_FLAG = ? 
                    AND im.CLASS IN ($class_placeholders)
                    AND COALESCE(ds.$closing_column, 0) > 0";
    
    $count_params = array_merge([$end_date_month, $mode], $allowed_classes);
    $count_types = str_repeat('s', count($count_params));
} else {
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

if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                     ds.STK_MONTH as stock_month
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                  AND ds.STK_MONTH = ?
              WHERE im.LIQ_FLAG = ? 
              AND im.CLASS IN ($class_placeholders)
              AND COALESCE(ds.$closing_column, 0) > 0";
    
    $params = array_merge([$end_date_month, $mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
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

$total_pages = ceil($total_items / $items_per_page);

if (!isset($_SESSION['closing_balances'])) {
    $_SESSION['closing_balances'] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['closing_balance'])) {
    foreach ($_POST['closing_balance'] as $item_code => $closing_balance) {
        $closing_val = floatval($closing_balance);
        $_SESSION['closing_balances'][$item_code] = $closing_val;
    }
    
    logMessage("Session closing balances updated from POST: " . count($_SESSION['closing_balances']) . " items");
}

if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS, im.LIQ_FLAG, im.RPRICE,
                               COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                               ds.STK_MONTH as stock_month
                        FROM tblitemmaster im
                        LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE
                            AND ds.STK_MONTH = ?
                        WHERE im.CLASS IN ($class_placeholders)";

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

$begin = new DateTime($start_date);
$end = new DateTime($end_date);
$end = $end->modify('+1 day');

$interval = new DateInterval('P1D');
$date_range = new DatePeriod($begin, $interval, $end);

$date_array = [];
foreach ($date_range as $date) {
    $date_array[] = $date->format("Y-m-d");
}
$days_count = count($date_array);

function updateItemStockFromClosing($conn, $item_code, $closing_balance, $current_stock_column, $opening_stock_column, $fin_year_id, $current_stock) {
    $sale_qty = $current_stock - $closing_balance;
    
    if ($sale_qty <= 0) {
        return 0;
    }
    
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
        $stock_stmt->bind_param("ds", $sale_qty, $item_code);
        $stock_stmt->execute();
        $stock_stmt->close();
    } else {
        $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_stock_column, $current_stock_column) 
                               VALUES (?, ?, ?, ?)";
        $insert_stock_stmt = $conn->prepare($insert_stock_query);
        $new_current_stock = -$sale_qty;
        $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $new_current_stock, $new_current_stock);
        $insert_stock_stmt->execute();
        $insert_stock_stmt->close();
    }
    
    return $sale_qty;
}

function distributeSalesIntelligently($conn, $sale_qty, $item_code, $start_date, $end_date, $comp_id, $date_array) {
    if ($sale_qty <= 0) return array_fill(0, count($date_array), 0);
    
    $date_check = checkBackdatedSalesForItem($conn, $item_code, $start_date, $end_date, $comp_id);
    $available_dates = $date_check['available_dates'];
    
    if (empty($available_dates)) {
        logMessage("Item $item_code has no available dates for distribution", 'INFO');
        return array_fill(0, count($date_array), 0);
    }
    
    $date_index_map = [];
    foreach ($date_array as $index => $date) {
        $date_index_map[$date] = $index;
    }
    
    $available_days = count($available_dates);
    $distribution = distributeSalesUniformlyBasic($sale_qty, $available_days);
    
    $full_distribution = array_fill(0, count($date_array), 0);
    
    foreach ($available_dates as $i => $date) {
        $index = $date_index_map[$date] ?? null;
        if ($index !== null) {
            $full_distribution[$index] = $distribution[$i] ?? 0;
        }
    }
    
    logMessage("Item $item_code: Distributing $sale_qty across $available_days available dates: " . implode(', ', $available_dates), 'INFO');
    
    return $full_distribution;
}

function distributeSalesUniformlyBasic($sale_qty, $days_count) {
    if ($sale_qty <= 0 || $days_count <= 0) return array_fill(0, $days_count, 0);
    
    $base_qty = floor($sale_qty / $days_count);
    $remainder = $sale_qty % $days_count;
    
    $daily_sales = array_fill(0, $days_count, $base_qty);
    
    for ($i = 0; $i < $remainder; $i++) {
        $daily_sales[$i]++;
    }
    
    shuffle($daily_sales);
    
    return $daily_sales;
}

function getNextBillNumber($conn, $comp_id) {
    logMessage("Getting next bill number for CompID: $comp_id", 'INFO');
    
    $conn->begin_transaction();
    
    try {
        $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader WHERE COMP_ID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $comp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $next_bill = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
        $stmt->close();
        
        $check_query = "SELECT COUNT(*) as count FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
        $check_stmt = $conn->prepare($check_query);
        $bill_no_to_check = "BL" . str_pad($next_bill, 4, '0', STR_PAD_LEFT);
        $check_stmt->bind_param("si", $bill_no_to_check, $comp_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $exists = $check_result->fetch_assoc()['count'] > 0;
        $check_stmt->close();
        
        if ($exists) {
            $next_bill++;
        }
        
        $conn->commit();
        logMessage("Next bill number for CompID $comp_id: $next_bill", 'INFO');
        
        return $next_bill;
        
    } catch (Exception $e) {
        $conn->rollback();
        logMessage("Error getting next bill number for CompID $comp_id: " . $e->getMessage(), 'ERROR');
        
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '1024M');
    
    $conn->query("SET SESSION wait_timeout = 28800");
    $conn->query("SET autocommit = 0");
    
    $bulk_operation = (count($_SESSION['closing_balances'] ?? []) > 100);
    
    if ($bulk_operation) {
        logMessage("Starting bulk sales operation with " . count($_SESSION['closing_balances']) . " items - Performance mode enabled", 'INFO');
    }
    
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
            
            if (isset($_SESSION['closing_balances']) && !empty($_SESSION['closing_balances'])) {
                $items_with_dates = [];
                
                foreach ($_SESSION['closing_balances'] as $item_code => $closing_balance) {
                    if (isset($all_items_data[$item_code])) {
                        $current_stock = $all_items_data[$item_code]['CURRENT_STOCK'];
                        if ($closing_balance < $current_stock) {
                            $items_with_dates[$item_code] = [
                                'start_date' => $start_date,
                                'end_date' => $end_date
                            ];
                        }
                    }
                }
                
                if (!empty($items_with_dates)) {
                    $restricted_items = checkItemsBackdatedForDateRange($conn, $items_with_dates, $comp_id);
                    
                    if (!empty($restricted_items)) {
                        $error_message = "<strong>Cannot enter sales for the following items in the selected date range:</strong><br><br>";
                        
                        foreach ($restricted_items as $item_code => $restriction) {
                            $item_name = isset($all_items_data[$item_code]['DETAILS']) ? 
                                $all_items_data[$item_code]['DETAILS'] : $item_code;
                            
                            $error_message .= "<div class='mb-2'>";
                            $error_message .= "<strong>$item_name ($item_code)</strong><br>";
                            $error_message .= "<small>Selected Date Range: <span class='text-primary'>{$restriction['start_date']} to {$restriction['end_date']}</span></small><br>";
                            $error_message .= "<small>Latest Existing Sale: <span class='text-danger'>{$restriction['latest_existing_sale']}</span></small><br>";
                            $error_message .= "<small>Available Dates: <span class='text-success'>" . 
                                (empty($restriction['available_dates']) ? 'None' : implode(', ', $restriction['available_dates'])) . 
                                "</span></small><br>";
                            $error_message .= "<small><em>{$restriction['message']}</em></small>";
                            $error_message .= "</div>";
                        }
                        
                        $error_message .= "<br><div class='alert alert-warning mt-2'>";
                        $error_message .= "<strong>Solution:</strong> Please adjust your date range to be after the latest existing sale date for each restricted item, or remove these items from your sales entry.";
                        $error_message .= "</div>";
                        
                        logMessage("Backdated sales prevented for items with no available dates: " . implode(', ', array_keys($restricted_items)), 'WARNING');
                        
                        goto render_page;
                    }
                }
            }
            
            $validation_errors = [];
            if (isset($_SESSION['closing_balances'])) {
                $item_count = 0;
                foreach ($_SESSION['closing_balances'] as $item_code => $closing_balance) {
                    $item_count++;
                    if ($bulk_operation && $item_count % 50 == 0) {
                        logMessage("Validation progress: $item_count/" . count($_SESSION['closing_balances']) . " items checked", 'INFO');
                    }
                    
                    if (isset($all_items_data[$item_code])) {
                        $current_stock = $all_items_data[$item_code]['CURRENT_STOCK'];
                        
                        if (!validateClosingBalance($current_stock, $closing_balance, $item_code)) {
                            $validation_errors[] = "Item {$item_code}: Current stock {$current_stock}, Closing balance {$closing_balance}";
                        }
                    }
                }
            }
            
            if (!empty($validation_errors)) {
                $error_message = "Closing balance validation failed:<br>" . implode("<br>", array_slice($validation_errors, 0, 5));
                if (count($validation_errors) > 5) {
                    $error_message .= "<br>... and " . (count($validation_errors) - 5) . " more errors";
                }
            } else {
                $conn->begin_transaction();
                
                try {
                    $total_amount = 0;
                    $items_data = [];
                    $daily_sales_data = [];
                    
                    if (isset($_SESSION['closing_balances'])) {
                        $item_count = 0;
                        foreach ($_SESSION['closing_balances'] as $item_code => $closing_balance) {
                            $item_count++;
                            if ($bulk_operation && $item_count % 50 == 0) {
                                logMessage("Processing progress: $item_count/" . count($_SESSION['closing_balances']) . " items processed", 'INFO');
                            }
                            
                            if (isset($all_items_data[$item_code])) {
                                $item = $all_items_data[$item_code];
                                $current_stock = $item['CURRENT_STOCK'];
                                
                                $sale_qty = $current_stock - $closing_balance;
                                
                                if ($sale_qty > 0) {
                                    $daily_sales = distributeSalesIntelligently($conn, $sale_qty, $item_code, $start_date, $end_date, $comp_id, $date_array);
                                    $daily_sales_data[$item_code] = $daily_sales;
                                    
                                    $items_data[$item_code] = [
                                        'name' => $item['DETAILS'],
                                        'rate' => $item['RPRICE'],
                                        'total_qty' => $sale_qty,
                                        'mode' => $item['LIQ_FLAG']
                                    ];
                                }
                            }
                        }
                    }
                    
                    if (!empty($items_data)) {
                        $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
                        
                        $current_stock_column = "Current_Stock" . $comp_id;
                        $opening_stock_column = "Opening_Stock" . $comp_id;
                        
                        $next_bill_number = getNextBillNumber($conn, $comp_id);
                        
                        usort($bills, function($a, $b) {
                            return strtotime($a['bill_date']) - strtotime($b['bill_date']);
                        });
                        
                        $bill_count = 0;
                        $total_bills = count($bills);
                        
                        foreach ($bills as $bill) {
                            $bill_count++;
                            if ($bulk_operation && $bill_count % 10 == 0) {
                                logMessage("Bill generation progress: $bill_count/$total_bills bills created", 'INFO');
                            }
                            
                            $padded_bill_no = "BL" . str_pad($next_bill_number++, 4, '0', STR_PAD_LEFT);
                            
                            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                             VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                            $header_stmt = $conn->prepare($header_query);
                            $header_stmt->bind_param("ssddssi", $padded_bill_no, $bill['bill_date'], $bill['total_amount'], 
                                                    $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
                            $header_stmt->execute();
                            $header_stmt->close();
                            
                            foreach ($bill['items'] as $item) {
                                $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                                $detail_stmt = $conn->prepare($detail_query);
                                $detail_stmt->bind_param("ssddssi", $padded_bill_no, $item['code'], $item['qty'], 
                                                        $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                                $detail_stmt->execute();
                                $detail_stmt->close();
                                
                                $current_stock = $all_items_data[$item['code']]['CURRENT_STOCK'];
                                updateItemStockFromClosing($conn, $item['code'], $_SESSION['closing_balances'][$item['code']], $current_stock_column, $opening_stock_column, $fin_year_id, $current_stock);
                            }
                            
                            $total_amount += $bill['total_amount'];
                        }

                        $cash_memos_generated = 0;
                        $cash_memo_errors = [];

                        if (count($bills) > 0) {
                            logMessage("Starting optimized cash memo generation for " . count($bills) . " bills", 'INFO');
                            
                            $cash_memo_start_time = time();
                            $MAX_CASH_MEMO_TIME = 30;
                            $cash_memo_count = 0;
                            
                            foreach ($bills as $bill_index => $bill) {
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
                                }
                                
                                if (count($bills) > 50 && $cash_memo_count % 10 == 0) {
                                    usleep(100000);
                                }
                            }
                            
                            logMessage("Cash memo generation completed: $cash_memos_generated successful, " . count($cash_memo_errors) . " failed", 'INFO');
                        }

                        $conn->commit();

                        clearSessionClosingBalances();

                        $success_message = "Sales distributed successfully! Generated " . count($bills) . " bills. Total Amount: ₹" . number_format($total_amount, 2);

                        if ($cash_memos_generated > 0) {
                            $success_message .= " | Cash Memos Generated: " . $cash_memos_generated;
                        }

                        if (!empty($cash_memo_errors)) {
                            $success_message .= " | Failed to generate cash memos for bills: " . implode(", ", array_slice($cash_memo_errors, 0, 5));
                            if (count($cash_memo_errors) > 5) {
                                $success_message .= " and " . (count($cash_memo_errors) - 5) . " more";
                            }
                        }

                        unset($all_items);
                        unset($items_data);
                        unset($daily_sales_data);
                        unset($bills);
                        gc_collect_cycles();
                        
                        if ($bulk_operation) {
                            logMessage("Bulk sales operation completed successfully", 'INFO');
                        }

                        header("Location: retail_sale.php?success=" . urlencode($success_message));
                        exit;
                    } else {
                        $error_message = "No sales calculated from closing balances.";
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_message = "Error updating sales: " . $e->getMessage();
                    logMessage("Transaction rolled back: " . $e->getMessage(), 'ERROR');
                }
            }
        }
    }
    
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
}

render_page:

if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

logMessage("Final session closing balances count: " . count($_SESSION['closing_balances']), 'INFO');
logMessage("Items in current view: " . count($items), 'INFO');

$debug_info = [
    'total_items' => $total_items,
    'current_page' => $current_page,
    'total_pages' => $total_pages,
    'session_closing_balances_count' => count($_SESSION['closing_balances']),
    'post_closing_balances_count' => ($_SERVER['REQUEST_METHOD'] === 'POST') ? count($_POST['closing_balance'] ?? []) : 0,
    'date_range' => "$start_date to $end_date",
    'days_count' => $days_count,
    'user_id' => $_SESSION['user_id'],
    'comp_id' => $comp_id,
    'license_type' => $license_type,
    'allowed_classes' => $allowed_classes,
    'end_date_day' => $end_date_day,
    'closing_column' => $closing_column,
    'end_date_month' => $end_date_month,
    'stock_filter' => '> 0',
    'daily_stock_table' => $daily_stock_table,
    'table_suffix' => $table_suffix,
    'current_month' => date('Y-m')
];
logArray($debug_info, "Closing Stock Page Load Debug Info");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Closing Stock for Date Range - Enter Closing Balances</title>
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
   
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type="number"] {
      -moz-appearance: textfield;
    }
    
    .highlight-row {
      background-color: #f8f9fa !important;
      box-shadow: 0 0 5px rgba(0,0,0,0.1);
    }
    
    .volume-limit-info {
      background-color: #e9ecef;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
    }

    .text-warning {
        color: #ffc107 !important;
    }

    .fw-bold {
        font-weight: bold !important;
    }

    .text-danger {
        color: #dc3545 !important;
        background-color: #f8d7da;
    }

    .styled-table tbody tr {
        height: 35px !important;
        line-height: 1.2 !important;
    }

    .styled-table tbody td {
        padding: 4px 8px !important;
    }

    .closing-input {
        height: 30px !important;
        padding: 2px 6px !important;
    }

    .btn-sm {
        padding: 2px 6px !important;
        font-size: 12px !important;
    }

    tr.has-closing {
        background-color: #e8f5e8 !important;
        border-left: 3px solid #28a745 !important;
    }

    tr.has-closing td {
        font-weight: 500;
    }

    tr.backdated-restriction {
        background-color: #f8d7da !important;
        border-left: 4px solid #dc3545 !important;
    }

    tr.backdated-restriction td {
        color: #721c24 !important;
        font-weight: 600;
    }

    tr.backdated-restriction .closing-input {
        background-color: #f5c6cb !important;
        border-color: #f5c6cb !important;
        color: #721c24 !important;
        cursor: not-allowed !important;
    }

    .badge.bg-danger {
        font-size: 10px;
        padding: 3px 8px;
        max-width: 200px;
        white-space: normal;
        text-align: left;
    }

    .backdated-tooltip {
        max-width: 300px !important;
        white-space: normal !important;
    }

    .is-invalid {
        border-color: #dc3545 !important;
        box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
    }

    .closing-saving {
        background-color: #e8f5e8 !important;
        transition: background-color 0.3s ease;
    }

    .closing-error {
        background-color: #f8d7da !important;
        transition: background-color 0.3s ease;
    }

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

    .pagination-ellipsis {
        display: inline-block;
        padding: 6px 12px;
        margin: 2px;
        color: #6c757d;
    }

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

    .stock-integer {
        font-weight: bold;
    }

    .stock-decimal {
        display: none !important;
    }

    .compact-info {
        font-size: 11px;
        color: #6c757d;
    }

    .workflow-indicator {
        background-color: #d1ecf1;
        border-left: 4px solid #17a2b8;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 15px;
    }

    .workflow-indicator h6 {
        color: #0c5460;
        margin-bottom: 5px;
    }

    .workflow-indicator small {
        color: #6c757d;
        font-style: italic;
    }
    
    .sale-qty-cell {
        font-weight: bold;
        text-align: center;
        background-color: #f8f9fa;
        color: #198754;
        background-color: rgba(25, 135, 84, 0.1);
    }

    .sale-qty-cell.positive {
        color: #198754;
        background-color: rgba(25, 135, 84, 0.1);
    }

    .sale-qty-cell.zero {
        color: #6c757d;
        background-color: #f8f9fa;
    }

    .auto-calculated-cell {
        background-color: #e9ecef;
        font-weight: bold;
        text-align: center;
    }

    .closing-input.highlight {
        background-color: #fff3cd !important;
        border-color: #ffc107 !important;
    }

    /* Date distribution cell styling */
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

    .date-distribution-cell.unavailable-date {
        background-color: #f8d7da !important;
        color: #721c24 !important;
    }

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

    /* Make sure the table layout doesn't break with date columns */
    .table-container {
        overflow-x: auto;
        max-width: 100%;
    }

    .styled-table {
        min-width: 1200px; /* Minimum width to ensure all columns are visible */
    }

    /* Action column adjustment */
    .action-column {
        width: 120px !important;
        min-width: 120px !important;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Closing Stock for Date Range - Enter Closing Balances</h3>

      <div class="workflow-indicator">
        <h6><i class="fas fa-exchange-alt"></i> NEW WORKFLOW: Enter Closing Balance</h6>
        <small>Enter closing balance → System auto-calculates sale quantity (Sale Qty = Available Stock - Closing Balance)</small>
      </div>

      <div class="alert alert-info mb-3 py-2">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0 compact-info">Showing items with available stock > 0</p>
      </div>

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
            </label>
          </div>
          
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Apply Date Range</button>
          </div>
        </form>
      </div>

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
            <?php if (count($_SESSION['closing_balances']) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['closing_balances']) ?> items with closing balances</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <form method="POST" id="salesForm" action="">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
        <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">
        <input type="hidden" name="update_sales" value="1">

        <div class="d-flex gap-2 mb-3 flex-wrap">
           <button type="button" id="shuffleBtn" class="btn btn-warning btn-action">
             <i class="fas fa-random"></i> Shuffle All
           </button>

           <button type="button" id="generateBillsBtn" class="btn btn-success btn-action">
             <i class="fas fa-save"></i> Generate Bills from Closing Balances
           </button>

           <button type="button" id="clearSessionBtn" class="btn btn-danger">
             <i class="fas fa-trash"></i> Clear All Closing Balances
           </button>

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

        <div class="table-container">
          <table class="styled-table table-striped" id="itemsTable">
            <thead class="table-header">
              <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Rate (₹)</th>
                <th>Available Stock</th>
                <th class="text-primary">Enter Closing Balance</th>
                <th class="text-success auto-calculated-header">Sale Qty (Auto-calculated)</th>
                <th class="action-column">Action</th>

                <!-- Date Distribution Headers (will be populated by JavaScript) -->

                <th>Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
<?php if (!empty($items)): ?>
    <?php foreach ($items as $item): 
        $item_code = $item['CODE'];
        $closing_balance = isset($_SESSION['closing_balances'][$item_code]) ? $_SESSION['closing_balances'][$item_code] : $item['CURRENT_STOCK'];
        $sale_qty = $item['CURRENT_STOCK'] - $closing_balance;
        $item_total = $sale_qty * $item['RPRICE'];
        
        $display_stock = floor($item['CURRENT_STOCK']);
        $display_rate = intval($item['RPRICE']);
        $display_closing = floor($closing_balance);
        $display_sale_qty = floor($sale_qty);
        $display_amount = intval($item_total);
        
        $size = 0;
        if (preg_match('/(\d+)\s*ML\b/i', $item['DETAILS'], $matches)) {
            $size = $matches[1];
        }
        
        $class_code = $item['CLASS'] ?? 'O';
        
        $stock_status_class = '';
        if ($item['CURRENT_STOCK'] <= 0) {
            $stock_status_class = 'stock-out';
        } elseif ($item['CURRENT_STOCK'] < 10) {
            $stock_status_class = 'stock-low';
        } else {
            $stock_status_class = 'stock-available';
        }
        
        $backdated_check = checkBackdatedSalesForItem($conn, $item_code, $start_date, $end_date, $comp_id);
        $has_backdated_restriction = $backdated_check['restricted'];
        $latest_existing = $backdated_check['latest_existing_sale'] ?? null;
        $available_dates = $backdated_check['available_dates'] ?? [];
        $unavailable_dates = $backdated_check['unavailable_dates'] ?? [];
        
        $has_available_dates = !empty($available_dates);
        
        $should_disable_input = $has_backdated_restriction && !$has_available_dates;
        
        $backdated_class = $should_disable_input ? 'backdated-restriction' : '';
        $backdated_title = $should_disable_input ? 
            "Sales exist on: " . implode(', ', $unavailable_dates) . ". No available dates in selected range." : 
            ($has_backdated_restriction ? 
                "Sales exist on: " . implode(', ', $unavailable_dates) . ". Available dates: " . implode(', ', $available_dates) : 
                '');
        
        $partial_class = ($has_backdated_restriction && $has_available_dates) ? 'partial-date-warning' : '';
        
        $has_sales = $closing_balance < $item['CURRENT_STOCK'];
    ?>
        <tr data-class="<?= htmlspecialchars($class_code) ?>" 
    data-details="<?= htmlspecialchars($item['DETAILS']) ?>" 
    data-details2="<?= htmlspecialchars($item['DETAILS2']) ?>"
    class="<?= $has_sales ? 'has-closing' : '' ?> <?= $backdated_class ?> <?= $partial_class ?>"
    data-has-backdated-restriction="<?= $has_backdated_restriction ? 'true' : 'false' ?>"
    data-available-dates="<?= htmlspecialchars(json_encode($available_dates)) ?>"
    data-unavailable-dates="<?= htmlspecialchars(json_encode($unavailable_dates)) ?>"
    data-latest-existing="<?= htmlspecialchars($latest_existing ?? '') ?>">
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
                    <?php if ($has_backdated_restriction && $has_available_dates): ?>
                        <span class="badge bg-warning" data-bs-toggle="tooltip" 
                              title="Sales exist on <?= implode(', ', $unavailable_dates) ?>. New sales will be distributed on available dates only.">
                            <i class="fas fa-calendar-day"></i> Partial Dates
                        </span>
                    <?php endif; ?>
                </span>
            </td>
            <td>
                <input type="number" name="closing_balance[<?= htmlspecialchars($item_code); ?>]" 
                       class="form-control closing-input" min="0" 
                       max="<?= floor($item['CURRENT_STOCK']); ?>" 
                       step="0.001" value="<?= $closing_balance ?>" 
                       data-rate="<?= $item['RPRICE'] ?>"
                       data-code="<?= htmlspecialchars($item_code); ?>"
                       data-stock="<?= $item['CURRENT_STOCK'] ?>"
                       data-size="<?= $size ?>"
                       data-has-backdated-restriction="<?= $has_backdated_restriction ? 'true' : 'false' ?>"
                       data-available-dates="<?= htmlspecialchars(json_encode($available_dates)) ?>"
                       data-unavailable-dates="<?= htmlspecialchars(json_encode($unavailable_dates)) ?>"
                       oninput="validateClosingBalance(this)"
                       <?= $should_disable_input ? 'disabled title="' . htmlspecialchars($backdated_title) . '"' : '' ?>>
            </td>
            <td class="sale-qty-cell <?= $sale_qty > 0 ? 'positive' : 'zero' ?>" id="sale_qty_<?= htmlspecialchars($item_code); ?>">
                <span class="stock-integer"><?= number_format($display_sale_qty) ?></span>
                <div class="compact-info">
                    <small>= <?= $display_stock ?> - <?= $display_closing ?></small>
                </div>
            </td>
            <td class="action-column">
                <?php if ($should_disable_input): ?>
                    <span class="badge bg-danger" data-bs-toggle="tooltip"
                          title="<?= htmlspecialchars($backdated_title) ?>">
                        <i class="fas fa-calendar-times"></i> No Available Dates
                    </span>
                <?php elseif ($has_backdated_restriction && $has_available_dates): ?>
                    <span class="badge bg-warning" data-bs-toggle="tooltip"
                          title="Sales exist on <?= implode(', ', $unavailable_dates) ?>. New sales will be distributed on available dates only.">
                        <i class="fas fa-calendar-day"></i> Available: <?= count($available_dates) ?> dates
                    </span>
                <?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-shuffle-item"
                            data-code="<?= htmlspecialchars($item_code); ?>"
                            title="Shuffle closing balance for this item">
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
            </div>
        </td>
    </tr>
<?php endif; ?>
</tbody>
            </tfoot>
          </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                
                <?php
                $show_pages = 5;
                $start_page = max(1, $current_page - floor($show_pages / 2));
                $end_page = min($total_pages, $start_page + $show_pages - 1);
                
                if ($end_page - $start_page < $show_pages - 1) {
                    $start_page = max(1, $end_page - $show_pages + 1);
                }
                
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
                
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                <?php endfor;
                
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
                
                <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
        <div class="pagination-info">
            Showing <?= count($items) ?> of <?= $total_items ?> items with stock > 0 (Page <?= $current_page ?> of <?= $total_pages ?>)
            <?php if (count($_SESSION['closing_balances']) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['closing_balances']) ?> items with closing balances across all pages</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <div id="ajaxLoader" class="ajax-loader">
          <div class="loader"></div>
          <p>Calculating distribution...</p>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<div class="modal fade" id="salesLogModal" tabindex="-1" aria-labelledby="salesLogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="salesLogModalLabel">Sales Log - Foreign Export</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="salesLogContent" style="max-height: 400px; overflow-y: auto;">
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
const dateArray = <?= json_encode($date_array) ?>;
const daysCount = <?= $days_count ?>;
const allSessionClosingBalances = <?= json_encode($_SESSION['closing_balances'] ?? []) ?>;
const allItemsData = <?= json_encode($all_items_data) ?>;

function saveClosingBalanceToSession(itemCode, closingBalance) {
    if (typeof saveClosingBalanceToSession.debounce === 'undefined') {
        saveClosingBalanceToSession.debounce = null;
    }
    
    clearTimeout(saveClosingBalanceToSession.debounce);
    saveClosingBalanceToSession.debounce = setTimeout(() => {
        $.ajax({
            url: 'update_session_quantity.php',
            type: 'POST',
            data: {
                item_code: itemCode,
                quantity: closingBalance
            },
            success: function(response) {
                console.log('Closing balance saved to session:', itemCode, closingBalance);
                allSessionClosingBalances[itemCode] = closingBalance;
            },
            error: function(xhr, status, error) {
                console.error('Failed to save closing balance to session:', error);
                alert('Error saving closing balance. Please try again.');
            }
        });
    }, 500);
}

function clearSessionClosingBalances() {
    $.ajax({
        url: 'clear_session_quantities.php',
        type: 'POST',
        dataType: 'json',
        success: function(response) {
            console.log('Session closing balances cleared:', response);
            location.reload();
        },
        error: function(xhr, status, error) {
            console.error('Error clearing session closing balances:', error);
            alert('Error clearing closing balances. Please try again.');
        }
    });
}

function saveToPendingSales() {
    let hasEntries = false;
    for (const itemCode in allSessionClosingBalances) {
        hasEntries = true;
        break;
    }
    
    if (!hasEntries) {
        alert('Please enter closing balances for at least one item.');
        return false;
    }
    
    $('#ajaxLoader').show();
    $('#generateBillsBtn').prop('disabled', true).addClass('btn-loading');
    
    const formData = new FormData();
    formData.append('save_pending', 'true');
    formData.append('start_date', '<?= $start_date ?>');
    formData.append('end_date', '<?= $end_date ?>');
    formData.append('mode', '<?= $mode ?>');
    
    for (const itemCode in allSessionClosingBalances) {
        const closingBalance = allSessionClosingBalances[itemCode];
        const currentStock = allItemsData[itemCode]?.CURRENT_STOCK || 0;
        const saleQty = currentStock - closingBalance;
        formData.append(`all_sale_qty[${itemCode}]`, saleQty > 0 ? saleQty : 0);
    }
    
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
                    clearSessionClosingBalances();
                    alert('Sales data saved to pending successfully! You can generate bills later from the "Post Daily Sales" page.');
                    window.location.href = 'retail_sale.php?success=' + encodeURIComponent(result.message);
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (e) {
                console.error('Error parsing response:', e, response);
                alert('Error saving to pending sales. Please check console for details.');
            }
        },
        error: function(xhr, status, error) {
            $('#ajaxLoader').hide();
            $('#generateBillsBtn').prop('disabled', false).removeClass('btn-loading');
            console.error('AJAX error:', error);
            alert('Error saving data to pending. Please try again.');
        }
    });
}

function generateBills() {
    console.log('generateBills function called');
    
    let hasEntries = false;
    let validationErrors = [];
    
    for (const itemCode in allSessionClosingBalances) {
        hasEntries = true;
        const closingBalance = allSessionClosingBalances[itemCode];
        
        if (closingBalance < 0) {
            validationErrors.push(`Item ${itemCode}: Closing balance cannot be negative`);
        }
        
        if (allItemsData[itemCode]) {
            const currentStock = allItemsData[itemCode].CURRENT_STOCK;
            if (closingBalance > currentStock) {
                validationErrors.push(`Item ${itemCode}: Closing balance (${closingBalance}) exceeds current stock (${currentStock})`);
            }
        }
    }
    
    if (!hasEntries) {
        alert('Please enter closing balances for at least one item.');
        return false;
    }
    
    if (validationErrors.length > 0) {
        alert('Validation errors:\n\n' + validationErrors.join('\n'));
        return false;
    }
    
    console.log('Validation passed, submitting form...');
    
    $('#ajaxLoader').show();
    $('#generateBillsBtn').prop('disabled', true).addClass('btn-loading');
    
    setTimeout(() => {
        console.log('Actually submitting form now');
        document.getElementById('salesForm').submit();
    }, 500);
    
    return true;
}

function validateClosingBalance(input) {
    const itemCode = $(input).data('code');
    const currentStock = parseFloat($(input).data('stock'));
    let closingBalance = parseFloat($(input).val()) || 0;
    
    if ($(input).prop('disabled')) {
        return false;
    }
    
    if (isNaN(closingBalance) || closingBalance < 0) {
        closingBalance = 0;
        $(input).val(0);
    }
    
    if (closingBalance > currentStock) {
        closingBalance = currentStock;
        $(input).val(currentStock);
        
        $(input).addClass('is-invalid');
        setTimeout(() => $(input).removeClass('is-invalid'), 2000);
    } else {
        $(input).removeClass('is-invalid');
    }
    
    if (closingBalance !== currentStock) {
        $(input).addClass('highlight');
    } else {
        $(input).removeClass('highlight');
    }
    
    updateItemUIFromClosing(itemCode, closingBalance, currentStock);

    // Update distribution preview
    updateDistributionPreviewWithAvailableDates(itemCode, closingBalance);

    saveClosingBalanceToSession(itemCode, closingBalance);

    return true;
}

function updateItemUIFromClosing(itemCode, closingBalance, currentStock) {
    const rate = parseFloat($(`input[name="closing_balance[${itemCode}]"]`).data('rate'));
    const saleQty = currentStock - closingBalance;
    const amount = saleQty * rate;
    
    const displaySaleQty = Math.floor(saleQty);
    const displayAmount = Math.floor(amount);
    const displayCurrentStock = Math.floor(currentStock);
    const displayClosingBalance = Math.floor(closingBalance);
    
    $(`#sale_qty_${itemCode}`).html(`
        <span class="stock-integer">${displaySaleQty}</span>
        <div class="compact-info">
            <small>= ${displayCurrentStock} - ${displayClosingBalance}</small>
        </div>
    `);
    $(`#amount_${itemCode}`).html(`<span class="stock-integer">${displayAmount}</span>`);
    
    const row = $(`input[name="closing_balance[${itemCode}]"]`).closest('tr');
    row.toggleClass('has-closing', closingBalance < currentStock);
    
    const saleQtyCell = $(`#sale_qty_${itemCode}`);
    saleQtyCell.removeClass('positive zero').addClass(saleQty > 0 ? 'positive' : 'zero');
}

function handleGenerateBills() {
    let hasEntries = false;
    for (const itemCode in allSessionClosingBalances) {
        hasEntries = true;
        break;
    }
    
    if (!hasEntries) {
        alert('Please enter closing balances for at least one item.');
        return false;
    }
    
    const userChoice = confirm(
        "Generate Bills Options:\n\n" +
        "Click OK to generate bills immediately (will update stock and create actual sales).\n\n" +
        "Click Cancel to save to pending sales (will save for later processing, no stock update)."
    );
    
    console.log('User choice:', userChoice);
    
    if (userChoice === true) {
        console.log('Calling generateBills()');
        generateBills();
    } else {
        console.log('Calling saveToPendingSales()');
        saveToPendingSales();
    }
}

function loadSalesLog() {
    $('#salesLogContent').html(`
        <div class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading sales log...</p>
        </div>
    `);
    
    $.ajax({
        url: 'sales_log_ajax.php',
        type: 'GET',
        dataType: 'html',
        success: function(response) {
            $('#salesLogContent').html(response);
        },
        error: function(xhr, status, error) {
            console.error('Error loading sales log:', error);
            $('#salesLogContent').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load sales log. Please try again.
                </div>
            `);
        }
    });
}

function initializeClosingBalancesFromSession() {
    $('input[name^="closing_balance"]').each(function() {
        const itemCode = $(this).data('code');
        if (allSessionClosingBalances[itemCode] !== undefined) {
            const sessionClosing = allSessionClosingBalances[itemCode];
            $(this).val(sessionClosing);
            
            const currentStock = parseFloat($(this).data('stock'));
            updateItemUIFromClosing(itemCode, sessionClosing, currentStock);
        }
    });
}

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
    const inputField = $(`input[name="closing_balance[${itemCode}]"]`);
    if (inputField.length > 0) {
        const itemRow = inputField.closest('tr');
        return {
            classCode: itemRow.data('class'),
            details: itemRow.data('details'),
            details2: itemRow.data('details2'),
            quantity: parseInt(allSessionClosingBalances[itemCode]) || 0
        };
    } else {
        // If not in current view, get from allItemsData
        if (allItemsData[itemCode]) {
            return {
                classCode: allItemsData[itemCode].CLASS,
                details: allItemsData[itemCode].DETAILS,
                details2: allItemsData[itemCode].DETAILS2,
                quantity: allSessionClosingBalances[itemCode] || 0
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

    console.log('Processing ALL session closing balances:', allSessionClosingBalances);

    // Process ALL session quantities > 0 (from ALL modes)
    let processedItems = 0;
    for (const itemCode in allSessionClosingBalances) {
        const quantity = allSessionClosingBalances[itemCode];
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
        $(`<th class="date-header" title="${date}">${day}<br>${month}</th>`).insertAfter($('.table-header tr th.action-column'));
    });
}

// Function to handle row navigation with arrow keys
function setupRowNavigation() {
    const qtyInputs = $('input.closing-input');
    let currentRowIndex = -1;

    // Highlight row when input is focused
    $(document).on('focus', 'input.closing-input', function() {
        // Remove highlight from all rows
        $('tr').removeClass('highlight-row');

        // Add highlight to current row
        $(this).closest('tr').addClass('highlight-row');

        // Update current row index
        currentRowIndex = qtyInputs.index(this);
    });

    // Handle arrow key navigation
    $(document).on('keydown', 'input.closing-input', function(e) {
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

// FIXED: Enhanced function to update distribution preview - for closing stock, show potential sale distribution
function updateDistributionPreviewWithAvailableDates(itemCode, closingBalance) {
    console.log(`DEBUG: updateDistributionPreviewWithAvailableDates called for ${itemCode} with closing ${closingBalance}`);
    const inputField = $(`input[name="closing_balance[${itemCode}]"]`);
    const itemRow = inputField.closest('tr');

    const currentStock = parseFloat(inputField.data('stock'));
    const saleQty = currentStock - closingBalance;

    if (saleQty <= 0) {
        // Remove distribution cells if no sales
        itemRow.find('.date-distribution-cell').remove();

        // Hide date columns if no items have sales
        if ($('input[name^="closing_balance"]').filter(function() {
            const stock = parseFloat($(this).data('stock'));
            const balance = parseFloat($(this).val()) || stock;
            return balance < stock;
        }).length === 0) {
            $('.date-header, .date-distribution-cell').hide();
        }

        return;
    }

    // Get available and unavailable dates from data attributes
    const hasBackdatedRestriction = inputField.data('has-backdated-restriction');
    const availableDates = inputField.data('available-dates') || [];
    const unavailableDates = inputField.data('unavailable-dates') || [];

    console.log(`DEBUG: ${itemCode} - hasBackdatedRestriction: ${hasBackdatedRestriction}`);
    console.log(`DEBUG: ${itemCode} - availableDates:`, availableDates);
    console.log(`DEBUG: ${itemCode} - unavailableDates:`, unavailableDates);

    // Create date index map
    const dateIndexMap = {};
    dateArray.forEach((date, index) => {
        dateIndexMap[date] = index;
    });

    // For closing stock, show a preview of how sales would be distributed
    let distribution = new Array(daysCount).fill(0);

    if (hasBackdatedRestriction && availableDates.length > 0) {
        // Distribute only on available dates
        const availableDaysCount = availableDates.length;
        const baseQty = Math.floor(saleQty / availableDaysCount);
        const remainder = saleQty % availableDaysCount;

        const dailySalesForAvailableDates = new Array(availableDaysCount).fill(baseQty);

        // Distribute remainder
        for (let i = 0; i < remainder; i++) {
            dailySalesForAvailableDates[i]++;
        }

        // Shuffle the distribution
        for (let i = dailySalesForAvailableDates.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [dailySalesForAvailableDates[i], dailySalesForAvailableDates[j]] = [dailySalesForAvailableDates[j], dailySalesForAvailableDates[i]];
        }

        // Place the distributed quantities in the correct date positions
        availableDates.forEach((date, index) => {
            const dateIndex = dateIndexMap[date];
            if (dateIndex !== undefined) {
                distribution[dateIndex] = dailySalesForAvailableDates[index];
            }
        });

        console.log(`Item ${itemCode}: Preview distribution ${saleQty} on ${availableDaysCount} available dates`);
    } else if (!hasBackdatedRestriction || availableDates.length === daysCount) {
        // Distribute across all dates
        const baseQty = Math.floor(saleQty / daysCount);
        const remainder = saleQty % daysCount;

        distribution = new Array(daysCount).fill(baseQty);

        // Distribute remainder evenly across days
        for (let i = 0; i < remainder; i++) {
            distribution[i]++;
        }

        // Shuffle the distribution
        for (let i = distribution.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [distribution[i], distribution[j]] = [distribution[j], distribution[i]];
        }

        console.log(`Item ${itemCode}: Preview distribution ${saleQty} across all ${daysCount} dates`);
    } else {
        console.log(`Item ${itemCode}: No available dates for distribution preview`);
    }

    // Remove any existing distribution cells
    itemRow.find('.date-distribution-cell').remove();

    // Add date distribution cells
    distribution.forEach((qty, index) => {
        const date = dateArray[index];
        const cell = $(`<td class="date-distribution-cell"></td>`);

        // Check if this date is unavailable due to existing sales
        const isUnavailable = hasBackdatedRestriction &&
                              unavailableDates.length > 0 &&
                              unavailableDates.includes(date);

        const isAvailable = hasBackdatedRestriction &&
                            availableDates.length > 0 &&
                            availableDates.includes(date);

        if (isUnavailable) {
            // Date has existing sales - show ✗
            cell.addClass('unavailable-date');
            cell.html('<span class="text-danger">✗</span>');
            cell.attr('title', `Sales already exist on ${date} - No new sales allowed`);
        } else if (isAvailable && qty > 0) {
            // Date is available and has sales
            cell.addClass('available-date-cell');
            cell.text(qty);
            cell.attr('title', `${qty} units would be sold on ${date} (available date)`);
        } else if (qty > 0) {
            // Normal distribution
            cell.addClass('non-zero-distribution');
            cell.text(qty);
            cell.attr('title', `${qty} units would be sold on ${date}`);
        } else {
            // Zero quantity
            cell.addClass('zero-distribution');
            cell.text('0');
            cell.attr('title', `No sales on ${date}`);
        }

        // Insert distribution cells after the action column
        cell.insertAfter(itemRow.find('.action-column'));
    });

    // Show date columns
    $('.date-header, .date-distribution-cell').show();
}

// Function to initialize distribution preview for all items with sales
function initializeDistributionPreview() {
    console.log('Initializing distribution preview for items with sales...');

    let itemsWithSales = 0;
    $('input[name^="closing_balance"]').each(function() {
        const itemCode = $(this).data('code');
        const currentStock = parseFloat($(this).data('stock'));
        const closingBalance = parseFloat($(this).val()) || currentStock;

        if (closingBalance < currentStock) {
            updateDistributionPreviewWithAvailableDates(itemCode, closingBalance);
            itemsWithSales++;
        }
    });

    console.log(`Initialized distribution preview for ${itemsWithSales} items with sales`);
}

$(document).ready(function() {
    console.log('Document ready - Initializing with existing endpoints...');
    console.log('Available session closing balances:', Object.keys(allSessionClosingBalances).length);

    // DEBUG: Log missing functionalities
    console.log("DEBUG: Checking for missing functionalities");
    console.log("DEBUG: Shuffle all button exists: " + ($('#shuffleBtn').length > 0));
    console.log("DEBUG: Shuffle single buttons exist: " + ($('.btn-shuffle-item').length > 0));
    console.log("DEBUG: Total sales summary button exists: " + ($('button[data-bs-target="#totalSalesModal"]').length > 0));
    console.log("DEBUG: Total sales modal exists: " + ($('#totalSalesModal').length > 0));

    // Initialize table headers and columns
    initializeTableHeaders();

    // Set up row navigation with arrow keys
    setupRowNavigation();

    initializeClosingBalancesFromSession();

    // Initialize distribution preview for items with sales
    initializeDistributionPreview();

    $('[data-bs-toggle="tooltip"]').tooltip();

    $('#clearSessionBtn').click(function() {
        if (confirm('Are you sure you want to clear all closing balances? This action cannot be undone.')) {
            clearSessionClosingBalances();
        }
    });

    $('#generateBillsBtn').click(function(e) {
        e.preventDefault();
        handleGenerateBills();
    });

    let closingTimeout;
    $(document).on('input', 'input.closing-input', function(e) {
        clearTimeout(closingTimeout);
        closingTimeout = setTimeout(() => {
            validateClosingBalance(this);
        }, 300);
    });

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

    // Shuffle all functionality
    $('#shuffleBtn').off('click').on('click', async function() {
        console.log('Shuffle all button clicked');
        $('#ajaxLoader').show();

        // Process all items with closing balances
        const itemsToShuffle = [];
        $('input.closing-input').each(function() {
            const itemCode = $(this).data('code');
            const closingBalance = parseFloat($(this).val()) || 0;
            const currentStock = parseFloat($(this).data('stock'));

            // Only shuffle if closing balance < current stock (meaning there are sales)
            if (closingBalance < currentStock && !$(this).prop('disabled')) {
                const saleQty = currentStock - closingBalance;
                itemsToShuffle.push({ itemCode, saleQty, currentStock });
            }
        });

        console.log('Shuffle all - found items to shuffle:', itemsToShuffle.length);

        // For closing stock, shuffling means randomly redistributing the sale quantities across dates
        // Since we don't have date distribution in closing stock, we'll just randomize the closing balances
        // while maintaining the same total sales quantity

        for (const item of itemsToShuffle) {
            // Generate a new random closing balance between 0 and current stock
            const newClosingBalance = Math.floor(Math.random() * (item.currentStock + 1));

            // Update the input field
            const inputField = $(`input[name="closing_balance[${item.itemCode}]"]`);
            inputField.val(newClosingBalance);

            // Update UI
            updateItemUIFromClosing(item.itemCode, newClosingBalance, item.currentStock);

            // Save to session
            saveClosingBalanceToSession(item.itemCode, newClosingBalance);
        }

        $('#ajaxLoader').hide();
        console.log('Shuffle all completed');
    });

    // Individual shuffle button functionality
    $(document).on('click', '.btn-shuffle-item', function() {
        const itemCode = $(this).data('code');
        console.log('Individual shuffle clicked for:', itemCode);

        const inputField = $(`input[name="closing_balance[${itemCode}]"]`);
        const currentStock = parseFloat(inputField.data('stock'));
        const currentClosing = parseFloat(inputField.val()) || currentStock;

        // Only shuffle if closing balance < current stock (meaning there are sales)
        if (currentClosing < currentStock && !inputField.prop('disabled')) {
            // Generate a new random closing balance
            const newClosingBalance = Math.floor(Math.random() * (currentStock + 1));

            // Update the input field
            inputField.val(newClosingBalance);

            // Update UI
            updateItemUIFromClosing(itemCode, newClosingBalance, currentStock);

            // Save to session
            saveClosingBalanceToSession(itemCode, newClosingBalance);

            console.log(`Shuffled item ${itemCode}: closing balance ${currentClosing} -> ${newClosingBalance}`);
        } else {
            console.log(`Cannot shuffle item ${itemCode}: closing=${currentClosing}, stock=${currentStock}, disabled=${inputField.prop('disabled')}`);
        }
    });
});
</script>
</body>
</html>