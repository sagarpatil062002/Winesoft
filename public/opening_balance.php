<?php
// Remove time limit for this script completely
set_time_limit(0);
ignore_user_abort(true);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Add debug log function
function debug_log($message, $data = null) {
    $log_file = __DIR__ . '/opening_balance_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message";
    if ($data !== null) {
        $log_entry .= " - " . print_r($data, true);
    }
    $log_entry .= PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

debug_log("Script started");

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
include_once "components/financial_year.php";
require_once 'license_functions.php'; // ADDED: Include license functions

// Helper function to get financial year start date from tblfinyear
function getFinancialYearStartDate($fin_year_id, $conn) {
    static $cache = null;
    if ($cache !== null) return $cache;
    
    $query = "SELECT START_DATE, END_DATE FROM tblfinyear WHERE ID = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $fin_year_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        $start_date = $row['START_DATE'];
        $end_date = $row['END_DATE'];
        $cache = [
            'start' => date('Y-m-d', strtotime($start_date)),
            'end' => date('Y-m-d', strtotime($end_date))
        ];
        return $cache;
    }
    
    $cache = [
        'start' => date('Y') . '-04-01',
        'end' => date('Y') . '-03-31'
    ];
    return $cache;
}

// Set default start date from financial year table
$fy_dates = getFinancialYearStartDate($fin_year_id, $conn);
$default_start_date = $fy_dates['start'];
$fy_end_date = $fy_dates['end'];

debug_log("Financial Year", ['start' => $default_start_date, 'end' => $fy_end_date, 'fin_year_id' => $fin_year_id]);

// Get company's license type and available classes - ADDED LICENSE FILTERING
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering - using CLASS_CODE from tblclass_new
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

debug_log("Allowed classes", $allowed_classes);

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// ==================== NEW: Find First Opening Batch Date ====================
// Get first batch data after mode is set so it can be used throughout the script
debug_log("Calling findFirstOpeningBatchDate with", [
    'comp_id' => $comp_id,
    'mode' => $mode,
    'fy_start' => $fy_dates['start'],
    'fy_end' => $fy_dates['end'],
    'allowed_classes' => $allowed_classes
]);

$first_batch_data = findFirstOpeningBatchDate(
    $conn, 
    $comp_id, 
    $mode, 
    $fy_dates['start'], 
    $fy_dates['end'], 
    $allowed_classes
);

debug_log("First batch data result", $first_batch_data);

debug_log("First batch data", $first_batch_data);
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
        debug_log("Added columns to tblitem_stock for company $comp_id");
    }
}

// Function to get archive table name for a specific month - FIXED: Added null check and better validation
function getArchiveTableName($comp_id, $month) {
    // Check if month is valid
    if (empty($month) || $month === null) {
        debug_log("getArchiveTableName called with invalid month", ['month' => $month]);
        return null;
    }
    
    // Validate month format (YYYY-MM)
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        debug_log("getArchiveTableName called with invalid month format", ['month' => $month]);
        return null;
    }
    
    $timestamp = strtotime($month . '-01');
    if ($timestamp === false) {
        debug_log("getArchiveTableName: strtotime failed for month", ['month' => $month]);
        return null;
    }
    
    $month_year = date('m_y', $timestamp);
    return "tbldailystock_{$comp_id}_{$month_year}";
}

// Function to check if a month is within the financial year
function isMonthInFinancialYear($month, $fy_start, $fy_end) {
    // Check if month is valid
    if (empty($month) || $month === null) {
        debug_log("isMonthInFinancialYear called with invalid month", ['month' => $month]);
        return false;
    }
    
    $month_ts = strtotime($month . '-01');
    if ($month_ts === false) {
        debug_log("isMonthInFinancialYear: strtotime failed for month", ['month' => $month]);
        return false;
    }
    
    $fy_start_ts = strtotime($fy_start);
    $fy_end_ts = strtotime($fy_end);
    
    if ($fy_start_ts === false || $fy_end_ts === false) {
        debug_log("isMonthInFinancialYear: strtotime failed for financial year dates", 
                 ['fy_start' => $fy_start, 'fy_end' => $fy_end]);
        return false;
    }
    
    return ($month_ts >= $fy_start_ts && $month_ts <= $fy_end_ts);
}

// Function to create a fresh archive table with only base columns (NO day columns)
function createFreshArchiveTable($conn, $comp_id, $month) {
    // Check if month is valid
    if (empty($month) || $month === null) {
        debug_log("createFreshArchiveTable called with invalid month", ['month' => $month]);
        return false;
    }
    
    $table_name = getArchiveTableName($comp_id, $month);
    if (!$table_name) {
        debug_log("Failed to get archive table name for month", ['month' => $month]);
        return false;
    }
    
    debug_log("Creating fresh archive table", ['table' => $table_name, 'month' => $month]);
    
    // Drop table if it exists (to ensure clean state)
    $drop_query = "DROP TABLE IF EXISTS $table_name";
    $conn->query($drop_query);
    
    // Create table with ONLY base columns, NO day columns
    $create_table_query = "CREATE TABLE $table_name (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock_{$comp_id}_{$month}` (`STK_MONTH`,`ITEM_CODE`),
        KEY `ITEM_CODE_{$comp_id}_{$month}` (`ITEM_CODE`),
        KEY `LIQ_FLAG_{$comp_id}_{$month}` (`LIQ_FLAG`),
        KEY `STK_MONTH_{$comp_id}_{$month}` (`STK_MONTH`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    if ($conn->query($create_table_query)) {
        debug_log("Successfully created archive table $table_name");
        return $table_name;
    } else {
        $error = $conn->error;
        debug_log("Failed to create archive table $table_name", $error);
        error_log("Failed to create archive table $table_name: " . $error);
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
    debug_log("Created main daily stock table for company $comp_id");
}

// ==================== PERFORMANCE OPTIMIZATION #2: Bulk Column Addition ====================
// Function to add day columns for a specific month (optimized for bulk operations)
function addDayColumnsForMonth($conn, $comp_id, $month, $force_create = false) {
    global $fy_dates;
    
    // Check if month is valid
    if (empty($month) || $month === null) {
        debug_log("addDayColumnsForMonth called with invalid month", ['month' => $month]);
        return false;
    }
    
    // Check if month is within financial year
    if (!isMonthInFinancialYear($month, $fy_dates['start'], $fy_dates['end'])) {
        debug_log("Skipping month outside financial year", ['month' => $month, 'fy_start' => $fy_dates['start'], 'fy_end' => $fy_dates['end']]);
        return false;
    }
    
    $year_month = explode('-', $month);
    $year = $year_month[0];
    $month_num = $year_month[1];
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
    
    // Determine which table to use (current or archive)
    $current_month = date('Y-m');
    $table_name = ($month == $current_month) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $month);
    
    // If table_name is null or invalid, return false
    if (!$table_name) {
        debug_log("addDayColumnsForMonth: Invalid table name for month", ['month' => $month]);
        return false;
    }
    
    // Create archive table if it doesn't exist and it's not current month
    if ($month != $current_month) {
        $check_archive_query = "SHOW TABLES LIKE '$table_name'";
        $check_result = $conn->query($check_archive_query);
        $archive_exists = $check_result->num_rows > 0;
        
        if (!$archive_exists) {
            // Create FRESH archive table with NO day columns
            $result = createFreshArchiveTable($conn, $comp_id, $month);
            if (!$result) {
                debug_log("Failed to create archive table for month", ['month' => $month]);
                return false;
            }
            $force_create = true; // Force column creation for new table
        }
    }
    
    // Only proceed if we need to create columns
    if ($force_create) {
        // Get all existing columns in ONE query
        $existing_columns_query = "SHOW COLUMNS FROM $table_name";
        $existing_result = $conn->query($existing_columns_query);
        
        if (!$existing_result) {
            debug_log("Failed to get columns from $table_name", $conn->error);
            return false;
        }
        
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
            if ($conn->query($alter_query)) {
                debug_log("Added columns to $table_name", ['columns' => count($alter_statements)]);
            } else {
                debug_log("Failed to add columns to $table_name", $conn->error);
                return false;
            }
        }
    }
    
    return true;
}

// Function to get the correct table for a specific month
function getTableForMonth($conn, $comp_id, $month) {
    global $fy_dates;
    
    // Check if month is valid
    if (empty($month) || $month === null) {
        debug_log("getTableForMonth called with invalid month", ['month' => $month]);
        return false;
    }
    
    // Check if month is within financial year
    if (!isMonthInFinancialYear($month, $fy_dates['start'], $fy_dates['end'])) {
        debug_log("Month $month is outside financial year, returning false");
        return false;
    }
    
    $current_month = date('Y-m');
    
    if ($month == $current_month) {
        return "tbldailystock_$comp_id";
    } else {
        $archive_table = getArchiveTableName($comp_id, $month);
        
        // If archive_table is null or invalid, return false
        if (!$archive_table) {
            debug_log("getTableForMonth: Invalid archive table name for month", ['month' => $month]);
            return false;
        }
        
        // Check if archive table exists
        $check_query = "SHOW TABLES LIKE '$archive_table'";
        $check_result = $conn->query($check_query);
        $table_exists = $check_result->num_rows > 0;
        
        if (!$table_exists) {
            // Create the archive table if it doesn't exist
            $result = addDayColumnsForMonth($conn, $comp_id, $month, true);
            if (!$result) {
                debug_log("Failed to create archive table for month", ['month' => $month]);
                return false;
            }
        }
        
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
    // Check for previous month data to archive - but only if within financial year
    $previous_month = date('Y-m', strtotime('-1 month'));
    
    if (isMonthInFinancialYear($previous_month, $fy_dates['start'], $fy_dates['end'])) {
        $check_prev_query = "SELECT 1 FROM tbldailystock_$comp_id WHERE STK_MONTH = ? LIMIT 1";
        $check_prev_stmt = $conn->prepare($check_prev_query);
        $check_prev_stmt->bind_param("s", $previous_month);
        $check_prev_stmt->execute();
        $check_prev_stmt->store_result();
        $prev_month_exists = $check_prev_stmt->num_rows > 0;
        $check_prev_stmt->close();
        
        if ($prev_month_exists) {
            debug_log("Archiving previous month", ['month' => $previous_month]);
            
            // Archive previous month's data
            $archive_table = getArchiveTableName($comp_id, $previous_month);
            
            if (!$archive_table) {
                debug_log("Failed to get archive table name for previous month", ['month' => $previous_month]);
            } else {
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
                    
                    debug_log("Archived data for month $previous_month", ['records' => $copy_stmt->affected_rows]);
                }
                
                // Delete archived data
                $delete_query = "DELETE FROM tbldailystock_$comp_id WHERE STK_MONTH = ?";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bind_param("s", $previous_month);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
    }
    
    // Add day columns for the new month (if within financial year)
    if (isMonthInFinancialYear($current_month, $fy_dates['start'], $fy_dates['end'])) {
        addDayColumnsForMonth($conn, $comp_id, $current_month, true);
    }
}

// ==================== CORRECTED: Bulk Daily Stock Updates with Proper Cascade ====================
// Function to update daily stock range (CORRECTED for proper cascade through entire financial year)
// ONLY called for items with stock > 0
function updateDailyStockRange($conn, $comp_id, $items_data, $mode, $start_date) {
    global $fy_dates;
    
    debug_log("========== UPDATE DAILY STOCK RANGE START ==========", [
        'items_count' => count($items_data), 
        'start_date' => $start_date,
        'fy_start' => $fy_dates['start'],
        'fy_end' => $fy_dates['end'],
        'mode' => $mode
    ]);
    
    $start = new DateTime($start_date);
    $end = new DateTime($fy_dates['end']); // Use financial year end instead of today
    
    debug_log("Date range for update", [
        'start' => $start->format('Y-m-d'),
        'end' => $end->format('Y-m-d')
    ]);
    
    // Generate all months from start_date to end of financial year
    $all_months = [];
    $current = new DateTime($start_date);
    $current->modify('first day of this month');
    
    while ($current <= $end) {
        $month = $current->format('Y-m');
        if (isMonthInFinancialYear($month, $fy_dates['start'], $fy_dates['end'])) {
            $all_months[] = $month;
        }
        $current->modify('+1 month');
    }
    
    debug_log("All months to process", ['months' => $all_months]);
    
    // Start transaction for all operations
    $conn->begin_transaction();
    
    try {
        // STEP 1: First, ensure all months have tables and insert base records for all items
        foreach ($all_months as $month_index => $month) {
            $table_name = getTableForMonth($conn, $comp_id, $month);
            
            if (!$table_name) {
                debug_log("Skipping month $month - table not available");
                continue;
            }
            
            // Ensure columns exist for this month
            addDayColumnsForMonth($conn, $comp_id, $month, true);
            
            // Get days in this month
            $year_month = explode('-', $month);
            $year = $year_month[0];
            $month_num = $year_month[1];
            $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
            
            // For each item, ensure a record exists
            foreach ($items_data as $item_code => $opening_balance) {
                // Check if record exists for this month
                $check_query = "SELECT 1 FROM $table_name WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ? LIMIT 1";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("sss", $month, $item_code, $mode);
                $check_stmt->execute();
                $check_stmt->store_result();
                $exists = $check_stmt->num_rows > 0;
                $check_stmt->close();
                
                if (!$exists) {
                    debug_log("Inserting base record for", [
                        'item_code' => $item_code, 
                        'month' => $month
                    ]);
                    
                    // Insert base record with zeros for all days
                    $columns = ['STK_MONTH', 'ITEM_CODE', 'LIQ_FLAG'];
                    $placeholders = ['?', '?', '?'];
                    $params = [$month, $item_code, $mode];
                    $types = 'sss';
                    
                    // Add all day columns with zero values
                    for ($day = 1; $day <= $days_in_month; $day++) {
                        $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                        $columns[] = "DAY_{$day_padded}_OPEN";
                        $columns[] = "DAY_{$day_padded}_PURCHASE";
                        $columns[] = "DAY_{$day_padded}_SALES";
                        $columns[] = "DAY_{$day_padded}_CLOSING";
                        
                        for ($i = 0; $i < 4; $i++) {
                            $placeholders[] = '?';
                            $params[] = 0;
                            $types .= 'i';
                        }
                    }
                    
                    $insert_query = "INSERT INTO $table_name (" . implode(', ', $columns) . 
                                  ") VALUES (" . implode(', ', $placeholders) . ")";
                    
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bind_param($types, ...$params);
                    $insert_stmt->execute();
                    $insert_stmt->close();
                }
            }
        }
        
        // STEP 2: Process each month sequentially to set correct OPEN values based on previous month's CLOSING
        for ($i = 0; $i < count($all_months); $i++) {
            $current_month = $all_months[$i];
            $table_name = getTableForMonth($conn, $comp_id, $current_month);
            
            if (!$table_name) continue;
            
            $year_month = explode('-', $current_month);
            $year = $year_month[0];
            $month_num = $year_month[1];
            $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
            
            // Determine previous month's closing balance source
            $prev_table = null;
            $prev_month_last_day = null;
            
            if ($i > 0) {
                $prev_month = $all_months[$i - 1];
                $prev_table = getTableForMonth($conn, $comp_id, $prev_month);
                
                if ($prev_table) {
                    $prev_year_month = explode('-', $prev_month);
                    $prev_year = $prev_year_month[0];
                    $prev_month_num = $prev_year_month[1];
                    $prev_days_in_month = cal_days_in_month(CAL_GREGORIAN, $prev_month_num, $prev_year);
                    $prev_month_last_day = str_pad($prev_days_in_month, 2, '0', STR_PAD_LEFT);
                }
            }
            
            // Process each item
            foreach ($items_data as $item_code => $opening_balance) {
                $is_first_month = ($i == 0);
                $start_day_padded = ($is_first_month) ? date('d', strtotime($start_date)) : '01';
                
                // For tracking the running balance
                $running_balance = 0;
                
                // For the first month, we need to know the opening balance
                if ($is_first_month) {
                    // Get the opening balance from the items_data
                    $running_balance = $opening_balance;
                } else {
                    // Get the closing balance from previous month's last day
                    if ($prev_table && $prev_month_last_day) {
                        $prev_query = "SELECT DAY_{$prev_month_last_day}_CLOSING as prev_closing 
                                      FROM $prev_table 
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?
                                      LIMIT 1";
                        $prev_stmt = $conn->prepare($prev_query);
                        $prev_stmt->bind_param("sss", $prev_month, $item_code, $mode);
                        $prev_stmt->execute();
                        $prev_result = $prev_stmt->get_result();
                        
                        if ($prev_row = $prev_result->fetch_assoc()) {
                            $running_balance = (int)$prev_row['prev_closing'];
                        }
                        $prev_stmt->close();
                    }
                }
                
                // Build update query for all days in the month
                $update_parts = [];
                $params = [];
                $types = '';
                
                // Process each day in the month
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                    
                    if ($is_first_month && $day == intval($start_day_padded)) {
                        // This is the start day - set OPEN to opening balance
                        $update_parts[] = "DAY_{$day_padded}_OPEN = ?";
                        $params[] = $running_balance;
                        $types .= 'i';
                        
                        // For days before the start day, they should remain 0 (already set)
                    } elseif ($day == 1 && !$is_first_month) {
                        // First day of subsequent month - set OPEN to previous month's closing
                        $update_parts[] = "DAY_{$day_padded}_OPEN = ?";
                        $params[] = $running_balance;
                        $types .= 'i';
                    } elseif ($day > 1) {
                        // For days after the first, OPEN should be previous day's CLOSING
                        $prev_day = str_pad($day - 1, 2, '0', STR_PAD_LEFT);
                        $update_parts[] = "DAY_{$day_padded}_OPEN = DAY_{$prev_day}_CLOSING";
                    }
                    
                    // Always recalculate CLOSING: OPEN + PURCHASE - SALES
                    $update_parts[] = "DAY_{$day_padded}_CLOSING = GREATEST(0, COALESCE(DAY_{$day_padded}_OPEN, 0) + COALESCE(DAY_{$day_padded}_PURCHASE, 0) - COALESCE(DAY_{$day_padded}_SALES, 0))";
                }
                
                if (!empty($update_parts)) {
                    $update_query = "UPDATE $table_name SET " . implode(', ', $update_parts) . 
                                  " WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
                    
                    $params[] = $current_month;
                    $params[] = $item_code;
                    $params[] = $mode;
                    $types .= 'sss';
                    
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param($types, ...$params);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        debug_log("Successfully updated daily stock with cascade through all months");
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        debug_log("Error in updateDailyStockRange", ['error' => $e->getMessage()]);
        throw $e;
    }
    
    debug_log("Completed updateDailyStockRange");
    debug_log("========== UPDATE DAILY STOCK RANGE END ==========");
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
    
    // Format volume - always use ML format for consistency
    // Handle special cases: 1L = 1000ml, 1.5L = 1500ml, etc.
    if ($volume >= 1000) {
        // For liter sizes, convert to ML but use the ML format
        $label = $volume . ' ML';
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

// ==================== HELPER FUNCTION: Generate months in range ====================
/**
 * Generate all months between two dates (inclusive)
 * 
 * @param string $start_date Start date in Y-m-d format
 * @param string $end_date End date in Y-m-d format
 * @return array Array of months in Y-m format
 */
function generateMonthsInRange($start_date, $end_date) {
    $months = [];
    
    // Validate input dates
    if (empty($start_date) || empty($end_date)) {
        debug_log("generateMonthsInRange: Invalid dates", ['start' => $start_date, 'end' => $end_date]);
        return $months;
    }
    
    $start = trim($start_date);
    $end = trim($end_date);
    
    // Parse dates properly
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    
    if ($start_ts === false || $end_ts === false) {
        debug_log("generateMonthsInRange: strtotime failed", ['start' => $start, 'end' => $end]);
        return $months;
    }
    
    // Start from first day of start month
    $current = date('Y-m-01', $start_ts);
    $end_month = date('Y-m-01', $end_ts);
    
    debug_log("generateMonthsInRange: Date range", ['start' => $current, 'end' => $end_month]);
    
    while ($current <= $end_month) {
        $months[] = date('Y-m', strtotime($current));
        $current = date('Y-m-01', strtotime($current . ' +1 month'));
    }
    
    debug_log("generateMonthsInRange: Generated months", ['count' => count($months), 'months' => $months]);
    
    return $months;
}

// ==================== NEW FUNCTION: Find First Opening Batch Date ====================
/**
 * Find the earliest date in financial year where any item has DAY_XX_OPEN > 0
 * 
 * @param mysqli $conn Database connection
 * @param int $comp_id Company ID
 * @param string $mode Liquor mode (F/C/O)
 * @param string $fy_start Financial year start (Y-m-d)
 * @param string $fy_end Financial year end (Y-m-d)
 * @param array $allowed_classes Allowed class codes from license
 * @return array|null Returns ['date' => 'Y-m-d', 'month' => 'Y-m', 'day' => 'dd'] or null
 */
function findFirstOpeningBatchDate($conn, $comp_id, $mode, $fy_start, $fy_end, $allowed_classes) {
    global $fy_dates;
    
    debug_log("findFirstOpeningBatchDate called", [
        'comp_id' => $comp_id,
        'mode' => $mode,
        'fy_start' => $fy_start,
        'fy_end' => $fy_end,
        'allowed_classes' => $allowed_classes
    ]);
    
    // Generate all months from fy_start to fy_end
    $months = generateMonthsInRange($fy_start, $fy_end);
    
    debug_log("Generated months", ['months' => $months]);
    
    if (empty($allowed_classes)) {
        debug_log("No allowed classes, returning null");
        return null;
    }
    
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    foreach ($months as $month) {
        // Determine correct table
        $current_month = date('Y-m');
        $table_name = ($month == $current_month) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $month);
        
        if (!$table_name) continue;
        
        // Check if table exists
        $table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($table_check->num_rows == 0) continue;
        
        // Get days in this month
        $year_month = explode('-', $month);
        $year = $year_month[0];
        $month_num = $year_month[1];
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
        
        // Build DAY_XX_OPEN > 0 conditions for all days
        $day_conditions = [];
        for ($day = 1; $day <= $days_in_month; $day++) {
            $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
            $day_conditions[] = "DAY_{$day_padded}_OPEN > 0";
        }
        
        if (empty($day_conditions)) continue;
        
        // Query for any item with opening balance in this month
        $query = "SELECT 
                    STK_MONTH,
                    ITEM_CODE,
                    LIQ_FLAG
                  FROM $table_name 
                  WHERE STK_MONTH = ? 
                    AND LIQ_FLAG = ?
                    AND (" . implode(' OR ', $day_conditions) . ")
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $month, $mode);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            // Found opening balance in this month, now find the specific day
            $row = $result->fetch_assoc();
            $stmt->close();
            
            debug_log("Found opening balance in month", ['month' => $month, 'item_code' => $row['ITEM_CODE']]);
            
            // Find which day has the opening balance
            $item_code = $row['ITEM_CODE'];
            
            for ($day = 1; $day <= $days_in_month; $day++) {
                $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                
                $check_query = "SELECT DAY_{$day_padded}_OPEN as opening 
                               FROM $table_name 
                               WHERE STK_MONTH = ? 
                                 AND ITEM_CODE = ? 
                                 AND LIQ_FLAG = ?
                                 AND DAY_{$day_padded}_OPEN > 0
                               LIMIT 1";
                
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bind_param("sss", $month, $item_code, $mode);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $check_stmt->close();
                    // Found the first day with opening balance
                    debug_log("Found first batch date", ['date' => $month . '-' . $day_padded, 'month' => $month, 'day' => $day_padded]);
                    return [
                        'date' => $month . '-' . $day_padded,
                        'month' => $month,
                        'day' => $day_padded,
                        'day_num' => $day
                    ];
                }
                $check_stmt->close();
            }
        }
        $stmt->close();
    }
    
    debug_log("No first batch date found, returning null");
    
    return null;
}

// ==================== NEW FUNCTION: Check for prior purchases ====================
/**
 * Check if an item had any purchase entries before a given cutoff date
 * 
 * @param mysqli $conn Database connection
 * @param int $comp_id Company ID
 * @param string $item_code Item code to check
 * @param string $cutoff_date Cutoff date in 'Y-m-d' format
 * @param string $mode Liquor mode
 * @param string $fy_start Financial year start date
 * @return boolean True if purchases exist before cutoff date
 */
function hasPurchaseBeforeDate($conn, $comp_id, $item_code, $cutoff_date, $mode, $fy_start = null) {
    global $fy_dates;
    
    // Use provided fy_start or get from session
    if ($fy_start === null && isset($GLOBALS['fy_dates'])) {
        $fy_start = $GLOBALS['fy_dates']['start'];
    }
    
    // Parse cutoff date
    $cutoff_timestamp = strtotime($cutoff_date);
    if ($cutoff_timestamp === false) return false;
    
    $cutoff_year_month = date('Y-m', $cutoff_timestamp);
    $cutoff_day = (int)date('d', $cutoff_timestamp);
    
    // Default to beginning of current year if no fy_start
    if ($fy_start === null) {
        $fy_start = date('Y') . '-04-01';
    }
    
    // Generate all months from financial year start to month before cutoff
    $months_to_check = generateMonthsInRange($fy_start, $cutoff_date);
    
    foreach ($months_to_check as $month) {
        $current_month = date('Y-m');
        $table_name = ($month == $current_month) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $month);
        
        if (!$table_name) continue;
        
        // Check if table exists
        $table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($table_check->num_rows == 0) continue;
        
        $year_month = explode('-', $month);
        $year = $year_month[0];
        $month_num = $year_month[1];
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
        
        if ($month == $cutoff_year_month) {
            // For cutoff month, only check days BEFORE cutoff day
            $max_day = $cutoff_day - 1;
            if ($max_day < 1) continue;
            
            $day_conditions = [];
            for ($day = 1; $day <= $max_day; $day++) {
                $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $day_conditions[] = "DAY_{$day_padded}_PURCHASE > 0";
            }
            
            if (empty($day_conditions)) continue;
            
            $query = "SELECT 1 FROM $table_name 
                      WHERE ITEM_CODE = ? 
                        AND STK_MONTH = ? 
                        AND LIQ_FLAG = ?
                        AND (" . implode(' OR ', $day_conditions) . ")
                      LIMIT 1";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sss", $item_code, $month, $mode);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $stmt->close();
                return true;
            }
            $stmt->close();
        } else {
            // For months before cutoff month, check ALL days
            $day_conditions = [];
            for ($day = 1; $day <= $days_in_month; $day++) {
                $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $day_conditions[] = "DAY_{$day_padded}_PURCHASE > 0";
            }
            
            if (empty($day_conditions)) continue;
            
            $query = "SELECT 1 FROM $table_name 
                      WHERE ITEM_CODE = ? 
                        AND STK_MONTH = ? 
                        AND LIQ_FLAG = ?
                        AND (" . implode(' OR ', $day_conditions) . ")
                      LIMIT 1";
            
            $stmt = $conn->prepare($query);
            $stmt->bind_param("sss", $item_code, $month, $mode);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $stmt->close();
                return true;
            }
            $stmt->close();
        }
    }
    
    return false;
}

// ==================== NEW FUNCTION: Get prior purchases subquery ====================
/**
 * Generate a subquery to check for purchases before a given date
 * 
 * @param mysqli $conn Database connection
 * @param int $comp_id Company ID
 * @param string $cutoff_date Cutoff date in 'Y-m-d' format
 * @param string $mode Liquor mode
 * @param string $fy_start Financial year start date
 * @return string SQL subquery
 */
function getPriorPurchasesSubquery($conn, $comp_id, $cutoff_date, $mode, $fy_start = null) {
    global $fy_dates;
    
    // Use provided fy_start or get from global
    if ($fy_start === null && isset($GLOBALS['fy_dates'])) {
        $fy_start = $GLOBALS['fy_dates']['start'];
    }
    
    // Default to beginning of current year if no fy_start
    if ($fy_start === null) {
        $fy_start = date('Y') . '-04-01';
    }
    
    // Parse cutoff date
    $cutoff_timestamp = strtotime($cutoff_date);
    if ($cutoff_timestamp === false) return "SELECT NULL as ITEM_CODE, NULL as STK_MONTH WHERE 1 = 0";
    
    $cutoff_year_month = date('Y-m', $cutoff_timestamp);
    $cutoff_day = (int)date('d', $cutoff_timestamp);
    
    // Generate all months from financial year start to month before cutoff
    $months_to_check = generateMonthsInRange($fy_start, $cutoff_date);
    
    $union_parts = [];
    
    foreach ($months_to_check as $month) {
        $current_month = date('Y-m');
        $table_name = ($month == $current_month) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $month);
        
        if (!$table_name) continue;
        
        // Check if table exists
        $table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($table_check->num_rows == 0) continue;
        
        $year_month = explode('-', $month);
        $year = $year_month[0];
        $month_num = $year_month[1];
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
        
        if ($month == $cutoff_year_month) {
            // For cutoff month, only check days BEFORE cutoff day
            $max_day = $cutoff_day - 1;
            if ($max_day < 1) continue;
            
            $day_conditions = [];
            for ($day = 1; $day <= $max_day; $day++) {
                $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $day_conditions[] = "DAY_{$day_padded}_PURCHASE > 0";
            }
            
            if (!empty($day_conditions)) {
                $union_parts[] = "SELECT ITEM_CODE, STK_MONTH FROM $table_name 
                                  WHERE STK_MONTH = '$month' 
                                    AND LIQ_FLAG = '$mode'
                                    AND (" . implode(' OR ', $day_conditions) . ")";
            }
        } else {
            // For months before cutoff month, check ALL days
            $day_conditions = [];
            for ($day = 1; $day <= $days_in_month; $day++) {
                $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $day_conditions[] = "DAY_{$day_padded}_PURCHASE > 0";
            }
            
            if (!empty($day_conditions)) {
                $union_parts[] = "SELECT ITEM_CODE, STK_MONTH FROM $table_name 
                                  WHERE STK_MONTH = '$month' 
                                    AND LIQ_FLAG = '$mode'
                                    AND (" . implode(' OR ', $day_conditions) . ")";
            }
        }
    }
    
    if (empty($union_parts)) {
        return "SELECT NULL as ITEM_CODE, NULL as STK_MONTH WHERE 1 = 0";
    }
    
    return implode(" UNION ALL ", $union_parts);
}

// ==================== OPENING BALANCE SUMMARY FUNCTION (UPDATED FOR FIRST BATCH) ====================
// Function to get opening balance summary with volume breakdown - now filtered by first batch only
function getOpeningBalanceSummary($conn, $comp_id, $mode, $allowed_classes = [], $first_batch_data = null) {
    global $fy_dates;
    
    debug_log("getOpeningBalanceSummary called", [
        'mode' => $mode,
        'comp_id' => $comp_id,
        'allowed_classes' => $allowed_classes,
        'first_batch_data' => $first_batch_data
    ]);
    
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
    
    // If no first batch data, return empty summary
    if (!$first_batch_data) {
        debug_log("No first batch data, returning empty summary");
        return $summary;
    }
    
    try {
        $batch_month = $first_batch_data['month'];
        $batch_day = $first_batch_data['day'];
        $batch_date = $first_batch_data['date'];
        $batch_table = ($batch_month == date('Y-m')) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $batch_month);
        
        debug_log("Processing summary", [
            'batch_month' => $batch_month,
            'batch_day' => $batch_day,
            'batch_date' => $batch_date,
            'batch_table' => $batch_table
        ]);
        
        if (!$batch_table) {
            debug_log("No batch table found");
            return $summary;
        }
        
        // Check if table exists
        $table_check = $conn->query("SHOW TABLES LIKE '$batch_table'");
        if ($table_check->num_rows == 0) {
            debug_log("Batch table does not exist", ['table' => $batch_table]);
            return $summary;
        }
        
        // Build query based on license filtering - USING CLASS_CODE_NEW and CLASS for backward compatibility
        if (!empty($allowed_classes)) {
            debug_log("Building query with allowed classes", ['count' => count($allowed_classes)]);
            
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
            
            // Get prior purchases subquery
            $prior_purchases_subquery = getPriorPurchasesSubquery($conn, $comp_id, $batch_date, $mode, $fy_dates['start']);
            
            debug_log("Prior purchases subquery generated", ['subquery_length' => strlen($prior_purchases_subquery)]);
            
            $query = "SELECT DISTINCT
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
                        im.CLASS_CODE_NEW,
                        im.SUBCLASS_CODE_NEW,
                        im.SIZE_CODE,
                        ds.DAY_{$batch_day}_OPEN as OPENING_STOCK,
                        COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                      FROM tblitemmaster im
                      INNER JOIN {$batch_table} ds ON im.CODE = ds.ITEM_CODE
                      LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                      WHERE ds.STK_MONTH = ?
                        AND ds.LIQ_FLAG = ?
                        AND ds.DAY_{$batch_day}_OPEN > 0
                        AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))
                        AND NOT EXISTS (
                            SELECT 1 FROM (
                                $prior_purchases_subquery
                            ) pp
                            WHERE pp.ITEM_CODE = im.CODE
                        )";
            $params = array_merge([$batch_month, $mode], $allowed_classes, $allowed_classes);
            $types = "ss" . str_repeat('s', count($allowed_classes) * 2);
        } else {
            debug_log("No allowed classes, returning empty summary");
            return $summary;
        }
        
        $stmt = $conn->prepare($query);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $items = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        debug_log("Summary query returned items", ['count' => count($items)]);
        
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

// ==================== VOLUME SUMMARY FUNCTION (UPDATED FOR FIRST BATCH) ====================
function getOpeningBalanceVolumeSummary($conn, $comp_id, $mode, $allowed_classes = [], $first_batch_data = null) {
    global $fy_dates;
    
    debug_log("getOpeningBalanceVolumeSummary called", [
        'mode' => $mode,
        'comp_id' => $comp_id,
        'allowed_classes' => $allowed_classes,
        'first_batch_data' => $first_batch_data
    ]);
    
    $volumeSummary = [
        'SPIRITS' => [],
        'WINE' => [],
        'FERMENTED BEER' => [],
        'MILD BEER' => [],
        'COUNTRY LIQUOR' => [],
        'OTHER' => []
    ];
    
    // If no first batch data, return empty summary
    if (!$first_batch_data) {
        debug_log("No first batch data, returning empty summary");
        // Initialize all sizes to 0 - SORTED FROM LARGEST TO SMALLEST
        $allSizes = [
            '50L', '30L', '20L', '15L', '4.5L', '3L', '2L', '1.75L', '1.5L',
            '1000 ML', '750 ML', '700 ML', '650 ML', '500 ML', '375 ML', 
            '355 ML', '330 ML', '275 ML', '250 ML', '200 ML', '180 ML', 
            '170 ML', '90 ML', '60 ML', '50 ML'
        ];
        
        foreach ($volumeSummary as $category => $data) {
            foreach ($allSizes as $size) {
                $volumeSummary[$category][$size] = 0;
            }
        }
        return $volumeSummary;
    }
    
    $batch_month = $first_batch_data['month'];
    $batch_day = $first_batch_data['day'];
    $batch_date = $first_batch_data['date'];
    $batch_table = ($batch_month == date('Y-m')) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $batch_month);
    
    debug_log("Processing volume summary", [
        'batch_month' => $batch_month,
        'batch_day' => $batch_day,
        'batch_date' => $batch_date,
        'batch_table' => $batch_table
    ]);
    
    if (!$batch_table) {
        debug_log("No batch table found");
        return $volumeSummary;
    }
    
    if (!$batch_table) {
        return $volumeSummary;
    }
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$batch_table'");
    if ($table_check->num_rows == 0) {
        debug_log("Batch table does not exist", ['table' => $batch_table]);
        return $volumeSummary;
    }
    
    // Initialize all volume sizes to 0 for each category - SORTED FROM LARGEST TO SMALLEST
    $allSizes = [
        '50L', '30L', '20L', '15L', '4.5L', '3L', '2L', '1.75L', '1.5L',
        '1000 ML', '750 ML', '700 ML', '650 ML', '500 ML', '375 ML', 
        '355 ML', '330 ML', '275 ML', '250 ML', '200 ML', '180 ML', 
        '170 ML', '90 ML', '60 ML', '50 ML'
    ];
    
    foreach ($volumeSummary as $category => $data) {
        foreach ($allSizes as $size) {
            $volumeSummary[$category][$size] = 0;
        }
    }
    
    try {
        // Build query to get first batch items with their stock - USING CLASS_CODE_NEW and CLASS
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
            
            // Get prior purchases subquery
            $prior_purchases_subquery = getPriorPurchasesSubquery($conn, $comp_id, $batch_date, $mode, $fy_dates['start']);
            
            $query = "SELECT DISTINCT
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
                        im.CLASS_CODE_NEW,
                        im.SUBCLASS_CODE_NEW,
                        im.SIZE_CODE,
                        ds.DAY_{$batch_day}_OPEN as OPENING_STOCK,
                        COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                      FROM tblitemmaster im
                      INNER JOIN {$batch_table} ds ON im.CODE = ds.ITEM_CODE
                      LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                      WHERE ds.STK_MONTH = ?
                        AND ds.LIQ_FLAG = ?
                        AND ds.DAY_{$batch_day}_OPEN > 0
                        AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))
                        AND NOT EXISTS (
                            SELECT 1 FROM (
                                $prior_purchases_subquery
                            ) pp
                            WHERE pp.ITEM_CODE = im.CODE
                        )";
            $params = array_merge([$batch_month, $mode], $allowed_classes, $allowed_classes);
            $types = "ss" . str_repeat('s', count($allowed_classes) * 2);
        } else {
            debug_log("No allowed classes");
            return $volumeSummary;
        }
        
        $stmt = $conn->prepare($query);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        
        $row_count = $result->num_rows;
        debug_log("Volume summary query returned rows", ['count' => $row_count]);
        
        while ($item = $result->fetch_assoc()) {
            $current_stock = (int)$item['CURRENT_STOCK'];
            debug_log("Processing item", [
                'code' => $item['CODE'],
                'current_stock' => $current_stock,
                'class_code_new' => $item['CLASS_CODE_NEW'],
                'class' => $item['CLASS']
            ]);
            
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
                
                debug_log("Item hierarchy", [
                    'display_category' => $display_category,
                    'ml_volume' => $ml_volume
                ]);
                
                // Get volume label
                $volumeColumn = getVolumeLabel($ml_volume);
                
                // Map volume to the correct column in the sorted list
                // This ensures that even if the volume label format differs, it maps to the predefined columns
                $mappedColumn = $volumeColumn;
                
                // Handle liter format conversions if needed
                if ($ml_volume >= 1000) {
                    $liters = $ml_volume / 1000;
                    // Check if this matches one of the liter size columns
                    $literKey = $liters . 'L';
                    if (in_array($literKey, $allSizes)) {
                        $mappedColumn = $literKey;
                    }
                }
                
                // Add to summary
                if (isset($volumeSummary[$display_category][$mappedColumn])) {
                    $volumeSummary[$display_category][$mappedColumn] += $current_stock;
                    debug_log("Added to volume summary", [
                        'category' => $display_category,
                        'volume' => $mappedColumn,
                        'new_total' => $volumeSummary[$display_category][$mappedColumn]
                    ]);
                } elseif ($display_category !== 'OTHER') {
                    // For unknown sizes in known categories, add to smallest size as fallback
                    $volumeSummary[$display_category]['50 ML'] += $current_stock;
                    debug_log("Added to fallback (50 ML)", [
                        'category' => $display_category,
                        'new_total' => $volumeSummary[$display_category]['50 ML']
                    ]);
                }
            }
        }
        
        $stmt->close();
        
        // Log final summary
        debug_log("Final volume summary", $volumeSummary);
        
    } catch (Exception $e) {
        error_log("Error fetching volume summary: " . $e->getMessage());
        debug_log("Exception in volume summary", ['error' => $e->getMessage()]);
    }
    
    return $volumeSummary;
}

// Handle export requests - MOVED TO TOP
if (isset($_GET['export'])) {
    debug_log("Export requested", ['type' => $_GET['export']]);
    
    $exportType = $_GET['export'];
    
    // If no first batch data, export empty file
    if (!$first_batch_data) {
        if ($exportType === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=opening_balance_empty_' . $mode . '_' . date('Y-m-d') . '.csv');
            
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            
            // Headers
            fputcsv($output, ['Item_Code', 'Item_Name', 'Size', 'Current_Stock']);
            
            fclose($output);
            debug_log("Export completed - empty (no first batch data)");
            exit;
        }
    }
    
    $batch_month = $first_batch_data['month'];
    $batch_day = $first_batch_data['day'];
    $batch_date = $first_batch_data['date'];
    $batch_table = ($batch_month == date('Y-m')) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $batch_month);
    
    if (!$batch_table) {
        if ($exportType === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=opening_balance_empty_' . $mode . '_' . date('Y-m-d') . '.csv');
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Item_Code', 'Item_Name', 'Size', 'Current_Stock']);
            fclose($output);
            exit;
        }
    }
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$batch_table'");
    if ($table_check->num_rows == 0) {
        if ($exportType === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=opening_balance_empty_' . $mode . '_' . date('Y-m-d') . '.csv');
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Item_Code', 'Item_Name', 'Size', 'Current_Stock']);
            fclose($output);
            exit;
        }
    }
    
    // Build query with license filtering and first batch filter - USING CLASS_CODE_NEW and CLASS
    $query_params = [$batch_month, $mode];
    $query_types = "ss";
    
    // Get prior purchases subquery
    $prior_purchases_subquery = getPriorPurchasesSubquery($conn, $comp_id, $batch_date, $mode);
    
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT DISTINCT
                    im.CODE, 
                    im.DETAILS, 
                    im.DETAILS2,
                    im.CLASS,
                    im.CLASS_CODE_NEW,
                    im.SUBCLASS_CODE_NEW,
                    im.SIZE_CODE,
                    sz.SIZE_DESC,
                    ds.DAY_{$batch_day}_OPEN as OPENING_STOCK,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                  FROM tblitemmaster im
                  INNER JOIN {$batch_table} ds ON im.CODE = ds.ITEM_CODE
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                  WHERE ds.STK_MONTH = ?
                    AND ds.LIQ_FLAG = ?
                    AND ds.DAY_{$batch_day}_OPEN > 0
                    AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))
                    AND NOT EXISTS (
                        SELECT 1 FROM (
                            $prior_purchases_subquery
                        ) pp
                        WHERE pp.ITEM_CODE = im.CODE
                    )";
        $query_params = array_merge([$batch_month, $mode], $allowed_classes, $allowed_classes);
        $query_types .= str_repeat('s', count($allowed_classes) * 2);
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
                    ds.DAY_{$batch_day}_OPEN as OPENING_STOCK,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                  FROM tblitemmaster im
                  INNER JOIN {$batch_table} ds ON im.CODE = ds.ITEM_CODE
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
        debug_log("Export completed");
        exit;
    }
}

// Handle template download - MOVED TO TOP
if (isset($_GET['download_template'])) {
    debug_log("Template download requested");
    
    // Fetch all items from tblitemmaster for the current liquor mode
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        // UPDATED QUERY TO USE CLASS_CODE_NEW AND CLASS
        $template_query = "SELECT im.CODE, im.DETAILS, sz.SIZE_DESC 
                          FROM tblitemmaster im
                          LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                          WHERE im.LIQ_FLAG = ? 
                          AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))
                          ORDER BY im.DETAILS ASC";
        $template_stmt = $conn->prepare($template_query);
        $template_params = array_merge([$mode], $allowed_classes, $allowed_classes);
        $template_types = "s" . str_repeat('s', count($allowed_classes) * 2);
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
    debug_log("Template download completed");
    exit;
}

// Handle AJAX request for items
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_items') {
    // Clean any output buffer to ensure clean JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    debug_log("AJAX get_items called", [
        'page' => isset($_GET['page']) ? $_GET['page'] : 1,
        'view' => isset($_GET['view']) ? $_GET['view'] : 'with_stock',
        'mode' => isset($_GET['mode']) ? $_GET['mode'] : 'F',
        'search' => isset($_GET['search']) ? $_GET['search'] : '',
        'first_batch_data' => $first_batch_data
    ]);
    
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $view_type = isset($_GET['view']) ? $_GET['view'] : 'with_stock';
    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $limit = 100;
    $offset = ($page - 1) * $limit;
    
    header('Content-Type: application/json');
    
    // If no first batch data, return empty
    if (!$first_batch_data) {
        echo json_encode(['items' => [], 'total' => 0, 'has_more' => false]);
        exit;
    }
    
    if (empty($allowed_classes)) {
        echo json_encode(['items' => [], 'total' => 0, 'has_more' => false]);
        exit;
    }
    
    $batch_month = $first_batch_data['month'];
    $batch_day = $first_batch_data['day'];
    $batch_date = $first_batch_data['date'];
    $batch_table = ($batch_month == date('Y-m')) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $batch_month);
    
    if (!$batch_table) {
        echo json_encode(['items' => [], 'total' => 0, 'has_more' => false]);
        exit;
    }
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$batch_table'");
    if ($table_check->num_rows == 0) {
        echo json_encode(['items' => [], 'total' => 0, 'has_more' => false]);
        exit;
    }
    
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // Get prior purchases subquery
    $prior_purchases_subquery = getPriorPurchasesSubquery($conn, $comp_id, $batch_date, $mode, $fy_dates['start']);
    
    // Get total count - Only show items with stock >= 0 (exclude negative stock)
    // For with_stock view: stock > 0
    // For without_stock view: stock = 0
    $stock_condition = ($view_type === 'with_stock') 
        ? "AND COALESCE(st.CURRENT_STOCK{$comp_id}, 0) > 0" 
        : "AND (st.CURRENT_STOCK{$comp_id} IS NULL OR COALESCE(st.CURRENT_STOCK{$comp_id}, 0) = 0)";
    
    // Count query with proper stock filtering - excludes negative stock
    $count_query = "SELECT 
                        COUNT(DISTINCT im.CODE) as total
                    FROM tblitemmaster im
                    INNER JOIN {$batch_table} ds ON im.CODE = ds.ITEM_CODE
                    LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                    WHERE ds.STK_MONTH = ?
                      AND ds.LIQ_FLAG = ?
                      AND ds.DAY_{$batch_day}_OPEN > 0
                      AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))
                      AND NOT EXISTS (
                          SELECT 1 FROM (
                              $prior_purchases_subquery
                          ) pp
                          WHERE pp.ITEM_CODE = im.CODE
                      )
                      AND COALESCE(st.CURRENT_STOCK{$comp_id}, 0) >= 0";
    
    $params = array_merge([$batch_month, $mode], $allowed_classes, $allowed_classes);
    $types = "ss" . str_repeat('s', count($allowed_classes) * 2);
    
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
    
    debug_log("AJAX get_items count query result", ['total' => $total, 'batch_month' => $batch_month, 'batch_day' => $batch_day]);
    
    // Get items
    $query = "SELECT DISTINCT
                im.CODE, 
                im.Print_Name, 
                im.DETAILS, 
                im.DETAILS2, 
                im.CLASS,
                im.CLASS_CODE_NEW, 
                im.SUBCLASS_CODE_NEW, 
                im.ITEM_GROUP,
                im.SIZE_CODE,
                ds.DAY_{$batch_day}_OPEN as OPENING_STOCK,
                COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK,
                sz.SIZE_DESC
              FROM tblitemmaster im
              INNER JOIN {$batch_table} ds ON im.CODE = ds.ITEM_CODE
              LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
              LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
              WHERE ds.STK_MONTH = ?
                AND ds.LIQ_FLAG = ?
                AND ds.DAY_{$batch_day}_OPEN > 0
                AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))
                AND NOT EXISTS (
                    SELECT 1 FROM (
                        $prior_purchases_subquery
                    ) pp
                    WHERE pp.ITEM_CODE = im.CODE
                )
                $stock_condition";
    
    $params = array_merge([$batch_month, $mode], $allowed_classes, $allowed_classes);
    $types = "ss" . str_repeat('s', count($allowed_classes) * 2);
    
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
    
    debug_log("AJAX get_items returning", ['items_count' => count($items), 'total' => $total, 'has_more' => ($offset + $limit) < $total]);
    
    echo json_encode([
        'items' => $items,
        'total' => (int)$total,
        'has_more' => ($offset + $limit) < $total,
        'first_batch_date' => $first_batch_data['date']
    ]);
    exit;
}

// Handle AJAX request for volume summary
if (isset($_GET['ajax']) && $_GET['ajax'] === 'volume_summary') {
    // Clean any output buffer to ensure clean JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    debug_log("AJAX volume_summary called", $_GET);
    
    try {
        // Check if first_batch_data exists
        if (!$first_batch_data) {
            debug_log("AJAX volume_summary - no first_batch_data");
            echo json_encode([
                'error' => 'No opening balance data found',
                'volume_summary' => [],
                'debug' => 'first_batch_data is null'
            ]);
            exit;
        }
        
        // Validate that the batch table exists before proceeding
        $batch_month = $first_batch_data['month'];
        $batch_day = $first_batch_data['day'];
        $batch_table = ($batch_month == date('Y-m')) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $batch_month);
        
        debug_log("Volume summary - checking table", [
            'batch_table' => $batch_table,
            'batch_month' => $batch_month
        ]);
        
        if (!$batch_table) {
            echo json_encode([
                'error' => 'Invalid batch table',
                'volume_summary' => [],
                'debug' => 'batch_table is null'
            ]);
            exit;
        }
        
        // Check if table exists
        $table_check = $conn->query("SHOW TABLES LIKE '$batch_table'");
        if ($table_check->num_rows == 0) {
            debug_log("Volume summary - table does not exist", ['table' => $batch_table]);
            echo json_encode([
                'error' => 'Batch table does not exist',
                'volume_summary' => [],
                'debug' => "Table $batch_table not found"
            ]);
            exit;
        }
        
        // Check if allowed_classes is valid
        if (empty($allowed_classes)) {
            debug_log("Volume summary - no allowed classes");
            // Return empty but valid JSON - SORTED FROM LARGEST TO SMALLEST
            $empty_summary = [
                'SPIRITS' => [],
                'WINE' => [],
                'FERMENTED BEER' => [],
                'MILD BEER' => [],
                'COUNTRY LIQUOR' => [],
                'OTHER' => []
            ];
            // Initialize all sizes to 0 - SORTED FROM LARGEST TO SMALLEST
            $allSizes = [
                '50L', '30L', '20L', '15L', '4.5L', '3L', '2L', '1.75L', '1.5L',
                '1000 ML', '750 ML', '700 ML', '650 ML', '500 ML', '375 ML', 
                '355 ML', '330 ML', '275 ML', '250 ML', '200 ML', '180 ML', 
                '170 ML', '90 ML', '60 ML', '50 ML'
            ];
            
            foreach ($empty_summary as $category => $data) {
                foreach ($allSizes as $size) {
                    $empty_summary[$category][$size] = 0;
                }
            }
            
            echo json_encode($empty_summary);
            exit;
        }
        
        $volume_summary_data = getOpeningBalanceVolumeSummary($conn, $comp_id, $mode, $allowed_classes, $first_batch_data);
        
        // Ensure we always return a valid array
        if (!is_array($volume_summary_data)) {
            $volume_summary_data = [];
        }
        
        debug_log("AJAX volume_summary returning with categories count: " . count($volume_summary_data));
        echo json_encode($volume_summary_data);
        
    } catch (Exception $e) {
        debug_log("AJAX volume_summary Exception: " . $e->getMessage());
        debug_log("Exception trace: " . $e->getTraceAsString());
        
        // Return a structured error response
        echo json_encode([
            'error' => $e->getMessage(),
            'volume_summary' => [],
            'debug' => 'Exception occurred'
        ]);
    } catch (Error $e) {
        debug_log("AJAX volume_summary Error: " . $e->getMessage());
        debug_log("Error trace: " . $e->getTraceAsString());
        
        echo json_encode([
            'error' => 'Server error: ' . $e->getMessage(),
            'volume_summary' => [],
            'debug' => 'PHP Error occurred'
        ]);
    }
    exit;
}

// Handle AJAX request for summary stats
if (isset($_GET['ajax']) && $_GET['ajax'] === 'summary_stats') {
    // Clean any output buffer to ensure clean JSON response
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    debug_log("AJAX summary_stats called", $_GET);
    
    if (!$first_batch_data) {
        echo json_encode(['error' => 'No opening balance data found']);
        exit;
    }
    
    try {
        $summary_data = getOpeningBalanceSummary($conn, $comp_id, $mode, $allowed_classes, $first_batch_data);
        echo json_encode($summary_data);
    } catch (Exception $e) {
        debug_log("AJAX summary_stats Exception: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    } catch (Error $e) {
        debug_log("AJAX summary_stats Error: " . $e->getMessage());
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
    debug_log("Import started", ['file' => $_FILES['csv_file']['name']]);
    
    $start_date = $_POST['start_date'];
    $csv_file = $_FILES['csv_file']['tmp_name'];
    
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
    
    debug_log("Detected delimiter", ['delimiter' => $delimiter === "\t" ? "TAB" : $delimiter]);
    
    $handle = fopen($csv_file, "r");
    if (!$handle) {
        debug_log("Failed to open file");
        $_SESSION['import_message'] = [
            'success' => false,
            'message' => "Failed to open uploaded file"
        ];
        header("Location: opening_balance.php?mode=" . $mode . "&view=" . $view_type . "&search=" . urlencode($search));
        exit;
    }

    // Read and validate header row with detected delimiter
    $header = fgetcsv($handle, 1000, $delimiter);
    
    if ($header === false) {
        debug_log("Failed to read header");
        fclose($handle);
        $_SESSION['import_message'] = [
            'success' => false,
            'message' => "Failed to read CSV header"
        ];
        header("Location: opening_balance.php?mode=" . $mode . "&view=" . $view_type . "&search=" . urlencode($search));
        exit;
    }
    
    // Check if CSV has the correct format (4 columns)
    $expected_headers = ['Item_Code', 'Item_Name', 'Size', 'Current_Stock'];
    
    // Normalize headers: trim whitespace and remove BOM
    $header = array_map(function($h) {
        // Remove UTF-8 BOM if present
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
        return trim($h);
    }, $header);
    
    if ($header !== $expected_headers) {
        debug_log("Header mismatch", ['expected' => $expected_headers, 'found' => $header]);
        fclose($handle);
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
    $error_messages = [];
    $items_to_update = []; // Store items for bulk update (ALL items)
    $items_for_daily_stock = []; // Store items for daily stock update (ONLY items with stock > 0)
    $skipped_items = []; // Store skipped items for reporting

    // Get all valid items in one query for validation (optimization) - CHECK BOTH OLD AND NEW FIELDS
    $valid_items = [];
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        
        // IMPORTANT: Check both CLASS (old) and CLASS_CODE_NEW (new) for backward compatibility
        $valid_items_query = "SELECT 
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
                              WHERE im.LIQ_FLAG = ? 
                              AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))";
        
        // Double the params for the OR condition
        $valid_params = array_merge([$mode], $allowed_classes, $allowed_classes);
        $valid_types = "s" . str_repeat('s', count($allowed_classes) * 2);
        
        $valid_stmt = $conn->prepare($valid_items_query);
        $valid_stmt->bind_param($valid_types, ...$valid_params);
        $valid_stmt->execute();
        $valid_result = $valid_stmt->get_result();
        
        // Create a comprehensive lookup array for faster validation
        while ($row = $valid_result->fetch_assoc()) {
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
        $valid_stmt->close();
        
        debug_log("Loaded valid items", ['count' => count($valid_items)]);
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // ====== DEBUG: Log CSV items ======
        debug_log("========== IMPORT DEBUG START ==========");
        debug_log("CSV File:", ['name' => $_FILES['csv_file']['name'], 'size' => $_FILES['csv_file']['size']]);
        debug_log("Start date for import:", ['start_date' => $start_date]);
        
        // Re-read CSV to log all items (for debugging)
        $csv_items_debug = [];
        $csv_handle = fopen($csv_file, "r");
        if ($csv_handle) {
            $header_check = fgetcsv($csv_handle, 1000, $delimiter);
            $csv_row_num = 1;
            while (($csv_data = fgetcsv($csv_handle, 1000, $delimiter)) !== FALSE) {
                $csv_row_num++;
                if (count($csv_data) >= 4) {
                    // Handle null values for PHP 8.x compatibility
                    $csv_code = isset($csv_data[0]) ? trim($csv_data[0]) : '';
                    $csv_name = isset($csv_data[1]) ? trim($csv_data[1]) : '';
                    $csv_size = isset($csv_data[2]) ? trim($csv_data[2]) : '';
                    $csv_balance = isset($csv_data[3]) ? intval(trim($csv_data[3] ?? '0')) : 0;
                    
                    $csv_items_debug[] = [
                        'row' => $csv_row_num,
                        'code' => $csv_code,
                        'name' => $csv_name,
                        'size' => $csv_size,
                        'balance' => $csv_balance
                    ];
                }
            }
            fclose($csv_handle);
        }
        debug_log("Total rows in CSV:", ['count' => count($csv_items_debug)]);
        debug_log("First 10 CSV items:", array_slice($csv_items_debug, 0, 10));
        
        // ====== DEBUG: Log valid database items ======
        debug_log("========== DATABASE ITEMS ==========");
        debug_log("Valid items loaded from database:", ['count' => count($valid_items)]);
        
        // Show some example items from database
        $db_item_examples = array_slice($valid_items, 0, 5, true);
        debug_log("Sample database items:", $db_item_examples);
        
        // ====== DEBUG: Check existing daily stock table ======
        debug_log("========== DAILY STOCK TABLE CHECK ==========");
        $check_daily_stock_query = "SHOW TABLES LIKE 'tbldailystock_$comp_id'";
        $daily_stock_table_exists = $conn->query($check_daily_stock_query)->num_rows > 0;
        debug_log("Daily stock table exists:", ['table' => "tbldailystock_$comp_id", 'exists' => $daily_stock_table_exists]);
        
        if ($daily_stock_table_exists) {
            // Get current month's data if exists
            $current_month = date('Y-m');
            $existing_stock_query = "SELECT ITEM_CODE, STK_MONTH FROM tbldailystock_$comp_id WHERE STK_MONTH = ? LIMIT 20";
            $existing_stmt = $conn->prepare($existing_stock_query);
            $existing_stmt->bind_param("s", $current_month);
            $existing_stmt->execute();
            $existing_result = $existing_stmt->get_result();
            $existing_items = [];
            while ($row = $existing_result->fetch_assoc()) {
                $existing_items[] = $row;
            }
            $existing_stmt->close();
            debug_log("Existing items in daily stock for current month:", ['month' => $current_month, 'count' => count($existing_items), 'items' => $existing_items]);
        }
        
        $batch_size = 100;
        $current_batch = 0;
        
        // Prepare statements for batch operations
        $check_stmt = $conn->prepare("SELECT 1 FROM tblitem_stock WHERE ITEM_CODE = ? LIMIT 1");
        $update_stmt = $conn->prepare("UPDATE tblitem_stock SET OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ? WHERE ITEM_CODE = ?");
        $insert_stmt = $conn->prepare("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id) VALUES (?, ?, ?, ?)");
        
        if (!$check_stmt || !$update_stmt || !$insert_stmt) {
            throw new Exception("Failed to prepare statements: " . $conn->error);
        }
        
        $row_count = 0;
        while (($data = fgetcsv($handle, 1000, $delimiter)) !== FALSE) {
            $row_count++;
            if (count($data) >= 4) {
                // Handle null values for PHP 8.x compatibility
                $code = isset($data[0]) ? trim($data[0]) : '';
                $name = isset($data[1]) ? trim($data[1]) : '';
                $size_desc = isset($data[2]) ? trim($data[2]) : '';
                $balance_raw = isset($data[3]) ? $data[3] : '0';
                $balance = intval(trim($balance_raw ?? '0'));
                
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
                // Strategy 6: Try matching by name only
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
                
                if ($item_found && $item_data) {
                    $item_code_to_use = $item_data['code'];
                    
                    // Add to tblitem_stock update list (ALL items, even zero stock)
                    $items_to_update[] = ['code' => $item_code_to_use, 'balance' => $balance];
                    
                    // IMPORTANT: Only add to daily stock if balance > 0
                    if ($balance > 0) {
                        $items_for_daily_stock[$item_code_to_use] = $balance;
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
                                $update_stmt->execute();
                            } else {
                                $insert_stmt->bind_param("siii", $item['code'], $fin_year_id, $item['balance'], $item['balance']);
                                $insert_stmt->execute();
                            }
                        }
                        
                        $items_to_update = [];
                        $current_batch++;
                    }
                } else {
                    $skipped_count++;
                    $skipped_items[] = [
                        'code' => $code_original,
                        'name' => $name,
                        'size' => $size_desc,
                        'reason' => 'Item not found in database or not allowed for your license type'
                    ];
                    
                    // Store in error messages (limit to first 100 to avoid huge messages)
                    if ($skipped_count <= 100) {
                        $error_messages[] = "Skipped item: '$code_original' - '$name' - '$size_desc' (not found in database or not allowed for your license type)";
                    }
                }
            }
        }
        
        debug_log("Import processed", ['rows' => $row_count, 'imported' => $imported_count, 'skipped' => $skipped_count]);
        
        // ====== DEBUG: Log items that were imported ======
        debug_log("========== IMPORTED ITEMS ==========");
        debug_log("Items imported to tblitem_stock:", ['count' => count($items_to_update)]);
        
        // Show first 20 imported items
        $imported_examples = array_slice($items_to_update, 0, 20, true);
        debug_log("First 20 imported items:", $imported_examples);
        
        // ====== DEBUG: Items sent to daily stock update ======
        debug_log("========== DAILY STOCK UPDATE ==========");
        debug_log("Items sent to daily stock update (balance > 0):", ['count' => count($items_for_daily_stock)]);
        $daily_stock_examples = array_slice($items_for_daily_stock, 0, 20, true);
        debug_log("First 20 items for daily stock:", $daily_stock_examples);
        
        // Process remaining items
        if (!empty($items_to_update)) {
            foreach ($items_to_update as $item) {
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
        
        // Close file handle - properly check if it's a valid resource
        if (isset($handle) && $handle !== false) {
            if (is_resource($handle)) {
                fclose($handle);
                debug_log("File handle closed successfully");
            } else {
                debug_log("File handle was not a valid resource, skipping fclose");
            }
        } else {
            debug_log("File handle not set or already closed");
        }
        
        // ==================== PERFORMANCE OPTIMIZATION #5: Bulk Daily Stock Update ====================
        // Only update daily stock for items with balance > 0
        if (!empty($items_for_daily_stock)) {
            debug_log("========== CALLING UPDATE DAILY STOCK RANGE ==========");
            debug_log("Updating daily stock", ['items' => count($items_for_daily_stock), 'start_date' => $start_date]);
            updateDailyStockRange($conn, $comp_id, $items_for_daily_stock, $mode, $start_date);
            
            // ====== DEBUG: Verify daily stock after update ======
            debug_log("========== DAILY STOCK AFTER UPDATE ==========");
            
            // Check the daily stock table for the items we just updated
            $start_month = date('Y-m', strtotime($start_date));
            $current_month_check = date('Y-m');
            
            // Get the relevant months
            $relevant_months = [];
            $start_ts = strtotime($start_date);
            $end_ts = strtotime($fy_dates['end']);
            for ($ts = $start_ts; $ts <= $end_ts; $ts = strtotime('+1 month', $ts)) {
                $relevant_months[] = date('Y-m', $ts);
            }
            debug_log("Checking daily stock for months:", ['months' => $relevant_months]);
            
            foreach (array_slice($relevant_months, 0, 3) as $check_month) {
                // Determine which table to check
                $check_table = ($check_month == $current_month_check) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $check_month);
                
                if ($check_table) {
                    // Check if table exists
                    $table_check = $conn->query("SHOW TABLES LIKE '$check_table'");
                    if ($table_check->num_rows > 0) {
                        // Get sample items from this month
                        $sample_query = "SELECT ITEM_CODE, STK_MONTH FROM $check_table LIMIT 5";
                        $sample_result = $conn->query($sample_query);
                        $sample_items = [];
                        while ($row = $sample_result->fetch_assoc()) {
                            $sample_items[] = $row;
                        }
                        debug_log("Sample from $check_table:", ['count' => count($sample_items), 'items' => $sample_items]);
                        
                        // Get detailed data for first item if exists
                        if (!empty($sample_items)) {
                            $first_item = $sample_items[0]['ITEM_CODE'];
                            $detail_query = "SELECT * FROM $check_table WHERE ITEM_CODE = ? AND STK_MONTH = ? LIMIT 1";
                            $detail_stmt = $conn->prepare($detail_query);
                            $detail_stmt->bind_param("ss", $first_item, $check_month);
                            $detail_stmt->execute();
                            $detail_result = $detail_stmt->get_result();
                            if ($detail_row = $detail_result->fetch_assoc()) {
                                // Get only day columns
                                $day_columns = [];
                                foreach ($detail_row as $key => $value) {
                                    if (preg_match('/^DAY_\d+_(OPEN|PURCHASE|SALES|CLOSING)$/', $key) && $value != 0) {
                                        $day_columns[$key] = $value;
                                    }
                                }
                                debug_log("Detailed data for item $first_item in $check_month:", $day_columns);
                            }
                            $detail_stmt->close();
                        }
                    } else {
                        debug_log("Table does not exist:", ['table' => $check_table]);
                    }
                }
            }
        }
        
        // Commit transaction
        $conn->commit();
        debug_log("Transaction committed");
        
        // ====== DEBUG: Final Summary ======
        debug_log("========== IMPORT COMPLETE - FINAL SUMMARY ==========");
        debug_log("Total CSV rows processed:", ['count' => $row_count]);
        debug_log("Total items imported to tblitem_stock:", ['count' => $imported_count]);
        debug_log("Total items skipped:", ['count' => $skipped_count]);
        debug_log("Total items sent to daily stock update:", ['count' => count($items_for_daily_stock)]);
        debug_log("Financial Year:", ['start' => $fy_dates['start'], 'end' => $fy_dates['end']]);
        
        // Verify final state in database
        if ($daily_stock_table_exists) {
            $final_check_query = "SELECT COUNT(*) as total FROM tbldailystock_$comp_id";
            $final_result = $conn->query($final_check_query);
            $final_row = $final_result->fetch_assoc();
            debug_log("Final count in tbldailystock_{$comp_id}:", ['total_records' => $final_row['total']]);
        }
        debug_log("========== DEBUG COMPLETE ==========");

        // Prepare success message
        $message = "Successfully imported $imported_count opening balances (only items allowed for your license type were processed). ";
        if ($skipped_count > 0) {
            $message .= "$skipped_count items were skipped because they were not found in the database or were not allowed for your license type. ";
        }
        if (!empty($error_messages)) {
            $message .= "Note: " . count($error_messages) . " warnings were generated during import.";
        }
        $message .= " Detected file format: " . ($delimiter === "\t" ? "Tab-Separated (TSV)" : ($delimiter === ";" ? "Semicolon-Separated" : "Comma-Separated (CSV)"));

        $_SESSION['import_message'] = [
            'success' => true,
            'message' => $message,
            'errors' => $error_messages,
            'imported_count' => $imported_count,
            'skipped_count' => $skipped_count,
            'skipped_items' => $skipped_items,
            'delimiter' => $delimiter
        ];

        header("Location: opening_balance.php?mode=" . $mode . "&view=" . $view_type . "&search=" . urlencode($search));
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        
        // Close file handle if still open - properly check if it's a valid resource
        if (isset($handle) && $handle !== false) {
            if (is_resource($handle)) {
                fclose($handle);
                debug_log("File handle closed in exception handler");
            }
        }
        
        debug_log("Import failed", ['error' => $e->getMessage()]);
        
        $_SESSION['import_message'] = [
            'success' => false,
            'message' => "Import failed: " . $e->getMessage(),
            'errors' => $error_messages,
            'skipped_items' => $skipped_items
        ];
        
        header("Location: opening_balance.php?mode=" . $mode . "&view=" . $view_type . "&search=" . urlencode($search));
        exit;
    }
}

// Handle bulk form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_balances'])) {
    debug_log("Bulk update started");
    
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
            debug_log("Processing bulk update", ['items' => count($items_to_update), 'daily_stock_items' => count($items_for_daily_stock)]);
            
            $conn->begin_transaction();
            
            try {
                // Prepare statements for batch processing
                $check_stmt = $conn->prepare("SELECT 1 FROM tblitem_stock WHERE ITEM_CODE = ? LIMIT 1");
                $update_stmt = $conn->prepare("UPDATE tblitem_stock SET OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ? WHERE ITEM_CODE = ?");
                $insert_stmt = $conn->prepare("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id) VALUES (?, ?, ?, ?)");
                
                if (!$check_stmt || !$update_stmt || !$insert_stmt) {
                    throw new Exception("Failed to prepare statements: " . $conn->error);
                }
                
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
                if (!empty($items_for_daily_stock)) {
                    updateDailyStockRange($conn, $comp_id, $items_for_daily_stock, $mode, $start_date);
                }
                
                $conn->commit();
                debug_log("Bulk update committed");
                
                $_SESSION['import_message'] = [
                    'success' => true,
                    'message' => "Successfully updated " . count($items_to_update) . " opening balances."
                ];
                
            } catch (Exception $e) {
                $conn->rollback();
                debug_log("Bulk update failed", ['error' => $e->getMessage()]);
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

// Get initial counts only (lightweight) - Now filtered by first batch
$total_items = 0;
$total_with_stock = 0;
$total_without_stock = 0;

if (!empty($allowed_classes) && $first_batch_data) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $batch_month = $first_batch_data['month'];
    $batch_day = $first_batch_data['day'];
    $batch_date = $first_batch_data['date'];
    $batch_table = ($batch_month == date('Y-m')) ? "tbldailystock_$comp_id" : getArchiveTableName($comp_id, $batch_month);
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$batch_table'");
    if ($table_check->num_rows > 0) {
        // Get prior purchases subquery
        $prior_purchases_subquery = getPriorPurchasesSubquery($conn, $comp_id, $batch_date, $mode, $fy_dates['start']);
        
        // Lightweight count query - CHECK BOTH CLASS AND CLASS_CODE_NEW with first batch filter
        // Only count items with stock >= 0 (exclude negative stock)
        $count_query = "SELECT 
                            COUNT(DISTINCT im.CODE) as total,
                            SUM(CASE WHEN COALESCE(st.CURRENT_STOCK{$comp_id}, 0) > 0 THEN 1 ELSE 0 END) as with_stock,
                            SUM(CASE WHEN COALESCE(st.CURRENT_STOCK{$comp_id}, 0) = 0 THEN 1 ELSE 0 END) as without_stock
                        FROM tblitemmaster im
                        INNER JOIN {$batch_table} ds ON im.CODE = ds.ITEM_CODE
                        LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                        WHERE ds.STK_MONTH = ?
                          AND ds.LIQ_FLAG = ?
                          AND ds.DAY_{$batch_day}_OPEN > 0
                          AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))
                          AND NOT EXISTS (
                              SELECT 1 FROM (
                                  $prior_purchases_subquery
                              ) pp
                              WHERE pp.ITEM_CODE = im.CODE
                          )
                          AND COALESCE(st.CURRENT_STOCK{$comp_id}, 0) >= 0";
        
        $params = array_merge([$batch_month, $mode], $allowed_classes, $allowed_classes);
        $types = "ss" . str_repeat('s', count($allowed_classes) * 2);
        
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
        $total_items = ($count_row['with_stock'] ?? 0) + ($count_row['without_stock'] ?? 0);
        $total_with_stock = $count_row['with_stock'] ?? 0;
        $total_without_stock = $count_row['without_stock'] ?? 0;
        $count_stmt->close();
        
        debug_log("Initial counts (first batch)", ['total' => $total_items, 'with_stock' => $total_with_stock]);
    }
}

// Show import message if exists
$import_message = null;
if (isset($_SESSION['import_message'])) {
    $import_message = $_SESSION['import_message'];
    unset($_SESSION['import_message']);
}

debug_log("Script completed, rendering page");
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
        <strong>Financial Year:</strong> <span id="financialYear"><?php echo date('Y-m-d', strtotime($finyear_data['START_DATE'])) . ' to ' . date('Y-m-d', strtotime($finyear_data['END_DATE'])); ?></span>
      </div>

      <?php if ($first_batch_data): ?>
      <!-- First Batch Date Info -->
      <div class="alert alert-info mb-4">
        <i class="fas fa-info-circle"></i>
        <strong>First Batch Opening Date:</strong> <?= date('d M Y', strtotime($first_batch_data['date'])) ?>
        <span class="text-muted">(Showing only items from the first batch of opening entries with no prior purchases)</span>
      </div>
      <?php else: ?>
      <!-- No Opening Balances Warning -->
      <div class="alert alert-warning mb-4">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>No Opening Balances Found</strong>
        <p class="mb-0">No opening balances were found for the current financial year. Please import opening balances using the CSV import feature.</p>
      </div>
      <?php endif; ?>

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
          <div class="col-md-4">
            <label for="csv_file" class="form-label">CSV/TSV File</label>
            <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv,.txt,.tsv" required>
          </div>
          <div class="col-md-3">
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
        </form>
        
        <?php if ($import_message): ?>
          <div class="alert alert-<?= $import_message['success'] ? 'success' : 'danger' ?> mt-3 alert-dismissible fade show" role="alert" id="importAlert">
            <strong><?= $import_message['success'] ? 'Success!' : 'Error!' ?></strong> <?= $import_message['message'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>
          <?php if (isset($import_message['skipped_items']) && !empty($import_message['skipped_items'])): ?>
            <div class="alert alert-warning mt-3" id="skippedItemsAlert">
              <strong><i class="fas fa-exclamation-triangle"></i> Items Not Found in Database (<?= count($import_message['skipped_items']) ?>)</strong>
              <p class="mb-2 small text-muted">The following items from your CSV file were not found in the database or are not allowed for your license type:</p>
              <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                <table class="table table-sm table-bordered table-striped" style="font-size: 12px;">
                  <thead class="table-warning">
                    <tr>
                      <th>Item Code</th>
                      <th>Item Name</th>
                      <th>Size</th>
                      <th>Reason</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($import_message['skipped_items'] as $item): ?>
                      <tr>
                        <td><?= htmlspecialchars($item['code']) ?></td>
                        <td><?= htmlspecialchars($item['name']) ?></td>
                        <td><?= htmlspecialchars($item['size']) ?></td>
                        <td><?= htmlspecialchars($item['reason']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>
          <?php if (isset($import_message['imported_count']) && isset($import_message['skipped_count'])): ?>
            <div class="mt-2 small">
              <strong>Import Summary:</strong><br>
              • Imported: <?= $import_message['imported_count'] ?> items<br>
              • Skipped: <?= $import_message['skipped_count'] ?> items (not found in database)
              <?php if (isset($import_message['delimiter'])): ?>
                <br>• File format: <?= $import_message['delimiter'] === "\t" ? "Tab-Separated (TSV)" : ($import_message['delimiter'] === ";" ? "Semicolon-Separated" : "Comma-Separated (CSV)") ?>
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
        <?php if ($first_batch_data): ?>
        <div class="mt-2 small text-muted">
          <i class="fas fa-filter"></i> Showing only items from first batch (<?= date('d M Y', strtotime($first_batch_data['date'])) ?>) with no prior purchases
        </div>
        <?php endif; ?>
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
let firstBatchDate = <?= $first_batch_data ? "'" . $first_batch_data['date'] . "'" : "null" ?>;

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
        
        console.log('Loading items with params:', Object.fromEntries(params));
        
        const response = await fetch('opening_balance.php?' + params);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        console.log('AJAX response:', data);
        console.log('Items count:', data.items ? data.items.length : 0);
        console.log('Total count:', data.total);
        
        if (!data || !Array.isArray(data.items)) {
            console.error('Invalid response format:', data);
            itemsTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-danger">Invalid response from server</td></tr>';
            isLoading = false;
            tableLoading.style.display = 'none';
            return;
        }
        
        if (!append) {
            items = data.items;
            totalItems = data.total;
            hasMore = data.has_more;
            // Store first batch date from response if available
            if (data.first_batch_date) {
                firstBatchDate = data.first_batch_date;
            }
        } else {
            items = [...items, ...data.items];
            hasMore = data.has_more;
        }
        
        console.log('Rendering', items.length, 'items');
        
        renderItems(append);
        
        if (hasMore) {
            showLoadMore();
        } else {
            hideLoadMore();
        }
        
        updateItemCounts();
        
    } catch (error) {
        console.error('Error loading items:', error);
        itemsTableBody.innerHTML = '<tr><td colspan="4" class="text-center text-danger">Error loading items: ' + error.message + '</td></tr>';
    } finally {
        isLoading = false;
        tableLoading.style.display = 'none';
    }
}

// Render items to table
function renderItems(append = false) {
    console.log('renderItems called, append:', append, ', items length:', items ? items.length : 0);
    
    if (!append) {
        itemsTableBody.innerHTML = '';
    }
    
    if (!items || items.length === 0) {
        let message = 'No items found';
        if (!firstBatchDate) {
            message = 'No opening balances found. Please import opening balances using the CSV import feature.';
        } else if (totalItems > 0) {
            message = 'No items to display. Please try a different search or view.';
        }
        itemsTableBody.innerHTML = '<tr><td colspan="4" class="text-center py-4">' + message + '</td></tr>';
        console.log('No items to render, showing message:', message);
        return;
    }
    
    let html = '';
    items.forEach(function(item, index) {
        // Build hierarchy badges
        let hierarchyHtml = '';
        if (item.category_name) {
            hierarchyHtml += '<span class="hierarchy-badge badge-category">' + escapeHtml(item.category_name) + '</span> ';
        }
        if (item.class_name) {
            hierarchyHtml += '<span class="hierarchy-badge badge-class">' + escapeHtml(item.class_name) + '</span> ';
        }
        if (item.subclass_name) {
            hierarchyHtml += '<span class="hierarchy-badge badge-subclass">' + escapeHtml(item.subclass_name) + '</span> ';
        }
        
        const sizeDesc = item.size_desc || 'N/A';
        const mlVolume = item.ml_volume > 0 ? getVolumeLabel(item.ml_volume) : '';
        const currentStock = item.current_stock || 0;
        
        html += '<tr>';
        html += '<td><strong>' + escapeHtml(item.code) + '</strong></td>';
        html += '<td>';
        html += '<div>' + escapeHtml(item.details) + '</div>';
        html += '<div class="size-info mt-1">' + hierarchyHtml + '</div>';
        html += '</td>';
        html += '<td>';
        html += '<div>' + escapeHtml(sizeDesc) + '</div>';
        html += '<div class="size-info">' + mlVolume + '</div>';
        html += '</td>';
        html += '<td class="company-column">';
        html += '<input type="number" name="opening_stock[' + escapeHtml(item.code) + ']" ';
        html += 'value="' + currentStock + '" min="0" ';
        html += 'class="form-control opening-balance-input" ';
        html += 'data-original="' + currentStock + '">';
        html += '<input type="hidden" name="original_stock[' + escapeHtml(item.code) + ']" ';
        html += 'value="' + currentStock + '">';
        html += '</td>';
        html += '</tr>';
    });
    
    if (append) {
        itemsTableBody.insertAdjacentHTML('beforeend', html);
    } else {
        itemsTableBody.innerHTML = html;
    }
    
    console.log('Items rendered successfully, row count:', items.length);
    
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
    // Always use ML format for consistency
    // 1000ml = 1000 ML, 1500ml = 1500 ML, etc.
    if (volume >= 1000) {
        return volume + ' ML';
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
    
    // Show loading with spinner
    loadingEl.style.display = 'block';
    loadingEl.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div><p class="mt-2">Loading volume summary...</p></div>';
    contentEl.style.display = 'none';
    contentEl.innerHTML = '';
    
    try {
        const params = new URLSearchParams({
            ajax: 'volume_summary',
            mode: currentMode,
            t: Date.now() // Add timestamp to prevent caching
        });
        
        console.log('Fetching volume summary...');
        const response = await fetch('opening_balance.php?' + params.toString());
        
        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Get the response text first
        const responseText = await response.text();
        console.log('Raw response:', responseText.substring(0, 200) + '...');
        
        // Try to parse as JSON
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('Failed to parse JSON. Response starts with:', responseText.substring(0, 100));
            // Check if it's HTML (starts with <)
            if (responseText.trim().startsWith('<')) {
                throw new Error('Server returned HTML instead of JSON. There might be a PHP error.');
            } else {
                throw new Error('Invalid JSON response from server');
            }
        }
        
        console.log('Volume summary data received:', data);
        
        // Check if we have an error in the response
        if (data.error) {
            throw new Error(data.error);
        }
        
        // Check if data is empty
        if (!data || Object.keys(data).length === 0) {
            loadingEl.innerHTML = '<div class="alert alert-warning">No volume summary data available</div>';
            return;
        }
        
        // Check if any category has non-zero values
        let hasData = false;
        for (let category in data) {
            if (data[category] && typeof data[category] === 'object') {
                for (let size in data[category]) {
                    if (data[category][size] > 0) {
                        hasData = true;
                        break;
                    }
                }
            }
            if (hasData) break;
        }
        
        if (!hasData) {
            loadingEl.innerHTML = '<div class="alert alert-info">No stock data available for the current selection.</div>';
            return;
        }
        
        // Generate and display the table
        const html = generateVolumeSummaryHTML(data);
        loadingEl.style.display = 'none';
        contentEl.innerHTML = html;
        contentEl.style.display = 'block';
        
    } catch (error) {
        console.error('Error in loadVolumeSummary:', error);
        loadingEl.innerHTML = `<div class="alert alert-danger">
            <strong>Error loading volume summary:</strong> ${error.message}<br>
            <small class="text-muted">Check browser console for details.</small>
        </div>`;
    }
}

// Generate volume summary HTML with improved styling and sorted sizes (largest to smallest)
function generateVolumeSummaryHTML(data) {
    const categories = ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER', 'COUNTRY LIQUOR'];
    
    // Define all sizes with their numeric values for sorting
    const sizeDefinitions = [
        { label: '50 ML', value: 50 },
        { label: '60 ML', value: 60 },
        { label: '90 ML', value: 90 },
        { label: '170 ML', value: 170 },
        { label: '180 ML', value: 180 },
        { label: '200 ML', value: 200 },
        { label: '250 ML', value: 250 },
        { label: '275 ML', value: 275 },
        { label: '330 ML', value: 330 },
        { label: '355 ML', value: 355 },
        { label: '375 ML', value: 375 },
        { label: '500 ML', value: 500 },
        { label: '650 ML', value: 650 },
        { label: '700 ML', value: 700 },
        { label: '750 ML', value: 750 },
        { label: '1000 ML', value: 1000 },
        { label: '1.5L', value: 1500 },
        { label: '1.75L', value: 1750 },
        { label: '2L', value: 2000 },
        { label: '3L', value: 3000 },
        { label: '4.5L', value: 4500 },
        { label: '15L', value: 15000 },
        { label: '20L', value: 20000 },
        { label: '30L', value: 30000 },
        { label: '50L', value: 50000 }
    ];
    
    // Sort sizes from largest to smallest (descending order)
    const sortedSizes = [...sizeDefinitions].sort((a, b) => b.value - a.value);
    const allSizes = sortedSizes.map(s => s.label);
    
    // Calculate summary statistics
    let totalBottles = 0;
    let categoriesWithData = 0;
    let sizesWithData = {};
    
    categories.forEach(category => {
        if (data[category]) {
            let categoryHasData = false;
            Object.values(data[category]).forEach(val => {
                if (val > 0) {
                    totalBottles += val;
                    categoryHasData = true;
                }
            });
            if (categoryHasData) categoriesWithData++;
            
            // Count sizes with data
            Object.entries(data[category]).forEach(([size, val]) => {
                if (val > 0) {
                    sizesWithData[size] = (sizesWithData[size] || 0) + val;
                }
            });
        }
    });
    
    // Generate summary cards HTML
    let html = `
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h6 class="card-title"><i class="fas fa-wine-bottle me-2"></i>Total Bottles</h6>
                        <h2 class="mb-0">${totalBottles.toLocaleString()}</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6 class="card-title"><i class="fas fa-tags me-2"></i>Categories with Stock</h6>
                        <h2 class="mb-0">${categoriesWithData}</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6 class="card-title"><i class="fas fa-ruler-combined me-2"></i>Different Sizes</h6>
                        <h2 class="mb-0">${Object.keys(sizesWithData).length}</h2>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Generate table HTML with sticky header
    html += '<div class="table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #dee2e6;">';
    html += '<table class="table table-bordered table-sm table-hover" style="font-size: 11px; margin-bottom: 0;">';
    html += '<thead class="sticky-top" style="background: #343a40; color: white; z-index: 100;">';
    html += '<tr><th style="position: sticky; left: 0; background: #343a40; z-index: 101;">Category</th>';
    
    allSizes.forEach(size => {
        const hasData = sizesWithData[size] > 0;
        const bgColor = hasData ? 'background: #28a745;' : '';
        html += `<th style="white-space: nowrap; ${bgColor}">${size}</th>`;
    });
    
    html += '<th style="background: #007bff; color: white;">Category Total</th>';
    html += '</tr></thead><tbody>';
    
    let grandTotal = 0;
    
    categories.forEach(category => {
        if (data[category]) {
            let rowTotal = 0;
            let rowHasData = false;
            
            // Check if row has any data
            allSizes.forEach(size => {
                const val = data[category][size] || 0;
                if (val > 0) {
                    rowTotal += val;
                    rowHasData = true;
                }
            });
            
            if (rowHasData) {
                grandTotal += rowTotal;
                
                // Category row with color coding
                let categoryClass = '';
                if (category === 'SPIRITS') categoryClass = 'table-primary';
                else if (category === 'WINE') categoryClass = 'table-danger';
                else if (category === 'FERMENTED BEER') categoryClass = 'table-warning';
                else if (category === 'MILD BEER') categoryClass = 'table-success';
                else if (category === 'COUNTRY LIQUOR') categoryClass = 'table-info';
                
                html += `<tr class="${categoryClass}">`;
                html += `<td style="position: sticky; left: 0; background: inherit; font-weight: bold;">${category}</td>`;
                
                allSizes.forEach(size => {
                    const value = data[category][size] || 0;
                    if (value > 0) {
                        html += `<td class="text-center" style="background: rgba(40, 167, 69, 0.2); font-weight: bold;">${value.toLocaleString()}</td>`;
                    } else {
                        html += `<td class="text-center text-muted">-</td>`;
                    }
                });
                
                html += `<td class="text-center" style="background: rgba(0, 123, 255, 0.2); font-weight: bold;">${rowTotal.toLocaleString()}</td>`;
                html += '</tr>';
            }
        }
    });
    
    // Grand total row
    html += '<tr style="background: #007bff; color: white; font-weight: bold;">';
    html += '<td style="position: sticky; left: 0; background: #007bff;">GRAND TOTAL</td>';
    
    allSizes.forEach(size => {
        const value = sizesWithData[size] || 0;
        if (value > 0) {
            html += `<td class="text-center">${value.toLocaleString()}</td>`;
        } else {
            html += `<td class="text-center">-</td>`;
        }
    });
    
    html += `<td class="text-center">${grandTotal.toLocaleString()}</td>`;
    html += '</tr>';
    
    html += '</tbody></table></div>';
    
    // Add first batch date info
    html += `<div class="mt-3 text-muted small">
        <i class="fas fa-info-circle me-1"></i>
        First Batch Date: ${firstBatchDate ? new Date(firstBatchDate).toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' }) : 'N/A'}
    </div>`;
    
    return html;
}

// Print volume summary
function printVolumeSummary() {
    const contentEl = document.getElementById('volumeSummaryContent');
    const companyName = document.getElementById('companyName').textContent;
    const currentMode = document.getElementById('currentMode').textContent;
    const financialYear = document.getElementById('financialYear').textContent;
    
    if (!contentEl || contentEl.innerHTML.trim() === '') {
        alert('No data to print. Please load the volume summary first.');
        return;
    }
    
    const content = contentEl.innerHTML;
    const printWindow = window.open('', '_blank');
    
    if (!printWindow) {
        alert('Please allow popups to print the volume summary.');
        return;
    }
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Opening Balance Volume Summary</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
            <style>
                @media print {
                    .no-print { display: none !important; }
                    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
                body { 
                    padding: 15px; 
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                }
                .print-header { 
                    text-align: center; 
                    margin-bottom: 20px;
                    border-bottom: 2px solid #343a40;
                    padding-bottom: 15px;
                }
                .print-header h2 {
                    margin-bottom: 10px;
                    color: #343a40;
                }
                .print-header h4 {
                    color: #6c757d;
                    margin-bottom: 5px;
                }
                .print-header p {
                    margin-bottom: 3px;
                    color: #6c757d;
                    font-size: 12px;
                }
                .card {
                    border: 1px solid #dee2e6;
                    border-radius: 5px;
                    margin-bottom: 15px;
                }
                .card-body {
                    padding: 10px;
                }
                .card h6 {
                    font-size: 11px;
                    margin-bottom: 5px;
                }
                .card h2 {
                    font-size: 24px;
                    margin-bottom: 0;
                }
                .table { 
                    font-size: 9px; 
                    margin-bottom: 0;
                }
                th, td { 
                    padding: 3px !important; 
                    text-align: center;
                    font-size: 9px;
                }
                .table-primary { background-color: #cff4fc !important; }
                .table-danger { background-color: #f8d7da !important; }
                .table-warning { background-color: #fff3cd !important; }
                .table-success { background-color: #d1e7dd !important; }
                .table-info { background-color: #cff4fc !important; }
                .bg-primary { background-color: #0d6efd !important; }
                .bg-success { background-color: #198754 !important; }
                .bg-info { background-color: #0dcaf0 !important; }
            </style>
        </head>
        <body>
            <div class="print-header">
                <h2><i class="fas fa-wine-bottle me-2"></i>Opening Balance Volume Summary</h2>
                <h4>${companyName}</h4>
                <p><strong>Mode:</strong> ${currentMode} | <strong>Financial Year:</strong> ${financialYear}</p>
                <p class="text-muted">Generated on: ${new Date().toLocaleString()}</p>
            </div>
            ${content}
            <script>
                window.onload = function() { 
                    window.print(); 
                    setTimeout(() => window.close(), 500); 
                };
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