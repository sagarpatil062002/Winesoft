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

// Fetch all categories from tblcategory
$categoryQuery = "SELECT CATEGORY_CODE, CATEGORY_NAME, LIQ_FLAG FROM tblcategory ORDER BY CATEGORY_CODE";
$categoryResult = $conn->query($categoryQuery);
$categories = [];
while ($row = $categoryResult->fetch_assoc()) {
    $categories[$row['CATEGORY_CODE']] = $row;
}

// Fetch all classes from tblclass_new with their category mapping
$classQuery = "SELECT CLASS_CODE, CLASS_NAME, CATEGORY_CODE, OLD_CLASS_CODE FROM tblclass_new";
$classResult = $conn->query($classQuery);
$classes = [];
$class_to_category = [];
$class_to_name = [];
while ($row = $classResult->fetch_assoc()) {
    $classes[$row['CLASS_CODE']] = $row;
    $class_to_category[$row['CLASS_CODE']] = $row['CATEGORY_CODE'];
    $class_to_name[$row['CLASS_CODE']] = $row['CLASS_NAME'];
    
    // Also map by OLD_CLASS_CODE if available (for backward compatibility)
    if (!empty($row['OLD_CLASS_CODE'])) {
        $class_to_category[$row['OLD_CLASS_CODE']] = $row['CATEGORY_CODE'];
        $class_to_name[$row['OLD_CLASS_CODE']] = $row['CLASS_NAME'];
    }
}

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

// Function to determine liquor type based on CLASS and LIQ_FLAG using new database structure
function getLiquorType($class_code, $liq_flag, $class_to_category, $categories) {
    if ($liq_flag == 'F') {
        // Get category for this class
        $category_code = isset($class_to_category[$class_code]) ? $class_to_category[$class_code] : '';
        
        // Map category to liquor type
        switch ($category_code) {
            case 'CAT001': return 'Spirits';
            case 'CAT002': return 'Wines';
            case 'CAT003': return 'Fermented Beer';
            case 'CAT004': return 'Mild Beer';
            case 'CAT005': return 'Country Liquor';
            default: 
                // Fallback to old logic if category mapping fails
                switch ($class_code) {
                    case 'F': return 'Fermented Beer';
                    case 'M': return 'Mild Beer';
                    case 'V': return 'Wines';
                    default: return 'Spirits';
                }
        }
    } elseif ($liq_flag == 'C') {
        return 'Country Liquor';
    }
    return 'Others';
}

// Function to get class display name
function getClassDisplayName($class_code, $class_to_name) {
    return isset($class_to_name[$class_code]) ? $class_to_name[$class_code] : $class_code;
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

// Initialize organized data structure by Category and Class
$organized_data = [
    'categories' => []
];

foreach ($categories as $category_code => $category) {
    $organized_data['categories'][$category_code] = [
        'category_name' => $category['CATEGORY_NAME'],
        'liq_flag' => $category['LIQ_FLAG'],
        'classes' => []
    ];
}

// Process items and organize by category and class
foreach ($items as $item) {
    $closing_stock = (float)$item['CLOSING_STOCK'];
    
    // Skip items with zero closing stock
    if ($closing_stock <= 0) continue;
    
    $class_code = $item['CLASS'];
    $liq_flag = $item['LIQ_FLAG'];
    
    // Get category for this class
    $category_code = isset($class_to_category[$class_code]) ? $class_to_category[$class_code] : '';
    
    // If category not found, try to determine from liquor type
    if (empty($category_code)) {
        if ($liq_flag == 'C') {
            $category_code = 'CAT005'; // Country Liquor
        } elseif ($liq_flag == 'F') {
            // Determine based on class name or default to Spirits
            $liquor_type = getLiquorType($class_code, $liq_flag, $class_to_category, $categories);
            switch ($liquor_type) {
                case 'Spirits': $category_code = 'CAT001'; break;
                case 'Wines': $category_code = 'CAT002'; break;
                case 'Fermented Beer': $category_code = 'CAT003'; break;
                case 'Mild Beer': $category_code = 'CAT004'; break;
                default: $category_code = 'CAT001';
            }
        } else {
            $category_code = 'CAT008'; // General/Others
        }
    }
    
    // Ensure category exists in organized data
    if (!isset($organized_data['categories'][$category_code])) {
        $organized_data['categories'][$category_code] = [
            'category_name' => isset($categories[$category_code]) ? $categories[$category_code]['CATEGORY_NAME'] : 'Others',
            'liq_flag' => isset($categories[$category_code]) ? $categories[$category_code]['LIQ_FLAG'] : 'O',
            'classes' => []
        ];
    }
    
    // Get class display name
    $class_display_name = getClassDisplayName($class_code, $class_to_name);
    
    // Initialize class if not exists
    if (!isset($organized_data['categories'][$category_code]['classes'][$class_code])) {
        $organized_data['categories'][$category_code]['classes'][$class_code] = [
            'class_name' => $class_display_name,
            'items' => [],
            'totals' => ['stock' => 0, 'amount' => 0]
        ];
    }
    
    // Get rate and amount
    $rate = getItemRate($item, $rate_type);
    $amount = $rate * $closing_stock;
    
    // Extract brand name
    $brandName = getBrandName($item['DETAILS']);
    if (empty($brandName)) $brandName = "Unknown";
    
    // Get grouped size
    $liquor_type = getLiquorType($class_code, $liq_flag, $class_to_category, $categories);
    $grouped_size = getGroupedSize($item['DETAILS2'] ?? '', $liquor_type);
    
    // Add item to class
    $item_data = [
        'CODE' => $item['CODE'],
        'ItemName' => $item['Print_Name'] ?: $item['DETAILS'],
        'ItemSize' => $item['DETAILS2'] ?? '',
        'GroupedSize' => $grouped_size,
        'BrandName' => $brandName,
        'LiquorType' => $liquor_type,
        'CLASS' => $class_code,
        'ClassDisplayName' => $class_display_name,
        'ClosingStock' => $closing_stock,
        'Rate' => $rate,
        'Amount' => $amount
    ];
    
    $organized_data['categories'][$category_code]['classes'][$class_code]['items'][] = $item_data;
    $organized_data['categories'][$category_code]['classes'][$class_code]['totals']['stock'] += $closing_stock;
    $organized_data['categories'][$category_code]['classes'][$class_code]['totals']['amount'] += $amount;
}

// Calculate grand totals
$grand_total_stock = 0;
$grand_total_amount = 0;
foreach ($organized_data['categories'] as $category) {
    foreach ($category['classes'] as $class) {
        $grand_total_stock += $class['totals']['stock'];
        $grand_total_amount += $class['totals']['amount'];
    }
}

// Define category display order
$category_order = ['CAT001', 'CAT002', 'CAT003', 'CAT004', 'CAT005', 'CAT006', 'CAT007', 'CAT008'];
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
    .category-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 20px;
        margin: 25px 0 15px 0;
        border-radius: 8px;
        font-weight: 600;
        font-size: 1.2rem;
    }
    .class-header {
        background-color: #f0f0f0;
        padding: 8px 15px;
        margin: 15px 0 10px 0;
        border-left: 4px solid #007bff;
        font-weight: bold;
        font-size: 1.1rem;
    }
    .total-row {
        background-color: #e9ecef;
        font-weight: bold;
    }
    .grand-total-row {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        font-weight: bold;
        font-size: 1.1rem;
    }
    .table-container {
        overflow-x: auto;
        margin-bottom: 20px;
        border-radius: 8px;
        border: 1px solid #dee2e6;
    }
    .report-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 0;
    }
    .report-table th {
        background-color: #495b6b;
        color: white;
        padding: 8px;
        font-weight: 500;
    }
    .report-table th, .report-table td {
        border: 1px solid #dee2e6;
        padding: 6px;
    }
    .report-table td {
        background-color: white;
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
    .company-header {
        text-align: center;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 2px solid #333;
    }
    .company-header h2 {
        color: #333;
        margin-bottom: 5px;
    }
    @media print {
        .no-print {
            display: none !important;
        }
        .print-content {
            display: block !important;
        }
        .report-table {
            font-size: 9px;
        }
        .report-table th, .report-table td {
            padding: 3px;
        }
        .category-header {
            background: #667eea !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .report-table th {
            background-color: #495b6b !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
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
                        class="btn btn-outline-primary <?= $sequence === 'U' ? 'active' : '' ?>">
                  User Defined
                </button>
                <button type="submit" name="sequence" value="S" 
                        class="btn btn-outline-primary <?= $sequence === 'S' ? 'active' : '' ?>">
                  System Defined
                </button>
              </div>
            </div>
            
            <div class="col-md-3">
              <label class="form-label">Mode:</label>
              <div class="btn-group w-100" role="group">
                <button type="submit" name="mode" value="D" 
                        class="btn btn-outline-primary <?= $mode === 'D' ? 'active' : '' ?>">
                  Detailed
                </button>
                <button type="submit" name="mode" value="S" 
                        class="btn btn-outline-primary <?= $mode === 'S' ? 'active' : '' ?>">
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
        <div class="company-header">
          <h2><?= htmlspecialchars($companyName) ?></h2>
          <p>License Type: <?= htmlspecialchars($license_type) ?></p>
          <p>Item Wise Closing Stock Statement As On <?= date('d-M-Y', strtotime($db_date)) ?></p>
          <p>Rate Type: <?= 
              $rate_type === 'mrp' ? 'MRP Rate' : 
              ($rate_type === 'brate' ? 'Base Rate' : 
              ($rate_type === 'prate' ? 'Purchase Rate' : 'Retail Rate'))
          ?></p>
        </div>

        <?php if ($mode === 'D'): ?>
          <!-- Detailed Report with Category and Class Grouping -->
          <?php 
          $global_sr_no = 1;
          foreach ($category_order as $cat_code): 
              if (!isset($organized_data['categories'][$cat_code]) || empty($organized_data['categories'][$cat_code]['classes'])) continue;
              
              $category = $organized_data['categories'][$cat_code];
          ?>
            <div class="category-header"><?= strtoupper($category['category_name']) ?></div>
            
            <?php foreach ($category['classes'] as $class_code => $class): 
                if (empty($class['items'])) continue;
                
                // Sort items based on sequence
                $items = $class['items'];
                if ($sequence === 'S') {
                    usort($items, function($a, $b) {
                        return strcmp($a['BrandName'], $b['BrandName']) ?:
                               strcmp($a['ItemName'], $b['ItemName']);
                    });
                }
            ?>
              <div class="class-header"><?= strtoupper($class['class_name']) ?></div>
              
              <div class="table-container">
                <table class="report-table">
                  <thead>
                    <tr>
                      <th>Sr. No.</th>
                      <th>Item Code</th>
                      <th>Item Description</th>
                      <th>Size</th>
                      <th>Brand Name</th>
                      <th class="text-right">Closing Stock</th>
                      <th class="text-right">Rate (<?= 
                          $rate_type === 'mrp' ? 'MRP' : 
                          ($rate_type === 'brate' ? 'Base' : 
                          ($rate_type === 'prate' ? 'Purchase' : 'Retail'))
                      ?>)</th>
                      <th class="text-right">Amount</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($items as $item): ?>
                      <tr>
                        <td class="text-center"><?= $global_sr_no++ ?></td>
                        <td><?= htmlspecialchars($item['CODE']) ?></td>
                        <td><?= htmlspecialchars($item['ItemName']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($item['ItemSize']) ?></td>
                        <td><?= htmlspecialchars($item['BrandName']) ?></td>
                        <td class="text-right"><?= number_format($item['ClosingStock'], 0) ?></td>
                        <td class="text-right"><?= number_format($item['Rate'], 2) ?></td>
                        <td class="text-right"><?= number_format($item['Amount'], 2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    
                    <!-- Class Total Row -->
                    <tr class="total-row">
                      <td colspan="5" class="text-end"><strong>Total <?= $class['class_name'] ?>:</strong></td>
                      <td class="text-right"><strong><?= number_format($class['totals']['stock'], 0) ?></strong></td>
                      <td></td>
                      <td class="text-right"><strong><?= number_format($class['totals']['amount'], 2) ?></strong></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
          
          <!-- Grand Total -->
          <div class="table-container" style="margin-top: 25px;">
            <table class="report-table">
              <tr class="grand-total-row">
                <td colspan="5" class="text-end"><strong>GRAND TOTAL:</strong></td>
                <td class="text-right"><strong><?= number_format($grand_total_stock, 0) ?></strong></td>
                <td></td>
                <td class="text-right"><strong><?= number_format($grand_total_amount, 2) ?></strong></td>
              </tr>
            </table>
          </div>
          
        <?php else: ?>
          <!-- Summary Report with Category and Class Grouping -->
          <?php 
          $global_sr_no = 1;
          foreach ($category_order as $cat_code): 
              if (!isset($organized_data['categories'][$cat_code]) || empty($organized_data['categories'][$cat_code]['classes'])) continue;
              
              $category = $organized_data['categories'][$cat_code];
          ?>
            <div class="category-header"><?= strtoupper($category['category_name']) ?></div>
            
            <?php foreach ($category['classes'] as $class_code => $class): 
                if (empty($class['items'])) continue;
                
                // Group items by brand for summary
                $brands = [];
                $size_list = [];
                
                foreach ($class['items'] as $item) {
                    $brand = $item['BrandName'];
                    $size = $item['GroupedSize'] ?: $item['ItemSize'];
                    
                    if (!isset($brands[$brand])) {
                        $brands[$brand] = [];
                    }
                    
                    if (!isset($brands[$brand][$size])) {
                        $brands[$brand][$size] = 0;
                    }
                    
                    $brands[$brand][$size] += $item['ClosingStock'];
                    
                    if (!in_array($size, $size_list)) {
                        $size_list[] = $size;
                    }
                }
                
                // Sort sizes naturally
                natsort($size_list);
                $size_list = array_values($size_list);
            ?>
              <div class="class-header"><?= strtoupper($class['class_name']) ?></div>
              
              <div class="table-container">
                <table class="report-table">
                  <thead>
                    <tr>
                      <th>Sr. No.</th>
                      <th>Brand Name</th>
                      <?php foreach ($size_list as $size): ?>
                        <th class="size-column"><?= htmlspecialchars($size) ?></th>
                      <?php endforeach; ?>
                      <th class="size-column">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php 
                    ksort($brands);
                    foreach ($brands as $brand => $sizes):
                        $brand_total = 0;
                    ?>
                      <tr>
                        <td class="text-center"><?= $global_sr_no++ ?></td>
                        <td><strong><?= htmlspecialchars($brand) ?></strong></td>
                        <?php foreach ($size_list as $size): 
                            $quantity = isset($sizes[$size]) ? $sizes[$size] : 0;
                            $brand_total += $quantity;
                        ?>
                          <td class="size-column"><?= $quantity > 0 ? number_format($quantity, 0) : '-' ?></td>
                        <?php endforeach; ?>
                        <td class="size-column" style="font-weight: bold;"><?= number_format($brand_total, 0) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    
                    <!-- Class Total Row -->
                    <tr class="total-row">
                      <td colspan="2" style="font-weight: bold;">Total <?= $class['class_name'] ?></td>
                      <?php 
                      $class_total = 0;
                      foreach ($size_list as $size):
                          $size_total = 0;
                          foreach ($brands as $sizes) {
                              if (isset($sizes[$size])) {
                                  $size_total += $sizes[$size];
                              }
                          }
                          $class_total += $size_total;
                      ?>
                        <td class="size-column" style="font-weight: bold;"><?= $size_total > 0 ? number_format($size_total, 0) : '-' ?></td>
                      <?php endforeach; ?>
                      <td class="size-column" style="font-weight: bold;"><?= number_format($class_total, 0) ?></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
          
          <!-- Grand Total -->
          <div class="table-container" style="margin-top: 25px;">
            <table class="report-table">
              <tr class="grand-total-row">
                <td colspan="2" class="text-end"><strong>GRAND TOTAL:</strong></td>
                <td class="text-right" colspan="<?= count($size_list) ?>"><strong><?= number_format($grand_total_stock, 0) ?></strong></td>
              </tr>
            </table>
          </div>
        <?php endif; ?>
        
        <div class="footer-info text-center mt-3">
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