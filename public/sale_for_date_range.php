<?php
session_start();
require_once 'drydays_functions.php';
require_once 'license_functions.php';
require_once 'cash_memo_functions.php';

// ============================================================================
// ENHANCED DEBUG LOGGING - SPECIFIC FOR STOCK CASCADING ISSUES
// ============================================================================

function debugCascadingLog($message, $data = null, $level = 'INFO') {
    $logFile = '../logs/cascading_debug_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message";
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $logMessage .= ": " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $logMessage .= ": " . $data;
        }
    }
    
    $logMessage .= PHP_EOL;
    
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Main logging function (kept for compatibility)
function logMessage($message, $level = 'INFO') {
    debugCascadingLog($message, null, $level);
}

// Function to log array data
function logArray($data, $title = 'Array data') {
    ob_start();
    print_r($data);
    $output = ob_get_clean();
    debugCascadingLog($title, $output);
}

// DEBUG: Log page access
debugCascadingLog("=== PAGE ACCESS ===");
debugCascadingLog("Request method: " . $_SERVER['REQUEST_METHOD']);
debugCascadingLog("Search term: '" . ($_GET['search'] ?? '') . "'");
debugCascadingLog("Session user_id: " . ($_SESSION['user_id'] ?? 'none'));
debugCascadingLog("Session CompID: " . ($_SESSION['CompID'] ?? 'none'));

// Function to clear session quantities
function clearSessionQuantities() {
    if (isset($_SESSION['sale_quantities'])) {
        unset($_SESSION['sale_quantities']);
        debugCascadingLog("Session quantities cleared");
    }
}

// ============================================================================
// ENHANCED STOCK VALIDATION FUNCTION - WITH BETTER DEBUGGING
// ============================================================================

function validateStockWithFallback($conn, $item_code, $requested_qty, $date, $comp_id) {
    debugCascadingLog("DEBUG validateStockWithFallback START", [
        'item_code' => $item_code,
        'requested_qty' => $requested_qty,
        'date' => $date,
        'comp_id' => $comp_id
    ], 'DEBUG');
    
    if ($requested_qty <= 0) {
        debugCascadingLog("Zero quantity requested, validation passes", null, 'DEBUG');
        return ['valid' => true, 'available' => 0, 'source' => 'zero_qty'];
    }
    
    try {
        // FIRST: Check if item exists in master table
        debugCascadingLog("Checking item existence in tblitemmaster", $item_code, 'DEBUG');
        $check_item_query = "SELECT COUNT(*) as item_exists, DETAILS FROM tblitemmaster WHERE CODE = ?";
        $check_item_stmt = $conn->prepare($check_item_query);
        $check_item_stmt->bind_param("s", $item_code);
        $check_item_stmt->execute();
        $item_result = $check_item_stmt->get_result();
        $item_row = $item_result->fetch_assoc();
        $item_exists = $item_row['item_exists'] > 0;
        $item_name = $item_row['DETAILS'] ?? 'Unknown';
        $check_item_stmt->close();
        
        if (!$item_exists) {
            debugCascadingLog("Item $item_code does not exist in tblitemmaster", $item_code, 'ERROR');
            return ['valid' => false, 'available' => 0, 'source' => 'not_found', 'error' => 'Item not found in master table'];
        }
        
        debugCascadingLog("Item exists in master table", ['code' => $item_code, 'name' => $item_name], 'DEBUG');
        
        // SECOND: Get the correct daily stock table for the date
        $daily_stock_table = getDailyStockTableName($conn, $date, $comp_id);
        debugCascadingLog("Daily stock table determined", $daily_stock_table, 'DEBUG');
        
        // Check if table exists
        $check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
        $table_result = $conn->query($check_table_query);
        
        if ($table_result->num_rows == 0) {
            // Table doesn't exist - this is OK for new items or archive months
            debugCascadingLog("Daily stock table '$daily_stock_table' not found for date $date", null, 'INFO');
            return ['valid' => true, 'available' => 0, 'source' => 'new_item_no_table', 'note' => 'No daily stock table found'];
        }
        
        debugCascadingLog("Daily stock table exists", $daily_stock_table, 'DEBUG');
        
        // THIRD: Try to get stock from daily stock table
        $stock_value = getStockAsOfDate($conn, $item_code, $date, $comp_id);
        
        debugCascadingLog("Stock retrieval result", [
            'item_code' => $item_code,
            'date' => $date,
            'stock_value' => $stock_value
        ], 'DEBUG');
        
        // If we got stock > 0 from daily table
        if ($stock_value > 0) {
            if ($requested_qty > $stock_value) {
                debugCascadingLog("Stock validation failed - insufficient stock", [
                    'item_code' => $item_code,
                    'available' => $stock_value,
                    'requested' => $requested_qty,
                    'deficit' => $requested_qty - $stock_value
                ], 'WARNING');
                return ['valid' => false, 'available' => $stock_value, 'source' => 'daily', 'error' => 'Insufficient stock'];
            }
            debugCascadingLog("Stock validation passed - sufficient stock", [
                'item_code' => $item_code,
                'available' => $stock_value,
                'requested' => $requested_qty
            ], 'INFO');
            return ['valid' => true, 'available' => $stock_value, 'source' => 'daily'];
        }
        
        // If stock is 0 or negative, it's still valid (just means no stock)
        // But we need to check if the item actually has a record in the daily table
        $check_record_query = "SELECT COUNT(*) as has_record FROM $daily_stock_table WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $check_record_stmt = $conn->prepare($check_record_query);
        $month_year = date('Y-m', strtotime($date));
        $check_record_stmt->bind_param("ss", $item_code, $month_year);
        $check_record_stmt->execute();
        $record_result = $check_record_stmt->get_result();
        $has_record = $record_result->fetch_assoc()['has_record'] > 0;
        $check_record_stmt->close();
        
        if ($has_record) {
            // Item has a record in daily table with 0 stock
            if ($requested_qty > 0) {
                debugCascadingLog("Item has 0 stock in daily table but user requested quantity", [
                    'item_code' => $item_code,
                    'requested_qty' => $requested_qty
                ], 'WARNING');
                return ['valid' => false, 'available' => 0, 'source' => 'daily_zero', 'error' => 'Zero stock in daily table'];
            }
            debugCascadingLog("Item has record in daily table with 0 stock", $item_code, 'INFO');
            return ['valid' => true, 'available' => 0, 'source' => 'daily_zero'];
        }
        
        debugCascadingLog("Item exists in master but has no record in daily table", [
            'item_code' => $item_code,
            'table' => $daily_stock_table,
            'month_year' => $month_year
        ], 'DEBUG');
        
        // Item exists in master but has no record in daily table
        // This could be:
        // 1. New item added after daily stock was generated
        // 2. Item from a different month
        // 3. Data synchronization issue
        
        // Check if we have any stock record at all for this item
        $fin_year_id = $_SESSION['FIN_YEAR_ID'] ?? null;
        if ($fin_year_id) {
            $current_stock_column = "Current_Stock" . $comp_id;
            
            $stock_query = "SELECT $current_stock_column FROM tblitem_stock 
                           WHERE ITEM_CODE = ? AND FIN_YEAR = ?";
            $stock_stmt = $conn->prepare($stock_query);
            $stock_stmt->bind_param("ss", $item_code, $fin_year_id);
            $stock_stmt->execute();
            $stock_result = $stock_stmt->get_result();
            
            if ($stock_result->num_rows > 0) {
                $stock_data = $stock_result->fetch_assoc();
                $item_stock = $stock_data[$current_stock_column] ?? 0;
                $stock_stmt->close();
                
                debugCascadingLog("Fallback stock from tblitem_stock", [
                    'item_code' => $item_code,
                    'stock' => $item_stock,
                    'column' => $current_stock_column
                ], 'INFO');
                
                if ($requested_qty > $item_stock) {
                    debugCascadingLog("Stock validation failed via fallback", [
                        'item_code' => $item_code,
                        'available' => $item_stock,
                        'requested' => $requested_qty
                    ], 'WARNING');
                    return ['valid' => false, 'available' => $item_stock, 'source' => 'fallback', 'error' => 'Insufficient fallback stock'];
                }
                
                debugCascadingLog("Stock validation passed via fallback", [
                    'item_code' => $item_code,
                    'available' => $item_stock,
                    'requested' => $requested_qty
                ], 'INFO');
                return ['valid' => true, 'available' => $item_stock, 'source' => 'fallback'];
            }
            $stock_stmt->close();
        }
        
        // Final scenario: Item exists but we can't determine stock
        // For business continuity, we'll log a warning but allow the sale
        debugCascadingLog("WARNING: Item exists but stock validation inconclusive. Allowing sale.", [
            'item_code' => $item_code,
            'requested_qty' => $requested_qty
        ], 'WARNING');
        return ['valid' => true, 'available' => 0, 'source' => 'inconclusive', 'warning' => 'Stock validation inconclusive'];
        
    } catch (Exception $e) {
        debugCascadingLog("Error in validateStockWithFallback", [
            'item_code' => $item_code,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'ERROR');
        // For business continuity, return valid=true but with error source
        return ['valid' => true, 'available' => 0, 'source' => 'error_but_allowed', 'error' => $e->getMessage()];
    }
}

// ============================================================================
// DAILY STOCK TABLE FUNCTIONS
// ============================================================================

// FIXED: Function to get the correct daily stock table name
function getDailyStockTableName($conn, $date, $comp_id) {
    $current_month = date('Y-m');
    $date_month = date('Y-m', strtotime($date));
    
    if ($date_month === $current_month) {
        // Use current month table (no suffix)
        $table_name = "tbldailystock_" . $comp_id;
        debugCascadingLog("Using current month table", [
            'date' => $date,
            'current_month' => $current_month,
            'date_month' => $date_month,
            'table' => $table_name
        ], 'DEBUG');
        return $table_name;
    } else {
        // Use archived month table (with suffix)
        $month_suffix = date('m_y', strtotime($date));
        $table_name = "tbldailystock_" . $comp_id . "_" . $month_suffix;
        debugCascadingLog("Using archived month table", [
            'date' => $date,
            'current_month' => $current_month,
            'date_month' => $date_month,
            'month_suffix' => $month_suffix,
            'table' => $table_name
        ], 'DEBUG');
        return $table_name;
    }
}

// FIXED: Function to get stock as of a specific date
function getStockAsOfDate($conn, $item_code, $date, $comp_id) {
    try {
        debugCascadingLog("getStockAsOfDate START", [
            'item_code' => $item_code,
            'date' => $date,
            'comp_id' => $comp_id
        ], 'DEBUG');
        
        // Get the correct table name
        $daily_stock_table = getDailyStockTableName($conn, $date, $comp_id);
        
        // Check if the table exists
        $check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
        $table_result = $conn->query($check_table_query);
        
        if ($table_result->num_rows == 0) {
            // Table doesn't exist
            debugCascadingLog("Table does not exist", $daily_stock_table, 'DEBUG');
            return 0;
        }
        
        // Extract day number from date
        $day_num = date('d', strtotime($date));
        $day_column = "DAY_" . str_pad($day_num, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        debugCascadingLog("Checking stock column", [
            'day_num' => $day_num,
            'day_column' => $day_column,
            'table' => $daily_stock_table
        ], 'DEBUG');
        
        // Check if the column exists
        $check_column_query = "SHOW COLUMNS FROM $daily_stock_table LIKE '$day_column'";
        $column_result = $conn->query($check_column_query);
        
        if ($column_result->num_rows == 0) {
            debugCascadingLog("Column does not exist", $day_column, 'DEBUG');
            return 0;
        }
        
        // Get the month for the date
        $month_year = date('Y-m', strtotime($date));
        
        // Query to get the closing stock
        $stock_query = "SELECT $day_column as stock_value 
                       FROM $daily_stock_table 
                       WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        
        debugCascadingLog("Executing stock query", [
            'query' => $stock_query,
            'params' => [$item_code, $month_year]
        ], 'DEBUG');
        
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("ss", $item_code, $month_year);
        $stock_stmt->execute();
        $stock_result = $stock_stmt->get_result();
        
        if ($stock_result->num_rows > 0) {
            $stock_data = $stock_result->fetch_assoc();
            $stock_value = $stock_data['stock_value'] ?? 0;
            $stock_stmt->close();
            
            debugCascadingLog("Stock retrieved successfully", [
                'item_code' => $item_code,
                'date' => $date,
                'stock_value' => $stock_value,
                'source' => $daily_stock_table
            ], 'DEBUG');
            
            return floatval($stock_value);
        } else {
            $stock_stmt->close();
            debugCascadingLog("No stock record found", [
                'item_code' => $item_code,
                'month_year' => $month_year,
                'table' => $daily_stock_table
            ], 'DEBUG');
            return 0;
        }
        
    } catch (Exception $e) {
        debugCascadingLog("Error getting stock for item $item_code on $date", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'ERROR');
        return 0;
    }
}

// ============================================================================
// ENHANCED DATE AVAILABILITY FUNCTIONS - INDEPENDENT PER ITEM
// ============================================================================

// Function to get all dates between start and end date
function getDatesBetween($start_date, $end_date) {
    $dates = [];
    $current = strtotime($start_date);
    $end = strtotime($end_date);
    
    while ($current <= $end) {
        $dates[] = date('Y-m-d', $current);
        $current = strtotime('+1 day', $current);
    }
    
    return $dates;
}

// FIXED: Function to get item's latest sale date - ONLY FOR THIS ITEM (INDEPENDENT)
function getItemLatestSaleDate($conn, $item_code, $comp_id) {
    debugCascadingLog("getItemLatestSaleDate START", [
        'item_code' => $item_code,
        'comp_id' => $comp_id
    ], 'DEBUG');
    
    $query = "SELECT MAX(BILL_DATE) as latest_sale_date 
              FROM tblsaleheader sh
              JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO
              WHERE sd.ITEM_CODE = ? AND sh.COMP_ID = ?
              GROUP BY sd.ITEM_CODE";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $item_code, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        debugCascadingLog("Latest sale date found", [
            'item_code' => $item_code,
            'latest_sale_date' => $row['latest_sale_date']
        ], 'DEBUG');
        return $row['latest_sale_date'];
    }
    
    debugCascadingLog("No previous sales found for item", $item_code, 'DEBUG');
    return null; // No previous sales for this item
}

// Function to get dry days for a date range
function getDryDaysForDateRange($conn, $start_date, $end_date) {
    debugCascadingLog("getDryDaysForDateRange START", [
        'start_date' => $start_date,
        'end_date' => $end_date
    ], 'DEBUG');
    
    $dry_days = [];
    
    try {
        // Check if the table exists
        $check_table = "SHOW TABLES LIKE 'tbldrydays'";
        $table_result = $conn->query($check_table);
        
        if ($table_result->num_rows == 0) {
            debugCascadingLog("Dry days table not found", null, 'DEBUG');
            return [];
        }
        
        // Query to get dry days in the date range
        $query = "SELECT DRY_DATE FROM tbldrydays 
                  WHERE DRY_DATE BETWEEN ? AND ? 
                  ORDER BY DRY_DATE";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $start_date, $end_date);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $dry_days[] = $row['DRY_DATE'];
        }
        
        $stmt->close();
        
        debugCascadingLog("Dry days retrieved", [
            'count' => count($dry_days),
            'dry_days' => $dry_days
        ], 'DEBUG');
        
    } catch (Exception $e) {
        debugCascadingLog("Error getting dry days", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'ERROR');
        return [];
    }
    
    return $dry_days;
}

// FIXED: Function to check item's date availability - INDEPENDENT PER ITEM
function getItemDateAvailability($conn, $item_code, $start_date, $end_date, $comp_id) {
    debugCascadingLog("getItemDateAvailability START", [
        'item_code' => $item_code,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'comp_id' => $comp_id
    ], 'DEBUG');
    
    $all_dates = getDatesBetween($start_date, $end_date);
    $available_dates = [];
    $blocked_dates = [];
    
    // Get item's latest sale date - INDEPENDENT FOR EACH ITEM
    $latest_sale_date = getItemLatestSaleDate($conn, $item_code, $comp_id);
    
    // Get dry days for the date range (shared across all items)
    $dry_days = getDryDaysForDateRange($conn, $start_date, $end_date);
    
    debugCascadingLog("Processing date availability", [
        'total_dates' => count($all_dates),
        'latest_sale_date' => $latest_sale_date,
        'dry_days_count' => count($dry_days)
    ], 'DEBUG');
    
    foreach ($all_dates as $date) {
        $is_available = true;
        $block_reason = "";
        
        // Check 1: Date must be after latest sale date FOR THIS ITEM
        if ($latest_sale_date && $date <= $latest_sale_date) {
            $is_available = false;
            $block_reason = "Sale already recorded on " . date('d-M-Y', strtotime($latest_sale_date));
        }
        
        // Check 2: Date must not be a dry day
        if (in_array($date, $dry_days)) {
            $is_available = false;
            $block_reason = "Dry day";
        }
        
        // Check 3: Date must not be in the future
        if (strtotime($date) > strtotime(date('Y-m-d'))) {
            $is_available = false;
            $block_reason = "Future date";
        }
        
        if ($is_available) {
            $available_dates[$date] = true;
        } else {
            $blocked_dates[$date] = $block_reason;
        }
    }
    
    $result = [
        'available_dates' => $available_dates,
        'blocked_dates' => $blocked_dates,
        'latest_sale_date' => $latest_sale_date,
        'total_dates' => count($all_dates),
        'available_count' => count($available_dates),
        'blocked_count' => count($blocked_dates)
    ];
    
    debugCascadingLog("Date availability result", [
        'item_code' => $item_code,
        'result' => $result
    ], 'DEBUG');
    
    return $result;
}

// Function to distribute sales only across available dates
function distributeSalesAcrossAvailableDates($total_qty, $date_array, $available_dates) {
    debugCascadingLog("distributeSalesAcrossAvailableDates START", [
        'total_qty' => $total_qty,
        'date_count' => count($date_array),
        'available_dates_count' => count($available_dates)
    ], 'DEBUG');
    
    if ($total_qty <= 0) {
        debugCascadingLog("Zero quantity, returning all zeros", null, 'DEBUG');
        return array_fill_keys($date_array, 0);
    }
    
    // Filter to get only available dates from the date array
    $available_dates_in_range = array_intersect($date_array, array_keys($available_dates));
    
    debugCascadingLog("Available dates in range", [
        'count' => count($available_dates_in_range),
        'dates' => $available_dates_in_range
    ], 'DEBUG');
    
    if (empty($available_dates_in_range)) {
        debugCascadingLog("No available dates, returning all zeros", null, 'DEBUG');
        return array_fill_keys($date_array, 0);
    }
    
    $available_count = count($available_dates_in_range);
    $base_qty = floor($total_qty / $available_count);
    $remainder = $total_qty % $available_count;
    
    debugCascadingLog("Distribution calculation", [
        'available_count' => $available_count,
        'base_qty' => $base_qty,
        'remainder' => $remainder
    ], 'DEBUG');
    
    $distribution = array_fill_keys($date_array, 0);
    
    // Distribute base quantity to available dates
    foreach ($available_dates_in_range as $date) {
        $distribution[$date] = $base_qty;
    }
    
    // Distribute remainder to first few available dates
    $available_dates_list = array_values($available_dates_in_range);
    for ($i = 0; $i < $remainder; $i++) {
        $distribution[$available_dates_list[$i]]++;
    }
    
    // Shuffle the distribution
    $available_dates_keys = array_keys($available_dates_in_range);
    shuffle($available_dates_keys);
    
    $shuffled_distribution = array_fill_keys($date_array, 0);
    $available_values = [];
    
    foreach ($available_dates_keys as $date) {
        $available_values[] = $distribution[$date];
    }
    
    // Assign shuffled values back to available dates
    foreach ($available_dates_keys as $index => $date) {
        $shuffled_distribution[$date] = $available_values[$index];
    }
    
    debugCascadingLog("Final distribution", [
        'total_distributed' => array_sum($shuffled_distribution),
        'distribution' => $shuffled_distribution
    ], 'DEBUG');
    
    return $shuffled_distribution;
}

// ============================================================================
// ENHANCED CASCADING DAILY STOCK UPDATE FUNCTION WITH COMPREHENSIVE DEBUGGING
// ============================================================================

function updateCascadingDailyStock($conn, $item_code, $sale_date, $comp_id, $transaction_type = 'sale', $qty) {
    debugCascadingLog("=== CASCADING STOCK UPDATE START ===", [
        'item_code' => $item_code,
        'sale_date' => $sale_date,
        'comp_id' => $comp_id,
        'transaction_type' => $transaction_type,
        'quantity' => $qty
    ], 'INFO');
    
    try {
        // Get sale month and current month
        $sale_month = date('Y-m', strtotime($sale_date));
        $current_month = date('Y-m');
        
        debugCascadingLog("Month information", [
            'sale_month' => $sale_month,
            'current_month' => $current_month
        ], 'DEBUG');
        
        // ============================================================================
        // STEP 1: UPDATE SALE DATE IN CORRECT TABLE
        // ============================================================================
        
        // Get the correct daily stock table for the sale date
        $sale_daily_stock_table = getDailyStockTableName($conn, $sale_date, $comp_id);
        
        debugCascadingLog("Sale daily stock table", $sale_daily_stock_table, 'DEBUG');
        
        // Check if table exists, create if not
        $check_table_query = "SHOW TABLES LIKE '$sale_daily_stock_table'";
        $table_result = $conn->query($check_table_query);
        
        if ($table_result->num_rows == 0) {
            // Table doesn't exist - this is OK for archive months
            debugCascadingLog("Archive table '$sale_daily_stock_table' not found - will create record", null, 'INFO');
        } else {
            debugCascadingLog("Table exists", $sale_daily_stock_table, 'DEBUG');
        }
        
        // Extract day number from sale date
        $sale_day_num = date('d', strtotime($sale_date));
        $sale_day_padded = str_pad($sale_day_num, 2, '0', STR_PAD_LEFT);
        
        // Get columns for sale day
        $sale_day_opening_column = "DAY_{$sale_day_padded}_OPEN";
        $sale_day_purchase_column = "DAY_{$sale_day_padded}_PURCHASE";
        $sale_day_sales_column = "DAY_{$sale_day_padded}_SALES";
        $sale_day_closing_column = "DAY_{$sale_day_padded}_CLOSING";
        
        debugCascadingLog("Sale day columns", [
            'day' => $sale_day_num,
            'opening' => $sale_day_opening_column,
            'purchase' => $sale_day_purchase_column,
            'sales' => $sale_day_sales_column,
            'closing' => $sale_day_closing_column
        ], 'DEBUG');
        
        // Check if record exists
        $check_query = "SELECT COUNT(*) as count, 
                               $sale_day_opening_column as opening,
                               $sale_day_purchase_column as purchase,
                               $sale_day_sales_column as sales,
                               $sale_day_closing_column as closing
                        FROM $sale_daily_stock_table 
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ss", $sale_month, $item_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($row = $check_result->fetch_assoc()) {
            $record_exists = $row['count'] > 0;
            debugCascadingLog("Existing record data", [
                'exists' => $record_exists,
                'opening' => $row['opening'],
                'purchase' => $row['purchase'],
                'sales' => $row['sales'],
                'closing' => $row['closing']
            ], 'DEBUG');
        } else {
            $record_exists = false;
            debugCascadingLog("No existing record found", null, 'DEBUG');
        }
        $check_stmt->close();
        
        if (!$record_exists) {
            // Create new record
            $opening = 0;
            $purchase = 0;
            $sales = $qty;
            $closing = $opening + $purchase - $sales;
            
            debugCascadingLog("Creating new record", [
                'opening' => $opening,
                'purchase' => $purchase,
                'sales' => $sales,
                'closing' => $closing
            ], 'INFO');
            
            $insert_query = "INSERT INTO $sale_daily_stock_table 
                            (STK_MONTH, ITEM_CODE, LIQ_FLAG, $sale_day_opening_column, $sale_day_purchase_column, $sale_day_sales_column, $sale_day_closing_column) 
                            VALUES (?, ?, 'F', ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssdddd", $sale_month, $item_code, $opening, $purchase, $sales, $closing);
            $insert_stmt->execute();
            $insert_stmt->close();
            
            debugCascadingLog("Created new stock record for item", [
                'item_code' => $item_code,
                'table' => $sale_daily_stock_table,
                'month' => $sale_month
            ], 'INFO');
        } else {
            // Update existing record
            debugCascadingLog("Updating existing record", [
                'item_code' => $item_code,
                'added_sales' => $qty,
                'table' => $sale_daily_stock_table,
                'month' => $sale_month
            ], 'INFO');
            
            // First get current values for accurate calculation
            $get_current_query = "SELECT $sale_day_opening_column as opening,
                                         $sale_day_purchase_column as purchase,
                                         $sale_day_sales_column as sales
                                  FROM $sale_daily_stock_table 
                                  WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $get_current_stmt = $conn->prepare($get_current_query);
            $get_current_stmt->bind_param("ss", $sale_month, $item_code);
            $get_current_stmt->execute();
            $current_result = $get_current_stmt->get_result();
            $current_data = $current_result->fetch_assoc();
            $get_current_stmt->close();
            
            $current_sales = $current_data['sales'] ?? 0;
            $new_sales = $current_sales + $qty;
            $new_closing = ($current_data['opening'] ?? 0) + ($current_data['purchase'] ?? 0) - $new_sales;
            
            debugCascadingLog("Current values", [
                'opening' => $current_data['opening'] ?? 0,
                'purchase' => $current_data['purchase'] ?? 0,
                'current_sales' => $current_sales,
                'new_sales' => $new_sales,
                'new_closing' => $new_closing
            ], 'DEBUG');
            
            $update_query = "UPDATE $sale_daily_stock_table 
                            SET $sale_day_sales_column = ?,
                                $sale_day_closing_column = ?,
                                LAST_UPDATED = CURRENT_TIMESTAMP 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ddss", $new_sales, $new_closing, $sale_month, $item_code);
            $update_stmt->execute();
            $update_stmt->close();
            
            debugCascadingLog("Updated sale day stock", [
                'item_code' => $item_code,
                'date' => $sale_date,
                'added_sales' => $qty,
                'new_sales_total' => $new_sales,
                'new_closing' => $new_closing
            ], 'INFO');
        }
        
        // ============================================================================
        // STEP 2: CASCADE WITHIN SALE MONTH
        // ============================================================================
        
        debugCascadingLog("Starting cascade within sale month", [
            'sale_month' => $sale_month,
            'sale_day' => $sale_day_num
        ], 'INFO');
        
        // Cascade to subsequent days in the sale month
        for ($day = $sale_day_num + 1; $day <= 31; $day++) {
            $current_day = str_pad($day, 2, '0', STR_PAD_LEFT);
            $prev_day = str_pad($day - 1, 2, '0', STR_PAD_LEFT);
            
            $current_opening_column = "DAY_{$current_day}_OPEN";
            $prev_closing_column = "DAY_{$prev_day}_CLOSING";
            $current_purchase_column = "DAY_{$current_day}_PURCHASE";
            $current_sales_column = "DAY_{$current_day}_SALES";
            $current_closing_column = "DAY_{$current_day}_CLOSING";
            
            // Check if current day columns exist
            $check_columns_query = "SHOW COLUMNS FROM $sale_daily_stock_table LIKE '$current_opening_column'";
            $columns_result = $conn->query($check_columns_query);
            
            if ($columns_result->num_rows == 0) {
                debugCascadingLog("Column $current_opening_column doesn't exist, stopping cascade", null, 'DEBUG');
                break;
            }
            
            // Update current day's opening to match previous day's closing
            $cascade_query = "UPDATE $sale_daily_stock_table 
                             SET $current_opening_column = (
                                 SELECT $prev_closing_column 
                                 FROM $sale_daily_stock_table 
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?
                             ),
                             LAST_UPDATED = CURRENT_TIMESTAMP 
                             WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $cascade_stmt = $conn->prepare($cascade_query);
            $cascade_stmt->bind_param("ssss", $sale_month, $item_code, $sale_month, $item_code);
            $cascade_stmt->execute();
            $cascade_stmt->close();
            
            debugCascadingLog("Updated opening for day $day", [
                'opening_column' => $current_opening_column,
                'prev_closing_column' => $prev_closing_column
            ], 'DEBUG');
            
            // Recalculate closing for this day
            $recalc_query = "UPDATE $sale_daily_stock_table 
                            SET $current_closing_column = $current_opening_column + $current_purchase_column - $current_sales_column,
                                LAST_UPDATED = CURRENT_TIMESTAMP 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $recalc_stmt = $conn->prepare($recalc_query);
            $recalc_stmt->bind_param("ss", $sale_month, $item_code);
            $recalc_stmt->execute();
            $recalc_stmt->close();
            
            debugCascadingLog("Recalculated closing for day $day", [
                'closing_column' => $current_closing_column
            ], 'DEBUG');
        }
        
        debugCascadingLog("Completed cascade within sale month", null, 'INFO');
        
        // ============================================================================
        // STEP 3: CASCADE ACROSS MONTHS IF NEEDED
        // ============================================================================
        
        if ($sale_month < $current_month) {
            debugCascadingLog("Sale month is in the past, starting cross-month cascade", [
                'sale_month' => $sale_month,
                'current_month' => $current_month
            ], 'INFO');
            
            // Get the last closing from sale month
            $last_closing = 0;
            for ($day = 31; $day >= 1; $day--) {
                $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
                $last_day_column = "DAY_{$day_str}_CLOSING";
                
                $check_column_query = "SHOW COLUMNS FROM $sale_daily_stock_table LIKE '$last_day_column'";
                $column_result = $conn->query($check_column_query);
                
                if ($column_result->num_rows > 0) {
                    $get_last_query = "SELECT $last_day_column FROM $sale_daily_stock_table 
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                    $get_last_stmt = $conn->prepare($get_last_query);
                    $get_last_stmt->bind_param("ss", $sale_month, $item_code);
                    $get_last_stmt->execute();
                    $get_last_result = $get_last_stmt->get_result();
                    
                    if ($get_last_result->num_rows > 0) {
                        $last_data = $get_last_result->fetch_assoc();
                        $last_closing = $last_data[$last_day_column] ?? 0;
                        $get_last_stmt->close();
                        debugCascadingLog("Found last closing for sale month", [
                            'day' => $day_str,
                            'last_closing' => $last_closing,
                            'column' => $last_day_column
                        ], 'DEBUG');
                        break;
                    }
                    $get_last_stmt->close();
                }
            }
            
            debugCascadingLog("Last closing from sale month", $last_closing, 'INFO');
            
            // Create list of months to cascade through
            $start = new DateTime($sale_month . '-01');
            $end = new DateTime($current_month . '-01');
            $interval = DateInterval::createFromDateString('1 month');
            $period = new DatePeriod($start, $interval, $end);
            
            $months_to_cascade = [];
            foreach ($period as $dt) {
                $months_to_cascade[] = $dt->format("Y-m");
            }
            
            debugCascadingLog("Months to cascade through", $months_to_cascade, 'INFO');
            
            // Cascade through each month
            foreach ($months_to_cascade as $month) {
                if ($month === $sale_month) continue;
                
                $month_table = getDailyStockTableName($conn, $month . '-01', $comp_id);
                
                debugCascadingLog("Processing month", [
                    'month' => $month,
                    'table' => $month_table
                ], 'INFO');
                
                // Check if table exists
                $check_table = "SHOW TABLES LIKE '$month_table'";
                $table_exists = $conn->query($check_table)->num_rows > 0;
                
                if (!$table_exists) {
                    debugCascadingLog("Table $month_table doesn't exist, skipping", null, 'WARNING');
                    continue;
                }
                
                // Check if record exists in this month
                $check_month_query = "SELECT COUNT(*) as count FROM $month_table 
                                     WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $check_month_stmt = $conn->prepare($check_month_query);
                $check_month_stmt->bind_param("ss", $month, $item_code);
                $check_month_stmt->execute();
                $month_result = $check_month_stmt->get_result();
                $month_exists = $month_result->fetch_assoc()['count'] > 0;
                $check_month_stmt->close();
                
                if (!$month_exists) {
                    // Create record with opening = last closing
                    debugCascadingLog("Creating new record in $month_table", [
                        'month' => $month,
                        'item_code' => $item_code,
                        'opening' => $last_closing
                    ], 'INFO');
                    
                    $create_query = "INSERT INTO $month_table 
                                    (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_01_OPEN, DAY_01_CLOSING) 
                                    VALUES (?, ?, 'F', ?, ?)";
                    $create_stmt = $conn->prepare($create_query);
                    $create_stmt->bind_param("ssdd", $month, $item_code, $last_closing, $last_closing);
                    $create_stmt->execute();
                    $create_stmt->close();
                } else {
                    // Update opening to reflect last closing
                    debugCascadingLog("Updating existing record in $month_table", [
                        'month' => $month,
                        'item_code' => $item_code,
                        'additional_opening' => $last_closing
                    ], 'INFO');
                    
                    $update_month_query = "UPDATE $month_table 
                                          SET DAY_01_OPEN = DAY_01_OPEN + ?,
                                              LAST_UPDATED = CURRENT_TIMESTAMP 
                                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                    $update_month_stmt = $conn->prepare($update_month_query);
                    $update_month_stmt->bind_param("dss", $last_closing, $month, $item_code);
                    $update_month_stmt->execute();
                    $update_month_stmt->close();
                }
                
                // Recalculate this month
                recalculateMonthStock($conn, $month_table, $item_code, $month);
                
                // Update last_closing for next month
                $last_closing = 0;
                for ($day = 31; $day >= 1; $day--) {
                    $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
                    $last_day_column = "DAY_{$day_str}_CLOSING";
                    
                    $check_column_query = "SHOW COLUMNS FROM $month_table LIKE '$last_day_column'";
                    $column_result = $conn->query($check_column_query);
                    
                    if ($column_result->num_rows > 0) {
                        $get_last_query = "SELECT $last_day_column FROM $month_table 
                                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                        $get_last_stmt = $conn->prepare($get_last_query);
                        $get_last_stmt->bind_param("ss", $month, $item_code);
                        $get_last_stmt->execute();
                        $get_last_result = $get_last_stmt->get_result();
                        
                        if ($get_last_result->num_rows > 0) {
                            $last_data = $get_last_result->fetch_assoc();
                            $last_closing = $last_data[$last_day_column] ?? 0;
                            $get_last_stmt->close();
                            debugCascadingLog("Updated last closing for next month", [
                                'month' => $month,
                                'last_closing' => $last_closing,
                                'day_column' => $last_day_column
                            ], 'DEBUG');
                            break;
                        }
                        $get_last_stmt->close();
                    }
                }
            }
            
            // FINAL STEP: Deduct the sale quantity from current month
            $current_table = "tbldailystock_" . $comp_id;
            $current_month_year = date('Y-m');
            
            debugCascadingLog("Deducting sale from current month", [
                'table' => $current_table,
                'current_month' => $current_month_year,
                'item_code' => $item_code,
                'deduction_qty' => $qty
            ], 'INFO');
            
            // Check if record exists in current month
            $check_current_query = "SELECT COUNT(*) as count, DAY_01_OPEN as opening 
                                   FROM $current_table 
                                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $check_current_stmt = $conn->prepare($check_current_query);
            $check_current_stmt->bind_param("ss", $current_month_year, $item_code);
            $check_current_stmt->execute();
            $current_result = $check_current_stmt->get_result();
            $current_row = $current_result->fetch_assoc();
            $check_current_stmt->close();
            
            if ($current_row['count'] > 0) {
                $current_opening = $current_row['opening'] ?? 0;
                $new_opening = $current_opening - $qty;
                
                debugCascadingLog("Current month opening adjustment", [
                    'current_opening' => $current_opening,
                    'new_opening' => $new_opening
                ], 'DEBUG');
                
                $deduct_query = "UPDATE $current_table 
                                SET DAY_01_OPEN = ?,
                                    LAST_UPDATED = CURRENT_TIMESTAMP 
                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $deduct_stmt = $conn->prepare($deduct_query);
                $deduct_stmt->bind_param("dss", $new_opening, $current_month_year, $item_code);
                $deduct_stmt->execute();
                $deduct_stmt->close();
                
                // Recalculate current month
                recalculateMonthStock($conn, $current_table, $item_code, $current_month_year);
            } else {
                debugCascadingLog("No record found in current month table", [
                    'table' => $current_table,
                    'month' => $current_month_year,
                    'item_code' => $item_code
                ], 'WARNING');
            }
        }
        
        // ============================================================================
        // STEP 4: UPDATE CURRENT MONTH IF SALE IS IN CURRENT MONTH
        // ============================================================================
        
        if ($sale_month === $current_month) {
            debugCascadingLog("Sale is in current month, recalculating", [
                'sale_month' => $sale_month,
                'current_month' => $current_month
            ], 'INFO');
            
            $current_table = "tbldailystock_" . $comp_id;
            $current_month_year = date('Y-m');
            
            recalculateMonthStock($conn, $current_table, $item_code, $current_month_year);
        }
        
        debugCascadingLog("=== CASCADING STOCK UPDATE COMPLETED ===", [
            'item_code' => $item_code,
            'sale_date' => $sale_date,
            'quantity' => $qty,
            'success' => true
        ], 'INFO');
        
        return true;
        
    } catch (Exception $e) {
        debugCascadingLog("=== CASCADING STOCK UPDATE FAILED ===", [
            'item_code' => $item_code,
            'sale_date' => $sale_date,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'ERROR');
        throw $e;
    }
}

// Helper function to recalculate an entire month's stock with debugging
function recalculateMonthStock($conn, $table_name, $item_code, $stk_month) {
    debugCascadingLog("recalculateMonthStock START", [
        'table' => $table_name,
        'item_code' => $item_code,
        'stk_month' => $stk_month
    ], 'DEBUG');
    
    try {
        // Check if table exists
        $check_table = "SHOW TABLES LIKE '$table_name'";
        if ($conn->query($check_table)->num_rows == 0) {
            debugCascadingLog("Table $table_name doesn't exist", null, 'WARNING');
            return false;
        }
        
        // Start from day 1 and recalculate all days
        for ($day = 1; $day <= 31; $day++) {
            $day_num = sprintf('%02d', $day);
            $opening_column = "DAY_{$day_num}_OPEN";
            $purchase_column = "DAY_{$day_num}_PURCHASE";
            $sales_column = "DAY_{$day_num}_SALES";
            $closing_column = "DAY_{$day_num}_CLOSING";
            
            // Check if day columns exist
            $check_columns = "SHOW COLUMNS FROM $table_name LIKE '$opening_column'";
            $column_result = $conn->query($check_columns);
            
            if ($column_result->num_rows == 0) {
                debugCascadingLog("Column $opening_column doesn't exist, stopping", null, 'DEBUG');
                break;
            }
            
            // Get current values for this day
            $day_query = "SELECT $opening_column, $purchase_column, $sales_column 
                          FROM $table_name 
                          WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $day_stmt = $conn->prepare($day_query);
            $day_stmt->bind_param("ss", $item_code, $stk_month);
            $day_stmt->execute();
            $day_result = $day_stmt->get_result();
            
            if ($day_result->num_rows > 0) {
                $day_values = $day_result->fetch_assoc();
                $opening = $day_values[$opening_column] ?? 0;
                $purchase = $day_values[$purchase_column] ?? 0;
                $sales = $day_values[$sales_column] ?? 0;
                
                // Calculate closing
                $closing = $opening + $purchase - $sales;
                
                debugCascadingLog("Day $day calculation", [
                    'opening' => $opening,
                    'purchase' => $purchase,
                    'sales' => $sales,
                    'closing' => $closing
                ], 'DEBUG');
                
                // Update closing
                $update_query = "UPDATE $table_name 
                                SET $closing_column = ?,
                                    LAST_UPDATED = CURRENT_TIMESTAMP 
                                WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("dss", $closing, $item_code, $stk_month);
                $update_stmt->execute();
                $update_stmt->close();
                
                // Set next day's opening to this day's closing
                $next_day = $day + 1;
                if ($next_day <= 31) {
                    $next_day_num = sprintf('%02d', $next_day);
                    $next_opening_column = "DAY_{$next_day_num}_OPEN";
                    
                    // Check if next day exists
                    $check_next = "SHOW COLUMNS FROM $table_name LIKE '$next_opening_column'";
                    $next_result = $conn->query($check_next);
                    
                    if ($next_result->num_rows > 0) {
                        $update_next_query = "UPDATE $table_name 
                                             SET $next_opening_column = ?,
                                                 LAST_UPDATED = CURRENT_TIMESTAMP 
                                             WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                        $update_next_stmt = $conn->prepare($update_next_query);
                        $update_next_stmt->bind_param("dss", $closing, $item_code, $stk_month);
                        $update_next_stmt->execute();
                        $update_next_stmt->close();
                        
                        debugCascadingLog("Set next day opening", [
                            'next_day' => $next_day,
                            'opening_value' => $closing
                        ], 'DEBUG');
                    }
                }
            } else {
                debugCascadingLog("No record found for day $day", [
                    'item_code' => $item_code,
                    'stk_month' => $stk_month
                ], 'DEBUG');
            }
            $day_stmt->close();
        }
        
        debugCascadingLog("recalculateMonthStock COMPLETED", [
            'table' => $table_name,
            'item_code' => $item_code,
            'stk_month' => $stk_month
        ], 'INFO');
        
        return true;
        
    } catch (Exception $e) {
        debugCascadingLog("Error in recalculateMonthStock", [
            'table' => $table_name,
            'item_code' => $item_code,
            'stk_month' => $stk_month,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'ERROR');
        return false;
    }
}

// ============================================================================
// NEW: Function to continue cascading from archived month to current month
// Taken from purchases.php and adapted for sales
// ============================================================================

function continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $sale_date, $qty) {
    debugCascadingLog("continueCascadingToCurrentMonth START", [
        'comp_id' => $comp_id,
        'itemCode' => $itemCode,
        'sale_date' => $sale_date,
        'quantity' => $qty
    ], 'INFO');
    
    $saleDay = date('j', strtotime($sale_date));
    $saleMonth = date('n', strtotime($sale_date));
    $saleYear = date('Y', strtotime($sale_date));
    $currentDay = date('j');
    $currentMonth = date('n');
    $currentYear = date('Y');
    
    // If sale is in current month, cascading has already been handled
    if ($saleMonth == $currentMonth && $saleYear == $currentYear) {
        debugCascadingLog("Sale is in current month, cascading already handled", null, 'INFO');
        return;
    }
    
    // Start from the next month after sale
    $startMonth = $saleMonth + 1;
    $startYear = $saleYear;
    if ($startMonth > 12) {
        $startMonth = 1;
        $startYear++;
    }
    
    debugCascadingLog("Starting cascading from month", [
        'startMonth' => $startMonth,
        'startYear' => $startYear
    ], 'INFO');
    
    // Loop through months from sale month+1 to current month
    while (($startYear < $currentYear) || ($startYear == $currentYear && $startMonth <= $currentMonth)) {
        $month_2digit = str_pad($startMonth, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($startYear, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        
        // Check if this month's table exists (archived or current)
        $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name = '$archive_table'";
        $check_result = $conn->query($check_table_query);
        $table_exists = $check_result->fetch_assoc()['count'] > 0;
        
        if ($table_exists) {
            debugCascadingLog("Found table for cascading", [
                'table' => $archive_table,
                'month' => $startMonth,
                'year' => $startYear
            ], 'INFO');
            
            $monthYear = date('Y-m', strtotime("$startYear-$startMonth-01"));
            
            // Get days in this month
            $daysInMonth = date('t', strtotime("$startYear-$startMonth-01"));
            
            // For the first month after sale, opening should come from previous month's last day
            if ($startMonth == $saleMonth + 1 || ($startMonth == 1 && $saleMonth == 12)) {
                // Get previous month's last day closing
                $prevMonth = $saleMonth;
                $prevYear = $saleYear;
                $prevMonthDays = date('t', strtotime("$prevYear-$prevMonth-01"));
                
                $prevMonth_2digit = str_pad($prevMonth, 2, '0', STR_PAD_LEFT);
                $prevYear_2digit = substr($prevYear, -2);
                $prevTable = "tbldailystock_{$comp_id}_{$prevMonth_2digit}_{$prevYear_2digit}";
                
                $prevClosingColumn = "DAY_" . str_pad($prevMonthDays, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                
                $getPrevClosingQuery = "SELECT $prevClosingColumn as closing FROM $prevTable 
                                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prevStmt = $conn->prepare($getPrevClosingQuery);
                $prevMonthYear = date('Y-m', strtotime("$prevYear-$prevMonth-01"));
                $prevStmt->bind_param("ss", $prevMonthYear, $itemCode);
                $prevStmt->execute();
                $prevResult = $prevStmt->get_result();
                $prevRow = $prevResult->fetch_assoc();
                $prevStmt->close();
                
                $openingValue = $prevRow ? $prevRow['closing'] : 0;
                
                debugCascadingLog("Got opening value from previous month", [
                    'prevTable' => $prevTable,
                    'prevClosingColumn' => $prevClosingColumn,
                    'openingValue' => $openingValue
                ], 'INFO');
                
                // Update the first day of this month with the opening value
                $updateOpeningQuery = "UPDATE $archive_table 
                                      SET DAY_01_OPEN = ?,
                                          DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $openingStmt = $conn->prepare($updateOpeningQuery);
                $openingStmt->bind_param("iss", $openingValue, $monthYear, $itemCode);
                $openingStmt->execute();
                $openingStmt->close();
                
                // Now cascade through the rest of this month
                for ($day = 2; $day <= $daysInMonth; $day++) {
                    $prevDay = $day - 1;
                    $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                    $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
                    $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
                    $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
                    $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                    
                    $updateDayQuery = "UPDATE $archive_table 
                                      SET $currentDayOpening = $prevDayClosing,
                                          $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                    
                    $dayStmt = $conn->prepare($updateDayQuery);
                    $dayStmt->bind_param("ss", $monthYear, $itemCode);
                    $dayStmt->execute();
                    $dayStmt->close();
                }
            } else {
                // For subsequent months, cascade from day 1
                // Update subsequent days in table (adapted from purchases.php)
                updateSubsequentDaysInTable($conn, $archive_table, $monthYear, $itemCode, 1);
            }
        }
        
        // Move to next month
        $startMonth++;
        if ($startMonth > 12) {
            $startMonth = 1;
            $startYear++;
        }
    }
    
    // If we've reached current month, ensure current month table is also updated
    if ($currentMonth != $saleMonth || $currentYear != $saleYear) {
        $dailyStockTable = "tbldailystock_" . $comp_id;
        $currentMonthYear = date('Y-m');
        
        // Check if record exists in current month table
        $checkCurrentQuery = "SELECT COUNT(*) as count FROM $dailyStockTable 
                             WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $checkCurrentStmt = $conn->prepare($checkCurrentQuery);
        $checkCurrentStmt->bind_param("ss", $currentMonthYear, $itemCode);
        $checkCurrentStmt->execute();
        $currentResult = $checkCurrentStmt->get_result();
        $currentExists = $currentResult->fetch_assoc()['count'] > 0;
        $checkCurrentStmt->close();
        
        if ($currentExists) {
            // Get previous month's last day closing for opening value
            $prevMonth = $currentMonth - 1;
            $prevYear = $currentYear;
            if ($prevMonth < 1) {
                $prevMonth = 12;
                $prevYear--;
            }
            
            $prevMonthDays = date('t', strtotime("$prevYear-$prevMonth-01"));
            $prevMonth_2digit = str_pad($prevMonth, 2, '0', STR_PAD_LEFT);
            $prevYear_2digit = substr($prevYear, -2);
            $prevTable = "tbldailystock_{$comp_id}_{$prevMonth_2digit}_{$prevYear_2digit}";
            
            // Check if previous table exists
            $checkPrevTableQuery = "SELECT COUNT(*) as count FROM information_schema.tables 
                                   WHERE table_schema = DATABASE() 
                                   AND table_name = '$prevTable'";
            $checkPrevResult = $conn->query($checkPrevTableQuery);
            $prevTableExists = $checkPrevResult->fetch_assoc()['count'] > 0;
            
            if ($prevTableExists) {
                $prevClosingColumn = "DAY_" . str_pad($prevMonthDays, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                $prevMonthYear = date('Y-m', strtotime("$prevYear-$prevMonth-01"));
                
                $getPrevClosingQuery = "SELECT $prevClosingColumn as closing FROM $prevTable 
                                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prevStmt = $conn->prepare($getPrevClosingQuery);
                $prevStmt->bind_param("ss", $prevMonthYear, $itemCode);
                $prevStmt->execute();
                $prevResult = $prevStmt->get_result();
                $prevRow = $prevResult->fetch_assoc();
                $prevStmt->close();
                
                $openingValue = $prevRow ? $prevRow['closing'] : 0;
                
                // Update current month's day 1 opening
                $updateCurrentOpeningQuery = "UPDATE $dailyStockTable 
                                            SET DAY_01_OPEN = ?,
                                                DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $currentOpeningStmt = $conn->prepare($updateCurrentOpeningQuery);
                $currentOpeningStmt->bind_param("iss", $openingValue, $currentMonthYear, $itemCode);
                $currentOpeningStmt->execute();
                $currentOpeningStmt->close();
            }
            
            // Cascade through current month up to today (or end of month)
            $daysInCurrentMonth = date('t');
            $cascadeTo = min($currentDay, $daysInCurrentMonth);
            
            for ($day = 2; $day <= $cascadeTo; $day++) {
                $prevDay = $day - 1;
                $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
                $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
                $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
                $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR-PAD_LEFT) . "_CLOSING";
                
                $updateDayQuery = "UPDATE $dailyStockTable 
                                  SET $currentDayOpening = $prevDayClosing,
                                      $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                                  WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                
                $dayStmt = $conn->prepare($updateDayQuery);
                $dayStmt->bind_param("ss", $currentMonthYear, $itemCode);
                $dayStmt->execute();
                $dayStmt->close();
            }
        }
    }
    
    debugCascadingLog("Cascading completed up to current date", null, 'INFO');
}

// NEW: Function to update subsequent days in table (adapted from purchases.php)
function updateSubsequentDaysInTable($conn, $table, $monthYear, $itemCode, $startDay) {
    debugCascadingLog("updateSubsequentDaysInTable START", [
        'table' => $table,
        'monthYear' => $monthYear,
        'itemCode' => $itemCode,
        'startDay' => $startDay
    ], 'DEBUG');
    
    // Get the number of days in the month
    $timestamp = strtotime($monthYear . "-01");
    $daysInMonth = date('t', $timestamp); // 28, 29, 30, or 31
    
    debugCascadingLog("Month has $daysInMonth days", [
        'timestamp' => date('Y-m-d', $timestamp),
        'daysInMonth' => $daysInMonth
    ], 'DEBUG');
    
    // Update opening for next day (carry forward from previous day's closing)
    // Only iterate through actual days in the month
    for ($day = $startDay + 1; $day <= $daysInMonth; $day++) {
        $prevDay = $day - 1;
        $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        // Check if the columns exist in the table
        $checkColumnsQuery = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = '$table' 
                            AND COLUMN_NAME IN ('$currentDayOpening', '$currentDayPurchase', '$currentDaySales', '$currentDayClosing')";
        
        $checkResult = $conn->query($checkColumnsQuery);
        $columnsExist = $checkResult->num_rows >= 4; // All 4 columns should exist
        
        if ($columnsExist) {
            // Update opening to previous day's closing, and recalculate closing
            $updateQuery = "UPDATE $table 
                           SET $currentDayOpening = $prevDayClosing,
                               $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            
            debugCascadingLog("Cascading update for day $day", [
                'query' => $updateQuery,
                'prevDayClosing' => $prevDayClosing,
                'columns_exist' => true
            ], 'DEBUG');
            
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("ss", $monthYear, $itemCode);
            $stmt->execute();
            $stmt->close();
        } else {
            debugCascadingLog("Skipping day $day - columns don't exist", [
                'columns_checked' => [$currentDayOpening, $currentDayPurchase, $currentDaySales, $currentDayClosing],
                'columns_found' => $checkResult->num_rows
            ], 'DEBUG');
        }
        $checkResult->free();
    }
    
    debugCascadingLog("Cascading updates completed for all days after start day", null, 'INFO');
}

// ============================================================================
// MAIN EXECUTION
// ============================================================================

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    debugCascadingLog("User not logged in, redirecting to index", null, 'WARNING');
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    debugCascadingLog("Company ID or Financial Year not set, redirecting to index", null, 'WARNING');
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";

debugCascadingLog("Database connection established", [
    'CompID' => $_SESSION['CompID'],
    'FIN_YEAR_ID' => $_SESSION['FIN_YEAR_ID']
], 'INFO');

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

debugCascadingLog("License information", [
    'license_type' => $license_type,
    'available_classes_count' => count($available_classes),
    'allowed_classes' => $allowed_classes
], 'INFO');

// Mode selection
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
$sequence_type = isset($_GET['sequence_type']) ? $_GET['sequence_type'] : 'user_defined';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$comp_id = $_SESSION['CompID'];

debugCascadingLog("Page parameters", [
    'mode' => $mode,
    'sequence_type' => $sequence_type,
    'search' => $search,
    'start_date' => $start_date,
    'end_date' => $end_date,
    'comp_id' => $comp_id
], 'INFO');

// Validate that end date is not in the future
if (strtotime($end_date) > strtotime(date('Y-m-d'))) {
    debugCascadingLog("End date in future, adjusting to today", [
        'original_end_date' => $end_date,
        'adjusted_end_date' => date('Y-m-d')
    ], 'WARNING');
    $end_date = date('Y-m-d');
}

// Get day number for end date
$end_day = date('d', strtotime($end_date));
$end_day_column = "DAY_" . str_pad($end_day, 2, '0', STR_PAD_LEFT) . "_CLOSING";

// Get the correct daily stock table for end date
$daily_stock_table = getDailyStockTableName($conn, $end_date, $comp_id);

debugCascadingLog("Stock table information", [
    'end_day' => $end_day,
    'end_day_column' => $end_day_column,
    'daily_stock_table' => $daily_stock_table
], 'INFO');

// Check if the daily stock table exists
$check_table_query = "SHOW TABLES LIKE '$daily_stock_table'";
$table_result = $conn->query($check_table_query);
$table_exists = ($table_result->num_rows > 0);

debugCascadingLog("Table existence check", [
    'table' => $daily_stock_table,
    'exists' => $table_exists
], 'INFO');

// Build the order clause
$order_clause = "";
if ($sequence_type === 'system_defined') {
    $order_clause = "ORDER BY im.CODE ASC";
} elseif ($sequence_type === 'group_defined') {
    $order_clause = "ORDER BY im.DETAILS2 ASC, im.DETAILS ASC";
} else {
    $order_clause = "ORDER BY im.DETAILS ASC";
}

// Pagination
$items_per_page = 50;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

debugCascadingLog("Pagination settings", [
    'items_per_page' => $items_per_page,
    'current_page' => $current_page,
    'offset' => $offset
], 'DEBUG');

// Get total count for pagination
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $count_query = "SELECT COUNT(DISTINCT im.CODE) as total 
                    FROM tblitemmaster im 
                    LEFT JOIN $daily_stock_table dst ON im.CODE = dst.ITEM_CODE 
                    WHERE im.LIQ_FLAG = ? 
                    AND im.CLASS IN ($class_placeholders)
                    AND dst.STK_MONTH = ?
                    AND dst.$end_day_column > 0";
    
    $count_params = array_merge([$mode], $allowed_classes, [date('Y-m', strtotime($end_date))]);
    $count_types = str_repeat('s', count($allowed_classes) + 2);
} else {
    $count_query = "SELECT COUNT(*) as total FROM tblitemmaster im WHERE 1 = 0";
    $count_params = [];
    $count_types = "";
}

if ($search !== '' && $table_exists) {
    $count_query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_types .= "ss";
}

$total_items = 0;
if ($table_exists) {
    debugCascadingLog("Executing count query", [
        'query' => $count_query,
        'params' => $count_params
    ], 'DEBUG');
    
    $count_stmt = $conn->prepare($count_query);
    if (!empty($count_params)) {
        $count_stmt->bind_param($count_types, ...$count_params);
    }
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total_items = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
    
    debugCascadingLog("Count query result", [
        'total_items' => $total_items
    ], 'INFO');
}

// Main query with pagination
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     COALESCE(dst.$end_day_column, 0) as CURRENT_STOCK
              FROM tblitemmaster im
              LEFT JOIN $daily_stock_table dst ON im.CODE = dst.ITEM_CODE 
              WHERE im.LIQ_FLAG = ? 
              AND im.CLASS IN ($class_placeholders)
              AND dst.STK_MONTH = ?
              AND dst.$end_day_column > 0";
    
    $params = array_merge([$mode], $allowed_classes, [date('Y-m', strtotime($end_date))]);
    $types = str_repeat('s', count($allowed_classes) + 2);
} else {
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, 
                     0 as CURRENT_STOCK
              FROM tblitemmaster im 
              WHERE 1 = 0";
    $params = [];
    $types = "";
}

if ($search !== '' && $table_exists) {
    $query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " " . $order_clause . " LIMIT ? OFFSET ?";
$params[] = $items_per_page;
$params[] = $offset;
$types .= "ii";

$items = [];
if ($table_exists) {
    debugCascadingLog("Executing main query", [
        'query' => $query,
        'params' => $params,
        'types' => $types
    ], 'DEBUG');
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    debugCascadingLog("Main query result", [
        'items_count' => count($items)
    ], 'INFO');
}

// Calculate total pages
$total_pages = ceil($total_items / $items_per_page);

debugCascadingLog("Pagination totals", [
    'total_items' => $total_items,
    'total_pages' => $total_pages,
    'current_page' => $current_page
], 'INFO');

// Initialize session if not exists
if (!isset($_SESSION['sale_quantities'])) {
    $_SESSION['sale_quantities'] = [];
    debugCascadingLog("Initialized empty sale_quantities session", null, 'DEBUG');
}

// Date availability calculation for all items
$all_dates = getDatesBetween($start_date, $end_date);
$date_array = $all_dates;
$days_count = count($all_dates);

debugCascadingLog("Date range information", [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'all_dates_count' => $days_count,
    'date_array' => $date_array
], 'INFO');

// Store date availability information for each item
$item_date_availability = [];
$availability_summary = [
    'fully_available' => 0,
    'partially_available' => 0,
    'not_available' => 0
];

debugCascadingLog("Starting date availability calculation for items", [
    'items_count' => count($items)
], 'INFO');

foreach ($items as $item) {
    $item_code = $item['CODE'];
    debugCascadingLog("Calculating date availability for item", $item_code, 'DEBUG');
    
    $availability = getItemDateAvailability($conn, $item_code, $start_date, $end_date, $comp_id);
    $item_date_availability[$item_code] = $availability;
    
    // Update availability summary
    if ($availability['available_count'] == $days_count) {
        $availability_summary['fully_available']++;
    } elseif ($availability['available_count'] > 0) {
        $availability_summary['partially_available']++;
    } else {
        $availability_summary['not_available']++;
    }
}

debugCascadingLog("Date availability summary", $availability_summary, 'INFO');

// Handle form submission to update session quantities
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sale_qty'])) {
    debugCascadingLog("POST request to update session quantities", [
        'sale_qty_count' => count($_POST['sale_qty'])
    ], 'INFO');
    
    foreach ($_POST['sale_qty'] as $item_code => $qty) {
        $qty_val = intval($qty);
        if ($qty_val > 0) {
            $_SESSION['sale_quantities'][$item_code] = $qty_val;
            debugCascadingLog("Set session quantity", [
                'item_code' => $item_code,
                'quantity' => $qty_val
            ], 'DEBUG');
        } else {
            if (isset($_SESSION['sale_quantities'][$item_code])) {
                unset($_SESSION['sale_quantities'][$item_code]);
                debugCascadingLog("Removed session quantity", $item_code, 'DEBUG');
            }
        }
    }
}

// Get ALL items data for JavaScript from ALL modes for Total Sales Summary
if (!empty($allowed_classes) && $table_exists) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS, im.LIQ_FLAG, im.RPRICE,
                               COALESCE(dst.$end_day_column, 0) as CURRENT_STOCK
                        FROM tblitemmaster im
                        LEFT JOIN $daily_stock_table dst ON im.CODE = dst.ITEM_CODE 
                        WHERE im.CLASS IN ($class_placeholders)
                        AND dst.STK_MONTH = ?
                        AND dst.$end_day_column > 0";
    $all_items_stmt = $conn->prepare($all_items_query);
    $all_items_params = array_merge($allowed_classes, [date('Y-m', strtotime($end_date))]);
    $all_items_types = str_repeat('s', count($all_items_params));
    $all_items_stmt->bind_param($all_items_types, ...$all_items_params);
} else {
    $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS, im.LIQ_FLAG, im.RPRICE,
                               0 as CURRENT_STOCK
                        FROM tblitemmaster im
                        WHERE 1 = 0";
    $all_items_stmt = $conn->prepare($all_items_query);
}

$all_items_stmt->execute();
$all_items_result = $all_items_stmt->get_result();
$all_items_data = [];
while ($row = $all_items_result->fetch_assoc()) {
    $all_items_data[$row['CODE']] = $row;
}
$all_items_stmt->close();

debugCascadingLog("All items data loaded", [
    'all_items_count' => count($all_items_data)
], 'INFO');

// Function to update item stock
function updateItemStock($conn, $item_code, $qty, $current_stock_column, $opening_stock_column, $fin_year_id) {
    debugCascadingLog("updateItemStock START", [
        'item_code' => $item_code,
        'qty' => $qty,
        'current_stock_column' => $current_stock_column,
        'opening_stock_column' => $opening_stock_column,
        'fin_year_id' => $fin_year_id
    ], 'DEBUG');
    
    // Check if record exists first
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
        
        debugCascadingLog("Updated existing stock record", [
            'item_code' => $item_code,
            'deduction' => $qty,
            'column' => $current_stock_column
        ], 'INFO');
    } else {
        $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_stock_column, $current_stock_column) 
                               VALUES (?, ?, ?, ?)";
        $insert_stock_stmt = $conn->prepare($insert_stock_query);
        $current_stock = -$qty;
        $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $current_stock, $current_stock);
        $insert_stock_stmt->execute();
        $insert_stock_stmt->close();
        
        debugCascadingLog("Created new stock record", [
            'item_code' => $item_code,
            'fin_year' => $fin_year_id,
            'opening_stock' => $current_stock,
            'current_stock' => $current_stock
        ], 'INFO');
    }
    
    return true;
}

// Function to get next bill number
function getNextBillNumber($conn, $comp_id) {
    debugCascadingLog("getNextBillNumber START", ['comp_id' => $comp_id], 'DEBUG');
    
    $conn->begin_transaction();
    
    try {
        $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader WHERE COMP_ID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $comp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $next_bill = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
        $stmt->close();
        
        $check_query = "SELECT COUNT(*) as count FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
        $check_stmt = $conn->prepare($check_query);
        $bill_no_to_check = "BL" . str_pad($next_bill, 4, '0', STR_PAD_LEFT);
        $check_stmt->bind_param("si", $bill_no_to_check, $comp_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $exists = $check_result->fetch_assoc()['count'] > 0;
        $check_stmt->close();
        
        if ($exists) {
            $next_bill++;
            debugCascadingLog("Bill number conflict, incremented", [
                'original' => $next_bill - 1,
                'new' => $next_bill
            ], 'DEBUG');
        }
        
        $conn->commit();
        
        debugCascadingLog("getNextBillNumber result", [
            'next_bill' => $next_bill,
            'formatted' => "BL" . str_pad($next_bill, 4, '0', STR_PAD_LEFT)
        ], 'INFO');
        
        return $next_bill;
        
    } catch (Exception $e) {
        $conn->rollback();
        debugCascadingLog("Error in getNextBillNumber, using fallback", [
            'error' => $e->getMessage()
        ], 'ERROR');
        
        $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader WHERE COMP_ID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $comp_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        $fallback = ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
        debugCascadingLog("Fallback bill number", $fallback, 'INFO');
        return $fallback;
    }
}

// Handle form submission for sales update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debugCascadingLog("=== BILL GENERATION START ===", [
        'post_data_keys' => array_keys($_POST),
        'session_quantities_count' => isset($_SESSION['sale_quantities']) ? count($_SESSION['sale_quantities']) : 0
    ], 'INFO');
    
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '1024M');
    
    $conn->query("SET SESSION wait_timeout = 28800");
    $conn->query("SET autocommit = 0");
    
    // Check if this is a duplicate submission
    if (isset($_SESSION['last_submission']) && (time() - $_SESSION['last_submission']) < 5) {
        $error_message = "Duplicate submission detected. Please wait a few seconds before trying again.";
        debugCascadingLog("Duplicate submission detected", [
            'last_submission' => $_SESSION['last_submission'],
            'current_time' => time(),
            'difference' => time() - $_SESSION['last_submission']
        ], 'WARNING');
    } else {
        $_SESSION['last_submission'] = time();
        
        if (isset($_POST['update_sales'])) {
            $start_date = $_POST['start_date'];
            $end_date = $_POST['end_date'];
            $comp_id = $_SESSION['CompID'];
            $user_id = $_SESSION['user_id'];
            $fin_year_id = $_SESSION['FIN_YEAR_ID'];
            
            debugCascadingLog("Bill generation parameters", [
                'start_date' => $start_date,
                'end_date' => $end_date,
                'comp_id' => $comp_id,
                'user_id' => $user_id,
                'fin_year_id' => $fin_year_id
            ], 'INFO');
            
            // Get end date details
            $end_day = date('d', strtotime($end_date));
            $end_day_column = "DAY_" . str_pad($end_day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
            $daily_stock_table = getDailyStockTableName($conn, $end_date, $comp_id);
            
            debugCascadingLog("End date details", [
                'end_day' => $end_day,
                'end_day_column' => $end_day_column,
                'daily_stock_table' => $daily_stock_table
            ], 'INFO');
            
            // Get ALL items from database
            if (!empty($allowed_classes)) {
                $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
                $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, im.LIQ_FLAG,
                                           COALESCE(dst.$end_day_column, 0) as CURRENT_STOCK
                                    FROM tblitemmaster im
                                    LEFT JOIN $daily_stock_table dst ON im.CODE = dst.ITEM_CODE 
                                    WHERE im.CLASS IN ($class_placeholders)
                                    AND dst.STK_MONTH = ?
                                    AND dst.$end_day_column > 0";
                $all_items_stmt = $conn->prepare($all_items_query);
                $all_items_params = array_merge($allowed_classes, [date('Y-m', strtotime($end_date))]);
                $all_items_types = str_repeat('s', count($all_items_params));
                $all_items_stmt->bind_param($all_items_types, ...$all_items_params);
            } else {
                $all_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.RPRICE, im.CLASS, im.LIQ_FLAG,
                                           0 as CURRENT_STOCK
                                    FROM tblitemmaster im
                                    WHERE 1 = 0";
                $all_items_stmt = $conn->prepare($all_items_query);
            }
            
            $all_items_stmt->execute();
            $all_items_result = $all_items_stmt->get_result();
            $all_items = [];
            while ($row = $all_items_result->fetch_assoc()) {
                $all_items[$row['CODE']] = $row;
            }
            $all_items_stmt->close();
            
            debugCascadingLog("Loaded all items for validation", [
                'all_items_count' => count($all_items)
            ], 'INFO');
            
            // FIXED: Enhanced stock validation - NO MORE "stock not found" errors
            $stock_errors = [];
            $warnings = [];
            if (isset($_SESSION['sale_quantities'])) {
                debugCascadingLog("Validating session quantities", [
                    'quantities_count' => count($_SESSION['sale_quantities'])
                ], 'INFO');
                
                foreach ($_SESSION['sale_quantities'] as $item_code => $total_qty) {
                    if ($total_qty > 0) {
                        debugCascadingLog("Validating item quantity", [
                            'item_code' => $item_code,
                            'total_qty' => $total_qty
                        ], 'DEBUG');
                        
                        // Use the FIXED validation function
                        $validation_result = validateStockWithFallback($conn, $item_code, $total_qty, $end_date, $comp_id);
                        
                        debugCascadingLog("Validation result", [
                            'item_code' => $item_code,
                            'validation_result' => $validation_result
                        ], 'DEBUG');
                        
                        if (!$validation_result['valid']) {
                            // Only show error if stock is insufficient
                            if ($validation_result['available'] < $total_qty) {
                                $stock_errors[] = "Item {$item_code}: Available stock {$validation_result['available']}, Requested {$total_qty}";
                                debugCascadingLog("Stock validation failed", [
                                    'item_code' => $item_code,
                                    'available' => $validation_result['available'],
                                    'requested' => $total_qty,
                                    'source' => $validation_result['source']
                                ], 'ERROR');
                            }
                            // Don't error for "not found" or other scenarios
                        }
                        
                        // Log warnings for special cases
                        if ($validation_result['source'] === 'new_item_no_table' || 
                            $validation_result['source'] === 'inconclusive' ||
                            $validation_result['source'] === 'error_but_allowed') {
                            $warnings[] = "Item {$item_code}: " . $validation_result['source'];
                            debugCascadingLog("Stock validation warning", [
                                'item_code' => $item_code,
                                'source' => $validation_result['source']
                            ], 'WARNING');
                        }
                    }
                }
            }
            
            // If stock errors found, stop processing
            if (!empty($stock_errors)) {
                $error_message = "Stock validation failed:<br>" . implode("<br>", array_slice($stock_errors, 0, 5));
                if (count($stock_errors) > 5) {
                    $error_message .= "<br>... and " . (count($stock_errors) - 5) . " more errors";
                }
                
                debugCascadingLog("Stock validation failed, stopping", [
                    'error_count' => count($stock_errors),
                    'first_errors' => array_slice($stock_errors, 0, 5)
                ], 'ERROR');
            } else {
                debugCascadingLog("Stock validation passed, starting transaction", null, 'INFO');
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    $total_amount = 0;
                    $items_data = [];
                    $daily_sales_data = [];
                    
                    debugCascadingLog("Processing session quantities for distribution", [
                        'session_quantities_count' => isset($_SESSION['sale_quantities']) ? count($_SESSION['sale_quantities']) : 0
                    ], 'INFO');
                    
                    // Process ALL session quantities > 0
                    if (isset($_SESSION['sale_quantities'])) {
                        foreach ($_SESSION['sale_quantities'] as $item_code => $total_qty) {
                            if ($total_qty > 0) {
                                debugCascadingLog("Processing item for distribution", [
                                    'item_code' => $item_code,
                                    'total_qty' => $total_qty
                                ], 'DEBUG');
                                
                                $item = $all_items[$item_code] ?? null;
                                
                                if (!$item) {
                                    // Item not in current query result
                                    debugCascadingLog("Item not in all_items, checking master table", $item_code, 'DEBUG');
                                    
                                    $check_master_query = "SELECT CODE, DETAILS, RPRICE, CLASS, LIQ_FLAG 
                                                          FROM tblitemmaster WHERE CODE = ?";
                                    $check_master_stmt = $conn->prepare($check_master_query);
                                    $check_master_stmt->bind_param("s", $item_code);
                                    $check_master_stmt->execute();
                                    $master_result = $check_master_stmt->get_result();
                                    
                                    if ($master_result->num_rows > 0) {
                                        $item = $master_result->fetch_assoc();
                                        debugCascadingLog("Found item in master table", [
                                            'item_code' => $item_code,
                                            'item_name' => $item['DETAILS']
                                        ], 'DEBUG');
                                    } else {
                                        debugCascadingLog("Item not found in master table, skipping", $item_code, 'WARNING');
                                        continue;
                                    }
                                    $check_master_stmt->close();
                                }
                                
                                // Get date availability for this item
                                $availability = getItemDateAvailability($conn, $item_code, $start_date, $end_date, $comp_id);
                                
                                debugCascadingLog("Date availability for item", [
                                    'item_code' => $item_code,
                                    'available_count' => $availability['available_count'],
                                    'total_dates' => $availability['total_dates']
                                ], 'DEBUG');
                                
                                // Check if item has any available dates
                                if ($availability['available_count'] == 0) {
                                    debugCascadingLog("Item has no available dates, skipping", [
                                        'item_code' => $item_code,
                                        'blocked_dates' => $availability['blocked_dates']
                                    ], 'WARNING');
                                    continue;
                                }
                                
                                // Generate distribution only across available dates
                                $daily_sales = distributeSalesAcrossAvailableDates($total_qty, $all_dates, $availability['available_dates']);
                                $daily_sales_data[$item_code] = $daily_sales;
                                
                                debugCascadingLog("Generated daily sales distribution", [
                                    'item_code' => $item_code,
                                    'total_qty' => $total_qty,
                                    'daily_sales_sum' => array_sum($daily_sales),
                                    'distribution' => $daily_sales
                                ], 'DEBUG');
                                
                                // Store item data
                                $items_data[$item_code] = [
                                    'name' => $item['DETAILS'],
                                    'rate' => $item['RPRICE'],
                                    'total_qty' => $total_qty,
                                    'mode' => $item['LIQ_FLAG']
                                ];
                            }
                        }
                    }
                    
                    debugCascadingLog("Items data prepared", [
                        'items_data_count' => count($items_data),
                        'daily_sales_data_count' => count($daily_sales_data)
                    ], 'INFO');
                    
                    // Only proceed if we have items with quantities
                    if (!empty($items_data)) {
                        debugCascadingLog("Generating bills with items data", [
                            'items_count' => count($items_data),
                            'dates_count' => count($all_dates)
                        ], 'INFO');
                        
                        // Generate bills
                        $bills = generateBillsWithLimits($conn, $items_data, $all_dates, $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
                        
                        debugCascadingLog("Bills generated", [
                            'bills_count' => count($bills)
                        ], 'INFO');
                        
                        // Get stock column names
                        $current_stock_column = "Current_Stock" . $comp_id;
                        $opening_stock_column = "Opening_Stock" . $comp_id;
                        
                        // Get next bill number
                        $next_bill_number = getNextBillNumber($conn, $comp_id);
                        
                        debugCascadingLog("Starting bill number", $next_bill_number, 'INFO');
                        
                        // Process each bill in chronological order
                        usort($bills, function($a, $b) {
                            return strtotime($a['bill_date']) - strtotime($b['bill_date']);
                        });
                        
                        $bill_counter = 0;
                        $processed_items = [];
                        
                        // Process each bill
                        foreach ($bills as $bill) {
                            $bill_counter++;
                            $padded_bill_no = "BL" . str_pad($next_bill_number++, 4, '0', STR_PAD_LEFT);
                            
                            debugCascadingLog("Processing bill $bill_counter", [
                                'bill_no' => $padded_bill_no,
                                'bill_date' => $bill['bill_date'],
                                'total_amount' => $bill['total_amount'],
                                'items_count' => count($bill['items']),
                                'mode' => $bill['mode']
                            ], 'INFO');
                            
                            // Insert sale header
                            $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                             VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                            $header_stmt = $conn->prepare($header_query);
                            $header_stmt->bind_param("ssddssi", $padded_bill_no, $bill['bill_date'], $bill['total_amount'], 
                                                    $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
                            $header_stmt->execute();
                            $header_stmt->close();
                            
                            debugCascadingLog("Inserted sale header", [
                                'bill_no' => $padded_bill_no,
                                'bill_date' => $bill['bill_date'],
                                'total_amount' => $bill['total_amount']
                            ], 'DEBUG');
                            
                            // Insert sale details for each item in the bill
                            foreach ($bill['items'] as $item) {
                                $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                                $detail_stmt = $conn->prepare($detail_query);
                                $detail_stmt->bind_param("ssddssi", $padded_bill_no, $item['code'], $item['qty'], 
                                                        $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                                $detail_stmt->execute();
                                $detail_stmt->close();
                                
                                debugCascadingLog("Inserted sale detail", [
                                    'bill_no' => $padded_bill_no,
                                    'item_code' => $item['code'],
                                    'qty' => $item['qty'],
                                    'rate' => $item['rate'],
                                    'amount' => $item['amount']
                                ], 'DEBUG');
                                
                                // Track processed items for stock update
                                if (!isset($processed_items[$item['code']])) {
                                    $processed_items[$item['code']] = 0;
                                }
                                $processed_items[$item['code']] += $item['qty'];
                                
                                // Update stock
                                updateItemStock($conn, $item['code'], $item['qty'], $current_stock_column, $opening_stock_column, $fin_year_id);
                                
                                // Use the ENHANCED cascading function
                                updateCascadingDailyStock($conn, $item['code'], $bill['bill_date'], $comp_id, 'sale', $item['qty']);
                                
                                // Also continue cascading to current month if needed
                                continueCascadingToCurrentMonth($conn, $comp_id, $item['code'], $bill['bill_date'], $item['qty']);
                            }
                            
                            $total_amount += $bill['total_amount'];
                        }
                        
                        debugCascadingLog("All bills processed", [
                            'total_bills' => $bill_counter,
                            'total_amount' => $total_amount,
                            'processed_items_count' => count($processed_items),
                            'processed_items' => $processed_items
                        ], 'INFO');
                        
                        // Commit transaction
                        $conn->commit();
                        
                        debugCascadingLog("Transaction committed successfully", null, 'INFO');
                        
                        // CLEAR SESSION QUANTITIES AFTER SUCCESS
                        clearSessionQuantities();
                        
                        $success_message = "Sales distributed successfully! Generated " . count($bills) . " bills. Total Amount: ₹" . number_format($total_amount, 2);
                        
                        debugCascadingLog("=== BILL GENERATION COMPLETED SUCCESSFULLY ===", [
                            'bills_generated' => count($bills),
                            'total_amount' => $total_amount
                        ], 'INFO');
                        
                        // Redirect to retail_sale.php
                        header("Location: retail_sale.php?success=" . urlencode($success_message));
                        exit;
                    } else {
                        $error_message = "No quantities entered for any items.";
                        debugCascadingLog("No items with quantities found", null, 'WARNING');
                    }
                } catch (Exception $e) {
                    // Rollback transaction on error
                    $conn->rollback();
                    $error_message = "Error updating sales: " . $e->getMessage();
                    
                    debugCascadingLog("=== BILL GENERATION FAILED ===", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ], 'ERROR');
                }
            }
        }
    }
    
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
}

// Check for success message in URL
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
    debugCascadingLog("Success message from URL", $success_message, 'INFO');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales by Date Range - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .ajax-loader {
      display: none;
      text-align: center;
      padding: 10px;
    }
    .loader {
      border: 5px solid #f3f3f3;
      border-top: 5px solid #3498db;
      border-radius: 50%;
      width: 30px;
      height: 30px;
      animation: spin 1s linear infinite;
      margin: 0 auto;
    }
    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }
   
    /* Remove spinner arrows from number inputs */
    input[type="number"]::-webkit-outer-spin-button,
    input[type="number"]::-webkit-inner-spin-button {
      -webkit-appearance: none;
      margin: 0;
    }
    input[type="number"] {
      -moz-appearance: textfield;
    }
    
    /* Highlight current row */
    .highlight-row {
      background-color: #f8f9fa !important;
      box-shadow: 0 0 5px rgba(0,0,0,0.1);
    }
    
    /* Volume limit info */
    .volume-limit-info {
      background-color: #e9ecef;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
    }

    /* Closing balance warning styles */
.text-warning {
    color: #ffc107 !important;
}

.fw-bold {
    font-weight: bold !important;
}

/* Negative closing balance (should never happen with validation) */
.text-danger {
    color: #dc3545 !important;
    background-color: #f8d7da;
}

/* Reduce space between table rows */
.styled-table tbody tr {
    height: 35px !important; /* Reduced from default */
    line-height: 1.2 !important;
}

.styled-table tbody td {
    padding: 4px 8px !important; /* Reduced padding */
}

/* Reduce input field height */
.qty-input {
    height: 30px !important;
    padding: 2px 6px !important;
}

/* Reduce button size */
.btn-sm {
    padding: 2px 6px !important;
    font-size: 12px !important;
}

/* Highlight rows with quantities */
tr.has-quantity {
    background-color: #e8f5e8 !important; /* Light green background */
    border-left: 3px solid #28a745 !important;
}

/* Make the highlight more noticeable */
tr.has-quantity td {
    font-weight: 500;
}

/* Enhanced validation styles */
.is-invalid {
    border-color: #dc3545 !important;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
}

.quantity-saving {
    background-color: #e8f5e8 !important;
    transition: background-color 0.3s ease;
}

.quantity-error {
    background-color: #f8d7da !important;
    transition: background-color 0.3s ease;
}

/* Button loading state */
.btn-loading {
    position: relative;
    color: transparent !important;
}

.btn-loading:after {
    content: '';
    position: absolute;
    left: 50%;
    top: 50%;
    margin-left: -10px;
    margin-top: -10px;
    width: 20px;
    height: 20px;
    border: 2px solid #ffffff;
    border-radius: 50%;
    border-top-color: transparent;
    animation: spin 1s linear infinite;
}

/* Enhanced Pagination Styles */
.pagination {
    margin: 15px 0;
    justify-content: center;
    flex-wrap: wrap;
}

.pagination .page-item .page-link {
    color: #007bff;
    border: 1px solid #dee2e6;
    padding: 6px 12px;
    font-size: 14px;
    margin: 2px;
}

.pagination .page-item.active .page-link {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
}

.pagination .page-item.disabled .page-link {
    color: #6c757d;
    pointer-events: none;
    background-color: #fff;
    border-color: #dee2e6;
}

.pagination .page-link:hover {
    background-color: #e9ecef;
    border-color: #dee2e6;
}

.pagination-info {
    text-align: center;
    margin: 10px 0;
    color: #6c757d;
    font-size: 14px;
}

/* Smart pagination - show limited pages */
.pagination-smart .page-item {
    display: none;
}

.pagination-smart .page-item:first-child,
.pagination-smart .page-item:last-child,
.pagination-smart .page-item.active,
.pagination-smart .page-item:nth-child(2),
.pagination-smart .page-item:nth-last-child(2) {
    display: block;
}

/* Show ellipsis for hidden pages */
.pagination-ellipsis {
    display: inline-block;
    padding: 6px 12px;
    margin: 2px;
    color: #6c757d;
}

/* Total Sales Summary Table Styles */
#totalSalesTable th {
    font-size: 11px;
    padding: 4px 2px;
    text-align: center;
    white-space: nowrap;
}

#totalSalesTable td {
    font-size: 11px;
    padding: 4px 2px;
    text-align: center;
}

.table-responsive {
    max-height: 600px;
    overflow: auto;
}

.table-success {
    background-color: #d1edff !important;
    font-weight: bold;
}

/* Client-side validation styles */
.validation-alert {
    display: none;
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
    max-width: 500px;
}

.stock-checking {
    background-color: #fff3cd !important;
}

/* Stock info note */
.stock-info-note {
    background-color: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 10px;
    margin: 10px 0;
    font-size: 0.9em;
}

/* NEW: Date Availability Styles */
.availability-info {
    background-color: #f8f9fa;
    border-left: 4px solid #0dcaf0;
    padding: 15px;
    margin-bottom: 20px;
}

.availability-summary {
    display: flex;
    gap: 15px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.availability-card {
    flex: 1;
    min-width: 150px;
    padding: 10px;
    border-radius: 5px;
    text-align: center;
}

.availability-card.fully {
    background-color: #d1e7dd;
    border: 1px solid #badbcc;
}

.availability-card.partially {
    background-color: #fff3cd;
    border: 1px solid #ffecb5;
}

.availability-card.none {
    background-color: #f8d7da;
    border: 1px solid #f5c2c7;
}

.availability-count {
    font-size: 24px;
    font-weight: bold;
    display: block;
}

.availability-label {
    font-size: 12px;
    color: #666;
}

/* Date availability badges */
.date-availability-badge {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 10px;
    margin-left: 5px;
}

.badge-fully {
    background-color: #198754;
    color: white;
}

.badge-partial {
    background-color: #ffc107;
    color: #000;
}

.badge-none {
    background-color: #dc3545;
    color: white;
}

/* Date distribution cell styles */
.date-available {
    background-color: #d1e7dd !important;
    color: #0f5132;
    font-weight: bold;
}

.date-blocked {
    background-color: #f8d7da !important;
    color: #842029;
    text-align: center;
}

.date-available-zero {
    background-color: #e8f5e9 !important;
    color: #2e7d32;
    text-align: center;
}

.date-blocked-icon {
    color: #dc3545;
    font-size: 14px;
}

/* Item availability status */
.item-unavailable {
    opacity: 0.6;
}

.item-unavailable .qty-input {
    background-color: #f8d7da;
    cursor: not-allowed;
}

.availability-tooltip {
    position: relative;
    display: inline-block;
}

.availability-tooltip .tooltip-text {
    visibility: hidden;
    width: 200px;
    background-color: #333;
    color: #fff;
    text-align: center;
    border-radius: 4px;
    padding: 5px;
    position: absolute;
    z-index: 1;
    bottom: 125%;
    left: 50%;
    margin-left: -100px;
    opacity: 0;
    transition: opacity 0.3s;
    font-size: 12px;
}

.availability-tooltip:hover .tooltip-text {
    visibility: visible;
    opacity: 1;
}

/* Archive month indicator */
.archive-month-badge {
    background-color: #6c757d;
    color: white;
    font-size: 10px;
    padding: 1px 4px;
    border-radius: 3px;
    margin-left: 3px;
}

.archive-month-info {
    background-color: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 10px;
    margin: 10px 0;
    font-size: 0.9em;
}

/* Cascading date indicator */
.cascading-effect {
    background-color: #e8f4f8 !important;
    border-left: 3px solid #17a2b8 !important;
}

.cascading-info {
    background-color: #d1ecf1;
    border-left: 4px solid #0dcaf0;
    padding: 10px;
    margin: 10px 0;
    font-size: 0.9em;
}

/* Enhanced debug info styles */
.debug-info {
    background-color: #f8f9fa;
    border-left: 4px solid #6c757d;
    padding: 10px;
    margin: 10px 0;
    font-size: 0.85em;
    font-family: monospace;
}

.debug-info-header {
    background-color: #6c757d;
    color: white;
    padding: 5px 10px;
    margin: -10px -10px 10px -10px;
    font-weight: bold;
}

.debug-info-item {
    margin: 5px 0;
    padding: 3px 5px;
    background-color: #e9ecef;
    border-radius: 3px;
}

.debug-info-success {
    border-left-color: #28a745;
    background-color: #d4edda;
}

.debug-info-warning {
    border-left-color: #ffc107;
    background-color: #fff3cd;
}

.debug-info-error {
    border-left-color: #dc3545;
    background-color: #f8d7da;
}
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Sales by Date Range</h3>

      <!-- Debug Information Panel -->
      <div class="debug-info mb-3">
          <div class="debug-info-header">
              <i class="fas fa-bug"></i> Debug Information
          </div>
          <div class="debug-info-item">
              <strong>Current Time:</strong> <?= date('Y-m-d H:i:s') ?>
          </div>
          <div class="debug-info-item">
              <strong>Session Items:</strong> <?= isset($_SESSION['sale_quantities']) ? count($_SESSION['sale_quantities']) : 0 ?>
          </div>
          <div class="debug-info-item">
              <strong>Table Exists:</strong> <?= $table_exists ? 'Yes' : 'No' ?>
          </div>
          <div class="debug-info-item">
              <strong>Daily Stock Table:</strong> <?= $daily_stock_table ?>
          </div>
          <div class="debug-info-item">
              <strong>Date Range:</strong> <?= date('d-M-Y', strtotime($start_date)) ?> to <?= date('d-M-Y', strtotime($end_date)) ?> (<?= $days_count ?> days)
          </div>
          <div class="debug-info-item">
              <strong>Available Items:</strong> <?= count($items) ?> (Filtered by license)
          </div>
      </div>

      <!-- NEW: Enhanced Cascading Stock Update Information -->
      <div class="cascading-info mb-3">
          <strong><i class="fas fa-sync-alt"></i> ENHANCED Cascading Stock Updates</strong>
          <p class="mb-0">The system now properly cascades stock updates across ALL months:</p>
          <ul class="mb-0">
              <li><strong>Sale on Nov 1st → Updates Nov 1st closing</strong></li>
              <li><strong>Cascades within Nov: Day 2 opening = Day 1 closing, etc.</strong></li>
              <li><strong>Carries to Dec: Day 1 opening = Nov last closing</strong></li>
              <li><strong>Carries to Jan: Day 1 opening = Dec last closing</strong></li>
              <li><strong>Deducts sale qty from current month opening</strong></li>
              <li><strong>Recalculates ALL closing balances across all months</strong></li>
          </ul>
      </div>

      <!-- NEW: Strict Date Availability Information Banner -->
      <div class="availability-info mb-4">
          <h5><i class="fas fa-calendar-times"></i> STRICT Date Availability System</h5>
          <p class="mb-2">Sales can ONLY be recorded on dates that are:</p>
          <ul class="mb-3">
              <li><strong>STRICTLY AFTER the item's latest sale date</strong> (prevents duplicate/backdated sales)</li>
              <li><strong>NOT on dry days</strong> (as per government regulations)</li>
              <li><strong>NOT in the future</strong> (only current/past dates allowed)</li>
          </ul>
          <p class="mb-0 text-danger"><strong>IMPORTANT:</strong> Items with NO available dates are COMPLETELY BLOCKED - quantity input disabled.</p>
          
          <!-- Availability Summary Cards -->
          <div class="availability-summary">
              <div class="availability-card fully">
                  <span class="availability-count"><?= $availability_summary['fully_available'] ?></span>
                  <span class="availability-label">Fully Available<br>(All <?= $days_count ?> dates)</span>
              </div>
              <div class="availability-card partially">
                  <span class="availability-count"><?= $availability_summary['partially_available'] ?></span>
                  <span class="availability-label">Partially Available<br>(Some dates blocked)</span>
              </div>
              <div class="availability-card none">
                  <span class="availability-count"><?= $availability_summary['not_available'] ?></span>
                  <span class="availability-label">COMPLETELY BLOCKED<br>(All dates blocked)</span>
              </div>
          </div>
      </div>

      <!-- ADDED: License Restriction Info -->
      <div class="alert alert-info mb-3">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
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

      <!-- Stock Info Note -->
      <div class="stock-info-note mb-3">
          <strong><i class="fas fa-info-circle"></i> Stock Information:</strong>
          <p class="mb-0">Showing stock as of <strong><?= date('d-M-Y', strtotime($end_date)) ?></strong> (DAY_<?= $end_day ?>_CLOSING from <?= $daily_stock_table ?> table). Only items with stock > 0 are displayed.</p>
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

      <?php if (!$table_exists): ?>
      <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle"></i> Daily stock table '<?= $daily_stock_table ?>' not found for the selected end date. Please select a different date range.
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <!-- Client-side Validation Alert -->
      <div class="alert alert-warning validation-alert" id="clientValidationAlert">
        <i class="fas fa-exclamation-triangle"></i>
        <span id="validationMessage"></span>
      </div>

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&sequence_type=<?= $sequence_type ?>&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>

      <!-- Sequence Type Selector -->
      <div class="mb-3">
        <label class="form-label">Sequence Type:</label>
        <div class="btn-group" role="group">
          <a href="?mode=<?= $mode ?>&sequence_type=user_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'user_defined' ? 'sequence-active' : '' ?>">
            User Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=system_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'system_defined' ? 'sequence-active' : '' ?>">
            System Defined
          </a>
          <a href="?mode=<?= $mode ?>&sequence_type=group_defined&search=<?= urlencode($search) ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1"
             class="btn btn-outline-primary <?= $sequence_type === 'group_defined' ? 'sequence-active' : '' ?>">
                         Group Defined
          </a>
        </div>
      </div>

      <!-- Date Range Selection -->
      <div class="date-range-container mb-4">
        <form method="GET" class="row g-3 align-items-end">
          <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
          <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
          <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>">
          <input type="hidden" name="page" value="1">
          
          <div class="col-md-3">
            <label for="start_date" class="form-label">Start Date</label>
            <input type="date" name="start_date" class="form-control" 
                   value="<?= htmlspecialchars($start_date); ?>" required>
          </div>
          
          <div class="col-md-3">
            <label for="end_date" class="form-label">End Date</label>
            <input type="date" name="end_date" class="form-control" 
                   value="<?= htmlspecialchars($end_date); ?>" 
                   max="<?= date('Y-m-d'); ?>" required>
          </div>
          
          <div class="col-md-4">
            <label class="form-label">Date Range: 
              <span class="fw-bold">
<?= date('d-M-Y', strtotime($start_date)) . " to " . date('d-M-Y', strtotime($end_date)) ?>
                (<?= $days_count ?> days)
              </span>
            </label>
            <div class="text-muted small">
              Stock shown: As of <?= date('d-M-Y', strtotime($end_date)) ?> closing
              <?php 
              // Show archive month indicator if end date is in archived month
              $current_month = date('Y-m');
              $end_date_month = date('Y-m', strtotime($end_date));
              if ($end_date_month < $current_month): ?>
                <span class="archive-month-badge">Archive Month</span>
              <?php endif; ?>
            </div>
          </div>
          
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Apply Date Range</button>
          </div>
        </form>
      </div>

      <!-- Search -->
      <div class="row mb-3">
        <div class="col-md-6">
          <form method="GET" class="search-control">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
            <input type="hidden" name="sequence_type" value="<?= htmlspecialchars($sequence_type); ?>">
            <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
            <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">
            <input type="hidden" name="page" value="1">
            <div class="input-group">
              <input type="text" name="search" class="form-control"
                     placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
              <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
              <?php if ($search !== ''): ?>
                <a href="?mode=<?= $mode ?>&sequence_type=<?= $sequence_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&page=1" class="btn btn-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
        <div class="col-md-6 text-end">
          <div class="text-muted">
            Total Items: <?= $total_items ?> | Page: <?= $current_page ?> of <?= $total_pages ?>
            <?php if (count($_SESSION['sale_quantities']) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['sale_quantities']) ?> items with quantities</span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Sales Form -->
      <form method="POST" id="salesForm">
        <input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
        <input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">
        <input type="hidden" name="update_sales" value="1">

        <!-- Action Buttons -->
        <div class="d-flex gap-2 mb-3 flex-wrap">
          <button type="button" id="shuffleBtn" class="btn btn-warning btn-action">
            <i class="fas fa-random"></i> Shuffle All
          </button>
          
          <!-- Single Button with Dual Functionality -->
          <button type="button" id="generateBillsBtn" class="btn btn-success btn-action" <?= !$table_exists ? 'disabled' : '' ?>>
            <i class="fas fa-save"></i> Generate Bills
          </button>
          
          <!-- Clear Session Button -->
          <button type="button" id="clearSessionBtn" class="btn btn-danger" <?= !$table_exists ? 'disabled' : '' ?>>
            <i class="fas fa-trash"></i> Clear All Quantities
          </button>
          
          <!-- Sales Log Button -->
          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#salesLogModal" onclick="loadSalesLog()" <?= !$table_exists ? 'disabled' : '' ?>>
              <i class="fas fa-file-alt"></i> View Sales Log
          </button>

          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#totalSalesModal" <?= !$table_exists ? 'disabled' : '' ?>>
              <i class="fas fa-chart-bar"></i> View Total Sales Summary
          </button>
          
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>

        <!-- Items Table with Integrated Distribution Preview -->
        <div class="table-container">
          <table class="styled-table table-striped" id="itemsTable">
            <thead class="table-header">
              <tr>
                <th>Item Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th>Rate (₹)</th>
                <th>Closing Stock<br><small>As of <?= date('d-M-Y', strtotime($end_date)) ?></small></th>
                <th>Total Sale Qty</th>
                <th class="closing-balance-header">Closing Balance</th>
                <th class="action-column">Action</th>
                
                <!-- Date Distribution Headers (will be populated by JavaScript) -->
                
                <th class="hidden-columns">Amount (₹)</th>
              </tr>
            </thead>
            <tbody>
<?php if (!empty($items) && $table_exists): ?>
    <?php foreach ($items as $item): 
        $item_code = $item['CODE'];
        $item_qty = isset($_SESSION['sale_quantities'][$item_code]) ? $_SESSION['sale_quantities'][$item_code] : 0;
        $item_total = $item_qty * $item['RPRICE'];
        $closing_balance = $item['CURRENT_STOCK'] - $item_qty;
        
        // Extract size from item details
        $size = 0;
        if (preg_match('/(\d+)\s*ML\b/i', $item['DETAILS'], $matches)) {
            $size = $matches[1];
        }
        
        // Get class code - now available from the query
        $class_code = $item['CLASS'] ?? 'O'; // Default to 'O' if not set
        
        // Get date availability for this item
        $availability = $item_date_availability[$item_code] ?? [
            'available_dates' => [],
            'blocked_dates' => [],
            'available_count' => 0,
            'blocked_count' => $days_count
        ];
        
        // Determine availability badge
        if ($availability['available_count'] == $days_count) {
            $availability_badge = '<span class="date-availability-badge badge-fully">All dates</span>';
        } elseif ($availability['available_count'] > 0) {
            $availability_badge = '<span class="date-availability-badge badge-partial">' . $availability['available_count'] . '/' . $days_count . ' dates</span>';
        } else {
            $availability_badge = '<span class="date-availability-badge badge-none">BLOCKED</span>';
        }
        
        // Determine if item is unavailable (all dates blocked)
        $is_unavailable = ($availability['available_count'] == 0);
        
        // Get latest sale date info for tooltip
        $latest_sale_info = '';
        if ($availability['latest_sale_date']) {
            $latest_sale_info = 'Latest sale: ' . date('d-M-Y', strtotime($availability['latest_sale_date']));
        }
    ?>
        <tr data-class="<?= htmlspecialchars($class_code) ?>" 
    data-details="<?= htmlspecialchars($item['DETAILS']) ?>" 
    data-details2="<?= htmlspecialchars($item['DETAILS2']) ?>"
    data-availability='<?= json_encode($availability) ?>'
    class="<?= $item_qty > 0 ? 'has-quantity' : '' ?> <?= $is_unavailable ? 'item-unavailable' : '' ?>">
            <td>
                <?= htmlspecialchars($item_code); ?>
                <div class="availability-tooltip">
                    <?= $availability_badge ?>
                    <?php if ($latest_sale_info): ?>
                    <span class="tooltip-text"><?= $latest_sale_info ?><br>
                    <?php if ($availability['blocked_count'] > 0): ?>
                        Blocked dates: <?= $availability['blocked_count'] ?>/<?= $days_count ?>
                    <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </td>
            <td><?= htmlspecialchars($item['DETAILS']); ?></td>
            <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
            <td><?= number_format($item['RPRICE'], 2); ?></td>
            <td>
                <span class="stock-info"><?= number_format($item['CURRENT_STOCK'], 3); ?></span>
            </td>
            <td>
    <input type="number" name="sale_qty[<?= htmlspecialchars($item_code); ?>]" 
           class="form-control qty-input" min="0" 
           max="<?= floor($item['CURRENT_STOCK']); ?>" 
           step="1" value="<?= $item_qty ?>" 
           data-rate="<?= $item['RPRICE'] ?>"
           data-code="<?= htmlspecialchars($item_code); ?>"
           data-stock="<?= $item['CURRENT_STOCK'] ?>"
           data-size="<?= $size ?>"
           data-available-count="<?= $availability['available_count'] ?>"
           oninput="validateQuantity(this)"
           <?= (!$table_exists || $is_unavailable) ? 'disabled' : '' ?>
           title="<?= $is_unavailable ? 'Item not available for any dates in selected range' : 'Enter quantity' ?>">
</td>
            <td class="closing-balance-cell" id="closing_<?= htmlspecialchars($item_code); ?>">
                <?= number_format($closing_balance, 3) ?>
            </td>
            <td class="action-column">
                <button type="button" class="btn btn-sm btn-outline-secondary btn-shuffle-item" 
                        data-code="<?= htmlspecialchars($item_code); ?>"
                        <?= (!$table_exists || $is_unavailable) ? 'disabled' : '' ?>
                        title="<?= $is_unavailable ? 'Item not available for any dates' : 'Shuffle distribution across available dates' ?>">
                    <i class="fas fa-random"></i> Shuffle
                </button>
            </td>
            
            <!-- Date distribution cells will be inserted here by JavaScript -->
            
            <td class="amount-cell hidden-columns" id="amount_<?= htmlspecialchars($item_code); ?>">
                <?= number_format($item_qty * $item['RPRICE'], 2) ?>
            </td>
        </tr>
    <?php endforeach; ?>
<?php elseif (!$table_exists): ?>
    <tr>
        <td colspan="9" class="text-center text-warning">
            <i class="fas fa-exclamation-triangle"></i> Daily stock table not found for the selected end date. Please select a different date range.
        </td>
    </tr>
<?php else: ?>
    <tr>
        <td colspan="9" class="text-center text-muted">No items found with stock > 0 for the selected date range.</td>
    </tr>
<?php endif; ?>
</tbody>
            </tfoot>
          </table>
        </div>
        
        <!-- Pagination Controls -->
        <?php if ($total_pages > 1): ?>
        <nav aria-label="Page navigation">
            <ul class="pagination justify-content-center">
                <!-- Previous Button -->
                <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page - 1])) ?>" aria-label="Previous">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                
                <?php
                // Smart pagination - show limited pages
                $show_pages = 5; // Number of page links to show
                $start_page = max(1, $current_page - floor($show_pages / 2));
                $end_page = min($total_pages, $start_page + $show_pages - 1);
                
                // Adjust if we're near the end
                if ($end_page - $start_page < $show_pages - 1) {
                    $start_page = max(1, $end_page - $show_pages + 1);
                }
                
                // Always show first page
                if ($start_page > 1): ?>
                    <li class="page-item <?= 1 == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                    </li>
                    <?php if ($start_page > 2): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                    <?php endif;
                endif;
                
                // Show page numbers
                for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                    </li>
                <?php endfor;
                
                // Always show last page
                if ($end_page < $total_pages): ?>
                    <?php if ($end_page < $total_pages - 1): ?>
                        <li class="page-item disabled">
                            <span class="page-link">...</span>
                        </li>
                    <?php endif; ?>
                    <li class="page-item <?= $total_pages == $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
                    </li>
                <?php endif; ?>
                
                <!-- Next Button -->
                <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $current_page + 1])) ?>" aria-label="Next">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
        <div class="pagination-info">
            Showing <?= count($items) ?> of <?= $total_items ?> items (Page <?= $current_page ?> of <?= $total_pages ?>)
            <?php if (count($_SESSION['sale_quantities']) > 0): ?>
              | <span class="text-success"><?= count($_SESSION['sale_quantities']) ?> items with quantities across all pages</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- Ajax Loader -->
        <div id="ajaxLoader" class="ajax-loader">
          <div class="loader"></div>
          <p>Calculating distribution...</p>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Sales Log Modal -->
<div class="modal fade" id="salesLogModal" tabindex="-1" aria-labelledby="salesLogModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
                        <div class="modal-header">
                <h5 class="modal-title" id="salesLogModalLabel">Sales Log - Foreign Export</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="salesLogContent" style="max-height: 400px; overflow-y: auto;">
                    <!-- Sales log content will be loaded here -->
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading sales log...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printSalesLog()">Print</button>
            </div>
        </div>
    </div>
</div>

<!-- Total Sales Modal -->
<div class="modal fade" id="totalSalesModal" tabindex="-1" aria-labelledby="totalSalesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="totalSalesModalLabel">Total Sales Summary</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="totalSalesTable">
                        <thead class="table-light">
                            <tr>
                                <th>Category</th>
                                <!-- ML Sizes -->
                                <th>50 ML</th>
                                <th>60 ML</th>
                                <th>90 ML</th>
                                <th>170 ML</th>
                                <th>180 ML</th>
                                <th>200 ML</th>
                                <th>250 ML</th>
                                <th>275 ML</th>
                                <th>330 ML</th>
                                <th>355 ML</th>
                                <th>375 ML</th>
                                <th>500 ML</th>
                                <th>650 ML</th>
                                <th>700 ML</th>
                                <th>750 ML</th>
                                <th>1000 ML</th>
                                <!-- Liter Sizes -->
                                <th>1.5L</th>
                                <th>1.75L</th>
                                <th>2L</th>
                                <th>3L</th>
                                <th>4.5L</th>
                                <th>15L</th>
                                <th>20L</th>
                                <th>30L</th>
                                <th>50L</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Rows will be populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Bill Progress Modal -->
<div class="modal fade" id="billProgressModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-md">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-spinner fa-spin me-2"></i>
                    Generating Bills...
                </h5>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <h6 class="mt-3 text-muted" id="progressStatus">Processing your request...</h6>
                </div>

                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-bold">Progress</span>
                        <span class="text-muted small" id="overallProgressText">0%</span>
                    </div>
                    <div class="progress" style="height: 20px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated bg-success"
                             id="billProgressBar" style="width: 0%"></div>
                    </div>
                </div>

                <div class="alert alert-light">
                    <small class="text-muted">Please do not close this window or refresh the page while bills are being generated.</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global variables
const dateArray = <?= json_encode($date_array) ?>;
const daysCount = <?= $days_count ?>;
// Pass ALL session quantities to JavaScript
const allSessionQuantities = <?= json_encode($_SESSION['sale_quantities'] ?? []) ?>;
// NEW: Pass ALL items data to JavaScript for Total Sales Summary (ALL modes)
const allItemsData = <?= json_encode($all_items_data) ?>;
// Table exists flag
const tableExists = <?= $table_exists ? 'true' : 'false' ?>;
// Pass date availability data to JavaScript
const itemDateAvailability = <?= json_encode($item_date_availability) ?>;

// NEW: Function to check stock availability via AJAX before submission
function checkStockAvailabilityBeforeSubmit() {
    return new Promise((resolve, reject) => {
        // Check if table exists
        if (!tableExists) {
            reject('Daily stock table not available for the selected date range.');
            return;
        }
        
        // Check if we have any quantities > 0
        let hasQuantity = false;
        for (const itemCode in allSessionQuantities) {
            if (allSessionQuantities[itemCode] > 0) {
                hasQuantity = true;
                break;
            }
        }
        
        if (!hasQuantity) {
            reject('Please enter quantities for at least one item.');
            return;
        }

        // NEW: Check date availability for all items with quantities - allow partial processing
        let skippedItems = [];
        for (const itemCode in allSessionQuantities) {
            const qty = allSessionQuantities[itemCode];
            if (qty > 0) {
                const availability = itemDateAvailability[itemCode];
                if (!availability || availability.available_count === 0) {
                    const itemName = allItemsData[itemCode]?.DETAILS || itemCode;
                    skippedItems.push(`${itemName} (${itemCode})`);
                    // Remove this item from session quantities to exclude it from processing
                    delete allSessionQuantities[itemCode];
                }
            }
        }

        // Items with no available dates are automatically removed from processing
        // No alert message displayed as requested

        // Show checking state
        $('#generateBillsBtn').prop('disabled', true).addClass('btn-loading');
        $('tr.has-quantity').addClass('stock-checking');

        // Prepare data for AJAX check
        const checkData = {
            start_date: '<?= $start_date ?>',
            end_date: '<?= $end_date ?>',
            mode: '<?= $mode ?>',
            comp_id: '<?= $comp_id ?>',
            quantities: allSessionQuantities
        };

        $.ajax({
            url: 'check_stock_availability.php',
            type: 'POST',
            data: JSON.stringify(checkData),
            contentType: 'application/json',
            success: function(response) {
                $('#generateBillsBtn').prop('disabled', false).removeClass('btn-loading');
                $('tr.has-quantity').removeClass('stock-checking');
                
                try {
                    const result = JSON.parse(response);
                    if (result.success) {
                        resolve(true);
                    } else {
                        showClientValidationAlert(result.message);
                        reject(result.message);
                    }
                } catch (e) {
                    showClientValidationAlert('Error checking stock availability. Please try again.');
                    reject('Error checking stock availability.');
                }
            },
            error: function() {
                $('#generateBillsBtn').prop('disabled', false).removeClass('btn-loading');
                $('tr.has-quantity').removeClass('stock-checking');
                showClientValidationAlert('Error connecting to server. Please try again.');
                reject('Connection error');
            }
        });
    });
}

// NEW: Function to show client-side validation alert
function showClientValidationAlert(message) {
    $('#validationMessage').text(message);
    $('#clientValidationAlert').fadeIn();
    
    // Auto-hide after 10 seconds
    setTimeout(() => {
        $('#clientValidationAlert').fadeOut();
    }, 10000);
}

// Function to clear session quantities via AJAX
function clearSessionQuantities() {
    // Check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot clear quantities.');
        return;
    }
    
    $.ajax({
        url: 'clear_session_quantities.php',
        type: 'POST',
        success: function(response) {
            console.log('Session quantities cleared');
            // Reload the page to reflect changes
            location.reload();
        },
        error: function() {
            console.log('Error clearing session quantities');
            alert('Error clearing quantities. Please try again.');
        }
    });
}

// STRICT QUANTITY VALIDATION: Completely block items with no available dates
function validateQuantity(input) {
    // Check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot update quantities.');
        $(input).val(0);
        return false;
    }
    
    const itemCode = $(input).data('code');
    const currentStock = parseFloat($(input).data('stock'));
    let enteredQty = parseInt($(input).val()) || 0;
    const availableCount = parseInt($(input).data('available-count') || 0);
    
    // STRICT BLOCKING: If item has NO available dates, COMPLETELY BLOCK
    if (availableCount === 0) {
        $(input).val(0);
        enteredQty = 0;
        $(input).prop('disabled', true);
        $(input).closest('tr').addClass('item-unavailable');
        
        // Show alert to user
        const itemName = $(input).closest('tr').find('td:nth-child(2)').text();
        showClientValidationAlert(`Item "${itemName}" (${itemCode}) is not available for any dates in the selected range.`);
        return false;
    }
    
    // Validate input
    if (isNaN(enteredQty) || enteredQty < 0) {
        enteredQty = 0;
        $(input).val(0);
    }
    
    // Prevent exceeding stock with better feedback
    if (enteredQty > currentStock) {
        const maxAllowed = Math.floor(currentStock);
        enteredQty = maxAllowed;
        $(input).val(maxAllowed);
        
        // Show warning but don't prevent operation
        $(input).addClass('is-invalid');
        setTimeout(() => $(input).removeClass('is-invalid'), 2000);
    } else {
        $(input).removeClass('is-invalid');
    }
    
    // Update UI immediately
    updateItemUI(itemCode, enteredQty, currentStock);
    
    // Save to session via AJAX to prevent data loss
    saveQuantityToSession(itemCode, enteredQty);
    
    return true;
}

// New function to update all UI elements for an item
function updateItemUI(itemCode, qty, currentStock) {
    const rate = parseFloat($(`input[name="sale_qty[${itemCode}]"]`).data('rate'));
    const closingBalance = currentStock - qty;
    const amount = qty * rate;
    
    // Update all related UI elements
    $(`#closing_${itemCode}`).text(closingBalance.toFixed(3));
    $(`#amount_${itemCode}`).text(amount.toFixed(2));
    
    // Update row styling
    const row = $(`input[name="sale_qty[${itemCode}]"]`).closest('tr');
    row.toggleClass('has-quantity', qty > 0);
    
    // Update closing balance styling
    const closingCell = $(`#closing_${itemCode}`);
    closingCell.removeClass('text-warning text-danger fw-bold');
    
    if (closingBalance < 0) {
        closingCell.addClass('text-danger fw-bold');
    } else if (closingBalance < (currentStock * 0.1)) {
        closingCell.addClass('text-warning fw-bold');
    }
}

// New function to save quantity to session via AJAX
function saveQuantityToSession(itemCode, qty) {
    // Check if table exists
    if (!tableExists) return;
    
    // Debounce to prevent too many requests
    if (typeof saveQuantityToSession.debounce === 'undefined') {
        saveQuantityToSession.debounce = null;
    }
    
    clearTimeout(saveQuantityToSession.debounce);
    saveQuantityToSession.debounce = setTimeout(() => {
        $.ajax({
            url: 'update_session_quantity.php',
            type: 'POST',
            data: {
                item_code: itemCode,
                quantity: qty
            },
            success: function(response) {
                console.log('Quantity saved to session:', itemCode, qty);
                // Update global session quantities object
                allSessionQuantities[itemCode] = qty;
            },
            error: function() {
                console.error('Failed to save quantity to session');
            }
        });
    }, 200);
}

// STRICT VALIDATION: Function to validate all quantities before form submission
function validateAllQuantities() {
    // Check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot generate bills.');
        return false;
    }
    
    let isValid = true;
    let errorItems = [];
    
    // Validate ONLY session quantities > 0 (optimization)
    for (const itemCode in allSessionQuantities) {
        const qty = allSessionQuantities[itemCode];
        if (qty > 0) {
            // Find the stock data from the input field or use a default
            const inputField = $(`input[name="sale_qty[${itemCode}]"]`);
            let currentStock;
            
            if (inputField.length > 0) {
                currentStock = parseFloat(inputField.data('stock'));
            } else {
                // If item not in current view, get from allItemsData
                if (allItemsData[itemCode]) {
                    currentStock = parseFloat(allItemsData[itemCode].CURRENT_STOCK);
                } else {
                    continue; // Skip if we can't validate
                }
            }
            
            const closingBalance = currentStock - qty;
            
            if (closingBalance < 0) {
                isValid = false;
                errorItems.push({
                    code: itemCode,
                    stock: currentStock,
                    qty: qty
                });
            }
            
            // STRICT CHECK: Item must have available dates
            const availability = itemDateAvailability[itemCode];
            if (!availability || availability.available_count === 0) {
                isValid = false;
                errorItems.push({
                    code: itemCode,
                    error: 'Item has no available dates for sale'
                });
            }
        }
    }
    
    if (!isValid) {
        let errorMessage = "The following items have issues:\n\n";
        errorItems.forEach(item => {
            if (item.stock !== undefined) {
                errorMessage += `• Item ${item.code}: Insufficient stock (Available: ${item.stock.toFixed(3)}, Requested: ${item.qty})\n`;
            } else {
                const itemName = allItemsData[item.code]?.DETAILS || item.code;
                errorMessage += `• ${itemName} (${item.code}): ${item.error}\n`;
            }
        });
        errorMessage += "\nPlease adjust quantities to resolve these issues.";
        alert(errorMessage);
    }
    
    return isValid;
}

// NEW: Function to distribute sales only across available dates (client-side version)
function distributeSalesAcrossAvailableDates(total_qty, date_array, available_dates) {
    if (total_qty <= 0) {
        return Object.fromEntries(date_array.map(date => [date, 0]));
    }
    
    // Filter to get only available dates from the date array
    const available_dates_in_range = date_array.filter(date => available_dates[date] === true);
    
    if (available_dates_in_range.length === 0) {
        // If no dates available, return all zeros
        return Object.fromEntries(date_array.map(date => [date, 0]));
    }
    
    const available_count = available_dates_in_range.length;
    const base_qty = Math.floor(total_qty / available_count);
    const remainder = total_qty % available_count;
    
    // Initialize distribution with zeros for all dates
    const distribution = {};
    date_array.forEach(date => {
        distribution[date] = 0;
    });
    
    // Distribute base quantity to available dates
    available_dates_in_range.forEach(date => {
        distribution[date] = base_qty;
    });
    
    // Distribute remainder to first few available dates
    for (let i = 0; i < remainder; i++) {
        distribution[available_dates_in_range[i]]++;
    }
    
    // Shuffle the distribution among available dates
    const shuffled_available_dates = [...available_dates_in_range];
    for (let i = shuffled_available_dates.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [shuffled_available_dates[i], shuffled_available_dates[j]] = [shuffled_available_dates[j], shuffled_available_dates[i]];
    }
    
    // Create shuffled distribution
    const shuffled_distribution = {};
    date_array.forEach(date => {
        shuffled_distribution[date] = 0;
    });
    
    const available_values = shuffled_available_dates.map(date => distribution[date]);
    
    // Assign shuffled values back to available dates
    shuffled_available_dates.forEach((date, index) => {
        shuffled_distribution[date] = available_values[index];
    });
    
    return shuffled_distribution;
}

// OPTIMIZED: Function to update the distribution preview ONLY for items with qty > 0
function updateDistributionPreview(itemCode, totalQty) {
    // Check if table exists
    if (!tableExists) return;
    
    if (totalQty <= 0) {
        // Remove distribution cells if quantity is 0
        $(`input[name="sale_qty[${itemCode}]"]`).closest('tr').find('.date-distribution-cell').remove();
        return;
    }
    
    // STRICT CHECK: Get date availability for this item - COMPLETELY BLOCK if no dates available
    const availability = itemDateAvailability[itemCode];
    if (!availability || availability.available_count === 0) {
        $(`input[name="sale_qty[${itemCode}]"]`).val(0);
        $(`input[name="sale_qty[${itemCode}]"]`).prop('disabled', true);
        $(`input[name="sale_qty[${itemCode}]"]`).closest('tr').addClass('item-unavailable');
        
        const itemName = $(`input[name="sale_qty[${itemCode}]"]`).closest('tr').find('td:nth-child(2)').text();
        showClientValidationAlert(`Item "${itemName}" (${itemCode}) has no available dates. Quantity reset to 0.`);
        return;
    }
    
    // NEW: Use date availability for distribution
    const dailySales = distributeSalesAcrossAvailableDates(totalQty, dateArray, availability.available_dates);
    const rate = parseFloat($(`input[name="sale_qty[${itemCode}]"]`).data('rate'));
    const itemRow = $(`input[name="sale_qty[${itemCode}]"]`).closest('tr');
    
    // Remove any existing distribution cells
    itemRow.find('.date-distribution-cell').remove();
    
    // Add date distribution cells after the action column
    let totalDistributed = 0;
    dateArray.forEach((date, index) => {
        const qty = dailySales[date] || 0;
        totalDistributed += qty;
        
        // Determine cell class based on availability and quantity
        let cellClass = 'date-distribution-cell';
        let cellContent = qty;
        let cellTitle = date;
        
        if (availability.available_dates[date]) {
            if (qty > 0) {
                cellClass += ' date-available';
                cellTitle += '\nAvailable date: ' + qty + ' units';
            } else {
                cellClass += ' date-available-zero';
                cellTitle += '\nAvailable date: 0 units';
            }
        } else {
            cellClass += ' date-blocked';
            cellContent = '<i class="fas fa-ban date-blocked-icon"></i>';
            cellTitle += '\n' + (availability.blocked_dates[date] || 'Not available');
        }
        
        // Create cell with tooltip
        const cell = $(`<td class="${cellClass}" title="${cellTitle}">${cellContent}</td>`);
        cell.insertAfter(itemRow.find('.action-column').eq(index));
    });
    
    // Update closing balance
    const currentStock = parseFloat($(`input[name="sale_qty[${itemCode}]"]`).data('stock'));
    const closingBalance = currentStock - totalDistributed;
    $(`#closing_${itemCode}`).text(closingBalance.toFixed(3));
    
    // Update amount
    const amount = totalDistributed * rate;
    $(`#amount_${itemCode}`).text(amount.toFixed(2));
    
    // Show date columns if they're hidden
    $('.date-header, .date-distribution-cell').show();
    
    return dailySales;
}

// Function to calculate total amount
function calculateTotalAmount() {
    let total = 0;
    $('.amount-cell').each(function() {
        total += parseFloat($(this).text()) || 0;
    });
    $('#totalAmount').text(total.toFixed(2));
}

// Function to initialize date headers and closing balance column
function initializeTableHeaders() {
    // Remove existing date headers if any
    $('.date-header').remove();
    
    // Add date headers after the action column header
    dateArray.forEach(date => {
        const dateObj = new Date(date);
        const day = dateObj.getDate();
        const month = dateObj.toLocaleString('default', { month: 'short' });
        
        // Insert date headers after the action column header
        $(`<th class="date-header" title="${date}" style="display: none;">${day}<br>${month}</th>`).insertAfter($('.table-header tr th.action-column'));
    });
}

// Function to handle row navigation with arrow keys
function setupRowNavigation() {
    const qtyInputs = $('input.qty-input');
    let currentRowIndex = -1;
    
    // Highlight row when input is focused
    $(document).on('focus', 'input.qty-input', function() {
        // Remove highlight from all rows
        $('tr').removeClass('highlight-row');
        
        // Add highlight to current row
        $(this).closest('tr').addClass('highlight-row');
        
        // Update current row index
        currentRowIndex = qtyInputs.index(this);
    });
    
    // Handle arrow key navigation
    $(document).on('keydown', 'input.qty-input', function(e) {
        // Only handle arrow keys
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        
        e.preventDefault(); // Prevent default scrolling behavior
        
        // Calculate new row index
        let newIndex;
        if (e.key === 'ArrowUp') {
            newIndex = currentRowIndex - 1;
        } else { // ArrowDown
            newIndex = currentRowIndex + 1;
        }
        
        // Check if new index is valid
        if (newIndex >= 0 && newIndex < qtyInputs.length) {
            // Focus the input in the new row
            $(qtyInputs[newIndex]).focus().select();
        }
    });
}

// UPDATED: Function to generate bills immediately with STRICT validation and ENHANCED cascading
function generateBills() {
    // First check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot generate bills.');
        return false;
    }

    // Then STRICTLY validate basic quantities including date availability
    if (!validateAllQuantities()) {
        return false;
    }

    // Then check stock availability via AJAX
    checkStockAvailabilityBeforeSubmit()
        .then(() => {
            // If validation passes, show progress modal and make AJAX call to generate_bills.php
            $('#billProgressModal').modal('show');
            $('#progressStatus').text('Sending data to server...');

            // Prepare data for AJAX call - send simple array of item_code => quantity
            const itemsData = {};
            for (const itemCode in allSessionQuantities) {
                if (allSessionQuantities[itemCode] > 0) {
                    itemsData[itemCode] = allSessionQuantities[itemCode];
                }
            }

            const postData = {
                generate_bills: true,
                start_date: '<?= $start_date ?>',
                end_date: '<?= $end_date ?>',
                mode: '<?= $mode ?>',
                comp_id: '<?= $comp_id ?>',
                items: JSON.stringify(itemsData)
            };

            // Make AJAX call to generate_bills.php
            $.ajax({
                url: 'generate_bills.php',
                type: 'POST',
                data: postData,
                dataType: 'json',
                success: function(response) {
                    $('#billProgressBar').css('width', '100%');
                    $('#overallProgressText').text('100%');
                    $('#progressStatus').text('Processing complete!');

                    setTimeout(() => {
                        $('#billProgressModal').modal('hide');

                        if (response.success) {
                            // Success - redirect to retail_sale.php
                            let message = response.message || 'Bills generated successfully!';
                            if (response.bill_count) {
                                message += `\nGenerated ${response.bill_count} bills.`;
                            }
                            
                            // ADDED: Show archive month info if applicable
                            if (response.archive_months_processed) {
                                const archiveMonths = Object.keys(response.archive_months_processed);
                                const archiveCount = archiveMonths.reduce((sum, month) => sum + response.archive_months_processed[month], 0);
                                message += `\nArchive months processed: ${archiveMonths.join(', ')} (${archiveCount} bills)`;
                            }
                            
                            // ADDED: Show ENHANCED cascading info
                            if (response.cascade_months) {
                                message += `\nCascading updates applied across ${response.cascade_months} months`;
                            }
                            
                            // ADDED: Show cash memo info
                            if (response.cash_memos_generated > 0) {
                                message += `\nCash memos generated: ${response.cash_memos_generated}`;
                            }
                            
                            window.location.href = 'retail_sale.php?success=' + encodeURIComponent(message);
                        } else {
                            // Error from server
                            alert('Error generating bills: ' + (response.message || 'Unknown error'));
                        }
                    }, 1000);
                },
                error: function(xhr, status, error) {
                    $('#billProgressModal').modal('hide');
                    console.error('AJAX Error:', xhr.responseText);
                    alert('Error connecting to server. Please check the console for details.');
                }
            });
        })
        .catch((error) => {
            // Validation failed, don't submit
            console.log('Client-side validation failed:', error);
        });
}

// Function to save to pending sales via AJAX
function saveToPendingSales() {
    // First check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Cannot save to pending.');
        return false;
    }
    
    // Then STRICTLY validate basic quantities
    if (!validateAllQuantities()) {
        return false;
    }
    
    // Then check stock availability via AJAX
    checkStockAvailabilityBeforeSubmit()
        .then(() => {
            // Show loader and disable button
            $('#ajaxLoader').show();
            $('#generateBillsBtn').prop('disabled', true).addClass('btn-loading');
            
            // Collect all the data
            const formData = new FormData();
            formData.append('save_pending', 'true');
            formData.append('start_date', '<?= $start_date ?>');
            formData.append('end_date', '<?= $end_date ?>');
            formData.append('mode', '<?= $mode ?>');
            
            // Add each item's quantity from session (not just visible ones)
            for (const itemCode in allSessionQuantities) {
                const qty = allSessionQuantities[itemCode];
                if (qty > 0) {
                    formData.append(`sale_qty[${itemCode}]`, qty);
                }
            }
            
            // Send AJAX request
            $.ajax({
                url: 'save_pending_sales.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    $('#ajaxLoader').hide();
                    $('#generateBillsBtn').prop('disabled', false).removeClass('btn-loading');
                    
                    try {
                        const result = JSON.parse(response);
                        if (result.success) {
                            // Clear session quantities
                            clearSessionQuantities();
                            alert('Sales data saved to pending successfully! You can generate bills later from the "Post Daily Sales" page.');
                            window.location.href = 'retail_sale.php?success=' + encodeURIComponent(result.message);
                        } else {
                            alert('Error: ' + result.message);
                        }
                    } catch (e) {
                        alert('Error processing response: ' + response);
                    }
                },
                error: function() {
                    $('#ajaxLoader').hide();
                    $('#generateBillsBtn').prop('disabled', false).removeClass('btn-loading');
                    alert('Error saving data to pending. Please try again.');
                }
            });
        })
        .catch((error) => {
            // Validation failed, don't proceed
            console.log('Client-side validation failed for pending sales:', error);
        });
}

// Single button with dual functionality
function handleGenerateBills() {
    // First check if table exists
    if (!tableExists) {
        alert('Daily stock table not available. Please select a different date range.');
        return false;
    }
    
    // Check if we have any quantities > 0 (optimized check)
    let hasQuantity = false;
    for (const itemCode in allSessionQuantities) {
        if (allSessionQuantities[itemCode] > 0) {
            hasQuantity = true;
            break;
        }
    }
    
    if (!hasQuantity) {
        alert('Please enter quantities for at least one item.');
        return false;
    }
    
    // STRICT CHECK: Remove items with no available dates from processing
    let skippedItems = [];
    for (const itemCode in allSessionQuantities) {
        const qty = allSessionQuantities[itemCode];
        if (qty > 0) {
            const availability = itemDateAvailability[itemCode];
            if (!availability || availability.available_count === 0) {
                const itemName = allItemsData[itemCode]?.DETAILS || itemCode;
                skippedItems.push(`${itemName} (${itemCode})`);
                // Remove this item from session quantities to exclude it from processing
                delete allSessionQuantities[itemCode];
            }
        }
    }

    // Show warning for skipped items
    if (skippedItems.length > 0) {
        alert(`The following items have no available dates and will be skipped:\n\n${skippedItems.join('\n')}`);
    }
    
    // Show confirmation dialog with two options
    const userChoice = confirm(
        "Generate Bills Options:\n\n" +
        "Click OK to generate bills immediately (will update stock and create actual sales).\n\n" +
        "Click Cancel to save to pending sales (will save for later processing, no stock update)."
    );
    
    if (userChoice === true) {
        // User clicked OK - Generate bills immediately
        generateBills();
    } else {
        // User clicked Cancel - Save to pending sales
        saveToPendingSales();
    }
}

// Function to load sales log content
function loadSalesLog() {
    // Show loading state
    $('#salesLogContent').html(`
        <div class="text-center py-3">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Loading sales log...</p>
        </div>
    `);
    
    // Load sales log content via AJAX
    $.ajax({
        url: 'sales_log_ajax.php',
        type: 'GET',
        dataType: 'html',
        success: function(response) {
            $('#salesLogContent').html(response);
        },
        error: function() {
            $('#salesLogContent').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i>
                    Failed to load sales log. Please try again.
                </div>
            `);
        }
    });
}

// Function to print sales log
function printSalesLog() {
    const printContent = $('#salesLogContent').html();
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Sales Log - Foreign Export</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f8f9fa; }
                .text-center { text-align: center; }
                .no-print { display: none; }
            </style>
        </head>
        <body>
            <h2 class="text-center">Sales Log - Foreign Export</h2>
            ${printContent}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 250);
}

// Function to get item data from ALL items data (not just visible rows)
function getItemData(itemCode) {
    // First try to get from visible row
    const inputField = $(`input[name="sale_qty[${itemCode}]"]`);
    if (inputField.length > 0) {
        const itemRow = inputField.closest('tr');
        return {
            classCode: itemRow.data('class'),
            details: itemRow.data('details'),
            details2: itemRow.data('details2'),
            quantity: parseInt(inputField.val()) || 0
        };
    } else {
        // If not in current view, get from allItemsData (now includes all modes)
        if (allItemsData[itemCode]) {
            return {
                classCode: allItemsData[itemCode].CLASS,
                details: allItemsData[itemCode].DETAILS,
                details2: allItemsData[itemCode].DETAILS2,
                quantity: allSessionQuantities[itemCode] || 0
            };
        }
    }
    return null;
}

// UPDATED: Function to classify product type from class code - ADDED COUNTRY LIQUOR
function getProductType(classCode) {
    const spirits = ['W', 'G', 'D', 'K', 'R', 'O'];
    if (spirits.includes(classCode)) return 'SPIRITS';
    if (classCode === 'V') return 'WINE';
    if (classCode === 'F') return 'FERMENTED BEER';
    if (classCode === 'M') return 'MILD BEER';
    if (classCode === 'L') return 'COUNTRY LIQUOR'; // ADDED COUNTRY LIQUOR
    return 'OTHER';
}

// Function to extract volume from details
function extractVolume(details, details2) {
    // Priority: details2 column first
    if (details2) {
        // Handle liter sizes with decimal points (1.5L, 2.0L, etc.)
        const literMatch = details2.match(/(\d+\.?\d*)\s*L\b/i);
        if (literMatch) {
            let volume = parseFloat(literMatch[1]);
            return Math.round(volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        const mlMatch = details2.match(/(\d+)\s*ML\b/i);
        if (mlMatch) {
            return parseInt(mlMatch[1]);
        }
    }
    
    // Fallback: parse details column
    if (details) {
        // Handle special cases
        if (details.includes('QUART')) return 750;
        if (details.includes('PINT')) return 375;
        if (details.includes('NIP')) return 90;
        
        // Handle liter sizes with decimal points
        const literMatch = details.match(/(\d+\.?\d*)\s*L\b/i);
        if (literMatch) {
            let volume = parseFloat(literMatch[1]);
            return Math.round(volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        const mlMatch = details.match(/(\d+)\s*ML\b/i);
        if (mlMatch) {
            return parseInt(mlMatch[1]);
        }
    }
    
    return 0; // Unknown volume
}

// Function to map volume to column - UPDATED WITH ALL SIZES
function getVolumeColumn(volume) {
    const volumeMap = {
        // ML sizes
        50: '50 ML',
        60: '60 ML', 
        90: '90 ML',
        170: '170 ML',
        180: '180 ML',
        200: '200 ML',
        250: '250 ML',
        275: '275 ML',
        330: '330 ML',
        355: '355 ML',
        375: '375 ML',
        500: '500 ML',
        650: '650 ML',
        700: '700 ML',
        750: '750 ML',
        1000: '1000 ML',
        
        // Liter sizes (converted to ML for consistency)
        1500: '1.5L',    // 1.5L = 1500ML
        1750: '1.75L',   // 1.75L = 1750ML
        2000: '2L',      // 2L = 2000ML
        3000: '3L',      // 3L = 3000ML
        4500: '4.5L',    // 4.5L = 4500ML
        15000: '15L',    // 15L = 15000ML
        20000: '20L',    // 20L = 20000ML
        30000: '30L',    // 30L = 30000ML
        50000: '50L'     // 50L = 50000ML
    };
    
    return volumeMap[volume] || null;
}

// UPDATED: Function to update total sales module - PROCESS ALL ITEMS FROM ALL MODES
function updateTotalSalesModule() {
    console.log('updateTotalSalesModule called - Processing ALL items from ALL modes');
    
    // Check if table exists
    if (!tableExists) {
        console.log('Table does not exist, skipping total sales update');
        return;
    }
    
    // Initialize empty summary object with ALL sizes
    const allSizes = [
        '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML', 
        '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
        '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
    ];
    
    // UPDATED: Added COUNTRY LIQUOR category in the requested order
    const salesSummary = {
        'SPIRITS': {},
        'WINE': {},
        'FERMENTED BEER': {},
        'MILD BEER': {},
        'COUNTRY LIQUOR': {} // ADDED COUNTRY LIQUOR AT THE END
    };
    
    // Initialize all sizes to 0 for each category
    Object.keys(salesSummary).forEach(category => {
        allSizes.forEach(size => {
            salesSummary[category][size] = 0;
        });
    });

    console.log('Processing ALL session quantities from ALL modes:', allSessionQuantities);

    // Process ALL session quantities > 0 (from ALL modes)
    let processedItems = 0;
    for (const itemCode in allSessionQuantities) {
        const quantity = allSessionQuantities[itemCode];
        if (quantity > 0) {
            // Get item data from ALL items data (works for items from all modes)
            const itemData = getItemData(itemCode);
            if (itemData) {
                const productType = getProductType(itemData.classCode);
                const volume = extractVolume(itemData.details, itemData.details2);
                const volumeColumn = getVolumeColumn(volume);
                
                console.log(`Item ${itemCode}: Class=${itemData.classCode}, ProductType=${productType}, Volume=${volume}, VolumeColumn=${volumeColumn}, Quantity=${quantity}`);
                
                if (volumeColumn && salesSummary[productType]) {
                    salesSummary[productType][volumeColumn] += quantity;
                    processedItems++;
                }
            }
        }
    }

    console.log(`Processed ${processedItems} items with quantities from ALL modes`);
    console.log('Final sales summary:', salesSummary);

    // Update the modal table
    updateSalesModalTable(salesSummary, allSizes);
}

// UPDATED: Function to update modal table with calculated values - ADDED COUNTRY LIQUOR ROW
function updateSalesModalTable(salesSummary, allSizes) {
    const tbody = $('#totalSalesTable tbody');
    tbody.empty();
    
    console.log('Updating modal table with categories:', Object.keys(salesSummary));
    
    // UPDATED: Added COUNTRY LIQUOR in the requested order
    const categories = ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER', 'COUNTRY LIQUOR'];
    
    categories.forEach(category => {
        const row = $('<tr>');
        row.append($('<td>').text(category));
        
        allSizes.forEach(size => {
            const value = salesSummary[category] ? (salesSummary[category][size] || 0) : 0;
            const cell = $('<td>').text(value > 0 ? value : '');
            
            // Add subtle highlighting for non-zero values
            if (value > 0) {
                cell.addClass('table-success');
            }
            
            row.append(cell);
        });
        
        tbody.append(row);
    });
    
    console.log('Modal table updated successfully with data from ALL modes');
}

// Print function
function printSalesSummary() {
    const printContent = document.getElementById('totalSalesModuleContainer').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = printContent;
    window.print();
    document.body.innerHTML = originalContent;
    
    // Re-initialize any necessary scripts
    location.reload();
}

// OPTIMIZED: Document ready - Only process items with quantities > 0
$(document).ready(function() {
    console.log('Document ready - Initializing with STRICT validation...');
    console.log('Date availability data:', itemDateAvailability);
    
    // Check if table exists
    if (!tableExists) {
        console.log('Daily stock table not found, disabling functionality');
        return;
    }
    
    // Initialize table headers and columns
    initializeTableHeaders();
    
    // Set up row navigation with arrow keys
    setupRowNavigation();
    
    // Initialize quantities in visible inputs from session
    initializeQuantitiesFromSession();
    
    // Clear session button click event
    $('#clearSessionBtn').click(function() {
        if (confirm('Are you sure you want to clear all quantities? This action cannot be undone.')) {
            clearSessionQuantities();
        }
    });
    
    // Single button with dual functionality
    $('#generateBillsBtn').click(function(e) {
        e.preventDefault();
        handleGenerateBills();
    });
    
    // OPTIMIZED: Event delegation with debouncing
    let quantityTimeout;
    $(document).off('input', 'input.qty-input').on('input', 'input.qty-input', function(e) {
        clearTimeout(quantityTimeout);
        quantityTimeout = setTimeout(() => {
            validateQuantity(this);
        }, 200);
    });
    
    // Quantity input change event
    $(document).on('change', 'input[name^="sale_qty"]', function() {
        // First STRICTLY validate the quantity
        if (!validateQuantity(this)) {
            return; // Stop if validation fails
        }
        
        const itemCode = $(this).data('code');
        const totalQty = parseInt($(this).val()) || 0;
        
        // Only update distribution if quantity > 0 (optimization)
        if (totalQty > 0) {
            updateDistributionPreview(itemCode, totalQty);
        } else {
            // Remove distribution cells if quantity is 0
            $(`input[name="sale_qty[${itemCode}]"]`).closest('tr').find('.date-distribution-cell').remove();
            
            // Reset closing balance and amount
            const currentStock = parseFloat($(this).data('stock'));
            $(`#closing_${itemCode}`).text(currentStock.toFixed(3));
            $(`#amount_${itemCode}`).text('0.00');
            
            // Hide date columns if no items have quantity
            if ($('input[name^="sale_qty"]').filter(function() { 
                return parseInt($(this).val()) > 0; 
            }).length === 0) {
                $('.date-header, .date-distribution-cell').hide();
            }
        }

        // Also update total sales module if modal is open
        if ($('#totalSalesModal').hasClass('show')) {
            console.log('Modal is open, updating total sales module with ALL modes data...');
            updateTotalSalesModule();
        }
        
        // Update total amount
        calculateTotalAmount();
    });
    
    // OPTIMIZED: Shuffle all button click event - Only shuffle items with qty > 0
    $('#shuffleBtn').off('click').on('click', function() {
        $('input.qty-input').each(function() {
            const itemCode = $(this).data('code');
            const totalQty = parseInt($(this).val()) || 0;
            
            // Only shuffle if quantity > 0 and visible (optimization)
            if (totalQty > 0 && $(this).is(':visible')) {
                updateDistributionPreview(itemCode, totalQty);
            }
        });
        
        // Update total amount
        calculateTotalAmount();
    });
    
    // Individual shuffle button click event
    $(document).on('click', '.btn-shuffle-item', function() {
        const itemCode = $(this).data('code');
        const totalQty = parseInt($(`input[name="sale_qty[${itemCode}]"]`).val()) || 0;
        
        // Only shuffle if quantity > 0
        if (totalQty > 0) {
            updateDistributionPreview(itemCode, totalQty);
            
            // Update total amount
            calculateTotalAmount();
        }
    });
    
    // Auto-load sales log when modal is shown
    $('#salesLogModal').on('shown.bs.modal', function() {
        loadSalesLog();
    });
    
    // Update total sales module when modal is shown - FIXED: Use 'show.bs.modal' instead of 'shown.bs.modal'
    $('#totalSalesModal').on('show.bs.modal', function() {
        console.log('Total Sales Modal opened - updating data from ALL modes...');
        updateTotalSalesModule();
    });
    
    // Also update when modal is already shown but data changes
    $('#totalSalesModal').on('shown.bs.modal', function() {
        console.log('Total Sales Modal shown - refreshing data from ALL modes...');
        updateTotalSalesModule();
    });
});

// NEW FUNCTION: Initialize input values from session on page load
function initializeQuantitiesFromSession() {
    $('input[name^="sale_qty"]').each(function() {
        const itemCode = $(this).data('code');
        if (allSessionQuantities[itemCode] !== undefined) {
            const sessionQty = allSessionQuantities[itemCode];
            $(this).val(sessionQty);
            
            // Update UI for this item
            const currentStock = parseFloat($(this).data('stock'));
            updateItemUI(itemCode, sessionQty, currentStock);
            
            // Show distribution if quantity > 0 (optimization)
            if (sessionQty > 0) {
                updateDistributionPreview(itemCode, sessionQty);
            }
        }
    });
    
    // Show date headers if any items have quantities > 0
    const hasQuantities = Object.values(allSessionQuantities).some(qty => qty > 0);
    if (hasQuantities) {
        $('.date-header').show();
    }
}
</script>
</body>
</html>
<?php
// Close database connection
$conn->close();
debugCascadingLog("Database connection closed", null, 'INFO');
?>