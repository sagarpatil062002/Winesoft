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

// Get parameters
$date_as_on = isset($_GET['date_as_on']) ? $_GET['date_as_on'] : date('d/m/Y');
$sequence = isset($_GET['sequence']) ? $_GET['sequence'] : 'U';
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'D'; // D for Detailed, S for Summary
$rate_type = isset($_GET['rate_type']) ? $_GET['rate_type'] : 'mrp'; // mrp, brate, prate, rrate

// Convert date format for database
$date_parts = explode('/', $date_as_on);
$db_date = count($date_parts) === 3 ? $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0] : date('Y-m-d');
$month_year = date('Y-m', strtotime($db_date));
$day = date('d', strtotime($db_date));

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

// Function to extract brand name from item details
function getBrandName($details) {
    // Remove size patterns (ML, CL, L, etc. with numbers)
    $brandName = preg_replace('/\s*\d+\s*(ML|CL|L).*$/i', '', $details);
    $brandName = preg_replace('/\s*\([^)]*\)\s*$/', '', $brandName); // Remove trailing parentheses
    $brandName = preg_replace('/\s*-\s*\d+$/', '', $brandName); // Remove trailing - numbers
    return trim($brandName);
}

// Function to group sizes by base size
function getBaseSize($size) {
    // Extract the base size (everything before any special characters after ML)
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize); // Remove trailing - numbers
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize); // Remove trailing (numbers)
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize); // Remove anything in parentheses
    return trim($baseSize);
}

// Define size columns for each liquor type
$size_columns_s = [
    '2000 ML Pet (6)', '2000 ML(4)', '2000 ML(6)', '1000 ML(Pet)', '1000 ML',
    '750 ML(6)', '750 ML (Pet)', '750 ML', '700 ML', '700 ML(6)',
    '375 ML (12)', '375 ML', '375 ML (Pet)', '350 ML (12)', '275 ML(24)',
    '200 ML (48)', '200 ML (24)', '200 ML (30)', '200 ML (12)', '180 ML(24)',
    '180 ML (Pet)', '180 ML', '90 ML(100)', '90 ML (Pet)-100', '90 ML (Pet)-96', 
    '90 ML-(96)', '90 ML', '60 ML', '60 ML (75)', '50 ML(120)', '50 ML (180)', 
    '50 ML (24)', '50 ML (192)'
];
$size_columns_w = ['750 ML(6)', '750 ML', '650 ML', '375 ML', '330 ML', '180 ML'];
$size_columns_fb = ['650 ML', '500 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];
$size_columns_mb = ['650 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];

// Group sizes by base size for each liquor type
function groupSizes($sizes) {
    $grouped = [];
    foreach ($sizes as $size) {
        $baseSize = getBaseSize($size);
        if (!isset($grouped[$baseSize])) {
            $grouped[$baseSize] = [];
        }
        $grouped[$baseSize][] = $size;
    }
    return $grouped;
}

$grouped_sizes_s = groupSizes($size_columns_s);
$grouped_sizes_w = groupSizes($size_columns_w);
$grouped_sizes_fb = groupSizes($size_columns_fb);
$grouped_sizes_mb = groupSizes($size_columns_mb);

// Get display sizes (base sizes) for each liquor type
$display_sizes_s = array_keys($grouped_sizes_s);
$display_sizes_w = array_keys($grouped_sizes_w);
$display_sizes_fb = array_keys($grouped_sizes_fb);
$display_sizes_mb = array_keys($grouped_sizes_mb);

// Function to determine liquor type based on CLASS and LIQ_FLAG
function getLiquorType($class, $liq_flag) {
    if ($liq_flag == 'F') {
        switch ($class) {
            case 'F': return 'Fermented Beer';
            case 'M': return 'Mild Beer';
            case 'V': return 'Wines';
            default: return 'Spirits';
        }
    }
    return 'Spirits'; // Default for non-F items
}

// Function to get grouped size for display
function getGroupedSize($size, $liquor_type) {
    global $grouped_sizes_s, $grouped_sizes_w, $grouped_sizes_fb, $grouped_sizes_mb;
    
    $baseSize = getBaseSize($size);
    
    // Check if this base size exists in the appropriate group
    switch ($liquor_type) {
        case 'Spirits':
            if (in_array($baseSize, array_keys($grouped_sizes_s))) {
                return $baseSize;
            }
            break;
        case 'Wines':
            if (in_array($baseSize, array_keys($grouped_sizes_w))) {
                return $baseSize;
            }
            break;
        case 'Fermented Beer':
            if (in_array($baseSize, array_keys($grouped_sizes_fb))) {
                return $baseSize;
            }
            break;
        case 'Mild Beer':
            if (in_array($baseSize, array_keys($grouped_sizes_mb))) {
                return $baseSize;
            }
            break;
    }
    
    return $baseSize; // Return base size even if not found in predefined groups
}

// Function to get rate based on rate type
function getItemRate($item, $rate_type) {
    switch ($rate_type) {
        case 'prate': // Purchase Rate
            return $item['PPRICE'] ?? 0;
        case 'rrate': // Retail Rate
            return $item['RPRICE'] ?? $item['MPRICE'] ?? 0;
        case 'brate': // Base Rate
            return $item['BPRICE'] ?? 0;
        case 'mrp': // MRP Rate (default)
        default:
            return $item['MPRICE'] ?? 0;
    }
}

// Fetch items with closing stock - FILTERED BY LICENSE TYPE
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT im.CODE, im.Print_Name, im.DETAILS, im.DETAILS2, im.CLASS, im.SUB_CLASS, 
                     im.ITEM_GROUP, im.PPRICE, im.BPRICE, im.MPRICE, im.RPRICE, im.LIQ_FLAG,
                     ds.DAY_{$day}_CLOSING as CLOSING_STOCK
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
              WHERE im.CLASS IN ($class_placeholders)";
    
    $params = array_merge([$month_year], $allowed_classes);
    $types = str_repeat('s', count($params));
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
} else {
    // If no classes allowed, show empty result
    $query = "SELECT im.CODE, im.Print_Name, im.DETAILS, im.DETAILS2, im.CLASS, im.SUB_CLASS, 
                     im.ITEM_GROUP, im.PPRICE, im.BPRICE, im.MPRICE, im.RPRICE, im.LIQ_FLAG,
                     ds.DAY_{$day}_CLOSING as CLOSING_STOCK
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
              WHERE 1 = 0"; // Always false condition
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $month_year);
}

$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);

// Initialize variables for detailed and summary reports
$detailed_data = [];
$brand_data_by_category = [
    'Spirits' => [],
    'Wines' => [],
    'Fermented Beer' => [],
    'Mild Beer' => []
];

// Define display sizes for each liquor type
$liquor_type_sizes = [
    'Spirits' => $display_sizes_s,
    'Wines' => $display_sizes_w,
    'Fermented Beer' => $display_sizes_fb,
    'Mild Beer' => $display_sizes_mb
];

// Process items for both detailed and summary reports
foreach ($items as $item) {
    $liquor_type = getLiquorType($item['CLASS'], $item['LIQ_FLAG']);
    $size = $item['DETAILS2'] ?? '';
    $closing_stock = (float)$item['CLOSING_STOCK'];
    
    // Skip items with zero closing stock
    if ($closing_stock <= 0) continue;
    
    // Get rate based on selected rate type
    $rate = getItemRate($item, $rate_type);
    $amount = $rate * $closing_stock;
    
    // Extract brand name
    $brandName = getBrandName($item['DETAILS']);
    if (empty($brandName)) continue;
    
    // Get grouped size for display
    $grouped_size = getGroupedSize($size, $liquor_type);
    
    // Add to detailed data
    $detailed_data[] = [
        'CODE' => $item['CODE'],
        'ItemName' => $item['Print_Name'] ?: $item['DETAILS'],
        'ItemSize' => $size,
        'GroupedSize' => $grouped_size,
        'BrandName' => $brandName,
        'LiquorType' => $liquor_type,
        'CLASS' => $item['CLASS'],
        'SUB_CLASS' => $item['SUB_CLASS'],
        'ITEM_GROUP' => $item['ITEM_GROUP'],
        'ClosingStock' => $closing_stock,
        'Rate' => $rate,
        'Amount' => $amount,
        'PPRICE' => $item['PPRICE'] ?? 0,
        'BPRICE' => $item['BPRICE'] ?? 0,
        'MPRICE' => $item['MPRICE'] ?? 0,
        'RPRICE' => $item['RPRICE'] ?? 0
    ];
    
    // Add to summary data (only if the grouped size exists in our display sizes for this liquor type)
    if (in_array($grouped_size, $liquor_type_sizes[$liquor_type])) {
        // Initialize brand data if not exists
        if (!isset($brand_data_by_category[$liquor_type][$brandName])) {
            $brand_data_by_category[$liquor_type][$brandName] = array_fill_keys($liquor_type_sizes[$liquor_type], 0);
        }
        
        // Add closing stock to the brand and size
        $brand_data_by_category[$liquor_type][$brandName][$grouped_size] += $closing_stock;
    }
}

// Calculate totals for each liquor type in summary report
$liquor_type_totals = [
    'Spirits' => array_fill_keys($display_sizes_s, 0),
    'Wines' => array_fill_keys($display_sizes_w, 0),
    'Fermented Beer' => array_fill_keys($display_sizes_fb, 0),
    'Mild Beer' => array_fill_keys($display_sizes_mb, 0)
];

foreach ($brand_data_by_category as $liquor_type => $brands) {
    foreach ($brands as $brand => $sizes) {
        foreach ($sizes as $size => $quantity) {
            if (isset($liquor_type_totals[$liquor_type][$size])) {
                $liquor_type_totals[$liquor_type][$size] += $quantity;
            }
        }
    }
}

// Calculate totals for detailed report
$detailed_total_stock = 0;
$detailed_total_amount = 0;
foreach ($detailed_data as $item) {
    $detailed_total_stock += $item['ClosingStock'];
    $detailed_total_amount += $item['Amount'];
}

// Country liquor items (if any)
$country_liquor_items = array_filter($items, function($item) {
    return $item['LIQ_FLAG'] === 'C';
});

$country_brands = [];
$country_sizes = [];

foreach ($country_liquor_items as $item) {
    $brandName = getBrandName($item['DETAILS']);
    $size = getBaseSize($item['DETAILS2'] ?? '');
    
    if (!isset($country_brands[$brandName])) {
        $country_brands[$brandName] = [];
    }
    if (!in_array($size, $country_sizes)) {
        $country_sizes[] = $size;
    }
    
    if (!isset($country_brands[$brandName][$size])) {
        $country_brands[$brandName][$size] = 0;
    }
    
    $country_brands[$brandName][$size] += (float)$item['CLOSING_STOCK'];
}

sort($country_sizes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Closing Stock Statement - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>"> 
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>"> 
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>"> 
  <!-- Include shortcuts functionality -->
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    .size-column {
        text-align: center;
        min-width: 60px;
    }
    .brand-header {
        background-color: #f0f0f0;
        padding: 8px;
        margin-top: 20px;
        border-left: 4px solid #007bff;
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
    }
    .report-table th, .report-table td {
        border: 1px solid #ddd;
        padding: 6px;
        text-align: left;
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
            padding: 3px;
        }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Closing Stock Statement</h3>

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
        <div class="card-body">
          <form method="GET" class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Date As On:</label>
              <input type="text" name="date_as_on" value="<?= htmlspecialchars($date_as_on) ?>" 
                     class="form-control datepicker" placeholder="DD/MM/YYYY">
            </div>
            
            <div class="col-md-3">
              <label class="form-label">Rate Type:</label>
              <select name="rate_type" class="form-select">
                <option value="mrp" <?= $rate_type === 'mrp' ? 'selected' : '' ?>>MRP Rate</option>
                <option value="brate" <?= $rate_type === 'brate' ? 'selected' : '' ?>>Base Rate</option>
                <option value="prate" <?= $rate_type === 'prate' ? 'selected' : '' ?>>Purchase Rate</option>
                <option value="rrate" <?= $rate_type === 'rrate' ? 'selected' : '' ?>>Retail Rate</option>
              </select>
            </div>
            
            <div class="col-md-3">
              <label class="form-label">Sequence:</label>
              <div class="btn-group w-100" role="group">
                <button type="submit" name="sequence" value="U" 
                        class="btn btn-outline-primary <?= $sequence === 'U' ? 'sequence-active' : '' ?>">
                  User Defined
                </button>
                <button type="submit" name="sequence" value="S" 
                        class="btn btn-outline-primary <?= $sequence === 'S' ? 'sequence-active' : '' ?>">
                  System Defined
                </button>
              </div>
            </div>
            
            <div class="col-md-3">
              <label class="form-label">Mode:</label>
              <div class="btn-group w-100" role="group">
                <button type="submit" name="mode" value="D" 
                        class="btn btn-outline-primary <?= $mode === 'D' ? 'sequence-active' : '' ?>">
                  Detailed
                </button>
                <button type="submit" name="mode" value="S" 
                        class="btn btn-outline-primary <?= $mode === 'S' ? 'sequence-active' : '' ?>">
                  Summary
                </button>
              </div>
            </div>
            
            <div class="col-md-12 d-flex align-items-end">
              <button type="submit" class="btn btn-primary me-2">
                <i class="fas fa-filter"></i> Apply
              </button>
              <a href="closing_stock.php" class="btn btn-secondary">
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
        <div class="report-header">
          <div class="print-header">
            <h2><?= htmlspecialchars($companyName) ?></h2>
            <p>License Type: <?= htmlspecialchars($license_type) ?></p>
            <p>Item Wise Closing Stock Statement As On <?= date('d-M-Y', strtotime($db_date)) ?></p>
            <p>Rate Type: <?= 
                $rate_type === 'mrp' ? 'MRP Rate' : 
                ($rate_type === 'brate' ? 'Base Rate' : 
                ($rate_type === 'prate' ? 'Purchase Rate' : 'Retail Rate'))
            ?></p>
          </div>
        </div>

        <?php if ($mode === 'D'): ?>
          <!-- Detailed Report -->
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Sr. No.</th>
                  <th>Item Code</th>
                  <th>Item Description</th>
                  <th>Size</th>
                  <th>Brand Name</th>
                  <th>Liquor Type</th>
                  <th>Closing Stock</th>
                  <th class="text-right">Rate (<?= 
                      $rate_type === 'mrp' ? 'MRP' : 
                      ($rate_type === 'brate' ? 'Base' : 
                      ($rate_type === 'prate' ? 'Purchase' : 'Retail'))
                  ?>)</th>
                  <th class="text-right">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php
                $sr_no = 1;
                // Sort detailed data based on sequence
                if ($sequence === 'S') {
                    usort($detailed_data, function($a, $b) {
                        return strcmp($a['LiquorType'], $b['LiquorType']) ?: 
                               strcmp($a['BrandName'], $b['BrandName']) ?:
                               strcmp($a['ItemName'], $b['ItemName']);
                    });
                }
                
                $current_liquor_type = '';
                foreach ($detailed_data as $item):
                    if ($current_liquor_type !== $item['LiquorType']):
                        $current_liquor_type = $item['LiquorType'];
                ?>
                <tr class="subclass-header">
                  <td colspan="9" style="background-color: #f0f0f0; font-weight: bold;">
                    <?= strtoupper($current_liquor_type) ?>
                  </td>
                </tr>
                <?php endif; ?>
                
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td><?= htmlspecialchars($item['CODE']) ?></td>
                  <td><?= htmlspecialchars($item['ItemName']) ?></td>
                  <td class="text-center"><?= htmlspecialchars($item['ItemSize']) ?></td>
                  <td><?= htmlspecialchars($item['BrandName']) ?></td>
                  <td><?= htmlspecialchars($item['LiquorType']) ?></td>
                  <td class="text-right"><?= number_format($item['ClosingStock'], 0) ?></td>
                  <td class="text-right"><?= number_format($item['Rate'], 2) ?></td>
                  <td class="text-right"><?= number_format($item['Amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <!-- Total Row -->
                <tr class="total-row">
                  <td colspan="6" class="text-end"><strong>Grand Total:</strong></td>
                  <td class="text-right"><strong><?= number_format($detailed_total_stock, 0) ?></strong></td>
                  <td></td>
                  <td class="text-right"><strong><?= number_format($detailed_total_amount, 2) ?></strong></td>
                </tr>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <!-- Summary Report -->
          <?php foreach ($brand_data_by_category as $liquor_type => $brands): 
              if (!empty($brands)): 
                  $display_sizes = $liquor_type_sizes[$liquor_type];
          ?>
          <div class="category-section">
            <h4 class="brand-header"><?= strtoupper($liquor_type) ?></h4>
            <div class="table-container">
              <table class="report-table">
                <thead>
                  <tr>
                    <th>Sr. No.</th>
                    <th>Brand Name</th>
                    <?php foreach ($display_sizes as $size): ?>
                      <th class="size-column"><?= $size ?></th>
                    <?php endforeach; ?>
                    <th class="size-column">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $sr_no = 1;
                  ksort($brands);
                  foreach ($brands as $brand => $sizes):
                      $brand_total = 0;
                  ?>
                  <tr>
                    <td><?= $sr_no++ ?></td>
                    <td><?= htmlspecialchars($brand) ?></td>
                    <?php foreach ($display_sizes as $size): 
                        $quantity = $sizes[$size] ?? 0;
                        $brand_total += $quantity;
                    ?>
                      <td class="size-column"><?= $quantity > 0 ? number_format($quantity, 0) : '' ?></td>
                    <?php endforeach; ?>
                    <td class="size-column" style="font-weight: bold;"><?= $brand_total > 0 ? number_format($brand_total, 0) : '' ?></td>
                  </tr>
                  <?php endforeach; ?>
                  
                  <!-- Total Row -->
                  <tr class="total-row">
                    <td colspan="2" style="font-weight: bold;">Total</td>
                    <?php 
                    $category_total = 0;
                    foreach ($display_sizes as $size): 
                        $size_total = $liquor_type_totals[$liquor_type][$size] ?? 0;
                        $category_total += $size_total;
                    ?>
                      <td class="size-column" style="font-weight: bold;">
                        <?= $size_total > 0 ? number_format($size_total, 0) : '' ?>
                      </td>
                    <?php endforeach; ?>
                    <td class="size-column" style="font-weight: bold;"><?= $category_total > 0 ? number_format($category_total, 0) : '' ?></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; endforeach; ?>
          
          <!-- Country Liquor Section (if needed) -->
          <?php if (!empty($country_brands)): ?>
          <div class="category-section">
            <h4 class="brand-header">COUNTRY LIQUOR</h4>
            <div class="table-container">
              <table class="report-table">
                <thead>
                  <tr>
                    <th>Sr. No.</th>
                    <th>Brand Name</th>
                    <?php foreach ($country_sizes as $size): ?>
                      <th class="size-column"><?= $size ?></th>
                    <?php endforeach; ?>
                    <th class="size-column">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $sr_no = 1;
                  ksort($country_brands);
                  $country_totals = array_fill_keys($country_sizes, 0);
                  $country_grand_total = 0;
                  
                  foreach ($country_brands as $brand => $sizes):
                      $brand_total = 0;
                  ?>
                  <tr>
                    <td><?= $sr_no++ ?></td>
                    <td><?= htmlspecialchars($brand) ?></td>
                    <?php foreach ($country_sizes as $size): 
                        $quantity = $sizes[$size] ?? 0;
                        $brand_total += $quantity;
                        $country_totals[$size] += $quantity;
                    ?>
                      <td class="size-column"><?= $quantity > 0 ? number_format($quantity, 0) : '' ?></td>
                    <?php endforeach; ?>
                    <td class="size-column" style="font-weight: bold;"><?= $brand_total > 0 ? number_format($brand_total, 0) : '' ?></td>
                  </tr>
                  <?php 
                      $country_grand_total += $brand_total;
                  endforeach; ?>
                  
                  <!-- Country Liquor Total Row -->
                  <tr class="total-row">
                    <td colspan="2" style="font-weight: bold;">Total</td>
                    <?php foreach ($country_sizes as $size): ?>
                      <td class="size-column" style="font-weight: bold;">
                        <?= $country_totals[$size] > 0 ? number_format($country_totals[$size], 0) : '' ?>
                      </td>
                    <?php endforeach; ?>
                    <td class="size-column" style="font-weight: bold;"><?= $country_grand_total > 0 ? number_format($country_grand_total, 0) : '' ?></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>
        <?php endif; ?>
        
        <div class="footer-info">
          Generated on: <?= date('d-M-Y h:i A') ?> | Generated by: <?= $_SESSION['username'] ?? 'System' ?>
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

// Initialize datepicker if you have one
$(document).ready(function() {
  // Simple date validation for DD/MM/YYYY format
  $('.datepicker').on('change', function() {
    var date = $(this).val();
    var regex = /^\d{2}\/\d{2}\/\d{4}$/;
    if (!regex.test(date)) {
      alert('Please enter date in DD/MM/YYYY format');
      $(this).focus();
    }
  });
});
</script>
</body>
</html>