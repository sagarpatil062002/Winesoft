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

// Fetch customers for dropdown from both sales and expenses
$customers = [];
$customerQuery = "SELECT DISTINCT PARTI as customer_name FROM (
    SELECT DISTINCT LCode as PARTI FROM tblcustomersales WHERE CompID = ? AND LCode IS NOT NULL AND LCode != ''
    UNION 
    SELECT DISTINCT PARTI FROM tblexpenses WHERE COMP_ID = ? AND PARTI IS NOT NULL AND PARTI != ''
) AS customer_data ORDER BY customer_name";
$customerStmt = $conn->prepare($customerQuery);
$customerStmt->bind_param("ii", $compID, $compID);
$customerStmt->execute();
$customerResult = $customerStmt->get_result();
while ($row = $customerResult->fetch_assoc()) {
    $customers[] = $row['customer_name'];
}
$customerStmt->close();

$report_data = [];
$total_purchase_amount = 0;
$total_paid_amount = 0;
$pending_amount = 0;

if (isset($_GET['generate'])) {
    // Get purchase data from tblcustomersales
    $purchase_query = "SELECT 
        LCode as CustomerName,
        BillDate as VocDate,
        SUM(Amount) as PurchaseAmount,
        BillNo
    FROM tblcustomersales 
    WHERE BillDate BETWEEN ? AND ? AND CompID = ?";
    
    $params = [$date_from, $date_to, $compID];
    $types = "ssi";
    
    if (!empty($customer_name)) {
        $purchase_query .= " AND LCode = ?";
        $params[] = $customer_name;
        $types .= "s";
    }
    
    $purchase_query .= " GROUP BY LCode, BillDate, BillNo ORDER BY LCode, BillDate";
    
    $purchase_stmt = $conn->prepare($purchase_query);
    $purchase_stmt->bind_param($types, ...$params);
    $purchase_stmt->execute();
    $purchase_result = $purchase_stmt->get_result();
    
    $purchases = [];
    while ($row = $purchase_result->fetch_assoc()) {
        $purchases[] = $row;
        $total_purchase_amount += $row['PurchaseAmount'];
    }
    $purchase_stmt->close();
    
    // Get payment data from tblexpenses
    $payment_query = "SELECT 
        PARTI as CustomerName,
        VDATE as VocDate,
        SUM(AMOUNT) as PaidAmount
    FROM tblexpenses 
    WHERE VDATE BETWEEN ? AND ? AND COMP_ID = ? AND DRCR = 'D'";
    
    $payment_params = [$date_from, $date_to, $compID];
    $payment_types = "ssi";
    
    if (!empty($customer_name)) {
        $payment_query .= " AND PARTI = ?";
        $payment_params[] = $customer_name;
        $payment_types .= "s";
    }
    
    $payment_query .= " GROUP BY PARTI, VDATE ORDER BY PARTI, VDATE";
    
    $payment_stmt = $conn->prepare($payment_query);
    $payment_stmt->bind_param($payment_types, ...$payment_params);
    $payment_stmt->execute();
    $payment_result = $payment_stmt->get_result();
    
    $payments = [];
    while ($row = $payment_result->fetch_assoc()) {
        $payments[] = $row;
        $total_paid_amount += $row['PaidAmount'];
    }
    $payment_stmt->close();
    
    // Combine purchase and payment data
    $combined_data = [];
    
    // Add purchases
    foreach ($purchases as $purchase) {
        $key = $purchase['CustomerName'] . '_' . $purchase['VocDate'];
        if (!isset($combined_data[$key])) {
            $combined_data[$key] = [
                'CustomerName' => $purchase['CustomerName'],
                'VocDate' => $purchase['VocDate'],
                'PurchaseAmount' => $purchase['PurchaseAmount'],
                'PaidAmount' => 0
            ];
        } else {
            $combined_data[$key]['PurchaseAmount'] += $purchase['PurchaseAmount'];
        }
    }
    
    // Add payments
    foreach ($payments as $payment) {
        $key = $payment['CustomerName'] . '_' . $payment['VocDate'];
        if (!isset($combined_data[$key])) {
            $combined_data[$key] = [
                'CustomerName' => $payment['CustomerName'],
                'VocDate' => $payment['VocDate'],
                'PurchaseAmount' => 0,
                'PaidAmount' => $payment['PaidAmount']
            ];
        } else {
            $combined_data[$key]['PaidAmount'] += $payment['PaidAmount'];
        }
    }
    
    // Convert to indexed array and sort
    $report_data = array_values($combined_data);
    usort($report_data, function($a, $b) {
        return strcmp($a['CustomerName'], $b['CustomerName']) ?: strcmp($a['VocDate'], $b['VocDate']);
    });
    
    $pending_amount = $total_purchase_amount - $total_paid_amount;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Credit Customer Ledger Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <style>
    .ledger-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    .ledger-table th, .ledger-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }
    .ledger-table th {
        background-color: #f2f2f2;
        font-weight: bold;
    }
    .ledger-table tr:nth-child(even) {
        background-color: #f9f9f9;
    }
    .ledger-table .total-row {
        background-color: #e9ecef;
        font-weight: bold;
    }
    .ledger-table .pending-row {
        background-color: #fff3cd;
        font-weight: bold;
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
    .text-end {
        text-align: end;
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
        .ledger-table {
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
      <h3 class="mb-4">Credit Customer Ledger Report</h3>

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
            <h5>Credit Customer Ledger Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
            <?php if (!empty($customer_name)): ?>
              <div class="customer-info">
                Customer Name: <?= htmlspecialchars($customer_name) ?>
              </div>
            <?php endif; ?>
          </div>
          
          <div class="table-container">
            <?php if (!empty($report_data)): ?>
              <table class="ledger-table">
                <thead>
                  <tr>
                    <th>Customer Name</th>
                    <th>Voc. Date</th>
                    <th>Purch. Amount</th>
                    <th>Paid Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $current_customer = '';
                  foreach ($report_data as $row): 
                    if ($current_customer != $row['CustomerName']):
                      $current_customer = $row['CustomerName'];
                  ?>
                    <tr>
                      <td><strong><?= htmlspecialchars($current_customer) ?></strong></td>
                      <td><?= date('d-m-Y', strtotime($row['VocDate'])) ?></td>
                      <td class="text-right"><?= number_format($row['PurchaseAmount'], 2) ?></td>
                      <td class="text-right"><?= number_format($row['PaidAmount'], 2) ?></td>
                    </tr>
                  <?php else: ?>
                    <tr>
                      <td></td>
                      <td><?= date('d-m-Y', strtotime($row['VocDate'])) ?></td>
                      <td class="text-right"><?= number_format($row['PurchaseAmount'], 2) ?></td>
                      <td class="text-right"><?= number_format($row['PaidAmount'], 2) ?></td>
                    </tr>
                  <?php endif; ?>
                  <?php endforeach; ?>
                  <tr class="total-row">
                    <td colspan="2" class="text-end"><strong>Total:</strong></td>
                    <td class="text-right"><strong><?= number_format($total_purchase_amount, 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($total_paid_amount, 2) ?></strong></td>
                  </tr>
                  <tr class="pending-row">
                    <td colspan="3" class="text-end"><strong>Pending Amount:</strong></td>
                    <td class="text-right"><strong><?= number_format($pending_amount, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
              <div class="mt-3 text-end">
                <strong>Pages: Page 1 of 1</strong>
              </div>
            <?php else: ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> No ledger records found for the selected criteria.
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