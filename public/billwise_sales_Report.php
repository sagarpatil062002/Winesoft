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
$report_date = isset($_GET['report_date']) ? $_GET['report_date'] : date('Y-m-d');

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

// Generate report data based on filters
$report_data = [];
$overall_total = 0;
$prev_balance = 0;
$credit_sales = 0;
$expenses = 0;
$received = 0;
$discount = 0;
$total_cash = 0;

if (isset($_GET['generate'])) {
   // Build the query to get customer sales data
$customer_sales_query = "SELECT
            cs.BillNo,
            cs.BillDate,
            cs.LCode,
            l.LHEAD as CustomerName,
            cs.ItemCode,
            COALESCE(CASE WHEN im.Print_Name != '' THEN im.Print_Name ELSE im.DETAILS END, cs.ItemName) as ItemName,
            COALESCE(im.DETAILS2, cs.ItemSize) as ItemSize,
            cs.Rate,
            cs.Quantity,
            cs.Amount,
            cs.CreatedDate,
            u.username as UserName
          FROM tblcustomersales cs
          INNER JOIN tbllheads l ON cs.LCode = l.LCODE
          LEFT JOIN tblitemmaster im ON cs.ItemCode = im.CODE
          LEFT JOIN users u ON cs.UserID = u.id
          WHERE cs.BillDate = ? AND cs.CompID = ?
          ORDER BY cs.BillNo, cs.CreatedDate";
    
    $stmt = $conn->prepare($customer_sales_query);
    $stmt->bind_param("si", $report_date, $compID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Organize customer sales data by bill and user
    while ($row = $result->fetch_assoc()) {
        $bill_no = $row['BillNo'];
        $user_name = $row['UserName'] ? $row['UserName'] : 'Unknown';
        
        if (!isset($report_data[$bill_no])) {
            $report_data[$bill_no] = [
                'type' => 'customer',
                'customer' => $row['CustomerName'],
                'user' => $user_name,
                'items' => [],
                'total' => 0
            ];
        }
        
        $report_data[$bill_no]['items'][] = $row;
        $report_data[$bill_no]['total'] += $row['Amount'];
        
        $overall_total += $row['Amount'];
    }
    
    $stmt->close();
    
    // Get retail sales data with actual item details
    $retail_sales_query = "SELECT
                sh.BILL_NO as BillNo,
                sh.BILL_DATE as BillDate,
                'RETAIL SALE' as CustomerName,
                sd.ITEM_CODE as ItemCode,
                COALESCE(CASE WHEN im.Print_Name != '' THEN im.Print_Name ELSE im.DETAILS END, 'Unknown Item') as ItemName,
                COALESCE(im.DETAILS2, '') as ItemSize,
                sd.RATE as Rate,
                sd.QTY as Quantity,
                sd.AMOUNT as Amount,
                sh.CREATED_DATE as CreatedDate,
                u.username as UserName
            FROM tblsaleheader sh
            INNER JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
            LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
            LEFT JOIN users u ON sh.CREATED_BY = u.id
            WHERE sh.BILL_DATE = ? AND sh.COMP_ID = ?
            ORDER BY sh.BILL_NO, sh.CREATED_DATE";
    
    $stmt = $conn->prepare($retail_sales_query);
    $stmt->bind_param("si", $report_date, $compID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Organize retail sales data by bill and user
    while ($row = $result->fetch_assoc()) {
        $bill_no = $row['BillNo'];
        $user_name = $row['UserName'] ? $row['UserName'] : 'Unknown';
        
        if (!isset($report_data[$bill_no])) {
            $report_data[$bill_no] = [
                'type' => 'retail',
                'customer' => $row['CustomerName'],
                'user' => $user_name,
                'items' => [],
                'total' => 0
            ];
        }
        
        $report_data[$bill_no]['items'][] = $row;
        $report_data[$bill_no]['total'] += $row['Amount'];
        
        $overall_total += $row['Amount'];
    }
    
    $stmt->close();
    
    // Sort bills by bill number
    ksort($report_data);
    
    // For demo purposes, setting some values - these would normally come from database
    $credit_sales = $overall_total;
    $total_cash = 0; // Assuming no cash received yet
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Billwise Sales Report - WineSoft</title>
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
      <h3 class="mb-4">Billwise Sales Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Report Date:</label>
                <input type="date" name="report_date" class="form-control" value="<?= htmlspecialchars($report_date) ?>">
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
      <?php if (isset($_GET['generate'])): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Bill Wise Sales Report For <?= date('d-M-Y', strtotime($report_date)) ?></h5>
          </div>
          
          <div class="table-container">
            <?php if (!empty($report_data)): ?>
              <?php foreach ($report_data as $bill_no => $bill_data): ?>
              <table class="report-table mb-4">
                <thead>
                  <tr class="bill-header">
                    <td colspan="6">
                      <strong>Bill No:</strong> <?= $bill_no ?> | 
                      <strong>Type:</strong> <?= $bill_data['type'] == 'customer' ? 'CUSTOMER SALE' : 'RETAIL SALE' ?> | 
                      <strong>Customer:</strong> <?= htmlspecialchars($bill_data['customer']) ?> | 
                      <strong>User:</strong> <?= htmlspecialchars($bill_data['user']) ?>
                    </td>
                  </tr>
                  <tr>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>Size</th>
                    <th class="text-right">Rate</th>
                    <th class="text-right">Qty</th>
                    <th class="text-right">Amount</th>
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
                    <td colspan="5" class="text-end"><strong>Bill Total:</strong></td>
                    <td class="text-right"><strong><?= number_format($bill_data['total'], 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
              <?php endforeach; ?>
              
              <!-- Grand Total -->
              <table class="report-table">
                <thead>
                  <tr>
                    <th colspan="2">Summary</th>
                  </tr>
                </thead>
                <tbody>
                  <tr class="total-row">
                    <td class="text-end"><strong>Grand Total:</strong></td>
                    <td class="text-right"><strong><?= number_format($overall_total, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
              
              <!-- Financial summary -->
              <table class="report-table mt-4">
                <thead>
                  <tr>
                    <th colspan="2">Financial Summary</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="text-end">Prev. Bal.:</td>
                    <td class="text-right"><?= number_format($prev_balance, 2) ?></td>
                  </tr>
                  <tr>
                    <td class="text-end">Credit Sales:</td>
                    <td class="text-right"><?= number_format($credit_sales, 2) ?></td>
                  </tr>
                  <tr>
                    <td class="text-end">Expenses:</td>
                    <td class="text-right"><?= number_format($expenses, 2) ?></td>
                  </tr>
                  <tr>
                    <td class="text-end">Received:</td>
                    <td class="text-right"><?= number_format($received, 2) ?></td>
                  </tr>
                  <tr>
                    <td class="text-end">Discount:</td>
                    <td class="text-right"><?= number_format($discount, 2) ?></td>
                  </tr>
                  <tr class="total-row">
                    <td class="text-end"><strong>Total Cash:</strong></td>
                    <td class="text-right"><strong><?= number_format($total_cash, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
            <?php else: ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> No sales records found for the selected date.
              </div>
            <?php endif; ?>
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