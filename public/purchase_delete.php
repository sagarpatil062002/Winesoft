<?php
// purchase_delete.php - Enhanced Version with Batch Processing and Progress Tracking
session_start();

// Increase execution time for large batch deletions
set_time_limit(600); // 10 minutes

// Set JSON content type first to prevent any HTML output
header('Content-Type: application/json');

require_once "../config/db.php";

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/purchase_delete_error.log');

// ============================================================================
// VOUCHER NUMBER RENUMBERING FUNCTION (UNCHANGED)
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
// ENHANCED CASCADE FUNCTION (PRESERVES ORIGINAL LOGIC BUT MORE EFFICIENT)
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
// ENHANCED CASCADE TO FY END (PRESERVES ORIGINAL LOGIC)
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

// ============================================================================
// BATCH DELETE FUNCTION WITH PROGRESS TRACKING (PRESERVES CORE LOGIC)
// ============================================================================
function batchReversePurchaseStock($conn, $purchase_ids, $comp_id, &$progress = null) {
    deleteDebugLog("Starting BATCH reverse for " . count($purchase_ids) . " purchases");
    
    // Initialize progress tracking
    if ($progress === null) {
        $progress = [
            'total' => count($purchase_ids),
            'processed' => 0,
            'current_phase' => 'Aggregating data...',
            'current_item' => '',
            'percentage' => 0
        ];
    }
    
    // ============================================================================
    // STEP 1: Aggregate all purchase details into consolidated changes
    // ============================================================================
    $progress['current_phase'] = 'Aggregating purchase data...';
    
    $aggregated_items = [];
    $purchase_details_list = [];
    $tp_numbers = [];
    $purchase_dates = [];
    
    // Get all purchase details in batches to avoid memory issues
    $batch_size = 500;
    $total_purchases = count($purchase_ids);
    
    for ($i = 0; $i < $total_purchases; $i += $batch_size) {
        $batch_ids = array_slice($purchase_ids, $i, $batch_size);
        $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
        $types = str_repeat('i', count($batch_ids));
        
        $details_query = "
            SELECT 
                pd.ItemCode as ITEM_CODE,
                pd.TotBott as QTY,
                p.DATE as PURCHASE_DATE,
                p.TPNO,
                p.AUTO_TPNO,
                p.ID as PURCHASE_ID
            FROM tblpurchasedetails pd
            INNER JOIN tblpurchases p ON pd.PurchaseID = p.ID
            WHERE p.ID IN ($placeholders) AND p.CompID = ?
            ORDER BY p.DATE ASC
        ";
        
        $stmt = $conn->prepare($details_query);
        $params = array_merge($batch_ids, [$comp_id]);
        $stmt->bind_param($types . "i", ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $item_code = $row['ITEM_CODE'];
            $date = $row['PURCHASE_DATE'];
            $qty = (int)$row['QTY'];
            $key = $item_code . '|' . $date;
            
            if (!isset($aggregated_items[$key])) {
                $aggregated_items[$key] = [
                    'ITEM_CODE' => $item_code,
                    'DATE' => $date,
                    'QTY' => 0,
                    'ORIGINAL_PURCHASE_IDS' => []
                ];
            }
            
            $aggregated_items[$key]['QTY'] += $qty;
            $aggregated_items[$key]['ORIGINAL_PURCHASE_IDS'][] = $row['PURCHASE_ID'];
            $purchase_details_list[$row['PURCHASE_ID']] = true;
            
            // Store TP numbers for response
            $tp_no = $row['TPNO'] ?: $row['AUTO_TPNO'];
            if ($tp_no) {
                $tp_numbers[$row['PURCHASE_ID']] = $tp_no;
            }
            $purchase_dates[$date] = true;
        }
        $stmt->close();
        
        // Update progress
        $progress['processed'] = min($i + $batch_size, $total_purchases);
        $progress['percentage'] = round(($progress['processed'] / $total_purchases) * 20); // 20% for aggregation
    }
    
    deleteDebugLog("Aggregated " . count($aggregated_items) . " unique item-date combinations");
    
    // ============================================================================
    // STEP 2: Update main stock in bulk (same logic, aggregated)
    // ============================================================================
    $progress['current_phase'] = 'Updating main stock levels...';
    
    $current_stock_column = "CURRENT_STOCK" . $comp_id;
    $stock_reductions = [];
    
    foreach ($aggregated_items as $item) {
        $item_code = $item['ITEM_CODE'];
        $qty = $item['QTY'];
        
        if (!isset($stock_reductions[$item_code])) {
            $stock_reductions[$item_code] = 0;
        }
        $stock_reductions[$item_code] += $qty;
    }
    
    // Apply bulk stock updates
    if (checkTableExists($conn, "tblitem_stock")) {
        $stock_count = count($stock_reductions);
        $current_stock_idx = 0;
        
        foreach ($stock_reductions as $item_code => $total_qty) {
            $current_stock_idx++;
            $progress['current_item'] = $item_code;
            $progress['percentage'] = 20 + round(($current_stock_idx / $stock_count) * 10); // 20-30%
            
            $update_stock = "UPDATE tblitem_stock 
                            SET $current_stock_column = GREATEST(0, $current_stock_column - ?),
                                LAST_UPDATED = NOW()
                            WHERE ITEM_CODE = ?";
            $stock_stmt = $conn->prepare($update_stock);
            $stock_stmt->bind_param("is", $total_qty, $item_code);
            $stock_stmt->execute();
            deleteDebugLog("Bulk stock update for $item_code: -$total_qty");
            $stock_stmt->close();
        }
    }
    
    // ============================================================================
    // STEP 3: Group by month for daily stock updates
    // ============================================================================
    $progress['current_phase'] = 'Grouping by month for daily stock updates...';
    $progress['percentage'] = 30;
    
    $monthly_updates = []; // Structure: [month][item_code][day] => total_qty
    
    foreach ($aggregated_items as $item) {
        $date = $item['DATE'];
        $day_num = (int)date('d', strtotime($date));
        $month = date('Y-m', strtotime($date));
        $item_code = $item['ITEM_CODE'];
        $total_qty = $item['QTY'];
        
        if (!isset($monthly_updates[$month])) {
            $monthly_updates[$month] = [];
        }
        if (!isset($monthly_updates[$month][$item_code])) {
            $monthly_updates[$month][$item_code] = [];
        }
        if (!isset($monthly_updates[$month][$item_code][$day_num])) {
            $monthly_updates[$month][$item_code][$day_num] = 0;
        }
        $monthly_updates[$month][$item_code][$day_num] += $total_qty;
    }
    
    // ============================================================================
    // STEP 4: Process daily stock updates by month (preserves core logic)
    // ============================================================================
    $progress['current_phase'] = 'Processing daily stock updates...';
    
    $conn->begin_transaction();
    
    try {
        $total_months = count($monthly_updates);
        $current_month_idx = 0;
        $monthly_closings = [];
        
        foreach ($monthly_updates as $month => $items_by_item) {
            $current_month_idx++;
            $progress['percentage'] = 30 + round(($current_month_idx / $total_months) * 50); // 30-80%
            $progress['current_item'] = "Month: $month";
            
            $month_date = $month . '-01';
            $is_previous_fy = isPreviousFinancialYear($month_date);
            
            // Determine which daily stock table to use
            if ($is_previous_fy) {
                $month_num = (int)date('n', strtotime($month));
                $year = (int)date('Y', strtotime($month));
                $daily_table = getArchiveTableName($conn, $comp_id, $month_num, $year);
            } else {
                $daily_table = "tbldailystock_" . $comp_id;
            }
            
            if (!checkTableExists($conn, $daily_table)) {
                deleteDebugLog("Table $daily_table doesn't exist, skipping");
                continue;
            }
            
            // Verify table has at least some day columns (just log, don't skip)
            $sample_day = 1;
            $sample_col = "DAY_" . str_pad($sample_day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
            $check_col = $conn->query("SHOW COLUMNS FROM $daily_table LIKE '$sample_col'");
            if (!$check_col || $check_col->num_rows == 0) {
                deleteDebugLog("Warning: Table $daily_table may have different column structure. Trying to proceed anyway...");
            }
            
            deleteDebugLog("Processing month: $month in table: $daily_table");
            
            // Process each item in this month
            $item_count = count($items_by_item);
            $current_item_idx = 0;
            
            foreach ($items_by_item as $item_code => $days) {
                $current_item_idx++;
                $progress['current_item'] = "Item: $item_code (Month: $month)";
                
                // Get the earliest day that needs modification
                $min_day = min(array_keys($days));
                $total_reduction = array_sum($days);
                
                deleteDebugLog("Processing item $item_code in month $month", [
                    'affected_days' => array_keys($days),
                    'total_reduction' => $total_reduction,
                    'min_day' => $min_day
                ]);
                
                // Build CASE UPDATE for all days at once - ensure zero-padding
                $case_statements = [];
                foreach ($days as $day_num => $qty) {
                    $day_int = (int)$day_num;
                    $day_str = str_pad($day_int, 2, '0', STR_PAD_LEFT);
                    $day_col = "DAY_{$day_str}_PURCHASE";
                    // Ensure quantity is treated as integer
                    $qty_int = (int)$qty;
                    $case_statements[] = "`$day_col` = GREATEST(0, COALESCE(`$day_col`, 0) - $qty_int)";
                }
                
                $update_sql = "UPDATE $daily_table 
                              SET " . implode(", ", $case_statements) . "
                              WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ss", $item_code, $month);
                $update_stmt->execute();
                $update_stmt->close();
                
                // Use original cascade function (preserves core logic)
                $last_closing = cascadeDailyStock($conn, $daily_table, $item_code, $month, $min_day);
                
                deleteDebugLog("Cascade completed for $item_code in $month, last closing: $last_closing");
                
                // Store for potential FY end cascade
                if (!isset($monthly_closings[$month])) {
                    $monthly_closings[$month] = [];
                }
                $monthly_closings[$month][$item_code] = $last_closing;
            }
        }
        
        // ============================================================================
        // STEP 5: Process FY end cascades (preserves core logic)
        // ============================================================================
        $progress['current_phase'] = 'Processing financial year end cascades...';
        $progress['percentage'] = 80;
        
        foreach ($monthly_updates as $month => $items_by_item) {
            $month_date = $month . '-01';
            $is_previous_fy = isPreviousFinancialYear($month_date);
            
            if ($is_previous_fy) {
                foreach ($items_by_item as $item_code => $days) {
                    $progress['current_item'] = "FY End Cascade: $item_code";
                    $total_reduction = array_sum($days);
                    $min_day = min(array_keys($days));
                    
                    if (isset($monthly_closings[$month][$item_code])) {
                        // Use original FY end cascade function
                        cascadeToFinancialYearEnd($conn, $comp_id, $item_code, $month_date, $total_reduction, $monthly_closings[$month][$item_code]);
                    }
                }
            }
        }
        
        // ============================================================================
        // STEP 6: Delete all purchase details and headers in bulk
        // ============================================================================
        $progress['current_phase'] = 'Deleting purchase records...';
        $progress['percentage'] = 90;
        
        // Bulk delete purchase details
        $all_purchase_ids = array_keys($purchase_details_list);
        $placeholders = implode(',', array_fill(0, count($all_purchase_ids), '?'));
        $types = str_repeat('i', count($all_purchase_ids));
        
        $delete_details = "DELETE FROM tblpurchasedetails WHERE PurchaseID IN ($placeholders)";
        $del_stmt = $conn->prepare($delete_details);
        $del_stmt->bind_param($types, ...$all_purchase_ids);
        $del_stmt->execute();
        deleteDebugLog("Deleted " . $del_stmt->affected_rows . " purchase details");
        $del_stmt->close();
        
        // Bulk delete purchase headers
        $delete_header = "DELETE FROM tblpurchases WHERE ID IN ($placeholders) AND CompID = ?";
        $del_stmt = $conn->prepare($delete_header);
        $params = array_merge($all_purchase_ids, [$comp_id]);
        $del_stmt->bind_param($types . "i", ...$params);
        $del_stmt->execute();
        deleteDebugLog("Deleted " . $del_stmt->affected_rows . " purchase headers");
        $del_stmt->close();
        
        // ============================================================================
        // STEP 7: Renumber voucher numbers once
        // ============================================================================
        $progress['current_phase'] = 'Renumbering voucher numbers...';
        $progress['percentage'] = 95;
        
        renumberVoucherNumbers($conn, $comp_id);
        
        $conn->commit();
        
        $progress['percentage'] = 100;
        $progress['current_phase'] = 'Completed!';
        
        return [
            'success' => true,
            'deleted_count' => count($all_purchase_ids),
            'tp_numbers_freed' => array_values($tp_numbers),
            'affected_items' => count($stock_reductions),
            'affected_months' => count($monthly_updates),
            'message' => "Successfully deleted " . count($all_purchase_ids) . " purchases"
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        deleteDebugLog("Batch deletion failed: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// ============================================================================
// ORIGINAL SINGLE DELETE FUNCTION (PRESERVED AS IS)
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
    
    // Get purchase details with TotBott
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
            'QTY' => (int)$row['QTY']
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
        // Update main stock
        $current_stock_column = "CURRENT_STOCK" . $comp_id;
        
        if (checkTableExists($conn, "tblitem_stock")) {
            $check_col_query = "SHOW COLUMNS FROM tblitem_stock LIKE '$current_stock_column'";
            $check_col_result = $conn->query($check_col_query);
            
            if ($check_col_result && $check_col_result->num_rows > 0) {
                foreach ($items as $item) {
                    $check_stock_query = "SELECT $current_stock_column as current_stock 
                                         FROM tblitem_stock WHERE ITEM_CODE = ?";
                    $check_stmt = $conn->prepare($check_stock_query);
                    $check_stmt->bind_param("s", $item['ITEM_CODE']);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $stock_row = $check_result->fetch_assoc();
                        $current_stock = (int)$stock_row['current_stock'];
                        $subtract_qty = min($item['QTY'], $current_stock);
                        
                        if ($subtract_qty > 0) {
                            $update_stock = "UPDATE tblitem_stock 
                                            SET $current_stock_column = $current_stock_column - ?,
                                                LAST_UPDATED = NOW()
                                            WHERE ITEM_CODE = ?";
                            $stock_stmt = $conn->prepare($update_stock);
                            $stock_stmt->bind_param("is", $subtract_qty, $item['ITEM_CODE']);
                            $stock_stmt->execute();
                            $stock_stmt->close();
                        }
                    }
                    $check_stmt->close();
                }
            }
        }
        
        // Update daily stock
        $day_num = (int)date('d', strtotime($purchase_date));
        $stk_month = date('Y-m', strtotime($purchase_date));
        $day_str = sprintf('%02d', $day_num);
        
        $purchase_month = (int)date('n', strtotime($purchase_date));
        $purchase_year = (int)date('Y', strtotime($purchase_date));
        
        if ($is_previous_fy) {
            $daily_table = getArchiveTableName($conn, $comp_id, $purchase_month, $purchase_year);
        } else {
            $daily_table = "tbldailystock_" . $comp_id;
        }
        
        $purchase_month_closing = null;
        
        if (checkTableExists($conn, $daily_table)) {
            foreach ($items as $item) {
                $check_exists = "SELECT COUNT(*) as cnt FROM $daily_table 
                                WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                $check_stmt = $conn->prepare($check_exists);
                $check_stmt->bind_param("ss", $item['ITEM_CODE'], $stk_month);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $exists = $check_result->fetch_assoc()['cnt'] > 0;
                $check_stmt->close();
                
                if ($exists) {
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
                    
                    $subtract_qty = min($item['QTY'], $current_purchase);
                    
                    if ($subtract_qty > 0) {
                        $update_purchase = "UPDATE $daily_table 
                                           SET $purchase_col = GREATEST(0, $purchase_col - ?)
                                           WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                        
                        $update_stmt = $conn->prepare($update_purchase);
                        $update_stmt->bind_param("iss", $subtract_qty, $item['ITEM_CODE'], $stk_month);
                        $update_stmt->execute();
                        $update_stmt->close();
                        
                        $last_closing = cascadeDailyStock($conn, $daily_table, $item['ITEM_CODE'], $stk_month, $day_num);
                        
                        if ($purchase_month_closing === null) {
                            $purchase_month_closing = $last_closing;
                        }
                    }
                }
            }
        }
        
        if ($is_previous_fy && $purchase_month_closing !== null) {
            foreach ($items as $item) {
                cascadeToFinancialYearEnd($conn, $comp_id, $item['ITEM_CODE'], $purchase_date, $item['QTY'], $purchase_month_closing);
            }
        }
        
        // Delete purchase details and header
        if (checkTableExists($conn, "tblpurchasedetails")) {
            $delete_details = "DELETE FROM tblpurchasedetails WHERE PurchaseID = ?";
            $del_details_stmt = $conn->prepare($delete_details);
            $del_details_stmt->bind_param("i", $purchase_id);
            $del_details_stmt->execute();
            $del_details_stmt->close();
        }
        
        if (checkTableExists($conn, "tblpurchases")) {
            $delete_header = "DELETE FROM tblpurchases WHERE ID = ? AND CompID = ?";
            $del_header_stmt = $conn->prepare($delete_header);
            $del_header_stmt->bind_param("ii", $purchase_id, $comp_id);
            $del_header_stmt->execute();
            $del_header_stmt->close();
        }
        
        renumberVoucherNumbers($conn, $comp_id);
        
        $conn->commit();
        
        return [
            'success' => true,
            'tp_no' => $tp_no,
            'item_count' => count($items),
            'message' => "Purchase deleted successfully. TP number: $tp_no"
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
// MAIN PROCESSING LOGIC WITH PROGRESS TRACKING
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Handle progress polling
        if (isset($_POST['check_progress']) && isset($_POST['session_key'])) {
            $session_key = $_POST['session_key'];
            if (isset($_SESSION['delete_progress_' . $session_key])) {
                echo json_encode($_SESSION['delete_progress_' . $session_key]);
            } else {
                echo json_encode(['status' => 'not_found']);
            }
            exit;
        }
        
        if (isset($_POST['bulk_delete']) && isset($_POST['purchase_ids'])) {
            // Bulk delete with progress tracking
            $purchase_ids = json_decode($_POST['purchase_ids'], true);
            
            if (!is_array($purchase_ids) || empty($purchase_ids)) {
                throw new Exception('No purchase IDs provided');
            }
            
            // Generate unique session key for this deletion job
            $session_key = uniqid('delete_');
            $progress = [
                'status' => 'processing',
                'total' => count($purchase_ids),
                'processed' => 0,
                'current_phase' => 'Starting...',
                'current_item' => '',
                'percentage' => 0,
                'session_key' => $session_key
            ];
            
            $_SESSION['delete_progress_' . $session_key] = $progress;
            
            // Start background processing (using output buffering to send progress)
            if (function_exists('fastcgi_finish_request')) {
                // For FastCGI, we can close connection and continue processing
                session_write_close();
                fastcgi_finish_request();
            }
            
            // Process the deletion
            $result = batchReversePurchaseStock($conn, $purchase_ids, $compID, $progress);
            
            // Update final progress
            $progress['status'] = $result['success'] ? 'completed' : 'failed';
            $progress['result'] = $result;
            $progress['percentage'] = 100;
            $_SESSION['delete_progress_' . $session_key] = $progress;
            
            // For AJAX requests that expect immediate response, return session key
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') {
                echo json_encode([
                    'success' => true,
                    'async' => true,
                    'session_key' => $session_key,
                    'message' => 'Deletion started. Check progress using session key.'
                ]);
            } else {
                echo json_encode($result);
            }
            exit;
            
        } elseif (isset($_POST['purchase_id'])) {
            // Single purchase delete - use original function
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