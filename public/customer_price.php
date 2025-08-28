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

$companyId = $_SESSION['CompID'];

include_once "../config/db.php"; // MySQLi connection in $conn

// Fetch customers from tbllheads for this company only
$customers = [];
$customerQuery = "SELECT LCODE, LHEAD FROM tbllheads WHERE REF_CODE = 'CUST' AND CompID = ? ORDER BY LHEAD";
$customerStmt = $conn->prepare($customerQuery);
$customerStmt->bind_param("i", $companyId);
$customerStmt->execute();
$customerResult = $customerStmt->get_result();
while ($row = $customerResult->fetch_assoc()) {
    $customers[$row['LCODE']] = $row['LHEAD'];
}
$customerStmt->close();

// Get selected customer
$selectedCustomer = isset($_GET['customer']) ? intval($_GET['customer']) : 0;
$customerName = isset($customers[$selectedCustomer]) ? $customers[$selectedCustomer] : '';

// Handle new customer creation
$newCustomerName = isset($_GET['new_customer']) ? trim($_GET['new_customer']) : '';
if ($newCustomerName !== '' && $selectedCustomer === 0) {
    // Check if customer already exists for this company
    $checkQuery = "SELECT LCODE FROM tbllheads WHERE LHEAD = ? AND CompID = ? AND REF_CODE = 'CUST'";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("si", $newCustomerName, $companyId);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        $errorMessage = "Customer already exists!";
    } else {
        // Find a valid GCODE from tblgheads - using GCODE 32 (Sundry Debtors) which exists
        $validGcode = 32; // Using Sundry Debtors as this exists in tblgheads
        
        // Create new customer
        $maxCodeQuery = "SELECT MAX(LCODE) as max_code FROM tbllheads";
        $maxResult = $conn->query($maxCodeQuery);
        $maxRow = $maxResult->fetch_assoc();
        $newCode = $maxRow['max_code'] + 1;
        
        // Default values for new customer
        $gcode = $validGcode;
        $op_bal = 0;
        $drcr = 'D';
        $ref_code = 'CUST';
        $serial_no = 0;
        
        $insertQuery = "INSERT INTO tbllheads (LCODE, LHEAD, GCODE, OP_BAL, DRCR, REF_CODE, SERIAL_NO, CompID) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $conn->prepare($insertQuery);
        $insertStmt->bind_param("isidssii", $newCode, $newCustomerName, $gcode, $op_bal, $drcr, $ref_code, $serial_no, $companyId);
        
        if ($insertStmt->execute()) {
            $selectedCustomer = $newCode;
            $customerName = $newCustomerName;
            $customers[$newCode] = $newCustomerName;
            
            // Refresh page to show the new customer as selected
            header("Location: customer_price.php?customer=" . $newCode);
            exit;
        } else {
            $errorMessage = "Error creating customer: " . $insertStmt->error;
            // You might want to display this error to the user
        }
        $insertStmt->close();
    }
    $checkStmt->close();
}

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch class descriptions from tblclass
$classDescriptions = [];
$classQuery = "SELECT SGROUP, `DESC` FROM tblclass";
$classResult = $conn->query($classQuery);
while ($row = $classResult->fetch_assoc()) {
    $classDescriptions[$row['SGROUP']] = $row['DESC'];
}

// Fetch subclass descriptions from tblsubclass
$subclassDescriptions = [];
$subclassQuery = "SELECT ITEM_GROUP, `DESC`, LIQ_FLAG FROM tblsubclass";
$subclassResult = $conn->query($subclassQuery);
while ($row = $subclassResult->fetch_assoc()) {
    $subclassDescriptions[$row['ITEM_GROUP']][$row['LIQ_FLAG']] = $row['DESC'];
}

// Fetch items from tblitemmaster
$query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE
          FROM tblitemmaster
          WHERE LIQ_FLAG = ?";
$params = [$mode];
$types = "s";

if ($search !== '') {
    $query .= " AND (DETAILS LIKE ? OR CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " ORDER BY DETAILS ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch existing customer prices
$customerPrices = [];
if ($selectedCustomer > 0) {
    $priceQuery = "SELECT Code, WPrice FROM tblCustomerPrices WHERE LCode = ?";
    $priceStmt = $conn->prepare($priceQuery);
    $priceStmt->bind_param("i", $selectedCustomer);
    $priceStmt->execute();
    $priceResult = $priceStmt->get_result();
    while ($row = $priceResult->fetch_assoc()) {
        $customerPrices[$row['Code']] = $row['WPrice'];
    }
    $priceStmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prices']) && $selectedCustomer > 0) {
    $hasChanges = false;
    $duplicateEntries = [];
    
    // Process each item price
    foreach ($_POST['prices'] as $code => $price) {
        $price = floatval(str_replace(',', '', $price));

        // Skip if price is empty AND not already set in DB
        if ($price <= 0 && !isset($customerPrices[$code])) {
            continue;
        }

        // Skip if price is unchanged (avoid unnecessary update)
        if (isset($customerPrices[$code]) && floatval($customerPrices[$code]) == $price) {
            continue;
        }

        // Check if price exists for this customer and item
        $checkQuery = "SELECT CustPID FROM tblCustomerPrices WHERE LCode = ? AND Code = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("is", $selectedCustomer, $code);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            // Update only if new price is given
            if ($price > 0) {
                $updateQuery = "UPDATE tblCustomerPrices SET WPrice = ? WHERE LCode = ? AND Code = ?";
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bind_param("dis", $price, $selectedCustomer, $code);
                if ($updateStmt->execute()) {
                    $hasChanges = true;
                }
                $updateStmt->close();
            }
        } else {
            // Insert only if price > 0
            if ($price > 0) {
                $insertQuery = "INSERT INTO tblCustomerPrices (LCode, Code, WPrice) VALUES (?, ?, ?)";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("isd", $selectedCustomer, $code, $price);
                if ($insertStmt->execute()) {
                    $hasChanges = true;
                } else {
                    // Check if it's a duplicate entry error
                    if ($insertStmt->errno == 1062) {
                        $duplicateEntries[] = $code;
                    }
                }
                $insertStmt->close();
            }
        }

        $checkStmt->close();
    }

    // Prepare redirect parameters
    $redirectParams = "customer=" . $selectedCustomer . "&mode=" . $mode . "&search=" . urlencode($search);
    
    // Add appropriate message parameter
    if (!empty($duplicateEntries)) {
        $redirectParams .= "&error=duplicate";
    } elseif ($hasChanges) {
        $redirectParams .= "&success=1";
    } else {
        $redirectParams .= "&info=nochanges";
    }
    
    // Redirect to refresh the page and prevent form resubmission
    header("Location: customer_price.php?" . $redirectParams);
    exit;
}

// Function to get class description
function getClassDescription($code, $classDescriptions) {
    return $classDescriptions[$code] ?? $code;
}

// Function to get subclass description
function getSubclassDescription($itemGroup, $liqFlag, $subclassDescriptions) {
    return $subclassDescriptions[$itemGroup][$liqFlag] ?? $itemGroup;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Wise Price Module - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .price-input {
      width: 120px;
      text-align: right;
    }
    .customer-selector {
      max-width: 600px;
    }
    .success-message {
      background-color: #d4edda;
      color: #155724;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
      text-align: center;
      font-weight: bold;
    }
    .info-message {
      background-color: #d1ecf1;
      color: #0c5460;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
      text-align: center;
      font-weight: bold;
    }
    .error-message {
      background-color: #f8d7da;
      color: #721c24;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
      text-align: center;
      font-weight: bold;
    }
    .combined-select {
      display: flex;
      width: 100%;
    }
    .combined-select select {
      flex: 1;
      border-top-right-radius: 0;
      border-bottom-right-radius: 0;
    }
    .combined-select input {
      flex: 2;
      border-radius: 0;
      border-left: none;
      border-right: none;
    }
    .combined-select button {
      border-top-left-radius: 0;
      border-bottom-left-radius: 0;
    }
    .table-disabled {
      opacity: 0.7;
      pointer-events: none;
    }
    .action-btn {
      display: flex;
      gap: 10px;
      margin-top: 20px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Customer Wise Price Module</h3>

      <!-- Success Message -->
      <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="success-message">
          <i class="fas fa-check-circle"></i> Customer price module successfully updated!
          <button type="button" class="btn btn-sm btn-success ms-3" onclick="window.location.href='customer_price.php?customer=<?= $selectedCustomer ?>&mode=<?= $mode ?>'">
            OK
          </button>
        </div>
      <?php endif; ?>

      <!-- Info Message -->
      <?php if (isset($_GET['info']) && $_GET['info'] == 'nochanges'): ?>
        <div class="info-message">
          <i class="fas fa-info-circle"></i> No changes were made to the prices.
          <button type="button" class="btn btn-sm btn-info ms-3" onclick="window.location.href='customer_price.php?customer=<?= $selectedCustomer ?>&mode=<?= $mode ?>'">
            OK
          </button>
        </div>
      <?php endif; ?>

      <!-- Error Message -->
      <?php if (isset($_GET['error']) && $_GET['error'] == 'duplicate'): ?>
        <div class="error-message">
          <i class="fas fa-exclamation-circle"></i> Some prices were not saved because they already exist in the database.
          <button type="button" class="btn btn-sm btn-danger ms-3" onclick="window.location.href='customer_price.php?customer=<?= $selectedCustomer ?>&mode=<?= $mode ?>'">
            OK
          </button>
        </div>
      <?php endif; ?>

      <?php if (isset($errorMessage)): ?>
        <div class="error-message">
          <i class="fas fa-exclamation-circle"></i> <?php echo $errorMessage; ?>
        </div>
      <?php endif; ?>

      <!-- Customer Selection -->
      <div class="card mb-4">
        <div class="card-body">
          <h5 class="card-title">Customer</h5>
          <form method="GET" class="customer-selector">
            <div class="combined-select mb-3">
              <select name="customer" class="form-select" id="customerSelect">
                <option value="0">-- Select Customer --</option>
                <?php foreach ($customers as $code => $name): ?>
                  <option value="<?= $code ?>" <?= $selectedCustomer == $code ? 'selected' : '' ?>>
                    <?= htmlspecialchars($name) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <input type="text" name="new_customer" class="form-control" id="newCustomerInput" 
                     placeholder="Enter new customer name" value="<?= htmlspecialchars($newCustomerName) ?>">
              <button type="submit" class="btn btn-primary">Apply</button>
            </div>
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
            <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>">
          </form>
          
          <?php if ($selectedCustomer > 0): ?>
            <div class="customer-details mt-3">
              <h6 class="text-primary"><?= htmlspecialchars($customerName) ?></h6>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?customer=<?= $selectedCustomer ?>&mode=F&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?customer=<?= $selectedCustomer ?>&mode=C&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
          <a href="?customer=<?= $selectedCustomer ?>&mode=O&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>

      <!-- Search -->
      <form method="GET" class="search-control mb-3">
        <input type="hidden" name="customer" value="<?= $selectedCustomer ?>">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <div class="input-group">
          <input type="text" name="search" class="form-control"
                 placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Find
          </button>
          <?php if ($search !== ''): ?>
            <a href="?customer=<?= $selectedCustomer ?>&mode=<?= $mode ?>" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Action Buttons - Moved below search bar -->
      <div class="action-btn">
        <button type="submit" form="priceForm" name="save_prices" class="btn btn-success" <?= $selectedCustomer == 0 ? 'disabled' : '' ?>>
          <i class="fas fa-save"></i> Save Prices
        </button>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Price Details Form -->
      <form method="POST" id="priceForm">
        <input type="hidden" name="customer" value="<?= $selectedCustomer ?>">
        
        <div class="card <?= $selectedCustomer == 0 ? 'table-disabled' : '' ?>">
          <div class="card-header">
            <h5 class="card-title">Price Details</h5>
            <?php if ($selectedCustomer == 0): ?>
              <small class="text-muted">Select or create a customer to edit prices</small>
            <?php endif; ?>
          </div>
          <div class="card-body p-0">
            <div class="table-container">
              <table class="styled-table table-striped">
                <thead class="table-header">
                  <tr>
                    <th width="5%">#</th>
                    <th width="40%">Details</th>
                    <th width="25%">Category</th>
                    <th width="30%">Price</th>
                  </tr>
                </thead>
                <tbody>
                <?php if (!empty($items)): ?>
                  <?php foreach ($items as $index => $item): 
                    $price = isset($customerPrices[$item['CODE']]) ? number_format($customerPrices[$item['CODE']], 2) : '';
                    $isEditable = $selectedCustomer > 0;
                  ?>
                    <tr>
                      <td><?= $index + 1 ?></td>
                      <td><strong><?= htmlspecialchars($item['DETAILS']) ?></strong></td>
                      <td><?= htmlspecialchars($item['DETAILS2']) ?></td>
                      <td>
                        <input type="text" name="prices[<?= $item['CODE'] ?>]" 
                               value="<?= $price ?>" 
                               class="form-control form-control-sm price-input"
                               placeholder="0.00" 
                               <?= !$isEditable ? 'disabled' : '' ?>>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="4" class="text-center text-muted">No items found.</td>
                  </tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  $(document).ready(function() {
    // Toggle between select and input based on selection
    $('#customerSelect').change(function() {
      if ($(this).val() != '0') {
        $('#newCustomerInput').val('');
      }
    });
    
    $('#newCustomerInput').on('input', function() {
      if ($(this).val() !== '') {
        $('#customerSelect').val('0');
      }
    });

    // Format price inputs on blur
    $('.price-input').on('blur', function() {
      var value = $(this).val().replace(/,/g, '');
      if(value !== '' && !isNaN(parseFloat(value))) {
        $(this).val(parseFloat(value).toLocaleString('en-IN', {
          minimumFractionDigits: 2,
          maximumFractionDigits: 2
        }));
      }
    });
    
    // Handle form submission - format all prices
    $('#priceForm').on('submit', function() {
      $('.price-input').each(function() {
        var value = $(this).val().replace(/,/g, '');
        if(value !== '' && !isNaN(parseFloat(value))) {
          $(this).val(parseFloat(value).toFixed(2));
        }
      });
    });
  });
</script>
</body>
</html>