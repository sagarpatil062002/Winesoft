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

if (!isset($_GET['id'])) {
    $_SESSION['error_message'] = "No permit ID specified.";
    header("Location: permit_master.php");
    exit;
}

$id = $_GET['id'];

// Verify the permit exists before attempting to delete
$check_query = "SELECT ID FROM tblpermit WHERE ID = ?";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param("i", $id);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows === 0) {
    $_SESSION['error_message'] = "Permit not found.";
    $check_stmt->close();
    header("Location: permit_master.php");
    exit;
}
$check_stmt->close();

// Permanently delete the permit
$query = "DELETE FROM tblpermit WHERE ID = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    // Check if any row was actually deleted
    if ($stmt->affected_rows > 0) {
        $_SESSION['success_message'] = "Permit permanently deleted successfully!";
    } else {
        $_SESSION['error_message'] = "No permit found to delete.";
    }
} else {
    $_SESSION['error_message'] = "Error deleting permit: " . $conn->error;
}

$stmt->close();
header("Location: permit_master.php");
exit;
?>