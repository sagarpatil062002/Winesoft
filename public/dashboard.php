<?php
// Increase execution time for the complete process
set_time_limit(300); // 5 minutes
ini_set('max_execution_time', 300);

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

function getMonthSuffix($month) {
    $date = DateTime::createFromFormat('Y-m', $month);
    return $date->format('m_y');
}

function getDaysInMonth($month) {
    return (int)date('t', strtotime($month . '-01'));
}

function doesDayColumnsExist($conn, $tableName, $day) {
    if ($day > 31) return false;
    
    $columnPrefix = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT);
    $openCol = $columnPrefix . "_OPEN";
    
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
    
    return $row['column_exists'] > 0;
}

function archiveTableExists($conn, $tableName) {
    $result = $conn->query("SHOW TABLES LIKE '{$tableName}'");
    $exists = $result->num_rows > 0;
    $result->free();
    
    return $exists;
}

function createArchiveTable($conn, $sourceTable, $archiveTable, $month) {
    $results = [];
    
    try {
        // Check if archive table already exists
        if (archiveTableExists($conn, $archiveTable)) {
            return [
                'success' => false,
                'error' => "Archive table {$archiveTable} already exists"
            ];
        }
        
        // Step 1: Create archive table structure (same as source table)
        $createQuery = "CREATE TABLE `{$archiveTable}` LIKE `{$sourceTable}`";
        
        if (!$conn->query($createQuery)) {
            $error = $conn->error;
            throw new Exception("Failed to create archive table structure: " . $error);
        }
        
        // Step 2: Copy ALL data from source to archive table (not filtered by month)
        $copyQuery = "INSERT INTO `{$archiveTable}` SELECT * FROM `{$sourceTable}`";
        
        // First, let's check total records to copy
        $checkQuery = "SELECT COUNT(*) as record_count FROM `{$sourceTable}`";
        $checkResult = $conn->query($checkQuery);
        $checkRow = $checkResult->fetch_assoc();
        
        if (!$conn->query($copyQuery)) {
            $error = $conn->error;
            
            // If copy fails, drop the created table to avoid orphaned tables
            $dropQuery = "DROP TABLE IF EXISTS `{$archiveTable}`";
            $conn->query($dropQuery);
            
            throw new Exception("Failed to copy data to archive: " . $error);
        }
        
        $copiedRows = $conn->affected_rows;
        
        $results = [
            'success' => true,
            'copied_rows' => $copiedRows,
            'archive_table' => $archiveTable,
            'source_month' => $month
        ];
        
    } catch (Exception $e) {
        $results = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    
    return $results;
}

function detectGapsInMonth($conn, $tableName, $month, $maxDay = null) {
    $daysInMonth = $maxDay ?: getDaysInMonth($month);
    
    // Find the last day that has data
    $lastCompleteDay = 0;
    $gaps = [];
    
    for ($day = 1; $day <= $daysInMonth; $day++) {
        // Check if columns exist for this day
        if (!doesDayColumnsExist($conn, $tableName, $day)) {
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
            $lastCompleteDay = $day;
        } else {
            // If no data but columns exist, and we have a previous complete day, it's a gap
            if ($lastCompleteDay > 0 && $day > $lastCompleteDay) {
                $gaps[] = $day;
            }
        }
    }
    
    $gapInfo = [
        'last_complete_day' => $lastCompleteDay,
        'gaps' => $gaps,
        'days_in_month' => $daysInMonth,
        'month' => $month,
        'max_day_checked' => $maxDay
    ];
    
    return $gapInfo;
}

function autoPopulateMonthGaps($conn, $tableName, $month, $gaps, $lastCompleteDay) {
    $results = [];
    $gapsFilled = 0;
    
    // If no last complete day, we can't populate gaps
    if ($lastCompleteDay === 0) {
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
            continue;
        }
        
        // Check if target columns exist
        if (!doesDayColumnsExist($conn, $tableName, $day)) {
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
            
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param("s", $month);
            $updateStmt->execute();
            $affectedRows = $updateStmt->affected_rows;
            $updateStmt->close();
            
            if ($affectedRows > 0) {
                $gapsFilled++;
                // Update last complete day for next iteration
                $lastCompleteDay = $day;
            }
            
            $results[$day] = [
                'success' => true,
                'affected_rows' => $affectedRows,
                'source_day' => $lastCompleteDay
            ];
            
        } catch (Exception $e) {
            $results[$day] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    $finalResult = [
        'success' => true,
        'gaps_filled' => $gapsFilled,
        'last_complete_day' => $lastCompleteDay,
        'details' => $results
    ];
    
    return $finalResult;
}

function getLastDayClosing($conn, $tableName, $month) {
    $daysInMonth = getDaysInMonth($month);
    $lastDayCol = "DAY_" . str_pad($daysInMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    // Check if the column exists before querying
    if (!doesDayColumnsExist($conn, $tableName, $daysInMonth)) {
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
    
    return $closingData;
}

function buildDayColumnsClearingQuery($conn, $tableName, $newMonth) {
    $clearColumns = [];
    $daysInNewMonth = getDaysInMonth($newMonth);
    
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
    
    return $query;
}

function initializeNewMonth($conn, $tableName, $previousMonth, $newMonth) {
    $results = [];
    
    try {
        // Get last day's closing stock from previous month
        $closingData = getLastDayClosing($conn, $tableName, $previousMonth);
        
        if (empty($closingData)) {
            return [
                'success' => false,
                'error' => "No closing stock data found for previous month {$previousMonth}"
            ];
        }
        
        // Build dynamic clearing query based on actual table structure and new month length
        $clearColumnsQuery = buildDayColumnsClearingQuery($conn, $tableName, $newMonth);
        
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
            
            $updateStmt = $conn->prepare($updateQuery);
            $updateStmt->bind_param("sdsss", $newMonth, $closingStock, $closingStock, $previousMonth, $itemCode);
            
            if ($updateStmt->execute()) {
                if ($updateStmt->affected_rows > 0) {
                    $updateCount++;
                }
            } else {
                $errorCount++;
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
        
    } catch (Exception $e) {
        $results = [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
    
    return $results;
}

function ensureDay1Data($conn, $tableName, $month) {
    try {
        // Check if we have any records for this month
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ?");
        $checkStmt->bind_param("s", $month);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();
        $checkStmt->close();
        
        if ($row['count'] > 0) {
            // Check if Day 1 data exists
            $day1CheckStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ? AND DAY_01_CLOSING IS NOT NULL AND DAY_01_CLOSING != 0");
            $day1CheckStmt->bind_param("s", $month);
            $day1CheckStmt->execute();
            $day1Result = $day1CheckStmt->get_result();
            $day1Row = $day1Result->fetch_assoc();
            $day1CheckStmt->close();
            
            if ($day1Row['count'] === 0) {
                // If no Day 1 data, we need to set opening = closing for day 1
                $updateStmt = $conn->prepare("
                    UPDATE `{$tableName}` 
                    SET DAY_01_CLOSING = DAY_01_OPEN 
                    WHERE STK_MONTH = ?
                    AND DAY_01_OPEN IS NOT NULL 
                    AND DAY_01_OPEN != 0
                ");
                $updateStmt->bind_param("s", $month);
                $updateStmt->execute();
                $affectedRows = $updateStmt->affected_rows;
                $updateStmt->close();
                
                return [
                    'success' => true,
                    'affected_rows' => $affectedRows,
                    'action' => 'ensured_day1_data'
                ];
            }
        }
        
        return [
            'success' => true,
            'action' => 'no_action_needed'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function checkMonthTransitionWithGaps($conn) {
    $currentCompanyId = $_SESSION['CompID'] ?? 1;
    $currentMonth = getCurrentMonth();
    $previousMonth = getPreviousMonth();
    $currentDay = getCurrentDay();
    
    // Define company tables
    $companyTables = [
        '1' => 'tbldailystock_1',
        '2' => 'tbldailystock_2',
        '3' => 'tbldailystock_3'
    ];
    
    $transitionInfo = [];
    
    if (isset($companyTables[$currentCompanyId])) {
        $tableName = $companyTables[$currentCompanyId];
        
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        if ($tableCheck->num_rows > 0) {
            
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
            $hasPreviousGaps = false;
            if ($prevMonthRow['count'] > 0) {
                $gapInfo = detectGapsInMonth($conn, $tableName, $previousMonth);
                $hasPreviousGaps = !empty($gapInfo['gaps']);
                $previousMonthGaps = $gapInfo;
            }
            
            // Check for gaps in current month (only if current month exists)
            $currentMonthGaps = [];
            $hasCurrentGaps = false;
            if ($currentMonthRow['count'] > 0) {
                $currentGapInfo = detectGapsInMonth($conn, $tableName, $currentMonth, $currentDay);
                $hasCurrentGaps = !empty($currentGapInfo['gaps']);
                $currentMonthGaps = $currentGapInfo;
            }
            
            $transitionInfo = [
                'company_id' => $currentCompanyId,
                'table_name' => $tableName,
                'current_month' => $currentMonth,
                'previous_month' => $previousMonth,
                'current_day' => $currentDay,
                'current_date' => getCurrentDate(),
                'current_month_exists' => $currentMonthRow['count'] > 0,
                'previous_month_exists' => $prevMonthRow['count'] > 0,
                'has_previous_gaps' => $hasPreviousGaps,
                'has_current_gaps' => $hasCurrentGaps,
                'previous_gap_info' => $previousMonthGaps,
                'current_gap_info' => $currentMonthGaps,
                'needs_transition' => $prevMonthRow['count'] > 0 && !$currentMonthRow['count'] > 0,
                'needs_current_gap_fill' => $hasCurrentGaps,
                'current_month_days' => getDaysInMonth($currentMonth),
                'previous_month_days' => getDaysInMonth($previousMonth)
            ];
            
        }
    }
    
    return $transitionInfo;
}

function executeCompleteMonthTransition($conn) {
    $currentCompanyId = $_SESSION['CompID'] ?? 1;
    $currentMonth = getCurrentMonth();
    $previousMonth = getPreviousMonth();
    $currentDay = getCurrentDay();
    
    // Define company tables
    $companyTables = [
        '1' => 'tbldailystock_1',
        '2' => 'tbldailystock_2',
        '3' => 'tbldailystock_3'
    ];
    
    $results = [];
    
    if (isset($companyTables[$currentCompanyId])) {
        $tableName = $companyTables[$currentCompanyId];
        
        try {
            // STEP 1: Fill gaps in previous month if any
            $previousGapFillResult = ['success' => true, 'message' => 'No previous month gaps to fill', 'gaps_filled' => 0];
            $transitionInfo = checkMonthTransitionWithGaps($conn);
            
            if ($transitionInfo['has_previous_gaps'] && !empty($transitionInfo['previous_gap_info']['gaps'])) {
                $previousGapFillResult = autoPopulateMonthGaps(
                    $conn, 
                    $tableName, 
                    $previousMonth, 
                    $transitionInfo['previous_gap_info']['gaps'], 
                    $transitionInfo['previous_gap_info']['last_complete_day']
                );
            }
            
            // STEP 2: Create archive table for previous month (MM_YYYY format)
            $archiveTable = $tableName . '_' . getMonthSuffix($previousMonth);
            $archiveResult = createArchiveTable($conn, $tableName, $archiveTable, $previousMonth);
            
            // STEP 3: Initialize new month with previous month's closing stock
            $initResult = ['success' => false, 'error' => 'Archive creation failed'];
            if ($archiveResult['success']) {
                $initResult = initializeNewMonth($conn, $tableName, $previousMonth, $currentMonth);
            }
            
            // STEP 4: Fill gaps in current month if any (FIXED VERSION)
            $currentGapFillResult = ['success' => true, 'message' => 'No current month gaps to fill', 'gaps_filled' => 0];
            
            // Force re-check for current month gaps regardless of initialization result
            // Wait a moment to ensure data is committed
            usleep(500000); // 0.5 second delay
            
            $currentGapInfo = detectGapsInMonth($conn, $tableName, $currentMonth, $currentDay);
            
            if (!empty($currentGapInfo['gaps']) && $currentGapInfo['last_complete_day'] > 0) {
                $currentGapFillResult = autoPopulateMonthGaps(
                    $conn, 
                    $tableName, 
                    $currentMonth, 
                    $currentGapInfo['gaps'], 
                    $currentGapInfo['last_complete_day']
                );
            } else if (!empty($currentGapInfo['gaps']) && $currentGapInfo['last_complete_day'] === 0) {
                // If no last complete day but we have gaps starting from day 1,
                // we need to handle this specially
                $firstGapDay = min($currentGapInfo['gaps']);
                if ($firstGapDay === 1) {
                    // For day 1 gaps, we need to ensure day 1 data exists
                    $ensureDay1Result = ensureDay1Data($conn, $tableName, $currentMonth);
                    if ($ensureDay1Result['success']) {
                        // Re-check gaps after ensuring day 1 data
                        usleep(300000); // 0.3 second delay
                        $currentGapInfo = detectGapsInMonth($conn, $tableName, $currentMonth, $currentDay);
                        if (!empty($currentGapInfo['gaps']) && $currentGapInfo['last_complete_day'] > 0) {
                            $currentGapFillResult = autoPopulateMonthGaps(
                                $conn, 
                                $tableName, 
                                $currentMonth, 
                                $currentGapInfo['gaps'], 
                                $currentGapInfo['last_complete_day']
                            );
                        }
                    }
                }
            }
            
            $results = [
                'company_id' => $currentCompanyId,
                'table_name' => $tableName,
                'previous_gap_fill_result' => $previousGapFillResult,
                'archive_result' => $archiveResult,
                'init_result' => $initResult,
                'current_gap_fill_result' => $currentGapFillResult,
                'current_gap_info' => $currentGapInfo, // Added for debugging
                'previous_month' => $previousMonth,
                'current_month' => $currentMonth,
                'current_day' => $currentDay,
                'archive_table_name' => $archiveTable,
                'previous_month_days' => getDaysInMonth($previousMonth),
                'current_month_days' => getDaysInMonth($currentMonth),
                'complete_process' => true
            ];
            
        } catch (Exception $e) {
            $results = [
                'success' => false,
                'error' => $e->getMessage(),
                'complete_process' => false
            ];
        }
    }
    
    return $results;
}

// Handle complete month transition request
$transitionResults = null;
if (isset($_POST['complete_month_transition']) && $_POST['complete_month_transition'] === '1') {
    error_log("Month transition process started by user: " . ($_SESSION['user_id'] ?? 'unknown'));
    
    try {
        $transitionResults = executeCompleteMonthTransition($conn);
        error_log("Month transition results: " . json_encode($transitionResults));
        
        if ($transitionResults && isset($transitionResults['complete_process']) && $transitionResults['complete_process']) {
            
            $successMsg = "Complete month transition processed successfully! ";
            $successMsg .= "Archive table created: " . $transitionResults['archive_table_name'];
            
            // Add gap filling details
            if (isset($transitionResults['previous_gap_fill_result']['gaps_filled']) && $transitionResults['previous_gap_fill_result']['gaps_filled'] > 0) {
                $successMsg .= " | Previous month gaps filled: " . $transitionResults['previous_gap_fill_result']['gaps_filled'];
            }
            
            if (isset($transitionResults['current_gap_fill_result']['gaps_filled']) && $transitionResults['current_gap_fill_result']['gaps_filled'] > 0) {
                $successMsg .= " | Current month gaps filled: " . $transitionResults['current_gap_fill_result']['gaps_filled'];
            }
            
            $_SESSION['transition_message'] = $successMsg;
            $_SESSION['message_type'] = 'success';
            
        } else {
            $errorMsg = "Month transition failed: ";
            
            if (isset($transitionResults['error'])) {
                $errorMsg .= $transitionResults['error'];
            } else if (isset($transitionResults['archive_result']['error'])) {
                $errorMsg .= "Archive Error: " . $transitionResults['archive_result']['error'];
            } else if (isset($transitionResults['init_result']['error'])) {
                $errorMsg .= "Init Error: " . $transitionResults['init_result']['error'];
            } else {
                $errorMsg .= "Unknown error occurred";
            }
            
            $_SESSION['transition_message'] = $errorMsg;
            $_SESSION['message_type'] = 'error';
        }
    } catch (Exception $e) {
        error_log("Month transition exception: " . $e->getMessage());
        $_SESSION['transition_message'] = "System error: " . $e->getMessage();
        $_SESSION['message_type'] = 'error';
    }
    
    // Redirect to prevent form resubmission
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Check if month transition is needed
$transitionInfo = checkMonthTransitionWithGaps($conn);

// Show success message if available
$successMessage = '';
$messageType = '';
if (isset($_SESSION['transition_message'])) {
    $successMessage = $_SESSION['transition_message'];
    $messageType = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['transition_message']);
    unset($_SESSION['message_type']);
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

} catch (Exception $e) {
    $error = "Database error: " . $e->getMessage();
}
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
    /* Update stats grid to 4 columns */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 20px;
      margin-top: 20px;
    }
    
    /* Responsive adjustments */
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
  </style>
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
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
        <?php if($messageType === 'success'): ?>
          <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <strong>Success!</strong> <?php echo $successMessage; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php else: ?>
          <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <strong>Error!</strong> <?php echo $successMessage; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>
        <?php endif; ?>
      <?php endif; ?>
      
      <?php if($transitionInfo && ($transitionInfo['needs_transition'] || $transitionInfo['needs_current_gap_fill'])): ?>
        
        
        <!-- Complete Month Transition Alert -->
        <div class="card transition-alert">
          <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <h4 class="mb-0">
                  <i class="fas fa-calendar-alt"></i> 
                  Complete Month Transition Required
                </h4>
                <div class="mt-2">
                  <strong>Previous Month:</strong> <?php echo $transitionInfo['previous_month']; ?> (<?php echo $transitionInfo['previous_month_days']; ?> days) │ 
                  <strong>Current Month:</strong> <?php echo $transitionInfo['current_month']; ?> (<?php echo $transitionInfo['current_month_days']; ?> days) │
                  <strong>Today:</strong> <?php echo $transitionInfo['current_date']; ?> (Day <?php echo $transitionInfo['current_day']; ?>)
                </div>
              </div>
              <form method="POST" id="transitionForm" style="display: inline;">
                <input type="hidden" name="complete_month_transition" value="1">
                <button type="submit" class="btn btn-primary btn-transition">
                  <i class="fas fa-sync-alt"></i> Process Complete Transition
                </button>
              </form>
            </div>
            
            <div class="month-info">
              <i class="fas fa-info-circle"></i> 
              <strong>Improved Month Transition Process:</strong> 
              Complete previous month, archive it, initialize current month, and fill gaps up to today.
            </div>
            
            <p class="mb-3">
              The system detected that we've entered a new month and there are data gaps that need to be filled automatically.
            </p>
            
            <div class="process-steps">
              <h6><i class="fas fa-list-ol"></i> Automated Process Steps:</h6>
              
              <?php if($transitionInfo['has_previous_gaps']): ?>
              <div class="step-item">
                <strong>Step 1: Fill Previous Month Gaps</strong>
                <div class="mt-1">
                  <small>Missing days in <?php echo $transitionInfo['previous_month']; ?>:</small>
                  <?php foreach($transitionInfo['previous_gap_info']['gaps'] as $day): ?>
                    <span class="badge bg-warning gap-days">Day <?php echo $day; ?></span>
                  <?php endforeach; ?>
                  <br>
                  <small class="text-warning">Last complete data: Day <?php echo $transitionInfo['previous_gap_info']['last_complete_day']; ?></small>
                </div>
              </div>
              <?php endif; ?>
              
              <div class="step-item">
                <strong>Step 2: Create Archive Table</strong>
                <div class="mt-1">
                  <small>Archive: <code><?php echo $transitionInfo['table_name'] . '_' . getMonthSuffix($transitionInfo['previous_month']); ?></code></small>
                  <br>
                  <small>Format: MM_YY (e.g., 09_25 for September 2025)</small>
                </div>
              </div>
              
              <div class="step-item">
                <strong>Step 3: Initialize New Month</strong>
                <div class="mt-1">
                  <small>Copy closing stock from <?php echo $transitionInfo['previous_month']; ?> to opening stock for <?php echo $transitionInfo['current_month']; ?></small>
                  <br>
                  <small>Reset daily purchase/sales data for the new month</small>
                </div>
              </div>
              
              <?php if($transitionInfo['has_current_gaps']): ?>
              <div class="step-item">
                <strong>Step 4: Fill Current Month Gaps</strong>
                <div class="mt-1">
                  <small>Missing days in <?php echo $transitionInfo['current_month']; ?> up to today (Day <?php echo $transitionInfo['current_day']; ?>):</small>
                  <?php foreach($transitionInfo['current_gap_info']['gaps'] as $day): ?>
                    <span class="badge bg-info gap-days">Day <?php echo $day; ?></span>
                  <?php endforeach; ?>
                  <br>
                  <small class="text-info">Data will be filled from Day 1 up to Day <?php echo $transitionInfo['current_day']; ?></small>
                </div>
              </div>
              <?php endif; ?>
            </div>
            
            <div class="mt-3 p-2 bg-light rounded">
              <small><i class="fas fa-clock"></i> <strong>Note:</strong> This process may take several minutes. Maximum execution time has been increased to 5 minutes.</small>
            </div>
          </div>
        </div>
      <?php else: ?>
        <!-- Show message when no transition is needed - NOW HIDDEN BY DEFAULT -->
        <!-- Remove or comment out this section to hide the "No Month Transition Required" message -->
        <!--
        <div class="card bg-white text-blue" style="display: none;">
          <div class="card-body text-center">
            <i class="fas fa-check-circle fa-2x mb-2"></i>
            <h5>No Month Transition Required</h5>
            <p class="mb-0">Your data is up to date for the current month.</p>
          </div>
        </div>
        -->
      <?php endif; ?>

      <!-- Statistics Grid - 4 PER ROW WITH SIMPLE COLORS -->
      <div class="stats-grid">
        <!-- Total Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: var(--primary-color);">
            <i class="fas fa-box"></i>
          </div>
          <div class="stat-info">
            <h4>TOTAL ITEMS</h4>
            <p><?php echo $stats['total_items']; ?></p>
          </div>
        </div>

        <!-- Total Customers -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #f5576c;">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-info">
            <h4>TOTAL CUSTOMERS</h4>
            <p><?php echo $stats['total_customers']; ?></p>
          </div>
        </div>

        <!-- Total Suppliers -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #4facfe;">
            <i class="fas fa-truck"></i>
          </div>
          <div class="stat-info">
            <h4>TOTAL SUPPLIERS</h4>
            <p><?php echo $stats['total_suppliers']; ?></p>
          </div>
        </div>

        <!-- Total Permits -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #43e97b;">
            <i class="fas fa-id-card"></i>
          </div>
          <div class="stat-info">
            <h4>ACTIVE PERMITS</h4>
            <p><?php echo $stats['total_permits']; ?></p>
          </div>
        </div>

        <!-- Total Dry Days -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #fa709a;">
            <i class="fas fa-calendar-times"></i>
          </div>
          <div class="stat-info">
            <h4>DRY DAYS (<?php echo date('Y'); ?>)</h4>
            <p><?php echo $stats['total_dry_days']; ?></p>
          </div>
        </div>

        <!-- Whisky Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #ff9a9e;">
            <i class="fas fa-glass-whiskey"></i>
          </div>
          <div class="stat-info">
            <h4>WHISKY ITEMS</h4>
            <p><?php echo $stats['whisky_items']; ?></p>
          </div>
        </div>

        <!-- Wine Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #a18cd1;">
            <i class="fas fa-wine-glass"></i>
          </div>
          <div class="stat-info">
            <h4>WINE ITEMS</h4>
            <p><?php echo $stats['wine_items']; ?></p>
          </div>
        </div>

        <!-- Gin Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #fad0c4;">
            <i class="fas fa-cocktail"></i>
          </div>
          <div class="stat-info">
            <h4>GIN ITEMS</h4>
            <p><?php echo $stats['gin_items']; ?></p>
          </div>
        </div>

        <!-- Fermented Beer -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #ffecd2;">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>FERMENTED BEER</h4>
            <p><?php echo $stats['fermented_beer_items']; ?></p>
          </div>
        </div>

        <!-- Mild Beer -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #a1c4fd;">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>MILD BEER</h4>
            <p><?php echo $stats['mild_beer_items']; ?></p>
          </div>
        </div>

        <!-- Total Beer -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #d4fc79;">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>TOTAL BEER ITEMS</h4>
            <p><?php echo $stats['total_beer_items']; ?></p>
          </div>
        </div>

        <!-- Brandy Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #ff9a9e;">
            <i class="fas fa-wine-bottle"></i>
          </div>
          <div class="stat-info">
            <h4>BRANDY ITEMS</h4>
            <p><?php echo $stats['brandy_items']; ?></p>
          </div>
        </div>

        <!-- Vodka Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #a18cd1;">
            <i class="fas fa-glass-whiskey"></i>
          </div>
          <div class="stat-info">
            <h4>VODKA ITEMS</h4>
            <p><?php echo $stats['vodka_items']; ?></p>
          </div>
        </div>

        <!-- Rum Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #fad0c4;">
            <i class="fas fa-glass-whiskey"></i>
          </div>
          <div class="stat-info">
            <h4>RUM ITEMS</h4>
            <p><?php echo $stats['rum_items']; ?></p>
          </div>
        </div>

        <!-- Other Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: #ffecd2;">
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
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {
    // Auto-hide alerts after 8 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 8000);
    
    // Show processing message when transition button is clicked
    $('#transitionForm').on('submit', function(e) {
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');
        
        // Add a small delay to show the loading state
        setTimeout(function() {
            // Form will submit normally
        }, 100);
    });
    
    // Check if there's a message to show
    <?php if($successMessage): ?>
        // Scroll to the top to show the message
        window.scrollTo(0, 0);
    <?php endif; ?>
});
</script>
</body>
</html>