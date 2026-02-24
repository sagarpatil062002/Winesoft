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

// Debug - uncomment to see the actual structure of available_classes
// echo "<pre>"; print_r($available_classes); echo "</pre>"; exit;

// Extract class identifiers for filtering - check what keys are available
$allowed_classes = [];
$class_display_names = [];

if (!empty($available_classes)) {
    foreach ($available_classes as $class) {
        // Try to get CLASS_CODE (new structure) or fall back to SGROUP (old structure)
        if (isset($class['CLASS_CODE'])) {
            $allowed_classes[] = $class['CLASS_CODE'];
            $display_name = isset($class['CLASS_NAME']) ? $class['CLASS_NAME'] : $class['CLASS_CODE'];
        } elseif (isset($class['SGROUP'])) {
            $allowed_classes[] = $class['SGROUP'];
            $display_name = isset($class['DESC']) ? $class['DESC'] : $class['SGROUP'];
        } elseif (isset($class['class_code'])) {
            $allowed_classes[] = $class['class_code'];
            $display_name = isset($class['class_name']) ? $class['class_name'] : $class['class_code'];
        } else {
            // If no recognizable keys, use the first value as fallback
            $first_value = reset($class);
            $allowed_classes[] = $first_value;
            $display_name = $first_value;
        }
        
        $class_display_names[] = $display_name . ' (' . end($allowed_classes) . ')';
    }
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

// Function to get category name based on class and size
function getCategoryName($class_code, $details2) {
    $details2_upper = strtoupper($details2);
    
    // Map CLASS_CODE to category names
    $class_categories = [
        'CLS001' => 'Foreign Liquor', // IMFL
        'CLS002' => 'Foreign Liquor', // IMPORTED
        'CLS003' => 'Foreign Liquor', // MML
        'CLS004' => 'Wine', // INDIAN Wine
        'CLS005' => 'Wine', // IMPORTED Wine
        'CLS006' => 'Wine', // MML Wine
        'CLS007' => 'Beer', // Fermented Beer
        'CLS008' => 'Beer', // Mild Beer
        'CLS009' => 'Country Liquor', // Country Liquor
        'CLS010' => 'Others', // Cold Drinks
        'CLS011' => 'Others', // Soda
        'CLS012' => 'Others' // General
    ];
    
    // Also support old class codes (A, B, C, etc.)
    $old_class_categories = [
        'A' => 'Foreign Liquor',
        'B' => 'Foreign Liquor',
        'C' => 'Country Liquor',
        'D' => 'Beer',
        'E' => 'Wine',
        'F' => 'Others'
    ];
    
    // Get main category from class
    if (isset($class_categories[$class_code])) {
        $main_category = $class_categories[$class_code];
    } elseif (isset($old_class_categories[$class_code])) {
        $main_category = $old_class_categories[$class_code];
    } else {
        $main_category = 'Others';
    }
    
    // Extract size from DETAILS2
    $size = '';
    if (preg_match('/(\d+)\s*ML/i', $details2_upper, $matches)) {
        $size = $matches[1] . ' ML';
    } else {
        // Default sizes based on main category
        switch($main_category) {
            case 'Foreign Liquor':
            case 'Country Liquor':
                $size = '750 ML';
                break;
            case 'Wine':
                $size = 'Wine 750 ML';
                break;
            case 'Beer':
                $size = '650 ML';
                break;
            default:
                $size = 'General';
        }
    }
    
    // For wine, add prefix
    if ($main_category == 'Wine' && strpos($size, 'Wine') === false) {
        $size = 'Wine ' . $size;
    }
    
    return [
        'main_category' => $main_category,
        'size_category' => $size
    ];
}

// Function to identify if item is Indian or Imported based on CLASS_CODE
function getItemOrigin($class_code, $details, $details2) {
    $details_upper = strtoupper($details);
    $details2_upper = strtoupper($details2);
    
    // Check CLASS_CODE first (new structure)
    $indian_classes = ['CLS001', 'CLS004', 'CLS009', 'CLS007', 'CLS008', 'CLS010', 'CLS011', 'CLS012'];
    $imported_classes = ['CLS002', 'CLS005'];
    $mml_classes = ['CLS003', 'CLS006'];
    
    // Also support old class codes
    $old_indian_classes = ['A', 'C', 'D', 'E', 'F', 'G', 'H'];
    $old_imported_classes = ['B'];
    
    if (in_array($class_code, $indian_classes)) {
        return 'Indian';
    } elseif (in_array($class_code, $imported_classes)) {
        return 'Imported';
    } elseif (in_array($class_code, $mml_classes)) {
        // MML - check details to determine
        if (strpos($details_upper, 'IMPORTED') !== false) {
            return 'Imported';
        } else {
            return 'Indian';
        }
    } elseif (in_array($class_code, $old_indian_classes)) {
        return 'Indian';
    } elseif (in_array($class_code, $old_imported_classes)) {
        return 'Imported';
    }
    
    // Default based on details
    $indian_keywords = ['INDIAN', 'DOMESTIC', 'MADE IN INDIA'];
    $imported_keywords = ['IMPORTED', 'SCOTCH', 'IMPORT', 'FOREIGN', 'PREMIUM IMPORT'];
    
    foreach ($imported_keywords as $keyword) {
        if (strpos($details_upper, $keyword) !== false || strpos($details2_upper, $keyword) !== false) {
            return 'Imported';
        }
    }
    
    foreach ($indian_keywords as $keyword) {
        if (strpos($details_upper, $keyword) !== false || strpos($details2_upper, $keyword) !== false) {
            return 'Indian';
        }
    }
    
    return 'Indian';
}

// Fetch items with closing stock and rates - FILTERED BY LICENSE TYPE
$items = [];
if (!empty($allowed_classes) && !empty($daily_stock_table) && tableExists($conn, $daily_stock_table)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // For April data, we always use STK_MONTH = '2025-04' and DAY_01_CLOSING
    $stk_month = '2025-04'; // Fixed for April
    $day_column = 'DAY_01_CLOSING'; // Fixed for April 1st
    
    $query = "SELECT im.CODE, im.Print_Name, im.DETAILS, im.DETAILS2, im.CLASS, 
                     im.PPRICE, im.BPRICE, im.LIQ_FLAG,
                     ds.{$day_column} as CLOSING_STOCK
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
              WHERE im.CLASS IN ($class_placeholders) AND im.PPRICE > 0
              AND COALESCE(ds.{$day_column}, 0) > 0";
    
    $params = array_merge([$stk_month], $allowed_classes);
    $types = str_repeat('s', count($params));
    
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
    }
}

// Organize items by category for detailed report with origin
$detailed_categories = [];
$summary_data = [];
$grand_total_amount = 0;

foreach ($items as $item) {
    $category_info = getCategoryName($item['CLASS'], $item['DETAILS2']);
    $main_category = $category_info['main_category'];
    $size_category = $category_info['size_category'];
    $origin = getItemOrigin($item['CLASS'], $item['DETAILS'], $item['DETAILS2']);
    $closing_stock = (float)$item['CLOSING_STOCK'];
    $rate = (float)$item['PPRICE'];
    $amount = $closing_stock * $rate;
    
    // Create a combined category with origin for detailed report
    $category_with_origin = $size_category . ' (' . $origin . ')';
    
    // For detailed report
    if (!isset($detailed_categories[$category_with_origin])) {
        $detailed_categories[$category_with_origin] = [];
    }
    
    $detailed_categories[$category_with_origin][] = [
        'description' => $item['DETAILS'],
        'closing_stock' => $closing_stock,
        'rate' => $rate,
        'amount' => $amount,
        'origin' => $origin,
        'main_category' => $main_category
    ];
    
    // For summary report - group by origin within category
    if (!isset($summary_data[$size_category])) {
        $summary_data[$size_category] = [
            'Indian' => ['closing_stock' => 0, 'amount' => 0],
            'Imported' => ['closing_stock' => 0, 'amount' => 0],
            'main_category' => $main_category
        ];
    }
    
    $summary_data[$size_category][$origin]['closing_stock'] += $closing_stock;
    $summary_data[$size_category][$origin]['amount'] += $amount;
    $grand_total_amount += $amount;
}

// Define main category order
$main_category_order = ['Foreign Liquor', 'Wine', 'Beer', 'Country Liquor', 'Others'];

// Define size order within each main category
$size_order = [
    'Foreign Liquor' => ['2000 ML', '1000 ML', '750 ML', '700 ML', '375 ML', '350 ML', '275 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'],
    'Wine' => ['Wine 750 ML', 'Wine 650 ML', 'Wine 375 ML', 'Wine 330 ML', 'Wine 180 ML'],
    'Beer' => ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'],
    'Country Liquor' => ['750 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'],
    'Others' => ['General']
];

// Calculate totals
$grand_total_stock = 0;
foreach ($summary_data as $category) {
    $grand_total_stock += $category['Indian']['closing_stock'] + $category['Imported']['closing_stock'];
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
    .origin-indian {
        background-color: #f8f9fa;
    }
    .origin-imported {
        background-color: #fff3cd;
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
        .origin-indian {
            background-color: #f8f9fa !important;
        }
        .origin-imported {
            background-color: #fff3cd !important;
        }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Stock Valuation Report - April</h3>

      <!-- License Restriction Info -->
      <div class="license-info no-print">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
              <?php 
              if (!empty($class_display_names)) {
                  echo implode(', ', $class_display_names);
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
          <!-- Detailed Report with Origin -->
          <?php foreach ($main_category_order as $main_category): 
                $has_data = false;
                $category_sizes = isset($size_order[$main_category]) ? $size_order[$main_category] : [];
                foreach ($category_sizes as $size) {
                    if ((isset($detailed_categories[$size . ' (Indian)']) && !empty($detailed_categories[$size . ' (Indian)'])) ||
                        (isset($detailed_categories[$size . ' (Imported)']) && !empty($detailed_categories[$size . ' (Imported)']))) {
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
                foreach ($category_sizes as $size):
                    $has_indian = isset($detailed_categories[$size . ' (Indian)']) && !empty($detailed_categories[$size . ' (Indian)']);
                    $has_imported = isset($detailed_categories[$size . ' (Imported)']) && !empty($detailed_categories[$size . ' (Imported)']);
                    
                    if (!$has_indian && !$has_imported) continue;
                ?>
                
                <?php if ($has_indian): ?>
                <tr class="subcategory-header origin-indian">
                  <td colspan="4"><?= $size ?> (Indian)</td>
                </tr>
                <?php 
                $subcategory_total_amount_indian = 0;
                foreach ($detailed_categories[$size . ' (Indian)'] as $item):
                    $subcategory_total_amount_indian += $item['amount'];
                ?>
                <tr class="origin-indian">
                  <td><?= htmlspecialchars($item['description']) ?></td>
                  <td class="text-right"><?= number_format($item['closing_stock'], 0) ?></td>
                  <td class="text-right"><?= number_format($item['rate'], 2) ?></td>
                  <td class="text-right"><?= number_format($item['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row origin-indian">
                  <td colspan="3" class="text-right"><strong>Sub Total (Indian)</strong></td>
                  <td class="text-right"><strong><?= number_format($subcategory_total_amount_indian, 2) ?></strong></td>
                </tr>
                <?php 
                $category_total_amount += $subcategory_total_amount_indian;
                endif; ?>
                
                <?php if ($has_imported): ?>
                <tr class="subcategory-header origin-imported">
                  <td colspan="4"><?= $size ?> (Imported)</td>
                </tr>
                <?php 
                $subcategory_total_amount_imported = 0;
                foreach ($detailed_categories[$size . ' (Imported)'] as $item):
                    $subcategory_total_amount_imported += $item['amount'];
                ?>
                <tr class="origin-imported">
                  <td><?= htmlspecialchars($item['description']) ?></td>
                  <td class="text-right"><?= number_format($item['closing_stock'], 0) ?></td>
                  <td class="text-right"><?= number_format($item['rate'], 2) ?></td>
                  <td class="text-right"><?= number_format($item['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row origin-imported">
                  <td colspan="3" class="text-right"><strong>Sub Total (Imported)</strong></td>
                  <td class="text-right"><strong><?= number_format($subcategory_total_amount_imported, 2) ?></strong></td>
                </tr>
                <?php 
                $category_total_amount += $subcategory_total_amount_imported;
                endif; ?>
                
                <?php endforeach; ?>
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
          <!-- Summary Report with Origin -->
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
                
                foreach ($main_category_order as $main_category):
                    $main_category_stock_indian = 0;
                    $main_category_amount_indian = 0;
                    $main_category_stock_imported = 0;
                    $main_category_amount_imported = 0;
                    $category_has_data = false;
                    
                    $category_sizes = isset($size_order[$main_category]) ? $size_order[$main_category] : [];
                ?>
                <tr class="category-header">
                  <td colspan="3"><?= $main_category ?></td>
                </tr>
                <?php foreach ($category_sizes as $size): 
                    if (isset($summary_data[$size])):
                        $indian_data = $summary_data[$size]['Indian'];
                        $imported_data = $summary_data[$size]['Imported'];
                        
                        if ($indian_data['closing_stock'] > 0 || $imported_data['closing_stock'] > 0) {
                            $category_has_data = true;
                        }
                        
                        $main_category_stock_indian += $indian_data['closing_stock'];
                        $main_category_amount_indian += $indian_data['amount'];
                        $main_category_stock_imported += $imported_data['closing_stock'];
                        $main_category_amount_imported += $imported_data['amount'];
                ?>
                <!-- Indian Items -->
                <?php if ($indian_data['closing_stock'] > 0): ?>
                <tr class="origin-indian">
                  <td style="padding-left: 20px;"><?= $size ?> (Indian)</td>
                  <td class="text-right"><?= number_format($indian_data['closing_stock'], 0) ?></td>
                  <td class="text-right"><?= number_format($indian_data['amount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <!-- Imported Items -->
                <?php if ($imported_data['closing_stock'] > 0): ?>
                <tr class="origin-imported">
                  <td style="padding-left: 20px;"><?= $size ?> (Imported)</td>
                  <td class="text-right"><?= number_format($imported_data['closing_stock'], 0) ?></td>
                  <td class="text-right"><?= number_format($imported_data['amount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php endif; endforeach; ?>
                
                <?php if ($category_has_data): ?>
                <tr class="total-row">
                  <td class="text-right"><strong>Total <?= $main_category ?> (Indian)</strong></td>
                  <td class="text-right"><strong><?= number_format($main_category_stock_indian, 0) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($main_category_amount_indian, 2) ?></strong></td>
                </tr>
                <tr class="total-row">
                  <td class="text-right"><strong>Total <?= $main_category ?> (Imported)</strong></td>
                  <td class="text-right"><strong><?= number_format($main_category_stock_imported, 0) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($main_category_amount_imported, 2) ?></strong></td>
                </tr>
                <?php 
                $summary_total_stock += $main_category_stock_indian + $main_category_stock_imported;
                $summary_total_amount += $main_category_amount_indian + $main_category_amount_imported;
                endif;
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