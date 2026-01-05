<?php
// generate_bills.php
session_start();

include_once "../config/db.php";

// Include volume limit utilities
include_once "volume_limit_utils.php";

// Include cash memo functions
include_once "cash_memo_functions.php";

// ============================================================================
// FUNCTION: GET CORRECT DAILY STOCK TABLE NAME FOR ARCHIVE MONTH SUPPORT
// ============================================================================
function getDailyStockTableName($conn, $date, $comp_id) {
    $current_month = date('Y-m'); // Current month in "YYYY-MM" format
    $date_month = date('Y-m', strtotime($date)); // Date month in "YYYY-MM" format
    
    if ($date_month === $current_month) {
        // Use current month table (no suffix)
        $table_name = "tbldailystock_" . $comp_id;
        return $table_name;
    } else {
        // Use archived month table (with suffix)
        $month_suffix = date('m_y', strtotime($date)); // e.g., "11_25" for November 2025
        $table_name = "tbldailystock_" . $comp_id . "_" . $month_suffix;
        return $table_name;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_bills'])) {
    $response = ['success' => false, 'message' => '', 'total_amount' => 0, 'bill_count' => 0];
    
    try {
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $mode = $_POST['mode'];
        $comp_id = $_SESSION['CompID'];
        $user_id = $_SESSION['user_id'];
        $fin_year_id = $_SESSION['FIN_YEAR_ID'];
        
        // Get items data - decode JSON
        $items = json_decode($_POST['items'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid items data: " . json_last_error_msg());
        }
        
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
        
        // ============================================================================
        // UPDATED BILL NUMBER GENERATION WITH ARCHIVE MONTH SUPPORT
        // ============================================================================
        function getNextBillNumberForGenerate($conn, $comp_id) {
            // Get the highest existing bill number numerically FOR THIS COMP_ID
            $sql = "SELECT BILL_NO FROM tblsaleheader 
                    WHERE COMP_ID = ? 
                    ORDER BY CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED) DESC 
                    LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $comp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $nextNumber = 1; // Default starting number for this company
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $lastBillNo = $row['BILL_NO'];
                
                // Extract numeric part and increment
                if (preg_match('/BL(\d+)/', $lastBillNo, $matches)) {
                    $nextNumber = intval($matches[1]) + 1;
                }
            }
            
            $stmt->close();
            return 'BL' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
        }
        
        // ============================================================================
        // UPDATED: DAILY STOCK UPDATE FUNCTION WITH ARCHIVE MONTH SUPPORT
        // (Consistent with sale_for_date_range.php)
        // ============================================================================
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
            $current_daily_stock_table = "tbldailystock_" . $comp_id;
            
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
                // Try to find the item with LIKE to see if there's a code mismatch
                $debug_query = "SELECT ITEM_CODE, STK_MONTH FROM $sale_daily_stock_table 
                               WHERE ITEM_CODE LIKE ? OR ITEM_CODE LIKE ? OR ITEM_CODE LIKE ?";
                $debug_stmt = $conn->prepare($debug_query);
                $like_code1 = $item_code;
                $like_code2 = "%" . substr($item_code, 0, -1) . "%"; // Try without last digit
                $like_code3 = "%" . substr($item_code, -6) . "%"; // Try last 6 digits
                $debug_stmt->bind_param("sss", $like_code1, $like_code2, $like_code3);
                $debug_stmt->execute();
                $debug_result = $debug_stmt->get_result();
                
                $found_items = [];
                while ($debug_row = $debug_result->fetch_assoc()) {
                    $found_items[] = $debug_row['ITEM_CODE'] . " (Month: " . $debug_row['STK_MONTH'] . ")";
                }
                $debug_stmt->close();
                
                $check_stmt->close();
                $found_items_str = !empty($found_items) ? implode(", ", $found_items) : "None";
                throw new Exception("No stock record found for item $item_code in table $sale_daily_stock_table for month $month_year_full. Found items: $found_items_str");
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
                            // Log warning but continue
                            error_log("Warning: Failed to update current table opening stock for item $item_code");
                        }
                        $update_current_stmt->close();
                        
                        // Recalculate closing balances for all days in current month
                        recalculateCurrentMonthStock($conn, $current_daily_stock_table, $item_code, $current_stk_month);
                    }
                }
            }
            
            return true;
        }
        
        // ============================================================================
        // HELPER FUNCTION: RECALCULATE CURRENT MONTH'S STOCK
        // ============================================================================
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
        
        // ============================================================================
        // FUNCTION TO UPDATE ITEM STOCK (UNCHANGED)
        // ============================================================================
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
        
        // Process ALL items sent from frontend
        foreach ($items as $item_code => $total_qty) {
            $total_qty = intval($total_qty);

            if ($total_qty > 0) {
                // Get item details including LIQ_FLAG
                $item_query = "SELECT CASE WHEN Print_Name != '' THEN Print_Name ELSE DETAILS END as display_name, DETAILS, RPRICE, LIQ_FLAG FROM tblitemmaster WHERE CODE = ?";
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

                // Store item with its actual LIQ_FLAG for volume limit processing
                $items_data[$item_code] = [
                    'name' => $item['display_name'],
                    'rate' => $item['RPRICE'],
                    'total_qty' => $total_qty,
                    'liq_flag' => $item['LIQ_FLAG'] // Include LIQ_FLAG for category-based processing
                ];
            }
        }
        
        // Generate bills with volume limits - Use 'ALL' mode to process all items
        $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, 'ALL', $comp_id, $user_id, $fin_year_id);
        
        $current_stock_column = "Current_Stock" . $comp_id;
        $opening_stock_column = "Opening_Stock" . $comp_id;
        
        // ============================================================================
        // SEQUENTIAL BILL NUMBER ASSIGNMENT WITH ARCHIVE MONTH SUPPORT
        // ============================================================================
        $generated_bills = [];
        $cash_memos_generated = 0;
        $cash_memo_errors = [];

        // Sort bills chronologically
        usort($bills, function($a, $b) {
            return strtotime($a['bill_date']) - strtotime($b['bill_date']);
        });

        foreach ($bills as $index => $bill) {
            // Generate sequential bill number for each bill with zero-padding
            $sequential_bill_no = getNextBillNumberForGenerate($conn, $comp_id);

            // Store bill info for reference
            $generated_bills[] = [
                'bill_no' => $sequential_bill_no,
                'bill_date' => $bill['bill_date'],
                'total_amount' => $bill['total_amount'],
                'month_type' => (date('Y-m', strtotime($bill['bill_date'])) == date('Y-m')) ? 'current' : 'archive'
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

                // Update item stock (unchanged)
                updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
                
                // UPDATED: Use archive month-aware daily stock update (consistent with sale_for_date_range.php)
                updateDailyStock($conn, $item['code'], $bill['bill_date'], $item['qty'], $comp_id);
            }

            $total_amount += $bill['total_amount'];

            // Update the bill array with the new sequential number (for reference)
            $bills[$index]['bill_no'] = $sequential_bill_no;

            // Generate cash memo for this bill
            if (autoGenerateCashMemoForBill($conn, $sequential_bill_no, $comp_id, $user_id)) {
                $cash_memos_generated++;
            } else {
                $cash_memo_errors[] = $sequential_bill_no;
            }
        }
        // ============================================================================
        // END: SEQUENTIAL BILL NUMBER ASSIGNMENT
        // ============================================================================
        
        // Commit transaction
        $conn->commit();
        
        // Calculate total volume and items processed
        $total_volume = 0;
        $total_items_processed = 0;
        foreach ($items_data as $item_code => $item_data) {
            $total_items_processed += $item_data['total_qty'];
            // Get item size for volume calculation
            $item_size = getItemSize($conn, $item_code, 'ALL');
            $total_volume += $item_size * $item_data['total_qty'];
        }

        $response['success'] = true;
        $response['message'] = "Sales distributed successfully! Generated " . count($bills) . " bills with sequential numbering.";
        $response['total_amount'] = number_format($total_amount, 2);
        $response['bill_count'] = count($bills);
        $response['total_items'] = $total_items_processed;
        $response['total_volume'] = $total_volume;
        $response['generated_bills'] = $generated_bills; // Include bill details in response
        $response['cash_memos_generated'] = $cash_memos_generated;
        $response['cash_memo_errors'] = $cash_memo_errors;
        $response['archive_month_support'] = true; // Flag indicating archive month support
        
        // Add info about archive months processed
        $archive_months = [];
        $current_months = [];
        foreach ($generated_bills as $bill) {
            $month = date('Y-m', strtotime($bill['bill_date']));
            if ($bill['month_type'] === 'archive') {
                $archive_months[$month] = ($archive_months[$month] ?? 0) + 1;
            } else {
                $current_months[$month] = ($current_months[$month] ?? 0) + 1;
            }
        }
        
        if (!empty($archive_months)) {
            $response['archive_months_processed'] = $archive_months;
            $response['message'] .= " Includes bills for archived months: " . implode(", ", array_keys($archive_months));
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = "Error updating sales: " . $e->getMessage();
        $response['message'] = $error_message;
        error_log("generate_bills.php Error: " . $e->getMessage());
    }
    
    echo json_encode($response);
    exit;
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}
?>