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
$tax_type = isset($_GET['tax_type']) ? $_GET['tax_type'] : 'tcs';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'detailed';
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'date';

// Format dates for display
$from_date_display = date('d-M-Y', strtotime($from_date));
$to_date_display = date('d-M-Y', strtotime($to_date));

// Get company ID from session
$compID = $_SESSION['CompID'];

// Fetch suppliers from tblsupplier for dropdown
$suppliers_query = "SELECT CODE, DETAILS FROM tblsupplier ORDER BY DETAILS";
$suppliers_stmt = $conn->prepare($suppliers_query);
$suppliers_stmt->execute();
$suppliers_result = $suppliers_stmt->get_result();
$suppliers = [];
while ($row = $suppliers_result->fetch_assoc()) {
    $suppliers[$row['CODE']] = $row['DETAILS'];
}
$suppliers_stmt->close();

// Build query to fetch purchase data for tax report
$query = "SELECT 
            p.DATE,
            p.VOC_NO,
            p.SUBCODE,
            p.TPNO,
            p.TP_DATE,
            p.TCS_AMT,
            p.STAX_AMT,
            p.SCHDIS,
            p.CASHDIS,
            p.OCTROI,
            p.FREIGHT,
            p.MISC_CHARG,
            p.TAMT
          FROM tblpurchases p
          WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?";

$params = [$from_date, $to_date, $compID];
$types = "ssi";

if ($supplier !== 'all') {
    $query .= " AND p.SUBCODE = ?";
    $params[] = $supplier;
    $types .= "s";
}

// Order by date for date-wise mode, by supplier for supplier-wise mode
if ($mode === 'supplier') {
    $query .= " ORDER BY p.SUBCODE, p.DATE";
} else {
    $query .= " ORDER BY p.DATE, p.SUBCODE";
}

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$purchases = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate totals
$totals = [
    'tcs_amt' => 0,
    'stax_amt' => 0,
    'octroi_amt' => 0,
    'tamt' => 0
];

foreach ($purchases as $purchase) {
    $totals['tcs_amt'] += floatval($purchase['TCS_AMT']);
    $totals['stax_amt'] += floatval($purchase['STAX_AMT']);
    $totals['octroi_amt'] += floatval($purchase['OCTROI']);
    $totals['tamt'] += floatval($purchase['TAMT']);
}

// Get company name
$company_query = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$company_stmt = $conn->prepare($company_query);
$company_stmt->bind_param("i", $compID);
$company_stmt->execute();
$company_result = $company_stmt->get_result();
$company_row = $company_result->fetch_assoc();
$companyName = $company_row['COMP_NAME'] ?? 'Company';
$company_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Other Taxes Report - WineSoft</title>
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
          <!-- Tax Type Selector -->
          <div class="col-md-12">
            <label class="form-label">Tax Type:</label>
            <div class="tax-type-selector">
              <div class="form-check tax-type-btn">
                <input class="form-check-input" type="radio" name="tax_type" id="tcsRadio" value="tcs" <?= $tax_type === 'tcs' ? 'checked' : '' ?>>
                <label class="form-check-label" for="tcsRadio">
                  TCS/Surcharge/Educ. Cess
                </label>
              </div>
              <div class="form-check tax-type-btn">
                <input class="form-check-input" type="radio" name="tax_type" id="octroiRadio" value="octroi" <?= $tax_type === 'octroi' ? 'checked' : '' ?>>
                <label class="form-check-label" for="octroiRadio">
                  OCTROI
                </label>
              </div>
              
            </div>
          </div>

          <!-- Report Type Selector -->
          <div class="col-md-4">
            <label class="form-label">Report Type:</label>
            <div class="mode-selector">
              <div class="btn-group w-100" role="group">
                <button type="button" class="btn btn-outline-primary mode-btn <?= $report_type === 'detailed' ? 'mode-active' : '' ?>" onclick="setReportType('detailed')">Detailed</button>
                <button type="button" class="btn btn-outline-primary mode-btn <?= $report_type === 'summary' ? 'mode-active' : '' ?>" onclick="setReportType('summary')">Summary</button>
              </div>
            </div>
            <input type="hidden" name="report_type" id="report_type" value="<?= $report_type ?>">
          </div>

          <!-- Mode Selector -->
          <div class="col-md-4">
            <label class="form-label">Mode:</label>
            <div class="mode-selector">
              <div class="btn-group w-100" role="group">
                <button type="button" class="btn btn-outline-primary mode-btn <?= $mode === 'date' ? 'mode-active' : '' ?>" onclick="setMode('date')">Date Wise</button>
                <button type="button" class="btn btn-outline-primary mode-btn <?= $mode === 'supplier' ? 'mode-active' : '' ?>" onclick="setMode('supplier')">Supplier Wise</button>
              </div>
            </div>
            <input type="hidden" name="mode" id="mode" value="<?= $mode ?>">
          </div>

          <!-- Date Inputs -->
          <div class="col-md-4">
            <div class="date-inputs">
              <div class="date-input">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
              </div>
              <div class="date-input">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
              </div>
            </div>
          </div>

          <!-- Supplier Selector -->
          <div class="col-md-4">
            <label class="form-label">Supplier</label>
            <select class="form-select" name="supplier">
              <option value="all" <?= $supplier === 'all' ? 'selected' : '' ?>>All Suppliers</option>
              <?php foreach ($suppliers as $code => $details): ?>
                <option value="<?= htmlspecialchars($code) ?>" <?= $supplier === $code ? 'selected' : '' ?>>
                  <?= htmlspecialchars($details) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Action Buttons -->
          <div class="col-md-12 mt-4">
            <div class="btn-group">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-cog"></i> Generate
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
          <h4>Other Taxes Report</h4>
        </div>

        <!-- Report Header -->
        <div class="report-header">
          <div class="report-title">
            <?php
            $tax_title = "";
            if ($tax_type === 'tcs') $tax_title = "T.C.S. / Surcharge / E. C. Report";
            elseif ($tax_type === 'octroi') $tax_title = "OCTROI Tax Report";
            
            echo $tax_title . " | From " . $from_date_display . " To " . $to_date_display;
            
            if ($supplier !== 'all') {
                echo " | Supplier: " . htmlspecialchars($suppliers[$supplier] ?? $supplier);
            }
            ?>
          </div>
        </div>

        <!-- Report Table -->
        <div class="table-container">
          <table class="styled-table report-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>Voc. No.</th>
                <th>Supplier</th>
                <th>T.P. No.</th>
                <?php if ($tax_type === 'tcs'): ?>
                  <th>T.C.S. Amt.</th>
                  <th>Surch. Amt.</th>
                  <th>E.C. Amt.</th>
                <?php elseif ($tax_type === 'octroi'): ?>
                  <th>OCTROI Amt.</th>
                <?php else: ?>
                  <th>Sales Tax Amt.</th>
                <?php endif; ?>
                <th>Total Amt.</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!empty($purchases)): ?>
                <?php foreach ($purchases as $purchase): ?>
                  <tr>
                    <td><?= date('d-M-Y', strtotime($purchase['DATE'])) ?></td>
                    <td><?= htmlspecialchars($purchase['VOC_NO']) ?></td>
                    <td><?= isset($suppliers[$purchase['SUBCODE']]) ? htmlspecialchars($suppliers[$purchase['SUBCODE']]) : htmlspecialchars($purchase['SUBCODE']) ?></td>
                    <td><?= htmlspecialchars($purchase['TPNO'] ?? 'N/A') ?></td>
                    <?php if ($tax_type === 'tcs'): ?>
                      <td class="text-right"><?= number_format($purchase['TCS_AMT'], 2) ?></td>
                      <td class="text-right"><?= number_format(0, 2) ?></td> <!-- Placeholder for surcharge -->
                      <td class="text-right"><?= number_format(0, 2) ?></td> <!-- Placeholder for EC amount -->
                    <?php elseif ($tax_type === 'octroi'): ?>
                      <td class="text-right"><?= number_format($purchase['OCTROI'], 2) ?></td>
                    <?php else: ?>
                      <td class="text-right"><?= number_format($purchase['STAX_AMT'], 2) ?></td>
                    <?php endif; ?>
                    <td class="text-right"><?= number_format($purchase['TAMT'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                  <td colspan="4" class="text-center"><strong>Total</strong></td>
                  <?php if ($tax_type === 'tcs'): ?>
                    <td class="text-right"><strong><?= number_format($totals['tcs_amt'], 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format(0, 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format(0, 2) ?></strong></td>
                  <?php elseif ($tax_type === 'octroi'): ?>
                    <td class="text-right"><strong><?= number_format($totals['octroi_amt'], 2) ?></strong></td>
                  <?php else: ?>
                    <td class="text-right"><strong><?= number_format($totals['stax_amt'], 2) ?></strong></td>
                  <?php endif; ?>
                  <td class="text-right"><strong><?= number_format($totals['tamt'], 2) ?></strong></td>
                </tr>
              <?php else: ?>
                <tr>
                  <td colspan="<?= $tax_type === 'tcs' ? 8 : ($tax_type === 'octroi' ? 6 : 6) ?>" class="text-center text-muted">No purchases found for the selected period.</td>
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
  function setReportType(type) {
    document.getElementById('report_type').value = type;
    
    // Update button styles
    document.querySelectorAll('[onclick*="setReportType"]').forEach(btn => {
      btn.classList.remove('mode-active');
    });
    event.target.classList.add('mode-active');
  }
  
  function setMode(mode) {
    document.getElementById('mode').value = mode;
    
    // Update button styles
    document.querySelectorAll('[onclick*="setMode"]').forEach(btn => {
      btn.classList.remove('mode-active');
    });
    event.target.classList.add('mode-active');
  }
</script>
</body>
</html>