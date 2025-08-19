<?php
session_start();

// Check if user is logged in and has permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";

// Process form data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ledgerId = isset($_POST['ledger_id']) ? (int)$_POST['ledger_id'] : 0;
    $ledgerName = trim($_POST['ledger_name']);
    $groupId = (int)$_POST['ledger_group'];
    
    // Validate inputs
    if (empty($ledgerName) || $groupId <= 0) {
        $_SESSION['error'] = "Please fill all required fields";
        header("Location: ledger_master.php");
        exit;
    }
    
    try {
        if ($ledgerId > 0) {
            // Update existing ledger
            $stmt = $conn->prepare("UPDATE tbllheads SET LHEAD = ?, GCODE = ? WHERE LCODE = ?");
            $stmt->bind_param("sii", $ledgerName, $groupId, $ledgerId);
            $action = "updated";
        } else {
            // Insert new ledger
            $stmt = $conn->prepare("INSERT INTO tbllheads (LHEAD, GCODE) VALUES (?, ?)");
            $stmt->bind_param("si", $ledgerName, $groupId);
            $action = "added";
        }
        
        if ($stmt->execute()) {
            $_SESSION['success'] = "Ledger successfully $action";
        } else {
            $_SESSION['error'] = "Error saving ledger: " . $conn->error;
        }
        
        $stmt->close();
    } catch (Exception $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
    
    header("Location: ledger_master.php");
    exit;
} else {
    header("Location: ledger_master.php");
    exit;
}