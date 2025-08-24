<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];
$purchaseId = $_GET['id'];
$mode = $_GET['mode'];

include_once "../config/db.php";

// Start transaction for data integrity
$conn->begin_transaction();

try {
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