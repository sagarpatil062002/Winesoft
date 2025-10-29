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

// Function to get VNO and PAYMENT_SEQ for existing purchase payments
function getVoucherDetails($conn, $comp_id, $purchase_voc_nos) {
    if (empty($purchase_voc_nos)) {
        return ['vno' => null, 'payment_seq' => 1];
    }
    
    // Check if we already have payments for any of these purchase invoices
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
        return ['vno' => $row['VNO'], 'payment_seq' => $row['max_seq'] + 1];
    }
    
    $stmt->close();
    return ['vno' => null, 'payment_seq' => 1];
}

// Function to get next VNO for new voucher
function getNextVoucherNo($conn, $comp_id) {
    $query = "SELECT COALESCE(MAX(VNO), 0) + 1 as next_vno FROM tblexpenses WHERE COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['next_vno'];
}

// Function to get next PAYMENT_SEQ for a VNO
function getNextPaymentSeq($conn, $vno, $comp_id) {
    $query = "SELECT COALESCE(MAX(PAYMENT_SEQ), 0) + 1 as next_seq 
              FROM tblexpenses 
              WHERE VNO = ? AND COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $vno, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['next_seq'];
}

try {
    $conn->autocommit(FALSE); // Start transaction
    
    // Generate auto DOC_NO
    $auto_doc_no = generateAutoDocNo($conn, $comp_id, $voucher_type, $is_payment);
    
    // Use manual doc_no if provided, otherwise use auto-generated
    $final_doc_no = !empty($doc_no) ? $doc_no : $auto_doc_no;
    
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

    // Determine voucher number and payment sequence
    if ($action === 'edit' && $voucher_id > 0) {
        // Editing existing voucher - use existing VNO
        $voucher_no = $voucher_id;
        $payment_seq = getNextPaymentSeq($conn, $voucher_no, $comp_id);
        
        // Delete existing payment entries for this voucher
        $delete_query = "DELETE FROM tblexpenses WHERE VNO = ? AND COMP_ID = ?";
        $stmt = $conn->prepare($delete_query);
        $stmt->bind_param("ii", $voucher_no, $comp_id);
        $stmt->execute();
        $stmt->close();
    } else {
        // New voucher - determine if we should reuse existing VNO
        $purchase_voc_nos = [];
        foreach ($paid_invoices as $invoice) {
            $purchase_voc_nos[] = $invoice['voc_no'];
        }
        
        $voucher_details = getVoucherDetails($conn, $comp_id, $purchase_voc_nos);
        
        if ($voucher_details['vno'] !== null && !empty($purchase_voc_nos)) {
            // Reuse existing VNO for same purchase
            $voucher_no = $voucher_details['vno'];
            $payment_seq = $voucher_details['payment_seq'];
        } else {
            // Create new VNO for new purchase or manual entry
            $voucher_no = getNextVoucherNo($conn, $comp_id);
            $payment_seq = 1;
        }
    }
    
    // If there are paid invoices, create entries for each
    if (count($paid_invoices) > 0) {
        $total_paid_amount = 0;
        $purchase_voc_nos = [];
        
        foreach ($paid_invoices as $invoice) {
            if ($invoice['paid_amount'] > 0) {
                $total_paid_amount += $invoice['paid_amount'];
                $purchase_voc_nos[] = $invoice['voc_no'];
                
                // Create voucher entry for EACH paid invoice
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
                $stmt->execute();
                $stmt->close();
                
                $payment_seq++; // Increment sequence for next payment
            }
        }

        // Update purchase records for each invoice
        foreach ($paid_invoices as $invoice) {
            if ($invoice['paid_amount'] > 0) {
                $total_paid = $invoice['total_paid'];
                $invoice_amount = $invoice['new_balance'] + $total_paid;

                $new_flag = 'P'; // Partial payment
                if ($total_paid >= $invoice_amount) {
                    $new_flag = 'C'; // Completed (fully paid)
                } elseif ($total_paid == 0) {
                    $new_flag = 'T'; // Unpaid
                }

                $update_query = "UPDATE tblpurchases SET PUR_FLAG = ? WHERE ID = ? AND CompID = ?";
                $stmt = $conn->prepare($update_query);
                $stmt->bind_param("sii", $new_flag, $invoice['id'], $comp_id);
                $stmt->execute();
                $stmt->close();
            }
        }
    } else {
        // Handle case where no specific invoices are selected (manual amount entry)
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
        $stmt->execute();
        $stmt->close();
    }
    
    $conn->commit();
    $conn->autocommit(TRUE);
    
    $response['success'] = true;
    $response['message'] = 'Voucher saved successfully';
    $response['voucher_no'] = $voucher_no;
    $response['doc_no'] = $final_doc_no;
    $response['total_invoices'] = count($paid_invoices);
    
} catch (Exception $e) {
    $conn->rollback();
    $conn->autocommit(TRUE);
    $response['message'] = 'Database error: ' . $e->getMessage();
}

echo json_encode($response);
?>