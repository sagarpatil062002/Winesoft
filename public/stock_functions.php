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

// Function to update daily stock summary
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
?>