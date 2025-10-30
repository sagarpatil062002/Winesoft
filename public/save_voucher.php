<?php
session_start();
include_once "../config/db.php";

// Enable error logging
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'voucher_debug.log');

$response = ['success' => false, 'message' => 'Unknown error'];

// Debug function
function debug_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message\n";
    error_log($log_message, 3, 'voucher_debug.log');
}

debug_log("=== START VOUCHER SAVE PROCESS ===");

if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID']) || !isset($_SESSION['user_id'])) {
    $response['message'] = 'Invalid session';
    debug_log("ERROR: Invalid session");
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

debug_log("CompID: $comp_id, Action: $action, Ledger: $ledger_code, Amount: $amount");

// Function to generate auto DOC_NO
function generateAutoDocNo($conn, $comp_id, $voucher_type, $is_payment) {
    if ($voucher_type == 'B') {
        $prefix = 'CHQ-';
        $like_pattern = 'CHQ-%';
    } else {
        $prefix = $is_payment ? 'PMT-' : 'RCP-';
        $like_pattern = $is_payment ? 'PMT-%' : 'RCP-%';
    }
    
    $query = "SELECT MAX(CAST(SUBSTRING(DOC_NO, 5) AS UNSIGNED)) as last_num 
              FROM tblexpenses 
              WHERE COMP_ID = ? AND DOC_NO LIKE ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $comp_id, $like_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $next_num = ($row['last_num'] ? $row['last_num'] + 1 : 1);
    return $prefix . str_pad($next_num, 6, '0', STR_PAD_LEFT);
}

// Function to get next VNO - GLOBALLY UNIQUE across all companies
function getNextVoucherNo($conn) {
    debug_log("Getting next GLOBAL VNO");
    
    // Get the maximum VNO from ALL companies
    $query = "SELECT COALESCE(MAX(VNO), 0) as max_vno FROM tblexpenses";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $next_vno = ($row['max_vno'] ? $row['max_vno'] + 1 : 1);
    
    debug_log("Current GLOBAL MAX VNO: " . ($row['max_vno'] ?? '0') . ", Next VNO: $next_vno");
    return $next_vno;
}

// Function to get next PAYMENT_SEQ for a VNO within the same company
function getNextPaymentSeq($conn, $vno, $comp_id) {
    debug_log("Getting next PAYMENT_SEQ for VNO: $vno, CompID: $comp_id");
    
    $query = "SELECT COALESCE(MAX(PAYMENT_SEQ), 0) as max_seq 
              FROM tblexpenses 
              WHERE VNO = ? AND COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $vno, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $next_seq = ($row['max_seq'] ? $row['max_seq'] + 1 : 1);
    
    debug_log("Current MAX PAYMENT_SEQ for VNO $vno: " . ($row['max_seq'] ?? '0') . ", Next PAYMENT_SEQ: $next_seq");
    return $next_seq;
}

// Function to check if a specific (VNO, PAYMENT_SEQ) combination already exists for ANY company
function checkVoucherExists($conn, $vno, $payment_seq) {
    $query = "SELECT COUNT(*) as count FROM tblexpenses WHERE VNO = ? AND PAYMENT_SEQ = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $vno, $payment_seq);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $exists = ($row['count'] > 0);
    debug_log("Check if VNO: $vno, PAYMENT_SEQ: $payment_seq exists GLOBALLY: " . ($exists ? 'YES' : 'NO'));
    return $exists;
}

// Function to get VNO and PAYMENT_SEQ for existing purchase payments - ONLY FOR SAME COMPANY
function getVoucherDetails($conn, $comp_id, $purchase_voc_nos) {
    debug_log("Getting voucher details for CompID: $comp_id, Purchase VOC Nos: " . implode(',', $purchase_voc_nos));
    
    if (empty($purchase_voc_nos)) {
        debug_log("No purchase VOC numbers provided, returning null");
        return ['vno' => null, 'payment_seq' => 1];
    }
    
    // Check if we already have payments for any of these purchase invoices IN THE SAME COMPANY
    $placeholders = str_repeat('?,', count($purchase_voc_nos) - 1) . '?';
    $query = "SELECT VNO, MAX(PAYMENT_SEQ) as max_seq 
              FROM tblexpenses 
              WHERE COMP_ID = ? AND PURCHASE_VOC_NO IN ($placeholders)
              GROUP BY VNO 
              ORDER BY max_seq DESC 
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $types = "i" . str_repeat("i", count($purchase_voc_nos));
    $params = array_merge([$comp_id], $purchase_voc_nos);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stmt->close();
        debug_log("Found existing voucher - VNO: {$row['VNO']}, MAX PAYMENT_SEQ: {$row['max_seq']}");
        return ['vno' => $row['VNO'], 'payment_seq' => $row['max_seq'] + 1];
    }
    
    $stmt->close();
    debug_log("No existing voucher found for these purchase VOC numbers");
    return ['vno' => null, 'payment_seq' => 1];
}

try {
    debug_log("Starting database transaction");
    $conn->autocommit(FALSE);
    
    // Generate auto DOC_NO
    $auto_doc_no = generateAutoDocNo($conn, $comp_id, $voucher_type, $is_payment);
    $final_doc_no = !empty($doc_no) ? $doc_no : $auto_doc_no;
    debug_log("Final DOC_NO: $final_doc_no");
    
    // Get ledger code
    $ledger_code_query = "SELECT LCODE FROM tbllheads WHERE REF_CODE = ?";
    $stmt = $conn->prepare($ledger_code_query);
    $stmt->bind_param("s", $ledger_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Ledger not found for reference code: $ledger_code");
    }
    
    $ledger_row = $result->fetch_assoc();
    $ledger_id = $ledger_row['LCODE'];
    $stmt->close();
    debug_log("Ledger ID: $ledger_id");

    // Determine voucher number
    if ($action === 'edit' && $voucher_id > 0) {
        debug_log("Editing voucher ID: $voucher_id");
        $voucher_no = $voucher_id;
        $payment_seq = getNextPaymentSeq($conn, $voucher_no, $comp_id);
        
        // Delete existing entries
        $delete_query = "DELETE FROM tblexpenses WHERE VNO = ? AND COMP_ID = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("ii", $voucher_no, $comp_id);
        $stmt->execute();
        $stmt->close();
        debug_log("Deleted existing entries for VNO: $voucher_no");
    } else {
        debug_log("Creating new voucher");
        
        // For new vouchers, check if we should reuse existing VNO for same purchase
        $purchase_voc_nos = [];
        foreach ($paid_invoices as $invoice) {
            $purchase_voc_nos[] = $invoice['voc_no'];
        }
        
        $voucher_details = getVoucherDetails($conn, $comp_id, $purchase_voc_nos);
        
        if ($voucher_details['vno'] !== null && !empty($purchase_voc_nos)) {
            // Reuse existing VNO for same purchase WITHIN SAME COMPANY
            $voucher_no = $voucher_details['vno'];
            $payment_seq = $voucher_details['payment_seq'];
            debug_log("Reusing existing VNO: $voucher_no with PAYMENT_SEQ: $payment_seq");
        } else {
            // Create new GLOBALLY UNIQUE VNO
            $voucher_no = getNextVoucherNo($conn);
            $payment_seq = 1; // Start with sequence 1 for new vouchers
            debug_log("New GLOBAL VNO: $voucher_no with PAYMENT_SEQ: $payment_seq");
        }
    }
    
    // Process paid invoices
    if (count($paid_invoices) > 0) {
        debug_log("Processing " . count($paid_invoices) . " paid invoices");
        
        foreach ($paid_invoices as $index => $invoice) {
            if ($invoice['paid_amount'] > 0) {
                debug_log("Invoice $index - VOC: {$invoice['voc_no']}, Amount: {$invoice['paid_amount']}, PAYMENT_SEQ: $payment_seq");
                
                // Check if this combination already exists GLOBALLY
                if (checkVoucherExists($conn, $voucher_no, $payment_seq)) {
                    debug_log("WARNING: VNO $voucher_no, PAYMENT_SEQ $payment_seq already exists GLOBALLY! Getting next PAYMENT_SEQ.");
                    $payment_seq = getNextPaymentSeq($conn, $voucher_no, $comp_id);
                    debug_log("New PAYMENT_SEQ: $payment_seq");
                }
                
                // Create voucher entry
                $voucher_data = [
                    'VNO' => $voucher_no,
                    'VDATE' => $voucher_date,
                    'PARTI' => $ledger_name,
                    'AMOUNT' => $invoice['paid_amount'],
                    'DRCR' => $is_payment ? 'D' : 'C',
                    'NARR' => $narration . ' - Payment for VOC No: ' . $invoice['voc_no'],
                    'MODE' => $voucher_type,
                    'REF_AC' => ($voucher_type === 'B') ? $bank_id : null,
                    'REF_SAC' => $ledger_id,
                    'INV_NO' => $invoice['voc_no'],
                    'DOC_NO' => $final_doc_no,
                    'LIQ_FLAG' => 'N',
                    'CHEQ_NO' => ($voucher_type === 'B') ? $cheq_no : null,
                    'CHEQ_DT' => ($voucher_type === 'B') ? $cheq_date : null,
                    'MAIN_BK' => 'CB',
                    'COMP_ID' => $comp_id,
                    'PURCHASE_VOC_NO' => $invoice['voc_no'],
                    'PAYMENT_SEQ' => $payment_seq
                ];

                debug_log("Inserting - VNO: $voucher_no, PAYMENT_SEQ: $payment_seq, CompID: $comp_id");

                // Save the voucher entry
                $columns = implode(', ', array_keys($voucher_data));
                $placeholders = implode(', ', array_fill(0, count($voucher_data), '?'));
                $query = "INSERT INTO tblexpenses ($columns) VALUES ($placeholders)";

                $stmt = $conn->prepare($query);
                
                // Create type string dynamically
                $types = '';
                foreach ($voucher_data as $value) {
                    if (is_int($value)) $types .= 'i';
                    elseif (is_double($value)) $types .= 'd';
                    else $types .= 's';
                }
                
                $values = array_values($voucher_data);
                $stmt->bind_param($types, ...$values);
                $result = $stmt->execute();
                
                if (!$result) {
                    throw new Exception("Failed to insert voucher: " . $stmt->error);
                }
                
                debug_log("INSERT SUCCESS - VNO: $voucher_no, PAYMENT_SEQ: $payment_seq");
                $stmt->close();
                
                $payment_seq++; // Increment for next payment
            }
        }

        // Update purchase records
        foreach ($paid_invoices as $invoice) {
            if ($invoice['paid_amount'] > 0) {
                $total_paid = $invoice['total_paid'];
                $invoice_amount = $invoice['new_balance'] + $total_paid;

                $new_flag = ($total_paid >= $invoice_amount) ? 'C' : 'P';
                debug_log("Updating purchase ID: {$invoice['id']} with flag: $new_flag");
                
                $update_query = "UPDATE tblpurchases SET PUR_FLAG = ? WHERE ID = ? AND CompID = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("sii", $new_flag, $invoice['id'], $comp_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    } else {
        debug_log("Creating manual voucher entry");
        
        // Check if this combination already exists GLOBALLY
        if (checkVoucherExists($conn, $voucher_no, $payment_seq)) {
            debug_log("WARNING: VNO $voucher_no, PAYMENT_SEQ $payment_seq already exists GLOBALLY! Getting next PAYMENT_SEQ.");
            $payment_seq = getNextPaymentSeq($conn, $voucher_no, $comp_id);
            debug_log("New PAYMENT_SEQ: $payment_seq");
        }
        
        // Manual voucher entry
        $voucher_data = [
            'VNO' => $voucher_no,
            'VDATE' => $voucher_date,
            'PARTI' => $ledger_name,
            'AMOUNT' => $amount,
            'DRCR' => $is_payment ? 'D' : 'C',
            'NARR' => $narration,
            'MODE' => $voucher_type,
            'REF_AC' => ($voucher_type === 'B') ? $bank_id : null,
            'REF_SAC' => $ledger_id,
            'INV_NO' => null,
            'DOC_NO' => $final_doc_no,
            'LIQ_FLAG' => 'N',
            'CHEQ_NO' => ($voucher_type === 'B') ? $cheq_no : null,
            'CHEQ_DT' => ($voucher_type === 'B') ? $cheq_date : null,
            'MAIN_BK' => 'CB',
            'COMP_ID' => $comp_id,
            'PURCHASE_VOC_NO' => null,
            'PAYMENT_SEQ' => $payment_seq
        ];

        debug_log("Inserting manual voucher - VNO: $voucher_no, PAYMENT_SEQ: $payment_seq, CompID: $comp_id");

        $columns = implode(', ', array_keys($voucher_data));
        $placeholders = implode(', ', array_fill(0, count($voucher_data), '?'));
        $query = "INSERT INTO tblexpenses ($columns) VALUES ($placeholders)";

        $stmt = $conn->prepare($query);
        
        $types = '';
        foreach ($voucher_data as $value) {
            if (is_int($value)) $types .= 'i';
            elseif (is_double($value)) $types .= 'd';
            else $types .= 's';
        }
        
        $values = array_values($voucher_data);
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        
        if (!$result) {
            throw new Exception("Failed to insert voucher: " . $stmt->error);
        }
        
        debug_log("MANUAL VOUCHER INSERT SUCCESS");
        $stmt->close();
    }
    
    debug_log("Committing transaction");
    $conn->commit();
    $conn->autocommit(TRUE);
    
    $response['success'] = true;
    $response['message'] = 'Voucher saved successfully';
    $response['voucher_no'] = $voucher_no;
    $response['doc_no'] = $final_doc_no;
    $response['total_invoices'] = count($paid_invoices);
    
    debug_log("=== VOUCHER SAVE SUCCESS ===");
    
} catch (Exception $e) {
    debug_log("ERROR: " . $e->getMessage());
    $conn->rollback();
    $conn->autocommit(TRUE);
    $response['message'] = 'Database error: ' . $e->getMessage();
    debug_log("=== VOUCHER SAVE FAILED ===");
}

echo json_encode($response);
?>