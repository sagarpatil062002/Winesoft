<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];
$purchaseId = $_GET['id'];
$mode = $_GET['mode'];

include_once "../config/db.php";

// Function to get table name for a specific month
function getStockTableName($conn, $companyId, $date, $checkExists = true) {
    $currentYearMonth = date('Y-m');
    $targetYearMonth = date('Y-m', strtotime($date));
    
    // If it's current month, use main table
    if ($targetYearMonth === $currentYearMonth) {
        $tableName = "tbldailystock_" . $companyId;
    } else {
        // Archive table: tbldailystock_[companyId]_[mm]_[yy]
        $month = date('m', strtotime($date));  // 2-digit month
        $year = date('y', strtotime($date));   // 2-digit year
        $tableName = "tbldailystock_" . $companyId . "_" . $month . "_" . $year;
    }
    
    // Check if table exists
    if ($checkExists) {
        $checkTable = "SHOW TABLES LIKE '$tableName'";
        $result = $conn->query($checkTable);
        if ($result->num_rows === 0) {
            return false; // Table doesn't exist
        }
    }
    
    return $tableName;
}

// Function to reverse purchase stock with multi-month cascading
function reversePurchaseStock($conn, $purchaseId, $companyId) {
    // Get purchase details
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
        
        // Reverse stock with multi-month cascading
        reverseStockMultiMonth($conn, $itemCode, $purchaseDate, $companyId, $totalBottles);
        
        // Reverse item stock changes
        reverseItemStock($conn, $itemCode, $totalBottles, $companyId);
    }
    $stmt->close();
}

// Main function to reverse stock across multiple months
function reverseStockMultiMonth($conn, $itemCode, $purchaseDate, $companyId, $quantity) {
    $purchaseDay = date('j', strtotime($purchaseDate));
    $purchaseMonth = date('m', strtotime($purchaseDate));
    $purchaseYear = date('Y', strtotime($purchaseDate));
    
    $currentDate = date('Y-m-d');
    $currentDay = date('j', strtotime($currentDate));
    $currentMonth = date('m', strtotime($currentDate));
    $currentYear = date('Y', strtotime($currentDate));
    
    // Start from purchase month
    $currentMonthProcess = $purchaseMonth;
    $currentYearProcess = $purchaseYear;
    $startDay = $purchaseDay;
    
    do {
        // Create date for this month
        $processDate = $currentYearProcess . "-" . $currentMonthProcess . "-01";
        
        // Get table name for this month
        $tableName = getStockTableName($conn, $companyId, $processDate);
        if (!$tableName) {
            // Table doesn't exist, skip this month
            break;
        }
        
        // Determine end day for this month
        if ($currentYearProcess == $currentYear && $currentMonthProcess == $currentMonth) {
            // Current month: cascade up to today
            $endDay = min($currentDay, date('t', strtotime($processDate)));
        } else {
            // Archive month: cascade through entire month
            $endDay = date('t', strtotime($processDate));
        }
        
        // Process this month
        $newClosingStock = processMonthStock(
            $conn, $itemCode, $quantity, $tableName, 
            $currentYearProcess, $currentMonthProcess, 
            $startDay, $endDay, $companyId
        );
        
        // Move to next month
        if ($currentMonthProcess == 12) {
            $currentMonthProcess = 1;
            $currentYearProcess++;
        } else {
            $currentMonthProcess++;
        }
        $startDay = 1; // Start from day 1 for next months
        
        // Stop if we've reached current month and processed today
    } while (!($currentYearProcess == $currentYear && 
               $currentMonthProcess == $currentMonth && 
               $endDay == $currentDay) &&
             $currentYearProcess <= $currentYear);
}

// Process stock reversal for a single month
function processMonthStock($conn, $itemCode, $quantity, $tableName, 
                          $year, $month, $startDay, $endDay, $companyId) {
    
    $yearMonth = $year . "-" . str_pad($month, 2, '0', STR_PAD_LEFT);
    $newClosingStock = null;
    
    for ($day = $startDay; $day <= $endDay; $day++) {
        $paddedDay = str_pad($day, 2, '0', STR_PAD_LEFT);
        $purchaseColumn = "DAY_" . $paddedDay . "_PURCHASE";
        $closingColumn = "DAY_" . $paddedDay . "_CLOSING";
        
        // Check if record exists
        $checkQuery = "SELECT COUNT(*) as count FROM $tableName
                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("ss", $yearMonth, $itemCode);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $exists = $result->fetch_assoc()['count'] > 0;
        $checkStmt->close();
        
        if (!$exists) {
            // Record doesn't exist, can't update
            continue;
        }
        
        if ($day == $startDay) {
            // First day: subtract purchase quantity
            $updateQuery = "UPDATE $tableName
                           SET $purchaseColumn = $purchaseColumn - ?,
                               $closingColumn = $closingColumn - ?
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param("ddss", $quantity, $quantity, $yearMonth, $itemCode);
            $updateStmt->execute();
            $updateStmt->close();
        }
        
        // Get new closing stock for this day
        $getClosingQuery = "SELECT $closingColumn as closing FROM $tableName
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $getStmt = $conn->prepare($getClosingQuery);
        $getStmt->bind_param("ss", $yearMonth, $itemCode);
        $getStmt->execute();
        $result = $getStmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $newClosingStock = $row['closing'];
        }
        $getStmt->close();
        
        // Update next day's opening if not the last day
        if ($day < $endDay) {
            $nextDay = $day + 1;
            $nextPaddedDay = str_pad($nextDay, 2, '0', STR_PAD_LEFT);
            $openColumn = "DAY_" . $nextPaddedDay . "_OPEN";
            
            $updateOpenQuery = "UPDATE $tableName
                               SET $openColumn = ?
                               WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $updateOpenStmt = $conn->prepare($updateOpenQuery);
            $updateOpenStmt->bind_param("dss", $newClosingStock, $yearMonth, $itemCode);
            $updateOpenStmt->execute();
            $updateOpenStmt->close();
            
            // Recalculate closing for next day
            $nextClosingColumn = "DAY_" . $nextPaddedDay . "_CLOSING";
            $nextPurchaseColumn = "DAY_" . $nextPaddedDay . "_PURCHASE";
            $nextSaleColumn = "DAY_" . $nextPaddedDay . "_SALES";
            
            $recalcQuery = "UPDATE $tableName
                           SET $nextClosingColumn = $openColumn + $nextPurchaseColumn - $nextSaleColumn
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $recalcStmt = $conn->prepare($recalcQuery);
            $recalcStmt->bind_param("ss", $yearMonth, $itemCode);
            $recalcStmt->execute();
            $recalcStmt->close();
        }
    }
    
    return $newClosingStock;
}

// Function to reverse item stock changes (unchanged)
function reverseItemStock($conn, $itemCode, $totalBottles, $companyId) {
    $stockColumn = "CURRENT_STOCK" . $companyId;
    
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
    // First, reverse the stock changes across all months
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
?>