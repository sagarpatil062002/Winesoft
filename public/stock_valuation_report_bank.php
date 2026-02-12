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

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'detailed';
$rate_type = isset($_GET['rate_type']) ? $_GET['rate_type'] : 'purc';
$stock_date = isset($_GET['stock_date']) ? $_GET['stock_date'] : date('Y-m-d');

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

// Function to get the appropriate stock table name
function getStockTableName($stock_date, $compID) {
    $current_month = date('m');
    $current_year = date('y');
    $stock_month = date('m', strtotime($stock_date));
    $stock_year = date('y', strtotime($stock_date));
    
    // If stock date is in current month, use current month table
    if ($stock_month == $current_month && $stock_year == $current_year) {
        return "tbldailystock_" . $compID;
    } else {
        // Use historical month table
        return "tbldailystock_" . $compID . "_" . $stock_month . "_" . $stock_year;
    }
}

// Function to check if table exists
function tableExists($conn, $tableName) {
    $result = $conn->query("SHOW TABLES LIKE '$tableName'");
    return $result->num_rows > 0;
}

// Function to get the day column name for a specific date
function getDayColumnName($stock_date) {
    $day = date('d', strtotime($stock_date));
    return "DAY_" . sprintf('%02d', $day) . "_CLOSING";
}

// Function to get the appropriate rate field based on rate type
function getRateField($rate_type) {
    switch ($rate_type) {
        case 'purc':
            return 'PPRICE'; // Purchase Rate
        case 'sales':
            return 'RPRICE'; // Retail Price (Sales Rate)
        case 'mrp':
            return 'MPRICE'; // MRP Rate
        case 'basic':
            return 'BPRICE'; // Base Price (Basic Rate)
        default:
            return 'PPRICE';
    }
}

// Function to group sizes by base size (remove suffixes after ML and trim)
function getBaseSize($size) {
    // Extract the base size (everything before any special characters after ML)
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize); // Remove trailing - numbers
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize); // Remove trailing (numbers)
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize); // Remove anything in parentheses
    return trim($baseSize);
}

// Function to get category name based on class and details (updated with comprehensive size mapping)
function getCategoryName($class, $details, $details2) {
    $details_upper = strtoupper($details);
    $details2_upper = strtoupper($details2);
    
    // Define size mappings similar to brand_register.php
    $spirit_sizes = [
        '2000 ML Pet (6)' => '2000 ML',
        '2000 ML(4)' => '2000 ML',
        '2000 ML(6)' => '2000 ML',
        '1000 ML(Pet)' => '1000 ML',
        '1000 ML' => '1000 ML',
        '750 ML(6)' => '750 ML',
        '750 ML (Pet)' => '750 ML',
        '750 ML' => '750 ML',
        '700 ML' => '700 ML',
        '700 ML(6)' => '700 ML',
        '375 ML (12)' => '375 ML',
        '375 ML' => '375 ML',
        '375 ML (Pet)' => '375 ML',
        '350 ML (12)' => '350 ML',
        '275 ML(24)' => '275 ML',
        '200 ML (48)' => '200 ML',
        '200 ML (24)' => '200 ML',
        '200 ML (30)' => '200 ML',
        '200 ML (12)' => '200 ML',
        '180 ML(24)' => '180 ML',
        '180 ML (Pet)' => '180 ML',
        '180 ML' => '180 ML',
        '90 ML(100)' => '90 ML',
        '90 ML (Pet)-100' => '90 ML',
        '90 ML (Pet)-96' => '90 ML',
        '90 ML-(96)' => '90 ML',
        '90 ML' => '90 ML',
        '60 ML' => '60 ML',
        '60 ML (75)' => '60 ML',
        '50 ML(120)' => '50 ML',
        '50 ML (180)' => '50 ML',
        '50 ML (24)' => '50 ML',
        '50 ML (192)' => '50 ML'
    ];
    
    $wine_sizes = [
        '750 ML(6)' => '750 ML',
        '750 ML' => '750 ML',
        '650 ML' => '650 ML',
        '375 ML' => '375 ML',
        '330 ML' => '330 ML',
        '180 ML' => '180 ML'
    ];
    
    $beer_sizes = [
        '650 ML' => '650 ML',
        '500 ML' => '500 ML',
        '500 ML (CAN)' => '500 ML',
        '330 ML' => '330 ML',
        '330 ML (CAN)' => '330 ML',
        '275 ML' => '275 ML',
        '250 ML' => '250 ML'
    ];
    
    // Check for beer types first (both fermented and mild)
    foreach ($beer_sizes as $excel_size => $category) {
        if (strpos($details2_upper, $excel_size) !== false) {
            return $category;
        }
    }
    
    // Old beer type checks for compatibility
    if (strpos($details2_upper, '1000 ML') !== false || strpos($details2_upper, '1 LTR') !== false) {
        return '1000 ML';
    } elseif (strpos($details2_upper, '650 ML') !== false) {
        return '650 ML';
    } elseif (strpos($details2_upper, '500 ML') !== false) {
        return '500 ML';
    } elseif (strpos($details2_upper, '330 ML') !== false) {
        return '330 ML';
    } elseif (strpos($details2_upper, '275 ML') !== false) {
        return '275 ML';
    } elseif (strpos($details2_upper, '250 ML') !== false) {
        return '250 ML';
    }
    
    // Check for wine types
    if (strpos($details_upper, 'WINE') !== false || $class === 'V') {
        foreach ($wine_sizes as $excel_size => $category) {
            if (strpos($details2_upper, $excel_size) !== false) {
                return 'Wine ' . $category;
            }
        }
        // Default wine category if no specific size found
        return 'Wine 750 ML';
    }
    
    // Check for country liquor
    if ($class === 'C') {
        foreach ($spirit_sizes as $excel_size => $category) {
            if (strpos($details2_upper, $excel_size) !== false) {
                // For country liquor, use size names without "Wine" prefix
                return $category;
            }
        }
        // Default country liquor category
        return '90 ML';
    }
    
    // Check for foreign liquor (spirits)
    foreach ($spirit_sizes as $excel_size => $category) {
        if (strpos($details2_upper, $excel_size) !== false) {
            return $category;
        }
    }
    
    // Fallback to old logic for edge cases
    if (strpos($details2_upper, 'NIP') !== false || strpos($details2_upper, '90 ML') !== false || strpos($details2_upper, '60 ML') !== false) {
        return '90 ML';
    } elseif (strpos($details2_upper, 'PINT') !== false || strpos($details2_upper, '375 ML') !== false) {
        return '375 ML';
    } else {
        return '750 ML';
    }
}

// Generate report data based on filters
$report_data = [];
$detailed_categories = [];
$summary_data = [];
$grand_total_amount = 0;

if (isset($_GET['generate'])) {
    // Get the appropriate stock table
    $stock_table = getStockTableName($stock_date, $compID);
    $stock_month = date('Y-m', strtotime($stock_date));
    $day_column = getDayColumnName($stock_date);
    $rate_field = getRateField($rate_type);
    
    // Check if the stock table exists
    if (tableExists($conn, $stock_table)) {
        // Build query with license type filtering
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
            
            $query = "SELECT im.CODE, im.Print_Name, im.DETAILS, im.DETAILS2, im.CLASS, im.SUB_CLASS, 
                             im.ITEM_GROUP, im.PPRICE, im.BPRICE, im.RPRICE, im.MPRICE, im.LIQ_FLAG,
                             ds.{$day_column} as CLOSING_STOCK
                      FROM tblitemmaster im
                      LEFT JOIN $stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
                      WHERE im.CLASS IN ($class_placeholders) AND im.PPRICE > 0
                      AND COALESCE(ds.{$day_column}, 0) > 0";
            
            $params = array_merge([$stock_month], $allowed_classes);
            $types = str_repeat('s', count($params));
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $items = $result->fetch_all(MYSQLI_ASSOC);
            
            // Organize items by category (following brand_register.php structure)
            foreach ($items as $item) {
                $category = getCategoryName($item['CLASS'], $item['DETAILS'], $item['DETAILS2']);
                $closing_stock = (float)$item['CLOSING_STOCK'];
                
                // Get the appropriate rate based on rate_type
                switch ($rate_type) {
                    case 'purc': $rate = (float)$item['PPRICE']; break;
                    case 'sales': $rate = (float)$item['RPRICE']; break;
                    case 'mrp': $rate = (float)$item['MPRICE']; break;
                    case 'basic': $rate = (float)$item['BPRICE']; break;
                    default: $rate = (float)$item['PPRICE'];
                }
                
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
                $grand_total_amount += $amount;
            }
        }
    }
}

// Define category order for display (updated with comprehensive size categories like brand_register.php)
$category_order = [
    'Foreign Liquor' => [
        '2000 ML', '1000 ML', '750 ML', '700 ML', '375 ML', '350 ML', '275 ML', 
        '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'
    ],
    'Wine' => [
        'Wine 750 ML', 'Wine 650 ML', 'Wine 375 ML', 'Wine 330 ML', 'Wine 180 ML'
    ],
    'Beer' => [
        '1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'
    ],
    'Country Liquor' => [
        '750 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'
    ]
];
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
    .license-info {
        background-color: #f8f9fa;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 15px;
        border-left: 4px solid #007bff;
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
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Stock Valuation Report [Bank]</h3>

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

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Stock Date:</label>
                <input type="date" name="stock_date" class="form-control" value="<?= htmlspecialchars($stock_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Rate Type:</label>
                <select name="rate_type" class="form-select">
                  <option value="purc" <?= $rate_type === 'purc' ? 'selected' : '' ?>>Purchase Rate</option>
                  <option value="sales" <?= $rate_type === 'sales' ? 'selected' : '' ?>>Sales Rate</option>
                  <option value="mrp" <?= $rate_type === 'mrp' ? 'selected' : '' ?>>MRP Rate</option>
                  <option value="basic" <?= $rate_type === 'basic' ? 'selected' : '' ?>>Basic Rate</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Report Type:</label>
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
      <?php if (isset($_GET['generate'])): ?>
        <div id="reportContent" class="print-content">
          <div class="report-header text-center mb-4">
            <h2><?= htmlspecialchars($companyName) ?></h2>
            <h4>Stock Valuation Report [ Bank - <?= $report_type === 'detailed' ? 'Detailed' : 'Summary' ?> ] (<?= 
                $rate_type === 'purc' ? 'Pure. Rate' : 
                ($rate_type === 'sales' ? 'Sales Rate' : 
                ($rate_type === 'mrp' ? 'MRP Rate' : 'Basic Rate'))
            ?>)</h4>
            <p>As On <?= date('d-M-Y', strtotime($stock_date)) ?></p>
          </div>

          <?php if (empty($detailed_categories) && empty($summary_data)): ?>
            <div class="alert alert-warning text-center">
              No stock data found for the selected date.
            </div>
          <?php elseif ($report_type === 'detailed'): ?>
            <!-- Detailed Report (Updated with comprehensive size categories) -->
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
            <!-- Summary Report (Updated with comprehensive size categories) -->
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
                  
                  foreach ($category_order as $main_category => $subcategories):
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
            </div>
          <?php endif; ?>
          
          <div class="footer-info mt-4">
            Generated on: <?= date('d-M-Y h:i A') ?> | Generated by: <?= $_SESSION['username'] ?? 'System' ?>
          </div>
        </div>
        
        <script>
          // Show report content after generation
          document.getElementById('reportContent').style.display = 'block';
        </script>
      <?php endif; ?>
    </div>
    
  <?php include 'components/footer.php'; ?>
  </div>
  
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="components/shortcuts.js?v=<?= time() ?>"></script>

</body>
</html>