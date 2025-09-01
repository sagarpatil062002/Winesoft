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
$breakageItems = [];
$totalAmount = 0;

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add item to breakage
    if (isset($_POST['add_item'])) {
        $itemCode = trim($_POST['item_code']);
        $quantity = (int)$_POST['quantity'];
        
        // Validate quantity
        if ($quantity <= 0) {
            $_SESSION['error'] = "Quantity must be greater than zero";
        } else {
            // Get item details
            $itemQuery = "SELECT CODE, DETAILS, DETAILS2, PPRICE FROM tblitemmaster WHERE CODE = ?";
            $stmt = $conn->prepare($itemQuery);
            $stmt->bind_param("s", $itemCode);
            $stmt->execute();
            $itemResult = $stmt->get_result();
            
            if ($itemResult->num_rows > 0) {
                $item = $itemResult->fetch_assoc();
                
                // Use purchase price for breakage
                $price = $item['PPRICE'];
                
                // Add to session cart
                if (!isset($_SESSION['breakage_cart'])) {
                    $_SESSION['breakage_cart'] = [];
                }
                
                $itemKey = $itemCode;
                if (isset($_SESSION['breakage_cart'][$itemKey])) {
                    $_SESSION['breakage_cart'][$itemKey]['quantity'] += $quantity;
                    $_SESSION['breakage_cart'][$itemKey]['amount'] = $_SESSION['breakage_cart'][$itemKey]['quantity'] * $price;
                } else {
                    $_SESSION['breakage_cart'][$itemKey] = [
                        'code' => $itemCode,
                        'name' => $item['DETAILS'],
                        'size' => $item['DETAILS2'],
                        'rate' => $price,
                        'quantity' => $quantity,
                        'amount' => $quantity * $price
                    ];
                }
            } else {
                $_SESSION['error'] = "Invalid item code selected";
            }
        }
    }
    
    // Update item price
    if (isset($_POST['update_price'])) {
        $itemCode = trim($_POST['item_code']);
        $newPrice = (float)$_POST['new_price'];
        
        if (!empty($itemCode) && $newPrice > 0) {
            // Update the price in the cart if the item exists
            if (isset($_SESSION['breakage_cart'][$itemCode])) {
                $_SESSION['breakage_cart'][$itemCode]['rate'] = $newPrice;
                $_SESSION['breakage_cart'][$itemCode]['amount'] = $_SESSION['breakage_cart'][$itemCode]['quantity'] * $newPrice;
            }
        }
    }
    
    // Remove item from breakage
    if (isset($_POST['remove_item'])) {
        $itemCode = $_POST['remove_item_code'];
        if (isset($_SESSION['breakage_cart'][$itemCode])) {
            unset($_SESSION['breakage_cart'][$itemCode]);
        }
    }
    
    // Finalize breakage record
    if (isset($_POST['finalize_breakage'])) {
        if (!isset($_SESSION['breakage_cart']) || empty($_SESSION['breakage_cart'])) {
            $_SESSION['error'] = "No items in cart to record breakage";
        } else {
            // Generate breakage number
            $breakageNoQuery = "SELECT MAX(BRK_No) as max_breakage FROM tblBreakages WHERE CompID = ?";
            $breakageStmt = $conn->prepare($breakageNoQuery);
            $breakageStmt->bind_param("i", $_SESSION['CompID']);
            $breakageStmt->execute();
            $breakageResult = $breakageStmt->get_result();
            
            $breakageNo = 1;
            if ($breakageResult->num_rows > 0) {
                $breakageData = $breakageResult->fetch_assoc();
                $breakageNo = (int)$breakageData['max_breakage'] + 1;
            }
            
            // Get the user ID from session
            $userId = $_SESSION['user_id'];
            $compId = $_SESSION['CompID'];
            
            // Insert breakage records
            $insertQuery = "INSERT INTO tblBreakages (BRK_No, BRK_Date, Code, Item_Desc, Rate, BRK_Qty, Amount, CompID, UserID) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insertStmt = $conn->prepare($insertQuery);
            
            $currentDate = date('Y-m-d H:i:s');
            $success = true;
            
            foreach ($_SESSION['breakage_cart'] as $item) {
                // Verify item exists in master table before inserting
                $verifyQuery = "SELECT COUNT(*) as count FROM tblitemmaster WHERE CODE = ?";
                $verifyStmt = $conn->prepare($verifyQuery);
                $verifyStmt->bind_param("s", $item['code']);
                $verifyStmt->execute();
                $verifyResult = $verifyStmt->get_result();
                $itemExists = $verifyResult->fetch_assoc()['count'] > 0;
                
                if (!$itemExists) {
                    $_SESSION['error'] = "Item " . $item['code'] . " does not exist in item master. Breakage record cancelled.";
                    $success = false;
                    break;
                }
                
                // Convert values to appropriate types
                $breakageNoInt = (int)$breakageNo;
                $rateFloat = (float)$item['rate'];
                $quantityInt = (int)$item['quantity'];
                $amountFloat = (float)$item['amount'];
                $compIdInt = (int)$compId;
                $userIdInt = (int)$userId;
                
                $itemDesc = $item['name'] . ' (' . $item['size'] . ')';
                
                $insertStmt->bind_param(
                    "isssddiii", 
                    $breakageNoInt, 
                    $currentDate, 
                    $item['code'], 
                    $itemDesc, 
                    $rateFloat, 
                    $quantityInt, 
                    $amountFloat,
                    $compIdInt,
                    $userIdInt
                );
                
                if (!$insertStmt->execute()) {
                    $_SESSION['error'] = "Error saving breakage: " . $conn->error;
                    $success = false;
                    break;
                }
            }
            
            if ($success) {
                // Store breakage data in session for the view page
                $_SESSION['last_breakage_data'] = [
                    'breakage_no' => $breakageNo,
                    'breakage_date' => $currentDate,
                    'items' => $_SESSION['breakage_cart'],
                    'total_amount' => $totalAmount
                ];
                
                // Clear cart
                unset($_SESSION['breakage_cart']);
                
                // Redirect to success page
                header("Location: breakages.php?success=1&breakage_no=" . $breakageNo);
                exit;
            }
        }
    }
    
    // Clear cart
    if (isset($_POST['clear_cart'])) {
        unset($_SESSION['breakage_cart']);
    }
}

// Get breakage items from session if available
if (isset($_SESSION['breakage_cart'])) {
    $breakageItems = $_SESSION['breakage_cart'];
    foreach ($breakageItems as $item) {
        $totalAmount += $item['amount'];
    }
}

// Fetch items for dropdown
$itemsQuery = "SELECT CODE, DETAILS, DETAILS2, PPRICE FROM tblitemmaster ORDER BY DETAILS";
$itemsResult = $conn->query($itemsQuery);
$itemOptions = [];
if ($itemsResult) {
    while ($row = $itemsResult->fetch_assoc()) {
        $itemOptions[$row['CODE']] = [
            'name' => $row['DETAILS'] . ' (' . $row['DETAILS2'] . ')',
            'price' => $row['PPRICE']
        ];
    }
} else {
    echo "Error fetching items: " . $conn->error;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Breakage Management - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <!-- Select2 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">    
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Breakage Management</h4>
      </div>

      <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> Breakage recorded successfully! Breakage No: <?= htmlspecialchars($_GET['breakage_no']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-exclamation me-2"></i> <?= $_SESSION['error'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <form method="POST" class="mb-4">
        <div class="card mb-4">
          <div class="card-header breakage-header fw-semibold"><i class="fa-solid fa-wine-bottle me-2"></i>Record Breakage</div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-6">
                <div class="form-group">
                  <label for="breakage_date" class="form-label">Breakage Date</label>
                  <input type="datetime-local" class="form-control" id="breakage_date" name="breakage_date" 
                         value="<?= date('Y-m-d\TH:i') ?>" required>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header breakage-header fw-semibold"><i class="fa-solid fa-cube me-2"></i>Add Breakage Items</div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-5">
                <div class="form-group">
                  <label for="item_search" class="form-label">Search and Select Item</label>
                  <select class="form-select" id="item_search" name="item_code" required>
                    <option value="">-- Type to search items --</option>
                    <?php foreach ($itemOptions as $code => $itemData): ?>
                      <option value="<?= $code ?>" data-price="<?= $itemData['price'] ?>">
                        <?= htmlspecialchars($itemData['name']) ?> 
                        <span class="item-price">(₹<?= number_format($itemData['price'], 2) ?>)</span>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group">
                  <label for="quantity" class="form-label">Breakage Quantity</label>
                  <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <button type="submit" name="add_item" class="btn btn-danger w-100">
                  <i class="fas fa-plus"></i> Add Item
                </button>
              </div>
            </div>
          </div>
        </div>
      </form>

      <!-- Breakage Items Table -->
      <?php if (!empty($breakageItems)): ?>
        <div class="card mb-4">
          <div class="card-header breakage-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-list me-2"></i>Breakage Items</span>
            <form method="POST" class="d-inline">
              <button type="submit" name="clear_cart" class="btn btn-sm btn-outline-danger">
                <i class="fas fa-trash"></i> Clear All
              </button>
            </form>
          </div>
          <div class="card-body p-0">
            <div class="table-container">
              <table class="styled-table">
                <thead>
                  <tr>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>Size</th>
                    <th>Rate</th>
                    <th>Breakage Qty</th>
                    <th>Amount</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($breakageItems as $item): ?>
                    <tr>
                      <td><?= htmlspecialchars($item['code']) ?></td>
                      <td><?= htmlspecialchars($item['name']) ?></td>
                      <td><?= htmlspecialchars($item['size']) ?></td>
                      <td>
                        <form method="POST" class="d-inline-flex">
                          <input type="hidden" name="item_code" value="<?= $item['code'] ?>">
                          <input type="number" step="0.001" class="form-control form-control-sm price-input" 
                                 name="new_price" value="<?= number_format($item['rate'], 3) ?>" required>
                          <button type="submit" name="update_price" class="btn btn-sm btn-outline-primary ms-1">
                            <i class="fas fa-sync"></i>
                          </button>
                        </form>
                      </td>
                      <td><?= $item['quantity'] ?></td>
                      <td>₹<?= number_format($item['amount'], 2) ?></td>
                      <td>
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="remove_item_code" value="<?= $item['code'] ?>">
                          <button type="submit" name="remove_item" class="btn btn-sm btn-danger">
                            <i class="fas fa-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="table-danger">
                    <td colspan="5" class="text-end fw-bold">Total Breakage Amount:</td>
                    <td class="fw-bold">₹<?= number_format($totalAmount, 2) ?></td>
                    <td></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <a href="dashboard.php" class="btn btn-secondary">
            <i class="fas fa-times"></i> Cancel
          </a>
          <form method="POST">
            <button type="submit" name="finalize_breakage" class="btn btn-danger">
              <i class="fas fa-check"></i> Record Breakage
            </button>
          </form>
        </div>
      <?php else: ?>
        <div class="text-center py-4">
          <i class="fa-solid fa-wine-bottle fa-3x text-muted mb-3"></i>
          <h5 class="text-muted">No breakage items added</h5>
          <p class="text-muted">Add items to record breakage</p>
        </div>
      <?php endif; ?>
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
  // Initialize Select2 for item search
  $('#item_search').select2({
    theme: 'bootstrap-5',
    placeholder: "Type to search items...",
    allowClear: true,
    templateResult: formatItem,
    templateSelection: formatItemSelection
  });
  
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
  
  // Update price display when item is selected
  $('#item_search').on('select2:select', function(e) {
    var data = e.params.data;
    var price = $(data.element).data('price');
    if (price) {
      // You can display the price somewhere if needed
      console.log('Selected item price: ' + price);
    }
  });
});
</script>
</body>
</html>