<?php
// Debug logging function
function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/debug_purchase.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $logMessage .= ": " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $logMessage .= ": " . $data;
        }
    }
    
    $logMessage .= "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Function to check TP number uniqueness
function isTPNoUnique($conn, $tp_no, $companyId, $exclude_voc_no = null) {
    if (empty($tp_no)) return true; // Empty TP numbers are allowed
    
    $query = "SELECT COUNT(*) as count FROM tblpurchases WHERE TPNO = ? AND CompID = ?";
    $params = [$tp_no, $companyId];
    $types = "si";
    
    // If updating existing record, exclude current voucher
    if ($exclude_voc_no !== null) {
        $query .= " AND VOC_NO != ?";
        $params[] = $exclude_voc_no;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'] == 0;
}

// Start debug session
debugLog("=== NEW PURCHASE SESSION STARTED ===");

session_start();

debugLog("Session data", $_SESSION);
debugLog("POST data", $_POST);
debugLog("GET data", $_GET);

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { 
    debugLog("User not logged in, redirecting to index");
    header("Location: index.php"); 
    exit; 
}
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { 
    debugLog("Company ID or Financial Year not set, redirecting to index");
    header("Location: index.php"); 
    exit; 
}

$companyId = $_SESSION['CompID'];
debugLog("Company ID from session", $companyId);

// Check for duplicate TP number error
$tp_no_duplicate_error = '';
if (isset($_GET['tp_error']) && $_GET['tp_error'] == 1) {
    $tp_no_duplicate_error = 'TP Number already exists. Please enter a unique TP number.';
}

include_once "../config/db.php";
include_once "stock_functions.php";
include_once "components/financial_year.php";
debugLog("Database connection included");

// Extract financial year variables from session
$fin_year_start = $_SESSION['FIN_YEAR_START'] ?? null;
$fin_year_end = $_SESSION['FIN_YEAR_END'] ?? null;
$fin_year_id = $_SESSION['FIN_YEAR_ID'] ?? null;

// ---- License filtering ----
require_once 'license_functions.php';
debugLog("License functions included");

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

debugLog("License type", $license_type);
debugLog("Available classes", $available_classes);

// Extract class SGROUP values for filtering
$allowed_classes = [];
if (!empty($available_classes)) {
    foreach ($available_classes as $class) {
        $allowed_classes[] = $class['SGROUP'];
    }
}
debugLog("Allowed class SGROUP values", $allowed_classes);

// ---- Mode: F (Foreign) / C (Country) ----
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
debugLog("Purchase mode", $mode);

// ============================================================================
// VOUCHER NUMBER RENUMBERING FUNCTION
// Renumbers all VOC_NO for the company based on TP_DATE (or DATE if TP_DATE is empty)
// ============================================================================
function renumberVoucherNumbers($conn, $companyId) {
    // Ensure companyId is an integer
    $companyId = (int)$companyId;
    
    if ($companyId <= 0) {
        debugLog("VOC_NO renumbering skipped - invalid company ID: $companyId");
        return -1;
    }
    
    // Use ROW_NUMBER() to assign new VOC_NO based on date ordering
    // TP_DATE takes precedence over DATE
    // For tie-breaking, use ID
    $renumberQuery = "
        UPDATE tblpurchases p
        INNER JOIN (
            SELECT ID, ROW_NUMBER() OVER (ORDER BY 
                COALESCE(NULLIF(TP_DATE, '0000-00-00'), DATE) ASC,
                ID ASC
            ) AS new_voc_no
            FROM tblpurchases 
            WHERE CompID = ?
        ) AS ranked ON p.ID = ranked.ID
        SET p.VOC_NO = ranked.new_voc_no";
    
    $renumberStmt = $conn->prepare($renumberQuery);
    if ($renumberStmt) {
        $renumberStmt->bind_param("i", $companyId);
        $renumberStmt->execute();
        $affectedRows = $renumberStmt->affected_rows;
        $renumberStmt->close();
        
        debugLog("VOC_NO renumbered for company $companyId, affected rows: $affectedRows");
        return $affectedRows;
    } else {
        debugLog("VOC_NO renumbering failed: " . $conn->error);
        return -1;
    }
}

// ---- Next Voucher No. (for current company) ----
// NOTE: This is just for display - actual VOC_NO will be assigned after renumbering
$vocQuery  = "SELECT MAX(VOC_NO) AS MAX_VOC FROM tblPurchases WHERE CompID = ?";
$vocStmt = $conn->prepare($vocQuery);
$vocStmt->bind_param("i", $companyId);
$vocStmt->execute();
$vocResult = $vocStmt->get_result();
$maxVoc    = $vocResult ? $vocResult->fetch_assoc() : ['MAX_VOC'=>0];
$nextVoc   = intval($maxVoc['MAX_VOC']) + 1;
$vocStmt->close();

debugLog("Next voucher number", $nextVoc);

// ---- Get distinct sizes from tblsubclass ----
$distinctSizes = [];
$sizeQuery = "SELECT DISTINCT CC FROM tblsubclass ORDER BY CC";
$sizeResult = $conn->query($sizeQuery);
if ($sizeResult) {
    while ($row = $sizeResult->fetch_assoc()) {
        $distinctSizes[] = $row['CC'];
    }
}
$sizeResult->close();
debugLog("Distinct sizes from database", $distinctSizes);

// Function to clean item code by removing SCM prefix (for display/search purposes only)
function cleanItemCode($code) {
    $cleaned = preg_replace('/^SCM/i', '', trim($code));
    debugLog("cleanItemCode: '$code' -> '$cleaned'");
    return $cleaned;
}

// Function to check if a month is archived
function isMonthArchived($conn, $comp_id, $month, $year) {
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    
    // Check if archive table exists
    $check_archive_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                           WHERE table_schema = DATABASE() 
                           AND table_name = '$archive_table'";
    $check_result = $conn->query($check_archive_query);
    $exists = $check_result->fetch_assoc()['count'] > 0;
    
    // Also check if it's the current month (not archived)
    $current_month = date('n');
    $current_year = date('Y');
    
    // If it's current month, return false (not archived)
    if ($month == $current_month && $year == $current_year) {
        return false;
    }
    
    // If archive table exists OR it's a past month, consider it archived
    return $exists || ($year < $current_year || ($year == $current_year && $month < $current_month));
}

// Function to update archived month stock with complete calculation including cascading
function updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    debugLog("Updating archived month stock", [
        'table' => $archive_table,
        'monthYear' => $monthYear,
        'itemCode' => $itemCode,
        'dayOfMonth' => $dayOfMonth,
        'totalBottles' => $totalBottles
    ]);
    
    // Check if archive table exists, if not create it
    $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                         WHERE table_schema = DATABASE() 
                         AND table_name = '$archive_table'";
    $check_table_result = $conn->query($check_table_query);
    $table_exists = $check_table_result->fetch_assoc()['count'] > 0;
    
    if (!$table_exists) {
        // Create the archive table for this month
        $days_in_month = date('t', strtotime($purchaseDate));
        
        $create_query = "CREATE TABLE $archive_table (
            `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
            `STK_DATE` date NOT NULL,
            `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
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
            KEY `idx_stk_month` (`STK_MONTH`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        if ($conn->query($create_query)) {
            debugLog("Created archive table: $archive_table");
        } else {
            debugLog("Failed to create archive table: " . $conn->error);
        }
    }
    
    // Check if record exists in archive table
    $check_query = "SELECT COUNT(*) as count FROM $archive_table 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Update existing record with complete calculation including sales
        $update_query = "UPDATE $archive_table 
                        SET $purchaseColumn = $purchaseColumn + ?,
                            $closingColumn = $openingColumn + $purchaseColumn - $saleColumn
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
        $result = $update_stmt->execute();
        $update_stmt->close();
        
        if ($result) {
            // Now update all subsequent days in the archived month (cascading effect)
            updateSubsequentDaysInTable($conn, $archive_table, $monthYear, $itemCode, $dayOfMonth);
        }
    } else {
        // For new record, opening is 0, so closing = purchase (no sales initially)
        $insert_query = "INSERT INTO $archive_table 
                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                        VALUES (?, ?, 'F', 0, ?, 0, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
        $result = $insert_stmt->execute();
        $insert_stmt->close();
        
        // Cascade subsequent days in the month
        if ($result) {
            updateSubsequentDaysInTable($conn, $archive_table, $monthYear, $itemCode, $dayOfMonth);
        }
    }
    
    return $result;
}

// Function to update current month stock with proper cascading updates
function updateCurrentMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    $dailyStockTable = "tbldailystock_" . $comp_id;
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    debugLog("Updating current month stock", [
        'table' => $dailyStockTable,
        'monthYear' => $monthYear,
        'itemCode' => $itemCode,
        'dayOfMonth' => $dayOfMonth,
        'totalBottles' => $totalBottles
    ]);
    
    // Check if daily stock record exists for this month and item
    $checkDailyStockQuery = "SELECT COUNT(*) as count FROM $dailyStockTable 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $checkStmt = $conn->prepare($checkDailyStockQuery);
    $checkStmt->bind_param("ss", $monthYear, $itemCode);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $row = $result->fetch_assoc();
    $checkStmt->close();
    
    if ($row['count'] > 0) {
        // Update existing record with complete calculation including sales
        $updateDailyStockQuery = "UPDATE $dailyStockTable 
                                 SET $purchaseColumn = $purchaseColumn + ?,
                                     $closingColumn = $openingColumn + $purchaseColumn - $saleColumn
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $dailyStmt = $conn->prepare($updateDailyStockQuery);
        $dailyStmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
        $result = $dailyStmt->execute();
        $dailyStmt->close();
        
        if ($result) {
            // Now update all subsequent days' opening and closing values with cascading effect
            updateSubsequentDaysInTable($conn, $dailyStockTable, $monthYear, $itemCode, $dayOfMonth);
        }
    } else {
        // For new record, opening is 0, so closing = purchase (no sales initially)
        $insertDailyStockQuery = "INSERT INTO $dailyStockTable 
                                 (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                                 VALUES (?, ?, 'F', 0, ?, 0, ?)";
        $dailyStmt = $conn->prepare($insertDailyStockQuery);
        $dailyStmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
        $result = $dailyStmt->execute();
        $dailyStmt->close();
    }
    
    return $result;
}

// Universal function to update subsequent days' opening and closing values with cascading effect
// Works for both current and archived tables
function updateSubsequentDaysInTable($conn, $table, $monthYear, $itemCode, $purchaseDay) {
    debugLog("Starting cascading updates in table", [
        'table' => $table,
        'monthYear' => $monthYear,
        'itemCode' => $itemCode,
        'purchaseDay' => $purchaseDay
    ]);
    
    // Get the number of days in the month
    $timestamp = strtotime($monthYear . "-01");
    $daysInMonth = date('t', $timestamp); // 28, 29, 30, or 31
    
    debugLog("Month has $daysInMonth days", [
        'timestamp' => date('Y-m-d', $timestamp),
        'daysInMonth' => $daysInMonth
    ]);
    
    // Update opening for next day (carry forward from previous day's closing)
    // Only iterate through actual days in the month
    for ($day = $purchaseDay + 1; $day <= $daysInMonth; $day++) {
        $prevDay = $day - 1;
        $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        // Check if the columns exist in the table
        $checkColumnsQuery = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = '$table' 
                            AND COLUMN_NAME IN ('$currentDayOpening', '$currentDayPurchase', '$currentDaySales', '$currentDayClosing')";
        
        $checkResult = $conn->query($checkColumnsQuery);
        $columnsExist = $checkResult->num_rows >= 4; // All 4 columns should exist
        
        if ($columnsExist) {
            // Update opening to previous day's closing, and recalculate closing
            $updateQuery = "UPDATE $table 
                           SET $currentDayOpening = $prevDayClosing,
                               $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            
            debugLog("Cascading update for day $day", [
                'query' => $updateQuery,
                'prevDayClosing' => $prevDayClosing,
                'columns_exist' => true
            ]);
            
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("ss", $monthYear, $itemCode);
            $stmt->execute();
            $stmt->close();
        } else {
            debugLog("Skipping day $day - columns don't exist", [
                'columns_checked' => [$currentDayOpening, $currentDayPurchase, $currentDaySales, $currentDayClosing],
                'columns_found' => $checkResult->num_rows
            ]);
        }
        $checkResult->free();
    }
    
    debugLog("Cascading updates completed for all days after purchase day");
}

// Function to continue cascading from archived month to current month
function continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate, $fy_end_date = null) {
    // Determine the correct end date based on whether it's a previous FY or current FY
    // If fy_end_date is provided as null, calculate based on purchase date
    if ($fy_end_date === null || $fy_end_date === $purchaseDate) {
        // Check if purchase is in previous financial year
        if (isPreviousFinancialYear($purchaseDate)) {
            $fy_end_date = getFinancialYearEndDate($purchaseDate);
        } else {
            // For current year, use today's date
            $fy_end_date = date('Y-m-d');
        }
    }
    
    debugLog("Continuing cascading to FY end", [
        'comp_id' => $comp_id,
        'itemCode' => $itemCode,
        'purchaseDate' => $purchaseDate,
        'fy_end_date' => $fy_end_date
    ]);
    
    // Default fy_end_date to end of current financial year if not provided
    if ($fy_end_date === null) {
        $fy_end_date = $_SESSION['FIN_YEAR_END'] ?? date('Y-m-t');
    }
    
    $purchaseDay = date('j', strtotime($purchaseDate));
    $purchaseMonth = date('n', strtotime($purchaseDate));
    $purchaseYear = date('Y', strtotime($purchaseDate));
    $currentDay = date('j');
    $currentMonth = date('n');
    $currentYear = date('Y');
    
    // Calculate the end month/year based on financial year
    $fyEndMonth = (int)date('n', strtotime($fy_end_date));
    $fyEndYear = (int)date('Y', strtotime($fy_end_date));
    
    // Get current date components for comparison
    $currentDay = (int)date('j');
    $currentMonth = (int)date('n');
    $currentYear = (int)date('Y');
    
    debugLog("FY end info vs current date", [
        'fyEndMonth' => $fyEndMonth,
        'fyEndYear' => $fyEndYear,
        'currentMonth' => $currentMonth,
        'currentYear' => $currentYear,
        'purchaseMonth' => $purchaseMonth,
        'purchaseYear' => $purchaseYear
    ]);
    
    // If purchase is in current month and year, cascading has already been handled in that month's table
    // For previous months in current FY, we need to cascade
    if ($purchaseMonth == $currentMonth && $purchaseYear == $currentYear) {
        debugLog("Purchase is in current month, cascading already handled");
        return;
    }
    
    // Start from the next month after purchase
    $startMonth = $purchaseMonth + 1;
    $startYear = $purchaseYear;
    if ($startMonth > 12) {
        $startMonth = 1;
        $startYear++;
    }
    
    debugLog("Starting cascading from month", [
        'startMonth' => $startMonth,
        'startYear' => $startYear,
        'fyEndMonth' => $fyEndMonth,
        'fyEndYear' => $fyEndYear
    ]);
    
    // Loop through months from purchase month+1 to end of financial year
    while (($startYear < $fyEndYear) || ($startYear == $fyEndYear && $startMonth <= $fyEndMonth)) {
        $month_2digit = str_pad($startMonth, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($startYear, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        
        // Check if this month's table exists (archived or current)
        $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name = '$archive_table'";
        $check_result = $conn->query($check_table_query);
        $table_exists = $check_result->fetch_assoc()['count'] > 0;
        
        // If table doesn't exist, create it
        if (!$table_exists) {
            // Create the archive table for this month
            $days_in_month = date('t', strtotime("$startYear-$startMonth-01"));
            
            $create_query = "CREATE TABLE $archive_table (
                `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
                `STK_DATE` date NOT NULL,
                `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
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
                KEY `idx_stk_month` (`STK_MONTH`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            if ($conn->query($create_query)) {
                debugLog("Created table during cascade: $archive_table");
                $table_exists = true;
            } else {
                debugLog("Failed to create table during cascade: " . $conn->error);
            }
        }
        
        if ($table_exists) {
            debugLog("Found table for cascading", [
                'table' => $archive_table,
                'month' => $startMonth,
                'year' => $startYear
            ]);
            
            $monthYear = date('Y-m', strtotime("$startYear-$startMonth-01"));
            
            // Get days in this month
            $daysInMonth = date('t', strtotime("$startYear-$startMonth-01"));
            
            // For the first month after purchase, opening should come from previous month's last day
            if ($startMonth == $purchaseMonth + 1 || ($startMonth == 1 && $purchaseMonth == 12)) {
                // Get previous month's last day closing
                $prevMonth = $purchaseMonth;
                $prevYear = $purchaseYear;
                $prevMonthDays = date('t', strtotime("$prevYear-$prevMonth-01"));
                
                $prevMonth_2digit = str_pad($prevMonth, 2, '0', STR_PAD_LEFT);
                $prevYear_2digit = substr($prevYear, -2);
                $prevTable = "tbldailystock_{$comp_id}_{$prevMonth_2digit}_{$prevYear_2digit}";
                
                $prevClosingColumn = "DAY_" . str_pad($prevMonthDays, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                
                $getPrevClosingQuery = "SELECT $prevClosingColumn as closing FROM $prevTable 
                                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prevStmt = $conn->prepare($getPrevClosingQuery);
                $prevMonthYear = date('Y-m', strtotime("$prevYear-$prevMonth-01"));
                $prevStmt->bind_param("ss", $prevMonthYear, $itemCode);
                $prevStmt->execute();
                $prevResult = $prevStmt->get_result();
                $prevRow = $prevResult->fetch_assoc();
                $prevStmt->close();
                
                $openingValue = $prevRow ? $prevRow['closing'] : 0;
                
                debugLog("Got opening value from previous month", [
                    'prevTable' => $prevTable,
                    'prevClosingColumn' => $prevClosingColumn,
                    'openingValue' => $openingValue
                ]);
                
                // Check if record exists, if not insert it
                $checkRecordQuery = "SELECT COUNT(*) as count FROM $archive_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $checkRecordStmt = $conn->prepare($checkRecordQuery);
                $checkRecordStmt->bind_param("ss", $monthYear, $itemCode);
                $checkRecordStmt->execute();
                $recordResult = $checkRecordStmt->get_result();
                $recordExists = $recordResult->fetch_assoc()['count'] > 0;
                $checkRecordStmt->close();
                
                if (!$recordExists) {
                    // Insert record with opening value
                    $insertQuery = "INSERT INTO $archive_table (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                                   VALUES (?, ?, 'F', ?, 0, 0, ?)";
                    $insertStmt = $conn->prepare($insertQuery);
                    $insertStmt->bind_param("ssii", $monthYear, $itemCode, $openingValue, $openingValue);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
                
                // Update the first day of this month with the opening value
                $updateOpeningQuery = "UPDATE $archive_table 
                                      SET DAY_01_OPEN = ?,
                                          DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $openingStmt = $conn->prepare($updateOpeningQuery);
                $openingStmt->bind_param("iss", $openingValue, $monthYear, $itemCode);
                $openingStmt->execute();
                $openingStmt->close();
                
                // Now cascade through the rest of this month
                for ($day = 2; $day <= $daysInMonth; $day++) {
                    $prevDay = $day - 1;
                    $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                    $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
                    $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
                    $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
                    $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                    
                    $updateDayQuery = "UPDATE $archive_table 
                                      SET $currentDayOpening = $prevDayClosing,
                                          $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                    
                    $dayStmt = $conn->prepare($updateDayQuery);
                    $dayStmt->bind_param("ss", $monthYear, $itemCode);
                    $dayStmt->execute();
                    $dayStmt->close();
                }
            } else {
                // For subsequent months, cascade from day 1
                updateSubsequentDaysInTable($conn, $archive_table, $monthYear, $itemCode, 1);
            }
        }
        
        // Move to next month
        $startMonth++;
        if ($startMonth > 12) {
            $startMonth = 1;
            $startYear++;
        }
    }
    
    // If we've reached end of financial year, ensure that month is also updated
    // (only if financial year end is before current month/year)
    if (($fyEndYear < $currentYear) || ($fyEndYear == $currentYear && $fyEndMonth < $currentMonth)) {
        // We need to update up to fy end month, not current month
        $cascadeEndMonth = $fyEndMonth;
        $cascadeEndYear = $fyEndYear;
    } else {
        // Financial year hasn't ended yet, use current month
        $cascadeEndMonth = $currentMonth;
        $cascadeEndYear = $currentYear;
    }
    
    if ($cascadeEndMonth != $purchaseMonth || $cascadeEndYear != $purchaseYear) {
        $dailyStockTable = "tbldailystock_" . $comp_id;
        $cascadeEndMonthYear = sprintf('%04d-%02d', $cascadeEndYear, $cascadeEndMonth);
        
        // Check if current month (end of FY) table exists, if not create it
        $checkCurrentTableQuery = "SELECT COUNT(*) as count FROM information_schema.tables 
                                 WHERE table_schema = DATABASE() 
                                 AND table_name = '$dailyStockTable'";
        $checkCurrentTableResult = $conn->query($checkCurrentTableQuery);
        $currentTableExists = $checkCurrentTableResult->fetch_assoc()['count'] > 0;
        
        if (!$currentTableExists) {
            // Create current month table with correct days for that month
            $days_in_cascade_month = date('t', strtotime($cascadeEndMonthYear . '-01'));
            
            $create_query = "CREATE TABLE $dailyStockTable (
                `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
                `STK_DATE` date NOT NULL,
                `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
                `ITEM_CODE` varchar(20) NOT NULL,
                `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
                `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),";
            
            for ($day = 1; $day <= $days_in_cascade_month; $day++) {
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
                KEY `idx_stk_month` (`STK_MONTH`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            $conn->query($create_query);
            debugLog("Created current month table: $dailyStockTable");
        }
        
        // Check if record exists in current month table
        $checkCurrentQuery = "SELECT COUNT(*) as count FROM $dailyStockTable 
                             WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $checkCurrentStmt = $conn->prepare($checkCurrentQuery);
        $checkCurrentStmt->bind_param("ss", $cascadeEndMonthYear, $itemCode);
        $checkCurrentStmt->execute();
        $currentResult = $checkCurrentStmt->get_result();
        $currentExists = $currentResult->fetch_assoc()['count'] > 0;
        $checkCurrentStmt->close();
        
        if ($currentExists) {
            // Get previous month's last day closing for opening value
            $prevMonth = $cascadeEndMonth - 1;
            $prevYear = $cascadeEndYear;
            if ($prevMonth < 1) {
                $prevMonth = 12;
                $prevYear--;
            }
            
            $prevMonthDays = date('t', strtotime("$prevYear-$prevMonth-01"));
            $prevMonth_2digit = str_pad($prevMonth, 2, '0', STR_PAD_LEFT);
            $prevYear_2digit = substr($prevYear, -2);
            $prevTable = "tbldailystock_{$comp_id}_{$prevMonth_2digit}_{$prevYear_2digit}";
            
            // Check if previous table exists
            $checkPrevTableQuery = "SELECT COUNT(*) as count FROM information_schema.tables 
                                   WHERE table_schema = DATABASE() 
                                   AND table_name = '$prevTable'";
            $checkPrevResult = $conn->query($checkPrevTableQuery);
            $prevTableExists = $checkPrevResult->fetch_assoc()['count'] > 0;
            
            $openingValue = 0;
            
            if ($prevTableExists) {
                $prevClosingColumn = "DAY_" . str_pad($prevMonthDays, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                $prevMonthYear = date('Y-m', strtotime("$prevYear-$prevMonth-01"));
                
                $getPrevClosingQuery = "SELECT $prevClosingColumn as closing FROM $prevTable 
                                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prevStmt = $conn->prepare($getPrevClosingQuery);
                $prevStmt->bind_param("ss", $prevMonthYear, $itemCode);
                $prevStmt->execute();
                $prevResult = $prevStmt->get_result();
                $prevRow = $prevResult->fetch_assoc();
                $prevStmt->close();
                
                $openingValue = $prevRow ? $prevRow['closing'] : 0;
            }
            
            // Update current month's day 1 opening
            $updateCurrentOpeningQuery = "UPDATE $dailyStockTable 
                                        SET DAY_01_OPEN = ?,
                                            DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $currentOpeningStmt = $conn->prepare($updateCurrentOpeningQuery);
            $currentOpeningStmt->bind_param("iss", $openingValue, $cascadeEndMonthYear, $itemCode);
            $currentOpeningStmt->execute();
            $currentOpeningStmt->close();
            
            // Cascade through current month up to end of FY month (or today if before FY end)
            $daysInCurrentMonth = date('t', strtotime($cascadeEndMonthYear . '-01'));
            $fyEndDay = (int)date('d', strtotime($fy_end_date));
            
            // If cascade end is in current month/year, use today; otherwise use FY end day
            if ($cascadeEndYear == $currentYear && $cascadeEndMonth == $currentMonth) {
                $cascadeTo = min($currentDay, $fyEndDay, $daysInCurrentMonth);
            } else {
                $cascadeTo = min($fyEndDay, $daysInCurrentMonth);
            }
            
            for ($day = 2; $day <= $cascadeTo; $day++) {
                $prevDay = $day - 1;
                $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
                $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
                $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
                $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                
                $updateDayQuery = "UPDATE $dailyStockTable 
                                  SET $currentDayOpening = $prevDayClosing,
                                      $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                                  WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                
                $dayStmt = $conn->prepare($updateDayQuery);
                $dayStmt->bind_param("ss", $cascadeEndMonthYear, $itemCode);
                $dayStmt->execute();
                $dayStmt->close();
            }
        }
    }
    
    debugLog("Cascading completed up to financial year end: " . $fy_end_date);
}

// Function to update item stock - NOW STORES FULL ITEM CODE WITHOUT CLEANING
function updateItemStock($conn, $itemCode, $totalBottles, $companyId, $fin_year_id) {
    $stockColumn = "CURRENT_STOCK" . $companyId;
    
    debugLog("Updating item stock with FULL code", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'company_id' => $companyId,
        'stock_column' => $stockColumn,
        'fin_year_id' => $fin_year_id
    ]);
    
    // First check if record exists
    $checkQuery = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ? AND FIN_YEAR = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("si", $itemCode, $fin_year_id);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $row = $checkResult->fetch_assoc();
    $exists = $row['count'] > 0;
    $checkStmt->close();
    
    if ($exists) {
        // Update existing record
        $updateQuery = "UPDATE tblitem_stock 
                       SET $stockColumn = $stockColumn + ? 
                       WHERE ITEM_CODE = ? AND FIN_YEAR = ?";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("isi", $totalBottles, $itemCode, $fin_year_id);
    } else {
        // Insert new record
        $updateQuery = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $stockColumn) 
                       VALUES (?, ?, ?)";
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("sii", $itemCode, $fin_year_id, $totalBottles);
    }
    
    $result = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    $stmt->close();
    
    debugLog("Item stock update result", [
        'success' => $result,
        'operation' => $exists ? 'update' : 'insert',
        'affected_rows' => $affectedRows
    ]);
    
    return $result;
}

// Function to update MRP in tblitemmaster - USE CLEAN CODE FOR MRP UPDATE ONLY
function updateItemMRP($conn, $itemCode, $mrp) {
    // Clean the item code by removing SCM prefix for MRP update
    // because tblitemmaster stores codes without SCM prefix
    $cleanCode = cleanItemCode($itemCode);
    
    debugLog("Updating MRP for item (using clean code)", [
        'original_code' => $itemCode,
        'clean_code' => $cleanCode,
        'mrp' => $mrp
    ]);
    
    // Update MPRICE in tblitemmaster
    $updateQuery = "UPDATE tblitemmaster SET MPRICE = ? WHERE CODE = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("ds", $mrp, $cleanCode);
    
    $result = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    
    debugLog("MRP update result", [
        'success' => $result,
        'affected_rows' => $affectedRows,
        'clean_code' => $cleanCode,
        'mrp' => $mrp
    ]);
    
    $stmt->close();
    
    return $result;
}

// Function to cascade stock through all months of a previous financial year
// Updates from purchase month+1 until March of that FY
function cascadeToFinancialYearEnd($conn, $comp_id, $item_code, $purchase_date, $total_bottles) {
    $purchase_timestamp = strtotime($purchase_date);
    $purchase_month = date('n', $purchase_timestamp);
    $purchase_year = date('Y', $purchase_timestamp);
    
    // Get financial year end date (use function from stock_functions.php)
    $fy_end_date = getFinancialYearEndDate($purchase_date);
    $fy_end_month = (int)date('n', strtotime($fy_end_date));
    $fy_end_year = (int)date('Y', strtotime($fy_end_date));
    
    debugLog("Cascading to FY end for previous year purchase", [
        'item_code' => $item_code,
        'purchase_date' => $purchase_date,
        'purchase_month' => $purchase_month,
        'purchase_year' => $purchase_year,
        'fy_end_date' => $fy_end_date,
        'fy_end_month' => $fy_end_month,
        'fy_end_year' => $fy_end_year
    ]);
    
    // Start from the next month after purchase
    $start_month = $purchase_month + 1;
    $start_year = $purchase_year;
    
    if ($start_month > 12) {
        $start_month = 1;
        $start_year++;
    }
    
    debugLog("Starting cascade from", ['start_month' => $start_month, 'start_year' => $start_year]);
    
    // Get the closing value from the purchase month to use as opening for next month
    $purchase_month_2digit = str_pad($purchase_month, 2, '0', STR_PAD_LEFT);
    $purchase_year_2digit = substr($purchase_year, -2);
    $purchase_table = "tbldailystock_{$comp_id}_{$purchase_month_2digit}_{$purchase_year_2digit}";
    
    // Find the last day of purchase month with the purchase
    // First, get the actual number of days in the purchase month
    $days_in_purchase_month = date('t', $purchase_timestamp);
    
    $last_day_with_purchase = 0;
    for ($day = $days_in_purchase_month; $day >= 1; $day--) {
        $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
        $purchase_col = "DAY_{$day_str}_PURCHASE";
        $check_query = "SELECT $purchase_col as purch FROM $purchase_table WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $check_stmt = $conn->prepare($check_query);
        $purchase_month_year = date('Y-m', strtotime($purchase_date));
        $check_stmt->bind_param("ss", $item_code, $purchase_month_year);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $row = $check_result->fetch_assoc();
            if ($row['purch'] > 0) {
                $last_day_with_purchase = $day;
                break;
            }
        }
        $check_stmt->close();
    }
    
    // Get the closing from the last day with purchase
    $closing_value = 0;
    if ($last_day_with_purchase > 0) {
        $last_day_str = str_pad($last_day_with_purchase, 2, '0', STR_PAD_LEFT);
        $closing_col = "DAY_{$last_day_str}_CLOSING";
        $get_closing_query = "SELECT $closing_col as closing FROM $purchase_table WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $closing_stmt = $conn->prepare($get_closing_query);
        $closing_stmt->bind_param("ss", $item_code, $purchase_month_year);
        $closing_stmt->execute();
        $closing_result = $closing_stmt->get_result();
        if ($closing_result->num_rows > 0) {
            $closing_row = $closing_result->fetch_assoc();
            $closing_value = (int)$closing_row['closing'];
        }
        $closing_stmt->close();
    }
    
    debugLog("Last day with purchase: $last_day_with_purchase, closing value: $closing_value");
    
    // Loop through months from purchase month+1 to end of financial year (March)
    while ($start_year < $fy_end_year || ($start_year == $fy_end_year && $start_month <= $fy_end_month)) {
        $month_2digit = str_pad($start_month, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($start_year, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        
        debugLog("Processing month", ['table' => $archive_table, 'month' => $start_month, 'year' => $start_year]);
        
        // Check if this month's table exists
        $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name = '$archive_table'";
        $check_result = $conn->query($check_table_query);
        $table_exists = $check_result ? $check_result->fetch_assoc()['count'] > 0 : false;
        
        // If table doesn't exist, create it
        if (!$table_exists) {
            $days_in_month = date('t', strtotime("$start_year-$start_month-01"));
            
            $create_query = "CREATE TABLE $archive_table (
                `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
                `STK_DATE` date NOT NULL,
                `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
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
                KEY `idx_stk_month` (`STK_MONTH`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            if ($conn->query($create_query)) {
                debugLog("Created archive table: $archive_table");
                $table_exists = true;
            }
        }
        
        if ($table_exists) {
            $monthYear = date('Y-m', strtotime("$start_year-$start_month-01"));
            $daysInMonth = date('t', strtotime("$start_year-$start_month-01"));
            
            // Check if record exists
            $checkRecordQuery = "SELECT COUNT(*) as count FROM $archive_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $checkRecordStmt = $conn->prepare($checkRecordQuery);
            $checkRecordStmt->bind_param("ss", $monthYear, $item_code);
            $checkRecordStmt->execute();
            $recordResult = $checkRecordStmt->get_result();
            $recordExists = $recordResult->fetch_assoc()['count'] > 0;
            $checkRecordStmt->close();
            
            if (!$recordExists) {
                // Insert new record with opening value from previous month
                $insertQuery = "INSERT INTO $archive_table (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                               VALUES (?, ?, 'F', ?, 0, 0, ?)";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("ssii", $monthYear, $item_code, $closing_value, $closing_value);
                $insertStmt->execute();
                $insertStmt->close();
                
                debugLog("Inserted new record in $archive_table with opening=$closing_value");
            } else {
                // Update existing record - set day 1 opening from previous month
                $updateOpeningQuery = "UPDATE $archive_table 
                                      SET DAY_01_OPEN = ?,
                                          DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $openingStmt = $conn->prepare($updateOpeningQuery);
                $openingStmt->bind_param("iss", $closing_value, $monthYear, $item_code);
                $openingStmt->execute();
                $openingStmt->close();
                
                debugLog("Updated existing record in $archive_table with opening=$closing_value");
            }
            
            // Cascade through all days of this month
            for ($day = 2; $day <= $daysInMonth; $day++) {
                $prevDay = $day - 1;
                $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
                $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
                $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
                $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                
                $updateDayQuery = "UPDATE $archive_table 
                                  SET $currentDayOpening = $prevDayClosing,
                                      $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                                  WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                
                $dayStmt = $conn->prepare($updateDayQuery);
                $dayStmt->bind_param("ss", $monthYear, $item_code);
                $dayStmt->execute();
                $dayStmt->close();
            }
            
            // Get the closing value from this month to use for next month
            $lastDayStr = str_pad($daysInMonth, 2, '0', STR_PAD_LEFT);
            $lastDayClosingCol = "DAY_{$lastDayStr}_CLOSING";
            $getClosingQuery = "SELECT $lastDayClosingCol as closing FROM $archive_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $getClosingStmt = $conn->prepare($getClosingQuery);
            $getClosingStmt->bind_param("ss", $monthYear, $item_code);
            $getClosingStmt->execute();
            $closingResult = $getClosingStmt->get_result();
            if ($closingResult->num_rows > 0) {
                $closingRow = $closingResult->fetch_assoc();
                $closing_value = (int)$closingRow['closing'];
            }
            $getClosingStmt->close();
            
            debugLog("Got closing value for next month: $closing_value");
        }
        
        // Move to next month
        $start_month++;
        if ($start_month > 12) {
            $start_month = 1;
            $start_year++;
        }
    }
    
    debugLog("Cascading completed to FY end: " . $fy_end_date);
}

// Function to update stock for purchases in previous financial years
function updatePreviousYearStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate, $fin_year_id) {
    debugLog("Updating previous year stock", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'fin_year_id' => $fin_year_id
    ]);
    
    // Update archived month stock
    updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
    
    // Cascade through all remaining months until FY end (March)
    // This handles all months from purchase month+1 through March of that FY
    cascadeToFinancialYearEnd($conn, $comp_id, $itemCode, $purchaseDate, $totalBottles);
    
    // Update tblitem_stock with FULL item code
    updateItemStock($conn, $itemCode, $totalBottles, $comp_id, $fin_year_id);
}

// Function to update stock for purchases in current financial year
function updateCurrentYearStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate, $fin_year_id) {
    debugLog("Updating current year stock", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'fin_year_id' => $fin_year_id
    ]);
    
    // Check if this month is archived
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $isArchived = isMonthArchived($conn, $comp_id, $month, $year);
    
    if ($isArchived) {
        debugLog("Month is archived, updating archive table with cascading");
        // Update archived month data with cascading
        updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
        
        // Continue cascading to current month (within same FY)
        continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate, null);
    } else {
        debugLog("Month is current, updating current table with cascading");
        // Update current month data with cascading
        updateCurrentMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
    }
    
    // Update tblitem_stock with FULL item code
    updateItemStock($conn, $itemCode, $totalBottles, $comp_id, $fin_year_id);
}

// Function to update stock after purchase - Main Entry Point
// DETERMINES whether to use current year or previous year logic
function updateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn, $fin_year_id) {
    debugLog("updateStock called", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'company_id' => $companyId,
        'fin_year_id' => $fin_year_id
    ]);
    
    // Determine if purchase is in current or previous financial year
    // Use the function from stock_functions.php
    if (isPreviousFinancialYear($purchaseDate)) {
        // USE NEW LOGIC for previous financial years
        debugLog("Using PREVIOUS YEAR logic for stock update");
        updatePreviousYearStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate, $fin_year_id);
    } else {
        // USE EXISTING LOGIC for current financial year
        debugLog("Using CURRENT YEAR logic for stock update");
        updateCurrentYearStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate, $fin_year_id);
    }
}

// ---- Items (for case rate lookup & modal) - FILTERED BY CATEGORY USING 4-LAYER STRUCTURE ----
$items = [];

// Get allowed categories based on license type
$allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);

if (!empty($allowed_categories)) {
    $category_codes = array_column($allowed_categories, 'CATEGORY_CODE');
    $category_placeholders = implode(',', array_fill(0, count($category_codes), '?'));
    
    $itemsQuery = "SELECT 
                        im.CODE, 
                        im.DETAILS, 
                        im.DETAILS2, 
                        im.PPRICE, 
                        im.ITEM_GROUP, 
                        im.LIQ_FLAG, 
                        im.CLASS_CODE_NEW AS CLASS,
                        COALESCE(s.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE,
                        CONCAT('SCM', im.CODE) AS SCM_CODE,
                        c.CATEGORY_NAME,
                        cn.CLASS_NAME,
                        sn.SUBCLASS_NAME,
                        im.CATEGORY_CODE,
                        im.CLASS_CODE_NEW AS CLASS_CODE_NEW
                   FROM tblitemmaster im
                   LEFT JOIN tblcategory c ON im.CATEGORY_CODE = c.CATEGORY_CODE
                   LEFT JOIN tblclass_new cn ON im.CLASS_CODE_NEW = cn.CLASS_CODE
                   LEFT JOIN tblsubclass_new sn ON im.SUBCLASS_CODE_NEW = sn.SUBCLASS_CODE
                   LEFT JOIN tblsize s ON im.SIZE_CODE = s.SIZE_CODE
                   WHERE im.CATEGORY_CODE IN ($category_placeholders)
                   ORDER BY im.DETAILS";
    
    $params = $category_codes;
    $types = str_repeat('s', count($params));
    
    debugLog("Items query parameters", [
        'query' => $itemsQuery,
        'params' => $params,
        'types' => $types
    ]);
    
    $itemsStmt = $conn->prepare($itemsQuery);
    $itemsStmt->bind_param($types, ...$params);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    if ($itemsResult) $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();
    
    debugLog("Items fetched from database", [
        'count' => count($items),
        'category_filter_applied' => true,
        'allowed_categories' => $allowed_categories
    ]);
} else {
    // If no categories allowed, show empty result
    $items = [];
    debugLog("No items fetched - no allowed categories for license type: " . $license_type);
}

// ---- Suppliers (for name/code replacement) ----
$suppliers = [];
$suppliersStmt = $conn->prepare("SELECT CODE, DETAILS FROM tblsupplier ORDER BY DETAILS");
$suppliersStmt->execute();
$suppliersResult = $suppliersStmt->get_result();
if ($suppliersResult) $suppliers = $suppliersResult->fetch_all(MYSQLI_ASSOC);
$suppliersStmt->close();

debugLog("Suppliers fetched", [
    'count' => count($suppliers)
]);

// ---- Save purchase ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    debugLog("=== FORM SUBMISSION STARTED ===");
    
    // ============================================================================
    // ULTRA-FAST PURCHASE SAVE - Apply same optimizations as bill generation
    // ============================================================================
    
    // Set ultra-fast database settings
    try {
        $conn->query("SET SESSION unique_checks = 0");
        $conn->query("SET SESSION foreign_key_checks = 0");
        $conn->query("SET SESSION sql_log_bin = 0");
        $conn->query("SET autocommit = 0");
        $conn->query("SET SESSION bulk_insert_buffer_size = 1024 * 1024 * 256");
        $conn->query("SET SESSION innodb_flush_log_at_trx_commit = 2");
        $conn->query("SET SESSION sync_binlog = 0");
        $conn->query("SET SESSION innodb_autoinc_lock_mode = 2");
    } catch (Exception $e) {
        // Continue even if some settings fail
    }
    
    // Get form data
    $date = $_POST['date'];
    $voc_no = $_POST['voc_no'];
    $auto_tp_no = $_POST['auto_tp_no'] ?? '';
    $tp_no = $_POST['tp_no'] ?? '';
    $tp_date = $_POST['tp_date'] ?? '';
    $inv_no = $_POST['inv_no'] ?? '';
    $inv_date = $_POST['inv_date'] ?? '';
    $supplier_code = $_POST['supplier_code'] ?? '';
    $supplier_name = $_POST['supplier_name'] ?? '';
    
    // Check if TP number is unique (if provided)
    if (!empty($tp_no)) {
        if (!isTPNoUnique($conn, $tp_no, $companyId)) {
            $errorMessage = "TP Number '$tp_no' already exists. Please enter a unique TP number.";
            debugLog("Duplicate TP number detected", [
                'tp_no' => $tp_no,
                'company_id' => $companyId
            ]);
            
            // Store the error and redirect back with the error message
            $_SESSION['tp_no_duplicate_error'] = $errorMessage;
            $_SESSION['form_data'] = $_POST; // Save form data for repopulation
            header("Location: purchases.php?mode=" . urlencode($mode) . "&tp_error=1");
            exit;
        }
    }
    
    // Charges and taxes
    $cash_disc = $_POST['cash_disc'] ?? 0;
    $trade_disc = $_POST['trade_disc'] ?? 0;
    $octroi = $_POST['octroi'] ?? 0;
    $freight = $_POST['freight'] ?? 0;
    $stax_per = $_POST['stax_per'] ?? 0;
    $stax_amt = $_POST['stax_amt'] ?? 0;
    $tcs_per = $_POST['tcs_per'] ?? 0;
    $tcs_amt = $_POST['tcs_amt'] ?? 0;
    $misc_charg = $_POST['misc_charg'] ?? 0;
    $basic_amt = $_POST['basic_amt'] ?? 0;
    $tamt = $_POST['tamt'] ?? 0;
    
    // Insert purchase header
    $insertQuery = "INSERT INTO tblpurchases (
        DATE, SUBCODE, AUTO_TPNO, VOC_NO, INV_NO, INV_DATE, TAMT, 
        TPNO, TP_DATE, SCHDIS, CASHDIS, OCTROI, FREIGHT, STAX_PER, STAX_AMT, 
        TCS_PER, TCS_AMT, MISC_CHARG, PUR_FLAG, CompID
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insertStmt = $conn->prepare($insertQuery);
    if ($insertStmt) {
        $pur_flag = 'T';
        
        $insertStmt->bind_param(
            "sssisssdddddddddddsi",
            $date, $supplier_code, $auto_tp_no, $voc_no, $inv_no, $inv_date, $tamt,
            $tp_no, $tp_date, $trade_disc, $cash_disc, $octroi, $freight, $stax_per, $stax_amt,
            $tcs_per, $tcs_amt, $misc_charg, $pur_flag, $companyId
        );
    } else {
        $errorMessage = "Error preparing statement: " . $conn->error;
    }
    
    if ($insertStmt->execute()) {
        $purchase_id = $conn->insert_id;
        
        // ============================================================================
        // ULTRA-FAST: BULK INSERT PURCHASE DETAILS
        // ============================================================================
        if (isset($_POST['items']) && is_array($_POST['items']) && !empty($_POST['items'])) {
            $detailValues = [];
            $stockUpdates = [];
            $mrpUpdates = [];
            
            foreach ($_POST['items'] as $index => $item) {
                $item_code = $item['code'] ?? '';
                $item_name = $item['name'] ?? '';
                $item_size = $item['size'] ?? '';
                $cases = floatval($item['cases'] ?? 0);
                $bottles = intval($item['bottles'] ?? 0);
                $free_cases = floatval($item['free_cases'] ?? 0);
                $free_bottles = intval($item['free_bottles'] ?? 0);
                $case_rate = floatval($item['case_rate'] ?? 0);
                $mrp = floatval($item['mrp'] ?? 0);
                $bottles_per_case = intval($item['bottles_per_case'] ?? 12);
                $batch_no = $item['batch_no'] ?? '';
                $auto_batch = $item['auto_batch'] ?? '';
                $mfg_month = $item['mfg_month'] ?? '';
                $bl = floatval($item['bl'] ?? 0);
                $vv = floatval($item['vv'] ?? 0);
                $tot_bott = intval($item['tot_bott'] ?? 0);
                
                // Calculate amount
                $amount = ($cases * $case_rate) + ($bottles * ($case_rate / $bottles_per_case));
                
                // Escape strings for bulk insert
                $item_code_esc = $conn->real_escape_string($item_code);
                $item_name_esc = $conn->real_escape_string($item_name);
                $item_size_esc = $conn->real_escape_string($item_size);
                $batch_no_esc = $conn->real_escape_string($batch_no);
                $auto_batch_esc = $conn->real_escape_string($auto_batch);
                $mfg_month_esc = $conn->real_escape_string($mfg_month);
                
                // Collect for bulk insert
                $detailValues[] = "($purchase_id, '$item_code_esc', '$item_name_esc', '$item_size_esc', $cases, $bottles, $free_cases, $free_bottles, $case_rate, $mrp, $amount, $bottles_per_case, '$batch_no_esc', '$auto_batch_esc', '$mfg_month_esc', $bl, $vv, $tot_bott)";
                
                // Collect MRP updates (use clean code for MRP update in tblitemmaster)
                if ($mrp > 0) {
                    $cleanCode = cleanItemCode($item_code);
                    $mrpUpdates[$cleanCode] = $mrp;
                }
                
                // Collect stock updates for batch processing - STORE FULL ITEM CODE
                if ($tot_bott > 0) {
                    if (!isset($stockUpdates[$item_code])) {
                        $stockUpdates[$item_code] = 0;
                    }
                    $stockUpdates[$item_code] += $tot_bott;
                }
            }
            
            // ============================================================================
            // BULK INSERT ALL PURCHASE DETAILS AT ONCE
            // ============================================================================
            if (!empty($detailValues)) {
                $detailBulkQuery = "INSERT INTO tblpurchasedetails (
                    PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, FreeCases, FreeBottles, 
                    CaseRate, MRP, Amount, BottlesPerCase, BatchNo, AutoBatch, MfgMonth, BL, VV, TotBott
                ) VALUES " . implode(',', $detailValues);
                
                if (!$conn->query($detailBulkQuery)) {
                    debugLog("Bulk insert failed, falling back to individual inserts: " . $conn->error);
                    // Fall back to individual inserts if bulk fails
                    $detailStmt = $conn->prepare("INSERT INTO tblpurchasedetails (
                        PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, FreeCases, FreeBottles, 
                        CaseRate, MRP, Amount, BottlesPerCase, BatchNo, AutoBatch, MfgMonth, BL, VV, TotBott
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    
                    foreach ($_POST['items'] as $index => $item) {
                        $item_code = $item['code'] ?? '';
                        $item_name = $item['name'] ?? '';
                        $item_size = $item['size'] ?? '';
                        $cases = floatval($item['cases'] ?? 0);
                        $bottles = intval($item['bottles'] ?? 0);
                        $free_cases = floatval($item['free_cases'] ?? 0);
                        $free_bottles = intval($item['free_bottles'] ?? 0);
                        $case_rate = floatval($item['case_rate'] ?? 0);
                        $mrp = floatval($item['mrp'] ?? 0);
                        $bottles_per_case = intval($item['bottles_per_case'] ?? 12);
                        $batch_no = $item['batch_no'] ?? '';
                        $auto_batch = $item['auto_batch'] ?? '';
                        $mfg_month = $item['mfg_month'] ?? '';
                        $bl = floatval($item['bl'] ?? 0);
                        $vv = floatval($item['vv'] ?? 0);
                        $tot_bott = intval($item['tot_bott'] ?? 0);
                        $amount = ($cases * $case_rate) + ($bottles * ($case_rate / $bottles_per_case));
                        
                        $detailStmt->bind_param(
                            "isssdddddddisssddi",
                            $purchase_id, $item_code, $item_name, $item_size,
                            $cases, $bottles, $free_cases, $free_bottles,
                            $case_rate, $mrp, $amount, $bottles_per_case,
                            $batch_no, $auto_batch, $mfg_month, $bl, $vv, $tot_bott
                        );
                        $detailStmt->execute();
                        
                        // Update MRP (use clean code for tblitemmaster)
                        if ($mrp > 0) {
                            updateItemMRP($conn, $item_code, $mrp);
                        }
                        
                        // Update stock - PASS FULL ITEM CODE
                        if ($tot_bott > 0) {
                            updateStock($item_code, $tot_bott, $date, $companyId, $conn, $fin_year_id);
                        }
                    }
                    $detailStmt->close();
                }
            }
            
            // ============================================================================
            // BULK UPDATE MRP - FOR TBLITEMMASTER (USING CLEAN CODES)
            // ============================================================================
            if (!empty($mrpUpdates)) {
                foreach ($mrpUpdates as $cleanCode => $mrp) {
                    $mrp_esc = $conn->real_escape_string($mrp);
                    $conn->query("UPDATE tblitemmaster SET MPRICE = '$mrp_esc' WHERE CODE = '$cleanCode'");
                }
            }
            
            // ============================================================================
            // BULK UPDATE STOCK - FOR TBLITEM_STOCK (USING FULL ITEM CODES)
            // ============================================================================
            if (!empty($stockUpdates)) {
                // First, update tblitem_stock with bulk operation
                $stockColumn = "CURRENT_STOCK" . $companyId;
                $stockValues = [];
                
                foreach ($stockUpdates as $itemCode => $totalBottles) {
                    // Store the FULL item code WITHOUT removing SCM prefix
                    $code_esc = $conn->real_escape_string($itemCode);
                    $stockValues[] = "('$code_esc', '$fin_year_id', $totalBottles)";
                }
                
                if (!empty($stockValues)) {
                    // Bulk upsert into tblitem_stock
                    $stockBulkQuery = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $stockColumn) 
                                      VALUES " . implode(',', $stockValues) . "
                                      ON DUPLICATE KEY UPDATE $stockColumn = $stockColumn + VALUES($stockColumn)";
                    
                    if ($conn->query($stockBulkQuery)) {
                        debugLog("Bulk stock update successful", [
                            'records' => count($stockValues)
                        ]);
                    } else {
                        debugLog("Bulk stock update failed: " . $conn->error);
                        
                        // Fall back to individual updates
                        foreach ($stockUpdates as $itemCode => $totalBottles) {
                            updateItemStock($conn, $itemCode, $totalBottles, $companyId, $fin_year_id);
                        }
                    }
                }
                
                // Now update daily stock - need to process each date separately
                // Group by date first
                $dailyStockByDate = [];
                foreach ($stockUpdates as $itemCode => $totalBottles) {
                    $dateKey = $date; // All items in same purchase date
                    if (!isset($dailyStockByDate[$dateKey])) {
                        $dailyStockByDate[$dateKey] = [];
                    }
                    $dailyStockByDate[$dateKey][$itemCode] = $totalBottles;
                }
                
                foreach ($dailyStockByDate as $purchaseDate => $items) {
                    foreach ($items as $itemCode => $totalBottles) {
                        updateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn, $fin_year_id);
                    }
                }
            }
        }
        
        // ============================================================================
        // RENUMBER VOC_NO BASED ON TP_DATE
        // After inserting a new purchase, renumber all purchases for the company
        // based on chronological order of TP_DATE (or DATE if TP_DATE is empty)
        // ============================================================================
        renumberVoucherNumbers($conn, $companyId);
        
        // ============================================================================
        // COMMIT ALL CHANGES AT ONCE
        // ============================================================================
        $conn->commit();
        
        // Re-enable constraints
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->query("SET UNIQUE_CHECKS = 1");
        
        debugLog("=== FORM SUBMISSION COMPLETED SUCCESSFULLY ===");
        header("Location: purchase_module.php?mode=".$mode."&success=1");
        exit;
    } else {
        $errorMessage = "Error saving purchase: " . $insertStmt->error;
        $conn->rollback();
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->query("SET UNIQUE_CHECKS = 1");
    }
    
    $insertStmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>New Purchase</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
<style>
.table-container {
    overflow-x: auto;
    max-height: 420px;
    margin: 20px 0;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
}

.styled-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    table-layout: fixed;
}

.styled-table th, 
.styled-table td {
    border: 1px solid #e5e7eb;
    padding: 6px 8px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
}

.styled-table thead th {
    position: sticky;
    top: 0;
    background: #f8fafc;
    z-index: 1;
    font-weight: 600;
}

.styled-table tbody tr:hover {
    background-color: #f8f9fa;
}

/* Column widths */
.styled-table th.col-code,
.styled-table td.col-code { width: 120px; }
.styled-table th.col-name,
.styled-table td.col-name { width: 180px; }
.styled-table th.col-size,
.styled-table td.col-size { width: 80px; }
.styled-table th.col-cases,
.styled-table td.col-cases { width: 70px; }
.styled-table th.col-bottles,
.styled-table td.col-bottles { width: 70px; }
.styled-table th.col-free-cases,
.styled-table td.col-free-cases { width: 70px; }
.styled-table th.col-free-bottles,
.styled-table td.col-free-bottles { width: 70px; }
.styled-table th.col-rate,
.styled-table td.col-rate { width: 80px; }
.styled-table th.col-amount,
.styled-table td.col-amount { width: 80px; }
.styled-table th.col-mrp,
.styled-table td.col-mrp { width: 80px; }
.styled-table th.col-batch,
.styled-table td.col-batch { width: 90px; }
.styled-table th.col-auto-batch,
.styled-table td.col-auto-batch { width: 100px; }
.styled-table th.col-mfg,
.styled-table td.col-mfg { width: 90px; }
.styled-table th.col-bl,
.styled-table td.col-bl { width: 70px; }
.styled-table th.col-vv,
.styled-table td.col-vv { width: 70px; }
.styled-table th.col-totbott,
.styled-table td.col-totbott { width: 80px; }
.styled-table th.col-action,
.styled-table td.col-action { width: 60px; }

/* Column alignments */
.styled-table th:nth-child(1),
.styled-table td:nth-child(1),
.styled-table th:nth-child(2),
.styled-table td:nth-child(2) {
    text-align: left;
    padding-left: 10px;
}

.styled-table th:nth-child(3),
.styled-table td:nth-child(3),
.styled-table th:nth-child(4),
.styled-table td:nth-child(4),
.styled-table th:nth-child(5),
.styled-table td:nth-child(5),
.styled-table th:nth-child(6),
.styled-table td:nth-child(6),
.styled-table th:nth-child(7),
.styled-table td:nth-child(7) {
    text-align: center;
}

.styled-table th:nth-child(8),
.styled-table td:nth-child(8),
.styled-table th:nth-child(9),
.styled-table td:nth-child(9),
.styled-table th:nth-child(10),
.styled-table td:nth-child(10) {
    text-align: right;
    padding-right: 12px;
}

.styled-table th:nth-child(11),
.styled-table td:nth-child(11),
.styled-table th:nth-child(12),
.styled-table td:nth-child(12),
.styled-table th:nth-child(13),
.styled-table td:nth-child(13) {
    text-align: left;
    padding-left: 8px;
}

.styled-table th:nth-child(14),
.styled-table td:nth-child(14),
.styled-table th:nth-child(15),
.styled-table td:nth-child(15),
.styled-table th:nth-child(16),
.styled-table td:nth-child(16) {
    text-align: right;
    padding-right: 12px;
}

.styled-table th:nth-child(17),
.styled-table td:nth-child(17) {
    text-align: center;
}

/* Input fields */
.styled-table input[type="number"],
.styled-table input[type="text"] {
    width: 100%;
    box-sizing: border-box;
    padding: 0.25rem 0.5rem;
    font-size: 0.8rem;
    border: 1px solid #ced4da;
    border-radius: 0.25rem;
}

/* Totals row */
.totals-row td:nth-child(1),
.totals-row td:nth-child(2),
.totals-row td:nth-child(3) {
    text-align: left;
    font-weight: bold;
    background-color: #f8f9fa;
}

.totals-row td:nth-child(4),
.totals-row td:nth-child(5),
.totals-row td:nth-child(6),
.totals-row td:nth-child(7) {
    text-align: center;
    font-weight: bold;
    background-color: #f8f9fa;
}

.totals-row td:nth-child(8),
.totals-row td:nth-child(9),
.totals-row td:nth-child(10),
.totals-row td:nth-child(11),
.totals-row td:nth-child(12),
.totals-row td:nth-child(13),
.totals-row td:nth-child(14),
.totals-row td:nth-child(15),
.totals-row td:nth-child(16) {
    text-align: right;
    font-weight: bold;
    background-color: #f8f9fa;
}

.totals-row td:nth-child(17) {
    text-align: center;
    font-weight: bold;
    background-color: #f8f9fa;
}

/* Bottles by size table */
#bottlesBySizeTable th {
    font-size: 0.75rem;
    padding: 4px 6px;
}
#bottlesBySizeTable td {
    font-size: 0.85rem;
    padding: 4px 6px;
}

/* Missing items modal */
#licenseRestrictedList tr:hover,
#missingItemsList tr:hover {
  background-color: #f8f9fa;
}
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <h4 class="mb-3">New Purchase</h4>

      <!-- Financial Year Indicator -->
      <div class="alert alert-info mb-3 py-2">
          <strong><i class="fas fa-calendar"></i> Financial Year: <?= htmlspecialchars(($fin_year_start ?? '') . ' to ' . ($fin_year_end ?? '')) ?></strong>
          <span class="ms-2 text-muted">(Working with year: <?= htmlspecialchars($_SESSION['FIN_YEAR_ID'] ?? 'Not Set') ?>)</span>
      </div>

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

       <?php if (isset($errorMessage)): ?>
         <div class="alert alert-danger"><?= htmlspecialchars($errorMessage) ?></div>
       <?php endif; ?>

       <?php if (isset($_GET['tp_error']) && $_GET['tp_error'] == 1 && isset($_SESSION['tp_no_duplicate_error'])): ?>
         <div class="alert alert-danger">
             <i class="fa-solid fa-circle-exclamation me-2"></i>
             <?= htmlspecialchars($_SESSION['tp_no_duplicate_error']) ?>
         </div>
         <?php unset($_SESSION['tp_no_duplicate_error']); ?>
       <?php endif; ?>

       <?php if (isset($_SESSION['form_data'])): ?>
         <script>
             $(function() {
                 // Repopulate form with saved data
                 <?php
                 $formData = $_SESSION['form_data'];
                 unset($_SESSION['form_data']);
                 
                 foreach ($formData as $key => $value) {
                     if ($key != 'items' && is_string($value)) {
                         echo "$('[name=\"$key\"]').val('" . addslashes($value) . "');\n";
                     }
                 }
                 ?>
             });
         </script>
       <?php endif; ?>

      <div class="alert alert-info">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="fa-solid fa-paste"></i>
          <strong>Paste from SCM System</strong>
        </div>
        <button id="pasteFromSCM" class="btn btn-primary btn-sm">
          <i class="fa-solid fa-clipboard"></i> Paste SCM Data
        </button>
      </div>

      <form method="POST" id="purchaseForm">
        <input type="hidden" name="mode" value="<?=htmlspecialchars($mode)?>">
        <input type="hidden" name="voc_no" value="<?=$nextVoc?>">

        <!-- HEADER -->
        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-receipt me-2"></i>Purchase Information</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label">Voucher No.</label>
                <input class="form-control" value="<?=$nextVoc?>" disabled>
              </div>
              <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="date" 
                       value="<?= isset($_SESSION['FIN_YEAR_START']) ? min($_SESSION['FIN_YEAR_START'], date('Y-m-d')) : date('Y-m-d') ?>"
                       min="<?= htmlspecialchars($_SESSION['FIN_YEAR_START'] ?? '') ?>"
                       max="<?= htmlspecialchars($_SESSION['FIN_YEAR_END'] ?? '') ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Auto TP No.</label>
                <input type="text" class="form-control" name="auto_tp_no" id="autoTpNo">
              </div>
              <div class="col-md-3">
                <label class="form-label">T.P. No.</label>
                <input type="text" class="form-control" name="tp_no" id="tpNo">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-3">
                <label class="form-label">T.P. Date</label>
                <input type="date" class="form-control" name="tp_date" id="tpDate">
              </div>
              <div class="col-md-3">
                <label class="form-label">Invoice No.</label>
                <input type="text" class="form-control" name="inv_no">
              </div>
              <div class="col-md-3">
                <label class="form-label">Invoice Date</label>
                <input type="date" class="form-control" name="inv_date">
              </div>
              <div class="col-md-3">
                <label class="form-label">Supplier</label>
                <div class="supplier-container">
                  <input type="text" class="form-control" name="supplier_name" id="supplierInput" placeholder="Type supplier name" required>
                  <div class="supplier-suggestions" id="supplierSuggestions"></div>
                </div>
                <select class="form-select mt-1" id="supplierSelect">
                  <option value="">Select Supplier</option>
                  <?php foreach($suppliers as $s): ?>
                    <option value="<?=htmlspecialchars($s['DETAILS'])?>"
                            data-code="<?=htmlspecialchars($s['CODE'])?>">
                      <?=htmlspecialchars($s['DETAILS'])?> (<?=htmlspecialchars($s['CODE'])?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="supplier_code" id="supplierCodeHidden">
              </div>
            </div>
          </div>
        </div>

        <!-- TOTAL BOTTLES BY SIZE -->
        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-wine-bottle me-2"></i>Total Bottles by Size</div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-bordered table-sm mb-0" id="bottlesBySizeTable">
                <thead class="table-light">
                  <tr id="sizeHeaders"></tr>
                </thead>
                <tbody>
                  <tr id="sizeValues"></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- ITEMS -->
        <div class="card mb-4">
          <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="fa-solid fa-list me-2"></i>Purchase Items</span>
            <div>
              <button class="btn btn-sm btn-primary" type="button" id="addItem"><i class="fa-solid fa-plus"></i> Add Item</button>
              <button class="btn btn-sm btn-secondary" type="button" id="clearItems"><i class="fa-solid fa-trash"></i> Clear All</button>
            </div>
          </div>
          <div class="card-body">
            <div class="table-container">
              <table class="styled-table" id="itemsTable">
                <thead>
                  <tr>
                    <th class="col-code">Item Code</th>
                    <th class="col-name">Brand Name</th>
                    <th class="col-size">Size</th>
                    <th class="col-cases">Cases</th>
                    <th class="col-bottles">Bottles</th>
                    <th class="col-free-cases">Free Cases</th>
                    <th class="col-free-bottles">Free Bottles</th>
                    <th class="col-rate">Case Rate</th>
                    <th class="col-amount">Amount</th>
                    <th class="col-mrp">MRP</th>
                    <th class="col-batch">Batch No</th>
                    <th class="col-auto-batch">Auto Batch</th>
                    <th class="col-mfg">Mfg. Month</th>
                    <th class="col-bl">B.L.</th>
                    <th class="col-vv">V/v (%)</th>
                    <th class="col-totbott">Tot. Bott.</th>
                    <th class="col-action">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>
                </tbody>
                <tfoot>
                  <tr class="totals-row">
                    <td colspan="3" class="text-end fw-semibold">Total:</td>
                    <td id="totalCases" class="fw-semibold">0.00</td>
                    <td id="totalBottles" class="fw-semibold">0</td>
                    <td id="totalFreeCases" class="fw-semibold">0.00</td>
                    <td id="totalFreeBottles" class="fw-semibold">0</td>
                    <td></td>
                    <td id="totalAmount" class="fw-semibold">0.00</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td id="totalBL" class="fw-semibold">0.00</td>
                    <td></td>
                    <td id="totalTotBott" class="fw-semibold">0</td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        </div>

        <!-- CHARGES -->
        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-calculator me-2"></i>Charges & Taxes</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-3"><label class="form-label">Cash Discount</label><input type="number" step="0.01" class="form-control" name="cash_disc" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Trade Discount</label><input type="number" step="0.01" class="form-control" name="trade_disc" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Octroi</label><input type="number" step="0.01" class="form-control" name="octroi" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Freight Charges</label><input type="number" step="0.01" class="form-control" name="freight" value="0.00"></div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-3"><label class="form-label">Sales Tax (%)</label><input type="number" step="0.01" class="form-control" name="stax_per" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Sales Tax Amount</label><input type="number" step="0.01" class="form-control" name="stax_amt" value="0.00" readonly></div>
              <div class="col-md-3"><label class="form-label">TCS (%)</label><input type="number" step="0.01" class="form-control" name="tcs_per" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">TCS Amount</label><input type="number" step="0.01" class="form-control" name="tcs_amt" value="0.00" readonly></div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-3"><label class="form-label">Misc. Charges</label><input type="number" step="0.01" class="form-control" name="misc_charg" value="0.00"></div>
              <div class="col-md-3"><label class="form-label">Basic Amount</label><input type="number" step="0.01" class="form-control" name="basic_amt" value="0.00" readonly></div>
              <div class="col-md-3"><label class="form-label">Total Amount</label><input type="number" step="0.01" class="form-control" name="tamt" value="0.00" readonly></div>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2">
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
          <a class="btn btn-secondary" href="purchase_module.php?mode=<?=$mode?>"><i class="fa-solid fa-xmark"></i> Cancel</a>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- ITEM PICKER MODAL -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Select Item</h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <input class="form-control mb-2" id="itemSearch" placeholder="Search items...">
      <div class="table-container">
        <table class="styled-table">
          <thead><tr><th>Code</th><th>Item</th><th>Size</th><th>Price</th><th>Bottles/Case</th><th>Action</th></tr></thead>
          <tbody id="itemsModalTable">
          <?php foreach($items as $it): ?>
            <tr class="item-row-modal">
              <td><?=htmlspecialchars($it['CODE'])?></td>
              <td><?=htmlspecialchars($it['DETAILS'])?></td>
              <td><?=htmlspecialchars($it['DETAILS2'])?></td>
              <td><?=number_format((float)$it['PPRICE'],3)?></td>
              <td><?=htmlspecialchars($it['BOTTLE_PER_CASE'])?></td>
              <td><button type="button" class="btn btn-sm btn-primary select-item"
                  data-code="<?=htmlspecialchars($it['CODE'])?>"
                  data-name="<?=htmlspecialchars($it['DETAILS'])?>"
                  data-size="<?=htmlspecialchars($it['DETAILS2'])?>"
                  data-price="<?=htmlspecialchars($it['PPRICE'])?>"
                  data-bottles-per-case="<?=htmlspecialchars($it['BOTTLE_PER_CASE'])?>">Select</button></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div></div>
</div>

<!-- SCM Paste Modal -->
<div class="modal fade" id="scmPasteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Paste SCM Data</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Paste SCM table data here:</label>
          <textarea class="form-control" id="scmPasteArea" rows="10" placeholder="Paste the copied table from SCM system here..."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="processSCMData">Process Data</button>
      </div>
    </div>
  </div>
</div>

<!-- Missing Items Modal -->
<div class="modal fade" id="missingItemsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Items Requiring Attention</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info mb-3">
          <i class="fa-solid fa-circle-info me-2"></i>
          <strong>Found <span id="validItemsCount">0</span> valid items and <span id="missingItemsCount">0</span> items requiring attention</strong>
        </div>
        
        <!-- License Restricted Items -->
        <div class="card mb-3" id="licenseRestrictedSection" style="display: none;">
          <div class="card-header bg-warning text-dark">
            <i class="fa-solid fa-ban me-2"></i>
            <strong>License Restricted Items</strong>
            <span class="badge bg-danger ms-2" id="restrictedCount">0</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th width="120">SCM Code</th>
                    <th>Brand Name</th>
                    <th width="80">Size</th>
                    <th width="120">Class</th>
                    <th width="200">Reason</th>
                  </tr>
                </thead>
                <tbody id="licenseRestrictedList"></tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Missing Items -->
        <div class="card" id="missingItemsSection" style="display: none;">
          <div class="card-header bg-danger text-white">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            <strong>Items Not Found in Database</strong>
            <span class="badge bg-dark ms-2" id="missingCount">0</span>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                  <tr>
                    <th width="120">SCM Code</th>
                    <th>Brand Name</th>
                    <th width="80">Size</th>
                    <th width="200">Possible Solutions</th>
                  </tr>
                </thead>
                <tbody id="missingItemsList"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <i class="fa-solid fa-times me-2"></i>Cancel
        </button>
        <button type="button" class="btn btn-success" id="continueWithFoundItems">
          <i class="fa-solid fa-check me-2"></i>Continue with <span id="continueItemsCount">0</span> Valid Items
        </button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
  let itemCount = 0;
  const dbItems = <?=json_encode(array_map(function($item) {
    return [
        'CODE' => $item['CODE'],
        'DETAILS' => $item['DETAILS'],
        'DETAILS2' => $item['DETAILS2'],
        'PPRICE' => $item['PPRICE'],
        'ITEM_GROUP' => $item['ITEM_GROUP'],
        'LIQ_FLAG' => $item['LIQ_FLAG'],
        'CLASS' => $item['CLASS'],
        'CLASS_CODE_NEW' => $item['CLASS_CODE_NEW'] ?? $item['CLASS'],
        'CATEGORY_CODE' => $item['CATEGORY_CODE'] ?? '',
        'BOTTLE_PER_CASE' => $item['BOTTLE_PER_CASE'],
        'SCM_CODE' => $item['SCM_CODE']
    ];
}, $items), JSON_UNESCAPED_UNICODE)?>;
  const suppliers = <?=json_encode($suppliers, JSON_UNESCAPED_UNICODE)?>;
  const distinctSizes = <?=json_encode($distinctSizes, JSON_UNESCAPED_UNICODE)?>;
  const allowedCategories = <?=json_encode($allowed_categories ?? [], JSON_UNESCAPED_UNICODE)?>;
  const companyId = <?= $companyId ?>;

  // Debug logging for JavaScript
  console.log('dbItems loaded:', dbItems.length, 'items');
  console.log('allowedCategories:', allowedCategories);

  // ---------- Helpers ----------
  function ymdFromDmyText(str){
    const m = str.trim().match(/^(\d{1,2})-([A-Za-z]{3})-(\d{4})$/);
    if(!m) return '';
    const map = {Jan:'01',Feb:'02',Mar:'03',Apr:'04',May:'05',Jun:'06',Jul:'07',Aug:'08',Sep:'09',Oct:'10',Nov:'11',Dec:'12'};
    const mon = map[m[2].slice(0,3)];
    if(!mon) return '';
    return `${m[3]}-${mon}-${String(m[1]).padStart(2,'0')}`;
  }

  function cleanItemCode(code) {
     return (code || '').replace(/^SCM/i, '').trim();
  }

  function findBestSupplierMatch(parsedName) {
    if (!parsedName) return null;

    const parsedClean = parsedName.toLowerCase().replace(/[^a-z0-9\s]/g, '');
    let bestMatch = null;
    let bestScore = 0;

    suppliers.forEach(supplier => {
        const supplierName = (supplier.DETAILS || '').toLowerCase().replace(/[^a-z0-9\s]/g, '');
        const supplierCode = (supplier.CODE || '').toLowerCase();

        const parsedBase = parsedClean.replace(/\d+$/, '');
        const supplierBase = supplierName.replace(/\d+$/, '');
        let score = 0;

        if (supplierName === parsedClean) score = 100;
        else if (supplierBase === parsedBase && supplierBase.length > 0) score = 95;
        else if (supplierName.includes(parsedClean) || parsedClean.includes(supplierName)) score = 80;
        else if (supplierBase.includes(parsedBase) || parsedBase.includes(supplierBase)) score = 70;
        else if (parsedClean.includes(supplierCode) || supplierCode.includes(parsedClean)) score = 60;
        else if (supplierName.startsWith(parsedClean.substring(0, 5)) ||
                 parsedClean.startsWith(supplierName.substring(0, 5))) score = 50;

        if (score > bestScore) {
            bestScore = score;
            bestMatch = supplier;
        }
    });

    return bestMatch;
  }

  // Function to update MRP in database via AJAX
  function updateItemMRPInDatabase(itemCode, mrp) {
    return $.ajax({
      url: 'update_mrp_ajax.php',
      type: 'POST',
      dataType: 'json',
      data: {
        item_code: itemCode,
        mrp: mrp,
        company_id: companyId
      }
    });
  }

  function validateSCMItems(scmItems) {
    const validItems = [];
    const missingItems = [];
    
    scmItems.forEach((scmItem, index) => {
        // Extract clean code without SCM prefix
        const cleanCode = scmItem.scmCode ? scmItem.scmCode.replace(/^SCM/i, '').trim() : '';
        
        // Try multiple matching strategies
        let matchingItem = null;
        
        // Strategy 1: Direct code match (without SCM prefix)
        matchingItem = dbItems.find(dbItem => dbItem.CODE === cleanCode);
        
        // Strategy 2: Match with SCM_CODE field
        if (!matchingItem) {
            matchingItem = dbItems.find(dbItem => dbItem.SCM_CODE === scmItem.scmCode);
        }
        
        // Strategy 3: Case-insensitive match
        if (!matchingItem) {
            matchingItem = dbItems.find(dbItem => 
                dbItem.CODE.toLowerCase() === cleanCode.toLowerCase()
            );
        }
        
        // Strategy 4: Partial match (for items with slightly different codes)
        if (!matchingItem && cleanCode.length > 5) {
            matchingItem = dbItems.find(dbItem => 
                dbItem.CODE.includes(cleanCode) || cleanCode.includes(dbItem.CODE)
            );
        }
        
        // Strategy 5: Match by name and size if code matching fails
        if (!matchingItem && scmItem.brandName && scmItem.size) {
            matchingItem = dbItems.find(dbItem => 
                dbItem.DETAILS.toLowerCase().includes(scmItem.brandName.toLowerCase()) &&
                dbItem.DETAILS2.toLowerCase().includes(scmItem.size.toLowerCase())
            );
        }
        
        if (matchingItem) {
            // Check license restriction using CATEGORY_CODE
            const isAllowed = allowedCategories.some(cat => cat.CATEGORY_CODE === matchingItem.CATEGORY_CODE);
            
            if (isAllowed) {
                validItems.push({
                    scmData: scmItem,
                    dbItem: matchingItem
                });
            } else {
                missingItems.push({
                    code: scmItem.scmCode,
                    name: scmItem.brandName || matchingItem.DETAILS,
                    size: scmItem.size || matchingItem.DETAILS2,
                    class: matchingItem.CLASS_CODE_NEW,
                    reason: 'License restriction',
                    type: 'restricted'
                });
            }
        } else {
            missingItems.push({
                code: scmItem.scmCode,
                name: scmItem.brandName || '',
                size: scmItem.size || '',
                reason: 'Not found in database',
                type: 'missing'
            });
        }
    });
    
    return { validItems, missingItems };
  }

  function showMissingItemsModal(missingItems, validItems, parsedData) {
    const restrictedItems = missingItems.filter(item => item.type === 'restricted');
    const missingDbItems = missingItems.filter(item => item.type === 'missing');
    
    $('#validItemsCount').text(validItems.length);
    $('#missingItemsCount').text(missingItems.length);
    $('#continueItemsCount').text(validItems.length);
    
    if (restrictedItems.length > 0) {
        $('#licenseRestrictedSection').show();
        $('#restrictedCount').text(restrictedItems.length);
        const restrictedList = $('#licenseRestrictedList');
        restrictedList.empty();
        restrictedItems.forEach(item => {
            restrictedList.append(`
                <tr>
                    <td><strong>${item.code}</strong></td>
                    <td>${item.name}</td>
                    <td>${item.size}</td>
                    <td><span class="badge bg-secondary">${item.class}</span></td>
                    <td><span class="text-danger">Not allowed for your license type</span></td>
                </tr>
            `);
        });
    } else {
        $('#licenseRestrictedSection').hide();
    }
    
    if (missingDbItems.length > 0) {
        $('#missingItemsSection').show();
        $('#missingCount').text(missingDbItems.length);
        const missingList = $('#missingItemsList');
        missingList.empty();
        missingDbItems.forEach(item => {
            missingList.append(`
                <tr>
                    <td><strong>${item.code}</strong></td>
                    <td>${item.name}</td>
                    <td>${item.size}</td>
                    <td>
                        <small class="text-muted">
                            • Check item code matches your database
                        </small>
                    </td>
                </tr>
            `);
        });
    } else {
        $('#missingItemsSection').hide();
    }
    
    $('#missingItemsModal').data({
        validItems: validItems,
        parsedData: parsedData
    });
    
    $('#missingItemsModal').modal('show');
  }

  function processValidSCMItems(validItems, parsedData) {
    $('#clearItems').click();
    
    if (parsedData.supplier) {
        if (!$('#supplierCodeHidden').val()) {
            const supplierMatch = findBestSupplierMatch(parsedData.supplier);
            if (supplierMatch) {
                $('#supplierInput').val(supplierMatch.DETAILS);
                $('#supplierCodeHidden').val(supplierMatch.CODE);
            }
        }
    }
    
    if (parsedData.tpNo) $('#tpNo').val(parsedData.tpNo);
    if (parsedData.tpDate) $('#tpDate').val(parsedData.tpDate);
    
    validItems.forEach((validItem, index) => {
        addRow({
            dbItem: validItem.dbItem,
            ...validItem.scmData,
            cleanCode: validItem.scmData.scmCode ? validItem.scmData.scmCode.replace(/^SCM/i, '').trim() : ''
        });
    });
    
    if (validItems.length === 0) {
        alert('No valid items found in the SCM data that match your database and license restrictions.');
    } else {
        alert(`Successfully added ${validItems.length} items from SCM data. MRP prices have been updated in the database.`);
    }
  }

  function parseSCMData(data) {
    const lines = data.split('\n').map(line => line.trim()).filter(line => line);
    let supplier = '';
    let tpNo = '';
    let tpDate = '';
    let receivedDate = '';
    let autoTpNo = '';
    const items = [];
    
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        
        if (!line || line.includes('Total') || line.match(/^\d+\s+Total/)) continue;
        
        if (/Received\s*Date/i.test(line)) {
            const nextLine = (lines[i + 1] || '').trim();
            if (nextLine) {
                const ymdDate = ymdFromDmyText(nextLine);
                receivedDate = ymdDate || nextLine;
                if (ymdDate) $('input[name="date"]').val(ymdDate);
            }
        }
        
        if (/Auto\s*T\.\s*P\.\s*No:/i.test(line)) {
            const nextLine = (lines[i + 1] || '').trim();
            if (nextLine && !/T\.?P\.?Date/i.test(nextLine)) {
                autoTpNo = nextLine;
                $('#autoTpNo').val(nextLine);
            }
        }
        
        if (/T\.\s*P\.\s*No\(Manual\):/i.test(line)) {
            const nextLine = (lines[i + 1] || '').trim();
            if (nextLine && !/T\.?P\.?Date/i.test(nextLine)) {
                tpNo = nextLine;
                $('#tpNo').val(nextLine);
            }
        }
        
        if (/T\.?P\.?Date:/i.test(line)) {
            const nextLine = (lines[i + 1] || '').trim();
            const ymdDate = ymdFromDmyText(nextLine);
            if (ymdDate) {
                tpDate = ymdDate;
                $('#tpDate').val(ymdDate);
            }
        }
        
        if (/^Party\s*:/i.test(line)) {
            const nextLine = (lines[i + 1] || '').trim();
            if (nextLine) {
                supplier = nextLine;
                const supplierMatch = findBestSupplierMatch(nextLine);
                if (supplierMatch) {
                    $('#supplierInput').val(supplierMatch.DETAILS);
                    $('#supplierCodeHidden').val(supplierMatch.CODE);
                } else {
                    $('#supplierInput').val(nextLine);
                }
            }
        }
        
        if (line.includes('SCM Code:')) {
            try {
                const item = parseSCMLine(line);
                if (item) items.push(item);
            } catch (error) {
                console.error('Error parsing SCM line:', error);
            }
        }
    }
    
    return { supplier, tpNo, tpDate, receivedDate, autoTpNo, items };
  }

  function parseSCMLine(line) {
    const parts = line.split(/\s{2,}/);
    if (parts.length < 2) return parseSCMLineAlternative(line);
    
    const item = {};
    const scmCodePart = parts[0];
    const scmCodeMatch = scmCodePart.match(/SCM Code:\s*(\S+)/i);
    if (scmCodeMatch && scmCodeMatch[1]) {
        item.scmCode = scmCodeMatch[1];
        const remainingFirstPart = scmCodePart.replace(/SCM Code:\s*\S+/i, '').trim();
        if (remainingFirstPart) item.brandName = remainingFirstPart;
    }
    
    const dataParts = line.replace(/SCM Code:\s*\S+/i, '').trim().split(/\s+/);
    
    if (dataParts.length >= 11) {
        let index = 0;
        
        if (!item.brandName) {
            let brandNameParts = [];
            while (index < dataParts.length && !dataParts[index].match(/\d+ML/i) && !dataParts[index].match(/\d+L/i)) {
                brandNameParts.push(dataParts[index]);
                index++;
            }
            item.brandName = brandNameParts.join(' ');
        } else {
            while (index < dataParts.length && !dataParts[index].match(/\d+ML/i) && !dataParts[index].match(/\d+L/i)) {
                index++;
            }
        }
        
        if (index < dataParts.length) {
            item.size = dataParts[index];
            index++;
        }
        
        if (index < dataParts.length) {
            item.cases = parseFloat(dataParts[index]) || 0;
            index++;
        }
        
        if (index < dataParts.length) {
            item.bottles = parseInt(dataParts[index]) || 0;
            index++;
        }
        
        if (index < dataParts.length) {
            item.batchNo = dataParts[index] || '';
            index++;
        }
        
        if (index < dataParts.length) {
            item.autoBatch = dataParts[index] || '';
            index++;
        }
        
        if (index < dataParts.length) {
            item.mfgMonth = dataParts[index] || '';
            index++;
        }
        
        if (index < dataParts.length) {
            item.mrp = parseFloat(dataParts[index]) || 0;
            index++;
        }
        
        if (index < dataParts.length) {
            item.bl = parseFloat(dataParts[index]) || 0;
            index++;
        }
        
        if (index < dataParts.length) {
            item.vv = parseFloat(dataParts[index]) || 0;
            index++;
        }
        
        if (index < dataParts.length) {
            item.totBott = parseInt(dataParts[index]) || 0;
        }
        
        item.freeCases = item.freeCases || 0;
        item.freeBottles = item.freeBottles || 0;
        item.caseRate = item.caseRate || 0;
    } else {
        return parseSCMLineAlternative(line);
    }
    
    if (!item.scmCode || !item.size) {
        return parseSCMLineAlternative(line);
    }
    
    return item;
  }

  function parseSCMLineAlternative(line) {
    const item = {};
    const scmCodeMatch = line.match(/SCM Code:\s*(\S+)/i);
    if (scmCodeMatch) item.scmCode = scmCodeMatch[1];
    
    const remainingLine = line.replace(/SCM Code:\s*\S+/i, '').trim();
    const dataMatch = remainingLine.match(/(.+?)\s+(\d+(?:\.\d+)?)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+(?:\.\d+)?)\s+(\d+)/);
    
    if (dataMatch) {
        item.brandName = dataMatch[1].trim();
        item.cases = parseFloat(dataMatch[2]) || 0;
        item.bottles = parseInt(dataMatch[3]) || 0;
        item.batchNo = dataMatch[4];
        item.autoBatch = dataMatch[5];
        item.mfgMonth = dataMatch[6];
        item.mrp = parseFloat(dataMatch[7]) || 0;
        item.bl = parseFloat(dataMatch[8]) || 0;
        item.vv = parseFloat(dataMatch[9]) || 0;
        item.totBott = parseInt(dataMatch[10]) || 0;
        
        const sizeMatch = item.brandName.match(/(\d+\s*ML|\d+\s*L)$/i);
        if (sizeMatch) {
            item.size = sizeMatch[1];
            item.brandName = item.brandName.replace(sizeMatch[0], '').trim();
        }
    }
    
    item.freeCases = item.freeCases || 0;
    item.freeBottles = item.freeBottles || 0;
    item.caseRate = item.caseRate || 0;
    
    return item;
  }

  function calculateAmount(cases, individualBottles, caseRate, bottlesPerCase) {
    if (bottlesPerCase <= 0) bottlesPerCase = 1;
    if (caseRate < 0) caseRate = 0;
    cases = Math.max(0, cases || 0);
    individualBottles = Math.max(0, individualBottles || 0);
    
    const fullCaseAmount = cases * caseRate;
    const bottleRate = caseRate / bottlesPerCase;
    const individualBottleAmount = individualBottles * bottleRate;
    
    return fullCaseAmount + individualBottleAmount;
  }

  function calculateTradeDiscount() {
    let totalTradeDiscount = 0;
    
    $('.item-row').each(function() {
      const row = $(this);
      const freeCases = parseFloat(row.find('.free-cases').val()) || 0;
      const freeBottles = parseFloat(row.find('.free-bottles').val()) || 0;
      const caseRate = parseFloat(row.find('.case-rate').val()) || 0;
      const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
      
      const freeAmount = calculateAmount(freeCases, freeBottles, caseRate, bottlesPerCase);
      totalTradeDiscount += freeAmount;
    });
    
    return totalTradeDiscount;
  }

  function calculateColumnTotals() {
    let totalCases = 0;
    let totalBottles = 0;
    let totalFreeCases = 0;
    let totalFreeBottles = 0;
    let totalBL = 0;
    let totalTotBott = 0;
    
    $('.item-row').each(function() {
      const row = $(this);
      totalCases += parseFloat(row.find('.cases').val()) || 0;
      totalBottles += parseFloat(row.find('.bottles').val()) || 0;
      totalFreeCases += parseFloat(row.find('.free-cases').val()) || 0;
      totalFreeBottles += parseFloat(row.find('.free-bottles').val()) || 0;
      
      const blValue = parseFloat(row.find('input[name*="[bl]"]').val()) || 0;
      const totBottValue = parseFloat(row.find('input[name*="[tot_bott]"]').val()) || 0;
      
      totalBL += blValue;
      totalTotBott += totBottValue;
    });
    
    return {
      cases: totalCases,
      bottles: totalBottles,
      freeCases: totalFreeCases,
      freeBottles: totalFreeBottles,
      bl: totalBL,
      totBott: totalTotBott
    };
  }

  function updateColumnTotals() {
    const totals = calculateColumnTotals();
    
    $('#totalCases').text(totals.cases.toFixed(2));
    $('#totalBottles').text(totals.bottles.toFixed(0));
    $('#totalFreeCases').text(totals.freeCases.toFixed(2));
    $('#totalFreeBottles').text(totals.freeBottles.toFixed(0));
    $('#totalBL').text(totals.bl.toFixed(2));
    $('#totalTotBott').text(totals.totBott.toFixed(0));
  }

  function calculateBL(sizeText, totalBottles) {
    if (!sizeText || !totalBottles) return 0;
    
    const sizeMatch = sizeText.match(/(\d+)/);
    if (!sizeMatch) return 0;
    
    const sizeML = parseInt(sizeMatch[1]);
    return (sizeML * totalBottles) / 1000;
  }

  function calculateTotalBottles(cases, bottles, bottlesPerCase) {
    return (cases * bottlesPerCase) + bottles;
  }

  function updateRowCalculations(row) {
    const cases = parseFloat(row.find('.cases').val()) || 0;
    const bottles = parseFloat(row.find('.bottles').val()) || 0;
    const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
    const size = row.find('input[name*="[size]"]').val() || '';
    
    const totalBottles = calculateTotalBottles(cases, bottles, bottlesPerCase);
    const blValue = calculateBL(size, totalBottles);
    
    row.find('.tot-bott-value').text(totalBottles);
    row.find('.bl-value').text(blValue.toFixed(2));
    
    row.find('input[name*="[tot_bott]"]').val(totalBottles);
    row.find('input[name*="[bl]"]').val(blValue.toFixed(2));
  }

  function initializeSizeTable() {
    const $headers = $('#sizeHeaders');
    const $values = $('#sizeValues');

    $headers.empty();
    $values.empty();

    const sortedSizes = distinctSizes.sort((a, b) => b - a);

    sortedSizes.forEach(size => {
      let displaySize;
      if (size >= 1000) {
        const liters = size / 1000;
        displaySize = liters % 1 === 0 ? `${liters}L` : `${liters.toFixed(1)}L`;
      } else {
        displaySize = `${size}ML`;
      }

      $headers.append(`<th>${displaySize}</th>`);
      $values.append(`<td id="size-${size}" class="text-center fw-bold">0</td>`);
    });
  }

  function calculateBottlesBySize() {
    const sizeMap = {};
    
    distinctSizes.forEach(size => {
      sizeMap[size] = 0;
    });
    
    $('.item-row').each(function() {
      const row = $(this);
      const sizeText = row.find('input[name*="[size]"]').val() || '';
      const totBott = parseInt(row.find('input[name*="[tot_bott]"]').val()) || 0;
      
      if (sizeText && totBott > 0) {
        const sizeMatch = sizeText.match(/(\d+)/);
        if (sizeMatch) {
          const sizeValue = parseInt(sizeMatch[1]);
          
          let matchedSize = null;
          let smallestDiff = Infinity;
          
          distinctSizes.forEach(dbSize => {
            const diff = Math.abs(dbSize - sizeValue);
            if (diff < smallestDiff && diff <= 50) {
              smallestDiff = diff;
              matchedSize = dbSize;
            }
          });
          
          if (matchedSize !== null) {
            sizeMap[matchedSize] += totBott;
          } else if (distinctSizes.includes(sizeValue)) {
            sizeMap[sizeValue] += totBott;
          }
        }
      }
    });
    
    return sizeMap;
  }

  function updateBottlesBySizeDisplay() {
    const sizeMap = calculateBottlesBySize();
    
    distinctSizes.forEach(size => {
      $(`#size-${size}`).text(sizeMap[size] || '0');
    });
  }

function addRow(item){
    const dbItem = item.dbItem || null;
    
    // Check license restriction using CATEGORY_CODE
    if (dbItem && allowedCategories.length > 0) {
        const isAllowed = allowedCategories.some(cat => cat.CATEGORY_CODE === dbItem.CATEGORY_CODE);
        if (!isAllowed) {
            console.log('Item not allowed by license:', dbItem.CODE, 'Category:', dbItem.CATEGORY_CODE);
            return;
        }
    } else if (dbItem && !dbItem.CATEGORY_CODE) {
        // If no CATEGORY_CODE, allow the item (backward compatibility)
        console.log('Item has no CATEGORY_CODE, allowing:', dbItem.CODE);
    }
    
    if($('#noItemsRow').length) {
        $('#noItemsRow').remove();
    }
    
    const bottlesPerCase = dbItem ? parseInt(dbItem.BOTTLE_PER_CASE) || 12 : 12;
    const caseRate = item.caseRate || (dbItem ? parseFloat(dbItem.PPRICE) : 0) || 0;
    const itemCode = dbItem ? dbItem.CODE : (item.cleanCode || item.code || '');
    const itemName = dbItem ? dbItem.DETAILS : (item.name || '');
    const itemSize = dbItem ? dbItem.DETAILS2 : (item.size || '');
    
    const cases = item.cases || 0;
    const bottles = item.bottles || 0;
    const freeCases = item.freeCases || 0;
    const freeBottles = item.freeBottles || 0;
    const mrp = item.mrp || 0;
    
    const mfgMonth = item.mfgMonth || '';
    const vv = item.vv || 0;
    
    const totalBottles = item.totBott || calculateTotalBottles(cases, bottles, bottlesPerCase);
    const blValue = item.bl || calculateBL(itemSize, totalBottles);
    
    const amount = calculateAmount(cases, bottles, caseRate, bottlesPerCase);
    
    const currentIndex = itemCount;
    
    const r = `
      <tr class="item-row" data-bottles-per-case="${bottlesPerCase}">
        <td>
          <input type="hidden" name="items[${currentIndex}][code]" value="${itemCode}">
          <input type="hidden" name="items[${currentIndex}][name]" value="${itemName}">
          <input type="hidden" name="items[${currentIndex}][size]" value="${itemSize}">
          <input type="hidden" name="items[${currentIndex}][bottles_per_case]" value="${bottlesPerCase}">
          <input type="hidden" name="items[${currentIndex}][batch_no]" value="${item.batchNo || ''}">
          <input type="hidden" name="items[${currentIndex}][auto_batch]" value="${item.autoBatch || ''}">
          <input type="hidden" name="items[${currentIndex}][mfg_month]" value="${mfgMonth}">
          <input type="hidden" name="items[${currentIndex}][bl]" value="${blValue}">
          <input type="hidden" name="items[${currentIndex}][vv]" value="${vv}">
          <input type="hidden" name="items[${currentIndex}][tot_bott]" value="${totalBottles}">
          <input type="hidden" name="items[${currentIndex}][free_cases]" value="${freeCases}">
          <input type="hidden" name="items[${currentIndex}][free_bottles]" value="${freeBottles}">
          ${itemCode}
        </td>
        <td>${itemName}</td>
        <td>${itemSize}</td>
        <td><input type="number" class="form-control form-control-sm cases" name="items[${currentIndex}][cases]" value="${cases}" min="0" step="0.01"></td>
        <td><input type="number" class="form-control form-control-sm bottles" name="items[${currentIndex}][bottles]" value="${bottles}" min="0" step="1"></td>
        <td><input type="number" class="form-control form-control-sm free-cases" name="items[${currentIndex}][free_cases]" value="${freeCases}" min="0" step="0.01"></td>
        <td><input type="number" class="form-control form-control-sm free-bottles" name="items[${currentIndex}][free_bottles]" value="${freeBottles}" min="0" step="1"></td>
        <td><input type="number" class="form-control form-control-sm case-rate" name="items[${currentIndex}][case_rate]" value="${caseRate.toFixed(3)}" step="0.001"></td>
        <td class="amount">${amount.toFixed(2)}</td>
        <td><input type="number" class="form-control form-control-sm mrp" name="items[${currentIndex}][mrp]" value="${mrp}" step="0.01"></td>
        <td><input type="text" class="form-control form-control-sm batch-no" name="items[${currentIndex}][batch_no]" value="${item.batchNo || ''}"></td>
        <td><input type="text" class="form-control form-control-sm auto-batch" name="items[${currentIndex}][auto_batch]" value="${item.autoBatch || ''}"></td>
        <td><input type="text" class="form-control form-control-sm mfg-month" name="items[${currentIndex}][mfg_month]" value="${mfgMonth}"></td>
        <td class="bl-value">${blValue.toFixed(2)}</td>
        <td><input type="number" class="form-control form-control-sm vv" name="items[${currentIndex}][vv]" value="${vv}" step="0.01"></td>
        <td class="tot-bott-value">${totalBottles}</td>
        <td><button class="btn btn-sm btn-danger remove-item" type="button"><i class="fa-solid fa-trash"></i></button></td>
      </tr>`;
    $('#itemsTable tbody').append(r);
    itemCount++;
    updateTotals();
}

  function updateTotals(){
    let t=0;
    $('.item-row .amount').each(function(){ t += parseFloat($(this).text())||0; });
    $('#totalAmount').text(t.toFixed(2));
    $('input[name="basic_amt"]').val(t.toFixed(2));
    
    const tradeDiscount = calculateTradeDiscount();
    $('input[name="trade_disc"]').val(tradeDiscount.toFixed(2));
    
    updateColumnTotals();
    updateBottlesBySizeDisplay();
    calcTaxes();
  }

  function calcTaxes(){
    const basic = parseFloat($('input[name="basic_amt"]').val())||0;
    const staxp = parseFloat($('input[name="stax_per"]').val())||0;
    const tcsp  = parseFloat($('input[name="tcs_per"]').val())||0;
    const cash  = parseFloat($('input[name="cash_disc"]').val())||0;
    const trade = parseFloat($('input[name="trade_disc"]').val())||0;
    const oct   = parseFloat($('input[name="octroi"]').val())||0;
    const fr    = parseFloat($('input[name="freight"]').val())||0;
    const misc  = parseFloat($('input[name="misc_charg"]').val())||0;
    const stax  = basic * staxp/100, tcs = basic * tcsp/100;
    
    $('input[name="stax_amt"]').val(stax.toFixed(2));
    $('input[name="tcs_amt"]').val(tcs.toFixed(2));
    const grand = basic + stax + tcs + oct + fr + misc - cash - trade;
    $('input[name="tamt"]').val(grand.toFixed(2));
  }

  // ------- Supplier UI -------
  $('#supplierSelect').on('change', function(){
    const name = $(this).val();
    const code = $(this).find(':selected').data('code') || '';
    if(name){ 
        $('#supplierInput').val(name); 
        $('#supplierCodeHidden').val(code); 
    }
  });

  $('#supplierInput').on('input', function(){
    const q = $(this).val().toLowerCase();
    if(q.length<2){ 
        $('#supplierSuggestions').hide().empty(); 
        return; 
    }
    
    const list = [];
    <?php foreach($suppliers as $s): ?>
      (function(){
        const nm = '<?=addslashes($s['DETAILS'])?>'.toLowerCase();
        const cd = '<?=addslashes($s['CODE'])?>'.toLowerCase();
        if(nm.includes(q) || cd.includes(q)){
          list.push({name:'<?=addslashes($s['DETAILS'])?>', code:'<?=addslashes($s['CODE'])?>'});
        }
      })();
    <?php endforeach; ?>
    const html = list.map(s=>`<div class="supplier-suggestion" data-code="${s.code}" data-name="${s.name}">${s.name} (${s.code})</div>`).join('');
    $('#supplierSuggestions').html(html).show();
  });

  $(document).on('click','.supplier-suggestion', function(){
    const name = $(this).data('name');
    const code = $(this).data('code');
    $('#supplierInput').val(name);
    $('#supplierCodeHidden').val(code);
    $('#supplierSuggestions').hide();
  });

  $(document).on('click', function(e){
    if(!$(e.target).closest('.supplier-container').length) {
        $('#supplierSuggestions').hide();
    }
  });

  // ------- Add/Clear Manually -------
  $('#addItem').on('click', function(){
    $('#itemModal').modal('show');
  });

  $('#itemSearch').on('input', function(){
    const v = this.value.toLowerCase();
    $('.item-row-modal').each(function(){
      $(this).toggle($(this).text().toLowerCase().includes(v));
    });
  });

  $(document).on('click','.select-item', function(){
    const data = $(this).data();
    addRow({
      code: data.code,
      name: data.name,
      size: data.size,
      cases: 0, bottles: 0,
      freeCases: 0, freeBottles: 0,
      caseRate: parseFloat(data.price)||0,
      mrp: 0,
      vv: 0
    });
    $('#itemModal').modal('hide');
  });

  $(document).on('input','.cases,.bottles,.case-rate,.free-cases,.free-bottles', function(){
    const row = $(this).closest('tr');
    const cases = parseFloat(row.find('.cases').val())||0;
    const bottles = parseFloat(row.find('.bottles').val())||0;
    const rate = parseFloat(row.find('.case-rate').val())||0;
    const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
    
    const amount = calculateAmount(cases, bottles, rate, bottlesPerCase);
    row.find('.amount').text(amount.toFixed(2));
    
    updateRowCalculations(row);
    updateTotals();
  });

  $(document).on('click','.remove-item', function(){
    $(this).closest('tr').remove();
    if($('.item-row').length===0){
      $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>');
      $('#totalAmount').text('0.00'); 
      $('input[name="basic_amt"]').val('0.00'); 
      $('input[name="tamt"]').val('0.00');
      $('input[name="trade_disc"]').val('0.00');
      
      $('#totalCases, #totalBottles, #totalFreeCases, #totalFreeBottles, #totalBL, #totalTotBott').text('0');
      updateBottlesBySizeDisplay();
    } else {
      updateTotals();
    }
  });

  $('input[name="stax_per"],input[name="tcs_per"],input[name="cash_disc"],input[name="trade_disc"],input[name="octroi"],input[name="freight"],input[name="misc_charg"]').on('input', function(){
    calcTaxes();
  });

  // ------- Paste-from-SCM -------
  $('#pasteFromSCM').on('click', function(){ 
    $('#scmPasteModal').modal('show'); 
    $('#scmPasteArea').val('').focus(); 
  });

  $('#processSCMData').on('click', function(){
    const scmData = $('#scmPasteArea').val().trim();
    
    if (!scmData) {
        alert('Please paste SCM data first.');
        return;
    }
    
    try {
        const parsedData = parseSCMData(scmData);
        const validationResult = validateSCMItems(parsedData.items);
        
        if (validationResult.missingItems.length > 0) {
            showMissingItemsModal(validationResult.missingItems, validationResult.validItems, parsedData);
        } else {
            processValidSCMItems(validationResult.validItems, parsedData);
            $('#scmPasteModal').modal('hide');
        }
    } catch (error) {
        console.error('Error parsing SCM data:', error);
        alert('Error parsing SCM data: ' + error.message);
    }
  });

  $('#continueWithFoundItems').click(function() {
    const modal = $('#missingItemsModal');
    const validItems = modal.data('validItems');
    const parsedData = modal.data('parsedData');
    
    processValidSCMItems(validItems, parsedData);
    modal.modal('hide');
    $('#scmPasteModal').modal('hide');
  });

  // Function to check TP number uniqueness via AJAX
  function checkTPNumberUniqueness(tpNo) {
      if (!tpNo || tpNo.trim() === '') return true;
      
      return $.ajax({
          url: 'check_tp_unique.php',
          type: 'POST',
          data: {
              tp_no: tpNo,
              company_id: companyId,
              voc_no: $('input[name="voc_no"]').val() // For edit mode
          },
          async: false
      }).responseJSON?.unique ?? true;
  }

  // Form submission
  $('#purchaseForm').on('submit', function(e) {
    if ($('.item-row').length === 0) {
        alert('Please add at least one item before saving.');
        e.preventDefault();
        return;
    }
    
    const tpNo = $('#tpNo').val().trim();
    if (tpNo) {
        const isUnique = checkTPNumberUniqueness(tpNo);
        if (!isUnique) {
            alert('TP Number already exists. Please enter a unique TP number.');
            e.preventDefault();
            return;
        }
    }
  });

  // Initialize
  initializeSizeTable();
  if($('.item-row').length===0){
    $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>');
  }else{
    itemCount = $('.item-row').length;
    updateTotals();
  }
});
</script>
</body>
</html>
<?php
$conn->close();
debugLog("Database connection closed");
debugLog("=== PURCHASE SESSION ENDED ===");
?>