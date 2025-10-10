<?php
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

// Get current company details
$company_query = "SELECT CompID, Comp_Name FROM tblcompany WHERE CompID = ?";
$company_stmt = $conn->prepare($company_query);
$company_stmt->bind_param("i", $comp_id);
$company_stmt->execute();
$company_result = $company_stmt->get_result();
$current_company = $company_result->fetch_assoc();
$company_stmt->close();

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
    $archive_table = "tbldailystock_archive_$comp_id";
    
    // Check if archive table exists
    $check_archive_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                           WHERE table_schema = DATABASE() 
                           AND table_name = '$archive_table'";
    $check_result = $conn->query($check_archive_query);
    $archive_exists = $check_result->fetch_assoc()['count'] > 0;
    
    if (!$archive_exists) {
        // Create archive table
        $create_archive_query = "CREATE TABLE $archive_table LIKE tbldailystock_$comp_id";
        $conn->query($create_archive_query);
    }
    
    // Copy data to archive
    $copy_data_query = "INSERT INTO $archive_table SELECT * FROM tbldailystock_$comp_id WHERE STK_MONTH = ?";
    $copy_stmt = $conn->prepare($copy_data_query);
    $copy_stmt->bind_param("s", $month);
    $copy_stmt->execute();
    $copy_stmt->close();
    
    // Delete archived data from main table
    $delete_query = "DELETE FROM tbldailystock_$comp_id WHERE STK_MONTH = ?";
    $delete_stmt = $conn->prepare($delete_query);
    $delete_stmt->bind_param("s", $month);
    $delete_stmt->execute();
    $delete_stmt->close();
}

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

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    
    // Fetch items from tblitemmaster with CURRENT_STOCK for the current company only - FILTERED BY LICENSE
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT 
                    im.CODE, 
                    im.DETAILS, 
                    im.DETAILS2,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
                  WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders)";
        $params = array_merge([$mode], $allowed_classes);
        $types = "s" . str_repeat('s', count($allowed_classes));
    } else {
        // If no classes allowed, return empty result
        $query = "SELECT 
                    im.CODE, 
                    im.DETAILS, 
                    im.DETAILS2,
                    COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
                  FROM tblitemmaster im
                  LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
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

    $query .= " ORDER BY im.DETAILS ASC";

    $stmt = $conn->prepare($query);
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if ($exportType === 'csv') {
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=opening_balance_export_' . $mode . '_' . date('Y-m-d') . '.csv');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        
        // Write header row
        fputcsv($output, ['Item_Code', 'Item_Name', 'Category', 'Current_Stock']);
        
        // Write data rows
        foreach ($items as $item) {
            fputcsv($output, [
                $item['CODE'],
                $item['DETAILS'],
                $item['DETAILS2'],
                $item['CURRENT_STOCK']
            ]);
        }
        
        fclose($output);
        exit;
    }
}

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
    $csv_file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($csv_file, "r");
    
    // Skip header row
    fgetcsv($handle);
    
    $imported_count = 0;
    $error_messages = [];
    
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($data) >= 4) {
            $code = trim($data[0]);
            $details = trim($data[1]);
            $details2 = trim($data[2]);
            $balance = intval(trim($data[3])); // Convert to integer
            
            // Validate item code exists and matches details AND check if item is allowed for company's license
            if (!empty($allowed_classes)) {
                $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
                $check_item_query = "SELECT COUNT(*) as count FROM tblitemmaster 
                                   WHERE CODE = ? AND DETAILS = ? AND DETAILS2 = ? 
                                   AND LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
                $check_item_stmt = $conn->prepare($check_item_query);
                
                $params = array_merge([$code, $details, $details2, $mode], $allowed_classes);
                $types = "ssss" . str_repeat('s', count($allowed_classes));
                $check_item_stmt->bind_param($types, ...$params);
            } else {
                // If no classes allowed, item validation will fail
                $check_item_query = "SELECT COUNT(*) as count FROM tblitemmaster 
                                   WHERE CODE = ? AND DETAILS = ? AND DETAILS2 = ? 
                                   AND LIQ_FLAG = ? AND 1 = 0";
                $check_item_stmt = $conn->prepare($check_item_query);
                $check_item_stmt->bind_param("ssss", $code, $details, $details2, $mode);
            }
            
            $check_item_stmt->execute();
            $item_result = $check_item_stmt->get_result();
            $item_exists_and_allowed = $item_result->fetch_assoc()['count'] > 0;
            $check_item_stmt->close();
            
            if ($item_exists_and_allowed) {
                // Check if ANY record exists for this item (regardless of financial year)
                $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?");
                $checkStmt->bind_param("s", $code);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $exists = $checkResult->fetch_assoc()['count'] > 0;
                $checkStmt->close();
                
                if ($exists) {
                    // Update existing record - only the columns for this company
                    $updateStmt = $conn->prepare("UPDATE tblitem_stock SET OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ?, LAST_UPDATED = CURRENT_TIMESTAMP WHERE ITEM_CODE = ?");
                    $updateStmt->bind_param("iis", $balance, $balance, $code);
                    $updateStmt->execute();
                    $updateStmt->close();
                } else {
                    // Insert new record - only set columns for this company
                    $insertStmt = $conn->prepare("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id) VALUES (?, ?, ?, ?)");
                    $insertStmt->bind_param("siii", $code, $fin_year, $balance, $balance);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
                
                // Update daily stock - get yesterday's closing for today's opening
                $yesterday_closing = getYesterdayClosingStock($conn, $comp_id, $code, $mode);
                $today_opening = ($yesterday_closing > 0) ? $yesterday_closing : $balance;
                
                updateDailyStock($conn, $comp_id, $code, $mode, $today_opening, $balance);
                
                $imported_count++;
            } else {
                $error_messages[] = "Item validation failed for '$code' - '$details' - '$details2'. Item not found or not allowed for your license type.";
            }
        }
    }
    
    fclose($handle);
    
    $_SESSION['import_message'] = [
        'success' => true,
        'message' => "Successfully imported $imported_count opening balances (only items allowed for your license type were processed)",
        'errors' => $error_messages
    ];
    
    header("Location: opening_balance.php?mode=" . $mode . "&search=" . urlencode($search));
    exit;
}

// Handle template download
if (isset($_GET['download_template'])) {
    // Fetch all items from tblitemmaster for the current liquor mode - UPDATED WITH LICENSE FILTERING
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $template_query = "SELECT CODE, DETAILS, DETAILS2 FROM tblitemmaster WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders) ORDER BY DETAILS ASC";
        $template_stmt = $conn->prepare($template_query);
        $template_params = array_merge([$mode], $allowed_classes);
        $template_types = "s" . str_repeat('s', count($allowed_classes));
        $template_stmt->bind_param($template_types, ...$template_params);
    } else {
        // If no classes allowed, return empty template
        $template_query = "SELECT CODE, DETAILS, DETAILS2 FROM tblitemmaster WHERE 1 = 0";
        $template_stmt = $conn->prepare($template_query);
    }
    
    $template_stmt->execute();
    $template_result = $template_stmt->get_result();
    $template_items = $template_result->fetch_all(MYSQLI_ASSOC);
    $template_stmt->close();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=opening_balance_template_' . $mode . '.csv');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write header row
    fputcsv($output, ['Item_Code', 'Item_Name', 'Category', 'Current_Stock']);
    
    // Write data rows
    foreach ($template_items as $item) {
        fputcsv($output, [
            $item['CODE'],
            $item['DETAILS'],
            $item['DETAILS2'],
            '' // Empty current stock column for user to fill
        ]);
    }
    
    fclose($output);
    exit;
}

// Fetch items from tblitemmaster with CURRENT_STOCK for the current company only - UPDATED WITH LICENSE FILTERING
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
                COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK,
                COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK
              FROM tblitemmaster im
              LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
              WHERE im.LIQ_FLAG = ? AND im.CLASS IN ($class_placeholders)";
    $params = array_merge([$mode], $allowed_classes);
    $types = "s" . str_repeat('s', count($allowed_classes));
} else {
    // If no classes allowed, show empty result
    $query = "SELECT 
                im.CODE, 
                im.Print_Name, 
                im.DETAILS, 
                im.DETAILS2, 
                im.CLASS, 
                im.SUB_CLASS, 
                im.ITEM_GROUP,
                COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK,
                COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK
              FROM tblitemmaster im
              LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE
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

$query .= " ORDER BY im.DETAILS ASC";

$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle form submission for updating opening balances
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_balances'])) {
    if (isset($_POST['opening_stock'])) {
        foreach ($_POST['opening_stock'] as $code => $balance) {
            $balance = intval($balance); // Convert to integer
            
            // Check if record exists
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?");
            $checkStmt->bind_param("s", $code);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $exists = $checkResult->fetch_assoc()['count'] > 0;
            $checkStmt->close();
            
            if ($exists) {
                // Update existing record - update BOTH opening and current stock
                $updateStmt = $conn->prepare("UPDATE tblitem_stock SET OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ?, LAST_UPDATED = CURRENT_TIMESTAMP WHERE ITEM_CODE = ?");
                $updateStmt->bind_param("iis", $balance, $balance, $code);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record - set BOTH opening and current stock
                $insertStmt = $conn->prepare("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id) VALUES (?, ?, ?, ?)");
                $insertStmt->bind_param("siii", $code, $fin_year, $balance, $balance);
                $insertStmt->execute();
                $insertStmt->close();
            }
            
            // Update daily stock - get yesterday's closing for today's opening
            $yesterday_closing = getYesterdayClosingStock($conn, $comp_id, $code, $mode);
            $today_opening = ($yesterday_closing > 0) ? $yesterday_closing : $balance;
            
            updateDailyStock($conn, $comp_id, $code, $mode, $today_opening, $balance);
        }
    }
    
    // Refresh the page to show updated values
    header("Location: opening_balance.php?mode=" . $mode . "&search=" . urlencode($search));
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
  <title>Opening Balance Management - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
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
    .csv-format {
      font-size: 0.9rem;
      color: #6c757d;
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
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Opening Balance Management</h3>

      <!-- Company and Financial Year Info -->
      <div class="company-info mb-3">
        <strong>Financial Year:</strong> <?= htmlspecialchars($fin_year) ?> | 
        <strong>Current Company:</strong> <?= htmlspecialchars($current_company['Comp_Name']) ?> |
        <strong>Current Month:</strong> <?= date('F Y') ?>
      </div>

      <!-- License Restriction Info - ADDED -->
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

      <!-- Import/Export Buttons -->
      <div class="import-export-buttons">
        <div class="btn-group">
          <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&export=csv" class="btn btn-info">
            <i class="fas fa-file-export"></i> Export CSV
          </a>
        </div>
      </div>

      <!-- Import from CSV Section -->
      <div class="import-section mb-4">
        <h5><i class="fas fa-file-import"></i> Import Opening Balances from CSV</h5>
        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
          <div class="col-md-6">
            <label for="csv_file" class="form-label">CSV File</label>
            <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
            <div class="csv-format">
              <strong>CSV format:</strong> Item_Code, Item_Name, Category, Current_Stock<br>
              <strong>Note:</strong> Do not modify the first three columns. Only fill the Current_Stock column.<br>
              <strong>License Filter:</strong> Only items allowed for your license type will be imported.
            </div>
          </div>
          <div class="col-md-3">
            <button type="submit" class="btn btn-primary w-100">
              <i class="fas fa-upload"></i> Import CSV
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
            <?= $import_message['message'] ?>
            <?php if (!empty($import_message['errors'])): ?>
              <ul class="mb-0 mt-2">
                <?php foreach ($import_message['errors'] as $error): ?>
                  <li><?= $error ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            </div>
        <?php endif; ?>
      </div>

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'active' : '' ?>">
            Others
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

      <!-- Balance Management Form -->
      <form method="POST" id="balanceForm">
        <div class="action-btn mb-3 d-flex gap-2">
          <button type="submit" name="update_balances" class="btn btn-success">
            <i class="fas fa-save"></i> Save Opening Balances
          </button>
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>

        <!-- Items Table -->
        <div class="table-container">
          <table class="table table-striped table-bordered table-hover">
            <thead class="sticky-header">
              <tr>
                <th>Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th class="company-column">
                  Current Stock
                </th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['CODE']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                  <td class="company-column">
                    <input type="number" name="opening_stock[<?= htmlspecialchars($item['CODE']); ?>]"
                           value="<?= (int)$item['CURRENT_STOCK']; ?>" min="0"
                           class="form-control opening-balance-input">
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="4" class="text-center">No items found for the selected liquor mode and license type.</td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Save Button at Bottom -->
        <div class="action-btn mt-3 d-flex gap-2">
          <button type="submit" name="update_balances" class="btn btn-success">
            <i class="fas fa-save"></i> Save Opening Balances
          </button>
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>
      </form>
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
  const inputs = form.querySelectorAll('input');
  
  inputs.forEach(input => {
    input.addEventListener('change', () => {
      formChanged = true;
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
</script>
</body>
</html>