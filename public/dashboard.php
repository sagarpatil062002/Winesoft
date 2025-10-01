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
// COMPLETE MONTH TRANSITION SYSTEM WITH GAP FILLING
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
 * Get month suffix for table names (MM_YY format)
 */
function getMonthSuffix($month) {
    $date = DateTime::createFromFormat('Y-m', $month);
    return $date->format('m_y');
}

/**
 * Check if day columns exist for a specific day
 */
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

/**
 * Check if archive table already exists
 */
function archiveTableExists($conn, $tableName) {
    $stmt = $conn->prepare("SHOW TABLES LIKE ?");
    $stmt->bind_param("s", $tableName);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Create archive table and copy data
 */
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
            throw new Exception("Failed to create archive table structure: " . $conn->error);
        }
        
        // Step 2: Copy data from source to archive table for the specific month
        $copyQuery = "INSERT INTO `{$archiveTable}` SELECT * FROM `{$sourceTable}` WHERE STK_MONTH = '{$month}'";
        if (!$conn->query($copyQuery)) {
            // If copy fails, drop the created table to avoid orphaned tables
            $conn->query("DROP TABLE IF EXISTS `{$archiveTable}`");
            throw new Exception("Failed to copy data to archive: " . $conn->error);
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

/**
 * Detect gaps in a specific month's table
 */
function detectGapsInMonth($conn, $tableName, $month) {
    $daysInMonth = (int)date('t', strtotime($month . '-01'));
    
    // For previous months, check all days
    $checkUptoDay = $daysInMonth;
    
    // Find the last day that has data
    $lastCompleteDay = 0;
    $gaps = [];
    
    for ($day = 1; $day <= $checkUptoDay; $day++) {
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
    
    return [
        'last_complete_day' => $lastCompleteDay,
        'gaps' => $gaps,
        'days_in_month' => $daysInMonth,
        'month' => $month
    ];
}

/**
 * Auto-populate gaps in a specific month
 */
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
        $monthDays = (int)date('t', strtotime($month . '-01'));
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
            $updateStmt = $conn->prepare("
                UPDATE `{$tableName}` 
                SET 
                    {$targetOpen} = {$sourceClosing},
                    {$targetPurchase} = 0,
                    {$targetSales} = 0,
                    {$targetClosing} = {$sourceClosing}
                WHERE STK_MONTH = ?
                AND {$sourceClosing} IS NOT NULL
            ");
            $updateStmt->bind_param("s", $month);
            $updateStmt->execute();
            $affectedRows = $updateStmt->affected_rows;
            $updateStmt->close();
            
            if ($affectedRows > 0) {
                $gapsFilled++;
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
    
    return [
        'success' => true,
        'gaps_filled' => $gapsFilled,
        'details' => $results
    ];
}

/**
 * Get last day's closing stock for a specific month
 */
function getLastDayClosing($conn, $tableName, $month) {
    $daysInMonth = (int)date('t', strtotime($month . '-01'));
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

/**
 * Build dynamic day columns clearing query based on actual table structure
 */
function buildDayColumnsClearingQuery($conn, $tableName) {
    $clearColumns = [];
    
    // Check which day columns actually exist in the table
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

/**
 * Initialize new month with previous month's closing stock
 */
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
        
        // Build dynamic clearing query based on actual table structure
        $clearColumnsQuery = buildDayColumnsClearingQuery($conn, $tableName);
        
        // Update existing records in the same table - change STK_MONTH and reset daily data
        $updateCount = 0;
        foreach ($closingData as $itemCode => $closingStock) {
            $updateStmt = $conn->prepare("
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
            ");
            $updateStmt->bind_param("sdsss", $newMonth, $closingStock, $closingStock, $previousMonth, $itemCode);
            $updateStmt->execute();
            if ($updateStmt->affected_rows > 0) {
                $updateCount++;
            }
            $updateStmt->close();
        }
        
        $results = [
            'success' => true,
            'updated_items' => $updateCount,
            'previous_month' => $previousMonth,
            'new_month' => $newMonth
        ];
        
    } catch (Exception $e) {
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
            $hasGaps = false;
            if ($prevMonthRow['count'] > 0) {
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
                'needs_transition' => $prevMonthRow['count'] > 0 && !$currentMonthRow['count'] > 0
            ];
        }
    }
    
    return $transitionInfo;
}

/**
 * Execute complete month transition with gap filling
 */
function executeCompleteMonthTransition($conn) {
    $currentCompanyId = $_SESSION['CompID'] ?? 1;
    $currentMonth = getCurrentMonth();
    $previousMonth = getPreviousMonth();
    
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
            // Step 1: Fill gaps in previous month if any
            $gapFillResult = ['success' => true, 'message' => 'No gaps to fill', 'gaps_filled' => 0];
            $transitionInfo = checkMonthTransitionWithGaps($conn);
            
            if ($transitionInfo['has_gaps'] && !empty($transitionInfo['gap_info']['gaps'])) {
                $gapFillResult = autoPopulateMonthGaps(
                    $conn, 
                    $tableName, 
                    $previousMonth, 
                    $transitionInfo['gap_info']['gaps'], 
                    $transitionInfo['gap_info']['last_complete_day']
                );
            }
            
            // Step 2: Create archive table for previous month
            $archiveTable = $tableName . '_' . getMonthSuffix($previousMonth);
            $archiveResult = createArchiveTable($conn, $tableName, $archiveTable, $previousMonth);
            
            // Step 3: Initialize new month with previous month's closing stock
            $initResult = ['success' => false, 'error' => 'Archive creation failed'];
            if ($archiveResult['success']) {
                $initResult = initializeNewMonth($conn, $tableName, $previousMonth, $currentMonth);
            }
            
            $results = [
                'company_id' => $currentCompanyId,
                'table_name' => $tableName,
                'gap_fill_result' => $gapFillResult,
                'archive_result' => $archiveResult,
                'init_result' => $initResult,
                'previous_month' => $previousMonth,
                'current_month' => $currentMonth,
                'archive_table_name' => $archiveTable
            ];
            
        } catch (Exception $e) {
            $results = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    return $results;
}

// Handle complete month transition request
$transitionResults = null;
if (isset($_POST['complete_month_transition']) && $_POST['complete_month_transition'] === '1') {
    $transitionResults = executeCompleteMonthTransition($conn);
    
    if ($transitionResults && 
        isset($transitionResults['archive_result']['success']) && 
        $transitionResults['archive_result']['success'] && 
        isset($transitionResults['init_result']['success']) && 
        $transitionResults['init_result']['success']) {
        
        $_SESSION['transition_message'] = "Complete month transition processed successfully! Archive table created: " . $transitionResults['archive_table_name'];
    } else {
        $errorMsg = "Month transition failed: ";
        if (isset($transitionResults['archive_result']['error'])) {
            $errorMsg .= "Archive: " . $transitionResults['archive_result']['error'];
        }
        if (isset($transitionResults['init_result']['error'])) {
            $errorMsg .= " Init: " . $transitionResults['init_result']['error'];
        }
        $_SESSION['transition_message'] = $errorMsg;
    }
}

// Check if month transition is needed
$transitionInfo = checkMonthTransitionWithGaps($conn);

// Show success message if available
$successMessage = '';
if (isset($_SESSION['transition_message'])) {
    $successMessage = $_SESSION['transition_message'];
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
                <strong>Previous Month:</strong> <?php echo $transitionInfo['previous_month']; ?> │ 
                <strong>Current Month:</strong> <?php echo $transitionInfo['current_month']; ?>
              </div>
            </div>
            <form method="POST" style="display: inline;">
              <button type="submit" name="complete_month_transition" value="1" class="btn-transition">
                <i class="fas fa-sync-alt"></i> Process Complete Transition
              </button>
            </form>
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
              </div>
            </div>
            
            <div class="step-item">
              <strong>Step 3: Initialize New Month</strong>
              <div class="mt-1">
                <small>Carry forward closing stock from <?php echo $transitionInfo['previous_month']; ?> to <?php echo $transitionInfo['current_month']; ?> opening</small>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

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
            <i class="fas fa-wine-bottle"></i>
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

        <!-- Beer Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>BEER ITEMS</h4>
            <p><?php echo $stats['total_beer_items']; ?></p>
          </div>
        </div>

        <!-- Brandy Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);">
            <i class="fas fa-wine-glass-alt"></i>
          </div>
          <div class="stat-info">
            <h4>BRANDY ITEMS</h4>
            <p><?php echo $stats['brandy_items']; ?></p>
          </div>
        </div>

        <!-- Vodka Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%);">
            <i class="fas fa-glass-martini-alt"></i>
          </div>
          <div class="stat-info">
            <h4>VODKA ITEMS</h4>
            <p><?php echo $stats['vodka_items']; ?></p>
          </div>
        </div>

        <!-- Rum Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #d4fc79 0%, #96e6a1 100%);">
            <i class="fas fa-tint"></i>
          </div>
          <div class="stat-info">
            <h4>RUM ITEMS</h4>
            <p><?php echo $stats['rum_items']; ?></p>
          </div>
        </div>

        <!-- Other Items -->
        <div class="stat-card">
          <div class="stat-icon" style="background: linear-gradient(135deg, #e6dee9 0%, #d1c4e9 100%);">
            <i class="fas fa-ellipsis-h"></i>
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
<script src="js/script.js"></script>
</body>
</html>