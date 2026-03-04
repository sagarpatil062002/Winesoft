<?php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_code = $_POST['item_code'] ?? '';
    $distribution_json = $_POST['distribution'] ?? '{}';
    
    if (empty($item_code)) {
        echo json_encode(['success' => false, 'message' => 'Item code required']);
        exit;
    }
    
    $distribution = json_decode($distribution_json, true);
    
    if (!isset($_SESSION['customer_date_distribution'])) {
        $_SESSION['customer_date_distribution'] = [];
    }
    
    $_SESSION['customer_date_distribution'][$item_code] = $distribution;
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
