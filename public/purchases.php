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

include_once "../config/db.php";
include_once "stock_functions.php";
debugLog("Database connection included");

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

// ---- Next Voucher No. (for current company) ----
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

// Function to clean item code by removing SCM prefix
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
    
    return $exists;
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
        
        // Now update all subsequent days in the archived month (cascading effect)
        updateSubsequentDaysInTable($conn, $archive_table, $monthYear, $itemCode, $dayOfMonth);
    } else {
        // For new record, opening is 0, so closing = purchase (no sales initially)
        $insert_query = "INSERT INTO $archive_table 
                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                        VALUES (?, ?, 'F', 0, ?, 0, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
        $result = $insert_stmt->execute();
        $insert_stmt->close();
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
        
        // Now update all subsequent days' opening and closing values with cascading effect
        updateSubsequentDaysInTable($conn, $dailyStockTable, $monthYear, $itemCode, $dayOfMonth);
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
    
    // Update opening for next day (carry forward from previous day's closing)
    for ($day = $purchaseDay + 1; $day <= 31; $day++) {
        $prevDay = $day - 1;
        $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        // Update opening to previous day's closing, and recalculate closing
        $updateQuery = "UPDATE $table 
                       SET $currentDayOpening = $prevDayClosing,
                           $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        
        debugLog("Cascading update for day $day", [
            'query' => $updateQuery,
            'prevDayClosing' => $prevDayClosing
        ]);
        
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("ss", $monthYear, $itemCode);
        $stmt->execute();
        $stmt->close();
    }
    
    debugLog("Cascading updates completed for all days after purchase day");
}

// Function to continue cascading from archived month to current month
function continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate) {
    debugLog("Continuing cascading to current month", [
        'comp_id' => $comp_id,
        'itemCode' => $itemCode,
        'purchaseDate' => $purchaseDate
    ]);
    
    $purchaseDay = date('j', strtotime($purchaseDate));
    $purchaseMonth = date('n', strtotime($purchaseDate));
    $purchaseYear = date('Y', strtotime($purchaseDate));
    $currentDay = date('j');
    $currentMonth = date('n');
    $currentYear = date('Y');
    
    // If purchase is in current month, cascading has already been handled
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
        'startYear' => $startYear
    ]);
    
    // Loop through months from purchase month+1 to current month
    while (($startYear < $currentYear) || ($startYear == $currentYear && $startMonth <= $currentMonth)) {
        $month_2digit = str_pad($startMonth, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($startYear, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        
        // Check if this month's table exists (archived or current)
        $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name = '$archive_table'";
        $check_result = $conn->query($check_table_query);
        $table_exists = $check_result->fetch_assoc()['count'] > 0;
        
        if ($table_exists) {
            debugLog("Found table for cascading", [
                'table' => $archive_table,
                'month' => $startMonth,
                'year' => $startYear
            ]);
            
            $monthYear = date('Y-m', strtotime("$startYear-$startMonth-01"));
            
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
                for ($day = 2; $day <= 31; $day++) {
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
    
    // If we've reached current month, ensure current month table is also updated
    if ($currentMonth != $purchaseMonth || $currentYear != $purchaseYear) {
        $dailyStockTable = "tbldailystock_" . $comp_id;
        $currentMonthYear = date('Y-m');
        
        // Check if record exists in current month table
        $checkCurrentQuery = "SELECT COUNT(*) as count FROM $dailyStockTable 
                             WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $checkCurrentStmt = $conn->prepare($checkCurrentQuery);
        $checkCurrentStmt->bind_param("ss", $currentMonthYear, $itemCode);
        $checkCurrentStmt->execute();
        $currentResult = $checkCurrentStmt->get_result();
        $currentExists = $currentResult->fetch_assoc()['count'] > 0;
        $checkCurrentStmt->close();
        
        if ($currentExists) {
            // Get previous month's last day closing for opening value
            $prevMonth = $currentMonth - 1;
            $prevYear = $currentYear;
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
                
                // Update current month's day 1 opening
                $updateCurrentOpeningQuery = "UPDATE $dailyStockTable 
                                            SET DAY_01_OPEN = ?,
                                                DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $currentOpeningStmt = $conn->prepare($updateCurrentOpeningQuery);
                $currentOpeningStmt->bind_param("iss", $openingValue, $currentMonthYear, $itemCode);
                $currentOpeningStmt->execute();
                $currentOpeningStmt->close();
            }
            
            // Cascade through current month up to today
            for ($day = 2; $day <= $currentDay; $day++) {
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
                $dayStmt->bind_param("ss", $currentMonthYear, $itemCode);
                $dayStmt->execute();
                $dayStmt->close();
            }
        }
    }
    
    debugLog("Cascading completed up to current date");
}

// Function to update item stock
function updateItemStock($conn, $itemCode, $totalBottles, $companyId) {
    $stockColumn = "CURRENT_STOCK" . $companyId;
    
    // Add to existing stock
    $updateItemStockQuery = "UPDATE tblitem_stock 
                            SET $stockColumn = $stockColumn + ? 
                            WHERE ITEM_CODE = ?";
    
    $itemStmt = $conn->prepare($updateItemStockQuery);
    $itemStmt->bind_param("is", $totalBottles, $itemCode);
    $result = $itemStmt->execute();
    $itemStmt->close();
    
    return $result;
}

// Function to update MRP in tblitemmaster
function updateItemMRP($conn, $itemCode, $mrp) {
    // Clean the item code by removing SCM prefix
    $cleanCode = cleanItemCode($itemCode);
    
    debugLog("Updating MRP for item", [
        'item_code' => $cleanCode,
        'mrp' => $mrp
    ]);
    
    // Update MPRICE in tblitemmaster
    $updateQuery = "UPDATE tblitemmaster SET MPRICE = ? WHERE CODE = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("ss", $mrp, $cleanCode);
    
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

// Function to update stock after purchase
function updateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn) {
    // Get day of month from purchase date
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    debugLog("Updating stock for item", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'day_of_month' => $dayOfMonth,
        'month' => $month,
        'year' => $year
    ]);
    
    // Check if this month is archived
    $isArchived = isMonthArchived($conn, $companyId, $month, $year);
    
    if ($isArchived) {
        debugLog("Month is archived, updating archive table with cascading");
        // Update archived month data with cascading
        updateArchivedMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
        
        // Continue cascading to current month
        continueCascadingToCurrentMonth($conn, $companyId, $itemCode, $purchaseDate);
    } else {
        debugLog("Month is current, updating current table with cascading");
        // Update current month data with cascading
        updateCurrentMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    }
    
    // Update tblitem_stock
    updateItemStock($conn, $itemCode, $totalBottles, $companyId);
}

// ---- Items (for case rate lookup & modal) - FILTERED BY LICENSE TYPE ONLY ----
$items = [];

if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $itemsQuery = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.ITEM_GROUP, im.LIQ_FLAG, im.CLASS,
                          COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE,
                          CONCAT('SCM', im.CODE) AS SCM_CODE
                     FROM tblitemmaster im
                     LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
                    WHERE im.CLASS IN ($class_placeholders)
                 ORDER BY im.DETAILS";
    
    $params = $allowed_classes;
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
        'license_filter_applied' => true
    ]);
} else {
    // If no classes allowed, show empty result
    $items = [];
    debugLog("No items fetched - no allowed classes for license type");
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
    
    debugLog("Form data extracted", [
        'date' => $date,
        'voc_no' => $voc_no,
        'auto_tp_no' => $auto_tp_no,
        'tp_no' => $tp_no,
        'tp_date' => $tp_date,
        'inv_no' => $inv_no,
        'inv_date' => $inv_date,
        'supplier_code' => $supplier_code,
        'supplier_name' => $supplier_name,
        'basic_amt' => $basic_amt,
        'total_amt' => $tamt
    ]);
    
    // Insert purchase header
    $insertQuery = "INSERT INTO tblpurchases (
        DATE, SUBCODE, AUTO_TPNO, VOC_NO, INV_NO, INV_DATE, TAMT, 
        TPNO, TP_DATE, SCHDIS, CASHDIS, OCTROI, FREIGHT, STAX_PER, STAX_AMT, 
        TCS_PER, TCS_AMT, MISC_CHARG, PUR_FLAG, CompID
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    debugLog("Purchase header insert query", [
        'query' => $insertQuery
    ]);

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
        debugLog("Error preparing purchase header statement", [
            'error' => $conn->error,
            'query' => $insertQuery
        ]);
    }
    
    if ($insertStmt->execute()) {
        $purchase_id = $conn->insert_id;
        debugLog("Purchase header inserted successfully", [
            'purchase_id' => $purchase_id,
            'affected_rows' => $conn->affected_rows
        ]);
        
        // Insert purchase items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $detailQuery = "INSERT INTO tblpurchasedetails (
                PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, FreeCases, FreeBottles, 
                CaseRate, MRP, Amount, BottlesPerCase, BatchNo, AutoBatch, MfgMonth, BL, VV, TotBott
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $detailStmt = $conn->prepare($detailQuery);
            $itemCount = 0;
            
            debugLog("Starting to process purchase items", [
                'item_count' => count($_POST['items'])
            ]);
            
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
                
                debugLog("Processing item $index", [
                    'item_code' => $item_code,
                    'mrp' => $mrp,
                    'tot_bott' => $tot_bott
                ]);
                
                $detailStmt->bind_param(
                    "isssdddddddisssddi",
                    $purchase_id, 
                    $item_code, 
                    $item_name, 
                    $item_size,
                    $cases, 
                    $bottles, 
                    $free_cases, 
                    $free_bottles, 
                    $case_rate, 
                    $mrp, 
                    $amount, 
                    $bottles_per_case,
                    $batch_no, 
                    $auto_batch, 
                    $mfg_month, 
                    $bl, 
                    $vv, 
                    $tot_bott
                );
                
                if ($detailStmt->execute()) {
                    $itemCount++;
                    
                    // Update MRP in tblitemmaster
                    updateItemMRP($conn, $item_code, $mrp);
                    
                    // Update stock using the cascading logic
                    updateStock($item_code, $tot_bott, $date, $companyId, $conn);
                } else {
                    debugLog("Error inserting purchase detail for item $index", [
                        'error' => $detailStmt->error,
                        'item_code' => $item_code
                    ]);
                }
            }
            $detailStmt->close();
            
            debugLog("Purchase items processing completed", [
                'successful_items' => $itemCount
            ]);
        } else {
            debugLog("No items found in POST data");
        }
        
        debugLog("=== FORM SUBMISSION COMPLETED SUCCESSFULLY ===");
        header("Location: purchase_module.php?mode=".$mode."&success=1");
        exit;
    } else {
        $errorMessage = "Error saving purchase: " . $insertStmt->error;
        debugLog("Error saving purchase header", [
            'error' => $insertStmt->error
        ]);
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
                <input type="date" class="form-control" name="date" value="<?=date('Y-m-d')?>" required>
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
  const dbItems = <?=json_encode($items, JSON_UNESCAPED_UNICODE)?>;
  const suppliers = <?=json_encode($suppliers, JSON_UNESCAPED_UNICODE)?>;
  const distinctSizes = <?=json_encode($distinctSizes, JSON_UNESCAPED_UNICODE)?>;
  const allowedClasses = <?= json_encode($allowed_classes) ?>;
  const companyId = <?= $companyId ?>;

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

  function validateSCMItems(scmItems) {
    const validItems = [];
    const missingItems = [];
    
    scmItems.forEach((scmItem, index) => {
        const cleanCode = scmItem.scmCode ? scmItem.scmCode.replace(/^SCM/i, '').trim() : '';
        let matchingItem = null;
        
        matchingItem = dbItems.find(dbItem => dbItem.CODE === cleanCode);
        if (!matchingItem) matchingItem = dbItems.find(dbItem => dbItem.SCM_CODE === scmItem.scmCode);
        if (!matchingItem) matchingItem = dbItems.find(dbItem => dbItem.CODE.toLowerCase() === cleanCode.toLowerCase());
        if (!matchingItem) matchingItem = dbItems.find(dbItem => dbItem.CODE.includes(cleanCode) || cleanCode.includes(dbItem.CODE));
        
        if (matchingItem) {
            if (allowedClasses.includes(matchingItem.CLASS)) {
                validItems.push({
                    scmData: scmItem,
                    dbItem: matchingItem
                });
            } else {
                missingItems.push({
                    code: scmItem.scmCode,
                    name: scmItem.brandName,
                    size: scmItem.size,
                    class: matchingItem.CLASS,
                    reason: 'License restriction',
                    type: 'restricted'
                });
            }
        } else {
            missingItems.push({
                code: scmItem.scmCode,
                name: scmItem.brandName,
                size: scmItem.size,
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
        alert(`Successfully added ${validItems.length} items from SCM data.`);
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
    
    if (dbItem && allowedClasses.length > 0 && !allowedClasses.includes(dbItem.CLASS)) {
        return;
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

  // Form submission
  $('#purchaseForm').on('submit', function(e) {
    if ($('.item-row').length === 0) {
        alert('Please add at least one item before saving.');
        e.preventDefault();
        return;
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