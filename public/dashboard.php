<?php
set_time_limit(300);
ini_set('max_execution_time', 300);

session_start();

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";
require_once 'license_functions.php';

// =============================================================================
// AUTOMATIC FINANCIAL YEAR MANAGEMENT
// =============================================================================

function checkAndCreateFinancialYear($conn) {
    $today = date('Y-m-d');
    $currentMonth = date('m');
    $currentYear = date('Y');
    
    // Determine current financial year based on date
    if ($currentMonth >= 4) {
        // After April 1, financial year is current_year to next_year
        $fyStartYear = $currentYear;
        $fyEndYear = $currentYear + 1;
    } else {
        // Before April 1, financial year is previous_year to current_year
        $fyStartYear = $currentYear - 1;
        $fyEndYear = $currentYear;
    }
    
    $fyStartDate = $fyStartYear . '-04-01 00:00:00';
    $fyEndDate = $fyEndYear . '-03-31 23:59:59';
    $fyName = $fyStartYear . '-' . substr($fyEndYear, -2);
    
    // Check if this financial year exists in database
    $checkQuery = "SELECT * FROM tblfinyear WHERE START_DATE = ? AND END_DATE = ?";
    $checkStmt = $conn->prepare($checkQuery);
    if (!$checkStmt) {
        error_log("Failed to prepare check statement: " . $conn->error);
        return getLatestFinancialYear($conn);
    }
    
    $checkStmt->bind_param("ss", $fyStartDate, $fyEndDate);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        // Financial year exists, get its data
        $fyData = $checkResult->fetch_assoc();
        
        // Add FIN_YEAR_NAME to the data if it doesn't exist
        if (!isset($fyData['FIN_YEAR_NAME'])) {
            // Calculate financial year name from dates
            $startYear = date('Y', strtotime($fyData['START_DATE']));
            $endYear = date('y', strtotime($fyData['END_DATE']));
            $fyData['FIN_YEAR_NAME'] = $startYear . '-' . $endYear;
        }
        
        // Make sure it's active
        if ($fyData['ACTIVE'] != 1) {
            // Deactivate all other financial years
            $conn->query("UPDATE tblfinyear SET ACTIVE = 0");
            
            // Activate this one
            $activateQuery = "UPDATE tblfinyear SET ACTIVE = 1 WHERE ID = ?";
            $activateStmt = $conn->prepare($activateQuery);
            if ($activateStmt) {
                $activateStmt->bind_param("i", $fyData['ID']);
                $activateStmt->execute();
                $activateStmt->close();
                $fyData['ACTIVE'] = 1;
                error_log("Financial year activated: {$fyName}");
            }
        }
        
        $checkStmt->close();
        return $fyData;
    } else {
        // Financial year doesn't exist, check if it should be created
        $checkStmt->close();
        
        // Only create if this is the logical next year
        $latestFY = getLatestFinancialYear($conn);
        
        if ($latestFY) {
            $latestEndYear = date('Y', strtotime($latestFY['END_DATE']));
            
            // If today is after the latest financial year's end date, create new
            if (strtotime($today) > strtotime($latestFY['END_DATE'])) {
                return createNewFinancialYear($conn, $latestFY);
            } else {
                // Add FIN_YEAR_NAME to the latest FY if it doesn't exist
                if (!isset($latestFY['FIN_YEAR_NAME'])) {
                    $startYear = date('Y', strtotime($latestFY['START_DATE']));
                    $endYear = date('y', strtotime($latestFY['END_DATE']));
                    $latestFY['FIN_YEAR_NAME'] = $startYear . '-' . $endYear;
                }
                return $latestFY;
            }
        } else {
            // No financial years exist, create current one
            return createNewFinancialYear($conn, null, $fyStartDate, $fyEndDate, $fyName);
        }
    }
}

function getLatestFinancialYear($conn) {
    $query = "SELECT * FROM tblfinyear ORDER BY END_DATE DESC LIMIT 1";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        $fyData = $result->fetch_assoc();
        
        // Add FIN_YEAR_NAME if it doesn't exist
        if (!isset($fyData['FIN_YEAR_NAME'])) {
            $startYear = date('Y', strtotime($fyData['START_DATE']));
            $endYear = date('y', strtotime($fyData['END_DATE']));
            $fyData['FIN_YEAR_NAME'] = $startYear . '-' . $endYear;
        }
        
        return $fyData;
    }
    
    return null;
}

function createNewFinancialYear($conn, $previousFY = null, $startDate = null, $endDate = null, $name = null) {
    try {
        if ($previousFY) {
            // Calculate next financial year based on previous
            $prevEndDate = new DateTime($previousFY['END_DATE']);
            $newStartDate = clone $prevEndDate;
            $newStartDate->modify('+1 day');
            
            $newEndDate = clone $newStartDate;
            $newEndDate->modify('+1 year');
            $newEndDate->modify('-1 day');
            
            $startYear = $newStartDate->format('Y');
            $endYear = $newEndDate->format('y');
            $fyName = $startYear . '-' . $endYear;
            
            $startDateStr = $newStartDate->format('Y-m-d H:i:s');
            $endDateStr = $newEndDate->format('Y-m-d 23:59:59');
        } else {
            // Use provided dates or calculate from current date
            $startDateStr = $startDate;
            $endDateStr = $endDate;
            $fyName = $name;
        }
        
        // Deactivate all existing financial years
        $conn->query("UPDATE tblfinyear SET ACTIVE = 0");
        
        // Check if FIN_YEAR_NAME column exists
        $columnCheck = $conn->query("SHOW COLUMNS FROM tblfinyear LIKE 'FIN_YEAR_NAME'");
        $hasFinYearColumn = $columnCheck && $columnCheck->num_rows > 0;
        
        if ($hasFinYearColumn) {
            // Insert with FIN_YEAR_NAME
            $insertQuery = "INSERT INTO tblfinyear (FIN_YEAR_NAME, START_DATE, END_DATE, ACTIVE) 
                            VALUES (?, ?, ?, 1)";
            $insertStmt = $conn->prepare($insertQuery);
            if (!$insertStmt) {
                throw new Exception("Failed to prepare insert: " . $conn->error);
            }
            $insertStmt->bind_param("sss", $fyName, $startDateStr, $endDateStr);
        } else {
            // Insert without FIN_YEAR_NAME
            $insertQuery = "INSERT INTO tblfinyear (START_DATE, END_DATE, ACTIVE) 
                            VALUES (?, ?, 1)";
            $insertStmt = $conn->prepare($insertQuery);
            if (!$insertStmt) {
                throw new Exception("Failed to prepare insert: " . $conn->error);
            }
            $insertStmt->bind_param("ss", $startDateStr, $endDateStr);
        }
        
        $insertStmt->execute();
        
        if ($insertStmt->affected_rows > 0) {
            $newFYId = $conn->insert_id;
            $insertStmt->close();
            
            // Get the newly created financial year
            $selectQuery = "SELECT * FROM tblfinyear WHERE ID = ?";
            $selectStmt = $conn->prepare($selectQuery);
            $selectStmt->bind_param("i", $newFYId);
            $selectStmt->execute();
            $result = $selectStmt->get_result();
            $newFY = $result->fetch_assoc();
            $selectStmt->close();
            
            // Add FIN_YEAR_NAME to the returned data
            if (!isset($newFY['FIN_YEAR_NAME'])) {
                $startYear = date('Y', strtotime($newFY['START_DATE']));
                $endYear = date('y', strtotime($newFY['END_DATE']));
                $newFY['FIN_YEAR_NAME'] = $startYear . '-' . $endYear;
            }
            
            error_log("New financial year created automatically: {$fyName}");
            
            return $newFY;
        } else {
            throw new Exception("Failed to insert new financial year");
        }
        
    } catch (Exception $e) {
        error_log("Error creating financial year: " . $e->getMessage());
        return getLatestFinancialYear($conn);
    }
}

// Get the current financial year
$currentFY = checkAndCreateFinancialYear($conn);

// Update session with current financial year data
// IMPORTANT: Only set session if not already set from login
// This preserves the financial year the user selected during login
if ($currentFY) {
    // Ensure FIN_YEAR_NAME is set
    if (!isset($currentFY['FIN_YEAR_NAME'])) {
        $startYear = date('Y', strtotime($currentFY['START_DATE']));
        $endYear = date('y', strtotime($currentFY['END_DATE']));
        $currentFY['FIN_YEAR_NAME'] = $startYear . '-' . $endYear;
    }
    
    // CRITICAL FIX: Only set session if no valid financial year was selected during login
    // This preserves the user's selected year - do NOT overwrite with current system year!
    if (!isset($_SESSION['FIN_YEAR_ID']) || $_SESSION['FIN_YEAR_ID'] == 0) {
        // First time login - use current system FY
        $_SESSION['FIN_YEAR_ID'] = $currentFY['ID'];
        $_SESSION['FIN_YEAR_NAME'] = $currentFY['FIN_YEAR_NAME'];
        $_SESSION['FIN_YEAR_START'] = $currentFY['START_DATE'];
        $_SESSION['FIN_YEAR_END'] = $currentFY['END_DATE'];
    }
    // If user already has a valid FY set from login, keep it - do NOT overwrite!
} else {
    // This should never happen, but just in case
    error_log("CRITICAL: No financial year found or created!");
    
    // Set default values based on current date
    $currentMonth = date('m');
    $currentYear = date('Y');
    
    if ($currentMonth >= 4) {
        $fyStartYear = $currentYear;
        $fyEndYear = $currentYear + 1;
    } else {
        $fyStartYear = $currentYear - 1;
        $fyEndYear = $currentYear;
    }
    
    $_SESSION['FIN_YEAR_ID'] = 0;
    $_SESSION['FIN_YEAR_NAME'] = $fyStartYear . '-' . substr($fyEndYear, -2);
    $_SESSION['FIN_YEAR_START'] = $fyStartYear . '-04-01 00:00:00';
    $_SESSION['FIN_YEAR_END'] = $fyEndYear . '-03-31 23:59:59';
    
    error_log("Set default financial year values: " . $_SESSION['FIN_YEAR_NAME']);
}

$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);

// =============================================================================
// MONTH TRANSITION FUNCTIONS - DYNAMIC FOR ANY MONTH
// =============================================================================

function getCurrentMonth() {
    return date('Y-m');
}

function getPreviousMonth() {
    return date('Y-m', strtotime('first day of previous month'));
}

function getCurrentDate() {
    return date('Y-m-d');
}

function getCurrentDay() {
    return (int)date('j');
}

/**
 * Get month suffix in MM_YY format (e.g., 02_26 for February 2026)
 */
function getMonthSuffix($month) {
    $date = DateTime::createFromFormat('Y-m', $month);
    return $date->format('m_y');
}

/**
 * Get days in month (28, 29, 30, or 31)
 */
function getDaysInMonth($month) {
    return (int)date('t', strtotime($month . '-01'));
}

/**
 * Check if a specific day column exists in the table
 */
function doesDayColumnExist($conn, $tableName, $day, $fieldType = 'CLOSING') {
    if ($day < 1 || $day > 31) return false;
    
    $columnName = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_" . $fieldType;
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = ? 
        AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['column_exists'] > 0;
}

/**
 * Get all existing day columns in the table
 */
function getAllExistingDayColumns($conn, $tableName) {
    $columns = [];
    
    $stmt = $conn->prepare("
        SELECT COLUMN_NAME 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = ? 
        AND COLUMN_NAME LIKE 'DAY_%'
        ORDER BY COLUMN_NAME
    ");
    $stmt->bind_param("s", $tableName);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['COLUMN_NAME'];
    }
    $stmt->close();
    
    return $columns;
}

/**
 * ALTER TABLE to add missing day columns for the new month
 */
function ensureDayColumnsForMonth($conn, $tableName, $month) {
    $daysInMonth = getDaysInMonth($month);
    $alterations = [];
    
    error_log("Ensuring day columns for {$month} with {$daysInMonth} days");
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
        
        // Check each column type for this day
        foreach (['OPEN', 'PURCHASE', 'SALES', 'CLOSING'] as $fieldType) {
            $columnName = "DAY_{$dayPadded}_{$fieldType}";
            
            if (!doesDayColumnExist($conn, $tableName, $day, $fieldType)) {
                $alterations[] = "ADD COLUMN `{$columnName}` int(11) DEFAULT 0";
                error_log("Need to add column: {$columnName}");
            }
        }
    }
    
    // Also check for and potentially remove columns beyond the month's days
    for ($day = $daysInMonth + 1; $day <= 31; $day++) {
        $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
        
        foreach (['OPEN', 'PURCHASE', 'SALES', 'CLOSING'] as $fieldType) {
            $columnName = "DAY_{$dayPadded}_{$fieldType}";
            
            if (doesDayColumnExist($conn, $tableName, $day, $fieldType)) {
                // Comment out the next line if you want to keep extra columns
                // $alterations[] = "DROP COLUMN `{$columnName}`";
                error_log("Extra column exists beyond month: {$columnName}");
            }
        }
    }
    
    if (!empty($alterations)) {
        $alterSql = "ALTER TABLE `{$tableName}` " . implode(", ", $alterations);
        error_log("Executing ALTER TABLE: " . $alterSql);
        
        if ($conn->query($alterSql)) {
            error_log("Successfully added " . count($alterations) . " columns to {$tableName}");
            return [
                'success' => true,
                'columns_added' => count($alterations),
                'alterations' => $alterations
            ];
        } else {
            error_log("Failed to add columns: " . $conn->error);
            return [
                'success' => false,
                'error' => $conn->error
            ];
        }
    }
    
    error_log("No column alterations needed for {$tableName}");
    return [
        'success' => true,
        'columns_added' => 0,
        'message' => 'No alterations needed'
    ];
}

/**
 * Create archive table with month suffix
 * Format: tbldailystock_1_02_26 (for February 2026)
 * Creates table with only the days that exist in that specific month
 * IMPORTANT: Copies ALL data from source to archive
 */
function createArchiveTable($conn, $sourceTable, $month) {
    $monthSuffix = getMonthSuffix($month);
    $archiveTable = $sourceTable . '_' . $monthSuffix;
    $daysInMonth = getDaysInMonth($month);
    
    try {
        // Check if archive table already exists
        $checkExists = $conn->query("SHOW TABLES LIKE '{$archiveTable}'");
        if ($checkExists->num_rows > 0) {
            error_log("Archive table {$archiveTable} already exists, skipping creation");
            return [
                'success' => true,
                'message' => 'Archive already exists',
                'archive_table' => $archiveTable,
                'action' => 'already_exists'
            ];
        }
        
        error_log("Creating archive table {$archiveTable} from {$sourceTable} with {$daysInMonth} days");
        
        // First, copy the full source table structure and data to archive
        // Use CREATE TABLE ... LIKE to copy structure
        $copyStructureSQL = "CREATE TABLE `{$archiveTable}` LIKE `{$sourceTable}`";
        if (!$conn->query($copyStructureSQL)) {
            throw new Exception("Failed to create archive table structure: " . $conn->error);
        }
        
        // Copy ALL data from source to archive
        $copyDataSQL = "INSERT INTO `{$archiveTable}` SELECT * FROM `{$sourceTable}`";
        if (!$conn->query($copyDataSQL)) {
            // If copy fails, drop the archive table
            $conn->query("DROP TABLE IF EXISTS `{$archiveTable}`");
            throw new Exception("Failed to copy data to archive: " . $conn->error);
        }
        
        $copiedRows = $conn->affected_rows;
        error_log("Copied {$copiedRows} rows to archive {$archiveTable}");
        
        // Now remove extra day columns beyond this month's days
        // This is optional - keeps archive clean with only relevant days
        for ($day = $daysInMonth + 1; $day <= 31; $day++) {
            $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
            foreach (['OPEN', 'PURCHASE', 'SALES', 'CLOSING'] as $fieldType) {
                $columnName = "`DAY_{$dayPadded}_{$fieldType}`";
                $conn->query("ALTER TABLE `{$archiveTable}` DROP COLUMN IF EXISTS {$columnName}");
            }
        }
        
        error_log("Successfully created archive {$archiveTable} with {$copiedRows} rows and {$daysInMonth} days");
        
        return [
            'success' => true,
            'archive_table' => $archiveTable,
            'copied_rows' => $copiedRows,
            'month' => $month,
            'days_in_month' => $daysInMonth,
            'action' => 'created'
        ];
        
    } catch (Exception $e) {
        error_log("Archive creation failed: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'archive_table' => $archiveTable
        ];
    }
}

/**
 * Get the last day's closing stock from a specific month
 */
function getLastDayClosingStock($conn, $tableName, $month) {
    $daysInMonth = getDaysInMonth($month);
    $lastDayCol = "DAY_" . str_pad($daysInMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    error_log("Getting closing stock from {$month}, last day = {$daysInMonth}, column = {$lastDayCol}");
    
    // Check if the column exists
    if (!doesDayColumnExist($conn, $tableName, $daysInMonth, 'CLOSING')) {
        error_log("Column {$lastDayCol} doesn't exist in {$tableName}");
        return [];
    }
    
    $query = "SELECT ITEM_CODE, {$lastDayCol} as closing_stock FROM `{$tableName}` WHERE STK_MONTH = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $closingData = [];
    while ($row = $result->fetch_assoc()) {
        $closingData[$row['ITEM_CODE']] = (int)$row['closing_stock'];
    }
    
    $stmt->close();
    error_log("Retrieved closing stock for " . count($closingData) . " items");
    
    return $closingData;
}

/**
 * Fill gaps in daily stock data
 * If a day has 0 opening but previous day has closing > 0, copy the closing to opening
 * Then recalculate all subsequent days' closing
 * Only fills gaps up to today's date
 */
function fillStockGaps($conn, $tableName, $month, $itemCode = null) {
    $daysInMonth = getDaysInMonth($month);
    $currentDay = (int)date('j'); // Today's day number
    $today = date('Y-m-d');
    
    // Determine how far to fill - either today or end of month
    $fillUntilDay = min($daysInMonth, $currentDay);
    
    error_log("Starting gap filling for month {$month}, filling up to day {$fillUntilDay}");
    
    // Get all items if not specified
    $items = [];
    if ($itemCode) {
        $items[] = $itemCode;
    } else {
        $query = "SELECT DISTINCT ITEM_CODE FROM `{$tableName}` WHERE STK_MONTH = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $month);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = $row['ITEM_CODE'];
        }
        $stmt->close();
    }
    
    $filledGaps = 0;
    
    foreach ($items as $code) {
        // Get current stock data for this item
        $stockData = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $stockData[$day] = [
                'open' => 0,
                'purchase' => 0,
                'sales' => 0,
                'closing' => 0
            ];
        }
        
        // Fetch actual data from DB
        $query = "SELECT * FROM `{$tableName}` WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $month, $code);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $stockData[$day] = [
                    'open' => floatval($row["DAY_{$dayPadded}_OPEN"] ?? 0),
                    'purchase' => floatval($row["DAY_{$dayPadded}_PURCHASE"] ?? 0),
                    'sales' => floatval($row["DAY_{$dayPadded}_SALES"] ?? 0),
                    'closing' => floatval($row["DAY_{$dayPadded}_CLOSING"] ?? 0)
                ];
            }
        }
        $stmt->close();
        
        // Fill gaps: if opening is 0 but previous closing > 0, copy closing to opening
        // Only fill up to today's date
        $previousClosing = 0;
        for ($day = 1; $day <= $fillUntilDay; $day++) {
            // If day 1, use the opening value (which should be previous month's closing)
            if ($day == 1) {
                $previousClosing = $stockData[1]['open'];
            }
            
            // Check if there's a gap (opening is 0 but we have previous closing)
            if ($stockData[$day]['open'] == 0 && $previousClosing > 0) {
                $stockData[$day]['open'] = $previousClosing;
                $filledGaps++;
                error_log("Gap filled for item {$code} day {$day}: set OPEN = {$previousClosing}");
            }
            
            // Recalculate closing = opening + purchase - sales
            $stockData[$day]['closing'] = $stockData[$day]['open'] + $stockData[$day]['purchase'] - $stockData[$day]['sales'];
            
            // Set previous closing for next iteration
            $previousClosing = $stockData[$day]['closing'];
        }
        
        // Update the database with filled gaps (only up to today)
        for ($day = 1; $day <= $fillUntilDay; $day++) {
            $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
            $updateQuery = "UPDATE `{$tableName}` SET 
                DAY_{$dayPadded}_OPEN = ?,
                DAY_{$dayPadded}_CLOSING = ?,
                LAST_UPDATED = CURRENT_TIMESTAMP 
                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param("ddss", 
                $stockData[$day]['open'],
                $stockData[$day]['closing'],
                $month, 
                $code
            );
            $updateStmt->execute();
            $updateStmt->close();
        }
    }
    
    error_log("Gap filling complete: filled {$filledGaps} gaps for month {$month} up to day {$fillUntilDay}");
    
    return [
        'success' => true,
        'filled_gaps' => $filledGaps,
        'month' => $month,
        'filled_until_day' => $fillUntilDay
    ];
}

/**
 * Clear all day column data and update STK_MONTH for the new month
 * Also copies last day's closing from archive to DAY_01_OPEN
 * Order: Clear data -> Add columns -> Copy closing from archive
 */
function transformTableForNewMonth($conn, $sourceTable, $previousMonth, $newMonth) {
    try {
        $archiveTable = $sourceTable . '_' . getMonthSuffix($previousMonth);
        $daysInPreviousMonth = getDaysInMonth($previousMonth);
        $newMonthDays = getDaysInMonth($newMonth);
        
        error_log("Starting transform: {$previousMonth} ({$daysInPreviousMonth} days) -> {$newMonth} ({$newMonthDays} days)");
        
        // Step 1: Get closing stock from archive table (for later use)
        $closingData = [];
        $archiveCheck = $conn->query("SHOW TABLES LIKE '{$archiveTable}'");
        if ($archiveCheck->num_rows > 0) {
            error_log("Getting closing data from archive {$archiveTable}");
            $closingData = getLastDayClosingStock($conn, $archiveTable, $previousMonth);
            error_log("Got closing data for " . count($closingData) . " items from archive");
        } else {
            // Try from source table if archive doesn't exist
            $closingData = getLastDayClosingStock($conn, $sourceTable, $previousMonth);
            error_log("Got closing data for " . count($closingData) . " items from source table");
        }
        
        // Step 2: Ensure the source table has correct columns for new month (add missing days)
        ensureDayColumnsForMonth($conn, $sourceTable, $newMonth);
        
        // Step 3: Clear ALL data from source table (but keep structure)
        // We'll update STK_MONTH and set opening from closing
        $clearQuery = "DELETE FROM `{$sourceTable}` WHERE STK_MONTH = ?";
        $clearStmt = $conn->prepare($clearQuery);
        $clearStmt->bind_param("s", $previousMonth);
        $clearStmt->execute();
        $deletedRows = $clearStmt->affected_rows;
        $clearStmt->close();
        error_log("Cleared {$deletedRows} rows from source table");
        
        // Step 4: Insert new records with opening from previous month's closing
        // IMPORTANT: Preserve the DailyStockID from archive so each item keeps its same ID
        if (!empty($closingData)) {
            $insertCount = 0;
            
            // First, get the DailyStockID mapping from archive
            $dailyStockIdMap = [];
            $archiveCheck = $conn->query("SHOW TABLES LIKE '{$archiveTable}'");
            if ($archiveCheck->num_rows > 0) {
                $idQuery = "SELECT ITEM_CODE, DailyStockID FROM `{$archiveTable}` WHERE STK_MONTH = ?";
                $idStmt = $conn->prepare($idQuery);
                $idStmt->bind_param("s", $previousMonth);
                $idStmt->execute();
                $idResult = $idStmt->get_result();
                while ($idRow = $idResult->fetch_assoc()) {
                    $dailyStockIdMap[$idRow['ITEM_CODE']] = $idRow['DailyStockID'];
                }
                $idStmt->close();
            }
            
            foreach ($closingData as $itemCode => $closingStock) {
                // Get the original DailyStockID for this item
                $originalId = $dailyStockIdMap[$itemCode] ?? null;
                
                if ($originalId) {
                    // Insert new record for new month with opening = previous month's closing
                    // PRESERVE the original DailyStockID
                    $insertQuery = "INSERT INTO `{$sourceTable}` (DailyStockID, ITEM_CODE, STK_MONTH, DAY_01_OPEN, DAY_01_CLOSING) 
                                    VALUES (?, ?, ?, ?, ?)";
                    $insertStmt = $conn->prepare($insertQuery);
                    $insertStmt->bind_param("issdd", $originalId, $itemCode, $newMonth, $closingStock, $closingStock);
                } else {
                    // Fallback: insert without DailyStockID (let auto-increment handle it)
                    $insertQuery = "INSERT INTO `{$sourceTable}` (ITEM_CODE, STK_MONTH, DAY_01_OPEN, DAY_01_CLOSING) 
                                    VALUES (?, ?, ?, ?)";
                    $insertStmt = $conn->prepare($insertQuery);
                    $insertStmt->bind_param("ssdd", $itemCode, $newMonth, $closingStock, $closingStock);
                }
                
                if ($insertStmt->execute()) {
                    $insertCount++;
                } else {
                    error_log("Failed to insert for item {$itemCode}: " . $insertStmt->error);
                }
                $insertStmt->close();
            }
            error_log("Inserted {$insertCount} new rows with opening from archive, preserving DailyStockID");
        } else {
            // No closing data, just insert empty record for new month
            error_log("No closing data available, inserting empty record for new month");
        }
        
        error_log("Transformation complete for {$previousMonth} -> {$newMonth}");
        
        return [
            'success' => true,
            'updated_items' => count($closingData),
            'previous_month' => $previousMonth,
            'new_month' => $newMonth,
            'used_archive_closing' => !empty($closingData),
            'archive_table' => $archiveTable
        ];
        
    } catch (Exception $e) {
        error_log("Table transformation failed: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Check if month transition is needed
 */
function checkMonthTransition($conn) {
    $companyId = $_SESSION['CompID'] ?? 1;
    $tableName = 'tbldailystock_' . $companyId;
    $currentMonth = getCurrentMonth();
    
    // Check if table exists
    $tableCheck = $conn->query("SHOW TABLES LIKE '{$tableName}'");
    if ($tableCheck->num_rows == 0) {
        error_log("Table {$tableName} does not exist");
        return [
            'needs_transition' => false,
            'table_exists' => false
        ];
    }
    
    // Check what months exist in the table
    $monthQuery = "SELECT DISTINCT STK_MONTH FROM `{$tableName}` ORDER BY STK_MONTH DESC";
    $monthResult = $conn->query($monthQuery);
    
    $existingMonths = [];
    while ($row = $monthResult->fetch_assoc()) {
        $existingMonths[] = $row['STK_MONTH'];
    }
    
    error_log("Existing months in {$tableName}: " . implode(', ', $existingMonths));
    
    // If no data at all, no transition needed
    if (empty($existingMonths)) {
        return [
            'needs_transition' => false,
            'table_exists' => true,
            'has_data' => false
        ];
    }
    
    // Get the latest month in the table
    $latestMonth = $existingMonths[0];
    
    // Calculate expected months based on current date
    $expectedMonths = [];
    $currentDateTime = new DateTime($currentMonth . '-01');
    $latestDateTime = new DateTime($latestMonth . '-01');
    
    // If latest month is before current month, we need transition
    if ($latestDateTime < $currentDateTime) {
        // Calculate all months between latest and current
        $tempDate = clone $latestDateTime;
        while ($tempDate < $currentDateTime) {
            $tempDate->modify('+1 month');
            if ($tempDate < $currentDateTime) {
                $expectedMonths[] = $tempDate->format('Y-m');
            }
        }
        
        error_log("Need to transition from {$latestMonth} to {$currentMonth}, missing months: " . implode(', ', $expectedMonths));
        
        return [
            'needs_transition' => true,
            'table_exists' => true,
            'has_data' => true,
            'latest_month' => $latestMonth,
            'current_month' => $currentMonth,
            'missing_months' => $expectedMonths,
            'multiple_months' => count($expectedMonths) > 0
        ];
    }
    
    return [
        'needs_transition' => false,
        'table_exists' => true,
        'has_data' => true,
        'latest_month' => $latestMonth,
        'current_month' => $currentMonth
    ];
}

/**
 * Execute complete month transition
 */
function executeMonthTransition($conn) {
    $companyId = $_SESSION['CompID'] ?? 1;
    $tableName = 'tbldailystock_' . $companyId;
    $currentMonth = getCurrentMonth();
    
    $results = [
        'company_id' => $companyId,
        'table_name' => $tableName,
        'steps' => [],
        'success' => true
    ];
    
    try {
        // Step 1: Check current state
        $checkResult = checkMonthTransition($conn);
        $results['check_result'] = $checkResult;
        
        if (!$checkResult['needs_transition']) {
            $results['message'] = 'No transition needed';
            return $results;
        }
        
        // Step 2: Ensure table has correct columns for current month
        error_log("Step 1: Ensuring columns for current month {$currentMonth}");
        $columnResult = ensureDayColumnsForMonth($conn, $tableName, $currentMonth);
        $results['steps']['ensure_columns'] = $columnResult;
        
        if (!$columnResult['success']) {
            throw new Exception("Failed to ensure columns: " . ($columnResult['error'] ?? 'Unknown error'));
        }
        
        // Step 3: Process each missing month
        $currentProcessingMonth = $checkResult['latest_month'];
        $targetMonth = $currentMonth;
        $archiveResults = [];
        $transformResults = [];
        
        while ($currentProcessingMonth != $targetMonth) {
            // Calculate next month
            $nextMonthDate = new DateTime($currentProcessingMonth . '-01');
            $nextMonthDate->modify('+1 month');
            $nextMonth = $nextMonthDate->format('Y-m');
            
            error_log("Processing transition: {$currentProcessingMonth} → {$nextMonth}");
            
            // Step 3a: Create archive for current processing month
            $archiveResult = createArchiveTable($conn, $tableName, $currentProcessingMonth);
            $archiveResults[] = $archiveResult;
            $results['steps']['archives'] = $archiveResults;
            
            if (!$archiveResult['success']) {
                throw new Exception("Failed to create archive for {$currentProcessingMonth}: " . ($archiveResult['error'] ?? 'Unknown error'));
            }
            
            // Step 3b: Ensure next month has correct columns
            $nextMonthColumnResult = ensureDayColumnsForMonth($conn, $tableName, $nextMonth);
            if (!$nextMonthColumnResult['success']) {
                throw new Exception("Failed to ensure columns for {$nextMonth}");
            }
            
            // Step 3c: Transform table from current to next month
            $transformResult = transformTableForNewMonth($conn, $tableName, $currentProcessingMonth, $nextMonth);
            $transformResults[] = $transformResult;
            $results['steps']['transforms'] = $transformResults;
            
            if (!$transformResult['success']) {
                throw new Exception("Failed to transform from {$currentProcessingMonth} to {$nextMonth}: " . ($transformResult['error'] ?? 'Unknown error'));
            }
            
            // Step 3d: Fill gaps in the new month (for days when system was off)
            error_log("Step 4: Filling gaps in month {$nextMonth}");
            $gapFillResult = fillStockGaps($conn, $tableName, $nextMonth);
            $results['steps']['gap_fill'] = $gapFillResult;
            
            // Move to next month
            $currentProcessingMonth = $nextMonth;
        }
        
        // Final step: Also fill gaps in the current month after all transitions
        $currentMonth = getCurrentMonth();
        if ($currentProcessingMonth == $currentMonth) {
            error_log("Final step: Filling gaps in current month {$currentMonth}");
            $finalGapFill = fillStockGaps($conn, $tableName, $currentMonth);
            $results['steps']['final_gap_fill'] = $finalGapFill;
        }
        
        $results['success'] = true;
        $results['message'] = "Successfully transitioned from {$checkResult['latest_month']} to {$currentMonth}";
        
    } catch (Exception $e) {
        error_log("Month transition failed: " . $e->getMessage());
        $results['success'] = false;
        $results['error'] = $e->getMessage();
    }
    
    return $results;
}

// =============================================================================
// TRANSITION PROCESSING
// =============================================================================

$transitionResults = null;
if (isset($_POST['execute_month_transition']) && $_POST['execute_month_transition'] === '1') {
    error_log("Manual month transition started by user");
    
    try {
        $transitionResults = executeMonthTransition($conn);
        
        if ($transitionResults['success']) {
            $successMsg = "Month transition completed successfully! ";
            if (isset($transitionResults['steps']['archives'])) {
                foreach ($transitionResults['steps']['archives'] as $archive) {
                    if ($archive['success']) {
                        $successMsg .= " | Archive: " . $archive['archive_table'];
                    }
                }
            }
            $_SESSION['transition_message'] = $successMsg;
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['transition_message'] = "Month transition failed: " . ($transitionResults['error'] ?? 'Unknown error');
            $_SESSION['message_type'] = 'error';
        }
    } catch (Exception $e) {
        error_log("Month transition exception: " . $e->getMessage());
        $_SESSION['transition_message'] = "System error: " . $e->getMessage();
        $_SESSION['message_type'] = 'error';
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

/**
 * Fix DailyStockID in current table to match archive
 * This is needed when historical transitions didn't preserve the ID
 * The archive contains the previous month's data with correct DailyStockID
 * We need to match by ITEM_CODE and update current month's records
 */
function fixDailyStockIdPreservation($conn, $sourceTable, $archiveMonth) {
    $archiveSuffix = getMonthSuffix($archiveMonth);
    $archiveTable = $sourceTable . '_' . $archiveSuffix;
    $currentMonth = getCurrentMonth();
    
    // Check if archive exists
    $archiveCheck = $conn->query("SHOW TABLES LIKE '{$archiveTable}'");
    if ($archiveCheck->num_rows == 0) {
        error_log("Archive table {$archiveTable} does not exist, skipping ID fix");
        return [
            'success' => true,
            'message' => 'No archive table to fix from'
        ];
    }
    
    try {
        // Get DailyStockID mapping from archive
        // Archive has the OLD month (e.g., 2026-02), current table has NEW month (e.g., 2026-03)
        // We match by ITEM_CODE
        $idMapQuery = "
            SELECT a.ITEM_CODE, a.DailyStockID as archive_id, c.DailyStockID as current_id
            FROM `{$archiveTable}` a
            INNER JOIN `{$sourceTable}` c ON a.ITEM_CODE = c.ITEM_CODE AND c.STK_MONTH = ?
            WHERE a.STK_MONTH = ?
        ";
        $stmt = $conn->prepare($idMapQuery);
        $stmt->bind_param("ss", $currentMonth, $archiveMonth);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $updatedCount = 0;
        $mismatchedCount = 0;
        while ($row = $result->fetch_assoc()) {
            // Only update if IDs are different
            if ($row['archive_id'] != $row['current_id']) {
                // Update the current table with the correct DailyStockID from archive
                $updateQuery = "UPDATE `{$sourceTable}` SET DailyStockID = ? WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bind_param("iss", $row['archive_id'], $currentMonth, $row['ITEM_CODE']);
                $updateStmt->execute();
                $updatedCount++;
                $updateStmt->close();
                error_log("Fixed DailyStockID for item {$row['ITEM_CODE']}: {$row['current_id']} -> {$row['archive_id']}");
            }
            $mismatchedCount++;
        }
        $stmt->close();
        
        error_log("Checked {$mismatchedCount} items, fixed {$updatedCount} DailyStockID values from archive {$archiveTable}");
        
        return [
            'success' => true,
            'fixed_count' => $updatedCount,
            'checked_count' => $mismatchedCount,
            'archive_table' => $archiveTable,
            'source_table' => $sourceTable,
            'current_month' => $currentMonth,
            'archive_month' => $archiveMonth
        ];
        
    } catch (Exception $e) {
        error_log("Failed to fix DailyStockID: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Check if transition is needed
$transitionInfo = checkMonthTransition($conn);

// Auto-execute if needed
// Force transition check to run every time for debugging
error_log("=== AUTO TRANSITION CHECK ===");
error_log("Current month: " . getCurrentMonth());
error_log("Transition info: " . print_r($transitionInfo, true));

if ($transitionInfo['needs_transition']) {
    $transitionKey = 'auto_transition_' . date('Y-m-d');
    error_log("Checking transition key: {$transitionKey}, set: " . (isset($_SESSION[$transitionKey]) ? 'yes' : 'no'));
    
    // Always execute if needed (remove the session check for now to debug)
    // The session check was preventing re-execution if it failed before
    if (!isset($_SESSION[$transitionKey])) {
        error_log("Auto transition triggered - executing now");
        $autoResults = executeMonthTransition($conn);
        error_log("Auto transition result: " . print_r($autoResults, true));
        
        if ($autoResults['success']) {
            $_SESSION[$transitionKey] = true;
            $_SESSION['transition_message'] = "Auto transition completed successfully!";
            $_SESSION['message_type'] = 'success';
            
            // FIX: After transition, fix the DailyStockID to match archive
            $previousMonth = $transitionInfo['latest_month'] ?? '';
            if ($previousMonth) {
                $companyId = $_SESSION['CompID'] ?? 1;
                $tableName = 'tbldailystock_' . $companyId;
                $fixResult = fixDailyStockIdPreservation($conn, $tableName, $previousMonth);
                error_log("DailyStockID fix result: " . print_r($fixResult, true));
                if ($fixResult['success'] && $fixResult['fixed_count'] > 0) {
                    $_SESSION['transition_message'] .= " | Fixed {$fixResult['fixed_count']} DailyStockID values";
                }
            }
        } else {
            $_SESSION['transition_message'] = "Auto transition failed: " . ($autoResults['error'] ?? 'Unknown error');
            $_SESSION['message_type'] = 'error';
            // Still mark as done to prevent infinite loop, but show error
            $_SESSION[$transitionKey] = true;
        }
        // Refresh transition info after auto-execution
        $transitionInfo = checkMonthTransition($conn);
    } else {
        error_log("Auto transition already executed today, skipping");
    }
} else {
    // No transition needed, but still check for gaps in current month
    $gapFillKey = 'auto_gap_fill_' . date('Y-m-d');
    if (!isset($_SESSION[$gapFillKey])) {
        $currentMonth = getCurrentMonth();
        $companyId = $_SESSION['CompID'] ?? 1;
        $tableName = 'tbldailystock_' . $companyId;
        
        // Check if current month has data
        $checkMonth = $conn->query("SELECT COUNT(*) as cnt FROM `{$tableName}` WHERE STK_MONTH = '{$currentMonth}'");
        if ($checkMonth && $checkMonth->fetch_assoc()['cnt'] > 0) {
            error_log("Checking for gaps in current month {$currentMonth}");
            $gapResult = fillStockGaps($conn, $tableName, $currentMonth);
            if ($gapResult['filled_gaps'] > 0) {
                $_SESSION['transition_message'] = "Gap filling completed: {$gapResult['filled_gaps']} gaps filled";
                $_SESSION['message_type'] = 'info';
            }
            $_SESSION[$gapFillKey] = true;
            
            // Also try to fix DailyStockID if we have previous month archive
            $prevMonth = date('Y-m', strtotime('first day of previous month'));
            $fixResult = fixDailyStockIdPreservation($conn, $tableName, $prevMonth);
            if ($fixResult['success'] && $fixResult['fixed_count'] > 0) {
                $_SESSION['transition_message'] = "Fixed {$fixResult['fixed_count']} DailyStockID values";
                $_SESSION['message_type'] = 'info';
            }
        }
    }
}

// Get any messages
$successMessage = '';
$messageType = '';
if (isset($_SESSION['transition_message'])) {
    $successMessage = $_SESSION['transition_message'];
    $messageType = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['transition_message']);
    unset($_SESSION['message_type']);
}

// =============================================================================
// DASHBOARD STATISTICS FUNCTIONS
// =============================================================================

function getTotalItemsCount($conn) {
    $query = "SELECT COUNT(*) as item_count FROM tblitemmaster";
    $result = $conn->query($query);
    if (!$result) return 0;
    $row = $result->fetch_assoc();
    $result->free();
    return $row ? $row['item_count'] : 0;
}

function getLicensedItemsCount($conn, $license_type) {
    $allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);
    if (empty($allowed_categories)) return 0;
    
    $category_codes = [];
    foreach ($allowed_categories as $cat) {
        $category_codes[] = $conn->real_escape_string($cat['CATEGORY_CODE']);
    }
    $codes_string = "'" . implode("','", $category_codes) . "'";
    
    $query = "SELECT COUNT(*) as item_count FROM tblitemmaster WHERE CATEGORY_CODE IN ($codes_string)";
    $result = $conn->query($query);
    if ($result) {
        $row = $result->fetch_assoc();
        $result->free();
        return $row ? $row['item_count'] : 0;
    }
    return 0;
}

function getClassesWithCounts($conn, $license_type) {
    $classes = [];
    $allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);
    if (empty($allowed_categories)) return [];
    
    $category_codes = [];
    foreach ($allowed_categories as $cat) {
        $category_codes[] = $conn->real_escape_string($cat['CATEGORY_CODE']);
    }
    $codes_string = "'" . implode("','", $category_codes) . "'";
    
    $query = "
        SELECT 
            tcn.CLASS_CODE,
            tcn.CLASS_NAME,
            tcn.CATEGORY_CODE,
            tc.CATEGORY_NAME,
            tcn.LIQ_FLAG,
            COUNT(tim.CODE) as item_count
        FROM tblclass_new tcn
        LEFT JOIN tblitemmaster tim ON tcn.CLASS_CODE = tim.CLASS_CODE_NEW
        LEFT JOIN tblcategory tc ON tcn.CATEGORY_CODE = tc.CATEGORY_CODE
        WHERE tcn.CATEGORY_CODE IN ($codes_string)
        GROUP BY tcn.CLASS_CODE, tcn.CLASS_NAME, tcn.CATEGORY_CODE, tc.CATEGORY_NAME, tcn.LIQ_FLAG
        HAVING COUNT(tim.CODE) > 0
        ORDER BY tc.CATEGORY_NAME, tcn.CLASS_NAME
    ";
    
    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $classes[$row['CLASS_CODE']] = [
                'name' => $row['CLASS_NAME'],
                'category_code' => $row['CATEGORY_CODE'],
                'category_name' => $row['CATEGORY_NAME'],
                'liq_flag' => $row['LIQ_FLAG'],
                'count' => $row['item_count']
            ];
        }
        $result->free();
    }
    return $classes;
}

function getFormattedClassName($class_name, $category_name) {
    if (stripos($category_name, 'spirit') !== false) {
        if (stripos($class_name, 'imfl') !== false) return 'IMFL Spirit';
        if (stripos($class_name, 'imported') !== false) return 'Imported Spirit';
        if (stripos($class_name, 'mml') !== false) return 'MML Spirit';
    } elseif (stripos($category_name, 'wine') !== false) {
        if (stripos($class_name, 'imported') !== false) return 'Imported Wine';
        if (stripos($class_name, 'indian') !== false) return 'Wine Indian';
        if (stripos($class_name, 'mml') !== false) return 'MML Wine';
    } elseif (stripos($category_name, 'beer') !== false) {
        if (stripos($class_name, 'fermented') !== false) return 'Fermented Beer';
        if (stripos($class_name, 'mild') !== false) return 'Mild Beer';
    }
    return $class_name;
}

function getCategoryColor($category_name, $class_name = '') {
    if (stripos($category_name, 'spirit') !== false) {
        if (stripos($class_name, 'imfl') !== false) return '#667eea';
        if (stripos($class_name, 'imported') !== false) return '#764ba2';
        if (stripos($class_name, 'mml') !== false) return '#4facfe';
        return '#667eea';
    } elseif (stripos($category_name, 'wine') !== false) {
        if (stripos($class_name, 'imported') !== false) return '#f5576c';
        if (stripos($class_name, 'indian') !== false) return '#fa709a';
        if (stripos($class_name, 'mml') !== false) return '#ff6b6b';
        return '#f5576c';
    } elseif (stripos($category_name, 'beer') !== false) {
        if (stripos($class_name, 'fermented') !== false) return '#43e97b';
        if (stripos($class_name, 'mild') !== false) return '#38f9d7';
        return '#43e97b';
    }
    $colors = ['#667eea', '#764ba2', '#f5576c', '#4facfe', '#43e97b', '#fa709a', '#ff9a9e', '#a18cd1'];
    return $colors[array_rand($colors)];
}

function getCategoryIcon($category_name, $class_name = '') {
    if (stripos($category_name, 'spirit') !== false) return 'fas fa-glass-whiskey';
    if (stripos($category_name, 'wine') !== false) return 'fas fa-wine-glass';
    if (stripos($category_name, 'beer') !== false) return 'fas fa-beer';
    return 'fas fa-cube';
}

function getTotalCustomers($conn, $company_id) {
    $query = "SELECT COUNT(*) as total_customers FROM tbllheads WHERE REF_CODE = 'CUST' AND CompID = ?";
    $stmt = $conn->prepare($query);
    if ($stmt) {
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ? $row['total_customers'] : 0;
    }
    return 0;
}

function getTotalSuppliers($conn) {
    $query = "SELECT COUNT(*) as total FROM tblsupplier WHERE CODE IS NOT NULL";
    $result = $conn->query($query);
    if (!$result) return 0;
    $row = $result->fetch_assoc();
    $result->free();
    return $row ? $row['total'] : 0;
}

function getActivePermits($conn) {
    $currentDate = date('Y-m-d');
    $query = "SELECT COUNT(*) as total FROM tblpermit WHERE P_EXP_DT >= ? AND PRMT_FLAG = 1";
    $stmt = $conn->prepare($query);
    if (!$stmt) return 0;
    $stmt->bind_param("s", $currentDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['total'] : 0;
}

function getDryDaysCount($conn) {
    $currentYear = date('Y');
    $query = "SELECT COUNT(*) as total FROM tbldrydays WHERE YEAR(DDATE) = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) return 0;
    $stmt->bind_param("s", $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? $row['total'] : 0;
}

// Get dashboard statistics
$stats = [
    'total_items' => number_format(getTotalItemsCount($conn)),
    'licensed_items' => $license_type ? number_format(getLicensedItemsCount($conn, $license_type)) : '0',
    'total_customers' => number_format(getTotalCustomers($conn, $company_id)),
    'total_suppliers' => number_format(getTotalSuppliers($conn)),
    'total_permits' => number_format(getActivePermits($conn)),
    'total_dry_days' => number_format(getDryDaysCount($conn))
];

$classes = $license_type ? getClassesWithCounts($conn, $license_type) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #43e97b;
            --danger-color: #f5576c;
            --warning-color: #ff9a9e;
            --info-color: #4facfe;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            transition: transform 0.3s;
            border-left: 4px solid;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: white;
            font-size: 24px;
        }
        
        .stat-info h4 {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }
        
        .stat-info p {
            font-size: 28px;
            font-weight: bold;
            margin: 0;
            color: #333;
        }
        
        .license-badge {
            background: linear-gradient(45deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: bold;
        }
        
        .allowed-badge {
            background: linear-gradient(45deg, #43e97b 0%, #38f9d7 100%);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            margin-left: 5px;
        }
        
        .transition-alert {
            background: linear-gradient(45deg, #f6d365 0%, #fda085 100%);
            border: none;
            color: #333;
            margin-bottom: 20px;
        }
        
        .transition-alert .card-body {
            padding: 20px;
        }
        
        .btn-transition {
            background: linear-gradient(45deg, #ff9a9e 0%, #fad0c4 100%);
            border: none;
            color: #333;
            font-weight: bold;
            padding: 10px 20px;
        }
        
        .btn-transition:hover {
            background: linear-gradient(45deg, #ff6b6b 0%, #ff8e53 100%);
            color: white;
        }
        
        .month-info {
            background: rgba(255, 255, 255, 0.9);
            padding: 10px 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #667eea;
        }
        
        .process-steps {
            margin: 20px 0;
            padding: 15px;
            background: rgba(255, 255, 255, 0.8);
            border-radius: 10px;
        }
        
        .step-item {
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #ddd;
        }
        
        .step-item:last-child {
            border-bottom: none;
        }
        
        .fy-indicator {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 20px;
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .fy-indicator .alert {
            margin-bottom: 0;
            background: rgba(255, 255, 255, 0.15);
            border: none;
            color: white;
        }
        
        .fy-indicator .alert .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .current-day-highlight {
            background: #ff6b6b;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: bold;
        }
        
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .header-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .archive-badge {
            background: #4ecdc4;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.7rem;
            margin-left: 5px;
        }
    </style>
    <script src="components/shortcuts.js?v=<?= time() ?>"></script>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    
    <div class="main-content">
        <div class="content-area">
            <!-- Financial Year Indicator -->
            <div class="fy-indicator">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-calendar-alt me-2"></i>
                        <strong>Financial Year:</strong> 
                        <?php 
                        echo isset($_SESSION['FIN_YEAR_NAME']) ? htmlspecialchars($_SESSION['FIN_YEAR_NAME']) : 'N/A'; 
                        ?>
                        (<?php 
                        echo isset($_SESSION['FIN_YEAR_START']) ? date('d M Y', strtotime($_SESSION['FIN_YEAR_START'])) : 'N/A'; 
                        ?> - 
                        <?php 
                        echo isset($_SESSION['FIN_YEAR_END']) ? date('d M Y', strtotime($_SESSION['FIN_YEAR_END'])) : 'N/A'; 
                        ?>)
                    </div>
                    <div class="text-white-50">
                        <i class="fas fa-calendar-day me-1"></i>
                        <?php echo date('d M Y'); ?>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['fy_transition']) && $_SESSION['fy_transition']): ?>
                    <div class="alert alert-success alert-dismissible fade show mt-3 mb-0">
                        <i class="fas fa-check-circle"></i>
                        <strong>Financial Year Updated!</strong> 
                        System has transitioned to <?php echo htmlspecialchars($_SESSION['FIN_YEAR_NAME']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['fy_transition']); ?>
                <?php endif; ?>
            </div>
            
            <div class="header-info">
                <h3 class="mb-0">Dashboard Overview</h3>
                <?php if($license_type): ?>
                    <div class="d-flex align-items-center">
                        <span class="license-badge">License: <?php echo htmlspecialchars($license_type); ?></span>
                        <span class="allowed-badge">
                            <?php echo count($classes); ?> Classes
                        </span>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if($successMessage): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?> alert-dismissible fade show">
                    <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                    <strong><?php echo $messageType === 'success' ? 'Success!' : 'Error!'; ?></strong> 
                    <?php echo htmlspecialchars($successMessage); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if($transitionInfo && $transitionInfo['needs_transition']): ?>
                <!-- Month Transition Card -->
                <div class="card transition-alert">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h4 class="mb-0">
                                    <i class="fas fa-calendar-alt"></i>
                                    Month Transition Required
                                </h4>
                                <div class="mt-2">
                                    <strong>Current Month in DB:</strong> <?php echo $transitionInfo['latest_month']; ?> 
                                    (<?php echo getDaysInMonth($transitionInfo['latest_month']); ?> days) │
                                    <strong>Expected Month:</strong> <?php echo $transitionInfo['current_month']; ?> 
                                    (<?php echo getDaysInMonth($transitionInfo['current_month']); ?> days) │
                                    <?php if(!empty($transitionInfo['missing_months'])): ?>
                                        <strong>Missing Months:</strong> <?php echo implode(', ', $transitionInfo['missing_months']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <form method="POST" id="transitionForm">
                                <input type="hidden" name="execute_month_transition" value="1">
                                <button type="submit" class="btn btn-warning btn-transition">
                                    <i class="fas fa-redo"></i> Execute Transition
                                </button>
                            </form>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>Process Overview:</strong>
                            The system will create archive tables and transition through each missing month.
                        </div>
                        
                        <div class="process-steps">
                            <h6><i class="fas fa-list-ol"></i> Automatic Process:</h6>
                            
                            <?php foreach($transitionInfo['missing_months'] as $index => $missingMonth): 
                                $prevMonth = $index == 0 ? $transitionInfo['latest_month'] : $transitionInfo['missing_months'][$index-1];
                                $monthDate = new DateTime($missingMonth . '-01');
                                $monthName = $monthDate->format('F Y');
                                $prevMonthDate = new DateTime($prevMonth . '-01');
                                $prevMonthName = $prevMonthDate->format('F Y');
                            ?>
                            <div class="step-item">
                                <strong>Step <?php echo $index + 1; ?>: Transition <?php echo $prevMonthName; ?> → <?php echo $monthName; ?></strong>
                                <div class="mt-1">
                                    <small>• Create archive: <code>tbldailystock_<?php echo $company_id; ?>_<?php echo getMonthSuffix($prevMonth); ?></code></small>
                                    <br>
                                    <small>• Update STK_MONTH from <?php echo $prevMonth; ?> to <?php echo $missingMonth; ?></small>
                                    <br>
                                    <small>• Set DAY_01_OPEN = Last day closing of <?php echo $prevMonth; ?></small>
                                    <br>
                                    <small>• Ensure <?php echo getDaysInMonth($missingMonth); ?> day columns exist</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="fas fa-clock"></i> 
                                    This process may take several minutes. Do not close the browser.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Grid -->
            <div class="stats-grid">
                <div class="stat-card" style="border-left-color: #667eea;">
                    <div class="stat-icon" style="background: #667eea;">
                        <i class="fas fa-box"></i>
                    </div>
                    <div class="stat-info">
                        <h4>TOTAL ITEMS</h4>
                        <p><?php echo $stats['total_items']; ?></p>
                        <small class="text-muted">All Products</small>
                    </div>
                </div>
                
                <div class="stat-card" style="border-left-color: #764ba2;">
                    <div class="stat-icon" style="background: #764ba2;">
                        <i class="fas fa-filter"></i>
                    </div>
                    <div class="stat-info">
                        <h4>LICENSED ITEMS</h4>
                        <p><?php echo $stats['licensed_items']; ?></p>
                        <small class="text-muted"><?php echo htmlspecialchars($license_type ?: 'ALL'); ?> License</small>
                    </div>
                </div>
                
                <div class="stat-card" style="border-left-color: #f5576c;">
                    <div class="stat-icon" style="background: #f5576c;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h4>TOTAL CUSTOMERS</h4>
                        <p><?php echo $stats['total_customers']; ?></p>
                        <small class="text-muted">Company <?php echo $company_id; ?></small>
                    </div>
                </div>
                
                <div class="stat-card" style="border-left-color: #4facfe;">
                    <div class="stat-icon" style="background: #4facfe;">
                        <i class="fas fa-truck"></i>
                    </div>
                    <div class="stat-info">
                        <h4>TOTAL SUPPLIERS</h4>
                        <p><?php echo $stats['total_suppliers']; ?></p>
                    </div>
                </div>
                
                <div class="stat-card" style="border-left-color: #43e97b;">
                    <div class="stat-icon" style="background: #43e97b;">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <div class="stat-info">
                        <h4>ACTIVE PERMITS</h4>
                        <p><?php echo $stats['total_permits']; ?></p>
                    </div>
                </div>
                
                <div class="stat-card" style="border-left-color: #fa709a;">
                    <div class="stat-icon" style="background: #fa709a;">
                        <i class="fas fa-calendar-times"></i>
                    </div>
                    <div class="stat-info">
                        <h4>DRY DAYS (<?php echo date('Y'); ?>)</h4>
                        <p><?php echo $stats['total_dry_days']; ?></p>
                    </div>
                </div>
                
                <?php if(!empty($classes)): ?>
                    <?php foreach (array_slice($classes, 0, 6) as $class): ?>
                        <?php 
                        $display_name = getFormattedClassName($class['name'], $class['category_name']);
                        $color = getCategoryColor($class['category_name'], $class['name']);
                        $icon = getCategoryIcon($class['category_name'], $class['name']);
                        ?>
                        <div class="stat-card" style="border-left-color: <?php echo htmlspecialchars($color); ?>;">
                            <div class="stat-icon" style="background: <?php echo htmlspecialchars($color); ?>;">
                                <i class="<?php echo htmlspecialchars($icon); ?>"></i>
                            </div>
                            <div class="stat-info">
                                <h4><?php echo htmlspecialchars($display_name); ?></h4>
                                <p><?php echo number_format($class['count']); ?></p>
                                <small class="text-muted"><?php echo number_format($class['count']); ?> items</small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Auto-hide alerts after 10 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 10000);
    
    // Show processing message on transition
    $('#transitionForm').on('submit', function(e) {
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');
    });
    
    <?php if($successMessage): ?>
        window.scrollTo(0, 0);
    <?php endif; ?>
});
</script>
</body>
</html>