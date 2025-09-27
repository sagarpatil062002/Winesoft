<?php
session_start();

// Include volume limit utilities
include_once "volume_limit_utils.php";

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

// Get company ID and stock column names
$comp_id = $_SESSION['CompID'];
$fin_year_id = $_SESSION['FIN_YEAR_ID'];
$daily_stock_table = "tbldailystock_" . $comp_id;
$opening_stock_column = "Opening_Stock" . $comp_id;
$current_stock_column = "Current_Stock" . $comp_id;

// Check if the stock columns exist, if not create them
$check_column_query = "SHOW COLUMNS FROM tblitem_stock LIKE '$current_stock_column'";
$column_result = $conn->query($check_column_query);

if ($column_result->num_rows == 0) {
    $alter_query = "ALTER TABLE tblitem_stock 
                    ADD COLUMN $opening_stock_column DECIMAL(10,3) DEFAULT 0.000,
                    ADD COLUMN $current_stock_column DECIMAL(10,3) DEFAULT 0.000";
    if (!$conn->query($alter_query)) {
        die("Error creating stock columns: " . $conn->error);
    }
}
$column_result->close();

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
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle adding item from search results
    if (isset($_POST['add_from_search'])) {
        $item_code = $_POST['item_code'];
        $quantity = intval($_POST['quantity']);
        
        // Fetch item details
        $item_query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE 
                      FROM tblitemmaster 
                      WHERE CODE = ? 
                      LIMIT 1";
        $item_stmt = $conn->prepare($item_query);
        $item_stmt->bind_param("s", $item_code);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        
        if ($item_result->num_rows > 0) {
            $item_data = $item_result->fetch_assoc();
            
            // Generate unique ID for this specific item entry
            $unique_id = uniqid();
            
            // Add new item to sale
            $_SESSION['sale_items'][] = [
                'id' => $unique_id,
                'code' => $item_data['CODE'],
                'name' => $item_data['DETAILS'],
                'size' => $item_data['DETAILS2'],
                'price' => floatval($item_data['RPRICE']),
                'quantity' => $quantity
            ];
            
            $_SESSION['sale_count'] = count($_SESSION['sale_items']);
            $_SESSION['last_added_item'] = $item_data['DETAILS'];
            
            // Auto-save after 10 items
            if ($_SESSION['sale_count'] >= 10) {
                processSale();
                $_SESSION['sale_items'] = [];
                $_SESSION['sale_count'] = 0;
                $_SESSION['current_focus_index'] = -1;
                $_SESSION['success_message'] = "Sale processed automatically after 10 items! Starting new sale.";
            }
        } else {
            $_SESSION['error_message'] = "Item not found!";
        }
        $item_stmt->close();
        
        // Redirect to avoid form resubmission
        header("Location: barcode_sale.php");
        exit;
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
            
            // Generate unique ID for this specific item entry
            $unique_id = uniqid();
            
            // Add new item to sale (always as separate record)
            $_SESSION['sale_items'][] = [
                'id' => $unique_id,
                'code' => $item_data['CODE'],
                'name' => $item_data['DETAILS'],
                'size' => $item_data['DETAILS2'],
                'price' => floatval($item_data['RPRICE']),
                'quantity' => $quantity
            ];
            
            $_SESSION['sale_count'] = count($_SESSION['sale_items']);
            
            // Auto-save after 10 items
            if ($_SESSION['sale_count'] >= 10) {
                processSale();
                $_SESSION['sale_items'] = [];
                $_SESSION['sale_count'] = 0;
                $_SESSION['current_focus_index'] = -1;
                $_SESSION['success_message'] = "Sale processed automatically after 10 items! Starting new sale.";
            } else {
                // No success message for individual item addition - just add to session
                $_SESSION['last_added_item'] = $item_data['DETAILS'];
            }
        } else {
            $_SESSION['error_message'] = "Item with barcode/code '$item_code' not found!";
        }
        $item_stmt->close();
        
        // Redirect to avoid form resubmission
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle ESC key press to process sale
    if (isset($_POST['process_sale_esc'])) {
        processSale();
        header("Location: barcode_sale.php");
        exit;
    }
}

// Get selected customer from session if available
$selectedCustomer = isset($_SESSION['selected_customer']) ? $_SESSION['selected_customer'] : '';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch items from tblitemmaster for barcode scanning
$query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE 
          FROM tblitemmaster 
          WHERE 1=1";
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
    $_SESSION['current_focus_index'] = -1;
}

// Handle form submissions for items
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle other actions (with redirect)
    if (isset($_POST['update_quantity'])) {
        $item_id = $_POST['item_id'];
        $quantity = intval($_POST['quantity']);
        
        foreach ($_SESSION['sale_items'] as $index => $item) {
            if ($item['id'] === $item_id) {
                $_SESSION['sale_items'][$index]['quantity'] = $quantity;
                break;
            }
        }
        
        header("Location: barcode_sale.php");
        exit;
    }
    
   if (isset($_POST['remove_item'])) {
    $item_id = $_POST['item_id'];
    
    foreach ($_SESSION['sale_items'] as $index => $item) {
        if ($item['id'] === $item_id) {
            unset($_SESSION['sale_items'][$index]);
            $_SESSION['sale_items'] = array_values($_SESSION['sale_items']);
            $_SESSION['sale_count'] = count($_SESSION['sale_items']);
            
            if ($_SESSION['current_focus_index'] >= $index) {
                $_SESSION['current_focus_index'] = max(-1, $_SESSION['current_focus_index'] - 1);
            }
            break;
        }
    }
    
    header("Location: barcode_sale.php");
    exit;
}
    
    if (isset($_POST['process_sale'])) {
        processSale();
        header("Location: barcode_sale.php");
        exit;
    }
    
    if (isset($_POST['preview_bill'])) {
        $_SESSION['preview_bill_data'] = prepareBillData();
        header("Location: barcode_sale.php#preview");
        exit;
    }
    
    if (isset($_POST['clear_sale'])) {
        $_SESSION['sale_items'] = [];
        $_SESSION['sale_count'] = 0;
        $_SESSION['current_focus_index'] = -1;
        unset($_SESSION['preview_bill_data']);
        header("Location: barcode_sale.php");
        exit;
    }
    
    if (isset($_POST['clear_preview'])) {
        unset($_SESSION['preview_bill_data']);
        header("Location: barcode_sale.php");
        exit;
    }
    
    if (isset($_POST['set_focus_index'])) {
        $_SESSION['current_focus_index'] = intval($_POST['set_focus_index']);
        header("Location: barcode_sale.php");
        exit;
    }
}

// Function to prepare bill data for preview with volume-based splitting
// Function to prepare bill data for preview with volume-based splitting and duplicate aggregation
function prepareBillData() {
    global $conn, $comp_id, $selectedCustomer, $customers;
    
    if (empty($_SESSION['sale_items'])) {
        return null;
    }
    
    $user_id = $_SESSION['user_id'];
    $mode = 'F';
    
    // First aggregate duplicate items from session
    $aggregated_session_items = [];
    foreach ($_SESSION['sale_items'] as $item) {
        $item_code = $item['code'];
        if (!isset($aggregated_session_items[$item_code])) {
            $aggregated_session_items[$item_code] = [
                'code' => $item['code'],
                'name' => $item['name'],
                'quantity' => 0,
                'price' => $item['price'],
                'size' => $item['size'] // Use the size from session
            ];
        }
        $aggregated_session_items[$item_code]['quantity'] += $item['quantity'];
    }
    $aggregated_session_items = array_values($aggregated_session_items);

    $category_limits = getCategoryLimits($conn, $comp_id);
    $category_items = [];

    foreach ($aggregated_session_items as $item) {
        $category = getItemCategory($conn, $item['code'], $mode);
        $size = getItemSize($conn, $item['code'], $mode);
        $volume = $item['quantity'] * $size;
        
        if (!isset($category_items[$category])) {
            $category_items[$category] = [];
        }
        
        $category_items[$category][] = [
            'code' => $item['code'],
            'qty' => $item['quantity'],
            'rate' => $item['price'],
            'size' => $size,
            'size_text' => $item['size'], // Use the text size from details2
            'volume' => $volume,
            'amount' => $item['quantity'] * $item['price'],
            'name' => $item['name']
        ];
    }
    
    // Calculate how many bills will be generated
    $all_bills = [];
    $total_bills_needed = 0;
    
    foreach ($category_items as $category => $items) {
        $limit = $category_limits[$category] ?? 0;
        
        if ($limit <= 0) {
            if (!empty($items)) {
                $all_bills[] = [
                    'category' => $category,
                    'items' => $items,
                    'total_volume' => array_sum(array_column($items, 'volume')),
                    'total_amount' => array_sum(array_column($items, 'amount'))
                ];
                $total_bills_needed++;
            }
        } else {
            $total_volume = array_sum(array_column($items, 'volume'));
            $bills_for_category = ceil($total_volume / $limit);
            
            // Sort items by volume (largest first for efficient packing)
            usort($items, function($a, $b) {
                return $b['volume'] <=> $a['volume'];
            });
            
            $bills_for_category_arr = [];
            $current_bill_items = [];
            $current_bill_volume = 0;
            
            foreach ($items as $item) {
                // If adding this item would exceed limit and we already have items, start new bill
                if ($current_bill_volume + $item['volume'] > $limit && !empty($current_bill_items)) {
                    $bills_for_category_arr[] = [
                        'items' => $current_bill_items,
                        'volume' => $current_bill_volume,
                        'amount' => array_sum(array_column($current_bill_items, 'amount'))
                    ];
                    $current_bill_items = [];
                    $current_bill_volume = 0;
                }
                
                $current_bill_items[] = $item;
                $current_bill_volume += $item['volume'];
            }
            
            if (!empty($current_bill_items)) {
                $bills_for_category_arr[] = [
                    'items' => $current_bill_items,
                    'volume' => $current_bill_volume,
                    'amount' => array_sum(array_column($current_bill_items, 'amount'))
                ];
            }
            
            foreach ($bills_for_category_arr as $bill_index => $bill_items) {
                $all_bills[] = [
                    'category' => $category,
                    'bill_index' => $bill_index + 1,
                    'items' => $bill_items['items'],
                    'total_volume' => $bill_items['volume'],
                    'total_amount' => $bill_items['amount']
                ];
            }
            $total_bills_needed += count($bills_for_category_arr);
        }
    }
    
    $customer_name = !empty($selectedCustomer) && isset($customers[$selectedCustomer]) ? $customers[$selectedCustomer] : 'Walk-in Customer';
    
    return [
        'customer_id' => $selectedCustomer,
        'customer_name' => $customer_name,
        'bill_date' => date('Y-m-d H:i:s'),
        'total_bills' => $total_bills_needed,
        'bills' => $all_bills,
        'grand_total' => array_sum(array_column($all_bills, 'total_amount'))
    ];
}
// Function to generate a unique bill number with transaction safety
function generateBillNumber($conn, $comp_id) {
    $conn->begin_transaction();
    
    try {
        $bill_query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill 
                       FROM tblsaleheader 
                       WHERE COMP_ID = ? 
                       FOR UPDATE";
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
        
        $conn->commit();
return "BL" . $next_bill;
    } catch (Exception $e) {
        $conn->rollback();
        $timestamp = time();
        $random_suffix = mt_rand(100, 999);
        return "BL" . substr($timestamp, -6) . $random_suffix;
    }
}

// Function to update item stock (NEW LOGIC from sale_for_date_range.php)
function updateItemStock($conn, $item_code, $qty, $current_stock_column, $opening_stock_column, $fin_year_id) {
    // Check if record exists first
    $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_stock_query);
    $check_stmt->bind_param("s", $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $stock_exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($stock_exists) {
        // Update existing stock record
        $stock_query = "UPDATE tblitem_stock SET $current_stock_column = $current_stock_column - ? WHERE ITEM_CODE = ?";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("ds", $qty, $item_code);
        $stock_stmt->execute();
        $stock_stmt->close();
    } else {
        // Create new stock record
        $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_stock_column, $current_stock_column) 
                               VALUES (?, ?, ?, ?)";
        $insert_stock_stmt = $conn->prepare($insert_stock_query);
        $current_stock = -$qty; // Negative since we're deducting
        $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $current_stock, $current_stock);
        $insert_stock_stmt->execute();
        $insert_stock_stmt->close();
    }
}

// Function to update daily stock table with proper opening/closing calculations (NEW LOGIC)
function updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id) {
    // Extract day number from date (e.g., 2025-09-03 -> day 03)
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $sales_column = "DAY_{$day_num}_SALES";
    $closing_column = "DAY_{$day_num}_CLOSING";
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    
    $month_year = date('Y-m', strtotime($sale_date));
    
    // Check if record exists for this month and item
    $check_query = "SELECT COUNT(*) as count FROM $daily_stock_table 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $month_year, $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Get current values to calculate closing properly
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
        
        // Update existing record with correct closing calculation
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
                // Update next day's opening to match current day's closing
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
        // For new records, opening and purchase are typically 0 unless specified otherwise
        $closing = 0 - $qty; // Since opening and purchase are 0
        
        // Create new record
        $insert_query = "INSERT INTO $daily_stock_table 
                         (STK_MONTH, ITEM_CODE, LIQ_FLAG, $opening_column, $purchase_column, $sales_column, $closing_column) 
                         VALUES (?, ?, 'F', 0, 0, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssdd", $month_year, $item_code, $qty, $closing);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
}

// Function to process the sale
function processSale() {
    global $conn, $comp_id, $current_stock_column, $opening_stock_column, $daily_stock_table, $fin_year_id, $selectedCustomer, $customers;
    
    if (!empty($_SESSION['sale_items'])) {
        $user_id = $_SESSION['user_id'];
        $mode = 'F';
        
        $conn->begin_transaction();
        
        try {
            $category_limits = getCategoryLimits($conn, $comp_id);
            $category_items = [];
            
            foreach ($_SESSION['sale_items'] as $item) {
                $category = getItemCategory($conn, $item['code'], $mode);
                $size = getItemSize($conn, $item['code'], $mode);
                $volume = $item['quantity'] * $size;
                
                if (!isset($category_items[$category])) {
                    $category_items[$category] = [];
                }
                
                $category_items[$category][] = [
                    'code' => $item['code'],
                    'qty' => $item['quantity'],
                    'rate' => $item['price'],
                    'size' => $size,
                    'volume' => $volume,
                    'amount' => $item['quantity'] * $item['price'],
                    'name' => $item['name'],
                    'details2' => $item['size']
                ];
            }
            
            $total_bills_needed = 0;
            foreach ($category_items as $category => $items) {
                $limit = $category_limits[$category] ?? 0;
                if ($limit <= 0) {
                    if (!empty($items)) $total_bills_needed++;
                } else {
                    $total_volume = array_sum(array_column($items, 'volume'));
                    $bills_for_category = ceil($total_volume / $limit);
                    $total_bills_needed += $bills_for_category;
                }
            }
            
            $bill_numbers = [];
            for ($i = 0; $i < $total_bills_needed; $i++) {
                $bill_numbers[] = generateBillNumber($conn, $comp_id);
            }
            
            $bill_index = 0;
            $all_bills = [];
            
            foreach ($category_items as $category => $items) {
                $limit = $category_limits[$category] ?? 0;
                
                if ($limit <= 0) {
                    if (!empty($items)) {
                        $all_bills[] = [
                            'bill_no' => $bill_numbers[$bill_index++],
                            'bill_date' => date('Y-m-d H:i:s'),
                            'total_amount' => array_sum(array_column($items, 'amount')),
                            'items' => $items,
                            'mode' => $mode,
                            'comp_id' => $comp_id,
                            'user_id' => $user_id
                        ];
                    }
                } else {
                    usort($items, function($a, $b) {
                        return $b['volume'] <=> $a['volume'];
                    });
                    
                    $bills_for_category = [];
                    $current_bill_items = [];
                    $current_bill_volume = 0;
                    
                    foreach ($items as $item) {
                        if ($current_bill_volume + $item['volume'] > $limit && !empty($current_bill_items)) {
                            $bills_for_category[] = $current_bill_items;
                            $current_bill_items = [];
                            $current_bill_volume = 0;
                        }
                        
                        $current_bill_items[] = $item;
                        $current_bill_volume += $item['volume'];
                    }
                    
                    if (!empty($current_bill_items)) {
                        $bills_for_category[] = $current_bill_items;
                    }
                    
                    foreach ($bills_for_category as $bill_items) {
                        $all_bills[] = [
                            'bill_no' => $bill_numbers[$bill_index++],
                            'bill_date' => date('Y-m-d H:i:s'),
                            'total_amount' => array_sum(array_column($bill_items, 'amount')),
                            'items' => $bill_items,
                            'mode' => $mode,
                            'comp_id' => $comp_id,
                            'user_id' => $user_id
                        ];
                    }
                }
            }
            
            $processed_bills = [];
            foreach ($all_bills as $bill) {
                $bill_data = processSingleBill($bill, $conn, $comp_id, $current_stock_column, $opening_stock_column, $daily_stock_table, $fin_year_id, $selectedCustomer, $customers, $user_id, $mode);
                if ($bill_data) {
                    $processed_bills[] = $bill_data;
                }
            }
            
            $conn->commit();
            
            if (!empty($processed_bills)) {
// Don't set last_bill_data for automatic preview
// $_SESSION['last_bill_data'] = $processed_bills;                $_SESSION['success_message'] = "Sale completed successfully! Generated " . count($processed_bills) . " bills.";
            } else {
                throw new Exception("No bills were processed successfully.");
            }
            
            unset($_SESSION['sale_items']);
            unset($_SESSION['sale_count']);
            unset($_SESSION['current_focus_index']);
            unset($_SESSION['selected_customer']);
            unset($_SESSION['preview_bill_data']);
            unset($_SESSION['last_added_item']);
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error processing sale: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "No items to process.";
    }
}

// Function to process a single bill with proper stock management (UPDATED LOGIC)
// Function to process a single bill with proper stock management and duplicate item handling
function processSingleBill($bill, $conn, $comp_id, $current_stock_column, $opening_stock_column, $daily_stock_table, $fin_year_id, $selectedCustomer, $customers, $user_id, $mode) {
    $bill_no = $bill['bill_no'];
    $sale_date = $bill['bill_date'];
    
    // Aggregate quantities for duplicate items before processing
    $aggregated_items = [];
    foreach ($bill['items'] as $item) {
        $item_code = $item['code'];
        if (!isset($aggregated_items[$item_code])) {
            $aggregated_items[$item_code] = [
                'code' => $item_code,
                'name' => $item['name'],
                'details2' => $item['details2'],
                'qty' => 0,
                'rate' => $item['rate'],
                'size' => $item['size'],
                'volume' => 0,
                'amount' => 0
            ];
        }
        $aggregated_items[$item_code]['qty'] += $item['qty'];
        $aggregated_items[$item_code]['volume'] += $item['volume'];
        $aggregated_items[$item_code]['amount'] += $item['amount'];
    }
    
    // Convert back to indexed array
    $aggregated_items = array_values($aggregated_items);
    $total_amount = array_sum(array_column($aggregated_items, 'amount'));
    
    $max_retries = 3;
    $retry_count = 0;
    $customer_id = !empty($selectedCustomer) ? $selectedCustomer : NULL;
    $success = false;
    
    while ($retry_count < $max_retries && !$success) {
        try {
            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, CUSTOMER_ID, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                             VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)";
            $header_stmt = $conn->prepare($header_query);
            $header_stmt->bind_param("sssddssi", $bill_no, $sale_date, $customer_id, $total_amount, $total_amount, $mode, $comp_id, $user_id);
            
            if ($header_stmt->execute()) {
                $success = true;
            }
            
            $header_stmt->close();
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false && $retry_count < $max_retries - 1) {
                $bill_no = generateBillNumber($conn, $comp_id);
                $retry_count++;
                continue;
            } else {
                throw new Exception("Failed to insert sale header after $max_retries attempts: " . $e->getMessage());
            }
        }
    }
    
    if (!$success) {
        throw new Exception("Failed to insert sale header after $max_retries attempts");
    }
    
    // Process aggregated items (no duplicates)
    foreach ($aggregated_items as $item) {
        $amount = $item['rate'] * $item['qty'];
        
        // Insert sale details (now only one record per item)
        $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                         VALUES (?, ?, ?, ?, ?, ?, ?)";
        $detail_stmt = $conn->prepare($detail_query);
        $detail_stmt->bind_param("ssddssi", $bill_no, $item['code'], $item['qty'], $item['rate'], $amount, $mode, $comp_id);
        
        if (!$detail_stmt->execute()) {
            throw new Exception("Failed to insert sale details: " . $detail_stmt->error);
        }
        
        $detail_stmt->close();
        
        // UPDATE STOCK TABLES USING THE NEW LOGIC
        updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
        updateDailyStock($conn, $daily_stock_table, $item['code'], $bill['bill_date'], $item['qty'], $comp_id);
    }
    
    $customer_name = !empty($selectedCustomer) && isset($customers[$selectedCustomer]) ? $customers[$selectedCustomer] : 'Walk-in Customer';
    
    return [
        'bill_no' => $bill_no,
        'customer_id' => $selectedCustomer,
        'customer_name' => $customer_name,
        'bill_date' => $sale_date,
        'items' => $aggregated_items, // Return aggregated items for preview
        'total_amount' => $total_amount,
        'final_amount' => $total_amount
    ];
}
// Check for success/error messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get current stock for items
if (!empty($_SESSION['sale_items'])) {
    foreach ($_SESSION['sale_items'] as &$item) {
        $stock_query = "SELECT COALESCE($current_stock_column, 0) as stock FROM tblitem_stock WHERE ITEM_CODE = ? AND (FIN_YEAR = ? OR FIN_YEAR = '0000') ORDER BY FIN_YEAR DESC LIMIT 1";
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
    unset($item);
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
    .no-barcode {
      color: #6c757d;
      font-style: italic;
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
    .create-customer-btn {
      margin-top: 32px;
    }
    .bill-navigation {
      position: fixed;
      bottom: 20px;
      right: 20px;
      background: white;
      padding: 10px;
      border-radius: 5px;
      box-shadow: 0 0 10px rgba(0,0,0,0.1);
      z-index: 1000;
    }
    
    /* Apply style.css styles specifically to the sale table */
    .sale-table .table-container {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      margin-bottom: 1rem;
      background: var(--white);
      border-radius: var(--border-radius);
      box-shadow: var(--box-shadow);
    }

    .sale-table .styled-table {
      width: 100%;
      min-width: 600px;
      border-collapse: collapse;
      color: var(--text-color);
    }

    .sale-table .styled-table thead tr {
      background-color: var(--secondary-color);
      color: var(--primary-color);
    }

    .sale-table .styled-table th, 
    .sale-table .styled-table td {
      padding: 12px;
      text-align: left;
      border: 1px solid #ddd;
      vertical-align: middle;
    }

    .sale-table .styled-table tbody tr:nth-child(even) {
      background-color: #f9f9f9;
    }

    .sale-table .styled-table tbody tr:hover {
      background-color: #eef6ff;
    }

    .sale-table .table-striped tbody tr:nth-child(odd) {
      background-color: rgba(0,0,0,0.02);
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
      <?php if (isset($_SESSION['last_bill_data']) && is_array($_SESSION['last_bill_data'])): 
        $bills_data = $_SESSION['last_bill_data'];
        $companyName = "WineSoft"; // Replace with actual company name if available
        $current_bill_index = 0;
      ?>
      <div class="modal fade show" id="billPreviewModal" tabindex="-1" aria-labelledby="billPreviewModalLabel" aria-modal="true" style="display: block;">
        <div class="modal-dialog modal-lg">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title" id="billPreviewModalLabel">Bill Preview (<?= count($bills_data) ?> bills generated)</h5>
              <button type="button" class="btn-close" onclick="window.location.href='barcode_sale.php'"></button>
            </div>
            <div class="modal-body">
              <?php foreach ($bills_data as $index => $bill_data): ?>
              <div class="bill-preview <?= $index !== $current_bill_index ? 'd-none' : '' ?>" id="bill-<?= $index ?>">
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
                      <td class="text-right"><?= $item['qty'] ?></td>
                      <td class="text-right"><?= number_format($item['rate'], 2) ?></td>
                      <td class="text-right"><?= number_format($item['rate'] * $item['qty'], 2) ?></td>
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
              </div>
              <?php endforeach; ?>
              
              <?php if (count($bills_data) > 1): ?>
              <div class="bill-navigation">
                <button class="btn btn-sm btn-secondary me-2" onclick="showPrevBill()">Previous</button>
                <span id="bill-counter">Bill 1 of <?= count($bills_data) ?></span>
                <button class="btn btn-sm btn-secondary ms-2" onclick="showNextBill()">Next</button>
              </div>
              <?php endif; ?>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" onclick="window.location.href='barcode_sale.php'">Close</button>
              <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
            </div>
          </div>
          <script>
            let currentBillIndex = 0;
            const totalBills = <?= count($bills_data) ?>;
            
            function showBill(index) {
              // Hide all bills
              document.querySelectorAll('.bill-preview').forEach(bill => {
                bill.classList.add('d-none');
              });
              
              // Show the selected bill
              document.getElementById('bill-' + index).classList.remove('d-none');
              
              // Update counter
              document.getElementById('bill-counter').textContent = 'Bill ' + (index + 1) + ' of ' + totalBills;
              
              currentBillIndex = index;
            }
            
            function showNextBill() {
              if (currentBillIndex < totalBills - 1) {
                showBill(currentBillIndex + 1);
              }
            }
            
            function showPrevBill() {
              if (currentBillIndex > 0) {
                showBill(currentBillIndex - 1);
              }
            }
          </script>
        </div>
      </div>
      <div class="modal-backdrop fade show"></div>
      <?php 
        // Clear the bill data after displaying
        unset($_SESSION['last_bill_data']);
      endif; ?>

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
                  <select class="form-select" id="customer_field" name="customer_field" style="width: 100%;">
                    <option value=""></option>
                    <?php foreach ($customers as $code => $name): ?>
                      <option value="<?= $code ?>" <?= $selectedCustomer == $code ? 'selected' : '' ?>>
                        <?= htmlspecialchars($name) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="customer-hint">
                    <i class="fas fa-info-circle"></i> 
                    Select existing customer or type "new: Customer Name" to create new customer. 
                    Leave empty for walk-in customer.
                  </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3">
                  <i class="fas fa-save"></i> Save Customer
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>


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
          <div class="table-container">
            <table class="styled-table table-striped">
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
                    <td><?= isset($item['current_stock']) ? $item['current_stock'] : 'N/A' ?></td>
                    <td>
                      <form method="POST" style="display:inline;">
<input type="hidden" name="item_id" value="<?= $item['id'] ?>"> 
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
  
  <!-- Add Preview Button -->
  <form method="POST" class="me-2">
    <button type="submit" name="preview_bill" class="btn btn-info">
      <i class="fas fa-eye me-1"></i> Preview Bill
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

      <!-- Bill Preview Section - MOVED TO HERE -->
      <?php if (isset($_SESSION['preview_bill_data'])): 
        $preview_data = $_SESSION['preview_bill_data'];
      ?>
      <div class="card mt-4" id="preview">
        <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
          <h5 class="mb-0"><i class="fas fa-eye me-2"></i>Bill Preview</h5>
          <form method="POST">
            <button type="submit" name="clear_preview" class="btn btn-sm btn-light">
              <i class="fas fa-times me-1"></i> Close Preview
            </button>
          </form>
        </div>
        <div class="card-body">
          <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            This preview shows how the sale will be split into <?= $preview_data['total_bills'] ?> bill(s).
          </div>
          
          <?php foreach ($preview_data['bills'] as $bill_index => $bill): ?>
          <div class="bill-preview mb-4 p-3 border rounded">
            <div class="bill-header text-center mb-3">
              <h4>WineSoft</h4>
              <p class="mb-1"><strong>Bill #<?= $bill_index + 1 ?></strong></p>
              <p class="mb-0">Customer: <?= $preview_data['customer_name'] ?></p>
              <p class="mb-0">Date: <?= date('d/m/Y H:i', strtotime($preview_data['bill_date'])) ?></p>
            </div>
            
            <table class="table table-bordered table-sm">
              <thead class="table-light">
                <tr>
                  <th>Item Name</th>
                  <th class="text-center">Size</th>
                  <th class="text-center">Qty</th>
                  <th class="text-center">Rate</th>
                  <th class="text-center">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bill['items'] as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['name']) ?></td>
                  <td class="text-center"><?= htmlspecialchars($item['size_text']) ?></td>
                  <td class="text-center"><?= $item['qty'] ?></td>
                  <td class="text-center">₹<?= number_format($item['rate'], 2) ?></td>
                  <td class="text-center">₹<?= number_format($item['amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
              <tfoot class="table-light">
                <tr>
                  <td colspan="4" class="text-end"><strong>Total:</strong></td>
                  <td class="text-center"><strong>₹<?= number_format($bill['total_amount'], 2) ?></strong></td>
                </tr>
              </tfoot>
            </table>
          </div>
          <?php endforeach; ?>
          
          <div class="alert alert-success text-center">
            <h5>Grand Total: ₹<?= number_format($preview_data['grand_total'], 2) ?></h5>
            <p class="mb-0">Total Bills: <?= $preview_data['total_bills'] ?></p>
          </div>
          
          <div class="d-flex justify-content-end">
            <form method="POST">
              <button type="submit" name="process_sale" class="btn btn-success btn-lg">
                <i class="fas fa-check me-1"></i> Confirm & Process Sale
              </button>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>
      
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

  // Create a hidden form for ESC key processing
  const escForm = document.createElement('form');
  escForm.method = 'POST';
  escForm.style.display = 'none';
  
  const escInput = document.createElement('input');
  escInput.type = 'hidden';
  escInput.name = 'process_sale_esc';
  escInput.value = '1';
  
  escForm.appendChild(escInput);
  document.body.appendChild(escForm);

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
    
    // ESC key - process sale
    if (e.key === 'Escape') {
      e.preventDefault();
      
      // Show confirmation dialog
      if (confirm('Are you sure you want to process the sale?')) {
        escForm.submit();
      }
      return;
    }
    
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