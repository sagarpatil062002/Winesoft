<?php
// get_bills_by_date.php
session_start();
require_once "../config/db.php";

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$compID = $_SESSION['CompID'];
$response = ['success' => false, 'message' => '', 'bills' => []];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_date'])) {
    $delete_date = $_POST['delete_date'];
    
    // Get all bills for the date
    $get_bills_query = "SELECT BILL_NO FROM tblsaleheader 
                       WHERE COMP_ID = ? AND BILL_DATE = ? 
                       ORDER BY BILL_NO";
    $get_stmt = $conn->prepare($get_bills_query);
    $get_stmt->bind_param("is", $compID, $delete_date);
    $get_stmt->execute();
    $result = $get_stmt->get_result();
    
    $bills = [];
    while ($row = $result->fetch_assoc()) {
        $bills[] = $row['BILL_NO'];
    }
    $get_stmt->close();
    
    if (!empty($bills)) {
        $response = [
            'success' => true,
            'message' => 'Found ' . count($bills) . ' bills',
            'bills' => $bills
        ];
    } else {
        $response = [
            'success' => true,
            'message' => 'No bills found for this date',
            'bills' => []
        ];
    }
} else {
    $response['message'] = 'Invalid request';
}

header('Content-Type: application/json');
echo json_encode($response);
?>