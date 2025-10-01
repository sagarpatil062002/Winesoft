<?php
session_start();

// Ensure user is logged in and company is selected
if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

// Database connection
include_once "../config/db.php";
require_once 'license_functions.php';

// =============================================================================
// DEBUG LOGGING SYSTEM
// =============================================================================

/**
 * Log debug messages to a file
 */
function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/debug_month_transition.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $logMessage .= " | Data: " . json_encode($data, JSON_PRETTY_PRINT);
        } else {
            $logMessage .= " | Data: " . $data;
        }
    }
    
    $logMessage .= PHP_EOL;
    
    // Write to log file
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    
    // Also log to PHP error log for immediate visibility
    error_log("MONTH_TRANSITION: " . $message);
}

// Initialize debug log
debugLog("=== MONTH TRANSITION DEBUG SESSION STARTED ===");
debugLog("Session Data", $_SESSION);

// =============================================================================
// COMPLETE MONTH TRANSITION SYSTEM WITH MONTH-LENGTH AWARENESS
// =============================================================================

/**
 * Get current month in YYYY-MM format
 */
function getCurrentMonth() {
    return date('Y-m');
}

/**
 * Get previous month in YYYY-MM format
 */
function getPreviousMonth() {
    return date('Y-m', strtotime('first day of previous month'));
}

/**
 * Get month suffix for table names (MM_YYYY format)
 */
function getMonthSuffix($month) {
    $date = DateTime::createFromFormat('Y-m', $month);
    return $date->format('m_Y');
}

/**
 * Get days in month
 */
function getDaysInMonth($month) {
    return (int)date('t', strtotime($month . '-01'));
}

/**
 * Check if day columns exist for a specific day
 */
function doesDayColumnsExist($conn, $tableName, $day) {
    if ($day > 31) return false;
    
    $columnPrefix = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT);
    $openCol = $columnPrefix . "_OPEN";
    
    debugLog("Checking if column exists", [
        'table' => $tableName,
        'column' => $openCol,
        'day' => $day
    ]);
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = ? 
        AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $tableName, $openCol);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    $exists = $row['column_exists'] > 0;
    debugLog("Column check result", [
        'column' => $openCol,
        'exists' => $exists
    ]);
    
    return $exists;
}

/**
 * Check if archive table already exists - FIXED VERSION
 */
function archiveTableExists($conn, $tableName) {
    debugLog("Checking if archive table exists", $tableName);
    
    // FIXED: Use direct query instead of prepared statement for SHOW TABLES
    $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");
    $exists = $result->num_rows > 0;
    $result->free();
    
    debugLog("Archive table exists check", [
        'table' => $tableName,
        'exists' => $exists
    ]);
    
    return $exists;
}

/**
 * Create archive table and copy data - FIXED VERSION
 */
function createArchiveTable($conn, $sourceTable, $archiveTable, $month) {
    debugLog("=== CREATE ARCHIVE TABLE PROCESS STARTED ===", [
        'source_table' => $sourceTable,
        'archive_table' => $archiveTable,
        'month' => $month
    ]);
    
    $results = [];
    
    try {
        // Check if archive table already exists
        if (archiveTableExists($conn, $archiveTable)) {
            debugLog("Archive table already exists - aborting", $archiveTable);
            return [
                'success' => false,
                'error' => "Archive table {$archiveTable} already exists"
            ];
        }
        
        // Step 1: Create archive table structure (same as source table)
        $createQuery = "CREATE TABLE `{$archiveTable}` LIKE `{$sourceTable}`";
        debugLog("Executing CREATE TABLE query", $createQuery);
        
        if (!$conn->query($createQuery)) {
            $error = $conn->error;
            debugLog("CREATE TABLE query failed", $error);
            throw new Exception("Failed to create archive table structure: " . $error);
        }
        
        debugLog("CREATE TABLE query successful");
        
        // Step 2: Copy ALL data from source to archive table (not filtered by month)
        $copyQuery = "INSERT INTO `{$archiveTable}` SELECT * FROM `{$sourceTable}`";
        debugLog("Executing INSERT/SELECT query", $copyQuery);
        
        // First, let's check total records to copy
        $checkQuery = "SELECT COUNT(*) as record_count FROM `{$sourceTable}`";
        $checkResult = $conn->query($checkQuery);
        $checkRow = $checkResult->fetch_assoc();
        debugLog("Total records to copy", $checkRow['record_count']);
        
        if (!$conn->query($copyQuery)) {
            $error = $conn->error;
            debugLog("INSERT/SELECT query failed", $error);
            
            // If copy fails, drop the created table to avoid orphaned tables
            $dropQuery = "DROP TABLE IF EXISTS `{$archiveTable}`";
            debugLog("Dropping orphaned table due to copy failure", $dropQuery);
            $conn->query($dropQuery);
            
            throw new Exception("Failed to copy data to archive: " . $error);
        }
        
        $copiedRows = $conn->affected_rows;
        debugLog("INSERT/SELECT query successful", [
            'rows_affected' => $copiedRows,
            'expected_rows' => $checkRow['record_count']
        ]);
        
        $results = [
            'success' => true,
            'copied_rows' => $copiedRows,
            'archive_table' => $archiveTable,
            'source_month' => $month
        ];
        
        debugLog("=== CREATE ARCHIVE TABLE PROCESS COMPLETED SUCCESSFULLY ===", $results);
        
    } catch (Exception $e) {
        debugLog("=== CREATE ARCHIVE TABLE PROCESS FAILED ===", $e->getMessage());
        $results = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    
    return $results;
}

/**
 * Detect gaps in a specific month's table
 */
function detectGapsInMonth($conn, $tableName, $month) {
    debugLog("Detecting gaps in month", [
        'table' => $tableName,
        'month' => $month
    ]);
    
    $daysInMonth = getDaysInMonth($month);
    
    // Find the last day that has data
    $lastCompleteDay = 0;
    $gaps = [];
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        // Check if columns exist for this day
        if (!doesDayColumnsExist($conn, $tableName, $day)) {
            debugLog("Columns don't exist for day", $day);
            continue;
        }
        
        $closingCol = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        // Check if this day has any non-zero, non-null closing data
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) as has_data 
            FROM `{$tableName}` 
            WHERE {$closingCol} IS NOT NULL 
            AND {$closingCol} != 0 
            AND STK_MONTH = ?
            LIMIT 1
        ");
        $checkStmt->bind_param("s", $month);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();
        $checkStmt->close();
        
        if ($row['has_data'] > 0) {
            debugLog("Day has data", [
                'day' => $day,
                'has_data' => $row['has_data']
            ]);
            $lastCompleteDay = $day;
        } else {
            debugLog("Day has NO data", [
                'day' => $day,
                'has_data' => $row['has_data']
            ]);
            // If no data but columns exist, and we have a previous complete day, it's a gap
            if ($lastCompleteDay > 0 && $day > $lastCompleteDay) {
                $gaps[] = $day;
                debugLog("Gap detected at day", $day);
            }
        }
    }
    
    $gapInfo = [
        'last_complete_day' => $lastCompleteDay,
        'gaps' => $gaps,
        'days_in_month' => $daysInMonth,
        'month' => $month
    ];
    
    debugLog("Gap detection completed", $gapInfo);
    
    return $gapInfo;
}

/**
 * Auto-populate gaps in a specific month - MISSING FUNCTION ADDED
 */
function autoPopulateMonthGaps($conn, $tableName, $month, $gaps, $lastCompleteDay) {
    debugLog("=== AUTO-POPULATE GAPS PROCESS STARTED ===", [
        'table' => $tableName,
        'month' => $month,
        'gaps' => $gaps,
        'last_complete_day' => $lastCompleteDay
    ]);
    
    $results = [];
    $gapsFilled = 0;
    
    // If no last complete day, we can't populate gaps
    if ($lastCompleteDay === 0) {
        debugLog("Cannot populate gaps - no source data available");
        return [
            'success' => false,
            'error' => 'No source data available to copy from',
            'gaps_filled' => 0
        ];
    }
    
    foreach ($gaps as $day) {
        // Safety check - don't exceed month days
        $monthDays = getDaysInMonth($month);
        if ($day > $monthDays) {
            debugLog("Skipping gap day - exceeds month days", [
                'day' => $day,
                'month_days' => $monthDays
            ]);
            continue;
        }
        
        // Check if target columns exist
        if (!doesDayColumnsExist($conn, $tableName, $day)) {
            debugLog("Skipping gap day - columns don't exist", $day);
            $results[$day] = [
                'success' => false,
                'error' => "Columns for day {$day} do not exist"
            ];
            continue;
        }
        
        // Column names
        $targetOpen = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $targetPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $targetSales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $targetClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        $sourceClosing = "DAY_" . str_pad($lastCompleteDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        debugLog("Populating gap day", [
            'day' => $day,
            'source_column' => $sourceClosing,
            'target_columns' => [$targetOpen, $targetPurchase, $targetSales, $targetClosing]
        ]);
        
        try {
            // Copy data: Opening = Previous day's closing, Purchase/Sales = 0, Closing = Opening
            $updateQuery = "
                UPDATE `{$tableName}` 
                SET 
                    {$targetOpen} = {$sourceClosing},
                    {$targetPurchase} = 0,
                    {$targetSales} = 0,
                    {$targetClosing} = {$sourceClosing}
                WHERE STK_MONTH = ?
                AND {$sourceClosing} IS NOT NULL
            ";
            
            debugLog("Executing gap fill UPDATE query", $updateQuery);
            
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param("s", $month);
            $updateStmt->execute();
            $affectedRows = $updateStmt->affected_rows;
            $updateStmt->close();
            
            if ($affectedRows > 0) {
                $gapsFilled++;
                debugLog("Gap fill successful for day", [
                    'day' => $day,
                    'affected_rows' => $affectedRows
                ]);
            } else {
                debugLog("Gap fill NO ROWS affected for day", $day);
            }
            
            $results[$day] = [
                'success' => true,
                'affected_rows' => $affectedRows,
                'source_day' => $lastCompleteDay
            ];
            
        } catch (Exception $e) {
            debugLog("Gap fill failed for day", [
                'day' => $day,
                'error' => $e->getMessage()
            ]);
            $results[$day] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    $finalResult = [
        'success' => true,
        'gaps_filled' => $gapsFilled,
        'details' => $results
    ];
    
    debugLog("=== AUTO-POPULATE GAPS PROCESS COMPLETED ===", $finalResult);
    
    return $finalResult;
}

/**
 * Get last day's closing stock for a specific month
 */
function getLastDayClosing($conn, $tableName, $month) {
    debugLog("Getting last day closing stock", [
        'table' => $tableName,
        'month' => $month
    ]);
    
    $daysInMonth = getDaysInMonth($month);
    $lastDayCol = "DAY_" . str_pad($daysInMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    // Check if the column exists before querying
    if (!doesDayColumnsExist($conn, $tableName, $daysInMonth)) {
        debugLog("Last day column doesn't exist", $lastDayCol);
        return [];
    }
    
    $stmt = $conn->prepare("
        SELECT ITEM_CODE, {$lastDayCol} as closing_stock 
        FROM `{$tableName}` 
        WHERE STK_MONTH = ? 
        AND {$lastDayCol} IS NOT NULL 
        AND {$lastDayCol} != 0
    ");
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $closingData = [];
    while ($row = $result->fetch_assoc()) {
        $closingData[$row['ITEM_CODE']] = $row['closing_stock'];
    }
    
    $stmt->close();
    
    debugLog("Retrieved closing stock data", [
        'items_count' => count($closingData),
        'sample_data' => array_slice($closingData, 0, 3) // Log first 3 items as sample
    ]);
    
    return $closingData;
}

/**
 * Build dynamic day columns clearing query based on actual table structure and month length
 */
function buildDayColumnsClearingQuery($conn, $tableName, $newMonth) {
    debugLog("Building day columns clearing query for new month", [
        'table' => $tableName,
        'new_month' => $newMonth
    ]);
    
    $clearColumns = [];
    $daysInNewMonth = getDaysInMonth($newMonth);
    
    debugLog("New month days count", $daysInNewMonth);
    
    // Check which day columns actually exist in the table for the new month
    for ($day = 2; $day <= 31; $day++) {
        // Only clear days that exist in the table AND are within the new month's range
        if (doesDayColumnsExist($conn, $tableName, $day)) {
            $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
            $clearColumns[] = "DAY_{$dayPadded}_OPEN = 0";
            $clearColumns[] = "DAY_{$dayPadded}_PURCHASE = 0";
            $clearColumns[] = "DAY_{$dayPadded}_SALES = 0";
            $clearColumns[] = "DAY_{$dayPadded}_CLOSING = 0";
        }
    }
    
    $query = implode(', ', $clearColumns);
    debugLog("Built clearing query", [
        'columns_count' => count($clearColumns),
        'days_in_new_month' => $daysInNewMonth,
        'query' => $query
    ]);
    
    return $query;
}

/**
 * Initialize new month with previous month's closing stock - MONTH-LENGTH AWARE VERSION
 */
function initializeNewMonth($conn, $tableName, $previousMonth, $newMonth) {
    debugLog("=== INITIALIZE NEW MONTH PROCESS STARTED ===", [
        'table' => $tableName,
        'previous_month' => $previousMonth,
        'new_month' => $newMonth,
        'previous_month_days' => getDaysInMonth($previousMonth),
        'new_month_days' => getDaysInMonth($newMonth)
    ]);
    
    $results = [];
    
    try {
        // Get last day's closing stock from previous month
        $closingData = getLastDayClosing($conn, $tableName, $previousMonth);
        
        if (empty($closingData)) {
            debugLog("No closing stock data found - aborting initialization");
            return [
                'success' => false,
                'error' => "No closing stock data found for previous month {$previousMonth}"
            ];
        }
        
        // Build dynamic clearing query based on actual table structure and new month length
        $clearColumnsQuery = buildDayColumnsClearingQuery($conn, $tableName, $newMonth);
        
        debugLog("Starting month initialization update", [
            'items_count' => count($closingData),
            'clear_columns_query' => $clearColumnsQuery,
            'previous_month_days' => getDaysInMonth($previousMonth),
            'new_month_days' => getDaysInMonth($newMonth)
        ]);
        
        // Update existing records in the same table - change STK_MONTH and reset daily data
        $updateCount = 0;
        $errorCount = 0;
        
        foreach ($closingData as $itemCode => $closingStock) {
            $updateQuery = "
                UPDATE `{$tableName}` 
                SET 
                    STK_MONTH = ?,
                    DAY_01_OPEN = ?,
                    DAY_01_PURCHASE = 0,
                    DAY_01_SALES = 0,
                    DAY_01_CLOSING = ?,
                    {$clearColumnsQuery}
                WHERE STK_MONTH = ? 
                AND ITEM_CODE = ?
            ";
            
            debugLog("Executing item update", [
                'item_code' => $itemCode,
                'closing_stock' => $closingStock,
                'query_preview' => substr($updateQuery, 0, 200) . "..."
            ]);
            
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param("sdsss", $newMonth, $closingStock, $closingStock, $previousMonth, $itemCode);
            
            if ($updateStmt->execute()) {
                if ($updateStmt->affected_rows > 0) {
                    $updateCount++;
                }
            } else {
                $errorCount++;
                debugLog("Update failed for item", [
                    'item_code' => $itemCode,
                    'error' => $updateStmt->error
                ]);
            }
            $updateStmt->close();
        }
        
        $results = [
            'success' => true,
            'updated_items' => $updateCount,
            'error_items' => $errorCount,
            'previous_month' => $previousMonth,
            'new_month' => $newMonth,
            'previous_month_days' => getDaysInMonth($previousMonth),
            'new_month_days' => getDaysInMonth($newMonth)
        ];
        
        debugLog("=== INITIALIZE NEW MONTH PROCESS COMPLETED ===", $results);
        
    } catch (Exception $e) {
        debugLog("=== INITIALIZE NEW MONTH PROCESS FAILED ===", $e->getMessage());
        $results = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    
    return $results;
}

/**
 * Check if month transition is needed with gap detection
 */
function checkMonthTransitionWithGaps($conn) {
    $currentCompanyId = $_SESSION['CompID'] ?? 1;
    $currentMonth = getCurrentMonth();
    $previousMonth = getPreviousMonth();
    
    debugLog("Checking month transition needs", [
        'company_id' => $currentCompanyId,
        'current_month' => $currentMonth,
        'previous_month' => $previousMonth,
        'current_month_days' => getDaysInMonth($currentMonth),
        'previous_month_days' => getDaysInMonth($previousMonth)
    ]);
    
    // Define company tables
    $companyTables = [
        '1' => 'tbldailystock_1',
        '2' => 'tbldailystock_2',
        '3' => 'tbldailystock_3'
    ];
    
    $transitionInfo = [];
    
    if (isset($companyTables[$currentCompanyId])) {
        $tableName = $companyTables[$currentCompanyId];
        
        debugLog("Checking table existence", $tableName);
        
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        if ($tableCheck->num_rows > 0) {
            debugLog("Table exists, checking month data");
            
            // Check if current month data exists
            $currentMonthStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ?");
            $currentMonthStmt->bind_param("s", $currentMonth);
            $currentMonthStmt->execute();
            $currentMonthResult = $currentMonthStmt->get_result();
            $currentMonthRow = $currentMonthResult->fetch_assoc();
            $currentMonthStmt->close();
            
            // Check if previous month data exists
            $prevMonthStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ?");
            $prevMonthStmt->bind_param("s", $previousMonth);
            $prevMonthStmt->execute();
            $prevMonthResult = $prevMonthStmt->get_result();
            $prevMonthRow = $prevMonthResult->fetch_assoc();
            $prevMonthStmt->close();
            
            // Check for gaps in previous month
            $previousMonthGaps = [];
            $hasGaps = false;
            if ($prevMonthRow['count'] > 0) {
                debugLog("Previous month has data, checking for gaps");
                $gapInfo = detectGapsInMonth($conn, $tableName, $previousMonth);
                $hasGaps = !empty($gapInfo['gaps']);
                $previousMonthGaps = $gapInfo;
            }
            
            $transitionInfo = [
                'company_id' => $currentCompanyId,
                'table_name' => $tableName,
                'current_month' => $currentMonth,
                'previous_month' => $previousMonth,
                'current_month_exists' => $currentMonthRow['count'] > 0,
                'previous_month_exists' => $prevMonthRow['count'] > 0,
                'has_gaps' => $hasGaps,
                'gap_info' => $previousMonthGaps,
                'needs_transition' => $prevMonthRow['count'] > 0 && !$currentMonthRow['count'] > 0,
                'current_month_days' => getDaysInMonth($currentMonth),
                'previous_month_days' => getDaysInMonth($previousMonth)
            ];
            
            debugLog("Transition check completed", $transitionInfo);
            
        } else {
            debugLog("Table does not exist", $tableName);
        }
    } else {
        debugLog("Company ID not found in tables mapping", $currentCompanyId);
    }
    
    return $transitionInfo;
}

/**
 * Execute complete month transition - MONTH-LENGTH AWARE VERSION
 */
function executeCompleteMonthTransition($conn) {
    debugLog("=== EXECUTE COMPLETE MONTH TRANSITION STARTED ===");
    
    $currentCompanyId = $_SESSION['CompID'] ?? 1;
    $currentMonth = getCurrentMonth();
    $previousMonth = getPreviousMonth();
    
    debugLog("Transition parameters", [
        'company_id' => $currentCompanyId,
        'current_month' => $currentMonth,
        'previous_month' => $previousMonth,
        'current_month_days' => getDaysInMonth($currentMonth),
        'previous_month_days' => getDaysInMonth($previousMonth)
    ]);
    
    // Define company tables
    $companyTables = [
        '1' => 'tbldailystock_1',
        '2' => 'tbldailystock_2',
        '3' => 'tbldailystock_3'
    ];
    
    $results = [];
    
    if (isset($companyTables[$currentCompanyId])) {
        $tableName = $companyTables[$currentCompanyId];
        
        debugLog("Processing transition for table", $tableName);
        
        try {
            // Step 1: Fill gaps in previous month if any
            $gapFillResult = ['success' => true, 'message' => 'No gaps to fill', 'gaps_filled' => 0];
            $transitionInfo = checkMonthTransitionWithGaps($conn);
            
            if ($transitionInfo['has_gaps'] && !empty($transitionInfo['gap_info']['gaps'])) {
                debugLog("Gaps detected, starting gap filling");
                $gapFillResult = autoPopulateMonthGaps(
                    $conn, 
                    $tableName, 
                    $previousMonth, 
                    $transitionInfo['gap_info']['gaps'], 
                    $transitionInfo['gap_info']['last_complete_day']
                );
            } else {
                debugLog("No gaps detected or no gaps to fill");
            }
            
            // Step 2: Create archive table for previous month (MM_YYYY format)
            $archiveTable = $tableName . '_' . getMonthSuffix($previousMonth);
            debugLog("Starting archive creation", [
                'archive_table' => $archiveTable,
                'format' => 'MM_YYYY'
            ]);
            $archiveResult = createArchiveTable($conn, $tableName, $archiveTable, $previousMonth);
            
            // Step 3: Initialize new month with previous month's closing stock
            $initResult = ['success' => false, 'error' => 'Archive creation failed'];
            if ($archiveResult['success']) {
                debugLog("Archive created successfully, starting new month initialization");
                $initResult = initializeNewMonth($conn, $tableName, $previousMonth, $currentMonth);
            } else {
                debugLog("Archive creation failed, skipping initialization");
            }
            
            $results = [
                'company_id' => $currentCompanyId,
                'table_name' => $tableName,
                'gap_fill_result' => $gapFillResult,
                'archive_result' => $archiveResult,
                'init_result' => $initResult,
                'previous_month' => $previousMonth,
                'current_month' => $currentMonth,
                'archive_table_name' => $archiveTable,
                'previous_month_days' => getDaysInMonth($previousMonth),
                'current_month_days' => getDaysInMonth($currentMonth)
            ];
            
            debugLog("=== EXECUTE COMPLETE MONTH TRANSITION COMPLETED ===", $results);
            
        } catch (Exception $e) {
            debugLog("=== EXECUTE COMPLETE MONTH TRANSITION FAILED ===", $e->getMessage());
            $results = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    } else {
        debugLog("Company table not found for ID", $currentCompanyId);
    }
    
    return $results;
}

// Handle complete month transition request
$transitionResults = null;
if (isset($_POST['complete_month_transition']) && $_POST['complete_month_transition'] === '1') {
    debugLog("Month transition form submitted via POST");
    $transitionResults = executeCompleteMonthTransition($conn);
    
    if ($transitionResults && 
        isset($transitionResults['archive_result']['success']) && 
        $transitionResults['archive_result']['success'] && 
        isset($transitionResults['init_result']['success']) && 
        $transitionResults['init_result']['success']) {
        
        $successMsg = "Complete month transition processed successfully! Archive table created: " . $transitionResults['archive_table_name'];
        debugLog("Month transition SUCCESS", $successMsg);
        $_SESSION['transition_message'] = $successMsg;
    } else {
        $errorMsg = "Month transition failed: ";
        if (isset($transitionResults['archive_result']['error'])) {
            $errorMsg .= "Archive: " . $transitionResults['archive_result']['error'];
        }
        if (isset($transitionResults['init_result']['error'])) {
            $errorMsg .= " Init: " . $transitionResults['init_result']['error'];
        }
        debugLog("Month transition FAILED", $errorMsg);
        $_SESSION['transition_message'] = $errorMsg;
    }
}

// Check if month transition is needed
debugLog("Checking if month transition is needed");
$transitionInfo = checkMonthTransitionWithGaps($conn);
debugLog("Month transition needed check result", $transitionInfo);

// Show success message if available
$successMessage = '';
if (isset($_SESSION['transition_message'])) {
    $successMessage = $_SESSION['transition_message'];
    debugLog("Setting success/error message for display", $successMessage);
    unset($_SESSION['transition_message']);
}

// =============================================================================
// EXISTING DASHBOARD STATISTICS LOGIC
// =============================================================================

// Initialize stats array with default values
$stats = [
    'total_items' => 0,
    'total_customers' => 0,
    'total_suppliers' => 0,
    'total_permits' => 0,
    'total_dry_days' => 0,
    'whisky_items' => 0,
    'wine_items' => 0,
    'gin_items' => 0,
    'fermented_beer_items' => 0,
    'mild_beer_items' => 0,
    'total_beer_items' => 0,
    'brandy_items' => 0,
    'vodka_items' => 0,
    'rum_items' => 0,
    'other_items' => 0
];

// Fetch statistics data
try {
    debugLog("Starting dashboard statistics fetch");
    
    // Check database connection
    if(!isset($conn) || !$conn instanceof mysqli) {
        throw new Exception("Database connection not established");
    }

    // Total Items
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['total_items'] = number_format($row['total']);
        $result->free();
    }

    // Total Customers
    $companyId = $_SESSION['CompID'] ?? 0;
    $customerCountQuery = "SELECT COUNT(*) as total_customers FROM tbllheads WHERE REF_CODE = 'CUST' AND CompID = ?";
    $customerCountStmt = $conn->prepare($customerCountQuery);
    $customerCountStmt->bind_param("i", $companyId);
    $customerCountStmt->execute();
    $customerCountResult = $customerCountStmt->get_result();
    $customerCount = $customerCountResult->fetch_assoc();
    $stats['total_customers'] = number_format($customerCount['total_customers']);
    $customerCountStmt->close();

    // Total Suppliers
    $result = $conn->query("SELECT COUNT(DISTINCT CODE) as total FROM tblsupplier WHERE CODE IS NOT NULL");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['total_suppliers'] = number_format($row['total']);
        $result->free();
    }

    // Total Permits (active)
    $currentDate = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tblpermit WHERE P_EXP_DT >= ? AND PRMT_FLAG = 1");
    $stmt->bind_param("s", $currentDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_permits'] = number_format($row['total']);
    $stmt->close();

    // Total Dry Days (current year)
    $currentYear = date('Y');
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbldrydays WHERE YEAR(DDATE) = ?");
    $stmt->bind_param("s", $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_dry_days'] = number_format($row['total']);
    $stmt->close();

    // Whisky Items (CLASS = 'W')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'W'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['whisky_items'] = number_format($row['total']);
        $result->free();
    }

    // Wine Items (CLASS = 'V')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'V'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['wine_items'] = number_format($row['total']);
        $result->free();
    }

    // Gin Items (CLASS = 'G')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'G'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['gin_items'] = number_format($row['total']);
        $result->free();
    }

    // Fermented Beer Items (CLASS = 'F')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'F'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['fermented_beer_items'] = number_format($row['total']);
        $result->free();
    }

    // Mild Beer Items (CLASS = 'M')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'M'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['mild_beer_items'] = number_format($row['total']);
        $result->free();
    }

    // Total Beer Items (F + M)
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS IN ('F', 'M')");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['total_beer_items'] = number_format($row['total']);
        $result->free();
    }

    // Brandy Items (CLASS = 'D')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'D'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['brandy_items'] = number_format($row['total']);
        $result->free();
    }

    // Vodka Items (CLASS = 'K')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'K'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['vodka_items'] = number_format($row['total']);
        $result->free();
    }

    // Rum Items (CLASS = 'R')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'R'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['rum_items'] = number_format($row['total']);
        $result->free();
    }

    // Other Items (CLASS = 'O')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'O'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['other_items'] = number_format($row['total']);
        $result->free();
    }

    debugLog("Dashboard statistics fetch completed successfully");

} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
    debugLog("Dashboard statistics fetch FAILED", $error);
}

debugLog("=== DASHBOARD PAGE RENDERING STARTED ===");
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
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .stat-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        transition: transform 0.3s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
    }
    
    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 15px;
        color: white;
        font-size: 24px;
    }
    
    .stat-info h4 {
        margin: 0;
        font-size: 14px;
        color: #718096;
    }
    
    .stat-info p {
        margin: 5px 0 0;
        font-size: 24px;
        font-weight: bold;
        color: #2D3748;
    }
    
    .transition-alert {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
    }
    
    .btn-transition {
        background: #ff6b6b;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 6px;
        font-weight: bold;
        transition: all 0.3s ease;
        font-size: 16px;
    }
    
    .btn-transition:hover {
        background: #ff5252;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
    }
    
    .success-alert {
        background: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%);
        color: white;
        border: none;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
    }
    
    .error-alert {
        background: linear-gradient(135deg, #ff6b6b 0%, #ee5a52 100%);
        color: white;
        border: none;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
    }

    .gap-days {
        display: inline-block;
        background: rgba(255, 255, 255, 0.2);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 14px;
        margin: 5px 5px 5px 0;
    }
    
    .process-steps {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 15px;
        margin: 10px 0;
    }
    
    .step-item {
        padding: 8px 0;
        border-left: 3px solid #ff6b6b;
        padding-left: 15px;
        margin: 5px 0;
    }
    
    .debug-info {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        padding: 15px;
        margin-top: 20px;
        font-size: 12px;
        color: #6c757d;
    }
    
    .month-info {
        background: #e7f3ff;
        border: 1px solid #b3d9ff;
        border-radius: 5px;
        padding: 10px;
        margin: 10px 0;
        font-size: 14px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <div class="content-area">
      <h3 class="mb-4">Dashboard Overview</h3>
      
      <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
      <?php endif; ?>
      
      <?php if($successMessage): ?>
        <?php if(strpos($successMessage, 'failed') === false): ?>
          <div class="success-alert">
            <i class="fas fa-check-circle"></i> <strong>Success!</strong> <?php echo $successMessage; ?>
          </div>
        <?php else: ?>
          <div class="error-alert">
            <i class="fas fa-exclamation-triangle"></i> <strong>Error!</strong> <?php echo $successMessage; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      
      <?php if($transitionInfo && $transitionInfo['needs_transition']): ?>
        <!-- Complete Month Transition Alert -->
        <div class="transition-alert">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h4 class="mb-0">
                <i class="fas fa-calendar-alt"></i> 
                Complete Month Transition Required
              </h4>
              <div class="mt-2">
                <strong>Previous Month:</strong> <?php echo $transitionInfo['previous_month']; ?> (<?php echo $transitionInfo['previous_month_days']; ?> days) │ 
                <strong>Current Month:</strong> <?php echo $transitionInfo['current_month']; ?> (<?php echo $transitionInfo['current_month_days']; ?> days)
              </div>
            </div>
            <form method="POST" style="display: inline;">
              <button type="submit" name="complete_month_transition" value="1" class="btn-transition">
                <i class="fas fa-sync-alt"></i> Process Complete Transition
              </button>
            </form>
          </div>
          
          <div class="month-info">
            <i class="fas fa-info-circle"></i> 
            <strong>Month Transition Details:</strong> 
            Copying <?php echo $transitionInfo['previous_month_days']; ?> days data from <?php echo $transitionInfo['previous_month']; ?> 
            to initialize <?php echo $transitionInfo['current_month_days']; ?> days for <?php echo $transitionInfo['current_month']; ?>
          </div>
          
          <p class="mb-3">
            The system detected that we've entered a new month but the previous month requires completion and archiving.
          </p>
          
          <div class="process-steps">
            <h6><i class="fas fa-list-ol"></i> Automated Process Steps:</h6>
            
            <?php if($transitionInfo['has_gaps']): ?>
            <div class="step-item">
              <strong>Step 1: Fill Data Gaps</strong>
              <div class="mt-1">
                <small>Missing days in <?php echo $transitionInfo['previous_month']; ?>:</small>
                <?php foreach($transitionInfo['gap_info']['gaps'] as $day): ?>
                  <span class="gap-days">Day <?php echo $day; ?></span>
                <?php endforeach; ?>
                <br>
                <small class="text-warning">Last complete data: Day <?php echo $transitionInfo['gap_info']['last_complete_day']; ?></small>
              </div>
            </div>
            <?php endif; ?>
            
            <div class="step-item">
              <strong>Step 2: Create Archive Table</strong>
              <div class="mt-1">
                <small>Archive: <code><?php echo $transitionInfo['table_name'] . '_' . getMonthSuffix($transitionInfo['previous_month']); ?></code></small>
                <br>
                <small>Format: MM_YYYY (e.g., 09_2025 for September 2025)</small>
              </div>
            </div>
            
            <div class="step-item">
              <strong>Step 3: Initialize New Month</strong>
              <div class="mt-1">
                <small>Carry forward closing stock from Day <?php echo $transitionInfo['previous_month_days']; ?> of <?php echo $transitionInfo['previous_month']; ?> to Day 1 opening of <?php echo $transitionInfo['current_month']; ?></small>
                <br>
                <small>Reset all other day columns to zero for the new month</small>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
      
      <?php if($transitionResults): ?>
        <!-- Transition Results -->
        <div class="alert alert-info">
          <h5><i class="fas fa-tasks"></i> Complete Month Transition Results</h5>
          <div class="mb-2">
            <strong>Company <?php echo $transitionResults['company_id']; ?>:</strong>
            <?php if(isset($transitionResults['archive_result']['success']) && $transitionResults['archive_result']['success'] && isset($transitionResults['init_result']['success']) && $transitionResults['init_result']['success']): ?>
              <span class="text-success">✅ Complete transition successful</span>
            <?php else: ?>
              <span class="text-danger">❌ Transition had issues</span>
            <?php endif; ?>
          </div>
          
          <div class="month-info">
            <strong>Month Details:</strong> 
            <?php echo $transitionResults['previous_month']; ?> (<?php echo $transitionResults['previous_month_days']; ?> days) → 
            <?php echo $transitionResults['current_month']; ?> (<?php echo $transitionResults['current_month_days']; ?> days)
          </div>
          
          <?php if(isset($transitionResults['gap_fill_result'])): ?>
            <div class="mb-1">
              <strong>Gap Filling:</strong> 
              <?php if(isset($transitionResults['gap_fill_result']['success']) && $transitionResults['gap_fill_result']['success']): ?>
                <span class="text-success">✅ Filled <?php echo $transitionResults['gap_fill_result']['gaps_filled'] ?? 0; ?> gaps in <?php echo $transitionResults['previous_month']; ?></span>
              <?php else: ?>
                <span class="text-warning">⚠️ <?php echo $transitionResults['gap_fill_result']['message'] ?? 'No gaps to fill'; ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          
          <?php if(isset($transitionResults['archive_result'])): ?>
            <div class="mb-1">
              <strong>Archive:</strong> 
              <?php if(isset($transitionResults['archive_result']['success']) && $transitionResults['archive_result']['success']): ?>
                <span class="text-success">✅ Created <?php echo $transitionResults['archive_result']['archive_table']; ?> (<?php echo $transitionResults['archive_result']['copied_rows']; ?> records)</span>
              <?php else: ?>
                <span class="text-danger">❌ Failed: <?php echo $transitionResults['archive_result']['error'] ?? 'Unknown error'; ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          
          <?php if(isset($transitionResults['init_result'])): ?>
            <div class="mb-1">
              <strong>Initialization:</strong> 
              <?php if(isset($transitionResults['init_result']['success']) && $transitionResults['init_result']['success']): ?>
                <span class="text-success">✅ Initialized <?php echo $transitionResults['init_result']['new_month']; ?> 
                (<?php echo $transitionResults['init_result']['updated_items']; ?> items updated)</span>
              <?php else: ?>
                <span class="text-danger">❌ Failed: <?php echo $transitionResults['init_result']['error'] ?? 'Unknown error'; ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <!-- Debug Information (Visible for troubleshooting) -->
      <div class="debug-info">
        <strong>Debug Information:</strong> 
        Log file: <code>debug_month_transition.log</code> | 
        Current Month: <code><?php echo getCurrentMonth(); ?> (<?php echo getDaysInMonth(getCurrentMonth()); ?> days)</code> | 
        Previous Month: <code><?php echo getPreviousMonth(); ?> (<?php echo getDaysInMonth(getPreviousMonth()); ?> days)</code> |
        Company ID: <code><?php echo $_SESSION['CompID'] ?? 'Not set'; ?></code>
      </div>

      <!-- Statistics Cards -->
      <div class="stats-grid">
        <!-- Total Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
            <i class="fas fa-box"></i>
          </div>
          <div class="stat-info">
            <h4>TOTAL ITEMS</h4>
            <p><?php echo $stats['total_items']; ?></p>
          </div>
        </div>

        <!-- Total Customers -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-info">
            <h4>TOTAL CUSTOMERS</h4>
            <p><?php echo $stats['total_customers']; ?></p>
          </div>
        </div>

        <!-- Total Suppliers -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
            <i class="fas fa-truck"></i>
          </div>
          <div class="stat-info">
            <h4>TOTAL SUPPLIERS</h4>
            <p><?php echo $stats['total_suppliers']; ?></p>
          </div>
        </div>

        <!-- Total Permits -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
            <i class="fas fa-file-contract"></i>
          </div>
          <div class="stat-info">
            <h4>ACTIVE PERMITS</h4>
            <p><?php echo $stats['total_permits']; ?></p>
          </div>
        </div>

        <!-- Total Dry Days -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
            <i class="fas fa-calendar-times"></i>
          </div>
          <div class="stat-info">
            <h4>DRY DAYS (<?php echo date('Y'); ?>)</h4>
            <p><?php echo $stats['total_dry_days']; ?></p>
          </div>
        </div>

        <!-- Whisky Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);">
            <i class="fas fa-glass-whiskey"></i>
          </div>
          <div class="stat-info">
            <h4>WHISKY ITEMS</h4>
            <p><?php echo $stats['whisky_items']; ?></p>
          </div>
        </div>

        <!-- Wine Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%);">
            <i class="fas fa-wine-glass-alt"></i>
          </div>
          <div class="stat-info">
            <h4>WINE ITEMS</h4>
            <p><?php echo $stats['wine_items']; ?></p>
          </div>
        </div>

        <!-- Gin Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #fad0c4 0%, #ffd1ff 100%);">
            <i class="fas fa-cocktail"></i>
          </div>
          <div class="stat-info">
            <h4>GIN ITEMS</h4>
            <p><?php echo $stats['gin_items']; ?></p>
          </div>
        </div>

        <!-- Fermented Beer Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>FERMENTED BEER</h4>
            <p><?php echo $stats['fermented_beer_items']; ?></p>
          </div>
        </div>

        <!-- Mild Beer Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>MILD BEER</h4>
            <p><?php echo $stats['mild_beer_items']; ?></p>
          </div>
        </div>

        <!-- Total Beer Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%);">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>TOTAL BEER ITEMS</h4>
            <p><?php echo $stats['total_beer_items']; ?></p>
          </div>
        </div>

        <!-- Brandy Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%);">
            <i class="fas fa-wine-bottle"></i>
          </div>
          <div class="stat-info">
            <h4>BRANDY ITEMS</h4>
            <p><?php echo $stats['brandy_items']; ?></p>
          </div>
        </div>

        <!-- Vodka Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);">
            <i class="fas fa-glass-whiskey"></i>
          </div>
          <div class="stat-info">
            <h4>VODKA ITEMS</h4>
            <p><?php echo $stats['vodka_items']; ?></p>
          </div>
        </div>

        <!-- Rum Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #a6c0fe 0%, #f68084 100%);">
            <i class="fas fa-glass-whiskey"></i>
          </div>
          <div class="stat-info">
            <h4>RUM ITEMS</h4>
            <p><?php echo $stats['rum_items']; ?></p>
          </div>
        </div>

        <!-- Other Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #fccb90 0%, #d57eeb 100%);">
            <i class="fas fa-boxes"></i>
          </div>
          <div class="stat-info">
            <h4>OTHER ITEMS</h4>
            <p><?php echo $stats['other_items']; ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-hide success message after 5 seconds
document.addEventListener('DOMContentLoaded', function() {
    const successAlert = document.querySelector('.success-alert');
    if (successAlert) {
        setTimeout(() => {
            successAlert.style.transition = 'opacity 0.5s ease';
            successAlert.style.opacity = '0';
            setTimeout(() => successAlert.remove(), 500);
        }, 5000);
    }
});
</script>
</body>
</html>

<?php
debugLog("=== DASHBOARD PAGE RENDERING COMPLETED ===");
?>