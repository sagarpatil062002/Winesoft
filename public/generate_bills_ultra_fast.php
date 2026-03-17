<?php
// generate_bills_ultra_fast.php - HYPER-OPTIMIZED WITH SMART BATCHING
// Automatically detects memory pressure and processes in batches
// Handles any number of items without increasing memory limits
session_start();

// Error reporting - minimal for production
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
ini_set('memory_limit', '512M'); // Reasonable limit, batching will handle rest
ini_set('max_execution_time', 600); // 10 minutes max for large batches

// Include required files
require_once "../config/db.php";
require_once "volume_limit_utils.php";
require_once "cash_memo_functions.php";
require_once "drydays_functions.php";

// ============================================================================
// ENHANCED LOGGING FUNCTIONS
// ============================================================================
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/sales_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

function logDebug($message, $data = null) {
    $dataStr = $data !== null ? ' | Data: ' . json_encode($data) : '';
    logMessage($message . $dataStr, 'DEBUG');
}

// ============================================================================
// SMART BATCH PROCESSING CONFIGURATION
// ============================================================================
define('TARGET_MEMORY_USAGE', 400 * 1024 * 1024); // 400MB target memory usage
define('MIN_CHUNK_SIZE', 3);   // Minimum 3 items per chunk
define('MAX_CHUNK_SIZE', 15);  // Maximum 15 items per chunk
define('MEMORY_SAFETY_MARGIN', 0.8); // Use 80% of available memory

// ============================================================================
// MEMORY MONITORING FUNCTIONS
// ============================================================================
function getMemoryUsage() {
    return memory_get_usage(true);
}

function getMemoryLimit() {
    $limit = ini_get('memory_limit');
    $unit = strtoupper(substr($limit, -1));
    $value = (int)$limit;
    
    switch($unit) {
        case 'G': return $value * 1024 * 1024 * 1024;
        case 'M': return $value * 1024 * 1024;
        case 'K': return $value * 1024;
        default: return $value;
    }
}

function getMemoryUsagePercent() {
    $limit = getMemoryLimit();
    $current = memory_get_usage(true);
    return ($current / $limit) * 100;
}

function getAvailableMemory() {
    $limit = getMemoryLimit();
    $current = memory_get_usage(true);
    return ($limit - $current) * MEMORY_SAFETY_MARGIN;
}

function calculateOptimalChunkSize($item_count, $sample_size) {
    if ($item_count <= MIN_CHUNK_SIZE) {
        return $item_count;
    }
    
    $available_memory = getAvailableMemory();
    $estimated_per_item = $sample_size * 2; // Add overhead
    
    // Calculate how many items we can process with available memory
    $optimal_size = floor($available_memory / $estimated_per_item);
    
    // Clamp between MIN and MAX
    $optimal_size = max(MIN_CHUNK_SIZE, min($optimal_size, MAX_CHUNK_SIZE));
    
    // Don't exceed total items
    return min($optimal_size, $item_count);
}

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

// ============================================================================
// INITIALIZE PROGRESS TRACKING
// ============================================================================
$progress_key = 'bill_progress_' . session_id() . '_' . uniqid();
$_SESSION[$progress_key] = [
    'total_bills' => 0,
    'current_bill' => 0,
    'status' => 'initializing',
    'message' => 'Initializing smart batch processor...',
    'percentage' => 0,
    'bills_generated' => [],
    'start_time' => microtime(true),
    'chunks_processed' => 0,
    'total_chunks' => 0,
    'items_processed' => 0,
    'total_items' => 0,
    'memory_usage' => []
];

// ============================================================================
// VALIDATE REQUEST
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_bills'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request - missing generate_bills parameter']);
    exit;
}

$response = ['success' => false, 'message' => ''];
$start_time = microtime(true);
$all_bills = [];
$all_generated_bills = [];
$total_amount = 0;

try {
    // Log start
    logMessage("=== SMART BATCH BILL GENERATION START ===", 'INFO');
    
    // ============================================================================
    // GET BASIC PARAMETERS
    // ============================================================================
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    
    // Ensure dates are in correct order
    if (!empty($start_date) && !empty($end_date) && strtotime($start_date) > strtotime($end_date)) {
        $temp = $start_date;
        $start_date = $end_date;
        $end_date = $temp;
        logMessage("Date range swapped: $start_date to $end_date", 'INFO');
    }
    
    $mode = $_POST['mode'] ?? 'F';
    
    // Validate session data
    if (!isset($_SESSION['CompID'])) throw new Exception("User not logged in - missing CompID");
    if (!isset($_SESSION['user_id'])) throw new Exception("User not logged in - missing user_id");
    if (!isset($_SESSION['FIN_YEAR_ID'])) throw new Exception("Financial year not set");
    
    $comp_id = (int)$_SESSION['CompID'];
    $user_id = (int)$_SESSION['user_id'];
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    
    logDebug("Parameters", [
        'start_date' => $start_date,
        'end_date' => $end_date,
        'mode' => $mode,
        'comp_id' => $comp_id,
        'user_id' => $user_id
    ]);
    
    // ============================================================================
    // COLLECT ALL ITEMS AND DISTRIBUTIONS
    // ============================================================================
    $all_items = [];
    $all_distributions = [];
    
    // Parse items from POST
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        $all_items = $_POST['items'];
        logDebug("Found items array in POST[items]", ['count' => count($all_items)]);
    } else {
        // Try to parse individual items[CODE] keys
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'items[') === 0) {
                preg_match('/items\[(.*?)\]/', $key, $matches);
                if (isset($matches[1])) {
                    $all_items[$matches[1]] = intval($value);
                }
            }
        }
        logDebug("Parsed items from individual keys", ['count' => count($all_items)]);
    }
    
    // Parse distributions from POST
    if (isset($_POST['distribution']) && is_array($_POST['distribution'])) {
        $all_distributions = $_POST['distribution'];
        logDebug("Found distributions array in POST[distribution]", ['count' => count($all_distributions)]);
    } else {
        // Try to parse individual distribution[CODE] keys
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'distribution[') === 0) {
                preg_match('/distribution\[(.*?)\]/', $key, $matches);
                if (isset($matches[1])) {
                    $all_distributions[$matches[1]] = $value;
                }
            }
        }
        logDebug("Parsed distributions from individual keys", ['count' => count($all_distributions)]);
    }
    
    $total_items = count($all_items);
    if ($total_items == 0) {
        throw new Exception("No items to process");
    }
    
    logMessage("Total items received: $total_items", 'INFO');
    
    // Update progress
    $_SESSION[$progress_key]['total_items'] = $total_items;
    $_SESSION[$progress_key]['message'] = "Received $total_items items, analyzing...";
    
    // ============================================================================
    // CREATE DATE ARRAY
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
        throw new Exception("Invalid date range - no days");
    }
    
    logDebug("Date range created", ['days' => $days_count, 'first' => $date_array[0], 'last' => end($date_array)]);
    
    // ============================================================================
    // GET AVAILABLE DATES (EXCLUDING DRY DAYS)
    // ============================================================================
    $dryDaysManager = new DryDaysManager($conn);
    $dry_days = $dryDaysManager->getDryDaysInRange($start_date, $end_date);
    $dry_dates = array_keys($dry_days);
    
    $available_dates = array_diff($date_array, $dry_dates);
    $available_dates = array_values($available_dates);
    
    if (empty($available_dates)) {
        throw new Exception("No available dates - all are dry days");
    }
    
    logMessage("Date range: $start_date to $end_date, Days: $days_count, Available: " . count($available_dates), 'INFO');
    
    // ============================================================================
    // LOAD ALL ITEM MASTER DATA (DO THIS ONCE)
    // ============================================================================
    $item_codes = array_keys($all_items);
    $item_cache = [];
    
    if (!empty($item_codes)) {
        $placeholders = implode(',', array_fill(0, count($item_codes), '?'));
        $types = str_repeat('s', count($item_codes));
        
        $item_query = "SELECT CODE, DETAILS, RPRICE, LIQ_FLAG FROM tblitemmaster WHERE CODE IN ($placeholders)";
        $item_stmt = $conn->prepare($item_query);
        
        if (!$item_stmt) {
            throw new Exception("Failed to prepare item query: " . $conn->error);
        }
        
        $item_stmt->bind_param($types, ...$item_codes);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        
        while ($row = $item_result->fetch_assoc()) {
            $item_cache[$row['CODE']] = $row;
        }
        $item_stmt->close();
        
        logDebug("Loaded item data", ['found' => count($item_cache), 'missing' => $total_items - count($item_cache)]);
    }
    
    // ============================================================================
    // ESTIMATE MEMORY PER ITEM FOR SMART BATCHING
    // ============================================================================
    // Take a sample to estimate memory usage
    $sample_item_code = array_key_first($all_items);
    $sample_item = $all_items[$sample_item_code];
    $sample_dist = $all_distributions[$sample_item_code] ?? null;
    
    $sample_size = strlen(serialize($sample_item));
    if ($sample_dist) {
        $sample_size += strlen(serialize($sample_dist));
    }
    
    $estimated_memory_per_item = max(1024, $sample_size * 3); // At least 1KB, with overhead
    
    logMessage("Memory analysis - Current usage: " . round(getMemoryUsage() / 1024 / 1024, 2) . "MB", 'INFO');
    logMessage("Memory analysis - Estimated per item: " . round($estimated_memory_per_item / 1024, 2) . "KB", 'INFO');
    logMessage("Memory analysis - Available memory: " . round(getAvailableMemory() / 1024 / 1024, 2) . "MB", 'INFO');
    
    // ============================================================================
    // CALCULATE OPTIMAL CHUNK SIZE
    // ============================================================================
    $chunk_size = calculateOptimalChunkSize($total_items, $estimated_memory_per_item);
    $total_chunks = ceil($total_items / $chunk_size);
    
    $_SESSION[$progress_key]['total_chunks'] = $total_chunks;
    $_SESSION[$progress_key]['message'] = "Processing $total_items items in $total_chunks batches of ~$chunk_size items";
    
    logMessage("Smart batching: $total_items items into $total_chunks chunks of ~$chunk_size items", 'INFO');
    
    // ============================================================================
    // PROCESS ITEMS IN BATCHES
    // ============================================================================
    $item_keys = array_keys($all_items);
    $chunks = array_chunk($item_keys, $chunk_size);
    $all_daily_sales_data = [];
    $all_items_data = [];
    $date_index_map = array_flip($date_array);
    $total_processed = 0;
    
    foreach ($chunks as $chunk_index => $chunk_item_keys) {
        $chunk_number = $chunk_index + 1;
        $chunk_items = [];
        $chunk_distributions = [];
        
        // Build chunk data
        foreach ($chunk_item_keys as $code) {
            if (isset($all_items[$code])) {
                $chunk_items[$code] = $all_items[$code];
            }
            if (isset($all_distributions[$code])) {
                $chunk_distributions[$code] = $all_distributions[$code];
            }
        }
        
        $chunk_item_count = count($chunk_items);
        $total_processed += $chunk_item_count;
        
        // Update progress
        $_SESSION[$progress_key]['chunks_processed'] = $chunk_index;
        $_SESSION[$progress_key]['message'] = "Processing batch $chunk_number of $total_chunks ($chunk_item_count items)...";
        $_SESSION[$progress_key]['percentage'] = round(($chunk_index / $total_chunks) * 40);
        
        // Record memory before chunk
        $mem_before = getMemoryUsage();
        
        logMessage("Processing batch $chunk_number with $chunk_item_count items", 'INFO');
        logDebug("Memory before batch $chunk_number", round($mem_before / 1024 / 1024, 2) . "MB");
        
        // Process this chunk
        $chunk_result = processItemChunk(
            $conn,
            $chunk_items,
            $chunk_distributions,
            $item_cache,
            $date_array,
            $available_dates,
            $days_count,
            $date_index_map,
            $mode
        );
        
        // Merge results
        foreach ($chunk_result['daily_sales_data'] as $code => $distribution) {
            $all_daily_sales_data[$code] = $distribution;
        }
        
        foreach ($chunk_result['items_data'] as $code => $data) {
            $all_items_data[$code] = $data;
        }
        
        // Free memory
        unset($chunk_result);
        unset($chunk_items);
        unset($chunk_distributions);
        
        // Force garbage collection
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Record memory after chunk
        $mem_after = getMemoryUsage();
        $mem_used = $mem_after - $mem_before;
        
        logDebug("Memory after batch $chunk_number", [
            'used' => round($mem_used / 1024 / 1024, 2) . "MB",
            'total' => round($mem_after / 1024 / 1024, 2) . "MB",
            'percent' => round(getMemoryUsagePercent(), 2) . "%"
        ]);
        
        // Store memory stats for progress
        $_SESSION[$progress_key]['memory_usage'][] = [
            'batch' => $chunk_number,
            'percent' => round(getMemoryUsagePercent(), 2)
        ];
        
        // Check memory and adjust if needed
        $memory_percent = getMemoryUsagePercent();
        if ($memory_percent > 70 && $chunk_index < $total_chunks - 1) {
            $remaining_items = $total_items - $total_processed;
            $new_chunk_size = max(MIN_CHUNK_SIZE, floor($chunk_size * 0.6));
            
            logMessage("High memory usage ({$memory_percent}%), adjusting chunk size to $new_chunk_size for remaining $remaining_items items", 'WARNING');
            
            // Rebuild remaining chunks with smaller size
            $remaining_keys = array_slice($item_keys, $total_processed);
            $chunks = array_chunk($remaining_keys, $new_chunk_size);
            $total_chunks = $chunk_index + 1 + count($chunks);
            
            $_SESSION[$progress_key]['total_chunks'] = $total_chunks;
            $_SESSION[$progress_key]['message'] = "Memory high, adjusted to smaller batches...";
        }
    }
    
    logMessage("All batches processed successfully", 'INFO');
    logMessage("Total items processed: " . count($all_items_data), 'INFO');
    
    // ============================================================================
    // GENERATE BILLS FROM ALL PROCESSED DATA
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Generating bills from all batches...';
    $_SESSION[$progress_key]['percentage'] = 45;
    
    if (empty($all_items_data)) {
        throw new Exception("No valid items data after processing");
    }
    
    logMessage("Calling generateBillsWithLimits with " . count($all_items_data) . " items", 'INFO');
    
    $bills = generateBillsWithLimits(
        $conn,
        $all_items_data,
        $date_array,
        $all_daily_sales_data,
        $mode,
        $comp_id,
        $user_id,
        $fin_year_id,
        $available_dates
    );
    
    if (empty($bills)) {
        throw new Exception("No bills generated");
    }
    
    logMessage("Generated " . count($bills) . " bills total", 'INFO');
    
    // ============================================================================
    // GET NEXT BILL NUMBER
    // ============================================================================
    $bill_no_query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader WHERE COMP_ID = ?";
    $bill_no_stmt = $conn->prepare($bill_no_query);
    $bill_no_stmt->bind_param("i", $comp_id);
    $bill_no_stmt->execute();
    $bill_no_result = $bill_no_stmt->get_result();
    $bill_no_row = $bill_no_result->fetch_assoc();
    $bill_no_start = ($bill_no_row['max_bill'] ?? 0) + 1;
    $bill_no_stmt->close();
    
    logDebug("Starting bill number", $bill_no_start);
    
    // ============================================================================
    // PREPARE DATA FOR BULK INSERT (WITH MEMORY MONITORING)
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Preparing bulk insert data...';
    $_SESSION[$progress_key]['percentage'] = 60;
    
    $header_values = [];
    $detail_values = [];
    $stock_updates = [];
    $generated_bills = [];
    $total_amount = 0;
    
    foreach ($bills as $index => $bill) {
        $bill_no = 'BL' . str_pad($bill_no_start + $index, 4, '0', STR_PAD_LEFT);
        
        // Header
        $header_values[] = "('$bill_no', '{$bill['bill_date']}', {$bill['total_amount']}, 0, {$bill['total_amount']}, '{$bill['mode']}', $comp_id, $user_id)";
        
        // Details and stock updates
        foreach ($bill['items'] as $item) {
            $detail_values[] = "('$bill_no', '{$item['code']}', {$item['qty']}, {$item['rate']}, {$item['amount']}, '{$bill['mode']}', $comp_id)";
            $stock_updates[$item['code']] = ($stock_updates[$item['code']] ?? 0) + $item['qty'];
        }
        
        $generated_bills[] = [
            'bill_no' => $bill_no,
            'date' => $bill['bill_date'],
            'amount' => $bill['total_amount'],
            'items' => count($bill['items'])
        ];
        
        $total_amount += $bill['total_amount'];
        
        // Update progress periodically
        if (($index + 1) % 50 == 0) {
            $_SESSION[$progress_key]['current_bill'] = $index + 1;
            $_SESSION[$progress_key]['percentage'] = 60 + round((($index + 1) / count($bills)) * 20);
        }
    }
    
    logDebug("Prepared for bulk insert", [
        'headers' => count($header_values),
        'details' => count($detail_values),
        'stock_updates' => count($stock_updates)
    ]);
    
    // ============================================================================
    // START TRANSACTION
    // ============================================================================
    $conn->begin_transaction();
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    $conn->query("SET UNIQUE_CHECKS = 0");
    
    // ============================================================================
    // BULK INSERT HEADERS (IN CHUNKS)
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Saving bill headers...';
    
    if (!empty($header_values)) {
        $header_chunks = array_chunk($header_values, 2000);
        $chunk_count = count($header_chunks);
        
        foreach ($header_chunks as $chunk_index => $chunk) {
            $sql = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) VALUES " . implode(',', $chunk);
            if (!$conn->query($sql)) {
                throw new Exception("Header insert failed: " . $conn->error);
            }
            logDebug("Inserted headers chunk " . ($chunk_index + 1) . "/$chunk_count");
        }
    }
    
    // ============================================================================
    // BULK INSERT DETAILS (IN CHUNKS)
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Saving bill details...';
    
    if (!empty($detail_values)) {
        $detail_chunks = array_chunk($detail_values, 5000);
        $chunk_count = count($detail_chunks);
        
        foreach ($detail_chunks as $chunk_index => $chunk) {
            $sql = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) VALUES " . implode(',', $chunk);
            if (!$conn->query($sql)) {
                throw new Exception("Details insert failed: " . $conn->error);
            }
            logDebug("Inserted details chunk " . ($chunk_index + 1) . "/$chunk_count");
        }
    }
    
    // ============================================================================
    // UPDATE STOCK (IN CHUNKS)
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Updating stock levels...';
    
    if (!empty($stock_updates)) {
        $stock_column = "Current_Stock" . $comp_id;
        $stock_values = [];
        
        foreach ($stock_updates as $code => $qty) {
            $code_esc = $conn->real_escape_string($code);
            $stock_values[] = "('$code_esc', '$fin_year_id', $qty)";
        }
        
        $stock_chunks = array_chunk($stock_values, 2000);
        $chunk_count = count($stock_chunks);
        
        foreach ($stock_chunks as $chunk_index => $chunk) {
            $sql = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $stock_column) VALUES " . implode(',', $chunk) .
                   " ON DUPLICATE KEY UPDATE $stock_column = $stock_column - VALUES($stock_column)";
            
            if (!$conn->query($sql)) {
                throw new Exception("Stock update failed: " . $conn->error);
            }
            logDebug("Updated stock chunk " . ($chunk_index + 1) . "/$chunk_count");
        }
    }
    
    // ============================================================================
    // GENERATE CASH MEMOS (IN BATCHES)
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Generating cash memos...';
    $_SESSION[$progress_key]['percentage'] = 85;
    
    $cash_memo_count = 0;
    
    if (!empty($generated_bills)) {
        // Get company data
        $companyQuery = "SELECT COMP_NAME, COMP_ADDR, COMP_FLNO FROM tblcompany WHERE CompID = ?";
        $companyStmt = $conn->prepare($companyQuery);
        $companyStmt->bind_param("i", $comp_id);
        $companyStmt->execute();
        $companyResult = $companyStmt->get_result();
        $companyRow = $companyResult->fetch_assoc();
        $companyStmt->close();
        
        $companyData = [
            'name' => $companyRow['COMP_NAME'] ?? 'WINE SHOP',
            'address' => $companyRow['COMP_ADDR'] ?? '',
            'licenseNumber' => $companyRow['COMP_FLNO'] ?? ''
        ];
        
        // Get permits
        $permitQuery = "SELECT P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, DETAILS FROM tblpermit WHERE P_NO IS NOT NULL AND P_NO != '' LIMIT 1000";
        $permitResult = $conn->query($permitQuery);
        $allPermits = $permitResult->fetch_all(MYSQLI_ASSOC);
        
        if (!empty($allPermits)) {
            $cashMemoValues = [];
            $printDate = date('Y-m-d H:i:s');
            
            foreach ($generated_bills as $bill) {
                // Find the corresponding bill data
                $billData = null;
                foreach ($bills as $b) {
                    if (($b['bill_no'] ?? '') === $bill['bill_no']) {
                        $billData = $b;
                        break;
                    }
                }
                
                if (!$billData) continue;
                
                $permitData = $allPermits[array_rand($allPermits)];
                
                // Prepare items for cash memo
                $itemsForMemo = [];
                foreach ($billData['items'] as $item) {
                    $itemsForMemo[] = [
                        'DETAILS' => $item['name'],
                        'QTY' => $item['qty'],
                        'DETAILS2' => 'ML',
                        'AMOUNT' => $item['amount']
                    ];
                }
                
                // Generate cash memo text
                $cashMemoText = generateCashMemoText($companyData, $billData, $itemsForMemo, $permitData);
                
                $cashMemoValues[] = "(
                    '{$bill['bill_no']}', 
                    $comp_id, 
                    '$printDate', 
                    $user_id, 
                    '{$companyData['licenseNumber']}', 
                    '{$companyData['name']}', 
                    '{$companyData['address']}', 
                    '{$bill['date']}', 
                    '{$permitData['DETAILS']}', 
                    '{$permitData['P_NO']}', 
                    '{$permitData['PLACE_ISS']}', 
                    '{$permitData['P_EXP_DT']}', 
                    '" . $conn->real_escape_string(json_encode($itemsForMemo)) . "', 
                    {$bill['amount']}, 
                    '" . $conn->real_escape_string($cashMemoText) . "'
                )";
                
                $cash_memo_count++;
                
                // Insert in batches of 500
                if (count($cashMemoValues) >= 500) {
                    $sql = "INSERT IGNORE INTO tbl_cash_memo_prints 
                            (bill_no, comp_id, print_date, printed_by, license_number, shop_name, shop_address, 
                             bill_date, customer_name, permit_no, permit_place, permit_exp_date, items_json, total_amount, cash_memo_text) 
                            VALUES " . implode(',', $cashMemoValues);
                    $conn->query($sql);
                    $cashMemoValues = [];
                }
            }
            
            // Insert remaining
            if (!empty($cashMemoValues)) {
                $sql = "INSERT IGNORE INTO tbl_cash_memo_prints 
                        (bill_no, comp_id, print_date, printed_by, license_number, shop_name, shop_address, 
                         bill_date, customer_name, permit_no, permit_place, permit_exp_date, items_json, total_amount, cash_memo_text) 
                        VALUES " . implode(',', $cashMemoValues);
                $conn->query($sql);
            }
        }
    }
    
    // ============================================================================
    // COMMIT TRANSACTION
    // ============================================================================
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
    $conn->commit();
    
    $execution_time = round(microtime(true) - $start_time, 3);
    
    // Clear session data
    if (isset($_SESSION['sale_quantities'])) {
        unset($_SESSION['sale_quantities']);
    }
    if (isset($_SESSION['item_distribution'])) {
        unset($_SESSION['item_distribution']);
    }
    
    // Prepare success response
    $response = [
        'success' => true,
        'message' => "Success! Processed $total_items items in $total_chunks batches. Generated " . count($generated_bills) . " bills with $cash_memo_count cash memos in {$execution_time}s",
        'total_amount' => number_format($total_amount, 2),
        'bill_count' => count($generated_bills),
        'cash_memo_count' => $cash_memo_count,
        'execution_time' => $execution_time,
        'bills' => array_slice($generated_bills, -10),
        'batches_used' => $total_chunks,
        'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . "MB",
        'progress_key' => $progress_key
    ];
    
    // Update final progress
    $_SESSION[$progress_key]['status'] = 'completed';
    $_SESSION[$progress_key]['percentage'] = 100;
    $_SESSION[$progress_key]['message'] = $response['message'];
    $_SESSION[$progress_key]['bills_generated'] = array_slice($generated_bills, -10);
    $_SESSION[$progress_key]['end_time'] = time();
    
    logMessage("SUCCESS: " . $response['message'], 'INFO');
    logMessage("Peak memory usage: " . $response['memory_peak'], 'INFO');
    
} catch (Exception $e) {
    // Rollback transaction if started
    if (isset($conn)) {
        $conn->rollback();
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->query("SET UNIQUE_CHECKS = 1");
    }
    
    // Log error
    logMessage("ERROR: " . $e->getMessage(), 'ERROR');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'ERROR');
    
    // Update progress
    $_SESSION[$progress_key]['status'] = 'error';
    $_SESSION[$progress_key]['message'] = "Error: " . $e->getMessage();
    
    // Prepare error response
    $response['message'] = "Error: " . $e->getMessage();
}

// Keep progress in session for 5 minutes
if (isset($_SESSION[$progress_key])) {
    $_SESSION[$progress_key]['expires'] = time() + 300;
}

// Send response
echo json_encode($response);
exit;

// ============================================================================
// HELPER FUNCTION TO PROCESS ITEM CHUNK
// ============================================================================
function processItemChunk($conn, $items, $distributions, $item_cache, $date_array, $available_dates, $days_count, $date_index_map, $mode) {
    $daily_sales_data = [];
    $items_data = [];
    
    foreach ($items as $item_code => $total_qty) {
        $total_qty = (int)$total_qty;
        if ($total_qty <= 0 || !isset($item_cache[$item_code])) {
            continue;
        }
        
        $full_distribution = array_fill(0, $days_count, 0);
        $has_saved_distribution = false;
        
        // Try to use saved distribution
        if (isset($distributions[$item_code])) {
            $dist_value = $distributions[$item_code];
            $dist_type = gettype($dist_value);
            
            // Case 1: Already array
            if (is_array($dist_value)) {
                if (count($dist_value) === $days_count) {
                    $full_distribution = $dist_value;
                    $has_saved_distribution = true;
                    logDebug("Chunk - Using array distribution for $item_code");
                }
            }
            // Case 2: JSON string
            elseif (is_string($dist_value)) {
                // Try JSON decode first
                $decoded = json_decode($dist_value, true);
                if (is_array($decoded) && count($decoded) === $days_count) {
                    $full_distribution = $decoded;
                    $has_saved_distribution = true;
                    logDebug("Chunk - Using JSON distribution for $item_code");
                } else {
                    // Try CSV format with brackets
                    $clean_str = trim($dist_value);
                    if (strpos($clean_str, '[') === 0 && strrpos($clean_str, ']') === strlen($clean_str) - 1) {
                        $clean_str = substr($clean_str, 1, -1);
                    }
                    
                    $parts = explode(',', $clean_str);
                    if (count($parts) === $days_count) {
                        $full_distribution = array_map('intval', $parts);
                        $has_saved_distribution = true;
                        logDebug("Chunk - Using CSV distribution for $item_code");
                    }
                }
            }
            // Case 3: Numeric for single day
            elseif (is_numeric($dist_value) && $days_count == 1) {
                $full_distribution[0] = (int)$dist_value;
                $has_saved_distribution = true;
                logDebug("Chunk - Using numeric distribution for $item_code");
            }
        }
        
        // Generate random distribution if needed
        if (!$has_saved_distribution) {
            if ($total_qty > 0 && !empty($available_dates)) {
                $available_count = count($available_dates);
                $temp_dist = array_fill(0, $available_count, 0);
                
                // Random distribution
                for ($i = 0; $i < $total_qty; $i++) {
                    $random_day = mt_rand(0, $available_count - 1);
                    $temp_dist[$random_day]++;
                }
                
                // Map to full date array
                foreach ($available_dates as $i => $date) {
                    $date_index = $date_index_map[$date] ?? null;
                    if ($date_index !== null) {
                        $full_distribution[$date_index] = $temp_dist[$i];
                    }
                }
                
                logDebug("Chunk - Generated random distribution for $item_code");
            }
        }
        
        // Validate sum and auto-correct if needed
        $distribution_sum = array_sum($full_distribution);
        if ($distribution_sum !== $total_qty && !empty($available_dates)) {
            $diff = $total_qty - $distribution_sum;
            if ($diff > 0) {
                $random_date = $available_dates[array_rand($available_dates)];
                $random_index = $date_index_map[$random_date] ?? 0;
                $full_distribution[$random_index] += $diff;
                logDebug("Chunk - Auto-corrected distribution for $item_code, added $diff to $random_date");
            }
        }
        
        $daily_sales_data[$item_code] = $full_distribution;
        
        $items_data[$item_code] = [
            'rate' => (float)$item_cache[$item_code]['RPRICE'],
            'name' => $item_cache[$item_code]['DETAILS']
        ];
    }
    
    return [
        'daily_sales_data' => $daily_sales_data,
        'items_data' => $items_data
    ];
}
?>