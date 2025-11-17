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

// Get company name from session or set default
$companyName = isset($_SESSION['company_name']) ? $_SESSION['company_name'] : "Company Name";

// Default values
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'detailed';
$supplier_type = isset($_GET['supplier_type']) ? $_GET['supplier_type'] : 'particular_supplier_all_brands';
$supplier_code = isset($_GET['supplier_code']) ? $_GET['supplier_code'] : '';
$brand_code = isset($_GET['brand_code']) ? $_GET['brand_code'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-6 months'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Fetch suppliers from tbllheads
$suppliers = [];
$supplierQuery = "SELECT l.LCODE, l.LHEAD, s.CODE 
                  FROM tbllheads l 
                  LEFT JOIN tblsupplier s ON l.REF_CODE = s.CODE 
                  WHERE l.GCODE = 33 
                  ORDER BY l.LHEAD";
$supplierResult = $conn->query($supplierQuery);
if ($supplierResult) {
    while ($row = $supplierResult->fetch_assoc()) {
        $suppliers[$row['CODE']] = $row['LHEAD'];
    }
}

// Fetch brands from tblitemmaster - using DETAILS (item name) instead of DETAILS2 (size)
$brands = [];
$brandQuery = "SELECT DISTINCT DETAILS 
               FROM tblitemmaster 
               WHERE DETAILS IS NOT NULL AND DETAILS != '' 
               ORDER BY DETAILS";
$brandResult = $conn->query($brandQuery);
if ($brandResult) {
    while ($row = $brandResult->fetch_assoc()) {
        $brands[] = $row['DETAILS'];
    }
}

// Generate report data based on filters
$report_data = [];
$summary_data = [];
$gross_amount = 0;

if (isset($_GET['generate'])) {
    if ($report_type == 'detailed') {
        // Build the query for detailed report - INCLUDING ALL COLUMNS FROM PDF
        $query = "SELECT
                    p.DATE,
                    p.TPNO as T_P_NO,
                    p.INV_NO,
                    pd.Cases,
                    pd.Bottles as Units,
                    (pd.Cases * pd.BottlesPerCase + pd.Bottles) as Total_Bottles,
                    s.DETAILS as Supplier_Description,
                    pd.ItemName as Item_Description,
                    pd.CaseRate,
                    pd.Amount,
                    pd.Size,
                    pd.FreeCases as Scheme_Cases,
                    pd.FreeBottles as Scheme_Units,
                    (pd.FreeCases * pd.BottlesPerCase + pd.FreeBottles) as Scheme_Total_Bottles,
                    (pd.FreeCases * pd.CaseRate) as Scheme_Amount,
                    pd.MRP,
                    pd.BottlesPerCase
                  FROM tblpurchases p
                  INNER JOIN tblpurchasedetails pd ON p.ID = pd.PurchaseID
                  INNER JOIN tblsupplier s ON p.SUBCODE = s.CODE
                  WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?";

        $params = [$date_from, $date_to, $company_id];
        $types = "ssi";
        
        if ($supplier_type != 'all_supplier' && !empty($supplier_code)) {
            $query .= " AND p.SUBCODE = ?";
            $params[] = $supplier_code;
            $types .= "s";
        }
        
        if ($supplier_type == 'particular_supplier_particular_brand' && !empty($brand_code)) {
            $query .= " AND pd.ItemName LIKE ?";
            $params[] = "%$brand_code%";
            $types .= "s";
        }
        
        $query .= " ORDER BY s.DETAILS, pd.ItemName, p.DATE";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $report_data = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    } else {
        // Build the query for summary report - INCLUDING ALL COLUMNS FROM PDF
        $query = "SELECT
                    s.DETAILS as Supplier_Description,
                    pd.ItemName as Item_Description,
                    SUM(pd.Cases) as Total_Cases,
                    SUM(pd.Bottles) as Total_Units,
                    SUM(pd.Cases * pd.BottlesPerCase + pd.Bottles) as Total_Bottles,
                    SUM(pd.Amount) as Total_Amount,
                    AVG(pd.CaseRate) as Avg_CaseRate,
                    SUM(pd.FreeCases) as Total_Scheme_Cases,
                    SUM(pd.FreeBottles) as Total_Scheme_Units,
                    SUM(pd.FreeCases * pd.BottlesPerCase + pd.FreeBottles) as Total_Scheme_Bottles,
                    SUM(pd.FreeCases * pd.CaseRate) as Total_Scheme_Amount,
                    AVG(pd.MRP) as Avg_MRP
                  FROM tblpurchases p
                  INNER JOIN tblpurchasedetails pd ON p.ID = pd.PurchaseID
                  INNER JOIN tblsupplier s ON p.SUBCODE = s.CODE
                  WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?";

        $params = [$date_from, $date_to, $company_id];
        $types = "ssi";
        
        if ($supplier_type != 'all_supplier' && !empty($supplier_code)) {
            $query .= " AND p.SUBCODE = ?";
            $params[] = $supplier_code;
            $types .= "s";
        }
        
        if ($supplier_type == 'particular_supplier_particular_brand' && !empty($brand_code)) {
            $query .= " AND pd.ItemName LIKE ?";
            $params[] = "%$brand_code%";
            $types .= "s";
        }
        
        $query .= " GROUP BY s.DETAILS, pd.ItemName 
                   ORDER BY s.DETAILS, pd.ItemName";
        
        $stmt = $conn->prepare($query);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $result = $stmt->get_result();
            $summary_data = $result->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }
    
    // Calculate gross amount
    $gross_query = "SELECT SUM(p.TAMT) as Gross_Amount
                    FROM tblpurchases p
                    WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?";

    $gross_params = [$date_from, $date_to, $company_id];
    $gross_types = "ssi";
    
    if ($supplier_type != 'all_supplier' && !empty($supplier_code)) {
        $gross_query .= " AND p.SUBCODE = ?";
        $gross_params[] = $supplier_code;
        $gross_types .= "s";
    }
    
    $gross_stmt = $conn->prepare($gross_query);
    if ($gross_stmt) {
        $gross_stmt->bind_param($gross_types, ...$gross_params);
        $gross_stmt->execute();
        $gross_result = $gross_stmt->get_result();
        $gross_row = $gross_result->fetch_assoc();
        $gross_amount = $gross_row['Gross_Amount'] ?? 0;
        $gross_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Supplier Wise Purchase Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
   <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    .text-right {
        text-align: right;
    }
    .text-center {
        text-align: center;
    }
    .total-row {
        font-weight: bold;
        background-color: #e9ecef;
    }
    .brand-row, .brand-header {
        background-color: #f8f9fa;
        font-weight: bold;
    }
    .brand-header {
        background-color: #e9ecef;
    }
    .report-table {
        font-size: 0.85rem;
    }
    .report-table th {
        white-space: nowrap;
        padding: 4px 8px;
    }
    .report-table td {
        padding: 4px 8px;
    }
    @media print {
        .report-table {
            font-size: 0.75rem;
        }
        .report-table th,
        .report-table td {
            padding: 2px 4px;
        }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Supplier Wise Purchase Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-4">
                <label class="form-label">Report Type:</label>
                <select name="report_type" class="form-select">
                  <option value="detailed" <?= $report_type === 'detailed' ? 'selected' : '' ?>>Detailed Report</option>
                  <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Summary Report</option>
                </select>
              </div>
              <div class="col-md-8">
                <label class="form-label">Report Details:</label>
                <select name="supplier_type" class="form-select details-dropdown" id="supplier_type">
                  <option value="particular_supplier_particular_brand" <?= $supplier_type === 'particular_supplier_particular_brand' ? 'selected' : '' ?>>Particular Supplier Particular Brand</option>
                  <option value="particular_supplier_all_brands" <?= $supplier_type === 'particular_supplier_all_brands' ? 'selected' : '' ?>>Particular Supplier All Brands</option>
                  <option value="all_supplier" <?= $supplier_type === 'all_supplier' ? 'selected' : '' ?>>All Supplier</option>
                </select>
              </div>
            </div>
            
            <div class="row mb-3">
              <div class="col-md-6">
                <div class="row">
                  <div class="col-md-6">
                    <label class="form-label">Date From:</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">Date To:</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
                  </div>
                </div>
              </div>
              
              <div class="col-md-6">
                <label class="form-label">Supplier:</label>
                <select name="supplier_code" class="form-select">
                  <option value="">-- Select Supplier --</option>
                  <?php foreach ($suppliers as $code => $name): ?>
                    <option value="<?= htmlspecialchars($code) ?>" 
                            <?= $supplier_code == $code ? 'selected' : '' ?>>
                      <?= htmlspecialchars($name) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            
            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label">Brand:</label>
                <select name="brand_code" class="form-select" id="brand_code"
                        <?= $supplier_type !== 'particular_supplier_particular_brand' ? 'disabled' : '' ?>>
                  <option value="">-- Select Brand --</option>
                  <?php foreach ($brands as $brand): ?>
                    <option value="<?= htmlspecialchars($brand) ?>" 
                            <?= $brand_code == $brand ? 'selected' : '' ?>>
                      <?= htmlspecialchars($brand) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate Report
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
      <?php if (!empty($report_data) || !empty($summary_data)): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Supplier Wise Purchase Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
            <p class="text-muted"><strong>Report Type:</strong> <?= $report_type == 'detailed' ? 'Detailed' : 'Summary' ?></p>
          </div>
          
          <div class="table-container">
            <?php if ($report_type == 'detailed'): ?>
              <!-- Detailed Report View - MATCHING PDF COLUMNS -->
              <table class="report-table table table-bordered">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>T. P. No.</th>
                    <th>Inv. No.</th>
                    <th class="text-right">Cases</th>
                    <th class="text-right">Units</th>
                    <th class="text-right">Total Bottles</th>
                    <th class="text-right">Scheme Cases</th>
                    <th class="text-right">Units</th>
                    <th class="text-right">Scheme Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $current_supplier = '';
                  $current_item = '';
                  $supplier_total_cases = 0;
                  $supplier_total_units = 0;
                  $supplier_total_bottles = 0;
                  $supplier_total_scheme_cases = 0;
                  $supplier_total_scheme_units = 0;
                  $supplier_total_scheme_amount = 0;
                  
                  foreach ($report_data as $index => $row): 
                    if ($current_supplier != $row['Supplier_Description']): 
                      // Display supplier total if not first supplier
                      if ($current_supplier != ''): ?>
                        <tr class="total-row">
                          <td colspan="3" class="text-end"><strong>Supplier Total:</strong></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_cases, 2) ?></strong></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_units, 2) ?></strong></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_bottles, 2) ?></strong></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_scheme_cases, 2) ?></strong></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_scheme_units, 2) ?></strong></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_scheme_amount, 2) ?></strong></td>
                        </tr>
                      <?php endif;
                      
                      $current_supplier = $row['Supplier_Description'];
                      $current_item = '';
                      // Reset supplier totals
                      $supplier_total_cases = 0;
                      $supplier_total_units = 0;
                      $supplier_total_bottles = 0;
                      $supplier_total_scheme_cases = 0;
                      $supplier_total_scheme_units = 0;
                      $supplier_total_scheme_amount = 0;
                  ?>
                    <tr class="brand-row">
                      <td colspan="9"><strong>Supplier Description :</strong> <?= htmlspecialchars($row['Supplier_Description']) ?></td>
                    </tr>
                  <?php endif; ?>
                  
                  <?php if ($current_item != $row['Item_Description']): 
                    $current_item = $row['Item_Description'];
                  ?>
                    <tr class="brand-header">
                      <td colspan="9"><strong>Item Description :</strong> <?= htmlspecialchars($row['Item_Description']) ?></td>
                    </tr>
                  <?php endif; ?>
                  
                  <tr>
                    <td><?= date('d/m/Y', strtotime($row['DATE'])) ?></td>
                    <td><?= htmlspecialchars($row['T_P_NO']) ?></td>
                    <td><?= htmlspecialchars($row['INV_NO']) ?></td>
                    <td class="text-right"><?= number_format($row['Cases'], 2) ?></td>
                    <td class="text-right"><?= number_format($row['Units'], 0) ?></td>
                    <td class="text-right"><?= number_format($row['Total_Bottles'], 0) ?></td>
                    <td class="text-right"><?= number_format($row['Scheme_Cases'], 2) ?></td>
                    <td class="text-right"><?= number_format($row['Scheme_Units'], 0) ?></td>
                    <td class="text-right"><?= number_format($row['Scheme_Amount'], 2) ?></td>
                  </tr>
                  
                  <?php 
                  // Accumulate supplier totals
                  $supplier_total_cases += floatval($row['Cases']);
                  $supplier_total_units += floatval($row['Units']);
                  $supplier_total_bottles += floatval($row['Total_Bottles']);
                  $supplier_total_scheme_cases += floatval($row['Scheme_Cases']);
                  $supplier_total_scheme_units += floatval($row['Scheme_Units']);
                  $supplier_total_scheme_amount += floatval($row['Scheme_Amount']);
                  
                  // Display final supplier total
                  if ($index == count($report_data) - 1): ?>
                    <tr class="total-row">
                      <td colspan="3" class="text-end"><strong>Supplier Total:</strong></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_cases, 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_units, 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_bottles, 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_scheme_cases, 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_scheme_units, 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_scheme_amount, 2) ?></strong></td>
                    </tr>
                  <?php endif;
                  
                  endforeach; ?>
                  
                  <tr class="total-row">
                    <td colspan="8" class="text-end"><strong>Gross Amount :</strong></td>
                    <td class="text-right"><strong><?= number_format($gross_amount, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
            <?php else: ?>
              <!-- Summary Report View - MATCHING PDF COLUMNS -->
              <table class="report-table table table-bordered">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Category</th>
                    <th>T. P. No.</th>
                    <th>Inv. No.</th>
                    <th class="text-right">Cases</th>
                    <th class="text-right">Units</th>
                    <th class="text-right">Total Bottles</th>
                    <th class="text-right">Case Rate</th>
                    <th class="text-right">Tot. Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $current_supplier = '';
                  $supplier_total_cases = 0;
                  $supplier_total_units = 0;
                  $supplier_total_bottles = 0;
                  $supplier_total_amount = 0;
                  
                  foreach ($summary_data as $index => $row): 
                    if ($current_supplier != $row['Supplier_Description']): 
                      // Display supplier total if not first supplier
                      if ($current_supplier != ''): ?>
                        <tr class="total-row">
                          <td colspan="4" class="text-end"><strong>Gross Amount :</strong></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_cases, 2) ?></strong></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_units, 2) ?></strong></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_bottles, 2) ?></strong></td>
                          <td class="text-right"></td>
                          <td class="text-right"><strong><?= number_format($supplier_total_amount, 2) ?></strong></td>
                        </tr>
                      <?php endif;
                      
                      $current_supplier = $row['Supplier_Description'];
                      // Reset supplier totals
                      $supplier_total_cases = 0;
                      $supplier_total_units = 0;
                      $supplier_total_bottles = 0;
                      $supplier_total_amount = 0;
                  ?>
                    <tr class="brand-row">
                      <td colspan="9"><strong>Supplier Description : <?= htmlspecialchars($row['Supplier_Description']) ?></strong></td>
                    </tr>
                  <?php endif; ?>
                  
                  <tr>
                    <td></td>
                    <td><?= htmlspecialchars($row['Item_Description']) ?></td>
                    <td></td>
                    <td></td>
                    <td class="text-right"><?= number_format($row['Total_Cases'], 2) ?></td>
                    <td class="text-right"><?= number_format($row['Total_Units'], 0) ?></td>
                    <td class="text-right"><?= number_format($row['Total_Bottles'], 0) ?></td>
                    <td class="text-right"><?= number_format($row['Avg_CaseRate'], 3) ?></td>
                    <td class="text-right"><?= number_format($row['Total_Amount'], 2) ?></td>
                  </tr>
                  
                  <?php 
                  // Accumulate supplier totals
                  $supplier_total_cases += floatval($row['Total_Cases']);
                  $supplier_total_units += floatval($row['Total_Units']);
                  $supplier_total_bottles += floatval($row['Total_Bottles']);
                  $supplier_total_amount += floatval($row['Total_Amount']);
                  
                  // Display final supplier total
                  if ($index == count($summary_data) - 1): ?>
                    <tr class="total-row">
                      <td colspan="4" class="text-end"><strong>Gross Amount :</strong></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_cases, 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_units, 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_bottles, 2) ?></strong></td>
                      <td class="text-right"></td>
                      <td class="text-right"><strong><?= number_format($supplier_total_amount, 2) ?></strong></td>
                    </tr>
                  <?php endif;
                  
                  endforeach; ?>
                  
                  <tr class="total-row">
                    <td colspan="8" class="text-end"><strong>Overall Gross Amount :</strong></td>
                    <td class="text-right"><strong><?= number_format($gross_amount, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif (isset($_GET['generate'])): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No purchase records found for the selected criteria.
        </div>
      <?php endif; ?>
    </div>
  <?php include 'components/footer.php'; ?>

  </div>
  
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Enable/disable brand selection based on report type
  $(document).ready(function() {
    // Initial state
    toggleBrandDropdown();
    
    // Change event
    $('#supplier_type').change(function() {
      toggleBrandDropdown();
    });
    
    function toggleBrandDropdown() {
      if ($('#supplier_type').val() === 'particular_supplier_particular_brand') {
        $('#brand_code').prop('disabled', false);
      } else {
        $('#brand_code').prop('disabled', true);
        $('#brand_code').val('');
      }
    }
  });
</script>
</body>
</html>