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

include_once "../config/db.php";
require_once 'license_functions.php';

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Get parameters
$date_as_on = isset($_GET['date_as_on']) ? $_GET['date_as_on'] : date('d/m/Y');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'D'; // D for Detailed, S for Summary

// Convert date format for database
$date_parts = explode('/', $date_as_on);
$db_date = count($date_parts) === 3 ? $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0] : date('Y-m-d');
$month_year = date('Y-m', strtotime($db_date));
$month_year_numeric = date('m_y', strtotime($db_date)); // Format: 04_25 for April 2025
$day = date('d', strtotime($db_date));

// Determine which stock table to use
$base_table = "tbldailystock_" . $_SESSION['CompID'];
$archived_table = "tbldailystock_" . $_SESSION['CompID'] . "_" . $month_year_numeric;

// Check if current month is April
$current_month = date('m');
$is_april = ($current_month == '04');

// Choose the correct table
if ($is_april) {
    $daily_stock_table = $base_table;
} else {
    $daily_stock_table = $archived_table;
}

// Fetch company name from tblcompany
$companyQuery = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$stmt = $conn->prepare($companyQuery);
$stmt->bind_param("i", $_SESSION['CompID']);
$stmt->execute();
$companyResult = $stmt->get_result();
$company = $companyResult->fetch_assoc();
$companyName = $company['COMP_NAME'] ?? 'DIAMOND WINE SHOP';

// Function to check if table exists
function tableExists($conn, $tableName) {
    $checkTable = "SHOW TABLES LIKE '$tableName'";
    $result = $conn->query($checkTable);
    return $result->num_rows > 0;
}

// Check if the selected stock table exists, if not try the alternative
if (!tableExists($conn, $daily_stock_table)) {
    if ($is_april) {
        // If current month is April but base table doesn't exist, try archived table
        $daily_stock_table = $archived_table;
    } else {
        // If not April and archived table doesn't exist, try base table
        $daily_stock_table = $base_table;
    }
    
    // Final check if neither table exists
    if (!tableExists($conn, $daily_stock_table)) {
        echo "<div class='alert alert-warning'>Stock data not available for the selected date.</div>";
        $items = [];
    }
}

// Fetch items with closing stock and rates - FILTERED BY LICENSE TYPE
if (!empty($allowed_classes) && !empty($daily_stock_table) && tableExists($conn, $daily_stock_table)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // For April data, we always use STK_MONTH = '2025-04' and DAY_01_CLOSING
    $stk_month = '2025-04'; // Fixed for April
    $day_column = 'DAY_01_CLOSING'; // Fixed for April 1st
    
    $query = "SELECT im.CODE, im.Print_Name, im.DETAILS, im.DETAILS2, im.CLASS, im.SUB_CLASS, 
                     im.ITEM_GROUP, im.PPRICE, im.BPRICE, im.LIQ_FLAG,
                     ds.{$day_column} as CLOSING_STOCK
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
              WHERE im.CLASS IN ($class_placeholders) AND im.PPRICE > 0";
    
    $params = array_merge([$stk_month], $allowed_classes);
    $types = str_repeat('s', count($params));
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
} else {
    // If no classes allowed or table doesn't exist, show empty result
    $query = "SELECT im.CODE, im.Print_Name, im.DETAILS, im.DETAILS2, im.CLASS, im.SUB_CLASS, 
                     im.ITEM_GROUP, im.PPRICE, im.BPRICE, im.LIQ_FLAG,
                     0 as CLOSING_STOCK
              FROM tblitemmaster im
              WHERE 1 = 0";
    
    $stmt = $conn->prepare($query);
}

$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);

// Rest of your existing code for processing and displaying the report...
// [Include all the existing functions and processing logic from your previous code]

// Function to get category name based on class and details
function getCategoryName($class, $details, $details2) {
    $details_upper = strtoupper($details);
    $details2_upper = strtoupper($details2);
    
    // Check for beer types first
    if (strpos($details2_upper, '650 E') !== false || strpos($details2_upper, '650 F') !== false) {
        return '650 E';
    } elseif (strpos($details2_upper, '650 M') !== false) {
        return '650 M';
    } elseif (strpos($details2_upper, '500 M') !== false) {
        return '500 M';
    } elseif (strpos($details2_upper, '500 F') !== false) {
        return '500 F';
    } elseif (strpos($details2_upper, '330 F') !== false) {
        return '330 F';
    } elseif (strpos($details2_upper, '330 M') !== false) {
        return '330 M';
    } elseif (strpos($details2_upper, '1 LTR') !== false || strpos($details2_upper, '1000 ML') !== false) {
        return '1 Ltr.';
    } elseif (strpos($details2_upper, '2 LTR') !== false || strpos($details2_upper, '2000 ML') !== false) {
        return '2 Ltrs.';
    } elseif (strpos($details2_upper, '60 ML') !== false) {
        return '60 Ml';
    } elseif (strpos($details2_upper, '90 ML') !== false) {
        return '90 Ml';
    }
    
    // Check for wine types
    if (strpos($details_upper, 'WINE') !== false || $class === 'V') {
        if (strpos($details2_upper, 'NIP') !== false || strpos($details2_upper, '90 ML') !== false || strpos($details2_upper, '60 ML') !== false) {
            return 'Wine Nip';
        } elseif (strpos($details2_upper, 'PINT') !== false || strpos($details2_upper, '375 ML') !== false) {
            return 'Wine Pint';
        } else {
            return 'Wine Quart';
        }
    }
    
    // Check for country liquor
    if ($class === 'C') {
        if (strpos($details2_upper, 'NIP') !== false || strpos($details2_upper, '90 ML') !== false) {
            return 'Nip';
        } elseif (strpos($details2_upper, 'QUART') !== false || strpos($details2_upper, '750 ML') !== false) {
            return 'Quart';
        } else {
            return '90 Ml';
        }
    }
    
    // Default to foreign liquor categories
    if (strpos($details2_upper, 'NIP') !== false || strpos($details2_upper, '90 ML') !== false || strpos($details2_upper, '60 ML') !== false) {
        return 'Nip';
    } elseif (strpos($details2_upper, 'PINT') !== false || strpos($details2_upper, '375 ML') !== false) {
        return 'Pint';
    } else {
        return 'Quart';
    }
}

// Organize items by category for detailed report
$detailed_categories = [];
$summary_data = [];

foreach ($items as $item) {
    $category = getCategoryName($item['CLASS'], $item['DETAILS'], $item['DETAILS2']);
    $closing_stock = (float)$item['CLOSING_STOCK'];
    $rate = (float)$item['PPRICE'];
    $amount = $closing_stock * $rate;
    
    // For detailed report
    if (!isset($detailed_categories[$category])) {
        $detailed_categories[$category] = [];
    }
    
    $detailed_categories[$category][] = [
        'description' => $item['DETAILS'],
        'closing_stock' => $closing_stock,
        'rate' => $rate,
        'amount' => $amount
    ];
    
    // For summary report
    if (!isset($summary_data[$category])) {
        $summary_data[$category] = [
            'closing_stock' => 0,
            'amount' => 0
        ];
    }
    
    $summary_data[$category]['closing_stock'] += $closing_stock;
    $summary_data[$category]['amount'] += $amount;
}

// Define category order for display (as per your PDF)
$category_order = [
    'Foreign Liquor' => ['Quart', 'Pint', 'Nip'],
    'Wine' => ['Wine Quart', 'Wine Pint', 'Wine Nip'],
    'Beer' => ['650 E', '650 M', '1 Ltr.', '60 Ml', '500 M', '500 F', '330 F', '330 M', '2 Ltrs.', '90 Ml'],
    'Country Liquor' => ['Quart', 'Nip', '90 Ml']
];

// Calculate totals
$grand_total_stock = 0;
$grand_total_amount = 0;

foreach ($summary_data as $category) {
    $grand_total_stock += $category['closing_stock'];
    $grand_total_amount += $category['amount'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stock Valuation Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>"> 
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>"> 
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>"> 
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    .report-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .report-table th, .report-table td {
        border: 1px solid #000;
        padding: 4px 8px;
        text-align: left;
    }
    .report-table th {
        background-color: #f0f0f0;
        font-weight: bold;
    }
    .category-header {
        background-color: #e0e0e0;
        font-weight: bold;
        font-size: 13px;
    }
    .subcategory-header {
        background-color: #f5f5f5;
        font-weight: bold;
    }
    .total-row {
        background-color: #d4edda;
        font-weight: bold;
    }
    .grand-total-row {
        background-color: #cce5ff;
        font-weight: bold;
        font-size: 14px;
    }
    .print-content {
        display: none;
    }
    .text-right {
        text-align: right;
    }
    .text-center {
        text-align: center;
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
            padding: 2px 4px;
        }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Stock Valuation Report</h3>

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
          <p class="mb-0"><strong>Data Source:</strong> <?= $daily_stock_table ?> (April 1st, 2025)</p>
      </div>

      <!-- Filter Form -->
      <div class="card mb-4 no-print">
        <div class="card-body">
          <form method="GET" class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Date As On:</label>
              <input type="text" name="date_as_on" value="<?= htmlspecialchars($date_as_on) ?>" 
                     class="form-control datepicker" placeholder="DD/MM/YYYY">
            </div>
            
            <div class="col-md-4">
              <label class="form-label">Report Type:</label>
              <div class="btn-group w-100" role="group">
                <button type="submit" name="report_type" value="D" 
                        class="btn btn-outline-primary <?= $report_type === 'D' ? 'active' : '' ?>">
                  Detailed
                </button>
                <button type="submit" name="report_type" value="S" 
                        class="btn btn-outline-primary <?= $report_type === 'S' ? 'active' : '' ?>">
                  Summary
                </button>
              </div>
            </div>
            
            <div class="col-md-4 d-flex align-items-end">
              <button type="submit" class="btn btn-primary me-2">
                <i class="fas fa-filter"></i> Apply
              </button>
              <a href="stock_valuation.php" class="btn btn-secondary">
                <i class="fas fa-sync"></i> Reset
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2 no-print">
        <button onclick="generateReport()" class="btn btn-primary">
          <i class="fas fa-file-alt"></i> Generate
        </button>
        <button onclick="window.print()" class="btn btn-secondary">
          <i class="fas fa-print"></i> Print
        </button>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Report Content -->
      <div id="reportContent" class="print-content">
        <div class="report-header text-center mb-4">
          <h2><?= htmlspecialchars($companyName) ?></h2>
          <h4>Stock Valuation Report [ April<?= $report_type === 'D' ? ' - Detailed' : ' - Summary' ?> ] (Pure. Rate)</h4>
          <p>As On 01-Apr-2025</p>
        </div>

        <?php if (empty($items)): ?>
          <div class="alert alert-warning text-center">
            No stock data found for April 1st, 2025.
          </div>
        <?php elseif ($report_type === 'D'): ?>
          <!-- Detailed Report -->
          <?php foreach ($category_order as $main_category => $subcategories): 
                $has_data = false;
                foreach ($subcategories as $subcat) {
                    if (isset($detailed_categories[$subcat]) && !empty($detailed_categories[$subcat])) {
                        $has_data = true;
                        break;
                    }
                }
                if (!$has_data) continue;
          ?>
          <div class="category-section mb-4">
            <table class="report-table">
              <thead>
                <tr class="category-header">
                  <th colspan="4"><?= strtoupper($main_category) ?></th>
                </tr>
                <tr>
                  <th>Item Description</th>
                  <th class="text-right">Cl. Stock</th>
                  <th class="text-right">Rate</th>
                  <th class="text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $category_total_amount = 0;
                foreach ($subcategories as $subcategory):
                    if (!isset($detailed_categories[$subcategory]) || empty($detailed_categories[$subcategory])) continue;
                ?>
                <tr class="subcategory-header">
                  <td colspan="4"><?= $subcategory ?></td>
                </tr>
                <?php 
                $subcategory_total_amount = 0;
                foreach ($detailed_categories[$subcategory] as $item):
                    $subcategory_total_amount += $item['amount'];
                ?>
                <tr>
                  <td><?= htmlspecialchars($item['description']) ?></td>
                  <td class="text-right"><?= number_format($item['closing_stock'], 0) ?></td>
                  <td class="text-right"><?= number_format($item['rate'], 2) ?></td>
                  <td class="text-right"><?= number_format($item['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                  <td colspan="3" class="text-right"><strong>Sub Total</strong></td>
                  <td class="text-right"><strong><?= number_format($subcategory_total_amount, 2) ?></strong></td>
                </tr>
                <?php 
                $category_total_amount += $subcategory_total_amount;
                endforeach; 
                ?>
                <tr class="grand-total-row">
                  <td colspan="3" class="text-right"><strong>Total <?= $main_category ?></strong></td>
                  <td class="text-right"><strong><?= number_format($category_total_amount, 2) ?></strong></td>
                </tr>
              </tbody>
            </table>
          </div>
          <?php endforeach; ?>
          
          <div class="grand-total-section">
            <table class="report-table">
              <tr class="grand-total-row">
                <td colspan="3" class="text-right"><strong>Total Stock Value :</strong></td>
                <td class="text-right"><strong><?= number_format($grand_total_amount, 2) ?></strong></td>
              </tr>
            </table>
          </div>

        <?php else: ?>
          <!-- Summary Report -->
          <div class="summary-section">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Category</th>
                  <th class="text-right">Cl. Stock</th>
                  <th class="text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $summary_total_amount = 0;
                $summary_total_stock = 0;
                
                // Display in the order we want
                $display_order = [
                    'Foreign Liquor' => ['Quart', 'Pint', 'Nip'],
                    'Wine' => ['Wine Quart', 'Wine Pint', 'Wine Nip'],
                    'Beer' => ['650 E', '650 M', '1 Ltr.', '60 Ml', '500 M', '500 F', '330 F', '330 M', '2 Ltrs.', '90 Ml'],
                    'Country Liquor' => ['Quart', 'Nip', '90 Ml']
                ];
                
                foreach ($display_order as $main_category => $subcategories):
                    $main_category_stock = 0;
                    $main_category_amount = 0;
                ?>
                <tr class="category-header">
                  <td colspan="3"><?= $main_category ?></td>
                </tr>
                <?php foreach ($subcategories as $subcategory): 
                    if (isset($summary_data[$subcategory])):
                        $main_category_stock += $summary_data[$subcategory]['closing_stock'];
                        $main_category_amount += $summary_data[$subcategory]['amount'];
                ?>
                <tr>
                  <td style="padding-left: 20px;"><?= $subcategory ?></td>
                  <td class="text-right"><?= number_format($summary_data[$subcategory]['closing_stock'], 0) ?></td>
                  <td class="text-right"><?= number_format($summary_data[$subcategory]['amount'], 2) ?></td>
                </tr>
                <?php endif; endforeach; ?>
                <tr class="total-row">
                  <td class="text-right"><strong>Total <?= $main_category ?></strong></td>
                  <td class="text-right"><strong><?= number_format($main_category_stock, 0) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($main_category_amount, 2) ?></strong></td>
                </tr>
                <?php 
                $summary_total_stock += $main_category_stock;
                $summary_total_amount += $main_category_amount;
                endforeach; 
                ?>
                <tr class="grand-total-row">
                  <td class="text-right"><strong>Total Stock Value :</strong></td>
                  <td class="text-right"><strong><?= number_format($summary_total_stock, 0) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($summary_total_amount, 2) ?></strong></td>
                </tr>
              </tbody>
            </table>
            
            <!-- Additional summary sections -->
            <div class="row mt-4">
              <div class="col-md-6">
                <table class="report-table">
                  <tr>
                    <td><strong>Total Spirits Sale :</strong></td>
                    <td class="text-right">0.00</td>
                  </tr>
                  <tr>
                    <td><strong>Total Wine Sale :</strong></td>
                    <td class="text-right">0.00</td>
                  </tr>
                </table>
              </div>
              <div class="col-md-6">
                <table class="report-table">
                  <tr>
                    <td><strong>Total Beer Sale :</strong></td>
                    <td class="text-right">0.00</td>
                  </tr>
                  <tr>
                    <td><strong>Total C.L. Sale :</strong></td>
                    <td class="text-right">0.00</td>
                  </tr>
                </table>
              </div>
            </div>
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

// Initialize datepicker
$(document).ready(function() {
  $('.datepicker').datepicker({
    format: 'dd/mm/yyyy',
    autoclose: true
  });
});
</script>
</body>
</html>