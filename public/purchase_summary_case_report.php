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

// Get financial year from session
$fin_year_id = $_SESSION['FIN_YEAR_ID'];

// Get financial year details - CORRECTED TABLE NAME
$fin_year_query = "SELECT START_DATE as StartDate, END_DATE as EndDate FROM tblfinyear WHERE ID = ?";
$fin_year_stmt = $conn->prepare($fin_year_query);
$fin_year_stmt->bind_param("i", $fin_year_id);
$fin_year_stmt->execute();
$fin_year_result = $fin_year_stmt->get_result();
$fin_year = $fin_year_result->fetch_assoc();
$fin_year_stmt->close();

// Set default dates to financial year if not specified
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : $fin_year['StartDate'];
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : $fin_year['EndDate'];

// Format dates for display
$from_date_display = date('d-M-Y', strtotime($from_date));
$to_date_display = date('d-M-Y', strtotime($to_date));

$compID = $_SESSION['CompID'];

// Get company name from session or set default
$companyName = isset($_SESSION['company_name']) ? $_SESSION['company_name'] : "Company Name";

// Build query to get monthly purchase data
$query = "SELECT 
            MONTH(p.DATE) as month_num,
            YEAR(p.DATE) as year_num,
            DATE_FORMAT(p.DATE, '%M') as month_name,
            SUM(pd.Cases) as total_cases,
            SUM(pd.Amount) as total_amount
          FROM tblpurchases p
          INNER JOIN tblpurchasedetails pd ON p.ID = pd.PurchaseID
          WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?
          GROUP BY YEAR(p.DATE), MONTH(p.DATE)
          ORDER BY YEAR(p.DATE), MONTH(p.DATE)";

$params = [$from_date, $to_date, $compID];
$types = "ssi";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$purchase_data = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate totals
$totals = [
    'total_cases' => 0,
    'total_amount' => 0
];

foreach ($purchase_data as $data) {
    $totals['total_cases'] += floatval($data['total_cases']);
    $totals['total_amount'] += floatval($data['total_amount']);
}

// Get current date and time for footer
$current_date_time = date('d-M-Y h:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Summary Case Report - WineSoft</title>
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
      <h3 class="mb-4">Purchase Summary Case Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Date From:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date To:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
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
      <?php if (isset($_GET['from_date']) || isset($_GET['to_date'])): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Purchase Summary [Cases] For <?= date('Y', strtotime($fin_year['StartDate'])) ?> - <?= date('Y', strtotime($fin_year['EndDate'])) ?></h5>
            <p class="text-muted">Financial Year: <?= date('d/m/Y', strtotime($fin_year['StartDate'])) ?> To <?= date('d/m/Y', strtotime($fin_year['EndDate'])) ?></p>
          </div>
          
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Month</th>
                  <th class="text-right">Cases</th>
                  <th class="text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($purchase_data)): ?>
                  <?php foreach ($purchase_data as $data): ?>
                    <tr>
                      <td><?= htmlspecialchars($data['month_name']) ?></td>
                      <td class="text-right"><?= number_format($data['total_cases'], 0) ?></td>
                      <td class="text-right"><?= number_format($data['total_amount'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="total-row">
                    <td class="text-end"><strong>Total:</strong></td>
                    <td class="text-right"><strong><?= number_format($totals['total_cases'], 0) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['total_amount'], 2) ?></strong></td>
                  </tr>
                <?php else: ?>
                  <tr>
                    <td colspan="3" class="text-center text-muted">No purchase data found for the selected period.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          
          <div class="footer-info">
            <p>S. S. SoftTech, Pune. (020-30224741, 9371251623, 9657860662)</p>
            <p>Printed on: <?= date('d-M-Y h:i A') ?></p>
          </div>
        </div>
      <?php elseif (isset($_GET['from_date']) && empty($purchase_data)): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No purchase data found for the selected criteria.
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