<?php
// purchase_delete.php - Fixed Version with Better Error Handling
session_start();
require_once "../config/db.php";

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

// Check if tblpurchases table exists
function checkTableExists($conn, $table_name) {
    $result = $conn->query("SHOW TABLES LIKE '$table_name'");
    return $result && $result->num_rows > 0;
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

// Simplified cascade function that works
function cascadeDailyStock($conn, $table_name, $item_code, $stk_month, $start_day) {
    deleteDebugLog("Cascading stock", [
        'table' => $table_name,
        'item_code' => $item_code,
        'stk_month' => $stk_month,
        'start_day' => $start_day
    ]);
    
    // Get current closing for start_day
    $day_str = sprintf('%02d', $start_day);
    $get_closing = "SELECT DAY_{$day_str}_CLOSING as closing FROM $table_name 
                   WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $stmt = $conn->prepare($get_closing);
    $stmt->bind_param("ss", $item_code, $stk_month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $stmt->close();
        return;
    }
    
    $row = $result->fetch_assoc();
    $current_closing = $row['closing'] ?? 0;
    $stmt->close();
    
    // Cascade through remaining days
    for ($day = $start_day + 1; $day <= 31; $day++) {
        $day_str = sprintf('%02d', $day);
        $prev_day_str = sprintf('%02d', $day - 1);
        
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
            $purchase = $val_row['purchase'] ?? 0;
            $sales = $val_row['sales'] ?? 0;
            
            // Update this day
            $update = "UPDATE $table_name 
                      SET $opening_col = ?,
                          $closing_col = ? + $purchase - $sales
                      WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            
            $update_stmt = $conn->prepare($update);
            $update_stmt->bind_param("ddss", $current_closing, $current_closing, $item_code, $stk_month);
            $update_stmt->execute();
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
                $current_closing = $new_row['closing'] ?? 0;
            }
            $new_stmt->close();
        }
        $val_stmt->close();
    }
}

// Function to reverse purchase stock updates
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
    
    deleteDebugLog("Purchase found", [
        'date' => $purchase_date,
        'tp_no' => $tp_no
    ]);
    
    // Get purchase details
    $details_query = "SELECT ItemCode as ITEM_CODE, 
                             Cases, 
                             Bottles,
                             BottlesPerCase,
                             (Cases * BottlesPerCase + Bottles) as QTY
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
            'QTY' => (float)$row['QTY']
        ];
    }
    $details_stmt->close();
    
    if (empty($items)) {
        deleteDebugLog("No items found for purchase ID: " . $purchase_id);
    } else {
        deleteDebugLog("Found " . count($items) . " items");
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Update main stock if table exists
        $current_stock_column = "Current_Stock" . $comp_id;
        
        if (checkTableExists($conn, "tblitem_stock")) {
            foreach ($items as $item) {
                $update_stock = "UPDATE tblitem_stock 
                                SET $current_stock_column = GREATEST(0, $current_stock_column - ?)
                                WHERE ITEM_CODE = ?";
                $stock_stmt = $conn->prepare($update_stock);
                $stock_stmt->bind_param("ds", $item['QTY'], $item['ITEM_CODE']);
                
                if (!$stock_stmt->execute()) {
                    deleteDebugLog("Stock update failed: " . $stock_stmt->error);
                }
                $stock_stmt->close();
            }
            deleteDebugLog("Main stock updated");
        }
        
        // 2. Update daily stock
        $daily_table = getDailyStockTableForDate($conn, $comp_id, $purchase_date);
        
        if (checkTableExists($conn, $daily_table)) {
            $day_num = date('d', strtotime($purchase_date));
            $stk_month = date('Y-m', strtotime($purchase_date));
            $day_str = sprintf('%02d', $day_num);
            
            deleteDebugLog("Updating daily stock", [
                'table' => $daily_table,
                'day' => $day_str,
                'month' => $stk_month
            ]);
            
            // Update each item
            foreach ($items as $item) {
                // Check if record exists
                $check_exists = "SELECT COUNT(*) as cnt FROM $daily_table 
                                WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                $check_stmt = $conn->prepare($check_exists);
                $check_stmt->bind_param("ss", $item['ITEM_CODE'], $stk_month);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $exists = $check_result->fetch_assoc()['cnt'] > 0;
                $check_stmt->close();
                
                if ($exists) {
                    // Reduce purchase for this day
                    $purchase_col = "DAY_{$day_str}_PURCHASE";
                    $update_purchase = "UPDATE $daily_table 
                                       SET $purchase_col = GREATEST(0, $purchase_col - ?)
                                       WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                    
                    $update_stmt = $conn->prepare($update_purchase);
                    $update_stmt->bind_param("dss", $item['QTY'], $item['ITEM_CODE'], $stk_month);
                    
                    if (!$update_stmt->execute()) {
                        deleteDebugLog("Purchase update failed: " . $update_stmt->error);
                    }
                    $update_stmt->close();
                    
                    // Recalculate closing for this day
                    $opening_col = "DAY_{$day_str}_OPEN";
                    $sales_col = "DAY_{$day_str}_SALES";
                    $closing_col = "DAY_{$day_str}_CLOSING";
                    
                    $recalc = "UPDATE $daily_table 
                              SET $closing_col = GREATEST(0, $opening_col + $purchase_col - $sales_col)
                              WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                    
                    $recalc_stmt = $conn->prepare($recalc);
                    $recalc_stmt->bind_param("ss", $item['ITEM_CODE'], $stk_month);
                    
                    if (!$recalc_stmt->execute()) {
                        deleteDebugLog("Recalc failed: " . $recalc_stmt->error);
                    }
                    $recalc_stmt->close();
                    
                    // Cascade to subsequent days
                    cascadeDailyStock($conn, $daily_table, $item['ITEM_CODE'], $stk_month, $day_num);
                }
            }
            deleteDebugLog("Daily stock updated");
        }
        
        // 3. Delete purchase details
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
        
        // 4. Delete purchase header
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
        
        // Commit transaction
        $conn->commit();
        deleteDebugLog("Transaction committed successfully");
        
        $message = 'Purchase deleted successfully';
        if ($tp_no) {
            $message .= ". TP number: $tp_no";
        }
        
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

// Main processing logic
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