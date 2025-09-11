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
$saleItems = [];
$totalAmount = 0;

// Fetch items from tblitemmaster for barcode scanning
$query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE 
          FROM tblitemmaster 
          WHERE BARCODE IS NOT NULL AND BARCODE != ''";
$params = [];
$types = "";

// Search functionality
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
    $query .= " AND (DETAILS LIKE ? OR CODE LIKE ? OR BARCODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "sss";
}

$query .= " ORDER BY DETAILS ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
} else {
    $stmt = $conn->prepare($query);
}

$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle adding item to cart
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add item to sale
    if (isset($_POST['add_item'])) {
        $itemCode = trim($_POST['item_code']);
        $quantity = (int)$_POST['quantity'];
        
        // Validate quantity
        if ($quantity <= 0) {
            $_SESSION['error'] = "Quantity must be greater than zero";
        } else {
            // Get item details
            $itemQuery = "SELECT CODE, DETAILS, DETAILS2, RPRICE FROM tblitemmaster WHERE CODE = ?";
            $stmt = $conn->prepare($itemQuery);
            $stmt->bind_param("s", $itemCode);
            $stmt->execute();
            $itemResult = $stmt->get_result();
            
            if ($itemResult->num_rows > 0) {
                $item = $itemResult->fetch_assoc();
                $price = $item['RPRICE'];
                
                // Add to session cart
                if (!isset($_SESSION['pos_cart'])) {
                    $_SESSION['pos_cart'] = [];
                }
                
                $itemKey = $itemCode;
                if (isset($_SESSION['pos_cart'][$itemKey])) {
                    $_SESSION['pos_cart'][$itemKey]['quantity'] += $quantity;
                    $_SESSION['pos_cart'][$itemKey]['amount'] = $_SESSION['pos_cart'][$itemKey]['quantity'] * $price;
                } else {
                    $_SESSION['pos_cart'][$itemKey] = [
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
    
    // Remove item from cart
    if (isset($_POST['remove_item'])) {
        $itemCode = $_POST['remove_item_code'];
        if (isset($_SESSION['pos_cart'][$itemCode])) {
            unset($_SESSION['pos_cart'][$itemCode]);
        }
    }
    
    // Clear cart
    if (isset($_POST['clear_cart'])) {
        unset($_SESSION['pos_cart']);
    }
    
    // Finalize sale
    if (isset($_POST['finalize_sale'])) {
        if (!isset($_SESSION['pos_cart']) || empty($_SESSION['pos_cart'])) {
            $_SESSION['error'] = "No items in cart to finalize sale";
        } else {
            // Start transaction
            $conn->begin_transaction();
            
            try {
                // Get next bill number
                $billNoQuery = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader";
                $billResult = $conn->query($billNoQuery);
                
                $billNo = 1;
                if ($billResult->num_rows > 0) {
                    $billData = $billResult->fetch_assoc();
                    $billNo = (int)$billData['max_bill'] + 1;
                }
                
                $user_id = $_SESSION['user_id'];
                $comp_id = $_SESSION['CompID'];
                $fin_year_id = $_SESSION['FIN_YEAR_ID'];
                
                $sale_date = date('Y-m-d H:i:s');
                $total_amount = 0;
                
                // Calculate total amount
                foreach ($_SESSION['pos_cart'] as $item) {
                    $total_amount += $item['amount'];
                }
                
                // Insert sale header
                $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                 VALUES (?, ?, ?, 0, ?, 'F', ?, ?)";
                $header_stmt = $conn->prepare($header_query);
                $header_stmt->bind_param("ssdssi", $bill_no, $sale_date, $total_amount, $total_amount, $comp_id, $user_id);
                $header_stmt->execute();
                $header_stmt->close();
                
                // Insert sale details and update stock
                foreach ($_SESSION['pos_cart'] as $item) {
                    $amount = $item['price'] * $item['quantity'];
                    
                    // Insert sale details
                    $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                     VALUES (?, ?, ?, ?, ?, 'F', ?)";
                    $detail_stmt = $conn->prepare($detail_query);
                    $detail_stmt->bind_param("ssddssi", $bill_no, $item['code'], $item['quantity'], $item['price'], $amount, $comp_id);
                    $detail_stmt->execute();
                    $detail_stmt->close();
                    
                    // Update stock
                    $stock_query = "UPDATE tblitem_stock SET Current_Stock{$comp_id} = Current_Stock{$comp_id} - ? WHERE ITEM_CODE = ?";
                    $stock_stmt = $conn->prepare($stock_query);
                    $stock_stmt->bind_param("ds", $item['quantity'], $item['code']);
                    $stock_stmt->execute();
                    $stock_stmt->close();
                }
                
                // Commit transaction
                $conn->commit();
                
                // Store bill data for receipt
                $_SESSION['last_bill_data'] = [
                    'bill_no' => $billNo,
                    'bill_date' => $sale_date,
                    'items' => $_SESSION['pos_cart'],
                    'total_amount' => $total_amount
                ];
                
                // Clear cart
                unset($_SESSION['pos_cart']);
                
                // Redirect to success page
                header("Location: pos_success.php?bill_no=" . $billNo);
                exit;
                
            } catch (Exception $e) {
                // Rollback transaction on error
                $conn->rollback();
                $_SESSION['error'] = "Error processing sale: " . $e->getMessage();
            }
        }
    }
}

// Get sale items from session if available
if (isset($_SESSION['pos_cart'])) {
    $saleItems = $_SESSION['pos_cart'];
    foreach ($saleItems as $item) {
        $totalAmount += $item['amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>POS System - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .barcode-scanner {
      background-color: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    .scanner-animation {
      height: 4px;
      background: #007bff;
      border-radius: 4px;
      overflow: hidden;
      margin: 10px 0;
      position: relative;
    }
    .scanner-animation::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 20%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.8), transparent);
      animation: scanner 2s infinite linear;
    }
    @keyframes scanner {
      0% { left: -20%; }
      100% { left: 120%; }
    }
    .cart-item {
      border-bottom: 1px solid #eee;
      padding: 10px 0;
    }
    .cart-item:last-child {
      border-bottom: none;
    }
    .pos-container {
      display: flex;
      gap: 20px;
    }
    .items-section {
      flex: 2;
    }
    .cart-section {
      flex: 1;
      background-color: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
    }
    .item-card {
      cursor: pointer;
      transition: all 0.2s;
      height: 100%;
    }
    .item-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    .status-indicator {
      display: inline-block;
      width: 12px;
      height: 12px;
      border-radius: 50%;
      margin-right: 5px;
    }
    .status-ready { background-color: #28a745; }
    .status-scanning { background-color: #ffc107; }
    .search-control {
      margin-bottom: 20px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">POS System</h3>

      <!-- Success/Error Messages -->
      <?php if (isset($_SESSION['error'])): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $_SESSION['error'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <div class="pos-container">
        <!-- Items Section -->
        <div class="items-section">
          <!-- Barcode Scanner -->
          <div class="barcode-scanner mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <h4><i class="fas fa-barcode me-2"></i>Barcode Scanner</h4>
              <div>
                <span class="status-indicator status-ready" id="statusIndicator"></span>
                <span id="statusText">Ready to scan</span>
              </div>
            </div>
            <div class="scanner-animation"></div>
            <div class="input-group">
              <input type="text" class="form-control form-control-lg" id="barcodeInput" 
                     placeholder="Scan barcode" autofocus>
              <button class="btn btn-primary" type="button" id="scanBtn">
                <i class="fas fa-camera"></i> Scan
              </button>
            </div>
          </div>

          <!-- Search -->
          <form method="GET" class="search-control mb-3">
            <div class="input-group">
              <input type="text" name="search" class="form-control"
                     placeholder="Search by item name, code, or barcode..." value="<?= htmlspecialchars($search); ?>">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Search
              </button>
              <?php if ($search !== ''): ?>
                <a href="pos_system.php" class="btn btn-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>

          <!-- Cart Section -->
          <div class="cart-section mb-4">
            <h4 class="mb-3"><i class="fas fa-shopping-cart me-2"></i>Shopping Cart</h4>
            
            <?php if (!empty($saleItems)): ?>
              <div class="cart-items mb-3">
                <?php foreach ($saleItems as $index => $item): ?>
                  <div class="cart-item">
                    <div class="d-flex justify-content-between align-items-start">
                      <div>
                        <h6 class="mb-1"><?= htmlspecialchars($item['name']) ?></h6>
                        <small class="text-muted"><?= htmlspecialchars($item['size']) ?></small>
                        <br>
                        <small>₹<?= number_format($item['rate'], 2) ?> x <?= $item['quantity'] ?></small>
                      </div>
                      <div class="text-end">
                        <strong>₹<?= number_format($item['amount'], 2) ?></strong>
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="remove_item_code" value="<?= htmlspecialchars($item['code']) ?>">
                          <button type="submit" name="remove_item" class="btn btn-sm btn-link text-danger">
                            <i class="fas fa-times"></i>
                          </button>
                        </form>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              
              <div class="cart-total mb-3">
                <div class="d-flex justify-content-between">
                  <strong>Total Amount:</strong>
                  <strong>₹<?= number_format($totalAmount, 2) ?></strong>
                </div>
              </div>
              
              <div class="cart-actions">
                <form method="POST" class="d-inline">
                  <button type="submit" name="clear_cart" class="btn btn-danger btn-sm">
                    <i class="fas fa-trash me-1"></i> Clear Cart
                  </button>
                </form>
                <form method="POST" class="d-inline float-end">
                  <button type="submit" name="finalize_sale" class="btn btn-success">
                    <i class="fas fa-check me-1"></i> Checkout
                  </button>
                </form>
              </div>
            <?php else: ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-1"></i> Your cart is empty. Scan items or select from the list.
              </div>
            <?php endif; ?>
          </div>

          <!-- Items Grid -->
          <div class="row row-cols-1 row-cols-md-3 g-3">
            <?php if (!empty($items)): ?>
              <?php foreach ($items as $item): ?>
                <div class="col">
                  <div class="card item-card h-100" 
                       data-code="<?= htmlspecialchars($item['CODE']) ?>"
                       data-name="<?= htmlspecialchars($item['DETAILS']) ?>"
                       data-price="<?= floatval($item['RPRICE']) ?>"
                       data-barcode="<?= htmlspecialchars($item['BARCODE']) ?>">
                    <div class="card-body">
                      <h6 class="card-title"><?= htmlspecialchars($item['DETAILS']) ?></h6>
                      <p class="card-text mb-1">
                        <small class="text-muted"><?= htmlspecialchars($item['DETAILS2']) ?></small>
                      </p>
                      <p class="card-text mb-1">
                        <strong>₹<?= number_format($item['RPRICE'], 2) ?></strong>
                      </p>
                      <p class="card-text">
                        <small class="text-muted">Code: <?= htmlspecialchars($item['CODE']) ?></small>
                        <?php if (!empty($item['BARCODE'])): ?>
                          <br>
                          <small class="text-muted">Barcode: <?= htmlspecialchars($item['BARCODE']) ?></small>
                        <?php endif; ?>
                      </p>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="col-12">
                <div class="alert alert-info">No items found. Try a different search term.</div>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Quantity Modal -->
      <div class="modal fade" id="quantityModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">Add Item to Cart</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="addItemForm">
              <input type="hidden" name="item_code" id="modalItemCode">
              <div class="modal-body">
                <p id="modalItemName"></p>
                <div class="mb-3">
                  <label for="quantity" class="form-label">Quantity</label>
                  <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required>
                </div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="add_item" class="btn btn-primary">Add to Cart</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    const barcodeInput = $('#barcodeInput');
    const statusIndicator = $('#statusIndicator');
    const statusText = $('#statusText');
    const quantityModal = new bootstrap.Modal('#quantityModal');
    let scanning = false;

    // Set status indicator
    function setStatus(status, message) {
        statusIndicator.removeClass('status-ready status-scanning');
        statusIndicator.addClass(status);
        statusText.text(message);
    }

    // Handle barcode scanning
    $('#scanBtn').click(function() {
        const barcode = barcodeInput.val().trim();
        if (barcode) {
            processBarcode(barcode);
        }
    });

    // Handle manual barcode input
    barcodeInput.on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const barcode = barcodeInput.val().trim();
            if (barcode) {
                processBarcode(barcode);
            }
        }
    });

    // Process barcode
    function processBarcode(barcode) {
        if (scanning) return;
        
        scanning = true;
        setStatus('status-scanning', 'Scanning...');
        
        // Find item with matching barcode
        let itemFound = null;
        $('.item-card').each(function() {
            const itemBarcode = $(this).data('barcode');
            if (itemBarcode === barcode) {
                itemFound = $(this);
                return false; // Break loop
            }
        });
        
        if (itemFound) {
            // Show quantity modal for the found item
            showQuantityModal(
                itemFound.data('code'),
                itemFound.data('name'),
                itemFound.data('price')
            );
            barcodeInput.val('');
        } else {
            // Try to find by code if barcode not found
            $('.item-card').each(function() {
                const itemCode = $(this).data('code');
                if (itemCode === barcode) {
                    itemFound = $(this);
                    return false; // Break loop
                }
            });
            
            if (itemFound) {
                showQuantityModal(
                    itemFound.data('code'),
                    itemFound.data('name'),
                    itemFound.data('price')
                );
                barcodeInput.val('');
            } else {
                alert('Item with barcode/code "' + barcode + '" not found.');
            }
        }
        
        setStatus('status-ready', 'Ready to scan');
        scanning = false;
    }

    // Show quantity modal
    function showQuantityModal(code, name, price) {
        $('#modalItemCode').val(code);
        $('#modalItemName').html('<strong>' + name + '</strong><br>Price: ₹' + price.toFixed(2));
        $('#quantity').val(1);
        quantityModal.show();
    }

    // Handle item card clicks
    $('.item-card').click(function() {
        showQuantityModal(
            $(this).data('code'),
            $(this).data('name'),
            $(this).data('price')
        );
    });

    // Focus on barcode input when modal is closed
    $('#quantityModal').on('hidden.bs.modal', function() {
        barcodeInput.focus();
    });

    // Initialize
    barcodeInput.focus();
    setStatus('status-ready', 'Ready to scan');
});
</script>
</body>
</html>