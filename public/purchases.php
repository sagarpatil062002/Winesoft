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

include_once "../config/db.php"; // MySQLi connection in $conn

// Initialize variables
$items = [];
$purchaseItems = [];
$totalAmount = 0;

// Handle form submission for purchase entry
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Process purchase items
    if (isset($_POST['add_items'])) {
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            // If we have existing items, merge them with new ones
            $existingItems = isset($_SESSION['purchase_items']) ? $_SESSION['purchase_items'] : [];
            
            // Handle item removal if requested
            if (isset($_POST['remove_item']) && is_array($_POST['remove_item'])) {
                foreach ($_POST['remove_item'] as $indexToRemove) {
                    if (isset($existingItems[$indexToRemove])) {
                        unset($existingItems[$indexToRemove]);
                    }
                }
                // Reindex the array
                $existingItems = array_values($existingItems);
            }
            
            // Add new items
            $newItems = $_POST['items'];
            $_SESSION['purchase_items'] = array_merge($existingItems, $newItems);
            
            $_SESSION['success'] = "Items added successfully!";
        }
    }
    
    // Finalize purchase
    if (isset($_POST['finalize_purchase'])) {
        if (!isset($_SESSION['purchase_items']) || empty($_SESSION['purchase_items'])) {
            $_SESSION['error'] = "No items to process purchase";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get next purchase number
                $purchaseNoQuery = "SELECT MAX(CAST(SUBSTRING(PURCHASE_NO, 3) AS UNSIGNED)) as max_purchase FROM tblpurchaseheader";
                $purchaseResult = $conn->query($purchaseNoQuery);
                
                $purchaseNo = 1;
                if ($purchaseResult->num_rows > 0) {
                    $purchaseData = $purchaseResult->fetch_assoc();
                    $purchaseNo = (int)$purchaseData['max_purchase'] + 1;
                }
                
                $user_id = $_SESSION['user_id'];
                $comp_id = $_SESSION['CompID'];
                $fin_year_id = $_SESSION['FIN_YEAR_ID'];
                
                $purchase_date = date('Y-m-d H:i:s');
                $total_amount = 0;
                
                // Calculate total amount
                foreach ($_SESSION['purchase_items'] as $item) {
                    $cases = isset($item['cases']) ? floatval($item['cases']) : 0;
                    $case_rate = isset($item['case_rate']) ? floatval($item['case_rate']) : 0;
                    $total_amount += $cases * $case_rate;
                }
                
                // Insert purchase header
                $header_query = "INSERT INTO tblpurchaseheader (PURCHASE_NO, PURCHASE_DATE, TOTAL_AMOUNT, SUPPLIER_ID, COMP_ID, CREATED_BY) 
                                 VALUES (?, ?, ?, ?, ?, ?)";
                $header_stmt = $conn->prepare($header_query);
                $supplier_id = 1; // You'll need to get this from your form
                $header_stmt->bind_param("ssdsii", $purchaseNo, $purchase_date, $total_amount, $supplier_id, $comp_id, $user_id);
                $header_stmt->execute();
                $header_stmt->close();
                
                // Insert purchase details and update stock
                foreach ($_SESSION['purchase_items'] as $item) {
                    $cases = isset($item['cases']) ? floatval($item['cases']) : 0;
                    $bottles = isset($item['bottles']) ? floatval($item['bottles']) : 0;
                    $case_rate = isset($item['case_rate']) ? floatval($item['case_rate']) : 0;
                    $amount = $cases * $case_rate;
                    
                    // Insert purchase details
                    $detail_query = "INSERT INTO tblpurchasedetails (PURCHASE_NO, ITEM_CODE, CASES, BOTTLES, RATE, AMOUNT, BATCH_NO, AUTO_BATCH, MFG_MONTH, BL, VV, TOT_BOTT, COMP_ID) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $detail_stmt = $conn->prepare($detail_query);
                    $detail_stmt->bind_param(
                        "ssddddsssdddi", 
                        $purchaseNo, 
                        $item['code'], 
                        $cases, 
                        $bottles, 
                        $case_rate, 
                        $amount,
                        $item['batch_no'],
                        $item['auto_batch'],
                        $item['mfg_month'],
                        $item['bl'],
                        $item['vv'],
                        $item['tot_bott'],
                        $comp_id
                    );
                    $detail_stmt->execute();
                    $detail_stmt->close();
                    
                    // Update stock (you'll need to adjust this based on your stock table structure)
                    $stock_query = "UPDATE tblitem_stock SET Current_Stock{$comp_id} = Current_Stock{$comp_id} + ? WHERE ITEM_CODE = ?";
                    $stock_stmt = $conn->prepare($stock_query);
                    // Calculate total quantity (cases * bottles_per_case + loose bottles)
                    $bottles_per_case = 12; // You'll need to get this from your items table
                    $total_quantity = ($cases * $bottles_per_case) + $bottles;
                    $stock_stmt->bind_param("ds", $total_quantity, $item['code']);
                    $stock_stmt->execute();
                    $stock_stmt->close();
                }
                
                // Commit transaction
                $conn->commit();
                
                // Clear purchase items
                unset($_SESSION['purchase_items']);
                
                $_SESSION['success'] = "Purchase finalized successfully! Purchase No: " . $purchaseNo;
                header("Location: purchases.php");
                exit;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $_SESSION['error'] = "Error processing purchase: " . $e->getMessage();
            }
        }
    }
    
    // Clear items
    if (isset($_POST['clear_items'])) {
        unset($_SESSION['purchase_items']);
        $_SESSION['success'] = "Items cleared successfully!";
    }
}

// Get purchase items from session if available
if (isset($_SESSION['purchase_items'])) {
    $purchaseItems = $_SESSION['purchase_items'];
    foreach ($purchaseItems as $item) {
        $cases = isset($item['cases']) ? floatval($item['cases']) : 0;
        $case_rate = isset($item['case_rate']) ? floatval($item['case_rate']) : 0;
        $totalAmount += $cases * $case_rate;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Entry - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .purchase-container {
      display: flex;
      gap: 20px;
    }
    .items-section {
      flex: 2;
    }
    .summary-section {
      flex: 1;
      background-color: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
    }
    .styled-table {
      width: 100%;
      border-collapse: collapse;
      margin: 25px 0;
      font-size: 0.9em;
      font-family: sans-serif;
      box-shadow: 0 0 20px rgba(0, 0, 0, 0.15);
    }
    .styled-table thead tr {
      background-color: #009879;
      color: #ffffff;
      text-align: left;
    }
    .styled-table th,
    .styled-table td {
      padding: 8px 10px;
      border: 1px solid #ddd;
    }
    .styled-table tbody tr {
      border-bottom: 1px solid #dddddd;
    }
    .styled-table tbody tr:nth-of-type(even) {
      background-color: #f3f3f3;
    }
    .styled-table tbody tr:last-of-type {
      border-bottom: 2px solid #009879;
    }
    .styled-table input {
      width: 100%;
      padding: 4px;
      border: 1px solid #ddd;
      border-radius: 3px;
    }
    .col-srno { width: 40px; }
    .col-name { width: 160px; }
    .col-size { width: 60px; }
    .col-cases { width: 70px; }
    .col-bottles { width: 70px; }
    .col-batch { width: 70px; }
    .col-auto-batch { width: 100px; }
    .col-mfg { width: 80px; }
    .col-mrp { width: 60px; }
    .col-bl { width: 60px; }
    .col-vv { width: 60px; }
    .col-totbott { width: 70px; }
    .col-rate { width: 80px; }
    .col-action { width: 50px; }
    .paste-highlight {
      background-color: #ffffcc !important;
      transition: background-color 0.5s ease;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Purchase Entry</h3>

      <!-- Success/Error Messages -->
      <?php if (isset($_SESSION['error'])): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $_SESSION['error'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <?php if (isset($_SESSION['success'])): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $_SESSION['success'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php unset($_SESSION['success']); ?>
      <?php endif; ?>

      <div class="purchase-container">
        <!-- Items Section -->
        <div class="items-section">
          <form method="POST" id="purchaseForm">
            <!-- Purchase Items Table (SCM Structure) -->
            <table class="styled-table" id="itemsTable" tabindex="0" style="outline: none;">
              <thead>
                <tr align="center" style="font-weight:bold;">
                  <th class="col-srno">SrNo</th>
                  <th class="col-name">ItemName</th>
                  <th class="col-size">Size</th>
                  <th class="col-cases">Qty (Cases)</th>
                  <th class="col-bottles">Qty (Bottles)</th>
                  <th class="col-batch">Batch No</th>
                  <th class="col-auto-batch">Auto Batch</th>
                  <th class="col-mfg">Mfg. Month</th>
                  <th class="col-mrp">MRP</th>
                  <th class="col-bl">B.L.</th>
                  <th class="col-vv">V/v (%)</th>
                  <th class="col-totbott">Tot. Bott.</th>
                  <th class="col-rate">Case Rate</th>
                  <th class="col-action">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($purchaseItems)): ?>
                  <?php $counter = 1; ?>
                  <?php foreach ($purchaseItems as $index => $item): ?>
                    <tr>
                      <td><?= $counter++ ?></td>
                      <td>
                        <input type="hidden" name="items[<?= $index ?>][code]" value="<?= htmlspecialchars($item['code'] ?? '') ?>">
                        <input type="hidden" name="items[<?= $index ?>][name]" value="<?= htmlspecialchars($item['name'] ?? '') ?>">
                        <?= htmlspecialchars($item['name'] ?? '') ?>
                      </td>
                      <td>
                        <input type="hidden" name="items[<?= $index ?>][size]" value="<?= htmlspecialchars($item['size'] ?? '') ?>">
                        <?= htmlspecialchars($item['size'] ?? '') ?>
                      </td>
                      <td><input type="number" class="form-control form-control-sm" name="items[<?= $index ?>][cases]" value="<?= htmlspecialchars($item['cases'] ?? '0') ?>" min="0" step="0.01"></td>
                      <td><input type="number" class="form-control form-control-sm" name="items[<?= $index ?>][bottles]" value="<?= htmlspecialchars($item['bottles'] ?? '0') ?>" min="0" step="1"></td>
                      <td><input type="text" class="form-control form-control-sm" name="items[<?= $index ?>][batch_no]" value="<?= htmlspecialchars($item['batch_no'] ?? '') ?>"></td>
                      <td><input type="text" class="form-control form-control-sm" name="items[<?= $index ?>][auto_batch]" value="<?= htmlspecialchars($item['auto_batch'] ?? '') ?>"></td>
                      <td><input type="text" class="form-control form-control-sm" name="items[<?= $index ?>][mfg_month]" value="<?= htmlspecialchars($item['mfg_month'] ?? '') ?>"></td>
                      <td><input type="number" class="form-control form-control-sm" name="items[<?= $index ?>][mrp]" value="<?= htmlspecialchars($item['mrp'] ?? '0') ?>" step="0.01"></td>
                      <td><input type="number" class="form-control form-control-sm" name="items[<?= $index ?>][bl]" value="<?= htmlspecialchars($item['bl'] ?? '0') ?>" step="0.01"></td>
                      <td><input type="number" class="form-control form-control-sm" name="items[<?= $index ?>][vv]" value="<?= htmlspecialchars($item['vv'] ?? '0') ?>" step="0.1"></td>
                      <td><input type="number" class="form-control form-control-sm" name="items[<?= $index ?>][tot_bott]" value="<?= htmlspecialchars($item['tot_bott'] ?? '0') ?>" step="1"></td>
                      <td><input type="number" class="form-control form-control-sm" name="items[<?= $index ?>][case_rate]" value="<?= htmlspecialchars($item['case_rate'] ?? '0') ?>" step="0.01" required></td>
                      <td>
                        <button type="button" class="btn btn-sm btn-danger remove-item" data-index="<?= $index ?>">
                          <i class="fas fa-trash"></i>
                        </button>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="14" class="text-center py-3">
                      <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Copy & Paste Instructions:</strong><br>
                        1. Select and copy the entire table from SCM system (Ctrl+A, Ctrl+C)<br>
                        2. Click anywhere in this table and paste (Ctrl+V)<br>
                        3. Click "Save Items" to process the data
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>

            <div class="mt-3">
              <button type="submit" name="add_items" class="btn btn-primary">
                <i class="fas fa-save me-2"></i> Save Items
              </button>
              <button type="submit" name="clear_items" class="btn btn-secondary">
                <i class="fas fa-trash me-2"></i> Clear All
              </button>
            </div>
          </form>
        </div>

        <!-- Summary Section -->
        <div class="summary-section">
          <h4 class="mb-3"><i class="fas fa-list me-2"></i>Purchase Summary</h4>
          
          <?php if (!empty($purchaseItems)): ?>
            <div class="summary-items mb-3">
              <?php foreach ($purchaseItems as $item): ?>
                <div class="summary-item border-bottom py-2">
                  <div class="d-flex justify-content-between">
                    <div class="fw-semibold"><?= htmlspecialchars($item['name'] ?? '') ?></div>
                    <div>
                      <?php
                      $cases = isset($item['cases']) ? floatval($item['cases']) : 0;
                      $case_rate = isset($item['case_rate']) ? floatval($item['case_rate']) : 0;
                      $amount = $cases * $case_rate;
                      ?>
                      ₹<?= number_format($amount, 2) ?>
                    </div>
                  </div>
                  <div class="text-muted small">
                    <?= htmlspecialchars($item['size'] ?? '') ?> | 
                    Cases: <?= htmlspecialchars($item['cases'] ?? '0') ?> | 
                    Bottles: <?= htmlspecialchars($item['bottles'] ?? '0') ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            
            <div class="summary-total mb-3 p-2 bg-light rounded">
              <div class="d-flex justify-content-between fw-bold fs-5">
                <span>Total Amount:</span>
                <span>₹<?= number_format($totalAmount, 2) ?></span>
              </div>
            </div>
            
            <div class="summary-actions">
              <form method="POST">
                <button type="submit" name="finalize_purchase" class="btn btn-success w-100">
                  <i class="fas fa-check-circle me-2"></i> Finalize Purchase
                </button>
              </form>
            </div>
          <?php else: ?>
            <div class="alert alert-info">
              <i class="fas fa-info-circle me-2"></i>
              No items added yet. Paste your purchase data in the table and click "Save Items".
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
  // Handle remove item button
  $(document).on('click', '.remove-item', function() {
    const index = $(this).data('index');
    if (confirm('Are you sure you want to remove this item?')) {
      // Create a hidden input to mark this item for removal
      $('<input>').attr({
        type: 'hidden',
        name: 'remove_item[]',
        value: index
      }).appendTo('#purchaseForm');
      
      // Submit the form
      $('#purchaseForm').append('<input type="hidden" name="add_items" value="1">');
      $('#purchaseForm').submit();
    }
  });

  // Handle paste event to capture data from SCM system
  $('#itemsTable').on('paste', function(e) {
    const clipboardData = e.originalEvent.clipboardData || window.clipboardData;
    const pastedData = clipboardData.getData('text');
    
    // Parse the pasted data (tab-separated values from SCM)
    const rows = pastedData.split('\n').filter(row => row.trim() !== '');
    const items = [];
    
    for (let i = 0; i < rows.length; i++) {
      const cells = rows[i].split('\t');
      
      // Skip empty rows
      if (cells.length < 2) continue;
      
      // Skip header row if it contains specific SCM headers
      if (i === 0 && (
        cells[0] === 'SrNo' || 
        cells[1] === 'ItemName' || 
        cells[2] === 'Size'
      )) {
        continue;
      }
      
      // Skip total row
      if (cells[0] === 'Total' || cells[1] === 'Total') {
        continue;
      }
      
      // Handle different SCM table formats
      if (cells.length >= 12) {
        // Standard SCM format with all columns
        items.push({
          code: cells[0] || '',
          name: cells[1] || '',
          size: cells[2] || '',
          cases: cells[3] || '0',
          bottles: cells[4] || '0',
          batch_no: cells[5] || '',
          auto_batch: cells[6] || '',
          mfg_month: cells[7] || '',
          mrp: cells[8] || '0',
          bl: cells[9] || '0',
          vv: cells[10] || '0',
          tot_bott: cells[11] || '0',
          case_rate: '0' // Default case rate
        });
      } else if (cells.length >= 8) {
        // Alternative SCM format with fewer columns
        items.push({
          code: '',
          name: cells[0] || '',
          size: cells[1] || '',
          cases: cells[2] || '0',
          bottles: cells[3] || '0',
          batch_no: cells[4] || '',
          auto_batch: cells[5] || '',
          mfg_month: cells[6] || '',
          mrp: cells[7] || '0',
          bl: '0',
          vv: '0',
          tot_bott: '0',
          case_rate: '0' // Default case rate
        });
      }
    }
    
    if (items.length > 0) {
      // Create a form to submit the pasted data
      const form = document.createElement('form');
      form.method = 'POST';
      form.style.display = 'none';
      
      items.forEach((item, index) => {
        for (const key in item) {
          const input = document.createElement('input');
          input.type = 'hidden';
          input.name = `items[${index}][${key}]`;
          input.value = item[key];
          form.appendChild(input);
        }
      });
      
      const submitInput = document.createElement('input');
      submitInput.type = 'hidden';
      submitInput.name = 'add_items';
      submitInput.value = '1';
      form.appendChild(submitInput);
      
      document.body.appendChild(form);
      form.submit();
    } else {
      alert('No valid data found in the clipboard. Please copy the table data from SCM system.');
    }
  });

  // Add keyboard shortcut for paste (Ctrl+V)
  $(document).on('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
      // Focus on the table to trigger the paste event
      $('#itemsTable').focus();
    }
  });

  // Add visual feedback for paste
  $('#itemsTable').on('paste', function(e) {
    $(this).addClass('paste-highlight');
    setTimeout(() => {
      $(this).removeClass('paste-highlight');
    }, 1000);
  });

  // Make the table focusable and show focus style
  $('#itemsTable').on('focus', function() {
    $(this).css('box-shadow', '0 0 0 2px #007bff');
  });
  $('#itemsTable').on('blur', function() {
    $(this).css('box-shadow', 'none');
  });
});
</script>
</body>
</html>