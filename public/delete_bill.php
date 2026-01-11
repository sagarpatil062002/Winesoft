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

// Optimized: Batch recalculation function
function recalculateAndCascadeForItems($conn, $comp_id, $items_data, $bill_date) {
    $day_num = date('d', strtotime($bill_date));
    $stk_month = date('Y-m', strtotime($bill_date));
    $daily_table = getDailyStockTableForDate($conn, $comp_id, $bill_date);
    
    // Check if table exists
    $check_table = "SHOW TABLES LIKE '$daily_table'";
    if ($conn->query($check_table)->num_rows == 0) {
        return false;
    }
    
    // Prepare column names
    $sales_column = "DAY_" . sprintf('%02d', $day_num) . "_SALES";
    $closing_column = "DAY_" . sprintf('%02d', $day_num) . "_CLOSING";
    
    // Check if columns exist
    $check_sales = "SHOW COLUMNS FROM $daily_table LIKE '$sales_column'";
    $check_closing = "SHOW COLUMNS FROM $daily_table LIKE '$closing_column'";
    
    if ($conn->query($check_sales)->num_rows == 0 || $conn->query($check_closing)->num_rows == 0) {
        return false;
    }
    
    // Batch update sales column
    $item_codes = [];
    $qtys = [];
    foreach ($items_data as $item) {
        $item_codes[] = $item['ITEM_CODE'];
        $qtys[] = $item['QTY'];
    }
    
    if (empty($item_codes)) return true;
    
    // Create placeholders
    $placeholders = implode(',', array_fill(0, count($item_codes), '?'));
    
    // Update sales in batch
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
    
    // Batch recalculate closing for all items
    foreach ($items_data as $item) {
        $item_code = $item['ITEM_CODE'];
        $qty = $item['QTY'];
        
        // Get current values
        $query = "SELECT 
                    DAY_" . sprintf('%02d', $day_num) . "_OPEN as opening,
                    DAY_" . sprintf('%02d', $day_num) . "_PURCHASE as purchase,
                    $sales_column as sales
                  FROM $daily_table 
                  WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $item_code, $stk_month);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $opening = $row['opening'] ?? 0;
            $purchase = $row['purchase'] ?? 0;
            $sales_val = $row['sales'] ?? 0;
            
            // Calculate new closing
            $new_closing = $opening + $purchase - $sales_val;
            
            // Update closing column
            $update_closing_query = "UPDATE $daily_table 
                                   SET $closing_column = ?,
                                       LAST_UPDATED = CURRENT_TIMESTAMP 
                                   WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $update_stmt = $conn->prepare($update_closing_query);
            $update_stmt->bind_param("dss", $new_closing, $item_code, $stk_month);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Optimized: Cascade only if needed
            cascadeDailyStockFromDayOptimized($conn, $daily_table, $item_code, $stk_month, $day_num + 1);
        }
        $stmt->close();
    }
    
    return true;
}

// Optimized cascade function
function cascadeDailyStockFromDayOptimized($conn, $table_name, $item_code, $stk_month, $start_day) {
    // Only cascade if we have valid columns
    for ($day = $start_day; $day <= 31; $day++) {
        $day_num = sprintf('%02d', $day);
        $closing_column = "DAY_{$day_num}_CLOSING";
        $next_opening_column = "DAY_" . sprintf('%02d', $day + 1) . "_OPEN";
        
        // Check if columns exist
        $check_columns = "SHOW COLUMNS FROM $table_name WHERE Field IN ('$closing_column', '$next_opening_column')";
        $col_result = $conn->query($check_columns);
        if ($col_result->num_rows < 2) break;
        
        // Get current closing
        $get_query = "SELECT $closing_column FROM $table_name WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $get_stmt = $conn->prepare($get_query);
        $get_stmt->bind_param("ss", $item_code, $stk_month);
        $get_stmt->execute();
        $get_result = $get_stmt->get_result();
        
        if ($get_result->num_rows > 0) {
            $row = $get_result->fetch_assoc();
            $current_closing = $row[$closing_column] ?? 0;
            
            // Set next day's opening
            $update_query = "UPDATE $table_name 
                           SET $next_opening_column = ?,
                               LAST_UPDATED = CURRENT_TIMESTAMP 
                           WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("dss", $current_closing, $item_code, $stk_month);
            $update_stmt->execute();
            $update_stmt->close();
        }
        $get_stmt->close();
    }
}

// Optimized: Function to reverse stock updates for a bill
function reverseStockUpdatesOptimized($conn, $bill_no, $comp_id) {
    // Get bill details
    $bill_query = "SELECT BILL_DATE, LIQ_FLAG FROM tblsaleheader 
                   WHERE BILL_NO = ? AND COMP_ID = ?";
    $bill_stmt = $conn->prepare($bill_query);
    $bill_stmt->bind_param("si", $bill_no, $comp_id);
    $bill_stmt->execute();
    $bill_result = $bill_stmt->get_result();
    
    if ($bill_result->num_rows == 0) {
        $bill_stmt->close();
        return false;
    }
    
    $bill = $bill_result->fetch_assoc();
    $bill_date = $bill['BILL_DATE'];
    $bill_stmt->close();
    
    // Get sale details
    $details_query = "SELECT ITEM_CODE, QTY FROM tblsaledetails 
                      WHERE BILL_NO = ? AND COMP_ID = ?";
    $details_stmt = $conn->prepare($details_query);
    $details_stmt->bind_param("si", $bill_no, $comp_id);
    $details_stmt->execute();
    $details_result = $details_stmt->get_result();
    
    $items = [];
    while ($row = $details_result->fetch_assoc()) {
        $items[] = $row;
    }
    $details_stmt->close();
    
    if (empty($items)) {
        return false;
    }
    
    // Get current stock column names
    $current_stock_column = "Current_Stock" . $comp_id;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Batch restore main stock
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
                       SET $current_stock_column = $current_stock_column + $case_statement 
                       WHERE ITEM_CODE IN ($placeholders)";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param($types, ...$params);
        $stock_stmt->execute();
        $stock_stmt->close();
        
        // 2. Reverse daily stock updates using batch function
        recalculateAndCascadeForItems($conn, $comp_id, $items, $bill_date);
        
        // 3. Delete sale details
        $delete_details_query = "DELETE FROM tblsaledetails WHERE BILL_NO = ? AND COMP_ID = ?";
        $delete_details_stmt = $conn->prepare($delete_details_query);
        $delete_details_stmt->bind_param("si", $bill_no, $comp_id);
        $delete_details_stmt->execute();
        $delete_details_stmt->close();
        
        // 4. Delete sale header
        $delete_header_query = "DELETE FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
        $delete_header_stmt = $conn->prepare($delete_header_query);
        $delete_header_stmt->bind_param("si", $bill_no, $comp_id);
        $delete_header_stmt->execute();
        $delete_header_stmt->close();
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error reversing stock for bill $bill_no: " . $e->getMessage());
        return false;
    }
}

// Function to renumber bills after deletion
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
        foreach ($bills as $bill) {
            $old_bill_no = $bill['BILL_NO'];
            $new_bill_no = "BL" . str_pad($counter, 4, '0', STR_PAD_LEFT);
            
            if ($old_bill_no !== $new_bill_no) {
                // Update bill header
                $update_header_query = "UPDATE tblsaleheader SET BILL_NO = ? WHERE BILL_NO = ? AND COMP_ID = ?";
                $update_header_stmt = $conn->prepare($update_header_query);
                $update_header_stmt->bind_param("ssi", $new_bill_no, $old_bill_no, $comp_id);
                $update_header_stmt->execute();
                $update_header_stmt->close();
                
                // Update bill details
                $update_details_query = "UPDATE tblsaledetails SET BILL_NO = ? WHERE BILL_NO = ? AND COMP_ID = ?";
                $update_details_stmt = $conn->prepare($update_details_query);
                $update_details_stmt->bind_param("ssi", $new_bill_no, $old_bill_no, $comp_id);
                $update_details_stmt->execute();
                $update_details_stmt->close();
            }
            
            $counter++;
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
            // Bulk delete
            $bill_nos = json_decode($_POST['bill_nos'], true);
            $deleted_count = 0;
            
            // Use optimized function if requested
            $use_optimized = $optimized;
            
            foreach ($bill_nos as $bill_no) {
                if ($use_optimized) {
                    if (reverseStockUpdatesOptimized($conn, $bill_no, $compID)) {
                        $deleted_count++;
                    }
                } else {
                    // Original function for backward compatibility
                    if (reverseStockUpdates($conn, $bill_no, $compID)) {
                        $deleted_count++;
                    }
                }
            }
            
            // Renumber bills after deletion
            if ($deleted_count > 0) {
                renumberBills($conn, $compID);
            }
            
            $response = [
                'success' => true,
                'message' => "Successfully deleted $deleted_count bill(s) and renumbered remaining bills."
            ];
            
        } elseif (isset($_POST['delete_by_date']) && isset($_POST['delete_date'])) {
            // Delete by date
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
            
            $deleted_count = 0;
            // Use optimized function if requested
            $use_optimized = $optimized;
            
            foreach ($bills_to_delete as $bill_no) {
                if ($use_optimized) {
                    if (reverseStockUpdatesOptimized($conn, $bill_no, $compID)) {
                        $deleted_count++;
                    }
                } else {
                    if (reverseStockUpdates($conn, $bill_no, $compID)) {
                        $deleted_count++;
                    }
                }
            }
            
            // Renumber bills after deletion
            if ($deleted_count > 0) {
                renumberBills($conn, $compID);
            }
            
            $response = [
                'success' => true,
                'message' => "Successfully deleted $deleted_count bill(s) for $delete_date and renumbered remaining bills."
            ];
            
        } elseif (isset($_POST['bill_no'])) {
            // Single bill delete
            $bill_no = $_POST['bill_no'];
            
            // Use optimized function if requested
            $use_optimized = $optimized;
            
            if ($use_optimized) {
                $success = reverseStockUpdatesOptimized($conn, $bill_no, $compID);
            } else {
                $success = reverseStockUpdates($conn, $bill_no, $compID);
            }
            
            if ($success) {
                // Renumber bills after deletion
                renumberBills($conn, $compID);
                
                $response = [
                    'success' => true,
                    'message' => "Bill $bill_no deleted successfully and bills renumbered."
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