<?php
session_start();
require_once 'components/financial_year_auto.php';

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
include_once "components/financial_year.php";

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

// Define categories based on tblcategory
$categories = [
    'CAT001' => 'SPIRITS',
    'CAT002' => 'WINE',
    'CAT003' => 'FERMENTED BEER',
    'CAT004' => 'MILD BEER',
    'CAT005' => 'COUNTRY LIQUOR'
];

// Generate report data based on filters
$report_data = [];
// Initialize group totals
$group_totals = [
    'SPIRITS' => ['with_tax' => 0, 'without_tax' => 0, 'tax' => 0],
    'WINE' => ['with_tax' => 0, 'without_tax' => 0, 'tax' => 0],
    'FERMENTED BEER' => ['with_tax' => 0, 'without_tax' => 0, 'tax' => 0],
    'MILD BEER' => ['with_tax' => 0, 'without_tax' => 0, 'tax' => 0],
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
        // Use tblcustomersales table with proper joins to new tables
        $sales_query = "SELECT 
                    cs.BillNo as BILL_NO,
                    cs.BillDate as BILL_DATE,
                    cs.ItemCode as ITEM_CODE,
                    cs.ItemName as ITEM_NAME,
                    cs.Rate as RATE,
                    cs.Quantity as QTY,
                    cs.Amount as AMOUNT,
                    i.CLASS as OLD_CLASS,
                    i.SUB_CLASS as OLD_SUB_CLASS,
                    i.LIQ_FLAG,
                    cat.CATEGORY_CODE,
                    cat.CATEGORY_NAME,
                    cls.CLASS_CODE,
                    cls.CLASS_NAME,
                    sub.SUBCLASS_CODE,
                    sub.SUBCLASS_NAME
                  FROM tblcustomersales cs
                  LEFT JOIN tblitemmaster i ON cs.ItemCode = i.CODE
                  LEFT JOIN tblcategory cat ON i.CATEGORY_CODE = cat.CATEGORY_CODE
                  LEFT JOIN tblclass_new cls ON i.CLASS_CODE_NEW = cls.CLASS_CODE
                  LEFT JOIN tblsubclass_new sub ON i.SUBCLASS_CODE_NEW = sub.SUBCLASS_CODE
                  WHERE cs.BillDate BETWEEN ? AND ? AND cs.CompID = ?
                  ORDER BY cat.CATEGORY_NAME, cls.CLASS_NAME, cs.BillDate, cs.BillNo";
        
        $stmt = $conn->prepare($sales_query);
        $stmt->bind_param("ssi", $date_from, $date_to, $compID);
    } else {
        // Use tblsaleheader and tblsaledetails tables with proper joins to new tables
        $sales_query = "SELECT
                    sh.BILL_NO,
                    sh.BILL_DATE,
                    sd.ITEM_CODE,
                    CASE WHEN i.Print_Name != '' THEN i.Print_Name ELSE i.DETAILS END as ITEM_NAME,
                    i.CLASS as OLD_CLASS,
                    i.SUB_CLASS as OLD_SUB_CLASS,
                    i.LIQ_FLAG,
                    cat.CATEGORY_CODE,
                    cat.CATEGORY_NAME,
                    cls.CLASS_CODE,
                    cls.CLASS_NAME,
                    sub.SUBCLASS_CODE,
                    sub.SUBCLASS_NAME,
                    sd.QTY,
                    sd.RATE,
                    sd.AMOUNT
                  FROM tblsaleheader sh
                  INNER JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
                  LEFT JOIN tblitemmaster i ON sd.ITEM_CODE = i.CODE
                  LEFT JOIN tblcategory cat ON i.CATEGORY_CODE = cat.CATEGORY_CODE
                  LEFT JOIN tblclass_new cls ON i.CLASS_CODE_NEW = cls.CLASS_CODE
                  LEFT JOIN tblsubclass_new sub ON i.SUBCLASS_CODE_NEW = sub.SUBCLASS_CODE
                  WHERE sh.BILL_DATE BETWEEN ? AND ? AND sh.COMP_ID = ?
                  ORDER BY cat.CATEGORY_NAME, cls.CLASS_NAME, sh.BILL_DATE, sh.BILL_NO";
        
        $stmt = $conn->prepare($sales_query);
        $stmt->bind_param("ssi", $date_from, $date_to, $compID);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Debug: Check if we got any results
    $row_count = $result->num_rows;
    
    // Class mapping for old class codes (as fallback)
    $class_mapping = [
        'W' => ['class_name' => 'IMFL', 'category' => 'CAT001', 'liq_flag' => 'F'],
        'G' => ['class_name' => 'IMFL', 'category' => 'CAT001', 'liq_flag' => 'F'],
        'K' => ['class_name' => 'IMFL', 'category' => 'CAT001', 'liq_flag' => 'F'],
        'D' => ['class_name' => 'IMFL', 'category' => 'CAT001', 'liq_flag' => 'F'],
        'R' => ['class_name' => 'IMFL', 'category' => 'CAT001', 'liq_flag' => 'F'],
        'V' => ['class_name' => 'INDIAN', 'category' => 'CAT002', 'liq_flag' => 'F'],
        'F' => ['class_name' => 'FERMENTED BEER', 'category' => 'CAT003', 'liq_flag' => 'F'],
        'M' => ['class_name' => 'MILD BEER', 'category' => 'CAT004', 'liq_flag' => 'F'],
        'L' => ['class_name' => 'COUNTRY LIQUOR', 'category' => 'CAT005', 'liq_flag' => 'C'],
        'O' => ['class_name' => 'GENERAL', 'category' => 'CAT001', 'liq_flag' => 'F']
    ];
    
    // Organize sales data by category and class
    while ($row = $result->fetch_assoc()) {
        $amount = (float)$row['AMOUNT'];
        
        // Get classification from new columns first
        $category_code = isset($row['CATEGORY_CODE']) ? $row['CATEGORY_CODE'] : '';
        $category_name = isset($row['CATEGORY_NAME']) ? $row['CATEGORY_NAME'] : '';
        $class_code = isset($row['CLASS_CODE']) ? $row['CLASS_CODE'] : '';
        $class_name = isset($row['CLASS_NAME']) ? $row['CLASS_NAME'] : '';
        $subclass_name = isset($row['SUBCLASS_NAME']) ? $row['SUBCLASS_NAME'] : 'Unknown';
        
        // If new columns are not set, use old columns as fallback
        if (empty($category_code) || empty($class_name)) {
            $old_class = isset($row['OLD_CLASS']) ? $row['OLD_CLASS'] : 'O';
            $liq_flag = isset($row['LIQ_FLAG']) ? $row['LIQ_FLAG'] : 'F';
            
            if (isset($class_mapping[$old_class])) {
                $mapping = $class_mapping[$old_class];
                $category_code = $mapping['category'];
                $category_name = isset($categories[$category_code]) ? $categories[$category_code] : 'SPIRITS';
                $class_name = $mapping['class_name'];
            } else {
                // Default to SPIRITS
                $category_code = 'CAT001';
                $category_name = 'SPIRITS';
                $class_name = 'IMFL';
            }
        }
        
        // Ensure category name is set
        if (empty($category_name) && isset($categories[$category_code])) {
            $category_name = $categories[$category_code];
        } elseif (empty($category_name)) {
            $category_name = 'SPIRITS';
        }
        
        // Initialize arrays if not exists
        if (!isset($report_data[$category_code])) {
            $report_data[$category_code] = [
                'name' => $category_name,
                'classes' => []
            ];
        }
        
        $class_key = !empty($class_code) ? $class_code : $class_name;
        if (!isset($report_data[$category_code]['classes'][$class_key])) {
            $report_data[$category_code]['classes'][$class_key] = [
                'name' => $class_name,
                'bills' => [],
                'total' => 0
            ];
        }
        
        // For simplicity, assuming tax rate of 0%
        $tax_amount = 0;
        $amount_without_tax = $amount;
        $amount_with_tax = $amount;
        
        if ($mode == 'summary') {
            // For summary mode, just accumulate totals by group
            $group_key = $category_name;
            if (isset($group_totals[$group_key])) {
                $group_totals[$group_key]['with_tax'] += $amount_with_tax;
                $group_totals[$group_key]['without_tax'] += $amount_without_tax;
                $group_totals[$group_key]['tax'] += $tax_amount;
            }
            
            $grand_total['with_tax'] += $amount_with_tax;
            $grand_total['without_tax'] += $amount_without_tax;
            $grand_total['tax'] += $tax_amount;
        } else {
            // For detailed mode, store bill information
            $bill_no = $row['BILL_NO'];
            
            if (!isset($report_data[$category_code]['classes'][$class_key]['bills'][$bill_no])) {
                $report_data[$category_code]['classes'][$class_key]['bills'][$bill_no] = [
                    'BILL_DATE' => $row['BILL_DATE'],
                    'items' => [],
                    'total' => 0
                ];
            }
            
            $report_data[$category_code]['classes'][$class_key]['bills'][$bill_no]['items'][] = [
                'ITEM_CODE' => $row['ITEM_CODE'],
                'ITEM_NAME' => $row['ITEM_NAME'],
                'SUBCLASS_NAME' => $subclass_name,
                'CLASS_NAME' => $class_name,
                'CATEGORY_NAME' => $category_name,
                'QTY' => $row['QTY'],
                'RATE' => $row['RATE'],
                'AMOUNT' => $amount
            ];
            
            $report_data[$category_code]['classes'][$class_key]['bills'][$bill_no]['total'] += $amount;
            $report_data[$category_code]['classes'][$class_key]['total'] += $amount;
            
            // Update group totals
            $group_key = $category_name;
            if (isset($group_totals[$group_key])) {
                $group_totals[$group_key]['with_tax'] += $amount_with_tax;
                $group_totals[$group_key]['without_tax'] += $amount_without_tax;
                $group_totals[$group_key]['tax'] += $tax_amount;
            }
            
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
  <style>
    /* Keep the original CSS styling */
    .group-header {
      font-size: 18px;
      font-weight: bold;
      margin-top: 25px;
      margin-bottom: 15px;
      padding: 8px 15px;
      background-color: #2c3e50;
      color: white;
      border-radius: 5px;
    }
    .class-header {
      font-size: 16px;
      font-weight: bold;
      margin-top: 15px;
      margin-bottom: 10px;
      padding: 5px 15px;
      background-color: #34495e;
      color: white;
      border-radius: 5px;
      margin-left: 20px;
    }
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 15px;
      margin-left: 30px;
      width: calc(100% - 30px);
    }
    .report-table th {
      background-color: #f2f2f2;
      padding: 10px;
      text-align: left;
      border-bottom: 2px solid #ddd;
    }
    .report-table td {
      padding: 8px 10px;
      border-bottom: 1px solid #ddd;
    }
    .text-right {
      text-align: right;
    }
    .group-total-row {
      background-color: #f9f9f9;
      font-weight: bold;
    }
    .group-total-row td {
      border-top: 2px solid #999;
    }
    .total-row {
      background-color: #e9e9e9;
      font-weight: bold;
      font-size: 16px;
    }
    .total-row td {
      border-top: 3px double #333;
    }
    .company-header {
      text-align: center;
      margin-bottom: 25px;
    }
    .company-header h1 {
      font-size: 24px;
      margin-bottom: 5px;
    }
    .company-header h5 {
      font-size: 16px;
      color: #666;
      margin-bottom: 5px;
    }
    .company-header p {
      font-size: 14px;
      color: #999;
      margin-bottom: 0;
    }
    .no-print {
      margin-bottom: 20px;
    }
    .subclass-name {
      font-weight: 500;
      color: #2980b9;
    }
    .text-muted {
      color: #6c757d;
      font-style: italic;
      margin-left: 30px;
    }
  </style>
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
        <p>Sample data from first row: 
          <?php
          if ($row_count > 0) {
              $result->data_seek(0);
              $sample = $result->fetch_assoc();
              echo "Item: " . ($sample['ITEM_NAME'] ?? 'N/A');
              echo ", Category: " . ($sample['CATEGORY_NAME'] ?? 'N/A');
              echo ", Class: " . ($sample['CLASS_NAME'] ?? 'N/A');
              echo ", Subclass: " . ($sample['SUBCLASS_NAME'] ?? 'N/A');
          }
          ?>
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
                  <?php foreach ($group_totals as $group_key => $totals): ?>
                  <tr>
                    <td><strong><?= $group_key ?></strong></td>
                    <td class="text-right"><?= number_format($totals['with_tax'], 2) ?></td>
                    <td class="text-right"><?= number_format($totals['without_tax'], 2) ?></td>
                    <td class="text-right"><?= number_format($totals['tax'], 2) ?></td>
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
              <!-- Detailed Report with Category and Class Hierarchy -->
              <?php foreach ($report_data as $category_code => $category): ?>
                <!-- Category Header -->
                <h5 class="group-header"><?= htmlspecialchars($category['name']) ?></h5>
                
                <?php if (!empty($category['classes'])): ?>
                  <?php foreach ($category['classes'] as $class_code => $class): ?>
                    <!-- Class Header -->
                    <h6 class="class-header"><?= htmlspecialchars($class['name']) ?></h6>
                    
                    <?php if (!empty($class['bills'])): ?>
                      <!-- Sales Details Table -->
                      <table class="report-table">
                        <thead>
                          <tr>
                            <th>Date</th>
                            <th>Bill No</th>
                            <th>Item Code</th>
                            <th>Item Description</th>
                            <th>Sub Class</th>
                            <th class="text-right">Rate (Rs.)</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Amount (Rs.)</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($class['bills'] as $bill_no => $bill_data): ?>
                            <?php foreach ($bill_data['items'] as $item): ?>
                            <tr>
                              <td><?= date('d/m/Y', strtotime($bill_data['BILL_DATE'])) ?></td>
                              <td><?= htmlspecialchars($bill_no) ?></td>
                              <td><?= htmlspecialchars($item['ITEM_CODE']) ?></td>
                              <td><?= htmlspecialchars($item['ITEM_NAME']) ?></td>
                              <td><span class="subclass-name"><?= htmlspecialchars($item['SUBCLASS_NAME']) ?></span></td>
                              <td class="text-right"><?= number_format($item['RATE'], 2) ?></td>
                              <td class="text-right"><?= number_format($item['QTY'], 3) ?></td>
                              <td class="text-right"><?= number_format($item['AMOUNT'], 2) ?></td>
                            </tr>
                            <?php endforeach; ?>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                      
                      <!-- Class Subtotal -->
                      <table class="report-table">
                        <tr class="group-total-row">
                          <td colspan="7" class="text-end"><?= htmlspecialchars($class['name']) ?> Sub Total:</td>
                          <td class="text-right"><?= number_format($class['total'], 2) ?></td>
                        </tr>
                      </table>
                    <?php else: ?>
                      <p class="text-muted">No sales found for <?= htmlspecialchars($class['name']) ?></p>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php else: ?>
                  <p class="text-muted">No sales found for <?= htmlspecialchars($category['name']) ?></p>
                <?php endif; ?>
              <?php endforeach; ?>
              
              <!-- Grand Total -->
              <table class="report-table">
                <tr class="total-row">
                  <td colspan="7" class="text-end">Grand Total:</td>
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
  <?php require_once 'components/financial_year_footer.php'; ?>

</body>
</html>