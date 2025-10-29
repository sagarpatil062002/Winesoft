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
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'both';

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
$opening_balance = 0;
$closing_balance = 0;
$total_debit = 0;
$total_credit = 0;

if (isset($_GET['generate'])) {
    // Calculate opening balance (transactions before date_from)
    $opening_query = "SELECT 
        SUM(CASE WHEN DRCR = 'D' THEN AMOUNT ELSE 0 END) as total_debit,
        SUM(CASE WHEN DRCR = 'C' THEN AMOUNT ELSE 0 END) as total_credit
        FROM tblexpenses 
        WHERE VDATE < ? AND COMP_ID = ?";
    
    if ($mode !== 'both') {
        $opening_query .= " AND MODE = ?";
    }
    
    $opening_stmt = $conn->prepare($opening_query);
    if ($mode !== 'both') {
        $opening_stmt->bind_param("sis", $date_from, $compID, $mode);
    } else {
        $opening_stmt->bind_param("si", $date_from, $compID);
    }
    $opening_stmt->execute();
    $opening_result = $opening_stmt->get_result();
    $opening_row = $opening_result->fetch_assoc();
    $opening_balance = ($opening_row['total_credit'] ?? 0) - ($opening_row['total_debit'] ?? 0);
    $opening_stmt->close();

    // Fetch transactions for the period
    $transaction_query = "SELECT 
        VNO,
        VDATE,
        PARTI as Particulars,
        AMOUNT,
        DRCR,
        NARR as Narration,
        MODE,
        DOC_NO,
        CHEQ_NO,
        CHEQ_DT,
        INV_NO
        FROM tblexpenses 
        WHERE VDATE BETWEEN ? AND ? AND COMP_ID = ?";
    
    if ($mode !== 'both') {
        $transaction_query .= " AND MODE = ?";
    }
    
    $transaction_query .= " ORDER BY VDATE, VNO";
    
    $transaction_stmt = $conn->prepare($transaction_query);
    if ($mode !== 'both') {
        $transaction_stmt->bind_param("ssis", $date_from, $date_to, $compID, $mode);
    } else {
        $transaction_stmt->bind_param("ssi", $date_from, $date_to, $compID);
    }
    $transaction_stmt->execute();
    $transaction_result = $transaction_stmt->get_result();
    
    $running_balance = $opening_balance;
    
    while ($row = $transaction_result->fetch_assoc()) {
        $debit = $row['DRCR'] == 'D' ? $row['AMOUNT'] : 0;
        $credit = $row['DRCR'] == 'C' ? $row['AMOUNT'] : 0;
        
        $running_balance += ($credit - $debit);
        
        $report_data[] = [
            'date' => $row['VDATE'],
            'doc_no' => $row['DOC_NO'] ?: '-',
            'cheq_no' => $row['CHEQ_NO'],
            'cheq_date' => $row['CHEQ_DT'],
            'particulars' => $row['Particulars'] ?: 'Unknown',
            'debit' => $debit,
            'credit' => $credit,
            'balance' => $running_balance,
            'narration' => $row['Narration'],
            'mode' => $row['MODE'],
            'inv_no' => $row['INV_NO']
        ];
        
        $total_debit += $debit;
        $total_credit += $credit;
    }
    
    $closing_balance = $running_balance;
    $transaction_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cash/Bank Ledger Report - WineSoft</title>
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
    .balance-positive {
        color: #28a745;
        font-weight: bold;
    }
    .balance-negative {
        color: #dc3545;
        font-weight: bold;
    }
    .mode-badge {
        font-size: 0.75em;
        padding: 0.25em 0.5em;
        margin-left: 5px;
    }
    .opening-balance-row {
        background-color: #e9ecef;
        font-weight: bold;
    }
    .closing-balance-row {
        background-color: #d1ecf1;
        font-weight: bold;
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
      <h3 class="mb-4">Cash / Bank Ledger Report</h3>

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
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-select">
                  <option value="both" <?= $mode === 'both' ? 'selected' : '' ?>>Both Cash & Bank</option>
                  <option value="C" <?= $mode === 'C' ? 'selected' : '' ?>>Cash Only</option>
                  <option value="B" <?= $mode === 'B' ? 'selected' : '' ?>>Bank Only</option>
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
      <?php if (isset($_GET['generate'])): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Ledger Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
            <?php if ($mode !== 'both'): ?>
              <p class="text-muted">Mode: <?= $mode === 'C' ? 'Cash' : 'Bank' ?></p>
            <?php endif; ?>
          </div>
          
          <div class="table-container">
            <?php if (!empty($report_data) || $opening_balance != 0): ?>
              <table class="collection-table">
                <thead>
                  <tr>
                    <th class="text-center">Voc. Date</th>
                    <th class="text-center">Doc. No.</th>
                    <th class="text-center">Cheq. No.</th>
                    <th>Particulars</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Credit</th>
                    <th class="text-right">Balance</th>
                  </tr>
                </thead>
                <tbody>
                  <!-- Opening Balance -->
                  <tr class="opening-balance-row">
                    <td colspan="4" class="text-end"><strong>Opening Balance:</strong></td>
                    <td colspan="2"></td>
                    <td class="text-right <?= $opening_balance >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                      <?= number_format(abs($opening_balance), 2) ?>
                      <?= $opening_balance >= 0 ? 'Cr' : 'Dr' ?>
                    </td>
                  </tr>
                  
                  <!-- Transactions -->
                  <?php foreach ($report_data as $index => $transaction): ?>
                  <tr>
                    <td class="text-center"><?= date('d/m/Y', strtotime($transaction['date'])) ?></td>
                    <td class="text-center">
                      <?= htmlspecialchars($transaction['doc_no']) ?>
                      <?php if ($transaction['mode']): ?>
                        <span class="badge bg-secondary mode-badge"><?= $transaction['mode'] == 'C' ? 'Cash' : 'Bank' ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <?php if ($transaction['cheq_no']): ?>
                        <?= htmlspecialchars($transaction['cheq_no']) ?>
                        <?php if ($transaction['cheq_date'] && $transaction['cheq_date'] != '0000-00-00'): ?>
                          <br><small><?= date('d/m/Y', strtotime($transaction['cheq_date'])) ?></small>
                        <?php endif; ?>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td>
                      <strong><?= htmlspecialchars($transaction['particulars']) ?></strong>
                      <?php if ($transaction['narration']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($transaction['narration']) ?></small>
                      <?php endif; ?>
                    </td>
                    <td class="text-right"><?= $transaction['debit'] > 0 ? number_format($transaction['debit'], 2) : '-' ?></td>
                    <td class="text-right"><?= $transaction['credit'] > 0 ? number_format($transaction['credit'], 2) : '-' ?></td>
                    <td class="text-right <?= $transaction['balance'] >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                      <?= number_format(abs($transaction['balance']), 2) ?>
                      <?= $transaction['balance'] >= 0 ? 'Cr' : 'Dr' ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance -->
                  <tr class="closing-balance-row">
                    <td colspan="4" class="text-end"><strong>Closing Balance:</strong></td>
                    <td class="text-right"><strong><?= number_format($total_debit, 2) ?></strong></td>
                    <td class="text-right"><strong><?= number_format($total_credit, 2) ?></strong></td>
                    <td class="text-right <?= $closing_balance >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                      <strong>
                        <?= number_format(abs($closing_balance), 2) ?>
                        <?= $closing_balance >= 0 ? 'Cr' : 'Dr' ?>
                      </strong>
                    </td>
                  </tr>
                </tbody>
              </table>
              
            <?php else: ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> No transactions found for the selected criteria.
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