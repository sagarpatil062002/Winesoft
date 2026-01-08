<?php
session_start();
require_once 'drydays_functions.php';
require_once 'license_functions.php';
require_once 'cash_memo_functions.php';

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/sales_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Function to get stock table name based on date
function getStockTableName($comp_id, $date) {
    $current_month = date('Y-m');
    $sale_month = date('Y-m', strtotime($date));
    
    if ($sale_month === $current_month) {
        return "tbldailystock_" . $comp_id; // Current month table
    } else {
        // Archived month table - format: tbldailystock_compid_mm_yy
        $month_year = date('m_y', strtotime($date)); // e.g., "12_25" for December 2025
        return "tbldailystock_" . $comp_id . "_" . $month_year;
    }
}

// Function to get closing stock for a specific date
function getClosingStockForDate($conn, $comp_id, $item_code, $date) {
    $stock_table = getStockTableName($comp_id, $date);
    $day_num = sprintf('%02d', date('d', strtotime($date)));
    $closing_column = "DAY_{$day_num}_CLOSING";
    $month_year = date('Y-m', strtotime($date));
    
    // Check if table exists
    $check_table = "SHOW TABLES LIKE '$stock_table'";
    $table_result = $conn->query($check_table);
    
    if ($table_result->num_rows == 0) {
        logMessage("Stock table $stock_table not found for date $date", 'WARNING');
        return 0; // Table doesn't exist
    }
    
    $query = "SELECT $closing_column FROM $stock_table 
              WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $item_code, $month_year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $closing_stock = $row[$closing_column] ?? 0;
        $stmt->close();
        return $closing_stock;
    }
    
    $stmt->close();
    return 0; // Item not found in stock table
}

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    logMessage('User not logged in, redirecting to index.php', 'WARNING');
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    logMessage('Company or financial year not set, redirecting to index.php', 'WARNING');
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Mode selection
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
$sequence_type = isset($_GET['sequence_type']) ? $_GET['sequence_type'] : 'user_defined';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Date range selection
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Validate date range
if (strtotime($end_date) < strtotime($start_date)) {
    $end_date = $start_date;
}

$comp_id = $_SESSION['CompID'];

// Log selected dates
logMessage("=== SALES PAGE LOAD ===");
logMessage("Selected date range: $start_date to $end_date");
logMessage("Company ID: $comp_id");

// Build order clause
$order_clause = "";
if ($sequence_type === 'system_defined') {
    $order_clause = "ORDER BY im.CODE ASC";
} elseif ($sequence_type === 'group_defined') {
    $order_clause = "ORDER BY im.DETAILS2 ASC, im.DETAILS ASC";
} else {
    $order_clause = "ORDER BY im.DETAILS ASC";
}

// Pagination
$items_per_page = 50;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Get closing stock column for end date
$end_day_num = sprintf('%02d', date('d', strtotime($end_date)));
$closing_column = "DAY_{$end_day_num}_CLOSING";

// Get stock table name for end date
$stock_table = getStockTableName($comp_id, $end_date);
$month_year = date('Y-m', strtotime($end_date));

// Check if stock table exists
$check_table = "SHOW TABLES LIKE '$stock_table'";
$table_result = $conn->query($check_table);

if ($table_result->num_rows == 0) {
    $stock_exists = false;
    logMessage("Stock table $stock_table does not exist for end date $end_date", 'WARNING');
} else {
    $stock_exists = true;
    logMessage("Using stock table: $stock_table, Month: $month_year, Closing Column: $closing_column");
}

// Get total count of items with closing stock > 0
if (!empty($allowed_classes) && $stock_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $count_query = "SELECT COUNT(*) as total 
                   FROM tblitemmaster im
                   LEFT JOIN $stock_table st ON im.CODE = st.ITEM_CODE AND st.STK_MONTH = ?
                   WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders)
                   AND (COALESCE(st.$closing_column, 0) > 0)";
    
    $count_params = array_merge([$month_year, $mode], $allowed_classes);
    $count_types = str_repeat('s', count($count_params));
    
    if ($search !== '') {
        $count_query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
        $count_params[] = "%$search%";
        $count_params[] = "%$search%";
        $count_types .= "ss";
    }
    
    $count_stmt = $conn->prepare($count_query);
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_items = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
    
    // Get items with pagination
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, im.LIQ_FLAG,
                     COALESCE(st.$closing_column, 0) as CLOSING_STOCK
              FROM tblitemmaster im
              LEFT JOIN $stock_table st ON im.CODE = st.ITEM_CODE AND st.STK_MONTH = ?
              WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders)
              AND (COALESCE(st.$closing_column, 0) > 0)";
    
    $params = array_merge([$month_year, $mode], $allowed_classes);
    $types = str_repeat('s', count($params));
    
    if ($search !== '') {
        $query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= "ss";
    }
    
    $query .= " " . $order_clause . " LIMIT ? OFFSET ?";
    $params[] = $items_per_page;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
} else {
    $total_items = 0;
    $items = [];
    if (!$stock_exists) {
        logMessage("No stock table found for the selected date", 'ERROR');
    }
}

$total_pages = ceil($total_items / $items_per_page);

// Initialize session quantities
if (!isset($_SESSION['sale_quantities'])) {
    $_SESSION['sale_quantities'] = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sale_qty'])) {
    foreach ($_POST['sale_qty'] as $item_code => $qty) {
        $qty_val = intval($qty);
        if ($qty_val > 0) {
            $_SESSION['sale_quantities'][$item_code] = $qty_val;
        } else {
            unset($_SESSION['sale_quantities'][$item_code]);
        }
    }
    
    // Log the update
    logMessage("Session quantities updated: " . count($_SESSION['sale_quantities']) . " items");
    
    // If update_sales is clicked, validate and redirect
    if (isset($_POST['update_sales'])) {
        $stock_errors = [];
        
        foreach ($_SESSION['sale_quantities'] as $item_code => $qty) {
            if ($qty > 0) {
                // Get closing stock for this item on end date
                $closing_stock = getClosingStockForDate($conn, $comp_id, $item_code, $end_date);
                
                logMessage("Validating item $item_code: Requested $qty, Available $closing_stock");
                
                if ($qty > $closing_stock) {
                    $stock_errors[] = "Item $item_code: Available stock $closing_stock, Requested $qty";
                }
            }
        }
        
        if (empty($stock_errors)) {
            // All good, redirect to generate bills
            header("Location: generate_bills.php?start_date=" . urlencode($start_date) . 
                   "&end_date=" . urlencode($end_date) . "&mode=" . urlencode($mode));
            exit;
        } else {
            $error_message = "Stock validation failed:<br>" . implode("<br>", array_slice($stock_errors, 0, 5));
            if (count($stock_errors) > 5) {
                $error_message .= "<br>... and " . (count($stock_errors) - 5) . " more errors";
            }
            logMessage("Stock validation failed: " . implode(", ", $stock_errors), 'ERROR');
        }
    }
}

// Create date range array
$begin = new DateTime($start_date);
$end = new DateTime($end_date);
$end = $end->modify('+1 day');
$interval = new DateInterval('P1D');
$date_range = new DatePeriod($begin, $interval, $end);

$date_array = [];
foreach ($date_range as $date) {
    $date_array[] = $date->format("Y-m-d");
}
$days_count = count($date_array);

// Debug info
$debug_info = [
    'date_range' => "$start_date to $end_date",
    'days_count' => $days_count,
    'end_day_num' => $end_day_num,
    'stock_table' => $stock_table,
    'month_year' => $month_year,
    'closing_column' => $closing_column,
    'stock_exists' => $stock_exists,
    'total_items' => $total_items,
    'session_quantities' => count($_SESSION['sale_quantities']),
    'user_id' => $_SESSION['user_id'],
    'comp_id' => $comp_id
];
logMessage("Debug Info: " . json_encode($debug_info));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales by Date Range - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
    <style>
        .stock-info {
            font-weight: bold;
        }
        .stock-available {
            color: #28a745;
        }
        .stock-low {
            color: #ffc107;
        }
        .stock-zero {
            color: #dc3545;
        }
        .has-quantity {
            background-color: #e8f5e8 !important;
            border-left: 3px solid #28a745 !important;
        }
        .qty-input {
            width: 100px;
            text-align: center;
        }
        .validation-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 500px;
        }
        .date-info-card {
            background-color: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        .table th {
            white-space: nowrap;
        }
        .table td {
            vertical-align: middle;
        }
        .btn-action {
            min-width: 120px;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>

    <div class="main-content">
        <?php include 'components/header.php'; ?>

        <div class="content-area">
            <h3 class="mb-4">Sales by Date Range</h3>

            <!-- Date Information -->
            <div class="card date-info-card mb-4">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="card-title mb-2">
                                <i class="fas fa-calendar-alt"></i> Selected Date Range
                            </h5>
                            <p class="card-text mb-1">
                                <strong>From:</strong> <?= date('d-M-Y', strtotime($start_date)) ?>
                                <strong>To:</strong> <?= date('d-M-Y', strtotime($end_date)) ?>
                                (<?= $days_count ?> days)
                            </p>
                            <p class="card-text mb-0">
                                <strong>Stock Display:</strong> Showing closing stock as on <?= date('d-M-Y', strtotime($end_date)) ?>
                                <span class="badge bg-info">Day <?= $end_day_num ?> Closing</span>
                            </p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="text-muted">
                                <small>Table: <?= $stock_table ?></small><br>
                                <small>Month: <?= $month_year ?></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- License Info -->
            <div class="alert alert-info mb-3">
                <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
                <p class="mb-0">Showing items for classes: 
                    <?php 
                    if (!empty($available_classes)) {
                        $class_names = [];
                        foreach ($available_classes as $class) {
                            $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                        }
                        echo implode(', ', $class_names);
                    } else {
                        echo 'No classes available for your license type';
                    }
                    ?>
                </p>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?= $success_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            
            <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?= $error_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <!-- Client-side Validation Alert -->
            <div class="alert alert-warning validation-alert" id="clientValidationAlert" style="display: none;">
                <i class="fas fa-exclamation-triangle"></i>
                <span id="validationMessage"></span>
            </div>

            <!-- Mode and Sequence Selector -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label">Liquor Mode:</label>
                    <div class="btn-group" role="group">
                        <a href="?mode=F&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
                           class="btn btn-outline-primary <?= $mode === 'F' ? 'active' : '' ?>">
                            Foreign Liquor
                        </a>
                        <a href="?mode=C&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
                           class="btn btn-outline-primary <?= $mode === 'C' ? 'active' : '' ?>">
                            Country Liquor
                        </a>
                        <a href="?mode=O&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
                           class="btn btn-outline-primary <?= $mode === 'O' ? 'active' : '' ?>">
                            Others
                        </a>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Sequence Type:</label>
                    <div class="btn-group" role="group">
                        <a href="?mode=<?= $mode ?>&sequence_type=user_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
                           class="btn btn-outline-secondary <?= $sequence_type === 'user_defined' ? 'active' : '' ?>">
                            User Defined
                        </a>
                        <a href="?mode=<?= $mode ?>&sequence_type=system_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
                           class="btn btn-outline-secondary <?= $sequence_type === 'system_defined' ? 'active' : '' ?>">
                            System Defined
                        </a>
                        <a href="?mode=<?= $mode ?>&sequence_type=group_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
                           class="btn btn-outline-secondary <?= $sequence_type === 'group_defined' ? 'active' : '' ?>">
                            Group Defined
                        </a>
                    </div>
                </div>
            </div>

            <!-- Date Range Selection -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3 align-items-end">
                        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
                        <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
                        <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>">
                        <input type="hidden" name="page" value="1">
                        
                        <div class="col-md-3">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" name="start_date" class="form-control" 
                                   value="<?= htmlspecialchars($start_date); ?>" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" name="end_date" class="form-control" 
                                   value="<?= htmlspecialchars($end_date); ?>" required>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="form-text">
                                <strong>Range:</strong> <?= $days_count ?> days<br>
                                <small>Stock shown as on end date</small>
                            </div>
                        </div>
                        
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-sync-alt"></i> Apply
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Search and Info -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <form method="GET" class="search-control">
                        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
                        <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
                        <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
                        <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">
                        <input type="hidden" name="page" value="1">
                        <div class="input-group">
                            <input type="text" name="search" class="form-control"
                                   placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <?php if ($search !== ''): ?>
                                <a href="?mode=<?= $mode ?>&sequence_type=<?= $sequence_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1" 
                                   class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                <div class="col-md-6 text-end">
                    <div class="text-muted">
                        <i class="fas fa-box"></i> Items with Stock: <?= $total_items ?> 
                        | <i class="fas fa-file"></i> Page: <?= $current_page ?> of <?= $total_pages ?>
                        <?php if (count($_SESSION['sale_quantities']) > 0): ?>
                            | <span class="text-success">
                                <i class="fas fa-shopping-cart"></i> 
                                <?= count($_SESSION['sale_quantities']) ?> items with quantities
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sales Form -->
            <form method="POST" id="salesForm">
                <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
                <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">

                <!-- Action Buttons -->
                <div class="d-flex gap-2 mb-3 flex-wrap">
                    <button type="button" id="generateBillsBtn" class="btn btn-success btn-action">
                        <i class="fas fa-file-invoice"></i> Generate Bills
                    </button>
                    
                    <button type="button" id="clearSessionBtn" class="btn btn-danger btn-action">
                        <i class="fas fa-trash"></i> Clear All
                    </button>
                    
                    <button type="button" id="validateStockBtn" class="btn btn-warning btn-action">
                        <i class="fas fa-check-circle"></i> Validate Stock
                    </button>
                    
                    <a href="dashboard.php" class="btn btn-secondary ms-auto">
                        <i class="fas fa-sign-out-alt"></i> Exit
                    </a>
                </div>

                <!-- Items Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="itemsTable">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Item Code</th>
                                <th>Item Name</th>
                                <th>Category</th>
                                <th>Rate (₹)</th>
                                <th>Closing Stock (<?= date('d-M', strtotime($end_date)) ?>)</th>
                                <th>Sale Qty</th>
                                <th>Remaining Stock</th>
                                <th>Amount (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($items)): ?>
                                <?php $counter = ($current_page - 1) * $items_per_page + 1; ?>
                                <?php foreach ($items as $item): 
                                    $item_code = $item['CODE'];
                                    $item_qty = isset($_SESSION['sale_quantities'][$item_code]) ? $_SESSION['sale_quantities'][$item_code] : 0;
                                    $closing_stock = $item['CLOSING_STOCK'];
                                    $remaining_stock = $closing_stock - $item_qty;
                                    $amount = $item_qty * $item['RPRICE'];
                                    
                                    // Determine stock class
                                    $stock_class = 'stock-available';
                                    if ($closing_stock <= 0) {
                                        $stock_class = 'stock-zero';
                                    } elseif ($closing_stock < 10) {
                                        $stock_class = 'stock-low';
                                    }
                                ?>
                                    <tr data-code="<?= htmlspecialchars($item_code); ?>" 
                                        data-stock="<?= $closing_stock ?>"
                                        data-rate="<?= $item['RPRICE'] ?>"
                                        class="<?= $item_qty > 0 ? 'has-quantity' : '' ?>">
                                        <td><?= $counter++; ?></td>
                                        <td><strong><?= htmlspecialchars($item_code); ?></strong></td>
                                        <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                                        <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                                        <td class="text-end"><?= number_format($item['RPRICE'], 2); ?></td>
                                        <td class="text-end">
                                            <span class="stock-info <?= $stock_class ?>">
                                                <?= number_format($closing_stock, 3); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <input type="number" 
                                                   name="sale_qty[<?= htmlspecialchars($item_code); ?>]" 
                                                   class="form-control qty-input" 
                                                   min="0" 
                                                   max="<?= floor($closing_stock); ?>"
                                                   step="1" 
                                                   value="<?= $item_qty ?>" 
                                                   data-code="<?= htmlspecialchars($item_code); ?>"
                                                   data-stock="<?= $closing_stock ?>"
                                                   data-rate="<?= $item['RPRICE'] ?>"
                                                   oninput="validateQuantity(this)">
                                        </td>
                                        <td class="text-end" id="remaining_<?= htmlspecialchars($item_code); ?>">
                                            <?= number_format($remaining_stock, 3); ?>
                                        </td>
                                        <td class="text-end" id="amount_<?= htmlspecialchars($item_code); ?>">
                                            <?= number_format($amount, 2); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <?php if ($stock_exists): ?>
                                            <i class="fas fa-box-open fa-2x mb-3"></i><br>
                                            No items found with stock on <?= date('d-M-Y', strtotime($end_date)) ?>.
                                        <?php else: ?>
                                            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i><br>
                                            Stock table not found for the selected date range.<br>
                                            <small>Please select a valid date range with existing stock data.</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                        <?php if (!empty($items)): ?>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="5" class="text-end"><strong>Total:</strong></td>
                                <td class="text-end">
                                    <strong><?= number_format(array_sum(array_column($items, 'CLOSING_STOCK')), 3); ?></strong>
                                </td>
                                <td class="text-end">
                                    <strong id="totalQty"><?= array_sum($_SESSION['sale_quantities'] ?? []); ?></strong>
                                </td>
                                <td class="text-end">
                                    <strong id="totalRemaining">
                                        <?= number_format(array_sum(array_column($items, 'CLOSING_STOCK')) - array_sum($_SESSION['sale_quantities'] ?? []), 3); ?>
                                    </strong>
                                </td>
                                <td class="text-end">
                                    <strong id="totalAmount">
                                        <?php
                                        $total_amount = 0;
                                        foreach ($items as $item) {
                                            $item_code = $item['CODE'];
                                            $item_qty = $_SESSION['sale_quantities'][$item_code] ?? 0;
                                            $total_amount += $item_qty * $item['RPRICE'];
                                        }
                                        echo number_format($total_amount, 2);
                                        ?>
                                    </strong>
                                </td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        </li>
                        
                        <?php
                        $show_pages = 5;
                        $start_page = max(1, $current_page - floor($show_pages / 2));
                        $end_page = min($total_pages, $start_page + $show_pages - 1);
                        
                        if ($start_page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                            </li>
                            <?php if ($start_page > 2): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif;
                        endif;
                        
                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                            <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor;
                        
                        if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <li class="page-item disabled">
                                    <span class="page-link">...</span>
                                </li>
                            <?php endif; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
                            </li>
                        <?php endif; ?>
                        
                        <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <div class="text-center text-muted mb-3">
                    Showing <?= count($items) ?> of <?= $total_items ?> items
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global variables
const allSessionQuantities = <?= json_encode($_SESSION['sale_quantities'] ?? []) ?>;
const dateArray = <?= json_encode($date_array) ?>;
const daysCount = <?= $days_count ?>;
const endDate = '<?= $end_date ?>';
const endDayNum = <?= $end_day_num ?>;
const compId = <?= $comp_id ?>;

// Function to show validation alert
function showValidationAlert(message, type = 'warning') {
    const alertClass = type === 'warning' ? 'alert-warning' : 'alert-danger';
    $('#validationMessage').text(message);
    $('#clientValidationAlert').removeClass('alert-warning alert-danger').addClass(alertClass).fadeIn();
    
    setTimeout(() => {
        $('#clientValidationAlert').fadeOut();
    }, 5000);
}

// Function to validate quantity
function validateQuantity(input) {
    const itemCode = $(input).data('code');
    const maxStock = parseInt($(input).data('stock')) || 0;
    const rate = parseFloat($(input).data('rate')) || 0;
    let enteredQty = parseInt($(input).val()) || 0;
    
    // Validate input
    if (isNaN(enteredQty) || enteredQty < 0) {
        enteredQty = 0;
        $(input).val(0);
    }
    
    // Prevent exceeding stock
    if (enteredQty > maxStock) {
        enteredQty = maxStock;
        $(input).val(maxStock);
        showValidationAlert(`Maximum available stock for ${itemCode} is ${maxStock}`);
    }
    
    // Update UI
    updateItemUI(itemCode, enteredQty, maxStock, rate);
    
    // Save to session
    saveQuantityToSession(itemCode, enteredQty);
    
    return true;
}

// Update item UI
function updateItemUI(itemCode, qty, maxStock, rate) {
    const remaining = maxStock - qty;
    const amount = qty * rate;
    
    // Update cells
    $(`#remaining_${itemCode}`).text(remaining.toFixed(3));
    $(`#amount_${itemCode}`).text(amount.toFixed(2));
    
    // Update row styling
    const row = $(`input[data-code="${itemCode}"]`).closest('tr');
    row.toggleClass('has-quantity', qty > 0);
    
    // Highlight negative remaining stock
    const remainingCell = $(`#remaining_${itemCode}`);
    remainingCell.removeClass('text-danger fw-bold');
    if (remaining < 0) {
        remainingCell.addClass('text-danger fw-bold');
    }
    
    // Update totals
    updateTotals();
}

// Update totals
function updateTotals() {
    let totalQty = 0;
    let totalRemaining = 0;
    let totalAmount = 0;
    let totalStock = 0;
    
    // Get all quantity inputs
    $('input.qty-input').each(function() {
        const itemCode = $(this).data('code');
        const qty = parseInt($(this).val()) || 0;
        const stock = parseFloat($(this).data('stock')) || 0;
        const rate = parseFloat($(this).data('rate')) || 0;
        
        totalQty += qty;
        totalStock += stock;
        totalRemaining += (stock - qty);
        totalAmount += (qty * rate);
    });
    
    $('#totalQty').text(totalQty);
    $('#totalRemaining').text(totalRemaining.toFixed(3));
    $('#totalAmount').text(totalAmount.toFixed(2));
}

// Save quantity to session
function saveQuantityToSession(itemCode, qty) {
    // Debounce to prevent too many requests
    clearTimeout(window.saveQuantityTimeout);
    window.saveQuantityTimeout = setTimeout(() => {
        $.ajax({
            url: 'update_session_quantity.php',
            type: 'POST',
            data: {
                item_code: itemCode,
                quantity: qty
            },
            success: function(response) {
                // Update global object
                if (qty > 0) {
                    allSessionQuantities[itemCode] = qty;
                } else {
                    delete allSessionQuantities[itemCode];
                }
                console.log('Quantity saved:', itemCode, qty);
            },
            error: function() {
                console.error('Failed to save quantity');
            }
        });
    }, 300);
}

// Validate all quantities before submission
function validateAllQuantities() {
    let hasQuantity = false;
    let errors = [];
    
    for (const itemCode in allSessionQuantities) {
        const qty = allSessionQuantities[itemCode];
        if (qty > 0) {
            hasQuantity = true;
            
            // Get stock from input field
            const input = $(`input[data-code="${itemCode}"]`);
            if (input.length > 0) {
                const stock = parseFloat(input.data('stock')) || 0;
                
                if (qty > stock) {
                    errors.push(`Item ${itemCode}: Requested ${qty}, Available ${stock}`);
                }
            }
        }
    }
    
    if (!hasQuantity) {
        showValidationAlert('Please enter quantities for at least one item.');
        return false;
    }
    
    if (errors.length > 0) {
        showValidationAlert('Stock validation failed. Please adjust quantities.');
        console.log('Validation errors:', errors);
        return false;
    }
    
    return true;
}

// Generate bills
function generateBills() {
    if (!validateAllQuantities()) {
        return false;
    }
    
    // Add update_sales flag to form and submit
    $('<input>').attr({
        type: 'hidden',
        name: 'update_sales',
        value: '1'
    }).appendTo('#salesForm');
    
    // Show loading state
    $('#generateBillsBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');
    
    // Submit the form
    $('#salesForm').submit();
}

// Clear session quantities
function clearSessionQuantities() {
    if (confirm('Are you sure you want to clear all quantities? This action cannot be undone.')) {
        $.ajax({
            url: 'clear_session_quantities.php',
            type: 'POST',
            success: function(response) {
                location.reload();
            },
            error: function() {
                showValidationAlert('Error clearing quantities. Please try again.');
            }
        });
    }
}

// Validate stock for all items
function validateStockForAll() {
    let validationResults = [];
    let hasErrors = false;
    
    $('input.qty-input').each(function() {
        const itemCode = $(this).data('code');
        const qty = parseInt($(this).val()) || 0;
        const stock = parseFloat($(this).data('stock')) || 0;
        
        if (qty > 0) {
            if (qty > stock) {
                validationResults.push(`❌ ${itemCode}: Requested ${qty}, Available ${stock}`);
                hasErrors = true;
                $(this).addClass('is-invalid');
            } else {
                validationResults.push(`✅ ${itemCode}: OK (${qty} <= ${stock})`);
                $(this).removeClass('is-invalid');
            }
        }
    });
    
    if (validationResults.length === 0) {
        showValidationAlert('No quantities entered for validation.', 'warning');
    } else if (hasErrors) {
        showValidationAlert('Stock validation failed for some items. Please check quantities.', 'danger');
        console.log('Validation results:', validationResults);
    } else {
        showValidationAlert('All quantities are within available stock limits!', 'warning');
    }
}

// Initialize on page load
$(document).ready(function() {
    console.log('Sales Page Initialized');
    console.log('Date Range:', dateArray);
    console.log('End Date:', endDate, '(Day', endDayNum, ')');
    console.log('Company ID:', compId);
    console.log('Session Quantities:', allSessionQuantities);
    
    // Initialize quantities from session
    $('input.qty-input').each(function() {
        const itemCode = $(this).data('code');
        if (allSessionQuantities[itemCode] !== undefined) {
            $(this).val(allSessionQuantities[itemCode]);
            const stock = parseFloat($(this).data('stock')) || 0;
            const rate = parseFloat($(this).data('rate')) || 0;
            updateItemUI(itemCode, allSessionQuantities[itemCode], stock, rate);
        }
    });
    
    // Update totals
    updateTotals();
    
    // Event handlers
    $('#generateBillsBtn').click(generateBills);
    $('#clearSessionBtn').click(clearSessionQuantities);
    $('#validateStockBtn').click(validateStockForAll);
    
    // Quantity input events
    $(document).on('input', 'input.qty-input', function() {
        validateQuantity(this);
    });
    
    // Quantity change events
    $(document).on('change', 'input.qty-input', function() {
        validateQuantity(this);
    });
    
    // Prevent form submission on Enter in quantity fields
    $(document).on('keydown', 'input.qty-input', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).blur();
        }
    });
    
    // Auto-focus first quantity field
    if ($('input.qty-input').length > 0) {
        $('input.qty-input').first().focus();
    }
});
</script>
</body>
</html>
