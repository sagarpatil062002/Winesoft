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

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Get item code and mode from URL
$item_code = isset($_GET['code']) ? $_GET['code'] : null;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Fetch class descriptions from tblclass - FILTERED BY LICENSE
$classDescriptions = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $classQuery = "SELECT SGROUP, `DESC` FROM tblclass WHERE SGROUP IN ($class_placeholders)";
    
    $classStmt = $conn->prepare($classQuery);
    $types = str_repeat('s', count($allowed_classes));
    $classStmt->bind_param($types, ...$allowed_classes);
    $classStmt->execute();
    $classResult = $classStmt->get_result();
    while ($row = $classResult->fetch_assoc()) {
        $classDescriptions[$row['SGROUP']] = $row['DESC'];
    }
    $classStmt->close();
}

// Fetch all subclass descriptions from tblsubclass for the current mode
$allSubclassDescriptions = [];
$subclassQuery = "SELECT ITEM_GROUP, `DESC`, LIQ_FLAG FROM tblsubclass WHERE LIQ_FLAG = ? ORDER BY ITEM_GROUP";
$subclassStmt = $conn->prepare($subclassQuery);
$subclassStmt->bind_param("s", $mode);
$subclassStmt->execute();
$subclassResult = $subclassStmt->get_result();
while ($row = $subclassResult->fetch_assoc()) {
    $allSubclassDescriptions[$row['ITEM_GROUP']] = $row['DESC'];
}
$subclassStmt->close();

// Fetch item details
$item = null;
$opening_balance = 0;
if ($item_code) {
    $stmt = $conn->prepare("SELECT * FROM tblitemmaster WHERE CODE = ?");
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    
    // Get opening balance from tblitem_stock
    if ($item) {
        $stock_query = "SELECT OPENING_STOCK{$company_id} as opening 
                       FROM tblitem_stock 
                       WHERE ITEM_CODE = ?";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("s", $item_code);
        $stock_stmt->execute();
        $stock_result = $stock_stmt->get_result();
        
        if ($stock_result->num_rows > 0) {
            $stock_row = $stock_result->fetch_assoc();
            $opening_balance = $stock_row['opening'];
        }
        $stock_stmt->close();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $Print_Name = $_POST['Print_Name'];
    $details = $_POST['details'];
    $class = $_POST['class'];
    $sub_class = $_POST['sub_class'];
    $item_group = $_POST['item_group'];
    $pprice = $_POST['pprice'];
    $bprice = $_POST['bprice'];
    $mprice = $_POST['mprice'];
    $barcode = $_POST['barcode'];
    $rprice = $_POST['rprice'] ?? 0;
    $opening_balance = intval($_POST['opening_balance'] ?? 0);

    // Validate class against license restrictions
    if (!in_array($class, $allowed_classes)) {
        $_SESSION['error_message'] = "Selected class is not allowed for your license type.";
        header("Location: edit_item.php?code=" . urlencode($item_code) . "&mode=" . $mode);
        exit;
    }

    // Check if subclass was changed - if not, keep the original value
    $original_sub_class = $item['SUB_CLASS'];
    $final_sub_class = ($sub_class !== $original_sub_class) ? $sub_class : $original_sub_class;

    $stmt = $conn->prepare("UPDATE tblitemmaster SET 
                          Print_Name = ?, 
                          DETAILS = ?, 
                          CLASS = ?, 
                          SUB_CLASS = ?, 
                          ITEM_GROUP = ?,
                          PPRICE = ?, 
                          BPRICE = ?,
                          MPRICE = ?,
                          RPRICE = ?,
                          BARCODE = ?
                          WHERE CODE = ?");
    $stmt->bind_param("sssssddddss", $Print_Name, $details, $class, $final_sub_class, $item_group, $pprice, $bprice, $mprice, $rprice, $barcode, $item_code);
    
    if ($stmt->execute()) {
        // Update stock information using the same function as in item_master.php
        updateItemStock($conn, $company_id, $item_code, $mode, $opening_balance);
        
        $_SESSION['success_message'] = "Item updated successfully!";
        header("Location: item_master.php?mode=" . $mode);
        exit;
    } else {
        $_SESSION['error_message'] = "Error updating item: " . $conn->error;
    }
    $stmt->close();
}

// Function to update item stock information (same as in item_master.php)
function updateItemStock($conn, $comp_id, $item_code, $liqFlag, $opening_balance) {
    // Update tblitem_stock
    $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_stock_query);
    $check_stmt->bind_param("s", $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $stock_exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    $opening_col = "OPENING_STOCK$comp_id";
    $current_col = "CURRENT_STOCK$comp_id";
    
    if ($stock_exists) {
        // Update existing stock record
        $update_query = "UPDATE tblitem_stock SET $opening_col = ?, $current_col = ? WHERE ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iis", $opening_balance, $opening_balance, $item_code);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new stock record
        $insert_query = "INSERT INTO tblitem_stock (ITEM_CODE, $opening_col, $current_col) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("sii", $item_code, $opening_balance, $opening_balance);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    // Update daily stock for today
    $today = date('d');
    $today_padded = str_pad($today, 2, '0', STR_PAD_LEFT);
    $current_month = date('Y-m');
    
    // Check if daily stock record exists
    $check_daily_query = "SELECT COUNT(*) as count FROM tbldailystock_$comp_id 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
    $check_daily_stmt = $conn->prepare($check_daily_query);
    $check_daily_stmt->bind_param("sss", $current_month, $item_code, $liqFlag);
    $check_daily_stmt->execute();
    $daily_result = $check_daily_stmt->get_result();
    $daily_exists = $daily_result->fetch_assoc()['count'] > 0;
    $check_daily_stmt->close();
    
    if ($daily_exists) {
        // Update existing daily record
        $update_daily_query = "UPDATE tbldailystock_$comp_id 
                              SET DAY_{$today_padded}_OPEN = ?, 
                                  DAY_{$today_padded}_CLOSING = ?,
                                  LAST_UPDATED = CURRENT_TIMESTAMP 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
        $update_daily_stmt = $conn->prepare($update_daily_query);
        $update_daily_stmt->bind_param("iisss", $opening_balance, $opening_balance, $current_month, $item_code, $liqFlag);
        $update_daily_stmt->execute();
        $update_daily_stmt->close();
    } else {
        // Insert new daily record
        $insert_daily_query = "INSERT INTO tbldailystock_$comp_id 
                              (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_{$today_padded}_OPEN, DAY_{$today_padded}_CLOSING) 
                              VALUES (?, ?, ?, ?, ?)";
        $insert_daily_stmt = $conn->prepare($insert_daily_query);
        $insert_daily_stmt->bind_param("sssii", $current_month, $item_code, $liqFlag, $opening_balance, $opening_balance);
        $insert_daily_stmt->execute();
        $insert_daily_stmt->close();
    }
}

// Function to detect class from item name (same as in item_master.php)
function detectClassFromItemName($itemName) {
    $itemName = strtoupper($itemName);
    
    // WHISKY Detection
    if (strpos($itemName, 'WHISKY') !== false || 
        strpos($itemName, 'WHISKEY') !== false ||
        strpos($itemName, 'SCOTCH') !== false ||
        strpos($itemName, 'SINGLE MALT') !== false ||
        strpos($itemName, 'BLENDED') !== false ||
        strpos($itemName, 'BOURBON') !== false ||
        strpos($itemName, 'RYE') !== false ||
        preg_match('/\b(JOHNNIE WALKER|JACK DANIEL|CHIVAS|ROYAL CHALLENGE|8PM|OFFICER\'S CHOICE|MCDOWELL\'S|SIGNATURE|IMPERIAL BLUE)\b/', $itemName) ||
        preg_match('/\b(\d+ YEARS?|AGED)\b/', $itemName)) {
        return 'W';
    }
    
    // WINE Detection
    if (strpos($itemName, 'WINE') !== false ||
        strpos($itemName, 'PORT') !== false ||
        strpos($itemName, 'SHERRY') !== false ||
        strpos($itemName, 'CHAMPAGNE') !== false ||
        strpos($itemName, 'SPARKLING') !== false ||
        strpos($itemName, 'MERLOT') !== false ||
        strpos($itemName, 'CABERNET') !== false ||
        strpos($itemName, 'CHARDONNAY') !== false ||
        strpos($itemName, 'SAUVIGNON') !== false ||
        strpos($itemName, 'RED WINE') !== false ||
        strpos($itemName, 'WHITE WINE') !== false ||
        strpos($itemName, 'ROSE WINE') !== false ||
        strpos($itemName, 'DESSERT WINE') !== false ||
        strpos($itemName, 'FORTIFIED WINE') !== false ||
        preg_match('/\b(SULA|GROVER|FRATELLI|BORDEAUX|CHATEAU)\b/', $itemName)) {
        return 'V';
    }
    
    // BRANDY Detection
    if (strpos($itemName, 'BRANDY') !== false ||
        strpos($itemName, 'COGNAC') !== false ||
        strpos($itemName, 'VSOP') !== false ||
        strpos($itemName, 'XO') !== false ||
        strpos($itemName, 'NAPOLEON') !== false ||
        preg_match('/\b(HENNESSY|REMY MARTIN|MARTELL|COURVOISIER|MANSION HOUSE|OLD ADMIRAL|DUNHILL)\b/', $itemName) ||
        strpos($itemName, 'VS ') !== false) {
        return 'D';
    }
    
    // VODKA Detection
    if (strpos($itemName, 'VODKA') !== false ||
        preg_match('/\b(SMIRNOFF|ABSOLUT|ROMANOV|GREY GOOSE|BELVEDERE|CIROC|FINLANDIA)\b/', $itemName) ||
        strpos($itemName, 'LEMON VODKA') !== false ||
        strpos($itemName, 'ORANGE VODKA') !== false ||
        strpos($itemName, 'FLAVORED VODKA') !== false) {
        return 'K';
    }
    
    // GIN Detection
    if (strpos($itemName, 'GIN') !== false ||
        strpos($itemName, 'LONDON DRY') !== false ||
        strpos($itemName, 'NAVY STRENGTH') !== false ||
        preg_match('/\b(BOMBAY|GORDON\'S|TANQUERAY|BEEFEATER|HENDRICK\'S|BLUE RIBAND)\b/', $itemName) ||
        strpos($itemName, 'JUNIPER') !== false ||
        strpos($itemName, 'BOTANICAL GIN') !== false ||
        strpos($itemName, 'DRY GIN') !== false) {
        return 'G';
    }
    
    // RUM Detection
    if (strpos($itemName, 'RUM') !== false ||
        strpos($itemName, 'DARK RUM') !== false ||
        strpos($itemName, 'WHITE RUM') !== false ||
        strpos($itemName, 'SPICED RUM') !== false ||
        strpos($itemName, 'AGED RUM') !== false ||
        preg_match('/\b(BACARDI|CAPTAIN MORGAN|OLD MONK|HAVANA CLUB|MCDOWELL\'S RUM|CONTESSA RUM)\b/', $itemName) ||
        strpos($itemName, 'GOLD RUM') !== false ||
        strpos($itemName, 'NAVY RUM') !== false) {
        return 'R';
    }
    
    // BEER Detection
    if (strpos($itemName, 'BEER') !== false || 
        strpos($itemName, 'LAGER') !== false ||
        strpos($itemName, 'ALE') !== false ||
        strpos($itemName, 'STOUT') !== false ||
        strpos($itemName, 'PILSNER') !== false ||
        strpos($itemName, 'DRAUGHT') !== false ||
        preg_match('/\b(KINGFISHER|TUBORG|CARLSBERG|BUDWEISER|HEINEKEN|CORONA|FOSTER\'S)\b/', $itemName)) {
        
        $strongIndicators = ['STRONG', 'SUPER STRONG', 'EXTRA STRONG', 'BOLD', 'HIGH', 'POWER', 'XXX', '5000', '8000', '9000', '10000'];
        $mildIndicators = ['MILD', 'SMOOTH', 'LIGHT', 'DRAUGHT', 'LAGER', 'PILSNER', 'REGULAR', 'PREMIUM', 'LITE'];
        
        $isStrongBeer = false;
        foreach ($strongIndicators as $indicator) {
            if (strpos($itemName, $indicator) !== false) {
                $isStrongBeer = true;
                break;
            }
        }
        
        if ($isStrongBeer) {
            return 'F'; // FERMENTED BEER (Strong)
        } else {
            return 'M'; // MILD BEER
        }
    }
    
    // Default to Others if no match found
    return 'O';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Item - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .license-info {
        background-color: #e7f3ff;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 15px;
    }
    .class-detection-info {
        background-color: #fff3cd;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 15px;
        border-left: 4px solid #ffc107;
    }
    .form-label small {
        font-weight: normal;
        color: #6c757d;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Edit Item</h3>

      <!-- License Restriction Info -->
      <div class="license-info mb-3">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Available classes: 
              <?php 
              if (!empty($available_classes)) {
                  $class_names = [];
                  foreach ($available_classes as $class) {
                      $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                  }
                  echo implode(', ', $class_names);
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
      </div>

      <!-- Class Detection Info -->
      <div class="class-detection-info mb-3">
          <strong>Note:</strong> You can manually select the class or let the system detect it from the Item Name.
          <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="detectClassFromName()">
              <i class="fas fa-magic"></i> Auto-Detect Class
          </button>
      </div>

      <!-- Liquor Mode Indicator -->
      <div class="mode-indicator mb-3">
        <span class="badge bg-primary">
          <?= $mode === 'F' ? 'Foreign Liquor' : ($mode === 'C' ? 'Country Liquor' : 'Others') ?>
        </span>
      </div>

      <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error_message'] ?></div>
        <?php unset($_SESSION['error_message']); ?>
      <?php endif; ?>

      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success_message'] ?></div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>

      <?php if ($item): ?>
      <div class="card">
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="mode" value="<?= $mode ?>">
            <input type="hidden" name="original_sub_class" value="<?= htmlspecialchars($item['SUB_CLASS']) ?>">
            
            <div class="row mb-3">
              <div class="col-md-4 col-12">
                <label for="code" class="form-label">Item Code</label>
                <input type="text" class="form-control" id="code" value="<?= htmlspecialchars($item['CODE']) ?>" readonly>
              </div>
              <div class="col-md-4 col-12">
                <label for="Print_Name" class="form-label">Print Name</label>
                <input type="text" class="form-control" id="Print_Name" name="Print_Name" value="<?= htmlspecialchars($item['Print_Name']) ?>">
              </div>
              <div class="col-md-4 col-12">
                <label for="item_group" class="form-label">Item Group</label>
                <input type="text" class="form-control" id="item_group" name="item_group" 
                       value="<?= htmlspecialchars($item['ITEM_GROUP']) ?>">
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6 col-12">
                <label for="details" class="form-label">Item Name</label>
                <input type="text" class="form-control" id="details" name="details" value="<?= htmlspecialchars($item['DETAILS']) ?>" required>
              </div>
              <div class="col-md-6 col-12">
                <label for="sub_class" class="form-label">Sub Class</label>
                <select class="form-select" id="sub_class" name="sub_class" required>
                  <option value="">Select Sub Class</option>
                  <?php foreach ($allSubclassDescriptions as $code => $desc): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $item['SUB_CLASS'] == $code ? 'selected' : '' ?>>
                      <?= htmlspecialchars($code) ?> - <?= htmlspecialchars($desc) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6 col-12">
                <label for="class" class="form-label">Class</label>
                <select class="form-select" id="class" name="class" required>
                  <option value="">Select Class</option>
                  <?php foreach ($classDescriptions as $code => $desc): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $item['CLASS'] == $code ? 'selected' : '' ?>>
                      <?= htmlspecialchars($code) ?> - <?= htmlspecialchars($desc) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6 col-12">
                <label for="barcode" class="form-label">Bar Code</label>
                <input type="text" class="form-control" id="barcode" name="barcode" value="<?= htmlspecialchars($item['BARCODE'] ?? '') ?>">
              </div>
            </div>

            <!-- Opening Balance Field -->
            <div class="row mb-3">
              <div class="col-md-6 col-12">
                <label for="opening_balance" class="form-label">Opening Balance</label>
                <input type="number" class="form-control" id="opening_balance" name="opening_balance" 
                       value="<?= htmlspecialchars($opening_balance) ?>" min="0" step="1">
                <small class="text-muted">Current stock quantity (whole number)</small>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-4 col-12">
                <label for="pprice" class="form-label">Purchase Price</label>
                <input type="number" step="0.001" class="form-control" id="pprice" name="pprice" value="<?= htmlspecialchars($item['PPRICE']) ?>" required>
              </div>
              <div class="col-md-4 col-12">
                <label for="bprice" class="form-label">Base Price</label>
                <input type="number" step="0.001" class="form-control" id="bprice" name="bprice" value="<?= htmlspecialchars($item['BPRICE']) ?>" required>
              </div>
              <div class="col-md-4 col-12">
                <label for="rprice" class="form-label">Retail Price</label>
                <input type="number" step="0.001" class="form-control" id="rprice" name="rprice" value="<?= htmlspecialchars($item['RPRICE'] ?? '') ?>">
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6 col-12">
                <label for="mprice" class="form-label">MRP Price</label>
                <input type="number" step="0.001" class="form-control" id="mprice" name="mprice" value="<?= htmlspecialchars($item['MPRICE'] ?? '') ?>">
              </div>
            </div>

            <div class="d-flex justify-content-between mt-4">
              <a href="item_master.php?mode=<?= $mode ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
              </a>
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Item
              </button>
            </div>
          </form>
        </div>
      </div>
      <?php else: ?>
        <div class="alert alert-danger">Item not found.</div>
      <?php endif; ?>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Function to detect class from item name
function detectClassFromName() {
    const itemName = document.getElementById('details').value.toUpperCase();
    const classSelect = document.getElementById('class');
    
    if (!itemName) {
        alert('Please enter an item name first.');
        return;
    }
    
    // Basic detection logic (simplified version of server-side logic)
    let detectedClass = 'O'; // Default to Others
    
    if (itemName.includes('WHISKY') || itemName.includes('WHISKEY') || itemName.includes('SCOTCH')) {
        detectedClass = 'W';
    } else if (itemName.includes('WINE') || itemName.includes('CHAMPAGNE')) {
        detectedClass = 'V';
    } else if (itemName.includes('BRANDY') || itemName.includes('COGNAC') || itemName.includes('VSOP')) {
        detectedClass = 'D';
    } else if (itemName.includes('VODKA')) {
        detectedClass = 'K';
    } else if (itemName.includes('GIN')) {
        detectedClass = 'G';
    } else if (itemName.includes('RUM')) {
        detectedClass = 'R';
    } else if (itemName.includes('BEER')) {
        if (itemName.includes('STRONG') || itemName.includes('5000') || itemName.includes('8000')) {
            detectedClass = 'F';
        } else {
            detectedClass = 'M';
        }
    }
    
    // Set the detected class in the dropdown
    for (let i = 0; i < classSelect.options.length; i++) {
        if (classSelect.options[i].value === detectedClass) {
            classSelect.selectedIndex = i;
            alert('Detected class: ' + detectedClass);
            return;
        }
    }
    
    alert('Could not detect a valid class. Please select manually.');
}
</script>
</body>
</html>