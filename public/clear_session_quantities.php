<?php
session_start();

if (isset($_SESSION['sale_quantities'])) {
    unset($_SESSION['sale_quantities']);
    echo json_encode(['success' => true, 'message' => 'Quantities cleared']);
} else {
    echo json_encode(['success' => true, 'message' => 'No quantities to clear']);
}
?>