<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Company</title>
    <style>
        :root {
            --primary-color: #2B6CB0;       /* Dominant blue for headers and primary actions */
            --primary-hover: #4299E1;       /* Lighter blue for hover states */
            --secondary-color: #F6AD55;     /* Warm orange for accents and secondary actions */
            --background-color: #F7FAFC;    /* Very light gray/blue background */
            --text-color: #2D3748;         /* Dark gray for main text */
            --light-text: #718096;         /* Medium gray for secondary text */
            --error-color: #E53E3E;        /* Red for errors/warnings */
            --success-color: #38A169;      /* Green for success states */
            --white: #FFFFFF;              /* Pure white */
            --border-radius: 6px;          /* Moderate rounded corners */
            --box-shadow: 0 1px 3px rgba(0,0,0,0.1); /* Subtle shadow */
            --transition: all 0.2s ease;   /* Smooth transitions */
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--background-color);
            color: var(--text-color);
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .card {
            background-color: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .card-header {
            background-color: var(--primary-color);
            color: var(--white);
            padding: 20px;
            text-align: center;
        }

        .card-header h2 {
            font-weight: 600;
            font-size: 1.8rem;
        }

        .card-body {
            padding: 30px;
        }

        .form-section {
            margin-bottom: 30px;
        }

        .form-section h3 {
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px 15px;
        }

        .form-group {
            flex: 1 0 calc(50% - 20px);
            margin: 0 10px 15px;
            min-width: 250px;
        }

        .form-group.full-width {
            flex: 1 0 calc(100% - 20px);
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }

        .required-field::after {
            content: "*";
            color: var(--error-color);
            margin-left: 4px;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #cbd5e0;
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: var(--transition);
            background-color: var(--white);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.2);
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: var(--primary-color);
            color: var(--white);
            border: none;
            border-radius: var(--border-radius);
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-right: 10px;
        }

        .btn:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: var(--light-text);
        }

        .btn-secondary:hover {
            background-color: #a0aec0;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
            font-weight: 500;
        }

        .alert-success {
            background-color: #c6f6d5;
            color: var(--success-color);
            border: 1px solid #9ae6b4;
        }

        .alert-error {
            background-color: #fed7d7;
            color: var(--error-color);
            border: 1px solid #feb2b2;
        }

        .alert ul {
            margin: 10px 0 0 20px;
        }

        @media (max-width: 768px) {
            .form-group {
                flex: 1 0 calc(100% - 20px);
            }
            
            .card-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2>Create New Company</h2>
            </div>
            <div class="card-body">
                <?php
                // Database connection
                $servername = "localhost";
                $username = "root";
                $password = "";
                $dbname = "winesoft";
                
                $conn = new mysqli($servername, $username, $password, $dbname);
                
                if ($conn->connect_error) {
                    die("Connection failed: " . $conn->connect_error);
                }
                
                // Process form submission
                if ($_SERVER["REQUEST_METHOD"] == "POST") {
                    $company_name = trim($_POST['company_name']);
                    $cf_line = trim($_POST['cf_line']);
                    $cs_line = trim($_POST['cs_line']);
                    $fin_year = intval($_POST['fin_year']);
                    $comp_addr = trim($_POST['comp_addr']);
                    $comp_flno = trim($_POST['comp_flno']);
                    
                    $admin_username = trim($_POST['admin_username']);
                    $admin_password = $_POST['admin_password'];
                    $confirm_password = $_POST['confirm_password'];
                    
                    $errors = [];
                    
                    // Validate inputs
                    if (empty($company_name)) {
                        $errors[] = "Company name is required.";
                    }
                    
                    if (empty($admin_username)) {
                        $errors[] = "Admin username is required.";
                    }
                    
                    if (empty($admin_password)) {
                        $errors[] = "Admin password is required.";
                    } elseif (strlen($admin_password) < 6) {
                        $errors[] = "Password must be at least 6 characters long.";
                    } elseif ($admin_password !== $confirm_password) {
                        $errors[] = "Passwords do not match.";
                    }
                    
                    // Check if username already exists
                    $check_user = $conn->prepare("SELECT id FROM users WHERE username = ?");
                    $check_user->bind_param("s", $admin_username);
                    $check_user->execute();
                    $check_user->store_result();
                    
                    if ($check_user->num_rows > 0) {
                        $errors[] = "Username already exists. Please choose a different username.";
                    }
                    $check_user->close();
                    
                    // If no errors, proceed with creation
                    if (empty($errors)) {
                        $conn->begin_transaction();
                        
                        try {
                            // Insert company
                            $insert_company = $conn->prepare("INSERT INTO tblcompany (COMP_NAME, CF_LINE, CS_LINE, FIN_YEAR, COMP_ADDR, COMP_FLNO) VALUES (?, ?, ?, ?, ?, ?)");
                            $insert_company->bind_param("sssiss", $company_name, $cf_line, $cs_line, $fin_year, $comp_addr, $comp_flno);
                            
                            if ($insert_company->execute()) {
                                $company_id = $insert_company->insert_id;
                                
                                // Hash password
                                $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
                                $is_admin = 1; // This user will be an admin
                                $created_by = 1; // Default admin user ID
                                
                                // Insert admin user
                                $insert_user = $conn->prepare("INSERT INTO users (username, password, company_id, is_admin, created_by) VALUES (?, ?, ?, ?, ?)");
                                $insert_user->bind_param("ssiii", $admin_username, $hashed_password, $company_id, $is_admin, $created_by);
                                
                                if ($insert_user->execute()) {
                                    $conn->commit();
                                    echo '<div class="alert alert-success">Company and admin user created successfully!</div>';
                                } else {
                                    throw new Exception("Error creating admin user: " . $conn->error);
                                }
                                
                                $insert_user->close();
                            } else {
                                throw new Exception("Error creating company: " . $conn->error);
                            }
                            
                            $insert_company->close();
                        } catch (Exception $e) {
                            $conn->rollback();
                            echo '<div class="alert alert-error">Error: ' . $e->getMessage() . '</div>';
                        }
                    } else {
                        // Display errors
                        echo '<div class="alert alert-error"><ul>';
                        foreach ($errors as $error) {
                            echo '<li>' . htmlspecialchars($error) . '</li>';
                        }
                        echo '</ul></div>';
                    }
                }
                
                // Fetch financial years for dropdown
                $fin_years = [];
                $result = $conn->query("SELECT ID, START_DATE, END_DATE FROM tblfinyear WHERE ACTIVE = 1 ORDER BY START_DATE DESC");
                
                if ($result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $fin_years[$row['ID']] = date('Y', strtotime($row['START_DATE'])) . '-' . date('Y', strtotime($row['END_DATE']));
                    }
                }
                ?>
                
                <form method="POST" action="">
                    <div class="form-section">
                        <h3>Company Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="company_name" class="required-field">Company Name</label>
                                <input type="text" id="company_name" name="company_name" required>
                            </div>
                            <div class="form-group">
                                <label for="fin_year" class="required-field">Financial Year</label>
                                <select id="fin_year" name="fin_year" required>
                                    <option value="">Select Financial Year</option>
                                    <?php foreach ($fin_years as $id => $year): ?>
                                        <option value="<?php echo $id; ?>"><?php echo $year; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="cf_line">CF Line</label>
                                <input type="text" id="cf_line" name="cf_line" maxlength="15">
                            </div>
                            <div class="form-group">
                                <label for="cs_line">CS Line</label>
                                <input type="text" id="cs_line" name="cs_line" maxlength="35">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="comp_addr">Company Address</label>
                                <textarea id="comp_addr" name="comp_addr" rows="2" maxlength="100"></textarea>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="comp_flno">FL No.</label>
                                <input type="text" id="comp_flno" name="comp_flno" maxlength="12">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Admin User Account</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="admin_username" class="required-field">Username</label>
                                <input type="text" id="admin_username" name="admin_username" required>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="admin_password" class="required-field">Password</label>
                                <input type="password" id="admin_password" name="admin_password" required>
                            </div>
                            <div class="form-group">
                                <label for="confirm_password" class="required-field">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <button type="submit" class="btn">Create Company & Admin Account</button>
                        <button type="reset" class="btn btn-secondary">Reset Form</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Simple password confirmation validation
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            const password = document.getElementById('admin_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            form.addEventListener('submit', function(e) {
                if (password.value !== confirmPassword.value) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    confirmPassword.focus();
                }
            });
        });
    </script>
</body>
</html>