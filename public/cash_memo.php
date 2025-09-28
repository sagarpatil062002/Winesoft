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
$showPrintSection = false;

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'foreign';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$bill_no = isset($_GET['bill_no']) ? $_GET['bill_no'] : '';

// Function to generate cash memo text exactly as shown in image
function generateCashMemoText($companyData, $billData, $billItems, $permitData) {
    $text = "";
    
    // License info - centered
    $license = !empty($companyData['licenseNumber']) ? $companyData['licenseNumber'] : "FL-II 3";
    $text .= str_pad($license, 40, " ", STR_PAD_BOTH) . "\n";
    
    // Shop name and address - centered
    $text .= str_pad($companyData['name'], 40, " ", STR_PAD_BOTH) . "\n";
    $text .= str_pad($companyData['address'], 40, " ", STR_PAD_BOTH) . "\n\n";
    
    // Bill number and date
    $billNoShort = substr($billData['BILL_NO'], -5);
    $billDate = date('d/m/Y', strtotime($billData['BILL_DATE']));
    $text .= "No : " . $billNoShort . str_repeat(" ", 10) . "CASH MEMO" . str_repeat(" ", 10) . "Date: " . $billDate . "\n\n";
    
    // Customer name
    $customerName = 'A.N. PARAB'; // Default
    if (!empty($permitData) && !empty($permitData['DETAILS'])) {
        $customerName = $permitData['DETAILS'];
    } elseif (!empty($billData['CUST_CODE']) && $billData['CUST_CODE'] != 'RETAIL') {
        $customerName = $billData['CUST_CODE'];
    }
    $text .= "Name: " . $customerName . "\n";
    
    // Permit information
    if (!empty($permitData)) {
        $permitNo = $permitData['P_NO'] ?? '';
        $permitPlace = $permitData['PLACE_ISS'] ?? 'SANGLI';
        $permitExpDate = !empty($permitData['P_EXP_DT']) ? date('d/m/Y', strtotime($permitData['P_EXP_DT'])) : '04/11/2026';
        
        $text .= "Permit No.: " . $permitNo . "\n";
        $text .= "Place: " . $permitPlace . str_repeat(" ", 15) . "Exp.Dt.: " . $permitExpDate . "\n";
    }
    $text .= "\n";
    
    // Table header
    $text .= str_pad("Particulars", 30) . str_pad("Qty", 10) . str_pad("Size", 15) . str_pad("Amount", 10) . "\n";
    $text .= str_repeat("-", 65) . "\n";
    
    // Items
    foreach ($billItems as $item) {
        $particulars = substr($item['DETAILS'] ?? '', 0, 30);
        $qty = number_format($item['QTY'], 3);
        $size = substr($item['DETAILS2'] ?? '', 0, 15);
        $amount = number_format($item['AMOUNT'], 2);
        
        $text .= str_pad($particulars, 30);
        $text .= str_pad($qty, 10);
        $text .= str_pad($size, 15);
        $text .= str_pad($amount, 10) . "\n";
    }
    
    $text .= "\n";
    $text .= str_repeat(" ", 45) . "Total: ₹" . number_format($billData['NET_AMOUNT'], 2) . "\n";
    
    return $text;
}

// Function to save complete cash memo data
function saveCompleteCashMemo($conn, $billData, $companyData, $billItems, $permitData, $compID, $userID) {
    $billNo = $billData['BILL_NO'];
    $printDate = date('Y-m-d H:i:s');
    
    // Check if already printed today
    $checkQuery = "SELECT id FROM tbl_cash_memo_prints 
                   WHERE bill_no = ? AND comp_id = ? AND DATE(print_date) = CURDATE()";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("si", $billNo, $compID);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        $checkStmt->close();
        return false; // Already printed today
    }
    $checkStmt->close();
    
    // Prepare data
    $licenseNumber = !empty($companyData['licenseNumber']) ? $companyData['licenseNumber'] : "FL-II 3";
    $shopName = $companyData['name'];
    $shopAddress = $companyData['address'];
    $billDate = $billData['BILL_DATE'];
    
    $customerName = 'A.N. PARAB';
    if (!empty($permitData) && !empty($permitData['DETAILS'])) {
        $customerName = $permitData['DETAILS'];
    } elseif (!empty($billData['CUST_CODE']) && $billData['CUST_CODE'] != 'RETAIL') {
        $customerName = $billData['CUST_CODE'];
    }
    
    $permitNo = $permitData['P_NO'] ?? null;
    $permitPlace = $permitData['PLACE_ISS'] ?? null;
    $permitExpDate = !empty($permitData['P_EXP_DT']) ? $permitData['P_EXP_DT'] : null;
    
    $itemsJson = json_encode($billItems);
    $totalAmount = $billData['NET_AMOUNT'];
    
    // Generate the exact cash memo text
    $cashMemoText = generateCashMemoText($companyData, $billData, $billItems, $permitData);
    
    // Insert complete data
    $insertQuery = "INSERT INTO tbl_cash_memo_prints 
                   (bill_no, comp_id, print_date, printed_by, 
                    license_number, shop_name, shop_address, bill_date, 
                    customer_name, permit_no, permit_place, permit_exp_date,
                    items_json, total_amount, cash_memo_text) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insertStmt = $conn->prepare($insertQuery);
    $insertStmt->bind_param("sisisssssssssds", 
        $billNo, $compID, $printDate, $userID,
        $licenseNumber, $shopName, $shopAddress, $billDate,
        $customerName, $permitNo, $permitPlace, $permitExpDate,
        $itemsJson, $totalAmount, $cashMemoText
    );
    
    $result = $insertStmt->execute();
    $insertStmt->close();
    
    return $result;
}

// Handle generating cash memos when Generate is clicked
if (isset($_GET['generate'])) {
    $print_date = date('Y-m-d H:i:s');
    $bill_numbers_to_store = [];
    
    // Fetch company details for saving
    $companyDataForSave = [
        'name' => "DIAMOND WINE SHOP",
        'address' => "Ishvanbag Sangli Tal Hiraj Dist Sangli",
        'licenseNumber' => ""
    ];
    
    $companyQuery = "SELECT COMP_NAME, COMP_ADDR, COMP_FLNO, CF_LINE, CS_LINE FROM tblcompany WHERE CompID = ?";
    $companyStmt = $conn->prepare($companyQuery);
    $companyStmt->bind_param("i", $compID);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    if ($row = $companyResult->fetch_assoc()) {
        $companyDataForSave['name'] = $row['COMP_NAME'];
        $companyDataForSave['address'] = $row['COMP_ADDR'] ?? $companyDataForSave['address'];
        $companyDataForSave['licenseNumber'] = $row['COMP_FLNO'] ?? "";
        $addressLine = $row['CF_LINE'] ?? "";
        if (!empty($row['CS_LINE'])) {
            $addressLine .= (!empty($addressLine) ? " " : "") . $row['CS_LINE'];
        }
        if (!empty($addressLine)) {
            $companyDataForSave['address'] = $addressLine;
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
            
            // Get bill details with bottle size information (DETAILS2 column)
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
            
            // Assign permit
            if (!empty($allPermits)) {
                $bill_data['permit'] = getRandomPermit($allPermits);
            }
            
            // Save complete cash memo data
            saveCompleteCashMemo($conn, $bill_data, $companyDataForSave, $bill_items, 
                               $bill_data['permit'] ?? null, $compID, $_SESSION['user_id']);
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
        
        $availablePermits = $allPermits; // Copy for unique assignment
        
        while ($row = $billsResult->fetch_assoc()) {
            $bill_numbers_to_store[] = $row['BILL_NO'];
            
            // Get bill details for each bill with bottle size (DETAILS2 column)
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
            
            // Assign unique permit if available
            $permit = null;
            if (!empty($availablePermits)) {
                $permit = array_shift($availablePermits);
            } elseif (!empty($allPermits)) {
                $permit = getRandomPermit($allPermits);
            }
            
            $all_bills[] = [
                'header' => $row,
                'items' => $items,
                'permit' => $permit
            ];
            
            // Save complete cash memo data for this bill
            saveCompleteCashMemo($conn, $row, $companyDataForSave, $items, $permit, $compID, $_SESSION['user_id']);
        }
        $billsStmt->close();
    }
    
    // Set success message
    $_SESSION['success_message'] = count($bill_numbers_to_store) . " cash memo(s) generated and saved successfully!";
    
    // Show print section
    $_SESSION['show_print_section'] = true;
    $_SESSION['generated_bills_count'] = count($bill_numbers_to_store);
    $_SESSION['all_bills_data'] = $all_bills; // Store bills in session for print
}

// Check if we should show the print section
$showPrintSection = isset($_SESSION['show_print_section']) && $_SESSION['show_print_section'];

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

// Clear print section when new filters are applied without generate
if (isset($_GET['date_from']) || isset($_GET['date_to']) || isset($_GET['bill_no']) || isset($_GET['mode'])) {
    if (!isset($_GET['generate'])) {
        $_SESSION['show_print_section'] = false;
        $showPrintSection = false;
        unset($_SESSION['all_bills_data']);
    }
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
    
    /* PRINT STYLES */
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
        
        /* A4 page setup */
        @page {
            size: A4 portrait;
            margin: 5mm;
        }
        
        /* Memos container setup */
        .memos-container {
            display: block !important;
            width: 100% !important;
            margin: 0 auto !important;
            padding: 0 !important;
            text-align: center;
        }
        
        /* Cash memo styling for print */
        .cash-memo-container {
            width: 95mm !important; /* Half of A4 width minus margins */
            height: auto !important;
            margin: 3mm !important;
            padding: 3mm !important;
            border: 1px solid #000 !important;
            background: white !important;
            display: inline-block !important;
            vertical-align: top !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            float: none !important;
            font-size: 10px !important;
            line-height: 1.1 !important;
            box-sizing: border-box !important;
        }
        
        /* Ensure 2 memos per row */
        .cash-memo-container:nth-child(2n) {
            margin-right: 0 !important;
        }
        
        .cash-memo-container:nth-child(2n+1) {
            margin-left: 0 !important;
        }
        
        /* Page break after every 4 memos (2 rows) */
        .cash-memo-container:nth-child(4n) {
            page-break-after: always;
        }
        
        /* Header and text sizing for print */
        .cash-memo-header {
            margin-bottom: 3px !important;
            padding-bottom: 2px !important;
        }
        
        .license-info {
            font-size: 10px !important;
            margin-bottom: 2px !important;
        }
        
        .shop-name {
            font-size: 11px !important;
            margin-bottom: 1px !important;
        }
        
        .shop-address {
            font-size: 8px !important;
            margin-bottom: 3px !important;
        }
        
        .memo-info {
            font-size: 9px !important;
            margin-bottom: 4px !important;
        }
        
        .customer-info {
            font-size: 9px !important;
            margin-bottom: 4px !important;
        }
        
        .permit-info {
            font-size: 8px !important;
            margin-bottom: 4px !important;
        }
        
        .items-table {
            font-size: 9px !important;
            margin-bottom: 4px !important;
        }
        
        .total-section {
            font-size: 10px !important;
            margin-bottom: 2px !important;
        }
        
        /* Remove all shadows, backgrounds, etc. */
        .cash-memo-container {
            box-shadow: none !important;
            background: white !important;
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
          <form method="GET" class="report-filters" id="cashMemoForm">
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
                <i class="fas fa-cog me-1"></i> Generate & Save Cash Memos
              </button>
              
              <?php if ($showPrintSection && !empty($all_bills)): ?>
              <button type="button" class="btn btn-success" id="printButton">
                <i class="fas fa-print me-1"></i> Print All Cash Memos
              </button>
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
        </div>
      </div>

      <!-- Cash Memo Display -->
      <?php if ($showPrintSection): ?>
        <?php if (!empty($bill_data)): ?>
          <!-- Single bill display -->
          <div class="memos-container">
            <div class="cash-memo-container">
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
                <div class="header-size">Size</div>
                <div class="header-amount">Amount</div>
              </div>
              
              <table class="items-table">
                <tbody>
                  <?php foreach ($bill_items as $item): ?>
                  <tr>
                    <td class="particulars-col"><?= htmlspecialchars($item['DETAILS']) ?></td>
                    <td class="qty-col"><?= htmlspecialchars($item['QTY']) ?></td>
                    <td class="size-col"><?= !empty($item['DETAILS2']) ? htmlspecialchars($item['DETAILS2']) : '' ?></td>
                    <td class="amount-col"><?= number_format($item['AMOUNT'], 2) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              
              <div class="total-section">
                Total: ₹<?= number_format($bill_data['NET_AMOUNT'], 2) ?>
              </div>
            </div>
          </div>
        <?php elseif (isset($all_bills) && !empty($all_bills)): ?>
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
        <?php elseif (isset($_GET['generate']) && empty($bill_no)): ?>
          <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i> No bills found for the selected date range.
          </div>
        <?php elseif (isset($_GET['generate']) && !empty($bill_no)): ?>
          <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i> No bill found with number: <?= htmlspecialchars($bill_no) ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="alert alert-info no-print">
          <i class="fas fa-info-circle me-2"></i> Use the "Generate Cash Memos" button to create cash memos for printing.
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
    
    // Debug: Log how many cash memos are visible
    console.log('Cash memos generated: ', $('.cash-memo-container').length);
});
</script>
</body>
</html>