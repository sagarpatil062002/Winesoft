<?php
// Start output buffering to prevent header issues
ob_start();

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
 * Get all financial years from tblfinyear
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
            
            $start_year = $start_date->format('Y');
            $end_year = $end_date->format('Y');
            
            $start_formatted = $start_date->format('d-m-Y');
            $end_formatted = $end_date->format('d-m-Y');
            
            $fy_list[$row['ID']] = [
                'id' => $row['ID'],
                'start_date' => $row['START_DATE'],
                'end_date' => $row['END_DATE'],
                'start_formatted' => $start_formatted,
                'end_formatted' => $end_formatted,
                'label' => $start_year . '-' . $end_year,
                'start_year' => $start_year,
                'end_year' => $end_year,
                'active' => $row['ACTIVE']
            ];
        }
    }
    
    return $fy_list;
}

/**
 * Get all months in a financial year
 */
function getMonthsInFinancialYear($start_date, $end_date) {
    $months = [];
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('first day of next month');
    
    $interval = new DateInterval('P1M');
    $period = new DatePeriod($start, $interval, $end);
    
    foreach ($period as $month) {
        $months[] = $month->format('Y-m');
    }
    
    return $months;
}

/**
 * Create daily stock structure for a company
 */
function createCompanyDailyStockStructure($conn, $company_id, $fin_year_id, $start_date, $end_date) {
    $tables_created = [];
    $errors = [];
    
    $months = getMonthsInFinancialYear($start_date, $end_date);
    
    foreach ($months as $month) {
        $month_year = date('m_Y', strtotime($month . '-01'));
        $table_name = "tbldailystock_{$company_id}_{$fin_year_id}_{$month_year}";
        
        $check_query = "SHOW TABLES LIKE '$table_name'";
        $check_result = $conn->query($check_query);
        
        if ($check_result->num_rows == 0) {
            $year_month = explode('-', $month);
            $year = $year_month[0];
            $month_num = $year_month[1];
            $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month_num, $year);
            
            $create_query = "CREATE TABLE $table_name (
                `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
                `STK_DATE` date NOT NULL,
                `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
                `FIN_YEAR_ID` int(11) NOT NULL,
                `ITEM_CODE` varchar(20) NOT NULL,
                `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
                `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),";
            
            for ($day = 1; $day <= $days_in_month; $day++) {
                $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $create_query .= "
                `DAY_{$day_padded}_OPEN` int(11) DEFAULT 0,
                `DAY_{$day_padded}_PURCHASE` int(11) DEFAULT 0,
                `DAY_{$day_padded}_SALES` int(11) DEFAULT 0,
                `DAY_{$day_padded}_CLOSING` int(11) DEFAULT 0,";
            }
            
            $create_query .= "
                PRIMARY KEY (`DailyStockID`),
                UNIQUE KEY `unique_daily_stock` (`STK_DATE`, `ITEM_CODE`),
                KEY `idx_item_code` (`ITEM_CODE`),
                KEY `idx_liq_flag` (`LIQ_FLAG`),
                KEY `idx_fin_year` (`FIN_YEAR_ID`),
                KEY `idx_stk_month` (`STK_MONTH`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            if ($conn->query($create_query)) {
                $tables_created[] = $table_name;
            } else {
                $errors[] = "Failed to create table $table_name: " . $conn->error;
            }
        }
    }
    
    $summary_table = "tbldailystock_summary_{$company_id}_{$fin_year_id}";
    $check_summary = $conn->query("SHOW TABLES LIKE '$summary_table'");
    
    if ($check_summary->num_rows == 0) {
        $create_summary = "CREATE TABLE $summary_table (
            `SummaryID` int(11) NOT NULL AUTO_INCREMENT,
            `ITEM_CODE` varchar(20) NOT NULL,
            `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
            `FIN_YEAR_ID` int(11) NOT NULL,
            `OPENING_STOCK` int(11) DEFAULT 0,
            `TOTAL_PURCHASE` int(11) DEFAULT 0,
            `TOTAL_SALES` int(11) DEFAULT 0,
            `CLOSING_STOCK` int(11) DEFAULT 0,
            `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`SummaryID`),
            UNIQUE KEY `unique_item` (`ITEM_CODE`, `FIN_YEAR_ID`),
            KEY `idx_liq_flag` (`LIQ_FLAG`),
            KEY `idx_fin_year` (`FIN_YEAR_ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        if ($conn->query($create_summary)) {
            $tables_created[] = $summary_table;
        } else {
            $errors[] = "Failed to create summary table $summary_table: " . $conn->error;
        }
    }
    
    return ['tables' => $tables_created, 'errors' => $errors];
}

/**
 * Update tblitem_stock structure for new company
 */
function updateItemStockStructure($conn, $company_id, $fin_year_id) {
    $errors = [];
    
    $table_check = $conn->query("SHOW TABLES LIKE 'tblitem_stock'");
    
    if ($table_check->num_rows > 0) {
        $check_fy_column = $conn->query("SHOW COLUMNS FROM tblitem_stock LIKE 'FIN_YEAR_ID'");
        if ($check_fy_column->num_rows == 0) {
            $alter_fy = "ALTER TABLE tblitem_stock ADD COLUMN FIN_YEAR_ID int(11) DEFAULT NULL AFTER ITEM_CODE";
            if (!$conn->query($alter_fy)) {
                $errors[] = "Failed to add FIN_YEAR_ID column: " . $conn->error;
            }
        }
        
        $check_opening = $conn->query("SHOW COLUMNS FROM tblitem_stock LIKE 'OPENING_STOCK_$company_id'");
        if ($check_opening->num_rows == 0) {
            $alter_opening = "ALTER TABLE tblitem_stock ADD COLUMN OPENING_STOCK_$company_id int(11) DEFAULT 0";
            if (!$conn->query($alter_opening)) {
                $errors[] = "Failed to add OPENING_STOCK_$company_id column: " . $conn->error;
            }
        }
        
        $check_current = $conn->query("SHOW COLUMNS FROM tblitem_stock LIKE 'CURRENT_STOCK_$company_id'");
        if ($check_current->num_rows == 0) {
            $alter_current = "ALTER TABLE tblitem_stock ADD COLUMN CURRENT_STOCK_$company_id int(11) DEFAULT 0";
            if (!$conn->query($alter_current)) {
                $errors[] = "Failed to add CURRENT_STOCK_$company_id column: " . $conn->error;
            }
        }
        
        $check_monthly = $conn->query("SHOW COLUMNS FROM tblitem_stock LIKE 'MONTHLY_STOCK_$company_id'");
        if ($check_monthly->num_rows == 0) {
            $alter_monthly = "ALTER TABLE tblitem_stock ADD COLUMN MONTHLY_STOCK_$company_id int(11) DEFAULT 0";
            if (!$conn->query($alter_monthly)) {
                $errors[] = "Failed to add MONTHLY_STOCK_$company_id column: " . $conn->error;
            }
        }
    } else {
        $create_stock = "CREATE TABLE tblitem_stock (
            `StockID` int(11) NOT NULL AUTO_INCREMENT,
            `ITEM_CODE` varchar(20) NOT NULL,
            `FIN_YEAR_ID` int(11) NOT NULL,
            `OPENING_STOCK_$company_id` int(11) DEFAULT 0,
            `CURRENT_STOCK_$company_id` int(11) DEFAULT 0,
            `MONTHLY_STOCK_$company_id` int(11) DEFAULT 0,
            `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`StockID`),
            UNIQUE KEY `unique_item_fy` (`ITEM_CODE`, `FIN_YEAR_ID`),
            KEY `idx_item_code` (`ITEM_CODE`),
            KEY `idx_fin_year` (`FIN_YEAR_ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        if (!$conn->query($create_stock)) {
            $errors[] = "Failed to create tblitem_stock: " . $conn->error;
        }
    }
    
    return $errors;
}

// Check and add missing columns in tblcompany
$columns_to_check = [
    'License_Type' => 'VARCHAR(20) NULL AFTER COMP_FLNO',
    'IMFLLimit' => 'DECIMAL(10,2) DEFAULT 0.00 AFTER license_type_id',
    'BEERLimit' => 'DECIMAL(10,2) DEFAULT 0.00 AFTER IMFLLimit',
    'CLLimit' => 'DECIMAL(10,2) DEFAULT 0.00 AFTER BEERLimit'
];

foreach ($columns_to_check as $column_name => $column_definition) {
    $check_column = $conn->query("SHOW COLUMNS FROM tblcompany LIKE '$column_name'");
    if ($check_column->num_rows == 0) {
        $alter_table = $conn->query("ALTER TABLE tblcompany ADD $column_name $column_definition");
    }
}

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
    
    // If no errors, proceed with creation
    if (empty($errors)) {
        $conn->begin_transaction();
        
        try {
            // Insert company
            $insert_company = $conn->prepare("INSERT INTO tblcompany (COMP_NAME, CF_LINE, CS_LINE, FIN_YEAR, COMP_ADDR, license_type_id, COMP_FLNO, IMFLLimit, BEERLimit, CLLimit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insert_company->bind_param("sssisiiddd", $company_name, $cf_line, $cs_line, $fin_year_id, $comp_addr, $license_type_id, $comp_flno, $imfl_limit, $beer_limit, $cl_limit);
            
            if ($insert_company->execute()) {
                $company_id = $insert_company->insert_id;
                
                // Get financial year details
                $fy_query = "SELECT START_DATE, END_DATE FROM tblfinyear WHERE ID = ?";
                $fy_stmt = $conn->prepare($fy_query);
                $fy_stmt->bind_param("i", $fin_year_id);
                $fy_stmt->execute();
                $fy_result = $fy_stmt->get_result();
                $fy_data = $fy_result->fetch_assoc();
                $fy_stmt->close();
                
                if ($fy_data) {
                    // Create daily stock structure for the company
                    $stock_structure = createCompanyDailyStockStructure(
                        $conn, 
                        $company_id, 
                        $fin_year_id, 
                        $fy_data['START_DATE'], 
                        $fy_data['END_DATE']
                    );
                    
                    // Update tblitem_stock structure
                    $stock_errors = updateItemStockStructure($conn, $company_id, $fin_year_id);
                    
                    if (!empty($stock_errors)) {
                        foreach ($stock_errors as $stock_error) {
                            error_log("Stock structure error: " . $stock_error);
                        }
                    }
                }
                
                // Hash password
                $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
                $is_admin = 1;
                $created_by = 1;
                
                // Insert admin user
                $insert_user = $conn->prepare("INSERT INTO users (username, password, company_id, is_admin, created_by) VALUES (?, ?, ?, ?, ?)");
                $insert_user->bind_param("ssiii", $admin_username, $hashed_password, $company_id, $is_admin, $created_by);
                
                if ($insert_user->execute()) {
                    $conn->commit();
                    
                    // Set success flag and message
                    $creation_success = true;
                    $success_message = "Company and admin user created successfully!";
                    if (!empty($stock_structure['tables'])) {
                        $success_message .= " Created " . count($stock_structure['tables']) . " daily stock tables for financial year.";
                    }
                    
                    // Store success message in session for display after redirect
                    session_start();
                    $_SESSION['company_creation_success'] = $success_message;
                    $_SESSION['company_created'] = $company_name;
                    $_SESSION['admin_username'] = $admin_username;
                    
                    // Clean output buffer and redirect
                    ob_end_clean();
                    header("Location: /Winesoft/public/index.php");
                    exit;
                    
                } else {
                    throw new Exception("Error creating admin user: " . $conn->error);
                }
                
                $insert_user->close();
            } else {
                throw new Exception("Error creating company: " . $conn->error);
            }
            
            $insert_company->close();
        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

// Store errors in session to display after redirect (if needed)
if (!empty($errors)) {
    session_start();
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
    <title>Create New Company</title>
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
            max-width: 1000px;
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
        }

        .form-section h3 {
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
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
                <h2>Create New Company</h2>
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
                    <p><a href="/Winesoft/public/index.php">Click here</a> to go to login page.</p>
                </div>
                
                <?php
                endif;
                
                // Only show the form if creation was not successful
                if (!$creation_success):
                ?>
                
                <div class="info-box">
                    <h4><i class="fas fa-info-circle"></i> Company Creation Process</h4>
                    <ul>
                        <li>Creates company record in <strong>tblcompany</strong></li>
                        <li>Creates monthly daily stock tables: <strong>tbldailystock_{company_id}_{fin_year_id}_MM_YYYY</strong></li>
                        <li>Creates financial year summary table: <strong>tbldailystock_summary_{company_id}_{fin_year_id}</strong></li>
                        <li>Updates <strong>tblitem_stock</strong> with company-specific columns</li>
                        <li>Creates admin user account</li>
                        <li>After successful creation, you'll be redirected to the login page</li>
                    </ul>
                </div>
                
                <form method="POST" action="" id="companyForm">
                    <div class="form-section">
                        <h3>Company Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="company_name" class="required-field">Company Name</label>
                                <input type="text" id="company_name" name="company_name" required 
                                       value="<?php echo isset($_POST['company_name']) ? htmlspecialchars($_POST['company_name']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label for="fin_year" class="required-field">Financial Year</label>
                                <select id="fin_year" name="fin_year" required>
                                    <option value="">-- Select Financial Year --</option>
                                    <?php 
                                    if (!empty($all_fy_list)) {
                                        foreach ($all_fy_list as $id => $fy): 
                                            $selected = (isset($_POST['fin_year']) && $_POST['fin_year'] == $id) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $id; ?>" <?php echo $selected; ?>>
                                            <?php echo $fy['label']; ?> 
                                            (<?php echo $fy['start_formatted']; ?> to <?php echo $fy['end_formatted']; ?>)
                                            <?php echo $fy['active'] ? ' [Active]' : ''; ?>
                                        </option>
                                    <?php 
                                        endforeach;
                                    } else {
                                        echo '<option value="" disabled>No financial years found in database!</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="comp_addr">Company Address</label>
                                <textarea id="comp_addr" name="comp_addr" rows="2" maxlength="100"><?php echo isset($_POST['comp_addr']) ? htmlspecialchars($_POST['comp_addr']) : ''; ?></textarea>
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
                                       value="<?php echo isset($_POST['comp_flno']) ? htmlspecialchars($_POST['comp_flno']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="imfl_limit">IMFL Limit</label>
                                <input type="number" step="0.01" id="imfl_limit" name="imfl_limit" value="<?php echo isset($_POST['imfl_limit']) ? htmlspecialchars($_POST['imfl_limit']) : '1000.00'; ?>">
                            </div>
                            <div class="form-group">
                                <label for="beer_limit">BEER Limit</label>
                                <input type="number" step="0.01" id="beer_limit" name="beer_limit" value="<?php echo isset($_POST['beer_limit']) ? htmlspecialchars($_POST['beer_limit']) : '4000.00'; ?>">
                            </div>
                            <div class="form-group">
                                <label for="cl_limit">CL Limit</label>
                                <input type="number" step="0.01" id="cl_limit" name="cl_limit" value="<?php echo isset($_POST['cl_limit']) ? htmlspecialchars($_POST['cl_limit']) : '2000.00'; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Admin User Account</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="admin_username" class="required-field">Username</label>
                                <input type="text" id="admin_username" name="admin_username" required
                                       value="<?php echo isset($_POST['admin_username']) ? htmlspecialchars($_POST['admin_username']) : ''; ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="admin_password" class="required-field">Password</label>
                                <input type="password" id="admin_password" name="admin_password" required
                                       placeholder="Any password length (no restrictions)">
                            </div>
                            <div class="form-group">
                                <label for="confirm_password" class="required-field">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required>
                            </div>
                        </div>
                        <small style="color: #6c757d; display: block; margin-top: -10px;">Password can be any length - no complexity requirements</small>
                    </div>
                    
                    <div class="form-row">
                        <button type="submit" class="btn" id="submitBtn">Create Company & Admin Account</button>
                        <button type="reset" class="btn btn-secondary">Reset Form</button>
                        <a href="/Winesoft/public/index.php" class="btn btn-secondary" style="text-decoration: none;">Cancel</a>
                    </div>
                </form>
                
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('companyForm');
            if (form) {
                const password = document.getElementById('admin_password');
                const confirmPassword = document.getElementById('confirm_password');
                const submitBtn = document.getElementById('submitBtn');
                
                form.addEventListener('submit', function(e) {
                    // Check if passwords match
                    if (password.value !== confirmPassword.value) {
                        e.preventDefault();
                        alert('Passwords do not match!');
                        confirmPassword.focus();
                        return false;
                    }
                    
                    // HTML5 required attributes will handle empty fields
                    // But we'll add an extra check for empty passwords
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
                    
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Company...';
                    return true;
                });
            }
        });
    </script>
</body>
</html>