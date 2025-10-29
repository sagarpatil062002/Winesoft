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
    // First, get the LCODE for this REF_CODE
    $ledgerQuery = "SELECT LCODE FROM tbllheads WHERE REF_CODE = ? AND (CompID IS NULL OR CompID = ?)";
    $stmt = $conn->prepare($ledgerQuery);
    $stmt->bind_param("si", $ledgerCode, $compID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['message'] = 'Ledger not found';
        echo json_encode($response);
        exit;
    }
    
    $ledgerRow = $result->fetch_assoc();
    $ledgerID = $ledgerRow['LCODE'];
    $stmt->close();

    // Fetch all purchase invoices for this ledger code
    $query = "SELECT 
                ID, 
                VOC_NO, 
                INV_NO, 
                DATE, 
                TAMT, 
                PUR_FLAG
              FROM tblpurchases 
              WHERE SUBCODE = ? AND CompID = ? 
              ORDER BY DATE";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $ledgerCode, $compID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $invoices = [];
    $totalPending = 0;
    
    while ($row = $result->fetch_assoc()) {
        // Get total payments for THIS SPECIFIC purchase invoice using PURCHASE_VOC_NO
        $paymentQuery = "SELECT COALESCE(SUM(AMOUNT), 0) as total_paid 
                         FROM tblexpenses 
                         WHERE PURCHASE_VOC_NO = ? AND COMP_ID = ?";
        $paymentStmt = $conn->prepare($paymentQuery);
        
        // Use the purchase VOC_NO to find payments
        $purchaseVocNo = $row['VOC_NO'];
        $paymentStmt->bind_param("ii", $purchaseVocNo, $compID);
        $paymentStmt->execute();
        $paymentResult = $paymentStmt->get_result();
        $paymentRow = $paymentResult->fetch_assoc();
        $paymentStmt->close();
        
        $paidAmount = $paymentRow['total_paid'];
        $balance = $row['TAMT'] - $paidAmount;
        
        // Only include invoices that are not fully paid
        if ($balance > 0) {
            $invoiceData = [
                'ID' => $row['ID'],
                'VOC_NO' => $row['VOC_NO'],
                'INV_NO' => $row['INV_NO'],
                'DATE' => $row['DATE'],
                'TAMT' => $row['TAMT'],
                'paid_amount' => $paidAmount,
                'balance' => $balance,
                'PUR_FLAG' => $row['PUR_FLAG']
            ];
            
            $invoices[] = $invoiceData;
            $totalPending += $balance;
        }
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
?>