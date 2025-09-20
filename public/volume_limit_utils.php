<?php
// volume_limit_utils.php

/**
 * Get category limits from tblcompany
 */
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

/**
 * Determine item category based on class and subclass
 */
function getItemCategory($conn, $item_code, $mode) {
    // Get item details directly from tblitemmaster
    $query = "SELECT DETAILS2 FROM tblitemmaster WHERE CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    if (!$item_data) {
        return 'OTHER';
    }
    
    $details2 = strtoupper($item_data['DETAILS2'] ?? '');
    
    // Categorize based on DETAILS2 content
    if ($mode === 'F') {
        // Check if it's a liquor item by looking for ML size indication
        if (preg_match('/\d+\s*ML/i', $details2)) {
            return 'IMFL';
        }
        if (strpos($details2, 'WHISKY') !== false) {
            return 'IMFL';
        } elseif (strpos($details2, 'GIN') !== false) {
            return 'IMFL';
        } elseif (strpos($details2, 'BRANDY') !== false) {
            return 'IMFL';
        } elseif (strpos($details2, 'VODKA') !== false) {
            return 'IMFL';
        } elseif (strpos($details2, 'RUM') !== false) {
            return 'IMFL';
        } elseif (strpos($details2, 'LIQUOR') !== false) {
            return 'IMFL';
        } elseif (strpos($details2, 'BEER') !== false) {
            return 'BEER';
        }
        
        // Default: if it's in Foreign Liquor mode but doesn't match above, still treat as IMFL
        return 'IMFL';
        
    } elseif ($mode === 'C') {
        if (strpos($details2, 'COUNTRY') !== false || 
            strpos($details2, 'CL') !== false) {
            return 'CL';
        }
    }
    
    return 'OTHER';
}

/**
 * Get item size from CC in tblsubclass
 */

function getItemSize($conn, $item_code, $mode) {
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
            return $size;
        }
    }
    
    // Default size if not found
    return 750; // Common liquor bottle size
}

/**
 * Generate bills with volume limits
 */
function generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id) {
    $category_limits = getCategoryLimits($conn, $comp_id);
    $bills = [];
    
    // Get the starting bill number once
    $next_bill = getNextBillNumber($conn);
    
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

/**
 * Create a bill
 */
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

/**
 * Get next bill number
 */
function getNextBillNumber($conn) {
    $conn->query("LOCK TABLES tblsaleheader WRITE");
    
    $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    $next_bill = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
    
    $conn->query("UNLOCK TABLES");
    
    return $next_bill;
}

/**
 * Distribute sales uniformly
 */
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
?>