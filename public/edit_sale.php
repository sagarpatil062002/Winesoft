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

// Get company ID from session
$compID = $_SESSION['CompID'];

// Check if bill number is provided
if (!isset($_GET['bill_no'])) {
    $_SESSION['error'] = "No bill number specified!";
    header("Location: retail_sale.php");
    exit;
}

$bill_no = $_GET['bill_no'];

// Fetch bill header information
$headerQuery = "SELECT * FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
$headerStmt = $conn->prepare($headerQuery);
$headerStmt->bind_param("si", $bill_no, $compID);
$headerStmt->execute();
$headerResult = $headerStmt->get_result();

if ($headerResult->num_rows === 0) {
    $_SESSION['error'] = "Bill not found!";
    header("Location: retail_sale.php");
    exit;
}

$billHeader = $headerResult->fetch_assoc();
$headerStmt->close();

// Fetch bill items
$itemsQuery = "SELECT 
                sd.ITEM_CODE, 
                im.DETAILS as ITEM_NAME, 
                sd.QTY, 
                sd.RATE, 
                sd.AMOUNT 
               FROM tblsaledetails sd
               JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
               WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?
               ORDER BY im.DETAILS";
$itemsStmt = $conn->prepare($itemsQuery);
$itemsStmt->bind_param("si", $bill_no, $compID);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$billItems = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

// Handle form submission for updating the bill
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug: Check what's being posted
    error_log("POST data: " . print_r($_POST, true));
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete existing items
        $deleteQuery = "DELETE FROM tblsaledetails WHERE BILL_NO = ? AND COMP_ID = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bind_param("si", $bill_no, $compID);
        if (!$deleteStmt->execute()) {
            throw new Exception("Error deleting existing items: " . $conn->error);
        }
        $deleteStmt->close();
        
        // Check if items are posted
        if (!isset($_POST['items']) || !is_array($_POST['items'])) {
            throw new Exception("No items data received");
        }
        
        // Insert new items
        $insertQuery = "INSERT INTO tblsaledetails (BILL_NO, COMP_ID, ITEM_CODE, QTY, RATE, AMOUNT) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        $insertStmt = $conn->prepare($insertQuery);
        
        $newTotal = 0;
        $items = $_POST['items'];
        
        foreach ($items as $index => $item) {
            // Validate item data
            if (empty($item['code']) || empty($item['qty']) || empty($item['rate'])) {
                throw new Exception("Invalid item data at index $index");
            }
            
            $itemCode = $item['code'];
            $qty = floatval($item['qty']);
            $rate = floatval($item['rate']);
            $amount = $qty * $rate;
            
            // Debug output
            error_log("Inserting item: BILL_NO=$bill_no, COMP_ID=$compID, ITEM_CODE=$itemCode, QTY=$qty, RATE=$rate, AMOUNT=$amount");
            
            // Check if the statement was prepared successfully
            if (!$insertStmt) {
                throw new Exception("Failed to prepare insert statement: " . $conn->error);
            }
            
            // Convert bill_no to string if it's numeric
            $billNoStr = (string)$bill_no;
            
            $bindResult = $insertStmt->bind_param("sisddd", $billNoStr, $compID, $itemCode, $qty, $rate, $amount);
            if (!$bindResult) {
                throw new Exception("Failed to bind parameters: " . $insertStmt->error);
            }
            
            if (!$insertStmt->execute()) {
                throw new Exception("Error inserting item $itemCode: " . $insertStmt->error);
            }
            
            $newTotal += $amount;
        }
        
        $insertStmt->close();
        
        // Update header with new totals
        $discount = floatval($_POST['discount']);
        $netAmount = $newTotal - $discount;
        
        $updateHeaderQuery = "UPDATE tblsaleheader 
                              SET TOTAL_AMOUNT = ?, DISCOUNT = ?, NET_AMOUNT = ? 
                              WHERE BILL_NO = ? AND COMP_ID = ?";
        $updateHeaderStmt = $conn->prepare($updateHeaderQuery);
        
        // Convert bill_no to string for header update as well
        $billNoStr = (string)$bill_no;
        $updateHeaderStmt->bind_param("dddss", $newTotal, $discount, $netAmount, $billNoStr, $compID);
        
        if (!$updateHeaderStmt->execute()) {
            throw new Exception("Error updating header: " . $updateHeaderStmt->error);
        }
        
        $updateHeaderStmt->close();
        
        $conn->commit();
        
        $_SESSION['success'] = "Bill #$bill_no updated successfully!";
        header("Location: retail_sale.php");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error updating bill: " . $e->getMessage();
        error_log("Update error: " . $e->getMessage());
        header("Location: edit_sale.php?bill_no=$bill_no");
        exit;
    }
}

// Fetch all available items for the dropdown with their prices
$allItemsQuery = "SELECT i.CODE, i.DETAILS, i.DETAILS2, COALESCE(cp.WPrice, i.BPRICE) as Price 
                  FROM tblitemmaster i 
                  LEFT JOIN tblcustomerprices cp ON i.CODE = cp.CODE 
                  GROUP BY i.CODE 
                  ORDER BY i.DETAILS";
$allItemsStmt = $conn->prepare($allItemsQuery);
$allItemsStmt->execute();
$allItemsResult = $allItemsStmt->get_result();
$allItems = $allItemsResult->fetch_all(MYSQLI_ASSOC);
$allItemsStmt->close();

// Prepare item options for Select2
$itemOptions = [];
foreach ($allItems as $item) {
    $itemOptions[$item['CODE']] = [
        'name' => $item['DETAILS'] . ' (' . $item['DETAILS2'] . ')',
        'price' => $item['Price']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Sale - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <!-- Select2 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .item-row { margin-bottom: 10px; }
    .totals-section { background-color: #f8f9fa; padding: 15px; border-radius: 5px; }
    /* Custom styling for Select2 */
    .select2-container--bootstrap-5 .select2-selection {
      min-height: 38px;
      padding: 5px;
    }
    .select2-results__option {
      padding: 8px 12px;
    }
    .item-option {
      display: flex;
      justify-content: space-between;
    }
    .item-code {
      font-size: 0.85rem;
      color: #6c757d;
    }
    .item-price {
      font-size: 0.9rem; 
      color: #6c757d;
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
        <h4>Edit Sale Bill #<?= $bill_no ?></h4>
        <a href="retail_sale.php" class="btn btn-secondary">
          <i class="fa-solid fa-arrow-left me-1"></i> Back to Sales
        </a>
      </div>

      <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-exclamation me-2"></i> <?= $_SESSION['error'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <form method="POST" id="editSaleForm">
        <div class="card mb-4">
          <div class="card-header fw-semibold">
            <i class="fa-solid fa-list me-2"></i> Bill Items
          </div>
          <div class="card-body">
            <div id="itemsContainer">
              <?php foreach($billItems as $index => $item): ?>
                <div class="item-row row g-3">
                  <div class="col-md-5">
                    <label class="form-label">Item</label>
                    <select name="items[<?= $index ?>][code]" class="form-select item-select select2-item" required>
                      <option value="">Select Item</option>
                      <?php foreach($itemOptions as $code => $itemData): ?>
                        <option value="<?= $code ?>" data-price="<?= $itemData['price'] ?>" 
                          <?= $code == $item['ITEM_CODE'] ? 'selected' : '' ?>>
                          <?= htmlspecialchars($itemData['name']) ?> 
                          <span class="item-price">(₹<?= number_format($itemData['price'], 2) ?>)</span>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Qty</label>
                    <input type="number" name="items[<?= $index ?>][qty]" class="form-control qty-input" 
                           step="0.01" min="0.01" value="<?= $item['QTY'] ?>" required>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Rate</label>
                    <input type="number" name="items[<?= $index ?>][rate]" class="form-control rate-input" 
                           step="0.01" min="0.01" value="<?= $item['RATE'] ?>" required>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">Amount</label>
                    <input type="text" class="form-control amount-display" value="<?= $item['AMOUNT'] ?>" readonly>
                  </div>
                  <div class="col-md-1 d-flex align-items-end">
                    <button type="button" class="btn btn-danger remove-item-btn" 
                            <?= count($billItems) <= 1 ? 'disabled' : '' ?>>
                      <i class="fa-solid fa-trash"></i>
                    </button>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            
            <div class="mt-3">
              <button type="button" id="addItemBtn" class="btn btn-success">
                <i class="fa-solid fa-plus me-1"></i> Add Item
              </button>
            </div>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header fw-semibold">
            <i class="fa-solid fa-calculator me-2"></i> Bill Totals
          </div>
          <div class="card-body">
            <div class="row g-3 totals-section">
              <div class="col-md-4 offset-md-6">
                <label class="form-label">Total Amount</label>
                <input type="text" id="totalAmount" class="form-control" 
                       value="<?= $billHeader['TOTAL_AMOUNT'] ?>" readonly>
              </div>
              <div class="col-md-4 offset-md-6">
                <label class="form-label">Discount</label>
                <input type="number" id="discount" name="discount" class="form-control" 
                       step="0.01" min="0" value="<?= $billHeader['DISCOUNT'] ?>" required>
              </div>
              <div class="col-md-4 offset-md-6">
                <label class="form-label">Net Amount</label>
                <input type="text" id="netAmount" class="form-control" 
                       value="<?= $billHeader['NET_AMOUNT'] ?>" readonly>
              </div>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-floppy-disk me-1"></i> Update Bill
          </button>
          <a href="sales_management.php" class="btn btn-secondary">Cancel</a>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
  let itemCount = <?= count($billItems) ?>;
  
  // Initialize Select2 for all item selects
  function initSelect2() {
    $('.select2-item').select2({
      theme: 'bootstrap-5',
      placeholder: "Type to search items...",
      allowClear: true,
      templateResult: formatItem,
      templateSelection: formatItemSelection
    });
  }
  
  // Format how items appear in the dropdown
  function formatItem(item) {
    if (!item.id) {
      return item.text;
    }
    
    var $item = $(
      '<div class="item-option">' +
        '<div>' + item.text + '</div>' +
        '<div class="item-code">' + item.id + '</div>' +
      '</div>'
    );
    return $item;
  }
  
  // Format how the selected item appears
  function formatItemSelection(item) {
    if (!item.id) {
      return item.text;
    }
    // Show just the item name in the selection
    return item.text.split(' (₹')[0];
  }
  
  // Initialize Select2 on page load
  initSelect2();
  
  // Add new item row
  $('#addItemBtn').click(function() {
    const newIndex = itemCount++;
    const newRow = `
      <div class="item-row row g-3">
        <div class="col-md-5">
          <label class="form-label">Item</label>
          <select name="items[${newIndex}][code]" class="form-select item-select select2-item" required>
            <option value="">Select Item</option>
            <?php foreach($itemOptions as $code => $itemData): ?>
              <option value="<?= $code ?>" data-price="<?= $itemData['price'] ?>">
                <?= htmlspecialchars($itemData['name']) ?> 
                <span class="item-price">(₹<?= number_format($itemData['price'], 2) ?>)</span>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Qty</label>
          <input type="number" name="items[${newIndex}][qty]" class="form-control qty-input" 
                 step="0.01" min="0.01" value="1" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Rate</label>
          <input type="number" name="items[${newIndex}][rate]" class="form-control rate-input" 
                 step="0.01" min="0.01" value="0" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Amount</label>
          <input type="text" class="form-control amount-display" value="0.00" readonly>
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button type="button" class="btn btn-danger remove-item-btn">
            <i class="fa-solid fa-trash"></i>
          </button>
        </div>
      </div>
    `;
    
    $('#itemsContainer').append(newRow);
    initSelect2(); // Initialize Select2 for the new dropdown
    
    // Add event listeners to the new row
    const newRowElement = $('#itemsContainer .item-row').last();
    newRowElement.find('.qty-input, .rate-input').on('input', calculateRowAmount);
    newRowElement.find('.select2-item').on('change', autoFillRate);
    
    updateRemoveButtons();
  });
  
  // Calculate amount for a single row
  function calculateRowAmount() {
    const row = $(this).closest('.item-row');
    const qty = parseFloat(row.find('.qty-input').val()) || 0;
    const rate = parseFloat(row.find('.rate-input').val()) || 0;
    const amount = qty * rate;
    
    row.find('.amount-display').val(amount.toFixed(2));
    recalculateTotals();
  }
  
  // Auto-fill rate when item is selected
  function autoFillRate() {
    const row = $(this).closest('.item-row');
    const selectedOption = $(this).find('option:selected');
    const price = selectedOption.data('price');
    
    if (price) {
      row.find('.rate-input').val(price);
      
      // Trigger calculation
      const qty = parseFloat(row.find('.qty-input').val()) || 0;
      const amount = qty * price;
      row.find('.amount-display').val(amount.toFixed(2));
      recalculateTotals();
    }
  }
  
  // Remove item row
  $(document).on('click', '.remove-item-btn', function() {
    if ($('.item-row').length > 1) {
      $(this).closest('.item-row').remove();
      recalculateTotals();
      updateRemoveButtons();
    }
  });
  
  // Calculate amount when qty or rate changes
  $(document).on('input', '.qty-input, .rate-input', calculateRowAmount);
  
  // Auto-fill rate when item is selected
  $(document).on('change', '.select2-item', autoFillRate);
  
  // Update discount and net amount
  $('#discount').on('input', function() {
    recalculateTotals();
  });
  
  // Update remove buttons state
  function updateRemoveButtons() {
    const itemRows = $('.item-row');
    itemRows.find('.remove-item-btn').prop('disabled', itemRows.length <= 1);
  }
  
  // Recalculate all totals
  function recalculateTotals() {
    let total = 0;
    
    $('.item-row').each(function() {
      const amount = parseFloat($(this).find('.amount-display').val()) || 0;
      total += amount;
    });
    
    const discount = parseFloat($('#discount').val()) || 0;
    const netAmount = total - discount;
    
    $('#totalAmount').val(total.toFixed(2));
    $('#netAmount').val(netAmount.toFixed(2));
  }
  
  // Initialize
  updateRemoveButtons();
  recalculateTotals();
});
</script>
</body>
</html>