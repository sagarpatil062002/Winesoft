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

// Handle edit/view actions
$action = isset($_GET['action']) ? $_GET['action'] : 'new';
$voucher_id = isset($_GET['id']) ? $_GET['id'] : 0;

// If editing/viewing, fetch voucher data
$voucher_data = null;
if (($action === 'edit' || $action === 'view') && $voucher_id > 0) {
    $query = "SELECT * FROM tblExpenses WHERE VNO = ? AND COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $voucher_id, $_SESSION['CompID']);
    $stmt->execute();
    $result = $stmt->get_result();
    $voucher_data = $result->fetch_assoc();
    $stmt->close();
}

// Fetch ledger data from database using tbllheads table
$ledgerData = [];
$query = "SELECT DISTINCT p.SUBCODE, l.LHEAD as LEDGER_NAME 
          FROM tblpurchases p 
          JOIN tbllheads l ON p.SUBCODE = l.REF_CODE 
          WHERE p.CompID = ?
          UNION 
          SELECT DISTINCT s.CUST_CODE, l.LHEAD as LEDGER_NAME 
          FROM tblsaleheader s 
          JOIN tbllheads l ON s.CUST_CODE = l.REF_CODE 
          WHERE s.COMP_ID = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("ii", $_SESSION['CompID'], $_SESSION['CompID']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $ledgerData[] = $row;
}
$stmt->close();
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
    }
    .suggestion-item {
        padding: 8px;
        cursor: pointer;
    }
    .suggestion-item:hover, .suggestion-item.selected {
        background-color: #f0f0f0;
    }
    .purchase-details {
        margin-top: 15px;
        max-height: 300px;
        overflow-y: auto;
    }
    .purchase-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }
    .purchase-table th, .purchase-table td {
        border: 1px solid #ddd;
        padding: 5px;
        text-align: center;
    }
    .purchase-table th {
        background-color: #f2f2f2;
    }
    .status-bar {
        background-color: #333;
        color: white;
        padding: 5px 10px;
        display: flex;
        justify-content: space-between;
        font-size: 12px;
    }
    .bank-fields {
        display: none;
    }
    .paid-input {
        width: 100%;
        border: 1px solid #ddd;
        padding: 2px 5px;
        text-align: right;
    }
    .amount-cell {
        text-align: right;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4"><?= ucfirst($action) ?> Voucher Entry</h3>

      <div class="status-bar mb-3">
        <div>ADMIN</div>
        <div>CAPS</div>
        <div>NUM</div>
        <div id="current-time"><?= date('h:i A') ?></div>
        <div id="current-date"><?= date('d-M-Y') ?></div>
      </div>

      <!-- Voucher Type Selection -->
      <div class="card mb-3">
        <div class="card-body">
          <div class="row align-items-center">
            <div class="col-md-3">
              <label class="form-label">Voucher Type:</label>
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
              <label class="form-label">Voucher Date:</label>
              <input type="date" class="form-control form-control-sm" id="voucher-date" value="<?= $voucher_data ? date('Y-m-d', strtotime($voucher_data['VDATE'])) : date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Voucher No:</label>
              <input type="text" class="form-control form-control-sm" id="voucher-no" value="<?= $voucher_data ? $voucher_data['VNO'] : 'Auto Generated' ?>" readonly>
            </div>
            <div class="col-md-3">
              <label class="form-label">Mode:</label>
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
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label class="form-label">Bank Name:</label>
              <select class="form-control form-control-sm" id="bank-name">
                <option value="">-- Select Bank --</option>
                <?php
                // Fetch banks from ledger heads
                $bankQuery = "SELECT LCODE, LHEAD FROM tbllheads WHERE GCODE = 2 AND CompID = ?";
                $bankStmt = $conn->prepare($bankQuery);
                $bankStmt->bind_param("i", $_SESSION['CompID']);
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
              <label class="form-label">Doc. No.:</label>
              <input type="text" class="form-control form-control-sm" id="doc-no" value="<?= $voucher_data ? $voucher_data['INV_NO'] : '' ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Cheq. No.:</label>
              <input type="text" class="form-control form-control-sm" id="cheq-no" value="<?= $voucher_data ? $voucher_data['CHEQ_NO'] : '' ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Doc. Date:</label>
              <input type="date" class="form-control form-control-sm" id="doc-date" value="<?= $voucher_data ? ($voucher_data['CHEQ_DT'] ? date('Y-m-d', strtotime($voucher_data['CHEQ_DT'])) : '') : date('Y-m-d') ?>">
            </div>
          </div>
          <div class="row">
            <div class="col-md-3">
              <label class="form-label">Cheq. Date:</label>
              <input type="date" class="form-control form-control-sm" id="cheq-date" value="<?= $voucher_data ? ($voucher_data['CHEQ_DT'] ? date('Y-m-d', strtotime($voucher_data['CHEQ_DT'])) : '') : date('Y-m-d') ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Voucher Table -->
      <div class="card mb-3">
        <div class="card-body">
          <table class="table table-bordered">
            <thead>
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
                  <input type="text" class="form-control form-control-sm" id="particulars-input" placeholder="Enter particulars" value="<?= $voucher_data ? $voucher_data['PARTI'] : '' ?>">
                  <div class="suggestions-box" id="suggestions"></div>
                </td>
                <td>
                  <input type="number" class="form-control form-control-sm debit-input" step="0.01" min="0" value="<?= ($voucher_data && $voucher_data['DRCR'] == 'D') ? $voucher_data['AMOUNT'] : '' ?>" readonly>
                </td>
                <td>
                  <input type="number" class="form-control form-control-sm credit-input" step="0.01" min="0" value="<?= ($voucher_data && $voucher_data['DRCR'] == 'C') ? $voucher_data['AMOUNT'] : '' ?>" readonly>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Purchase Details (Initially Hidden) -->
          <div class="purchase-details" id="purchase-details" style="display: none;">
            <h6>Pending Invoices</h6>
            <table class="purchase-table">
              <thead>
                <tr>
                  <th>Select</th>
                  <th>Voc. No.</th>
                  <th>Inv. No.</th>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Paid</th>
                  <th>Balance</th>
                  <th>Paid Now</th>
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
        <div class="card-body">
          <div class="row">
            <div class="col-md-4">
              <label class="form-label">Pending Amt:</label>
              <input type="text" class="form-control form-control-sm" id="pending-amt" value="0.00" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Total Amount:</label>
              <input type="text" class="form-control form-control-sm" id="total-amount" value="<?= $voucher_data ? $voucher_data['AMOUNT'] : '0.00' ?>" readonly>
            </div>
            <div class="col-md-4">
              <label class="form-label">Narration:</label>
              <input type="text" class="form-control form-control-sm" id="narr" value="<?= $voucher_data ? $voucher_data['NARR'] : '' ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2">
        <button class="btn btn-primary" id="save-btn">
          <i class="fas fa-save"></i> Save
        </button>
        <button class="btn btn-secondary" id="new-btn">
          <i class="fas fa-file"></i> New
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
        <a href="voucher_view.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
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
      
      if (query.length < 2) {
        suggestions.hide();
        return;
      }
      
      filteredSuggestions = ledgerData.filter(item => 
        item.LEDGER_NAME.toLowerCase().includes(query) || 
        item.SUBCODE.toLowerCase().includes(query)
      );
      
      if (filteredSuggestions.length > 0) {
        suggestions.empty();
        filteredSuggestions.forEach((item, index) => {
          suggestions.append(`<div class="suggestion-item" data-index="${index}" data-code="${item.SUBCODE}" data-name="${item.LEDGER_NAME}">${item.LEDGER_NAME} (${item.SUBCODE})</div>`);
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
        selectedLedgerCode = selected.SUBCODE;
        selectedLedgerName = selected.LEDGER_NAME;
        $('#particulars-input').val(selected.LEDGER_NAME);
        $('#suggestions').hide();
        currentSuggestionIndex = -1;
        
        // Fetch pending invoices for this ledger
        fetchPendingInvoices(selected.SUBCODE);
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
      $.ajax({
        url: 'fetch_pending_invoices.php',
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
            $('#pending-amt').val(response.total_pending.toFixed(2));
          } else {
            alert('Error fetching pending invoices: ' + response.message);
            $('#purchase-details').hide();
          }
        },
        error: function() {
          alert('Error connecting to server');
          $('#purchase-details').hide();
        }
      });
    }

    // Display pending invoices
    function displayPendingInvoices(invoices) {
      const tbody = $('#purchase-details-body');
      tbody.empty();
      
      if (invoices.length === 0) {
        tbody.append('<tr><td colspan="8" class="text-center">No pending invoices found</td></tr>');
        return;
      }
      
      invoices.forEach(invoice => {
        tbody.append(`
          <tr>
            <td><input type="checkbox" class="select-invoice" data-id="${invoice.id}" data-balance="${invoice.balance}"></td>
            <td>${invoice.VOC_NO}</td>
            <td>${invoice.INV_NO || ''}</td>
            <td>${invoice.DATE}</td>
            <td class="amount-cell">${parseFloat(invoice.TAMT).toFixed(2)}</td>
            <td class="amount-cell">${(parseFloat(invoice.TAMT) - parseFloat(invoice.balance)).toFixed(2)}</td>
            <td class="amount-cell">${parseFloat(invoice.balance).toFixed(2)}</td>
            <td><input type="number" class="paid-input" step="0.01" min="0" max="${parseFloat(invoice.balance).toFixed(2)}" value="0.00" data-balance="${parseFloat(invoice.balance).toFixed(2)}"></td>
          </tr>
        `);
      });
    }

    // Handle paid amount input
    $(document).on('input', '.paid-input', function() {
      const maxAmount = parseFloat($(this).data('balance'));
      const enteredAmount = parseFloat($(this).val()) || 0;
      
      // Validate that paid amount doesn't exceed balance
      if (enteredAmount > maxAmount) {
        alert('Paid amount cannot exceed the balance amount');
        $(this).val(maxAmount.toFixed(2));
      }
      
      // Calculate total amount
      calculateTotalAmount();
    });

    // Calculate total amount from all paid inputs
    function calculateTotalAmount() {
      let totalAmount = 0;
      $('.paid-input').each(function() {
        const amount = parseFloat($(this).val()) || 0;
        totalAmount += amount;
      });
      
      $('#total-amount').val(totalAmount.toFixed(2));
      
      // Set debit or credit based on mode
      if ($('#mode-payment').is(':checked')) {
        $('.credit-input').val(totalAmount.toFixed(2));
        $('.debit-input').val('');
      } else {
        $('.debit-input').val(totalAmount.toFixed(2));
        $('.credit-input').val('');
      }
    }

    // Save button handler
    $('#save-btn').on('click', function() {
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
      $('.paid-input').each(function() {
        const paidAmount = parseFloat($(this).val()) || 0;
        if (paidAmount > 0) {
          const invoiceId = $(this).closest('tr').find('.select-invoice').data('id');
          paidInvoices.push({
            id: invoiceId,
            paid_amount: paidAmount
          });
        }
      });
      
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
          paid_invoices: paidInvoices,
          comp_id: <?= $_SESSION['CompID'] ?>,
          fin_year_id: <?= $_SESSION['FIN_YEAR_ID'] ?>
        },
        success: function(response) {
          try {
            const result = JSON.parse(response);
            if (result.success) {
              alert('Voucher saved successfully!');
              // Refresh the page to clear the form
              window.location.href = 'voucher_entry.php';
            } else {
              alert('Error saving voucher: ' + result.message);
            }
          } catch (e) {
            alert('Error parsing server response');
          }
        },
        error: function() {
          alert('Error connecting to server');
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