<?php
// Function to update stock when purchase is made
function updateStockFromPurchase($purchaseID, $compId, $conn) {
    $query = "SELECT pd.ItemCode, pd.TotBott as Qty, p.PUR_FLAG as LIQ_FLAG, p.DATE as StkDate
              FROM tblpurchasedetails pd
              INNER JOIN tblpurchases p ON pd.PurchaseID = p.ID
              WHERE p.ID = ? AND p.CompID = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $purchaseID, $compId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Insert stock movement
        $insertQuery = "INSERT INTO tblstock (ITEM_CODE, QTY, STK_TYPE, STK_DATE, REF_NO, LIQ_FLAG, COMP_ID)
                        VALUES (?, ?, 'PI', ?, ?, ?, ?)";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bind_param("sdsssi", $row['ItemCode'], $row['Qty'], $row['StkDate'], $purchaseID, $row['LIQ_FLAG'], $compId);
        $insertStmt->execute();
        $insertStmt->close();
    }
    $stmt->close();
    
    // Update daily stock summary
    updateDailyStockSummary($compId, $conn);
}

// Function to update stock when sale is made
function updateStockFromSale($billNo, $liqFlag, $compId, $conn) {
    $query = "SELECT sd.ITEM_CODE, sd.QTY, sh.BILL_DATE as StkDate
              FROM tblsaledetails sd
              INNER JOIN tblsaleheader sh ON sd.BILL_NO = sh.BILL_NO AND sd.LIQ_FLAG = sh.LIQ_FLAG AND sd.COMP_ID = sh.COMP_ID
              WHERE sd.BILL_NO = ? AND sd.LIQ_FLAG = ? AND sd.COMP_ID = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssi", $billNo, $liqFlag, $compId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        // Insert stock movement
        $insertQuery = "INSERT INTO tblstock (ITEM_CODE, QTY, STK_TYPE, STK_DATE, REF_NO, LIQ_FLAG, COMP_ID)
                        VALUES (?, ?, 'SO', ?, ?, ?, ?)";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bind_param("sdsssi", $row['ITEM_CODE'], $row['QTY'], $row['StkDate'], $billNo, $liqFlag, $compId);
        $insertStmt->execute();
        $insertStmt->close();
    }
    $stmt->close();
    
    // Update daily stock summary
    updateDailyStockSummary($compId, $conn);
}

// Function to set initial opening balance from tblitemmaster.OB
function setInitialOpeningBalance($compId, $conn) {
    $query = "SELECT CODE, OB, LIQ_FLAG FROM tblitemmaster WHERE OB > 0";
    $result = $conn->query($query);
    
    while ($row = $result->fetch_assoc()) {
        $insertQuery = "INSERT INTO tblstock (ITEM_CODE, QTY, STK_TYPE, STK_DATE, REF_NO, LIQ_FLAG, COMP_ID)
                        VALUES (?, ?, 'OB', CURDATE(), 'OPENING_BALANCE', ?, ?)";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bind_param("sdsi", $row['CODE'], $row['OB'], $row['LIQ_FLAG'], $compId);
        $insertStmt->execute();
        $insertStmt->close();
    }
    
    // Update daily stock summary
    updateDailyStockSummary($compId, $conn);
}

// Function to update daily stock summary with cascading logic
function updateDailyStockSummary($compId, $conn) {
    $currentDate = date('Y-m-d');

    // Get all items for the company
    $itemsQuery = "SELECT CODE, LIQ_FLAG FROM tblitemmaster";
    $itemsResult = $conn->query($itemsQuery);

    while ($item = $itemsResult->fetch_assoc()) {
        // Calculate opening balance (sum of all stock movements before current date)
        $openingQuery = "SELECT
                            COALESCE(SUM(CASE WHEN STK_TYPE IN ('OB', 'PI') THEN QTY ELSE 0 END), 0) -
                            COALESCE(SUM(CASE WHEN STK_TYPE IN ('SO', 'AD') THEN QTY ELSE 0 END), 0) as OpeningQty
                         FROM tblstock
                         WHERE ITEM_CODE = ? AND COMP_ID = ? AND STK_DATE < ?";

        $openingStmt = $conn->prepare($openingQuery);
        $openingStmt->bind_param("sis", $item['CODE'], $compId, $currentDate);
        $openingStmt->execute();
        $openingResult = $openingStmt->get_result();
        $openingQty = $openingResult->fetch_assoc()['OpeningQty'];
        $openingStmt->close();

        // Calculate today's movements
        $todayQuery = "SELECT
                         SUM(CASE WHEN STK_TYPE = 'PI' THEN QTY ELSE 0 END) as PurchaseQty,
                         SUM(CASE WHEN STK_TYPE = 'SO' THEN QTY ELSE 0 END) as SalesQty,
                         SUM(CASE WHEN STK_TYPE = 'AD' THEN QTY ELSE 0 END) as AdjustmentQty
                       FROM tblstock
                       WHERE ITEM_CODE = ? AND COMP_ID = ? AND STK_DATE = ?";

        $todayStmt = $conn->prepare($todayQuery);
        $todayStmt->bind_param("sis", $item['CODE'], $compId, $currentDate);
        $todayStmt->execute();
        $todayResult = $todayStmt->get_result();
        $todayData = $todayResult->fetch_assoc();
        $todayStmt->close();

        $closingQty = $openingQty + $todayData['PurchaseQty'] - $todayData['SalesQty'] + $todayData['AdjustmentQty'];

        // Insert or update daily stock summary
        $upsertQuery = "INSERT INTO tbldailystock (STK_DATE, ITEM_CODE, COMP_ID, LIQ_FLAG, OPENING_QTY, PURCHASE_QTY, SALES_QTY, ADJUSTMENT_QTY, CLOSING_QTY)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                        OPENING_QTY = VALUES(OPENING_QTY),
                        PURCHASE_QTY = VALUES(PURCHASE_QTY),
                        SALES_QTY = VALUES(SALES_QTY),
                        ADJUSTMENT_QTY = VALUES(ADJUSTMENT_QTY),
                        CLOSING_QTY = VALUES(CLOSING_QTY)";

        $upsertStmt = $conn->prepare($upsertQuery);
        $upsertStmt->bind_param("ssisddddd", $currentDate, $item['CODE'], $compId, $item['LIQ_FLAG'],
                               $openingQty, $todayData['PurchaseQty'], $todayData['SalesQty'],
                               $todayData['AdjustmentQty'], $closingQty);
        $upsertStmt->execute();
        $upsertStmt->close();
    }
}

// Function to update cascading daily stock for a specific item and date - OPTIMIZED
function updateCascadingDailyStock($conn, $itemCode, $transactionDate, $compId, $transactionType, $quantity) {
    $dayOfMonth = date('j', strtotime($transactionDate));
    $monthYear = date('Y-m', strtotime($transactionDate));
    $dailyStockTable = "tbldailystock_" . $compId;

    // Determine column names
    $transactionColumn = ($transactionType === 'purchase') ?
        "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE" :
        "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";

    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";

    // Use transaction for atomicity and performance optimization
    $conn->begin_transaction();

    // Optimize with query caching and reduced I/O
    $conn->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
    $conn->query("SET SESSION innodb_lock_wait_timeout = 50");

    try {
        // Check if record exists in daily stock table
        $check_query = "SELECT COUNT(*) as count FROM $dailyStockTable
                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ss", $monthYear, $itemCode);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $exists = $result->fetch_assoc()['count'] > 0;
        $check_stmt->close();

        if (!$exists) {
            // Create record with default values
            $insert_query = "INSERT INTO $dailyStockTable (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $transactionColumn, $closingColumn)
                            VALUES (?, ?, 'F', 0, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssdd", $monthYear, $itemCode, $quantity, $quantity);
            $insert_stmt->execute();
            $insert_stmt->close();
        } else {
            // Get current values first for accurate calculation
            $select_query = "SELECT $openingColumn, $transactionColumn,
                            " . ($transactionType === 'purchase' ?
                                "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES" :
                                "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE") . " as other_qty
                            FROM $dailyStockTable WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $select_stmt = $conn->prepare($select_query);
            $select_stmt->bind_param("ss", $monthYear, $itemCode);
            $select_stmt->execute();
            $select_result = $select_stmt->get_result();
            $current_values = $select_result->fetch_assoc();
            $select_stmt->close();

            $opening = $current_values[$openingColumn] ?? 0;
            $current_transaction = $current_values[$transactionColumn] ?? 0;
            $other_qty = $current_values['other_qty'] ?? 0;

            // Calculate new values
            $new_transaction = $current_transaction + $quantity;
            $new_closing = $opening + ($transactionType === 'purchase' ? $new_transaction : $other_qty) -
                          ($transactionType === 'purchase' ? $other_qty : $new_transaction);

            // Update existing record with calculated values
            $update_query = "UPDATE $dailyStockTable
                            SET $transactionColumn = ?,
                                $closingColumn = ?,
                                LAST_UPDATED = CURRENT_TIMESTAMP
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ddss", $new_transaction, $new_closing, $monthYear, $itemCode);
            $update_stmt->execute();
            $update_stmt->close();
        }

        // Cascade changes to subsequent days until FY end
        cascadeStockChanges($conn, $itemCode, $monthYear, $dayOfMonth, $dailyStockTable, $transactionDate);

        $conn->commit();
        return true;

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error in updateCascadingDailyStock: " . $e->getMessage());
        // Add retry logic for deadlock situations
        if (strpos($e->getMessage(), 'deadlock') !== false || strpos($e->getMessage(), 'lock wait timeout') !== false) {
            usleep(100000); // Wait 100ms before retry
            return updateCascadingDailyStock($conn, $itemCode, $transactionDate, $compId, $transactionType, $quantity);
        }
        return false;
    }
}

// Function to get financial year end date based on transaction date
// Financial year runs from April 1 to March 31
if (!function_exists('getFinancialYearEndDate')) {
    function getFinancialYearEndDate($transactionDate) {
        $month = (int)date('m', strtotime($transactionDate));
        $year = (int)date('Y', strtotime($transactionDate));
        
        // If transaction is in Jan-Mar (months 1-3), end date is March 31 of current year
        // If transaction is in Apr-Dec (months 4-12), end date is March 31 of next year
        if ($month >= 1 && $month <= 3) {
            return date('Y-03-31', strtotime($year . '-01-01'));
        } else {
            return date('Y-03-31', strtotime(($year + 1) . '-01-01'));
        }
    }
}

// Function to check if a transaction is in a previous financial year
function isPreviousFinancialYear($transactionDate) {
    $today = new DateTime();
    $transaction = new DateTime($transactionDate);
    
    // Get current financial year (April 1 to March 31)
    $currentMonth = (int)$today->format('n');
    $currentYear = (int)$today->format('Y');
    
    // If we're in April-Dec (months 4-12), current FY is this year to next year
    // If we're in Jan-Mar (months 1-3), current FY is last year to this year
    if ($currentMonth >= 4) {
        $fyStart = new DateTime("$currentYear-04-01");
        $fyEnd = new DateTime(($currentYear + 1) . '-03-31');
    } else {
        $fyStart = new DateTime(($currentYear - 1) . '-04-01');
        $fyEnd = new DateTime("$currentYear-03-31");
    }
    
    // If transaction is before the start of current FY, it's in a previous year
    return $transaction < $fyStart;
}

// Function to cascade stock changes to subsequent days
function cascadeStockChanges($conn, $itemCode, $monthYear, $startDay, $dailyStockTable, $transactionDate = null) {
    // Get the new closing stock for the modified day
    $closingColumn = "DAY_" . str_pad($startDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    $query = "SELECT $closingColumn as new_closing FROM $dailyStockTable
              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $monthYear, $itemCode);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $newClosingStock = $row['new_closing'];
    $stmt->close();

    // Use financial year end date instead of current date
    // Only for previous financial years - for current year, use today
    if ($transactionDate !== null && isPreviousFinancialYear($transactionDate)) {
        $fyEndDate = getFinancialYearEndDate($transactionDate);
    } else {
        $fyEndDate = date('Y-m-d'); // For current year, use today
    }
    
    $currentDate = $fyEndDate;
    $currentDay = (int)date('j', strtotime($currentDate));
    $currentMonthYear = date('Y-m', strtotime($currentDate));
    $currentYear = (int)date('Y', strtotime($currentDate));
    $currentMonth = (int)date('m', strtotime($currentDate));

    // Get the last day of the current month
    $monthTimestamp = strtotime($monthYear . '-01');
    $lastDayOfMonth = (int)date('t', $monthTimestamp);
    
    // Determine the end day for cascade - use minimum of last day of month and FY end day
    $endDay = min($lastDayOfMonth, $currentDay);
    
    // If we're in a past month relative to FY end, use all days
    if ($monthYear < $currentMonthYear) {
        $endDay = $lastDayOfMonth;
    }

    // Update all subsequent days' opening stock up to financial year end
    for ($day = $startDay + 1; $day <= $endDay; $day++) {
        $openColumn = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $closingColumn = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";

        // Check if this day has data
        $check_query = "SELECT COUNT(*) as count FROM information_schema.columns
                       WHERE table_name = '$dailyStockTable' AND column_name = '$openColumn'";
        $check_result = $conn->query($check_query);
        if ($check_result->fetch_assoc()['count'] > 0) {
            // Update opening stock for this day
            $update_query = "UPDATE $dailyStockTable
                            SET $openColumn = ?
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("dss", $newClosingStock, $monthYear, $itemCode);
            $update_stmt->execute();
            $update_stmt->close();

            // Recalculate closing stock for this day
            $saleColumn = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
            $purchaseColumn = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";

            $recalc_query = "UPDATE $dailyStockTable
                            SET $closingColumn = $openColumn + $purchaseColumn - $saleColumn
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $recalc_stmt = $conn->prepare($recalc_query);
            $recalc_stmt->bind_param("ss", $monthYear, $itemCode);
            $recalc_stmt->execute();
            $recalc_stmt->close();

            // Get the new closing stock for next day's opening
            $get_closing_query = "SELECT $closingColumn as closing FROM $dailyStockTable
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $get_stmt = $conn->prepare($get_closing_query);
            $get_stmt->bind_param("ss", $monthYear, $itemCode);
            $get_stmt->execute();
            $get_result = $get_stmt->get_result();
            $closing_row = $get_result->fetch_assoc();
            $newClosingStock = $closing_row['closing'];
            $get_stmt->close();
        }
    }
    
    // If there are more months until FY end, cascade to next month
    if ($monthYear < $currentMonthYear) {
        cascadeToNextMonthStock($conn, $itemCode, $monthYear, $dailyStockTable, $fyEndDate);
    }
}

// New function to cascade to next month until FY end
function cascadeToNextMonthStock($conn, $itemCode, $currentMonthYear, $dailyStockTable, $fyEndDate) {
    // Calculate next month
    $nextMonthTimestamp = strtotime($currentMonthYear . '-01 +1 month');
    $nextMonthYear = date('Y-m', $nextMonthTimestamp);
    $nextYear = (int)date('Y', $nextMonthTimestamp);
    $nextMonth = (int)date('m', $nextMonthTimestamp);
    
    // Check if we've reached FY end
    $fyEndYear = (int)date('Y', strtotime($fyEndDate));
    $fyEndMonth = (int)date('m', strtotime($fyEndDate));
    
    // Stop if we've passed the FY end month
    if ($nextYear > $fyEndYear || ($nextYear == $fyEndYear && $nextMonth > $fyEndMonth)) {
        return;
    }
    
    // Determine table name for next month (may be archive table)
    $nextMonthNum = date('m', $nextMonthTimestamp);
    $nextYearShort = date('y', $nextMonthTimestamp);
    $nextMonthTable = "tbldailystock_" . substr($dailyStockTable, strlen("tbldailystock_")) . "_" . $nextMonthNum . "_" . $nextYearShort;
    
    // Try to find the correct table - check if it exists
    $tablePrefix = "tbldailystock_";
    $compId = "";
    if (preg_match('/tbldailystock_(\d+)/', $dailyStockTable, $matches)) {
        $compId = $matches[1];
        $nextMonthTable = "tbldailystock_" . $compId . "_" . $nextMonthNum . "_" . $nextYearShort;
    }
    
    // Check if next month table exists
    $checkTable = $conn->query("SHOW TABLES LIKE '$nextMonthTable'");
    if (!$checkTable || $checkTable->num_rows == 0) {
        // Try the main table format
        $nextMonthTable = $dailyStockTable;
    }
    
    // Get last day of next month
    $lastDayNextMonth = (int)date('t', $nextMonthTimestamp);
    
    // Get the last day's closing from current month as opening for next month
    $currentMonthLastDay = (int)date('t', strtotime($currentMonthYear . '-01'));
    $currentClosingCol = "DAY_" . sprintf('%02d', $currentMonthLastDay) . "_CLOSING";
    
    $getClosingQuery = "SELECT $currentClosingCol as closing FROM $dailyStockTable
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $closingStmt = $conn->prepare($getClosingQuery);
    $closingStmt->bind_param("ss", $currentMonthYear, $itemCode);
    $closingStmt->execute();
    $closingResult = $closingStmt->get_result();
    $closingRow = $closingResult->fetch_assoc();
    $nextOpeningStock = $closingRow['closing'] ?? 0;
    $closingStmt->close();
    
    // Update next month's day 1 opening
    $updateNextQuery = "UPDATE $nextMonthTable 
                        SET DAY_01_OPEN = ? 
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $updateNextStmt = $conn->prepare($updateNextQuery);
    $updateNextStmt->bind_param("dss", $nextOpeningStock, $nextMonthYear, $itemCode);
    $updateNextStmt->execute();
    $updateNextStmt->close();
    
    // Recalculate day 1 closing
    $recalcNextQuery = "UPDATE $nextMonthTable 
                        SET DAY_01_CLOSING = GREATEST(0, DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES)
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $recalcNextStmt = $conn->prepare($recalcNextQuery);
    $recalcNextStmt->bind_param("ss", $nextMonthYear, $itemCode);
    $recalcNextStmt->execute();
    $recalcNextStmt->close();
    
    // Cascade through all days in next month
    for ($day = 2; $day <= $lastDayNextMonth; $day++) {
        $dayStr = sprintf('%02d', $day);
        $prevDayStr = sprintf('%02d', $day - 1);
        
        $openCol = "DAY_{$dayStr}_OPEN";
        $purchaseCol = "DAY_{$dayStr}_PURCHASE";
        $salesCol = "DAY_{$dayStr}_SALES";
        $closingCol = "DAY_{$dayStr}_CLOSING";
        
        // Check if columns exist
        $checkCols = $conn->query("SHOW COLUMNS FROM $nextMonthTable LIKE '$openCol'");
        if ($checkCols->num_rows == 0) break;
        
        // Get previous day's closing
        $prevClosingCol = "DAY_{$prevDayStr}_CLOSING";
        $getPrevQuery = "SELECT $prevClosingCol as prev_closing FROM $nextMonthTable 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $prevStmt = $conn->prepare($getPrevQuery);
        $prevStmt->bind_param("ss", $nextMonthYear, $itemCode);
        $prevStmt->execute();
        $prevResult = $prevStmt->get_result();
        $prevRow = $prevResult->fetch_assoc();
        $prevClosing = $prevRow['prev_closing'] ?? $nextOpeningStock;
        $prevStmt->close();
        
        // Update this day's opening
        $updateDayQuery = "UPDATE $nextMonthTable 
                          SET $openCol = ?,
                              $closingCol = ? + $purchaseCol - $salesCol
                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $dayStmt = $conn->prepare($updateDayQuery);
        $dayStmt->bind_param("ddss", $prevClosing, $prevClosing, $nextMonthYear, $itemCode);
        $dayStmt->execute();
        $dayStmt->close();
        
        // Get new closing for next iteration
        $getNewClosingQuery = "SELECT $closingCol as new_closing FROM $nextMonthTable 
                               WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $newStmt = $conn->prepare($getNewClosingQuery);
        $newStmt->bind_param("ss", $nextMonthYear, $itemCode);
        $newStmt->execute();
        $newResult = $newStmt->get_result();
        $newRow = $newResult->fetch_assoc();
        $nextOpeningStock = $newRow['new_closing'] ?? 0;
        $newStmt->close();
    }
    
    // Continue to next month if not at FY end
    cascadeToNextMonthStock($conn, $itemCode, $nextMonthYear, $nextMonthTable, $fyEndDate);
}
?>