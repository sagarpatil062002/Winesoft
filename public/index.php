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
<!-- Add version parameter to force cache refresh -->
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>"></head>
<body>

<div class="login-container">
<img src="/winesoft/public/assets/logo.png" alt="Logo" class="logo">
    <h1>Liquor Inventory & Billing</h1>

    <?php if (isset($_SESSION['error'])): ?>
        <p class="error"><?= $_SESSION['error']; unset($_SESSION['error']); ?></p>
    <?php endif; ?>

    <form action="../backend/login.php" method="POST">
        <input type="text" name="username" placeholder="Username" required>
        
        <div class="password-container">
            <input type="password" name="password" placeholder="Password" id="password" required>
            <span class="toggle" onclick="togglePassword()">Show</span>
        </div>
        
        <button type="submit">Login</button>
    </form>

    <p class="hardware-id">Hardware 10 - 1334.8978/2012</p>
</div>

<script>
function togglePassword() {
    let pwd = document.getElementById("password");
    pwd.type = (pwd.type === "password") ? "text" : "password";
}
</script>

</body>
</html>
