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

// ====================================================================
// FUNCTIONS FOR 4-LAYER CLASSIFICATION SYSTEM
// ====================================================================

// Function to get category name from category code
function getCategoryName($category_code, $conn) {
    if (empty($category_code)) return 'N/A';
    
    $query = "SELECT CATEGORY_NAME FROM tblcategory WHERE CATEGORY_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $category_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['CATEGORY_NAME'];
    }
    return $category_code;
}

// Function to get class name from class_code_new
function getClassNameNew($class_code_new, $conn) {
    if (empty($class_code_new)) return 'N/A';
    
    $query = "SELECT CLASS_NAME FROM tblclass_new WHERE CLASS_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $class_code_new);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['CLASS_NAME'];
    }
    return $class_code_new;
}

// Function to get subclass name from subclass_code_new
function getSubclassNameNew($subclass_code_new, $conn) {
    if (empty($subclass_code_new)) return 'N/A';
    
    $query = "SELECT SUBCLASS_NAME FROM tblsubclass_new WHERE SUBCLASS_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $subclass_code_new);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['SUBCLASS_NAME'];
    }
    return $subclass_code_new;
}

// Function to get size description from size_code
function getSizeDescription($size_code, $conn) {
    if (empty($size_code)) return 'N/A';
    
    $query = "SELECT SIZE_DESC FROM tblsize WHERE SIZE_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $size_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['SIZE_DESC'];
    }
    return $size_code;
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
        preg_match('/\b(JOHNNIE WALKER|8PM|OFFICER\'S CHOICE|MCDOWELL\'S|IMPERIAL BLUE|BLENDED)\b/', $itemName)) {
        return 'W';
    }
    
    // WINE Detection
    if (strpos($itemName, 'WINE') !== false ||
        strpos($itemName, 'SULA') !== false) {
        return 'V';
    }
    
    // BRANDY Detection
    if (strpos($itemName, 'BRANDY') !== false ||
        strpos($itemName, 'COGNAC') !== false ||
        strpos($itemName, 'HENNESSY') !== false ||
        strpos($itemName, 'VSOP') !== false) {
        return 'D';
    }
    
    // VODKA Detection
    if (strpos($itemName, 'VODKA') !== false ||
        strpos($itemName, 'SMIRNOFF') !== false) {
        return 'K';
    }
    
    // GIN Detection
    if (strpos($itemName, 'GIN') !== false) {
        return 'G';
    }
    
    // RUM Detection
    if (strpos($itemName, 'RUM') !== false ||
        strpos($itemName, 'OLD MONK') !== false) {
        return 'R';
    }
    
    // BEER Detection
    if (strpos($itemName, 'BEER') !== false || 
        strpos($itemName, 'LAGER') !== false ||
        strpos($itemName, 'KINGFISHER') !== false ||
        strpos($itemName, 'BUDWEISER') !== false ||
        strpos($itemName, 'FOSTERS') !== false) {
        if (strpos($itemName, 'STRONG') !== false) {
            return 'F'; // Strong Beer
        } else {
            return 'M'; // Mild Beer
        }
    }
    
    // Default to Others
    return 'O';
}

// Function to get code from name (for import) - CASE INSENSITIVE
function getCategoryCodeByName($category_name, $conn) {
    if (empty($category_name)) return '';
    
    $query = "SELECT CATEGORY_CODE FROM tblcategory WHERE UPPER(CATEGORY_NAME) = UPPER(?) OR CATEGORY_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $category_name, $category_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['CATEGORY_CODE'];
    }
    return '';
}

function getClassCodeByName($class_name, $conn) {
    if (empty($class_name)) return '';
    
    $query = "SELECT CLASS_CODE FROM tblclass_new WHERE UPPER(CLASS_NAME) = UPPER(?) OR CLASS_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $class_name, $class_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['CLASS_CODE'];
    }
    return '';
}

function getSubclassCodeByName($subclass_name, $conn) {
    if (empty($subclass_name)) return '';
    
    $query = "SELECT SUBCLASS_CODE FROM tblsubclass_new WHERE UPPER(SUBCLASS_NAME) = UPPER(?) OR SUBCLASS_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $subclass_name, $subclass_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['SUBCLASS_CODE'];
    }
    return '';
}

function getSizeCodeByDescription($size_desc, $conn) {
    if (empty($size_desc)) return '';
    
    $query = "SELECT SIZE_CODE FROM tblsize WHERE UPPER(SIZE_DESC) = UPPER(?) OR SIZE_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $size_desc, $size_desc);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['SIZE_CODE'];
    }
    return '';
}

// ====================================================================
// END OF FUNCTIONS
// ====================================================================

// Handle delete request
$deleteMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_code']) && isset($_POST['delete_liq_flag'])) {
    $delete_code = trim($_POST['delete_code']);
    $delete_liq_flag = trim($_POST['delete_liq_flag']);

    if (!empty($delete_code) && !empty($delete_liq_flag)) {
        $conn->begin_transaction();
        try {
            $delete_master_stmt = $conn->prepare("DELETE FROM tblitemmaster WHERE CODE = ? AND LIQ_FLAG = ?");
            $delete_master_stmt->bind_param("ss", $delete_code, $delete_liq_flag);
            $delete_master_stmt->execute();
            $delete_master_stmt->close();

            $delete_stock_stmt = $conn->prepare("DELETE FROM tblitem_stock WHERE ITEM_CODE = ?");
            $delete_stock_stmt->bind_param("s", $delete_code);
            $delete_stock_stmt->execute();
            $delete_stock_stmt->close();

            $delete_daily_stmt = $conn->prepare("DELETE FROM tbldailystock_$comp_id WHERE ITEM_CODE = ? AND LIQ_FLAG = ?");
            $delete_daily_stmt->bind_param("ss", $delete_code, $delete_liq_flag);
            $delete_daily_stmt->execute();
            $delete_daily_stmt->close();

            $conn->commit();
            $deleteMessage = "Item '$delete_code' deleted successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $deleteMessage = "Error deleting item: " . $e->getMessage();
        }
    } else {
        $deleteMessage = "Invalid delete request.";
    }
}

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Check if company columns exist in tblitem_stock, if not create them
$check_columns_query = "SELECT COUNT(*) as count FROM information_schema.columns
                       WHERE table_name = 'tblitem_stock'
                       AND column_name = 'OPENING_STOCK$comp_id'";
$check_result = $conn->query($check_columns_query);
$opening_col_exists = $check_result->fetch_assoc()['count'] > 0;

if (!$opening_col_exists) {
    $add_col1_query = "ALTER TABLE tblitem_stock ADD COLUMN OPENING_STOCK$comp_id INT DEFAULT 0";
    $conn->query($add_col1_query);
}

// Check if company daily stock table exists, if not create it with proper structure
$check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'tbldailystock_$comp_id'";
$check_table_result = $conn->query($check_table_query);
$table_exists = $check_table_result->fetch_assoc()['count'] > 0;

if (!$table_exists) {
    // Create table with day columns
    $create_table_query = "CREATE TABLE tbldailystock_$comp_id (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        `CATEGORY_CODE` varchar(20) DEFAULT NULL,
        `CLASS_CODE_NEW` varchar(20) DEFAULT NULL,
        `SUBCLASS_CODE_NEW` varchar(20) DEFAULT NULL,
        `SIZE_CODE` varchar(20) DEFAULT NULL,";
    
    // Add day columns for 31 days
    for ($i = 1; $i <= 31; $i++) {
        $day_padded = str_pad($i, 2, '0', STR_PAD_LEFT);
        $create_table_query .= "
        `DAY_{$day_padded}_OPEN` int(11) DEFAULT 0,
        `DAY_{$day_padded}_PURCHASE` int(11) DEFAULT 0,
        `DAY_{$day_padded}_SALES` int(11) DEFAULT 0,
        `DAY_{$day_padded}_CLOSING` int(11) DEFAULT 0,";
    }
    
    $create_table_query .= "
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock_$comp_id` (`STK_MONTH`,`ITEM_CODE`,`LIQ_FLAG`),
        KEY `ITEM_CODE_$comp_id` (`ITEM_CODE`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $conn->query($create_table_query);
}

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    
    // Fetch items from tblitemmaster - FILTERED BY LICENSE TYPE
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                         PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG,
                         CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE
                  FROM tblitemmaster
                  WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
        
        $params = array_merge([$mode], $allowed_classes);
        $types = str_repeat('s', count($params));
    } else {
        $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                         PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG,
                         CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE
                  FROM tblitemmaster
                  WHERE 1 = 0";
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
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=items_' . $mode . '_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers - Size column removed, Subclass renamed to Size
        fputcsv($output, array('Code', 'ItemName', 'PrintName', 'Size', 
                               'PPrice', 'BPrice', 'MPrice', 'RPrice', 'LIQFLAG', 
                               'OpeningBalance', 'Category', 'Class', 'Subclass'));
        
        foreach ($items as $item) {
            // Get opening balance
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
            
            // Get NAMES for export
            $category_name = getCategoryName($item['CATEGORY_CODE'], $conn);
            $class_name = getClassNameNew($item['CLASS_CODE_NEW'], $conn);
            $subclass_name = getSubclassNameNew($item['SUBCLASS_CODE_NEW'], $conn);
            $size_desc = getSizeDescription($item['SIZE_CODE'], $conn);
            
            // Use DETAILS2 as Size (330 ML, 650 ML, etc.)
            $size_column = $item['DETAILS2'];
            
            $exportRow = [
                'Code' => $item['CODE'],
                'ItemName' => $item['DETAILS'],
                'PrintName' => $item['Print_Name'],
                'Size' => $size_column, // Changed from 'Subclass' to 'Size'
                'PPrice' => $item['PPRICE'],
                'BPrice' => $item['BPRICE'],
                'MPrice' => $item['MPRICE'],
                'RPrice' => $item['RPRICE'],
                'LIQFLAG' => $item['LIQ_FLAG'],
                'OpeningBalance' => $opening_balance,
                'Category' => $category_name,
                'Class' => $class_name,
                'Subclass' => $subclass_name // This is now the actual subclass (Whisky, Vodka, etc.)
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
    
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '512M');
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $filePath = $file['tmp_name'];
        $fileName = $file['name'];
        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
        
        try {
            if ($importType === 'csv' && $fileExt === 'csv') {
                // Start transaction
                $conn->begin_transaction();
                
                // IMPORTANT: Disable foreign key checks temporarily
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                $conn->query("SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0");
                $conn->query("SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0");
                
                $handle = fopen($filePath, 'r');
                if ($handle !== FALSE) {
                    // Read and normalize headers (case-insensitive)
                    $header = fgetcsv($handle);
                    $normalized_header = array_map(function($col) {
                        $col = trim($col);
                        $col = preg_replace('/^\xEF\xBB\xBF/', '', $col); // Remove UTF-8 BOM
                        $col = strtolower($col);
                        $col = str_replace([' ', '_', '-'], '', $col); // Remove spaces, underscores, hyphens
                        return $col;
                    }, $header);
                    
                    $headerMap = array_flip($normalized_header);
                    
                    // Define expected columns with flexible matching
                    $expected_columns = [
                        'code' => ['code'],
                        'itemname' => ['itemname', 'item'],
                        'printname' => ['printname', 'printname'],
                        'size' => ['size', 'details2'],
                        'pprice' => ['pprice', 'purchaseprice'],
                        'bprice' => ['bprice', 'baseprice'],
                        'mprice' => ['mprice', 'mrp'],
                        'rprice' => ['rprice', 'retailprice'],
                        'liqflag' => ['liqflag', 'liq_flag'],
                        'openingbalance' => ['openingbalance', 'opening_stock'],
                        'category' => ['category', 'cat'],
                        'class' => ['class', 'class_name'],
                        'subclass' => ['subclass', 'subclass', 'sub_class']
                    ];
                    
                    // Find actual column indices
                    $column_indices = [];
                    foreach ($expected_columns as $col_name => $possible_names) {
                        $column_indices[$col_name] = -1;
                        foreach ($possible_names as $possible_name) {
                            if (isset($headerMap[$possible_name])) {
                                $column_indices[$col_name] = $headerMap[$possible_name];
                                break;
                            }
                        }
                    }
                    
                    // Check essential columns
                    if ($column_indices['code'] === -1 || $column_indices['itemname'] === -1) {
                        throw new Exception("CSV must contain 'Code' and 'ItemName' columns");
                    }
                    
                    $imported = 0;
                    $updated = 0;
                    $errors = 0;
                    $errorDetails = [];
                    $rowCount = 0;

                    while (($data = fgetcsv($handle)) !== FALSE) {
                        $rowCount++;

                        if (count($data) >= 10) {
                            $code = isset($data[$column_indices['code']]) ? trim($data[$column_indices['code']]) : '';
                            $itemName = isset($data[$column_indices['itemname']]) ? trim($data[$column_indices['itemname']]) : '';
                            $printName = isset($data[$column_indices['printname']]) ? trim($data[$column_indices['printname']]) : '';
                            $size = isset($data[$column_indices['size']]) ? trim($data[$column_indices['size']]) : '';
                            $pprice = isset($data[$column_indices['pprice']]) ? floatval(trim($data[$column_indices['pprice']])) : 0;
                            $bprice = isset($data[$column_indices['bprice']]) ? floatval(trim($data[$column_indices['bprice']])) : 0;
                            $mprice = isset($data[$column_indices['mprice']]) ? floatval(trim($data[$column_indices['mprice']])) : 0;
                            $rprice = isset($data[$column_indices['rprice']]) ? floatval(trim($data[$column_indices['rprice']])) : 0;
                            
                            $liqFlag = '';
                            if (isset($data[$column_indices['liqflag']]) && !empty(trim($data[$column_indices['liqflag']]))) {
                                $liqFlag = strtoupper(trim($data[$column_indices['liqflag']]));
                            } else {
                                $liqFlag = $mode;
                            }

                            if (empty($liqFlag)) {
                                $errors++;
                                $errorDetails[] = "Row $rowCount: LIQ_FLAG cannot be empty for item $code";
                                continue;
                            }

                            $openingBalance = isset($data[$column_indices['openingbalance']]) ? intval(trim($data[$column_indices['openingbalance']])) : 0;

                            // Detect class from item name
                            $detectedClass = detectClassFromItemName($itemName, $liqFlag);
                            
                            // Validate against license
                            if (!in_array($detectedClass, $allowed_classes)) {
                                $errors++;
                                $errorDetails[] = "Row $rowCount: Item $code: Class '$detectedClass' not allowed for license '$license_type'";
                                continue;
                            }

                            // Get ITEM_GROUP from tblsubclass based on size description
                            $itemGroup = 'SC001'; // Default
                            if (!empty($size)) {
                                $groupQuery = "SELECT ITEM_GROUP FROM tblsubclass WHERE UPPER(`DESC`) = UPPER(?) AND LIQ_FLAG = ? LIMIT 1";
                                $groupStmt = $conn->prepare($groupQuery);
                                $groupStmt->bind_param("ss", $size, $liqFlag);
                                $groupStmt->execute();
                                $groupResult = $groupStmt->get_result();
                                if ($groupResult->num_rows > 0) {
                                    $groupRow = $groupResult->fetch_assoc();
                                    $itemGroup = $groupRow['ITEM_GROUP'];
                                }
                                $groupStmt->close();
                            }

                            // Get 4-layer classification data from CSV (CASE-INSENSITIVE)
                            $categoryName = isset($data[$column_indices['category']]) ? trim($data[$column_indices['category']]) : '';
                            $className = isset($data[$column_indices['class']]) ? trim($data[$column_indices['class']]) : '';
                            $subclassName = isset($data[$column_indices['subclass']]) ? trim($data[$column_indices['subclass']]) : '';

                            // Convert names to codes (CASE-INSENSITIVE)
                            $categoryCode = !empty($categoryName) ? getCategoryCodeByName($categoryName, $conn) : '';
                            $classCodeNew = !empty($className) ? getClassCodeByName($className, $conn) : '';
                            $subclassCodeNew = !empty($subclassName) ? getSubclassCodeByName($subclassName, $conn) : '';
                            
                            // Get size code from size description (CASE-INSENSITIVE)
                            $sizeCode = !empty($size) ? getSizeCodeByDescription($size, $conn) : '';

                            // Check if item exists
                            $checkQuery = $conn->prepare("SELECT CODE FROM tblitemmaster WHERE CODE = ? AND LIQ_FLAG = ?");
                            $checkQuery->bind_param("ss", $code, $liqFlag);
                            $checkQuery->execute();
                            $checkResult = $checkQuery->get_result();
                            $itemExists = $checkResult->num_rows > 0;
                            $checkQuery->close();

                            if ($itemExists) {
                                // UPDATE existing item
                                $updateQuery = $conn->prepare("UPDATE tblitemmaster SET 
                                    Print_Name = ?, DETAILS = ?, DETAILS2 = ?, CLASS = ?, ITEM_GROUP = ?, 
                                    PPRICE = ?, BPRICE = ?, MPRICE = ?, RPRICE = ?,
                                    CATEGORY_CODE = ?, CLASS_CODE_NEW = ?, SUBCLASS_CODE_NEW = ?, SIZE_CODE = ?
                                    WHERE CODE = ? AND LIQ_FLAG = ?");
                                
                                $updateQuery->bind_param("sssssddddssssss",
                                    $printName, $itemName, $size, $detectedClass,
                                    $itemGroup, $pprice, $bprice, $mprice, $rprice,
                                    $categoryCode, $classCodeNew, $subclassCodeNew, $sizeCode,
                                    $code, $liqFlag
                                );
                                
                                if ($updateQuery->execute()) {
                                    $updated++;
                                    
                                    // Update stock with opening balance
                                    $stockUpdate = $conn->prepare("INSERT INTO tblitem_stock 
                                        (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id,
                                         CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
                                        ON DUPLICATE KEY UPDATE 
                                        OPENING_STOCK$comp_id = VALUES(OPENING_STOCK$comp_id), 
                                        CURRENT_STOCK$comp_id = VALUES(CURRENT_STOCK$comp_id),
                                        CATEGORY_CODE = VALUES(CATEGORY_CODE),
                                        CLASS_CODE_NEW = VALUES(CLASS_CODE_NEW),
                                        SUBCLASS_CODE_NEW = VALUES(SUBCLASS_CODE_NEW),
                                        SIZE_CODE = VALUES(SIZE_CODE)");
                                    $stockUpdate->bind_param("siiissss", $code, $fin_year, $openingBalance, $openingBalance, $categoryCode, $classCodeNew, $subclassCodeNew, $sizeCode);
                                    $stockUpdate->execute();
                                    $stockUpdate->close();
                                } else {
                                    $errors++;
                                    $errorDetails[] = "Row $rowCount: Failed to update $code: " . $updateQuery->error;
                                }
                                $updateQuery->close();
                            } else {
                                // INSERT new item
                                $insertQuery = $conn->prepare("INSERT INTO tblitemmaster 
                                    (CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                                     PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG,
                                     CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                
                                $insertQuery->bind_param("sssssddddssssss",
                                    $code, $printName, $itemName, $size,
                                    $detectedClass, $itemGroup,
                                    $pprice, $bprice, $mprice, $rprice, $liqFlag,
                                    $categoryCode, $classCodeNew, $subclassCodeNew, $sizeCode
                                );
                                
                                if ($insertQuery->execute()) {
                                    $imported++;
                                    
                                    // Create stock with opening balance
                                    $stockInsert = $conn->prepare("INSERT INTO tblitem_stock 
                                        (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id,
                                         CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                    $stockInsert->bind_param("siiissss", $code, $fin_year, $openingBalance, $openingBalance, $categoryCode, $classCodeNew, $subclassCodeNew, $sizeCode);
                                    $stockInsert->execute();
                                    $stockInsert->close();
                                    
                                    // Create daily stock entry
                                    $current_month = date('Y-m');
                                    $current_day = date('d');
                                    
                                    // Check if day columns exist, add if not
                                    $check_day_column = "SELECT COUNT(*) as count FROM information_schema.columns 
                                                       WHERE table_name = 'tbldailystock_$comp_id' 
                                                       AND column_name = 'DAY_{$current_day}_OPEN'";
                                    $check_day_result = $conn->query($check_day_column);
                                    $day_column_exists = $check_day_result->fetch_assoc()['count'] > 0;
                                    
                                    if (!$day_column_exists) {
                                        // Add columns for this day
                                        $conn->query("ALTER TABLE tbldailystock_$comp_id 
                                            ADD COLUMN DAY_{$current_day}_OPEN INT DEFAULT 0,
                                            ADD COLUMN DAY_{$current_day}_PURCHASE INT DEFAULT 0,
                                            ADD COLUMN DAY_{$current_day}_SALES INT DEFAULT 0,
                                            ADD COLUMN DAY_{$current_day}_CLOSING INT DEFAULT 0");
                                    }
                                    
                                    $dailyStockQuery = $conn->prepare("INSERT INTO tbldailystock_$comp_id 
                                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, 
                                         DAY_{$current_day}_OPEN, DAY_{$current_day}_PURCHASE, DAY_{$current_day}_SALES, DAY_{$current_day}_CLOSING,
                                         CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE) 
                                        VALUES (?, ?, ?, ?, 0, 0, ?, ?, ?, ?, ?) 
                                        ON DUPLICATE KEY UPDATE
                                        DAY_{$current_day}_OPEN = VALUES(DAY_{$current_day}_OPEN),
                                        DAY_{$current_day}_CLOSING = VALUES(DAY_{$current_day}_CLOSING),
                                        CATEGORY_CODE = VALUES(CATEGORY_CODE),
                                        CLASS_CODE_NEW = VALUES(CLASS_CODE_NEW),
                                        SUBCLASS_CODE_NEW = VALUES(SUBCLASS_CODE_NEW),
                                        SIZE_CODE = VALUES(SIZE_CODE)");
                                    
                                    $dailyStockQuery->bind_param("sssiissss", 
                                        $current_month, $code, $liqFlag,
                                        $openingBalance, $openingBalance,
                                        $categoryCode, $classCodeNew, $subclassCodeNew, $sizeCode
                                    );
                                    $dailyStockQuery->execute();
                                    $dailyStockQuery->close();
                                } else {
                                    $errors++;
                                    $errorDetails[] = "Row $rowCount: Failed to import $code: " . $insertQuery->error;
                                }
                                $insertQuery->close();
                            }
                        } else {
                            $errors++;
                            $errorDetails[] = "Row $rowCount: Insufficient columns";
                        }
                    }
                    fclose($handle);
                    
                    // Re-enable foreign key checks and constraints
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    $conn->query("SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS");
                    $conn->query("SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS");
                    
                    // Commit transaction
                    $conn->commit();
                    
                    $importMessage = "Import completed: $imported new items imported, $updated items updated, $errors errors.";
                    if ($errors > 0) {
                        $importMessage .= " First few errors: " . implode("; ", array_slice($errorDetails, 0, 5));
                    }
                } else {
                    $conn->rollback();
                    $importMessage = "Error: Could not open CSV file.";
                }
            } else {
                $importMessage = "Error: Please upload a valid CSV file.";
            }
        } catch (Exception $e) {
            // Rollback on error
            if ($conn) {
                $conn->rollback();
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                $conn->query("SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS");
                $conn->query("SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS");
            }
            $importMessage = "Error during import: " . $e->getMessage() . " (Line: " . $e->getLine() . ")";
        }
    } else {
        $importMessage = "Error uploading file: " . $file['error'];
    }
}

// Fetch items for display - FILTERED BY LICENSE TYPE
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                     PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG,
                     CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE
              FROM tblitemmaster
              WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
    
    $params = array_merge([$mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
    $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                     PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG,
                     CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE
              FROM tblitemmaster
              WHERE 1 = 0";
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
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>

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
    /* Compact table styling */
    .table-container {
        overflow-x: auto;
        max-width: 100%;
    }
    .styled-table {
        font-size: 0.85rem;
        width: 100%;
        min-width: 1200px;
    }
    .styled-table th {
        white-space: nowrap;
        padding: 8px 5px;
    }
    .styled-table td {
        padding: 6px 4px;
        vertical-align: middle;
    }
    .styled-table .col-code { width: 80px; }
    .styled-table .col-item-name { width: 200px; }
    .styled-table .col-print-name { width: 100px; }
    .styled-table .col-category { width: 120px; }
    .styled-table .col-class { width: 120px; }
    .styled-table .col-subclass { width: 120px; }
    .styled-table .col-size { width: 100px; }
    .styled-table .col-price { width: 70px; text-align: right; }
    .styled-table .col-stock { width: 60px; text-align: center; }
    .styled-table .col-actions { width: 100px; }
    
    .compact-text {
        font-size: 0.8rem;
        line-height: 1.2;
    }
    .hierarchy-label {
        font-weight: bold;
        font-size: 0.75rem;
        color: #495057;
        margin-bottom: 2px;
    }
    .new-system {
        color: #198754;
        font-size: 0.8rem;
        font-weight: 500;
    }
    .dropdown-values {
        font-size: 0.7rem;
        color: #6c757d;
        margin-top: 5px;
    }
  </style>
<script>
function downloadTemplate() {
    const headers = ['Code', 'ItemName', 'PrintName', 'Size', 
                    'PPrice', 'BPrice', 'MPrice', 'RPrice', 'LIQFLAG', 
                    'OpeningBalance', 'Category', 'Class', 'Subclass'];
    
    // Create examples with actual NAMES
    const exampleRows = [
        // Beer Examples with proper subclasses
        ['SCMBR0009735', 'Budweiser Premium King of Beer', '', '330 ML', 
         '80.000', '70.000', '100.000', '120.000', 'F', '0',
         'Mild Beer', 'Mild Beer', 'Mild Beer'],
        ['SCMBR0009846', 'Kingfisher Strong Premium Beer', '', '650 ML', 
         '120.000', '100.000', '130.000', '150.000', 'F', '0',
         'Fermented Beer', 'Fermented Beer', 'Fermented Beer'],
        ['SCMBR0009787', 'FOSTERS LAGAR BEER', '', '50 Ltr', 
         '80.000', '70.000', '100.000', '120.000', 'F', '0',
         'Mild Beer', 'Mild Beer', 'Mild Beer'],
        
        // Whisky Examples with proper subclasses
        ['WHISKY001', 'Johnnie Walker Red Label Whisky', 'JW Red Label', '750ML', 
         '2500.000', '2200.000', '2800.000', '2600.000', 'F', '50',
         'Spirit', 'IMFL', 'Whisky'],
        ['WHISKY002', '8PM Premium Whisky', '8PM Premium', '90ML', 
         '120.000', '100.000', '150.000', '130.000', 'F', '100',
         'Spirit', 'IMFL', 'Whisky'],
        
        // Wine Example with proper subclass
        ['WINE001', 'Sula Chenin Blanc White Wine', 'Sula White', '750ML', 
         '800.000', '700.000', '1000.000', '900.000', 'F', '30',
         'Wine', 'Indian', 'Indian'],
        
        // Brandy Example with proper subclass
        ['BRANDY001', 'Hennessy VSOP Cognac', 'Hennessy VSOP', '750ML', 
         '8500.000', '8000.000', '10000.000', '9500.000', 'F', '15',
         'Spirit', 'IMFL', 'Brandy'],
        
        // Vodka Example with proper subclass
        ['VODKA001', 'Smirnoff Red Label Vodka', 'Smirnoff Red', '750ML', 
         '900.000', '800.000', '1100.000', '1000.000', 'F', '40',
         'Spirit', 'IMFL', 'Vodka'],
        
        // Rum Example with proper subclass
        ['RUM001', 'Old Monk Supreme Rum', 'Old Monk', '750ML', 
         '600.000', '500.000', '750.000', '650.000', 'F', '60',
         'Spirit', 'IMFL', 'Rum'],
        
        // Minimal example (4-layer fields optional)
        ['LIQ001', 'Generic Liquor', 'Generic', '180 ML', 
         '100.000', '90.000', '120.000', '110.000', 'F', '50',
         '', '', '']
    ];
    
    let csvContent = headers.join(',') + '\r\n';
    
    exampleRows.forEach(row => {
        csvContent += row.join(',') + '\r\n';
    });
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'item_import_template.csv');
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
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

    <div class="content-area">
      <h3 class="mb-4">Excise Item Master 
        <span class="badge bg-info">4-Layer Classification System</span>
      </h3>

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
              <li><strong>Required columns:</strong> Code, ItemName, PrintName, Size, PPrice, BPrice, MPrice, RPrice, LIQFLAG, OpeningBalance</li>
              <li><strong>Optional 4-layer columns:</strong> Category, Class, Subclass</li>
              <li><strong>Size:</strong> Use size descriptions (330 ML, 650 ML, 50 Ltr, 90 ML-(96), etc.) - This goes into DETAILS2</li>
              <li><strong>Category:</strong> Use category names (Spirit, Wine, Fermented Beer, Mild Beer, etc.) - CASE-INSENSITIVE</li>
              <li><strong>Class:</strong> Use class names (IMFL, Imported, MML, Indian, etc.) - CASE-INSENSITIVE</li>
              <li><strong>Subclass:</strong> Use subclass names (Whisky, Vodka, Rum, Brandy, etc.) - CASE-INSENSITIVE</li>
              <li><strong>Note:</strong> "Mild Beer", "MILD BEER", "mild beer" all treated as same</li>
          </ul>
       
          <div class="download-template">
              <a href="javascript:void(0);" onclick="downloadTemplate()" class="btn btn-sm btn-outline-secondary">
                  <i class="fas fa-download"></i> Download Template
              </a>
              <small class="text-muted ms-2">Includes examples with proper 4-layer classification names</small>
          </div>
          
          <div class="dropdown-values mt-2">
              <p><strong>Valid values for 4-layer system (case-insensitive):</strong></p>
              <div class="row">
                  <div class="col-md-4">
                      <strong>Category:</strong><br>
                      <span class="badge bg-secondary">Spirit</span>
                      <span class="badge bg-secondary">Wine</span>
                      <span class="badge bg-secondary">Fermented Beer</span>
                      <span class="badge bg-secondary">Mild Beer</span>
                  </div>
                  <div class="col-md-4">
                      <strong>Class:</strong><br>
                      <span class="badge bg-info">IMFL</span>
                      <span class="badge bg-info">Imported</span>
                      <span class="badge bg-info">MML</span>
                      <span class="badge bg-info">Indian</span>
                  </div>
                  <div class="col-md-4">
                      <strong>Subclass:</strong><br>
                      <span class="badge bg-success">Whisky</span>
                      <span class="badge bg-success">Vodka</span>
                      <span class="badge bg-success">Rum</span>
                      <span class="badge bg-success">Brandy</span>
                      <span class="badge bg-success">Gin</span>
                      <span class="badge bg-success">Tequila</span>
                  </div>
              </div>
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
          <i class="fas fa-plus"></i> New Item
        </a>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Import/Delete Messages -->
      <?php if (!empty($importMessage)): ?>
      <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= $importMessage ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <?php if (!empty($deleteMessage)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $deleteMessage ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <!-- Items Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th class="col-code">Code</th>
              <th class="col-item-name">Item Name</th>
              <th class="col-print-name">Print Name</th>
              <th class="col-category">Category</th>
              <th class="col-class">Class</th>
              <th class="col-subclass">Subclass</th>
              <th class="col-size">Size</th>
              <th class="col-price">P.Price</th>
              <th class="col-price">B.Price</th>
              <th class="col-price">MRP</th>
              <th class="col-price">R.Price</th>
              <th class="col-stock">Open Stock</th>
              <th class="col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
              <?php
              // Get opening balance
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

              // Get 4-layer NAMES from codes
              $category_name = getCategoryName($item['CATEGORY_CODE'], $conn);
              $class_name = getClassNameNew($item['CLASS_CODE_NEW'], $conn);
              $subclass_name = getSubclassNameNew($item['SUBCLASS_CODE_NEW'], $conn);
              ?>
              <tr class="compact-text">
                <td class="col-code"><?= htmlspecialchars($item['CODE']); ?></td>
                <td class="col-item-name"><?= htmlspecialchars($item['DETAILS']); ?></td>
                <td class="col-print-name"><?= htmlspecialchars($item['Print_Name']); ?></td>
                
                <!-- Category Column - Show NAME only -->
                <td class="col-category">
                    <div class="hierarchy-label">Category:</div>
                    <div class="new-system"><?= htmlspecialchars($category_name); ?></div>
                </td>
                
                <!-- Class Column - Show NAME only -->
                <td class="col-class">
                    <div class="hierarchy-label">Class:</div>
                    <div class="new-system"><?= htmlspecialchars($class_name); ?></div>
                </td>
                
                <!-- Subclass Column - Show NAME only -->
                <td class="col-subclass">
                    <div class="hierarchy-label">Subclass:</div>
                    <div class="new-system"><?= htmlspecialchars($subclass_name); ?></div>
                </td>
                
                <!-- Size Column - Show from DETAILS2 -->
                <td class="col-size">
                    <div class="hierarchy-label">Size:</div>
                    <div class="new-system"><?= htmlspecialchars($item['DETAILS2']); ?></div>
                </td>
                
                <td class="col-price"><?= number_format($item['PPRICE'], 2); ?></td>
                <td class="col-price"><?= number_format($item['BPRICE'], 2); ?></td>
                <td class="col-price"><?= number_format($item['MPRICE'], 2); ?></td>
                <td class="col-price"><?= number_format($item['RPRICE'], 2); ?></td>
                <td class="col-stock"><?= $opening_balance; ?></td>
                <td class="col-actions">
                  <div class="d-flex gap-1">
                    <a href="edit_item.php?code=<?= urlencode($item['CODE']) ?>&mode=<?= $mode ?>"
                       class="btn btn-sm btn-primary" title="Edit">
                      <i class="fas fa-edit"></i>
                    </a>
                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?= htmlspecialchars($item['DETAILS']) ?>')">
                      <input type="hidden" name="delete_code" value="<?= htmlspecialchars($item['CODE']) ?>">
                      <input type="hidden" name="delete_liq_flag" value="<?= htmlspecialchars($item['LIQ_FLAG']) ?>">
                      <button type="submit" class="btn btn-sm btn-danger" title="Delete">
                        <i class="fas fa-trash"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="13" class="text-center text-muted">No items found.</td>
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
        <h5 class="modal-title" id="importModalLabel">Import Items 
          <span class="badge bg-success">4-Layer System</span>
        </h5>
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
              <li>Class will be auto-detected from ItemName</li>
              <li><strong>CSV columns should be:</strong></li>
              <li>- Size: 330 ML, 650 ML, 50 Ltr, 90 ML-(96), etc. (goes to DETAILS2)</li>
              <li>- Category: Spirit, Wine, Fermented Beer, Mild Beer, etc. (CASE-INSENSITIVE)</li>
              <li>- Class: IMFL, Imported, MML, Indian, etc. (CASE-INSENSITIVE)</li>
              <li>- Subclass: Whisky, Vodka, Rum, Brandy, etc. (CASE-INSENSITIVE)</li>
              <li>Example: "Mild Beer", "MILD BEER", "mild beer" all work the same</li>
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
function confirmDelete(itemName) {
    return confirm('Are you sure you want to delete the item "' + itemName + '"? This action cannot be undone.');
}

// Show loading indicator during import
document.addEventListener('DOMContentLoaded', function() {
    const importForm = document.querySelector('form[enctype="multipart/form-data"]');
    if (importForm) {
        importForm.addEventListener('submit', function() {
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
                    <p class="mt-2">Importing with 4-layer classification...</p>
                </div>
            `;
            document.body.appendChild(loadingOverlay);
        });
    }
});
</script>

</body>
</html>