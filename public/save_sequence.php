<?php
session_start();
include_once "../config/db.php";

header('Content-Type: application/json');

// Check if request is AJAX and POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get the raw POST data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Begin transaction
$conn->begin_transaction();

try {
    // Prepare update statement
    $stmt = $conn->prepare("UPDATE tblitemmaster SET SERIAL_NO = ?, SEQ_NO = ? 
                           WHERE DETAILS = ? AND DETAILS2 = ? AND LIQ_FLAG = ?");
    
    // Update each item
    foreach ($data['items'] as $item) {
        $serialNo = !empty($item['serialNo']) ? $item['serialNo'] : 0;
        $newSeq = !empty($item['newSeq']) ? $item['newSeq'] : 0;
        
        $stmt->bind_param("iisss", $serialNo, $newSeq, $item['description'], 
                         $item['category'], $data['mode']);
        $stmt->execute();
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Sequence updated successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

$stmt->close();
$conn->close();
?>