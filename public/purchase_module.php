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

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch purchases from tblpurchases
$query = "SELECT 
            DATE, 
            SUBCODE, 
            VOC_NO, 
            INV_NO, 
            INV_DATE, 
            TAMT, 
            BILL_NO, 
            TPNO, 
            SCHDIS, 
            CASHDIS, 
            OCTROI, 
            CDAYS, 
            CTYPE, 
            BALANCE, 
            DISQTY, 
            OCT_PER, 
            DIS_DISC, 
            BRK_AMT, 
            BRK_DESC, 
            IND_NO, 
            LIQ_FLAG, 
            STAX_PER, 
            STAX_AMT, 
            TCS_PER, 
            TCS_AMT, 
            RATEDIFF, 
            SUR_PER, 
            SUR_AMT, 
            EDUC_PER, 
            EDUC_AMT, 
            EXPORTED, 
            MISC_CHARG, 
            PUR_FLAG, 
            SHOP_MODE, 
            COUNTER, 
            WSTax_Perc, 
            WSTax_Amt, 
            MBSTax_Perc, 
            MBSTax_Amt, 
            SBSTax_Perc, 
            SBStax_Amt, 
            CLSTax_Perc, 
            CLSTax_Amt 
          FROM tblpurchases 
          WHERE LIQ_FLAG = ?";
$params = [$mode];
$types = "s";

if ($search !== '') {
    $query .= " AND (INV_NO LIKE ? OR TPNO LIKE ? OR BILL_NO LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "sss";
}

$query .= " ORDER BY DATE DESC, INV_NO DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$purchases = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch supplier names from tblsupplier (not tblsubmaster)
$supplierNames = [];
$supplierQuery = "SELECT CODE, DETAILS FROM tblsupplier";
$supplierResult = $conn->query($supplierQuery);
while ($row = $supplierResult->fetch_assoc()) {
    $supplierNames[$row['CODE']] = $row['DETAILS'];
}

// Function to get supplier name
function getSupplierName($code, $supplierNames) {
    return $supplierNames[$code] ?? $code;
}

// Function to format date
function formatDate($dateString) {
    if (empty($dateString)) return '';
    $date = DateTime::createFromFormat('Y-m-d H:i:s.u', $dateString);
    return $date ? $date->format('d/m/Y') : $dateString;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Module - WineSoft</title>
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
      <h3 class="mb-4">Purchase Module</h3>

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
                 placeholder="Search by Invoice No, T.P. No, or Bill No..." value="<?= htmlspecialchars($search); ?>">
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
        <a href="purchases.php" class="btn btn-primary">
          <i class="fas fa-plus"></i> New Purchase
        </a>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Purchases Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th>Date</th>
              <th>T.P. No</th>
              <th>Inv. No</th>
              <th>Inv. Date</th>
              <th>Voc. No</th>
              <th>Supplier</th>
              <th>Amount</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($purchases)): ?>
            <?php foreach ($purchases as $purchase): ?>
              <tr>
                <td><?= formatDate($purchase['DATE']); ?></td>
                <td><?= htmlspecialchars($purchase['TPNO']); ?></td>
                <td><?= htmlspecialchars($purchase['INV_NO']); ?></td>
                <td><?= formatDate($purchase['INV_DATE']); ?></td>
                <td><?= htmlspecialchars($purchase['VOC_NO']); ?></td>
                <td><?= htmlspecialchars(getSupplierName($purchase['SUBCODE'], $supplierNames)); ?></td>
                <td><?= number_format($purchase['TAMT'], 2); ?></td>
                <td>
                  <a href="view_purchase.php?vno=<?= urlencode($purchase['VOC_NO']) ?>&mode=<?= $mode ?>"
                     class="btn btn-sm btn-primary" title="View">
                    <i class="fas fa-eye"></i> View
                  </a>
                  <a href="edit_purchase.php?vno=<?= urlencode($purchase['VOC_NO']) ?>&mode=<?= $mode ?>"
                     class="btn btn-sm btn-secondary" title="Edit">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="8" class="text-center text-muted">No purchases found.</td>
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