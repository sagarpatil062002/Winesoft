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

// Initialize variables
$breakages = [];
$totalRecords = 0;
$searchParams = [];

// Process search/filter parameters
$searchBreakageNo = isset($_GET['breakage_no']) ? trim($_GET['breakage_no']) : '';
$searchItemCode = isset($_GET['item_code']) ? trim($_GET['item_code']) : '';
$searchFromDate = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$searchToDate = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';

// Build the query with filters
$query = "SELECT 
            BRK_No, 
            BRK_Date, 
            Code, 
            Item_Desc, 
            Rate, 
            BRK_Qty, 
            Amount,
            Created_At
          FROM tblBreakages 
          WHERE CompID = ?";
$types = "i";
$params = [$_SESSION['CompID']];

// Add search filters
if (!empty($searchBreakageNo)) {
    $query .= " AND BRK_No = ?";
    $types .= "i";
    $params[] = (int)$searchBreakageNo;
    $searchParams['breakage_no'] = $searchBreakageNo;
}

if (!empty($searchItemCode)) {
    $query .= " AND Code LIKE ?";
    $types .= "s";
    $params[] = "%$searchItemCode%";
    $searchParams['item_code'] = $searchItemCode;
}

if (!empty($searchFromDate)) {
    $query .= " AND DATE(BRK_Date) >= ?";
    $types .= "s";
    $params[] = $searchFromDate;
    $searchParams['from_date'] = $searchFromDate;
}

if (!empty($searchToDate)) {
    $query .= " AND DATE(BRK_Date) <= ?";
    $types .= "s";
    $params[] = $searchToDate;
    $searchParams['to_date'] = $searchToDate;
}

$query .= " ORDER BY BRK_Date DESC, BRK_No DESC";

// Prepare and execute the query
$stmt = $conn->prepare($query);

if ($stmt) {
    // Bind parameters dynamically
    if (count($params) > 0) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result) {
        $breakages = $result->fetch_all(MYSQLI_ASSOC);
        $totalRecords = count($breakages);
    }
    
    $stmt->close();
}

// Calculate total amount
$totalAmount = 0;
foreach ($breakages as $breakage) {
    $totalAmount += $breakage['Amount'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Breakage Records - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4><i class="fa-solid fa-list-check me-2"></i>Breakage Records</h4>
        <a href="breakages.php" class="btn btn-danger">
          <i class="fas fa-plus-circle me-1"></i> New Breakage
        </a>
      </div>

      <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> Breakage recorded successfully! Breakage No: <?= htmlspecialchars($_GET['breakage_no']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Search and Filter Card -->
      <div class="card search-card mb-4">
        <div class="card-header breakage-header fw-semibold">
          <i class="fa-solid fa-magnifying-glass me-2"></i>Search & Filter
        </div>
        <div class="card-body">
          <form method="GET" action="">
            <div class="row g-3">
              <div class="col-md-3">
                <label for="breakage_no" class="form-label">Breakage No</label>
                <input type="number" class="form-control" id="breakage_no" name="breakage_no" 
                       value="<?= htmlspecialchars($searchBreakageNo) ?>" min="1">
              </div>
              <div class="col-md-3">
                <label for="item_code" class="form-label">Item Code</label>
                <input type="text" class="form-control" id="item_code" name="item_code" 
                       value="<?= htmlspecialchars($searchItemCode) ?>">
              </div>
              <div class="col-md-3">
                <label for="from_date" class="form-label">From Date</label>
                <input type="date" class="form-control" id="from_date" name="from_date" 
                       value="<?= htmlspecialchars($searchFromDate) ?>">
              </div>
              <div class="col-md-3">
                <label for="to_date" class="form-label">To Date</label>
                <input type="date" class="form-control" id="to_date" name="to_date" 
                       value="<?= htmlspecialchars($searchToDate) ?>">
              </div>
              <div class="col-12 d-flex justify-content-end gap-2">
                <a href="breakage_records.php" class="btn btn-secondary">Clear</a>
                <button type="submit" class="btn btn-danger">
                  <i class="fas fa-search me-1"></i> Search
                </button>
              </div>
            </div>
          </form>
          
          <!-- Active Filters -->
          <?php if (!empty($searchParams)): ?>
          <div class="mt-3">
            <small class="text-muted">Active filters:</small>
            <?php foreach ($searchParams as $key => $value): ?>
              <?php if (!empty($value)): ?>
                <span class="filter-badge">
                  <?php 
                    $filterNames = [
                      'breakage_no' => 'Breakage No',
                      'item_code' => 'Item Code', 
                      'from_date' => 'From Date',
                      'to_date' => 'To Date'
                    ];
                    echo $filterNames[$key] . ': ' . htmlspecialchars($value);
                  ?>
                  <a href="?<?php 
                    $newParams = $searchParams;
                    unset($newParams[$key]);
                    echo http_build_query($newParams);
                  ?>" class="close">&times;</a>
                </span>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Summary Card -->
      <div class="card summary-card mb-4">
        <div class="card-header fw-semibold">
          <i class="fa-solid fa-chart-pie me-2"></i>Summary
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-4">
              <div class="d-flex justify-content-between align-items-center p-3 bg-white rounded shadow-sm">
                <div>
                  <p class="text-muted mb-0">Total Records</p>
                  <h3 class="mb-0"><?= $totalRecords ?></h3>
                </div>
                <div class="icon-circle bg-primary text-white">
                  <i class="fas fa-list"></i>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="d-flex justify-content-between align-items-center p-3 bg-white rounded shadow-sm">
                <div>
                  <p class="text-muted mb-0">Total Amount</p>
                  <h3 class="mb-0">₹<?= number_format($totalAmount, 2) ?></h3>
                </div>
                <div class="icon-circle bg-success text-white">
                  <i class="fas fa-rupee-sign"></i>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="d-flex justify-content-between align-items-center p-3 bg-white rounded shadow-sm">
                <div>
                  <p class="text-muted mb-0">Average per Record</p>
                  <h3 class="mb-0">₹<?= $totalRecords > 0 ? number_format($totalAmount / $totalRecords, 2) : '0.00' ?></h3>
                </div>
                <div class="icon-circle bg-info text-white">
                  <i class="fas fa-calculator"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Breakage Records Table -->
      <div class="card">
        <div class="card-header breakage-header fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="fa-solid fa-table-list me-2"></i>Breakage Records</span>
          <span class="badge bg-danger rounded-pill"><?= $totalRecords ?> records</span>
        </div>
        <div class="card-body p-0">
          <?php if ($totalRecords > 0): ?>
            <div class="table-container">
              <table class="styled-table">
                <thead>
                  <tr>
                    <th>Breakage No</th>
                    <th>Date & Time</th>
                    <th>Item Code</th>
                    <th>Item Description</th>
                    <th class="text-end">Rate (₹)</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Amount (₹)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($breakages as $breakage): ?>
                    <tr>
                      <td>
                        <span class="badge badge-breakage bg-danger"><?= $breakage['BRK_No'] ?></span>
                      </td>
                      <td><?= date('d M Y, h:i A', strtotime($breakage['BRK_Date'])) ?></td>
                      <td><?= htmlspecialchars($breakage['Code']) ?></td>
                      <td><?= htmlspecialchars($breakage['Item_Desc']) ?></td>
                      <td class="text-end"><?= number_format($breakage['Rate'], 2) ?></td>
                      <td class="text-end"><?= $breakage['BRK_Qty'] ?></td>
                      <td class="text-end fw-bold"><?= number_format($breakage['Amount'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if ($totalRecords > 10): ?>
                    <tr class="table-danger">
                      <td colspan="6" class="text-end fw-bold">Total Amount:</td>
                      <td class="text-end fw-bold">₹<?= number_format($totalAmount, 2) ?></td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-center py-5">
              <i class="fa-solid fa-box-open fa-3x text-muted mb-3"></i>
              <h5 class="text-muted">No breakage records found</h5>
              <p class="text-muted">
                <?php if (!empty($searchParams)): ?>
                  Try adjusting your search filters
                <?php else: ?>
                  Start by <a href="breakages.php">recording a breakage</a>
                <?php endif; ?>
              </p>
            </div>
          <?php endif; ?>
        </div>
        <?php if ($totalRecords > 0): ?>
          <div class="card-footer d-flex justify-content-between align-items-center">
            <div>
              <span class="text-muted">Showing <?= $totalRecords ?> record<?= $totalRecords !== 1 ? 's' : '' ?></span>
            </div>
            <div>
              <span class="text-muted">Total: ₹<?= number_format($totalAmount, 2) ?></span>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
  // Set today's date as default for To Date
  if (!$('#to_date').val()) {
    var today = new Date();
    var dd = String(today.getDate()).padStart(2, '0');
    var mm = String(today.getMonth() + 1).padStart(2, '0');
    var yyyy = today.getFullYear();
    today = yyyy + '-' + mm + '-' + dd;
    $('#to_date').val(today);
  }
  
  // Set From Date to 30 days ago if not set
  if (!$('#from_date').val()) {
    var thirtyDaysAgo = new Date();
    thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
    var dd = String(thirtyDaysAgo.getDate()).padStart(2, '0');
    var mm = String(thirtyDaysAgo.getMonth() + 1).padStart(2, '0');
    var yyyy = thirtyDaysAgo.getFullYear();
    thirtyDaysAgo = yyyy + '-' + mm + '-' + dd;
    $('#from_date').val(thirtyDaysAgo);
  }
});
</script>
</body>
</html>