<?php
// get_cart_summary.php
session_start();

header('Content-Type: application/json');

$totalItems = 0;
$totalQty = 0;

if (isset($_SESSION['sale_quantities'])) {
    $quantities = $_SESSION['sale_quantities'];
    $totalItems = count($quantities);
    $totalQty = array_sum($quantities);
}

echo json_encode([
    'success' => true,
    'totalItems' => $totalItems,
    'totalQty' => $totalQty
]);
?>