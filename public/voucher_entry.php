<?php
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

include_once "../config/db.php";
require_once 'license_functions.php';

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Handle edit/view actions
$action = isset($_GET['action']) ? $_GET['action'] : 'new';
$voucher_id = isset($_GET['id']) ? $_GET['id'] : 0;

// If editing/viewing, fetch voucher data
$voucher_data = null;
if (($action === 'edit' || $action === 'view') && $voucher_id > 0) {
    $query = "SELECT e.*, l.REF_CODE 
              FROM tblexpenses e 
              LEFT JOIN tbllheads l ON e.REF_SAC = l.LCODE 
              WHERE e.VNO = ? AND e.COMP_ID = ? 
              LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $voucher_id, $_SESSION['CompID']);
    $stmt->execute();
    $result = $stmt->get_result();
    $voucher_data = $result->fetch_assoc();
    $stmt->close();
}

// Fetch all ledger data from tbllheads table that have a REF_CODE
$ledgerData = [];
$query = "SELECT LCODE, LHEAD, REF_CODE FROM tbllheads WHERE REF_CODE IS NOT NULL AND REF_CODE != ''";
$stmt = $conn->prepare($query);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $ledgerData[] = $row;
}
$stmt->close();

// Function to generate DOC_NO starting from 1
function generateDocNo($conn, $comp_id, $doc_type) {
    $doc_type = strtoupper($doc_type); // PMT, RCP, CHQ
    
    // Get the last used number for this document type
    $query = "SELECT MAX(CAST(SUBSTRING(DOC_NO, 5) AS UNSIGNED)) as last_num 
              FROM tblexpenses 
              WHERE COMP_ID = ? AND DOC_NO LIKE ?";
    $stmt = $conn->prepare($query);
    
    switch($doc_type) {
        case 'PMT': 
            $like_pattern = 'PMT-%';
            break;
        case 'RCP': 
            $like_pattern = 'RCP-%';
            break;
        case 'CHQ': 
            $like_pattern = 'CHQ-%';
            break;
        default: 
            $like_pattern = 'DOC-%';
    }
    
    $stmt->bind_param("is", $comp_id, $like_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $next_num = ($row['last_num'] ? $row['last_num'] + 1 : 1);
    
    // Format DOC_NO based on type (PMT-000001, RCP-000001, CHQ-000001)
    switch($doc_type) {
        case 'PMT': return 'PMT-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
        case 'RCP': return 'RCP-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
        case 'CHQ': return 'CHQ-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
        default: return 'DOC-' . str_pad($next_num, 6, '0', STR_PAD_LEFT);
    }
}

// Function to get next VNO (find max VNO and increment)
function getNextVNO($conn, $comp_id) {
    $query = "SELECT COALESCE(MAX(VNO), 0) + 1 as next_vno FROM tblexpenses WHERE COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['next_vno'];
}

// Function to get next PAYMENT_SEQ for a given VNO
function getNextPaymentSeq($conn, $vno, $comp_id) {
    $query = "SELECT COALESCE(MAX(PAYMENT_SEQ), 0) + 1 as next_seq FROM tblexpenses WHERE VNO = ? AND COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $vno, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['next_seq'];
}

// Generate initial DOC_NO for new voucher
$initial_doc_no = 'Auto Generated';
if ($action === 'new') {
    $is_payment = true; // Default to payment mode
    $voucher_type = 'C'; // Default to cash
    $initial_doc_no = generateDocNo($conn, $_SESSION['CompID'], $is_payment ? 'PMT' : 'RCP');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= ucfirst($action) ?> Voucher Entry - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>

  <style>
.particulars-input {
    position: relative;
}
.suggestions-box {
    position: absolute;
    background: white;
    border: 1px solid #ccc;
    width: 100%;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
.suggestion-item {
    padding: 8px 12px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
}
.suggestion-item:hover, .suggestion-item.selected {
    background-color: #e3f2fd;
}

/* Pending invoices table styling */
.purchase-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    font-size: 14px;
}

.purchase-table th,
.purchase-table td {
    padding: 8px 12px;
    border: 1px solid #dee2e6;
    text-align: left;
}

.purchase-table th {
    background-color: #f8f9fa;
    font-weight: 600;
    color: #495057;
}

.purchase-table tr:nth-child(even) {
    background-color: #f8f9fa;
}

.purchase-table tr:hover {
    background-color: #e9ecef;
}

.amount-cell {
    text-align: right;
    font-family: monospace;
    padding: 8px 12px;
}

.paid-input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    text-align: right;
    font-family: monospace;
}

.paid-input:focus {
    border-color: #86b7fe;
    outline: 0;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

/* Hide number input arrows */
input[type="number"]::-webkit-outer-spin-button,
input[type="number"]::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

input[type="number"] {
    -moz-appearance: textfield;
}

.select-invoice {
    margin: 0;
    transform: scale(1.2);
    cursor: pointer;
}

.purchase-details {
    margin-top: 20px;
    padding: 20px;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    background-color: #f8f9fa;
    display: none;
}

.purchase-details h6 {
    margin-bottom: 15px;
    color: #495057;
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
    padding-bottom: 8px;
}

.text-danger {
    color: #dc3545 !important;
    font-weight: bold;
}

.bank-fields {
    display: none;
}

.amount-display {
    font-family: monospace;
    font-weight: bold;
    font-size: 1.1em;
}

.voucher-info {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.voucher-info h4 {
    margin: 0;
    font-weight: 600;
}
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <div class="content-area">
      <!-- Voucher Info Header -->
      <div class="voucher-info">
        <div class="row align-items-center">
          <div class="col-md-8">
            <h4><i class="fas fa-file-invoice-dollar me-2"></i><?= ucfirst($action) ?> Voucher Entry</h4>
          </div>
          <div class="col-md-4 text-end">
            <div id="current-date" class="small"></div>
            <div id="current-time" class="small"></div>
          </div>
        </div>
      </div>

      <!-- Voucher Type Selection -->
      <div class="card mb-3">
        <div class="card-header bg-light">
          <h6 class="mb-0"><i class="fas fa-cog me-2"></i>Voucher Settings</h6>
        </div>
        <div class="card-body">
          <div class="row align-items-center">
            <div class="col-md-3">
              <label class="form-label fw-bold">Voucher Type:</label>
              <div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" id="type-cash" name="voucher_type" value="C" <?= (!$voucher_data || $voucher_data['MODE'] == 'C') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="type-cash">Cash</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" id="type-bank" name="voucher_type" value="B" <?= ($voucher_data && $voucher_data['MODE'] == 'B') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="type-bank">Bank</label>
                </div>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Voucher Date:</label>
              <input type="date" class="form-control form-control-sm" id="voucher-date" value="<?= $voucher_data ? date('Y-m-d', strtotime($voucher_data['VDATE'])) : date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Voucher No:</label>
              <input type="text" class="form-control form-control-sm bg-light" id="voucher-no" value="<?= $voucher_data ? $voucher_data['VNO'] : 'Auto Generated' ?>" readonly>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Mode:</label>
              <div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" id="mode-payment" name="mode" value="Payment" <?= (!$voucher_data || $voucher_data['DRCR'] == 'D') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="mode-payment">Payment</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="radio" id="mode-receipts" name="mode" value="Receipts" <?= ($voucher_data && $voucher_data['DRCR'] == 'C') ? 'checked' : '' ?>>
                  <label class="form-check-label" for="mode-receipts">Receipts</label>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Bank Details (Only shown for Bank vouchers) -->
      <div class="card mb-3 bank-fields" id="bank-details">
        <div class="card-header bg-light">
          <h6 class="mb-0"><i class="fas fa-university me-2"></i>Bank Details</h6>
        </div>
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label class="form-label fw-bold">Bank Name:</label>
              <select class="form-control form-control-sm" id="bank-name">
                <option value="">-- Select Bank --</option>
                <?php
                // Fetch all bank accounts from ledger heads (include all bank-related accounts)
                $bankQuery = "SELECT LCODE, LHEAD FROM tbllheads 
                             WHERE (LHEAD LIKE '%bank%' OR LHEAD LIKE '%Bank%' OR LHEAD LIKE '%BANK%' 
                             OR LHEAD LIKE '%account%' OR LHEAD LIKE '%Account%' OR LHEAD LIKE '%ACCOUNT%'
                             OR LHEAD LIKE '%cheque%' OR LHEAD LIKE '%Cheque%' OR LHEAD LIKE '%CHEQUE%'
                             OR LHEAD LIKE '%check%' OR LHEAD LIKE '%Check%' OR LHEAD LIKE '%CHECK%')";
                $bankStmt = $conn->prepare($bankQuery);
                $bankStmt->execute();
                $bankResult = $bankStmt->get_result();
                while ($bank = $bankResult->fetch_assoc()) {
                    $selected = ($voucher_data && $voucher_data['REF_AC'] == $bank['LCODE']) ? 'selected' : '';
                    echo "<option value='{$bank['LCODE']}' $selected>{$bank['LHEAD']}</option>";
                }
                $bankStmt->close();
                ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Doc. No.:</label>
              <input type="text" class="form-control form-control-sm bg-light" id="doc-no" value="<?= $voucher_data ? $voucher_data['DOC_NO'] : $initial_doc_no ?>" readonly title="Document number will be auto-generated (PMT-000001, RCP-000001, CHQ-000001)">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Cheq. No.:</label>
              <input type="text" class="form-control form-control-sm" id="cheq-no" value="<?= $voucher_data ? $voucher_data['CHEQ_NO'] : '' ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-bold">Doc. Date:</label>
              <input type="date" class="form-control form-control-sm" id="doc-date" value="<?= $voucher_data ? ($voucher_data['CHEQ_DT'] ? date('Y-m-d', strtotime($voucher_data['CHEQ_DT'])) : '') : date('Y-m-d') ?>">
            </div>
          </div>
          <div class="row">
            <div class="col-md-3">
              <label class="form-label fw-bold">Cheq. Date:</label>
              <input type="date" class="form-control form-control-sm" id="cheq-date" value="<?= $voucher_data ? ($voucher_data['CHEQ_DT'] ? date('Y-m-d', strtotime($voucher_data['CHEQ_DT'])) : '') : date('Y-m-d') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Narration Section -->
      <div class="card mb-3">
        <div class="card-header bg-light">
          <h6 class="mb-0"><i class="fas fa-comment me-2"></i>Narration</h6>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-12">
              <label class="form-label fw-bold">Narration:</label>
              <input type="text" class="form-control form-control-sm" id="narr" value="<?= $voucher_data ? $voucher_data['NARR'] : '' ?>" placeholder="Enter narration for this voucher...">
            </div>
          </div>
        </div>
      </div>

      <!-- Voucher Table -->
      <div class="card mb-3">
        <div class="card-header bg-light">
          <h6 class="mb-0"><i class="fas fa-table me-2"></i>Voucher Details</h6>
        </div>
        <div class="card-body">
          <table class="table table-bordered">
            <thead class="table-light">
              <tr>
                <th width="5%">#</th>
                <th width="55%">Particulars</th>
                <th width="20%">Debit</th>
                <th width="20%">Credit</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>1</td>
                <td class="particulars-input">
                  <input type="text" class="form-control form-control-sm" id="particulars-input" placeholder="Type to search for supplier..." value="<?= $voucher_data ? $voucher_data['PARTI'] : '' ?>">
                  <div class="suggestions-box" id="suggestions"></div>
                </td>
                <td>
                  <input type="number" class="form-control form-control-sm debit-input" step="0.01" min="0" value="<?= ($voucher_data && $voucher_data['DRCR'] == 'D') ? $voucher_data['AMOUNT'] : '' ?>" inputmode="numeric" placeholder="0.00">
                </td>
                <td>
                  <input type="number" class="form-control form-control-sm credit-input" step="0.01" min="0" value="<?= ($voucher_data && $voucher_data['DRCR'] == 'C') ? $voucher_data['AMOUNT'] : '' ?>" inputmode="numeric" placeholder="0.00">
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Purchase Details (Initially Hidden) -->
          <div class="purchase-details" id="purchase-details">
            <h6><i class="fas fa-receipt me-2"></i>Pending Invoices</h6>
            <table class="purchase-table">
              <thead>
                <tr>
                  <th width="5%">Select</th>
                  <th width="10%">Voc. No.</th>
                  <th width="15%">Inv. No.</th>
                  <th width="12%">Date</th>
                  <th width="12%">Amount</th>
                  <th width="12%">Paid</th>
                  <th width="12%">Balance</th>
                  <th width="22%">Paid Now</th>
                </tr>
              </thead>
              <tbody id="purchase-details-body">
                <!-- Will be populated by JavaScript -->
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Amount Section -->
      <div class="card mb-3">
        <div class="card-header bg-light">
          <h6 class="mb-0"><i class="fas fa-calculator me-2"></i>Amount Summary</h6>
        </div>
        <div class="card-body">
          <div class="row">
            <div class="col-md-6">
              <label class="form-label fw-bold">Pending Amount:</label>
              <input type="text" class="form-control form-control-sm amount-display bg-warning bg-opacity-10" id="pending-amt" value="0.00" readonly>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-bold">Total Amount:</label>
              <input type="text" class="form-control form-control-sm amount-display bg-success bg-opacity-10" id="total-amount" value="<?= $voucher_data ? $voucher_data['AMOUNT'] : '0.00' ?>" readonly>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2">
        <button class="btn btn-primary" id="save-btn">
          <i class="fas fa-save"></i> Save Voucher
        </button>
        <button class="btn btn-secondary" id="new-btn">
          <i class="fas fa-file"></i> New Voucher
        </button>
        <button class="btn btn-info" id="print-btn">
          <i class="fas fa-print"></i> Print
        </button>
        <?php if ($action === 'edit'): ?>
        <button class="btn btn-warning" id="modify-btn">
          <i class="fas fa-edit"></i> Modify
        </button>
        <button class="btn btn-danger" id="delete-btn">
          <i class="fas fa-trash"></i> Delete
        </button>
        <?php endif; ?>
        <a href="voucher_view.php" class="btn btn-outline-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Back to View
        </a>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  $(document).ready(function() {
    // Convert PHP ledger data to JavaScript
    const ledgerData = <?= json_encode($ledgerData) ?>;
    
    // Variables for keyboard navigation
    let currentSuggestionIndex = -1;
    let filteredSuggestions = [];
    let selectedLedgerCode = '';
    let selectedLedgerName = '';

    // Toggle bank fields based on voucher type
    function toggleBankFields() {
      if ($('#type-bank').is(':checked')) {
        $('#bank-details').show();
      } else {
        $('#bank-details').hide();
      }
    }
    
    // Initial toggle
    toggleBankFields();
    
    // Bind change event
    $('input[name="voucher_type"]').change(function() {
      toggleBankFields();
    });

    // Update DOC_NO when mode changes
    $('input[name="mode"]').change(function() {
      updateDocNo();
    });

    // Update DOC_NO when voucher type changes
    $('input[name="voucher_type"]').change(function() {
      updateDocNo();
    });

    // Function to update DOC_NO based on current selections
    function updateDocNo() {
      const isPayment = $('#mode-payment').is(':checked');
      const voucherType = $('input[name="voucher_type"]:checked').val();
      
      // Generate appropriate DOC_NO prefix
      let prefix = '';
      if (voucherType === 'B') {
        prefix = 'CHQ';
      } else {
        prefix = isPayment ? 'PMT' : 'RCP';
      }
      
      // Show loading text while we generate the DOC_NO
      $('#doc-no').val('Generating...');
      
      // Call server to get the next DOC_NO
      $.ajax({
        url: 'get_next_docno.php',
        type: 'POST',
        data: {
          prefix: prefix,
          comp_id: <?= $_SESSION['CompID'] ?>
        },
        dataType: 'json',
        success: function(response) {
          if (response.success) {
            $('#doc-no').val(response.doc_no);
          } else {
            $('#doc-no').val('Auto Generated');
          }
        },
        error: function() {
          $('#doc-no').val('Auto Generated');
        }
      });
    }

    // Update current time and date
    function updateDateTime() {
      const now = new Date();
      const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
      const dateStr = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
      $('#current-time').text(timeStr);
      $('#current-date').text(dateStr);
    }
    
    updateDateTime();
    setInterval(updateDateTime, 60000); // Update every minute

    // Particulars input suggestions with keyboard navigation
    $('#particulars-input').on('input', function() {
      const query = $(this).val().toLowerCase();
      const suggestions = $('#suggestions');
      
      if (query.length < 1) {
        suggestions.hide();
        return;
      }
      
      filteredSuggestions = ledgerData.filter(item => 
        item.LHEAD && item.LHEAD.toLowerCase().includes(query) || 
        (item.REF_CODE && item.REF_CODE.toLowerCase().includes(query))
      );
      
      if (filteredSuggestions.length > 0) {
        suggestions.empty();
        filteredSuggestions.forEach((item, index) => {
          suggestions.append(`<div class="suggestion-item" data-index="${index}" data-code="${item.REF_CODE}" data-name="${item.LHEAD}">${item.LHEAD} (${item.REF_CODE})</div>`);
        });
        suggestions.show();
        currentSuggestionIndex = -1;
      } else {
        suggestions.hide();
      }
    });

    // Keyboard navigation for suggestions
    $('#particulars-input').on('keydown', function(e) {
      const suggestions = $('#suggestions');
      const suggestionItems = $('.suggestion-item');
      
      if (suggestionItems.length === 0 || !suggestions.is(':visible')) {
        return;
      }
      
      switch(e.key) {
        case 'ArrowDown':
          e.preventDefault();
          if (currentSuggestionIndex < suggestionItems.length - 1) {
            currentSuggestionIndex++;
            updateSuggestionSelection();
          }
          break;
        case 'ArrowUp':
          e.preventDefault();
          if (currentSuggestionIndex > 0) {
            currentSuggestionIndex--;
            updateSuggestionSelection();
          }
          break;
        case 'Enter':
          e.preventDefault();
          if (currentSuggestionIndex >= 0) {
            selectSuggestion(currentSuggestionIndex);
          } else if (filteredSuggestions.length > 0) {
            // If user presses enter without selecting, select the first suggestion
            selectSuggestion(0);
          }
          break;
        case 'Escape':
          suggestions.hide();
          currentSuggestionIndex = -1;
          break;
      }
    });

    function updateSuggestionSelection() {
      $('.suggestion-item').removeClass('selected');
      if (currentSuggestionIndex >= 0) {
        $(`.suggestion-item[data-index="${currentSuggestionIndex}"]`).addClass('selected');
        // Scroll to selected item
        const selectedItem = $(`.suggestion-item[data-index="${currentSuggestionIndex}"]`)[0];
        if (selectedItem) {
          selectedItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }
    }

    function selectSuggestion(index) {
      const selected = filteredSuggestions[index];
      if (selected) {
        selectedLedgerCode = selected.REF_CODE;
        selectedLedgerName = selected.LHEAD;
        $('#particulars-input').val(selected.LHEAD);
        $('#suggestions').hide();
        currentSuggestionIndex = -1;
        
        // Fetch pending invoices for this ledger
        fetchPendingInvoices(selected.REF_CODE);
      }
    }

    // Handle suggestion selection with mouse
    $(document).on('click', '.suggestion-item', function() {
      const index = $(this).data('index');
      selectSuggestion(index);
    });

    // Hide suggestions when clicking elsewhere
    $(document).on('click', function(e) {
      if (!$(e.target).closest('.particulars-input').length) {
        $('#suggestions').hide();
        currentSuggestionIndex = -1;
      }
    });

    // Fetch pending invoices from server
    function fetchPendingInvoices(ledgerCode) {
      const timestamp = new Date().getTime();
      
      $.ajax({
        url: 'fetch_pending_invoices.php?' + timestamp,
        type: 'POST',
        data: { 
          ledger_code: ledgerCode,
          comp_id: <?= $_SESSION['CompID'] ?>
        },
        dataType: 'json',
        success: function(response) {
          if (response.success) {
            displayPendingInvoices(response.data);
            $('#purchase-details').show();
            $('#pending-amt').val(response.total_pending.toFixed(0));
            
            // Update the total amount field based on current mode
            if ($('#mode-payment').is(':checked')) {
              $('.credit-input').val('0.00');
              $('.debit-input').val('');
            } else {
              $('.debit-input').val('0.00');
              $('.credit-input').val('');
            }
          } else {
            alert('Error fetching pending invoices: ' + response.message);
            $('#purchase-details').hide();
          }
        },
        error: function(xhr, status, error) {
          alert('Error connecting to server: ' + error);
          $('#purchase-details').hide();
        }
      });
    }

    // Display pending invoices
    function displayPendingInvoices(invoices) {
      const tbody = $('#purchase-details-body');
      tbody.empty();

      if (invoices.length === 0) {
        tbody.append('<tr><td colspan="8" class="text-center text-muted py-3">No pending invoices found for this supplier</td></tr>');
        return;
      }

      invoices.forEach(invoice => {
        const totalAmount = parseFloat(invoice.TAMT);
        const paidAmount = parseFloat(invoice.paid_amount);
        const balance = parseFloat(invoice.balance);

        tbody.append(`
          <tr data-id="${invoice.ID}" data-total="${totalAmount}" data-paid="${paidAmount}" data-balance="${balance}" data-paid-now="0" data-voc-no="${invoice.VOC_NO}">
            <td class="text-center"><input type="checkbox" class="select-invoice" checked></td>
            <td><strong>${invoice.VOC_NO}</strong></td>
            <td>${invoice.INV_NO || 'N/A'}</td>
            <td>${invoice.DATE}</td>
            <td class="amount-cell total-amount">${totalAmount.toFixed(0)}</td>
            <td class="amount-cell paid-amount">${paidAmount.toFixed(0)}</td>
            <td class="amount-cell balance-amount ${balance < 0 ? 'text-danger' : ''}">${balance.toFixed(0)}</td>
            <td><input type="number" class="paid-input form-control form-control-sm" step="0.01" value="0.00" inputmode="numeric" placeholder="0.00"></td>
          </tr>
        `);
      });
    }

    // Handle paid amount input - update calculations in real-time
    let manualEntryMode = false;

    $(document).on('input', '.paid-input', function() {
      manualEntryMode = true;

      const row = $(this).closest('tr');
      const totalAmount = parseFloat(row.data('total'));
      const currentPaid = parseFloat(row.data('paid'));
      const paidNow = parseFloat($(this).val()) || 0;

      // Validate that paid amount doesn't exceed balance
      const currentBalance = parseFloat(row.data('balance'));
      if (paidNow > currentBalance) {
        alert('Paid amount cannot exceed the current balance');
        $(this).val(currentBalance.toFixed(0));
        return;
      }

      // Calculate new paid amount and balance
      const newPaidAmount = currentPaid + paidNow;
      const newBalance = totalAmount - newPaidAmount;

      // Update the displayed values
      row.find('.paid-amount').text(newPaidAmount.toFixed(0));

      const balanceCell = row.find('.balance-amount');
      balanceCell.text(newBalance.toFixed(0));

      if (newBalance < 0) {
        balanceCell.addClass('text-danger');
      } else {
        balanceCell.removeClass('text-danger');
      }

      // Store the updated values in data attributes
      row.data('paid-now', paidNow);
      row.data('new-paid', newPaidAmount);
      row.data('new-balance', newBalance);

      // Calculate total amount across all invoices
      calculateTotalAmount();
    });

    // Reset manual entry mode when particulars change
    $('#particulars-input').on('input', function() {
      manualEntryMode = false;
    });

    // Calculate total amount from all paid inputs
    function calculateTotalAmount() {
      let totalPaidNow = 0;
      let totalPending = 0;

      $('#purchase-details-body tr').each(function() {
        const paidNow = parseFloat($(this).data('paid-now')) || 0;
        const balance = parseFloat($(this).data('new-balance')) || parseFloat($(this).data('balance'));

        totalPaidNow += paidNow;
        totalPending += balance;
      });

      $('#pending-amt').val(totalPending.toFixed(0));
      $('#total-amount').val(totalPaidNow.toFixed(0));

      // Set debit or credit based on mode
      if ($('#mode-payment').is(':checked')) {
        $('.credit-input').val(totalPaidNow.toFixed(0));
        $('.debit-input').val('');
      } else {
        $('.debit-input').val(totalPaidNow.toFixed(0));
        $('.credit-input').val('');
      }

      // Trigger auto-distribution when amount is entered in debit/credit fields
      const debitVal = parseFloat($('.debit-input').val()) || 0;
      const creditVal = parseFloat($('.credit-input').val()) || 0;
      const totalEntered = debitVal + creditVal;

      if (totalEntered > 0 && selectedLedgerCode) {
        distributeAmount(totalEntered);
      }
    }

    // Smart amount distribution function
    function distributeAmount(totalAmount) {
      let remainingAmount = totalAmount;
      let invoices = [];

      // Collect all pending invoices
      $('#purchase-details-body tr').each(function() {
        const balance = parseFloat($(this).data('balance'));
        const id = $(this).data('id');
        const vocNo = $(this).data('voc-no');

        if (balance > 0) {
          invoices.push({
            id: id,
            vocNo: vocNo,
            balance: balance,
            row: $(this)
          });
        }
      });

      // Distribute amount to invoices
      invoices.forEach(invoice => {
        if (remainingAmount <= 0) return;

        const amountToPay = Math.min(remainingAmount, invoice.balance);
        invoice.row.find('.paid-input').val(amountToPay.toFixed(0));

        // Update calculations for this row
        const row = invoice.row;
        const totalAmount = parseFloat(row.data('total'));
        const currentPaid = parseFloat(row.data('paid'));
        const paidNow = amountToPay;

        const newPaidAmount = currentPaid + paidNow;
        const newBalance = totalAmount - newPaidAmount;

        row.find('.paid-amount').text(newPaidAmount.toFixed(0));
        const balanceCell = row.find('.balance-amount');
        balanceCell.text(newBalance.toFixed(0));

        if (newBalance < 0) {
          balanceCell.addClass('text-danger');
        } else {
          balanceCell.removeClass('text-danger');
        }

        row.data('paid-now', paidNow);
        row.data('new-paid', newPaidAmount);
        row.data('new-balance', newBalance);

        remainingAmount -= amountToPay;
      });

      // Update total calculations
      calculateTotalAmount();
    }

    // Handle debit/credit input changes for instant auto-distribution
    $(document).on('input', '.debit-input, .credit-input', function() {
      if (!selectedLedgerCode) {
        alert('Please select a particulars first');
        $(this).val('');
        return;
      }

      const debitVal = parseFloat($('.debit-input').val()) || 0;
      const creditVal = parseFloat($('.credit-input').val()) || 0;
      const totalEntered = debitVal + creditVal;

      if (totalEntered > 0 && !manualEntryMode) {
        // Clear existing paid amounts first
        $('#purchase-details-body tr').each(function() {
          $(this).find('.paid-input').val('0.00');
          const totalAmount = parseFloat($(this).data('total'));
          const currentPaid = parseFloat($(this).data('paid'));
          const balance = totalAmount - currentPaid;

          $(this).find('.paid-amount').text(currentPaid.toFixed(0));
          $(this).find('.balance-amount').text(balance.toFixed(0)).removeClass('text-danger');
          $(this).data('paid-now', 0);
          $(this).data('new-paid', currentPaid);
          $(this).data('new-balance', balance);
        });

        // Auto-distribute the entered amount instantly
        distributeAmount(totalEntered);
      } else {
        // Clear all paid amounts if no amount entered
        $('#purchase-details-body tr').each(function() {
          $(this).find('.paid-input').val('0.00');
          const totalAmount = parseFloat($(this).data('total'));
          const currentPaid = parseFloat($(this).data('paid'));
          const balance = totalAmount - currentPaid;

          $(this).find('.paid-amount').text(currentPaid.toFixed(0));
          $(this).find('.balance-amount').text(balance.toFixed(0)).removeClass('text-danger');
          $(this).data('paid-now', 0);
          $(this).data('new-paid', currentPaid);
          $(this).data('new-balance', balance);
        });
        calculateTotalAmount();
      }
    });

    // Function to get existing VNO for purchase invoices
    function getExistingVNO(purchaseVocNos) {
        return new Promise((resolve, reject) => {
            if (purchaseVocNos.length === 0) {
                resolve(null);
                return;
            }
            
            $.ajax({
                url: 'get_existing_vno.php',
                type: 'POST',
                data: {
                    purchase_voc_nos: JSON.stringify(purchaseVocNos),
                    comp_id: <?= $_SESSION['CompID'] ?>
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        resolve(response.vno);
                    } else {
                        resolve(null);
                    }
                },
                error: function() {
                    resolve(null);
                }
            });
        });
    }

    // Save button handler
    $('#save-btn').on('click', async function() {
      const particulars = $('#particulars-input').val();
      const amount = parseFloat($('#total-amount').val()) || 0;
      const isPayment = $('#mode-payment').is(':checked');
      const voucherType = $('input[name="voucher_type"]:checked').val();
      const narration = $('#narr').val();
      const voucherDate = $('#voucher-date').val();
      
      // Bank fields
      const bankName = $('#bank-name').val();
      const docNo = $('#doc-no').val();
      const cheqNo = $('#cheq-no').val();
      const docDate = $('#doc-date').val();
      const cheqDate = $('#cheq-date').val();
      
      if (!particulars || !selectedLedgerCode) {
        alert('Please select a valid particulars entry');
        return;
      }
      
      if (amount <= 0) {
        alert('Please enter a valid amount');
        return;
      }
      
      // Get paid amounts for each invoice
      const paidInvoices = [];
      let hasPaidInvoices = false;
      let purchaseVocNos = [];
      
      $('#purchase-details-body tr').each(function() {
        const paidNow = parseFloat($(this).data('paid-now')) || 0;
        if (paidNow > 0) {
          hasPaidInvoices = true;
          const invoiceId = $(this).data('id');
          const invoiceVocNo = $(this).data('voc-no');
          const currentPaid = parseFloat($(this).data('paid'));
          const newPaidAmount = currentPaid + paidNow;
          const totalAmount = parseFloat($(this).data('total'));
          
          paidInvoices.push({
            id: invoiceId,
            voc_no: invoiceVocNo,
            paid_amount: paidNow,
            total_paid: newPaidAmount,
            new_balance: totalAmount - newPaidAmount
          });
          
          purchaseVocNos.push(invoiceVocNo);
        }
      });
      
      // If no invoices are paid but amount is entered, create a manual voucher
      if (!hasPaidInvoices && amount > 0) {
        paidInvoices.length = 0; // Clear the array
        purchaseVocNos = [];
      }
      
      // Check for existing VNO if we have paid invoices
      let existingVNO = null;
      if (hasPaidInvoices) {
        existingVNO = await getExistingVNO(purchaseVocNos);
      }
      
      // Show loading state
      const saveBtn = $(this);
      const originalText = saveBtn.html();
      saveBtn.html('<i class="fas fa-spinner fa-spin"></i> Saving...');
      saveBtn.prop('disabled', true);
      
      // Submit data to server
      $.ajax({
        url: 'save_voucher.php',
        type: 'POST',
        data: {
          action: '<?= $action ?>',
          voucher_id: <?= $voucher_id ?>,
          ledger_code: selectedLedgerCode,
          ledger_name: selectedLedgerName,
          amount: amount,
          is_payment: isPayment,
          voucher_type: voucherType,
          narration: narration,
          voucher_date: voucherDate,
          bank_id: bankName,
          doc_no: docNo,
          cheq_no: cheqNo,
          doc_date: docDate,
          cheq_date: cheqDate,
          paid_invoices: JSON.stringify(paidInvoices),
          comp_id: <?= $_SESSION['CompID'] ?>,
          fin_year_id: <?= $_SESSION['FIN_YEAR_ID'] ?>,
          user_id: <?= $_SESSION['user_id'] ?>
        },
        success: function(response) {
          try {
            const result = JSON.parse(response);
            if (result.success) {
              let successMessage = 'Voucher saved successfully! Voucher No: ' + result.voucher_no + ', Doc No: ' + result.doc_no;
              if (result.total_invoices > 0) {
                successMessage += ', Payments applied to ' + result.total_invoices + ' invoice(s)';
              }
              alert(successMessage);
              
              // Update the voucher number and doc no display
              $('#voucher-no').val(result.voucher_no);
              $('#doc-no').val(result.doc_no);
              
              // Instead of refreshing to a blank page, reload with the same particulars
              if (selectedLedgerCode) {
                // Re-fetch the pending invoices to see updated balances
                fetchPendingInvoices(selectedLedgerCode);
                
                // Clear the paid now inputs but keep the particulars selected
                $('.paid-input').val('0.00');
                $('#total-amount').val('0.00');
                if (isPayment) {
                  $('.credit-input').val('0.00');
                } else {
                  $('.debit-input').val('0.00');
                }
                
                // Reset the paid-now data attributes
                $('#purchase-details-body tr').each(function() {
                  $(this).data('paid-now', 0);
                });
              } else {
                // Refresh the page to clear the form
                window.location.href = 'voucher_entry.php';
              }
            } else {
              alert('Error saving voucher: ' + result.message);
            }
          } catch (e) {
            alert('Error parsing server response: ' + e.message);
          }
        },
        error: function(xhr, status, error) {
          alert('Error connecting to server: ' + error);
        },
        complete: function() {
          // Restore button state
          saveBtn.html(originalText);
          saveBtn.prop('disabled', false);
        }
      });
    });

    // New button handler
    $('#new-btn').on('click', function() {
      window.location.href = 'voucher_entry.php';
    });

    // Print button handler
    $('#print-btn').on('click', function() {
      window.print();
    });

    // Delete button handler
    $('#delete-btn').on('click', function() {
      if (confirm('Are you sure you want to delete this voucher?')) {
        $.ajax({
          url: 'delete_voucher.php',
          type: 'POST',
          data: {
            voucher_id: <?= $voucher_id ?>,
            comp_id: <?= $_SESSION['CompID'] ?>
          },
          success: function(response) {
            try {
              const result = JSON.parse(response);
              if (result.success) {
                alert('Voucher deleted successfully!');
                window.location.href = 'voucher_view.php';
              } else {
                alert('Error deleting voucher: ' + result.message);
              }
            } catch (e) {
              alert('Error parsing server response');
            }
          },
          error: function() {
            alert('Error connecting to server');
          }
        });
      }
    });
  });
</script>
</body>
</html>