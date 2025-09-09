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

// Fetch brands for dropdown - extract from DETAILS field in tblitemmaster
$brands = [];
$brandQuery = "SELECT DISTINCT DETAILS as BRAND_NAME FROM tblitemmaster ORDER BY DETAILS";
$brandStmt = $conn->prepare($brandQuery);
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
        
        // Build query to get item details
        $itemQuery = "SELECT 
            CODE as ITEM_CODE,
            DETAILS as ITEM_NAME,
            DETAILS2 as ITEM_SIZE,
            DETAILS as BRAND,
            LIQ_FLAG
        FROM tblitemmaster 
        WHERE 1=1";
        
        $params = [];
        $types = "";
        
        if ($liquor_mode !== 'all') {
            $itemQuery .= " AND LIQ_FLAG = ?";
            $params[] = $liquor_mode;
            $types .= "s";
        }
        
        if ($brand !== 'all') {
            $itemQuery .= " AND DETAILS = ?";
            $params[] = $brand;
            $types .= "s";
        }
        
        $itemQuery .= " ORDER BY DETAILS, DETAILS2";
        
        $itemStmt = $conn->prepare($itemQuery);
        
        if (!empty($params)) {
            $itemStmt->bind_param($types, ...$params);
        }
        
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        
        // Get the month for the daily stock table (format: YYYY-MM)
        $month = $selectedDate->format('Y-m');
        
        while ($item = $itemResult->fetch_assoc()) {
            $itemCode = $item['ITEM_CODE'];
            
            // Query the daily stock table for this item and month
            $stockQuery = "SELECT * FROM $dailyStockTable 
                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $stockStmt = $conn->prepare($stockQuery);
            $stockStmt->bind_param("ss", $month, $itemCode);
            $stockStmt->execute();
            $stockResult = $stockStmt->get_result();
            
            if ($stockRow = $stockResult->fetch_assoc()) {
                // Get opening, receipts, issues, and closing stock for the selected date
                $openingStock = (float)$stockRow[$dayField . '_OPEN'];
                $receipts = (float)$stockRow[$dayField . '_PURCHASE'];
                $issues = (float)$stockRow[$dayField . '_SALES'];
                $closingStock = (float)$stockRow[$dayField . '_CLOSING'];
                
                // Add to report data
                $report_data[] = [
                    'BRAND' => $item['BRAND'],
                    'ITEM_NAME' => $item['ITEM_NAME'],
                    'ITEM_SIZE' => $item['ITEM_SIZE'],
                    'OP_STOCK' => $openingStock,
                    'RECEIPTS' => $receipts,
                    'ISSUES' => $issues,
                    'CL_STOCK' => $closingStock
                ];
            }
            
            $stockStmt->close();
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
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Item Wise Stock Report</h3>

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
                  <th class="text-center">Op. Stock</th>
                  <th class="text-center">Receipts</th>
                  <th class="text-center">Issues</th>
                  <th class="text-center">Cl. Stock</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $currentBrand = '';
                foreach ($report_data as $index => $row): 
                  if ($currentBrand !== $row['BRAND']):
                    $currentBrand = $row['BRAND'];
                ?>
                  <tr class="bill-header">
                    <td colspan="5"><strong><?= htmlspecialchars($currentBrand) ?></strong></td>
                  </tr>
                <?php endif; ?>
                
                <tr>
                  <td><?= htmlspecialchars($row['ITEM_NAME']) ?> <?= !empty($row['ITEM_SIZE']) ? '(' . htmlspecialchars($row['ITEM_SIZE']) . ')' : '' ?></td>
                  <td class="text-center"><?= number_format($row['OP_STOCK'], 0) ?></td>
                  <td class="text-center"><?= number_format($row['RECEIPTS'], 0) ?></td>
                  <td class="text-center"><?= number_format($row['ISSUES'], 0) ?></td>
                  <td class="text-center"><?= number_format($row['CL_STOCK'], 0) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
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