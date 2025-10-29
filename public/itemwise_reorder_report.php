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

// Convert date format for display
$date_parts = explode('/', $date_as_on);
$display_date = count($date_parts) === 3 ? $date_parts[0] . '-' . $date_parts[1] . '-' . $date_parts[2] : date('d-M-Y');

// Fetch company name from tblcompany
$companyQuery = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$stmt = $conn->prepare($companyQuery);
$stmt->bind_param("i", $_SESSION['CompID']);
$stmt->execute();
$companyResult = $stmt->get_result();
$company = $companyResult->fetch_assoc();
$companyName = $company['COMP_NAME'] ?? 'DIAMOND WINE SHOP';

// Define all possible sizes in the order they appear in the report
$all_sizes = [
    '4.5 L', '3 L', '2 L', '1 Ltr', '750 ML', '650 ML', '500 ML', 
    '375 ML', '330 ML', '325 ML', '180 ML', '90 ML', '60 ML'
];

// Function to extract brand name from item details
function getBrandName($details) {
    // Remove size patterns and special characters
    $brandName = preg_replace('/\s*\d+(\.\d+)?\s*(ML|L|Ltr).*$/i', '', $details);
    $brandName = preg_replace('/\s*\([^)]*\)\s*$/', '', $brandName);
    $brandName = preg_replace('/\s*\.\s*$/', '', $brandName);
    return trim($brandName);
}

// Function to normalize size for grouping
function normalizeSize($size) {
    if (empty($size)) return '';
    
    // Convert to standard format
    $size = strtoupper(trim($size));
    
    // Handle common variations
    if (strpos($size, '1 LTR') !== false) return '1 Ltr';
    if (strpos($size, '750ML') !== false) return '750 ML';
    if (strpos($size, '650ML') !== false) return '650 ML';
    if (strpos($size, '500ML') !== false) return '500 ML';
    if (strpos($size, '375ML') !== false) return '375 ML';
    if (strpos($size, '330ML') !== false) return '330 ML';
    if (strpos($size, '325ML') !== false) return '325 ML';
    if (strpos($size, '180ML') !== false) return '180 ML';
    if (strpos($size, '90ML') !== false) return '90 ML';
    if (strpos($size, '60ML') !== false) return '60 ML';
    
    return $size;
}

// Fetch items with reorder levels - FILTERED BY LICENSE TYPE
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT CODE, DETAILS, DETAILS2, REORDER, GREORDER, CLASS, LIQ_FLAG 
              FROM tblitemmaster 
              WHERE (REORDER > 0 OR GREORDER > 0)
              AND CLASS IN ($class_placeholders)
              ORDER BY DETAILS, DETAILS2";
    
    $stmt = $conn->prepare($query);
    $types = str_repeat('s', count($allowed_classes));
    $stmt->bind_param($types, ...$allowed_classes);
} else {
    // If no classes allowed, show empty result
    $query = "SELECT CODE, DETAILS, DETAILS2, REORDER, GREORDER, CLASS, LIQ_FLAG 
              FROM tblitemmaster 
              WHERE 1 = 0"; // Always false condition
    
    $stmt = $conn->prepare($query);
}

$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);

// Organize data by brand and size
$brand_data = [];
$size_totals = array_fill_keys($all_sizes, 0);

foreach ($items as $item) {
    $brandName = getBrandName($item['DETAILS']);
    $size = normalizeSize($item['DETAILS2']);
    
    if (empty($brandName)) continue;
    
    // Only include if size is in our predefined list
    if (in_array($size, $all_sizes)) {
        // Initialize brand data if not exists
        if (!isset($brand_data[$brandName])) {
            $brand_data[$brandName] = array_fill_keys($all_sizes, 0);
        }
        
        // Use REORDER level (fallback to GREORDER if REORDER is 0)
        $reorder_level = $item['REORDER'] > 0 ? $item['REORDER'] : $item['GREORDER'];
        
        // Add reorder level to the brand and size
        $brand_data[$brandName][$size] += (float)$reorder_level;
        $size_totals[$size] += (float)$reorder_level;
    }
}

// Calculate brand totals
$brand_totals = [];
foreach ($brand_data as $brand => $sizes) {
    $brand_totals[$brand] = array_sum($sizes);
}

// Calculate grand total
$grand_total = array_sum($size_totals);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item Wise ReOrder Level Report - WineSoft</title>
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
    .item-description {
        font-weight: bold;
        padding-left: 10px !important;
    }
    .total-row {
        background-color: #e9ecef;
        font-weight: bold;
    }
    .grand-total-row {
        background-color: #d1ecf1;
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
        text-align: center;
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
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Item Wise ReOrder Level Report</h3>

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
            <div class="col-md-4">
              <label class="form-label">Date As On:</label>
              <input type="text" name="date_as_on" value="<?= htmlspecialchars($date_as_on) ?>" 
                     class="form-control datepicker" placeholder="DD/MM/YYYY">
            </div>
            
            <div class="col-md-4 d-flex align-items-end">
              <button type="submit" class="btn btn-primary me-2">
                <i class="fas fa-filter"></i> Apply Filter
              </button>
              <a href="itemwise_reorder.php" class="btn btn-secondary">
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
            <p>License Type: <?= htmlspecialchars($license_type) ?></p>
            <p>Item Wise ReOrder Statement As On <?= $display_date ?></p>
          </div>
        </div>

        <div class="table-container">
          <table class="report-table">
            <thead>
              <tr>
                <th style="width: 200px;">Item Description</th>
                <?php foreach ($all_sizes as $size): ?>
                  <th class="size-column"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($brand_data)): ?>
                <?php 
                $sr_no = 1;
                ksort($brand_data);
                foreach ($brand_data as $brand => $sizes): 
                  $brand_total = $brand_totals[$brand];
                ?>
                  <tr>
                    <td class="item-description"><?= htmlspecialchars($brand) ?></td>
                    <?php foreach ($all_sizes as $size): 
                      $quantity = $sizes[$size] ?? 0;
                    ?>
                      <td class="size-column"><?= $quantity > 0 ? number_format($quantity, 0) : '' ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
                
                <!-- Total Row -->
                <tr class="total-row">
                  <td class="item-description">Total</td>
                  <?php foreach ($all_sizes as $size): ?>
                    <td class="size-column"><?= $size_totals[$size] > 0 ? number_format($size_totals[$size], 0) : '' ?></td>
                  <?php endforeach; ?>
                </tr>
                
                <!-- Grand Total Row -->
                <tr class="grand-total-row">
                  <td colspan="<?= count($all_sizes) + 1 ?>" style="text-align: center; font-weight: bold;">
                    Grand Total: <?= number_format($grand_total, 0) ?>
                  </td>
                </tr>
              <?php else: ?>
                <tr>
                  <td colspan="<?= count($all_sizes) + 1 ?>" style="text-align: center; padding: 20px; color: #666; font-style: italic;">
                    No reorder level data found for the selected criteria
                  </td>
                </tr>
              <?php endif; ?>
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
  
  // Auto-generate report on page load
  generateReport();
});

// Keyboard shortcuts
document.addEventListener('keydown', function(event) {
  // Ctrl + G for Generate
  if (event.ctrlKey && event.key === 'g') {
    event.preventDefault();
    generateReport();
  }
  // Ctrl + P for Print
  if (event.ctrlKey && event.key === 'p') {
    event.preventDefault();
    window.print();
  }
  // Escape for Exit
  if (event.key === 'Escape') {
    window.location.href = 'dashboard.php';
  }
});
</script>
</body>
</html>
<?php
// Close database connection
$conn->close();
?>