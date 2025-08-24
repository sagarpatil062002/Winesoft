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
        /* Ensure only one arrow appears */
        .dropdown-toggle .dropdown-arrow {
            margin-left: auto;
            transition: transform 0.3s ease;
        }
        /* Hide any duplicate arrows */
        .nav-link .material-symbols-rounded:not(.nav-icon):not(.dropdown-arrow) {
            display: none !important;
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

            <!-- Masters Dropdown -->
            <li class="nav-item has-dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <span class="nav-icon material-symbols-rounded">database</span>
                    <span class="nav-label">Masters</span>
                </a>
                <ul class="dropdown">
                    <li class="nav-item"><a href="item_master.php" class="nav-link"><span class="nav-icon material-symbols-rounded">liquor</span><span class="nav-label">Item Master</span></a></li>
                    <li class="nav-item"><a href="barcode_master.php" class="nav-link"><span class="nav-icon material-symbols-rounded">barcode</span><span class="nav-label">Barcode Master</span></a></li>
                    <li class="nav-item"><a href="item_sequence.php" class="nav-link"><span class="nav-icon material-symbols-rounded">format_list_numbered</span><span class="nav-label">Item Sequence</span></a></li>
                    <li class="nav-item"><a href="item_reorder.php" class="nav-link"><span class="nav-icon material-symbols-rounded">low_priority</span><span class="nav-label">Item Reorder</span></a></li>
                    <li class="nav-item"><a href="reference_code.php" class="nav-link"><span class="nav-icon material-symbols-rounded">code</span><span class="nav-label">Reference Code</span></a></li>
                    <li class="nav-item"><a href="customer_price.php" class="nav-link"><span class="nav-icon material-symbols-rounded">receipt_long</span><span class="nav-label">Customer Price</span></a></li>
                    <li class="nav-item"><a href="supplier_master.php" class="nav-link"><span class="nav-icon material-symbols-rounded">local_shipping</span><span class="nav-label">Supplier Master</span></a></li>
                    <li class="nav-item"><a href="ledger_master.php" class="nav-link"><span class="nav-icon material-symbols-rounded">account_balance</span><span class="nav-label">Ledger Master</span></a></li>
                    <li class="nav-item"><a href="permit_master.php" class="nav-link"><span class="nav-icon material-symbols-rounded">verified</span><span class="nav-label">Permit Master</span></a></li>
                    <li class="nav-item"><a href="brand_category.php" class="nav-link"><span class="nav-icon material-symbols-rounded">branding_watermark</span><span class="nav-label">Brand Category</span></a></li>
                    <li class="nav-item"><a href="opening_balance.php" class="nav-link"><span class="nav-icon material-symbols-rounded">account_balance</span><span class="nav-label">Opening Balance</span></a></li>
                    <li class="nav-item"><a href="itemwise_price.php" class="nav-link"><span class="nav-icon material-symbols-rounded">price_check</span><span class="nav-label">ItemWise Price</span></a></li>
                    <li class="nav-item"><a href="purchase_price.php" class="nav-link"><span class="nav-icon material-symbols-rounded">payments</span><span class="nav-label">Purchase Price</span></a></li>
                    
                </ul>
            </li>    
            <li class="nav-item has-dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <span class="nav-icon material-symbols-rounded">receipt_long</span>
                    <span class="nav-label">Transaction</span>

                </a>
                <ul class="dropdown">
                    <li class="nav-item"><a href="purchase_module.php" class="nav-link"><span class="nav-icon material-symbols-rounded">work_history</span><span class="nav-label">Purchases</span></a></li>
                </ul>
            </li>    
            <li class="nav-item"><a href="#" class="nav-link"><span class="nav-icon material-symbols-rounded">point_of_sale</span><span class="nav-label">Registers</span></a></li>
            <li class="nav-item has-dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <span class="nav-icon material-symbols-rounded">summarize</span>
                    <span class="nav-label">Reports</span>

                </a>
                <ul class="dropdown">
                    <li class="nav-item"><a href="purchase_report.php" class="nav-link"><span class="nav-icon material-symbols-rounded">shopping_bag</span><span class="nav-label">Purchase Report</span></a></li>
                </ul>
            </li>
            <!-- Utilities Dropdown -->
            <li class="nav-item has-dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <span class="nav-icon material-symbols-rounded">settings</span>
                    <span class="nav-label">Utilities</span>

                </a>
                <ul class="dropdown">
                    <li class="nav-item">
                        <a href="register.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">app_registration</span>
                            <span class="nav-label">Register</span>

                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="Company_info.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">business</span>
                            <span class="nav-label">Company Info</span>

                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="brandwise_report.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">analytics</span>
                            <span class="nav-label">Brandwise Report</span>
                            
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="dryday.php" class="nav-link">
                            <span class="nav-icon material-symbols-rounded">event_busy</span>
                            <span class="nav-label">Dry Day</span>
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    </nav>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sidebar = document.getElementById('sidebar');
            const mobileMenuToggle = document.getElementById('mobileMenuToggle');
            const dropdownToggles = document.querySelectorAll('.has-dropdown > .dropdown-toggle');

            // Mobile menu toggle
            mobileMenuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                sidebar.classList.toggle('active');
                document.body.classList.toggle('sidebar-active');
            });

            // Loop through all dropdown toggles
            dropdownToggles.forEach(toggle => {
                toggle.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const parentItem = this.closest('.has-dropdown');
                    parentItem.classList.toggle('active');

                    // Rotate arrow
                    const arrow = this.querySelector('.dropdown-arrow');
                    if (arrow) {
                        arrow.style.transform = parentItem.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0)';
                    }
                });
            });

            // Close sidebar on outside click (mobile)
            document.addEventListener('click', function() {
                if (window.innerWidth <= 768 && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    document.body.classList.remove('sidebar-active');
                }
            });

            // Prevent dropdown from closing when clicking inside
            document.querySelectorAll('.dropdown').forEach(dropdown => {
                dropdown.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            });
        });
    </script>
</body>
</html>