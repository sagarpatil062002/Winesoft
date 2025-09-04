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
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

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

$report_data = [];
$size_columns = ['4.5 L', '3 L', '2 L', '1 Ltr', '750 ML', '860 ML', '800 ML', '375 ML', '330 ML', '325 ML', '180 ML', '90 ML'];

if (isset($_GET['generate'])) {
    // Build query based on selected filters
    $query = "SELECT 
        b.BRK_Date,
        b.Code,
        b.Item_Desc,
        b.Rate,
        b.BRK_Qty,
        b.Amount
    FROM tblbreakages b
    WHERE b.BRK_Date BETWEEN ? AND ? AND b.CompID = ?
    ORDER BY b.Item_Desc";
    
    $params = [$date_from, $date_to, $compID];
    $types = "ssi";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $report_data[] = $row;
    }
    
    $stmt->close();
    
    // Group data by item description for display
    $grouped_data = [];
    foreach ($report_data as $row) {
        $item_desc = $row['Item_Desc'];
        
        if (!isset($grouped_data[$item_desc])) {
            $grouped_data[$item_desc] = [
                'sizes' => array_fill_keys($size_columns, 0),
                'total_amount' => 0
            ];
        }
        
        // Extract size from item description
        $size_key = extractSizeFromDescription($item_desc);
        if ($size_key && isset($grouped_data[$item_desc]['sizes'][$size_key])) {
            $grouped_data[$item_desc]['sizes'][$size_key] += (float)$row['BRK_Qty'];
        }
        
        $grouped_data[$item_desc]['total_amount'] += (float)$row['Amount'];
    }
}

// Helper function to extract size from item description
function extractSizeFromDescription($description) {
    $size_patterns = [
        '4.5 L' => '/4\.5\s*L|4500\s*ML/i',
        '3 L' => '/3\s*L|3000\s*ML/i',
        '2 L' => '/2\s*L|2000\s*ML/i',
        '1 Ltr' => '/1\s*L|1000\s*ML|1\s*Ltr/i',
        '750 ML' => '/750\s*ML/i',
        '860 ML' => '/860\s*ML/i',
        '800 ML' => '/800\s*ML/i',
        '375 ML' => '/375\s*ML/i',
        '330 ML' => '/330\s*ML/i',
        '325 ML' => '/325\s*ML/i',
        '180 ML' => '/180\s*ML/i',
        '90 ML' => '/90\s*ML/i'
    ];
    
    foreach ($size_patterns as $size => $pattern) {
        if (preg_match($pattern, $description)) {
            return $size;
        }
    }
    
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item Wise Breakages Report - WineSoft</title>
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
      <h3 class="mb-4">Item Wise Breakages Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Date From:</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date To:</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
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
            <h5>Item Wise Breakages Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
          </div>
          
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Item Description</th>
                  <?php foreach ($size_columns as $column): ?>
                    <th class="text-center"><?= $column ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($grouped_data)): ?>
                  <?php foreach ($grouped_data as $item_desc => $data): ?>
                    <tr>
                      <td><?= htmlspecialchars($item_desc) ?></td>
                      <?php foreach ($size_columns as $column): ?>
                        <td class="text-center"><?= $data['sizes'][$column] > 0 ? $data['sizes'][$column] : '' ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="<?= count($size_columns) + 1 ?>" class="text-center">No breakages recorded</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          
          <div class="footer-info">
            <p>S. S. SoftTech, Pune. (020-30224741, 9371251623, 9657860662)</p>
            <p>Printed on: <?= date('d-M-Y h:i A') ?></p>
          </div>
        </div>
      <?php elseif (isset($_GET['generate'])): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No breakages records found for the selected criteria.
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