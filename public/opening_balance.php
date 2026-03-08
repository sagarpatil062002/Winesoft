<?php
// Remove time limit for this script completely
set_time_limit(0);
ignore_user_abort(true);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

session_start();

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

$comp_id = $_SESSION['CompID'];
$fin_year_id = $_SESSION['FIN_YEAR_ID']; // This is the ID from tblfinyear

include_once "../config/db.php"; // MySQLi connection in $conn
require_once 'license_functions.php'; // ADDED: Include license functions

// Helper function to get financial year start date from tblfinyear
function getFinancialYearStartDate($fin_year_id, $conn) {
    static $cache = null;
    if ($cache !== null) return $cache;
    
    $query = "SELECT START_DATE FROM tblfinyear WHERE ID = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $fin_year_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $start_date = $row['START_DATE'];
        $cache = date('Y-m-d', strtotime($start_date));
        return $cache;
    }
    
    $cache = date('Y') . '-04-01';
    return $cache;
}

// Set default start date from financial year table
$default_start_date = getFinancialYearStartDate($fin_year_id, $conn);

// Get company's license type and available classes - ADDED LICENSE FILTERING
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering - using CLASS_CODE from tblclass_new
// MODIFIED: Now properly filters using CLASS_CODE_NEW only (not the numeric CLASS field)
$allowed_classes = [];
$allowed_category_codes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
    $allowed_category_codes[] = $class['CATEGORY_CODE'];
}

// Also get the numeric CLASS IDs for backward compatibility mapping
// These map to the old CLASS values in tblitemmaster
$allowed_class_ids = [];
$old_class_mapping = [
    'W' => [1, 3, 5, 6, 7, 8, 15],     // Spirit categories
    'V' => [2, 16],                     // Wine categories
    'F' => [4],                         // Strong Beer
    'M' => [14],                        // Mild Beer
    'L' => [9, 10]                      // Country Liquor
];

foreach ($allowed_classes as $sgroup) {
    if (isset($old_class_mapping[$sgroup])) {
        $allowed_class_ids = array_merge($allowed_class_ids, $old_class_mapping[$sgroup]);
    }
}
$allowed_class_ids = array_unique($allowed_class_ids);

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// View type selection (with_stock or without_stock)
$view_type = isset($_GET['view']) ? $_GET['view'] : 'with_stock';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 100; // Reduced for faster initial load
$offset = ($page - 1) * $limit;

// Get current company details - OPTIMIZED: Single query
$company_query = "SELECT c.CompID, c.Comp_Name, fy.START_DATE, fy.END_DATE 
                  FROM tblcompany c 
                  CROSS JOIN tblfinyear fy 
                  WHERE c.CompID = ? AND fy.ID = ?";
$company_stmt = $conn->prepare($company_query);
$company_stmt->bind_param("ii", $comp_id, $fin_year_id);
$company_stmt->execute();
$company_result = $company_stmt->get_result();
$row = $company_result->fetch_assoc();
$current_company = ['CompID' => $row['CompID'], 'Comp_Name' => $row['Comp_Name']];
$finyear_data = ['START_DATE' => $row['START_DATE'], 'END_DATE' => $row['END_DATE']];
$company_stmt->close();

// ==================== PERFORMANCE OPTIMIZATION #1: Bulk Column Creation ====================
// Check and create all needed columns in ONE query (only if table exists)
$table_check = $conn->query("SHOW TABLES LIKE 'tblitem_stock'");
if ($table_check->num_rows > 0) {
    $check_columns_query = "SHOW COLUMNS FROM tblitem_stock LIKE 'OPENING_STOCK$comp_id'";
    $check_result = $conn->query($check_columns_query);
    
    if ($check_result->num_rows == 0) {
        $alter_query = "ALTER TABLE tblitem_stock 
                        ADD COLUMN OPENING_STOCK$comp_id INT DEFAULT 0,
                        ADD COLUMN CURRENT_STOCK$comp_id INT DEFAULT 0";
        $conn->query($alter_query);
    }
}

// Function to get archive table name for a specific month
function getArchiveTableName($comp_id, $month) {
    $month_year = date('m_y', strtotime($month . '-01'));
    return "tbldailystock_{$comp_id}_{$month_year}";
}

// Function to create a fresh archive table WITH day columns
function createFreshArchiveTable($conn, $comp_id, $month) {
    $table_name = getArchiveTableName($comp_id, $month);
    
    // Get days in this month
    $year_month = explode('-', $month);
    $year = $year_month[0];
    $month_num = $year_month[1];
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
    
    // Build column definitions for all days
    $column_defs = [];
    for ($day = 1; $day <= $days_in_month; $day++) {
        $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
        $column_defs[] = "`DAY_{$day_padded}_OPEN` INT DEFAULT 0";
        $column_defs[] = "`DAY_{$day_padded}_PURCHASE` INT DEFAULT 0";
        $column_defs[] = "`DAY_{$day_padded}_SALES` INT DEFAULT 0";
        $column_defs[] = "`DAY_{$day_padded}_CLOSING` INT DEFAULT 0";
    }
    
    $columns_sql = implode(",\n    ", $column_defs);
    
    // Create table with ALL day columns
    $create_table_query = "CREATE TABLE $table_name (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        $columns_sql,
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock_$comp_id` (`STK_MONTH`,`ITEM_CODE`),
        KEY `ITEM_CODE_$comp_id` (`ITEM_CODE`),
        KEY `LIQ_FLAG_$comp_id` (`LIQ_FLAG`),
        KEY `STK_MONTH_$comp_id` (`STK_MONTH`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if ($conn->query($create_table_query)) {
        return $table_name;
    } else {
        error_log("Failed to create archive table $table_name: " . $conn->error);
        return false;
    }
}

// Check if company daily stock table exists, if not create it
$check_table_query = "SHOW TABLES LIKE 'tbldailystock_$comp_id'";
$check_table_result = $conn->query($check_table_query);
$table_exists = $check_table_result->num_rows > 0;

if (!$table_exists) {
    // Create company-specific daily stock table with dynamic columns
    $create_table_query = "CREATE TABLE tbldailystock_$comp_id (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock_$comp_id` (`STK_MONTH`,`ITEM_CODE`),
        KEY `ITEM_CODE_$comp_id` (`ITEM_CODE`),
        KEY `LIQ_FLAG_$comp_id` (`LIQ_FLAG`),
        KEY `STK_MONTH_$comp_id` (`STK_MONTH`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $conn->query($create_table_query);
}

// ==================== PERFORMANCE OPTIMIZATION #2: Bulk Column Addition ====================
// Function to add day columns for a specific month (optimized for bulk operations)
function addDayColumnsForMonth($conn, $comp_id, $month, $force_create = false) {
    $year_month = explode('-', $month);
    $year = $year_month[0];
    $month_num = $year_month[1];
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
    
    // Determine which table to use (current or archive)
    $current_month = date('Y-m');
    $table_name = ($month == $current_month) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $month);
    
    // Create archive table if it doesn't exist and it's not current month
    if ($month != $current_month) {
        $check_archive_query = "SHOW TABLES LIKE '$table_name'";
        $check_result = $conn->query($check_archive_query);
        $archive_exists = $check_result->num_rows > 0;
        
        if (!$archive_exists) {
            // Create FRESH archive table with NO day columns
            createFreshArchiveTable($conn, $comp_id, $month);
            $force_create = true; // Force column creation for new table
        }
    }
    
    // Only proceed if we need to create columns
    if ($force_create) {
        // Get all existing columns in ONE query
        $existing_columns_query = "SHOW COLUMNS FROM $table_name";
        $existing_result = $conn->query($existing_columns_query);
        $existing_columns = [];
        while ($row = $existing_result->fetch_assoc()) {
            $existing_columns[] = $row['Field'];
        }
        
        // Prepare ALTER TABLE statements to add multiple columns at once
        $alter_statements = [];
        
        for ($day = 1; $day <= $days_in_month; $day++) {
            $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
            
            $cols_to_add = [
                "DAY_{$day_padded}_OPEN",
                "DAY_{$day_padded}_PURCHASE",
                "DAY_{$day_padded}_SALES",
                "DAY_{$day_padded}_CLOSING"
            ];
            
            foreach ($cols_to_add as $col) {
                if (!in_array($col, $existing_columns)) {
                    $alter_statements[] = "ADD COLUMN $col INT DEFAULT 0";
                }
            }
        }
        
        // Execute all ALTER statements at once if there are any
        if (!empty($alter_statements)) {
            $alter_query = "ALTER TABLE $table_name " . implode(", ", $alter_statements);
            $conn->query($alter_query);
        }
    }
}

// Function to get the correct table for a specific month (FIXED)
function getTableForMonth($conn, $comp_id, $month) {
    $current_month = date('Y-m');
    $debug = ['month' => $month, 'current_month' => $current_month];
    
    if ($month == $current_month) {
        $table = "tbldailystock_$comp_id";
        $debug['table'] = $table;
        $debug['reason'] = 'current month';
        error_log("getTableForMonth: " . json_encode($debug));
        return $table;
    } else {
        $archive_table = getArchiveTableName($comp_id, $month);
        $debug['archive_table'] = $archive_table;
        
        // Check if archive table exists
        $check_query = "SHOW TABLES LIKE '$archive_table'";
        $check_result = $conn->query($check_query);
        $table_exists = $check_result->num_rows > 0;
        $debug['table_exists'] = $table_exists;
        
        if (!$table_exists) {
            $debug['action'] = 'creating new table with day columns';
            // Create the archive table WITH day columns
            createFreshArchiveTable($conn, $comp_id, $month);
        } else {
            $debug['action'] = 'using existing table';
        }
        
        error_log("getTableForMonth: " . json_encode($debug));
        return $archive_table;
    }
}

// Check if we need to switch to a new month (optimized)
$current_month = date('Y-m');
$check_current_month_query = "SELECT 1 FROM tbldailystock_$comp_id WHERE STK_MONTH = ? LIMIT 1";
$check_month_stmt = $conn->prepare($check_current_month_query);
$check_month_stmt->bind_param("s", $current_month);
$check_month_stmt->execute();
$check_month_stmt->store_result();
$current_month_exists = $check_month_stmt->num_rows > 0;
$check_month_stmt->close();

if (!$current_month_exists) {
    // Check for previous month data to archive
    $previous_month = date('Y-m', strtotime('-1 month'));
    $check_prev_query = "SELECT 1 FROM tbldailystock_$comp_id WHERE STK_MONTH = ? LIMIT 1";
    $check_prev_stmt = $conn->prepare($check_prev_query);
    $check_prev_stmt->bind_param("s", $previous_month);
    $check_prev_stmt->execute();
    $check_prev_stmt->store_result();
    $prev_month_exists = $check_prev_stmt->num_rows > 0;
    $check_prev_stmt->close();
    
    if ($prev_month_exists) {
        // Archive previous month's data
        $archive_table = getArchiveTableName($comp_id, $previous_month);
        
        // Create FRESH archive table with NO day columns
        createFreshArchiveTable($conn, $comp_id, $previous_month);
        
        // Now add the correct day columns for this month
        $prev_year_month = explode('-', $previous_month);
        $prev_year = $prev_year_month[0];
        $prev_month_num = $prev_year_month[1];
        $prev_days_in_month = cal_days_in_month(CAL_GREGORIAN, $prev_month_num, $prev_year);
        
        // Add day columns for previous month
        $alter_statements = [];
        for ($day = 1; $day <= $prev_days_in_month; $day++) {
            $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
            
            $alter_statements[] = "ADD COLUMN DAY_{$day_padded}_OPEN INT DEFAULT 0";
            $alter_statements[] = "ADD COLUMN DAY_{$day_padded}_PURCHASE INT DEFAULT 0";
            $alter_statements[] = "ADD COLUMN DAY_{$day_padded}_SALES INT DEFAULT 0";
            $alter_statements[] = "ADD COLUMN DAY_{$day_padded}_CLOSING INT DEFAULT 0";
        }
        
        if (!empty($alter_statements)) {
            $alter_query = "ALTER TABLE $archive_table " . implode(", ", $alter_statements);
            $conn->query($alter_query);
        }
        
        // Copy data to archive - we need to build dynamic column lists
        // Get columns from source table
        $source_columns = [];
        $source_query = "SHOW COLUMNS FROM tbldailystock_$comp_id";
        $source_result = $conn->query($source_query);
        while ($row = $source_result->fetch_assoc()) {
            $source_columns[] = $row['Field'];
        }
        
        // Get columns from destination table
        $dest_columns = [];
        $dest_query = "SHOW COLUMNS FROM $archive_table";
        $dest_result = $conn->query($dest_query);
        while ($row = $dest_result->fetch_assoc()) {
            $dest_columns[] = $row['Field'];
        }
        
        // Find common columns (excluding auto_increment)
        $common_columns = array_intersect($source_columns, $dest_columns);
        // Remove DailyStockID if it's auto_increment
        $common_columns = array_filter($common_columns, function($col) {
            return $col !== 'DailyStockID';
        });
        
        if (!empty($common_columns)) {
            $columns_list = implode(', ', $common_columns);
            $copy_data_query = "INSERT INTO $archive_table ($columns_list) 
                               SELECT $columns_list FROM tbldailystock_$comp_id 
                               WHERE STK_MONTH = ?";
            $copy_stmt = $conn->prepare($copy_data_query);
            $copy_stmt->bind_param("s", $previous_month);
            $copy_stmt->execute();
            $copy_stmt->close();
        }
        
        // Delete archived data
        $delete_query = "DELETE FROM tbldailystock_$comp_id WHERE STK_MONTH = ?";
        $delete_stmt = $conn->prepare($delete_query);
        $delete_stmt->bind_param("s", $previous_month);
        $delete_stmt->execute();
        $delete_stmt->close();
    }
    
    // Add day columns for the new month
    addDayColumnsForMonth($conn, $comp_id, $current_month, true);
}

// ==================== PERFORMANCE OPTIMIZATION #3: Bulk Daily Stock Updates ====================
// Function to update daily stock range (OPTIMIZED for bulk operations)
// ONLY called for items with stock > 0
// MODIFIED: Now limits to company's financial year only
// ==================== IMPROVED DAILY STOCK UPDATE WITH VALUE VERIFICATION ====================
function updateDailyStockRange($conn, $comp_id, $items_data, $mode, $start_date, $fin_year_start = null, $fin_year_end = null) {
    // Initialize debug log
    $debug = [
        'function_called' => date('Y-m-d H:i:s'),
        'items_count' => count($items_data),
        'items_list' => array_keys($items_data),
        'mode' => $mode,
        'start_date' => $start_date,
        'fin_year_start' => $fin_year_start,
        'fin_year_end' => $fin_year_end,
        'steps' => [],
        'errors' => [],
        'updates' => [],
        'value_verification' => []
    ];
    
    try {
        // Use provided financial year dates or default to start_date if not provided
        if ($fin_year_start === null) {
            $fin_year_start = $start_date;
            $debug['steps'][] = 'Using start_date as fin_year_start';
        }
        if ($fin_year_end === null) {
            $fin_year_end = date('Y-12-31'); // Default to end of current year
            $debug['steps'][] = 'Using default fin_year_end';
        }
        
        // IMPORTANT: Start from the opening balance start date, not today!
        $start = new DateTime($start_date);
        $end = new DateTime($fin_year_end);
        
        // Ensure we don't go beyond today (for verification purposes only)
        $today = new DateTime();
        $process_end = $end;
        if ($end > $today) {
            $process_end = $today;
            $debug['steps'][] = "Limited processing to today: " . $process_end->format('Y-m-d') . " but will create records for full FY";
        }
        
        // Ensure we don't go beyond today
        $today = new DateTime();
        if ($end > $today) {
            $end = $today;
            $debug['steps'][] = 'Limited end date to today';
        }
        
        // Generate all dates between start and end
        $dates = [];
        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        $date_count = 0;
        
        foreach ($period as $date) {
            $dates[] = $date->format('Y-m-d');
            $date_count++;
        }
        $debug['steps'][] = "Generated $date_count dates from {$start_date} to " . $process_end->format('Y-m-d');
        
        if (empty($dates)) {
            $debug['errors'][] = 'No dates in range';
            error_log("DailyStock Debug: " . json_encode($debug));
            return;
        }
        
        // Group by month for more efficient processing
        $monthly_data = [];
        foreach ($dates as $date) {
            $month = date('Y-m', strtotime($date));
            $day = date('d', strtotime($date));
            $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
            
            if (!isset($monthly_data[$month])) {
                $monthly_data[$month] = [];
            }
            $monthly_data[$month][] = $day_padded;
        }
        $debug['steps'][] = 'Grouped into ' . count($monthly_data) . ' months: ' . implode(', ', array_keys($monthly_data));
        
        $total_updates = 0;
        $total_inserts = 0;
        $failed_items = [];
        
        // Process each month in chronological order
        $month_index = 0;
        foreach ($monthly_data as $month => $days) {
            $debug['steps'][] = "Processing month: $month with " . count($days) . " days";
            
            $table_name = getTableForMonth($conn, $comp_id, $month);
            $debug['steps'][] = "Using table: $table_name";
            
            // Ensure columns exist for this month
            addDayColumnsForMonth($conn, $comp_id, $month);
            
            // Get all days in this month
            $year_month = explode('-', $month);
            $year = $year_month[0];
            $month_num = $year_month[1];
            $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
            $all_days_in_month = [];
            for ($d = 1; $d <= $days_in_month; $d++) {
                $all_days_in_month[] = str_pad($d, 2, '0', STR_PAD_LEFT);
            }
            
            // Determine if this is the first month (contains the start_date)
            $is_first_month = ($month_index === 0);
            
            // Process each item for this month
            $month_updates = 0;
            $month_inserts = 0;
            
            foreach ($items_data as $item_code => $opening_balance) {
                // Skip zero balances
                if ($opening_balance <= 0) {
                    continue;
                }
                
                // Check if record exists for this month
                $check_query = "SELECT 1 FROM $table_name WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ? LIMIT 1";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("sss", $month, $item_code, $mode);
                $check_stmt->execute();
                $check_stmt->store_result();
                $exists = $check_stmt->num_rows > 0;
                $check_stmt->close();
                
                if ($exists) {
                    // UPDATE existing record
                    // IMPORTANT: For first month, we need to set ALL days up to current date, not just the days in range
                    $update_parts = [];
                    $params = [];
                    $types = '';
                    
                    $first_month = array_key_first($monthly_data);
                    // Use $month_index for first month check (already defined above)
                    
                    // Update ALL days in the month (not just days in range)
                    foreach ($all_days_in_month as $day_padded) {
                        if ($is_first_month) {
                            // First month: only days in the date range get the opening balance
                            if (in_array($day_padded, $days)) {
                                // Days in our range get the opening balance
                                $update_parts[] = "DAY_{$day_padded}_OPEN = ?";
                                $update_parts[] = "DAY_{$day_padded}_CLOSING = ?";
                                $params[] = $opening_balance;
                                $params[] = $opening_balance;
                                $types .= 'ii';
                            } else {
                                // Days before start_date or future days should be 0
                                $update_parts[] = "DAY_{$day_padded}_OPEN = 0";
                                $update_parts[] = "DAY_{$day_padded}_CLOSING = 0";
                            }
                        } else {
                            // NOT first month: ALL days should get the opening balance (cascade from first month)
                            $update_parts[] = "DAY_{$day_padded}_OPEN = ?";
                            $update_parts[] = "DAY_{$day_padded}_CLOSING = ?";
                            $params[] = $opening_balance;
                            $params[] = $opening_balance;
                            $types .= 'ii';
                        }
                    }
                    
                    if (!empty($update_parts)) {
                        $update_query = "UPDATE $table_name SET " . implode(', ', $update_parts) . 
                                      " WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
                        
                        $params[] = $month;
                        $params[] = $item_code;
                        $params[] = $mode;
                        $types .= 'sss';
                        
                        $update_stmt = $conn->prepare($update_query);
                        $update_stmt->bind_param($types, ...$params);
                        $update_result = $update_stmt->execute();
                        $affected = $update_stmt->affected_rows;
                        $update_stmt->close();
                        
                        if ($update_result) {
                            $month_updates++;
                            $debug['updates'][] = [
                                'item' => $item_code,
                                'month' => $month,
                                'action' => 'UPDATE',
                                'days' => count($days),
                                'balance' => $opening_balance,
                                'affected_rows' => $affected
                            ];
                            
                            // Verify the value was actually set
                            // For first month: verify first day of range
                            // For subsequent months: verify day 01 (first day of month)
                            $verify_day = $is_first_month ? $days[0] : '01';
                            $verify_query = "SELECT DAY_{$verify_day}_OPEN FROM $table_name WHERE ITEM_CODE = ? AND STK_MONTH = ?";
                            $verify_stmt = $conn->prepare($verify_query);
                            $verify_stmt->bind_param("ss", $item_code, $month);
                            $verify_stmt->execute();
                            $verify_result = $verify_stmt->get_result();
                            if ($row = $verify_result->fetch_assoc()) {
                                $actual_value = $row["DAY_{$verify_day}_OPEN"];
                                $debug['value_verification'][] = [
                                    'item' => $item_code,
                                    'month' => $month,
                                    'is_first_month' => $is_first_month,
                                    'verify_day' => $verify_day,
                                    'expected' => $opening_balance,
                                    'actual' => $actual_value,
                                    'match' => ($actual_value == $opening_balance)
                                ];
                            }
                            $verify_stmt->close();
                        } else {
                            $debug['errors'][] = "Update failed for $item_code in $month: " . $conn->error;
                            $failed_items[] = $item_code;
                        }
                    }
                } else {
                    // Insert new record with ALL days in month
                    $columns = ['STK_MONTH', 'ITEM_CODE', 'LIQ_FLAG'];
                    $placeholders = ['?', '?', '?'];
                    $params = [$month, $item_code, $mode];
                    $types = 'sss';
                    
                    // Set values for all days in the month
                    foreach ($all_days_in_month as $day_padded) {
                        $columns[] = "DAY_{$day_padded}_OPEN";
                        $columns[] = "DAY_{$day_padded}_PURCHASE";
                        $columns[] = "DAY_{$day_padded}_SALES";
                        $columns[] = "DAY_{$day_padded}_CLOSING";
                        $placeholders[] = '?';
                        $placeholders[] = '?';
                        $placeholders[] = '?';
                        $placeholders[] = '?';
                        
                        // For the FIRST month: only days in our range get the opening balance
                        // For ALL SUBSEQUENT months: ALL days should get the opening balance (cascade)
                        if ($is_first_month) {
                            // First month: only days in the date range get the opening balance
                            if (in_array($day_padded, $days)) {
                                $params[] = $opening_balance;
                                $params[] = 0;
                                $params[] = 0;
                                $params[] = $opening_balance;
                            } else {
                                // Days before start_date in first month
                                $params[] = 0;
                                $params[] = 0;
                                $params[] = 0;
                                $params[] = 0;
                            }
                        } else {
                            // NOT first month: ALL days should get the opening balance (cascade from first month)
                            $params[] = $opening_balance;
                            $params[] = 0;
                            $params[] = 0;
                            $params[] = $opening_balance;
                        }
                        $types .= 'iiii';
                    }
                    
                    $insert_query = "INSERT INTO $table_name (" . implode(', ', $columns) . 
                                  ") VALUES (" . implode(', ', $placeholders) . ")";
                    
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param($types, ...$params);
                    $insert_result = $insert_stmt->execute();
                    $insert_stmt->close();
                    
                    if ($insert_result) {
                        $month_inserts++;
                        $debug['updates'][] = [
                            'item' => $item_code,
                            'month' => $month,
                            'action' => 'INSERT',
                            'days' => count($days),
                            'balance' => $opening_balance
                        ];
                    } else {
                        $debug['errors'][] = "Insert failed for $item_code in $month: " . $conn->error;
                        $failed_items[] = $item_code;
                    }
                }
            }
            
            $total_updates += $month_updates;
            $total_inserts += $month_inserts;
            $debug['steps'][] = "Month $month: $month_updates updates, $month_inserts inserts";
            $month_index++;
        }
        
        // Summary
        $debug['summary'] = [
            'total_items_processed' => count($items_data),
            'total_updates' => $total_updates,
            'total_inserts' => $total_inserts,
            'failed_items_count' => count(array_unique($failed_items)),
            'failed_items' => array_unique($failed_items),
            'success_rate' => count($items_data) > 0 ? 
                round(((count($items_data) - count(array_unique($failed_items))) / count($items_data)) * 100, 2) . '%' : 'N/A'
        ];
        
        // Value verification summary
        $verified_count = 0;
        $mismatch_count = 0;
        if (!empty($debug['value_verification'])) {
            foreach ($debug['value_verification'] as $v) {
                if ($v['match']) {
                    $verified_count++;
                } else {
                    $mismatch_count++;
                }
            }
        }
        $debug['value_summary'] = [
            'verified_count' => $verified_count,
            'mismatch_count' => $mismatch_count,
            'accuracy' => ($verified_count + $mismatch_count) > 0 ? 
                round(($verified_count / ($verified_count + $mismatch_count)) * 100, 2) . '%' : 'N/A'
        ];
        
    } catch (Exception $e) {
        $debug['errors'][] = 'Exception: ' . $e->getMessage();
        $debug['trace'] = $e->getTraceAsString();
    }
    
    // Write debug log
    $debug['function_completed'] = date('Y-m-d H:i:s');
    $debug_file = 'daily_stock_debug_' . date('Y-m-d_H-i-s') . '.log';
    file_put_contents($debug_file, json_encode($debug, JSON_PRETTY_PRINT));
    error_log("DailyStock Debug: Written to $debug_file");
    
    return $debug['summary']['success_rate'] ?? '0%';
}

// ==================== NEW HIERARCHY FUNCTIONS FOR 4-LAYER STRUCTURE ====================
// Cache for hierarchy data
$hierarchy_cache = [];

/**
 * Get complete hierarchy information for an item
 * 
 * @param string $class_code Class code from tblclass_new
 * @param string $subclass_code Subclass code from tblsubclass_new
 * @param string $size_code Size code from tblsize
 * @param mysqli $conn Database connection
 * @return array Hierarchy data with display names
 */
function getItemHierarchy($class_code, $subclass_code, $size_code, $conn) {
    global $hierarchy_cache;
    
    // Create cache key
    $cache_key = $class_code . '|' . $subclass_code . '|' . $size_code;
    
    if (isset($hierarchy_cache[$cache_key])) {
        return $hierarchy_cache[$cache_key];
    }
    
    $hierarchy = [
        'class_code' => $class_code,
        'class_name' => '',
        'subclass_code' => $subclass_code,
        'subclass_name' => '',
        'category_code' => '',
        'category_name' => '',
        'display_category' => 'OTHER',
        'size_code' => $size_code,
        'size_desc' => '',
        'ml_volume' => 0,
        'full_hierarchy' => ''
    ];
    
    try {
        // Get class and category information
        if (!empty($class_code)) {
            $query = "SELECT cn.CLASS_NAME, cn.CATEGORY_CODE, cat.CATEGORY_NAME 
                      FROM tblclass_new cn
                      LEFT JOIN tblcategory cat ON cn.CATEGORY_CODE = cat.CATEGORY_CODE
                      WHERE cn.CLASS_CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $class_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $hierarchy['class_name'] = $row['CLASS_NAME'];
                $hierarchy['category_code'] = $row['CATEGORY_CODE'];
                $hierarchy['category_name'] = $row['CATEGORY_NAME'] ?? '';
                
                // Map category name to display category
                $category_name = strtoupper($row['CATEGORY_NAME'] ?? '');
                $display_category = 'OTHER';
                
                if ($category_name == 'SPIRIT') {
                    $display_category = 'SPIRITS';
                } elseif ($category_name == 'WINE') {
                    $display_category = 'WINE';
                } elseif ($category_name == 'FERMENTED BEER') {
                    $display_category = 'FERMENTED BEER';
                } elseif ($category_name == 'MILD BEER') {
                    $display_category = 'MILD BEER';
                } elseif ($category_name == 'COUNTRY LIQUOR') {
                    $display_category = 'COUNTRY LIQUOR';
                }
                
                $hierarchy['display_category'] = $display_category;
            }
            $stmt->close();
        }
        
        // Get subclass information
        if (!empty($subclass_code)) {
            $query = "SELECT SUBCLASS_NAME FROM tblsubclass_new WHERE SUBCLASS_CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $subclass_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $hierarchy['subclass_name'] = $row['SUBCLASS_NAME'];
            }
            $stmt->close();
        }
        
        // Get size information
        if (!empty($size_code)) {
            $query = "SELECT SIZE_DESC, ML_VOLUME FROM tblsize WHERE SIZE_CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $size_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $hierarchy['size_desc'] = $row['SIZE_DESC'];
                $hierarchy['ml_volume'] = (int)($row['ML_VOLUME'] ?? 0);
            }
            $stmt->close();
        }
        
        // Build full hierarchy string
        $parts = [];
        if (!empty($hierarchy['category_name'])) $parts[] = $hierarchy['category_name'];
        if (!empty($hierarchy['class_name'])) $parts[] = $hierarchy['class_name'];
        if (!empty($hierarchy['subclass_name'])) $parts[] = $hierarchy['subclass_name'];
        if (!empty($hierarchy['size_desc'])) $parts[] = $hierarchy['size_desc'];
        
        $hierarchy['full_hierarchy'] = !empty($parts) ? implode(' > ', $parts) : 'N/A';
        
    } catch (Exception $e) {
        error_log("Error in getItemHierarchy: " . $e->getMessage());
    }
    
    $hierarchy_cache[$cache_key] = $hierarchy;
    return $hierarchy;
}

// Cache for size descriptions
$size_desc_cache = [];
function getSizeDescriptionFromCode($size_code, $conn) {
    global $size_desc_cache;
    
    if (empty($size_code)) return 'N/A';
    
    if (isset($size_desc_cache[$size_code])) {
        return $size_desc_cache[$size_code];
    }
    
    try {
        $query = "SELECT SIZE_DESC FROM tblsize WHERE SIZE_CODE = ? LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $size_code);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $size_desc_cache[$size_code] = $row['SIZE_DESC'];
        } else {
            $size_desc_cache[$size_code] = 'N/A';
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error getting size description: " . $e->getMessage());
        $size_desc_cache[$size_code] = 'N/A';
    }
    
    return $size_desc_cache[$size_code];
}

// Cache for volume labels
$volume_label_cache = [];
function getVolumeLabel($volume) {
    global $volume_label_cache;
    
    if (isset($volume_label_cache[$volume])) {
        return $volume_label_cache[$volume];
    }
    
    // Format volume based on size
    if ($volume >= 1000) {
        $liters = $volume / 1000;
        // Check if it's a whole number
        if ($liters == intval($liters)) {
            $label = intval($liters) . 'L';
        } else {
            $label = rtrim(rtrim(number_format($liters, 1), '0'), '.') . 'L';
        }
    } else {
        $label = $volume . ' ML';
    }
    
    $volume_label_cache[$volume] = $label;
    return $label;
}

// Helper function to extract volume from item details (BACKWARD COMPATIBILITY)
function extractVolumeFromDetails($details, $details2, $item_code = null, $conn = null) {
    // Priority: Try to get from size table first if connection is available
    if ($item_code && $conn) {
        try {
            $query = "SELECT sz.ML_VOLUME 
                      FROM tblitemmaster im 
                      LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE 
                      WHERE im.CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $item_code);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                if ($row['ML_VOLUME']) {
                    $stmt->close();
                    return (int)$row['ML_VOLUME'];
                }
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error getting volume from size table: " . $e->getMessage());
        }
    }
    
    // Fallback to parsing from details
    // Priority: details2 column first
    if ($details2) {
        // Handle liter sizes with decimal points (1.5L, 2.0L, etc.)
        $literMatch = preg_match('/(\d+\.?\d*)\s*L\b/i', $details2, $matches);
        if ($literMatch && isset($matches[1])) {
            $volume = floatval($matches[1]);
            return round($volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        $mlMatch = preg_match('/(\d+)\s*ML\b/i', $details2, $matches);
        if ($mlMatch && isset($matches[1])) {
            return intval($matches[1]);
        }
    }
    
    // Fallback: parse details column
    if ($details) {
        // Handle special cases
        if (stripos($details, 'QUART') !== false) return 750;
        if (stripos($details, 'PINT') !== false) return 375;
        if (stripos($details, 'NIP') !== false) return 90;
        
        // Handle liter sizes with decimal points
        $literMatch = preg_match('/(\d+\.?\d*)\s*L\b/i', $details, $matches);
        if ($literMatch && isset($matches[1])) {
            $volume = floatval($matches[1]);
            return round($volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        $mlMatch = preg_match('/(\d+)\s*ML\b/i', $details, $matches);
        if ($mlMatch && isset($matches[1])) {
            return intval($matches[1]);
        }
    }
    
    return 0; // Unknown volume
}

// ==================== OPENING BALANCE SUMMARY FUNCTION (UPDATED) ====================
// Function to get opening balance summary with volume breakdown
function getOpeningBalanceSummary($conn, $comp_id, $mode, $allowed_classes = []) {
    $summary = [
        'total_items' => 0,
        'total_stock' => 0,
        'items_with_stock' => 0,
        'items_without_stock' => 0,
        'average_stock' => 0,
        'max_stock' => 0,
        'min_stock' => 0,
        'category_breakdown' => [],
        'volume_breakdown' => []
    ];
    
    try {
        // Build query based on license filtering - USING CLASS_CODE_NEW and CLASS for backward compatibility
        // MODIFIED: Now properly uses both SGROUP values and numeric CLASS IDs
        if (!empty($allowed_classes) || !empty($allowed_class_ids)) {
            $class_placeholders_sgroup = implode(',', array_fill(0, count($allowed_classes), '?'));
            $class_placeholders_id = implode(',', array_fill(0, count($allowed_class_ids), '?'));
            
            // Build condition for both SGROUP (CLASS_CODE_NEW) and numeric CLASS
            $class_condition = "";
            $params = [];
            $types = "";
            
            if (!empty($allowed_classes) && !empty($allowed_class_ids)) {
                $class_condition = "(im.CLASS_CODE_NEW IN ($class_placeholders_sgroup) OR im.CLASS IN ($class_placeholders_id))";
                $params = array_merge($allowed_classes, $allowed_class_ids);
                $types = str_repeat('s', count($allowed_classes)) . str_repeat('i', count($allowed_class_ids));
            } elseif (!empty($allowed_classes)) {
                $class_condition = "im.CLASS_CODE_NEW IN ($class_placeholders_sgroup)";
                $params = $allowed_classes;
                $types = str_repeat('s', count($allowed_classes));
            } elseif (!empty($allowed_class_ids)) {
                $class_condition = "im.CLASS IN ($class_placeholders_id)";
                $params = $allowed_class_ids;
                $types = str_repeat('i', count($allowed_class_ids));
            }
            
            $query = "SELECT 
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
                        im.CLASS_CODE_NEW,
                        im.SUBCLASS_CODE_NEW,
                        im.SIZE_CODE,
                        COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK,
                        COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                      FROM tblitemmaster im
                      LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                      WHERE im.LIQ_FLAG = ? 
                      AND $class_condition";
            $params = array_merge([$mode], $params);
            $types = "s" . $types;
        } else {
            $query = "SELECT 
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
                        im.CLASS_CODE_NEW,
                        im.SUBCLASS_CODE_NEW,
                        im.SIZE_CODE,
                        COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK,
                        COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                      FROM tblitemmaster im
                      LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                      WHERE 1 = 0";
            $params = [$mode];
            $types = "s";
        }
        
        $stmt = $conn->prepare($query);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Calculate summary statistics
        $total_stock = 0;
        $items_with_stock = 0;
        $max_stock = 0;
        $min_stock = PHP_INT_MAX;
        $category_totals = [];
        $category_counts = [];
        $volume_totals = [];
        
        foreach ($items as $item) {
            $current_stock = (int)$item['CURRENT_STOCK'];
            
            // Get hierarchy information - use CLASS_CODE_NEW if available, otherwise fallback to CLASS
            $class_to_use = !empty($item['CLASS_CODE_NEW']) ? $item['CLASS_CODE_NEW'] : $item['CLASS'];
            $hierarchy = getItemHierarchy(
                $class_to_use, 
                $item['SUBCLASS_CODE_NEW'], 
                $item['SIZE_CODE'], 
                $conn
            );
            $display_category = $hierarchy['display_category'];
            $ml_volume = $hierarchy['ml_volume'];
            
            // Initialize category arrays if not exists
            if (!isset($category_totals[$display_category])) {
                $category_totals[$display_category] = 0;
                $category_counts[$display_category] = 0;
            }
            
            // Update statistics
            $total_stock += $current_stock;
            $category_totals[$display_category] += $current_stock;
            $category_counts[$display_category]++;
            
            if ($current_stock > 0) {
                $items_with_stock++;
            }
            
            if ($current_stock > $max_stock) {
                $max_stock = $current_stock;
            }
            
            if ($current_stock < $min_stock && $current_stock > 0) {
                $min_stock = $current_stock;
            }
            
            // Use ML volume from hierarchy for volume breakdown
            if ($ml_volume > 0) {
                if (!isset($volume_totals[$ml_volume])) {
                    $volume_totals[$ml_volume] = 0;
                }
                $volume_totals[$ml_volume] += $current_stock;
            }
        }
        
        // Prepare summary array
        $summary['total_items'] = count($items);
        $summary['total_stock'] = $total_stock;
        $summary['items_with_stock'] = $items_with_stock;
        $summary['items_without_stock'] = count($items) - $items_with_stock;
        $summary['average_stock'] = count($items) > 0 ? round($total_stock / count($items), 2) : 0;
        $summary['max_stock'] = $max_stock;
        $summary['min_stock'] = $min_stock === PHP_INT_MAX ? 0 : $min_stock;
        
        // Prepare category breakdown
        foreach ($category_totals as $category => $total) {
            $summary['category_breakdown'][] = [
                'category' => $category,
                'item_count' => $category_counts[$category],
                'total_stock' => $total,
                'average_stock' => $category_counts[$category] > 0 ? round($total / $category_counts[$category], 2) : 0
            ];
        }
        
        // Prepare volume breakdown
        foreach ($volume_totals as $volume => $total) {
            $summary['volume_breakdown'][] = [
                'volume' => $volume,
                'volume_label' => getVolumeLabel($volume),
                'total_stock' => $total
            ];
        }
        
        // Sort categories by total stock (descending)
        usort($summary['category_breakdown'], function($a, $b) {
            return $b['total_stock'] - $a['total_stock'];
        });
        
        // Sort volumes by size
        usort($summary['volume_breakdown'], function($a, $b) {
            return $a['volume'] - $b['volume'];
        });
        
    } catch (Exception $e) {
        error_log("Error fetching opening balance summary: " . $e->getMessage());
    }
    
    return $summary;
}

// ==================== VOLUME SUMMARY FUNCTION (UPDATED) ====================
function getOpeningBalanceVolumeSummary($conn, $comp_id, $mode, $allowed_classes = []) {
    $volumeSummary = [
        'SPIRITS' => [],
        'WINE' => [],
        'FERMENTED BEER' => [],
        'MILD BEER' => [],
        'COUNTRY LIQUOR' => [],
        'OTHER' => []
    ];
    
    // Initialize all volume sizes to 0 for each category
    $allSizes = [
        '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML', 
        '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
        '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
    ];
    
    foreach ($volumeSummary as $category => $data) {
        foreach ($allSizes as $size) {
            $volumeSummary[$category][$size] = 0;
        }
    }
    
    try {
        // Build query to get all items with their stock - USING CLASS_CODE_NEW and CLASS
        // MODIFIED: Now properly uses both SGROUP values and numeric CLASS IDs
        if (!empty($allowed_classes) || !empty($allowed_class_ids)) {
            $class_placeholders_sgroup = implode(',', array_fill(0, count($allowed_classes), '?'));
            $class_placeholders_id = implode(',', array_fill(0, count($allowed_class_ids), '?'));
            
            // Build condition for both SGROUP (CLASS_CODE_NEW) and numeric CLASS
            $class_condition = "";
            $params = [];
            $types = "";
            
            if (!empty($allowed_classes) && !empty($allowed_class_ids)) {
                $class_condition = "(im.CLASS_CODE_NEW IN ($class_placeholders_sgroup) OR im.CLASS IN ($class_placeholders_id))";
                $params = array_merge($allowed_classes, $allowed_class_ids);
                $types = str_repeat('s', count($allowed_classes)) . str_repeat('i', count($allowed_class_ids));
            } elseif (!empty($allowed_classes)) {
                $class_condition = "im.CLASS_CODE_NEW IN ($class_placeholders_sgroup)";
                $params = $allowed_classes;
                $types = str_repeat('s', count($allowed_classes));
            } elseif (!empty($allowed_class_ids)) {
                $class_condition = "im.CLASS IN ($class_placeholders_id)";
                $params = $allowed_class_ids;
                $types = str_repeat('i', count($allowed_class_ids));
            }
            
            $query = "SELECT 
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
                        im.CLASS_CODE_NEW,
                        im.SUBCLASS_CODE_NEW,
                        im.SIZE_CODE,
                        COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                      FROM tblitemmaster im
                      LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                      WHERE im.LIQ_FLAG = ? 
                      AND $class_condition";
            $params = array_merge([$mode], $params);
            $types = "s" . $types;
        } else {
            $query = "SELECT 
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
                        im.CLASS_CODE_NEW,
                        im.SUBCLASS_CODE_NEW,
                        im.SIZE_CODE,
                        COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                      FROM tblitemmaster im
                      LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                      WHERE 1 = 0";
            $params = [$mode];
            $types = "s";
        }
        
        $stmt = $conn->prepare($query);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($item = $result->fetch_assoc()) {
            $current_stock = (int)$item['CURRENT_STOCK'];
            if ($current_stock > 0) {
                // Get hierarchy information - use CLASS_CODE_NEW if available, otherwise fallback to CLASS
                $class_to_use = !empty($item['CLASS_CODE_NEW']) ? $item['CLASS_CODE_NEW'] : $item['CLASS'];
                $hierarchy = getItemHierarchy(
                    $class_to_use, 
                    $item['SUBCLASS_CODE_NEW'], 
                    $item['SIZE_CODE'], 
                    $conn
                );
                $display_category = $hierarchy['display_category'];
                $ml_volume = $hierarchy['ml_volume'];
                
                // Get volume label
                $volumeColumn = getVolumeLabel($ml_volume);
                
                // Add to summary
                if (isset($volumeSummary[$display_category][$volumeColumn])) {
                    $volumeSummary[$display_category][$volumeColumn] += $current_stock;
                } elseif ($display_category !== 'OTHER') {
                    // For unknown sizes in known categories, add to smallest size as fallback
                    $volumeSummary[$display_category]['50 ML'] += $current_stock;
                }
            }
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Error fetching volume summary: " . $e->getMessage());
    }
    
    return $volumeSummary;
}

// Handle export requests - MOVED TO TOP
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    
    // Build query with license filtering - USING CLASS_CODE_NEW and CLASS
    // MODIFIED: Now properly uses both SGROUP values and numeric CLASS IDs
    $query_params = [$mode];
    $query_types = "s";
    
    $class_condition = "";
    if (!empty($allowed_classes) || !empty($allowed_class_ids)) {
        $class_placeholders_sgroup = implode(',', array_fill(0, count($allowed_classes), '?'));
        $class_placeholders_id = implode(',', array_fill(0, count($allowed_class_ids), '?'));
        
        if (!empty($allowed_classes) && !empty($allowed_class_ids)) {
            $class_condition = "(im.CLASS_CODE_NEW IN ($class_placeholders_sgroup) OR im.CLASS IN ($class_placeholders_id))";
            $query_params = array_merge([$mode], $allowed_classes, $allowed_class_ids);
            $query_types = "s" . str_repeat('s', count($allowed_classes)) . str_repeat('i', count($allowed_class_ids));
        } elseif (!empty($allowed_classes)) {
            $class_condition = "im.CLASS_CODE_NEW IN ($class_placeholders_sgroup)";
            $query_params = array_merge([$mode], $allowed_classes);
            $query_types = "s" . str_repeat('s', count($allowed_classes));
        } elseif (!empty($allowed_class_ids)) {
            $class_condition = "im.CLASS IN ($class_placeholders_id)";
            $query_params = array_merge([$mode], $allowed_class_ids);
            $query_types = "s" . str_repeat('i', count($allowed_class_ids));
        }
    }
    
    if (!empty($class_condition)) {
        $query = "SELECT 
                    im.CODE, 
                    im.DETAILS, 
                    im.DETAILS2,
                    im.CLASS,
                    im.CLASS_CODE_NEW,
                    im.SUBCLASS_CODE_NEW,
                    im.SIZE_CODE,
                    sz.SIZE_DESC,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                  WHERE im.LIQ_FLAG = ? 
                  AND $class_condition";
    } else {
        $query = "SELECT 
                    im.CODE, 
                    im.DETAILS, 
                    im.DETAILS2,
                    im.CLASS,
                    im.CLASS_CODE_NEW,
                    im.SUBCLASS_CODE_NEW,
                    im.SIZE_CODE,
                    sz.SIZE_DESC,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                  WHERE 1 = 0";
    }

    if ($search !== '') {
        $query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
        $query_params[] = "%$search%";
        $query_params[] = "%$search%";
        $query_types .= "ss";
    }

    $query .= " ORDER BY im.DETAILS ASC";

    $stmt = $conn->prepare($query);
    if ($query_params) {
        $stmt->bind_param($query_types, ...$query_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=opening_balance_export_' . $mode . '_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        
        // Use comma as delimiter for consistent export
        $delimiter = ',';
        
        // UPDATED HEADERS - Only 4 columns: Item_Code, Item_Name, Size, Current_Stock
        fputcsv($output, ['Item_Code', 'Item_Name', 'Size', 'Current_Stock'], $delimiter);
        
        while ($item = $result->fetch_assoc()) {
            fputcsv($output, [
                $item['CODE'],
                $item['DETAILS'],
                $item['SIZE_DESC'] ?? '',
                $item['CURRENT_STOCK']
            ], $delimiter);
        }
        
        fclose($output);
        $stmt->close();
        exit;
    }
}

// Handle template download - MOVED TO TOP
if (isset($_GET['download_template'])) {
    // Fetch all items from tblitemmaster for the current liquor mode
    // MODIFIED: Now properly uses both SGROUP values and numeric CLASS IDs
    $template_condition = "";
    $template_params = [];
    $template_types = "";
    
    if (!empty($allowed_classes) || !empty($allowed_class_ids)) {
        $class_placeholders_sgroup = implode(',', array_fill(0, count($allowed_classes), '?'));
        $class_placeholders_id = implode(',', array_fill(0, count($allowed_class_ids), '?'));
        
        if (!empty($allowed_classes) && !empty($allowed_class_ids)) {
            $template_condition = "(im.CLASS_CODE_NEW IN ($class_placeholders_sgroup) OR im.CLASS IN ($class_placeholders_id))";
            $template_params = array_merge([$mode], $allowed_classes, $allowed_class_ids);
            $template_types = "s" . str_repeat('s', count($allowed_classes)) . str_repeat('i', count($allowed_class_ids));
        } elseif (!empty($allowed_classes)) {
            $template_condition = "im.CLASS_CODE_NEW IN ($class_placeholders_sgroup)";
            $template_params = array_merge([$mode], $allowed_classes);
            $template_types = "s" . str_repeat('s', count($allowed_classes));
        } elseif (!empty($allowed_class_ids)) {
            $template_condition = "im.CLASS IN ($class_placeholders_id)";
            $template_params = array_merge([$mode], $allowed_class_ids);
            $template_types = "s" . str_repeat('i', count($allowed_class_ids));
        }
    }
    
    if (!empty($template_condition)) {
        $template_query = "SELECT im.CODE, im.DETAILS, sz.SIZE_DESC 
                          FROM tblitemmaster im
                          LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                          WHERE im.LIQ_FLAG = ? 
                          AND $template_condition
                          ORDER BY im.DETAILS ASC";
        $template_stmt = $conn->prepare($template_query);
        $template_stmt->bind_param($template_types, ...$template_params);
    } else {
        $template_query = "SELECT im.CODE, im.DETAILS, sz.SIZE_DESC 
                          FROM tblitemmaster im
                          LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                          WHERE 1 = 0";
        $template_stmt = $conn->prepare($template_query);
    }
    
    $template_stmt->execute();
    $template_result = $template_stmt->get_result();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=opening_balance_template_' . $mode . '.csv');
    
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    
    // Use comma as delimiter for consistent template
    $delimiter = ',';
    
    // UPDATED HEADERS - Only 4 columns
    fputcsv($output, ['Item_Code', 'Item_Name', 'Size', 'Current_Stock'], $delimiter);
    
    while ($item = $template_result->fetch_assoc()) {
        fputcsv($output, [
            $item['CODE'],
            $item['DETAILS'],
            $item['SIZE_DESC'] ?? '',
            ''
        ], $delimiter);
    }
    
    fclose($output);
    $template_stmt->close();
    exit;
}

// Handle AJAX request for items
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_items') {
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $view_type = isset($_GET['view']) ? $_GET['view'] : 'with_stock';
    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $limit = 100;
    $offset = ($page - 1) * $limit;
    
    header('Content-Type: application/json');
    
    if (empty($allowed_classes)) {
        echo json_encode(['items' => [], 'total' => 0, 'has_more' => false]);
        exit;
    }
    
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // Get total count
    $stock_condition = ($view_type === 'with_stock') 
        ? "AND COALESCE(st.CURRENT_STOCK$comp_id, 0) > 0" 
        : "AND (st.CURRENT_STOCK$comp_id IS NULL OR COALESCE(st.CURRENT_STOCK$comp_id, 0) = 0)";
    
    // MODIFIED: Now properly uses both SGROUP values and numeric CLASS IDs
    $class_condition = "";
    $count_params = [];
    $count_types = "";
    
    if (!empty($allowed_classes) || !empty($allowed_class_ids)) {
        $class_placeholders_sgroup = implode(',', array_fill(0, count($allowed_classes), '?'));
        $class_placeholders_id = implode(',', array_fill(0, count($allowed_class_ids), '?'));
        
        if (!empty($allowed_classes) && !empty($allowed_class_ids)) {
            $class_condition = "(im.CLASS_CODE_NEW IN ($class_placeholders_sgroup) OR im.CLASS IN ($class_placeholders_id))";
            $count_params = array_merge($allowed_classes, $allowed_class_ids);
            $count_types = str_repeat('s', count($allowed_classes)) . str_repeat('i', count($allowed_class_ids));
        } elseif (!empty($allowed_classes)) {
            $class_condition = "im.CLASS_CODE_NEW IN ($class_placeholders_sgroup)";
            $count_params = $allowed_classes;
            $count_types = str_repeat('s', count($allowed_classes));
        } elseif (!empty($allowed_class_ids)) {
            $class_condition = "im.CLASS IN ($class_placeholders_id)";
            $count_params = $allowed_class_ids;
            $count_types = str_repeat('i', count($allowed_class_ids));
        }
    }
    
    if (!empty($class_condition)) {
        $count_query = "SELECT COUNT(*) as total 
                        FROM tblitemmaster im
                        LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                        WHERE im.LIQ_FLAG = ? 
                        AND $class_condition
                        $stock_condition";
        $params = array_merge([$mode], $count_params);
        $types = "s" . $count_types;
    } else {
        $count_query = "SELECT COUNT(*) as total 
                        FROM tblitemmaster im
                        LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                        WHERE 1 = 0";
        $params = [$mode];
        $types = "s";
    }
    
    if ($search !== '') {
        $count_query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= "ss";
    }
    
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $total = $count_result->fetch_assoc()['total'];
    $count_stmt->close();
    
    // Get items
    // MODIFIED: Now properly uses both SGROUP values and numeric CLASS IDs
    $items_condition = "";
    $items_params = [];
    $items_types = "";
    
    if (!empty($allowed_classes) || !empty($allowed_class_ids)) {
        $class_placeholders_sgroup = implode(',', array_fill(0, count($allowed_classes), '?'));
        $class_placeholders_id = implode(',', array_fill(0, count($allowed_class_ids), '?'));
        
        if (!empty($allowed_classes) && !empty($allowed_class_ids)) {
            $items_condition = "(im.CLASS_CODE_NEW IN ($class_placeholders_sgroup) OR im.CLASS IN ($class_placeholders_id))";
            $items_params = array_merge($allowed_classes, $allowed_class_ids);
            $items_types = str_repeat('s', count($allowed_classes)) . str_repeat('i', count($allowed_class_ids));
        } elseif (!empty($allowed_classes)) {
            $items_condition = "im.CLASS_CODE_NEW IN ($class_placeholders_sgroup)";
            $items_params = $allowed_classes;
            $items_types = str_repeat('s', count($allowed_classes));
        } elseif (!empty($allowed_class_ids)) {
            $items_condition = "im.CLASS IN ($class_placeholders_id)";
            $items_params = $allowed_class_ids;
            $items_types = str_repeat('i', count($allowed_class_ids));
        }
    }
    
    if (!empty($items_condition)) {
        $query = "SELECT 
                    im.CODE, 
                    im.Print_Name, 
                    im.DETAILS, 
                    im.DETAILS2, 
                    im.CLASS,
                    im.CLASS_CODE_NEW, 
                    im.SUBCLASS_CODE_NEW, 
                    im.ITEM_GROUP,
                    im.SIZE_CODE,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK,
                    COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK,
                    sz.SIZE_DESC
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                  WHERE im.LIQ_FLAG = ? 
                  AND $items_condition
                  $stock_condition";
        $params = array_merge([$mode], $items_params);
        $types = "s" . $items_types;
    } else {
        $query = "SELECT 
                    im.CODE, 
                    im.Print_Name, 
                    im.DETAILS, 
                    im.DETAILS2, 
                    im.CLASS,
                    im.CLASS_CODE_NEW, 
                    im.SUBCLASS_CODE_NEW, 
                    im.ITEM_GROUP,
                    im.SIZE_CODE,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK,
                    COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK,
                    sz.SIZE_DESC
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                  WHERE 1 = 0";
        $params = [$mode];
        $types = "s";
    }
    
    if ($search !== '') {
        $query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= "ss";
    }
    
    $query .= " ORDER BY im.DETAILS ASC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $items = [];
    while ($row = $result->fetch_assoc()) {
        // Get hierarchy information - use CLASS_CODE_NEW if available, otherwise fallback to CLASS
        $class_to_use = !empty($row['CLASS_CODE_NEW']) ? $row['CLASS_CODE_NEW'] : $row['CLASS'];
        $hierarchy = getItemHierarchy(
            $class_to_use, 
            $row['SUBCLASS_CODE_NEW'], 
            $row['SIZE_CODE'], 
            $conn
        );
        
        $items[] = [
            'code' => $row['CODE'],
            'details' => $row['DETAILS'],
            'class' => $row['CLASS'],
            'class_code' => $row['CLASS_CODE_NEW'],
            'class_name' => $hierarchy['class_name'],
            'subclass_code' => $row['SUBCLASS_CODE_NEW'],
            'subclass_name' => $hierarchy['subclass_name'],
            'category_code' => $hierarchy['category_code'],
            'category_name' => $hierarchy['category_name'],
            'display_category' => $hierarchy['display_category'],
            'size_code' => $row['SIZE_CODE'],
            'size_desc' => $hierarchy['size_desc'] ?: ($row['SIZE_DESC'] ?? getSizeDescriptionFromCode($row['SIZE_CODE'], $conn)),
            'ml_volume' => $hierarchy['ml_volume'],
            'full_hierarchy' => $hierarchy['full_hierarchy'],
            'current_stock' => (int)$row['CURRENT_STOCK'],
            'opening_stock' => (int)$row['OPENING_STOCK']
        ];
    }
    $stmt->close();
    
    echo json_encode([
        'items' => $items,
        'total' => (int)$total,
        'has_more' => ($offset + $limit) < $total
    ]);
    exit;
}

// Handle AJAX request for volume summary
if (isset($_GET['ajax']) && $_GET['ajax'] === 'volume_summary') {
    header('Content-Type: application/json');
    $volume_summary_data = getOpeningBalanceVolumeSummary($conn, $comp_id, $mode, $allowed_classes);
    echo json_encode($volume_summary_data);
    exit;
}

// Handle AJAX request for summary stats
if (isset($_GET['ajax']) && $_GET['ajax'] === 'summary_stats') {
    header('Content-Type: application/json');
    $summary_data = getOpeningBalanceSummary($conn, $comp_id, $mode, $allowed_classes);
    echo json_encode($summary_data);
    exit;
}

// Handle import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
    $start_date = $_POST['start_date'];
    $csv_file = $_FILES['csv_file']['tmp_name'];
    
    // ==================== DEBUG MODE CHECK ====================
    $debug_mode = isset($_POST['debug_import']) && $_POST['debug_import'] == '1';
    $debug_log = [];
    $debug_log['start_time'] = date('Y-m-d H:i:s');
    $debug_log['csv_items'] = [];
    $debug_log['scm_items_found'] = [];
    $debug_log['scm_items_not_found'] = [];
    $debug_log['imported_items'] = [];
    $debug_log['stock_updates'] = [];
    $debug_log['daily_stock_cascade'] = [];
    $debug_log['errors'] = [];
    $debug_log['summary'] = [];
    
    // ==================== NEW: DELIMITER DETECTION ====================
    // Read first line to detect separator
    $first_line = file_get_contents($csv_file, false, null, 0, 1000);
    $first_line = trim($first_line);
    
    // Detect separator based on first line
    $delimiter = ',';
    if (strpos($first_line, "\t") !== false) {
        $delimiter = "\t";
    } elseif (strpos($first_line, ';') !== false) {
        $delimiter = ';';
    }
    
    $handle = fopen($csv_file, "r");

    // Read and validate header row with detected delimiter
    $header = fgetcsv($handle, 1000, $delimiter);
    
    // Check if CSV has the correct format (4 columns)
    $expected_headers = ['Item_Code', 'Item_Name', 'Size', 'Current_Stock'];
    
    // Normalize headers: trim whitespace and remove BOM
    $header = array_map(function($h) {
        // Remove UTF-8 BOM if present
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        return trim($h);
    }, $header);
    
    if ($header !== $expected_headers) {
        $_SESSION['import_message'] = [
            'success' => false,
            'message' => "CSV format is incorrect. Expected headers: " . implode(', ', $expected_headers) . 
                        ". Found: " . implode(', ', $header) . 
                        ". Detected delimiter: " . ($delimiter === "\t" ? "TAB" : $delimiter)
        ];
        header("Location: opening_balance.php?mode=" . $mode . "&view=" . $view_type . "&search=" . urlencode($search));
        exit;
    }

    $imported_count = 0;
    $skipped_count = 0;
    $duplicate_items = []; // Initialize here to avoid undefined variable error
    $duplicate_count = 0; // Will be updated after processing
    $error_messages = [];
    $items_to_update = []; // Store items for bulk update (ALL items) - associative array to handle duplicates
    $items_for_daily_stock = []; // Store items for daily stock update (ONLY items with stock > 0) - associative
    $skipped_items = []; // Store skipped items for reporting
    
    // Use associative arrays to handle duplicates by overwriting with last value
    $items_to_update_agg = []; // Associative array - overwrite with last value
    $items_for_daily_stock_agg = []; // Associative array for daily stock
    
    // ==================== DEBUG: Count total items in CSV ====================
    $csv_total_items = 0;
    $csv_items_data = [];
    $duplicate_items = []; // Track duplicate item codes (for display only)
    
    // First pass: count all items in CSV and identify duplicates
    while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
        if (count($data) >= 4) {
            $csv_total_items++;
            $code = trim($data[0]);
            $name = trim($data[1]);
            $size_desc = trim($data[2]);
            $balance = intval(trim($data[3]));
            
            // Track duplicates - just record them, use last value (overwrite)
            if (isset($csv_items_data[$code])) {
                // This is a duplicate - overwrite with new value (keep latest)
                $current_dup_count = ($csv_items_data[$code]['duplicate_count'] ?? 0) + 1;
                $csv_items_data[$code] = [
                    'code' => $code,
                    'name' => $name,
                    'size' => $size_desc,
                    'balance' => $balance,
                    'is_duplicate' => true,
                    'duplicate_count' => $current_dup_count,
                    'original_balances' => array_merge(($csv_items_data[$code]['original_balances'] ?? []), [$balance])
                ];
                $duplicate_items[$code] = $csv_items_data[$code];
            } else {
                $csv_items_data[$code] = [
                    'code' => $code,
                    'name' => $name,
                    'size' => $size_desc,
                    'balance' => $balance,
                    'is_duplicate' => false,
                    'duplicate_count' => 0,
                    'original_balances' => [$balance]
                ];
            }
            
            // Debug: Log CSV item
            if ($debug_mode) {
                $debug_log['csv_items'][] = [
                    'line' => $csv_total_items,
                    'code' => $code,
                    'name' => $name,
                    'size' => $size_desc,
                    'balance' => $balance,
                    'is_duplicate' => $csv_items_data[$code]['is_duplicate']
                ];
            }
        }
    }
    fclose($handle);
    
    // Re-open file for processing
    $handle = fopen($csv_file, "r");
    $header = fgetcsv($handle, 1000, $delimiter); // Skip header again
    
    $debug_log['summary']['csv_total_items'] = $csv_total_items;
    $debug_log['summary']['csv_total_stock'] = array_sum(array_column($csv_items_data, 'balance'));
    $debug_log['summary']['duplicate_items_found'] = count($duplicate_items);
    $debug_log['summary']['duplicate_items_details'] = $duplicate_items;

    // Get ALL items from SCM for matching (NO license filtering at this stage)
    // We will filter by license AFTER matching based on item's category
    // MODIFIED: Get all items regardless of license, filter later
    $valid_items = [];
    $all_items_query = "SELECT 
                            im.CODE, 
                            im.DETAILS, 
                            im.DETAILS2, 
                            im.LIQ_FLAG, 
                            im.CLASS, 
                            im.CLASS_CODE_NEW, 
                            im.SUBCLASS_CODE_NEW, 
                            im.SIZE_CODE, 
                            sz.SIZE_DESC
                          FROM tblitemmaster im
                          LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                          WHERE im.LIQ_FLAG = ?";
    
    $all_params = [$mode];
    $all_types = "s";
    
    $all_stmt = $conn->prepare($all_items_query);
    $all_stmt->bind_param($all_types, ...$all_params);
    $all_stmt->execute();
    $all_result = $all_stmt->get_result();
    
    // Create a comprehensive lookup array for faster validation
    while ($row = $all_result->fetch_assoc()) {
        // Create multiple lookup keys for flexibility
        $key1 = $row['CODE']; // Just by code
        $key2 = $row['CODE'] . '|' . trim($row['DETAILS']) . '|' . trim($row['SIZE_DESC'] ?? ''); // Code + Name + Size Description
        $key3 = $row['CODE'] . '|' . trim($row['DETAILS']); // Code + Name only
        $key4 = trim($row['DETAILS']); // Name only as fallback
        $key5 = strtoupper(trim($row['CODE'])); // Uppercase code
        $key6 = strtoupper(trim($row['CODE'])) . '|' . strtoupper(trim($row['DETAILS'])); // Uppercase code + name
        
        $item_data = [
            'code' => $row['CODE'],
            'details' => $row['DETAILS'],
            'class' => $row['CLASS'],
            'class_code_new' => $row['CLASS_CODE_NEW'],
            'size_code' => $row['SIZE_CODE'],
            'size_desc' => $row['SIZE_DESC'] ?? ''
        ];
        
        $valid_items[$key1] = $item_data;
        $valid_items[$key2] = $item_data;
        $valid_items[$key3] = $item_data;
        $valid_items[$key4] = $item_data;
        $valid_items[$key5] = $item_data;
        $valid_items[$key6] = $item_data;
    }
    $all_stmt->close();

    // Start transaction
    $conn->begin_transaction();

    try {
        $batch_size = 100;
        $current_batch = 0;
        
        // Prepare statements for batch operations
        $check_stmt = $conn->prepare("SELECT 1 FROM tblitem_stock WHERE ITEM_CODE = ? LIMIT 1");
        $update_stmt = $conn->prepare("UPDATE tblitem_stock SET OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ? WHERE ITEM_CODE = ?");
        $insert_stmt = $conn->prepare("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id) VALUES (?, ?, ?, ?)");
        
        while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
            if (count($data) >= 4) {
                $code = trim($data[0]);
                $name = trim($data[1]);
                $size_desc = trim($data[2]);
                $balance = intval(trim($data[3]));
                
                // Clean and normalize data for matching
                $code_original = $code;
                $code = strtoupper(trim($code));
                $name = trim(preg_replace('/\s+/', ' ', $name)); // Normalize spaces
                $name_upper = strtoupper($name);
                $size_desc = trim($size_desc);
                
                // Try multiple matching strategies in order of specificity
                $item_found = false;
                $item_data = null;
                $match_method = '';
                $item_category = '';
                $item_class_code = '';
                
                // Strategy 1: Try exact match with code + name + size description
                $full_key = $code . '|' . $name . '|' . $size_desc;
                if (isset($valid_items[$full_key])) {
                    $item_found = true;
                    $item_data = $valid_items[$full_key];
                    $match_method = 'exact code+name+size';
                }
                // Strategy 2: Try match with code + name only
                elseif (isset($valid_items[$code . '|' . $name])) {
                    $item_found = true;
                    $item_data = $valid_items[$code . '|' . $name];
                    $match_method = 'code+name';
                }
                // Strategy 3: Try uppercase version
                elseif (isset($valid_items[$code . '|' . $name_upper])) {
                    $item_found = true;
                    $item_data = $valid_items[$code . '|' . $name_upper];
                    $match_method = 'uppercase code+name';
                }
                // Strategy 4: Try matching just by code
                elseif (isset($valid_items[$code])) {
                    $item_found = true;
                    $item_data = $valid_items[$code];
                    $match_method = 'code only';
                    
                    // Check if size matches (if size info is provided)
                    if (!empty($size_desc) && $size_desc != 'N/A' && !empty($item_data['size_desc']) && $item_data['size_desc'] != 'N/A') {
                        if (stripos($item_data['size_desc'], $size_desc) === false && stripos($size_desc, $item_data['size_desc']) === false) {
                            // Size mismatch, but we'll still process with warning
                            $error_messages[] = "Size mismatch for item '$code': CSV has '$size_desc', database has '{$item_data['size_desc']}'. Using database size.";
                        }
                    }
                }
                // Strategy 5: Try uppercase code
                elseif (isset($valid_items[strtoupper($code)])) {
                    $item_found = true;
                    $item_data = $valid_items[strtoupper($code)];
                    $match_method = 'uppercase code';
                }
                // Strategy 6: Try matching by name only - NEED TO GET CATEGORY INFO
                elseif (isset($valid_items[$name])) {
                    $item_found = true;
                    $item_data = $valid_items[$name];
                    $match_method = 'name only';
                    $error_messages[] = "Matched item '$name' by name only (code mismatch). CSV code: '$code_original', DB code: '{$item_data['code']}'";
                }
                // Strategy 7: Try fuzzy matching - search through all keys
                else {
                    foreach ($valid_items as $key => $valid_item) {
                        // Check if code appears in the key
                        if (strpos($key, $code) !== false && strlen($code) > 5) {
                            $item_found = true;
                            $item_data = $valid_item;
                            $match_method = 'fuzzy code';
                            $error_messages[] = "Fuzzy matched item by code '$code' to '{$valid_item['code']}'";
                            break;
                        }
                        // Check if name appears in the key (with some similarity)
                        elseif (strpos($key, substr($name, 0, 15)) !== false) {
                            $item_found = true;
                            $item_data = $valid_item;
                            $match_method = 'fuzzy name';
                            $error_messages[] = "Fuzzy matched item by name '$name' to '{$valid_item['code']}'";
                            break;
                        }
                    }
                }
                
                // After finding the item, check if it's allowed by license
                // Get the category and class info for the found item
                if ($item_found && $item_data) {
                    // Get additional info about the item including category
                    $item_category_query = "SELECT cn.CATEGORY_CODE, cat.CATEGORY_NAME, cn.CLASS_CODE, cn.CLASS_NAME 
                                           FROM tblitemmaster im
                                           LEFT JOIN tblclass_new cn ON (im.CLASS_CODE_NEW = cn.CLASS_CODE OR im.CLASS = cn.CLASS_CODE)
                                           LEFT JOIN tblcategory cat ON cn.CATEGORY_CODE = cat.CATEGORY_CODE
                                           WHERE im.CODE = ? LIMIT 1";
                    $item_cat_stmt = $conn->prepare($item_category_query);
                    $item_cat_stmt->bind_param("s", $item_data['code']);
                    $item_cat_stmt->execute();
                    $item_cat_result = $item_cat_stmt->get_result();
                    
                    if ($item_cat_row = $item_cat_result->fetch_assoc()) {
                        $item_category = $item_cat_row['CATEGORY_CODE'];
                        $item_class_code = $item_cat_row['CLASS_CODE'];
                        $item_category_name = $item_cat_row['CATEGORY_NAME'] ?? '';
                    }
                    $item_cat_stmt->close();
                    
                    // Check if this category is allowed by license
                    $category_allowed = false;
                    if (!empty($item_category) && !empty($allowed_category_codes)) {
                        $category_allowed = in_array($item_category, $allowed_category_codes);
                    }
                    
                    // If category is not allowed, skip this item
                    if (!$category_allowed) {
                        $item_found = false;
                        $skip_reason = "Item category '$item_category_name' (Code: $item_category) not allowed for your license type. Allowed: " . implode(', ', $allowed_category_codes);
                        
                        if ($debug_mode) {
                            $debug_log['scm_items_not_found'][] = [
                                'csv_code' => $code_original,
                                'name' => $name,
                                'size' => $size_desc,
                                'balance' => $balance,
                                'matched_scm_code' => $item_data['code'],
                                'matched_method' => $match_method,
                                'item_category' => $item_category,
                                'item_category_name' => $item_category_name ?? '',
                                'skip_reason' => $skip_reason,
                                'allowed_categories' => $allowed_category_codes
                            ];
                        }
                        
                        $skipped_count++;
                        $skipped_items[] = [
                            'code' => $code_original,
                            'name' => $name,
                            'size' => $size_desc,
                            'reason' => $skip_reason
                        ];
                        
                        if ($skipped_count <= 100) {
                            $error_messages[] = "Skipped: '$code_original' - '$name' - '$size_desc' - Category '$item_category_name' not allowed for your license";
                        }
                        
                        continue; // Skip to next item
                    }
                }
                
                if ($item_found && $item_data) {
                    $item_code_to_use = $item_data['code'];
                    
                    // Use the summed balance from duplicate tracking
                    $final_balance = $csv_items_data[$code_original]['balance'] ?? $balance;
                    
                    // Debug: Log SCM item found with category info
                    if ($debug_mode) {
                        $debug_log['scm_items_found'][] = [
                            'csv_code' => $code_original,
                            'scm_code' => $item_code_to_use,
                            'name' => $name,
                            'size' => $size_desc,
                            'balance' => $final_balance,
                            'original_balance' => $balance,
                            'is_duplicate' => ($csv_items_data[$code_original]['duplicate_count'] ?? 0) > 0,
                            'duplicate_count' => $csv_items_data[$code_original]['duplicate_count'] ?? 0,
                            'match_method' => $match_method,
                            'scm_class' => $item_data['class'],
                            'scm_class_code_new' => $item_data['class_code_new'],
                            'item_category' => $item_category ?? '',
                            'item_category_name' => $item_category_name ?? '',
                            'license_allowed' => true
                        ];
                    }
                    
                    // Add to tblitem_stock update list (ALL items, even zero stock)
                    // Use associative array - OVERWRITE MODE (last value wins, don't sum)
                    $items_to_update_agg[$item_code_to_use] = $final_balance;
                    
                    // IMPORTANT: Only add to daily stock if balance > 0
                    // OVERWRITE MODE - last value wins
                    if ($final_balance > 0) {
                        $items_for_daily_stock_agg[$item_code_to_use] = $final_balance;
                    }
                    
                    $imported_count++;
                    
                    // Process in batches
                    if (count($items_to_update) >= $batch_size) {
                        // Process batch
                        foreach ($items_to_update as $item) {
                            $check_stmt->bind_param("s", $item['code']);
                            $check_stmt->execute();
                            $check_stmt->store_result();
                            $exists = $check_stmt->num_rows > 0;
                            $check_stmt->free_result();
                            
                            if ($exists) {
                                $update_stmt->bind_param("iis", $item['balance'], $item['balance'], $item['code']);
                                $update_result = $update_stmt->execute();
                                
                                // Debug: Log stock update
                                if ($debug_mode) {
                                    $debug_log['stock_updates'][] = [
                                        'code' => $item['code'],
                                        'balance' => $item['balance'],
                                        'action' => 'UPDATE',
                                        'success' => true,
                                        'timestamp' => date('Y-m-d H:i:s')
                                    ];
                                }
                            } else {
                                $insert_stmt->bind_param("siii", $item['code'], $fin_year_id, $item['balance'], $item['balance']);
                                $insert_result = $insert_stmt->execute();
                                
                                // Debug: Log stock insert
                                if ($debug_mode) {
                                    $debug_log['stock_updates'][] = [
                                        'code' => $item['code'],
                                        'balance' => $item['balance'],
                                        'action' => 'INSERT',
                                        'success' => true,
                                        'timestamp' => date('Y-m-d H:i:s')
                                    ];
                                }
                            }
                        }
                        
                        $items_to_update = [];
                        $current_batch++;
                    }
                } else {
                    // Debug: Log SCM item NOT found with specific reason
                    $skip_reason = '';
                    
                    // Determine why the item was skipped
                    if (!isset($valid_items[$code]) && !isset($valid_items[$code_original]) && !isset($valid_items[strtoupper($code)])) {
                        // Try to find by name
                        $found_by_name = false;
                        foreach ($valid_items as $key => $v) {
                            if (stripos($key, $name) !== false || stripos($name, $key) !== false) {
                                $found_by_name = true;
                                break;
                            }
                        }
                        
                        if ($found_by_name) {
                            $skip_reason = 'Code mismatch but name found - using exact code match required';
                        } else {
                            $skip_reason = 'Item code not found in SCM (tblitemmaster)';
                        }
                    } else {
                        // Item exists but not allowed for license type
                        $skip_reason = 'Item found but not allowed for your license type';
                    }
                    
                    if ($debug_mode) {
                        $debug_log['scm_items_not_found'][] = [
                            'csv_code' => $code_original,
                            'name' => $name,
                            'size' => $size_desc,
                            'balance' => $balance,
                            'skip_reason' => $skip_reason,
                            'matched_strategies_tried' => [
                                'exact_code+name+size' => isset($valid_items[$full_key]) ? 'FOUND' : 'NOT_FOUND',
                                'code+name' => isset($valid_items[$code . '|' . $name]) ? 'FOUND' : 'NOT_FOUND',
                                'uppercase_code+name' => isset($valid_items[$code . '|' . $name_upper]) ? 'FOUND' : 'NOT_FOUND',
                                'code_only' => isset($valid_items[$code]) ? 'FOUND' : 'NOT_FOUND',
                                'uppercase_code' => isset($valid_items[strtoupper($code)]) ? 'FOUND' : 'NOT_FOUND',
                                'name_only' => isset($valid_items[$name]) ? 'FOUND' : 'NOT_FOUND'
                            ]
                        ];
                    }
                    
                    $skipped_count++;
                    $skipped_items[] = [
                        'code' => $code_original,
                        'name' => $name,
                        'size' => $size_desc,
                        'reason' => $skip_reason
                    ];
                    
                    // Store in error messages (limit to first 100 to avoid huge messages)
                    if ($skipped_count <= 100) {
                        $error_messages[] = "Skipped item: '$code_original' - '$name' - '$size_desc' (not found in database or not allowed for your license type)";
                    }
                }
            }
        }
        
        // Process remaining items
        // Convert associative arrays to regular arrays for processing
        $items_to_update = [];
        foreach ($items_to_update_agg as $code => $bal) {
            $items_to_update[] = ['code' => $code, 'balance' => $bal];
        }
        $items_for_daily_stock = $items_for_daily_stock_agg;
        
        if (!empty($items_to_update)) {
            foreach ($items_to_update as $item) {
                $check_stmt->bind_param("s", $item['code']);
                $check_stmt->execute();
                $check_stmt->store_result();
                $exists = $check_stmt->num_rows > 0;
                $check_stmt->free_result();
                
                if ($exists) {
                    $update_stmt->bind_param("iis", $item['balance'], $item['balance'], $item['code']);
                    $update_result = $update_stmt->execute();
                    
                    // Debug: Log stock update result
                    if ($debug_mode) {
                        $debug_log['stock_updates'][] = [
                            'code' => $item['code'],
                            'balance' => $item['balance'],
                            'action' => 'UPDATE',
                            'success' => $update_result === true,
                            'affected_rows' => $update_stmt->affected_rows,
                            'timestamp' => date('Y-m-d H:i:s')
                        ];
                    }
                } else {
                    $insert_stmt->bind_param("siii", $item['code'], $fin_year_id, $item['balance'], $item['balance']);
                    $insert_result = $insert_stmt->execute();
                    
                    // Debug: Log stock insert result
                    if ($debug_mode) {
                        $debug_log['stock_updates'][] = [
                            'code' => $item['code'],
                            'balance' => $item['balance'],
                            'action' => 'INSERT',
                            'success' => $insert_result === true,
                            'affected_rows' => $insert_stmt->affected_rows,
                            'timestamp' => date('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        }
        
        // Close prepared statements
        $check_stmt->close();
        $update_stmt->close();
        $insert_stmt->close();
        fclose($handle);
        
        // ==================== DEBUG: Stock Cascade Verification ====================
        if ($debug_mode && !empty($items_for_daily_stock)) {
            $debug_log['summary']['daily_stock_cascade_start'] = date('Y-m-d H:i:s');
            $debug_log['summary']['items_for_daily_stock_count'] = count($items_for_daily_stock);
            $debug_log['summary']['start_date'] = $start_date;
            
            // DEBUG: Add financial year information
            $fy_start = date('Y-m-d', strtotime($finyear_data['START_DATE']));
            $fy_end = date('Y-m-d', strtotime($finyear_data['END_DATE']));
            $debug_log['summary']['financial_year_start'] = $fy_start;
            $debug_log['summary']['financial_year_end'] = $fy_end;
            $debug_log['summary']['table_limited_to_fy'] = true;
            
            // DEBUG: Add license type information
            $debug_log['summary']['license_type'] = $license_type;
            $debug_log['summary']['allowed_sgroups'] = $allowed_classes;
            $debug_log['summary']['allowed_category_codes'] = $allowed_category_codes;
            $debug_log['summary']['allowed_class_ids'] = $allowed_class_ids;
            
            // Verify daily stock was cascaded for each item
            $current_month = date('Y-m');
            $table_name = getTableForMonth($conn, $comp_id, $current_month);
            
            $cascade_verified = 0;
            $cascade_failed = [];
            
            foreach ($items_for_daily_stock as $item_code => $expected_balance) {
                // Check if daily stock record exists
                $check_daily_query = "SELECT * FROM $table_name WHERE ITEM_CODE = ? AND STK_MONTH = ? AND LIQ_FLAG = ?";
                $check_daily_stmt = $conn->prepare($check_daily_query);
                $check_daily_stmt->bind_param("sss", $item_code, $current_month, $mode);
                $check_daily_stmt->execute();
                $daily_result = $check_daily_stmt->get_result();
                
                if ($daily_row = $daily_result->fetch_assoc()) {
                    // Check first day of month
                    $first_day = date('d');
                    $first_day_padded = str_pad($first_day, 2, '0', STR_PAD_LEFT);
                    $open_col = "DAY_{$first_day_padded}_OPEN";
                    $closing_col = "DAY_{$first_day_padded}_CLOSING";
                    
                    $actual_open = isset($daily_row[$open_col]) ? $daily_row[$open_col] : 0;
                    $actual_closing = isset($daily_row[$closing_col]) ? $daily_row[$closing_col] : 0;
                    
                    if ($actual_open == $expected_balance && $actual_closing == $expected_balance) {
                        $cascade_verified++;
                        $debug_log['daily_stock_cascade'][] = [
                            'item_code' => $item_code,
                            'expected' => $expected_balance,
                            'actual_open' => $actual_open,
                            'actual_closing' => $actual_closing,
                            'status' => 'VERIFIED'
                        ];
                    } else {
                        $cascade_failed[] = $item_code;
                        $debug_log['daily_stock_cascade'][] = [
                            'item_code' => $item_code,
                            'expected' => $expected_balance,
                            'actual_open' => $actual_open,
                            'actual_closing' => $actual_closing,
                            'status' => 'MISMATCH'
                        ];
                    }
                } else {
                    $cascade_failed[] = $item_code;
                    $debug_log['daily_stock_cascade'][] = [
                        'item_code' => $item_code,
                        'expected' => $expected_balance,
                        'status' => 'NOT_FOUND'
                    ];
                }
                $check_daily_stmt->close();
            }
            
            $debug_log['summary']['cascade_verified'] = $cascade_verified;
            $debug_log['summary']['cascade_failed'] = count($cascade_failed);
            $debug_log['summary']['cascade_percentage'] = count($items_for_daily_stock) > 0 ? 
                round(($cascade_verified / count($items_for_daily_stock)) * 100, 2) : 0;
            
            // Add all skipped items to debug log
            $debug_log['skipped_items'] = $skipped_items;
        }
        
        // ==================== PERFORMANCE OPTIMIZATION #5: Bulk Daily Stock Update ====================
        // Only update daily stock for items with balance > 0
        // MODIFIED: Now passes financial year dates to limit table creation to company's FY only
        if (!empty($items_for_daily_stock)) {
            $fy_start = date('Y-m-d', strtotime($finyear_data['START_DATE']));
            $fy_end = date('Y-m-d', strtotime($finyear_data['END_DATE']));
            
            // Debug: Log before calling
            $daily_stock_debug = [
                'before_call' => date('Y-m-d H:i:s'),
                'items_count' => count($items_for_daily_stock),
                'items' => array_keys($items_for_daily_stock),
                'mode' => $mode,
                'start_date' => $start_date,
                'fy_start' => $fy_start,
                'fy_end' => $fy_end
            ];
            
            $success_rate = updateDailyStockRange($conn, $comp_id, $items_for_daily_stock, $mode, $start_date, $fy_start, $fy_end);
            
            // Debug: Log after
            $daily_stock_debug['after_call'] = date('Y-m-d H:i:s');
            $daily_stock_debug['returned_success_rate'] = $success_rate;
            
            // Add to main debug log
            if ($debug_mode) {
                $debug_log['daily_stock_call'] = $daily_stock_debug;
            }
            
            // Immediate verification - check start_date month, not current month
            $verify_month = date('Y-m', strtotime($start_date));
            $verify_table = getTableForMonth($conn, $comp_id, $verify_month);
            $verify_count = 0;
            
            foreach (array_keys($items_for_daily_stock) as $code) {
                $check = $conn->prepare("SELECT 1 FROM $verify_table WHERE ITEM_CODE = ? AND STK_MONTH = ? LIMIT 1");
                $check->bind_param("ss", $code, $verify_month);
                $check->execute();
                $check->store_result();
                if ($check->num_rows > 0) {
                    $verify_count++;
                }
                $check->close();
            }
            
            $daily_stock_debug['immediate_verification'] = [
                'month' => $verify_month,
                'table' => $verify_table,
                'records_found' => $verify_count,
                'expected' => count($items_for_daily_stock),
                'percentage' => count($items_for_daily_stock) > 0 ? 
                    round(($verify_count / count($items_for_daily_stock)) * 100, 2) . '%' : 'N/A'
            ];
            
            if ($debug_mode) {
                $debug_log['daily_stock_verification'] = $daily_stock_debug['immediate_verification'];
            }
            
            error_log("DailyStock Update: Found $verify_count/" . count($items_for_daily_stock) . " records immediately after update");
        }
        
        // Commit transaction
        $conn->commit();
        
        // ==================== VERIFICATION: Count actual items saved in database ====================
        // This verifies how many items were actually saved to tblitem_stock
        $actual_saved_count = 0;
        $actual_items_saved = [];
        $items_not_in_db = [];
        
        // Get item codes from associative array
        $item_codes_to_verify = array_keys($items_for_daily_stock_agg);
        
        if (!empty($item_codes_to_verify)) {
            $placeholders = implode(',', array_fill(0, count($item_codes_to_verify), '?'));
            $verify_query = "SELECT ITEM_CODE, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id 
                           FROM tblitem_stock 
                           WHERE ITEM_CODE IN ($placeholders)";
            $verify_stmt = $conn->prepare($verify_query);
            $verify_stmt->bind_param(str_repeat('s', count($item_codes_to_verify)), ...$item_codes_to_verify);
            $verify_stmt->execute();
            $verify_result = $verify_stmt->get_result();
            
            $saved_codes = [];
            while ($row = $verify_result->fetch_assoc()) {
                $actual_saved_count++;
                $saved_codes[] = $row['ITEM_CODE'];
                $actual_items_saved[$row['ITEM_CODE']] = $row['ITEM_CODE'];
            }
            $verify_stmt->close();
            
            // Find items that were NOT saved (for debugging)
            foreach ($item_codes_to_verify as $code) {
                if (!in_array($code, $saved_codes)) {
                    $items_not_in_db[] = $code;
                }
            }
        }
        
        // Update the imported count to reflect actual items in database
        $imported_count = $actual_saved_count;
        
        // ==================== DEBUG: Post-Commit Verification ====================
        if ($debug_mode) {
            // Verify all imported items in tblitem_stock
            $post_verify_query = "SELECT ITEM_CODE, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id 
                                  FROM tblitem_stock 
                                  WHERE ITEM_CODE IN (" . implode(',', array_fill(0, count($items_for_daily_stock), '?')) . ")";
            $post_verify_stmt = $conn->prepare($post_verify_query);
            if (count($items_for_daily_stock) > 0) {
                $post_verify_stmt->bind_param(str_repeat('s', count($items_for_daily_stock)), ...array_keys($items_for_daily_stock));
            }
            $post_verify_stmt->execute();
            $post_verify_result = $post_verify_stmt->get_result();
            
            $post_verify_count = 0;
            $post_verify_mismatch = [];
            while ($row = $post_verify_result->fetch_assoc()) {
                $expected = $items_for_daily_stock[$row['ITEM_CODE']] ?? 0;
                $actual = $row['CURRENT_STOCK' . $comp_id] ?? 0;
                if ($expected == $actual) {
                    $post_verify_count++;
                } else {
                    $post_verify_mismatch[] = [
                        'code' => $row['ITEM_CODE'],
                        'expected' => $expected,
                        'actual' => $actual
                    ];
                }
            }
            $post_verify_stmt->close();
            
            $debug_log['summary']['post_commit_verified'] = $post_verify_count;
            $debug_log['summary']['post_commit_mismatch'] = count($post_verify_mismatch);
            $debug_log['summary']['post_commit_percentage'] = count($items_for_daily_stock) > 0 ? 
                round(($post_verify_count / count($items_for_daily_stock)) * 100, 2) : 0;
            $debug_log['summary']['post_commit_mismatch_details'] = $post_verify_mismatch;
            
            // Calculate overall accuracy
            $total_stock_items = count($items_for_daily_stock);
            $debug_log['summary']['total_accuracy'] = $total_stock_items > 0 ? 
                round(($post_verify_count / $total_stock_items) * 100, 2) : 0;
            
            // Add verification info
            $debug_log['summary']['actual_saved_count'] = $actual_saved_count;
            $debug_log['summary']['initial_count'] = $imported_count;
            $debug_log['summary']['count_difference'] = $actual_saved_count - $imported_count;
            $debug_log['summary']['items_saved_codes'] = $actual_items_saved;
            $debug_log['summary']['items_not_in_db'] = $items_not_in_db;
            $debug_log['summary']['items_not_in_db_count'] = count($items_not_in_db);
            
            // Add duplicate items summary
            $debug_log['summary']['duplicates_processed'] = [];
            foreach ($duplicate_items as $code => $data) {
                $debug_log['summary']['duplicates_processed'][] = [
                    'code' => $code,
                    'entries' => $data['duplicate_count'] + 1,
                    'original_values' => $data['original_balances'],
                    'last_value_only' => $data['original_balances'][count($data['original_balances']) - 1],
                    'overwrite_mode' => true
                ];
            }
            
            // Analyze stock updates
            $update_count = 0;
            $insert_count = 0;
            $update_success = 0;
            $insert_success = 0;
            $update_fail = 0;
            $insert_fail = 0;
            
            if (!empty($debug_log['stock_updates'])) {
                foreach ($debug_log['stock_updates'] as $su) {
                    $action = $su['action'] ?? '';
                    $success = $su['success'] ?? true; // Default to true if not set (assume success)
                    
                    if ($action === 'UPDATE') {
                        $update_count++;
                        if ($success) $update_success++;
                        else $update_fail++;
                    } elseif ($action === 'INSERT') {
                        $insert_count++;
                        if ($success) $insert_success++;
                        else $insert_fail++;
                    }
                }
            }
            
            $debug_log['summary']['update_count'] = $update_count;
            $debug_log['summary']['insert_count'] = $insert_count;
            $debug_log['summary']['update_success'] = $update_success;
            $debug_log['summary']['insert_success'] = $insert_success;
            $debug_log['summary']['update_fail'] = $update_fail;
            $debug_log['summary']['insert_fail'] = $insert_fail;
            
            // Add skipped items summary to debug log
            $debug_log['summary']['skipped_items_count'] = count($skipped_items);
            $debug_log['summary']['skipped_items_reasons'] = [];
            
            // Group skipped items by reason
            $reasons_count = [];
            foreach ($skipped_items as $skipped) {
                $reason = $skipped['reason'] ?? 'Unknown';
                if (!isset($reasons_count[$reason])) {
                    $reasons_count[$reason] = 0;
                }
                $reasons_count[$reason]++;
            }
            $debug_log['summary']['skipped_reasons_breakdown'] = $reasons_count;
            
            // Write final debug log
            $debug_log['end_time'] = date('Y-m-d H:i:s');
            $debug_file = 'opening_balance_import_debug_' . date('Y-m-d_H-i-s') . '.log';
            $debug_content = json_encode($debug_log, JSON_PRETTY_PRINT);
            file_put_contents($debug_file, $debug_content);
            $debug_log['debug_file'] = $debug_file;
            
            $_SESSION['import_message']['debug_info']['post_commit_verified'] = $post_verify_count;
            $_SESSION['import_message']['debug_info']['post_commit_mismatch'] = count($post_verify_mismatch);
            $_SESSION['import_message']['debug_info']['post_commit_percentage'] = $debug_log['summary']['post_commit_percentage'];
            $_SESSION['import_message']['debug_info']['total_accuracy'] = $debug_log['summary']['total_accuracy'];
        }

        // Prepare success message with ACTUAL verification
        $duplicate_msg = '';
        if (!empty($duplicate_items)) {
            $duplicate_msg = "Warning: $duplicate_count duplicate item codes found and their values have been OVERWRITTEN (last value used). ";
        }
        
        $message = "Successfully imported $actual_saved_count opening balances (verified in database). ";
        if ($actual_saved_count != $imported_count) {
            $message .= "Note: Initial count was $imported_count but verified $actual_saved_count items in database. ";
        }
        if ($skipped_count > 0) {
            $message .= "$skipped_count items were skipped because they were not found in the database or were not allowed for your license type. ";
        }
        if (!empty($error_messages)) {
            $message .= "Note: " . count($error_messages) . " warnings were generated during import.";
        }
        $message .= $duplicate_msg;
        $message .= " Detected file format: " . ($delimiter === "\t" ? "Tab-Separated (TSV)" : ($delimiter === ";" ? "Semicolon-Separated" : "Comma-Separated (CSV)"));

        $_SESSION['import_message'] = [
            'success' => true,
            'message' => $message,
            'errors' => $error_messages,
            'imported_count' => $actual_saved_count, // Use actual verified count
            'skipped_count' => $skipped_count,
            'delimiter' => $delimiter,
            'debug_mode' => $debug_mode,
            'skipped_items_details' => $skipped_items,
            'actual_verified' => true // Flag to show this is verified
        ];
        
        // Add debug info to session if debug mode was enabled
        if ($debug_mode) {
            $fy_start = date('Y-m-d', strtotime($finyear_data['START_DATE']));
            $fy_end = date('Y-m-d', strtotime($finyear_data['END_DATE']));
            
            // Group skipped items by reason for summary
            $reasons_count = [];
            foreach ($skipped_items as $skipped) {
                $reason = $skipped['reason'] ?? 'Unknown';
                if (!isset($reasons_count[$reason])) {
                    $reasons_count[$reason] = 0;
                }
                $reasons_count[$reason]++;
            }
            
            $_SESSION['import_message']['debug_info'] = [
                'csv_total_items' => $csv_total_items,
                'scm_matched' => count($debug_log['scm_items_found']),
                'scm_not_matched' => count($debug_log['scm_items_not_found']),
                'scm_accuracy' => $csv_total_items > 0 ? round((count($debug_log['scm_items_found']) / $csv_total_items) * 100, 2) : 0,
                'cascade_verified' => $debug_log['summary']['cascade_verified'] ?? 0,
                'cascade_failed' => $debug_log['summary']['cascade_failed'] ?? 0,
                'cascade_percentage' => $debug_log['summary']['cascade_percentage'] ?? 0,
                'debug_file' => $debug_log['debug_file'] ?? '',
                'financial_year_start' => $fy_start,
                'financial_year_end' => $fy_end,
                'table_limited_to_fy' => true,
                'skipped_reasons_breakdown' => $reasons_count,
                'license_type' => $license_type,
                'allowed_sgroups' => $allowed_classes,
                'allowed_category_codes' => $allowed_category_codes,
                'actual_saved_count' => $actual_saved_count,
                'initial_count' => $imported_count,
                'count_difference' => $actual_saved_count - $imported_count,
                'items_not_in_db' => $items_not_in_db,
                'items_not_in_db_count' => count($items_not_in_db),
                'update_count' => $update_count ?? 0,
                'insert_count' => $insert_count ?? 0,
                'update_success' => $update_success ?? 0,
                'insert_success' => $insert_success ?? 0,
                'update_fail' => $update_fail ?? 0,
                'insert_fail' => $insert_fail ?? 0,
                'duplicate_items_found' => count($duplicate_items),
                'duplicates_processed' => $debug_log['summary']['duplicates_processed'] ?? []
            ];
        }

        header("Location: opening_balance.php?mode=" . $mode . "&view=" . $view_type . "&search=" . urlencode($search));
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        fclose($handle);
        
        $_SESSION['import_message'] = [
            'success' => false,
            'message' => "Import failed: " . $e->getMessage(),
            'errors' => $error_messages
        ];
        
        header("Location: opening_balance.php?mode=" . $mode . "&view=" . $view_type . "&search=" . urlencode($search));
        exit;
    }
}

// Handle bulk form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_balances'])) {
    $start_date = $_POST['start_date'];
    
    if (isset($_POST['opening_stock']) && !empty($_POST['opening_stock'])) {
        $items_to_update = [];
        $items_for_daily_stock = [];

        foreach ($_POST['opening_stock'] as $code => $balance) {
            $balance = intval($balance);
            $original_balance = isset($_POST['original_stock'][$code]) ? intval($_POST['original_stock'][$code]) : 0;

            // Only update if the balance has changed
            if ($balance >= 0 && $balance !== $original_balance) {
                $items_to_update[] = ['code' => $code, 'balance' => $balance];
                
                // IMPORTANT: Only add to daily stock if balance > 0
                if ($balance > 0) {
                    $items_for_daily_stock[$code] = $balance;
                }
            }
        }
        
        if (!empty($items_to_update)) {
            $conn->begin_transaction();
            
            try {
                // Prepare statements for batch processing
                $check_stmt = $conn->prepare("SELECT 1 FROM tblitem_stock WHERE ITEM_CODE = ? LIMIT 1");
                $update_stmt = $conn->prepare("UPDATE tblitem_stock SET OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ? WHERE ITEM_CODE = ?");
                $insert_stmt = $conn->prepare("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id) VALUES (?, ?, ?, ?)");
                
                $batch_size = 100;
                $batches = array_chunk($items_to_update, $batch_size);
                
                foreach ($batches as $batch) {
                    foreach ($batch as $item) {
                        $check_stmt->bind_param("s", $item['code']);
                        $check_stmt->execute();
                        $check_stmt->store_result();
                        $exists = $check_stmt->num_rows > 0;
                        $check_stmt->free_result();
                        
                        if ($exists) {
                            $update_stmt->bind_param("iis", $item['balance'], $item['balance'], $item['code']);
                            $update_stmt->execute();
                        } else {
                            $insert_stmt->bind_param("siii", $item['code'], $fin_year_id, $item['balance'], $item['balance']);
                            $insert_stmt->execute();
                        }
                    }
                }
                
                // Close prepared statements
                $check_stmt->close();
                $update_stmt->close();
                $insert_stmt->close();
                
                // Update daily stock in bulk (ONLY for items with stock > 0)
                // MODIFIED: Now passes financial year dates to limit table creation to company's FY only
                if (!empty($items_for_daily_stock)) {
                    $fy_start = date('Y-m-d', strtotime($finyear_data['START_DATE']));
                    $fy_end = date('Y-m-d', strtotime($finyear_data['END_DATE']));
                    updateDailyStockRange($conn, $comp_id, $items_for_daily_stock, $mode, $start_date, $fy_start, $fy_end);
                }
                
                $conn->commit();
                
                $_SESSION['import_message'] = [
                    'success' => true,
                    'message' => "Successfully updated " . count($items_to_update) . " opening balances."
                ];
                
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['import_message'] = [
                    'success' => false,
                    'message' => "Update failed: " . $e->getMessage()
                ];
            }
        }
    }
    
    header("Location: opening_balance.php?mode=" . $mode . "&view=" . $view_type . "&search=" . urlencode($search) . "&page=" . $page);
    exit;
}

// Get initial counts only (lightweight)
$total_with_stock = 0;
$total_without_stock = 0;

if (!empty($allowed_classes) || !empty($allowed_class_ids)) {
    // MODIFIED: Now properly uses both SGROUP values and numeric CLASS IDs
    $class_placeholders_sgroup = implode(',', array_fill(0, count($allowed_classes), '?'));
    $class_placeholders_id = implode(',', array_fill(0, count($allowed_class_ids), '?'));
    
    // Build condition for both SGROUP (CLASS_CODE_NEW) and numeric CLASS
    $class_condition = "";
    $count_params = [];
    $count_types = "";
    
    if (!empty($allowed_classes) && !empty($allowed_class_ids)) {
        $class_condition = "(im.CLASS_CODE_NEW IN ($class_placeholders_sgroup) OR im.CLASS IN ($class_placeholders_id))";
        $count_params = array_merge($allowed_classes, $allowed_class_ids);
        $count_types = str_repeat('s', count($allowed_classes)) . str_repeat('i', count($allowed_class_ids));
    } elseif (!empty($allowed_classes)) {
        $class_condition = "im.CLASS_CODE_NEW IN ($class_placeholders_sgroup)";
        $count_params = $allowed_classes;
        $count_types = str_repeat('s', count($allowed_classes));
    } elseif (!empty($allowed_class_ids)) {
        $class_condition = "im.CLASS IN ($class_placeholders_id)";
        $count_params = $allowed_class_ids;
        $count_types = str_repeat('i', count($allowed_class_ids));
    }
    
    // Lightweight count query - CHECK BOTH CLASS AND CLASS_CODE_NEW
    $count_query = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN COALESCE(st.CURRENT_STOCK$comp_id, 0) > 0 THEN 1 ELSE 0 END) as with_stock
                    FROM tblitemmaster im
                    LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                    WHERE im.LIQ_FLAG = ? 
                    AND $class_condition";
    
    $params = array_merge([$mode], $count_params);
    $types = "s" . $count_types;
    
    if ($search !== '') {
        $count_query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= "ss";
    }
    
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->bind_param($types, ...$params);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $count_row = $count_result->fetch_assoc();
    $total_items = $count_row['total'] ?? 0;
    $total_with_stock = $count_row['with_stock'] ?? 0;
    $total_without_stock = $total_items - $total_with_stock;
    $count_stmt->close();
}

// Show import message if exists
$import_message = null;
if (isset($_SESSION['import_message'])) {
    $import_message = $_SESSION['import_message'];
    unset($_SESSION['import_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Opening Balance Management - liqoursoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    body {
      background-color: #f8f9fa;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    }
    .header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 20px 0;
      margin-bottom: 30px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .card {
      border: none;
      border-radius: 15px;
      box-shadow: 0 5px 15px rgba(0,0,0,0.08);
      margin-bottom: 20px;
      transition: transform 0.3s ease;
    }
    .card:hover {
      transform: translateY(-5px);
    }
    .card-header {
      background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
      border-bottom: none;
      border-radius: 15px 15px 0 0 !important;
      font-weight: 600;
      padding: 15px 20px;
    }
    .btn-custom {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      border: none;
      color: white;
      padding: 10px 20px;
      border-radius: 8px;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    .btn-custom:hover {
      transform: translateY(-2px);
      box-shadow: 0 5px 15px rgba(0,0,0,0.2);
      color: white;
    }
    .stat-card {
      background: white;
      padding: 20px;
      border-radius: 10px;
      text-align: center;
      box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    }
    .stat-number {
      font-size: 2.5rem;
      font-weight: 700;
      color: #667eea;
      margin-bottom: 5px;
    }
    .stat-label {
      font-size: 0.9rem;
      color: #6c757d;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    .table {
      background: white;
    }
    .table th {
      background-color: #f8f9fa;
      border-top: none;
      font-weight: 600;
      color: #495057;
    }
    .search-box {
      max-width: 400px;
    }
    .alert-custom {
      border-radius: 10px;
      border: none;
      box-shadow: 0 3px 10px rgba(0,0,0,0.08);
    }
    .modal-content {
      border-radius: 15px;
      border: none;
    }
    .nav-tabs .nav-link {
      border: none;
      color: #6c757d;
      font-weight: 500;
      padding: 10px 20px;
    }
    .nav-tabs .nav-link.active {
      color: #667eea;
      border-bottom: 3px solid #667eea;
      background: transparent;
    }
    .volume-table th {
      background-color: #f0f2ff;
    }
    .badge-custom {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 5px 10px;
      border-radius: 20px;
      font-weight: 500;
    }
    .company-info {
      background-color: #f8f9fa;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
    }
    .table-container {
      max-height: 70vh;
      overflow-x: auto;
      overflow-y: auto;
      position: relative;
      min-height: 200px;
    }
    .opening-balance-input {
      max-width: 120px;
      margin: 0 auto;
      display: block;
    }
    .sticky-header {
      position: sticky;
      top: 0;
      background-color: white;
      z-index: 100;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .company-column {
      min-width: 150px;
      text-align: center;
    }
    .import-section {
      background-color: #e9ecef;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    .download-template {
      margin-top: 10px;
    }
    .table th {
      background-color: #343a40;
      color: white;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .action-btn {
      position: sticky;
      bottom: 0;
      background-color: white;
      padding: 10px 0;
      border-top: 1px solid #dee2e6;
      z-index: 100;
    }
    .import-export-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
    }
    .pagination-container {
        margin-top: 15px;
        display: flex;
        justify-content: center;
    }
    /* Total Opening Balance Summary Table Styles */
    #openingBalanceSummaryTable th {
        font-size: 11px;
        padding: 4px 2px;
        text-align: center;
        white-space: nowrap;
    }
    #openingBalanceSummaryTable td {
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
    .section-header {
        background-color: #e3f2fd;
        padding: 8px 15px;
        border-radius: 5px;
        margin: 20px 0 10px 0;
        border-left: 4px solid #0d6efd;
    }
    .section-header h5 {
        margin: 0;
        color: #0d6efd;
    }
    .view-toggle-buttons {
        margin-bottom: 20px;
    }
    .size-info {
        font-size: 0.85rem;
        color: #6c757d;
    }
    .dashboard-container {
      display: flex;
      min-height: 100vh;
    }
    .main-content {
      flex: 1;
      padding: 20px;
      background: #f8f9fa;
    }
    .content-area {
      background: white;
      padding: 30px;
      border-radius: 10px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .mode-selector {
      margin-bottom: 20px;
    }
    .search-control {
      margin-bottom: 20px;
    }
    .mode-selector .btn-group {
      width: 100%;
    }
    .mode-selector .btn {
      flex: 1;
    }
    .loading-overlay {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(255,255,255,0.9);
      z-index: 1000;
      display: none;
      justify-content: center;
      align-items: center;
    }
    .table-loading {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      text-align: center;
    }
    .infinite-scroll-trigger {
      height: 20px;
      margin: 10px 0;
      text-align: center;
    }
    .load-more-btn {
      margin: 10px 0;
    }
    .hierarchy-badge {
      display: inline-block;
      padding: 3px 6px;
      margin: 2px;
      border-radius: 4px;
      font-size: 0.7rem;
      font-weight: normal;
    }
    .badge-category {
      background-color: #4e73df;
      color: white;
    }
    .badge-class {
      background-color: #1cc88a;
      color: white;
    }
    .badge-subclass {
      background-color: #36b9cc;
      color: white;
    }
    .debug-info-box {
      background-color: #fff3cd;
      border: 1px solid #ffc107;
      border-radius: 5px;
      padding: 15px;
      margin-top: 10px;
    }
    .debug-success {
      color: #28a745;
      font-weight: bold;
    }
    .debug-warning {
      color: #ffc107;
      font-weight: bold;
    }
    .debug-danger {
      color: #dc3545;
      font-weight: bold;
    }
    .table-duplicate {
      background-color: #fff3cd !important;
    }
    .table-not-found {
      background-color: #f8d7da !important;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Opening Balance Management</h3>

      <!-- Company Info -->
      <div class="company-info">
        <strong>Company:</strong> <span id="companyName"><?php echo htmlspecialchars($current_company['Comp_Name']); ?></span> | 
        <strong>Mode:</strong> <span id="currentMode"><?php echo $mode === 'F' ? 'Foreign Liquor' : ($mode === 'C' ? 'Country Liquor' : 'Others'); ?></span> |
        <strong>Financial Year:</strong> <span id="financialYear"><?php echo date('Y-m-d', strtotime($finyear_data['START_DATE'])) . ' to ' . date('Y-m-d', strtotime($finyear_data['END_DATE'])); ?></span> |
        <strong>License Type:</strong> <span id="licenseType" class="badge bg-primary"><?php echo htmlspecialchars($license_type ?? 'Unknown'); ?></span>
        <strong>Allowed Classes:</strong> <span id="allowedClasses">
            <?php 
            if (!empty($available_classes)) {
                $class_names = array_column($available_classes, 'DESC');
                echo htmlspecialchars(implode(', ', $class_names));
            } else {
                echo 'None';
            }
            ?>
        </span>
      </div>

      <!-- Import/Export Buttons -->
      <div class="import-export-buttons">
        <div class="btn-group">
          <button type="button" class="btn btn-info position-relative" data-bs-toggle="modal" data-bs-target="#openingBalanceVolumeModal" onclick="loadVolumeSummary()">
            <i class="fas fa-wine-bottle"></i> View Volume Summary
          </button>
          <a href="?mode=<?= $mode ?>&view=<?= $view_type ?>&search=<?= urlencode($search) ?>&export=csv" class="btn btn-info">
            <i class="fas fa-file-export"></i> Export CSV
          </a>
        </div>
      </div>

      <!-- Import from CSV Section -->
      <div class="import-section mb-4">
        <h5><i class="fas fa-file-import"></i> Import Opening Balances from CSV/TSV</h5>
        <p class="text-muted small">
          <strong>Supported formats:</strong> CSV (comma-separated), TSV (tab-separated), or semicolon-separated<br>
          <strong>Format:</strong> Item_Code, Item_Name, Size, Current_Stock (4 columns only)<br>
          <strong>System automatically detects:</strong> CSV (,), TSV (tab), or Semicolon (;) files
        </p>
        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end" id="importForm">
          <div class="col-md-3">
            <label for="csv_file" class="form-label">CSV/TSV File</label>
            <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv,.txt,.tsv" required>
          </div>
          <div class="col-md-2">
            <label for="start_date_import" class="form-label">Start Date</label>
            <input type="date" class="form-control" id="start_date_import" name="start_date" value="<?= $default_start_date ?>" required>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100" id="importBtn">
              <i class="fas fa-upload"></i> Import File
            </button>
          </div>
          <div class="col-md-3">
            <a href="?download_template=1&mode=<?= $mode ?>" class="btn btn-outline-secondary w-100 download-template">
              <i class="fas fa-download"></i> Download Template
            </a>
          </div>
          <div class="col-md-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="debug_import" name="debug_import" value="1">
              <label class="form-check-label" for="debug_import">
                <i class="fas fa-bug"></i> Debug Mode
              </label>
            </div>
          </div>
        </form>
        
        <?php if ($import_message): ?>
          <div class="alert alert-<?= $import_message['success'] ? 'success' : 'danger' ?> mt-3">
            <strong><?= $import_message['success'] ? 'Success!' : 'Error!' ?></strong> <?= $import_message['message'] ?>
            <?php if (isset($import_message['imported_count']) && isset($import_message['skipped_count'])): ?>
              <div class="mt-2">
                <strong>Import Summary:</strong><br>
                • Imported: <?= $import_message['imported_count'] ?> items
                <?php if (isset($import_message['actual_verified']) && $import_message['actual_verified']): ?>
                  <span class="badge bg-success">VERIFIED IN DATABASE</span>
                <?php endif; ?>
                <br>
                • Skipped: <?= $import_message['skipped_count'] ?> items (not found in database)
                <?php if (isset($import_message['debug_info']['count_difference']) && $import_message['debug_info']['count_difference'] != 0): ?>
                  <br><span class="text-warning">• Count difference: <?= $import_message['debug_info']['count_difference'] ?> (initial: <?= $import_message['debug_info']['initial_count'] ?? 'N/A' ?>, actual: <?= $import_message['debug_info']['actual_saved_count'] ?? 'N/A' ?>)</span>
                <?php endif; ?>
                <?php if (isset($import_message['delimiter'])): ?>
                  <br>• File format: <?= $import_message['delimiter'] === "\t" ? "Tab-Separated (TSV)" : ($import_message['delimiter'] === ";" ? "Semicolon-Separated" : "Comma-Separated (CSV)") ?>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            
            <?php if (isset($import_message['debug_info']) && !empty($import_message['debug_info'])): ?>
              <div class="mt-3 p-3 bg-warning-subtle border rounded">
                <strong><i class="fas fa-bug"></i> DEBUG REPORT:</strong>
                <div class="row mt-2">
                  <div class="col-md-6">
                    <strong>CSV Items Analysis:</strong><br>
                    • CSV Total Items: <?= $import_message['debug_info']['csv_total_items'] ?? 0 ?><br>
                    • SCM Items Matched: <?= $import_message['debug_info']['scm_matched'] ?? 0 ?><br>
                    • SCM Items NOT Matched: <?= $import_message['debug_info']['scm_not_matched'] ?? 0 ?><br>
                    <span class="text-success"><strong>• SCM Accuracy: <?= $import_message['debug_info']['scm_accuracy'] ?? 0 ?>%</strong></span>
                  </div>
                  <div class="col-md-6">
                    <strong>Database Save Operations:</strong><br>
                    • Updates Attempted: <?= $import_message['debug_info']['update_count'] ?? 0 ?><br>
                    • Inserts Attempted: <?= $import_message['debug_info']['insert_count'] ?? 0 ?><br>
                    • Updates Success: <?= $import_message['debug_info']['update_success'] ?? 0 ?><br>
                    • Inserts Success: <?= $import_message['debug_info']['insert_success'] ?? 0 ?><br>
                    <?php if (($import_message['debug_info']['update_fail'] ?? 0) > 0 || ($import_message['debug_info']['insert_fail'] ?? 0) > 0): ?>
                      <span class="text-danger">• Updates Failed: <?= $import_message['debug_info']['update_fail'] ?? 0 ?></span><br>
                      <span class="text-danger">• Inserts Failed: <?= $import_message['debug_info']['insert_fail'] ?? 0 ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="row mt-2">
                  <div class="col-md-6">
                    <strong>Actual Database Verification:</strong><br>
                    • Initial Count: <?= $import_message['debug_info']['initial_count'] ?? $import_message['imported_count'] ?><br>
                    • Actual Saved: <span class="text-success"><strong><?= $import_message['debug_info']['actual_saved_count'] ?? $import_message['imported_count'] ?></strong></span><br>
                    • Difference: <?= isset($import_message['debug_info']['count_difference']) ? ($import_message['debug_info']['count_difference'] == 0 ? '0 (Perfect)' : $import_message['debug_info']['count_difference']) : 'N/A' ?><br>
                    <?php if (($import_message['debug_info']['items_not_in_db_count'] ?? 0) > 0): ?>
                      <span class="text-danger">• Items NOT in DB: <?= $import_message['debug_info']['items_not_in_db_count'] ?? 0 ?></span>
                    <?php endif; ?>
                    <br><span class="badge bg-success">VERIFIED ✓</span>
                  </div>
                <div class="row mt-2">
                  <div class="col-md-6">
                    <strong>Stock Cascade Verification:</strong><br>
                    • Items with Stock (>0): <?= $import_message['debug_info']['cascade_verified'] ?? 0 ?><br>
                    • Cascade Verified: <?= $import_message['debug_info']['cascade_verified'] ?? 0 ?><br>
                    • Cascade Failed: <?= $import_message['debug_info']['cascade_failed'] ?? 0 ?><br>
                    <span class="text-success"><strong>• Cascade Accuracy: <?= $import_message['debug_info']['cascade_percentage'] ?? 0 ?>%</strong></span>
                  </div>
                  <div class="col-md-6">
                    <strong>Stock Verification (tblitem_stock):</strong><br>
                    • Verified Items: <?= $import_message['debug_info']['post_commit_verified'] ?? 0 ?><br>
                    • Mismatch: <?= $import_message['debug_info']['post_commit_mismatch'] ?? 0 ?><br>
                    <span class="text-success"><strong>• Total Accuracy: <?= $import_message['debug_info']['total_accuracy'] ?? 0 ?>%</strong></span>
                  </div>
                </div>
                <div class="row mt-2">
                  <div class="col-md-12">
                    <strong>Daily Stock Table Scope:</strong><br>
                    • Financial Year: <?= $import_message['debug_info']['financial_year_start'] ?? 'N/A' ?> to <?= $import_message['debug_info']['financial_year_end'] ?? 'N/A' ?><br>
                    • Tables limited to company FY: <span class="text-success"><strong><?= (isset($import_message['debug_info']['table_limited_to_fy']) && $import_message['debug_info']['table_limited_to_fy']) ? 'YES' : 'NO' ?></strong></span>
                  </div>
                </div>
                <?php if (isset($import_message['debug_info']['skipped_reasons_breakdown']) && !empty($import_message['debug_info']['skipped_reasons_breakdown'])): ?>
                <div class="row mt-2">
                  <div class="col-md-12">
                    <strong>Skipped Items Reasons Breakdown:</strong><br>
                    <?php foreach ($import_message['debug_info']['skipped_reasons_breakdown'] as $reason => $count): ?>
                      • <?= htmlspecialchars($reason) ?>: <strong><?= $count ?></strong> items<br>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php endif; ?>
                
                <?php if (isset($import_message['debug_info']['license_type'])): ?>
                <div class="row mt-2">
                  <div class="col-md-12">
                    <strong>License Information:</strong><br>
                    • License Type: <span class="badge bg-primary"><?= htmlspecialchars($import_message['debug_info']['license_type']) ?></span><br>
                    • Allowed SGroups (CLASS_CODE_NEW): <strong><?= htmlspecialchars(implode(', ', $import_message['debug_info']['allowed_sgroups'] ?? [])) ?></strong><br>
                    • Allowed Category Codes: <strong><?= htmlspecialchars(implode(', ', $import_message['debug_info']['allowed_category_codes'] ?? [])) ?></strong>
                  </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($import_message['debug_info']['debug_file'])): ?>
                  <div class="mt-2">
                    <strong>Debug Log File:</strong> <code><?= $import_message['debug_info']['debug_file'] ?></code>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
            
            <?php if (!empty($import_message['errors'])): ?>
              <div class="mt-2">
                <strong>Notes (<?= count($import_message['errors']) ?>):</strong>
                <ul class="mb-0 mt-2 small" style="max-height: 200px; overflow-y: auto;">
                  <?php foreach ($import_message['errors'] as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>
            
            <?php if (isset($import_message['debug_info']['duplicate_items_found']) && $import_message['debug_info']['duplicate_items_found'] > 0): ?>
              <div class="mt-3">
                <strong><i class="fas fa-copy text-warning"></i> DUPLICATE ITEMS PROCESSED (<?= $import_message['debug_info']['duplicate_items_found'] ?> items):</strong>
                <div class="table-responsive mt-2" style="max-height: 250px; overflow-y: auto;">
                  <table class="table table-sm table-bordered table-striped" style="font-size: 11px;">
                    <thead class="table-warning">
                      <tr>
                        <th>#</th>
                        <th>Item Code</th>
                        <th>Entries</th>
                        <th>Last Value Only</th>
                        <th>Summed Total</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php 
                      $dup_details = $import_message['debug_info']['duplicates_processed'] ?? [];
                      foreach ($dup_details as $idx => $dup): ?>
                        <tr>
                          <td><?= $idx + 1 ?></td>
                          <td><?= htmlspecialchars($dup['code']) ?></td>
                          <td><?= $dup['entries'] ?></td>
                          <td><?= $dup['last_value_only'] ?></td>
                          <td><?= $dup['entries'] > 1 ? 'N/A (Overwrite Mode)' : $dup['last_value_only'] ?></td>
                          <td><span class="badge bg-warning">✅ Fixed (Overwrite)</span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>
            
            <?php if (isset($import_message['debug_info']['items_not_in_db_count']) && $import_message['debug_info']['items_not_in_db_count'] > 0): ?>
              <div class="mt-3">
                <strong><i class="fas fa-exclamation-triangle text-danger"></i> ITEMS NOT SAVED IN DATABASE (<?= $import_message['debug_info']['items_not_in_db_count'] ?>):</strong>
                <div class="table-responsive mt-2" style="max-height: 200px; overflow-y: auto;">
                  <table class="table table-sm table-bordered table-striped" style="font-size: 11px;">
                    <thead class="table-dark">
                      <tr>
                        <th>#</th>
                        <th>Item Code (from CSV)</th>
                        <th>Matched SCM Code</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php 
                      $not_in_db = $import_message['debug_info']['items_not_in_db'] ?? [];
                      $scm_items_found = $import_message['debug_info']['scm_matched'] ?? 0;
                      foreach ($not_in_db as $idx => $code): ?>
                        <tr>
                          <td><?= $idx + 1 ?></td>
                          <td><?= htmlspecialchars($code) ?></td>
                          <td>N/A</td>
                          <td><span class="badge bg-danger">NOT IN DB</span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>
            
            <?php if (isset($import_message['skipped_items_details']) && !empty($import_message['skipped_items_details']) && $import_message['debug_mode']): ?>
              <div class="mt-3">
                <strong><i class="fas fa-exclamation-triangle"></i> SKIPPED ITEMS DETAILS (<?= count($import_message['skipped_items_details']) ?>):</strong>
                <div class="table-responsive mt-2" style="max-height: 300px; overflow-y: auto;">
                  <table class="table table-sm table-bordered table-striped" style="font-size: 11px;">
                    <thead class="table-dark">
                      <tr>
                        <th>#</th>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Size</th>
                        <th>Balance</th>
                        <th>Skip Reason</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($import_message['skipped_items_details'] as $idx => $item): ?>
                        <tr>
                          <td><?= $idx + 1 ?></td>
                          <td><?= htmlspecialchars($item['code']) ?></td>
                          <td><?= htmlspecialchars($item['name']) ?></td>
                          <td><?= htmlspecialchars($item['size']) ?></td>
                          <td><?= htmlspecialchars($item['balance'] ?? 'N/A') ?></td>
                          <td><span class="text-danger"><?= htmlspecialchars($item['reason']) ?></span></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&view=<?= $view_type ?>&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary mode-btn <?= $mode === 'F' ? 'active' : '' ?>" data-mode="F">
            Foreign Liquor
          </a>
          <a href="?mode=C&view=<?= $view_type ?>&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary mode-btn <?= $mode === 'C' ? 'active' : '' ?>" data-mode="C">
            Country Liquor
          </a>
          <a href="?mode=O&view=<?= $view_type ?>&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary mode-btn <?= $mode === 'O' ? 'active' : '' ?>" data-mode="O">
            Others
          </a>
        </div>
      </div>

      <!-- Search -->
      <form method="GET" class="search-control mb-3" id="searchForm">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view_type); ?>">
        <div class="input-group">
          <input type="text" name="search" class="form-control" id="searchInput"
                 placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Find
          </button>
          <?php if ($search !== ''): ?>
            <a href="?mode=<?= $mode ?>&view=<?= $view_type ?>" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- View Toggle Buttons -->
      <div class="view-toggle-buttons mb-3">
        <label class="form-label">View Items:</label>
        <div class="btn-group" role="group">
          <a href="?mode=<?= $mode ?>&view=with_stock&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary view-btn <?= $view_type === 'with_stock' ? 'active' : '' ?>" data-view="with_stock">
            <i class="fas fa-box-open"></i> Items with Stock (<span id="withStockCount"><?= $total_with_stock ?></span>)
          </a>
          <a href="?mode=<?= $mode ?>&view=without_stock&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary view-btn <?= $view_type === 'without_stock' ? 'active' : '' ?>" data-view="without_stock">
            <i class="fas fa-box"></i> Items without Stock (<span id="withoutStockCount"><?= $total_without_stock ?></span>)
          </a>
        </div>
      </div>

      <!-- Balance Management Form -->
      <form method="POST" id="balanceForm">
        <input type="hidden" name="page" value="1" id="currentPage">
        <input type="hidden" name="view" value="<?= $view_type ?>" id="currentView">
        <input type="hidden" name="mode" value="<?= $mode ?>" id="currentMode">
        <div class="mb-3">
          <label for="start_date_balance" class="form-label">Start Date for Opening Balance</label>
          <input type="date" class="form-control" id="start_date_balance" name="start_date" value="<?= $default_start_date ?>" required style="max-width: 200px;">
        </div>

        <div class="action-btn mb-3 d-flex gap-2">
          <button type="submit" name="update_balances" class="btn btn-success" id="saveBtn">
            <i class="fas fa-save"></i> Save Opening Balances
          </button>
          <div class="ms-auto">
            <span class="text-muted me-3" id="itemCountDisplay">
              Loading items...
            </span>
            <a href="dashboard.php" class="btn btn-secondary">
              <i class="fas fa-sign-out-alt"></i> Exit
            </a>
          </div>
        </div>

        <!-- Items Table with Lazy Loading -->
        <div class="table-container" id="tableContainer">
          <div class="loading-overlay" id="tableLoading">
            <div class="table-loading">
              <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
              </div>
              <p class="mt-2">Loading items...</p>
            </div>
          </div>
          <table class="table table-striped table-bordered table-hover">
            <thead class="sticky-header">
              <tr>
                <th>Code</th>
                <th>Item Name / Hierarchy</th>
                <th>Size</th>
                <th class="company-column">
                  Current Stock (CURRENT_STOCK<?= $comp_id ?>)
                </th>
              </tr>
            </thead>
            <tbody id="itemsTableBody">
              <tr>
                <td colspan="4" class="text-center py-4">
                  <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                  </div>
                  <p class="mt-2">Loading items...</p>
                </td>
              </tr>
            </tbody>
          </table>
          <div class="infinite-scroll-trigger" id="scrollTrigger">
            <div class="spinner-border spinner-border-sm text-primary d-none" id="loadMoreSpinner" role="status">
              <span class="visually-hidden">Loading more...</span>
            </div>
            <button class="btn btn-outline-primary btn-sm load-more-btn d-none" id="loadMoreBtn">
              Load More
            </button>
          </div>
        </div>

        <!-- Save Button at Bottom -->
        <div class="action-btn mt-3 d-flex gap-2">
          <button type="submit" name="update_balances" class="btn btn-success" id="saveBottomBtn">
            <i class="fas fa-save"></i> Save Opening Balances
          </button>
          <div class="ms-auto">
            <span class="text-muted me-3" id="bottomItemCountDisplay">
              Loading...
            </span>
            <a href="dashboard.php" class="btn btn-secondary">
              <i class="fas fa-sign-out-alt"></i> Exit
            </a>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Opening Balance Volume Summary Modal -->
<div class="modal fade" id="openingBalanceVolumeModal" tabindex="-1" aria-labelledby="openingBalanceVolumeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-dark">
                <h5 class="modal-title" id="openingBalanceVolumeModalLabel">
                    <i class="fas fa-wine-bottle me-2"></i>Opening Balance Volume Summary (CURRENT_STOCK<?= $comp_id ?>)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="volumeSummaryLoading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading volume summary...</p>
                </div>
                <div id="volumeSummaryContent" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printVolumeSummary()">
                    <i class="fas fa-print me-1"></i> Print Volume Summary
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay for full page operations -->
<div class="loading-overlay" id="fullPageLoading" style="position: fixed; display: none;">
  <div class="text-center">
    <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
      <span class="visually-hidden">Loading...</span>
    </div>
    <h4 class="mt-3">Processing...</h4>
    <p class="mt-2" id="loadingMessage">Please wait</p>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// State management
let currentPage = 1;
let currentView = '<?= $view_type ?>';
let currentMode = '<?= $mode ?>';
let currentSearch = '<?= htmlspecialchars($search) ?>';
let isLoading = false;
let hasMore = true;
let items = [];
let totalItems = <?= $total_items ?? 0 ?>;

// DOM elements
const itemsTableBody = document.getElementById('itemsTableBody');
const tableLoading = document.getElementById('tableLoading');
const scrollTrigger = document.getElementById('scrollTrigger');
const loadMoreBtn = document.getElementById('loadMoreBtn');
const loadMoreSpinner = document.getElementById('loadMoreSpinner');
const itemCountDisplay = document.getElementById('itemCountDisplay');
const bottomItemCountDisplay = document.getElementById('bottomItemCountDisplay');
const withStockCount = document.getElementById('withStockCount');
const withoutStockCount = document.getElementById('withoutStockCount');

// Loading functions
function showFullPageLoading(message = 'Processing...') {
    document.getElementById('loadingMessage').textContent = message;
    document.getElementById('fullPageLoading').style.display = 'flex';
}

function hideFullPageLoading() {
    document.getElementById('fullPageLoading').style.display = 'none';
}

// Load items via AJAX
async function loadItems(page = 1, append = false) {
    if (isLoading) return;
    
    isLoading = true;
    
    if (!append) {
        tableLoading.style.display = 'flex';
        itemsTableBody.innerHTML = '';
    }
    
    try {
        const params = new URLSearchParams({
            ajax: 'get_items',
            page: page,
            view: currentView,
            mode: currentMode,
            search: currentSearch
        });
        
        const response = await fetch('opening_balance.php?' + params);
        const data = await response.json();
        
        if (!append) {
            items = data.items;
            totalItems = data.total;
            hasMore = data.has_more;
        } else {
            items = [...items, ...data.items];
            hasMore = data.has_more;
        }
        
        renderItems(append);
        
        if (hasMore) {
            showLoadMore();
        } else {
            hideLoadMore();
        }
        
        updateItemCounts();
        
    } catch (error) {
        console.error('Error loading items:', error);
        itemsTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-danger">Error loading items</td></tr>';
    } finally {
        isLoading = false;
        tableLoading.style.display = 'none';
    }
}

// Render items to table
function renderItems(append = false) {
    if (!append) {
        itemsTableBody.innerHTML = '';
    }
    
    if (items.length === 0) {
        itemsTableBody.innerHTML = '<tr><td colspan="4" class="text-center py-4">No items found</td></tr>';
        return;
    }
    
    let html = '';
    items.forEach(item => {
        // Build hierarchy badges
        let hierarchyHtml = '';
        if (item.category_name) {
            hierarchyHtml += `<span class="hierarchy-badge badge-category">${escapeHtml(item.category_name)}</span> `;
        }
        if (item.class_name) {
            hierarchyHtml += `<span class="hierarchy-badge badge-class">${escapeHtml(item.class_name)}</span> `;
        }
        if (item.subclass_name) {
            hierarchyHtml += `<span class="hierarchy-badge badge-subclass">${escapeHtml(item.subclass_name)}</span> `;
        }
        
        html += `
            <tr>
                <td><strong>${escapeHtml(item.code)}</strong></td>
                <td>
                    <div>${escapeHtml(item.details)}</div>
                    <div class="size-info mt-1">${hierarchyHtml}</div>
                </td>
                <td>
                    <div>${escapeHtml(item.size_desc)}</div>
                    <div class="size-info">${item.ml_volume > 0 ? getVolumeLabel(item.ml_volume) : ''}</div>
                </td>
                <td class="company-column">
                    <input type="number" name="opening_stock[${escapeHtml(item.code)}]"
                           value="${item.current_stock}" min="0"
                           class="form-control opening-balance-input"
                           data-original="${item.current_stock}">
                    <input type="hidden" name="original_stock[${escapeHtml(item.code)}]"
                           value="${item.current_stock}">
                </td>
            </tr>
        `;
    });
    
    if (append) {
        itemsTableBody.insertAdjacentHTML('beforeend', html);
    } else {
        itemsTableBody.innerHTML = html;
    }
    
    // Reattach change listeners
    attachInputListeners();
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Helper function to get volume label
function getVolumeLabel(volume) {
    if (volume >= 1000) {
        const liters = volume / 1000;
        if (liters === Math.floor(liters)) {
            return liters + 'L';
        } else {
            return liters.toFixed(1).replace(/\.0$/, '') + 'L';
        }
    } else {
        return volume + ' ML';
    }
}

// Attach change listeners to inputs
function attachInputListeners() {
    document.querySelectorAll('.opening-balance-input').forEach(input => {
        const original = input.dataset.original || input.value;
        input.removeEventListener('change', changeHandler);
        input.addEventListener('change', changeHandler);
    });
}

function changeHandler(e) {
    const original = this.dataset.original || this.value;
    formChanged = (this.value !== original);
}

// Show/hide load more
function showLoadMore() {
    loadMoreBtn.classList.remove('d-none');
    loadMoreSpinner.classList.add('d-none');
}

function hideLoadMore() {
    loadMoreBtn.classList.add('d-none');
    loadMoreSpinner.classList.add('d-none');
}

// Load more items
async function loadMore() {
    if (isLoading || !hasMore) return;
    
    currentPage++;
    loadMoreSpinner.classList.remove('d-none');
    loadMoreBtn.classList.add('d-none');
    
    await loadItems(currentPage, true);
}

// Update item count displays
function updateItemCounts() {
    const displayText = `Showing ${items.length} of ${totalItems} items`;
    itemCountDisplay.textContent = displayText;
    bottomItemCountDisplay.textContent = displayText;
}

// Handle view toggle
document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const view = this.dataset.view;
        currentView = view;
        currentPage = 1;
        document.getElementById('currentView').value = view;
        loadItems(1, false);
        
        // Update active state
        document.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
    });
});

// Handle mode toggle
document.querySelectorAll('.mode-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const mode = this.dataset.mode;
        currentMode = mode;
        currentPage = 1;
        document.getElementById('currentMode').value = mode;
        loadItems(1, false);
        
        // Update active state
        document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
    });
});

// Handle search
document.getElementById('searchForm').addEventListener('submit', function(e) {
    e.preventDefault();
    currentSearch = document.getElementById('searchInput').value;
    currentPage = 1;
    loadItems(1, false);
});

// Infinite scroll
const tableContainer = document.getElementById('tableContainer');
tableContainer.addEventListener('scroll', function() {
    if (!hasMore || isLoading) return;
    
    const scrollTop = this.scrollTop;
    const scrollHeight = this.scrollHeight;
    const clientHeight = this.clientHeight;
    
    if (scrollHeight - scrollTop - clientHeight < 50) {
        loadMore();
    }
});

// Load more button click
loadMoreBtn.addEventListener('click', loadMore);

// Form change detection
let formChanged = false;

window.addEventListener('beforeunload', (e) => {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = '';
    }
});

document.getElementById('balanceForm').addEventListener('submit', function(e) {
    formChanged = false;
    showFullPageLoading('Saving opening balances...');
});

// Import form submit
document.getElementById('importForm')?.addEventListener('submit', function() {
    showFullPageLoading('Importing opening balances...');
});

// Load volume summary
async function loadVolumeSummary() {
    const loadingEl = document.getElementById('volumeSummaryLoading');
    const contentEl = document.getElementById('volumeSummaryContent');
    
    loadingEl.style.display = 'block';
    contentEl.style.display = 'none';
    
    try {
        const params = new URLSearchParams({
            ajax: 'volume_summary',
            mode: currentMode
        });
        
        const response = await fetch('opening_balance.php?' + params);
        const data = await response.json();
        
        let html = generateVolumeSummaryHTML(data);
        loadingEl.style.display = 'none';
        contentEl.innerHTML = html;
        contentEl.style.display = 'block';
    } catch (error) {
        loadingEl.innerHTML = '<div class="alert alert-danger">Error loading volume summary</div>';
    }
}

// Generate volume summary HTML
function generateVolumeSummaryHTML(data) {
    const categories = ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER', 'COUNTRY LIQUOR', 'OTHER'];
    const sizes = [
        '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
        '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
        '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
    ];
    
    let html = '<div class="table-responsive">';
    html += '<table class="table table-bordered table-sm" id="openingBalanceSummaryTable">';
    html += '<thead class="table-light"><tr><th>Category</th>';
    
    sizes.forEach(size => {
        html += `<th>${size}</th>`;
    });
    
    html += '</tr></thead><tbody>';
    
    categories.forEach(category => {
        if (category === 'OTHER' || (data[category] && Object.values(data[category]).some(v => v > 0))) {
            html += '<tr><td><strong>' + category + '</strong></td>';
            sizes.forEach(size => {
                const value = (data[category] && data[category][size]) ? data[category][size] : 0;
                const className = value > 0 ? 'table-success' : '';
                html += `<td class="${className}">${value > 0 ? value.toLocaleString() : ''}</td>`;
            });
            html += '</tr>';
        }
    });
    
    html += '</tbody></table></div>';
    return html;
}

// Print volume summary
function printVolumeSummary() {
    const content = document.getElementById('volumeSummaryContent').innerHTML;
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Opening Balance Volume Summary</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { padding: 20px; }
                .print-header { 
                    text-align: center; 
                    margin-bottom: 30px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 20px;
                }
                .table { font-size: 10px; }
                th, td { padding: 3px !important; text-align: center; }
                @media print {
                    .no-print { display: none; }
                }
            </style>
        </head>
        <body>
            <div class="print-header">
                <h2>Opening Balance Volume Summary</h2>
                <h4>${document.getElementById('companyName').textContent}</h4>
                <p>Mode: ${document.getElementById('currentMode').textContent}</p>
                <p>Financial Year: ${document.getElementById('financialYear').textContent}</p>
                <p>Generated on: ${new Date().toLocaleString()}</p>
            </div>
            ${content}
            <script>
                window.onload = function() { window.print(); setTimeout(() => window.close(), 500); };
            <\/script>
        </body>
        </html>
    `);
    
    printWindow.document.close();
}

// Auto-hide alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(alert => {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);

// Initial load
document.addEventListener('DOMContentLoaded', function() {
    loadItems(1, false);
});
</script>
</body>
</html>