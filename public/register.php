<?php
session_start();
require '../config/db.php';

// Check if user is logged in and is an admin
$isAdmin = false;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $adminCheck = mysqli_query($conn, "SELECT is_admin FROM users WHERE id = $user_id");
    if ($adminCheck && mysqli_num_rows($adminCheck) > 0) {
        $userData = mysqli_fetch_assoc($adminCheck);
        $isAdmin = $userData['is_admin'] == 1;
    }
}

// Redirect to login if not admin
if (!$isAdmin) {
    header("Location: index.php");
    exit();
}

// Initialize variables
$error = '';
$success = '';
$companies = [];

// Get all companies
$companyResult = mysqli_query($conn, "SELECT CompID, COMP_NAME FROM tblCompany ORDER BY COMP_NAME");
while($company = mysqli_fetch_assoc($companyResult)) {
    $companies[] = $company;
}

// Handle form submission
if(isset($_POST['register'])){
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $company_id = intval($_POST['company']);
    $is_admin = isset($_POST['is_admin']) ? 1 : 0;
    
    // Validate inputs
    if(empty($username) || empty($password) || empty($confirm_password) || empty($company_id)) {
        $error = "All fields are required";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long";
    } else {
        // Check if username already exists
        $user_check_query = "SELECT * FROM users WHERE username='$username'";
        $result = mysqli_query($conn, $user_check_query);
        
        if(mysqli_num_rows($result) > 0){
            $error = "Username already exists";
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert new user
            $query = "INSERT INTO users (username, password, company_id, is_admin, created_by) 
                      VALUES ('$username', '$hashed_password', $company_id, $is_admin, {$_SESSION['user_id']})";
            
            if(mysqli_query($conn, $query)){
                $success = "User registered successfully!";
                // Clear form
                $_POST = array();
            } else {
                $error = "Error: " . mysqli_error($conn);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register User - Liquor Inventory & Billing</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        
        .register-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 500px;
            padding: 30px;
            text-align: center;
        }
        
        .logo {
            width: 100px;
            margin-bottom: 20px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 24px;
        }
        
        .subtitle {
            color: #666;
            margin-bottom: 25px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #444;
        }
        
        .form-control, .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }
        
        .password-container {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #777;
        }
        
        .admin-check {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .admin-check input {
            width: 18px;
            height: 18px;
        }
        
        .btn-register {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            padding: 12px 20px;
            width: 100%;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .btn-register:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-back {
            display: inline-block;
            margin-top: 15px;
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        
        .btn-back:hover {
            text-decoration: underline;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: left;
            border-left: 4px solid #c62828;
        }
        
        .success {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: left;
            border-left: 4px solid #2e7d32;
        }
        
        .password-strength {
            height: 5px;
            margin-top: 8px;
            border-radius: 3px;
            background: #eee;
            overflow: hidden;
        }
        
        .strength-meter {
            height: 100%;
            width: 0;
            transition: width 0.3s, background 0.3s;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <img src="/winesoft/public/assets/logo.png" alt="Logo" class="logo">
        <h1>Liquor Inventory & Billing</h1>
        <p class="subtitle">Register New User (Admin Only)</p>

        <?php if (!empty($error)): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="success"><?= $success ?></div>
        <?php endif; ?>

        <form class="register-form" method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" class="form-control" 
                       placeholder="Enter username" value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>" required>
            </div>
            
            <div class="form-group">
                <label for="company">Company</label>
                <select name="company" id="company" class="form-select" required>
                    <option value="">-- Select Company --</option>
                    <?php foreach($companies as $company): ?>
                        <option value="<?= $company['CompID'] ?>" <?= isset($_POST['company']) && $_POST['company'] == $company['CompID'] ? 'selected' : '' ?>>
                            <?= $company['COMP_NAME'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-container">
                    <input type="password" name="password" id="password" class="form-control" 
                           placeholder="Enter password (min. 6 characters)" required minlength="6">
                    <button type="button" class="toggle-password" onclick="togglePassword('password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="password-strength">
                    <div class="strength-meter" id="password-strength-meter"></div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="password-container">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" 
                           placeholder="Confirm password" required minlength="6">
                    <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-group">
                <div class="admin-check">
                    <input type="checkbox" name="is_admin" id="is_admin" value="1" <?= isset($_POST['is_admin']) ? 'checked' : '' ?>>
                    <label for="is_admin" style="display: inline; font-weight: normal;">Grant administrator privileges</label>
                </div>
            </div>
            
            <button type="submit" name="register" class="btn-register">Register User</button>
            <a href="dashboard.php" class="btn-back">Back to Dashboard</a>
        </form>
    </div>

    <script>
    function togglePassword(fieldId) {
        const passwordField = document.getElementById(fieldId);
        const icon = passwordField.parentNode.querySelector('.toggle-password i');
        if (passwordField.type === 'password') {
            passwordField.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            passwordField.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
    
    // Password strength indicator
    document.getElementById('password').addEventListener('input', function() {
        const password = this.value;
        const strengthMeter = document.getElementById('password-strength-meter');
        let strength = 0;
        
        if (password.length >= 6) strength += 25;
        if (password.match(/[a-z]+/)) strength += 25;
        if (password.match(/[A-Z]+/)) strength += 25;
        if (password.match(/[0-9]+/)) strength += 25;
        
        strengthMeter.style.width = strength + '%';
        
        if (strength < 50) {
            strengthMeter.style.background = '#f44336';
        } else if (strength < 75) {
            strengthMeter.style.background = '#ff9800';
        } else {
            strengthMeter.style.background = '#4caf50';
        }
    });
    </script>
</body>
</html>