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

// Build query to fetch vouchers (group by VNO to show unique vouchers)
$query = "SELECT VNO, VDATE, PARTI, SUM(AMOUNT) as AMOUNT, DRCR, MODE, NARR, CHEQ_NO, CHEQ_DT, 
                 COUNT(*) as payment_count, GROUP_CONCAT(DISTINCT DOC_NO SEPARATOR ', ') as doc_nos
          FROM tblexpenses 
          WHERE COMP_ID = ? AND VDATE BETWEEN ? AND ?";
$params = [$_SESSION['CompID'], $date_from, $date_to];
$types = "iss";

if (!empty($voucher_mode)) {
    $query .= " AND MODE = ?";
    $params[] = $voucher_mode;
    $types .= "s";
}

$query .= " GROUP BY VNO, VDATE, PARTI, DRCR, MODE, NARR, CHEQ_NO, CHEQ_DT
          ORDER BY VDATE DESC, VNO DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$vouchers = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate totals
$total_amount = 0;
$total_vouchers = 0;
foreach ($vouchers as $voucher) {
    $total_amount += $voucher['AMOUNT'];
    $total_vouchers += $voucher['payment_count'];
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
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    .payment-badge {
        font-size: 0.7em;
        margin-left: 5px;
    }
    .doc-nos {
        font-size: 0.8em;
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .table th {
        background-color: #f8f9fa;
        font-weight: 600;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <div class="content-area">
      <h3 class="mb-4">View Voucher Entries</h3>

      <!-- Filter Controls -->
      <form method="GET" class="mb-4">
        <div class="row g-3 align-items-end">
          <div class="col-md-3">
            <label class="form-label">Date From:</label>
            <input type="date" class="form-control" name="date_from" value="<?= $date_from ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Date To:</label>
            <input type="date" class="form-control" name="date_to" value="<?= $date_to ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Voucher Mode:</label>
            <select class="form-select" name="voucher_mode">
              <option value="">All Modes</option>
              <option value="C" <?= $voucher_mode === 'C' ? 'selected' : '' ?>>Cash</option>
              <option value="B" <?= $voucher_mode === 'B' ? 'selected' : '' ?>>Bank</option>
            </select>
          </div>
          <div class="col-md-3">
            <button type="submit" class="btn btn-primary w-100">
              <i class="fas fa-filter"></i> Filter
            </button>
            <a href="voucher_view.php" class="btn btn-secondary w-100 mt-2">
              <i class="fas fa-times"></i> Clear
            </a>
          </div>
        </div>
      </form>

      <!-- Summary Card -->
      <div class="card mb-4">
        <div class="card-body">
          <div class="row text-center">
            <div class="col-md-3">
              <h4 class="text-primary"><?= count($vouchers) ?></h4>
              <strong>Total Vouchers</strong>
            </div>
            <div class="col-md-3">
              <h4 class="text-success"><?= $total_vouchers ?></h4>
              <strong>Total Payments</strong>
            </div>
            <div class="col-md-3">
              <h4 class="text-info">₹<?= number_format($total_amount, 2) ?></h4>
              <strong>Total Amount</strong>
            </div>
            <div class="col-md-3">
              <h6 class="text-muted"><?= date('d/m/Y', strtotime($date_from)) ?> - <?= date('d/m/Y', strtotime($date_to)) ?></h6>
              <strong>Period</strong>
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
      <div class="card">
        <div class="card-body">
          <div class="table-responsive">
            <table class="table table-bordered table-hover">
              <thead class="table-light">
                <tr>
                  <th>Voucher No</th>
                  <th>Date</th>
                  <th>Particulars</th>
                  <th>Mode</th>
                  <th>Amount</th>
                  <th>Payments</th>
                  <th>Doc Nos</th>
                  <th>Cheque No</th>
                  <th>Cheque Date</th>
                  <th>Type</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($vouchers)): ?>
                  <?php foreach ($vouchers as $voucher): ?>
                    <tr>
                      <td>
                        <strong><?= htmlspecialchars($voucher['VNO']) ?></strong>
                        <?php if ($voucher['payment_count'] > 1): ?>
                          <span class="badge bg-warning payment-badge" title="Multiple payments"><?= $voucher['payment_count'] ?></span>
                        <?php endif; ?>
                      </td>
                      <td><?= date('d/m/Y', strtotime($voucher['VDATE'])) ?></td>
                      <td><?= htmlspecialchars($voucher['PARTI']) ?></td>
                      <td>
                        <span class="badge bg-<?= $voucher['MODE'] === 'C' ? 'success' : 'info' ?>">
                          <?= $voucher['MODE'] === 'C' ? 'Cash' : 'Bank' ?>
                        </span>
                      </td>
                      <td class="fw-bold">₹<?= number_format($voucher['AMOUNT'], 2) ?></td>
                      <td>
                        <span class="badge bg-<?= $voucher['payment_count'] > 1 ? 'warning' : 'secondary' ?>">
                          <?= $voucher['payment_count'] ?> payment<?= $voucher['payment_count'] > 1 ? 's' : '' ?>
                        </span>
                      </td>
                      <td>
                        <span class="doc-nos" title="<?= htmlspecialchars($voucher['doc_nos'] ?? 'N/A') ?>">
                          <?= htmlspecialchars($voucher['doc_nos'] ?? 'N/A') ?>
                        </span>
                      </td>
                      <td><?= htmlspecialchars($voucher['CHEQ_NO'] ?? 'N/A') ?></td>
                      <td><?= !empty($voucher['CHEQ_DT']) ? date('d/m/Y', strtotime($voucher['CHEQ_DT'])) : 'N/A' ?></td>
                      <td>
                        <span class="badge bg-<?= $voucher['DRCR'] === 'D' ? 'danger' : 'success' ?>">
                          <?= $voucher['DRCR'] === 'D' ? 'Payment' : 'Receipt' ?>
                        </span>
                      </td>
                      <td>
                        <div class="btn-group btn-group-sm">
                          <a href="voucher_entry.php?action=edit&id=<?= $voucher['VNO'] ?>" class="btn btn-outline-primary">
                            <i class="fas fa-edit"></i>
                          </a>
                          <a href="voucher_entry.php?action=view&id=<?= $voucher['VNO'] ?>" class="btn btn-outline-info">
                            <i class="fas fa-eye"></i>
                          </a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="11" class="text-center text-muted py-4">
                      <i class="fas fa-inbox fa-2x mb-2"></i><br>
                      No voucher entries found for the selected criteria.
                    </td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>