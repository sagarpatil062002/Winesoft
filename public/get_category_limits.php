<?php
// get_category_limits.php
session_start();
include_once "../config/db.php";
include_once "volume_limit_utils.php";

if (!isset($_SESSION['CompID'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$comp_id = $_SESSION['CompID'];
$limits = getCategoryLimits($conn, $comp_id);

echo json_encode($limits);
?>