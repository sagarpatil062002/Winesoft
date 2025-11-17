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
require_once 'license_functions.php'; // Add license functions

// Get company ID from session
$compID = $_SESSION['CompID'];

// Get company's license type and available classes
$license_type = getCompanyLicenseType($compID, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Default values - Set to single date by default
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Validate date range
if (strtotime($from_date) > strtotime($to_date)) {
    $from_date = $to_date; // Ensure from_date is not after to_date
}

// Fetch company name and license number
$companyName = "Digvijay WINE SHOP";
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
    '180 ML (Pet)', '180 ML', '170 ML (48)', '90 ML(100)', '90 ML (Pet)-100', '90 ML (Pet)-96',
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

// Get display sizes (base sizes) for each liquor type - ORDER: Spirit, Imported, Wine, Wine Imp, Fermented Beer, Mild Beer
$display_sizes_s = ['2000 ML', '1000 ML', '750 ML', '700 ML', '500 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_imported = $display_sizes_s; // Imported uses same sizes as Spirit
$display_sizes_w = ['750 ML', '375 ML', '180 ML', '90 ML'];
$display_sizes_wine_imp = $display_sizes_w; // Wine Imp uses same sizes as Wine
$display_sizes_fb = ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];
$display_sizes_mb = ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];

// For Country Liquor - use Spirits sizes
$display_sizes_country = $display_sizes_s;

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

// DEBUG: Check if Imported and Wine Imp classes exist
$debug_classes = ['I', 'W'];
foreach ($debug_classes as $debug_class) {
    if (isset($classData[$debug_class])) {
        error_log("Class $debug_class found: " . $classData[$debug_class]['DESC']);
    } else {
        error_log("Class $debug_class NOT found in tblclass");
    }
}

// Fetch item master data with size information - FILTERED BY LICENSE TYPE AND MODE
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // Modified query to include all LIQ_FLAG values for Foreign Liquor mode
    // so we can properly categorize Imported and Wine Imp
    if ($mode == 'Country Liquor') {
        $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster WHERE CLASS IN ($class_placeholders) AND LIQ_FLAG = 'C'";
    } else {
        // For Foreign Liquor, include all items from allowed classes regardless of LIQ_FLAG
        // This allows proper categorization of Imported ('I') and Wine Imp ('W') classes
        $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster WHERE CLASS IN ($class_placeholders)";
    }
    
    $itemStmt = $conn->prepare($itemQuery);
    $itemStmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    
    $item_count_by_class = [];
    while ($row = $itemResult->fetch_assoc()) {
        $items[$row['CODE']] = $row;
        $class = $row['CLASS'];
        if (!isset($item_count_by_class[$class])) {
            $item_count_by_class[$class] = 0;
        }
        $item_count_by_class[$class]++;
    }
    
    // DEBUG: Log item counts by class
    error_log("Item counts by class: " . print_r($item_count_by_class, true));
    
    $itemStmt->close();
}

// Initialize report data structure
$dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// Initialize daily data structure for each date
$daily_data = [];

// Initialize T.P. Nos data
$tp_nos_data = [];

// Map database sizes to Excel column sizes
$size_mapping = [
    // Spirits
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '170 ML' => '180 ML',
    '90 ML' => '90 ML',
    '90 ML-100' => '90 ML',
    '90 ML-96' => '90 ML',
    '2000 ML' => '2000 ML',
    '2000 ML Pet' => '2000 ML',

    // Wines
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '650 ML' => '650 ML',
    '330 ML' => '330 ML',
    '180 ML' => '180 ML',

    // Fermented Beer
    '650 ML' => '650 ML',
    '500 ML' => '500 ML',
    '500 ML (CAN)' => '500 ML (CAN)',
    '330 ML' => '330 ML',
    '330 ML (CAN)' => '330 ML (CAN)',

    // Mild Beer
    '650 ML' => '650 ML',
    '500 ML (CAN)' => '500 ML (CAN)',
    '330 ML' => '330 ML',
    '330 ML (CAN)' => '330 ML (CAN)'
];

// Function to determine liquor type based on CLASS and LIQ_FLAG
function getLiquorType($class, $liq_flag, $mode, $desc = '') {
    if ($mode == 'Country Liquor') {
        return 'Country Liquor';
    }

    if ($liq_flag == 'F') {
        switch ($class) {
            case 'I':
                return 'Imported Spirit';
            case 'W':
                if (stripos($desc, 'Wine') !== false || stripos($desc, 'Imp') !== false) {
                    return 'Wine Imp';
                } else {
                    return 'Spirits';
                }
            case 'V':
                return 'Wines';
            case 'F':
                return 'Fermented Beer';
            case 'M':
                return 'Mild Beer';
            case 'G':
            case 'D':
            case 'K':
            case 'R':
            case 'O':
                return 'Spirits';
            default:
                return 'Spirits';
        }
    }
    return 'Spirits'; // Default for non-F items
}

// Function to get base size for grouping
function getGroupedSize($size, $liquor_type) {
    global $grouped_sizes_s, $grouped_sizes_w, $grouped_sizes_fb, $grouped_sizes_mb;
    
    $baseSize = getBaseSize($size);
    
    // For Country Liquor, use Spirits grouping
    if ($liquor_type == 'Country Liquor') {
        if (in_array($baseSize, array_keys($grouped_sizes_s))) {
            return $baseSize;
        }
        return $baseSize;
    }
    
    // Check if this base size exists in the appropriate group
    switch ($liquor_type) {
        case 'Spirits':
        case 'Imported Spirit': // Imported uses same grouping as Spirits
            if (in_array($baseSize, array_keys($grouped_sizes_s))) {
                return $baseSize;
            }
            break;
        case 'Wines':
        case 'Wine Imp': // Wine Imp uses same grouping as Wines
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

// Fetch T.P. Nos from tblpurchases for each date
foreach ($dates as $date) {
    $tpQuery = "SELECT DISTINCT TPNO FROM tblpurchases WHERE DATE = ? AND CompID = ?";
    $tpStmt = $conn->prepare($tpQuery);
    $tpStmt->bind_param("si", $date, $compID);
    $tpStmt->execute();
    $tpResult = $tpStmt->get_result();
    
    $tp_nos = [];
    while ($row = $tpResult->fetch_assoc()) {
        if (!empty($row['TPNO'])) {
            $tp_nos[] = $row['TPNO'];
        }
    }
    
    $tp_nos_data[$date] = $tp_nos;
    $tpStmt->close();
}

// Process each date in the range with month-aware logic
$stock_data_debug = [];
foreach ($dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    
    // Get appropriate table for this date
    $dailyStockTable = getTableForDate($conn, $compID, $date);
    
    // Check if this specific table has columns for this specific day
    if (!tableHasDayColumns($conn, $dailyStockTable, $day)) {
        // Skip this date as the table doesn't have columns for this day
        continue;
    }
    
    $day_padded = sprintf('%02d', $day);
    
    // Initialize daily data for this date based on mode - ensure all categories are present
    if ($mode == 'Country Liquor') {
        $daily_data[$date] = [
            'Country Liquor' => [
                'opening' => array_fill_keys($display_sizes_country, 0),
                'purchase' => array_fill_keys($display_sizes_country, 0),
                'sales' => array_fill_keys($display_sizes_country, 0),
                'closing' => array_fill_keys($display_sizes_country, 0)
            ]
        ];
    } else {
        // Ensure all 6 categories are initialized even if some have no data
        $daily_data[$date] = [
            'Spirits' => [
                'opening' => array_fill_keys($display_sizes_s, 0),
                'purchase' => array_fill_keys($display_sizes_s, 0),
                'sales' => array_fill_keys($display_sizes_s, 0),
                'closing' => array_fill_keys($display_sizes_s, 0)
            ],
            'Imported Spirit' => [
                'opening' => array_fill_keys($display_sizes_imported, 0),
                'purchase' => array_fill_keys($display_sizes_imported, 0),
                'sales' => array_fill_keys($display_sizes_imported, 0),
                'closing' => array_fill_keys($display_sizes_imported, 0)
            ],
            'Wines' => [
                'opening' => array_fill_keys($display_sizes_w, 0),
                'purchase' => array_fill_keys($display_sizes_w, 0),
                'sales' => array_fill_keys($display_sizes_w, 0),
                'closing' => array_fill_keys($display_sizes_w, 0)
            ],
            'Wine Imp' => [
                'opening' => array_fill_keys($display_sizes_wine_imp, 0),
                'purchase' => array_fill_keys($display_sizes_wine_imp, 0),
                'sales' => array_fill_keys($display_sizes_wine_imp, 0),
                'closing' => array_fill_keys($display_sizes_wine_imp, 0)
            ],
            'Fermented Beer' => [
                'opening' => array_fill_keys($display_sizes_fb, 0),
                'purchase' => array_fill_keys($display_sizes_fb, 0),
                'sales' => array_fill_keys($display_sizes_fb, 0),
                'closing' => array_fill_keys($display_sizes_fb, 0)
            ],
            'Mild Beer' => [
                'opening' => array_fill_keys($display_sizes_mb, 0),
                'purchase' => array_fill_keys($display_sizes_mb, 0),
                'sales' => array_fill_keys($display_sizes_mb, 0),
                'closing' => array_fill_keys($display_sizes_mb, 0)
            ]
        ];
    }
    
    // Fetch stock data for this specific day
    $stockQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                  DAY_{$day_padded}_OPEN as opening,
                  DAY_{$day_padded}_PURCHASE as purchase, 
                  DAY_{$day_padded}_SALES as sales, 
                  DAY_{$day_padded}_CLOSING as closing 
                  FROM $dailyStockTable 
                  WHERE STK_MONTH = ?";
    
    $stockStmt = $conn->prepare($stockQuery);
    $stockStmt->bind_param("s", $month);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    
    $stock_count_by_type = [];
    while ($row = $stockResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        // Skip if item not found in master (due to license filtering)
        if (!isset($items[$item_code])) continue;
        
        $item_details = $items[$item_code];
        $size = $item_details['DETAILS2'];
        $class = $item_details['CLASS'];
        $liq_flag = $item_details['LIQ_FLAG'];
        
        // Determine liquor type - this now properly handles 'I' and 'W' classes
        $liquor_type = getLiquorType($class, $liq_flag, $mode, $classData[$class]['DESC'] ?? '');
        
        // DEBUG: Track stock data by type
        if (!isset($stock_count_by_type[$liquor_type])) {
            $stock_count_by_type[$liquor_type] = 0;
        }
        $stock_count_by_type[$liquor_type]++;
        
        // Map database size to Excel size
        $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
        
        // Get grouped size for display
        $grouped_size = getGroupedSize($excel_size, $liquor_type);
        
        // Add to daily data based on liquor type and grouped size
        if ($mode == 'Country Liquor') {
            if (in_array($grouped_size, $display_sizes_country)) {
                $daily_data[$date]['Country Liquor']['opening'][$grouped_size] += $row['opening'];
                $daily_data[$date]['Country Liquor']['purchase'][$grouped_size] += $row['purchase'];
                $daily_data[$date]['Country Liquor']['sales'][$grouped_size] += $row['sales'];
                $daily_data[$date]['Country Liquor']['closing'][$grouped_size] += $row['closing'];
            }
        } else {
            // Make sure the liquor type exists in our data structure
            if (isset($daily_data[$date][$liquor_type])) {
                // Determine which display sizes to use based on liquor type
                $target_sizes = [];
                switch ($liquor_type) {
                    case 'Spirits': $target_sizes = $display_sizes_s; break;
                    case 'Imported Spirit': $target_sizes = $display_sizes_imported; break;
                    case 'Wines': $target_sizes = $display_sizes_w; break;
                    case 'Wine Imp': $target_sizes = $display_sizes_wine_imp; break;
                    case 'Fermented Beer': $target_sizes = $display_sizes_fb; break;
                    case 'Mild Beer': $target_sizes = $display_sizes_mb; break;
                    default: $target_sizes = $display_sizes_s; // Default to spirits
                }
                
                if (in_array($grouped_size, $target_sizes)) {
                    $daily_data[$date][$liquor_type]['opening'][$grouped_size] += $row['opening'];
                    $daily_data[$date][$liquor_type]['purchase'][$grouped_size] += $row['purchase'];
                    $daily_data[$date][$liquor_type]['sales'][$grouped_size] += $row['sales'];
                    $daily_data[$date][$liquor_type]['closing'][$grouped_size] += $row['closing'];
                }
            }
        }
    }
    
    // DEBUG: Log stock counts by type for this date
    if (!empty($stock_count_by_type)) {
        error_log("Date $date - Stock counts by type: " . print_r($stock_count_by_type, true));
        $stock_data_debug[$date] = $stock_count_by_type;
    }
    
    $stockStmt->close();
}

// DEBUG: Show what data we have
error_log("Final daily data structure: " . print_r($daily_data, true));

// Calculate total columns count for table formatting
if ($mode == 'Country Liquor') {
    $total_columns = count($display_sizes_country);
} else {
    $total_columns = count($display_sizes_s) + count($display_sizes_imported) + count($display_sizes_w) + count($display_sizes_wine_imp) + count($display_sizes_fb) + count($display_sizes_mb);
}

// Calculate column positions for double lines in Foreign Liquor mode
if ($mode == 'Foreign Liquor') {
    $spirit_cols = count($display_sizes_s);
    $imported_cols = count($display_sizes_imported);
    $wine_cols = count($display_sizes_w);
    $wine_imp_cols = count($display_sizes_wine_imp);
    $fermented_cols = count($display_sizes_fb);
    
    // Starting from column 4 (after Date, T.P. Nos, Type columns)
    $base_col = 4;
    
    // After Spirits
    $after_spirit = $base_col + $spirit_cols;
    
    // After Imported Spirit  
    $after_imported = $after_spirit + $imported_cols;
    
    // After Wines
    $after_wine = $after_imported + $wine_cols;
    
    // After Wine Imp
    $after_wine_imp = $after_wine + $wine_imp_cols;
    
    // After Fermented Beer
    $after_fermented = $after_wine_imp + $fermented_cols;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Excise Register (FLR-3) - liqoursoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  
  <style>
    /* Screen styles */
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
      min-width: 25px;
      max-width: 25px;
      width: 25px;
      font-size: 9px;
      line-height: 1.1;
      font-weight: bold;
    }
    .vertical-text-full {
      writing-mode: vertical-lr;
      transform: rotate(180deg);
      text-align: center;
      white-space: nowrap;
      padding: 8px 2px;
      min-width: 25px;
      max-width: 25px;
      width: 25px;
      font-size: 9px;
      line-height: 1.1;
      font-weight: bold;
    }
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
    }
    
    /* Double line separators - using class-based approach */
    .double-line-right {
      border-right: 3px double #000 !important;
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
    .tp-nos {
      font-size: 8px;
      line-height: 1.1;
      text-align: left;
      padding: 2px;
    }
    .tp-nos span {
      display: inline-block;
      margin-right: 3px;
    }
    .type-col {
      width: 40px;
      min-width: 40px;
    }
    .date-col {
      width: 30px;
      min-width: 30px;
    }
    .tp-col {
      width: 50px;
      min-width: 50px;
    }
    .size-col {
      width: 25px;
      min-width: 25px;
      max-width: 25px;
    }
    .date-display {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      height: 100%;
      line-height: 1;
    }
    .date-display span {
      display: block;
      line-height: 1;
      margin: 0;
      padding: 0;
    }
    .debug-info {
      background-color: #fff3cd;
      border: 1px solid #ffeaa7;
      padding: 10px;
      margin: 10px 0;
      border-radius: 5px;
      font-size: 12px;
    }
    .category-header {
      font-weight: bold;
      background-color: #e9ecef !important;
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
      }
      
      .no-print, .debug-info {
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
        font-size: 7px !important;
        table-layout: fixed;
        border-collapse: collapse;
        page-break-inside: avoid;
      }
      
      .report-table th, .report-table td {
        padding: 2px 1px !important;
        line-height: 1;
        height: 16px;
        min-width: 20px;
        max-width: 22px;
        font-size: 7px !important;
        border: 1px solid #000 !important;
      }
      
      .report-table th {
        background-color: #f0f0f0 !important;
        padding: 3px 1px !important;
        font-weight: bold;
      }
      
      .vertical-text, .vertical-text-full {
        writing-mode: vertical-lr;
        transform: rotate(180deg);
        text-align: center;
        white-space: nowrap;
        padding: 2px !important;
        font-size: 6px !important;
        min-width: 18px;
        max-width: 20px;
        width: 20px !important;
        line-height: 1;
        height: auto !important;
      }
      
      .date-col {
        width: 25px !important;
        min-width: 25px !important;
        max-width: 25px !important;
      }
      
      .tp-col {
        width: 40px !important;
        min-width: 40px !important;
        max-width: 40px !important;
      }
      
      .type-col {
        width: 30px !important;
        min-width: 30px !important;
        max-width: 30px !important;
      }
      
      .size-col {
        width: 20px !important;
        min-width: 20px !important;
        max-width: 20px !important;
      }
      
      .summary-row {
        background-color: #f8f9fa !important;
        font-weight: bold;
      }
      
      .tp-nos {
        font-size: 6px !important;
        line-height: 1;
        padding: 1px !important;
      }
      
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 7px;
        page-break-before: avoid;
      }
      
      tr {
        page-break-inside: avoid;
        page-break-after: auto;
      }
      
      .date-display {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        line-height: 1;
      }
      
      .date-display span {
        display: block;
        line-height: 1;
        margin: 0;
        padding: 0;
      }
      
      /* Print double line separators */
      .double-line-right {
        border-right: 3px double #000 !important;
      }
      
      .category-header {
        background-color: #e9ecef !important;
        font-weight: bold;
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
      <h3 class="mb-4">Excise Register (FLR-3) Printing Module</h3>

      <!-- License Restriction Info -->
      <div class="license-info no-print">
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
              <div class="col-md-3">
                <label class="form-label">From Date:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>" max="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>" max="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date Range Info:</label>
                <div class="form-control-plaintext">
                  <small class="text-muted">Selected: <?= count($dates) ?> day(s)</small>
                </div>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate Report
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
              </button>
              <button type="button" class="btn btn-info" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-1"></i> Export to Excel
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
          <h1>Excise Register (FLR-3)</h1>
          <h5>Mode: <?= htmlspecialchars($mode) ?></h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
        </div>
        
        <?php if (empty($dates) || (count($dates) == 1 && !isset($daily_data[$dates[0]]))): ?>
          <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No data available for the selected date range.
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="report-table" id="excise-register-table">
              <thead>
                <tr>
                  <th rowspan="2" class="date-col">Date</th>
                  <th rowspan="2" class="tp-col">T. P. Nos</th>
                  <th rowspan="2" class="type-col">Type</th>
                  
                  <?php if ($mode == 'Country Liquor'): ?>
                    <th colspan="<?= count($display_sizes_country) ?>">COUNTRY LIQUOR</th>
                  <?php else: ?>
                    <th colspan="<?= count($display_sizes_s) ?>">SPIRIT S</th>
                    <th colspan="<?= count($display_sizes_imported) ?>">IMPORTED SPIRIT</th>
                    <th colspan="<?= count($display_sizes_w) ?>">WINE</th>
                    <th colspan="<?= count($display_sizes_wine_imp) ?>">WINE IMP</th>
                    <th colspan="<?= count($display_sizes_fb) ?>">FERMENTED BEER</th>
                    <th colspan="<?= count($display_sizes_mb) ?>">MILD BEER</th>
                  <?php endif; ?>
                </tr>
                <tr>
                  <?php if ($mode == 'Country Liquor'): ?>
                    <!-- Country Liquor Sizes -->
                    <?php 
                    $last_country_index = count($display_sizes_country) - 1;
                    foreach ($display_sizes_country as $index => $size): ?>
                      <th class="size-col vertical-text-full <?= $index == $last_country_index ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                  <?php else: ?>
                    <!-- Spirits Sizes -->
                    <?php 
                    $last_spirit_index = count($display_sizes_s) - 1;
                    foreach ($display_sizes_s as $index => $size): ?>
                      <th class="size-col vertical-text-full <?= $index == $last_spirit_index ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                    
                    <!-- Imported Sizes -->
                    <?php 
                    $last_imported_index = count($display_sizes_imported) - 1;
                    foreach ($display_sizes_imported as $index => $size): ?>
                      <th class="size-col vertical-text-full <?= $index == $last_imported_index ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                    
                    <!-- Wines Sizes -->
                    <?php 
                    $last_wine_index = count($display_sizes_w) - 1;
                    foreach ($display_sizes_w as $index => $size): ?>
                      <th class="size-col vertical-text-full <?= $index == $last_wine_index ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                    
                    <!-- Wine Imp Sizes -->
                    <?php 
                    $last_wine_imp_index = count($display_sizes_wine_imp) - 1;
                    foreach ($display_sizes_wine_imp as $index => $size): ?>
                      <th class="size-col vertical-text-full <?= $index == $last_wine_imp_index ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                    
                    <!-- Fermented Beer Sizes -->
                    <?php 
                    $last_fermented_index = count($display_sizes_fb) - 1;
                    foreach ($display_sizes_fb as $index => $size): ?>
                      <th class="size-col vertical-text-full <?= $index == $last_fermented_index ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                    
                    <!-- Mild Beer Sizes -->
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <th class="size-col vertical-text-full"><?= $size ?></th>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php 
                $date_count = 0;
                $first_date = true;
                foreach ($dates as $date): 
                  // Skip if this date was not processed due to missing columns
                  if (!isset($daily_data[$date])) continue;
                  
                  $day_num = date('d', strtotime($date));
                  $month_num = date('m', strtotime($date));
                  $year_num = date('y', strtotime($date));
                  $tp_nos = $tp_nos_data[$date];
                  $date_count++;
                ?>
                  
                  <?php if ($first_date): ?>
                    <!-- First date - Show all 4 rows (Op, Rec, Sale, Clo) -->
                    <tr>
                      <td rowspan="4" class="date-col">
                        <div class="date-display">
                          <span><?= $day_num ?></span>
                          <span><?= $month_num ?></span>
                          <span><?= $year_num ?></span>
                        </div>
                      </td>
                      <td rowspan="4" class="tp-nos">
                        <?php if (!empty($tp_nos)): ?>
                          <?php foreach ($tp_nos as $tp_no): ?>
                            <span><?= $tp_no ?></span>
                          <?php endforeach; ?>
                        <?php else: ?>
                          &nbsp;
                        <?php endif; ?>
                      </td>
                      <td>Op.</td>
                      
                      <?php if ($mode == 'Country Liquor'): ?>
                        <!-- Country Liquor Opening Stock -->
                        <?php 
                        $last_country_index = count($display_sizes_country) - 1;
                        foreach ($display_sizes_country as $index => $size): ?>
                          <td class="<?= $index == $last_country_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Country Liquor']['opening'][$size] > 0 ? $daily_data[$date]['Country Liquor']['opening'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <!-- Spirits Opening Stock -->
                        <?php 
                        $last_spirit_index = count($display_sizes_s) - 1;
                        foreach ($display_sizes_s as $index => $size): ?>
                          <td class="<?= $index == $last_spirit_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Spirits']['opening'][$size] > 0 ? $daily_data[$date]['Spirits']['opening'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Imported Spirit Opening Stock -->
                        <?php 
                        $last_imported_index = count($display_sizes_imported) - 1;
                        foreach ($display_sizes_imported as $index => $size): ?>
                           <td class="<?= $index == $last_imported_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Imported Spirit']['opening'][$size] > 0 ? $daily_data[$date]['Imported Spirit']['opening'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wines Opening Stock -->
                        <?php 
                        $last_wine_index = count($display_sizes_w) - 1;
                        foreach ($display_sizes_w as $index => $size): ?>
                          <td class="<?= $index == $last_wine_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wines']['opening'][$size] > 0 ? $daily_data[$date]['Wines']['opening'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wine Imp Opening Stock -->
                        <?php 
                        $last_wine_imp_index = count($display_sizes_wine_imp) - 1;
                        foreach ($display_sizes_wine_imp as $index => $size): ?>
                          <td class="<?= $index == $last_wine_imp_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wine Imp']['opening'][$size] > 0 ? $daily_data[$date]['Wine Imp']['opening'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Fermented Beer Opening Stock -->
                        <?php 
                        $last_fermented_index = count($display_sizes_fb) - 1;
                        foreach ($display_sizes_fb as $index => $size): ?>
                          <td class="<?= $index == $last_fermented_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Fermented Beer']['opening'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['opening'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Mild Beer Opening Stock -->
                        <?php foreach ($display_sizes_mb as $size): ?>
                          <td><?= $daily_data[$date]['Mild Beer']['opening'][$size] > 0 ? $daily_data[$date]['Mild Beer']['opening'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tr>
                    
                    <tr>
                      <td>Rec.</td>
                      
                      <?php if ($mode == 'Country Liquor'): ?>
                        <!-- Country Liquor Received -->
                        <?php 
                        $last_country_index = count($display_sizes_country) - 1;
                        foreach ($display_sizes_country as $index => $size): ?>
                          <td class="<?= $index == $last_country_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Country Liquor']['purchase'][$size] > 0 ? $daily_data[$date]['Country Liquor']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <!-- Spirits Received -->
                        <?php 
                        $last_spirit_index = count($display_sizes_s) - 1;
                        foreach ($display_sizes_s as $index => $size): ?>
                          <td class="<?= $index == $last_spirit_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Spirits']['purchase'][$size] > 0 ? $daily_data[$date]['Spirits']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Imported Spirit Received -->
                        <?php 
                        $last_imported_index = count($display_sizes_imported) - 1;
                        foreach ($display_sizes_imported as $index => $size): ?>
                           <td class="<?= $index == $last_imported_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Imported Spirit']['purchase'][$size] > 0 ? $daily_data[$date]['Imported Spirit']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wines Received -->
                        <?php 
                        $last_wine_index = count($display_sizes_w) - 1;
                        foreach ($display_sizes_w as $index => $size): ?>
                          <td class="<?= $index == $last_wine_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wines']['purchase'][$size] > 0 ? $daily_data[$date]['Wines']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wine Imp Received -->
                        <?php 
                        $last_wine_imp_index = count($display_sizes_wine_imp) - 1;
                        foreach ($display_sizes_wine_imp as $index => $size): ?>
                          <td class="<?= $index == $last_wine_imp_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wine Imp']['purchase'][$size] > 0 ? $daily_data[$date]['Wine Imp']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Fermented Beer Received -->
                        <?php 
                        $last_fermented_index = count($display_sizes_fb) - 1;
                        foreach ($display_sizes_fb as $index => $size): ?>
                          <td class="<?= $index == $last_fermented_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Fermented Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Mild Beer Received -->
                        <?php foreach ($display_sizes_mb as $size): ?>
                          <td><?= $daily_data[$date]['Mild Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Mild Beer']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tr>
                    
                    <tr>
                      <td>Sale</td>
                      
                      <?php if ($mode == 'Country Liquor'): ?>
                        <!-- Country Liquor Sales -->
                        <?php 
                        $last_country_index = count($display_sizes_country) - 1;
                        foreach ($display_sizes_country as $index => $size): ?>
                          <td class="<?= $index == $last_country_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Country Liquor']['sales'][$size] > 0 ? $daily_data[$date]['Country Liquor']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <!-- Spirits Sales -->
                        <?php 
                        $last_spirit_index = count($display_sizes_s) - 1;
                        foreach ($display_sizes_s as $index => $size): ?>
                          <td class="<?= $index == $last_spirit_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Spirits']['sales'][$size] > 0 ? $daily_data[$date]['Spirits']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Imported Spirit Sales -->
                        <?php 
                        $last_imported_index = count($display_sizes_imported) - 1;
                        foreach ($display_sizes_imported as $index => $size): ?>
                           <td class="<?= $index == $last_imported_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Imported Spirit']['sales'][$size] > 0 ? $daily_data[$date]['Imported Spirit']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wines Sales -->
                        <?php 
                        $last_wine_index = count($display_sizes_w) - 1;
                        foreach ($display_sizes_w as $index => $size): ?>
                          <td class="<?= $index == $last_wine_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wines']['sales'][$size] > 0 ? $daily_data[$date]['Wines']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wine Imp Sales -->
                        <?php 
                        $last_wine_imp_index = count($display_sizes_wine_imp) - 1;
                        foreach ($display_sizes_wine_imp as $index => $size): ?>
                          <td class="<?= $index == $last_wine_imp_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wine Imp']['sales'][$size] > 0 ? $daily_data[$date]['Wine Imp']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Fermented Beer Sales -->
                        <?php 
                        $last_fermented_index = count($display_sizes_fb) - 1;
                        foreach ($display_sizes_fb as $index => $size): ?>
                          <td class="<?= $index == $last_fermented_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Fermented Beer']['sales'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Mild Beer Sales -->
                        <?php foreach ($display_sizes_mb as $size): ?>
                          <td><?= $daily_data[$date]['Mild Beer']['sales'][$size] > 0 ? $daily_data[$date]['Mild Beer']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tr>
                    
                    <tr>
                      <td>Clo.</td>
                      
                      <?php if ($mode == 'Country Liquor'): ?>
                        <!-- Country Liquor Closing Stock -->
                        <?php 
                        $last_country_index = count($display_sizes_country) - 1;
                        foreach ($display_sizes_country as $index => $size): ?>
                          <td class="<?= $index == $last_country_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Country Liquor']['closing'][$size] > 0 ? $daily_data[$date]['Country Liquor']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <!-- Spirits Closing Stock -->
                        <?php 
                        $last_spirit_index = count($display_sizes_s) - 1;
                        foreach ($display_sizes_s as $index => $size): ?>
                          <td class="<?= $index == $last_spirit_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Spirits']['closing'][$size] > 0 ? $daily_data[$date]['Spirits']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Imported Spirit Closing Stock -->
                        <?php 
                        $last_imported_index = count($display_sizes_imported) - 1;
                        foreach ($display_sizes_imported as $index => $size): ?>
                           <td class="<?= $index == $last_imported_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Imported Spirit']['closing'][$size] > 0 ? $daily_data[$date]['Imported Spirit']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wines Closing Stock -->
                        <?php 
                        $last_wine_index = count($display_sizes_w) - 1;
                        foreach ($display_sizes_w as $index => $size): ?>
                          <td class="<?= $index == $last_wine_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wines']['closing'][$size] > 0 ? $daily_data[$date]['Wines']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wine Imp Closing Stock -->
                        <?php 
                        $last_wine_imp_index = count($display_sizes_wine_imp) - 1;
                        foreach ($display_sizes_wine_imp as $index => $size): ?>
                          <td class="<?= $index == $last_wine_imp_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wine Imp']['closing'][$size] > 0 ? $daily_data[$date]['Wine Imp']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Fermented Beer Closing Stock -->
                        <?php 
                        $last_fermented_index = count($display_sizes_fb) - 1;
                        foreach ($display_sizes_fb as $index => $size): ?>
                          <td class="<?= $index == $last_fermented_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Fermented Beer']['closing'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Mild Beer Closing Stock -->
                        <?php foreach ($display_sizes_mb as $size): ?>
                          <td><?= $daily_data[$date]['Mild Beer']['closing'][$size] > 0 ? $daily_data[$date]['Mild Beer']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tr>
                    
                    <?php $first_date = false; ?>
                    
                  <?php else: ?>
                    <!-- Subsequent dates - Show only 3 rows (Rec, Sale, Clo) -->
                    <tr>
                      <td rowspan="3" class="date-col">
                        <div class="date-display">
                          <span><?= $day_num ?></span>
                          <span><?= $month_num ?></span>
                          <span><?= $year_num ?></span>
                        </div>
                      </td>
                      <td rowspan="3" class="tp-nos">
                        <?php if (!empty($tp_nos)): ?>
                          <?php foreach ($tp_nos as $tp_no): ?>
                            <span><?= $tp_no ?></span>
                          <?php endforeach; ?>
                        <?php else: ?>
                          &nbsp;
                        <?php endif; ?>
                      </td>
                      <td>Rec.</td>
                      
                      <?php if ($mode == 'Country Liquor'): ?>
                        <!-- Country Liquor Received -->
                        <?php 
                        $last_country_index = count($display_sizes_country) - 1;
                        foreach ($display_sizes_country as $index => $size): ?>
                          <td class="<?= $index == $last_country_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Country Liquor']['purchase'][$size] > 0 ? $daily_data[$date]['Country Liquor']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <!-- Spirits Received -->
                        <?php 
                        $last_spirit_index = count($display_sizes_s) - 1;
                        foreach ($display_sizes_s as $index => $size): ?>
                          <td class="<?= $index == $last_spirit_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Spirits']['purchase'][$size] > 0 ? $daily_data[$date]['Spirits']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Imported Spirit Received -->
                        <?php 
                        $last_imported_index = count($display_sizes_imported) - 1;
                        foreach ($display_sizes_imported as $index => $size): ?>
                          <td class="<?= $index == $last_imported_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Imported Spirit']['purchase'][$size] > 0 ? $daily_data[$date]['Imported Spirit']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wines Received -->
                        <?php 
                        $last_wine_index = count($display_sizes_w) - 1;
                        foreach ($display_sizes_w as $index => $size): ?>
                          <td class="<?= $index == $last_wine_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wines']['purchase'][$size] > 0 ? $daily_data[$date]['Wines']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wine Imp Received -->
                        <?php 
                        $last_wine_imp_index = count($display_sizes_wine_imp) - 1;
                        foreach ($display_sizes_wine_imp as $index => $size): ?>
                          <td class="<?= $index == $last_wine_imp_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wine Imp']['purchase'][$size] > 0 ? $daily_data[$date]['Wine Imp']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Fermented Beer Received -->
                        <?php 
                        $last_fermented_index = count($display_sizes_fb) - 1;
                        foreach ($display_sizes_fb as $index => $size): ?>
                          <td class="<?= $index == $last_fermented_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Fermented Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Mild Beer Received -->
                        <?php foreach ($display_sizes_mb as $size): ?>
                          <td><?= $daily_data[$date]['Mild Beer']['purchase'][$size] > 0 ? $daily_data[$date]['Mild Beer']['purchase'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tr>
                    
                    <tr>
                      <td>Sale</td>
                      
                      <?php if ($mode == 'Country Liquor'): ?>
                        <!-- Country Liquor Sales -->
                        <?php 
                        $last_country_index = count($display_sizes_country) - 1;
                        foreach ($display_sizes_country as $index => $size): ?>
                          <td class="<?= $index == $last_country_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Country Liquor']['sales'][$size] > 0 ? $daily_data[$date]['Country Liquor']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <!-- Spirits Sales -->
                        <?php 
                        $last_spirit_index = count($display_sizes_s) - 1;
                        foreach ($display_sizes_s as $index => $size): ?>
                          <td class="<?= $index == $last_spirit_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Spirits']['sales'][$size] > 0 ? $daily_data[$date]['Spirits']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Imported Spirit Sales -->
                        <?php 
                        $last_imported_index = count($display_sizes_imported) - 1;
                        foreach ($display_sizes_imported as $index => $size): ?>
                          <td class="<?= $index == $last_imported_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Imported Spirit']['sales'][$size] > 0 ? $daily_data[$date]['Imported Spirit']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wines Sales -->
                        <?php 
                        $last_wine_index = count($display_sizes_w) - 1;
                        foreach ($display_sizes_w as $index => $size): ?>
                          <td class="<?= $index == $last_wine_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wines']['sales'][$size] > 0 ? $daily_data[$date]['Wines']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wine Imp Sales -->
                        <?php 
                        $last_wine_imp_index = count($display_sizes_wine_imp) - 1;
                        foreach ($display_sizes_wine_imp as $index => $size): ?>
                          <td class="<?= $index == $last_wine_imp_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wine Imp']['sales'][$size] > 0 ? $daily_data[$date]['Wine Imp']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Fermented Beer Sales -->
                        <?php 
                        $last_fermented_index = count($display_sizes_fb) - 1;
                        foreach ($display_sizes_fb as $index => $size): ?>
                          <td class="<?= $index == $last_fermented_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Fermented Beer']['sales'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Mild Beer Sales -->
                        <?php foreach ($display_sizes_mb as $size): ?>
                          <td><?= $daily_data[$date]['Mild Beer']['sales'][$size] > 0 ? $daily_data[$date]['Mild Beer']['sales'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tr>
                    
                    <tr>
                      <td>Clo.</td>
                      
                      <?php if ($mode == 'Country Liquor'): ?>
                        <!-- Country Liquor Closing Stock -->
                        <?php 
                        $last_country_index = count($display_sizes_country) - 1;
                        foreach ($display_sizes_country as $index => $size): ?>
                          <td class="<?= $index == $last_country_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Country Liquor']['closing'][$size] > 0 ? $daily_data[$date]['Country Liquor']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <!-- Spirits Closing Stock -->
                        <?php 
                        $last_spirit_index = count($display_sizes_s) - 1;
                        foreach ($display_sizes_s as $index => $size): ?>
                          <td class="<?= $index == $last_spirit_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Spirits']['closing'][$size] > 0 ? $daily_data[$date]['Spirits']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Imported Spirit Closing Stock -->
                        <?php 
                        $last_imported_index = count($display_sizes_imported) - 1;
                        foreach ($display_sizes_imported as $index => $size): ?>
                          <td class="<?= $index == $last_imported_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Imported Spirit']['closing'][$size] > 0 ? $daily_data[$date]['Imported Spirit']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wines Closing Stock -->
                        <?php 
                        $last_wine_index = count($display_sizes_w) - 1;
                        foreach ($display_sizes_w as $index => $size): ?>
                          <td class="<?= $index == $last_wine_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wines']['closing'][$size] > 0 ? $daily_data[$date]['Wines']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Wine Imp Closing Stock -->
                        <?php 
                        $last_wine_imp_index = count($display_sizes_wine_imp) - 1;
                        foreach ($display_sizes_wine_imp as $index => $size): ?>
                          <td class="<?= $index == $last_wine_imp_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Wine Imp']['closing'][$size] > 0 ? $daily_data[$date]['Wine Imp']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Fermented Beer Closing Stock -->
                        <?php 
                        $last_fermented_index = count($display_sizes_fb) - 1;
                        foreach ($display_sizes_fb as $index => $size): ?>
                          <td class="<?= $index == $last_fermented_index ? 'double-line-right' : '' ?>"><?= $daily_data[$date]['Fermented Beer']['closing'][$size] > 0 ? $daily_data[$date]['Fermented Beer']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                        
                        <!-- Mild Beer Closing Stock -->
                        <?php foreach ($display_sizes_mb as $size): ?>
                          <td><?= $daily_data[$date]['Mild Beer']['closing'][$size] > 0 ? $daily_data[$date]['Mild Beer']['closing'][$size] : '' ?></td>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </tr>
                  <?php endif; ?>
                  
                <?php endforeach; ?>
                
                <?php if ($date_count == 0): ?>
                  <tr>
                    <td colspan="<?= 3 + $total_columns ?>" class="text-center">No data available for the selected date range.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          
          <div class="footer-info">
            <p>Generated on: <?= date('d-M-Y h:i A') ?> | Total Days: <?= $date_count ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportToExcel() {
  // Get the table element
  var table = document.getElementById('excise-register-table');
  
  // Create a new workbook
  var wb = XLSX.utils.book_new();
  
  // Clone the table to avoid modifying the original
  var tableClone = table.cloneNode(true);
  
  // Convert table to worksheet
  var ws = XLSX.utils.table_to_sheet(tableClone);
  
  // Add worksheet to workbook
  XLSX.utils.book_append_sheet(wb, ws, 'Excise Register');
  
  // Generate Excel file and download
  var fileName = 'Excise_Register_<?= date('Y-m-d') ?>.xlsx';
  XLSX.writeFile(wb, fileName);
}

// Load XLSX library dynamically
if (typeof XLSX === 'undefined') {
  var script = document.createElement('script');
  script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
  script.onload = function() {
    console.log('XLSX library loaded');
  };
  document.head.appendChild(script);
}
</script>
</body>
</html>