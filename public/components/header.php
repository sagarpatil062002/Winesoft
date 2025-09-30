<?php
require_once 'license_functions.php';


// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);
?>

<html>
    <head>
        <script src="components/shortcuts.js?v=<?= time() ?>"></script>
</head>
<body>
</body>
</html>