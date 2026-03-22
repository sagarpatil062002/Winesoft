<?php
// clear_customer_session_quantities.php
session_start();

header('Content-Type: application/json');

// Clear both quantities and distribution
if (isset($_SESSION['customer_sale_quantities'])) {
    unset($_SESSION['customer_sale_quantities']);
}
if (isset($_SESSION['customer_item_distribution'])) {
    unset($_SESSION['customer_item_distribution']);
}

echo json_encode(['success' => true, 'message' => 'Customer session quantities and distribution cleared']);
?>