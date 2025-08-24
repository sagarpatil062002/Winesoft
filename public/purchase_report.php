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

// Format dates for display
$from_date_display = date('d-M-Y', strtotime($from_date));
$to_date_display = date('d-M-Y', strtotime($to_date));

// Fetch suppliers from tbllheads for dropdown
$suppliers_query = "SELECT LCODE, LHEAD, REF_CODE FROM tbllheads WHERE GCODE = 33 ORDER BY LHEAD";
$suppliers_result = $conn->query($suppliers_query);
$suppliers = [];
while ($row = $suppliers_result->fetch_assoc()) {
    $suppliers[$row['REF_CODE']] = $row['LHEAD'];
}

// Build query to fetch purchase data
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
          WHERE p.DATE BETWEEN ? AND ?";

$params = [$from_date, $to_date];
$types = "ss";



$query .= " ORDER BY p.DATE, p.VOC_NO";

$stmt = $conn->prepare($query);
if ($supplier !== 'all') {
    $stmt->bind_param($types, ...$params);
} else {
    $stmt->bind_param($types, $from_date, $to_date);
}

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
      <!-- Filters Section (Not Printable) -->
      <div class="report-filters no-print">
        <form method="GET" class="row g-3">
          <div class="col-md-4">
            <label class="form-label">From Date</label>
            <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">To Date</label>
            <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
          </div>
          <div class="col-md-12">
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

          <h4>Purchase Report</h4>
        </div>

        <!-- Report Header -->
        <div class="report-header">
          <div class="report-title">
            Purchase Report From <?= $from_date_display ?> To <?= $to_date_display ?>
          </div>
        </div>

        <!-- Report Table -->
        <div class="table-container">
          <table class="styled-table report-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Supplier Name</th>
                <th>V. No.</th>
                <th>Bill No.</th>
                <th>T.P. No.</th>
                <th>Net Amt.</th>
                <th>Cash Disc.</th>
                <th>Sch. Disc.</th>
                <th>Oct.</th>
                <th>Sales Tax</th>
                <th>TC $ Amt.</th>
                <th>Sarc. Amt.</th>
                <th>E.C. Amt.</th>
                <th>Stp. Duty</th>
                <th>Frieght</th>
                <th>Total Bill Amt.</th>
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
                <tr class="total-row">
                  <td colspan="5" class="text-center"><strong>Total</strong></td>
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

        

  </div>
      <?php include 'components/footer.php'; ?>

</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>