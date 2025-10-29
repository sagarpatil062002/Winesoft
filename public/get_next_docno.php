<?php
session_start();
include_once "../config/db.php";

$response = ['success' => false, 'doc_no' => ''];

if (!isset($_SESSION['CompID']) || !isset($_POST['prefix'])) {
    echo json_encode($response);
    exit;
}

$comp_id = $_SESSION['CompID'];
$prefix = $_POST['prefix'];

try {
    // Get the last used number for this document type
    $query = "SELECT MAX(CAST(SUBSTRING(DOC_NO, 5) AS UNSIGNED)) as last_num 
              FROM tblexpenses 
              WHERE COMP_ID = ? AND DOC_NO LIKE ?";
    $stmt = $conn->prepare($query);
    $like_pattern = $prefix . '-%';
    $stmt->bind_param("is", $comp_id, $like_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $next_num = ($row['last_num'] ? $row['last_num'] + 1 : 1);
    $doc_no = $prefix . '-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
    
    $response['success'] = true;
    $response['doc_no'] = $doc_no;
} catch (Exception $e) {
    $response['doc_no'] = 'Auto Generated';
}

echo json_encode($response);
?>