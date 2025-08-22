<?php
session_start();

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { header("Location: index.php"); exit; }

include_once "../config/db.php";

// ---- Mode: F (Foreign) / C (Country) ----
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// ---- Next Voucher No. ----
$vocQuery  = "SELECT MAX(VOC_NO) AS MAX_VOC FROM tblPurchases";
$vocResult = $conn->query($vocQuery);
$maxVoc    = $vocResult ? $vocResult->fetch_assoc() : ['MAX_VOC'=>0];
$nextVoc   = intval($maxVoc['MAX_VOC']) + 1;

// ---- Items (for case rate lookup & modal) ----
$items = [];
$itemsStmt = $conn->prepare(
  "SELECT CODE, DETAILS, DETAILS2, PPRICE
     FROM tblitemmaster
    WHERE LIQ_FLAG = ?
 ORDER BY DETAILS"
);
$itemsStmt->bind_param("s", $mode);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
if ($itemsResult) $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// ---- Suppliers (for name/code replacement) ----
$suppliers = [];
$suppliersRes = $conn->query("SELECT CODE, DETAILS FROM tblsupplier ORDER BY DETAILS");
if ($suppliersRes) $suppliers = $suppliersRes->fetch_all(MYSQLI_ASSOC);

// ---- Save (stub – wire to your exact schema) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // TODO: validate & insert header to tblPurchases + items to detail table.
  // This file focuses on parsing & mapping the SCM → UI exactly as requested.
  header("Location: purchase_module.php?mode=".$mode);
  exit;
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

      <div class="alert alert-info">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="fa-solid fa-paste"></i>
          <strong>Paste from SCM System</strong>
        </div>
        <div class="small-help mb-2">
          Copy the table (including headers) from the SCM retailer screen and paste it.  
          The parser understands the two-line “SCM Code:” rows automatically.
        </div>
        <button id="pasteFromSCM" class="btn btn-primary btn-sm">
          <i class="fa-solid fa-clipboard"></i> Paste SCM Data
        </button>
      </div>

      <form method="POST" id="purchaseForm">
        <input type="hidden" name="mode" value="<?=htmlspecialchars($mode)?>">

        <!-- HEADER -->
        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-receipt me-2"></i>Purchase Information</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label">Voucher No.</label>
                <input class="form-control" value="<?=$nextVoc?>" disabled>
                <input type="hidden" name="voc_no" value="<?=$nextVoc?>">
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
          <thead><tr><th>Code</th><th>Item</th><th>Size</th><th>Price</th><th>Action</th></tr></thead>
          <tbody id="itemsModalTable">
          <?php foreach($items as $it): ?>
            <tr class="item-row-modal">
              <td><?=htmlspecialchars($it['CODE'])?></td>
              <td><?=htmlspecialchars($it['DETAILS'])?></td>
              <td><?=htmlspecialchars($it['DETAILS2'])?></td>
              <td><?=number_format((float)$it['PPRICE'],3)?></td>
              <td><button type="button" class="btn btn-sm btn-primary select-item"
                  data-code="<?=htmlspecialchars($it['CODE'])?>"
                  data-name="<?=htmlspecialchars($it['DETAILS'])?>"
                  data-size="<?=htmlspecialchars($it['DETAILS2'])?>"
                  data-price="<?=htmlspecialchars($it['PPRICE'])?>">Select</button></td>
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
<script>
$(function(){
  let itemCount = 0;
  const dbItems = <?=json_encode($items, JSON_UNESCAPED_UNICODE)?>; // for matching

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
  function findDbItemPrice(name, size){
    const n = (name||'').toLowerCase().replace(/\s+/g,' ').trim();
    const sz = (size||'').toLowerCase();
    let best = null;
    for(const it of dbItems){
      const nm = (it.DETAILS||'').toLowerCase();
      if(nm && (n.includes(nm) || nm.includes(n))){
        // soft size check
        if(!size || (it.DETAILS2||'').toLowerCase().includes(sz) || sz.includes((it.DETAILS2||'').toLowerCase())){
          best = it;
          break;
        }
      }
    }
    return best ? parseFloat(best.PPRICE||0) : 0;
  }
  function addRow(item){
    if($('#noItemsRow').length) $('#noItemsRow').remove();
    const amount = (item.cases*item.caseRate) + (item.bottles*item.mrp);
    const r = `
      <tr class="item-row">
        <td>
          <input type="hidden" name="items[${itemCount}][code]" value="${item.code||''}">
          ${item.code||''}
        </td>
        <td>${item.name||''}</td>
        <td>${item.size||''}</td>
        <td><input type="number" class="form-control form-control-sm cases" name="items[${itemCount}][cases]" value="${item.cases||0}" min="0" step="0.01"></td>
        <td><input type="number" class="form-control form-control-sm bottles" name="items[${itemCount}][bottles]" value="${item.bottles||0}" min="0" step="1" max="11"></td>
        <td><input type="number" class="form-control form-control-sm case-rate" name="items[${itemCount}][case_rate]" value="${(item.caseRate||0).toFixed(3)}" step="0.001"></td>
        <td class="amount">${amount.toFixed(2)}</td>
        <td><input type="number" class="form-control form-control-sm mrp" name="items[${itemCount}][mrp]" value="${item.mrp||0}" step="0.01"></td>
        <td><button class="btn btn-sm btn-danger remove-item" type="button"><i class="fa-solid fa-trash"></i></button></td>
      </tr>`;
    $('#itemsTable tbody').append(r);
    itemCount++;
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
    const mrp = parseFloat(row.find('.mrp').val())||0;
    const amt = (cases*rate) + (bottles*mrp);
    row.find('.amount').text(amt.toFixed(2));
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
      // Fill header bits
      if(parsed.receivedDate){ $('input[name="date"]').val(parsed.receivedDate); }
      if(parsed.tpNo){ $('#tpNo').val(parsed.tpNo); }
      if(parsed.tpDate){ $('#tpDate').val(parsed.tpDate); }
      if(parsed.party){ $('#supplierInput').val(parsed.party); } // supplier name not code
      $('#supplierCodeHidden').val(''); // keep code blank unless user picks

      // Fill items
      $('.item-row').remove(); itemCount=0;
      if($('#noItemsRow').length) $('#noItemsRow').remove();
      if(parsed.items.length===0){ $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="9" class="text-center text-muted">No items added</td></tr>'); }
      parsed.items.forEach(it=>addRow(it));

      $('#pasteModal').modal('hide');
      alert('Imported '+parsed.items.length+' items.');
    }catch(err){
      console.error(err);
      alert('Could not parse the SCM text. '+err.message);
    }
  });

  // Core parser – tuned for your SCM export
function parseSCM(text){
  const lines = text.split(/\r?\n/).map(l=>l.replace(/\u00A0/g,' ').trim()).filter(l=>l!=='');
  const out = { tpNo:'', tpDate:'', receivedDate:'', party:'', items:[] };

  // --- Header fields ---
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

  // --- Table start ---
  let start = -1;
  for(let i=0;i<lines.length;i++){
    if(/Sr.?No/i.test(lines[i]) && /ItemName/i.test(lines[i]) && /Qty/i.test(lines[i])){
      start = i+1; break;
    }
  }
  if(start === -1) return out;

  // --- Table rows ---
  for(let i=start;i<lines.length;i++){
    let L = lines[i];

    // Stop at totals/footer
    if(/^Total/i.test(L) || /^$/.test(L)) break;

    // First line: SrNo + ItemName
    const firstLine = L;
    const srMatch = firstLine.match(/^\d+\s+(.*)$/);
    if(srMatch){
      const itemName = srMatch[1].trim();

      // Second line must be SCM Code line
      const second = (lines[i+1]||'').trim();
      if(second.startsWith("SCM Code:")){
        const parts = second.split(/\s+/);

        // Example second line: "SCM Code:SCMKB0018204 650 ML 5.00 0 36 BTOS70-20 Jun-2025 195.00 39.00 8.0 60"
        const size    = parts[2] + " " + parts[3];
        const cases   = parseFloat(parts[4])||0;
        const bottles = parseInt(parts[5])||0;
        const mrp     = parseFloat(parts[9])||0;
        const caseRate= findDbItemPrice(itemName, size);

        out.items.push({
          code: "", // can be looked up from dbItems if needed
          name: itemName,
          size: size,
          cases: cases,
          bottles: bottles,
          caseRate: caseRate,
          mrp: mrp
        });

        i++; // skip the SCM Code line
      }
    }
  }

  return out;
}

});
</script>
</body>
</html>
