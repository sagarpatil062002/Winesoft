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
$stock_column = "CURRENT_STOCK" . $comp_id;

// Check if the stock column exists, if not create it
$check_column_query = "SHOW COLUMNS FROM tblitem_stock LIKE '$stock_column'";
$column_result = $conn->query($check_column_query);

if ($column_result->num_rows == 0) {
    // Column doesn't exist, create it
    $alter_query = "ALTER TABLE tblitem_stock ADD COLUMN $stock_column DECIMAL(10,3) DEFAULT 0.000";
    if ($conn->query($alter_query)) {
        // Column created successfully
    } else {
        die("Error creating stock column: " . $conn->error);
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
                 COALESCE(st.$stock_column, 0) as CURRENT_STOCK
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

// Handle form submission for sales update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_sales'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $comp_id = $_SESSION['CompID'];
    $user_id = $_SESSION['user_id'];
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    
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
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        $total_amount = 0;
        
        // Process each item sale
        foreach ($_POST['sale_qty'] as $item_code => $total_qty) {
            $total_qty = floatval($total_qty);
            
            if ($total_qty > 0) {
                // Get item details
                $item_query = "SELECT RPRICE FROM tblitemmaster WHERE CODE = ?";
                $item_stmt = $conn->prepare($item_query);
                $item_stmt->bind_param("s", $item_code);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item_data = $item_result->fetch_assoc();
                $item_stmt->close();
                
                $rate = $item_data['RPRICE'];
                
                // Distribute sales randomly across the date range
                $remaining_qty = $total_qty;
                $daily_sales = [];
                
                // Create a weighted distribution (more sales on some days)
                for ($i = 0; $i < $days_count; $i++) {
                    if ($i === $days_count - 1) {
                        // Last day gets the remaining quantity
                        $daily_sales[$i] = $remaining_qty;
                    } else {
                        // Random percentage between 5% and 30% of remaining quantity
                        $percent = mt_rand(5, 30) / 100;
                        $day_qty = round($remaining_qty * $percent, 3);
                        $daily_sales[$i] = $day_qty;
                        $remaining_qty -= $day_qty;
                        
                        if ($remaining_qty <= 0) break;
                    }
                }
                
                // Create sales for each day
                foreach ($daily_sales as $index => $qty) {
                    if ($qty <= 0) continue;
                    
                    $sale_date = $date_array[$index];
                    $amount = $qty * $rate;
                    
                    // Generate a unique bill number for each sale
                    $bill_no = "BL" . date('YmdHis') . "_" . $item_code . "_" . $index;
                    
                    // Insert sale header
                    $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                     VALUES (?, ?, 0, 0, 0, ?, ?, ?)";
                    $header_stmt = $conn->prepare($header_query);
                    $header_stmt->bind_param("sssii", $bill_no, $sale_date, $mode, $comp_id, $user_id);
                    $header_stmt->execute();
                    $header_stmt->close();
                    
                    // Insert sale details
                    $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $detail_stmt = $conn->prepare($detail_query);
                    $detail_stmt->bind_param("ssddssi", $bill_no, $item_code, $qty, $rate, $amount, $mode, $comp_id);
                    $detail_stmt->execute();
                    $detail_stmt->close();
                    
                    // Update sale header with total amount
                    $update_header_query = "UPDATE tblsaleheader SET TOTAL_AMOUNT = ?, NET_AMOUNT = ? WHERE BILL_NO = ?";
                    $update_header_stmt = $conn->prepare($update_header_query);
                    $update_header_stmt->bind_param("dds", $amount, $amount, $bill_no);
                    $update_header_stmt->execute();
                    $update_header_stmt->close();
                    
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
                        $stock_query = "UPDATE tblitem_stock SET $stock_column = $stock_column - ? WHERE ITEM_CODE = ?";
                        $stock_stmt = $conn->prepare($stock_query);
                        $stock_stmt->bind_param("ds", $qty, $item_code);
                        $stock_stmt->execute();
                        $stock_stmt->close();
                    } else {
                        // Insert new stock record
                        $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $stock_column) 
                                               VALUES (?, ?, ?)";
                        $insert_stock_stmt = $conn->prepare($insert_stock_query);
                        $current_stock = -$qty; // Negative since we're deducting
                        $insert_stock_stmt->bind_param("ssd", $item_code, $fin_year_id, $current_stock);
                        $insert_stock_stmt->execute();
                        $insert_stock_stmt->close();
                    }
                    
                    $total_amount += $amount;
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        $success_message = "Sales distributed successfully across the date range!";
        
        // Refresh the page to show updated stock values
        header("Location: sale_by_date_range.php?mode=$mode&sequence_type=$sequence_type&start_date=" . 
               urlencode($start_date) . "&end_date=" . urlencode($end_date) . "&success=" . urlencode($success_message));
        exit;
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = "Error updating sales: " . $e->getMessage();
    }
}

// Check for success message in URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
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
      max-height: 500px;
      overflow-y: auto;
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
                (<?= round((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1 ?> days)
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
        
        <!-- Items Table -->
        <div class="table-container">
          <table class="styled-table table-striped">
            <thead class="table-header">
              <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Rate (₹)</th>
                <th>Current Stock</th>
                <th>Total Sale Qty</th>
                <th>Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['CODE']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                  <td><?= number_format($item['RPRICE'], 2); ?></td>
                  <td>
                    <span class="stock-info"><?= number_format($item['CURRENT_STOCK'], 3); ?></span>
                  </td>
                  <td>
                    <input type="number" name="sale_qty[<?= htmlspecialchars($item['CODE']); ?>]" 
                           class="form-control qty-input" min="0" max="<?= $item['CURRENT_STOCK']; ?>" 
                           step="0.001" value="0" onchange="calculateAmount(this, <?= $item['RPRICE']; ?>)">
                  </td>
                  <td class="amount-cell" id="amount_<?= htmlspecialchars($item['CODE']); ?>">0.00</td>
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
                <td colspan="6" class="text-end"><strong>Total Amount:</strong></td>
                <td><strong id="totalAmount">0.00</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Action Buttons -->
        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-success">
            <i class="fas fa-save"></i> Distribute Sales
          </button>
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>
        
        <div class="alert alert-info mt-3">
          <i class="fas fa-info-circle"></i> 
          Total sales quantities will be randomly distributed across the selected date range.
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Calculate amount for each item
function calculateAmount(input, rate) {
  const qty = parseFloat(input.value) || 0;
  const amount = qty * rate;
  const itemCode = input.name.match(/\[(.*?)\]/)[1];
  
  document.getElementById(`amount_${itemCode}`).textContent = amount.toFixed(2);
  calculateTotal();
}

// Calculate total amount
function calculateTotal() {
  let total = 0;
  document.querySelectorAll('.amount-cell').forEach(cell => {
    total += parseFloat(cell.textContent) || 0;
  });
  
  document.getElementById('totalAmount').textContent = total.toFixed(2);
}

// Initialize calculations on page load
document.addEventListener('DOMContentLoaded', function() {
  // Add event listeners to all quantity inputs
  document.querySelectorAll('.qty-input').forEach(input => {
    const rate = parseFloat(input.closest('tr').cells[3].textContent);
    input.addEventListener('input', () => calculateAmount(input, rate));
  });
});
</script>
</body>
</html>