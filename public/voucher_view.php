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

// Set default filter values
$date_from = date('Y-m-01');
$date_to = date('Y-m-d');
$voucher_mode = isset($_GET['mode']) ? $_GET['mode'] : '';

// Handle filter form submission
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : $date_from;
    $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : $date_to;
    $voucher_mode = isset($_GET['voucher_mode']) ? $_GET['voucher_mode'] : '';
}

// Build query to fetch vouchers
$query = "SELECT VNO, VDATE, PARTI, AMOUNT, DRCR, MODE, NARR, CHEQ_NO, CHEQ_DT 
          FROM tblExpenses 
          WHERE COMP_ID = ? AND VDATE BETWEEN ? AND ?";
$params = [$_SESSION['CompID'], $date_from, $date_to];
$types = "iss";

if (!empty($voucher_mode)) {
    $query .= " AND MODE = ?";
    $params[] = $voucher_mode;
    $types .= "s";
}

$query .= " ORDER BY VDATE DESC, VNO DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$vouchers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate totals
$total_amount = 0;
foreach ($vouchers as $voucher) {
    $total_amount += $voucher['AMOUNT'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Voucher Entries - WineSoft</title>
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
      <h3 class="mb-4">View Voucher Entries</h3>

      <!-- Filter Controls -->
      <div class="action-controls mb-3">
        <div class="mode-selector">
          <label class="form-label">Date From:</label>
          <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
        </div>
        <div class="mode-selector">
          <label class="form-label">Date To:</label>
          <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
        </div>
        <div class="mode-selector">
          <label class="form-label">Voucher Mode:</label>
          <select class="form-select" name="voucher_mode">
            <option value="">All Modes</option>
            <option value="C" <?= $voucher_mode === 'C' ? 'selected' : '' ?>>Cash</option>
            <option value="B" <?= $voucher_mode === 'B' ? 'selected' : '' ?>>Bank</option>
          </select>
        </div>
        <div class="mode-selector d-flex align-items-end">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
          </button>
          <a href="voucher_view.php" class="btn btn-secondary ms-2">
            <i class="fas fa-times"></i> Clear
          </a>
        </div>
      </div>

      <!-- Summary Card -->
      <div class="card mb-3">
        <div class="card-body">
          <div class="row">
            <div class="col-md-4">
              <strong>Total Vouchers:</strong> <?= count($vouchers) ?>
            </div>
            <div class="col-md-4">
              <strong>Total Amount:</strong> ₹<?= number_format($total_amount, 2) ?>
            </div>
            <div class="col-md-4">
              <strong>Period:</strong> <?= date('d/m/Y', strtotime($date_from)) ?> - <?= date('d/m/Y', strtotime($date_to)) ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2">
        <a href="voucher_entry.php" class="btn btn-primary">
          <i class="fas fa-plus"></i> New Voucher
        </a>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Vouchers Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th>Voucher No</th>
              <th>Date</th>
              <th>Particulars</th>
              <th>Mode</th>
              <th>Amount</th>
              <th>Cheque No</th>
              <th>Cheque Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!empty($vouchers)): ?>
              <?php foreach ($vouchers as $voucher): ?>
                <tr>
                  <td><?= htmlspecialchars($voucher['VNO']) ?></td>
                  <td><?= date('d/m/Y', strtotime($voucher['VDATE'])) ?></td>
                  <td><?= htmlspecialchars($voucher['PARTI']) ?></td>
                  <td>
                    <span class="badge bg-<?= $voucher['MODE'] === 'C' ? 'success' : 'info' ?>">
                      <?= $voucher['MODE'] === 'C' ? 'Cash' : 'Bank' ?>
                    </span>
                  </td>
                  <td>₹<?= number_format($voucher['AMOUNT'], 2) ?></td>
                  <td><?= htmlspecialchars($voucher['CHEQ_NO'] ?? 'N/A') ?></td>
                  <td><?= !empty($voucher['CHEQ_DT']) ? date('d/m/Y', strtotime($voucher['CHEQ_DT'])) : 'N/A' ?></td>
                  <td>
                    <div class="btn-group">
                      <a href="voucher_entry.php?action=edit&id=<?= $voucher['VNO'] ?>" class="btn btn-sm btn-primary">
                        <i class="fas fa-edit"></i> Edit
                      </a>
                      <a href="voucher_entry.php?action=view&id=<?= $voucher['VNO'] ?>" class="btn btn-sm btn-info">
                        <i class="fas fa-eye"></i> View
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="8" class="text-center text-muted">No voucher entries found for the selected criteria.</td>
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