<?php
// purchase_delete.php - Fixed Version with Better Error Handling
// Includes separate logic for current and previous financial years
// Includes VOC_NO renumbering based on TP_DATE
session_start();
require_once "../config/db.php";
require_once "stock_functions.php";

// ============================================================================
// VOUCHER NUMBER RENUMBERING FUNCTION
// Renumbers all VOC_NO for the company based on TP_DATE (or DATE if TP_DATE is empty)
// Called after deleting a purchase to close the gap in sequence
// ============================================================================
function renumberVoucherNumbers($conn, $companyId) {
    // Ensure companyId is an integer
    $companyId = (int)$companyId;
    
    if ($companyId <= 0) {
        deleteDebugLog("VOC_NO renumbering skipped - invalid company ID: $companyId");
        return -1;
    }
    
    // Use ROW_NUMBER() to assign new VOC_NO based on date ordering
    // TP_DATE takes precedence over DATE
    // For tie-breaking, use ID
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

// Check if tblpurchases table exists
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

// Function to cascade stock reversal through all months until FY end (for previous FY purchases)
function cascadeToFinancialYearEnd($conn, $comp_id, $item_code, $purchase_date, $reduction_qty) {
    $purchase_timestamp = strtotime($purchase_date);
    $purchase_month = (int)date('n', $purchase_timestamp);
    $purchase_year = (int)date('Y', $purchase_timestamp);
    
    // Get financial year end date (use function from stock_functions.php)
    $fy_end_date = getFinancialYearEndDate($purchase_date);
    $fy_end_month = (int)date('n', strtotime($fy_end_date));
    $fy_end_year = (int)date('Y', strtotime($fy_end_date));
    
    deleteDebugLog("Cascading reversal to FY end for previous year purchase", [
        'item_code' => $item_code,
        'purchase_date' => $purchase_date,
        'purchase_month' => $purchase_month,
        'purchase_year' => $purchase_year,
        'fy_end_date' => $fy_end_date,
        'fy_end_month' => $fy_end_month,
        'fy_end_year' => $fy_end_year,
        'reduction_qty' => $reduction_qty
    ]);
    
    // Start from the next month after purchase
    $start_month = $purchase_month + 1;
    $start_year = $purchase_year;
    
    if ($start_month > 12) {
        $start_month = 1;
        $start_year++;
    }
    
    // Get the closing value from the purchase month to use as opening for next month
    $purchase_month_2digit = str_pad($purchase_month, 2, '0', STR_PAD_LEFT);
    $purchase_year_2digit = substr($purchase_year, -2);
    $purchase_table = "tbldailystock_{$comp_id}_{$purchase_month_2digit}_{$purchase_year_2digit}";
    
    // Find the last day of purchase month with the purchase
    // First, get the actual number of days in the purchase month
    $days_in_purchase_month = date('t', $purchase_timestamp);
    
    $last_day_with_purchase = 0;
    for ($day = $days_in_purchase_month; $day >= 1; $day--) {
        $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
        $purchase_col = "DAY_{$day_str}_PURCHASE";
        $check_query = "SELECT $purchase_col as purch FROM $purchase_table WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $check_stmt = $conn->prepare($check_query);
        $purchase_month_year = date('Y-m', strtotime($purchase_date));
        $check_stmt->bind_param("ss", $item_code, $purchase_month_year);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $row = $check_result->fetch_assoc();
            if ($row['purch'] > 0) {
                $last_day_with_purchase = $day;
                break;
            }
        }
        $check_stmt->close();
    }
    
    // Get the closing from the last day with purchase
    $closing_value = 0;
    if ($last_day_with_purchase > 0) {
        $last_day_str = str_pad($last_day_with_purchase, 2, '0', STR_PAD_LEFT);
        $closing_col = "DAY_{$last_day_str}_CLOSING";
        $get_closing_query = "SELECT $closing_col as closing FROM $purchase_table WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $closing_stmt = $conn->prepare($get_closing_query);
        $closing_stmt->bind_param("ss", $item_code, $purchase_month_year);
        $closing_stmt->execute();
        $closing_result = $closing_stmt->get_result();
        if ($closing_result->num_rows > 0) {
            $closing_row = $closing_result->fetch_assoc();
            $closing_value = (int)$closing_row['closing'];
        }
        $closing_stmt->close();
    }
    
    deleteDebugLog("Starting cascade - last day: $last_day_with_purchase, closing value: $closing_value");
    
    // Loop through months from purchase month+1 to end of financial year (March)
    while ($start_year < $fy_end_year || ($start_year == $fy_end_year && $start_month <= $fy_end_month)) {
        $archive_table = getArchiveTableName($conn, $comp_id, $start_month, $start_year);
        $monthYear = date('Y-m', strtotime("$start_year-$start_month-01"));
        $daysInMonth = date('t', strtotime("$start_year-$start_month-01"));
        
        // Check if this month's table exists - if not, create it
        if (!checkTableExists($conn, $archive_table)) {
            // Create the archive table
            $create_query = "CREATE TABLE $archive_table (
                `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
                `STK_DATE` date NOT NULL,
                `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
                `ITEM_CODE` varchar(20) NOT NULL,
                `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
                `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),";
            
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $create_query .= "
                `DAY_{$day_padded}_OPEN` int(11) DEFAULT 0,
                `DAY_{$day_padded}_PURCHASE` int(11) DEFAULT 0,
                `DAY_{$day_padded}_SALES` int(11) DEFAULT 0,
                `DAY_{$day_padded}_CLOSING` int(11) DEFAULT 0,";
            }
            
            $create_query .= "
                PRIMARY KEY (`DailyStockID`),
                UNIQUE KEY `unique_daily_stock` (`STK_DATE`, `ITEM_CODE`),
                KEY `idx_item_code` (`ITEM_CODE`),
                KEY `idx_liq_flag` (`LIQ_FLAG`),
                KEY `idx_stk_month` (`STK_MONTH`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            if ($conn->query($create_query)) {
                deleteDebugLog("Created archive table during reversal: $archive_table");
            }
        }
        
        // Now process the table (it should exist now)
        if (checkTableExists($conn, $archive_table)) {
            deleteDebugLog("Processing archived month table for reversal", [
                'table' => $archive_table,
                'monthYear' => $monthYear,
                'daysInMonth' => $daysInMonth
            ]);
            
            // Check if record exists for this item
            $checkRecordQuery = "SELECT COUNT(*) as count FROM $archive_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $checkRecordStmt = $conn->prepare($checkRecordQuery);
            $checkRecordStmt->bind_param("ss", $monthYear, $item_code);
            $checkRecordStmt->execute();
            $recordResult = $checkRecordStmt->get_result();
            $recordExists = $recordResult->fetch_assoc()['count'] > 0;
            $checkRecordStmt->close();
            
            if (!$recordExists) {
                // Create new record with opening value from previous month (reduced by reversal qty)
                $openingValue = max(0, $closing_value - $reduction_qty);
                $insertQuery = "INSERT INTO $archive_table (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                               VALUES (?, ?, 'F', ?, 0, 0, ?)";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("ssii", $monthYear, $item_code, $openingValue, $openingValue);
                $insertStmt->execute();
                $insertStmt->close();
                
                deleteDebugLog("Created new record in $archive_table with opening=$openingValue (reduced from $closing_value)");
                
                // Update closing_value for next month
                $closing_value = $openingValue;
            } else {
                // Record exists - update it
                // For the first month after purchase, we need to reduce opening stock
                if ($start_month == $purchase_month + 1 || ($start_month == 1 && $purchase_month == 12)) {
                    // Get previous month's last day closing
                    $prevMonth = $purchase_month;
                    $prevYear = $purchase_year;
                    
                    if ($start_month == 1 && $purchase_month == 12) {
                        $prevYear = $purchase_year + 1;
                    }
                    
                    $prevMonthDays = date('t', strtotime("$prevYear-$prevMonth-01"));
                    $prevTable = getArchiveTableName($conn, $comp_id, $prevMonth, $prevYear);
                    $prevClosingColumn = "DAY_" . str_pad($prevMonthDays, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                    
                    // Check if previous table exists
                    if (checkTableExists($conn, $prevTable)) {
                        $prevMonthYear = date('Y-m', strtotime("$prevYear-$prevMonth-01"));
                        $getPrevClosingQuery = "SELECT $prevClosingColumn as closing FROM $prevTable 
                                               WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                        $prevStmt = $conn->prepare($getPrevClosingQuery);
                        $prevStmt->bind_param("ss", $prevMonthYear, $item_code);
                        $prevStmt->execute();
                        $prevResult = $prevStmt->get_result();
                        $prevRow = $prevResult->fetch_assoc();
                        $prevStmt->close();
                        
                        $openingValue = $prevRow ? (int)$prevRow['closing'] : 0;
                        
                        // Reduce the opening value
                        $reduceOpeningQuery = "UPDATE $archive_table 
                                              SET DAY_01_OPEN = GREATEST(0, DAY_01_OPEN - ?),
                                                  DAY_01_CLOSING = GREATEST(0, DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES)
                                              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                        $reduceStmt = $conn->prepare($reduceOpeningQuery);
                        $reduceStmt->bind_param("iss", $reduction_qty, $monthYear, $item_code);
                        $reduceStmt->execute();
                        $reduceStmt->close();
                    }
                    
                    // Cascade through all days of this month - reducing each day's stock
                    for ($day = 2; $day <= $daysInMonth; $day++) {
                        $prevDay = $day - 1;
                        $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                        $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
                        $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
                        $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
                        $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                        
                        // Reduce opening and recalculate closing
                        $updateDayQuery = "UPDATE $archive_table 
                                          SET $currentDayOpening = GREATEST(0, $prevDayClosing - ?),
                                              $currentDayClosing = GREATEST(0, $currentDayOpening + $currentDayPurchase - $currentDaySales)
                                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                        
                        $dayStmt = $conn->prepare($updateDayQuery);
                        $dayStmt->bind_param("iss", $reduction_qty, $monthYear, $item_code);
                        $dayStmt->execute();
                        $dayStmt->close();
                    }
                } else {
                    // For subsequent months, reduce every day's stock
                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
                        $currentDayOpening = "DAY_{$day_str}_OPEN";
                        $currentDayPurchase = "DAY_{$day_str}_PURCHASE";
                        $currentDaySales = "DAY_{$day_str}_SALES";
                        $currentDayClosing = "DAY_{$day_str}_CLOSING";
                        
                        // For day 1, opening comes from previous month
                        if ($day == 1) {
                            // Get previous month's closing
                            $prevMonth = $start_month - 1;
                            $prevYear = $start_year;
                            if ($prevMonth < 1) {
                                $prevMonth = 12;
                                $prevYear--;
                            }
                            
                            $prevMonthDays = date('t', strtotime("$prevYear-$prevMonth-01"));
                            $prevTable = getArchiveTableName($conn, $comp_id, $prevMonth, $prevYear);
                            $prevClosingColumn = "DAY_" . str_pad($prevMonthDays, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                            
                            // Check if previous table exists
                            if (checkTableExists($conn, $prevTable)) {
                                $prevMonthYear = date('Y-m', strtotime("$prevYear-$prevMonth-01"));
                                $getPrevClosingQuery = "SELECT $prevClosingColumn as closing FROM $prevTable 
                                                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                                $prevStmt = $conn->prepare($getPrevClosingQuery);
                                $prevStmt->bind_param("ss", $prevMonthYear, $item_code);
                                $prevStmt->execute();
                                $prevResult = $prevStmt->get_result();
                                $prevRow = $prevResult->fetch_assoc();
                                $prevStmt->close();
                                
                                $openingValue = $prevRow ? (int)$prevRow['closing'] : 0;
                                
                                // Reduce opening and recalculate
                                $updateQuery = "UPDATE $archive_table 
                                               SET $currentDayOpening = GREATEST(0, $openingValue - ?),
                                                   $currentDayClosing = GREATEST(0, $currentDayOpening + $currentDayPurchase - $currentDaySales)
                                               WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                                $dayStmt = $conn->prepare($updateQuery);
                                $dayStmt->bind_param("iss", $reduction_qty, $monthYear, $item_code);
                                $dayStmt->execute();
                                $dayStmt->close();
                            }
                        } else {
                            $prevDay = $day - 1;
                            $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                            
                            $updateQuery = "UPDATE $archive_table 
                                           SET $currentDayOpening = GREATEST(0, $prevDayClosing - ?),
                                               $currentDayClosing = GREATEST(0, $currentDayOpening + $currentDayPurchase - $currentDaySales)
                                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                            
                            $dayStmt = $conn->prepare($updateQuery);
                            $dayStmt->bind_param("iss", $reduction_qty, $monthYear, $item_code);
                            $dayStmt->execute();
                            $dayStmt->close();
                        }
                    }
                }
                
                // Get the closing value from this month to use for next month
                $lastDayStr = str_pad($daysInMonth, 2, '0', STR_PAD_LEFT);
                $lastDayClosingCol = "DAY_{$lastDayStr}_CLOSING";
                $getClosingQuery = "SELECT $lastDayClosingCol as closing FROM $archive_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $getClosingStmt = $conn->prepare($getClosingQuery);
                $getClosingStmt->bind_param("ss", $monthYear, $item_code);
                $getClosingStmt->execute();
                $closingResult = $getClosingStmt->get_result();
                if ($closingResult->num_rows > 0) {
                    $closingRow = $closingResult->fetch_assoc();
                    $closing_value = (int)$closingRow['closing'];
                }
                $getClosingStmt->close();
            }
        }
        
        // Move to next month
        $start_month++;
        if ($start_month > 12) {
            $start_month = 1;
            $start_year++;
        }
    }
    
    deleteDebugLog("Cascading reversal completed to FY end: " . $fy_end_date);
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
    
    // Get the number of days in the month
    $days_in_month = date('t', strtotime($stk_month . "-01"));
    
    // Cascade through remaining days
    for ($day = $start_day + 1; $day <= $days_in_month; $day++) {
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

// Function to reverse purchase stock updates - with dual logic for current and previous FY
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
        
        // 2. Update daily stock - DETERMINE WHICH LOGIC TO USE
        if ($is_previous_fy) {
            // ============================================================
            // USE NEW LOGIC for previous financial years
            // - Reduce stock in purchase month table
            // - Cascade through ALL months until FY end (March)
            // ============================================================
            deleteDebugLog("Using PREVIOUS YEAR logic for stock reversal");
            
            $day_num = date('d', strtotime($purchase_date));
            $stk_month = date('Y-m', strtotime($purchase_date));
            $day_str = sprintf('%02d', $day_num);
            
            // Get the archive table for purchase month
            $purchase_month = date('n', strtotime($purchase_date));
            $purchase_year = date('Y', strtotime($purchase_date));
            $purchase_table = getArchiveTableName($conn, $comp_id, $purchase_month, $purchase_year);
            
            deleteDebugLog("Updating previous FY purchase month table", [
                'table' => $purchase_table,
                'day' => $day_str,
                'month' => $stk_month
            ]);
            
            if (checkTableExists($conn, $purchase_table)) {
                // Update each item in the purchase month
                foreach ($items as $item) {
                    // Check if record exists
                    $check_exists = "SELECT COUNT(*) as cnt FROM $purchase_table 
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
                        $update_purchase = "UPDATE $purchase_table 
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
                        
                        $recalc = "UPDATE $purchase_table 
                                  SET $closing_col = GREATEST(0, $opening_col + $purchase_col - $sales_col)
                                  WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                        
                        $recalc_stmt = $conn->prepare($recalc);
                        $recalc_stmt->bind_param("ss", $item['ITEM_CODE'], $stk_month);
                        
                        if (!$recalc_stmt->execute()) {
                            deleteDebugLog("Recalc failed: " . $recalc_stmt->error);
                        }
                        $recalc_stmt->close();
                        
                        // Cascade to subsequent days within the same month
                        cascadeDailyStock($conn, $purchase_table, $item['ITEM_CODE'], $stk_month, $day_num);
                    }
                }
                deleteDebugLog("Previous FY purchase month table updated");
            }
            
            // Cascade through all remaining months until FY end
            foreach ($items as $item) {
                cascadeToFinancialYearEnd($conn, $comp_id, $item['ITEM_CODE'], $purchase_date, $item['QTY']);
            }
            
        } else {
            // ============================================================
            // USE EXISTING LOGIC for current financial year
            // - Keep current implementation
            // - Use existing cascadeDailyStock()
            // ============================================================
            deleteDebugLog("Using CURRENT YEAR logic for stock reversal");
            
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
        
        // ============================================================================
        // RENUMBER VOC_NO AFTER DELETION
        // After deleting a purchase, renumber all remaining purchases for the company
        // to close any gaps in the VOC_NO sequence
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