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
    // First, get the LCODE for this ledger - check both REF_CODE and LCODE
    $ledgerQuery = "SELECT LCODE FROM tbllheads WHERE (REF_CODE = ? OR LCODE = ?) AND (CompID IS NULL OR CompID = ?)";
    $stmt = $conn->prepare($ledgerQuery);
    $stmt->bind_param("ssi", $ledgerCode, $ledgerCode, $compID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Try to find the supplier by CODE in tblsupplier to get their REF_CODE
        $supplierQuery = "SELECT CODE FROM tblsupplier WHERE CODE = ?";
        $supplierStmt = $conn->prepare($supplierQuery);
        $supplierStmt->bind_param("s", $ledgerCode);
        $supplierStmt->execute();
        $supplierResult = $supplierStmt->get_result();
        
        if ($supplierResult->num_rows === 0) {
            $response['message'] = 'Ledger not found';
            echo json_encode($response);
            exit;
        }
        $supplierStmt->close();
        
        // Supplier exists but doesn't have a ledger entry - that's okay, continue with empty result
        $response['success'] = true;
        $response['message'] = 'No pending invoices - supplier has no ledger entry';
        $response['data'] = [];
        $response['total_pending'] = 0;
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
    
    // Pre-fetch all payments for this supplier in one query for better performance
    $paymentLookup = [];
    $paymentQuery = "SELECT PURCHASE_VOC_NO, COALESCE(SUM(AMOUNT), 0) as total_paid 
                     FROM tblexpenses 
                     WHERE COMP_ID = ? AND PURCHASE_VOC_NO IS NOT NULL AND PURCHASE_VOC_NO > 0
                     GROUP BY PURCHASE_VOC_NO";
    $paymentStmt = $conn->prepare($paymentQuery);
    $paymentStmt->bind_param("i", $compID);
    $paymentStmt->execute();
    $paymentResult = $paymentStmt->get_result();
    while ($paymentRow = $paymentResult->fetch_assoc()) {
        $paymentLookup[$paymentRow['PURCHASE_VOC_NO']] = $paymentRow['total_paid'];
    }
    $paymentStmt->close();
    
    while ($row = $result->fetch_assoc()) {
        // Get total payments from lookup array
        $purchaseVocNo = $row['VOC_NO'];
        $paidAmount = isset($paymentLookup[$purchaseVocNo]) ? $paymentLookup[$purchaseVocNo] : 0;
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