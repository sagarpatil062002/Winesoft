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

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// Fetch company name and license number
$companyName = "DIAMOND WINE SHOP";
$licenseNo = "3";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
    $licenseNo = $row['COMP_FLNO'] ? $row['COMP_FLNO'] : $licenseNo;
}
$companyStmt->close();

// Determine which daily stock table to use based on company ID
$dailyStockTable = "tbldailystock_" . $compID;

// Check if the table exists, if not use default tbldailystock_1
$tableCheckQuery = "SHOW TABLES LIKE '$dailyStockTable'";
$tableCheckResult = $conn->query($tableCheckQuery);
if ($tableCheckResult->num_rows == 0) {
    $dailyStockTable = "tbldailystock_1";
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

// Define size columns for each liquor type exactly as they appear in Excel
$size_columns_fb = ['650 ML', '500 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];
$size_columns_mb = ['650 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];
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

$grouped_sizes_fb = groupSizes($size_columns_fb);
$grouped_sizes_mb = groupSizes($size_columns_mb);
$grouped_sizes_s = groupSizes($size_columns_s);
$grouped_sizes_w = groupSizes($size_columns_w);

// Get display sizes (base sizes) for each liquor type
$display_sizes_fb = array_keys($grouped_sizes_fb);
$display_sizes_mb = array_keys($grouped_sizes_mb);
$display_sizes_s = array_keys($grouped_sizes_s);
$display_sizes_w = array_keys($grouped_sizes_w);

// Fetch class data to map liquor types
$classData = [];
$classQuery = "SELECT SGROUP, `DESC`, LIQ_FLAG FROM tblclass";
$classStmt = $conn->prepare($classQuery);
$classStmt->execute();
$classResult = $classStmt->get_result();
while ($row = $classResult->fetch_assoc()) {
    $classData[$row['SGROUP']] = $row;
}
$classStmt->close();

// Fetch item master data with size information
$items = [];
$itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster";
$itemStmt = $conn->prepare($itemQuery);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();
while ($row = $itemResult->fetch_assoc()) {
    $items[$row['CODE']] = $row;
}
$itemStmt->close();

// Initialize report data structure
$dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// Initialize daily data structure for each date
$daily_data = [];

// Initialize totals for each size column (using grouped base sizes)
$totals = [
    'Fermented Beer' => [
        'purchase' => array_fill_keys($display_sizes_fb, 0),
        'sales' => array_fill_keys($display_sizes_fb, 0),
        'closing' => array_fill_keys($display_sizes_fb, 0),
        'opening' => array_fill_keys($display_sizes_fb, 0)
    ],
    'Mild Beer' => [
        'purchase' => array_fill_keys($display_sizes_mb, 0),
        'sales' => array_fill_keys($display_sizes_mb, 0),
        'closing' => array_fill_keys($display_sizes_mb, 0),
        'opening' => array_fill_keys($display_sizes_mb, 0)
    ],
    'Spirits' => [
        'purchase' => array_fill_keys($display_sizes_s, 0),
        'sales' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0),
        'opening' => array_fill_keys($display_sizes_s, 0)
    ],
    'Wines' => [
        'purchase' => array_fill_keys($display_sizes_w, 0),
        'sales' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0),
        'opening' => array_fill_keys($display_sizes_w, 0)
    ]
];

// Map database sizes to Excel column sizes
$size_mapping = [
    // Fermented Beer
    '650 ML Bottle' => '650 ML',
    '500 ML Bottle' => '500 ML',
    '500 ML Can' => '500 ML (CAN)',
    '330 ML Bottle' => '330 ML',
    '330 ML Can' => '330 ML (CAN)',
    
    // Mild Beer
    '650 ML Bottle' => '650 ML',
    '500 ML Can' => '500 ML (CAN)',
    '330 ML Bottle' => '330 ML',
    '330 ML Can' => '330 ML (CAN)',
    
    // Spirits - Add more mappings as needed based on your DETAILS2 values
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '90 ML' => '90 ML',
    '90 ML-100' => '90 ML',
    '90 ML-96' => '90 ML',
    '2000 ML' => '2000 ML',
    '2000 ML Pet' => '2000 ML',
    
    // Wines
    '750 ML' => '750 ML',
    '375 ML' => '375 ML'
];

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

// Function to get base size for grouping
function getGroupedSize($size, $liquor_type) {
    global $grouped_sizes_fb, $grouped_sizes_mb, $grouped_sizes_s, $grouped_sizes_w;
    
    $baseSize = getBaseSize($size);
    
    // Check if this base size exists in the appropriate group
    switch ($liquor_type) {
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
    }
    
    return $baseSize; // Return base size even if not found in predefined groups
}

// Process each date in the range
foreach ($dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    
    // Initialize daily data for this date
    $daily_data[$date] = [
        'Fermented Beer' => [
            'purchase' => array_fill_keys($display_sizes_fb, 0),
            'sales' => array_fill_keys($display_sizes_fb, 0),
            'closing' => array_fill_keys($display_sizes_fb, 0),
            'opening' => array_fill_keys($display_sizes_fb, 0)
        ],
        'Mild Beer' => [
            'purchase' => array_fill_keys($display_sizes_mb, 0),
            'sales' => array_fill_keys($display_sizes_mb, 0),
            'closing' => array_fill_keys($display_sizes_mb, 0),
            'opening' => array_fill_keys($display_sizes_mb, 0)
        ],
        'Spirits' => [
            'purchase' => array_fill_keys($display_sizes_s, 0),
            'sales' => array_fill_keys($display_sizes_s, 0),
            'closing' => array_fill_keys($display_sizes_s, 0),
            'opening' => array_fill_keys($display_sizes_s, 0)
        ],
        'Wines' => [
            'purchase' => array_fill_keys($display_sizes_w, 0),
            'sales' => array_fill_keys($display_sizes_w, 0),
            'closing' => array_fill_keys($display_sizes_w, 0),
            'opening' => array_fill_keys($display_sizes_w, 0)
        ]
    ];
    
    // Fetch all stock data for this month
    $stockQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                  DAY_{$day}_OPEN as opening, 
                  DAY_{$day}_PURCHASE as purchase, 
                  DAY_{$day}_SALES as sales, 
                  DAY_{$day}_CLOSING as closing 
                  FROM $dailyStockTable 
                  WHERE STK_MONTH = ?";
    
    $stockStmt = $conn->prepare($stockQuery);
    $stockStmt->bind_param("s", $month);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    
    while ($row = $stockResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        // Skip if item not found in master
        if (!isset($items[$item_code])) continue;
        
        $item_details = $items[$item_code];
        $size = $item_details['DETAILS2'];
        $class = $item_details['CLASS'];
        $liq_flag = $item_details['LIQ_FLAG'];
        
        // Determine liquor type
        $liquor_type = getLiquorType($class, $liq_flag);
        
        // Map database size to Excel size
        $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
        
        // Get grouped size for display
        $grouped_size = getGroupedSize($excel_size, $liquor_type);
        
        // Add to daily data and totals based on liquor type and grouped size
        switch ($liquor_type) {
            case 'Fermented Beer':
                if (in_array($grouped_size, $display_sizes_fb)) {
                    $daily_data[$date]['Fermented Beer']['purchase'][$grouped_size] += $row['purchase'];
                    $daily_data[$date]['Fermented Beer']['sales'][$grouped_size] += $row['sales'];
                    $daily_data[$date]['Fermented Beer']['closing'][$grouped_size] += $row['closing'];
                    $daily_data[$date]['Fermented Beer']['opening'][$grouped_size] += $row['opening'];
                    
                    $totals['Fermented Beer']['purchase'][$grouped_size] += $row['purchase'];
                    $totals['Fermented Beer']['sales'][$grouped_size] += $row['sales'];
                    $totals['Fermented Beer']['closing'][$grouped_size] += $row['closing'];
                    $totals['Fermented Beer']['opening'][$grouped_size] += $row['opening'];
                }
                break;
                
            case 'Mild Beer':
                if (in_array($grouped_size, $display_sizes_mb)) {
                    $daily_data[$date]['Mild Beer']['purchase'][$grouped_size] += $row['purchase'];
                    $daily_data[$date]['Mild Beer']['sales'][$grouped_size] += $row['sales'];
                    $daily_data[$date]['Mild Beer']['closing'][$grouped_size] += $row['closing'];
                    $daily_data[$date]['Mild Beer']['opening'][$grouped_size] += $row['opening'];
                    
                    $totals['Mild Beer']['purchase'][$grouped_size] += $row['purchase'];
                    $totals['Mild Beer']['sales'][$grouped_size] += $row['sales'];
                    $totals['Mild Beer']['closing'][$grouped_size] += $row['closing'];
                    $totals['Mild Beer']['opening'][$grouped_size] += $row['opening'];
                }
                break;
                
            case 'Spirits':
                if (in_array($grouped_size, $display_sizes_s)) {
                    $daily_data[$date]['Spirits']['purchase'][$grouped_size] += $row['purchase'];
                    $daily_data[$date]['Spirits']['sales'][$grouped_size] += $row['sales'];
                    $daily_data[$date]['Spirits']['closing'][$grouped_size] += $row['closing'];
                    $daily_data[$date]['Spirits']['opening'][$grouped_size] += $row['opening'];
                    
                    $totals['Spirits']['purchase'][$grouped_size] += $row['purchase'];
                    $totals['Spirits']['sales'][$grouped_size] += $row['sales'];
                    $totals['Spirits']['closing'][$grouped_size] += $row['closing'];
                    $totals['Spirits']['opening'][$grouped_size] += $row['opening'];
                }
                break;
                
            case 'Wines':
                if (in_array($grouped_size, $display_sizes_w)) {
                    $daily_data[$date]['Wines']['purchase'][$grouped_size] += $row['purchase'];
                    $daily_data[$date]['Wines']['sales'][$grouped_size] += $row['sales'];
                    $daily_data[$date]['Wines']['closing'][$grouped_size] += $row['closing'];
                    $daily_data[$date]['Wines']['opening'][$grouped_size] += $row['opening'];
                    
                    $totals['Wines']['purchase'][$grouped_size] += $row['purchase'];
                    $totals['Wines']['sales'][$grouped_size] += $row['sales'];
                    $totals['Wines']['closing'][$grouped_size] += $row['closing'];
                    $totals['Wines']['opening'][$grouped_size] += $row['opening'];
                }
                break;
        }
    }
    
    $stockStmt->close();
}

// Calculate total columns count for table formatting
$total_columns = count($display_sizes_fb) + count($display_sizes_mb) + count($display_sizes_s) + count($display_sizes_w);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FLR 1A/2A/3A Datewise Register - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    @media print {
      @page {
        size: legal; /* or letter */
        margin: 0.2in;
      }
      body {
        margin: 0;
        padding: 0;
        font-size: 6px;
        line-height: 1;
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
        font-size: 6px;
        transform: scale(0.95);
        transform-origin: top left;
      }
      .no-print {
        display: none !important;
      }
      .table-responsive {
        overflow-x: visible;
      }
      .report-table {
        page-break-inside: avoid;
        width: 100% !important;
      }
      .company-header h1 {
        font-size: 12px !important;
        margin-bottom: 1px !important;
      }
      .company-header h5, .company-header h6 {
        font-size: 8px !important;
        margin-bottom: 1px !important;
      }
    }
    body {
      font-size: 12px;
    }
    .company-header {
      text-align: center;
      margin-bottom: 3px;
      padding: 2px;
    }
    .company-header h1 {
      font-size: 14px;
      font-weight: bold;
      margin-bottom: 1px;
    }
    .company-header h5 {
      font-size: 10px;
      margin-bottom: 1px;
    }
    .company-header h6 {
      font-size: 8px;
      margin-bottom: 2px;
    }
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 3px;
      font-size: 6px;
      table-layout: fixed;
    }
    .report-table th, .report-table td {
      border: 1px solid #000;
      padding: 1px;
      text-align: center;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1;
      height: 12px;
    }
    .report-table th {
      background-color: #f0f0f0;
      font-weight: bold;
      padding: 2px 1px;
    }
    .report-table .text-right {
      text-align: right;
    }
    .report-table .text-center {
      text-align: center;
    }
    .liquor-header {
      background-color: #e0e0e0;
      font-weight: bold;
    }
    .size-header {
      background-color: #f0f0f0;
      font-weight: bold;
    }
    .footer-info {
      text-align: center;
      margin-top: 5px;
      font-size: 6px;
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
    .rotate-text {
      writing-mode: vertical-rl;
      transform: rotate(180deg);
      white-space: nowrap;
    }
    .summary-row {
      background-color: #f8f9fa;
      font-weight: bold;
    }
    th, td {
      min-width: 25px;
      max-width: 35px;
    }
    .size-cell {
      font-size: 5px;
      line-height: 1;
      padding: 0;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">FLR 1A/2A/3A Datewise Register</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">From Date:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
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
          <h1>Form F.L.R. 1A/2A/3A (See Rule 15)</h1>
          <h5>REGISTER OF TRANSACTION OF FOREIGN LIQUOR EFFECTED BY HOLDER OF VENDOR'S/HOTEL/CLUB LICENCE</h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table">
            <thead>
              <tr>
                <th rowspan="3" style="width: 25px;">Date</th>
                <th rowspan="3" style="width: 30px;">Permit No</th>
                <th colspan="<?= $total_columns ?>">Received</th>
                <th colspan="<?= $total_columns ?>">Sold</th>
                <th colspan="<?= $total_columns ?>">Closing Balance</th>
                <th rowspan="3" style="width: 25px;">Signature</th>
              </tr>
              <tr>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wines</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wines</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wines</th>
              </tr>
              <tr>
                <!-- Fermented Beer Received -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Received -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Received -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Received -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Closing Balance -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing Balance -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Closing Balance -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Closing Balance -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th style="width: 25px;" class="size-cell"><?= str_replace(' ', '<br>', $size) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <!-- Balance of the Month row -->
              <tr>
                <td>Balance of the Month</td>
                <td></td>
                
                <!-- Received Section - All zeros as per Excel -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td>0</td>
                <?php endfor; ?>
                
                <!-- Sold Section - All zeros as per Excel -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td>0</td>
                <?php endfor; ?>
                
                <!-- Closing Balance Section -->
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <td></td>
              </tr>
              
              <!-- For each date in the range -->
              <?php foreach ($dates as $date): 
                $day_num = date('d', strtotime($date));
              ?>
                <tr>
                  <td><?= $day_num ?></td>
                  <td></td>
                  
                  <!-- Received Section -->
                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $daily_data[$date]['Fermented Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['purchase'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $daily_data[$date]['Mild Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Mild Beer']['purchase'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $daily_data[$date]['Spirits']['purchase'][$size] > 0 ? $daily_data[$date]['Spirits']['purchase'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $daily_data[$date]['Wines']['purchase'][$size] > 0 ? $daily_data[$date]['Wines']['purchase'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section -->
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $daily_data[$date]['Fermented Beer']['sales'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['sales'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $daily_data[$date]['Mild Beer']['sales'][$size] > 0 ? $daily_data[$date]['Mild Beer']['sales'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $daily_data[$date]['Spirits']['sales'][$size] > 0 ? $daily_data[$date]['Spirits']['sales'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $daily_data[$date]['Wines']['sales'][$size] > 0 ? $daily_data[$date]['Wines']['sales'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section -->
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $daily_data[$date]['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $daily_data[$date]['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $daily_data[$date]['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $daily_data[$date]['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <td><?= $day_num ?></td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Summary rows -->
              <tr class="summary-row">
                <td>Received</td>
                <td></td>
                
                <!-- Received Section - Show purchase totals -->
                <!-- Fermented Beer Received -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Received -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Received -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Received -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Sold Section - Show sales totals -->
                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Closing Balance Section - Show closing totals -->
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <td>Received</td>
              </tr>
              
              <tr class="summary-row">
                <td>Opening Balance</td>
                <td></td>
                
                <!-- Received Section - Show opening totals -->
                <!-- Fermented Beer Opening -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Opening -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Opening -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Opening -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Sold Section - Show opening totals -->
                <!-- Fermented Beer Opening -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Opening -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Opening -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Opening -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Closing Balance Section - Show opening totals -->
                <!-- Fermented Beer Opening -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Opening -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Opening -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Opening -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <td>Opening Balance</td>
              </tr>
              
              <tr class="summary-row">
                <td>Grand Total</td>
                <td></td>
                
                <!-- Received Section - Show closing totals -->
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Sold Section - Show closing totals -->
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Closing Balance Section - Show closing totals -->
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <td>Grand Total</td>
              </tr>
              
              <tr class="summary-row">
                <td>Sold</td>
                <td></td>
                
                <!-- Received Section - Show sales totals -->
                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Sold Section - Show sales totals -->
                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Closing Balance Section - Show sales totals -->
                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <td>Sold</td>
              </tr>
              
              <tr class="summary-row">
                <td>Closing Balance</td>
                <td></td>
                
                <!-- Received Section - Show closing totals -->
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Sold Section - Show closing totals -->
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Closing Balance Section - Show closing totals -->
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <td>Closing Balance</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="footer-info">
          <p>Generated on: <?= date('d-M-Y h:i A') ?></p>
        </div>
      </div>
    </div>
    
    <?php include 'components/footer.php'; ?>
  </div>
  
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>