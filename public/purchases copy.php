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
      background-color: #f8f9fa;
      border-bottom: 1px solid #dee2e6;
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
            <h5 class="mb-0">Purchase Information</h5>
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
                  <label class="form-label">Supplier Code</label>
                  <input type="text" class="form-control" name="subcode" required>
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
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">T.P. Date</label>
                  <input type="date" class="form-control" name="tp_date" id="tpDate">
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group mb-3">
                  <label class="form-label">Received From</label>
                  <input type="text" class="form-control" name="received_from" id="receivedFrom">
                </div>
              </div>
            </div>
          </div>
        </div>
        
        <!-- Purchase Items -->
        <div class="card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Purchase Items</h5>
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
                    <th>Batch No</th>
                    <th>MRP</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr id="noItemsRow">
                    <td colspan="9" class="text-center text-muted">No items added</td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr>
                    <td colspan="5" class="text-end fw-bold">Total Amount:</td>
                    <td id="totalAmount">0.00</td>
                    <td colspan="3"></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>
        
        <!-- Charges and Taxes -->
        <div class="card mb-4">
          <div class="card-header">
            <h5 class="mb-0">Charges & Taxes</h5>
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
        <div class="table-container">
          <table class="styled-table">
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
                <tr>
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
        </div>
        <textarea class="form-control" id="scmData" rows="10" placeholder="Paste SCM table data here..."></textarea>
        <div class="mt-3">
          <button type="button" class="btn btn-primary" id="processSCMData">
            <i class="fas fa-cog"></i> Process Data
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
  
  // Clear all items
  $('#clearItems').click(function() {
    if (confirm('Are you sure you want to clear all items?')) {
      $('.item-row').remove();
      $('#itemsTable tbody').append('<tr id="noItemsRow"><td colspan="9" class="text-center text-muted">No items added</td></tr>');
      $('#totalAmount').text('0.00');
      $('input[name="basic_amt"]').val(0);
      $('input[name="tamt"]').val(0);
      itemCount = 0;
    }
  });
  
  // Show paste modal
  $('#pasteFromSCM').click(function() {
    $('#pasteModal').modal('show');
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
        if (parsedData.receivedDate) {
          // Convert received date format if needed
          $('input[name="date"]').val(convertToYMD(parsedData.receivedDate));
        }
        if (parsedData.receivedFrom) {
          $('#receivedFrom').val(parsedData.receivedFrom);
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
      receivedDate: '',
      receivedFrom: '',
      party: '',
      validity: '',
      items: []
    };
    
    // Extract header information
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i].trim();
      
      // Extract TP No
      if (line.includes('Auto T. P. No:')) {
        const nextLine = i + 1 < lines.length ? lines[i + 1].trim() : '';
        if (nextLine) {
          result.tpNo = nextLine;
        }
      }
      
      // Extract Manual TP No
      if (line.includes('T. P. No(Manual):')) {
        const nextLine = i + 1 < lines.length ? lines[i + 1].trim() : '';
        if (nextLine && !nextLine.includes('T.P.Date:')) {
          result.tpNo = nextLine;
        }
      }
      
      // Extract TP Date
      if (line.includes('T.P.Date:')) {
        const nextLine = i + 1 < lines.length ? lines[i + 1].trim() : '';
        if (nextLine) {
          // Convert date format from "05-Jul-2025" to "2025-07-05"
          const dateParts = nextLine.split('-');
          if (dateParts.length === 3) {
            const months = {
              'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04', 'May': '05', 'Jun': '06',
              'Jul': '07', 'Aug': '08', 'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12'
            };
            const month = months[dateParts[1]];
            if (month) {
              result.tpDate = `${dateParts[2]}-${month}-${dateParts[0].padStart(2, '0')}`;
            }
          }
        }
      }
      
      // Extract Received Date
      if (line.includes('Received Date :')) {
        const nextLine = i + 1 < lines.length ? lines[i + 1].trim() : '';
        if (nextLine) {
          result.receivedDate = nextLine;
        }
      }
      
      // Extract Received From
      if (line.includes('Received From :')) {
        const nextLine = i + 1 < lines.length ? lines[i + 1].trim() : '';
        if (nextLine) {
          result.receivedFrom = nextLine;
        }
      }
      
      // Extract Party
      if (line.includes('Party :')) {
        const nextLine = i + 1 < lines.length ? lines[i + 1].trim() : '';
        if (nextLine) {
          result.party = nextLine;
        }
      }
      
      // Extract Validity
      if (line.includes('Validity :')) {
        const nextLine = i + 1 < lines.length ? lines[i + 1].trim() : '';
        if (nextLine) {
          result.validity = nextLine;
        }
      }
    }
    
    // Find the table data
    let tableStarted = false;
    for (let i = 0; i < lines.length; i++) {
      const line = lines[i].trim();
      
      // Look for the start of the table (header row)
      if (line.includes('SrNo') || line.includes('ItemName') || line.includes('Size') || 
          line.includes('Qty') || line.includes('Batch No')) {
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
        // Split by multiple spaces or tabs
        const columns = line.split(/\s{2,}|\t/).filter(col => col.trim());
        
        // Skip empty rows or rows that don't have enough data
        if (columns.length < 5 || columns[0] === 'Total') {
          continue;
        }
        
        // Extract item data
        const itemName = columns[1] || '';
        const size = columns[2] || '';
        const cases = parseFloat(columns[3]) || 0;
        const bottles = parseFloat(columns[4]) || 0;
        const batchNo = columns.length > 5 ? columns[5] : '';
        const mrp = columns.length > 7 ? parseFloat(columns[7]) : 0;
        
        // Extract just the brand name without SCM code if present
        let brandName = itemName;
        const scmCodeIndex = itemName.indexOf('SCM Code:');
        if (scmCodeIndex !== -1) {
          brandName = itemName.substring(0, scmCodeIndex).trim();
        }
        
        // Try to find a matching item in our database
        const matchingItem = findMatchingItem(brandName, size);
        
        result.items.push({
          name: brandName,
          size: size,
          cases: cases,
          bottles: bottles,
          batchNo: batchNo,
          mrp: mrp,
          caseRate: matchingItem ? matchingItem.price : 0
        });
      }
    }
    
    return result;
  }
  
  // Helper function to convert date format
  function convertToYMD(dateStr) {
    const dateParts = dateStr.split('-');
    if (dateParts.length === 3) {
      const months = {
        'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04', 'May': '05', 'Jun': '06',
        'Jul': '07', 'Aug': '08', 'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12'
      };
      const month = months[dateParts[1]];
      if (month) {
        return `${dateParts[2]}-${month}-${dateParts[0].padStart(2, '0')}`;
      }
    }
    return dateStr;
  }
  
  // Find matching item in our database
  function findMatchingItem(name, size) {
    // This is a simple implementation - you might want to enhance this
    const searchName = name.toLowerCase();
    const searchSize = size.toLowerCase();
    
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
    
    return null;
  }
  
  // Add item to table
  function addItemToTable(item) {
    const newRow = `
      <tr class="item-row" data-price="${item.caseRate}">
        <td>${item.name} <input type="hidden" name="items[${itemCount}][code]" value="${item.code || ''}"></td>
        <td>${item.size} <input type="hidden" name="items[${itemCount}][size]" value="${item.size}"></td>
        <td><input type="number" class="form-control form-control-sm cases" name="items[${itemCount}][cases]" value="${item.cases}" min="0"></td>
        <td><input type="number" class="form-control form-control-sm bottles" name="items[${itemCount}][bottles]" value="${item.bottles}" min="0"></td>
        <td><input type="number" class="form-control form-control-sm case-rate" name="items[${itemCount}][case_rate]" value="${item.caseRate}" step="0.01"></td>
        <td class="amount">${((item.cases * 12 + item.bottles) * item.caseRate / 12).toFixed(2)}</td>
        <td><input type="text" class="form-control form-control-sm" name="items[${itemCount}][batch_no]" value="${item.batchNo}"></td>
        <td><input type="number" class="form-control form-control-sm" name="items[${itemCount}][mrp]" value="${item.mrp}" step="0.01"></td>
        <td><button type="button" class="btn btn-sm btn-danger remove-item"><i class="fas fa-trash"></i></button></td>
      </tr>
    `;
    
    $('#itemsTable tbody').append(newRow);
    itemCount++;
  }
  
  // Handle item selection from modal
  $('.select-item').click(function() {
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
        <td>${name} <input type="hidden" name="items[${itemCount}][code]" value="${code}"></td>
        <td><input type="text" class="form-control form-control-sm" name="items[${itemCount}][size]" placeholder="Size"></td>
        <td><input type="number" class="form-control form-control-sm cases" name="items[${itemCount}][cases]" value="0" min="0"></td>
        <td><input type="number" class="form-control form-control-sm bottles" name="items[${itemCount}][bottles]" value="0" min="0"></td>
        <td><input type="number" class="form-control form-control-sm case-rate" name="items[${itemCount}][case_rate]" value="${price}" step="0.01"></td>
        <td class="amount">0.00</td>
        <td><input type="text" class="form-control form-control-sm" name="items[${itemCount}][batch_no]" placeholder="Batch No"></td>
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
      $('#itemsTable tbody').append('<tr id="noItemsRow"><td colspan="9" class="text-center text-muted">No items added</td></tr>');
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
  $(document).on('input', 'input[name="stax_per"], input[name="tcs_per"]', function() {
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