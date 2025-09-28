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
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-6 days'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$customer = isset($_GET['customer']) ? $_GET['customer'] : 'all';
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'summary';

// Fetch company name
$companyName = "DIAMOND WINE SHOP"; // Default
$companyQuery = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
}
$companyStmt->close();

// Fetch customers for dropdown
$customers = [];
$customerQuery = "SELECT LCODE, LHEAD FROM tbllheads WHERE CompID = ? AND LHEAD != '' ORDER BY LHEAD";
$customerStmt = $conn->prepare($customerQuery);
$customerStmt->bind_param("i", $compID);
$customerStmt->execute();
$customerResult = $customerStmt->get_result();
while ($row = $customerResult->fetch_assoc()) {
    $customers[$row['LCODE']] = $row['LHEAD'];
}
$customerStmt->close();

// Generate report data based on filters
$report_data = [];
$total_amount = 0;

if (isset($_GET['generate'])) {
    // Build the query to get credit sales data
    $credit_sales_query = "SELECT 
                cs.BillNo,
                cs.BillDate,
                cs.LCode,
                l.LHEAD as CustomerName,
                cs.ItemCode,
                cs.ItemName,
                cs.ItemSize,
                cs.Rate,
                cs.Quantity,
                cs.Amount,
                cs.CreatedDate
              FROM tblcustomersales cs
              INNER JOIN tbllheads l ON cs.LCode = l.LCODE
              WHERE cs.BillDate BETWEEN ? AND ? AND cs.CompID = ?";
    
    // Add customer filter if not "all"
    if ($customer != 'all') {
        $credit_sales_query .= " AND cs.LCode = ?";
    }
    
    $credit_sales_query .= " ORDER BY cs.BillDate, cs.BillNo";
    
    $stmt = $conn->prepare($credit_sales_query);
    
    if ($customer != 'all') {
        $stmt->bind_param("ssii", $date_from, $date_to, $compID, $customer);
    } else {
        $stmt->bind_param("ssi", $date_from, $date_to, $compID);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Organize credit sales data
    while ($row = $result->fetch_assoc()) {
        $bill_no = $row['BillNo'];
        $customer_name = $row['CustomerName'];
        
        if ($mode == 'summary') {
            // For summary mode, group by bill
            if (!isset($report_data[$bill_no])) {
                $report_data[$bill_no] = [
                    'BillDate' => $row['BillDate'],
                    'CustomerName' => $customer_name,
                    'TotalAmount' => 0
                ];
            }
            $report_data[$bill_no]['TotalAmount'] += $row['Amount'];
            $total_amount += $row['Amount'];
        } else {
            // For detailed mode, keep all items
            if (!isset($report_data[$bill_no])) {
                $report_data[$bill_no] = [
                    'BillDate' => $row['BillDate'],
                    'CustomerName' => $customer_name,
                    'items' => [],
                    'TotalAmount' => 0
                ];
            }
            $report_data[$bill_no]['items'][] = $row;
            $report_data[$bill_no]['TotalAmount'] += $row['Amount'];
            $total_amount += $row['Amount'];
        }
    }
    
    $stmt->close();
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
<script src="components/shortcuts.js?v=<?= time() ?>"></script>

</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

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
                <select name="customer" class="form-select">
                  <option value="all" <?= $customer == 'all' ? 'selected' : '' ?>>All Customers</option>
                  <?php foreach ($customers as $code => $name): ?>
                    <option value="<?= $code ?>" <?= $customer == $code ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Report Mode:</label>
                <select name="mode" class="form-select">
                  <option value="summary" <?= $mode == 'summary' ? 'selected' : '' ?>>Summary</option>
                  <option value="detailed" <?= $mode == 'detailed' ? 'selected' : '' ?>>Detailed</option>
                </select>
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
            <h5>Credit Sales Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
            <h5>Credit Customer Name: <?= $customer == 'all' ? 'All Customers' : htmlspecialchars($customers[$customer]) ?></h5>
          </div>
          
          <div class="table-container">
            <?php if ($mode == 'summary'): ?>
              <!-- Summary Report -->
              <table class="report-table">
                <thead>
                  <tr>
                    <th>S. No.</th>
                    <th>Date</th>
                    <th>Order No.</th>
                    <th>Customer</th>
                    <th>Tot. Amt.</th>
                  </tr>
                </thead>
                <tbody>
                  <?php $sno = 1; ?>
                  <?php foreach ($report_data as $bill_no => $bill_data): ?>
                  <tr>
                    <td class="text-center"><?= $sno++ ?></td>
                    <td><?= date('d/m/Y', strtotime($bill_data['BillDate'])) ?></td>
                    <td class="text-center"><?= $bill_no ?></td>
                    <td><?= htmlspecialchars($bill_data['CustomerName']) ?></td>
                    <td class="text-right"><?= number_format($bill_data['TotalAmount'], 2) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <tr class="total-row">
                    <td colspan="4" class="text-end">Total:</td>
                    <td class="text-right"><?= number_format($total_amount, 2) ?></td>
                  </tr>
                </tbody>
              </table>
            <?php else: ?>
              <!-- Detailed Report -->
              <?php foreach ($report_data as $bill_no => $bill_data): ?>
              <h6>Bill No: <?= $bill_no ?> | Date: <?= date('d/m/Y', strtotime($bill_data['BillDate'])) ?> | Customer: <?= htmlspecialchars($bill_data['CustomerName']) ?></h6>
              <table class="report-table">
                <thead>
                  <tr>
                    <th>Item Code</th>
                    <th>Item Description</th>
                    <th>Category</th>
                    <th>Rate</th>
                    <th>Qty</th>
                    <th>Tot. Amt.</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($bill_data['items'] as $item): ?>
                  <tr>
                    <td><?= htmlspecialchars($item['ItemCode']) ?></td>
                    <td><?= htmlspecialchars($item['ItemName']) ?></td>
                    <td><?= htmlspecialchars($item['ItemSize']) ?></td>
                    <td class="text-right"><?= number_format($item['Rate'], 2) ?></td>
                    <td class="text-right"><?= $item['Quantity'] ?></td>
                    <td class="text-right"><?= number_format($item['Amount'], 2) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <tr class="total-row">
                    <td colspan="5" class="text-end">Bill Total:</td>
                    <td class="text-right"><?= number_format($bill_data['TotalAmount'], 2) ?></td>
                  </tr>
                </tbody>
              </table>
              <?php endforeach; ?>
              
              <!-- Grand Total -->
              <table class="report-table">
                <tr class="total-row">
                  <td colspan="5" class="text-end">Grand Total:</td>
                  <td class="text-right"><?= number_format($total_amount, 2) ?></td>
                </tr>
              </table>
            <?php endif; ?>
            
          </div>
        </div>
      <?php elseif (isset($_GET['generate'])): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No credit sales records found for the selected criteria.
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