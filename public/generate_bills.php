<?php
// generate_bills.php
session_start();
include_once "../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_bills'])) {
    $response = ['success' => false, 'message' => '', 'total_amount' => 0];
    
    try {
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $mode = $_POST['mode'];
        $comp_id = $_SESSION['CompID'];
        $user_id = $_SESSION['user_id'];
        $fin_year_id = $_SESSION['FIN_YEAR_ID'];
        
        // Get items data
        $items = $_POST['items'];
        
        // Create date range array
        $begin = new DateTime($start_date);
        $end = new DateTime($end_date);
        $end = $end->modify('+1 day'); // Include end date
        
        $interval = new DateInterval('P1D');
        $date_range = new DatePeriod($begin, $interval, $end);
        
        $date_array = [];
        foreach ($date_range as $date) {
            $date_array[] = $date->format("Y-m-d");
        }
        $days_count = count($date_array);
        
        // Function to distribute sales uniformly
        function distributeSales($total_qty, $days_count) {
            if ($total_qty <= 0 || $days_count <= 0) return array_fill(0, $days_count, 0);
            
            $base_qty = floor($total_qty / $days_count);
            $remainder = $total_qty % $days_count;
            
            $daily_sales = array_fill(0, $days_count, $base_qty);
            
            // Distribute remainder evenly across days
            for ($i = 0; $i < $remainder; $i++) {
                $daily_sales[$i]++;
            }
            
            // Shuffle the distribution to make it look more natural
            shuffle($daily_sales);
            
            return $daily_sales;
        }
        
        // Get next bill number
        function getNextBillNumber($conn) {
            $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader";
            $result = $conn->query($query);
            $row = $result->fetch_assoc();
            return ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
        }
        
        // Function to update daily stock table with proper opening/closing calculations
        function updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id) {
            // Implementation from your existing code
            // ... (same as in your original code)
        }
        
        // Start transaction
        $conn->begin_transaction();
        
        $next_bill = getNextBillNumber($conn);
        $total_amount = 0;
        $processed_items = 0;
        
        foreach ($items as $item_code => $total_qty) {
            $total_qty = intval($total_qty);
            
            if ($total_qty > 0) {
                $processed_items++;
                
                // Limit processing to prevent issues
                if ($processed_items > 1000) {
                    throw new Exception("Too many items with quantities. Maximum allowed is 1000 items.");
                }
                
                // Get item details
                $item_query = "SELECT RPRICE, LIQ_FLAG FROM tblitemmaster WHERE CODE = ?";
                $item_stmt = $conn->prepare($item_query);
                $item_stmt->bind_param("s", $item_code);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item = $item_result->fetch_assoc();
                $item_stmt->close();
                
                if (!$item) {
                    continue; // Skip if item not found
                }
                
                $rate = $item['RPRICE'];
                $item_mode = $item['LIQ_FLAG'];
                
                // Generate distribution
                $daily_sales = distributeSales($total_qty, $days_count);
                
                // Create sales for each day
                foreach ($daily_sales as $index => $qty) {
                    if ($qty <= 0) continue;
                    
                    $sale_date = $date_array[$index];
                    $amount = $qty * $rate;
                    
                    // Generate bill number starting from 1
                    $bill_no = "BL" . $next_bill;
                    $next_bill++;
                    
                    // Insert sale header
                    $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                     VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                    $header_stmt = $conn->prepare($header_query);
                    $header_stmt->bind_param("ssddssi", $bill_no, $sale_date, $amount, $amount, $item_mode, $comp_id, $user_id);
                    $header_stmt->execute();
                    $header_stmt->close();
                    
                    // Insert sale details
                    $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                     VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $detail_stmt = $conn->prepare($detail_query);
                    $detail_stmt->bind_param("ssddssi", $bill_no, $item_code, $qty, $rate, $amount, $item_mode, $comp_id);
                    $detail_stmt->execute();
                    $detail_stmt->close();
                    
                    // Update stock - check if record exists first
                    $current_stock_column = "Current_Stock" . $comp_id;
                    $opening_stock_column = "Opening_Stock" . $comp_id;
                    
                    $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
                    $check_stmt = $conn->prepare($check_stock_query);
                    $check_stmt->bind_param("s", $item_code);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    $stock_exists = $check_result->fetch_assoc()['count'] > 0;
                    $check_stmt->close();
                    
                    if ($stock_exists) {
                        // Update existing stock
                        $stock_query = "UPDATE tblitem_stock SET $current_stock_column = $current_stock_column - ? WHERE ITEM_CODE = ?";
                        $stock_stmt = $conn->prepare($stock_query);
                        $stock_stmt->bind_param("ds", $qty, $item_code);
                        $stock_stmt->execute();
                        $stock_stmt->close();
                    } else {
                        // Insert new stock record
                        $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_stock_column, $current_stock_column) 
                                               VALUES (?, ?, ?, ?)";
                        $insert_stock_stmt = $conn->prepare($insert_stock_query);
                        $current_stock = -$qty; // Negative since we're deducting
                        $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $current_stock, $current_stock);
                        $insert_stock_stmt->execute();
                        $insert_stock_stmt->close();
                    }
                    
                    // Update daily stock table with closing calculation
                    $daily_stock_table = "tbldailystock_" . $comp_id;
                    updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id);
                    
                    $total_amount += $amount;
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = "Sales distributed successfully across the date range!";
        $response['total_amount'] = number_format($total_amount, 2);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $response['message'] = "Error updating sales: " . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}