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

if (!isset($_GET['id'])) {
    header("Location: dryday.php");
    exit;
}

$id = intval($_GET['id']);
$message = '';
$message_type = '';

// Fetch record
$stmt = $conn->prepare("SELECT id, DATE_FORMAT(DDATE, '%Y-%m-%d') as ddate, DDESC 
                        FROM tblDryDays WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
$dryday = $result->fetch_assoc();
$stmt->close();

if (!$dryday) {
    $_SESSION['message'] = "Dry day not found!";
    $_SESSION['message_type'] = "danger";
    header("Location: dryday.php");
    exit;
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ddate = $_POST['ddate'];
    $ddesc = $_POST['ddesc'];

    $stmt = $conn->prepare("UPDATE tblDryDays SET DDATE = ?, DDESC = ? WHERE id = ?");
    $stmt->bind_param("ssi", $ddate, $ddesc, $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Dry day updated successfully!";
        $_SESSION['message_type'] = "success";
        header("Location: dryday.php");
        exit;
    } else {
        $message = "Error updating dry day: " . $conn->error;
        $message_type = "danger";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Dry Day - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .form-date {
      max-width: 180px;
    }
    .form-desc {
      min-width: 250px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Modify Dry Day</h3>

      <!-- Display messages -->
      <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
          <?= htmlspecialchars($message) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Edit Form -->
      <div class="card">
        <div class="card-header">
          <h5>Edit Dry Day Details</h5>
        </div>
        <div class="card-body">
          <form method="POST" class="row g-3 align-items-end">
            <div class="col-md-3">
              <label for="ddate" class="form-label">Date</label>
              <input type="date" class="form-control form-date" id="ddate" name="ddate" 
                     value="<?= htmlspecialchars($dryday['ddate']) ?>" required>
            </div>
            <div class="col-md-5">
              <label for="ddesc" class="form-label">Description</label>
              <input type="text" class="form-control form-desc" id="ddesc" name="ddesc" 
                     maxlength="25" value="<?= htmlspecialchars($dryday['DDESC']) ?>" required>
            </div>
            <div class="col-md-2">
              <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-save"></i> Save Changes
              </button>
            </div>
            <div class="col-md-2">
              <a href="dryday.php" class="btn btn-secondary w-100">
                <i class="fas fa-times"></i> Cancel
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="mt-3 d-flex gap-2">
        <a href="dryday.php" class="btn btn-secondary">
          <i class="fas fa-arrow-left"></i> Back to List
        </a>
      </div>

    <?php include 'components/footer.php'; ?>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
