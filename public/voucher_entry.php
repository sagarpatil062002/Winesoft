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

// Initialize variables
$voucher_no = "";
$voucher_date = date('Y-m-d');
$mode = "C";
$amount = 0.00;
$parti = "";
$narr = "";
$cheq_no = "";
$cheq_dt = "";
$edit_mode = false;
$voucher_id = null;

// Check if we're editing an existing voucher
if (isset($_GET['action']) && ($_GET['action'] === 'edit' || $_GET['action'] === 'view') && isset($_GET['id'])) {
    $voucher_id = intval($_GET['id']);
    $query = "SELECT VNO, VDATE, PARTI, AMOUNT, DRCR, MODE, NARR, CHEQ_NO, CHEQ_DT 
              FROM tblExpenses 
              WHERE VNO = ? AND COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $voucher_id, $_SESSION['CompID']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $voucher = $result->fetch_assoc();
        $voucher_no = $voucher['VNO'];
        $voucher_date = $voucher['VDATE'];
        $mode = $voucher['MODE'];
        $amount = $voucher['AMOUNT'];
        $parti = $voucher['PARTI'];
        $narr = $voucher['NARR'];
        $cheq_no = $voucher['CHEQ_NO'];
        $cheq_dt = $voucher['CHEQ_DT'];
        $edit_mode = ($_GET['action'] === 'edit');
    } else {
        header("Location: voucher_view.php");
        exit;
    }
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $voucher_no = $_POST['voucher_no'] ?? '';
    $voucher_date = $_POST['voucher_date'] ?? date('Y-m-d');
    $mode = $_POST['mode'] ?? 'C';
    $amount = $_POST['amount'] ?? 0.00;
    $parti = $_POST['parti'] ?? '';
    $narr = $_POST['narr'] ?? '';
    $cheq_no = $_POST['cheq_no'] ?? '';
    $cheq_dt = $_POST['cheq_dt'] ?? '';
    
    if ($edit_mode && $voucher_id) {
        // Update existing voucher
        $query = "UPDATE tblExpenses 
                  SET VDATE = ?, PARTI = ?, AMOUNT = ?, MODE = ?, NARR = ?, CHEQ_NO = ?, CHEQ_DT = ?
                  WHERE VNO = ? AND COMP_ID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ssdssssii", $voucher_date, $parti, $amount, $mode, $narr, $cheq_no, $cheq_dt, $voucher_id, $_SESSION['CompID']);
    } else {
        // Insert new voucher
        $query = "INSERT INTO tblExpenses (VDATE, PARTI, AMOUNT, DRCR, MODE, NARR, CHEQ_NO, CHEQ_DT, COMP_ID) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $drcr = 'D';
        $stmt->bind_param("ssdsssssi", $voucher_date, $parti, $amount, $drcr, $mode, $narr, $cheq_no, $cheq_dt, $_SESSION['CompID']);
    }
    
    if ($stmt->execute()) {
        if (!$edit_mode) {
            $voucher_id = $conn->insert_id;
        }
        $success_message = "Voucher " . ($edit_mode ? "updated" : "saved") . " successfully!";
    } else {
        $error_message = "Error saving voucher: " . $conn->error;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $edit_mode ? 'Edit' : 'Create' ?> Voucher Entry - WineSoft</title>
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
      <h3 class="mb-4"><?= $edit_mode ? 'Edit' : 'Create' ?> Voucher Entry</h3>

      <!-- Success/Error Messages -->
      <?php if (isset($success_message)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <!-- Voucher Entry Form -->
      <form method="POST" class="voucher-form">
        <div class="card mb-3">
          <div class="card-header">
            <h5 class="mb-0">Voucher Details</h5>
          </div>
          <div class="card-body">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Voucher No</label>
                <input type="text" class="form-control" name="voucher_no" value="<?= $voucher_no ?>" <?= $edit_mode ? 'readonly' : '' ?> required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Voucher Date</label>
                <input type="date" class="form-control" name="voucher_date" value="<?= $voucher_date ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Mode</label>
                <select class="form-select" name="mode" id="voucher-mode" required>
                  <option value="C" <?= $mode === 'C' ? 'selected' : '' ?>>Cash</option>
                  <option value="B" <?= $mode === 'B' ? 'selected' : '' ?>>Bank</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Amount (₹)</label>
                <input type="number" class="form-control" name="amount" value="<?= $amount ?>" step="0.01" required>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label class="form-label">Particulars</label>
                <input type="text" class="form-control" name="parti" value="<?= $parti ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label">Narration</label>
                <textarea class="form-control" name="narr" rows="1"><?= $narr ?></textarea>
              </div>
            </div>

            <!-- Bank-related fields -->
            <div class="row mb-3 bank-fields" style="display: <?= $mode === 'B' ? 'flex' : 'none' ?>;">
              <div class="col-md-6">
                <label class="form-label">Cheque No</label>
                <input type="text" class="form-control" name="cheq_no" value="<?= $cheq_no ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Cheque Date</label>
                <input type="date" class="form-control" name="cheq_dt" value="<?= $cheq_dt ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-btn mb-3 d-flex gap-2">
          <?php if (!$edit_mode || (isset($_GET['action']) && $_GET['action'] === 'edit')): ?>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> <?= $edit_mode ? 'Update' : 'Save' ?>
          </button>
          <?php endif; ?>
          
          <a href="voucher_view.php" class="btn btn-info">
            <i class="fas fa-list"></i> View All
          </a>
          
          <?php if (isset($_GET['action']) && $_GET['action'] === 'view'): ?>
          <a href="voucher_entry.php?action=edit&id=<?= $voucher_id ?>" class="btn btn-warning">
            <i class="fas fa-edit"></i> Edit
          </a>
          <?php endif; ?>
          
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Show/hide bank-related fields based on mode selection
$(document).ready(function() {
    $('#voucher-mode').change(function() {
        if ($(this).val() === 'B') {
            $('.bank-fields').show();
        } else {
            $('.bank-fields').hide();
        }
    });
    
    // Auto-generate voucher number if creating new voucher
    <?php if (!$edit_mode && empty($voucher_no)): ?>
    function generateVoucherNumber() {
        const date = new Date();
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        const random = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
        
        return `VCH-${year}${month}${day}-${random}`;
    }
    
    $('input[name="voucher_no"]').val(generateVoucherNumber());
    <?php endif; ?>
});
</script>
</body>
</html>