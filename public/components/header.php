<?php
// Ensure session exists
if(!isset($_SESSION)) session_start();
?>
<header class="dashboard-header">
    <div class="header-left">
        <h2>Welcome, <?= $_SESSION['user'] ?? 'User' ?></h2>
    </div>
    <div class="header-right">
        <p>Company: <?= $_SESSION['COMP_NAME'] ?? 'N/A' ?></p>
        <p>Financial Year: <?= $_SESSION['FIN_YEAR'] ?? 'N/A' ?></p>
    </div>
</header>
