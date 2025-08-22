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

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch permits from tblpermit
$query = "SELECT CODE, DETAILS, P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS 
          FROM tblpermit
          WHERE PRMT_FLAG = 1"; // Only active permits

if ($search !== '') {
    $query .= " AND (DETAILS LIKE ? OR P_NO LIKE ?)";
    $params = ["%$search%", "%$search%"];
    $types = "ss";
} else {
    $params = [];
    $types = "";
}

$query .= " ORDER BY DETAILS ASC";

$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$permits = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Permit Master - WineSoft</title>
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
      <h3 class="mb-4">Permit Master Module</h3>

      <!-- Search -->
      <form method="GET" class="search-control mb-3">
        <div class="input-group">
          <input type="text" name="search" class="form-control"
                 placeholder="Search by permit name or number..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Find
          </button>
          <?php if ($search !== ''): ?>
            <a href="permit_master.php" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2">
        <a href="add_permit.php" class="btn btn-primary">
          <i class="fas fa-plus"></i> New
        </a>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Permits Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th>Ex.</th>
              <th>Permit Name</th>
              <th>Permit No.</th>
              <th>Iss. Date</th>
              <th>Exp. Date</th>
              <th>Place</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($permits)): ?>
            <?php foreach ($permits as $index => $permit): ?>
              <tr>
                <td><?= $index + 1 ?></td>
                <td><?= htmlspecialchars($permit['DETAILS']); ?></td>
                <td><?= htmlspecialchars($permit['P_NO']); ?></td>
                <td><?= date('d/m/Y', strtotime($permit['P_ISSDT'])); ?></td>
                <td><?= date('d/m/Y', strtotime($permit['P_EXP_DT'])); ?></td>
                <td><?= htmlspecialchars($permit['PLACE_ISS']); ?></td>
                <td class="d-flex gap-1">
                  <td class="d-flex gap-2">
    
    <a href="edit_permit.php?code=<?= urlencode($permit['CODE']) ?>" class="btn btn-sm btn-warning" title="Edit">
        <i class="fas fa-edit me-1"></i> Edit
    </a>
    <a href="delete_permit.php?code=<?= urlencode($permit['CODE']) ?>" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this permit?')">
        <i class="fas fa-trash me-1"></i> Delete
    </a>
</td>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="7" class="text-center text-muted">No permits found.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      
    </div>

  </div>

</div>
      <?php include 'components/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>