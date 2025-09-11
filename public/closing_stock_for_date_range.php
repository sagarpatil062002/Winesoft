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
        // Update sales column
        $update_query = "UPDATE $daily_stock_table 
                         SET $sales_column = $sales_column + ?, 
                             $closing_column = $closing_column - ?,
                             LAST_UPDATED = CURRENT_TIMESTAMP 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ddss", $qty, $qty, $month_year, $item_code);
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
                                     SET $next_opening_column = $closing_column,
                                         LAST_UPDATED = CURRENT_TIMESTAMP 
                                     WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_next_stmt = $conn->prepare($update_next_query);
                $update_next_stmt->bind_param("ss", $month_year, $item_code);
                $update_next_stmt->execute();
                $update_next_stmt->close();
            }
        }
    } else {
        // For new records, create with proper values
        $insert_query = "INSERT INTO $daily_stock_table 
                         (STK_MONTH, ITEM_CODE, LIQ_FLAG, $opening_column, $purchase_column, $closing_column, $sales_column) 
                         VALUES (?, ?, 'F', 0, 0, -?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssdd", $month_year, $item_code, $qty, $qty);
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
            // We'll manually check each item code instead of using $_POST['closing_qty'] directly
            $processed_items = 0;
            
            foreach ($items as $item) {
                $item_code = $item['CODE'];
                
                // Check if this item has a closing quantity in the POST data
                if (isset($_POST['closing_qty'][$item_code])) {
                    $closing_qty = intval($_POST['closing_qty'][$item_code]); // Convert to integer
                    $current_stock = $item['CURRENT_STOCK'];
                    
                    if ($closing_qty != $current_stock) {
                        $processed_items++;
                        
                        // Limit processing to prevent max_input_vars issues
                        if ($processed_items > 1000) {
                            throw new Exception("Too many items with quantities. Maximum allowed is 1000 items.");
                        }
                        
                        $rate = $item['RPRICE'];
                        $item_name = $item['DETAILS'];
                        
                        // Calculate total sales quantity
                        $total_sales_qty = $current_stock - $closing_qty;
                        
                        if ($total_sales_qty > 0) {
                            // Generate distribution
                            $daily_sales = distributeSales($total_sales_qty, $days_count);
                            
                            // Store distribution details
                            $distribution_details[$item_code] = [
                                'name' => $item_name,
                                'total_qty' => $total_sales_qty,
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
                        } else if ($total_sales_qty < 0) {
                            throw new Exception("Closing stock cannot be greater than current stock for item: $item_name");
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
$closing_quantities = [];
foreach ($items as $item) {
    $closing_quantities[$item['CODE']] = $item['CURRENT_STOCK'];
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
  <title>Closing Stock Sales Distribution - WineSoft</title>
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
    
    /* Style for sale quantity display */
    .sale-qty {
      font-weight: bold;
      color: #dc3545;
    }
    
    /* Initially hide distribution columns */
    .dist-header, .dist-cell {
      display: none;
    }
    
    /* Show distribution columns when in edit mode */
    .edit-mode .dist-header, 
    .edit-mode .dist-cell {
      display: table-cell;
    }
    
    /* Show action buttons by default (changed from display: none) */
    .btn-action, .btn-shuffle-item {
      display: inline-block !important;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Closing Stock Sales Distribution</h3>

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
          <button type="button" id="shuffleBtn" class="btn btn-warning btn-action">
            <i class="fas fa-random"></i> Shuffle All
          </button>
          <button type="submit" name="update_sales" class="btn btn-success btn-action">
            <i class="fas fa-save"></i> Save Distribution
          </button>
          
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>
        
        <div class="alert alert-info mt-3">
          <i class="fas fa-info-circle"></i> 
          Enter the desired closing stock for each item. The system will calculate sales as: <strong>Sales = Current Stock - Closing Stock</strong>
          <br>Sales quantities will be uniformly distributed across the selected date range as whole numbers.
          <br><strong>Distribution:</strong> Enter closing stock values to see the sales distribution across dates. Click "Shuffle All" to regenerate all distributions.
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
                <th>Closing Stock</th>
                <th class="sale-qty-header">Sale Qty</th>
                <th class="action-column">Action</th>
                
                <!-- Date Distribution Headers (will be populated by JavaScript) -->
                
                <th class="hidden-columns">Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php foreach ($items as $item): 
                $item_code = $item['CODE'];
                $closing_qty = isset($closing_quantities[$item_code]) ? $closing_quantities[$item_code] : $item['CURRENT_STOCK'];
                $sale_qty = $item['CURRENT_STOCK'] - $closing_qty;
                $item_total = $sale_qty * $item['RPRICE'];
                
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
                    <input type="number" name="closing_qty[<?= htmlspecialchars($item_code); ?>]" 
                           class="form-control closing-input" min="0" max="<?= $item['CURRENT_STOCK']; ?>" 
                           step="1" value="<?= $closing_qty ?>" 
                           data-rate="<?= $item['RPRICE'] ?>"
                           data-code="<?= htmlspecialchars($item_code); ?>"
                           data-stock="<?= $item['CURRENT_STOCK'] ?>"
                           data-size="<?= $size ?>">
                  </td>
                  <td class="sale-qty-cell sale-qty" id="sale_qty_<?= htmlspecialchars($item_code); ?>">
                    <?= number_format($sale_qty, 3) ?>
                  </td>
                  <td class="action-column">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-shuffle-item" 
                            data-code="<?= htmlspecialchars($item_code); ?>">
                      <i class="fas fa-random"></i> Shuffle
                    </button>
                  </td>
                  
                  <!-- Date distribution cells will be inserted here by JavaScript -->
                  
                  <td class="amount-cell hidden-columns" id="amount_<?= htmlspecialchars($item_code); ?>">
                    <?= number_format($item_total, 2) ?>
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
let isProcessing = false;
let isEditMode = false;

// Function to distribute sales uniformly
function distributeSales(totalQty, daysCount) {
  if (totalQty <= 0 || daysCount <= 0) return Array(daysCount).fill(0);
  
  const baseQty = Math.floor(totalQty / daysCount);
  const remainder = totalQty % daysCount;
  
  let dailySales = Array(daysCount).fill(baseQty);
  
  // Distribute remainder evenly across days
  for (let i = 0; i < remainder; i++) {
    dailySales[i]++;
  }
  
  // Shuffle the distribution to make it look more natural
  for (let i = dailySales.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [dailySales[i], dailySales[j]] = [dailySales[j], dailySales[i]];
  }
  
  return dailySales;
}

// Function to calculate and display distribution for an item
function calculateDistribution(itemCode, closingQty) {
  const currentStock = parseFloat(document.querySelector(`input[data-code="${itemCode}"]`).dataset.stock);
  const rate = parseFloat(document.querySelector(`input[data-code="${itemCode}"]`).dataset.rate);
  const saleQty = currentStock - closingQty;
  
  // Update sale quantity display
  document.getElementById(`sale_qty_${itemCode}`).textContent = saleQty.toFixed(3);
  
  // Update amount
  const amount = saleQty * rate;
  document.getElementById(`amount_${itemCode}`).textContent = amount.toFixed(2);
  
  // Calculate distribution
  const dailySales = distributeSales(saleQty, daysCount);
  
  // Update distribution cells
  for (let i = 0; i < daysCount; i++) {
    const cell = document.querySelector(`.dist-cell-${itemCode}-${i}`);
    if (cell) {
      cell.textContent = dailySales[i];
    }
  }
  
  // Update total amount
  updateTotalAmount();
  
  return dailySales;
}

// Function to update total amount
function updateTotalAmount() {
  let total = 0;
  document.querySelectorAll('.amount-cell').forEach(cell => {
    total += parseFloat(cell.textContent) || 0;
  });
  document.getElementById('totalAmount').textContent = total.toFixed(2);
}

// Function to create distribution columns in table
function createDistributionColumns() {
  if (daysCount <= 0) return;
  
  const headerRow = document.querySelector('#itemsTable thead tr');
  const tbodyRows = document.querySelectorAll('#itemsTable tbody tr');
  
  // Clear existing distribution columns
  document.querySelectorAll('.dist-header, .dist-cell').forEach(el => el.remove());
  
  // Add headers for each date
  dateArray.forEach((date, index) => {
    const dateObj = new Date(date);
    const th = document.createElement('th');
    th.className = 'dist-header';
    th.textContent = dateObj.toLocaleDateString('en-IN', { day: '2-digit', month: 'short' });
    headerRow.insertBefore(th, headerRow.querySelector('.action-column'));
  });
  
  // Add distribution cells for each item row
  tbodyRows.forEach(row => {
    const itemCode = row.querySelector('input.closing-input').dataset.code;
    
    for (let i = 0; i < daysCount; i++) {
      const td = document.createElement('td');
      td.className = `dist-cell dist-cell-${itemCode}-${i}`;
      td.textContent = '0';
      row.insertBefore(td, row.querySelector('.action-column'));
    }
  });
}

// Function to toggle edit mode
function toggleEditMode(enable) {
  isEditMode = enable;
  const table = document.getElementById('itemsTable');
  
  if (enable) {
    table.classList.add('edit-mode');
  } else {
    table.classList.remove('edit-mode');
  }
}

// Function to update sale module view
function updateSaleModuleView() {
  // Reset all module view cells
  <?php 
  foreach ($categories as $category_id => $category_name): 
    foreach ($all_sizes as $size): 
  ?>
    document.getElementById('module_<?= $category_id ?>_<?= $size ?>').textContent = '0';
  <?php 
    endforeach; 
  endforeach; 
  ?>
  
  // Calculate totals for each category and size
  document.querySelectorAll('input.closing-input').forEach(input => {
    const itemCode = input.dataset.code;
    const currentStock = parseFloat(input.dataset.stock);
    const closingQty = parseFloat(input.value);
    const size = parseInt(input.dataset.size);
    const itemName = input.closest('tr').querySelector('td:nth-child(2)').textContent;
    
    // Skip if no size or invalid input
    if (!size || isNaN(closingQty)) return;
    
    // Calculate sale quantity
    const saleQty = currentStock - closingQty;
    if (saleQty <= 0) return;
    
    // Determine category based on item details
    let category = '';
    if (itemName.includes('WHISKY') || itemName.includes('GIN') || itemName.includes('BRANDY') || 
        itemName.includes('VODKA') || itemName.includes('RUM') || itemName.includes('LIQUOR') || 
        itemName.includes('GENERAL') || itemName.includes('OTHERS')) {
      category = 'WHISKY,GIN,BRANDY,VODKA,RUM,LIQUORS,OTHERS/GENERAL';
    } else if (itemName.includes('WINE')) {
      category = 'WINES';
    } else if (itemName.includes('FERMENTED')) {
      category = 'FERMENTED BEER';
    } else if (itemName.includes('MILD') || itemName.includes('BEER')) {
      category = 'MILD BEER';
    }
    
    // Update the corresponding cell
    if (category && <?= json_encode($all_sizes) ?>.includes(size)) {
      const cell = document.getElementById(`module_${category}_${size}`);
      if (cell) {
        const currentValue = parseInt(cell.textContent) || 0;
        cell.textContent = currentValue + Math.round(saleQty);
      }
    }
  });
}

// Initialize when document is ready
document.addEventListener('DOMContentLoaded', function() {
  // Create distribution columns (hidden initially)
  createDistributionColumns();
  
  // Calculate initial distributions
  document.querySelectorAll('input.closing-input').forEach(input => {
    const itemCode = input.dataset.code;
    const closingQty = parseFloat(input.value);
    calculateDistribution(itemCode, closingQty);
  });
  
  // Update sale module view
  updateSaleModuleView();
  
  // Add event listeners for closing quantity changes
  document.querySelectorAll('input.closing-input').forEach(input => {
    input.addEventListener('input', function() {
      const itemCode = this.dataset.code;
      let closingQty = parseFloat(this.value);
      const currentStock = parseFloat(this.dataset.stock);
      
      // Validate input
      if (closingQty > currentStock) {
        this.value = currentStock;
        closingQty = currentStock;
      }
      
      // Enable edit mode if any input changes
      if (!isEditMode) {
        toggleEditMode(true);
      }
      
      // Calculate and display new distribution
      calculateDistribution(itemCode, closingQty);
      
      // Update sale module view
      updateSaleModuleView();
    });
    
    // Enable edit mode on focus
    input.addEventListener('focus', function() {
      if (!isEditMode) {
        toggleEditMode(true);
      }
    });
  });
  
  // Add event listener for shuffle all button
  document.getElementById('shuffleBtn').addEventListener('click', function() {
    document.querySelectorAll('input.closing-input').forEach(input => {
      const itemCode = input.dataset.code;
      const closingQty = parseFloat(input.value);
      calculateDistribution(itemCode, closingQty);
    });
    
    // Update sale module view
    updateSaleModuleView();
  });
  
  // Add event listeners for individual shuffle buttons
  document.querySelectorAll('.btn-shuffle-item').forEach(btn => {
    btn.addEventListener('click', function() {
      const itemCode = this.dataset.code;
      const input = document.querySelector(`input[data-code="${itemCode}"]`);
      const closingQty = parseFloat(input.value);
      
      calculateDistribution(itemCode, closingQty);
      
      // Update sale module view
      updateSaleModuleView();
    });
  });
  
  // Add form submission handler
  document.getElementById('salesForm').addEventListener('submit', function(e) {
    // Validate that at least one item has a sale quantity
    let hasSales = false;
    document.querySelectorAll('.sale-qty-cell').forEach(cell => {
      const saleQty = parseFloat(cell.textContent);
      if (saleQty > 0) {
        hasSales = true;
      }
    });
    
    if (!hasSales) {
      e.preventDefault();
      alert('No sales quantities to save. Please adjust closing stock values to create sales.');
      return;
    }
    
    // Show loading indicator
    document.getElementById('ajaxLoader').style.display = 'block';
  });
});
</script>
</body>
</html>