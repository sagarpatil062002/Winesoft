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

// Fetch items from tblitemmaster with reorder levels
$query = "SELECT CODE, DETAILS, DETAILS2, REORDER, GREORDER 
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

// Handle form submission for updating reorder levels
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_reorder'])) {
    $code = $_POST['code'];
    $reorder = $_POST['reorder'];
    $greorder = $_POST['greorder'];
    
    $update_stmt = $conn->prepare("UPDATE tblitemmaster SET REORDER = ?, GREORDER = ? WHERE CODE = ?");
    $update_stmt->bind_param("iis", $reorder, $greorder, $code);
    
    if ($update_stmt->execute()) {
        $_SESSION['success_message'] = "Reorder levels updated successfully!";
        header("Location: item_reorder_level.php?mode=$mode");
        exit;
    } else {
        $error = "Error updating reorder levels: " . $conn->error;
    }
    $update_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item Reorder Level - WineSoft</title>
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
      <h3 class="mb-4">Item Reorder Level</h3>

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
            <i class="fas fa-search"></i> Search
          </button>
          <?php if ($search !== ''): ?>
            <a href="?mode=<?= $mode ?>" class="btn btn-secondary">
              <i class="fas fa-times"></i> Clear
            </a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Success/Error Messages -->
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success mb-3"><?= $_SESSION['success_message'] ?></div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>
      
      <?php if (isset($error)): ?>
        <div class="alert alert-danger mb-3"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- Items Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th>#</th>
              <th>Item Description</th>
              <th>Category</th>
              <th>Reorder Level</th>
              <th>Global Reorder</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $index => $item): ?>
              <tr>
                <td><?= $index + 1 ?></td>
                <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                <td>
                  <form method="POST" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="code" value="<?= htmlspecialchars($item['CODE']) ?>">
                    <input type="number" name="reorder" class="form-control form-control-sm" 
                           value="<?= htmlspecialchars($item['REORDER']) ?>" min="0" style="width: 80px;">
                    <button type="submit" name="update_reorder" class="btn btn-sm btn-primary">
                      <i class="fas fa-save"></i> Save
                    </button>
                  </form>
                </td>
                <td>
                  <form method="POST" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="code" value="<?= htmlspecialchars($item['CODE']) ?>">
                    <input type="number" name="greorder" class="form-control form-control-sm" 
                           value="<?= htmlspecialchars($item['GREORDER']) ?>" min="0" style="width: 80px;">
                    <button type="submit" name="update_reorder" class="btn btn-sm btn-primary">
                      <i class="fas fa-save"></i> Save
                    </button>
                  </form>
                </td>
                <td>
                  <a href="edit_item.php?code=<?= urlencode($item['CODE']) ?>&mode=<?= $mode ?>" 
                     class="btn btn-sm btn-primary" title="Edit Item">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="6" class="text-center text-muted">No items found.</td>
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