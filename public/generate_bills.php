<?php
// generate_bills.php - HYPER-OPTIMIZED VERSION WITH BULK CASH MEMO
session_start();
require_once 'drydays_functions.php'; // Single include
require_once 'license_functions.php'; // ADDED: Include license 
require_once 'cash_memo_functions.php'; // ADDED: Include cash memo functions
include_once "../config/db.php";
include_once "volume_limit_utils.php";

// Logging function
function logMessage($message, $level = 'INFO') {
    $logFile = '../logs/sales_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Create logs directory if it doesn't exist
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// ============================================================================
// ENHANCED CHRONOLOGICAL INTEGRITY CHECK: GLOBAL BLOCKING
// ============================================================================

/**
 * Check if ANY sales exist for ANY item within or after the given date range
 * Returns array with allowed dates (after latest global sale)
 */
function checkGlobalBackdatedSales($conn, $start_date, $end_date, $comp_id) {
    // Query to get all sales in or after the date range for ANY item
    $query = "SELECT DISTINCT sh.BILL_DATE
              FROM tblsaleheader sh
              WHERE sh.BILL_DATE >= ? 
              AND sh.COMP_ID = ?
              ORDER BY sh.BILL_DATE ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $start_date, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $existing_dates = [];
    while ($row = $result->fetch_assoc()) {
        $existing_dates[] = $row['BILL_DATE'];
    }
    $stmt->close();
    
    // Create date range array
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day'); // Include end date
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($begin, $interval, $end);
    
    $all_dates = [];
    foreach ($date_range as $date) {
        $all_dates[] = $date->format("Y-m-d");
    }
    
    if (!empty($existing_dates)) {
        // Find the latest existing sale date
        $latest_existing = max($existing_dates);
        $latest_existing_date = new DateTime($latest_existing);
        
        // Determine which dates are available (after latest sale date)
        $available_dates = [];
        $unavailable_dates = [];
        
        foreach ($all_dates as $date) {
            $current_date = new DateTime($date);
            if ($current_date > $latest_existing_date) {
                $available_dates[] = $date;
            } else {
                $unavailable_dates[] = $date;
            }
        }
        
        logMessage("GLOBAL CHECK: Latest existing sale: $latest_existing", 'INFO');
        logMessage("Available dates: " . implode(', ', $available_dates), 'INFO');
        logMessage("Unavailable dates (has existing sales): " . implode(', ', $unavailable_dates), 'INFO');
        
        return [
            'restricted' => !empty($unavailable_dates), // Restricted if ANY dates are unavailable
            'latest_existing_sale' => $latest_existing,
            'available_dates' => $available_dates,
            'unavailable_dates' => $unavailable_dates,
            'all_existing_dates' => $existing_dates,
            'message' => !empty($unavailable_dates) ? 
                "Global sales exist on: " . implode(', ', $unavailable_dates) . ". Available dates: " . implode(', ', $available_dates) :
                "No sales restrictions"
        ];
    }
    
    return [
        'restricted' => false,
        'latest_existing_sale' => null,
        'available_dates' => $all_dates, // All dates available if no existing sales
        'unavailable_dates' => [],
        'all_existing_dates' => [],
        'message' => "No global sales restrictions"
    ];
}

// ============================================================================
// DRY DAY VALIDATION
// ============================================================================

/**
 * Check if any dry days fall within the date range
 */
function checkDryDaysInRange($conn, $start_date, $end_date) {
    $dryDaysManager = new DryDaysManager($conn);
    $dry_days = $dryDaysManager->getDryDaysInRange($start_date, $end_date);
    
    if (!empty($dry_days)) {
        logMessage("DRY DAYS FOUND: " . implode(', ', array_keys($dry_days)), 'INFO');
    }
    
    return [
        'has_dry_days' => !empty($dry_days),
        'dry_days' => $dry_days,
        'dry_dates' => array_keys($dry_days),
        'message' => !empty($dry_days) ? 
            "Dry days found: " . implode(', ', array_keys($dry_days)) : 
            "No dry days in selected range"
    ];
}

/**
 * Validate both global sales and dry days restrictions
 */
function validateDateRangeRestrictions($conn, $start_date, $end_date, $comp_id) {
    // Check global sales restrictions
    $global_check = checkGlobalBackdatedSales($conn, $start_date, $end_date, $comp_id);
    
    // Check dry days
    $dry_days_check = checkDryDaysInRange($conn, $start_date, $end_date);
    
    // Combine restrictions - a date is unavailable if it has sales OR is a dry day
    $all_unavailable_dates = array_merge(
        $global_check['unavailable_dates'],
        $dry_days_check['dry_dates']
    );
    
    // Remove duplicates
    $all_unavailable_dates = array_unique($all_unavailable_dates);
    sort($all_unavailable_dates);
    
    // Calculate available dates (all dates minus unavailable)
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($begin, $interval, $end);
    
    $all_dates = [];
    foreach ($date_range as $date) {
        $all_dates[] = $date->format("Y-m-d");
    }
    
    $available_dates = array_diff($all_dates, $all_unavailable_dates);
    $available_dates = array_values($available_dates); // Re-index
    
    // Prepare messages
    $messages = [];
    if ($global_check['restricted']) {
        $messages[] = "Existing sales on: " . implode(', ', $global_check['unavailable_dates']);
    }
    if ($dry_days_check['has_dry_days']) {
        $messages[] = "Dry days: " . implode(', ', $dry_days_check['dry_dates']);
    }
    
    return [
        'restricted' => !empty($all_unavailable_dates),
        'global_restricted' => $global_check['restricted'],
        'has_dry_days' => $dry_days_check['has_dry_days'],
        'latest_existing_sale' => $global_check['latest_existing_sale'],
        'available_dates' => $available_dates,
        'unavailable_dates' => $all_unavailable_dates,
        'unavailable_sales_dates' => $global_check['unavailable_dates'],
        'dry_dates' => $dry_days_check['dry_dates'],
        'dry_days_info' => $dry_days_check['dry_days'],
        'message' => !empty($messages) ? implode(' | ', $messages) : "No restrictions",
        'full_message' => !empty($messages) ? 
            "<strong>Date Range Restrictions:</strong><br>" . implode('<br>', $messages) . 
            "<br><strong>Available dates:</strong> " . (empty($available_dates) ? 'None' : implode(', ', $available_dates)) :
            "No date range restrictions"
    ];
}

/**
 * NEW: Get unavailable dates due to global sales and dry days
 */
function getUnavailableDates($conn, $start_date, $end_date, $comp_id) {
    $restrictions = validateDateRangeRestrictions($conn, $start_date, $end_date, $comp_id);
    return $restrictions['unavailable_dates'];
}

// ============================================================================
// ENHANCED DISTRIBUTION LOGIC WITH GLOBAL BLOCKING AND DRY DAYS
// ============================================================================

/**
 * Enhanced distribution function that handles global restrictions
 * Distributes only across available dates (after latest global sale, excluding dry days)
 */
function distributeSalesWithGlobalRestrictions($total_qty, $available_dates) {
    if ($total_qty <= 0 || empty($available_dates)) return [];
    
    $available_days_count = count($available_dates);
    
    // Distribute across available dates
    $base_qty = floor($total_qty / $available_days_count);
    $remainder = $total_qty % $available_days_count;
    
    $distribution = array_fill(0, $available_days_count, $base_qty);
    
    // Distribute remainder evenly
    for ($i = 0; $i < $remainder; $i++) {
        $distribution[$i]++;
    }
    
    // Shuffle the distribution to make it look more natural
    shuffle($distribution);
    
    return $distribution;
}

/**
 * Get final distribution array for all dates (with zeros for unavailable dates)
 */
function getFullDistribution($total_qty, $date_array, $available_dates) {
    $full_distribution = array_fill(0, count($date_array), 0);
    
    if ($total_qty <= 0 || empty($available_dates)) {
        return $full_distribution;
    }
    
    // Create date index map
    $date_index_map = [];
    foreach ($date_array as $index => $date) {
        $date_index_map[$date] = $index;
    }
    
    // Get distribution for available dates
    $distribution = distributeSalesWithGlobalRestrictions($total_qty, $available_dates);
    
    // Fill in the distribution
    foreach ($available_dates as $i => $date) {
        $index = $date_index_map[$date] ?? null;
        if ($index !== null) {
            $full_distribution[$index] = $distribution[$i] ?? 0;
        }
    }
    
    return $full_distribution;
}

// Function to get the correct daily stock table for a specific date with validation
function getDailyStockTableForDate($conn, $comp_id, $date) {
    $current_date = new DateTime();
    $sale_date = new DateTime($date);
    
    // If sale date is in the future, use current month table
    if ($sale_date > $current_date) {
        logMessage("Sale date $date is in future, using current month table", 'WARNING');
        return "tbldailystock_" . $comp_id;
    }
    
    $current_month = $current_date->format('Y-m'); // Current month in "YYYY-MM" format
    $date_month = $sale_date->format('Y-m'); // Date month in "YYYY-MM" format
    
    if ($date_month === $current_month) {
        // Use current month table (no suffix)
        return "tbldailystock_" . $comp_id;
    } else {
        // Use archived month table (with suffix mm_yy)
        $date_month_short = $sale_date->format('m'); // e.g., "12"
        $date_year_short = $sale_date->format('y'); // e.g., "25"
        return "tbldailystock_" . $comp_id . "_" . $date_month_short . "_" . $date_year_short;
    }
}

// Helper function to create daily stock table
function createDailyStockTable($conn, $table_name) {
    $create_query = "CREATE TABLE IF NOT EXISTS $table_name (
        ID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ITEM_CODE VARCHAR(50) NOT NULL,
        STK_MONTH VARCHAR(7) NOT NULL,
        DAY_01_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_01_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_02_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_03_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_04_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_05_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_06_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_07_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_08_SALES DECimal(10,3) DEFAULT 0.000,
        DAY_08_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_09_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_10_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_11_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_12_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_13_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_14_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_15_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_16_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_17_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_PURCHASE DECimal(10,3) DEFAULT 0.000,
        DAY_18_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_18_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_19_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_20_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_21_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_22_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_23_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_24_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_25_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_26_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_27_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_28_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_PURCHASE DECimal(10,3) DEFAULT 0.000,
        DAY_29_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_29_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_30_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_OPEN DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_PURCHASE DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_SALES DECIMAL(10,3) DEFAULT 0.000,
        DAY_31_CLOSING DECIMAL(10,3) DEFAULT 0.000,
        LAST_UPDATED TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_item_month (ITEM_CODE, STK_MONTH),
        KEY idx_item_code (ITEM_CODE),
        KEY idx_stk_month (STK_MONTH)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    if ($conn->query($create_query)) {
        logMessage("Created daily stock table: $table_name", 'INFO');
        return true;
    } else {
        logMessage("Failed to create daily stock table: " . $conn->error, 'ERROR');
        return false;
    }
}

// ENHANCED: Function to recalculate daily stock from a specific day onward with proper rollover
function recalculateDailyStockFromDay($conn, $table_name, $item_code, $stk_month, $start_day = 1) {
    logMessage("Recalculating stock from day $start_day for item $item_code in $stk_month in table $table_name", 'INFO');
    
    // Get the current date to know if we're dealing with current or future month
    $current_date = new DateTime();
    $table_month = new DateTime($stk_month . '-01');
    
    // Get last day of this month
    $last_day_of_month = date('t', strtotime($stk_month . '-01'));
    
    // Start from the specified day and recalculate forward
    for ($day = $start_day; $day <= 31; $day++) {
        $day_num = sprintf('%02d', $day);
        $opening_column = "DAY_{$day_num}_OPEN";
        $purchase_column = "DAY_{$day_num}_PURCHASE";
        $sales_column = "DAY_{$day_num}_SALES";
        $closing_column = "DAY_{$day_num}_CLOSING";
        
        // Check if day columns exist
        $check_columns = "SHOW COLUMNS FROM $table_name LIKE '$opening_column'";
        $column_result = $conn->query($check_columns);
        
        if ($column_result->num_rows == 0) {
            continue; // Day doesn't exist in table
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
            
            // Calculate closing using the formula: Closing = Opening + Purchase - Sales
            $closing = $opening + $purchase - $sales;
            
            // Update closing
            $update_query = "UPDATE $table_name 
                            SET $closing_column = ?,
                                LAST_UPDATED = CURRENT_TIMESTAMP 
                            WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("dss", $closing, $item_code, $stk_month);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Set next day's opening to this day's closing (but only within same month)
            $next_day = $day + 1;
            if ($next_day <= $last_day_of_month && $next_day <= 31) {
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
                }
            }
            
            logMessage("Day $day: Opening=$opening, Purchase=$purchase, Sales=$sales, Closing=$closing", 'DEBUG');
        }
        $day_stmt->close();
        
        // Break if we've processed all days of the month
        if ($day >= $last_day_of_month) {
            break;
        }
    }
    
    // Handle month-to-month rollover
    if ($start_day == 1) {
        // If we're recalculating from day 1, we need to ensure consistency with previous month
        $prev_month = date('Y-m', strtotime($stk_month . '-01 -1 month'));
        if ($prev_month) {
            $prev_table = getDailyStockTableForDate($conn, $_SESSION['CompID'], $prev_month . '-01');
            
            // Check if previous month table exists
            $check_prev_table = "SHOW TABLES LIKE '$prev_table'";
            if ($conn->query($check_prev_table)->num_rows > 0) {
                // Get last day of previous month
                $prev_last_day = date('d', strtotime('last day of ' . $prev_month));
                $prev_closing_column = "DAY_" . sprintf('%02d', $prev_last_day) . "_CLOSING";
                
                // Get previous month's closing
                $prev_query = "SELECT $prev_closing_column FROM $prev_table 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prev_stmt = $conn->prepare($prev_query);
                $prev_stmt->bind_param("ss", $prev_month, $item_code);
                if ($prev_stmt->execute()) {
                    $prev_result = $prev_stmt->get_result();
                    if ($prev_result->num_rows > 0) {
                        $prev_row = $prev_result->fetch_assoc();
                        $prev_closing = $prev_row[$prev_closing_column] ?? 0;
                        
                        // Update current month's day 1 opening to match previous month's closing
                        $update_opening_query = "UPDATE $table_name 
                                                SET DAY_01_OPEN = ?,
                                                    LAST_UPDATED = CURRENT_TIMESTAMP 
                                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                        $update_opening_stmt = $conn->prepare($update_opening_query);
                        $update_opening_stmt->bind_param("dss", $prev_closing, $stk_month, $item_code);
                        $update_opening_stmt->execute();
                        $update_opening_stmt->close();
                        
                        logMessage("Updated DAY_01_OPEN to $prev_closing based on previous month's closing", 'INFO');
                    }
                }
                $prev_stmt->close();
            }
        }
    }
    
    logMessage("Completed recalculating stock for $stk_month from day $start_day", 'INFO');
}

// ENHANCED: Function to update daily stock table with CASCADE TO FINANCIAL YEAR END
function updateDailyStock($conn, $item_code, $sale_date, $qty, $comp_id) {
    logMessage("Starting daily stock update for item $item_code sold on $sale_date (Qty: $qty)", 'INFO');
    
    // Get financial year end from session
    $fin_year_end = $_SESSION['FIN_YEAR_END']; // Format: YYYY-MM-DD (e.g., 2022-03-31)
    $fin_year_end_obj = new DateTime($fin_year_end);
    
    // Get the correct table for the sale date
    $sale_daily_stock_table = getDailyStockTableForDate($conn, $comp_id, $sale_date);
    
    // Extract day number from date (e.g., 2025-09-27 → day 27)
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $sales_column = "DAY_{$day_num}_SALES";
    $closing_column = "DAY_{$day_num}_CLOSING";
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    
    $month_year_full = date('Y-m', strtotime($sale_date)); // e.g., "2025-09"
    $sale_date_obj = new DateTime($sale_date);
    $current_date = new DateTime();
    $current_month = $current_date->format('Y-m');
    
    // ============================================================================
    // STEP 1: UPDATE THE STOCK TABLE FOR THE SALE DATE
    // ============================================================================
    
    // First, check if the required table exists
    $check_table_query = "SHOW TABLES LIKE '$sale_daily_stock_table'";
    $table_result = $conn->query($check_table_query);
    
    if ($table_result->num_rows == 0) {
        // Table doesn't exist, create it
        createDailyStockTable($conn, $sale_daily_stock_table);
    }
    
    // Check if record exists for this month and item
    $check_query = "SELECT $closing_column, $opening_column, $purchase_column, $sales_column 
                    FROM $sale_daily_stock_table 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $month_year_full, $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows == 0) {
        // Record doesn't exist, create it with initial values
        $check_stmt->close();
        
        // Get previous month's closing if available
        $prev_month = date('Y-m', strtotime($month_year_full . '-01 -1 month'));
        $prev_table = getDailyStockTableForDate($conn, $comp_id, $prev_month . '-01');
        
        $prev_closing = 0;
        if ($prev_month) {
            // Get last day of previous month
            $prev_last_day = date('d', strtotime('last day of ' . $prev_month));
            $prev_closing_column = "DAY_" . sprintf('%02d', $prev_last_day) . "_CLOSING";
            
            $prev_query = "SELECT $prev_closing_column FROM $prev_table 
                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $prev_stmt = $conn->prepare($prev_query);
            $prev_stmt->bind_param("ss", $prev_month, $item_code);
            if ($prev_stmt->execute()) {
                $prev_result = $prev_stmt->get_result();
                if ($prev_result->num_rows > 0) {
                    $prev_row = $prev_result->fetch_assoc();
                    $prev_closing = $prev_row[$prev_closing_column] ?? 0;
                }
            }
            $prev_stmt->close();
        }
        
        // Insert new record
        $insert_query = "INSERT INTO $sale_daily_stock_table 
                        (ITEM_CODE, STK_MONTH, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                        VALUES (?, ?, ?, 0, 0, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssdd", $item_code, $month_year_full, $prev_closing, $prev_closing);
        $insert_stmt->execute();
        $insert_stmt->close();
        
        // Now get the record
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ss", $month_year_full, $item_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
    }
    
    $current_values = $check_result->fetch_assoc();
    $check_stmt->close();
    
    $current_closing = $current_values[$closing_column] ?? 0;
    $current_opening = $current_values[$opening_column] ?? 0;
    $current_purchase = $current_values[$purchase_column] ?? 0;
    $current_sales = $current_values[$sales_column] ?? 0;
    
    // Validate closing stock is sufficient for the sale quantity
    if ($current_closing < $qty) {
        // Try to get stock from another source or calculate from opening + purchase
        $available_stock = $current_opening + $current_purchase - $current_sales;
        if ($available_stock < $qty) {
            throw new Exception("Insufficient closing stock for item $item_code on $sale_date. Available: $available_stock, Requested: $qty");
        }
        // If we got here, use the calculated available stock
        $current_closing = $available_stock;
    }
    
    // Calculate new sales and closing
    $new_sales = $current_sales + $qty;
    $new_closing = $current_opening + $current_purchase - $new_sales;
    
    // Update existing record with correct closing calculation
    $update_query = "UPDATE $sale_daily_stock_table 
                     SET $sales_column = ?, 
                         $closing_column = ?,
                         LAST_UPDATED = CURRENT_TIMESTAMP 
                     WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("ddss", $new_sales, $new_closing, $month_year_full, $item_code);
    $update_stmt->execute();
    
    if ($update_stmt->affected_rows === 0) {
        $update_stmt->close();
        throw new Exception("Failed to update daily stock for item $item_code on $sale_date in table $sale_daily_stock_table");
    }
    $update_stmt->close();
    
    // ============================================================================
    // STEP 2: CASCADE UPDATES TO SUBSEQUENT DAYS IN THE SAME MONTH
    // ============================================================================
    
    recalculateDailyStockFromDay($conn, $sale_daily_stock_table, $item_code, $month_year_full, $day_num);
    
    // ============================================================================
    // STEP 3: CASCADE TO ALL SUBSEQUENT MONTHS UNTIL FINANCIAL YEAR END
    // ============================================================================
    
    logMessage("Starting cascading to all months until financial year end $fin_year_end for item $item_code sold on $sale_date", 'INFO');
    
    // Create a month iterator starting from the sale month
    $current_month_obj = new DateTime($month_year_full . '-01');
    
    while (true) {
        // Move to next month
        $current_month_obj->modify('+1 month');
        $next_month = $current_month_obj->format('Y-m');
        $next_month_first_day = $current_month_obj->format('Y-m-01');
        
        // Get the last day of next month
        $next_month_last_day_obj = clone $current_month_obj;
        $next_month_last_day_obj->modify('last day of this month');
        $next_month_last_day = $next_month_last_day_obj->format('Y-m-d');
        
        // Check if we've reached or passed the financial year end
        if ($next_month_last_day_obj > $fin_year_end_obj) {
            // This month extends beyond financial year end
            // We need to process only up to the financial year end date
            
            logMessage("Reached month $next_month which extends beyond financial year end $fin_year_end", 'INFO');
            
            // Get the table for this month
            $next_month_table = getDailyStockTableForDate($conn, $comp_id, $next_month_first_day);
            
            // Check if table exists
            $check_table = "SHOW TABLES LIKE '$next_month_table'";
            if ($conn->query($check_table)->num_rows == 0) {
                createDailyStockTable($conn, $next_month_table);
                logMessage("Created table $next_month_table for final month cascade", 'INFO');
            }
            
            // Get previous month's closing (which is the closing from the month we just processed)
            $prev_month = date('Y-m', strtotime($next_month . '-01 -1 month'));
            $prev_table = getDailyStockTableForDate($conn, $comp_id, $prev_month . '-01');
            $prev_last_day = date('d', strtotime('last day of ' . $prev_month));
            $prev_closing_column = "DAY_" . sprintf('%02d', $prev_last_day) . "_CLOSING";
            
            // Get previous month's closing
            $prev_closing = 0;
            $prev_query = "SELECT $prev_closing_column FROM $prev_table 
                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $prev_stmt = $conn->prepare($prev_query);
            $prev_stmt->bind_param("ss", $prev_month, $item_code);
            if ($prev_stmt->execute()) {
                $prev_result = $prev_stmt->get_result();
                if ($prev_result->num_rows > 0) {
                    $prev_row = $prev_result->fetch_assoc();
                    $prev_closing = $prev_row[$prev_closing_column] ?? 0;
                }
            }
            $prev_stmt->close();
            
            // Update or create record in this month
            $check_record = "SELECT DAY_01_OPEN FROM $next_month_table 
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $check_stmt = $conn->prepare($check_record);
            $check_stmt->bind_param("ss", $next_month, $item_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows == 0) {
                // Create new record
                $check_stmt->close();
                $insert_query = "INSERT INTO $next_month_table 
                                (ITEM_CODE, STK_MONTH, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                                VALUES (?, ?, ?, 0, 0, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("ssdd", $item_code, $next_month, $prev_closing, $prev_closing);
                $insert_stmt->execute();
                $insert_stmt->close();
                logMessage("Inserted record for $item_code in $next_month with opening $prev_closing", 'INFO');
            } else {
                // Update existing record
                $check_stmt->close();
                $update_query = "UPDATE $next_month_table 
                               SET DAY_01_OPEN = ?,
                                   LAST_UPDATED = CURRENT_TIMESTAMP 
                               WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("dss", $prev_closing, $next_month, $item_code);
                $update_stmt->execute();
                $update_stmt->close();
                logMessage("Updated opening for $item_code in $next_month to $prev_closing", 'INFO');
            }
            
            // We only need to update up to the financial year end date, not the entire month
            // Get the day number of financial year end
            $fin_year_end_day = (int)$fin_year_end_obj->format('d');
            
            // Recalculate from day 1 up to financial year end day
            for ($day = 1; $day <= $fin_year_end_day; $day++) {
                $day_num = sprintf('%02d', $day);
                
                // Skip if we're before the sale date in the first month
                if ($next_month === $month_year_full && $day < date('d', strtotime($sale_date))) {
                    continue;
                }
                
                $opening_col = "DAY_{$day_num}_OPEN";
                $purchase_col = "DAY_{$day_num}_PURCHASE";
                $sales_col = "DAY_{$day_num}_SALES";
                $closing_col = "DAY_{$day_num}_CLOSING";
                
                // Check if columns exist
                $check_columns = "SHOW COLUMNS FROM $next_month_table LIKE '$opening_col'";
                if ($conn->query($check_columns)->num_rows == 0) {
                    continue;
                }
                
                // Get current values
                $day_query = "SELECT $opening_col, $purchase_col, $sales_col 
                             FROM $next_month_table 
                             WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                $day_stmt = $conn->prepare($day_query);
                $day_stmt->bind_param("ss", $item_code, $next_month);
                $day_stmt->execute();
                $day_result = $day_stmt->get_result();
                
                if ($day_result->num_rows > 0) {
                    $day_values = $day_result->fetch_assoc();
                    $opening = $day_values[$opening_col] ?? 0;
                    $purchase = $day_values[$purchase_col] ?? 0;
                    $sales = $day_values[$sales_col] ?? 0;
                    
                    $closing = $opening + $purchase - $sales;
                    
                    $update_day_query = "UPDATE $next_month_table 
                                        SET $closing_col = ? 
                                        WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                    $update_day_stmt = $conn->prepare($update_day_query);
                    $update_day_stmt->bind_param("dss", $closing, $item_code, $next_month);
                    $update_day_stmt->execute();
                    $update_day_stmt->close();
                    
                    // Update next day's opening if within same month and not exceeding financial year end
                    if ($day < $fin_year_end_day) {
                        $next_day = sprintf('%02d', $day + 1);
                        $update_next_query = "UPDATE $next_month_table 
                                             SET DAY_{$next_day}_OPEN = ? 
                                             WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                        $update_next_stmt = $conn->prepare($update_next_query);
                        $update_next_stmt->bind_param("dss", $closing, $item_code, $next_month);
                        $update_next_stmt->execute();
                        $update_next_stmt->close();
                    }
                }
                $day_stmt->close();
            }
            
            logMessage("Completed cascading for final month $next_month up to financial year end $fin_year_end", 'INFO');
            break; // Stop after processing up to financial year end
        }
        
        // If next month is completely within financial year, process the entire month
        logMessage("Cascading to month $next_month", 'INFO');
        
        // Get the table for this month
        $next_month_table = getDailyStockTableForDate($conn, $comp_id, $next_month_first_day);
        
        // Check if table exists
        $check_table = "SHOW TABLES LIKE '$next_month_table'";
        if ($conn->query($check_table)->num_rows == 0) {
            createDailyStockTable($conn, $next_month_table);
            logMessage("Created table $next_month_table for cascading", 'INFO');
        }
        
        // Get previous month's closing (which is the closing from the month we just processed)
        $prev_month = date('Y-m', strtotime($next_month . '-01 -1 month'));
        $prev_table = getDailyStockTableForDate($conn, $comp_id, $prev_month . '-01');
        $prev_last_day = date('d', strtotime('last day of ' . $prev_month));
        $prev_closing_column = "DAY_" . sprintf('%02d', $prev_last_day) . "_CLOSING";
        
        // Get previous month's closing
        $prev_closing = 0;
        $prev_query = "SELECT $prev_closing_column FROM $prev_table 
                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $prev_stmt = $conn->prepare($prev_query);
        $prev_stmt->bind_param("ss", $prev_month, $item_code);
        if ($prev_stmt->execute()) {
            $prev_result = $prev_stmt->get_result();
            if ($prev_result->num_rows > 0) {
                $prev_row = $prev_result->fetch_assoc();
                $prev_closing = $prev_row[$prev_closing_column] ?? 0;
            }
        }
        $prev_stmt->close();
        
        // Update or create record in next month
        $check_record = "SELECT DAY_01_OPEN FROM $next_month_table 
                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $check_stmt = $conn->prepare($check_record);
        $check_stmt->bind_param("ss", $next_month, $item_code);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows == 0) {
            // Create new record
            $check_stmt->close();
            $insert_query = "INSERT INTO $next_month_table 
                            (ITEM_CODE, STK_MONTH, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                            VALUES (?, ?, ?, 0, 0, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssdd", $item_code, $next_month, $prev_closing, $prev_closing);
            $insert_stmt->execute();
            $insert_stmt->close();
            logMessage("Inserted record for $item_code in $next_month with opening $prev_closing", 'INFO');
        } else {
            // Update existing record
            $check_stmt->close();
            $update_query = "UPDATE $next_month_table 
                           SET DAY_01_OPEN = ?,
                               LAST_UPDATED = CURRENT_TIMESTAMP 
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("dss", $prev_closing, $next_month, $item_code);
            $update_stmt->execute();
            $update_stmt->close();
            logMessage("Updated opening for $item_code in $next_month to $prev_closing", 'INFO');
        }
        
        // Recalculate the entire month
        recalculateDailyStockFromDay($conn, $next_month_table, $item_code, $next_month, 1);
        
        logMessage("Completed cascading for month $next_month", 'INFO');
    }
    
    logMessage("Daily stock updated successfully for item $item_code on $sale_date with cascade to financial year end $fin_year_end", 'INFO');
    
    return true;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_bills'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$response = ['success' => false, 'message' => '', 'total_amount' => 0, 'bill_count' => 0];
$start_time = microtime(true);

try {
    // ============================================================================
    // STEP 1: MAXIMUM PERFORMANCE SETTINGS
    // ============================================================================
    $conn->query("SET SESSION unique_checks = 0");
    $conn->query("SET SESSION foreign_key_checks = 0");
    $conn->query("SET SESSION sql_log_bin = 0");
    $conn->query("SET autocommit = 0");
    $conn->query("SET SESSION bulk_insert_buffer_size = 1024 * 1024 * 1024"); // 1GB
    
    // ============================================================================
    // STEP 2: GET INPUT PARAMETERS
    // ============================================================================
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    // FIX: Ensure start_date is before end_date (swap if needed)
    if (strtotime($start_date) > strtotime($end_date)) {
        $temp = $start_date;
        $start_date = $end_date;
        $end_date = $temp;
        logMessage("Date range was swapped: start_date=$start_date, end_date=$end_date", 'INFO');
    }
    
    $mode = $_POST['mode'];
    $comp_id = (int)$_SESSION['CompID'];
    $user_id = (int)$_SESSION['user_id'];
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    $items = $_POST['items']; // Array of [item_code => qty]
    
    // ============================================================================
    // STEP 3: VALIDATE DATE RANGE RESTRICTIONS
    // ============================================================================
    $restrictions = validateDateRangeRestrictions($conn, $start_date, $end_date, $comp_id);
    
    if ($restrictions['restricted'] && empty($restrictions['available_dates'])) {
        throw new Exception("No available dates in the selected range due to existing sales or dry days.");
    }
    
    // ============================================================================
    // STEP 4: CREATE DATE ARRAY (FAST)
    // ============================================================================
    $date_array = [];
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    foreach (new DatePeriod($begin, $interval, $end) as $date) {
        $date_array[] = $date->format("Y-m-d");
    }
    $days_count = count($date_array);
    
    // ============================================================================
    // STEP 4: BULK LOAD ALL MASTER DATA (3 QUERIES TOTAL)
    // ============================================================================
    $item_codes = array_keys($items);
    
    // Query 1: Load all item data
    $item_cache = [];
    if (!empty($item_codes)) {
        $placeholders = implode(',', array_fill(0, count($item_codes), '?'));
        $types = str_repeat('s', count($item_codes));
        
        $item_query = "SELECT im.CODE, 
                              COALESCE(NULLIF(im.Print_Name, ''), im.DETAILS) as display_name,
                              im.DETAILS, im.DETAILS2, im.RPRICE, im.LIQ_FLAG
                       FROM tblitemmaster im
                       WHERE im.CODE IN ($placeholders)";
        
        $item_stmt = $conn->prepare($item_query);
        $item_stmt->bind_param($types, ...$item_codes);
        $item_stmt->execute();
        $item_result = $item_stmt->get_result();
        while ($row = $item_result->fetch_assoc()) {
            $item_cache[$row['CODE']] = $row;
        }
        $item_stmt->close();
    }
    
    // Query 2: Load all subclass data for sizes
    $size_cache = [];
    $size_query = "SELECT ITEM_GROUP, CC, LIQ_FLAG FROM tblsubclass";
    $size_result = $conn->query($size_query);
    while ($row = $size_result->fetch_assoc()) {
        $key = $row['ITEM_GROUP'] . '|' . $row['LIQ_FLAG'];
        $size_cache[$key] = (float)$row['CC'];
    }
    
    // Query 3: Get category limits
    $limit_query = "SELECT IMFLLimit, BEERLimit, CLLimit FROM tblcompany WHERE CompID = ?";
    $limit_stmt = $conn->prepare($limit_query);
    $limit_stmt->bind_param("i", $comp_id);
    $limit_stmt->execute();
    $limit_result = $limit_stmt->get_result();
    $limits = $limit_result->fetch_assoc();
    $limit_stmt->close();
    
    $category_limits = [
        'IMFL' => (float)($limits['IMFLLimit'] ?? 1000),
        'BEER' => (float)($limits['BEERLimit'] ?? 0),
        'CL' => (float)($limits['CLLimit'] ?? 0),
        'OTHER' => PHP_FLOAT_MAX
    ];
    
    // ============================================================================
    // STEP 5: PRE-PROCESS ALL ITEMS IN MEMORY
    // ============================================================================
    $processed_items = [];
    $daily_distribution = array_fill(0, $days_count, []);
    
    foreach ($items as $item_code => $total_qty) {
        $total_qty = (int)$total_qty;
        if ($total_qty <= 0 || !isset($item_cache[$item_code])) {
            continue;
        }
        
        $item = $item_cache[$item_code];
        
        // FAST category detection
        $liq_flag = strtoupper($item['LIQ_FLAG'] ?? '');
        if ($liq_flag === 'F' || $liq_flag === 'FL') {
            $category = 'IMFL';
        } elseif ($liq_flag === 'C' || $liq_flag === 'CL') {
            $category = 'CL';
        } elseif ($liq_flag === 'B' || $liq_flag === 'BEER' || strpos(strtoupper($item['DETAILS2'] ?? ''), 'BEER') !== false) {
            $category = 'BEER';
        } else {
            $category = 'OTHER';
        }
        
        // FAST size extraction
        $size_key = ($item['DETAILS2'] ?? '') . '|' . $liq_flag;
        $size = $size_cache[$size_key] ?? 0;
        
        if ($size <= 0 && preg_match('/(\d+(?:\.\d+)?)\s*ML/i', $item['DETAILS2'] ?? $item['DETAILS'] ?? '', $matches)) {
            $size = (float)$matches[1];
        }
        
        if ($size <= 0) {
            $size = ($category === 'BEER') ? 650 : 750;
        }
        
        // ENHANCED: Distribution that handles global restrictions and dry days
        $full_distribution = getFullDistribution($total_qty, $date_array, $restrictions['available_dates']);
        
        for ($d = 0; $d < $days_count; $d++) {
            $qty = $full_distribution[$d];
            if ($qty > 0) {
                $daily_distribution[$d][] = [
                    'code' => $item_code,
                    'name' => $item['display_name'] ?? $item['DETAILS'],
                    'rate' => (float)$item['RPRICE'],
                    'size' => $size,
                    'category' => $category,
                    'qty' => $qty,
                    'amount' => $qty * (float)$item['RPRICE']
                ];
            }
        }
        
        $processed_items[$item_code] = [
            'category' => $category,
            'size' => $size,
            'rate' => (float)$item['RPRICE']
        ];
    }
    
    // ============================================================================
    // STEP 6: GENERATE BILLS WITH GREEDY PACKING (IN-MEMORY)
    // ============================================================================
    $bills = [];
    $next_bill_num = getNextBillNumberBatch($conn, $comp_id, count($daily_distribution) * 10); // Estimate
    
    foreach ($daily_distribution as $day_idx => $day_items) {
        if (empty($day_items)) continue;
        
        $sale_date = $date_array[$day_idx];
        $remaining = $day_items;
        
        while (!empty($remaining)) {
            $bill_items = [];
            $category_volumes = ['IMFL' => 0, 'BEER' => 0, 'CL' => 0, 'OTHER' => 0];
            
            // Sort by size descending for better packing
            usort($remaining, function($a, $b) {
                return $b['size'] <=> $a['size'];
            });
            
            foreach ($remaining as $idx => $item) {
                $cat = $item['category'];
                $item_volume = $item['size'] * $item['qty'];
                $limit = $category_limits[$cat] ?? PHP_FLOAT_MAX;
                
                if ($limit === 0 || $category_volumes[$cat] + $item_volume <= $limit) {
                    $bill_items[] = $item;
                    $category_volumes[$cat] += $item_volume;
                    unset($remaining[$idx]);
                }
            }
            
            if (empty($bill_items)) {
                // Force add first item
                $first_item = array_shift($remaining);
                $bill_items[] = $first_item;
            }
            
            // Calculate total amount
            $total_amount = 0;
            foreach ($bill_items as $item) {
                $total_amount += $item['amount'];
            }
            
            $bills[] = [
                'bill_no' => 'BL' . str_pad($next_bill_num++, 4, '0', STR_PAD_LEFT),
                'bill_date' => $sale_date,
                'items' => $bill_items,
                'total_amount' => $total_amount,
                'mode' => $mode,
                'comp_id' => $comp_id,
                'user_id' => $user_id
            ];
        }
    }
    
    // ============================================================================
    // STEP 7: BATCH INSERT HEADERS (1 QUERY)
    // ============================================================================
    if (empty($bills)) {
        throw new Exception("No bills generated");
    }
    
    $header_values = [];
    foreach ($bills as $bill) {
        $header_values[] = "('{$bill['bill_no']}', '{$bill['bill_date']}', {$bill['total_amount']}, 0, {$bill['total_amount']}, '{$bill['mode']}', {$bill['comp_id']}, {$bill['user_id']})";
    }
    
    $batch_header = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) VALUES " . implode(',', $header_values);
    $conn->query($batch_header);
    $header_count = $conn->affected_rows;
    
    // ============================================================================
    // STEP 8: BATCH INSERT DETAILS (1 QUERY WITH CHUNKING)
    // ============================================================================
    $detail_values = [];
    foreach ($bills as $bill) {
        foreach ($bill['items'] as $item) {
            $detail_values[] = "('{$bill['bill_no']}', '{$item['code']}', {$item['qty']}, {$item['rate']}, {$item['amount']}, '{$bill['mode']}', {$bill['comp_id']})";
        }
    }
    
    // Insert in chunks of 2000
    $detail_chunks = array_chunk($detail_values, 2000);
    foreach ($detail_chunks as $chunk) {
        $batch_detail = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) VALUES " . implode(',', $chunk);
        $conn->query($batch_detail);
    }
    
    // ============================================================================
    // STEP 9: BULK UPDATE ITEM STOCK (1 QUERY WITH TEMP TABLE)
    // ============================================================================
    $current_stock_column = "Current_Stock" . $comp_id;
    
    // Aggregate quantities
    $stock_aggregates = [];
    foreach ($bills as $bill) {
        foreach ($bill['items'] as $item) {
            $code = $item['code'];
            $stock_aggregates[$code] = ($stock_aggregates[$code] ?? 0) + $item['qty'];
        }
    }
    
    // Create and populate temp table
    $conn->query("CREATE TEMPORARY TABLE temp_stock (item_code VARCHAR(50) PRIMARY KEY, qty DECIMAL(10,3))");
    
    $stock_values = [];
    foreach ($stock_aggregates as $code => $qty) {
        $stock_values[] = "('" . $conn->real_escape_string($code) . "', $qty)";
    }
    
    if (!empty($stock_values)) {
        $conn->query("INSERT INTO temp_stock (item_code, qty) VALUES " . implode(',', $stock_values));
        
        // Update existing stocks
        $conn->query("UPDATE tblitem_stock ts 
                      JOIN temp_stock t ON ts.ITEM_CODE = t.item_code
                      SET ts.$current_stock_column = ts.$current_stock_column - t.qty");
        
        // Insert missing stocks
        $conn->query("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $current_stock_column)
                      SELECT t.item_code, '$fin_year_id', -t.qty
                      FROM temp_stock t
                      LEFT JOIN tblitem_stock ts ON ts.ITEM_CODE = t.item_code
                      WHERE ts.ITEM_CODE IS NULL");
    }
    
    $conn->query("DROP TEMPORARY TABLE temp_stock");
    
    // ============================================================================
    // STEP 10: CASCADING DAILY STOCK UPDATE (ENHANCED)
    // ============================================================================
    
    // Process each bill and update daily stock with cascading logic
    foreach ($bills as $bill) {
        $sale_date = $bill['bill_date'];
        
        foreach ($bill['items'] as $item) {
            updateDailyStock($conn, $item['code'], $sale_date, $item['qty'], $comp_id);
        }
    }
    
    // ============================================================================
    // STEP 11: BULK CASH MEMO GENERATION (SINGLE QUERY) - THE KEY OPTIMIZATION
    // ============================================================================
    $cash_memo_count = 0;
    
    // Get max cash memo number
    $max_memo_query = "SELECT MAX(CAST(SUBSTRING(CASH_MEMO_NO, 3) AS UNSIGNED)) as max_memo 
                       FROM tbl_cash_memo_prints WHERE COMP_ID = ?";
    $max_stmt = $conn->prepare($max_memo_query);
    $max_stmt->bind_param("i", $comp_id);
    $max_stmt->execute();
    $max_result = $max_stmt->get_result();
    $max_row = $max_result->fetch_assoc();
    $next_memo_num = ($max_row['max_memo'] ?? 0) + 1;
    $max_stmt->close();
    
    // Prepare cash memo values - BULK INSERT
    $memo_values = [];
    
    foreach ($bills as $bill) {
        $cash_memo_no = 'CM' . str_pad($next_memo_num++, 4, '0', STR_PAD_LEFT);
        
        $memo_values[] = "('$cash_memo_no', '{$bill['bill_no']}', {$bill['total_amount']}, {$bill['total_amount']}, '{$bill['bill_date']}', {$bill['comp_id']}, {$bill['user_id']})";
    }
    
    // Insert cash memos in bulk - SINGLE QUERY!
    if (!empty($memo_values)) {
        $batch_memo = "INSERT INTO tbl_cash_memo_prints (CASH_MEMO_NO, BILL_NO, TOTAL_AMOUNT, NET_AMOUNT, MEMO_DATE, COMP_ID, CREATED_BY) 
                       VALUES " . implode(',', $memo_values);
        $conn->query($batch_memo);
        $cash_memo_count = count($memo_values);
    }
    
    // ============================================================================
    // STEP 12: COMMIT AND RETURN
    // ============================================================================
    $conn->commit();
    
    $execution_time = round(microtime(true) - $start_time, 2);
    
    // Calculate total amount
    $total_amount = array_sum(array_column($bills, 'total_amount'));
    
    $response['success'] = true;
    $response['message'] = "Generated " . count($bills) . " bills with $cash_memo_count cash memos in {$execution_time} seconds";
    $response['total_amount'] = number_format($total_amount, 2);
    $response['bill_count'] = count($bills);
    $response['cash_memo_count'] = $cash_memo_count;
    $response['execution_time'] = $execution_time;
    
    // Re-enable constraints
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
    
} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = "Error: " . $e->getMessage();
    
    // Re-enable constraints
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
}

echo json_encode($response);
exit;

// Helper function for batch bill number generation
function getNextBillNumberBatch($conn, $comp_id, $estimate = 100) {
    $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill 
              FROM tblsaleheader WHERE COMP_ID = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return ($row['max_bill'] ?? 0) + 1;
}
?>
