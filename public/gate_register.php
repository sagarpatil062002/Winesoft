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
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Validate date range
if (strtotime($from_date) > strtotime($to_date)) {
    $from_date = $to_date;
}

// Fetch company name
$companyName = "DIAMOND WINE SHOP";
$licenseNo = "3";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
    $licenseNo = $row['COMP_FLNO'] ? $row['COMP_FLNO'] : $licenseNo;
}
$companyStmt->close();

// Fetch gate register data from tbl_cash_memo_prints
$transactions = [];
$daily_totals = [];
$grand_total = 0;
$bill_count = 0;

// Query to fetch gate register data from cash memo prints table
$query = "
    SELECT 
        DATE_FORMAT(c.bill_date, '%d/%m/%Y') as formatted_date,
        c.bill_no,
        c.permit_no,
        c.permit_place as place_of_issue,
        c.items_json,
        c.total_amount as amount,
        c.customer_name,
        c.bill_date as original_date
    FROM tbl_cash_memo_prints c
    WHERE c.bill_date BETWEEN ? AND ? 
    AND c.comp_id = ?
    ORDER BY c.bill_date, c.bill_no
";

$stmt = $conn->prepare($query);
$stmt->bind_param("ssi", $from_date, $to_date, $compID);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Decode the items JSON to get item details
    $items = json_decode($row['items_json'], true);
    
    if (is_array($items) && count($items) > 0) {
        // For each item in the bill, create a transaction record
        foreach ($items as $item) {
            $transaction = [
                'formatted_date' => $row['formatted_date'],
                'bill_no' => $row['bill_no'],
                'permit_no' => $row['permit_no'] ? $row['permit_no'] : 'N/A',
                'place_of_issue' => $row['place_of_issue'] ? $row['place_of_issue'] : 'N/A',
                'item_code' => isset($item['code']) ? $item['code'] : 'N/A',
                'item_details' => isset($item['description']) ? $item['description'] : 'N/A',
                'amount' => isset($item['amount']) ? $item['amount'] : 0,
                'customer_name' => $row['customer_name'],
                'original_date' => $row['original_date']
            ];
            
            $transactions[] = $transaction;
            
            // Calculate daily totals
            $date = $row['original_date'];
            if (!isset($daily_totals[$date])) {
                $daily_totals[$date] = 0;
            }
            $daily_totals[$date] += $transaction['amount'];
            
            $grand_total += $transaction['amount'];
        }
        $bill_count++;
    } else {
        // If no items JSON or empty, create a single transaction for the bill
        $transaction = [
            'formatted_date' => $row['formatted_date'],
            'bill_no' => $row['bill_no'],
            'permit_no' => $row['permit_no'] ? $row['permit_no'] : 'N/A',
            'place_of_issue' => $row['place_of_issue'] ? $row['place_of_issue'] : 'N/A',
            'item_code' => 'N/A',
            'item_details' => 'Bill Total',
            'amount' => $row['amount'],
            'customer_name' => $row['customer_name'],
            'original_date' => $row['original_date']
        ];
        
        $transactions[] = $transaction;
        
        // Calculate daily totals
        $date = $row['original_date'];
        if (!isset($daily_totals[$date])) {
            $daily_totals[$date] = 0;
        }
        $daily_totals[$date] += $transaction['amount'];
        
        $grand_total += $transaction['amount'];
        $bill_count++;
    }
}

$stmt->close();

// Generate dates array for the range
$dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gate Register Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  
  <style>
    /* Screen styles */
    body {
      font-size: 12px;
      background-color: #f8f9fa;
    }
    .company-header {
      text-align: center;
      margin-bottom: 15px;
      padding: 10px;
    }
    .company-header h1 {
      font-size: 18px;
      font-weight: bold;
      margin-bottom: 5px;
    }
    .company-header h5 {
      font-size: 14px;
      margin-bottom: 3px;
    }
    .company-header h6 {
      font-size: 12px;
      margin-bottom: 5px;
    }
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 15px;
      font-size: 10px;
    }
    .report-table th, .report-table td {
      border: 1px solid #000;
      padding: 4px;
      text-align: center;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.2;
    }
    .report-table th {
      background-color: #f0f0f0;
      font-weight: bold;
      padding: 6px 3px;
    }
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
    }
    .filter-card {
      background-color: #f8f9fa;
    }
    .table-responsive {
      overflow-x: auto;
      max-width: 100%;
    }
    .action-controls {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .no-print {
      display: block;
    }
    .footer-signature {
      margin-top: 30px;
      width: 100%;
    }
    .footer-signature table {
      width: 100%;
      border: none;
    }
    .footer-signature td {
      border: none;
      padding: 5px;
      text-align: center;
      width: 33%;
    }
    .dotted-line {
      border-bottom: 1px dashed #000;
      width: 80%;
      margin: 0 auto;
      padding-top: 20px;
    }
    .separator {
      border-top: 2px solid #000;
      margin: 10px 0;
    }

    /* Print styles */
    @media print {
      @page {
        size: legal portrait;
        margin: 0.2in;
      }
      
      body {
        margin: 0;
        padding: 0;
        font-size: 10px;
        line-height: 1;
        background: white;
        width: 100%;
        height: 100%;
      }
      
      .no-print {
        display: none !important;
      }
      
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
        height: 100%;
        margin: 0;
        padding: 0;
      }
      
      .company-header {
        text-align: center;
        margin-bottom: 10px;
        padding: 2px;
        page-break-after: avoid;
      }
      
      .company-header h1 {
        font-size: 16px !important;
        margin-bottom: 1px !important;
      }
      
      .company-header h5 {
        font-size: 12px !important;
        margin-bottom: 1px !important;
      }
      
      .company-header h6 {
        font-size: 10px !important;
        margin-bottom: 2px !important;
      }
      
      .table-responsive {
        overflow: visible;
        width: 100%;
        height: auto;
      }
      
      .report-table {
        width: 100% !important;
        font-size: 9px !important;
        table-layout: fixed;
        border-collapse: collapse;
        page-break-inside: avoid;
      }
      
      .report-table th, .report-table td {
        padding: 2px !important;
        line-height: 1;
        font-size: 9px !important;
        border: 1px solid #000 !important;
      }
      
      .report-table th {
        background-color: #f0f0f0 !important;
        padding: 3px 2px !important;
        font-weight: bold;
      }
      
      .summary-row {
        background-color: #f8f9fa !important;
        font-weight: bold;
      }
      
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 9px;
        page-break-before: avoid;
      }
      
      .footer-signature {
        margin-top: 20px;
        font-size: 9px;
      }
      
      tr {
        page-break-inside: avoid;
        page-break-after: auto;
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
      <h3 class="mb-4">Gate Register Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-control">
                  <option value="Foreign Liquor" <?= $mode == 'Foreign Liquor' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="Country Liquor" <?= $mode == 'Country Liquor' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">From Date:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>" max="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>" max="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date Range Info:</label>
                <div class="form-control-plaintext">
                  <small class="text-muted">Selected: <?= count($dates) ?> day(s)</small>
                </div>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate Report
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
              </button>
              <button type="button" class="btn btn-info" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-1"></i> Export to Excel
              </button>
              <a href="dashboard.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-times me-1"></i> Exit
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Report Results -->
      <div class="print-section">
        <div class="company-header">
          <h1>GATE REGISTER REPORT</h1>
          <h5>Mode: <?= htmlspecialchars($mode) ?></h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>Date From : <?= date('d/m/Y', strtotime($from_date)) ?> Date To : <?= date('d/m/Y', strtotime($to_date)) ?></h6>
        </div>
        
        <?php if (empty($transactions)): ?>
          <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No data available for the selected date range.
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="report-table" id="gate-register-table">
              <thead>
                <tr>
                  <th width="10%">Date</th>
                  <th width="10%">Bill No.</th>
                  <th width="12%">Permit No.</th>
                  <th width="15%">Place of Issue</th>
                  <th width="10%">Item Code</th>
                  <th width="23%">Details</th>
                  <th width="10%">Amount (₹)</th>
                  <th width="10%">Status</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $current_date = null;
                foreach ($transactions as $index => $transaction): 
                  $date = $transaction['original_date'];
                  
                  // Display daily subtotal if we're on a new date
                  if ($current_date !== null && $current_date !== $date) {
                    echo '<tr class="summary-row">';
                    echo '<td colspan="5">' . date('d/m/Y', strtotime($current_date)) . ' Total</td>';
                    echo '<td colspan="2">₹' . number_format($daily_totals[$current_date], 2) . '</td>';
                    echo '<td></td>';
                    echo '</tr>';
                    echo '<tr><td colspan="8" class="separator"></td></tr>';
                  }
                  
                  $current_date = $date;
                ?>
                  <tr>
                    <td><?= $transaction['formatted_date'] ?></td>
                    <td><?= $transaction['bill_no'] ?></td>
                    <td><?= $transaction['permit_no'] ?></td>
                    <td><?= $transaction['place_of_issue'] ?></td>
                    <td><?= $transaction['item_code'] ?></td>
                    <td><?= $transaction['item_details'] ?></td>
                    <td>₹<?= number_format($transaction['amount'], 2) ?></td>
                    <td>Issued</td>
                  </tr>
                <?php endforeach; ?>
                
                <!-- Display final daily total -->
                <?php if ($current_date !== null): ?>
                  <tr class="summary-row">
                    <td colspan="5"><?= date('d/m/Y', strtotime($current_date)) ?> Total</td>
                    <td colspan="2">₹<?= number_format($daily_totals[$current_date], 2) ?></td>
                    <td></td>
                  </tr>
                <?php endif; ?>
                
                <!-- Grand Total -->
                <tr><td colspan="8" class="separator"></td></tr>
                <tr class="summary-row">
                  <td colspan="5">Total No. of Bills: <?= $bill_count ?></td>
                  <td colspan="2">Grand Total: ₹<?= number_format($grand_total, 2) ?></td>
                  <td></td>
                </tr>
              </tbody>
            </table>
          </div>
          
          <!-- Signature Section -->
          <div class="footer-signature">
            <table>
              <tr>
                <td>
                  <div class="dotted-line"></div>
                  <div>Prepared By</div>
                </td>
                <td>
                  <div class="dotted-line"></div>
                  <div>Checked By</div>
                </td>
                <td>
                  <div class="dotted-line"></div>
                  <div>Signature</div>
                </td>
              </tr>
            </table>
          </div>
          
          <div class="footer-info">
            <p>Generated on: <?= date('d-M-Y H:i:s') ?> | Total Records: <?= count($transactions) ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportToExcel() {
  // Get the current date range for filename
  const fromDate = "<?= $from_date ?>";
  const toDate = "<?= $to_date ?>";
  const mode = "<?= $mode ?>";
  
  // Create a filename
  const filename = `Gate_Register_${mode}_${fromDate}_to_${toDate}.xlsx`;
  
  // Create a temporary table for export
  const originalTable = document.getElementById('gate-register-table');
  const tempTable = originalTable.cloneNode(true);
  
  // Create HTML content for export
  const htmlContent = `
    <html>
    <head>
      <meta charset="UTF-8">
      <style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid black; padding: 4px; text-align: center; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .summary-row { background-color: #e9ecef; font-weight: bold; }
        .separator { border-top: 2px solid #000; }
      </style>
    </head>
    <body>
      <h2>Gate Register Report</h2>
      <h3>Mode: ${mode}</h3>
      <h4><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h4>
      <h4>Date From: <?= date('d/m/Y', strtotime($from_date)) ?> Date To: <?= date('d/m/Y', strtotime($to_date)) ?></h4>
      ${tempTable.outerHTML}
      <p>Generated on: <?= date('d-M-Y H:i:s') ?></p>
    </body>
    </html>
  `;
  
  // Create blob and download
  const blob = new Blob([htmlContent], { type: 'application/vnd.ms-excel' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}
</script>
</body>
</html>