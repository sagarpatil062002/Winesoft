<?php
// save_quantities_to_session.php
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quantities'])) {
    // Validate and sanitize quantities
    $quantities = [];
    foreach ($_POST['quantities'] as $item_code => $qty) {
        $quantities[$item_code] = max(0, intval($qty));
    }
    
    // Initialize session quantities if not exists
    if (!isset($_SESSION['sale_quantities'])) {
        $_SESSION['sale_quantities'] = [];
    }
    
    // Merge with existing session quantities (preserve all quantities)
    $_SESSION['sale_quantities'] = array_merge($_SESSION['sale_quantities'], $quantities);
    
    // Remove zero quantities to keep session clean
    $_SESSION['sale_quantities'] = array_filter($_SESSION['sale_quantities'], function($qty) {
        return $qty > 0;
    });
    
    echo json_encode(['success' => true, 'message' => 'Quantities saved successfully']);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>