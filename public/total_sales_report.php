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

// Default values
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'detailed';
$counter = isset($_GET['counter']) ? $_GET['counter'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Fetch counters (users) from users table - only those associated with the current company
$counters = [];
$counterQuery = "SELECT id, username 
                 FROM users 
                 WHERE company_id = ?
                 ORDER BY username";
$counterStmt = $conn->prepare($counterQuery);
$counterStmt->bind_param("i", $compID);
$counterStmt->execute();
$counterResult = $counterStmt->get_result();
while ($row = $counterResult->fetch_assoc()) {
    $counters[$row['id']] = $row['username'];
}
$counterStmt->close();

// Generate report data based on filters
$report_data = [];
$total_amount = 0;

if (isset($_GET['generate'])) {
    // Build the query based on selected filters
    if ($report_type === 'detailed') {
        $query = "SELECT 
                    cs.BillDate as DATE, 
                    cs.BillNo, 
                    cs.ItemName, 
                    cs.ItemSize, 
                    cs.Rate, 
                    cs.Quantity, 
                    cs.Amount,
                    lh.LHEAD as Customer_Name,
                    u.username as Counter_Name
                  FROM tblcustomersales cs
                  INNER JOIN tbllheads lh ON cs.LCode = lh.LCODE
                  LEFT JOIN users u ON cs.UserID = u.id
                  WHERE cs.BillDate BETWEEN ? AND ? AND cs.CompID = ?";
    } else {
        // Summary report - group by item and size
        $query = "SELECT 
                    cs.ItemName, 
                    cs.ItemSize, 
                    SUM(cs.Quantity) as TotalQty,
                    SUM(cs.Amount) as TotalAmount,
                    u.username as Counter_Name
                  FROM tblcustomersales cs
                  LEFT JOIN users u ON cs.UserID = u.id
                  WHERE cs.BillDate BETWEEN ? AND ? AND cs.CompID = ?";
    }
    
    $params = [$date_from, $date_to, $compID];
    $types = "ssi";
    
    // Add counter filter if not 'all'
    if ($counter !== 'all') {
        $query .= " AND cs.UserID = ?";
        $params[] = $counter;
        $types .= "i";
    }
    
    if ($report_type === 'detailed') {
        $query .= " ORDER BY cs.BillDate, cs.BillNo";
    } else {
        $query .= " GROUP BY cs.ItemName, cs.ItemSize, u.username
                    ORDER BY cs.ItemName";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $report_data = $result->fetch_all(MYSQLI_ASSOC);
    
    // Calculate total amount
    $total_query = "SELECT SUM(Amount) as Total_Amount 
                    FROM tblcustomersales 
                    WHERE BillDate BETWEEN ? AND ? AND CompID = ?";
    
    $total_params = [$date_from, $date_to, $compID];
    $total_types = "ssi";
    
    // Add counter filter if not 'all'
    if ($counter !== 'all') {
        $total_query .= " AND UserID = ?";
        $total_params[] = $counter;
        $total_types .= "i";
    }
    
    $total_stmt = $conn->prepare($total_query);
    $total_stmt->bind_param($total_types, ...$total_params);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result();
    $total_row = $total_result->fetch_assoc();
    $total_amount = $total_row['Total_Amount'] ?? 0;
    
    $stmt->close();
    $total_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Total Sales Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Total Sales Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Report Type:</label>
                <select name="report_type" class="form-select">
                  <option value="detailed" <?= $report_type === 'detailed' ? 'selected' : '' ?>>Detailed</option>
                  <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Summary</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Counter:</label>
                <select name="counter" class="form-select">
                  <option value="all" <?= $counter === 'all' ? 'selected' : '' ?>>All Counters</option>
                  <?php foreach ($counters as $id => $username): ?>
                    <option value="<?= $id ?>" <?= $counter == $id ? 'selected' : '' ?>>
                      <?= htmlspecialchars($username) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Date From:</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date To:</label>
                <input type="date" name['date_to'] class="form-control" value="<?= htmlspecialchars($date_to) ?>">
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate Report
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
              </button>
              <a href="dashboard.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-times me-1"></i> Exit
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Report Results -->
      <?php if (!empty($report_data)): ?>
        <div class="print-section">
          <div class="company-header">
                <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Total Sales Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
            <h6>Counter: <?= $counter === 'all' ? 'All Counters' : htmlspecialchars($counters[$counter] ?? 'Unknown') ?></h6>
          </div>
          
          <div class="table-container">
            <?php if ($report_type === 'detailed'): ?>
              <table class="report-table">
                <thead>
                  <tr>
                    <th>S. No</th>
                    <th>Item Description</th>
                    <th>Size</th>
                    <th>Rate</th>
                    <th>Qty</th>
                    <th>Tot. Amt.</th>
                    <th>Counter</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $sno = 1;
                  $current_bill = 0;
                  foreach ($report_data as $row): 
                    if ($current_bill != $row['BillNo']):
                      $current_bill = $row['BillNo'];
                  ?>
                    <tr class="bill-header">
                      <td colspan="7"><strong>Bill No:</strong> <?= htmlspecialchars($row['BillNo']) ?> | 
                          <strong>Date:</strong> <?= date('d/m/Y', strtotime($row['DATE'])) ?> | 
                          <strong>Customer:</strong> <?= htmlspecialchars($row['Customer_Name']) ?></td>
                    </tr>
                  <?php endif; ?>
                  
                  <tr>
                    <td><?= $sno++ ?></td>
                    <td><?= htmlspecialchars($row['ItemName']) ?></td>
                    <td><?= htmlspecialchars($row['ItemSize']) ?></td>
                    <td class="text-right"><?= number_format($row['Rate'], 2) ?></td>
                    <td class="text-right"><?= htmlspecialchars($row['Quantity']) ?></td>
                    <td class="text-right"><?= number_format($row['Amount'], 2) ?></td>
                    <td><?= htmlspecialchars($row['Counter_Name'] ?? 'N/A') ?></td>
                  </tr>
                  <?php endforeach; ?>
                  
                  <tr class="total-row">
                    <td colspan="6" class="text-end"><strong>Total Amount :</strong></td>
                    <td class="text-right"><strong><?= number_format($total_amount, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
            <?php else: ?>
              <!-- Summary Report -->
              <table class="report-table">
                <thead>
                  <tr>
                    <th>Item Description</th>
                    <th>Size</th>
                    <th>Qty</th>
                    <th>Tot. Amt.</th>
                    <th>Counter</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($report_data as $row): ?>
                  <tr>
                    <td><?= htmlspecialchars($row['ItemName']) ?></td>
                    <td><?= htmlspecialchars($row['ItemSize']) ?></td>
                    <td class="text-right"><?= htmlspecialchars($row['TotalQty']) ?></td>
                    <td class="text-right"><?= number_format($row['TotalAmount'], 2) ?></td>
                    <td><?= htmlspecialchars($row['Counter_Name'] ?? 'N/A') ?></td>
                  </tr>
                  <?php endforeach; ?>
                  
                  <tr class="total-row">
                    <td colspan="3" class="text-end"><strong>Total Amount :</strong></td>
                    <td class="text-right"><strong><?= number_format($total_amount, 2) ?></strong></td>
                    <td></td>
                  </tr>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
          
         
        </div>
      <?php elseif (isset($_GET['generate'])): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No sales records found for the selected criteria.
        </div>
      <?php endif; ?>
    </div>
  </div>
  
  <?php include 'components/footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>