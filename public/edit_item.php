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

// Fetch subclass descriptions from tblsubclass for the current mode
$subclassDescriptions = [];
$subclassQuery = "SELECT ITEM_GROUP, `DESC` FROM tblsubclass WHERE LIQ_FLAG = ?";
$subclassStmt = $conn->prepare($subclassQuery);
$subclassStmt->bind_param("s", $mode);
$subclassStmt->execute();
$subclassResult = $subclassStmt->get_result();
while ($row = $subclassResult->fetch_assoc()) {
    $subclassDescriptions[$row['ITEM_GROUP']] = $row['DESC'];
}
$subclassStmt->close();

// Fetch item details
$item = null;
if ($item_code) {
    $stmt = $conn->prepare("SELECT * FROM tblitemmaster WHERE CODE = ?");
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $Print_Name = $_POST['Print_Name'];
    $details = $_POST['details'];
    $details2 = $_POST['details2'];
    $class = $_POST['class'];
    $sub_class = $_POST['sub_class'];
    $item_group = $_POST['item_group'];
    $pprice = $_POST['pprice'];
    $bprice = $_POST['bprice'];
    $bottles = $_POST['bottles'];
    $gob = $_POST['gob'];
    $ob = $_POST['ob'];
    $ob2 = $_POST['ob2'];
    $mprice = $_POST['mprice'];
    $barcode = $_POST['barcode'];
    $rprice = $_POST['rprice'] ?? 0;

    // Validate class against license restrictions
    if (!in_array($class, $allowed_classes)) {
        $_SESSION['error_message'] = "Selected class is not allowed for your license type.";
        header("Location: edit_item.php?code=" . urlencode($item_code) . "&mode=" . $mode);
        exit;
    }

    $stmt = $conn->prepare("UPDATE tblitemmaster SET 
                          Print_Name = ?, 
                          DETAILS = ?, 
                          DETAILS2 = ?, 
                          CLASS = ?, 
                          SUB_CLASS = ?, 
                          ITEM_GROUP = ?,
                          PPRICE = ?, 
                          BPRICE = ?,
                          BOTTLES = ?,
                          GOB = ?,
                          OB = ?,
                          OB2 = ?,
                          MPRICE = ?,
                          RPRICE = ?,
                          BARCODE = ?
                          WHERE CODE = ?");
    $stmt->bind_param("ssssssdddddddds", $Print_Name, $details, $details2, $class, $sub_class, $item_group, $pprice, $bprice, $bottles, $gob, $ob, $ob2, $mprice, $rprice, $barcode, $item_code);
    
    if ($stmt->execute()) {
        // Update stock information if opening stock changed
        $opening_stock = max($gob, $ob, $ob2);
        updateItemStock($conn, $company_id, $item_code, $mode, $opening_stock);
        
        $_SESSION['success_message'] = "Item updated successfully!";
        header("Location: item_master.php?mode=" . $mode);
        exit;
    } else {
        $_SESSION['error_message'] = "Error updating item: " . $conn->error;
    }
    $stmt->close();
}

// Function to update item stock information
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

      <?php if ($item): ?>
      <div class="card">
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="mode" value="<?= $mode ?>">
            
            <div class="row mb-3">
              <div class="col-md-4 col-12">
                <label for="code" class="form-label">Item Code</label>
                <input type="text" class="form-control" id="code" value="<?= htmlspecialchars($item['CODE']) ?>" readonly>
              </div>
              <div class="col-md-4 col-12">
                <label for="Print_Name" class="form-label">New Code</label>
                <input type="text" class="form-control" id="Print_Name" name="Print_Name" value="<?= htmlspecialchars($item['Print_Name']) ?>">
              </div>
              <div class="col-md-4 col-12">
                <label for="item_group" class="form-label">Item Group</label>
                <input type="text" class="form-control" id="item_group" name="item_group" value="<?= htmlspecialchars($item['ITEM_GROUP']) ?>" readonly>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6 col-12">
                <label for="details" class="form-label">Item Name</label>
                <input type="text" class="form-control" id="details" name="details" value="<?= htmlspecialchars($item['DETAILS']) ?>" required>
              </div>
              <div class="col-md-6 col-12">
                <label for="details2" class="form-label">Description</label>
                <input type="text" class="form-control" id="details2" name="details2" value="<?= htmlspecialchars($item['DETAILS2']) ?>">
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
                <label for="sub_class" class="form-label">Sub Class</label>
                <select class="form-select" id="sub_class" name="sub_class" required>
                  <option value="">Select Sub Class</option>
                  <?php foreach ($subclassDescriptions as $code => $desc): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $item['SUB_CLASS'] == $code ? 'selected' : '' ?>>
                      <?= htmlspecialchars($code) ?> - <?= htmlspecialchars($desc) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- New Fields: Bits/Case, Op.Stk(G), Op.Stk(C1), Op.Stk(C2), MRP Price, Bar Code -->
            <div class="row mb-3">
              <div class="col-md-3 col-6">
                <label for="bottles" class="form-label">Btls/Case</label>
                <input type="number" step="0.001" class="form-control" id="bottles" name="bottles" value="<?= htmlspecialchars($item['BOTTLES'] ?? '') ?>">
              </div>
              <div class="col-md-3 col-6">
                <label for="gob" class="form-label">Op.Stk(G)</label>
                <input type="number" step="0.001" class="form-control" id="gob" name="gob" value="<?= htmlspecialchars($item['GOB'] ?? '') ?>">
              </div>
              <div class="col-md-3 col-6">
                <label for="ob" class="form-label">Op.Stk(C1)</label>
                <input type="number" step="0.001" class="form-control" id="ob" name="ob" value="<?= htmlspecialchars($item['OB'] ?? '') ?>">
              </div>
              <div class="col-md-3 col-6">
                <label for="ob2" class="form-label">Op.Stk(C2)</label>
                <input type="number" step="0.001" class="form-control" id="ob2" name="ob2" value="<?= htmlspecialchars($item['OB2'] ?? '') ?>">
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-4 col-12">
                <label for="pprice" class="form-label">P. Price</label>
                <input type="number" step="0.001" class="form-control" id="pprice" name="pprice" value="<?= htmlspecialchars($item['PPRICE']) ?>" required>
              </div>
              <div class="col-md-4 col-12">
                <label for="bprice" class="form-label">B. Price</label>
                <input type="number" step="0.001" class="form-control" id="bprice" name="bprice" value="<?= htmlspecialchars($item['BPRICE']) ?>" required>
              </div>
              <div class="col-md-4 col-12">
                <label for="rprice" class="form-label">R. Price</label>
                <input type="number" step="0.001" class="form-control" id="rprice" name="rprice" value="<?= htmlspecialchars($item['RPRICE'] ?? '') ?>">
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6 col-12">
                <label for="mprice" class="form-label">MRP Price</label>
                <input type="number" step="0.001" class="form-control" id="mprice" name="mprice" value="<?= htmlspecialchars($item['MPRICE'] ?? '') ?>">
              </div>
              <div class="col-md-6 col-12">
                <label for="barcode" class="form-label">Bar Code</label>
                <input type="text" class="form-control" id="barcode" name="barcode" value="<?= htmlspecialchars($item['BARCODE'] ?? '') ?>">
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
</body>
</html>