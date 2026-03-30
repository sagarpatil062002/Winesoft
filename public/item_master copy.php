<?php
session_start();

// Remove time limit for long-running imports
set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
include_once "../vendor/autoload.php"; // PhpSpreadsheet autoload

// Function to get financial year from a date
function getFinancialYearFromDate($date) {
    $year = date('Y', strtotime($date));
    $month = date('m', strtotime($date));
    
    // If month is April (04) or later, financial year starts in current year
    if ($month >= 4) {
        return $year . '-' . ($year + 1);
    } else {
        // If month is January-March, financial year started in previous year
        return ($year - 1) . '-' . $year;
    }
}

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Cache arrays for faster lookups
$category_cache = [];
$class_cache = [];
$subclass_cache = [];
$size_cache = [];

// Preload all classification data for faster lookups
function preloadClassificationData($conn) {
    global $category_cache, $class_cache, $subclass_cache, $size_cache;
    
    // Preload all categories
    $cat_result = $conn->query("SELECT CATEGORY_CODE, UPPER(CATEGORY_NAME) as CAT_NAME FROM tblcategory");
    while ($row = $cat_result->fetch_assoc()) {
        $category_cache[$row['CAT_NAME']] = $row['CATEGORY_CODE'];
    }
    
    // Preload all classes
    $class_result = $conn->query("SELECT CLASS_CODE, UPPER(CLASS_NAME) as CLASS_NAME FROM tblclass_new");
    while ($row = $class_result->fetch_assoc()) {
        $class_cache[$row['CLASS_NAME']] = $row['CLASS_CODE'];
    }
    
    // Preload all subclasses
    $sub_result = $conn->query("SELECT SUBCLASS_CODE, UPPER(SUBCLASS_NAME) as SUB_NAME FROM tblsubclass_new");
    while ($row = $sub_result->fetch_assoc()) {
        $subclass_cache[$row['SUB_NAME']] = $row['SUBCLASS_CODE'];
    }
    
    // Preload all sizes
    $size_result = $conn->query("SELECT SIZE_CODE, UPPER(SIZE_DESC) as SIZE_DESC FROM tblsize");
    while ($row = $size_result->fetch_assoc()) {
        $size_cache[$row['SIZE_DESC']] = $row['SIZE_CODE'];
    }
}

// Preload classification data
preloadClassificationData($conn);

// Function to get category code from category name
function getCategoryCodeByName($category_name, $conn) {
    global $category_cache;
    if (empty($category_name)) return '';
    $key = strtoupper(trim($category_name));
    return isset($category_cache[$key]) ? $category_cache[$key] : '';
}

// Function to get class code from class name
function getClassCodeByName($class_name, $conn) {
    global $class_cache;
    if (empty($class_name)) return '';
    $key = strtoupper(trim($class_name));
    return isset($class_cache[$key]) ? $class_cache[$key] : '';
}

// Function to get subclass code from subclass name
function getSubclassCodeByName($subclass_name, $conn) {
    global $subclass_cache;
    if (empty($subclass_name)) return '';
    $key = strtoupper(trim($subclass_name));
    return isset($subclass_cache[$key]) ? $subclass_cache[$key] : '';
}

// Function to get size code from size description
function getSizeCodeByDescription($size_desc, $conn) {
    global $size_cache;
    if (empty($size_desc)) return '';
    $key = strtoupper(trim($size_desc));
    return isset($size_cache[$key]) ? $size_cache[$key] : '';
}

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

// Function to get class name from class code
function getClassName($class_code, $conn) {
    if (empty($class_code)) return 'N/A';
    $query = "SELECT CLASS_NAME FROM tblclass_new WHERE CLASS_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $class_code);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['CLASS_NAME'];
    }
    return $class_code;
}

// Function to get subclass name from subclass code
function getSubclassName($subclass_code, $conn) {
    if (empty($subclass_code)) return 'N/A';
    $query = "SELECT SUBCLASS_NAME FROM tblsubclass_new WHERE SUBCLASS_CODE = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $subclass_code);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['SUBCLASS_NAME'];
    }
    return $subclass_code;
}

// Function to get size description from size code
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

// Function to detect class from item name (returns single character class code)
function detectClassFromItemName($itemName, $liqFlag = 'F') {
    $itemName = strtoupper($itemName);
    
    if ($liqFlag === 'C') {
        return 'L';
    }
    
    if (strpos($itemName, 'WHISKY') !== false || strpos($itemName, 'WHISKEY') !== false || strpos($itemName, 'SCOTCH') !== false) {
        return 'W';
    }
    
    if (strpos($itemName, 'WINE') !== false || strpos($itemName, 'SULA') !== false) {
        return 'V';
    }
    
    if (strpos($itemName, 'BRANDY') !== false || strpos($itemName, 'COGNAC') !== false) {
        return 'D';
    }
    
    if (strpos($itemName, 'VODKA') !== false) {
        return 'K';
    }
    
    if (strpos($itemName, 'GIN') !== false) {
        return 'G';
    }
    
    if (strpos($itemName, 'RUM') !== false) {
        return 'R';
    }
    
    if (strpos($itemName, 'BEER') !== false || strpos($itemName, 'LAGER') !== false) {
        if (strpos($itemName, 'STRONG') !== false) {
            return 'F';
        } else {
            return 'M';
        }
    }
    
    return 'O';
}

// ====================================================================
// FUNCTIONS FOR DAILY STOCK UPDATE
// ====================================================================
// ====================================================================
// FUNCTIONS FOR DAILY STOCK UPDATE
// ====================================================================

// Function to ensure main daily stock table exists (with backward compatibility)
function ensureDailyStockTable($conn, $comp_id) {
    $table_name = "tbldailystock_$comp_id";
    $check_table = $conn->query("SHOW TABLES LIKE '$table_name'");
    if ($check_table->num_rows == 0) {
        // Create new table with STK_DATE support
        $create_sql = "CREATE TABLE IF NOT EXISTS $table_name (
            DailyStockID INT AUTO_INCREMENT PRIMARY KEY,
            STK_DATE DATE NOT NULL,
            STK_MONTH VARCHAR(7) NOT NULL,
            ITEM_CODE VARCHAR(20) NOT NULL,
            LIQ_FLAG CHAR(1) DEFAULT 'F',
            CATEGORY_CODE VARCHAR(10),
            CLASS_CODE_NEW VARCHAR(10),
            SUBCLASS_CODE_NEW VARCHAR(10),
            SIZE_CODE VARCHAR(10),
            OPENING_BALANCE INT DEFAULT 0,
            LAST_UPDATED TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_stock (STK_DATE, ITEM_CODE, LIQ_FLAG),
            INDEX idx_stk_month (STK_MONTH),
            INDEX idx_item_code (ITEM_CODE)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $conn->query($create_sql);
    } else {
        // Check if STK_DATE column exists, if not add it
        $check_column = $conn->query("SHOW COLUMNS FROM $table_name LIKE 'STK_DATE'");
        if ($check_column->num_rows == 0) {
            // Add STK_DATE column and update existing data
            $conn->query("ALTER TABLE $table_name ADD COLUMN STK_DATE DATE NULL AFTER DailyStockID");
            $conn->query("ALTER TABLE $table_name ADD COLUMN OPENING_BALANCE INT DEFAULT 0 AFTER SIZE_CODE");
            
            // Update existing records with STK_DATE based on STK_MONTH (set to first day of month)
            $conn->query("UPDATE $table_name SET STK_DATE = CONCAT(STK_MONTH, '-01') WHERE STK_DATE IS NULL");
            
            // Make STK_DATE NOT NULL after populating
            $conn->query("ALTER TABLE $table_name MODIFY STK_DATE DATE NOT NULL");
            
            // Add unique constraint if not exists
            try {
                $conn->query("ALTER TABLE $table_name ADD UNIQUE KEY unique_stock (STK_DATE, ITEM_CODE, LIQ_FLAG)");
            } catch (Exception $e) {
                // Unique key might already exist, ignore error
            }
        }
        
        // Check if OPENING_BALANCE column exists
        $check_balance = $conn->query("SHOW COLUMNS FROM $table_name LIKE 'OPENING_BALANCE'");
        if ($check_balance->num_rows == 0) {
            $conn->query("ALTER TABLE $table_name ADD COLUMN OPENING_BALANCE INT DEFAULT 0 AFTER SIZE_CODE");
        }
    }
    return $table_name;
}

// Function to update daily stock - Modified to work with both old and new structure
function updateDailyStock($conn, $comp_id, $item_code, $liq_flag, $opening_balance, $category_code, $class_code_new, $subclass_code_new, $size_code, $start_date) {
    $table_name = ensureDailyStockTable($conn, $comp_id);
    $stk_month = date('Y-m', strtotime($start_date));
    $stk_date = $start_date;
    
    // Check if STK_DATE column exists
    $check_column = $conn->query("SHOW COLUMNS FROM $table_name LIKE 'STK_DATE'");
    $has_stk_date = $check_column->num_rows > 0;
    
    if ($has_stk_date) {
        // New structure with STK_DATE
        $check_sql = "SELECT 1 FROM $table_name WHERE STK_DATE = ? AND ITEM_CODE = ? AND LIQ_FLAG = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("sss", $stk_date, $item_code, $liq_flag);
        $check_stmt->execute();
        $check_stmt->store_result();
        $exists = $check_stmt->num_rows > 0;
        $check_stmt->close();
        
        if ($exists) {
            $update_sql = "UPDATE $table_name SET CATEGORY_CODE = ?, CLASS_CODE_NEW = ?, SUBCLASS_CODE_NEW = ?, SIZE_CODE = ?, OPENING_BALANCE = ? WHERE STK_DATE = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ssssisss", $category_code, $class_code_new, $subclass_code_new, $size_code, $opening_balance, $stk_date, $item_code, $liq_flag);
            $update_stmt->execute();
            $update_stmt->close();
        } else {
            $insert_sql = "INSERT INTO $table_name (STK_DATE, STK_MONTH, ITEM_CODE, LIQ_FLAG, CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, OPENING_BALANCE) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bind_param("ssssssssi", $stk_date, $stk_month, $item_code, $liq_flag, $category_code, $class_code_new, $subclass_code_new, $size_code, $opening_balance);
            $insert_stmt->execute();
            $insert_stmt->close();
        }
    } else {
        // Old structure without STK_DATE - use STK_MONTH only
        $check_sql = "SELECT 1 FROM $table_name WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("sss", $stk_month, $item_code, $liq_flag);
        $check_stmt->execute();
        $check_stmt->store_result();
        $exists = $check_stmt->num_rows > 0;
        $check_stmt->close();
        
        // Check if OPENING_BALANCE column exists
        $check_balance = $conn->query("SHOW COLUMNS FROM $table_name LIKE 'OPENING_BALANCE'");
        $has_balance = $check_balance->num_rows > 0;
        
        if ($exists) {
            if ($has_balance) {
                $update_sql = "UPDATE $table_name SET CATEGORY_CODE = ?, CLASS_CODE_NEW = ?, SUBCLASS_CODE_NEW = ?, SIZE_CODE = ?, OPENING_BALANCE = ? WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("ssssisss", $category_code, $class_code_new, $subclass_code_new, $size_code, $opening_balance, $stk_month, $item_code, $liq_flag);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                $update_sql = "UPDATE $table_name SET CATEGORY_CODE = ?, CLASS_CODE_NEW = ?, SUBCLASS_CODE_NEW = ?, SIZE_CODE = ? WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bind_param("sssssss", $category_code, $class_code_new, $subclass_code_new, $size_code, $stk_month, $item_code, $liq_flag);
                $update_stmt->execute();
                $update_stmt->close();
            }
        } else {
            if ($has_balance) {
                $insert_sql = "INSERT INTO $table_name (STK_MONTH, ITEM_CODE, LIQ_FLAG, CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, OPENING_BALANCE) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("sssssssi", $stk_month, $item_code, $liq_flag, $category_code, $class_code_new, $subclass_code_new, $size_code, $opening_balance);
                $insert_stmt->execute();
                $insert_stmt->close();
            } else {
                $insert_sql = "INSERT INTO $table_name (STK_MONTH, ITEM_CODE, LIQ_FLAG, CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("sssssss", $stk_month, $item_code, $liq_flag, $category_code, $class_code_new, $subclass_code_new, $size_code);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
        }
    }
}
// ====================================================================
// MAIN BULK INSERT FUNCTION
// ====================================================================

function bulkInsertItems($conn, $items, $comp_id, $fin_year, $start_date) {
    // Initialize counters
    $imported = 0;
    $updated = 0;
    $errors = [];
    
    if (empty($items)) return ['imported' => $imported, 'updated' => $updated, 'errors' => $errors];
    
    // Get financial year from start date for stock table
    $stock_fin_year = getFinancialYearFromDate($start_date);
    
    // Ensure stock table has required columns
    $check_col_sql = "SHOW COLUMNS FROM tblitem_stock LIKE 'OPENING_STOCK$comp_id'";
    $col_result = $conn->query($check_col_sql);
    if ($col_result->num_rows == 0) {
        $add_col_sql = "ALTER TABLE tblitem_stock ADD COLUMN OPENING_STOCK$comp_id INT DEFAULT 0, ADD COLUMN CURRENT_STOCK$comp_id INT DEFAULT 0";
        $conn->query($add_col_sql);
    }
    
    // Prepare statements for reuse
    $check_item_stmt = $conn->prepare("SELECT CODE FROM tblitemmaster WHERE CODE = ? AND LIQ_FLAG = ? LIMIT 1");
    $update_item_stmt = $conn->prepare("UPDATE tblitemmaster SET Print_Name = ?, DETAILS = ?, DETAILS2 = ?, CLASS = ?, ITEM_GROUP = ?, PPRICE = ?, BPRICE = ?, MPRICE = ?, RPRICE = ?, BARCODE = ?, CATEGORY_CODE = ?, CLASS_CODE_NEW = ?, SUBCLASS_CODE_NEW = ?, SIZE_CODE = ? WHERE CODE = ? AND LIQ_FLAG = ?");
    $insert_item_stmt = $conn->prepare("INSERT INTO tblitemmaster (CODE, Print_Name, DETAILS, DETAILS2, CLASS, ITEM_GROUP, PPRICE, BPRICE, MPRICE, RPRICE, LIQ_FLAG, BARCODE, CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $check_stock_stmt = $conn->prepare("SELECT ITEM_CODE FROM tblitem_stock WHERE ITEM_CODE = ? AND FIN_YEAR = ?");
    $update_stock_stmt = $conn->prepare("UPDATE tblitem_stock SET OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ?, CATEGORY_CODE = ?, CLASS_CODE_NEW = ?, SUBCLASS_CODE_NEW = ?, SIZE_CODE = ? WHERE ITEM_CODE = ? AND FIN_YEAR = ?");
    $insert_stock_stmt = $conn->prepare("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id, CATEGORY_CODE, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($items as $item) {
        $code = $item['code'];
        $liq_flag = $item['liq_flag'];
        $opening_balance = $item['opening_balance'];
        $barcode = $item['barcode'] ?? '';
        
        // Check if item exists
        $check_item_stmt->bind_param("ss", $code, $liq_flag);
        $check_item_stmt->execute();
        $check_item_stmt->store_result();
        $exists = $check_item_stmt->num_rows > 0;
        $check_item_stmt->free_result();
        
        if ($exists) {
            // Update existing item
            $update_item_stmt->bind_param(
                "sssssddddsssssss",
                $item['print_name'], $item['item_name'], $item['size'], $item['class'],
                $item['item_group'], $item['pprice'], $item['bprice'], $item['mprice'], $item['rprice'],
                $barcode, $item['category_code'], $item['class_code_new'], $item['subclass_code_new'], $item['size_code'],
                $code, $liq_flag
            );
            
            if ($update_item_stmt->execute()) {
                $updated++;
            } else {
                $errors[] = "Failed to update $code: " . $update_item_stmt->error;
            }
        } else {
            // Insert new item
            $insert_item_stmt->bind_param(
                "sssssddddsssssss",
                $code, $item['print_name'], $item['item_name'], $item['size'], 
                $item['class'], $item['item_group'],
                $item['pprice'], $item['bprice'], $item['mprice'], $item['rprice'], $liq_flag, $barcode,
                $item['category_code'], $item['class_code_new'], $item['subclass_code_new'], $item['size_code']
            );
            
            if ($insert_item_stmt->execute()) {
                $imported++;
            } else {
                $errors[] = "Failed to insert $code: " . $insert_item_stmt->error;
            }
        }
        
        // Update stock table with the correct financial year based on start date
        $check_stock_stmt->bind_param("ss", $code, $stock_fin_year);
        $check_stock_stmt->execute();
        $check_stock_stmt->store_result();
        $stock_exists = $check_stock_stmt->num_rows > 0;
        $check_stock_stmt->free_result();
        
        if ($stock_exists) {
            $update_stock_stmt->bind_param(
                "iissssss",
                $opening_balance, $opening_balance,
                $item['category_code'], $item['class_code_new'], $item['subclass_code_new'], $item['size_code'],
                $code, $stock_fin_year
            );
            $update_stock_stmt->execute();
        } else {
            $insert_stock_stmt->bind_param(
                "siiissss",
                $code, $stock_fin_year, $opening_balance, $opening_balance,
                $item['category_code'], $item['class_code_new'], $item['subclass_code_new'], $item['size_code']
            );
            $insert_stock_stmt->execute();
        }
        
        // Update daily stock table for the specific start date
        updateDailyStock($conn, $comp_id, $code, $liq_flag, $opening_balance, 
                        $item['category_code'], $item['class_code_new'], 
                        $item['subclass_code_new'], $item['size_code'], $start_date);
    }
    
    // Close statements
    $check_item_stmt->close();
    $update_item_stmt->close();
    $insert_item_stmt->close();
    $check_stock_stmt->close();
    $update_stock_stmt->close();
    $insert_stock_stmt->close();
    
    return ['imported' => $imported, 'updated' => $updated, 'errors' => $errors];
}

// Function to download Excel template
function downloadExcelTemplate() {
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set headers
    $headers = ['Code', 'ItemName', 'PrintName', 'Size', 'PPrice', 'BPrice', 'MPrice', 'RPrice', 
                'LIQFLAG', 'OpeningBalance', 'Category', 'Class', 'Subclass', 'Barcode'];
    
    $col = 'A';
    foreach ($headers as $header) {
        $sheet->setCellValue($col . '1', $header);
        $sheet->getStyle($col . '1')->getFont()->setBold(true);
        $col++;
    }
    
    // Add example data
    $exampleRows = [
        ['SCMBR0009735', 'Budweiser Premium King of Beer', '', '330 ML', 80, 70, 100, 120, 'F', 0, 'Mild Beer', 'Mild Beer', 'Mild Beer', '8901234567890'],
        ['SCMBR0009846', 'Kingfisher Strong Premium Beer', '', '650 ML', 120, 100, 130, 150, 'F', 0, 'Fermented Beer', 'Fermented Beer', 'Fermented Beer', '8901234567891'],
        ['WHISKY001', 'Johnnie Walker Red Label Whisky', 'JW Red Label', '750ML', 2500, 2200, 2800, 2600, 'F', 50, 'Spirit', 'IMFL', 'Whisky', '8901234567892'],
        ['WINE001', 'Sula Chenin Blanc White Wine', 'Sula White', '750ML', 800, 700, 1000, 900, 'F', 30, 'Wine', 'Indian', 'Indian', '8901234567893'],
        ['VODKA001', 'Smirnoff Red Label Vodka', 'Smirnoff Red', '750ML', 900, 800, 1100, 1000, 'F', 40, 'Spirit', 'IMFL', 'Vodka', '8901234567894']
    ];
    
    $row = 2;
    foreach ($exampleRows as $exampleRow) {
        $col = 'A';
        foreach ($exampleRow as $value) {
            $sheet->setCellValue($col . $row, $value);
            $col++;
        }
        $row++;
    }
    
    // Add instructions sheet
    $instructionSheet = $spreadsheet->createSheet();
    $instructionSheet->setTitle('Instructions');
    $instructionSheet->setCellValue('A1', 'Import Instructions');
    $instructionSheet->getStyle('A1')->getFont()->setBold(true);
    $instructionSheet->setCellValue('A2', '1. Code: Unique item code (required)');
    $instructionSheet->setCellValue('A3', '2. ItemName: Full item name (required)');
    $instructionSheet->setCellValue('A4', '3. PrintName: Short name for printing (optional)');
    $instructionSheet->setCellValue('A5', '4. Size: Size description (e.g., 750 ML, 180 ML)');
    $instructionSheet->setCellValue('A6', '5. PPrice: Purchase price');
    $instructionSheet->setCellValue('A7', '6. BPrice: Base price');
    $instructionSheet->setCellValue('A8', '7. MPrice: MRP');
    $instructionSheet->setCellValue('A9', '8. RPrice: Retail price');
    $instructionSheet->setCellValue('A10', '9. LIQFLAG: F=Foreign, C=Country, O=Others');
    $instructionSheet->setCellValue('A11', '10. OpeningBalance: Initial stock quantity');
    $instructionSheet->setCellValue('A12', '11. Category: Spirit, Wine, Fermented Beer, Mild Beer, etc.');
    $instructionSheet->setCellValue('A13', '12. Class: IMFL, Imported, MML, Indian, etc.');
    $instructionSheet->setCellValue('A14', '13. Subclass: Whisky, Vodka, Rum, Brandy, Gin, etc.');
    $instructionSheet->setCellValue('A15', '14. Barcode: Product barcode (optional, max 15 chars)');
    $instructionSheet->setCellValue('A17', 'Note: Opening Balance Start Date can be any date in the past or future');
    $instructionSheet->getStyle('A1:A17')->getFont()->setSize(10);
    
    // Auto-size columns
    foreach (range('A', 'N') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }
    $instructionSheet->getColumnDimension('A')->setWidth(50);
    
    // Set headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="item_import_template.xlsx"');
    header('Cache-Control: max-age=0');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit();
}

// ====================================================================
// HANDLE IMPORT - CSV AND EXCEL
// ====================================================================

// Handle template download
if (isset($_GET['download_template']) && $_GET['download_template'] == 'excel') {
    downloadExcelTemplate();
}

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

            $conn->commit();
            $deleteMessage = "Item '$delete_code' deleted successfully.";
        } catch (Exception $e) {
            $conn->rollback();
            $deleteMessage = "Error deleting item: " . $e->getMessage();
        }
    }
}

// Mode selection
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $limit;

// Ensure stock table columns exist
$check_columns_query = "SELECT COUNT(*) as count FROM information_schema.columns
                       WHERE table_name = 'tblitem_stock'
                       AND column_name = 'OPENING_STOCK$comp_id'";
$check_result = $conn->query($check_columns_query);
$opening_col_exists = $check_result->fetch_assoc()['count'] > 0;

if (!$opening_col_exists) {
    $add_col1_query = "ALTER TABLE tblitem_stock ADD COLUMN OPENING_STOCK$comp_id INT DEFAULT 0, ADD COLUMN CURRENT_STOCK$comp_id INT DEFAULT 0";
    $conn->query($add_col1_query);
}

// Ensure daily stock table exists
ensureDailyStockTable($conn, $comp_id);

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    
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
        
        fputcsv($output, array('Code', 'ItemName', 'PrintName', 'Size', 
                               'PPrice', 'BPrice', 'MPrice', 'RPrice', 'LIQFLAG', 
                               'OpeningBalance', 'Category', 'Class', 'Subclass', 'Barcode'));
        
        foreach ($items as $item) {
            $opening_balance = 0;
            $stock_query = "SELECT OPENING_STOCK{$company_id} as opening FROM tblitem_stock WHERE ITEM_CODE = ?";
            $stock_stmt = $conn->prepare($stock_query);
            $stock_stmt->bind_param("s", $item['CODE']);
            $stock_stmt->execute();
            $stock_result = $stock_stmt->get_result();
            if ($stock_result->num_rows > 0) {
                $stock_row = $stock_result->fetch_assoc();
                $opening_balance = $stock_row['opening'];
            }
            $stock_stmt->close();
            
            $category_name = getCategoryName($item['CATEGORY_CODE'], $conn);
            $class_name = getClassName($item['CLASS_CODE_NEW'], $conn);
            $subclass_name = getSubclassName($item['SUBCLASS_CODE_NEW'], $conn);
            $size_desc = getSizeDescription($item['SIZE_CODE'], $conn);
            $size_column = $size_desc ?: $item['DETAILS2'];
            
            $exportRow = [
                'Code' => $item['CODE'],
                'ItemName' => $item['DETAILS'],
                'PrintName' => $item['Print_Name'],
                'Size' => $size_column,
                'PPrice' => $item['PPRICE'],
                'BPrice' => $item['BPRICE'],
                'MPrice' => $item['MPRICE'],
                'RPrice' => $item['RPRICE'],
                'LIQFLAG' => $item['LIQ_FLAG'],
                'OpeningBalance' => $opening_balance,
                'Category' => $category_name,
                'Class' => $class_name,
                'Subclass' => $subclass_name,
                'Barcode' => $item['BARCODE'] ?? ''
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
    $start_date = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-d');
    
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('memory_limit', '-1');
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $filePath = $file['tmp_name'];
        $fileName = $file['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        // Validate file type matches selected import type
        if ($importType == 'csv' && !in_array($fileExt, ['csv'])) {
            $importMessage = "Error: You selected CSV import but uploaded a " . strtoupper($fileExt) . " file. Please select the correct file type or upload a CSV file.";
        } elseif ($importType == 'excel' && !in_array($fileExt, ['xls', 'xlsx'])) {
            $importMessage = "Error: You selected Excel import but uploaded a " . strtoupper($fileExt) . " file. Please select the correct file type or upload an Excel file.";
        } else {
            try {
                $conn->begin_transaction();
                $conn->query("SET SESSION wait_timeout = 28800");
                $conn->query("SET SESSION interactive_timeout = 28800");
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                $conn->query("SET UNIQUE_CHECKS = 0");
                $conn->query("SET AUTOCOMMIT = 0");
                
                $items_to_process = [];
                $errors = [];
                $errorDetails = [];
                $errorCount = 0;
                $rowCount = 0;
                $imported = 0;
                $updated = 0;
                
                if ($importType === 'csv') {
                    $handle = fopen($filePath, 'r');
                    if ($handle !== FALSE) {
                        // Read header
                        $header = fgetcsv($handle);
                        
                        if (!$header) {
                            throw new Exception("Could not read CSV headers");
                        }
                        
                        $header = array_map(function($col) {
                            $col = ($col !== null && $col !== '') ? trim($col) : '';
                            $col = preg_replace('/^\xEF\xBB\xBF/', '', $col);
                            return $col;
                        }, $header);
                        
                        $header_map = [];
                        foreach ($header as $idx => $col_name) {
                            $clean_name = strtolower(str_replace([' ', '-', '_'], '', $col_name));
                            $header_map[$clean_name] = $idx;
                        }
                        
                        // Define column mappings
                        $col_mappings = [
                            'code' => ['code'],
                            'itemname' => ['itemname', 'item', 'details'],
                            'printname' => ['printname', 'print_name'],
                            'size' => ['size', 'details2'],
                            'pprice' => ['pprice', 'purchaseprice'],
                            'bprice' => ['bprice', 'baseprice'],
                            'mprice' => ['mprice', 'mrp'],
                            'rprice' => ['rprice', 'retailprice'],
                            'liqflag' => ['liqflag', 'liq_flag'],
                            'openingbalance' => ['openingbalance', 'opening_balance'],
                            'category' => ['category', 'cat'],
                            'class' => ['class', 'class_name'],
                            'subclass' => ['subclass', 'sub_class'],
                            'barcode' => ['barcode', 'bar_code', 'barcodeno']
                        ];
                        
                        $col_indices = [];
                        foreach ($col_mappings as $col_name => $possible_names) {
                            $col_indices[$col_name] = -1;
                            foreach ($possible_names as $possible_name) {
                                if (isset($header_map[$possible_name])) {
                                    $col_indices[$col_name] = $header_map[$possible_name];
                                    break;
                                }
                            }
                        }
                        
                        if ($col_indices['code'] === -1) {
                            throw new Exception("CSV must contain 'Code' column. Found: " . implode(', ', $header));
                        }
                        if ($col_indices['itemname'] === -1) {
                            throw new Exception("CSV must contain 'ItemName' column. Found: " . implode(', ', $header));
                        }
                        
                        $chunk_size = 500;
                        $chunk = [];
                        
                        while (($data = fgetcsv($handle)) !== FALSE) {
                            $rowCount++;
                            if (count($data) < 2 || empty(trim($data[$col_indices['code']]))) {
                                continue;
                            }
                            
                            $code = trim($data[$col_indices['code']]);
                            $itemName = trim($data[$col_indices['itemname']]);
                            $printName = ($col_indices['printname'] !== -1 && isset($data[$col_indices['printname']])) ? trim($data[$col_indices['printname']]) : '';
                            $size = ($col_indices['size'] !== -1 && isset($data[$col_indices['size']])) ? trim($data[$col_indices['size']]) : '';
                            
                            $pprice = 0;
                            if ($col_indices['pprice'] !== -1 && isset($data[$col_indices['pprice']]) && $data[$col_indices['pprice']] !== '') {
                                $pprice = floatval($data[$col_indices['pprice']]);
                            }
                            
                            $bprice = 0;
                            if ($col_indices['bprice'] !== -1 && isset($data[$col_indices['bprice']]) && $data[$col_indices['bprice']] !== '') {
                                $bprice = floatval($data[$col_indices['bprice']]);
                            }
                            
                            $mprice = 0;
                            if ($col_indices['mprice'] !== -1 && isset($data[$col_indices['mprice']]) && $data[$col_indices['mprice']] !== '') {
                                $mprice = floatval($data[$col_indices['mprice']]);
                            }
                            
                            $rprice = 0;
                            if ($col_indices['rprice'] !== -1 && isset($data[$col_indices['rprice']]) && $data[$col_indices['rprice']] !== '') {
                                $rprice = floatval($data[$col_indices['rprice']]);
                            }
                            
                            $liqFlag = $mode;
                            if ($col_indices['liqflag'] !== -1 && isset($data[$col_indices['liqflag']]) && !empty(trim($data[$col_indices['liqflag']]))) {
                                $liqFlag = strtoupper(trim($data[$col_indices['liqflag']]));
                            }
                            
                            $openingBalance = 0;
                            if ($col_indices['openingbalance'] !== -1 && isset($data[$col_indices['openingbalance']]) && $data[$col_indices['openingbalance']] !== '') {
                                $openingBalance = intval($data[$col_indices['openingbalance']]);
                            }
                            
                            $categoryName = ($col_indices['category'] !== -1 && isset($data[$col_indices['category']])) ? trim($data[$col_indices['category']]) : '';
                            $className = ($col_indices['class'] !== -1 && isset($data[$col_indices['class']])) ? trim($data[$col_indices['class']]) : '';
                            $subclassName = ($col_indices['subclass'] !== -1 && isset($data[$col_indices['subclass']])) ? trim($data[$col_indices['subclass']]) : '';
                            
                            $barcode = '';
                            if ($col_indices['barcode'] !== -1 && isset($data[$col_indices['barcode']])) {
                                $barcode = trim($data[$col_indices['barcode']]);
                                if (strlen($barcode) > 15) {
                                    $barcode = substr($barcode, 0, 15);
                                }
                            }
                            
                            // Detect class from item name
                            $detectedClass = detectClassFromItemName($itemName, $liqFlag);
                            
                            // Validate against license
                            if (!in_array($detectedClass, $allowed_classes)) {
                                $errorCount++;
                                $errorDetails[] = "Row $rowCount: Item $code: Class '$detectedClass' not allowed for license '$license_type'. Skipped.";
                                continue;
                            }
                            
                            // Get ITEM_GROUP - default to SC001
                            $itemGroup = 'SC001';
                            if (!empty($subclassName)) {
                                $subClassCode = getSubclassCodeByName($subclassName, $conn);
                                if (!empty($subClassCode)) {
                                    $groupQuery = "SELECT OLD_ITEM_GROUP FROM tblsubclass_new WHERE SUBCLASS_CODE = ? LIMIT 1";
                                    $groupStmt = $conn->prepare($groupQuery);
                                    if ($groupStmt) {
                                        $groupStmt->bind_param("s", $subClassCode);
                                        $groupStmt->execute();
                                        $groupResult = $groupStmt->get_result();
                                        if ($groupResult->num_rows > 0) {
                                            $groupRow = $groupResult->fetch_assoc();
                                            $itemGroup = $groupRow['OLD_ITEM_GROUP'] ?: 'SC001';
                                        }
                                        $groupStmt->close();
                                    }
                                }
                            }
                            
                            // Convert names to codes
                            $categoryCode = !empty($categoryName) ? getCategoryCodeByName($categoryName, $conn) : '';
                            $classCodeNew = !empty($className) ? getClassCodeByName($className, $conn) : '';
                            $subclassCodeNew = !empty($subclassName) ? getSubclassCodeByName($subclassName, $conn) : '';
                            $sizeCode = !empty($size) ? getSizeCodeByDescription($size, $conn) : '';
                            
                            $chunk[] = [
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
                                'barcode' => $barcode
                            ];
                            
                            if (count($chunk) >= $chunk_size) {
                                $result = bulkInsertItems($conn, $chunk, $comp_id, $fin_year, $start_date);
                                $imported += $result['imported'];
                                $updated += $result['updated'];
                                $errors = array_merge($errors, $result['errors']);
                                $chunk = [];
                                $conn->ping();
                            }
                        }
                        
                        if (!empty($chunk)) {
                            $result = bulkInsertItems($conn, $chunk, $comp_id, $fin_year, $start_date);
                            $imported += $result['imported'];
                            $updated += $result['updated'];
                            $errors = array_merge($errors, $result['errors']);
                        }
                        
                        fclose($handle);
                    } else {
                        throw new Exception("Could not open CSV file.");
                    }
                } else if ($importType === 'excel') {
                    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                        throw new Exception("Excel import is not available. Please install PhpSpreadsheet.");
                    }
                    
                    $inputFileType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filePath);
                    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
                    $reader->setReadDataOnly(true);
                    $spreadsheet = $reader->load($filePath);
                    $sheet = $spreadsheet->getActiveSheet();
                    $rows = $sheet->toArray();
                    
                    if (empty($rows)) {
                        throw new Exception("Excel file is empty.");
                    }
                    
                    $header = $rows[0];
                    
                    $header = array_map(function($col) {
                        $col = ($col !== null && $col !== '') ? trim($col) : '';
                        $col = preg_replace('/^\xEF\xBB\xBF/', '', $col);
                        return $col;
                    }, $header);
                    
                    $header_map = [];
                    foreach ($header as $idx => $col_name) {
                        $clean_name = strtolower(str_replace([' ', '-', '_'], '', $col_name));
                        $header_map[$clean_name] = $idx;
                    }
                    
                    $col_mappings = [
                        'code' => ['code'],
                        'itemname' => ['itemname', 'item', 'details'],
                        'printname' => ['printname', 'print_name'],
                        'size' => ['size', 'details2'],
                        'pprice' => ['pprice', 'purchaseprice'],
                        'bprice' => ['bprice', 'baseprice'],
                        'mprice' => ['mprice', 'mrp'],
                        'rprice' => ['rprice', 'retailprice'],
                        'liqflag' => ['liqflag', 'liq_flag'],
                        'openingbalance' => ['openingbalance', 'opening_balance'],
                        'category' => ['category', 'cat'],
                        'class' => ['class', 'class_name'],
                        'subclass' => ['subclass', 'sub_class'],
                        'barcode' => ['barcode', 'bar_code', 'barcodeno']
                    ];
                    
                    $col_indices = [];
                    foreach ($col_mappings as $col_name => $possible_names) {
                        $col_indices[$col_name] = -1;
                        foreach ($possible_names as $possible_name) {
                            if (isset($header_map[$possible_name])) {
                                $col_indices[$col_name] = $header_map[$possible_name];
                                break;
                            }
                        }
                    }
                    
                    if ($col_indices['code'] === -1) {
                        throw new Exception("Excel must contain 'Code' column. Found: " . implode(', ', $header));
                    }
                    if ($col_indices['itemname'] === -1) {
                        throw new Exception("Excel must contain 'ItemName' column. Found: " . implode(', ', $header));
                    }
                    
                    $chunk_size = 500;
                    $chunk = [];
                    
                    for ($i = 1; $i < count($rows); $i++) {
                        $rowCount++;
                        $data = $rows[$i];
                        if (count($data) < 2 || empty(trim($data[$col_indices['code']]))) {
                            continue;
                        }
                        
                        $code = trim($data[$col_indices['code']]);
                        $itemName = trim($data[$col_indices['itemname']]);
                        $printName = ($col_indices['printname'] !== -1 && isset($data[$col_indices['printname']])) ? trim($data[$col_indices['printname']]) : '';
                        $size = ($col_indices['size'] !== -1 && isset($data[$col_indices['size']])) ? trim($data[$col_indices['size']]) : '';
                        
                        $pprice = 0;
                        if ($col_indices['pprice'] !== -1 && isset($data[$col_indices['pprice']]) && $data[$col_indices['pprice']] !== '') {
                            $pprice = floatval($data[$col_indices['pprice']]);
                        }
                        
                        $bprice = 0;
                        if ($col_indices['bprice'] !== -1 && isset($data[$col_indices['bprice']]) && $data[$col_indices['bprice']] !== '') {
                            $bprice = floatval($data[$col_indices['bprice']]);
                        }
                        
                        $mprice = 0;
                        if ($col_indices['mprice'] !== -1 && isset($data[$col_indices['mprice']]) && $data[$col_indices['mprice']] !== '') {
                            $mprice = floatval($data[$col_indices['mprice']]);
                        }
                        
                        $rprice = 0;
                        if ($col_indices['rprice'] !== -1 && isset($data[$col_indices['rprice']]) && $data[$col_indices['rprice']] !== '') {
                            $rprice = floatval($data[$col_indices['rprice']]);
                        }
                        
                        $liqFlag = $mode;
                        if ($col_indices['liqflag'] !== -1 && isset($data[$col_indices['liqflag']]) && !empty(trim($data[$col_indices['liqflag']]))) {
                            $liqFlag = strtoupper(trim($data[$col_indices['liqflag']]));
                        }
                        
                        $openingBalance = 0;
                        if ($col_indices['openingbalance'] !== -1 && isset($data[$col_indices['openingbalance']]) && $data[$col_indices['openingbalance']] !== '') {
                            $openingBalance = intval($data[$col_indices['openingbalance']]);
                        }
                        
                        $categoryName = ($col_indices['category'] !== -1 && isset($data[$col_indices['category']])) ? trim($data[$col_indices['category']]) : '';
                        $className = ($col_indices['class'] !== -1 && isset($data[$col_indices['class']])) ? trim($data[$col_indices['class']]) : '';
                        $subclassName = ($col_indices['subclass'] !== -1 && isset($data[$col_indices['subclass']])) ? trim($data[$col_indices['subclass']]) : '';
                        
                        $barcode = '';
                        if ($col_indices['barcode'] !== -1 && isset($data[$col_indices['barcode']])) {
                            $barcode = trim($data[$col_indices['barcode']]);
                            if (strlen($barcode) > 15) {
                                $barcode = substr($barcode, 0, 15);
                            }
                        }
                        
                        $detectedClass = detectClassFromItemName($itemName, $liqFlag);
                        
                        if (!in_array($detectedClass, $allowed_classes)) {
                            $errorCount++;
                            $errorDetails[] = "Row $rowCount: Item $code: Class '$detectedClass' not allowed for license '$license_type'. Skipped.";
                            continue;
                        }
                        
                        $itemGroup = 'SC001';
                        if (!empty($subclassName)) {
                            $subClassCode = getSubclassCodeByName($subclassName, $conn);
                            if (!empty($subClassCode)) {
                                $groupQuery = "SELECT OLD_ITEM_GROUP FROM tblsubclass_new WHERE SUBCLASS_CODE = ? LIMIT 1";
                                $groupStmt = $conn->prepare($groupQuery);
                                if ($groupStmt) {
                                    $groupStmt->bind_param("s", $subClassCode);
                                    $groupStmt->execute();
                                    $groupResult = $groupStmt->get_result();
                                    if ($groupResult->num_rows > 0) {
                                        $groupRow = $groupResult->fetch_assoc();
                                        $itemGroup = $groupRow['OLD_ITEM_GROUP'] ?: 'SC001';
                                    }
                                    $groupStmt->close();
                                }
                            }
                        }
                        
                        $categoryCode = !empty($categoryName) ? getCategoryCodeByName($categoryName, $conn) : '';
                        $classCodeNew = !empty($className) ? getClassCodeByName($className, $conn) : '';
                        $subclassCodeNew = !empty($subclassName) ? getSubclassCodeByName($subclassName, $conn) : '';
                        $sizeCode = !empty($size) ? getSizeCodeByDescription($size, $conn) : '';
                        
                        $chunk[] = [
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
                            'barcode' => $barcode
                        ];
                        
                        if (count($chunk) >= $chunk_size) {
                            $result = bulkInsertItems($conn, $chunk, $comp_id, $fin_year, $start_date);
                            $imported += $result['imported'];
                            $updated += $result['updated'];
                            $errors = array_merge($errors, $result['errors']);
                            $chunk = [];
                            $conn->ping();
                        }
                    }
                    
                    if (!empty($chunk)) {
                        $result = bulkInsertItems($conn, $chunk, $comp_id, $fin_year, $start_date);
                        $imported += $result['imported'];
                        $updated += $result['updated'];
                        $errors = array_merge($errors, $result['errors']);
                    }
                }
                
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                $conn->query("SET UNIQUE_CHECKS = 1");
                $conn->query("SET AUTOCOMMIT = 1");
                $conn->commit();
                
                $importMessage = "Import completed: $imported new items imported, $updated items updated, $errorCount rows skipped. Total rows processed: $rowCount. Opening balance date: $start_date";
                if (!empty($errorDetails)) {
                    $importMessage .= "<br>First few errors: " . implode("; ", array_slice($errorDetails, 0, 10));
                }
                if (!empty($errors)) {
                    $importMessage .= "<br>Database errors: " . implode("; ", array_slice($errors, 0, 5));
                }
                
            } catch (Exception $e) {
                if ($conn) {
                    $conn->rollback();
                    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
                    $conn->query("SET UNIQUE_CHECKS = 1");
                    $conn->query("SET AUTOCOMMIT = 1");
                }
                $importMessage = "Error during import: " . $e->getMessage();
            }
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
    $count_params = [];
    $count_types = "";
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

$total_pages = ceil($total_items / $limit);

// Fetch items for display
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
    .import-export-buttons { display: flex; gap: 10px; margin-bottom: 15px; }
    .import-template { font-size: 0.9rem; color: #6c757d; margin-top: 10px; padding: 10px; background-color: #f8f9fa; border-radius: 5px; }
    .import-template ul { margin-bottom: 0; padding-left: 20px; }
    .download-template { margin-top: 10px; }
    .table-container { overflow-x: auto; max-width: 100%; }
    .styled-table { font-size: 0.85rem; width: 100%; min-width: 1200px; border-collapse: separate; border-spacing: 0; }
    .styled-table th { white-space: nowrap; padding: 8px 5px; background-color: #f8f9fa; border-bottom: 2px solid #dee2e6; font-weight: 600; position: sticky; top: 0; z-index: 10; }
    .styled-table td { padding: 6px 4px; vertical-align: middle; border-bottom: 1px solid #dee2e6; }
    .styled-table tbody tr:nth-child(even) { background-color: #f8f9fa; }
    .styled-table tbody tr:hover { background-color: #e9ecef; }
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
    .compact-text { font-size: 0.8rem; line-height: 1.2; }
    .classification-data { font-size: 0.8rem; color: #198754; font-weight: 500; }
    .date-field { max-width: 200px; }
    .pagination-container { display: flex; justify-content: center; margin-top: 20px; }
    .page-info { text-align: center; margin: 10px 0; color: #6c757d; }
    .pagination .page-link { padding: 5px 10px; font-size: 0.9rem; }
    .page-size-selector { max-width: 100px; display: inline-block; margin-left: 10px; }
  </style>
<script>
function downloadTemplate() {
    window.location.href = '?download_template=excel';
}

function changePageSize(size) {
    const url = new URL(window.location.href);
    url.searchParams.set('limit', size);
    url.searchParams.set('page', 1);
    window.location.href = url.toString();
}

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

      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&search=<?= urlencode($search) ?>&page=1" class="btn btn-outline-primary <?= $mode === 'F' ? 'active' : '' ?>">Foreign Liquor</a>
          <a href="?mode=C&search=<?= urlencode($search) ?>&page=1" class="btn btn-outline-primary <?= $mode === 'C' ? 'active' : '' ?>">Country Liquor</a>
          <a href="?mode=O&search=<?= urlencode($search) ?>&page=1" class="btn btn-outline-primary <?= $mode === 'O' ? 'active' : '' ?>">Others</a>
        </div>
      </div>
      
      <div class="import-export-buttons">
        <div class="btn-group">
          <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-import"></i> Import with Opening Balance Date
          </button>
          <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&export=csv" class="btn btn-info">
            <i class="fas fa-file-export"></i> Export CSV
          </a>
        </div>
      </div>

      <div class="import-template">
          <p><strong>4-Layer Classification System Import Features:</strong></p>
          <ul>
              <li><strong>Category:</strong> Spirit, Wine, Fermented Beer, Mild Beer, etc.</li>
              <li><strong>Class:</strong> IMFL, Imported, MML, Indian, etc.</li>
              <li><strong>Subclass:</strong> Whisky, Vodka, Rum, Brandy, Gin, etc.</li>
              <li><strong>Size:</strong> 750 ML, 180 ML, 650 ML, etc.</li>
              <li><strong>Barcode:</strong> Optional - 15 characters max</li>
              <li><strong>Opening Balance Start Date:</strong> Can be any date (past, present, or future). Stock will be recorded for that specific date.</li>
          </ul>
       
          <div class="download-template">
              <a href="javascript:void(0);" onclick="downloadTemplate()" class="btn btn-sm btn-outline-secondary">
                  <i class="fas fa-download"></i> Download Excel Template with 4-Layer Support
              </a>
          </div>
      </div>

      <form method="GET" class="search-control mb-3">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <input type="hidden" name="page" value="1">
        <div class="input-group">
          <input type="text" name="search" class="form-control" placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Find</button>
          <?php if ($search !== ''): ?><a href="?mode=<?= $mode ?>&page=1" class="btn btn-secondary">Clear</a><?php endif; ?>
        </div>
      </form>

      <div class="page-info">
        Showing <?= min($limit, count($items)) ?> of <?= $total_items ?> items
        <select class="form-select form-select-sm page-size-selector" onchange="changePageSize(this.value)">
          <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20 per page</option>
          <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50 per page</option>
          <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100 per page</option>
          <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200 per page</option>
        </select>
      </div>
      
      <div class="action-btn mb-3 d-flex gap-2">
        <a href="add_item.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Item</a>
        <a href="dashboard.php" class="btn btn-secondary ms-auto"><i class="fas fa-sign-out-alt"></i> Exit</a>
      </div>

      <?php if (!empty($importMessage)): ?>
      <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($importMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <?php if (!empty($deleteMessage)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $deleteMessage ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <div class="table-container">
        <table class="styled-table table-striped">
          <thead>
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
              <th class="col-barcode">Barcode</th>
              <th class="col-actions">Actions</th>
                </tr>
          </thead>
          <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
              <?php
              $opening_balance = 0;
              $stock_query = "SELECT OPENING_STOCK{$company_id} as opening FROM tblitem_stock WHERE ITEM_CODE = ?";
              $stock_stmt = $conn->prepare($stock_query);
              $stock_stmt->bind_param("s", $item['CODE']);
              $stock_stmt->execute();
              $stock_result = $stock_stmt->get_result();
              if ($stock_result->num_rows > 0) {
                  $stock_row = $stock_result->fetch_assoc();
                  $opening_balance = $stock_row['opening'];
              }
              $stock_stmt->close();

              $category_name = getCategoryName($item['CATEGORY_CODE'], $conn);
              $class_name = getClassName($item['CLASS_CODE_NEW'], $conn);
              $subclass_name = getSubclassName($item['SUBCLASS_CODE_NEW'], $conn);
              $size_desc = getSizeDescription($item['SIZE_CODE'], $conn);
              $pprice_int = intval($item['PPRICE']);
              $bprice_int = intval($item['BPRICE']);
              $mprice_int = intval($item['MPRICE']);
              $rprice_int = intval($item['RPRICE']);
              ?>
              <tr class="compact-text">
                <td class="col-code"><?= htmlspecialchars($item['CODE']); ?></td>
                <td class="col-item-name"><?= htmlspecialchars($item['DETAILS']); ?></td>
                <td class="col-print-name"><?= htmlspecialchars($item['Print_Name']); ?></td>
                <td class="col-category classification-data"><?= htmlspecialchars($category_name); ?></td>
                <td class="col-class classification-data"><?= htmlspecialchars($class_name); ?></td>
                <td class="col-subclass classification-data"><?= htmlspecialchars($subclass_name); ?></td>
                <td class="col-size classification-data"><?= htmlspecialchars($size_desc ?: $item['DETAILS2']); ?></td>
                <td class="col-price"><?= number_format($pprice_int, 0); ?></td>
                                <td class="col-price"><?= number_format($bprice_int, 0); ?> </td>
                <td class="col-price"><?= number_format($mprice_int, 0); ?> </td>
                <td class="col-price"><?= number_format($rprice_int, 0); ?> </td>
                <td class="col-stock"><?= $opening_balance; ?> </td>
                <td class="col-barcode classification-data"><?= htmlspecialchars($item['BARCODE'] ?? ''); ?> </td>
                <td class="col-actions">
                  <div class="d-flex gap-1">
                    <a href="edit_item.php?code=<?= urlencode($item['CODE']) ?>&mode=<?= $mode ?>" class="btn btn-sm btn-primary" title="Edit"><i class="fas fa-edit"></i></a>
                    <form method="POST" style="display: inline;" onsubmit="return confirmDelete('<?= htmlspecialchars($item['DETAILS']) ?>')">
                      <input type="hidden" name="delete_code" value="<?= htmlspecialchars($item['CODE']) ?>">
                      <input type="hidden" name="delete_liq_flag" value="<?= htmlspecialchars($item['LIQ_FLAG']) ?>">
                      <button type="submit" class="btn btn-sm btn-danger" title="Delete"><i class="fas fa-trash"></i></button>
                    </form>
                  </div>
                 </td>
                </tr>
            <?php endforeach; ?>
          <?php else: ?>
              <tr><td colspan="14" class="text-center text-muted">No items found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <?php if ($total_pages > 1): ?>
      <div class="pagination-container">
        <nav aria-label="Page navigation">
          <ul class="pagination">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=1">&laquo;&laquo;</a></li>
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= max(1, $page - 1) ?>">&laquo;</a></li>
            <?php 
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            for ($i = $start_page; $i <= $end_page; $i++): ?>
              <li class="page-item <?= $i == $page ? 'active' : '' ?>"><a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= $i ?>"><?= $i ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= min($total_pages, $page + 1) ?>">&raquo;</a></li>
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"><a class="page-link" href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&page=<?= $total_pages ?>">&raquo;&raquo;</a></li>
          </ul>
        </nav>
      </div>
      <div class="row justify-content-center mt-3">
        <div class="col-md-4">
          <div class="input-group">
            <input type="number" id="jumpPage" class="form-control" min="1" max="<?= $total_pages ?>" placeholder="Page #">
            <button class="btn btn-outline-secondary" type="button" onclick="goToPage(document.getElementById('jumpPage').value)">Go</button>
          </div>
          <div class="form-text text-center">Page <?= $page ?> of <?= $total_pages ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="importModalLabel">Bulk Import Items
          <span class="badge bg-success">4-Layer Classification</span>
          <span class="badge bg-info">Barcode Support</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" enctype="multipart/form-data" id="importForm">
        <div class="modal-body">
          <div class="mb-3">
            <label for="importFileType" class="form-label">File Type</label>
            <select class="form-select" id="importFileType" name="import_type" required>
              <option value="">-- Select File Type --</option>
              <option value="excel">Excel File (.xls, .xlsx)</option>
              <option value="csv">CSV File (.csv)</option>
            </select>
            <div class="form-text">Please select the file type that matches your upload file.</div>
          </div>
          
          <div class="mb-3">
            <label for="importFile" class="form-label">Select file to import</label>
            <input class="form-control" type="file" id="importFile" name="import_file" required accept=".csv,.xls,.xlsx">
            <div class="form-text">Supports CSV and Excel files with 4-layer classification data</div>
          </div>
          
          <div class="mb-3">
            <label for="start_date" class="form-label">Opening Balance Start Date</label>
            <input type="date" class="form-control date-field" id="start_date" name="start_date" value="<?= date('Y-m-d') ?>" required>
            <div class="form-text">Opening balance will be recorded for this specific date. Can be any date (past, present, or future).</div>
          </div>
          
          <div class="alert alert-warning">
            <strong><i class="fas fa-layer-group"></i> 4-Layer Classification System</strong>
            <ul class="mb-0 mt-2">
              <li><strong>Category:</strong> Spirit, Wine, Fermented Beer, Mild Beer, etc.</li>
              <li><strong>Class:</strong> IMFL, Imported, Indian, MML, etc.</li>
              <li><strong>Subclass:</strong> Whisky, Vodka, Rum, Brandy, Gin, etc.</li>
              <li><strong>Size:</strong> 750 ML, 180 ML, 650 ML, etc.</li>
              <li><strong>Barcode:</strong> Optional - 15 characters max</li>
              <li><strong>Opening Balance Date:</strong> Stock will be recorded for the selected date in the daily stock table</li>
            </ul>
          </div>
          
          <div class="alert alert-info">
            <strong><i class="fas fa-calendar-alt"></i> Note about Opening Balance Date:</strong>
            <p class="mb-0 mt-2">The opening balance will be stored in the daily stock table with the exact date you select. This allows you to track stock levels from any point in time, not just the current financial year.</p>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="importSubmitBtn">
            <i class="fas fa-rocket"></i> Start Import
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

document.addEventListener('DOMContentLoaded', function() {
    const importForm = document.getElementById('importForm');
    if (importForm) {
        importForm.addEventListener('submit', function(e) {
            const fileType = document.getElementById('importFileType').value;
            const fileInput = document.getElementById('importFile');
            const startDate = document.getElementById('start_date');
            
            if (!fileType) { 
                e.preventDefault(); 
                alert('Please select file type.'); 
                return; 
            }
            if (!fileInput.files.length) { 
                e.preventDefault(); 
                alert('Please select a file to import.'); 
                return; 
            }
            if (!startDate.value) { 
                e.preventDefault(); 
                alert('Please select an opening balance start date.'); 
                return; 
            }
            
            // Validate file type matches selection
            const fileName = fileInput.files[0].name;
            const fileExt = fileName.split('.').pop().toLowerCase();
            
            if (fileType === 'csv' && fileExt !== 'csv') {
                e.preventDefault();
                alert('You selected CSV import but uploaded a ' + fileExt.toUpperCase() + ' file. Please upload a CSV file or change file type selection.');
                return;
            }
            if (fileType === 'excel' && !['xls', 'xlsx'].includes(fileExt)) {
                e.preventDefault();
                alert('You selected Excel import but uploaded a ' + fileExt.toUpperCase() + ' file. Please upload an Excel file or change file type selection.');
                return;
            }
            
            // Validate date format
            const selectedDate = new Date(startDate.value);
            if (isNaN(selectedDate.getTime())) {
                e.preventDefault();
                alert('Please select a valid date.');
                return;
            }
            
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
            loadingOverlay.innerHTML = `<div class="text-center p-5">
                <div class="spinner-border text-primary" style="width: 4rem; height: 4rem;"></div>
                <h3 class="mt-4">Import in Progress</h3>
                <p class="mt-2">Processing large file may take a few minutes...</p>
                <p class="mt-2 text-muted">Please do not close this window.</p>
                <p class="mt-2"><small>Opening balance date: ${startDate.value}</small></p>
            </div>`;
            document.body.appendChild(loadingOverlay);
            
            const submitBtn = document.getElementById('importSubmitBtn');
            if (submitBtn) { 
                submitBtn.disabled = true; 
                submitBtn.innerHTML = '<i class="fas fa-sync fa-spin"></i> Processing...'; 
            }
        });
    }
    
    const startDateInput = document.getElementById('start_date');
    if (startDateInput) { 
        // No restrictions - any date can be selected
        if (!startDateInput.value) {
            startDateInput.value = new Date().toISOString().split('T')[0];
        }
        
        // Add helper text showing which financial year will be used
        startDateInput.addEventListener('change', function() {
            if (this.value) {
                // Calculate financial year from selected date
                const selectedDate = new Date(this.value);
                const year = selectedDate.getFullYear();
                const month = selectedDate.getMonth() + 1;
                let finYear;
                if (month >= 4) {
                    finYear = year + '-' + (year + 1);
                } else {
                    finYear = (year - 1) + '-' + year;
                }
                
                // Show financial year info (optional)
                console.log('Selected date: ' + this.value + ' falls in financial year: ' + finYear);
            }
        });
    }
});

// Auto-dismiss alerts after 5 seconds
setTimeout(() => { 
    document.querySelectorAll('.alert').forEach(alert => { 
        try {
            new bootstrap.Alert(alert).close(); 
        } catch(e) {
            // Bootstrap alert might not be available, just hide it
            alert.style.display = 'none';
        }
    }); 
}, 5000);

// Function to validate date range (optional - remove if you want no restrictions)
function validateDateRange(date) {b
    // You can add custom validation here if needed
    // For example, prevent future dates:
    // const today = new Date();
    // today.setHours(0, 0, 0, 0);
    // if (new Date(date) > today) {
    //     alert('Opening balance date cannot be in the future!');
    //     return false;
    // }
    return true;
}
</script>
</body>
</html>