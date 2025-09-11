<?php
session_start();
include_once "../config/db.php";

$response = ['success' => false];

if (isset($_POST['barcode'])) {
    $barcode = trim($_POST['barcode']);
    
    // First try to find by barcode
    $query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE 
              FROM tblitemmaster 
              WHERE BARCODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $barcode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $response['success'] = true;
        $response['item'] = $result->fetch_assoc();
    }
    $stmt->close();
} 
elseif (isset($_POST['code'])) {
    $code = trim($_POST['code']);
    
    // If barcode not found, try finding by item code
    $query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE 
              FROM tblitemmaster 
              WHERE CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $response['success'] = true;
        $response['item'] = $result->fetch_assoc();
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($response);
?>