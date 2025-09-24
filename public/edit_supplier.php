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

// Get supplier code from URL
$code = isset($_GET['code']) ? trim($_GET['code']) : '';

if (empty($code)) {
    header("Location: supplier_master.php");
    exit;
}

// Fetch supplier details
$stmt = $conn->prepare("SELECT * FROM tblsupplier WHERE CODE = ?");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();
$supplier = $result->fetch_assoc();
$stmt->close();

if (!$supplier) {
    header("Location: supplier_master.php");
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $details = isset($_POST['details']) ? trim($_POST['details']) : '';
    $mode = isset($_POST['mode']) ? trim($_POST['mode']) : 'Trader';
    $liq_flag = isset($_POST['liq_flag']) ? trim($_POST['liq_flag']) : 'F';
    $addr1 = isset($_POST['addr1']) ? trim($_POST['addr1']) : '';
    $addr2 = isset($_POST['addr2']) ? trim($_POST['addr2']) : '';
    $pincode = isset($_POST['pincode']) ? trim($_POST['pincode']) : '';
    $sales_tax = isset($_POST['sales_tax']) ? trim($_POST['sales_tax']) : '';
    $obdr = isset($_POST['obdr']) ? (float)$_POST['obdr'] : 0;
    $obcr = isset($_POST['obcr']) ? (float)$_POST['obcr'] : 0;
    $stax_perc = isset($_POST['stax_perc']) ? (float)$_POST['stax_perc'] : 0;
    $tcs_perc = isset($_POST['tcs_perc']) ? (float)$_POST['tcs_perc'] : 0;
    $misc_charg = isset($_POST['misc_charg']) ? (float)$_POST['misc_charg'] : 0;

    // Update supplier in database
    $stmt = $conn->prepare("UPDATE tblsupplier SET 
        DETAILS = ?, MODE = ?, LIQ_FLAG = ?, ADDR1 = ?, ADDR2 = ?, 
        PINCODE = ?, SALES_TAX = ?, OBDR = ?, OBCR = ?, 
        STAX_PERC = ?, TCS_PERC = ?, MISC_CHARG = ?
        WHERE CODE = ?");
    
    $stmt->bind_param("sssssssddddds", 
        $details, $mode, $liq_flag, $addr1, $addr2, 
        $pincode, $sales_tax, $obdr, $obcr, 
        $stax_perc, $tcs_perc, $misc_charg, $code);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Supplier updated successfully!";
        header("Location: supplier_master.php");
        exit;
    } else {
        $error_message = "Error updating supplier: " . $conn->error;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Supplier - WineSoft</title>
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
      <h3 class="mb-4">Edit Supplier: <?= htmlspecialchars($supplier['CODE']) ?></h3>

      <?php if (isset($error_message)): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error_message) ?></div>
      <?php endif; ?>

      <form method="POST" class="card p-3">
        <div class="row">
          <div class="col-md-6">
            <div class="mb-3">
              <label for="code" class="form-label">Supplier Code</label>
              <input type="text" class="form-control" id="code" value="<?= htmlspecialchars($supplier['CODE']) ?>" readonly>
            </div>
            
            <div class="mb-3">
              <label for="details" class="form-label">Supplier Name</label>
              <input type="text" class="form-control" id="details" name="details" 
                     value="<?= htmlspecialchars($supplier['DETAILS']) ?>" required>
            </div>
            
            <div class="mb-3">
              <label for="mode" class="form-label">Mode</label>
              <select class="form-select" id="mode" name="mode">
                <option value="Trader" <?= $supplier['MODE'] === 'Trader' ? 'selected' : '' ?>>Trader</option>
                <option value="Manufacturer" <?= $supplier['MODE'] === 'Manufacturer' ? 'selected' : '' ?>>Manufacturer</option>
                <option value="Distributor" <?= $supplier['MODE'] === 'Distributor' ? 'selected' : '' ?>>Distributor</option>
              </select>
            </div>
            
            <div class="mb-3">
              <label for="liq_flag" class="form-label">Liquor Flag</label>
              <select class="form-select" id="liq_flag" name="liq_flag">
                <option value="F" <?= $supplier['LIQ_FLAG'] === 'F' ? 'selected' : '' ?>>Foreign Liquor</option>
                <option value="C" <?= $supplier['LIQ_FLAG'] === 'C' ? 'selected' : '' ?>>Country Liquor</option>
                <option value="O" <?= $supplier['LIQ_FLAG'] === 'O' ? 'selected' : '' ?>>Other</option>
              </select>
            </div>
            
            <div class="mb-3">
              <label for="addr1" class="form-label">Address Line 1</label>
              <input type="text" class="form-control" id="addr1" name="addr1" 
                     value="<?= htmlspecialchars($supplier['ADDR1']) ?>">
            </div>
          </div>
          
          <div class="col-md-6">
            <div class="mb-3">
              <label for="addr2" class="form-label">Address Line 2</label>
              <input type="text" class="form-control" id="addr2" name="addr2" 
                     value="<?= htmlspecialchars($supplier['ADDR2']) ?>">
            </div>
            
            <div class="mb-3">
              <label for="pincode" class="form-label">Pincode</label>
              <input type="text" class="form-control" id="pincode" name="pincode" 
                     value="<?= htmlspecialchars($supplier['PINCODE']) ?>">
            </div>
            
            <div class="mb-3">
              <label for="sales_tax" class="form-label">Sales Tax</label>
              <input type="text" class="form-control" id="sales_tax" name="sales_tax" 
                     value="<?= htmlspecialchars($supplier['SALES_TAX']) ?>">
            </div>
            
            <div class="mb-3">
              <label for="obdr" class="form-label">Opening Balance (Debit)</label>
              <input type="number" step="0.01" class="form-control" id="obdr" name="obdr" 
                     value="<?= htmlspecialchars($supplier['OBDR']) ?>">
            </div>
            
            <div class="mb-3">
              <label for="obcr" class="form-label">Opening Balance (Credit)</label>
              <input type="number" step="0.01" class="form-control" id="obcr" name="obcr" 
                     value="<?= htmlspecialchars($supplier['OBCR']) ?>">
            </div>
          </div>
        </div>
        
        <div class="row">
          <div class="col-md-4">
            <div class="mb-3">
              <label for="stax_perc" class="form-label">Sales Tax Percentage</label>
              <input type="number" step="0.01" class="form-control" id="stax_perc" name="stax_perc" 
                     value="<?= htmlspecialchars($supplier['STAX_PERC']) ?>">
            </div>
          </div>
          
          <div class="col-md-4">
            <div class="mb-3">
              <label for="tcs_perc" class="form-label">TCS Percentage</label>
              <input type="number" step="0.01" class="form-control" id="tcs_perc" name="tcs_perc" 
                     value="<?= htmlspecialchars($supplier['TCS_PERC']) ?>">
            </div>
          </div>
          
          <div class="col-md-4">
            <div class="mb-3">
              <label for="misc_charg" class="form-label">Miscellaneous Charges</label>
              <input type="number" step="0.01" class="form-control" id="misc_charg" name="misc_charg" 
                     value="<?= htmlspecialchars($supplier['MISC_CHARG']) ?>">
            </div>
          </div>
        </div>
        
        <div class="action-btn mt-4 d-flex gap-2">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Changes
          </button>
          <a href="supplier_master.php" class="btn btn-secondary">
            <i class="fas fa-times"></i> Back to Supplier Master
          </a>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>