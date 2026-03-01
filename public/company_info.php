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

// Fetch users for this company
$users = [];
$users_query = "SELECT id, username, is_admin, created_at, created_by FROM users WHERE company_id = ? ORDER BY id DESC";
$stmt = $conn->prepare($users_query);
$stmt->bind_param("i", $comp_id);
$stmt->execute();
$users_result = $stmt->get_result();
while ($row = $users_result->fetch_assoc()) {
    $users[] = $row;
}
$stmt->close();

// Handle user addition
if (isset($_POST['add_user'])) {
    $new_username = trim($_POST['new_username']);
    $new_password = trim($_POST['new_password']);
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    
    if (empty($new_username) || empty($new_password)) {
        $error_msg = "Username and password are required.";
    } else {
        // Check if username already exists
        $check_query = "SELECT id FROM users WHERE username = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("s", $new_username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_msg = "Username already exists. Please choose a different username.";
        } else {
            // Hash the password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            
            // Insert new user
            $insert_query = "INSERT INTO users (username, password, company_id, is_admin, created_by) VALUES (?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssiii", $new_username, $hashed_password, $comp_id, $is_admin, $_SESSION['user_id']);
            
            if ($insert_stmt->execute()) {
                $success_msg = "User added successfully.";
                // Refresh users list
                $users = [];
                $stmt = $conn->prepare($users_query);
                $stmt->bind_param("i", $comp_id);
                $stmt->execute();
                $users_result = $stmt->get_result();
                while ($row = $users_result->fetch_assoc()) {
                    $users[] = $row;
                }
                $stmt->close();
            } else {
                $error_msg = "Error adding user: " . $conn->error;
            }
            $insert_stmt->close();
        }
        $check_stmt->close();
    }
}

// Handle user deletion
if (isset($_GET['delete_user'])) {
    $user_id = intval($_GET['delete_user']);
    
    // Don't allow deleting yourself
    if ($user_id == $_SESSION['user_id']) {
        $error_msg = "You cannot delete your own account.";
    } else {
        $delete_query = "DELETE FROM users WHERE id = ? AND company_id = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("ii", $user_id, $comp_id);
        
        if ($delete_stmt->execute()) {
            $success_msg = "User deleted successfully.";
            // Refresh users list
            $users = [];
            $stmt = $conn->prepare($users_query);
            $stmt->bind_param("i", $comp_id);
            $stmt->execute();
            $users_result = $stmt->get_result();
            while ($row = $users_result->fetch_assoc()) {
                $users[] = $row;
            }
            $stmt->close();
        } else {
            $error_msg = "Error deleting user: " . $conn->error;
        }
        $delete_stmt->close();
    }
}

// Handle username update
if (isset($_POST['update_username'])) {
    $edit_user_id = intval($_POST['edit_user_id']);
    $new_username = trim($_POST['edit_username']);
    
    if (empty($new_username)) {
        $error_msg = "Username cannot be empty.";
    } else {
        // Check if username already exists (excluding current user)
        $check_query = "SELECT id FROM users WHERE username = ? AND id != ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $new_username, $edit_user_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            $error_msg = "Username already exists. Please choose a different username.";
        } else {
            // Update username
            $update_query = "UPDATE users SET username = ? WHERE id = ? AND company_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sii", $new_username, $edit_user_id, $comp_id);
            
            if ($update_stmt->execute()) {
                $success_msg = "Username updated successfully.";
                // Refresh users list
                $users = [];
                $stmt = $conn->prepare($users_query);
                $stmt->bind_param("i", $comp_id);
                $stmt->execute();
                $users_result = $stmt->get_result();
                while ($row = $users_result->fetch_assoc()) {
                    $users[] = $row;
                }
                $stmt->close();
                
                // If updating own username, update session username if you store it
                if ($edit_user_id == $_SESSION['user_id']) {
                    // Update session username if you store it
                    // $_SESSION['username'] = $new_username;
                }
            } else {
                $error_msg = "Error updating username: " . $conn->error;
            }
            $update_stmt->close();
        }
        $check_stmt->close();
    }
}

// Handle password reset
if (isset($_POST['reset_password'])) {
    $reset_user_id = intval($_POST['reset_user_id']);
    $new_password = trim($_POST['reset_password_value']);
    
    if (empty($new_password)) {
        $error_msg = "New password is required.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $reset_query = "UPDATE users SET password = ? WHERE id = ? AND company_id = ?";
        $reset_stmt = $conn->prepare($reset_query);
        $reset_stmt->bind_param("sii", $hashed_password, $reset_user_id, $comp_id);
        
        if ($reset_stmt->execute()) {
            $success_msg = "Password reset successfully.";
        } else {
            $error_msg = "Error resetting password: " . $conn->error;
        }
        $reset_stmt->close();
    }
}

// Process company info form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_company'])) {
    $comp_name = trim($_POST['comp_name']);
    $fin_year = intval($_POST['fin_year']);
    $comp_addr = trim($_POST['comp_addr']);
    $comp_flno = trim($_POST['comp_flno']);
    $gst_no = trim($_POST['gst_no']);
    $mvat_no = trim($_POST['mvat_no']);
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
                        FIN_YEAR = ?, 
                        COMP_ADDR = ?, 
                        COMP_FLNO = ?,
                        GST_NO = ?,
                        MVAT_NO = ?,
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
        $stmt->bind_param("sissssdddddddddddddi", 
            $comp_name,       // s
            $fin_year,        // i
            $comp_addr,       // s
            $comp_flno,       // s
            $gst_no,          // s
            $mvat_no,         // s
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
      font-weight: 600;
    }
    .btn-primary {
      background-color: #4e73df;
      border-color: #4e73df;
    }
    .btn-primary:hover {
      background-color: #2e59d9;
      border-color: #2e59d9;
    }
    .btn-success {
      background-color: #28a745;
      border-color: #28a745;
    }
    .btn-danger {
      background-color: #dc3545;
      border-color: #dc3545;
    }
    .btn-info {
      background-color: #17a2b8;
      border-color: #17a2b8;
      color: white;
    }
    .btn-info:hover {
      background-color: #138496;
      border-color: #138496;
      color: white;
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
    .users-section {
      border-left: 4px solid #17a2b8;
      padding-left: 15px;
      margin: 20px 0;
    }
    .users-section h6 {
      color: #17a2b8;
      margin-bottom: 15px;
    }
    .table-users {
      font-size: 0.95rem;
    }
    .badge-admin {
      background-color: #4e73df;
      color: white;
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 0.8rem;
    }
    .badge-user {
      background-color: #6c757d;
      color: white;
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 0.8rem;
    }
    .action-btns .btn {
      padding: 0.25rem 0.5rem;
      font-size: 0.875rem;
      margin: 2px;
    }
    .modal-header {
      background-color: #4e73df;
      color: white;
    }
    .username-display {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .username-display i {
      cursor: pointer;
      color: #4e73df;
    }
    .username-display i:hover {
      color: #2e59d9;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <div class="content-area">
      <h3 class="mb-4"><i class="fas fa-building me-2"></i>Company Information</h3>

      <!-- Success/Error Messages -->
      <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <?= $success_msg ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <?php if ($error_msg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <?= $error_msg ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Company Information Form -->
      <form method="POST" class="mb-4">
        <div class="card">
          <div class="card-header">
            <i class="fas fa-edit me-2"></i>Company Details
          </div>
          <div class="card-body">
            <div class="row mb-3">
              <div class="col-md-6">
                <label for="comp_name" class="form-label">Company Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="comp_name" name="comp_name" 
                       value="<?= htmlspecialchars($company['COMP_NAME'] ?? 'Nandanwan') ?>" required>
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

            <div class="mb-3">
              <label for="comp_addr" class="form-label">Company Address</label>
              <textarea class="form-control" id="comp_addr" name="comp_addr" rows="2"><?= htmlspecialchars($company['COMP_ADDR'] ?? 'Sangli') ?></textarea>
            </div>

            <div class="row mb-3">
              <div class="col-md-4">
                <label for="comp_flno" class="form-label">FL Number</label>
                <input type="text" class="form-control" id="comp_flno" name="comp_flno" 
                       value="<?= htmlspecialchars($company['COMP_FLNO'] ?? '0') ?>">
              </div>
              <div class="col-md-4">
                <label for="gst_no" class="form-label">GST Number</label>
                <input type="text" class="form-control" id="gst_no" name="gst_no" 
                       value="<?= htmlspecialchars($company['GST_NO'] ?? '') ?>">
              </div>
              <div class="col-md-4">
                <label for="mvat_no" class="form-label">MVAT Number</label>
                <input type="text" class="form-control" id="mvat_no" name="mvat_no" 
                       value="<?= htmlspecialchars($company['MVAT_NO'] ?? '') ?>">
              </div>
            </div>

            <div class="row mb-3">
              <div class="col-md-4">
                <label for="imfl_limit" class="form-label">IMFL Limit</label>
                <input type="number" step="0.01" class="form-control" id="imfl_limit" name="imfl_limit" 
                       value="<?= htmlspecialchars($company['IMFLLimit'] ?? '1000.00') ?>">
              </div>
              <div class="col-md-4">
                <label for="beer_limit" class="form-label">BEER Limit</label>
                <input type="number" step="0.01" class="form-control" id="beer_limit" name="beer_limit" 
                       value="<?= htmlspecialchars($company['BEERLimit'] ?? '4000.00') ?>">
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
              <button type="submit" name="update_company" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Information
              </button>
              <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
              </a>
            </div>
          </div>
        </div>
      </form>

      <!-- Users Management Section -->
      <div class="card mt-4">
        <div class="card-header">
          <i class="fas fa-users me-2"></i>User Management
        </div>
        <div class="card-body">
          <!-- Add User Form -->
          <button type="button" class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fas fa-plus-circle"></i> Add New User
          </button>

          <!-- Users List -->
          <?php if (empty($users)): ?>
            <div class="alert alert-info">No users found for this company.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover table-users">
                <thead>
                  <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Created At</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($users as $user): ?>
                    <tr>
                      <td><?= $user['id'] ?></td>
                      <td>
                        <div class="username-display">
                          <i class="fas fa-user-circle me-2"></i>
                          <span id="username-<?= $user['id'] ?>"><?= htmlspecialchars($user['username']) ?></span>
                          <?php if ($user['id'] == $_SESSION['user_id']): ?>
                            <span class="badge bg-info">Current User</span>
                          <?php endif; ?>
                          <button type="button" class="btn btn-sm btn-link p-0 ms-2" 
                                  onclick="editUsername(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                            <i class="fas fa-pencil-alt text-primary"></i>
                          </button>
                        </div>
                      </td>
                      <td>
                        <?php if ($user['is_admin']): ?>
                          <span class="badge-admin"><i class="fas fa-crown"></i> Admin</span>
                        <?php else: ?>
                          <span class="badge-user"><i class="fas fa-user"></i> User</span>
                        <?php endif; ?>
                      </td>
                      <td><?= date('d-m-Y H:i', strtotime($user['created_at'])) ?></td>
                      <td class="action-btns">
                        <button type="button" class="btn btn-info btn-sm" 
                                onclick="editUsername(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                          <i class="fas fa-pencil-alt"></i> Edit
                        </button>
                        <button type="button" class="btn btn-warning btn-sm" 
                                onclick="resetPassword(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')">
                          <i class="fas fa-key"></i> Password
                        </button>
                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                          <a href="?delete_user=<?= $user['id'] ?>" 
                             class="btn btn-danger btn-sm" 
                             onclick="return confirm('Are you sure you want to delete this user?')">
                            <i class="fas fa-trash"></i> Delete
                          </a>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <div class="mb-3">
            <label for="new_username" class="form-label">Username <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="new_username" name="new_username" required>
          </div>
          <div class="mb-3">
            <label for="new_password" class="form-label">Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" id="new_password" name="new_password" required>
            <div class="form-text">Minimum 6 characters recommended.</div>
          </div>
          <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" id="is_admin" name="is_admin">
            <label class="form-check-label" for="is_admin">Grant Administrator Privileges</label>
            <div class="form-text">Admins can manage users and access all features.</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="add_user" class="btn btn-success">
            <i class="fas fa-save"></i> Add User
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Username Modal -->
<div class="modal fade" id="editUsernameModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-pencil-alt me-2"></i>Edit Username</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" id="edit_user_id" name="edit_user_id">
          <div class="mb-3">
            <label for="edit_username" class="form-label">New Username <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="edit_username" name="edit_username" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="update_username" class="btn btn-primary">
            <i class="fas fa-save"></i> Update Username
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-key me-2"></i>Reset Password</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <div class="modal-body">
          <input type="hidden" id="reset_user_id" name="reset_user_id">
          <p>Reset password for user: <strong id="reset_username"></strong></p>
          <div class="mb-3">
            <label for="reset_password_value" class="form-label">New Password <span class="text-danger">*</span></label>
            <input type="password" class="form-control" id="reset_password_value" name="reset_password_value" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="reset_password" class="btn btn-warning">
            <i class="fas fa-save"></i> Reset Password
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function editUsername(userId, currentUsername) {
  document.getElementById('edit_user_id').value = userId;
  document.getElementById('edit_username').value = currentUsername;
  
  var editModal = new bootstrap.Modal(document.getElementById('editUsernameModal'));
  editModal.show();
}

function resetPassword(userId, username) {
  document.getElementById('reset_user_id').value = userId;
  document.getElementById('reset_username').textContent = username;
  document.getElementById('reset_password_value').value = '';
  
  var resetModal = new bootstrap.Modal(document.getElementById('resetPasswordModal'));
  resetModal.show();
}
</script>
</body>
</html>