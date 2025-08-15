<?php
if(!isset($_SESSION)) session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Winesoft</title>
    <link rel="stylesheet" href="/winesoft/public/css/navbar.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
</head>
<body>
    <nav class="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <img src="/winesoft/public/assets/logo.png" alt="Winesoft" class="nav-logo">
                <span class="nav-title">Winesoft</span>
            </div>
        </div>
        <ul class="nav-list">
            <li class="nav-item has-dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <span class="nav-icon material-symbols-rounded">layers</span>
                    <span class="nav-label">Masters</span>
                </a>
                <ul class="dropdown">
                    <li class="nav-item">
                        <a href="item_master.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">inventory_2</span>
                            <span class="nav-label">Item Master</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="brand_category.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">category</span>
                            <span class="nav-label">Brand Category</span>
                        </a>
                    </li>
                        <li class="nav-item">
                        <a href="item_sequence.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">category</span>
                            <span class="nav-label">Item Sequence</span>
                        </a>
                    </li>
                </ul>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="nav-icon material-symbols-rounded">receipt_long</span>
                    <span class="nav-label">Transaction</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="nav-icon material-symbols-rounded">point_of_sale</span>
                    <span class="nav-label">Registers</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="nav-icon material-symbols-rounded">summarize</span>
                    <span class="nav-label">Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="#" class="nav-link">
                    <span class="nav-icon material-symbols-rounded">settings</span>
                    <span class="nav-label">Utilities</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const dropdownToggles = document.querySelectorAll('.dropdown-toggle');
            
            dropdownToggles.forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    const parentItem = this.closest('.has-dropdown');
                    parentItem.classList.toggle('active');
                    
                    // Rotate arrow icon
                    const arrow = this.querySelector('.dropdown-arrow');
                    arrow.style.transform = parentItem.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0)';
                });
            });
        });
    </script>
</body>
</html>