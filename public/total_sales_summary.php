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

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Get parameters - using same date format as groupwise_sales_report.php (YYYY-MM-DD)
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F'; // F for Foreign Liquor, C for Country Liquor

// Convert date format for display (from YYYY-MM-DD to DD/MM/YYYY for internal processing)
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

// Get daily stock table name based on company ID
$daily_stock_table = "tbldailystock_" . $_SESSION['CompID'];

// Fetch company name from tblcompany
$companyQuery = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$stmt = $conn->prepare($companyQuery);
$stmt->bind_param("i", $_SESSION['CompID']);
$stmt->execute();
$companyResult = $stmt->get_result();
$company = $companyResult->fetch_assoc();
$companyName = $company['COMP_NAME'] ?? 'DIAMOND WINE SHOP';

// Define size columns exactly as shown in the image
$size_columns = [
    '4.5 L', '3 L', '2 L', '1 Ltr', '750 ML', '650 ML', '500 ML', 
    '375 ML', '330 ML', '325 ML', '180 ML', '90 ML', '60 ML'
];

// Define groups based on liquor types (similar to groupwise_sales_report.php)
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

// Generate date range for display
$date_range = [];
$current_date = $db_date_from;
while (strtotime($current_date) <= strtotime($db_date_to)) {
    $date_range[] = [
        'db_date' => $current_date,
        'display_date' => date('d/m/Y', strtotime($current_date)),
        'day_column' => 'DAY_' . date('d', strtotime($current_date)) . '_SALES'
    ];
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// Function to get base size for grouping (matching the image format)
function getSalesBaseSize($size) {
    // Map sizes to match the column headers in the image
    $size_mapping = [
        '4500 ML' => '4.5 L',
        '4500 ML PET' => '4.5 L',
        '3000 ML' => '3 L', 
        '2000 ML' => '2 L',
        '1000 ML' => '1 Ltr',
        '750 ML' => '750 ML',
        '650 ML' => '650 ML',
        '500 ML' => '500 ML',
        '375 ML' => '375 ML',
        '330 ML' => '330 ML',
        '325 ML' => '325 ML',
        '180 ML' => '180 ML',
        '90 ML' => '90 ML',
        '60 ML' => '60 ML'
    ];
    
    $base_size = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $base_size = preg_replace('/\s*-\s*\d+$/', '', $base_size);
    $base_size = preg_replace('/\s*\(\d+\)$/', '', $base_size);
    $base_size = preg_replace('/\s*\([^)]*\)/', '', $base_size);
    $base_size = trim($base_size);
    
    // Convert to uppercase for consistent matching
    $base_size_upper = strtoupper($base_size);
    
    foreach ($size_mapping as $db_size => $display_size) {
        if (strpos($base_size_upper, $db_size) !== false) {
            return $display_size;
        }
    }
    
    // Default mapping based on common patterns
    if (strpos($base_size_upper, '4500') !== false) return '4.5 L';
    if (strpos($base_size_upper, '3000') !== false) return '3 L';
    if (strpos($base_size_upper, '2000') !== false) return '2 L';
    if (strpos($base_size_upper, '1000') !== false) return '1 Ltr';
    if (strpos($base_size_upper, '750') !== false) return '750 ML';
    if (strpos($base_size_upper, '650') !== false) return '650 ML';
    if (strpos($base_size_upper, '500') !== false) return '500 ML';
    if (strpos($base_size_upper, '375') !== false) return '375 ML';
    if (strpos($base_size_upper, '330') !== false) return '330 ML';
    if (strpos($base_size_upper, '325') !== false) return '325 ML';
    if (strpos($base_size_upper, '180') !== false) return '180 ML';
    if (strpos($base_size_upper, '90') !== false) return '90 ML';
    if (strpos($base_size_upper, '60') !== false) return '60 ML';
    
    return $base_size;
}

// Fetch sales data - using same logic as groupwise_sales_report.php
$sales_data = [];
$group_totals = [];
$date_totals = array_fill_keys(array_column($date_range, 'display_date'), 0);
$size_totals = array_fill_keys($size_columns, 0);
$grand_total = 0;

// Initialize group totals
foreach ($groups as $group_key => $group_info) {
    $group_totals[$group_key] = [
        'sizes' => array_fill_keys($size_columns, 0),
        'dates' => array_fill_keys(array_column($date_range, 'display_date'), 0),
        'total' => 0
    ];
}

// Check which tables have data (same logic as groupwise_sales_report.php)
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
    // Use tblcustomersales table
    $sales_query = "SELECT
                    cs.BillDate as BILL_DATE,
                    cs.ItemCode as ITEM_CODE,
                    cs.ItemName as ITEM_NAME,
                    cs.Quantity as QTY,
                    im.DETAILS2 as SIZE,
                    im.CLASS as SGROUP,
                    im.LIQ_FLAG
                  FROM tblcustomersales cs
                  LEFT JOIN tblitemmaster im ON cs.ItemCode = im.CODE
                  WHERE cs.BillDate BETWEEN ? AND ? AND cs.CompID = ?
                  ORDER BY cs.BillDate";

    $stmt = $conn->prepare($sales_query);
    $stmt->bind_param("ssi", $db_date_from, $db_date_to, $company_id);
} else {
    // Use tblsaleheader and tblsaledetails tables
    $sales_query = "SELECT
                    sh.BILL_DATE,
                    sd.ITEM_CODE,
                    CASE WHEN im.Print_Name != '' THEN im.Print_Name ELSE im.DETAILS END as ITEM_NAME,
                    sd.QTY,
                    im.DETAILS2 as SIZE,
                    im.CLASS as SGROUP,
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

// Process sales data by groups
while ($row = $result->fetch_assoc()) {
    $sgroup = isset($row['SGROUP']) ? $row['SGROUP'] : 'O';
    $liq_flag = isset($row['LIQ_FLAG']) ? $row['LIQ_FLAG'] : 'F';
    $bill_date = $row['BILL_DATE'];
    $display_date = date('d/m/Y', strtotime($bill_date));
    $quantity = (float)$row['QTY'];
    $size = $row['SIZE'] ?? '';
    $base_size = getSalesBaseSize($size);

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

    // Filter by mode (Foreign Liquor or Country Liquor)
    if ($mode === 'F' && $item_group === 'COUNTRY LIQUOR') continue;
    if ($mode === 'C' && $item_group !== 'COUNTRY LIQUOR') continue;

    // Only include if the size exists in our display columns
    if (in_array($base_size, $size_columns) && $quantity > 0) {
        // Initialize if not exists
        if (!isset($sales_data[$item_group][$base_size][$display_date])) {
            $sales_data[$item_group][$base_size][$display_date] = 0;
        }

        // Add to sales data
        $sales_data[$item_group][$base_size][$display_date] += $quantity;

        // Update totals
        $group_totals[$item_group]['sizes'][$base_size] += $quantity;
        $group_totals[$item_group]['dates'][$display_date] += $quantity;
        $group_totals[$item_group]['total'] += $quantity;

        $date_totals[$display_date] += $quantity;
        $size_totals[$base_size] += $quantity;
        $grand_total += $quantity;
    }
}

$stmt->close();

// Format dates for report display
$report_display_date_from = date('d-M-Y', strtotime($db_date_from));
$report_display_date_to = date('d-M-Y', strtotime($db_date_to));
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
  <!-- Include shortcuts functionality -->
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

      <!-- Filter Form - Using same date selection as groupwise_sales_report.php -->
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
            <p>Mode: <?= $mode === 'F' ? 'Foreign Liquor' : ($mode === 'C' ? 'Country Liquor' : 'Foreign Liquor Country Liquor') ?></p>
          </div>
        </div>

        <?php foreach ($groups as $group_key => $group_info): ?>
          <?php if (isset($sales_data[$group_key]) || $mode === 'B' || ($mode === 'F' && $group_key !== 'COUNTRY LIQUOR') || ($mode === 'C' && $group_key === 'COUNTRY LIQUOR')): ?>
            <div class="category-section">
              <h4 class="group-header"><?= strtoupper($group_info['name']) ?></h4>
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
                          $quantity = $sales_data[$group_key][$size][$date_info['display_date']] ?? 0;
                          $date_total += $quantity;
                        ?>
                          <td class="text-right"><?= $quantity > 0 ? number_format($quantity, 0) : '' ?></td>
                        <?php endforeach; ?>
                        <td class="text-right" style="font-weight: bold;"><?= $date_total > 0 ? number_format($date_total, 0) : '' ?></td>
                      </tr>
                    <?php endforeach; ?>
                    
                    <!-- Group Total Row -->
                    <tr class="total-row">
                      <td style="font-weight: bold;">Total</td>
                      <?php
                      $group_size_total = 0;
                      foreach ($size_columns as $size):
                        $size_total = $group_totals[$group_key]['sizes'][$size] ?? 0;
                        $group_size_total += $size_total;
                      ?>
                        <td class="text-right" style="font-weight: bold;">
                          <?= $size_total > 0 ? number_format($size_total, 0) : '' ?>
                        </td>
                      <?php endforeach; ?>
                      <td class="text-right" style="font-weight: bold;">
                        <?= $group_totals[$group_key]['total'] > 0 ? number_format($group_totals[$group_key]['total'], 0) : '' ?>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>

        <!-- Grand Total Section -->
        <div class="table-container">
          <table class="report-table">
            <tr class="total-row">
              <td colspan="<?= count($size_columns) + 2 ?>" style="text-align: center; font-weight: bold;">
                Total Sales (Bulk Ltrs.): <?= number_format($grand_total, 2) ?> Ltrs.
              </td>
            </tr>
          </table>
        </div>

        
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

// No need for datepicker initialization since we're using native date inputs
$(document).ready(function() {
  // Any other initialization code if needed
});
</script>
</body>
</html>
