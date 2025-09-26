<?php
// generate_bills.php
session_start();

include_once "../config/db.php";

// Include volume limit utilities
include_once "volume_limit_utils.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_bills'])) {
    $response = ['success' => false, 'message' => '', 'total_amount' => 0, 'bill_count' => 0];
    
    try {
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $mode = $_POST['mode'];
        $comp_id = $_SESSION['CompID'];
        $user_id = $_SESSION['user_id'];
        $fin_year_id = $_SESSION['FIN_YEAR_ID'];
        
        // Get items data
        $items = $_POST['items'];
        
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
        
        // ========== UPDATED: Robust Sequential Bill Number Generation ==========
        function getNextBillNumber($conn, $comp_id) {
            // Create sequence table if it doesn't exist
            $createTable = "CREATE TABLE IF NOT EXISTS tbl_bill_sequence (
                comp_id INT PRIMARY KEY,
                last_bill_number INT DEFAULT 0,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $conn->query($createTable);
            
            // Use transaction to ensure atomicity
            $conn->begin_transaction();
            
            try {
                // Get or create sequence for this company
                $selectSql = "SELECT last_bill_number FROM tbl_bill_sequence WHERE comp_id = ? FOR UPDATE";
                $selectStmt = $conn->prepare($selectSql);
                $selectStmt->bind_param("i", $comp_id);
                $selectStmt->execute();
                $result = $selectStmt->get_result();
                
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $nextNumber = $row['last_bill_number'] + 1;
                    
                    // Update sequence
                    $updateSql = "UPDATE tbl_bill_sequence SET last_bill_number = ? WHERE comp_id = ?";
                    $updateStmt = $conn->prepare($updateSql);
                    $updateStmt->bind_param("ii", $nextNumber, $comp_id);
                    $updateStmt->execute();
                    $updateStmt->close();
                } else {
                    $nextNumber = 1;
                    
                    // Insert new sequence
                    $insertSql = "INSERT INTO tbl_bill_sequence (comp_id, last_bill_number) VALUES (?, ?)";
                    $insertStmt = $conn->prepare($insertSql);
                    $insertStmt->bind_param("ii", $comp_id, $nextNumber);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
                
                $selectStmt->close();
                $conn->commit();
                
                return 'BL' . $nextNumber;
                
            } catch (Exception $e) {
                $conn->rollback();
                // Fallback to the original method if sequence table fails
                return getNextBillNumberFallback($conn, $comp_id);
            }
        }
        
        // Fallback method using the original approach
        function getNextBillNumberFallback($conn, $comp_id) {
            // Get the highest existing bill number numerically
            $sql = "SELECT BILL_NO FROM tblsaleheader 
                    WHERE COMP_ID = ? 
                    ORDER BY LENGTH(BILL_NO) DESC, BILL_NO DESC 
                    LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $comp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $nextNumber = 1; // Default starting number
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $lastBillNo = $row['BILL_NO'];
                
                // Extract numeric part and increment
                if (preg_match('/BL(\d+)/', $lastBillNo, $matches)) {
                    $nextNumber = intval($matches[1]) + 1;
                }
            }
            
            // Safety check: Ensure bill number doesn't exist
            $billExists = true;
            $attempts = 0;
            
            while ($billExists && $attempts < 10) {
                $newBillNo = 'BL' . $nextNumber;
                
                // Check if this bill number already exists
                $checkSql = "SELECT COUNT(*) as count FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
                $checkStmt = $conn->prepare($checkSql);
                $checkStmt->bind_param("si", $newBillNo, $comp_id);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $checkRow = $checkResult->fetch_assoc();
                
                if ($checkRow['count'] == 0) {
                    $billExists = false;
                } else {
                    $nextNumber++; // Try next number
                    $attempts++;
                }
                $checkStmt->close();
            }
            
            $stmt->close();
            return 'BL' . $nextNumber;
        }
        // ========== END: Robust Sequential Bill Number Generation ==========

        // Function to update daily stock table (UNCHANGED)
        function updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id) {
            $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
            $sales_column = "DAY_{$day_num}_SALES";
            $closing_column = "DAY_{$day_num}_CLOSING";
            $opening_column = "DAY_{$day_num}_OPEN";
            $purchase_column = "DAY_{$day_num}_PURCHASE";
            
            $month_year = date('Y-m', strtotime($sale_date));
            
            // Check if record exists
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
                
                // Update next day's opening stock if it exists
                $next_day = intval($day_num) + 1;
                if ($next_day <= 31) {
                    $next_day_num = sprintf('%02d', $next_day);
                    $next_opening_column = "DAY_{$next_day_num}_OPEN";
                    
                    // Check if next day exists in the table
                    $check_next_day_query = "SHOW COLUMNS FROM $daily_stock_table LIKE '$next_opening_column'";
                    $next_day_result = $conn->query($check_next_day_query);
                    
                    if ($next_day_result->num_rows > 0) {
                        // Update next day's opening to match current day's closing
                        $update_next_query = "UPDATE $daily_stock_table 
                                             SET $next_opening_column = ?,
                                                 LAST_UPDATED = CURRENT_TIMESTAMP 
                                             WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                        $update_next_stmt = $conn->prepare($update_next_query);
                        $update_next_stmt->bind_param("dss", $new_closing, $month_year, $item_code);
                        $update_next_stmt->execute();
                        $update_next_stmt->close();
                    }
                }
            } else {
                // For new records, opening and purchase are typically 0 unless specified otherwise
                $closing = 0 - $qty; // Since opening and purchase are 0
                
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
        
        // Function to update item stock (UNCHANGED)
        function updateItemStock($conn, $item_code, $qty, $current_stock_column, $opening_stock_column, $fin_year_id) {
            // Check if record exists
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
        
        // Start transaction
        $conn->begin_transaction();
        
        $total_amount = 0;
        $items_data = [];
        $daily_sales_data = [];
        
        foreach ($items as $item_code => $total_qty) {
            $total_qty = intval($total_qty);
            
            if ($total_qty > 0) {
                $item_query = "SELECT DETAILS, RPRICE FROM tblitemmaster WHERE CODE = ?";
                $item_stmt = $conn->prepare($item_query);
                $item_stmt->bind_param("s", $item_code);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item = $item_result->fetch_assoc();
                $item_stmt->close();
                
                if (!$item) {
                    continue;
                }
                
                $daily_sales = distributeSales($total_qty, $days_count);
                $daily_sales_data[$item_code] = $daily_sales;
                
                $items_data[$item_code] = [
                    'name' => $item['DETAILS'],
                    'rate' => $item['RPRICE'],
                    'total_qty' => $total_qty
                ];
            }
        }
        
        $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
        
        $current_stock_column = "Current_Stock" . $comp_id;
        $opening_stock_column = "Opening_Stock" . $comp_id;
        $daily_stock_table = "tbldailystock_" . $comp_id;
        
        // ========== UPDATED: Sequential Bill Number Assignment ==========
        $generated_bills = [];
        
        foreach ($bills as $index => $bill) {
            // Generate sequential bill number for each bill
            $sequential_bill_no = getNextBillNumber($conn, $comp_id);
            
            // Store bill info for reference
            $generated_bills[] = [
                'bill_no' => $sequential_bill_no,
                'bill_date' => $bill['bill_date'],
                'total_amount' => $bill['total_amount']
            ];
            
            // Insert sale header with sequential bill number
            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                             VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
            $header_stmt = $conn->prepare($header_query);
            $header_stmt->bind_param("ssddssi", $sequential_bill_no, $bill['bill_date'], $bill['total_amount'], 
                                    $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
            $header_stmt->execute();
            $header_stmt->close();
            
            // Insert sale details with the same sequential bill number
            foreach ($bill['items'] as $item) {
                $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                $detail_stmt = $conn->prepare($detail_query);
                $detail_stmt->bind_param("ssddssi", $sequential_bill_no, $item['code'], $item['qty'], 
                                        $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                $detail_stmt->execute();
                $detail_stmt->close();
                
                updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
                updateDailyStock($conn, $daily_stock_table, $item['code'], $bill['bill_date'], $item['qty'], $comp_id);
            }
            
            $total_amount += $bill['total_amount'];
            
            // Update the bill array with the new sequential number (for reference)
            $bills[$index]['bill_no'] = $sequential_bill_no;
        }
        // ========== END: Sequential Bill Number Assignment ==========
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = "Sales distributed successfully! Generated " . count($bills) . " bills with sequential numbering.";
        $response['total_amount'] = number_format($total_amount, 2);
        $response['bill_count'] = count($bills);
        $response['generated_bills'] = $generated_bills; // Include bill details in response
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = "Error updating sales: " . $e->getMessage();
        $response['message'] = $error_message;
    }
    
    echo json_encode($response);
    exit;
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}
?>