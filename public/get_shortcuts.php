<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include_once "../config/db.php";

// Check connection
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

$company_id = $_SESSION['CompID'];

try {
    // Fetch shortcuts from database
    $stmt = $conn->prepare("SELECT shortcut_key, action_name, action_url FROM tbl_shortcuts WHERE company_id = ?");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $shortcuts = [];
    while ($row = $result->fetch_assoc()) {
        $shortcuts[] = $row;
    }

    echo json_encode($shortcuts);
    $stmt->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?>