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
$customer_name = isset($_GET['customer_name']) ? $_GET['customer_name'] : '';
$report_mode = isset($_GET['report_mode']) ? $_GET['report_mode'] : 'detailed';

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

// Fetch customers for dropdown from tblcustomersales
$customers = [];
$customerQuery = "SELECT DISTINCT LCode FROM tblcustomersales WHERE CompID = ? AND LCode IS NOT NULL AND LCode != '' ORDER BY LCode";
$customerStmt = $conn->prepare($customerQuery);
$customerStmt->bind_param("i", $compID);
$customerStmt->execute();
$customerResult = $customerStmt->get_result();
while ($row = $customerResult->fetch_assoc()) {
    $customers[] = $row['LCode'];
}
$customerStmt->close();

$report_data = [];
$summary_data = [];
$grand_total = 0;

if (isset($_GET['generate'])) {
    if ($report_mode == 'detailed') {
        // Detailed Report - show individual transactions
        $sales_query = "SELECT 
            LCode as Customer,
            BillDate,
            BillNo,
            SUM(Amount) as TotalAmount
        FROM tblcustomersales 
        WHERE BillDate BETWEEN ? AND ? AND CompID = ?";
        
        $params = [$date_from, $date_to, $compID];
        $types = "ssi";
        
        if (!empty($customer_name)) {
            $sales_query .= " AND LCode = ?";
            $params[] = $customer_name;
            $types .= "s";
        }
        
        $sales_query .= " GROUP BY LCode, BillDate, BillNo ORDER BY LCode, BillDate, BillNo";
        
        $sales_stmt = $conn->prepare($sales_query);
        $sales_stmt->bind_param($types, ...$params);
        $sales_stmt->execute();
        $sales_result = $sales_stmt->get_result();
        
        while ($row = $sales_result->fetch_assoc()) {
            $report_data[] = $row;
            $grand_total += $row['TotalAmount'];
        }
        $sales_stmt->close();
    } else {
        // Summary Report - show customer-wise totals
        $summary_query = "SELECT 
            LCode as Customer,
            COUNT(DISTINCT BillNo) as OrderCount,
            SUM(Amount) as TotalAmount
        FROM tblcustomersales 
        WHERE BillDate BETWEEN ? AND ? AND CompID = ?";
        
        $params = [$date_from, $date_to, $compID];
        $types = "ssi";
        
        if (!empty($customer_name)) {
            $summary_query .= " AND LCode = ?";
            $params[] = $customer_name;
            $types .= "s";
        }
        
        $summary_query .= " GROUP BY LCode ORDER BY LCode";
        
        $summary_stmt = $conn->prepare($summary_query);
        $summary_stmt->bind_param($types, ...$params);
        $summary_stmt->execute();
        $summary_result = $summary_stmt->get_result();
        
        while ($row = $summary_result->fetch_assoc()) {
            $summary_data[] = $row;
            $grand_total += $row['TotalAmount'];
        }
        $summary_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Credit Sales Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <style>
    .sales-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    .sales-table th, .sales-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }
    .sales-table th {
        background-color: #f2f2f2;
        font-weight: bold;
    }
    .sales-table tr:nth-child(even) {
        background-color: #f9f9f9;
    }
    .sales-table .customer-row {
        background-color: #e9ecef;
        font-weight: bold;
    }
    .sales-table .total-row {
        background-color: #d4edda;
        font-weight: bold;
    }
    .sales-table .grand-total-row {
        background-color: #cce5ff;
        font-weight: bold;
        font-size: 1.1em;
    }
    .company-header {
        text-align: center;
        margin-bottom: 20px;
    }
    .customer-info {
        margin-bottom: 15px;
        font-weight: bold;
    }
    .date-range {
        margin-bottom: 15px;
    }
    .text-right {
        text-align: right;
    }
    .text-center {
        text-align: center;
    }
    .mode-selection {
        margin-bottom: 15px;
    }
    .mode-selection .form-check {
        display: inline-block;
        margin-right: 20px;
    }
    @media print {
        .no-print {
            display: none !important;
        }
        .company-header h1 {
            font-size: 24px;
        }
        .company-header h5 {
            font-size: 16px;
        }
        body {
            font-size: 12px;
        }
        .sales-table {
            font-size: 11px;
        }
        .print-section {
            margin: 0;
            padding: 0;
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
      <h3 class="mb-4">Credit Sales Report</h3>

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
              <div class="col-md-3">
                <label class="form-label">Customer:</label>
                <select name="customer_name" class="form-select">
                  <option value="">All Customers</option>
                  <?php foreach ($customers as $customer): ?>
                    <option value="<?= htmlspecialchars($customer) ?>" 
                      <?= $customer_name == $customer ? 'selected' : '' ?>>
                      <?= htmlspecialchars($customer) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            
            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label">Mode:</label>
                <div class="mode-selection">
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="report_mode" id="detailed" value="detailed" <?= $report_mode == 'detailed' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="detailed">Detailed</label>
                  </div>
                  <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="report_mode" id="summary" value="summary" <?= $report_mode == 'summary' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="summary">Summary</label>
                  </div>
                </div>
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
            <h5>Credit Sales Report From <?= date('d/m/Y', strtotime($date_from)) ?> To <?= date('d/m/Y', strtotime($date_to)) ?></h5>
            <div class="customer-info">
              Credit Customer Name: <?= empty($customer_name) ? 'All Customers' : htmlspecialchars($customer_name) ?>
            </div>
          </div>
          
          <div class="table-container">
            <?php if ($report_mode == 'detailed' && !empty($report_data)): ?>
              <!-- Detailed Report -->
              <table class="sales-table">
                <thead>
                  <tr>
                    <th>Customer</th>
                    <th>S.No.</th>
                    <th>Date</th>
                    <th>Order No.</th>
                    <th>Tot. Amt.</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $current_customer = '';
                  $customer_total = 0;
                  $serial_no = 0;
                  foreach ($report_data as $index => $row): 
                    if ($current_customer != $row['Customer']):
                      // Show customer total for previous customer
                      if ($current_customer != ''): ?>
                        <tr class="total-row">
                          <td colspan="4" class="text-right"><strong>Total for <?= htmlspecialchars($current_customer) ?>:</strong></td>
                          <td class="text-right"><strong><?= number_format($customer_total, 2) ?></strong></td>
                        </tr>
                      <?php endif;
                      
                      $current_customer = $row['Customer'];
                      $customer_total = 0;
                      $serial_no = 0;
                    endif;
                    
                    $serial_no++;
                    $customer_total += $row['TotalAmount'];
                  ?>
                    <tr>
                      <td><?= htmlspecialchars($current_customer) ?></td>
                      <td><?= $serial_no ?></td>
                      <td><?= date('d/m/Y', strtotime($row['BillDate'])) ?></td>
                      <td><?= htmlspecialchars($row['BillNo']) ?></td>
                      <td class="text-right"><?= number_format($row['TotalAmount'], 2) ?></td>
                    </tr>
                    
                    <?php if ($index == count($report_data) - 1): ?>
                      <!-- Last row - show final customer total -->
                      <tr class="total-row">
                        <td colspan="4" class="text-right"><strong>Total for <?= htmlspecialchars($current_customer) ?>:</strong></td>
                        <td class="text-right"><strong><?= number_format($customer_total, 2) ?></strong></td>
                      </tr>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  
                  <tr class="grand-total-row">
                    <td colspan="4" class="text-right"><strong>Grand Total:</strong></td>
                    <td class="text-right"><strong><?= number_format($grand_total, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
              
            <?php elseif ($report_mode == 'summary' && !empty($summary_data)): ?>
              <!-- Summary Report -->
              <table class="sales-table">
                <thead>
                  <tr>
                    <th>Customer</th>
                    <th>No. of Orders</th>
                    <th>Total Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($summary_data as $row): ?>
                    <tr>
                      <td><?= htmlspecialchars($row['Customer']) ?></td>
                      <td class="text-center"><?= $row['OrderCount'] ?></td>
                      <td class="text-right"><?= number_format($row['TotalAmount'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="grand-total-row">
                    <td class="text-right"><strong>Grand Total:</strong></td>
                    <td class="text-center"><strong><?= count($summary_data) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($grand_total, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
              
            <?php else: ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> No sales records found for the selected criteria.
              </div>
            <?php endif; ?>
            
            <div class="mt-3 text-end">
              <strong>Pages: Page 1 of 1</strong>
            </div>
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