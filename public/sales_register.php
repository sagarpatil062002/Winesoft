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
require_once 'license_functions.php'; // Add license functions

// Get company ID from session
$compID = $_SESSION['CompID'];

// Get company's license type and available classes
$license_type = getCompanyLicenseType($compID, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

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

// Function to get table names for a specific date range
function getSalesTablesForDateRange($conn, $compID, $date_from, $date_to) {
    $tables = [];
    
    // Generate all months between date_from and date_to
    $start = new DateTime($date_from);
    $end = new DateTime($date_to);
    $end->modify('first day of next month');
    
    $period = new DatePeriod($start, new DateInterval('P1M'), $end);
    
    foreach ($period as $dt) {
        $year_month = $dt->format('Y-m');
        $current_month = date('Y-m');
        
        // If target month is current month, use base tables
        if ($year_month === $current_month) {
            $tables[$year_month] = [
                'header' => "tblsaleheader",
                'details' => "tblsaledetails"
            ];
        } else {
            // For previous months, use archive table format: tblsaleheader_mm_yy, tblsaledetails_mm_yy
            $month = $dt->format('m');
            $year = $dt->format('y');
            
            $headerTable = "tblsaleheader_" . $month . "_" . $year;
            $detailsTable = "tblsaledetails_" . $month . "_" . $year;
            
            // Check if archive tables exist
            $headerCheck = $conn->query("SHOW TABLES LIKE '$headerTable'");
            $detailsCheck = $conn->query("SHOW TABLES LIKE '$detailsTable'");
            
            if ($headerCheck->num_rows > 0 && $detailsCheck->num_rows > 0) {
                $tables[$year_month] = [
                    'header' => $headerTable,
                    'details' => $detailsTable
                ];
            } else {
                // If archive tables don't exist, fall back to base tables
                $tables[$year_month] = [
                    'header' => "tblsaleheader",
                    'details' => "tblsaledetails"
                ];
            }
        }
    }
    
    return $tables;
}

// Define categories based on the image format - UPDATED WITH LICENSE RESTRICTIONS - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
$categories = [
    'IMFL Sales' => ['classes' => array_intersect(['W', 'G', 'K', 'D', 'R', 'O'], $allowed_classes), 'liq_flag' => 'F'],
    'Imported Spirit Sales' => ['classes' => array_intersect(['I'], $allowed_classes), 'liq_flag' => 'F'],
    'Wine Sales' => ['classes' => array_intersect(['V'], $allowed_classes), 'liq_flag' => 'F'],
    'Imported Wine Sales' => ['classes' => array_intersect(['W'], $allowed_classes), 'liq_flag' => 'F'],
    'Fermented Beer Sales' => ['classes' => array_intersect(['F'], $allowed_classes), 'liq_flag' => 'F'],
    'Mild Beer Sales' => ['classes' => array_intersect(['M'], $allowed_classes), 'liq_flag' => 'F'],
    'Country Sales' => ['classes' => array_intersect(['L', 'O'], $allowed_classes), 'liq_flag' => 'C']
];

// Remove empty categories (where no classes are allowed by license)
$categories = array_filter($categories, function($category) {
    return !empty($category['classes']);
});

// Generate report data based on filters
$report_data = [];
$daily_totals = [];

if (isset($_GET['generate'])) {
    // Get sales tables for the date range
    $salesTables = getSalesTablesForDateRange($conn, $compID, $date_from, $date_to);
    
    // Get all dates in the range from all relevant tables
    $allDates = [];
    foreach ($salesTables as $month => $tables) {
        $headerTable = $tables['header'];
        
        $dateQuery = "SELECT DISTINCT DATE(BILL_DATE) as sale_date 
                      FROM $headerTable 
                      WHERE BILL_DATE BETWEEN ? AND ? AND COMP_ID = ?
                      ORDER BY sale_date";
        $dateStmt = $conn->prepare($dateQuery);
        $dateStmt->bind_param("ssi", $date_from, $date_to, $compID);
        $dateStmt->execute();
        $dateResult = $dateStmt->get_result();
        
        while ($dateRow = $dateResult->fetch_assoc()) {
            $sale_date = $dateRow['sale_date'];
            if (!in_array($sale_date, $allDates)) {
                $allDates[] = $sale_date;
                
                // Initialize report data for this date
                $report_data[$sale_date] = [];
                $daily_totals[$sale_date] = 0;
                
                // Initialize all categories for this date
                foreach ($categories as $category_name => $category_info) {
                    $report_data[$sale_date][$category_name] = [
                        'min_bill' => null,
                        'max_bill' => null,
                        'amount' => 0
                    ];
                }
            }
        }
        $dateStmt->close();
    }
    
    sort($allDates); // Sort dates chronologically

    // Get sales data with bill ranges for each category and date from all relevant tables
    $rate_field = ($rate_type === 'mrp') ? 'MPRICE' : 'BPRICE';
    
    foreach ($salesTables as $month => $tables) {
        $headerTable = $tables['header'];
        $detailsTable = $tables['details'];
        
        // Build query with license restrictions
        $query = "SELECT 
                    DATE(s.BILL_DATE) as sale_date,
                    i.CLASS as SGROUP,
                    i.LIQ_FLAG,
                    MIN(s.BILL_NO) as min_bill,
                    MAX(s.BILL_NO) as max_bill,
                    SUM(COALESCE(i.$rate_field, sd.RATE) * sd.QTY) as total_sale
                  FROM $detailsTable sd
                  INNER JOIN $headerTable s ON sd.BILL_NO = s.BILL_NO AND sd.COMP_ID = s.COMP_ID
                  LEFT JOIN tblitemmaster i ON sd.ITEM_CODE = i.CODE
                  WHERE s.BILL_DATE BETWEEN ? AND ? AND s.COMP_ID = ?";
        
        // Add license class restrictions if there are allowed classes
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
            $query .= " AND i.CLASS IN ($class_placeholders)";
        } else {
            // If no classes allowed, show no results
            $query .= " AND 1 = 0";
        }
        
        $query .= " GROUP BY DATE(s.BILL_DATE), i.CLASS, i.LIQ_FLAG
                  ORDER BY sale_date, i.CLASS";
        
        $stmt = $conn->prepare($query);
        
        // Bind parameters based on whether we have class restrictions
        if (!empty($allowed_classes)) {
            $params = array_merge([$date_from, $date_to, $compID], $allowed_classes);
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        } else {
            $stmt->bind_param("ssi", $date_from, $date_to, $compID);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $sgroup = isset($row['SGROUP']) ? $row['SGROUP'] : 'O';
            $liq_flag = isset($row['LIQ_FLAG']) ? $row['LIQ_FLAG'] : 'F';
            $amount = (float)$row['total_sale'];
            $sale_date = $row['sale_date'];
            $min_bill = $row['min_bill'];
            $max_bill = $row['max_bill'];
            
            // Determine which category this item belongs to
            $item_category = null;
            foreach ($categories as $category_name => $category_info) {
                if ($category_info['liq_flag'] === $liq_flag && in_array($sgroup, $category_info['classes'])) {
                    $item_category = $category_name;
                    break;
                }
            }
            
            // If we couldn't classify the item, assign to IMFL Sales as default (if allowed)
            if ($item_category === null && in_array($sgroup, $categories['IMFL Sales']['classes'] ?? [])) {
                $item_category = 'IMFL Sales';
            }
            
            // Update category data only if category exists and is allowed
            if ($item_category !== null && isset($report_data[$sale_date][$item_category])) {
                // If this category already has data, update min/max bills and amount
                if ($report_data[$sale_date][$item_category]['min_bill'] === null || 
                    $min_bill < $report_data[$sale_date][$item_category]['min_bill']) {
                    $report_data[$sale_date][$item_category]['min_bill'] = $min_bill;
                }
                
                if ($report_data[$sale_date][$item_category]['max_bill'] === null || 
                    $max_bill > $report_data[$sale_date][$item_category]['max_bill']) {
                    $report_data[$sale_date][$item_category]['max_bill'] = $max_bill;
                }
                
                $report_data[$sale_date][$item_category]['amount'] += $amount;
            }
            
            $daily_totals[$sale_date] += $amount;
        }
        $stmt->close();
    }

    // Get bill ranges for categories with zero sales but have bills on that date from all relevant tables
    foreach ($salesTables as $month => $tables) {
        $headerTable = $tables['header'];
        
        $billRangeQuery = "SELECT 
                            DATE(BILL_DATE) as sale_date,
                            MIN(BILL_NO) as min_bill,
                            MAX(BILL_NO) as max_bill
                          FROM $headerTable 
                          WHERE BILL_DATE BETWEEN ? AND ? AND COMP_ID = ?
                          GROUP BY DATE(BILL_DATE)
                          ORDER BY sale_date";
        
        $billStmt = $conn->prepare($billRangeQuery);
        $billStmt->bind_param("ssi", $date_from, $date_to, $compID);
        $billStmt->execute();
        $billResult = $billStmt->get_result();
        
        while ($billRow = $billResult->fetch_assoc()) {
            $sale_date = $billRow['sale_date'];
            $overall_min_bill = $billRow['min_bill'];
            $overall_max_bill = $billRow['max_bill'];
            
            // For categories with zero sales but bills exist on that date, set the bill range
            foreach ($categories as $category_name => $category_info) {
                if (isset($report_data[$sale_date][$category_name]) && 
                    $report_data[$sale_date][$category_name]['amount'] == 0 &&
                    $report_data[$sale_date][$category_name]['min_bill'] === null) {
                    
                    $report_data[$sale_date][$category_name]['min_bill'] = $overall_min_bill;
                    $report_data[$sale_date][$category_name]['max_bill'] = $overall_max_bill;
                }
            }
        }
        $billStmt->close();
    }
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
      font-size: 14px;
    }
    
    .report-table th, .report-table td {
      padding: 6px 8px;
      border: 1px solid #dee2e6;
      vertical-align: top;
    }
    
    .report-table th {
      background-color: #f8f9fa;
      font-weight: bold;
      text-align: center;
    }
    
    .report-table .text-right {
      text-align: right;
    }
    
    .report-table .text-center {
      text-align: center;
    }
    
    .report-table .sr-no {
      width: 50px;
      text-align: center;
    }
    
    .report-table .date-col {
      width: 100px;
      text-align: center;
    }
    
    .report-table .particulars-col {
      width: 180px;
    }
    
    .report-table .bills-col {
      width: 150px;
      text-align: center;
    }
    
    .report-table .amount-col {
      width: 120px;
      text-align: right;
    }
    
    .total-row {
      font-weight: bold;
      background-color: #e9ecef;
      border-bottom: double 3px #000;
    }
    
    .daily-total-row {
      font-weight: bold;
      background-color: #f1f3f4;
    }
    
    .footer-info {
      text-align: center;
      margin-top: 20px;
      font-size: 14px;
      color: #6c757d;
    }
    
    .license-info {
      background-color: #e7f3ff;
      border-left: 4px solid #0d6efd;
      padding: 10px 15px;
      margin-bottom: 15px;
      border-radius: 4px;
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
        font-size: 12px;
      }
      
      .report-table {
        font-size: 12px;
      }
      
      .report-table th, .report-table td {
        padding: 4px 6px;
      }
      
      .license-info {
        display: none;
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

      <!-- License Restriction Info -->
      <div class="license-info no-print">
        <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
        <p class="mb-0">Showing sales data for classes: 
            <?php 
            if (!empty($available_classes)) {
                $class_names = [];
                foreach ($available_classes as $class) {
                    $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                }
                echo implode(', ', $class_names);
            } else {
                echo 'No classes available for your license type';
            }
            ?>
        </p>
      </div>

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
              <button type="button" class="btn btn-info" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-1"></i> Export to Excel
              </button>
              <button type="button" class="btn btn-warning" onclick="exportToCSV()">
                <i class="fas fa-file-csv me-1"></i> Export to CSV
              </button>
              <button type="button" class="btn btn-danger" onclick="exportToPDF()">
                <i class="fas fa-file-pdf me-1"></i> Export to PDF
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
            <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
          </div>
          
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th class="sr-no">Sr.No.</th>
                  <th class="date-col">Date</th>
                  <th class="particulars-col">Particulars</th>
                  <th class="bills-col">[Bill's From - To]</th>
                  <th class="amount-col">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $sr_no = 1;
                $grand_total = 0;
                ksort($report_data); // Sort by date
                ?>
                
                <?php foreach ($report_data as $date => $categories_data): ?>
                  <?php 
                  $date_total = 0;
                  $date_printed = false;
                  $has_sales = false;
                  
                  // Check if any category has sales for this date
                  foreach ($categories_data as $category_data) {
                    if ($category_data['amount'] > 0) {
                      $has_sales = true;
                      break;
                    }
                  }
                  
                  // Only show date if it has sales
                  if ($has_sales): 
                  ?>
                  
                  <?php foreach ($categories as $category_name => $category_info): ?>
                    <?php 
                    $category_data = $categories_data[$category_name];
                    $min_bill = $category_data['min_bill'];
                    $max_bill = $category_data['max_bill'];
                    $amount = $category_data['amount'];
                    
                    // Only show category if it has sales
                    if ($amount > 0):
                    ?>
                      <tr>
                        <?php if (!$date_printed): ?>
                          <td class="sr-no text-center"><?= $sr_no ?></td>
                          <td class="date-col"><?= date('d/m/Y', strtotime($date)) ?></td>
                          <?php $date_printed = true; ?>
                        <?php else: ?>
                          <td class="sr-no"></td>
                          <td class="date-col"></td>
                        <?php endif; ?>
                        
                        <td class="particulars-col"><?= $category_name ?></td>
                        <td class="bills-col text-center"><?= $min_bill ?> - <?= $max_bill ?></td>
                        <td class="amount-col text-right"><?= number_format($amount, 2) ?></td>
                      </tr>
                      
                      <?php $date_total += $amount; ?>
                    <?php endif; ?>
                  <?php endforeach; ?>
                  
                  <!-- Daily total row -->
                  <tr class="daily-total-row">
                    <td class="sr-no"></td>
                    <td class="date-col"></td>
                    <td class="particulars-col"></td>
                    <td class="bills-col text-center"><strong>Total</strong></td>
                    <td class="amount-col text-right"><strong><?= number_format($date_total, 2) ?></strong></td>
                  </tr>
                  
                  <?php 
                  $grand_total += $date_total;
                  $sr_no++;
                  ?>
                  <?php endif; ?>
                <?php endforeach; ?>
                
                <!-- Grand total row -->
                <tr class="total-row">
                  <td class="sr-no"></td>
                  <td class="date-col"></td>
                  <td class="particulars-col"></td>
                  <td class="bills-col text-center"><strong>Grand Total</strong></td>
                  <td class="amount-col text-right"><strong><?= number_format($grand_total, 2) ?></strong></td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <div class="footer-info">
            Generated on: <?= date('d-M-Y h:i A') ?> | Generated by: <?= $_SESSION['username'] ?? 'System' ?> | License Type: <?= htmlspecialchars($license_type) ?>
          </div>
        </div>
      <?php elseif (isset($_GET['generate'])): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No sales records found for the selected criteria.
          <?php if (empty($allowed_classes)): ?>
            <br><small>No license classes are available for your company.</small>
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
function exportToExcel() {
    // Get the table element
    var table = document.querySelector('.report-table');

    // Create a new workbook
    var wb = XLSX.utils.book_new();

    // Clone the table to avoid modifying the original
    var tableClone = table.cloneNode(true);

    // Convert table to worksheet
    var ws = XLSX.utils.table_to_sheet(tableClone);

    // Add worksheet to workbook
    XLSX.utils.book_append_sheet(wb, ws, 'Sales Register');

    // Generate Excel file and download
    var fileName = 'Sales_Register_<?= date('Y-m-d') ?>.xlsx';
    XLSX.writeFile(wb, fileName);
}

function exportToCSV() {
    // Get the table element
    var table = document.querySelector('.report-table');

    // Convert table to worksheet
    var ws = XLSX.utils.table_to_sheet(table);

    // Generate CSV file and download
    var fileName = 'Sales_Register_<?= date('Y-m-d') ?>.csv';
    XLSX.writeFile(ws, fileName);
}

function exportToPDF() {
    // Use html2pdf library to convert the report section to PDF
    const element = document.querySelector('.print-section');
    const opt = {
        margin: 0.5,
        filename: 'Sales_Register_<?= date('Y-m-d') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' }
    };

    // New Promise-based usage:
    html2pdf().set(opt).from(element).save();
}

// Load XLSX library dynamically
if (typeof XLSX === 'undefined') {
    var script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    script.onload = function() {
        console.log('XLSX library loaded');
    };
    document.head.appendChild(script);
}

// Load html2pdf library dynamically
if (typeof html2pdf === 'undefined') {
    var script2 = document.createElement('script');
    script2.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
    script2.onload = function() {
        console.log('html2pdf library loaded');
    };
    document.head.appendChild(script2);
}
</script>
</body>
</html>