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

// Initialize message variables
$message = '';
$message_type = ''; // success, danger, etc.

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_dryday']) || isset($_POST['update_dryday'])) {
        // Add or update dry day
        $ddate = $_POST['ddate'];
        $ddesc = $_POST['ddesc'];
        
        // Check if dry day with same date already exists (for add operation only)
        if (isset($_POST['add_dryday'])) {
            $check_stmt = $conn->prepare("SELECT id FROM tblDryDays WHERE DDATE = ?");
            $check_stmt->bind_param("s", $ddate);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $_SESSION['message'] = "A dry day with this date already exists!";
                $_SESSION['message_type'] = "danger";
                header("Location: dryday.php");
                exit;
            }
            $check_stmt->close();
        }
        
        if (isset($_POST['add_dryday'])) {
            // Add new dry day
            $stmt = $conn->prepare("INSERT INTO tblDryDays (DDATE, DDESC) VALUES (?, ?)");
            $stmt->bind_param("ss", $ddate, $ddesc);
            if ($stmt->execute()) {
                $_SESSION['message'] = "Dry day added successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error adding dry day: " . $conn->error;
                $_SESSION['message_type'] = "danger";
            }
            $stmt->close();
        } elseif (isset($_POST['update_dryday'])) {
            // Update existing dry day
            $id = $_POST['id'];
            
            $stmt = $conn->prepare("UPDATE tblDryDays SET DDATE = ?, DDESC = ? WHERE id = ?");
            $stmt->bind_param("ssi", $ddate, $ddesc, $id);
            if ($stmt->execute()) {
                $_SESSION['message'] = "Dry day updated successfully!";
                $_SESSION['message_type'] = "success";
            } else {
                $_SESSION['message'] = "Error updating dry day: " . $conn->error;
                $_SESSION['message_type'] = "danger";
            }
            $stmt->close();
        }
        
        // Redirect to prevent form resubmission
        header("Location: dryday.php");
        exit;
    }
} elseif (isset($_GET['delete'])) {
    // Delete dry day
    $id = $_GET['delete'];
    
    $stmt = $conn->prepare("DELETE FROM tblDryDays WHERE id = ?");
    $stmt->bind_param("i", $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = "Dry day deleted successfully!";
        $_SESSION['message_type'] = "success";
    } else {
        $_SESSION['message'] = "Error deleting dry day: " . $conn->error;
        $_SESSION['message_type'] = "danger";
    }
    $stmt->close();
    
    header("Location: dryday.php");
    exit;
}

// Check for messages from redirects
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}

// Fetch all dry days from database
$query = "SELECT id, DATE_FORMAT(DDATE, '%d/%m/%Y') as formatted_date, DDESC FROM tblDryDays ORDER BY DDATE";
$result = $conn->query($query);
$drydays = $result->fetch_all(MYSQLI_ASSOC);

// Check if we're editing an existing dry day
$edit_mode = false;
$edit_data = [];
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT id, DATE_FORMAT(DDATE, '%Y-%m-%d') as ddate, DDESC FROM tblDryDays WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $edit_result = $stmt->get_result();
    if ($edit_result->num_rows > 0) {
        $edit_data = $edit_result->fetch_assoc();
        $edit_mode = true;
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dry Days Management - WineSoft</title>
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
      <h3 class="mb-4">Dry Days Management</h3>

      <!-- Display messages -->
      <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show" role="alert">
          <?= htmlspecialchars($message) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- Dry Days Form -->
      <div class="card mb-4">
        <div class="card-header">
          <h5><?= $edit_mode ? 'Modify Dry Day' : 'Add New Dry Day' ?></h5>
        </div>
        <div class="card-body">
          <form method="POST" class="row g-3">
            <?php if ($edit_mode): ?>
              <input type="hidden" name="id" value="<?= htmlspecialchars($edit_data['id']) ?>">
            <?php endif; ?>
            <div class="col-md-5">
              <label for="ddate" class="form-label">Date</label>
              <input type="date" class="form-control" id="ddate" name="ddate" 
                     value="<?= $edit_mode ? htmlspecialchars($edit_data['ddate']) : '' ?>" required>
            </div>
            <div class="col-md-5">
              <label for="ddesc" class="form-label">Description</label>
              <input type="text" class="form-control" id="ddesc" name="ddesc" 
                     value="<?= $edit_mode ? htmlspecialchars($edit_data['DDESC']) : '' ?>" required maxlength="25">
            </div>
            <div class="col-md-2 d-flex align-items-end">
              <button type="submit" class="btn btn-primary w-100" name="<?= $edit_mode ? 'update_dryday' : 'add_dryday' ?>">
                <i class="fas fa-<?= $edit_mode ? 'save' : 'plus' ?>"></i> <?= $edit_mode ? 'Update' : 'Add' ?>
              </button>
            </div>
          </form>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2">
       
        <?php if ($edit_mode): ?>
          <a href="dryday.php" class="btn btn-secondary">
            <i class="fas fa-times"></i> Cancel
          </a>
        <?php endif; ?>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Dry Days Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th>Date</th>
              <th>Description</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($drydays)): ?>
            <?php foreach ($drydays as $day): ?>
              <tr>
                <td><?= htmlspecialchars($day['formatted_date']); ?></td>
                <td><?= htmlspecialchars($day['DDESC']); ?></td>
                <td>
                  <a href="edit_dryday.php?id=<?= $day['id'] ?>" class="btn btn-sm btn-primary">
  <i class="fas fa-edit"></i> Modify
</a>
<a href="delete_dryday.php?id=<?= $day['id'] ?>" class="btn btn-sm btn-danger" 
   onclick="return confirm('Are you sure you want to delete this dry day?');">
  <i class="fas fa-trash"></i> Delete
</a>

                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="3" class="text-center text-muted">No dry days found.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>