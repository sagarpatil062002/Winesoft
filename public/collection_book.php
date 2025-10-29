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
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('first day of this month'));
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$supplier_name = isset($_GET['supplier_name']) ? $_GET['supplier_name'] : '';

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

// Fetch suppliers for dropdown from purchases and expenses tables
$suppliers = [];
$supplierQuery = "SELECT DISTINCT PARTI as supplier_name FROM (
    SELECT DISTINCT PARTI FROM tblexpenses WHERE COMP_ID = ? AND PARTI IS NOT NULL AND PARTI != ''
    UNION 
    SELECT DISTINCT SUBCODE as PARTI FROM tblpurchases WHERE CompID = ? AND SUBCODE IS NOT NULL AND SUBCODE != ''
) AS supplier_data ORDER BY supplier_name";
$supplierStmt = $conn->prepare($supplierQuery);
$supplierStmt->bind_param("ii", $compID, $compID);
$supplierStmt->execute();
$supplierResult = $supplierStmt->get_result();
while ($row = $supplierResult->fetch_assoc()) {
    $suppliers[] = $row['supplier_name'];
}
$supplierStmt->close();

$report_data = [];
$total_bill_amount = 0;
$total_cheque_amount = 0;

if (isset($_GET['generate'])) {
    // Build query for purchases data with payment information
    $purchase_query = "SELECT 
        p.DATE as BillDate,
        p.INV_NO as BillNo,
        p.TAMT as BillAmount,
        e.CHEQ_DT as ChequeDate,
        e.CHEQ_NO as ChequeNo,
        e.AMOUNT as ChequeAmount,
        p.SUBCODE as SupplierCode
    FROM tblpurchases p
    LEFT JOIN tblexpenses e ON p.VOC_NO = e.PURCHASE_VOC_NO AND p.CompID = e.COMP_ID
    WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?";
    
    $params = [$date_from, $date_to, $compID];
    $types = "ssi";
    
    if (!empty($supplier_name)) {
        $purchase_query .= " AND (p.SUBCODE = ? OR EXISTS (
            SELECT 1 FROM tblexpenses ex 
            WHERE ex.PARTI = ? AND ex.PURCHASE_VOC_NO = p.VOC_NO AND ex.COMP_ID = p.CompID
        ))";
        $params[] = $supplier_name;
        $params[] = $supplier_name;
        $types .= "ss";
    }
    
    $purchase_query .= " ORDER BY p.DATE, p.INV_NO";
    
    $purchase_stmt = $conn->prepare($purchase_query);
    $purchase_stmt->bind_param($types, ...$params);
    $purchase_stmt->execute();
    $purchase_result = $purchase_stmt->get_result();
    
    while ($row = $purchase_result->fetch_assoc()) {
        $report_data[] = $row;
        $total_bill_amount += $row['BillAmount'];
        if (!empty($row['ChequeAmount'])) {
            $total_cheque_amount += $row['ChequeAmount'];
        }
    }
    
    $purchase_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Supplier Wise Collection Book - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <style>
    .collection-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    .collection-table th, .collection-table td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }
    .collection-table th {
        background-color: #f2f2f2;
        font-weight: bold;
    }
    .collection-table tr:nth-child(even) {
        background-color: #f9f9f9;
    }
    .collection-table .total-row {
        background-color: #e9ecef;
        font-weight: bold;
    }
    .company-header {
        text-align: center;
        margin-bottom: 20px;
    }
    .supplier-info {
        margin-bottom: 15px;
        font-weight: bold;
    }
    .date-range {
        margin-bottom: 15px;
    }
    .text-right {
        text-align: right;
    }
    .text-end {
        text-align: end;
    }
    @media print {
        .no-print {
            display: none !important;
        }
        .company-header h1 {
            font-size: 24px;
        }
        .company-header h5 {
            font-size: 16px;
        }
        body {
            font-size: 12px;
        }
        .collection-table {
            font-size: 11px;
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
      <h3 class="mb-4">Supplier Wise Collection Book</h3>

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
              <div class="col-md-3">
                <label class="form-label">Supplier:</label>
                <select name="supplier_name" class="form-select">
                  <option value="">All Suppliers</option>
                  <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?= htmlspecialchars($supplier) ?>" 
                      <?= $supplier_name == $supplier ? 'selected' : '' ?>>
                      <?= htmlspecialchars($supplier) ?>
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
      <?php if (isset($_GET['generate'])): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Supplier Wise Collection Book From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
            <?php if (!empty($supplier_name)): ?>
              <div class="supplier-info">
                Supplier Name: <?= htmlspecialchars($supplier_name) ?>
              </div>
            <?php endif; ?>
          </div>
          
          <div class="table-container">
            <?php if (!empty($report_data)): ?>
              <table class="collection-table">
                <thead>
                  <tr>
                    <th>Bill Date</th>
                    <th>Bill No.</th>
                    <th>Bill Amt.</th>
                    <th>Cheq. Date</th>
                    <th>Cheq. No</th>
                    <th>Cheq. Amt.</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($report_data as $row): ?>
                    <tr>
                      <td><?= date('d-m-Y', strtotime($row['BillDate'])) ?></td>
                      <td><?= htmlspecialchars($row['BillNo']) ?></td>
                      <td class="text-right"><?= number_format($row['BillAmount'], 2) ?></td>
                      <td><?= !empty($row['ChequeDate']) && $row['ChequeDate'] != '0000-00-00' ? date('d-m-Y', strtotime($row['ChequeDate'])) : '' ?></td>
                      <td><?= !empty($row['ChequeNo']) ? htmlspecialchars($row['ChequeNo']) : '' ?></td>
                      <td class="text-right"><?= !empty($row['ChequeAmount']) ? number_format($row['ChequeAmount'], 2) : '' ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="total-row">
                    <td colspan="2" class="text-end"><strong>Total:</strong></td>
                    <td class="text-right"><strong><?= number_format($total_bill_amount, 2) ?></strong></td>
                    <td colspan="2"></td>
                    <td class="text-right"><strong><?= number_format($total_cheque_amount, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
              <div class="mt-3 text-end">
                <strong>Pages: Page 1 of 1</strong>
              </div>
            <?php else: ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> No collection records found for the selected criteria.
              </div>
            <?php endif; ?>
          </div>
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