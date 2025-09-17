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

// Function to get item volume from tblsubclass
function getItemVolume($conn, $item_code) {
    // Extract subclass code from item code (assuming format like SCMPL0019028 where SC001 is the subclass)
    $subclass_code = substr($item_code, 4, 4); // Get the 4 characters after "SCMP"
    
    $query = "SELECT CC FROM tblsubclass WHERE ITEM_GROUP = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $subclass_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['CC'];
    }
    
    // Default to 0 if not found
    return 0;
}

// Function to get item class from tblitemmaster
function getItemClass($conn, $item_code) {
    $query = "SELECT DETAILS2 FROM tblitemmaster WHERE CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['DETAILS2'];
    }
    
    // Default to empty if not found
    return '';
}

// Function to get company limits
function getCompanyLimits($conn, $comp_id) {
    $query = "SELECT IMFLLimit, BEERLimit, CLLimit FROM tblcompany WHERE CompID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    
    // Default limits if not found
    return ['IMFLLimit' => 1000, 'BEERLimit' => 4000, 'CLLimit' => 0];
}

// Function to determine liquor type based on class
function getLiquorType($class) {
    // IMFL classes: W, V, G, D, K, R, O (Foreign)
    $imfl_classes = ['W', 'V', 'G', 'D', 'K', 'R', 'O'];
    
    // Beer classes: B, M
    $beer_classes = ['B', 'M'];
    
    // CL classes: L, O (Country)
    $cl_classes = ['L', 'O'];
    
    if (in_array($class, $imfl_classes)) {
        return 'IMFL';
    } elseif (in_array($class, $beer_classes)) {
        return 'BEER';
    } elseif (in_array($class, $cl_classes)) {
        return 'CL';
    }
    
    return 'OTHER';
}

// Function to split items into bills based on volume limits
function splitItemsIntoBills($items_with_qty, $conn, $comp_id) {
    $bills = [];
    $company_limits = getCompanyLimits($conn, $comp_id);
    
    // Group items by liquor type
    $items_by_type = [
        'IMFL' => [],
        'BEER' => [],
        'CL' => [],
        'OTHER' => []
    ];
    
    foreach ($items_with_qty as $item_code => $qty) {
        if ($qty <= 0) continue;
        
        $class = getItemClass($conn, $item_code);
        $liquor_type = getLiquorType($class);
        $volume = getItemVolume($conn, $item_code);
        
        $items_by_type[$liquor_type][] = [
            'code' => $item_code,
            'qty' => $qty,
            'volume' => $volume,
            'total_volume' => $volume * $qty
        ];
    }
    
    // Process each liquor type separately
    foreach ($items_by_type as $type => $items) {
        if (empty($items)) continue;
        
        $limit = 0;
        switch ($type) {
            case 'IMFL':
                $limit = $company_limits['IMFLLimit'];
                break;
            case 'BEER':
                $limit = $company_limits['BEERLimit'];
                break;
            case 'CL':
                $limit = $company_limits['CLLimit'];
                break;
            default:
                $limit = PHP_INT_MAX; // No limit for other types
        }
        
        // If no limit or limit is 0, put all items in one bill
        if ($limit <= 0) {
            $bills[] = $items;
            continue;
        }
        
        // Split items into bills based on volume limit
        $current_bill = [];
        $current_volume = 0;
        
        foreach ($items as $item) {
            $item_volume = $item['total_volume'];
            
            // If adding this item would exceed the limit, start a new bill
            if ($current_volume + $item_volume > $limit && !empty($current_bill)) {
                $bills[] = $current_bill;
                $current_bill = [];
                $current_volume = 0;
            }
            
            // Add item to current bill
            $current_bill[] = $item;
            $current_volume += $item_volume;
        }
        
        // Add the last bill if it has items
        if (!empty($current_bill)) {
            $bills[] = $current_bill;
        }
    }
    
    return $bills;
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
            
            // Collect items with quantities
            $items_with_qty = [];
            foreach ($items as $item) {
                $item_code = $item['CODE'];
                
                // Check if this item has a quantity in the POST data
                if (isset($_POST['sale_qty'][$item_code])) {
                    $total_qty = intval($_POST['sale_qty'][$item_code]); // Convert to integer
                    
                    if ($total_qty > 0) {
                        $items_with_qty[$item_code] = $total_qty;
                    }
                }
            }
            
            // Split items into bills based on volume limits
            $bills = splitItemsIntoBills($items_with_qty, $conn, $comp_id);
            
            // Process each bill
            foreach ($bills as $bill_items) {
                // Process each item in the bill
                foreach ($bill_items as $item) {
                    $item_code = $item['code'];
                    $total_qty = $item['qty'];
                    $rate = 0;
                    $item_name = '';
                    
                    // Find item details
                    foreach ($items as $item_detail) {
                        if ($item_detail['CODE'] === $item_code) {
                            $rate = $item_detail['RPRICE'];
                            $item_name = $item_detail['DETAILS'];
                            break;
                        }
                    }
                    
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
          <button type="button" id="generateBillsBtn" class="btn btn-success btn-action" style="display: none;">
            <i class="fas fa-save"></i> Generate Bills
          </button>
          
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>
        
        <div class="alert alert-info mt-3">
          <i class="fas fa-info-circle"></i> 
          Total sales quantities will be uniformly distributed across the selected date range as whole numbers.
          <br><strong>Distribution:</strong> Enter quantities to see the distribution across dates. Click "Shuffle All" to regenerate all distributions.
          <br><strong>Bill Splitting:</strong> Bills will be automatically split based on volume limits (IMFL: 1000ml, Beer: 4000ml).
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
                
                // Get class description
                $class_desc = isset($classes[$item['DETAILS2']]) ? $classes[$item['DETAILS2']] : $item['DETAILS2'];
              ?>
              <tr class="item-row" data-code="<?= $item_code ?>" data-size="<?= $size ?>">
                <td><?= $item_code ?></td>
                <td><?= $item['DETAILS'] ?></td>
                <td><?= $class_desc ?></td>
                <td><?= number_format($item['RPRICE'], 2) ?></td>
                <td><?= number_format($item['CURRENT_STOCK'], 3) ?></td>
                <td>
                  <input type="number" name="sale_qty[<?= $item_code ?>]" 
                         class="form-control sale-qty-input" min="0" step="1" 
                         value="<?= $item_qty ?>" 
                         data-rate="<?= $item['RPRICE'] ?>"
                         data-stock="<?= $item['CURRENT_STOCK'] ?>">
                </td>
                <td class="closing-balance"><?= number_format($closing_balance, 3) ?></td>
                <td class="action-column">
                  <button type="button" class="btn btn-sm btn-outline-primary shuffle-btn" 
                          data-code="<?= $item_code ?>">
                    <i class="fas fa-random"></i>
                  </button>
                </td>
                
                <!-- Date Distribution Cells (will be populated by JavaScript) -->
                
                <td class="item-total hidden-columns"><?= number_format($item_total, 2) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" class="text-center">No items found.</td>
              </tr>
            <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="5" class="text-end fw-bold">Total:</td>
                <td id="total-qty">0</td>
                <td colspan="2"></td>
                <td id="total-amount" class="hidden-columns">₹0.00</td>
              </tr>
            </tfoot>
          </table>
        </div>
        
        <!-- Submit Button -->
        <div class="mt-4">
          <button type="submit" name="update_sales" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Update Sales
          </button>
        </div>
      </form>
      
      <!-- Sale Module Modal -->
      <div class="modal fade sale-module-modal" id="saleModuleModal" tabindex="-1" aria-labelledby="saleModuleModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
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
                      <th rowspan="2">Item Code</th>
                      <th rowspan="2">Item Name</th>
                      <th rowspan="2">Rate (₹)</th>
                      <th rowspan="2">Current Stock</th>
                      <?php foreach ($all_sizes as $size): ?>
                        <th colspan="2"><?= $size ?> ML</th>
                      <?php endforeach; ?>
                    </tr>
                    <tr>
                      <?php foreach ($all_sizes as $size): ?>
                        <th>Qty</th>
                        <th>Amount</th>
                      <?php endforeach; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($subclass_groups as $cc => $subclasses): ?>
                      <?php foreach ($subclasses as $subclass): ?>
                        <tr>
                          <td><?= $subclass['ITEM_GROUP'] ?></td>
                          <td><?= $subclass['DESC'] ?></td>
                          <td></td>
                          <td></td>
                          <?php foreach ($all_sizes as $size): ?>
                            <td>
                              <input type="number" class="form-control form-control-sm" 
                                     name="sale_module_qty[<?= $subclass['ITEM_GROUP'] ?>][<?= $size ?>]" 
                                     min="0" step="1" value="0">
                            </td>
                            <td class="sale-module-amount"></td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="button" class="btn btn-primary" id="applySaleModule">Apply to Main Table</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const dateArray = <?= json_encode($date_array) ?>;
  const daysCount = <?= $days_count ?>;
  const table = document.getElementById('itemsTable');
  const tbody = table.querySelector('tbody');
  const totalQtyEl = document.getElementById('total-qty');
  const totalAmountEl = document.getElementById('total-amount');
  const shuffleBtn = document.getElementById('shuffleBtn');
  const generateBillsBtn = document.getElementById('generateBillsBtn');
  const salesForm = document.getElementById('salesForm');
  
  // Create date headers
  const headerRow = table.querySelector('thead tr');
  const dateHeaders = [];
  
  // Insert date headers before the Amount column
  const amountHeader = headerRow.querySelector('.hidden-columns');
  
  dateArray.forEach((date, index) => {
    const dateObj = new Date(date);
    const day = dateObj.getDate();
    const month = dateObj.toLocaleString('default', { month: 'short' });
    
    const th = document.createElement('th');
    th.textContent = `${day} ${month}`;
    th.className = 'date-header';
    th.setAttribute('data-date', date);
    
    headerRow.insertBefore(th, amountHeader);
    dateHeaders.push(th);
  });
  
  // Create distribution cells for each item row
  const itemRows = tbody.querySelectorAll('.item-row');
  
  itemRows.forEach(row => {
    const itemCode = row.dataset.code;
    const amountCell = row.querySelector('.item-total');
    
    dateArray.forEach((date, index) => {
      const td = document.createElement('td');
      td.className = 'daily-qty';
      td.setAttribute('data-date', date);
      td.setAttribute('data-index', index);
      td.innerHTML = '0';
      
      row.insertBefore(td, amountCell);
    });
  });
  
  // Function to distribute sales
  function distributeSales(totalQty, daysCount) {
    if (totalQty <= 0 || daysCount <= 0) return Array(daysCount).fill(0);
    
    const baseQty = Math.floor(totalQty / daysCount);
    const remainder = totalQty % daysCount;
    
    const dailySales = Array(daysCount).fill(baseQty);
    
    // Distribute remainder
    for (let i = 0; i < remainder; i++) {
      dailySales[i]++;
    }
    
    // Shuffle the distribution
    for (let i = dailySales.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [dailySales[i], dailySales[j]] = [dailySales[j], dailySales[i]];
    }
    
    return dailySales;
  }
  
  // Update distribution for a specific item
  function updateItemDistribution(itemCode, totalQty) {
    const row = document.querySelector(`.item-row[data-code="${itemCode}"]`);
    if (!row) return;
    
    const dailyCells = row.querySelectorAll('.daily-qty');
    const rate = parseFloat(row.querySelector('.sale-qty-input').dataset.rate);
    const dailySales = distributeSales(totalQty, daysCount);
    
    dailyCells.forEach((cell, index) => {
      cell.textContent = dailySales[index];
    });
    
    // Update item total
    const itemTotal = row.querySelector('.item-total');
    itemTotal.textContent = '₹' + (totalQty * rate).toFixed(2);
    
    // Update closing balance
    const currentStock = parseFloat(row.querySelector('.sale-qty-input').dataset.stock);
    const closingBalance = currentStock - totalQty;
    row.querySelector('.closing-balance').textContent = closingBalance.toFixed(3);
  }
  
  // Update all totals
  function updateTotals() {
    let totalQty = 0;
    let totalAmount = 0;
    
    itemRows.forEach(row => {
      const qtyInput = row.querySelector('.sale-qty-input');
      const rate = parseFloat(qtyInput.dataset.rate);
      const qty = parseInt(qtyInput.value) || 0;
      
      totalQty += qty;
      totalAmount += qty * rate;
    });
    
    totalQtyEl.textContent = totalQty;
    totalAmountEl.textContent = '₹' + totalAmount.toFixed(2);
    
    // Show/hide action buttons based on whether there are quantities
    if (totalQty > 0) {
      shuffleBtn.style.display = 'inline-block';
      generateBillsBtn.style.display = 'inline-block';
    } else {
      shuffleBtn.style.display = 'none';
      generateBillsBtn.style.display = 'none';
    }
  }
  
  // Event listener for quantity inputs
  tbody.addEventListener('input', function(e) {
    if (e.target.classList.contains('sale-qty-input')) {
      const itemCode = e.target.closest('.item-row').dataset.code;
      const totalQty = parseInt(e.target.value) || 0;
      
      // Validate against current stock
      const currentStock = parseFloat(e.target.dataset.stock);
      if (totalQty > currentStock) {
        alert(`Quantity cannot exceed current stock of ${currentStock}`);
        e.target.value = Math.min(totalQty, currentStock);
        return;
      }
      
      updateItemDistribution(itemCode, totalQty);
      updateTotals();
    }
  });
  
  // Event listener for individual shuffle buttons
  tbody.addEventListener('click', function(e) {
    if (e.target.closest('.shuffle-btn')) {
      const btn = e.target.closest('.shuffle-btn');
      const itemCode = btn.dataset.code;
      const row = btn.closest('.item-row');
      const qtyInput = row.querySelector('.sale-qty-input');
      const totalQty = parseInt(qtyInput.value) || 0;
      
      if (totalQty > 0) {
        updateItemDistribution(itemCode, totalQty);
        updateTotals();
      }
    }
  });
  
  // Shuffle all button
  shuffleBtn.addEventListener('click', function() {
    itemRows.forEach(row => {
      const qtyInput = row.querySelector('.sale-qty-input');
      const totalQty = parseInt(qtyInput.value) || 0;
      
      if (totalQty > 0) {
        const itemCode = row.dataset.code;
        updateItemDistribution(itemCode, totalQty);
      }
    });
  });
  
  // Generate bills button
  generateBillsBtn.addEventListener('click', function() {
    // This is just a visual indicator, the actual bill generation happens on form submit
    alert('Bills will be generated and split based on volume limits when you click "Update Sales".');
  });
  
  // Apply sale module to main table
  document.getElementById('applySaleModule').addEventListener('click', function() {
    const saleModuleInputs = document.querySelectorAll('#saleModuleModal input[name^="sale_module_qty"]');
    
    saleModuleInputs.forEach(input => {
      const nameParts = input.name.match(/sale_module_qty\[([^\]]+)\]\[(\d+)\]/);
      if (!nameParts) return;
      
      const itemGroup = nameParts[1];
      const size = parseInt(nameParts[2]);
      const qty = parseInt(input.value) || 0;
      
      if (qty > 0) {
        // Find matching rows in the main table
        const matchingRows = document.querySelectorAll(`.item-row[data-size="${size}"]`);
        
        matchingRows.forEach(row => {
          const itemCode = row.dataset.code;
          if (itemCode.includes(itemGroup)) {
            const qtyInput = row.querySelector('.sale-qty-input');
            const currentQty = parseInt(qtyInput.value) || 0;
            qtyInput.value = currentQty + qty;
            
            // Trigger update
            const event = new Event('input', { bubbles: true });
            qtyInput.dispatchEvent(event);
          }
        });
      }
    });
    
    // Close the modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('saleModuleModal'));
    modal.hide();
  });
  
  // Initialize totals
  updateTotals();
  
  // Highlight row on hover
  tbody.addEventListener('mouseover', function(e) {
    const row = e.target.closest('.item-row');
    if (row) {
      row.classList.add('highlight-row');
    }
  });
  
  tbody.addEventListener('mouseout', function(e) {
    const row = e.target.closest('.item-row');
    if (row) {
      row.classList.remove('highlight-row');
    }
  });
});
</script>
</body>
</html>