<?php
// generate_bills_ultra_fast.php - ULTRA-OPTIMIZED FOR 100 BILLS IN 5 SECONDS
// Uses batch processing with real-time AJAX progress updates
session_start();

// Error reporting - only log errors, don't display
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

// Include required files
require_once "../config/db.php";
require_once "volume_limit_utils.php";
require_once "cash_memo_functions.php";

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

// ENHANCED: Function to update daily stock table with MULTI-MONTH cascading updates
function updateDailyStock($conn, $item_code, $sale_date, $qty, $comp_id) {
    logMessage("Starting daily stock update for item $item_code sold on $sale_date (Qty: $qty)", 'INFO');
    
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
    // STEP 3: CASCADE TO CURRENT MONTH (CRITICAL FIX - THIS WAS MISSING)
    // ============================================================================
    
    logMessage("Starting cascading to current month for item $item_code sold on $sale_date", 'INFO');
    
    // If sale is not in current month, we need to cascade to current month
    if ($month_year_full < $current_month) {
        logMessage("Sale in archived month $month_year_full, cascading to current month $current_month", 'INFO');
        
        // Create a month iterator starting from sale month
        $current_month_obj = new DateTime($month_year_full . '-01');
        
        while (true) {
            // Move to next month
            $current_month_obj->modify('+1 month');
            $next_month = $current_month_obj->format('Y-m');
            
            // Stop if we've reached beyond current month
            if ($next_month > $current_month) {
                logMessage("Reached month $next_month which is beyond current month $current_month, stopping cascade", 'INFO');
                break;
            }
            
            // Get the table for this month
            $next_month_table = getDailyStockTableForDate($conn, $comp_id, $next_month . '-01');
            
            // Check if table exists
            $check_table = "SHOW TABLES LIKE '$next_month_table'";
            if ($conn->query($check_table)->num_rows == 0) {
                // Create the table
                createDailyStockTable($conn, $next_month_table);
                logMessage("Created table $next_month_table for cascading", 'INFO');
            }
            
            // Get previous month's closing
            $prev_month = date('Y-m', strtotime($next_month . '-01 -1 month'));
            $prev_table = getDailyStockTableForDate($conn, $comp_id, $prev_month . '-01');
            $prev_last_day = date('d', strtotime('last day of ' . $prev_month));
            $prev_closing_column = "DAY_" . sprintf('%02d', $prev_last_day) . "_CLOSING";
            
            // Get previous month's closing
            $prev_closing = 0;
            if ($prev_month) {
                $prev_query = "SELECT $prev_closing_column FROM $prev_table 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prev_stmt = $conn->prepare($prev_query);
                $prev_stmt->bind_param("ss", $prev_month, $item_code);
                if ($prev_stmt->execute()) {
                    $prev_result = $prev_stmt->get_result();
                    if ($prev_result->num_rows > 0) {
                        $prev_row = $prev_result->fetch_assoc();
                        $prev_closing = $prev_row[$prev_closing_column] ?? 0;
                    } else {
                        // If no record in previous month, use 0
                        $prev_closing = 0;
                    }
                }
                $prev_stmt->close();
            }
            
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
            
            // Break after updating current month
            if ($next_month >= $current_month) {
                logMessage("Reached current month $current_month, stopping cascading", 'INFO');
                break;
            }
        }
    }
    
    // ============================================================================
    // STEP 4: UPDATE CURRENT MONTH'S STOCK IF SALE DATE IS IN ARCHIVED MONTH
    // ============================================================================
    
    // Get current month table
    $current_daily_stock_table = "tbldailystock_" . $comp_id;
    
    // If sale is in archived month, update current month's stock
    if ($month_year_full < $current_month) {
        logMessage("Sale in archived month, updating current month's stock", 'INFO');
        
        // Get previous month (the month before current month)
        $prev_month_of_current = date('Y-m', strtotime($current_month . '-01 -1 month'));
        
        if ($prev_month_of_current == $month_year_full) {
            // Sale was in the month just before current month, update current month's opening
            $current_record_check = "SELECT DAY_01_OPEN FROM $current_daily_stock_table 
                                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $current_check_stmt = $conn->prepare($current_record_check);
            $current_check_stmt->bind_param("ss", $current_month, $item_code);
            $current_check_stmt->execute();
            $current_check_result = $current_check_stmt->get_result();
            
            if ($current_check_result->num_rows > 0) {
                // Update current month's opening (deduct the sale)
                $update_current_query = "UPDATE $current_daily_stock_table 
                                        SET DAY_01_OPEN = DAY_01_OPEN - ?,
                                            LAST_UPDATED = CURRENT_TIMESTAMP 
                                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_current_stmt = $conn->prepare($update_current_query);
                $update_current_stmt->bind_param("dss", $qty, $current_month, $item_code);
                $update_current_stmt->execute();
                $update_current_stmt->close();
                
                // Recalculate current month
                recalculateDailyStockFromDay($conn, $current_daily_stock_table, $item_code, $current_month, 1);
                logMessage("Updated current month's opening by deducting $qty", 'INFO');
            }
            $current_check_stmt->close();
        }
    }
    
    logMessage("Daily stock updated successfully for item $item_code on $sale_date in table $sale_daily_stock_table: Sales=$new_sales, Closing=$new_closing", 'INFO');
    
    return true;
}

// Set headers
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ============================================================================
// STEP 1: INITIALIZE PROGRESS TRACKING (Shared Memory Simulation via Session)
// ============================================================================
$progress_key = 'bill_progress_' . session_id();
$_SESSION[$progress_key] = [
    'total_bills' => 0,
    'current_bill' => 0,
    'status' => 'initializing',
    'message' => 'Initializing bill generation...',
    'percentage' => 0,
    'bills_generated' => [],
    'start_time' => microtime(true),
    'last_update' => time(),
    'speed' => 0
];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['generate_bills'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$response = ['success' => false, 'message' => '', 'total_amount' => 0, 'bill_count' => 0];
$start_time = microtime(true);

try {
    // ============================================================================
    // STEP 2: MAXIMUM PERFORMANCE DATABASE SETTINGS
    // ============================================================================
    $conn->query("SET SESSION unique_checks = 0");
    $conn->query("SET SESSION foreign_key_checks = 0");
    $conn->query("SET SESSION sql_log_bin = 0");
    $conn->query("SET autocommit = 0");
    $conn->query("SET SESSION bulk_insert_buffer_size = 1024 * 1024 * 1024");
    $conn->query("SET SESSION wait_timeout = 28800");
    
    // ============================================================================
    // STEP 3: GET INPUT PARAMETERS
    // ============================================================================
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    $mode = $_POST['mode'];
    $comp_id = (int)$_SESSION['CompID'];
    $user_id = (int)$_SESSION['user_id'];
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    $items = $_POST['items'];
    
    // Update progress
    $_SESSION[$progress_key]['status'] = 'loading_data';
    $_SESSION[$progress_key]['message'] = 'Loading item data...';
    
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
    // STEP 5: BULK LOAD ALL MASTER DATA (3 QUERIES MAX)
    // ============================================================================
    $item_codes = array_keys($items);
    
    // Query 1: Load all item data with proper category and size info
    $item_cache = [];
    $items_data = [];
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
            $items_data[$row['CODE']] = [
                'rate' => (float)$row['RPRICE'],
                'name' => $row['display_name'] ?? $row['DETAILS']
            ];
        }
        $item_stmt->close();
    }
    
    // Query 2: Get category limits using the proper function
    $category_limits = getCategoryLimits($conn, $comp_id);
    $category_limits['OTHER'] = PHP_FLOAT_MAX; // No limit for OTHER category
    
    // Update progress
    $_SESSION[$progress_key]['status'] = 'processing';
    $_SESSION[$progress_key]['message'] = 'Processing items with proper volume limits...';
    
    // ============================================================================
    // STEP 6: PRE-PROCESS ALL ITEMS - CREATE DAILY DISTRIBUTION
    // ============================================================================
    $daily_sales_data = [];
    
    // Initialize daily sales data for each item
    foreach ($items as $item_code => $total_qty) {
        $total_qty = (int)$total_qty;
        if ($total_qty <= 0 || !isset($item_cache[$item_code])) {
            continue;
        }
        
        // Use distributeSales function from volume_limit_utils for uniform distribution
        $daily_sales_data[$item_code] = distributeSales($total_qty, $days_count);
    }
    
    // ============================================================================
    // STEP 7: GENERATE BILLS USING PROPER VOLUME LIMIT FUNCTIONS
    // ============================================================================
    // Use the proper generateBillsWithLimits function from volume_limit_utils.php
    // This ensures proper category detection and volume limit handling
    $bills = generateBillsWithLimits(
        $conn,
        $items_data,
        $date_array,
        $daily_sales_data,
        $mode,
        $comp_id,
        $user_id,
        $fin_year_id
    );
    
    // Assign proper bill numbers to all bills
    $next_bill_num = getNextBillNumberBatch($conn, $comp_id);
    $bill_idx = 0;
    foreach ($bills as &$bill) {
        $bill['bill_no'] = 'BL' . str_pad($next_bill_num + $bill_idx, 4, '0', STR_PAD_LEFT);
        $bill_idx++;
    }
    unset($bill); // Break reference
    
    // Update progress for each bill generated
    foreach ($bills as $bill) {
        $bill_no = $bill['bill_no'];
        $sale_date = $bill['bill_date'];
        $total_amount = $bill['total_amount'];
        $item_count = count($bill['items']);
        
        $_SESSION[$progress_key]['current_bill'] = $_SESSION[$progress_key]['current_bill'] + 1;
        $_SESSION[$progress_key]['message'] = "Generated bill $bill_no ($item_count items)";
        $_SESSION[$progress_key]['bills_generated'][] = [
            'bill_no' => $bill_no,
            'date' => $sale_date,
            'amount' => $total_amount,
            'items' => $item_count
        ];
        
        // Calculate speed (bills per second)
        $elapsed = microtime(true) - $start_time;
        $_SESSION[$progress_key]['speed'] = $_SESSION[$progress_key]['current_bill'] / max($elapsed, 0.001);
        $_SESSION[$progress_key]['last_update'] = time();
    }
    
    // Update total bills count
    $_SESSION[$progress_key]['total_bills'] = count($bills);
    
    if (empty($bills)) {
        throw new Exception("No bills generated");
    }
    
    // ============================================================================
    // STEP 8: BATCH INSERT HEADERS (1 QUERY - MAXIMUM SPEED)
    // ============================================================================
    $_SESSION[$progress_key]['status'] = 'saving';
    $_SESSION[$progress_key]['message'] = 'Saving bills to database...';
    
    $header_values = [];
    foreach ($bills as $bill) {
        $header_values[] = "('{$bill['bill_no']}', '{$bill['bill_date']}', {$bill['total_amount']}, 0, {$bill['total_amount']}, '{$bill['mode']}', {$bill['comp_id']}, {$bill['user_id']})";
    }
    
    // Insert in chunks of 500
    $header_chunks = array_chunk($header_values, 500);
    foreach ($header_chunks as $chunk) {
        $batch_header = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) VALUES " . implode(',', $chunk);
        $conn->query($batch_header);
    }
    
    // ============================================================================
    // STEP 9: BATCH INSERT DETAILS (1 QUERY WITH CHUNKING)
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Saving bill details...';
    
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
    // STEP 10: BULK UPDATE ITEM STOCK (OPTIMIZED WITH TEMP TABLE)
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Updating stock levels...';
    
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
    // STEP 11: CASCADING DAILY STOCK UPDATE (ENHANCED)
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Updating daily stock records with cascading...';
    
    // Process each bill and update daily stock with cascading logic
    foreach ($bills as $bill) {
        $sale_date = $bill['bill_date'];
        
        foreach ($bill['items'] as $item) {
            updateDailyStock($conn, $item['code'], $sale_date, $item['qty'], $comp_id);
        }
    }
    
    // ============================================================================
    // STEP 12: BULK GENERATE CASH MEMOS (OPTIMIZED)
    // Cash memos are generated in bulk for all bills
    // ============================================================================
    $_SESSION[$progress_key]['message'] = 'Generating cash memos...';
    
    // Get company data once
    $companyQuery = "SELECT COMP_NAME, COMP_ADDR, COMP_FLNO, CF_LINE, CS_LINE FROM tblcompany WHERE CompID = ?";
    $companyStmt = $conn->prepare($companyQuery);
    $companyStmt->bind_param("i", $comp_id);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    $companyRow = $companyResult->fetch_assoc();
    $companyStmt->close();
    
    $companyData = [
        'name' => $companyRow['COMP_NAME'] ?? 'WINE SHOP',
        'address' => $companyRow['COMP_ADDR'] ?? '',
        'licenseNumber' => $companyRow['COMP_FLNO'] ?? ''
    ];
    $addressLine = $companyRow['CF_LINE'] ?? "";
    if (!empty($companyRow['CS_LINE'])) {
        $addressLine .= (!empty($addressLine) ? " " : "") . $companyRow['CS_LINE'];
    }
    if (!empty($addressLine)) {
        $companyData['address'] = $addressLine;
    }
    
    // Get permits once
    $permitResult = $conn->query("SELECT P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, DETAILS FROM tblpermit WHERE P_NO IS NOT NULL AND P_NO != '' LIMIT 100");
    $allPermits = [];
    if ($permitResult) {
        while ($row = $permitResult->fetch_assoc()) {
            $allPermits[] = $row;
        }
    }
    
    // Bulk insert cash memos
    $cash_memo_count = 0;
    if (!empty($bills) && !empty($allPermits)) {
        $cashMemoValues = [];
        $printDate = date('Y-m-d H:i:s');
        
        foreach ($bills as $bill) {
            $billNo = $bill['bill_no'];
            $billDate = $bill['bill_date'];
            $totalAmount = $bill['total_amount'];
            
            // Pick random permit
            $permitData = $allPermits[array_rand($allPermits)];
            
            $customerName = $permitData['DETAILS'] ?? 'RETAIL';
            $permitNo = $permitData['P_NO'] ?? null;
            $permitPlace = $permitData['PLACE_ISS'] ?? null;
            $permitExpDate = !empty($permitData['P_EXP_DT']) ? $permitData['P_EXP_DT'] : null;
            
            // Build items JSON from bill items
            $itemsForJson = [];
            foreach ($bill['items'] as $item) {
                $itemsForJson[] = [
                    'ITEM_CODE' => $item['code'],
                    'QTY' => $item['qty'],
                    'RATE' => $item['rate'],
                    'AMOUNT' => $item['amount'],
                    'DETAILS' => $item['name'] ?? '',
                    'DETAILS2' => $item['size'] . 'ML'
                ];
            }
            $itemsJson = json_encode($itemsForJson);
            
            // Create cash memo text
            $billDataForText = [
                'BILL_NO' => $billNo,
                'BILL_DATE' => $billDate,
                'NET_AMOUNT' => $totalAmount
            ];
            $cashMemoText = generateCashMemoText($companyData, $billDataForText, $itemsForJson, $permitData);
            
            // Escape strings for SQL
            $billNoEsc = $conn->real_escape_string($billNo);
            $printDateEsc = $conn->real_escape_string($printDate);
            $licenseNumberEsc = $conn->real_escape_string($companyData['licenseNumber']);
            $shopNameEsc = $conn->real_escape_string($companyData['name']);
            $shopAddressEsc = $conn->real_escape_string($companyData['address']);
            $billDateEsc = $conn->real_escape_string($billDate);
            $customerNameEsc = $conn->real_escape_string($customerName);
            $permitNoEsc = $permitNo ? $conn->real_escape_string($permitNo) : '';
            $permitPlaceEsc = $permitPlace ? $conn->real_escape_string($permitPlace) : '';
            $permitExpDateEsc = $permitExpDate ? $conn->real_escape_string($permitExpDate) : '';
            $itemsJsonEsc = $conn->real_escape_string($itemsJson);
            $cashMemoTextEsc = $conn->real_escape_string($cashMemoText);
            
            $cashMemoValues[] = "('$billNoEsc', $comp_id, '$printDateEsc', $user_id, '$licenseNumberEsc', '$shopNameEsc', '$shopAddressEsc', '$billDateEsc', '$customerNameEsc', '$permitNoEsc', '$permitPlaceEsc', '$permitExpDateEsc', '$itemsJsonEsc', $totalAmount, '$cashMemoTextEsc')";
            $cash_memo_count++;
        }
        
        // Bulk insert in chunks
        if (!empty($cashMemoValues)) {
            $cashMemoChunks = array_chunk($cashMemoValues, 500);
            foreach ($cashMemoChunks as $chunk) {
                $cashMemoSql = "INSERT IGNORE INTO tbl_cash_memo_prints 
                    (bill_no, comp_id, print_date, printed_by, license_number, shop_name, shop_address, 
                     bill_date, customer_name, permit_no, permit_place, permit_exp_date, items_json, total_amount, cash_memo_text) 
                    VALUES " . implode(',', $chunk);
                $conn->query($cashMemoSql);
            }
        }
    } else {
        // If no permits, skip cash memo generation
        $cash_memo_count = 0;
    }
    
    // ============================================================================
    // STEP 13: COMMIT AND RETURN
    // ============================================================================
    $conn->commit();
    
    $execution_time = round(microtime(true) - $start_time, 3);
    
    // Calculate total amount
    $total_amount = array_sum(array_column($bills, 'total_amount'));
    
    // Update final progress
    $_SESSION[$progress_key]['status'] = 'completed';
    $_SESSION[$progress_key]['percentage'] = 100;
    $_SESSION[$progress_key]['message'] = "Completed! Generated " . count($bills) . " bills with $cash_memo_count cash memos in {$execution_time} seconds";
    $_SESSION[$progress_key]['end_time'] = time();
    
    $response['success'] = true;
    $response['message'] = "Generated " . count($bills) . " bills with $cash_memo_count cash memos in {$execution_time} seconds";
    $response['total_amount'] = number_format($total_amount, 2);
    $response['bill_count'] = count($bills);
    $response['cash_memo_count'] = $cash_memo_count;
    $response['execution_time'] = $execution_time;
    $response['bills'] = $_SESSION[$progress_key]['bills_generated'];
    $response['progress_key'] = $progress_key;
    
    // Re-enable constraints
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
    
} catch (Exception $e) {
    $conn->rollback();
    $response['message'] = "Error: " . $e->getMessage();
    
    $_SESSION[$progress_key]['status'] = 'error';
    $_SESSION[$progress_key]['message'] = "Error: " . $e->getMessage();
    
    // Re-enable constraints
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    $conn->query("SET UNIQUE_CHECKS = 1");
}

// Keep progress in session for 5 minutes
if (isset($_SESSION[$progress_key])) {
    $_SESSION[$progress_key]['expires'] = time() + 300;
}

echo json_encode($response);
exit;

// Helper function for batch bill number generation
function getNextBillNumberBatch($conn, $comp_id) {
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
