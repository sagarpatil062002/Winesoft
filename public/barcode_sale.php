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

// Fetch customers from tbllheads
$customerQuery = "SELECT LCODE, LHEAD FROM tbllheads WHERE GCODE=32 ORDER BY LHEAD";
$customerResult = $conn->query($customerQuery);
$customers = [];
if ($customerResult) {
    while ($row = $customerResult->fetch_assoc()) {
        $customers[$row['LCODE']] = $row['LHEAD'];
    }
} else {
    echo "Error fetching customers: " . $conn->error;
}

// Get selected customer from session if available
$selectedCustomer = isset($_SESSION['selected_customer']) ? $_SESSION['selected_customer'] : '';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get company ID
$comp_id = $_SESSION['CompID'];
$fin_year_id = $_SESSION['FIN_YEAR_ID'];
$current_stock_column = "Current_Stock" . $comp_id;

// Fetch items from tblitemmaster for barcode scanning
$query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE 
          FROM tblitemmaster 
          WHERE 1=1"; // Removed barcode filter to include all items
$params = [];
$types = "";

if ($search !== '') {
    $query .= " AND (DETAILS LIKE ? OR CODE LIKE ? OR BARCODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "sss";
}

$query .= " ORDER BY DETAILS ASC";

if (!empty($params)) {
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $search_items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $result = $conn->query($query);
    $search_items = $result->fetch_all(MYSQLI_ASSOC);
}

// Initialize sale items session if not exists
if (!isset($_SESSION['sale_items'])) {
    $_SESSION['sale_items'] = [];
    $_SESSION['sale_count'] = 0;
    $_SESSION['current_focus_index'] = -1; // Track currently focused item
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Store customer ID in session to preserve selection
    if (isset($_POST['customer_id'])) {
        $_SESSION['selected_customer'] = $_POST['customer_id'];
        $selectedCustomer = $_POST['customer_id'];
    }
    
    // Handle adding item to sale
    if (isset($_POST['add_item'])) {
        $item_code = $_POST['item_code'];
        $quantity = intval($_POST['quantity']);
        
        // Fetch item details - search by BARCODE first, then by CODE
        $item_query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE 
                      FROM tblitemmaster 
                      WHERE BARCODE = ? OR CODE = ? 
                      LIMIT 1";
        $item_stmt = $conn->prepare($item_query);
        $item_stmt->bind_param("ss", $item_code, $item_code);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        
        if ($item_result->num_rows > 0) {
            $item_data = $item_result->fetch_assoc();
            
            // Check if item already exists in sale
            $item_index = -1;
            foreach ($_SESSION['sale_items'] as $index => $item) {
                if ($item['code'] === $item_data['CODE']) {
                    $item_index = $index;
                    break;
                }
            }
            
            if ($item_index !== -1) {
                // Update quantity if item already in sale
                $_SESSION['sale_items'][$item_index]['quantity'] += $quantity;
            } else {
                // Add new item to sale
                $_SESSION['sale_items'][] = [
                    'code' => $item_data['CODE'],
                    'name' => $item_data['DETAILS'],
                    'size' => $item_data['DETAILS2'],
                    'price' => floatval($item_data['RPRICE']),
                    'quantity' => $quantity
                ];
            }
            
            $_SESSION['sale_count'] = count($_SESSION['sale_items']); // Update count based on actual items
            
            // Auto-save after 10 items - MODIFIED: Process immediately and redirect
            if ($_SESSION['sale_count'] >= 10) {
                processSale();
                // Clear the session after processing
                $_SESSION['sale_items'] = [];
                $_SESSION['sale_count'] = 0;
                $_SESSION['current_focus_index'] = -1;
                
                // Store success message
                $_SESSION['success_message'] = "Sale processed automatically after 10 items! Starting new sale.";
                
                // Redirect to refresh the page with empty sale table
                header("Location: barcode_sale.php");
                exit;
            }
        } else {
            // Item not found - store error message
            $_SESSION['error_message'] = "Item with barcode/code '$item_code' not found!";
        }
        $item_stmt->close();
        
        // Redirect to avoid form resubmission
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle updating item quantity
    if (isset($_POST['update_quantity'])) {
        $item_code = $_POST['item_code'];
        $quantity = intval($_POST['quantity']);
        
        foreach ($_SESSION['sale_items'] as $index => $item) {
            if ($item['code'] === $item_code) {
                $_SESSION['sale_items'][$index]['quantity'] = $quantity;
                break;
            }
        }
        
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle removing item from sale
    if (isset($_POST['remove_item'])) {
        $item_code = $_POST['item_code'];
        
        foreach ($_SESSION['sale_items'] as $index => $item) {
            if ($item['code'] === $item_code) {
                unset($_SESSION['sale_items'][$index]);
                // Reindex array
                $_SESSION['sale_items'] = array_values($_SESSION['sale_items']);
                
                // Update sale count based on actual items
                $_SESSION['sale_count'] = count($_SESSION['sale_items']);
                
                // Adjust focus index if needed
                if ($_SESSION['current_focus_index'] >= $index) {
                    $_SESSION['current_focus_index'] = max(-1, $_SESSION['current_focus_index'] - 1);
                }
                break;
            }
        }
        
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle manual sale processing
    if (isset($_POST['process_sale'])) {
        processSale();
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle clearing sale
    if (isset($_POST['clear_sale'])) {
        $_SESSION['sale_items'] = [];
        $_SESSION['sale_count'] = 0;
        $_SESSION['current_focus_index'] = -1;
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle setting focus index
    if (isset($_POST['set_focus_index'])) {
        $_SESSION['current_focus_index'] = intval($_POST['set_focus_index']);
        header("Location: barcode_sale.php");
        exit;
    }
}

// Function to generate a unique bill number
function generateBillNumber($conn, $comp_id) {
    // Try to get the maximum bill number
    $bill_query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill 
                   FROM tblsaleheader 
                   WHERE COMP_ID = ?";
    $bill_stmt = $conn->prepare($bill_query);
    $bill_stmt->bind_param("i", $comp_id);
    $bill_stmt->execute();
    $bill_result = $bill_stmt->get_result();
    
    $next_bill = 1;
    if ($bill_result->num_rows > 0) {
        $bill_row = $bill_result->fetch_assoc();
        $next_bill = ($bill_row['max_bill'] ? $bill_row['max_bill'] + 1 : 1);
    }
    $bill_stmt->close();
    
    // Format the bill number with leading zeros
return "BL" . $next_bill;
}

// Function to process the sale
function processSale() {
    global $conn, $comp_id, $current_stock_column, $fin_year_id, $selectedCustomer, $customers;
    
    if (!empty($_SESSION['sale_items'])) {
        $user_id = $_SESSION['user_id'];
        $mode = 'F'; // Default to Foreign Liquor
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Generate a unique bill number
            $bill_no = generateBillNumber($conn, $comp_id);
            
            $sale_date = date('Y-m-d H:i:s');
            $total_amount = 0;
            
            // Calculate total amount
            foreach ($_SESSION['sale_items'] as $item) {
                $total_amount += $item['price'] * $item['quantity'];
            }
            
            // Insert sale header
            $customer_id = !empty($selectedCustomer) ? $selectedCustomer : NULL;
            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, CUSTOMER_ID, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)";
            $header_stmt = $conn->prepare($header_query);
            $header_stmt->bind_param("sssddssi", $bill_no, $sale_date, $customer_id, $total_amount, $total_amount, $mode, $comp_id, $user_id);
            $header_stmt->execute();
            
            if ($header_stmt->errno === 1062) {
                // Duplicate entry error - retry with a new bill number
                $header_stmt->close();
                $bill_no = generateBillNumber($conn, $comp_id);
                
                $header_stmt = $conn->prepare($header_query);
                $header_stmt->bind_param("sssddssi", $bill_no, $sale_date, $customer_id, $total_amount, $total_amount, $mode, $comp_id, $user_id);
                $header_stmt->execute();
            }
            
            $header_stmt->close();
            
            // Insert sale details and update stock
            foreach ($_SESSION['sale_items'] as $item) {
                $amount = $item['price'] * $item['quantity'];
                
                // Insert sale details
                $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                $detail_stmt = $conn->prepare($detail_query);
                $detail_stmt->bind_param("ssddssi", $bill_no, $item['code'], $item['quantity'], $item['price'], $amount, $mode, $comp_id);
                $detail_stmt->execute();
                $detail_stmt->close();
                
                // Update stock - check if record exists first
                $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ? AND FIN_YEAR = ?";
                $check_stmt = $conn->prepare($check_stock_query);
                $check_stmt->bind_param("ss", $item['code'], $fin_year_id);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                $stock_exists = $check_result->fetch_assoc()['count'] > 0;
                $check_stmt->close();
                
                if ($stock_exists) {
                    // Update existing stock
                    $stock_query = "UPDATE tblitem_stock SET $current_stock_column = $current_stock_column - ? WHERE ITEM_CODE = ? AND FIN_YEAR = ?";
                    $stock_stmt = $conn->prepare($stock_query);
                    $stock_stmt->bind_param("dss", $item['quantity'], $item['code'], $fin_year_id);
                    $stock_stmt->execute();
                    $stock_stmt->close();
                } else {
                    // Insert new stock record
                    $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $current_stock_column) 
                                           VALUES (?, ?, ?)";
                    $insert_stock_stmt = $conn->prepare($insert_stock_query);
                    $current_stock = -$item['quantity']; // Negative since we're deducting
                    $insert_stock_stmt->bind_param("ssd", $item['code'], $fin_year_id, $current_stock);
                    $insert_stock_stmt->execute();
                    $insert_stock_stmt->close();
                }
                
                // Update daily stock table
                $sale_date_only = date('Y-m-d');
                $daily_stock_table = "tbldailystock_" . $comp_id;
                
                // Check if the daily stock table exists and has the correct structure
                $table_check = $conn->query("SHOW TABLES LIKE '$daily_stock_table'");
                if ($table_check->num_rows > 0) {
                    // Check if the table has the STOCK_DATE column
                    $column_check = $conn->query("SHOW COLUMNS FROM $daily_stock_table LIKE 'STOCK_DATE'");
                    if ($column_check->num_rows > 0) {
                        // Check if daily stock record exists
                        $check_daily_query = "SELECT COUNT(*) as count FROM $daily_stock_table WHERE ITEM_CODE = ? AND STOCK_DATE = ?";
                        $check_daily_stmt = $conn->prepare($check_daily_query);
                        $check_daily_stmt->bind_param("ss", $item['code'], $sale_date_only);
                        $check_daily_stmt->execute();
                        $check_daily_result = $check_daily_stmt->get_result();
                        $daily_exists = $check_daily_result->fetch_assoc()['count'] > 0;
                        $check_daily_stmt->close();
                        
                        if ($daily_exists) {
                            // Update existing daily stock
                            $daily_query = "UPDATE $daily_stock_table SET SALE_QTY = SALE_QTY + ?, SALE_VALUE = SALE_VALUE + ? 
                                            WHERE ITEM_CODE = ? AND STOCK_DATE = ?";
                            $daily_stmt = $conn->prepare($daily_query);
                            $sale_value = $item['price'] * $item['quantity'];
                            $daily_stmt->bind_param("ddss", $item['quantity'], $sale_value, $item['code'], $sale_date_only);
                            $daily_stmt->execute();
                            $daily_stmt->close();
                        } else {
                            // Insert new daily stock record
                            $insert_daily_query = "INSERT INTO $daily_stock_table (ITEM_CODE, STOCK_DATE, SALE_QTY, SALE_VALUE) 
                                                   VALUES (?, ?, ?, ?)";
                            $insert_daily_stmt = $conn->prepare($insert_daily_query);
                            $sale_value = $item['price'] * $item['quantity'];
                            $insert_daily_stmt->bind_param("ssdd", $item['code'], $sale_date_only, $item['quantity'], $sale_value);
                            $insert_daily_stmt->execute();
                            $insert_daily_stmt->close();
                        }
                    }
                }
            }
            
            // Commit transaction
            $conn->commit();
            
            // Store bill data in session for the view page
            $customer_name = !empty($selectedCustomer) && isset($customers[$selectedCustomer]) ? $customers[$selectedCustomer] : 'Walk-in Customer';
            
            $_SESSION['last_bill_data'] = [
                'bill_no' => $bill_no,
                'customer_id' => $selectedCustomer,
                'customer_name' => $customer_name,
                'bill_date' => $sale_date,
                'items' => $_SESSION['sale_items'],
                'total_amount' => $total_amount,
                'tax_rate' => 0.08, // 8% tax
                'tax_amount' => $total_amount * 0.08,
                'final_amount' => $total_amount * 1.08
            ];
            
            $success_message = "Sale completed successfully! Bill No: $bill_no, Total Amount: ₹" . number_format($total_amount, 2);
            
            // Store messages in session to display after redirect
            $_SESSION['success_message'] = $success_message;
            
            // Clear cart and selected customer
            unset($_SESSION['sale_items']);
            unset($_SESSION['sale_count']);
            unset($_SESSION['current_focus_index']);
            unset($_SESSION['selected_customer']);
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error processing sale: " . $e->getMessage();
            $_SESSION['error_message'] = $error_message;
        }
    } else {
        $error_message = "No items to process.";
        $_SESSION['error_message'] = $error_message;
    }
}

// Check for success/error messages in session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get current stock for items in sale table
if (!empty($_SESSION['sale_items'])) {
    foreach ($_SESSION['sale_items'] as &$item) {
        $stock_query = "SELECT COALESCE($current_stock_column, 0) as stock FROM tblitem_stock WHERE ITEM_CODE = ? AND FIN_YEAR = ?";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("ss", $item['code'], $fin_year_id);
        $stock_stmt->execute();
        $stock_result = $stock_stmt->get_result();
        
        if ($stock_result->num_rows > 0) {
            $stock_data = $stock_result->fetch_assoc();
            $item['current_stock'] = floatval($stock_data['stock']);
        } else {
            $item['current_stock'] = 0;
        }
        
        $stock_stmt->close();
    }
    unset($item); // Break the reference
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>POS System - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .barcode-scanner {
      background-color: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    .scanner-animation {
      height: 4px;
      background: #007bff;
      border-radius: 4px;
      overflow: hidden;
      margin: 10px 0;
      position: relative;
    }
    .scanner-animation::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 20%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.8), transparent);
      animation: scanner 2s infinite linear;
    }
    @keyframes scanner {
      0% { left: -20%; }
      100% { left: 120%; }
    }
    .status-indicator {
      display: inline-block;
      width: 12px;
      height: 12px;
      border-radius: 50%;
      margin-right: 5px;
    }
    .status-ready { background-color: #28a745; }
    .status-scanning { background-color: #ffc107; }
    .search-results {
      max-height: 300px;
      overflow-y: auto;
      border: 1px solid #dee2e6;
      border-radius: 5px;
      margin-top: 10px;
    }
    .search-item {
      padding: 10px;
      border-bottom: 1px solid #eee;
      cursor: pointer;
    }
    .search-item:hover {
      background-color: #f8f9fa;
    }
    .search-item:last-child {
      border-bottom: none;
    }
    .table-responsive {
      max-height: 500px;
      overflow-y: auto;
    }
    .no-barcode {
      color: #6c757d;
      font-style: italic;
    }
    .sale-table {
      margin-top: 20px;
    }
    .sale-table th {
      background-color: #343a40;
      color: white;
    }
    .sale-info {
      background-color: #f8f9fa;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 10px;
    }
    .search-header {
      background-color: #f8f9fa;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 10px;
    }
    .auto-save-notice {
      background-color: #fff3cd;
      border: 1px solid #ffeaa7;
      color: #856404;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
    }
    .quantity-controls {
      display: flex;
      align-items: center;
    }
    .quantity-btn {
      width: 30px;
      height: 30px;
      display: flex;
      align-items: center;
      justify-content: center;
      border: 1px solid #ddd;
      background-color: #f8f9fa;
      cursor: pointer;
    }
    .quantity-btn:hover {
      background-color: #e9ecef;
    }
    .quantity-input {
      width: 50px;
      text-align: center;
      margin: 0 5px;
    }
    .focused-row {
      background-color: rgba(0, 123, 255, 0.1) !important;
      box-shadow: 0 0 0 2px #007bff;
    }
    .keyboard-hint {
      font-size: 0.8rem;
      color: #6c757d;
      margin-top: 5px;
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
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">POS System</h3>

      <!-- Success/Error Messages -->
      <?php if (isset($success_message)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <!-- Bill Preview Modal -->
      <?php if (isset($_SESSION['last_bill_data'])): 
        $bill_data = $_SESSION['last_bill_data'];
        $companyName = "WineSoft"; // Replace with actual company name if available
      ?>
      <div class="modal fade show" id="billPreviewModal" tabindex="-1" aria-labelledby="billPreviewModalLabel" aria-modal="true" style="display: block;">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="billPreviewModalLabel">Bill Preview</h5>
              <button type="button" class="btn-close" onclick="window.location.href='barcode_sale.php'"></button>
            </div>
            <div class="modal-body">
              <div class="bill-preview">
                <div class="bill-header text-center">
                  <h1><?= htmlspecialchars($companyName) ?></h1>
                </div>
                
                <div style="margin: 5px 0;">
                  <p style="margin: 2px 0;"><strong>Bill No:</strong> <?= $bill_data['bill_no'] ?></p>
                  <p style="margin: 2px 0;"><strong>Date:</strong> <?= date('d/m/Y', strtotime($bill_data['bill_date'])) ?></p>
                  <p style="margin: 2px 0;"><strong>Customer:</strong> <?= $bill_data['customer_name'] ?> <?= !empty($bill_data['customer_id']) ? '(' . $bill_data['customer_id'] . ')' : '' ?></p>
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
                  <tbody>
                    <?php foreach ($bill_data['items'] as $item): ?>
                    <tr>
                      <td><?= substr($item['name'], 0, 15) ?></td>
                      <td class="text-right"><?= $item['quantity'] ?></td>
                      <td class="text-right"><?= number_format($item['price'], 2) ?></td>
                      <td class="text-right"><?= number_format($item['price'] * $item['quantity'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                
                <div class="bill-footer">
                  <table class="bill-table">
                    <tr>
                      <td>Sub Total:</td>
                      <td class="text-right">₹<?= number_format($bill_data['total_amount'], 2) ?></td>
                    </tr>
                    <tr>
                      <td>Tax (<?= ($bill_data['tax_rate'] * 100) ?>%):</td>
                      <td class="text-right">₹<?= number_format($bill_data['tax_amount'], 2) ?></td>
                    </tr>
                    <tr>
                      <td><strong>Total Due:</strong></td>
                      <td class="text-right"><strong>₹<?= number_format($bill_data['final_amount'], 2) ?></strong></td>
                    </tr>
                  </table>
                  
                  <p style="margin: 5px 0; text-align: center;">Thank you for your business!</p>
                  <p style="margin: 2px 0; text-align: center; font-size: 10px;">GST #: 103340329010001</p>
                </div>
                
                <div class="no-print text-center" style="margin-top: 15px;">
                  <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                  <button class="btn btn-secondary btn-sm" onclick="window.location.href='barcode_sale.php'"><i class="fas fa-plus"></i> New Sale</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-backdrop fade show"></div>
      <?php 
        // Clear the bill data after displaying
        unset($_SESSION['last_bill_data']);
      endif; ?>

      <!-- Customer Selection -->
      <form method="POST" class="mb-3">
        <div class="card">
          <div class="card-header fw-semibold"><i class="fa-solid fa-user me-2"></i>Customer Information (Optional)</div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-8">
                <div class="form-group">
                  <label for="customer_id" class="form-label">Select Customer</label>
                  <select class="form-select" id="customer_id" name="customer_id">
                    <option value="">-- Walk-in Customer --</option>
                    <?php foreach ($customers as $code => $name): ?>
                      <option value="<?= $code ?>" <?= ($selectedCustomer == $code) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($name) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div class="col-md-4 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                  <i class="fas fa-save"></i> Save Customer
                </button>
              </div>
            </div>
          </div>
        </div>
      </form>

      <!-- Auto-save notice -->
      <?php if (isset($_SESSION['sale_count']) && $_SESSION['sale_count'] >= 9): ?>
      <div class="auto-save-notice">
        <i class="fas fa-info-circle me-1"></i> 
        <strong>Notice:</strong> After adding the next item, the sale will be automatically processed and a new sale will start.
      </div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['sale_items'])): ?>
      <div class="sale-info">
        <strong>Items in current sale:</strong> <?= count($_SESSION['sale_items']) ?> | 
        <strong>Total sale count:</strong> <?= $_SESSION['sale_count'] ?>
        <?php if ($_SESSION['sale_count'] >= 10): ?>
          <span class="badge bg-warning float-end">Ready to auto-save</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Barcode Scanner -->
      <div class="barcode-scanner mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h4><i class="fas fa-barcode me-2"></i>Barcode Scanner</h4>
          <div>
            <span class="status-indicator status-ready" id="statusIndicator"></span>
            <span id="statusText">Ready to scan</span>
          </div>
        </div>
        <div class="scanner-animation"></div>
        <div class="input-group">
          <input type="text" class="form-control form-control-lg" id="barcodeInput" 
                 placeholder="Scan barcode or enter item code" autofocus>
          <button class="btn btn-primary" type="button" id="scanBtn">
            <i class="fas fa-camera"></i> Scan
          </button>
        </div>
        <div class="form-text">Enter barcode or item code to search</div>
      </div>

      <!-- Search -->
      <form method="GET" class="search-control mb-3">
        <div class="input-group">
          <input type="text" name="search" class="form-control"
                 placeholder="Search by item name, code, or barcode..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Search
          </button>
          <?php if ($search !== ''): ?>
            <a href="barcode_sale.php" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Search Results -->
      <?php if ($search !== '' && !empty($search_items)): ?>
        <div class="search-results">
          <?php foreach ($search_items as $item): ?>
            <div class="search-item" 
                 data-code="<?= htmlspecialchars($item['CODE']) ?>"
                 data-name="<?= htmlspecialchars($item['DETAILS']) ?>"
                 data-price="<?= floatval($item['RPRICE']) ?>"
                 data-barcode="<?= htmlspecialchars($item['BARCODE']) ?>">
              <h6 class="mb-1"><?= htmlspecialchars($item['DETAILS']) ?></h6>
              <p class="mb-1 text-muted small"><?= htmlspecialchars($item['DETAILS2']) ?></p>
              <p class="mb-1"><strong>₹<?= number_format($item['RPRICE'], 2) ?></strong></p>
              <p class="mb-0 text-muted small">
                Code: <?= htmlspecialchars($item['CODE']) ?> | 
                Barcode: <?= $item['BARCODE'] ? htmlspecialchars($item['BARCODE']) : '<span class="no-barcode">No barcode</span>' ?>
              </p>
            </div>
          <?php endforeach; ?>
        </div>
      <?php elseif ($search !== ''): ?>
        <div class="alert alert-info">No items found matching your search.</div>
      <?php endif; ?>

      <!-- Sale Table -->
      <div class="sale-table">
        <h4 class="mb-3">Current Sale Items</h4>
        
        <?php if (!empty($_SESSION['sale_items'])): ?>
          <div class="table-responsive">
            <table class="table table-striped table-bordered">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Item Name</th>
                  <th>Size</th>
                  <th>Price</th>
                  <th>Quantity</th>
                  <th>Amount</th>
                  <th>Current Stock</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $total_amount = 0;
                foreach ($_SESSION['sale_items'] as $index => $item): 
                  $item_amount = $item['price'] * $item['quantity'];
                  $total_amount += $item_amount;
                  $is_focused = $index == $_SESSION['current_focus_index'];
                ?>
                  <tr id="item-row-<?= $index ?>" class="<?= $is_focused ? 'focused-row' : '' ?>">
                    <td><?= htmlspecialchars($item['code']) ?></td>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td><?= htmlspecialchars($item['size']) ?></td>
                    <td>₹<?= number_format($item['price'], 2) ?></td>
                    <td>
                      <div class="quantity-controls">
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="item_code" value="<?= $item['code'] ?>">
                          <input type="hidden" name="quantity" value="<?= $item['quantity'] - 1 ?>">
                          <button type="submit" name="update_quantity" class="quantity-btn" <?= $item['quantity'] <= 1 ? 'disabled' : '' ?>>
                            <i class="fas fa-minus"></i>
                          </button>
                        </form>
                        <span class="quantity-display"><?= $item['quantity'] ?></span>
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="item_code" value="<?= $item['code'] ?>">
                          <input type="hidden" name="quantity" value="<?= $item['quantity'] + 1 ?>">
                          <button type="submit" name="update_quantity" class="quantity-btn">
                            <i class="fas fa-plus"></i>
                          </button>
                        </form>
                      </div>
                    </td>
                    <td>₹<?= number_format($item_amount, 2) ?></td>
                    <td><?= $item['current_stock'] ?></td>
                    <td>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="item_code" value="<?= $item['code'] ?>">
                        <button type="submit" name="remove_item" class="btn btn-sm btn-danger">
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="5" class="text-end"><strong>Total:</strong></td>
                  <td><strong>₹<?= number_format($total_amount, 2) ?></strong></td>
                  <td colspan="2"></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <div class="keyboard-hint">
            <i class="fas fa-info-circle"></i> Use Arrow Up/Down keys to navigate between items, +/- to adjust quantities
          </div>

          <div class="d-flex justify-content-end mt-3">
            <form method="POST" class="me-2">
              <button type="submit" name="clear_sale" class="btn btn-danger">
                <i class="fas fa-trash me-1"></i> Clear Sale
              </button>
            </form>
            <form method="POST">
              <button type="submit" name="process_sale" class="btn btn-success">
                <i class="fas fa-check me-1"></i> Process Sale
              </button>
            </form>
          </div>
        <?php else: ?>
          <div class="alert alert-info">No items in the current sale. Scan or search for items to add.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Hidden form for adding items -->
<form method="POST" id="addItemForm">
  <input type="hidden" name="item_code" id="itemCodeInput">
  <input type="hidden" name="quantity" id="quantityInput" value="1">
  <input type="hidden" name="add_item" value="1">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  const barcodeInput = document.getElementById('barcodeInput');
  const scanBtn = document.getElementById('scanBtn');
  const statusIndicator = document.getElementById('statusIndicator');
  const statusText = document.getElementById('statusText');
  const addItemForm = document.getElementById('addItemForm');
  const itemCodeInput = document.getElementById('itemCodeInput');
  const quantityInput = document.getElementById('quantityInput');
  const searchItems = document.querySelectorAll('.search-item');
  const setFocusForms = document.querySelectorAll('.set-focus-form');

  // Focus on barcode input on page load
  barcodeInput.focus();

  // Handle barcode scanning
  barcodeInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      handleBarcodeInput();
    }
  });

  scanBtn.addEventListener('click', handleBarcodeInput);

  function handleBarcodeInput() {
    const barcode = barcodeInput.value.trim();
    if (barcode) {
      // Simulate scanning
      statusIndicator.className = 'status-indicator status-scanning';
      statusText.textContent = 'Scanning...';
      
      setTimeout(() => {
        // Add item to sale
        itemCodeInput.value = barcode;
        quantityInput.value = 1;
        addItemForm.submit();
        
        // Clear the input after submission
        barcodeInput.value = '';
        
        // Reset status after a delay
        setTimeout(() => {
          statusIndicator.className = 'status-indicator status-ready';
          statusText.textContent = 'Ready to scan';
        }, 1000);
      }, 500);
    }
  }

  // Handle search item clicks
  searchItems.forEach(item => {
    item.addEventListener('click', function() {
      const code = this.getAttribute('data-code');
      const name = this.getAttribute('data-name');
      const price = this.getAttribute('data-price');
      const barcode = this.getAttribute('data-barcode');
      
      // Add item to sale
      itemCodeInput.value = code;
      quantityInput.value = 1;
      addItemForm.submit();
    });
  });

  // Handle set focus forms
  setFocusForms.forEach(form => {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      this.submit();
    });
  });

  // Keyboard navigation for items
  document.addEventListener('keydown', function(e) {
    const items = <?= json_encode($_SESSION['sale_items'] ?? []) ?>;
    const currentFocus = <?= $_SESSION['current_focus_index'] ?? -1 ?>;
    
    if (items.length === 0) return;
    
    // Arrow down - move to next item
    if (e.key === 'ArrowDown' && currentFocus < items.length - 1) {
      e.preventDefault();
      const newFocus = currentFocus + 1;
      document.querySelector(`input[name="set_focus_index"][value="${newFocus}"]`).closest('form').submit();
    }
    
    // Arrow up - move to previous item
    if (e.key === 'ArrowUp' && currentFocus > 0) {
      e.preventDefault();
      const newFocus = currentFocus - 1;
      document.querySelector(`input[name="set_focus_index"][value="${newFocus}"]`).closest('form').submit();
    }
    
    // Plus key - increase quantity of focused item
    if ((e.key === '+' || e.key === '=') && currentFocus >= 0) {
      e.preventDefault();
      const itemCode = items[currentFocus].code;
      const quantityInput = document.querySelector(`input[name="item_code"][value="${itemCode}"]`)
        .closest('form')
        .querySelector('input[name="quantity"]');
      quantityInput.value = parseInt(quantityInput.value) + 1;
      document.querySelector(`input[name="item_code"][value="${itemCode}"]`).closest('form').submit();
    }
    
    // Minus key - decrease quantity of focused item
    if ((e.key === '-' || e.key === '_') && currentFocus >= 0) {
      e.preventDefault();
      const itemCode = items[currentFocus].code;
      const quantityInput = document.querySelector(`input[name="item_code"][value="${itemCode}"]`)
        .closest('form')
        .querySelector('input[name="quantity"]');
      const newQuantity = parseInt(quantityInput.value) - 1;
      if (newQuantity >= 1) {
        quantityInput.value = newQuantity;
        document.querySelector(`input[name="item_code"][value="${itemCode}"]`).closest('form').submit();
      }
    }
  });

  // Auto-focus on barcode input after actions
  <?php if (isset($_POST['add_item']) || isset($_POST['remove_item']) || isset($_POST['process_sale']) || isset($_POST['clear_sale']) || isset($_POST['update_quantity'])): ?>
    window.onload = function() {
      document.getElementById('barcodeInput').focus();
    };
  <?php endif; ?>
});
</script>
</body>
</html>