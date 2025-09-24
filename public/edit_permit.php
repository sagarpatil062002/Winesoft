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

$error = '';
$success = '';

if (!isset($_GET['id'])) {
    header("Location: permit_master.php");
    exit;
}

$id = $_GET['id'];

// Fetch permit details
$query = "SELECT ID, CODE, DETAILS, P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, PERMIT_TYPE 
          FROM tblpermit WHERE ID = ? AND PRMT_FLAG = 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$permit = $result->fetch_assoc();
$stmt->close();

if (!$permit) {
    header("Location: permit_master.php");
    exit;
}

// Format the date for the input field
$formatted_issue_date = date('Y-m-d', strtotime($permit['P_ISSDT']));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $details = trim($_POST['details']);
    $p_no = trim($_POST['p_no']);
    $p_issdt = trim($_POST['p_issdt']);
    $permit_type = trim($_POST['permit_type']);
    $place_iss = trim($_POST['place_iss']);
    
    // Calculate expiration date based on permit type
    if ($permit_type === 'LIFETIME') {
        $p_exp_dt = '2099-12-31'; // Far future date for lifetime permits
    } else {
        // For one-year permits, calculate expiration date
        $issdt = new DateTime($p_issdt);
        $issdt->modify('+1 year');
        $p_exp_dt = $issdt->format('Y-m-d');
    }
    
    $query = "UPDATE tblpermit SET DETAILS = ?, P_NO = ?, P_ISSDT = ?, 
              P_EXP_DT = ?, PLACE_ISS = ?, PERMIT_TYPE = ? 
              WHERE ID = ? AND PRMT_FLAG = 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssssssi", $details, $p_no, $p_issdt, $p_exp_dt, $place_iss, $permit_type, $id);
    
    if ($stmt->execute()) {
        // Check if any row was actually updated
        if ($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Permit updated successfully!";
        } else {
            $_SESSION['error_message'] = "No changes made or permit not found.";
        }
        $stmt->close();
        header("Location: permit_master.php");
        exit;
    } else {
        $error = "Error updating permit: " . $conn->error;
        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Permit - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

  
    <div class="content-area">
      <h3 class="mb-4">Edit Permit</h3>

      <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
      <?php endif; ?>
      
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success_message'] ?></div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>
      
      <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error_message'] ?></div>
        <?php unset($_SESSION['error_message']); ?>
      <?php endif; ?>

      <form method="POST" class="needs-validation" novalidate>
        <div class="card">
          <div class="card-body">
            <div class="row mb-3">
              <div class="col-md-6">
                <label for="details" class="form-label">Permit Name *</label>
                <input type="text" class="form-control" id="details" name="details" 
                       value="<?= htmlspecialchars($permit['DETAILS']) ?>" required>
                <div class="invalid-feedback">Please enter permit name.</div>
              </div>
              <div class="col-md-6">
                <label for="p_no" class="form-label">Permit Number *</label>
                <input type="text" class="form-control" id="p_no" name="p_no" 
                       value="<?= htmlspecialchars($permit['P_NO']) ?>" required>
                <div class="invalid-feedback">Please enter permit number.</div>
              </div>
            </div>
            
            <div class="row mb-3">
              <div class="col-md-4">
                <label for="permit_type" class="form-label">Permit Type *</label>
                <select class="form-select" id="permit_type" name="permit_type" required>
                  <option value="ONE_YEAR" <?= ($permit['PERMIT_TYPE'] === 'ONE_YEAR') ? 'selected' : '' ?>>One Year</option>
                  <option value="LIFETIME" <?= ($permit['PERMIT_TYPE'] === 'LIFETIME') ? 'selected' : '' ?>>Lifetime</option>
                </select>
                <div class="invalid-feedback">Please select permit type.</div>
              </div>
              <div class="col-md-4">
                <label for="p_issdt" class="form-label">Issue Date *</label>
                <input type="date" class="form-control" id="p_issdt" name="p_issdt" 
                       value="<?= $formatted_issue_date ?>" required>
                <div class="invalid-feedback">Please select issue date.</div>
              </div>
              <div class="col-md-4">
                <label for="place_iss" class="form-label">Place of Issue</label>
                <input type="text" class="form-control" id="place_iss" name="place_iss" 
                       value="<?= htmlspecialchars($permit['PLACE_ISS']) ?>">
              </div>
            </div>
          </div>
        </div>
        
        <div class="mt-3">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save me-1"></i> Update
          </button>
          <a href="permit_master.php" class="btn btn-secondary">
            <i class="fas fa-times me-1"></i> Cancel
          </a>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include 'components/footer.php'; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Form validation
(function () {
  'use strict'
  var forms = document.querySelectorAll('.needs-validation')
  Array.prototype.slice.call(forms)
    .forEach(function (form) {
      form.addEventListener('submit', function (event) {
        if (!form.checkValidity()) {
          event.preventDefault()
          event.stopPropagation()
        }
        form.classList.add('was-validated')
      }, false)
    })
})()
</script>
</body>
</html>