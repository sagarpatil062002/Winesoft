<?php
session_start();

// Function to save item distribution to session
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['item_code']) && isset($data['distribution'])) {
        $itemCode = $data['item_code'];
        $distribution = $data['distribution']; // Array of quantities per date
        
        // Initialize session distribution array if not exists
        if (!isset($_SESSION['item_distribution'])) {
            $_SESSION['item_distribution'] = [];
        }
        
        // Save the distribution
        $_SESSION['item_distribution'][$itemCode] = $distribution;
        
        echo json_encode([
            'success' => true,
            'message' => 'Distribution saved for ' . $itemCode
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid data'
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method'
    ]);
}
