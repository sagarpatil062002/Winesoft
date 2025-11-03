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

// Default values - set to current month range
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$user_id = isset($_GET['user_id']) ? $_GET['user_id'] : 'all';

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

// Fetch users for dropdown
$users = [];
$userQuery = "SELECT id, username FROM users WHERE company_id = ? ORDER BY username";
$userStmt = $conn->prepare($userQuery);
$userStmt->bind_param("i", $compID);
$userStmt->execute();
$userResult = $userStmt->get_result();
while ($row = $userResult->fetch_assoc()) {
    $users[] = $row;
}
$userStmt->close();

// Initialize report data
$user_summary = [];
$bill_details = [];
$overall_total = 0;
$prev_balance = 0;
$credit_sales = 0;
$expenses = 0;
$received = 0;
$discount = 0;
$total_cash = 0;

if (isset($_GET['generate'])) {
    // Get user-wise summary data
    $user_summary_query = "SELECT 
        u.id as UserID,
        u.username as UserName,
        COALESCE(SUM(retail_sales.TotalAmount), 0) + COALESCE(SUM(customer_sales.TotalAmount), 0) as TotalAmount
    FROM users u
    LEFT JOIN (
        -- Retail sales from tblsaleheader and tblsaledetails
        SELECT 
            sh.CREATED_BY as UserID,
            SUM(sd.AMOUNT) as TotalAmount
        FROM tblsaleheader sh
        INNER JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
        WHERE sh.BILL_DATE BETWEEN ? AND ? AND sh.COMP_ID = ?
        GROUP BY sh.CREATED_BY
    ) as retail_sales ON u.id = retail_sales.UserID
    LEFT JOIN (
        -- Customer sales from tblcustomersales
        SELECT 
            cs.UserID,
            SUM(cs.Amount) as TotalAmount
        FROM tblcustomersales cs
        WHERE cs.BillDate BETWEEN ? AND ? AND cs.CompID = ?
        GROUP BY cs.UserID
    ) as customer_sales ON u.id = customer_sales.UserID
    WHERE u.company_id = ?";
    
    $params = [$start_date, $end_date, $compID, $start_date, $end_date, $compID, $compID];
    $types = "ssisssi";
    
    if ($user_id !== 'all') {
        $user_summary_query .= " AND u.id = ?";
        $params[] = $user_id;
        $types .= "i";
    }
    
    $user_summary_query .= " GROUP BY u.id, u.username ORDER BY u.username";
    
    $stmt = $conn->prepare($user_summary_query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $user_summary[] = $row;
        $overall_total += $row['TotalAmount'];
    }
    $stmt->close();
    
    // Get detailed bill data for each user
    foreach ($user_summary as $user) {
        $user_id_filter = $user['UserID'];
        
        // Customer sales data
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
          WHERE cs.BillDate BETWEEN ? AND ? AND cs.CompID = ? AND cs.UserID = ?
          ORDER BY cs.BillNo, cs.CreatedDate";
        
        $stmt = $conn->prepare($customer_sales_query);
        $stmt->bind_param("ssii", $start_date, $end_date, $compID, $user_id_filter);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $bill_no = $row['BillNo'];
            $user_name = $row['UserName'] ? $row['UserName'] : 'Unknown';
            
            if (!isset($bill_details[$user_id_filter])) {
                $bill_details[$user_id_filter] = [];
            }
            
            if (!isset($bill_details[$user_id_filter][$bill_no])) {
                $bill_details[$user_id_filter][$bill_no] = [
                    'type' => 'customer',
                    'customer' => $row['CustomerName'],
                    'user' => $user_name,
                    'bill_date' => $row['BillDate'],
                    'items' => [],
                    'total' => 0
                ];
            }
            
            $bill_details[$user_id_filter][$bill_no]['items'][] = $row;
            $bill_details[$user_id_filter][$bill_no]['total'] += $row['Amount'];
        }
        $stmt->close();
        
        // Retail sales data
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
            WHERE sh.BILL_DATE BETWEEN ? AND ? AND sh.COMP_ID = ? AND sh.CREATED_BY = ?
            ORDER BY sh.BILL_NO, sh.CREATED_DATE";
        
        $stmt = $conn->prepare($retail_sales_query);
        $stmt->bind_param("ssii", $start_date, $end_date, $compID, $user_id_filter);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $bill_no = $row['BillNo'];
            $user_name = $row['UserName'] ? $row['UserName'] : 'Unknown';
            
            if (!isset($bill_details[$user_id_filter])) {
                $bill_details[$user_id_filter] = [];
            }
            
            if (!isset($bill_details[$user_id_filter][$bill_no])) {
                $bill_details[$user_id_filter][$bill_no] = [
                    'type' => 'retail',
                    'customer' => $row['CustomerName'],
                    'user' => $user_name,
                    'bill_date' => $row['BillDate'],
                    'items' => [],
                    'total' => 0
                ];
            }
            
            $bill_details[$user_id_filter][$bill_no]['items'][] = $row;
            $bill_details[$user_id_filter][$bill_no]['total'] += $row['Amount'];
        }
        $stmt->close();
    }
    
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
  <title>Combined Sales Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <style>
    .section-title {
        background-color: #f8f9fa;
        padding: 8px 15px;
        margin: 20px 0 10px 0;
        border-left: 4px solid #007bff;
        font-weight: bold;
    }
    .user-section {
        background-color: #e9ecef;
        padding: 6px 12px;
        margin: 15px 0 8px 0;
        border-radius: 4px;
        font-weight: bold;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Combined Sales Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Start Date:</label>
                <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">End Date:</label>
                <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">User:</label>
                <select name="user_id" class="form-select">
                  <option value="all" <?= $user_id === 'all' ? 'selected' : '' ?>>All Users</option>
                  <?php foreach ($users as $user): ?>
                    <option value="<?= htmlspecialchars($user['id']) ?>" <?= $user_id == $user['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($user['username']) ?>
                    </option>
                  <?php endforeach; ?>
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
      <?php if (isset($_GET['generate'])): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Combined Sales Report For Period: <?= date('d-M-Y', strtotime($start_date)) ?> to <?= date('d-M-Y', strtotime($end_date)) ?></h5>
          </div>
          
          <div class="table-container">
            <?php if (!empty($user_summary)): ?>
              <!-- User-wise Summary Section -->
              <div class="section-title">User Wise Summary</div>
              <table class="report-table mb-4">
                <thead>
                  <tr>
                    <th class="text-center">S. No.</th>
                    <th>User Name</th>
                    <th class="text-right">Total Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $sno = 1;
                  foreach ($user_summary as $user): 
                  ?>
                    <tr>
                      <td class="text-center"><?= $sno++ ?></td>
                      <td><?= htmlspecialchars($user['UserName']) ?></td>
                      <td class="text-right"><?= number_format($user['TotalAmount'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  
                  <tr class="total-row">
                    <td colspan="2" class="text-end"><strong>Grand Total:</strong></td>
                    <td class="text-right"><strong><?= number_format($overall_total, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
              
              <!-- Bill-wise Details Section -->
              <div class="section-title">Bill Wise Details</div>
              <?php foreach ($user_summary as $user): ?>
                <?php if (isset($bill_details[$user['UserID']]) && !empty($bill_details[$user['UserID']])): ?>
                  <div class="user-section">User: <?= htmlspecialchars($user['UserName']) ?></div>
                  
                  <?php foreach ($bill_details[$user['UserID']] as $bill_no => $bill_data): ?>
                  <!-- REMOVED BILL HEADER INFORMATION - Only show the items table -->
                  <table class="report-table mb-4">
                    <thead>
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
                <?php endif; ?>
              <?php endforeach; ?>
              
              <!-- Financial Summary -->
              <div class="section-title">Financial Summary</div>
              <table class="report-table">
                <tbody>
                  <tr>
                    <td class="text-end">Previous Balance:</td>
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
                <i class="fas fa-info-circle me-2"></i> No sales records found for the selected criteria.
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