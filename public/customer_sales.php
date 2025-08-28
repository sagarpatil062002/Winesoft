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
$customers = [];
$items = [];
$selectedCustomer = null;
$saleItems = [];
$totalAmount = 0;

// Fetch customers from tbllheads - CORRECTED QUERY
$customerQuery = "SELECT LCODE, LHEAD FROM tbllheads WHERE GCODE=32 ORDER BY LHEAD";
$customerResult = $conn->query($customerQuery);
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $customers[$row['LCODE']] = $row['LHEAD'];
    }
} else {
    echo "Error fetching customers: " . $conn->error;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store customer ID in session to preserve selection
    if (isset($_POST['customer_id'])) {
        $_SESSION['selected_customer'] = $_POST['customer_id'];
    }
    
    // Add item to sale
    if (isset($_POST['add_item'])) {
        $itemCode = trim($_POST['item_code']);
        $quantity = (int)$_POST['quantity'];
        
        // Validate quantity
        if ($quantity <= 0) {
            $_SESSION['error'] = "Quantity must be greater than zero";
        } else {
            // Get item details
            $itemQuery = "SELECT CODE, DETAILS, DETAILS2, BPRICE FROM tblitemmaster WHERE CODE = ?";
            $stmt = $conn->prepare($itemQuery);
            $stmt->bind_param("s", $itemCode);
            $stmt->execute();
            $itemResult = $stmt->get_result();
            
            if ($itemResult->num_rows > 0) {
                $item = $itemResult->fetch_assoc();
                
                // Get customer-specific price - MODIFIED: Show WPRICE for any customer
                $price = $item['BPRICE']; // Default to base price
                
                // Check if there's any price record for this item (regardless of customer)
                $priceQuery = "SELECT WPrice FROM tblcustomerprices WHERE Code = ? LIMIT 1";
                $priceStmt = $conn->prepare($priceQuery);
                $priceStmt->bind_param("s", $itemCode);
                $priceStmt->execute();
                $priceResult = $priceStmt->get_result();
                
                if ($priceResult->num_rows > 0) {
                    $priceData = $priceResult->fetch_assoc();
                    $price = $priceData['WPrice'];
                }
                
                // Add to session cart
                if (!isset($_SESSION['sale_cart'])) {
                    $_SESSION['sale_cart'] = [];
                }
                
                $itemKey = $itemCode;
                if (isset($_SESSION['sale_cart'][$itemKey])) {
                    $_SESSION['sale_cart'][$itemKey]['quantity'] += $quantity;
                    $_SESSION['sale_cart'][$itemKey]['amount'] = $_SESSION['sale_cart'][$itemKey]['quantity'] * $price;
                } else {
                    $_SESSION['sale_cart'][$itemKey] = [
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
            // Check if price record exists for any customer
            $checkQuery = "SELECT COUNT(*) as count FROM tblcustomerprices WHERE Code = ? LIMIT 1";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("s", $itemCode);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $count = $checkResult->fetch_assoc()['count'];
            
            if ($count > 0) {
                // Update existing price for all customers (or first found)
                $updateQuery = "UPDATE tblcustomerprices SET WPrice = ? WHERE Code = ? LIMIT 1";
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bind_param("ds", $newPrice, $itemCode);
                $updateStmt->execute();
            } else {
                // Insert new price with a default customer ID (0 or first customer)
                $firstCustomerQuery = "SELECT LCODE FROM tbllheads WHERE GCODE=32 ORDER BY LCODE LIMIT 1";
                $firstCustomerResult = $conn->query($firstCustomerQuery);
                $firstCustomer = $firstCustomerResult->fetch_assoc();
                $defaultCustomerId = $firstCustomer['LCODE'] ?? 0;
                
                $insertQuery = "INSERT INTO tblcustomerprices (LCode, Code, WPrice) VALUES (?, ?, ?)";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("isd", $defaultCustomerId, $itemCode, $newPrice);
                $insertStmt->execute();
            }
            
            // Update the price in the cart if the item exists
            if (isset($_SESSION['sale_cart'][$itemCode])) {
                $_SESSION['sale_cart'][$itemCode]['rate'] = $newPrice;
                $_SESSION['sale_cart'][$itemCode]['amount'] = $_SESSION['sale_cart'][$itemCode]['quantity'] * $newPrice;
            }
        }
    }
    
    // Remove item from sale
    if (isset($_POST['remove_item'])) {
        $itemCode = $_POST['remove_item_code'];
        if (isset($_SESSION['sale_cart'][$itemCode])) {
            unset($_SESSION['sale_cart'][$itemCode]);
        }
    }
    
    // Finalize sale
    if (isset($_POST['finalize_sale'])) {
        // Validate customer selection
        $customerId = (int)($_POST['customer_id'] ?? 0);
        if ($customerId <= 0 || !array_key_exists($customerId, $customers)) {
            $_SESSION['error'] = "Please select a valid customer";
        } elseif (!isset($_SESSION['sale_cart']) || empty($_SESSION['sale_cart'])) {
            $_SESSION['error'] = "No items in cart to finalize sale";
        } else {
            // Generate bill number
            $billNoQuery = "SELECT MAX(BillNo) as max_bill FROM tblcustomersales WHERE CompID = ?";
            $billStmt = $conn->prepare($billNoQuery);
            $billStmt->bind_param("i", $_SESSION['CompID']);
            $billStmt->execute();
            $billResult = $billStmt->get_result();
            
            $billNo = 1;
            if ($billResult->num_rows > 0) {
                $billData = $billResult->fetch_assoc();
                $billNo = (int)$billData['max_bill'] + 1;
            }
            
            // Get the user ID from session
            $userId = $_SESSION['user_id'];
            
            // Insert sales records - UserID column exists in your table
            $insertQuery = "INSERT INTO tblcustomersales (BillNo, BillDate, LCode, ItemCode, ItemName, ItemSize, Rate, Quantity, Amount, CompID, UserID) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insertStmt = $conn->prepare($insertQuery);
            
            $currentDate = date('Y-m-d');
            $success = true;
            
            foreach ($_SESSION['sale_cart'] as $item) {
                // Verify item exists in master table before inserting
                $verifyQuery = "SELECT COUNT(*) as count FROM tblitemmaster WHERE CODE = ?";
                $verifyStmt = $conn->prepare($verifyQuery);
                $verifyStmt->bind_param("s", $item['code']);
                $verifyStmt->execute();
                $verifyResult = $verifyStmt->get_result();
                $itemExists = $verifyResult->fetch_assoc()['count'] > 0;
                
                if (!$itemExists) {
                    $_SESSION['error'] = "Item " . $item['code'] . " does not exist in item master. Sale cancelled.";
                    $success = false;
                    break;
                }
                
                // Convert values to appropriate types
                $billNoInt = (int)$billNo;
                $customerIdInt = (int)$customerId;
                $rateFloat = (float)$item['rate'];
                $quantityInt = (int)$item['quantity'];
                $amountFloat = (float)$item['amount'];
                $compIdInt = (int)$_SESSION['CompID'];
                $userIdInt = (int)$userId;
                
                $insertStmt->bind_param(
                    "isssssdiiii", 
                    $billNoInt, 
                    $currentDate, 
                    $customerIdInt, 
                    $item['code'], 
                    $item['name'], 
                    $item['size'], 
                    $rateFloat, 
                    $quantityInt, 
                    $amountFloat,
                    $compIdInt,
                    $userIdInt
                );
                
                if (!$insertStmt->execute()) {
                    $_SESSION['error'] = "Error saving sale: " . $conn->error;
                    $success = false;
                    break;
                }
            }
            
            if ($success) {
                // Calculate totals
                $totalAmount = 0;
                foreach ($_SESSION['sale_cart'] as $item) {
                    $totalAmount += $item['amount'];
                }
                
                $taxRate = 0.08; // 8% tax - you can fetch this from your database
                $taxAmount = $totalAmount * $taxRate;
                $finalAmount = $totalAmount + $taxAmount;
                
                // Store bill data in session for the view page
                $_SESSION['last_bill_data'] = [
                    'bill_no' => $billNo,
                    'customer_id' => $customerId,
                    'customer_name' => $customers[$customerId],
                    'bill_date' => $currentDate,
                    'items' => $_SESSION['sale_cart'],
                    'total_amount' => $totalAmount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'final_amount' => $finalAmount
                ];
                
                // Clear cart and selected customer
                unset($_SESSION['sale_cart']);
                unset($_SESSION['selected_customer']);
                
                // Show bill preview
                echo '<!DOCTYPE html>
                <html lang="en">
                <head>
                  <meta charset="UTF-8">
                  <meta name="viewport" content="width=device-width, initial-scale=1.0">
                  <title>Bill Preview - WineSoft</title>
                  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
                  <style>
                    @media print {
                      body * {
                        visibility: hidden;
                      }
                      .bill-preview, .bill-preview * {
                        visibility: visible;
                      }
                      .bill-preview {
                        position: absolute;
                        left: 0;
                        top: 0;
                        width: 100%;
                      }
                      .no-print {
                        display: none !important;
                      }
                    }
                    .bill-preview {
                      width: 80mm;
                      margin: 0 auto;
                      padding: 5px;
                      font-family: monospace;
                      font-size: 12px;
                    }
                    .text-center {
                      text-align: center;
                    }
                    .text-right {
                      text-align: right;
                    }
                    .bill-header {
                      border-bottom: 1px dashed #000;
                      padding-bottom: 5px;
                      margin-bottom: 5px;
                    }
                    .bill-footer {
                      border-top: 1px dashed #000;
                      padding-top: 5px;
                      margin-top: 5px;
                    }
                    .bill-table {
                      width: 100%;
                      border-collapse: collapse;
                    }
                    .bill-table th, .bill-table td {
                      padding: 2px 0;
                    }
                    .bill-table .text-right {
                      text-align: right;
                    }
                  </style>
                </head>
                <body>
                  <div class="bill-preview">
                    <div class="bill-header text-center">
                <h1><?= htmlspecialchars($companyName) ?></h1>

                    </div>
                    
                    <div style="margin: 5px 0;">
                      <p style="margin: 2px 0;"><strong>Bill No:</strong> ' . $billNo . '</p>
                      <p style="margin: 2px 0;"><strong>Date:</strong> ' . date('d/m/Y', strtotime($currentDate)) . '</p>
                      <p style="margin: 2px 0;"><strong>Customer:</strong> ' . $customers[$customerId] . ' (' . $customerId . ')</p>
                    </div>
                    
                    <table class="bill-table">
                      <thead>
                        <tr>
                          <th>Item</th>
                          <th class="text-right">Qty</th>
                          <th class="text-right">Rate</th>
                          <th class="text-right">Amount</th>
                        </tr>
                      </thead>
                      <tbody>';
                
                foreach ($_SESSION['last_bill_data']['items'] as $item) {
                    echo '<tr>
                            <td>' . substr($item['name'], 0, 15) . '</td>
                            <td class="text-right">' . $item['quantity'] . '</td>
                            <td class="text-right">' . number_format($item['rate'], 2) . '</td>
                            <td class="text-right">' . number_format($item['amount'], 2) . '</td>
                          </tr>';
                }
                
                echo '</tbody>
                    </table>
                    
                    <div class="bill-footer">
                      <table class="bill-table">
                        <tr>
                          <td>Sub Total:</td>
                          <td class="text-right">₹' . number_format($totalAmount, 2) . '</td>
                        </tr>
                        <tr>
                          <td>Tax (' . ($taxRate * 100) . '%):</td>
                          <td class="text-right">₹' . number_format($taxAmount, 2) . '</td>
                        </tr>
                        <tr>
                          <td><strong>Total Due:</strong></td>
                          <td class="text-right"><strong>₹' . number_format($finalAmount, 2) . '</strong></td>
                        </tr>
                      </table>
                      
                      <p style="margin: 5px 0; text-align: center;">Thank you for your business!</p>
                      <p style="margin: 2px 0; text-align: center; font-size: 10px;">GST #: 103340329010001</p>
                    </div>
                    
                    <div class="no-print text-center" style="margin-top: 15px;">
                      <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                      <a href="customer_sales_view.php?bill_no=' . $billNo . '" class="btn btn-success btn-sm"><i class="fas fa-eye"></i> View Details</a>
                      <a href="customer_sales.php" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> New Sale</a>
                    </div>
                  </div>
                </body>
                </html>';
                exit;
            }
        }
    }
    
    // Clear cart
    if (isset($_POST['clear_cart'])) {
        unset($_SESSION['sale_cart']);
    }
}

// Get sale items from session if available
if (isset($_SESSION['sale_cart'])) {
    $saleItems = $_SESSION['sale_cart'];
    foreach ($saleItems as $item) {
        $totalAmount += $item['amount'];
    }
}

// Fetch items for dropdown with their WPRICE (from any customer)
$itemsQuery = "SELECT i.CODE, i.DETAILS, i.DETAILS2, COALESCE(cp.WPrice, i.BPRICE) as Price 
               FROM tblitemmaster i 
               LEFT JOIN tblcustomerprices cp ON i.CODE = cp.CODE 
               GROUP BY i.CODE 
               ORDER BY i.DETAILS";
$itemsResult = $conn->query($itemsQuery);
$itemOptions = [];
if ($itemsResult) {
    while ($row = $itemsResult->fetch_assoc()) {
        $itemOptions[$row['CODE']] = [
            'name' => $row['DETAILS'] . ' (' . $row['DETAILS2'] . ')',
            'price' => $row['Price']
        ];
    }
} else {
    echo "Error fetching items: " . $conn->error;
}

// Get selected customer from session if available
$selectedCustomer = isset($_SESSION['selected_customer']) ? $_SESSION['selected_customer'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Sales - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <!-- Select2 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
  <style>
    .table-container{overflow-x:auto;max-height:520px}
    table.styled-table{width:100%;border-collapse:collapse}
    .styled-table th,.styled-table td{border:1px solid #e5e7eb;padding:8px 10px}
    .styled-table thead th{position:sticky;top:0;background:#f8fafc;z-index:1}
    .action-buttons{display:flex;gap:5px}
    .status-badge{padding:4px 8px;border-radius:4px;font-size:0.8rem}
    .status-completed{background:#d1fae5;color:#065f46}
    .status-pending{background:#fef3c7;color:#92400e}
    .price-input {width: 80px; text-align: right;}
    .item-price {font-size: 0.9rem; color: #6c757d;}
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
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Customer Sales</h4>
      </div>

      <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> Sale completed successfully! Bill No: <?= htmlspecialchars($_GET['bill_no']) ?>
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
          <div class="card-header fw-semibold"><i class="fa-solid fa-user me-2"></i>Customer Information</div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-6">
                <div class="form-group">
                  <label for="customer_id" class="form-label">Select Customer</label>
                  <select class="form-select" id="customer_id" name="customer_id" required>
                    <option value="">-- Select Customer --</option>
                    <?php foreach ($customers as $code => $name): ?>
                      <option value="<?= $code ?>" <?= ($selectedCustomer == $code) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($name) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-group">
                  <label for="bill_date" class="form-label">Bill Date</label>
                  <input type="date" class="form-control" id="bill_date" name="bill_date" 
                         value="<?= date('Y-m-d') ?>" required readonly>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-cube me-2"></i>Add Items</div>
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
                  <label for="quantity" class="form-label">Quantity</label>
                  <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <button type="submit" name="add_item" class="btn btn-primary w-100">
                  <i class="fas fa-plus"></i> Add Item
                </button>
              </div>
            </div>
          </div>
        </div>
      </form>

      <!-- Sale Items Table -->
      <?php if (!empty($saleItems)): ?>
        <div class="card mb-4">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-list me-2"></i>Sale Items</span>
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
                    <th>Qty</th>
                    <th>Amount</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($saleItems as $item): ?>
                    <tr>
                      <td><?= htmlspecialchars($item['code']) ?></td>
                      <td><?= htmlspecialchars($item['name']) ?></td>
                      <td><?= htmlspecialchars($item['size']) ?></td>
                      <td>
                        <form method="POST" class="d-inline-flex">
                          <input type="hidden" name="item_code" value="<?= $item['code'] ?>">
                          <input type="hidden" name="customer_id" value="<?= $selectedCustomer ?>">
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
                          <input type="hidden" name="customer_id" value="<?= $selectedCustomer ?>">
                          <button type="submit" name="remove_item" class="btn btn-sm btn-danger">
                            <i class="fas fa-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="table-primary">
                    <td colspan="5" class="text-end fw-bold">Total Amount:</td>
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
            <input type="hidden" name="customer_id" value="<?= $selectedCustomer ?>">
            <button type="submit" name="finalize_sale" class="btn btn-success">
              <i class="fas fa-check"></i> Finalize Sale
            </button>
          </form>
        </div>
      <?php else: ?>
        <div class="text-center py-4">
          <i class="fa-solid fa-cart-shopping fa-3x text-muted mb-3"></i>
          <h5 class="text-muted">No items in cart</h5>
          <p class="text-muted">Select a customer and add items to create a sale</p>
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
  
  // Auto-focus on item selection after customer is selected
  $('#customer_id').change(function() {
    if ($(this).val()) {
      $('#item_search').select2('open');
    }
  });
  
  // Update price display when item is selected
  $('#item_search').on('select2:select', function(e) {
    var data = e.params.data;
    var price = $(data.element).data('price');
    if (price) {
      // You can display the price somewhere if needed
      console.log('Selected item price: ' + price);
    }
  });
  
  // Prevent form submission if customer is not selected when adding items
  $('form').submit(function(e) {
    if ($(this).find('button[name="add_item"]').length > 0) {
      if (!$('#customer_id').val()) {
        e.preventDefault();
        alert('Please select a customer first');
        $('#customer_id').focus();
      }
    }
  });
});
</script>
</body>
</html>