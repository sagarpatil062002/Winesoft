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
 * Generate bills with volume limits - UPDATED LOGIC
 */
function generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id) {
    $category_limits = getCategoryLimits($conn, $comp_id);
    $bills = [];
    
    // Get the starting bill number once - REMOVED DUPLICATE CALL
    // $next_bill = getNextBillNumber($conn); // This is now handled in the main file
    
    foreach ($date_array as $date_index => $sale_date) {
        $daily_bills = [];
        
        // Collect all items for this day
        $all_items = [];
        foreach ($items_data as $item_code => $item_data) {
            $qty = $daily_sales_data[$item_code][$date_index] ?? 0;
            if ($qty > 0) {
                $category = getItemCategory($conn, $item_code, $mode);
                $size = getItemSize($conn, $item_code, $mode);
                
                $all_items[] = [
                    'code' => $item_code,
                    'qty' => $qty,
                    'rate' => $item_data['rate'],
                    'size' => $size,
                    'amount' => $qty * $item_data['rate'],
                    'name' => $item_data['name'],
                    'category' => $category
                ];
            }
        }
        
        // If no items for this day, skip
        if (empty($all_items)) {
            continue;
        }
        
        // Create bills using the UPDATED algorithm
        $bills_for_day = createOptimizedBills($all_items, $category_limits);
        
        // Create actual bills - bill number assignment moved to main file
        foreach ($bills_for_day as $bill_items) {
            if (!empty($bill_items)) {
                // Pass 0 as bill number - will be assigned in main file
                $daily_bills[] = createBill($bill_items, $sale_date, 0, $mode, $comp_id, $user_id);
            }
        }
        
        $bills = array_merge($bills, $daily_bills);
    }
    
    return $bills;
}

/**
 * Create optimized bills that handle both scenarios - UPDATED FUNCTION
 */
function createOptimizedBills($all_items, $category_limits) {
    $bills = [];
    
    if (empty($all_items)) {
        return [];
    }
    
    // Expand items while keeping track of quantities for optimal packing
    $item_pools = [];
    foreach ($all_items as $item) {
        $category = $item['category'];
        if (!isset($item_pools[$category])) {
            $item_pools[$category] = [];
        }
        
        $item_pools[$category][] = [
            'code' => $item['code'],
            'rate' => $item['rate'],
            'size' => $item['size'],
            'name' => $item['name'],
            'total_qty' => $item['qty'],
            'remaining_qty' => $item['qty']
        ];
    }
    
    // Sort each category's items by size descending
    foreach ($item_pools as &$pool) {
        usort($pool, function($a, $b) {
            return $b['size'] <=> $a['size'];
        });
    }
    
    // Continue creating bills until all items are allocated
    $has_remaining_items = true;
    
    while ($has_remaining_items) {
        $current_bill = [];
        $category_usage = []; // Track volume per category in current bill
        
        $has_remaining_items = false;
        
        // Try to fill the current bill with items from all categories
        foreach ($item_pools as $category => &$pool) {
            $limit = $category_limits[$category] ?? 0;
            
            foreach ($pool as &$item) {
                if ($item['remaining_qty'] > 0) {
                    $current_category_volume = $category_usage[$category] ?? 0;
                    $available_space = $limit - $current_category_volume;
                    
                    // Calculate how many of this item can fit
                    $max_fit = ($limit <= 0) ? $item['remaining_qty'] : floor($available_space / $item['size']);
                    $qty_to_add = min($item['remaining_qty'], $max_fit);
                    
                    if ($qty_to_add > 0) {
                        // Add to current bill
                        $bill_item_key = findBillItem($current_bill, $item['code']);
                        
                        if ($bill_item_key !== false) {
                            // Update existing item in bill
                            $current_bill[$bill_item_key]['qty'] += $qty_to_add;
                            $current_bill[$bill_item_key]['amount'] += $qty_to_add * $item['rate'];
                        } else {
                            // Add new item to bill
                            $current_bill[] = [
                                'code' => $item['code'],
                                'qty' => $qty_to_add,
                                'rate' => $item['rate'],
                                'size' => $item['size'],
                                'amount' => $qty_to_add * $item['rate'],
                                'name' => $item['name'],
                                'category' => $category
                            ];
                        }
                        
                        // Update tracking
                        $item['remaining_qty'] -= $qty_to_add;
                        $category_usage[$category] = ($category_usage[$category] ?? 0) + ($qty_to_add * $item['size']);
                    }
                }
                
                if ($item['remaining_qty'] > 0) {
                    $has_remaining_items = true;
                }
            }
        }
        
        // If we created a bill with items, add it to the bills list
        if (!empty($current_bill)) {
            $bills[] = $current_bill;
        } else {
            // If no items could be added to the current bill, break to avoid infinite loop
            break;
        }
    }
    
    return $bills;
}

/**
 * Find if item already exists in bill
 */
function findBillItem($bill_items, $item_code) {
    foreach ($bill_items as $key => $item) {
        if ($item['code'] === $item_code) {
            return $key;
        }
    }
    return false;
}

/**
 * Create a bill - UPDATED to handle zero bill numbers
 */
function createBill($items, $sale_date, $bill_no, $mode, $comp_id, $user_id) {
    $total_amount = 0;
    
    // If bill_no is 0, don't assign a number (will be assigned in main file)
    $bill_no_str = ($bill_no > 0) ? "BL" . str_pad($bill_no, 4, '0', STR_PAD_LEFT) : "TEMP";
    
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