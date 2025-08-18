<?php
// Start session to track login messages
session_start();
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
        
        .login-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 420px;
            padding: 2.5rem;
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .login-card:hover {
            transform: translateY(-5px);
        }
        
        .logo {
            width: 100px;
            height: auto;
            margin-bottom: 1.5rem;
        }
        
        .login-card h1 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            font-size: 1.75rem;
        }
        
        .error {
            color: var(--error-color);
            background-color: #f8d7da;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            margin-bottom: 1rem;
            font-size: 0.9rem;
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
        
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: var(--transition);
        }
        
        .form-control:focus {
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
            .login-card {
                padding: 1.5rem;
            }
            
            .login-card h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <img src="/winesoft/public/assets/logo.png" alt="Logo" class="logo">
        <h1>Liquor Inventory & Billing</h1>

        <?php if (isset($_SESSION['error'])): ?>
            <p class="error"><?= $_SESSION['error']; unset($_SESSION['error']); ?></p>
        <?php endif; ?>

        <form class="login-form" action="../backend/login.php" method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" name="username" id="username" class="form-control" placeholder="Enter your username" required>
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
            
            <button type="submit" class="btn-login">Login</button>
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