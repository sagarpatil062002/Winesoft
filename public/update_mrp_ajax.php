<?php
// update_mrp_ajax.php
session_start();
require_once "../config/db.php";

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get POST data
$item_code = $_POST['item_code'] ?? '';
$mrp = floatval($_POST['mrp'] ?? 0);
$company_id = intval($_POST['company_id'] ?? 0);

// Validate input
if (empty($item_code) || $mrp <= 0 || $company_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

// Debug logging
$debugLog = __DIR__ . '/debug_mrp_update.log';
$timestamp = date('Y-m-d H:i:s');
$logMessage = "[$timestamp] MRP Update Request - Item: $item_code, MRP: $mrp, Company: $company_id\n";
file_put_contents($debugLog, $logMessage, FILE_APPEND | LOCK_EX);

try {
    // Update MRP in tblitemmaster
    $updateQuery = "UPDATE tblitemmaster SET MPRICE = ? WHERE CODE = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("ss", $mrp, $item_code);
    
    $result = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    
    $logMessage = "[$timestamp] MRP Update Result - Success: $result, Affected Rows: $affectedRows\n";
    file_put_contents($debugLog, $logMessage, FILE_APPEND | LOCK_EX);
    
    $stmt->close();
    
    if ($result) {
        echo json_encode([
            'success' => true, 
            'message' => 'MRP updated successfully',
            'affected_rows' => $affectedRows
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to update MRP',
            'error' => $conn->error
        ]);
    }
} catch (Exception $e) {
    $logMessage = "[$timestamp] MRP Update Error - " . $e->getMessage() . "\n";
    file_put_contents($debugLog, $logMessage, FILE_APPEND | LOCK_EX);
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error updating MRP',
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>