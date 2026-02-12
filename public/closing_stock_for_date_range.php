<?php
session_start();
require_once 'license_functions.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";

$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
$sequence_type = isset($_GET['sequence_type']) ? $_GET['sequence_type'] : 'user_defined';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

$comp_id = $_SESSION['CompID'];
$current_stock_column = "Current_Stock" . $comp_id;
$opening_stock_column = "Opening_Stock" . $comp_id;

if (!isset($_SESSION['stock_columns_checked'])) {
    $check_column_query = "SHOW COLUMNS FROM tblitem_stock LIKE '$current_stock_column'";
    $column_result = $conn->query($check_column_query);

    if ($column_result->num_rows == 0) {
        $alter_query = "ALTER TABLE tblitem_stock 
                        ADD COLUMN $opening_stock_column DECIMAL(10,3) DEFAULT 0.000,
                        ADD COLUMN $current_stock_column DECIMAL(10,3) DEFAULT 0.000";
        if (!$conn->query($alter_query)) {
            die("Error creating stock columns: " . $conn->error);
        }
    }
    $_SESSION['stock_columns_checked'] = true;
}

$end_date_day = date('d', strtotime($end_date));
$closing_column = "DAY_" . sprintf('%02d', $end_date_day) . "_CLOSING";
$end_date_month = date('Y-m', strtotime($end_date));

$current_month = date('Y-m');

if ($end_date_month === $current_month) {
    $daily_stock_table = "tbldailystock_" . $comp_id;
} else {
    $end_date_month_short = date('m', strtotime($end_date));
    $end_date_year_short = date('y', strtotime($end_date));
    $daily_stock_table = "tbldailystock_" . $comp_id . "_" . $end_date_month_short . "_" . $end_date_year_short;
}

$order_clause = "";
if ($sequence_type === 'system_defined') {
    $order_clause = "ORDER BY im.CODE ASC";
} elseif ($sequence_type === 'group_defined') {
    $order_clause = "ORDER BY im.DETAILS2 ASC, im.DETAILS ASC";
} else {
    $order_clause = "ORDER BY im.DETAILS ASC";
}

$items_per_page = 100;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

$check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
$table_result = $conn->query($check_table_query);
$table_exists = $table_result->num_rows > 0;

if (!$table_exists) {
    $create_table_query = "CREATE TABLE IF NOT EXISTS $daily_stock_table (
        ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ITEM_CODE VARCHAR(50) NOT NULL,
        STK_MONTH VARCHAR(7) NOT NULL,
        DAY_01_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        LAST_UPDATED TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_item_month (ITEM_CODE, STK_MONTH),
        KEY idx_item_code (ITEM_CODE),
        KEY idx_stk_month (STK_MONTH)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $conn->query($create_table_query);
    $table_exists = true;
}

if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $count_query = "SELECT COUNT(DISTINCT im.CODE) as total 
                    FROM tblitemmaster im
                    LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                        AND ds.STK_MONTH = ?
                    WHERE im.LIQ_FLAG = ? 
                    AND im.CLASS IN ($class_placeholders)
                    AND COALESCE(ds.$closing_column, 0) > 0";
    
    $count_params = array_merge([$end_date_month, $mode], $allowed_classes);
    $count_types = str_repeat('s', count($count_params));
} else {
    $count_query = "SELECT COUNT(*) as total FROM tblitemmaster im WHERE 1 = 0";
    $count_params = [];
    $count_types = "";
}

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

if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     COALESCE(ds.$closing_column, 0) as CURRENT_STOCK
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                  AND ds.STK_MONTH = ?
              WHERE im.LIQ_FLAG = ? 
              AND im.CLASS IN ($class_placeholders)
              AND COALESCE(ds.$closing_column, 0) > 0";
    
    $params = array_merge([$end_date_month, $mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     COALESCE(ds.$closing_column, 0) as CURRENT_STOCK
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE 
                  AND ds.STK_MONTH = ?
              WHERE 1 = 0";
    $params = [$end_date_month];
    $types = "s";
}

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

$total_pages = ceil($total_items / $items_per_page);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['closing_balance'])) {
    foreach ($_POST['closing_balance'] as $item_code => $closing_balance) {
        $closing_val = floatval($closing_balance);
        $_SESSION['closing_balances'][$item_code] = $closing_val;
    }
}

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

if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Closing Stock for Date Range - Enter Closing Balances</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .ajax-loader {
      display: none;
      text-align: center;
      padding: 10px;
    }
    .loader {
      border: 5px solid #f3f3f3;
      border-top: 5px solid #3498db;
      border-radius: 50%;
      width: 30px;
      height: 30px;
      animation: spin 1s linear infinite;
      margin: 0 auto;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
    
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type="number"] {
      -moz-appearance: textfield;
    }
    
    .stock-integer {
        font-weight: bold;
    }

    .closing-input {
        height: 30px !important;
        padding: 2px 6px !important;
    }

    .btn-sm {
        padding: 2px 6px !important;
        font-size: 12px !important;
    }

    tr.has-closing {
        background-color: #e8f5e8 !important;
        border-left: 3px solid #28a745 !important;
    }

    .table-container {
        overflow-x: auto;
        max-width: 100%;
    }

    .styled-table {
        min-width: 800px;
    }

    .pagination {
        margin: 15px 0;
        justify-content: center;
        flex-wrap: wrap;
    }

    .pagination .page-item .page-link {
        color: #007bff;
        border: 1px solid #dee2e6;
        padding: 6px 12px;
        font-size: 14px;
        margin: 2px;
    }

    .pagination .page-item.active .page-link {
        background-color: #007bff;
        border-color: #007bff;
        color: white;
    }

    .pagination-info {
        text-align: center;
        margin: 10px 0;
        color: #6c757d;
        font-size: 14px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Closing Stock for Date Range - Enter Closing Balances</h3>

      <div class="alert alert-info mb-3 py-2">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items with available stock > 0</p>
      </div>

      <?php if (isset($success_message)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <div class="mode-selector mb-3">
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

      <div class="mb-3">
        <label class="form-label">Sequence Type:</label>
        <div class="btn-group" role="group">
          <a href="?mode=<?= $mode ?>&sequence_type=user_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'user_defined' ? 'active' : '' ?>">
            User Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=system_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'system_defined' ? 'active' : '' ?>">
            System Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=group_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'group_defined' ? 'active' : '' ?>">
            Group Defined
          </a>
        </div>
      </div>

      <div class="date-range-container mb-4">
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
            <label class="form-label">Date Range: 
              <span class="fw-bold">
  <?= date('d-M-Y', strtotime($start_date)) . " to " . date('d-M-Y', strtotime($end_date)) ?>
                (<?= $days_count ?> days)
              </span>
            </label>
          </div>
          
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Apply</button>
          </div>
        </form>
      </div>

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
              <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
              <?php if ($search !== ''): ?>
                <a href="?mode=<?= $mode ?>&sequence_type=<?= $sequence_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1" class="btn btn-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
        <div class="col-md-6 text-end">
          <div class="text-muted">
            Total Items with Stock: <?= $total_items ?> | Page: <?= $current_page ?> of <?= $total_pages ?>
            <?php if (count($_SESSION['closing_balances'] ?? []) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['closing_balances']) ?> items with closing balances</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <form method="POST" id="salesForm" action="">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
        <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">
        <input type="hidden" name="update_sales" value="1">

        <div class="d-flex gap-2 mb-3 flex-wrap">
           <button type="button" id="shuffleBtn" class="btn btn-warning btn-action">
             <i class="fas fa-random"></i> Shuffle All
           </button>

           <button type="button" id="generateBillsBtn" class="btn btn-success btn-action">
             <i class="fas fa-save"></i> Generate Bills
           </button>

           <button type="button" id="clearSessionBtn" class="btn btn-danger">
             <i class="fas fa-trash"></i> Clear All
           </button>

           <a href="dashboard.php" class="btn btn-secondary ms-auto">
             <i class="fas fa-sign-out-alt"></i> Exit
           </a>
        </div>

        <div class="table-container">
          <table class="styled-table table-striped" id="itemsTable">
            <thead class="table-header">
              <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Rate (₹)</th>
                <th>Available Stock</th>
                <th>Enter Closing Balance</th>
                <th>Sale Qty</th>
                <th>Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
  <?php if (!empty($items)): ?>
      <?php foreach ($items as $item): 
          $item_code = $item['CODE'];
          $closing_balance = isset($_SESSION['closing_balances'][$item_code]) ? $_SESSION['closing_balances'][$item_code] : $item['CURRENT_STOCK'];
          $sale_qty = $item['CURRENT_STOCK'] - $closing_balance;
          $item_total = $sale_qty * $item['RPRICE'];
          
          $display_stock = floor($item['CURRENT_STOCK']);
          $display_rate = intval($item['RPRICE']);
          $display_closing = floor($closing_balance);
          $display_sale_qty = floor($sale_qty);
          $display_amount = intval($item_total);
          
          $has_sales = $closing_balance < $item['CURRENT_STOCK'];
      ?>
          <tr class="<?= $has_sales ? 'has-closing' : '' ?>">
              <td><?= htmlspecialchars($item_code); ?></td>
              <td><?= htmlspecialchars($item['DETAILS']); ?></td>
              <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
              <td class="stock-integer"><?= number_format($display_rate); ?></td>
              <td><span class="stock-integer"><?= number_format($display_stock); ?></span></td>
              <td>
                  <input type="number" name="closing_balance[<?= htmlspecialchars($item_code); ?>]" 
                         class="form-control closing-input" min="0" 
                         max="<?= floor($item['CURRENT_STOCK']); ?>" 
                         step="0.001" value="<?= $closing_balance ?>" 
                         data-rate="<?= $item['RPRICE'] ?>"
                         data-code="<?= htmlspecialchars($item_code); ?>"
                         data-stock="<?= $item['CURRENT_STOCK'] ?>"
                         oninput="validateClosingBalance(this)">
              </td>
              <td class="sale-qty-cell <?= $sale_qty > 0 ? 'positive' : 'zero' ?>" id="sale_qty_<?= htmlspecialchars($item_code); ?>">
                  <span class="stock-integer"><?= number_format($display_sale_qty) ?></span>
              </td>
              <td class="amount-cell" id="amount_<?= htmlspecialchars($item_code); ?>">
                  <span class="stock-integer"><?= number_format($display_amount) ?></span>
              </td>
          </tr>
      <?php endforeach; ?>
  <?php else: ?>
      <tr>
          <td colspan="8" class="text-center text-muted">
              <div class="py-4">
                  <i class="fas fa-box-open fa-2x mb-3 text-muted"></i>
                  <h5>No items found with available stock</h5>
                  <?php if ($search !== ''): ?>
                      <p class="mb-1">Try a different search term</p>
                  <?php endif; ?>
                  <p class="mb-0"><small>Note: Only items with stock > 0 are shown</small></p>
              </div>
          </td>
      </tr>
  <?php endif; ?>
            </tbody>
          </table>
        </div>
        
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                
                <?php
                $show_pages = 5;
                $start_page = max(1, $current_page - floor($show_pages / 2));
                $end_page = min($total_pages, $start_page + $show_pages - 1);
                
                if ($end_page - $start_page < $show_pages - 1) {
                    $start_page = max(1, $end_page - $show_pages + 1);
                }
                
                if ($start_page > 1): ?>
                    <li class="page-item <?= 1 == $current_page ? 'active' : '' ?>">
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
                    <li class="page-item <?= $total_pages == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
                    </li>
                <?php endif; ?>
                
                <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
        <div class="pagination-info">
            Showing <?= count($items) ?> of <?= $total_items ?> items with stock > 0 (Page <?= $current_page ?> of <?= $total_pages ?>)
        </div>
        <?php endif; ?>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const allSessionClosingBalances = <?= json_encode($_SESSION['closing_balances'] ?? []) ?>;

function validateClosingBalance(input) {
    const itemCode = $(input).data('code');
    const currentStock = parseFloat($(input).data('stock'));
    let closingBalance = parseFloat($(input).val()) || 0;
    
    if (isNaN(closingBalance) || closingBalance < 0) {
        closingBalance = 0;
        $(input).val(0);
    }
    
    if (closingBalance > currentStock) {
        closingBalance = currentStock;
        $(input).val(currentStock);
    }
    
    updateItemUIFromClosing(itemCode, closingBalance, currentStock);
}

function updateItemUIFromClosing(itemCode, closingBalance, currentStock) {
    const rate = parseFloat($('input[name="closing_balance[' + itemCode + ']"]').data('rate'));
    const saleQty = currentStock - closingBalance;
    const amount = saleQty * rate;
    
    const displaySaleQty = Math.floor(saleQty);
    const displayAmount = Math.floor(amount);
    
    $('#sale_qty_' + itemCode).html('<span class="stock-integer">' + displaySaleQty.toLocaleString() + '</span>');
    $('#amount_' + itemCode).html('<span class="stock-integer">' + displayAmount.toLocaleString() + '</span>');
    
    const row = $('input[name="closing_balance[' + itemCode + ']"]').closest('tr');
    row.toggleClass('has-closing', closingBalance < currentStock);
}

function generateBills() {
    let hasEntries = false;
    for (const itemCode in allSessionClosingBalances) {
        hasEntries = true;
        break;
    }
    
    if (!hasEntries) {
        alert('Please enter closing balances for at least one item.');
        return false;
    }
    
    document.getElementById('salesForm').submit();
    return true;
}

$(document).ready(function() {
    $('#generateBillsBtn').click(function() {
        generateBills();
    });
    
    $('#clearSessionBtn').click(function() {
        if (confirm('Are you sure you want to clear all closing balances?')) {
            $.ajax({
                url: 'clear_session_quantities.php',
                type: 'POST',
                success: function() {
                    location.reload();
                }
            });
        }
    });
});
</script>
</body>
</html>
