<?php
session_start();

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];

include_once "../config/db.php";

// ---- Mode: F (Foreign) / C (Country) ----
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// ---- Get purchase ID from URL ----
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: purchase_module.php?mode=".$mode);
    exit;
}
$purchaseId = $_GET['id'];

// ---- Fetch existing purchase data ----
$purchaseQuery = "SELECT p.*, s.DETAILS as supplier_name 
                 FROM tblpurchases p 
                 LEFT JOIN tblsupplier s ON p.SUBCODE = s.CODE 
                 WHERE p.ID = ? AND p.CompID = ?";
$purchaseStmt = $conn->prepare($purchaseQuery);
$purchaseStmt->bind_param("ii", $purchaseId, $companyId);
$purchaseStmt->execute();
$purchaseResult = $purchaseStmt->get_result();
$purchase = $purchaseResult->fetch_assoc();
$purchaseStmt->close();

if (!$purchase) {
    header("Location: purchase_module.php?mode=".$mode);
    exit;
}

// ---- Fetch purchase items ----
$itemsQuery = "SELECT * FROM tblpurchasedetails WHERE PurchaseID = ?";
$itemsStmt = $conn->prepare($itemsQuery);
$itemsStmt->bind_param("i", $purchaseId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$existingItems = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

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
function isMonthArchived($conn, $comp_id, $month) {
    $safe_month = str_replace('-', '_', $month);
    $archive_table = "tbldailystock_archive_{$comp_id}_{$safe_month}";
    
    $check_archive_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                           WHERE table_schema = DATABASE() 
                           AND table_name = '$archive_table'";
    $check_result = $conn->query($check_archive_query);
    return $check_result->fetch_assoc()['count'] > 0;
}

// Function to reverse stock (for edit - subtract original purchase)
function reverseStock($itemCode, $cases, $bottles, $freeCases, $freeBottles, $bottlesPerCase, $purchaseDate, $companyId, $conn) {
    // Calculate total bottles to reverse (including free items)
    $totalBottles = (($cases + $freeCases) * $bottlesPerCase) + $bottles + $freeBottles;
    
    // Get day of month from purchase date
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    // Check if this month is archived
    $isArchived = isMonthArchived($conn, $companyId, $monthYear);
    
    if ($isArchived) {
        reverseArchivedMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    } else {
        reverseCurrentMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate, $dayOfMonth, $monthYear);
    }
}

// Function to reverse current month stock
function reverseCurrentMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate, $dayOfMonth, $monthYear) {
    // Reverse tblitem_stock
    $stockColumn = "CURRENT_STOCK" . $companyId;
    $updateItemStockQuery = "UPDATE tblitem_stock 
                            SET $stockColumn = $stockColumn - ? 
                            WHERE ITEM_CODE = ? AND FIN_YEAR = YEAR(?)";
    
    $itemStmt = $conn->prepare($updateItemStockQuery);
    $itemStmt->bind_param("iss", $totalBottles, $itemCode, $purchaseDate);
    $itemStmt->execute();
    $itemStmt->close();
    
    // Reverse daily stock table
    $dailyStockTable = "tbldailystock_" . $companyId;
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    // Update purchase column
    $updateDailyStockQuery = "UPDATE $dailyStockTable 
                             SET $purchaseColumn = $purchaseColumn - ?,
                                 $closingColumn = $closingColumn - ? 
                             WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $dailyStmt = $conn->prepare($updateDailyStockQuery);
    $dailyStmt->bind_param("iiss", $totalBottles, $totalBottles, $monthYear, $itemCode);
    $dailyStmt->execute();
    $dailyStmt->close();
    
    // Update subsequent days' opening and closing balances
    updateSubsequentDays($conn, $dailyStockTable, $monthYear, $itemCode, $dayOfMonth, -$totalBottles);
}

// Function to reverse archived month stock
function reverseArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    $safe_month = str_replace('-', '_', $monthYear);
    $archive_table = "tbldailystock_archive_{$comp_id}_{$safe_month}";
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    $update_query = "UPDATE $archive_table 
                    SET $purchaseColumn = $purchaseColumn - ?, 
                        $closingColumn = $closingColumn - ? 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("iiss", $totalBottles, $totalBottles, $monthYear, $itemCode);
    $update_stmt->execute();
    $update_stmt->close();
}

// Function to update subsequent days after stock change
function updateSubsequentDays($conn, $dailyStockTable, $monthYear, $itemCode, $startDay, $quantityChange) {
    for ($day = $startDay + 1; $day <= 31; $day++) {
        $dayStr = str_pad($day, 2, '0', STR_PAD_LEFT);
        $openingColumn = "DAY_{$dayStr}_OPEN";
        $closingColumn = "DAY_{$dayStr}_CLOSING";
        
        // Update opening (which is previous day's closing)
        $updateQuery = "UPDATE $dailyStockTable 
                       SET $openingColumn = $openingColumn + ?,
                           $closingColumn = $closingColumn + ? 
                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param("iiss", $quantityChange, $quantityChange, $monthYear, $itemCode);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

// Function to update stock after purchase
function updateStock($itemCode, $cases, $bottles, $freeCases, $freeBottles, $bottlesPerCase, $purchaseDate, $companyId, $conn) {
    // Calculate total bottles purchased (including free items)
    $totalBottles = (($cases + $freeCases) * $bottlesPerCase) + $bottles + $freeBottles;
    
    // Get day of month from purchase date
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    // Check if this month is archived
    $isArchived = isMonthArchived($conn, $companyId, $monthYear);
    
    if ($isArchived) {
        updateArchivedMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    } else {
        updateCurrentMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate, $dayOfMonth, $monthYear);
    }
}

// Function to update current month stock
function updateCurrentMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate, $dayOfMonth, $monthYear) {
    // Update tblitem_stock
    $stockColumn = "CURRENT_STOCK" . $companyId;
    $updateItemStockQuery = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $stockColumn) 
                             VALUES (?, YEAR(?), ?) 
                             ON DUPLICATE KEY UPDATE $stockColumn = $stockColumn + ?";
    
    $itemStmt = $conn->prepare($updateItemStockQuery);
    $itemStmt->bind_param("ssii", $itemCode, $purchaseDate, $totalBottles, $totalBottles);
    $itemStmt->execute();
    $itemStmt->close();
    
    // Update daily stock table
    $dailyStockTable = "tbldailystock_" . $companyId;
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
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
        // Update existing record
        $updateDailyStockQuery = "UPDATE $dailyStockTable 
                                 SET $purchaseColumn = $purchaseColumn + ?,
                                     $closingColumn = $closingColumn + ? 
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $dailyStmt = $conn->prepare($updateDailyStockQuery);
        $dailyStmt->bind_param("iiss", $totalBottles, $totalBottles, $monthYear, $itemCode);
    } else {
        // Insert new record
        $updateDailyStockQuery = "INSERT INTO $dailyStockTable 
                                 (STK_MONTH, ITEM_CODE, LIQ_FLAG, $purchaseColumn, $closingColumn) 
                                 VALUES (?, ?, 'F', ?, ?)";
        $dailyStmt = $conn->prepare($updateDailyStockQuery);
        $dailyStmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
    }
    
    $dailyStmt->execute();
    $dailyStmt->close();
    
    // Update subsequent days' opening and closing balances
    updateSubsequentDays($conn, $dailyStockTable, $monthYear, $itemCode, $dayOfMonth, $totalBottles);
}

// Function to update archived month stock
function updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    $safe_month = str_replace('-', '_', $monthYear);
    $archive_table = "tbldailystock_archive_{$comp_id}_{$safe_month}";
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    // Check if record exists in archive table
    $check_query = "SELECT COUNT(*) as count FROM $archive_table 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Update existing record
        $update_query = "UPDATE $archive_table 
                        SET $purchaseColumn = $purchaseColumn + ?, 
                            $closingColumn = $closingColumn + ? 
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iiss", $totalBottles, $totalBottles, $monthYear, $itemCode);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new record
        $insert_query = "INSERT INTO $archive_table 
                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, $purchaseColumn, $closingColumn) 
                        VALUES (?, ?, 'F', ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
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

// ---- Save purchase update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $date = $_POST['date'];
    $voc_no = $_POST['voc_no'];
    $auto_tp_no = $_POST['auto_tp_no'] ?? '';
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
    
    // Start transaction for data consistency
    $conn->begin_transaction();
    
    try {
        // First, reverse stock for all existing items
        foreach ($existingItems as $existingItem) {
            reverseStock(
                $existingItem['ItemCode'],
                $existingItem['Cases'],
                $existingItem['Bottles'],
                $existingItem['FreeCases'],
                $existingItem['FreeBottles'],
                $existingItem['BottlesPerCase'],
                $purchase['DATE'],
                $companyId,
                $conn
            );
        }
        
        // Update purchase header
        $updateQuery = "UPDATE tblpurchases SET
            DATE = ?, SUBCODE = ?, AUTO_TPNO = ?, INV_NO = ?, INV_DATE = ?, TAMT = ?,
            TPNO = ?, TP_DATE = ?, SCHDIS = ?, CASHDIS = ?, OCTROI = ?, FREIGHT = ?, 
            STAX_PER = ?, STAX_AMT = ?, TCS_PER = ?, TCS_AMT = ?, MISC_CHARG = ?, PUR_FLAG = ?
            WHERE ID = ? AND CompID = ?";

        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param(
            "ssssssssddddddddddsii",
            $date, $supplier_code, $auto_tp_no, $inv_no, $inv_date, $tamt,
            $tp_no, $tp_date, $trade_disc, $cash_disc, $octroi, $freight, 
            $stax_per, $stax_amt, $tcs_per, $tcs_amt, $misc_charg, $mode,
            $purchaseId, $companyId
        );
        
        if (!$updateStmt->execute()) {
            throw new Exception("Error updating purchase: " . $updateStmt->error);
        }
        $updateStmt->close();
        
        // Delete existing purchase items
        $deleteQuery = "DELETE FROM tblpurchasedetails WHERE PurchaseID = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bind_param("i", $purchaseId);
        if (!$deleteStmt->execute()) {
            throw new Exception("Error deleting existing items: " . $deleteStmt->error);
        }
        $deleteStmt->close();
        
        // Insert updated purchase items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $detailQuery = "INSERT INTO tblpurchasedetails (
                PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, FreeCases, FreeBottles, 
                CaseRate, MRP, Amount, BottlesPerCase, BatchNo, AutoBatch, MfgMonth, BL, VV, TotBott
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $detailStmt = $conn->prepare($detailQuery);
            
            foreach ($_POST['items'] as $item) {
                $item_code = $item['code'] ?? '';
                $item_name = $item['name'] ?? '';
                $item_size = $item['size'] ?? '';
                $cases = $item['cases'] ?? 0;
                $bottles = $item['bottles'] || 0;
                $free_cases = $item['free_cases'] || 0;
                $free_bottles = $item['free_bottles'] || 0;
                $case_rate = $item['case_rate'] || 0;
                $mrp = $item['mrp'] || 0;
                $bottles_per_case = $item['bottles_per_case'] || 12;
                $batch_no = $item['batch_no'] || '';
                $auto_batch = $item['auto_batch'] || '';
                $mfg_month = $item['mfg_month'] || '';
                $bl = $item['bl'] || 0;
                $vv = $item['vv'] || 0;
                $tot_bott = $item['tot_bott'] || 0;
                
                // Calculate amount correctly
                $amount = ($cases * $case_rate) + ($bottles * ($case_rate / $bottles_per_case));
                
                $detailStmt->bind_param(
                    "isssdddddddisssddi",
                    $purchaseId, $item_code, $item_name, $item_size,
                    $cases, $bottles, $free_cases, $free_bottles, $case_rate, $mrp, $amount, $bottles_per_case,
                    $batch_no, $auto_batch, $mfg_month, $bl, $vv, $tot_bott
                );
                if (!$detailStmt->execute()) {
                    throw new Exception("Error inserting item: " . $detailStmt->error);
                }
                
                // Update stock with new values
                updateStock($item_code, $cases, $bottles, $free_cases, $free_bottles, $bottles_per_case, $date, $companyId, $conn);
            }
            $detailStmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        header("Location: purchase_module.php?mode=".$mode."&success=1");
        exit;
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $errorMessage = "Error updating purchase: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Purchase</title>
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

/* Fixed column widths */
.col-code { width: 150px; }
.col-name { width: 180px; }
.col-size { width: 100px; }
.col-cases { width: 100px; }
.col-bottles { width: 100px; }
.col-free-cases { width: 100px; }
.col-free-bottles { width: 100px; }
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

input.form-control-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
    width: 100%;
    box-sizing: border-box;
}

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
      <h4 class="mb-3">Edit Purchase</h4>

      <?php if (isset($errorMessage)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
      <?php endif; ?>

      <form method="POST" id="purchaseForm">
        <input type="hidden" name="mode" value="<?=htmlspecialchars($mode)?>">
        <input type="hidden" name="voc_no" value="<?=$purchase['VOC_NO']?>">

        <!-- HEADER -->
        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-receipt me-2"></i>Purchase Information</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label">Voucher No.</label>
                <input class="form-control" value="<?=$purchase['VOC_NO']?>" disabled>
              </div>
              <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="date" value="<?=htmlspecialchars($purchase['DATE'])?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Auto TP No.</label>
                <input type="text" class="form-control" name="auto_tp_no" id="autoTpNo" value="<?=htmlspecialchars($purchase['AUTO_TPNO'] ?? '')?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">T.P. No.</label>
                <input type="text" class="form-control" name="tp_no" id="tpNo" value="<?=htmlspecialchars($purchase['TPNO'] ?? '')?>">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-3">
                <label class="form-label">T.P. Date</label>
                <input type="date" class="form-control" name="tp_date" id="tpDate" value="<?=htmlspecialchars($purchase['TP_DATE'] ?? '')?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Invoice No.</label>
                <input type="text" class="form-control" name="inv_no" value="<?=htmlspecialchars($purchase['INV_NO'] ?? '')?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Invoice Date</label>
                <input type="date" class="form-control" name="inv_date" value="<?=htmlspecialchars($purchase['INV_DATE'] ?? '')?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Supplier</label>
                <div class="supplier-container">
                  <input type="text" class="form-control" name="supplier_name" id="supplierInput" 
                         value="<?=htmlspecialchars($purchase['supplier_name'] ?? '')?>" placeholder="e.g., ASIAN TRADERS-5" required>
                  <div class="supplier-suggestions" id="supplierSuggestions"></div>
                </div>
                <select class="form-select mt-1" id="supplierSelect">
                  <option value="">Select Supplier</option>
                  <?php foreach($suppliers as $s): ?>
                    <option value="<?=htmlspecialchars($s['DETAILS'])?>"
                            data-code="<?=htmlspecialchars($s['CODE'])?>"
                            <?=($s['CODE'] == $purchase['SUBCODE']) ? 'selected' : ''?>>
                      <?=htmlspecialchars($s['DETAILS'])?> (<?=htmlspecialchars($s['CODE'])?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="supplier_code" id="supplierCodeHidden" value="<?=htmlspecialchars($purchase['SUBCODE'])?>">
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
                    <th class="col-free-cases">Free Cases</th>
                    <th class="col-free-bottles">Free Bottles</th>
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
                  <?php if (empty($existingItems)): ?>
                    <tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>
                  <?php endif; ?>
                </tbody>
                <tfoot>
                  <tr class="totals-row">
                    <td colspan="3" class="text-end fw-semibold">Total:</td>
                    <td id="totalCases" class="fw-semibold">0.00</td>
                    <td id="totalBottles" class="fw-semibold">0</td>
                    <td id="totalFreeCases" class="fw-semibold">0.00</td>
                    <td id="totalFreeBottles" class="fw-semibold">0</td>
                    <td></td>
                    <td id="totalAmount" class="fw-semibold">0.00</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td id="totalBL" class="fw-semibold">0.00</td>
                    <td></td>
                    <td id="totalTotBott" class="fw-semibold">0</td>
                    <td></td>
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
              <div class="col-md-3">
                <label class="form-label">Trade Discount (%)</label>
                <input type="number" class="form-control" name="trade_disc" id="tradeDisc" step="0.01" value="<?=htmlspecialchars($purchase['SCHDIS'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Cash Discount (%)</label>
                <input type="number" class="form-control" name="cash_disc" id="cashDisc" step="0.01" value="<?=htmlspecialchars($purchase['CASHDIS'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Octroi</label>
                <input type="number" class="form-control" name="octroi" id="octroi" step="0.01" value="<?=htmlspecialchars($purchase['OCTROI'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Freight</label>
                <input type="number" class="form-control" name="freight" id="freight" step="0.01" value="<?=htmlspecialchars($purchase['FREIGHT'] ?? 0)?>">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-3">
                <label class="form-label">S.Tax (%)</label>
                <input type="number" class="form-control" name="stax_per" id="staxPer" step="0.01" value="<?=htmlspecialchars($purchase['STAX_PER'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">S.Tax Amount</label>
                <input type="number" class="form-control" name="stax_amt" id="staxAmt" step="0.01" value="<?=htmlspecialchars($purchase['STAX_AMT'] ?? 0)?>" readonly>
              </div>
              <div class="col-md-3">
                <label class="form-label">TCS (%)</label>
                <input type="number" class="form-control" name="tcs_per" id="tcsPer" step="0.01" value="<?=htmlspecialchars($purchase['TCS_PER'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">TCS Amount</label>
                <input type="number" class="form-control" name="tcs_amt" id="tcsAmt" step="0.01" value="<?=htmlspecialchars($purchase['TCS_AMT'] ?? 0)?>" readonly>
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-3">
                <label class="form-label">Misc. Charges</label>
                <input type="number" class="form-control" name="misc_charg" id="miscCharg" step="0.01" value="<?=htmlspecialchars($purchase['MISC_CHARG'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Basic Amount</label>
                <input type="number" class="form-control" name="basic_amt" id="basicAmt" step="0.01" value="<?=htmlspecialchars($purchase['TAMT'] ?? 0)?>" readonly>
              </div>
              <div class="col-md-3">
                <label class="form-label">Total Amount</label>
                <input type="number" class="form-control" name="tamt" id="tamt" step="0.01" value="<?=htmlspecialchars($purchase['TAMT'] ?? 0)?>" readonly>
              </div>
            </div>
          </div>
        </div>

        <!-- BUTTONS -->
        <div class="d-flex justify-content-between">
          <a href="purchase_module.php?mode=<?=$mode?>" class="btn btn-secondary"><i class="fa-solid fa-arrow-left me-2"></i>Back</a>
          <div>
            <button type="button" class="btn btn-warning" id="resetForm"><i class="fa-solid fa-rotate me-2"></i>Reset</button>
            <button type="submit" class="btn btn-success"><i class="fa-solid fa-floppy-disk me-2"></i>Update Purchase</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
// Global variables
let items = <?= json_encode($items) ?>;
let existingItems = <?= json_encode($existingItems) ?>;
let distinctSizes = <?= json_encode($distinctSizes) ?>;
let itemCounter = 0;

// Initialize the form with existing items
document.addEventListener('DOMContentLoaded', function() {
    // Initialize size table (same as purchases.php)
    initializeSizeTable();
    
    // Add existing items to the table
    if (existingItems.length > 0) {
        existingItems.forEach(item => {
            addItemToTable(item);
        });
        updateTotals();
        updateBottlesBySizeDisplay();
        updateCharges();
    }
    
    // Initialize supplier suggestions
    initSupplierSuggestions();
    
    // Initialize event listeners
    initEventListeners();
});

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
    row.find('input[name*="[totbott]"]').val(totalBottles);
    row.find('input[name*="[bl]"]').val(blValue.toFixed(2));
}

function initializeSizeTable() {
    const $headers = $('#sizeHeaders');
    const $values = $('#sizeValues');
    
    $headers.empty();
    $values.empty();
    
    // Group sizes into logical categories for better display (same as purchases.php)
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

function initSupplierSuggestions() {
    const supplierInput = document.getElementById('supplierInput');
    const supplierSelect = document.getElementById('supplierSelect');
    const supplierSuggestions = document.getElementById('supplierSuggestions');
    const supplierCodeHidden = document.getElementById('supplierCodeHidden');
    
    supplierInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        supplierSuggestions.innerHTML = '';
        
        if (searchTerm.length < 2) {
            supplierSuggestions.style.display = 'none';
            return;
        }
        
        const filteredSuppliers = <?= json_encode($suppliers) ?>.filter(s => 
            s.DETAILS.toLowerCase().includes(searchTerm) || 
            s.CODE.toString().includes(searchTerm)
        );
        
        if (filteredSuppliers.length > 0) {
            filteredSuppliers.forEach(s => {
                const div = document.createElement('div');
                div.className = 'suggestion-item';
                div.textContent = `${s.DETAILS} (${s.CODE})`;
                div.addEventListener('click', function() {
                    supplierInput.value = s.DETAILS;
                    supplierCodeHidden.value = s.CODE;
                    supplierSuggestions.style.display = 'none';
                });
                supplierSuggestions.appendChild(div);
            });
            supplierSuggestions.style.display = 'block';
        } else {
            supplierSuggestions.style.display = 'none';
        }
    });
    
    supplierSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption.value) {
            supplierInput.value = selectedOption.value;
            supplierCodeHidden.value = selectedOption.dataset.code;
        }
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!supplierInput.contains(e.target) && !supplierSuggestions.contains(e.target)) {
            supplierSuggestions.style.display = 'none';
        }
    });
}

function initEventListeners() {
    // Add item button
    document.getElementById('addItem').addEventListener('click', function() {
        addItemToTable();
    });
    
    // Clear items button
    document.getElementById('clearItems').addEventListener('click', function() {
        if (confirm('Are you sure you want to clear all items?')) {
            document.querySelectorAll('#itemsTable tbody tr.item-row').forEach(row => {
                row.remove();
            });
            updateTotals();
            updateBottlesBySizeDisplay();
            updateCharges();
        }
    });
    
    // Reset form button
    document.getElementById('resetForm').addEventListener('click', function() {
        if (confirm('Are you sure you want to reset the form? All changes will be lost.')) {
            document.getElementById('purchaseForm').reset();
            document.querySelectorAll('#itemsTable tbody tr.item-row').forEach(row => {
                row.remove();
            });
            existingItems.forEach(item => {
                addItemToTable(item);
            });
            updateTotals();
            updateBottlesBySizeDisplay();
            updateCharges();
        }
    });
    
    // Charges calculation events
    ['tradeDisc', 'cashDisc', 'octroi', 'freight', 'staxPer', 'tcsPer', 'miscCharg'].forEach(id => {
        document.getElementById(id).addEventListener('input', updateCharges);
    });
}

function addItemToTable(existingItem = null) {
    const tbody = document.querySelector('#itemsTable tbody');
    const noItemsRow = document.getElementById('noItemsRow');
    
    if (noItemsRow) noItemsRow.remove();
    
    const row = document.createElement('tr');
    row.className = 'item-row';
    row.dataset.index = itemCounter;
    
    const isNew = !existingItem;
    const itemData = existingItem || {
        ItemCode: '',
        ItemName: '',
        Size: '',
        Cases: 0,
        Bottles: 0,
        FreeCases: 0,
        FreeBottles: 0,
        CaseRate: 0,
        MRP: 0,
        BottlesPerCase: 12,
        BatchNo: '',
        AutoBatch: '',
        MfgMonth: '',
        BL: 0,
        VV: 0,
        TotBott: 0
    };
    
    row.innerHTML = `
        <td>
            <input type="text" class="form-control form-control-sm item-code" name="items[${itemCounter}][code]" 
                   value="${itemData.ItemCode}" required>
            <div class="item-suggestions" id="suggestions_${itemCounter}"></div>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm item-name" name="items[${itemCounter}][name]" 
                   value="${itemData.ItemName}" readonly>
        </td>
        <td>
            <input type="text" class="form-control form-control-sm item-size" name="items[${itemCounter}][size]" 
                   value="${itemData.Size}" readonly>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-cases" name="items[${itemCounter}][cases]" 
                   value="${itemData.Cases}" step="0.01" min="0">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-bottles" name="items[${itemCounter}][bottles]" 
                   value="${itemData.Bottles}" step="1" min="0" max="${itemData.BottlesPerCase - 1}">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-free-cases" name="items[${itemCounter}][free_cases]" 
                   value="${itemData.FreeCases}" step="0.01" min="0">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-free-bottles" name="items[${itemCounter}][free_bottles]" 
                   value="${itemData.FreeBottles}" step="1" min="0" max="${itemData.BottlesPerCase - 1}">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-case-rate" name="items[${itemCounter}][case_rate]" 
                   value="${itemData.CaseRate}" step="0.01" min="0">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-amount" name="items[${itemCounter}][amount]" 
                   value="${itemData.Amount || 0}" step="0.01" readonly>
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-mrp" name="items[${itemCounter}][mrp]" 
                   value="${itemData.MRP}" step="0.01" min="0">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm item-batch" name="items[${itemCounter}][batch_no]" 
                   value="${itemData.BatchNo}">
        </td>
        <td>
            <input type="text" class="form-control form-control-sm item-auto-batch" name="items[${itemCounter}][auto_batch]" 
                   value="${itemData.AutoBatch}">
        </td>
        <td>
            <input type="month" class="form-control form-control-sm item-mfg" name="items[${itemCounter}][mfg_month]" 
                   value="${itemData.MfgMonth}">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-bl" name="items[${itemCounter}][bl]" 
                   value="${itemData.BL}" step="0.01" min="0">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-vv" name="items[${itemCounter}][vv]" 
                   value="${itemData.VV}" step="0.01" min="0">
        </td>
        <td>
            <input type="number" class="form-control form-control-sm item-totbott" name="items[${itemCounter}][totbott]" 
                   value="${itemData.TotBott}" step="1" min="0" readonly>
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-danger remove-item"><i class="fa-solid fa-trash"></i></button>
            <input type="hidden" class="item-bottles-per-case" name="items[${itemCounter}][bottles_per_case]" value="${itemData.BottlesPerCase}">
        </td>
    `;
    
    tbody.appendChild(row);
    
    // Initialize item event listeners
    initItemEventListeners(row, itemCounter);
    
    itemCounter++;
    
    if (isNew) {
        updateTotals();
        updateBottlesBySizeDisplay();
        updateCharges();
    }
}

function initItemEventListeners(row, index) {
    const codeInput = row.querySelector('.item-code');
    const nameInput = row.querySelector('.item-name');
    const sizeInput = row.querySelector('.item-size');
    const casesInput = row.querySelector('.item-cases');
    const bottlesInput = row.querySelector('.item-bottles');
    const freeCasesInput = row.querySelector('.item-free-cases');
    const freeBottlesInput = row.querySelector('.item-free-bottles');
    const caseRateInput = row.querySelector('.item-case-rate');
    const amountInput = row.querySelector('.item-amount');
    const mrpInput = row.querySelector('.item-mrp');
    const batchInput = row.querySelector('.item-batch');
    const autoBatchInput = row.querySelector('.item-auto-batch');
    const mfgInput = row.querySelector('.item-mfg');
    const blInput = row.querySelector('.item-bl');
    const vvInput = row.querySelector('.item-vv');
    const totBottInput = row.querySelector('.item-totbott');
    const bottlesPerCaseInput = row.querySelector('.item-bottles-per-case');
    const removeBtn = row.querySelector('.remove-item');
    
    // Item code autocomplete
    codeInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const suggestions = document.getElementById(`suggestions_${index}`);
        suggestions.innerHTML = '';
        
        if (searchTerm.length < 2) {
            suggestions.style.display = 'none';
            return;
        }
        
        const filteredItems = items.filter(item => 
            item.CODE.toLowerCase().includes(searchTerm) || 
            item.DETAILS.toLowerCase().includes(searchTerm) ||
            item.SCM_CODE.toLowerCase().includes(searchTerm)
        );
        
        if (filteredItems.length > 0) {
            filteredItems.forEach(item => {
                const div = document.createElement('div');
                div.className = 'suggestion-item';
                div.textContent = `${item.SCM_CODE} - ${item.DETAILS}`;
                div.addEventListener('click', function() {
                    codeInput.value = item.CODE;
                    nameInput.value = item.DETAILS;
                    
                    // Set size from item group
                    sizeInput.value = item.ITEM_GROUP || '';
                    
                    // Set case rate from purchase price
                    caseRateInput.value = item.PPRICE || 0;
                    
                    // Set bottles per case
                    bottlesPerCaseInput.value = item.BOTTLE_PER_CASE || 12;
                    
                    // Update max for bottles inputs
                    bottlesInput.max = item.BOTTLE_PER_CASE - 1;
                    freeBottlesInput.max = item.BOTTLE_PER_CASE - 1;
                    
                    suggestions.style.display = 'none';
                    calculateItemAmount(row);
                    updateTotals();
                    updateBottlesBySizeDisplay();
                });
                suggestions.appendChild(div);
            });
            suggestions.style.display = 'block';
        } else {
            suggestions.style.display = 'none';
        }
    });
    
    // Hide suggestions when clicking outside
    document.addEventListener('click', function(e) {
        if (!codeInput.contains(e.target)) {
            const suggestions = document.getElementById(`suggestions_${index}`);
            suggestions.style.display = 'none';
        }
    });
    
    // Calculation events
    [casesInput, bottlesInput, freeCasesInput, freeBottlesInput, caseRateInput].forEach(input => {
        input.addEventListener('input', function() {
            calculateItemAmount(row);
            updateTotals();
            updateBottlesBySizeDisplay();
        });
    });
    
    // Remove item
    removeBtn.addEventListener('click', function() {
        if (confirm('Are you sure you want to remove this item?')) {
            row.remove();
            updateTotals();
            updateBottlesBySizeDisplay();
            updateCharges();
        }
    });
}

function calculateItemAmount(row) {
    const cases = parseFloat(row.querySelector('.item-cases').value) || 0;
    const bottles = parseInt(row.querySelector('.item-bottles').value) || 0;
    const caseRate = parseFloat(row.querySelector('.item-case-rate').value) || 0;
    const bottlesPerCase = parseInt(row.querySelector('.item-bottles-per-case').value) || 12;
    
    const bottleRate = caseRate / bottlesPerCase;
    const amount = (cases * caseRate) + (bottles * bottleRate);
    
    row.querySelector('.item-amount').value = amount.toFixed(2);
    
    // Calculate total bottles
    const freeCases = parseFloat(row.querySelector('.item-free-cases').value) || 0;
    const freeBottles = parseInt(row.querySelector('.item-free-bottles').value) || 0;
    const totalBottles = ((cases + freeCases) * bottlesPerCase) + bottles + freeBottles;
    row.querySelector('.item-totbott').value = totalBottles;
}

function updateTotals() {
    let totalCases = 0;
    let totalBottles = 0;
    let totalFreeCases = 0;
    let totalFreeBottles = 0;
    let totalAmount = 0;
    let totalBL = 0;
    let totalTotBott = 0;
    
    document.querySelectorAll('#itemsTable tbody tr.item-row').forEach(row => {
        totalCases += parseFloat(row.querySelector('.item-cases').value) || 0;
        totalBottles += parseInt(row.querySelector('.item-bottles').value) || 0;
        totalFreeCases += parseFloat(row.querySelector('.item-free-cases').value) || 0;
        totalFreeBottles += parseInt(row.querySelector('.item-free-bottles').value) || 0;
        totalAmount += parseFloat(row.querySelector('.item-amount').value) || 0;
        totalBL += parseFloat(row.querySelector('.item-bl').value) || 0;
        totalTotBott += parseInt(row.querySelector('.item-totbott').value) || 0;
    });
    
    document.getElementById('totalCases').textContent = totalCases.toFixed(2);
    document.getElementById('totalBottles').textContent = totalBottles;
    document.getElementById('totalFreeCases').textContent = totalFreeCases.toFixed(2);
    document.getElementById('totalFreeBottles').textContent = totalFreeBottles;
    document.getElementById('totalAmount').textContent = totalAmount.toFixed(2);
    document.getElementById('totalBL').textContent = totalBL.toFixed(2);
    document.getElementById('totalTotBott').textContent = totalTotBott;
    
    // Update basic amount
    document.getElementById('basicAmt').value = totalAmount.toFixed(2);
}

function calculateBottlesBySize() {
    const sizeMap = {};
    
    // Initialize all sizes to 0
    distinctSizes.forEach(size => {
        sizeMap[size] = 0;
    });
    
    $('.item-row').each(function() {
        const row = $(this);
        const sizeText = row.find('input[name*="[size]"]').val() || '';
        const totBott = parseInt(row.find('input[name*="[totbott]"]').val()) || 0;
        
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

function updateBottlesBySizeDisplay() {
    const sizeMap = calculateBottlesBySize();
    
    // Update all size values in the table
    distinctSizes.forEach(size => {
        $(`#size-${size}`).text(sizeMap[size] || '0');
    });
}

function updateCharges() {
    const basicAmt = parseFloat(document.getElementById('basicAmt').value) || 0;
    const tradeDisc = parseFloat(document.getElementById('tradeDisc').value) || 0;
    const cashDisc = parseFloat(document.getElementById('cashDisc').value) || 0;
    const octroi = parseFloat(document.getElementById('octroi').value) || 0;
    const freight = parseFloat(document.getElementById('freight').value) || 0;
    const staxPer = parseFloat(document.getElementById('staxPer').value) || 0;
    const tcsPer = parseFloat(document.getElementById('tcsPer').value) || 0;
    const miscCharg = parseFloat(document.getElementById('miscCharg').value) || 0;
    
    // Calculate discounts
    const tradeDiscAmt = basicAmt * (tradeDisc / 100);
    const cashDiscAmt = basicAmt * (cashDisc / 100);
    
    // Calculate taxable amount
    const taxableAmt = basicAmt - tradeDiscAmt - cashDiscAmt;
    
    // Calculate taxes
    const staxAmt = taxableAmt * (staxPer / 100);
    const tcsAmt = taxableAmt * (tcsPer / 100);
    
    // Calculate total amount
    const totalAmt = taxableAmt + octroi + freight + staxAmt + tcsAmt + miscCharg;
    
    // Update fields
    document.getElementById('staxAmt').value = staxAmt.toFixed(2);
    document.getElementById('tcsAmt').value = tcsAmt.toFixed(2);
    document.getElementById('tamt').value = totalAmt.toFixed(2);
}
</script>
</body>
</html>