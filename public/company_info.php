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

// Initialize variables
$success_msg = '';
$error_msg = '';

// Fetch company data
$comp_id = $_SESSION['CompID'];
$query = "SELECT * FROM tblcompany WHERE CompID = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $comp_id);
$stmt->execute();
$result = $stmt->get_result();
$company = $result->fetch_assoc();
$stmt->close();

// Fetch financial years for dropdown
$fin_years = [];
$fin_query = "SELECT ID, START_DATE, END_DATE FROM tblfinyear ORDER BY START_DATE DESC";
$fin_result = $conn->query($fin_query);
if ($fin_result) {
    while ($row = $fin_result->fetch_assoc()) {
        $start_year = date('Y', strtotime($row['START_DATE']));
        $end_year = date('Y', strtotime($row['END_DATE']));
        $fin_years[$row['ID']] = $start_year . '-' . $end_year;
    }
} else {
    // Fallback if query fails
    $fin_years = [1 => '2024-2025', 2 => '2023-2024'];
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comp_name = trim($_POST['comp_name']);
    $cf_line = trim($_POST['cf_line']);
    $cs_line = trim($_POST['cs_line']);
    $fin_year = intval($_POST['fin_year']);
    $comp_addr = trim($_POST['comp_addr']);
    $comp_flno = trim($_POST['comp_flno']);
    
    // Validate required fields
    if (empty($comp_name) || empty($fin_year)) {
        $error_msg = "Company name and financial year are required.";
    } else {
        // Update company information
        $update_query = "UPDATE tblcompany SET 
                        COMP_NAME = ?, 
                        CF_LINE = ?, 
                        CS_LINE = ?, 
                        FIN_YEAR = ?, 
                        COMP_ADDR = ?, 
                        COMP_FLNO = ?,
                        UPDATED_AT = CURRENT_TIMESTAMP
                        WHERE CompID = ?";
        
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sssisss", $comp_name, $cf_line, $cs_line, $fin_year, $comp_addr, $comp_flno, $comp_id);
        
        if ($stmt->execute()) {
            $success_msg = "Company information updated successfully.";
            // Refresh company data
            $query = "SELECT * FROM tblcompany WHERE CompID = ?";
            $stmt2 = $conn->prepare($query);
            $stmt2->bind_param("i", $comp_id);
            $stmt2->execute();
            $result = $stmt2->get_result();
            $company = $result->fetch_assoc();
            $stmt2->close();
        } else {
            $error_msg = "Error updating company information: " . $conn->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Company Information - WineSoft</title>
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
      <h3 class="mb-4">Company Information</h3>

      <!-- Success/Error Messages -->
      <?php if ($success_msg): ?>
        <div class="alert alert-success"><?= $success_msg ?></div>
      <?php endif; ?>
      
      <?php if ($error_msg): ?>
        <div class="alert alert-danger"><?= $error_msg ?></div>
      <?php endif; ?>

      <!-- Company Information Form -->
      <form method="POST" class="mb-4">
        <div class="card">
          <div class="card-body">
            <div class="row mb-3">
              <div class="col-md-6">
                <label for="comp_name" class="form-label">Company Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="comp_name" name="comp_name" 
                       value="<?= htmlspecialchars($company['COMP_NAME'] ?? '') ?>" required>
              </div>
              <div class="col-md-6">
                <label for="fin_year" class="form-label">Financial Year <span class="text-danger">*</span></label>
                <select class="form-select" id="fin_year" name="fin_year" required>
                  <option value="">Select Financial Year</option>
                  <?php foreach ($fin_years as $id => $year): ?>
                    <option value="<?= $id ?>" <?= ($company['FIN_YEAR'] ?? '') == $id ? 'selected' : '' ?>>
                      <?= htmlspecialchars($year) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-6">
                <label for="cf_line" class="form-label">CF Line</label>
                <input type="text" class="form-control" id="cf_line" name="cf_line" 
                       value="<?= htmlspecialchars($company['CF_LINE'] ?? '') ?>">
              </div>
              <div class="col-md-6">
                <label for="cs_line" class="form-label">CS Line</label>
                <input type="text" class="form-control" id="cs_line" name="cs_line" 
                       value="<?= htmlspecialchars($company['CS_LINE'] ?? '') ?>">
              </div>
            </div>

            <div class="mb-3">
              <label for="comp_addr" class="form-label">Company Address</label>
              <textarea class="form-control" id="comp_addr" name="comp_addr" rows="3"><?= htmlspecialchars($company['COMP_ADDR'] ?? '') ?></textarea>
            </div>

            <div class="mb-3">
              <label for="comp_flno" class="form-label">FL Number</label>
              <input type="text" class="form-control" id="comp_flno" name="comp_flno" 
                     value="<?= htmlspecialchars($company['COMP_FLNO'] ?? '') ?>">
            </div>

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Information
              </button>
              <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-sign-out-alt"></i> Back to Dashboard
              </a>
            </div>
          </div>
        </div>
      </form>

      <!-- Company Information Display -->
      <div class="card">
        <div class="card-header">
          <h5 class="mb-0">Current Company Information</h5>
        </div>
        <div class="card-body">
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Company Name:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['COMP_NAME'] ?? 'Not set') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Financial Year:</div>
            <div class="col-md-9"><?= htmlspecialchars($fin_years[$company['FIN_YEAR']] ?? 'Not set') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">CF Line:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['CF_LINE'] ?? 'Not set') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">CS Line:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['CS_LINE'] ?? 'Not set') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Address:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['COMP_ADDR'] ?? 'Not set') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">FL Number:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['COMP_FLNO'] ?? 'Not set') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Last Updated:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['UPDATED_AT'] ?? 'Not available') ?></div>
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