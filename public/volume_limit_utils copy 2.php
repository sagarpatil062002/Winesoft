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
 * Determine item category based on LIQ_FLAG and item details - ENHANCED
 */
function getItemCategory($conn, $item_code, $mode) {
    // Get item details including LIQ_FLAG from tblitemmaster
    $query = "SELECT im.DETAILS2, sc.LIQ_FLAG 
              FROM tblitemmaster im 
              LEFT JOIN tblsubclass sc ON im.DETAILS2 = sc.ITEM_GROUP 
              WHERE im.CODE = ?";
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
    $liq_flag = $item_data['LIQ_FLAG'] ?? '';
    
    // PRIMARY: Use LIQ_FLAG for categorization if available
    if (!empty($liq_flag)) {
        switch (strtoupper($liq_flag)) {
            case 'F':
            case 'FL':
                return 'IMFL';
            case 'C':
            case 'CL':
                return 'CL';
            case 'B':
            case 'BEER':
                return 'BEER';
        }
    }
    
    // SECONDARY: Categorize based on DETAILS2 content if LIQ_FLAG not available
    if ($mode === 'F' || $mode === 'FL') {
        // Check if it's a liquor item by looking for ML size indication
        if (preg_match('/\d+\s*ML/i', $details2)) {
            return 'IMFL';
        }
        
        // Specific liquor type detection
        $liquor_keywords = ['WHISKY', 'WHISKEY', 'GIN', 'BRANDY', 'VODKA', 'RUM', 'LIQUOR', 'WINE', 'SCOTCH', 'BOURBON', 'TEQUILA'];
        foreach ($liquor_keywords as $keyword) {
            if (strpos($details2, $keyword) !== false) {
                return 'IMFL';
            }
        }
        
        // Beer detection
        if (strpos($details2, 'BEER') !== false || strpos($details2, 'LAGER') !== false || strpos($details2, 'ALE') !== false) {
            return 'BEER';
        }
        
        // Default: if it's in Foreign Liquor mode but doesn't match above, treat as IMFL
        return 'IMFL';
        
    } elseif ($mode === 'C' || $mode === 'CL') {
        // Country liquor detection
        $cl_keywords = ['COUNTRY', 'CL', 'DESI', 'LOCAL', 'TRADITIONAL'];
        foreach ($cl_keywords as $keyword) {
            if (strpos($details2, $keyword) !== false) {
                return 'CL';
            }
        }
        
        return 'CL'; // Default for CL mode
    }
    
    return 'OTHER';
}

/**
 * Get item size from CC in tblsubclass or extract from details - ENHANCED
 */
function getItemSize($conn, $item_code, $mode) {
    // First try to get size from DETAILS2 in tblitemmaster with better extraction
    $query = "SELECT im.DETAILS2, sc.CC 
              FROM tblitemmaster im 
              LEFT JOIN tblsubclass sc ON im.DETAILS2 = sc.ITEM_GROUP AND sc.LIQ_FLAG = ?
              WHERE im.CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $mode, $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    // Priority 1: Use CC from tblsubclass if available and valid
    if ($item_data && $item_data['CC'] > 0) {
        return (float)$item_data['CC'];
    }
    
    // Priority 2: Extract from DETAILS2 with improved pattern matching
    if ($item_data && !empty($item_data['DETAILS2'])) {
        $details2 = $item_data['DETAILS2'];
        // Enhanced size extraction (handles various formats)
        if (preg_match('/(\d+(?:\.\d+)?)\s*ML/i', $details2, $matches)) {
            $size = (float)$matches[1];
            // Common size validation
            $common_sizes = [30, 60, 90, 120, 180, 250, 330, 350, 500, 650, 750, 1000, 1500];
            foreach ($common_sizes as $common_size) {
                if (abs($size - $common_size) <= 10) { // Allow small variations
                    return $common_size;
                }
            }
            return $size;
        }
    }
    
    // Priority 3: Try to get from DETAILS in tblitemmaster
    $query = "SELECT DETAILS FROM tblitemmaster WHERE CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($item_data && !empty($item_data['DETAILS'])) {
        // Try to extract size from item name
        if (preg_match('/(\d+(?:\.\d+)?)\s*ML/i', $item_data['DETAILS'], $matches)) {
            $size = (float)$matches[1];
            return $size;
        }
    }
    
    // Default sizes based on category and mode
    $category = getItemCategory($conn, $item_code, $mode);
    switch ($category) {
        case 'IMFL':
            return 750; // Standard liquor bottle
        case 'BEER':
            return 650; // Standard beer bottle/can
        case 'CL':
            return 180; // Standard country liquor pouch
        default:
            return 750; // Common default
    }
}

/**
 * Generate bills with volume limits - ENHANCED MULTI-CATEGORY LOGIC WITH DEBUGGING
 */
function generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id) {
    $category_limits = getCategoryLimits($conn, $comp_id);

    // OPTIMIZATION: Log volume limits for debugging (only in development)
    // error_log("Volume limits for CompID $comp_id: " . json_encode($category_limits));

    $bills = [];

    foreach ($date_array as $date_index => $sale_date) {
        $daily_bills = [];

        // Collect all items for this day with enhanced categorization
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

        // OPTIMIZATION: Log items being processed for debugging
        // error_log("Processing " . count($all_items) . " items for date $sale_date");

        // Create bills using the ENHANCED multi-category algorithm
        $bills_for_day = createMultiCategoryBills($all_items, $category_limits);

        // OPTIMIZATION: Log bill creation results
        // error_log("Created " . count($bills_for_day) . " bills for date $sale_date");

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
 * Create optimized bills with proper multi-category handling - ENHANCED
 */
function createMultiCategoryBills($all_items, $category_limits) {
    $bills = [];
    
    if (empty($all_items)) {
        return [];
    }
    
    // Organize items by category with quantity tracking
    $category_pools = [];
    foreach ($all_items as $item) {
        $category = $item['category'];
        if (!isset($category_pools[$category])) {
            $category_pools[$category] = [];
        }
        
        $category_pools[$category][] = [
            'code' => $item['code'],
            'rate' => $item['rate'],
            'size' => $item['size'],
            'name' => $item['name'],
            'total_qty' => $item['qty'],
            'remaining_qty' => $item['qty']
        ];
    }
    
    // Sort each category's items by size descending (largest first for better packing)
    foreach ($category_pools as &$pool) {
        usort($pool, function($a, $b) {
            return $b['size'] <=> $a['size'];
        });
    }
    
    // Continue creating bills until all items are allocated
    $iteration_count = 0;
    $max_iterations = 1000; // Safety limit to prevent infinite loops
    
    while (hasRemainingItems($category_pools) && $iteration_count < $max_iterations) {
        $current_bill = [];
        $category_volumes = []; // Track volume per category in current bill
        
        // Initialize category volumes
        foreach (array_keys($category_limits) as $category) {
            $category_volumes[$category] = 0;
        }
        
        // OPTIMIZATION: Process categories in priority order (IMFL first, then others)
        $category_order = ['IMFL', 'BEER', 'CL', 'OTHER'];
        $bill_has_items = false;

        foreach ($category_order as $category) {
            if (!isset($category_pools[$category]) || empty($category_pools[$category])) {
                continue;
            }

            $category_limit = $category_limits[$category] ?? 0;

            // Skip if category has no limit
            if ($category_limit <= 0) {
                continue;
            }

            $pool = &$category_pools[$category];
            
            foreach ($pool as &$item) {
                if ($item['remaining_qty'] <= 0) {
                    continue;
                }
                
                $current_volume = $category_volumes[$category];
                $available_space = $category_limit - $current_volume;
                
                if ($available_space >= $item['size']) {
                    // Calculate how many can fit
                    $max_fit = floor($available_space / $item['size']);
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
                        $category_volumes[$category] += $qty_to_add * $item['size'];
                    }
                }
                
                // Check if we've reached the category limit
                if ($category_volumes[$category] >= $category_limit) {
                    break; // Move to next category
                }
            }
        }
        
        // Add smaller items to fill remaining space (optimization pass)
        if (!empty($current_bill)) {
            $current_bill = fillRemainingSpace($current_bill, $category_pools, $category_volumes, $category_limits);
        }
        
        // If we created a bill with items, add it to the bills list
        if (!empty($current_bill)) {
            $bills[] = $current_bill;
        }
        
        $iteration_count++;
    }
    
    // Safety check: if we hit max iterations, force create bills with remaining items
    if ($iteration_count >= $max_iterations && hasRemainingItems($category_pools)) {
        $forced_bills = createForcedBills($category_pools);
        $bills = array_merge($bills, $forced_bills);
    }
    
    return $bills;
}

/**
 * Check if there are any remaining items across all categories
 */
function hasRemainingItems($category_pools) {
    foreach ($category_pools as $pool) {
        foreach ($pool as $item) {
            if ($item['remaining_qty'] > 0) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Fill remaining space in bill with smaller items - OPTIMIZATION
 */
function fillRemainingSpace($current_bill, &$category_pools, $category_volumes, $category_limits) {
    foreach ($category_pools as $category => &$pool) {
        $category_limit = $category_limits[$category] ?? 0;
        $current_volume = $category_volumes[$category] ?? 0;
        $available_space = $category_limit - $current_volume;
        
        if ($available_space <= 0) {
            continue;
        }
        
        // Sort items by size ascending (smallest first for filling space)
        usort($pool, function($a, $b) {
            return $a['size'] <=> $b['size'];
        });
        
        foreach ($pool as &$item) {
            if ($item['remaining_qty'] <= 0) {
                continue;
            }
            
            if ($item['size'] <= $available_space) {
                $max_fit = floor($available_space / $item['size']);
                $qty_to_add = min($item['remaining_qty'], $max_fit);
                
                if ($qty_to_add > 0) {
                    // Add to current bill
                    $bill_item_key = findBillItem($current_bill, $item['code']);
                    
                    if ($bill_item_key !== false) {
                        $current_bill[$bill_item_key]['qty'] += $qty_to_add;
                        $current_bill[$bill_item_key]['amount'] += $qty_to_add * $item['rate'];
                    } else {
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
                    $available_space -= $qty_to_add * $item['size'];
                    $category_volumes[$category] += $qty_to_add * $item['size'];
                }
            }
            
            if ($available_space <= 0) {
                break;
            }
        }
    }
    
    return $current_bill;
}

/**
 * Create forced bills for any remaining items (safety mechanism)
 */
function createForcedBills(&$category_pools) {
    $forced_bills = [];
    
    foreach ($category_pools as $category => &$pool) {
        foreach ($pool as &$item) {
            while ($item['remaining_qty'] > 0) {
                $qty_to_add = min($item['remaining_qty'], 10); // Add max 10 per forced bill
                
                $forced_bill = [[
                    'code' => $item['code'],
                    'qty' => $qty_to_add,
                    'rate' => $item['rate'],
                    'size' => $item['size'],
                    'amount' => $qty_to_add * $item['rate'],
                    'name' => $item['name'],
                    'category' => $category
                ]];
                
                $forced_bills[] = $forced_bill;
                $item['remaining_qty'] -= $qty_to_add;
            }
        }
    }
    
    return $forced_bills;
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