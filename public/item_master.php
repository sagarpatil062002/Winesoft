<?php
session_start();

// Remove time limit for long-running imports
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

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
$fin_year = $_SESSION['FIN_YEAR_ID'];

include_once "../config/db.php"; // MySQLi connection in $conn
require_once 'license_functions.php';

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Check if company columns exist in tblitem_stock, if not create them
$check_columns_query = "SELECT COUNT(*) as count FROM information_schema.columns 
                       WHERE table_name = 'tblitem_stock' 
                       AND column_name IN ('OPENING_STOCK$comp_id', 'CURRENT_STOCK$comp_id')";
$check_result = $conn->query($check_columns_query);
$columns_exist = $check_result->fetch_assoc()['count'] == 2;

if (!$columns_exist) {
    // Add company-specific columns as INT instead of DECIMAL
    $add_col1_query = "ALTER TABLE tblitem_stock ADD COLUMN OPENING_STOCK$comp_id INT DEFAULT 0";
    $add_col2_query = "ALTER TABLE tblitem_stock ADD COLUMN CURRENT_STOCK$comp_id INT DEFAULT 0";
    
    $conn->query($add_col1_query);
    $conn->query($add_col2_query);
}

// Check if company daily stock table exists, if not create it with dynamic day columns
$check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'tbldailystock_$comp_id'";
$check_table_result = $conn->query($check_table_query);
$table_exists = $check_table_result->fetch_assoc()['count'] > 0;

if (!$table_exists) {
    // Create company-specific daily stock table with dynamic day columns
    $create_table_query = "CREATE TABLE tbldailystock_$comp_id (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock_$comp_id` (`STK_MONTH`,`ITEM_CODE`),
        KEY `ITEM_CODE_$comp_id` (`ITEM_CODE`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $conn->query($create_table_query);
    
    // Add day columns for the current month
    $current_month = date('Y-m');
    addDayColumnsForMonth($conn, $comp_id, $current_month);
}

// Function to add day columns for a specific month
function addDayColumnsForMonth($conn, $comp_id, $month) {
    $year_month = explode('-', $month);
    $year = $year_month[0];
    $month_num = $year_month[1];
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
    
    for ($day = 1; $day <= $days_in_month; $day++) {
        $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
        
        // Check if column exists
        $check_col_query = "SELECT COUNT(*) as count FROM information_schema.columns 
                           WHERE table_name = 'tbldailystock_$comp_id' 
                           AND column_name = 'DAY_{$day_padded}_OPEN'";
        $check_result = $conn->query($check_col_query);
        $col_exists = $check_result->fetch_assoc()['count'] > 0;
        
        if (!$col_exists) {
            // Add opening, purchase, sales, and closing columns for this day as INT
            $add_open_col = "ALTER TABLE tbldailystock_$comp_id ADD COLUMN DAY_{$day_padded}_OPEN INT DEFAULT 0";
            $add_purchase_col = "ALTER TABLE tbldailystock_$comp_id ADD COLUMN DAY_{$day_padded}_PURCHASE INT DEFAULT 0";
            $add_sales_col = "ALTER TABLE tbldailystock_$comp_id ADD COLUMN DAY_{$day_padded}_SALES INT DEFAULT 0";
            $add_closing_col = "ALTER TABLE tbldailystock_$comp_id ADD COLUMN DAY_{$day_padded}_CLOSING INT DEFAULT 0";
            
            $conn->query($add_open_col);
            $conn->query($add_purchase_col);
            $conn->query($add_sales_col);
            $conn->query($add_closing_col);
        }
    }
}

// Function to archive previous month's data
function archiveMonthlyData($conn, $comp_id, $month) {
    // Extract year and month for proper naming (e.g., "2025-04" becomes "2025_04")
    $year_month = explode('-', $month);
    $year = $year_month[0];
    $month_num = $year_month[1];
    $archive_table = "tbldailystock_archive_{$comp_id}_{$year}_{$month_num}";
    
    // Check if archive table exists
    $check_archive_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                           WHERE table_schema = DATABASE() 
                           AND table_name = '$archive_table'";
    $check_result = $conn->query($check_archive_query);
    $archive_exists = $check_result->fetch_assoc()['count'] > 0;
    
    if (!$archive_exists) {
        // Create archive table with the same structure
        $create_archive_query = "CREATE TABLE $archive_table LIKE tbldailystock_$comp_id";
        if (!$conn->query($create_archive_query)) {
            error_log("Error creating archive table: " . $conn->error);
            return false;
        }
        
        // Add day columns for the archive month
        addDayColumnsForMonth($conn, $comp_id, $month);
    }
    
    // Copy data to archive
    $copy_data_query = "INSERT INTO $archive_table SELECT * FROM tbldailystock_$comp_id WHERE STK_MONTH = ?";
    $copy_stmt = $conn->prepare($copy_data_query);
    $copy_stmt->bind_param("s", $month);
    if (!$copy_stmt->execute()) {
        error_log("Error copying data to archive: " . $copy_stmt->error);
        $copy_stmt->close();
        return false;
    }
    $copy_stmt->close();
    
    // Delete archived data from main table
    $delete_query = "DELETE FROM tbldailystock_$comp_id WHERE STK_MONTH = ?";
    $delete_stmt = $conn->prepare($delete_query);
    $delete_stmt->bind_param("s", $month);
    if (!$delete_stmt->execute()) {
        error_log("Error deleting archived data: " . $delete_stmt->error);
        $delete_stmt->close();
        return false;
    }
    $delete_stmt->close();
    
    return true;
}

// NEW FUNCTION: Create archive tables for previous months in current financial year
function createFinancialYearArchiveTables($conn, $comp_id, $fin_year) {
    // Extract the starting year from financial year (e.g., "2025-26" -> "2025")
    $fin_year_parts = explode('-', $fin_year);
    $start_year = $fin_year_parts[0];
    
    // Financial year starts from April (4) to March (3) next year
    $current_month = date('n'); // Current month number
    $current_year = date('Y');
    
    // Determine which months need archive tables
    $months_to_archive = [];
    
    if ($current_month >= 4) {
        // Current financial year (April to current month)
        for ($month = 4; $month <= $current_month; $month++) {
            $months_to_archive[] = $month;
        }
    } else {
        // Previous financial year (April to December) + current year (January to current month)
        for ($month = 4; $month <= 12; $month++) {
            $months_to_archive[] = $month;
        }
        for ($month = 1; $month <= $current_month; $month++) {
            $months_to_archive[] = $month;
        }
    }
    
    foreach ($months_to_archive as $month_num) {
        $month_padded = str_pad($month_num, 2, '0', STR_PAD_LEFT);
        
        // Determine the year for this month
        if ($month_num >= 4) {
            $year = $start_year;
        } else {
            $year = intval($start_year) + 1;
        }
        
        $month = $year . '-' . $month_padded;
        $archive_table = "tbldailystock_archive_{$comp_id}_{$year}_{$month_padded}";
        
        // Check if archive table already exists
        $check_archive_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                               WHERE table_schema = DATABASE() 
                               AND table_name = '$archive_table'";
        $check_result = $conn->query($check_archive_query);
        $archive_exists = $check_result->fetch_assoc()['count'] > 0;
        
        if (!$archive_exists) {
            // Create archive table with the same structure as the main table
            $create_archive_query = "CREATE TABLE $archive_table LIKE tbldailystock_$comp_id";
            if ($conn->query($create_archive_query)) {
                error_log("Created archive table: $archive_table");
                
                // Add day columns for the archive month
                addDayColumnsForMonth($conn, $comp_id, $month);
                
            } else {
                error_log("Error creating archive table: " . $conn->error);
            }
        }
    }
}

// UPDATED FUNCTION: Initialize daily stock record for an item with all zeros except current day
function initializeDailyStockRecord($conn, $comp_id, $item_code, $liq_flag, $opening_balance) {
    $current_month = date('Y-m');
    $today = date('d');
    $today_padded = str_pad($today, 2, '0', STR_PAD_LEFT);
    
    // Get days in current month
    $year_month = explode('-', $current_month);
    $year = $year_month[0];
    $month_num = $year_month[1];
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
    
    // Build the INSERT query with all day columns set to 0, except today's opening/closing
    $columns = ['STK_MONTH', 'ITEM_CODE', 'LIQ_FLAG'];
    $placeholders = ['?', '?', '?'];
    $values = [$current_month, $item_code, $liq_flag];
    $types = 'sss';
    
    // Add all day columns (set to 0 initially)
    for ($day = 1; $day <= $days_in_month; $day++) {
        $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
        
        $columns[] = "DAY_{$day_padded}_OPEN";
        $columns[] = "DAY_{$day_padded}_PURCHASE";
        $columns[] = "DAY_{$day_padded}_SALES";
        $columns[] = "DAY_{$day_padded}_CLOSING";
        
        if ($day_padded == $today_padded) {
            // For today, set opening and closing to the actual opening balance, others to 0
            $placeholders[] = '?';
            $placeholders[] = '?';
            $placeholders[] = '?';
            $placeholders[] = '?';
            $values[] = $opening_balance; // OPEN = opening balance
            $values[] = 0; // PURCHASE = 0
            $values[] = 0; // SALES = 0
            $values[] = $opening_balance; // CLOSING = opening balance
            $types .= 'iiii';
        } else {
            // For other days, set all to 0
            $placeholders[] = '?';
            $placeholders[] = '?';
            $placeholders[] = '?';
            $placeholders[] = '?';
            $values[] = 0; // OPEN = 0
            $values[] = 0; // PURCHASE = 0
            $values[] = 0; // SALES = 0
            $values[] = 0; // CLOSING = 0
            $types .= 'iiii';
        }
    }
    
    $columns_str = implode(', ', $columns);
    $placeholders_str = implode(', ', $placeholders);
    
    $insert_query = "INSERT INTO tbldailystock_$comp_id ($columns_str) VALUES ($placeholders_str)";
    
    $stmt = $conn->prepare($insert_query);
    if ($stmt) {
        $stmt->bind_param($types, ...$values);
        $result = $stmt->execute();
        $stmt->close();
        return $result;
    }
    
    return false;
}

// NEW FUNCTION: Add item to archive tables for all months in current financial year
function addItemToArchiveTables($conn, $comp_id, $fin_year, $item_code, $liq_flag, $opening_balance) {
    // Extract the starting year from financial year (e.g., "2025-26" -> "2025")
    $fin_year_parts = explode('-', $fin_year);
    $start_year = $fin_year_parts[0];
    
    // Financial year starts from April (4) to March (3) next year
    $current_month = date('n'); // Current month number
    $current_year = date('Y');
    
    // Determine which months need to have this item added to archive
    $months_to_process = [];
    
    if ($current_month >= 4) {
        // Current financial year (April to current month)
        for ($month = 4; $month <= $current_month; $month++) {
            $months_to_process[] = $month;
        }
    } else {
        // Previous financial year (April to December) + current year (January to current month)
        for ($month = 4; $month <= 12; $month++) {
            $months_to_process[] = $month;
        }
        for ($month = 1; $month <= $current_month; $month++) {
            $months_to_process[] = $month;
        }
    }
    
    foreach ($months_to_process as $month_num) {
        $month_padded = str_pad($month_num, 2, '0', STR_PAD_LEFT);
        
        // Determine the year for this month
        if ($month_num >= 4) {
            $year = $start_year;
        } else {
            $year = intval($start_year) + 1;
        }
        
        $month = $year . '-' . $month_padded;
        $archive_table = "tbldailystock_archive_{$comp_id}_{$year}_{$month_padded}";
        
        // Check if archive table exists
        $check_archive_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                               WHERE table_schema = DATABASE() 
                               AND table_name = '$archive_table'";
        $check_result = $conn->query($check_archive_query);
        $archive_exists = $check_result->fetch_assoc()['count'] > 0;
        
        if ($archive_exists) {
            // Check if item already exists in archive table for this month
            $check_item_query = "SELECT COUNT(*) as count FROM $archive_table WHERE ITEM_CODE = ? AND STK_MONTH = ?";
            $check_stmt = $conn->prepare($check_item_query);
            $check_stmt->bind_param("ss", $item_code, $month);
            $check_stmt->execute();
            $item_result = $check_stmt->get_result();
            $item_exists = $item_result->fetch_assoc()['count'] > 0;
            $check_stmt->close();
            
            if (!$item_exists) {
                // Add item to archive table with all zeros
                $today = date('d');
                $today_padded = str_pad($today, 2, '0', STR_PAD_LEFT);
                
                // Get days in the archive month
                $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
                
                // Build the INSERT query with all day columns set to 0
                $columns = ['STK_MONTH', 'ITEM_CODE', 'LIQ_FLAG'];
                $placeholders = ['?', '?', '?'];
                $values = [$month, $item_code, $liq_flag];
                $types = 'sss';
                
                // Add all day columns (set to 0)
                for ($day = 1; $day <= $days_in_month; $day++) {
                    $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                    
                    $columns[] = "DAY_{$day_padded}_OPEN";
                    $columns[] = "DAY_{$day_padded}_PURCHASE";
                    $columns[] = "DAY_{$day_padded}_SALES";
                    $columns[] = "DAY_{$day_padded}_CLOSING";
                    
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $values[] = 0; // OPEN = 0
                    $values[] = 0; // PURCHASE = 0
                    $values[] = 0; // SALES = 0
                    $values[] = 0; // CLOSING = 0
                    $types .= 'iiii';
                }
                
                $columns_str = implode(', ', $columns);
                $placeholders_str = implode(', ', $placeholders);
                
                $insert_query = "INSERT INTO $archive_table ($columns_str) VALUES ($placeholders_str)";
                
                $stmt = $conn->prepare($insert_query);
                if ($stmt) {
                    $stmt->bind_param($types, ...$values);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
    }
}

// Create archive tables for current financial year
createFinancialYearArchiveTables($conn, $comp_id, $fin_year);

// Check if we need to switch to a new month
$current_month = date('Y-m');
$check_current_month_query = "SELECT COUNT(*) as count FROM tbldailystock_$comp_id WHERE STK_MONTH = ?";
$check_month_stmt = $conn->prepare($check_current_month_query);
$check_month_stmt->bind_param("s", $current_month);
$check_month_stmt->execute();
$month_result = $check_month_stmt->get_result();
$current_month_exists = $month_result->fetch_assoc()['count'] > 0;
$check_month_stmt->close();

if (!$current_month_exists) {
    // Check if we have previous month data to archive
    $previous_month = date('Y-m', strtotime('-1 month'));
    $check_prev_month_query = "SELECT COUNT(*) as count FROM tbldailystock_$comp_id WHERE STK_MONTH = ?";
    $check_prev_stmt = $conn->prepare($check_prev_month_query);
    $check_prev_stmt->bind_param("s", $previous_month);
    $check_prev_stmt->execute();
    $prev_result = $check_prev_stmt->get_result();
    $prev_month_exists = $prev_result->fetch_assoc()['count'] > 0;
    $check_prev_stmt->close();
    
    if ($prev_month_exists) {
        // Archive previous month's data
        archiveMonthlyData($conn, $comp_id, $previous_month);
    }
    
    // Add day columns for the new month
    addDayColumnsForMonth($conn, $comp_id, $current_month);
}

// Function to get yesterday's closing stock for today's opening
function getYesterdayClosingStock($conn, $comp_id, $item_code, $mode) {
    $yesterday = date('d', strtotime('-1 day'));
    $yesterday_padded = str_pad($yesterday, 2, '0', STR_PAD_LEFT);
    $current_month = date('Y-m');
    
    $query = "SELECT DAY_{$yesterday_padded}_CLOSING as closing_qty FROM tbldailystock_$comp_id 
              WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("sss", $current_month, $item_code, $mode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return (int)$row['closing_qty'];
    }
    
    return 0; // Return 0 if no record found for yesterday
}

// Function to update daily stock
function updateDailyStock($conn, $comp_id, $item_code, $mode, $opening_stock, $closing_stock, $purchase_qty = 0, $sales_qty = 0) {
    $today = date('d');
    $today_padded = str_pad($today, 2, '0', STR_PAD_LEFT);
    $current_month = date('Y-m');
    
    // Convert to integers
    $opening_stock = (int)$opening_stock;
    $closing_stock = (int)$closing_stock;
    $purchase_qty = (int)$purchase_qty;
    $sales_qty = (int)$sales_qty;
    
    // Check if record exists for this month
    $check_query = "SELECT COUNT(*) as count FROM tbldailystock_$comp_id 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("sss", $current_month, $item_code, $mode);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Update existing record
        $update_query = "UPDATE tbldailystock_$comp_id 
                        SET DAY_{$today_padded}_OPEN = ?, 
                            DAY_{$today_padded}_PURCHASE = DAY_{$today_padded}_PURCHASE + ?,
                            DAY_{$today_padded}_SALES = DAY_{$today_padded}_SALES + ?,
                            DAY_{$today_padded}_CLOSING = ?,
                            LAST_UPDATED = CURRENT_TIMESTAMP 
                        WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iiiiiss", $opening_stock, $purchase_qty, $sales_qty, $closing_stock, $current_month, $item_code, $mode);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new record
        $insert_query = "INSERT INTO tbldailystock_$comp_id 
                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_{$today_padded}_OPEN, DAY_{$today_padded}_PURCHASE, DAY_{$today_padded}_SALES, DAY_{$today_padded}_CLOSING) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("sssiiii", $current_month, $item_code, $mode, $opening_stock, $purchase_qty, $sales_qty, $closing_stock);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
}

// Function to update item stock information
function updateItemStock($conn, $comp_id, $item_code, $liqFlag, $opening_balance) {
    // Update tblitem_stock
    $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_stock_query);
    $check_stmt->bind_param("s", $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $stock_exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    $opening_col = "OPENING_STOCK$comp_id";
    $current_col = "CURRENT_STOCK$comp_id";
    
    if ($stock_exists) {
        // Update existing stock record - only update company-specific columns
        $update_query = "UPDATE tblitem_stock SET $opening_col = ?, $current_col = ? WHERE ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iis", $opening_balance, $opening_balance, $item_code);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new stock record with only company-specific columns
        // Set other company columns to 0 or NULL
        $insert_query = "INSERT INTO tblitem_stock (ITEM_CODE, $opening_col, $current_col) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("sii", $item_code, $opening_balance, $opening_balance);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    // Update daily stock for today - ONLY if record exists, don't create new one here
    $today = date('d');
    $today_padded = str_pad($today, 2, '0', STR_PAD_LEFT);
    $current_month = date('Y-m');
    
    // Check if daily stock record exists
    $check_daily_query = "SELECT COUNT(*) as count FROM tbldailystock_$comp_id 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
    $check_daily_stmt = $conn->prepare($check_daily_query);
    $check_daily_stmt->bind_param("sss", $current_month, $item_code, $liqFlag);
    $check_daily_stmt->execute();
    $daily_result = $check_daily_stmt->get_result();
    $daily_exists = $daily_result->fetch_assoc()['count'] > 0;
    $check_daily_stmt->close();
    
    if ($daily_exists) {
        // Update existing daily record
        $update_daily_query = "UPDATE tbldailystock_$comp_id 
                              SET DAY_{$today_padded}_OPEN = ?, 
                                  DAY_{$today_padded}_CLOSING = ?,
                                  LAST_UPDATED = CURRENT_TIMESTAMP 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
        $update_daily_stmt = $conn->prepare($update_daily_query);
        $update_daily_stmt->bind_param("iisss", $opening_balance, $opening_balance, $current_month, $item_code, $liqFlag);
        $update_daily_stmt->execute();
        $update_daily_stmt->close();
    }
    // Don't create new record here - let initializeDailyStockRecord handle it
}

// Fetch class descriptions from tblclass
$classDescriptions = [];
$classQuery = "SELECT SGROUP, `DESC` FROM tblclass";
$classResult = $conn->query($classQuery);
while ($row = $classResult->fetch_assoc()) {
    $classDescriptions[$row['SGROUP']] = $row['DESC'];
}

// Fetch subclass descriptions from tblsubclass
$subclassDescriptions = [];
$validItemGroups = []; // To store valid ITEM_GROUP values for each LIQ_FLAG
$subclassQuery = "SELECT ITEM_GROUP, `DESC`, LIQ_FLAG FROM tblsubclass";
$subclassResult = $conn->query($subclassQuery);
while ($row = $subclassResult->fetch_assoc()) {
    $subclassDescriptions[$row['ITEM_GROUP']][$row['LIQ_FLAG']] = $row['DESC'];
    $validItemGroups[$row['LIQ_FLAG']][] = $row['ITEM_GROUP'];
}

// Function to get ITEM_GROUP based on Subclass description and LIQ_FLAG
function getValidItemGroup($subclass, $liqFlag, $conn) {
    if (empty($subclass)) {
        // Get default ITEM_GROUP for the given LIQ_FLAG (usually 'O' for Others)
        $query = "SELECT ITEM_GROUP FROM tblsubclass WHERE LIQ_FLAG = ? AND ITEM_GROUP = 'O' LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $liqFlag);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['ITEM_GROUP'];
        }
        return 'O'; // Fallback
    }
    
    // Query tblsubclass to find a matching description with the same LIQ_FLAG
    $query = "SELECT ITEM_GROUP FROM tblsubclass WHERE `DESC` = ? AND LIQ_FLAG = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $subclass, $liqFlag);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['ITEM_GROUP'];
    }
    
    // If no exact match found, try partial matching with same LIQ_FLAG
    $query = "SELECT ITEM_GROUP FROM tblsubclass WHERE `DESC` LIKE ? AND LIQ_FLAG = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $searchTerm = "%" . $subclass . "%";
    $stmt->bind_param("ss", $searchTerm, $liqFlag);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['ITEM_GROUP'];
    }
    
    // Fallback to 'O' for Others with the same LIQ_FLAG
    $query = "SELECT ITEM_GROUP FROM tblsubclass WHERE LIQ_FLAG = ? AND ITEM_GROUP = 'O' LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $liqFlag);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['ITEM_GROUP'];
    }
    
    return 'O'; // Final fallback
}

// Function to detect class from item name
function detectClassFromItemName($itemName) {
    $itemName = strtoupper($itemName);
    
    // Whisky detection
    if (strpos($itemName, 'WHISKY') !== false || 
        strpos($itemName, 'WHISKEY') !== false ||
        strpos($itemName, 'BLEND') !== false ||
        strpos($itemName, 'SCOTCH') !== false ||
        preg_match('/\b(8PM|OFFICER\'S|MCDOWELL)\b/', $itemName)) {
        return 'W';
    }
    
    // Vodka detection
    if (strpos($itemName, 'VODKA') !== false ||
        preg_match('/\b(ABSOLUT|SMIRNOFF|ROMANOV)\b/', $itemName)) {
        return 'K';
    }
    
    // Wine detection
    if (strpos($itemName, 'WINE') !== false ||
        preg_match('/\b(PORT|SHERRY|CHAMPAGNE)\b/', $itemName)) {
        return 'V';
    }
    
    // Gin detection
    if (strpos($itemName, 'GIN') !== false ||
        preg_match('/\b(BOMBAY|GORDON\'S|TANQUERAY)\b/', $itemName)) {
        return 'G';
    }
    
    // Brandy detection
    if (strpos($itemName, 'BRANDY') !== false ||
        preg_match('/\b(HENNESSY|MARTELL|REMY MARTIN)\b/', $itemName)) {
        return 'D';
    }
    
    // Rum detection
    if (strpos($itemName, 'RUM') !== false ||
        preg_match('/\b(BACARDI|CAPTAIN MORGAN|HAVANA)\b/', $itemName)) {
        return 'R';
    }
    
    // Beer detection
    if (strpos($itemName, 'BEER') !== false ||
        preg_match('/\b(KINGFISHER|TUBORG|CARLSBERG|BUDWEISER)\b/', $itemName)) {
        return 'B';
    }
    
    // Default to Others if no match found
    return 'O';
}

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    
    // Get company's license type and available classes
    $company_id = $_SESSION['CompID'];
    $license_type = getCompanyLicenseType($company_id, $conn);
    $available_classes = getClassesByLicenseType($license_type, $conn);
    
    // Extract class SGROUP values for filtering
    $allowed_classes = [];
    foreach ($available_classes as $class) {
        $allowed_classes[] = $class['SGROUP'];
    }
    
    // Fetch items from tblitemmaster - FILTERED BY LICENSE TYPE
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG
                  FROM tblitemmaster
                  WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
        
        $params = array_merge([$mode], $allowed_classes);
        $types = str_repeat('s', count($params));
    } else {
        // If no classes allowed, show empty result
        $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG
                  FROM tblitemmaster
                  WHERE 1 = 0"; // Always false condition
        $params = [];
        $types = "";
    }
    
    if ($search !== '') {
        $query .= " AND (DETAILS LIKE ? OR CODE LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= "ss";
    }
    
    $query .= " ORDER BY DETAILS ASC";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if ($exportType === 'csv') {
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=items_' . $mode . '_' . date('Y-m-d') . '.csv');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add CSV headers with user-friendly names
        fputcsv($output, array('Code', 'ItemName', 'PrintName', 'Class', 'Subclass', 'PPrice', 'BPrice', 'MPrice', 'RPrice', 'LIQFLAG', 'OpeningBalance'));
        
        // Add data rows with user-friendly mapping
        foreach ($items as $item) {
            // Get opening balance from tblitem_stock - REMOVED LIQ_FLAG CONDITION
            $opening_balance = 0;
            $stock_query = "SELECT OPENING_STOCK{$company_id} as opening 
                           FROM tblitem_stock 
                           WHERE ITEM_CODE = ?";
            $stock_stmt = $conn->prepare($stock_query);
            $stock_stmt->bind_param("s", $item['CODE']);
            $stock_stmt->execute();
            $stock_result = $stock_stmt->get_result();
            
            if ($stock_result->num_rows > 0) {
                $stock_row = $stock_result->fetch_assoc();
                $opening_balance = $stock_row['opening'];
            }
            $stock_stmt->close();
            
            $exportRow = [
                'Code' => $item['CODE'],
                'ItemName' => $item['DETAILS'],
                'PrintName' => $item['Print_Name'],
                'Class' => $item['CLASS'],
                'Subclass' => $item['DETAILS2'],
                'PPrice' => $item['PPRICE'],
                'BPrice' => $item['BPRICE'],
                'MPrice' => $item['MPRICE'],
                'RPrice' => $item['RPRICE'],
                'LIQFLAG' => $item['LIQ_FLAG'],
                'OpeningBalance' => $opening_balance
            ];
            fputcsv($output, $exportRow);
        }
        
        fclose($output);
        exit();
    }
}

// Handle import if form submitted
$importMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && isset($_POST['import_type'])) {
    $importType = $_POST['import_type'];
    $file = $_FILES['import_file'];
    
    // Remove time limits for import processing
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '512M');
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $filePath = $file['tmp_name'];
        $fileName = $file['name'];
        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
        
        try {
            if ($importType === 'csv' && $fileExt === 'csv') {
                // Process CSV file
                $handle = fopen($filePath, 'r');
                if ($handle !== FALSE) {
                    // Get header row to determine column mapping
                    $headers = fgetcsv($handle);
                    $headerMap = array_flip($headers);
                    
                    // Map user-friendly column names to database fields
                    $codeCol = isset($headerMap['Code']) ? $headerMap['Code'] : (isset($headerMap['CODE']) ? $headerMap['CODE'] : 0);
                    $itemNameCol = isset($headerMap['ItemName']) ? $headerMap['ItemName'] : (isset($headerMap['DETAILS']) ? $headerMap['DETAILS'] : 1);
                    $printNameCol = isset($headerMap['PrintName']) ? $headerMap['PrintName'] : (isset($headerMap['Print_Name']) ? $headerMap['Print_Name'] : 2);
                    $subclassCol = isset($headerMap['Subclass']) ? $headerMap['Subclass'] : (isset($headerMap['DETAILS2']) ? $headerMap['DETAILS2'] : 3);
                    $ppriceCol = isset($headerMap['PPrice']) ? $headerMap['PPrice'] : (isset($headerMap['PPRICE']) ? $headerMap['PPRICE'] : 4);
                    $bpriceCol = isset($headerMap['BPrice']) ? $headerMap['BPrice'] : (isset($headerMap['BPRICE']) ? $headerMap['BPRICE'] : 5);
                    $mpriceCol = isset($headerMap['MPrice']) ? $headerMap['MPrice'] : (isset($headerMap['MPRICE']) ? $headerMap['MPRICE'] : 6);
                    $rpriceCol = isset($headerMap['RPrice']) ? $headerMap['RPrice'] : (isset($headerMap['RPRICE']) ? $headerMap['RPRICE'] : 7);
                    $liqFlagCol = isset($headerMap['LIQFLAG']) ? $headerMap['LIQFLAG'] : (isset($headerMap['LIQ_FLAG']) ? $headerMap['LIQ_FLAG'] : 8);
                    $openingBalanceCol = isset($headerMap['OpeningBalance']) ? $headerMap['OpeningBalance'] : (isset($headerMap['OPENING_BALANCE']) ? $headerMap['OPENING_BALANCE'] : 9);
                    
                    $imported = 0;
                    $updated = 0;
                    $errors = 0;
                    $errorDetails = [];
                    
                    // Get company's license type and available classes for validation
                    $company_id = $_SESSION['CompID'];
                    $license_type = getCompanyLicenseType($company_id, $conn);
                    $available_classes = getClassesByLicenseType($license_type, $conn);
                    
                    // Extract class SGROUP values for filtering
                    $allowed_classes = [];
                    foreach ($available_classes as $class) {
                        $allowed_classes[] = $class['SGROUP'];
                    }
                    
                    // Prepare statements for better performance
                    $checkQuery = $conn->prepare("SELECT CODE FROM tblitemmaster WHERE CODE = ? AND LIQ_FLAG = ?");
                    $updateQuery = $conn->prepare("UPDATE tblitemmaster SET Print_Name = ?, DETAILS = ?, DETAILS2 = ?, CLASS = ?, SUB_CLASS = ?, ITEM_GROUP = ?, PPRICE = ?, BPRICE = ?, MPRICE = ?, RPRICE = ? WHERE CODE = ? AND LIQ_FLAG = ?");
                    $insertQuery = $conn->prepare("INSERT INTO tblitemmaster (CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    $rowCount = 0;
                    while (($data = fgetcsv($handle)) !== FALSE) {
                        $rowCount++;
                        
                        if (count($data) >= 9) { // At least 9 required columns now
                            $code = $conn->real_escape_string(trim($data[$codeCol]));
                            $itemName = $conn->real_escape_string(trim($data[$itemNameCol]));
                            $printName = $conn->real_escape_string(trim($data[$printNameCol]));
                            $subclass = $conn->real_escape_string(trim($data[$subclassCol]));
                            $pprice = floatval(trim($data[$ppriceCol]));
                            $bprice = floatval(trim($data[$bpriceCol]));
                            $mprice = floatval(trim($data[$mpriceCol]));
                            $rprice = floatval(trim($data[$rpriceCol]));
                            $liqFlag = '';
                            
                            if (isset($data[$liqFlagCol]) && !empty(trim($data[$liqFlagCol]))) {
                                $liqFlag = $conn->real_escape_string(trim($data[$liqFlagCol]));
                            } else {
                                $liqFlag = $mode; // Use the current mode as default
                            }
                            
                            // Additional validation to ensure LIQ_FLAG is not empty
                            if (empty($liqFlag)) {
                                $errors++;
                                $errorDetails[] = "LIQ_FLAG cannot be empty for item $code";
                                continue; // Skip this row
                            }
                            
                            $openingBalance = isset($data[$openingBalanceCol]) ? intval(trim($data[$openingBalanceCol])) : 0;
                            
                            // Detect class from item name
                            $class = detectClassFromItemName($itemName);
                            
                            // Check if class is allowed for this company's license
                            if (!in_array($class, $allowed_classes)) {
                                $errors++;
                                $errorDetails[] = "Class '$class' not allowed for your license type '$license_type' for item $code";
                                continue; // Skip this row
                            }
                            
                            // Validate LIQ_FLAG exists in tblsubclass
                            $checkLiqFlagQuery = "SELECT COUNT(*) as count FROM tblsubclass WHERE LIQ_FLAG = '$liqFlag'";
                            $liqFlagResult = $conn->query($checkLiqFlagQuery);
                            $liqFlagExists = $liqFlagResult->fetch_assoc()['count'] > 0;
                            
                            if (!$liqFlagExists) {
                                $errors++;
                                $errorDetails[] = "LIQ_FLAG '$liqFlag' does not exist in tblsubclass for item $code";
                                continue; // Skip this row
                            }
                            
                            // Get valid ITEM_GROUP based on Subclass description and LIQ_FLAG
                            $itemGroupField = getValidItemGroup($subclass, $liqFlag, $conn);
                            
                            // For SUB_CLASS, use the first character of subclass or a default
                            $subClassField = !empty($subclass) ? substr($subclass, 0, 1) : 'O';
                            
                            // Check if item exists using prepared statement
                            $checkQuery->bind_param("ss", $code, $liqFlag);
                            $checkQuery->execute();
                            $checkResult = $checkQuery->get_result();
                            
                            if ($checkResult->num_rows > 0) {
                                // Update existing item using prepared statement
                                $updateQuery->bind_param("ssssssddddss", $printName, $itemName, $subclass, $class, $subClassField, $itemGroupField, $pprice, $bprice, $mprice, $rprice, $code, $liqFlag);
                                
                                if ($updateQuery->execute()) {
                                    $updated++;
                                    
                                    // Update stock information ONLY for current company
                                    updateItemStock($conn, $company_id, $code, $liqFlag, $openingBalance);
                                    
                                    // Initialize daily stock record with all zeros except current day
                                    initializeDailyStockRecord($conn, $company_id, $code, $liqFlag, $openingBalance);
                                    
                                    // ADD ITEM TO ARCHIVE TABLES
                                    addItemToArchiveTables($conn, $company_id, $fin_year, $code, $liqFlag, $openingBalance);
                                    
                                } else {
                                    $errors++;
                                    $errorDetails[] = "Error updating $code: " . $conn->error;
                                }
                            } else {
                                // Insert new item using prepared statement
                                $insertQuery->bind_param("ssssssddddss", $code, $printName, $itemName, $subclass, $class, $subClassField, $itemGroupField, $pprice, $bprice, $mprice, $rprice, $liqFlag);
                                
                                if ($insertQuery->execute()) {
                                    $imported++;
                                    
                                    // Add stock information ONLY for current company
                                    updateItemStock($conn, $company_id, $code, $liqFlag, $openingBalance);
                                    
                                    // Initialize daily stock record with all zeros except current day
                                    initializeDailyStockRecord($conn, $company_id, $code, $liqFlag, $openingBalance);
                                    
                                    // ADD ITEM TO ARCHIVE TABLES
                                    addItemToArchiveTables($conn, $company_id, $fin_year, $code, $liqFlag, $openingBalance);
                                    
                                } else {
                                    $errors++;
                                    $errorDetails[] = "Error inserting $code: " . $conn->error;
                                }
                            }
                        } else {
                            $errors++;
                            $errorDetails[] = "Row with insufficient data: " . implode(',', $data);
                        }
                        
                        // Free memory periodically for large imports
                        if ($rowCount % 100 === 0) {
                            if (function_exists('gc_collect_cycles')) {
                                gc_collect_cycles();
                            }
                        }
                    }
                    
                    // Close prepared statements
                    $checkQuery->close();
                    $updateQuery->close();
                    $insertQuery->close();
                    
                    fclose($handle);
                    
                    $importMessage = "Import completed: $imported new items added, $updated items updated, $errors errors. Processed $rowCount rows total.";
                    if (!empty($errorDetails)) {
                        $importMessage .= " Error details: " . implode('; ', array_slice($errorDetails, 0, 5));
                        if (count($errorDetails) > 5) {
                            $importMessage .= " and " . (count($errorDetails) - 5) . " more errors.";
                        }
                    }
                }
            } else {
                $importMessage = "Error: Only CSV files are supported for import.";
            }
        } catch (Exception $e) {
            $importMessage = "Error during import: " . $e->getMessage();
        }
    } else {
        $importMessage = "Error uploading file. Please try again.";
    }
}

// Fetch items from tblitemmaster for display - FILTERED BY LICENSE TYPE
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, MPRICE, RPRICE
              FROM tblitemmaster
              WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
    
    $params = array_merge([$mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
    // If no classes allowed, show empty result
    $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, MPRICE, RPRICE
              FROM tblitemmaster
              WHERE 1 = 0"; // Always false condition
    $params = [];
    $types = "";
}

if ($search !== '') {
    $query .= " AND (DETAILS LIKE ? OR CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " ORDER BY DETAILS ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Function to get class description
function getClassDescription($code, $classDescriptions) {
    return $classDescriptions[$code] ?? $code;
}

// Function to get subclass description
function getSubclassDescription($itemGroup, $liqFlag, $subclassDescriptions) {
    return $subclassDescriptions[$itemGroup][$liqFlag] ?? $itemGroup;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Excise Item Master - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .import-export-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
    }
    .import-template {
        font-size: 0.9rem;
        color: #6c757d;
        margin-top: 10px;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }
    .import-template ul {
        margin-bottom: 0;
        padding-left: 20px;
    }
    .download-template {
        margin-top: 10px;
    }
  </style>
<script>
function downloadTemplate() {
    // Create a CSV template with headers and example rows
    const headers = ['Code', 'ItemName', 'PrintName', 'Subclass', 'PPrice', 'BPrice', 'MPrice', 'RPrice', 'LIQFLAG', 'OpeningBalance'];
    
    // Example rows with proper formatting
    const exampleRows = [
        ['ITEM001', 'Sample Whisky Item', 'Sample Print', '180ML', '100.000', '90.000', '120.000', '110.000', '<?= $mode ?>', '100'],
        ['ITEM002', '8 PM Special Whisky', '8 PM', '180ML', '120.000', '100.000', '150.000', '130.000', 'F', '50'],
        ['ITEM003', 'ABSOLUT INDIA VODKA', 'Absolut', '750ML', '800.000', '700.000', '1200.000', '1000.000', 'F', '25'],
        ['ITEM004', 'Kingfisher Strong Beer', 'Kingfisher', '650ML', '80.000', '70.000', '120.000', '100.000', 'F', '200']
    ];
    
    // Create CSV content
    let csvContent = headers.join(',') + '\r\n';
    exampleRows.forEach(row => {
        csvContent += row.join(',') + '\r\n';
    });
    
    // Create blob and download
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'item_import_template.csv');
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Clean up
    setTimeout(() => {
        URL.revokeObjectURL(url);
    }, 100);
}
</script>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Excise Item Master</h3>

      <!-- License Restriction Info -->
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

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>
      
      <!-- Import/Export Buttons -->
      <div class="import-export-buttons">
        <div class="btn-group">
          <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-import"></i> Import
          </button>
          <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&export=csv" class="btn btn-info">
            <i class="fas fa-file-export"></i> Export CSV
          </a>
        </div>
      </div>

      <!-- Import Template Info -->
      <div class="import-template">
        <p><strong>Import file requirements:</strong></p>
        <ul>
          <li>File must contain these columns in order: <strong>Code, ItemName, PrintName, Subclass, PPrice, BPrice, MPrice, RPrice, LIQFLAG, OpeningBalance</strong></li>
          <li>Class will be automatically detected from ItemName (e.g., "Whisky" → W, "Vodka" → K)</li>
          <li>Only CSV files are supported for import</li>
          <li>OpeningBalance should be a whole number (integer)</li>
        </ul>
        <p><strong>Naming suggestions for automatic class detection:</strong></p>
        <ul>
          <li>Whisky: Include "Whisky", "Whiskey", "Blend", "Scotch" or brand names like "8PM", "McDowell's"</li>
          <li>Vodka: Include "Vodka" or brand names like "Absolut", "Smirnoff"</li>
          <li>Beer: Include "Beer" or brand names like "Kingfisher", "Tuborg"</li>
          <li>Wine: Include "Wine", "Port", "Sherry"</li>
          <li>Brandy: Include "Brandy" or brand names like "Hennessy"</li>
          <li>Rum: Include "Rum" or brand names like "Bacardi"</li>
          <li>Gin: Include "Gin" or brand names like "Bombay"</li>
        </ul>
        <div class="download-template">
          <a href="javascript:void(0);" onclick="downloadTemplate()" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-download"></i> Download Template
          </a>
        </div>
      </div>

      <!-- Search -->
      <form method="GET" class="search-control mb-3">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <div class="input-group">
          <input type="text" name="search" class="form-control"
                 placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Find
          </button>
          <?php if ($search !== ''): ?>
            <a href="?mode=<?= $mode ?>" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      
      <!-- Add Item Button -->
      <div class="action-btn mb-3 d-flex gap-2">
        <a href="add_item.php" class="btn btn-primary">
          <i class="fas fa-plus"></i> New
        </a>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Import Result Message -->
      <?php if (!empty($importMessage)): ?>
      <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= $importMessage ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <!-- Items Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th>Code</th>
              <th>Item Name</th>
              <th>Print Name</th>
              <th>Class</th>
              <th>Sub Class</th>
              <th>Purchase Price</th>
              <th>Base Price</th>
              <th>MRP Price</th>
              <th>Retail Price</th>
              <th>Opening Stock</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
              <?php
              // Get opening balance from tblitem_stock
              $opening_balance = 0;
              $stock_query = "SELECT OPENING_STOCK{$company_id} as opening 
                             FROM tblitem_stock 
                             WHERE ITEM_CODE = ?";
              $stock_stmt = $conn->prepare($stock_query);
              $stock_stmt->bind_param("s", $item['CODE']);
              $stock_stmt->execute();
              $stock_result = $stock_stmt->get_result();

              if ($stock_result->num_rows > 0) {
                  $stock_row = $stock_result->fetch_assoc();
                  $opening_balance = $stock_row['opening'];
              }
              $stock_stmt->close();
              ?>
              <tr>
                <td><?= htmlspecialchars($item['CODE']); ?></td>
                <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                <td><?= htmlspecialchars($item['Print_Name']); ?></td>
                <td><?= htmlspecialchars(getClassDescription($item['CLASS'], $classDescriptions)); ?></td>
                <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                <td><?= number_format($item['PPRICE'], 3); ?></td>
                <td><?= number_format($item['BPRICE'], 3); ?></td>
                <td><?= number_format($item['MPRICE'], 3); ?></td>
                <td><?= number_format($item['RPRICE'], 3); ?></td>
                <td><?= $opening_balance; ?></td>
                <td>
                  <a href="edit_item.php?code=<?= urlencode($item['CODE']) ?>&mode=<?= $mode ?>"
                     class="btn btn-sm btn-primary" title="Edit">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="11" class="text-center text-muted">No items found.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="importModalLabel">Import Items</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="mb-3">
            <label for="importFile" class="form-label">Select CSV file to import</label>
            <input class="form-control" type="file" id="importFile" name="import_file" required accept=".csv">
            <div class="form-text">Only CSV files are supported</div>
          </div>
          <input type="hidden" name="import_type" value="csv">
          <div class="alert alert-info">
            <strong>Note:</strong> 
            <ul class="mb-0">
              <li>LIQFLAG must be one of: F, C, O</li>
              <li>Subclass must match exactly with subclass master descriptions</li>
              <li>Existing items with matching Code and LIQFLAG will be updated</li>
              <li>ITEM_GROUP will be determined by matching Subclass with database descriptions</li>
              <li>Class will be automatically detected from ItemName</li>
              <li>Only classes allowed for your license type (<?= htmlspecialchars($license_type) ?>) will be imported</li>
              <li>OpeningBalance should be a whole number (integer)</li>
              <li>Daily stock records will be automatically created for all days in current month</li>
              <li>Archive tables will be created and items will be added to them automatically</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Import</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Show loading indicator during import
document.addEventListener('DOMContentLoaded', function() {
    const importForm = document.querySelector('form[enctype="multipart/form-data"]');
    if (importForm) {
        importForm.addEventListener('submit', function() {
            // Show loading overlay
            const loadingOverlay = document.createElement('div');
            loadingOverlay.style.position = 'fixed';
            loadingOverlay.style.top = '0';
            loadingOverlay.style.left = '0';
            loadingOverlay.style.width = '100%';
            loadingOverlay.style.height = '100%';
            loadingOverlay.style.backgroundColor = 'rgba(255,255,255,0.8)';
            loadingOverlay.style.zIndex = '9999';
            loadingOverlay.style.display = 'flex';
            loadingOverlay.style.justifyContent = 'center';
            loadingOverlay.style.alignItems = 'center';
            loadingOverlay.innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Importing data, please wait... This may take several minutes for large files.</p>
                </div>
            `;
            document.body.appendChild(loadingOverlay);
        });
    }
});
</script>

</body>
</html>