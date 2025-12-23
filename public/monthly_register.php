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

// Get company ID from session
$compID = $_SESSION['CompID'];

// Handle license functions safely
$license_type = 'FL';
$allowed_classes = [];
$available_classes = [];

if (file_exists('license_functions.php')) {
    require_once 'license_functions.php';
    $company_id = $_SESSION['CompID'];
    
    if (function_exists('getCompanyLicenseType')) {
        $license_type = getCompanyLicenseType($company_id, $conn);
    }
    
    if (function_exists('getClassesByLicenseType')) {
        $available_classes = getClassesByLicenseType($license_type, $conn);
        foreach ($available_classes as $class) {
            if (isset($class['SGROUP'])) {
                $allowed_classes[] = $class['SGROUP'];
            }
        }
    }
} else {
    $license_type = 'FL';
    $allowed_classes = ['W', 'D', 'G', 'K', 'R', 'I', 'F', 'M', 'V', 'Q'];
}

// Default values
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Fetch company information
$companyName = "Company Name";
$licenseNo = "N/A";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
if ($companyStmt = $conn->prepare($companyQuery)) {
    $companyStmt->bind_param("i", $compID);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    if ($row = $companyResult->fetch_assoc()) {
        $companyName = $row['COMP_NAME'] ?? $companyName;
        $licenseNo = $row['COMP_FLNO'] ?? $licenseNo;
    }
    $companyStmt->close();
}

// Function to get correct table name
function getTableForMonth($conn, $compID, $month) {
    $current_month = date('Y-m');
    $tablePrefix = "tbldailystock_" . $compID;
    
    if ($month == $current_month) {
        $tableName = $tablePrefix;
    } else {
        $month_num = date('m', strtotime($month . '-01'));
        $year_short = date('y', strtotime($month . '-01'));
        $tableName = $tablePrefix . "_" . $month_num . "_" . $year_short;
    }
    
    $tableCheckQuery = "SHOW TABLES LIKE '$tableName'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult->num_rows == 0) {
        $tableName = $tablePrefix;
        $tableCheckQuery2 = "SHOW TABLES LIKE '$tableName'";
        $tableCheckResult2 = $conn->query($tableCheckQuery2);
        if ($tableCheckResult2->num_rows == 0) {
            return false;
        }
    }
    
    return $tableName;
}

// Fixed Categorization Function
function getLiquorType($class) {
    $class = strtoupper(trim($class));
    
    // SPIRITS: W, D, G, K, R, I
    if (in_array($class, ['W', 'D', 'G', 'K', 'R', 'I'])) {
        return 'Spirits';
    }
    // MILD BEER: M class
    elseif ($class == 'M') {
        return 'Mild Beer';
    }
    // FERMENTED BEER: F class
    elseif ($class == 'F') {
        return 'Fermented Beer';
    }
    // WINE: V, Q classes
    elseif (in_array($class, ['V', 'Q'])) {
        return 'Wines';
    }
    // COUNTRY LIQUOR: C, B, S
    elseif (in_array($class, ['C', 'B', 'S'])) {
        return 'Country Liquor';
    }
    
    return 'Other';
}

// Fixed Size Normalization Function
function normalizeSize($size, $class = '') {
    $size = trim($size);
    $class = strtoupper(trim($class));
    
    // Clean the size string - remove extra text
    $clean_size = preg_replace('/\s*\([^)]*\)/', '', $size); // Remove parentheses content
    $clean_size = preg_replace('/\s*BOTTLE/i', '', $clean_size); // Remove "BOTTLE"
    $clean_size = preg_replace('/\s*CAN/i', '', $clean_size); // Remove "CAN"
    $clean_size = preg_replace('/\s*-\s*\d+/', '', $clean_size); // Remove dash numbers
    $clean_size = trim($clean_size);
    
    // Extract ML value from cleaned size
    preg_match('/(\d+)\s*ML/i', $clean_size, $matches);
    if (!isset($matches[1])) {
        // Try original size if cleaned doesn't work
        preg_match('/(\d+)\s*ML/i', $size, $matches);
        if (!isset($matches[1])) {
            return 'Other';
        }
    }
    
    $ml = (int)$matches[1];
    
    // Special handling for beer sizes
    if ($class == 'M' || $class == 'F') {
        if ($ml == 330) return '330 ML';
        if ($ml == 500) return '500 ML';
        if ($ml == 650) return '650 ML';
    }
    
    // General size grouping
    if ($ml >= 2000) return '2000 ML';
    elseif ($ml >= 1000) return '1000 ML';
    elseif ($ml >= 750) return '750 ML';
    elseif ($ml >= 700) return '700 ML';
    elseif ($ml >= 650) return '650 ML';
    elseif ($ml >= 500) return '500 ML';
    elseif ($ml >= 375) return '375 ML';
    elseif ($ml >= 330) return '330 ML';
    elseif ($ml >= 275) return '275 ML';
    elseif ($ml >= 250) return '250 ML';
    elseif ($ml >= 200) return '200 ML';
    elseif ($ml >= 180) return '180 ML';
    elseif ($ml >= 90) return '90 ML';
    elseif ($ml >= 60) return '60 ML';
    elseif ($ml >= 50) return '50 ML';
    
    return 'Other';
}

// Function to extract ML value from size string
function getMlFromSize($size) {
    preg_match('/(\d+(?:\.\d+)?)\s*ML/i', $size, $matches);
    return isset($matches[1]) ? (float)$matches[1] : 0;
}

// Define display sizes
$display_sizes_s = ['2000 ML', '1000 ML', '750 ML', '700 ML', '500 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_w = ['750 ML', '375 ML', '180 ML', '90 ML'];
$display_sizes_fb = ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];
$display_sizes_mb = ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];
$display_sizes_cl = ['1000 ML', '750 ML', '500 ML', '375 ML', '250 ML', '200 ML', '180 ML', '90 ML'];

// Initialize monthly data structure
$monthly_data = [
    'Spirits' => [
        'opening' => array_fill_keys($display_sizes_s, 0),
        'received' => array_fill_keys($display_sizes_s, 0),
        'sold' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0),
        'breakages' => array_fill_keys($display_sizes_s, 0)
    ],
    'Wines' => [
        'opening' => array_fill_keys($display_sizes_w, 0),
        'received' => array_fill_keys($display_sizes_w, 0),
        'sold' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0),
        'breakages' => array_fill_keys($display_sizes_w, 0)
    ],
    'Fermented Beer' => [
        'opening' => array_fill_keys($display_sizes_fb, 0),
        'received' => array_fill_keys($display_sizes_fb, 0),
        'sold' => array_fill_keys($display_sizes_fb, 0),
        'closing' => array_fill_keys($display_sizes_fb, 0),
        'breakages' => array_fill_keys($display_sizes_fb, 0)
    ],
    'Mild Beer' => [
        'opening' => array_fill_keys($display_sizes_mb, 0),
        'received' => array_fill_keys($display_sizes_mb, 0),
        'sold' => array_fill_keys($display_sizes_mb, 0),
        'closing' => array_fill_keys($display_sizes_mb, 0),
        'breakages' => array_fill_keys($display_sizes_mb, 0)
    ],
    'Country Liquor' => [
        'opening' => array_fill_keys($display_sizes_cl, 0),
        'received' => array_fill_keys($display_sizes_cl, 0),
        'sold' => array_fill_keys($display_sizes_cl, 0),
        'closing' => array_fill_keys($display_sizes_cl, 0),
        'breakages' => array_fill_keys($display_sizes_cl, 0)
    ]
];

// Fetch item master data
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2 as SIZE, CLASS FROM tblitemmaster WHERE CLASS IN ($class_placeholders)";
    
    if ($itemStmt = $conn->prepare($itemQuery)) {
        $types = str_repeat('s', count($allowed_classes));
        $itemStmt->bind_param($types, ...$allowed_classes);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        while ($row = $itemResult->fetch_assoc()) {
            $items[$row['CODE']] = $row;
        }
        $itemStmt->close();
    }
} else {
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2 as SIZE, CLASS FROM tblitemmaster";
    if ($itemStmt = $conn->prepare($itemQuery)) {
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        while ($row = $itemResult->fetch_assoc()) {
            $items[$row['CODE']] = $row;
        }
        $itemStmt->close();
    }
}

// Get Opening Balance
$dailyStockTable = getTableForMonth($conn, $compID, $month);
$data_fetched = false;
$total_opening = 0;

if ($dailyStockTable) {
    $checkColumnQuery = "SHOW COLUMNS FROM $dailyStockTable LIKE 'DAY_01_OPEN'";
    $columnResult = $conn->query($checkColumnQuery);
    
    if ($columnResult && $columnResult->num_rows > 0) {
        $openingQuery = "SELECT ds.ITEM_CODE, ds.DAY_01_OPEN as opening 
                        FROM $dailyStockTable ds
                        WHERE ds.STK_MONTH = ?";
        
        if ($openingStmt = $conn->prepare($openingQuery)) {
            $openingStmt->bind_param("s", $month);
            $openingStmt->execute();
            $openingResult = $openingStmt->get_result();
            
            while ($row = $openingResult->fetch_assoc()) {
                $item_code = $row['ITEM_CODE'];
                $opening_balance = (int)$row['opening'];
                
                if ($opening_balance <= 0 || !isset($items[$item_code])) {
                    continue;
                }
                
                $item_details = $items[$item_code];
                $size = $item_details['SIZE'];
                $class = $item_details['CLASS'];
                
                $total_opening += $opening_balance;
                
                // Determine category based on mode
                if ($mode == 'Country Liquor') {
                    $category = getLiquorType($class);
                    if ($category != 'Country Liquor') {
                        continue;
                    }
                } else {
                    $category = getLiquorType($class);
                    if ($category == 'Country Liquor' || $category == 'Other') {
                        continue;
                    }
                }
                
                $normalized_size = normalizeSize($size, $class);
                
                if (isset($monthly_data[$category]['opening'][$normalized_size])) {
                    $monthly_data[$category]['opening'][$normalized_size] += $opening_balance;
                }
            }
            $openingStmt->close();
            $data_fetched = true;
        }
    }
}

// Calculate totals
$spirits_total = array_sum($monthly_data['Spirits']['opening']);
$wine_total = array_sum($monthly_data['Wines']['opening']);
$fermented_beer_total = array_sum($monthly_data['Fermented Beer']['opening']);
$mild_beer_total = array_sum($monthly_data['Mild Beer']['opening']);
$country_liquor_total = array_sum($monthly_data['Country Liquor']['opening']);

if ($mode == 'Country Liquor') {
    $calculated_total = $country_liquor_total;
} else {
    $calculated_total = $spirits_total + $wine_total + $fermented_beer_total + $mild_beer_total;
}

// Calculate closing balance
foreach ($display_sizes_s as $size) {
    $monthly_data['Spirits']['closing'][$size] = $monthly_data['Spirits']['opening'][$size];
}
foreach ($display_sizes_w as $size) {
    $monthly_data['Wines']['closing'][$size] = $monthly_data['Wines']['opening'][$size];
}
foreach ($display_sizes_fb as $size) {
    $monthly_data['Fermented Beer']['closing'][$size] = $monthly_data['Fermented Beer']['opening'][$size];
}
foreach ($display_sizes_mb as $size) {
    $monthly_data['Mild Beer']['closing'][$size] = $monthly_data['Mild Beer']['opening'][$size];
}
foreach ($display_sizes_cl as $size) {
    $monthly_data['Country Liquor']['closing'][$size] = $monthly_data['Country Liquor']['opening'][$size];
}

// Calculate summary in liters
$summary_liters = [
    'Spirits' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'closing' => 0],
    'Wines' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'closing' => 0],
    'Fermented Beer' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'closing' => 0],
    'Mild Beer' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'closing' => 0],
    'Country Liquor' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'closing' => 0]
];

// Convert to liters
foreach ($display_sizes_s as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;
    $summary_liters['Spirits']['opening'] += $monthly_data['Spirits']['opening'][$size] * $liters_factor;
    $summary_liters['Spirits']['closing'] += $monthly_data['Spirits']['closing'][$size] * $liters_factor;
}

foreach ($display_sizes_w as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;
    $summary_liters['Wines']['opening'] += $monthly_data['Wines']['opening'][$size] * $liters_factor;
    $summary_liters['Wines']['closing'] += $monthly_data['Wines']['closing'][$size] * $liters_factor;
}

foreach ($display_sizes_fb as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;
    $summary_liters['Fermented Beer']['opening'] += $monthly_data['Fermented Beer']['opening'][$size] * $liters_factor;
    $summary_liters['Fermented Beer']['closing'] += $monthly_data['Fermented Beer']['closing'][$size] * $liters_factor;
}

foreach ($display_sizes_mb as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;
    $summary_liters['Mild Beer']['opening'] += $monthly_data['Mild Beer']['opening'][$size] * $liters_factor;
    $summary_liters['Mild Beer']['closing'] += $monthly_data['Mild Beer']['closing'][$size] * $liters_factor;
}

foreach ($display_sizes_cl as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;
    $summary_liters['Country Liquor']['opening'] += $monthly_data['Country Liquor']['opening'][$size] * $liters_factor;
    $summary_liters['Country Liquor']['closing'] += $monthly_data['Country Liquor']['closing'][$size] * $liters_factor;
}

// Format liters
foreach ($summary_liters as $category => $data) {
    foreach ($data as $key => $value) {
        $summary_liters[$category][$key] = number_format($value, 2);
    }
}

// Calculate column counts
if ($mode == 'Country Liquor') {
    $total_columns = count($display_sizes_cl);
} else {
    $total_columns = count($display_sizes_s) + count($display_sizes_w) + count($display_sizes_fb) + count($display_sizes_mb);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Monthly Register (FLR-4) - <?= htmlspecialchars($companyName) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    body {
      font-size: 12px;
      background-color: #f8f9fa;
    }
    .company-header {
      text-align: center;
      margin-bottom: 15px;
      padding: 10px;
    }
    .company-header h1 {
      font-size: 18px;
      font-weight: bold;
      margin-bottom: 5px;
    }
    .company-header h5 {
      font-size: 14px;
      margin-bottom: 3px;
    }
    .company-header h6 {
      font-size: 12px;
      margin-bottom: 5px;
    }
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 15px;
      font-size: 10px;
    }
    .report-table th, .report-table td {
      border: 1px solid #000;
      padding: 4px;
      text-align: center;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.2;
    }
    .report-table th {
      background-color: #f0f0f0;
      font-weight: bold;
      padding: 6px 3px;
    }
    .vertical-text {
      writing-mode: vertical-lr;
      transform: rotate(180deg);
      text-align: center;
      white-space: nowrap;
      padding: 8px 2px;
      min-width: 20px;
    }
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
    }
    .filter-card {
      background-color: #f8f9fa;
    }
    .table-responsive {
      overflow-x: auto;
      max-width: 100%;
    }
    .action-controls {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .no-print {
      display: block;
    }
    .license-info {
        background-color: #d1ecf1;
        border: 1px solid #bee5eb;
        border-radius: 5px;
        padding: 10px;
        margin-bottom: 15px;
    }
    .data-summary {
      background-color: #e7f4e4;
      padding: 10px;
      margin-bottom: 15px;
      border-radius: 5px;
    }

    @media print {
      @page {
        size: legal landscape;
        margin: 0.2in;
      }
      body {
        margin: 0;
        padding: 0;
        font-size: 8px;
        line-height: 1;
        background: white;
        width: 100%;
        height: 100%;
        transform: scale(0.8);
        transform-origin: 0 0;
        width: 125%;
      }
      .no-print {
        display: none !important;
      }
      body * {
        visibility: hidden;
      }
      .print-section, .print-section * {
        visibility: visible;
      }
      .print-section {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
      }
      .company-header {
        text-align: center;
        margin-bottom: 5px;
        padding: 2px;
        page-break-after: avoid;
      }
      .company-header h1 {
        font-size: 12px !important;
        margin-bottom: 1px !important;
      }
      .company-header h5 {
        font-size: 9px !important;
        margin-bottom: 1px !important;
      }
      .company-header h6 {
        font-size: 8px !important;
        margin-bottom: 2px !important;
      }
      .table-responsive {
        overflow: visible;
        width: 100%;
        height: auto;
      }
      .report-table {
        width: 100% !important;
        font-size: 6px !important;
        table-layout: fixed;
        border-collapse: collapse;
        page-break-inside: avoid;
      }
      .report-table th, .report-table td {
        padding: 1px !important;
        line-height: 1;
        height: 14px;
        min-width: 18px;
        max-width: 22px;
        font-size: 6px !important;
        border: 0.5px solid #000 !important;
      }
      .report-table th {
        background-color: #f0f0f0 !important;
        padding: 2px 1px !important;
        font-weight: bold;
      }
      .vertical-text {
        writing-mode: vertical-lr;
        transform: rotate(180deg);
        text-align: center;
        white-space: nowrap;
        padding: 1px !important;
        font-size: 5px !important;
        min-width: 15px;
        max-width: 18px;
        line-height: 1;
        height: 25px !important;
      }
      .description-col {
        width: 120px !important;
        min-width: 120px !important;
        max-width: 120px !important;
        text-align: left !important;
      }
      .size-col {
        width: 18px !important;
        min-width: 18px !important;
        max-width: 18px !important;
      }
      .summary-row {
        background-color: #f8f9fa !important;
        font-weight: bold;
      }
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 6px;
        page-break-before: avoid;
      }
      tr {
        page-break-inside: avoid;
        page-break-after: auto;
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
      <h3 class="mb-4">Monthly Register (FLR-4)</h3>

      <div class="license-info no-print">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Mode: <?= htmlspecialchars($mode) ?> | Showing items for: 
          <?php 
          if (!empty($available_classes)) {
              $class_names = [];
              foreach ($available_classes as $class) {
                  if (isset($class['DESC']) && isset($class['SGROUP'])) {
                      $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                  }
              }
              echo implode(', ', $class_names);
          } else {
              echo 'All available classes';
          }
          ?>
          </p>
      </div>

      <div class="data-summary no-print">
        <h5>Data Summary - <?= $mode ?></h5>
        <div class="row">
          <?php if ($mode == 'Country Liquor'): ?>
            <div class="col-md-12">
              <strong>Country Liquor Total:</strong> <?= number_format($country_liquor_total) ?> units
            </div>
          <?php else: ?>
            <div class="col-md-3">
              <strong>Spirits (W,D,G,K,R,I):</strong> <?= number_format($spirits_total) ?> units
            </div>
            <div class="col-md-2">
              <strong>Mild Beer (M):</strong> <?= number_format($mild_beer_total) ?> units
            </div>
            <div class="col-md-2">
              <strong>Fermented Beer (F):</strong> <?= number_format($fermented_beer_total) ?> units
            </div>
            <div class="col-md-2">
              <strong>Wine (V,Q):</strong> <?= number_format($wine_total) ?> units
            </div>
            <div class="col-md-3">
              <strong>Total:</strong> <?= number_format($calculated_total) ?> units
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-control">
                  <option value="Foreign Liquor" <?= $mode == 'Foreign Liquor' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="Country Liquor" <?= $mode == 'Country Liquor' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Month:</label>
                <select name="month" class="form-control">
                  <?php
                  $months = [
                    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
                    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
                    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                  ];
                  foreach ($months as $key => $name) {
                    $selected = ($month == date('Y') . '-' . $key) ? 'selected' : '';
                    echo "<option value=\"" . date('Y') . "-$key\" $selected>$name</option>";
                  }
                  ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Year:</label>
                <select name="year" class="form-control">
                  <?php
                  $current_year = date('Y');
                  for ($y = $current_year - 5; $y <= $current_year + 1; $y++) {
                    $selected = ($year == $y) ? 'selected' : '';
                    echo "<option value=\"$y\" $selected>$y</option>";
                  }
                  ?>
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

      <div class="print-section">
        <div class="company-header">
          <h5>License to <?= htmlspecialchars($companyName) ?> (<?= $year ?> - <?= $year + 1 ?>), Pune.</h5>
          <h5>[Monthly Register]</h5>
          <h6><?= htmlspecialchars($companyName) ?></h6>
          <h6>LICENCE NO. :- <?= htmlspecialchars($licenseNo) ?></h6>
          <h6>Month: <?= date('F Y', strtotime($month . '-01')) ?></h6>
          <h6>Mode: <?= htmlspecialchars($mode) ?></h6>
        </div>
        
        <?php if ($mode == 'Country Liquor'): ?>
          <!-- Country Liquor Table -->
          <div class="table-responsive">
            <table class="report-table">
              <thead>
                <tr>
                  <th rowspan="3" class="description-col">Description</th>
                  <th colspan="<?= count($display_sizes_cl) ?>">COUNTRY LIQUOR</th>
                </tr>
                <tr>
                  <?php foreach ($display_sizes_cl as $size): ?>
                    <th class="size-col vertical-text"><?= $size ?></th>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <th colspan="<?= $total_columns ?>">SOL D IND. OF BOTTLES (in min.)</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="description-col">Opening Balance of the Beginning of the Month :-</td>
                  <?php foreach ($display_sizes_cl as $size): ?>
                    <td><?= $monthly_data['Country Liquor']['opening'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td class="description-col">Received during the Current Month :-</td>
                  <?php foreach ($display_sizes_cl as $size): ?>
                    <td><?= $monthly_data['Country Liquor']['received'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td class="description-col">Sold during the Current Month :-</td>
                  <?php foreach ($display_sizes_cl as $size): ?>
                    <td><?= $monthly_data['Country Liquor']['sold'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td class="description-col">Breakages during the Current Month :-</td>
                  <?php foreach ($display_sizes_cl as $size): ?>
                    <td><?= $monthly_data['Country Liquor']['breakages'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td class="description-col">Closing Balance at the End of the Month :-</td>
                  <?php foreach ($display_sizes_cl as $size): ?>
                    <td><?= $monthly_data['Country Liquor']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
              </tbody>
            </table>
          </div>
          
          <!-- Summary Section for Country Liquor -->
          <div class="table-responsive mt-3">
            <table class="report-table">
              <thead>
                <tr>
                  <th colspan="3" style="text-align: center;">SUMMARY (IN LITERS) - COUNTRY LIQUOR</th>
                </tr>
                <tr>
                  <th></th>
                  <th>COUNTRY LIQUOR</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><strong>Op. Stk. (Ltrs.)</strong></td>
                  <td><?= $summary_liters['Country Liquor']['opening'] ?></td>
                </tr>
                <tr>
                  <td><strong>Receipts (Ltrs.)</strong></td>
                  <td>0.00</td>
                </tr>
                <tr>
                  <td><strong>Sold (Ltrs.)</strong></td>
                  <td>0.00</td>
                </tr>
                <tr>
                  <td><strong>Cl. Stk. (Ltrs.)</strong></td>
                  <td><?= $summary_liters['Country Liquor']['closing'] ?></td>
                </tr>
              </tbody>
            </table>
          </div>
          
        <?php else: ?>
          <!-- Foreign Liquor Table -->
          <div class="table-responsive">
            <table class="report-table">
              <thead>
                <tr>
                  <th rowspan="3" class="description-col">Description</th>
                  <th colspan="<?= count($display_sizes_s) ?>">SPIRITS</th>
                  <th colspan="<?= count($display_sizes_w) ?>">WINE</th>
                  <th colspan="<?= count($display_sizes_fb) ?>">FERMENTED BEER</th>
                  <th colspan="<?= count($display_sizes_mb) ?>">MILD BEER</th>
                </tr>
                <tr>
                  <?php foreach ($display_sizes_s as $size): ?>
                    <th class="size-col vertical-text"><?= $size ?></th>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_w as $size): ?>
                    <th class="size-col vertical-text"><?= $size ?></th>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <th class="size-col vertical-text"><?= $size ?></th>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <th class="size-col vertical-text"><?= $size ?></th>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <th colspan="<?= $total_columns ?>">SOL D IND. OF BOTTLES (in min.)</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td class="description-col">Opening Balance of the Beginning of the Month :-</td>
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $monthly_data['Spirits']['opening'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $monthly_data['Wines']['opening'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $monthly_data['Fermented Beer']['opening'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $monthly_data['Mild Beer']['opening'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td class="description-col">Received during the Current Month :-</td>
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $monthly_data['Spirits']['received'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $monthly_data['Wines']['received'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $monthly_data['Fermented Beer']['received'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $monthly_data['Mild Beer']['received'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td class="description-col">Sold during the Current Month :-</td>
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $monthly_data['Spirits']['sold'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $monthly_data['Wines']['sold'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $monthly_data['Fermented Beer']['sold'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $monthly_data['Mild Beer']['sold'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td class="description-col">Breakages during the Current Month :-</td>
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $monthly_data['Spirits']['breakages'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $monthly_data['Wines']['breakages'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $monthly_data['Fermented Beer']['breakages'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $monthly_data['Mild Beer']['breakages'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <td class="description-col">Closing Balance at the End of the Month :-</td>
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $monthly_data['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $monthly_data['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $monthly_data['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $monthly_data['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                </tr>
              </tbody>
            </table>
          </div>
          
          <!-- Summary Section for Foreign Liquor -->
          <div class="table-responsive mt-3">
            <table class="report-table">
              <thead>
                <tr>
                  <th colspan="5" style="text-align: center;">SUMMARY (IN LITERS) - FOREIGN LIQUOR</th>
                </tr>
                <tr>
                  <th></th>
                  <th>SPIRITS</th>
                  <th>WINE</th>
                  <th>FERMENTED BEER</th>
                  <th>MILD BEER</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td><strong>Op. Stk. (Ltrs.)</strong></td>
                  <td><?= $summary_liters['Spirits']['opening'] ?></td>
                  <td><?= $summary_liters['Wines']['opening'] ?></td>
                  <td><?= $summary_liters['Fermented Beer']['opening'] ?></td>
                  <td><?= $summary_liters['Mild Beer']['opening'] ?></td>
                </tr>
                <tr>
                  <td><strong>Receipts (Ltrs.)</strong></td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                </tr>
                <tr>
                  <td><strong>Sold (Ltrs.)</strong></td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                  <td>0.00</td>
                </tr>
                <tr>
                  <td><strong>Cl. Stk. (Ltrs.)</strong></td>
                  <td><?= $summary_liters['Spirits']['closing'] ?></td>
                  <td><?= $summary_liters['Wines']['closing'] ?></td>
                  <td><?= $summary_liters['Fermented Beer']['closing'] ?></td>
                  <td><?= $summary_liters['Mild Beer']['closing'] ?></td>
                </tr>
              </tbody>
            </table>
          </div>
          
        <?php endif; ?>
        
        <div class="footer-info" style="text-align: right;">
           <p>Authorised Signature</p>
           <p>Generated on: <?= date('d/m/Y h:i A') ?> | User: <?= $_SESSION['user_name'] ?? 'System' ?></p>
           <p>Total Units: <?= number_format($calculated_total) ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>
