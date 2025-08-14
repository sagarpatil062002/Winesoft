<?php
if(!isset($_SESSION)) session_start();
?>
<nav class="side-nav">
    <div class="nav-header">
        <div class="logo-container">
            <img src="/winesoft/public/assets/logo.png" alt="Logo" class="nav-logo">
            <span class="nav-title">Winesoft</span>
        </div>
    </div>
    <ul class="nav-menu">
        <li class="nav-item">
            <a href="#" class="nav-link">
                <i class="icon-menu icon-masters"></i>
                <span>Masters</span>
            </a>
            <ul class="dropdown">
                <li><a href="item_master.php"><i class="icon-menu icon-items"></i> Item Master</a></li>
                <li><a href="brand_category.php"><i class="icon-menu icon-brand"></i> Brand Category</a></li>
            </ul>
        </li>
        <li class="nav-item">
            <a href="#" class="nav-link">
                <i class="icon-menu icon-transaction"></i>
                <span>Transaction</span>
            </a>
            <ul class="dropdown"></ul>
        </li>
        <li class="nav-item">
            <a href="#" class="nav-link">
                <i class="icon-menu icon-registers"></i>
                <span>Registers</span>
            </a>
            <ul class="dropdown"></ul>
        </li>
        <li class="nav-item">
            <a href="#" class="nav-link">
                <i class="icon-menu icon-reports"></i>
                <span>Reports</span>
            </a>
            <ul class="dropdown"></ul>
        </li>
        <li class="nav-item">
            <a href="#" class="nav-link">
                <i class="icon-menu icon-utilities"></i>
                <span>Utilities</span>
            </a>
            <ul class="dropdown"></ul>
        </li>
    </ul>
</nav>
