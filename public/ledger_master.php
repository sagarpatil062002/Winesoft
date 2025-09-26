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

// Fetch all groups first for dropdown and display
$groups = [];
$groupQuery = "SELECT GCODE, GHEAD FROM tblgheads ORDER BY GHEAD";
$groupResult = $conn->query($groupQuery);
while ($row = $groupResult->fetch_assoc()) {
    $groups[$row['GCODE']] = $row['GHEAD'];
}

// Fetch ledger data from tbllheads with group names
$query = "SELECT l.LCODE, l.LHEAD, l.GCODE, g.GHEAD AS GROUP_NAME 
          FROM tbllheads l
          LEFT JOIN tblgheads g ON l.GCODE = g.GCODE
          WHERE 1=1";
$params = [];
$types = "";

if ($search !== '') {
    $query .= " AND (l.LHEAD LIKE ? OR l.LCODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " ORDER BY l.LHEAD ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $ledgers = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $result = $conn->query($query);
    $ledgers = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ledger Master - WineSoft</title>
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
      <h3 class="mb-4">Ledger Master</h3>

      <!-- Search and Filter -->
      <div class="d-flex flex-column flex-md-row gap-3 mb-3">
       <!-- Search Form -->
<div class="row mb-3">
    <div class="col-md-6">
        <form method="GET" class="search-control" id="searchForm">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
            <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
            <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">
            <div class="input-group">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
                <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                <?php if ($search !== ''): ?>
                    <a href="?mode=<?= $mode ?>&sequence_type=<?= $sequence_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-secondary">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
      </div>

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLedgerModal">
          <i class="fas fa-plus"></i> New Ledger
        </button>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Ledgers Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th>Ledger ID</th>
              <th>Ledger Description</th>
              <th>Group</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($ledgers)): ?>
            <?php foreach ($ledgers as $ledger): ?>
              <tr>
                <td><?= htmlspecialchars($ledger['LCODE']); ?></td>
                <td><?= htmlspecialchars($ledger['LHEAD']); ?></td>
                <td><?= htmlspecialchars($ledger['GROUP_NAME'] ?? 'N/A'); ?></td>
                <td>
                  <button class="btn btn-sm btn-primary edit-ledger" 
                          data-id="<?= $ledger['LCODE'] ?>"
                          data-name="<?= htmlspecialchars($ledger['LHEAD']) ?>"
                          data-group="<?= $ledger['GCODE'] ?>">
                    <i class="fas fa-edit"></i> Edit
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="4" class="text-center text-muted">No ledgers found.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Add Ledger Modal -->
<div class="modal fade" id="addLedgerModal" tabindex="-1" aria-labelledby="addLedgerModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form id="ledgerForm" action="save_ledger.php" method="POST">
        <div class="modal-header">
          <h5 class="modal-title" id="addLedgerModalLabel">Add New Ledger</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="ledger_id" id="ledger_id" value="">
          <div class="mb-3">
            <label for="ledger_name" class="form-label">Ledger Name</label>
            <input type="text" class="form-control" id="ledger_name" name="ledger_name" required>
          </div>
          <div class="mb-3">
            <label for="ledger_group" class="form-label">Group</label>
            <select class="form-select" id="ledger_group" name="ledger_group" required>
              <option value="">Select Group</option>
              <?php foreach ($groups as $code => $name): ?>
                <option value="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Ledger</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
  // Handle edit button clicks
  $('.edit-ledger').click(function() {
    const ledgerId = $(this).data('id');
    const ledgerName = $(this).data('name');
    const groupId = $(this).data('group');
    
    $('#ledger_id').val(ledgerId);
    $('#ledger_name').val(ledgerName);
    $('#ledger_group').val(groupId);
    
    $('#addLedgerModalLabel').text('Edit Ledger');
    $('#addLedgerModal').modal('show');
  });
  
  // Reset form when modal is closed
  $('#addLedgerModal').on('hidden.bs.modal', function () {
    $('#ledgerForm')[0].reset();
    $('#ledger_id').val('');
    $('#addLedgerModalLabel').text('Add New Ledger');
  });
});
</script>
</body>
</html>