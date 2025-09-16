<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];
$purchaseId = $_GET['id'];
$mode = $_GET['mode'];

include_once "../config/db.php";

// Get purchase header - FIXED: Using ID instead of PurchaseID
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

// Format dates for input fields
$date = isset($purchase['DATE']) ? date('Y-m-d', strtotime($purchase['DATE'])) : '';
$inv_date = isset($purchase['INV_DATE']) && $purchase['INV_DATE'] != '0000-00-00' ? date('Y-m-d', strtotime($purchase['INV_DATE'])) : '';
$tp_date = isset($purchase['TP_DATE']) && $purchase['TP_DATE'] != '0000-00-00' ? date('Y-m-d', strtotime($purchase['TP_DATE'])) : '';

// Get purchase items
$itemsQuery = "SELECT * FROM tblpurchasedetails WHERE PurchaseID = ?";
$itemsStmt = $conn->prepare($itemsQuery);
$itemsStmt->bind_param("i", $purchaseId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$items = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// Get suppliers for dropdown
$suppliers = [];
$suppliersStmt = $conn->prepare("SELECT CODE, DETAILS FROM tblsupplier ORDER BY DETAILS");
$suppliersStmt->execute();
$suppliersResult = $suppliersStmt->get_result();
if ($suppliersResult) $suppliers = $suppliersResult->fetch_all(MYSQLI_ASSOC);
$suppliersStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Purchase - Voucher #<?=$purchase['VOC_NO']?></title>
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

.form-label { 
    font-weight: 500; 
    margin-bottom: 0.3rem;
}
.required-field::after { 
    content: " *"; 
    color: red; 
}

.supplier-container {
    position: relative;
}

.supplier-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ccc;
    border-top: none;
    z-index: 1000;
    max-height: 200px;
    overflow-y: auto;
    display: none;
}

.supplier-suggestion {
    padding: 8px 12px;
    cursor: pointer;
}

.supplier-suggestion:hover {
    background-color: #f0f0f0;
}

.small-help {
    font-size: 0.8rem;
    color: #6c757d;
    margin-top: 0.25rem;
}
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  
  <div class="main-content">
    <?php include 'components/header.php'; ?>
    
    <div class="content-area p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4>Edit Purchase - <?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?> - Voucher #<?=$purchase['VOC_NO']?></h4>
            <a href="purchase_module.php?mode=<?=$mode?>" class="btn btn-secondary">
                <i class="fa-solid fa-arrow-left me-1"></i> Back to List
            </a>
        </div>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2"></i> Error updating purchase. Please try again.
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i> Purchase updated successfully!
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form action="purchase_update.php" method="POST" id="purchaseForm">
            <input type="hidden" name="purchase_id" value="<?=$purchaseId?>">
            <input type="hidden" name="mode" value="<?=$mode?>">
            
            <!-- HEADER -->
            <div class="card mb-4">
                <div class="card-header fw-semibold"><i class="fa-solid fa-receipt me-2"></i>Purchase Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label required-field">Date</label>
                            <input type="date" class="form-control" name="date" value="<?=$date?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-field">Voucher No</label>
                            <input type="text" class="form-control" name="voc_no" value="<?=$purchase['VOC_NO']?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Auto TP No.</label>
                            <input type="text" class="form-control" name="auto_tp_no" value="<?=$purchase['AUTO_TPNO']?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">T.P. No</label>
                            <input type="text" class="form-control" name="tp_no" value="<?=$purchase['TPNO']?>">
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-3">
                            <label class="form-label">T.P. Date</label>
                            <input type="date" class="form-control" name="tp_date" value="<?=$tp_date?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Invoice No</label>
                            <input type="text" class="form-control" name="inv_no" value="<?=$purchase['INV_NO']?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Invoice Date</label>
                            <input type="date" class="form-control" name="inv_date" value="<?=$inv_date?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Supplier</label>
                            <div class="supplier-container">
                                <input type="text" class="form-control" id="supplierInput" value="<?=$purchase['supplier_name']?>" readonly>
                                <input type="hidden" name="supplier_code" value="<?=$purchase['SUBCODE']?>">
                            </div>
                        </div>
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
                                <?php if (count($items) === 0): ?>
                                    <tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>
                                <?php else: ?>
                                    <?php foreach($items as $index => $item): ?>
                                    <tr class="item-row">
                                        <td>
                                            <input type="hidden" name="items[<?=$index?>][detail_id]" value="<?=$item['DetailID']?>">
                                            <input type="hidden" name="items[<?=$index?>][code]" value="<?=$item['ItemCode']?>">
                                            <input type="hidden" name="items[<?=$index?>][name]" value="<?=$item['ItemName']?>">
                                            <input type="hidden" name="items[<?=$index?>][size]" value="<?=$item['Size']?>">
                                            <input type="hidden" name="items[<?=$index?>][bottles_per_case]" value="<?=$item['BottlesPerCase']?>">
                                            <input type="hidden" name="items[<?=$index?>][batch_no]" value="<?=$item['BatchNo']?>">
                                            <input type="hidden" name="items[<?=$index?>][auto_batch]" value="<?=$item['AutoBatch']?>">
                                            <input type="hidden" name="items[<?=$index?>][mfg_month]" value="<?=$item['MfgMonth']?>">
                                            <input type="hidden" name="items[<?=$index?>][bl]" value="<?=$item['BL']?>">
                                            <input type="hidden" name="items[<?=$index?>][vv]" value="<?=$item['VV']?>">
                                            <input type="hidden" name="items[<?=$index?>][tot_bott]" value="<?=$item['TotBott']?>">
                                            <input type="hidden" name="items[<?=$index?>][free_cases]" value="<?=$item['FreeCases']?>">
                                            <input type="hidden" name="items[<?=$index?>][free_bottles]" value="<?=$item['FreeBottles']?>">
                                            <?=$item['ItemCode']?>
                                        </td>
                                        <td><?=$item['ItemName']?></td>
                                        <td><?=$item['Size']?></td>
                                        <td><input type="number" class="form-control form-control-sm cases" name="items[<?=$index?>][cases]" value="<?=$item['Cases']?>" min="0" step="0.01"></td>
                                        <td><input type="number" class="form-control form-control-sm bottles" name="items[<?=$index?>][bottles]" value="<?=$item['Bottles']?>" min="0" step="1"></td>
                                        <td><input type="number" class="form-control form-control-sm free-cases" name="items[<?=$index?>][free_cases]" value="<?=$item['FreeCases']?>" min="0" step="0.01"></td>
                                        <td><input type="number" class="form-control form-control-sm free-bottles" name="items[<?=$index?>][free_bottles]" value="<?=$item['FreeBottles']?>" min="0" step="1"></td>
                                        <td><input type="number" class="form-control form-control-sm case-rate" name="items[<?=$index?>][case_rate]" value="<?=$item['CaseRate']?>" step="0.001"></td>
                                        <td class="amount"><?=number_format($item['Amount'], 2)?></td>
                                        <td><input type="number" class="form-control form-control-sm mrp" name="items[<?=$index?>][mrp]" value="<?=$item['MRP']?>" step="0.01"></td>
                                        <td><?=$item['BatchNo']?></td>
                                        <td><?=$item['AutoBatch']?></td>
                                        <td><?=$item['MfgMonth']?></td>
                                        <td><?=$item['BL']?></td>
                                        <td><?=$item['VV']?></td>
                                        <td><?=$item['TotBott']?></td>
                                        <td><button class="btn btn-sm btn-danger remove-item" type="button"><i class="fa-solid fa-trash"></i></button></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr class="totals-row">
                                    <td colspan="3" class="text-end fw-semibold">Total:</td>
                                    <td id="totalCases" class="fw-semibold"><?=array_sum(array_column($items, 'Cases'))?></td>
                                    <td id="totalBottles" class="fw-semibold"><?=array_sum(array_column($items, 'Bottles'))?></td>
                                    <td id="totalFreeCases" class="fw-semibold"><?=array_sum(array_column($items, 'FreeCases'))?></td>
                                    <td id="totalFreeBottles" class="fw-semibold"><?=array_sum(array_column($items, 'FreeBottles'))?></td>
                                    <td></td>
                                    <td id="totalAmount" class="fw-semibold"><?=number_format(array_sum(array_column($items, 'Amount')), 2)?></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td></td>
                                    <td id="totalBL" class="fw-semibold"><?=array_sum(array_column($items, 'BL'))?></td>
                                    <td></td>
                                    <td id="totalTotBott" class="fw-semibold"><?=array_sum(array_column($items, 'TotBott'))?></td>
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
                        <div class="col-md-3"><label class="form-label">Cash Discount</label><input type="number" step="0.01" class="form-control" name="cash_disc" value="<?=$purchase['CASHDIS']?>"></div>
                        <div class="col-md-3"><label class="form-label">Trade Discount</label><input type="number" step="0.01" class="form-control" name="trade_disc" value="<?=$purchase['SCHDIS']?>"></div>
                        <div class="col-md-3"><label class="form-label">Octroi</label><input type="number" step="0.01" class="form-control" name="octroi" value="<?=$purchase['OCTROI']?>"></div>
                        <div class="col-md-3"><label class="form-label">Freight Charges</label><input type="number" step="0.01" class="form-control" name="freight" value="<?=$purchase['FREIGHT']?>"></div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-3"><label class="form-label">Sales Tax (%)</label><input type="number" step="0.01" class="form-control" name="stax_per" value="<?=$purchase['STAX_PER']?>"></div>
                        <div class="col-md-3"><label class="form-label">Sales Tax Amount</label><input type="number" step="0.01" class="form-control" name="stax_amt" value="<?=$purchase['STAX_AMT']?>" readonly></div>
                        <div class="col-md-3"><label class="form-label">TCS (%)</label><input type="number" step="0.01" class="form-control" name="tcs_per" value="<?=$purchase['TCS_PER']?>"></div>
                        <div class="col-md-3"><label class="form-label">TCS Amount</label><input type="number" step="0.01" class="form-control" name="tcs_amt" value="<?=$purchase['TCS_AMT']?>" readonly></div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-3"><label class="form-label">Misc. Charges</label><input type="number" step="0.01" class="form-control" name="misc_charg" value="<?=$purchase['MISC_CHARG']?>"></div>
                        <div class="col-md-3"><label class="form-label">Basic Amount</label><input type="number" step="0.01" class="form-control" name="basic_amt" value="<?=$purchase['TAMT'] - $purchase['STAX_AMT'] - $purchase['TCS_AMT'] - $purchase['OCTROI'] - $purchase['FREIGHT'] - $purchase['MISC_CHARG'] + $purchase['CASHDIS'] + $purchase['SCHDIS']?>" readonly></div>
                        <div class="col-md-3"><label class="form-label">Total Amount</label><input type="number" step="0.01" class="form-control" name="tamt" value="<?=$purchase['TAMT']?>" readonly></div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Update Purchase</button>
                <a class="btn btn-secondary" href="purchase_module.php?mode=<?=$mode?>"><i class="fa-solid fa-xmark"></i> Cancel</a>
            </div>
        </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
    let itemCount = <?=count($items)?>;
    
    function calculateAmount(cases, individualBottles, caseRate, bottlesPerCase) {
        if (bottlesPerCase <= 0) bottlesPerCase = 1;
        if (caseRate < 0) caseRate = 0;
        cases = Math.max(0, cases || 0);
        individualBottles = Math.max(0, individualBottles || 0);
        
        const fullCaseAmount = cases * caseRate;
        const bottleRate = caseRate / bottlesPerCase;
        const individualBottleAmount = individualBottles * bottleRate;
        
        return fullCaseAmount + individualBottleAmount;
    }

    function calculateTradeDiscount() {
        let totalTradeDiscount = 0;
        
        $('.item-row').each(function() {
            const row = $(this);
            const freeCases = parseFloat(row.find('.free-cases').val()) || 0;
            const freeBottles = parseFloat(row.find('.free-bottles').val()) || 0;
            const caseRate = parseFloat(row.find('.case-rate').val()) || 0;
            const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
            
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
        
        $('#totalCases').text(totals.cases.toFixed(2));
        $('#totalBottles').text(totals.bottles.toFixed(0));
        $('#totalFreeCases').text(totals.freeCases.toFixed(2));
        $('#totalFreeBottles').text(totals.freeBottles.toFixed(0));
        $('#totalBL').text(totals.bl.toFixed(2));
        $('#totalTotBott').text(totals.totBott.toFixed(0));
    }

    function updateTotals(){
        let t=0;
        $('.item-row .amount').each(function(){ t += parseFloat($(this).text())||0; });
        $('#totalAmount').text(t.toFixed(2));
        $('input[name="basic_amt"]').val(t.toFixed(2));
        
        const tradeDiscount = calculateTradeDiscount();
        $('input[name="trade_disc"]').val(tradeDiscount.toFixed(2));
        
        updateColumnTotals();
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

    // ------- Recalculate on edit -------
    $(document).on('input','.cases,.bottles,.case-rate,.mrp,.free-cases,.free-bottles', function(){
        const row = $(this).closest('tr');
        const cases = parseFloat(row.find('.cases').val())||0;
        const bottles = parseFloat(row.find('.bottles').val())||0;
        const rate = parseFloat(row.find('.case-rate').val())||0;
        const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
        
        const amount = calculateAmount(cases, bottles, rate, bottlesPerCase);
        row.find('.amount').text(amount.toFixed(2));
        updateTotals();
    });

    $(document).on('click','.remove-item', function(){
        $(this).closest('tr').remove();
        if($('.item-row').length===0){
            $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>');
            $('#totalAmount').text('0.00'); 
            $('input[name="basic_amt"]').val('0.00'); 
            $('input[name="tamt"]').val('0.00');
            $('input[name="trade_disc"]').val('0.00');
            
            // Reset column totals
            $('#totalCases, #totalBottles, #totalFreeCases, #totalFreeBottles, #totalBL, #totalTotBott').text('0');
        } else {
            updateTotals();
        }
    });

    $('input[name="stax_per"],input[name="tcs_per"],input[name="cash_disc"],input[name="trade_disc"],input[name="octroi"],input[name="freight"],input[name="misc_charg"]').on('input', calcTaxes);

    // Initialize
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