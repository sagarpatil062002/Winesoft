<?php
// generate_bills_ultra_fast.php - HYPER-OPTIMIZED FOR 1000+ BILLS IN < 3 SECONDS
// Includes all features: bills, cash memos, stock updates with real-time progress
// NOW WITH PROPER VOLUME LIMIT ENFORCEMENT USING EXISTING FUNCTIONS
// NOW WITH DRY DAY FILTERING
session_start();

// Error reporting - only log errors, don't display
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('memory_limit', '2048M'); // 2GB memory for large operations
ini_set('max_execution_time', 300); // 5 minutes max

// Include required files
require_once "../config/db.php";
require_once "volume_limit_utils.php";
require_once "cash_memo_functions.php";
require_once "drydays_functions.php"; // ADDED: For dry day checking

// Minimal logging function - for critical errors and info
function logMessage($message, $level = 'INFO') {
    // Always log errors, but also log DEBUG when needed
    if ($level === 'ERROR' || $level === 'INFO' || $level === 'DEBUG') {
        $logFile = '../logs/sales_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
        
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// DEBUG: Enhanced logging function to trace distribution flow
function logDistribution($step, $item_code, $data) {
    $logFile = '../logs/distribution_debug_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s.') . sprintf('%03d', (microtime(true) - floor(microtime(true))) * 1000);
    $logMessage = "[$timestamp] [DIST-$step] Item: $item_code | " . json_encode($data) . PHP_EOL;
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ============================================================================
// STEP 1: MAXIMUM PERFORMANCE DATABASE SETTINGS
// ============================================================================
try {
    $conn->query("SET SESSION unique_checks = 0");
    $conn->query("SET SESSION foreign_key_checks = 0");
    $conn->query("SET SESSION sql_log_bin = 0");
    $conn->query("SET autocommit = 0");
    $conn->query("SET SESSION bulk_insert_buffer_size = 1024 * 1024 * 1024"); // 1GB buffer
    $conn->query("SET SESSION wait_timeout = 28800");
    $conn->query("SET SESSION innodb_flush_log_at_trx_commit = 2");
    $conn->query("SET SESSION sync_binlog = 0");
    $conn->query("SET SESSION innodb_autoinc_lock_mode = 2");
} catch (Exception $e) {
    // Continue even if some settings fail
}

// ============================================================================
// STEP 2: INITIALIZE PROGRESS TRACKING
// ============================================================================
$progress_key = 'bill_progress_' . session_id() . '_' . uniqid();
$_SESSION[$progress_key] = [
    'total_bills' => 0,
    'current_bill' => 0,
    'status' => 'initializing',
    'message' => 'Initializing hyper-fast bill generation...',
    'percentage' => 0,
    'bills_generated' => [],
    'start_time' => microtime(true),
    'last_update' => time(),
    'speed' => 0,
    'items_processed' => 0,
    'total_items' => 0
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_bills'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$response = ['success' => false, 'message' => '', 'total_amount' => 0, 'bill_count' => 0];
$start_time = microtime(true);

try {
    // ============================================================================
    // STEP 3: GET INPUT PARAMETERS
    // ============================================================================
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $mode = $_POST['mode'] ?? 'F';
    $comp_id = (int)$_SESSION['CompID'];
    $user_id = (int)$_SESSION['user_id'];
    $fin_year_id = $_SESSION['FIN_YEAR_ID'] ?? '';
    $items = $_POST['items'] ?? [];
    $distributions = $_POST['distribution'] ?? []; // NEW: Get saved distributions
    
    // DEBUG: Log what was received
    logMessage("=== ULTRA FAST BILL GEN START ===", 'INFO');
    logMessage("Received " . count($items) . " items and " . count($distributions) . " saved distributions", 'INFO');
    logMessage("Items received: " . implode(', ', array_keys($items)), 'DEBUG');
    
    // DEBUG: Log raw distribution data
    if (!empty($distributions)) {
        logMessage("Distribution keys received: " . implode(', ', array_keys($distributions)), 'DEBUG');
        foreach ($distributions as $code => $dist) {
            $distType = gettype($dist);
            $distPreview = is_array($dist) ? json_encode(array_slice($dist, 0, 5)) : (is_string($dist) ? substr($dist, 0, 100) : $dist);
            logMessage("Distribution for $code (type: $distType): $distPreview", 'DEBUG');
        }
    } else {
        logMessage("WARNING: No distributions received in POST data!", 'INFO');
    }
    
    if (empty($start_date) || empty($end_date) || empty($items)) {
        throw new Exception("Missing required parameters");
    }
    
    $_SESSION[$progress_key]['total_items'] = count($items);
    $_SESSION[$progress_key]['status'] = 'processing';
    $_SESSION[$progress_key]['message'] = 'Processing ' . count($items) . ' items...';
    
    // ============================================================================
    // STEP 4: CREATE DATE ARRAY (ULTRA-FAST)
    // ============================================================================
    $date_array = [];
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_period = new DatePeriod($begin, $interval, $end);
    
    foreach ($date_period as $date) {
        $date_array[] = $date->format("Y-m-d");
    }
    $days_count = count($date_array);
    
    if ($days_count == 0) {
        throw new Exception("Invalid date range");
    }
    
    // ============================================================================
    // STEP 5: GET AVAILABLE DATES (EXCLUDING DRY DAYS) - NEW!
    // ============================================================================
    $dryDaysManager = new DryDaysManager($conn);
    $dry_days = $dryDaysManager->getDryDaysInRange($start_date, $end_date);
    $dry_dates = array_keys($dry_days);
    
    // Get available dates (exclude dry days)
    $available_dates = [];
    foreach ($date_array as $date) {
        if (!in_array($date, $dry_dates)) {
            $available_dates[] = $date;
        }
    }
    
    // If no available dates, throw error
    if (empty($available_dates)) {
        throw new Exception("No available dates in selected range - all dates are dry days!");
    }
    
    logMessage("Date range: $start_date to $end_date, Total days: $days_count, Dry days: " . count($dry_dates) . ", Available days: " . count($available_dates), 'INFO');
    
    // ============================================================================
    // STEP 5: SINGLE QUERY TO LOAD ALL MASTER DATA
    // ============================================================================
    $item_codes = array_keys($items);
    $item_cache = [];
    $items_data = [];
    
    if (!empty($item_codes)) {
        $placeholders = implode(',', array_fill(0, count($item_codes), '?'));
        $types = str_repeat('s', count($item_codes));
        
        // Combined query for all item data
        $item_query = "SELECT im.CODE, 
                              COALESCE(NULLIF(im.Print_Name, ''), im.DETAILS) as display_name,
                              im.DETAILS, im.DETAILS2, im.RPRICE, im.LIQ_FLAG,
                              im.CLASS as class_code
                       FROM tblitemmaster im
                       WHERE im.CODE IN ($placeholders)";
        
        $item_stmt = $conn->prepare($item_query);
        if ($item_stmt) {
            $item_stmt->bind_param($types, ...$item_codes);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            while ($row = $item_result->fetch_assoc()) {
                $item_cache[$row['CODE']] = $row;
                $items_data[$row['CODE']] = [
                    'rate' => (float)$row['RPRICE'],
                    'name' => $row['display_name'] ?? $row['DETAILS'],
                    'details2' => $row['DETAILS2'] ?? '',
                    'liq_flag' => $row['LIQ_FLAG'] ?? $mode
                ];
            }
            $item_stmt->close();
        }
    }
    
    // ============================================================================
    // STEP 6: GET CATEGORY LIMITS FROM tblcompany
    // ============================================================================
    $category_limits = getCategoryLimits($conn, $comp_id);
    
    // Map to match the categories used in volume_limit_utils.php
    $limits = [
        'IMFL' => (float)($category_limits['IMFL'] ?? 1000),
        'BEER' => (float)($category_limits['BEER'] ?? 0),
        'CL' => (float)($category_limits['CL'] ?? 0),
        'OTHER' => PHP_FLOAT_MAX
    ];
    
    // ============================================================================
    // STEP 7: OPTIMIZED DAILY DISTRIBUTION
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Calculating distribution...';
    
    $daily_sales_data = [];
    $total_bills_estimate = 0;
    $items_processed = 0;
    
    foreach ($items as $item_code => $total_qty) {
        $total_qty = (int)$total_qty;
        if ($total_qty <= 0 || !isset($item_cache[$item_code])) {
            continue;
        }
        
        $items_processed++;
        $_SESSION[$progress_key]['items_processed'] = $items_processed;
        
        // CRITICAL FIX: Use saved distribution if available, otherwise generate new one
        // Note: FormData sends JSON string, not array, so we need to handle both cases
        $has_saved_distribution = false;
        $full_distribution = [];
        
        // DEBUG: Log the distribution check
        logMessage("DEBUG: Checking distribution for item $item_code - Total Qty: $total_qty", 'DEBUG');
        logMessage("DEBUG: Available distributions: " . (isset($distributions[$item_code]) ? 'YES' : 'NO'), 'DEBUG');
        
        if (isset($distributions[$item_code])) {
            $dist_value = $distributions[$item_code];
            $dist_type = gettype($dist_value);
            logMessage("DEBUG: Distribution type for $item_code: $dist_type", 'DEBUG');
            
            // Check if it's already an array (some edge cases)
            if (is_array($dist_value)) {
                $full_distribution = $dist_value;
                $has_saved_distribution = true;
                logMessage("SAVED DIST USED (array): $item_code = " . implode(', ', $full_distribution), 'INFO');
                logDistribution('SAVED_ARRAY', $item_code, $full_distribution);
            }
            // Or if it's a JSON string that needs decoding
            elseif (is_string($dist_value)) {
                logMessage("DEBUG: Raw distribution string for $item_code: " . substr($dist_value, 0, 200), 'DEBUG');
                $decoded = json_decode($dist_value, true);
                if (is_array($decoded)) {
                    $full_distribution = $decoded;
                    $has_saved_distribution = true;
                    logMessage("SAVED DIST USED (json decoded): $item_code = " . implode(', ', $full_distribution), 'INFO');
                    logDistribution('SAVED_JSON', $item_code, $full_distribution);
                } else {
                    logMessage("ERROR: Failed to decode JSON for $item_code: " . json_last_error_msg(), 'ERROR');
                }
            }
        }
        
        if (!$has_saved_distribution) {
            // Generate random distribution if not saved (fallback)
            logMessage("NEW DIST GENERATED: $item_code (no saved distribution found)", 'INFO');
            logDistribution('GENERATED_NEW', $item_code, ['total_qty' => $total_qty, 'available_dates' => count($available_dates)]);
            $distribution = distributeSalesWithGlobalRestrictions($total_qty, $available_dates);
            
            // Map back to full date array (with 0 for unavailable/dry days)
            $full_distribution = array_fill(0, $days_count, 0);
            foreach ($available_dates as $i => $date) {
                $date_index = array_search($date, $date_array);
                if ($date_index !== false) {
                    $full_distribution[$date_index] = $distribution[$i] ?? 0;
                }
            }
        }
        
        $daily_sales_data[$item_code] = $full_distribution;
        
        // Validate: Check that sum of distribution equals total quantity
        $distribution_sum = array_sum($full_distribution);
        if ($distribution_sum !== $total_qty) {
            logMessage("WARNING: Distribution mismatch for item $item_code - Expected $total_qty, got $distribution_sum", 'INFO');
        }
        
        // CRITICAL: Log what distribution will be used for bill generation
        logMessage("=== FINAL DISTRIBUTION FOR BILLS === Item: $item_code | Qty: $total_qty | Distribution: " . implode(',', $full_distribution) . " | Sum: $distribution_sum", 'INFO');
        logDistribution('BILL_GEN', $item_code, ['qty' => $total_qty, 'dist' => $full_distribution, 'sum' => $distribution_sum]);
        
        // Count days with sales for this item
        $sale_days = 0;
        foreach ($full_distribution as $qty) {
            if ($qty > 0) $sale_days++;
        }
        $total_bills_estimate += $sale_days;
    }
    
    $_SESSION[$progress_key]['total_bills'] = max($total_bills_estimate, 1);
    
    // ============================================================================
    // STEP 8: BATCH BILL GENERATION WITH VOLUME LIMITS USING EXISTING FUNCTIONS
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Generating bills with volume limits...';
    
    // Prepare items_data for generateBillsWithLimits function
    $formatted_items_data = [];
    foreach ($items_data as $code => $data) {
        $formatted_items_data[$code] = [
            'rate' => $data['rate'],
            'name' => $data['name']
        ];
    }
    
    // Call the existing generateBillsWithLimits function from volume_limit_utils.php
    // Pass available_dates to filter out dry days at backend
    $bills = generateBillsWithLimits(
        $conn,
        $formatted_items_data,
        $date_array,
        $daily_sales_data,
        $mode,
        $comp_id,
        $user_id,
        $fin_year_id,
        $available_dates  // NEW: Pass available dates to filter out dry days
    );
    
    // Get starting bill number
    $bill_no_start = getNextBillNumberBatch($conn, $comp_id);
    $bill_counter = 0;
    
    // Assign proper bill numbers to bills
    foreach ($bills as &$bill) {
        $bill['bill_no'] = 'BL' . str_pad($bill_no_start + $bill_counter, 4, '0', STR_PAD_LEFT);
        $bill_counter++;
        
        // Update progress every 20 bills
        if ($bill_counter % 20 == 0) {
            $_SESSION[$progress_key]['current_bill'] = $bill_counter;
            $_SESSION[$progress_key]['percentage'] = min(30, round(($bill_counter / $_SESSION[$progress_key]['total_bills']) * 30));
            $_SESSION[$progress_key]['message'] = "Generated $bill_counter bills with volume limits...";
            
            $elapsed = microtime(true) - $start_time;
            $_SESSION[$progress_key]['speed'] = $bill_counter / max($elapsed, 0.001);
            $_SESSION[$progress_key]['last_update'] = time();
        }
        
        // Add to progress tracking
        $_SESSION[$progress_key]['bills_generated'][] = [
            'bill_no' => $bill['bill_no'],
            'date' => $bill['bill_date'],
            'amount' => $bill['total_amount'],
            'items' => count($bill['items'])
        ];
    }
    unset($bill);
    
    $_SESSION[$progress_key]['total_bills'] = count($bills);
    $_SESSION[$progress_key]['current_bill'] = count($bills);
    $_SESSION[$progress_key]['percentage'] = 30;
    
    if (empty($bills)) {
        throw new Exception("No bills generated");
    }
    
    // ============================================================================
    // STEP 9: BULK INSERT HEADERS (CHUNKED)
    // ============================================================================
    $_SESSION[$progress_key]['status'] = 'saving_headers';
    $_SESSION[$progress_key]['message'] = 'Saving bill headers...';
    
    $header_values = [];
    foreach ($bills as $bill) {
        $header_values[] = "('{$bill['bill_no']}', '{$bill['bill_date']}', {$bill['total_amount']}, 0, {$bill['total_amount']}, '{$bill['mode']}', {$bill['comp_id']}, {$bill['user_id']})";
    }
    
    // Insert in chunks of 5000
    $header_chunks = array_chunk($header_values, 5000);
    $chunk_count = count($header_chunks);
    $current_chunk = 0;
    
    foreach ($header_chunks as $chunk) {
        $current_chunk++;
        $_SESSION[$progress_key]['message'] = "Saving headers (chunk $current_chunk/$chunk_count)...";
        
        $batch_header = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) VALUES " . implode(',', $chunk);
        if (!$conn->query($batch_header)) {
            throw new Exception("Header insert failed: " . $conn->error);
        }
    }
    
    $_SESSION[$progress_key]['percentage'] = 45;
    
    // ============================================================================
    // STEP 10: BULK INSERT DETAILS (CHUNKED)
    // ============================================================================
    $_SESSION[$progress_key]['status'] = 'saving_details';
    $_SESSION[$progress_key]['message'] = 'Saving bill details...';
    
    $detail_values = [];
    foreach ($bills as $bill) {
        foreach ($bill['items'] as $item) {
            $detail_values[] = "('{$bill['bill_no']}', '{$item['code']}', {$item['qty']}, {$item['rate']}, {$item['amount']}, '{$bill['mode']}', {$bill['comp_id']})";
        }
    }
    
    // Insert in chunks of 10000
    $detail_chunks = array_chunk($detail_values, 10000);
    $chunk_count = count($detail_chunks);
    $current_chunk = 0;
    
    foreach ($detail_chunks as $chunk) {
        $current_chunk++;
        $_SESSION[$progress_key]['message'] = "Saving details (chunk $current_chunk/$chunk_count)...";
        
        $batch_detail = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) VALUES " . implode(',', $chunk);
        if (!$conn->query($batch_detail)) {
            throw new Exception("Details insert failed: " . $conn->error);
        }
    }
    
    $_SESSION[$progress_key]['percentage'] = 60;
    
    // ============================================================================
    // STEP 11: BULK STOCK UPDATE (OPTIMIZED)
    // ============================================================================
    $_SESSION[$progress_key]['status'] = 'updating_stock';
    $_SESSION[$progress_key]['message'] = 'Updating stock levels...';
    
    $current_stock_column = "Current_Stock" . $comp_id;
    
    // Aggregate quantities
    $stock_updates = [];
    foreach ($bills as $bill) {
        foreach ($bill['items'] as $item) {
            $code = $item['code'];
            $stock_updates[$code] = ($stock_updates[$code] ?? 0) + $item['qty'];
        }
    }
    
    // Use INSERT ... ON DUPLICATE KEY UPDATE for speed
    if (!empty($stock_updates)) {
        $stock_values = [];
        foreach ($stock_updates as $code => $qty) {
            $code_esc = $conn->real_escape_string($code);
            $stock_values[] = "('$code_esc', '$fin_year_id', $qty)";
        }
        
        $stock_chunks = array_chunk($stock_values, 5000);
        $chunk_count = count($stock_chunks);
        $current_chunk = 0;
        
        foreach ($stock_chunks as $chunk) {
            $current_chunk++;
            $_SESSION[$progress_key]['message'] = "Updating stock (chunk $current_chunk/$chunk_count)...";
            
            $stock_sql = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $current_stock_column) 
                          VALUES " . implode(',', $chunk) . "
                          ON DUPLICATE KEY UPDATE $current_stock_column = $current_stock_column - VALUES($current_stock_column)";
            
            if (!$conn->query($stock_sql)) {
                throw new Exception("Stock update failed: " . $conn->error);
            }
        }
    }
    
    $_SESSION[$progress_key]['percentage'] = 70;
    
    // ============================================================================
    // STEP 12: DAILY STOCK UPDATE (OPTIMIZED BULK VERSION)
    // ============================================================================
    $_SESSION[$progress_key]['status'] = 'daily_stock';
    $_SESSION[$progress_key]['message'] = 'Updating daily stock records...';
    
    // Aggregate daily stock updates by table and date
    $daily_stock_updates = [];
    
    foreach ($bills as $bill) {
        $sale_date = $bill['bill_date'];
        $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
        $month_year = date('Y-m', strtotime($sale_date));
        
        // Determine table name
        $current_date = new DateTime();
        $current_month = $current_date->format('Y-m');
        
        if ($month_year === $current_month) {
            $table_name = "tbldailystock_" . $comp_id;
        } else {
            $month_short = date('m', strtotime($sale_date));
            $year_short = date('y', strtotime($sale_date));
            $table_name = "tbldailystock_" . $comp_id . "_" . $month_short . "_" . $year_short;
        }
        
        foreach ($bill['items'] as $item) {
            $key = $table_name . '|' . $item['code'] . '|' . $month_year;
            
            if (!isset($daily_stock_updates[$key])) {
                $daily_stock_updates[$key] = [
                    'table' => $table_name,
                    'item_code' => $item['code'],
                    'month' => $month_year,
                    'sales' => [],
                    'total_qty' => 0
                ];
            }
            
            $daily_stock_updates[$key]['sales'][$day_num] = ($daily_stock_updates[$key]['sales'][$day_num] ?? 0) + $item['qty'];
            $daily_stock_updates[$key]['total_qty'] += $item['qty'];
        }
    }
    
    // Process daily stock updates in bulk per table
    $tables_processed = [];
    $update_count = 0;
    $total_updates = count($daily_stock_updates);
    
    foreach ($daily_stock_updates as $update) {
        $update_count++;
        if ($update_count % 50 == 0) {
            $_SESSION[$progress_key]['message'] = "Daily stock: $update_count/$total_updates items...";
        }
        
        $table = $update['table'];
        $item_code = $update['item_code'];
        $month = $update['month'];
        $sales = $update['sales'];
        
        // Check if table exists, create if not
        if (!isset($tables_processed[$table])) {
            $check_table = $conn->query("SHOW TABLES LIKE '$table'");
            if ($check_table->num_rows == 0) {
                createDailyStockTableFast($conn, $table);
            }
            $tables_processed[$table] = true;
        }
        
        // Escape strings for safety
        $item_code_esc = $conn->real_escape_string($item_code);
        $month_esc = $conn->real_escape_string($month);
        
        // Check if record exists
        $check = $conn->query("SELECT 1 FROM $table WHERE ITEM_CODE = '$item_code_esc' AND STK_MONTH = '$month_esc' LIMIT 1");
        
        if (!$check || $check->num_rows == 0) {
            // Get previous month's closing
            $prev_month = date('Y-m', strtotime($month . '-01 -1 month'));
            $prev_table = getDailyStockTableName($comp_id, $prev_month);
            $prev_closing = 0;
            
            if ($prev_table) {
                $prev_last_day = date('d', strtotime('last day of ' . $prev_month));
                $prev_col = "DAY_" . sprintf('%02d', $prev_last_day) . "_CLOSING";
                
                $prev_result = $conn->query("SELECT $prev_col FROM $prev_table WHERE ITEM_CODE = '$item_code_esc' AND STK_MONTH = '$prev_month'");
                if ($prev_result && $row = $prev_result->fetch_assoc()) {
                    $prev_closing = (float)($row[$prev_col] ?? 0);
                }
            }
            
            // Insert new record
            $insert_cols = ["ITEM_CODE", "STK_MONTH", "DAY_01_OPEN", "DAY_01_CLOSING"];
            $insert_vals = ["'$item_code_esc'", "'$month_esc'", $prev_closing, $prev_closing];
            
            $conn->query("INSERT INTO $table (" . implode(',', $insert_cols) . ") VALUES (" . implode(',', $insert_vals) . ")");
        }
        
        // Update sales for each day
        foreach ($sales as $day => $qty) {
            $day_padded = sprintf('%02d', $day);
            $col = "DAY_{$day_padded}_SALES";
            $closing_col = "DAY_{$day_padded}_CLOSING";
            
            // Get current values
            $current = $conn->query("SELECT DAY_{$day_padded}_OPEN, DAY_{$day_padded}_PURCHASE, $col FROM $table WHERE ITEM_CODE = '$item_code_esc' AND STK_MONTH = '$month_esc'");
            if ($current && $row = $current->fetch_assoc()) {
                $opening = (float)($row["DAY_{$day_padded}_OPEN"] ?? 0);
                $purchase = (float)($row["DAY_{$day_padded}_PURCHASE"] ?? 0);
                $current_sales = (float)($row[$col] ?? 0);
                
                $new_sales = $current_sales + $qty;
                $closing = $opening + $purchase - $new_sales;
                
                $conn->query("UPDATE $table SET $col = $new_sales, $closing_col = $closing WHERE ITEM_CODE = '$item_code_esc' AND STK_MONTH = '$month_esc'");
            }
        }
        
        // Recalculate from earliest day
        $min_day = min(array_keys($sales));
        recalculateDailyStockFast($conn, $table, $item_code_esc, $month_esc, $min_day);
    }
    
    $_SESSION[$progress_key]['percentage'] = 85;
    
    // ============================================================================
    // STEP 13: BULK CASH MEMO GENERATION USING EXISTING FUNCTIONS
    // ============================================================================
    $_SESSION[$progress_key]['status'] = 'cash_memos';
    $_SESSION[$progress_key]['message'] = 'Generating cash memos...';
    
    // Get company data once
    $companyQuery = "SELECT COMP_NAME, COMP_ADDR, COMP_FLNO, CF_LINE, CS_LINE FROM tblcompany WHERE CompID = ?";
    $companyStmt = $conn->prepare($companyQuery);
    if ($companyStmt) {
        $companyStmt->bind_param("i", $comp_id);
        $companyStmt->execute();
        $companyResult = $companyStmt->get_result();
        $companyRow = $companyResult->fetch_assoc();
        $companyStmt->close();
    } else {
        $companyRow = [];
    }
    
    $companyData = [
        'name' => $companyRow['COMP_NAME'] ?? 'WINE SHOP',
        'address' => $companyRow['COMP_ADDR'] ?? '',
        'licenseNumber' => $companyRow['COMP_FLNO'] ?? ''
    ];
    
    // Get permits (cached)
    $permitQuery = "SELECT P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, DETAILS FROM tblpermit 
                    WHERE P_NO IS NOT NULL AND P_NO != '' 
                    ORDER BY RAND() LIMIT 1000";
    $permitResult = $conn->query($permitQuery);
    $allPermits = [];
    if ($permitResult) {
        while ($row = $permitResult->fetch_assoc()) {
            $allPermits[] = $row;
        }
    }
    
    // Bulk insert cash memos
    $cash_memo_count = 0;
    if (!empty($bills) && !empty($allPermits)) {
        $cashMemoValues = [];
        $printDate = date('Y-m-d H:i:s');
        
        foreach ($bills as $bill) {
            $permitData = $allPermits[array_rand($allPermits)];
            
            $billNoEsc = $conn->real_escape_string($bill['bill_no']);
            $printDateEsc = $conn->real_escape_string($printDate);
            $licenseNumberEsc = $conn->real_escape_string($companyData['licenseNumber']);
            $shopNameEsc = $conn->real_escape_string($companyData['name']);
            $shopAddressEsc = $conn->real_escape_string($companyData['address']);
            $billDateEsc = $conn->real_escape_string($bill['bill_date']);
            $customerNameEsc = $conn->real_escape_string($permitData['DETAILS'] ?? 'RETAIL');
            $permitNoEsc = $conn->real_escape_string($permitData['P_NO'] ?? '');
            $permitPlaceEsc = $conn->real_escape_string($permitData['PLACE_ISS'] ?? '');
            $permitExpDateEsc = $conn->real_escape_string($permitData['P_EXP_DT'] ?? '');
            
            // Prepare bill data for cash memo function
            $billDataForMemo = [
                'BILL_NO' => $bill['bill_no'],
                'BILL_DATE' => $bill['bill_date'],
                'NET_AMOUNT' => $bill['total_amount']
            ];
            
            // Prepare items for cash memo function
            $itemsForMemo = [];
            foreach ($bill['items'] as $item) {
                $itemsForMemo[] = [
                    'DETAILS' => $item['name'],
                    'QTY' => $item['qty'],
                    'DETAILS2' => $item['size'] . 'ML',
                    'AMOUNT' => $item['amount']
                ];
            }
            
            // Use the existing generateCashMemoText function
            $cashMemoText = generateCashMemoText($companyData, $billDataForMemo, $itemsForMemo, $permitData);
            $cashMemoTextEsc = $conn->real_escape_string($cashMemoText);
            
            $itemsJson = json_encode($itemsForMemo);
            $itemsJsonEsc = $conn->real_escape_string($itemsJson);
            
            $cashMemoValues[] = "('$billNoEsc', $comp_id, '$printDateEsc', $user_id, '$licenseNumberEsc', '$shopNameEsc', '$shopAddressEsc', '$billDateEsc', '$customerNameEsc', '$permitNoEsc', '$permitPlaceEsc', '$permitExpDateEsc', '$itemsJsonEsc', {$bill['total_amount']}, '$cashMemoTextEsc')";
            $cash_memo_count++;
        }
        
        // Bulk insert
        if (!empty($cashMemoValues)) {
            $cashMemoChunks = array_chunk($cashMemoValues, 5000);
            $chunk_count = count($cashMemoChunks);
            $current_chunk = 0;
            
            foreach ($cashMemoChunks as $chunk) {
                $current_chunk++;
                $_SESSION[$progress_key]['message'] = "Saving cash memos (chunk $current_chunk/$chunk_count)...";
                
                $cashMemoSql = "INSERT IGNORE INTO tbl_cash_memo_prints 
                    (bill_no, comp_id, print_date, printed_by, license_number, shop_name, shop_address, 
                     bill_date, customer_name, permit_no, permit_place, permit_exp_date, items_json, total_amount, cash_memo_text) 
                    VALUES " . implode(',', $chunk);
                $conn->query($cashMemoSql);
            }
        }
    }
    
    $_SESSION[$progress_key]['percentage'] = 95;
    
    // ============================================================================
    // STEP 14: COMMIT AND FINALIZE
    // ============================================================================
    $conn->commit();
    
    $execution_time = round(microtime(true) - $start_time, 3);
    
    // Calculate total amount
    $total_amount = 0;
    $recent_bills = [];
    foreach ($bills as $bill) {
        $total_amount += $bill['total_amount'];
        if (count($recent_bills) < 20) {
            $recent_bills[] = [
                'bill_no' => $bill['bill_no'],
                'date' => $bill['bill_date'],
                'amount' => $bill['total_amount'],
                'items' => count($bill['items'])
            ];
        }
    }
    
    // Update final progress
    $_SESSION[$progress_key]['status'] = 'completed';
    $_SESSION[$progress_key]['percentage'] = 100;
    $_SESSION[$progress_key]['message'] = "Completed! Generated " . count($bills) . " bills with $cash_memo_count cash memos in {$execution_time} seconds";
    $_SESSION[$progress_key]['end_time'] = time();
    $_SESSION[$progress_key]['bills_generated'] = $recent_bills;
    
    $response['success'] = true;
    $response['message'] = "Generated " . count($bills) . " bills with $cash_memo_count cash memos in {$execution_time} seconds";
    $response['total_amount'] = number_format($total_amount, 2);
    $response['bill_count'] = count($bills);
    $response['cash_memo_count'] = $cash_memo_count;
    $response['execution_time'] = $execution_time;
    $response['bills'] = array_slice($recent_bills, -10);
    $response['progress_key'] = $progress_key;
    
    // Re-enable constraints
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
    
    // Clear saved distributions from session
    if (isset($_SESSION['item_distribution'])) {
        unset($_SESSION['item_distribution']);
    }
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollback();
    }
    $response['message'] = "Error: " . $e->getMessage();
    logMessage($e->getMessage(), 'ERROR');
    
    $_SESSION[$progress_key]['status'] = 'error';
    $_SESSION[$progress_key]['message'] = "Error: " . $e->getMessage();
    $_SESSION[$progress_key]['percentage'] = 0;
    
    // Re-enable constraints
    if (isset($conn)) {
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->query("SET UNIQUE_CHECKS = 1");
    }
}

// Keep progress in session for 5 minutes
if (isset($_SESSION[$progress_key])) {
    $_SESSION[$progress_key]['expires'] = time() + 300;
}

// Ensure we send JSON response
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode($response);
exit;

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get next bill number for batch generation
 */
function getNextBillNumberBatch($conn, $comp_id) {
    $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill 
              FROM tblsaleheader WHERE COMP_ID = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return 1;
    }
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return ($row['max_bill'] ?? 0) + 1;
}

/**
 * Get daily stock table name for a given month
 */
function getDailyStockTableName($comp_id, $month) {
    if (empty($month)) return null;
    
    $current_month = date('Y-m');
    if ($month === $current_month) {
        return "tbldailystock_" . $comp_id;
    } else {
        $month_short = date('m', strtotime($month));
        $year_short = date('y', strtotime($month));
        return "tbldailystock_" . $comp_id . "_" . $month_short . "_" . $year_short;
    }
}

/**
 * Create daily stock table quickly
 */
function createDailyStockTableFast($conn, $table_name) {
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
    
    return $conn->query($create_query);
}

/**
 * Recalculate daily stock quickly
 */
function recalculateDailyStockFast($conn, $table, $item_code, $month, $start_day) {
    $last_day = date('t', strtotime($month . '-01'));
    
    for ($day = $start_day; $day <= $last_day; $day++) {
        $day_num = sprintf('%02d', $day);
        $opening_col = "DAY_{$day_num}_OPEN";
        $purchase_col = "DAY_{$day_num}_PURCHASE";
        $sales_col = "DAY_{$day_num}_SALES";
        $closing_col = "DAY_{$day_num}_CLOSING";
        
        // Get current values
        $result = $conn->query("SELECT $opening_col, $purchase_col, $sales_col 
                                FROM $table 
                                WHERE ITEM_CODE = '$item_code' AND STK_MONTH = '$month'");
        
        if ($result && $row = $result->fetch_assoc()) {
            $opening = (float)($row[$opening_col] ?? 0);
            $purchase = (float)($row[$purchase_col] ?? 0);
            $sales = (float)($row[$sales_col] ?? 0);
            
            $closing = $opening + $purchase - $sales;
            
            $conn->query("UPDATE $table SET $closing_col = $closing WHERE ITEM_CODE = '$item_code' AND STK_MONTH = '$month'");
            
            // Update next day's opening
            if ($day < $last_day) {
                $next_day = sprintf('%02d', $day + 1);
                $conn->query("UPDATE $table SET DAY_{$next_day}_OPEN = $closing WHERE ITEM_CODE = '$item_code' AND STK_MONTH = '$month'");
            }
        }
    }
}
?>