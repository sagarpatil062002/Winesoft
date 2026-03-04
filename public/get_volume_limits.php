<?php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include_once "../config/db.php";
include_once "volume_limit_utils.php";

$company_id = $_SESSION['CompID'];

// Get license-based volume limits
$license_type = getCompanyLicenseType($company_id, $conn);
$volume_limits = getVolumeLimits($license_type);

header('Content-Type: application/json');
echo json_encode(['success' => true, 'limits' => $volume_limits]);
