<?php
session_start();

// Enhanced session validation
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values with validation
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Validate month format
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}

// Fetch company information with error handling
$companyName = "DIAMOND WINE SHOP";
$licenseNo = "3";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);

if ($companyStmt) {
    $companyStmt->bind_param("i", $compID);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    
    if ($row = $companyResult->fetch_assoc()) {
        $companyName = htmlspecialchars($row['COMP_NAME']);
        $licenseNo = $row['COMP_FLNO'] ? htmlspecialchars($row['COMP_FLNO']) : $licenseNo;
    }
    $companyStmt->close();
}

// Determine daily stock table with fallback
$dailyStockTable = "tbldailystock_" . intval($compID);

// Check if table exists
$tableCheckQuery = "SHOW TABLES LIKE '$dailyStockTable'";
$tableCheckResult = $conn->query($tableCheckQuery);
if ($tableCheckResult->num_rows == 0) {
    $dailyStockTable = "tbldailystock_1";
}

// Check for DAY_31 columns for month compatibility
$checkDay31Query = "SHOW COLUMNS FROM $dailyStockTable LIKE 'DAY_31_OPEN'";
$day31Result = $conn->query($checkDay31Query);
$hasDay31Columns = ($day31Result->num_rows > 0);

// Function to group sizes by base size
function getBaseSize($size) {
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize);
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize);
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize);
    return trim($baseSize);
}

// Define size columns
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

// Group sizes by base size
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

// Get display sizes
$display_sizes_s = array_keys($grouped_sizes_s);
$display_sizes_w = array_keys($grouped_sizes_w);
$display_sizes_fb = array_keys($grouped_sizes_fb);
$display_sizes_mb = array_keys($grouped_sizes_mb);

// Fetch class data
$classData = [];
$classQuery = "SELECT SGROUP, `DESC`, LIQ_FLAG FROM tblclass";
$classStmt = $conn->prepare($classQuery);

if ($classStmt) {
    $classStmt->execute();
    $classResult = $classStmt->get_result();
    while ($row = $classResult->fetch_assoc()) {
        $classData[$row['SGROUP']] = $row;
    }
    $classStmt->close();
}

// Fetch item master data
$items = [];
$itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster";
$itemStmt = $conn->prepare($itemQuery);

if ($itemStmt) {
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    while ($row = $itemResult->fetch_assoc()) {
        $items[$row['CODE']] = $row;
    }
    $itemStmt->close();
}

// Initialize monthly data structure
$monthly_data = [
    'Spirits' => [
        'opening' => array_fill_keys($display_sizes_s, 0),
        'received' => array_fill_keys($display_sizes_s, 0),
        'total' => array_fill_keys($display_sizes_s, 0),
        'sold' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0)
    ],
    'Wines' => [
        'opening' => array_fill_keys($display_sizes_w, 0),
        'received' => array_fill_keys($display_sizes_w, 0),
        'total' => array_fill_keys($display_sizes_w, 0),
        'sold' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0)
    ],
    'Fermented Beer' => [
        'opening' => array_fill_keys($display_sizes_fb, 0),
        'received' => array_fill_keys($display_sizes_fb, 0),
        'total' => array_fill_keys($display_sizes_fb, 0),
        'sold' => array_fill_keys($display_sizes_fb, 0),
        'closing' => array_fill_keys($display_sizes_fb, 0)
    ],
    'Mild Beer' => [
        'opening' => array_fill_keys($display_sizes_mb, 0),
        'received' => array_fill_keys($display_sizes_mb, 0),
        'total' => array_fill_keys($display_sizes_mb, 0),
        'sold' => array_fill_keys($display_sizes_mb, 0),
        'closing' => array_fill_keys($display_sizes_mb, 0)
    ]
];

// Size mapping
$size_mapping = [
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '90 ML' => '90 ML',
    '90 ML-100' => '90 ML',
    '90 ML-96' => '90 ML',
    '2000 ML' => '2000 ML',
    '2000 ML Pet' => '2000 ML',
    '650 ML Bottle' => '650 ML',
    '500 ML Bottle' => '500 ML',
    '500 ML Can' => '500 ML (CAN)',
    '330 ML Bottle' => '330 ML',
    '330 ML Can' => '330 ML (CAN)'
];

// Function to determine liquor type
function getLiquorType($class, $liq_flag) {
    if ($liq_flag == 'F') {
        switch ($class) {
            case 'F': return 'Fermented Beer';
            case 'M': return 'Mild Beer';
            case 'V': return 'Wines';
            default: return 'Spirits';
        }
    }
    return 'Spirits';
}

// Function to get grouped size
function getGroupedSize($size, $liquor_type) {
    global $grouped_sizes_s, $grouped_sizes_w, $grouped_sizes_fb, $grouped_sizes_mb;
    
    $baseSize = getBaseSize($size);
    
    switch ($liquor_type) {
        case 'Spirits':
            return in_array($baseSize, array_keys($grouped_sizes_s)) ? $baseSize : $baseSize;
        case 'Wines':
            return in_array($baseSize, array_keys($grouped_sizes_w)) ? $baseSize : $baseSize;
        case 'Fermented Beer':
            return in_array($baseSize, array_keys($grouped_sizes_fb)) ? $baseSize : $baseSize;
        case 'Mild Beer':
            return in_array($baseSize, array_keys($grouped_sizes_mb)) ? $baseSize : $baseSize;
    }
    
    return $baseSize;
}

// Process monthly data
$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$days_in_month = date('t', strtotime($month_start));

// Fetch stock data for the month
$monthQuery = "SELECT ITEM_CODE, LIQ_FLAG, ";

// Build dynamic columns
$columns = [];
for ($day = 1; $day <= $days_in_month; $day++) {
    $day_padded = sprintf('%02d', $day);
    
    if ($day <= $days_in_month || ($day <= 31 && $hasDay31Columns)) {
        $columns[] = "DAY_{$day_padded}_OPEN as open_{$day_padded}";
        $columns[] = "DAY_{$day_padded}_PURCHASE as purchase_{$day_padded}";
        $columns[] = "DAY_{$day_padded}_SALES as sales_{$day_padded}";
        $columns[] = "DAY_{$day_padded}_CLOSING as closing_{$day_padded}";
    }
}

$monthQuery .= implode(', ', $columns);
$monthQuery .= " FROM $dailyStockTable WHERE STK_MONTH = ?";

$monthStmt = $conn->prepare($monthQuery);

if ($monthStmt) {
    $monthStmt->bind_param("s", $month);
    $monthStmt->execute();
    $monthResult = $monthStmt->get_result();

    // Process each row
    while ($row = $monthResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        if (!isset($items[$item_code])) continue;
        
        $item_details = $items[$item_code];
        $size = $item_details['DETAILS2'];
        $class = $item_details['CLASS'];
        $liq_flag = $item_details['LIQ_FLAG'];
        
        $liquor_type = getLiquorType($class, $liq_flag);
        $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
        $grouped_size = getGroupedSize($excel_size, $liquor_type);
        
        // Process each day
        for ($day = 1; $day <= $days_in_month; $day++) {
            $day_padded = sprintf('%02d', $day);
            
            if (!isset($row["open_{$day_padded}"])) continue;
            
            $opening = floatval($row["open_{$day_padded}"]) ?? 0;
            $purchase = floatval($row["purchase_{$day_padded}"]) ?? 0;
            $sales = floatval($row["sales_{$day_padded}"]) ?? 0;
            $closing = floatval($row["closing_{$day_padded}"]) ?? 0;
            
            // Add to monthly data
            if (isset($monthly_data[$liquor_type])) {
                if ($day == 1) {
                    $monthly_data[$liquor_type]['opening'][$grouped_size] += $opening;
                }
                
                $monthly_data[$liquor_type]['received'][$grouped_size] += $purchase;
                $monthly_data[$liquor_type]['sold'][$grouped_size] += $sales;
                
                if ($day == $days_in_month) {
                    $monthly_data[$liquor_type]['closing'][$grouped_size] += $closing;
                }
            }
        }
    }
    
    $monthStmt->close();
}

// Calculate totals
foreach ($display_sizes_s as $size) {
    $monthly_data['Spirits']['total'][$size] = 
        $monthly_data['Spirits']['opening'][$size] + 
        $monthly_data['Spirits']['received'][$size];
}

foreach ($display_sizes_w as $size) {
    $monthly_data['Wines']['total'][$size] = 
        $monthly_data['Wines']['opening'][$size] + 
        $monthly_data['Wines']['received'][$size];
}

foreach ($display_sizes_fb as $size) {
    $monthly_data['Fermented Beer']['total'][$size] = 
        $monthly_data['Fermented Beer']['opening'][$size] + 
        $monthly_data['Fermented Beer']['received'][$size];
}

foreach ($display_sizes_mb as $size) {
    $monthly_data['Mild Beer']['total'][$size] = 
        $monthly_data['Mild Beer']['opening'][$size] + 
        $monthly_data['Mild Beer']['received'][$size];
}

// Calculate total columns
$total_columns = count($display_sizes_s) + count($display_sizes_w) + count($display_sizes_fb) + count($display_sizes_mb);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Monthly Register (FLR-4) - WineSoft</title>
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

    /* Print styles */
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

      <!-- Report Filters -->
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

      <!-- Report Results -->
      <div class="print-section">
        <div class="company-header">
          <h5>License to <?= $companyName ?> (<?= $year ?> - <?= $year + 1 ?>), Pune.</h5>
          <h5>[Monthly Register]</h5>
          <h6><?= $companyName ?></h6>
          <h6>LICENCE NO. :- <?= $licenseNo ?> | MORTIF.: | SPRINT 3</h6>
          <h6>Month: <?= date('F Y', strtotime($month . '-01')) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table">
            <thead>
              <tr>
                <th rowspan="3" class="description-col">Description</th>
                <th colspan="<?= count($display_sizes_s) ?>">SPIRITS</th>
                <th colspan="<?= count($display_sizes_w) ?>">WINES</th>
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
              <!-- Opening Balance -->
              <tr>
                <td class="description-col">Opening Balance of the Beginning of the Month :-</td>
                
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= number_format($monthly_data['Spirits']['opening'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= number_format($monthly_data['Wines']['opening'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= number_format($monthly_data['Fermented Beer']['opening'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= number_format($monthly_data['Mild Beer']['opening'][$size]) ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Received during the Current Month -->
              <tr>
                <td class="description-col">Received during the Current Month :-</td>
                
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= number_format($monthly_data['Spirits']['received'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= number_format($monthly_data['Wines']['received'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= number_format($monthly_data['Fermented Beer']['received'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= number_format($monthly_data['Mild Beer']['received'][$size]) ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Total -->
              <tr class="summary-row">
                <td class="description-col">Total :-</td>
                
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= number_format($monthly_data['Spirits']['total'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= number_format($monthly_data['Wines']['total'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= number_format($monthly_data['Fermented Beer']['total'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= number_format($monthly_data['Mild Beer']['total'][$size]) ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Sold during the Current Month -->
              <tr>
                <td class="description-col">Sold during the Current Month :-</td>
                
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= number_format($monthly_data['Spirits']['sold'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= number_format($monthly_data['Wines']['sold'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= number_format($monthly_data['Fermented Beer']['sold'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= number_format($monthly_data['Mild Beer']['sold'][$size]) ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Closing Balance -->
              <tr class="summary-row">
                <td class="description-col">Closing Balance at the end of Current Month :-</td>
                
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= number_format($monthly_data['Spirits']['closing'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= number_format($monthly_data['Wines']['closing'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= number_format($monthly_data['Fermented Beer']['closing'][$size]) ?></td>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= number_format($monthly_data['Mild Beer']['closing'][$size]) ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    
    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Print functionality
function printReport() {
  window.print();
}

// Auto-refresh on filter change
document.addEventListener('DOMContentLoaded', function() {
  const filters = document.querySelectorAll('select[name="month"], select[name="year"], select[name="mode"]');
  filters.forEach(filter => {
    filter.addEventListener('change', function() {
      document.querySelector('form.report-filters').submit();
    });
  });
});
</script>
</body>
</html> 