<?php
session_start();
include_once "../config/db.php";

$response = ['success' => false, 'vno' => null];

if (!isset($_SESSION['CompID']) || !isset($_POST['purchase_voc_nos'])) {
    echo json_encode($response);
    exit;
}

$comp_id = $_SESSION['CompID'];
$purchase_voc_nos = json_decode($_POST['purchase_voc_nos'], true);

try {
    if (!empty($purchase_voc_nos)) {
        $placeholders = str_repeat('?,', count($purchase_voc_nos) - 1) . '?';
        $query = "SELECT VNO 
                  FROM tblexpenses 
                  WHERE COMP_ID = ? AND PURCHASE_VOC_NO IN ($placeholders)
                  ORDER BY VNO DESC 
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $types = "i" . str_repeat("i", count($purchase_voc_nos));
        $params = array_merge([$comp_id], $purchase_voc_nos);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $response['success'] = true;
            $response['vno'] = $row['VNO'];
        }
        $stmt->close();
    }
} catch (Exception $e) {
    // Silently fail - we'll create new VNO
}

echo json_encode($response);
?>