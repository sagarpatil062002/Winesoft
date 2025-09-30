<?php
session_start();

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php"; // MySQLi connection in $conn

// Get company ID from session
$compID = $_SESSION['CompID'];

// Fetch current financial year from database
$financial_year = [];
$finyearQuery = "SELECT ID, START_DATE, END_DATE FROM tblfinyear WHERE ACTIVE = 1 LIMIT 1";
$finyearResult = $conn->query($finyearQuery);
if ($finyearResult->num_rows > 0) {
    $financial_year = $finyearResult->fetch_assoc();
} else {
    // Fallback to current year if no active financial year found
    $current_year = date('Y');
    $financial_year = [
        'START_DATE' => ($current_year - 1) . '-04-01 00:00:00',
        'END_DATE' => $current_year . '-03-31 23:59:59'
    ];
}

// Extract year from start date for display
$year = date('Y', strtotime($financial_year['START_DATE']));
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Fetch company name and license number
$companyName = "DIAMOND WINE SHOP";
$licenseNo = "3";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
    $licenseNo = $row['COMP_FLNO'] ? $row['COMP_FLNO'] : $licenseNo;
}
$companyStmt->close();

// Function to group sizes by base size (remove suffixes after ML and trim)
function getBaseSize($size) {
    // Extract the base size (everything before any special characters after ML)
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize); // Remove trailing - numbers
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize); // Remove trailing (numbers)
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize); // Remove anything in parentheses
    return trim($baseSize);
}

// Define size columns for each liquor type exactly as they appear in FLR Datewise
$size_columns_s = [
    '2000 ML Pet (6)', '2000 ML(4)', '2000 ML(6)', '1000 ML(Pet)', '1000 ML',
    '750 ML(6)', '750 ML (Pet)', '750 ML', '700 ML', '700 ML(6)',
    '375 ML (12)', '375 ML', '375 ML (Pet)', '350 ML (12)', '275 ML(24)',
    '200 ML (48)', '200 ML (24)', '200 ML (30)', '200 ML (12)', '180 ML(24)',
    '180 ML (Pet)', '180 ML', '90 ML(100)', '90 ML (Pet)-100', '90 ML (Pet)-96', 
    '90 ML-(96)', '90 ML', '60 ML', '60 ML (75)', '50 ML(120)', '50 ML (180)', 
    '50 ML (24)', '50 ML (192)'
];
$size_columns_w = ['750 ML(6)', '750 ML', '650 ML', '375 ML', '330 ML', '180 ML'];
$size_columns_fb = ['650 ML', '500 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];
$size_columns_mb = ['650 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];

// Group sizes by base size for each liquor type
function groupSizes($sizes) {
    $grouped = [];
    foreach ($sizes as $size) {
        $baseSize = getBaseSize($size);
        if (!isset($grouped[$baseSize])) {
            $grouped[$baseSize] = [];
        }
        $grouped[$baseSize][] = $size;
    }
    return $grouped;
}

$grouped_sizes_s = groupSizes($size_columns_s);
$grouped_sizes_w = groupSizes($size_columns_w);
$grouped_sizes_fb = groupSizes($size_columns_fb);
$grouped_sizes_mb = groupSizes($size_columns_mb);

// Get display sizes (base sizes) for each liquor type - ORDER: Spirit, Wine, Fermented Beer, Mild Beer
$display_sizes_s = array_keys($grouped_sizes_s);
$display_sizes_w = array_keys($grouped_sizes_w);
$display_sizes_fb = array_keys($grouped_sizes_fb);
$display_sizes_mb = array_keys($grouped_sizes_mb);

// Fetch class data to map liquor types
$classData = [];
$classQuery = "SELECT SGROUP, `DESC`, LIQ_FLAG FROM tblclass";
$classStmt = $conn->prepare($classQuery);
$classStmt->execute();
$classResult = $classStmt->get_result();
while ($row = $classResult->fetch_assoc()) {
    $classData[$row['SGROUP']] = $row;
}
$classStmt->close();

// Fetch item master data with size information
$items = [];
$itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster";
$itemStmt = $conn->prepare($itemQuery);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();
while ($row = $itemResult->fetch_assoc()) {
    $items[$row['CODE']] = $row;
}
$itemStmt->close();

// Initialize yearly data structure - ORDER: Spirit, Wine, Fermented Beer, Mild Beer
$yearly_data = [
    'Spirits' => [
        'opening' => array_fill_keys($display_sizes_s, 0),
        'received' => array_fill_keys($display_sizes_s, 0),
        'total' => array_fill_keys($display_sizes_s, 0),
        'sold' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0),
        'breakages' => array_fill_keys($display_sizes_s, 0)
    ],
    'Wines' => [
        'opening' => array_fill_keys($display_sizes_w, 0),
        'received' => array_fill_keys($display_sizes_w, 0),
        'total' => array_fill_keys($display_sizes_w, 0),
        'sold' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0),
        'breakages' => array_fill_keys($display_sizes_w, 0)
    ],
    'Fermented Beer' => [
        'opening' => array_fill_keys($display_sizes_fb, 0),
        'received' => array_fill_keys($display_sizes_fb, 0),
        'total' => array_fill_keys($display_sizes_fb, 0),
        'sold' => array_fill_keys($display_sizes_fb, 0),
        'closing' => array_fill_keys($display_sizes_fb, 0),
        'breakages' => array_fill_keys($display_sizes_fb, 0)
    ],
    'Mild Beer' => [
        'opening' => array_fill_keys($display_sizes_mb, 0),
        'received' => array_fill_keys($display_sizes_mb, 0),
        'total' => array_fill_keys($display_sizes_mb, 0),
        'sold' => array_fill_keys($display_sizes_mb, 0),
        'closing' => array_fill_keys($display_sizes_mb, 0),
        'breakages' => array_fill_keys($display_sizes_mb, 0)
    ]
];

// Map database sizes to Excel column sizes
$size_mapping = [
    // Spirits
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '90 ML' => '90 ML',
    '90 ML-100' => '90 ML',
    '90 ML-96' => '90 ML',
    '2000 ML' => '2000 ML',
    '2000 ML Pet' => '2000 ML',
    
    // Wines
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    
    // Fermented Beer
    '650 ML Bottle' => '650 ML',
    '500 ML Bottle' => '500 ML',
    '500 ML Can' => '500 ML (CAN)',
    '330 ML Bottle' => '330 ML',
    '330 ML Can' => '330 ML (CAN)',
    
    // Mild Beer
    '650 ML Bottle' => '650 ML',
    '500 ML Can' => '500 ML (CAN)',
    '330 ML Bottle' => '330 ML',
    '330 ML Can' => '330 ML (CAN)'
];

// Function to determine liquor type based on CLASS and LIQ_FLAG
function getLiquorType($class, $liq_flag) {
    if ($liq_flag == 'F') {
        switch ($class) {
            case 'F': return 'Fermented Beer';
            case 'M': return 'Mild Beer';
            case 'V': return 'Wines';
            default: return 'Spirits';
        }
    }
    return 'Spirits'; // Default for non-F items
}

// Function to get base size for grouping
function getGroupedSize($size, $liquor_type) {
    global $grouped_sizes_s, $grouped_sizes_w, $grouped_sizes_fb, $grouped_sizes_mb;
    
    $baseSize = getBaseSize($size);
    
    // Check if this base size exists in the appropriate group
    switch ($liquor_type) {
        case 'Spirits':
            if (in_array($baseSize, array_keys($grouped_sizes_s))) {
                return $baseSize;
            }
            break;
        case 'Wines':
            if (in_array($baseSize, array_keys($grouped_sizes_w))) {
                return $baseSize;
            }
            break;
        case 'Fermented Beer':
            if (in_array($baseSize, array_keys($grouped_sizes_fb))) {
                return $baseSize;
            }
            break;
        case 'Mild Beer':
            if (in_array($baseSize, array_keys($grouped_sizes_mb))) {
                return $baseSize;
            }
            break;
    }
    
    return $baseSize; // Return base size even if not found in predefined groups
}

// Function to get table name for a specific date
function getTableForDate($conn, $compID, $date) {
    $current_month = date('Y-m');
    $target_month = date('Y-m', strtotime($date));
    
    // If current month, use main table
    if ($target_month == $current_month) {
        $tableName = "tbldailystock_" . $compID;
    } else {
        // For previous months, use archive table format: tbldailystock_compID_MM_YY
        $month = date('m', strtotime($date));
        $year = date('y', strtotime($date));
        $tableName = "tbldailystock_" . $compID . "_" . $month . "_" . $year;
    }
    
    // Check if table exists
    $tableCheckQuery = "SHOW TABLES LIKE '$tableName'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult->num_rows == 0) {
        // If archive table doesn't exist, fall back to main table
        $tableName = "tbldailystock_" . $compID;
        
        // Check if main table exists, if not use default
        $tableCheckQuery2 = "SHOW TABLES LIKE '$tableName'";
        $tableCheckResult2 = $conn->query($tableCheckQuery2);
        if ($tableCheckResult2->num_rows == 0) {
            $tableName = "tbldailystock_1";
        }
    }
    
    return $tableName;
}

// Function to check if table has specific day columns
function tableHasDayColumns($conn, $tableName, $day) {
    $day_padded = sprintf('%02d', $day);
    
    // Check if all required columns for this day exist
    $columns_to_check = [
        "DAY_{$day_padded}_OPEN",
        "DAY_{$day_padded}_PURCHASE", 
        "DAY_{$day_padded}_SALES",
        "DAY_{$day_padded}_CLOSING"
    ];
    
    foreach ($columns_to_check as $column) {
        $checkColumnQuery = "SHOW COLUMNS FROM $tableName LIKE '$column'";
        $columnResult = $conn->query($checkColumnQuery);
        if ($columnResult->num_rows == 0) {
            return false; // Column doesn't exist
        }
    }
    
    return true; // All columns exist
}

// Extract financial year dates
$year_start = date('Y-m-d', strtotime($financial_year['START_DATE']));
$year_end = date('Y-m-d', strtotime($financial_year['END_DATE']));

// Define financial year months (April to March)
$financial_year_months = [
    '04' => 'April', '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December',
    '01' => 'January', '02' => 'February', '03' => 'March'
];

// Get opening balance from first day of financial year (April 1)
$opening_date = $year_start;
$opening_month = date('Y-m', strtotime($opening_date));

// Get appropriate table for opening date
$openingTable = getTableForDate($conn, $compID, $opening_date);

// Check if opening table has columns for day 1
if (tableHasDayColumns($conn, $openingTable, 1)) {
    // Fetch opening balance data for the first month of financial year
    $openingQuery = "SELECT ITEM_CODE, LIQ_FLAG, DAY_01_OPEN as opening
                     FROM $openingTable 
                     WHERE STK_MONTH = ?";
    $openingStmt = $conn->prepare($openingQuery);
    $openingStmt->bind_param("s", $opening_month);
    $openingStmt->execute();
    $openingResult = $openingStmt->get_result();

    while ($row = $openingResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        // Skip if item not found in master
        if (!isset($items[$item_code])) continue;
        
        $item_details = $items[$item_code];
        $size = $item_details['DETAILS2'];
        $class = $item_details['CLASS'];
        $liq_flag = $item_details['LIQ_FLAG'];
        
        // Determine liquor type
        $liquor_type = getLiquorType($class, $liq_flag);
        
        // Map database size to Excel size
        $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
        
        // Get grouped size for display
        $grouped_size = getGroupedSize($excel_size, $liquor_type);
        
        // Add to yearly opening data based on liquor type and grouped size
        switch ($liquor_type) {
            case 'Spirits':
                if (in_array($grouped_size, $display_sizes_s)) {
                    $yearly_data['Spirits']['opening'][$grouped_size] += $row['opening'];
                }
                break;
                
            case 'Wines':
                if (in_array($grouped_size, $display_sizes_w)) {
                    $yearly_data['Wines']['opening'][$grouped_size] += $row['opening'];
                }
                break;
                
            case 'Fermented Beer':
                if (in_array($grouped_size, $display_sizes_fb)) {
                    $yearly_data['Fermented Beer']['opening'][$grouped_size] += $row['opening'];
                }
                break;
                
            case 'Mild Beer':
                if (in_array($grouped_size, $display_sizes_mb)) {
                    $yearly_data['Mild Beer']['opening'][$grouped_size] += $row['opening'];
                }
                break;
        }
    }
    $openingStmt->close();
}

// Process all months in the financial year
foreach ($financial_year_months as $month_num => $month_name) {
    $start_year = date('Y', strtotime($year_start));
    $current_year = ($month_num >= '04') ? $start_year : ($start_year + 1);
    $current_month = $current_year . '-' . $month_num;
    
    // Skip months that are outside the financial year range
    $month_start = $current_year . '-' . $month_num . '-01';
    if (strtotime($month_start) < strtotime($year_start) || strtotime($month_start) > strtotime($year_end)) {
        continue;
    }
    
    // Get number of days in this month
    $days_in_month = date('t', strtotime($current_month . '-01'));
    
    // Process each day in the month
    for ($day = 1; $day <= $days_in_month; $day++) {
        $current_date = $current_year . '-' . $month_num . '-' . sprintf('%02d', $day);
        
        // Skip if date is outside financial year range
        if (strtotime($current_date) < strtotime($year_start) || strtotime($current_date) > strtotime($year_end)) {
            continue;
        }
        
        // Get appropriate table for this date
        $dailyStockTable = getTableForDate($conn, $compID, $current_date);
        
        // Check if this specific table has columns for this specific day
        if (!tableHasDayColumns($conn, $dailyStockTable, $day)) {
            // Skip this date as the table doesn't have columns for this day
            continue;
        }
        
        $day_padded = sprintf('%02d', $day);
        
        // Fetch stock data for this specific day
        $stockQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                      DAY_{$day_padded}_PURCHASE as purchase, 
                      DAY_{$day_padded}_SALES as sales
                      FROM $dailyStockTable 
                      WHERE STK_MONTH = ?";
        
        $stockStmt = $conn->prepare($stockQuery);
        $stockStmt->bind_param("s", $current_month);
        $stockStmt->execute();
        $stockResult = $stockStmt->get_result();
        
        while ($row = $stockResult->fetch_assoc()) {
            $item_code = $row['ITEM_CODE'];
            
            // Skip if item not found in master
            if (!isset($items[$item_code])) continue;
            
            $item_details = $items[$item_code];
            $size = $item_details['DETAILS2'];
            $class = $item_details['CLASS'];
            $liq_flag = $item_details['LIQ_FLAG'];
            
            // Determine liquor type
            $liquor_type = getLiquorType($class, $liq_flag);
            
            // Map database size to Excel size
            $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
            
            // Get grouped size for display
            $grouped_size = getGroupedSize($excel_size, $liquor_type);
            
            // Add to yearly data based on liquor type and grouped size
            switch ($liquor_type) {
                case 'Spirits':
                    if (in_array($grouped_size, $display_sizes_s)) {
                        // Received during year (all DAY_X_PURCHASE)
                        $yearly_data['Spirits']['received'][$grouped_size] += $row['purchase'];
                        
                        // Sold during year (all DAY_X_SALES)
                        $yearly_data['Spirits']['sold'][$grouped_size] += $row['sales'];
                    }
                    break;
                    
                case 'Wines':
                    if (in_array($grouped_size, $display_sizes_w)) {
                        // Received during year (all DAY_X_PURCHASE)
                        $yearly_data['Wines']['received'][$grouped_size] += $row['purchase'];
                        
                        // Sold during year (all DAY_X_SALES)
                        $yearly_data['Wines']['sold'][$grouped_size] += $row['sales'];
                    }
                    break;
                    
                case 'Fermented Beer':
                    if (in_array($grouped_size, $display_sizes_fb)) {
                        // Received during year (all DAY_X_PURCHASE)
                        $yearly_data['Fermented Beer']['received'][$grouped_size] += $row['purchase'];
                        
                        // Sold during year (all DAY_X_SALES)
                        $yearly_data['Fermented Beer']['sold'][$grouped_size] += $row['sales'];
                    }
                    break;
                    
                case 'Mild Beer':
                    if (in_array($grouped_size, $display_sizes_mb)) {
                        // Received during year (all DAY_X_PURCHASE)
                        $yearly_data['Mild Beer']['received'][$grouped_size] += $row['purchase'];
                        
                        // Sold during year (all DAY_X_SALES)
                        $yearly_data['Mild Beer']['sold'][$grouped_size] += $row['sales'];
                    }
                    break;
            }
        }
        
        $stockStmt->close();
    }
}

// Get closing balance from last day of financial year (March 31)
$closing_date = $year_end;
$closing_month = date('Y-m', strtotime($closing_date));

// Get appropriate table for closing date
$closingTable = getTableForDate($conn, $compID, $closing_date);

// Check if closing table has columns for the last day
$last_day = date('d', strtotime($closing_date));
if (tableHasDayColumns($conn, $closingTable, $last_day)) {
    // Fetch closing balance data for the last month of financial year
    $closingQuery = "SELECT ITEM_CODE, LIQ_FLAG, DAY_{$last_day}_CLOSING as closing
                     FROM $closingTable 
                     WHERE STK_MONTH = ?";
    $closingStmt = $conn->prepare($closingQuery);
    $closingStmt->bind_param("s", $closing_month);
    $closingStmt->execute();
    $closingResult = $closingStmt->get_result();

    while ($row = $closingResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        // Skip if item not found in master
        if (!isset($items[$item_code])) continue;
        
        $item_details = $items[$item_code];
        $size = $item_details['DETAILS2'];
        $class = $item_details['CLASS'];
        $liq_flag = $item_details['LIQ_FLAG'];
        
        // Determine liquor type
        $liquor_type = getLiquorType($class, $liq_flag);
        
        // Map database size to Excel size
        $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
        
        // Get grouped size for display
        $grouped_size = getGroupedSize($excel_size, $liquor_type);
        
        // Add to yearly closing data based on liquor type and grouped size
        switch ($liquor_type) {
            case 'Spirits':
                if (in_array($grouped_size, $display_sizes_s)) {
                    $yearly_data['Spirits']['closing'][$grouped_size] += $row['closing'];
                }
                break;
                
            case 'Wines':
                if (in_array($grouped_size, $display_sizes_w)) {
                    $yearly_data['Wines']['closing'][$grouped_size] += $row['closing'];
                }
                break;
                
            case 'Fermented Beer':
                if (in_array($grouped_size, $display_sizes_fb)) {
                    $yearly_data['Fermented Beer']['closing'][$grouped_size] += $row['closing'];
                }
                break;
                
            case 'Mild Beer':
                if (in_array($grouped_size, $display_sizes_mb)) {
                    $yearly_data['Mild Beer']['closing'][$grouped_size] += $row['closing'];
                }
                break;
        }
    }
    $closingStmt->close();
}

// Fetch breakages data for the entire financial year
$breakagesQuery = "SELECT b.Code, b.BRK_Qty, i.DETAILS2, i.CLASS, i.LIQ_FLAG 
                   FROM tblbreakages b 
                   JOIN tblitemmaster i ON b.Code = i.CODE 
                   WHERE b.CompID = ? AND b.BRK_Date BETWEEN ? AND ?";
$breakagesStmt = $conn->prepare($breakagesQuery);
$breakagesStmt->bind_param("iss", $compID, $year_start, $year_end);
$breakagesStmt->execute();
$breakagesResult = $breakagesStmt->get_result();

while ($row = $breakagesResult->fetch_assoc()) {
    $item_code = $row['Code'];
    
    // Skip if item not found in master
    if (!isset($items[$item_code])) continue;
    
    $item_details = $items[$item_code];
    $size = $item_details['DETAILS2'];
    $class = $item_details['CLASS'];
    $liq_flag = $item_details['LIQ_FLAG'];
    
    // Determine liquor type
    $liquor_type = getLiquorType($class, $liq_flag);
    
    // Map database size to Excel size
    $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
    
    // Get grouped size for display
    $grouped_size = getGroupedSize($excel_size, $liquor_type);
    
    // Add to breakages data based on liquor type and grouped size
    switch ($liquor_type) {
        case 'Spirits':
            if (in_array($grouped_size, $display_sizes_s)) {
                $yearly_data['Spirits']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;
            
        case 'Wines':
            if (in_array($grouped_size, $display_sizes_w)) {
                $yearly_data['Wines']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;
            
        case 'Fermented Beer':
            if (in_array($grouped_size, $display_sizes_fb)) {
                $yearly_data['Fermented Beer']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;
            
        case 'Mild Beer':
            if (in_array($grouped_size, $display_sizes_mb)) {
                $yearly_data['Mild Beer']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;
    }
}
$breakagesStmt->close();

// Calculate totals (Opening + Received)
foreach ($display_sizes_s as $size) {
    $yearly_data['Spirits']['total'][$size] = 
        $yearly_data['Spirits']['opening'][$size] + 
        $yearly_data['Spirits']['received'][$size];
}

foreach ($display_sizes_w as $size) {
    $yearly_data['Wines']['total'][$size] = 
        $yearly_data['Wines']['opening'][$size] + 
        $yearly_data['Wines']['received'][$size];
}

foreach ($display_sizes_fb as $size) {
    $yearly_data['Fermented Beer']['total'][$size] = 
        $yearly_data['Fermented Beer']['opening'][$size] + 
        $yearly_data['Fermented Beer']['received'][$size];
}

foreach ($display_sizes_mb as $size) {
    $yearly_data['Mild Beer']['total'][$size] = 
        $yearly_data['Mild Beer']['opening'][$size] + 
        $yearly_data['Mild Beer']['received'][$size];
}

// Calculate summary totals
$summary_totals = [
    'opening' => 0,
    'received' => 0,
    'total' => 0,
    'sold' => 0,
    'breakages' => 0,
    'closing' => 0
];

// Calculate totals for each category
foreach ($yearly_data as $category => $data) {
    foreach ($data['opening'] as $size => $value) {
        $summary_totals['opening'] += $value;
    }
    foreach ($data['received'] as $size => $value) {
        $summary_totals['received'] += $value;
    }
    foreach ($data['total'] as $size => $value) {
        $summary_totals['total'] += $value;
    }
    foreach ($data['sold'] as $size => $value) {
        $summary_totals['sold'] += $value;
    }
    foreach ($data['breakages'] as $size => $value) {
        $summary_totals['breakages'] += $value;
    }
    foreach ($data['closing'] as $size => $value) {
        $summary_totals['closing'] += $value;
    }
}

// Calculate total columns count for table formatting
$total_columns = count($display_sizes_s) + count($display_sizes_w) + count($display_sizes_fb) + count($display_sizes_mb);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Yearly Register (FLR-4) - Financial Year - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  
  <style>
    /* Your existing CSS styles */
    body {
      font-size: 12px;
      background-color: #f8f9fa;
    }
    .company-header {
      text-align: center;
      margin-bottom: 15px;
      padding: 10px;
    }
    .company-header h1 {
      font-size: 18px;
      font-weight: bold;
      margin-bottom: 5px;
    }
    .company-header h5 {
      font-size: 14px;
      margin-bottom: 3px;
    }
    .company-header h6 {
      font-size: 12px;
      margin-bottom: 5px;
    }
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 15px;
      font-size: 10px;
    }
    .report-table th, .report-table td {
      border: 1px solid #000;
      padding: 4px;
      text-align: center;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      line-height: 1.2;
    }
    .report-table th {
      background-color: #f0f0f0;
      font-weight: bold;
      padding: 6px 3px;
    }
    .vertical-text {
      writing-mode: vertical-lr;
      transform: rotate(180deg);
      text-align: center;
      white-space: nowrap;
      padding: 8px 2px;
      min-width: 20px;
    }
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
    }
    .filter-card {
      background-color: #f8f9fa;
    }
    .table-responsive {
      overflow-x: auto;
      max-width: 100%;
    }
    .action-controls {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .no-print {
      display: block;
    }

    /* Print styles */
    @media print {
      @page {
        size: legal landscape;
        margin: 0.2in;
      }
      
      body {
        margin: 0;
        padding: 0;
        font-size: 8px;
        line-height: 1;
        background: white;
        width: 100%;
        height: 100%;
        transform: scale(0.8);
        transform-origin: 0 0;
        width: 125%;
      }
      
      .no-print {
        display: none !important;
      }
      
      body * {
        visibility: hidden;
      }
      
      .print-section, .print-section * {
        visibility: visible;
      }
      
      .print-section {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
      }
      
      .company-header {
        text-align: center;
        margin-bottom: 5px;
        padding: 2px;
        page-break-after: avoid;
      }
      
      .company-header h1 {
        font-size: 12px !important;
        margin-bottom: 1px !important;
      }
      
      .company-header h5 {
        font-size: 9px !important;
        margin-bottom: 1px !important;
      }
      
      .company-header h6 {
        font-size: 8px !important;
        margin-bottom: 2px !important;
      }
      
      .table-responsive {
        overflow: visible;
        width: 100%;
        height: auto;
      }
      
      .report-table {
        width: 100% !important;
        font-size: 6px !important;
        table-layout: fixed;
        border-collapse: collapse;
        page-break-inside: avoid;
      }
      
      .report-table th, .report-table td {
        padding: 1px !important;
        line-height: 1;
        height: 14px;
        min-width: 18px;
        max-width: 22px;
        font-size: 6px !important;
        border: 0.5px solid #000 !important;
      }
      
      .report-table th {
        background-color: #f0f0f0 !important;
        padding: 2px 1px !important;
        font-weight: bold;
      }
      
      .vertical-text {
        writing-mode: vertical-lr;
        transform: rotate(180deg);
        text-align: center;
        white-space: nowrap;
        padding: 1px !important;
        font-size: 5px !important;
        min-width: 15px;
        max-width: 18px;
        line-height: 1;
        height: 25px !important;
      }
      
      .description-col {
        width: 120px !important;
        min-width: 120px !important;
        max-width: 120px !important;
        text-align: left !important;
      }
      
      .size-col {
        width: 18px !important;
        min-width: 18px !important;
        max-width: 18px !important;
      }
      
      .summary-row {
        background-color: #f8f9fa !important;
        font-weight: bold;
      }
      
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 6px;
        page-break-before: avoid;
      }
      
      /* Ensure no page breaks within the table */
      tr {
        page-break-inside: avoid;
        page-break-after: auto;
      }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Yearly Register (FLR-4) - Financial Year</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-control">
                  <option value="Foreign Liquor" <?= $mode == 'Foreign Liquor' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="Country Liquor" <?= $mode == 'Country Liquor' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label">Financial Year:</label>
                <div class="form-control-plaintext">
                  <strong><?= date('F Y', strtotime($year_start)) ?> to <?= date('F Y', strtotime($year_end)) ?></strong>
                  <small class="text-muted ms-2">(Based on active financial year in system)</small>
                </div>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
              </button>
              <a href="dashboard.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-times me-1"></i> Exit
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Report Results -->
      <div class="print-section">
        <div class="company-header">
          <h5>License to <?= htmlspecialchars($companyName) ?> (<?= date('Y', strtotime($year_start)) ?> - <?= date('Y', strtotime($year_end)) ?>), Pune.</h5>
          <h5>[Yearly Register - Financial Year]</h5>
          <h6><?= htmlspecialchars($companyName) ?></h6>
          <h6>LICENCE NO. :- <?= htmlspecialchars($licenseNo) ?> | MORTIF.: | SPRINT 3</h6>
          <h6>Financial Year: <?= date('d-M-Y', strtotime($year_start)) ?> to <?= date('d-M-Y', strtotime($year_end)) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table">
            <thead>
              <tr>
                <th rowspan="3" class="description-col">Description</th>
                <th colspan="<?= count($display_sizes_s) ?>">SPIRITS</th>
                <th colspan="<?= count($display_sizes_w) ?>">WINES</th>
                <th colspan="<?= count($display_sizes_fb) ?>">FERMENTED BEER</th>
                <th colspan="<?= count($display_sizes_mb) ?>">MILD BEER</th>
              </tr>
              <tr>
                <!-- Spirits Size Columns -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Size Columns -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Size Columns -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Size Columns -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <!-- Opening Balance Row -->
              <tr>
                <td class="description-col">Opening Balance</td>
                
                <!-- Spirits Opening -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $yearly_data['Spirits']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Opening -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $yearly_data['Wines']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Opening -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $yearly_data['Fermented Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Opening -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $yearly_data['Mild Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Received During Year Row -->
              <tr>
                <td class="description-col">Received During Year</td>
                
                <!-- Spirits Received -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $yearly_data['Spirits']['received'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Received -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $yearly_data['Wines']['received'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Received -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $yearly_data['Fermented Beer']['received'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Received -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $yearly_data['Mild Beer']['received'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Total Row -->
              <tr>
                <td class="description-col">Total</td>
                
                <!-- Spirits Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $yearly_data['Spirits']['total'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $yearly_data['Wines']['total'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $yearly_data['Fermented Beer']['total'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $yearly_data['Mild Beer']['total'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Sold During Year Row -->
              <tr>
                <td class="description-col">Sold During Year</td>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $yearly_data['Spirits']['sold'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $yearly_data['Wines']['sold'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $yearly_data['Fermented Beer']['sold'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $yearly_data['Mild Beer']['sold'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Breakages Row -->
              <tr>
                <td class="description-col">Breakages</td>
                
                <!-- Spirits Breakages -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $yearly_data['Spirits']['breakages'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Breakages -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $yearly_data['Wines']['breakages'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Breakages -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $yearly_data['Fermented Beer']['breakages'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Breakages -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $yearly_data['Mild Beer']['breakages'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Closing Balance Row -->
              <tr>
                <td class="description-col">Closing Balance</td>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $yearly_data['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $yearly_data['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $yearly_data['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $yearly_data['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Summary Row -->
              <tr class="summary-row">
                <td class="description-col">Total</td>
                <td colspan="<?= $total_columns ?>">
                  Opening: <?= $summary_totals['opening'] ?> | 
                  Received: <?= $summary_totals['received'] ?> | 
                  Total: <?= $summary_totals['total'] ?> | 
                  Sold: <?= $summary_totals['sold'] ?> | 
                  Breakages: <?= $summary_totals['breakages'] ?> | 
                  Closing: <?= $summary_totals['closing'] ?>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <p>Generated on: <?= date('d-m-Y h:i A') ?> | Software: WineSoft | User: <?= $_SESSION['user_name'] ?? 'Admin' ?></p>
          <p>Financial Year: <?= date('d-M-Y', strtotime($year_start)) ?> to <?= date('d-M-Y', strtotime($year_end)) ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php $conn->close(); ?>