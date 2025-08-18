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

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch suppliers from tblsupplier
$query = "SELECT CODE, DETAILS, MODE, LIQ_FLAG, ADDR1, ADDR2, PINCODE, SALES_TAX
          FROM tblsupplier
          WHERE 1=1";
$params = [];
$types = "";

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
$suppliers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Supplier Master - WineSoft</title>
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
      <h3 class="mb-4">Supplier Master</h3>

      <!-- Search -->
      <form method="GET" class="search-control mb-3">
        <div class="input-group">
          <input type="text" name="search" class="form-control"
                 placeholder="Search by supplier name or code..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Find
          </button>
          <?php if ($search !== ''): ?>
            <a href="supplier_master.php" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Add Supplier Button -->
      <div class="action-btn mb-3 d-flex gap-2">
        <a href="add_supplier.php" class="btn btn-primary">
          <i class="fas fa-plus"></i> New Supplier
        </a>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Suppliers Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th>Supplier ID</th>
              <th>Supplier Description</th>
              <th>Mode</th>
              <th>Liquor Flag</th>
              <th>Address</th>
              <th>Pincode</th>
              <th>Sales Tax</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($suppliers)): ?>
            <?php foreach ($suppliers as $supplier): ?>
              <tr>
                <td><?= htmlspecialchars($supplier['CODE']); ?></td>
                <td><?= htmlspecialchars($supplier['DETAILS']); ?></td>
                <td><?= htmlspecialchars($supplier['MODE']); ?></td>
                <td><?= htmlspecialchars($supplier['LIQ_FLAG']); ?></td>
                <td>
                  <?= htmlspecialchars($supplier['ADDR1']); ?><br>
                  <?= htmlspecialchars($supplier['ADDR2']); ?>
                </td>
                <td><?= htmlspecialchars($supplier['PINCODE']); ?></td>
                <td><?= htmlspecialchars($supplier['SALES_TAX']); ?></td>
                <td>
                  <a href="edit_supplier.php?code=<?= urlencode($supplier['CODE']) ?>"
                     class="btn btn-sm btn-primary" title="Edit">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="8" class="text-center text-muted">No suppliers found.</td>
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