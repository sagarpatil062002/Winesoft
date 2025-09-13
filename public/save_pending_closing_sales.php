<?php
// save_pending_closing_sales.php
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
        
        // Save to pending sales table
        foreach ($items as $item_code => $closing_qty) {
            // Insert into pending_sales table
            $query = "INSERT INTO tbl_pending_sales 
                     (item_code, quantity, start_date, end_date, mode, comp_id, user_id, created_at, is_closing_stock) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 1)";
            $stmt = $conn->prepare($query);
            
            // Calculate sale quantity from closing stock
            $item_query = "SELECT CURRENT_STOCK FROM tblitemmaster WHERE CODE = ?";
            $item_stmt = $conn->prepare($item_query);
            $item_stmt->bind_param("s", $item_code);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $item = $item_result->fetch_assoc();
            $item_stmt->close();
            
            if ($item) {
                $sale_qty = $item['CURRENT_STOCK'] - $closing_qty;
                if ($sale_qty > 0) {
                    $stmt->bind_param("sisssii", $item_code, $sale_qty, $start_date, $end_date, $mode, $comp_id, $user_id);
                    $stmt->execute();
                }
            }
            $stmt->close();
        }
        
        $response['success'] = true;
        $response['message'] = 'Closing stock data saved for later posting.';
        
    } catch (Exception $e) {
        $response['message'] = 'Error: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}