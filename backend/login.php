<?php
session_start();
require_once("../config/db.php");

// Get POST values
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    $_SESSION['error'] = "Please fill all fields.";
    header("Location: ../public/index.php");
    exit;
}

// Prepare statement to prevent SQL injection
$sql = "SELECT * FROM users WHERE username = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // Verify password
    if (password_verify($password, $row['password'])) {
        // Set session variables
        $_SESSION['user_id'] = $row['id'];
        $_SESSION['user'] = $row['username'];

        // Redirect to company selection page
        header("Location: ../public/select_company.php");
        exit;
    } else {
        $_SESSION['error'] = "Invalid password.";
    }
} else {
    $_SESSION['error'] = "User not found.";
}

// If login failed, redirect back to login page
header("Location: ../public/index.php");
exit;
?>
