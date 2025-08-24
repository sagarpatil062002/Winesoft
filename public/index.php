<?php
session_start();
require '../config/db.php';
require 'components/financial_year.php'; // Include the financial year module

// Initialize variables
$error = '';
$companies = [];
$financial_years = [];
$filtered_years = [];

// Get all companies
$companyResult = mysqli_query($conn, "SELECT CompID, COMP_NAME, FIN_YEAR FROM tblCompany ORDER BY COMP_NAME");
while($company = mysqli_fetch_assoc($companyResult)) {
    $companies[] = $company;
}

// Get all financial years with start and end dates
$yearResult = mysqli_query($conn, "
    SELECT ID, START_DATE, END_DATE 
    FROM tblfinyear 
    ORDER BY START_DATE DESC
");
while($year = mysqli_fetch_assoc($yearResult)) {
    $financial_years[$year['ID']] = $year;
}

// If a company is selected, filter financial years
if(isset($_POST['company']) && !empty($_POST['company'])) {
    $selected_company_id = intval($_POST['company']);
    
    // Find the company's financial year
    foreach($companies as $company) {
        if($company['CompID'] == $selected_company_id) {
            $company_fin_year = $company['FIN_YEAR'];
            break;
        }
    }
    
    // Add only the company's financial year to filtered_years
    if(isset($company_fin_year) && isset($financial_years[$company_fin_year])) {
        $filtered_years[] = array(
            'ID' => $company_fin_year,
            'START_DATE' => $financial_years[$company_fin_year]['START_DATE'],
            'END_DATE' => $financial_years[$company_fin_year]['END_DATE']
        );
    }
} else {
    // If no company selected, show all financial years
    $filtered_years = array_values($financial_years);
}

// Handle form submission
if(isset($_POST['login'])){
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];
    $company_id = intval($_POST['company']);
    $financial_year_id = intval($_POST['financial_year']);
    
    // Validate inputs
    if(empty($username) || empty($password) || empty($company_id) || empty($financial_year_id)) {
        $error = "All fields are required";
    } else {
        // Check if user exists and has access to the selected company
        $user_query = "SELECT * FROM users WHERE username='$username' AND company_id=$company_id";
        $user_result = mysqli_query($conn, $user_query);
        
        if(mysqli_num_rows($user_result) == 1){
            $user = mysqli_fetch_assoc($user_result);
            
            // Verify password
            if(password_verify($password, $user['password'])){
                // Get the financial year details for the selected ID
                $year_query = "SELECT ID, START_DATE, END_DATE FROM tblfinyear WHERE ID='$financial_year_id'";
                $year_result = mysqli_query($conn, $year_query);
                $year_data = mysqli_fetch_assoc($year_result);
                
                // User has access to this company - set session variables
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['CompID'] = $company_id;
                $_SESSION['FIN_YEAR_ID'] = $year_data['ID'];
                $_SESSION['FIN_YEAR_START'] = $year_data['START_DATE'];
                $_SESSION['FIN_YEAR_END'] = $year_data['END_DATE'];
                
                // Format financial year for display (e.g., "2023-2024")
                $start_year = date('Y', strtotime($year_data['START_DATE']));
                $end_year = date('Y', strtotime($year_data['END_DATE']));
                $_SESSION['FIN_YEAR_DISPLAY'] = $start_year . '-' . $end_year;
                
                // Get company name
                $comp_query = "SELECT COMP_NAME FROM tblCompany WHERE CompID=$company_id";
                $comp_result = mysqli_query($conn, $comp_query);
                $company_data = mysqli_fetch_assoc($comp_result);
                $_SESSION['COMP_NAME'] = $company_data['COMP_NAME'];
                
                // Initialize financial year module in session
                $finYearModule = FinancialYearModule::getInstance();                
                // Redirect to dashboard
                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Invalid password";
            }
        } else {
            $error = "Username not found or user doesn't have access to the selected company";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liquor Inventory & Billing - Login</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2B6CB0;
            --primary-hover: #4299E1;
            --secondary-color: #F6AD55;
            --background-color: #F7FAFC;
            --text-color: #2D3748;
            --light-text: #718096;
            --error-color: #E53E3E;
            --success-color: #38A169;
            --white: #FFFFFF;
            --border-radius: 6px;
            --box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            --transition: all 0.2s ease;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            background-color: var(--background-color);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 450px;
            padding: 2.5rem;
            transition: transform 0.3s ease;
        }
        
        .login-container:hover {
            transform: translateY(-5px);
        }
        
        .logo {
            width: 100px;
            height: auto;
            margin-bottom: 1.5rem;
            display: block;
            margin-left: auto;
            margin-right: auto;
        }
        
        h1 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 1.75rem;
        }
        
        .error {
            color: var(--error-color);
            background-color: #f8d7da;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-size: 0.9rem;
            text-align: center;
        }
        
        .login-form {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }
        
        .form-group {
            position: relative;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-color);
            font-weight: 500;
        }
        
        .form-control, .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(43, 108, 176, 0.2);
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
            color: var(--light-text);
            cursor: pointer;
        }
        
        .btn-login {
            background-color: var(--primary-color);
            color: var(--white);
            border: none;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .btn-login:hover {
            background-color: var(--primary-hover);
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 1.5rem;
            }
            
            h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <img src="/winesoft/public/assets/logo.png" alt="Logo" class="logo">
        <h1>Liquor Inventory & Billing</h1>

        <?php if (!empty($error)): ?>
            <p class="error"><?= $error ?></p>
        <?php endif; ?>

        <form class="login-form" method="POST" action="">
            <div class="form-group">
                <label for="company">Company</label>
                <select name="company" id="company" class="form-select" required onchange="this.form.submit()">
                    <option value="">-- Select Company --</option>
                    <?php foreach($companies as $company): ?>
                        <option value="<?= $company['CompID'] ?>" <?= isset($_POST['company']) && $_POST['company'] == $company['CompID'] ? 'selected' : '' ?>>
                            <?= $company['COMP_NAME'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="financial_year">Financial Year</label>
                <select name="financial_year" id="financial_year" class="form-select" required>
                    <option value="">-- Select Financial Year --</option>
                    <?php foreach($filtered_years as $year): ?>
                        <?php
                        $start_date = date('d M Y', strtotime($year['START_DATE']));
                        $end_date = date('d M Y', strtotime($year['END_DATE']));
                        $display_text = $start_date . ' to ' . $end_date;
                        ?>
                        <option value="<?= $year['ID'] ?>" <?= isset($_POST['financial_year']) && $_POST['financial_year'] == $year['ID'] ? 'selected' : '' ?>>
                            <?= $display_text ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" class="form-control" 
                       placeholder="Enter your username" value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username']) : '' ?>" required>
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-container">
                    <input type="password" name="password" id="password" class="form-control" placeholder="Enter your password" required>
                    <button type="button" class="toggle-password" onclick="togglePassword()">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <button type="submit" name="login" class="btn-login">Login</button>
        </form>
    </div>

    <script>
    function togglePassword() {
        const password = document.getElementById('password');
        const icon = document.querySelector('.toggle-password i');
        if (password.type === 'password') {
            password.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            password.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }
    </script>
</body>
</html>