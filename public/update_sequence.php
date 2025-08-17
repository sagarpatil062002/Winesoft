<?php
session_start();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.1 401 Unauthorized");
    exit;
}

include_once "../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = $_POST['code'] ?? '';
    $seq_no = $_POST['seq_no'] ?? 0;
    
    if (!empty($code)) {
        $stmt = $conn->prepare("UPDATE tblitemmaster SET SEQ_NO = ? WHERE CODE = ?");
        $stmt->bind_param("is", $seq_no, $code);
        $stmt->execute();
        $stmt->close();
        
        echo json_encode(['success' => true]);
        exit;
    }
}

header("HTTP/1.1 400 Bad Request");
echo json_encode(['success' => false, 'message' => 'Invalid request']);