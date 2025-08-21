<?php
session_start();

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR'])) {
    header("Location: select_company.php");
    exit;
}

include_once "../config/db.php";

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Get next VOC_NO
$vocQuery = "SELECT MAX(VOC_NO) as MAX_VOC FROM tblPurchases";
$vocResult = $conn->query($vocQuery);
$maxVoc = $vocResult->fetch_assoc();
$nextVoc = $maxVoc['MAX_VOC'] + 1;

// Fetch items for the selected mode
$itemsQuery = "SELECT CODE, DETAILS, PPRICE FROM tblitemmaster WHERE LIQ_FLAG = ? ORDER BY DETAILS";
$itemsStmt = $conn->prepare($itemsQuery);
$itemsStmt->bind_param("s", $mode);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$items = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// Fetch suppliers
$suppliersQuery = "SELECT CODE, DETAILS FROM tblsupplier ORDER BY DETAILS";
$suppliersResult = $conn->query($suppliersQuery);
$suppliers = $suppliersResult->fetch_all(MYSQLI_ASSOC);

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process purchase data and save to database
    // This would include validation and insertion into tblPurchases and related tables
    
    // After successful save, redirect to purchase list
    header("Location: purchase_module.php?mode=" . $mode);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>New Purchase - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .table-container {
      overflow-x: auto;
      max-height: 400px;
    }
    .styled-table {
      width: 100%;
      border-collapse: collapse;
      margin: 10px 0;
    }
    .styled-table th, .styled-table td {
      padding: 8px 12px;
      border: 1px solid #ddd;
    }
    .styled-table th {
      background-color: #f8f9fa;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .styled-table tbody tr:hover {
      background-color: #f5f5f5;
    }
    .form-control-sm {
      padding: 0.25rem 0.5rem;
      font-size: 0.875rem;
    }
    .dashboard-container {
      display: flex;
      min-height: 100vh;
    }
    .main-content {
      flex: 1;
      display: flex;
      flex-direction: column;
    }
    .content-area {
      flex: 1;
      padding: 20px;
      background-color: #f8f9fa;
    }
    .card {
      box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
      margin-bottom: 20px;
    }
    .card-header {
      background-color: #f0f0f0;
      border-bottom: 2px solid #ddd;
      font-weight: bold;
    }
    .form-label {
      font-weight: bold;
      margin-bottom: 5px;
    }
    .form-control {
      border: 1px solid #ced4da;
      border-radius: 4px;
    }
    #itemsTable td, #itemsTable th {
      vertical-align: middle;
      text-align: center;
    }
    #itemsTable td:first-child, #itemsTable th:first-child {
      text-align: left;
    }
    .alert-info {
      background-color: #d1ecf1;
      border-color: #bee5eb;
      color: #0c5460;
    }
    .section-title {
      border-bottom: 2px solid #007bff;
      padding-bottom: 5px;
      margin-bottom: 15px;
      color: #007bff;
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
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">New Purchase - <?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?></h3>

      <!-- Paste from SCM Button -->
      <div class="alert alert-info mb-3">
        <h5><i class="fas fa-info-circle"></i> Paste from SCM System</h5>
        <p>Copy the table data from the SCM retailer management system and click the button below to paste it.</p>
        <button type="button" class="btn btn-primary" id="pasteFromSCM">
          <i class="fas fa-paste"></i> Paste SCM Data
        </button>
      </div>

      <form method="POST" id="purchaseForm">
        <input type="hidden" name="mode" value="<?= $mode ?>">
        
        <!-- Purchase Header Information -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-info-circle"></i> Purchase Information</h5>
          </div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Voucher No.</label>
                  <input type="text" class="form-control" value="<?= $nextVoc ?>" disabled>
                  <input type="hidden" name="voc_no" value="<?= $nextVoc ?>">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Date</label>
                  <input type="date" class="form-control" name="date" value="<?= date('Y-m-d') ?>" required>
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">T.P. No.</label>
                  <input type="text" class="form-control" name="tp_no" id="tpNo">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">T.P. Date</label>
                  <input type="date" class="form-control" name="tp_date" id="tpDate">
                </div>
              </div>
            </div>
            
            <div class="row">
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Invoice No.</label>
                  <input type="text" class="form-control" name="inv_no">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Invoice Date</label>
                  <input type="date" class="form-control" name="inv_date">
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-group mb-3">
                  <label class="form-label">Supplier</label>
                  <div class="supplier-container">
                    <input type="text" class="form-control" name="subcode" id="supplierInput" required placeholder="Type supplier code or name">
                    <div class="supplier-suggestions" id="supplierSuggestions"></div>
                  </div>
                  <small class="form-text text-muted">Or select from list: 
                    <select class="form-control mt-1" id="supplierSelect">
                      <option value="">Select Supplier</option>
                      <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?= $supplier['CODE'] ?>" data-details="<?= htmlspecialchars($supplier['DETAILS']) ?>">
                          <?= $supplier['CODE'] ?> - <?= $supplier['DETAILS'] ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </small>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Purchase Items -->
        <div class="card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-list"></i> Purchase Items</h5>
            <div>
              <button type="button" class="btn btn-sm btn-primary" id="addItem">
                <i class="fas fa-plus"></i> Add Item
              </button>
              <button type="button" class="btn btn-sm btn-secondary" id="clearItems">
                <i class="fas fa-trash"></i> Clear All
              </button>
            </div>
          </div>
          <div class="card-body">
            <div class="table-container">
              <table class="styled-table" id="itemsTable">
                <thead>
                  <tr>
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
                  <tr id="noItemsRow">
                    <td colspan="8" class="text-center text-muted">No items added</td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr>
                    <td colspan="5" class="text-end fw-bold">Total Amount:</td>
                    <td id="totalAmount">0.00</td>
                    <td colspan="2"></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>
        
        <!-- Charges and Taxes -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-calculator"></i> Charges & Taxes</h5>
          </div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Cash Discount</label>
                  <input type="number" class="form-control" name="cash_disc" value="0.00" step="0.01">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Trade Discount</label>
                  <input type="number" class="form-control" name="trade_disc" value="0.00" step="0.01">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Octroi</label>
                  <input type="number" class="form-control" name="octroi" value="0.00" step="0.01">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Freight Charges</label>
                  <input type="number" class="form-control" name="freight" value="0.00" step="0.01">
                </div>
              </div>
            </div>
            
            <div class="row">
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Sales Tax (%)</label>
                  <input type="number" class="form-control" name="stax_per" value="0.00" step="0.01">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Sales Tax Amount</label>
                  <input type="number" class="form-control" name="stax_amt" value="0.00" step="0.01" readonly>
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">TCS (%)</label>
                  <input type="number" class="form-control" name="tcs_per" value="0.00" step="0.01">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">TCS Amount</label>
                  <input type="number" class="form-control" name="tcs_amt" value="0.00" step="0.01" readonly>
                </div>
              </div>
            </div>
            
            <div class="row">
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Misc. Charges</label>
                  <input type="number" class="form-control" name="misc_charg" value="0.00" step="0.01">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Basic Amount</label>
                  <input type="number" class="form-control" name="basic_amt" value="0.00" step="0.01" readonly>
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Total Amount</label>
                  <input type="number" class="form-control" name="tamt" value="0.00" step="0.01" readonly>
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Action Buttons -->
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save
          </button>
          <a href="purchase_module.php?mode=<?= $mode ?>" class="btn btn-secondary">
            <i class="fas fa-times"></i> Cancel
          </a>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Item Selection Modal -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Select Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <input type="text" class="form-control" id="itemSearch" placeholder="Search items...">
        </div>
        <div class="table-container">
          <table class="styled-table" id="itemsModalTable">
            <thead>
              <tr>
                <th>Code</th>
                <th>Item Name</th>
                <th>Price</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <tr class="item-row-modal">
                  <td><?= $item['CODE'] ?></td>
                  <td><?= $item['DETAILS'] ?></td>
                  <td><?= number_format($item['PPRICE'], 3) ?></td>
                  <td>
                    <button type="button" class="btn btn-sm btn-primary select-item" 
                            data-code="<?= $item['CODE'] ?>" 
                            data-name="<?= htmlspecialchars($item['DETAILS']) ?>" 
                            data-price="<?= $item['PPRICE'] ?>">
                      Select
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Paste Modal -->
<div class="modal fade" id="pasteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Paste SCM Data</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info">
          <p>Copy the table data from the SCM retailer management system (including headers) and paste it in the textarea below.</p>
          <p class="mb-0"><strong>Example format:</strong></p>
          <pre class="mt-2 bg-light p-2 small">
SrNo    ItemName                Size    Qly (Cases)  Qly (Bottles) Batch No.      MRP
1       Desjay Doctor Brandy    180 ML  7.00         0             271            110.00
          </pre>
        </div>
        <textarea class="form-control" id="scmData" rows="10" placeholder="Paste SCM table data here..."></textarea>
        <div class="mt-3">
          <button type="button" class="btn btn-primary" id="processSCMData">
            <i class="fas fa-cog"></i> Process Data
          </button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times"></i> Cancel
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
  let itemCount = 0;
  
  // Show item selection modal
  $('#addItem').click(function() {
    $('#itemModal').modal('show');
  });
  
  // Item search functionality
  $('#itemSearch').on('keyup', function() {
    const value = $(this).val().toLowerCase();
    $('.item-row-modal').filter(function() {
      $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
    });
  });
  
  // Supplier selection from dropdown
  $('#supplierSelect').change(function() {
    const selectedOption = $(this).find('option:selected');
    if (selectedOption.val()) {
      $('#supplierInput').val(selectedOption.val());
    }
  });
  
  // Supplier search suggestions
  $('#supplierInput').on('keyup', function() {
    const query = $(this).val().toLowerCase();
    if (query.length < 2) {
      $('#supplierSuggestions').hide().empty();
      return;
    }
    
    const suggestions = [];
    <?php foreach ($suppliers as $supplier): ?>
      if ('<?= $supplier['CODE'] ?>'.toLowerCase().includes(query) || 
          '<?= $supplier['DETAILS'] ?>'.toLowerCase().includes(query)) {
        suggestions.push({
          code: '<?= $supplier['CODE'] ?>',
          details: '<?= addslashes($supplier['DETAILS']) ?>'
        });
      }
    <?php endforeach; ?>
    
    const suggestionsHtml = suggestions.map(s => 
      `<div class="supplier-suggestion" data-code="${s.code}">${s.code} - ${s.details}</div>`
    ).join('');
    
    $('#supplierSuggestions').html(suggestionsHtml).show();
  });
  
  // Select supplier from suggestions
  $(document).on('click', '.supplier-suggestion', function() {
    const code = $(this).data('code');
    $('#supplierInput').val(code);
    $('#supplierSuggestions').hide();
  });
  
  // Hide suggestions when clicking outside
  $(document).on('click', function(e) {
    if (!$(e.target).closest('.supplier-container').length) {
      $('#supplierSuggestions').hide();
    }
  });
  
  // Clear all items
  $('#clearItems').click(function() {
    if (confirm('Are you sure you want to clear all items?')) {
      $('.item-row').remove();
      $('#itemsTable tbody').append('<tr id="noItemsRow"><td colspan="8" class="text-center text-muted">No items added</td></tr>');
      $('#totalAmount').text('0.00');
      $('input[name="basic_amt"]').val(0);
      $('input[name="tamt"]').val(0);
      itemCount = 0;
    }
  });
  
  // Show paste modal
  $('#pasteFromSCM').click(function() {
    $('#pasteModal').modal('show');
    $('#scmData').val('').focus();
  });
  
  // Process SCM data
  $('#processSCMData').click(function() {
    const scmData = $('#scmData').val().trim();
    if (!scmData) {
      alert('Please paste SCM data first.');
      return;
    }
    
    try {
      const parsedData = parseSCMData(scmData);
      if (parsedData.items.length > 0) {
        // Remove the "no items" row if it exists
        if ($('#noItemsRow').length) {
          $('#noItemsRow').remove();
        }
        
        // Clear existing items
        $('.item-row').remove();
        itemCount = 0;
        
        // Add items to the table
        parsedData.items.forEach(item => {
          addItemToTable(item);
        });
        
        // Update header fields if available
        if (parsedData.tpNo) {
          $('#tpNo').val(parsedData.tpNo);
        }
        if (parsedData.tpDate) {
          $('#tpDate').val(parsedData.tpDate);
        }
        if (parsedData.supplier) {
          $('#supplierInput').val(parsedData.supplier);
        }
        
        // Update totals
        updateTotals();
        
        $('#pasteModal').modal('hide');
        alert(`Successfully imported ${parsedData.items.length} items.`);
      } else {
        alert('No valid items found in the pasted data.');
      }
    } catch (error) {
      alert('Error parsing SCM data: ' + error.message);
      console.error(error);
    }
  });
  
  // Parse SCM data from copied table
  function parseSCMData(data) {
    const lines = data.split('\n').filter(line => line.trim());
    const result = {
      tpNo: '',
      tpDate: '',
      supplier: '',
      items: []
    };
    
    // Extract header information
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i].trim();
      
      // Extract TP No
      if (line.includes('T. P. No') || line.includes('TP No') || line.includes('T.P. No')) {
        const parts = line.split(':');
        if (parts.length > 1) {
          result.tpNo = parts[1].trim();
        } else if (i + 1 < lines.length) {
          result.tpNo = lines[i + 1].trim();
        }
      }
      
      // Extract TP Date
      if (line.includes('T.P.Date') || line.includes('TP Date') || line.includes('T.P. Date')) {
        const parts = line.split(':');
        if (parts.length > 1) {
          const dateStr = parts[1].trim();
          result.tpDate = convertToYMD(dateStr);
        }
      }
      
      // Extract Supplier information
      if (line.includes('Supplier') || line.includes('Party')) {
        const parts = line.split(':');
        if (parts.length > 1) {
          result.supplier = parts[1].trim();
        }
      }
    }
    
    // Find the table data
    let tableStarted = false;
    let headerRowFound = false;
    let headerColumns = [];
    
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i].trim();
      
      // Look for the start of the table (header row)
      if ((line.includes('SrNo') || line.includes('ItemName') || line.includes('Item Name') || 
           line.includes('Size') || line.includes('Qty') || line.includes('Batch No')) && !tableStarted) {
        headerColumns = line.split(/\s{2,}|\t/).filter(col => col.trim());
        headerRowFound = true;
        tableStarted = true;
        continue;
      }
      
      // Look for the end of the table
      if (tableStarted && (line.includes('Total') || line.includes('Transporter') || 
          line.includes('SCM Code Display'))) {
        break;
      }
      
      // Process table rows
      if (tableStarted && line) {
        // Skip if this is still part of the header
        if (!headerRowFound) continue;
        
        // Skip empty rows or summary rows
        if (line.includes('Total') || line.includes('Transporter') || 
            line.includes('SCM Code Display')) {
          break;
        }
        
        // Split by multiple spaces or tabs
        const columns = line.split(/\s{2,}|\t/).filter(col => col.trim());
        
        // Skip empty rows or rows that don't have enough data
        if (columns.length < 3 || isNaN(parseInt(columns[0]))) {
          continue;
        }
        
        // Extract item data
        let itemName = '';
        let size = '';
        let cases = 0;
        let bottles = 0;
        let mrp = 0;
        
        // Find item name (usually the first text column after serial number)
        for (let j = 1; j < columns.length; j++) {
          if (!columns[j].match(/^\d+(\.\d+)?$/) && !columns[j].includes('ML') && 
              !columns[j].includes('L') && columns[j] !== '-') {
            itemName = columns[j];
            break;
          }
        }
        
        // Find size (look for ML or L units)
        for (let j = 1; j < columns.length; j++) {
          if (columns[j].includes('ML') || columns[j].includes('L')) {
            size = columns[j];
            break;
          }
        }
        
        // Find quantities (cases and bottles)
        for (let j = 1; j < columns.length; j++) {
          if (!isNaN(parseFloat(columns[j]))) {
            cases = parseFloat(columns[j]) || 0;
            if (j + 1 < columns.length && !isNaN(parseFloat(columns[j + 1]))) {
              bottles = parseFloat(columns[j + 1]) || 0;
            }
            break;
          }
        }
        
        // Find MRP (look for decimal values, usually at the end)
        for (let j = columns.length - 1; j >= 0; j--) {
          if (!isNaN(parseFloat(columns[j])) && parseFloat(columns[j]) > 1) {
            mrp = parseFloat(columns[j]);
            break;
          }
        }
        
        // Extract just the brand name without SCM code if present
        let brandName = itemName;
        const scmCodeIndex = itemName.indexOf('SCM Code:');
        if (scmCodeIndex !== -1) {
          brandName = itemName.substring(0, scmCodeIndex).trim();
        }
        
        // Remove any asterisks or special characters
        brandName = brandName.replace(/\*/g, '').trim();
        
        // Try to find a matching item in our database
        const matchingItem = findMatchingItem(brandName, size);
        
        result.items.push({
          name: brandName,
          size: size,
          cases: cases,
          bottles: bottles,
          mrp: mrp,
          caseRate: matchingItem ? matchingItem.price : 0,
          code: matchingItem ? matchingItem.code : ''
        });
      }
    }
    
    return result;
  }
  
  // Helper function to convert date format
  function convertToYMD(dateStr) {
    if (!dateStr) return '';
    
    // If already in YYYY-MM-DD format
    if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
      return dateStr;
    }
    
    const dateParts = dateStr.split('-');
    if (dateParts.length === 3) {
      const months = {
        'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04', 'May': '05', 'Jun': '06',
        'Jul': '07', 'Aug': '08', 'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12',
        'January': '01', 'February': '02', 'March': '03', 'April': '04', 'May': '05', 'June': '06',
        'July': '07', 'August': '08', 'September': '09', 'October': '10', 'November': '11', 'December': '12'
      };
      
      let day, month, year;
      
      // Check if format is DD-MMM-YYYY or similar
      if (dateParts[1].length === 3 || months.hasOwnProperty(dateParts[1])) {
        day = dateParts[0];
        month = months[dateParts[1]] || '01';
        year = dateParts[2];
      } 
      // Check if format is MMM-DD-YYYY
      else if (months.hasOwnProperty(dateParts[0])) {
        month = months[dateParts[0]];
        day = dateParts[1];
        year = dateParts[2];
      }
      // Default to first format
      else {
        day = dateParts[0];
        month = dateParts[1].padStart(2, '0');
        year = dateParts[2];
      }
      
      // Ensure day and month are 2 digits
      day = day.padStart(2, '0');
      month = month.padStart(2, '0');
      
      // Handle 2-digit years
      if (year.length === 2) {
        year = '20' + year;
      }
      
      return `${year}-${month}-${day}`;
    }
    
    return dateStr;
  }
  
  // Find matching item in our database
  function findMatchingItem(name, size) {
    if (!name) return null;
    
    const searchName = name.toLowerCase();
    
    // First try exact match
    for (const item of <?= json_encode($items) ?>) {
      const itemName = item.DETAILS.toLowerCase();
      if (itemName === searchName) {
        return {
          code: item.CODE,
          name: item.DETAILS,
          price: item.PPRICE
        };
      }
    }
    
    // Then try partial match
    for (const item of <?= json_encode($items) ?>) {
      const itemName = item.DETAILS.toLowerCase();
      if (itemName.includes(searchName) || searchName.includes(itemName)) {
        return {
          code: item.CODE,
          name: item.DETAILS,
          price: item.PPRICE
        };
      }
    }
    
    // If no match found, return null
    return null;
  }
  
  // Add item to table
  function addItemToTable(item) {
    // Calculate amount based on cases and bottles
    const totalBottles = (item.cases * 12) + item.bottles;
    const amount = (totalBottles * item.caseRate) / 12;
    
    const newRow = `
      <tr class="item-row" data-price="${item.caseRate}">
        <td>
          <input type="hidden" name="items[${itemCount}][code]" value="${item.code || ''}">
          ${item.name}
        </td>
        <td>${item.size}</td>
        <td>
          <input type="number" class="form-control form-control-sm cases" 
                 name="items[${itemCount}][cases]" value="${item.cases}" 
                 min="0" step="1">
        </td>
        <td>
          <input type="number" class="form-control form-control-sm bottles" 
                 name="items[${itemCount}][bottles]" value="${item.bottles}" 
                 min="0" step="1" max="11">
        </td>
        <td>
          <input type="number" class="form-control form-control-sm case-rate" 
                 name="items[${itemCount}][case_rate]" value="${item.caseRate.toFixed(3)}" 
                 step="0.001">
        </td>
        <td class="amount">${amount.toFixed(2)}</td>
        <td>
          <input type="number" class="form-control form-control-sm" 
                 name="items[${itemCount}][mrp]" value="${item.mrp}" 
                 step="0.01">
        </td>
        <td>
          <button type="button" class="btn btn-sm btn-danger remove-item">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      </tr>
    `;
    
    $('#itemsTable tbody').append(newRow);
    itemCount++;
  }
  
  // Handle item selection from modal
  $(document).on('click', '.select-item', function() {
    const code = $(this).data('code');
    const name = $(this).data('name');
    const price = $(this).data('price');
    
    // Remove the "no items" row if it exists
    if ($('#noItemsRow').length) {
      $('#noItemsRow').remove();
    }
    
    // Add new row to the table
    const newRow = `
      <tr class="item-row" data-price="${price}">
        <td>
          <input type="hidden" name="items[${itemCount}][code]" value="${code}">
          ${name}
        </td>
        <td><input type="text" class="form-control form-control-sm" name="items[${itemCount}][size]" placeholder="Size"></td>
        <td><input type="number" class="form-control form-control-sm cases" name="items[${itemCount}][cases]" value="0" min="0" step="1"></td>
        <td><input type="number" class="form-control form-control-sm bottles" name="items[${itemCount}][bottles]" value="0" min="0" step="1" max="11"></td>
        <td><input type="number" class="form-control form-control-sm case-rate" name="items[${itemCount}][case_rate]" value="${price}" step="0.001"></td>
        <td class="amount">0.00</td>
        <td><input type="number" class="form-control form-control-sm" name="items[${itemCount}][mrp]" placeholder="MRP" step="0.01"></td>
        <td><button type="button" class="btn btn-sm btn-danger remove-item"><i class="fas fa-trash"></i></button></td>
      </tr>
    `;
    
    $('#itemsTable tbody').append(newRow);
    itemCount++;
    
    $('#itemModal').modal('hide');
  });
  
  // Handle item removal
  $(document).on('click', '.remove-item', function() {
    $(this).closest('tr').remove();
    
    // If no items left, add the "no items" row
    if ($('.item-row').length === 0) {
      $('#itemsTable tbody').append('<tr id="noItemsRow"><td colspan="8" class="text-center text-muted">No items added</td></tr>');
      $('#totalAmount').text('0.00');
      $('input[name="basic_amt"]').val(0);
      $('input[name="tamt"]').val(0);
    }
  });
  
  // Calculate amount when quantity changes
  $(document).on('input', '.cases, .bottles, .case-rate', function() {
    const row = $(this).closest('tr');
    const caseRate = parseFloat(row.find('.case-rate').val()) || 0;
    const cases = parseInt(row.find('.cases').val()) || 0;
    const bottles = parseInt(row.find('.bottles').val()) || 0;
    
    // Calculate total bottles (assuming 12 bottles per case)
    const totalBottles = (cases * 12) + bottles;
    const amount = (totalBottles * caseRate) / 12;
    
    row.find('.amount').text(amount.toFixed(2));
    
    // Update totals
    updateTotals();
  });
  
  // Calculate tax amounts when percentages change
  $(document).on('input', 'input[name="stax_per"], input[name="tcs_per"], input[name="cash_disc"], input[name="trade_disc"], input[name="octroi"], input[name="freight"], input[name="misc_charg"]', function() {
    calculateTaxes();
  });
  
  function updateTotals() {
    let total = 0;
    
    $('.item-row').each(function() {
      const amount = parseFloat($(this).find('.amount').text()) || 0;
      total += amount;
    });
    
    $('#totalAmount').text(total.toFixed(2));
    $('input[name="basic_amt"]').val(total.toFixed(2));
    
    // Recalculate taxes
    calculateTaxes();
  }
  
  function calculateTaxes() {
    const basicAmt = parseFloat($('input[name="basic_amt"]').val()) || 0;
    const staxPer = parseFloat($('input[name="stax_per"]').val()) || 0;
    const tcsPer = parseFloat($('input[name="tcs_per"]').val()) || 0;
    const cashDisc = parseFloat($('input[name="cash_disc"]').val()) || 0;
    const tradeDisc = parseFloat($('input[name="trade_disc"]').val()) || 0;
    const octroi = parseFloat($('input[name="octroi"]').val()) || 0;
    const freight = parseFloat($('input[name="freight"]').val()) || 0;
    const miscCharg = parseFloat($('input[name="misc_charg"]').val()) || 0;
    
    // Calculate tax amounts
    const staxAmt = (basicAmt * staxPer) / 100;
    const tcsAmt = (basicAmt * tcsPer) / 100;
    
    $('input[name="stax_amt"]').val(staxAmt.toFixed(2));
    $('input[name="tcs_amt"]').val(tcsAmt.toFixed(2));
    
    // Calculate total amount
    const totalAmt = basicAmt + staxAmt + tcsAmt + octroi + freight + miscCharg - cashDisc - tradeDisc;
    $('input[name="tamt"]').val(totalAmt.toFixed(2));
  }
});
</script>
</body>
</html>