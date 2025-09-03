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

// Define groups based on tblclass
$groups = [
    'SPIRITS' => [
        'name' => 'SPIRITS',
        'classes' => ['W', 'G', 'K', 'D', 'R', 'O'], // Whisky, Gin, Vodka, Brandy, Rum, Other/General
        'liq_flag' => 'F'
    ],
    'WINE' => [
        'name' => 'WINE',
        'classes' => ['V'], // Wines
        'liq_flag' => 'F'
    ],
    'BEER [FERMENTED & MILD]' => [
        'name' => 'BEER [FERMENTED & MILD]',
        'classes' => ['F', 'M'], // Fermented Beer, Mild Beer
        'liq_flag' => 'F'
    ],
    'COUNTRY LIQUOR' => [
        'name' => 'COUNTRY LIQUOR',
        'classes' => ['L', 'O'], // Liquors, Other/General
        'liq_flag' => 'C'
    ]
];

// Generate report data based on filters
$report_data = [];
$group_totals = [
    'SPIRITS' => ['with_tax' => 0, 'without_tax' => 0, 'tax' => 0],
    'WINE' => ['with_tax' => 0, 'without_tax' => 0, 'tax' => 0],
    'BEER [FERMENTED & MILD]' => ['with_tax' => 0, 'without_tax' => 0, 'tax' => 0],
    'COUNTRY LIQUOR' => ['with_tax' => 0, 'without_tax' => 0, 'tax' => 0]
];
$grand_total = ['with_tax' => 0, 'without_tax' => 0, 'tax' => 0];

if (isset($_GET['generate'])) {
    // First, let's check which tables have data
    $check_tables = [];
    
    // Check tblsaleheader
    $check_query = "SELECT COUNT(*) as count FROM tblsaleheader WHERE BILL_DATE BETWEEN ? AND ? AND COMP_ID = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ssi", $date_from, $date_to, $compID);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $row = $check_result->fetch_assoc();
    $check_tables['tblsaleheader'] = $row['count'];
    $check_stmt->close();
    
    // Check tblcustomersales
    $check_query = "SELECT COUNT(*) as count FROM tblcustomersales WHERE BillDate BETWEEN ? AND ? AND CompID = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ssi", $date_from, $date_to, $compID);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $row = $check_result->fetch_assoc();
    $check_tables['tblcustomersales'] = $row['count'];
    $check_stmt->close();
    
    // Determine which table to use based on data availability
    $use_customer_sales = ($check_tables['tblcustomersales'] > 0);
    
    if ($use_customer_sales) {
        // Use tblcustomersales table
        $sales_query = "SELECT 
                    cs.BillNo as BILL_NO,
                    cs.BillDate as BILL_DATE,
                    cs.ItemCode as ITEM_CODE,
                    cs.ItemName as ITEM_NAME,
                    cs.Rate as RATE,
                    cs.Quantity as QTY,
                    cs.Amount as AMOUNT,
                    i.CLASS as SGROUP,
                    c.DESC as CLASS_DESC,
                    i.LIQ_FLAG
                  FROM tblcustomersales cs
                  LEFT JOIN tblitemmaster i ON cs.ItemCode = i.CODE
                  LEFT JOIN tblclass c ON i.CLASS = c.SGROUP AND i.LIQ_FLAG = c.LIQ_FLAG
                  WHERE cs.BillDate BETWEEN ? AND ? AND cs.CompID = ?
                  ORDER BY cs.BillDate, cs.BillNo";
        
        $stmt = $conn->prepare($sales_query);
        $stmt->bind_param("ssi", $date_from, $date_to, $compID);
    } else {
        // Use tblsaleheader and tblsaledetails tables
        $sales_query = "SELECT 
                    sh.BILL_NO,
                    sh.BILL_DATE,
                    sd.ITEM_CODE,
                    i.DETAILS as ITEM_NAME,
                    i.CLASS as SGROUP,
                    c.DESC as CLASS_DESC,
                    i.LIQ_FLAG,
                    sd.QTY,
                    sd.RATE,
                    sd.AMOUNT
                  FROM tblsaleheader sh
                  INNER JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
                  LEFT JOIN tblitemmaster i ON sd.ITEM_CODE = i.CODE
                  LEFT JOIN tblclass c ON i.CLASS = c.SGROUP AND i.LIQ_FLAG = c.LIQ_FLAG
                  WHERE sh.BILL_DATE BETWEEN ? AND ? AND sh.COMP_ID = ?
                  ORDER BY sh.BILL_DATE, sh.BILL_NO";
        
        $stmt = $conn->prepare($sales_query);
        $stmt->bind_param("ssi", $date_from, $date_to, $compID);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Debug: Check if we got any results
    $row_count = $result->num_rows;
    
    // Organize sales data by group
    while ($row = $result->fetch_assoc()) {
        $sgroup = isset($row['SGROUP']) ? $row['SGROUP'] : 'O'; // Default to 'O' if not set
        $liq_flag = isset($row['LIQ_FLAG']) ? $row['LIQ_FLAG'] : 'F'; // Default to 'F' if not set
        $amount = (float)$row['AMOUNT'];
        
        // Determine which group this item belongs to
        $item_group = null;
        foreach ($groups as $group_key => $group_info) {
            if ($group_info['liq_flag'] === $liq_flag && in_array($sgroup, $group_info['classes'])) {
                $item_group = $group_key;
                break;
            }
        }
        
        // If we couldn't classify the item, assign to SPIRITS as default
        if ($item_group === null) {
            $item_group = 'SPIRITS';
        }
        
        // For simplicity, assuming tax rate of 0% (as shown in sample reports)
        $tax_amount = 0;
        $amount_without_tax = $amount;
        $amount_with_tax = $amount;
        
        if ($mode == 'summary') {
            // For summary mode, just accumulate totals by group
            $group_totals[$item_group]['with_tax'] += $amount_with_tax;
            $group_totals[$item_group]['without_tax'] += $amount_without_tax;
            $group_totals[$item_group]['tax'] += $tax_amount;
            
            $grand_total['with_tax'] += $amount_with_tax;
            $grand_total['without_tax'] += $amount_without_tax;
            $grand_total['tax'] += $tax_amount;
        } else {
            // For detailed mode, store bill information
            $bill_no = $row['BILL_NO'];
            
            if (!isset($report_data[$item_group][$bill_no])) {
                $report_data[$item_group][$bill_no] = [
                    'BILL_DATE' => $row['BILL_DATE'],
                    'items' => [],
                    'with_tax' => 0,
                    'without_tax' => 0,
                    'tax' => 0
                ];
            }
            
            $report_data[$item_group][$bill_no]['items'][] = [
                'ITEM_CODE' => $row['ITEM_CODE'],
                'ITEM_NAME' => $row['ITEM_NAME'],
                'CLASS_DESC' => isset($row['CLASS_DESC']) ? $row['CLASS_DESC'] : 'Unknown',
                'QTY' => $row['QTY'],
                'RATE' => $row['RATE'],
                'AMOUNT' => $amount
            ];
            
            $report_data[$item_group][$bill_no]['with_tax'] += $amount_with_tax;
            $report_data[$item_group][$bill_no]['without_tax'] += $amount_without_tax;
            $report_data[$item_group][$bill_no]['tax'] += $tax_amount;
            
            // Also update group totals
            $group_totals[$item_group]['with_tax'] += $amount_with_tax;
            $group_totals[$item_group]['without_tax'] += $amount_without_tax;
            $group_totals[$item_group]['tax'] += $tax_amount;
            
            $grand_total['with_tax'] += $amount_with_tax;
            $grand_total['without_tax'] += $amount_without_tax;
            $grand_total['tax'] += $tax_amount;
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
  <title>Group Wise Sales Report - WineSoft</title>
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
      <h3 class="mb-4">Group Wise Sales Report</h3>

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

      <!-- Debug Information (hidden by default, can be shown with URL parameter) -->
      <?php if (isset($_GET['generate']) && isset($_GET['debug'])): ?>
      <div class="alert alert-info no-print">
        <h5>Debug Information:</h5>
        <p>Using data from: <?= $use_customer_sales ? 'tblcustomersales' : 'tblsaleheader/tblsaledetails' ?></p>
        <p>Rows found: <?= $row_count ?></p>
        <p>Tables counts: 
          tblsaleheader: <?= $check_tables['tblsaleheader'] ?>, 
          tblcustomersales: <?= $check_tables['tblcustomersales'] ?>
        </p>
      </div>
      <?php endif; ?>

      <!-- Report Results -->
      <?php if (isset($_GET['generate'])): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Group Wise Sales Summary Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
            <?php if ($use_customer_sales): ?>
            <p class="text-muted">(Data source: tblcustomersales)</p>
            <?php else: ?>
            <p class="text-muted">(Data source: tblsaleheader/tblsaledetails)</p>
            <?php endif; ?>
          </div>
          
          <div class="table-container">
            <?php if ($mode == 'summary'): ?>
              <!-- Summary Report -->
              <table class="report-table">
                <thead>
                  <tr>
                    <th>Particulars</th>
                    <th class="text-right">Amt. With Sales Tax</th>
                    <th class="text-right">Amt. Without Sales Tax</th>
                    <th class="text-right">Sales Tax</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($groups as $group_key => $group_info): ?>
                  <tr>
                    <td><strong><?= $group_info['name'] ?></strong></td>
                    <td class="text-right"><?= number_format($group_totals[$group_key]['with_tax'], 2) ?></td>
                    <td class="text-right"><?= number_format($group_totals[$group_key]['without_tax'], 2) ?></td>
                    <td class="text-right"><?= number_format($group_totals[$group_key]['tax'], 2) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  <tr class="total-row">
                    <td><strong>Total:</strong></td>
                    <td class="text-right"><?= number_format($grand_total['with_tax'], 2) ?></td>
                    <td class="text-right"><?= number_format($grand_total['without_tax'], 2) ?></td>
                    <td class="text-right"><?= number_format($grand_total['tax'], 2) ?></td>
                  </tr>
                </tbody>
              </table>
            <?php else: ?>
              <!-- Detailed Report -->
              <?php foreach ($groups as $group_key => $group_info): ?>
                <?php if (isset($report_data[$group_key]) && !empty($report_data[$group_key])): ?>
                <h5 class="group-header"><?= $group_info['name'] ?></h5>
                
                <?php foreach ($report_data[$group_key] as $bill_no => $bill_data): ?>
                <h6>Bill No: <?= $bill_no ?> | Date: <?= date('d/m/Y', strtotime($bill_data['BILL_DATE'])) ?></h6>
                <table class="report-table">
                  <thead>
                    <tr>
                      <th>Item Code</th>
                      <th>Item Description</th>
                      <th>Category</th>
                      <th class="text-right">Rate</th>
                      <th class="text-right">Qty</th>
                      <th class="text-right">Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($bill_data['items'] as $item): ?>
                    <tr>
                      <td><?= htmlspecialchars($item['ITEM_CODE']) ?></td>
                      <td><?= htmlspecialchars($item['ITEM_NAME']) ?></td>
                      <td><?= htmlspecialchars($item['CLASS_DESC']) ?></td>
                      <td class="text-right"><?= number_format($item['RATE'], 2) ?></td>
                      <td class="text-right"><?= $item['QTY'] ?></td>
                      <td class="text-right"><?= number_format($item['AMOUNT'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="subtotal-row">
                      <td colspan="5" class="text-end">Bill Sub Total:</td>
                      <td class="text-right"><?= number_format($bill_data['without_tax'], 2) ?></td>
                    </tr>
                  </tbody>
                </table>
                <?php endforeach; ?>
                
                <!-- Group Subtotal -->
                <table class="report-table">
                  <tr class="group-total-row">
                    <td colspan="5" class="text-end"><?= $group_info['name'] ?> Sub Total:</td>
                    <td class="text-right"><?= number_format($group_totals[$group_key]['without_tax'], 2) ?></td>
                  </tr>
                </table>
                <?php else: ?>
                <h5 class="group-header"><?= $group_info['name'] ?></h5>
                <p class="text-muted">No sales found for this group</p>
                <?php endif; ?>
              <?php endforeach; ?>
              
              <!-- Grand Total -->
              <table class="report-table">
                <tr class="total-row">
                  <td colspan="5" class="text-end">Grand Total:</td>
                  <td class="text-right"><?= number_format($grand_total['without_tax'], 2) ?></td>
                </tr>
              </table>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif (isset($_GET['generate']) && $row_count == 0): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No sales records found for the selected criteria.
          <p class="mb-0 mt-2">Checked tables: 
            tblsaleheader (<?= $check_tables['tblsaleheader'] ?> records), 
            tblcustomersales (<?= $check_tables['tblcustomersales'] ?> records)
          </p>
          <p class="mb-0">Try adding <code>&debug=1</code> to the URL for more information.</p>
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