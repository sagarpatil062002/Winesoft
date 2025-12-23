<?php
session_start();
require_once 'drydays_functions.php';
require_once 'license_functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

include_once "../config/db.php";

$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

$current_stock_column = "Current_Stock" . $company_id;

// OPTIMIZATION: Only load items that have quantities in session or are needed
$all_items_data = [];

if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS, im.LIQ_FLAG, im.RPRICE,
                     COALESCE(st.$current_stock_column, 0) as CURRENT_STOCK
              FROM tblitemmaster im
              LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
              WHERE im.CLASS IN ($class_placeholders)";

    $stmt = $conn->prepare($query);
    $stmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $all_items_data[$row['CODE']] = $row;
    }
    $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($all_items_data);
?>