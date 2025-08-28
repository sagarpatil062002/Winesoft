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

// Handle price updates when Save button is clicked
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_prices'])) {
    $updated = false;
    foreach ($_POST['prices'] as $code => $priceData) {
        $bprice = floatval($priceData['bprice']);
        $pprice = floatval($priceData['pprice']);
        $code = $conn->real_escape_string($code);
        
        $updateQuery = "UPDATE tblitemmaster SET BPRICE = ?, PPRICE = ? WHERE CODE = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("dds", $bprice, $pprice, $code);
        if ($stmt->execute()) {
            $updated = true;
        }
        $stmt->close();
    }
    
    if ($updated) {
        $_SESSION['price_update_message'] = "Prices saved successfully!";
    }
    header("Location: ".$_SERVER['PHP_SELF']."?mode=".$_GET['mode']);
    exit;
}

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch items from tblitemmaster
$query = "SELECT CODE, DETAILS, DETAILS2, CLASS, PPRICE, BPRICE
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item-wise Price List - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .price-input {
      width: 100px;
      text-align: right;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Item-wise Price List</h3>

      <?php if (isset($_SESSION['price_update_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <?= $_SESSION['price_update_message'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['price_update_message']); ?>
      <?php endif; ?>

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
          <a href="?mode=O&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>

      <!-- User/System Defined Radio Buttons -->
      <div class="mb-3">
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="priceType" id="userDefined" checked>
          <label class="form-check-label" for="userDefined">User Defined</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="priceType" id="systemDefined">
          <label class="form-check-label" for="systemDefined">System Defined</label>
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

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2">
        <button type="submit" form="priceForm" class="btn btn-success">
          <i class="fas fa-save"></i> Save
        </button>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Items Table -->
      <form id="priceForm" method="POST">
        <input type="hidden" name="save_prices" value="1">
        <div class="table-container">
          <table class="styled-table table-striped">
            <thead class="table-header">
              <tr>
                <th>S.No</th>
                <th>Item Description</th>
                <th>Category</th>
                <th>B. Rate</th>
                <th>P.Price</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php $s_no = 1; ?>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= $s_no++; ?></td>
                  <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                  <td>
                    <input type="number" step="0.001" 
                           name="prices[<?= htmlspecialchars($item['CODE']) ?>][bprice]" 
                           class="form-control price-input bprice-input" 
                           value="<?= number_format($item['BPRICE'], 3, '.', '') ?>">
                  </td>
                  <td>
                    <input type="number" step="0.001" 
                           name="prices[<?= htmlspecialchars($item['CODE']) ?>][pprice]" 
                           class="form-control price-input pprice-input" 
                           value="<?= number_format($item['PPRICE'], 3, '.', '') ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" class="text-center text-muted">No items found.</td>
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
</body>
</html>