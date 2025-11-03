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

// Get company name from session or set default
$companyName = isset($_SESSION['company_name']) ? $_SESSION['company_name'] : "Company Name";

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$supplier = isset($_GET['supplier']) ? $_GET['supplier'] : 'all';

// Format dates for display
$from_date_display = date('d-M-Y', strtotime($from_date));
$to_date_display = date('d-M-Y', strtotime($to_date));

// Fetch suppliers from tblsupplier for dropdown (filtered by company if needed)
$suppliers_query = "SELECT CODE, DETAILS FROM tblsupplier ORDER BY DETAILS";
$suppliers_result = $conn->query($suppliers_query);
$suppliers = [];
while ($row = $suppliers_result->fetch_assoc()) {
    $suppliers[$row['CODE']] = $row['DETAILS'];
}

// Build query to fetch purchase data with CompID filter
$query = "SELECT 
            p.ID, 
            p.DATE, 
            p.SUBCODE, 
            p.VOC_NO, 
            p.INV_NO, 
            p.TPNO, 
            p.TAMT as net_amt, 
            p.CASHDIS as cash_disc, 
            p.SCHDIS as sch_disc, 
            p.OCTROI as oct, 
            p.STAX_AMT as sales_tax, 
            p.TCS_AMT as tc_amt, 
            p.MISC_CHARG as sarc_amt, 
            0 as ec_amt, 
            0 as stp_duty, 
            p.FREIGHT as frieght,
            (p.TAMT - p.CASHDIS - p.SCHDIS + p.OCTROI + p.STAX_AMT + p.TCS_AMT + p.MISC_CHARG + p.FREIGHT) as total
          FROM tblpurchases p
          WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?";

$params = [$from_date, $to_date, $compID];
$types = "ssi";

if ($supplier !== 'all') {
    $query .= " AND p.SUBCODE = ?";
    $params[] = $supplier;
    $types .= "s";
}

$query .= " ORDER BY p.DATE, p.VOC_NO";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$purchases = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate totals
$totals = [
    'net_amt' => 0,
    'cash_disc' => 0,
    'sch_disc' => 0,
    'oct' => 0,
    'sales_tax' => 0,
    'tc_amt' => 0,
    'sarc_amt' => 0,
    'ec_amt' => 0,
    'stp_duty' => 0,
    'frieght' => 0,
    'total' => 0
];

foreach ($purchases as $purchase) {
    $totals['net_amt'] += floatval($purchase['net_amt']);
    $totals['cash_disc'] += floatval($purchase['cash_disc']);
    $totals['sch_disc'] += floatval($purchase['sch_disc']);
    $totals['oct'] += floatval($purchase['oct']);
    $totals['sales_tax'] += floatval($purchase['sales_tax']);
    $totals['tc_amt'] += floatval($purchase['tc_amt']);
    $totals['sarc_amt'] += floatval($purchase['sarc_amt']);
    $totals['ec_amt'] += floatval($purchase['ec_amt']);
    $totals['stp_duty'] += floatval($purchase['stp_duty']);
    $totals['frieght'] += floatval($purchase['frieght']);
    $totals['total'] += floatval($purchase['total']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Report - WineSoft</title>
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
      <h3 class="mb-4">Purchase Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Date From:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date To:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Supplier:</label>
                <select class="form-select" name="supplier">
                  <option value="all" <?= $supplier === 'all' ? 'selected' : '' ?>>All Suppliers</option>
                  <?php foreach ($suppliers as $code => $name): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $supplier === $code ? 'selected' : '' ?>>
                      <?= htmlspecialchars($name) ?>
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
      <?php if (isset($_GET['from_date']) || isset($_GET['to_date']) || isset($_GET['supplier'])): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Purchase Report From <?= $from_date_display ?> To <?= $to_date_display ?></h5>
            <?php if ($supplier !== 'all'): ?>
              <p class="text-muted">Supplier: <?= htmlspecialchars($suppliers[$supplier] ?? $supplier) ?></p>
            <?php endif; ?>
          </div>
          
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Supplier Name</th>
                  <th>V. No.</th>
                  <th>Bill No.</th>
                  <th>T.P. No.</th>
                  <th class="text-right">Net Amt.</th>
                  <th class="text-right">Cash Disc.</th>
                  <th class="text-right">Sch. Disc.</th>
                  <th class="text-right">Oct.</th>
                  <th class="text-right">Sales Tax</th>
                  <th class="text-right">TC $ Amt.</th>
                  <th class="text-right">Sarc. Amt.</th>
                  <th class="text-right">E.C. Amt.</th>
                  <th class="text-right">Stp. Duty</th>
                  <th class="text-right">Frieght</th>
                  <th class="text-right">Total Bill Amt.</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($purchases)): ?>
                  <?php foreach ($purchases as $purchase): ?>
                    <tr>
                      <td><?= date('d-M-Y', strtotime($purchase['DATE'])) ?></td>
                      <td><?= isset($suppliers[$purchase['SUBCODE']]) ? htmlspecialchars($suppliers[$purchase['SUBCODE']]) : htmlspecialchars($purchase['SUBCODE']) ?></td>
                      <td><?= htmlspecialchars($purchase['VOC_NO']) ?></td>
                      <td><?= htmlspecialchars($purchase['INV_NO']) ?></td>
                      <td><?= htmlspecialchars($purchase['TPNO']) ?></td>
                      <td class="text-right"><?= number_format($purchase['net_amt'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['cash_disc'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['sch_disc'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['oct'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['sales_tax'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['tc_amt'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['sarc_amt'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['ec_amt'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['stp_duty'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['frieght'], 2) ?></td>
                      <td class="text-right"><?= number_format($purchase['total'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="total-row" style="border-bottom: double 3px #000;">
                    <td colspan="5" class="text-end"><strong>Total:</strong></td>
                    <td class="text-right"><strong><?= number_format($totals['net_amt'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['cash_disc'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['sch_disc'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['oct'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['sales_tax'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['tc_amt'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['sarc_amt'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['ec_amt'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['stp_duty'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['frieght'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($totals['total'], 2) ?></strong></td>
                  </tr>
                <?php else: ?>
                  <tr>
                    <td colspan="16" class="text-center text-muted">No purchases found for the selected period.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          
          
      <?php elseif (isset($_GET['from_date']) && empty($purchases)): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No purchases found for the selected criteria.
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