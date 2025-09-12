<?php
session_start();

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php"; // MySQLi connection in $conn

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Sequence type selection (default user_defined)
$sequence_type = isset($_GET['sequence_type']) ? $_GET['sequence_type'] : 'user_defined';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Date range selection (default to current day only)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Get company ID
$comp_id = $_SESSION['CompID'];
$daily_stock_table = "tbldailystock_" . $comp_id;
$opening_stock_column = "Opening_Stock" . $comp_id;
$current_stock_column = "Current_Stock" . $comp_id;

// Check if the stock columns exist, if not create them
$check_column_query = "SHOW COLUMNS FROM tblitem_stock LIKE '$current_stock_column'";
$column_result = $conn->query($check_column_query);

if ($column_result->num_rows == 0) {
    // Columns don't exist, create them
    $alter_query = "ALTER TABLE tblitem_stock 
                    ADD COLUMN $opening_stock_column DECIMAL(10,3) DEFAULT 0.000,
                    ADD COLUMN $current_stock_column DECIMAL(10,3) DEFAULT 0.000";
    if (!$conn->query($alter_query)) {
        die("Error creating stock columns: " . $conn->error);
    }
}

// Build query based on sequence type
if ($sequence_type === 'system_defined') {
    $order_clause = "ORDER BY im.CODE ASC";
} elseif ($sequence_type === 'group_defined') {
    $order_clause = "ORDER BY im.DETAILS2 ASC, im.DETAILS ASC";
} else {
    // User defined (default)
    $order_clause = "ORDER BY im.DETAILS ASC";
}

// Fetch items from tblitemmaster with their current stock
$query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, 
                 COALESCE(st.$current_stock_column, 0) as CURRENT_STOCK
          FROM tblitemmaster im
          LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE 
          WHERE im.LIQ_FLAG = ?";
$params = [$mode];
$types = "s";

// Add search condition if provided
if ($search !== '') {
    $query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " " . $order_clause;

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Create date range array
$begin = new DateTime($start_date);
$end = new DateTime($end_date);
$end = $end->modify('+1 day'); // Include end date

$interval = new DateInterval('P1D');
$date_range = new DatePeriod($begin, $interval, $end);

$date_array = [];
foreach ($date_range as $date) {
    $date_array[] = $date->format("Y-m-d");
}
$days_count = count($date_array);

// Function to distribute sales uniformly
function distributeSales($total_qty, $days_count) {
    if ($total_qty <= 0 || $days_count <= 0) return array_fill(0, $days_count, 0);
    
    $base_qty = floor($total_qty / $days_count);
    $remainder = $total_qty % $days_count;
    
    $daily_sales = array_fill(0, $days_count, $base_qty);
    
    // Distribute remainder evenly across days
    for ($i = 0; $i < $remainder; $i++) {
        $daily_sales[$i]++;
    }
    
    // Shuffle the distribution to make it look more natural
    shuffle($daily_sales);
    
    return $daily_sales;
}

// Get next bill number
function getNextBillNumber($conn) {
    $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    $next_bill = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
    return $next_bill;
}

// Function to update daily stock table with proper opening/closing calculations
function updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id) {
    // Extract day number from date (e.g., 2025-09-03 -> day 03)
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $sales_column = "DAY_{$day_num}_SALES";
    $closing_column = "DAY_{$day_num}_CLOSING";
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    
    $month_year = date('Y-m', strtotime($sale_date));
    
    // Check if record exists for this month and item
    $check_query = "SELECT COUNT(*) as count FROM $daily_stock_table 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $month_year, $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Get current values to calculate closing properly
        $select_query = "SELECT $opening_column, $purchase_column, $sales_column 
                         FROM $daily_stock_table 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $select_stmt = $conn->prepare($select_query);
        $select_stmt->bind_param("ss", $month_year, $item_code);
        $select_stmt->execute();
        $select_result = $select_stmt->get_result();
        $current_values = $select_result->fetch_assoc();
        $select_stmt->close();
        
        $opening = $current_values[$opening_column] ?? 0;
        $purchase = $current_values[$purchase_column] ?? 0;
        $current_sales = $current_values[$sales_column] ?? 0;
        
        // Calculate new sales and closing
        $new_sales = $current_sales + $qty;
        $new_closing = $opening + $purchase - $new_sales;
        
        // Update existing record with correct closing calculation
        $update_query = "UPDATE $daily_stock_table 
                         SET $sales_column = ?, 
                             $closing_column = ?,
                             LAST_UPDATED = CURRENT_TIMESTAMP 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ddss", $new_sales, $new_closing, $month_year, $item_code);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Update next day's opening stock if it exists
        $next_day = intval($day_num) + 1;
        if ($next_day <= 31) {
            $next_day_num = sprintf('%02d', $next_day);
            $next_opening_column = "DAY_{$next_day_num}_OPEN";
            
            // Check if next day exists in the table
            $check_next_day_query = "SHOW COLUMNS FROM $daily_stock_table LIKE '$next_opening_column'";
            $next_day_result = $conn->query($check_next_day_query);
            
            if ($next_day_result->num_rows > 0) {
                // Update next day's opening to match current day's closing
                $update_next_query = "UPDATE $daily_stock_table 
                                     SET $next_opening_column = ?,
                                         LAST_UPDATED = CURRENT_TIMESTAMP 
                                     WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_next_stmt = $conn->prepare($update_next_query);
                $update_next_stmt->bind_param("dss", $new_closing, $month_year, $item_code);
                $update_next_stmt->execute();
                $update_next_stmt->close();
            }
        }
    } else {
        // For new records, opening and purchase are typically 0 unless specified otherwise
        $closing = 0 - $qty; // Since opening and purchase are 0
        
        // Create new record
        $insert_query = "INSERT INTO $daily_stock_table 
                         (STK_MONTH, ITEM_CODE, LIQ_FLAG, $opening_column, $purchase_column, $sales_column, $closing_column) 
                         VALUES (?, ?, 'F', 0, 0, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssdd", $month_year, $item_code, $qty, $closing);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
}

// Handle form submission for sales update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_sales'])) {
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $comp_id = $_SESSION['CompID'];
        $user_id = $_SESSION['user_id'];
        $fin_year_id = $_SESSION['FIN_YEAR_ID'];
        
        // Get next bill number
        $next_bill = getNextBillNumber($conn);
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $total_amount = 0;
            $distribution_details = []; // Store distribution details for display
            
            // Process only items with quantities to avoid max_input_vars issue
            // We'll manually check each item code instead of using $_POST['sale_qty'] directly
            $processed_items = 0;
            
            foreach ($items as $item) {
                $item_code = $item['CODE'];
                
                // Check if this item has a quantity in the POST data
                if (isset($_POST['sale_qty'][$item_code])) {
                    $total_qty = intval($_POST['sale_qty'][$item_code]); // Convert to integer
                    
                    if ($total_qty > 0) {
                        $processed_items++;
                        
                        // Limit processing to prevent max_input_vars issues
                        if ($processed_items > 1000) {
                            throw new Exception("Too many items with quantities. Maximum allowed is 1000 items.");
                        }
                        
                        $rate = $item['RPRICE'];
                        $item_name = $item['DETAILS'];
                        
                        // Generate distribution
                        $daily_sales = distributeSales($total_qty, $days_count);
                        
                        // Store distribution details
                        $distribution_details[$item_code] = [
                            'name' => $item_name,
                            'total_qty' => $total_qty,
                            'rate' => $rate,
                            'daily_sales' => $daily_sales,
                            'dates' => $date_array
                        ];
                        
                        // Create sales for each day
                        foreach ($daily_sales as $index => $qty) {
                            if ($qty <= 0) continue;
                            
                            $sale_date = $date_array[$index];
                            $amount = $qty * $rate;
                            
                            // Generate bill number starting from 1
                            $bill_no = "BL" . $next_bill;
                            $next_bill++;
                            
                            // Insert sale header
                            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                             VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                            $header_stmt = $conn->prepare($header_query);
                            $header_stmt->bind_param("ssddssi", $bill_no, $sale_date, $amount, $amount, $mode, $comp_id, $user_id);
                            $header_stmt->execute();
                            $header_stmt->close();
                            
                            // Insert sale details
                            $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?)";
                            $detail_stmt = $conn->prepare($detail_query);
                            $detail_stmt->bind_param("ssddssi", $bill_no, $item_code, $qty, $rate, $amount, $mode, $comp_id);
                            $detail_stmt->execute();
                            $detail_stmt->close();
                            
                            // Update stock - check if record exists first
                            $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
                            $check_stmt = $conn->prepare($check_stock_query);
                            $check_stmt->bind_param("s", $item_code);
                            $check_stmt->execute();
                            $check_result = $check_stmt->get_result();
                            $stock_exists = $check_result->fetch_assoc()['count'] > 0;
                            $check_stmt->close();
                            
                            if ($stock_exists) {
                                // Update existing stock
                                $stock_query = "UPDATE tblitem_stock SET $current_stock_column = $current_stock_column - ? WHERE ITEM_CODE = ?";
                                $stock_stmt = $conn->prepare($stock_query);
                                $stock_stmt->bind_param("ds", $qty, $item_code);
                                $stock_stmt->execute();
                                $stock_stmt->close();
                            } else {
                                // Insert new stock record
                                $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_stock_column, $current_stock_column) 
                                                       VALUES (?, ?, ?, ?)";
                                $insert_stock_stmt = $conn->prepare($insert_stock_query);
                                $current_stock = -$qty; // Negative since we're deducting
                                $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $current_stock, $current_stock);
                                $insert_stock_stmt->execute();
                                $insert_stock_stmt->close();
                            }
                            
                            // Update daily stock table with closing calculation
                            updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id);
                            
                            $total_amount += $amount;
                        }
                    }
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            $success_message = "Sales distributed successfully across the date range! Total Amount: ₹" . number_format($total_amount, 2);
            
            // Redirect to retail_sale.php
            header("Location: retail_sale.php?success=" . urlencode($success_message));
            exit;
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error updating sales: " . $e->getMessage();
        }
    }
}

// Check for success message in URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// Initialize quantities array
$quantities = [];
foreach ($items as $item) {
    $quantities[$item['CODE']] = 0;
}

// Get subclass data for sale module view
$subclass_query = "SELECT ITEM_GROUP, CC, `DESC`, BOTTLE_PER_CASE FROM tblsubclass WHERE LIQ_FLAG = ? ORDER BY CC, ITEM_GROUP";
$subclass_stmt = $conn->prepare($subclass_query);
$subclass_stmt->bind_param("s", $mode);
$subclass_stmt->execute();
$subclass_result = $subclass_stmt->get_result();
$subclasses = $subclass_result->fetch_all(MYSQLI_ASSOC);
$subclass_stmt->close();

// Group subclasses by CC
$subclass_groups = [];
foreach ($subclasses as $subclass) {
    $cc = $subclass['CC'];
    if (!isset($subclass_groups[$cc])) {
        $subclass_groups[$cc] = [];
    }
    $subclass_groups[$cc][] = $subclass;
}

// Get class data for categorization
$class_query = "SELECT SGROUP, `DESC` FROM tblclass WHERE LIQ_FLAG = ?";
$class_stmt = $conn->prepare($class_query);
$class_stmt->bind_param("s", $mode);
$class_stmt->execute();
$class_result = $class_stmt->get_result();
$classes = [];
while ($row = $class_result->fetch_assoc()) {
    $classes[$row['SGROUP']] = $row['DESC'];
}
$class_stmt->close();

// Define all possible sizes for the sale module view
$all_sizes = [50, 60, 90, 100, 125, 180, 187, 200, 250, 375, 700, 750, 1000, 1500, 1750, 2000, 3000, 4500, 15000, 20000, 30000, 50000];
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
   
    .sale-module-modal .modal-dialog {
      max-width: 95%;
    }
    .sale-module-table th, .sale-module-table td {
      text-align: center;
      padding: 0.3rem;
      font-size: 0.75rem;
    }
    .sale-module-table th {
      font-size: 0.7rem;
    }
    .sale-module-table td {
      font-size: 0.8rem;
    }
    
    /* Remove spinner arrows from number inputs */
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type="number"] {
      -moz-appearance: textfield;
    }
    
    /* Highlight current row */
    .highlight-row {
      background-color: #f8f9fa !important;
      box-shadow: 0 0 5px rgba(0,0,0,0.1);
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

      <!-- Success/Error Messages -->
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

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>

      <!-- Sequence Type Selector -->
      <div class="mb-3">
        <label class="form-label">Sequence Type:</label>
        <div class="btn-group" role="group">
          <a href="?mode=<?= $mode ?>&sequence_type=user_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
             class="btn btn-outline-primary <?= $sequence_type === 'user_defined' ? 'sequence-active' : '' ?>">
            User Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=system_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
             class="btn btn-outline-primary <?= $sequence_type === 'system_defined' ? 'sequence-active' : '' ?>">
            System Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=group_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>"
             class="btn btn-outline-primary <?= $sequence_type === 'group_defined' ? 'sequence-active' : '' ?>">
            Group Defined
          </a>
        </div>
      </div>

      <!-- Date Range Selection -->
      <div class="date-range-container mb-4">
        <form method="GET" class="row g-3 align-items-end">
          <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
          <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
          <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>">
          
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
            <button type="submit" class="btn btn-primary w-100">Apply Date Range</button>
          </div>
        </form>
      </div>

      <!-- Search -->
      <div class="row mb-3">
        <div class="col-md-6">
          <form method="GET" class="search-control">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
            <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
            <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">
            <div class="input-group">
              <input type="text" name="search" class="form-control"
                     placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
              <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
              <?php if ($search !== ''): ?>
                <a href="?mode=<?= $mode ?>&sequence_type=<?= $sequence_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
        <div class="col-md-6 text-end">
          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#saleModuleModal">
            <i class="fas fa-table"></i> Sale Module View
          </button>
        </div>
      </div>

      <!-- Sales Form -->
      <form method="POST" id="salesForm">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
        <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">

        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-3">
          <button type="button" id="shuffleBtn" class="btn btn-warning btn-action" style="display: none;">
            <i class="fas fa-random"></i> Shuffle All
          </button>
          <button type="submit" name="update_sales" class="btn btn-success btn-action" style="display: none;">
            <i class="fas fa-save"></i> Save Distribution
          </button>
          
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>
        
        <div class="alert alert-info mt-3">
          <i class="fas fa-info-circle"></i> 
          Total sales quantities will be uniformly distributed across the selected date range as whole numbers.
          <br><strong>Distribution:</strong> Enter quantities to see the distribution across dates. Click "Shuffle All" to regenerate all distributions.
        </div>

        <!-- Items Table with Integrated Distribution Preview -->
        <div class="table-container">
          <table class="styled-table table-striped" id="itemsTable">
            <thead class="table-header">
              <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Rate (₹)</th>
                <th>Current Stock</th>
                <th>Total Sale Qty</th>
                <th class="closing-balance-header">Closing Balance</th>
                <th class="action-column">Action</th>
                
                <!-- Date Distribution Headers (will be populated by JavaScript) -->
                
                <th class="hidden-columns">Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php foreach ($items as $item): 
                $item_code = $item['CODE'];
                $item_qty = isset($quantities[$item_code]) ? $quantities[$item_code] : 0;
                $item_total = $item_qty * $item['RPRICE'];
                $closing_balance = $item['CURRENT_STOCK'] - $item_qty;
                
                // Extract size from item details
                $size = 0;
                if (preg_match('/(\d+)\s*ML/i', $item['DETAILS'], $matches)) {
                  $size = $matches[1];
                }
              ?>
                <tr>
                  <td><?= htmlspecialchars($item_code); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                  <td><?= number_format($item['RPRICE'], 2); ?></td>
                  <td>
                    <span class="stock-info"><?= number_format($item['CURRENT_STOCK'], 3); ?></span>
                  </td>
                  <td>
                    <input type="number" name="sale_qty[<?= htmlspecialchars($item_code); ?>]" 
                           class="form-control qty-input" min="0" max="<?= floor($item['CURRENT_STOCK']); ?>" 
                           step="1" value="<?= $item_qty ?>" 
                           data-rate="<?= $item['RPRICE'] ?>"
                           data-code="<?= htmlspecialchars($item_code); ?>"
                           data-stock="<?= $item['CURRENT_STOCK'] ?>"
                           data-size="<?= $size ?>">
                  </td>
                  <td class="closing-balance-cell" id="closing_<?= htmlspecialchars($item_code); ?>">
                    <?= number_format($closing_balance, 3) ?>
                  </td>
                  <td class="action-column">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-shuffle-item" 
                            data-code="<?= htmlspecialchars($item_code); ?>" style="display: none;">
                      <i class="fas fa-random"></i> Shuffle
                    </button>
                  </td>
                  
                  <!-- Date distribution cells will be inserted here by JavaScript -->
                  
                  <td class="amount-cell hidden-columns" id="amount_<?= htmlspecialchars($item_code); ?>">
                    <?= number_format($item_qty * $item['RPRICE'], 2) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" class="text-center text-muted">No items found.</td>
              </tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="7" class="text-end"><strong>Total Amount:</strong></td>
                <td class="action-column"><strong id="totalAmount">0.00</strong></td>
                <td class="hidden-columns"></td>
              </tr>
            </tfoot>
          </table>
        </div>
        
        <!-- Ajax Loader -->
        <div id="ajaxLoader" class="ajax-loader">
          <div class="loader"></div>
          <p>Calculating distribution...</p>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Sale Module View Modal -->
<div class="modal fade sale-module-modal" id="saleModuleModal" tabindex="-1" aria-labelledby="saleModuleModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="saleModuleModalLabel">Sale Module View</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive">
          <table class="table table-bordered sale-module-table">
            <thead>
              <tr>
                <th>Category</th>
                <?php foreach ($all_sizes as $size): ?>
                  <th><?= $size ?> ML</th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php 
              // Define categories with their IDs
              $categories = [
                'WHISKY,GIN,BRANDY,VODKA,RUM,LIQUORS,OTHERS/GENERAL' => 'SPRITS',
                'WINES' => 'WINE',
                'FERMENTED BEER' => 'FERMENTED BEER',
                'MILD BEER' => 'MILD BEER'
              ];
              
              foreach ($categories as $category_id => $category_name): 
              ?>
                <tr>
                  <td><?= $category_name ?></td>
                  <?php foreach ($all_sizes as $size): ?>
                    <td id="module_<?= $category_id ?>_<?= $size ?>">0</td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global variables
const dateArray = <?= json_encode($date_array) ?>;
const daysCount = <?= $days_count ?>;

// Function to distribute sales uniformly (client-side version)
function distributeSales(total_qty, days_count) {
    if (total_qty <= 0 || days_count <= 0) return new Array(days_count).fill(0);
    
    const base_qty = Math.floor(total_qty / days_count);
    const remainder = total_qty % days_count;
    
    const daily_sales = new Array(days_count).fill(base_qty);
    
    // Distribute remainder evenly across days
    for (let i = 0; i < remainder; i++) {
        daily_sales[i]++;
    }
    
    // Shuffle the distribution to make it look more natural
    for (let i = daily_sales.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [daily_sales[i], daily_sales[j]] = [daily_sales[j], daily_sales[i]];
    }
    
    return daily_sales;
}

// Function to update the distribution preview for a specific item
function updateDistributionPreview(itemCode, totalQty) {
    const dailySales = distributeSales(totalQty, daysCount);
    const rate = parseFloat($(`input[name="sale_qty[${itemCode}]"]`).data('rate'));
    const itemRow = $(`input[name="sale_qty[${itemCode}]"]`).closest('tr');
    
    // Remove any existing distribution cells
    itemRow.find('.date-distribution-cell').remove();
    
    // Add date distribution cells after the action column
    let totalDistributed = 0;
    dailySales.forEach((qty, index) => {
        totalDistributed += qty;
        // Insert distribution cells after the action column
        $(`<td class="date-distribution-cell">${qty}</td>`).insertAfter(itemRow.find('.action-column'));
    });
    
    // Update closing balance
    const currentStock = parseFloat($(`input[name="sale_qty[${itemCode}]"]`).data('stock'));
    const closingBalance = currentStock - totalDistributed;
    $(`#closing_${itemCode}`).text(closingBalance.toFixed(3));
    
    // Update amount
    const amount = totalDistributed * rate;
    $(`#amount_${itemCode}`).text(amount.toFixed(2));
    
    // Show date columns if they're hidden
    $('.date-header, .date-distribution-cell').show();
    
    return dailySales;
}

// Function to categorize item based on its category and size
function categorizeItem(itemCategory, itemName, itemSize) {
    const category = (itemCategory || '').toUpperCase();
    const name = (itemName || '').toUpperCase();
    
    // Check for wine first
    if (category.includes('WINE') || name.includes('WINE')) {
        return 'WINES';
    }
    // Check for mild beer
    else if ((category.includes('BEER') || name.includes('BEER')) && 
             (category.includes('MILD') || name.includes('MILD'))) {
        return 'MILD BEER';
    }
    // Check for regular beer
    else if (category.includes('BEER') || name.includes('BEER')) {
        return 'FERMENTED BEER';
    }
    // Everything else is spirits (WHISKY, GIN, BRANDY, VODKA, RUM, LIQUORS, OTHERS/GENERAL)
    else {
        return 'WHISKY,GIN,BRANDY,VODKA,RUM,LIQUORS,OTHERS/GENERAL';
    }
}

// Function to update sale module view
function updateSaleModuleView() {
    // Reset all values to 0
    $('.sale-module-table td').not(':first-child').text('0');
    
    // Calculate quantities for each category and size
    $('input[name^="sale_qty"]').each(function() {
        const qty = parseInt($(this).val()) || 0;
        if (qty > 0) {
            const itemCode = $(this).data('code');
            const itemRow = $(this).closest('tr');
            const itemName = itemRow.find('td:eq(1)').text();
            const itemCategory = itemRow.find('td:eq(2)').text();
            const size = $(this).data('size');
            
            // Determine the category type
            const categoryType = categorizeItem(itemCategory, itemName, size);
            
            // Update the corresponding cell using the ID pattern
            if (size > 0) {
                const cellId = `module_${categoryType}_${size}`;
                const targetCell = $(`#${cellId}`);
                if (targetCell.length) {
                    const currentValue = parseInt(targetCell.text()) || 0;
                    targetCell.text(currentValue + qty);
                }
            }
        }
    });
}

// Function to calculate total amount
function calculateTotalAmount() {
    let total = 0;
    $('.amount-cell').each(function() {
        total += parseFloat($(this).text()) || 0;
    });
    $('#totalAmount').text(total.toFixed(2));
}

// Function to initialize date headers and closing balance column
function initializeTableHeaders() {
    // Remove existing date headers if any
    $('.date-header').remove();
    
    // Add date headers after the action column header
    dateArray.forEach(date => {
        const dateObj = new Date(date);
        const day = dateObj.getDate();
        const month = dateObj.toLocaleString('default', { month: 'short' });
        
        // Insert date headers after the action column header
        $(`<th class="date-header" title="${date}" style="display: none;">${day}<br>${month}</th>`).insertAfter($('.table-header tr th.action-column'));
    });
}

// Function to handle row navigation with arrow keys
function setupRowNavigation() {
    const qtyInputs = $('input.qty-input');
    let currentRowIndex = -1;
    
    // Highlight row when input is focused
    $(document).on('focus', 'input.qty-input', function() {
        // Remove highlight from all rows
        $('tr').removeClass('highlight-row');
        
        // Add highlight to current row
        $(this).closest('tr').addClass('highlight-row');
        
        // Update current row index
        currentRowIndex = qtyInputs.index(this);
    });
    
    // Handle arrow key navigation
    $(document).on('keydown', 'input.qty-input', function(e) {
        // Only handle arrow keys
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        
        e.preventDefault(); // Prevent default scrolling behavior
        
        // Calculate new row index
        let newIndex;
        if (e.key === 'ArrowUp') {
            newIndex = currentRowIndex - 1;
        } else { // ArrowDown
            newIndex = currentRowIndex + 1;
        }
        
        // Check if new index is valid
        if (newIndex >= 0 && newIndex < qtyInputs.length) {
            // Focus the input in the new row
            $(qtyInputs[newIndex]).focus().select();
        }
    });
}

// Document ready
$(document).ready(function() {
    // Initialize table headers and columns
    initializeTableHeaders();
    
    // Set up row navigation with arrow keys
    setupRowNavigation();
    
    // Show action buttons
    $('#shuffleBtn, .btn-shuffle-item, .btn-shuffle').show();
    $('button[name="update_sales"]').show();
    
    // Quantity input change event
    $(document).on('change', 'input[name^="sale_qty"]', function() {
        const itemCode = $(this).data('code');
        const totalQty = parseInt($(this).val()) || 0;
        
        if (totalQty > 0) {
            updateDistributionPreview(itemCode, totalQty);
        } else {
            // Remove distribution cells if quantity is 0
            $(`input[name="sale_qty[${itemCode}]"]`).closest('tr').find('.date-distribution-cell').remove();
            
            // Reset closing balance and amount
            const currentStock = parseFloat($(this).data('stock'));
            $(`#closing_${itemCode}`).text(currentStock.toFixed(3));
            $(`#amount_${itemCode}`).text('0.00');
            
            // Hide date columns if no items have quantity
            if ($('input[name^="sale_qty"]').filter(function() { 
                return parseInt($(this).val()) > 0; 
            }).length === 0) {
                $('.date-header, .date-distribution-cell').hide();
            }
        }
        
        // Update total amount
        calculateTotalAmount();
        
        // Update sale module view
        updateSaleModuleView();
    });
    
    // Shuffle all button click event
    $('#shuffleBtn').click(function() {
        $('input[name^="sale_qty"]').each(function() {
            const itemCode = $(this).data('code');
            const totalQty = parseInt($(this).val()) || 0;
            
            if (totalQty > 0) {
                updateDistributionPreview(itemCode, totalQty);
            }
        });
        
        // Update total amount
        calculateTotalAmount();
    });
    
    // Individual shuffle button click event
    $(document).on('click', '.btn-shuffle-item', function() {
        const itemCode = $(this).data('code');
        const totalQty = parseInt($(`input[name="sale_qty[${itemCode}]"]`).val()) || 0;
        
        if (totalQty > 0) {
            updateDistributionPreview(itemCode, totalQty);
            
            // Update total amount
            calculateTotalAmount();
        }
    });
    
    // Sale module modal show event
    $('#saleModuleModal').on('show.bs.modal', function() {
        updateSaleModuleView();
    });
    
    // Form submit event
    $('#salesForm').on('submit', function() {
        // Show loader
        $('#ajaxLoader').show();
        
        // Validate that at least one item has quantity
        let hasQuantity = false;
        $('input[name^="sale_qty"]').each(function() {
            if (parseInt($(this).val()) > 0) {
                hasQuantity = true;
                return false; // Break the loop
            }
        });
        
        if (!hasQuantity) {
            alert('Please enter quantities for at least one item.');
            $('#ajaxLoader').hide();
            return false;
        }
    });
});
</script> 
</body>
</html>

