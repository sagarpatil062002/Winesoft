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

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '1024M');

// Function to get daily stock table for a date
function getDailyStockTableForDate($comp_id, $date) {
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

// Function to recalculate closing balance for a specific day
function recalculateClosingForDay($conn, $table_name, $item_code, $stk_month, $day) {
    $day_num = sprintf('%02d', $day);
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    $sales_column = "DAY_{$day_num}_SALES";
    $closing_column = "DAY_{$day_num}_CLOSING";
    
    // Get current values
    $query = "SELECT $opening_column, $purchase_column, $sales_column 
              FROM $table_name 
              WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $item_code, $stk_month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $opening = $row[$opening_column] ?? 0;
        $purchase = $row[$purchase_column] ?? 0;
        $sales = $row[$sales_column] ?? 0;
        
        // Calculate new closing: closing = opening + purchase - sales
        $new_closing = $opening + $purchase - $sales;
        
        // Update closing column
        $update_query = "UPDATE $table_name 
                         SET $closing_column = ?,
                             LAST_UPDATED = CURRENT_TIMESTAMP 
                         WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("dss", $new_closing, $item_code, $stk_month);
        $update_stmt->execute();
        $update_stmt->close();
        
        return $new_closing;
    }
    $stmt->close();
    return 0;
}

// Function to cascade updates through all days from start day
function cascadeDailyStockFromDay($conn, $table_name, $item_code, $stk_month, $start_day) {
    for ($day = $start_day; $day <= 31; $day++) {
        $day_num = sprintf('%02d', $day);
        $closing_column = "DAY_{$day_num}_CLOSING";
        
        // Check if column exists
        $check_column = "SHOW COLUMNS FROM $table_name LIKE '$closing_column'";
        $column_result = $conn->query($check_column);
        if ($column_result->num_rows == 0) break;
        
        // Recalculate closing for this day
        $current_closing = recalculateClosingForDay($conn, $table_name, $item_code, $stk_month, $day);
        
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
                $update_next_stmt->bind_param("dss", $current_closing, $item_code, $stk_month);
                $update_next_stmt->execute();
                $update_next_stmt->close();
            }
        }
    }
}

// Function to cascade stock to next month
function cascadeStockToNextMonth($conn, $comp_id, $item_code, $closing_stock, $target_month) {
    $target_table = getDailyStockTableForDate($comp_id, $target_month . '-01');
    
    // Check if target table exists
    $check_table = "SHOW TABLES LIKE '$target_table'";
    if ($conn->query($check_table)->num_rows == 0) {
        return; // Table doesn't exist, nothing to update
    }
    
    // Update opening stock for next month
    $update_query = "UPDATE $target_table 
                    SET DAY_01_OPEN = ?,
                        LAST_UPDATED = CURRENT_TIMESTAMP 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("dss", $closing_stock, $target_month, $item_code);
    $update_stmt->execute();
    $update_stmt->close();
    
    // Recalculate the entire next month
    cascadeDailyStockFromDay($conn, $target_table, $item_code, $target_month, 1);
}

// Function to reverse stock updates for a bill
function reverseStockUpdates($conn, $bill_no, $comp_id) {
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
    $opening_stock_column = "Opening_Stock" . $comp_id;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // For each item in the bill, reverse the stock updates
        foreach ($items as $item) {
            $item_code = $item['ITEM_CODE'];
            $qty = $item['QTY'];
            
            // 1. Restore main stock (add back the sold quantity)
            $stock_query = "UPDATE tblitem_stock 
                           SET $current_stock_column = $current_stock_column + ? 
                           WHERE ITEM_CODE = ?";
            $stock_stmt = $conn->prepare($stock_query);
            $stock_stmt->bind_param("ds", $qty, $item_code);
            $stock_stmt->execute();
            $stock_stmt->close();
            
            // 2. Reverse daily stock updates
            $day_num = date('d', strtotime($bill_date));
            $stk_month = date('Y-m', strtotime($bill_date));
            $daily_table = getDailyStockTableForDate($comp_id, $bill_date);
            
            // Check if table exists
            $check_table = "SHOW TABLES LIKE '$daily_table'";
            if ($conn->query($check_table)->num_rows > 0) {
                // Subtract sales from the specific day
                $sales_column = "DAY_" . sprintf('%02d', $day_num) . "_SALES";
                $closing_column = "DAY_" . sprintf('%02d', $day_num) . "_CLOSING";
                
                // Check if columns exist
                $check_sales = "SHOW COLUMNS FROM $daily_table LIKE '$sales_column'";
                $check_closing = "SHOW COLUMNS FROM $daily_table LIKE '$closing_column'";
                
                if ($conn->query($check_sales)->num_rows > 0 && $conn->query($check_closing)->num_rows > 0) {
                    // Update sales column: sales = sales - qty
                    $update_sales_query = "UPDATE $daily_table 
                                          SET $sales_column = $sales_column - ?,
                                              LAST_UPDATED = CURRENT_TIMESTAMP 
                                          WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                    $update_sales_stmt = $conn->prepare($update_sales_query);
                    $update_sales_stmt->bind_param("dss", $qty, $item_code, $stk_month);
                    $update_sales_stmt->execute();
                    $update_sales_stmt->close();
                    
                    // Recalculate closing for this day
                    $current_closing = recalculateClosingForDay($conn, $daily_table, $item_code, $stk_month, $day_num);
                    
                    // Cascade updates through remaining days of the month
                    cascadeDailyStockFromDay($conn, $daily_table, $item_code, $stk_month, $day_num + 1);
                    
                    // Check if this is the last day of the month
                    $last_day_of_month = date('t', strtotime($bill_date));
                    if ($day_num == $last_day_of_month) {
                        // Cascade to next month
                        $next_month = date('Y-m', strtotime($bill_date . ' +1 month'));
                        cascadeStockToNextMonth($conn, $comp_id, $item_code, $current_closing, $next_month);
                    }
                    
                    // Cascade through all subsequent months up to current month
                    $current_month = date('Y-m');
                    $sale_month = $stk_month;
                    
                    while ($sale_month < $current_month) {
                        $next_month_date = date('Y-m-d', strtotime($sale_month . '-01 +1 month'));
                        $next_month = date('Y-m', strtotime($next_month_date));
                        
                        $next_table = getDailyStockTableForDate($comp_id, $next_month . '-01');
                        if ($conn->query("SHOW TABLES LIKE '$next_table'")->num_rows > 0) {
                            // Add back the quantity to opening stock of next month
                            $update_next_opening = "UPDATE $next_table 
                                                   SET DAY_01_OPEN = DAY_01_OPEN + ?,
                                                       LAST_UPDATED = CURRENT_TIMESTAMP 
                                                   WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                            $update_next_stmt = $conn->prepare($update_next_opening);
                            $update_next_stmt->bind_param("dss", $qty, $item_code, $next_month);
                            $update_next_stmt->execute();
                            $update_next_stmt->close();
                            
                            // Recalculate the next month
                            cascadeDailyStockFromDay($conn, $next_table, $item_code, $next_month, 1);
                        }
                        
                        $sale_month = $next_month;
                    }
                    
                    // Update current month's opening stock if needed
                    if ($stk_month < $current_month) {
                        $current_table = "tbldailystock_" . $comp_id;
                        if ($conn->query("SHOW TABLES LIKE '$current_table'")->num_rows > 0) {
                            $update_current_opening = "UPDATE $current_table 
                                                     SET DAY_01_OPEN = DAY_01_OPEN + ?,
                                                         LAST_UPDATED = CURRENT_TIMESTAMP 
                                                     WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                            $update_current_stmt = $conn->prepare($update_current_opening);
                            $update_current_stmt->bind_param("dss", $qty, $item_code, $current_month);
                            $update_current_stmt->execute();
                            $update_current_stmt->close();
                            
                            cascadeDailyStockFromDay($conn, $current_table, $item_code, $current_month, 1);
                        }
                    }
                }
            }
        }
        
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
            
            foreach ($bill_nos as $bill_no) {
                if (reverseStockUpdates($conn, $bill_no, $compID)) {
                    $deleted_count++;
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
            foreach ($bills_to_delete as $bill_no) {
                if (reverseStockUpdates($conn, $bill_no, $compID)) {
                    $deleted_count++;
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
            
            if (reverseStockUpdates($conn, $bill_no, $compID)) {
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
?>