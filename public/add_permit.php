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
        $issdt = new DateTime($p_issdt);
        $issdt->modify('+1 year');
        $p_exp_dt = $issdt->format('Y-m-d');
    }
    
    // Insert without generating a CODE (let it be NULL or empty)
    $query = "INSERT INTO tblpermit (DETAILS, P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, PERMIT_TYPE, PRMT_FLAG) 
              VALUES (?, ?, ?, ?, ?, ?, 1)";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssssss", $details, $p_no, $p_issdt, $p_exp_dt, $place_iss, $permit_type);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Permit added successfully!";
        $stmt->close();
        header("Location: permit_master.php");
        exit;
    } else {
        $error = "Error adding permit: " . $conn->error;
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Permit - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
    <!-- Include shortcuts functionality -->
    <script src="components/shortcuts.js?v=<?= time() ?>"></script>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .content-area {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .form-label {
            font-weight: 500;
        }
        .action-btn {
            margin-top: 20px;
        }
        h3 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .form-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
        }
        .form-section h5 {
            color: #2c3e50;
            margin-bottom: 15px;
        }
        .required-field::after {
            content: " *";
            color: red;
        }
        .custom-dropdown {
            position: relative;
        }
        .custom-dropdown select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            padding-right: 30px;
        }
        .custom-dropdown:after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #6c757d;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    <div class="main-content">

        <div class="content-area">
            <h3 class="mb-4">Add New Permit</h3>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success_message'] ?></div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <form method="POST" class="row g-3" id="add_permit_form">
                <!-- Permit Details Section -->
                <div class="col-12 form-section">
                    <h5>Permit Details</h5>
                    <div class="row g-3">
                        <!-- Permit Name -->
                        <div class="col-md-4">
                            <label for="details" class="form-label required-field">Permit Name</label>
                            <input type="text" class="form-control" id="details" name="details" 
                                   value="<?= isset($_POST['details']) ? htmlspecialchars($_POST['details']) : '' ?>" required>
                            <div class="invalid-feedback">Please enter permit name.</div>
                        </div>

                        <!-- Permit Number -->
                        <div class="col-md-4">
                            <label for="p_no" class="form-label required-field">Permit Number</label>
                            <input type="text" class="form-control" id="p_no" name="p_no" 
                                   value="<?= isset($_POST['p_no']) ? htmlspecialchars($_POST['p_no']) : '' ?>" required>
                            <div class="invalid-feedback">Please enter permit number.</div>
                        </div>

                        <!-- Place of Issue -->
                        <div class="col-md-4">
                            <label for="place_iss" class="form-label">Place of Issue</label>
                            <input type="text" class="form-control" id="place_iss" name="place_iss" 
                                   value="<?= isset($_POST['place_iss']) ? htmlspecialchars($_POST['place_iss']) : '' ?>">
                        </div>
                    </div>
                </div>

                <!-- Permit Type & Dates Section -->
                <div class="col-12 form-section">
                    <h5>Permit Type & Validity</h5>
                    <div class="row g-3">
                        <!-- Permit Type -->
                        <div class="col-md-4">
                            <label for="permit_type" class="form-label required-field">Permit Type</label>
                            <div class="custom-dropdown">
                                <select class="form-select" id="permit_type" name="permit_type" required>
                                    <option value="ONE_YEAR" <?= (isset($_POST['permit_type']) && $_POST['permit_type'] === 'ONE_YEAR') ? 'selected' : 'selected' ?>>One Year</option>
                                    <option value="LIFETIME" <?= (isset($_POST['permit_type']) && $_POST['permit_type'] === 'LIFETIME') ? 'selected' : '' ?>>Lifetime</option>
                                </select>
                            </div>
                            <div class="invalid-feedback">Please select permit type.</div>
                        </div>

                        <!-- Issue Date -->
                        <div class="col-md-4">
                            <label for="p_issdt" class="form-label required-field">Issue Date</label>
                            <input type="date" class="form-control" id="p_issdt" name="p_issdt" 
                                   value="<?= isset($_POST['p_issdt']) ? $_POST['p_issdt'] : date('Y-m-d') ?>" required>
                            <div class="invalid-feedback">Please select issue date.</div>
                        </div>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="col-12 action-btn mb-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add Permit
                    </button>
                    <a href="permit_master.php" class="btn btn-secondary ms-auto">
                        <i class="fas fa-arrow-left"></i> Back to Permit Master
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

// Show loading overlay during form submission
document.getElementById('add_permit_form').addEventListener('submit', function(e) {
    // Validate required fields
    const requiredFields = ['details', 'p_no', 'permit_type', 'p_issdt'];
    for (const fieldId of requiredFields) {
        const field = document.getElementById(fieldId);
        if (!field.value) {
            e.preventDefault();
            alert(`Please fill in the ${field.previousElementSibling.textContent.replace('*', '').trim()} field`);
            field.focus();
            return;
        }
    }
    
    // Show loading overlay
    const loadingOverlay = document.createElement('div');
    loadingOverlay.id = 'loading_overlay';
    loadingOverlay.style.position = 'fixed';
    loadingOverlay.style.top = '0';
    loadingOverlay.style.left = '0';
    loadingOverlay.style.width = '100%';
    loadingOverlay.style.height = '100%';
    loadingOverlay.style.backgroundColor = 'rgba(255,255,255,0.8)';
    loadingOverlay.style.zIndex = '9999';
    loadingOverlay.style.display = 'flex';
    loadingOverlay.style.justifyContent = 'center';
    loadingOverlay.style.alignItems = 'center';
    loadingOverlay.innerHTML = `
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Adding permit, please wait...</p>
        </div>
    `;
    document.body.appendChild(loadingOverlay);
});
</script>
</body>
</html>
