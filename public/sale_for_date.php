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

// Date selection (default to today)
$sale_date = isset($_GET['sale_date']) ? $_GET['sale_date'] : date('Y-m-d');

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
    $sale_date = $_POST['sale_date'];
    $comp_id = $_SESSION['CompID'];
    $user_id = $_SESSION['user_id'];
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    
    // Generate a unique bill number
    $bill_no = "BL" . date('YmdHis');
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert sale header
        $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                         VALUES (?, ?, 0, 0, 0, ?, ?, ?)";
        $header_stmt = $conn->prepare($header_query);
        $header_stmt->bind_param("sssii", $bill_no, $sale_date, $mode, $comp_id, $user_id);
        $header_stmt->execute();
        $header_stmt->close();
        
        $total_amount = 0;
        
        // Process each item sale
        foreach ($_POST['sale_qty'] as $item_code => $qty) {
            $qty = floatval($qty);
            
            if ($qty > 0) {
                // Get item details
                $item_query = "SELECT RPRICE FROM tblitemmaster WHERE CODE = ?";
                $item_stmt = $conn->prepare($item_query);
                $item_stmt->bind_param("s", $item_code);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item_data = $item_result->fetch_assoc();
                $item_stmt->close();
                
                $rate = $item_data['RPRICE'];
                $amount = $qty * $rate;
                $total_amount += $amount;
                
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
            }
        }
        
        // Update sale header with total amount
        $update_header_query = "UPDATE tblsaleheader SET TOTAL_AMOUNT = ?, NET_AMOUNT = ? WHERE BILL_NO = ?";
        $update_header_stmt = $conn->prepare($update_header_query);
        $update_header_stmt->bind_param("dds", $total_amount, $total_amount, $bill_no);
        $update_header_stmt->execute();
        $update_header_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $success_message = "Sales updated successfully! Bill No: $bill_no";
        
        // Refresh the page to show updated stock values
        header("Location: retail_sale.php?mode=$mode&sequence_type=$sequence_type&sale_date=" . urlencode($sale_date) . "&success=" . urlencode($success_message));
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
  <title>Sales (For Date) - WineSoft</title>
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
    .bill-preview {
      background-color: #f8f9fa;
      border-radius: 6px;
      padding: 1.5rem;
      margin-top: 2rem;
      display: none;
    }
    .bill-header {
      text-align: center;
      margin-bottom: 1.5rem;
      border-bottom: 2px dashed #ccc;
      padding-bottom: 1rem;
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
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Sales (For Date)</h3>

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
          <a href="?mode=F&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>

      <!-- Sequence Type Selector -->
      <div class="mb-3">
        <label class="form-label">Sequence Type:</label>
        <div class="btn-group" role="group">
          <a href="?mode=<?= $mode ?>&sequence_type=user_defined&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>"
             class="btn btn-outline-primary <?= $sequence_type === 'user_defined' ? 'sequence-active' : '' ?>">
            User Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=system_defined&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>"
             class="btn btn-outline-primary <?= $sequence_type === 'system_defined' ? 'sequence-active' : '' ?>">
            System Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=group_defined&search=<?= urlencode($search) ?>&sale_date=<?= $sale_date ?>"
             class="btn btn-outline-primary <?= $sequence_type === 'group_defined' ? 'sequence-active' : '' ?>">
            Group Defined
          </a>
        </div>
      </div>

      <!-- Search and Date Selection -->
      <div class="row mb-3">
        <div class="col-md-6">
          <form method="GET" class="search-control">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
            <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
            <input type="hidden" name="sale_date" value="<?= htmlspecialchars($sale_date); ?>">
            <div class="input-group">
              <input type="text" name="search" class="form-control"
                     placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
              <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
              <?php if ($search !== ''): ?>
                <a href="?mode=<?= $mode ?>&sequence_type=<?= $sequence_type ?>&sale_date=<?= $sale_date ?>" class="btn btn-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
        <div class="col-md-6">
          <form method="GET" class="date-selector">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
            <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>">
            <div class="input-group">
              <label class="input-group-text" for="sale_date">Sale Date</label>
              <input type="date" name="sale_date" class="form-control" 
                     value="<?= htmlspecialchars($sale_date); ?>" required>
              <button type="submit" class="btn btn-primary">Go</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Sales Form -->
      <form method="POST" id="salesForm">
        <input type="hidden" name="sale_date" value="<?= htmlspecialchars($sale_date); ?>">
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
                <th>Sale Qty</th>
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
            <i class="fas fa-save"></i> Update Sales
          </button>
          <button type="button" class="btn btn-primary" onclick="generateBill()">
            <i class="fas fa-receipt"></i> Generate Bill
          </button>
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>
      </form>

      <!-- Bill Preview -->
      <div class="bill-preview" id="billPreview">
        <div class="bill-header">
          <h4>DIAMOND WINE SHOP</h4>
          <p>Sales Bill</p>
          <p>Date: <?= date('d-M-Y', strtotime($sale_date)); ?></p>
        </div>
        
        <div class="bill-items">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Item</th>
                <th>Qty</th>
                <th>Rate</th>
                <th>Amount</th>
              </tr>
            </thead>
            <tbody id="billItems">
              <!-- Bill items will be populated by JavaScript -->
            </tbody>
            <tfoot>
              <tr>
                <td colspan="3" class="text-end"><strong>Total:</strong></td>
                <td><strong id="billTotal">0.00</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
        
        <div class="text-center mt-3">
          <button class="btn btn-sm btn-outline-secondary" onclick="window.print()">
            <i class="fas fa-print"></i> Print Bill
          </button>
        </div>
      </div>
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

// Generate bill preview
function generateBill() {
  const billItems = document.getElementById('billItems');
  billItems.innerHTML = '';
  let billTotal = 0;
  
  // Add items to bill preview
  document.querySelectorAll('tbody tr').forEach(row => {
    const qtyInput = row.querySelector('.qty-input');
    const qty = parseFloat(qtyInput.value) || 0;
    
    if (qty > 0) {
      const itemName = row.cells[1].textContent;
      const rate = parseFloat(row.cells[3].textContent);
      const amount = qty * rate;
      billTotal += amount;
      
      const billRow = document.createElement('tr');
      billRow.innerHTML = `
        <td>${itemName}</td>
        <td>${qty}</td>
        <td>${rate.toFixed(2)}</td>
        <td>${amount.toFixed(2)}</td>
      `;
      billItems.appendChild(billRow);
    }
  });
  
  // Update bill total
  document.getElementById('billTotal').textContent = billTotal.toFixed(2);
  
  // Show bill preview
  document.getElementById('billPreview').style.display = 'block';
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