<?php
// delete_bill.php
session_start();
require_once "../config/db.php";

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$compID = $_SESSION['CompID'];
$user_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => ''];

// Check if optimized processing is requested
$optimized = isset($_POST['optimized']) && $_POST['optimized'] == 'true';

// Optimize PHP settings
if ($optimized) {
    set_time_limit(300); // Increased for very large bulk operations
    ini_set('max_execution_time', 300);
    ini_set('memory_limit', '1024M'); // Increased memory for large batches
    ini_set('mysql.connect_timeout', 300);
    ini_set('default_socket_timeout', 300);
    
    // Disable some heavy PHP features for speed
    if (function_exists('apc_clear_cache')) apc_clear_cache();
    if (function_exists('opcache_reset')) opcache_reset();
} else {
    set_time_limit(60);
    ini_set('max_execution_time', 60);
    ini_set('memory_limit', '256M');
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

// NEW FUNCTION: Delete cash memos for bills
function deleteCashMemos($conn, $bill_nos, $comp_id) {
    if (empty($bill_nos)) {
        return 0;
    }
    
    // Handle single bill or array of bills
    if (!is_array($bill_nos)) {
        $bill_nos = [$bill_nos];
    }
    
    // Create placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($bill_nos), '?'));
    
    // Delete from cash_memo_prints
    $deleteCashMemoSQL = "DELETE FROM tbl_cash_memo_prints WHERE bill_no IN ($placeholders) AND comp_id = ?";
    $deleteCashMemoStmt = $conn->prepare($deleteCashMemoSQL);
    
    // Combine parameters: bill numbers + comp_id
    $params = array_merge($bill_nos, [$comp_id]);
    $types = str_repeat('s', count($bill_nos)) . 'i';
    $deleteCashMemoStmt->bind_param($types, ...$params);
    $deleteCashMemoStmt->execute();
    $deletedCashMemos = $deleteCashMemoStmt->affected_rows;
    $deleteCashMemoStmt->close();
    
    return $deletedCashMemos;
}

// NEW FUNCTION: Update cash memo bill numbers after renumbering
function updateCashMemoBillNumbers($conn, $old_bill_no, $new_bill_no, $comp_id) {
    $updateCashMemoSQL = "UPDATE tbl_cash_memo_prints 
                         SET bill_no = ? 
                         WHERE bill_no = ? AND comp_id = ?";
    $updateCashMemoStmt = $conn->prepare($updateCashMemoSQL);
    $updateCashMemoStmt->bind_param("ssi", $new_bill_no, $old_bill_no, $comp_id);
    $updateCashMemoStmt->execute();
    $updatedCount = $updateCashMemoStmt->affected_rows;
    $updateCashMemoStmt->close();
    
    return $updatedCount;
}

// NEW FUNCTION: Batch update cash memo bill numbers
function batchUpdateCashMemoBillNumbers($conn, $bill_mappings, $comp_id) {
    $updatedCount = 0;
    
    foreach ($bill_mappings as $mapping) {
        $old_bill_no = $mapping['old'];
        $new_bill_no = $mapping['new'];
        
        $updateCashMemoSQL = "UPDATE tbl_cash_memo_prints 
                             SET bill_no = ? 
                             WHERE bill_no = ? AND comp_id = ?";
        $updateCashMemoStmt = $conn->prepare($updateCashMemoSQL);
        $updateCashMemoStmt->bind_param("ssi", $new_bill_no, $old_bill_no, $comp_id);
        $updateCashMemoStmt->execute();
        $updatedCount += $updateCashMemoStmt->affected_rows;
        $updateCashMemoStmt->close();
    }
    
    return $updatedCount;
}

// Function to recalculate daily stock for SALES deletion (FIXED)
function recalculateAndCascadeForSalesItems($conn, $comp_id, $items_data, $bill_date) {
    $day_num = date('d', strtotime($bill_date));
    $stk_month = date('Y-m', strtotime($bill_date));
    $daily_table = getDailyStockTableForDate($conn, $comp_id, $bill_date);
    
    // Check if table exists
    $check_table = "SHOW TABLES LIKE '$daily_table'";
    if ($conn->query($check_table)->num_rows == 0) {
        return false;
    }
    
    // Prepare column names
    $day_str = sprintf('%02d', $day_num);
    $sales_column = "DAY_" . $day_str . "_SALES";
    $closing_column = "DAY_" . $day_str . "_CLOSING";
    $opening_column = "DAY_" . $day_str . "_OPEN";
    $purchase_column = "DAY_" . $day_str . "_PURCHASE";
    
    // Check if columns exist
    $check_sales = "SHOW COLUMNS FROM $daily_table LIKE '$sales_column'";
    $check_closing = "SHOW COLUMNS FROM $daily_table LIKE '$closing_column'";
    $check_opening = "SHOW COLUMNS FROM $daily_table LIKE '$opening_column'";
    $check_purchase = "SHOW COLUMNS FROM $daily_table LIKE '$purchase_column'";
    
    if ($conn->query($check_sales)->num_rows == 0 || 
        $conn->query($check_closing)->num_rows == 0 ||
        $conn->query($check_opening)->num_rows == 0 ||
        $conn->query($check_purchase)->num_rows == 0) {
        return false;
    }
    
    $item_codes = [];
    $qtys = [];
    foreach ($items_data as $item) {
        $item_codes[] = $item['ITEM_CODE'];
        $qtys[] = $item['QTY'];
    }
    
    if (empty($item_codes)) return true;
    
    // Create placeholders
    $placeholders = implode(',', array_fill(0, count($item_codes), '?'));
    
    // 1. FIRST: Reduce sales quantities in batch
    $update_sales_query = "UPDATE $daily_table 
                          SET $sales_column = $sales_column - CASE ITEM_CODE ";
    
    $params = [];
    $types = '';
    for ($i = 0; $i < count($item_codes); $i++) {
        $update_sales_query .= "WHEN ? THEN ? ";
        $params[] = $item_codes[$i];
        $params[] = $qtys[$i];
        $types .= 'sd'; // string, decimal
    }
    
    $update_sales_query .= "END,
                           LAST_UPDATED = CURRENT_TIMESTAMP 
                          WHERE ITEM_CODE IN ($placeholders) AND STK_MONTH = ?";
    
    // Add remaining parameters
    foreach ($item_codes as $code) {
        $params[] = $code;
        $types .= 's';
    }
    $params[] = $stk_month;
    $types .= 's';
    
    $update_sales_stmt = $conn->prepare($update_sales_query);
    $update_sales_stmt->bind_param($types, ...$params);
    $update_sales_stmt->execute();
    $update_sales_stmt->close();
    
    // 2. THEN: Recalculate closing for all items using the formula:
    // day_xx_closing = day_xx_opening + day_xx_purchase - day_xx_sales
    
    // Batch update all items at once for better performance
    $recalc_query = "UPDATE $daily_table 
                    SET $closing_column = GREATEST(0, $opening_column + $purchase_column - $sales_column),
                        LAST_UPDATED = CURRENT_TIMESTAMP 
                    WHERE ITEM_CODE IN ($placeholders) AND STK_MONTH = ?";
    
    $recalc_params = [];
    $recalc_types = '';
    foreach ($item_codes as $code) {
        $recalc_params[] = $code;
        $recalc_types .= 's';
    }
    $recalc_params[] = $stk_month;
    $recalc_types .= 's';
    
    $recalc_stmt = $conn->prepare($recalc_query);
    $recalc_stmt->bind_param($recalc_types, ...$recalc_params);
    $recalc_stmt->execute();
    $recalc_stmt->close();
    
    // 3. Cascade to subsequent days for each item
    foreach ($item_codes as $item_code) {
        cascadeDailyStockForSales($conn, $daily_table, $item_code, $stk_month, $day_num);
    }
    
    return true;
}

// Function to cascade daily stock for SALES deletion (FIXED)
function cascadeDailyStockForSales($conn, $table_name, $item_code, $stk_month, $start_day) {
    // First, get the recalculated closing for start_day
    $start_day_str = sprintf('%02d', $start_day);
    $get_closing_query = "SELECT DAY_{$start_day_str}_CLOSING as closing 
                         FROM $table_name 
                         WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $get_stmt = $conn->prepare($get_closing_query);
    $get_stmt->bind_param("ss", $item_code, $stk_month);
    $get_stmt->execute();
    $result = $get_stmt->get_result();
    
    if ($result->num_rows == 0) {
        $get_stmt->close();
        return;
    }
    
    $row = $result->fetch_assoc();
    $current_closing = $row['closing'] ?? 0;
    $get_stmt->close();
    
    // Now cascade day by day
    for ($day = $start_day + 1; $day <= 31; $day++) {
        $day_str = sprintf('%02d', $day);
        $opening_column = "DAY_{$day_str}_OPEN";
        $purchase_column = "DAY_{$day_str}_PURCHASE";
        $sales_column = "DAY_{$day_str}_SALES";
        $closing_column = "DAY_{$day_str}_CLOSING";
        
        // Check if columns exist
        $check_columns = "SHOW COLUMNS FROM $table_name WHERE Field IN ('$opening_column', '$purchase_column', '$sales_column', '$closing_column')";
        $col_result = $conn->query($check_columns);
        if ($col_result->num_rows < 4) break;
        
        // Get purchase and sales for this day
        $get_values_query = "SELECT $purchase_column as purchase, 
                                    $sales_column as sales 
                             FROM $table_name 
                             WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $values_stmt = $conn->prepare($get_values_query);
        $values_stmt->bind_param("ss", $item_code, $stk_month);
        $values_stmt->execute();
        $values_result = $values_stmt->get_result();
        
        if ($values_result->num_rows > 0) {
            $values_row = $values_result->fetch_assoc();
            $purchase = $values_row['purchase'] ?? 0;
            $sales = $values_row['sales'] ?? 0;
            
            // Set opening to previous day's closing
            $new_opening = $current_closing;
            
            // Calculate new closing using the formula:
            // closing = opening + purchase - sales
            $new_closing = $new_opening + $purchase - $sales;
            if ($new_closing < 0) $new_closing = 0;
            
            // Update the day
            $update_query = "UPDATE $table_name 
                            SET $opening_column = ?,
                                $closing_column = ?,
                                LAST_UPDATED = CURRENT_TIMESTAMP 
                            WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ddss", $new_opening, $new_closing, $item_code, $stk_month);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Update current closing for next iteration
            $current_closing = $new_closing;
        }
        
        $values_stmt->close();
    }
}

// NEW FUNCTION: Process bills in batches
function reverseStockUpdatesBulkOptimizedInBatches($conn, $bill_nos, $comp_id, $batch_size = 200) {
    if (empty($bill_nos)) {
        return 0;
    }
    
    // Convert to array if single bill
    if (!is_array($bill_nos)) {
        $bill_nos = [$bill_nos];
    }
    
    $total_deleted = 0;
    $total_batches = ceil(count($bill_nos) / $batch_size);
    $successful_batches = 0;
    
    // Process in batches
    for ($i = 0; $i < count($bill_nos); $i += $batch_size) {
        $batch = array_slice($bill_nos, $i, $batch_size);
        $batch_num = floor($i / $batch_size) + 1;
        
        error_log("Processing batch $batch_num of $total_batches with " . count($batch) . " bills");
        
        $deleted_in_batch = reverseStockUpdatesBulkOptimized($conn, $batch, $comp_id);
        
        if ($deleted_in_batch > 0) {
            $total_deleted += $deleted_in_batch;
            $successful_batches++;
        }
        
        // Optional: Small pause between batches to reduce server load
        if ($i + $batch_size < count($bill_nos)) {
            usleep(100000); // 0.1 second pause
        }
    }
    
    return $total_deleted;
}

// Ultra-optimized: Function to reverse stock updates for multiple bills in bulk
function reverseStockUpdatesBulkOptimized($conn, $bill_nos, $comp_id) {
    if (empty($bill_nos)) {
        return 0;
    }
    
    // Convert to array if single bill
    if (!is_array($bill_nos)) {
        $bill_nos = [$bill_nos];
    }
    
    // Get all bill details in bulk
    $bill_placeholders = implode(',', array_fill(0, count($bill_nos), '?'));
    $bill_query = "SELECT BILL_NO, BILL_DATE, LIQ_FLAG FROM tblsaleheader 
                   WHERE BILL_NO IN ($bill_placeholders) AND COMP_ID = ?";
    $bill_stmt = $conn->prepare($bill_query);
    $bill_params = array_merge($bill_nos, [$comp_id]);
    $bill_types = str_repeat('s', count($bill_nos)) . 'i';
    $bill_stmt->bind_param($bill_types, ...$bill_params);
    $bill_stmt->execute();
    $bill_result = $bill_stmt->get_result();
    
    $bills = [];
    while ($row = $bill_result->fetch_assoc()) {
        $bills[$row['BILL_NO']] = $row;
    }
    $bill_stmt->close();
    
    if (empty($bills)) {
        return 0;
    }
    
    // Get all sale details in bulk
    $details_query = "SELECT BILL_NO, ITEM_CODE, QTY FROM tblsaledetails 
                      WHERE BILL_NO IN ($bill_placeholders) AND COMP_ID = ?";
    $details_stmt = $conn->prepare($details_query);
    $details_stmt->bind_param($bill_types, ...$bill_params);
    $details_stmt->execute();
    $details_result = $details_stmt->get_result();
    
    $all_items = [];
    $bill_items_map = [];
    while ($row = $details_result->fetch_assoc()) {
        $item = [
            'ITEM_CODE' => $row['ITEM_CODE'],
            'QTY' => (float)$row['QTY']
        ];
        $all_items[] = $item;
        if (!isset($bill_items_map[$row['BILL_NO']])) {
            $bill_items_map[$row['BILL_NO']] = [];
        }
        $bill_items_map[$row['BILL_NO']][] = $item;
    }
    $details_stmt->close();
    
    if (empty($all_items)) {
        return 0;
    }
    
    // Get current stock column name
    $current_stock_column = "Current_Stock" . $comp_id;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Delete all cash memos in bulk (NEW - optimized)
        deleteCashMemos($conn, $bill_nos, $comp_id);
        
        // 2. Batch restore main stock (group items by item code and sum quantities)
        $item_qty_map = [];
        foreach ($all_items as $item) {
            if (!isset($item_qty_map[$item['ITEM_CODE']])) {
                $item_qty_map[$item['ITEM_CODE']] = 0;
            }
            $item_qty_map[$item['ITEM_CODE']] += $item['QTY'];
        }
        
        $item_codes = array_keys($item_qty_map);
        $qtys = array_values($item_qty_map);
        
        // Create CASE statement for batch update
        $case_statement = "CASE ITEM_CODE ";
        $params = [];
        $types = '';
        
        for ($i = 0; $i < count($item_codes); $i++) {
            $case_statement .= "WHEN ? THEN ? ";
            $params[] = $item_codes[$i];
            $params[] = $qtys[$i];
            $types .= 'sd'; // string, decimal
        }
        $case_statement .= "END";
        
        // Add the rest of parameters for IN clause
        $placeholders = implode(',', array_fill(0, count($item_codes), '?'));
        foreach ($item_codes as $code) {
            $params[] = $code;
            $types .= 's';
        }
        
        $stock_query = "UPDATE tblitem_stock 
                       SET $current_stock_column = $current_stock_column + $case_statement 
                       WHERE ITEM_CODE IN ($placeholders)";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param($types, ...$params);
        $stock_stmt->execute();
        $stock_stmt->close();
        
        // 3. Reverse daily stock updates (group by date for efficiency)
        $date_items_map = [];
        foreach ($bill_nos as $bill_no) {
            if (isset($bills[$bill_no]) && isset($bill_items_map[$bill_no])) {
                $bill_date = $bills[$bill_no]['BILL_DATE'];
                if (!isset($date_items_map[$bill_date])) {
                    $date_items_map[$bill_date] = [];
                }
                // Merge items for the same date, summing quantities
                foreach ($bill_items_map[$bill_no] as $item) {
                    $found = false;
                    foreach ($date_items_map[$bill_date] as &$existing_item) {
                        if ($existing_item['ITEM_CODE'] == $item['ITEM_CODE']) {
                            $existing_item['QTY'] += $item['QTY'];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        $date_items_map[$bill_date][] = $item;
                    }
                }
            }
        }
        
        foreach ($date_items_map as $bill_date => $items) {
            if (!recalculateAndCascadeForSalesItems($conn, $comp_id, $items, $bill_date)) {
                throw new Exception("Failed to update daily stock for date: " . $bill_date);
            }
        }
        
        // 4. Delete all sale details in bulk
        $delete_details_query = "DELETE FROM tblsaledetails WHERE BILL_NO IN ($bill_placeholders) AND COMP_ID = ?";
        $delete_details_stmt = $conn->prepare($delete_details_query);
        $delete_details_stmt->bind_param($bill_types, ...$bill_params);
        $delete_details_stmt->execute();
        $delete_details_stmt->close();
        
        // 5. Delete all sale headers in bulk
        $delete_header_query = "DELETE FROM tblsaleheader WHERE BILL_NO IN ($bill_placeholders) AND COMP_ID = ?";
        $delete_header_stmt = $conn->prepare($delete_header_query);
        $delete_header_stmt->bind_param($bill_types, ...$bill_params);
        $delete_header_stmt->execute();
        $delete_header_stmt->close();
        
        $conn->commit();
        return count($bill_nos);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error reversing stock for bills: " . $e->getMessage());
        return 0;
    }
}

// Optimized: Function to reverse stock updates for a bill (UPDATED with cash memo deletion)
function reverseStockUpdatesOptimized($conn, $bill_no, $comp_id) {
    return reverseStockUpdatesBulkOptimized($conn, [$bill_no], $comp_id);
}

// Optimized: Function to renumber bills after deletion (UPDATED with cash memo updates)
function renumberBills($conn, $comp_id) {
    // Get all bills ordered by BILL_DATE and original BILL_NO
    $get_bills_query = "SELECT BILL_NO, BILL_DATE FROM tblsaleheader 
                       WHERE COMP_ID = ? 
                       ORDER BY BILL_DATE, CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)";
    $get_stmt = $conn->prepare($get_bills_query);
    $get_stmt->bind_param("i", $comp_id);
    $get_stmt->execute();
    $result = $get_stmt->get_result();
    
    $bills = [];
    while ($row = $result->fetch_assoc()) {
        $bills[] = $row;
    }
    $get_stmt->close();
    
    if (empty($bills)) return true;
    
    // Start transaction for renumbering
    $conn->begin_transaction();
    
    try {
        $counter = 1;
        $bill_mappings = []; // Track old->new mappings for cash memo updates
        
        // Prepare statements once for reuse
        $update_header_query = "UPDATE tblsaleheader SET BILL_NO = ? WHERE BILL_NO = ? AND COMP_ID = ?";
        $update_header_stmt = $conn->prepare($update_header_query);
        
        $update_details_query = "UPDATE tblsaledetails SET BILL_NO = ? WHERE BILL_NO = ? AND COMP_ID = ?";
        $update_details_stmt = $conn->prepare($update_details_query);
        
        foreach ($bills as $bill) {
            $old_bill_no = $bill['BILL_NO'];
            $new_bill_no = "BL" . str_pad($counter, 4, '0', STR_PAD_LEFT);
            
            if ($old_bill_no !== $new_bill_no) {
                // Store mapping for cash memo update
                $bill_mappings[] = ['old' => $old_bill_no, 'new' => $new_bill_no];
                
                // Update bill header
                $update_header_stmt->bind_param("ssi", $new_bill_no, $old_bill_no, $comp_id);
                $update_header_stmt->execute();
                
                // Update bill details
                $update_details_stmt->bind_param("ssi", $new_bill_no, $old_bill_no, $comp_id);
                $update_details_stmt->execute();
            }
            
            $counter++;
        }
        
        $update_header_stmt->close();
        $update_details_stmt->close();
        
        // Update cash memo bill numbers using the mappings (NEW) - optimized
        if (!empty($bill_mappings)) {
            // Create a temporary table or use CASE statement for bulk update
            $cases = [];
            $params = [];
            $types = '';
            
            foreach ($bill_mappings as $mapping) {
                $cases[] = "WHEN ? THEN ?";
                $params[] = $mapping['old'];
                $params[] = $mapping['new'];
                $types .= 'ss';
            }
            
            $case_stmt = implode(' ', $cases);
            
            $update_cash_memo_query = "UPDATE tbl_cash_memo_prints 
                                     SET bill_no = CASE bill_no 
                                     $case_stmt 
                                     END 
                                     WHERE bill_no IN (" . implode(',', array_fill(0, count($bill_mappings), '?')) . ") 
                                     AND comp_id = ?";
            
            // Add IN clause parameters
            foreach ($bill_mappings as $mapping) {
                $params[] = $mapping['old'];
                $types .= 's';
            }
            
            $params[] = $comp_id;
            $types .= 'i';
            
            $update_cash_memo_stmt = $conn->prepare($update_cash_memo_query);
            $update_cash_memo_stmt->bind_param($types, ...$params);
            $update_cash_memo_stmt->execute();
            $update_cash_memo_stmt->close();
        }
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error renumbering bills: " . $e->getMessage());
        return false;
    }
}

// Main processing logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    
    try {
        if (isset($_POST['bulk_delete']) && isset($_POST['bill_nos'])) {
            // Bulk delete - use batch processing for large numbers
            $bill_nos = json_decode($_POST['bill_nos'], true);
            
            // Check if we should use batch processing (for large deletions)
            $batch_size = 200; // Default batch size
            if (isset($_POST['batch_size']) && is_numeric($_POST['batch_size'])) {
                $batch_size = intval($_POST['batch_size']);
            }
            
            // Use batch processing for large deletions
            if (count($bill_nos) > $batch_size) {
                $deleted_count = reverseStockUpdatesBulkOptimizedInBatches($conn, $bill_nos, $compID, $batch_size);
                
                // Only renumber once after all batches are complete
                if ($deleted_count > 0) {
                    renumberBills($conn, $compID);
                }
                
                $response = [
                    'success' => true,
                    'message' => "Successfully deleted $deleted_count bill(s) (processed in batches of $batch_size) and renumbered remaining bills."
                ];
            } else {
                // Use regular bulk function for smaller deletions
                $deleted_count = reverseStockUpdatesBulkOptimized($conn, $bill_nos, $compID);
                
                // Renumber bills after deletion
                if ($deleted_count > 0) {
                    renumberBills($conn, $compID);
                }
                
                $response = [
                    'success' => true,
                    'message' => "Successfully deleted $deleted_count bill(s) and associated cash memos, and renumbered remaining bills."
                ];
            }
            
        } elseif (isset($_POST['delete_by_date']) && isset($_POST['delete_date'])) {
            // Delete by date - get all bills first then use batch function
            $delete_date = $_POST['delete_date'];
            
            // Get all bills for the date
            $get_bills_query = "SELECT BILL_NO FROM tblsaleheader 
                               WHERE COMP_ID = ? AND BILL_DATE = ?";
            $get_stmt = $conn->prepare($get_bills_query);
            $get_stmt->bind_param("is", $compID, $delete_date);
            $get_stmt->execute();
            $result = $get_stmt->get_result();
            
            $bills_to_delete = [];
            while ($row = $result->fetch_assoc()) {
                $bills_to_delete[] = $row['BILL_NO'];
            }
            $get_stmt->close();
            
            if (empty($bills_to_delete)) {
                $response = [
                    'success' => false,
                    'message' => "No bills found for date: $delete_date"
                ];
            } else {
                // Use batch processing for large deletions
                $batch_size = 200;
                if (isset($_POST['batch_size']) && is_numeric($_POST['batch_size'])) {
                    $batch_size = intval($_POST['batch_size']);
                }
                
                if (count($bills_to_delete) > $batch_size) {
                    $deleted_count = reverseStockUpdatesBulkOptimizedInBatches($conn, $bills_to_delete, $compID, $batch_size);
                    
                    // Only renumber once after all batches are complete
                    if ($deleted_count > 0) {
                        renumberBills($conn, $compID);
                    }
                    
                    $response = [
                        'success' => true,
                        'message' => "Successfully deleted $deleted_count bill(s) for $delete_date (processed in batches of $batch_size), and renumbered remaining bills."
                    ];
                } else {
                    $deleted_count = reverseStockUpdatesBulkOptimized($conn, $bills_to_delete, $compID);
                    
                    // Renumber bills after deletion
                    if ($deleted_count > 0) {
                        renumberBills($conn, $compID);
                    }
                    
                    $response = [
                        'success' => true,
                        'message' => "Successfully deleted $deleted_count bill(s) and associated cash memos for $delete_date, and renumbered remaining bills."
                    ];
                }
            }
            
        } elseif (isset($_POST['bill_no'])) {
            // Single bill delete - use optimized function
            $bill_no = $_POST['bill_no'];
            
            $deleted_count = reverseStockUpdatesBulkOptimized($conn, [$bill_no], $compID);
            
            if ($deleted_count > 0) {
                // Renumber bills after deletion
                renumberBills($conn, $compID);
                
                $response = [
                    'success' => true,
                    'message' => "Bill $bill_no deleted successfully, along with associated cash memos, and bills renumbered."
                ];
            } else {
                $response = [
                    'success' => false,
                    'message' => "Failed to delete bill $bill_no."
                ];
            }
            
        } else {
            $response = [
                'success' => false,
                'message' => 'Invalid request parameters.'
            ];
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $response = [
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ];
    }
} else {
    $response = [
        'success' => false,
        'message' => 'Invalid request method.'
    ];
}

header('Content-Type: application/json');
echo json_encode($response);

// Original functions kept for backward compatibility (called from above when not using optimized)
function reverseStockUpdates($conn, $bill_no, $comp_id) {
    // This is the original function - kept for backward compatibility
    // It's called when $optimized is false
    return reverseStockUpdatesOptimized($conn, $bill_no, $comp_id);
}
?>