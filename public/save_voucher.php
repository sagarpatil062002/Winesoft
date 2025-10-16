<?php
session_start();
include_once "../config/db.php";

$response = ['success' => false, 'message' => 'Unknown error'];

if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID']) || !isset($_SESSION['user_id'])) {
    $response['message'] = 'Invalid session';
    echo json_encode($response);
    exit;
}

// Get POST data
$action = $_POST['action'] ?? 'new';
$voucher_id = $_POST['voucher_id'] ?? 0;
$ledger_code = $_POST['ledger_code'] ?? '';
$ledger_name = $_POST['ledger_name'] ?? '';
$amount = $_POST['amount'] ?? 0;
$is_payment = $_POST['is_payment'] === 'true' || $_POST['is_payment'] === '1';
$voucher_type = $_POST['voucher_type'] ?? 'C';
$narration = $_POST['narration'] ?? '';
$voucher_date = $_POST['voucher_date'] ?? date('Y-m-d');
$bank_id = $_POST['bank_id'] ?? null;
$doc_no = $_POST['doc_no'] ?? '';
$cheq_no = $_POST['cheq_no'] ?? '';
$doc_date = $_POST['doc_date'] ?? null;
$cheq_date = $_POST['cheq_date'] ?? null;
$paid_invoices = json_decode($_POST['paid_invoices'] ?? '[]', true);
$comp_id = $_SESSION['CompID'];
$fin_year_id = $_SESSION['FIN_YEAR_ID'];
$user_id = $_SESSION['user_id'];

try {
    $conn->autocommit(FALSE); // Start transaction
    
    // Get next voucher number
    $voucher_no = 0;
    if ($action === 'new') {
        $query = "SELECT COALESCE(MAX(VNO), 0) + 1 as next_vno FROM tblexpenses WHERE COMP_ID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $comp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $voucher_no = $row['next_vno'];
        $stmt->close();
    } else {
        $voucher_no = $voucher_id;
    }
    
    // Get ledger code (LCODE) for the selected particular using REF_CODE
    $ledger_code_query = "SELECT LCODE FROM tbllheads WHERE REF_CODE = ? AND (CompID IS NULL OR CompID = ?)";
    $stmt = $conn->prepare($ledger_code_query);
    $stmt->bind_param("si", $ledger_code, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Ledger not found for reference code: $ledger_code");
    }
    
    $ledger_row = $result->fetch_assoc();
    $ledger_id = $ledger_row['LCODE'];
    $stmt->close();
    
    // For each paid invoice, create a voucher entry
    foreach ($paid_invoices as $invoice) {
        $paid_amount = $invoice['paid_amount'];
        
        // Get the purchase VOC_NO to store as reference
        $vocNoQuery = "SELECT VOC_NO FROM tblpurchases WHERE ID = ? AND CompID = ?";
        $stmt = $conn->prepare($vocNoQuery);
        $stmt->bind_param("ii", $invoice['id'], $comp_id);
        $stmt->execute();
        $vocNoResult = $stmt->get_result();
        $vocNoRow = $vocNoResult->fetch_assoc();
        $stmt->close();
        
        $purchase_voc_no = $vocNoRow['VOC_NO'];
        
        // Prepare voucher data for this payment
        $voucher_data = [
            'VNO' => $voucher_no,
            'VDATE' => $voucher_date,
            'PARTI' => $ledger_name,
            'AMOUNT' => $paid_amount,
            'DRCR' => $is_payment ? 'D' : 'C',
            'NARR' => $narration . ' - Payment for VOC No: ' . $purchase_voc_no,
            'MODE' => $voucher_type,
            'REF_AC' => $bank_id,
            'REF_SAC' => $ledger_id,
            'INV_NO' => $purchase_voc_no, // Store the purchase VOC_NO as reference
            'LIQ_FLAG' => 'N',
            'CHEQ_NO' => $cheq_no,
            'CHEQ_DT' => $cheq_date,
            'MAIN_BK' => 'CB',
            'COMP_ID' => $comp_id
        ];
        
        // Save voucher
        $columns = implode(', ', array_keys($voucher_data));
        $placeholders = implode(', ', array_fill(0, count($voucher_data), '?'));
        $query = "INSERT INTO tblexpenses ($columns) VALUES ($placeholders)";
        
        $stmt = $conn->prepare($query);
        $types = str_repeat('s', count($voucher_data));
        $values = array_values($voucher_data);
        
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
        
        // Update purchase record status - FIXED: Use 'C' for completed instead of 'T'
        $total_paid = $invoice['total_paid'];
        $invoice_amount = $invoice['new_balance'] + $total_paid; // Calculate original amount
        
        // FIXED: Correct PUR_FLAG values
        $new_flag = 'P'; // Partial payment
        if ($total_paid >= $invoice_amount) {
            $new_flag = 'C'; // Completed (fully paid) - CHANGED FROM 'T' TO 'C'
        } elseif ($total_paid == 0) {
            $new_flag = 'T'; // Unpaid
        }
        
        $update_query = "UPDATE tblpurchases SET PUR_FLAG = ? WHERE ID = ? AND CompID = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sii", $new_flag, $invoice['id'], $comp_id);
        $stmt->execute();
        $stmt->close();
    }
    
    $conn->commit();
    $conn->autocommit(TRUE);
    
    $response['success'] = true;
    $response['message'] = 'Voucher saved successfully';
    $response['voucher_no'] = $voucher_no;
    
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(TRUE);
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);