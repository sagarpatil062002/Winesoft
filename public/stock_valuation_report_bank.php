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

// Extract class SGROUP values for filtering (since the function returns SGROUP)
$allowed_sgroups = [];
$class_descriptions = [];

if (!empty($available_classes) && is_array($available_classes)) {
    foreach ($available_classes as $class) {
        if (isset($class['SGROUP'])) {
            $allowed_sgroups[] = $class['SGROUP'];
            $class_descriptions[$class['SGROUP']] = $class['DESC'] ?? 'Unknown';
        }
    }
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

// Function to get size description from size code by joining with tblsize
function getSizeDescription($conn, $size_code) {
    if (empty($size_code)) return '';
    
    $query = "SELECT SIZE_DESC, ML_VOLUME FROM tblsize WHERE SIZE_CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $size_code);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        return $row['SIZE_DESC'];
    }
    return $size_code;
}

// Function to get category name based on class from DETAILS and DETAILS2
function getCategoryName($conn, $details, $details2, $class_field, $size_code = null, $old_item_group = null) {
    static $size_cache = [];
    
    $details_upper = strtoupper($details ?? '');
    $details2_upper = strtoupper($details2 ?? '');
    $class_upper = strtoupper($class_field ?? '');
    
    // Get size info
    $ml_value = 0;
    $size_desc = '';
    
    if (!empty($size_code)) {
        $size_key = $size_code;
        if (!isset($size_cache[$size_key])) {
            $size_cache[$size_key] = getSizeDescription($conn, $size_code);
        }
        $size_desc = $size_cache[$size_key];
        
        // Extract ML value from size description
        if (preg_match('/(\d+)\s*ML/i', $size_desc, $matches)) {
            $ml_value = (int)$matches[1];
        }
    } elseif (!empty($old_item_group)) {
        // Try to get size from OLD_ITEM_GROUP in tblsize
        $size_key = 'old_' . $old_item_group;
        if (!isset($size_cache[$size_key])) {
            $size_query = "SELECT SIZE_DESC, ML_VOLUME FROM tblsize WHERE OLD_ITEM_GROUP = ? LIMIT 1";
            $size_stmt = $conn->prepare($size_query);
            $size_stmt->bind_param("s", $old_item_group);
            $size_stmt->execute();
            $size_result = $size_stmt->get_result();
            if ($size_row = $size_result->fetch_assoc()) {
                $size_desc = $size_row['SIZE_DESC'];
                $ml_value = (int)($size_row['ML_VOLUME'] ?? 0);
                $size_cache[$size_key] = $size_desc;
            } else {
                $size_cache[$size_key] = '';
            }
        } else {
            $size_desc = $size_cache[$size_key];
        }
    }
    
    // If still no size, try to extract from details2
    if ($ml_value == 0 && !empty($details2_upper)) {
        if (preg_match('/(\d+)\s*ML/i', $details2_upper, $matches)) {
            $ml_value = (int)$matches[1];
        }
    }
    
    // Determine base size from ML value
    $base_size = 'Other';
    if ($ml_value >= 15000) $base_size = '15 Ltr+';
    elseif ($ml_value >= 3000) $base_size = '3000 ML';
    elseif ($ml_value >= 2000) $base_size = '2000 ML';
    elseif ($ml_value >= 1750) $base_size = '1750 ML';
    elseif ($ml_value >= 1500) $base_size = '1500 ML';
    elseif ($ml_value >= 1000) $base_size = '1000 ML';
    elseif ($ml_value >= 750) $base_size = '750 ML';
    elseif ($ml_value >= 700) $base_size = '700 ML';
    elseif ($ml_value >= 650) $base_size = '650 ML';
    elseif ($ml_value >= 500) $base_size = '500 ML';
    elseif ($ml_value >= 375) $base_size = '375 ML';
    elseif ($ml_value >= 350) $base_size = '350 ML';
    elseif ($ml_value >= 330) $base_size = '330 ML';
    elseif ($ml_value >= 275) $base_size = '275 ML';
    elseif ($ml_value >= 250) $base_size = '250 ML';
    elseif ($ml_value >= 200) $base_size = '200 ML';
    elseif ($ml_value >= 180) $base_size = '180 ML';
    elseif ($ml_value >= 170) $base_size = '170 ML';
    elseif ($ml_value >= 90) $base_size = '90 ML';
    elseif ($ml_value >= 60) $base_size = '60 ML';
    elseif ($ml_value >= 50) $base_size = '50 ML';
    
    // Determine main category from class field and details
    if ($class_upper == 'C' || strpos($details_upper, 'COUNTRY') !== false || strpos($details2_upper, 'COUNTRY') !== false) {
        return 'Country Liquor - ' . $base_size;
    } elseif (strpos($details_upper, 'WINE') !== false || strpos($details2_upper, 'WINE') !== false) {
        return 'Wine - ' . $base_size;
    } elseif (strpos($details_upper, 'BEER') !== false || strpos($details2_upper, 'BEER') !== false) {
        if (strpos($details2_upper, 'MILD') !== false) {
            return 'Mild Beer - ' . $base_size;
        } else {
            return 'Beer - ' . $base_size;
        }
    } elseif (strpos($details_upper, 'WHISK') !== false || strpos($details2_upper, 'WHISK') !== false) {
        return 'Foreign Liquor - Whisky - ' . $base_size;
    } elseif (strpos($details_upper, 'VODKA') !== false || strpos($details2_upper, 'VODKA') !== false) {
        return 'Foreign Liquor - Vodka - ' . $base_size;
    } elseif (strpos($details_upper, 'RUM') !== false || strpos($details2_upper, 'RUM') !== false) {
        return 'Foreign Liquor - Rum - ' . $base_size;
    } elseif (strpos($details_upper, 'BRAND') !== false || strpos($details2_upper, 'BRAND') !== false) {
        return 'Foreign Liquor - Brandy - ' . $base_size;
    } elseif (strpos($details_upper, 'GIN') !== false || strpos($details2_upper, 'GIN') !== false) {
        return 'Foreign Liquor - Gin - ' . $base_size;
    } elseif (strpos($details_upper, 'SODA') !== false || strpos($details2_upper, 'SODA') !== false) {
        return 'Others - Soda - ' . $base_size;
    } elseif (strpos($details_upper, 'COLD DRINK') !== false || strpos($details2_upper, 'COLD DRINK') !== false) {
        return 'Others - Cold Drinks - ' . $base_size;
    } else {
        return 'Other Products - ' . $base_size;
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
        // First, let's check the structure of tblitemmaster
        $columns_query = "SHOW COLUMNS FROM tblitemmaster";
        $columns_result = $conn->query($columns_query);
        $columns = [];
        while ($col = $columns_result->fetch_assoc()) {
            $columns[] = $col['Field'];
        }
        
        // Build the SELECT part based on actual columns
        $select_fields = "im.CODE, im.Print_Name, im.DETAILS, im.DETAILS2, im.CLASS, im.PPRICE, im.BPRICE, im.RPRICE, im.MPRICE";
        
        // Add these columns only if they exist
        if (in_array('SUB_CLASS', $columns)) {
            $select_fields .= ", im.SUB_CLASS";
        }
        if (in_array('ITEM_GROUP', $columns)) {
            $select_fields .= ", im.ITEM_GROUP";
        }
        if (in_array('LIQ_FLAG', $columns)) {
            $select_fields .= ", im.LIQ_FLAG";
        }
        if (in_array('SIZE_CODE', $columns)) {
            $select_fields .= ", im.SIZE_CODE";
        }
        
        // Build query with license type filtering using SGROUP values from license_functions
        if (!empty($allowed_sgroups)) {
            $sgroup_placeholders = implode(',', array_fill(0, count($allowed_sgroups), '?'));
            
            // Simpler query that doesn't rely on CLASS_CODE
            $query = "SELECT $select_fields,
                             ds.{$day_column} as CLOSING_STOCK,
                             sz.OLD_ITEM_GROUP, sz.SIZE_DESC, sz.ML_VOLUME
                      FROM tblitemmaster im
                      LEFT JOIN $stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
                      LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                      WHERE im.PPRICE > 0
                      AND COALESCE(ds.{$day_column}, 0) > 0
                      AND (";
            
            // Add conditions for each SGROUP based on patterns in DETAILS, DETAILS2, and CLASS
            $conditions = [];
            foreach ($allowed_sgroups as $sgroup) {
                switch ($sgroup) {
                    case 'W': // Whisky/Spirit
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%WHISK%' OR UPPER(im.DETAILS2) LIKE '%WHISK%' OR UPPER(im.DETAILS) LIKE '%SPIRIT%')";
                        break;
                    case 'V': // Wine
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%WINE%' OR UPPER(im.DETAILS2) LIKE '%WINE%')";
                        break;
                    case 'F': // Strong Beer
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%BEER%' OR UPPER(im.DETAILS2) LIKE '%BEER%') AND (UPPER(im.DETAILS2) NOT LIKE '%MILD%')";
                        break;
                    case 'M': // Mild Beer
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%MILD%' OR UPPER(im.DETAILS2) LIKE '%MILD%' OR UPPER(im.DETAILS) LIKE '%MILD BEER%')";
                        break;
                    case 'L': // Country Liquor
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%COUNTRY%' OR UPPER(im.DETAILS2) LIKE '%COUNTRY%' OR im.CLASS = 'C')";
                        break;
                    case 'D': // Brandy
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%BRAND%' OR UPPER(im.DETAILS2) LIKE '%BRAND%')";
                        break;
                    case 'K': // Vodka
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%VODKA%' OR UPPER(im.DETAILS2) LIKE '%VODKA%')";
                        break;
                    case 'G': // Gin
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%GIN%' OR UPPER(im.DETAILS2) LIKE '%GIN%')";
                        break;
                    case 'R': // Rum
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%RUM%' OR UPPER(im.DETAILS2) LIKE '%RUM%')";
                        break;
                    case 'O': // Others
                        $conditions[] = "(UPPER(im.DETAILS) LIKE '%SODA%' OR UPPER(im.DETAILS2) LIKE '%SODA%' OR UPPER(im.DETAILS) LIKE '%COLD DRINK%')";
                        break;
                }
            }
            
            if (!empty($conditions)) {
                $query .= implode(' OR ', $conditions);
            } else {
                $query .= "1=1"; // No specific conditions, return all
            }
            
            $query .= ")";
            
            // Prepare parameters
            $params = array_merge([$stock_month]);
            $types = 's';
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            $items = $result->fetch_all(MYSQLI_ASSOC);
            
            // If no items found with conditions, try a simpler approach - get all items with stock
            if (empty($items)) {
                $query = "SELECT $select_fields,
                                 ds.{$day_column} as CLOSING_STOCK,
                                 sz.OLD_ITEM_GROUP, sz.SIZE_DESC, sz.ML_VOLUME
                          FROM tblitemmaster im
                          LEFT JOIN $stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
                          LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                          WHERE im.PPRICE > 0
                          AND COALESCE(ds.{$day_column}, 0) > 0";
                
                $stmt = $conn->prepare($query);
                $stmt->bind_param("s", $stock_month);
                $stmt->execute();
                $result = $stmt->get_result();
                $items = $result->fetch_all(MYSQLI_ASSOC);
            }
            
            // Organize items by category
            foreach ($items as $item) {
                $category = getCategoryName(
                    $conn, 
                    $item['DETAILS'] ?? '', 
                    $item['DETAILS2'] ?? '', 
                    $item['CLASS'] ?? '',
                    $item['SIZE_CODE'] ?? '',
                    $item['OLD_ITEM_GROUP'] ?? null
                );
                
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
                
                // Create description
                $description = trim($item['Print_Name'] ?? $item['DETAILS'] ?? '');
                if (empty($description) && !empty($item['DETAILS2'])) {
                    $description = $item['DETAILS2'];
                }
                
                // For detailed report
                if (!isset($detailed_categories[$category])) {
                    $detailed_categories[$category] = [];
                }
                
                $detailed_categories[$category][] = [
                    'description' => $description,
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

// Organize categories into main groups
$organized_categories = [];
foreach (array_keys($summary_data) as $cat) {
    if (strpos($cat, 'Country Liquor') === 0) {
        $organized_categories['Country Liquor'][] = $cat;
    } elseif (strpos($cat, 'Wine') === 0) {
        $organized_categories['Wine'][] = $cat;
    } elseif (strpos($cat, 'Mild Beer') === 0) {
        $organized_categories['Mild Beer'][] = $cat;
    } elseif (strpos($cat, 'Beer') === 0) {
        $organized_categories['Beer'][] = $cat;
    } elseif (strpos($cat, 'Foreign Liquor') === 0) {
        $organized_categories['Foreign Liquor'][] = $cat;
    } elseif (strpos($cat, 'Others') === 0) {
        $organized_categories['Others'][] = $cat;
    } else {
        $organized_categories['Other Products'][] = $cat;
    }
}

// Sort categories within each group
foreach ($organized_categories as &$group) {
    sort($group);
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
              if (!empty($class_descriptions)) {
                  $display_names = [];
                  foreach ($class_descriptions as $sgroup => $desc) {
                      $display_names[] = $desc . ' (' . $sgroup . ')';
                  }
                  echo implode(', ', $display_names);
              } else {
                  echo 'All classes available for your license type';
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
                $rate_type === 'purc' ? 'Purchase Rate' : 
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
            <!-- Detailed Report -->
            <?php foreach ($organized_categories as $main_category => $subcategories): 
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
                    <th class="text-right">Closing Stock</th>
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
                    <th class="text-right">Closing Stock</th>
                    <th class="text-right">Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $summary_total_amount = 0;
                  $summary_total_stock = 0;
                  
                  foreach ($organized_categories as $main_category => $subcategories):
                      $main_category_stock = 0;
                      $main_category_amount = 0;
                      
                      // Check if any subcategory has data
                      $has_main_data = false;
                      foreach ($subcategories as $subcategory) {
                          if (isset($summary_data[$subcategory])) {
                              $has_main_data = true;
                              break;
                          }
                      }
                      if (!$has_main_data) continue;
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