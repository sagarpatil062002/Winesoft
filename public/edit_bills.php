<?php
// edit_bills.php - Dedicated bill editor with volume-based splitting
session_start();
include_once "../config/db.php";
include_once "volume_limit_utils.php";

if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    header("Location: index.php");
    exit;
}

// Handle GET request for loading bill data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['bill_no'])) {
    $bill_no = $_GET['bill_no'];
    $comp_id = $_SESSION['CompID'];
    
    $bill_data = getBillData($conn, $bill_no, $comp_id);
    
    if ($bill_data) {
        echo json_encode(['success' => true, 'data' => $bill_data]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Bill not found']);
    }
    exit;
}

// Handle POST request for updating bill
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $bill_no = $_POST['bill_no'];
        $comp_id = $_SESSION['CompID'];
        $user_id = $_SESSION['user_id'];
        $bill_date = $_POST['bill_date'] ?? date('Y-m-d');
        $force_update = isset($_POST['force_update']) && $_POST['force_update'] == '1';
        
        // Get edited items from POST data
        $edited_items = [];
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item) {
                if (!empty($item['code'])) {
                    $edited_items[] = [
                        'code' => $item['code'],
                        'qty' => floatval($item['qty']),
                        'rate' => floatval($item['rate']),
                        'name' => $item['name'] ?? ''
                    ];
                }
            }
        }
        
        // Validate items
        if (empty($edited_items)) {
            throw new Exception("No valid items found in the bill");
        }
        
        // Calculate total volume using existing utilities
        $category_limits = getCategoryLimits($conn, $comp_id);
        $total_volume = 0;
        
        foreach ($edited_items as $item) {
            $size = getItemSize($conn, $item['code'], 'F'); // Assuming Foreign liquor
            $total_volume += ($item['qty'] * $size);
        }
        
        // Check if volume exceeds limit
        $limit = $category_limits['IMFL'] ?? 1000;
        
        if ($total_volume <= $limit || $force_update) {
            // Simple update - volume within limits OR force update requested
            $response = updateBillSimple($conn, $bill_no, $edited_items, $comp_id);
        } else {
            // Complex update - volume exceeds limits, need to split
            $response = updateBillWithSplitting($conn, $bill_no, $bill_date, $edited_items, $comp_id, $user_id);
        }
        
    } catch (Exception $e) {
        $response['message'] = "Error: " . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Get bill data for editing
 */
function getBillData($conn, $bill_no, $comp_id) {
    // Get header info
    $header_sql = "SELECT BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG 
                   FROM tblsaleheader 
                   WHERE BILL_NO = ? AND COMP_ID = ?";
    $header_stmt = $conn->prepare($header_sql);
    $header_stmt->bind_param("si", $bill_no, $comp_id);
    $header_stmt->execute();
    $header_result = $header_stmt->get_result();
    $header = $header_result->fetch_assoc();
    $header_stmt->close();
    
    if (!$header) return null;
    
    // Get items
    $items_sql = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, CASE WHEN im.Print_Name != '' THEN im.Print_Name ELSE im.DETAILS END as item_name
                  FROM tblsaledetails sd
                  JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                  WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
    $items_stmt = $conn->prepare($items_sql);
    $items_stmt->bind_param("si", $bill_no, $comp_id);
    $items_stmt->execute();
    $items_result = $items_stmt->get_result();
    $items = $items_result->fetch_all(MYSQLI_ASSOC);
    $items_stmt->close();
    
    return [
        'header' => $header,
        'items' => $items
    ];
}

/**
 * Simple update when volume is within limits
 */
function updateBillSimple($conn, $bill_no, $edited_items, $comp_id) {
    $conn->begin_transaction();
    try {
        // Delete existing items
        $delete_sql = "DELETE FROM tblsaledetails WHERE BILL_NO = ? AND COMP_ID = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("si", $bill_no, $comp_id);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // Insert updated items
        $total_amount = 0;
        foreach ($edited_items as $item) {
            $amount = $item['qty'] * $item['rate'];
            $insert_sql = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, COMP_ID) 
                           VALUES (?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ssdddi", $bill_no, $item['code'], $item['qty'], $item['rate'], $amount, $comp_id);
            $insert_stmt->execute();
            $insert_stmt->close();
            $total_amount += $amount;
        }
        
        // Update header total
        $update_sql = "UPDATE tblsaleheader SET TOTAL_AMOUNT = ?, NET_AMOUNT = ? WHERE BILL_NO = ? AND COMP_ID = ?";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bind_param("ddsi", $total_amount, $total_amount, $bill_no, $comp_id);
        $update_stmt->execute();
        $update_stmt->close();
        
        $conn->commit();
        return ['success' => true, 'message' => 'Bill updated successfully!'];
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Complex update when volume exceeds limits - requires bill splitting
 */
function updateBillWithSplitting($conn, $original_bill_no, $bill_date, $edited_items, $comp_id, $user_id) {
    $conn->begin_transaction();
    try {
        // Prepare items data for splitting function
        $all_items = [];
        foreach ($edited_items as $item) {
            $category = getItemCategory($conn, $item['code'], 'F');
            $size = getItemSize($conn, $item['code'], 'F');
            
            $all_items[] = [
                'code' => $item['code'],
                'qty' => $item['qty'],
                'rate' => $item['rate'],
                'size' => $size,
                'amount' => $item['qty'] * $item['rate'],
                'name' => $item['name'],
                'category' => $category
            ];
        }
        
        // Get category limits
        $category_limits = getCategoryLimits($conn, $comp_id);
        
        // Split into multiple bills using existing algorithm
        $split_bills = createOptimizedBills($all_items, $category_limits);
        
        if (count($split_bills) === 0) {
            throw new Exception("No bills generated after splitting");
        }
        
        // Store original bill and subsequent bills in temp table
        storeSubsequentBillsInTemp($conn, $original_bill_no, $comp_id);
        
        // Delete original bill completely
        deleteBillCompletely($conn, $original_bill_no, $comp_id);
        
        // Create new bills with sequential numbering
        $created_bills = [];
        foreach ($split_bills as $bill_index => $bill_items) {
            if (!empty($bill_items)) {
                $new_bill_no = getNextBillNumber($conn, $comp_id);
                
                // Create the new bill
                createNewBill($conn, $new_bill_no, $bill_date, $bill_items, 'F', $comp_id, $user_id);
                $created_bills[] = $new_bill_no;
            }
        }
        
        // Restore subsequent bills with new numbers
        restoreSubsequentBillsFromTemp($conn, $comp_id);
        
        $conn->commit();
        return [
            'success' => true, 
            'message' => 'Bill split into ' . count($created_bills) . ' bills due to volume limits!',
            'new_bills' => $created_bills
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Store subsequent bills in temporary table
 */
function storeSubsequentBillsInTemp($conn, $original_bill_no, $comp_id) {
    // Create temp table if not exists
    $create_temp_sql = "CREATE TABLE IF NOT EXISTS temp_bill_storage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        original_bill_no VARCHAR(50),
        bill_no VARCHAR(50),
        bill_date DATE,
        total_amount DECIMAL(10,2),
        discount DECIMAL(10,2),
        net_amount DECIMAL(10,2),
        liq_flag VARCHAR(1),
        comp_id INT,
        created_by INT,
        item_data TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($create_temp_sql);
    
    // Get subsequent bills (bills with higher numbers)
    $subsequent_sql = "SELECT BILL_NO FROM tblsaleheader 
                      WHERE COMP_ID = ? AND BILL_NO > ? 
                      ORDER BY BILL_NO";
    $subsequent_stmt = $conn->prepare($subsequent_sql);
    $subsequent_stmt->bind_param("is", $comp_id, $original_bill_no);
    $subsequent_stmt->execute();
    $subsequent_result = $subsequent_stmt->get_result();
    
    while ($row = $subsequent_result->fetch_assoc()) {
        $bill_no = $row['BILL_NO'];
        
        // Get bill header
        $header_sql = "SELECT * FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
        $header_stmt = $conn->prepare($header_sql);
        $header_stmt->bind_param("si", $bill_no, $comp_id);
        $header_stmt->execute();
        $header_result = $header_stmt->get_result();
        $header = $header_result->fetch_assoc();
        $header_stmt->close();
        
        // Get bill items
        $items_sql = "SELECT * FROM tblsaledetails WHERE BILL_NO = ? AND COMP_ID = ?";
        $items_stmt = $conn->prepare($items_sql);
        $items_stmt->bind_param("si", $bill_no, $comp_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $items = $items_result->fetch_all(MYSQLI_ASSOC);
        $items_stmt->close();
        
        // Store in temp table
        $insert_sql = "INSERT INTO temp_bill_storage 
                      (original_bill_no, bill_no, bill_date, total_amount, discount, net_amount, liq_flag, comp_id, created_by, item_data) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        $item_data_json = json_encode($items);
        $insert_stmt->bind_param("sssddddsis", 
            $bill_no, $header['BILL_NO'], $header['BILL_DATE'], $header['TOTAL_AMOUNT'], 
            $header['DISCOUNT'], $header['NET_AMOUNT'], $header['LIQ_FLAG'], $comp_id, 
            $header['CREATED_BY'], $item_data_json);
        $insert_stmt->execute();
        $insert_stmt->close();
        
        // Delete from main tables
        deleteBillCompletely($conn, $bill_no, $comp_id);
    }
    $subsequent_stmt->close();
}

/**
 * Restore subsequent bills from temporary table
 */
function restoreSubsequentBillsFromTemp($conn, $comp_id) {
    // Get all temp bills ordered by original bill number
    $temp_sql = "SELECT * FROM temp_bill_storage WHERE comp_id = ? ORDER BY original_bill_no";
    $temp_stmt = $conn->prepare($temp_sql);
    $temp_stmt->bind_param("i", $comp_id);
    $temp_stmt->execute();
    $temp_result = $temp_stmt->get_result();
    
    while ($temp_bill = $temp_result->fetch_assoc()) {
        $new_bill_no = getNextBillNumber($conn, $comp_id);
        
        // Restore header
        $header_sql = "INSERT INTO tblsaleheader 
                      (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $header_stmt = $conn->prepare($header_sql);
        $header_stmt->bind_param("ssddddsi", 
            $new_bill_no, $temp_bill['bill_date'], $temp_bill['total_amount'], 
            $temp_bill['discount'], $temp_bill['net_amount'], $temp_bill['liq_flag'], 
            $comp_id, $temp_bill['created_by']);
        $header_stmt->execute();
        $header_stmt->close();
        
        // Restore items
        $items = json_decode($temp_bill['item_data'], true);
        foreach ($items as $item) {
            $detail_sql = "INSERT INTO tblsaledetails 
                          (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)";
            $detail_stmt = $conn->prepare($detail_sql);
            $detail_stmt->bind_param("ssdddsi", 
                $new_bill_no, $item['ITEM_CODE'], $item['QTY'], $item['RATE'], 
                $item['AMOUNT'], $item['LIQ_FLAG'], $comp_id);
            $detail_stmt->execute();
            $detail_stmt->close();
        }
    }
    $temp_stmt->close();
    
    // Clear temp table
    $clear_sql = "DELETE FROM temp_bill_storage WHERE comp_id = ?";
    $clear_stmt = $conn->prepare($clear_sql);
    $clear_stmt->bind_param("i", $comp_id);
    $clear_stmt->execute();
    $clear_stmt->close();
}

/**
 * Completely delete a bill (header + details)
 */
function deleteBillCompletely($conn, $bill_no, $comp_id) {
    // Delete details
    $delete_details_sql = "DELETE FROM tblsaledetails WHERE BILL_NO = ? AND COMP_ID = ?";
    $delete_details_stmt = $conn->prepare($delete_details_sql);
    $delete_details_stmt->bind_param("si", $bill_no, $comp_id);
    $delete_details_stmt->execute();
    $delete_details_stmt->close();
    
    // Delete header
    $delete_header_sql = "DELETE FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
    $delete_header_stmt = $conn->prepare($delete_header_sql);
    $delete_header_stmt->bind_param("si", $bill_no, $comp_id);
    $delete_header_stmt->execute();
    $delete_header_stmt->close();
}

/**
 * Create a new bill
 */
function createNewBill($conn, $bill_no, $bill_date, $bill_items, $mode, $comp_id, $user_id) {
    $total_amount = 0;
    
    foreach ($bill_items as $item) {
        $total_amount += $item['amount'];
    }
    
    // Insert header
    $header_sql = "INSERT INTO tblsaleheader 
                  (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                  VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
    $header_stmt = $conn->prepare($header_sql);
    $header_stmt->bind_param("ssddssi", $bill_no, $bill_date, $total_amount, $total_amount, $mode, $comp_id, $user_id);
    $header_stmt->execute();
    $header_stmt->close();
    
    // Insert details
    foreach ($bill_items as $item) {
        $detail_sql = "INSERT INTO tblsaledetails 
                      (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                      VALUES (?, ?, ?, ?, ?, ?, ?)";
        $detail_stmt = $conn->prepare($detail_sql);
        $detail_stmt->bind_param("ssdddsi", 
            $bill_no, $item['code'], $item['qty'], $item['rate'], $item['amount'], $mode, $comp_id);
        $detail_stmt->execute();
        $detail_stmt->close();
    }
}

/**
 * Get next bill number (from your existing code)
 */
function getNextBillNumber($conn, $comp_id) {
    $sql = "SELECT BILL_NO FROM tblsaleheader 
            WHERE COMP_ID = ? 
            ORDER BY CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED) DESC 
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $nextNumber = 1;
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastBillNo = $row['BILL_NO'];
        
        if (preg_match('/BL(\d+)/', $lastBillNo, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }
    }
    
    $stmt->close();
    
    // Safety check
    $billExists = true;
    $attempts = 0;
    
    while ($billExists && $attempts < 10) {
        $newBillNo = 'BL' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
        
        $checkSql = "SELECT COUNT(*) as count FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->bind_param("si", $newBillNo, $comp_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $checkRow = $checkResult->fetch_assoc();
        
        if ($checkRow['count'] == 0) {
            $billExists = false;
        } else {
            $nextNumber++;
            $attempts++;
        }
        $checkStmt->close();
    }
    
    return 'BL' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
}

/**
 * Get item name
 */
function getItemName($conn, $item_code) {
    $sql = "SELECT CASE WHEN Print_Name != '' THEN Print_Name ELSE DETAILS END as item_name FROM tblitemmaster WHERE CODE = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();

    return $item ? $item['item_name'] : 'Unknown Item';
}
?>