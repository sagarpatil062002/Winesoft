<?php
// logout.php

session_start();

// Check if this is an AJAX backup request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'backup') {
    
    $company_id = $_SESSION['CompID'] ?? null;
    $fin_year_id = $_SESSION['FIN_YEAR_ID'] ?? null;
    $company_name = $_SESSION['COMP_NAME'] ?? 'company';
    
    // Database credentials
    $host = "localhost";
    $user = "root";
    $pass = "";
    $dbname = "winesoft";
    
    // Create backup directories
    $base_backup_dir = __DIR__ . '/backups';
    $complete_backup_dir = $base_backup_dir . '/complete_backup';
    $company_backup_dir = $base_backup_dir . '/company_backup';
    
    if (!is_dir($base_backup_dir)) {
        mkdir($base_backup_dir, 0755, true);
    }
    if (!is_dir($complete_backup_dir)) {
        mkdir($complete_backup_dir, 0755, true);
    }
    if (!is_dir($company_backup_dir)) {
        mkdir($company_backup_dir, 0755, true);
    }
    
    $results = ['success' => true, 'files' => []];
    
    // Connect to database
    $conn = new mysqli($host, $user, $pass, $dbname);
    
    if ($conn->connect_error) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    
    // 1. Create FULL DATABASE BACKUP using PHP (not mysqldump)
    $full_filename = "full_database_backup_{$timestamp}.sql";
    $full_filepath = $complete_backup_dir . '/' . $full_filename;
    
    $full_backup_result = createFullDatabaseBackup($conn, $full_filepath, $dbname);
    
    if ($full_backup_result['success'] && file_exists($full_filepath) && filesize($full_filepath) > 0) {
        $results['files']['full'] = [
            'filename' => $full_filename,
            'path' => 'backups/complete_backup/' . $full_filename,
            'size' => filesize($full_filepath)
        ];
    } else {
        $results['files']['full'] = [
            'filename' => $full_filename,
            'error' => $full_backup_result['error'] ?? 'Failed to create backup'
        ];
        $results['success'] = false;
    }
    
    // 2. Create COMPANY-SPECIFIC BACKUP
    if ($company_id) {
        // Create company-specific folder
        $safe_company_name = preg_replace('/[^a-zA-Z0-9]/', '_', $company_name);
        $company_dir = $company_backup_dir . '/' . $safe_company_name;
        if (!is_dir($company_dir)) {
            mkdir($company_dir, 0755, true);
        }
        
        $company_filename = "{$safe_company_name}_backup_{$timestamp}.sql";
        $company_filepath = $company_dir . '/' . $company_filename;
        
        $company_backup_result = createCompanyBackup($conn, $company_filepath, $company_id, $company_name, $fin_year_id);
        
        if ($company_backup_result['success'] && file_exists($company_filepath) && filesize($company_filepath) > 0) {
            $results['files']['company'] = [
                'filename' => $company_filename,
                'path' => 'backups/company_backup/' . $safe_company_name . '/' . $company_filename,
                'size' => filesize($company_filepath)
            ];
        } else {
            $results['files']['company'] = [
                'filename' => $company_filename,
                'error' => $company_backup_result['error'] ?? 'Failed to create backup'
            ];
        }
    }
    
    $conn->close();
    
    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
}

/**
 * Create full database backup using PHP (not mysqldump)
 */
function createFullDatabaseBackup($conn, $filepath, $dbname) {
    $fp = fopen($filepath, 'w');
    
    if (!$fp) {
        return ['success' => false, 'error' => 'Cannot create backup file'];
    }
    
    // Write header
    fwrite($fp, "-- WineSoft Full Database Backup\n");
    fwrite($fp, "-- ====================================================\n");
    fwrite($fp, "-- Database: {$dbname}\n");
    fwrite($fp, "-- Backup Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "-- ====================================================\n\n");
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    // Get all tables in the database
    $result = $conn->query("SHOW TABLES");
    if (!$result) {
        fclose($fp);
        return ['success' => false, 'error' => 'Cannot get tables list'];
    }
    
    $tables = [];
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    
    foreach ($tables as $table) {
        // Get table structure
        $create_result = $conn->query("SHOW CREATE TABLE `{$table}`");
        if (!$create_result) continue;
        
        $create_row = $create_result->fetch_assoc();
        
        fwrite($fp, "-- --------------------------------------------------------\n");
        fwrite($fp, "-- Table: {$table}\n");
        fwrite($fp, "-- --------------------------------------------------------\n");
        fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
        fwrite($fp, $create_row['Create Table'] . ";\n\n");
        
        // Get all data from the table
        $data_result = $conn->query("SELECT * FROM `{$table}`");
        
        if ($data_result && $data_result->num_rows > 0) {
            $columns = array_keys($data_result->fetch_assoc());
            $data_result->data_seek(0);
            
            $batch_size = 100;
            $count = 0;
            
            while ($row_data = $data_result->fetch_assoc()) {
                $values = [];
                foreach ($row_data as $value) {
                    if ($value === null) {
                        $values[] = "NULL";
                    } else {
                        $values[] = "'" . $conn->real_escape_string($value) . "'";
                    }
                }
                
                fwrite($fp, "INSERT INTO `{$table}` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n");
                $count++;
                
                // Add newline every batch_size rows for readability
                if ($count % $batch_size == 0) {
                    fwrite($fp, "\n");
                }
            }
            fwrite($fp, "\n");
        }
    }
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    
    return ['success' => true, 'tables' => count($tables)];
}

/**
 * Create company-specific backup with ALL data
 */
function createCompanyBackup($conn, $filepath, $company_id, $company_name, $fin_year_id = null) {
    $fp = fopen($filepath, 'w');
    
    if (!$fp) {
        return ['success' => false, 'error' => 'Cannot create backup file'];
    }
    
    // Write header
    fwrite($fp, "-- WineSoft Company Backup\n");
    fwrite($fp, "-- ====================================================\n");
    fwrite($fp, "-- Company: {$company_name}\n");
    fwrite($fp, "-- CompID: {$company_id}\n");
    if ($fin_year_id) {
        fwrite($fp, "-- Financial Year ID: {$fin_year_id}\n");
    }
    fwrite($fp, "-- Backup Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "-- ====================================================\n");
    fwrite($fp, "-- This backup contains ALL data for: {$company_name}\n");
    fwrite($fp, "-- When restored, it will NOT affect other companies\n");
    fwrite($fp, "-- Uses INSERT IGNORE for safe restoration\n");
    fwrite($fp, "-- ====================================================\n\n");
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    // Tables to backup with company data - INCLUDING ALL MASTER DATA
    // Format: 'table_name' => ['company_col' => 'column_name', 'where' => 'WHERE clause']
    $tables_config = [
        // Transaction tables
        'tblsaleheader' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$company_id}"],
        'tblsaledetails' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$company_id}"],
        'tblpurchases' => ['company_col' => 'CompID', 'where' => "CompID = {$company_id}"],
        'tblpurchasedetails' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID IN (SELECT CompID FROM tblpurchases WHERE CompID = {$company_id})"],
        'tblexpenses' => ['company_col' => 'comp_id', 'where' => "comp_id = {$company_id}"],
        'tbl_cash_memo_prints' => ['company_col' => 'comp_id', 'where' => "comp_id = {$company_id}"],
        'tblcustomersales' => ['company_col' => 'CompID', 'where' => "CompID = {$company_id}"],
        'tbl_pending_sales' => ['company_col' => 'comp_id', 'where' => "comp_id = {$company_id}"],
        'tblstock' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$company_id}"],
        'tbldailystock' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$company_id}"],
        
        // Financial tables
        'tblvoucher' => ['company_col' => 'company_id', 'where' => "company_id = {$company_id}"],
        'tblvoucher_details' => ['company_col' => 'company_id', 'where' => "company_id = {$company_id}"],
        
        // Opening balance tables
        'tblopeningbalance' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$company_id}"],
        'tblopeningbalancedetails' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$company_id}"],
        
        // Customer related
        'tblcustomerprices' => ['company_col' => 'CompID', 'where' => "CompID = {$company_id}", 'custom_query' => true],
    ];
    
    foreach ($tables_config as $table => $config) {
        // Skip if table doesn't exist
        $table_check = $conn->query("SHOW TABLES LIKE '{$table}'");
        if (!$table_check || $table_check->num_rows == 0) {
            continue;
        }
        
        // Get table structure
        $create_result = $conn->query("SHOW CREATE TABLE `{$table}`");
        if (!$create_result) continue;
        
        $create_row = $create_result->fetch_assoc();
        
        fwrite($fp, "-- Table: {$table}\n");
        fwrite($fp, "DROP TABLE IF EXISTS `{$table}_backup_{$company_id}`;\n");
        fwrite($fp, $create_row['Create Table'] . ";\n\n");
        
        // Handle custom queries for customer prices
        if (isset($config['custom_query']) && $config['custom_query']) {
            if ($table === 'tblcustomerprices') {
                // Get customer prices via ledger join
                $data_result = $conn->query("SELECT cp.* FROM tblcustomerprices cp 
                    INNER JOIN tbllheads l ON cp.LCODE = l.LCODE 
                    WHERE l.CompID = {$company_id}");
            } else {
                $data_result = $conn->query("SELECT * FROM `{$table}` WHERE " . $config['where']);
            }
        } else {
            $data_result = $conn->query("SELECT * FROM `{$table}` WHERE " . $config['where']);
        }
        
        if ($data_result && $data_result->num_rows > 0) {
            $columns = array_keys($data_result->fetch_assoc());
            $data_result->data_seek(0);
            
            $count = 0;
            while ($row_data = $data_result->fetch_assoc()) {
                $values = [];
                foreach ($row_data as $value) {
                    if ($value === null) {
                        $values[] = "NULL";
                    } else {
                        $values[] = "'" . $conn->real_escape_string($value) . "'";
                    }
                }
                
                // Use INSERT IGNORE for safe restoration
                fwrite($fp, "INSERT IGNORE INTO `{$table}` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n");
                $count++;
                
                // Add newline every 100 rows for readability
                if ($count % 100 == 0) {
                    fwrite($fp, "\n");
                }
            }
            fwrite($fp, "\n");
        }
    }
    
    // Backup company-specific items (items created by this company)
    $items_table_check = $conn->query("SHOW TABLES LIKE 'tblitems'");
    if ($items_table_check && $items_table_check->num_rows > 0) {
        fwrite($fp, "-- Table: tblitems (company specific)\n");
        
        $create_result = $conn->query("SHOW CREATE TABLE `tblitems`");
        if ($create_result) {
            $create_row = $create_result->fetch_assoc();
            fwrite($fp, "DROP TABLE IF EXISTS `tblitems_backup_{$company_id}`;\n");
            fwrite($fp, $create_row['Create Table'] . ";\n\n");
            
            // Check if items have company_id column
            $item_company_check = $conn->query("SHOW COLUMNS FROM tblitems LIKE '%COMP%'");
            if ($item_company_check && $item_company_check->num_rows > 0) {
                $data_result = $conn->query("SELECT * FROM tblitems WHERE CompID = {$company_id}");
                if ($data_result && $data_result->num_rows > 0) {
                    $columns = array_keys($data_result->fetch_assoc());
                    $data_result->data_seek(0);
                    
                    while ($row_data = $data_result->fetch_assoc()) {
                        $values = [];
                        foreach ($row_data as $value) {
                            $values[] = $value === null ? "NULL" : "'" . $conn->real_escape_string($value) . "'";
                        }
                        fwrite($fp, "INSERT IGNORE INTO tblitems (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n");
                    }
                }
            } else {
                // If no company column, backup all items (common setup)
                $data_result = $conn->query("SELECT * FROM tblitems");
                if ($data_result && $data_result->num_rows > 0) {
                    $columns = array_keys($data_result->fetch_assoc());
                    $data_result->data_seek(0);
                    
                    while ($row_data = $data_result->fetch_assoc()) {
                        $values = [];
                        foreach ($row_data as $value) {
                            $values[] = $value === null ? "NULL" : "'" . $conn->real_escape_string($value) . "'";
                        }
                        fwrite($fp, "INSERT IGNORE INTO tblitems (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n");
                    }
                }
            }
            fwrite($fp, "\n");
        }
    }
    
    // Backup company ledger (party accounts)
    $ledger_table_check = $conn->query("SHOW TABLES LIKE 'tbllheads'");
    if ($ledger_table_check && $ledger_table_check->num_rows > 0) {
        fwrite($fp, "-- Table: tbllheads (Ledger/Party accounts)\n");
        
        $create_result = $conn->query("SHOW CREATE TABLE `tbllheads`");
        if ($create_result) {
            $create_row = $create_result->fetch_assoc();
            fwrite($fp, "DROP TABLE IF EXISTS `tbllheads_backup_{$company_id}`;\n");
            fwrite($fp, $create_row['Create Table'] . ";\n\n");
            
            $data_result = $conn->query("SELECT * FROM tbllheads WHERE CompID = {$company_id}");
            if ($data_result && $data_result->num_rows > 0) {
                $columns = array_keys($data_result->fetch_assoc());
                $data_result->data_seek(0);
                
                while ($row_data = $data_result->fetch_assoc()) {
                    $values = [];
                    foreach ($row_data as $value) {
                        $values[] = $value === null ? "NULL" : "'" . $conn->real_escape_string($value) . "'";
                    }
                    fwrite($fp, "INSERT IGNORE INTO tbllheads (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n");
                }
            }
            fwrite($fp, "\n");
        }
    }
    
    // Backup company information
    $company_table_check = $conn->query("SHOW TABLES LIKE 'tblcompany'");
    if ($company_table_check && $company_table_check->num_rows > 0) {
        fwrite($fp, "-- Table: tblcompany (Company Info)\n");
        
        $create_result = $conn->query("SHOW CREATE TABLE `tblcompany`");
        if ($create_result) {
            $create_row = $create_result->fetch_assoc();
            fwrite($fp, "DROP TABLE IF EXISTS `tblcompany_backup_{$company_id}`;\n");
            fwrite($fp, $create_row['Create Table'] . ";\n\n");
            
            $data_result = $conn->query("SELECT * FROM tblcompany WHERE CompID = {$company_id}");
            if ($data_result && $data_result->num_rows > 0) {
                $columns = array_keys($data_result->fetch_assoc());
                $data_result->data_seek(0);
                
                while ($row_data = $data_result->fetch_assoc()) {
                    $values = [];
                    foreach ($row_data as $value) {
                        $values[] = $value === null ? "NULL" : "'" . $conn->real_escape_string($value) . "'";
                    }
                    fwrite($fp, "INSERT IGNORE INTO tblcompany (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n");
                }
            }
            fwrite($fp, "\n");
        }
    }
    
    // Add backup marker table
    fwrite($fp, "-- Backup marker table\n");
    fwrite($fp, "CREATE TABLE IF NOT EXISTS `tblcompany_backup_marker` (\n");
    fwrite($fp, "  `id` INT AUTO_INCREMENT PRIMARY KEY,\n");
    fwrite($fp, "  `comp_id` INT NOT NULL,\n");
    fwrite($fp, "  `company_name` VARCHAR(255),\n");
    fwrite($fp, "  `backup_date` DATETIME,\n");
    fwrite($fp, "  `fin_year_id` INT\n");
    fwrite($fp, ");\n");
    fwrite($fp, "INSERT IGNORE INTO tblcompany_backup_marker (comp_id, company_name, backup_date, fin_year_id) VALUES ({$company_id}, '" . $conn->real_escape_string($company_name) . "', NOW(), " . ($fin_year_id ? $fin_year_id : "NULL") . ");\n\n");
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    
    return ['success' => true];
}

// Store session data before destroying
$company_name = $_SESSION['COMP_NAME'] ?? '';
$username = $_SESSION['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logout - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logout-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            padding: 40px;
            max-width: 450px;
            width: 90%;
            text-align: center;
        }
        .logout-icon {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 20px;
        }
        .company-badge {
            background: #f8f9fa;
            padding: 10px 20px;
            border-radius: 20px;
            display: inline-block;
            margin: 10px 0;
        }
        .btn-logout {
            padding: 15px 50px;
            font-size: 1.2rem;
            border-radius: 30px;
            margin: 0 10px;
        }
        .loading-spinner {
            display: none;
        }
        .btn-yes {
            background: #28a745;
            color: white;
            border: none;
        }
        .btn-yes:hover {
            background: #218838;
            color: white;
        }
        .btn-no {
            background: #6c757d;
            color: white;
            border: none;
        }
        .btn-no:hover {
            background: #5a6268;
            color: white;
        }
    </style>
</head>
<body>
    <div class="logout-card">
        <i class="fas fa-sign-out-alt logout-icon"></i>
        <h2>Ready to Logout?</h2>
        <p class="text-muted">You are logged in as <strong><?= htmlspecialchars($username) ?></strong></p>
        
        <?php if (!empty($company_name)): ?>
        <div class="company-badge">
            <i class="fas fa-building me-2"></i>
            <?= htmlspecialchars($company_name) ?>
        </div>
        <?php endif; ?>
        
        <!-- Simple Yes/No Question -->
        <div class="mt-4" id="backupQuestion">
            <h4 class="mb-4"><i class="fas fa-database me-2"></i>Create Backup?</h4>
            <p class="text-muted">This will create both full database backup and company backup</p>
            
            <div class="d-flex justify-content-center gap-3">
                <button type="button" class="btn btn-logout btn-yes" id="yesBtn">
                    <i class="fas fa-check me-2"></i>Yes
                </button>
                <button type="button" class="btn btn-logout btn-no" id="noBtn">
                    <i class="fas fa-times me-2"></i>No
                </button>
            </div>
        </div>
        
        <!-- Loading Spinner -->
        <div class="loading-spinner" id="loadingSpinner">
            <div class="spinner-border text-primary" role="status" style="width: 3rem; height: 3rem;">
                <span class="visually-hidden">Creating backup...</span>
            </div>
            <p class="mt-3">Creating backups...</p>
            <p class="text-muted small">Full database backup & Company backup</p>
        </div>
        
        <!-- Result Message -->
        <div id="resultMessage"></div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    $(document).ready(function() {
        // Handle Yes button click
        $('#yesBtn').on('click', function() {
            $('#backupQuestion').hide();
            $('#loadingSpinner').show();
            
            // Create both backups via AJAX
            $.ajax({
                url: 'logout.php',
                type: 'POST',
                data: {
                    action: 'backup'
                },
                success: function(response) {
                    $('#loadingSpinner').hide();
                    
                    if (response.success) {
                        var message = '<div class="alert alert-success">';
                        message += '<i class="fas fa-check-circle"></i> <strong>Backups Created Successfully!</strong><br><br>';
                        
                        // Full backup download
                        if (response.files && response.files.full && !response.files.full.error) {
                            var fullSize = (response.files.full.size / 1024).toFixed(2);
                            if (fullSize < 1024) {
                                message += '<a href="' + response.files.full.path + '" class="btn btn-success btn-sm mb-2" download>' +
                                    '<i class="fas fa-database"></i> Download Full Database Backup (' + fullSize + ' KB)</a><br>';
                            } else {
                                var fullSizeMB = (response.files.full.size / (1024 * 1024)).toFixed(2);
                                message += '<a href="' + response.files.full.path + '" class="btn btn-success btn-sm mb-2" download>' +
                                    '<i class="fas fa-database"></i> Download Full Database Backup (' + fullSizeMB + ' MB)</a><br>';
                            }
                        } else if (response.files && response.files.full && response.files.full.error) {
                            message += '<div class="text-danger"><i class="fas fa-times"></i> Full backup failed: ' + response.files.full.error + '</div><br>';
                        }
                        
                        // Company backup download
                        if (response.files && response.files.company && !response.files.company.error) {
                            var companySize = (response.files.company.size / 1024).toFixed(2);
                            if (companySize < 1024) {
                                message += '<a href="' + response.files.company.path + '" class="btn btn-primary btn-sm" download>' +
                                    '<i class="fas fa-building"></i> Download Company Backup (' + companySize + ' KB)</a>';
                            } else {
                                var companySizeMB = (response.files.company.size / (1024 * 1024)).toFixed(2);
                                message += '<a href="' + response.files.company.path + '" class="btn btn-primary btn-sm" download>' +
                                    '<i class="fas fa-building"></i> Download Company Backup (' + companySizeMB + ' MB)</a>';
                            }
                        } else if (response.files && response.files.company && response.files.company.error) {
                            message += '<div class="text-warning"><i class="fas fa-exclamation"></i> Company backup failed: ' + response.files.company.error + '</div>';
                        }
                        
                        message += '</div>';
                        $('#resultMessage').html(message);
                    } else {
                        var errorMsg = response.message || 'Unknown error';
                        $('#resultMessage').html(
                            '<div class="alert alert-warning">' +
                            '<i class="fas fa-exclamation-triangle"></i> Backup created with warnings:<br>' + errorMsg +
                            '</div>'
                        );
                    }
                    
                    // After 3 seconds, redirect to login
                    setTimeout(function() {
                        window.location.href = 'logout.php?confirmed=1';
                    }, 5000);
                },
                error: function(xhr, status, error) {
                    $('#loadingSpinner').hide();
                    $('#resultMessage').html(
                        '<div class="alert alert-danger">' +
                        '<i class="fas fa-times-circle"></i> Error: ' + error +
                        '</div>'
                    );
                    
                    // Still allow logout after error
                    setTimeout(function() {
                        window.location.href = 'logout.php?confirmed=1';
                    }, 2000);
                }
            });
        });
        
        // Handle No button click - just logout
        $('#noBtn').on('click', function() {
            window.location.href = 'logout.php?confirmed=1';
        });
    });
    </script>
</body>
</html>

<?php
// If confirmed logout parameter is set, perform the actual logout
if (isset($_GET['confirmed']) && $_GET['confirmed'] == 1) {
    // Unset all session variables
    $_SESSION = array();
    
    // If it's desired to kill the session, also delete the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Finally, destroy the session
    session_destroy();
    
    // Use JavaScript redirect to avoid header issues
    echo '<script>window.location.href = "index.php";</script>';
    exit;
}
?>
