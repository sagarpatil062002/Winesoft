<?php
session_start();

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];

include_once "../config/db.php";

// ---- License filtering ----
require_once 'license_functions.php';

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
if (!empty($available_classes)) {
    foreach ($available_classes as $class) {
        $allowed_classes[] = $class['SGROUP'];
    }
}

// ---- Mode: F (Foreign) / C (Country) ----
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// ---- Next Voucher No. (for current company) ----
$vocQuery  = "SELECT MAX(VOC_NO) AS MAX_VOC FROM tblPurchases WHERE CompID = ?";
$vocStmt = $conn->prepare($vocQuery);
$vocStmt->bind_param("i", $companyId);
$vocStmt->execute();
$vocResult = $vocStmt->get_result();
$maxVoc    = $vocResult ? $vocResult->fetch_assoc() : ['MAX_VOC'=>0];
$nextVoc   = intval($maxVoc['MAX_VOC']) + 1;
$vocStmt->close();

// ---- Get distinct sizes from tblsubclass ----
$distinctSizes = [];
$sizeQuery = "SELECT DISTINCT CC FROM tblsubclass ORDER BY CC";
$sizeResult = $conn->query($sizeQuery);
if ($sizeResult) {
    while ($row = $sizeResult->fetch_assoc()) {
        $distinctSizes[] = $row['CC'];
    }
}
$sizeResult->close();

// Function to clean item code by removing SCM prefix
function cleanItemCode($code) {
    return preg_replace('/^SCM/i', '', trim($code));
}

// Function to check if a month is archived
function isMonthArchived($conn, $comp_id, $month, $year) {
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    
    // Check if archive table exists
    $check_archive_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                           WHERE table_schema = DATABASE() 
                           AND table_name = '$archive_table'";
    $check_result = $conn->query($check_archive_query);
    return $check_result->fetch_assoc()['count'] > 0;
}

// Function to update archived month stock - ONLY purchase column
function updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    
    // Check if record exists in archive table
    $check_query = "SELECT COUNT(*) as count FROM $archive_table 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $monthYear = date('Y-m', strtotime($purchaseDate));
    $check_stmt->bind_param("ss", $monthYear, $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Update existing record - ONLY purchase column
        $update_query = "UPDATE $archive_table 
                        SET $purchaseColumn = $purchaseColumn + ? 
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new record - ONLY purchase column
        $insert_query = "INSERT INTO $archive_table 
                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, $purchaseColumn) 
                        VALUES (?, ?, 'F', ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssi", $monthYear, $itemCode, $totalBottles);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
}

// Function to update current month stock - ONLY purchase column
function updateCurrentMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    $dailyStockTable = "tbldailystock_" . $comp_id;
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    
    // Check if daily stock record exists for this month and item
    $checkDailyStockQuery = "SELECT COUNT(*) as count FROM $dailyStockTable 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $checkStmt = $conn->prepare($checkDailyStockQuery);
    $checkStmt->bind_param("ss", $monthYear, $itemCode);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $row = $result->fetch_assoc();
    $checkStmt->close();
    
    if ($row['count'] > 0) {
        // Update existing record - ONLY purchase column
        $updateDailyStockQuery = "UPDATE $dailyStockTable 
                                 SET $purchaseColumn = $purchaseColumn + ? 
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $dailyStmt = $conn->prepare($updateDailyStockQuery);
        $dailyStmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
    } else {
        // Insert new record - ONLY purchase column
        $updateDailyStockQuery = "INSERT INTO $dailyStockTable 
                                 (STK_MONTH, ITEM_CODE, LIQ_FLAG, $purchaseColumn) 
                                 VALUES (?, ?, 'F', ?)";
        $dailyStmt = $conn->prepare($updateDailyStockQuery);
        $dailyStmt->bind_param("ssi", $monthYear, $itemCode, $totalBottles);
    }
    
    $dailyStmt->execute();
    $dailyStmt->close();
}

// Function to update item stock
function updateItemStock($conn, $itemCode, $totalBottles, $purchaseDate, $companyId) {
    $stockColumn = "CURRENT_STOCK" . $companyId;
    $updateItemStockQuery = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $stockColumn) 
                             VALUES (?, YEAR(?), ?) 
                             ON DUPLICATE KEY UPDATE $stockColumn = $stockColumn + ?";
    
    $itemStmt = $conn->prepare($updateItemStockQuery);
    $itemStmt->bind_param("ssii", $itemCode, $purchaseDate, $totalBottles, $totalBottles);
    $itemStmt->execute();
    $itemStmt->close();
}

// Function to update stock after purchase - USING EXTRACTED TOTAL BOTTLES
function updateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn) {
    // $totalBottles is the EXTRACTED value from SCM, not calculated
    
    // Get day of month from purchase date
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    // Check if this month is archived
    $isArchived = isMonthArchived($conn, $companyId, $month, $year);
    
    if ($isArchived) {
        // Update archived month data - tbldailystock_{comp_id}_{mm}_{yy}
        updateArchivedMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    } else {
        // Update current month data - tbldailystock_{comp_id}
        updateCurrentMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    }
    
    // UPDATE tblitem_stock - Add to current stock
    updateItemStock($conn, $itemCode, $totalBottles, $purchaseDate, $companyId);
}

// ---- Items (for case rate lookup & modal) - FILTERED BY LICENSE TYPE ----
$items = [];

if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $itemsQuery = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.ITEM_GROUP, im.LIQ_FLAG, im.CLASS,
                          COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE,
                          CONCAT('SCM', im.CODE) AS SCM_CODE
                     FROM tblitemmaster im
                     LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
                    WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders)
                 ORDER BY im.DETAILS";
    
    $params = array_merge([$mode], $allowed_classes);
    $types = str_repeat('s', count($params));
    
    $itemsStmt = $conn->prepare($itemsQuery);
    $itemsStmt->bind_param($types, ...$params);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    if ($itemsResult) $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();
} else {
    // If no classes allowed, show empty result
    $items = [];
}

// ---- Suppliers (for name/code replacement) ----
$suppliers = [];
$suppliersStmt = $conn->prepare("SELECT CODE, DETAILS FROM tblsupplier ORDER BY DETAILS");
$suppliersStmt->execute();
$suppliersResult = $suppliersStmt->get_result();
if ($suppliersResult) $suppliers = $suppliersResult->fetch_all(MYSQLI_ASSOC);
$suppliersStmt->close();

// ---- Save purchase ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $date = $_POST['date'];
    $voc_no = $_POST['voc_no'];
    $auto_tp_no = $_POST['auto_tp_no'] ?? ''; // NEW FIELD
    $tp_no = $_POST['tp_no'] ?? '';
    $tp_date = $_POST['tp_date'] ?? '';
    $inv_no = $_POST['inv_no'] ?? '';
    $inv_date = $_POST['inv_date'] ?? '';
    $supplier_code = $_POST['supplier_code'] ?? '';
    $supplier_name = $_POST['supplier_name'] ?? '';
    
    // Charges and taxes
    $cash_disc = $_POST['cash_disc'] ?? 0;
    $trade_disc = $_POST['trade_disc'] ?? 0;
    $octroi = $_POST['octroi'] ?? 0;
    $freight = $_POST['freight'] ?? 0;
    $stax_per = $_POST['stax_per'] ?? 0;
    $stax_amt = $_POST['stax_amt'] ?? 0;
    $tcs_per = $_POST['tcs_per'] ?? 0;
    $tcs_amt = $_POST['tcs_amt'] ?? 0;
    $misc_charg = $_POST['misc_charg'] ?? 0;
    $basic_amt = $_POST['basic_amt'] ?? 0;
    $tamt = $_POST['tamt'] ?? 0;
    
    // Insert purchase header with AUTO_TPNO, TP_DATE and FREIGHT columns
    $insertQuery = "INSERT INTO tblpurchases (
        DATE, SUBCODE, AUTO_TPNO, VOC_NO, INV_NO, INV_DATE, TAMT, 
        TPNO, TP_DATE, SCHDIS, CASHDIS, OCTROI, FREIGHT, STAX_PER, STAX_AMT, 
        TCS_PER, TCS_AMT, MISC_CHARG, PUR_FLAG, CompID
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $insertStmt = $conn->prepare($insertQuery);
    $insertStmt->bind_param(
        "sssisssdddddddddddsi",
        $date, $supplier_code, $auto_tp_no, $voc_no, $inv_no, $inv_date, $tamt,
        $tp_no, $tp_date, $trade_disc, $cash_disc, $octroi, $freight, $stax_per, $stax_amt,
        $tcs_per, $tcs_amt, $misc_charg, $mode, $companyId
    );
    
    if ($insertStmt->execute()) {
        $purchase_id = $conn->insert_id;
        
        // Insert purchase items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $detailQuery = "INSERT INTO tblpurchasedetails (
                PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, FreeCases, FreeBottles, 
                CaseRate, MRP, Amount, BottlesPerCase, BatchNo, AutoBatch, MfgMonth, BL, VV, TotBott
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $detailStmt = $conn->prepare($detailQuery);
            
            foreach ($_POST['items'] as $item) {
                // Use null coalescing operator (??) instead of logical OR (||) for array values
                $item_code = $item['code'] ?? '';
                $item_name = $item['name'] ?? '';
                $item_size = $item['size'] ?? '';
                $cases = floatval($item['cases'] ?? 0);
                $bottles = intval($item['bottles'] ?? 0);
                $free_cases = floatval($item['free_cases'] ?? 0);
                $free_bottles = intval($item['free_bottles'] ?? 0);
                $case_rate = floatval($item['case_rate'] ?? 0);
                $mrp = floatval($item['mrp'] ?? 0);
                $bottles_per_case = intval($item['bottles_per_case'] ?? 12);
                $batch_no = $item['batch_no'] ?? '';
                $auto_batch = $item['auto_batch'] ?? '';
                $mfg_month = $item['mfg_month'] ?? '';
                $bl = floatval($item['bl'] ?? 0);
                $vv = floatval($item['vv'] ?? 0);
                $tot_bott = intval($item['tot_bott'] ?? 0); // EXTRACTED TOTAL BOTTLES FROM SCM
                
                // Calculate amount correctly: cases * case_rate + bottles * (case_rate / bottles_per_case)
                $amount = ($cases * $case_rate) + ($bottles * ($case_rate / $bottles_per_case));
                
                $detailStmt->bind_param(
                    "isssdddddddisssddi",
                    $purchase_id, 
                    $item_code, 
                    $item_name, 
                    $item_size,
                    $cases, 
                    $bottles, 
                    $free_cases, 
                    $free_bottles, 
                    $case_rate, 
                    $mrp, 
                    $amount, 
                    $bottles_per_case,
                    $batch_no, 
                    $auto_batch, 
                    $mfg_month, 
                    $bl, 
                    $vv, 
                    $tot_bott  // This is the extracted total bottles from SCM
                );
                
                if (!$detailStmt->execute()) {
                    error_log("Error inserting purchase detail: " . $detailStmt->error);
                }
                
                // Update stock using the EXTRACTED tot_bott value
                updateStock($item_code, $tot_bott, $date, $companyId, $conn);
            }
            $detailStmt->close();
        }
        
        header("Location: purchase_module.php?mode=".$mode."&success=1");
        exit;
    } else {
        $errorMessage = "Error saving purchase: " . $insertStmt->error;
    }
    
    $insertStmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New Purchase</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
<style>
.table-container {
    overflow-x: auto;
    max-height: 420px;
    margin: 20px 0;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.styled-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    table-layout: fixed;
}

.styled-table th, 
.styled-table td {
    border: 1px solid #e5e7eb;
    padding: 6px 8px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.styled-table thead th {
    position: sticky;
    top: 0;
    background: #f8fafc;
    z-index: 1;
    font-weight: 600;
    text-align: center;
    vertical-align: middle;
}

.styled-table tbody tr:hover {
    background-color: #f8f9fa;
}

/* Fixed column widths to match SCM layout */
.col-code { width: 150px; }
.col-name { width: 180px; }
.col-size { width: 100px; }
.col-cases { width: 100px; }
.col-bottles { width: 100px; }
.col-free-cases { width: 100px; }      /* NEW */
.col-free-bottles { width: 100px; }    /* NEW */
.col-rate { width: 100px; }
.col-amount { width: 100px; }
.col-mrp { width: 100px; }
.col-batch { width: 90px; }
.col-auto-batch { width: 180px; }
.col-mfg { width: 100px; }
.col-bl { width: 100px; }
.col-vv { width: 90px; }
.col-totbott { width: 100px; }
.col-action { width: 60px; }

/* Text alignment */
#itemsTable td:first-child,
#itemsTable th:first-child {
    text-align: left;
}

#itemsTable td:nth-child(2),
#itemsTable th:nth-child(2) {
    text-align: left;
}

#itemsTable td, 
#itemsTable th {
    text-align: center;
    vertical-align: middle;
}

/* Input field styling */
input.form-control-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
    width: 100%;
    box-sizing: border-box;
}

/* Total amount styling */
.total-amount {
    font-weight: bold;
    padding: 10px;
    text-align: right;
    background-color: #f1f1f1;
    border-top: 2px solid #dee2e6;
}

/* Bottles by size table styling */
#bottlesBySizeTable th {
    font-size: 0.75rem;
    padding: 4px 6px;
}
#bottlesBySizeTable td {
    font-size: 0.85rem;
    padding: 4px 6px;
}
.size-separator {
    background-color: #6c757d !important;
    color: white !important;
    font-weight: bold;
    width: 10px;
}

/* Alert styling for missing items */
.missing-items-alert {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    background-color: #f8f9fa;
    margin-top: 10px;
}
.missing-item {
    padding: 5px 0;
    border-bottom: 1px solid #e9ecef;
}
.missing-item:last-child {
    border-bottom: none;
}

/* Supplier suggestions styling */
.supplier-container {
    position: relative;
}
.supplier-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}
.supplier-suggestion {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
}
.supplier-suggestion:hover {
    background-color: #f8f9fa;
}
.supplier-suggestion:last-child {
    border-bottom: none;
}

/* Enhanced styling for the missing items modal */
#licenseRestrictedList tr:hover,
#missingItemsList tr:hover {
  background-color: #f8f9fa;
}

.modal-table th {
  font-size: 0.8rem;
  font-weight: 600;
}
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <h4 class="mb-3">New Purchase - <?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?></h4>

      <!-- License Restriction Info -->
      <div class="alert alert-info mb-3">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
              <?php 
              if (!empty($available_classes)) {
                  $class_names = [];
                  foreach ($available_classes as $class) {
                      $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                  }
                  echo implode(', ', $class_names);
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
      </div>

      <?php if (isset($errorMessage)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
      <?php endif; ?>

      <div class="alert alert-info">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="fa-solid fa-paste"></i>
          <strong>Paste from SCM System</strong>
        </div>
        <div class="small-help mb-2">
          Copy the table (including headers) from the SCM retailer screen and paste it.  
          The parser understands the two-line "SCM Code:" rows automatically.
        </div>
        <button id="pasteFromSCM" class="btn btn-primary btn-sm">
          <i class="fa-solid fa-clipboard"></i> Paste SCM Data
        </button>
      </div>

      <form method="POST" id="purchaseForm">
        <input type="hidden" name="mode" value="<?=htmlspecialchars($mode)?>">
        <input type="hidden" name="voc_no" value="<?=$nextVoc?>">

        <!-- HEADER -->
        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-receipt me-2"></i>Purchase Information</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label">Voucher No.</label>
                <input class="form-control" value="<?=$nextVoc?>" disabled>
              </div>
              <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="date" value="<?=date('Y-m-d')?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Auto TP No.</label> <!-- NEW FIELD -->
                <input type="text" class="form-control" name="auto_tp_no" id="autoTpNo">
              </div>
              <div class="col-md-3">
                <label class="form-label">T.P. No.</label>
                <input type="text" class="form-control" name="tp_no" id="tpNo">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-3">
                <label class="form-label">T.P. Date</label>
                <input type="date" class="form-control" name="tp_date" id="tpDate">
              </div>
              <div class="col-md-3">
                <label class="form-label">Invoice No.</label>
                <input type="text" class="form-control" name="inv_no">
              </div>
              <div class="col-md-3">
                <label class="form-label">Invoice Date</label>
                <input type="date" class="form-control" name="inv_date">
              </div>
              <div class="col-md-3">
                <label class="form-label">Supplier (type code or name)</label>
                <div class="supplier-container">
                  <input type="text" class="form-control" name="supplier_name" id="supplierInput" placeholder="e.g., ASIAN TRADERS-5" required>
                  <div class="supplier-suggestions" id="supplierSuggestions"></div>
                </div>
                <div class="small-help">This field is filled with the <strong>Party (supplier name)</strong> from SCM. You can also pick from list:</div>
                <select class="form-select mt-1" id="supplierSelect">
                  <option value="">Select Supplier</option>
                  <?php foreach($suppliers as $s): ?>
                    <option value="<?=htmlspecialchars($s['DETAILS'])?>"
                            data-code="<?=htmlspecialchars($s['CODE'])?>">
                      <?=htmlspecialchars($s['DETAILS'])?> (<?=htmlspecialchars($s['CODE'])?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="supplier_code" id="supplierCodeHidden">
              </div>
            </div>
          </div>
        </div>

              <!-- TOTAL BOTTLES BY SIZE -->
        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-wine-bottle me-2"></i>Total Bottles by Size</div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-bordered table-sm mb-0" id="bottlesBySizeTable">
                <thead class="table-light">
                  <tr id="sizeHeaders">
                    <!-- Headers will be populated by JavaScript -->
                  </tr>
                </thead>
                <tbody>
                  <tr id="sizeValues">
                    <!-- Values will be populated by JavaScript -->
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ITEMS -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span class="fw-semibold"><i class="fa-solid fa-list me-2"></i>Purchase Items</span>
    <div>
      <button class="btn btn-sm btn-primary" type="button" id="addItem"><i class="fa-solid fa-plus"></i> Add Item</button>
      <button class="btn btn-sm btn-secondary" type="button" id="clearItems"><i class="fa-solid fa-trash"></i> Clear All</button>
    </div>
  </div>
  <div class="card-body">
    <div class="table-container">
      <table class="styled-table" id="itemsTable">
        <thead>
          <tr>
            <th class="col-code">Item Code</th>
            <th class="col-name">Brand Name</th>
            <th class="col-size">Size</th>
            <th class="col-cases">Cases</th>
            <th class="col-bottles">Bottles</th>
            <th class="col-free-cases">Free Cases</th>    <!-- NEW COLUMN -->
            <th class="col-free-bottles">Free Bottles</th> <!-- NEW COLUMN -->
            <th class="col-rate">Case Rate</th>
            <th class="col-amount">Amount</th>
            <th class="col-mrp">MRP</th>
            <th class="col-batch">Batch No</th>
            <th class="col-auto-batch">Auto Batch</th>
            <th class="col-mfg">Mfg. Month</th>
            <th class="col-bl">B.L.</th>
            <th class="col-vv">V/v (%)</th>
            <th class="col-totbott">Tot. Bott.</th>
            <th class="col-action">Action</th>
          </tr>
        </thead>
        <tbody>
          <tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>
        </tbody>
        <!-- In the HTML table section, replace the tfoot section with this corrected version -->
<tfoot>
  <tr class="totals-row">
    <td colspan="3" class="text-end fw-semibold">Total:</td>
    <td id="totalCases" class="fw-semibold">0.00</td>
    <td id="totalBottles" class="fw-semibold">0</td>
    <td id="totalFreeCases" class="fw-semibold">0.00</td>
    <td id="totalFreeBottles" class="fw-semibold">0</td>
    <td></td> <!-- Case Rate -->
    <td id="totalAmount" class="fw-semibold">0.00</td>
    <td></td> <!-- MRP -->
    <td></td> <!-- Batch No -->
    <td></td> <!-- Auto Batch -->
    <td></td> <!-- Mfg. Month -->
    <td id="totalBL" class="fw-semibold">0.00</td> <!-- B.L. -->
    <td></td> <!-- V/v (%) -->
    <td id="totalTotBott" class="fw-semibold">0</td> <!-- Tot. Bott. -->
    <td></td> <!-- Action -->
  </tr>
</tfoot>
      </table>
    </div>
  </div>
</div>



        <!-- CHARGES -->
        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-calculator me-2"></i>Charges & Taxes</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-3"><label class="form-label">Cash Discount</label><input type="number" step="0.01" class="form-control" name="cash_disc" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Trade Discount</label><input type="number" step="0.01" class="form-control" name="trade_disc" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Octroi</label><input type="number" step="0.01" class="form-control" name="octroi" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Freight Charges</label><input type="number" step="0.01" class="form-control" name="freight" value="0.00"></div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-3"><label class="form-label">Sales Tax (%)</label><input type="number" step="0.01" class="form-control" name="stax_per" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Sales Tax Amount</label><input type="number" step="0.01" class="form-control" name="stax_amt" value="0.00" readonly></div>
              <div class="col-md-3"><label class="form-label">TCS (%)</label><input type="number" step="0.01" class="form-control" name="tcs_per" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">TCS Amount</label><input type="number" step="0.01" class="form-control" name="tcs_amt" value="0.00" readonly></div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-3"><label class="form-label">Misc. Charges</label><input type="number" step="0.01" class="form-control" name="misc_charg" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Basic Amount</label><input type="number" step="0.01" class="form-control" name="basic_amt" value="0.00" readonly></div>
              <div class="col-md-3"><label class="form-label">Total Amount</label><input type="number" step="0.01" class="form-control" name="tamt" value="0.00" readonly></div>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
          <a class="btn btn-secondary" href="purchase_module.php?mode=<?=$mode?>"><i class="fa-solid fa-xmark"></i> Cancel</a>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- ITEM PICKER MODAL -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Select Item</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <input class="form-control mb-2" id="itemSearch" placeholder="Search items...">
      <div class="table-container">
        <table class="styled-table">
          <thead><tr><th>Code</th><th>Item</th><th>Size</th><th>Price</th><th>Bottles/Case</th><th>Action</th></tr></thead>
          <tbody id="itemsModalTable">
          <?php foreach($items as $it): ?>
            <tr class="item-row-modal">
              <td><?=htmlspecialchars($it['CODE'])?></td>
              <td><?=htmlspecialchars($it['DETAILS'])?></td>
              <td><?=htmlspecialchars($it['DETAILS2'])?></td>
              <td><?=number_format((float)$it['PPRICE'],3)?></td>
              <td><?=htmlspecialchars($it['BOTTLE_PER_CASE'])?></td>
              <td><button type="button" class="btn btn-sm btn-primary select-item"
                  data-code="<?=htmlspecialchars($it['CODE'])?>"
                  data-name="<?=htmlspecialchars($it['DETAILS'])?>"
                  data-size="<?=htmlspecialchars($it['DETAILS2'])?>"
                  data-price="<?=htmlspecialchars($it['PPRICE'])?>"
                  data-bottles-per-case="<?=htmlspecialchars($it['BOTTLE_PER_CASE'])?>">Select</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div></div>
</div>

<!-- SCM Paste Modal (Enhanced from purchases.php) -->
<div class="modal fade" id="scmPasteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Paste SCM Data</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Paste SCM table data here:</label>
          <textarea class="form-control" id="scmPasteArea" rows="10" placeholder="Paste the copied table from SCM system here..."></textarea>
        </div>
        <div class="alert alert-warning">
          <strong>Note:</strong> Make sure to copy the entire table from SCM, including headers and the two-line rows with "SCM Code:".
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="processSCMData">Process Data</button>
      </div>
    </div>
  </div>
</div>

<!-- Missing Items Modal (From purchases.php) -->
<div class="modal fade" id="missingItemsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Items Requiring Attention</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="fa-solid fa-circle-info me-2"></i>
          <strong>Found <span id="validItemsCount">0</span> valid items and <span id="missingItemsCount">0</span> items requiring attention</strong>
        </div>
        
        <!-- License Restricted Items -->
        <div class="card mb-3" id="licenseRestrictedSection" style="display: none;">
          <div class="card-header bg-warning text-dark">
            <i class="fa-solid fa-ban me-2"></i>
            <strong>License Restricted Items</strong>
            <span class="badge bg-danger ms-2" id="restrictedCount">0</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th width="120">SCM Code</th>
                    <th>Brand Name</th>
                    <th width="80">Size</th>
                    <th width="120">Class</th>
                    <th width="200">Reason</th>
                  </tr>
                </thead>
                <tbody id="licenseRestrictedList">
                  <!-- License restricted items will be listed here -->
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Missing Items -->
        <div class="card" id="missingItemsSection" style="display: none;">
          <div class="card-header bg-danger text-white">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            <strong>Items Not Found in Database</strong>
            <span class="badge bg-dark ms-2" id="missingCount">0</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th width="120">SCM Code</th>
                    <th>Brand Name</th>
                    <th width="80">Size</th>
                    <th width="200">Possible Solutions</th>
                  </tr>
                </thead>
                <tbody id="missingItemsList">
                  <!-- Missing items will be listed here -->
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="mt-3">
          <div class="alert alert-warning">
            <strong><i class="fa-solid fa-lightbulb me-2"></i>Next Steps:</strong>
            <ul class="mb-0 mt-2">
              <li>License restricted items cannot be added due to your license type (<strong><?= htmlspecialchars($license_type) ?></strong>)</li>
              <li>Missing items need to be added to your database first</li>
              <li>You can proceed with the valid items found</li>
            </ul>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fa-solid fa-times me-2"></i>Cancel Processing
        </button>
        <button type="button" class="btn btn-success" id="continueWithFoundItems">
          <i class="fa-solid fa-check me-2"></i>Continue with <span id="continueItemsCount">0</span> Valid Items
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
  let itemCount = 0;
  const dbItems = <?=json_encode($items, JSON_UNESCAPED_UNICODE)?>; // for matching
  const suppliers = <?=json_encode($suppliers, JSON_UNESCAPED_UNICODE)?>; // for supplier matching
  const distinctSizes = <?=json_encode($distinctSizes, JSON_UNESCAPED_UNICODE)?>; // from database
  const allowedClasses = <?= json_encode($allowed_classes) ?>; // License allowed classes

  // ---------- Helpers ----------
  function ymdFromDmyText(str){
    // Accepts "15-Apr-2025" (with any case) → "2025-04-15"
    const m = str.trim().match(/^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/);
    if(!m) return '';
    const map = {Jan:'01',Feb:'02',Mar:'03',Apr:'04',May:'05',Jun:'06',Jul:'07',Aug:'08',Sep:'09',Oct:'10',Nov:'11',Dec:'12'};
    const mon = map[m[2].slice(0,3)];
    if(!mon) return '';
    return `${m[3]}-${mon}-${String(m[1]).padStart(2,'0')}`;
  }

  // Function to clean item code by removing SCM prefix
  function cleanItemCode(code) {
    return (code || '').replace(/^SCM/i, '').trim();
  }

  // Function to find best supplier match
function findBestSupplierMatch(parsedName) {
    if (!parsedName) return null;
    
    const parsedClean = parsedName.toLowerCase().replace(/[^a-z0-9\s]/g, '');
    let bestMatch = null;
    let bestScore = 0;
    
    suppliers.forEach(supplier => {
        const supplierName = (supplier.DETAILS || '').toLowerCase().replace(/[^a-z0-9\s]/g, '');
        const supplierCode = (supplier.CODE || '').toLowerCase();
        
        // Remove numeric suffixes for better matching
        const parsedBase = parsedClean.replace(/\d+$/, '');
        const supplierBase = supplierName.replace(/\d+$/, '');
        
        // Score based on string similarity
        let score = 0;
        
        // 1. Exact match (highest priority)
        if (supplierName === parsedClean) {
            score = 100;
        }
        // 2. Base name match (without suffixes)
        else if (supplierBase === parsedBase && supplierBase.length > 0) {
            score = 95;
        }
        // 3. Contains match
        else if (supplierName.includes(parsedClean) || parsedClean.includes(supplierName)) {
            score = 80;
        }
        // 4. Base name contains match (without suffixes)
        else if (supplierBase.includes(parsedBase) || parsedBase.includes(supplierBase)) {
            score = 70;
        }
        // 5. Code match
        else if (parsedClean.includes(supplierCode) || supplierCode.includes(parsedClean)) {
            score = 60;
        }
        // 6. Partial match with common prefix
        else if (supplierName.startsWith(parsedClean.substring(0, 5)) || 
                 parsedClean.startsWith(supplierName.substring(0, 5))) {
            score = 50;
        }
        
        if (score > bestScore) {
            bestScore = score;
            bestMatch = supplier;
        }
    });
    
    return bestMatch;
}
  // Enhanced database item matching function (from purchases.php)
  function findDbItemData(name, size, code) {
    // Clean inputs
    const cleanName = (name || '').toLowerCase().replace(/[^\w\s]/g, '').replace(/\s+/g, ' ').trim();
    const cleanSize = (size || '').toLowerCase().replace(/[^\w\s]/g, '').trim();
    const cleanCode = (code || '').toLowerCase().replace(/^scm/, '').trim();
    
    console.log("Looking for item:", {name: cleanName, size: cleanSize, code: cleanCode});
    
    // 1. Try exact code match first (with cleaned code)
    if (cleanCode) {
        for (const it of dbItems) {
            const dbCode = (it.CODE || '').toLowerCase().trim();
            if (dbCode === cleanCode) {
                console.log("Exact code match found:", it.CODE, "Class:", it.CLASS);
                return it;
            }
        }
        
        // 2. Try SCM code match (SCM + code)
        for (const it of dbItems) {
            const scmCode = (it.SCM_CODE || '').toLowerCase().replace(/^scm/, '').trim();
            if (scmCode === cleanCode) {
                console.log("SCM code match found:", it.CODE, "Class:", it.CLASS);
                return it;
            }
        }
    }
    
    // 3. Try name and size match with scoring
    let bestMatch = null;
    let bestScore = 0;
    
    for (const it of dbItems) {
        const dbName = (it.DETAILS || '').toLowerCase().replace(/[^\w\s]/g, '').replace(/\s+/g, ' ').trim();
        const dbSize = (it.DETAILS2 || '').toLowerCase().replace(/[^\w\s]/g, '').trim();
        let score = 0;
        
        // Name similarity (higher weight)
        if (dbName && cleanName === dbName) score += 40;
        else if (dbName && cleanName.includes(dbName)) score += 30;
        else if (dbName && dbName.includes(cleanName)) score += 20;
        
        // Size similarity
        if (dbSize && cleanSize === dbSize) score += 30;
        else if (dbSize && cleanSize.includes(dbSize)) score += 20;
        else if (dbSize && dbSize.includes(cleanSize)) score += 10;
        // If we have a code, check if it's similar to the database code
        if (cleanCode) {
            const dbCode = (it.CODE || '').toLowerCase().trim();
            if (dbCode === cleanCode) score += 50;
            else if (dbCode.includes(cleanCode) || cleanCode.includes(dbCode)) score += 25;
        }
        
        if (score > bestScore) {
            bestScore = score;
            bestMatch = it;
        }
    }
    
    if (bestMatch) {
        console.log("Best match found:", bestMatch.CODE, "Class:", bestMatch.CLASS, "Score:", bestScore);
        return bestMatch;
    }
    
    console.log("No match found for:", {name: cleanName, size: cleanSize, code: cleanCode});
    return null;
  }

  // Enhanced SCM data validation (from purchases.php)
  function validateSCMItems(scmItems) {
    const validItems = [];
    const missingItems = [];
    
    scmItems.forEach((scmItem, index) => {
        // Clean the SCM code by removing 'SCM' prefix and any whitespace
        const cleanCode = scmItem.scmCode ? scmItem.scmCode.replace(/^SCM/i, '').trim() : '';
        
        // Find matching item in database using multiple strategies
        let matchingItem = null;
        
        // Strategy 1: Exact match with cleaned code
        matchingItem = dbItems.find(dbItem => dbItem.CODE === cleanCode);
        
        // Strategy 2: Match by SCM_CODE field (if available)
        if (!matchingItem) {
            matchingItem = dbItems.find(dbItem => dbItem.SCM_CODE === scmItem.scmCode);
        }
        
        // Strategy 3: Case-insensitive code match
        if (!matchingItem) {
            matchingItem = dbItems.find(dbItem => 
                dbItem.CODE.toLowerCase() === cleanCode.toLowerCase()
            );
        }
        
        // Strategy 4: Partial code match
        if (!matchingItem) {
            matchingItem = dbItems.find(dbItem => 
                dbItem.CODE.includes(cleanCode) || cleanCode.includes(dbItem.CODE)
            );
        }
        
        // Strategy 5: Brand name match (as fallback)
        if (!matchingItem && scmItem.brandName) {
            const brandSearchTerm = scmItem.brandName.toLowerCase().replace(/[\.\-]/g, ' ').trim();
            matchingItem = dbItems.find(dbItem => {
                const dbBrandName = dbItem.DETAILS ? dbItem.DETAILS.toLowerCase().replace(/[\.\-]/g, ' ').trim() : '';
                return dbBrandName.includes(brandSearchTerm) || brandSearchTerm.includes(dbBrandName);
            });
        }
        
        if (matchingItem) {
            // Check if item is allowed by license
            if (allowedClasses.includes(matchingItem.CLASS)) {
                validItems.push({
                    scmData: scmItem,
                    dbItem: matchingItem
                });
            } else {
                missingItems.push({
                    code: scmItem.scmCode,
                    name: scmItem.brandName,
                    size: scmItem.size,
                    class: matchingItem.CLASS,
                    reason: 'License restriction',
                    type: 'restricted'
                });
            }
        } else {
            missingItems.push({
                code: scmItem.scmCode,
                name: scmItem.brandName,
                size: scmItem.size,
                reason: 'Not found in database',
                type: 'missing'
            });
        }
    });
    
    return { validItems, missingItems };
  }

  // Show missing items modal (from purchases.php)
  function showMissingItemsModal(missingItems, validItems, parsedData) {
    // Separate restricted and missing items
    const restrictedItems = missingItems.filter(item => item.type === 'restricted');
    const missingDbItems = missingItems.filter(item => item.type === 'missing');
    
    // Update counts
    $('#validItemsCount').text(validItems.length);
    $('#missingItemsCount').text(missingItems.length);
    $('#continueItemsCount').text(validItems.length);
    
    // Show/hide restricted items section
    if (restrictedItems.length > 0) {
        $('#licenseRestrictedSection').show();
        $('#restrictedCount').text(restrictedItems.length);
        
        const restrictedList = $('#licenseRestrictedList');
        restrictedList.empty();
        
        restrictedItems.forEach(item => {
            restrictedList.append(`
                <tr>
                    <td><strong>${item.code}</strong></td>
                    <td>${item.name}</td>
                    <td>${item.size}</td>
                    <td><span class="badge bg-secondary">${item.class}</span></td>
                    <td><span class="text-danger">Not allowed for your license type</span></td>
                </tr>
            `);
        });
    } else {
        $('#licenseRestrictedSection').hide();
    }
    
    // Show/hide missing items section
    if (missingDbItems.length > 0) {
        $('#missingItemsSection').show();
        $('#missingCount').text(missingDbItems.length);
        
        const missingList = $('#missingItemsList');
        missingList.empty();
        
        missingDbItems.forEach(item => {
            missingList.append(`
                <tr>
                    <td><strong>${item.code}</strong></td>
                    <td>${item.name}</td>
                    <td>${item.size}</td>
                    <td>
                        <small class="text-muted">
                            • Check item code matches your database<br>
                            • Verify item exists in tblitemmaster<br>
                            • Item may need to be added manually
                        </small>
                    </td>
                </tr>
            `);
        });
    } else {
        $('#missingItemsSection').hide();
    }
    
    // Store data for later use
    $('#missingItemsModal').data({
        validItems: validItems,
        parsedData: parsedData
    });
    
    $('#missingItemsModal').modal('show');
  }

  // Process valid SCM items (from purchases.php)
  function processValidSCMItems(validItems, parsedData) {
    // Clear existing items first
    $('#clearItems').click();
    
    // Set supplier information
    if (parsedData.supplier) {
        $('#supplierInput').val(parsedData.supplier);
        
        // Try to find matching supplier code
        const matchedSupplier = suppliers.find(s => 
            s.DETAILS.toLowerCase().includes(parsedData.supplier.toLowerCase()) ||
            parsedData.supplier.toLowerCase().includes(s.DETAILS.toLowerCase())
        );
        
        if (matchedSupplier) {
            $('#supplierCodeHidden').val(matchedSupplier.CODE);
        }
    }
    
    // Set TP information
    if (parsedData.tpNo) {
        $('#tpNo').val(parsedData.tpNo);
    }
    if (parsedData.tpDate) {
        $('#tpDate').val(parsedData.tpDate);
    }
    
    // Auto-generate Auto TP No from TP No and current date
    if (parsedData.tpNo) {
        const currentDate = new Date().toISOString().slice(0, 10).replace(/-/g, '');
        const autoTpNo = `${parsedData.tpNo}-${currentDate}`;
        $('#autoTpNo').val(autoTpNo);
    }
    
    // Add valid items to table
    validItems.forEach((validItem, index) => {
        addRow({
            dbItem: validItem.dbItem,
            ...validItem.scmData,
            cleanCode: validItem.scmData.scmCode ? validItem.scmData.scmCode.replace(/^SCM/i, '').trim() : ''
        });
    });
    
    if (validItems.length === 0) {
        alert('No valid items found in the SCM data that match your database and license restrictions.');
    } else {
        alert(`Successfully added ${validItems.length} items from SCM data.`);
    }
  }

  // COMBINED SCM PARSING FUNCTION - Preserves header extraction from purchases copy.php
  function parseSCMData(data) {
    const lines = data.split('\n').map(line => line.trim()).filter(line => line);
    
    let supplier = '';
    let tpNo = '';
    let tpDate = '';
    let receivedDate = '';
    let autoTpNo = '';
    const items = [];
    
    // Parse header information (from purchases copy.php)
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        
        // Skip empty lines and total lines
        if (!line || line.includes('Total') || line.match(/^\d+\s+Total/)) {
            continue;
        }
        
        // Extract Received Date
        if (/Received\s*Date/i.test(line)) {
            const nextLine = (lines[i + 1] || '').trim();
            if (nextLine) {
                const ymdDate = ymdFromDmyText(nextLine);
                receivedDate = ymdDate || nextLine;
                if (ymdDate) {
                    $('input[name="date"]').val(ymdDate);
                }
            }
        }
        
        // Extract Auto T.P. No
        if (/Auto\s*T\.\s*P\.\s*No:/i.test(line)) {
            const nextLine = (lines[i + 1] || '').trim();
            if (nextLine && !/T\.?P\.?Date/i.test(nextLine)) {
                autoTpNo = nextLine;
                $('#autoTpNo').val(nextLine);
            }
        }
        
        // Extract Manual T.P. No
        if (/T\.\s*P\.\s*No\(Manual\):/i.test(line)) {
            const nextLine = (lines[i + 1] || '').trim();
            if (nextLine && !/T\.?P\.?Date/i.test(nextLine)) {
                tpNo = nextLine;
                $('#tpNo').val(nextLine);
            }
        }
        
        // Extract T.P. Date
        if (/T\.?P\.?Date:/i.test(line)) {
            const nextLine = (lines[i + 1] || '').trim();
            const ymdDate = ymdFromDmyText(nextLine);
            if (ymdDate) {
                tpDate = ymdDate;
                $('#tpDate').val(ymdDate);
            }
        }
        
        // Extract Party (Supplier)
if (/^Party\s*:/i.test(line)) {
    const nextLine = (lines[i + 1] || '').trim();
    if (nextLine) {
        supplier = nextLine;
        
        // Try to find the best supplier match (with suffix removal logic)
        const supplierMatch = findBestSupplierMatch(nextLine);
        if (supplierMatch) {
            $('#supplierInput').val(supplierMatch.DETAILS);
            $('#supplierCodeHidden').val(supplierMatch.CODE);
        } else {
            $('#supplierInput').val(nextLine);
        }
    }
}
        // Item lines (SCM Code) - from purchases.php
        if (line.includes('SCM Code:')) {
            try {
                const item = parseSCMLine(line);
                if (item) {
                    items.push(item);
                }
            } catch (error) {
                console.error('Error parsing SCM line:', error);
            }
        }
    }
    
    return { 
        supplier, 
        tpNo, 
        tpDate, 
        receivedDate,
        autoTpNo,
        items 
    };
  }

  // Enhanced SCM line parsing (from purchases.php)
  function parseSCMLine(line) {
    // Split by multiple spaces to get parts
    const parts = line.split(/\s{2,}/);
    
    if (parts.length < 2) {
        return parseSCMLineAlternative(line);
    }
    
    const item = {};
    
    // First part contains "SCM Code: CODE"
    const scmCodePart = parts[0];
    const scmCodeMatch = scmCodePart.match(/SCM Code:\s*(\S+)/i);
    if (scmCodeMatch && scmCodeMatch[1]) {
        item.scmCode = scmCodeMatch[1];
        // Extract brand name from the rest of the first part if available
        const remainingFirstPart = scmCodePart.replace(/SCM Code:\s*\S+/i, '').trim();
        if (remainingFirstPart) {
            item.brandName = remainingFirstPart;
        }
    }
    
    // The remaining parts contain the data
    const dataParts = line.replace(/SCM Code:\s*\S+/i, '').trim().split(/\s+/);
    
    if (dataParts.length >= 11) {
        let index = 0;
        
        // If brand name wasn't extracted from first part, get it from data parts
        if (!item.brandName) {
            let brandNameParts = [];
            while (index < dataParts.length && !dataParts[index].match(/\d+ML/i) && !dataParts[index].match(/\d+L/i)) {
                brandNameParts.push(dataParts[index]);
                index++;
            }
            item.brandName = brandNameParts.join(' ');
        } else {
            // Still need to skip the brand name parts in data parts
            while (index < dataParts.length && !dataParts[index].match(/\d+ML/i) && !dataParts[index].match(/\d+L/i)) {
                index++;
            }
        }
        
        // Size (e.g., "375 ML", "750 ML")
        if (index < dataParts.length) {
            item.size = dataParts[index];
            index++;
        }
        
        // Cases (decimal)
        if (index < dataParts.length) {
            item.cases = parseFloat(dataParts[index]) || 0;
            index++;
        }
        
        // Bottles (integer)
        if (index < dataParts.length) {
            item.bottles = parseInt(dataParts[index]) || 0;
            index++;
        }
        
        // Batch No
        if (index < dataParts.length) {
            item.batchNo = dataParts[index] || '';
            index++;
        }
        
        // Auto Batch
        if (index < dataParts.length) {
            item.autoBatch = dataParts[index] || '';
            index++;
        }
        
        // Mfg Month
        if (index < dataParts.length) {
            item.mfgMonth = dataParts[index] || '';
            index++;
        }
        
        // MRP (decimal)
        if (index < dataParts.length) {
            item.mrp = parseFloat(dataParts[index]) || 0;
            index++;
        }
        
        // B.L. (decimal)
        if (index < dataParts.length) {
            item.bl = parseFloat(dataParts[index]) || 0;
            index++;
        }
        
        // V/v (%) (decimal)
        if (index < dataParts.length) {
            item.vv = parseFloat(dataParts[index]) || 0;
            index++;
        }
        
        // Total Bottles (integer)
        if (index < dataParts.length) {
            item.totBott = parseInt(dataParts[index]) || 0;
        }
        
        // Set default values for missing fields
        item.freeCases = item.freeCases || 0;
        item.freeBottles = item.freeBottles || 0;
        item.caseRate = item.caseRate || 0;
    } else {
        return parseSCMLineAlternative(line);
    }
    
    // If we couldn't parse properly with the above method, try alternative parsing
    if (!item.scmCode || !item.size) {
        return parseSCMLineAlternative(line);
    }
    
    return item;
  }

  function parseSCMLineAlternative(line) {
    const item = {};
    
    // Extract SCM Code
    const scmCodeMatch = line.match(/SCM Code:\s*(\S+)/i);
    if (scmCodeMatch) {
        item.scmCode = scmCodeMatch[1];
    }
    
    // Remove SCM Code part to parse the rest
    const remainingLine = line.replace(/SCM Code:\s*\S+/i, '').trim();
    
    // Use regex to extract the main data components
    const dataMatch = remainingLine.match(/(.+?)\s+(\d+(?:\.\d+)?)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+)/);
    
    if (dataMatch) {
        item.brandName = dataMatch[1].trim();
        item.cases = parseFloat(dataMatch[2]) || 0;
        item.bottles = parseInt(dataMatch[3]) || 0;
        item.batchNo = dataMatch[4];
        item.autoBatch = dataMatch[5];
        item.mfgMonth = dataMatch[6];
        item.mrp = parseFloat(dataMatch[7]) || 0;
        item.bl = parseFloat(dataMatch[8]) || 0;
        item.vv = parseFloat(dataMatch[9]) || 0;
        item.totBott = parseInt(dataMatch[10]) || 0;
        
        // Extract size from brand name if possible
        const sizeMatch = item.brandName.match(/(\d+\s*ML|\d+\s*L)$/i);
        if (sizeMatch) {
            item.size = sizeMatch[1];
            item.brandName = item.brandName.replace(sizeMatch[0], '').trim();
        }
    }
    
    // Set default values
    item.freeCases = item.freeCases || 0;
    item.freeBottles = item.freeBottles || 0;
    item.caseRate = item.caseRate || 0;
    
    return item;
  }

  function calculateAmount(cases, individualBottles, caseRate, bottlesPerCase) {
    // Handle invalid inputs
    if (bottlesPerCase <= 0) bottlesPerCase = 1;
    if (caseRate < 0) caseRate = 0;
    cases = Math.max(0, cases || 0);
    individualBottles = Math.max(0, individualBottles || 0);
    
    // Calculate total amount for full cases
    const fullCaseAmount = cases * caseRate;
    
    // Calculate rate per individual bottle (case rate divided by bottles per case)
    const bottleRate = caseRate / bottlesPerCase;
    
    // Calculate amount for individual bottles
    const individualBottleAmount = individualBottles * bottleRate;
    
    return fullCaseAmount + individualBottleAmount;
  }

  function calculateTradeDiscount() {
    let totalTradeDiscount = 0;
    
    // Calculate trade discount from free cases and free bottles
    $('.item-row').each(function() {
      const row = $(this);
      const freeCases = parseFloat(row.find('.free-cases').val()) || 0;
      const freeBottles = parseFloat(row.find('.free-bottles').val()) || 0;
      const caseRate = parseFloat(row.find('.case-rate').val()) || 0;
      const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
      
      // Calculate the value of free items (this becomes the trade discount)
      const freeAmount = calculateAmount(freeCases, freeBottles, caseRate, bottlesPerCase);
      totalTradeDiscount += freeAmount;
    });
    
    return totalTradeDiscount;
  }

  function calculateColumnTotals() {
    let totalCases = 0;
    let totalBottles = 0;
    let totalFreeCases = 0;
    let totalFreeBottles = 0;
    let totalBL = 0;
    let totalTotBott = 0;
    
    $('.item-row').each(function() {
      const row = $(this);
      totalCases += parseFloat(row.find('.cases').val()) || 0;
      totalBottles += parseFloat(row.find('.bottles').val()) || 0;
      totalFreeCases += parseFloat(row.find('.free-cases').val()) || 0;
      totalFreeBottles += parseFloat(row.find('.free-bottles').val()) || 0;
      
      // Get BL and Tot Bott values from hidden input fields
      const blValue = parseFloat(row.find('input[name*="[bl]"]').val()) || 0;
      const totBottValue = parseFloat(row.find('input[name*="[tot_bott]"]').val()) || 0;
      
      totalBL += blValue;
      totalTotBott += totBottValue;
    });
    
    return {
      cases: totalCases,
      bottles: totalBottles,
      freeCases: totalFreeCases,
      freeBottles: totalFreeBottles,
      bl: totalBL,
      totBott: totalTotBott
    };
  }

  function updateColumnTotals() {
    const totals = calculateColumnTotals();
    
    // Update the totals row
    $('#totalCases').text(totals.cases.toFixed(2));
    $('#totalBottles').text(totals.bottles.toFixed(0));
    $('#totalFreeCases').text(totals.freeCases.toFixed(2));
    $('#totalFreeBottles').text(totals.freeBottles.toFixed(0));
    $('#totalBL').text(totals.bl.toFixed(2));
    $('#totalTotBott').text(totals.totBott.toFixed(0));
  }

  // Function to calculate B.L. (size in ML * total bottles / 1000)
  function calculateBL(sizeText, totalBottles) {
    if (!sizeText || !totalBottles) return 0;
    
    // Extract numeric value from size (e.g., "500 ML" → 500)
    const sizeMatch = sizeText.match(/(\d+)/);
    if (!sizeMatch) return 0;
    
    const sizeML = parseInt(sizeMatch[1]);
    return (sizeML * totalBottles) / 1000; // Convert to liters
  }

  // Function to calculate total bottles (cases * bottles per case + individual bottles)
  function calculateTotalBottles(cases, bottles, bottlesPerCase) {
    return (cases * bottlesPerCase) + bottles;
  }

  // Function to calculate and update B.L. and Total Bottles for a row
  function updateRowCalculations(row) {
    const cases = parseFloat(row.find('.cases').val()) || 0;
    const bottles = parseFloat(row.find('.bottles').val()) || 0;
    const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
    const size = row.find('input[name*="[size]"]').val() || '';
    
    // Calculate total bottles
    const totalBottles = calculateTotalBottles(cases, bottles, bottlesPerCase);
    
    // Calculate B.L. (size in ML * total bottles / 1000)
    const blValue = calculateBL(size, totalBottles);
    
    // Update the displayed values
    row.find('.tot-bott-value').text(totalBottles);
    row.find('.bl-value').text(blValue.toFixed(2));
    
    // Update hidden fields
    row.find('input[name*="[tot_bott]"]').val(totalBottles);
    row.find('input[name*="[bl]"]').val(blValue.toFixed(2));
  }

  // Function to initialize the size table headers
  function initializeSizeTable() {
    const $headers = $('#sizeHeaders');
    const $values = $('#sizeValues');
    
    $headers.empty();
    $values.empty();
    
    // Group sizes into logical categories for better display
    const smallSizes = distinctSizes.filter(size => size <= 375);
    const mediumSizes = distinctSizes.filter(size => size > 375 && size <= 1000);
    const largeSizes = distinctSizes.filter(size => size > 1000);
    
    // Add headers for small sizes
    smallSizes.forEach(size => {
      $headers.append(`<th>${size} ML</th>`);
      $values.append(`<td id="size-${size}" class="text-center fw-bold">0</td>`);
    });
    
    // Add separator if we have both small and medium sizes
    if (smallSizes.length > 0 && mediumSizes.length > 0) {
      $headers.append('<th class="size-separator">|</th>');
      $values.append('<td class="size-separator">|</td>');
    }
    
    // Add headers for medium sizes
    mediumSizes.forEach(size => {
      $headers.append(`<th>${size} ML</th>`);
      $values.append(`<td id="size-${size}" class="text-center fw-bold">0</td>`);
    });
    
    // Add separator if we have both medium and large sizes
    if (mediumSizes.length > 0 && largeSizes.length > 0) {
      $headers.append('<th class="size-separator">|</th>');
      $values.append('<td class="size-separator">|</td>');
    }
    
    // Add headers for large sizes (display in liters for better readability)
    largeSizes.forEach(size => {
      const sizeInLiters = size >= 1000 ? `${size/1000}L` : `${size}ML`;
      $headers.append(`<th>${sizeInLiters}</th>`);
      $values.append(`<td id="size-${size}" class="text-center fw-bold">0</td>`);
    });
  }

  // Function to calculate bottles by size
  function calculateBottlesBySize() {
    const sizeMap = {};
    
    // Initialize all sizes to 0
    distinctSizes.forEach(size => {
      sizeMap[size] = 0;
    });
    
    $('.item-row').each(function() {
      const row = $(this);
      const sizeText = row.find('input[name*="[size]"]').val() || '';
      const totBott = parseInt(row.find('input[name*="[tot_bott]"]').val()) || 0;
      
      if (sizeText && totBott > 0) {
        // Extract numeric value from size text
        const sizeMatch = sizeText.match(/(\d+)/);
        if (sizeMatch) {
          const sizeValue = parseInt(sizeMatch[1]);
          
          // Find the closest matching size from distinctSizes
          let matchedSize = null;
          let smallestDiff = Infinity;
          
          distinctSizes.forEach(dbSize => {
            const diff = Math.abs(dbSize - sizeValue);
            if (diff < smallestDiff && diff <= 50) { // Allow 50ml tolerance for matching
              smallestDiff = diff;
              matchedSize = dbSize;
            }
          });
          
          if (matchedSize !== null) {
            sizeMap[matchedSize] += totBott;
          } else {
            // If no close match found, check if it's exactly one of our sizes
            if (distinctSizes.includes(sizeValue)) {
              sizeMap[sizeValue] += totBott;
            }
          }
        }
      }
    });
    
    return sizeMap;
  }

  // Function to update bottles by size display
  function updateBottlesBySizeDisplay() {
    const sizeMap = calculateBottlesBySize();
    
    // Update all size values in the table
    distinctSizes.forEach(size => {
      $(`#size-${size}`).text(sizeMap[size] || '0');
    });
  }

function addRow(item){
    // Validate if item is allowed by license
    const dbItem = item.dbItem || findDbItemData(item.name, item.size, item.cleanCode || item.code);
    
    // Skip if item is not in allowed classes
    if (dbItem && allowedClasses.length > 0 && !allowedClasses.includes(dbItem.CLASS)) {
        console.log('Skipping item not allowed by license:', item.name, 'Class:', dbItem.CLASS);
        return; // Skip this item
    }
    
    if($('#noItemsRow').length) $('#noItemsRow').remove();
    
    // Use the database item if available for accurate data
    const bottlesPerCase = dbItem ? parseInt(dbItem.BOTTLE_PER_CASE) || 12 : 12;
    const caseRate = item.caseRate || (dbItem ? parseFloat(dbItem.PPRICE) : 0) || 0;
    const itemCode = dbItem ? dbItem.CODE : (item.cleanCode || item.code || '');
    const itemName = dbItem ? dbItem.DETAILS : (item.name || '');
    const itemSize = dbItem ? dbItem.DETAILS2 : (item.size || '');
    
    const cases = item.cases || 0;
    const bottles = item.bottles || 0;
    const freeCases = item.freeCases || 0;
    const freeBottles = item.freeBottles || 0;
    
    // Use extracted values or calculate if not provided
    const mfgMonth = item.mfgMonth || '';
    const vv = item.vv || 0;
    
    // Calculate total bottles and B.L. (use extracted values if available, otherwise calculate)
    const totalBottles = item.totBott || calculateTotalBottles(cases, bottles, bottlesPerCase);
    const blValue = item.bl || calculateBL(itemSize, totalBottles);
    
    const amount = calculateAmount(cases, bottles, caseRate, bottlesPerCase);
    
    // Use a unique index for each row
    const currentIndex = itemCount;
    
    const r = `
      <tr class="item-row" data-bottles-per-case="${bottlesPerCase}">
        <td>
          <input type="hidden" name="items[${currentIndex}][code]" value="${itemCode}">
          <input type="hidden" name="items[${currentIndex}][name]" value="${itemName}">
          <input type="hidden" name="items[${currentIndex}][size]" value="${itemSize}">
          <input type="hidden" name="items[${currentIndex}][bottles_per_case]" value="${bottlesPerCase}">
          <input type="hidden" name="items[${currentIndex}][batch_no]" value="${item.batchNo || ''}">
          <input type="hidden" name="items[${currentIndex}][auto_batch]" value="${item.autoBatch || ''}">
          <input type="hidden" name="items[${currentIndex}][mfg_month]" value="${mfgMonth}"> <!-- FIXED: Correct field name -->
          <input type="hidden" name="items[${currentIndex}][bl]" value="${blValue}"> <!-- FIXED: Use extracted or calculated BL -->
          <input type="hidden" name="items[${currentIndex}][vv]" value="${vv}">
          <input type="hidden" name="items[${currentIndex}][tot_bott]" value="${totalBottles}"> <!-- FIXED: Use extracted or calculated total bottles -->
          <input type="hidden" name="items[${currentIndex}][free_cases]" value="${freeCases}">
          <input type="hidden" name="items[${currentIndex}][free_bottles]" value="${freeBottles}">
          ${itemCode}
        </td>
        <td>${itemName}</td>
        <td>${itemSize}</td>
        <td><input type="number" class="form-control form-control-sm cases" name="items[${currentIndex}][cases]" value="${cases}" min="0" step="0.01"></td>
        <td><input type="number" class="form-control form-control-sm bottles" name="items[${currentIndex}][bottles]" value="${bottles}" min="0" step="1"></td> <!-- REMOVED max attribute -->
        <td><input type="number" class="form-control form-control-sm free-cases" name="items[${currentIndex}][free_cases]" value="${freeCases}" min="0" step="0.01"></td>
        <td><input type="number" class="form-control form-control-sm free-bottles" name="items[${currentIndex}][free_bottles]" value="${freeBottles}" min="0" step="1"></td> <!-- REMOVED max attribute -->
        <td><input type="number" class="form-control form-control-sm case-rate" name="items[${currentIndex}][case_rate]" value="${caseRate.toFixed(3)}" step="0.001"></td>
        <td class="amount">${amount.toFixed(2)}</td>
        <td><input type="number" class="form-control form-control-sm mrp" name="items[${currentIndex}][mrp]" value="${item.mrp || 0}" step="0.01"></td>
        <td><input type="text" class="form-control form-control-sm batch-no" name="items[${currentIndex}][batch_no]" value="${item.batchNo || ''}"></td>
        <td><input type="text" class="form-control form-control-sm auto-batch" name="items[${currentIndex}][auto_batch]" value="${item.autoBatch || ''}"></td>
        <td><input type="text" class="form-control form-control-sm mfg-month" name="items[${currentIndex}][mfg_month]" value="${mfgMonth}"></td> <!-- FIXED: Visible field -->
        <td class="bl-value">${blValue.toFixed(2)}</td>
        <td><input type="number" class="form-control form-control-sm vv" name="items[${currentIndex}][vv]" value="${vv}" step="0.01"></td>
        <td class="tot-bott-value">${totalBottles}</td>
        <td><button class="btn btn-sm btn-danger remove-item" type="button"><i class="fa-solid fa-trash"></i></button></td>
      </tr>`;
    $('#itemsTable tbody').append(r);
    itemCount++; // Increment after adding the row
    updateTotals();
}
  function updateTotals(){
    let t=0;
    $('.item-row .amount').each(function(){ t += parseFloat($(this).text())||0; });
    $('#totalAmount').text(t.toFixed(2));
    $('input[name="basic_amt"]').val(t.toFixed(2));
    
    // Calculate and update trade discount from free items
    const tradeDiscount = calculateTradeDiscount();
    $('input[name="trade_disc"]').val(tradeDiscount.toFixed(2));
    
    // Update column totals
    updateColumnTotals();
    
    // Update bottles by size display
    updateBottlesBySizeDisplay();
    
    calcTaxes();
  }

  function calcTaxes(){
    const basic = parseFloat($('input[name="basic_amt"]').val())||0;
    const staxp = parseFloat($('input[name="stax_per"]').val())||0;
    const tcsp  = parseFloat($('input[name="tcs_per"]').val())||0;
    const cash  = parseFloat($('input[name="cash_disc"]').val())||0;
    const trade = parseFloat($('input[name="trade_disc"]').val())||0;
    const oct   = parseFloat($('input[name="octroi"]').val())||0;
    const fr    = parseFloat($('input[name="freight"]').val())||0;
    const misc  = parseFloat($('input[name="misc_charg"]').val())||0;
    const stax  = basic * staxp/100, tcs = basic * tcsp/100;
    $('input[name="stax_amt"]').val(stax.toFixed(2));
    $('input[name="tcs_amt"]').val(tcs.toFixed(2));
    const grand = basic + stax + tcs + oct + fr + misc - cash - trade;
    $('input[name="tamt"]').val(grand.toFixed(2));
  }

  // ------- Supplier UI -------
  $('#supplierSelect').on('change', function(){
    const name = $(this).val();
    const code = $(this).find(':selected').data('code') || '';
    if(name){ $('#supplierInput').val(name); $('#supplierCodeHidden').val(code); }
  });

  $('#supplierInput').on('input', function(){
    const q = $(this).val().toLowerCase();
    if(q.length<2){ $('#supplierSuggestions').hide().empty(); return; }
    const list = [];
    <?php foreach($suppliers as $s): ?>
      (function(){
        const nm = '<?=addslashes($s['DETAILS'])?>'.toLowerCase();
        const cd = '<?=addslashes($s['CODE'])?>'.toLowerCase();
        if(nm.includes(q) || cd.includes(q)){
          list.push({name:'<?=addslashes($s['DETAILS'])?>', code:'<?=addslashes($s['CODE'])?>'});
        }
      })();
    <?php endforeach; ?>
    const html = list.map(s=>`<div class="supplier-suggestion" data-code="${s.code}" data-name="${s.name}">${s.name} (${s.code})</div>`).join('');
    $('#supplierSuggestions').html(html).show();
  });

  $(document).on('click','.supplier-suggestion', function(){
    $('#supplierInput').val($(this).data('name'));
    $('#supplierCodeHidden').val($(this).data('code'));
    $('#supplierSuggestions').hide();
  });

  $(document).on('click', function(e){
    if(!$(e.target).closest('.supplier-container').length) $('#supplierSuggestions').hide();
  });

  // ------- Add/Clear Manually -------
  $('#addItem').on('click', ()=>$('#itemModal').modal('show'));

  $('#itemSearch').on('input', function(){
    const v = this.value.toLowerCase();
    $('.item-row-modal').each(function(){
      $(this).toggle($(this).text().toLowerCase().includes(v));
    });
  });

  $(document).on('click','.select-item', function(){
    addRow({
      code: $(this).data('code'),
      name: $(this).data('name'),
      size: $(this).data('size'),
      cases: 0, bottles: 0,
      freeCases: 0, freeBottles: 0,
      caseRate: parseFloat($(this).data('price'))||0,
      mrp: 0,
      vv: 0
    });
    $('#itemModal').modal('hide');
  });

  $('#clearItems').on('click', function(){
    if(confirm('Clear all items?')){
      $('.item-row').remove(); itemCount=0;
      $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>');
      $('#totalAmount').text('0.00');
      $('input[name="basic_amt"]').val('0.00');
      $('input[name="tamt"]').val('0.00');
      $('input[name="trade_disc"]').val('0.00'); // Reset trade discount
      
      // Reset column totals
      $('#totalCases, #totalBottles, #totalFreeCases, #totalFreeBottles, #totalBL, #totalTotBott').text('0');
      
      // Update bottles by size display
      updateBottlesBySizeDisplay();
    }
  });

  // ------- Recalculate on edit -------
  $(document).on('input','.cases,.bottles,.case-rate,.free-cases,.free-bottles', function(){
    const row = $(this).closest('tr');
    const cases = parseFloat(row.find('.cases').val())||0;
    const bottles = parseFloat(row.find('.bottles').val())||0;
    const rate = parseFloat(row.find('.case-rate').val())||0;
    const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
    
    const amount = calculateAmount(cases, bottles, rate, bottlesPerCase);
    row.find('.amount').text(amount.toFixed(2));
    
    // Update B.L. and Total Bottles calculations
    updateRowCalculations(row);
    
    updateTotals();
  });

  $(document).on('click','.remove-item', function(){
    $(this).closest('tr').remove();
    if($('.item-row').length===0){
      $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>');
      $('#totalAmount').text('0.00'); 
      $('input[name="basic_amt"]').val('0.00'); 
      $('input[name="tamt"]').val('0.00');
      $('input[name="trade_disc"]').val('0.00'); // Reset trade discount
      
      // Reset column totals
      $('#totalCases, #totalBottles, #totalFreeCases, #totalFreeBottles, #totalBL, #totalTotBott').text('0');
      
      // Update bottles by size display
      updateBottlesBySizeDisplay();
    } else {
      updateTotals();
    }
  });

  $('input[name="stax_per"],input[name="tcs_per"],input[name="cash_disc"],input[name="trade_disc"],input[name="octroi"],input[name="freight"],input[name="misc_charg"]').on('input', calcTaxes);

  // ------- Paste-from-SCM (Enhanced) -------
  $('#pasteFromSCM').on('click', function(){ 
    $('#scmPasteModal').modal('show'); 
    $('#scmPasteArea').val('').focus(); 
  });

  $('#processSCMData').on('click', function(){
    const scmData = $('#scmPasteArea').val().trim();
    
    if (!scmData) {
        alert('Please paste SCM data first.');
        return;
    }
    
    try {
        const parsedData = parseSCMData(scmData);
        const validationResult = validateSCMItems(parsedData.items);
        
        if (validationResult.missingItems.length > 0) {
            showMissingItemsModal(validationResult.missingItems, validationResult.validItems, parsedData);
        } else {
            processValidSCMItems(validationResult.validItems, parsedData);
            $('#scmPasteModal').modal('hide');
        }
    } catch (error) {
        console.error('Error parsing SCM data:', error);
        alert('Error parsing SCM data: ' + error.message);
    }
  });

  // Continue with found items
  $('#continueWithFoundItems').click(function() {
    const modal = $('#missingItemsModal');
    const validItems = modal.data('validItems');
    const parsedData = modal.data('parsedData');
    
    processValidSCMItems(validItems, parsedData);
    modal.modal('hide');
    $('#scmPasteModal').modal('hide');
  });

  // Arrow navigation between input fields
  $(document).on('keydown', '.cases, .bottles, .free-cases, .free-bottles, .case-rate, .mrp, .batch-no, .auto-batch, .mfg-month, .vv', function(e) {
    const $current = $(this);
    const $row = $current.closest('tr');
    const $allInputs = $row.find('input[type="number"], input[type="text"]');
    const currentIndex = $allInputs.index($current);
    
    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
        e.preventDefault();
        
        // Find the next/previous row
        let $targetRow;
        if (e.key === 'ArrowUp') {
            $targetRow = $row.prev('.item-row');
        } else {
            $targetRow = $row.next('.item-row');
        }
        
        if ($targetRow.length) {
            // Get the same input field in the next/previous row
            const $targetInputs = $targetRow.find('input[type="number"], input[type="text"]');
            if ($targetInputs.length > currentIndex) {
                $targetInputs.eq(currentIndex).focus().select();
            }
        }
    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        // Navigate within the same row
        e.preventDefault();
        
        let $targetInput;
        if (e.key === 'ArrowLeft' && currentIndex > 0) {
            $targetInput = $allInputs.eq(currentIndex - 1);
        } else if (e.key === 'ArrowRight' && currentIndex < $allInputs.length - 1) {
            $targetInput = $allInputs.eq(currentIndex + 1);
        }
        
        if ($targetInput) {
            $targetInput.focus().select();
        }
    }
  });

  // Initialize
  initializeSizeTable();
  if($('.item-row').length===0){
    $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>');
  }else{
    itemCount = $('.item-row').length;
    updateTotals();
  }
});
</script>
</body>
</html>
