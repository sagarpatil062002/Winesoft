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
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Fetch company name
$companyName = "DIAMOND WINE SHOP"; // Default name
$companyQuery = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
}
$companyStmt->close();

$report_data = [];
$total_amount = 0;

if (isset($_GET['generate'])) {
    // Build query to get cash memo summary data - using correct column names
    $query = "SELECT 
        sh.BILL_DATE,
        sh.BILL_NO,
        sh.CUST_CODE,
        sh.NET_AMOUNT
    FROM tblsaleheader sh
    WHERE sh.BILL_DATE BETWEEN ? AND ? AND sh.COMP_ID = ?
    ORDER BY sh.BILL_DATE, sh.BILL_NO";
    
    $params = [$date_from, $date_to, $compID];
    $types = "ssi";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $report_data[] = $row;
        $total_amount += (float)$row['NET_AMOUNT'];
    }
    
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cash Memo Summary Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
    <!-- Include shortcuts functionality -->
<script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

  
    <div class="content-area">
      <h3 class="mb-4">Cash Memo Summary Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Date From:</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date To:</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate
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
            <h5>Cash Memo Summary Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
          </div>
          
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th class="text-center">Bill Date</th>
                  <th class="text-center">Bill No</th>
                  <th class="text-center">Customer Code</th>
                  <th class="text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($report_data as $row): ?>
                  <tr>
                    <td class="text-center"><?= date('d/m/Y', strtotime($row['BILL_DATE'])) ?></td>
                    <td class="text-center"><?= htmlspecialchars($row['BILL_NO']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($row['CUST_CODE']) ?></td>
                    <td class="text-right"><?= number_format($row['NET_AMOUNT'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
                
                <tr class="total-row">
                  <td colspan="3" class="text-end"><strong>Total Amount :</strong></td>
                  <td class="text-right"><strong><?= number_format($total_amount, 2) ?></strong></td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <div class="footer-info">
            <p>S. S. SoftTech, Pune. (020-30224741, 9371251623, 9657860662)</p>
            <p>Printed on: <?= date('d-M-Y h:i A') ?></p>
          </div>
        </div>
      <?php elseif (isset($_GET['generate'])): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No cash memo records found for the selected criteria.
        </div>
      <?php endif; ?>
    </div>
    
  <?php include 'components/footer.php'; ?>
  </div>
  
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>