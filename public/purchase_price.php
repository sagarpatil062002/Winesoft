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

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch items from tblitemmaster - FILTERED BY LICENSE TYPE
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE
              FROM tblitemmaster
              WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
    $params = array_merge([$mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
    // If no classes allowed, show empty result
    $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE
              FROM tblitemmaster
              WHERE 1 = 0"; // Always false condition
    $params = [];
    $types = "";
}

if ($search !== '') {
    $query .= " AND (DETAILS LIKE ? OR CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " ORDER BY DETAILS ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle form submission for updating prices
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_prices'])) {
    foreach ($_POST['pprice'] as $code => $price) {
        $price = floatval($price);
        $updateStmt = $conn->prepare("UPDATE tblitemmaster SET PPRICE = ? WHERE CODE = ?");
        $updateStmt->bind_param("ds", $price, $code);
        $updateStmt->execute();
        $updateStmt->close();
    }
    
    // Refresh the page to show updated values
    header("Location: purchase_price.php?mode=" . $mode . "&search=" . urlencode($search));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Price Management - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Purchase Price Management</h3>

      <!-- License Restriction Info -->
      <div class="alert alert-info mb-3">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
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

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
        </div>
      </div>

      <!-- Search -->
      <form method="GET" class="search-control mb-3">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <div class="input-group">
          <input type="text" name="search" class="form-control"
                 placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Find
          </button>
          <?php if ($search !== ''): ?>
            <a href="?mode=<?= $mode ?>" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Price Management Form -->
      <form method="POST" id="priceForm">
        <div class="action-btn mb-3 d-flex gap-2">
          <button type="submit" name="update_prices" class="btn btn-success">
            <i class="fas fa-save"></i> Save
          </button>
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>

        <!-- Items Table -->
        <div class="table-container">
          <table class="styled-table table-striped">
            <thead class="table-header">
              <tr>
                <th>Code</th>
                <th>New Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>P. Price</th>
                <th>B. Price</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['CODE']); ?></td>
                  <td><?= htmlspecialchars($item['Print_Name']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                  <td>
                    <input type="number" step="0.001" 
                           name="pprice[<?= htmlspecialchars($item['CODE']); ?>]" 
                           value="<?= number_format($item['PPRICE'], 3); ?>" 
                           class="form-control form-control-sm">
                  </td>
                  <td><?= number_format($item['BPRICE'], 3); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="6" class="text-center text-muted">
                    <?php if (empty($allowed_classes)): ?>
                        No items available for your license type.
                    <?php else: ?>
                        No items found.
                    <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Add confirmation before saving
document.getElementById('priceForm').addEventListener('submit', function(e) {
    if (!confirm('Are you sure you want to update the purchase prices?')) {
        e.preventDefault();
    }
});
</script>
</body>
</html>