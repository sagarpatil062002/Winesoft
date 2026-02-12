<?php
set_time_limit(300);
ini_set('max_execution_time', 300);

session_start();

if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";
require_once 'license_functions.php';

$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);

// =============================================================================
// MONTH TRANSITION & GAP FILLING FUNCTIONS - IMPROVED VERSION WITH TABLE CREATION
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

function getMonthSuffix($month) {
    $date = DateTime::createFromFormat('Y-m', $month);
    return $date->format('m_y');
}

function getDaysInMonth($month) {
    return (int)date('t', strtotime($month . '-01'));
}

// =============================================================================
// TABLE MANAGEMENT FUNCTIONS
// =============================================================================

function ensureMainTableExists($conn, $tableName) {
    try {
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        if ($tableCheck->num_rows > 0) {
            return [
                'success' => true,
                'action' => 'table_already_exists',
                'table_name' => $tableName
            ];
        }
        
        error_log("Main table {$tableName} does not exist. Attempting to create...");
        
        // Find a template table to copy structure from
        $templateTable = null;
        
        // First try: Find most recent archive table for this company
        $archiveCheck = $conn->query("SHOW TABLES LIKE '{$tableName}_%'");
        if ($archiveCheck->num_rows > 0) {
            $tables = [];
            while ($row = $archiveCheck->fetch_array()) {
                $tables[] = $row[0];
            }
            sort($tables);
            $templateTable = end($tables);
            error_log("Found archive template: {$templateTable}");
        }
        
        // Second try: Use another company's table as template
        if (!$templateTable) {
            $companyTables = [
                '1' => 'tbldailystock_1',
                '2' => 'tbldailystock_2',
                '3' => 'tbldailystock_3'
            ];
            
            foreach ($companyTables as $id => $table) {
                if ($table !== $tableName) {
                    $check = $conn->query("SHOW TABLES LIKE '{$table}'");
                    if ($check->num_rows > 0) {
                        $templateTable = $table;
                        error_log("Found other company template: {$templateTable}");
                        break;
                    }
                }
            }
        }
        
        // If we found a template, create the table
        if ($templateTable) {
            $createQuery = "CREATE TABLE `{$tableName}` LIKE `{$templateTable}`";
            if ($conn->query($createQuery)) {
                error_log("Table {$tableName} created successfully from template {$templateTable}");
                return [
                    'success' => true,
                    'action' => 'table_created_from_template',
                    'table_name' => $tableName,
                    'template' => $templateTable
                ];
            } else {
                throw new Exception("Failed to create table from template: " . $conn->error);
            }
        }
        
        // Last resort: Create table from hardcoded structure (simplified version)
        $createQuery = "
            CREATE TABLE `{$tableName}` (
                `STK_MONTH` varchar(10) NOT NULL,
                `ITEM_CODE` varchar(50) NOT NULL,
                `ITEM_NAME` varchar(255) DEFAULT NULL,
                `CLASS` varchar(10) DEFAULT NULL,
                `GROUP` varchar(10) DEFAULT NULL,
                `DAY_01_OPEN` decimal(15,2) DEFAULT 0.00,
                `DAY_01_PURCHASE` decimal(15,2) DEFAULT 0.00,
                `DAY_01_SALES` decimal(15,2) DEFAULT 0.00,
                `DAY_01_CLOSING` decimal(15,2) DEFAULT 0.00,
                `DAY_02_OPEN` decimal(15,2) DEFAULT 0.00,
                `DAY_02_PURCHASE` decimal(15,2) DEFAULT 0.00,
                `DAY_02_SALES` decimal(15,2) DEFAULT 0.00,
                `DAY_02_CLOSING` decimal(15,2) DEFAULT 0.00,
                `DAY_03_OPEN` decimal(15,2) DEFAULT 0.00,
                `DAY_03_PURCHASE` decimal(15,2) DEFAULT 0.00,
                `DAY_03_SALES` decimal(15,2) DEFAULT 0.00,
                `DAY_03_CLOSING` decimal(15,2) DEFAULT 0.00,
                PRIMARY KEY (`STK_MONTH`, `ITEM_CODE`),
                KEY `idx_item_code` (`ITEM_CODE`),
                KEY `idx_month` (`STK_MONTH`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        if ($conn->query($createQuery)) {
            error_log("Table {$tableName} created from scratch");
            return [
                'success' => true,
                'action' => 'table_created_from_scratch',
                'table_name' => $tableName
            ];
        } else {
            throw new Exception("Failed to create table from scratch: " . $conn->error);
        }
        
    } catch (Exception $e) {
        error_log("Table creation failed: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'action' => 'table_creation_failed'
        ];
    }
}

function restorePreviousMonthFromArchive($conn, $tableName, $previousMonth) {
    try {
        $archiveTable = $tableName . '_' . getMonthSuffix($previousMonth);
        
        // Check if archive exists
        $archiveCheck = $conn->query("SHOW TABLES LIKE '{$archiveTable}'");
        if ($archiveCheck->num_rows == 0) {
            return [
                'success' => false,
                'error' => "Archive table {$archiveTable} does not exist",
                'archive_table' => $archiveTable
            ];
        }
        
        // Check if we already have data for previous month
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ?");
        $checkStmt->bind_param("s", $previousMonth);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();
        $checkStmt->close();
        
        if ($row['count'] > 0) {
            return [
                'success' => true,
                'action' => 'data_already_exists',
                'archive_table' => $archiveTable,
                'record_count' => $row['count']
            ];
        }
        
        // Copy data from archive to main table
        $copyQuery = "INSERT INTO `{$tableName}` SELECT * FROM `{$archiveTable}`";
        if ($conn->query($copyQuery)) {
            $copiedRows = $conn->affected_rows;
            error_log("Restored {$copiedRows} records from {$archiveTable} to {$tableName}");
            return [
                'success' => true,
                'action' => 'restored_from_archive',
                'archive_table' => $archiveTable,
                'record_count' => $copiedRows
            ];
        } else {
            throw new Exception("Failed to restore from archive: " . $conn->error);
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'archive_table' => $archiveTable
        ];
    }
}

// =============================================================================
// DAY COLUMN FUNCTIONS
// =============================================================================

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

// =============================================================================
// ARCHIVE FUNCTIONS
// =============================================================================

function createArchiveTable($conn, $sourceTable, $archiveTable, $month) {
    try {
        if (archiveTableExists($conn, $archiveTable)) {
            return [
                'success' => false,
                'error' => "Archive table {$archiveTable} already exists"
            ];
        }
        
        $createQuery = "CREATE TABLE `{$archiveTable}` LIKE `{$sourceTable}`";
        if (!$conn->query($createQuery)) {
            throw new Exception("Failed to create archive table structure: " . $conn->error);
        }
        
        $copyQuery = "INSERT INTO `{$archiveTable}` SELECT * FROM `{$sourceTable}`";
        if (!$conn->query($copyQuery)) {
            $dropQuery = "DROP TABLE IF EXISTS `{$archiveTable}`";
            $conn->query($dropQuery);
            throw new Exception("Failed to copy data to archive: " . $conn->error);
        }
        
        $copiedRows = $conn->affected_rows;
        
        return [
            'success' => true,
            'copied_rows' => $copiedRows,
            'archive_table' => $archiveTable,
            'source_month' => $month
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// =============================================================================
// GAP DETECTION AND FILLING FUNCTIONS
// =============================================================================

function getItemsWithOpeningBalance($conn, $tableName, $month, $day) {
    $items = [];
    
    if ($day < 1 || $day > 31) {
        return $items;
    }
    
    $closingCol = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    if (!doesDayColumnsExist($conn, $tableName, $day)) {
        return $items;
    }
    
    $query = "
        SELECT ITEM_CODE, {$closingCol} as opening_balance 
        FROM `{$tableName}` 
        WHERE STK_MONTH = ? 
        AND {$closingCol} IS NOT NULL 
        AND {$closingCol} != 0
    ";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return $items;
    }
    
    $stmt->bind_param("s", $month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $items[$row['ITEM_CODE']] = $row['opening_balance'];
    }
    
    $stmt->close();
    
    return $items;
}

function detectGapsInMonth($conn, $tableName, $month, $maxDay = null) {
    $daysInMonth = getDaysInMonth($month);
    $currentDay = getCurrentDay();
    
    $lastCompleteDay = 0;
    $gaps = [];
    $dayItemCounts = [];
    
    $checkUpTo = $maxDay ?: min($currentDay, $daysInMonth);
    
    for ($day = 1; $day <= $checkUpTo; $day++) {
        if (!doesDayColumnsExist($conn, $tableName, $day)) {
            $dayItemCounts[$day] = 0;
            continue;
        }
        
        $closingCol = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        $countStmt = $conn->prepare("
            SELECT COUNT(DISTINCT ITEM_CODE) as item_count 
            FROM `{$tableName}` 
            WHERE STK_MONTH = ? 
            AND {$closingCol} IS NOT NULL 
            AND {$closingCol} != 0
        ");
        
        $countStmt->bind_param("s", $month);
        $countStmt->execute();
        $countResult = $countStmt->get_result();
        $countRow = $countResult->fetch_assoc();
        $countStmt->close();
        
        $itemCount = $countRow['item_count'] ?? 0;
        $dayItemCounts[$day] = $itemCount;
        
        if ($itemCount > 0) {
            $lastCompleteDay = $day;
        } else {
            if ($lastCompleteDay > 0 && $day > $lastCompleteDay) {
                $gaps[] = $day;
            }
        }
    }
    
    $itemsFromLastDay = [];
    if ($lastCompleteDay > 0) {
        $itemsFromLastDay = getItemsWithOpeningBalance($conn, $tableName, $month, $lastCompleteDay);
    }
    
    return [
        'last_complete_day' => $lastCompleteDay,
        'gaps' => $gaps,
        'days_in_month' => $daysInMonth,
        'month' => $month,
        'max_day_checked' => $maxDay,
        'current_day' => $currentDay,
        'check_up_to' => $checkUpTo,
        'day_item_counts' => $dayItemCounts,
        'items_from_last_day' => $itemsFromLastDay,
        'total_items_last_day' => count($itemsFromLastDay),
        'gap_start_day' => empty($gaps) ? null : min($gaps),
        'gap_end_day' => empty($gaps) ? null : max($gaps)
    ];
}

function autoPopulateMonthGaps($conn, $tableName, $month, $gaps, $lastCompleteDay) {
    $results = [];
    $gapsFilled = 0;
    $totalItemsProcessed = 0;
    
    if ($lastCompleteDay === 0) {
        return [
            'success' => false,
            'error' => 'No source data available to copy from',
            'gaps_filled' => 0,
            'total_items' => 0
        ];
    }
    
    $sourceItems = getItemsWithOpeningBalance($conn, $tableName, $month, $lastCompleteDay);
    
    if (empty($sourceItems)) {
        return [
            'success' => false,
            'error' => "No items found with opening balance on day {$lastCompleteDay}",
            'gaps_filled' => 0,
            'total_items' => 0
        ];
    }
    
    $totalItems = count($sourceItems);
    sort($gaps);
    
    foreach ($gaps as $day) {
        $monthDays = getDaysInMonth($month);
        if ($day > $monthDays) {
            $results[$day] = [
                'success' => false,
                'error' => "Day {$day} exceeds month days ({$monthDays})"
            ];
            continue;
        }
        
        if (!doesDayColumnsExist($conn, $tableName, $day)) {
            $results[$day] = [
                'success' => false,
                'error' => "Columns for day {$day} do not exist"
            ];
            continue;
        }
        
        $targetOpen = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $targetPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $targetSales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $targetClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        try {
            $itemsProcessed = 0;
            $itemsUpdated = 0;
            
            foreach ($sourceItems as $itemCode => $openingBalance) {
                $checkItemStmt = $conn->prepare("
                    SELECT COUNT(*) as item_exists 
                    FROM `{$tableName}` 
                    WHERE STK_MONTH = ? 
                    AND ITEM_CODE = ?
                ");
                $checkItemStmt->bind_param("ss", $month, $itemCode);
                $checkItemStmt->execute();
                $checkResult = $checkItemStmt->get_result();
                $checkRow = $checkResult->fetch_assoc();
                $checkItemStmt->close();
                
                if ($checkRow['item_exists'] == 0) {
                    continue;
                }
                
                $itemsProcessed++;
                
                $updateQuery = "
                    UPDATE `{$tableName}` 
                    SET 
                        {$targetOpen} = ?,
                        {$targetPurchase} = 0,
                        {$targetSales} = 0,
                        {$targetClosing} = ?
                    WHERE STK_MONTH = ?
                    AND ITEM_CODE = ?
                ";
                
                $updateStmt = $conn->prepare($updateQuery);
                $updateStmt->bind_param("ddss", $openingBalance, $openingBalance, $month, $itemCode);
                
                if ($updateStmt->execute()) {
                    if ($updateStmt->affected_rows > 0) {
                        $itemsUpdated++;
                    }
                }
                $updateStmt->close();
            }
            
            $totalItemsProcessed += $itemsProcessed;
            
            if ($itemsUpdated > 0) {
                $gapsFilled++;
                $lastCompleteDay = $day;
                $sourceItems = getItemsWithOpeningBalance($conn, $tableName, $month, $day);
            }
            
            $results[$day] = [
                'success' => true,
                'items_processed' => $itemsProcessed,
                'items_updated' => $itemsUpdated,
                'source_day' => $lastCompleteDay,
                'total_source_items' => count($sourceItems)
            ];
            
        } catch (Exception $e) {
            $results[$day] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    return [
        'success' => true,
        'gaps_filled' => $gapsFilled,
        'total_gaps' => count($gaps),
        'last_complete_day' => $lastCompleteDay,
        'total_items_processed' => $totalItemsProcessed,
        'total_source_items' => $totalItems,
        'gap_details' => $results,
        'gap_range' => [
            'start' => empty($gaps) ? null : min($gaps),
            'end' => empty($gaps) ? null : max($gaps)
        ]
    ];
}

// =============================================================================
// MONTH INITIALIZATION FUNCTIONS
// =============================================================================

function getLastDayClosing($conn, $tableName, $month) {
    $daysInMonth = getDaysInMonth($month);
    $lastDayCol = "DAY_" . str_pad($daysInMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
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
    
    for ($day = 2; $day <= 31; $day++) {
        if (doesDayColumnsExist($conn, $tableName, $day)) {
            $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
            $clearColumns[] = "DAY_{$dayPadded}_OPEN = 0";
            $clearColumns[] = "DAY_{$dayPadded}_PURCHASE = 0";
            $clearColumns[] = "DAY_{$dayPadded}_SALES = 0";
            $clearColumns[] = "DAY_{$dayPadded}_CLOSING = 0";
        }
    }
    
    return implode(', ', $clearColumns);
}

function initializeNewMonth($conn, $tableName, $previousMonth, $newMonth) {
    try {
        $closingData = getLastDayClosing($conn, $tableName, $previousMonth);
        
        if (empty($closingData)) {
            return [
                'success' => false,
                'error' => "No closing stock data found for previous month {$previousMonth}",
                'total_items' => 0
            ];
        }
        
        $totalItems = count($closingData);
        $clearColumnsQuery = buildDayColumnsClearingQuery($conn, $tableName, $newMonth);
        
        $updateCount = 0;
        $errorCount = 0;
        $itemDetails = [];
        
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
                    $itemDetails[$itemCode] = ['success' => true, 'stock' => $closingStock];
                } else {
                    $errorCount++;
                    $itemDetails[$itemCode] = ['success' => false, 'error' => 'No rows affected'];
                }
            } else {
                $errorCount++;
                $itemDetails[$itemCode] = ['success' => false, 'error' => $updateStmt->error];
            }
            $updateStmt->close();
        }
        
        return [
            'success' => true,
            'updated_items' => $updateCount,
            'error_items' => $errorCount,
            'total_items_found' => $totalItems,
            'previous_month' => $previousMonth,
            'new_month' => $newMonth,
            'previous_month_days' => getDaysInMonth($previousMonth),
            'new_month_days' => getDaysInMonth($newMonth),
            'item_details' => $itemDetails
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

function ensureDay1Data($conn, $tableName, $month) {
    try {
        $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ?");
        $checkStmt->bind_param("s", $month);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();
        $checkStmt->close();
        
        if ($row['count'] > 0) {
            $day1CheckStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ? AND DAY_01_CLOSING IS NOT NULL AND DAY_01_CLOSING != 0");
            $day1CheckStmt->bind_param("s", $month);
            $day1CheckStmt->execute();
            $day1Result = $day1CheckStmt->get_result();
            $day1Row = $day1Result->fetch_assoc();
            $day1CheckStmt->close();
            
            if ($day1Row['count'] === 0) {
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

// =============================================================================
// TRANSITION CHECK AND EXECUTION
// =============================================================================

function checkMonthTransitionWithGaps($conn) {
    $currentCompanyId = $_SESSION['CompID'] ?? 1;
    $currentMonth = getCurrentMonth();
    $previousMonth = getPreviousMonth();
    $currentDay = getCurrentDay();

    $companyTables = [
        '1' => 'tbldailystock_1',
        '2' => 'tbldailystock_2',
        '3' => 'tbldailystock_3'
    ];

    $transitionInfo = [];

    if (isset($companyTables[$currentCompanyId])) {
        $tableName = $companyTables[$currentCompanyId];

        $tableCheck = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        if ($tableCheck->num_rows > 0) {
            $currentMonthStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ?");
            $currentMonthStmt->bind_param("s", $currentMonth);
            $currentMonthStmt->execute();
            $currentMonthResult = $currentMonthStmt->get_result();
            $currentMonthRow = $currentMonthResult->fetch_assoc();
            $currentMonthStmt->close();

            $prevMonthStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ?");
            $prevMonthStmt->bind_param("s", $previousMonth);
            $prevMonthStmt->execute();
            $prevMonthResult = $prevMonthStmt->get_result();
            $prevMonthRow = $prevMonthResult->fetch_assoc();
            $prevMonthStmt->close();

            $previousMonthGaps = [];
            $hasPreviousGaps = false;
            if ($prevMonthRow['count'] > 0) {
                $gapInfo = detectGapsInMonth($conn, $tableName, $previousMonth);
                $hasPreviousGaps = !empty($gapInfo['gaps']);
                $previousMonthGaps = $gapInfo;
            }

            $currentMonthGaps = [];
            $hasCurrentGaps = false;
            if ($currentMonthRow['count'] > 0) {
                $currentGapInfo = detectGapsInMonth($conn, $tableName, $currentMonth, $currentDay);
                $hasCurrentGaps = !empty($currentGapInfo['gaps']);
                $currentMonthGaps = $currentGapInfo;
            }

            $needsTransition = false;
            if ($prevMonthRow['count'] > 0 && !$currentMonthRow['count'] > 0) {
                $needsTransition = true;
            } elseif ($currentMonthRow['count'] > 0 && $hasCurrentGaps) {
                $needsTransition = true;
            }

            $transitionInfo = [
                'company_id' => $currentCompanyId,
                'table_name' => $tableName,
                'current_month' => $currentMonth,
                'previous_month' => $previousMonth,
                'current_day' => $currentDay,
                'current_date' => getCurrentDate(),
                'current_month_exists' => $currentMonthRow['count'] > 0,
                'current_month_count' => $currentMonthRow['count'],
                'previous_month_exists' => $prevMonthRow['count'] > 0,
                'previous_month_count' => $prevMonthRow['count'],
                'has_previous_gaps' => $hasPreviousGaps,
                'has_current_gaps' => $hasCurrentGaps,
                'previous_gap_info' => $previousMonthGaps,
                'current_gap_info' => $currentMonthGaps,
                'needs_transition' => $needsTransition,
                'needs_current_gap_fill' => $hasCurrentGaps || $needsTransition,
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
    
    $companyTables = [
        '1' => 'tbldailystock_1',
        '2' => 'tbldailystock_2',
        '3' => 'tbldailystock_3'
    ];
    
    if (!isset($companyTables[$currentCompanyId])) {
        return [
            'success' => false,
            'error' => 'Invalid company ID',
            'complete_process' => false
        ];
    }
    
    $tableName = $companyTables[$currentCompanyId];
    
    try {
        // STEP 0: ENSURE MAIN TABLE EXISTS
        $ensureTableResult = ensureMainTableExists($conn, $tableName);
        if (!$ensureTableResult['success']) {
            throw new Exception("Failed to ensure main table exists: " . $ensureTableResult['error']);
        }
        
        // STEP 0.5: RESTORE PREVIOUS MONTH DATA IF NEEDED
        $prevMonthStmt = $conn->prepare("SELECT COUNT(*) as count FROM `{$tableName}` WHERE STK_MONTH = ?");
        $prevMonthStmt->bind_param("s", $previousMonth);
        $prevMonthStmt->execute();
        $prevMonthResult = $prevMonthStmt->get_result();
        $prevMonthRow = $prevMonthResult->fetch_assoc();
        $prevMonthStmt->close();
        
        $restoreResult = null;
        if ($prevMonthRow['count'] == 0) {
            $restoreResult = restorePreviousMonthFromArchive($conn, $tableName, $previousMonth);
            if (!$restoreResult['success']) {
                // Try to restore current month if previous month doesn't exist
                $restoreResult = restorePreviousMonthFromArchive($conn, $tableName, $currentMonth);
                if (!$restoreResult['success']) {
                    throw new Exception("No data found for previous month and no archive available");
                }
            }
        }
        
        // STEP 1: Fill gaps in previous month if any
        $previousGapFillResult = ['success' => true, 'message' => 'No previous month gaps to fill', 'gaps_filled' => 0];
        $transitionInfo = checkMonthTransitionWithGaps($conn);
        
        if ($transitionInfo && $transitionInfo['has_previous_gaps'] && !empty($transitionInfo['previous_gap_info']['gaps'])) {
            $previousGapFillResult = autoPopulateMonthGaps(
                $conn, 
                $tableName, 
                $previousMonth, 
                $transitionInfo['previous_gap_info']['gaps'], 
                $transitionInfo['previous_gap_info']['last_complete_day']
            );
        }
        
        // STEP 2: Create archive table for previous month
        $archiveTable = $tableName . '_' . getMonthSuffix($previousMonth);
        $archiveResult = createArchiveTable($conn, $tableName, $archiveTable, $previousMonth);
        
        // STEP 3: Initialize new month with previous month's closing stock
        $initResult = ['success' => false, 'error' => 'Archive creation failed'];
        if ($archiveResult['success'] || $archiveResult['error'] && strpos($archiveResult['error'], 'already exists') !== false) {
            $initResult = initializeNewMonth($conn, $tableName, $previousMonth, $currentMonth);
        } else {
            // Try to initialize even if archive creation failed but table might already exist
            $initResult = initializeNewMonth($conn, $tableName, $previousMonth, $currentMonth);
        }
        
        // STEP 4: Fill gaps in current month if any
        $currentGapFillResult = ['success' => true, 'message' => 'No current month gaps to fill', 'gaps_filled' => 0];
        
        sleep(1);
        
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
            $firstGapDay = min($currentGapInfo['gaps']);
            if ($firstGapDay === 1) {
                $ensureDay1Result = ensureDay1Data($conn, $tableName, $currentMonth);
                if ($ensureDay1Result['success']) {
                    sleep(1);
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
        
        return [
            'success' => true,
            'company_id' => $currentCompanyId,
            'table_name' => $tableName,
            'ensure_table_result' => $ensureTableResult,
            'restore_result' => $restoreResult,
            'previous_gap_fill_result' => $previousGapFillResult,
            'archive_result' => $archiveResult,
            'init_result' => $initResult,
            'current_gap_fill_result' => $currentGapFillResult,
            'current_gap_info' => $currentGapInfo,
            'previous_month' => $previousMonth,
            'current_month' => $currentMonth,
            'current_day' => $currentDay,
            'archive_table_name' => $archiveTable,
            'previous_month_days' => getDaysInMonth($previousMonth),
            'current_month_days' => getDaysInMonth($currentMonth),
            'complete_process' => true
        ];
        
    } catch (Exception $e) {
        error_log("Month transition failed: " . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'complete_process' => false
        ];
    }
}

// =============================================================================
// DASHBOARD STATISTICS FUNCTIONS
// =============================================================================

function getTotalItemsCount($conn) {
    $query = "SELECT COUNT(*) as item_count FROM tblitemmaster";
    $result = $conn->query($query);
    
    if (!$result) {
        return 0;
    }
    
    $row = $result->fetch_assoc();
    $result->free();
    
    return $row ? $row['item_count'] : 0;
}

function getLicensedItemsCount($conn, $license_type) {
    $allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);
    
    if (empty($allowed_categories)) {
        return 0;
    }
    
    $category_codes = [];
    foreach ($allowed_categories as $cat) {
        $category_codes[] = $conn->real_escape_string($cat['CATEGORY_CODE']);
    }
    
    $codes_string = "'" . implode("','", $category_codes) . "'";
    
    $query = "
        SELECT COUNT(*) as item_count 
        FROM tblitemmaster 
        WHERE CATEGORY_CODE IN ($codes_string)
    ";
    
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
    
    if (empty($allowed_categories)) {
        return [];
    }
    
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
        if (stripos($class_name, 'imfl') !== false) {
            return 'IMFL Spirit';
        } elseif (stripos($class_name, 'imported') !== false) {
            return 'Imported Spirit';
        } elseif (stripos($class_name, 'mml') !== false) {
            return 'MML Spirit';
        }
    } elseif (stripos($category_name, 'wine') !== false) {
        if (stripos($class_name, 'imported') !== false) {
            return 'Imported Wine';
        } elseif (stripos($class_name, 'indian') !== false) {
            return 'Wine Indian';
        } elseif (stripos($class_name, 'mml') !== false) {
            return 'MML Wine';
        }
    } elseif (stripos($category_name, 'beer') !== false) {
        if (stripos($class_name, 'fermented') !== false) {
            return 'Fermented Beer';
        } elseif (stripos($class_name, 'mild') !== false) {
            return 'Mild Beer';
        }
    }
    
    return $class_name;
}

function getCategoryColor($category_name, $class_name = '') {
    if (stripos($category_name, 'spirit') !== false) {
        if (stripos($class_name, 'imfl') !== false) {
            return '#667eea';
        } elseif (stripos($class_name, 'imported') !== false) {
            return '#764ba2';
        } elseif (stripos($class_name, 'mml') !== false) {
            return '#4facfe';
        }
        return '#667eea';
    } elseif (stripos($category_name, 'wine') !== false) {
        if (stripos($class_name, 'imported') !== false) {
            return '#f5576c';
        } elseif (stripos($class_name, 'indian') !== false) {
            return '#fa709a';
        } elseif (stripos($class_name, 'mml') !== false) {
            return '#ff6b6b';
        }
        return '#f5576c';
    } elseif (stripos($category_name, 'beer') !== false) {
        if (stripos($class_name, 'fermented') !== false) {
            return '#43e97b';
        } elseif (stripos($class_name, 'mild') !== false) {
            return '#38f9d7';
        }
        return '#43e97b';
    }
    
    $colors = ['#667eea', '#764ba2', '#f5576c', '#4facfe', '#43e97b', '#fa709a', '#ff9a9e', '#a18cd1'];
    return $colors[array_rand($colors)];
}

function getCategoryIcon($category_name, $class_name = '') {
    if (stripos($category_name, 'spirit') !== false) {
        return 'fas fa-glass-whiskey';
    } elseif (stripos($category_name, 'wine') !== false) {
        return 'fas fa-wine-glass';
    } elseif (stripos($category_name, 'beer') !== false) {
        return 'fas fa-beer';
    }
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
        
        if ($row) {
            return $row['total_customers'];
        }
    }
    
    return 0;
}

function getTotalSuppliers($conn) {
    $query = "SELECT COUNT(*) as total FROM tblsupplier WHERE CODE IS NOT NULL";
    $result = $conn->query($query);
    
    if (!$result) {
        return 0;
    }
    
    $row = $result->fetch_assoc();
    $result->free();
    
    return $row ? $row['total'] : 0;
}

function getActivePermits($conn) {
    $currentDate = date('Y-m-d');
    $query = "SELECT COUNT(*) as total FROM tblpermit WHERE P_EXP_DT >= ? AND PRMT_FLAG = 1";
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        return 0;
    }
    
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
    if (!$stmt) {
        return 0;
    }
    
    $stmt->bind_param("s", $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row ? $row['total'] : 0;
}

// =============================================================================
// MONTH TRANSITION PROCESSING
// =============================================================================

$transitionResults = null;
if (isset($_POST['complete_month_transition']) && $_POST['complete_month_transition'] === '1') {
    error_log("Manual month transition started by user: " . ($_SESSION['user_id'] ?? 'unknown'));
    
    try {
        $transitionResults = executeCompleteMonthTransition($conn);
        
        if ($transitionResults && isset($transitionResults['complete_process']) && $transitionResults['complete_process']) {
            $successMsg = "Complete month transition processed successfully! ";
            $successMsg .= "Archive table: " . $transitionResults['archive_table_name'];
            
            if (isset($transitionResults['ensure_table_result']['action'])) {
                $successMsg .= " | Table: " . $transitionResults['ensure_table_result']['action'];
            }
            
            if (isset($transitionResults['restore_result']['action'])) {
                $successMsg .= " | Restore: " . $transitionResults['restore_result']['action'];
            }
            
            if (isset($transitionResults['previous_gap_fill_result']['gaps_filled']) && $transitionResults['previous_gap_fill_result']['gaps_filled'] > 0) {
                $successMsg .= " | Prev gaps: " . $transitionResults['previous_gap_fill_result']['gaps_filled'];
            }
            
            if (isset($transitionResults['current_gap_fill_result']['gaps_filled']) && $transitionResults['current_gap_fill_result']['gaps_filled'] > 0) {
                $successMsg .= " | Curr gaps: " . $transitionResults['current_gap_fill_result']['gaps_filled'];
                if (isset($transitionResults['current_gap_fill_result']['total_items_processed'])) {
                    $successMsg .= " (" . $transitionResults['current_gap_fill_result']['total_items_processed'] . " items)";
                }
            }
            
            $_SESSION['transition_message'] = $successMsg;
            $_SESSION['message_type'] = 'success';
        } else {
            $errorMsg = "Month transition failed: " . ($transitionResults['error'] ?? 'Unknown error');
            $_SESSION['transition_message'] = $errorMsg;
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

$transitionInfo = checkMonthTransitionWithGaps($conn);

$autoTransitionResults = null;
if ($transitionInfo && ($transitionInfo['needs_transition'] || $transitionInfo['needs_current_gap_fill'])) {
    $transitionKey = 'auto_transition_' . $transitionInfo['current_month'] . '_' . date('Y-m-d');
    if (!isset($_SESSION[$transitionKey])) {
        error_log("Automatic month transition started for: " . $transitionInfo['current_month']);
        
        try {
            $autoTransitionResults = executeCompleteMonthTransition($conn);
            
            if ($autoTransitionResults && isset($autoTransitionResults['complete_process']) && $autoTransitionResults['complete_process']) {
                $successMsg = "Automatic month transition completed successfully!";
                $_SESSION['transition_message'] = $successMsg;
                $_SESSION['message_type'] = 'success';
                $_SESSION[$transitionKey] = true;
            }
        } catch (Exception $e) {
            error_log("Automatic month transition exception: " . $e->getMessage());
        }
        
        $transitionInfo = checkMonthTransitionWithGaps($conn);
    }
}

$successMessage = '';
$messageType = '';
if (isset($_SESSION['transition_message'])) {
    $successMessage = $_SESSION['transition_message'];
    $messageType = $_SESSION['message_type'] ?? 'info';
    unset($_SESSION['transition_message']);
    unset($_SESSION['message_type']);
}

// =============================================================================
// DASHBOARD STATISTICS
// =============================================================================

$stats = [
    'total_items' => 0,
    'licensed_items' => 0,
    'total_customers' => 0,
    'total_suppliers' => 0,
    'total_permits' => 0,
    'total_dry_days' => 0
];

$total_items_count = getTotalItemsCount($conn);
$stats['total_items'] = number_format($total_items_count);

if ($license_type) {
    $licensed_items_count = getLicensedItemsCount($conn, $license_type);
    $stats['licensed_items'] = number_format($licensed_items_count);
    $classes = getClassesWithCounts($conn, $license_type);
} else {
    $stats['licensed_items'] = 0;
    $classes = [];
}

try {
    $total_customers_count = getTotalCustomers($conn, $company_id);
    $stats['total_customers'] = number_format($total_customers_count);
    
    $total_suppliers_count = getTotalSuppliers($conn);
    $stats['total_suppliers'] = number_format($total_suppliers_count);
    
    $active_permits_count = getActivePermits($conn);
    $stats['total_permits'] = number_format($active_permits_count);
    
    $dry_days_count = getDryDaysCount($conn);
    $stats['total_dry_days'] = number_format($dry_days_count);
    
} catch (Exception $e) {
    $error = "Dashboard statistics error: " . $e->getMessage();
}

// =============================================================================
// DETERMINE IF TRANSITION CARD SHOULD BE SHOWN
// =============================================================================

$show_transition_card = false;

// Show card if automatic transition was attempted and failed
if ($autoTransitionResults && isset($autoTransitionResults['complete_process']) && !$autoTransitionResults['complete_process']) {
    $show_transition_card = true;
}
// Show card if there's an error message from previous attempt
else if (isset($transitionResults) && $transitionResults && isset($transitionResults['complete_process']) && !$transitionResults['complete_process']) {
    $show_transition_card = true;
}
// Show card if manual form was submitted and we have results (success or fail)
else if (isset($_POST['complete_month_transition']) && $_POST['complete_month_transition'] === '1' && isset($transitionResults)) {
    $show_transition_card = true;
}
// Show card if there's a transition message in session that indicates error
else if (isset($successMessage) && $successMessage && isset($messageType) && $messageType === 'error') {
    $show_transition_card = true;
}
// Show card if transition is needed AND auto processing was not attempted or failed
else if ($transitionInfo && ($transitionInfo['needs_transition'] || $transitionInfo['needs_current_gap_fill'])) {
    // Only show if auto processing didn't run or failed
    $transitionKey = 'auto_transition_' . $transitionInfo['current_month'] . '_' . date('Y-m-d');
    if (!isset($_SESSION[$transitionKey])) {
        // Auto processing hasn't been attempted yet, don't show card yet
        // Wait for auto processing to try first
    } else {
        // Auto processing was attempted, check if it succeeded
        if ($autoTransitionResults && isset($autoTransitionResults['complete_process']) && !$autoTransitionResults['complete_process']) {
            $show_transition_card = true;
        }
    }
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
        
        .gap-days {
            font-size: 0.8rem;
            margin-right: 5px;
        }
        
        .item-count-badge {
            background: #4ecdc4;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.8rem;
            margin-left: 5px;
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
        
        .current-day-highlight {
            background: #ff6b6b;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: bold;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    
    <div class="main-content">
        <div class="content-area">
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
            
            <?php if($show_transition_card && $transitionInfo && $transitionInfo['table_name']): ?>
                <div class="card transition-alert">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h4 class="mb-0">
                                    <i class="fas fa-exclamation-triangle"></i>
                                    Month Transition Failed - Manual Action Required
                                </h4>
                                <div class="mt-2">
                                    <strong>Previous Month:</strong> <?php echo $transitionInfo['previous_month']; ?> 
                                    (<?php echo $transitionInfo['previous_month_days']; ?> days) │
                                    <strong>Current Month:</strong> <?php echo $transitionInfo['current_month']; ?> 
                                    (<?php echo $transitionInfo['current_month_days']; ?> days) │
                                    <strong>Today:</strong> <?php echo $transitionInfo['current_date']; ?> 
                                    (Day <span class="current-day-highlight"><?php echo $transitionInfo['current_day']; ?></span>)
                                </div>
                            </div>
                            <form method="POST" id="transitionForm" style="display: inline;">
                                <input type="hidden" name="complete_month_transition" value="1">
                                <button type="submit" class="btn btn-warning btn-transition">
                                    <i class="fas fa-redo"></i> Manual Re-process
                                </button>
                            </form>
                        </div>
                        
                        <div class="alert alert-danger">
                            <i class="fas fa-times-circle"></i>
                            <strong>Automatic Processing Failed:</strong>
                            The system was unable to automatically complete the month transition.
                            <?php if($autoTransitionResults && isset($autoTransitionResults['error'])): ?>
                                <br><small>Error: <?php echo htmlspecialchars($autoTransitionResults['error']); ?></small>
                            <?php elseif($transitionResults && isset($transitionResults['error'])): ?>
                                <br><small>Error: <?php echo htmlspecialchars($transitionResults['error']); ?></small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="process-steps">
                            <h6><i class="fas fa-list-ol"></i> Manual Process Steps:</h6>
                            
                            <div class="step-item">
                                <strong>Step 1: Ensure Table Exists</strong>
                                <div class="mt-1">
                                    <small>Check if main table exists and create if missing</small>
                                </div>
                            </div>
                            
                            <?php if(!$transitionInfo['previous_month_exists']): ?>
                            <div class="step-item">
                                <strong>Step 2: Restore Previous Month Data</strong>
                                <div class="mt-1">
                                    <small>Restore <?php echo $transitionInfo['previous_month']; ?> data from archive</small>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if($transitionInfo['has_previous_gaps']): ?>
                            <div class="step-item">
                                <strong>Step 3: Fill Previous Month Gaps</strong>
                                <div class="mt-1">
                                    <?php if(!empty($transitionInfo['previous_gap_info']['gaps'])): ?>
                                        <small>Missing days: </small>
                                        <?php foreach($transitionInfo['previous_gap_info']['gaps'] as $day): ?>
                                            <span class="badge bg-warning gap-days">Day <?php echo $day; ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="step-item">
                                <strong>Step 4: Create Archive Table</strong>
                                <div class="mt-1">
                                    <small>Archive: <code><?php echo $transitionInfo['table_name'] . '_' . getMonthSuffix($transitionInfo['previous_month']); ?></code></small>
                                </div>
                            </div>
                            
                            <div class="step-item">
                                <strong>Step 5: Initialize New Month</strong>
                                <div class="mt-1">
                                    <small>Copy closing stock from <?php echo $transitionInfo['previous_month']; ?> to <?php echo $transitionInfo['current_month']; ?></small>
                                </div>
                            </div>
                            
                            <?php if($transitionInfo['has_current_gaps']): ?>
                            <div class="step-item">
                                <strong>Step 6: Fill Current Month Gaps</strong>
                                <div class="mt-1">
                                    <?php if(!empty($transitionInfo['current_gap_info']['gaps'])): ?>
                                        <small>Missing days: </small>
                                        <?php foreach($transitionInfo['current_gap_info']['gaps'] as $day): ?>
                                            <span class="badge bg-info gap-days">Day <?php echo $day; ?></span>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mt-3">
                                <small class="text-muted">
                                    <i class="fas fa-info-circle"></i> 
                                    Click "Manual Re-process" to attempt the transition again.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
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
                        <div class="stat-card" style="border-left-color: <?php echo $color; ?>;">
                            <div class="stat-icon" style="background: <?php echo $color; ?>;">
                                <i class="<?php echo $icon; ?>"></i>
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

<!-- Only include shortcuts.js without any dashboard shortcut code -->
<script src="components/shortcuts.js?v=<?= time() ?>"></script>

<script>
$(document).ready(function() {
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 8000);
    
    $('#transitionForm').on('submit', function(e) {
        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Processing...');
    });
    
    <?php if($successMessage): ?>
        window.scrollTo(0, 0);
    <?php endif; ?>
    
    <?php if($autoTransitionResults && isset($autoTransitionResults['complete_process']) && $autoTransitionResults['complete_process']): ?>
        setTimeout(function() {
            location.reload();
        }, 30000);
    <?php endif; ?>
});

// No dashboard shortcut code here - shortcuts.js will work independently
</script>

</body>
</html>