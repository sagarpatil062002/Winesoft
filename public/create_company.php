<?php
// Start output buffering to prevent header issues
ob_start();
session_start();

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "winesoft";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ==================== FINANCIAL YEAR FUNCTIONS ====================

/**
 * Get all financial years from tblfinyear with date validation
 */
function getAllFinancialYears($conn) {
    $fy_list = [];
    
    $query = "SELECT ID, START_DATE, END_DATE, ACTIVE 
              FROM tblfinyear 
              ORDER BY START_DATE DESC";
    
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $start_date = new DateTime($row['START_DATE']);
            $end_date = new DateTime($row['END_DATE']);
            
            // Format dates for display
            $start_formatted = $start_date->format('d-m-Y');
            $end_formatted = $end_date->format('d-m-Y');
            
            // Extract years for label
            $start_year = $start_date->format('Y');
            $end_year = $end_date->format('Y');
            
            // Determine if financial year spans across years
            if ($start_date->format('m') > $end_date->format('m')) {
                // Financial year spans across calendar years (e.g., Apr 2025 - Mar 2026)
                $label = $start_year . '-' . $end_year;
            } else {
                // Same calendar year financial year
                $label = $start_year;
            }
            
            $fy_list[$row['ID']] = [
                'id' => $row['ID'],
                'start_date' => $row['START_DATE'],
                'end_date' => $row['END_DATE'],
                'start_formatted' => $start_formatted,
                'end_formatted' => $end_formatted,
                'start_datetime' => $start_date,
                'end_datetime' => $end_date,
                'label' => $label,
                'start_year' => $start_year,
                'end_year' => $end_year,
                'active' => $row['ACTIVE']
            ];
        }
    }
    
    return $fy_list;
}

/**
 * Validate if a date falls within a financial year
 */
function isDateInFinancialYear($date, $fy_start, $fy_end) {
    $check_date = new DateTime($date);
    $start = new DateTime($fy_start);
    $end = new DateTime($fy_end);
    
    // Set time to start of day for accurate comparison
    $check_date->setTime(0, 0, 0);
    $start->setTime(0, 0, 0);
    $end->setTime(23, 59, 59); // Include the entire end date
    
    return ($check_date >= $start && $check_date <= $end);
}

/**
 * Validate financial year dates are properly set
 */
function validateFinancialYearDates($fy_data) {
    $errors = [];
    
    if (empty($fy_data['START_DATE'])) {
        $errors[] = "Financial year start date is not set.";
    }
    
    if (empty($fy_data['END_DATE'])) {
        $errors[] = "Financial year end date is not set.";
    }
    
    if (!empty($fy_data['START_DATE']) && !empty($fy_data['END_DATE'])) {
        $start = new DateTime($fy_data['START_DATE']);
        $end = new DateTime($fy_data['END_DATE']);
        
        if ($start > $end) {
            $errors[] = "Financial year start date cannot be after end date.";
        }
        
        // Check if financial year is at least 1 month long
        $interval = $start->diff($end);
        if ($interval->days < 28) { // Minimum about a month
            $errors[] = "Financial year must be at least 1 month long.";
        }
    }
    
    return $errors;
}

/**
 * Get all months in a financial year with validation
 */
function getMonthsInFinancialYear($start_date, $end_date) {
    $months = [];
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    
    // Set to first day of month for consistent iteration
    $start->modify('first day of this month');
    $end->modify('first day of next month');
    
    $interval = new DateInterval('P1M');
    $period = new DatePeriod($start, $interval, $end);
    
    $current_month = new DateTime();
    $current_month_formatted = $current_month->format('Y-m');
    
    foreach ($period as $month) {
        $year_month = $month->format('Y-m');
        $is_current_month = ($year_month === $current_month_formatted);
        
        $months[] = [
            'year_month' => $year_month,
            'year' => $month->format('Y'),
            'month' => $month->format('m'),
            'month_name' => $month->format('F Y'),
            'days_in_month' => cal_days_in_month(CAL_GREGORIAN, $month->format('m'), $month->format('Y')),
            'month_short' => $month->format('m'),
            'year_short' => $month->format('y'), // 2-digit year
            'is_current_month' => $is_current_month
        ];
    }
    
    return $months;
}

/**
 * Create daily stock structure for a company
 * - Current month table: tbldailystock_compid (ALWAYS created for current month operations)
 * - Archive month tables: tbldailystock_compid_mm_yy (only for PREVIOUS financial years)
 * - NO archive tables for current financial year
 */
function createCompanyDailyStockStructure($conn, $company_id, $start_date, $end_date) {
    $tables_created = [];
    $errors = [];
    
    error_log("Starting table creation for company ID: $company_id");
    error_log("Financial Year: $start_date to $end_date");
    
    // Validate financial year dates first
    $fy_data = ['START_DATE' => $start_date, 'END_DATE' => $end_date];
    $validation_errors = validateFinancialYearDates($fy_data);
    
    if (!empty($validation_errors)) {
        error_log("Financial year validation errors: " . implode(", ", $validation_errors));
        return ['tables' => [], 'errors' => $validation_errors];
    }
    
    // Check if selected FY is current or previous financial year
    $fy_start = new DateTime($start_date);
    $fy_end = new DateTime($end_date);
    $now = new DateTime();
    
    // Current FY is the one that contains the current date
    $is_current_fy = ($now >= $fy_start && $now <= $fy_end);
    
    error_log("Is Current FY: " . ($is_current_fy ? 'YES' : 'NO'));
    error_log("Current date: " . $now->format('Y-m-d'));
    error_log("FY range: " . $fy_start->format('Y-m-d') . " to " . $fy_end->format('Y-m-d'));
    
    // Get current month information
    $current_month = new DateTime();
    $current_month_formatted = $current_month->format('Y-m');
    $current_month_name = $current_month->format('F Y');
    $current_month_days = cal_days_in_month(CAL_GREGORIAN, $current_month->format('m'), $current_month->format('Y'));
    
    error_log("Current month: $current_month_name with $current_month_days days");
    
    // ===== PART 1: ALWAYS create current month table for ongoing operations =====
    $current_table_name = "tbldailystock_{$company_id}";
    
    error_log("Attempting to create current month table: $current_table_name");
    
    // Drop existing table if it exists to ensure clean creation
    $drop_query = "DROP TABLE IF EXISTS `$current_table_name`";
    if (!$conn->query($drop_query)) {
        error_log("Warning: Could not drop table $current_table_name: " . $conn->error);
    }
    
    // Create current month table (without date suffix)
    $create_current = "CREATE TABLE `$current_table_name` (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_DATE` date NOT NULL,
        `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),";
    
    // Add columns for each day of the current month
    for ($day = 1; $day <= $current_month_days; $day++) {
        $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
        $create_current .= "
        `DAY_{$day_padded}_OPEN` int(11) DEFAULT 0,
        `DAY_{$day_padded}_PURCHASE` int(11) DEFAULT 0,
        `DAY_{$day_padded}_SALES` int(11) DEFAULT 0,
        `DAY_{$day_padded}_CLOSING` int(11) DEFAULT 0,";
    }
    
    // Add indexes and constraints
    $create_current .= "
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock` (`STK_DATE`, `ITEM_CODE`),
        KEY `idx_item_code` (`ITEM_CODE`),
        KEY `idx_liq_flag` (`LIQ_FLAG`),
        KEY `idx_stk_month` (`STK_MONTH`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    error_log("Executing CREATE TABLE for current month: $current_table_name");
    
    if ($conn->query($create_current)) {
        $tables_created[] = [
            'name' => $current_table_name,
            'type' => 'Current Month',
            'month' => $current_month_name,
            'days' => $current_month_days,
            'current' => true
        ];
        
        error_log("SUCCESS: Created current month table: $current_table_name");
        
        // Verify the table was created
        $verify_query = "SHOW TABLES LIKE '$current_table_name'";
        $verify_result = $conn->query($verify_query);
        if ($verify_result->num_rows > 0) {
            error_log("VERIFIED: Current month table $current_table_name exists in database");
        } else {
            $errors[] = "CRITICAL: Current month table $current_table_name was created but not found in verification!";
            error_log("CRITICAL: Current month table $current_table_name was created but not found in verification!");
        }
    } else {
        $error_msg = "Failed to create current month table $current_table_name: " . $conn->error;
        $errors[] = "CRITICAL: " . $error_msg;
        error_log("CRITICAL: " . $error_msg);
    }
    
    // ===== PART 2: Create archive tables ONLY for PREVIOUS financial years =====
    // If selected FY is current (contains today's date), skip archive table creation
    
    if (!$is_current_fy) {
        $fy_months = getMonthsInFinancialYear($start_date, $end_date);
        
        error_log("Found " . count($fy_months) . " months in financial year (Previous FY - will create archives)");
        
        foreach ($fy_months as $month_data) {
            // Skip if this month is the current month (already created as current table)
            $year_month = $month_data['year_month'];
            if ($year_month === $current_month_formatted) {
                error_log("Skipping current month {$month_data['month_name']} from archive creation");
                continue;
            }
            
            // Archive month table: tbldailystock_compid_mm_yy
            $table_name = "tbldailystock_{$company_id}_{$month_data['month_short']}_{$month_data['year_short']}";
            
            error_log("Creating archive table: $table_name for {$month_data['month_name']} with {$month_data['days_in_month']} days");
            
            // Drop existing table if it exists to ensure clean creation
            $drop_query = "DROP TABLE IF EXISTS `$table_name`";
            $conn->query($drop_query);
            
            $days_in_month = $month_data['days_in_month'];
            
            // Create archive table for this FY month
            $create_query = "CREATE TABLE `$table_name` (
                `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
                `STK_DATE` date NOT NULL,
                `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
                `ITEM_CODE` varchar(20) NOT NULL,
                `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
                `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),";
            
            // Add columns for each day of the month
            for ($day = 1; $day <= $days_in_month; $day++) {
                $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $create_query .= "
                `DAY_{$day_padded}_OPEN` int(11) DEFAULT 0,
                `DAY_{$day_padded}_PURCHASE` int(11) DEFAULT 0,
                `DAY_{$day_padded}_SALES` int(11) DEFAULT 0,
                `DAY_{$day_padded}_CLOSING` int(11) DEFAULT 0,";
            }
            
            // Add indexes and constraints
            $create_query .= "
                PRIMARY KEY (`DailyStockID`),
                UNIQUE KEY `unique_daily_stock` (`STK_DATE`, `ITEM_CODE`),
                KEY `idx_item_code` (`ITEM_CODE`),
                KEY `idx_liq_flag` (`LIQ_FLAG`),
                KEY `idx_stk_month` (`STK_MONTH`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            if ($conn->query($create_query)) {
                $tables_created[] = [
                    'name' => $table_name,
                    'type' => 'FY Archive',
                    'month' => $month_data['month_name'],
                    'days' => $days_in_month,
                    'fy_month' => true
                ];
                
                error_log("SUCCESS: Created FY archive table: $table_name for {$month_data['month_name']}");
            } else {
                $error_msg = "Failed to create FY archive table $table_name: " . $conn->error;
                $errors[] = $error_msg;
                error_log($error_msg);
            }
        }
    } else {
        error_log("Current financial year selected - skipping archive table creation");
    }
    
    // ===== PART 3: Summary table REMOVED as per requirement =====
    // No summary table is created
    
    error_log("Table creation completed. Created " . count($tables_created) . " tables with " . count($errors) . " errors");
    
    return ['tables' => $tables_created, 'errors' => $errors];
}

/**
 * Create or update tblitem_stock structure with FY date tracking
 * Column naming convention: OPENING_STOCKCOMPID, CURRENT_STOCKCOMPID, MONTHLY_STOCKCOMPID
 */
function ensureItemStockStructure($conn, $company_id) {
    $errors = [];
    
    // Check if tblitem_stock exists
    $table_check = $conn->query("SHOW TABLES LIKE 'tblitem_stock'");
    
    if ($table_check->num_rows == 0) {
        // Create the table from scratch if it doesn't exist
        $create_stock = "CREATE TABLE tblitem_stock (
            `StockID` int(11) NOT NULL AUTO_INCREMENT,
            `ITEM_CODE` varchar(20) NOT NULL,
            PRIMARY KEY (`StockID`),
            UNIQUE KEY `unique_item` (`ITEM_CODE`),
            KEY `idx_item_code` (`ITEM_CODE`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        if (!$conn->query($create_stock)) {
            $errors[] = "Failed to create tblitem_stock: " . $conn->error;
            return $errors;
        }
    }
    
    // Add company-specific stock columns with correct naming convention
    // Column names should be: OPENING_STOCKCOMPID, CURRENT_STOCKCOMPID, MONTHLY_STOCKCOMPID
    $company_columns = [
        "OPENING_STOCK{$company_id}" => "int(11) DEFAULT 0",
        "CURRENT_STOCK{$company_id}" => "int(11) DEFAULT 0",
        "MONTHLY_STOCK{$company_id}" => "int(11) DEFAULT 0"
    ];
    
    foreach ($company_columns as $column => $definition) {
        $check_column = $conn->query("SHOW COLUMNS FROM tblitem_stock LIKE '$column'");
        if ($check_column->num_rows == 0) {
            $alter_sql = "ALTER TABLE tblitem_stock ADD COLUMN `$column` $definition";
            if (!$conn->query($alter_sql)) {
                $errors[] = "Failed to add $column column: " . $conn->error;
            } else {
                error_log("SUCCESS: Added column $column to tblitem_stock");
            }
        }
    }
    
    return $errors;
}

// ==================== MAIN PROCESSING ====================

// Get all financial years for dropdown
$all_fy_list = getAllFinancialYears($conn);

// Fetch license types for dropdown
$license_types = [];
$license_result = $conn->query("SELECT id, license_code FROM license_types ORDER BY license_code");

if ($license_result && $license_result->num_rows > 0) {
    while ($row = $license_result->fetch_assoc()) {
        $license_types[$row['id']] = $row['license_code'];
    }
}

// Check if this is a successful creation and we need to redirect
$creation_success = false;
$success_message = '';
$errors = [];

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $company_name = trim($_POST['company_name']);
    $cf_line = ''; 
    $cs_line = ''; 
    $fin_year_id = isset($_POST['fin_year']) ? intval($_POST['fin_year']) : 0;
    $comp_addr = trim($_POST['comp_addr']);
    $license_type_id = isset($_POST['license_type']) ? intval($_POST['license_type']) : 0;
    $comp_flno = trim($_POST['comp_flno']);
    $imfl_limit = isset($_POST['imfl_limit']) ? floatval($_POST['imfl_limit']) : 0.00;
    $beer_limit = isset($_POST['beer_limit']) ? floatval($_POST['beer_limit']) : 0.00;
    $cl_limit = isset($_POST['cl_limit']) ? floatval($_POST['cl_limit']) : 0.00;
    
    $admin_username = trim($_POST['admin_username']);
    $admin_password = $_POST['admin_password'];
    $confirm_password = $_POST['confirm_password'];
    
    $errors = [];
    $required_fields_missing = [];
    
    // Check ALL required fields
    if (empty($company_name)) {
        $required_fields_missing[] = "Company Name";
    }
    
    if (empty($fin_year_id)) {
        $required_fields_missing[] = "Financial Year";
    }
    
    if (empty($license_type_id)) {
        $required_fields_missing[] = "License Type";
    }
    
    if (empty($comp_flno)) {
        $required_fields_missing[] = "FL No.";
    }
    
    if (empty($admin_username)) {
        $required_fields_missing[] = "Username";
    }
    
    if (empty($admin_password)) {
        $required_fields_missing[] = "Password";
    }
    
    if (empty($confirm_password)) {
        $required_fields_missing[] = "Confirm Password";
    }
    
    // If any required fields are missing, add to errors
    if (!empty($required_fields_missing)) {
        $errors[] = "The following required fields are missing: " . implode(", ", $required_fields_missing);
    }
    
    // Check if passwords match (only if both are provided)
    if (!empty($admin_password) && !empty($confirm_password) && $admin_password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }
    
    // Check password length (optional - can be adjusted)
    if (!empty($admin_password) && strlen($admin_password) < 3) {
        $errors[] = "Password must be at least 3 characters long.";
    }
    
    // Check if username already exists (only if username is provided)
    if (!empty($admin_username)) {
        $check_user = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_user->bind_param("s", $admin_username);
        $check_user->execute();
        $check_user->store_result();
        
        if ($check_user->num_rows > 0) {
            $errors[] = "Username already exists. Please choose a different username.";
        }
        $check_user->close();
    }
    
    // Validate financial year exists and get its dates
    $fy_data = null;
    if (!empty($fin_year_id) && isset($all_fy_list[$fin_year_id])) {
        $fy_data = $all_fy_list[$fin_year_id];
        
        // Validate financial year dates
        $fy_errors = validateFinancialYearDates([
            'START_DATE' => $fy_data['start_date'],
            'END_DATE' => $fy_data['end_date']
        ]);
        
        if (!empty($fy_errors)) {
            foreach ($fy_errors as $fy_error) {
                $errors[] = "Financial Year error: " . $fy_error;
            }
        }
    } elseif (!empty($fin_year_id)) {
        $errors[] = "Selected financial year does not exist in database.";
    }
    
    // If no errors, proceed with creation
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // FIRST: Insert company
            $insert_company = $conn->prepare("INSERT INTO tblcompany (COMP_NAME, CF_LINE, CS_LINE, FIN_YEAR, COMP_ADDR, license_type_id, COMP_FLNO, IMFLLimit, BEERLimit, CLLimit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_company->bind_param("sssisiiddd", $company_name, $cf_line, $cs_line, $fin_year_id, $comp_addr, $license_type_id, $comp_flno, $imfl_limit, $beer_limit, $cl_limit);
            
            if (!$insert_company->execute()) {
                throw new Exception("Error creating company: " . $conn->error);
            }
            
            $company_id = $insert_company->insert_id;
            $insert_company->close();
            
            error_log("Company created with ID: $company_id");
            
            // SECOND: Add financial year to company_finyear table (new multiple year support)
            $insert_fy_link = $conn->prepare("INSERT INTO tblcompany_finyear (company_id, finyear_id, is_active) VALUES (?, ?, 1)");
            $insert_fy_link->bind_param("ii", $company_id, $fin_year_id);
            
            if (!$insert_fy_link->execute()) {
                error_log("Warning: Could not add financial year to tblcompany_finyear: " . $conn->error);
            }
            $insert_fy_link->close();
            
            // THIRD: Get financial year details
            $fy_query = "SELECT START_DATE, END_DATE FROM tblfinyear WHERE ID = ?";
            $fy_stmt = $conn->prepare($fy_query);
            $fy_stmt->bind_param("i", $fin_year_id);
            $fy_stmt->execute();
            $fy_result = $fy_stmt->get_result();
            $fy_data = $fy_result->fetch_assoc();
            $fy_stmt->close();
            
            if (!$fy_data) {
                throw new Exception("Financial year data not found for ID: " . $fin_year_id);
            }
            
            error_log("Financial year dates: START=" . $fy_data['START_DATE'] . ", END=" . $fy_data['END_DATE']);
            
            // FOURTH: Create daily stock tables (NO summary table)
            $stock_structure = createCompanyDailyStockStructure(
                $conn, 
                $company_id, 
                $fy_data['START_DATE'], 
                $fy_data['END_DATE']
            );
            
            // VERIFY current month table was created
            $current_table_check = "tbldailystock_{$company_id}";
            $verify_table = $conn->query("SHOW TABLES LIKE '$current_table_check'");
            
            if ($verify_table->num_rows == 0) {
                error_log("CRITICAL: Current month table $current_table_check not found after creation!");
                
                // Emergency fallback - try to create just the current month table
                error_log("EMERGENCY: Attempting direct creation of current month table");
                
                $current_month = new DateTime();
                $current_month_days = cal_days_in_month(CAL_GREGORIAN, $current_month->format('m'), $current_month->format('Y'));
                
                $emergency_create = "CREATE TABLE `$current_table_check` (
                    `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
                    `STK_DATE` date NOT NULL,
                    `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
                    `ITEM_CODE` varchar(20) NOT NULL,
                    `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
                    `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),";
                
                for ($day = 1; $day <= $current_month_days; $day++) {
                    $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                    $emergency_create .= "
                    `DAY_{$day_padded}_OPEN` int(11) DEFAULT 0,
                    `DAY_{$day_padded}_PURCHASE` int(11) DEFAULT 0,
                    `DAY_{$day_padded}_SALES` int(11) DEFAULT 0,
                    `DAY_{$day_padded}_CLOSING` int(11) DEFAULT 0,";
                }
                
                $emergency_create .= "
                    PRIMARY KEY (`DailyStockID`),
                    UNIQUE KEY `unique_daily_stock` (`STK_DATE`, `ITEM_CODE`),
                    KEY `idx_item_code` (`ITEM_CODE`),
                    KEY `idx_liq_flag` (`LIQ_FLAG`),
                    KEY `idx_stk_month` (`STK_MONTH`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
                
                if ($conn->query($emergency_create)) {
                    $stock_structure['tables'][] = [
                        'name' => $current_table_check,
                        'type' => 'Current Month (Emergency)',
                        'month' => $current_month->format('F Y'),
                        'days' => $current_month_days
                    ];
                    error_log("EMERGENCY: Successfully created current month table");
                    
                    // Verify again
                    $verify_again = $conn->query("SHOW TABLES LIKE '$current_table_check'");
                    if ($verify_again->num_rows > 0) {
                        error_log("VERIFIED: Current month table exists after emergency creation");
                    }
                } else {
                    $error_msg = "CRITICAL: Could not create current month table even in emergency: " . $conn->error;
                    $errors[] = $error_msg;
                    error_log($error_msg);
                }
            } else {
                error_log("VERIFIED: Current month table $current_table_check exists in database");
            }
            
            if (!empty($stock_structure['errors'])) {
                foreach ($stock_structure['errors'] as $stock_error) {
                    error_log("Stock structure error: " . $stock_error);
                    $errors[] = "Warning - Stock table creation issue: " . $stock_error;
                }
            }
            
            // FIFTH: Update tblitem_stock with company-specific columns (correct naming convention)
            $update_stock_errors = ensureItemStockStructure($conn, $company_id);
            if (!empty($update_stock_errors)) {
                foreach ($update_stock_errors as $stock_error) {
                    error_log("Stock structure update error: " . $stock_error);
                    $errors[] = "Warning - Stock structure update issue: " . $stock_error;
                }
            }
            
            // SIXTH: Insert admin user
            $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
            $is_admin = 1;
            $created_by = 1;
            
            $insert_user = $conn->prepare("INSERT INTO users (username, password, company_id, is_admin, created_by) VALUES (?, ?, ?, ?, ?)");
            $insert_user->bind_param("ssiii", $admin_username, $hashed_password, $company_id, $is_admin, $created_by);
            
            if (!$insert_user->execute()) {
                throw new Exception("Error creating admin user: " . $conn->error);
            }
            
            $insert_user->close();
            
            // If we got here, commit the transaction
            $conn->commit();
            
            // Set success flag and message
            $creation_success = true;
            $success_message = "Company and admin user created successfully!";
            
            if (!empty($stock_structure['tables'])) {
                $tables_count = count($stock_structure['tables']);
                $success_message .= " Created $tables_count daily stock tables.<br>";
                $success_message .= "<strong>Table Structure:</strong><br>";
                
                // Separate tables by type
                $current_table = null;
                $fy_archive_tables = [];
                
                foreach ($stock_structure['tables'] as $table) {
                    if ($table['type'] === 'Current Month' || $table['type'] === 'Current Month (Emergency)') {
                        $current_table = $table;
                    } elseif ($table['type'] === 'FY Archive') {
                        $fy_archive_tables[] = $table;
                    }
                }
                
                // Show current month table (ALWAYS created)
                if ($current_table) {
                    $success_message .= "• <span style='color: #38A169; font-weight: bold;'>CURRENT MONTH TABLE (Ongoing Operations):</span><br>";
                    $success_message .= "  <strong>{$current_table['name']}</strong> - {$current_table['month']} ({$current_table['days']} days)<br>";
                } else {
                    $success_message .= "• <span style='color: #E53E3E; font-weight: bold;'>ERROR: Current month table was not created!</span><br>";
                }
                
                // Show FY archive tables
                if (!empty($fy_archive_tables)) {
                    $fy_label = date('Y', strtotime($fy_data['START_DATE'])) . "-" . date('y', strtotime($fy_data['END_DATE']));
                    $success_message .= "• <span style='color: #2B6CB0; font-weight: bold;'>FINANCIAL YEAR ARCHIVE TABLES (FY {$fy_label} - Previous Year):</span><br>";
                    
                    // Sort archive tables by date
                    usort($fy_archive_tables, function($a, $b) {
                        return strtotime($a['month']) - strtotime($b['month']);
                    });
                    
                    foreach ($fy_archive_tables as $table) {
                        $success_message .= "  - <strong>{$table['name']}</strong> - {$table['month']} ({$table['days']} days)<br>";
                    }
                    $success_message .= "  Total: " . count($fy_archive_tables) . " archive tables<br>";
                } else {
                    $success_message .= "• <span style='color: #38A169; font-weight: bold;'>Note:</span> Current Financial Year selected - Archive tables will be created as months pass.<br>";
                }
            }
            
            // Add information about tblitem_stock columns
            $success_message .= "<br><strong>Item Stock Structure:</strong><br>";
            $success_message .= "• Added columns to tblitem_stock:<br>";
            $success_message .= "  - <strong>OPENING_STOCK{$company_id}</strong> (Opening Stock for Company {$company_id})<br>";
            $success_message .= "  - <strong>CURRENT_STOCK{$company_id}</strong> (Current Stock for Company {$company_id})<br>";
            $success_message .= "  - <strong>MONTHLY_STOCK{$company_id}</strong> (Monthly Stock for Company {$company_id})<br>";
            
            // Store success message in session for display after redirect
            $_SESSION['company_creation_success'] = $success_message;
            $_SESSION['company_created'] = $company_name;
            $_SESSION['admin_username'] = $admin_username;
            $_SESSION['company_id'] = $company_id;
            $_SESSION['fy_dates'] = [
                'start' => date('d-m-Y', strtotime($fy_data['START_DATE'])),
                'end' => date('d-m-Y', strtotime($fy_data['END_DATE']))
            ];
            
            // Clean output buffer and redirect
            ob_end_clean();
            header("Location: /Winesoft/public/index.php");
            exit;
            
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error: " . $e->getMessage();
            error_log("Transaction error: " . $e->getMessage());
        }
    }
}

// Store errors in session to display after redirect (if needed)
if (!empty($errors)) {
    $_SESSION['creation_errors'] = $errors;
    $_SESSION['form_data'] = $_POST;
}

// End output buffering and send output
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Company - Financial Year Enforcement</title>
    <style>
        :root {
            --primary-color: #2B6CB0;
            --primary-hover: #4299E1;
            --secondary-color: #F6AD55;
            --background-color: #F7FAFC;
            --text-color: #2D3748;
            --light-text: #718096;
            --error-color: #E53E3E;
            --success-color: #38A169;
            --warning-color: #DD6B20;
            --white: #FFFFFF;
            --border-radius: 6px;
            --box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            --transition: all 0.2s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--background-color);
            color: var(--text-color);
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            max-width: 1100px;
            margin: 0 auto;
        }

        .card {
            background-color: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            margin-bottom: 30px;
        }

        .card-header {
            background-color: var(--primary-color);
            color: var(--white);
            padding: 20px;
            text-align: center;
        }

        .card-header h2 {
            font-weight: 600;
            font-size: 1.8rem;
        }

        .card-body {
            padding: 30px;
        }

        .form-section {
            margin-bottom: 30px;
            border: 1px solid #e2e8f0;
            border-radius: var(--border-radius);
            padding: 20px;
        }

        .form-section h3 {
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-color);
            font-weight: 600;
        }

        .form-row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -10px 15px;
        }

        .form-group {
            flex: 1 0 calc(50% - 20px);
            margin: 0 10px 15px;
            min-width: 250px;
        }

        .form-group.full-width {
            flex: 1 0 calc(100% - 20px);
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }

        .required-field::after {
            content: "*";
            color: var(--error-color);
            margin-left: 4px;
        }

        input, select, textarea {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #cbd5e0;
            border-radius: var(--border-radius);
            font-size: 16px;
            transition: var(--transition);
            background-color: var(--white);
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(66, 153, 225, 0.2);
        }

        input:read-only, select:disabled {
            background-color: #edf2f7;
            cursor: not-allowed;
        }

        .btn {
            display: inline-block;
            padding: 12px 24px;
            background-color: var(--primary-color);
            color: var(--white);
            border: none;
            border-radius: var(--border-radius);
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-right: 10px;
        }

        .btn:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background-color: var(--light-text);
        }

        .btn-secondary:hover {
            background-color: #a0aec0;
        }

        .btn-warning {
            background-color: var(--warning-color);
        }

        .btn-warning:hover {
            background-color: #e67e22;
        }

        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: var(--border-radius);
            font-weight: 500;
        }

        .alert-success {
            background-color: #c6f6d5;
            color: var(--success-color);
            border: 1px solid #9ae6b4;
        }

        .alert-error {
            background-color: #fed7d7;
            color: var(--error-color);
            border: 1px solid #feb2b2;
        }

        .alert-warning {
            background-color: #feebc8;
            color: var(--warning-color);
            border: 1px solid #fbd38d;
        }

        .alert ul {
            margin: 10px 0 0 20px;
        }

        .info-box {
            background-color: #ebf8ff;
            border-left: 4px solid var(--primary-color);
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 0 var(--border-radius) var(--border-radius) 0;
        }

        .info-box h4 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .info-box ul {
            margin-left: 20px;
            color: var(--text-color);
        }

        .validation-summary {
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
        }

        .validation-summary ul {
            margin: 10px 0 0 20px;
        }

        .fy-dates-display {
            background-color: #f0f9ff;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-top: 10px;
            border: 1px solid #bae6fd;
        }

        .fy-dates-display h4 {
            color: var(--primary-color);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .fy-dates-display p {
            margin: 5px 0;
        }

        .date-range {
            display: inline-block;
            background-color: var(--primary-color);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.9rem;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .badge-active {
            background-color: #c6f6d5;
            color: #22543d;
        }

        .badge-inactive {
            background-color: #fed7d7;
            color: #742a2a;
        }

        .warning-message {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 10px 15px;
            margin: 10px 0;
            border-radius: 4px;
        }

        .table-format-example {
            background-color: #e6f7ff;
            border: 1px solid #91d5ff;
            border-radius: 4px;
            padding: 15px;
            margin: 15px 0;
        }

        .table-format-example ul {
            margin: 10px 0 0 20px;
        }

        .table-format-example li {
            margin: 5px 0;
        }

        .current-month-table {
            color: var(--success-color);
            font-weight: bold;
        }

        .archive-table {
            color: var(--primary-color);
            font-weight: bold;
        }

        @media (max-width: 768px) {
            .form-group {
                flex: 1 0 calc(100% - 20px);
            }
            
            .card-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-building"></i> Create New Company - Financial Year Enforcement</h2>
                <p style="margin-top: 10px; opacity: 0.9;">Current month table always created for ongoing operations</p>
            </div>
            <div class="card-body">
                <?php
                // Display errors if any
                if (!empty($errors)) {
                    echo '<div class="validation-summary">';
                    echo '<strong><i class="fas fa-exclamation-triangle"></i> Please correct the following errors:</strong>';
                    echo '<ul>';
                    foreach ($errors as $error) {
                        echo '<li>' . htmlspecialchars($error) . '</li>';
                    }
                    echo '</ul>';
                    echo '</div>';
                }

                // If creation was successful, show message (this should rarely show since redirect happens)
                if ($creation_success):
                ?>
                <div class="alert alert-success">
                    <h3 style="margin-bottom: 15px;">
                        <i class="fas fa-check-circle"></i> Success!
                    </h3>
                    <p><?php echo $success_message; ?></p>
                    <p><a href="/Winesoft/public/index.php" class="btn">Go to Login Page</a></p>
                </div>
                
                <?php
                endif;
                
                // Only show the form if creation was not successful
                if (!$creation_success):
                ?>
                
                
                <form method="POST" action="" id="companyForm">
                    <div class="form-section">
                        <h3><i class="fas fa-building"></i> Company Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="company_name" class="required-field">Company Name</label>
                                <input type="text" id="company_name" name="company_name" required 
                                       value="<?php echo isset($_POST['company_name']) ? htmlspecialchars($_POST['company_name']) : ''; ?>"
                                       placeholder="Enter company name">
                            </div>
                            <div class="form-group">
                                <label for="fin_year" class="required-field">Financial Year</label>
                                <select id="fin_year" name="fin_year" required onchange="updateFYDates(this)">
                                    <option value="">-- Select Financial Year --</option>
                                    <?php 
                                    if (!empty($all_fy_list)) {
                                        foreach ($all_fy_list as $id => $fy): 
                                            $selected = (isset($_POST['fin_year']) && $_POST['fin_year'] == $id) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $id; ?>" 
                                                data-start="<?php echo htmlspecialchars($fy['start_formatted']); ?>"
                                                data-end="<?php echo htmlspecialchars($fy['end_formatted']); ?>"
                                                data-start-raw="<?php echo htmlspecialchars($fy['start_date']); ?>"
                                                data-end-raw="<?php echo htmlspecialchars($fy['end_date']); ?>"
                                                data-active="<?php echo $fy['active']; ?>"
                                                <?php echo $selected; ?>>
                                            <?php echo $fy['label']; ?> 
                                            (<?php echo $fy['start_formatted']; ?> to <?php echo $fy['end_formatted']; ?>)
                                            <?php echo $fy['active'] ? '✓' : '✗'; ?>
                                        </option>
                                    <?php 
                                        endforeach;
                                    } else {
                                        echo '<option value="" disabled>⚠️ No financial years found in database!</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Financial Year Dates Display -->
                        <div id="fyDatesDisplay" class="fy-dates-display" style="display: none;">
                            <h4>
                                <i class="fas fa-calendar-alt"></i> Selected Financial Year Details
                                <span id="fyActiveBadge" class="badge badge-inactive" style="display: none;"></span>
                            </h4>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Financial Year Start Date</label>
                                    <input type="text" id="fy_start_display" class="date-range" readonly value="">
                                </div>
                                <div class="form-group">
                                    <label>Financial Year End Date</label>
                                    <input type="text" id="fy_end_display" class="date-range" readonly value="">
                                </div>
                            </div>
                            <p class="warning-message" id="fyWarning" style="display: none;">
                                <i class="fas fa-exclamation-triangle"></i> 
                                <span id="fyWarningMessage"></span>
                            </p>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="comp_addr">Company Address</label>
                                <textarea id="comp_addr" name="comp_addr" rows="2" maxlength="100" 
                                          placeholder="Enter company address"><?php echo isset($_POST['comp_addr']) ? htmlspecialchars($_POST['comp_addr']) : ''; ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="license_type" class="required-field">License Type</label>
                                <select id="license_type" name="license_type" required>
                                    <option value="">-- Select License Type --</option>
                                    <?php foreach ($license_types as $id => $license_code): ?>
                                        <option value="<?php echo $id; ?>" 
                                            <?php echo (isset($_POST['license_type']) && $_POST['license_type'] == $id) ? 'selected' : ''; ?>>
                                            <?php echo $license_code; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="comp_flno" class="required-field">FL No.</label>
                                <input type="text" id="comp_flno" name="comp_flno" maxlength="12" required
                                       value="<?php echo isset($_POST['comp_flno']) ? htmlspecialchars($_POST['comp_flno']) : ''; ?>"
                                       placeholder="e.g., FL/2025/001">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="imfl_limit">IMFL Limit</label>
                                <input type="number" step="0.01" id="imfl_limit" name="imfl_limit" 
                                       value="<?php echo isset($_POST['imfl_limit']) ? htmlspecialchars($_POST['imfl_limit']) : '1000.00'; ?>">
                            </div>
                            <div class="form-group">
                                <label for="beer_limit">BEER Limit</label>
                                <input type="number" step="0.01" id="beer_limit" name="beer_limit" 
                                       value="<?php echo isset($_POST['beer_limit']) ? htmlspecialchars($_POST['beer_limit']) : '4000.00'; ?>">
                            </div>
                            <div class="form-group">
                                <label for="cl_limit">CL Limit</label>
                                <input type="number" step="0.01" id="cl_limit" name="cl_limit" 
                                       value="<?php echo isset($_POST['cl_limit']) ? htmlspecialchars($_POST['cl_limit']) : '2000.00'; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3><i class="fas fa-user-shield"></i> Admin User Account</h3>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="admin_username" class="required-field">Username</label>
                                <input type="text" id="admin_username" name="admin_username" required
                                       value="<?php echo isset($_POST['admin_username']) ? htmlspecialchars($_POST['admin_username']) : ''; ?>"
                                       placeholder="Enter admin username">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="admin_password" class="required-field">Password</label>
                                <input type="password" id="admin_password" name="admin_password" required
                                       placeholder="Enter password">
                            </div>
                            <div class="form-group">
                                <label for="confirm_password" class="required-field">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required
                                       placeholder="Confirm password">
                            </div>
                        </div>
                        <small style="color: #6c757d; display: block; margin-top: -10px;">
                            <i class="fas fa-info-circle"></i> Password can be any length - no complexity requirements
                        </small>
                    </div>
                    
                    <div class="form-row" style="justify-content: center; gap: 10px;">
                        <button type="submit" class="btn" id="submitBtn">
                            <i class="fas fa-save"></i> Create Company & Admin Account
                        </button>
                        <button type="reset" class="btn btn-secondary">
                            <i class="fas fa-undo"></i> Reset Form
                        </button>
                        <a href="/Winesoft/public/index.php" class="btn btn-warning">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
                
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Function to update financial year dates display
        function updateFYDates(selectElement) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const fyDisplay = document.getElementById('fyDatesDisplay');
            const startDisplay = document.getElementById('fy_start_display');
            const endDisplay = document.getElementById('fy_end_display');
            const activeBadge = document.getElementById('fyActiveBadge');
            const fyWarning = document.getElementById('fyWarning');
            const warningMessage = document.getElementById('fyWarningMessage');
            
            if (selectedOption.value) {
                const startDate = selectedOption.getAttribute('data-start');
                const endDate = selectedOption.getAttribute('data-end');
                const startRaw = selectedOption.getAttribute('data-start-raw');
                const endRaw = selectedOption.getAttribute('data-end-raw');
                const isActive = selectedOption.getAttribute('data-active') === '1';
                
                startDisplay.value = startDate;
                endDisplay.value = endDate;
                
                // Update active badge
                if (isActive) {
                    activeBadge.textContent = 'Active Financial Year';
                    activeBadge.className = 'badge badge-active';
                    activeBadge.style.display = 'inline-block';
                    
                    // Hide warning for active FY
                    fyWarning.style.display = 'none';
                } else {
                    activeBadge.textContent = 'Inactive Financial Year';
                    activeBadge.className = 'badge badge-inactive';
                    activeBadge.style.display = 'inline-block';
                    
                    // Show warning for inactive FY
                    warningMessage.textContent = 'Warning: Selected financial year is marked as inactive. Company creation is still allowed but transactions may be restricted.';
                    fyWarning.style.display = 'block';
                }
                
                // Validate dates
                const start = new Date(startRaw);
                const end = new Date(endRaw);
                
                if (start > end) {
                    warningMessage.textContent = 'Error: Financial year start date is after end date! Please select a valid financial year.';
                    fyWarning.style.display = 'block';
                    fyWarning.style.backgroundColor = '#fed7d7';
                    fyWarning.style.color = '#742a2a';
                } else {
                    // Calculate duration
                    const diffTime = Math.abs(end - start);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                    
                    if (diffDays < 28) {
                        warningMessage.textContent = 'Warning: Financial year is less than one month long. This may cause issues with monthly stock tables.';
                        fyWarning.style.display = 'block';
                        fyWarning.style.backgroundColor = '#fff3cd';
                        fyWarning.style.color = '#856404';
                    }
                }
                
                fyDisplay.style.display = 'block';
            } else {
                fyDisplay.style.display = 'none';
            }
        }
        
        // Check if there's a pre-selected financial year on page load
        document.addEventListener('DOMContentLoaded', function() {
            const finYearSelect = document.getElementById('fin_year');
            if (finYearSelect.value) {
                updateFYDates(finYearSelect);
            }
            
            const form = document.getElementById('companyForm');
            if (form) {
                const password = document.getElementById('admin_password');
                const confirmPassword = document.getElementById('confirm_password');
                const submitBtn = document.getElementById('submitBtn');
                
                form.addEventListener('submit', function(e) {
                    // Check if financial year is selected
                    if (!finYearSelect.value) {
                        e.preventDefault();
                        alert('Please select a Financial Year!');
                        finYearSelect.focus();
                        return false;
                    }
                    
                    // Check if passwords match
                    if (password.value !== confirmPassword.value) {
                        e.preventDefault();
                        alert('Passwords do not match!');
                        confirmPassword.focus();
                        return false;
                    }
                    
                    // Check for empty passwords
                    if (!password.value.trim()) {
                        e.preventDefault();
                        alert('Password cannot be empty!');
                        password.focus();
                        return false;
                    }
                    
                    if (!confirmPassword.value.trim()) {
                        e.preventDefault();
                        alert('Please confirm your password!');
                        confirmPassword.focus();
                        return false;
                    }
                    
                    // Check password length
                    if (password.value.trim().length < 3) {
                        e.preventDefault();
                        alert('Password must be at least 3 characters long!');
                        password.focus();
                        return false;
                    }
                    
                    // Confirm company creation with financial year dates
                    const startDate = document.getElementById('fy_start_display').value;
                    const endDate = document.getElementById('fy_end_display').value;
                    const currentMonth = '<?php echo date('F Y'); ?>';
                    const currentDays = <?php echo cal_days_in_month(CAL_GREGORIAN, date('m'), date('Y')); ?>;
                    
                    if (!confirm(`Create company with:\n\n` +
                        `Financial Year: ${startDate} to ${endDate}\n\n` +
                        `Table Structure:\n` +
                        `• CURRENT MONTH (${currentMonth}, ${currentDays} days): tbldailystock_XX\n` +
                        `• FY Archive Tables: tbldailystock_XX_mm_yy (one per month in FY)\n\n` +
                        `Item Stock Columns:\n` +
                        `• OPENING_STOCKXX\n` +
                        `• CURRENT_STOCKXX\n` +
                        `• MONTHLY_STOCKXX\n\n` +
                        `Proceed with creation?`)) {
                        e.preventDefault();
                        return false;
                    }
                    
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Company...';
                    return true;
                });
            }
        });
    </script>
    
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</body>
</html>