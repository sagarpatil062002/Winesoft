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
while ($row = $supplierResult->fetch_assoc()) {
    $suppliers[$row['CODE']] = $row['LHEAD'];
}

// Fetch brands from tblitemmaster - using DETAILS (item name) instead of DETAILS2 (size)
$brands = [];
$brandQuery = "SELECT DISTINCT DETAILS 
               FROM tblitemmaster 
               WHERE DETAILS IS NOT NULL AND DETAILS != '' 
               ORDER BY DETAILS";
$brandResult = $conn->query($brandQuery);
while ($row = $brandResult->fetch_assoc()) {
    $brands[] = $row['DETAILS'];
}

// Generate report data based on filters
$report_data = [];
$gross_amount = 0;

if (isset($_GET['generate'])) {
    // Build the query based on selected filters
    $query = "SELECT 
                p.DATE, 
                p.TPNO as T_P_NO, 
                p.INV_NO, 
                pd.Cases, 
                pd.Bottles as Units, 
                (pd.Cases * pd.BottlesPerCase + pd.Bottles) as Total_Bottles,
                '' as Scheme_Cases, 
                '' as Scheme_Units, 
                '' as Scheme_Amount,
                s.DETAILS as Supplier_Description,
                pd.ItemName as Item_Description
              FROM tblpurchases p
              INNER JOIN tblpurchasedetails pd ON p.ID = pd.PurchaseID
              INNER JOIN tblsupplier s ON p.SUBCODE = s.CODE
              WHERE p.DATE BETWEEN ? AND ?";
    
    $params = [$date_from, $date_to];
    $types = "ss";
    
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
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $report_data = $result->fetch_all(MYSQLI_ASSOC);
    
    // Calculate gross amount
    $gross_query = "SELECT SUM(p.TAMT) as Gross_Amount 
                    FROM tblpurchases p
                    WHERE p.DATE BETWEEN ? AND ?";
    
    $gross_params = [$date_from, $date_to];
    $gross_types = "ss";
    
    if ($supplier_type != 'all_supplier' && !empty($supplier_code)) {
        $gross_query .= " AND p.SUBCODE = ?";
        $gross_params[] = $supplier_code;
        $gross_types .= "s";
    }
    
    $gross_stmt = $conn->prepare($gross_query);
    $gross_stmt->bind_param($gross_types, ...$gross_params);
    $gross_stmt->execute();
    $gross_result = $gross_stmt->get_result();
    $gross_row = $gross_result->fetch_assoc();
    $gross_amount = $gross_row['Gross_Amount'] ?? 0;
    
    $stmt->close();
    $gross_stmt->close();
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
  <style>
    :root {
      --primary-color: #8B0000;
      --secondary-color: #f8f9fa;
      --accent-color: #ffc107;
    }
    
    body {
      background-color: #f5f5f5;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    
    .dashboard-container {
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }
    
    .main-content {
      flex: 1;
      padding: 20px;
    }
    
    .content-area {
      background: white;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
      padding: 25px;
      margin-top: 20px;
    }
    
    .card.filter-card {
      border: none;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    }
    
    .card.filter-card .card-header {
      background-color: var(--primary-color);
      color: white;
      border-radius: 8px 8px 0 0 !important;
      padding: 12px 20px;
      font-weight: 600;
    }
    
    .form-label {
      font-weight: 500;
      margin-bottom: 5px;
    }
    
    .btn-primary {
      background-color: var(--primary-color);
      border-color: var(--primary-color);
    }
    
    .btn-primary:hover {
      background-color: #6d0000;
      border-color: #6d0000;
    }
    
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
    }
    
    .report-table th {
      background-color: var(--primary-color);
      color: white;
      padding: 10px;
      text-align: left;
      border: 1px solid #dee2e6;
    }
    
    .report-table td {
      padding: 10px;
      border: 1px solid #dee2e6;
    }
    
    .brand-row, .brand-header {
      background-color: #f8f9fa;
      font-weight: bold;
    }
    
    .brand-row td {
      background-color: #e9ecef;
      padding: 8px 10px;
    }
    
    .brand-header td {
      background-color: #f8f9fa;
      padding: 8px 10px;
    }
    
    .total-row {
      font-weight: bold;
      background-color: #fff3cd;
    }
    
    .text-right {
      text-align: right;
    }
    
    .text-end {
      text-align: end;
    }
    
    .company-header {
      text-align: center;
      margin-bottom: 25px;
      padding-bottom: 15px;
      border-bottom: 2px solid var(--primary-color);
    }
    
    .company-header h1 {
      color: var(--primary-color);
      font-size: 28px;
      margin-bottom: 5px;
    }
    
    .company-header h5 {
      color: #495057;
      font-size: 16px;
    }
    
    .action-controls {
      display: flex;
      gap: 10px;
      margin-top: 20px;
      flex-wrap: wrap;
    }
    
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
      }
      .no-print {
        display: none !important;
      }
      .company-header {
        margin-top: 0;
      }
    }
    
    @media (max-width: 768px) {
      .content-area {
        padding: 15px;
      }
      
      .action-controls .btn {
        flex: 1;
        min-width: 120px;
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
      <h3 class="mb-4">Supplier Wise Purchase Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-12">
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
      <?php if (!empty($report_data)): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Supplier Wise Purchase Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
          </div>
          
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>T. P. No.</th>
                  <th>Inv. No.</th>
                  <th>Cases</th>
                  <th>Units</th>
                  <th>Total Bottles</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $current_supplier = '';
                $current_item = '';
                foreach ($report_data as $row): 
                  if ($current_supplier != $row['Supplier_Description']): 
                    $current_supplier = $row['Supplier_Description'];
                    $current_item = '';
                ?>
                  <tr class="brand-row">
                    <td colspan="6"><strong>Supplier Description :</strong> <?= htmlspecialchars($row['Supplier_Description']) ?></td>
                  </tr>
                <?php endif; ?>
                
                <?php if ($current_item != $row['Item_Description']): 
                  $current_item = $row['Item_Description'];
                ?>
                  <tr class="brand-header">
                    <td colspan="6"><strong>Item Description :</strong> <?= htmlspecialchars($row['Item_Description']) ?></td>
                  </tr>
                <?php endif; ?>
                
                <tr>
                  <td><?= date('d/m/Y', strtotime($row['DATE'])) ?></td>
                  <td><?= htmlspecialchars($row['T_P_NO']) ?></td>
                  <td><?= htmlspecialchars($row['INV_NO']) ?></td>
                  <td class="text-right"><?= number_format($row['Cases'], 2) ?></td>
                  <td class="text-right"><?= htmlspecialchars($row['Units']) ?></td>
                  <td class="text-right"><?= number_format($row['Total_Bottles'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <tr class="total-row">
                  <td colspan="5" class="text-end"><strong>Gross Amount :</strong></td>
                  <td class="text-right"><strong><?= number_format($gross_amount, 2) ?></strong></td>
                </tr>
              </tbody>
            </table>
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