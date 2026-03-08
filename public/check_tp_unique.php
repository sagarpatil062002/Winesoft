<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    echo json_encode(['unique' => false, 'error' => 'Not authenticated']);
    exit;
}

include_once "../config/db.php";

$tp_no = $_POST['tp_no'] ?? '';
$auto_tp_no = $_POST['auto_tp_no'] ?? '';
$auto_tp = $_POST['auto_tp'] ?? 'no';
$supplier = $_POST['supplier'] ?? '';
$company_id = $_SESSION['CompID'];
$exclude_voc_no = $_POST['voc_no'] ?? null;

// If auto_tp is "no", check uniqueness based on both tp_no AND supplier
// Only when both are the same, it's considered a duplicate
if ($auto_tp === 'no') {
    if (empty($tp_no) || empty($supplier)) {
        echo json_encode(['unique' => true]);
        exit;
    }
    
    $query = "SELECT COUNT(*) as count FROM tblpurchases 
              WHERE TPNO = ? AND SUBCODE = ? AND CompID = ?";
    $params = [$tp_no, $supplier, $company_id];
    $types = "ssi";
    
    if ($exclude_voc_no) {
        $query .= " AND VOC_NO != ?";
        $params[] = $exclude_voc_no;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    echo json_encode(['unique' => $row['count'] == 0]);
    exit;
}

// For auto_tp = "yes", check only AUTO_TPNO field uniqueness
if (empty($auto_tp_no)) {
    echo json_encode(['unique' => true]);
    exit;
}

$query = "SELECT COUNT(*) as count FROM tblpurchases WHERE AUTO_TPNO = ? AND CompID = ?";
$params = [$auto_tp_no, $company_id];
$types = "si";

if ($exclude_voc_no) {
    $query .= " AND VOC_NO != ?";
    $params[] = $exclude_voc_no;
    $types .= "i";
}

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$stmt->close();
$conn->close();

echo json_encode(['unique' => $row['count'] == 0]);
?>