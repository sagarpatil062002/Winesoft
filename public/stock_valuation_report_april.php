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

// Function to group sizes by base size (remove suffixes after ML and trim)
function getBaseSize($size) {
    // Extract the base size (everything before any special characters after ML)
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize); // Remove trailing - numbers
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize); // Remove trailing (numbers)
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize); // Remove anything in parentheses
    return trim($baseSize);
}

// Function to identify if item is Indian or Imported
function getItemOrigin($details, $details2, $class) {
    $details_upper = strtoupper($details);
    $details2_upper = strtoupper($details2);
    
    // Indian brands list
    $indian_brands = [
        'OFFICER\'S CHOICE', 'MCDOWELL\'S NO.1', 'BAGPIPER', 'IMPERIAL BLUE', 
        'ROYAL STAG', '8 PM', 'OLD MONK', 'HAYWARDS', 'DIRTY DOZEN', 
        'BLENDERS PRIDE', 'ROYAL CHALLENGE', 'ANTiquity', 'SIGNATURE',
        'KINGFISHER', 'WHITE MISCHIEF', 'ROMANOV', 'MAGIC MOMENTS',
        'MCDOWELL\'S BRANDY', 'DREHER', 'MOLESWORTH', 'BLUE RIBBOND',
        'GORDON\'S', 'HONEY BEE', 'CONTESSA', 'CAPTAIN SPECIAL'
    ];
    
    // Imported brands list
    $imported_brands = [
        'JOHNNIE WALKER', 'CHIVAS REGAL', 'BALLANTINE\'S', 'GLENFIDDICH', 
        'GLENLIVET', 'JACK DANIEL\'S', 'JIM BEAM', 'JAMESON', 'ABSOLUT', 
        'SMIRNOFF', 'BACARDI', 'CAPTAIN MORGAN', 'JOSE CUERVO', 'MACALLAN',
        'DEWAR\'S', 'BLACK & WHITE', 'TEACHER\'S', 'WHITE HORSE', 'CUTTY SARK',
        'FAMOUS GROUSE', 'MAKER\'S MARK', 'WILD TURKEY', 'BULLEIT', 'WOODFORD RESERVE',
        'BUSHMILLS', 'TULLAMORE DEW', 'CANADIAN CLUB', 'CROWN ROYAL', 'GREY GOOSE',
        'BELVEDERE', 'CIROC', 'STOLICHNAYA', 'FINLANDIA', 'TANQUERAY', 'BEEFEATER',
        'HENDRICK\'S', 'HAVANA CLUB', 'MALIBU', 'PATRON', 'DON JULIO', 'SAUZA',
        'JACOB\'S CREEK', 'YELLOW TAIL', 'BAREFOOT', 'GALLO', 'MOET',
        'VEUVE CLICQUOT', 'DOM PERIGNON', 'CHAMPAGNE', 'STELLA ARTOIS', 'GUINNESS'
    ];
    
    // Check for imported keywords in description
    $imported_keywords = [
        'SCOTCH', 'IMPORTED', 'IMPORT', 'FOREIGN', 'PREMIUM IMPORT',
        'INTERNATIONAL', 'ORIGINAL IMPORT'
    ];
    
    // Check for Indian keywords
    $indian_keywords = [
        'INDIAN', 'DOMESTIC', 'LOCAL', 'MADE IN INDIA', 'COUNTRY'
    ];
    
    // First check brand names
    foreach ($indian_brands as $brand) {
        if (strpos($details_upper, $brand) !== false) {
            return 'Indian';
        }
    }
    
    foreach ($imported_brands as $brand) {
        if (strpos($details_upper, $brand) !== false) {
            return 'Imported';
        }
    }
    
    // Check for keywords
    foreach ($imported_keywords as $keyword) {
        if (strpos($details_upper, $keyword) !== false || 
            strpos($details2_upper, $keyword) !== false) {
            return 'Imported';
        }
    }
    
    foreach ($indian_keywords as $keyword) {
        if (strpos($details_upper, $keyword) !== false || 
            strpos($details2_upper, $keyword) !== false) {
            return 'Indian';
        }
    }
    
    // Default based on class
    if ($class === 'C') { // Country Liquor is always Indian
        return 'Indian';
    }
    
    // For other classes, default to Indian (most common case in India)
    return 'Indian';
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
        '750 ML(6)' => 'Wine 750 ML',
        '750 ML' => 'Wine 750 ML',
        '650 ML' => 'Wine 650 ML',
        '375 ML' => 'Wine 375 ML',
        '330 ML' => 'Wine 330 ML',
        '180 ML' => 'Wine 180 ML'
    ];
    
    $beer_sizes = [
        '1000 ML' => '1000 ML',
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
                return $category;
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

// Organize items by category for detailed report with origin
$detailed_categories = [];
$summary_data = [];
$grand_total_amount = 0;

foreach ($items as $item) {
    $category = getCategoryName($item['CLASS'], $item['DETAILS'], $item['DETAILS2']);
    $origin = getItemOrigin($item['DETAILS'], $item['DETAILS2'], $item['CLASS']);
    $closing_stock = (float)$item['CLOSING_STOCK'];
    $rate = (float)$item['PPRICE'];
    $amount = $closing_stock * $rate;
    
    // Create a combined category with origin for detailed report
    $category_with_origin = $category . ' (' . $origin . ')';
    
    // For detailed report
    if (!isset($detailed_categories[$category_with_origin])) {
        $detailed_categories[$category_with_origin] = [];
    }
    
    $detailed_categories[$category_with_origin][] = [
        'description' => $item['DETAILS'],
        'closing_stock' => $closing_stock,
        'rate' => $rate,
        'amount' => $amount,
        'origin' => $origin
    ];
    
    // For summary report - group by origin within category
    if (!isset($summary_data[$category])) {
        $summary_data[$category] = [
            'Indian' => ['closing_stock' => 0, 'amount' => 0],
            'Imported' => ['closing_stock' => 0, 'amount' => 0]
        ];
    }
    
    $summary_data[$category][$origin]['closing_stock'] += $closing_stock;
    $summary_data[$category][$origin]['amount'] += $amount;
    $grand_total_amount += $amount;
}

// Define category order for display (updated with comprehensive size categories)
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
          <!-- Detailed Report with Origin -->
          <?php foreach ($category_order as $main_category => $subcategories): 
                $has_data = false;
                foreach ($subcategories as $subcat) {
                    // Check both Indian and Imported versions
                    if ((isset($detailed_categories[$subcat . ' (Indian)']) && !empty($detailed_categories[$subcat . ' (Indian)'])) ||
                        (isset($detailed_categories[$subcat . ' (Imported)']) && !empty($detailed_categories[$subcat . ' (Imported)']))) {
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
                    $has_indian = isset($detailed_categories[$subcategory . ' (Indian)']) && !empty($detailed_categories[$subcategory . ' (Indian)']);
                    $has_imported = isset($detailed_categories[$subcategory . ' (Imported)']) && !empty($detailed_categories[$subcategory . ' (Imported)']);
                    
                    if (!$has_indian && !$has_imported) continue;
                ?>
                
                <?php if ($has_indian): ?>
                <tr class="subcategory-header origin-indian">
                  <td colspan="4"><?= $subcategory ?> (Indian)</td>
                </tr>
                <?php 
                $subcategory_total_amount_indian = 0;
                foreach ($detailed_categories[$subcategory . ' (Indian)'] as $item):
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
                  <td colspan="4"><?= $subcategory ?> (Imported)</td>
                </tr>
                <?php 
                $subcategory_total_amount_imported = 0;
                foreach ($detailed_categories[$subcategory . ' (Imported)'] as $item):
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
                
                foreach ($category_order as $main_category => $subcategories):
                    $main_category_stock_indian = 0;
                    $main_category_amount_indian = 0;
                    $main_category_stock_imported = 0;
                    $main_category_amount_imported = 0;
                ?>
                <tr class="category-header">
                  <td colspan="3"><?= $main_category ?></td>
                </tr>
                <?php foreach ($subcategories as $subcategory): 
                    if (isset($summary_data[$subcategory])):
                        $indian_data = $summary_data[$subcategory]['Indian'];
                        $imported_data = $summary_data[$subcategory]['Imported'];
                        
                        $main_category_stock_indian += $indian_data['closing_stock'];
                        $main_category_amount_indian += $indian_data['amount'];
                        $main_category_stock_imported += $imported_data['closing_stock'];
                        $main_category_amount_imported += $imported_data['amount'];
                ?>
                <!-- Indian Items -->
                <tr class="origin-indian">
                  <td style="padding-left: 20px;"><?= $subcategory ?> (Indian)</td>
                  <td class="text-right"><?= number_format($indian_data['closing_stock'], 0) ?></td>
                  <td class="text-right"><?= number_format($indian_data['amount'], 2) ?></td>
                </tr>
                <!-- Imported Items -->
                <tr class="origin-imported">
                  <td style="padding-left: 20px;"><?= $subcategory ?> (Imported)</td>
                  <td class="text-right"><?= number_format($imported_data['closing_stock'], 0) ?></td>
                  <td class="text-right"><?= number_format($imported_data['amount'], 2) ?></td>
                </tr>
                <?php endif; endforeach; ?>
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