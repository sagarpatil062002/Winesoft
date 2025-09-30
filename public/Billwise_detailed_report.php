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
$bill_date = isset($_GET['bill_date']) ? $_GET['bill_date'] : date('Y-m-d');
$report_mode = isset($_GET['report_mode']) ? $_GET['report_mode'] : 'all';
$bill_no = isset($_GET['bill_no']) ? $_GET['bill_no'] : '';

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

// Fetch bill numbers for dropdown
$billNumbers = [];
$billQuery = "SELECT DISTINCT BILL_NO FROM (
    SELECT BILL_NO FROM tblsaleheader WHERE COMP_ID = ? 
    UNION 
    SELECT BillNo as BILL_NO FROM tblcustomersales WHERE CompID = ?
) AS bills ORDER BY BILL_NO";
$billStmt = $conn->prepare($billQuery);
$billStmt->bind_param("ii", $compID, $compID);
$billStmt->execute();
$billResult = $billStmt->get_result();
while ($row = $billResult->fetch_assoc()) {
    $billNumbers[] = $row['BILL_NO'];
}
$billStmt->close();

$report_data = [];
$total_amount = 0;
$grouped_data = [];

if (isset($_GET['generate'])) {
    // Build query based on selected filters
    $retail_query = "SELECT 
        sh.BILL_NO, 
        sh.BILL_DATE as DATE,
        sh.CUST_CODE as Customer_Code,
        'Retail Sale' as Sale_Type,
        sd.ITEM_CODE,
        COALESCE(im.DETAILS, 'Unknown Item') as ItemName,
        COALESCE(im.DETAILS2, '') as ItemSize,
        sd.RATE,
        sd.QTY as Quantity,
        sd.AMOUNT
    FROM tblsaledetails sd
    INNER JOIN tblsaleheader sh ON sd.BILL_NO = sh.BILL_NO AND sd.COMP_ID = sh.COMP_ID
    LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
    WHERE sh.BILL_DATE = ? AND sh.COMP_ID = ?";
    
    $customer_query = "SELECT 
        cs.BillNo as BILL_NO,
        cs.BillDate as DATE,
        lh.LHEAD as Customer_Code,
        'Customer Sale' as Sale_Type,
        cs.ItemCode as ITEM_CODE,
        cs.ItemName,
        cs.ItemSize,
        cs.Rate,
        cs.Quantity,
        cs.Amount
    FROM tblcustomersales cs
    INNER JOIN tbllheads lh ON cs.LCode = lh.LCODE
    WHERE cs.BillDate = ? AND cs.CompID = ?";
    
    $params = [$bill_date, $compID];
    $types = "si";
    
    if ($report_mode === 'particular' && !empty($bill_no)) {
        $retail_query .= " AND sh.BILL_NO = ?";
        $customer_query .= " AND cs.BillNo = ?";
        $params[] = $bill_no;
        $types .= "s";
    }
    
    // Retail sales from tblsaledetails and tblsaleheader
    $retail_stmt = $conn->prepare($retail_query);
    $retail_stmt->bind_param($types, ...$params);
    $retail_stmt->execute();
    $retail_result = $retail_stmt->get_result();
    
    while ($row = $retail_result->fetch_assoc()) {
        $report_data[] = $row;
        $total_amount += $row['AMOUNT'];
    }
    
    $retail_stmt->close();
    
    // Customer sales from tblcustomersales
    $customer_stmt = $conn->prepare($customer_query);
    $customer_stmt->bind_param($types, ...$params);
    $customer_stmt->execute();
    $customer_result = $customer_stmt->get_result();
    
    while ($row = $customer_result->fetch_assoc()) {
        $report_data[] = $row;
        $total_amount += $row['Amount'];
    }
    
    $customer_stmt->close();
    
    // Group data by bill number for display
    foreach ($report_data as $row) {
        $bill_no_key = $row['BILL_NO'];
        if (!isset($grouped_data[$bill_no_key])) {
            $grouped_data[$bill_no_key] = [
                'date' => $row['DATE'],
                'customer' => $row['Customer_Code'],
                'sale_type' => $row['Sale_Type'],
                'items' => []
            ];
        }
        $grouped_data[$bill_no_key]['items'][] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Billwise Detailed Report - WineSoft</title>
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
      <h3 class="mb-4">Billwise Detailed Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Date:</label>
                <input type="date" name="bill_date" class="form-control" value="<?= htmlspecialchars($bill_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Mode:</label>
                <select name="report_mode" class="form-select" id="reportMode">
                  <option value="all" <?= $report_mode === 'all' ? 'selected' : '' ?>>All Bills</option>
                  <option value="particular" <?= $report_mode === 'particular' ? 'selected' : '' ?>>Particular Bill</option>
                </select>
              </div>
              <div class="col-md-3" id="billNoField" style="<?= $report_mode === 'particular' ? '' : 'display: none;' ?>">
                <label class="form-label">Bill No:</label>
                <select name="bill_no" class="form-select" id="billNoSelect">
                  <option value="">Select Bill No</option>
                  <?php foreach ($billNumbers as $number): ?>
                    <option value="<?= htmlspecialchars($number) ?>" <?= $bill_no == $number ? 'selected' : '' ?>>
                      <?= htmlspecialchars($number) ?>
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
            <h5>Billwise Detailed Report For <?= date('d-M-Y', strtotime($bill_date)) ?></h5>
            <?php if ($report_mode === 'particular' && !empty($bill_no)): ?>
              <p class="text-muted">Bill No: <?= htmlspecialchars($bill_no) ?></p>
            <?php endif; ?>
          </div>
          
          <div class="table-container">
            <?php if (!empty($report_data)): ?>
              <table class="report-table">
                <thead>
                  <tr>
                    <th class="text-center">S. No.</th>
                    <th class="text-center">Date</th>
                    <th class="text-center">Bill No.</th>
                    <th class="text-center">TBL No.</th>
                    <th>Item Description</th>
                    <th class="text-right">Rate</th>
                    <th class="text-center">Qty.</th>
                    <th class="text-center">Size</th>
                    <th class="text-right">Tot. Amt.</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $sno = 1;
                  foreach ($grouped_data as $bill_no_key => $bill_data): 
                  ?>
                    <tr class="bill-header">
                      <td colspan="9">
                        <strong>Bill No:</strong> <?= htmlspecialchars($bill_no_key) ?> | 
                        <strong>Date:</strong> <?= date('d/m/Y', strtotime($bill_data['date'])) ?> | 
                        <strong>Customer:</strong> <?= htmlspecialchars($bill_data['customer']) ?> | 
                        <strong>Type:</strong> <?= htmlspecialchars($bill_data['sale_type']) ?>
                      </td>
                    </tr>
                    
                    <?php foreach ($bill_data['items'] as $item): ?>
                    <tr>
                      <td class="text-center"><?= $sno++ ?></td>
                      <td class="text-center"><?= date('d/m/Y', strtotime($item['DATE'])) ?></td>
                      <td class="text-center"><?= htmlspecialchars($bill_no_key) ?></td>
                      <td class="text-center"><?= htmlspecialchars($bill_data['customer']) ?></td>
                      <td><?= htmlspecialchars($item['ItemName']) ?></td>
                      <td class="text-right"><?= isset($item['RATE']) ? number_format($item['RATE'], 2) : number_format($item['Rate'], 2) ?></td>
                      <td class="text-center"><?= htmlspecialchars($item['Quantity']) ?></td>
                      <td class="text-center"><?= !empty($item['ItemSize']) ? htmlspecialchars($item['ItemSize']) : '-' ?></td>
                      <td class="text-right"><?= isset($item['AMOUNT']) ? number_format($item['AMOUNT'], 2) : number_format($item['Amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                  <?php endforeach; ?>
                  
                  <tr class="total-row">
                    <td colspan="8" class="text-end"><strong>Total Amount:</strong></td>
                    <td class="text-right"><strong><?= number_format($total_amount, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
            <?php else: ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> No bill records found for the selected criteria.
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
<script>
  // Show/hide bill number field based on report mode
  $(document).ready(function() {
    $('#reportMode').change(function() {
      if ($(this).val() === 'particular') {
        $('#billNoField').show();
      } else {
        $('#billNoField').hide();
      }
    });
  });
</script>
</body>
</html>