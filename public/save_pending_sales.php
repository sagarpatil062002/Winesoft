<?php
// save_pending_sales.php
session_start();
include_once "../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pending'])) {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $mode = $_POST['mode'];
        $comp_id = $_SESSION['CompID'];
        $user_id = $_SESSION['user_id'];
        
        // Get items data
        $items = $_POST['items'];
        
        // Save to pending sales table (you'll need to create this table)
        foreach ($items as $item_code => $qty) {
            // Insert into pending_sales table
            $query = "INSERT INTO tbl_pending_sales 
                     (item_code, quantity, start_date, end_date, mode, comp_id, user_id, created_at) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sisssii", $item_code, $qty, $start_date, $end_date, $mode, $comp_id, $user_id);
            $stmt->execute();
            $stmt->close();
        }
        
        $response['success'] = true;
        $response['message'] = 'Sales data saved for later posting. Total items: ' . count($items);
        
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}