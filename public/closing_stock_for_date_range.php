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

// Build query based on sequence type
if ($sequence_type === 'system_defined') {
    $order_clause = "ORDER BY im.CODE ASC";
} elseif ($sequence_type === 'group_defined') {
    $order_clause = "ORDER BY im.DETAILS2 ASC, im.DETAILS ASC";
} else {
    // User defined (default)
    $order_clause = "ORDER BY im.DETAILS ASC";
}

logMessage("Order clause: $order_clause");

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
                    
                    if ($total_qty > 0) {
                        logMessage("Item $item_code quantity: $total_qty");
                        // Generate distribution
                        $daily_sales = distributeSales($total_qty, $days_count);
                        $daily_sales_data[$item_code] = $daily_sales;
                        
                        // Store item data - MAKE SURE YOU'RE INCLUDING THE RATE
                        $items_data[$item_code] = [
                            'name' => $item['DETAILS'],
                            'rate' => $item['RPRICE'], // This is crucial for amount calculation
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

// Check for success message in URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
    logMessage("Success message from URL: $success_message");
}

// Initialize closing balances array
$closing_balances = [];
foreach ($items as $item) {
    $closing_balances[$item['CODE']] = intval($item['CURRENT_STOCK']); // Convert to integer
}

// DEBUG: Log the initial closing balances
logArray($closing_balances, "Initial closing balances");

// If we have POST data with closing balances, update the closing_balances array
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['closing_balance'])) {
    foreach ($_POST['closing_balance'] as $item_code => $closing_balance) {
        if (isset($closing_balances[$item_code])) {
            $closing_balances[$item_code] = intval($closing_balance);
        }
    }
    // DEBUG: Log the updated closing balances from POST
    logArray($_POST['closing_balance'], "POST closing balances");
    logArray($closing_balances, "Updated closing balances from POST");
}

// DEBUG: Log the final closing balances before rendering
logArray($closing_balances, "Final closing balances before rendering");

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

      <!-- Volume Limit Information -->
      <div class="volume-limit-info">
          <h5><i class="fas fa-info-circle"></i> Volume Limit Information</h5>
          <p>Bills will be automatically split when the total volume exceeds the category limits:</p>
          <ul>
              <li><strong>IMFL Limit:</strong> <?= getCategoryLimits($conn, $comp_id)['IMFL'] ?> ML</li>
              <li><strong>BEER Limit:</strong> <?= getCategoryLimits($conn, $comp_id)['BEER'] ?> ML</li>
              <li><strong>CL Limit:</strong> <?= getCategoryLimits($conn, $comp_id)['CL'] ?> ML</li>
          </ul>
          <p class="mb-0">Items are sorted by volume (descending) and packed optimally to minimize the number of bills.</p>
      </div>

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
            <i class="fas fa-random"></i> Shuffle All
          </button>
          <button type="button" id="generateBillsBtn" class="btn btn-success btn-action" style="display: none;">
            <i class="fas fa-save"></i> Generate Bills
          </button>
          <!-- Sales Log Button -->
<button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#salesLogModal" onclick="loadSalesLog()">
    <i class="fas fa-file-alt"></i> View Sales Log
</button>
          
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>
        
        <div class="alert alert-info mt-3">
          <i class="fas fa-info-circle"></i> 
          Enter the desired closing balance for each item. The sale quantity will be calculated as: <strong>Current Stock - Closing Balance</strong>.
          <br>Total sales quantities will be uniformly distributed across the selected date range as whole numbers.
          <br><strong>Distribution:</strong> Click "Shuffle All" to regenerate all distributions.
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
                <th class="closing-balance-header">Closing Balance</th>
                <th>Total Sale Qty</th>
                <th class="action-column">Action</th>
                
                <!-- Date Distribution Headers (will be populated by JavaScript) -->
                
                <th class="hidden-columns">Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php foreach ($items as $item): 
                $item_code = $item['CODE'];
                $closing_balance = isset($closing_balances[$item_code]) ? $closing_balances[$item_code] : intval($item['CURRENT_STOCK']);
                $sale_qty = intval($item['CURRENT_STOCK']) - $closing_balance;
                $item_total = $sale_qty * $item['RPRICE'];
              ?>
                <tr>
                  <td><?= htmlspecialchars($item_code); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                  <td><?= number_format($item['RPRICE'], 2); ?></td>
                  <td>
                    <span class="stock-info"><?= number_format($item['CURRENT_STOCK'], 0); ?></span>
                  </td>
                  <td class="closing-balance-cell">
                    <input type="number" name="closing_balance[<?= htmlspecialchars($item_code); ?>]" 
                           class="form-control closing-input" min="0" max="<?= intval($item['CURRENT_STOCK']); ?>" 
                           step="1" value="<?= $closing_balance ?>" 
                           data-rate="<?= $item['RPRICE'] ?>"
                           data-code="<?= htmlspecialchars($item_code); ?>"
                           data-stock="<?= intval($item['CURRENT_STOCK']) ?>">
                  </td>
                  <td id="sale_qty_<?= htmlspecialchars($item_code); ?>">
                    <?= $sale_qty ?>
                  </td>
                  <td class="action-column">
                    <button type="button" class="btn btn-sm btn-outline-secondary btn-shuffle-item" 
                            data-code="<?= htmlspecialchars($item_code); ?>" style="display: none;">
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
                <td class="action-column"><strong id="totalAmount"><?= number_format(array_sum(array_map(function($item) use ($closing_balances) {
                  $sale_qty = intval($item['CURRENT_STOCK']) - (isset($closing_balances[$item['CODE']]) ? $closing_balances[$item['CODE']] : intval($item['CURRENT_STOCK']));
                  return $sale_qty * $item['RPRICE'];
                }, $items)), 2) ?></strong></td>
                <td class="hidden-columns"></td>
              </tr>
            </tfoot>
          </table>
        </div>
        
        <!-- Hidden submit button for form submission -->
        <button type="submit" name="update_sales" class="d-none" id="updateSalesBtn"></button>
      </form>
    </div>
  </div>
</div>

<!-- AJAX Loader -->
<div class="ajax-loader" id="ajaxLoader">
  <div class="loader"></div>
  <p>Processing your request...</p>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize date range
    const startDate = new Date('<?= $start_date ?>');
    const endDate = new Date('<?= $end_date ?>');
    const daysCount = <?= $days_count ?>;
    
    // Generate date headers
    const dateHeaders = generateDateHeaders(startDate, endDate);
    addDateHeadersToTable(dateHeaders);
    
    // Generate distribution cells for each item
    generateDistributionCells(daysCount);
    
    // Show action buttons
    $('#shuffleBtn, #generateBillsBtn').show();
    $('.btn-shuffle-item').show();
    
    // Calculate total amount on page load
    updateTotalAmount();
    
    // Add keyboard navigation for closing balance inputs
    $('.closing-input').on('keydown', function(e) {
        if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
            e.preventDefault();
            
            // Get all closing balance inputs
            const inputs = $('.closing-input').toArray();
            const currentIndex = inputs.indexOf(this);
            
            // Find next/previous input
            let nextIndex;
            if (e.key === 'ArrowUp') {
                nextIndex = currentIndex - 1;
                if (nextIndex < 0) nextIndex = inputs.length - 1; // Wrap around to bottom
            } else { // ArrowDown
                nextIndex = currentIndex + 1;
                if (nextIndex >= inputs.length) nextIndex = 0; // Wrap around to top
            }
            
            // Focus on the next/previous input
            $(inputs[nextIndex]).focus().select();
        }
    });
    
    // Handle closing balance input changes
    $('.closing-input').on('input', function() {
        const itemCode = $(this).data('code');
        const currentStock = parseInt($(this).data('stock'));
        const rate = parseFloat($(this).data('rate'));
        let closingBalance = parseInt($(this).val()) || 0;
        
        // Validate closing balance
        if (closingBalance < 0) {
            closingBalance = 0;
            $(this).val(0);
        } else if (closingBalance > currentStock) {
            closingBalance = currentStock;
            $(this).val(currentStock);
        }
        
        // Calculate sale quantity
        const saleQty = currentStock - closingBalance;
        $('#sale_qty_' + itemCode).text(saleQty);
        
        // Calculate item amount
        const amount = saleQty * rate;
        $('#amount_' + itemCode).text(amount.toFixed(2));
        
        // Update total amount
        updateTotalAmount();
        
        // Update distribution cells if they exist
        updateDistributionCells(itemCode, saleQty, daysCount);
    });
    
    // Shuffle all distributions
    $('#shuffleBtn').on('click', function() {
        shuffleAllDistributions(daysCount);
    });
    
    // Generate bills
    $('#generateBillsBtn').on('click', function() {
        // Show loader
        $('#ajaxLoader').show();
        
        // Submit the form
        $('#updateSalesBtn').click();
    });
    
    // Individual item shuffle
    $(document).on('click', '.btn-shuffle-item', function() {
        const itemCode = $(this).data('code');
        const saleQty = parseInt($('#sale_qty_' + itemCode).text());
        updateDistributionCells(itemCode, saleQty, daysCount);
    });
    
    // Function to generate date headers
    function generateDateHeaders(startDate, endDate) {
        const headers = [];
        let currentDate = new Date(startDate);
        
        while (currentDate <= endDate) {
            headers.push({
                date: currentDate.toISOString().split('T')[0],
                display: currentDate.toLocaleDateString('en-US', { 
                    day: '2-digit', 
                    month: 'short'
                })
            });
            currentDate.setDate(currentDate.getDate() + 1);
        }
        
        return headers;
    }
    
    // Function to add date headers to table
    function addDateHeadersToTable(dateHeaders) {
        // Add headers to thead
        const headerRow = $('.table-header tr');
        
        // Insert after the action column and before the amount column
        dateHeaders.forEach(header => {
            $('<th>')
                .addClass('date-header')
                .attr('title', header.date)
                .text(header.display)
                .insertBefore(headerRow.find('.action-column'));
        });
        
        // Adjust colspan for footer
        $('tfoot tr td:first').attr('colspan', 7 + dateHeaders.length);
    }
    
    // Function to generate distribution cells for each item
    function generateDistributionCells(daysCount) {
        $('tbody tr').each(function() {
            const itemCode = $(this).find('.closing-input').data('code');
            const saleQty = parseInt($('#sale_qty_' + itemCode).text());
            
            // Add distribution cells before the action column
            for (let i = 0; i < daysCount; i++) {
                $('<td>')
                    .addClass('distribution-cell')
                    .attr('id', `dist_${itemCode}_${i}`)
                    .text('0')
                    .insertBefore($(this).find('.action-column'));
            }
            
            // Update the cells with distribution
            updateDistributionCells(itemCode, saleQty, daysCount);
        });
    }
    
    // Function to update distribution cells for an item
    function updateDistributionCells(itemCode, saleQty, daysCount) {
        if (saleQty <= 0) {
            // Clear all distribution cells
            for (let i = 0; i < daysCount; i++) {
                $(`#dist_${itemCode}_${i}`).text('0');
            }
            return;
        }
        
        // Generate distribution
        const distribution = distributeSales(saleQty, daysCount);
        
        // Update distribution cells
        for (let i = 0; i < daysCount; i++) {
            $(`#dist_${itemCode}_${i}`).text(distribution[i] || 0);
        }
    }
    
    // Function to shuffle all distributions
    function shuffleAllDistributions(daysCount) {
        $('tbody tr').each(function() {
            const itemCode = $(this).find('.closing-input').data('code');
            const saleQty = parseInt($('#sale_qty_' + itemCode).text());
            
            if (saleQty > 0) {
                updateDistributionCells(itemCode, saleQty, daysCount);
            }
        });
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
        url: 'sales_log_ajax.php', // Create this file (see below)
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

// Optional: Auto-load sales log when modal is shown
$(document).ready(function() {
    $('#salesLogModal').on('shown.bs.modal', function() {
        loadSalesLog();
    });
});
    
    // Function to update total amount
    function updateTotalAmount() {
        let total = 0;
        
        $('.amount-cell').each(function() {
            total += parseFloat($(this).text()) || 0;
        });
        
        $('#totalAmount').text(total.toFixed(2));
    }
    
    // Function to distribute sales (uniform distribution)
    function distributeSales(totalQty, daysCount) {
        if (totalQty <= 0 || daysCount <= 0) return Array(daysCount).fill(0);
        
        const baseQty = Math.floor(totalQty / daysCount);
        const remainder = totalQty % daysCount;
        
        const distribution = Array(daysCount).fill(baseQty);
        
        // Distribute remainder randomly
        for (let i = 0; i < remainder; i++) {
            const randomIndex = Math.floor(Math.random() * daysCount);
            distribution[randomIndex]++;
        }
        
        return distribution;
    }
    
    // Add row highlighting on focus
    $('.closing-input').on('focus', function() {
        $(this).closest('tr').addClass('highlight-row');
    });
    
    $('.closing-input').on('blur', function() {
        $(this).closest('tr').removeClass('highlight-row');
    });
});
</script>
</body>
</html>