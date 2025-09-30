<?php
session_start();
include_once "../config/db.php";

// Logging function
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

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

logMessage("=== PROCESS SALES STARTED ===");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sales'])) {
    $comp_id = $_SESSION['CompID'];
    $user_id = $_SESSION['user_id'];
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $mode = $_POST['mode'];
    
    logMessage("Processing sales for user $user_id, company $comp_id, date range: $start_date to $end_date");

    // Get quantities from ALL sources to ensure we have everything
    $all_quantities = $_SESSION['sale_quantities'] ?? [];
    
    // Merge with form data
    if (isset($_POST['all_sale_qty'])) {
        $all_quantities = array_merge($all_quantities, $_POST['all_sale_qty']);
    }
    if (isset($_POST['sale_qty'])) {
        $all_quantities = array_merge($all_quantities, $_POST['sale_qty']);
    }
    
    // Filter out zero quantities
    $quantities_to_process = array_filter($all_quantities, function($qty) {
        return $qty > 0;
    });
    
    logMessage("Items with quantities > 0: " . count($quantities_to_process));

    if (empty($quantities_to_process)) {
        $error_message = "No items with quantities found to generate bills.";
        logMessage($error_message, 'WARNING');
        header("Location: sale_for_date_range.php?error=" . urlencode($error_message));
        exit;
    }

    // Include volume limit utilities
    include_once "volume_limit_utils.php";

    try {
        // Process the sales
        $result = processSales($conn, $quantities_to_process, $start_date, $end_date, $mode, $comp_id, $user_id, $fin_year_id);
        
        if ($result['success']) {
            // Clear session quantities after success
            unset($_SESSION['sale_quantities']);
            
            logMessage($result['message']);
            header("Location: retail_sale.php?success=" . urlencode($result['message']));
            exit;
        } else {
            throw new Exception($result['message']);
        }
        
    } catch (Exception $e) {
        $error_message = "Error processing sales: " . $e->getMessage();
        logMessage($error_message, 'ERROR');
        header("Location: sale_for_date_range.php?error=" . urlencode($error_message));
        exit;
    }
} else {
    header("Location: sale_for_date_range.php");
    exit;
}

/**
 * Main function to process sales
 */
function processSales($conn, $quantities, $start_date, $end_date, $mode, $comp_id, $user_id, $fin_year_id) {
    logMessage("Starting sales processing with " . count($quantities) . " items");
    
    // Get stock column names
    $current_stock_column = "Current_Stock" . $comp_id;
    $opening_stock_column = "Opening_Stock" . $comp_id;
    $daily_stock_table = "tbldailystock_" . $comp_id;
    
    // Get ALL items with quantities from database
    $item_codes = array_keys($quantities);
    $placeholders = str_repeat('?,', count($item_codes) - 1) . '?';
    
    $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                               COALESCE(st.$current_stock_column, 0) as CURRENT_STOCK
                        FROM tblitemmaster im
                        LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE 
                        WHERE im.CODE IN ($placeholders)";
    
    $all_stmt = $conn->prepare($all_items_query);
    $all_stmt->bind_param(str_repeat('s', count($item_codes)), ...$item_codes);
    $all_stmt->execute();
    $all_result = $all_stmt->get_result();
    $items_from_db = $all_result->fetch_all(MYSQLI_ASSOC);
    $all_stmt->close();
    
    logMessage("Fetched " . count($items_from_db) . " items from database");

    // Enhanced stock validation
    $stock_errors = [];
    $items_data = [];
    
    foreach ($items_from_db as $item) {
        $item_code = $item['CODE'];
        $total_qty = $quantities[$item_code] ?? 0;
        $current_stock = $item['CURRENT_STOCK'];
        
        // Skip items with zero quantity
        if ($total_qty <= 0) continue;
        
        // Stock validation
        if ($total_qty > $current_stock) {
            $stock_errors[] = "Item {$item_code}: Available stock {$current_stock}, Requested {$total_qty}";
        } else {
            // Store item data
            $items_data[$item_code] = [
                'name' => $item['DETAILS'],
                'rate' => $item['RPRICE'],
                'total_qty' => $total_qty,
                'details2' => $item['DETAILS2'],
                'class' => $item['CLASS'],
                'current_stock' => $current_stock
            ];
        }
    }
    
    // If stock errors found, stop processing
    if (!empty($stock_errors)) {
        $error_message = "Stock validation failed:<br>" . implode("<br>", array_slice($stock_errors, 0, 5));
        if (count($stock_errors) > 5) {
            $error_message .= "<br>... and " . (count($stock_errors) - 5) . " more errors";
        }
        return ['success' => false, 'message' => $error_message];
    }
    
    if (empty($items_data)) {
        return ['success' => false, 'message' => "No valid items with quantities found to generate bills."];
    }
    
    logMessage("Starting transaction with " . count($items_data) . " valid items");

    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Create date range array
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
        
        logMessage("Date range spans $days_count days");
        
        // Generate daily sales distribution for each item
        $daily_sales_data = [];
        foreach ($items_data as $item_code => $item_data) {
            $daily_sales = distributeSales($item_data['total_qty'], $days_count);
            $daily_sales_data[$item_code] = $daily_sales;
        }
        
        // Generate bills with volume limits
        $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
        
        logMessage("Generated " . count($bills) . " bills with volume limits");
        
        $total_amount = 0;
        $bill_count = 0;
        
        // Process each bill
        foreach ($bills as $bill) {
            $bill_count++;
            
            // Insert sale header
            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                             VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
            $header_stmt = $conn->prepare($header_query);
            $header_stmt->bind_param("ssddssi", $bill['bill_no'], $bill['bill_date'], $bill['total_amount'], 
                                    $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
            if (!$header_stmt->execute()) {
                throw new Exception("Error inserting sale header: " . $header_stmt->error);
            }
            $header_stmt->close();
            
            // Insert sale details for each item in the bill
            foreach ($bill['items'] as $item) {
                $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                $detail_stmt = $conn->prepare($detail_query);
                $detail_stmt->bind_param("ssddssi", $bill['bill_no'], $item['code'], $item['qty'], 
                                        $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                if (!$detail_stmt->execute()) {
                    throw new Exception("Error inserting sale details: " . $detail_stmt->error);
                }
                $detail_stmt->close();
                
                // Update stock
                updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
                
                // Update daily stock
                updateDailyStock($conn, $daily_stock_table, $item['code'], $bill['bill_date'], $item['qty'], $comp_id);
            }
            
            $total_amount += $bill['total_amount'];
            logMessage("Processed bill {$bill['bill_no']} for date {$bill['bill_date']} with amount: {$bill['total_amount']}");
        }
        
        // Commit transaction
        $conn->commit();
        
        $success_message = "Sales distributed successfully! Generated " . $bill_count . " bills. Total Amount: ₹" . number_format($total_amount, 2);
        
        return [
            'success' => true, 
            'message' => $success_message,
            'bill_count' => $bill_count,
            'total_amount' => $total_amount
        ];
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        throw $e;
    }
}

// Function to distribute sales uniformly
function distributeSales($total_qty, $days_count) {
    if ($total_qty <= 0 || $days_count <= 0) return array_fill(0, $days_count, 0);
    
    $base_qty = floor($total_qty / $days_count);
    $remainder = $total_qty % $days_count;
    
    $daily_sales = array_fill(0, $days_count, $base_qty);
    
    // Distribute remainder evenly across days
    for ($i = 0; $i < $remainder; $i++) {
        $daily_sales[$i]++;
    }
    
    // Shuffle the distribution to make it look more natural
    shuffle($daily_sales);
    
    return $daily_sales;
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

// Function to update daily stock table
function updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id) {
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $sales_column = "DAY_{$day_num}_SALES";
    $closing_column = "DAY_{$day_num}_CLOSING";
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    
    $month_year = date('Y-m', strtotime($sale_date));
    
    // Check if record exists for this month and item
    $check_query = "SELECT COUNT(*) as count FROM $daily_stock_table 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $month_year, $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Get current values to calculate closing properly
        $select_query = "SELECT $opening_column, $purchase_column, $sales_column 
                         FROM $daily_stock_table 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $select_stmt = $conn->prepare($select_query);
        $select_stmt->bind_param("ss", $month_year, $item_code);
        $select_stmt->execute();
        $select_result = $select_stmt->get_result();
        $current_values = $select_result->fetch_assoc();
        $select_stmt->close();
        
        $opening = $current_values[$opening_column] ?? 0;
        $purchase = $current_values[$purchase_column] ?? 0;
        $current_sales = $current_values[$sales_column] ?? 0;
        
        // Calculate new sales and closing
        $new_sales = $current_sales + $qty;
        $new_closing = $opening + $purchase - $new_sales;
        
        // Update existing record with correct closing calculation
        $update_query = "UPDATE $daily_stock_table 
                         SET $sales_column = ?, 
                             $closing_column = ?,
                             LAST_UPDATED = CURRENT_TIMESTAMP 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ddss", $new_sales, $new_closing, $month_year, $item_code);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // For new records, opening and purchase are typically 0 unless specified otherwise
        $closing = 0 - $qty;
        
        // Create new record
        $insert_query = "INSERT INTO $daily_stock_table 
                         (STK_MONTH, ITEM_CODE, LIQ_FLAG, $opening_column, $purchase_column, $sales_column, $closing_column) 
                         VALUES (?, ?, 'F', 0, 0, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssdd", $month_year, $item_code, $qty, $closing);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
}
?>