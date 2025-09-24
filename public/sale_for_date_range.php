<?php
session_start();

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/sales_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Function to log array data in a readable format
function logArray($data, $title = 'Array data') {
    ob_start();
    print_r($data);
    $output = ob_get_clean();
    logMessage("$title:\n$output");
}

// Function to log POST data (sanitized)
function logPostData() {
    $postData = $_POST;
    // Remove sensitive data if needed
    unset($postData['password'], $postData['credit_card'], $postData['auth_token']);
    logArray($postData, 'POST data');
}

// Function to log GET data
function logGetData() {
    logArray($_GET, 'GET data');
}

// Function to log session data
function logSessionData() {
    logArray($_SESSION, 'Session data');
}

// Function to clear session quantities
function clearSessionQuantities() {
    if (isset($_SESSION['sale_quantities'])) {
        unset($_SESSION['sale_quantities']);
        logMessage("Session quantities cleared");
    }
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

logMessage("Sales page accessed by user ID: {$_SESSION['user_id']}, Company ID: {$_SESSION['CompID']}");
logSessionData();

include_once "../config/db.php"; // MySQLi connection in $conn
logMessage("Database connection established");

// Include volume limit utilities
include_once "volume_limit_utils.php";

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
logMessage("Mode selected: $mode");

// Sequence type selection (default user_defined)
$sequence_type = isset($_GET['sequence_type']) ? $_GET['sequence_type'] : 'user_defined';
logMessage("Sequence type selected: $sequence_type");

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
logMessage("Search keyword: '$search'");

// Date range selection (default to current day only)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
logMessage("Date range: $start_date to $end_date");

// Get company ID
$comp_id = $_SESSION['CompID'];
$daily_stock_table = "tbldailystock_" . $comp_id;
$opening_stock_column = "Opening_Stock" . $comp_id;
$current_stock_column = "Current_Stock" . $comp_id;

logMessage("Company ID: $comp_id, Stock table: $daily_stock_table");

// Check if the stock columns exist, if not create them
$check_column_query = "SHOW COLUMNS FROM tblitem_stock LIKE '$current_stock_column'";
$column_result = $conn->query($check_column_query);

if ($column_result->num_rows == 0) {
    logMessage("Creating stock columns: $opening_stock_column, $current_stock_column");
    $alter_query = "ALTER TABLE tblitem_stock 
                    ADD COLUMN $opening_stock_column DECIMAL(10,3) DEFAULT 0.000,
                    ADD COLUMN $current_stock_column DECIMAL(10,3) DEFAULT 0.000";
    if (!$conn->query($alter_query)) {
        $error = "Error creating stock columns: " . $conn->error;
        logMessage($error, 'ERROR');
        die("Error creating stock columns: " . $conn->error);
    }
    logMessage("Stock columns created successfully");
} else {
    logMessage("Stock columns already exist");
}

// Build the order clause based on sequence type
$order_clause = "";
if ($sequence_type === 'system_defined') {
    $order_clause = "ORDER BY im.CODE ASC";
} elseif ($sequence_type === 'group_defined') {
    $order_clause = "ORDER BY im.DETAILS2 ASC, im.DETAILS ASC";
} else {
    // User defined (default)
    $order_clause = "ORDER BY im.DETAILS ASC";
}

// Fetch items from tblitemmaster with their current stock
$query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
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

logMessage("Executing query: $query");
logArray($params, "Query parameters");

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
logMessage("Fetched " . count($items) . " items");

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

logMessage("Date range spans $days_count days: " . implode(', ', $date_array));

// Function to update item stock
function updateItemStock($conn, $item_code, $qty, $current_stock_column, $opening_stock_column, $fin_year_id) {
    logMessage("Updating stock for item $item_code, quantity: $qty");
    
    // Check if record exists first
    $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_stock_query);
    $check_stmt->bind_param("s", $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $stock_exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($stock_exists) {
        logMessage("Updating existing stock record for $item_code");
        $stock_query = "UPDATE tblitem_stock SET $current_stock_column = $current_stock_column - ? WHERE ITEM_CODE = ?";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("ds", $qty, $item_code);
        $stock_stmt->execute();
        $stock_stmt->close();
    } else {
        logMessage("Creating new stock record for $item_code");
        $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_stock_column, $current_stock_column) 
                               VALUES (?, ?, ?, ?)";
        $insert_stock_stmt = $conn->prepare($insert_stock_query);
        $current_stock = -$qty; // Negative since we're deducting
        $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $current_stock, $current_stock);
        $insert_stock_stmt->execute();
        $insert_stock_stmt->close();
    }
    
    logMessage("Stock updated successfully for item $item_code");
}

// Function to update daily stock table with proper opening/closing calculations
function updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id) {
    logMessage("Updating daily stock for item $item_code, quantity: $qty, date: $sale_date");
    
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
        logMessage("Updating existing daily stock record for $item_code");
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
        
        logMessage("Daily stock update - Opening: $opening, Purchase: $purchase, Current Sales: $current_sales, New Sales: $new_sales, New Closing: $new_closing");
        
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
                logMessage("Updating next day's opening stock for day $next_day_num");
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
        logMessage("Creating new daily stock record for $item_code");
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
    
    logMessage("Daily stock updated successfully for item $item_code");
}

// Handle form submission for sales update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logMessage("POST request received");
    logPostData();
    
    // Check if this is a duplicate submission
    if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 5) {
        $error_message = "Duplicate submission detected. Please wait a few seconds before trying again.";
        logMessage($error_message, 'WARNING');
    } else {
        $_SESSION['last_submission'] = time();
        
        if (isset($_POST['update_sales'])) {
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $comp_id = $_SESSION['CompID'];
            $user_id = $_SESSION['user_id'];
            $fin_year_id = $_SESSION['FIN_YEAR_ID'];
            
            logMessage("Form submitted for sales update - Start: $start_date, End: $end_date");
            
            // Start transaction
            $conn->begin_transaction();
            logMessage("Transaction started");
            
            try {
                $total_amount = 0;
                $items_data = []; // Store item data for bill generation
                $daily_sales_data = []; // Store daily sales for each item
                
                logMessage("Processing " . count($items) . " items with quantities");
                
                // Process items with quantities
                foreach ($items as $item) {
                    $item_code = $item['CODE'];
                    
                    if (isset($_POST['sale_qty'][$item_code])) {
                        $total_qty = intval($_POST['sale_qty'][$item_code]);
                        $current_stock = $item['CURRENT_STOCK'];
                        
                        // Stock validation
                        if ($total_qty > $current_stock) {
                            $error_message = "Insufficient stock for item {$item_code}. Available: {$current_stock}, Requested: {$total_qty}";
                            logMessage($error_message, 'ERROR');
                            throw new Exception($error_message);
                        }
                        
                        if ($total_qty > 0) {
                            logMessage("Item $item_code quantity: $total_qty");
                            // Generate distribution
                            $daily_sales = distributeSales($total_qty, $days_count);
                            $daily_sales_data[$item_code] = $daily_sales;
                            
                            // Store item data
                            $items_data[$item_code] = [
                                'name' => $item['DETAILS'],
                                'rate' => $item['RPRICE'],
                                'total_qty' => $total_qty
                            ];
                        }
                    }
                }
                
                logMessage("Generating bills with volume limits for " . count($items_data) . " items");
                $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
                logMessage("Generated " . count($bills) . " bills");
                
                // Get stock column names
                $current_stock_column = "Current_Stock" . $comp_id;
                $opening_stock_column = "Opening_Stock" . $comp_id;
                $daily_stock_table = "tbldailystock_" . $comp_id;
                
                // Process each bill
                foreach ($bills as $bill) {
                    logMessage("Processing bill {$bill['bill_no']} with " . count($bill['items']) . " items, amount: {$bill['total_amount']}");
                    
                    // Insert sale header
                    $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                     VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                    $header_stmt = $conn->prepare($header_query);
                    $header_stmt->bind_param("ssddssi", $bill['bill_no'], $bill['bill_date'], $bill['total_amount'], 
                                            $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
                    $header_stmt->execute();
                    $header_stmt->close();
                    
                    // Insert sale details for each item in the bill
                    foreach ($bill['items'] as $item) {
                        logMessage("Bill {$bill['bill_no']} - Item {$item['code']}: Qty={$item['qty']}, Rate={$item['rate']}, Amount={$item['amount']}");
                        
                        $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $detail_stmt = $conn->prepare($detail_query);
                        $detail_stmt->bind_param("ssddssi", $bill['bill_no'], $item['code'], $item['qty'], 
                                                $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                        $detail_stmt->execute();
                        $detail_stmt->close();
                        
                        // Update stock
                        updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
                        
                        // Update daily stock
                        updateDailyStock($conn, $daily_stock_table, $item['code'], $bill['bill_date'], $item['qty'], $comp_id);
                    }
                    
                    $total_amount += $bill['total_amount'];
                }
                
                // Commit transaction
                $conn->commit();
                logMessage("Transaction committed successfully. Total amount: $total_amount");
                
                // CLEAR SESSION QUANTITIES AFTER SUCCESS
                clearSessionQuantities();
                
                $success_message = "Sales distributed successfully! Generated " . count($bills) . " bills. Total Amount: ₹" . number_format($total_amount, 2);
                logMessage($success_message);
                
                // Redirect to retail_sale.php
                header("Location: retail_sale.php?success=" . urlencode($success_message));
                exit;
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $error_message = "Error updating sales: " . $e->getMessage();
                logMessage($error_message, 'ERROR');
                logMessage("Transaction rolled back due to error", 'ERROR');
                logMessage("Stack trace: " . $e->getTraceAsString(), 'ERROR');
            }
        }
    }
}

// Check for success message in URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
    logMessage("Success message from URL: $success_message");
}

// Initialize quantities array from session if available, otherwise from POST
$quantities = [];
foreach ($items as $item) {
    $item_code = $item['CODE'];
    
    // Priority: POST data (current submission)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sale_qty'][$item_code])) {
        $quantities[$item_code] = intval($_POST['sale_qty'][$item_code]);
    }
    // Second priority: Session data (from previous searches)
    elseif (isset($_SESSION['sale_quantities'][$item_code])) {
        $quantities[$item_code] = $_SESSION['sale_quantities'][$item_code];
    }
    // Default: 0
    else {
        $quantities[$item_code] = 0;
    }
}

// Store quantities in session for persistence during searches
$_SESSION['sale_quantities'] = $quantities;

// DEBUG: Log the quantities
logArray($quantities, "Final quantities before rendering");
logMessage("Page rendering completed");
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
    
    /* Volume limit info */
    .volume-limit-info {
      background-color: #e9ecef;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
    }

    /* Closing balance warning styles */
.text-warning {
    color: #ffc107 !important;
}

.fw-bold {
    font-weight: bold !important;
}

/* Negative closing balance (should never happen with validation) */
.text-danger {
    color: #dc3545 !important;
    background-color: #f8d7da;
}

/* Reduce space between table rows */
.styled-table tbody tr {
    height: 35px !important; /* Reduced from default */
    line-height: 1.2 !important;
}

.styled-table tbody td {
    padding: 4px 8px !important; /* Reduced padding */
}

/* Reduce input field height */
.qty-input {
    height: 30px !important;
    padding: 2px 6px !important;
}

/* Reduce button size */
.btn-sm {
    padding: 2px 6px !important;
    font-size: 12px !important;
}

/* Highlight rows with quantities */
tr.has-quantity {
    background-color: #e8f5e8 !important; /* Light green background */
    border-left: 3px solid #28a745 !important;
}

/* Make the highlight more noticeable */
tr.has-quantity td {
    font-weight: 500;
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
        <input type="hidden" name="update_sales" value="1">

        <!-- Action Buttons -->
<div class="d-flex gap-2 mb-3">
  <button type="button" id="shuffleBtn" class="btn btn-warning btn-action" style="display: none;">
    <i class="fas fa-random"></i> Shuffle All
  </button>
  <button type="button" id="generateBillsBtn" class="btn btn-success btn-action" style="display: none;">
    <i class="fas fa-save"></i> Generate Bills
  </button>
  
<!-- Sales Log Button -->
<button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#salesLogModal" onclick="loadSalesLog()">
    <i class="fas fa-file-alt"></i> View Sales Log
</button>

<button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#totalSalesModal">
    <i class="fas fa-chart-bar"></i> View Total Sales Summary
</button>
  
  <a href="dashboard.php" class="btn btn-secondary ms-auto">
    <i class="fas fa-sign-out-alt"></i> Exit
  </a>
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
        if (preg_match('/(\d+)\s*ML\b/i', $item['DETAILS'], $matches)) {
            $size = $matches[1];
        }
        
        // Get class code - now available from the query
        $class_code = $item['CLASS'] ?? 'O'; // Default to 'O' if not set
    ?>
        <tr data-class="<?= htmlspecialchars($class_code) ?>" 
    data-details="<?= htmlspecialchars($item['DETAILS']) ?>" 
    data-details2="<?= htmlspecialchars($item['DETAILS2']) ?>"
    class="<?= $item_qty > 0 ? 'has-quantity' : '' ?>">
            <td><?= htmlspecialchars($item_code); ?></td>
            <td><?= htmlspecialchars($item['DETAILS']); ?></td>
            <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
            <td><?= number_format($item['RPRICE'], 2); ?></td>
            <td>
                <span class="stock-info"><?= number_format($item['CURRENT_STOCK'], 3); ?></span>
            </td>
            <td>
    <input type="number" name="sale_qty[<?= htmlspecialchars($item_code); ?>]" 
           class="form-control qty-input" min="0" 
           max="<?= floor($item['CURRENT_STOCK']); ?>" 
           step="1" value="<?= $item_qty ?>" 
           data-rate="<?= $item['RPRICE'] ?>"
           data-code="<?= htmlspecialchars($item_code); ?>"
           data-stock="<?= $item['CURRENT_STOCK'] ?>"
           data-size="<?= $size ?>"
           oninput="validateQuantity(this)">
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

<!-- Sales Log Modal -->
<div class="modal fade" id="salesLogModal" tabindex="-1" aria-labelledby="salesLogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="salesLogModalLabel">Sales Log - Foreign Export</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="salesLogContent" style="max-height: 400px; overflow-y: auto;">
                    <!-- Sales log content will be loaded here -->
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading sales log...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printSalesLog()">Print</button>
            </div>
        </div>
    </div>
</div>

<!-- Total Sales Modal -->
<div class="modal fade" id="totalSalesModal" tabindex="-1" aria-labelledby="totalSalesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="totalSalesModalLabel">Total Sales Summary</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Total Sales Module Table will be inserted here by JavaScript -->
                <div id="totalSalesModuleContainer">
                    <table class="table table-bordered table-sm" id="totalSalesTable">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>2 Ltrs.</th>
                                <th>1 Ltr.</th>
                                <th>750 ML</th>
                                <th>650 ML</th>
                                <th>500 ML</th>
                                <th>375 ML</th>
                                <th>330 ML</th>
                                <th>275 ML</th>
                                <th>180 ML</th>
                                <th>100 ML</th>
                                <th>90 ML</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Rows will be populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printSalesSummary()">Print</button>
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

// Function to clear session quantities via AJAX
function clearSessionQuantities() {
    $.ajax({
        url: 'clear_session_quantities.php',
        type: 'POST',
        success: function() {
            console.log('Session quantities cleared');
        },
        error: function() {
            console.log('Error clearing session quantities');
        }
    });
}

// Function to validate all quantities before form submission
function validateAllQuantities() {
    let isValid = true;
    let errorItems = [];
    
    $('input[name^="sale_qty"]').each(function() {
        const itemCode = $(this).data('code');
        const currentStock = parseFloat($(this).data('stock'));
        const enteredQty = parseInt($(this).val()) || 0;
        const closingBalance = currentStock - enteredQty;
        
        if (closingBalance < 0) {
            isValid = false;
            errorItems.push({
                code: itemCode,
                stock: currentStock,
                qty: enteredQty
            });
        }
    });
    
    if (!isValid) {
        let errorMessage = "The following items have insufficient stock:\n\n";
        errorItems.forEach(item => {
            errorMessage += `• Item ${item.code}: Stock ${item.stock.toFixed(3)}, Quantity ${item.qty}\n`;
        });
        errorMessage += "\nPlease adjust quantities to avoid negative closing balance.";
        alert(errorMessage);
    }
    
    return isValid;
}

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

// Function to validate quantity input and prevent negative closing balance
function validateQuantity(input) {
    const itemCode = $(input).data('code');
    const currentStock = parseFloat($(input).data('stock'));
    const enteredQty = parseInt($(input).val()) || 0;
    
    // Prevent entering quantity greater than available stock
    if (enteredQty > currentStock) {
        alert(`Quantity cannot exceed available stock of ${currentStock.toFixed(3)}`);
        $(input).val(Math.floor(currentStock)); // Set to max available
        return false;
    }
    
    // Calculate and update closing balance in real-time
    const closingBalance = currentStock - enteredQty;
    $(`#closing_${itemCode}`).text(closingBalance.toFixed(3));
    
    // Update amount
    const rate = parseFloat($(input).data('rate'));
    const amount = enteredQty * rate;
    $(`#amount_${itemCode}`).text(amount.toFixed(2));
    
    // Toggle row highlighting based on quantity
    const row = $(input).closest('tr');
    if (enteredQty > 0) {
        row.addClass('has-quantity');
    } else {
        row.removeClass('has-quantity');
    }
    
    // Highlight if closing balance is low (but not negative)
    if (closingBalance < (currentStock * 0.1)) { // Less than 10% of original stock
        $(`#closing_${itemCode}`).addClass('text-warning fw-bold');
    } else {
        $(`#closing_${itemCode}`).removeClass('text-warning fw-bold');
    }
    
    return true;
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

// Function to save to pending sales
function saveToPendingSales() {
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
        return false;
    }
    
    // Validate all quantities to prevent negative closing balance
    if (!validateAllQuantities()) {
        return false;
    }
    
    // Show loader
    $('#ajaxLoader').show();
    
    // Collect all the data
    const formData = new FormData();
    formData.append('save_pending', 'true');
    formData.append('start_date', '<?= $start_date ?>');
    formData.append('end_date', '<?= $end_date ?>');
    formData.append('mode', '<?= $mode ?>');
    
    // Add each item's quantity
    $('input[name^="sale_qty"]').each(function() {
        const itemCode = $(this).data('code');
        const qty = $(this).val();
        if (qty > 0) {
            formData.append(`sale_qty[${itemCode}]`, qty);
        }
    });
    
    // Send AJAX request
    $.ajax({
        url: 'save_pending_sales.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            $('#ajaxLoader').hide();
            try {
                const result = JSON.parse(response);
                if (result.success) {
                    // Clear session quantities
                    clearSessionQuantities();
                    alert('Sales data saved successfully! You can generate bills later from the "Post Daily Sales" page.');
                    window.location.href = 'retail_sale.php?success=' + encodeURIComponent(result.message);
                } else {
                    alert('Error: ' + result.message);
                }
            } catch (e) {
                alert('Error processing response: ' + response);
            }
        },
        error: function() {
            $('#ajaxLoader').hide();
            alert('Error saving data. Please try again.');
        }
    });
}

// Function to generate bills immediately
function generateBills() {
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
        return false;
    }
    
    // Validate all quantities to prevent negative closing balance
    if (!validateAllQuantities()) {
        return false;
    }
    
    // Show loader
    $('#ajaxLoader').show();
    
    // Submit the form normally (not via AJAX) to maintain server-side processing
    document.getElementById('salesForm').submit();
}

// Function to load sales log content
function loadSalesLog() {
    // Show loading state
    $('#salesLogContent').html(`
        <div class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading sales log...</p>
        </div>
    `);
    
    // Load sales log content via AJAX
    $.ajax({
        url: 'sales_log_ajax.php',
        type: 'GET',
        dataType: 'html',
        success: function(response) {
            $('#salesLogContent').html(response);
        },
        error: function() {
            $('#salesLogContent').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load sales log. Please try again.
                </div>
            `);
        }
    });
}

// Function to print sales log
function printSalesLog() {
    const printContent = $('#salesLogContent').html();
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Sales Log - Foreign Export</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f8f9fa; }
                .text-center { text-align: center; }
                .no-print { display: none; }
            </style>
        </head>
        <body>
            <h2 class="text-center">Sales Log - Foreign Export</h2>
            ${printContent}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
}

// Function to get item data from row
function getItemData(itemCode) {
    const itemRow = $(`input[name="sale_qty[${itemCode}]"]`).closest('tr');
    return {
        classCode: itemRow.data('class'),
        details: itemRow.data('details'),
        details2: itemRow.data('details2'),
        quantity: parseInt($(`input[name="sale_qty[${itemCode}]"]`).val()) || 0
    };
}

// Function to classify product type from class code
function getProductType(classCode) {
    const spirits = ['W', 'G', 'D', 'K', 'R', 'O'];
    if (spirits.includes(classCode)) return 'SPIRITS';
    if (classCode === 'V') return 'WINE';
    if (classCode === 'B') return 'FERMENTED BEER';
    if (classCode === 'M') return 'MILD BEER';
    return 'OTHER';
}

// Function to extract volume from details
function extractVolume(details, details2) {
    // Priority: details2 column first
    if (details2) {
        const volumeMatch = details2.match(/(\d+)\s*(ML|LTR?)/i);
        if (volumeMatch) {
            let volume = parseInt(volumeMatch[1]);
            const unit = volumeMatch[2].toUpperCase();
            
            if (unit === 'LTR' || unit === 'L') {
                volume = volume * 1000; // Convert liters to ML
            }
            return volume;
        }
    }
    
    // Fallback: parse details column
    if (details) {
        // Handle special cases like QUART, PINT, NIP
        if (details.includes('QUART')) return 750;
        if (details.includes('PINT')) return 375;
        if (details.includes('NIP')) return 90;
        if (details.includes('80 ML')) return 80;
        
        // Try to extract numeric volume
        const volumeMatch = details.match(/(\d+)\s*(ML|LTR?)/i);
        if (volumeMatch) {
            let volume = parseInt(volumeMatch[1]);
            const unit = volumeMatch[2].toUpperCase();
            
            if (unit === 'LTR' || unit === 'L') {
                volume = volume * 1000;
            }
            return volume;
        }
    }
    
    return 0; // Unknown volume
}

// Function to map volume to column
function getVolumeColumn(volume) {
    const volumeMap = {
        2000: '2 Ltrs.',
        1000: '1 Ltr.',
        750: '750 ML',
        650: '650 ML',
        500: '500 ML',
        375: '375 ML',
        330: '330 ML',
        275: '275 ML',
        180: '180 ML',
        100: '100 ML',
        90: '90 ML',
        80: '100 ML' // Map 80ML to 100ML column as per your example
    };
    
    return volumeMap[volume] || null;
}

// Function to update total sales module
function updateTotalSalesModule() {
    // Initialize empty summary object
    const salesSummary = {
        'SPIRITS': {'2 Ltrs.': 0, '1 Ltr.': 0, '750 ML': 0, '650 ML': 0, '500 ML': 0, 
                   '375 ML': 0, '330 ML': 0, '275 ML': 0, '180 ML': 0, '100 ML': 0, '90 ML': 0},
        'WINE': {'2 Ltrs.': 0, '1 Ltr.': 0, '750 ML': 0, '650 ML': 0, '500 ML': 0, 
                '375 ML': 0, '330 ML': 0, '275 ML': 0, '180 ML': 0, '100 ML': 0, '90 ML': 0},
        'FERMENTED BEER': {'2 Ltrs.': 0, '1 Ltr.': 0, '750 ML': 0, '650 ML': 0, '500 ML': 0, 
                          '375 ML': 0, '330 ML': 0, '275 ML': 0, '180 ML': 0, '100 ML': 0, '90 ML': 0},
        'MILD BEER': {'2 Ltrs.': 0, '1 Ltr.': 0, '750 ML': 0, '650 ML': 0, '500 ML': 0, 
                     '375 ML': 0, '330 ML': 0, '275 ML': 0, '180 ML': 0, '100 ML': 0, '90 ML': 0}
    };

    // Process all quantity inputs
    $('input[name^="sale_qty"]').each(function() {
        const quantity = parseInt($(this).val()) || 0;
        if (quantity > 0) {
            const itemCode = $(this).data('code');
            const itemRow = $(this).closest('tr');
            
            // Get item details
            const classCode = itemRow.data('class');
            const details = itemRow.data('details');
            const details2 = itemRow.data('details2');
            
            const productType = getProductType(classCode);
            const volume = extractVolume(details, details2);
            const volumeColumn = getVolumeColumn(volume);
            
            if (volumeColumn && salesSummary[productType]) {
                salesSummary[productType][volumeColumn] += quantity;
            }
        }
    });

    // Update the modal table
    updateSalesModalTable(salesSummary);
}

// Function to update modal table with calculated values
function updateSalesModalTable(salesSummary) {
    const tbody = $('#totalSalesTable tbody');
    tbody.empty();
    
    ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER'].forEach(category => {
        const row = $('<tr>');
        row.append($('<td>').text(category));
        
        ['2 Ltrs.', '1 Ltr.', '750 ML', '650 ML', '500 ML', '375 ML', 
         '330 ML', '275 ML', '180 ML', '100 ML', '90 ML'].forEach(volume => {
            const value = salesSummary[category][volume] || 0;
            row.append($('<td>').text(value > 0 ? value : ''));
        });
        
        tbody.append(row);
    });
}

// Print function
function printSalesSummary() {
    const printContent = document.getElementById('totalSalesModuleContainer').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = printContent;
    window.print();
    document.body.innerHTML = originalContent;
    
    // Re-initialize any necessary scripts
    location.reload();
}

// Document ready
$(document).ready(function() {
    // Initialize table headers and columns
    initializeTableHeaders();
    
    // Set up row navigation with arrow keys
    setupRowNavigation();
    
    // Show action buttons
    $('#shuffleBtn, .btn-shuffle-item, .btn-shuffle').show();
    $('#generateBillsBtn').show();
    
    // Quantity input change event
    $(document).on('change', 'input[name^="sale_qty"]', function() {
        // First validate the quantity
        if (!validateQuantity(this)) {
            return; // Stop if validation fails
        }
        
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

        // Also update total sales module if modal is open
        if ($('#totalSalesModal').hasClass('show')) {
            updateTotalSalesModule();
        }
        
        // Update total amount
        calculateTotalAmount();
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
    
    // Generate bills button click event - FIXED VERSION
    $('#generateBillsBtn').click(function() {
        // Show confirmation dialog only once
        if (confirm('Do you want to generate sale bills now? Click "OK" to generate bills or "Cancel" to save for later posting.')) {
            // User clicked OK - generate bills immediately
            generateBills();
        } else {
            // User clicked Cancel - save to pending sales
            saveToPendingSales();
        }
    });
    
    // Auto-load sales log when modal is shown
    $('#salesLogModal').on('shown.bs.modal', function() {
        loadSalesLog();
    });
    
    // Update total sales module when modal is shown
    $('#totalSalesModal').on('show.bs.modal', function() {
        updateTotalSalesModule();
    });
});
</script> 
</body>
</html>