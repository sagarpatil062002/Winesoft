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


// Function to cascade stock changes to subsequent days
function cascadeStockChanges($conn, $itemCode, $monthYear, $startDay, $dailyStockTable) {
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

    // Get current date to limit updates
    $currentDate = date('Y-m-d');
    $currentDay = date('j', strtotime($currentDate));
    $currentMonthYear = date('Y-m', strtotime($currentDate));

    // Only cascade if we're in the same month
    if ($monthYear === $currentMonthYear) {
        $endDay = min(31, $currentDay); // Don't go beyond current day
    } else {
        $endDay = 31; // For past months, update all days
    }

    // Update all subsequent days' opening stock up to current date
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
}
?>