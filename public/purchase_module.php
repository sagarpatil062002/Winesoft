<?php
session_start();

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

include_once "../config/db.php";

// Handle success message
$success = isset($_GET['success']) ? $_GET['success'] : 0;

// Build query with filters
$whereConditions = ["p.CompID = ?", "p.PUR_FLAG = ?"];
$params = [$companyId, $mode];
$paramTypes = "is";

// Apply filters if they exist
if (isset($_GET['from_date']) && !empty($_GET['from_date'])) {
    $whereConditions[] = "p.DATE >= ?";
    $params[] = $_GET['from_date'];
    $paramTypes .= "s";
}

if (isset($_GET['to_date']) && !empty($_GET['to_date'])) {
    $whereConditions[] = "p.DATE <= ?";
    $params[] = $_GET['to_date'];
    $paramTypes .= "s";
}

if (isset($_GET['voc_no']) && !empty($_GET['voc_no'])) {
    $whereConditions[] = "p.VOC_NO LIKE ?";
    $params[] = '%' . $_GET['voc_no'] . '%';
    $paramTypes .= "s";
}

if (isset($_GET['supplier']) && !empty($_GET['supplier'])) {
    $whereConditions[] = "s.DETAILS LIKE ?";
    $params[] = '%' . $_GET['supplier'] . '%';
    $paramTypes .= "s";
}

// Get all purchases for this company with filters
$purchases = [];
$purchaseQuery = "SELECT p.*, s.DETAILS as supplier_name 
                  FROM tblpurchases p 
                  LEFT JOIN tblsupplier s ON p.SUBCODE = s.CODE
                  WHERE " . implode(" AND ", $whereConditions) . "
                  ORDER BY p.DATE DESC, p.VOC_NO DESC";
                  
$purchaseStmt = $conn->prepare($purchaseQuery);
$purchaseStmt->bind_param($paramTypes, ...$params);
$purchaseStmt->execute();
$purchaseResult = $purchaseStmt->get_result();
if ($purchaseResult) $purchases = $purchaseResult->fetch_all(MYSQLI_ASSOC);
$purchaseStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Purchase Module - <?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
<style>
  .table-container{overflow-x:auto;max-height:520px}
  table.styled-table{width:100%;border-collapse:collapse}
  .styled-table th,.styled-table td{border:1px solid #e5e7eb;padding:8px 10px}
  .styled-table thead th{position:sticky;top:0;background:#f8fafc;z-index:1}
  .action-buttons{display:flex;gap:5px}
  .status-badge{padding:4px 8px;border-radius:4px;font-size:0.8rem}
  .status-completed{background:#d1fae5;color:#065f46}
  .status-pending{background:#fef3c7;color:#92400e}
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Purchase Module - <?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?></h4>
        <a href="purchases.php?mode=<?=$mode?>" class="btn btn-primary">
          <i class="fa-solid fa-plus me-1"></i> New Purchase
        </a>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> Purchase saved successfully!
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Filter Section -->
      <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="fa-solid fa-filter me-2"></i>Filters</div>
        <div class="card-body">
          <form method="GET" class="row g-3">
            <input type="hidden" name="mode" value="<?=$mode?>">
            <div class="col-md-3">
              <label class="form-label">From Date</label>
              <input type="date" class="form-control" name="from_date" value="<?=isset($_GET['from_date']) ? $_GET['from_date'] : ''?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">To Date</label>
              <input type="date" class="form-control" name="to_date" value="<?=isset($_GET['to_date']) ? $_GET['to_date'] : ''?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Voucher No.</label>
              <input type="text" class="form-control" name="voc_no" value="<?=isset($_GET['voc_no']) ? $_GET['voc_no'] : ''?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Supplier</label>
              <input type="text" class="form-control" name="supplier" value="<?=isset($_GET['supplier']) ? $_GET['supplier'] : ''?>">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter me-1"></i> Apply Filters</button>
              <a href="purchase_module.php?mode=<?=$mode?>" class="btn btn-secondary"><i class="fa-solid fa-times me-1"></i> Clear</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Purchases List -->
      <div class="card">
        <div class="card-header fw-semibold"><i class="fa-solid fa-list me-2"></i>Purchase Records</div>
        <div class="card-body">
          <?php if (count($purchases) > 0): ?>
            <div class="table-container">
              <table class="styled-table">
                <thead>
                  <tr>
                    <th>Voucher No.</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>Invoice No.</th>
                    <th>Invoice Date</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($purchases as $purchase): ?>
                    <tr>
                      <td><?=htmlspecialchars($purchase['VOC_NO'])?></td>
                      <td><?=htmlspecialchars($purchase['DATE'])?></td>
                      <td><?=htmlspecialchars($purchase['supplier_name'])?></td>
                      <td><?=htmlspecialchars($purchase['INV_NO'])?></td>
                      <td><?=htmlspecialchars($purchase['INV_DATE'])?></td>
                      <td>₹<?=number_format($purchase['TAMT'], 2)?></td>
                      <td>
                        <span class="status-badge status-completed">Completed</span>
                      </td>
                      <td>

    <a href="purchase_edit.php?id=<?=htmlspecialchars($purchase['ID'])?>&mode=<?=htmlspecialchars($mode)?>" 
       class="btn btn-sm btn-warning" title="Edit">
      <i class="fa-solid fa-edit"></i>
    </a>

    <button class="btn btn-sm btn-danger" 
            title="Delete" 
            onclick="confirmDelete(<?=htmlspecialchars($purchase['ID'])?>, '<?=htmlspecialchars($mode)?>')">
      <i class="fa-solid fa-trash"></i>
    </button>
  </div>
</td>

                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-center py-4">
              <i class="fa-solid fa-inbox fa-3x text-muted mb-3"></i>
              <h5 class="text-muted">No purchases found</h5>
              <p class="text-muted">Get started by creating your first purchase</p>
              <a href="purchases.php?mode=<?=$mode?>" class="btn btn-primary mt-2">
                <i class="fa-solid fa-plus me-1"></i> Create Purchase
              </a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete this purchase? This action cannot be undone.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <a href="#" id="deleteConfirm" class="btn btn-danger">Delete</a>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmDelete(purchaseId, mode) {
  $('#deleteConfirm').attr('href', 'purchase_delete.php?id=' + purchaseId + '&mode=' + mode);
  $('#deleteModal').modal('show');
}

// Apply filters with date range validation
$('form').on('submit', function(e) {
  const fromDate = $('input[name="from_date"]').val();
  const toDate = $('input[name="to_date"]').val();
  
  if (fromDate && toDate && fromDate > toDate) {
    e.preventDefault();
    alert('From date cannot be greater than To date');
    return false;
  }
});
</script>
</body>
</html>