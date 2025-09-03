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

// Date range selection (default to current month)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

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

// Function to update daily stock table - FIXED VERSION
function updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id) {
    // Extract day number from date (e.g., 2025-09-03 -> day 03)
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $sales_column = "DAY_{$day_num}_SALES";
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
        // Update existing record
        $update_query = "UPDATE $daily_stock_table 
                         SET $sales_column = $sales_column + ?, 
                             LAST_UPDATED = CURRENT_TIMESTAMP 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("dss", $qty, $month_year, $item_code);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Create new record
        $insert_query = "INSERT INTO $daily_stock_table 
                         (STK_MONTH, ITEM_CODE, LIQ_FLAG, $sales_column) 
                         VALUES (?, ?, 'F', ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssd", $month_year, $item_code, $qty);
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
            
            // NEW APPROACH: Process only items with quantities to avoid max_input_vars issue
            $item_codes = array_keys($_POST['sale_qty']);
            
            foreach ($item_codes as $item_code) {
                $total_qty = intval($_POST['sale_qty'][$item_code]); // Convert to integer
                
                if ($total_qty > 0) {
                    // Get item details
                    $item_query = "SELECT DETAILS, RPRICE FROM tblitemmaster WHERE CODE = ?";
                    $item_stmt = $conn->prepare($item_query);
                    $item_stmt->bind_param("s", $item_code);
                    $item_stmt->execute();
                    $item_result = $item_stmt->get_result();
                    $item_data = $item_result->fetch_assoc();
                    $item_stmt->close();
                    
                    $rate = $item_data['RPRICE'];
                    $item_name = $item_data['DETAILS'];
                    
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
                        
                        // Update daily stock table
                        updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id);
                        
                        $total_amount += $amount;
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
    .qty-input {
      width: 80px;
      text-align: center;
    }
    .stock-info {
      font-size: 0.9rem;
      color: #6c757d;
    }
    .mode-active, .sequence-active {
      background-color: #0d6efd;
      color: white !important;
    }
    .table-container {
      overflow-x: auto;
    }
    .styled-table {
      width: 100%;
      border-collapse: collapse;
    }
    .styled-table th {
      position: sticky;
      top: 0;
      background-color: #f8f9fa;
      z-index: 10;
    }
    .date-range-container {
      background-color: #f8f9fa;
      border-radius: 6px;
      padding: 1rem;
      margin-bottom: 1.5rem;
    }
    .distribution-cell {
      width: 40px;
      text-align: center;
      font-weight: bold;
      padding: 3px;
    }
    .distribution-row {
      background-color: #fff3cd;
    }
    .distribution-total {
      font-weight: bold;
      background-color: #e9ecef;
    }
    .date-header {
      text-align: center;
      padding: 5px;
      font-size: 0.7rem;
      width: 40px;
    }
    .btn-action {
      min-width: 120px;
    }
    .distribution-table {
      font-size: 0.85rem;
    }
    .distribution-table th {
      white-space: nowrap;
    }
    .distribution-section {
      margin-top: 20px;
      border-top: 2px solid #dee2e6;
      padding-top: 15px;
    }
    .distribution-preview {
      max-height: 400px;
      overflow-y: auto;
    }
    .distribution-summary {
      background-color: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 15px;
    }
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
    .date-distribution-header {
      text-align: center;
      font-weight: bold;
      background-color: #f8f9fa;
    }
    .date-distribution-cell {
      text-align: center;
      padding: 3px;
      font-size: 0.8rem;
      width: 40px;
    }
    .distribution-total-cell {
      text-align: center;
      font-weight: bold;
      background-color: #e9ecef;
    }
    .hidden-columns {
      display: none;
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
      </div>

      <!-- Sales Form -->
      <form method="POST" id="salesForm">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
        <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">

        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-3">
          <button type="button" id="shuffleBtn" class="btn btn-warning btn-action" style="display: none;">
            <i class="fas fa-random"></i> Shuffle Distribution
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
          <br><strong>Distribution:</strong> Enter quantities to see the distribution across dates. Click "Shuffle Distribution" to regenerate.
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
                           data-code="<?= htmlspecialchars($item_code); ?>">
                  </td>
                  <td class="amount-cell hidden-columns" id="amount_<?= htmlspecialchars($item_code); ?>">
                    <?= number_format($item_qty * $item['RPRICE'], 2) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="7" class="text-center text-muted">No items found.</td>
              </tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="5" class="text-end"><strong>Total Amount:</strong></td>
                <td><strong id="totalAmount">0.00</strong></td>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global variables
const dateArray = <?= json_encode($date_array) ?>;
const daysCount = <?= $days_count ?>;

// Function to distribute sales uniformly (client-side version)
function distributeSales(total_qty, days_count) {
    if (total_qty <= 0 || days_count <= 0) return Array(days_count).fill(0);
    
    const base_qty = Math.floor(total_qty / days_count);
    const remainder = total_qty % days_count;
    
    let daily_sales = Array(days_count).fill(base_qty);
    
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

// Create date headers for the distribution columns
function createDateHeaders() {
    const table = document.getElementById('itemsTable');
    const headerRow = table.querySelector('thead tr');
    
    // Remove existing date headers if any
    const existingDateHeaders = headerRow.querySelectorAll('.date-distribution-header, .date-total-header');
    existingDateHeaders.forEach(header => header.remove());
    
    // Add date headers
    dateArray.forEach(date => {
        const th = document.createElement('th');
        th.className = 'date-distribution-header';
        th.textContent = formatDateShort(date);
        headerRow.insertBefore(th, headerRow.lastElementChild);
    });
    
    // Add total header
    const totalTh = document.createElement('th');
    totalTh.className = 'date-total-header';
    totalTh.textContent = 'Total';
    headerRow.insertBefore(totalTh, headerRow.lastElementChild);
}

// Format date to short format (dd MMM)
function formatDateShort(dateStr) {
    const date = new Date(dateStr);
    return date.getDate() + ' ' + date.toLocaleString('default', { month: 'short' });
}

// Update distribution for a specific item
function updateItemDistribution(itemCode, totalQty) {
    const table = document.getElementById('itemsTable');
    const rows = table.querySelectorAll('tbody tr');
    
    for (let row of rows) {
        const codeInput = row.querySelector('input[data-code]');
        if (codeInput && codeInput.dataset.code === itemCode) {
            // Remove existing distribution cells
            const existingCells = row.querySelectorAll('.date-distribution-cell, .distribution-total-cell');
            existingCells.forEach(cell => cell.remove());
            
            // Add new distribution cells if quantity > 0
            if (totalQty > 0) {
                const rate = parseFloat(codeInput.dataset.rate);
                const dailySales = distributeSales(totalQty, daysCount);
                
                // Add daily distribution cells
                dailySales.forEach(qty => {
                    const td = document.createElement('td');
                    td.className = 'date-distribution-cell';
                    td.textContent = qty > 0 ? qty : '';
                    row.insertBefore(td, row.lastElementChild);
                });
                
                // Add total cell
                const totalTd = document.createElement('td');
                totalTd.className = 'distribution-total-cell';
                totalTd.textContent = totalQty;
                row.insertBefore(totalTd, row.lastElementChild);
                
                // Highlight the row
                row.classList.add('distribution-row');
            } else {
                // Remove highlight if no quantity
                row.classList.remove('distribution-row');
            }
            
            break;
        }
    }
}

// Check if any items have distribution
function hasDistribution() {
    const inputs = document.querySelectorAll('.qty-input');
    for (let input of inputs) {
        if (parseInt(input.value) > 0) {
            return true;
        }
    }
    return false;
}

// Update the table based on distribution status
function updateTableUI() {
    const hasDist = hasDistribution();
    
    // Show/hide action buttons
    document.getElementById('shuffleBtn').style.display = hasDist ? 'block' : 'none';
    document.querySelector('button[name="update_sales"]').style.display = hasDist ? 'block' : 'none';
    
    // Show/hide date headers if needed
    if (hasDist) {
        createDateHeaders();
    } else {
        // Remove date headers if no distribution
        const headerRow = document.querySelector('#itemsTable thead tr');
        const existingDateHeaders = headerRow.querySelectorAll('.date-distribution-header, .date-total-header');
        existingDateHeaders.forEach(header => header.remove());
        
        // Remove distribution cells from all rows
        const rows = document.querySelectorAll('#itemsTable tbody tr');
        rows.forEach(row => {
            const existingCells = row.querySelectorAll('.date-distribution-cell, .distribution-total-cell');
            existingCells.forEach(cell => cell.remove());
            row.classList.remove('distribution-row');
        });
    }
}

// Calculate amount for each item
function calculateAmount(input) {
    const qty = parseInt(input.value) || 0;
    const rate = parseFloat(input.dataset.rate);
    const amount = qty * rate;
    const itemCode = input.dataset.code;
    
    document.getElementById(`amount_${itemCode}`).textContent = amount.toFixed(2);
    calculateTotal();
    
    // Update distribution for this item
    updateItemDistribution(itemCode, qty);
    
    // Update UI based on distribution status
    updateTableUI();
}

// Calculate total amount
function calculateTotal() {
    let total = 0;
    document.querySelectorAll('.amount-cell').forEach(cell => {
        total += parseFloat(cell.textContent) || 0;
    });
    
    document.getElementById('totalAmount').textContent = total.toFixed(2);
}

// Shuffle all distributions
function shuffleAllDistributions() {
    const inputs = document.querySelectorAll('.qty-input');
    inputs.forEach(input => {
        const qty = parseInt(input.value) || 0;
        if (qty > 0) {
            updateItemDistribution(input.dataset.code, qty);
        }
    });
}

// Initialize calculations on page load
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners to all quantity inputs
    document.querySelectorAll('.qty-input').forEach(input => {
        input.addEventListener('input', () => {
            calculateAmount(input);
        });
    });
    
    // Calculate initial total
    calculateTotal();
    
    // Set up shuffle button
    document.getElementById('shuffleBtn').addEventListener('click', shuffleAllDistributions);
});
</script>
</body>
</html>