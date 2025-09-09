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

// Define size columns for each liquor type exactly as they appear in Excel
$size_columns_fb = ['650 ML', '500 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];
$size_columns_mb = ['650 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];
$size_columns_s = [
    '2000 ML Pet (6)', '2000 ML(4)', '2000 ML(6)', '1000 ML(Pet)', '1000 ML',
    '750 ML(6)', '750 ML (Pet)', '750 ML', '700 ML', '700 ML(6)',
    '375 ML (12)', '375 ML', '375 ML (Pet)', '350 ML (12)', '275 ML(24)',
    '200 ML (48)', '200 ML (24)', '200 ML (30)', '200 ML (12)', '180 ML(24)',
    '180 ML (Pet)', '180 ML', '90 ML(100)', '90 ML (Pet)-100', '90 ML (Pet)-96', 
    '90 ML-(96)', '60 ML', '60 ML (75)', '50 ML(120)', '50 ML (180)', 
    '50 ML (24)', '50 ML (192)'
];
$size_columns_w = ['750 ML(6)', '750 ML', '650 ML', '375 ML', '330 ML', '180 ML'];

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

// Initialize daily data array
$daily_data = [];

// Process each date in the range
foreach ($dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    
    // Initialize daily totals for this date
    $daily_totals = [
        'Fermented Beer' => [
            'purchase' => array_fill_keys($size_columns_fb, 0),
            'sales' => array_fill_keys($size_columns_fb, 0),
            'closing' => array_fill_keys($size_columns_fb, 0)
        ],
        'Mild Beer' => [
            'purchase' => array_fill_keys($size_columns_mb, 0),
            'sales' => array_fill_keys($size_columns_mb, 0),
            'closing' => array_fill_keys($size_columns_mb, 0)
        ],
        'Spirits' => [
            'purchase' => array_fill_keys($size_columns_s, 0),
            'sales' => array_fill_keys($size_columns_s, 0),
            'closing' => array_fill_keys($size_columns_s, 0)
        ],
        'Wines' => [
            'purchase' => array_fill_keys($size_columns_w, 0),
            'sales' => array_fill_keys($size_columns_w, 0),
            'closing' => array_fill_keys($size_columns_w, 0)
        ]
    ];
    
    // Fetch all stock data for this month and day
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
        
        // Add to daily totals based on liquor type and size
        switch ($liquor_type) {
            case 'Fermented Beer':
                if (in_array($excel_size, $size_columns_fb)) {
                    $daily_totals['Fermented Beer']['purchase'][$excel_size] += $row['purchase'];
                    $daily_totals['Fermented Beer']['sales'][$excel_size] += $row['sales'];
                    $daily_totals['Fermented Beer']['closing'][$excel_size] += $row['closing'];
                }
                break;
                
            case 'Mild Beer':
                if (in_array($excel_size, $size_columns_mb)) {
                    $daily_totals['Mild Beer']['purchase'][$excel_size] += $row['purchase'];
                    $daily_totals['Mild Beer']['sales'][$excel_size] += $row['sales'];
                    $daily_totals['Mild Beer']['closing'][$excel_size] += $row['closing'];
                }
                break;
                
            case 'Spirits':
                if (in_array($excel_size, $size_columns_s)) {
                    $daily_totals['Spirits']['purchase'][$excel_size] += $row['purchase'];
                    $daily_totals['Spirits']['sales'][$excel_size] += $row['sales'];
                    $daily_totals['Spirits']['closing'][$excel_size] += $row['closing'];
                }
                break;
                
            case 'Wines':
                if (in_array($excel_size, $size_columns_w)) {
                    $daily_totals['Wines']['purchase'][$excel_size] += $row['purchase'];
                    $daily_totals['Wines']['sales'][$excel_size] += $row['sales'];
                    $daily_totals['Wines']['closing'][$excel_size] += $row['closing'];
                }
                break;
        }
    }
    
    $stockStmt->close();
    
    // Store daily totals for this date
    $daily_data[$date] = $daily_totals;
}

// Get the closing balance for the "to date" (last date in the range)
$to_date_closing = isset($daily_data[$to_date]) ? $daily_data[$to_date] : [
    'Fermented Beer' => ['closing' => array_fill_keys($size_columns_fb, 0)],
    'Mild Beer' => ['closing' => array_fill_keys($size_columns_mb, 0)],
    'Spirits' => ['closing' => array_fill_keys($size_columns_s, 0)],
    'Wines' => ['closing' => array_fill_keys($size_columns_w, 0)]
];
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
        font-size: 10px;
      }
      .no-print {
        display: none !important;
      }
      .table-responsive {
        overflow-x: visible;
      }
    }
    .company-header {
      text-align: center;
      margin-bottom: 10px;
      page-break-after: avoid;
    }
    .company-header h1 {
      font-size: 18px;
      font-weight: bold;
      margin-bottom: 3px;
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
      margin-bottom: 10px;
      font-size: 9px;
      page-break-inside: avoid;
    }
    .report-table th, .report-table td {
      border: 1px solid #000;
      padding: 2px;
      text-align: center;
    }
    .report-table th {
      background-color: #f0f0f0;
      font-weight: bold;
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
      margin-top: 15px;
      font-size: 10px;
    }
    .filter-card {
      background-color: #f8f9fa;
    }
    .table-responsive {
      overflow-x: auto;
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
                <th rowspan="3">Date</th>
                <th rowspan="3">Permit No</th>
                <th colspan="<?= count($size_columns_fb) + count($size_columns_mb) + count($size_columns_s) + count($size_columns_w) ?>">Received</th>
                <th colspan="<?= count($size_columns_fb) + count($size_columns_mb) + count($size_columns_s) + count($size_columns_w) ?>">Sold</th>
                <th colspan="<?= count($size_columns_fb) + count($size_columns_mb) + count($size_columns_s) + count($size_columns_w) ?>">Closing Balance</th>
                <th rowspan="3">Signature</th>
              </tr>
              <tr>
                <th colspan="<?= count($size_columns_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($size_columns_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($size_columns_s) ?>">Spirits</th>
                <th colspan="<?= count($size_columns_w) ?>">Wines</th>
                <th colspan="<?= count($size_columns_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($size_columns_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($size_columns_s) ?>">Spirits</th>
                <th colspan="<?= count($size_columns_w) ?>">Wines</th>
                <th colspan="<?= count($size_columns_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($size_columns_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($size_columns_s) ?>">Spirits</th>
                <th colspan="<?= count($size_columns_w) ?>">Wines</th>
              </tr>
              <tr>
                <!-- Fermented Beer Received -->
                <?php foreach ($size_columns_fb as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Received -->
                <?php foreach ($size_columns_mb as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Received -->
                <?php foreach ($size_columns_s as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Received -->
                <?php foreach ($size_columns_w as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Sold -->
                <?php foreach ($size_columns_fb as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($size_columns_mb as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Sold -->
                <?php foreach ($size_columns_s as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($size_columns_w as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Closing Balance -->
                <?php foreach ($size_columns_fb as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing Balance -->
                <?php foreach ($size_columns_mb as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Closing Balance -->
                <?php foreach ($size_columns_s as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Closing Balance -->
                <?php foreach ($size_columns_w as $size): ?>
                  <th><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <!-- Balance of the Month row - Show only the closing balance for the "to date" -->
              <tr>
                <td>Balance of the Month</td>
                <td></td>
                
                <!-- Received Section - All zeros as per Excel -->
                <?php for ($i = 0; $i < (count($size_columns_fb) + count($size_columns_mb) + count($size_columns_s) + count($size_columns_w)); $i++): ?>
                  <td>0</td>
                <?php endfor; ?>
                
                <!-- Sold Section - All zeros as per Excel -->
                <?php for ($i = 0; $i < (count($size_columns_fb) + count($size_columns_mb) + count($size_columns_s) + count($size_columns_w)); $i++): ?>
                  <td>0</td>
                <?php endfor; ?>
                
                <!-- Closing Balance Section - Show only the closing balance for the "to date" -->
                <!-- Fermented Beer Closing -->
                <?php foreach ($size_columns_fb as $size): ?>
                  <td><?= $to_date_closing['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($size_columns_mb as $size): ?>
                  <td><?= $to_date_closing['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Spirits Closing -->
                <?php foreach ($size_columns_s as $size): ?>
                  <td><?= $to_date_closing['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($size_columns_w as $size): ?>
                  <td><?= $to_date_closing['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <td></td>
              </tr>
              
              <!-- For each date in the range -->
              <?php foreach ($dates as $date): 
                $day_num = date('d', strtotime($date));
                $daily_totals = $daily_data[$date];
              ?>
                <tr>
                  <td><?= $day_num ?></td>
                  <td></td>
                  
                  <!-- Received Section -->
                  <!-- Fermented Beer Received -->
                  <?php foreach ($size_columns_fb as $size): ?>
                    <td><?= $daily_totals['Fermented Beer']['purchase'][$size] > 0 ? $daily_totals['Fermented Beer']['purchase'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($size_columns_mb as $size): ?>
                    <td><?= $daily_totals['Mild Beer']['purchase'][$size] > 0 ? $daily_totals['Mild Beer']['purchase'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Spirits Received -->
                  <?php foreach ($size_columns_s as $size): ?>
                    <td><?= $daily_totals['Spirits']['purchase'][$size] > 0 ? $daily_totals['Spirits']['purchase'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($size_columns_w as $size): ?>
                    <td><?= $daily_totals['Wines']['purchase'][$size] > 0 ? $daily_totals['Wines']['purchase'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section -->
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($size_columns_fb as $size): ?>
                    <td><?= $daily_totals['Fermented Beer']['sales'][$size] > 0 ? $daily_totals['Fermented Beer']['sales'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($size_columns_mb as $size): ?>
                    <td><?= $daily_totals['Mild Beer']['sales'][$size] > 0 ? $daily_totals['Mild Beer']['sales'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Spirits Sold -->
                  <?php foreach ($size_columns_s as $size): ?>
                    <td><?= $daily_totals['Spirits']['sales'][$size] > 0 ? $daily_totals['Spirits']['sales'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($size_columns_w as $size): ?>
                    <td><?= $daily_totals['Wines']['sales'][$size] > 0 ? $daily_totals['Wines']['sales'][$size] : 0 ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section -->
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($size_columns_fb as $size): ?>
                    <td><?= $daily_totals['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($size_columns_mb as $size): ?>
                    <td><?= $daily_totals['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Spirits Closing -->
                  <?php foreach ($size_columns_s as $size): ?>
                    <td><?= $daily_totals['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($size_columns_w as $size): ?>
                    <td><?= $daily_totals['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <td><?= $day_num ?></td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Summary rows -->
              <?php
              $total_columns = count($size_columns_fb) + count($size_columns_mb) + count($size_columns_s) + count($size_columns_w);
              ?>
              <tr>
                <td></td>
                <td></td>
                <?php for ($i = 0; $i < $total_columns * 3; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                <td></td>
              </tr>
              
              <tr>
                <td>Received</td>
                <td></td>
                <?php for ($i = 0; $i < $total_columns * 3; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                <td>Received</td>
              </tr>
              
              <tr>
                <td>Opening Balance</td>
                <td></td>
                <?php for ($i = 0; $i < $total_columns * 3; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                <td>Opening Balance</td>
              </tr>
              
              <tr>
                <td>Grand Total</td>
                <td></td>
                <?php for ($i = 0; $i < $total_columns * 3; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                <td>Grand Total</td>
              </tr>
              
              <tr>
                <td>Sold</td>
                <td></td>
                <?php for ($i = 0; $i < $total_columns * 3; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                <td>Sold</td>
              </tr>
              
              <tr>
                <td>Closing Balance</td>
                <td></td>
                <?php for ($i = 0; $i < $total_columns * 3; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                <td>Closing Balance</td>
              </tr>
            </tbody>
          </table>
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