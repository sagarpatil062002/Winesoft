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

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$supplier = isset($_GET['supplier']) ? $_GET['supplier'] : 'all';
$group_by = isset($_GET['group_by']) ? $_GET['group_by'] : 'item';

// Format dates for display
$from_date_display = date('d-M-Y', strtotime($from_date));
$to_date_display = date('d-M-Y', strtotime($to_date));

$compID = $_SESSION['CompID'];

// Get company name from session or set default
$companyName = isset($_SESSION['company_name']) ? $_SESSION['company_name'] : "Company Name";

// Fetch suppliers from tbllheads for dropdown (filtered by company)
$suppliers_query = "SELECT LCODE, LHEAD, REF_CODE FROM tbllheads WHERE GCODE = 33 AND CompID = ? ORDER BY LHEAD";
$suppliers_stmt = $conn->prepare($suppliers_query);
$suppliers_stmt->bind_param("i", $compID);
$suppliers_stmt->execute();
$suppliers_result = $suppliers_stmt->get_result();
$suppliers = [];
while ($row = $suppliers_result->fetch_assoc()) {
    $suppliers[$row['REF_CODE']] = $row['LHEAD'];
}
$suppliers_stmt->close();

// Build query based on grouping option
if ($group_by === 'supplier') {
    // Group by supplier
    $query = "SELECT 
                p.SUBCODE as group_key,
                l.LHEAD as group_name,
                COUNT(DISTINCT p.ID) as invoice_count,
                SUM(pd.Cases) as total_cases,
                SUM(pd.Bottles) as total_bottles,
                SUM(pd.Amount) as total_amount
              FROM tblpurchases p
              INNER JOIN tblpurchasedetails pd ON p.ID = pd.PurchaseID
              LEFT JOIN tbllheads l ON p.SUBCODE = l.REF_CODE
              WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?";
} else {
    // Default: Group by item
    $query = "SELECT 
                pd.ItemCode as group_key,
                pd.ItemName as group_name,
                pd.Size,
                COUNT(DISTINCT p.ID) as invoice_count,
                SUM(pd.Cases) as total_cases,
                SUM(pd.Bottles) as total_bottles,
                SUM(pd.Amount) as total_amount
              FROM tblpurchases p
              INNER JOIN tblpurchasedetails pd ON p.ID = pd.PurchaseID
              WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?";
}

$params = [$from_date, $to_date, $compID];
$types = "ssi";

if ($supplier !== 'all') {
    $query .= " AND p.SUBCODE = ?";
    $params[] = $supplier;
    $types .= "s";
}

if ($group_by === 'supplier') {
    $query .= " GROUP BY p.SUBCODE ORDER BY l.LHEAD";
} else {
    $query .= " GROUP BY pd.ItemCode, pd.Size ORDER BY pd.ItemName";
}

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$purchase_data = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate totals
$totals = [
    'invoice_count' => 0,
    'total_cases' => 0,
    'total_bottles' => 0,
    'total_amount' => 0
];

foreach ($purchase_data as $data) {
    $totals['invoice_count'] += intval($data['invoice_count']);
    $totals['total_cases'] += floatval($data['total_cases']);
    $totals['total_bottles'] += intval($data['total_bottles']);
    $totals['total_amount'] += floatval($data['total_amount']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Summary Case Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <style>
    .report-table th {
      background-color: #f8f9fa;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .total-row {
      font-weight: bold;
      background-color: #e9ecef;
    }
    @media print {
      .no-print {
        display: none !important;
      }
      .print-section {
        display: block !important;
      }
      body {
        font-size: 12px;
      }
      .report-table {
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
      <!-- Filters Section (Not Printable) -->
      <div class="report-filters no-print">
        <form method="GET" class="row g-3">
          <div class="col-md-2">
            <label class="form-label">From Date</label>
            <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">To Date</label>
            <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Supplier</label>
            <select class="form-select" name="supplier">
              <option value="all" <?= $supplier === 'all' ? 'selected' : '' ?>>All Suppliers</option>
              <?php foreach ($suppliers as $ref_code => $lhead): ?>
                <option value="<?= htmlspecialchars($ref_code) ?>" <?= $supplier === $ref_code ? 'selected' : '' ?>>
                  <?= htmlspecialchars($lhead) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Group By</label>
            <select class="form-select" name="group_by">
              <option value="item" <?= $group_by === 'item' ? 'selected' : '' ?>>Item</option>
              <option value="supplier" <?= $group_by === 'supplier' ? 'selected' : '' ?>>Supplier</option>
            </select>
          </div>
          <div class="col-md-12 mt-4">
            <div class="btn-group">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-sync-alt"></i> Generate
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print"></i> Print
              </button>
              <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-sign-out-alt"></i> Exit
              </a>
            </div>
          </div>
        </form>
      </div>

      <!-- Printable Report Section -->
      <div class="print-section">
        <div class="print-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
          <h4>Purchase Summary Case Report</h4>
        </div>

        <!-- Report Header -->
        <div class="report-header">
          <div class="report-title">
            Purchase Summary Case Report | From <?= $from_date_display ?> To <?= $to_date_display ?>
            <?php if ($supplier !== 'all'): ?>
              | Supplier: <?= htmlspecialchars($suppliers[$supplier] ?? $supplier) ?>
            <?php endif; ?>
            | Grouped by: <?= $group_by === 'supplier' ? 'Supplier' : 'Item' ?>
          </div>
        </div>

        <!-- Report Table -->
        <div class="table-container">
          <table class="styled-table report-table">
            <thead>
              <tr>
                <?php if ($group_by === 'supplier'): ?>
                  <th>Supplier Name</th>
                <?php else: ?>
                  <th>Item Name</th>
                  <th>Size</th>
                <?php endif; ?>
                <th>Invoices</th>
                <th>Cases</th>
                <th>Bottles</th>
                <th>Total Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($purchase_data)): ?>
                <?php foreach ($purchase_data as $data): ?>
                  <tr>
                    <?php if ($group_by === 'supplier'): ?>
                      <td><?= htmlspecialchars($data['group_name']) ?></td>
                    <?php else: ?>
                      <td><?= htmlspecialchars($data['group_name']) ?></td>
                      <td><?= htmlspecialchars($data['Size']) ?></td>
                    <?php endif; ?>
                    <td class="text-right"><?= number_format($data['invoice_count']) ?></td>
                    <td class="text-right"><?= number_format($data['total_cases'], 2) ?></td>
                    <td class="text-right"><?= number_format($data['total_bottles']) ?></td>
                    <td class="text-right"><?= number_format($data['total_amount'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                  <?php if ($group_by === 'supplier'): ?>
                    <td class="text-center"><strong>Total</strong></td>
                  <?php else: ?>
                    <td colspan="2" class="text-center"><strong>Total</strong></td>
                  <?php endif; ?>
                  <td class="text-right"><strong><?= number_format($totals['invoice_count']) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($totals['total_cases'], 2) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($totals['total_bottles']) ?></strong></td>
                  <td class="text-right"><strong><?= number_format($totals['total_amount'], 2) ?></strong></td>
                </tr>
              <?php else: ?>
                <tr>
                  <td colspan="<?= $group_by === 'supplier' ? 6 : 7 ?>" class="text-center text-muted">No purchase data found for the selected period.</td>
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
</body>
</html>