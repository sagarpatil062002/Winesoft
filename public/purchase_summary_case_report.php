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
  <style>
    .report-table th {
      background-color: #f8f9fa;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .total-row {
      font-weight: bold;
      background-color: #e9ecef;
    }
    .print-header {
      text-align: center;
      margin-bottom: 20px;
    }
    .print-footer {
      margin-top: 30px;
      font-size: 12px;
      text-align: center;
    }
    .company-name {
      font-size: 24px;
      font-weight: bold;
      text-transform: uppercase;
    }
    .report-title {
      font-size: 18px;
      font-weight: bold;
      margin-bottom: 10px;
    }
    .financial-year {
      font-size: 14px;
      margin-bottom: 15px;
    }
    @media print {
      .no-print {
        display: none !important;
      }
      .print-section {
        display: block !important;
      }
      body {
        font-size: 12px;
      }
      .report-table {
        font-size: 11px;
      }
      .print-header, .print-footer {
        display: block !important;
      }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <!-- Filters Section (Not Printable) -->
      <div class="report-filters no-print">
        <form method="GET" class="row g-3">
          <div class="col-md-2">
            <label class="form-label">From Date</label>
            <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">To Date</label>
            <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
          </div>
          <div class="col-md-12 mt-4">
            <div class="btn-group">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-sync-alt"></i> Generate
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print"></i> Print
              </button>
              <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-sign-out-alt"></i> Exit
              </a>
            </div>
          </div>
        </form>
      </div>

      <!-- Printable Report Section -->
      <div class="print-section">
        <div class="print-header">
          <div class="company-name"><?= htmlspecialchars($companyName) ?></div>
          <div class="report-title">Purchase Summary [Cases] For <?= date('Y', strtotime($fin_year['StartDate'])) ?> - <?= date('Y', strtotime($fin_year['EndDate'])) ?></div>
          <div class="financial-year">Financial Year: <?= date('d/m/Y', strtotime($fin_year['StartDate'])) ?> To <?= date('d/m/Y', strtotime($fin_year['EndDate'])) ?></div>
        </div>

        <!-- Report Table -->
        <div class="table-container">
          <table class="styled-table report-table">
            <thead>
              <tr>
                <th>Month</th>
                <th>Cases</th>
                <th>Amount</th>
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
                  <td class="text-center"><strong>Total</strong></td>
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

          </div>
    </div>
      <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>