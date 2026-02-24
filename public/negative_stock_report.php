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

// Debug: Check what keys are available in $available_classes
// Uncomment the following line for debugging if needed
// var_dump($available_classes); exit;

// Extract class codes for filtering - Check what key is actually used
$allowed_classes = [];
if (!empty($available_classes)) {
    foreach ($available_classes as $class) {
        // Check if CLASS_CODE exists, if not try other possible keys
        if (isset($class['CLASS_CODE'])) {
            $allowed_classes[] = $class['CLASS_CODE'];
        } elseif (isset($class['code'])) {
            $allowed_classes[] = $class['code'];
        } elseif (isset($class['SGROUP'])) {
            // Fallback to SGROUP if that's what's being returned
            $allowed_classes[] = $class['SGROUP'];
        } elseif (isset($class['class_code'])) {
            $allowed_classes[] = $class['class_code'];
        }
    }
}

// Get parameters
$stock_date = isset($_GET['stock_date']) ? $_GET['stock_date'] : date('d/m/Y');

// Convert date format for database
$date_parts = explode('/', $stock_date);
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

// Function to get liquor type based on category
function getLiquorTypeByCategory($category_code) {
    switch ($category_code) {
        case 'CAT001': return 'Spirits';
        case 'CAT002': return 'Wines';
        case 'CAT003': return 'Fermented Beer';
        case 'CAT004': return 'Mild Beer';
        case 'CAT005': return 'Country Liquor';
        default: return 'Other';
    }
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
        case 'Country Liquor':
            // For country liquor, return the base size as is
            return $baseSize;
    }
    
    return $baseSize;
}

// Updated query to join with new table structure - FIXED ALIAS ISSUE
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT 
                im.CODE, 
                im.Print_Name, 
                im.DETAILS, 
                im.DETAILS2, 
                cat.CATEGORY_CODE,
                cat.CATEGORY_NAME,
                cl.CLASS_CODE,
                cl.CLASS_NAME,
                im.PPRICE, 
                im.BPRICE,
                ds.DAY_{$day}_CLOSING as CLOSING_STOCK
              FROM tblitemmaster im
              LEFT JOIN tblclass_new cl ON im.CLASS = cl.CLASS_CODE
              LEFT JOIN tblcategory cat ON cl.CATEGORY_CODE = cat.CATEGORY_CODE
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
              WHERE cl.CLASS_CODE IN ($class_placeholders) 
              AND ds.DAY_{$day}_CLOSING < 0
              ORDER BY cat.CATEGORY_NAME, cl.CLASS_NAME, im.DETAILS, im.DETAILS2";
    
    $params = array_merge([$month_year], $allowed_classes);
    $types = str_repeat('s', count($params));
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
} else {
    // If no classes allowed, show empty result
    $query = "SELECT 
                im.CODE, 
                im.Print_Name, 
                im.DETAILS, 
                im.DETAILS2, 
                cat.CATEGORY_CODE,
                cat.CATEGORY_NAME,
                cl.CLASS_CODE,
                cl.CLASS_NAME,
                im.PPRICE, 
                im.BPRICE,
                ds.DAY_{$day}_CLOSING as CLOSING_STOCK
              FROM tblitemmaster im
              LEFT JOIN tblclass_new cl ON im.CLASS = cl.CLASS_CODE
              LEFT JOIN tblcategory cat ON cl.CATEGORY_CODE = cat.CATEGORY_CODE
              LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
              WHERE 1 = 0"; // Always false condition
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $month_year);
}

$stmt->execute();
$result = $stmt->get_result();
$negative_items = $result->fetch_all(MYSQLI_ASSOC);

// Restructure data by liquor type -> brand
$negative_brand_data_by_category = [
    'Spirits' => [],
    'Wines' => [],
    'Fermented Beer' => [],
    'Mild Beer' => [],
    'Country Liquor' => [],
    'Other' => []
];

// Define display sizes for each liquor type
$liquor_type_sizes = [
    'Spirits' => $display_sizes_s,
    'Wines' => $display_sizes_w,
    'Fermented Beer' => $display_sizes_fb,
    'Mild Beer' => $display_sizes_mb,
    'Country Liquor' => [],
    'Other' => []
];

// Process negative items
foreach ($negative_items as $item) {
    $category_code = $item['CATEGORY_CODE'] ?? '';
    $liquor_type = getLiquorTypeByCategory($category_code);
    $size = $item['DETAILS2'] ?? '';
    
    // Extract brand name
    $brandName = getBrandName($item['DETAILS']);
    if (empty($brandName)) continue;
    
    // Get grouped size for display
    $grouped_size = getGroupedSize($size, $liquor_type);
    
    if ($liquor_type === 'Country Liquor' || $liquor_type === 'Other') {
        // Handle sizes dynamically for Country Liquor and Other
        if (!isset($negative_brand_data_by_category[$liquor_type][$brandName])) {
            $negative_brand_data_by_category[$liquor_type][$brandName] = [];
        }
        
        if (!isset($negative_brand_data_by_category[$liquor_type][$brandName][$grouped_size])) {
            $negative_brand_data_by_category[$liquor_type][$brandName][$grouped_size] = 0;
        }
        
        $negative_brand_data_by_category[$liquor_type][$brandName][$grouped_size] += (float)$item['CLOSING_STOCK'];
        
        // Add to liquor_type_sizes
        if (!in_array($grouped_size, $liquor_type_sizes[$liquor_type])) {
            $liquor_type_sizes[$liquor_type][] = $grouped_size;
        }
    } else {
        // For other liquor types, only include if the grouped size exists in our display sizes
        if (in_array($grouped_size, $liquor_type_sizes[$liquor_type])) {
            // Initialize brand data if not exists
            if (!isset($negative_brand_data_by_category[$liquor_type][$brandName])) {
                $negative_brand_data_by_category[$liquor_type][$brandName] = array_fill_keys($liquor_type_sizes[$liquor_type], 0);
            }
            
            // Add negative closing stock to the brand and size
            $negative_brand_data_by_category[$liquor_type][$brandName][$grouped_size] += (float)$item['CLOSING_STOCK'];
        }
    }
}

// Sort dynamic sizes
foreach (['Country Liquor', 'Other'] as $type) {
    sort($liquor_type_sizes[$type]);
}

// Calculate negative totals for each liquor type
$negative_liquor_type_totals = [
    'Spirits' => array_fill_keys($display_sizes_s, 0),
    'Wines' => array_fill_keys($display_sizes_w, 0),
    'Fermented Beer' => array_fill_keys($display_sizes_fb, 0),
    'Mild Beer' => array_fill_keys($display_sizes_mb, 0),
    'Country Liquor' => array_fill_keys($liquor_type_sizes['Country Liquor'], 0),
    'Other' => array_fill_keys($liquor_type_sizes['Other'], 0)
];

$negative_grand_totals = [
    'Spirits' => 0,
    'Wines' => 0,
    'Fermented Beer' => 0,
    'Mild Beer' => 0,
    'Country Liquor' => 0,
    'Other' => 0
];

foreach ($negative_brand_data_by_category as $liquor_type => $brands) {
    foreach ($brands as $brand => $sizes) {
        foreach ($sizes as $size => $quantity) {
            if (isset($negative_liquor_type_totals[$liquor_type][$size])) {
                $negative_liquor_type_totals[$liquor_type][$size] += $quantity;
                $negative_grand_totals[$liquor_type] += $quantity;
            }
        }
    }
}

// Check if any negative items exist
$has_negative_items = false;
foreach ($negative_brand_data_by_category as $brands) {
    if (!empty($brands)) {
        $has_negative_items = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Negative Stock Report - WineSoft</title>
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
    .negative-quantity {
        color: #dc3545;
        font-weight: bold;
    }
    .brand-header {
        background-color: #f0f0f0;
        padding: 8px;
        margin-top: 20px;
        border-left: 4px solid #dc3545;
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
    .no-negative-stock {
        text-align: center;
        padding: 40px;
        color: #6c757d;
        font-style: italic;
    }
    .grand-total-section {
        background-color: #fff3cd;
        padding: 10px;
        margin-top: 20px;
        border-radius: 4px;
        border-left: 4px solid #ffc107;
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
        .negative-quantity {
            color: #000 !important;
            font-weight: bold;
        }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">ItemWise Negative Stock Report</h3>

      <!-- License Restriction Info -->
      <div class="license-info no-print">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing negative stock items for classes: 
              <?php 
              if (!empty($available_classes)) {
                  $class_names = [];
                  foreach ($available_classes as $class) {
                      // Try different possible keys for display
                      if (isset($class['CLASS_NAME'])) {
                          $class_names[] = $class['CLASS_NAME'] . ' (' . ($class['CLASS_CODE'] ?? $class['SGROUP'] ?? '') . ')';
                      } elseif (isset($class['DESC'])) {
                          $class_names[] = $class['DESC'] . ' (' . ($class['SGROUP'] ?? '') . ')';
                      } elseif (isset($class['name'])) {
                          $class_names[] = $class['name'];
                      }
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
            <div class="col-md-4">
              <label class="form-label">Stock Date:</label>
              <input type="text" name="stock_date" value="<?= htmlspecialchars($stock_date) ?>" 
                     class="form-control datepicker" placeholder="DD/MM/YYYY">
            </div>
            
            <div class="col-md-4 d-flex align-items-end">
              <button type="submit" class="btn btn-primary me-2">
                <i class="fas fa-filter"></i> Apply
              </button>
              <a href="negative_stock_report.php" class="btn btn-secondary">
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
            <p>ItemWise Negative Stock Report</p>
            <p>Stock Date : <?= htmlspecialchars($stock_date) ?></p>
          </div>
        </div>

        <?php if (!$has_negative_items): ?>
          <div class="no-negative-stock">
            <i class="fas fa-check-circle fa-3x mb-3" style="color: #28a745;"></i>
            <h4>No Negative Stock Items Found</h4>
            <p>All items have positive or zero closing stock as on <?= htmlspecialchars($stock_date) ?></p>
          </div>
        <?php else: ?>
          <?php foreach ($negative_brand_data_by_category as $liquor_type => $brands): 
              if (!empty($brands)): 
                  $display_sizes = $liquor_type_sizes[$liquor_type];
          ?>
          <div class="category-section">
            <h4 class="brand-header"><?= strtoupper($liquor_type) ?> - NEGATIVE STOCK</h4>
            <div class="table-container">
              <table class="report-table">
                <thead>
                  <tr>
                    <th>Sr. No.</th>
                    <th>Brand Name</th>
                    <?php foreach ($display_sizes as $size): ?>
                      <th class="text-right"><?= htmlspecialchars($size) ?></th>
                    <?php endforeach; ?>
                    <th class="text-right">Total</th>
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
                      <td class="text-right">
                        <?php if ($quantity < 0): ?>
                          <span class="negative-quantity"><?= number_format($quantity, 0) ?></span>
                        <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
                    <td class="text-right" style="font-weight: bold;">
                      <?php if ($brand_total < 0): ?>
                        <span class="negative-quantity"><?= number_format($brand_total, 0) ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  
                  <!-- Total Row -->
                  <tr class="total-row">
                    <td colspan="2" style="font-weight: bold;">Total</td>
                    <?php
                    $category_total = 0;
                    foreach ($display_sizes as $size):
                        $size_total = $negative_liquor_type_totals[$liquor_type][$size] ?? 0;
                        $category_total += $size_total;
                    ?>
                      <td class="text-right" style="font-weight: bold;">
                        <?php if ($size_total < 0): ?>
                          <span class="negative-quantity"><?= number_format($size_total, 0) ?></span>
                        <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
                    <td class="text-right" style="font-weight: bold;">
                      <?php if ($category_total < 0): ?>
                        <span class="negative-quantity"><?= number_format($category_total, 0) ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; endforeach; ?>
          
          <!-- Grand Total Summary -->
          <div class="grand-total-section">
            <h5>Negative Stock Summary</h5>
            <?php foreach ($negative_grand_totals as $liquor_type => $total): ?>
              <?php if ($total < 0): ?>
                <p><strong><?= $liquor_type ?>:</strong> <span class="negative-quantity"><?= number_format($total, 0) ?></span></p>
              <?php endif; ?>
            <?php endforeach; ?>
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

// Initialize datepicker if you have one
$(document).ready(function() {
  $('.datepicker').datepicker({
    format: 'dd/mm/yyyy',
    autoclose: true
  });
});
</script>
</body>
</html>