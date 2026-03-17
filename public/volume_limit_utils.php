<?php
// volume_limit_utils.php - OPTIMIZED WITH CACHING
// Updated to use new database structure:
// - tblcategory: CATEGORY_CODE, CATEGORY_NAME, LIQ_FLAG
// - tblclass_new: CLASS_CODE, CLASS_NAME, CATEGORY_CODE, LIQ_FLAG
// - tblsubclass_new: SUBCLASS_CODE, SUBCLASS_NAME, CLASS_CODE
// - tblsize: SIZE_CODE, SIZE_DESC, ML_VOLUME, CC, LIQ_FLAG
//
// Volume Limit Categories:
// - IMFL: Spirit (CAT001) + Wine (CAT002) categories
// - BEER: Fermented Beer (CAT003) + Mild Beer (CAT004) categories
// - CL: Country Liquor (CAT005) category

// Global caches
$category_cache = [];
$size_cache = [];
$limits_cache = [];

/**
 * Get the daily stock table name for a specific date
 */
if (!function_exists('getDailyStockTableForDate')) {
function getDailyStockTableForDate($conn, $comp_id, $date) {
    $current_date = new DateTime();
    $sale_date = new DateTime($date);
    
    // If sale date is in the future, use current month table
    if ($sale_date > $current_date) {
        return "tbldailystock_" . $comp_id;
    }
    
    $current_month = $current_date->format('Y-m');
    $date_month = $sale_date->format('Y-m');
    
    if ($date_month === $current_month) {
        return "tbldailystock_" . $comp_id;
    } else {
        $date_month_short = $sale_date->format('m');
        $date_year_short = $sale_date->format('y');
        return "tbldailystock_" . $comp_id . "_" . $date_month_short . "_" . $date_year_short;
    }
}
}

/**
 * Check if stock is available for an item on a specific date
 * Returns true if closing stock > 0, false otherwise
 */
if (!function_exists('isStockAvailable')) {
function isStockAvailable($conn, $item_code, $date, $comp_id) {
    $table_name = getDailyStockTableForDate($conn, $comp_id, $date);
    $month_year = date('Y-m', strtotime($date));
    $day_num = sprintf('%02d', date('d', strtotime($date)));
    $closing_column = "DAY_{$day_num}_CLOSING";
    
    // Check if table exists
    $check_table = $conn->query("SHOW TABLES LIKE '$table_name'");
    if ($check_table->num_rows === 0) {
        return false; // No table = no stock
    }
    
    // Check if column exists
    $check_column = $conn->query("SHOW COLUMNS FROM $table_name LIKE '$closing_column'");
    if ($check_column->num_rows === 0) {
        return false; // No column = no stock
    }
    
    // Get closing stock for this date
    $query = "SELECT $closing_column FROM $table_name WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $item_code, $month_year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $closing_stock = 0;
    if ($row = $result->fetch_assoc()) {
        $closing_stock = (float)($row[$closing_column] ?? 0);
    }
    $stmt->close();
    
    return $closing_stock > 0;
}
}

/**
 * Get closing stock for an item on a specific date
 */
if (!function_exists('getClosingStockForDate')) {
function getClosingStockForDate($conn, $item_code, $date, $comp_id) {
    $table_name = getDailyStockTableForDate($conn, $comp_id, $date);
    $month_year = date('Y-m', strtotime($date));
    $day_num = sprintf('%02d', date('d', strtotime($date)));
    $closing_column = "DAY_{$day_num}_CLOSING";
    
    // Check if table exists
    $check_table = $conn->query("SHOW TABLES LIKE '$table_name'");
    if ($check_table->num_rows === 0) {
        return 0;
    }
    
    // Check if column exists
    $check_column = $conn->query("SHOW COLUMNS FROM $table_name LIKE '$closing_column'");
    if ($check_column->num_rows === 0) {
        return 0;
    }
    
    $query = "SELECT $closing_column FROM $table_name WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $item_code, $month_year);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $closing_stock = 0;
    if ($row = $result->fetch_assoc()) {
        $closing_stock = (float)($row[$closing_column] ?? 0);
    }
    $stmt->close();
    
    return $closing_stock;
}
}

/**
 * Sanitize distribution - remove quantities from dates with no stock
 * Returns cleaned distribution array
 */
if (!function_exists('sanitizeDistribution')) {
function sanitizeDistribution($conn, $item_code, $date_array, $distribution, $comp_id, $dry_dates = [], $unavailable_dates = []) {
    $cleaned_distribution = $distribution;
    $removed_count = 0;
    
    // Create lookup sets for faster checking
    $dry_dates_set = array_flip($dry_dates);
    $unavailable_dates_set = array_flip($unavailable_dates);
    
    foreach ($date_array as $index => $date) {
        // Skip if quantity is already 0
        if ($cleaned_distribution[$index] <= 0) {
            continue;
        }
        
        // Skip dry days
        if (isset($dry_dates_set[$date])) {
            $removed_count += $cleaned_distribution[$index];
            $cleaned_distribution[$index] = 0;
            continue;
        }
        
        // Skip dates with existing sales
        if (isset($unavailable_dates_set[$date])) {
            $removed_count += $cleaned_distribution[$index];
            $cleaned_distribution[$index] = 0;
            continue;
        }
        
        // Check if stock is available on this date
        $closing_stock = getClosingStockForDate($conn, $item_code, $date, $comp_id);
        
        if ($closing_stock <= 0) {
            // Stock not available - remove quantity
            $removed_count += $cleaned_distribution[$index];
            $cleaned_distribution[$index] = 0;
        }
    }
    
    if ($removed_count > 0) {
        error_log("SANITIZE: Removed $removed_count units from distribution for item $item_code due to no stock on specific dates");
    }
    
    return $cleaned_distribution;
}
}

/**
 * Get category name and LIQ_FLAG from category code
 */
function getCategoryInfo($conn, $category_code) {
    $query = "SELECT CATEGORY_NAME, LIQ_FLAG FROM tblcategory WHERE CATEGORY_CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $category_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ?: ['CATEGORY_NAME' => '', 'LIQ_FLAG' => ''];
}

/**
 * Get category code from item code using the new hierarchy
 */
function getItemCategoryCode($conn, $item_code) {
    // Get item's CLASS_CODE_NEW and then get the CATEGORY_CODE from tblclass_new
    $query = "SELECT im.CLASS_CODE_NEW, cn.CATEGORY_CODE 
              FROM tblitemmaster im 
              LEFT JOIN tblclass_new cn ON im.CLASS_CODE_NEW = cn.CLASS_CODE
              WHERE im.CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row['CATEGORY_CODE'] ?? '';
}

/**
 * Get category limits from tblcompany - CACHED
 */
function getCategoryLimits($conn, $comp_id) {
    global $limits_cache;
    
    if (!isset($limits_cache[$comp_id])) {
        $query = "SELECT IMFLLimit, BEERLimit, CLLimit FROM tblcompany WHERE CompID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $comp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $limits = $result->fetch_assoc();
        $stmt->close();
        
        $limits_cache[$comp_id] = [
            'IMFL' => (float)($limits['IMFLLimit'] ?? 1000),
            'BEER' => (float)($limits['BEERLimit'] ?? 0),
            'CL' => (float)($limits['CLLimit'] ?? 0)
        ];
    }
    
    return $limits_cache[$comp_id];
}

/**
 * Determine item category based on CATEGORY_CODE from tblitemmaster
 * Now uses the new database structure:
 * - IMFL: Spirit (CAT001) + Wine (CAT002) categories
 * - BEER: Fermented Beer (CAT003) + Mild Beer (CAT004) categories
 * - CL: Country Liquor (CAT005) category
 */
function getItemCategory($conn, $item_code, $mode) {
    global $category_cache;
    
    $cache_key = $item_code . '|' . $mode;
    
    if (isset($category_cache[$cache_key])) {
        return $category_cache[$cache_key];
    }
    
    // Get item details including CATEGORY_CODE from tblitemmaster
    // Join with tblclass_new to get the category mapping
    $query = "SELECT im.CATEGORY_CODE, im.CLASS_CODE_NEW, cn.CATEGORY_CODE as CLASS_CATEGORY_CODE, cat.LIQ_FLAG as CAT_LIQ_FLAG
              FROM tblitemmaster im 
              LEFT JOIN tblclass_new cn ON im.CLASS_CODE_NEW = cn.CLASS_CODE
              LEFT JOIN tblcategory cat ON cn.CATEGORY_CODE = cat.CATEGORY_CODE
              WHERE im.CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    if (!$item_data) {
        $category_cache[$cache_key] = 'OTHER';
        return 'OTHER';
    }
    
    // Get the category code - prefer from class mapping
    $category_code = $item_data['CLASS_CATEGORY_CODE'] ?? $item_data['CATEGORY_CODE'] ?? '';
    $liq_flag = $item_data['CAT_LIQ_FLAG'] ?? '';
    
    // Determine category based on category code
    // IMFL: Spirit (CAT001) + Wine (CAT002)
    if ($category_code === 'CAT001' || $category_code === 'CAT002') {
        $category_cache[$cache_key] = 'IMFL';
        return 'IMFL';
    }
    
    // BEER: Fermented Beer (CAT003) + Mild Beer (CAT004)
    if ($category_code === 'CAT003' || $category_code === 'CAT004') {
        $category_cache[$cache_key] = 'BEER';
        return 'BEER';
    }
    
    // CL: Country Liquor (CAT005)
    if ($category_code === 'CAT005') {
        $category_cache[$cache_key] = 'CL';
        return 'CL';
    }
    
    // Fallback: Use LIQ_FLAG from category if available
    if (!empty($liq_flag)) {
        switch (strtoupper($liq_flag)) {
            case 'F':
                $category_cache[$cache_key] = 'IMFL';
                return 'IMFL';
            case 'C':
                $category_cache[$cache_key] = 'CL';
                return 'CL';
            case 'O':
                // Other (non-liquor) - could be soda, cold drinks, etc.
                $category_cache[$cache_key] = 'OTHER';
                return 'OTHER';
        }
    }
    
    // Fallback: Use mode to determine category
    if ($mode === 'F' || $mode === 'FL') {
        $category_cache[$cache_key] = 'IMFL';
        return 'IMFL';
    } elseif ($mode === 'C' || $mode === 'CL') {
        $category_cache[$cache_key] = 'CL';
        return 'CL';
    }
    
    $category_cache[$cache_key] = 'OTHER';
    return 'OTHER';
}

/**
 * Get item size from SIZE_CODE in tblsize table - CACHED
 * Now uses the new database structure with SIZE_CODE from tblitemmaster
 */
function getItemSize($conn, $item_code, $mode) {
    global $size_cache;
    
    $cache_key = $item_code . '|' . $mode;
    
    if (isset($size_cache[$cache_key])) {
        return $size_cache[$cache_key];
    }
    
    // First try to get size from SIZE_CODE in tblitemmaster joined with tblsize
    $query = "SELECT im.SIZE_CODE, sz.ML_VOLUME, sz.CC
              FROM tblitemmaster im 
              LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE AND sz.LIQ_FLAG = ?
              WHERE im.CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $mode, $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    // Priority 1: Use ML_VOLUME from tblsize if available and valid
    if ($item_data && !empty($item_data['ML_VOLUME']) && $item_data['ML_VOLUME'] > 0) {
        $size_cache[$cache_key] = (float)$item_data['ML_VOLUME'];
        return (float)$item_data['ML_VOLUME'];
    }
    
    // Priority 2: Use CC from tblsize if available and valid
    if ($item_data && !empty($item_data['CC']) && $item_data['CC'] > 0) {
        $size_cache[$cache_key] = (float)$item_data['CC'];
        return (float)$item_data['CC'];
    }
    
    // Priority 3: Try to get from DETAILS2 in tblitemmaster with better extraction
    $query = "SELECT im.DETAILS2 
              FROM tblitemmaster im 
              WHERE im.CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item_data = $result->fetch_assoc();
    $stmt->close();
    
    if ($item_data && !empty($item_data['DETAILS2'])) {
        $details2 = $item_data['DETAILS2'];
        // Enhanced size extraction (handles various formats)
        if (preg_match('/(\d+(?:\.\d+)?)\s*ML/i', $details2, $matches)) {
            $size = (float)$matches[1];
            // Common size validation
            $common_sizes = [30, 60, 90, 120, 180, 250, 330, 350, 500, 650, 750, 1000, 1500, 1750, 2000, 3000, 4500];
            foreach ($common_sizes as $common_size) {
                if (abs($size - $common_size) <= 10) { // Allow small variations
                    $size_cache[$cache_key] = $common_size;
                    return $common_size;
                }
            }
            $size_cache[$cache_key] = $size;
            return $size;
        }
    }
    
    // Priority 4: Try to get from DETAILS in tblitemmaster
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
            $size_cache[$cache_key] = $size;
            return $size;
        }
    }
    
    // Default sizes based on category and mode
    $category = getItemCategory($conn, $item_code, $mode);
    switch ($category) {
        case 'IMFL':
            $size_cache[$cache_key] = 750;
            return 750;
        case 'BEER':
            $size_cache[$cache_key] = 650;
            return 650;
        case 'CL':
            $size_cache[$cache_key] = 180;
            return 180;
        default:
            $size_cache[$cache_key] = 750;
            return 750;
    }
}

/**
 * Generate bills with volume limits - ENHANCED MULTI-CATEGORY LOGIC
 * Now accepts available_dates parameter to filter out dry days
 * MODIFIED: Now also validates per-day stock availability
 */
function generateBillsWithLimits($conn, $items_data, $date_array, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id, $available_dates = [], $dry_dates = [], $unavailable_dates = []) {
    // DEBUG: Log what distribution was received
    $distLog = "=== generateBillsWithLimits START ===" . PHP_EOL;
    $distLog .= "Date array (" . count($date_array) . "): " . implode(',', $date_array) . PHP_EOL;
    foreach ($daily_sales_data as $item => $dist) {
        $distSum = array_sum($dist);
        $distLog .= "Item $item distribution (" . count($dist) . "): [" . implode(',', $dist) . "] Sum=$distSum" . PHP_EOL;
    }
    error_log($distLog);
    
    $category_limits = getCategoryLimits($conn, $comp_id);
    
    // ============================================================
    // CRITICAL FIX: Sanitize distribution - remove invalid dates
    // This ensures backend is the FINAL AUTHORITY
    // ============================================================
    $sanitized_daily_sales_data = [];
    foreach ($items_data as $item_code => $item_data) {
        $original_distribution = $daily_sales_data[$item_code] ?? array_fill(0, count($date_array), 0);
        
        // Sanitize: remove quantities from dates with no stock
        $sanitized_distribution = sanitizeDistribution(
            $conn, 
            $item_code, 
            $date_array, 
            $original_distribution, 
            $comp_id,
            $dry_dates,
            $unavailable_dates
        );
        
        $sanitized_daily_sales_data[$item_code] = $sanitized_distribution;
        
        // Log if distribution changed
        $original_sum = array_sum($original_distribution);
        $sanitized_sum = array_sum($sanitized_distribution);
        if ($original_sum !== $sanitized_sum) {
            error_log("SANITIZED: Item $item_code - Original: $original_sum, Sanitized: $sanitized_sum");
        }
    }
    
    // Filter out dry days from the date array - only process available dates
    $valid_date_indices = [];
    if (!empty($available_dates)) {
        // Create a lookup set for faster checking
        $available_dates_set = array_flip($available_dates);
        
        foreach ($date_array as $index => $date) {
            // Only include dates that are in the available_dates list
            if (isset($available_dates_set[$date])) {
                $valid_date_indices[$index] = $date;
            }
        }
    } else {
        // If no available_dates provided, assume all dates are valid (backward compatibility)
        $valid_date_indices = array_combine(array_keys($date_array), $date_array);
    }
    
    $bills = [];
    
    foreach ($valid_date_indices as $date_index => $sale_date) {
        $daily_bills = [];
        
        // Collect all items for this day with enhanced categorization
        $all_items = [];
        foreach ($items_data as $item_code => $item_data) {
            // Use SANITIZED distribution data
            $qty = $sanitized_daily_sales_data[$item_code][$date_index] ?? 0;
            
            if ($qty > 0) {
                // CRITICAL: Double-check stock availability before adding to bill
                if (!isStockAvailable($conn, $item_code, $sale_date, $comp_id)) {
                    error_log("BLOCKED: Item $item_code on $sale_date - no stock available");
                    continue; // Skip this date for this item
                }
                
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
        
        // Create bills using the ENHANCED multi-category algorithm
        $bills_for_day = createMultiCategoryBills($all_items, $category_limits);
        
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
        
        // Try to add items from each category to the current bill
        foreach ($category_pools as $category => &$pool) {
            $category_limit = $category_limits[$category] ?? 0;
            
            // Skip if category has no limit or no items
            if ($category_limit <= 0 || empty($pool)) {
                continue;
            }
            
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
 * Distribute sales randomly across days
 * Changed from uniform distribution to random distribution
 */
function distributeSales($total_qty, $days_count) {
    if ($total_qty <= 0 || $days_count <= 0) return array_fill(0, $days_count, 0);
    
    // Initialize all days with 0
    $daily_sales = array_fill(0, $days_count, 0);
    
    // Randomly distribute each unit across days
    for ($i = 0; $i < $total_qty; $i++) {
        $random_day = mt_rand(0, $days_count - 1);
        $daily_sales[$random_day]++;
    }
    
    return $daily_sales;
}

/**
 * Distribute sales only to available dates (excluding dry days)
 * Used by generate_bills_ultra_fast.php
 * Changed from uniform distribution to random distribution
 */
if (!function_exists('distributeSalesWithGlobalRestrictions')) {
    function distributeSalesWithGlobalRestrictions($total_qty, $available_dates) {
        if ($total_qty <= 0 || empty($available_dates)) return [];
        
        $available_days_count = count($available_dates);
        
        // Initialize all available days with 0
        $distribution = array_fill(0, $available_days_count, 0);
        
        // Randomly distribute each unit across available days
        for ($i = 0; $i < $total_qty; $i++) {
            $random_day = mt_rand(0, $available_days_count - 1);
            $distribution[$random_day]++;
        }
        
        return $distribution;
    }
}
?>