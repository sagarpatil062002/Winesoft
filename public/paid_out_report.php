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
    // Build query for paid out expenses
    $expense_query = "SELECT 
        DATE(VDATE) as VoucherDate,
        PARTI as Description,
        AMOUNT as Amount,
        VNO as VoucherNo,
        INV_NO as InvoiceNo,
        CHEQ_NO as ChequeNo,
        CHEQ_DT as ChequeDate,
        NARR as Narration
    FROM tblexpenses 
    WHERE DATE(VDATE) BETWEEN ? AND ? 
    AND COMP_ID = ? 
    AND DRCR = 'D' 
    AND MODE = 'C'
    ORDER BY VDATE, VNO";

    $expense_stmt = $conn->prepare($expense_query);
    $expense_stmt->bind_param("ssi", $date_from, $date_to, $compID);
    $expense_stmt->execute();
    $expense_result = $expense_stmt->get_result();
    
    $serial_no = 1;
    while ($row = $expense_result->fetch_assoc()) {
        $report_data[] = [
            'VoucherDate' => $row['VoucherDate'],
            'SerialNo' => $serial_no++,
            'Description' => $row['Description'],
            'Amount' => $row['Amount'],
            'VoucherNo' => $row['VoucherNo'],
            'InvoiceNo' => $row['InvoiceNo'],
            'ChequeNo' => $row['ChequeNo'],
            'ChequeDate' => $row['ChequeDate'],
            'Narration' => $row['Narration']
        ];
        $total_amount += $row['Amount'];
    }
    
    $expense_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Paid Out Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <style>
    .paidout-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
        font-size: 14px;
    }
    .paidout-table th, .paidout-table td {
        border: 1px solid #000;
        padding: 6px 8px;
        text-align: left;
    }
    .paidout-table th {
        background-color: #f0f0f0;
        font-weight: bold;
        text-align: center;
    }
    .paidout-table .total-row {
        background-color: #e9ecef;
        font-weight: bold;
    }
    .company-header {
        text-align: center;
        margin-bottom: 20px;
    }
    .company-header h1 {
        font-size: 24px;
        font-weight: bold;
        margin-bottom: 5px;
    }
    .company-header h5 {
        font-size: 16px;
        margin-bottom: 10px;
    }
    .text-right {
        text-align: right;
    }
    .text-center {
        text-align: center;
    }
    @media print {
        .no-print {
            display: none !important;
        }
        .company-header h1 {
            font-size: 20px;
        }
        .company-header h5 {
            font-size: 14px;
        }
        body {
            font-size: 12px;
            margin: 0;
            padding: 10px;
        }
        .paidout-table {
            font-size: 11px;
        }
        .content-area {
            padding: 0 !important;
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
      <h3 class="mb-4">Paid Out Report</h3>

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
      <?php if (isset($_GET['generate'])): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Paid Out Report From <?= date('d/m/Y', strtotime($date_from)) ?> To <?= date('d/m/Y', strtotime($date_to)) ?></h5>
          </div>
          
          <div class="table-container">
            <?php if (!empty($report_data)): ?>
              <table class="paidout-table">
                <thead>
                  <tr>
                    <th width="15%">V. Date</th>
                    <th width="10%">S. No.</th>
                    <th width="50%">Description</th>
                    <th width="25%">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $prev_date = null;
                  foreach ($report_data as $row): 
                    $current_date = $row['VoucherDate'];
                  ?>
                    <tr>
                      <td class="text-center">
                        <?= ($current_date != $prev_date) ? date('d/m/Y', strtotime($current_date)) : '' ?>
                      </td>
                      <td class="text-center"><?= $row['SerialNo'] ?></td>
                      <td><?= htmlspecialchars($row['Description']) ?></td>
                      <td class="text-right"><?= number_format($row['Amount'], 2) ?></td>
                    </tr>
                    <?php $prev_date = $current_date; ?>
                  <?php endforeach; ?>
                  <tr class="total-row">
                    <td colspan="3" class="text-right"><strong>Total:</strong></td>
                    <td class="text-right"><strong><?= number_format($total_amount, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
              <div class="mt-3 text-end">
                <strong>Pages: Page 1 of 1</strong>
              </div>
            <?php else: ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> No paid out records found for the selected criteria.
              </div>
            <?php endif; ?>
          </div>
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