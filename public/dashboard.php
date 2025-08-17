<?php
session_start();

// Ensure user is logged in and company is selected
if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR'])){
    header("Location: select_company.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - WineSoft</title>
<!-- Add version parameter to force cache refresh -->
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
</head>
<body>

<div class="dashboard-container">

    <!-- Side Navbar -->
    <?php include 'components/navbar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <?php include 'components/header.php'; ?>

        <div class="content-area">
            <h3>Dashboard</h3>
            <p>Welcome to the dashboard! Select a menu item from the side navigation to proceed.</p>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>

</div>

</body>
</html>
