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
$fin_year = $_SESSION['FIN_YEAR_ID'];

include_once "../config/db.php"; // MySQLi connection in $conn
require_once 'license_functions.php'; // ADDED: Include license functions

// Get company's license type and available classes - ADDED LICENSE FILTERING
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering - ADDED
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

// Get current company details
$company_query = "SELECT CompID, Comp_Name FROM tblcompany WHERE CompID = ?";
$company_stmt = $conn->prepare($company_query);
$company_stmt->bind_param("i", $comp_id);
$company_stmt->execute();
$company_result = $company_stmt->get_result();
$current_company = $company_result->fetch_assoc();
$company_stmt->close();

// ==================== PERFORMANCE OPTIMIZATION #1: Bulk Column Creation ====================
// Check and create all needed columns in ONE query
$check_columns_query = "SELECT column_name FROM information_schema.columns 
                       WHERE table_name = 'tblitem_stock' 
                       AND table_schema = DATABASE()
                       AND column_name IN ('OPENING_STOCK$comp_id', 'CURRENT_STOCK$comp_id')";
$check_result = $conn->query($check_columns_query);
$existing_columns = [];
while ($row = $check_result->fetch_assoc()) {
    $existing_columns[] = $row['column_name'];
}

// Create missing columns in bulk
$alter_queries = [];
if (!in_array("OPENING_STOCK$comp_id", $existing_columns)) {
    $alter_queries[] = "ADD COLUMN OPENING_STOCK$comp_id INT DEFAULT 0";
}
if (!in_array("CURRENT_STOCK$comp_id", $existing_columns)) {
    $alter_queries[] = "ADD COLUMN CURRENT_STOCK$comp_id INT DEFAULT 0";
}

if (!empty($alter_queries)) {
    $alter_query = "ALTER TABLE tblitem_stock " . implode(", ", $alter_queries);
    if (!$conn->query($alter_query)) {
        error_log("Failed to create columns: " . $conn->error);
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
                // Build update query for all days at once
                $update_parts = [];
                $params = [];
                $types = '';
                
                foreach ($days as $day_padded) {
                    $update_parts[] = "DAY_{$day_padded}_OPEN = ?";
                    $update_parts[] = "DAY_{$day_padded}_PURCHASE = 0";
                    $update_parts[] = "DAY_{$day_padded}_SALES = 0";
                    $update_parts[] = "DAY_{$day_padded}_CLOSING = ?";
                    $params[] = $opening_balance;
                    $params[] = $opening_balance;
                    $types .= 'ii';
                }
                
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
            } else {
                // Insert new record with all days at once
                $columns = ['STK_MONTH', 'ITEM_CODE', 'LIQ_FLAG'];
                $placeholders = ['?', '?', '?'];
                $params = [$month, $item_code, $mode];
                $types = 'sss';
                
                foreach ($days as $day_padded) {
                    $columns[] = "DAY_{$day_padded}_OPEN";
                    $columns[] = "DAY_{$day_padded}_PURCHASE";
                    $columns[] = "DAY_{$day_padded}_SALES";
                    $columns[] = "DAY_{$day_padded}_CLOSING";
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $params[] = $opening_balance;
                    $params[] = 0;
                    $params[] = 0;
                    $params[] = $opening_balance;
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

// ==================== HELPER FUNCTIONS FOR NEW 4-LAYER STRUCTURE ====================
// Function to get product type from class code (UPDATED FOR NEW STRUCTURE)
function getProductTypeFromClass($classCode, $conn) {
    // First check if it's a new class code
    if (strpos($classCode, 'CLS') === 0) {
        // It's a new class code, map to product type
        $query = "SELECT cat.CATEGORY_NAME 
                  FROM tblclass_new cn 
                  JOIN tblcategory cat ON cn.CATEGORY_CODE = cat.CATEGORY_CODE 
                  WHERE cn.CLASS_CODE = ? LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $classCode);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $categoryName = strtoupper($row['CATEGORY_NAME']);
            $stmt->close();
            // Map category names to standard product types
            $categoryMap = [
                'SPIRIT' => 'SPIRITS',
                'WINE' => 'WINE',
                'FERMENTED BEER' => 'FERMENTED BEER',
                'MILD BEER' => 'MILD BEER',
                'COUNTRY LIQUOR' => 'COUNTRY LIQUOR',
                'COLD DRINKS' => 'OTHER',
                'SODA' => 'OTHER',
                'GENERAL' => 'OTHER'
            ];
            return $categoryMap[$categoryName] ?? 'OTHER';
        }
        $stmt->close();
    }
    
    // Fallback to old class codes
    $spirits = ['W', 'G', 'D', 'K', 'R', 'O'];
    if (in_array($classCode, $spirits)) return 'SPIRITS';
    if ($classCode === 'V') return 'WINE';
    if ($classCode === 'F') return 'FERMENTED BEER';
    if ($classCode === 'M') return 'MILD BEER';
    if ($classCode === 'L') return 'COUNTRY LIQUOR';
    return 'OTHER';
}

// Helper function to get size description from size code
function getSizeDescriptionFromCode($size_code, $conn) {
    if (empty($size_code)) return 'N/A';
    
    try {
        $query = "SELECT SIZE_DESC FROM tblsize WHERE SIZE_CODE = ? LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $size_code);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row['SIZE_DESC'];
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error getting size description: " . $e->getMessage());
    }
    
    return 'N/A';
}

// Helper function to get size code from size description
function getSizeCodeFromDescription($size_desc, $conn) {
    if (empty($size_desc)) return null;
    
    try {
        $query = "SELECT SIZE_CODE FROM tblsize WHERE SIZE_DESC = ? LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $size_desc);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stmt->close();
            return $row['SIZE_CODE'];
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log("Error getting size code: " . $e->getMessage());
    }
    
    return null;
}

// Helper function to extract volume from item details (ENHANCED)
function extractVolumeFromDetails($details, $details2, $item_code = null, $conn = null) {
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
    
    // New: Try to get volume from the size table if connection is available
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
                    return $row['ML_VOLUME'];
                }
            }
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error getting volume from size table: " . $e->getMessage());
        }
    }
    
    return 0; // Unknown volume
}

// Helper function to get volume label
function getVolumeLabel($volume) {
    $volumeMap = [
        // ML sizes
        50 => '50 ML',
        60 => '60 ML', 
        90 => '90 ML',
        170 => '170 ML',
        180 => '180 ML',
        200 => '200 ML',
        250 => '250 ML',
        275 => '275 ML',
        330 => '330 ML',
        355 => '355 ML',
        375 => '375 ML',
        500 => '500 ML',
        650 => '650 ML',
        700 => '700 ML',
        750 => '750 ML',
        1000 => '1000 ML',
        
        // Liter sizes (converted to ML for consistency)
        1500 => '1.5L',    // 1.5L = 1500ML
        1750 => '1.75L',   // 1.75L = 1750ML
        2000 => '2L',      // 2L = 2000ML
        3000 => '3L',      // 3L = 3000ML
        4500 => '4.5L',    // 4.5L = 4500ML
        15000 => '15L',    // 15L = 15000ML
        20000 => '20L',    // 20L = 20000ML
        30000 => '30L',    // 30L = 30000ML
        50000 => '50L'     // 50L = 50000ML
    ];
    
    return $volumeMap[$volume] ?? $volume . ' ML';
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
        // Build query based on license filtering - UPDATED FOR NEW STRUCTURE
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
            $query = "SELECT 
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
                        im.SIZE_CODE,
                        COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK,
                        COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                      FROM tblitemmaster im
                      LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                      WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders)";
            $params = array_merge([$mode], $allowed_classes);
            $types = "s" . str_repeat('s', count($allowed_classes));
        } else {
            $query = "SELECT 
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
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
            
            // Get product type from class code
            $productType = getProductTypeFromClass($item['CLASS'], $conn);
            
            // Initialize category arrays if not exists
            if (!isset($category_totals[$productType])) {
                $category_totals[$productType] = 0;
                $category_counts[$productType] = 0;
            }
            
            // Update statistics
            $total_stock += $current_stock;
            $category_totals[$productType] += $current_stock;
            $category_counts[$productType]++;
            
            if ($current_stock > 0) {
                $items_with_stock++;
            }
            
            if ($current_stock > $max_stock) {
                $max_stock = $current_stock;
            }
            
            if ($current_stock < $min_stock) {
                $min_stock = $current_stock;
            }
            
            // Extract volume from item details for volume breakdown
            $volume = extractVolumeFromDetails($item['DETAILS'], $item['DETAILS2'], $item['CODE'], $conn);
            if ($volume > 0) {
                if (!isset($volume_totals[$volume])) {
                    $volume_totals[$volume] = 0;
                }
                $volume_totals[$volume] += $current_stock;
            }
        }
        
        // Prepare summary array
        $summary['total_items'] = count($items);
        $summary['total_stock'] = $total_stock;
        $summary['items_with_stock'] = $items_with_stock;
        $summary['items_without_stock'] = count($items) - $items_with_stock;
        $summary['average_stock'] = count($items) > 0 ? round($total_stock / count($items), 2) : 0;
        $summary['max_stock'] = $max_stock === PHP_INT_MAX ? 0 : $max_stock;
        $summary['min_stock'] = $min_stock === PHP_INT_MAX ? 0 : $min_stock;
        
        // Prepare category breakdown
        foreach ($category_totals as $category => $total) {
            $summary['category_breakdown'][] = [
                'category' => $category,
                'item_count' => $category_counts[$category],
                'total_stock' => $total,
                'average_stock' => round($total / $category_counts[$category], 2)
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
        // Build query to get all items with their stock - UPDATED
        if (!empty($allowed_classes)) {
            $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
            $query = "SELECT 
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
                        im.SIZE_CODE,
                        COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                      FROM tblitemmaster im
                      LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                      WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders)";
            $params = array_merge([$mode], $allowed_classes);
            $types = "s" . str_repeat('s', count($allowed_classes));
        } else {
            $query = "SELECT 
                        im.CODE,
                        im.DETAILS,
                        im.DETAILS2,
                        im.CLASS,
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
                $classCode = $item['CLASS'] ?? 'O';
                $productType = getProductTypeFromClass($classCode, $conn);
                
                // Extract volume from item details
                $volume = extractVolumeFromDetails($item['DETAILS'], $item['DETAILS2'], $item['CODE'], $conn);
                $volumeColumn = getVolumeLabel($volume);
                
                // Add to summary
                if (isset($volumeSummary[$productType][$volumeColumn])) {
                    $volumeSummary[$productType][$volumeColumn] += $current_stock;
                } elseif ($productType !== 'OTHER') {
                    // For unknown sizes in known categories, add to 50ML as fallback
                    $volumeSummary[$productType]['50 ML'] += $current_stock;
                }
            }
        }
        
        $stmt->close();
        
    } catch (Exception $e) {
        error_log("Error fetching volume summary: " . $e->getMessage());
    }
    
    return $volumeSummary;
}

// Get summary data
$summary_data = getOpeningBalanceSummary($conn, $comp_id, $mode, $allowed_classes);
$volume_summary_data = getOpeningBalanceVolumeSummary($conn, $comp_id, $mode, $allowed_classes);

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    
    // Build query with license filtering - UPDATED FOR EXPORT
    $query_params = [$mode];
    $query_types = "s";
    
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT 
                    im.CODE, 
                    im.DETAILS, 
                    im.DETAILS2,
                    im.CLASS,
                    im.SIZE_CODE,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders)";
        $query_params = array_merge($query_params, $allowed_classes);
        $query_types .= str_repeat('s', count($allowed_classes));
    } else {
        $query = "SELECT 
                    im.CODE, 
                    im.DETAILS, 
                    im.DETAILS2,
                    im.CLASS,
                    im.SIZE_CODE,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
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
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=opening_balance_export_' . $mode . '_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        
        // Use comma as delimiter for consistent export
        $delimiter = ',';
        
        // UPDATED HEADERS - Only 4 columns: Item_Code, Item_Name, Size, Current_Stock
        fputcsv($output, ['Item_Code', 'Item_Name', 'Size', 'Current_Stock'], $delimiter);
        
        foreach ($items as $item) {
            // Get size description from SIZE_CODE
            $size_desc = getSizeDescriptionFromCode($item['SIZE_CODE'], $conn);
            
            fputcsv($output, [
                $item['CODE'],
                $item['DETAILS'],
                $size_desc,
                $item['CURRENT_STOCK']
            ], $delimiter);
        }
        
        fclose($output);
        exit;
    }
}

// ==================== PERFORMANCE OPTIMIZATION #4: Bulk CSV Import WITH DELIMITER DETECTION ====================
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
    $items_to_update = []; // Store items for bulk update
    $items_for_daily_stock = []; // Store items for daily stock update
    $skipped_items = []; // Store skipped items for reporting

    // Get all valid items in one query for validation (optimization)
    $valid_items = [];
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        // UPDATED QUERY TO INCLUDE SIZE_CODE and join with tblsize
        $valid_items_query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.LIQ_FLAG, im.CLASS, im.SIZE_CODE, sz.SIZE_DESC
                             FROM tblitemmaster im
                             LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                             WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders)";
        $valid_stmt = $conn->prepare($valid_items_query);
        $valid_params = array_merge([$mode], $allowed_classes);
        $valid_types = "s" . str_repeat('s', count($allowed_classes));
        $valid_stmt->bind_param($valid_types, ...$valid_params);
        $valid_stmt->execute();
        $valid_result = $valid_stmt->get_result();
        
        // Create a lookup array for faster validation
        while ($row = $valid_result->fetch_assoc()) {
            // Create multiple lookup keys for flexibility
            $key1 = $row['CODE']; // Just by code
            $key2 = $row['CODE'] . '|' . $row['DETAILS'] . '|' . $row['SIZE_DESC']; // Code + Name + Size Description
            $valid_items[$key1] = [
                'code' => $row['CODE'],
                'size_code' => $row['SIZE_CODE'],
                'size_desc' => $row['SIZE_DESC'],
                'class' => $row['CLASS']
            ];
            $valid_items[$key2] = [
                'code' => $row['CODE'],
                'size_code' => $row['SIZE_CODE'],
                'size_desc' => $row['SIZE_DESC'],
                'class' => $row['CLASS']
            ];
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
                $code = strtoupper($code);
                $name = trim($name);
                $size_desc = trim($size_desc);
                
                // Try multiple matching strategies
                $item_found = false;
                $item_data = null;
                
                // Strategy 1: Try exact match with code + name + size description
                $full_key = $code . '|' . $name . '|' . $size_desc;
                if (isset($valid_items[$full_key])) {
                    $item_found = true;
                    $item_data = $valid_items[$full_key];
                }
                // Strategy 2: Try matching just by code
                elseif (isset($valid_items[$code])) {
                    $item_found = true;
                    $item_data = $valid_items[$code];
                    
                    // Check if size matches
                    if ($item_data['size_desc'] !== $size_desc) {
                        // Size mismatch, but we'll still process with warning
                        $error_messages[] = "Size mismatch for item '$code': CSV has '$size_desc', database has '{$item_data['size_desc']}'. Using database size.";
                    }
                }
                // Strategy 3: Try fuzzy matching by name and size
                else {
                    foreach ($valid_items as $key => $valid_item) {
                        if (strpos($key, $code) !== false || 
                            (strpos($key, $name) !== false && strpos($key, $size_desc) !== false)) {
                            $item_found = true;
                            $item_data = $valid_item;
                            break;
                        }
                    }
                }
                
                if ($item_found && $item_data) {
                    $item_code_to_use = $item_data['code'];
                    $items_to_update[] = ['code' => $item_code_to_use, 'balance' => $balance];
                    $items_for_daily_stock[$item_code_to_use] = $balance;
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
                                $insert_stmt->bind_param("siii", $item['code'], $fin_year, $item['balance'], $item['balance']);
                                $insert_stmt->execute();
                            }
                        }
                        
                        $items_to_update = [];
                        $current_batch++;
                    }
                } else {
                    $skipped_count++;
                    $skipped_items[] = [
                        'code' => $code,
                        'name' => $name,
                        'size' => $size_desc,
                        'reason' => 'Item not found in database or not allowed for your license type'
                    ];
                    
                    // Store in error messages (limit to first 10 to avoid huge messages)
                    if ($skipped_count <= 10) {
                        $error_messages[] = "Skipped item: '$code' - '$name' - '$size_desc' (not found in database or not allowed for your license type)";
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
                    $insert_stmt->bind_param("siii", $item['code'], $fin_year, $item['balance'], $item['balance']);
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
            $message .= "Note: Some items had size mismatches but were processed using database sizes.";
        }
        $message .= " Detected file format: " . ($delimiter === "\t" ? "Tab-Separated (TSV)" : ($delimiter === ";" ? "Semicolon-Separated" : "Comma-Separated (CSV)"));
        $message .= " Performance: ~" . round($imported_count / max(1, time() - $_SERVER['REQUEST_TIME']), 0) . " items/second";

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

// Handle template download
if (isset($_GET['download_template'])) {
    // Fetch all items from tblitemmaster for the current liquor mode
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        // UPDATED QUERY TO GET SIZE_DESC instead of SIZE_CODE
        $template_query = "SELECT im.CODE, im.DETAILS, sz.SIZE_DESC 
                          FROM tblitemmaster im
                          LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
                          WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders) 
                          ORDER BY im.DETAILS ASC";
        $template_stmt = $conn->prepare($template_query);
        $template_params = array_merge([$mode], $allowed_classes);
        $template_types = "s" . str_repeat('s', count($allowed_classes));
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

// ==================== PERFORMANCE OPTIMIZATION #6: Optimized Item Fetching ====================
// Fetch items based on view type
$limit = 1000; // Adjust based on your needs
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

$items = [];
$total_items = 0;
$total_with_stock = 0;
$total_without_stock = 0;

// First, get counts for both views
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // Count items with stock > 0
    $count_with_stock_query = "SELECT COUNT(*) as total 
                              FROM tblitemmaster im
                              LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                              WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders) 
                              AND COALESCE(st.CURRENT_STOCK$comp_id, 0) > 0";
    $params = array_merge([$mode], $allowed_classes);
    $types = "s" . str_repeat('s', count($allowed_classes));
    
    if ($search !== '') {
        $count_with_stock_query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= "ss";
    }
    
    $count_with_stock_stmt = $conn->prepare($count_with_stock_query);
    if ($params) {
        $count_with_stock_stmt->bind_param($types, ...$params);
    }
    $count_with_stock_stmt->execute();
    $count_with_stock_result = $count_with_stock_stmt->get_result();
    $total_with_stock_row = $count_with_stock_result->fetch_assoc();
    $total_with_stock = $total_with_stock_row['total'] ?? 0;
    $count_with_stock_stmt->close();
    
    // Count items with stock = 0
    $count_without_stock_query = "SELECT COUNT(*) as total 
                                 FROM tblitemmaster im
                                 LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                                 WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders) 
                                 AND (st.CURRENT_STOCK$comp_id IS NULL OR COALESCE(st.CURRENT_STOCK$comp_id, 0) = 0)";
    $params_without = array_merge([$mode], $allowed_classes);
    $types_without = "s" . str_repeat('s', count($allowed_classes));
    
    if ($search !== '') {
        $count_without_stock_query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
        $params_without[] = "%$search%";
        $params_without[] = "%$search%";
        $types_without .= "ss";
    }
    
    $count_without_stock_stmt = $conn->prepare($count_without_stock_query);
    if ($params_without) {
        $count_without_stock_stmt->bind_param($types_without, ...$params_without);
    }
    $count_without_stock_stmt->execute();
    $count_without_stock_result = $count_without_stock_stmt->get_result();
    $total_without_stock_row = $count_without_stock_result->fetch_assoc();
    $total_without_stock = $total_without_stock_row['total'] ?? 0;
    $count_without_stock_stmt->close();
} else {
    $total_with_stock = 0;
    $total_without_stock = 0;
}

$total_items = $total_with_stock + $total_without_stock;

// Now fetch items based on view type
if ($view_type === 'with_stock') {
    // Get items with stock > 0
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT 
                    im.CODE, 
                    im.Print_Name, 
                    im.DETAILS, 
                    im.DETAILS2, 
                    im.CLASS, 
                    im.SUB_CLASS, 
                    im.ITEM_GROUP,
                    im.SIZE_CODE,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK,
                    COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders) 
                  AND COALESCE(st.CURRENT_STOCK$comp_id, 0) > 0";
        $params = array_merge([$mode], $allowed_classes);
        $types = "s" . str_repeat('s', count($allowed_classes));
    } else {
        $query = "SELECT 
                    im.CODE, 
                    im.Print_Name, 
                    im.DETAILS, 
                    im.DETAILS2, 
                    im.CLASS, 
                    im.SUB_CLASS, 
                    im.ITEM_GROUP,
                    im.SIZE_CODE,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK,
                    COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  WHERE 1 = 0";
        $params = [$mode];
        $types = "s";
    }
} else {
    // Get items with stock = 0
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT 
                    im.CODE, 
                    im.Print_Name, 
                    im.DETAILS, 
                    im.DETAILS2, 
                    im.CLASS, 
                    im.SUB_CLASS, 
                    im.ITEM_GROUP,
                    im.SIZE_CODE,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK,
                    COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders) 
                  AND (st.CURRENT_STOCK$comp_id IS NULL OR COALESCE(st.CURRENT_STOCK$comp_id, 0) = 0)";
        $params = array_merge([$mode], $allowed_classes);
        $types = "s" . str_repeat('s', count($allowed_classes));
    } else {
        $query = "SELECT 
                    im.CODE, 
                    im.Print_Name, 
                    im.DETAILS, 
                    im.DETAILS2, 
                    im.CLASS, 
                    im.SUB_CLASS, 
                    im.ITEM_GROUP,
                    im.SIZE_CODE,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK,
                    COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  WHERE 1 = 0";
        $params = [$mode];
        $types = "s";
    }
}

if ($search !== '') {
    $query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

// Add pagination
$query .= " ORDER BY im.DETAILS ASC LIMIT $limit OFFSET $offset";

// Get items for current view
$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ==================== PERFORMANCE OPTIMIZATION #7: Bulk Form Submission ====================
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
                $items_for_daily_stock[$code] = $balance;
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
                            $insert_stmt->bind_param("siii", $item['code'], $fin_year, $item['balance'], $item['balance']);
                            $insert_stmt->execute();
                        }
                    }
                }
                
                // Close prepared statements
                $check_stmt->close();
                $update_stmt->close();
                $insert_stmt->close();
                
                // Update daily stock in bulk
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
        <strong>Company:</strong> <?php echo htmlspecialchars($current_company['Comp_Name']); ?> | 
        <strong>Mode:</strong> <?php echo $mode === 'F' ? 'Foreign Liquor' : ($mode === 'C' ? 'Country Liquor' : 'Others'); ?>
      </div>

      <!-- Import/Export Buttons -->
      <div class="import-export-buttons">
        <div class="btn-group">
          <button type="button" class="btn btn-info position-relative" data-bs-toggle="modal" data-bs-target="#openingBalanceVolumeModal">
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
            <input type="date" class="form-control" id="start_date_import" name="start_date" value="<?= date('Y-m-d') ?>" required>
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
            <?php if (isset($import_message['imported_count']) && isset($import_message['skipped_count']) && $import_message['skipped_count'] > 0): ?>
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
                <strong>Notes:</strong>
                <ul class="mb-0 mt-2 small">
                  <?php foreach ($import_message['errors'] as $error): ?>
                    <li><?= $error ?></li>
                  <?php endforeach; ?>
                  <?php if (isset($import_message['skipped_count']) && $import_message['skipped_count'] > 10): ?>
                    <li>... and <?= $import_message['skipped_count'] - 10 ?> more items were skipped</li>
                  <?php endif; ?>
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
             class="btn btn-outline-primary <?= $mode === 'F' ? 'active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&view=<?= $view_type ?>&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&view=<?= $view_type ?>&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'active' : '' ?>">
            Others
          </a>
        </div>
      </div>

      <!-- Search -->
      <form method="GET" class="search-control mb-3">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <input type="hidden" name="view" value="<?= htmlspecialchars($view_type); ?>">
        <div class="input-group">
          <input type="text" name="search" class="form-control"
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
             class="btn btn-outline-primary <?= $view_type === 'with_stock' ? 'active' : '' ?>">
            <i class="fas fa-box-open"></i> Items with Stock (<?= $total_with_stock ?>)
          </a>
          <a href="?mode=<?= $mode ?>&view=without_stock&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $view_type === 'without_stock' ? 'active' : '' ?>">
            <i class="fas fa-box"></i> Items without Stock (<?= $total_without_stock ?>)
          </a>
        </div>
      </div>

      <!-- Balance Management Form -->
      <form method="POST" id="balanceForm">
        <input type="hidden" name="page" value="<?= $page ?>">
        <input type="hidden" name="view" value="<?= $view_type ?>">
        <div class="mb-3">
          <label for="start_date_balance" class="form-label">Start Date for Opening Balance</label>
          <input type="date" class="form-control" id="start_date_balance" name="start_date" value="<?= date('Y-m-d') ?>" required style="max-width: 200px;">
        </div>

        <div class="action-btn mb-3 d-flex gap-2">
          <button type="submit" name="update_balances" class="btn btn-success" id="saveBtn">
            <i class="fas fa-save"></i> Save Opening Balances
          </button>
          <div class="ms-auto">
            <span class="text-muted me-3">
              <?php if ($view_type === 'with_stock'): ?>
                Showing <?= count($items) ?> items with stock (Total: <?= $total_with_stock ?>)
              <?php else: ?>
                Showing <?= count($items) ?> items without stock (Total: <?= $total_without_stock ?>)
              <?php endif; ?>
            </span>
            <a href="dashboard.php" class="btn btn-secondary">
              <i class="fas fa-sign-out-alt"></i> Exit
            </a>
          </div>
        </div>

        <!-- Items Table -->
        <div class="table-container">
          <table class="table table-striped table-bordered table-hover">
            <thead class="sticky-header">
              <tr>
                <th>Code</th>
                <th>Item Name</th>
                <th>Size</th>
                <th class="company-column">
                  Current Stock (CURRENT_STOCK<?= $comp_id ?>)
                </th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php foreach ($items as $item): 
                  // Get product type for display
                  $productType = getProductTypeFromClass($item['CLASS'], $conn);
                  // Get size description
                  $size_desc = getSizeDescriptionFromCode($item['SIZE_CODE'], $conn);
              ?>
                <tr>
                  <td><?= htmlspecialchars($item['CODE']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                  <td>
                    <div><?= $size_desc ?></div>
                    <div class="size-info">Category: <?= $productType ?></div>
                  </td>
                  <td class="company-column">
                    <input type="number" name="opening_stock[<?= htmlspecialchars($item['CODE']); ?>]"
                           value="<?= (int)$item['CURRENT_STOCK']; ?>" min="0"
                           class="form-control opening-balance-input">
                    <input type="hidden" name="original_stock[<?= htmlspecialchars($item['CODE']); ?>]"
                           value="<?= (int)$item['CURRENT_STOCK']; ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="4" class="text-center">
                  <?php if ($view_type === 'with_stock'): ?>
                    No items with stock found for the selected liquor mode and license type.
                  <?php else: ?>
                    No items without stock found for the selected liquor mode and license type.
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Save Button at Bottom -->
        <div class="action-btn mt-3 d-flex gap-2">
          <button type="submit" name="update_balances" class="btn btn-success" id="saveBottomBtn">
            <i class="fas fa-save"></i> Save Opening Balances
          </button>
          <div class="ms-auto">
            <span class="text-muted me-3">
              <?php if ($view_type === 'with_stock'): ?>
                Showing <?= count($items) ?> items with stock (Total: <?= $total_with_stock ?>)
              <?php else: ?>
                Showing <?= count($items) ?> items without stock (Total: <?= $total_without_stock ?>)
              <?php endif; ?>
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
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="openingBalanceSummaryTable">
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
                            <?php 
                            // Display categories in the requested order
                            $categories = ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER', 'COUNTRY LIQUOR'];
                            $allSizes = [
                                '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML', 
                                '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
                                '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
                            ];
                            
                            foreach ($categories as $category): ?>
                                <tr>
                                    <td><strong><?= $category ?></strong></td>
                                    <?php foreach ($allSizes as $size): 
                                        $value = isset($volume_summary_data[$category][$size]) ? $volume_summary_data[$category][$size] : 0;
                                    ?>
                                        <td class="<?= $value > 0 ? 'table-success' : '' ?>">
                                            <?= $value > 0 ? number_format($value) : '' ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printOpeningBalanceVolumeSummary()">
                    <i class="fas fa-print me-1"></i> Print Volume Summary
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
  // Auto-hide alerts after 5 seconds
  setTimeout(() => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
      const bsAlert = new bootstrap.Alert(alert);
      bsAlert.close();
    });
  }, 5000);

  // Confirm before leaving if form has changes
  let formChanged = false;
  const form = document.getElementById('balanceForm');
  const inputs = form.querySelectorAll('input[type="number"]');

  inputs.forEach(input => {
    const originalValue = input.value;
    input.addEventListener('change', () => {
      formChanged = (input.value !== originalValue);
    });
  });

  window.addEventListener('beforeunload', (e) => {
    if (formChanged) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  form.addEventListener('submit', () => {
    formChanged = false;
  });

  // Print volume summary function
  function printOpeningBalanceVolumeSummary() {
    const modalContent = document.querySelector('#openingBalanceVolumeModal .modal-content').innerHTML;
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Opening Balance Volume Summary - <?= htmlspecialchars($current_company['Comp_Name']) ?></title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>
                body { padding: 20px; }
                .print-header { 
                    text-align: center; 
                    margin-bottom: 30px;
                    border-bottom: 2px solid #333;
                    padding-bottom: 20px;
                }
                .print-footer { 
                    margin-top: 30px; 
                    text-align: center;
                    color: #666;
                    font-size: 0.9rem;
                }
                @media print {
                    .no-print { display: none; }
                    .table { font-size: 10px; }
                    th, td { padding: 3px !important; }
                }
            </style>
        </head>
        <body>
            <div class="print-header">
                <h2>Opening Balance Volume Summary</h2>
                <h4><?= htmlspecialchars($current_company['Comp_Name']) ?></h4>
                <p>Mode: <?= $mode === 'F' ? 'Foreign Liquor' : ($mode === 'C' ? 'Country Liquor' : 'Others') ?></p>
                <p>Generated on: <?= date('Y-m-d H:i:s') ?></p>
                <p>Column: CURRENT_STOCK<?= $comp_id ?> from tblitem_stock</p>
            </div>
            
            ${modalContent}
            
            <div class="print-footer no-print">
                <p>This report was generated from liqoursoft system</p>
            </div>
            
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

  // Show progress for bulk operations
  document.addEventListener('DOMContentLoaded', function() {
    const importForm = document.getElementById('importForm');
    const saveBtn = document.getElementById('saveBtn');
    const saveBottomBtn = document.getElementById('saveBottomBtn');
    
    function showProgress(message) {
      const loadingOverlay = document.createElement('div');
      loadingOverlay.id = 'loadingOverlay';
      loadingOverlay.style.position = 'fixed';
      loadingOverlay.style.top = '0';
      loadingOverlay.style.left = '0';
      loadingOverlay.style.width = '100%';
      loadingOverlay.style.height = '100%';
      loadingOverlay.style.backgroundColor = 'rgba(255,255,255,0.95)';
      loadingOverlay.style.zIndex = '9999';
      loadingOverlay.style.display = 'flex';
      loadingOverlay.style.justifyContent = 'center';
      loadingOverlay.style.alignItems = 'center';
      loadingOverlay.innerHTML = `
        <div class="text-center">
          <div class="spinner-border text-primary" style="width: 3rem; height: 3rem;" role="status">
            <span class="visually-hidden">Loading...</span>
          </div>
          <h4 class="mt-3">Processing...</h4>
          <p class="mt-2">${message}</p>
          <div class="progress mt-3" style="width: 300px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%"></div>
          </div>
          <p class="mt-2"><small>Items not found in database will be skipped automatically.</small></p>
        </div>
      `;
      document.body.appendChild(loadingOverlay);
    }
    
    if (importForm) {
      importForm.addEventListener('submit', function() {
        showProgress('Importing opening balances... System will automatically detect CSV/TSV format.');
      });
    }
    
    if (saveBtn) {
      saveBtn.addEventListener('click', function() {
        showProgress('Saving opening balances...');
      });
    }
    
    if (saveBottomBtn) {
      saveBottomBtn.addEventListener('click', function() {
        showProgress('Saving opening balances...');
      });
    }
  });
</script>
</body>
</html>