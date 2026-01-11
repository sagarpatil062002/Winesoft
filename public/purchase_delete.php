<?php
// purchase_delete_optimized.php - Complete Working Version
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
    
    // Prepare column names - using PURCHASE columns instead of SALES
    $purchase_column = "DAY_" . sprintf('%02d', $day_num) . "_PURCHASE";
    $closing_column = "DAY_" . sprintf('%02d', $day_num) . "_CLOSING";
    $opening_column = "DAY_" . sprintf('%02d', $day_num) . "_OPEN";
    
    // Check if columns exist
    $check_purchase = "SHOW COLUMNS FROM $daily_table LIKE '$purchase_column'";
    $check_closing = "SHOW COLUMNS FROM $daily_table LIKE '$closing_column'";
    $check_opening = "SHOW COLUMNS FROM $daily_table LIKE '$opening_column'";
    
    if ($conn->query($check_purchase)->num_rows == 0 || 
        $conn->query($check_closing)->num_rows == 0 ||
        $conn->query($check_opening)->num_rows == 0) {
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
    
    // Update purchase quantities in batch
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
    
    // Batch recalculate closing for all items
    foreach ($items_data as $item) {
        $item_code = $item['ITEM_CODE'];
        
        // Get current values
        $query = "SELECT 
                    $opening_column as opening,
                    $purchase_column as purchase,
                    DAY_" . sprintf('%02d', $day_num) . "_SALES as sales
                  FROM $daily_table 
                  WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $item_code, $stk_month);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $opening_val = $row['opening'] ?? 0;
            $purchase_val = $row['purchase'] ?? 0;
            $sales_val = $row['sales'] ?? 0;
            
            // Calculate new closing: opening + purchase - sales
            $new_closing = $opening_val + $purchase_val - $sales_val;
            
            // Update closing column
            $update_closing_query = "UPDATE $daily_table 
                                   SET $closing_column = GREATEST(0, ?),
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

// Optimized cascade function (same as delete_bill.php)
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
            
            // Recalculate closing for this next day
            $next_purchase_col = "DAY_" . sprintf('%02d', $day + 1) . "_PURCHASE";
            $next_sales_col = "DAY_" . sprintf('%02d', $day + 1) . "_SALES";
            $next_closing_col = "DAY_" . sprintf('%02d', $day + 1) . "_CLOSING";
            
            $recalc_query = "UPDATE $table_name 
                           SET $next_closing_col = GREATEST(0, $next_opening_column + $next_purchase_col - $next_sales_col),
                               LAST_UPDATED = CURRENT_TIMESTAMP 
                           WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $recalc_stmt = $conn->prepare($recalc_query);
            $recalc_stmt->bind_param("ss", $item_code, $stk_month);
            $recalc_stmt->execute();
            $recalc_stmt->close();
        }
        $get_stmt->close();
    }
}

// Optimized: Function to reverse stock updates for a purchase
function reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $comp_id) {
    // Get purchase details - using SQL schema from your file
    $purchase_query = "SELECT DATE, TPNO, AUTO_TPNO FROM tblpurchases 
                      WHERE ID = ? AND CompID = ?";
    $purchase_stmt = $conn->prepare($purchase_query);
    $purchase_stmt->bind_param("ii", $purchase_id, $comp_id);
    $purchase_stmt->execute();
    $purchase_result = $purchase_stmt->get_result();
    
    if ($purchase_result->num_rows == 0) {
        $purchase_stmt->close();
        return false;
    }
    
    $purchase = $purchase_result->fetch_assoc();
    $purchase_date = $purchase['DATE'];
    $tp_no = $purchase['TPNO'] ?: $purchase['AUTO_TPNO'];
    $purchase_stmt->close();
    
    // Get purchase details - using column names from your SQL schema
    // Note: Your table uses ItemCode not ITEM_CODE, and Cases/Bottles instead of QTY
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
        return false;
    }
    
    // Get current stock column name
    $current_stock_column = "Current_Stock" . $comp_id;
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // 1. Batch reduce main stock (opposite of sales - we're removing purchase)
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
        $stock_stmt->execute();
        $stock_stmt->close();
        
        // 2. Reverse daily stock updates using batch function (for PURCHASE)
        recalculateAndCascadeForPurchaseItems($conn, $comp_id, $items, $purchase_date);
        
        // 3. Delete purchase details
        $delete_details_query = "DELETE FROM tblpurchasedetails WHERE PurchaseID = ?";
        $delete_details_stmt = $conn->prepare($delete_details_query);
        $delete_details_stmt->bind_param("i", $purchase_id);
        $delete_details_stmt->execute();
        $delete_details_stmt->close();
        
        // 4. Delete purchase header
        $delete_header_query = "DELETE FROM tblpurchases WHERE ID = ? AND CompID = ?";
        $delete_header_stmt = $conn->prepare($delete_header_query);
        $delete_header_stmt->bind_param("ii", $purchase_id, $comp_id);
        $delete_header_stmt->execute();
        $delete_header_stmt->close();
        
        // 5. Update any related records (optional)
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
            'item_count' => count($items)
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

// Main processing logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        if (isset($_POST['bulk_delete']) && isset($_POST['purchase_ids'])) {
            // Bulk delete
            $purchase_ids = json_decode($_POST['purchase_ids'], true);
            
            if (!is_array($purchase_ids) || empty($purchase_ids)) {
                throw new Exception("No purchase IDs provided");
            }
            
            // Limit bulk operations for performance
            $max_bulk_delete = 50;
            if (count($purchase_ids) > $max_bulk_delete) {
                throw new Exception("Maximum $max_bulk_delete purchases can be deleted at once");
            }
            
            $deleted_count = 0;
            $failed_count = 0;
            $results = [];
            
            // Use optimized function if requested
            $use_optimized = $optimized;
            
            foreach ($purchase_ids as $purchase_id) {
                $purchase_id = (int)$purchase_id;
                
                if ($purchase_id <= 0) {
                    $failed_count++;
                    $results[] = ['success' => false, 'error' => 'Invalid purchase ID'];
                    continue;
                }
                
                if ($use_optimized) {
                    $result = reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $compID);
                } else {
                    // For backward compatibility - use original function if needed
                    $result = reversePurchaseStockUpdates($conn, $purchase_id, $compID);
                }
                
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
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to fetch purchases for the date");
            }
            
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
                $deleted_count = 0;
                $failed_count = 0;
                $results = [];
                
                // Use optimized function if requested
                $use_optimized = $optimized;
                
                foreach ($purchases_to_delete as $purchase_id) {
                    if ($use_optimized) {
                        $result = reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $compID);
                    } else {
                        $result = reversePurchaseStockUpdates($conn, $purchase_id, $compID);
                    }
                    
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
            
            // Use optimized function if requested
            $use_optimized = $optimized;
            
            if ($use_optimized) {
                $result = reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $compID);
            } else {
                $result = reversePurchaseStockUpdates($conn, $purchase_id, $compID);
            }
            
            if ($result['success'] ?? false) {
                $response = [
                    'success' => true,
                    'message' => "Purchase deleted successfully",
                    'tp_no' => $result['tp_no'] ?? '',
                    'item_count' => $result['item_count'] ?? 0
                ];
            } else {
                $response = [
                    'success' => false,
                    'message' => $result['error'] ?? "Failed to delete purchase"
                ];
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

// Original function for backward compatibility (called when not using optimized)
function reversePurchaseStockUpdates($conn, $purchase_id, $comp_id) {
    // This is the original function - kept for backward compatibility
    // It's called when $optimized is false
    return reversePurchaseStockUpdatesOptimized($conn, $purchase_id, $comp_id);
}
?>