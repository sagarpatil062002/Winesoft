<?php
// generate_bills_progress.php - WITH REAL-TIME PROGRESS TRACKING
session_start();
include_once "../config/db.php";
include_once "volume_limit_utils.php";
include_once "cash_memo_functions.php";

// Initialize progress tracking
$_SESSION['bill_progress'] = [
    'total_bills' => 0,
    'current_bill' => 0,
    'status' => 'initializing',
    'message' => 'Preparing to generate bills...',
    'percentage' => 0,
    'bills_generated' => [],
    'start_time' => time(),
    'estimated_time_remaining' => 0
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_bills'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$response = ['success' => false, 'message' => '', 'total_amount' => 0, 'bill_count' => 0];
$start_time = microtime(true);

try {
    // ============================================================================
    // STEP 1: MAXIMUM PERFORMANCE SETTINGS
    // ============================================================================
    $conn->query("SET SESSION unique_checks = 0");
    $conn->query("SET SESSION foreign_key_checks = 0");
    $conn->query("SET SESSION sql_log_bin = 0");
    $conn->query("SET autocommit = 0");
    $conn->query("SET SESSION bulk_insert_buffer_size = 1024 * 1024 * 1024"); // 1GB
    
    // ============================================================================
    // STEP 2: GET INPUT PARAMETERS
    // ============================================================================
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $mode = $_POST['mode'];
    $comp_id = (int)$_SESSION['CompID'];
    $user_id = (int)$_SESSION['user_id'];
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    $items = $_POST['items']; // Array of [item_code => qty]
    $source_page = $_POST['source_page'] ?? 'sale_for_date_range'; // Track which page called it
    
    $_SESSION['bill_progress']['status'] = 'loading_data';
    $_SESSION['bill_progress']['message'] = 'Loading item data...';
    
    // ============================================================================
    // STEP 3: CREATE DATE ARRAY
    // ============================================================================
    $date_array = [];
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    foreach (new DatePeriod($begin, $interval, $end) as $date) {
        $date_array[] = $date->format("Y-m-d");
    }
    $days_count = count($date_array);
    
    // ============================================================================
    // STEP 4: BULK LOAD ALL MASTER DATA
    // ============================================================================
    $item_codes = array_keys($items);
    
    // Query 1: Load all item data with proper structure for volume_limit_utils
    $item_cache = [];
    $items_data = [];
    if (!empty($item_codes)) {
        $placeholders = implode(',', array_fill(0, count($item_codes), '?'));
        $types = str_repeat('s', count($item_codes));
        
        $item_query = "SELECT im.CODE, 
                              COALESCE(NULLIF(im.Print_Name, ''), im.DETAILS) as display_name,
                              im.DETAILS, im.DETAILS2, im.RPRICE, im.LIQ_FLAG
                       FROM tblitemmaster im
                       WHERE im.CODE IN ($placeholders)";
        
        $item_stmt = $conn->prepare($item_query);
        $item_stmt->bind_param($types, ...$item_codes);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        while ($row = $item_result->fetch_assoc()) {
            $item_cache[$row['CODE']] = $row;
            $items_data[$row['CODE']] = [
                'rate' => (float)$row['RPRICE'],
                'name' => $row['display_name'] ?? $row['DETAILS']
            ];
        }
        $item_stmt->close();
    }
    
    // Query 2: Get category limits using the proper function
    $category_limits = getCategoryLimits($conn, $comp_id);
    $category_limits['OTHER'] = PHP_FLOAT_MAX; // No limit for OTHER
    
    $_SESSION['bill_progress']['status'] = 'processing';
    $_SESSION['bill_progress']['message'] = 'Processing items with proper volume limits...';
    
    // ============================================================================
    // STEP 5: CREATE DAILY DISTRIBUTION FOR PROPER BILL GENERATION
    // ============================================================================
    $daily_sales_data = [];
    $total_items_processed = 0;
    $total_items = count($items);
    
    foreach ($items as $item_code => $total_qty) {
        $total_qty = (int)$total_qty;
        if ($total_qty <= 0 || !isset($item_cache[$item_code])) {
            $total_items_processed++;
            continue;
        }
        
        // Use distributeSales function from volume_limit_utils for uniform distribution
        $daily_sales_data[$item_code] = distributeSales($total_qty, $days_count);
        
        $total_items_processed++;
        
        // Update progress every 10 items
        if ($total_items_processed % 10 == 0) {
            $_SESSION['bill_progress']['message'] = "Processed $total_items_processed of $total_items items...";
        }
    }
    
    // ============================================================================
    // STEP 6: ESTIMATE TOTAL BILLS
    // ============================================================================
    $estimated_bills = 0;
    foreach ($daily_sales_data as $item_code => $daily_qtys) {
        foreach ($daily_qtys as $qty) {
            if ($qty > 0) $estimated_bills++;
        }
    }
    
    $_SESSION['bill_progress']['total_bills'] = $estimated_bills;
    $_SESSION['bill_progress']['status'] = 'generating';
    $_SESSION['bill_progress']['message'] = "Generating approximately $estimated_bills bills with proper volume limits...";
    
    // ============================================================================
    // STEP 7: GENERATE BILLS USING PROPER VOLUME LIMIT FUNCTIONS
    // ============================================================================
    // Use the proper generateBillsWithLimits function from volume_limit_utils.php
    $bills = generateBillsWithLimits(
        $conn,
        $items_data,
        $date_array,
        $daily_sales_data,
        $mode,
        $comp_id,
        $user_id,
        $fin_year_id
    );
    
    // Assign proper bill numbers to all bills
    $next_bill_num = getNextBillNumberBatch($conn, $comp_id);
    $bill_count = 0;
    foreach ($bills as &$bill) {
        $bill['bill_no'] = 'BL' . str_pad($next_bill_num + $bill_count, 4, '0', STR_PAD_LEFT);
        $bill['mode'] = $mode;
        $bill['comp_id'] = $comp_id;
        $bill['user_id'] = $user_id;
        $bill_count++;
    }
    unset($bill);
    
    // Update progress for each bill
    foreach ($bills as $bill) {
        $bill_count++;
        $_SESSION['bill_progress']['current_bill'] = $bill_count;
        $_SESSION['bill_progress']['total_bills'] = max($estimated_bills, $bill_count);
        $_SESSION['bill_progress']['percentage'] = round(($bill_count / max($estimated_bills, $bill_count)) * 100);
        
        $elapsed = time() - $_SESSION['bill_progress']['start_time'];
        $rate = $bill_count / max($elapsed, 1);
        $remaining_bills = max($estimated_bills, $bill_count) - $bill_count;
        $_SESSION['bill_progress']['estimated_time_remaining'] = round($remaining_bills / max($rate, 0.1));
        
        $_SESSION['bill_progress']['bills_generated'][] = [
            'bill_no' => $bill['bill_no'],
            'date' => $bill['bill_date'],
            'amount' => $bill['total_amount'],
            'items' => count($bill['items'])
        ];
        
        $_SESSION['bill_progress']['message'] = "Generated bill " . $bill['bill_no'] . " ($bill_count of " . max($estimated_bills, $bill_count) . ")";
    }
    $bill_count = count($bills);
    
    // ============================================================================
    // STEP 8: BATCH INSERT HEADERS
    // ============================================================================
    $_SESSION['bill_progress']['status'] = 'saving';
    $_SESSION['bill_progress']['message'] = 'Saving bills to database...';
    
    if (empty($bills)) {
        throw new Exception("No bills generated");
    }
    
    $header_values = [];
    foreach ($bills as $bill) {
        $header_values[] = "('{$bill['bill_no']}', '{$bill['bill_date']}', {$bill['total_amount']}, 0, {$bill['total_amount']}, '{$bill['mode']}', {$bill['comp_id']}, {$bill['user_id']})";
    }
    
    // Insert in chunks of 500
    $header_chunks = array_chunk($header_values, 500);
    foreach ($header_chunks as $chunk) {
        $batch_header = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) VALUES " . implode(',', $chunk);
        $conn->query($batch_header);
    }
    
    // ============================================================================
    // STEP 9: BATCH INSERT DETAILS
    // ============================================================================
    $_SESSION['bill_progress']['message'] = 'Saving bill details...';
    
    $detail_values = [];
    foreach ($bills as $bill) {
        foreach ($bill['items'] as $item) {
            $detail_values[] = "('{$bill['bill_no']}', '{$item['code']}', {$item['qty']}, {$item['rate']}, {$item['amount']}, '{$bill['mode']}', {$bill['comp_id']})";
        }
    }
    
    // Insert in chunks of 2000
    $detail_chunks = array_chunk($detail_values, 2000);
    foreach ($detail_chunks as $chunk) {
        $batch_detail = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) VALUES " . implode(',', $chunk);
        $conn->query($batch_detail);
    }
    
    // ============================================================================
    // STEP 10: BULK UPDATE ITEM STOCK
    // ============================================================================
    $_SESSION['bill_progress']['message'] = 'Updating stock levels...';
    
    $current_stock_column = "Current_Stock" . $comp_id;
    
    // Aggregate quantities
    $stock_aggregates = [];
    foreach ($bills as $bill) {
        foreach ($bill['items'] as $item) {
            $code = $item['code'];
            $stock_aggregates[$code] = ($stock_aggregates[$code] ?? 0) + $item['qty'];
        }
    }
    
    // Create and populate temp table
    $conn->query("CREATE TEMPORARY TABLE temp_stock (item_code VARCHAR(50) PRIMARY KEY, qty DECIMAL(10,3))");
    
    $stock_values = [];
    foreach ($stock_aggregates as $code => $qty) {
        $stock_values[] = "('" . $conn->real_escape_string($code) . "', $qty)";
    }
    
    if (!empty($stock_values)) {
        $conn->query("INSERT INTO temp_stock (item_code, qty) VALUES " . implode(',', $stock_values));
        
        // Update existing stocks
        $conn->query("UPDATE tblitem_stock ts 
                      JOIN temp_stock t ON ts.ITEM_CODE = t.item_code
                      SET ts.$current_stock_column = ts.$current_stock_column - t.qty");
        
        // Insert missing stocks
        $conn->query("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $current_stock_column)
                      SELECT t.item_code, '$fin_year_id', -t.qty
                      FROM temp_stock t
                      LEFT JOIN tblitem_stock ts ON ts.ITEM_CODE = t.item_code
                      WHERE ts.ITEM_CODE IS NULL");
    }
    
    $conn->query("DROP TEMPORARY TABLE temp_stock");
    
    // ============================================================================
    // STEP 11: BULK UPDATE DAILY STOCK
    // ============================================================================
    $_SESSION['bill_progress']['message'] = 'Updating daily stock records...';
    
    $current_month = date('Y-m');
    $updates_by_table_day = [];
    
    foreach ($bills as $bill) {
        $sale_date = $bill['bill_date'];
        $month = date('Y-m', strtotime($sale_date));
        $day = (int)date('d', strtotime($sale_date));
        $day_col = "DAY_" . sprintf('%02d', $day) . "_SALES";
        
        $table = ($month === $current_month) 
            ? "tbldailystock_{$comp_id}" 
            : "tbldailystock_{$comp_id}_" . date('m_y', strtotime($sale_date));
        
        $key = $table . '|' . $month . '|' . $day_col;
        
        foreach ($bill['items'] as $item) {
            $item_key = $key . '|' . $item['code'];
            $updates_by_table_day[$item_key] = ($updates_by_table_day[$item_key] ?? 0) + $item['qty'];
        }
    }
    
    // Group by table and execute updates
    $table_updates = [];
    foreach ($updates_by_table_day as $key => $qty) {
        $parts = explode('|', $key);
        $table = $parts[0] ?? '';
        $month = $parts[1] ?? '';
        $day_col = $parts[2] ?? '';
        $item_code = $parts[3] ?? '';
        
        if (!isset($table_updates[$table])) {
            $table_updates[$table] = [];
        }
        if (!isset($table_updates[$table][$month])) {
            $table_updates[$table][$month] = [];
        }
        if (!isset($table_updates[$table][$month][$day_col])) {
            $table_updates[$table][$month][$day_col] = [];
        }
        $table_updates[$table][$month][$day_col][$item_code] = $qty;
    }
    
    foreach ($table_updates as $table => $month_data) {
        foreach ($month_data as $month => $day_data) {
            foreach ($day_data as $day_col => $items) {
                $case_sql = "UPDATE $table SET $day_col = CASE ";
                $item_list = [];
                
                foreach ($items as $item_code => $qty) {
                    $case_sql .= "WHEN ITEM_CODE = '$item_code' THEN $day_col + $qty ";
                    $item_list[] = "'$item_code'";
                }
                
                $case_sql .= "END, LAST_UPDATED = CURRENT_TIMESTAMP 
                              WHERE STK_MONTH = '$month' 
                              AND ITEM_CODE IN (" . implode(',', $item_list) . ")";
                
                $conn->query($case_sql);
            }
        }
    }
    
    // ============================================================================
    // STEP 12: BULK GENERATE CASH MEMOS (PROPER STRUCTURE)
    // ============================================================================
    $_SESSION['bill_progress']['message'] = 'Generating cash memos...';
    
    // Get company data once
    $companyQuery = "SELECT COMP_NAME, COMP_ADDR, COMP_FLNO, CF_LINE, CS_LINE FROM tblcompany WHERE CompID = ?";
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
    $addressLine = $companyRow['CF_LINE'] ?? "";
    if (!empty($companyRow['CS_LINE'])) {
        $addressLine .= (!empty($addressLine) ? " " : "") . $companyRow['CS_LINE'];
    }
    if (!empty($addressLine)) {
        $companyData['address'] = $addressLine;
    }
    
    // Get permits once
    $permitResult = $conn->query("SELECT P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, DETAILS FROM tblpermit WHERE P_NO IS NOT NULL AND P_NO != '' LIMIT 100");
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
            $billNo = $bill['bill_no'];
            $billDate = $bill['bill_date'];
            $totalAmount = $bill['total_amount'];
            
            // Pick random permit
            $permitData = $allPermits[array_rand($allPermits)];
            
            $customerName = $permitData['DETAILS'] ?? 'RETAIL';
            $permitNo = $permitData['P_NO'] ?? null;
            $permitPlace = $permitData['PLACE_ISS'] ?? null;
            $permitExpDate = !empty($permitData['P_EXP_DT']) ? $permitData['P_EXP_DT'] : null;
            
            // Build items JSON from bill items
            $itemsForJson = [];
            foreach ($bill['items'] as $item) {
                $itemsForJson[] = [
                    'ITEM_CODE' => $item['code'],
                    'QTY' => $item['qty'],
                    'RATE' => $item['rate'],
                    'AMOUNT' => $item['amount'],
                    'DETAILS' => $item['name'] ?? '',
                    'DETAILS2' => $item['size'] . 'ML'
                ];
            }
            $itemsJson = json_encode($itemsForJson);
            
            // Create cash memo text
            $billDataForText = [
                'BILL_NO' => $billNo,
                'BILL_DATE' => $billDate,
                'NET_AMOUNT' => $totalAmount
            ];
            $cashMemoText = generateCashMemoText($companyData, $billDataForText, $itemsForJson, $permitData);
            
            // Escape strings for SQL
            $billNoEsc = $conn->real_escape_string($billNo);
            $printDateEsc = $conn->real_escape_string($printDate);
            $licenseNumberEsc = $conn->real_escape_string($companyData['licenseNumber']);
            $shopNameEsc = $conn->real_escape_string($companyData['name']);
            $shopAddressEsc = $conn->real_escape_string($companyData['address']);
            $billDateEsc = $conn->real_escape_string($billDate);
            $customerNameEsc = $conn->real_escape_string($customerName);
            $permitNoEsc = $permitNo ? $conn->real_escape_string($permitNo) : '';
            $permitPlaceEsc = $permitPlace ? $conn->real_escape_string($permitPlace) : '';
            $permitExpDateEsc = $permitExpDate ? $conn->real_escape_string($permitExpDate) : '';
            $itemsJsonEsc = $conn->real_escape_string($itemsJson);
            $cashMemoTextEsc = $conn->real_escape_string($cashMemoText);
            
            $cashMemoValues[] = "('$billNoEsc', $comp_id, '$printDateEsc', $user_id, '$licenseNumberEsc', '$shopNameEsc', '$shopAddressEsc', '$billDateEsc', '$customerNameEsc', '$permitNoEsc', '$permitPlaceEsc', '$permitExpDateEsc', '$itemsJsonEsc', $totalAmount, '$cashMemoTextEsc')";
            $cash_memo_count++;
        }
        
        // Bulk insert in chunks
        if (!empty($cashMemoValues)) {
            $cashMemoChunks = array_chunk($cashMemoValues, 500);
            foreach ($cashMemoChunks as $chunk) {
                $cashMemoSql = "INSERT IGNORE INTO tbl_cash_memo_prints 
                    (bill_no, comp_id, print_date, printed_by, license_number, shop_name, shop_address, 
                     bill_date, customer_name, permit_no, permit_place, permit_exp_date, items_json, total_amount, cash_memo_text) 
                    VALUES " . implode(',', $chunk);
                $conn->query($cashMemoSql);
            }
        }
    } else {
        $cash_memo_count = 0;
    }
    
    // ============================================================================
    // STEP 13: COMMIT AND FINALIZE
    // ============================================================================
    $conn->commit();
    
    $execution_time = round(microtime(true) - $start_time, 2);
    
    // Calculate total amount
    $total_amount = array_sum(array_column($bills, 'total_amount'));
    
    $_SESSION['bill_progress']['status'] = 'completed';
    $_SESSION['bill_progress']['percentage'] = 100;
    $_SESSION['bill_progress']['message'] = "Completed! Generated " . count($bills) . " bills with $cash_memo_count cash memos in {$execution_time} seconds";
    
    $response['success'] = true;
    $response['message'] = $_SESSION['bill_progress']['message'];
    $response['total_amount'] = number_format($total_amount, 2);
    $response['bill_count'] = count($bills);
    $response['cash_memo_count'] = $cash_memo_count;
    $response['execution_time'] = $execution_time;
    $response['bills'] = $_SESSION['bill_progress']['bills_generated'];
    
    // Re-enable constraints
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
    
} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = "Error: " . $e->getMessage();
    
    $_SESSION['bill_progress']['status'] = 'error';
    $_SESSION['bill_progress']['message'] = "Error: " . $e->getMessage();
    
    // Re-enable constraints
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
}

// Keep progress in session for 5 minutes
$_SESSION['bill_progress']['end_time'] = time();
$_SESSION['bill_progress']['expires'] = time() + 300;

echo json_encode($response);
exit;

// Helper function for batch bill number generation
function getNextBillNumberBatch($conn, $comp_id) {
    $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill 
              FROM tblsaleheader WHERE COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return ($row['max_bill'] ?? 0) + 1;
}
?>
