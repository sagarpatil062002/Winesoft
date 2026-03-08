<?php
session_start();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Check if user is admin (backup is an admin function)
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
    die("Access denied. Only administrators can create backups.");
}

include_once "../config/db.php";

// Database credentials from config
$host = "localhost";
$user = "root";
$pass = "";
$dbname = "winesoft";

/**
 * Get company information
 */
function getCompanyInfo($conn, $comp_id) {
    $stmt = $conn->prepare("SELECT * FROM tblcompany WHERE CompID = ?");
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $company = $result->fetch_assoc();
    $stmt->close();
    return $company;
}

/**
 * Create full database backup using PHP (not mysqldump - for Windows compatibility)
 */
function createFullBackup($host, $user, $pass, $dbname, $backup_dir) {
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "winesoft_full_backup_{$timestamp}.sql";
    $filepath = $backup_dir . '/' . $filename;
    
    // Connect to database
    $conn = new mysqli($host, $user, $pass, $dbname);
    
    if ($conn->connect_error) {
        return [
            'success' => false,
            'error' => 'Database connection failed: ' . $conn->connect_error
        ];
    }
    
    $fp = fopen($filepath, 'w');
    
    if (!$fp) {
        $conn->close();
        return [
            'success' => false,
            'error' => 'Cannot create backup file'
        ];
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
        $conn->close();
        return [
            'success' => false,
            'error' => 'Cannot get tables list'
        ];
    }
    
    $tables = [];
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    
    $table_count = 0;
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
                
                fwrite($fp, "INSERT INTO `{$table}` (`" . implode("`, `", $columns) . ") VALUES (" . implode(", ", $values) . ");\n");
                $count++;
                
                // Add newline every batch_size rows for readability
                if ($count % $batch_size == 0) {
                    fwrite($fp, "\n");
                }
            }
            fwrite($fp, "\n");
            $table_count++;
        }
    }
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    $conn->close();
    
    if (file_exists($filepath) && filesize($filepath) > 0) {
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'type' => 'full',
            'tables' => $table_count
        ];
    }
    
    return [
        'success' => false,
        'error' => 'Failed to create full database backup'
    ];
}

/**
 * Create company-specific backup that can be safely restored without affecting other companies
 * Uses INSERT IGNORE to prevent overwriting existing data from other companies
 */
function createCompanyBackup($conn, $host, $user, $pass, $dbname, $backup_dir, $comp_id, $fin_year_id) {
    $company = getCompanyInfo($conn, $comp_id);
    $company_name = preg_replace('/[^a-zA-Z0-9]/', '_', $company['COMP_NAME']);
    
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "winesoft_{$company_name}_backup_{$timestamp}.sql";
    $filepath = $backup_dir . '/' . $filename;
    
    $fp = fopen($filepath, 'w');
    
    if (!$fp) {
        return [
            'success' => false,
            'error' => 'Cannot create backup file'
        ];
    }
    
    // Write header with important information
    fwrite($fp, "-- WineSoft Company Backup\n");
    fwrite($fp, "-- ====================================================\n");
    fwrite($fp, "-- Company: " . $company['COMP_NAME'] . "\n");
    fwrite($fp, "-- CompID: {$comp_id}\n");
    fwrite($fp, "-- Financial Year ID: {$fin_year_id}\n");
    fwrite($fp, "-- Backup Date: " . date('Y-m-d H:i:s') . "\n");
    fwrite($fp, "-- ====================================================\n");
    fwrite($fp, "-- IMPORTANT RESTORATION INSTRUCTIONS:\n");
    fwrite($fp, "-- This backup contains ALL data for company: " . $company['COMP_NAME'] . "\n");
    fwrite($fp, "-- When restored, it will only INSERT or UPDATE records for this company\n");
    fwrite($fp, "-- Existing data from OTHER companies will NOT be affected\n");
    fwrite($fp, "-- The backup uses INSERT IGNORE for safe restoration\n");
    fwrite($fp, "-- ====================================================\n\n");
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    
    // Transaction tables
    $tables_config = [
        'tblsaleheader' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$comp_id}"],
        'tblsaledetails' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$comp_id}"],
        'tblpurchases' => ['company_col' => 'CompID', 'where' => "CompID = {$comp_id}"],
        'tblpurchasedetails' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID IN (SELECT CompID FROM tblpurchases WHERE CompID = {$comp_id})"],
        'tblexpenses' => ['company_col' => 'comp_id', 'where' => "comp_id = {$comp_id}"],
        'tbl_cash_memo_prints' => ['company_col' => 'comp_id', 'where' => "comp_id = {$comp_id}"],
        'tblcustomersales' => ['company_col' => 'CompID', 'where' => "CompID = {$comp_id}"],
        'tbl_pending_sales' => ['company_col' => 'comp_id', 'where' => "comp_id = {$comp_id}"],
        'tblstock' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$comp_id}"],
        'tbldailystock' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$comp_id}"],
        'tblvoucher' => ['company_col' => 'company_id', 'where' => "company_id = {$comp_id}"],
        'tblvoucher_details' => ['company_col' => 'company_id', 'where' => "company_id = {$comp_id}"],
        'tblopeningbalance' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$comp_id}"],
        'tblopeningbalancedetails' => ['company_col' => 'COMP_ID', 'where' => "COMP_ID = {$comp_id}"],
    ];
    
    // Process company-specific tables
    foreach ($tables_config as $table => $config) {
        // Check if table exists
        $table_check = $conn->query("SHOW TABLES LIKE '{$table}'");
        if (!$table_check || $table_check->num_rows == 0) {
            continue;
        }
        
        // Get table structure
        $result = $conn->query("SHOW CREATE TABLE `{$table}`");
        if (!$result) continue;
        
        $row = $result->fetch_assoc();
        $create_table = $row['Create Table'];
        
        fwrite($fp, "-- Table: {$table}\n");
        
        // For company-specific tables, we'll use backup table to preserve existing data
        fwrite($fp, "DROP TABLE IF EXISTS `{$table}_backup_{$comp_id}`;\n");
        fwrite($fp, "{$create_table};\n\n");
        
        // Get data for this company
        $data_query = "SELECT * FROM `{$table}` WHERE " . $config['where'];
        $data_result = $conn->query($data_query);
        
        if ($data_result && $data_result->num_rows > 0) {
            // Get column names
            $columns = array_keys($data_result->fetch_assoc());
            $data_result->data_seek(0);
            
            // INSERT IGNORE to add new records without affecting existing
            $count = 0;
            while ($row_data = $data_result->fetch_assoc()) {
                $values = [];
                foreach ($row_data as $value) {
                    $values[] = $value === null ? "NULL" : "'" . $conn->real_escape_string($value) . "'";
                }
                fwrite($fp, "INSERT IGNORE INTO `{$table}` (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n");
                $count++;
                
                if ($count % 100 == 0) {
                    fwrite($fp, "\n");
                }
            }
            fwrite($fp, "\n");
        }
    }
    
    // Handle customer prices (join with ledger)
    fwrite($fp, "-- Table: tblcustomerprices (via ledger)\n");
    $prices_result = $conn->query("SELECT cp.* FROM tblcustomerprices cp 
        INNER JOIN tbllheads l ON cp.LCODE = l.LCODE 
        WHERE l.CompID = {$comp_id}");
    
    if ($prices_result && $prices_result->num_rows > 0) {
        $columns = array_keys($prices_result->fetch_assoc());
        $prices_result->data_seek(0);
        
        while ($row_data = $prices_result->fetch_assoc()) {
            $values = [];
            foreach ($row_data as $value) {
                $values[] = $value === null ? "NULL" : "'" . $conn->real_escape_string($value) . "'";
            }
            fwrite($fp, "INSERT IGNORE INTO tblcustomerprices (`" . implode("`, `", $columns) . "`) VALUES (" . implode(", ", $values) . ");\n");
        }
    }
    fwrite($fp, "\n");
    
    // Backup company-specific items
    $items_table_check = $conn->query("SHOW TABLES LIKE 'tblitems'");
    if ($items_table_check && $items_table_check->num_rows > 0) {
        fwrite($fp, "-- Table: tblitems (company specific)\n");
        
        $create_result = $conn->query("SHOW CREATE TABLE `tblitems`");
        if ($create_result) {
            $create_row = $create_result->fetch_assoc();
            fwrite($fp, "DROP TABLE IF EXISTS `tblitems_backup_{$comp_id}`;\n");
            fwrite($fp, $create_row['Create Table'] . ";\n\n");
            
            // Check if items have company_id column
            $item_company_check = $conn->query("SHOW COLUMNS FROM tblitems LIKE '%COMP%'");
            if ($item_company_check && $item_company_check->num_rows > 0) {
                $data_result = $conn->query("SELECT * FROM tblitems WHERE CompID = {$comp_id}");
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
                // Backup all items
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
            fwrite($fp, "DROP TABLE IF EXISTS `tbllheads_backup_{$comp_id}`;\n");
            fwrite($fp, $create_row['Create Table'] . ";\n\n");
            
            $data_result = $conn->query("SELECT * FROM tbllheads WHERE CompID = {$comp_id}");
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
    
    // Add backup marker table to track company backups
    fwrite($fp, "-- Backup marker table\n");
    fwrite($fp, "CREATE TABLE IF NOT EXISTS `tblcompany_backup_marker` (\n");
    fwrite($fp, "  `id` INT AUTO_INCREMENT PRIMARY KEY,\n");
    fwrite($fp, "  `comp_id` INT NOT NULL,\n");
    fwrite($fp, "  `company_name` VARCHAR(255),\n");
    fwrite($fp, "  `backup_date` DATETIME,\n");
    fwrite($fp, "  `fin_year_id` INT\n");
    fwrite($fp, ");\n");
    fwrite($fp, "INSERT IGNORE INTO tblcompany_backup_marker (comp_id, company_name, backup_date, fin_year_id) VALUES ({$comp_id}, '" . $conn->real_escape_string($company['COMP_NAME']) . "', NOW(), {$fin_year_id});\n\n");
    
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    
    if (file_exists($filepath) && filesize($filepath) > 0) {
        return [
            'success' => true,
            'filename' => $filename,
            'filepath' => $filepath,
            'size' => filesize($filepath),
            'type' => 'company',
            'company_name' => $company['COMP_NAME']
        ];
    }
    
    return [
        'success' => false,
        'error' => 'Failed to create company backup'
    ];
}

// Handle backup request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_backup'])) {
    $backup_type = $_POST['backup_type'] ?? 'full';
    $company_id = isset($_SESSION['CompID']) ? $_SESSION['CompID'] : null;
    $fin_year_id = isset($_SESSION['FIN_YEAR_ID']) ? $_SESSION['FIN_YEAR_ID'] : null;
    
    // Create backup directory if not exists
    $backup_dir = __DIR__ . '/backups';
    if (!is_dir($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }
    
    $result = null;
    
    if ($backup_type === 'full') {
        // Create full database backup using PHP
        $result = createFullBackup($host, $user, $pass, $dbname, $backup_dir);
    } elseif ($backup_type === 'company' && $company_id) {
        // Create company-specific backup
        $result = createCompanyBackup($conn, $host, $user, $pass, $dbname, $backup_dir, $company_id, $fin_year_id);
    } else {
        $result = [
            'success' => false,
            'error' => 'Invalid backup type or company not selected'
        ];
    }
    
    // Return JSON response
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// If not AJAX request, show the backup form
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
    <style>
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            background-color: #f8f9fa;
        }
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .content-area {
            flex: 1;
            padding: 20px;
            background-color: white;
            margin: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .backup-option {
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .backup-option:hover {
            border-color: #4e73df;
            background-color: #f8f9ff;
        }
        .backup-option.selected {
            border-color: #4e73df;
            background-color: #e7f1ff;
        }
        .backup-option input[type="radio"] {
            display: none;
        }
        .backup-icon {
            font-size: 2.5rem;
            color: #4e73df;
            margin-bottom: 10px;
        }
        .loading-spinner {
            display: none;
        }
        .info-box {
            background: #e7f1ff;
            border-left: 4px solid #4e73df;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>

    <div class="main-content">
        <div class="content-area">
            <h3 class="mb-4"><i class="fas fa-database me-2"></i>Database Backup</h3>
            
            <div class="info-box">
                <h5><i class="fas fa-info-circle"></i> About Backups</h5>
                <ul class="mb-0">
                    <li><strong>Full Database Backup:</strong> Creates a complete backup of all companies and all data in the database</li>
                    <li><strong>Company Backup:</strong> Creates a backup of only the current company's data. When restored, it will only update/insert records for this company without affecting other companies.</li>
                </ul>
            </div>

            <!-- Current Company Info -->
            <?php if (isset($_SESSION['CompID'])): ?>
                <?php 
                    $current_company = getCompanyInfo($conn, $_SESSION['CompID']);
                ?>
                <div class="alert alert-secondary mb-4">
                    <strong><i class="fas fa-building"></i> Current Company:</strong> <?= htmlspecialchars($current_company['COMP_NAME'] ?? 'N/A') ?>
                </div>
            <?php endif; ?>

            <!-- Backup Options -->
            <form id="backupForm">
                <div class="row">
                    <div class="col-md-6">
                        <!-- Full Database Backup Option -->
                        <label class="backup-option" id="fullBackupOption">
                            <input type="radio" name="backup_type" value="full" checked>
                            <div class="text-center">
                                <i class="fas fa-database backup-icon"></i>
                                <h5>Full Database Backup</h5>
                                <p class="text-muted mb-0">
                                    Backup all companies and all data in the database.<br>
                                    <small>Recommended for complete system backup</small>
                                </p>
                            </div>
                        </label>
                    </div>
                    <div class="col-md-6">
                        <!-- Company-Specific Backup Option -->
                        <label class="backup-option" id="companyBackupOption">
                            <input type="radio" name="backup_type" value="company">
                            <div class="text-center">
                                <i class="fas fa-building backup-icon"></i>
                                <h5>Company Backup</h5>
                                <p class="text-muted mb-0">
                                    Backup only current company's data.<br>
                                    <small>Safe to restore - won't affect other companies</small>
                                </p>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-primary btn-lg" id="createBackupBtn">
                        <i class="fas fa-download me-2"></i>Create Backup
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg" onclick="window.location.href='dashboard.php'">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </button>
                </div>
            </form>

            <!-- Loading Spinner -->
            <div class="text-center mt-4 loading-spinner" id="loadingSpinner">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Creating backup...</span>
                </div>
                <p class="mt-2">Creating backup, please wait...</p>
            </div>

            <!-- Result Message -->
            <div class="mt-4" id="resultMessage"></div>

            <!-- Recent Backups -->
            <div class="mt-5">
                <h4><i class="fas fa-history me-2"></i>Recent Backups</h4>
                <?php
                $backup_dir = __DIR__ . '/backups';
                $backups = [];
                if (is_dir($backup_dir)) {
                    $files = scandir($backup_dir);
                    foreach ($files as $file) {
                        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                            $filepath = $backup_dir . '/' . $file;
                            $backups[] = [
                                'name' => $file,
                                'size' => filesize($filepath),
                                'date' => filemtime($filepath)
                            ];
                        }
                    }
                    // Sort by date, newest first
                    usort($backups, function($a, $b) {
                        return $b['date'] - $a['date'];
                    });
                }
                ?>
                
                <?php if (!empty($backups)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Backup File</th>
                                    <th>Size</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($backups, 0, 10) as $backup): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-file-sql text-success me-2"></i>
                                            <?= htmlspecialchars($backup['name']) ?>
                                        </td>
                                        <td><?= number_format($backup['size'] / 1024, 2) ?> KB</td>
                                        <td><?= date('d-m-Y H:i:s', $backup['date']) ?></td>
                                        <td>
                                            <a href="backups/<?= urlencode($backup['name']) ?>" 
                                               class="btn btn-sm btn-success" 
                                               download>
                                                <i class="fas fa-download"></i> Download
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-folder-open"></i> No backups found. Create your first backup!
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Handle option selection styling
    $('.backup-option').on('click', function() {
        $('.backup-option').removeClass('selected');
        $(this).addClass('selected');
    });
    
    // Set initial selection
    $('#fullBackupOption').addClass('selected');
    
    // Handle radio button changes
    $('input[name="backup_type"]').on('change', function() {
        if ($(this).val() === 'full') {
            $('#fullBackupOption').addClass('selected');
            $('#companyBackupOption').removeClass('selected');
        } else {
            $('#companyBackupOption').addClass('selected');
            $('#fullBackupOption').removeClass('selected');
        }
    });
    
    // Handle form submission
    $('#backupForm').on('submit', function(e) {
        e.preventDefault();
        
        var backupType = $('input[name="backup_type"]:checked').val();
        
        $('#loadingSpinner').show();
        $('#resultMessage').html('');
        $('#createBackupBtn').prop('disabled', true);
        
        $.ajax({
            url: 'backup.php',
            type: 'POST',
            data: {
                create_backup: true,
                backup_type: backupType
            },
            success: function(response) {
                $('#loadingSpinner').hide();
                $('#createBackupBtn').prop('disabled', false);
                
                if (response.success) {
                    var sizeKB = (response.size / 1024).toFixed(2);
                    var sizeDisplay = '';
                    
                    if (sizeKB < 1024) {
                        sizeDisplay = sizeKB + ' KB';
                    } else {
                        sizeDisplay = (sizeKB / 1024).toFixed(2) + ' MB';
                    }
                    
                    var alertClass = 'success';
                    var icon = 'check-circle';
                    var message = '<strong>Backup Created Successfully!</strong><br>';
                    message += 'File: ' + response.filename + '<br>';
                    message += 'Size: ' + sizeDisplay;
                    
                    if (response.tables) {
                        message += '<br>Tables: ' + response.tables;
                    }
                    
                    if (response.type === 'company') {
                        message += '<br>Company: ' + response.company_name;
                        message += '<br><small class="text-muted">This backup can be safely restored without affecting other companies</small>';
                    }
                    
                    message += '<br><br><a href="backups/' + encodeURIComponent(response.filename) + '" class="btn btn-success btn-sm" download><i class="fas fa-download"></i> Download Backup</a>';
                    message += ' <a href="backup.php" class="btn btn-primary btn-sm"><i class="fas fa-refresh"></i> Refresh Page</a>';
                    
                    $('#resultMessage').html('<div class="alert alert-' + alertClass + '">' + message + '</div>');
                } else {
                    $('#resultMessage').html('<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Error: ' + (response.error || 'Unknown error occurred') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                $('#loadingSpinner').hide();
                $('#createBackupBtn').prop('disabled', false);
                $('#resultMessage').html('<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Error: ' + error + '</div>');
            }
        });
    });
});
</script>
</body>
</html>
