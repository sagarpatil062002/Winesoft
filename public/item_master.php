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
        // Update existing stock record
        $update_query = "UPDATE tblitem_stock SET $opening_col = ?, $current_col = ? WHERE ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iis", $opening_balance, $opening_balance, $item_code);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new stock record
        $insert_query = "INSERT INTO tblitem_stock (ITEM_CODE, $opening_col, $current_col) VALUES (?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("sii", $item_code, $opening_balance, $opening_balance);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    // Update daily stock for today
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
    } else {
        // Insert new daily record
        $insert_daily_query = "INSERT INTO tbldailystock_$comp_id 
                              (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_{$today_padded}_OPEN, DAY_{$today_padded}_CLOSING) 
                              VALUES (?, ?, ?, ?, ?)";
        $insert_daily_stmt = $conn->prepare($insert_daily_query);
        $insert_daily_stmt->bind_param("sssii", $current_month, $item_code, $liqFlag, $opening_balance, $opening_balance);
        $insert_daily_stmt->execute();
        $insert_daily_stmt->close();
    }
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
function detectClassFromItemName($itemName, $liqFlag = 'F') {
    $itemName = strtoupper($itemName);
    
    // If LIQ_FLAG is 'C' (Country Liquor), return 'L'
    if ($liqFlag === 'C') {
        return 'L';
    }
    
    // WHISKY Detection
    if (strpos($itemName, 'WHISKY') !== false || 
        strpos($itemName, 'WHISKEY') !== false ||
        strpos($itemName, 'SCOTCH') !== false ||
        strpos($itemName, 'SINGLE MALT') !== false ||
        strpos($itemName, 'BLENDED') !== false ||
        strpos($itemName, 'BOURBON') !== false ||
        strpos($itemName, 'RYE') !== false ||
        preg_match('/\b(JOHNNIE WALKER|JACK DANIEL|CHIVAS|ROYAL CHALLENGE|8PM|OFFICER\'S CHOICE|MCDOWELL\'S|SIGNATURE|IMPERIAL BLUE)\b/', $itemName) ||
        preg_match('/\b(\d+ YEARS?|AGED)\b/', $itemName)) {
        return 'W';
    }
    
    // WINE Detection
    if (strpos($itemName, 'WINE') !== false ||
        strpos($itemName, 'PORT') !== false ||
        strpos($itemName, 'SHERRY') !== false ||
        strpos($itemName, 'CHAMPAGNE') !== false ||
        strpos($itemName, 'SPARKLING') !== false ||
        strpos($itemName, 'MERLOT') !== false ||
        strpos($itemName, 'CABERNET') !== false ||
        strpos($itemName, 'CHARDONNAY') !== false ||
        strpos($itemName, 'SAUVIGNON') !== false ||
        strpos($itemName, 'RED WINE') !== false ||
        strpos($itemName, 'WHITE WINE') !== false ||
        strpos($itemName, 'ROSE WINE') !== false ||
        strpos($itemName, 'DESSERT WINE') !== false ||
        strpos($itemName, 'FORTIFIED WINE') !== false ||
        preg_match('/\b(SULA|GROVER|FRATELLI|BORDEAUX|CHATEAU)\b/', $itemName)) {
        return 'V';
    }
    
    // BRANDY Detection
    if (strpos($itemName, 'BRANDY') !== false ||
        strpos($itemName, 'COGNAC') !== false ||
        strpos($itemName, 'VSOP') !== false ||
        strpos($itemName, 'XO') !== false ||
        strpos($itemName, 'NAPOLEON') !== false ||
        preg_match('/\b(HENNESSY|REMY MARTIN|MARTELL|COURVOISIER|MANSION HOUSE|OLD ADMIRAL|DUNHILL)\b/', $itemName) ||
        strpos($itemName, 'VS ') !== false) {
        return 'D';
    }
    
    // VODKA Detection
    if (strpos($itemName, 'VODKA') !== false ||
        preg_match('/\b(SMIRNOFF|ABSOLUT|ROMANOV|GREY GOOSE|BELVEDERE|CIROC|FINLANDIA)\b/', $itemName) ||
        strpos($itemName, 'LEMON VODKA') !== false ||
        strpos($itemName, 'ORANGE VODKA') !== false ||
        strpos($itemName, 'FLAVORED VODKA') !== false) {
        return 'K';
    }
    
    // GIN Detection
    if (strpos($itemName, 'GIN') !== false ||
        strpos($itemName, 'LONDON DRY') !== false ||
        strpos($itemName, 'NAVY STRENGTH') !== false ||
        preg_match('/\b(BOMBAY|GORDON\'S|TANQUERAY|BEEFEATER|HENDRICK\'S|BLUE RIBAND)\b/', $itemName) ||
        strpos($itemName, 'JUNIPER') !== false ||
        strpos($itemName, 'BOTANICAL GIN') !== false ||
        strpos($itemName, 'DRY GIN') !== false) {
        return 'G';
    }
    
    // RUM Detection
    if (strpos($itemName, 'RUM') !== false ||
        strpos($itemName, 'DARK RUM') !== false ||
        strpos($itemName, 'WHITE RUM') !== false ||
        strpos($itemName, 'SPICED RUM') !== false ||
        strpos($itemName, 'AGED RUM') !== false ||
        preg_match('/\b(BACARDI|CAPTAIN MORGAN|OLD MONK|HAVANA CLUB|MCDOWELL\'S RUM|CONTESSA RUM)\b/', $itemName) ||
        strpos($itemName, 'GOLD RUM') !== false ||
        strpos($itemName, 'NAVY RUM') !== false) {
        return 'R';
    }
    
    // BEER Detection - Enhanced with comprehensive indicators
    if (strpos($itemName, 'BEER') !== false || 
        strpos($itemName, 'LAGER') !== false ||
        strpos($itemName, 'ALE') !== false ||
        strpos($itemName, 'STOUT') !== false ||
        strpos($itemName, 'PILSNER') !== false ||
        strpos($itemName, 'DRAUGHT') !== false ||
        preg_match('/\b(KINGFISHER|TUBORG|CARLSBERG|BUDWEISER|HEINEKEN|CORONA|FOSTER\'S)\b/', $itemName)) {
        
        // Check for FERMENTED BEER (Strong) indicators
        $strongIndicators = [
            'STRONG', 'SUPER STRONG', 'EXTRA STRONG', 'BOLD', 'HIGH', 'POWER',
            'XXX', '5000', '8000', '9000', '10000', 'WHEAT STRONG', 'PREMIUM STRONG',
            'EXPORT STRONG', 'SPECIAL STRONG', 'ULTRA', 'HEAVY', 'MAXIMUM'
        ];
        
        // Check for MILD BEER indicators
        $mildIndicators = [
            'MILD', 'SMOOTH', 'LIGHT', 'DRAUGHT', 'LAGER', 'PILSNER', 'REGULAR',
            'PREMIUM', 'EXTRA', 'CLASSIC', 'ORIGINAL', 'LITE', 'STANDARD', 'NORMAL',
            'CRISP', 'REFRESHING'
        ];
        
        $isStrongBeer = false;
        $isMildBeer = false;
        
        // Check for strong beer indicators first (they take priority)
        foreach ($strongIndicators as $indicator) {
            if (strpos($itemName, $indicator) !== false) {
                $isStrongBeer = true;
                break;
            }
        }
        
        // If not strong beer, check for mild indicators
        if (!$isStrongBeer) {
            foreach ($mildIndicators as $indicator) {
                if (strpos($itemName, $indicator) !== false) {
                    $isMildBeer = true;
                    break;
                }
            }
        }
        
        // Determine beer class
        if ($isStrongBeer) {
            return 'F'; // FERMENTED BEER (Strong)
        } elseif ($isMildBeer) {
            return 'M'; // MILD BEER
        } else {
            // Default to Mild Beer if no specific indicators found
            return 'M';
        }
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
                            
                            // Detect class from item name with LIQ_FLAG context
                            $detectedClass = detectClassFromItemName($itemName, $liqFlag);
                            
                            // If LIQ_FLAG is 'C' (Country Liquor), force class to 'L'
                            if ($liqFlag === 'C') {
                                $detectedClass = 'L';
                            }
                            
                            // Validate class against license type
                            if (!in_array($detectedClass, $allowed_classes)) {
                                $errors++;
                                $errorDetails[] = "Item $code: Class '$detectedClass' not allowed for your license type '$license_type'";
                                continue; // Skip this row
                            }
                            
                            // Get valid ITEM_GROUP based on subclass description and LIQ_FLAG
                            $validItemGroup = getValidItemGroup($subclass, $liqFlag, $conn);
                            
                            // Check if item already exists
                            $checkQuery->bind_param("ss", $code, $liqFlag);
                            $checkQuery->execute();
                            $checkResult = $checkQuery->get_result();
                            
                            if ($checkResult->num_rows > 0) {
                                // Update existing item
                                $updateQuery->bind_param("ssssssddddss", 
                                    $printName, $itemName, $subclass, $detectedClass, $subclass, $validItemGroup,
                                    $pprice, $bprice, $mprice, $rprice, $code, $liqFlag
                                );
                                if ($updateQuery->execute()) {
                                    $updated++;
                                    
                                    // Update stock information
                                    updateItemStock($conn, $comp_id, $code, $liqFlag, $openingBalance);
                                } else {
                                    $errors++;
                                    $errorDetails[] = "Failed to update item $code: " . $updateQuery->error;
                                }
                            } else {
                                // Insert new item
                                $insertQuery->bind_param("ssssssddddss", 
                                    $code, $printName, $itemName, $subclass, $detectedClass, $subclass, $validItemGroup,
                                    $pprice, $bprice, $mprice, $rprice, $liqFlag
                                );
                                if ($insertQuery->execute()) {
                                    $imported++;
                                    
                                    // Create stock information
                                    updateItemStock($conn, $comp_id, $code, $liqFlag, $openingBalance);
                                } else {
                                    $errors++;
                                    $errorDetails[] = "Failed to import item $code: " . $insertQuery->error;
                                }
                            }
                        } else {
                            $errors++;
                            $errorDetails[] = "Row $rowCount: Insufficient data columns";
                        }
                    }
                    
                    // Close prepared statements
                    $checkQuery->close();
                    $updateQuery->close();
                    $insertQuery->close();
                    fclose($handle);
                    
                    $importMessage = "Import completed: $imported new items imported, $updated items updated, $errors errors.";
                    if ($errors > 0) {
                        $importMessage .= " Error details: " . implode("; ", array_slice($errorDetails, 0, 10));
                        if (count($errorDetails) > 10) {
                            $importMessage .= " and " . (count($errorDetails) - 10) . " more errors.";
                        }
                    }
                } else {
                    $importMessage = "Error: Could not open CSV file.";
                }
            } else {
                $importMessage = "Error: Please upload a valid CSV file.";
            }
        } catch (Exception $e) {
            $importMessage = "Error during import: " . $e->getMessage();
        }
    } else {
        $importMessage = "Error uploading file: " . $file['error'];
    }
}

// Fetch items from tblitemmaster for display - FILTERED BY LICENSE TYPE
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
    
    // Comprehensive example rows covering all classes
    const exampleRows = [
        // Whisky Examples
        ['WHISKY001', 'Johnnie Walker Red Label Whisky', 'JW Red Label', '750ML', '2500.000', '2200.000', '2800.000', '2600.000', 'F', '50'],
        ['WHISKY002', '8PM Premium Whisky', '8PM Premium', '180ML', '120.000', '100.000', '150.000', '130.000', 'F', '100'],
        ['WHISKY003', 'Officer\'s Choice Whisky', 'Officer\'s Choice', '375ML', '450.000', '400.000', '500.000', '480.000', 'F', '75'],
        
        // Wine Examples
        ['WINE001', 'Sula Chenin Blanc White Wine', 'Sula White', '750ML', '800.000', '700.000', '1000.000', '900.000', 'F', '30'],
        ['WINE002', 'Grover Red Wine', 'Grover Red', '750ML', '1200.000', '1000.000', '1500.000', '1300.000', 'F', '25'],
        
        // Brandy Examples
        ['BRANDY001', 'Hennessy VSOP Cognac', 'Hennessy VSOP', '750ML', '8500.000', '8000.000', '10000.000', '9500.000', 'F', '15'],
        ['BRANDY002', 'Mansion House Brandy', 'Mansion House', '180ML', '150.000', '130.000', '180.000', '160.000', 'F', '60'],
        
        // Vodka Examples
        ['VODKA001', 'Smirnoff Red Label Vodka', 'Smirnoff Red', '750ML', '900.000', '800.000', '1100.000', '1000.000', 'F', '40'],
        ['VODKA002', 'Absolut Vodka', 'Absolut', '750ML', '1200.000', '1100.000', '1400.000', '1300.000', 'F', '35'],
        
        // Gin Examples
        ['GIN001', 'Bombay Sapphire Gin', 'Bombay Sapphire', '750ML', '1500.000', '1400.000', '1800.000', '1600.000', 'F', '20'],
        ['GIN002', 'Gordon\'s London Dry Gin', 'Gordon\'s Gin', '750ML', '800.000', '700.000', '1000.000', '900.000', 'F', '45'],
        
        // Rum Examples
        ['RUM001', 'Bacardi White Rum', 'Bacardi White', '750ML', '700.000', '600.000', '850.000', '750.000', 'F', '55'],
        ['RUM002', 'Old Monk Premium Rum', 'Old Monk', '750ML', '500.000', '450.000', '600.000', '550.000', 'F', '80'],
        
        // Beer Examples - Strong and Mild
        ['BEER001', 'Kingfisher Strong Beer', 'Kingfisher Strong', '650ML', '90.000', '80.000', '120.000', '100.000', 'F', '200'],
        ['BEER002', 'Kingfisher Premium Lager', 'Kingfisher Premium', '650ML', '85.000', '75.000', '110.000', '95.000', 'F', '180'],
        ['BEER003', 'Tuborg Super Strong Beer', 'Tuborg Strong', '650ML', '95.000', '85.000', '125.000', '105.000', 'F', '150'],
        ['BEER004', 'Budweiser Mild Beer', 'Budweiser Mild', '650ML', '80.000', '70.000', '100.000', '90.000', 'F', '220']
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

<div class="import-template">
    <p><strong>Import file requirements:</strong></p>
    <ul>
        <li>File must contain these columns in order: <strong>Code, ItemName, PrintName, Subclass, PPrice, BPrice, MPrice, RPrice, LIQFLAG, OpeningBalance</strong></li>
        <li>Class will be automatically detected from ItemName using intelligent pattern matching</li>
        <li>Only CSV files are supported for import</li>
        <li>OpeningBalance should be a whole number (integer)</li>
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
              <th>P. Price</th>
              <th>B. Price</th>
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