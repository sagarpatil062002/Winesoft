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

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d', strtotime('-30 days'));
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$bill_no = isset($_GET['bill_no']) ? $_GET['bill_no'] : '';
$customer = isset($_GET['customer']) ? $_GET['customer'] : '';

// Format dates for display
$from_date_display = date('d-M-Y', strtotime($from_date));
$to_date_display = date('d-M-Y', strtotime($to_date));

// Build query to fetch sales data
$query = "SELECT 
            s.BillNo,
            s.BillDate,
            s.LCode,
            l.LHEAD as customer_name,
            COUNT(s.ItemCode) as item_count,
            SUM(s.Amount) as total_amount
          FROM tblcustomersales s
          LEFT JOIN tbllheads l ON s.LCode = l.LCODE
          WHERE s.CompID = ? AND s.BillDate BETWEEN ? AND ?";

$params = [$compID, $from_date, $to_date];
$types = "iss";

if (!empty($bill_no)) {
    $query .= " AND s.BillNo LIKE ?";
    $params[] = '%' . $bill_no . '%';
    $types .= "s";
}

if (!empty($customer)) {
    $query .= " AND l.LHEAD LIKE ?";
    $params[] = '%' . $customer . '%';
    $types .= "s";
}

$query .= " GROUP BY s.BillNo, s.BillDate, s.LCode, l.LHEAD
            ORDER BY s.BillDate DESC, s.BillNo DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$sales = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle delete action
if (isset($_GET['delete_bill'])) {
    $bill_no = $_GET['delete_bill'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete sales records
        $deleteQuery = "DELETE FROM tblcustomersales WHERE BillNo = ? AND CompID = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bind_param("ii", $bill_no, $compID);
        $deleteStmt->execute();
        
        if ($deleteStmt->affected_rows > 0) {
            $conn->commit();
            $_SESSION['success'] = "Bill #$bill_no deleted successfully!";
        } else {
            throw new Exception("No records found to delete");
        }
        
        $deleteStmt->close();
        
        // Redirect to avoid resubmission
        header("Location: customer_sales_view.php?" . http_build_query($_GET));
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error deleting bill: " . $e->getMessage();
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
  <title>Customer Sales View - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .table-container{overflow-x:auto;max-height:520px}
    table.styled-table{width:100%;border-collapse:collapse}
    .styled-table th,.styled-table td{border:1px solid #e5e7eb;padding:8px 10px}
    .styled-table thead th{position:sticky;top:0;background:#f8fafc;z-index:1}
    .action-buttons{display:flex;gap:5px}
    .status-badge{padding:4px 8px;border-radius:4px;font-size:0.8rem}
    .status-completed{background:#d1fae5;color:#065f46}
    .status-pending{background:#fef3c7;color:#92400e}
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Customer Sales View</h4>
        <a href="customer_sales.php" class="btn btn-primary">
          <i class="fa-solid fa-plus me-1"></i> New Sale
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

      <!-- Filter Section -->
      <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="fa-solid fa-filter me-2"></i>Filters</div>
        <div class="card-body">
          <form method="GET" class="row g-3">
            <div class="col-md-3">
              <label class="form-label">From Date</label>
              <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">To Date</label>
              <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Bill No.</label>
              <input type="text" class="form-control" name="bill_no" value="<?= htmlspecialchars($bill_no) ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Customer</label>
              <input type="text" class="form-control" name="customer" value="<?= htmlspecialchars($customer) ?>">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter me-1"></i> Apply Filters</button>
              <a href="customer_sales_view.php" class="btn btn-secondary"><i class="fa-solid fa-times me-1"></i> Clear</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Sales List -->
      <div class="card">
        <div class="card-header fw-semibold"><i class="fa-solid fa-list me-2"></i>Sales Records</div>
        <div class="card-body">
          <?php if (count($sales) > 0): ?>
            <div class="table-container">
              <table class="styled-table">
                <thead>
                  <tr>
                    <th>Bill No.</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($sales as $sale): ?>
                    <tr>
                      <td><?= htmlspecialchars($sale['BillNo']) ?></td>
                      <td><?= date('d-M-Y', strtotime($sale['BillDate'])) ?></td>
                      <td><?= htmlspecialchars($sale['customer_name']) ?></td>
                      <td><?= htmlspecialchars($sale['item_count']) ?></td>
                      <td>₹<?= number_format($sale['total_amount'], 2) ?></td>
                      <td>
                        <span class="status-badge status-completed">Completed</span>
                      </td>
                      <td>
                        
                          <!-- Edit Button -->
                          <a href="customer_sales_edit.php?bill_no=<?= $sale['BillNo'] ?>" 
                             class="btn btn-sm btn-warning" title="Edit">
                            <i class="fa-solid fa-edit"></i>
                          </a>
                          
                          <!-- Delete Button -->
                          <button class="btn btn-sm btn-danger" 
                                  title="Delete" 
                                  onclick="confirmDelete(<?= $sale['BillNo'] ?>)">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-center py-4">
              <i class="fa-solid fa-receipt fa-3x text-muted mb-3"></i>
              <h5 class="text-muted">No sales records found</h5>
              <p class="text-muted">Try adjusting your filters or create a new sale</p>
              <a href="customer_sales.php" class="btn btn-primary mt-2">
                <i class="fa-solid fa-plus me-1"></i> Create Sale
              </a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete this sale bill? This action cannot be undone.</p>
        <p class="text-danger"><strong>Warning:</strong> All items in this bill will be permanently deleted.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <a href="#" id="deleteConfirm" class="btn btn-danger">Delete</a>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmDelete(billNo) {
  // Build the delete URL with current filter parameters
  const params = new URLSearchParams(window.location.search);
  params.set('delete_bill', billNo);
  
  $('#deleteConfirm').attr('href', 'customer_sales_view.php?' + params.toString());
  $('#deleteModal').modal('show');
}

// Apply filters with date range validation
$('form').on('submit', function(e) {
  const fromDate = $('input[name="from_date"]').val();
  const toDate = $('input[name="to_date"]').val();
  
  if (fromDate && toDate && fromDate > toDate) {
    e.preventDefault();
    alert('From date cannot be greater than To date');
    return false;
  }
});
</script>
</body>
</html>