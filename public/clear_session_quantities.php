<?php
// clear_session_quantities.php
session_start();

header('Content-Type: application/json');

if (isset($_SESSION['sale_quantities'])) {
    unset($_SESSION['sale_quantities']);
    echo json_encode(['success' => true, 'message' => 'Session quantities cleared']);
} else {
    echo json_encode(['success' => true, 'message' => 'No quantities to clear']);
}
?>