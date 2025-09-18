<?php
// generate_bills.php
session_start();
include_once "../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_bills'])) {
    $response = ['success' => false, 'message' => '', 'total_amount' => 0, 'bill_count' => 0];
    
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
        
        // Get next bill number - FIXED VERSION
        function getNextBillNumber($conn) {
            // Use a transaction-safe method to get the next bill number
            $conn->query("LOCK TABLES tblsaleheader WRITE");
            
            $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader";
            $result = $conn->query($query);
            $row = $result->fetch_assoc();
            $next_bill = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
            
            $conn->query("UNLOCK TABLES");
            
            return $next_bill;
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
        
        // Function to get category limits from tblcompany
        function getCategoryLimits($conn, $comp_id) {
            $query = "SELECT IMFLLimit, BEERLimit, CLLimit FROM tblcompany WHERE CompID = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("i", $comp_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $limits = $result->fetch_assoc();
            $stmt->close();
            
            return [
                'IMFL' => $limits['IMFLLimit'] ?? 1000, // Default 1000ml if not set
                'BEER' => $limits['BEERLimit'] ?? 0,
                'CL' => $limits['CLLimit'] ?? 0
            ];
        }
        
        // Function to determine item category based on class and subclass
        function getItemCategory($conn, $item_code, $mode) {
            // Get item details directly from tblitemmaster
            $query = "SELECT DETAILS2 FROM tblitemmaster WHERE CODE = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $item_code);
            $stmt->execute();
            $result = $stmt->get_result();
            $item_data = $result->fetch_assoc();
            $stmt->close();
            
            if (!$item_data) return 'OTHER';
            
            $details2 = strtoupper($item_data['DETAILS2'] ?? '');
            
            // Categorize based on DETAILS2 content
            if ($mode === 'F') {
                if (strpos($details2, 'WHISKY') !== false || 
                    strpos($details2, 'GIN') !== false ||
                    strpos($details2, 'BRANDY') !== false ||
                    strpos($details2, 'VODKA') !== false ||
                    strpos($details2, 'RUM') !== false ||
                    strpos($details2, 'LIQUOR') !== false) {
                    return 'IMFL';
                } elseif (strpos($details2, 'BEER') !== false) {
                    return 'BEER';
                }
            } elseif ($mode === 'C') {
                if (strpos($details2, 'COUNTRY') !== false || 
                    strpos($details2, 'CL') !== false) {
                    return 'CL';
                }
            }
            
            return 'OTHER';
        }
        
        // Function to get item size from CC in tblsubclass
        function getItemSize($conn, $item_code, $mode) {
            // First try to get size from CC in tblsubclass
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
                return (float)$item_data['CC'];
            }
            
            // If not found in subclass, try to extract from item name
            $query = "SELECT DETAILS FROM tblitemmaster WHERE CODE = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $item_code);
            $stmt->execute();
            $result = $stmt->get_result();
            $item_data = $result->fetch_assoc();
            $stmt->close();
            
            if ($item_data) {
                // Try to extract size from item name (e.g., "Item Name 750ML")
                if (preg_match('/(\d+)\s*ML/i', $item_data['DETAILS'], $matches)) {
                    return (float)$matches[1];
                }
            }
            
            // Default size if not found
            return 750; // Common liquor bottle size
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
        }
        
        // Function to generate bills with volume limits - FIXED VERSION
        function generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id) {
            $category_limits = getCategoryLimits($conn, $comp_id);
            $bills = [];
            
            // Get the starting bill number once
            $next_bill = getNextBillNumber($conn);
            
            // Get stock column names
            $current_stock_column = "Current_Stock" . $comp_id;
            $opening_stock_column = "Opening_Stock" . $comp_id;
            $daily_stock_table = "tbldailystock_" . $comp_id;
            
            foreach ($date_array as $date_index => $sale_date) {
                $daily_bills = [];
                
                // Group items by category for this day
                $category_items = [];
                foreach ($items_data as $item_code => $item_data) {
                    $qty = $daily_sales_data[$item_code][$date_index] ?? 0;
                    if ($qty > 0) {
                        $category = getItemCategory($conn, $item_code, $mode);
                        $size = getItemSize($conn, $item_code, $mode);
                        $volume = $qty * $size;
                        
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
                
                // Process each category with its limit
                foreach ($category_items as $category => $items) {
                    $limit = $category_limits[$category] ?? 0;
                    
                    if ($limit <= 0) {
                        // No limit, put all items in one bill
                        if (!empty($items)) {
                            $daily_bills[] = createBill($items, $sale_date, $next_bill++, $mode, $comp_id, $user_id);
                        }
                    } else {
                        // Sort items by volume descending (First-Fit Decreasing algorithm)
                        usort($items, function($a, $b) {
                            return $b['volume'] <=> $a['volume'];
                        });
                        
                        // Create bills by grouping items without exceeding the limit
                        $bills_for_category = [];
                        $current_bill_items = [];
                        $current_bill_volume = 0;
                        
                        foreach ($items as $item) {
                            // If adding this item would exceed the limit, finalize current bill
                            if ($current_bill_volume + $item['volume'] > $limit && !empty($current_bill_items)) {
                                $bills_for_category[] = $current_bill_items;
                                $current_bill_items = [];
                                $current_bill_volume = 0;
                            }
                            
                            // Add item to current bill
                            $current_bill_items[] = $item;
                            $current_bill_volume += $item['volume'];
                        }
                        
                        // Add the last bill if it has items
                        if (!empty($current_bill_items)) {
                            $bills_for_category[] = $current_bill_items;
                        }
                        
                        // Create actual bills from the grouped items
                        foreach ($bills_for_category as $bill_items) {
                            $daily_bills[] = createBill($bill_items, $sale_date, $next_bill++, $mode, $comp_id, $user_id);
                        }
                    }
                }
                
                $bills = array_merge($bills, $daily_bills);
            }
            
            return $bills;
        }
        
        // Function to create a bill
        function createBill($items, $sale_date, $bill_no, $mode, $comp_id, $user_id) {
            $total_amount = 0;
            $bill_no_str = "BL" . $bill_no;
            
            foreach ($items as $item) {
                $total_amount += $item['amount'];
            }
            
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
        
        // Start transaction
        $conn->begin_transaction();
        
        $total_amount = 0;
        $items_data = []; // Store item data for bill generation
        $daily_sales_data = []; // Store daily sales for each item
        
        // Process items with quantities
        foreach ($items as $item_code => $total_qty) {
            $total_qty = intval($total_qty);
            
            if ($total_qty > 0) {
                // Get item details
                $item_query = "SELECT DETAILS, RPRICE FROM tblitemmaster WHERE CODE = ?";
                $item_stmt = $conn->prepare($item_query);
                $item_stmt->bind_param("s", $item_code);
                $item_stmt->execute();
                $item_result = $item_stmt->get_result();
                $item = $item_result->fetch_assoc();
                $item_stmt->close();
                
                if (!$item) {
                    continue; // Skip if item not found
                }
                
                // Generate distribution
                $daily_sales = distributeSales($total_qty, $days_count);
                $daily_sales_data[$item_code] = $daily_sales;
                
                // Store item data
                $items_data[$item_code] = [
                    'name' => $item['DETAILS'],
                    'rate' => $item['RPRICE'],
                    'total_qty' => $total_qty
                ];
            }
        }
        
        // Generate bills with volume limits
        $bills = generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
        
        // Get stock column names
        $current_stock_column = "Current_Stock" . $comp_id;
        $opening_stock_column = "Opening_Stock" . $comp_id;
        $daily_stock_table = "tbldailystock_" . $comp_id;
        
        // Process each bill
        foreach ($bills as $bill) {
            // Insert sale header
            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                             VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
            $header_stmt = $conn->prepare($header_query);
            $header_stmt->bind_param("ssddssi", $bill['bill_no'], $bill['bill_date'], $bill['total_amount'], 
                                    $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
            $header_stmt->execute();
            $header_stmt->close();
            
            // Insert sale details for each item in the bill
            foreach ($bill['items'] as $item) {
                $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                $detail_stmt = $conn->prepare($detail_query);
                $detail_stmt->bind_param("ssddssi", $bill['bill_no'], $item['code'], $item['qty'], 
                                        $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                $detail_stmt->execute();
                $detail_stmt->close();
                
                // Update stock
                updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
                
                // Update daily stock
                updateDailyStock($conn, $daily_stock_table, $item['code'], $bill['bill_date'], $item['qty'], $comp_id);
            }
            
            $total_amount += $bill['total_amount'];
        }
        
        // Commit transaction
        $conn->commit();
        
        $response['success'] = true;
        $response['message'] = "Sales distributed successfully! Generated " . count($bills) . " bills.";
        $response['total_amount'] = number_format($total_amount, 2);
        $response['bill_count'] = count($bills);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $response['message'] = "Error updating sales: " . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}