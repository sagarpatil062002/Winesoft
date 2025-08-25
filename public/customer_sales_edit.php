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

// Get company ID from session
$compID = $_SESSION['CompID'];

// Check if bill number is provided
if (!isset($_GET['bill_no'])) {
    $_SESSION['error'] = "No bill number specified";
    header("Location: customer_sales_view.php");
    exit;
}

$bill_no = $_GET['bill_no'];

// Fetch the bill header information
$headerQuery = "SELECT 
                s.BillNo,
                s.BillDate,
                s.LCode,
                l.LHEAD as customer_name,
                SUM(s.Amount) as TotalAmount
            FROM tblcustomersales s
            LEFT JOIN tbllheads l ON s.LCode = l.LCODE
            WHERE s.CompID = ? AND s.BillNo = ?
            GROUP BY s.BillNo, s.BillDate, s.LCode, l.LHEAD";

$headerStmt = $conn->prepare($headerQuery);
$headerStmt->bind_param("ii", $compID, $bill_no);
$headerStmt->execute();
$headerResult = $headerStmt->get_result();

if ($headerResult->num_rows === 0) {
    $_SESSION['error'] = "Bill #$bill_no not found";
    header("Location: customer_sales_view.php");
    exit;
}

$billHeader = $headerResult->fetch_assoc();
$headerStmt->close();

// Fetch the bill items - using correct column names from your database
$itemsQuery = "SELECT 
                s.ItemCode,
                s.Quantity as Qty,
                s.Rate,
                s.Amount
            FROM tblcustomersales s
            WHERE s.CompID = ? AND s.BillNo = ?";

$itemsStmt = $conn->prepare($itemsQuery);
$itemsStmt->bind_param("ii", $compID, $bill_no);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$billItems = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// Handle form submission for updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete existing items for this bill
        $deleteQuery = "DELETE FROM tblcustomersales WHERE BillNo = ? AND CompID = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bind_param("ii", $bill_no, $compID);
        $deleteStmt->execute();
        $deleteStmt->close();
        
        // Insert updated items - using correct column names
        $itemCodes = $_POST['item_code'];
        $qtys = $_POST['qty'];
        $rates = $_POST['rate'];
        $amounts = $_POST['amount'];
        
        $insertQuery = "INSERT INTO tblcustomersales 
                        (CompID, BillNo, BillDate, LCode, ItemCode, Quantity, Rate, Amount) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insertStmt = $conn->prepare($insertQuery);
        
        $billDate = $billHeader['BillDate']; // Using original bill date
        $lCode = $billHeader['LCode'];
        
        $totalAmount = 0;
        
        for ($i = 0; $i < count($itemCodes); $i++) {
            $insertStmt->bind_param(
                "iissiidd", 
                $compID, 
                $bill_no, 
                $billDate, 
                $lCode, 
                $itemCodes[$i], 
                $qtys[$i], 
                $rates[$i], 
                $amounts[$i]
            );
            $insertStmt->execute();
            $totalAmount += $amounts[$i];
        }
        
        $insertStmt->close();
        
        $conn->commit();
        $_SESSION['success'] = "Bill #$bill_no updated successfully!";
        
        // Redirect to view page
        header("Location: customer_sales_view.php");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error updating bill: " . $e->getMessage();
        header("Location: customer_sales_edit.php?bill_no=" . $bill_no);
        exit;
    }
}

// Handle success/error messages
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error_message = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Customer Sale - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .item-row { margin-bottom: 10px; }
    .total-row { font-weight: bold; background-color: #f8f9fa; }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Edit Customer Sale - Bill #<?= $bill_no ?></h4>
        <a href="customer_sales_view.php" class="btn btn-secondary">
          <i class="fa-solid fa-arrow-left me-1"></i> Back to List
        </a>
      </div>

      <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> <?= $success_message ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-exclamation me-2"></i> <?= $error_message ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Bill Information -->
      <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="fa-solid fa-receipt me-2"></i>Bill Information</div>
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label class="form-label">Bill Number</label>
              <input type="text" class="form-control" value="<?= $bill_no ?>" disabled>
            </div>
            <div class="col-md-3">
              <label class="form-label">Bill Date</label>
              <input type="text" class="form-control" value="<?= date('d-M-Y', strtotime($billHeader['BillDate'])) ?>" disabled>
            </div>
            <div class="col-md-6">
              <label class="form-label">Customer</label>
              <input type="text" class="form-control" value="<?= htmlspecialchars($billHeader['customer_name']) ?>" disabled>
            </div>
          </div>
        </div>
      </div>

      <!-- Items Section -->
      <form method="POST" id="salesForm">
        <div class="card mb-4">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-list me-2"></i>Items</span>
            <button type="button" class="btn btn-sm btn-primary" id="addItem">
              <i class="fa-solid fa-plus me-1"></i> Add Item
            </button>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-bordered" id="itemsTable">
                <thead>
                  <tr>
                    <th width="40%">Item Code</th>
                    <th width="15%">Quantity</th>
                    <th width="15%">Rate</th>
                    <th width="15%">Amount</th>
                    <th width="15%">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($billItems as $index => $item): ?>
                    <tr class="item-row">
                      <td>
                        <input type="text" class="form-control" name="item_code[]" value="<?= htmlspecialchars($item['ItemCode']) ?>" required>
                      </td>
                      <td>
                        <input type="number" class="form-control qty" name="qty[]" step="0.01" value="<?= $item['Qty'] ?>" required>
                      </td>
                      <td>
                        <input type="number" class="form-control rate" name="rate[]" step="0.01" value="<?= $item['Rate'] ?>" required>
                      </td>
                      <td>
                        <input type="number" class="form-control amount" name="amount[]" step="0.01" value="<?= $item['Amount'] ?>" readonly>
                      </td>
                      <td>
                        <button type="button" class="btn btn-sm btn-danger remove-item">
                          <i class="fa-solid fa-trash"></i>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
                <tfoot>
                  <tr class="total-row">
                    <td colspan="3" class="text-end">Total Amount:</td>
                    <td id="totalAmount">₹<?= number_format($billHeader['TotalAmount'], 2) ?></td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <a href="customer_sales_view.php" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary">Update Bill</button>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
  // Add new item row
  $('#addItem').click(function() {
    const newRow = `
      <tr class="item-row">
        <td>
          <input type="text" class="form-control" name="item_code[]" value="" placeholder="Enter Item Code" required>
        </td>
        <td>
          <input type="number" class="form-control qty" name="qty[]" step="0.01" value="1" required>
        </td>
        <td>
          <input type="number" class="form-control rate" name="rate[]" step="0.01" value="0" required>
        </td>
        <td>
          <input type="number" class="form-control amount" name="amount[]" step="0.01" value="0" readonly>
        </td>
        <td>
          <button type="button" class="btn btn-sm btn-danger remove-item">
            <i class="fa-solid fa-trash"></i>
          </button>
        </td>
      </tr>
    `;
    $('#itemsTable tbody').append(newRow);
  });

  // Remove item row
  $(document).on('click', '.remove-item', function() {
    if ($('#itemsTable tbody tr').length > 1) {
      $(this).closest('tr').remove();
      calculateTotal();
    } else {
      alert('You must have at least one item in the bill.');
    }
  });

  // Calculate amount when quantity or rate changes
  $(document).on('input', '.qty, .rate', function() {
    const row = $(this).closest('tr');
    const qty = parseFloat(row.find('.qty').val()) || 0;
    const rate = parseFloat(row.find('.rate').val()) || 0;
    const amount = qty * rate;
    
    row.find('.amount').val(amount.toFixed(2));
    calculateTotal();
  });

  // Calculate total amount
  function calculateTotal() {
    let total = 0;
    $('.amount').each(function() {
      total += parseFloat($(this).val()) || 0;
    });
    $('#totalAmount').text('₹' + total.toFixed(2));
  }
});
</script>
</body>
</html>