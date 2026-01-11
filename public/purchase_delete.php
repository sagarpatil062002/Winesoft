<?php
// purchase_delete_optimized.php - Complete Working Version with Full Cascading
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
    set_time_limit(120); // Increased for bulk operations
    ini_set('max_execution_time', 120);
    ini_set('memory_limit', '512M');
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

// Function to get all daily stock tables from start date to today
function getDailyStockTablesInRange($conn, $comp_id, $start_date) {
    $tables = [];
    $start_date_obj = new DateTime($start_date);
    $end_date_obj = new DateTime(); // today
    $current_month = date('Y-m');
    
    // Always include current month table
    $tables[] = "tbldailystock_" . $comp_id;
    
    // Add historical tables
    for ($i = 1; $i <= 12; $i++) { // Check up to 12 months back
        $check_date = clone $start_date_obj;
        $check_date->modify("-$i months");
        $check_month = $check_date->format('Y-m');
        
        // Stop if we've gone before the start date
        if ($check_date < $start_date_obj && $i > 1) break;
        
        $table_name = "tbldailystock_" . $comp_id . "_" . $check_date->format('m') . "_" . $check_date->format('y');
        
        // Check if table exists
        $check_table = "SHOW TABLES LIKE '$table_name'";
        if ($conn->query($check_table)->num_rows > 0) {
            $tables[] = $table_name;
        }
    }
    
    return array_unique($tables);
}

// Optimized: Batch recalculation function for PURCHASE
function recalculateAndCascadeForPurchaseItems($conn, $comp_id, $items_data, $purchase_date) {
    $day_num = date('d', strtotime($purchase_date));
    $stk_month = date('Y-m', strtotime($purchase_date));
    $daily_table = getDailyStockTableForDate($conn, $comp_id, $purchase_date);
    
    // Check if table exists
    $check_table = "SHOW TABLES LIKE '$daily_table'";
    if ($conn->query($check_table)->num_rows == 0) {
        return false;
    }
    
    // Prepare column names for PURCHASE
    $day_str = sprintf('%02d', $day_num);
    $purchase_column = "DAY_" . $day_str . "_PURCHASE";
    $closing_column = "DAY_" . $day_str . "_CLOSING";
    $opening_column = "DAY_" . $day_str . "_OPEN";
    $sales_column = "DAY_" . $day_str . "_SALES";
    
    // Check if columns exist
    $check_purchase = "SHOW COLUMNS FROM $daily_table LIKE '$purchase_column'";
    $check_closing = "SHOW COLUMNS FROM $daily_table LIKE '$closing_column'";
    $check_opening = "SHOW COLUMNS FROM $daily_table LIKE '$opening_column'";
    $check_sales = "SHOW COLUMNS FROM $daily_table LIKE '$sales_column'";
    
    if ($conn->query($check_purchase)->num_rows == 0 || 
        $conn->query($check_closing)->num_rows == 0 ||
        $conn->query($check_opening)->num_rows == 0 ||
        $conn->query($check_sales)->num_rows == 0) {
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
    
    // 1. FIRST: Reduce purchase quantities in batch
    $update_purchase_query = "UPDATE $daily_table 
                          SET $purchase_column = $purchase_column - CASE ITEM_CODE ";
    
    $params = [];
    $types = '';
    for ($i = 0; $i < count($item_codes); $i++) {
        $update_purchase_query .= "WHEN ? THEN ? ";
        $params[] = $item_codes[$i];
        $params[] = $qtys[$i];
        $types .= 'sd'; // string, decimal
    }
    
    $update_purchase_query .= "END,
                           LAST_UPDATED = CURRENT_TIMESTAMP 
                          WHERE ITEM_CODE IN ($placeholders) AND STK_MONTH = ?";
    
    // Add remaining parameters
    foreach ($item_codes as $code) {
        $params[] = $code;
        $types .= 's';
    }
    $params[] = $stk_month;
    $types .= 's';
    
    $update_purchase_stmt = $conn->prepare($update_purchase_query);
    $update_purchase_stmt->bind_param($types, ...$params);
    $update_purchase_stmt->execute();
    $update_purchase_stmt->close();
    
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
    
    // 3. Cascade to subsequent days for each item using NEW CORRECTED FUNCTION
    foreach ($item_codes as $item_code) {
        cascadeDailyStockCorrected($conn, $daily_table, $item_code, $stk_month, $day_num);
    }
    
    return true;
}

// NEW CORRECTED CASCADE FUNCTION - FIXED VERSION
function cascadeDailyStockCorrected($conn, $table_name, $item_code, $stk_month, $start_day) {
    // First, let's get ALL the data for this item in one query to avoid transaction visibility issues
    $all_data_query = "SELECT ";
    
    // Build SELECT for all columns from start_day to 31
    for ($day = $start_day; $day <= 31; $day++) {
        $day_str = sprintf('%02d', $day);
        $prev_day_str = sprintf('%02d', $day - 1);
        
        $opening_column = "DAY_{$day_str}_OPEN";
        $purchase_column = "DAY_{$day_str}_PURCHASE";
        $sales_column = "DAY_{$day_str}_SALES";
        $closing_column = "DAY_{$day_str}_CLOSING";
        
        // Check if columns exist
        $check_columns = "SHOW COLUMNS FROM $table_name WHERE Field IN ('$opening_column', '$purchase_column', '$sales_column', '$closing_column')";
        $col_result = $conn->query($check_columns);
        if ($col_result->num_rows < 4) break;
        
        if ($day > $start_day) $all_data_query .= ", ";
        $all_data_query .= "$opening_column as opening_$day_str, ";
        $all_data_query .= "$purchase_column as purchase_$day_str, ";
        $all_data_query .= "$sales_column as sales_$day_str, ";
        $all_data_query .= "$closing_column as closing_$day_str";
    }
    
    $all_data_query .= " FROM $table_name WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    
    $stmt = $conn->prepare($all_data_query);
    $stmt->bind_param("ss", $item_code, $stk_month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $stmt->close();
        return;
    }
    
    $row = $result->fetch_assoc();
    $stmt->close();
    
    // Now process the cascading in memory
    $previous_closing = 0;
    
    // For start_day, we need to use the recalculated closing from step 2
    // Get the recalculated closing for start_day
    $start_day_str = sprintf('%02d', $start_day);
    $get_start_closing = "SELECT DAY_{$start_day_str}_CLOSING as closing 
                         FROM $table_name 
                         WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $get_stmt = $conn->prepare($get_start_closing);
    $get_stmt->bind_param("ss", $item_code, $stk_month);
    $get_stmt->execute();
    $closing_result = $get_stmt->get_result();
    
    if ($closing_result->num_rows > 0) {
        $closing_row = $closing_result->fetch_assoc();
        $previous_closing = $closing_row['closing'] ?? 0;
    }
    $get_stmt->close();
    
    // Now cascade from start_day+1 to 31
    for ($day = $start_day + 1; $day <= 31; $day++) {
        $day_str = sprintf('%02d', $day);
        $prev_day_str = sprintf('%02d', $day - 1);
        
        $opening_column = "DAY_{$day_str}_OPEN";
        $purchase_column = "DAY_{$day_str}_PURCHASE";
        $sales_column = "DAY_{$day_str}_SALES";
        $closing_column = "DAY_{$day_str}_CLOSING";
        
        // Check if columns exist
        $check_columns = "SHOW COLUMNS FROM $table_name WHERE Field IN ('$opening_column', '$purchase_column', '$sales_column', '$closing_column')";
        $col_result = $conn->query($check_columns);
        if ($col_result->num_rows < 4) break;
        
        // Get purchase and sales values from our fetched data
        $purchase = $row["purchase_$day_str"] ?? 0;
        $sales = $row["sales_$day_str"] ?? 0;
        
        // Calculate new opening (previous day's closing)
        $new_opening = $previous_closing;
        
        // Calculate new closing: opening + purchase - sales
        $new_closing = $new_opening + $purchase - $sales;
        if ($new_closing < 0) $new_closing = 0;
        
        // Update the day in a single query
        $update_query = "UPDATE $table_name 
                        SET $opening_column = ?,
                            $closing_column = ?,
                            LAST_UPDATED = CURRENT_TIMESTAMP 
                        WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ddss", $new_opening, $new_closing, $item_code, $stk_month);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Update previous closing for next iteration
        $previous_closing = $new_closing;
    }
}

// NEW SIMPLIFIED AND WORKING CASCADE FUNCTION
function cascadeDailyStockSimple($conn, $table_name, $item_code, $stk_month, $start_day) {
    // Get initial closing for start_day (already recalculated)
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
            
            // Calculate new closing
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

// Optimized: Function to reverse stock updates for a purchase with full cascading
function reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $comp_id) {
    // Get purchase details
    $purchase_query = "SELECT DATE, TPNO, AUTO_TPNO FROM tblpurchases 
                      WHERE ID = ? AND CompID = ?";
    $purchase_stmt = $conn->prepare($purchase_query);
    $purchase_stmt->bind_param("ii", $purchase_id, $comp_id);
    $purchase_stmt->execute();
    $purchase_result = $purchase_stmt->get_result();
    
    if ($purchase_result->num_rows == 0) {
        $purchase_stmt->close();
        return ['success' => false, 'error' => 'Purchase not found'];
    }
    
    $purchase = $purchase_result->fetch_assoc();
    $purchase_date = $purchase['DATE'];
    $tp_no = $purchase['TPNO'] ?: $purchase['AUTO_TPNO'];
    $purchase_stmt->close();
    
    // Get purchase details with calculated QTY
    $details_query = "SELECT ItemCode as ITEM_CODE, 
                             Cases, 
                             Bottles,
                             BottlesPerCase,
                             (Cases * BottlesPerCase + Bottles) as QTY
                      FROM tblpurchasedetails 
                      WHERE PurchaseID = ?";
    $details_stmt = $conn->prepare($details_query);
    $details_stmt->bind_param("i", $purchase_id);
    $details_stmt->execute();
    $details_result = $details_stmt->get_result();
    
    $items = [];
    while ($row = $details_result->fetch_assoc()) {
        $items[] = [
            'ITEM_CODE' => $row['ITEM_CODE'],
            'QTY' => (float)$row['QTY']
        ];
    }
    $details_stmt->close();
    
    if (empty($items)) {
        return ['success' => false, 'error' => 'No items found in purchase'];
    }
    
    // Get current stock column name
    $current_stock_column = "Current_Stock" . $comp_id;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Reduce main stock (opposite of purchase - we're removing purchase)
        $item_codes = [];
        $qtys = [];
        foreach ($items as $item) {
            $item_codes[] = $item['ITEM_CODE'];
            $qtys[] = $item['QTY'];
        }
        
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
                       SET $current_stock_column = $current_stock_column - $case_statement 
                       WHERE ITEM_CODE IN ($placeholders)";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param($types, ...$params);
        
        if (!$stock_stmt->execute()) {
            throw new Exception("Failed to update main stock");
        }
        $stock_stmt->close();
        
        // 2. Reverse daily stock updates using batch function (for PURCHASE)
        if (!recalculateAndCascadeForPurchaseItems($conn, $comp_id, $items, $purchase_date)) {
            throw new Exception("Failed to update daily stock");
        }
        
        // 3. Handle subsequent months if needed
        $purchase_day = date('d', strtotime($purchase_date));
        $purchase_month = date('Y-m', strtotime($purchase_date));
        $current_month = date('Y-m');
        
        // If purchase was before current month, we need to cascade through all months
        if ($purchase_month < $current_month) {
            $daily_tables = getDailyStockTablesInRange($conn, $comp_id, $purchase_date);
            
            foreach ($daily_tables as $table_name) {
                // Skip the current month table (already processed in step 2)
                if ($table_name == getDailyStockTableForDate($conn, $comp_id, $purchase_date)) {
                    continue;
                }
                
                // Get STK_MONTH from table name
                $stk_month = date('Y-m');
                if (preg_match('/_(\d{2})_(\d{2})$/', $table_name, $matches)) {
                    $month = $matches[1];
                    $year = '20' . $matches[2];
                    $stk_month = $year . '-' . $month;
                }
                
                // For each item, cascade from day 1
                foreach ($items as $item) {
                    $item_code = $item['ITEM_CODE'];
                    
                    // First, ensure day 1 opening is correct
                    // Get last day of previous month's closing
                    $prev_month = date('Y-m', strtotime($stk_month . ' -1 month'));
                    $prev_month_table = getDailyStockTableForDate($conn, $comp_id, $prev_month . '-01');
                    
                    // Check if previous month table exists
                    $check_prev = "SHOW TABLES LIKE '$prev_month_table'";
                    if ($conn->query($check_prev)->num_rows > 0) {
                        // Get last day's closing from previous month
                        $last_day_closing = 0;
                        for ($day = 31; $day >= 1; $day--) {
                            $day_str = sprintf('%02d', $day);
                            $closing_column = "DAY_{$day_str}_CLOSING";
                            
                            $check_col = "SHOW COLUMNS FROM $prev_month_table LIKE '$closing_column'";
                            if ($conn->query($check_col)->num_rows > 0) {
                                $get_closing = "SELECT $closing_column as closing 
                                               FROM $prev_month_table 
                                               WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                                $closing_stmt = $conn->prepare($get_closing);
                                $closing_stmt->bind_param("ss", $item_code, $prev_month);
                                $closing_stmt->execute();
                                $closing_result = $closing_stmt->get_result();
                                
                                if ($closing_result->num_rows > 0) {
                                    $closing_row = $closing_result->fetch_assoc();
                                    $last_day_closing = $closing_row['closing'] ?? 0;
                                    break;
                                }
                                $closing_stmt->close();
                            }
                        }
                        
                        // Set day 1 opening to previous month's last closing
                        if ($last_day_closing > 0) {
                            $update_opening = "UPDATE $table_name 
                                             SET DAY_01_OPEN = ?,
                                                 LAST_UPDATED = CURRENT_TIMESTAMP 
                                             WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                            $opening_stmt = $conn->prepare($update_opening);
                            $opening_stmt->bind_param("dss", $last_day_closing, $item_code, $stk_month);
                            $opening_stmt->execute();
                            $opening_stmt->close();
                        }
                    }
                    
                    // Now cascade through this month
                    cascadeDailyStockSimple($conn, $table_name, $item_code, $stk_month, 1);
                }
            }
        }
        
        // 4. Delete purchase details
        $delete_details_query = "DELETE FROM tblpurchasedetails WHERE PurchaseID = ?";
        $delete_details_stmt = $conn->prepare($delete_details_query);
        $delete_details_stmt->bind_param("i", $purchase_id);
        
        if (!$delete_details_stmt->execute()) {
            throw new Exception("Failed to delete purchase details");
        }
        $delete_details_stmt->close();
        
        // 5. Delete purchase header
        $delete_header_query = "DELETE FROM tblpurchases WHERE ID = ? AND CompID = ?";
        $delete_header_stmt = $conn->prepare($delete_header_query);
        $delete_header_stmt->bind_param("ii", $purchase_id, $comp_id);
        
        if (!$delete_header_stmt->execute()) {
            throw new Exception("Failed to delete purchase header");
        }
        $delete_header_stmt->close();
        
        // 6. Update any related records (optional)
        if (tableExists($conn, "tblsupplier_ledger")) {
            $delete_ledger_query = "DELETE FROM tblsupplier_ledger 
                                   WHERE REF_NO = ? AND REF_TYPE = 'PURCHASE'";
            $delete_ledger_stmt = $conn->prepare($delete_ledger_query);
            $delete_ledger_stmt->bind_param("s", $tp_no);
            $delete_ledger_stmt->execute();
            $delete_ledger_stmt->close();
        }
        
        $conn->commit();
        return [
            'success' => true,
            'tp_no' => $tp_no,
            'item_count' => count($items),
            'message' => 'Purchase deleted successfully'
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error reversing stock for purchase $purchase_id: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Helper function to check if table exists
function tableExists($conn, $table_name) {
    $check = $conn->query("SHOW TABLES LIKE '$table_name'");
    return $check->num_rows > 0;
}

// Helper function to validate dates in bulk delete
function validateBulkDeleteRequest($purchase_ids, $max_limit = 50) {
    if (!is_array($purchase_ids) || empty($purchase_ids)) {
        return ['success' => false, 'error' => 'No purchase IDs provided'];
    }
    
    if (count($purchase_ids) > $max_limit) {
        return ['success' => false, 'error' => "Maximum $max_limit purchases can be deleted at once"];
    }
    
    foreach ($purchase_ids as $id) {
        if (!is_numeric($id) || $id <= 0) {
            return ['success' => false, 'error' => 'Invalid purchase ID found: ' . $id];
        }
    }
    
    return ['success' => true];
}

// Main processing logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        if (isset($_POST['bulk_delete']) && isset($_POST['purchase_ids'])) {
            // Bulk delete
            $purchase_ids = json_decode($_POST['purchase_ids'], true);
            
            // Validate the request
            $validation = validateBulkDeleteRequest($purchase_ids);
            if (!$validation['success']) {
                throw new Exception($validation['error']);
            }
            
            $deleted_count = 0;
            $failed_count = 0;
            $results = [];
            
            foreach ($purchase_ids as $purchase_id) {
                $purchase_id = (int)$purchase_id;
                
                $result = reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $compID);
                $results[] = $result;
                
                if ($result['success'] ?? false) {
                    $deleted_count++;
                } else {
                    $failed_count++;
                }
            }
            
            $response = [
                'success' => true,
                'message' => "Deleted $deleted_count purchase(s). Failed: $failed_count",
                'deleted_count' => $deleted_count,
                'failed_count' => $failed_count,
                'results' => $results
            ];
            
        } elseif (isset($_POST['delete_by_date']) && isset($_POST['delete_date'])) {
            // Delete all purchases for a specific date
            $delete_date = $_POST['delete_date'];
            $mode = isset($_POST['mode']) ? $_POST['mode'] : 'ALL';
            
            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $delete_date) || 
                strtotime($delete_date) === false) {
                throw new Exception("Invalid date format. Use YYYY-MM-DD");
            }
            
            // Get purchase IDs for the date
            $get_purchases_query = "SELECT ID FROM tblpurchases 
                                   WHERE CompID = ? AND DATE = ?";
            
            if ($mode !== 'ALL') {
                $get_purchases_query .= " AND PUR_FLAG = ?";
                $stmt = $conn->prepare($get_purchases_query);
                $stmt->bind_param("iss", $compID, $delete_date, $mode);
            } else {
                $stmt = $conn->prepare($get_purchases_query);
                $stmt->bind_param("is", $compID, $delete_date);
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            $purchases_to_delete = [];
            
            while ($row = $result->fetch_assoc()) {
                $purchases_to_delete[] = $row['ID'];
            }
            $stmt->close();
            
            if (empty($purchases_to_delete)) {
                $response = [
                    'success' => true,
                    'message' => "No purchases found for date $delete_date"
                ];
            } else {
                // Validate bulk delete limit
                if (count($purchases_to_delete) > 50) {
                    throw new Exception("Maximum 50 purchases can be deleted at once. Found " . count($purchases_to_delete) . " purchases for this date.");
                }
                
                $deleted_count = 0;
                $failed_count = 0;
                $results = [];
                
                foreach ($purchases_to_delete as $purchase_id) {
                    $result = reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $compID);
                    $results[] = $result;
                    
                    if ($result['success'] ?? false) {
                        $deleted_count++;
                    } else {
                        $failed_count++;
                    }
                }
                
                $response = [
                    'success' => true,
                    'message' => "Deleted $deleted_count purchase(s) for $delete_date. Failed: $failed_count",
                    'deleted_count' => $deleted_count,
                    'failed_count' => $failed_count,
                    'results' => $results
                ];
            }
            
        } elseif (isset($_POST['purchase_id'])) {
            // Single purchase delete
            $purchase_id = (int)$_POST['purchase_id'];
            
            if ($purchase_id <= 0) {
                throw new Exception("Invalid purchase ID");
            }
            
            $result = reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $compID);
            
            if ($result['success'] ?? false) {
                $response = [
                    'success' => true,
                    'message' => "Purchase deleted successfully",
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
        $response = [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
    
    echo json_encode($response);
    exit;
    
} else {
    // Invalid request method
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. Use POST.'
    ]);
    exit;
}

// Original function for backward compatibility
function reversePurchaseStockUpdates($conn, $purchase_id, $comp_id) {
    return reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $comp_id);
}
?>]