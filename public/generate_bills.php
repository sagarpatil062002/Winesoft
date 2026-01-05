<?php
// generate_bills.php
session_start();

include_once "../config/db.php";

// Include volume limit utilities
include_once "volume_limit_utils.php";

// Include cash memo functions
include_once "cash_memo_functions.php";

// Include sales functions for cascading
include_once "sales_functions.php";

// ============================================================================
// FUNCTION: GET CORRECT DAILY STOCK TABLE NAME FOR ARCHIVE MONTH SUPPORT
// (Consistent with sale_for_date_range.php)
// ============================================================================
function getDailyStockTableName($conn, $date, $comp_id) {
    $current_month = date('Y-m');
    $date_month = date('Y-m', strtotime($date));
    
    if ($date_month === $current_month) {
        // Use current month table (no suffix)
        $table_name = "tbldailystock_" . $comp_id;
        return $table_name;
    } else {
        // Use archived month table (with suffix)
        $month_suffix = date('m_y', strtotime($date));
        $table_name = "tbldailystock_" . $comp_id . "_" . $month_suffix;
        return $table_name;
    }
}

// ============================================================================
// UPDATED CASCADING FUNCTION (Same as in sale_for_date_range.php)
// ============================================================================
function updateCascadingDailyStock($conn, $item_code, $sale_date, $comp_id, $qty) {
    try {
        $current_date = date('Y-m-d');
        $sale_month = date('Y-m', strtotime($sale_date));
        $current_month = date('Y-m');
        
        // ============================================================================
        // STEP 1: UPDATE THE SALE DATE IN CORRECT TABLE
        // ============================================================================
        
        // Get the correct daily stock table for the sale date
        $sale_daily_stock_table = getDailyStockTableName($conn, $sale_date, $comp_id);
        
        // Check if table exists
        $check_table_query = "SHOW TABLES LIKE '$sale_daily_stock_table'";
        $table_result = $conn->query($check_table_query);
        
        if ($table_result->num_rows == 0) {
            throw new Exception("Stock table '$sale_daily_stock_table' not found for item $item_code on date $sale_date");
        }
        
        // Extract day number from sale date
        $sale_day_num = intval(date('d', strtotime($sale_date)));
        $sale_day_padded = str_pad($sale_day_num, 2, '0', STR_PAD_LEFT);
        
        // Get columns for sale day
        $sale_day_opening_column = "DAY_{$sale_day_padded}_OPEN";
        $sale_day_purchase_column = "DAY_{$sale_day_padded}_PURCHASE";
        $sale_day_sales_column = "DAY_{$sale_day_padded}_SALES";
        $sale_day_closing_column = "DAY_{$sale_day_padded}_CLOSING";
        
        $month_year_full = date('Y-m', strtotime($sale_date));
        
        // Check if record exists
        $check_query = "SELECT $sale_day_closing_column, $sale_day_opening_column, $sale_day_purchase_column, $sale_day_sales_column 
                        FROM $sale_daily_stock_table 
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ss", $month_year_full, $item_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows == 0) {
            throw new Exception("No stock record found for item $item_code in table $sale_daily_stock_table for month $month_year_full");
        }
        
        $current_values = $check_result->fetch_assoc();
        $check_stmt->close();
        
        $current_closing = $current_values[$sale_day_closing_column] ?? 0;
        $current_opening = $current_values[$sale_day_opening_column] ?? 0;
        $current_purchase = $current_values[$sale_day_purchase_column] ?? 0;
        $current_sales = $current_values[$sale_day_sales_column] ?? 0;
        
        // Validate closing stock is sufficient for the sale quantity
        if ($current_closing < $qty) {
            throw new Exception("Insufficient closing stock for item $item_code on $sale_date. Available: $current_closing, Requested: $qty");
        }
        
        // Calculate new sales and closing
        $new_sales = $current_sales + $qty;
        $new_closing = $current_opening + $current_purchase - $new_sales;
        
        // Update existing record
        $update_query = "UPDATE $sale_daily_stock_table 
                         SET $sale_day_sales_column = ?, 
                             $sale_day_closing_column = ?,
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
        
        // ============================================================================
        // STEP 2: CASCADE WITHIN THE SALE MONTH
        // ============================================================================
        
        // Start from next day and cascade through the rest of the month
        for ($day = $sale_day_num + 1; $day <= 31; $day++) {
            $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
            $prev_day_str = str_pad($day - 1, 2, '0', STR_PAD_LEFT);
            
            $opening_column = "DAY_{$day_str}_OPEN";
            $closing_column = "DAY_{$day_str}_CLOSING";
            $purchase_column = "DAY_{$day_str}_PURCHASE";
            $sales_column = "DAY_{$day_str}_SALES";
            
            // Check if this day exists in the table
            $check_day_query = "SHOW COLUMNS FROM $sale_daily_stock_table LIKE '$opening_column'";
            $day_result = $conn->query($check_day_query);
            
            if ($day_result->num_rows == 0) {
                break; // No more days in this month
            }
            
            // Set current day's opening to previous day's closing
            $prev_closing = getDayClosingStock($conn, $sale_daily_stock_table, $item_code, $month_year_full, $prev_day_str);
            
            $update_opening_query = "UPDATE $sale_daily_stock_table 
                                    SET $opening_column = ?,
                                        LAST_UPDATED = CURRENT_TIMESTAMP 
                                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_opening_stmt = $conn->prepare($update_opening_query);
            $update_opening_stmt->bind_param("dss", $prev_closing, $month_year_full, $item_code);
            $update_opening_stmt->execute();
            $update_opening_stmt->close();
            
            // Recalculate closing for this day
            $recalc_query = "UPDATE $sale_daily_stock_table 
                            SET $closing_column = $opening_column + $purchase_column - $sales_column,
                                LAST_UPDATED = CURRENT_TIMESTAMP 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $recalc_stmt = $conn->prepare($recalc_query);
            $recalc_stmt->bind_param("ss", $month_year_full, $item_code);
            $recalc_stmt->execute();
            $recalc_stmt->close();
        }
        
        // ============================================================================
        // STEP 3: CASCADE TO SUBSEQUENT MONTHS UP TO CURRENT DATE
        // ============================================================================
        
        if ($sale_month < $current_month) {
            // Get the last closing from sale month
            $last_closing = getLastDayClosingStock($conn, $sale_daily_stock_table, $item_code, $month_year_full);
            
            // Create list of months between sale month and current month
            $start = new DateTime($sale_month . '-01');
            $end = new DateTime($current_month . '-01');
            $interval = DateInterval::createFromDateString('1 month');
            $period = new DatePeriod($start, $interval, $end);
            
            $months_to_cascade = [];
            foreach ($period as $dt) {
                $month = $dt->format("Y-m");
                if ($month !== $sale_month) {
                    $months_to_cascade[] = $month;
                }
            }
            
            // Cascade through each month
            foreach ($months_to_cascade as $month) {
                $month_table = getDailyStockTableName($conn, $month . '-01', $comp_id);
                
                // Check if table exists
                $check_month_table = "SHOW TABLES LIKE '$month_table'";
                $month_table_result = $conn->query($check_month_table);
                
                if ($month_table_result->num_rows == 0) {
                    // Create table if it doesn't exist
                    createDailyStockTable($conn, $month_table, $comp_id);
                }
                
                // Check if record exists for this item in this month
                $check_month_record = "SELECT COUNT(*) as count FROM $month_table 
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $check_month_stmt = $conn->prepare($check_month_record);
                $check_month_stmt->bind_param("ss", $month, $item_code);
                $check_month_stmt->execute();
                $month_record_result = $check_month_stmt->get_result();
                $record_exists = $month_record_result->fetch_assoc()['count'] > 0;
                $check_month_stmt->close();
                
                if (!$record_exists) {
                    // Create new record with opening = last closing
                    $create_query = "INSERT INTO $month_table 
                                    (STK_MONTH, ITEM_CODE, DAY_01_OPEN, DAY_01_CLOSING) 
                                    VALUES (?, ?, ?, ?)";
                    $create_stmt = $conn->prepare($create_query);
                    $create_stmt->bind_param("ssdd", $month, $item_code, $last_closing, $last_closing);
                    $create_stmt->execute();
                    $create_stmt->close();
                } else {
                    // Update opening to reflect last closing
                    $update_opening_query = "UPDATE $month_table 
                                            SET DAY_01_OPEN = DAY_01_OPEN + ?,
                                                LAST_UPDATED = CURRENT_TIMESTAMP 
                                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                    $update_opening_stmt = $conn->prepare($update_opening_query);
                    $update_opening_stmt->bind_param("dss", $last_closing, $month, $item_code);
                    $update_opening_stmt->execute();
                    $update_opening_stmt->close();
                }
                
                // Recalculate this month
                recalculateMonthStock($conn, $month_table, $item_code, $month);
                
                // Get new last closing for next month
                $last_closing = getLastDayClosingStock($conn, $month_table, $item_code, $month);
            }
            
            // ============================================================================
            // STEP 4: DEDUCT FROM CURRENT MONTH'S OPENING
            // ============================================================================
            
            if (!empty($months_to_cascade)) {
                $current_table = getDailyStockTableName($conn, $current_date, $comp_id);
                $deduct_query = "UPDATE $current_table 
                                SET DAY_01_OPEN = DAY_01_OPEN - ?,
                                    LAST_UPDATED = CURRENT_TIMESTAMP 
                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $deduct_stmt = $conn->prepare($deduct_query);
                $deduct_stmt->bind_param("dss", $qty, $current_month, $item_code);
                $deduct_stmt->execute();
                $deduct_stmt->close();
                
                // Recalculate current month
                recalculateMonthStock($conn, $current_table, $item_code, $current_month);
            }
        }
        
        // ============================================================================
        // STEP 5: IF SALE IS IN CURRENT MONTH, RECALCULATE CURRENT MONTH
        // ============================================================================
        
        if ($sale_month === $current_month) {
            $current_table = getDailyStockTableName($conn, $current_date, $comp_id);
            recalculateMonthStock($conn, $current_table, $item_code, $current_month);
        }
        
        return true;
        
    } catch (Exception $e) {
        throw $e;
    }
}

// Helper functions (same as in sale_for_date_range.php)
function getDayClosingStock($conn, $table_name, $item_code, $stk_month, $day_str) {
    $closing_column = "DAY_{$day_str}_CLOSING";
    
    $query = "SELECT $closing_column FROM $table_name 
              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $stk_month, $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data[$closing_column] ?? 0;
    }
    $stmt->close();
    return 0;
}

function getLastDayClosingStock($conn, $table_name, $item_code, $stk_month) {
    for ($day = 31; $day >= 1; $day--) {
        $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
        $closing_column = "DAY_{$day_str}_CLOSING";
        
        $check_column = "SHOW COLUMNS FROM $table_name LIKE '$closing_column'";
        $column_result = $conn->query($check_column);
        
        if ($column_result->num_rows > 0) {
            $closing = getDayClosingStock($conn, $table_name, $item_code, $stk_month, $day_str);
            if ($closing > 0) {
                return $closing;
            }
        }
    }
    return 0;
}

function createDailyStockTable($conn, $table_name, $comp_id) {
    $check_table = "SHOW TABLES LIKE '$table_name'";
    $result = $conn->query($check_table);
    
    if ($result->num_rows == 0) {
        $create_query = "CREATE TABLE $table_name LIKE tbldailystock_$comp_id";
        $conn->query($create_query);
    }
}

function recalculateMonthStock($conn, $table_name, $item_code, $stk_month) {
    for ($day = 1; $day <= 31; $day++) {
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
            if ($next_day <= 31) {
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
        // BILL NUMBER GENERATION
        // ============================================================================
        function getNextBillNumberForGenerate($conn, $comp_id) {
            $sql = "SELECT BILL_NO FROM tblsaleheader 
                    WHERE COMP_ID = ? 
                    ORDER BY CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED) DESC 
                    LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $comp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $nextNumber = 1;
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $lastBillNo = $row['BILL_NO'];
                
                if (preg_match('/BL(\d+)/', $lastBillNo, $matches)) {
                    $nextNumber = intval($matches[1]) + 1;
                }
            }
            
            $stmt->close();
            return 'BL' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
        }
        
        // ============================================================================
        // FUNCTION TO UPDATE ITEM STOCK
        // ============================================================================
        function updateItemStock($conn, $item_code, $qty, $current_stock_column, $opening_stock_column, $fin_year_id) {
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

                // Distribute sales
                $daily_sales = distributeSales($total_qty, $days_count);
                $daily_sales_data[$item_code] = $daily_sales;

                // Store item data
                $items_data[$item_code] = [
                    'name' => $item['display_name'],
                    'rate' => $item['RPRICE'],
                    'total_qty' => $total_qty,
                    'liq_flag' => $item['LIQ_FLAG']
                ];
            }
        }
        
        // Generate bills with volume limits
        $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, 'ALL', $comp_id, $user_id, $fin_year_id);
        
        $current_stock_column = "Current_Stock" . $comp_id;
        $opening_stock_column = "Opening_Stock" . $comp_id;
        
        // ============================================================================
        // SEQUENTIAL BILL NUMBER ASSIGNMENT
        // ============================================================================
        $generated_bills = [];
        $cash_memos_generated = 0;
        $cash_memo_errors = [];

        // Sort bills chronologically
        usort($bills, function($a, $b) {
            return strtotime($a['bill_date']) - strtotime($b['bill_date']);
        });

        $archive_months_processed = [];
        $cascade_months_count = 0;

        foreach ($bills as $index => $bill) {
            // Generate sequential bill number
            $sequential_bill_no = getNextBillNumberForGenerate($conn, $comp_id);

            // Track archive months
            $bill_month = date('Y-m', strtotime($bill['bill_date']));
            $current_month = date('Y-m');
            
            if ($bill_month < $current_month) {
                if (!isset($archive_months_processed[$bill_month])) {
                    $archive_months_processed[$bill_month] = 0;
                }
                $archive_months_processed[$bill_month]++;
                $cascade_months_count++;
            }

            // Store bill info
            $generated_bills[] = [
                'bill_no' => $sequential_bill_no,
                'bill_date' => $bill['bill_date'],
                'total_amount' => $bill['total_amount'],
                'month_type' => ($bill_month == $current_month) ? 'current' : 'archive'
            ];

            // Insert sale header
            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY)
                             VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
            $header_stmt = $conn->prepare($header_query);
            $header_stmt->bind_param("ssddssi", $sequential_bill_no, $bill['bill_date'], $bill['total_amount'],
                                    $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
            $header_stmt->execute();
            $header_stmt->close();

            // Insert sale details
            foreach ($bill['items'] as $item) {
                $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                $detail_stmt = $conn->prepare($detail_query);
                $detail_stmt->bind_param("ssddssi", $sequential_bill_no, $item['code'], $item['qty'],
                                        $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                $detail_stmt->execute();
                $detail_stmt->close();

                // Update item stock
                updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
                
                // Use UPDATED cascading function
                updateCascadingDailyStock($conn, $item['code'], $bill['bill_date'], $comp_id, $item['qty']);
            }

            $total_amount += $bill['total_amount'];

            // Update bill array with new sequential number
            $bills[$index]['bill_no'] = $sequential_bill_no;

            // Generate cash memo
            if (autoGenerateCashMemoForBill($conn, $sequential_bill_no, $comp_id, $user_id)) {
                $cash_memos_generated++;
            } else {
                $cash_memo_errors[] = $sequential_bill_no;
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Calculate totals
        $total_volume = 0;
        $total_items_processed = 0;
        foreach ($items_data as $item_code => $item_data) {
            $total_items_processed += $item_data['total_qty'];
            $item_size = getItemSize($conn, $item_code, 'ALL');
            $total_volume += $item_size * $item_data['total_qty'];
        }

        $response['success'] = true;
        $response['message'] = "Sales distributed successfully! Generated " . count($bills) . " bills with sequential numbering.";
        $response['total_amount'] = number_format($total_amount, 2);
        $response['bill_count'] = count($bills);
        $response['total_items'] = $total_items_processed;
        $response['total_volume'] = $total_volume;
        $response['generated_bills'] = $generated_bills;
        $response['cash_memos_generated'] = $cash_memos_generated;
        $response['cash_memo_errors'] = $cash_memo_errors;
        $response['archive_month_support'] = true;
        
        if (!empty($archive_months_processed)) {
            $response['archive_months_processed'] = $archive_months_processed;
            $response['message'] .= " Includes bills for archived months: " . implode(", ", array_keys($archive_months_processed));
        }
        
        if ($cascade_months_count > 0) {
            $response['cascade_months'] = $cascade_months_count;
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
