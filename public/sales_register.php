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
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'datewise';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'detailed';
$rate_type = isset($_GET['rate_type']) ? $_GET['rate_type'] : 'mrp';

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

// Define groups based on tblclass (UPDATED - Beer classes separated like groupwise_sales_report.php)
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
    'FERMENTED BEER' => [
        'name' => 'FERMENTED BEER',
        'classes' => ['F'], // Fermented Beer
        'liq_flag' => 'F'
    ],
    'MILD BEER' => [
        'name' => 'MILD BEER', 
        'classes' => ['M'], // Mild Beer
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
// Initialize group totals with the updated beer groups
$group_totals = [
    'SPIRITS' => 0,
    'WINE' => 0,
    'FERMENTED BEER' => 0,
    'MILD BEER' => 0,
    'COUNTRY LIQUOR' => 0
];
$grand_total = 0;

if (isset($_GET['generate'])) {
    // Get sales data grouped by date and category
    $rate_field = ($rate_type === 'mrp') ? 'MPRICE' : 'BPRICE';
    
    $query = "SELECT 
                DATE(s.BILL_DATE) as sale_date,
                i.CLASS as SGROUP,
                i.LIQ_FLAG,
                SUM(COALESCE(i.$rate_field, sd.RATE) * sd.QTY) as total_sale
              FROM tblsaledetails sd
              INNER JOIN tblsaleheader s ON sd.BILL_NO = s.BILL_NO AND sd.COMP_ID = s.COMP_ID
              LEFT JOIN tblitemmaster i ON sd.ITEM_CODE = i.CODE
              WHERE s.BILL_DATE BETWEEN ? AND ? AND s.COMP_ID = ?
              GROUP BY DATE(s.BILL_DATE), i.CLASS, i.LIQ_FLAG
              ORDER BY sale_date, i.CLASS";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssi", $date_from, $date_to, $compID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $sgroup = isset($row['SGROUP']) ? $row['SGROUP'] : 'O'; // Default to 'O' if not set
        $liq_flag = isset($row['LIQ_FLAG']) ? $row['LIQ_FLAG'] : 'F'; // Default to 'F' if not set
        $amount = (float)$row['total_sale'];
        $sale_date = $row['sale_date'];
        
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
        
        // Store data by date and group
        if (!isset($report_data[$sale_date])) {
            $report_data[$sale_date] = [
                'SPIRITS' => 0,
                'WINE' => 0,
                'FERMENTED BEER' => 0,
                'MILD BEER' => 0,
                'COUNTRY LIQUOR' => 0,
                'TOTAL' => 0
            ];
        }
        
        $report_data[$sale_date][$item_group] += $amount;
        $report_data[$sale_date]['TOTAL'] += $amount;
        
        // Update group totals
        $group_totals[$item_group] += $amount;
        $grand_total += $amount;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales Register Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  
  <style>
    /* Additional styles to match the groupwise sales report */
    .filter-card {
      border: 1px solid #dee2e6;
      border-radius: 0.25rem;
    }
    
    .filter-card .card-header {
      background-color: #f8f9fa;
      border-bottom: 1px solid #dee2e6;
      font-weight: bold;
      padding: 0.75rem 1.25rem;
    }
    
    .report-filters .form-label {
      font-weight: 500;
      margin-bottom: 0.5rem;
    }
    
    .action-controls {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    
    .print-section {
      margin-top: 20px;
    }
    
    .company-header {
      text-align: center;
      margin-bottom: 20px;
      border-bottom: 2px solid #000;
      padding-bottom: 15px;
    }
    
    .company-header h1 {
      font-size: 24px;
      font-weight: bold;
      margin-bottom: 5px;
    }
    
    .company-header h5, .company-header h6 {
      margin: 5px 0;
      font-weight: normal;
    }
    
    .table-container {
      overflow-x: auto;
    }
    
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
    }
    
    .report-table th, .report-table td {
      padding: 8px;
      border: 1px solid #dee2e6;
    }
    
    .report-table th {
      background-color: #f8f9fa;
      font-weight: bold;
      text-align: center;
    }
    
    .report-table .text-right {
      text-align: right;
    }
    
    .report-table .text-end {
      text-align: end;
    }
    
    .total-row {
      font-weight: bold;
      background-color: #e9ecef;
    }
    
    .footer-info {
      text-align: center;
      margin-top: 20px;
      font-size: 14px;
      color: #6c757d;
    }
    
    @media print {
      .no-print {
        display: none !important;
      }
      
      .print-section {
        margin-top: 0;
      }
      
      body {
        padding: 0;
        margin: 0;
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
      <h3 class="mb-4">Sales Register Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-2">
                <label class="form-label">Date From:</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Date To:</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Rates:</label>
                <select name="rate_type" class="form-select">
                  <option value="mrp" <?= $rate_type === 'mrp' ? 'selected' : '' ?>>MRP Rate</option>
                  <option value="brate" <?= $rate_type === 'brate' ? 'selected' : '' ?>>B. Rate</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-select">
                  <option value="datewise" <?= $mode === 'datewise' ? 'selected' : '' ?>>Date Wise</option>
                  <option value="monthwise" <?= $mode === 'monthwise' ? 'selected' : '' ?>>Month Wise</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Report:</label>
                <select name="report_type" class="form-select">
                  <option value="detailed" <?= $report_type === 'detailed' ? 'selected' : '' ?>>Detailed</option>
                  <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Summary</option>
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
      <?php if (!empty($report_data)): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Sales Register Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
            <h6>Rate Type: <?= $rate_type === 'mrp' ? 'MRP Rate' : 'B. Rate' ?></h6>
            <h6>Mode: <?= $mode === 'datewise' ? 'Date Wise' : 'Month Wise' ?></h6>
          </div>
          
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>SPIRITS</th>
                  <th>WINE</th>
                  <th>FERMENTED BEER</th>
                  <th>MILD BEER</th>
                  <th>COUNTRY LIQUOR</th>
                  <th>Total Sale</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($report_data as $date => $groups_data): ?>
                  <tr>
                    <td><?= date('d/m/Y', strtotime($date)) ?></td>
                    <td class="text-right"><?= number_format($groups_data['SPIRITS'], 2) ?></td>
                    <td class="text-right"><?= number_format($groups_data['WINE'], 2) ?></td>
                    <td class="text-right"><?= number_format($groups_data['FERMENTED BEER'], 2) ?></td>
                    <td class="text-right"><?= number_format($groups_data['MILD BEER'], 2) ?></td>
                    <td class="text-right"><?= number_format($groups_data['COUNTRY LIQUOR'], 2) ?></td>
                    <td class="text-right"><?= number_format($groups_data['TOTAL'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
                
                <!-- Total row -->
                <tr class="total-row">
                  <td><strong>Total</strong></td>
                  <td class="text-right"><strong><?= number_format($group_totals['SPIRITS'], 2) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($group_totals['WINE'], 2) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($group_totals['FERMENTED BEER'], 2) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($group_totals['MILD BEER'], 2) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($group_totals['COUNTRY LIQUOR'], 2) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($grand_total, 2) ?></strong></td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <div class="footer-info">
            Generated on: <?= date('d-M-Y h:i A') ?> | Generated by: <?= $_SESSION['username'] ?? 'System' ?>
          </div>
        </div>
      <?php elseif (isset($_GET['generate'])): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No sales records found for the selected criteria.
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