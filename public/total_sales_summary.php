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

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Get parameters
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F'; // F for Foreign Liquor, C for Country Liquor

// Convert date format
function convertToDisplayDate($date_str) {
    return date('d/m/Y', strtotime($date_str));
}

function convertToDbDate($date_str) {
    return date('Y-m-d', strtotime($date_str));
}

$display_date_from = convertToDisplayDate($date_from);
$display_date_to = convertToDisplayDate($date_to);
$db_date_from = convertToDbDate($date_from);
$db_date_to = convertToDbDate($date_to);

// Fetch company name from tblcompany
$companyQuery = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$stmt = $conn->prepare($companyQuery);
$stmt->bind_param("i", $_SESSION['CompID']);
$stmt->execute();
$companyResult = $stmt->get_result();
$company = $companyResult->fetch_assoc();
$companyName = $company['COMP_NAME'] ?? 'DIAMOND WINE SHOP';

// Define size columns for display
$size_columns = [
    '4.5 L', '3 L', '2 L', '1 Ltr', '750 ML', '650 ML', '500 ML', 
    '375 ML', '330 ML', '325 ML', '180 ML', '90 ML', '60 ML'
];

// Get all classes from tblclass_new with their info
$classes_query = "SELECT 
                    c.CLASS_CODE,
                    c.CLASS_NAME,
                    c.LIQ_FLAG,
                    cat.CATEGORY_NAME
                  FROM tblclass_new c
                  LEFT JOIN tblcategory cat ON c.CATEGORY_CODE = cat.CATEGORY_CODE
                  ORDER BY c.LIQ_FLAG, c.CLASS_NAME";
$classes_result = $conn->query($classes_query);

$groups = [];
while ($class_row = $classes_result->fetch_assoc()) {
    $class_code = $class_row['CLASS_CODE'];
    $class_name = $class_row['CLASS_NAME'];
    $liq_flag = $class_row['LIQ_FLAG'];
    $category_name = $class_row['CATEGORY_NAME'];
    
    // Only include classes that match the selected mode
    if (($mode === 'F' && $liq_flag === 'F') || 
        ($mode === 'C' && $liq_flag === 'C') || 
        $mode === 'B') {
        
        $groups[$class_code] = [
            'name' => $class_name,
            'category' => $category_name,
            'liq_flag' => $liq_flag,
            'class_code' => $class_code
        ];
    }
}

// Get size mapping from tblsize
$size_query = "SELECT SIZE_CODE, SIZE_DESC, ML_VOLUME FROM tblsize";
$size_result = $conn->query($size_query);
$size_mapping = [];
$size_to_display = [];

while ($size_row = $size_result->fetch_assoc()) {
    $size_code = $size_row['SIZE_CODE'];
    $size_desc = $size_row['SIZE_DESC'];
    $ml_volume = $size_row['ML_VOLUME'];
    
    // Map size code to display size
    $display_size = 'Other';
    if ($ml_volume >= 4000) $display_size = '4.5 L';
    elseif ($ml_volume >= 3000) $display_size = '3 L';
    elseif ($ml_volume >= 2000) $display_size = '2 L';
    elseif ($ml_volume >= 1000) $display_size = '1 Ltr';
    elseif ($ml_volume >= 750) $display_size = '750 ML';
    elseif ($ml_volume >= 650) $display_size = '650 ML';
    elseif ($ml_volume >= 500) $display_size = '500 ML';
    elseif ($ml_volume >= 375) $display_size = '375 ML';
    elseif ($ml_volume >= 330) $display_size = '330 ML';
    elseif ($ml_volume >= 325) $display_size = '325 ML';
    elseif ($ml_volume >= 180) $display_size = '180 ML';
    elseif ($ml_volume >= 90) $display_size = '90 ML';
    elseif ($ml_volume >= 60) $display_size = '60 ML';
    
    $size_mapping[$size_code] = [
        'desc' => $size_desc,
        'ml' => $ml_volume,
        'display' => $display_size
    ];
    $size_to_display[$size_code] = $display_size;
}

// Generate date range
$date_range = [];
$current_date = $db_date_from;
while (strtotime($current_date) <= strtotime($db_date_to)) {
    $date_range[] = [
        'db_date' => $current_date,
        'display_date' => date('d/m/Y', strtotime($current_date))
    ];
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// Initialize data structures
$sales_data = [];
$group_totals = [];
$date_totals = array_fill_keys(array_column($date_range, 'display_date'), 0);
$size_totals = array_fill_keys($size_columns, 0);
$grand_total = 0;

// Initialize group totals
foreach ($groups as $class_code => $group_info) {
    $group_totals[$class_code] = [
        'sizes' => array_fill_keys($size_columns, 0),
        'dates' => array_fill_keys(array_column($date_range, 'display_date'), 0),
        'total' => 0
    ];
}

// Check which tables have data
$check_tables = [];

$check_query = "SELECT COUNT(*) as count FROM tblsaleheader WHERE BILL_DATE BETWEEN ? AND ? AND COMP_ID = ?";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param("ssi", $db_date_from, $db_date_to, $company_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();
$row = $check_result->fetch_assoc();
$check_tables['tblsaleheader'] = $row['count'];
$check_stmt->close();

$check_query = "SELECT COUNT(*) as count FROM tblcustomersales WHERE BillDate BETWEEN ? AND ? AND CompID = ?";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param("ssi", $db_date_from, $db_date_to, $company_id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();
$row = $check_result->fetch_assoc();
$check_tables['tblcustomersales'] = $row['count'];
$check_stmt->close();

// Determine which table to use
$use_customer_sales = ($check_tables['tblcustomersales'] > 0);

if ($use_customer_sales) {
    // Use tblcustomersales table with new column structure
    $sales_query = "SELECT
                    cs.BillDate as BILL_DATE,
                    cs.ItemCode as ITEM_CODE,
                    cs.ItemName as ITEM_NAME,
                    cs.Quantity as QTY,
                    im.SIZE_CODE,
                    im.CLASS_CODE_NEW,
                    im.LIQ_FLAG
                  FROM tblcustomersales cs
                  LEFT JOIN tblitemmaster im ON cs.ItemCode = im.CODE
                  WHERE cs.BillDate BETWEEN ? AND ? AND cs.CompID = ?
                  ORDER BY cs.BillDate";

    $stmt = $conn->prepare($sales_query);
    $stmt->bind_param("ssi", $db_date_from, $db_date_to, $company_id);
} else {
    // Use tblsaleheader and tblsaledetails tables with new column structure
    $sales_query = "SELECT
                    sh.BILL_DATE,
                    sd.ITEM_CODE,
                    CASE WHEN im.Print_Name != '' THEN im.Print_Name ELSE im.DETAILS END as ITEM_NAME,
                    sd.QTY,
                    im.SIZE_CODE,
                    im.CLASS_CODE_NEW,
                    im.LIQ_FLAG
                  FROM tblsaleheader sh
                  INNER JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
                  LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                  WHERE sh.BILL_DATE BETWEEN ? AND ? AND sh.COMP_ID = ?
                  ORDER BY sh.BILL_DATE";

    $stmt = $conn->prepare($sales_query);
    $stmt->bind_param("ssi", $db_date_from, $db_date_to, $company_id);
}

$stmt->execute();
$result = $stmt->get_result();

// Process sales data
$row_count = 0;
while ($row = $result->fetch_assoc()) {
    $row_count++;
    $class_code_new = isset($row['CLASS_CODE_NEW']) ? $row['CLASS_CODE_NEW'] : '';
    $liq_flag = isset($row['LIQ_FLAG']) ? $row['LIQ_FLAG'] : 'F';
    $bill_date = $row['BILL_DATE'];
    $display_date = date('d/m/Y', strtotime($bill_date));
    $quantity = (float)$row['QTY'];
    $size_code = $row['SIZE_CODE'] ?? '';
    
    // Skip if no class code or class not in our groups
    if (empty($class_code_new) || !isset($groups[$class_code_new])) {
        continue;
    }
    
    // Filter by mode
    if ($mode === 'F' && $liq_flag !== 'F') continue;
    if ($mode === 'C' && $liq_flag !== 'C') continue;
    
    // Get display size from size code
    $display_size = isset($size_to_display[$size_code]) ? $size_to_display[$size_code] : 'Other';
    
    // Only include if size is in our display columns
    if (in_array($display_size, $size_columns) && $quantity > 0) {
        // Initialize nested arrays if needed
        if (!isset($sales_data[$class_code_new])) {
            $sales_data[$class_code_new] = [];
        }
        if (!isset($sales_data[$class_code_new][$display_size])) {
            $sales_data[$class_code_new][$display_size] = [];
        }
        if (!isset($sales_data[$class_code_new][$display_size][$display_date])) {
            $sales_data[$class_code_new][$display_size][$display_date] = 0;
        }
        
        // Add to sales data
        $sales_data[$class_code_new][$display_size][$display_date] += $quantity;
        
        // Update totals
        if (isset($group_totals[$class_code_new])) {
            $group_totals[$class_code_new]['sizes'][$display_size] += $quantity;
            $group_totals[$class_code_new]['dates'][$display_date] += $quantity;
            $group_totals[$class_code_new]['total'] += $quantity;
        }
        
        $date_totals[$display_date] += $quantity;
        $size_totals[$display_size] += $quantity;
        $grand_total += $quantity;
    }
}

$stmt->close();

// Debug: Check if we have any data
$has_any_data = ($row_count > 0);

// Format dates for report display
$report_display_date_from = date('d-M-Y', strtotime($db_date_from));
$report_display_date_to = date('d-M-Y', strtotime($db_date_to));

// Get mode display name
$mode_display = 'All';
if ($mode === 'F') $mode_display = 'Foreign Liquor';
if ($mode === 'C') $mode_display = 'Country Liquor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Total Sales Summary - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>"> 
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>"> 
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>"> 
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    .size-column, .date-column {
        text-align: center;
        min-width: 70px;
    }
    .group-header {
        background-color: #f0f0f0;
        padding: 8px;
        margin-top: 20px;
        border-left: 4px solid #007bff;
        font-weight: bold;
    }
    .total-row {
        background-color: #e9ecef;
        font-weight: bold;
    }
    .table-container {
        overflow-x: auto;
    }
    .report-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        font-size: 12px;
    }
    .report-table th, .report-table td {
        border: 1px solid #ddd;
        padding: 4px;
        text-align: center;
    }
    .report-table th {
        background-color: #f8f9fa;
        font-weight: bold;
    }
    .print-content {
        display: none;
    }
    .license-info {
        background-color: #e7f3ff;
        border-left: 4px solid #0d6efd;
        padding: 10px 15px;
        margin-bottom: 15px;
        border-radius: 4px;
    }
    .mode-buttons .btn {
        min-width: 180px;
    }
    .date-header {
        background-color: #e9ecef;
        font-weight: bold;
    }
    .debug-info {
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        padding: 10px;
        margin-bottom: 15px;
        border-radius: 4px;
        color: #721c24;
    }
    @media print {
        .no-print {
            display: none !important;
        }
        .print-content {
            display: block !important;
        }
        .report-table {
            font-size: 10px;
        }
        .report-table th, .report-table td {
            padding: 2px;
        }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Total Sales Summary</h3>

      <!-- Debug Info (remove in production) -->
      <?php if (!$has_any_data): ?>
      <div class="debug-info no-print">
        <strong>Debug Information:</strong><br>
        Total rows fetched: <?= $row_count ?><br>
        Date range: <?= $db_date_from ?> to <?= $db_date_to ?><br>
        Mode: <?= $mode ?><br>
        Company ID: <?= $company_id ?><br>
        Using table: <?= $use_customer_sales ? 'tblcustomersales' : 'tblsaleheader' ?><br>
        Groups loaded: <?= count($groups) ?><br>
        <button type="button" class="btn btn-sm btn-danger mt-2" onclick="location.href='?date_from=2026-01-01&date_to=2026-12-31&mode=B'">Try All Data 2026</button>
      </div>
      <?php endif; ?>

      <!-- License Restriction Info -->
      <div class="license-info no-print">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
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

      <!-- Filter Form -->
      <div class="card mb-4 no-print">
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
              <div class="col-md-6">
                <label class="form-label">Mode:</label>
                <div class="btn-group w-100 mode-buttons" role="group">
                  <button type="submit" name="mode" value="F" 
                          class="btn btn-outline-primary <?= $mode === 'F' ? 'active' : '' ?>">
                    Foreign Liquor
                  </button>
                  <button type="submit" name="mode" value="C" 
                          class="btn btn-outline-primary <?= $mode === 'C' ? 'active' : '' ?>">
                    Country Liquor
                  </button>
                  <button type="submit" name="mode" value="B" 
                          class="btn btn-outline-primary <?= $mode === 'B' ? 'active' : '' ?>">
                    Both
                  </button>
                </div>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" class="btn btn-primary me-2">
                <i class="fas fa-filter"></i> Apply Filters
              </button>
              <button type="button" onclick="generateReport()" class="btn btn-success">
                <i class="fas fa-file-alt"></i> Generate Report
              </button>
              <button type="button" class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Print Report
              </button>
              <a href="dashboard.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-sign-out-alt"></i> Exit
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Report Content -->
      <div id="reportContent" class="print-content">
        <div class="report-header">
          <div class="print-header text-center">
            <h2><?= htmlspecialchars($companyName) ?></h2>
            <h4>Total Sales Summary Report</h4>
            <p>From <?= $report_display_date_from ?> To <?= $report_display_date_to ?></p>
            <p>Mode: <?= $mode_display ?></p>
          </div>
        </div>

        <?php 
        $has_data = false;
        foreach ($groups as $class_code => $group_info): 
          if (isset($sales_data[$class_code])):
            $has_data = true;
        ?>
            <div class="category-section">
              <h4 class="group-header">
                <?= strtoupper($group_info['name']) ?>
                <?php if ($group_info['category']): ?>
                  <small style="font-weight: normal; margin-left: 10px;">(<?= $group_info['category'] ?>)</small>
                <?php endif; ?>
              </h4>
              <div class="table-container">
                <table class="report-table">
                  <thead>
                    <tr>
                      <th>Sales Date</th>
                      <?php foreach ($size_columns as $size): ?>
                        <th class="text-right"><?= $size ?></th>
                      <?php endforeach; ?>
                      <th class="text-right">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($date_range as $date_info): ?>
                      <tr>
                        <td class="date-header"><?= $date_info['display_date'] ?></td>
                        <?php
                        $date_total = 0;
                        foreach ($size_columns as $size):
                          $quantity = isset($sales_data[$class_code][$size][$date_info['display_date']]) ? 
                                     $sales_data[$class_code][$size][$date_info['display_date']] : 0;
                          $date_total += $quantity;
                        ?>
                          <td class="text-right"><?= $quantity > 0 ? number_format($quantity, 0) : '-' ?></td>
                        <?php endforeach; ?>
                        <td class="text-right" style="font-weight: bold;">
                          <?= $date_total > 0 ? number_format($date_total, 0) : '-' ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    
                    <!-- Group Total Row -->
                    <tr class="total-row">
                      <td style="font-weight: bold;">Total</td>
                      <?php
                      $group_size_total = 0;
                      foreach ($size_columns as $size):
                        $size_total = isset($group_totals[$class_code]['sizes'][$size]) ? 
                                     $group_totals[$class_code]['sizes'][$size] : 0;
                        $group_size_total += $size_total;
                      ?>
                        <td class="text-right" style="font-weight: bold;">
                          <?= $size_total > 0 ? number_format($size_total, 0) : '-' ?>
                        </td>
                      <?php endforeach; ?>
                      <td class="text-right" style="font-weight: bold;">
                        <?= isset($group_totals[$class_code]['total']) && $group_totals[$class_code]['total'] > 0 ? 
                            number_format($group_totals[$class_code]['total'], 0) : '-' ?>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>

        <!-- Grand Total Section -->
        <?php if ($grand_total > 0): ?>
        <div class="table-container">
          <table class="report-table">
            <tr class="total-row">
              <td colspan="<?= count($size_columns) + 2 ?>" style="text-align: center; font-weight: bold;">
                Total Sales (Bulk Ltrs.): <?= number_format($grand_total, 2) ?> Ltrs.
              </td>
            </tr>
          </table>
        </div>
        <?php elseif (!$has_data): ?>
        <div class="alert alert-info text-center">
          No sales data found for the selected criteria.
          <?php if ($row_count > 0): ?>
          <br><small>(Found <?= $row_count ?> rows but none matched the class/size criteria)</small>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function generateReport() {
  document.getElementById('reportContent').style.display = 'block';
  window.scrollTo(0, document.getElementById('reportContent').offsetTop);
}

$(document).ready(function() {
  // Auto-generate report if filters are applied
  <?php if (isset($_GET['date_from']) || isset($_GET['mode'])): ?>
  generateReport();
  <?php endif; ?>
});
</script>
</body>
</html>