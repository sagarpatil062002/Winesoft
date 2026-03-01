<?php
// bill_progress.php - Real-time progress tracker
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['bill_progress'])) {
    echo json_encode(['error' => 'No active bill generation']);
    exit;
}

// Check if progress has expired (5 minutes)
if (isset($_SESSION['bill_progress']['expires']) && time() > $_SESSION['bill_progress']['expires']) {
    unset($_SESSION['bill_progress']);
    echo json_encode(['error' => 'Progress expired']);
    exit;
}

echo json_encode($_SESSION['bill_progress']);
?>
