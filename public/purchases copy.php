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

// Function to clean item code by removing SCM prefix
function cleanItemCode($code) {
    return preg_replace('/^SCM/i', '', trim($code));
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
    
    // Insert purchase header with FREIGHT column
    $insertQuery = "INSERT INTO tblpurchases (
        DATE, SUBCODE, VOC_NO, INV_NO, INV_DATE, TAMT, 
        TPNO, SCHDIS, CASHDIS, OCTROI, FREIGHT, STAX_PER, STAX_AMT, 
        TCS_PER, TCS_AMT, MISC_CHARG, PUR_FLAG, CompID
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insertStmt = $conn->prepare($insertQuery);
    $insertStmt->bind_param(
        "ssissdddddddddddsi", // 18 type specifiers for 18 variables
        $date, $supplier_code, $voc_no, $inv_no, $inv_date, $tamt,
        $tp_no, $trade_disc, $cash_disc, $octroi, $freight, $stax_per, $stax_amt,
        $tcs_per, $tcs_amt, $misc_charg, $mode, $companyId
    );
    
    if ($insertStmt->execute()) {
        $purchase_id = $conn->insert_id;
        
        // Insert purchase items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $detailQuery = "INSERT INTO tblpurchasedetails (
                PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, CaseRate, MRP, Amount, BottlesPerCase
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $detailStmt = $conn->prepare($detailQuery);
            
            foreach ($_POST['items'] as $item) {
                $item_code = $item['code'] ?? '';
                $item_name = $item['name'] ?? '';
                $item_size = $item['size'] ?? '';
                $cases = $item['cases'] ?? 0;
                $bottles = $item['bottles'] ?? 0;
                $case_rate = $item['case_rate'] ?? 0;
                $mrp = $item['mrp'] ?? 0;
                $bottles_per_case = $item['bottles_per_case'] ?? 12;
                
                // Calculate amount correctly: cases * case_rate + bottles * (case_rate / bottles_per_case)
                $amount = ($cases * $case_rate) + ($bottles * ($case_rate / $bottles_per_case));
                
                $detailStmt->bind_param(
                    "isssdiddii", // Changed to "i" for BottlesPerCase (integer)
                    $purchase_id, $item_code, $item_name, $item_size,
                    $cases, $bottles, $case_rate, $mrp, $amount, $bottles_per_case
                );
                $detailStmt->execute();
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
  .table-container{overflow-x:auto;max-height:420px}
  table.styled-table{width:100%;border-collapse:collapse}
  .styled-table th,.styled-table td{border:1px solid #e5e7eb;padding:8px 10px}
  .styled-table thead th{position:sticky;top:0;background:#f8fafc;z-index:1}
  #itemsTable td,#itemsTable th{text-align:center;vertical-align:middle}
  #itemsTable td:first-child,#itemsTable th:first-child,
  #itemsTable td:nth-child(2),#itemsTable th:nth-child(2){text-align:left}
  .supplier-container{position:relative}
  .supplier-suggestions{position:absolute;left:0;right:0;top:100%;
    background:#fff;border:1px solid #d1d5db;max-height:220px;overflow:auto;display:none;z-index:10}
  .supplier-suggestion{padding:8px 10px;cursor:pointer}
  .supplier-suggestion:hover{background:#f3f4f6}
  .small-help{font-size:.84rem;color:#6b7280}
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
                <label class="form-label">T.P. No.</label>
                <input type="text" class="form-control" name="tp_no" id="tpNo">
              </div>
              <div class="col-md-3">
                <label class="form-label">T.P. Date</label>
                <input type="date" class="form-control" name="tp_date" id="tpDate">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-3">
                <label class="form-label">Invoice No.</label>
                <input type="text" class="form-control" name="inv_no">
              </div>
              <div class="col-md-3">
                <label class="form-label">Invoice Date</label>
                <input type="date" class="form-control" name="inv_date">
              </div>
              <div class="col-md-6">
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
                    <th>Item Code</th>
                    <th>Brand Name</th>
                    <th>Size</th>
                    <th>Cases</th>
                    <th>Bottles</th>
                    <th>Case Rate</th>
                    <th>Amount</th>
                    <th>MRP</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr id="noItemsRow"><td colspan="9" class="text-center text-muted">No items added</td></tr>
                </tbody>
                <tfoot>
                  <tr>
                    <td colspan="6" class="text-end fw-semibold">Total Amount:</td>
                    <td id="totalAmount">0.00</td>
                    <td colspan="2"></td>
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
        <pre class="bg-light p-2 mt-2 small mb-0">SrNo  ItemName     Size   Qty (Cases)  Qty (Bottles)  Batch No.  MRP
1     Deejay Doctor Brandy  180 ML  7.00  0  271  110.00
SCM Code:SCMPL0011486</pre>
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
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
  let itemCount = 0;
  const dbItems = <?=json_encode($items, JSON_UNESCAPED_UNICODE)?>; // for matching
  const suppliers = <?=json_encode($suppliers, JSON_UNESCAPED_UNICODE)?>; // for supplier matching

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
    
    const parsedClean = parsedName.toLowerCase().replace(/[^a-z0-9]/g, '');
    let bestMatch = null;
    let bestScore = 0;
    
    suppliers.forEach(supplier => {
        const supplierName = (supplier.DETAILS || '').toLowerCase().replace(/[^a-z0-9]/g, '');
        const supplierCode = (supplier.CODE || '').toLowerCase();
        
        // Score based on string similarity
        let score = 0;
        
        // Exact match
        if (supplierName === parsedClean) {
            score = 100;
        }
        // Contains match (high score)
        else if (supplierName.includes(parsedClean) || parsedClean.includes(supplierName)) {
            score = 80;
        }
        // Partial match with common prefix
        else if (supplierName.startsWith(parsedClean.substring(0, 5)) || 
                 parsedClean.startsWith(supplierName.substring(0, 5))) {
            score = 60;
        }
        // Code match (if supplier code is in the name)
        else if (parsedClean.includes(supplierCode) || supplierCode.includes(parsedClean)) {
            score = 70;
        }
        
        // Remove numbers from the end for better matching
        const parsedWithoutNumbers = parsedClean.replace(/\d+$/, '');
        const supplierWithoutNumbers = supplierName.replace(/\d+$/, '');
        
        if (parsedWithoutNumbers === supplierWithoutNumbers) {
            score = Math.max(score, 90);
        }
        
        if (score > bestScore) {
            bestScore = score;
            bestMatch = supplier;
        }
    });
    
    return bestMatch;
  }

  function findDbItemData(name, size, code) {
    const n = (name || '').toLowerCase().replace(/\s+/g, ' ').trim();
    const sz = (size || '').toLowerCase();
    const cd = (code || '').toLowerCase();
    
    // 1. Try exact code match first (with cleaned code)
    if (cd) {
        for (const it of dbItems) {
            if ((it.CODE || '').toLowerCase() === cd) {
                console.log("Exact code match found:", it.CODE);
                return it;
            }
        }
        
        // 2. Try SCM code match (SCM + code)
        for (const it of dbItems) {
            if ((it.SCM_CODE || '').toLowerCase() === cd) {
                console.log("SCM code match found:", it.CODE);
                return it;
            }
        }
    }
    
    // 3. Try name and size match with scoring
    let bestMatch = null;
    let bestScore = 0;
    
    for (const it of dbItems) {
        const dbName = (it.DETAILS || '').toLowerCase();
        const dbSize = (it.DETAILS2 || '').toLowerCase();
        let score = 0;
        
        // Name similarity (higher weight)
        if (dbName && n === dbName) score += 5;
        else if (dbName && n.includes(dbName)) score += 4;
        else if (dbName && dbName.includes(n)) score += 3;
        
        // Size similarity
        if (dbSize && sz === dbSize) score += 3;
        else if (dbSize && sz.includes(dbSize)) score += 2;
        else if (dbSize && dbSize.includes(sz)) score += 1;
        
        if (score > bestScore) {
            bestScore = score;
            bestMatch = it;
        }
    }
    
    if (bestMatch) {
        console.log("Best name/size match found:", bestMatch.CODE, "Score:", bestScore);
        return bestMatch;
    }
    
    console.log("No match found for:", {name: n, size: sz, code: cd});
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

  function addRow(item){
    if($('#noItemsRow').length) $('#noItemsRow').remove();
    
    // Use the database item if available for accurate data
    const dbItem = item.dbItem || findDbItemData(item.name, item.size, item.cleanCode || item.code);
    const bottlesPerCase = dbItem ? parseInt(dbItem.BOTTLE_PER_CASE) || 12 : 12;
    const caseRate = item.caseRate || (dbItem ? parseFloat(dbItem.PPRICE) : 0) || 0;
    const itemCode = dbItem ? dbItem.CODE : (item.cleanCode || item.code || '');
    const itemName = dbItem ? dbItem.DETAILS : (item.name || '');
    const itemSize = dbItem ? dbItem.DETAILS2 : (item.size || '');
    
    const amount = calculateAmount(item.cases, item.bottles, caseRate, bottlesPerCase);
    
    // Use a unique index for each row
    const currentIndex = itemCount;
    
    const r = `
      <tr class="item-row" data-bottles-per-case="${bottlesPerCase}">
        <td>
          <input type="hidden" name="items[${currentIndex}][code]" value="${itemCode}">
          <input type="hidden" name="items[${currentIndex}][name]" value="${itemName}">
          <input type="hidden" name="items[${currentIndex}][size]" value="${itemSize}">
          <input type="hidden" name="items[${currentIndex}][bottles_per_case]" value="${bottlesPerCase}">
          ${itemCode}
        </td>
        <td>${itemName}</td>
        <td>${itemSize}</td>
        <td><input type="number" class="form-control form-control-sm cases" name="items[${currentIndex}][cases]" value="${item.cases||0}" min="0" step="0.01"></td>
        <td><input type="number" class="form-control form-control-sm bottles" name="items[${currentIndex}][bottles]" value="${item.bottles||0}" min="0" step="1" max="${bottlesPerCase - 1}"></td>
        <td><input type="number" class="form-control form-control-sm case-rate" name="items[${currentIndex}][case_rate]" value="${caseRate.toFixed(3)}" step="0.001"></td>
        <td class="amount">${amount.toFixed(2)}</td>
        <td><input type="number" class="form-control form-control-sm mrp" name="items[${currentIndex}][mrp]" value="${item.mrp||0}" step="0.01"></td>
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
      caseRate: parseFloat($(this).data('price'))||0,
      mrp: 0
    });
    $('#itemModal').modal('hide');
  });

  $('#clearItems').on('click', function(){
    if(confirm('Clear all items?')){
      $('.item-row').remove(); itemCount=0;
      $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="9" class="text-center text-muted">No items added</td></tr>');
      $('#totalAmount').text('0.00');
      $('input[name="basic_amt"]').val('0.00');
      $('input[name="tamt"]').val('0.00');
    }
  });

  // ------- Recalculate on edit -------
  $(document).on('input','.cases,.bottles,.case-rate,.mrp', function(){
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
      $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="9" class="text-center text-muted">No items added</td></tr>');
      $('#totalAmount').text('0.00'); $('input[name="basic_amt"]').val('0.00'); $('input[name="tamt"]').val('0.00');
    }else updateTotals();
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

  // Core parser – tuned for your SCM paste format
  function parseSCM(text){
    const lines = text.split(/\r?\n/).map(l=>l.replace(/\u00A0/g,' ').trim()).filter(l=>l!=='');
    const out = { tpNo:'', tpDate:'', receivedDate:'', party:'', items:[] };

    // ---- HEADER extraction ----
    for(let i=0;i<lines.length;i++){
      const L = lines[i];

      if(/Auto\s*T\.\s*P\.\s*No:/i.test(L)){
        const nxt = (lines[i+1]||'').trim();
        if(nxt) out.tpNo = nxt;
      }
      if(/T\.\s*P\.\s*No\(Manual\):/i.test(L)){
        const nxt = (lines[i+1]||'').trim();
        if(nxt && !/T\.?P\.?Date/i.test(nxt)) out.tpNo = nxt;
      }
      if(/T\.?P\.?Date:/i.test(L)){
        const nxt = (lines[i+1]||'').trim();
        const d = ymdFromDmyText(nxt); if(d) out.tpDate = d;
      }
      if(/Received\s*Date/i.test(L)){
        const nxt = (lines[i+1]||'').trim();
        const d = ymdFromDmyText(nxt);
        out.receivedDate = d || nxt;
      }
      if(/^Party\s*:/i.test(L)){
        const nxt = (lines[i+1]||'').trim();
        if(nxt) out.party = nxt;
      }
    }

    // ---- TABLE start ----
    let start = -1;
    for(let i=0;i<lines.length;i++){
      if(/Sr.?No/i.test(lines[i]) && /ItemName/i.test(lines[i]) && /Qty/i.test(lines[i])){
        start = i+1; break;
      }
    }
    if(start === -1) return out;

    // ---- ITEMS ----
    for(let i=start;i<lines.length;i++){
      const first = lines[i];

      // stop at "Total" or footer lines
      if(/^Total/i.test(first)) break;

      const srMatch = first.match(/^(\d+)\s+(.+)$/);
      if(srMatch){
        const itemName = srMatch[2].trim();

        const second = (lines[i+1]||'').trim();
        if(second.startsWith("SCM Code:")){
          const parts = second.split(/\s+/);
          // Example: SCM Code:SCMKB0018204 650 ML 5.00 0 36 BTOS70-20 Jun-2025 195.00 39.00 8.0 60

          const itemCode = parts[1].replace("SCM Code:","").trim();
          const size     = parts[2] + " " + parts[3];
          const cases    = parseFloat(parts[4])||0;
          const bottles  = parseInt(parts[5])||0;
          const mrp      = parseFloat(parts[9])||0;
          
          // Clean the item code and find in database
          const cleanCode = cleanItemCode(itemCode);
          const dbItem = findDbItemData(itemName, size, cleanCode);
          const caseRate = dbItem ? parseFloat(dbItem.PPRICE)||0 : 0;

          out.items.push({
            code: dbItem ? dbItem.CODE : cleanCode,
            name: dbItem ? dbItem.DETAILS : itemName,
            size: dbItem ? dbItem.DETAILS2 : size,
            cases: cases,
            bottles: bottles,
            caseRate: caseRate,
            mrp: mrp,
            cleanCode: cleanCode,
            dbItem: dbItem
          });

          i++; // skip the SCM Code line
        }
      }
    }

    // SUPPLIER MATCHING
    if(out.party){
        const bestSupplier = findBestSupplierMatch(out.party);
        if(bestSupplier){
            $('#supplierInput').val(bestSupplier.DETAILS);
            $('#supplierCodeHidden').val(bestSupplier.CODE);
        } else {
            $('#supplierInput').val(out.party);
            $('#supplierCodeHidden').val('');
        }
    }
    
    // Fill header bits
    if(out.receivedDate){ $('input[name="date"]').val(out.receivedDate); }
    if(out.tpNo){ $('#tpNo').val(out.tpNo); }
    if(out.tpDate){ $('#tpDate').val(out.tpDate); }

    // Fill items
    $('.item-row').remove(); 
    itemCount = 0; // Reset counter
    if($('#noItemsRow').length) $('#noItemsRow').remove();
    if(out.items.length === 0){ 
        $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="9" class="text-center text-muted">No items added</td></tr>'); 
    }
    
    // Add each item individually
    out.items.forEach(it => {
        addRow(it);
    });

    return out;
  }
});
</script>
</body>
</html>