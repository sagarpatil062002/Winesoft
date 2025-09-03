<?php
// Start session at the very beginning
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

// Initialize variables to prevent undefined variable warnings
$totalBills = 0;
$totalAmount = 0.00;
$bill_data = [];
$bill_items = [];
$bill_total = 0;
$all_bills = [];

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'foreign';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$bill_no = isset($_GET['bill_no']) ? $_GET['bill_no'] : '';

// Handle printing and storing cash memos when Generate is clicked
if (isset($_GET['generate'])) {
    $print_date = date('Y-m-d H:i:s');
    $bill_numbers_to_store = [];
    
    // If specific bill number is provided
    if (!empty($bill_no)) {
        // Get bill header data
        $billQuery = "SELECT BILL_NO, BILL_DATE, CUST_CODE, TOTAL_AMOUNT, 
                             DISCOUNT, NET_AMOUNT, LIQ_FLAG
                      FROM tblsaleheader 
                      WHERE BILL_NO = ? AND COMP_ID = ?";
        $billStmt = $conn->prepare($billQuery);
        $billStmt->bind_param("si", $bill_no, $compID);
        $billStmt->execute();
        $billResult = $billStmt->get_result();
        
        if ($billResult->num_rows > 0) {
            $bill_data = $billResult->fetch_assoc();
            $bill_numbers_to_store[] = $bill_no;
            
            // Get bill details
            $detailsQuery = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, im.DETAILS, im.DETAILS2
                             FROM tblsaledetails sd
                             LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                             WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
            $detailsStmt = $conn->prepare($detailsQuery);
            $detailsStmt->bind_param("si", $bill_no, $compID);
            $detailsStmt->execute();
            $detailsResult = $detailsStmt->get_result();
            
            while ($row = $detailsResult->fetch_assoc()) {
                $bill_items[] = $row;
            }
            $detailsStmt->close();
            
            $bill_total = $bill_data['NET_AMOUNT'] ?? 0;
        }
        $billStmt->close();
    } 
    // If no specific bill number, get all bills for the date range
    else {
        // Get all bills for the date range
        $billsQuery = "SELECT BILL_NO, BILL_DATE, CUST_CODE, TOTAL_AMOUNT, 
                              DISCOUNT, NET_AMOUNT, LIQ_FLAG
                       FROM tblsaleheader 
                       WHERE BILL_DATE BETWEEN ? AND ? AND COMP_ID = ?
                       ORDER BY BILL_DATE, BILL_NO";
        $billsStmt = $conn->prepare($billsQuery);
        $billsStmt->bind_param("ssi", $date_from, $date_to, $compID);
        $billsStmt->execute();
        $billsResult = $billsStmt->get_result();
        
        while ($row = $billsResult->fetch_assoc()) {
            $bill_numbers_to_store[] = $row['BILL_NO'];
            
            // Get bill details for each bill
            $detailsQuery = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, im.DETAILS, im.DETAILS2
                             FROM tblsaledetails sd
                             LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                             WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
            $detailsStmt = $conn->prepare($detailsQuery);
            $detailsStmt->bind_param("si", $row['BILL_NO'], $compID);
            $detailsStmt->execute();
            $detailsResult = $detailsStmt->get_result();
            
            $items = [];
            while ($itemRow = $detailsResult->fetch_assoc()) {
                $items[] = $itemRow;
            }
            $detailsStmt->close();
            
            $all_bills[] = [
                'header' => $row,
                'items' => $items
            ];
        }
        $billsStmt->close();
    }
    
    // Store all bill numbers in the database
    if (!empty($bill_numbers_to_store)) {
        foreach ($bill_numbers_to_store as $bill_number) {
            // Check if this bill has already been printed today
            $checkQuery = "SELECT id FROM tbl_cash_memo_prints 
                           WHERE bill_no = ? AND comp_id = ? AND DATE(print_date) = CURDATE()";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("si", $bill_number, $compID);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            // Only store if not already printed today
            if ($checkResult->num_rows == 0) {
                $insertQuery = "INSERT INTO tbl_cash_memo_prints (bill_no, comp_id, print_date, printed_by) 
                                VALUES (?, ?, ?, ?)";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("sisi", $bill_number, $compID, $print_date, $_SESSION['user_id']);
                $insertStmt->execute();
                $insertStmt->close();
            }
            $checkStmt->close();
        }
        
        // Set success message
        $_SESSION['success_message'] = count($bill_numbers_to_store) . " cash memo(s) generated and stored successfully!";
        
        // Auto-print after generation
        echo '<script>window.onload = function() { window.print(); };</script>';
    }
}

// Fetch company details including license information
$companyName = "DIAMOND WINE SHOP";
$companyAddress = "Ishvanbag Sangli Tal Hiraj Dist Sangli";
$licenseNumber = "";
$companyAddress2 = "";

$companyQuery = "SELECT COMP_NAME, COMP_ADDR, COMP_FLNO, CF_LINE, CS_LINE FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
    $companyAddress = $row['COMP_ADDR'] ?? $companyAddress;
    $licenseNumber = $row['COMP_FLNO'] ?? "";
    $companyAddress2 = $row['CF_LINE'] ?? "";
    if (!empty($row['CS_LINE'])) {
        $companyAddress2 .= (!empty($companyAddress2) ? " " : "") . $row['CS_LINE'];
    }
}
$companyStmt->close();

// Fetch all available permit numbers with customer names
$permitQuery = "SELECT P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, DETAILS FROM tblpermit WHERE P_NO IS NOT NULL AND P_NO != ''";
$permitResult = $conn->query($permitQuery);
$allPermits = [];
if ($permitResult) {
    while ($row = $permitResult->fetch_assoc()) {
        $allPermits[] = $row;
    }
}

// Function to get a random permit
function getRandomPermit($permits) {
    if (empty($permits)) {
        return null;
    }
    return $permits[array_rand($permits)];
}

// Assign random permits to bills if available
if (!empty($allPermits)) {
    if (!empty($bill_data)) {
        $bill_data['permit'] = getRandomPermit($allPermits);
    }
    
    foreach ($all_bills as &$bill) {
        $bill['permit'] = getRandomPermit($allPermits);
    }
}

// Get total bills and amount for the date range (only if not already fetched)
if (!isset($_GET['generate']) || empty($bill_no)) {
    $summaryQuery = "SELECT COUNT(*) as total_bills, SUM(NET_AMOUNT) as total_amount
                     FROM tblsaleheader 
                     WHERE BILL_DATE BETWEEN ? AND ? AND COMP_ID = ?";
    $summaryStmt = $conn->prepare($summaryQuery);
    $summaryStmt->bind_param("ssi", $date_from, $date_to, $compID);
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();

    if ($row = $summaryResult->fetch_assoc()) {
        $totalBills = $row['total_bills'];
        $totalAmount = $row['total_amount'] ?? 0.00;
    }
    $summaryStmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cash Memo Printing - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <style>
    body {
        font-family: 'Courier New', monospace;
        background-color: #f8f9fa;
    }
    
    .cash-memo-container {
        width: 350px;
        margin: 20px auto;
        padding: 5px;
        border: 1px solid #000;
        background: white;
        page-break-inside: avoid;
        font-size: 14px;
        line-height: 1.1;
    }
    
    .cash-memo-header {
        text-align: center;
        margin-bottom: 5px;
        padding-bottom: 3px;
        border-bottom: 1px solid #000;
    }
    
    .license-info {
        text-align: center;
        font-weight: bold;
        margin-bottom: 3px;
        font-size: 14px;
        letter-spacing: 0.5px;
    }
    
    .shop-name {
        font-weight: bold;
        text-transform: uppercase;
        margin-bottom: 2px;
        font-size: 16px;
        letter-spacing: 0.5px;
    }
    
    .shop-address {
        font-size: 12px;
        margin-bottom: 5px;
        line-height: 1.1;
    }
    
    .memo-info {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        padding-bottom: 3px;
        border-bottom: 1px solid #000;
        font-size: 13px;
    }
    
    .customer-info {
        margin-bottom: 8px;
        font-size: 13px;
    }
    
    .permit-info {
        margin-bottom: 8px;
        font-size: 12px;
        line-height: 1.1;
        border-bottom: 1px solid #000;
        padding-bottom: 5px;
    }
    
    .permit-row {
        display: flex;
        justify-content: space-between;
    }
    
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 8px;
        font-size: 13px;
    }
    
    .items-table td {
        padding: 2px 0;
        vertical-align: top;
    }
    
    .total-section {
        border-top: 1px solid #000;
        padding-top: 5px;
        text-align: right;
        font-weight: bold;
        margin-bottom: 5px;
        font-size: 14px;
        padding-right: 10px;
    }
    
    .memos-container {
        display: flex;
        flex-wrap: wrap;
        gap: 15px;
        justify-content: center;
    }
    
    .qty-col {
        width: 15%;
        text-align: center;
    }
    
    .particulars-col {
        width: 55%;
        text-align: left;
        padding-left: 5px;
    }
    
    .amount-col {
        width: 30%;
        text-align: right;
        padding-right: 10px;
    }
    
    .table-header {
        display: flex;
        justify-content: space-between;
        text-align: center;
        margin-bottom: 5px;
        font-weight: bold;
        font-size: 13px;
        line-height: 1.1;
        border-bottom: 1px solid #000;
        padding-bottom: 3px;
    }
    
    .header-qty {
        width: 15%;
    }
    
    .header-particulars {
        width: 55%;
        text-align: left;
        padding-left: 5px;
    }
    
    .header-amount {
        width: 30%;
        text-align: right;
        padding-right: 10px;
    }
    
    .bill-no {
        font-weight: bold;
        font-size: 13px;
    }
    
    .no-print {
        margin-bottom: 20px;
    }
    
    .print-controls {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
        border: 1px solid #dee2e6;
    }
    
    .bill-checkbox {
        margin-right: 5px;
    }
    
    @media print {
        .no-print {
            display: none !important;
        }
        
        body, html {
            background: white;
            margin: 0;
            padding: 0;
            width: 100%;
        }
        
        .cash-memo-container {
            border: 1px solid #000;
            margin: 5mm;
            padding: 2mm;
            page-break-inside: avoid;
            break-inside: avoid;
        }
        
        .memos-container {
            display: block;
        }
        
        .content-area {
            padding: 0 !important;
            margin: 0 !important;
        }
        
        .alert {
            display: none !important;
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
      <h3 class="mb-4">Cash Memo Printing</h3>

      <!-- Display success message if set -->
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
          <?= $_SESSION['success_message'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>

      <!-- Cash Memo Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Cash Memo Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-select">
                  <option value="foreign" <?= $mode === 'foreign' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="country" <?= $mode === 'country' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Date From:</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date To:</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Bill No (Optional):</label>
                <input type="text" name="bill_no" class="form-control" value="<?= htmlspecialchars($bill_no) ?>" placeholder="Leave empty for all bills">
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate & Print
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Again
              </button>
              <a href="dashboard.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-times me-1"></i> Exit
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Summary Information -->
      <div class="card mb-4 no-print">
        <div class="card-header">Summary</div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-6">
              <strong>Total Bills for Date(s):</strong> <?= $totalBills ?>
            </div>
            <div class="col-md-6">
              <strong>Total Bill Amount for Date(s):</strong> ₹<?= number_format($totalAmount, 2) ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Cash Memo Display -->
      <?php if (!empty($bill_data)): ?>
        <div class="memos-container">
          <div class="cash-memo-container print-section">
            <div class="license-info">
              <?= !empty($licenseNumber) ? htmlspecialchars($licenseNumber) : "FL-II 3" ?>
            </div>
            
            <div class="cash-memo-header">
              <div class="shop-name"><?= htmlspecialchars($companyName) ?></div>
              <div class="shop-address"><?= !empty($companyAddress) ? htmlspecialchars($companyAddress) : "Vishrambag Sangli Tal Miraj Dist Sangli" ?></div>
            </div>
            
            <div class="memo-info">
              <div>No : <?= substr($bill_data['BILL_NO'], -5) ?></div>
              <div>CASH MEMO</div>
              <div>Date: <?= date('d/m/Y', strtotime($bill_data['BILL_DATE'])) ?></div>
            </div>
            
            <div class="customer-info">
              <div>Name: 
                <?php if (!empty($bill_data['permit']) && !empty($bill_data['permit']['DETAILS'])): ?>
                  <?= htmlspecialchars($bill_data['permit']['DETAILS']) ?>
                <?php else: ?>
                  <?= (!empty($bill_data['CUST_CODE']) && $bill_data['CUST_CODE'] != 'RETAIL') ? htmlspecialchars($bill_data['CUST_CODE']) : 'A.N. PARAB' ?>
                <?php endif; ?>
              </div>
              
              <?php if (!empty($bill_data['permit'])): ?>
              <div class="permit-info">
                <div class="permit-row">
                  <div>Permit No.: <?= htmlspecialchars($bill_data['permit']['P_NO']) ?></div>
                  <div>Exp.Dt.: <?= !empty($bill_data['permit']['P_EXP_DT']) ? date('d/m/Y', strtotime($bill_data['permit']['P_EXP_DT'])) : '04/11/2026' ?></div>
                </div>
                <div class="permit-row">
                  <div>Place: <?= htmlspecialchars($bill_data['permit']['PLACE_ISS'] ?? 'Sangli') ?></div>
                  <div></div>
                </div>
              </div>
              <?php endif; ?>
            </div>
            
            <div class="table-header">
              <div class="header-particulars">Particulars</div>
              <div class="header-qty">Qty</div>
              <div class="header-amount">Rs./Ps.</div>
            </div>
            
            <table class="items-table">
              <tbody>
                <?php foreach ($bill_items as $item): ?>
                <tr>
                  <td class="particulars-col"><?= htmlspecialchars($item['DETAILS']) ?></td>
                  <td class="qty-col"><?= htmlspecialchars($item['QTY']) ?></td>
                  <td class="amount-col"><?= number_format($item['AMOUNT'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            
            <div class="total-section">
              <?= number_format($bill_total, 2) ?>
            </div>
          </div>
        </div>
      <?php elseif (isset($all_bills) && !empty($all_bills)): ?>
        <div class="memos-container">
          <?php foreach ($all_bills as $bill): ?>
            <div class="cash-memo-container print-section">
              <div class="license-info">
                <?= !empty($licenseNumber) ? htmlspecialchars($licenseNumber) : "FL-II 3" ?>
              </div>
              
              <div class="cash-memo-header">
                <div class="shop-name"><?= htmlspecialchars($companyName) ?></div>
                <div class="shop-address"><?= !empty($companyAddress) ? htmlspecialchars($companyAddress) : "Vishrambag Sangli Tal Miraj Dist Sangli" ?></div>
              </div>
              
              <div class="memo-info">
                <div>No : <?= substr($bill['header']['BILL_NO'], -5) ?></div>
                <div>CASH MEMO</div>
                <div>Date: <?= date('d/m/Y', strtotime($bill['header']['BILL_DATE'])) ?></div>
              </div>
              
              <div class="customer-info">
                <div>Name: 
                  <?php if (!empty($bill['permit']) && !empty($bill['permit']['DETAILS'])): ?>
                    <?= htmlspecialchars($bill['permit']['DETAILS']) ?>
                  <?php else: ?>
                    <?= (!empty($bill['header']['CUST_CODE']) && $bill['header']['CUST_CODE'] != 'RETAIL') ? htmlspecialchars($bill['header']['CUST_CODE']) : 'A.N. PARAB' ?>
                  <?php endif; ?>
                </div>
                
                <?php if (!empty($bill['permit'])): ?>
                <div class="permit-info">
                  <div class="permit-row">
                    <div>Permit No.: <?= htmlspecialchars($bill['permit']['P_NO']) ?></div>
                    <div>Exp.Dt.: <?= !empty($bill['permit']['P_EXP_DT']) ? date('d/m/Y', strtotime($bill['permit']['P_EXP_DT'])) : '04/11/2026' ?></div>
                  </div>
                  <div class="permit-row">
                    <div>Place: <?= htmlspecialchars($bill['permit']['PLACE_ISS'] ?? 'Sangli') ?></div>
                    <div></div>
                  </div>
                </div>
                <?php endif; ?>
              </div>
              
              <div class="table-header">
                <div class="header-particulars">Particulars</div>
                <div class="header-qty">Qty</div>
                <div class="header-amount">Rs./Ps.</div>
              </div>
              
              <table class="items-table">
                <tbody>
                  <?php foreach ($bill['items'] as $item): ?>
                  <tr>
                    <td class="particulars-col"><?= htmlspecialchars($item['DETAILS']) ?></td>
                    <td class="qty-col"><?= htmlspecialchars($item['QTY']) ?></td>
                    <td class="amount-col"><?= number_format($item['AMOUNT'], 2) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              
              <div class="total-section">
                <?= number_format($bill['header']['NET_AMOUNT'], 2) ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php elseif (isset($_GET['generate']) && empty($bill_no)): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No bills found for the selected date range.
        </div>
      <?php elseif (isset($_GET['generate']) && !empty($bill_no)): ?>
        <div class="alert alert-warning">
          <i class="fas fa-exclamation-triangle me-2"></i> No bill found with number: <?= htmlspecialchars($bill_no) ?>
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