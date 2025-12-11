<?php
session_start();

// Include volume limit utilities
include_once "volume_limit_utils.php";
// Include license functions
require_once 'license_functions.php';
// ADDED: Include cash memo functions
require_once 'cash_memo_functions.php';

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
include_once "stock_functions.php";

// Generate form token to prevent duplicate submissions
if (!isset($_SESSION['form_token'])) {
    $_SESSION['form_token'] = bin2hex(random_bytes(32));
}

// Get company ID and stock column names
$comp_id = $_SESSION['CompID'];
$fin_year_id = $_SESSION['FIN_YEAR_ID'];
$daily_stock_table = "tbldailystock_" . $comp_id;
$opening_stock_column = "Opening_Stock" . $comp_id;
$current_stock_column = "Current_Stock" . $comp_id;

// Get company's license type and available classes for item filtering
$license_type = getCompanyLicenseType($comp_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

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

// Initialize sale items session if not exists
if (!isset($_SESSION['sale_items'])) {
    $_SESSION['sale_items'] = [];
    $_SESSION['sale_count'] = 0;
    $_SESSION['current_focus_index'] = -1;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle adding item to sale
    if (isset($_POST['add_item'])) {
        // Check form token to prevent duplicate submissions
        if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
            $_SESSION['error_message'] = "Invalid form submission. Please try again.";
            header("Location: barcode_sale.php");
            exit;
        }
        
        // Prevent duplicate submissions using session token
        $item_code = $_POST['item_code'];
        $tokenKey = 'last_item_' . $item_code . '_' . time();
        
        // If we processed this same item within last 2 seconds, skip
        if (isset($_SESSION['last_item_processed']) && 
            $_SESSION['last_item_processed']['code'] == $item_code &&
            (time() - $_SESSION['last_item_processed']['time']) < 2) {
            
            $_SESSION['error_message'] = "Item already being processed. Please wait.";
            header("Location: barcode_sale.php");
            exit;
        }
        
        // Store processing info
        $_SESSION['last_item_processed'] = [
            'code' => $item_code,
            'time' => time()
        ];
        
        $quantity = intval($_POST['quantity']);
        
        // Fetch item details with license restriction check - search by BARCODE first, then by CODE
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
            $item_query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE, CLASS 
                          FROM tblitemmaster 
                          WHERE (BARCODE = ? OR CODE = ?)
                          AND CLASS IN ($class_placeholders)
                          LIMIT 1";
            $item_stmt = $conn->prepare($item_query);
            
            // Bind parameters: barcode, code + all allowed classes
            $params = array_merge([$item_code, $item_code], $allowed_classes);
            $types = str_repeat('s', count($params));
            $item_stmt->bind_param($types, ...$params);
        } else {
            // If no classes allowed, show empty result
            $item_query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE, CLASS 
                          FROM tblitemmaster 
                          WHERE 1 = 0
                          LIMIT 1";
            $item_stmt = $conn->prepare($item_query);
        }
        
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        
        if ($item_result->num_rows > 0) {
            $item_data = $item_result->fetch_assoc();
            
            // Check stock availability BEFORE adding item
            $stock_check_query = "SELECT COALESCE($current_stock_column, 0) as stock
                                  FROM tblitem_stock
                                  WHERE ITEM_CODE = ?
                                  AND (FIN_YEAR = ? OR FIN_YEAR = '0000')
                                  ORDER BY FIN_YEAR DESC LIMIT 1";
            $stock_check_stmt = $conn->prepare($stock_check_query);
            $stock_check_stmt->bind_param("ss", $item_data['CODE'], $fin_year_id);
            $stock_check_stmt->execute();
            $stock_check_result = $stock_check_stmt->get_result();
            
            $current_stock = 0;
            if ($stock_check_result->num_rows > 0) {
                $stock_data = $stock_check_result->fetch_assoc();
                $current_stock = floatval($stock_data['stock']);
            }
            $stock_check_stmt->close();
            
            // Check if there's ANY stock available
            if ($current_stock <= 0) {
                $_SESSION['error_message'] = "No stock available for item '{$item_data['DETAILS']}'!";
            } else {
                // Count how many of this item are already in the sale
                $total_quantity_in_sale = 0;
                if (isset($_SESSION['sale_items'])) {
                    foreach ($_SESSION['sale_items'] as $item) {
                        if ($item['code'] === $item_data['CODE']) {
                            $total_quantity_in_sale += $item['quantity'];
                        }
                    }
                }
                
                // Check if adding this new item would exceed available stock
                $total_requested = $total_quantity_in_sale + $quantity;
                
                if ($total_requested > $current_stock) {
                    $_SESSION['error_message'] = "Insufficient stock for item '{$item_data['DETAILS']}'. Available: $current_stock, Already in sale: $total_quantity_in_sale";
                } else {
                    // Generate unique ID for this specific item entry
                    $unique_id = uniqid() . '_' . time() . '_' . mt_rand(1000, 9999);
                    
                    // Add new item to sale (ALWAYS as separate record, even if same item)
                    $_SESSION['sale_items'][] = [
                        'id' => $unique_id,
                        'code' => $item_data['CODE'],
                        'name' => $item_data['DETAILS'],
                        'size' => $item_data['DETAILS2'],
                        'price' => floatval($item_data['RPRICE']),
                        'quantity' => $quantity,
                        'current_stock' => $current_stock // Store current stock for display
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
                }
            }
        } else {
            $_SESSION['error_message'] = "Item with barcode/code '$item_code' not found or not allowed for your license type!";
        }
        $item_stmt->close();
        
        // Regenerate token after successful processing
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        
        // Redirect to avoid form resubmission
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle adding item from search results
    if (isset($_POST['add_from_search'])) {
        // Check form token
        if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
            $_SESSION['error_message'] = "Invalid form submission. Please try again.";
            header("Location: barcode_sale.php");
            exit;
        }
        
        $item_code = $_POST['item_code'];
        $quantity = intval($_POST['quantity']);
        
        // Prevent duplicate submissions using session token
        $tokenKey = 'last_item_' . $item_code . '_' . time();
        
        // If we processed this same item within last 2 seconds, skip
        if (isset($_SESSION['last_item_processed']) && 
            $_SESSION['last_item_processed']['code'] == $item_code &&
            (time() - $_SESSION['last_item_processed']['time']) < 2) {
            
            $_SESSION['error_message'] = "Item already being processed. Please wait.";
            header("Location: barcode_sale.php");
            exit;
        }
        
        // Store processing info
        $_SESSION['last_item_processed'] = [
            'code' => $item_code,
            'time' => time()
        ];
        
        // Fetch item details with license restriction check
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
            $item_query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE, CLASS 
                          FROM tblitemmaster 
                          WHERE CODE = ? 
                          AND CLASS IN ($class_placeholders)
                          LIMIT 1";
            $item_stmt = $conn->prepare($item_query);
            
            // Bind parameters: item code + all allowed classes
            $params = array_merge([$item_code], $allowed_classes);
            $types = str_repeat('s', count($params));
            $item_stmt->bind_param($types, ...$params);
        } else {
            // If no classes allowed, show empty result
            $item_query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE, CLASS 
                          FROM tblitemmaster 
                          WHERE 1 = 0
                          LIMIT 1";
            $item_stmt = $conn->prepare($item_query);
        }
        
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        
        if ($item_result->num_rows > 0) {
            $item_data = $item_result->fetch_assoc();
            
            // Check stock availability BEFORE adding
            $stock_check_query = "SELECT COALESCE($current_stock_column, 0) as stock
                                  FROM tblitem_stock
                                  WHERE ITEM_CODE = ?
                                  AND (FIN_YEAR = ? OR FIN_YEAR = '0000')
                                  ORDER BY FIN_YEAR DESC LIMIT 1";
            $stock_check_stmt = $conn->prepare($stock_check_query);
            $stock_check_stmt->bind_param("ss", $item_data['CODE'], $fin_year_id);
            $stock_check_stmt->execute();
            $stock_check_result = $stock_check_stmt->get_result();
            
            $current_stock = 0;
            if ($stock_check_result->num_rows > 0) {
                $stock_data = $stock_check_result->fetch_assoc();
                $current_stock = floatval($stock_data['stock']);
            }
            $stock_check_stmt->close();
            
            // Check if there's ANY stock available
            if ($current_stock <= 0) {
                $_SESSION['error_message'] = "No stock available for item '{$item_data['DETAILS']}'!";
            } else {
                // Count how many of this item are already in the sale
                $total_quantity_in_sale = 0;
                if (isset($_SESSION['sale_items'])) {
                    foreach ($_SESSION['sale_items'] as $item) {
                        if ($item['code'] === $item_data['CODE']) {
                            $total_quantity_in_sale += $item['quantity'];
                        }
                    }
                }
                
                // Check if adding this new item would exceed available stock
                $total_requested = $total_quantity_in_sale + $quantity;
                
                if ($total_requested > $current_stock) {
                    $_SESSION['error_message'] = "Insufficient stock for item '{$item_data['DETAILS']}'. Available: $current_stock, Already in sale: $total_quantity_in_sale";
                } else {
                    // Generate unique ID for this specific item entry
                    $unique_id = uniqid() . '_' . time() . '_' . mt_rand(1000, 9999);
                    
                    // Add new item to sale (always as separate record)
                    $_SESSION['sale_items'][] = [
                        'id' => $unique_id,
                        'code' => $item_data['CODE'],
                        'name' => $item_data['DETAILS'],
                        'size' => $item_data['DETAILS2'],
                        'price' => floatval($item_data['RPRICE']),
                        'quantity' => $quantity,
                        'current_stock' => $current_stock
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
                }
            }
        } else {
            $_SESSION['error_message'] = "Item not found or not allowed for your license type!";
        }
        $item_stmt->close();
        
        // Regenerate token
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        
        // Redirect to avoid form resubmission
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle adding multiple items from pending barcodes
    if (isset($_POST['add_multiple_items'])) {
        // Check form token
        if (!isset($_POST['form_token']) || $_POST['form_token'] !== $_SESSION['form_token']) {
            $_SESSION['error_message'] = "Invalid form submission. Please try again.";
            header("Location: barcode_sale.php");
            exit;
        }
        
        $barcodes = json_decode($_POST['barcodes'], true);
        foreach ($barcodes as $item_code) {
            $quantity = 1; // Default quantity for scanned barcodes

            // Fetch item details with license restriction check - search by BARCODE first, then by CODE
            if (!empty($allowed_classes)) {
                $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
                $item_query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE, CLASS
                              FROM tblitemmaster
                              WHERE (BARCODE = ? OR CODE = ?)
                              AND CLASS IN ($class_placeholders)
                              LIMIT 1";
                $item_stmt = $conn->prepare($item_query);

                // Bind parameters: barcode, code + all allowed classes
                $params = array_merge([$item_code, $item_code], $allowed_classes);
                $types = str_repeat('s', count($params));
                $item_stmt->bind_param($types, ...$params);
            } else {
                // If no classes allowed, show empty result
                $item_query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE, CLASS
                              FROM tblitemmaster
                              WHERE 1 = 0
                              LIMIT 1";
                $item_stmt = $conn->prepare($item_query);
            }

            $item_stmt->execute();
            $item_result = $item_stmt->get_result();

            if ($item_result->num_rows > 0) {
                $item_data = $item_result->fetch_assoc();
                
                // Check stock availability BEFORE adding
                $stock_check_query = "SELECT COALESCE($current_stock_column, 0) as stock
                                      FROM tblitem_stock
                                      WHERE ITEM_CODE = ?
                                      AND (FIN_YEAR = ? OR FIN_YEAR = '0000')
                                      ORDER BY FIN_YEAR DESC LIMIT 1";
                $stock_check_stmt = $conn->prepare($stock_check_query);
                $stock_check_stmt->bind_param("ss", $item_data['CODE'], $fin_year_id);
                $stock_check_stmt->execute();
                $stock_check_result = $stock_check_stmt->get_result();
                
                $current_stock = 0;
                if ($stock_check_result->num_rows > 0) {
                    $stock_data = $stock_check_result->fetch_assoc();
                    $current_stock = floatval($stock_data['stock']);
                }
                $stock_check_stmt->close();
                
                // Check if there's ANY stock available
                if ($current_stock > 0) {
                    // Count how many of this item are already in the sale
                    $total_quantity_in_sale = 0;
                    if (isset($_SESSION['sale_items'])) {
                        foreach ($_SESSION['sale_items'] as $item) {
                            if ($item['code'] === $item_data['CODE']) {
                                $total_quantity_in_sale += $item['quantity'];
                            }
                        }
                    }
                    
                    // Check if adding this new item would exceed available stock
                    $total_requested = $total_quantity_in_sale + $quantity;
                    
                    if ($total_requested <= $current_stock) {
                        // Generate unique ID for this specific item entry
                        $unique_id = uniqid() . '_' . time() . '_' . mt_rand(1000, 9999);

                        // Add new item to sale (always as separate record)
                        $_SESSION['sale_items'][] = [
                            'id' => $unique_id,
                            'code' => $item_data['CODE'],
                            'name' => $item_data['DETAILS'],
                            'size' => $item_data['DETAILS2'],
                            'price' => floatval($item_data['RPRICE']),
                            'quantity' => $quantity,
                            'current_stock' => $current_stock
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
                            // No success message for individual item addition
                            $_SESSION['last_added_item'] = $item_data['DETAILS'];
                        }
                    } else {
                        $_SESSION['error_message'] = "Insufficient stock for item '{$item_data['DETAILS']}'. Available: $current_stock, Already in sale: $total_quantity_in_sale";
                    }
                } else {
                    $_SESSION['error_message'] = "No stock available for item '{$item_data['DETAILS']}'!";
                }
            } else {
                $_SESSION['error_message'] = "Item with barcode/code '$item_code' not found or not allowed for your license type!";
            }
            $item_stmt->close();
        }

        // Regenerate token
        $_SESSION['form_token'] = bin2hex(random_bytes(32));
        
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
    
    // Handle quantity updates with + and - buttons
    if (isset($_POST['update_quantity'])) {
        $item_id = $_POST['item_id'];
        $new_quantity = intval($_POST['quantity']);
        
        foreach ($_SESSION['sale_items'] as $index => $item) {
            if ($item['id'] === $item_id) {
                // Check if new quantity exceeds available stock
                if ($new_quantity > $item['current_stock']) {
                    $_SESSION['error_message'] = "Cannot update quantity to $new_quantity. Available stock: {$item['current_stock']}";
                } else {
                    $_SESSION['sale_items'][$index]['quantity'] = $new_quantity;
                }
                break;
            }
        }
        
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle quantity increment
    if (isset($_POST['increment_quantity'])) {
        $item_id = $_POST['item_id'];
        
        foreach ($_SESSION['sale_items'] as $index => $item) {
            if ($item['id'] === $item_id) {
                $new_quantity = $item['quantity'] + 1;
                if ($new_quantity > $item['current_stock']) {
                    $_SESSION['error_message'] = "Cannot increase quantity. Available stock: {$item['current_stock']}";
                } else {
                    $_SESSION['sale_items'][$index]['quantity'] = $new_quantity;
                }
                break;
            }
        }
        
        header("Location: barcode_sale.php");
        exit;
    }
    
    // Handle quantity decrement
    if (isset($_POST['decrement_quantity'])) {
        $item_id = $_POST['item_id'];
        
        foreach ($_SESSION['sale_items'] as $index => $item) {
            if ($item['id'] === $item_id) {
                if ($_SESSION['sale_items'][$index]['quantity'] > 1) {
                    $_SESSION['sale_items'][$index]['quantity'] -= 1;
                }
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

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch items from tblitemmaster for barcode scanning with license restrictions
$query = "SELECT CODE, DETAILS, DETAILS2, RPRICE, BARCODE, CLASS 
          FROM tblitemmaster 
          WHERE 1=1";
$params = [];
$types = "";

// Add license restriction
if (!empty($allowed_classes)) {
    $query .= " AND CLASS IN (" . implode(',', array_fill(0, count($allowed_classes), '?')) . ")";
    $params = array_merge($params, $allowed_classes);
    $types .= str_repeat('s', count($allowed_classes));
}

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

// Get selected customer from session if available (for walk-in customers)
$selectedCustomer = '';

// Function to prepare bill data for preview with volume-based splitting and duplicate aggregation
function prepareBillData() {
    global $conn, $comp_id, $selectedCustomer, $customers, $allowed_classes;
    
    if (empty($_SESSION['sale_items'])) {
        return null;
    }
    
    $user_id = $_SESSION['user_id'];
    $mode = 'F';
    
    // First aggregate duplicate items from session for bill generation
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
    
    $customer_name = 'Walk-in Customer';
    
    return [
        'customer_id' => $selectedCustomer,
        'customer_name' => $customer_name,
        'bill_date' => date('Y-m-d H:i:s'),
        'total_bills' => $total_bills_needed,
        'bills' => $all_bills,
        'grand_total' => array_sum(array_column($all_bills, 'total_amount'))
    ];
}

// FIXED: Function to generate a unique bill number - simplified and reliable
function generateBillNumber($conn, $comp_id) {
    // Get the maximum numeric part of bill numbers for this company
    $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill 
              FROM tblsaleheader 
              WHERE COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $next_bill = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
    $stmt->close();
    
    // Double-check this bill number doesn't exist (prevent race conditions)
    $check_query = "SELECT COUNT(*) as count FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
    $check_stmt = $conn->prepare($check_query);
    $bill_no_to_check = "BL" . str_pad($next_bill, 4, '0', STR_PAD_LEFT);
    $check_stmt->bind_param("si", $bill_no_to_check, $comp_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // If it exists, increment and use next number
        $next_bill++;
    }
    
    // Final safety check - ensure we have a valid bill number
    if ($next_bill <= 0) {
        // Ultimate fallback - use timestamp-based numbering
        $timestamp = time();
        $random_suffix = mt_rand(100, 999);
        return "BL" . substr($timestamp, -6) . $random_suffix;
    }
    
    return "BL" . str_pad($next_bill, 4, '0', STR_PAD_LEFT);
}

// Function to update item stock
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

// Function to update daily stock table with proper opening/closing calculations
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
    global $conn, $comp_id, $current_stock_column, $opening_stock_column, $daily_stock_table, $fin_year_id, $selectedCustomer, $customers, $allowed_classes;
    
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
// Generate sequential bill numbers starting from the next available
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
            $cash_memos_generated = 0; // ADDED: Cash memo counter
            $cash_memo_errors = []; // ADDED: Cash memo error tracker
            
            foreach ($all_bills as $bill) {
                $bill_data = processSingleBill($bill, $conn, $comp_id, $current_stock_column, $opening_stock_column, $daily_stock_table, $fin_year_id, $selectedCustomer, $customers, $user_id, $mode);
                if ($bill_data) {
                    $processed_bills[] = $bill_data;
                    
                    // ADDED: Generate cash memo for this bill
                    if (autoGenerateCashMemoForBill($conn, $bill_data['bill_no'], $comp_id, $user_id)) {
                        $cash_memos_generated++;
                        logCashMemoGeneration($bill_data['bill_no'], true);
                    } else {
                        $cash_memo_errors[] = $bill_data['bill_no'];
                        logCashMemoGeneration($bill_data['bill_no'], false, "Cash memo generation failed");
                    }
                }
            }
            
            $conn->commit();
            
            if (!empty($processed_bills)) {
                $success_message = "Sale completed successfully! Generated " . count($processed_bills) . " bills.";
                
                // ADDED: Include cash memo info in success message
                if ($cash_memos_generated > 0) {
                    $success_message .= " | Cash Memos Generated: " . $cash_memos_generated;
                }
                
                if (!empty($cash_memo_errors)) {
                    $success_message .= " | Failed to generate cash memos for bills: " . implode(", ", array_slice($cash_memo_errors, 0, 5));
                    if (count($cash_memo_errors) > 5) {
                        $success_message .= " and " . (count($cash_memo_errors) - 5) . " more";
                    }
                }
                
                $_SESSION['success_message'] = $success_message;
            } else {
                throw new Exception("No bills were processed successfully.");
            }
            
            unset($_SESSION['sale_items']);
            unset($_SESSION['sale_count']);
            unset($_SESSION['current_focus_index']);
            unset($_SESSION['selected_customer']);
            unset($_SESSION['preview_bill_data']);
            unset($_SESSION['last_added_item']);
            unset($_SESSION['last_item_processed']); // Clear processing lock
            
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_message'] = "Error processing sale: " . $e->getMessage();
        }
    } else {
        $_SESSION['error_message'] = "No items to process.";
    }
}

// Function to process a single bill with proper stock management and duplicate item handling
function processSingleBill($bill, $conn, $comp_id, $current_stock_column, $opening_stock_column, $daily_stock_table, $fin_year_id, $selectedCustomer, $customers, $user_id, $mode) {
    $bill_no = $bill['bill_no'];
    
    // CRITICAL FIX: Ensure bill_no is never null
    if (empty($bill_no) || $bill_no === 'TEMP') {
        // Generate a proper bill number immediately
        $bill_no = generateBillNumber($conn, $comp_id);
    }
    
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
        
        // UPDATE STOCK TABLES
        updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
        updateCascadingDailyStock($conn, $item['code'], $bill['bill_date'], $comp_id, 'sale', $item['qty']);
    }
    
    $customer_name = 'Walk-in Customer';
    
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
  <!-- Include shortcuts functionality -->
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
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
        justify-content: center;
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
        border-radius: 3px;
    }
    .quantity-btn:hover {
        background-color: #e9ecef;
    }
    .quantity-btn:disabled {
        background-color: #f8f9fa;
        cursor: not-allowed;
        opacity: 0.6;
    }
    .quantity-display {
        width: 50px;
        text-align: center;
        margin: 0 5px;
        font-weight: bold;
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
    
    /* License restriction info */
    .license-info {
        background-color: #d1ecf1;
        border: 1px solid #bee5eb;
        color: #0c5460;
        padding: 10px;
        border-radius: 5px;
        margin-bottom: 15px;
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
    
    /* Stock warning styles */
    .stock-warning {
        color: #dc3545;
        font-weight: bold;
    }
    .stock-ok {
        color: #28a745;
    }
    .stock-na {
        color: #6c757d;
    }
    
    /* Serial number column */
    .serial-col {
        width: 50px;
        text-align: center;
        font-weight: bold;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">POS System</h3>

      <!-- License Restriction Info -->
      <div class="license-info">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Allowed classes: 
              <?php 
              if (!empty($available_classes)) {
                  $class_names = [];
                  foreach ($available_classes as $class) {
                      $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                  }
                  echo implode(', ', $class_names);
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
      </div>

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
                  <th class="serial-col">#</th>
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
                $item_counter = 1;
                foreach ($_SESSION['sale_items'] as $index => $item): 
                  $item_amount = $item['price'] * $item['quantity'];
                  $total_amount += $item_amount;
                  $is_focused = $index == $_SESSION['current_focus_index'];
                  
                  // Determine stock display class
                  $stock_class = 'stock-na';
                  if (isset($item['current_stock'])) {
                      if ($item['quantity'] > $item['current_stock']) {
                          $stock_class = 'stock-warning';
                      } else {
                          $stock_class = 'stock-ok';
                      }
                  }
                ?>
                  <tr id="item-row-<?= $index ?>" class="<?= $is_focused ? 'focused-row' : '' ?>">
                    <td class="serial-col"><?= $item_counter ?></td>
                    <td><?= htmlspecialchars($item['code']) ?></td>
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td><?= htmlspecialchars($item['size']) ?></td>
                    <td>₹<?= number_format($item['price'], 2) ?></td>
                    <td>
                      <div class="quantity-controls">
                        <!-- Decrement Button -->
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                          <button type="submit" name="decrement_quantity" class="quantity-btn" <?= $item['quantity'] <= 1 ? 'disabled' : '' ?>>
                            <i class="fas fa-minus"></i>
                          </button>
                        </form>
                        
                        <!-- Quantity Display -->
                        <span class="quantity-display"><?= $item['quantity'] ?></span>
                        
                        <!-- Increment Button -->
                        <form method="POST" class="d-inline">
                          <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                          <button type="submit" name="increment_quantity" class="quantity-btn" <?= isset($item['current_stock']) && $item['quantity'] >= $item['current_stock'] ? 'disabled' : '' ?>>
                            <i class="fas fa-plus"></i>
                          </button>
                        </form>
                      </div>
                    </td>
                    <td>₹<?= number_format($item_amount, 2) ?></td>
                    <td class="<?= $stock_class ?>">
                      <?= isset($item['current_stock']) ? $item['current_stock'] : 'N/A' ?>
                      <?php if (isset($item['current_stock']) && $item['quantity'] > $item['current_stock']): ?>
                        <i class="fas fa-exclamation-triangle ms-1" title="Quantity exceeds available stock!"></i>
                      <?php endif; ?>
                    </td>
                    <td>
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="item_id" value="<?= $item['id'] ?>"> 
                        <button type="submit" name="remove_item" class="btn btn-sm btn-danger">
                          <i class="fas fa-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php 
                $item_counter++;
                endforeach; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="6" class="text-end"><strong>Total:</strong></td>
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

      <!-- Bill Preview Section -->
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
  <input type="hidden" name="form_token" value="<?= $_SESSION['form_token'] ?>">
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

  // Create a simple lock mechanism to prevent duplicate submissions
  let isProcessingScan = false;
  let lastProcessedBarcode = '';
  let lastProcessedTime = 0;

  // Focus on barcode input on page load
  barcodeInput.focus();

  function processBarcodeScan() {
    if (isProcessingScan) {
      console.log('Already processing a scan, skipping...');
      return;
    }
    
    const barcode = barcodeInput.value.trim();
    if (!barcode) return;
    
    // Prevent processing the same barcode within 500ms
    const currentTime = Date.now();
    if (barcode === lastProcessedBarcode && (currentTime - lastProcessedTime) < 500) {
      console.log('Duplicate barcode detected, skipping...');
      barcodeInput.value = '';
      barcodeInput.focus();
      return;
    }
    
    // Lock the scanner
    isProcessingScan = true;
    lastProcessedBarcode = barcode;
    lastProcessedTime = currentTime;
    
    // Disable input and button
    barcodeInput.disabled = true;
    scanBtn.disabled = true;
    
    // Update status
    statusIndicator.className = 'status-indicator status-scanning';
    statusText.textContent = 'Processing...';
    
    // Prepare form submission
    itemCodeInput.value = barcode;
    quantityInput.value = 1;
    
    // Clear input immediately
    barcodeInput.value = '';
    
    // Add a small delay to ensure single submission
    setTimeout(function() {
      // Submit form
      addItemForm.submit();
      
      // Re-enable after a longer delay to prevent accidental double-taps
      setTimeout(function() {
        isProcessingScan = false;
        barcodeInput.disabled = false;
        scanBtn.disabled = false;
        statusIndicator.className = 'status-indicator status-ready';
        statusText.textContent = 'Ready to scan';
        barcodeInput.focus();
      }, 1000); // Wait 1 second before allowing next scan
    }, 300);
  }

  // Handle barcode scanning - with better event handling
  function handleBarcodeScan(e) {
    if (e.key === 'Enter') {
      e.preventDefault();
      e.stopPropagation();
      processBarcodeScan();
    }
  }

  // Remove any existing listeners first
  barcodeInput.removeEventListener('keydown', handleBarcodeScan);
  barcodeInput.addEventListener('keydown', handleBarcodeScan);

  // Handle button click
  scanBtn.addEventListener('click', function(e) {
    e.preventDefault();
    e.stopPropagation();
    processBarcodeScan();
  });

  // Also add a flag to check if we're in a form submission
  window.addEventListener('load', function() {
    // Reset processing flag
    isProcessingScan = false;
    barcodeInput.disabled = false;
    scanBtn.disabled = false;
    barcodeInput.focus();
    
    // Check if we just submitted a form
    if (performance.navigation.type === performance.navigation.TYPE_RELOAD) {
      // Page was reloaded, likely after form submission
      // Clear any pending flags
      sessionStorage.removeItem('processingScan');
    }
    
    // Check for pending barcodes from other pages
    checkPendingBarcodes();
  });

  // Check for pending barcodes stored from other pages
  function checkPendingBarcodes() {
    let pendingBarcodes = JSON.parse(localStorage.getItem('pendingBarcodes') || '[]');
    if (pendingBarcodes.length > 0) {
      console.log('Found pending barcodes from other pages:', pendingBarcodes.length);
      
      // Sort by timestamp (oldest first)
      pendingBarcodes.sort((a, b) => a.timestamp - b.timestamp);
      
      // Process each barcode with a delay to avoid overwhelming
      pendingBarcodes.forEach((barcodeData, index) => {
        setTimeout(() => {
          processPendingBarcode(barcodeData.barcode);
        }, index * 500); // 500ms delay between each
      });
      
      // Clear pending barcodes after processing
      localStorage.removeItem('pendingBarcodes');
      
      // Show notification
      showProcessedNotification(pendingBarcodes.length);
    }
  }
  
  function processPendingBarcode(barcode) {
    // Simulate entering the barcode and pressing Enter
    barcodeInput.value = barcode;
    
    // Wait a bit then trigger the scan
    setTimeout(() => {
      const event = new KeyboardEvent('keydown', {
        key: 'Enter',
        code: 'Enter',
        keyCode: 13,
        which: 13,
        bubbles: true
      });
      barcodeInput.dispatchEvent(event);
    }, 100);
  }
  
  function showProcessedNotification(count) {
    const notification = document.createElement('div');
    notification.style.cssText = `
      position: fixed;
      top: 20px;
      right: 20px;
      background: #17a2b8;
      color: white;
      padding: 15px 20px;
      border-radius: 5px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      z-index: 9999;
      font-family: Arial, sans-serif;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 10px;
      animation: slideIn 0.3s ease-out;
    `;
    
    notification.innerHTML = `
      <i class="fas fa-sync-alt" style="font-size: 18px;"></i>
      <div>
        <strong>Processing ${count} scanned item(s)</strong><br>
        <small>From other pages</small>
      </div>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
      if (notification.parentNode) {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
      }
    }, 5000);
  }

  // Prevent form double submission
  addItemForm.addEventListener('submit', function(e) {
    if (isProcessingScan) {
      console.log('Form already submitting, preventing duplicate...');
      e.preventDefault();
      return false;
    }
  });

  // Handle search item clicks
  searchItems.forEach(item => {
    item.addEventListener('click', function() {
      if (isProcessingScan) {
        console.log('Already processing, skipping...');
        return;
      }
      
      const code = this.getAttribute('data-code');
      const name = this.getAttribute('data-name');
      
      // Add item to sale
      isProcessingScan = true;
      itemCodeInput.value = code;
      quantityInput.value = 1;
      addItemForm.submit();
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
    
    // Plus key - increase quantity of focused item
    if ((e.key === '+' || e.key === '=') && currentFocus >= 0) {
      e.preventDefault();
      const itemId = items[currentFocus].id;
      const incrementForm = document.createElement('form');
      incrementForm.method = 'POST';
      incrementForm.style.display = 'none';
      
      const itemIdInput = document.createElement('input');
      itemIdInput.type = 'hidden';
      itemIdInput.name = 'item_id';
      itemIdInput.value = itemId;
      
      incrementForm.appendChild(itemIdInput);
      document.body.appendChild(incrementForm);
      
      // Submit increment form
      const incrementInput = document.createElement('input');
      incrementInput.type = 'hidden';
      incrementInput.name = 'increment_quantity';
      incrementInput.value = '1';
      incrementForm.appendChild(incrementInput);
      
      incrementForm.submit();
    }
    
    // Minus key - decrease quantity of focused item
    if ((e.key === '-' || e.key === '_') && currentFocus >= 0) {
      e.preventDefault();
      const itemId = items[currentFocus].id;
      const decrementForm = document.createElement('form');
      decrementForm.method = 'POST';
      decrementForm.style.display = 'none';
      
      const itemIdInput = document.createElement('input');
      itemIdInput.type = 'hidden';
      itemIdInput.name = 'item_id';
      itemIdInput.value = itemId;
      
      decrementForm.appendChild(itemIdInput);
      document.body.appendChild(decrementForm);
      
      // Submit decrement form
      const decrementInput = document.createElement('input');
      decrementInput.type = 'hidden';
      decrementInput.name = 'decrement_quantity';
      decrementInput.value = '1';
      decrementForm.appendChild(decrementInput);
      
      decrementForm.submit();
    }
    
    if (items.length === 0) return;
    
    // Arrow down - move to next item
    if (e.key === 'ArrowDown' && currentFocus < items.length - 1) {
      e.preventDefault();
      const newFocus = currentFocus + 1;
      
      // Create and submit focus form
      const focusForm = document.createElement('form');
      focusForm.method = 'POST';
      focusForm.style.display = 'none';
      
      const focusInput = document.createElement('input');
      focusInput.type = 'hidden';
      focusInput.name = 'set_focus_index';
      focusInput.value = newFocus;
      
      focusForm.appendChild(focusInput);
      document.body.appendChild(focusForm);
      focusForm.submit();
    }
    
    // Arrow up - move to previous item
    if (e.key === 'ArrowUp' && currentFocus > 0) {
      e.preventDefault();
      const newFocus = currentFocus - 1;
      
      // Create and submit focus form
      const focusForm = document.createElement('form');
      focusForm.method = 'POST';
      focusForm.style.display = 'none';
      
      const focusInput = document.createElement('input');
      focusInput.type = 'hidden';
      focusInput.name = 'set_focus_index';
      focusInput.value = newFocus;
      
      focusForm.appendChild(focusInput);
      document.body.appendChild(focusForm);
      focusForm.submit();
    }
  });

  // Auto-focus on barcode input after actions
  <?php if (isset($_POST['add_item']) || isset($_POST['remove_item']) || isset($_POST['process_sale']) || isset($_POST['clear_sale']) || isset($_POST['update_quantity']) || isset($_POST['increment_quantity']) || isset($_POST['decrement_quantity'])): ?>
    window.onload = function() {
      document.getElementById('barcodeInput').focus();
    };
  <?php endif; ?>
});
</script>
</body>
</html>