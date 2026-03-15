<?php
session_start();

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];

include_once "../config/db.php";
include_once "stock_functions.php";
include_once "license_functions.php";

// ---- Mode: F (Foreign) / C (Country) ----
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// ---- Get purchase ID from URL ----
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: purchase_module.php?mode=".$mode);
    exit;
}
$purchaseId = $_GET['id'];

// ---- Get company's license type and available classes ----
$license_type = getCompanyLicenseType($companyId, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

$allowed_classes = [];
if (!empty($available_classes)) {
    foreach ($available_classes as $class) {
        $allowed_classes[] = $class['SGROUP'];
    }
}

// ---- Fetch existing purchase data ----
$purchaseQuery = "SELECT p.*, s.DETAILS as supplier_name
              FROM tblpurchases p
              LEFT JOIN tblsupplier s ON TRIM(p.SUBCODE) = TRIM(s.CODE)
              WHERE p.ID = ? AND p.CompID = ?";
$purchaseStmt = $conn->prepare($purchaseQuery);
$purchaseStmt->bind_param("ii", $purchaseId, $companyId);
$purchaseStmt->execute();
$purchaseResult = $purchaseStmt->get_result();
$purchase = $purchaseResult->fetch_assoc();
$purchaseStmt->close();

if (!$purchase) {
    header("Location: purchase_module.php?mode=".$mode);
    exit;
}

// ---- Fetch purchase items ----
$itemsQuery = "SELECT * FROM tblpurchasedetails WHERE PurchaseID = ?";
$itemsStmt = $conn->prepare($itemsQuery);
$itemsStmt->bind_param("i", $purchaseId);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();
$existingItems = $itemsResult->fetch_all(MYSQLI_ASSOC);
$itemsStmt->close();

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

// Function to clean item code by removing SCM prefix
function cleanItemCode($code) {
    return preg_replace('/^SCM/i', '', trim($code));
}

// Function to format bottle size with proper units (ML/L) without spaces
function formatBottleSize($sizeText) {
    if (!$sizeText) return '';

    // Extract numeric value
    $match = preg_match('/(\d+(?:\.\d+)?)/', $sizeText, $matches);
    if (!$match) return $sizeText;

    $sizeNum = floatval($matches[1]);

    // If 1000 or more, display as L, otherwise as ML
    if ($sizeNum >= 1000) {
        $liters = $sizeNum / 1000;
        return $liters == intval($liters) ? $liters . 'L' : number_format($liters, 1) . 'L';
    } else {
        return $sizeNum . 'ML';
    }
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

// Function to check if a date is within the current financial year
function isWithinFinancialYear($month, $year, $conn, $comp_id) {
    // Get financial year start and end from session
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    
    // Parse financial year (assuming format like "2024-2025" or "2024-25")
    $fin_year_parts = explode('-', $fin_year_id);
    if (count($fin_year_parts) >= 2) {
        $start_year = (int)$fin_year_parts[0];
        // Handle both "2024-2025" and "2024-25" formats
        $end_year_str = $fin_year_parts[1];
        if (strlen($end_year_str) == 2) {
            // If it's 2-digit year, assume it's the last two digits of the century
            $century = substr($start_year, 0, 2);
            $end_year = (int)($century . $end_year_str);
        } else {
            $end_year = (int)$end_year_str;
        }
        
        // Financial year in India is April to March
        $fin_start_date = strtotime("01-04-{$start_year}");
        $fin_end_date = strtotime("31-03-{$end_year}");
        
        $current_date = strtotime("01-{$month}-{$year}");
        
        return ($current_date >= $fin_start_date && $current_date <= $fin_end_date);
    }
    
    // If we can't determine, default to true (allow)
    return true;
}

// Modified: Get the correct table name for any month - now checks financial year
function getDailyStockTableName($conn, $comp_id, $month, $year) {
    // First check if this month is within the financial year
    if (!isWithinFinancialYear($month, $year, $conn, $comp_id)) {
        return null; // Return null if outside financial year
    }
    
    return ensureMonthTableExists($conn, $comp_id, $month, $year);
}

// Modified: Ensure table exists for a given month - now checks financial year
function ensureMonthTableExists($conn, $comp_id, $month, $year) {
    // First check if this month is within the financial year
    if (!isWithinFinancialYear($month, $year, $conn, $comp_id)) {
        return null; // Return null if outside financial year
    }
    
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $current_month = (int)date('n');
    $current_year = (int)date('Y');
    
    // If it's current month, use main table
    if ($month == $current_month && $year == $current_year) {
        $table = "tbldailystock_" . $comp_id;
        $check_table = "SELECT COUNT(*) as count FROM information_schema.tables 
                       WHERE table_schema = DATABASE() AND table_name = '$table'";
        $table_result = $conn->query($check_table);
        $table_exists = $table_result && $table_result->fetch_assoc()['count'] > 0;
        
        if (!$table_exists) {
            $days_in_month = date('t', strtotime("$year-$month-01"));
            createArchiveTable($conn, $table, $days_in_month);
        }
        return $table;
    }
    
    // For other months, check/create archive table
    $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    $check_table = "SELECT COUNT(*) as count FROM information_schema.tables 
                   WHERE table_schema = DATABASE() AND table_name = '$archive_table'";
    $table_result = $conn->query($check_table);
    $table_exists = $table_result && $table_result->fetch_assoc()['count'] > 0;
    
    if (!$table_exists) {
        $days_in_month = date('t', strtotime("$year-$month-01"));
        createArchiveTable($conn, $archive_table, $days_in_month);
    }
    
    return $archive_table;
}

// ==================== FIXED: Get the last day's closing of a month ====================
function getMonthEndClosing($conn, $table, $itemCode, $monthYear) {
    $daysInMonth = date('t', strtotime($monthYear . '-01'));
    $lastDay = str_pad($daysInMonth, 2, '0', STR_PAD_LEFT);
    
    $query = "SELECT DAY_{$lastDay}_CLOSING as closing FROM $table 
              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $monthYear, $itemCode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $closing = (int)($row['closing'] ?? 0);
        $stmt->close();
        return $closing;
    }
    $stmt->close();
    return 0;
}

// ==================== FIXED: Cascade within a specific month from a given day ====================
function cascadeMonthFromDay($conn, $table, $itemCode, $monthYear, $startDay) {
    $daysInMonth = date('t', strtotime($monthYear . '-01'));
    
    // If startDay is 0, we need to cascade from day 1
    $startFrom = max(1, $startDay);
    
    for ($day = $startFrom; $day <= $daysInMonth; $day++) {
        $prevDay = $day - 1;
        $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        // First, get the previous day's closing
        $get_prev_query = "SELECT $prevDayClosing as prev_closing FROM $table 
                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $get_stmt = $conn->prepare($get_prev_query);
        $get_stmt->bind_param("ss", $monthYear, $itemCode);
        $get_stmt->execute();
        $prev_result = $get_stmt->get_result();
        $prev_row = $prev_result->fetch_assoc();
        $prev_closing = $prev_row['prev_closing'] ?? 0;
        $get_stmt->close();
        
        // Get current day's purchase and sales
        $get_current = "SELECT $currentDayPurchase as purchase, $currentDaySales as sales 
                       FROM $table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $stmt = $conn->prepare($get_current);
        $stmt->bind_param("ss", $monthYear, $itemCode);
        $stmt->execute();
        $current_result = $stmt->get_result();
        $current_data = $current_result->fetch_assoc();
        $purchase = $current_data['purchase'] ?? 0;
        $sales = $current_data['sales'] ?? 0;
        $stmt->close();
        
        // Update opening and calculate closing
        $new_closing = $prev_closing + $purchase - $sales;
        
        $update_query = "UPDATE $table 
                        SET $currentDayOpening = ?,
                            $currentDayClosing = ?
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iiss", $prev_closing, $new_closing, $monthYear, $itemCode);
        $update_stmt->execute();
        $update_stmt->close();
    }
}

// ==================== FIXED: Reset days after a certain date (for gap days) ====================
function resetDaysAfterDate($conn, $table, $itemCode, $monthYear, $targetDay, $startClosing) {
    $daysInMonth = date('t', strtotime($monthYear . '-01'));
    
    // First, ensure the target day has the correct closing
    $targetDayPadded = str_pad($targetDay, 2, '0', STR_PAD_LEFT);
    $targetClosingCol = "DAY_{$targetDayPadded}_CLOSING";
    
    $update_target = "UPDATE $table SET $targetClosingCol = ? 
                     WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $stmt = $conn->prepare($update_target);
    $stmt->bind_param("iss", $startClosing, $monthYear, $itemCode);
    $stmt->execute();
    $stmt->close();
    
    // Reset all days after target day to have zero stock (no purchase)
    for ($day = $targetDay + 1; $day <= $daysInMonth; $day++) {
        $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
        $openingCol = "DAY_{$dayPadded}_OPEN";
        $purchaseCol = "DAY_{$dayPadded}_PURCHASE";
        $salesCol = "DAY_{$dayPadded}_SALES";
        $closingCol = "DAY_{$dayPadded}_CLOSING";
        
        // For days after the new purchase date, they should have no purchase
        // But they still need proper cascading based on previous day's closing
        $prevDay = $day - 1;
        $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        $get_prev = "SELECT $prevDayClosing as prev_closing FROM $table 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $stmt = $conn->prepare($get_prev);
        $stmt->bind_param("ss", $monthYear, $itemCode);
        $stmt->execute();
        $prev_result = $stmt->get_result();
        $prev_data = $prev_result->fetch_assoc();
        $prev_closing = $prev_data['prev_closing'] ?? 0;
        $stmt->close();
        
        $update_query = "UPDATE $table 
                        SET $openingCol = ?,
                            $purchaseCol = 0,
                            $closingCol = ?
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iiss", $prev_closing, $prev_closing, $monthYear, $itemCode);
        $update_stmt->execute();
        $update_stmt->close();
    }
}

// Modified: Cascade through all future months from a starting point - now stops at financial year end
function cascadeAllFutureMonths($conn, $comp_id, $itemCode, $startDate) {
    $start_timestamp = strtotime($startDate);
    $start_month = (int)date('n', $start_timestamp);
    $start_year = (int)date('Y', $start_timestamp);
    $start_day = (int)date('j', $start_timestamp);
    $startMonthYear = date('Y-m', $start_timestamp);
    
    // Check if start date is within financial year
    if (!isWithinFinancialYear($start_month, $start_year, $conn, $comp_id)) {
        error_log("Attempted to cascade from date outside financial year: $startDate");
        return; // Exit if outside financial year
    }
    
    $current_month = (int)date('n');
    $current_year = (int)date('Y');
    $currentMonthYear = date('Y-m');
    
    // Get the correct table for the start month
    $start_table = ensureMonthTableExists($conn, $comp_id, $start_month, $start_year);
    
    // If start table is null (outside financial year), exit
    if ($start_table === null) {
        return;
    }
    
    // Cascade from start day to end of start month
    cascadeMonthFromDay($conn, $start_table, $itemCode, $startMonthYear, $start_day);
    
    // Get the last day's closing of the start month
    $carry_forward = getMonthEndClosing($conn, $start_table, $itemCode, $startMonthYear);
    
    // Process each subsequent month
    $next_month = $start_month + 1;
    $next_year = $start_year;
    if ($next_month > 12) {
        $next_month = 1;
        $next_year++;
    }
    
    // Get financial year end
    $fin_year_id = $_SESSION['FIN_YEAR_ID'];
    $fin_year_parts = explode('-', $fin_year_id);
    if (count($fin_year_parts) >= 2) {
        $fin_start_year = (int)$fin_year_parts[0];
        $fin_end_year_str = $fin_year_parts[1];
        
        // Handle both formats
        if (strlen($fin_end_year_str) == 2) {
            $century = substr($fin_start_year, 0, 2);
            $fin_end_year = (int)($century . $fin_end_year_str);
        } else {
            $fin_end_year = (int)$fin_end_year_str;
        }
        
        // Financial year end is March of the end year
        $fin_end_month = 3;
        $fin_end_year_for_comparison = $fin_end_year;
    } else {
        // If we can't determine, default to current year + 1
        $fin_end_month = 3;
        $fin_end_year_for_comparison = $start_year + 1;
    }
    
    // Process months until we hit current month or financial year end
    while (($next_year < $current_year || ($next_year == $current_year && $next_month <= $current_month)) && 
           ($next_year < $fin_end_year_for_comparison || ($next_year == $fin_end_year_for_comparison && $next_month <= $fin_end_month))) {
        
        // Check if this next month is within financial year
        if (!isWithinFinancialYear($next_month, $next_year, $conn, $comp_id)) {
            // Stop processing if we're outside financial year
            break;
        }
        
        $nextMonthYear = date('Y-m', strtotime("$next_year-$next_month-01"));
        $next_table = ensureMonthTableExists($conn, $comp_id, $next_month, $next_year);
        
        // If next table is null (outside financial year), break
        if ($next_table === null) {
            break;
        }
        
        $days_in_next_month = date('t', strtotime("$next_year-$next_month-01"));
        
        // First, check if record exists for this item
        $check_query = "SELECT COUNT(*) as count FROM $next_table 
                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ss", $nextMonthYear, $itemCode);
        $check_stmt->execute();
        $result = $check_stmt->get_result();
        $exists = $result->fetch_assoc()['count'] > 0;
        $check_stmt->close();
        
        if ($exists) {
            // Update first day's opening with carry forward from previous month
            $update_first = "UPDATE $next_table 
                            SET DAY_01_OPEN = ?,
                                DAY_01_CLOSING = ? + DAY_01_PURCHASE - DAY_01_SALES
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $stmt = $conn->prepare($update_first);
            $stmt->bind_param("iiss", $carry_forward, $carry_forward, $nextMonthYear, $itemCode);
            $stmt->execute();
            $stmt->close();
            
            // Cascade through the rest of the month
            cascadeMonthFromDay($conn, $next_table, $itemCode, $nextMonthYear, 2);
            
            // Get last day's closing for next month
            $carry_forward = getMonthEndClosing($conn, $next_table, $itemCode, $nextMonthYear);
        } else {
            // If record doesn't exist, create it with the carry forward
            $insert_query = "INSERT INTO $next_table 
                            (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                            VALUES (?, ?, 'F', ?, 0, 0, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssii", $nextMonthYear, $itemCode, $carry_forward, $carry_forward);
            $insert_stmt->execute();
            $insert_stmt->close();
            
            // For days 2 to end of month, keep them as carry_forward (no purchases/sales)
            for ($day = 2; $day <= $days_in_next_month; $day++) {
                $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $openingCol = "DAY_{$dayPadded}_OPEN";
                $closingCol = "DAY_{$dayPadded}_CLOSING";
                
                $update_day = "UPDATE $next_table 
                              SET $openingCol = ?, $closingCol = ?
                              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $stmt = $conn->prepare($update_day);
                $stmt->bind_param("iiss", $carry_forward, $carry_forward, $nextMonthYear, $itemCode);
                $stmt->execute();
                $stmt->close();
            }
            
            // carry_forward remains same for next month
        }
        
        // Move to next month
        $next_month++;
        if ($next_month > 12) {
            $next_month = 1;
            $next_year++;
        }
    }
}

// Modified: Remove stock from old date - now checks financial year
function removeStockFromDate($itemCode, $cases, $bottles, $freeCases, $freeBottles, $bottlesPerCase, $oldDate, $companyId, $conn) {
    // Use TotBott from the database instead of calculating
    // Since we don't have TotBott here, we need to get it from the existing item data
    global $existingItems;
    
    // Find the matching item in existingItems to get TotBott
    $totalBottles = 0;
    foreach ($existingItems as $existingItem) {
        if ($existingItem['ItemCode'] == $itemCode) {
            $totalBottles = (int)($existingItem['TotBott'] ?? 0);
            break;
        }
    }
    
    // If TotBott not found, calculate as fallback (but should not happen)
    if ($totalBottles <= 0) {
        $totalBottles = (($cases + $freeCases) * $bottlesPerCase) + $bottles + $freeBottles;
    }
    
    if ($totalBottles <= 0) return 0;
    
    $dayOfMonth = date('j', strtotime($oldDate));
    $month = date('n', strtotime($oldDate));
    $year = date('Y', strtotime($oldDate));
    $monthYear = date('Y-m', strtotime($oldDate));
    
    // Check if the date is within financial year
    if (!isWithinFinancialYear($month, $year, $conn, $companyId)) {
        error_log("Attempted to remove stock from date outside financial year: $oldDate");
        return 0; // Don't process if outside financial year
    }
    
    // Get the appropriate table
    $table = ensureMonthTableExists($conn, $companyId, $month, $year);
    
    // If table is null (outside financial year), return
    if ($table === null) {
        return 0;
    }
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
    $salesColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    // Check if record exists
    $check_query = "SELECT COUNT(*) as count, $purchaseColumn as current_purchase, $openingColumn as opening, $salesColumn as sales 
                   FROM $table 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $data = $result->fetch_assoc();
    $exists = $data['count'] > 0;
    $current_purchase = (int)($data['current_purchase'] ?? 0);
    $opening = (int)($data['opening'] ?? 0);
    $sales = (int)($data['sales'] ?? 0);
    $check_stmt->close();
    
    if ($exists) {
        // Reduce purchase by the exact amount
        $new_purchase = max(0, $current_purchase - $totalBottles);
        $new_closing = $opening + $new_purchase - $sales;
        
        $update_query = "UPDATE $table 
                        SET $purchaseColumn = ?,
                            $closingColumn = ?
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iiss", $new_purchase, $new_closing, $monthYear, $itemCode);
        $update_stmt->execute();
        $update_stmt->close();
    }
    
    return $totalBottles;
}

// Modified: Add stock at new date - now checks financial year
function addStockAtDate($itemCode, $cases, $bottles, $freeCases, $freeBottles, $bottlesPerCase, $newDate, $companyId, $conn, $totBott = null) {
    // Use provided TotBott or calculate
    $totalBottles = $totBott;
    if ($totalBottles === null) {
        $totalBottles = (($cases + $freeCases) * $bottlesPerCase) + $bottles + $freeBottles;
    }
    
    if ($totalBottles <= 0) return 0;
    
    $dayOfMonth = date('j', strtotime($newDate));
    $month = date('n', strtotime($newDate));
    $year = date('Y', strtotime($newDate));
    $monthYear = date('Y-m', strtotime($newDate));
    
    // Check if the date is within financial year
    if (!isWithinFinancialYear($month, $year, $conn, $companyId)) {
        error_log("Attempted to add stock to date outside financial year: $newDate");
        return 0; // Don't process if outside financial year
    }
    
    // Get the appropriate table
    $table = ensureMonthTableExists($conn, $companyId, $month, $year);
    
    // If table is null (outside financial year), return
    if ($table === null) {
        return 0;
    }
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
    $salesColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    // Check if record exists
    $check_query = "SELECT COUNT(*) as count, $purchaseColumn as current_purchase, $openingColumn as opening, $salesColumn as sales 
                   FROM $table 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $data = $result->fetch_assoc();
    $exists = $data['count'] > 0;
    $current_purchase = (int)($data['current_purchase'] ?? 0);
    $opening = (int)($data['opening'] ?? 0);
    $sales = (int)($data['sales'] ?? 0);
    $check_stmt->close();
    
    if ($exists) {
        // Add purchase
        $new_purchase = $current_purchase + $totalBottles;
        $new_closing = $opening + $new_purchase - $sales;
        
        $update_query = "UPDATE $table 
                        SET $purchaseColumn = ?,
                            $closingColumn = ?
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iiss", $new_purchase, $new_closing, $monthYear, $itemCode);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new record
        $insert_query = "INSERT INTO $table 
                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $salesColumn, $closingColumn) 
                        VALUES (?, ?, 'F', 0, ?, 0, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    return $totalBottles;
}

// Function to update main stock
function updateMainStock($conn, $companyId, $itemCode, $changeAmount, $isAddition = true) {
    $stockColumn = "CURRENT_STOCK" . $companyId;
    
    // Check if column exists
    $check_col_query = "SHOW COLUMNS FROM tblitem_stock LIKE '$stockColumn'";
    $check_col_result = $conn->query($check_col_query);
    
    if (!$check_col_result || $check_col_result->num_rows == 0) {
        return false;
    }
    
    // Check if record exists
    $check_query = "SELECT COUNT(*) as count, $stockColumn as current_stock FROM tblitem_stock WHERE ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $data = $result->fetch_assoc();
    $exists = $data['count'] > 0;
    $current_stock = (int)($data['current_stock'] ?? 0);
    $check_stmt->close();
    
    if ($exists) {
        $new_stock = $isAddition ? $current_stock + $changeAmount : max(0, $current_stock - $changeAmount);
        $update_query = "UPDATE tblitem_stock 
                        SET $stockColumn = ?,
                            LAST_UPDATED = NOW()
                        WHERE ITEM_CODE = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("is", $new_stock, $itemCode);
        $stmt->execute();
        $stmt->close();
    } else if ($isAddition) {
        // Only insert if adding stock (removing from non-existent record shouldn't happen)
        $insert_query = "INSERT INTO tblitem_stock (ITEM_CODE, $stockColumn) VALUES (?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("si", $itemCode, $changeAmount);
        $stmt->execute();
        $stmt->close();
    }
    
    return true;
}

// Function to create archive table
function createArchiveTable($conn, $table_name, $days_in_month) {
    $create_query = "CREATE TABLE IF NOT EXISTS $table_name (
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
    
    return $conn->query($create_query);
}

// Function to update MRP in tblitemmaster
function updateItemMRP($conn, $itemCode, $mrp) {
    // Clean the item code by removing SCM prefix
    $cleanCode = preg_replace('/^SCM/i', '', trim($itemCode));
    
    // Update MPRICE in tblitemmaster
    $updateQuery = "UPDATE tblitemmaster SET MPRICE = ? WHERE CODE = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("ss", $mrp, $cleanCode);
    $result = $stmt->execute();
    $stmt->close();
    
    return $result;
}

// ---- Items (for case rate lookup & modal) - FILTERED BY LICENSE TYPE ONLY ----
$items = [];

if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    // NOTE: Removed LIQ_FLAG filter to match purchases.php behavior
    // This ensures all items for the allowed classes are shown
    $itemsQuery = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.ITEM_GROUP, im.LIQ_FLAG, im.CLASS,
                          COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE,
                          CONCAT('SCM', im.CODE) AS SCM_CODE
                     FROM tblitemmaster im
                     LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
                    WHERE im.CLASS IN ($class_placeholders)
                 ORDER BY im.DETAILS";
    
    $params = $allowed_classes;
    $types = str_repeat('s', count($params));
    
    $itemsStmt = $conn->prepare($itemsQuery);
    $itemsStmt->bind_param($types, ...$params);
    $itemsStmt->execute();
    $itemsResult = $itemsStmt->get_result();
    if ($itemsResult) $items = $itemsResult->fetch_all(MYSQLI_ASSOC);
    $itemsStmt->close();
}

// ---- Suppliers (for name/code replacement) ----
$suppliers = [];
$suppliersStmt = $conn->prepare("SELECT CODE, DETAILS FROM tblsupplier ORDER BY DETAILS");
$suppliersStmt->execute();
$suppliersResult = $suppliersStmt->get_result();
if ($suppliersResult) $suppliers = $suppliersResult->fetch_all(MYSQLI_ASSOC);
$suppliersStmt->close();

// ---- Save purchase update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $newDate = $_POST['date'];
    $oldDate = $purchase['DATE']; // Original date
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
    
    // Preserve the original PUR_FLAG
    $pur_flag = $purchase['PUR_FLAG'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Validate that both old and new dates are within financial year
        if (!isWithinFinancialYear(date('n', strtotime($oldDate)), date('Y', strtotime($oldDate)), $conn, $companyId) ||
            !isWithinFinancialYear(date('n', strtotime($newDate)), date('Y', strtotime($newDate)), $conn, $companyId)) {
            throw new Exception("Purchase date must be within the current financial year");
        }
        
        // ===== STEP 1: REMOVE ALL STOCK FROM OLD DATE FOR ALL ITEMS =====
        $removedQtys = [];
        foreach ($existingItems as $existingItem) {
            $removedQty = removeStockFromDate(
                $existingItem['ItemCode'],
                $existingItem['Cases'],
                $existingItem['Bottles'],
                $existingItem['FreeCases'],
                $existingItem['FreeBottles'],
                $existingItem['BottlesPerCase'],
                $oldDate,
                $companyId,
                $conn
            );
            
            if ($removedQty > 0) {
                $removedQtys[$existingItem['ItemCode']] = ($removedQtys[$existingItem['ItemCode']] ?? 0) + $removedQty;
            }
        }
        
        // ===== STEP 2: UPDATE MAIN STOCK - REMOVE OLD QUANTITIES =====
        foreach ($removedQtys as $itemCode => $removedQty) {
            updateMainStock($conn, $companyId, $itemCode, $removedQty, false);
        }
        
        // ===== STEP 3: CASCADE FROM OLD DATE AFTER ALL REMOVALS =====
        $oldItemCodes = array_keys($removedQtys);
        foreach ($oldItemCodes as $itemCode) {
            cascadeAllFutureMonths($conn, $companyId, $itemCode, $oldDate);
        }
        
        // ===== STEP 4: UPDATE PURCHASE HEADER =====
        $updateQuery = "UPDATE tblpurchases SET
            DATE = ?, SUBCODE = ?, AUTO_TPNO = ?, INV_NO = ?, INV_DATE = ?, TAMT = ?,
            TPNO = ?, TP_DATE = ?, SCHDIS = ?, CASHDIS = ?, OCTROI = ?, FREIGHT = ?, 
            STAX_PER = ?, STAX_AMT = ?, TCS_PER = ?, TCS_AMT = ?, MISC_CHARG = ?, PUR_FLAG = ?
            WHERE ID = ? AND CompID = ?";

        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param(
            "ssssssssdddddddddsii",
            $newDate, $supplier_code, $auto_tp_no, $inv_no, $inv_date, $tamt,
            $tp_no, $tp_date, $trade_disc, $cash_disc, $octroi, $freight, 
            $stax_per, $stax_amt, $tcs_per, $tcs_amt, $misc_charg, $pur_flag,
            $purchaseId, $companyId
        );
        
        if (!$updateStmt->execute()) {
            throw new Exception("Error updating purchase: " . $updateStmt->error);
        }
        $updateStmt->close();
        
        // ===== STEP 5: DELETE EXISTING PURCHASE ITEMS =====
        $deleteQuery = "DELETE FROM tblpurchasedetails WHERE PurchaseID = ?";
        $deleteStmt = $conn->prepare($deleteQuery);
        $deleteStmt->bind_param("i", $purchaseId);
        if (!$deleteStmt->execute()) {
            throw new Exception("Error deleting existing items: " . $deleteStmt->error);
        }
        $deleteStmt->close();
        
        // ===== STEP 6: INSERT UPDATED PURCHASE ITEMS =====
        if (isset($_POST['items']) && is_array($_POST['items']) && !empty($_POST['items'])) {
            $detailValues = [];
            $mrpUpdates = [];
            $newItems = []; // Store new items for later processing
            $newItemQtys = []; // Track total quantities for each item
            
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
                $detailValues[] = "($purchaseId, '$item_code_esc', '$item_name_esc', '$item_size_esc', $cases, $bottles, $free_cases, $free_bottles, $case_rate, $mrp, $amount, $bottles_per_case, '$batch_no_esc', '$auto_batch_esc', '$mfg_month_esc', $bl, $vv, $tot_bott)";
                
                // Collect MRP updates
                if ($mrp > 0) {
                    $mrpUpdates[$item_code] = $mrp;
                }
                
                // Store new item for stock addition
                if ($tot_bott > 0) {
                    $newItems[] = [
                        'code' => $item_code,
                        'cases' => $cases,
                        'bottles' => $bottles,
                        'free_cases' => $free_cases,
                        'free_bottles' => $free_bottles,
                        'bottles_per_case' => $bottles_per_case,
                        'tot_bott' => $tot_bott
                    ];
                    
                    // Track total quantity for each item
                    $newItemQtys[$item_code] = ($newItemQtys[$item_code] ?? 0) + $tot_bott;
                }
            }
            
            // Bulk insert all purchase details
            if (!empty($detailValues)) {
                $detailBulkQuery = "INSERT INTO tblpurchasedetails (
                    PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, FreeCases, FreeBottles, 
                    CaseRate, MRP, Amount, BottlesPerCase, BatchNo, AutoBatch, MfgMonth, BL, VV, TotBott
                ) VALUES " . implode(',', $detailValues);
                
                if (!$conn->query($detailBulkQuery)) {
                    throw new Exception("Bulk insert failed: " . $conn->error);
                }
            }
            
            // Bulk update MRP
            if (!empty($mrpUpdates)) {
                foreach ($mrpUpdates as $code => $mrp) {
                    updateItemMRP($conn, $code, $mrp);
                }
            }
            
            // ===== STEP 7: ADD STOCK AT NEW DATE FOR ALL ITEMS =====
            $addedQtys = [];
            foreach ($newItems as $item) {
                $addedQty = addStockAtDate(
                    $item['code'],
                    $item['cases'],
                    $item['bottles'],
                    $item['free_cases'],
                    $item['free_bottles'],
                    $item['bottles_per_case'],
                    $newDate,
                    $companyId,
                    $conn,
                    $item['tot_bott']
                );
                
                if ($addedQty > 0) {
                    $addedQtys[$item['code']] = ($addedQtys[$item['code']] ?? 0) + $addedQty;
                }
            }
            
            // ===== STEP 8: UPDATE MAIN STOCK - ADD NEW QUANTITIES =====
            foreach ($addedQtys as $itemCode => $addedQty) {
                updateMainStock($conn, $companyId, $itemCode, $addedQty, true);
            }
            
            // ===== STEP 9: CASCADE FROM NEW DATE AFTER ALL ADDITIONS =====
            $newItemCodes = array_keys($addedQtys);
            foreach ($newItemCodes as $itemCode) {
                cascadeAllFutureMonths($conn, $companyId, $itemCode, $newDate);
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        // Re-enable constraints
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->query("SET UNIQUE_CHECKS = 1");
        
        header("Location: purchase_module.php?mode=".$mode."&success=1");
        exit;
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->query("SET UNIQUE_CHECKS = 1");
        $errorMessage = "Error updating purchase: " . $e->getMessage();
        error_log("Purchase update failed - ID: $purchaseId, Error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Edit Purchase</title>
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

/* Status indicator for PUR_FLAG */
.pur-flag-indicator {
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: bold;
}
.pur-flag-A { background-color: #d4edda; color: #155724; }
.pur-flag-F { background-color: #cce7ff; color: #004085; }
.pur-flag-T { background-color: #fff3cd; color: #856404; }
.pur-flag-P { background-color: #d1ecf1; color: #0c5460; }
.pur-flag-C { background-color: #d1edff; color: #004085; }
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <div class="position-relative">
        <h4 class="mb-3">Edit Purchase</h4>
        <span class="pur-flag-indicator pur-flag-<?= $purchase['PUR_FLAG'] ?>">
          PUR_FLAG: <?= $purchase['PUR_FLAG'] ?>
        </span>
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

      <form method="POST" id="purchaseForm">
        <input type="hidden" name="mode" value="<?=htmlspecialchars($mode)?>">
        <input type="hidden" name="voc_no" value="<?=$purchase['VOC_NO']?>">

        <!-- HEADER -->
        <div class="card mb-4">
          <div class="card-header fw-semibold"><i class="fa-solid fa-receipt me-2"></i>Purchase Information</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label">Voucher No.</label>
                <input class="form-control" value="<?=$purchase['VOC_NO']?>" disabled>
              </div>
              <div class="col-md-3">
                <label class="form-label">Date</label>
                <input type="date" class="form-control" name="date" value="<?=htmlspecialchars($purchase['DATE'])?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Auto TP No.</label>
                <input type="text" class="form-control" name="auto_tp_no" id="autoTpNo" value="<?=htmlspecialchars($purchase['AUTO_TPNO'] ?? '')?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">T.P. No.</label>
                <input type="text" class="form-control" name="tp_no" id="tpNo" value="<?=htmlspecialchars($purchase['TPNO'] ?? '')?>">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-md-3">
                <label class="form-label">T.P. Date</label>
                <input type="date" class="form-control" name="tp_date" id="tpDate" value="<?=htmlspecialchars($purchase['TP_DATE'] ?? '')?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Invoice No.</label>
                <input type="text" class="form-control" name="inv_no" value="<?=htmlspecialchars($purchase['INV_NO'] ?? '')?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Invoice Date</label>
                <input type="date" class="form-control" name="inv_date" value="<?=htmlspecialchars($purchase['INV_DATE'] ?? '')?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Supplier</label>
                <div class="supplier-container">
                  <input type="text" class="form-control" name="supplier_name" id="supplierInput" 
                         value="<?=htmlspecialchars($purchase['supplier_name'] ?? '')?>" placeholder="Type supplier name" required>
                  <div class="supplier-suggestions" id="supplierSuggestions"></div>
                </div>
                <select class="form-select mt-1" id="supplierSelect">
                  <option value="">Select Supplier</option>
                  <?php foreach($suppliers as $s): ?>
                    <option value="<?=htmlspecialchars($s['DETAILS'])?>"
                            data-code="<?=htmlspecialchars($s['CODE'])?>"
                            <?=($s['CODE'] == $purchase['SUBCODE']) ? 'selected' : ''?>>
                      <?=htmlspecialchars($s['DETAILS'])?> (<?=htmlspecialchars($s['CODE'])?>)
                    </option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="supplier_code" id="supplierCodeHidden" value="<?=htmlspecialchars($purchase['SUBCODE'])?>">
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
                  <?php if (empty($existingItems)): ?>
                    <tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>
                  <?php endif; ?>
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
              <div class="col-md-3">
                <label class="form-label">Cash Discount</label>
                <input type="number" step="0.01" class="form-control" name="cash_disc" value="<?=htmlspecialchars($purchase['CASHDIS'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Trade Discount</label>
                <input type="number" step="0.01" class="form-control" name="trade_disc" value="<?=htmlspecialchars($purchase['SCHDIS'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Octroi</label>
                <input type="number" step="0.01" class="form-control" name="octroi" value="<?=htmlspecialchars($purchase['OCTROI'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Freight Charges</label>
                <input type="number" step="0.01" class="form-control" name="freight" value="<?=htmlspecialchars($purchase['FREIGHT'] ?? 0)?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-3">
                <label class="form-label">Sales Tax (%)</label>
                <input type="number" step="0.01" class="form-control" name="stax_per" value="<?=htmlspecialchars($purchase['STAX_PER'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Sales Tax Amount</label>
                <input type="number" step="0.01" class="form-control" name="stax_amt" value="<?=htmlspecialchars($purchase['STAX_AMT'] ?? 0)?>" readonly>
              </div>
              <div class="col-md-3">
                <label class="form-label">TCS (%)</label>
                <input type="number" step="0.01" class="form-control" name="tcs_per" value="<?=htmlspecialchars($purchase['TCS_PER'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">TCS Amount</label>
                <input type="number" step="0.01" class="form-control" name="tcs_amt" value="<?=htmlspecialchars($purchase['TCS_AMT'] ?? 0)?>" readonly>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-3">
                <label class="form-label">Misc. Charges</label>
                <input type="number" step="0.01" class="form-control" name="misc_charg" value="<?=htmlspecialchars($purchase['MISC_CHARG'] ?? 0)?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Basic Amount</label>
                <input type="number" step="0.01" class="form-control" name="basic_amt" value="<?=htmlspecialchars($purchase['TAMT'] ?? 0)?>" readonly>
              </div>
              <div class="col-md-3">
                <label class="form-label">Total Amount</label>
                <input type="number" step="0.01" class="form-control" name="tamt" value="<?=htmlspecialchars($purchase['TAMT'] ?? 0)?>" readonly>
              </div>
            </div>
          </div>
        </div>

        <div class="d-flex gap-2">
          <a href="purchase_module.php?mode=<?=$mode?>" class="btn btn-secondary"><i class="fa-solid fa-arrow-left"></i> Back</a>
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Update Purchase</button>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- ITEM PICKER MODAL -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Select Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input class="form-control mb-2" id="itemSearch" placeholder="Search items...">
        <div class="table-container">
          <table class="styled-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Item</th>
                <th>Size</th>
                <th>Price</th>
                <th>Bottles/Case</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="itemsModalTable">
              <?php foreach($items as $it): ?>
                <tr class="item-row-modal">
                  <td><?=htmlspecialchars($it['CODE'])?></td>
                  <td><?=htmlspecialchars($it['DETAILS'])?></td>
                  <td><?=htmlspecialchars($it['DETAILS2'])?></td>
                  <td><?=number_format((float)$it['PPRICE'],3)?></td>
                  <td><?=htmlspecialchars($it['BOTTLE_PER_CASE'])?></td>
                  <td>
                    <button type="button" class="btn btn-sm btn-primary select-item"
                        data-code="<?=htmlspecialchars($it['CODE'])?>"
                        data-name="<?=htmlspecialchars($it['DETAILS'])?>"
                        data-size="<?=htmlspecialchars($it['DETAILS2'])?>"
                        data-price="<?=htmlspecialchars($it['PPRICE'])?>"
                        data-bottles-per-case="<?=htmlspecialchars($it['BOTTLE_PER_CASE'])?>">
                      Select
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function(){
  let itemCount = <?= count($existingItems) ?>;
  const dbItems = <?=json_encode($items, JSON_UNESCAPED_UNICODE)?>;
  const suppliers = <?=json_encode($suppliers, JSON_UNESCAPED_UNICODE)?>;
  const distinctSizes = <?=json_encode($distinctSizes, JSON_UNESCAPED_UNICODE)?>;
  const existingItems = <?=json_encode($existingItems, JSON_UNESCAPED_UNICODE)?>;

  // ---------- Helper Functions (from purchases.php) ----------
  // Helper function to convert size text to ML value for proper matching
  function parseSizeToML(sizeText) {
    if (!sizeText) return null;
    
    const str = sizeText.toString().toUpperCase().trim();
    
    // Check if it contains "L" (liters)
    if (str.includes('L')) {
      const match = str.match(/([\d.]+)\s*L/);
      if (match) {
        const liters = parseFloat(match[1]);
        return Math.round(liters * 1000); // Convert to ML
      }
    }
    
    // Check if it contains "ML"
    if (str.includes('ML')) {
      const match = str.match(/(\d+)\s*ML/);
      if (match) {
        return parseInt(match[1]);
      }
    }
    
    // Fallback: just extract any number
    const match = str.match(/(\d+)/);
    if (match) {
      return parseInt(match[1]);
    }
    
    return null;
  }

  function formatBottleSize(sizeText) {
    if (!sizeText) return '';

    const match = sizeText.toString().match(/(\d+(?:\.\d+)?)/);
    if (!match) return sizeText;

    const sizeNum = parseFloat(match[1]);

    if (sizeNum >= 1000) {
      const liters = sizeNum / 1000;
      return liters % 1 === 0 ? `${liters}L` : `${liters.toFixed(1)}L`;
    } else {
      return `${sizeNum}ML`;
    }
  }

  function calculateBL(sizeText, totalBottles) {
    if (!sizeText || !totalBottles) return 0;

    const sizeMatch = sizeText.match(/(\d+)/);
    if (!sizeMatch) return 0;

    const sizeML = parseInt(sizeMatch[1]);
    return (sizeML * totalBottles) / 1000;
  }

  function calculateTotalBottles(cases, bottles, bottlesPerCase) {
    return (cases * bottlesPerCase) + parseInt(bottles || 0);
  }

  function calculateAmount(cases, bottles, caseRate, bottlesPerCase) {
    if (bottlesPerCase <= 0) bottlesPerCase = 1;
    if (caseRate < 0) caseRate = 0;
    cases = Math.max(0, cases || 0);
    bottles = Math.max(0, bottles || 0);
    
    const fullCaseAmount = cases * caseRate;
    const bottleRate = caseRate / bottlesPerCase;
    const individualBottleAmount = bottles * bottleRate;
    
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

  function updateRowCalculations(row) {
    const cases = parseFloat(row.find('.cases').val()) || 0;
    const bottles = parseFloat(row.find('.bottles').val()) || 0;
    const freeCases = parseFloat(row.find('.free-cases').val()) || 0;
    const freeBottles = parseFloat(row.find('.free-bottles').val()) || 0;
    const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
    const size = row.find('input[name*="[size]"]').val() || '';
    
    // Calculate total bottles including free items
    const totalBottles = calculateTotalBottles(cases + freeCases, bottles + freeBottles, bottlesPerCase);
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
        // Use the new helper function to properly parse sizes like "1L", "1.5L", "750ML"
        const sizeValue = parseSizeToML(sizeText);
        
        if (sizeValue !== null) {
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

  function updateTotals() {
    let totalAmount = 0;
    $('.item-row .amount').each(function() { 
      totalAmount += parseFloat($(this).text()) || 0; 
    });
    
    $('#totalAmount').text(totalAmount.toFixed(2));
    $('input[name="basic_amt"]').val(totalAmount.toFixed(2));
    
    const tradeDiscount = calculateTradeDiscount();
    $('input[name="trade_disc"]').val(tradeDiscount.toFixed(2));
    
    updateColumnTotals();
    updateBottlesBySizeDisplay();
    calcTaxes();
  }

  function calcTaxes() {
    const basic = parseFloat($('input[name="basic_amt"]').val()) || 0;
    const staxp = parseFloat($('input[name="stax_per"]').val()) || 0;
    const tcsp  = parseFloat($('input[name="tcs_per"]').val()) || 0;
    const cash  = parseFloat($('input[name="cash_disc"]').val()) || 0;
    const trade = parseFloat($('input[name="trade_disc"]').val()) || 0;
    const oct   = parseFloat($('input[name="octroi"]').val()) || 0;
    const fr    = parseFloat($('input[name="freight"]').val()) || 0;
    const misc  = parseFloat($('input[name="misc_charg"]').val()) || 0;
    
    const stax  = basic * staxp / 100;
    const tcs   = basic * tcsp / 100;
    
    $('input[name="stax_amt"]').val(stax.toFixed(2));
    $('input[name="tcs_amt"]').val(tcs.toFixed(2));
    
    const grand = basic + stax + tcs + oct + fr + misc - cash - trade;
    $('input[name="tamt"]').val(grand.toFixed(2));
  }

  // ---------- Add Row Function (exactly like purchases.php) ----------
  function addRow(item){
    const dbItem = item.dbItem || null;
    
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
    
    const totalBottles = item.totBott || calculateTotalBottles(cases + freeCases, bottles + freeBottles, bottlesPerCase);
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

  // ---------- Load Existing Items ----------
  if (existingItems && existingItems.length > 0) {
    existingItems.forEach(function(item) {
      addRow({
        dbItem: {
          CODE: item.ItemCode,
          DETAILS: item.ItemName,
          DETAILS2: formatBottleSize(item.Size),
          PPRICE: parseFloat(item.CaseRate) || 0,
          BOTTLE_PER_CASE: parseInt(item.BottlesPerCase) || 12,
          CLASS: '' // We don't have CLASS in existing items, but it's okay
        },
        code: item.ItemCode,
        name: item.ItemName,
        size: formatBottleSize(item.Size),
        cases: parseFloat(item.Cases) || 0,
        bottles: parseInt(item.Bottles) || 0,
        freeCases: parseFloat(item.FreeCases) || 0,
        freeBottles: parseInt(item.FreeBottles) || 0,
        caseRate: parseFloat(item.CaseRate) || 0,
        mrp: parseFloat(item.MRP) || 0,
        batchNo: item.BatchNo || '',
        autoBatch: item.AutoBatch || '',
        mfgMonth: item.MfgMonth || '',
        vv: parseFloat(item.VV) || 0,
        bl: parseFloat(item.BL) || 0,
        totBott: parseInt(item.TotBott) || 0
      });
    });
  }

  // ---------- Event Listeners ----------
  $('#addItem').on('click', function(){
    $('#itemModal').modal('show');
  });

  $('#itemSearch').on('input', function(){
    const v = this.value.toLowerCase();
    $('.item-row-modal').each(function(){
      $(this).toggle($(this).text().toLowerCase().includes(v));
    });
  });

  $(document).on('click', '.select-item', function(){
    const data = $(this).data();
    
    addRow({
      dbItem: {
        CODE: data.code,
        DETAILS: data.name,
        DETAILS2: data.size,
        PPRICE: parseFloat(data.price) || 0,
        BOTTLE_PER_CASE: data.bottlesPerCase || 12,
        CLASS: '' // This will be checked by license filter
      },
      code: data.code,
      name: data.name,
      size: data.size,
      cases: 0,
      bottles: 0,
      freeCases: 0,
      freeBottles: 0,
      caseRate: parseFloat(data.price) || 0,
      mrp: 0,
      batchNo: '',
      autoBatch: '',
      mfgMonth: '',
      vv: 0,
      bottles_per_case: data.bottlesPerCase || 12
    });
    
    $('#itemModal').modal('hide');
    $('#itemSearch').val('').trigger('input');
  });

  $(document).on('input', '.cases, .bottles, .case-rate, .free-cases, .free-bottles', function(){
    const row = $(this).closest('tr');
    const cases = parseFloat(row.find('.cases').val()) || 0;
    const bottles = parseFloat(row.find('.bottles').val()) || 0;
    const freeCases = parseFloat(row.find('.free-cases').val()) || 0;
    const freeBottles = parseFloat(row.find('.free-bottles').val()) || 0;
    const rate = parseFloat(row.find('.case-rate').val()) || 0;
    const bottlesPerCase = parseInt(row.data('bottles-per-case')) || 12;
    
    // Calculate amount including free items in total bottles but not in amount
    const amount = calculateAmount(cases, bottles, rate, bottlesPerCase);
    row.find('.amount').text(amount.toFixed(2));
    
    // Update total bottles including free items for BL calculation
    const totalBottles = calculateTotalBottles(cases + freeCases, bottles + freeBottles, bottlesPerCase);
    const size = row.find('input[name*="[size]"]').val() || '';
    const blValue = calculateBL(size, totalBottles);
    
    row.find('.tot-bott-value').text(totalBottles);
    row.find('.bl-value').text(blValue.toFixed(2));
    
    row.find('input[name*="[tot_bott]"]').val(totalBottles);
    row.find('input[name*="[bl]"]').val(blValue.toFixed(2));
    
    updateTotals();
  });

  $(document).on('click', '.remove-item', function(){
    $(this).closest('tr').remove();
    if($('.item-row').length === 0){
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

  $('#clearItems').on('click', function(){
    if (confirm('Are you sure you want to clear all items?')) {
      $('.item-row').remove();
      $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>');
      $('#totalAmount').text('0.00');
      $('input[name="basic_amt"]').val('0.00');
      $('input[name="tamt"]').val('0.00');
      $('input[name="trade_disc"]').val('0.00');
      
      $('#totalCases, #totalBottles, #totalFreeCases, #totalFreeBottles, #totalBL, #totalTotBott').text('0');
      updateBottlesBySizeDisplay();
    }
  });

  $('input[name="stax_per"], input[name="tcs_per"], input[name="cash_disc"], input[name="trade_disc"], input[name="octroi"], input[name="freight"], input[name="misc_charg"]').on('input', function(){
    calcTaxes();
  });

  // Supplier UI
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
    if(q.length < 2){ 
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

  $(document).on('click', '.supplier-suggestion', function(){
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

  // Form submission validation
  $('#purchaseForm').on('submit', function(e) {
    if ($('.item-row').length === 0) {
      alert('Please add at least one item before saving.');
      e.preventDefault();
      return false;
    }
  });

  // Initialize
  initializeSizeTable();
  if ($('.item-row').length === 0) {
    $('#itemsTable tbody').html('<tr id="noItemsRow"><td colspan="17" class="text-center text-muted">No items added</td></tr>');
  } else {
    updateTotals();
  }
});
</script>
</body>
</html>
<?php
$conn->close();
?>