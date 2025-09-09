<?php
session_start();
include_once "../config/db.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_POST['ledger_code']) || !isset($_POST['comp_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$ledgerCode = $_POST['ledger_code'];
$compId = $_POST['comp_id'];

try {
    // Fetch pending purchase invoices
    $purchaseQuery = "SELECT 
                        p.ID as id,
                        'Purchase' as type,
                        p.VOC_NO as voc_no,
                        p.INV_NO as doc_no,
                        p.DATE as date,
                        p.TAMT as amount,
                        COALESCE(SUM(e.AMOUNT), 0) as paid,
                        (p.TAMT - COALESCE(SUM(e.AMOUNT), 0)) as balance
                      FROM tblpurchases p
                      LEFT JOIN tblExpenses e ON p.SUBCODE = e.PARTI AND p.CompID = e.COMP_ID
                      WHERE p.SUBCODE = ? AND p.CompID = ?
                      GROUP BY p.ID
                      HAVING balance > 0";
    
    $stmt = $conn->prepare($purchaseQuery);
    $stmt->bind_param("si", $ledgerCode, $compId);
    $stmt->execute();
    $purchaseResult = $stmt->get_result();
    $purchaseInvoices = $purchaseResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Fetch pending sale invoices
    $saleQuery = "SELECT 
                    s.BILL_NO as id,
                    'Sale' as type,
                    s.BILL_NO as voc_no,
                    s.BILL_NO as doc_no,
                    s.BILL_DATE as date,
                    s.NET_AMOUNT as amount,
                    COALESCE(SUM(e.AMOUNT), 0) as paid,
                    (s.NET_AMOUNT - COALESCE(SUM(e.AMOUNT), 0)) as balance
                  FROM tblsaleheader s
                  LEFT JOIN tblExpenses e ON s.CUST_CODE = e.PARTI AND s.COMP_ID = e.COMP_ID
                  WHERE s.CUST_CODE = ? AND s.COMP_ID = ?
                  GROUP BY s.BILL_NO
                  HAVING balance > 0";
    
    $stmt = $conn->prepare($saleQuery);
    $stmt->bind_param("si", $ledgerCode, $compId);
    $stmt->execute();
    $saleResult = $stmt->get_result();
    $saleInvoices = $saleResult->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Combine results
    $invoices = array_merge($purchaseInvoices, $saleInvoices);
    
    // Calculate total pending amount
    $totalPending = 0;
    foreach ($invoices as $invoice) {
        $totalPending += $invoice['balance'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $invoices,
        'total_pending' => $totalPending
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}