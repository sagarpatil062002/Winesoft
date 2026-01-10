<?php
session_start();

// Include necessary functions
require_once 'drydays_functions.php';
require_once 'license_functions.php';
require_once 'cash_memo_functions.php';

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/customer_sales_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Function to log array data
function logArray($data, $title = 'Array data') {
    ob_start();
    print_r($data);
    $output = ob_get_clean();
    logMessage("$title:\n$output");
}

// DEBUG: Log page access
logMessage("=== CUSTOMER SALES PAGE ACCESS ===");
logMessage("Request method: " . $_SERVER['REQUEST_METHOD']);

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
// LICENSE-BASED FILTERING
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
// CUSTOMER MANAGEMENT
// ============================================================================

// Fetch customers from tbllheads
$customers = [];
$customerQuery = "SELECT LCODE, LHEAD FROM tbllheads WHERE GCODE=32 ORDER BY LHEAD";
$customerResult = $conn->query($customerQuery);
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $customers[$row['LCODE']] = $row['LHEAD'];
    }
} else {
    logMessage("Error fetching customers: " . $conn->error, 'ERROR');
}

// Handle customer selection/creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle customer selection/creation
    if (isset($_POST['customer_field'])) {
        $customerField = trim($_POST['customer_field']);

        if (!empty($customerField)) {
            // Check if it's a new customer (starts with "new:" or doesn't match existing customer codes)
            if (preg_match('/^new:/i', $customerField) || !is_numeric($customerField)) {
                // Extract customer name (remove "new:" prefix if present)
                $customerName = preg_replace('/^new:\s*/i', '', $customerField);

                if (!empty($customerName)) {
                    // Get the next available LCODE for GCODE=32
                    $maxCodeQuery = "SELECT MAX(LCODE) as max_code FROM tbllheads WHERE GCODE=32";
                    $maxResult = $conn->query($maxCodeQuery);
                    $maxCode = 1;
                    if ($maxResult && $maxResult->num_rows > 0) {
                        $maxData = $maxResult->fetch_assoc();
                        $maxCode = $maxData['max_code'] + 1;
                    }

                    // Insert new customer
                    $insertQuery = "INSERT INTO tbllheads (GCODE, LCODE, LHEAD) VALUES (32, ?, ?)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("is", $maxCode, $customerName);

                    if ($stmt->execute()) {
                        $_SESSION['selected_customer'] = $maxCode;
                        $_SESSION['success_message'] = "Customer '$customerName' created successfully!";
                        logMessage("New customer created: $customerName (ID: $maxCode)", 'INFO');

                        // Refresh customers list
                        $customerResult = $conn->query($customerQuery);
                        $customers = [];
                        if ($customerResult) {
                            while ($row = $customerResult->fetch_assoc()) {
                                $customers[$row['LCODE']] = $row['LHEAD'];
                            }
                        }
                    } else {
                        $_SESSION['error_message'] = "Error creating customer: " . $conn->error;
                        logMessage("Error creating customer: " . $conn->error, 'ERROR');
                    }
                    $stmt->close();
                }
            } else {
                // It's an existing customer code
                $customerCode = intval($customerField);
                if (array_key_exists($customerCode, $customers)) {
                    $_SESSION['selected_customer'] = $customerCode;
                    $_SESSION['success_message'] = "Customer selected successfully!";
                    logMessage("Customer selected: ID $customerCode", 'INFO');
                } else {
                    $_SESSION['error_message'] = "Invalid customer code!";
                    logMessage("Invalid customer code: $customerCode", 'WARNING');
                }
            }
        } else {
            // Empty field means walk-in customer
            $_SESSION['selected_customer'] = '';
            $_SESSION['success_message'] = "Walk-in customer selected!";
            logMessage("Walk-in customer selected", 'INFO');
        }

        // Redirect to avoid form resubmission
        header("Location: customer_sales.php");
        exit;
    }
}

// Get selected customer from session
$selectedCustomer = isset($_SESSION['selected_customer']) ? $_SESSION['selected_customer'] : '';

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

// Date selection (default to current day)
$sale_date = isset($_GET['sale_date']) ? $_GET['sale_date'] : date('Y-m-d');

// Get company ID
$comp_id = $_SESSION['CompID'];
$current_stock_column = "Current_Stock" . $comp_id;
$opening_stock_column = "Opening_Stock" . $comp_id;

// Check if the stock columns exist, if not create them
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

// Calculate the day number for the sale_date to get closing balance
$sale_day = date('d', strtotime($sale_date));
$closing_column = "DAY_" . sprintf('%02d', $sale_day) . "_CLOSING";
$sale_month = date('Y-m', strtotime($sale_date));

// Determine which daily stock table to use based on sale_date
$current_month = date('Y-m');
$sale_month_year = date('m_Y', strtotime($sale_date));

if ($sale_month === $current_month) {
    // Use current month table (no suffix)
    $daily_stock_table = "tbldailystock_" . $comp_id;
    $table_suffix = "";
} else {
    // Use archived month table (with suffix mm_yyyy)
    $sale_month_short = date('m', strtotime($sale_date));
    $sale_year_short = date('y', strtotime($sale_date));
    $daily_stock_table = "tbldailystock_" . $comp_id . "_" . $sale_month_short . "_" . $sale_year_short;
    $table_suffix = "_" . $sale_month_short . "_" . $sale_year_short;
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
$items_per_page = 50;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Check if the required daily stock table exists
$check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
$table_result = $conn->query($check_table_query);
$table_exists = $table_result->num_rows > 0;

if (!$table_exists) {
    // Table doesn't exist, create it
    createDailyStockTable($conn, $daily_stock_table);
    $table_exists = true;
}

// MODIFIED: Get total count for pagination with license filtering AND stock > 0 condition
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $count_query = "SELECT COUNT(DISTINCT im.CODE) as total 
                    FROM tblitemmaster im
                    LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                        AND ds.STK_MONTH = ?
                    WHERE im.LIQ_FLAG = ? 
                    AND im.CLASS IN ($class_placeholders)
                    AND COALESCE(ds.$closing_column, 0) > 0";
    
    $count_params = array_merge([$sale_month, $mode], $allowed_classes);
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

// MODIFIED: Main query with pagination, license filtering AND stock > 0 condition
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
    
    $params = array_merge([$sale_month, $mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                     ds.STK_MONTH as stock_month
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                  AND ds.STK_MONTH = ?
              WHERE 1 = 0";
    $params = [$sale_month];
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
// SESSION QUANTITY PRESERVATION
// ============================================================================

// Initialize session if not exists
if (!isset($_SESSION['customer_sale_quantities'])) {
    $_SESSION['customer_sale_quantities'] = [];
}

// Handle form submission to update session quantities
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sale_qty'])) {
    foreach ($_POST['sale_qty'] as $item_code => $qty) {
        $qty_val = intval($qty);
        if ($qty_val > 0) {
            $_SESSION['customer_sale_quantities'][$item_code] = $qty_val;
        } else {
            unset($_SESSION['customer_sale_quantities'][$item_code]);
        }
    }
    
    logMessage("Customer session quantities updated: " . count($_SESSION['customer_sale_quantities']) . " items");
}

// MODIFIED: Get ALL items data for JavaScript from ALL modes
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS, im.LIQ_FLAG, im.RPRICE,
                               COALESCE(ds.$closing_column, 0) as CURRENT_STOCK,
                               ds.STK_MONTH as stock_month
                        FROM tblitemmaster im
                        LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                            AND ds.STK_MONTH = ?
                        WHERE im.CLASS IN ($class_placeholders) 
                        AND COALESCE(ds.$closing_column, 0) > 0";
    
    $all_items_stmt = $conn->prepare($all_items_query);
    $all_items_params = array_merge([$sale_month], $allowed_classes);
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
    $all_items_params = [$sale_month];
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

// ============================================================================
// FUNCTIONS FROM sale_for_date_range.php
// ============================================================================

// Function to clear session quantities
function clearSessionQuantities() {
    if (isset($_SESSION['customer_sale_quantities'])) {
        unset($_SESSION['customer_sale_quantities']);
        logMessage("Customer session quantities cleared");
    }
}

// Enhanced stock validation function
function validateStock($current_stock, $requested_qty, $item_code) {
    if ($requested_qty <= 0) return true;
    
    if ($requested_qty > $current_stock) {
        logMessage("Stock validation failed for item $item_code: Available: $current_stock, Requested: $requested_qty", 'WARNING');
        return false;
    }
    
    if ($current_stock - $requested_qty < 0) {
        logMessage("Negative closing balance prevented for item $item_code", 'WARNING');
        return false;
    }
    
    return true;
}

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
        $current_stock = -$qty;
        $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $current_stock, $current_stock);
        $insert_stock_stmt->execute();
        $insert_stock_stmt->close();
    }
}

// Function to get the correct daily stock table for a specific date
function getDailyStockTableForDate($conn, $comp_id, $date) {
    $current_date = new DateTime();
    $sale_date = new DateTime($date);
    
    if ($sale_date > $current_date) {
        logMessage("Sale date $date is in future, using current month table", 'WARNING');
        return "tbldailystock_" . $comp_id;
    }
    
    $current_month = $current_date->format('Y-m');
    $date_month = $sale_date->format('Y-m');
    
    if ($date_month === $current_month) {
        return "tbldailystock_" . $comp_id;
    } else {
        $date_month_short = $sale_date->format('m');
        $date_year_short = $sale_date->format('y');
        return "tbldailystock_" . $comp_id . "_" . $date_month_short . "_" . $date_year_short;
    }
}

// Function to recalculate daily stock from a specific day onward
function recalculateDailyStockFromDay($conn, $table_name, $item_code, $stk_month, $start_day = 1) {
    logMessage("Recalculating stock from day $start_day for item $item_code in $stk_month in table $table_name", 'INFO');
    
    $current_date = new DateTime();
    $table_month = new DateTime($stk_month . '-01');
    $last_day_of_month = date('t', strtotime($stk_month . '-01'));
    
    for ($day = $start_day; $day <= 31; $day++) {
        $day_num = sprintf('%02d', $day);
        $opening_column = "DAY_{$day_num}_OPEN";
        $purchase_column = "DAY_{$day_num}_PURCHASE";
        $sales_column = "DAY_{$day_num}_SALES";
        $closing_column = "DAY_{$day_num}_CLOSING";
        
        $check_columns = "SHOW COLUMNS FROM $table_name LIKE '$opening_column'";
        $column_result = $conn->query($check_columns);
        
        if ($column_result->num_rows == 0) {
            continue;
        }
        
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
            
            $closing = $opening + $purchase - $sales;
            
            $update_query = "UPDATE $table_name 
                            SET $closing_column = ?,
                                LAST_UPDATED = CURRENT_TIMESTAMP 
                            WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("dss", $closing, $item_code, $stk_month);
            $update_stmt->execute();
            $update_stmt->close();
            
            $next_day = $day + 1;
            if ($next_day <= $last_day_of_month && $next_day <= 31) {
                $next_day_num = sprintf('%02d', $next_day);
                $next_opening_column = "DAY_{$next_day_num}_OPEN";
                
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
        
        if ($day >= $last_day_of_month) {
            break;
        }
    }
    
    if ($start_day == 1) {
        $prev_month = date('Y-m', strtotime($stk_month . '-01 -1 month'));
        if ($prev_month) {
            $prev_table = getDailyStockTableForDate($conn, $_SESSION['CompID'], $prev_month . '-01');
            
            $check_prev_table = "SHOW TABLES LIKE '$prev_table'";
            if ($conn->query($check_prev_table)->num_rows > 0) {
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
                        
                        $update_opening_query = "UPDATE $table_name 
                                                SET DAY_01_OPEN = ?,
                                                    LAST_UPDATED = CURRENT_TIMESTAMP 
                                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                        $update_opening_stmt = $conn->prepare($update_opening_query);
                        $update_opening_stmt->bind_param("dss", $prev_closing, $stk_month, $item_code);
                        $update_opening_stmt->execute();
                        $update_opening_stmt->close();
                    }
                }
                $prev_stmt->close();
            }
        }
    }
}

// Function to update daily stock table
function updateDailyStock($conn, $item_code, $sale_date, $qty, $comp_id) {
    $sale_daily_stock_table = getDailyStockTableForDate($conn, $comp_id, $sale_date);
    $current_daily_stock_table = "tbldailystock_" . $comp_id;
    
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $sales_column = "DAY_{$day_num}_SALES";
    $closing_column = "DAY_{$day_num}_CLOSING";
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    
    $month_year_full = date('Y-m', strtotime($sale_date));
    $sale_date_obj = new DateTime($sale_date);
    $current_date = new DateTime();
    
    // Check if table exists
    $check_table_query = "SHOW TABLES LIKE '$sale_daily_stock_table'";
    $table_result = $conn->query($check_table_query);
    
    if ($table_result->num_rows == 0) {
        createDailyStockTable($conn, $sale_daily_stock_table);
    }
    
    // Check if record exists
    $check_query = "SELECT $closing_column, $opening_column, $purchase_column, $sales_column 
                    FROM $sale_daily_stock_table 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $month_year_full, $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        $check_stmt->close();
        
        $prev_month = date('Y-m', strtotime($month_year_full . '-01 -1 month'));
        $prev_table = getDailyStockTableForDate($conn, $comp_id, $prev_month . '-01');
        
        $prev_closing = 0;
        if ($prev_month) {
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
        
        $insert_query = "INSERT INTO $sale_daily_stock_table 
                        (ITEM_CODE, STK_MONTH, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                        VALUES (?, ?, ?, 0, 0, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssdd", $item_code, $month_year_full, $prev_closing, $prev_closing);
        $insert_stmt->execute();
        $insert_stmt->close();
        
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
    
    if ($current_closing < $qty) {
        $available_stock = $current_opening + $current_purchase - $current_sales;
        if ($available_stock < $qty) {
            throw new Exception("Insufficient closing stock for item $item_code on $sale_date. Available: $available_stock, Requested: $qty");
        }
        $current_closing = $available_stock;
    }
    
    $new_sales = $current_sales + $qty;
    $new_closing = $current_opening + $current_purchase - $new_sales;
    
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
    
    recalculateDailyStockFromDay($conn, $sale_daily_stock_table, $item_code, $month_year_full, $day_num);
    
    logMessage("Daily stock updated successfully for item $item_code on $sale_date: Sales=$new_sales, Closing=$new_closing", 'INFO');
    
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
    
    if ($conn->query($create_query)) {
        logMessage("Created daily stock table: $table_name", 'INFO');
        return true;
    } else {
        logMessage("Failed to create daily stock table: " . $conn->error, 'ERROR');
        return false;
    }
}

// Function to get next bill number
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

// Function to get next customer bill number
function getNextCustomerBillNumber($conn, $comp_id) {
    logMessage("Getting next customer bill number for CompID: $comp_id", 'INFO');
    
    $query = "SELECT MAX(BillNo) as max_bill FROM tblcustomersales WHERE CompID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $next_bill = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
    $stmt->close();
    
    logMessage("Next customer bill number for CompID $comp_id: $next_bill", 'INFO');
    return $next_bill;
}

// ============================================================================
// HANDLE SALE FINALIZATION WITH BILL GENERATION
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['finalize_sale']) || isset($_POST['generate_bills']))) {
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '1024M');
    
    $conn->query("SET SESSION wait_timeout = 28800");
    $conn->query("SET autocommit = 0");
    
    // Validate customer selection
    if (empty($selectedCustomer) && $selectedCustomer !== '') {
        $_SESSION['error'] = "Please select a customer before finalizing sale";
        header("Location: customer_sales.php");
        exit;
    }
    
    // Validate items in cart
    if (empty($_SESSION['customer_sale_quantities'])) {
        $_SESSION['error'] = "No items in cart to finalize sale";
        header("Location: customer_sales.php");
        exit;
    }
    
    // Check if we have any quantities > 0
    $hasQuantity = false;
    $itemsWithQuantity = [];
    foreach ($_SESSION['customer_sale_quantities'] as $item_code => $qty) {
        if ($qty > 0) {
            $hasQuantity = true;
            $itemsWithQuantity[] = $item_code;
            break;
        }
    }
    
    if (!$hasQuantity) {
        $_SESSION['error'] = "Please enter quantities for at least one item";
        header("Location: customer_sales.php");
        exit;
    }
    
    // Check for backdated sales
    if (!empty($itemsWithQuantity)) {
        // Function from sale_for_date_range.php to check backdated sales
        include_once 'check_backdated_functions.php';
        
        $items_with_dates = [];
        foreach ($itemsWithQuantity as $item_code) {
            if ($_SESSION['customer_sale_quantities'][$item_code] > 0) {
                $items_with_dates[$item_code] = [
                    'start_date' => $sale_date,
                    'end_date' => $sale_date
                ];
            }
        }
        
        if (!empty($items_with_dates)) {
            // You'll need to create check_backdated_functions.php with this function
            // $restricted_items = checkItemsBackdatedForDateRange($conn, $items_with_dates, $comp_id);
            
            // For now, we'll skip this check but you should implement it
            $restricted_items = [];
            
            if (!empty($restricted_items)) {
                $error_message = "<strong>Cannot enter sales for the following items on the selected date:</strong><br><br>";
                
                foreach ($restricted_items as $item_code => $restriction) {
                    $item_name = isset($all_items_data[$item_code]['DETAILS']) ? 
                        $all_items_data[$item_code]['DETAILS'] : $item_code;
                    
                    $error_message .= "<div class='mb-2'>";
                    $error_message .= "<strong>$item_name ($item_code)</strong><br>";
                    $error_message .= "<small>Selected Date: <span class='text-primary'>{$restriction['start_date']}</span></small><br>";
                    $error_message .= "<small>Existing Sales: <span class='text-danger'>{$restriction['earliest_existing_sale']} to {$restriction['latest_existing_sale']}</span></small><br>";
                    $error_message .= "</div>";
                }
                
                $_SESSION['error'] = $error_message;
                header("Location: customer_sales.php");
                exit;
            }
        }
    }
    
    // Enhanced stock validation before transaction
    $stock_errors = [];
    $item_count = 0;
    foreach ($_SESSION['customer_sale_quantities'] as $item_code => $total_qty) {
        $item_count++;
        
        if ($total_qty > 0 && isset($all_items_data[$item_code])) {
            $current_stock = $all_items_data[$item_code]['CURRENT_STOCK'];
            
            if (!validateStock($current_stock, $total_qty, $item_code)) {
                $stock_errors[] = "Item {$item_code}: Available stock {$current_stock}, Requested {$total_qty}";
            }
        }
    }
    
    // If stock errors found, stop processing
    if (!empty($stock_errors)) {
        $_SESSION['error'] = "Stock validation failed:<br>" . implode("<br>", array_slice($stock_errors, 0, 5));
        if (count($stock_errors) > 5) {
            $_SESSION['error'] .= "<br>... and " . (count($stock_errors) - 5) . " more errors";
        }
        header("Location: customer_sales.php");
        exit;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        $comp_id = $_SESSION['CompID'];
        $user_id = $_SESSION['user_id'];
        $fin_year_id = $_SESSION['FIN_YEAR_ID'];
        $current_date = $sale_date;
        
        // Get next bill numbers
        $retail_bill_number = getNextBillNumber($conn, $comp_id);
        $retail_bill_no = "BL" . str_pad($retail_bill_number, 4, '0', STR_PAD_LEFT);
        
        // Get customer bill number
        $customer_bill_no = getNextCustomerBillNumber($conn, $comp_id);
        
        // Process items for both tables
        $total_amount = 0;
        $customer_total_amount = 0;
        $items_data = [];
        
        // Get customer-specific prices if available
        $customer_prices = [];
        if ($selectedCustomer !== '') {
            $priceQuery = "SELECT Code, WPrice FROM tblcustomerprices WHERE LCode = ?";
            $priceStmt = $conn->prepare($priceQuery);
            $priceStmt->bind_param("i", $selectedCustomer);
            $priceStmt->execute();
            $priceResult = $priceStmt->get_result();
            while ($priceRow = $priceResult->fetch_assoc()) {
                $customer_prices[$priceRow['Code']] = $priceRow['WPrice'];
            }
            $priceStmt->close();
        }
        
        // Also get any price from tblcustomerprices (fallback)
        $anyPriceQuery = "SELECT Code, WPrice FROM tblcustomerprices GROUP BY Code";
        $anyPriceResult = $conn->query($anyPriceQuery);
        $any_prices = [];
        while ($priceRow = $anyPriceResult->fetch_assoc()) {
            $any_prices[$priceRow['Code']] = $priceRow['WPrice'];
        }
        
        foreach ($_SESSION['customer_sale_quantities'] as $item_code => $qty) {
            if ($qty > 0 && isset($all_items_data[$item_code])) {
                $item = $all_items_data[$item_code];
                
                // Determine price - priority: customer price > any customer price > retail price
                $price = $customer_prices[$item_code] ?? ($any_prices[$item_code] ?? $item['RPRICE']);
                $amount = $qty * $price;
                $total_amount += $amount;
                $customer_total_amount += $amount;
                
                $items_data[$item_code] = [
                    'code' => $item_code,
                    'name' => $item['DETAILS'],
                    'size' => $item['DETAILS2'],
                    'rate' => $price,
                    'qty' => $qty,
                    'amount' => $amount,
                    'mode' => $item['LIQ_FLAG'],
                    'class' => $item['CLASS']
                ];
            }
        }
        
        if (empty($items_data)) {
            throw new Exception("No valid items to process");
        }
        
        // Determine mode for retail sale (use most common mode from items)
        $mode_counts = [];
        foreach ($items_data as $item) {
            $mode = $item['mode'];
            $mode_counts[$mode] = isset($mode_counts[$mode]) ? $mode_counts[$mode] + 1 : 1;
        }
        arsort($mode_counts);
        $retail_mode = key($mode_counts);
        
        // ========== PART 1: Save to tblcustomersales (if customer is selected) ==========
        if ($selectedCustomer !== '') {
            $customer_success = true;
            
            foreach ($items_data as $item_code => $item) {
                $customerInsertQuery = "INSERT INTO tblcustomersales (BillNo, BillDate, LCode, ItemCode, ItemName, ItemSize, Rate, Quantity, Amount, CompID, UserID) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $customerInsertStmt = $conn->prepare($customerInsertQuery);
                
                $customerInsertStmt->bind_param(
                    "isisssdiiii", 
                    $customer_bill_no, 
                    $current_date, 
                    $selectedCustomer, 
                    $item_code, 
                    $item['name'], 
                    $item['size'], 
                    $item['rate'], 
                    $item['qty'], 
                    $item['amount'],
                    $comp_id,
                    $user_id
                );
                
                if (!$customerInsertStmt->execute()) {
                    $customer_success = false;
                    logMessage("Error saving to customer sales for item $item_code: " . $conn->error, 'ERROR');
                    break;
                }
                $customerInsertStmt->close();
            }
            
            if (!$customer_success) {
                throw new Exception("Error saving to customer sales table");
            }
        }
        
        // ========== PART 2: Save to retail sales (tblsaleheader/tblsaledetails) ==========
        // Insert sale header
        $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                         VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
        $header_stmt = $conn->prepare($header_query);
        $header_stmt->bind_param("ssddssi", $retail_bill_no, $current_date, $total_amount, 
                                $total_amount, $retail_mode, $comp_id, $user_id);
        if (!$header_stmt->execute()) {
            throw new Exception("Error saving retail sale header: " . $conn->error);
        }
        $header_stmt->close();
        
        // Insert sale details and update stock
        $current_stock_column = "Current_Stock" . $comp_id;
        $opening_stock_column = "Opening_Stock" . $comp_id;
        
        foreach ($items_data as $item_code => $item) {
            // Insert sale detail
            $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                             VALUES (?, ?, ?, ?, ?, ?, ?)";
            $detail_stmt = $conn->prepare($detail_query);
            $detail_stmt->bind_param("ssddssi", $retail_bill_no, $item_code, $item['qty'], 
                                    $item['rate'], $item['amount'], $retail_mode, $comp_id);
            if (!$detail_stmt->execute()) {
                throw new Exception("Error saving retail sale detail for item $item_code: " . $conn->error);
            }
            $detail_stmt->close();
            
            // Update item stock
            updateItemStock($conn, $item_code, $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
            
            // Update daily stock
            updateDailyStock($conn, $item_code, $current_date, $item['qty'], $comp_id);
        }
        
        // ========== PART 3: Generate Cash Memo ==========
        $cash_memos_generated = 0;
        $cash_memo_errors = [];
        
        if (file_exists("cash_memo_functions.php")) {
            include_once "cash_memo_functions.php";
            if (function_exists('autoGenerateCashMemoForBill')) {
                try {
                    if (autoGenerateCashMemoForBill($conn, $retail_bill_no, $comp_id, $user_id)) {
                        $cash_memos_generated++;
                        logMessage("Cash memo generated for bill: $retail_bill_no", 'INFO');
                    } else {
                        $cash_memo_errors[] = $retail_bill_no;
                        logMessage("Failed to generate cash memo for bill: $retail_bill_no", 'WARNING');
                    }
                } catch (Exception $e) {
                    $cash_memo_errors[] = $retail_bill_no;
                    logMessage("Exception generating cash memo for $retail_bill_no: " . $e->getMessage(), 'ERROR');
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Clear session quantities
        clearSessionQuantities();
        
        // Calculate totals for display
        $taxRate = 0.08; // 8% tax
        $taxAmount = $total_amount * $taxRate;
        $finalAmount = $total_amount + $taxAmount;
        
        // Store bill data in session
        $customerName = ($selectedCustomer === '') ? 'Walk-in Customer' : ($customers[$selectedCustomer] ?? 'Unknown Customer');
        $customerIdForDisplay = ($selectedCustomer === '') ? 0 : $selectedCustomer;
        
        $_SESSION['last_customer_bill_data'] = [
            'bill_no' => ($selectedCustomer === '') ? $retail_bill_no : $customer_bill_no,
            'retail_bill_no' => $retail_bill_no,
            'customer_bill_no' => $customer_bill_no,
            'customer_id' => $customerIdForDisplay,
            'customer_name' => $customerName,
            'bill_date' => $current_date,
            'items' => $items_data,
            'total_amount' => $total_amount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'final_amount' => $finalAmount,
            'cash_memos_generated' => $cash_memos_generated,
            'cash_memo_errors' => $cash_memo_errors
        ];
        
        // Clear selected customer
        unset($_SESSION['selected_customer']);
        
        // Redirect to bill preview
        header("Location: customer_bill_preview.php");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error processing sale: " . $e->getMessage();
        logMessage("Transaction rolled back: " . $e->getMessage(), 'ERROR');
        header("Location: customer_sales.php");
        exit;
    }
}

// ============================================================================
// CREATE HELPER FILES
// ============================================================================

// You'll need to create these helper files:

// 1. clear_customer_session_quantities.php
// 2. update_customer_session_quantity.php
// 3. customer_bill_preview.php (similar to bill preview in date range)
// 4. check_backdated_functions.php (copy from sale_for_date_range.php)

// ============================================================================
// RENDER PAGE
// ============================================================================

// Clear customer-related session messages after displaying
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Debug info
$debug_info = [
    'total_items' => $total_items,
    'current_page' => $current_page,
    'total_pages' => $total_pages,
    'session_quantities_count' => count($_SESSION['customer_sale_quantities'] ?? []),
    'date' => $sale_date,
    'user_id' => $_SESSION['user_id'],
    'comp_id' => $comp_id,
    'license_type' => $license_type,
    'allowed_classes' => $allowed_classes,
    'sale_day' => $sale_day,
    'closing_column' => $closing_column,
    'sale_month' => $sale_month,
    'selected_customer' => $selectedCustomer
];
logArray($debug_info, "Customer Sales Page Load Debug Info");
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Sales - liqoursoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    /* All CSS from sale_for_date_range.php */
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

    .qty-input {
        height: 30px !important;
        padding: 2px 6px !important;
    }

    .btn-sm {
        padding: 2px 6px !important;
        font-size: 12px !important;
    }

    tr.has-quantity {
        background-color: #e8f5e8 !important;
        border-left: 3px solid #28a745 !important;
    }

    tr.has-quantity td {
        font-weight: 500;
    }

    .backdated-restriction {
        background-color: #f8d7da !important;
        border-left: 4px solid #dc3545 !important;
    }

    .backdated-restriction td {
        color: #721c24 !important;
        font-weight: 600;
    }

    .backdated-restriction .qty-input {
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

    .quantity-saving {
        background-color: #e8f5e8 !important;
        transition: background-color 0.3s ease;
    }

    .quantity-error {
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
    
    /* Customer field styling */
    .customer-combined-field {
        position: relative;
    }
    .customer-hint {
        font-size: 0.875rem;
        color: #6c757d;
        margin-top: 5px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Customer Sales</h3>

      <!-- SIMPLIFIED License Restriction Info -->
      <div class="alert alert-info mb-3 py-2">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0 compact-info">Showing items with available stock > 0</p>
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
      
      <?php if (isset($_SESSION['error'])): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $_SESSION['error'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <!-- Client-side Validation Alert -->
      <div class="alert alert-warning validation-alert" id="clientValidationAlert">
        <i class="fas fa-exclamation-triangle"></i>
        <span id="validationMessage"></span>
      </div>

      <!-- Combined Customer Field -->
      <div class="row mb-4">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0"><i class="fas fa-user"></i> Customer Information</h5>
            </div>
            <div class="card-body">
              <form method="POST" id="customerForm">
                <div class="customer-combined-field">
                  <label for="customer_field" class="form-label">Select or Create Customer</label>
                  <input type="text"
                         class="form-control"
                         id="customer_field"
                         name="customer_field"
                         list="customerOptions"
                         placeholder="Type to search customers or type 'new: Customer Name' to create new"
                         value="<?= !empty($selectedCustomer) && isset($customers[$selectedCustomer]) ? $customers[$selectedCustomer] : '' ?>">
                  <datalist id="customerOptions">
                    <option value="">Walk-in Customer</option>
                    <?php foreach ($customers as $code => $name): ?>
                      <option value="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                  </datalist>
                  <div class="customer-hint">
                    <i class="fas fa-info-circle"></i>
                    Select existing customer from dropdown or type "new: Customer Name" to create new customer.
                    Leave empty for walk-in customer.
                  </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">
                  <i class="fas fa-save"></i> Save Customer Selection
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>

      <!-- Sequence Type Selector -->
      <div class="mb-3">
        <label class="form-label">Sequence Type:</label>
        <div class="btn-group" role="group">
          <a href="?mode=<?= $mode ?>&sequence_type=user_defined&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'user_defined' ? 'sequence-active' : '' ?>">
            User Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=system_defined&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'system_defined' ? 'sequence-active' : '' ?>">
            System Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=group_defined&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'group_defined' ? 'sequence-active' : '' ?>">
            Group Defined
          </a>
        </div>
      </div>

      <!-- Date Selection -->
      <div class="date-range-container mb-4">
        <form method="GET" class="row g-3 align-items-end">
          <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
          <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
          <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>">
          <input type="hidden" name="page" value="1">
          
          <div class="col-md-4">
            <label for="sale_date" class="form-label">Sale Date</label>
            <input type="date" name="sale_date" class="form-control" 
                   value="<?= htmlspecialchars($sale_date); ?>" required>
          </div>
          
          <div class="col-md-6">
            <label class="form-label">Selected Date: 
              <span class="fw-bold"><?= date('d-M-Y', strtotime($sale_date)) ?></span>
              <span class="table-source-indicator <?= $sale_month === date('Y-m') ? 'table-current' : 'table-archive' ?>">
                <?= $sale_month === date('Y-m') ? 'Current Month' : 'Archived Month' ?>
              </span>
            </label>
          </div>
          
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Apply Date</button>
          </div>
        </form>
      </div>

      <!-- Search -->
      <div class="row mb-3">
        <div class="col-md-6">
          <form method="GET" class="search-control">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
            <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
            <input type="hidden" name="sale_date" value="<?= htmlspecialchars($sale_date); ?>">
            <input type="hidden" name="page" value="1">
            <div class="input-group">
              <input type="text" name="search" class="form-control"
                     placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
              <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
              <?php if ($search !== ''): ?>
                <a href="?mode=<?= $mode ?>&sequence_type=<?= $sequence_type ?>&sale_date=<?= $sale_date ?>&page=1" class="btn btn-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
        <div class="col-md-6 text-end">
          <div class="text-muted">
            Total Items with Stock: <?= $total_items ?> | Page: <?= $current_page ?> of <?= $total_pages ?>
            <?php if (count($_SESSION['customer_sale_quantities']) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['customer_sale_quantities']) ?> items with quantities</span>
            <?php endif; ?>
            <span class="compact-info"> | Stock filter: > 0</span>
          </div>
        </div>
      </div>

      <!-- Sales Form -->
      <form method="POST" id="salesForm">
        <input type="hidden" name="sale_date" value="<?= htmlspecialchars($sale_date); ?>">

        <!-- Action Buttons (like sale_for_date_range.php) -->
        <div class="d-flex gap-2 mb-3 flex-wrap">
          <!-- Generate Bills Button -->
          <button type="submit" name="generate_bills" id="generateBillsBtn" class="btn btn-success btn-action">
            <i class="fas fa-save"></i> Generate Bills
          </button>
          
          <!-- Clear Session Button -->
          <button type="button" id="clearSessionBtn" class="btn btn-danger">
            <i class="fas fa-trash"></i> Clear All Quantities
          </button>
          
          <!-- Total Sales Summary Button -->
          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#totalSalesModal">
              <i class="fas fa-chart-bar"></i> View Total Sales Summary
          </button>
          
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>

        <!-- Items Table -->
        <div class="table-container">
          <table class="styled-table table-striped" id="itemsTable">
            <thead class="table-header">
              <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Rate (₹)</th>
                <th>Available Stock</th>
                <th>Sale Qty</th>
                <th>Closing Balance</th>
                <th>Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
<?php if (!empty($items)): ?>
    <?php foreach ($items as $item): 
        $item_code = $item['CODE'];
        $item_qty = isset($_SESSION['customer_sale_quantities'][$item_code]) ? $_SESSION['customer_sale_quantities'][$item_code] : 0;
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
        
        // Get class code
        $class_code = $item['CLASS'] ?? 'O';
        
        // Determine stock status for styling
        $stock_status_class = '';
        if ($item['CURRENT_STOCK'] <= 0) {
            $stock_status_class = 'stock-out';
        } elseif ($item['CURRENT_STOCK'] < 10) {
            $stock_status_class = 'stock-low';
        } else {
            $stock_status_class = 'stock-available';
        }
        
        // Check for backdated sales (simplified version)
        $has_backdated_restriction = false;
        $earliest_sale = null;
        $latest_sale = null;
        $sale_count = 0;
        
        // Note: You should implement the full backdated check like in sale_for_date_range.php
        // For now, we'll skip it
        
        $backdated_class = $has_backdated_restriction ? 'backdated-restriction' : '';
        $backdated_title = $has_backdated_restriction ? 
            "Sales exist from $earliest_sale to $latest_sale (Total: $sale_count sales)" : '';
    ?>
        <tr data-class="<?= htmlspecialchars($class_code) ?>" 
            data-details="<?= htmlspecialchars($item['DETAILS']) ?>" 
            data-details2="<?= htmlspecialchars($item['DETAILS2']) ?>"
            class="<?= $item_qty > 0 ? 'has-quantity' : '' ?> <?= $backdated_class ?>">
            <td><?= htmlspecialchars($item_code); ?></td>
            <td><?= htmlspecialchars($item['DETAILS']); ?></td>
            <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
            <td class="stock-integer"><?= number_format($display_rate); ?></td>
            <td>
                <span class="stock-info">
                    <span class="stock-integer"><?= number_format($display_stock); ?></span>
                    <span class="stock-status <?= $stock_status_class ?>">
                        <?php if ($item['CURRENT_STOCK'] <= 0): ?>
                            Out
                        <?php elseif ($item['CURRENT_STOCK'] < 10): ?>
                            Low
                        <?php else: ?>
                            Available
                        <?php endif; ?>
                    </span>
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
                       oninput="validateQuantity(this)"
                       <?= $has_backdated_restriction ? 'disabled title="' . htmlspecialchars($backdated_title) . '"' : '' ?>>
            </td>
            <td class="closing-balance-cell" id="closing_<?= htmlspecialchars($item_code); ?>">
                <span class="stock-integer"><?= number_format($display_closing) ?></span>
                <?php if ($closing_balance <= 0): ?>
                    <br><span class="stock-status stock-out">Out</span>
                <?php elseif ($closing_balance < 10): ?>
                    <br><span class="stock-status stock-low">Low</span>
                <?php endif; ?>
            </td>
            <td class="amount-cell" id="amount_<?= htmlspecialchars($item_code); ?>">
                <span class="stock-integer"><?= number_format($display_amount) ?></span>
            </td>
        </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
        <td colspan="8" class="text-center text-muted">
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
            <?php if (count($_SESSION['customer_sale_quantities']) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['customer_sale_quantities']) ?> items with quantities across all pages</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Ajax Loader -->
        <div id="ajaxLoader" class="ajax-loader">
          <div class="loader"></div>
          <p>Processing sale...</p>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Total Sales Modal (from sale_for_date_range.php) -->
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
const allSessionQuantities = <?= json_encode($_SESSION['customer_sale_quantities'] ?? []) ?>;
const allItemsData = <?= json_encode($all_items_data) ?>;
const saleDate = '<?= $sale_date ?>';

// Function to show client-side validation alert
function showClientValidationAlert(message) {
    $('#validationMessage').text(message);
    $('#clientValidationAlert').fadeIn();
    
    setTimeout(() => {
        $('#clientValidationAlert').fadeOut();
    }, 10000);
}

// Function to clear session quantities via AJAX
function clearSessionQuantities() {
    $.ajax({
        url: 'clear_customer_session_quantities.php',
        type: 'POST',
        success: function(response) {
            console.log('Customer session quantities cleared');
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
    
    // If input is disabled due to backdated restriction, don't validate
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

// Function to update all UI elements for an item
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

// Function to save quantity to session via AJAX
function saveQuantityToSession(itemCode, qty) {
    if (typeof saveQuantityToSession.debounce === 'undefined') {
        saveQuantityToSession.debounce = null;
    }
    
    clearTimeout(saveQuantityToSession.debounce);
    saveQuantityToSession.debounce = setTimeout(() => {
        $.ajax({
            url: 'update_customer_session_quantity.php',
            type: 'POST',
            data: {
                item_code: itemCode,
                quantity: qty
            },
            success: function(response) {
                console.log('Customer quantity saved to session:', itemCode, qty);
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
    
    // Validate ONLY session quantities > 0
    for (const itemCode in allSessionQuantities) {
        const qty = allSessionQuantities[itemCode];
        if (qty > 0) {
            const inputField = $(`input[name="sale_qty[${itemCode}]"]`);
            let currentStock;
            
            if (inputField.length > 0) {
                currentStock = parseFloat(inputField.data('stock'));
            } else {
                if (allItemsData[itemCode]) {
                    currentStock = parseFloat(allItemsData[itemCode].CURRENT_STOCK);
                } else {
                    continue;
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

// Function to check stock availability via AJAX before submission
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
            sale_date: saleDate,
            mode: '<?= $mode ?>',
            comp_id: '<?= $comp_id ?>',
            quantities: allSessionQuantities,
            daily_stock_table: '<?= $daily_stock_table ?>',
            sale_month: '<?= $sale_month ?>'
        };

        $.ajax({
            url: 'check_customer_stock_availability.php',
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

// Function to check customer selection
function checkCustomerSelection() {
    const customerField = document.getElementById('customer_field');
    const customerValue = customerField.value.trim();
    
    // Check if customer is selected (can be empty for walk-in)
    if (customerValue === '' || customerValue === '0') {
        // Empty or 0 means walk-in customer - this is allowed
        return true;
    }
    
    // Check if it's a valid customer code (numeric) or new customer
    if (!isNaN(customerValue) || customerValue.toLowerCase().startsWith('new:')) {
        return true;
    }
    
    alert('Please select a valid customer or create a new one.\nLeave empty for walk-in customer.');
    customerField.focus();
    return false;
}

// Function to get item data from ALL items data
function getItemData(itemCode) {
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
    if (details2) {
        const literMatch = details2.match(/(\d+\.?\d*)\s*L\b/i);
        if (literMatch) {
            let volume = parseFloat(literMatch[1]);
            return Math.round(volume * 1000);
        }
        
        const mlMatch = details2.match(/(\d+)\s*ML\b/i);
        if (mlMatch) {
            return parseInt(mlMatch[1]);
        }
    }
    
    if (details) {
        if (details.includes('QUART')) return 750;
        if (details.includes('PINT')) return 375;
        if (details.includes('NIP')) return 90;
        
        const literMatch = details.match(/(\d+\.?\d*)\s*L\b/i);
        if (literMatch) {
            let volume = parseFloat(literMatch[1]);
            return Math.round(volume * 1000);
        }
        
        const mlMatch = details.match(/(\d+)\s*ML\b/i);
        if (mlMatch) {
            return parseInt(mlMatch[1]);
        }
    }
    
    return 0;
}

// Function to map volume to column
function getVolumeColumn(volume) {
    const volumeMap = {
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
        1500: '1.5L',
        1750: '1.75L',
        2000: '2L',
        3000: '3L',
        4500: '4.5L',
        15000: '15L',
        20000: '20L',
        30000: '30L',
        50000: '50L'
    };
    
    return volumeMap[volume] || null;
}

// Function to update total sales module
function updateTotalSalesModule() {
    console.log('updateTotalSalesModule called - Processing ALL items');
    
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
        'COUNTRY LIQUOR': {},
        'OTHER': {}
    };
    
    Object.keys(salesSummary).forEach(category => {
        allSizes.forEach(size => {
            salesSummary[category][size] = 0;
        });
    });

    console.log('Processing ALL session quantities:', allSessionQuantities);

    let processedItems = 0;
    for (const itemCode in allSessionQuantities) {
        const quantity = allSessionQuantities[itemCode];
        if (quantity > 0) {
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

    console.log(`Processed ${processedItems} items with quantities`);
    console.log('Final sales summary:', salesSummary);

    updateSalesModalTable(salesSummary, allSizes);
}

// Function to update modal table with calculated values
function updateSalesModalTable(salesSummary, allSizes) {
    const tbody = $('#totalSalesTable tbody');
    tbody.empty();
    
    console.log('Updating modal table with categories:', Object.keys(salesSummary));
    
    const categories = ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER', 'COUNTRY LIQUOR', 'OTHER'];
    
    categories.forEach(category => {
        const row = $('<tr>');
        row.append($('<td>').text(category));
        
        allSizes.forEach(size => {
            const value = salesSummary[category] ? (salesSummary[category][size] || 0) : 0;
            const cell = $('<td>').text(value > 0 ? value : '');
            
            if (value > 0) {
                cell.addClass('table-success');
            }
            
            row.append(cell);
        });
        
        tbody.append(row);
    });
    
    console.log('Modal table updated successfully');
}

// Function to setup row navigation with arrow keys
function setupRowNavigation() {
    const qtyInputs = $('input.qty-input');
    let currentRowIndex = -1;
    
    $(document).on('focus', 'input.qty-input', function() {
        $('tr').removeClass('highlight-row');
        $(this).closest('tr').addClass('highlight-row');
        currentRowIndex = qtyInputs.index(this);
    });
    
    $(document).on('keydown', 'input.qty-input', function(e) {
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        
        e.preventDefault();
        
        let newIndex;
        if (e.key === 'ArrowUp') {
            newIndex = currentRowIndex - 1;
        } else {
            newIndex = currentRowIndex + 1;
        }
        
        if (newIndex >= 0 && newIndex < qtyInputs.length) {
            $(qtyInputs[newIndex]).focus().select();
        }
    });
}

// Initialize input values from session on page load
function initializeQuantitiesFromSession() {
    $('input[name^="sale_qty"]').each(function() {
        const itemCode = $(this).data('code');
        if (allSessionQuantities[itemCode] !== undefined) {
            const sessionQty = allSessionQuantities[itemCode];
            $(this).val(sessionQty);
            
            const currentStock = parseFloat($(this).data('stock'));
            updateItemUI(itemCode, sessionQty, currentStock);
        }
    });
}

// Generate bills with validation
function generateBills() {
    // First validate customer selection
    if (!checkCustomerSelection()) {
        return false;
    }
    
    // Then validate basic quantities
    if (!validateAllQuantities()) {
        return false;
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
        alert('Please enter quantities for at least one item.');
        return false;
    }
    
    // Show confirmation
    const customerField = document.getElementById('customer_field');
    const customerName = customerField.value.trim() === '' ? 'Walk-in Customer' : customerField.value;
    
    const confirmed = confirm(`Generate bills for ${customerName}?\n\nThis will:
    1. Create customer sale record
    2. Create retail sale record
    3. Update stock
    4. Generate cash memo
    
    Click OK to proceed.`);
    
    if (confirmed) {
        // Show loader
        $('#ajaxLoader').show();
        $('#generateBillsBtn').prop('disabled', true).addClass('btn-loading');
        return true;
    }
    
    return false;
}

// Initialize backdated tooltips
function initializeBackdatedTooltips() {
    $('[data-bs-toggle="tooltip"]').tooltip({
        placement: 'top',
        trigger: 'hover',
        container: 'body',
        template: '<div class="tooltip backdated-tooltip" role="tooltip"><div class="tooltip-arrow"></div><div class="tooltip-inner"></div></div>'
    });
}

// Document ready
$(document).ready(function() {
    console.log('Customer Sales - Document ready');
    
    // Set up row navigation
    setupRowNavigation();
    
    // Initialize quantities from session
    initializeQuantitiesFromSession();
    
    // Initialize enhanced tooltips
    initializeBackdatedTooltips();
    
    // Clear session button click event
    $('#clearSessionBtn').click(function() {
        if (confirm('Are you sure you want to clear all quantities? This action cannot be undone.')) {
            clearSessionQuantities();
        }
    });
    
    // Generate bills button click event
    $('#generateBillsBtn').click(function(e) {
        if (!generateBills()) {
            e.preventDefault();
        }
    });
    
    // Quantity input change event with debouncing
    let quantityTimeout;
    $(document).on('input', 'input.qty-input', function(e) {
        clearTimeout(quantityTimeout);
        quantityTimeout = setTimeout(() => {
            validateQuantity(this);
        }, 200);
    });
    
    // Quantity input change event
    $(document).on('change', 'input[name^="sale_qty"]', function() {
        if (!validateQuantity(this)) {
            return;
        }
        
        const itemCode = $(this).data('code');
        const totalQty = parseInt($(this).val()) || 0;
        
        if (totalQty <= 0) {
            const currentStock = parseFloat($(this).data('stock'));
            const displayClosing = Math.floor(currentStock);
            $(`#closing_${itemCode}`).html(`<span class="stock-integer">${displayClosing}</span>`);
            $(`#amount_${itemCode}`).html('<span class="stock-integer">0</span>');
        }
        
        // Update total sales module if modal is open
        if ($('#totalSalesModal').hasClass('show')) {
            console.log('Modal is open, updating total sales module...');
            updateTotalSalesModule();
        }
    });
    
    // Update total sales module when modal is shown
    $('#totalSalesModal').on('show.bs.modal', function() {
        console.log('Total Sales Modal opened - updating data...');
        updateTotalSalesModule();
    });
    
    // Also update when modal is already shown but data changes
    $('#totalSalesModal').on('shown.bs.modal', function() {
        console.log('Total Sales Modal shown - refreshing data...');
        updateTotalSalesModule();
    });
});
</script>
</body>
</html>