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
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

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

// Function to create a fresh archive table with only base columns (NO day columns)
function createFreshArchiveTable($conn, $comp_id, $month) {
    $table_name = getArchiveTableName($comp_id, $month);
    
    // Create table with ONLY base columns, NO day columns
    $create_table_query = "CREATE TABLE $table_name (
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

// Function to get the correct table for a specific month
function getTableForMonth($conn, $comp_id, $month) {
    $current_month = date('Y-m');
    
    if ($month == $current_month) {
        return "tbldailystock_$comp_id";
    } else {
        $archive_table = getArchiveTableName($comp_id, $month);
        
        // Check if archive table exists
        $check_query = "SHOW TABLES LIKE '$archive_table'";
        $check_result = $conn->query($check_query);
        $table_exists = $check_result->num_rows > 0;
        
        if (!$table_exists) {
            // Create the archive table if it doesn't exist
            addDayColumnsForMonth($conn, $comp_id, $month, true);
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
function updateDailyStockRange($conn, $comp_id, $items_data, $mode, $start_date) {
    $start = new DateTime($start_date);
    $end = new DateTime();
    
    // Generate all dates between start and end
    $dates = [];
    $period = new DatePeriod($start, new DateInterval('P1D'), $end);
    
    foreach ($period as $date) {
        $dates[] = $date->format('Y-m-d');
    }
    
    if (empty($dates)) {
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
    
    // Process each month
    foreach ($monthly_data as $month => $days) {
        $table_name = getTableForMonth($conn, $comp_id, $month);
        
        // Ensure columns exist for this month
        addDayColumnsForMonth($conn, $comp_id, $month);
        
        // Get days in this month
        $year_month = explode('-', $month);
        $year = $year_month[0];
        $month_num = $year_month[1];
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
        $all_days_in_month = [];
        for ($d = 1; $d <= $days_in_month; $d++) {
            $all_days_in_month[] = str_pad($d, 2, '0', STR_PAD_LEFT);
        }
        
        // Process each item for this month
        foreach ($items_data as $item_code => $opening_balance) {
            // Check if record exists for this month
            $check_query = "SELECT 1 FROM $table_name WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ? LIMIT 1";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("sss", $month, $item_code, $mode);
            $check_stmt->execute();
            $check_stmt->store_result();
            $exists = $check_stmt->num_rows > 0;
            $check_stmt->close();
            
            if ($exists) {
                // Build update query for specific days in range
                $update_parts = [];
                $params = [];
                $types = '';
                
                // Update only the days that are in our date range
                foreach ($days as $day_padded) {
                    $update_parts[] = "DAY_{$day_padded}_OPEN = ?";
                    $update_parts[] = "DAY_{$day_padded}_CLOSING = ?";
                    $params[] = $opening_balance;
                    $params[] = $opening_balance;
                    $types .= 'ii';
                }
                
                // Also set days before start_date to 0 if they're not already set
                // But only if this is the start month (first month in range)
                $first_month = array_key_first($monthly_data);
                if ($month === $first_month) {
                    $start_day = intval($days[0]);
                    for ($d = 1; $d < $start_day; $d++) {
                        $day_padded = str_pad($d, 2, '0', STR_PAD_LEFT);
                        if (!in_array($day_padded, $days)) {
                            $update_parts[] = "DAY_{$day_padded}_OPEN = 0";
                            $update_parts[] = "DAY_{$day_padded}_CLOSING = 0";
                        }
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
                    $update_stmt->execute();
                    $update_stmt->close();
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
                    
                    // For days in our range, set opening and closing to opening_balance
                    if (in_array($day_padded, $days)) {
                        $params[] = $opening_balance;
                        $params[] = 0;
                        $params[] = 0;
                        $params[] = $opening_balance;
                    } else {
                        // For days outside range, set to 0
                        $params[] = 0;
                        $params[] = 0;
                        $params[] = 0;
                        $params[] = 0;
                    }
                    $types .= 'iiii';
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
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
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
                      AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))";
            $params = array_merge([$mode], $allowed_classes, $allowed_classes);
            $types = "s" . str_repeat('s', count($allowed_classes) * 2);
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
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
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
                      AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))";
            $params = array_merge([$mode], $allowed_classes, $allowed_classes);
            $types = "s" . str_repeat('s', count($allowed_classes) * 2);
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
    $query_params = [$mode];
    $query_types = "s";
    
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
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
                  AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))";
        $query_params = array_merge([$mode], $allowed_classes, $allowed_classes);
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
    
    $count_query = "SELECT COUNT(*) as total 
                    FROM tblitemmaster im
                    LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                    WHERE im.LIQ_FLAG = ? 
                    AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))
                    $stock_condition";
    
    $params = array_merge([$mode], $allowed_classes, $allowed_classes);
    $types = "s" . str_repeat('s', count($allowed_classes) * 2);
    
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
              AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))
              $stock_condition";
    
    $params = array_merge([$mode], $allowed_classes, $allowed_classes);
    $types = "s" . str_repeat('s', count($allowed_classes) * 2);
    
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
    }

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
        fclose($handle);
        
        // ==================== PERFORMANCE OPTIMIZATION #5: Bulk Daily Stock Update ====================
        // Only update daily stock for items with balance > 0
        if (!empty($items_for_daily_stock)) {
            updateDailyStockRange($conn, $comp_id, $items_for_daily_stock, $mode, $start_date);
        }
        
        // Commit transaction
        $conn->commit();

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
            'delimiter' => $delimiter
        ];

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
                if (!empty($items_for_daily_stock)) {
                    updateDailyStockRange($conn, $comp_id, $items_for_daily_stock, $mode, $start_date);
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

if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // Lightweight count query - CHECK BOTH CLASS AND CLASS_CODE_NEW
    $count_query = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN COALESCE(st.CURRENT_STOCK$comp_id, 0) > 0 THEN 1 ELSE 0 END) as with_stock
                    FROM tblitemmaster im
                    LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                    WHERE im.LIQ_FLAG = ? 
                    AND (im.CLASS_CODE_NEW IN ($class_placeholders) OR im.CLASS IN ($class_placeholders))";
    
    $params = array_merge([$mode], $allowed_classes, $allowed_classes);
    $types = "s" . str_repeat('s', count($allowed_classes) * 2);
    
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
          <div class="alert alert-<?= $import_message['success'] ? 'success' : 'danger' ?> mt-3">
            <strong><?= $import_message['success'] ? 'Success!' : 'Error!' ?></strong> <?= $import_message['message'] ?>
            <?php if (isset($import_message['imported_count']) && isset($import_message['skipped_count'])): ?>
              <div class="mt-2">
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