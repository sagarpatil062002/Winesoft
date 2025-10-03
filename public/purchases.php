<?php
session_start();

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];

include_once "../config/db.php";

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

// ---- Items (for case rate lookup & modal) ----
$items = [];
$itemsStmt = $conn->prepare(
  "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.ITEM_GROUP, im.LIQ_FLAG,
          COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE,
          CONCAT('SCM', im.CODE) AS SCM_CODE
     FROM tblitemmaster im
     LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
    WHERE im.LIQ_FLAG = ?
 ORDER BY im.DETAILS"
);
$itemsStmt->bind_param("s", $mode);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
if ($itemsResult) $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

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
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <h4 class="mb-3">New Purchase - <?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?></h4>

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

<!-- PASTE MODAL -->
<div class="modal fade" id="pasteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Paste SCM Data</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info">
        <div><strong>How to paste:</strong> copy the table section (with headers) + the header area from SCM and paste below.</div>
        <pre class="bg-light p-2 mt-2 small mb-0">STNO  ItemName     Size   Qty (Cases)  Qty (Bottles)  Batch No  Auto Batch  Mfg. Month  MRP  B.L.  V/v (%)  Tot. Bott.
1     Deejay Doctor Brandy  180 ML  7.00  0  271  BT944-220225/920  Mar-2025  110.00  82.08  25.0  456
SCM Code:SCMPL001186</pre>
      </div>
      <textarea class="form-control" id="scmData" rows="12" placeholder="Paste here..."></textarea>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary" type="button" id="processSCMData"><i class="fa-solid fa-gears"></i> Process Data</button>
        <button class="btn btn-secondary" type="button" data-bs-dismiss="modal"><i class="fa-solid fa-xmark"></i> Cancel</button>
      </div>
    </div>
  </div></div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
  let itemCount = 0;
  const dbItems = <?=json_encode($items, JSON_UNESCAPED_UNICODE)?>; // for matching
  const suppliers = <?=json_encode($suppliers, JSON_UNESCAPED_UNICODE)?>; // for supplier matching
  const distinctSizes = <?=json_encode($distinctSizes, JSON_UNESCAPED_UNICODE)?>; // from database

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
        // 2. Base name match
        else if (supplierBase === parsedBase && supplierBase.length > 0) {
            score = 95;
        }
        // 3. Contains match
        else if (supplierName.includes(parsedClean) || parsedClean.includes(supplierName)) {
            score = 80;
        }
        // 4. Base name contains match
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
    
    console.log("Supplier match:", parsedName, "→", bestMatch ? bestMatch.DETAILS : "No match", "Score:", bestScore);
    return bestMatch;
  }

  // Improved database item matching function
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
                console.log("Exact code match found:", it.CODE);
                return it;
            }
        }
        
        // 2. Try SCM code match (SCM + code)
        for (const it of dbItems) {
            const scmCode = (it.SCM_CODE || '').toLowerCase().replace(/^scm/, '').trim();
            if (scmCode === cleanCode) {
                console.log("SCM code match found:", it.CODE);
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
        console.log("Best match found:", bestMatch.CODE, "Score:", bestScore);
        return bestMatch;
    }
    
    console.log("No match found for:", {name: cleanName, size: cleanSize, code: cleanCode});
    return null;
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
    if($('#noItemsRow').length) $('#noItemsRow').remove();
    
    // Use the database item if available for accurate data
    const dbItem = item.dbItem || findDbItemData(item.name, item.size, item.cleanCode || item.code);
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

  // ------- Paste-from-SCM -------
  $('#pasteFromSCM').on('click', function(){ $('#pasteModal').modal('show'); $('#scmData').val('').focus(); });

  $('#processSCMData').on('click', function(){
    const raw = ($('#scmData').val()||'').trim();
    if(!raw){ alert('Please paste SCM data first.'); return; }

    try{
      const parsed = parseSCM(raw);
      
      $('#pasteModal').modal('hide');
      alert('Imported '+parsed.items.length+' items.');
    }catch(err){
      console.error(err);
      alert('Could not parse the SCM text. '+err.message);
    }
  });

  // Enhanced parser for SCM code lines
  function parseSCMItemLine(scmLine) {
    // Pattern to match SCM code lines with variable spacing
    const pattern = /SCM Code:(\S+)\s+([\w\s\(\)\-\.']+)\s+([\d\.]+)\s+([\d\.]+)\s+([\w\-]+)\s+([\w\-\/]+)\s+([\w\-]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)/i;
    const match = scmLine.match(pattern);
    
    if (match) {
        return {
            itemCode: match[1].replace("SCM", ""),
            name: match[2].trim(),
            size: match[2].trim(),
            cases: parseFloat(match[3]),
            bottles: parseInt(match[4]),
            batchNo: match[5],
            autoBatch: match[6],
            mfgMonth: match[7],  // This should be properly extracted
            mrp: parseFloat(match[8]),
            bl: parseFloat(match[9]),  // This should be properly extracted
            vv: parseFloat(match[10]),
            totBott: parseInt(match[11]),  // This should be properly extracted
            freeCases: 0,
            freeBottles: 0
        };
    }
    
    // Add debug logging to see what's being parsed
    console.log("SCM Line being parsed:", scmLine);
    
    // Fallback parsing...
    const parts = scmLine.split(/\s+/);
    if (parts.length < 11) {
        console.warn("Not enough parts in SCM line:", scmLine);
        return null;
    }
  
    // Find where the size field ends (before the first numeric value)
    let sizeEndIndex = 2; // Start after "SCM Code:XXXXX"
    while (sizeEndIndex < parts.length && isNaN(parseFloat(parts[sizeEndIndex]))) {
        sizeEndIndex++;
    }
    
    const sizeParts = parts.slice(2, sizeEndIndex);
    const size = sizeParts.join(' ');
    const numericParts = parts.slice(sizeEndIndex);
    
    // Ensure we have enough numeric parts
    if (numericParts.length < 9) {
        console.warn("Not enough numeric parts in SCM line:", scmLine);
        return null;
    }
    
    return {
        itemCode: parts[1].replace("SCM", ""),
        name: "", // We'll need to extract this from the previous line
        size: size,
        cases: parseFloat(numericParts[0]) || 0,
        bottles: parseInt(numericParts[1]) || 0,
        batchNo: numericParts[2] || '',
        autoBatch: numericParts[3] || '',
        mfgMonth: numericParts[4] || '',
        mrp: parseFloat(numericParts[5]) || 0,
        bl: parseFloat(numericParts[6]) || 0,
        vv: parseFloat(numericParts[7]) || 0,
        totBott: parseInt(numericParts[8]) || 0,
        freeCases: 0,
        freeBottles: 0
    };
  }

  // Main SCM parsing function
  function parseSCM(text) {
    const lines = text.split(/\r?\n/).map(l => l.replace(/\u00A0/g, ' ').trim()).filter(l => l !== '');
    const out = { 
      receivedDate: '', 
      autoTpNo: '', 
      manualTpNo: '', 
      tpDate: '', 
      party: '', 
      items: [] 
    };

    // Parse header information
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      
      // Received Date
      if (/Received\s*Date/i.test(line)) {
        const nextLine = (lines[i + 1] || '').trim();
        if (nextLine) {
          const ymdDate = ymdFromDmyText(nextLine);
          out.receivedDate = ymdDate || nextLine;
          if (ymdDate) {
            $('input[name="date"]').val(ymdDate);
          }
        }
      }
      
      // Auto T.P. No
      if (/Auto\s*T\.\s*P\.\s*No:/i.test(line)) {
        const nextLine = (lines[i + 1] || '').trim();
        if (nextLine && !/T\.?P\.?Date/i.test(nextLine)) {
          out.autoTpNo = nextLine;
          $('#autoTpNo').val(nextLine); // Populate the Auto TP No field
        }
      }
      
      // Manual T.P. No
      if (/T\.\s*P\.\s*No\(Manual\):/i.test(line)) {
        const nextLine = (lines[i + 1] || '').trim();
        if (nextLine && !/T\.?P\.?Date/i.test(nextLine)) {
          out.manualTpNo = nextLine;
          $('#tpNo').val(nextLine);
        }
      }
      
      // T.P. Date
      if (/T\.?P\.?Date:/i.test(line)) {
        const nextLine = (lines[i + 1] || '').trim();
        const ymdDate = ymdFromDmyText(nextLine);
        if (ymdDate) {
          out.tpDate = ymdDate;
          $('#tpDate').val(ymdDate);
        }
      }
      
      // Party (Supplier)
      if (/^Party\s*:/i.test(line)) {
        const nextLine = (lines[i + 1] || '').trim();
        if (nextLine) {
          out.party = nextLine;
          
          // Try to find the best supplier match
          const supplierMatch = findBestSupplierMatch(nextLine);
          if (supplierMatch) {
            $('#supplierInput').val(supplierMatch.DETAILS);
            $('#supplierCodeHidden').val(supplierMatch.CODE);
          } else {
            $('#supplierInput').val(nextLine);
          }
        }
      }
      
      // Item lines (SCM Code)
      if (line.startsWith("SCM Code:")) {
        const item = parseSCMItemLine(line);
        if (item) {
          // Clean the item code
          item.cleanCode = cleanItemCode(item.itemCode);
          
          // Find matching database item
          item.dbItem = findDbItemData("", item.size, item.cleanCode);
          
          // Add to items list
          out.items.push(item);
        }
      }
    }
    
    // Add all parsed items to the table
    $('.item-row').remove(); 
    itemCount = 0;
    if ($('#noItemsRow').length) $('#noItemsRow').remove();
    
    if (out.items.length === 0) {
      $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>');
    } else {
      out.items.forEach(item => {
        addRow(item);
      });
    }
    
    // UPDATE BOTTLES BY SIZE DISPLAY AFTER ADDING SCM ITEMS
    updateBottlesBySizeDisplay();
    updateTotals();
    
    return out;
  }

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