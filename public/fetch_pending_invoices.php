<?php
session_start();
include_once "../config/db.php";

$response = ['success' => false, 'message' => 'Unknown error', 'data' => []];

if (!isset($_SESSION['CompID']) || !isset($_POST['ledger_code'])) {
    $response['message'] = 'Invalid request';
    echo json_encode($response);
    exit;
}

$ledgerCode = $_POST['ledger_code'];
$compID = $_SESSION['CompID'];

try {
    // Fetch pending purchase invoices for this ledger
    $query = "SELECT p.ID, p.VOC_NO, p.INV_NO, p.DATE, p.TAMT, 
                     COALESCE(SUM(e.AMOUNT), 0) as paid_amount,
                     (p.TAMT - COALESCE(SUM(e.AMOUNT), 0)) as balance
              FROM tblpurchases p
              LEFT JOIN tblexpenses e ON p.ID = e.REF_SAC AND e.COMP_ID = p.CompID
              WHERE p.SUBCODE = ? AND p.CompID = ? AND p.PUR_FLAG = 'F'
              GROUP BY p.ID
              HAVING balance > 0
              ORDER BY p.DATE";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $ledgerCode, $compID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $invoices = [];
    $totalPending = 0;
    
    while ($row = $result->fetch_assoc()) {
        $invoices[] = $row;
        $totalPending += $row['balance'];
    }
    
    $stmt->close();
    
    $response['success'] = true;
    $response['message'] = 'Data fetched successfully';
    $response['data'] = $invoices;
    $response['total_pending'] = $totalPending;
    
} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);