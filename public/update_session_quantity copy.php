<?php
session_start();

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/sales_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Not authorized';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_code'])) {
    // Initialize session array if not exists
    if (!isset($_SESSION['sale_quantities'])) {
        $_SESSION['sale_quantities'] = [];
    }
    
    $item_code = $_POST['item_code'];
    $quantity = intval($_POST['quantity']);
    
    // Update the quantity in session
    $_SESSION['sale_quantities'][$item_code] = $quantity;
    
    logMessage("Session quantity updated: $item_code = $quantity");
    
    echo 'OK';
} else {
    http_response_code(400);
    echo 'Invalid request';
}
?>