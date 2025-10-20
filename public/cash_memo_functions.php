<?php
// cash_memo_functions.php

// Function to generate cash memo text exactly as shown in image
function generateCashMemoText($companyData, $billData, $billItems, $permitData) {
    $text = "";
    
    // License info - centered
    $license = !empty($companyData['licenseNumber']) ? $companyData['licenseNumber'] : "FL-II 3";
    $text .= str_pad($license, 40, " ", STR_PAD_BOTH) . "\n";
    
    // Shop name and address - centered
    $text .= str_pad($companyData['name'], 40, " ", STR_PAD_BOTH) . "\n";
    $text .= str_pad($companyData['address'], 40, " ", STR_PAD_BOTH) . "\n\n";
    
    // Bill number and date
    $billNoShort = substr($billData['BILL_NO'], -5);
    $billDate = date('d/m/Y', strtotime($billData['BILL_DATE']));
    $text .= "No : " . $billNoShort . str_repeat(" ", 10) . "CASH MEMO" . str_repeat(" ", 10) . "Date: " . $billDate . "\n\n";
    
    // Customer name
    $customerName = 'A.N. PARAB'; // Default
    if (!empty($permitData) && !empty($permitData['DETAILS'])) {
        $customerName = $permitData['DETAILS'];
    } elseif (!empty($billData['CUST_CODE']) && $billData['CUST_CODE'] != 'RETAIL') {
        $customerName = $billData['CUST_CODE'];
    }
    $text .= "Name: " . $customerName . "\n";
    
    // Permit information
    if (!empty($permitData)) {
        $permitNo = $permitData['P_NO'] ?? '';
        $permitPlace = $permitData['PLACE_ISS'] ?? 'SANGLI';
        $permitExpDate = !empty($permitData['P_EXP_DT']) ? date('d/m/Y', strtotime($permitData['P_EXP_DT'])) : '04/11/2026';
        
        $text .= "Permit No.: " . $permitNo . "\n";
        $text .= "Place: " . $permitPlace . str_repeat(" ", 15) . "Exp.Dt.: " . $permitExpDate . "\n";
    }
    $text .= "\n";
    
    // Table header
    $text .= str_pad("Particulars", 30) . str_pad("Qty", 10) . str_pad("Size", 15) . str_pad("Amount", 10) . "\n";
    $text .= str_repeat("-", 65) . "\n";
    
    // Items
    foreach ($billItems as $item) {
        $particulars = substr($item['DETAILS'] ?? '', 0, 30);
        $qty = number_format($item['QTY'], 3);
        $size = substr($item['DETAILS2'] ?? '', 0, 15);
        $amount = number_format($item['AMOUNT'], 2);
        
        $text .= str_pad($particulars, 30);
        $text .= str_pad($qty, 10);
        $text .= str_pad($size, 15);
        $text .= str_pad($amount, 10) . "\n";
    }
    
    $text .= "\n";
    $text .= str_repeat(" ", 45) . "Total: ₹" . number_format($billData['NET_AMOUNT'], 2) . "\n";
    
    return $text;
}

// Function to save complete cash memo data
function saveCompleteCashMemo($conn, $billData, $companyData, $billItems, $permitData, $compID, $userID) {
    $billNo = $billData['BILL_NO'];
    $printDate = date('Y-m-d H:i:s');
    
    // Check if already printed today
    $checkQuery = "SELECT id FROM tbl_cash_memo_prints 
                   WHERE bill_no = ? AND comp_id = ? AND DATE(print_date) = CURDATE()";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("si", $billNo, $compID);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        $checkStmt->close();
        return false; // Already printed today
    }
    $checkStmt->close();
    
    // Prepare data
    $licenseNumber = !empty($companyData['licenseNumber']) ? $companyData['licenseNumber'] : "FL-II 3";
    $shopName = $companyData['name'];
    $shopAddress = $companyData['address'];
    $billDate = $billData['BILL_DATE'];
    
    $customerName = 'A.N. PARAB';
    if (!empty($permitData) && !empty($permitData['DETAILS'])) {
        $customerName = $permitData['DETAILS'];
    } elseif (!empty($billData['CUST_CODE']) && $billData['CUST_CODE'] != 'RETAIL') {
        $customerName = $billData['CUST_CODE'];
    }
    
    $permitNo = $permitData['P_NO'] ?? null;
    $permitPlace = $permitData['PLACE_ISS'] ?? null;
    $permitExpDate = !empty($permitData['P_EXP_DT']) ? $permitData['P_EXP_DT'] : null;
    
    $itemsJson = json_encode($billItems);
    $totalAmount = $billData['NET_AMOUNT'];
    
    // Generate the exact cash memo text
    $cashMemoText = generateCashMemoText($companyData, $billData, $billItems, $permitData);
    
    // Insert complete data
    $insertQuery = "INSERT INTO tbl_cash_memo_prints 
                   (bill_no, comp_id, print_date, printed_by, 
                    license_number, shop_name, shop_address, bill_date, 
                    customer_name, permit_no, permit_place, permit_exp_date,
                    items_json, total_amount, cash_memo_text) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insertStmt = $conn->prepare($insertQuery);
    $insertStmt->bind_param("sisisssssssssds", 
        $billNo, $compID, $printDate, $userID,
        $licenseNumber, $shopName, $shopAddress, $billDate,
        $customerName, $permitNo, $permitPlace, $permitExpDate,
        $itemsJson, $totalAmount, $cashMemoText
    );
    
    $result = $insertStmt->execute();
    $insertStmt->close();
    
    return $result;
}

// Function to generate cash memo for a bill
function generateCashMemoForBill($conn, $bill_no, $comp_id, $user_id) {
    // Get company details
    $companyData = [
        'name' => "DIAMOND WINE SHOP",
        'address' => "Ishvanbag Sangli Tal Hiraj Dist Sangli", 
        'licenseNumber' => ""
    ];
    
    $companyQuery = "SELECT COMP_NAME, COMP_ADDR, COMP_FLNO, CF_LINE, CS_LINE FROM tblcompany WHERE CompID = ?";
    $companyStmt = $conn->prepare($companyQuery);
    $companyStmt->bind_param("i", $comp_id);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    if ($row = $companyResult->fetch_assoc()) {
        $companyData['name'] = $row['COMP_NAME'];
        $companyData['address'] = $row['COMP_ADDR'] ?? $companyData['address'];
        $companyData['licenseNumber'] = $row['COMP_FLNO'] ?? "";
        $addressLine = $row['CF_LINE'] ?? "";
        if (!empty($row['CS_LINE'])) {
            $addressLine .= (!empty($addressLine) ? " " : "") . $row['CS_LINE'];
        }
        if (!empty($addressLine)) {
            $companyData['address'] = $addressLine;
        }
    }
    $companyStmt->close();
    
    // Get bill data
    $billQuery = "SELECT BILL_NO, BILL_DATE, CUST_CODE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG
                  FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
    $billStmt = $conn->prepare($billQuery);
    $billStmt->bind_param("si", $bill_no, $comp_id);
    $billStmt->execute();
    $billResult = $billStmt->get_result();
    
    if ($billResult->num_rows === 0) {
        $billStmt->close();
        return false;
    }
    
    $bill_data = $billResult->fetch_assoc();
    $billStmt->close();
    
    // Get bill items
    $itemsQuery = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, im.DETAILS, im.DETAILS2
                   FROM tblsaledetails sd
                   LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                   WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
    $itemsStmt = $conn->prepare($itemsQuery);
    $itemsStmt->bind_param("si", $bill_no, $comp_id);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    
    $bill_items = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $bill_items[] = $row;
    }
    $itemsStmt->close();
    
    // Get permit data (use random permit)
    $permitQuery = "SELECT P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, DETAILS FROM tblpermit WHERE P_NO IS NOT NULL AND P_NO != ''";
    $permitResult = $conn->query($permitQuery);
    $allPermits = [];
    if ($permitResult) {
        while ($row = $permitResult->fetch_assoc()) {
            $allPermits[] = $row;
        }
    }
    
    $permitData = null;
    if (!empty($allPermits)) {
        $permitData = $allPermits[array_rand($allPermits)];
    }
    
    // Save to cash memo table
    return saveCompleteCashMemo($conn, $bill_data, $companyData, $bill_items, $permitData, $comp_id, $user_id);
}

// Function for automatic generation - IMPROVED RELIABILITY
function autoGenerateCashMemoForBill($conn, $bill_no, $comp_id, $user_id) {
    try {
        // Add transaction safety
        $conn->begin_transaction();

        // Check if bill exists and get bill data
        $check_query = "SELECT BILL_NO, BILL_DATE, TOTAL_AMOUNT, NET_AMOUNT FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("si", $bill_no, $comp_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows === 0) {
            $check_stmt->close();
            $conn->rollback();
            error_log("Bill not found for cash memo generation: " . $bill_no);
            return false;
        }

        $bill_data = $check_result->fetch_assoc();
        $check_stmt->close();

        // Check if cash memo already exists for today
        $today_check = "SELECT id FROM tbl_cash_memo_prints
                       WHERE bill_no = ? AND comp_id = ? AND DATE(print_date) = CURDATE()";
        $today_stmt = $conn->prepare($today_check);
        $today_stmt->bind_param("si", $bill_no, $comp_id);
        $today_stmt->execute();
        $today_result = $today_stmt->get_result();

        if ($today_result->num_rows > 0) {
            $today_stmt->close();
            $conn->rollback();
            error_log("Cash memo already generated today for bill: " . $bill_no);
            return true; // Consider this success since it already exists
        }
        $today_stmt->close();

        // Generate cash memo using existing function
        $result = generateCashMemoForBill($conn, $bill_no, $comp_id, $user_id);

        if ($result) {
            $conn->commit();
            return true;
        } else {
            $conn->rollback();
            return false;
        }

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error auto-generating cash memo for bill " . $bill_no . ": " . $e->getMessage());
        return false;
    }
}

// Function to log cash memo generation
function logCashMemoGeneration($bill_no, $success, $error_message = null) {
    $logFile = '../logs/cash_memo_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'SUCCESS' : 'FAILED';
    $message = "[$timestamp] [$status] Cash memo generation for bill: $bill_no";
    
    if (!$success && $error_message) {
        $message .= " - Error: $error_message";
    }
    
    $message .= PHP_EOL;
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
}
?>