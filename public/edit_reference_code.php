<?php
session_start();

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR'])) {
    header("Location: select_company.php");
    exit;
}

include_once "../config/db.php";

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
$code = isset($_GET['code']) ? $_GET['code'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle form submission - only update REF_CODE
    $new_ref_code = $_POST['ref_code'];
    
    $stmt = $conn->prepare("UPDATE tblitemmaster SET REF_CODE = ? WHERE CODE = ?");
    $stmt->bind_param("ss", $new_ref_code, $code);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Reference code updated successfully!";
        header("Location: reference_code_master.php?mode=$mode");
        exit;
    } else {
        $error = "Error updating reference code: " . $conn->error;
    }
    $stmt->close();
}

// Fetch current item data
$stmt = $conn->prepare("SELECT CODE, DETAILS, DETAILS2, REF_CODE FROM tblitemmaster WHERE CODE = ?");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();
$item = $result->fetch_assoc();
$stmt->close();

if (!$item) {
    header("Location: reference_code_master.php?mode=$mode");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Reference Code - WineSoft</title>
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
        <h3 class="mb-4">Edit Reference Code</h3>
        
        <?php if (isset($error)): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <form method="POST">
          <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
          
          <div class="mb-3">
            <label class="form-label">Item Code</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($item['CODE']) ?>" readonly>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Item Description</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($item['DETAILS']) ?>" readonly>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Category</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($item['DETAILS2']) ?>" readonly>
          </div>
          
          <div class="mb-3">
            <label class="form-label">Reference Code</label>
            <input type="text" name="ref_code" class="form-control" value="<?= htmlspecialchars($item['REF_CODE']) ?>" required>
          </div>
          
          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Update Reference Code</button>
            <a href="reference_code.php?mode=<?= $mode ?>" class="btn btn-outline-primary">
              <i class="fas fa-arrow-left"></i> Back to Reference Codes
            </a>
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