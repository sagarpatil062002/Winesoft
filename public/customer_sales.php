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

include_once "../config/db.php"; // MySQLi connection in $conn

// Initialize variables
$customers = [];
$items = [];
$selectedCustomer = null;
$saleItems = [];
$totalAmount = 0;

// Fetch customers from tbllheads - CORRECTED QUERY
$customerQuery = "SELECT LCODE, LHEAD FROM tbllheads WHERE GCODE=32 ORDER BY LHEAD";
$customerResult = $conn->query($customerQuery);
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $customers[$row['LCODE']] = $row['LHEAD'];
    }
} else {
    echo "Error fetching customers: " . $conn->error;
}

// Handle customer creation and selection in one field
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle customer selection/creation
    if (isset($_POST['customer_field'])) {
        $customerField = trim($_POST['customer_field']);

        if (!empty($customerField)) {
            // Check if it's a new customer (starts with "new:" or doesn't match existing customer codes)
            if (preg_match('/^new:/i', $customerField) || !is_numeric($customerField)) {
                // Extract customer name (remove "new:" prefix if present)
                $customerName = preg_replace('/^new:\s*/i', '', $customerField);

                if (!empty($customerName)) {
                    // Get the next available LCODE for GCODE=32
                    $maxCodeQuery = "SELECT MAX(LCODE) as max_code FROM tbllheads WHERE GCODE=32";
                    $maxResult = $conn->query($maxCodeQuery);
                    $maxCode = 1;
                    if ($maxResult && $maxResult->num_rows > 0) {
                        $maxData = $maxResult->fetch_assoc();
                        $maxCode = $maxData['max_code'] + 1;
                    }

                    // Insert new customer
                    $insertQuery = "INSERT INTO tbllheads (GCODE, LCODE, LHEAD) VALUES (32, ?, ?)";
                    $stmt = $conn->prepare($insertQuery);
                    $stmt->bind_param("is", $maxCode, $customerName);

                    if ($stmt->execute()) {
                        $_SESSION['selected_customer'] = $maxCode;
                        $_SESSION['success_message'] = "Customer '$customerName' created successfully!";

                        // Refresh customers list
                        $customerResult = $conn->query($customerQuery);
                        $customers = [];
                        if ($customerResult) {
                            while ($row = $customerResult->fetch_assoc()) {
                                $customers[$row['LCODE']] = $row['LHEAD'];
                            }
                        }
                    } else {
                        $_SESSION['error_message'] = "Error creating customer: " . $conn->error;
                    }
                    $stmt->close();
                }
            } else {
                // It's an existing customer code
                $customerCode = intval($customerField);
                if (array_key_exists($customerCode, $customers)) {
                    $_SESSION['selected_customer'] = $customerCode;
                    $_SESSION['success_message'] = "Customer selected successfully!";
                } else {
                    $_SESSION['error_message'] = "Invalid customer code!";
                }
            }
        } else {
            // Empty field means walk-in customer
            $_SESSION['selected_customer'] = '';
            $_SESSION['success_message'] = "Walk-in customer selected!";
        }

        // Redirect to avoid form resubmission
        header("Location: customer_sales.php");
        exit;
    }

    // Store customer ID in session to preserve selection
    if (isset($_POST['customer_id'])) {
        $_SESSION['selected_customer'] = $_POST['customer_id'];
    }
    
    // Add item to sale
    if (isset($_POST['add_item'])) {
        $itemCode = trim($_POST['item_code']);
        $quantity = (int)$_POST['quantity'];
        
        // Validate quantity
        if ($quantity <= 0) {
            $_SESSION['error'] = "Quantity must be greater than zero";
        } else {
            // Get item details
            $itemQuery = "SELECT CODE, DETAILS, DETAILS2, BPRICE FROM tblitemmaster WHERE CODE = ?";
            $stmt = $conn->prepare($itemQuery);
            $stmt->bind_param("s", $itemCode);
            $stmt->execute();
            $itemResult = $stmt->get_result();
            
            if ($itemResult->num_rows > 0) {
                $item = $itemResult->fetch_assoc();
                
                // Get customer-specific price - MODIFIED: Show WPRICE for any customer
                $price = $item['BPRICE']; // Default to base price
                
                // Check if there's any price record for this item (regardless of customer)
                $priceQuery = "SELECT WPrice FROM tblcustomerprices WHERE Code = ? LIMIT 1";
                $priceStmt = $conn->prepare($priceQuery);
                $priceStmt->bind_param("s", $itemCode);
                $priceStmt->execute();
                $priceResult = $priceStmt->get_result();
                
                if ($priceResult->num_rows > 0) {
                    $priceData = $priceResult->fetch_assoc();
                    $price = $priceData['WPrice'];
                }
                
                // Add to session cart
                if (!isset($_SESSION['sale_cart'])) {
                    $_SESSION['sale_cart'] = [];
                }
                
                $itemKey = $itemCode;
                if (isset($_SESSION['sale_cart'][$itemKey])) {
                    $_SESSION['sale_cart'][$itemKey]['quantity'] += $quantity;
                    $_SESSION['sale_cart'][$itemKey]['amount'] = $_SESSION['sale_cart'][$itemKey]['quantity'] * $price;
                } else {
                    $_SESSION['sale_cart'][$itemKey] = [
                        'code' => $itemCode,
                        'name' => $item['DETAILS'],
                        'size' => $item['DETAILS2'],
                        'rate' => $price,
                        'quantity' => $quantity,
                        'amount' => $quantity * $price
                    ];
                }
            } else {
                $_SESSION['error'] = "Invalid item code selected";
            }
        }
    }
    
    // Update item price
    if (isset($_POST['update_price'])) {
        $itemCode = trim($_POST['item_code']);
        $newPrice = (float)$_POST['new_price'];
        
        if (!empty($itemCode) && $newPrice > 0) {
            // Check if price record exists for any customer
            $checkQuery = "SELECT COUNT(*) as count FROM tblcustomerprices WHERE Code = ? LIMIT 1";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("s", $itemCode);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $count = $checkResult->fetch_assoc()['count'];
            
            if ($count > 0) {
                // Update existing price for all customers (or first found)
                $updateQuery = "UPDATE tblcustomerprices SET WPrice = ? WHERE Code = ? LIMIT 1";
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bind_param("ds", $newPrice, $itemCode);
                $updateStmt->execute();
            } else {
                // Insert new price with a default customer ID (0 or first customer)
                $firstCustomerQuery = "SELECT LCODE FROM tbllheads WHERE GCODE=32 ORDER BY LCODE LIMIT 1";
                $firstCustomerResult = $conn->query($firstCustomerQuery);
                $firstCustomer = $firstCustomerResult->fetch_assoc();
                $defaultCustomerId = $firstCustomer['LCODE'] ?? 0;
                
                $insertQuery = "INSERT INTO tblcustomerprices (LCode, Code, WPrice) VALUES (?, ?, ?)";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("isd", $defaultCustomerId, $itemCode, $newPrice);
                $insertStmt->execute();
            }
            
            // Update the price in the cart if the item exists
            if (isset($_SESSION['sale_cart'][$itemCode])) {
                $_SESSION['sale_cart'][$itemCode]['rate'] = $newPrice;
                $_SESSION['sale_cart'][$itemCode]['amount'] = $_SESSION['sale_cart'][$itemCode]['quantity'] * $newPrice;
            }
        }
    }
    
    // Remove item from sale
    if (isset($_POST['remove_item'])) {
        $itemCode = $_POST['remove_item_code'];
        if (isset($_SESSION['sale_cart'][$itemCode])) {
            unset($_SESSION['sale_cart'][$itemCode]);
        }
    }
    
    // Finalize sale
    if (isset($_POST['finalize_sale'])) {
        // Get customer ID from session - this is the key fix
        $customerId = isset($_SESSION['selected_customer']) ? $_SESSION['selected_customer'] : '';
        
        // For walk-in customers, session value will be empty string
        if ($customerId === '') {
            // Walk-in customer is allowed
            $isWalkIn = true;
            $customerIdForDisplay = 0;
            $customerNameForDisplay = 'Walk-in Customer';
        } else {
            // Validate it's a valid customer code
            $customerId = intval($customerId);
            if ($customerId <= 0 || !array_key_exists($customerId, $customers)) {
                $_SESSION['error'] = "Please select a valid customer before finalizing sale";
                header("Location: customer_sales.php");
                exit;
            }
            $isWalkIn = false;
            $customerIdForDisplay = $customerId;
            $customerNameForDisplay = $customers[$customerId];
        }
        
        // Validate cart
        if (!isset($_SESSION['sale_cart']) || empty($_SESSION['sale_cart'])) {
            $_SESSION['error'] = "No items in cart to finalize sale";
            header("Location: customer_sales.php");
            exit;
        }
        
        // ========== NEW: Get next retail bill number ==========
        function getNextBillNumberForCustomerSale($conn, $comp_id) {
            // Get the highest existing bill number numerically FOR THIS COMP_ID
            $sql = "SELECT BILL_NO FROM tblsaleheader 
                    WHERE COMP_ID = ? 
                    ORDER BY CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED) DESC 
                    LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $comp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            $nextNumber = 1; // Default starting number for this company
            
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $lastBillNo = $row['BILL_NO'];
                
                // Extract numeric part and increment
                if (preg_match('/BL(\d+)/', $lastBillNo, $matches)) {
                    $nextNumber = intval($matches[1]) + 1;
                }
            }
            
            $stmt->close();
            return 'BL' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
        }
        
        // ========== NEW: Stock update functions ==========
        function updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id) {
            $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
            $sales_column = "DAY_{$day_num}_SALES";
            $closing_column = "DAY_{$day_num}_CLOSING";
            $opening_column = "DAY_{$day_num}_OPEN";
            $purchase_column = "DAY_{$day_num}_PURCHASE";
            
            $month_year = date('Y-m', strtotime($sale_date));
            
            // Check if record exists
            $check_query = "SELECT COUNT(*) as count FROM $daily_stock_table 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("ss", $month_year, $item_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $exists = $check_result->fetch_assoc()['count'] > 0;
            $check_stmt->close();
            
            if ($exists) {
                // Get current values
                $select_query = "SELECT $opening_column, $purchase_column, $sales_column 
                                 FROM $daily_stock_table 
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $select_stmt = $conn->prepare($select_query);
                $select_stmt->bind_param("ss", $month_year, $item_code);
                $select_stmt->execute();
                $select_result = $select_stmt->get_result();
                $current_values = $select_result->fetch_assoc();
                $select_stmt->close();
                
                $opening = $current_values[$opening_column] ?? 0;
                $purchase = $current_values[$purchase_column] ?? 0;
                $current_sales = $current_values[$sales_column] ?? 0;
                
                // Calculate new sales and closing
                $new_sales = $current_sales + $qty;
                $new_closing = $opening + $purchase - $new_sales;
                
                // Update existing record
                $update_query = "UPDATE $daily_stock_table 
                                 SET $sales_column = ?, 
                                     $closing_column = ?,
                                     LAST_UPDATED = CURRENT_TIMESTAMP 
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("ddss", $new_sales, $new_closing, $month_year, $item_code);
                $update_stmt->execute();
                $update_stmt->close();
                
                // Update next day's opening stock if it exists
                $next_day = intval($day_num) + 1;
                if ($next_day <= 31) {
                    $next_day_num = sprintf('%02d', $next_day);
                    $next_opening_column = "DAY_{$next_day_num}_OPEN";
                    
                    // Check if next day exists in the table
                    $check_next_day_query = "SHOW COLUMNS FROM $daily_stock_table LIKE '$next_opening_column'";
                    $next_day_result = $conn->query($check_next_day_query);
                    
                    if ($next_day_result->num_rows > 0) {
                        $update_next_query = "UPDATE $daily_stock_table 
                                             SET $next_opening_column = ?,
                                                 LAST_UPDATED = CURRENT_TIMESTAMP 
                                             WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                        $update_next_stmt = $conn->prepare($update_next_query);
                        $update_next_stmt->bind_param("dss", $new_closing, $month_year, $item_code);
                        $update_next_stmt->execute();
                        $update_next_stmt->close();
                    }
                }
            } else {
                // Create new record
                $closing = 0 - $qty;
                $insert_query = "INSERT INTO $daily_stock_table 
                                 (STK_MONTH, ITEM_CODE, LIQ_FLAG, $opening_column, $purchase_column, $sales_column, $closing_column) 
                                 VALUES (?, ?, 'F', 0, 0, ?, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("ssdd", $month_year, $item_code, $qty, $closing);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
        }
        
        function updateItemStock($conn, $item_code, $qty, $current_stock_column, $opening_stock_column, $fin_year_id) {
            // Check if record exists
            $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
            $check_stmt = $conn->prepare($check_stock_query);
            $check_stmt->bind_param("s", $item_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $stock_exists = $check_result->fetch_assoc()['count'] > 0;
            $check_stmt->close();
            
            if ($stock_exists) {
                $stock_query = "UPDATE tblitem_stock SET $current_stock_column = $current_stock_column - ? WHERE ITEM_CODE = ?";
                $stock_stmt = $conn->prepare($stock_query);
                $stock_stmt->bind_param("ds", $qty, $item_code);
                $stock_stmt->execute();
                $stock_stmt->close();
            } else {
                $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_stock_column, $current_stock_column) 
                                       VALUES (?, ?, ?, ?)";
                $insert_stock_stmt = $conn->prepare($insert_stock_query);
                $current_stock = -$qty;
                $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $current_stock, $current_stock);
                $insert_stock_stmt->execute();
                $insert_stock_stmt->close();
            }
        }
        
        // Start transaction for all operations
        $conn->begin_transaction();
        
        try {
            // ========== PART 1: EXISTING Customer Sales ==========
            // Generate customer sales bill number
            $customerBillNoQuery = "SELECT MAX(BillNo) as max_bill FROM tblcustomersales WHERE CompID = ?";
            $customerBillStmt = $conn->prepare($customerBillNoQuery);
            $customerBillStmt->bind_param("i", $_SESSION['CompID']);
            $customerBillStmt->execute();
            $customerBillResult = $customerBillStmt->get_result();
            
            $customerBillNo = 1;
            if ($customerBillResult->num_rows > 0) {
                $customerBillData = $customerBillResult->fetch_assoc();
                $customerBillNo = (int)$customerBillData['max_bill'] + 1;
            }
            
            // Get the user ID from session
            $userId = $_SESSION['user_id'];
            
            // Only save to tblcustomersales if it's NOT a walk-in customer
            if (!$isWalkIn) {
                // Insert customer sales records
                $customerInsertQuery = "INSERT INTO tblcustomersales (BillNo, BillDate, LCode, ItemCode, ItemName, ItemSize, Rate, Quantity, Amount, CompID, UserID) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $customerInsertStmt = $conn->prepare($customerInsertQuery);
                
                $currentDate = date('Y-m-d');
                $customerSuccess = true;
                
                foreach ($_SESSION['sale_cart'] as $item) {
                    // Verify item exists in master table
                    $verifyQuery = "SELECT COUNT(*) as count FROM tblitemmaster WHERE CODE = ?";
                    $verifyStmt = $conn->prepare($verifyQuery);
                    $verifyStmt->bind_param("s", $item['code']);
                    $verifyStmt->execute();
                    $verifyResult = $verifyStmt->get_result();
                    $itemExists = $verifyResult->fetch_assoc()['count'] > 0;
                    
                    if (!$itemExists) {
                        $_SESSION['error'] = "Item " . $item['code'] . " does not exist in item master. Sale cancelled.";
                        $customerSuccess = false;
                        break;
                    }
                    
                    // Convert values
                    $customerBillNoInt = (int)$customerBillNo;
                    $rateFloat = (float)$item['rate'];
                    $quantityInt = (int)$item['quantity'];
                    $amountFloat = (float)$item['amount'];
                    $compIdInt = (int)$_SESSION['CompID'];
                    $userIdInt = (int)$userId;
                    
                    $customerInsertStmt->bind_param(
                        "isisssdiiii", 
                        $customerBillNoInt, 
                        $currentDate, 
                        $customerIdForDisplay, 
                        $item['code'], 
                        $item['name'], 
                        $item['size'], 
                        $rateFloat, 
                        $quantityInt, 
                        $amountFloat,
                        $compIdInt,
                        $userIdInt
                    );
                    
                    if (!$customerInsertStmt->execute()) {
                        $_SESSION['error'] = "Error saving customer sale: " . $conn->error;
                        $customerSuccess = false;
                        break;
                    }
                }
                
                if (!$customerSuccess) {
                    $conn->rollback();
                    header("Location: customer_sales.php");
                    exit;
                }
                
                $customerInsertStmt->close();
            }
            
            // ========== PART 2: NEW Retail Sales ==========
            // Get next retail bill number
            $retailBillNo = getNextBillNumberForCustomerSale($conn, $_SESSION['CompID']);
            
            // Calculate total amount for retail sale
            $retailTotalAmount = 0;
            foreach ($_SESSION['sale_cart'] as $item) {
                $retailTotalAmount += $item['amount'];
            }
            
            // Determine mode (LIQ_FLAG) for retail sale
            // Default to Foreign Liquor, you can adjust this logic
            $mode = 'F'; // 'F' for Foreign Liquor
            
            // Insert retail sale header
            $retailHeaderQuery = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY)
                                  VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
            $retailHeaderStmt = $conn->prepare($retailHeaderQuery);
            $retailHeaderStmt->bind_param("ssddssi", 
                $retailBillNo, 
                $currentDate, 
                $retailTotalAmount,
                $retailTotalAmount, 
                $mode, 
                $_SESSION['CompID'], 
                $userId);
            
            if (!$retailHeaderStmt->execute()) {
                throw new Exception("Error saving retail sale header: " . $conn->error);
            }
            $retailHeaderStmt->close();
            
            // Insert retail sale details and update stock
            $current_stock_column = "Current_Stock" . $_SESSION['CompID'];
            $opening_stock_column = "Opening_Stock" . $_SESSION['CompID'];
            $daily_stock_table = "tbldailystock_" . $_SESSION['CompID'];
            $fin_year_id = $_SESSION['FIN_YEAR_ID'];
            
            foreach ($_SESSION['sale_cart'] as $item) {
                // Insert retail sale detail
                $retailDetailQuery = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID)
                                      VALUES (?, ?, ?, ?, ?, ?, ?)";
                $retailDetailStmt = $conn->prepare($retailDetailQuery);
                $retailDetailStmt->bind_param("ssddssi", 
                    $retailBillNo, 
                    $item['code'], 
                    $item['quantity'],
                    $item['rate'], 
                    $item['amount'], 
                    $mode, 
                    $_SESSION['CompID']);
                
                if (!$retailDetailStmt->execute()) {
                    throw new Exception("Error saving retail sale detail: " . $conn->error);
                }
                $retailDetailStmt->close();
                
                // ========== STOCK UPDATES (ONLY ONCE) ==========
                updateItemStock($conn, $item['code'], $item['quantity'], $current_stock_column, $opening_stock_column, $fin_year_id);
                updateDailyStock($conn, $daily_stock_table, $item['code'], $currentDate, $item['quantity'], $_SESSION['CompID']);
            }
            
            // ========== PART 3: Generate Cash Memo ==========
            // Include cash memo functions if available
            $cashMemoFile = "cash_memo_functions.php";
            if (file_exists($cashMemoFile)) {
                include_once $cashMemoFile;
                if (function_exists('autoGenerateCashMemoForBill')) {
                    if (!autoGenerateCashMemoForBill($conn, $retailBillNo, $_SESSION['CompID'], $userId)) {
                        // Log error but don't fail the transaction
                        error_log("Failed to generate cash memo for bill: " . $retailBillNo);
                    }
                }
            }
            
            // Commit all changes
            $conn->commit();
            
            // Calculate totals for display
            $totalAmount = 0;
            foreach ($_SESSION['sale_cart'] as $item) {
                $totalAmount += $item['amount'];
            }
            
            $taxRate = 0.08; // 8% tax
            $taxAmount = $totalAmount * $taxRate;
            $finalAmount = $totalAmount + $taxAmount;
            
            // Store bill data in session for the view page
            $_SESSION['last_bill_data'] = [
                'bill_no' => $isWalkIn ? $retailBillNo : $customerBillNo, // Use retail bill for walk-in
                'retail_bill_no' => $retailBillNo,
                'customer_id' => $customerIdForDisplay,
                'customer_name' => $customerNameForDisplay,
                'bill_date' => $currentDate,
                'items' => $_SESSION['sale_cart'],
                'total_amount' => $totalAmount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'final_amount' => $finalAmount
            ];
            
            // Clear cart and selected customer
            unset($_SESSION['sale_cart']);
            unset($_SESSION['selected_customer']);
            
            // Show bill preview
            echo '<!DOCTYPE html>
            <html lang="en">
            <head>
              <meta charset="UTF-8">
              <meta name="viewport" content="width=device-width, initial-scale=1.0">
              <title>Bill Preview - WineSoft</title>
              <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
              <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
              <style>
                @media print {
                  body * {
                    visibility: hidden;
                  }
                  .bill-preview, .bill-preview * {
                    visibility: visible;
                  }
                  .bill-preview {
                    position: absolute;
                    left: 0;
                    top: 0;
                    width: 100%;
                  }
                  .no-print {
                    display: none !important;
                  }
                }
                .bill-preview {
                  width: 80mm;
                  margin: 0 auto;
                  padding: 5px;
                  font-family: monospace;
                  font-size: 12px;
                }
                .text-center {
                  text-align: center;
                }
                .text-right {
                  text-align: right;
                }
                .bill-header {
                  border-bottom: 1px dashed #000;
                  padding-bottom: 5px;
                  margin-bottom: 5px;
                }
                .bill-footer {
                  border-top: 1px dashed #000;
                  padding-top: 5px;
                  margin-top: 5px;
                }
                .bill-table {
                  width: 100%;
                  border-collapse: collapse;
                }
                .bill-table th, .bill-table td {
                  padding: 2px 0;
                }
                .bill-table .text-right {
                  text-align: right;
                }
              </style>
            </head>
            <body>
              <div class="bill-preview">
                <div class="bill-header text-center">
                  <h4>WineSoft POS</h4>
                  <p>Customer Sale Invoice</p>
                </div>
                
                <div style="margin: 5px 0;">
                  <p style="margin: 2px 0;"><strong>Bill No:</strong> ' . ($isWalkIn ? $retailBillNo : $customerBillNo) . '</p>
                  <p style="margin: 2px 0;"><strong>Retail Bill No:</strong> ' . $retailBillNo . '</p>
                  <p style="margin: 2px 0;"><strong>Date:</strong> ' . date('d/m/Y', strtotime($currentDate)) . '</p>
                  <p style="margin: 2px 0;"><strong>Customer:</strong> ' . $customerNameForDisplay . ($customerIdForDisplay > 0 ? ' (' . $customerIdForDisplay . ')' : '') . '</p>
                </div>
                
                <table class="bill-table">
                  <thead>
                    <tr>
                      <th>Item</th>
                      <th class="text-right">Qty</th>
                      <th class="text-right">Rate</th>
                      <th class="text-right">Amount</th>
                    </tr>
                  </thead>
                  <tbody>';
            
            foreach ($_SESSION['last_bill_data']['items'] as $item) {
                echo '<tr>
                        <td>' . substr($item['name'], 0, 15) . '</td>
                        <td class="text-right">' . $item['quantity'] . '</td>
                        <td class="text-right">' . number_format($item['rate'], 2) . '</td>
                        <td class="text-right">' . number_format($item['amount'], 2) . '</td>
                      </tr>';
            }
            
            echo '</tbody>
                </table>
                
                <div class="bill-footer">
                  <table class="bill-table">
                    <tr>
                      <td>Sub Total:</td>
                      <td class="text-right">₹' . number_format($totalAmount, 2) . '</td>
                    </tr>
                    <tr>
                      <td>Tax (' . ($taxRate * 100) . '%):</td>
                      <td class="text-right">₹' . number_format($taxAmount, 2) . '</td>
                    </tr>
                    <tr>
                      <td><strong>Total Due:</strong></td>
                      <td class="text-right"><strong>₹' . number_format($finalAmount, 2) . '</strong></td>
                    </tr>
                  </table>
                  
                  <p style="margin: 5px 0; text-align: center;">Thank you for your business!</p>
                  <p style="margin: 2px 0; text-align: center; font-size: 10px;">GST #: 103340329010001</p>
                </div>
                
                <div class="no-print text-center" style="margin-top: 15px;">
                  <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                  <a href="customer_sales_view.php?bill_no=' . ($isWalkIn ? $retailBillNo : $customerBillNo) . '" class="btn btn-success btn-sm"><i class="fas fa-eye"></i> View Details</a>
                  <a href="customer_sales.php" class="btn btn-secondary btn-sm"><i class="fas fa-plus"></i> New Sale</a>
                </div>
              </div>
            </body>
            </html>';
            exit;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $_SESSION['error'] = "Error processing sale: " . $e->getMessage();
            header("Location: customer_sales.php");
            exit;
        }
    }
    
    // Clear cart
    if (isset($_POST['clear_cart'])) {
        unset($_SESSION['sale_cart']);
    }
}

// Get sale items from session if available
if (isset($_SESSION['sale_cart'])) {
    $saleItems = $_SESSION['sale_cart'];
    foreach ($saleItems as $item) {
        $totalAmount += $item['amount'];
    }
}

// Fetch items for dropdown with their WPRICE (from any customer)
$itemsQuery = "SELECT i.CODE, i.DETAILS, i.DETAILS2, COALESCE(cp.WPrice, i.BPRICE) as Price 
               FROM tblitemmaster i 
               LEFT JOIN tblcustomerprices cp ON i.CODE = cp.CODE 
               GROUP BY i.CODE 
               ORDER BY i.DETAILS";
$itemsResult = $conn->query($itemsQuery);
$itemOptions = [];
if ($itemsResult) {
    while ($row = $itemsResult->fetch_assoc()) {
        $itemOptions[$row['CODE']] = [
            'name' => $row['DETAILS'] . ' (' . $row['DETAILS2'] . ')',
            'price' => $row['Price']
        ];
    }
} else {
    echo "Error fetching items: " . $conn->error;
}

// Get selected customer from session if available
$selectedCustomer = isset($_SESSION['selected_customer']) ? $_SESSION['selected_customer'] : '';

// Clear customer-related session messages after displaying
if (isset($_SESSION['success_message'])) {
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    unset($_SESSION['error_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Sales - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <!-- Select2 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>

  <style>
    .table-container{overflow-x:auto;max-height:520px}
    table.styled-table{width:100%;border-collapse:collapse}
    .styled-table th,.styled-table td{border:1px solid #e5e7eb;padding:8px 10px}
    .styled-table thead th{position:sticky;top:0;background:#f8fafc;z-index:1}
    .action-buttons{display:flex;gap:5px}
    .status-badge{padding:4px 8px;border-radius:4px;font-size:0.8rem}
    .status-completed{background:#d1fae5;color:#065f46}
    .status-pending{background:#fef3c7;color:#92400e}
    .price-input {width: 80px; text-align: right;}
    .item-price {font-size: 0.9rem; color: #6c757d;}
    /* Custom styling for Select2 */
    .select2-container--bootstrap-5 .select2-selection {
      min-height: 38px;
      padding: 5px;
    }
    .select2-results__option {
      padding: 8px 12px;
    }
    .item-option {
      display: flex;
      justify-content: space-between;
    }
    .item-code {
      font-size: 0.85rem;
      color: #6c757d;
    }
    /* Customer field styling */
    .customer-combined-field {
        position: relative;
    }
    .customer-hint {
        font-size: 0.875rem;
        color: #6c757d;
        margin-top: 5px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <div class="content-area p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Customer Sales</h4>
      </div>

      <!-- Success/Error Messages -->
      <?php if (isset($_SESSION['success_message'])): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $_SESSION['success_message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <?php if (isset($_SESSION['error_message'])): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $_SESSION['error_message'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> Sale completed successfully! Bill No: <?= htmlspecialchars($_GET['bill_no']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-exclamation me-2"></i> <?= $_SESSION['error'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
      <?php endif; ?>

      <!-- Combined Customer Field -->
      <div class="row mb-4">
        <div class="col-12">
          <div class="card">
            <div class="card-header">
              <h5 class="card-title mb-0"><i class="fas fa-user"></i> Customer Information</h5>
            </div>
            <div class="card-body">
              <form method="POST" id="customerForm">
                <div class="customer-combined-field">
                  <label for="customer_field" class="form-label">Select or Create Customer</label>
                  <input type="text"
                         class="form-control"
                         id="customer_field"
                         name="customer_field"
                         list="customerOptions"
                         placeholder="Type to search customers or type 'new: Customer Name' to create new"
                         value="<?= !empty($selectedCustomer) && isset($customers[$selectedCustomer]) ? $customers[$selectedCustomer] : '' ?>">
                  <datalist id="customerOptions">
                    <option value="">Walk-in Customer</option>
                    <?php foreach ($customers as $code => $name): ?>
                      <option value="<?= $code ?>"><?= htmlspecialchars($name) ?></option>
                    <?php endforeach; ?>
                  </datalist>
                  <div class="customer-hint">
                    <i class="fas fa-info-circle"></i>
                    Select existing customer from dropdown or type "new: Customer Name" to create new customer.
                    Leave empty for walk-in customer.
                  </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">
                  <i class="fas fa-save"></i> Save Customer Selection
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <form method="POST" class="mb-4">

        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-cube me-2"></i>Add Items</div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-5">
                <div class="form-group">
                  <label for="item_search" class="form-label">Search and Select Item</label>
                  <select class="form-select" id="item_search" name="item_code" required>
                    <option value="">-- Type to search items --</option>
                    <?php foreach ($itemOptions as $code => $itemData): ?>
                      <option value="<?= $code ?>" data-price="<?= $itemData['price'] ?>">
                        <?= htmlspecialchars($itemData['name']) ?> 
                        <span class="item-price">(₹<?= number_format($itemData['price'], 2) ?>)</span>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="col-md-3">
                <div class="form-group">
                  <label for="quantity" class="form-label">Quantity</label>
                  <input type="number" class="form-control" id="quantity" name="quantity" value="1" min="1" required>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <button type="submit" name="add_item" class="btn btn-primary w-100">
                  <i class="fas fa-plus"></i> Add Item
                </button>
              </div>
            </div>
          </div>
        </div>
      </form>

      <!-- Sale Items Table -->
      <?php if (!empty($saleItems)): ?>
        <div class="card mb-4">
          <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-list me-2"></i>Sale Items</span>
            <form method="POST" class="d-inline">
              <button type="submit" name="clear_cart" class="btn btn-sm btn-outline-danger">
                <i class="fas fa-trash"></i> Clear All
              </button>
            </form>
          </div>
          <div class="card-body p-0">
            <div class="table-container">
              <table class="styled-table">
                <thead>
                  <tr>
                    <th>Item Code</th>
                    <th>Item Name</th>
                    <th>Size</th>
                    <th>Rate</th>
                    <th>Qty</th>
                    <th>Amount</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($saleItems as $item): ?>
                    <tr>
                      <td><?= htmlspecialchars($item['code']) ?></td>
                      <td><?= htmlspecialchars($item['name']) ?></td>
                      <td><?= htmlspecialchars($item['size']) ?></td>
                      <td>
                        <form method="POST" class="d-inline-flex">
                          <input type="hidden" name="item_code" value="<?= $item['code'] ?>">
                          <input type="hidden" name="customer_id" value="<?= $selectedCustomer ?>">
                          <input type="number" step="0.001" class="form-control form-control-sm price-input" 
                                 name="new_price" value="<?= number_format($item['rate'], 3) ?>" required>
                          <button type="submit" name="update_price" class="btn btn-sm btn-outline-primary ms-1">
                            <i class="fas fa-sync"></i>
                          </button>
                        </form>
                      </td>
                      <td><?= $item['quantity'] ?></td>
                      <td>₹<?= number_format($item['amount'], 2) ?></td>
                      <td>
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="remove_item_code" value="<?= $item['code'] ?>">
                          <input type="hidden" name="customer_id" value="<?= $selectedCustomer ?>">
                          <button type="submit" name="remove_item" class="btn btn-sm btn-danger">
                            <i class="fas fa-trash"></i>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <tr class="table-primary">
                    <td colspan="5" class="text-end fw-bold">Total Amount:</td>
                    <td class="fw-bold">₹<?= number_format($totalAmount, 2) ?></td>
                    <td></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
          <a href="dashboard.php" class="btn btn-secondary">
            <i class="fas fa-times"></i> Cancel
          </a>
          <form method="POST">
            <button type="submit" name="finalize_sale" class="btn btn-success">
              <i class="fas fa-check"></i> Finalize Sale
            </button>
          </form>
        </div>
      <?php else: ?>
        <div class="text-center py-4">
          <i class="fa-solid fa-cart-shopping fa-3x text-muted mb-3"></i>
          <h5 class="text-muted">No items in cart</h5>
          <p class="text-muted">Select a customer and add items to create a sale</p>
        </div>
      <?php endif; ?>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
  // Initialize Select2 for item search
  $('#item_search').select2({
    theme: 'bootstrap-5',
    placeholder: "Type to search items...",
    allowClear: true,
    templateResult: formatItem,
    templateSelection: formatItemSelection
  });
  
  // Format how items appear in the dropdown
  function formatItem(item) {
    if (!item.id) {
      return item.text;
    }
    
    var $item = $(
      '<div class="item-option">' +
        '<div>' + item.text + '</div>' +
        '<div class="item-code">' + item.id + '</div>' +
      '</div>'
    );
    return $item;
  }
  
  // Format how the selected item appears
  function formatItemSelection(item) {
    if (!item.id) {
      return item.text;
    }
    // Show just the item name in the selection
    return item.text.split(' (₹')[0];
  }
  
  
  // Update price display when item is selected
  $('#item_search').on('select2:select', function(e) {
    var data = e.params.data;
    var price = $(data.element).data('price');
    if (price) {
      // You can display the price somewhere if needed
      console.log('Selected item price: ' + price);
    }
  });
  
  // Prevent form submission if customer is not selected when adding items
  $('form').submit(function(e) {
    if ($(this).find('button[name="add_item"]').length > 0) {
      if (!<?= $selectedCustomer ? 'true' : 'false' ?>) {
        e.preventDefault();
        alert('Please select a customer first');
        $('#customer_field').focus();
      }
    }
  });
});
</script>
</body>
</html>