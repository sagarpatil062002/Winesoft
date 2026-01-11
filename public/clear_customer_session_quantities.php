<?php
// clear_customer_session_quantities.php
session_start();

header('Content-Type: application/json');

if (isset($_SESSION['customer_sale_quantities'])) {
    unset($_SESSION['customer_sale_quantities']);
    echo json_encode(['success' => true, 'message' => 'Customer session quantities cleared']);
} else {
    echo json_encode(['success' => true, 'message' => 'No customer quantities to clear']);
}
?>