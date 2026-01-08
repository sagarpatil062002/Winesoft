<?php
function getDailyStockTable($compid, $saleDate) {
    $saleMonth = date('Y-m', strtotime($saleDate));
    $currentMonth = date('Y-m');

    if ($saleMonth === $currentMonth) {
        return "tbldailystock_{$compid}";
    }

    return "tbldailystock_{$compid}_" . date('m_y', strtotime($saleDate));
}

function getItemStockOnDate($conn, $compid, $itemCode, $saleDate) {
    logMessage("getItemStockOnDate called: CompID=$compid, Item=$itemCode, Date=$saleDate", 'DEBUG');

    $table = getDailyStockTable($compid, $saleDate);
    logMessage("Using table: $table for date $saleDate", 'DEBUG');

    // Check if table exists
    $check_table_query = "SHOW TABLES LIKE '$table'";
    $table_result = $conn->query($check_table_query);
    if ($table_result->num_rows == 0) {
        logMessage("Daily stock table '$table' not found for date $saleDate", 'WARNING');
        return 0;
    }
    logMessage("Table $table exists", 'DEBUG');

    $month = date('Y-m', strtotime($saleDate));
    $day = (int)date('d', strtotime($saleDate));
    logMessage("Month: $month, Day: $day", 'DEBUG');

    $sql = "SELECT * FROM `$table`
            WHERE ITEM_CODE = ?
            AND STK_MONTH = ?
            LIMIT 1";

    logMessage("Executing query: $sql with item=$itemCode, month=$month", 'DEBUG');

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $itemCode, $month);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows == 0) {
        logMessage("No row found for item $itemCode in month $month in table $table", 'WARNING');
        return 0; // item never existed in stock
    }

    $row = $res->fetch_assoc();
    logMessage("Row found for item $itemCode, starting backward scan from day $day", 'DEBUG');

    // 🔁 BACKWARD SCAN (CRITICAL FIX)
    for ($d = $day; $d >= 1; $d--) {
        $closingField = "DAY_" . str_pad($d, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        $value = $row[$closingField] ?? 'NULL';
        logMessage("Checking day $d, field $closingField, value: $value", 'DEBUG');
        if (isset($row[$closingField]) && $row[$closingField] > 0) {
            logMessage("Found valid stock on day $d: " . $row[$closingField], 'DEBUG');
            return (int)$row[$closingField];
        }
    }

    logMessage("No valid closing stock found in backward scan for item $itemCode on $saleDate", 'WARNING');
    return 0;
}

function updateDailyStock($conn, $compid, $itemCode, $saleDate, $qty) {

    $table = getDailyStockTable($compid, $saleDate);
    $month = date('Y-m', strtotime($saleDate));
    $day   = (int)date('d', strtotime($saleDate));
    $dayStr = str_pad($day, 2, '0', STR_PAD_LEFT);

    $salesField   = "DAY_{$dayStr}_SALES";
    $closingField = "DAY_{$dayStr}_CLOSING";

    // Get previous closing
    $prevStock = getItemStockOnDate($conn, $compid, $itemCode, $saleDate);

    $newClosing = $prevStock - $qty;

    $sql = "
        UPDATE `$table`
        SET
            $salesField = $salesField + ?,
            $closingField = ?
        WHERE ITEM_CODE = ?
        AND STK_MONTH = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiss", $qty, $newClosing, $itemCode, $month);
    $stmt->execute();
}
?>