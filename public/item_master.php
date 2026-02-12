<?php
session_start();

// Remove time limit for long-running imports
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

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
// OPTIMIZED FUNCTIONS FOR BULK OPERATIONS
// ====================================================================

// Function to get or create archive table name
function getArchiveTableName($comp_id, $month) {
    $month_suffix = date('m_y', strtotime($month . '-01'));
    return "tbldailystock_{$comp_id}_{$month_suffix}";
}

// Function to create archive table
function createArchiveTable($conn, $table_name) {
    $create_sql = "CREATE TABLE IF NOT EXISTS $table_name (
        DailyStockID INT AUTO_INCREMENT PRIMARY KEY,
        STK_MONTH VARCHAR(7) NOT NULL,
        ITEM_CODE VARCHAR(20) NOT NULL,
        LIQ_FLAG CHAR(1) DEFAULT 'F',
        CATEGORY_CODE VARCHAR(20),
        CLASS_CODE_NEW VARCHAR(20),
        SUBCLASS_CODE_NEW VARCHAR(20),
        SIZE_CODE VARCHAR(20),
        LAST_UPDATED TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_stock (STK_MONTH, ITEM_CODE, LIQ_FLAG),
        KEY idx_item_code (ITEM_CODE),
        KEY idx_stk_month (STK_MONTH)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    return $conn->query($create_sql);
}

// Function to get correct table for a month
function getTableForMonth($conn, $comp_id, $month) {
    $current_month = date('Y-m');
    
    if ($month == $current_month) {
        // Current month - use main table
        $table_name = "tbldailystock_$comp_id";
        
        // Ensure main table exists
        $check_table = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($check_table->num_rows == 0) {
            createArchiveTable($conn, $table_name);
        }
        
        return $table_name;
    } else {
        // Archive month - use archive table
        $table_name = getArchiveTableName($comp_id, $month);
        
        // Create archive table if it doesn't exist
        $check_table = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($check_table->num_rows == 0) {
            createArchiveTable($conn, $table_name);
        }
        
        return $table_name;
    }
}

// Function to ensure table has day columns
function ensureDayColumns($conn, $table_name, $days_in_month) {
    // Check existing columns
    $existing_columns = [];
    $columns_result = $conn->query("SHOW COLUMNS FROM $table_name");
    while ($row = $columns_result->fetch_assoc()) {
        $existing_columns[] = $row['Field'];
    }
    
    // Add missing day columns
    $alter_sqls = [];
    
    for ($day = 1; $day <= $days_in_month; $day++) {
        $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
        
        $day_cols = [
            "DAY_{$day_padded}_OPEN",
            "DAY_{$day_padded}_PURCHASE", 
            "DAY_{$day_padded}_SALES",
            "DAY_{$day_padded}_CLOSING"
        ];
        
        foreach ($day_cols as $col) {
            if (!in_array($col, $existing_columns)) {
                $alter_sqls[] = "ADD COLUMN $col INT DEFAULT 0";
            }
        }
    }
    
    // Execute ALTER if needed
    if (!empty($alter_sqls)) {
        $alter_query = "ALTER TABLE $table_name " . implode(', ', $alter_sqls);
        $conn->query($alter_query);
    }
}

// Function to update daily stock from start date to today (FIXED VERSION)
function updateDailyStockFromDate($conn, $comp_id, $items_data, $start_date) {
    if (empty($items_data)) return;
    
    // Calculate dates from start_date to today
    $start = new DateTime($start_date);
    $end = new DateTime();
    
    // Group dates by month
    $monthly_dates = [];
    
    $current = clone $start;
    while ($current <= $end) {
        $month = $current->format('Y-m');
        $day = $current->format('d');
        
        if (!isset($monthly_dates[$month])) {
            $monthly_dates[$month] = [];
        }
        $monthly_dates[$month][] = $day;
        
        $current->modify('+1 day');
    }
    
    // Process each month
    foreach ($monthly_dates as $month => $days) {
        // Get correct table for this month
        $table_name = getTableForMonth($conn, $comp_id, $month);
        
        // Get days in this month
        $month_date = DateTime::createFromFormat('Y-m', $month);
        $days_in_month = $month_date->format('t');
        
        // Ensure table has day columns
        ensureDayColumns($conn, $table_name, $days_in_month);
        
        // Process each item for this month
        foreach ($items_data as $item_code => $item_data) {
            $opening_balance = $item_data['balance'];
            $liq_flag = $item_data['liq_flag'];
            $category_code = $item_data['category_code'] ?? '';
            $class_code_new = $item_data['class_code_new'] ?? '';
            $subclass_code_new = $item_data['subclass_code_new'] ?? '';
            $size_code = $item_data['size_code'] ?? '';
            
            // Check if record exists for this month
            $check_sql = "SELECT 1 FROM $table_name WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ? LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("sss", $month, $item_code, $liq_flag);
            $check_stmt->execute();
            $check_stmt->store_result();
            $exists = $check_stmt->num_rows > 0;
            $check_stmt->close();
            
            if ($exists) {
                // Update existing record
                $update_parts = [];
                $update_params = [];
                $update_types = '';
                
                foreach ($days as $day) {
                    $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                    $update_parts[] = "DAY_{$day_padded}_OPEN = ?, DAY_{$day_padded}_PURCHASE = 0, DAY_{$day_padded}_SALES = 0, DAY_{$day_padded}_CLOSING = ?";
                    $update_params[] = $opening_balance;
                    $update_params[] = $opening_balance;
                    $update_types .= 'ii';
                }
                
                $update_sql = "UPDATE $table_name SET " . implode(', ', $update_parts) . 
                             ", CATEGORY_CODE = ?, CLASS_CODE_NEW = ?, SUBCLASS_CODE_NEW = ?, SIZE_CODE = ? 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
                
                $update_params[] = $category_code;
                $update_params[] = $class_code_new;
                $update_params[] = $subclass_code_new;
                $update_params[] = $size_code;
                $update_params[] = $month;
                $update_params[] = $item_code;
                $update_params[] = $liq_flag;
                $update_types .= 'sssssss';
                
                $update_stmt = $conn->prepare($update_sql);
                if ($update_stmt) {
                    $update_stmt->bind_param($update_types, ...$update_params);
                    $update_stmt->execute();
                    $update_stmt->close();
                }
            } else {
                // Insert new record
                $columns = ['STK_MONTH', 'ITEM_CODE', 'LIQ_FLAG', 'CATEGORY_CODE', 'CLASS_CODE_NEW', 'SUBCLASS_CODE_NEW', 'SIZE_CODE'];
                $placeholders = ['?', '?', '?', '?', '?', '?', '?'];
                $insert_params = [$month, $item_code, $liq_flag, $category_code, $class_code_new, $subclass_code_new, $size_code];
                $insert_types = 'sssssss';
                
                // Add all day columns for this month
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
                    
                    // Set values for the specific days in our range
                    if (in_array($day, $days)) {
                        $insert_params[] = $opening_balance;  // OPEN
                        $insert_params[] = 0;                 // PURCHASE
                        $insert_params[] = 0;                 // SALES
                        $insert_params[] = $opening_balance;  // CLOSING
                    } else {
                        $insert_params[] = 0;  // OPEN
                        $insert_params[] = 0;  // PURCHASE
                        $insert_params[] = 0;  // SALES
                        $insert_params[] = 0;  // CLOSING
                    }
                    $insert_types .= 'iiii';
                }
                
                $insert_sql = "INSERT INTO $table_name (" . implode(', ', $columns) . 
                             ") VALUES (" . implode(', ', $placeholders) . ")";
                
                $insert_stmt = $conn->prepare($insert_sql);
                if ($insert_stmt) {
                    $insert_stmt->bind_param($insert_types, ...$insert_params);
                    $insert_stmt->execute();
                    $insert_stmt->close();
                }
            }
        }
        
        // Keep connection alive
        $conn->ping();
    }
}

// Function to bulk insert items with FIXED stock table handling
function bulkInsertItems($conn, $items, $comp_id, $fin_year, $start_date) {
    if (empty($items)) return ['imported' => 0, 'updated' => 0];
    
    $imported = 0;
    $updated = 0;
    $batch_size = 100;
    
    // Prepare daily stock data
    $daily_stock_data = [];
    
    // Split items into batches
    $item_batches = array_chunk($items, $batch_size);
    
    foreach ($item_batches as $batch_index => $batch) {
        // Keep MySQL connection alive
        $conn->ping();
        
        foreach ($batch as $item) {
            $code = $item['code'];
            $liq_flag = $item['liq_flag'];
            $opening_balance = $item['opening_balance'];
            $barcode = $item['barcode'] ?? '';
            
            // Check if item exists in tblitemmaster
            $check_sql = "SELECT CODE FROM tblitemmaster WHERE CODE = ? AND LIQ_FLAG = ? LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("ss", $code, $liq_flag);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            $exists = $check_result->num_rows > 0;
            $check_stmt->close();
            
            if ($exists) {
                // Update existing item in tblitemmaster (INCLUDING BARCODE)
                $update_sql = "UPDATE tblitemmaster SET 
                    Print_Name = ?, DETAILS = ?, DETAILS2 = ?, CLASS = ?, ITEM_GROUP = ?,
                    PPRICE = ?, BPRICE = ?, MPRICE = ?, RPRICE = ?,
                    CATEGORY_CODE = ?, CLASS_CODE_NEW = ?, SUBCLASS_CODE_NEW = ?, SIZE_CODE = ?, BARCODE = ?
                    WHERE CODE = ? AND LIQ_FLAG = ?";
                
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param(
                    "sssssddddssssssss",
                    $item['print_name'], $item['item_name'], $item['size'], $item['class'],
                    $item['item_group'], $item['pprice'], $item['bprice'], $item['mprice'], $item['rprice'],
                    $item['category_code'], $item['class_code_new'], $item['subclass_code_new'], $item['size_code'],
                    $barcode,
                    $code, $liq_flag
                );
                
                if ($update_stmt->execute()) {
                    $updated++;
                }
                $update_stmt->close();
            } else {
                // Insert new item into tblitemmaster (INCLUDING BARCODE)
                $insert_sql = "INSERT INTO tblitemmaster 
                    (CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                     PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG, BARCODE,
                     CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param(
                    "sssssddddsssssss",
                    $code, $item['print_name'], $item['item_name'], $item['size'], 
                    $item['class'], $item['item_group'],
                    $item['pprice'], $item['bprice'], $item['mprice'], $item['rprice'], $liq_flag, $barcode,
                    $item['category_code'], $item['class_code_new'], $item['subclass_code_new'], $item['size_code']
                );
                
                if ($insert_stmt->execute()) {
                    $imported++;
                }
                $insert_stmt->close();
            }
            
            // FIXED: Update stock table with proper ON DUPLICATE KEY UPDATE
            // First, check if tblitem_stock has the right columns
            $check_col_sql = "SHOW COLUMNS FROM tblitem_stock LIKE 'OPENING_STOCK$comp_id'";
            $col_result = $conn->query($check_col_sql);
            if ($col_result->num_rows == 0) {
                // Add column if it doesn't exist
                $add_col_sql = "ALTER TABLE tblitem_stock ADD COLUMN OPENING_STOCK$comp_id INT DEFAULT 0, ADD COLUMN CURRENT_STOCK$comp_id INT DEFAULT 0";
                $conn->query($add_col_sql);
            }
            
            // Now insert/update with proper ON DUPLICATE KEY UPDATE
            // Note: tblitem_stock should have a unique key on ITEM_CODE
            $stock_sql = "INSERT INTO tblitem_stock 
                (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id,
                 CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                OPENING_STOCK$comp_id = VALUES(OPENING_STOCK$comp_id),
                CURRENT_STOCK$comp_id = VALUES(CURRENT_STOCK$comp_id),
                CATEGORY_CODE = VALUES(CATEGORY_CODE),
                CLASS_CODE_NEW = VALUES(CLASS_CODE_NEW),
                SUBCLASS_CODE_NEW = VALUES(SUBCLASS_CODE_NEW),
                SIZE_CODE = VALUES(SIZE_CODE)";
            
            $stock_stmt = $conn->prepare($stock_sql);
            if ($stock_stmt) {
                $stock_stmt->bind_param(
                    "siiissss",
                    $code, $fin_year, $opening_balance, $opening_balance,
                    $item['category_code'], $item['class_code_new'], $item['subclass_code_new'], $item['size_code']
                );
                $stock_stmt->execute();
                
                // Check if duplicate entry error
                if ($stock_stmt->errno == 1062) {
                    // Duplicate key - update instead
                    $update_stock_sql = "UPDATE tblitem_stock SET 
                        OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ?,
                        CATEGORY_CODE = ?, CLASS_CODE_NEW = ?, SUBCLASS_CODE_NEW = ?, SIZE_CODE = ?
                        WHERE ITEM_CODE = ?";
                    
                    $update_stock_stmt = $conn->prepare($update_stock_sql);
                    $update_stock_stmt->bind_param(
                        "iisssss",
                        $opening_balance, $opening_balance,
                        $item['category_code'], $item['class_code_new'], $item['subclass_code_new'], $item['size_code'],
                        $code
                    );
                    $update_stock_stmt->execute();
                    $update_stock_stmt->close();
                }
                
                $stock_stmt->close();
            }
            
            // Prepare data for daily stock update
            $daily_stock_data[$code] = [
                'balance' => $opening_balance,
                'liq_flag' => $liq_flag,
                'category_code' => $item['category_code'],
                'class_code_new' => $item['class_code_new'],
                'subclass_code_new' => $item['subclass_code_new'],
                'size_code' => $item['size_code']
            ];
        }
    }
    
    // Update daily stock for all items
    if (!empty($daily_stock_data)) {
        updateDailyStockFromDate($conn, $comp_id, $daily_stock_data, $start_date);
    }
    
    return ['imported' => $imported, 'updated' => $updated];
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

            // Delete from all daily stock tables (current and archive)
            // First, get all tables starting with tbldailystock_
            $tables_query = "SHOW TABLES LIKE 'tbldailystock_%'";
            $tables_result = $conn->query($tables_query);
            
            while ($table_row = $tables_result->fetch_array()) {
                $table_name = $table_row[0];
                $delete_daily_stmt = $conn->prepare("DELETE FROM $table_name WHERE ITEM_CODE = ? AND LIQ_FLAG = ?");
                $delete_daily_stmt->bind_param("ss", $delete_code, $delete_liq_flag);
                $delete_daily_stmt->execute();
                $delete_daily_stmt->close();
            }

            $conn->commit();
            $deleteMessage = "Item '$delete_code' deleted successfully from all tables.";
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

// Pagination setup
$limit = 50; // Items per page
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Check if company columns exist in tblitem_stock, if not create them
$check_columns_query = "SELECT COUNT(*) as count FROM information_schema.columns
                       WHERE table_name = 'tblitem_stock'
                       AND column_name = 'OPENING_STOCK$comp_id'";
$check_result = $conn->query($check_columns_query);
$opening_col_exists = $check_result->fetch_assoc()['count'] > 0;

if (!$opening_col_exists) {
    $add_col1_query = "ALTER TABLE tblitem_stock ADD COLUMN OPENING_STOCK$comp_id INT DEFAULT 0, ADD COLUMN CURRENT_STOCK$comp_id INT DEFAULT 0";
    $conn->query($add_col1_query);
}

// Ensure main daily stock table exists
$main_table_name = "tbldailystock_$comp_id";
$check_main_table = $conn->query("SHOW TABLES LIKE '$main_table_name'");
if ($check_main_table->num_rows == 0) {
    createArchiveTable($conn, $main_table_name);
}

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    
    // Fetch items from tblitemmaster - FILTERED BY LICENSE TYPE
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                         PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG, BARCODE,
                         CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE
                  FROM tblitemmaster
                  WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
        
        $params = array_merge([$mode], $allowed_classes);
        $types = str_repeat('s', count($params));
    } else {
        $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                         PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG, BARCODE,
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
        
        // CSV headers - INCLUDING BARCODE as last column
        fputcsv($output, array('Code', 'ItemName', 'PrintName', 'Size', 
                               'PPrice', 'BPrice', 'MPrice', 'RPrice', 'LIQFLAG', 
                               'OpeningBalance', 'Category', 'Class', 'Subclass', 'Barcode'));
        
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
                'Subclass' => $subclass_name, // This is now the actual subclass (Whisky, Vodka, etc.)
                'Barcode' => $item['BARCODE'] ?? '' // Include barcode as last column
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
    
    // Get start date for opening balance
    $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '-1');
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $filePath = $file['tmp_name'];
        $fileName = $file['name'];
        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
        
        try {
            if ($importType === 'csv' && $fileExt === 'csv') {
                // Start transaction
                $conn->begin_transaction();
                
                // IMPORTANT: Set MySQL timeouts for long operations
                $conn->query("SET SESSION wait_timeout = 28800");
                $conn->query("SET SESSION interactive_timeout = 28800");
                $conn->query("SET SESSION net_read_timeout = 28800");
                $conn->query("SET SESSION net_write_timeout = 28800");
                
                // Disable foreign key checks temporarily
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                $conn->query("SET UNIQUE_CHECKS = 0");
                $conn->query("SET AUTOCOMMIT = 0");
                
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
                    
                    // Define expected columns with flexible matching - INCLUDING BARCODE
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
                        'subclass' => ['subclass', 'subclass', 'sub_class'],
                        'barcode' => ['barcode', 'bar_code', 'barcodeno'] // BARCODE column
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
                    
                    $items_to_process = [];
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

                            // Get barcode (last column) - can be empty
                            $barcode = '';
                            if (isset($data[$column_indices['barcode']])) {
                                $barcode = trim($data[$column_indices['barcode']]);
                                // Trim to 15 characters as per database schema
                                if (strlen($barcode) > 15) {
                                    $barcode = substr($barcode, 0, 15);
                                }
                            }

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

                            // Add to items to process
                            $items_to_process[] = [
                                'code' => $code,
                                'print_name' => $printName,
                                'item_name' => $itemName,
                                'size' => $size,
                                'class' => $detectedClass,
                                'item_group' => $itemGroup,
                                'pprice' => $pprice,
                                'bprice' => $bprice,
                                'mprice' => $mprice,
                                'rprice' => $rprice,
                                'liq_flag' => $liqFlag,
                                'opening_balance' => $openingBalance,
                                'category_code' => $categoryCode,
                                'class_code_new' => $classCodeNew,
                                'subclass_code_new' => $subclassCodeNew,
                                'size_code' => $sizeCode,
                                'barcode' => $barcode // Include barcode
                            ];
                        } else {
                            $errors++;
                            $errorDetails[] = "Row $rowCount: Insufficient columns";
                        }
                    }
                    fclose($handle);
                    
                    // Bulk process items
                    $result = bulkInsertItems($conn, $items_to_process, $comp_id, $fin_year, $start_date);
                    $imported = $result['imported'];
                    $updated = $result['updated'];
                    
                    // Re-enable constraints
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    $conn->query("SET UNIQUE_CHECKS = 1");
                    $conn->query("SET AUTOCOMMIT = 1");
                    
                    // Commit transaction
                    $conn->commit();
                    
                    $importMessage = "Import completed: $imported new items imported, $updated items updated, $errors errors. Opening balances set from $start_date to today.";
                    if ($errors > 0) {
                        $importMessage .= " First few errors: " . implode("; ", array_slice($errorDetails, 0, 5));
                    }
                    
                    // Add performance info
                    $execution_time = microtime(true) - $_SERVER["REQUEST_TIME_FLOAT"];
                    $items_per_second = round(($imported + $updated) / $execution_time, 2);
                    $importMessage .= " [Performance: $items_per_second items/second]";
                    
                    // Debug info about tables used
                    $current_month = date('Y-m');
                    if ($start_date < $current_month) {
                        $start_month = date('Y-m', strtotime($start_date));
                        $importMessage .= " [Tables used: Main table for $current_month, Archive tables for $start_month to " . date('Y-m', strtotime('-1 month', strtotime($current_month))) . "]";
                    } else {
                        $importMessage .= " [Table used: Main table only for $current_month]";
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
                $conn->query("SET UNIQUE_CHECKS = 1");
                $conn->query("SET AUTOCOMMIT = 1");
            }
            $importMessage = "Error during import: " . $e->getMessage() . " (Line: " . $e->getLine() . ")";
        }
    } else {
        $importMessage = "Error uploading file: " . $file['error'];
    }
}

// Get total count for pagination
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $count_query = "SELECT COUNT(*) as total 
                   FROM tblitemmaster
                   WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
    
    $count_params = array_merge([$mode], $allowed_classes);
    $count_types = str_repeat('s', count($count_params));
} else {
    $count_query = "SELECT COUNT(*) as total 
                   FROM tblitemmaster
                   WHERE 1 = 0";
    $count_params = [$mode];
    $count_types = "s";
}

if ($search !== '') {
    $count_query .= " AND (DETAILS LIKE ? OR CODE LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_types .= "ss";
}

$count_stmt = $conn->prepare($count_query);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_row = $count_result->fetch_assoc();
$total_items = $total_row['total'];
$count_stmt->close();

// Calculate total pages
$total_pages = ceil($total_items / $limit);

// Fetch items for display with pagination - FILTERED BY LICENSE TYPE
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                     PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG, BARCODE,
                     CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE
              FROM tblitemmaster
              WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
    
    $params = array_merge([$mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
    $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, 
                     PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG, BARCODE,
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

$query .= " ORDER BY DETAILS ASC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

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
    /* Compact table styling with original colors */
    .table-container {
        overflow-x: auto;
        max-width: 100%;
    }
    .styled-table {
        font-size: 0.85rem;
        width: 100%;
        min-width: 1200px;
        border-collapse: separate;
        border-spacing: 0;
    }
    .styled-table th {
        white-space: nowrap;
        padding: 8px 5px;
        background-color: #f8f9fa; /* Original header color */
        border-bottom: 2px solid #dee2e6;
        font-weight: 600;
        position: sticky;
        top: 0;
        z-index: 10;
    }
    .styled-table td {
        padding: 6px 4px;
        vertical-align: middle;
        border-bottom: 1px solid #dee2e6;
    }
    .styled-table tbody tr:nth-child(even) {
        background-color: #f8f9fa; /* Original striped color */
    }
    .styled-table tbody tr:hover {
        background-color: #e9ecef; /* Original hover color */
    }
    
    /* Column width classes (similar to original) */
    .col-code { width: 80px; }
    .col-item-name { width: 200px; }
    .col-print-name { width: 100px; }
    .col-category { width: 120px; }
    .col-class { width: 120px; }
    .col-subclass { width: 120px; }
    .col-size { width: 100px; }
    .col-price { width: 70px; text-align: right; }
    .col-stock { width: 60px; text-align: center; }
    .col-barcode { width: 100px; }
    .col-actions { width: 100px; }
    
    /* Compact text (similar to original) */
    .compact-text {
        font-size: 0.8rem;
        line-height: 1.2;
    }
    
    /* Original color for classification data */
    .new-system {
        color: #198754; /* Original green color */
        font-size: 0.8rem;
        font-weight: 500;
    }
    
    /* Classification data without labels */
    .classification-data {
        font-size: 0.8rem;
        color: #198754; /* Same green as original new-system */
        font-weight: 500;
    }
    
    /* Remove the hierarchy-label class that was showing "Category:", "Class:", etc. */
    
    .date-field {
        max-width: 200px;
    }
    .pagination-container {
        display: flex;
        justify-content: center;
        margin-top: 20px;
    }
    .page-info {
        text-align: center;
        margin: 10px 0;
        color: #6c757d;
    }
    .pagination .page-link {
        padding: 5px 10px;
        font-size: 0.9rem;
    }
    .page-size-selector {
        max-width: 100px;
        display: inline-block;
        margin-left: 10px;
    }
  </style>
<script>
function downloadTemplate() {
    const headers = ['Code', 'ItemName', 'PrintName', 'Size', 
                    'PPrice', 'BPrice', 'MPrice', 'RPrice', 'LIQFLAG', 
                    'OpeningBalance', 'Category', 'Class', 'Subclass', 'Barcode'];
    
    // Create examples with actual NAMES
    const exampleRows = [
        // Beer Examples with proper subclasses
        ['SCMBR0009735', 'Budweiser Premium King of Beer', '', '330 ML', 
         '80.000', '70.000', '100.000', '120.000', 'F', '0',
         'Mild Beer', 'Mild Beer', 'Mild Beer', '8901234567890'],
        ['SCMBR0009846', 'Kingfisher Strong Premium Beer', '', '650 ML', 
         '120.000', '100.000', '130.000', '150.000', 'F', '0',
         'Fermented Beer', 'Fermented Beer', 'Fermented Beer', '8901234567891'],
        ['SCMBR0009787', 'FOSTERS LAGAR BEER', '', '50 Ltr', 
         '80.000', '70.000', '100.000', '120.000', 'F', '0',
         'Mild Beer', 'Mild Beer', 'Mild Beer', ''],
        
        // Whisky Examples with proper subclasses
        ['WHISKY001', 'Johnnie Walker Red Label Whisky', 'JW Red Label', '750ML', 
         '2500.000', '2200.000', '2800.000', '2600.000', 'F', '50',
         'Spirit', 'IMFL', 'Whisky', '8901234567892'],
        ['WHISKY002', '8PM Premium Whisky', '8PM Premium', '90ML', 
         '120.000', '100.000', '150.000', '130.000', 'F', '100',
         'Spirit', 'IMFL', 'Whisky', ''],
        
        // Wine Example with proper subclass
        ['WINE001', 'Sula Chenin Blanc White Wine', 'Sula White', '750ML', 
         '800.000', '700.000', '1000.000', '900.000', 'F', '30',
         'Wine', 'Indian', 'Indian', '8901234567893'],
        
        // Brandy Example with proper subclass
        ['BRANDY001', 'Hennessy VSOP Cognac', 'Hennessy VSOP', '750ML', 
         '8500.000', '8000.000', '10000.000', '9500.000', 'F', '15',
         'Spirit', 'IMFL', 'Brandy', ''],
        
        // Vodka Example with proper subclass
        ['VODKA001', 'Smirnoff Red Label Vodka', 'Smirnoff Red', '750ML', 
         '900.000', '800.000', '1100.000', '1000.000', 'F', '40',
         'Spirit', 'IMFL', 'Vodka', '8901234567894'],
        
        // Rum Example with proper subclass
        ['RUM001', 'Old Monk Supreme Rum', 'Old Monk', '750ML', 
         '600.000', '500.000', '750.000', '650.000', 'F', '60',
         'Spirit', 'IMFL', 'Rum', ''],
        
        // Minimal example (4-layer fields optional)
        ['LIQ001', 'Generic Liquor', 'Generic', '180 ML', 
         '100.000', '90.000', '120.000', '110.000', 'F', '50',
         '', '', '', '8901234567895']
    ];
    
    let csvContent = "Opening Balance Start Date: YYYY-MM-DD\n";
    csvContent += headers.join(',') + '\r\n';
    
    exampleRows.forEach(row => {
        csvContent += row.join(',') + '\r\n';
    });
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    
    link.setAttribute('href', url);
    link.setAttribute('download', 'item_import_template_with_barcode.csv');
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    setTimeout(() => {
        URL.revokeObjectURL(url);
    }, 100);
}

// Change page size
function changePageSize(size) {
    const url = new URL(window.location.href);
    url.searchParams.set('limit', size);
    url.searchParams.set('page', 1); // Reset to first page
    window.location.href = url.toString();
}

// Jump to page
function goToPage(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    window.location.href = url.toString();
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
        <span class="badge bg-success">Page <?= $page ?> of <?= $total_pages ?></span>
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
          <a href="?mode=F&search=<?= urlencode($search) ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&search=<?= urlencode($search) ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&search=<?= urlencode($search) ?>&page=1"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>
      
      <!-- Import/Export Buttons -->
      <div class="import-export-buttons">
        <div class="btn-group">
          <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importModal" onclick="prepareImport()">
            <i class="fas fa-file-import"></i> Import with Opening Balance Date
          </button>
          <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&export=csv" class="btn btn-info">
            <i class="fas fa-file-export"></i> Export CSV
          </a>
        </div>
      </div>

      <div class="import-template">
          <p><strong>Fixed Import Features with Barcode Support:</strong></p>
          <ul>
              <li><strong>Barcode Support:</strong> Accepts barcode as last column (15 chars max, can be empty)</li>
              <li><strong>No Duplicates:</strong> Proper ON DUPLICATE KEY UPDATE for tblitem_stock</li>
              <li><strong>Archive Tables:</strong> Creates archive tables for past months (tbldailystock_<?= $comp_id ?>_mm_yy)</li>
              <li><strong>Correct Distribution:</strong> Dates go to correct monthly tables</li>
              <li><strong>Bulk Processing:</strong> Processes items in batches of 100</li>
              <li><strong>Required columns:</strong> Code, ItemName, PrintName, Size, PPrice, BPrice, MPrice, RPrice, LIQFLAG, OpeningBalance</li>
              <li><strong>Optional columns:</strong> Category, Class, Subclass, Barcode</li>
          </ul>
       
          <div class="download-template">
              <a href="javascript:void(0);" onclick="downloadTemplate()" class="btn btn-sm btn-outline-secondary">
                  <i class="fas fa-download"></i> Download Template with Barcode
              </a>
              <small class="text-muted ms-2">Barcode support added - can be empty</small>
          </div>
      </div>

      <!-- Search -->
      <form method="GET" class="search-control mb-3">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <input type="hidden" name="page" value="1">
        <div class="input-group">
          <input type="text" name="search" class="form-control"
                 placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Find
          </button>
          <?php if ($search !== ''): ?>
            <a href="?mode=<?= $mode ?>&page=1" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Page Info -->
      <div class="page-info">
        Showing <?= min($limit, count($items)) ?> of <?= $total_items ?> items
        <select class="form-select form-select-sm page-size-selector" onchange="changePageSize(this.value)">
          <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20 per page</option>
          <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50 per page</option>
          <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100 per page</option>
          <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200 per page</option>
        </select>
      </div>
      
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
              <th class="col-barcode">Barcode</th> <!-- New Barcode column -->
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
              
              // Format prices as integers (remove decimals)
              $pprice_int = intval($item['PPRICE']);
              $bprice_int = intval($item['BPRICE']);
              $mprice_int = intval($item['MPRICE']);
              $rprice_int = intval($item['RPRICE']);
              ?>
              <tr class="compact-text">
                <td class="col-code"><?= htmlspecialchars($item['CODE']); ?></td>
                <td class="col-item-name"><?= htmlspecialchars($item['DETAILS']); ?></td>
                <td class="col-print-name"><?= htmlspecialchars($item['Print_Name']); ?></td>
                
                <!-- Category Column - Show NAME only (no label) -->
                <td class="col-category classification-data">
                    <?= htmlspecialchars($category_name); ?>
                </td>
                
                <!-- Class Column - Show NAME only (no label) -->
                <td class="col-class classification-data">
                    <?= htmlspecialchars($class_name); ?>
                </td>
                
                <!-- Subclass Column - Show NAME only (no label) -->
                <td class="col-subclass classification-data">
                    <?= htmlspecialchars($subclass_name); ?>
                </td>
                
                <!-- Size Column - Show from DETAILS2 (no label) -->
                <td class="col-size classification-data">
                    <?= htmlspecialchars($item['DETAILS2']); ?>
                </td>
                
                <!-- Prices as integers -->
                <td class="col-price"><?= number_format($pprice_int, 0); ?></td>
                <td class="col-price"><?= number_format($bprice_int, 0); ?></td>
                <td class="col-price"><?= number_format($mprice_int, 0); ?></td>
                <td class="col-price"><?= number_format($rprice_int, 0); ?></td>
                
                <td class="col-stock"><?= $opening_balance; ?></td>
                
                <!-- Barcode Column -->
                <td class="col-barcode classification-data">
                    <?= htmlspecialchars($item['BARCODE'] ?? ''); ?>
                </td>
                
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
              <td colspan="14" class="text-center text-muted">No items found.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="pagination-container">
        <nav aria-label="Page navigation">
          <ul class="pagination">
            <!-- First Page -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=1" aria-label="First">
                <span aria-hidden="true">&laquo;&laquo;</span>
              </a>
            </li>
            
            <!-- Previous Page -->
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
              <a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= max(1, $page - 1) ?>" aria-label="Previous">
                <span aria-hidden="true">&laquo;</span>
              </a>
            </li>
            
            <!-- Page Numbers -->
            <?php 
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++): 
            ?>
              <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                <a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>">
                  <?= $i ?>
                </a>
              </li>
            <?php endfor; ?>
            
            <!-- Next Page -->
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
              <a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= min($total_pages, $page + 1) ?>" aria-label="Next">
                <span aria-hidden="true">&raquo;</span>
              </a>
            </li>
            
            <!-- Last Page -->
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
              <a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= $total_pages ?>" aria-label="Last">
                <span aria-hidden="true">&raquo;&raquo;</span>
              </a>
            </li>
          </ul>
        </nav>
      </div>
      
      <!-- Page Jump Form -->
      <div class="row justify-content-center mt-3">
        <div class="col-md-4">
          <div class="input-group">
            <input type="number" id="jumpPage" class="form-control" min="1" max="<?= $total_pages ?>" placeholder="Page #">
            <button class="btn btn-outline-secondary" type="button" onclick="goToPage(document.getElementById('jumpPage').value)">
              Go
            </button>
          </div>
          <div class="form-text text-center">
            Page <?= $page ?> of <?= $total_pages ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Import Modal with Opening Balance Date Field -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="importModalLabel">Bulk Import Items
          <span class="badge bg-success">Fixed Table Distribution</span>
          <span class="badge bg-info">Barcode Support</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" enctype="multipart/form-data" id="importForm">
        <div class="modal-body">
          <div class="mb-3">
            <label for="importFile" class="form-label">Select CSV file to import</label>
            <input class="form-control" type="file" id="importFile" name="import_file" required accept=".csv">
            <div class="form-text">Supports CSV files with thousands of items</div>
          </div>
          
          <!-- Opening Balance Date Field -->
          <div class="mb-3">
            <label for="start_date" class="form-label">Opening Balance Start Date</label>
            <input type="date" class="form-control date-field" id="start_date" name="start_date" value="<?= date('Y-m-d') ?>" required>
            <div class="form-text">Opening balance will be cascaded from this date through today (uses correct monthly tables)</div>
          </div>
          
          <input type="hidden" name="import_type" value="csv">
          <div class="alert alert-warning">
            <strong><i class="fas fa-database"></i> Fixed Table System with Barcode Support</strong>
            <ul class="mb-0 mt-2">
              <li>Barcode column is optional (15 characters max)</li>
              <li>Current month: tbldailystock_<?= $comp_id ?></li>
              <li>Archive months: tbldailystock_<?= $comp_id ?>_mm_yy</li>
              <li>No duplicate entries in tblitem_stock</li>
              <li>Proper ON DUPLICATE KEY UPDATE handling</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="importSubmitBtn">
            <i class="fas fa-rocket"></i> Start Fixed Import
          </button>
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
function prepareImport() {
    const importForm = document.getElementById('importForm');
    if (importForm) {
        importForm.addEventListener('submit', function(e) {
            const fileInput = document.getElementById('importFile');
            const startDate = document.getElementById('start_date');
            
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Please select a CSV file to import.');
                return;
            }
            
            if (!startDate.value) {
                e.preventDefault();
                alert('Please select an opening balance start date.');
                return;
            }
            
            // Show loading overlay
            const loadingOverlay = document.createElement('div');
            loadingOverlay.style.position = 'fixed';
            loadingOverlay.style.top = '0';
            loadingOverlay.style.left = '0';
            loadingOverlay.style.width = '100%';
            loadingOverlay.style.height = '100%';
            loadingOverlay.style.backgroundColor = 'rgba(255,255,255,0.98)';
            loadingOverlay.style.zIndex = '9999';
            loadingOverlay.style.display = 'flex';
            loadingOverlay.style.justifyContent = 'center';
            loadingOverlay.style.alignItems = 'center';
            loadingOverlay.innerHTML = `
                <div class="text-center p-5" style="max-width: 600px;">
                    <div class="spinner-border text-primary" style="width: 4rem; height: 4rem;" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <h3 class="mt-4">Fixed Import in Progress</h3>
                    <div class="mt-4">
                        <div class="progress" style="height: 30px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" 
                                 role="progressbar" style="width: 100%">
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <i class="fas fa-database fa-2x text-primary mb-2"></i>
                                        <h5>No Duplicates</h5>
                                        <p class="small">Proper tblitem_stock handling</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-body">
                                        <i class="fas fa-barcode fa-2x text-success mb-2"></i>
                                        <h5>Barcode Support</h5>
                                        <p class="small">Optional barcode import</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Fixed Import System</strong><br>
                        • Barcode support added (optional)<br>
                        • No duplicate entries in stock table<br>
                        • Dates distributed to correct monthly tables<br>
                        • Archive tables created automatically
                    </div>
                </div>
            `;
            document.body.appendChild(loadingOverlay);
            
            // Disable submit button
            const submitBtn = document.getElementById('importSubmitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-sync fa-spin"></i> Processing...';
            }
        });
    }
    
    // Set max date to today
    const startDateInput = document.getElementById('start_date');
    if (startDateInput) {
        const today = new Date().toISOString().split('T')[0];
        startDateInput.max = today;
        if (!startDateInput.value) {
            startDateInput.value = today;
        }
    }
}

// Auto-hide alerts after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(() => {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
    
    // Initialize import form
    prepareImport();
});
</script>

</body>
</html>