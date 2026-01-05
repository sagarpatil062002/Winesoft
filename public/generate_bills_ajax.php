<?php
// generate_bills_ajax.php
session_start();

// Ensure no output before headers
ob_start();

// Include required files
require_once "../config/db.php";
require_once "volume_limit_utils.php";
require_once "cash_memo_functions.php";
require_once "drydays_functions.php";

// Set headers for JSON response
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Suppress any output
ob_end_clean();

// Function to clean output
function cleanOutput() {
    while (ob_get_level()) {
        ob_end_clean();
    }
}

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired. Please login again.', 'progress' => 0]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_bills_ajax'])) {
    $response = ['success' => false, 'message' => '', 'progress' => 0];
    
    try {
        // Get POST data
        $start_date = $_POST['start_date'] ?? '';
        $end_date = $_POST['end_date'] ?? '';
        $mode = $_POST['mode'] ?? 'F';
        $comp_id = $_SESSION['CompID'];
        $user_id = $_SESSION['user_id'];
        $fin_year_id = $_SESSION['FIN_YEAR_ID'];
        
        // Validate input
        if (empty($start_date) || empty($end_date)) {
            throw new Exception("Start date and end date are required.");
        }
        
        // Send initial progress
        echo json_encode(['success' => true, 'message' => 'Starting bill generation...', 'progress' => 5]);
        flush();
        
        // Check if we have items with quantities
        if (!isset($_SESSION['sale_quantities']) || empty($_SESSION['sale_quantities'])) {
            throw new Exception("No quantities entered for any items.");
        }
        
        // ============================================================================
        // REUSE FUNCTIONS FROM sale_for_date_range.php
        // ============================================================================
        
        // Function to get the correct daily stock table name
        function getDailyStockTableNameForAjax($conn, $date, $comp_id) {
            $current_month = date('Y-m');
            $date_month = date('Y-m', strtotime($date));
            
            if ($date_month === $current_month) {
                return "tbldailystock_" . $comp_id;
            } else {
                $month_suffix = date('m_y', strtotime($date));
                return "tbldailystock_" . $comp_id . "_" . $month_suffix;
            }
        }
        
        // Function to get dates between
        function getDatesBetweenAjax($start_date, $end_date) {
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
        function getItemLatestSaleDateAjax($conn, $item_code, $comp_id) {
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
        
        // Function to check item's date availability
        function getItemDateAvailabilityAjax($conn, $item_code, $start_date, $end_date, $comp_id) {
            $all_dates = getDatesBetweenAjax($start_date, $end_date);
            $available_dates = [];
            $blocked_dates = [];
            
            $latest_sale_date = getItemLatestSaleDateAjax($conn, $item_code, $comp_id);
            
            foreach ($all_dates as $date) {
                $is_available = true;
                $block_reason = "";
                
                if ($latest_sale_date && $date <= $latest_sale_date) {
                    $is_available = false;
                    $block_reason = "Sale already recorded";
                }
                
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
        function distributeSalesAcrossAvailableDatesAjax($total_qty, $date_array, $available_dates) {
            if ($total_qty <= 0) {
                return array_fill_keys($date_array, 0);
            }
            
            $available_dates_in_range = array_intersect($date_array, array_keys($available_dates));
            
            if (empty($available_dates_in_range)) {
                return array_fill_keys($date_array, 0);
            }
            
            $available_count = count($available_dates_in_range);
            $base_qty = floor($total_qty / $available_count);
            $remainder = $total_qty % $available_count;
            
            $distribution = array_fill_keys($date_array, 0);
            
            foreach ($available_dates_in_range as $date) {
                $distribution[$date] = $base_qty;
            }
            
            $available_dates_list = array_values($available_dates_in_range);
            for ($i = 0; $i < $remainder; $i++) {
                $distribution[$available_dates_list[$i]]++;
            }
            
            $available_dates_keys = array_keys($available_dates_in_range);
            shuffle($available_dates_keys);
            
            $shuffled_distribution = array_fill_keys($date_array, 0);
            $available_values = [];
            
            foreach ($available_dates_keys as $date) {
                $available_values[] = $distribution[$date];
            }
            
            foreach ($available_dates_keys as $index => $date) {
                $shuffled_distribution[$date] = $available_values[$index];
            }
            
            return $shuffled_distribution;
        }
        
        // Function to get next bill number
        function getNextBillNumberAjax($conn, $comp_id) {
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
                return $next_bill;
                
            } catch (Exception $e) {
                $conn->rollback();
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
        
        // Function to update daily stock (simplified version)
        function updateDailyStockAjax($conn, $item_code, $sale_date, $qty, $comp_id) {
            $current_month = date('Y-m');
            $sale_month = date('Y-m', strtotime($sale_date));
            
            if ($sale_month === $current_month) {
                $sale_daily_stock_table = "tbldailystock_" . $comp_id;
            } else {
                $sale_month_year = date('m_y', strtotime($sale_date));
                $sale_daily_stock_table = "tbldailystock_" . $comp_id . "_" . $sale_month_year;
            }
            
            $current_daily_stock_table = "tbldailystock_" . $comp_id;
            
            $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
            $sales_column = "DAY_{$day_num}_SALES";
            $closing_column = "DAY_{$day_num}_CLOSING";
            $opening_column = "DAY_{$day_num}_OPEN";
            $purchase_column = "DAY_{$day_num}_PURCHASE";
            
            $month_year_full = date('Y-m', strtotime($sale_date));
            
            // Check if table exists
            $check_table_query = "SHOW TABLES LIKE '$sale_daily_stock_table'";
            $table_result = $conn->query($check_table_query);
            
            if ($table_result->num_rows == 0) {
                throw new Exception("Stock table '$sale_daily_stock_table' not found for item $item_code on date $sale_date");
            }
            
            // Get current values
            $check_query = "SELECT $closing_column, $opening_column, $purchase_column, $sales_column 
                            FROM $sale_daily_stock_table 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("ss", $month_year_full, $item_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows == 0) {
                $check_stmt->close();
                throw new Exception("No stock record found for item $item_code in table $sale_daily_stock_table for month $month_year_full");
            }
            
            $current_values = $check_result->fetch_assoc();
            $check_stmt->close();
            
            $current_closing = $current_values[$closing_column] ?? 0;
            $current_opening = $current_values[$opening_column] ?? 0;
            $current_purchase = $current_values[$purchase_column] ?? 0;
            $current_sales = $current_values[$sales_column] ?? 0;
            
            if ($current_closing < $qty) {
                throw new Exception("Insufficient closing stock for item $item_code on $sale_date. Available: $current_closing, Requested: $qty");
            }
            
            $new_sales = $current_sales + $qty;
            $new_closing = $current_opening + $current_purchase - $new_sales;
            
            // Update sale date record
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
                throw new Exception("Failed to update daily stock for item $item_code on $sale_date");
            }
            $update_stmt->close();
            
            // Cascade to next day
            $next_day = intval($day_num) + 1;
            if ($next_day <= 31) {
                $next_day_num = sprintf('%02d', $next_day);
                $next_opening_column = "DAY_{$next_day_num}_OPEN";
                
                $check_next_day_query = "SHOW COLUMNS FROM $sale_daily_stock_table LIKE '$next_opening_column'";
                $next_day_result = $conn->query($check_next_day_query);
                
                if ($next_day_result->num_rows > 0) {
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
            
            // If sale is in archive month, update current month
            if ($sale_month < $current_month) {
                $check_current_table = "SHOW TABLES LIKE '$current_daily_stock_table'";
                $current_table_result = $conn->query($check_current_table);
                
                if ($current_table_result->num_rows > 0) {
                    $current_stk_month = $current_month;
                    
                    $check_current_item = "SELECT COUNT(*) as count FROM $current_daily_stock_table 
                                          WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                    $check_current_stmt = $conn->prepare($check_current_item);
                    $check_current_stmt->bind_param("ss", $item_code, $current_stk_month);
                    $check_current_stmt->execute();
                    $check_current_result = $check_current_stmt->get_result();
                    $item_exists = $check_current_result->fetch_assoc()['count'] > 0;
                    $check_current_stmt->close();
                    
                    if ($item_exists) {
                        // Adjust current month's opening stock
                        $update_current_opening = "UPDATE $current_daily_stock_table 
                                                  SET DAY_01_OPEN = DAY_01_OPEN - ?,
                                                      LAST_UPDATED = CURRENT_TIMESTAMP 
                                                  WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                        $update_current_stmt = $conn->prepare($update_current_opening);
                        $update_current_stmt->bind_param("dss", $qty, $item_code, $current_stk_month);
                        $update_current_stmt->execute();
                        $update_current_stmt->close();
                    }
                }
            }
            
            return true;
        }
        
        // Function to update item stock
        function updateItemStockAjax($conn, $item_code, $qty, $current_stock_column, $opening_stock_column, $fin_year_id) {
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
        
        // Send progress update
        echo json_encode(['success' => true, 'message' => 'Validating data...', 'progress' => 10]);
        flush();
        
        // Get all dates in range
        $all_dates = getDatesBetweenAjax($start_date, $end_date);
        $date_array = $all_dates;
        $days_count = count($all_dates);
        
        // Filter items with quantities > 0
        $items_to_process = [];
        foreach ($_SESSION['sale_quantities'] as $item_code => $total_qty) {
            if ($total_qty > 0) {
                // Check date availability
                $availability = getItemDateAvailabilityAjax($conn, $item_code, $start_date, $end_date, $comp_id);
                if ($availability['available_count'] > 0) {
                    $items_to_process[$item_code] = $total_qty;
                }
            }
        }
        
        if (empty($items_to_process)) {
            throw new Exception("No items with valid dates available for processing.");
        }
        
        // Send progress update
        echo json_encode(['success' => true, 'message' => 'Processing ' . count($items_to_process) . ' items...', 'progress' => 20]);
        flush();
        
        // Start transaction
        $conn->begin_transaction();
        
        $total_amount = 0;
        $items_data = [];
        $daily_sales_data = [];
        $item_count = 0;
        $total_items_count = count($items_to_process);
        
        // Process each item
        foreach ($items_to_process as $item_code => $total_qty) {
            $item_count++;
            $progress = 20 + round(($item_count / $total_items_count) * 20);
            
            // Send progress update
            echo json_encode(['success' => true, 'message' => "Processing item $item_count of $total_items_count...", 'progress' => $progress]);
            flush();
            
            // Get item details
            $item_query = "SELECT DETAILS, RPRICE, LIQ_FLAG FROM tblitemmaster WHERE CODE = ?";
            $item_stmt = $conn->prepare($item_query);
            $item_stmt->bind_param("s", $item_code);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $item = $item_result->fetch_assoc();
            $item_stmt->close();
            
            if (!$item) {
                continue;
            }
            
            // Get date availability
            $availability = getItemDateAvailabilityAjax($conn, $item_code, $start_date, $end_date, $comp_id);
            
            // Distribute sales across available dates
            $daily_sales = distributeSalesAcrossAvailableDatesAjax($total_qty, $all_dates, $availability['available_dates']);
            $daily_sales_data[$item_code] = $daily_sales;
            
            $items_data[$item_code] = [
                'name' => $item['DETAILS'],
                'rate' => $item['RPRICE'],
                'total_qty' => $total_qty,
                'mode' => $item['LIQ_FLAG']
            ];
        }
        
        // Send progress update
        echo json_encode(['success' => true, 'message' => 'Generating bills...', 'progress' => 45]);
        flush();
        
        // Generate bills (simplified version - you can use your generateBillsWithLimits function here)
        $bills = [];
        foreach ($all_dates as $date) {
            $bill_items = [];
            $bill_total = 0;
            
            $has_sales_on_date = false;
            foreach ($items_data as $item_code => $item_data) {
                if (isset($daily_sales_data[$item_code][$date]) && $daily_sales_data[$item_code][$date] > 0) {
                    $has_sales_on_date = true;
                    break;
                }
            }
            
            if (!$has_sales_on_date) {
                continue;
            }
            
            foreach ($items_data as $item_code => $item_data) {
                $qty_on_date = isset($daily_sales_data[$item_code][$date]) ? $daily_sales_data[$item_code][$date] : 0;
                
                if ($qty_on_date > 0) {
                    $amount = $qty_on_date * $item_data['rate'];
                    $bill_total += $amount;
                    
                    $bill_items[] = [
                        'code' => $item_code,
                        'name' => $item_data['name'],
                        'qty' => $qty_on_date,
                        'rate' => $item_data['rate'],
                        'amount' => $amount
                    ];
                }
            }
            
            if (!empty($bill_items)) {
                $bills[] = [
                    'bill_date' => $date,
                    'items' => $bill_items,
                    'total_amount' => $bill_total,
                    'mode' => $mode,
                    'comp_id' => $comp_id,
                    'user_id' => $user_id
                ];
            }
        }
        
        if (empty($bills)) {
            $conn->rollback();
            throw new Exception("No bills generated. Check date availability.");
        }
        
        // Send progress update
        echo json_encode(['success' => true, 'message' => "Creating " . count($bills) . " bills...", 'progress' => 60]);
        flush();
        
        $current_stock_column = "Current_Stock" . $comp_id;
        $opening_stock_column = "Opening_Stock" . $comp_id;
        
        $next_bill_number = getNextBillNumberAjax($conn, $comp_id);
        
        // Sort bills chronologically
        usort($bills, function($a, $b) {
            return strtotime($a['bill_date']) - strtotime($b['bill_date']);
        });
        
        $bill_count = 0;
        $total_bills = count($bills);
        $cash_memos_generated = 0;
        $cash_memo_errors = [];
        
        // Process each bill
        foreach ($bills as $bill) {
            $bill_count++;
            $progress = 60 + round(($bill_count / $total_bills) * 35);
            
            // Send progress update
            echo json_encode(['success' => true, 'message' => "Creating bill $bill_count of $total_bills...", 'progress' => $progress]);
            flush();
            
            $padded_bill_no = "BL" . str_pad($next_bill_number++, 4, '0', STR_PAD_LEFT);
            
            // Insert sale header
            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                             VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
            $header_stmt = $conn->prepare($header_query);
            $header_stmt->bind_param("ssddssi", $padded_bill_no, $bill['bill_date'], $bill['total_amount'], 
                                    $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
            $header_stmt->execute();
            $header_stmt->close();
            
            // Insert sale details
            foreach ($bill['items'] as $item) {
                $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                $detail_stmt = $conn->prepare($detail_query);
                $detail_stmt->bind_param("ssddssi", $padded_bill_no, $item['code'], $item['qty'], 
                                        $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                $detail_stmt->execute();
                $detail_stmt->close();
                
                // Update stock
                updateItemStockAjax($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
                
                // Update daily stock
                updateDailyStockAjax($conn, $item['code'], $bill['bill_date'], $item['qty'], $comp_id);
            }
            
            $total_amount += $bill['total_amount'];
            
            // Generate cash memo (optional)
            try {
                if (function_exists('autoGenerateCashMemoForBill')) {
                    if (autoGenerateCashMemoForBill($conn, $padded_bill_no, $comp_id, $user_id)) {
                        $cash_memos_generated++;
                    } else {
                        $cash_memo_errors[] = $padded_bill_no;
                    }
                }
            } catch (Exception $e) {
                // Continue even if cash memo generation fails
                $cash_memo_errors[] = $padded_bill_no;
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Clear session quantities
        unset($_SESSION['sale_quantities']);
        
        // Build success message
        $success_message = "Sales distributed successfully! Generated " . count($bills) . " bills. Total Amount: ₹" . number_format($total_amount, 2);
        
        if ($cash_memos_generated > 0) {
            $success_message .= " | Cash Memos Generated: " . $cash_memos_generated;
        }
        
        if (!empty($cash_memo_errors)) {
            $success_message .= " | Failed to generate cash memos for " . count($cash_memo_errors) . " bills";
        }
        
        // Send final success response
        echo json_encode([
            'success' => true, 
            'message' => $success_message, 
            'progress' => 100, 
            'redirect' => 'retail_sale.php?success=' . urlencode($success_message)
        ]);
        
        exit;
        
    } catch (Exception $e) {
        if (isset($conn) && method_exists($conn, 'rollback')) {
            $conn->rollback();
        }
        
        echo json_encode(['success' => false, 'message' => $e->getMessage(), 'progress' => 0]);
        exit;
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method', 'progress' => 0]);
    exit;
}
?>