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
    $imfl_limit = isset($_POST['imfl_limit']) ? floatval($_POST['imfl_limit']) : 0;
    $beer_limit = isset($_POST['beer_limit']) ? floatval($_POST['beer_limit']) : 0;
    $cl_limit = isset($_POST['cl_limit']) ? floatval($_POST['cl_limit']) : 0;
    
    // Tax fields
    $sales_tax_percent = isset($_POST['sales_tax_percent']) ? floatval($_POST['sales_tax_percent']) : 0.00;
    $cl_tax = isset($_POST['cl_tax']) ? floatval($_POST['cl_tax']) : 0.00;
    $imfl_tax = isset($_POST['imfl_tax']) ? floatval($_POST['imfl_tax']) : 0.00;
    $wine_tax = isset($_POST['wine_tax']) ? floatval($_POST['wine_tax']) : 0.00;
    $mid_beer_tax = isset($_POST['mid_beer_tax']) ? floatval($_POST['mid_beer_tax']) : 0.00;
    $strong_beer_tax = isset($_POST['strong_beer_tax']) ? floatval($_POST['strong_beer_tax']) : 0.00;
    $tcs_percent = isset($_POST['tcs_percent']) ? floatval($_POST['tcs_percent']) : 1.00;
    $surcharges_percent = isset($_POST['surcharges_percent']) ? floatval($_POST['surcharges_percent']) : 0.00;
    $educ_cess_percent = isset($_POST['educ_cess_percent']) ? floatval($_POST['educ_cess_percent']) : 0.00;
    $court_fees = isset($_POST['court_fees']) ? floatval($_POST['court_fees']) : 10.00;
    
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
                        IMFLLimit = ?,
                        BEERLimit = ?,
                        CLLimit = ?,
                        sales_tax_percent = ?,
                        cl_tax = ?,
                        imfl_tax = ?,
                        wine_tax = ?,
                        mid_beer_tax = ?,
                        strong_beer_tax = ?,
                        tcs_percent = ?,
                        surcharges_percent = ?,
                        educ_cess_percent = ?,
                        court_fees = ?,
                        UPDATED_AT = CURRENT_TIMESTAMP
                        WHERE CompID = ?";
        
        $stmt = $conn->prepare($update_query);
        // Fixed parameter count: 20 parameters total (19 fields + 1 WHERE clause)
        $stmt->bind_param("sssisddddddddddddddi", 
            $comp_name,       // s
            $cf_line,         // s
            $cs_line,         // s
            $fin_year,        // i
            $comp_addr,       // s
            $comp_flno,       // s
            $imfl_limit,      // d
            $beer_limit,      // d
            $cl_limit,        // d
            $sales_tax_percent, // d
            $cl_tax,          // d
            $imfl_tax,        // d
            $wine_tax,        // d
            $mid_beer_tax,    // d
            $strong_beer_tax, // d
            $tcs_percent,     // d
            $surcharges_percent, // d
            $educ_cess_percent,  // d
            $court_fees,      // d
            $comp_id          // i
        );
        
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
  <title>Company Information - LiqourSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
    <!-- Include shortcuts functionality -->
<script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    .dashboard-container {
      display: flex;
      min-height: 100vh;
      background-color: #f8f9fa;
    }
    .main-content {
      flex: 1;
      display: flex;
      flex-direction: column;
    }
    .content-area {
      flex: 1;
      padding: 20px;
      background-color: white;
      margin: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .card {
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      margin-bottom: 20px;
      border: none;
      border-radius: 8px;
    }
    .card-header {
      background-color: #4e73df;
      color: white;
      border-radius: 8px 8px 0 0 !important;
    }
    .btn-primary {
      background-color: #4e73df;
      border-color: #4e73df;
    }
    .btn-primary:hover {
      background-color: #2e59d9;
      border-color: #2e59d9;
    }
    .form-label {
      font-weight: 500;
    }
    .alert {
      border-radius: 8px;
    }
    .tax-section {
      border-left: 4px solid #28a745;
      padding-left: 15px;
      margin: 20px 0;
    }
    .tax-section h6 {
      color: #28a745;
      margin-bottom: 15px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>

  <div class="main-content">
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
                       value="<?= htmlspecialchars($company['COMP_NAME'] ?? 'Diamond Wine Shop') ?>" required>
              </div>
              <div class="col-md-6">
                <label for="fin_year" class="form-label">Financial Year <span class="text-danger">*</span></label>
                <select class="form-select" id="fin_year" name="fin_year" required>
                  <option value="">Select Financial Year</option>
                  <?php foreach ($fin_years as $id => $year): ?>
                    <option value="<?= $id ?>" <?= ($company['FIN_YEAR'] ?? 1) == $id ? 'selected' : '' ?>>
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
              <textarea class="form-control" id="comp_addr" name="comp_addr" rows="3"><?= htmlspecialchars($company['COMP_ADDR'] ?? 'Vishrambag Sangli') ?></textarea>
            </div>

            <div class="mb-3">
              <label for="comp_flno" class="form-label">FL Number</label>
              <input type="text" class="form-control" id="comp_flno" name="comp_flno" 
                     value="<?= htmlspecialchars($company['COMP_FLNO'] ?? 'FL-II 3') ?>">
            </div>

            <div class="row mb-3">
              <div class="col-md-4">
                <label for="imfl_limit" class="form-label">IMFL Limit</label>
                <input type="number" step="0.01" class="form-control" id="imfl_limit" name="imfl_limit" 
                       value="<?= htmlspecialchars($company['IMFLLimit'] ?? '5000.00') ?>">
              </div>
              <div class="col-md-4">
                <label for="beer_limit" class="form-label">BEER Limit</label>
                <input type="number" step="0.01" class="form-control" id="beer_limit" name="beer_limit" 
                       value="<?= htmlspecialchars($company['BEERLimit'] ?? '3000.00') ?>">
              </div>
              <div class="col-md-4">
                <label for="cl_limit" class="form-label">CL Limit</label>
                <input type="number" step="0.01" class="form-control" id="cl_limit" name="cl_limit" 
                       value="<?= htmlspecialchars($company['CLLimit'] ?? '2000.00') ?>">
              </div>
            </div>

            <!-- Tax Configuration Section -->
            <div class="tax-section">
              <h6><i class="fas fa-percentage me-2"></i>Tax Configuration</h6>
              
              <div class="row mb-3">
                <div class="col-md-3">
                  <label for="sales_tax_percent" class="form-label">Sales Tax %</label>
                  <input type="number" step="0.01" class="form-control" id="sales_tax_percent" name="sales_tax_percent" 
                         value="<?= htmlspecialchars($company['sales_tax_percent'] ?? '0.00') ?>">
                </div>
                <div class="col-md-3">
                  <label for="cl_tax" class="form-label">CL Tax %</label>
                  <input type="number" step="0.01" class="form-control" id="cl_tax" name="cl_tax" 
                         value="<?= htmlspecialchars($company['cl_tax'] ?? '0.00') ?>">
                </div>
                <div class="col-md-3">
                  <label for="imfl_tax" class="form-label">IMFL Tax %</label>
                  <input type="number" step="0.01" class="form-control" id="imfl_tax" name="imfl_tax" 
                         value="<?= htmlspecialchars($company['imfl_tax'] ?? '0.00') ?>">
                </div>
                <div class="col-md-3">
                  <label for="wine_tax" class="form-label">Wine Tax %</label>
                  <input type="number" step="0.01" class="form-control" id="wine_tax" name="wine_tax" 
                         value="<?= htmlspecialchars($company['wine_tax'] ?? '0.00') ?>">
                </div>
              </div>

              <div class="row mb-3">
                <div class="col-md-3">
                  <label for="mid_beer_tax" class="form-label">Mid Beer Tax %</label>
                  <input type="number" step="0.01" class="form-control" id="mid_beer_tax" name="mid_beer_tax" 
                         value="<?= htmlspecialchars($company['mid_beer_tax'] ?? '0.00') ?>">
                </div>
                <div class="col-md-3">
                  <label for="strong_beer_tax" class="form-label">Strong Beer Tax %</label>
                  <input type="number" step="0.01" class="form-control" id="strong_beer_tax" name="strong_beer_tax" 
                         value="<?= htmlspecialchars($company['strong_beer_tax'] ?? '0.00') ?>">
                </div>
                <div class="col-md-3">
                  <label for="tcs_percent" class="form-label">TCS %</label>
                  <input type="number" step="0.01" class="form-control" id="tcs_percent" name="tcs_percent" 
                         value="<?= htmlspecialchars($company['tcs_percent'] ?? '1.00') ?>">
                </div>
                <div class="col-md-3">
                  <label for="court_fees" class="form-label">Court Fees</label>
                  <input type="number" step="0.01" class="form-control" id="court_fees" name="court_fees" 
                         value="<?= htmlspecialchars($company['court_fees'] ?? '10.00') ?>">
                </div>
              </div>

              <div class="row mb-3">
                <div class="col-md-4">
                  <label for="surcharges_percent" class="form-label">Surcharges %</label>
                  <input type="number" step="0.01" class="form-control" id="surcharges_percent" name="surcharges_percent" 
                         value="<?= htmlspecialchars($company['surcharges_percent'] ?? '0.00') ?>">
                </div>
                <div class="col-md-4">
                  <label for="educ_cess_percent" class="form-label">Education Cess %</label>
                  <input type="number" step="0.01" class="form-control" id="educ_cess_percent" name="educ_cess_percent" 
                         value="<?= htmlspecialchars($company['educ_cess_percent'] ?? '0.00') ?>">
                </div>
              </div>
            </div>

            <div class="d-flex gap-2">
              <button type="submit" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Information
              </button>
              <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
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
            <div class="col-md-9"><?= htmlspecialchars($company['COMP_NAME'] ?? 'Diamond Wine Shop') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Financial Year:</div>
            <div class="col-md-9"><?= htmlspecialchars($fin_years[$company['FIN_YEAR']] ?? '2024-2025') ?></div>
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
            <div class="col-md-9"><?= htmlspecialchars($company['COMP_ADDR'] ?? 'Vishrambag Sangli') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">FL Number:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['COMP_FLNO'] ?? 'FL-II 3') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">IMFL Limit:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['IMFLLimit'] ?? '5000.00') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">BEER Limit:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['BEERLimit'] ?? '3000.00') ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">CL Limit:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['CLLimit'] ?? '2000.00') ?></div>
          </div>
          
          <!-- Tax Information Display -->
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Sales Tax:</div>
            <div class="col-md-9"><?= number_format($company['sales_tax_percent'] ?? 0.00, 2) ?>%</div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">CL Tax:</div>
            <div class="col-md-9"><?= number_format($company['cl_tax'] ?? 0.00, 2) ?>%</div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">IMFL Tax:</div>
            <div class="col-md-9"><?= number_format($company['imfl_tax'] ?? 0.00, 2) ?>%</div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Wine Tax:</div>
            <div class="col-md-9"><?= number_format($company['wine_tax'] ?? 0.00, 2) ?>%</div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Mid Beer Tax:</div>
            <div class="col-md-9"><?= number_format($company['mid_beer_tax'] ?? 0.00, 2) ?>%</div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Strong Beer Tax:</div>
            <div class="col-md-9"><?= number_format($company['strong_beer_tax'] ?? 0.00, 2) ?>%</div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">TCS:</div>
            <div class="col-md-9"><?= number_format($company['tcs_percent'] ?? 1.00, 2) ?>%</div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Surcharges:</div>
            <div class="col-md-9"><?= number_format($company['surcharges_percent'] ?? 0.00, 2) ?>%</div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Education Cess:</div>
            <div class="col-md-9"><?= number_format($company['educ_cess_percent'] ?? 0.00, 2) ?>%</div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Court Fees:</div>
            <div class="col-md-9">₹<?= number_format($company['court_fees'] ?? 10.00, 2) ?></div>
          </div>
          <div class="row mb-2">
            <div class="col-md-3 fw-bold">Last Updated:</div>
            <div class="col-md-9"><?= htmlspecialchars($company['UPDATED_AT'] ?? 'Not available') ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>