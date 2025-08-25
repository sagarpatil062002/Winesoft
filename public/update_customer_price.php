<?php
session_start();
include_once "../config/db.php";

$customerId = $_POST['customerId'];
$itemCode = $_POST['itemCode'];
$price = $_POST['price'];

// Check if price already exists
$checkQuery = "SELECT COUNT(*) as count FROM tblcustomerprices WHERE LCODE = ? AND CODE = ?";
$checkStmt = $conn->prepare($checkQuery);
$checkStmt->bind_param("ss", $customerId, $itemCode);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();
$checkRow = $checkResult->fetch_assoc();
$checkStmt->close();

if ($checkRow['count'] > 0) {
    // Update existing price
    $query = "UPDATE tblcustomerprices SET WPrice = ? WHERE LCODE = ? AND CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("dss", $price, $customerId, $itemCode);
} else {
    // Insert new price
    $query = "INSERT INTO tblcustomerprices (LCODE, CODE, WPrice) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssd", $customerId, $itemCode, $price);
}

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$stmt->close();
$conn->close();
?>