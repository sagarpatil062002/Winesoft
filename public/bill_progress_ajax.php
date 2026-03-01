<?php
// bill_progress_ajax.php - Real-time progress tracker for AJAX polling
session_start();

// Clear any previous output
ob_clean();

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$response = [
    'success' => false, 
    'error' => 'Unknown error',
    'status' => 'error'
];

try {
    // Get the progress key from the request
    $progress_key = isset($_GET['progress_key']) ? $_GET['progress_key'] : '';

    if (empty($progress_key)) {
        // First check for fixed key 'bill_progress' (used by generate_bills_progress.php)
        if (isset($_SESSION['bill_progress'])) {
            $progress_key = 'bill_progress';
        } else {
            // Try to find any active progress with dynamic key
            foreach ($_SESSION as $key => $value) {
                if (strpos($key, 'bill_progress_') === 0 && is_array($value)) {
                    $progress_key = $key;
                    break;
                }
            }
        }
    }

    if (empty($progress_key) || !isset($_SESSION[$progress_key])) {
        $response = [
            'success' => false, 
            'error' => 'No active bill generation',
            'status' => 'not_started'
        ];
        echo json_encode($response);
        exit;
    }

    // Check if progress has expired (5 minutes)
    if (isset($_SESSION[$progress_key]['expires']) && time() > $_SESSION[$progress_key]['expires']) {
        unset($_SESSION[$progress_key]);
        $response = [
            'success' => false, 
            'error' => 'Progress expired',
            'status' => 'expired'
        ];
        echo json_encode($response);
        exit;
    }

    $progress = $_SESSION[$progress_key];

    // Calculate percentage based on current bill and total
    $percentage = 0;
    if (isset($progress['total_bills']) && $progress['total_bills'] > 0) {
        $percentage = round(($progress['current_bill'] / $progress['total_bills']) * 100);
    } elseif (isset($progress['status']) && $progress['status'] === 'completed') {
        $percentage = 100;
    }

    // Calculate elapsed time
    $elapsed = 0;
    if (isset($progress['start_time'])) {
        $elapsed = round(microtime(true) - $progress['start_time'], 2);
    }

    // Calculate estimated time remaining
    $estimated_remaining = 0;
    if (isset($progress['speed']) && $progress['speed'] > 0 && 
        isset($progress['current_bill']) && isset($progress['total_bills']) &&
        $progress['current_bill'] < $progress['total_bills']) {
        $remaining_bills = $progress['total_bills'] - $progress['current_bill'];
        $estimated_remaining = round($remaining_bills / $progress['speed']);
    }

    // Get recent bills (last 10)
    $recent_bills = [];
    if (!empty($progress['bills_generated'])) {
        $recent_bills = array_slice($progress['bills_generated'], -10);
    }

    $response = [
        'success' => true,
        'status' => isset($progress['status']) ? $progress['status'] : 'unknown',
        'message' => isset($progress['message']) ? $progress['message'] : '',
        'current_bill' => isset($progress['current_bill']) ? $progress['current_bill'] : 0,
        'total_bills' => isset($progress['total_bills']) ? $progress['total_bills'] : 0,
        'percentage' => $percentage,
        'speed' => isset($progress['speed']) ? round($progress['speed'], 1) : 0,
        'elapsed_time' => $elapsed,
        'estimated_remaining' => $estimated_remaining,
        'recent_bills' => $recent_bills,
        'is_complete' => isset($progress['status']) && $progress['status'] === 'completed',
        'has_error' => isset($progress['status']) && $progress['status'] === 'error'
    ];

} catch (Exception $e) {
    $response = [
        'success' => false, 
        'error' => $e->getMessage(),
        'status' => 'error'
    ];
}

echo json_encode($response);
exit;
?>
