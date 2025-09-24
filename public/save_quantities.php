<?php
session_start();
if (isset($_POST['quantities'])) {
    // Merge with existing quantities (don't overwrite)
    $existing = isset($_SESSION['sale_quantities']) ? $_SESSION['sale_quantities'] : [];
    $_SESSION['sale_quantities'] = array_merge($existing, $_POST['quantities']);
    echo "OK";
}
?>