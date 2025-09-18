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
    
    // Get ledger code for the selected particular
    $ledger_code_query = "SELECT LCODE FROM tbllheads WHERE REF_CODE = ? AND CompID = ?";
    $stmt = $conn->prepare($ledger_code_query);
    $stmt->bind_param("si", $ledger_code, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $ledger_row = $result->fetch_assoc();
    $ledger_id = $ledger_row['LCODE'];
    $stmt->close();
    
    // Prepare voucher data
    $voucher_data = [
        'VNO' => $voucher_no,
        'VDATE' => $voucher_date,
        'PARTI' => $ledger_name,
        'AMOUNT' => $amount,
        'DRCR' => $is_payment ? 'D' : 'C',
        'NARR' => $narration,
        'MODE' => $voucher_type,
        'REF_AC' => $bank_id,
        'REF_SAC' => $ledger_id,
        'INV_NO' => $doc_no,
        'LIQ_FLAG' => 'N',
        'CHEQ_NO' => $cheq_no,
        'CHEQ_DT' => $cheq_date,
        'MAIN_BK' => 'CB',
        'COMP_ID' => $comp_id
    ];
    
    // Save or update voucher
    if ($action === 'new') {
        $columns = implode(', ', array_keys($voucher_data));
        $placeholders = implode(', ', array_fill(0, count($voucher_data), '?'));
        $query = "INSERT INTO tblexpenses ($columns) VALUES ($placeholders)";
    } else {
        $set_clause = implode(' = ?, ', array_keys($voucher_data)) . ' = ?';
        $query = "UPDATE tblexpenses SET $set_clause WHERE VNO = ? AND COMP_ID = ?";
        $voucher_data['VNO_OLD'] = $voucher_no;
        $voucher_data['COMP_ID_OLD'] = $comp_id;
    }
    
    $stmt = $conn->prepare($query);
    $types = str_repeat('s', count($voucher_data));
    $values = array_values($voucher_data);
    
    if ($action !== 'new') {
        $types .= 'ii';
        $values[] = $voucher_no;
        $values[] = $comp_id;
    }
    
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
    
    // Update purchase records with paid amounts
    foreach ($paid_invoices as $invoice) {
        $update_query = "UPDATE tblpurchases SET PUR_FLAG = CASE WHEN (TAMT - ?) <= 0 THEN 'T' ELSE 'P' END WHERE ID = ? AND CompID = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("dii", $invoice['paid_amount'], $invoice['id'], $comp_id);
        $stmt->execute();
        $stmt->close();
    }
    
    $conn->commit();
    $conn->autocommit(TRUE);
    
    $response['success'] = true;
    $response['message'] = 'Voucher saved successfully';
    
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(TRUE);
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);