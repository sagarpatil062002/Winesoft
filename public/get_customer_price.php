<?php
session_start();
include_once "../config/db.php";

$customerId = $_POST['customerId'];
$itemCode = $_POST['itemCode'];

// Fetch customer-specific price
$query = "SELECT WPrice FROM tblcustomerprices WHERE LCODE = ? AND CODE = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $customerId, $itemCode);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode(['success' => true, 'price' => $row['WPrice']]);
} else {
    // Return 0 if no price found
    echo json_encode(['success' => false, 'price' => 0]);
}

$stmt->close();
$conn->close();
?>