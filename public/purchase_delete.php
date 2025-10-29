<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];
$purchaseId = $_GET['id'];
$mode = $_GET['mode'];

include_once "../config/db.php";
include_once "stock_functions.php";

// Function to reverse stock changes for purchase deletion
function reversePurchaseStock($conn, $purchaseId, $companyId) {
    // Get purchase details before deletion
    $query = "SELECT pd.ItemCode, pd.TotBott as Qty, p.DATE as StkDate
              FROM tblpurchasedetails pd
              INNER JOIN tblpurchases p ON pd.PurchaseID = p.ID
              WHERE p.ID = ? AND p.CompID = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $purchaseId, $companyId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $itemCode = $row['ItemCode'];
        $totalBottles = $row['Qty'];
        $purchaseDate = $row['StkDate'];

        // Reverse daily stock changes using cascading logic
        updateCascadingDailyStock($conn, $itemCode, $purchaseDate, $companyId, 'purchase', -$totalBottles);

        // Reverse item stock changes
        reverseItemStock($conn, $itemCode, $totalBottles, $companyId);
    }
    $stmt->close();
}

// Function to reverse daily stock changes
function reverseDailyStock($conn, $itemCode, $totalBottles, $purchaseDate, $companyId) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    $dailyStockTable = "tbldailystock_" . $companyId;

    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";

    // Check if record exists
    $check_query = "SELECT COUNT(*) as count FROM $dailyStockTable
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->fetch_assoc()['count'] > 0;
    $check_stmt->close();

    if ($exists) {
        // Reverse purchase: subtract from purchase column and recalculate closing
        $update_query = "UPDATE $dailyStockTable
                        SET $purchaseColumn = $purchaseColumn - ?,
                            $closingColumn = $closingColumn - ?
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ddss", $totalBottles, $totalBottles, $monthYear, $itemCode);
        $update_stmt->execute();
        $update_stmt->close();

        // Now cascade the changes to subsequent days
        cascadePurchaseStockChanges($conn, $itemCode, $monthYear, $dayOfMonth, $dailyStockTable);
    }
}

// Function to cascade stock changes to subsequent days for purchases
function cascadePurchaseStockChanges($conn, $itemCode, $monthYear, $startDay, $dailyStockTable) {
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

// Function to reverse item stock changes
function reverseItemStock($conn, $itemCode, $totalBottles, $companyId) {
    $stockColumn = "CURRENT_STOCK" . $companyId;

    // Subtract from current stock (reverse the addition)
    $updateItemStockQuery = "UPDATE tblitem_stock
                            SET $stockColumn = $stockColumn - ?
                            WHERE ITEM_CODE = ?";

    $itemStmt = $conn->prepare($updateItemStockQuery);
    $itemStmt->bind_param("ds", $totalBottles, $itemCode);
    $itemStmt->execute();
    $itemStmt->close();
}

// Start transaction for data integrity
$conn->begin_transaction();

try {
    // First, reverse the stock changes
    reversePurchaseStock($conn, $purchaseId, $companyId);

    // Delete purchase items first
    $deleteItemsQuery = "DELETE FROM tblpurchasedetails WHERE PurchaseID = ?";
    $deleteItemsStmt = $conn->prepare($deleteItemsQuery);
    $deleteItemsStmt->bind_param("i", $purchaseId);
    $deleteItemsStmt->execute();
    $deleteItemsStmt->close();

    // Delete purchase header
    $deletePurchaseQuery = "DELETE FROM tblpurchases WHERE ID = ? AND CompID = ?";
    $deletePurchaseStmt = $conn->prepare($deletePurchaseQuery);
    $deletePurchaseStmt->bind_param("ii", $purchaseId, $companyId);
    $deletePurchaseStmt->execute();

    if ($deletePurchaseStmt->affected_rows > 0) {
        $conn->commit();
        $message = "deleted=1";
    } else {
        $conn->rollback();
        $message = "error=delete_failed";
    }

    $deletePurchaseStmt->close();

} catch (Exception $e) {
    $conn->rollback();
    $message = "error=delete_failed";
}

header("Location: purchase_module.php?mode=".$mode."&".$message);
exit;