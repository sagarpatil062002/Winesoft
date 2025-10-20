<?php
// delete_bill.php - Dedicated bill deletion with renumbering
session_start();
include_once "../config/db.php";

if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => ''];
    
    try {
        $bill_no = $_POST['bill_no'];
        $comp_id = $_SESSION['CompID'];
        
        $response = deleteBillWithRenumbering($conn, $bill_no, $comp_id);
        
    } catch (Exception $e) {
        $response['message'] = "Error: " . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Delete bill and renumber subsequent bills
 */
function deleteBillWithRenumbering($conn, $bill_no, $comp_id) {
    $conn->begin_transaction();
    try {
        // Check if bill exists
        if (!billExists($conn, $bill_no, $comp_id)) {
            throw new Exception("Bill not found!");
        }

        // First, reverse the stock changes for the bill being deleted
        reverseSaleStock($conn, $bill_no, $comp_id);

        // 1. Create temp table if not exists
        createTempBillStorageTable($conn);

        // 2. Store subsequent bills in temp table
        storeSubsequentBillsInTemp($conn, $bill_no, $comp_id);

        // 3. Delete the target bill
        deleteBillCompletely($conn, $bill_no, $comp_id);

        // 4. Restore subsequent bills with new numbers (automatically renumbers)
        restoreSubsequentBillsFromTemp($conn, $comp_id);

        $conn->commit();
        return ['success' => true, 'message' => 'Bill deleted and numbering sequence maintained!'];

    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Check if bill exists
 */
function billExists($conn, $bill_no, $comp_id) {
    $sql = "SELECT COUNT(*) as count FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $bill_no, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'] > 0;
}

/**
 * Create temp bill storage table if not exists
 */
function createTempBillStorageTable($conn) {
    $create_sql = "CREATE TABLE IF NOT EXISTS temp_bill_storage (
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
    
    if (!$conn->query($create_sql)) {
        throw new Exception("Failed to create temp table: " . $conn->error);
    }
}

/**
 * Store subsequent bills in temporary table
 */
function storeSubsequentBillsInTemp($conn, $target_bill_no, $comp_id) {
    // Get subsequent bills (bills with higher numbers)
    $subsequent_sql = "SELECT BILL_NO FROM tblsaleheader 
                      WHERE COMP_ID = ? AND BILL_NO > ? 
                      ORDER BY BILL_NO";
    $subsequent_stmt = $conn->prepare($subsequent_sql);
    $subsequent_stmt->bind_param("is", $comp_id, $target_bill_no);
    $subsequent_stmt->execute();
    $subsequent_result = $subsequent_stmt->get_result();
    
    $subsequent_bills_count = 0;
    
    while ($row = $subsequent_result->fetch_assoc()) {
        $bill_no = $row['BILL_NO'];
        $subsequent_bills_count++;
        
        // Get bill header
        $header_sql = "SELECT * FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
        $header_stmt = $conn->prepare($header_sql);
        $header_stmt->bind_param("si", $bill_no, $comp_id);
        $header_stmt->execute();
        $header_result = $header_stmt->get_result();
        $header = $header_result->fetch_assoc();
        $header_stmt->close();
        
        if (!$header) {
            continue; // Skip if header not found
        }
        
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
    
    // If no subsequent bills, we can proceed with just deleting the target bill
    if ($subsequent_bills_count === 0) {
        // Just delete the target bill without any renumbering needed
        deleteBillCompletely($conn, $target_bill_no, $comp_id);
    }
}

/**
 * Restore subsequent bills from temporary table with new numbers
 */
function restoreSubsequentBillsFromTemp($conn, $comp_id) {
    // Check if there are any bills to restore
    $check_sql = "SELECT COUNT(*) as count FROM temp_bill_storage WHERE comp_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param("i", $comp_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_row = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if ($check_row['count'] == 0) {
        return; // No bills to restore
    }
    
    // Get all temp bills ordered by original bill number
    $temp_sql = "SELECT * FROM temp_bill_storage WHERE comp_id = ? ORDER BY original_bill_no";
    $temp_stmt = $conn->prepare($temp_sql);
    $temp_stmt->bind_param("i", $comp_id);
    $temp_stmt->execute();
    $temp_result = $temp_stmt->get_result();
    
    while ($temp_bill = $temp_result->fetch_assoc()) {
        $new_bill_no = getNextBillNumber($conn, $comp_id);
        
        // Restore header with new bill number
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
        
        // Restore items with new bill number
        $items = json_decode($temp_bill['item_data'], true);
        if (is_array($items)) {
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
    // Delete details first (foreign key constraint)
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
 * Reverse stock changes for sale deletion
 */
function reverseSaleStock($conn, $bill_no, $comp_id) {
    // Get sale details before deletion - need LIQ_FLAG for proper joining
    $query = "SELECT sd.ITEM_CODE, sd.QTY, sh.BILL_DATE as StkDate, sh.LIQ_FLAG
              FROM tblsaledetails sd
              INNER JOIN tblsaleheader sh ON sd.BILL_NO = sh.BILL_NO AND sd.LIQ_FLAG = sh.LIQ_FLAG AND sd.COMP_ID = sh.COMP_ID
              WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $bill_no, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $itemCode = $row['ITEM_CODE'];
        $quantity = $row['QTY'];
        $saleDate = $row['StkDate'];
        $liqFlag = $row['LIQ_FLAG'];

        // Reverse daily stock changes using cascading logic
        updateCascadingDailyStock($conn, $itemCode, $saleDate, $comp_id, 'sale', -$quantity);

        // Reverse item stock changes
        reverseSaleItemStock($conn, $itemCode, $quantity, $comp_id);
    }
    $stmt->close();
}

/**
 * Reverse daily stock changes for sale
 */
function reverseSaleDailyStock($conn, $itemCode, $quantity, $saleDate, $comp_id) {
    $dayOfMonth = date('j', strtotime($saleDate));
    $monthYear = date('Y-m', strtotime($saleDate));
    $dailyStockTable = "tbldailystock_" . $comp_id;

    $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";

    // Check if record exists
    $check_query = "SELECT COUNT(*) as count FROM $dailyStockTable
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->fetch_assoc()['count'] > 0;
    $check_stmt->close();

    if ($exists) {
        // Reverse sale: subtract from sales column and add back to closing stock
        $update_query = "UPDATE $dailyStockTable
                        SET $saleColumn = $saleColumn - ?,
                            $closingColumn = $closingColumn + ?
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ddss", $quantity, $quantity, $monthYear, $itemCode);
        $update_stmt->execute();
        $update_stmt->close();

        // Now cascade the changes to subsequent days
        cascadeSaleStockChanges($conn, $itemCode, $monthYear, $dayOfMonth, $dailyStockTable);
    }
}

/**
 * Cascade stock changes to subsequent days for sales
 */
function cascadeSaleStockChanges($conn, $itemCode, $monthYear, $startDay, $dailyStockTable) {
    // Get the new closing stock for the modified day
    $closingColumn = "DAY_" . str_pad($startDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    $query = "SELECT $closingColumn as new_closing FROM $dailyStockTable
              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $monthYear, $itemCode);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $newClosingStock = $row['new_closing'];
    $stmt->close();

    // Get current date to limit updates
    $currentDate = date('Y-m-d');
    $currentDay = date('j', strtotime($currentDate));
    $currentMonthYear = date('Y-m', strtotime($currentDate));

    // Only cascade if we're in the same month
    if ($monthYear === $currentMonthYear) {
        $endDay = min(31, $currentDay); // Don't go beyond current day
    } else {
        $endDay = 31; // For past months, update all days
    }

    // Update all subsequent days' opening stock up to current date
    for ($day = $startDay + 1; $day <= $endDay; $day++) {
        $openColumn = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $closingColumn = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";

        // Check if this day has data
        $check_query = "SELECT COUNT(*) as count FROM information_schema.columns
                       WHERE table_name = '$dailyStockTable' AND column_name = '$openColumn'";
        $check_result = $conn->query($check_query);
        if ($check_result->fetch_assoc()['count'] > 0) {
            // Update opening stock for this day
            $update_query = "UPDATE $dailyStockTable
                            SET $openColumn = ?
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("dss", $newClosingStock, $monthYear, $itemCode);
            $update_stmt->execute();
            $update_stmt->close();

            // Recalculate closing stock for this day
            $saleColumn = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
            $purchaseColumn = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";

            $recalc_query = "UPDATE $dailyStockTable
                            SET $closingColumn = $openColumn + $purchaseColumn - $saleColumn
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $recalc_stmt = $conn->prepare($recalc_query);
            $recalc_stmt->bind_param("ss", $monthYear, $itemCode);
            $recalc_stmt->execute();
            $recalc_stmt->close();

            // Get the new closing stock for next day's opening
            $get_closing_query = "SELECT $closingColumn as closing FROM $dailyStockTable
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $get_stmt = $conn->prepare($get_closing_query);
            $get_stmt->bind_param("ss", $monthYear, $itemCode);
            $get_stmt->execute();
            $get_result = $get_stmt->get_result();
            $closing_row = $get_result->fetch_assoc();
            $newClosingStock = $closing_row['closing'];
            $get_stmt->close();
        }
    }
}

/**
 * Reverse item stock changes for sale
 */
function reverseSaleItemStock($conn, $itemCode, $quantity, $comp_id) {
    $stockColumn = "CURRENT_STOCK" . $comp_id;

    // Add back to current stock (reverse the subtraction)
    $updateItemStockQuery = "UPDATE tblitem_stock
                            SET $stockColumn = $stockColumn + ?
                            WHERE ITEM_CODE = ?";

    $itemStmt = $conn->prepare($updateItemStockQuery);
    $itemStmt->bind_param("ds", $quantity, $itemCode);
    $itemStmt->execute();
    $itemStmt->close();
}

/**
 * Get next bill number
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
?>