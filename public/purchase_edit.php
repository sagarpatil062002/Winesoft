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
<style>
    .form-label { font-weight: 500; }
    .required-field::after { content: " *"; color: red; }
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  
  <div class="main-content">
    <?php include 'components/header.php'; ?>
    
    <div class="content-area p-3 p-md-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4>Edit Purchase Voucher #<?=$purchase['VOC_NO']?></h4>
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

        <form action="purchase_update.php" method="POST">
            <input type="hidden" name="purchase_id" value="<?=$purchaseId?>">
            <input type="hidden" name="mode" value="<?=$mode?>">
            
            <div class="card mb-4">
                <div class="card-header"><strong>Purchase Information</strong></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label required-field">Date</label>
                            <input type="date" class="form-control" name="date" value="<?=$date?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label required-field">Voucher No</label>
                            <input type="text" class="form-control" name="voc_no" value="<?=$purchase['VOC_NO']?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Invoice No</label>
                            <input type="text" class="form-control" name="inv_no" value="<?=$purchase['INV_NO']?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Invoice Date</label>
                            <input type="date" class="form-control" name="inv_date" value="<?=$inv_date?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">T.P. No</label>
                            <input type="text" class="form-control" name="tp_no" value="<?=$purchase['TPNO']?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">T.P. Date</label>
                            <input type="date" class="form-control" name="tp_date" value="<?=$tp_date?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Supplier</label>
                            <input type="text" class="form-control" value="<?=$purchase['supplier_name']?>" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Purchase Items</strong>
                    <button type="button" class="btn btn-sm btn-success" id="addItem">
                        <i class="fa-solid fa-plus me-1"></i> Add Item
                    </button>
                </div>
                <div class="card-body">
                    <div id="itemsContainer">
                        <?php foreach($items as $index => $item): ?>
                        <div class="item-row border-bottom pb-3 mb-3">
                            <input type="hidden" name="item_id[]" value="<?=$item['DetailID']?>">
                            <div class="row">
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">Item Code</label>
                                    <input type="text" class="form-control" name="item_code[]" value="<?=$item['ItemCode']?>">
                                </div>
                                <div class="col-md-2 mb-2">
                                    <label class="form-label">Item Name</label>
                                    <input type="text" class="form-control" name="item_name[]" value="<?=$item['ItemName']?>">
                                </div>
                                <div class="col-md-1 mb-2">
                                    <label class="form-label">Size</label>
                                    <input type="text" class="form-control" name="size[]" value="<?=$item['Size']?>">
                                </div>
                                <div class="col-md-1 mb-2">
                                    <label class="form-label">Cases</label>
                                    <input type="number" step="0.01" class="form-control" name="cases[]" value="<?=$item['Cases']?>">
                                </div>
                                <div class="col-md-1 mb-2">
                                    <label class="form-label">Bottles</label>
                                    <input type="number" class="form-control" name="bottles[]" value="<?=$item['Bottles']?>">
                                </div>
                                <div class="col-md-1 mb-2">
                                    <label class="form-label">Case Rate</label>
                                    <input type="number" step="0.001" class="form-control" name="case_rate[]" value="<?=$item['CaseRate']?>">
                                </div>
                                <div class="col-md-1 mb-2">
                                    <label class="form-label">MRP</label>
                                    <input type="number" step="0.01" class="form-control" name="mrp[]" value="<?=$item['MRP']?>">
                                </div>
                                <div class="col-md-1 mb-2">
                                    <label class="form-label">Amount</label>
                                    <input type="number" step="0.01" class="form-control" name="amount[]" value="<?=$item['Amount']?>" readonly>
                                </div>
                                <div class="col-md-1 mb-2 d-flex align-items-end">
                                    <button type="button" class="btn btn-sm btn-danger remove-item">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><strong>Charges & Taxes</strong></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Cash Discount</label>
                            <input type="number" step="0.01" class="form-control" name="cash_discount" value="<?=$purchase['CASHDIS']?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Trade Discount</label>
                            <input type="number" step="0.01" class="form-control" name="trade_discount" value="<?=$purchase['SCHDIS']?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Octroi</label>
                            <input type="number" step="0.01" class="form-control" name="octroi" value="<?=$purchase['OCTROI']?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Freight</label>
                            <input type="number" step="0.01" class="form-control" name="freight" value="<?=$purchase['FREIGHT']?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Sales Tax (%)</label>
                            <input type="number" step="0.01" class="form-control" name="stax_per" value="<?=$purchase['STAX_PER']?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Sales Tax Amount</label>
                            <input type="number" step="0.01" class="form-control" name="stax_amt" value="<?=$purchase['STAX_AMT']?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">TCS (%)</label>
                            <input type="number" step="0.01" class="form-control" name="tcs_per" value="<?=$purchase['TCS_PER']?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">TCS Amount</label>
                            <input type="number" step="0.01" class="form-control" name="tcs_amt" value="<?=$purchase['TCS_AMT']?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Misc. Charges</label>
                            <input type="number" step="0.01" class="form-control" name="misc_charges" value="<?=$purchase['MISC_CHARG']?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Total Amount</label>
                            <input type="number" step="0.01" class="form-control" name="total_amt" value="<?=$purchase['TAMT']?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mb-4">
                <button type="submit" class="btn btn-primary me-2">
                    <i class="fa-solid fa-save me-1"></i> Update Purchase
                </button>
                <a href="purchase_module.php?mode=<?=$mode?>" class="btn btn-secondary">
                    <i class="fa-solid fa-times me-1"></i> Cancel
                </a>
            </div>
        </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add new item row
    document.getElementById('addItem').addEventListener('click', function() {
        const container = document.getElementById('itemsContainer');
        const newRow = document.createElement('div');
        newRow.className = 'item-row border-bottom pb-3 mb-3';
        newRow.innerHTML = `
            <div class="row">
                <div class="col-md-2 mb-2">
                    <label class="form-label">Item Code</label>
                    <input type="text" class="form-control" name="new_item_code[]">
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Item Name</label>
                    <input type="text" class="form-control" name="new_item_name[]">
                </div>
                <div class="col-md-1 mb-2">
                    <label class="form-label">Size</label>
                    <input type="text" class="form-control" name="new_size[]">
                </div>
                <div class="col-md-1 mb-2">
                    <label class="form-label">Cases</label>
                    <input type="number" step="0.01" class="form-control" name="new_cases[]">
                </div>
                <div class="col-md-1 mb-2">
                    <label class="form-label">Bottles</label>
                    <input type="number" class="form-control" name="new_bottles[]">
                </div>
                <div class="col-md-1 mb-2">
                    <label class="form-label">Case Rate</label>
                    <input type="number" step="0.001" class="form-control" name="new_case_rate[]">
                </div>
                <div class="col-md-1 mb-2">
                    <label class="form-label">MRP</label>
                    <input type="number" step="0.01" class="form-control" name="new_mrp[]">
                </div>
                <div class="col-md-1 mb-2">
                    <label class="form-label">Amount</label>
                    <input type="number" step="0.01" class="form-control" name="new_amount[]" readonly>
                </div>
                <div class="col-md-1 mb-2 d-flex align-items-end">
                    <button type="button" class="btn btn-sm btn-danger remove-item">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
        container.appendChild(newRow);
    });

    // Remove item row
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-item')) {
            const row = e.target.closest('.item-row');
            row.remove();
        }
    });

    // Auto-calculate amount when case rate or cases change
    document.addEventListener('input', function(e) {
        if (e.target.name === 'case_rate[]' || e.target.name === 'cases[]' || 
            e.target.name === 'new_case_rate[]' || e.target.name === 'new_cases[]') {
            
            const row = e.target.closest('.item-row');
            const caseRateInput = row.querySelector('input[name*="case_rate"]');
            const casesInput = row.querySelector('input[name*="cases"]');
            const amountInput = row.querySelector('input[name*="amount"]');
            
            const caseRate = parseFloat(caseRateInput.value) || 0;
            const cases = parseFloat(casesInput.value) || 0;
            
            amountInput.value = (caseRate * cases).toFixed(2);
        }
    });
});
</script>
</body>
</html>