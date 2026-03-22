<?php
// save_customer_distribution.php
session_start();

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/customer_sales_' . date('Y-m-d') . '.log';
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
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Initialize session array if not exists
    if (!isset($_SESSION['customer_item_distribution'])) {
        $_SESSION['customer_item_distribution'] = [];
    }

    $item_code = $_POST['item_code'];
    $distribution_json = $_POST['distribution'];
    
    // Decode the distribution JSON
    $distribution = json_decode($distribution_json, true);
    
    if ($distribution === null) {
        logMessage("Error decoding distribution for item: $item_code", 'ERROR');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid distribution data']);
        exit;
    }

    // Update the distribution in session
    $_SESSION['customer_item_distribution'][$item_code] = $distribution;

    logMessage("Customer item distribution saved: $item_code = " . implode(', ', $distribution));

    echo json_encode(['success' => true, 'message' => 'Distribution saved']);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
}
?>
