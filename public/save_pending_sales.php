<?php
session_start();
include_once "../config/db.php";

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/sales_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Function to log array data
function logArray($data, $title = 'Array data') {
    ob_start();
    print_r($data);
    $output = ob_get_clean();
    logMessage("$title:\n$output");
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_pending'])) {
    $comp_id = $_SESSION['CompID'];
    $user_id = $_SESSION['user_id'];
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $mode = $_POST['mode'];
// Get items from multiple sources to ensure we have all data
$items = [];
if (isset($_POST['all_sale_qty'])) {
    $items = $_POST['all_sale_qty'];
} elseif (isset($_POST['sale_qty'])) {
    $items = $_POST['sale_qty'];
} elseif (isset($_SESSION['sale_quantities'])) {
    $items = $_SESSION['sale_quantities'];
}

// Filter out zero quantities
$items = array_filter($items, function($qty) {
    return $qty > 0;
});    
    logMessage("Saving pending sales for user $user_id, company $comp_id");
    logArray($items, 'Items to save');
    
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Check if the fin_year_id column exists, if not add it
        $check_column_query = "SHOW COLUMNS FROM tbl_pending_sales LIKE 'fin_year_id'";
        $column_result = $conn->query($check_column_query);
        
        if ($column_result->num_rows == 0) {
            logMessage("Adding fin_year_id column to tbl_pending_sales");
            $alter_query = "ALTER TABLE tbl_pending_sales ADD COLUMN fin_year_id INT NULL AFTER user_id";
            if (!$conn->query($alter_query)) {
                throw new Exception("Error adding fin_year_id column: " . $conn->error);
            }
            logMessage("fin_year_id column added successfully");
        }
        
        // Delete any existing pending sales for this date range and company
        $delete_query = "DELETE FROM tbl_pending_sales WHERE comp_id = ? AND start_date = ? AND end_date = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("iss", $comp_id, $start_date, $end_date);
        $delete_stmt->execute();
        $delete_stmt->close();
        
        // Prepare items data for bill generation with volume limits
        $items_data = [];
        foreach ($items as $item_code => $quantity) {
            // Get item details from database
            $item_query = "SELECT DETAILS, RPRICE, DETAILS2 FROM tblitemmaster WHERE CODE = ?";
            $item_stmt = $conn->prepare($item_query);
            $item_stmt->bind_param("s", $item_code);
            $item_stmt->execute();
            $item_result = $item_stmt->get_result();
            $item_details = $item_result->fetch_assoc();
            $item_stmt->close();
            
            if ($item_details) {
                $items_data[$item_code] = [
                    'name' => $item_details['DETAILS'],
                    'rate' => $item_details['RPRICE'],
                    'total_qty' => $quantity,
                    'details2' => $item_details['DETAILS2']
                ];
            }
        }
        
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
        
        logMessage("Date range spans $days_count days: " . implode(', ', $date_array));
        
        // Generate daily sales distribution for each item
        $daily_sales_data = [];
        foreach ($items_data as $item_code => $item_data) {
            $daily_sales = distributeSales($item_data['total_qty'], $days_count);
            $daily_sales_data[$item_code] = $daily_sales;
            logMessage("Item $item_code distribution: " . implode(', ', $daily_sales));
        }
        
        // Generate bills with volume limits (same logic as in main file)
        $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
        
        logMessage("Generated " . count($bills) . " bills with volume limits");
        
        // Insert bill information into pending sales table
        $insert_query = "INSERT INTO tbl_pending_sales (comp_id, user_id, fin_year_id, start_date, end_date, mode, 
                         item_code, quantity, bill_no, bill_date, bill_amount, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $insert_stmt = $conn->prepare($insert_query);
        
        $bill_count = 0;
        $total_amount = 0;
        
        foreach ($bills as $bill) {
            $bill_count++;
            $total_amount += $bill['total_amount'];
            
            foreach ($bill['items'] as $item) {
                $insert_stmt->bind_param(
                    "iiissssisds", 
                    $comp_id, 
                    $user_id, 
                    $fin_year_id, 
                    $start_date, 
                    $end_date, 
                    $mode,
                    $item['code'],
                    $item['qty'],
                    $bill['bill_no'],
                    $bill['bill_date'],
                    $item['amount']
                );
                $insert_stmt->execute();
            }
        }
        
        $insert_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $message = "Saved $bill_count bills for later posting. Date range: $start_date to $end_date. Total Amount: ₹" . number_format($total_amount, 2);
        
        logMessage($message);
        echo json_encode([
            'success' => true, 
            'message' => $message, 
            'bill_count' => $bill_count,
            'total_amount' => $total_amount
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $error_message = "Error saving pending sales: " . $e->getMessage();
        logMessage($error_message, 'ERROR');
        echo json_encode(['success' => false, 'message' => $error_message]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}

// Function to distribute sales uniformly
function distributeSales($total_qty, $days_count) {
    logMessage("Distributing $total_qty units across $days_count days");
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
    
    logMessage("Distribution for $total_qty units: " . implode(', ', $daily_sales));
    return $daily_sales;
}

// Function to get category limits from tblcompany
function getCategoryLimits($conn, $comp_id) {
    logMessage("Getting category limits for company $comp_id");
    $query = "SELECT IMFLLimit, BEERLimit, CLLimit FROM tblcompany WHERE CompID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $limits = $result->fetch_assoc();
    $stmt->close();
    
    $result = [
        'IMFL' => $limits['IMFLLimit'] ?? 1000, // Default 1000ml if not set
        'BEER' => $limits['BEERLimit'] ?? 0,
        'CL' => $limits['CLLimit'] ?? 0
    ];
    
    logMessage("Category limits: " . json_encode($result));
    return $result;
}

// Function to determine item category based on class and subclass with debugging
function getItemCategory($conn, $item_code, $mode) {
    logMessage("Determining category for item $item_code, mode: $mode");
    
    // Get item details directly from tblitemmaster
    $query = "SELECT DETAILS2 FROM tblitemmaster WHERE CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    if (!$item_data) {
        logMessage("Item $item_code not found in database, category: OTHER", 'WARNING');
        return 'OTHER';
    }
    
    $details2 = strtoupper($item_data['DETAILS2'] ?? '');
    logMessage("Item $item_code - DETAILS2: $details2");
    
    // Categorize based on DETAILS2 content
    if ($mode === 'F') {
        // Check if it's a liquor item by looking for ML size indication
        if (preg_match('/\d+\s*ML/i', $details2)) {
            logMessage("Item $item_code categorized as IMFL (Foreign Liquor with ML size)");
            return 'IMFL';
        }
        if (strpos($details2, 'WHISKY') !== false) {
            logMessage("Item $item_code categorized as IMFL (WHISKY)");
            return 'IMFL';
        } elseif (strpos($details2, 'GIN') !== false) {
            logMessage("Item $item_code categorized as IMFL (GIN)");
            return 'IMFL';
        } elseif (strpos($details2, 'BRANDY') !== false) {
            logMessage("Item $item_code categorized as IMFL (BRANDY)");
            return 'IMFL';
        } elseif (strpos($details2, 'VODKA') !== false) {
            logMessage("Item $item_code categorized as IMFL (VODKA)");
            return 'IMFL';
        } elseif (strpos($details2, 'RUM') !== false) {
            logMessage("Item $item_code categorized as IMFL (RUM)");
            return 'IMFL';
        } elseif (strpos($details2, 'LIQUOR') !== false) {
            logMessage("Item $item_code categorized as IMFL (LIQUOR)");
            return 'IMFL';
        } elseif (strpos($details2, 'BEER') !== false) {
            logMessage("Item $item_code categorized as BEER");
            return 'BEER';
        }
        
        // Default: if it's in Foreign Liquor mode but doesn't match above, still treat as IMFL
        logMessage("Item $item_code categorized as IMFL (default for Foreign Liquor mode)");
        return 'IMFL';
        
    } elseif ($mode === 'C') {
        if (strpos($details2, 'COUNTRY') !== false || 
            strpos($details2, 'CL') !== false) {
            logMessage("Item $item_code categorized as CL");
            return 'CL';
        }
    }
    
    logMessage("Item $item_code categorized as OTHER");
    return 'OTHER';
}

// Function to get item size from CC in tblsubclass with debugging
function getItemSize($conn, $item_code, $mode) {
    logMessage("Getting size for item $item_code, mode: $mode");
    
    // First try to get size from DETAILS2 in tblitemmaster
    $query = "SELECT DETAILS2 FROM tblitemmaster WHERE CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($item_data && !empty($item_data['DETAILS2'])) {
        $details2 = $item_data['DETAILS2'];
        // Extract size from DETAILS2 (e.g., "180 ML", "750 ML", "90 ML-(96)", "1000 ML")
        if (preg_match('/(\d+)\s*ML/i', $details2, $matches)) {
            $size = (float)$matches[1];
            logMessage("Item $item_code - Size from DETAILS2: {$size}ml");
            return $size;
        }
    }
    
    // If not found in DETAILS2, try to get from CC in tblsubclass
    $query = "SELECT sc.CC 
              FROM tblitemmaster im 
              LEFT JOIN tblsubclass sc ON im.DETAILS2 = sc.ITEM_GROUP AND sc.LIQ_FLAG = ?
              WHERE im.CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $mode, $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($item_data && $item_data['CC'] > 0) {
        logMessage("Item $item_code - Size from subclass: {$item_data['CC']}ml");
        return (float)$item_data['CC'];
    }
    
    // If not found in subclass, try to extract from item name in DETAILS
    $query = "SELECT DETAILS FROM tblitemmaster WHERE CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($item_data && !empty($item_data['DETAILS'])) {
        // Try to extract size from item name (e.g., "Item Name 750ML")
        if (preg_match('/(\d+)\s*ML/i', $item_data['DETAILS'], $matches)) {
            $size = (float)$matches[1];
            logMessage("Item $item_code - Size from item name: {$size}ml");
            return $size;
        }
    }
    
    // Default size if not found
    logMessage("Item $item_code - Using default size: 750ml", 'WARNING');
    return 750; // Common liquor bottle size
}

// Function to generate bills with volume limits with debugging
function generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id) {
    logMessage("Starting bill generation with volume limits");
    
    $category_limits = getCategoryLimits($conn, $comp_id);
    $bills = [];
    
    // Debug: Show category limits
    logMessage("Category Limits - IMFL: " . $category_limits['IMFL'] . 
              ", BEER: " . $category_limits['BEER'] . 
              ", CL: " . $category_limits['CL']);
    
    // Get the starting bill number once
    $next_bill = getNextBillNumber($conn);
    
    foreach ($date_array as $date_index => $sale_date) {
        logMessage("Processing date: $sale_date (index: $date_index)");
        $daily_bills = [];
        
        // Group items by category for this day
        $category_items = [];
        foreach ($items_data as $item_code => $item_data) {
            $qty = $daily_sales_data[$item_code][$date_index] ?? 0;
            if ($qty > 0) {
                $category = getItemCategory($conn, $item_code, $mode);
                $size = getItemSize($conn, $item_code, $mode);
                $volume = $qty * $size;
                
                // Debug: Show item details
                logMessage("Date $sale_date - Item $item_code: Qty=$qty, Size={$size}ml, Volume={$volume}ml, Category=$category");
                
                if (!isset($category_items[$category])) {
                    $category_items[$category] = [];
                }
                
                $category_items[$category][] = [
                    'code' => $item_code,
                    'qty' => $qty,
                    'rate' => $item_data['rate'],
                    'size' => $size,
                    'volume' => $volume,
                    'amount' => $qty * $item_data['rate']
                ];
            }
        }
        
        // Debug: Show categories found
        logMessage("Date $sale_date - Categories with items: " . implode(", ", array_keys($category_items)));
        
        // Process each category with its limit
        foreach ($category_items as $category => $items) {
            $limit = $category_limits[$category] ?? 0;
            
            // Debug: Show category processing
            logMessage("Processing category: $category with limit: {$limit}ml");
            logMessage("Items in category: " . count($items));
            
            if ($limit <= 0) {
                // No limit, put all items in one bill
                if (!empty($items)) {
                    logMessage("No limit for category $category, creating single bill");
                    $daily_bills[] = createBill($items, $sale_date, $next_bill++, $mode, $comp_id, $user_id);
                }
            } else {
                // Sort items by volume descending (First-Fit Decreasing algorithm)
                usort($items, function($a, $b) {
                    return $b['volume'] <=> $a['volume'];
                });
                
                // Debug: Show sorted items
                logMessage("Sorted items by volume (descending) for category $category:");
                foreach ($items as $item) {
                    logMessage("  - {$item['code']}: {$item['volume']}ml");
                }
                
                // Create bills by grouping items without exceeding the limit
                $bills_for_category = [];
                $current_bill_items = [];
                $current_bill_volume = 0;
                $bill_count = 1;
                
                foreach ($items as $item) {
                    // If adding this item would exceed the limit, finalize current bill
                    if ($current_bill_volume + $item['volume'] > $limit && !empty($current_bill_items)) {
                        logMessage("Category $category - Bill $bill_count: Volume={$current_bill_volume}ml, Items=" . count($current_bill_items));
                        $bills_for_category[] = $current_bill_items;
                        $current_bill_items = [];
                        $current_bill_volume = 0;
                        $bill_count++;
                    }
                    
                    // Add item to current bill
                    $current_bill_items[] = $item;
                    $current_bill_volume += $item['volume'];
                    logMessage("Added {$item['code']} ({$item['volume']}ml) to bill. Current volume: {$current_bill_volume}ml");
                }
                
                // Add the last bill if it has items
                if (!empty($current_bill_items)) {
                    logMessage("Category $category - Final bill $bill_count: Volume={$current_bill_volume}ml, Items=" . count($current_bill_items));
                    $bills_for_category[] = $current_bill_items;
                }
                
                // Debug: Show bills created for this category
                logMessage("Created " . count($bills_for_category) . " bills for category $category");
                
                // Create actual bills from the grouped items
                foreach ($bills_for_category as $bill_items) {
                    $daily_bills[] = createBill($bill_items, $sale_date, $next_bill++, $mode, $comp_id, $user_id);
                }
            }
        }
        
        $bills = array_merge($bills, $daily_bills);
    }
    
    // Debug: Show total bills created
    logMessage("Total bills created: " . count($bills));
    
    return $bills;
}

// Function to create a bill
function createBill($items, $sale_date, $bill_no, $mode, $comp_id, $user_id) {
    $total_amount = 0;
    $bill_no_str = "BL" . $bill_no;
    
    foreach ($items as $item) {
        $total_amount += $item['amount'];
    }
    
    logMessage("Created bill $bill_no_str for date $sale_date with " . count($items) . " items, total amount: $total_amount");
    
    return [
        'bill_no' => $bill_no_str,
        'bill_date' => $sale_date,
        'total_amount' => $total_amount,
        'items' => $items,
        'mode' => $mode,
        'comp_id' => $comp_id,
        'user_id' => $user_id
    ];
}

// Function to get next bill number
function getNextBillNumber($conn) {
    logMessage("Getting next bill number");
    $conn->query("LOCK TABLES tblsaleheader WRITE");
    
    $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    $next_bill = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
    
    $conn->query("UNLOCK TABLES");
    logMessage("Next bill number: $next_bill");
    
    return $next_bill;
}
?>