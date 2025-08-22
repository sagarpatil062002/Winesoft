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

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle form submission
    $code = $_POST['code'];
    $ref_code = $_POST['ref_code'];
    $details = $_POST['details'];
    $details2 = $_POST['details2'];
    
    // Check if item code already exists
    $check_stmt = $conn->prepare("SELECT CODE FROM tblitemmaster WHERE CODE = ?");
    $check_stmt->bind_param("s", $code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $error = "Item code already exists!";
    } else {
        $stmt = $conn->prepare("INSERT INTO tblitemmaster (CODE, REF_CODE, DETAILS, DETAILS2, LIQ_FLAG) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssss", $code, $ref_code, $details, $details2, $mode);
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = "Reference code added successfully!";
            header("Location: reference_code_master.php?mode=$mode");
            exit;
        } else {
            $error = "Error adding reference code: " . $conn->error;
        }
        $stmt->close();
    }
    $check_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Add Reference Code - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <div class="card">
        <h3 class="mb-4">Add New Reference Code</h3>
        
        <?php if (isset($error)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
          <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
          
          <div class="mb-3">
            <label class="form-label">Item Code</label>
            <input type="text" name="code" class="form-control" required>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Item Description</label>
            <input type="text" name="details" class="form-control" required>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Category</label>
            <input type="text" name="details2" class="form-control" required>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Reference Code</label>
            <input type="text" name="ref_code" class="form-control" required>
          </div>
          
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Save</button>
            <a href="reference_code_master.php?mode=<?= $mode ?>" class="btn btn-secondary">Cancel</a>
          </div>
        </form>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>