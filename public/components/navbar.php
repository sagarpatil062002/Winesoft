<?php
if(!isset($_SESSION)) session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Winesoft</title>
    <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@24,400,0,0">
    <style>
        /* Add this CSS to ensure only one arrow appears */
        .dropdown-toggle .dropdown-arrow {
            margin-left: auto;
            transition: transform 0.3s ease;
        }
        /* Hide any duplicate arrows */
        .nav-link .material-symbols-rounded:not(.nav-icon):not(.dropdown-arrow) {
            display: none;
        }
    </style>
</head>
<body>
    <button class="mobile-menu-toggle" id="mobileMenuToggle">
        <span class="material-symbols-rounded">menu</span>
    </button>

    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo-container">
                <img src="/winesoft/public/assets/logo.png" alt="Winesoft" class="nav-logo">
                <span class="nav-title">Winesoft</span>
            </div>
        </div>
        <ul class="nav-list">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <span class="nav-icon material-symbols-rounded">dashboard</span>
                    <span class="nav-label">Dashboard</span>
                </a>
            </li>
            <li class="nav-item has-dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <span class="nav-icon material-symbols-rounded">database</span>
                    <span class="nav-label">Masters</span>
                    <span class="dropdown-arrow material-symbols-rounded">expand_more</span>
                </a>
                <ul class="dropdown">
                    <li class="nav-item">
                        <a href="item_master.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">liquor</span>
                            <span class="nav-label">Item Master</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="barcode_master.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">barcode</span>
                            <span class="nav-label">Barcode Master</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="brand_category.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">branding_watermark</span>
                            <span class="nav-label">Brand Category</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="item_sequence.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">format_list_numbered</span>
                            <span class="nav-label">Item Sequence</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reference_code.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">code</span>
                            <span class="nav-label">Reference Code</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="item_reorder.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">low_priority</span>
                            <span class="nav-label">Item Reorder</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="permit_master.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">verified</span>
                            <span class="nav-label">Permit Master</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="itemwise_price.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">price_check</span>
                            <span class="nav-label">ItemWise Price</span>
                        </a>
                    </li>
                    <li class="nav-item">
    <a href="supplier_master.php" class="nav-link">
        <span class="nav-icon material-symbols-rounded">local_shipping</span>
        <span class="nav-label">Supplier Master</span>
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
            const sidebar = document.getElementById('sidebar');
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const dropdownToggle = document.querySelector('.has-dropdown .dropdown-toggle');
            
            // Mobile menu toggle
            mobileMenuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                sidebar.classList.toggle('active');
                document.body.classList.toggle('sidebar-active');
            });
            
            // Dropdown functionality
            if (dropdownToggle) {
                dropdownToggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const parentItem = this.closest('.has-dropdown');
                    parentItem.classList.toggle('active');
                    
                    // Rotate the single arrow icon
                    const arrow = this.querySelector('.dropdown-arrow');
                    if (arrow) {
                        arrow.style.transform = parentItem.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0)';
                    }
                });
            }
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function() {
                const dropdown = document.querySelector('.has-dropdown');
                if (dropdown && dropdown.classList.contains('active')) {
                    dropdown.classList.remove('active');
                    const arrow = dropdown.querySelector('.dropdown-arrow');
                    if (arrow) {
                        arrow.style.transform = 'rotate(0)';
                    }
                }
                
                // Also close sidebar when clicking outside on mobile
                if (window.innerWidth <= 768 && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });
            
            // Prevent dropdown close when clicking inside it
            const dropdown = document.querySelector('.dropdown');
            if (dropdown) {
                dropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            }
        });
    </script>
</body>
</html>