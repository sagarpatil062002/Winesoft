<?php
session_start();

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR'])) {
    header("Location: select_company.php");
    exit;
}

include_once "../config/db.php"; // MySQLi connection in $conn

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch items from tblitemmaster
$query = "SELECT CODE, NEW_CODE, DETAILS, DETAILS2, CLASS, SUB_CLASS, PPRICE, BPRICE
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
  <title>Excise Item Master - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">

  <style>
      .btn-primary {
          background-color: #006BBB;
          border-color: #006BBB;
      }
      .btn-primary:hover {
          background-color: #30A0E0;
          border-color: #30A0E0;
      }
      .btn-outline-primary {
          color: #006BBB;
          border-color: #006BBB;
      }
      .btn-outline-primary:hover {
          background-color: #006BBB;
          color: #fff;
      }
      .mode-active {
          background-color: #006BBB !important;
          color: #fff !important;
          border-color: #006BBB !important;
      }
      .table-header {
          background-color: #FFC872 !important;
          color: #006BBB !important;
      }
      @media (max-width: 768px) {
          .table-container {
              overflow-x: auto;
          }
          table {
              font-size: 14px;
          }
          .btn, .input-group .form-control {
              font-size: 14px;
          }
      }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Excise Item Master</h3>

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

      <!-- Add Item Button -->
      <div class="action-btn mb-3">
        <a href="add_item.php?mode=<?= $mode; ?>" class="btn btn-primary add-btn">
          <i class="fas fa-plus"></i> Add New Item
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
              <th>Description</th>
              <th>Class</th>
              <th>Sub Class</th>
              <th>P. Price</th>
              <th>B. Price</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?= htmlspecialchars($item['CODE']); ?></td>
                <td><?= htmlspecialchars($item['NEW_CODE']); ?></td>
                <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                <td><?= htmlspecialchars($item['CLASS']); ?></td>
                <td><?= htmlspecialchars($item['SUB_CLASS']); ?></td>
                <td><?= number_format($item['PPRICE'], 3); ?></td>
                <td><?= number_format($item['BPRICE'], 3); ?></td>
                <td>
                  <a href="edit_item.php?code=<?= urlencode($item['CODE']) ?>&mode=<?= $mode ?>"
                     class="btn btn-sm btn-primary" title="Edit">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="9" class="text-center text-muted">No items found.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
