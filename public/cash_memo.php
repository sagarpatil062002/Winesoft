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
require_once 'cash_memo_functions.php'; // Include shared cash memo functions

// Initialize variables
$totalBills = 0;
$totalAmount = 0.00;
$bill_data = [];
$bill_items = [];
$bill_total = 0;
$all_bills = [];
$showPrintSection = false;

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'foreign';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$bill_no = isset($_GET['bill_no']) ? $_GET['bill_no'] : '';

// Check if clear filter is requested
if (isset($_GET['clear_filter'])) {
    header("Location: cash_memo.php");
    exit;
}

// ============================================================================
// AUTO-DISPLAY ALL CASH MEMOS OR SEARCH BY BILL NO
// ============================================================================

// If bill number is provided, search for specific bill
if (!empty($bill_no)) {
    $billQuery = "SELECT DISTINCT cmp.bill_no, sh.BILL_DATE, sh.CUST_CODE, sh.TOTAL_AMOUNT, 
                         sh.DISCOUNT, sh.NET_AMOUNT, sh.LIQ_FLAG,
                         cmp.print_date, cmp.cash_memo_text, cmp.customer_name,
                         cmp.permit_no, cmp.permit_place, cmp.permit_exp_date
                 FROM tbl_cash_memo_prints cmp
                 JOIN tblsaleheader sh ON cmp.bill_no = sh.BILL_NO AND cmp.comp_id = sh.COMP_ID
                 WHERE cmp.comp_id = ? AND cmp.bill_no = ?
                 ORDER BY cmp.print_date DESC
                 LIMIT 1";
    
    $billStmt = $conn->prepare($billQuery);
    $billStmt->bind_param("is", $compID, $bill_no);
    $billStmt->execute();
    $billResult = $billStmt->get_result();
    
    if ($billResult->num_rows > 0) {
        $billData = $billResult->fetch_assoc();
        
        // Get bill details
        $detailsQuery = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, im.DETAILS, im.DETAILS2
                        FROM tblsaledetails sd
                        LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                        WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
        $detailsStmt = $conn->prepare($detailsQuery);
        $detailsStmt->bind_param("si", $bill_no, $compID);
        $detailsStmt->execute();
        $detailsResult = $detailsStmt->get_result();
        
        $items = [];
        while ($itemRow = $detailsResult->fetch_assoc()) {
            $items[] = $itemRow;
        }
        $detailsStmt->close();
        
        // Use permit data from cash memo prints table
        $permitData = null;
        if (!empty($billData['permit_no'])) {
            $permitData = [
                'P_NO' => $billData['permit_no'],
                'PLACE_ISS' => $billData['permit_place'],
                'P_EXP_DT' => $billData['permit_exp_date'],
                'DETAILS' => $billData['customer_name']
            ];
        }
        
        $all_bills[] = [
            'header' => [
                'BILL_NO' => $billData['bill_no'],
                'BILL_DATE' => $billData['BILL_DATE'],
                'CUST_CODE' => $billData['CUST_CODE'],
                'TOTAL_AMOUNT' => $billData['TOTAL_AMOUNT'],
                'NET_AMOUNT' => $billData['NET_AMOUNT'],
                'LIQ_FLAG' => $billData['LIQ_FLAG']
            ],
            'items' => $items,
            'permit' => $permitData,
            'cash_memo_text' => $billData['cash_memo_text']
        ];
        
        $showPrintSection = true;
        $_SESSION['success_message'] = "Found cash memo for bill: " . $bill_no;
    } else {
        $_SESSION['error_message'] = "No cash memo found for bill: " . $bill_no;
    }
    $billStmt->close();
} 
// Otherwise, show all cash memos for the date range
else {
    $cashMemoQuery = "SELECT DISTINCT cmp.bill_no, sh.BILL_DATE, sh.CUST_CODE, sh.TOTAL_AMOUNT, 
                             sh.DISCOUNT, sh.NET_AMOUNT, sh.LIQ_FLAG,
                             cmp.print_date, cmp.cash_memo_text, cmp.customer_name,
                             cmp.permit_no, cmp.permit_place, cmp.permit_exp_date
                     FROM tbl_cash_memo_prints cmp
                     JOIN tblsaleheader sh ON cmp.bill_no = sh.BILL_NO AND cmp.comp_id = sh.COMP_ID
                     WHERE cmp.comp_id = ? AND sh.BILL_DATE BETWEEN ? AND ?
                     ORDER BY sh.BILL_DATE DESC, cmp.bill_no DESC
                     LIMIT 200";
    
    $cashMemoStmt = $conn->prepare($cashMemoQuery);
    $cashMemoStmt->bind_param("iss", $compID, $date_from, $date_to);
    $cashMemoStmt->execute();
    $cashMemoResult = $cashMemoStmt->get_result();
    
    $allBillsData = [];
    while ($row = $cashMemoResult->fetch_assoc()) {
        $allBillsData[] = $row;
    }
    $cashMemoStmt->close();
    
    // If we have cash memos, display them
    if (!empty($allBillsData)) {
        foreach ($allBillsData as $billData) {
            $billNo = $billData['bill_no'];
            
            // Get bill details
            $detailsQuery = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, im.DETAILS, im.DETAILS2
                            FROM tblsaledetails sd
                            LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                            WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
            $detailsStmt = $conn->prepare($detailsQuery);
            $detailsStmt->bind_param("si", $billNo, $compID);
            $detailsStmt->execute();
            $detailsResult = $detailsStmt->get_result();
            
            $items = [];
            while ($itemRow = $detailsResult->fetch_assoc()) {
                $items[] = $itemRow;
            }
            $detailsStmt->close();
            
            // Use permit data from cash memo prints table
            $permitData = null;
            if (!empty($billData['permit_no'])) {
                $permitData = [
                    'P_NO' => $billData['permit_no'],
                    'PLACE_ISS' => $billData['permit_place'],
                    'P_EXP_DT' => $billData['permit_exp_date'],
                    'DETAILS' => $billData['customer_name']
                ];
            }
            
            $all_bills[] = [
                'header' => [
                    'BILL_NO' => $billData['bill_no'],
                    'BILL_DATE' => $billData['BILL_DATE'],
                    'CUST_CODE' => $billData['CUST_CODE'],
                    'TOTAL_AMOUNT' => $billData['TOTAL_AMOUNT'],
                    'NET_AMOUNT' => $billData['NET_AMOUNT'],
                    'LIQ_FLAG' => $billData['LIQ_FLAG']
                ],
                'items' => $items,
                'permit' => $permitData,
                'cash_memo_text' => $billData['cash_memo_text']
            ];
        }
        
        $showPrintSection = true;
        if (count($allBillsData) > 0) {
            $_SESSION['success_message'] = "Displaying " . count($allBillsData) . " cash memo(s) for the selected date range";
        }
    }
}

// Check if we should show the print section
$showPrintSection = $showPrintSection || (isset($_SESSION['show_print_section']) && $_SESSION['show_print_section']);

// If we have stored bills in session, use them for printing
if (isset($_SESSION['all_bills_data']) && !empty($_SESSION['all_bills_data'])) {
    $all_bills = $_SESSION['all_bills_data'];
}

// Fetch company details for display
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

// Get total bills and amount for the date range
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

// Clear print section when new filters are applied
if (isset($_GET['date_from']) || isset($_GET['date_to']) || isset($_GET['bill_no']) || isset($_GET['mode'])) {
    $_SESSION['show_print_section'] = false;
    unset($_SESSION['all_bills_data']);
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
  <!-- Include shortcuts functionality -->
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    /* SCREEN STYLES */
    body {
        font-family: 'Courier New', monospace;
        background-color: #f8f9fa;
    }
    
    .cash-memo-container {
        width: 300px;
        margin: 10px;
        padding: 8px;
        border: 1px solid #000;
        background: white;
        font-size: 12px;
        line-height: 1.2;
        display: inline-block;
        vertical-align: top;
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
        font-size: 11px;
    }
    
    .shop-name {
        font-weight: bold;
        text-transform: uppercase;
        margin-bottom: 2px;
        font-size: 13px;
    }
    
    .shop-address {
        font-size: 10px;
        margin-bottom: 5px;
        line-height: 1.1;
    }
    
    .memo-info {
        display: flex;
        justify-content: space-between;
        margin-bottom: 6px;
        padding-bottom: 3px;
        border-bottom: 1px solid #000;
        font-size: 11px;
    }
    
    .customer-info {
        margin-bottom: 6px;
        font-size: 11px;
    }
    
    .permit-info {
        margin-bottom: 6px;
        font-size: 10px;
        line-height: 1.1;
        border-bottom: 1px solid #000;
        padding-bottom: 3px;
    }
    
    .permit-row {
        display: flex;
        justify-content: space-between;
    }
    
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 6px;
        font-size: 11px;
    }
    
    .items-table td {
        padding: 2px 0;
        vertical-align: top;
        border-bottom: 1px dotted #ccc;
    }
    
    .total-section {
        border-top: 2px solid #000;
        padding-top: 3px;
        text-align: right;
        font-weight: bold;
        margin-bottom: 3px;
        font-size: 12px;
        padding-right: 5px;
    }
    
    .memos-container {
        display: block;
        text-align: center;
        margin: 0 auto;
        width: 100%;
    }
    
    .qty-col {
        width: 15%;
        text-align: center;
    }
    
    .particulars-col {
        width: 40%;
        text-align: left;
        padding-left: 3px;
    }
    
    .size-col {
        width: 25%;
        text-align: center;
    }
    
    .amount-col {
        width: 20%;
        text-align: right;
        padding-right: 5px;
    }
    
    .table-header {
        display: flex;
        justify-content: space-between;
        text-align: center;
        margin-bottom: 3px;
        font-weight: bold;
        font-size: 11px;
        line-height: 1.1;
        border-bottom: 1px solid #000;
        padding-bottom: 2px;
    }
    
    .header-particulars {
        width: 40%;
        text-align: left;
        padding-left: 3px;
    }
    
    .header-qty {
        width: 15%;
    }
    
    .header-size {
        width: 25%;
        text-align: center;
    }
    
    .header-amount {
        width: 20%;
        text-align: right;
        padding-right: 5px;
    }
    
    .no-print {
        margin-bottom: 20px;
    }
    
    .auto-display-info {
        background-color: #d1ecf1;
        border-color: #bee5eb;
        color: #0c5460;
    }

    .search-form {
        transition: all 0.3s ease;
    }
    
    .search-form:focus-within {
        box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        border-radius: 0.375rem;
    }

    /* PRINT STYLES - OPTIMIZED FOR NO WASTED SPACE */
    @media print {
        /* Hide everything except cash memos */
        body * {
            visibility: hidden;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        /* Only show the memos container and its children */
        .memos-container,
        .memos-container * {
            visibility: visible;
        }
        
        /* Reset body for print */
        body {
            background: white !important;
            margin: 0 !important;
            padding: 0 !important;
            font-family: 'Courier New', monospace !important;
            width: 100% !important;
        }
        
        /* A4 page setup - minimal margins */
        @page {
            size: A4 portrait;
            margin: 2mm;
        }
        
        /* Memos container setup */
        .memos-container {
            display: block !important;
            width: 100% !important;
            margin: 0 auto !important;
            padding: 0 !important;
            text-align: center;
        }
        
        /* Cash memo styling for print - optimized for maximum per page */
        .cash-memo-container {
            width: 97mm !important; /* Optimized width for 3 per row */
            height: auto !important;
            margin: 1mm !important; /* Minimal margin */
            padding: 2mm !important;
            border: 1px solid #000 !important;
            background: white !important;
            display: inline-block !important;
            vertical-align: top !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            float: none !important;
            font-size: 9px !important;
            line-height: 1.0 !important;
            box-sizing: border-box !important;
        }
        
        /* Remove all forced page breaks - let browser handle pagination naturally */
        .cash-memo-container {
            page-break-after: auto !important;
            page-break-before: auto !important;
        }
        
        /* Allow page breaks only when necessary */
        .memos-container {
            page-break-inside: auto !important;
        }
        
        /* Header and text sizing for print - optimized */
        .cash-memo-header {
            margin-bottom: 1px !important;
            padding-bottom: 1px !important;
        }
        
        .license-info {
            font-size: 8px !important;
            margin-bottom: 1px !important;
        }
        
        .shop-name {
            font-size: 9px !important;
            margin-bottom: 1px !important;
        }
        
        .shop-address {
            font-size: 7px !important;
            margin-bottom: 2px !important;
            line-height: 1.0 !important;
        }
        
        .memo-info {
            font-size: 8px !important;
            margin-bottom: 2px !important;
            padding-bottom: 1px !important;
        }
        
        .customer-info {
            font-size: 8px !important;
            margin-bottom: 2px !important;
        }
        
        .permit-info {
            font-size: 7px !important;
            margin-bottom: 2px !important;
            padding-bottom: 1px !important;
            line-height: 1.0 !important;
        }
        
        .items-table {
            font-size: 8px !important;
            margin-bottom: 2px !important;
        }
        
        .items-table td {
            padding: 1px 0 !important;
            line-height: 1.0 !important;
        }
        
        .total-section {
            font-size: 9px !important;
            margin-bottom: 1px !important;
            padding-top: 1px !important;
        }
        
        /* Remove all shadows, backgrounds, etc. */
        .cash-memo-container {
            box-shadow: none !important;
            background: white !important;
        }
        
        /* Ensure no blank pages */
        .cash-memo-container:last-child {
            page-break-after: avoid !important;
        }
    }
    
    /* Screen responsive styles */
    @media screen and (min-width: 1200px) {
        .cash-memo-container {
            width: 45%;
            margin: 10px 2.5%;
        }
    }
    
    @media screen and (max-width: 1199px) and (min-width: 768px) {
        .cash-memo-container {
            width: 45%;
            margin: 10px 2.5%;
        }
    }
    
    @media screen and (max-width: 767px) {
        .cash-memo-container {
            width: 100%;
            margin: 10px 0;
        }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Cash Memo Printing</h3>

      <!-- Display success/error messages -->
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
          <i class="fas fa-check-circle me-2"></i>
          <?= $_SESSION['success_message'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>

      <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
          <i class="fas fa-exclamation-triangle me-2"></i>
          <?= $_SESSION['error_message'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
      <?php endif; ?>

      <!-- Cash Memo Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Cash Memo Search</div>
        <div class="card-body">
          <form method="GET" class="report-filters search-form" id="cashMemoForm">
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
                <label class="form-label">Bill No:</label>
                <input type="text" name="bill_no" class="form-control" value="<?= htmlspecialchars($bill_no) ?>" 
                       placeholder="Enter bill number and press Enter" id="billNoInput">
              </div>
            </div>
            
            <div class="action-controls">
              <?php if ($showPrintSection && !empty($all_bills)): ?>
              <button type="button" class="btn btn-success" id="printButton">
                <i class="fas fa-print me-1"></i> Print All Cash Memos
              </button>
              <?php endif; ?>
              
              <?php if (!empty($bill_no)): ?>
              <a href="?clear_filter=1" class="btn btn-warning">
                <i class="fas fa-times me-1"></i> Clear Filter
              </a>
              <?php endif; ?>
              
              
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
          <?php if ($showPrintSection && !empty($all_bills)): ?>
          <div class="row mt-2">
            <div class="col-12">
              <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle me-2"></i>
                <strong>Displaying:</strong> <?= count($all_bills) ?> cash memo(s) 
                <?php if (!empty($bill_no)): ?>
                  for bill: <?= htmlspecialchars($bill_no) ?>
                <?php else: ?>
                  for date range: <?= date('d-M-Y', strtotime($date_from)) ?> to <?= date('d-M-Y', strtotime($date_to)) ?>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Cash Memo Display -->
      <?php if ($showPrintSection): ?>
        <?php if (isset($all_bills) && !empty($all_bills)): ?>
          <div class="memos-container">
            <?php foreach ($all_bills as $index => $bill): ?>
              <div class="cash-memo-container">
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
                  <div class="header-size">Size</div>
                  <div class="header-amount">Amount</div>
                </div>
                
                <table class="items-table">
                  <tbody>
                    <?php if (!empty($bill['items'])): ?>
                      <?php foreach ($bill['items'] as $item): ?>
                      <tr>
                        <td class="particulars-col"><?= htmlspecialchars($item['DETAILS']) ?></td>
                        <td class="qty-col"><?= htmlspecialchars($item['QTY']) ?></td>
                        <td class="size-col"><?= !empty($item['DETAILS2']) ? htmlspecialchars($item['DETAILS2']) : '' ?></td>
                        <td class="amount-col"><?= number_format($item['AMOUNT'], 2) ?></td>
                      </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr>
                        <td colspan="4" style="text-align: center;">No items found</td>
                      </tr>
                    <?php endif; ?>
                  </tbody>
                </table>
                
                <div class="total-section">
                  Total: ₹<?= number_format($bill['header']['NET_AMOUNT'], 2) ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="alert alert-warning no-print">
            <i class="fas fa-exclamation-triangle me-2"></i> No cash memos found for the selected criteria.
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="alert alert-info no-print">
          <i class="fas fa-info-circle me-2"></i> 
          Select a date range or enter a bill number to view cash memos. Cash memos are automatically generated from sales.
        </div>
      <?php endif; ?>
    </div>
    
  <?php include 'components/footer.php'; ?>
  </div>
  
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Handle Enter key in bill number field
    $('#billNoInput').on('keypress', function(e) {
        if (e.which === 13) { // Enter key
            e.preventDefault();
            $('#cashMemoForm').submit();
        }
    });
    
    // Clear print section when filters are changed
    $('#cashMemoForm select, #cashMemoForm input').on('change', function() {
        $('.memos-container').hide();
        $('.alert-info, .alert-warning').hide();
    });
    
    // Enhanced print functionality
    $('#printButton').on('click', function() {
        // Add a small delay to ensure everything is rendered
        setTimeout(function() {
            window.print();
        }, 500);
    });
    
    // Auto-focus on bill number field if it has value
    if ($('#billNoInput').val()) {
        $('#billNoInput').focus();
    }
    
    // Debug: Log how many cash memos are visible
    console.log('Cash memos displayed: ', $('.cash-memo-container').length);
});
</script>
</body>
</html>