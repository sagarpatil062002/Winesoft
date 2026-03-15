<?php
// purchase_delete.php - Fixed Version with Proper Stock Reversal using TotBott
session_start();
require_once "../config/db.php";

// ============================================================================
// VOUCHER NUMBER RENUMBERING FUNCTION
// ============================================================================
function renumberVoucherNumbers($conn, $companyId) {
    $companyId = (int)$companyId;
    
    if ($companyId <= 0) {
        deleteDebugLog("VOC_NO renumbering skipped - invalid company ID: $companyId");
        return -1;
    }
    
    $renumberQuery = "
        UPDATE tblpurchases p
        INNER JOIN (
            SELECT ID, ROW_NUMBER() OVER (ORDER BY 
                COALESCE(NULLIF(TP_DATE, '0000-00-00'), DATE) ASC,
                ID ASC
            ) AS new_voc_no
            FROM tblpurchases 
            WHERE CompID = ?
        ) AS ranked ON p.ID = ranked.ID
        SET p.VOC_NO = ranked.new_voc_no";
    
    $renumberStmt = $conn->prepare($renumberQuery);
    if ($renumberStmt) {
        $renumberStmt->bind_param("i", $companyId);
        $renumberStmt->execute();
        $affectedRows = $renumberStmt->affected_rows;
        $renumberStmt->close();
        
        deleteDebugLog("VOC_NO renumbered for company $companyId after deletion, affected rows: $affectedRows");
        return $affectedRows;
    } else {
        deleteDebugLog("VOC_NO renumbering failed: " . $conn->error);
        return -1;
    }
}

// Enable error logging
error_log("=== PURCHASE DELETE STARTED ===");

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    error_log("Delete failed: Unauthorized access");
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$compID = $_SESSION['CompID'];
$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

// Function to log debug messages
function deleteDebugLog($message, $data = null) {
    $logFile = __DIR__ . '/purchase_delete_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $logMessage .= ": " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $logMessage .= ": " . $data;
        }
    }
    
    $logMessage .= "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

deleteDebugLog("Starting delete operation for company ID: " . $compID);

// Check if table exists
function checkTableExists($conn, $table_name) {
    $result = $conn->query("SHOW TABLES LIKE '$table_name'");
    return $result && $result->num_rows > 0;
}

// Function to get archive table name for a specific month/year
function getArchiveTableName($conn, $comp_id, $month, $year) {
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    return "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
}

// Function to determine if date is in previous financial year
function isPreviousFinancialYear($date) {
    $current_fy_start = date('Y-04-01');
    $purchase_timestamp = strtotime($date);
    $fy_start_timestamp = strtotime($current_fy_start);
    
    return $purchase_timestamp < $fy_start_timestamp;
}

// ============================================================================
// FIXED: Enhanced cascade function that properly recalculates all days
// ============================================================================
function cascadeDailyStock($conn, $table_name, $item_code, $stk_month, $start_day) {
    deleteDebugLog("Cascading stock in table: $table_name", [
        'item_code' => $item_code,
        'stk_month' => $stk_month,
        'start_day' => $start_day
    ]);
    
    // Get the number of days in the month
    $days_in_month = date('t', strtotime($stk_month . "-01"));
    
    // First, ensure the start day's closing is correctly calculated
    $day_str = sprintf('%02d', $start_day);
    $opening_col = "DAY_{$day_str}_OPEN";
    $purchase_col = "DAY_{$day_str}_PURCHASE";
    $sales_col = "DAY_{$day_str}_SALES";
    $closing_col = "DAY_{$day_str}_CLOSING";
    
    // Recalculate closing for the start day
    $recalc_query = "UPDATE $table_name 
                    SET $closing_col = GREATEST(0, $opening_col + $purchase_col - $sales_col)
                    WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $recalc_stmt = $conn->prepare($recalc_query);
    $recalc_stmt->bind_param("ss", $item_code, $stk_month);
    $recalc_stmt->execute();
    $recalc_stmt->close();
    
    // Get the new closing value for the start day
    $get_closing = "SELECT $closing_col as closing FROM $table_name 
                   WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $stmt = $conn->prepare($get_closing);
    $stmt->bind_param("ss", $item_code, $stk_month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $stmt->close();
        return null;
    }
    
    $row = $result->fetch_assoc();
    $current_closing = (int)($row['closing'] ?? 0);
    $stmt->close();
    
    deleteDebugLog("Start day $start_day closing after recalculation: $current_closing");
    
    // Cascade through remaining days
    for ($day = $start_day + 1; $day <= $days_in_month; $day++) {
        $day_str = sprintf('%02d', $day);
        
        $opening_col = "DAY_{$day_str}_OPEN";
        $purchase_col = "DAY_{$day_str}_PURCHASE";
        $sales_col = "DAY_{$day_str}_SALES";
        $closing_col = "DAY_{$day_str}_CLOSING";
        
        // Check if columns exist
        $check_cols = $conn->query("SHOW COLUMNS FROM $table_name LIKE '$opening_col'");
        if ($check_cols->num_rows == 0) break;
        
        // Get purchase and sales for this day
        $get_values = "SELECT $purchase_col as purchase, $sales_col as sales 
                      FROM $table_name WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $val_stmt = $conn->prepare($get_values);
        $val_stmt->bind_param("ss", $item_code, $stk_month);
        $val_stmt->execute();
        $val_result = $val_stmt->get_result();
        
        if ($val_result->num_rows > 0) {
            $val_row = $val_result->fetch_assoc();
            $purchase = (int)($val_row['purchase'] ?? 0);
            $sales = (int)($val_row['sales'] ?? 0);
            
            // Update this day - opening is previous day's closing
            $update = "UPDATE $table_name 
                      SET $opening_col = ?,
                          $closing_col = ? + $purchase - $sales
                      WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            
            $update_stmt = $conn->prepare($update);
            $update_stmt->bind_param("iiss", $current_closing, $current_closing, $item_code, $stk_month);
            
            if (!$update_stmt->execute()) {
                deleteDebugLog("Cascade update failed for day $day: " . $update_stmt->error);
            }
            $update_stmt->close();
            
            // Get new closing for next iteration
            $get_new_closing = "SELECT $closing_col as closing FROM $table_name 
                               WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $new_stmt = $conn->prepare($get_new_closing);
            $new_stmt->bind_param("ss", $item_code, $stk_month);
            $new_stmt->execute();
            $new_result = $new_stmt->get_result();
            
            if ($new_result->num_rows > 0) {
                $new_row = $new_result->fetch_assoc();
                $current_closing = (int)($new_row['closing'] ?? 0);
            }
            $new_stmt->close();
            
            deleteDebugLog("Day $day updated - opening: $current_closing, purchase: $purchase, sales: $sales, new closing: $current_closing");
        }
        $val_stmt->close();
    }
    
    deleteDebugLog("Cascading completed for all days after $start_day in $table_name");
    
    // Return the final closing value for this month (last day)
    $last_day_str = str_pad($days_in_month, 2, '0', STR_PAD_LEFT);
    $last_closing_col = "DAY_{$last_day_str}_CLOSING";
    
    $get_last = "SELECT $last_closing_col as closing FROM $table_name 
                WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $last_stmt = $conn->prepare($get_last);
    $last_stmt->bind_param("ss", $item_code, $stk_month);
    $last_stmt->execute();
    $last_result = $last_stmt->get_result();
    
    if ($last_result->num_rows > 0) {
        $last_row = $last_result->fetch_assoc();
        $last_closing = (int)($last_row['closing'] ?? 0);
        $last_stmt->close();
        return $last_closing;
    }
    $last_stmt->close();
    return $current_closing;
}

// ============================================================================
// FIXED: Function to cascade stock reversal through all months until FY end
// ============================================================================
function cascadeToFinancialYearEnd($conn, $comp_id, $item_code, $purchase_date, $reduction_qty, $starting_closing = null) {
    $purchase_timestamp = strtotime($purchase_date);
    $purchase_month = (int)date('n', $purchase_timestamp);
    $purchase_year = (int)date('Y', $purchase_timestamp);
    
    // Get financial year end date (March 31 of next year)
    if ($purchase_month >= 4) {
        // Purchase in Apr-Dec: FY ends March next year
        $fy_end_year = $purchase_year + 1;
        $fy_end_month = 3;
    } else {
        // Purchase in Jan-Mar: FY ends March same year
        $fy_end_year = $purchase_year;
        $fy_end_month = 3;
    }
    
    $fy_end_date = "$fy_end_year-$fy_end_month-31";
    
    deleteDebugLog("Cascading reversal to FY end", [
        'item_code' => $item_code,
        'purchase_date' => $purchase_date,
        'purchase_month' => $purchase_month,
        'purchase_year' => $purchase_year,
        'fy_end' => $fy_end_date,
        'reduction_qty' => $reduction_qty,
        'starting_closing' => $starting_closing
    ]);
    
    // Start from the month after purchase
    $current_month = $purchase_month + 1;
    $current_year = $purchase_year;
    
    if ($current_month > 12) {
        $current_month = 1;
        $current_year++;
    }
    
    $carry_forward = $starting_closing;
    
    // Loop through months until end of financial year
    while ($current_year < $fy_end_year || ($current_year == $fy_end_year && $current_month <= $fy_end_month)) {
        $month_2digit = str_pad($current_month, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($current_year, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        $monthYear = date('Y-m', strtotime("$current_year-$current_month-01"));
        $daysInMonth = date('t', strtotime("$current_year-$current_month-01"));
        
        deleteDebugLog("Processing month: $archive_table", [
            'monthYear' => $monthYear,
            'daysInMonth' => $daysInMonth,
            'carry_forward' => $carry_forward
        ]);
        
        if (checkTableExists($conn, $archive_table)) {
            // Check if record exists for this item
            $check_query = "SELECT COUNT(*) as count FROM $archive_table 
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("ss", $monthYear, $item_code);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $exists = $result->fetch_assoc()['count'] > 0;
            $check_stmt->close();
            
            if ($exists && $carry_forward !== null) {
                // Update day 1 opening with carry forward
                $update_query = "UPDATE $archive_table 
                                SET DAY_01_OPEN = ?,
                                    DAY_01_CLOSING = ? + DAY_01_PURCHASE - DAY_01_SALES
                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("iiss", $carry_forward, $carry_forward, $monthYear, $item_code);
                $update_stmt->execute();
                $update_stmt->close();
                
                deleteDebugLog("Updated day 1 opening in $archive_table to $carry_forward");
                
                // Cascade through all days of this month
                for ($day = 2; $day <= $daysInMonth; $day++) {
                    $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
                    $prev_day = $day - 1;
                    $prev_day_str = str_pad($prev_day, 2, '0', STR_PAD_LEFT);
                    
                    $prev_day_closing = "DAY_{$prev_day_str}_CLOSING";
                    $current_day_open = "DAY_{$day_str}_OPEN";
                    $current_day_purchase = "DAY_{$day_str}_PURCHASE";
                    $current_day_sales = "DAY_{$day_str}_SALES";
                    $current_day_closing = "DAY_{$day_str}_CLOSING";
                    
                    $cascade_query = "UPDATE $archive_table 
                                     SET $current_day_open = $prev_day_closing,
                                         $current_day_closing = $prev_day_closing + $current_day_purchase - $current_day_sales
                                     WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                    
                    $cascade_stmt = $conn->prepare($cascade_query);
                    $cascade_stmt->bind_param("ss", $monthYear, $item_code);
                    $cascade_stmt->execute();
                    $cascade_stmt->close();
                }
            }
            
            // Get the last day's closing for next month
            if ($exists && $carry_forward !== null) {
                $last_day_str = str_pad($daysInMonth, 2, '0', STR_PAD_LEFT);
                $last_closing_col = "DAY_{$last_day_str}_CLOSING";
                
                $get_last = "SELECT $last_closing_col as closing FROM $archive_table 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $last_stmt = $conn->prepare($get_last);
                $last_stmt->bind_param("ss", $monthYear, $item_code);
                $last_stmt->execute();
                $last_result = $last_stmt->get_result();
                
                if ($last_result->num_rows > 0) {
                    $last_row = $last_result->fetch_assoc();
                    $carry_forward = (int)($last_row['closing'] ?? 0);
                }
                $last_stmt->close();
            }
            
            deleteDebugLog("Month $current_month/$current_year processed, carry forward: $carry_forward");
        }
        
        // Move to next month
        $current_month++;
        if ($current_month > 12) {
            $current_month = 1;
            $current_year++;
        }
    }
    
    deleteDebugLog("Cascading reversal completed to FY end: $fy_end_date");
}

// Function to get daily stock table for a date
function getDailyStockTableForDate($conn, $comp_id, $date) {
    $current_month = date('Y-m');
    $date_month = date('Y-m', strtotime($date));
    
    if ($date_month === $current_month) {
        return "tbldailystock_" . $comp_id;
    } else {
        $date_month_short = date('m', strtotime($date));
        $date_year_short = date('y', strtotime($date));
        return "tbldailystock_" . $comp_id . "_" . $date_month_short . "_" . $date_year_short;
    }
}

// ============================================================================
// FIXED: Function to reverse purchase stock updates using TotBott
// ============================================================================
function reversePurchaseStock($conn, $purchase_id, $comp_id) {
    deleteDebugLog("Starting reverse for purchase ID: " . $purchase_id);
    
    // Get purchase details
    $purchase_query = "SELECT DATE, TPNO, AUTO_TPNO FROM tblpurchases 
                      WHERE ID = ? AND CompID = ?";
    $purchase_stmt = $conn->prepare($purchase_query);
    $purchase_stmt->bind_param("ii", $purchase_id, $comp_id);
    
    if (!$purchase_stmt->execute()) {
        deleteDebugLog("Failed to get purchase: " . $purchase_stmt->error);
        return ['success' => false, 'error' => 'Failed to get purchase details'];
    }
    
    $purchase_result = $purchase_stmt->get_result();
    
    if ($purchase_result->num_rows == 0) {
        $purchase_stmt->close();
        return ['success' => false, 'error' => 'Purchase not found'];
    }
    
    $purchase = $purchase_result->fetch_assoc();
    $purchase_date = $purchase['DATE'];
    $tp_no = $purchase['TPNO'] ?: $purchase['AUTO_TPNO'];
    $purchase_stmt->close();
    
    // Determine if purchase is in current or previous financial year
    $is_previous_fy = isPreviousFinancialYear($purchase_date);
    
    deleteDebugLog("Purchase found", [
        'date' => $purchase_date,
        'tp_no' => $tp_no,
        'is_previous_fy' => $is_previous_fy
    ]);
    
    // ============================================================================
    // FIXED: Use TotBott column directly from tblpurchasedetails
    // ============================================================================
    $details_query = "SELECT ItemCode as ITEM_CODE, 
                             TotBott as QTY
                      FROM tblpurchasedetails 
                      WHERE PurchaseID = ?";
    $details_stmt = $conn->prepare($details_query);
    $details_stmt->bind_param("i", $purchase_id);
    
    if (!$details_stmt->execute()) {
        deleteDebugLog("Failed to get purchase details: " . $details_stmt->error);
        return ['success' => false, 'error' => 'Failed to get purchase details'];
    }
    
    $details_result = $details_stmt->get_result();
    
    $items = [];
    while ($row = $details_result->fetch_assoc()) {
        $items[] = [
            'ITEM_CODE' => $row['ITEM_CODE'],
            'QTY' => (int)$row['QTY'] // Cast to integer to ensure proper subtraction
        ];
    }
    $details_stmt->close();
    
    if (empty($items)) {
        deleteDebugLog("No items found for purchase ID: " . $purchase_id);
    } else {
        deleteDebugLog("Found " . count($items) . " items with TotBott quantities", $items);
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // ============================================================================
        // FIXED: Update main stock in tblitem_stock - Correct column name
        // ============================================================================
        $current_stock_column = "CURRENT_STOCK" . $comp_id;
        
        if (checkTableExists($conn, "tblitem_stock")) {
            // First check if the column exists
            $check_col_query = "SHOW COLUMNS FROM tblitem_stock LIKE '$current_stock_column'";
            $check_col_result = $conn->query($check_col_query);
            
            if ($check_col_result && $check_col_result->num_rows > 0) {
                foreach ($items as $item) {
                    // First check current stock to ensure we don't go negative
                    $check_stock_query = "SELECT $current_stock_column as current_stock 
                                         FROM tblitem_stock WHERE ITEM_CODE = ?";
                    $check_stmt = $conn->prepare($check_stock_query);
                    $check_stmt->bind_param("s", $item['ITEM_CODE']);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $stock_row = $check_result->fetch_assoc();
                        $current_stock = (int)$stock_row['current_stock'];
                        
                        // Ensure we don't subtract more than available
                        $subtract_qty = min($item['QTY'], $current_stock);
                        
                        if ($subtract_qty > 0) {
                            $update_stock = "UPDATE tblitem_stock 
                                            SET $current_stock_column = $current_stock_column - ?,
                                                LAST_UPDATED = NOW()
                                            WHERE ITEM_CODE = ?";
                            $stock_stmt = $conn->prepare($update_stock);
                            $stock_stmt->bind_param("is", $subtract_qty, $item['ITEM_CODE']);
                            
                            if (!$stock_stmt->execute()) {
                                deleteDebugLog("Stock update failed: " . $stock_stmt->error);
                            } else {
                                deleteDebugLog("Main stock updated for {$item['ITEM_CODE']}: subtracted $subtract_qty (was $current_stock, now " . ($current_stock - $subtract_qty) . ")");
                            }
                            $stock_stmt->close();
                        }
                    }
                    $check_stmt->close();
                }
            } else {
                deleteDebugLog("Stock column $current_stock_column does not exist in tblitem_stock");
            }
        }
        
        // ============================================================================
        // FIXED: Update daily stock in purchase month
        // ============================================================================
        $day_num = (int)date('d', strtotime($purchase_date));
        $stk_month = date('Y-m', strtotime($purchase_date));
        $day_str = sprintf('%02d', $day_num);
        
        // Get the appropriate table for the purchase month
        $purchase_month = (int)date('n', strtotime($purchase_date));
        $purchase_year = (int)date('Y', strtotime($purchase_date));
        
        if ($is_previous_fy) {
            $daily_table = getArchiveTableName($conn, $comp_id, $purchase_month, $purchase_year);
        } else {
            $daily_table = "tbldailystock_" . $comp_id;
        }
        
        deleteDebugLog("Updating daily stock in table: $daily_table for date: $purchase_date, day: $day_num");
        
        // Store the final closing value from purchase month for cascading
        $purchase_month_closing = null;
        
        if (checkTableExists($conn, $daily_table)) {
            // Process each item
            foreach ($items as $item) {
                // Check if record exists for this item in this month
                $check_exists = "SELECT COUNT(*) as cnt FROM $daily_table 
                                WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                $check_stmt = $conn->prepare($check_exists);
                $check_stmt->bind_param("ss", $item['ITEM_CODE'], $stk_month);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $exists = $check_result->fetch_assoc()['cnt'] > 0;
                $check_stmt->close();
                
                if ($exists) {
                    // Get current purchase value for this day
                    $purchase_col = "DAY_{$day_str}_PURCHASE";
                    $get_purchase = "SELECT $purchase_col as purchase FROM $daily_table 
                                    WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                    $get_stmt = $conn->prepare($get_purchase);
                    $get_stmt->bind_param("ss", $item['ITEM_CODE'], $stk_month);
                    $get_stmt->execute();
                    $get_result = $get_stmt->get_result();
                    $current_purchase = 0;
                    
                    if ($get_result->num_rows > 0) {
                        $purchase_row = $get_result->fetch_assoc();
                        $current_purchase = (int)$purchase_row['purchase'];
                    }
                    $get_stmt->close();
                    
                    // Calculate how much to subtract (should not exceed current purchase)
                    $subtract_qty = min($item['QTY'], $current_purchase);
                    
                    deleteDebugLog("Adjusting purchase for {$item['ITEM_CODE']} on day $day_num", [
                        'current_purchase' => $current_purchase,
                        'need_to_subtract' => $item['QTY'],
                        'actual_subtract' => $subtract_qty
                    ]);
                    
                    if ($subtract_qty > 0) {
                        // Reduce purchase for this day
                        $update_purchase = "UPDATE $daily_table 
                                           SET $purchase_col = GREATEST(0, $purchase_col - ?)
                                           WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                        
                        $update_stmt = $conn->prepare($update_purchase);
                        $update_stmt->bind_param("iss", $subtract_qty, $item['ITEM_CODE'], $stk_month);
                        
                        if (!$update_stmt->execute()) {
                            deleteDebugLog("Purchase update failed: " . $update_stmt->error);
                        }
                        $update_stmt->close();
                        
                        // Use enhanced cascade function that properly recalculates
                        $last_closing = cascadeDailyStock($conn, $daily_table, $item['ITEM_CODE'], $stk_month, $day_num);
                        
                        if ($purchase_month_closing === null) {
                            $purchase_month_closing = $last_closing;
                        }
                        
                        deleteDebugLog("Successfully reversed $subtract_qty bottles for {$item['ITEM_CODE']} in month $stk_month, last day closing: $last_closing");
                    } else {
                        deleteDebugLog("No purchase found for {$item['ITEM_CODE']} on day $day_num in month $stk_month");
                    }
                } else {
                    deleteDebugLog("No record found for {$item['ITEM_CODE']} in $daily_table for month $stk_month");
                }
            }
        } else {
            deleteDebugLog("Daily stock table $daily_table does not exist");
        }
        
        // ============================================================================
        // FIXED: If this is previous FY, cascade through remaining months
        // ============================================================================
        if ($is_previous_fy && $purchase_month_closing !== null) {
            foreach ($items as $item) {
                // For previous FY, cascade the reduction through all months until FY end
                // Pass the last closing value from purchase month
                cascadeToFinancialYearEnd($conn, $comp_id, $item['ITEM_CODE'], $purchase_date, $item['QTY'], $purchase_month_closing);
            }
        }
        
        // ============================================================================
        // Delete purchase details and header
        // ============================================================================
        if (checkTableExists($conn, "tblpurchasedetails")) {
            $delete_details = "DELETE FROM tblpurchasedetails WHERE PurchaseID = ?";
            $del_details_stmt = $conn->prepare($delete_details);
            $del_details_stmt->bind_param("i", $purchase_id);
            
            if (!$del_details_stmt->execute()) {
                deleteDebugLog("Failed to delete details: " . $del_details_stmt->error);
                throw new Exception("Failed to delete purchase details");
            }
            deleteDebugLog("Deleted " . $del_details_stmt->affected_rows . " detail records");
            $del_details_stmt->close();
        }
        
        if (checkTableExists($conn, "tblpurchases")) {
            $delete_header = "DELETE FROM tblpurchases WHERE ID = ? AND CompID = ?";
            $del_header_stmt = $conn->prepare($delete_header);
            $del_header_stmt->bind_param("ii", $purchase_id, $comp_id);
            
            if (!$del_header_stmt->execute()) {
                deleteDebugLog("Failed to delete header: " . $del_header_stmt->error);
                throw new Exception("Failed to delete purchase header");
            }
            deleteDebugLog("Deleted purchase header, affected rows: " . $del_header_stmt->affected_rows);
            $del_header_stmt->close();
        }
        
        // ============================================================================
        // Renumber VOC_NO after deletion
        // ============================================================================
        renumberVoucherNumbers($conn, $comp_id);
        
        // Commit transaction
        $conn->commit();
        deleteDebugLog("Transaction committed successfully");
        
        $message = 'Purchase deleted successfully';
        if ($tp_no) {
            $message .= ". TP number: $tp_no";
        }
        $message .= " (Logic: " . ($is_previous_fy ? "Previous FY" : "Current FY") . ")";
        
        return [
            'success' => true,
            'tp_no' => $tp_no,
            'item_count' => count($items),
            'message' => $message
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        deleteDebugLog("ERROR: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// ============================================================================
// Main processing logic
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        if (isset($_POST['bulk_delete']) && isset($_POST['purchase_ids'])) {
            // Bulk delete
            $purchase_ids = json_decode($_POST['purchase_ids'], true);
            
            if (!is_array($purchase_ids) || empty($purchase_ids)) {
                throw new Exception('No purchase IDs provided');
            }
            
            if (count($purchase_ids) > 50) {
                throw new Exception('Maximum 50 purchases can be deleted at once');
            }
            
            deleteDebugLog("Bulk delete request", $purchase_ids);
            
            $deleted_count = 0;
            $failed_count = 0;
            $results = [];
            $tp_numbers_freed = [];
            
            foreach ($purchase_ids as $purchase_id) {
                $purchase_id = (int)$purchase_id;
                
                if ($purchase_id <= 0) {
                    $failed_count++;
                    continue;
                }
                
                $result = reversePurchaseStock($conn, $purchase_id, $compID);
                $results[] = $result;
                
                if ($result['success'] ?? false) {
                    $deleted_count++;
                    if (!empty($result['tp_no'])) {
                        $tp_numbers_freed[] = $result['tp_no'];
                    }
                } else {
                    $failed_count++;
                }
            }
            
            $message = "Deleted $deleted_count purchase(s). Failed: $failed_count";
            if (!empty($tp_numbers_freed)) {
                $message .= ". TP numbers: " . implode(', ', array_unique($tp_numbers_freed));
            }
            
            $response = [
                'success' => true,
                'message' => $message,
                'deleted_count' => $deleted_count,
                'failed_count' => $failed_count,
                'tp_numbers_freed' => $tp_numbers_freed,
                'results' => $results
            ];
            
        } elseif (isset($_POST['purchase_id'])) {
            // Single purchase delete
            $purchase_id = (int)$_POST['purchase_id'];
            
            if ($purchase_id <= 0) {
                throw new Exception("Invalid purchase ID");
            }
            
            deleteDebugLog("Single delete request for ID: " . $purchase_id);
            
            $result = reversePurchaseStock($conn, $purchase_id, $compID);
            
            if ($result['success'] ?? false) {
                $response = [
                    'success' => true,
                    'message' => $result['message'] ?? "Purchase deleted successfully",
                    'tp_no' => $result['tp_no'] ?? '',
                    'item_count' => $result['item_count'] ?? 0
                ];
            } else {
                throw new Exception($result['error'] ?? "Failed to delete purchase");
            }
            
        } else {
            throw new Exception("Invalid request parameters");
        }
        
    } catch (Exception $e) {
        deleteDebugLog("Exception: " . $e->getMessage());
        $response = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
    
    echo json_encode($response);
    exit;
    
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. Use POST.'
    ]);
    exit;
}

deleteDebugLog("=== PURCHASE DELETE ENDED ===");
?>