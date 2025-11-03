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

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values - changed to single date
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$liquor_mode = isset($_GET['liquor_mode']) ? $_GET['liquor_mode'] : 'all';
$brand = isset($_GET['brand']) ? $_GET['brand'] : 'all';

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

// Fetch brands for dropdown - extract from DETAILS field in tblitemmaster - FILTERED BY LICENSE TYPE
$brands = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $brandQuery = "SELECT DISTINCT DETAILS as BRAND_NAME FROM tblitemmaster WHERE CLASS IN ($class_placeholders) ORDER BY DETAILS";
    $brandStmt = $conn->prepare($brandQuery);
    $brandStmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
} else {
    // If no classes allowed, show empty brands list
    $brandQuery = "SELECT DISTINCT DETAILS as BRAND_NAME FROM tblitemmaster WHERE 1 = 0 ORDER BY DETAILS";
    $brandStmt = $conn->prepare($brandQuery);
}

$brandStmt->execute();
$brandResult = $brandStmt->get_result();
while ($row = $brandResult->fetch_assoc()) {
    $brands[] = $row['BRAND_NAME'];
}
$brandStmt->close();

$report_data = [];

if (isset($_GET['generate'])) {
    // Determine the daily stock table based on company ID
    $dailyStockTable = "tbldailystock_" . $compID;
    
    // Check if the daily stock table exists
    $tableCheckQuery = "SHOW TABLES LIKE '$dailyStockTable'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult->num_rows === 0) {
        $error = "Daily stock table for this company does not exist.";
    } else {
        // Get the day number for the selected date
        $selectedDate = new DateTime($date);
        $day = $selectedDate->format('d');
        $dayField = 'DAY_' . str_pad($day, 2, '0', STR_PAD_LEFT);
        $month = $selectedDate->format('Y-m');
        
        // Build query to get item details with stock data in single query - FILTERED BY LICENSE TYPE
        $itemQuery = "SELECT 
            im.CODE as ITEM_CODE,
            im.DETAILS as ITEM_NAME,
            im.DETAILS2 as ITEM_SIZE,
            im.DETAILS as BRAND,
            im.LIQ_FLAG,
            ds.{$dayField}_OPEN as OP_STOCK,
            ds.{$dayField}_PURCHASE as RECEIPTS,
            ds.{$dayField}_SALES as ISSUES,
            ds.{$dayField}_CLOSING as CL_STOCK
        FROM tblitemmaster im
        LEFT JOIN $dailyStockTable ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
        WHERE 1=1";
        
        $params = [$month];
        $types = "s";
        
        // Add license type filtering
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
            $itemQuery .= " AND im.CLASS IN ($class_placeholders)";
            $params = array_merge($params, $allowed_classes);
            $types .= str_repeat('s', count($allowed_classes));
        } else {
            // If no classes allowed, show empty result
            $itemQuery .= " AND 1 = 0";
        }
        
        if ($liquor_mode !== 'all') {
            $itemQuery .= " AND im.LIQ_FLAG = ?";
            $params[] = $liquor_mode;
            $types .= "s";
        }
        
        if ($brand !== 'all') {
            $itemQuery .= " AND im.DETAILS = ?";
            $params[] = $brand;
            $types .= "s";
        }
        
        // Only include items that have stock data
        $itemQuery .= " AND (ds.{$dayField}_OPEN IS NOT NULL OR ds.{$dayField}_PURCHASE IS NOT NULL OR ds.{$dayField}_SALES IS NOT NULL OR ds.{$dayField}_CLOSING IS NOT NULL)";
        
        $itemQuery .= " ORDER BY im.DETAILS, im.DETAILS2";
        
        $itemStmt = $conn->prepare($itemQuery);
        
        if (!empty($params)) {
            $itemStmt->bind_param($types, ...$params);
        }
        
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        
        while ($row = $itemResult->fetch_assoc()) {
            $report_data[] = [
                'BRAND' => $row['BRAND'],
                'ITEM_NAME' => $row['ITEM_NAME'],
                'ITEM_SIZE' => $row['ITEM_SIZE'],
                'OP_STOCK' => (float)$row['OP_STOCK'],
                'RECEIPTS' => (float)$row['RECEIPTS'],
                'ISSUES' => (float)$row['ISSUES'],
                'CL_STOCK' => (float)$row['CL_STOCK']
            ];
        }
        
        $itemStmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item Wise Stock Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <style>
    .license-info {
        background-color: #e7f3ff;
        border-left: 4px solid #0d6efd;
        padding: 10px 15px;
        margin-bottom: 15px;
        border-radius: 4px;
    }
    .report-table td, .report-table th {
        padding: 8px 12px;
        border: 1px solid #dee2e6;
    }
    .report-table {
        width: 100%;
        border-collapse: collapse;
    }
    .table-container {
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
      <h3 class="mb-4">Item Wise Stock Report</h3>

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

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?= $error ?></div>
          <?php endif; ?>
          
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Date:</label>
                <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Liquor Mode:</label>
                <select name="liquor_mode" class="form-select">
                  <option value="all" <?= $liquor_mode === 'all' ? 'selected' : '' ?>>All</option>
                  <option value="F" <?= $liquor_mode === 'F' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="C" <?= $liquor_mode === 'C' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Brand:</label>
                <select name="brand" class="form-select">
                  <option value="all" <?= $brand === 'all' ? 'selected' : '' ?>>All Brands</option>
                  <?php foreach ($brands as $brandName): ?>
                    <option value="<?= htmlspecialchars($brandName) ?>" <?= $brand == $brandName ? 'selected' : '' ?>>
                      <?= htmlspecialchars($brandName) ?>
                    </option>
                  <?php endforeach; ?>
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
      <?php if (!empty($report_data)): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>License Type: <?= htmlspecialchars($license_type) ?></h5>
            <h5>Item Wise Stock Report For <?= date('d-M-Y', strtotime($date)) ?></h5>
            <?php if ($liquor_mode !== 'all'): ?>
              <h6>Mode: <?= $liquor_mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?></h6>
            <?php endif; ?>
            <?php if ($brand !== 'all'): ?>
              <h6>Brand: <?= htmlspecialchars($brand) ?></h6>
            <?php endif; ?>
          </div>
          
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Item Description</th>
                  <th class="text-center">Size</th>
                  <th class="text-center">Op. Stock</th>
                  <th class="text-center">Receipts</th>
                  <th class="text-center">Issues</th>
                  <th class="text-center">Cl. Stock</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($report_data as $row): ?>
                <tr>
                  <td><?= htmlspecialchars($row['ITEM_NAME']) ?></td>
                  <td class="text-center"><?= !empty($row['ITEM_SIZE']) ? htmlspecialchars($row['ITEM_SIZE']) : '-' ?></td>
                  <td class="text-center"><?= number_format($row['OP_STOCK'], 0) ?></td>
                  <td class="text-center"><?= number_format($row['RECEIPTS'], 0) ?></td>
                  <td class="text-center"><?= number_format($row['ISSUES'], 0) ?></td>
                  <td class="text-center"><?= number_format($row['CL_STOCK'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php elseif (isset($_GET['generate'])): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No stock records found for the selected criteria.
        </div>
      <?php endif; ?>
    </div>
    
  <?php include 'components/footer.php'; ?>
  </div>
  
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>