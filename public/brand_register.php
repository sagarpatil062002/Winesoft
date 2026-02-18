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
require_once 'license_functions.php';

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// Check if report should be shown
$show_report = isset($_GET['generate']) || (isset($_GET['from_date']) && isset($_GET['to_date']));

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

// Function to get table name for a specific date
function getTableForDate($conn, $compID, $date) {
    $current_month = date('Y-m');
    $target_month = date('Y-m', strtotime($date));
    
    // Current month uses main table
    if ($target_month == $current_month) {
        $table_name = "tbldailystock_" . $compID;
        
        // Check if main table exists, if not use default
        $tableCheckQuery = "SHOW TABLES LIKE '$table_name'";
        $tableCheckResult = $conn->query($tableCheckQuery);
        if ($tableCheckResult->num_rows == 0) {
            $table_name = "tbldailystock_1";
        }
        
        return $table_name;
    } 
    // Previous months use archive table
    else {
        $month = date('m', strtotime($date));
        $year = date('y', strtotime($date));
        $table_name = "tbldailystock_" . $compID . "_" . $month . "_" . $year;
        
        // Check if archive table exists
        $tableCheckQuery = "SHOW TABLES LIKE '$table_name'";
        $tableCheckResult = $conn->query($tableCheckQuery);
        if ($tableCheckResult->num_rows > 0) {
            return $table_name;
        } else {
            // If archive table doesn't exist, try main table as fallback
            $main_table = "tbldailystock_" . $compID;
            $tableCheckQuery = "SHOW TABLES LIKE '$main_table'";
            $tableCheckResult = $conn->query($tableCheckQuery);
            if ($tableCheckResult->num_rows == 0) {
                return "tbldailystock_1";
            }
            return $main_table;
        }
    }
}

// Function to get all tables needed for date range
function getTablesForDateRange($conn, $compID, $from_date, $to_date) {
    $tables = [];
    
    // Create an array of all dates in the range
    $start = new DateTime($from_date);
    $end = new DateTime($to_date);
    $interval = new DateInterval('P1D');
    $dateRange = new DatePeriod($start, $interval, $end->modify('+1 day'));
    
    // Group dates by table
    foreach ($dateRange as $date) {
        $dateStr = $date->format('Y-m-d');
        $table_name = getTableForDate($conn, $compID, $dateStr);
        
        if (!isset($tables[$table_name])) {
            $tables[$table_name] = [
                'dates' => [],
                'months' => []
            ];
        }
        
        $tables[$table_name]['dates'][] = $dateStr;
        $month = $date->format('Y-m');
        if (!in_array($month, $tables[$table_name]['months'])) {
            $tables[$table_name]['months'][] = $month;
        }
    }
    
    return $tables;
}

// Check if specific day columns exist in a table
function tableHasDayColumns($conn, $table_name, $day) {
    $day_padded = sprintf('%02d', $day);
    $checkOpenQuery = "SHOW COLUMNS FROM $table_name LIKE 'DAY_{$day_padded}_OPEN'";
    $checkPurchaseQuery = "SHOW COLUMNS FROM $table_name LIKE 'DAY_{$day_padded}_PURCHASE'";
    $checkSalesQuery = "SHOW COLUMNS FROM $table_name LIKE 'DAY_{$day_padded}_SALES'";
    $checkClosingQuery = "SHOW COLUMNS FROM $table_name LIKE 'DAY_{$day_padded}_CLOSING'";
    
    $openResult = $conn->query($checkOpenQuery);
    $purchaseResult = $conn->query($checkPurchaseQuery);
    $salesResult = $conn->query($checkSalesQuery);
    $closingResult = $conn->query($checkClosingQuery);
    
    // All four columns must exist for the day to be valid
    return ($openResult->num_rows > 0 && $purchaseResult->num_rows > 0 && 
            $salesResult->num_rows > 0 && $closingResult->num_rows > 0);
}

// Function to group sizes by base size (remove suffixes after ML and trim)
function getBaseSize($size) {
    // Extract the base size (everything before any special characters after ML)
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize); // Remove trailing - numbers
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize); // Remove trailing (numbers)
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize); // Remove anything in parentheses
    return trim($baseSize);
}

// Function to extract brand name from item details
function getBrandName($details) {
    // Remove size patterns (ML, CL, L, etc. with numbers)
    $brandName = preg_replace('/\s*\d+\s*(ML|CL|L).*$/i', '', $details);
    $brandName = preg_replace('/\s*\([^)]*\)\s*$/', '', $brandName); // Remove trailing parentheses
    $brandName = preg_replace('/\s*-\s*\d+$/', '', $brandName); // Remove trailing - numbers
    return trim($brandName);
}

// Define display sizes for each liquor type (using base sizes)
$display_sizes_s = ['2000 ML', '1000 ML', '750 ML', '700 ML', '500 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_imported = $display_sizes_s; // Imported uses same sizes as Spirit
$display_sizes_w = ['750 ML', '375 ML', '180 ML', '90 ML'];
$display_sizes_wine_imp = $display_sizes_w; // Wine Imp uses same sizes as Wine
$display_sizes_fb = ['650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];
$display_sizes_mb = ['650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];

// Combine all sizes in the required order for display (without duplicates)
$all_display_sizes = array_merge(
    $display_sizes_s,
    $display_sizes_imported,
    $display_sizes_w,
    $display_sizes_wine_imp,
    $display_sizes_fb,
    $display_sizes_mb
);

// Remove duplicates while preserving order
$all_display_sizes = array_values(array_unique($all_display_sizes));

// Map database sizes to display sizes
$size_mapping = [
    // Spirits
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '90 ML' => '90 ML',
    '90 ML-100' => '90 ML',
    '90 ML-96' => '90 ML',
    '2000 ML' => '2000 ML',
    '2000 ML Pet' => '2000 ML',
    '1000 ML' => '1000 ML',
    '700 ML' => '700 ML',
    '500 ML' => '500 ML',
    '350 ML' => '350 ML',
    '275 ML' => '275 ML',
    '200 ML' => '200 ML',
    '180 ML' => '180 ML',
    '170 ML' => '180 ML',
    '60 ML' => '60 ML',
    '50 ML' => '50 ML',
    
    // Wines
    '750 ML' => '750 ML',
    '375 ML' => '375 ML',
    '180 ML' => '180 ML',
    '90 ML' => '90 ML',
    
    // Fermented Beer
    '650 ML' => '650 ML',
    '500 ML' => '500 ML',
    '500 ML (CAN)' => '500 ML',
    '330 ML' => '330 ML',
    '330 ML (CAN)' => '330 ML',
    '275 ML' => '275 ML',
    '250 ML' => '250 ML',
    
    // Mild Beer
    '650 ML' => '650 ML',
    '500 ML (CAN)' => '500 ML',
    '330 ML' => '330 ML',
    '330 ML (CAN)' => '330 ML',
    '275 ML' => '275 ML',
    '250 ML' => '250 ML'
];

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

// Fetch item master data with size information and extract brand names - WITH LICENSE FILTERING
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster WHERE CLASS IN ($class_placeholders)";
    
    $stmt = $conn->prepare($itemQuery);
    $stmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
    $stmt->execute();
    $itemResult = $stmt->get_result();
} else {
    // If no classes allowed, return empty result
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster WHERE 1 = 0";
    $itemResult = $conn->query($itemQuery);
}

while ($row = $itemResult->fetch_assoc()) {
    $items[$row['CODE']] = $row;
}
if (isset($stmt)) {
    $stmt->close();
}

// Function to determine liquor type based on CLASS and LIQ_FLAG (from excise_register.php)
function getLiquorType($class, $liq_flag, $desc = '') {
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

// Function to get grouped size based on liquor type
function getGroupedSize($size, $liquor_type) {
    global $display_sizes_s, $display_sizes_w, $display_sizes_fb, $display_sizes_mb;
    
    $baseSize = getBaseSize($size);
    
    // Check if this base size exists in the appropriate group
    switch ($liquor_type) {
        case 'Spirits':
        case 'Imported Spirit':
            if (in_array($baseSize, $display_sizes_s)) {
                return $baseSize;
            }
            break;
        case 'Wines':
        case 'Wine Imp':
            if (in_array($baseSize, $display_sizes_w)) {
                return $baseSize;
            }
            break;
        case 'Fermented Beer':
            if (in_array($baseSize, $display_sizes_fb)) {
                return $baseSize;
            }
            break;
        case 'Mild Beer':
            if (in_array($baseSize, $display_sizes_mb)) {
                return $baseSize;
            }
            break;
    }
    
    return $baseSize; // Return base size even if not found in predefined groups
}

// NEW: Restructure data by liquor type -> brand
$brand_data_by_category = [
    'Spirits' => [],
    'Imported Spirit' => [],
    'Wines' => [],
    'Wine Imp' => [],
    'Fermented Beer' => [],
    'Mild Beer' => []
];

// Initialize category totals
$category_totals = [
    'Spirits' => [
        'purchase' => array_fill_keys($all_display_sizes, 0),
        'sales' => array_fill_keys($all_display_sizes, 0),
        'closing' => array_fill_keys($all_display_sizes, 0)
    ],
    'Imported Spirit' => [
        'purchase' => array_fill_keys($all_display_sizes, 0),
        'sales' => array_fill_keys($all_display_sizes, 0),
        'closing' => array_fill_keys($all_display_sizes, 0)
    ],
    'Wines' => [
        'purchase' => array_fill_keys($all_display_sizes, 0),
        'sales' => array_fill_keys($all_display_sizes, 0),
        'closing' => array_fill_keys($all_display_sizes, 0)
    ],
    'Wine Imp' => [
        'purchase' => array_fill_keys($all_display_sizes, 0),
        'sales' => array_fill_keys($all_display_sizes, 0),
        'closing' => array_fill_keys($all_display_sizes, 0)
    ],
    'Fermented Beer' => [
        'purchase' => array_fill_keys($all_display_sizes, 0),
        'sales' => array_fill_keys($all_display_sizes, 0),
        'closing' => array_fill_keys($all_display_sizes, 0)
    ],
    'Mild Beer' => [
        'purchase' => array_fill_keys($all_display_sizes, 0),
        'sales' => array_fill_keys($all_display_sizes, 0),
        'closing' => array_fill_keys($all_display_sizes, 0)
    ]
];

// Store TP Nos data by brand (only for items with purchase)
$brand_tp_nos = [];

// Get all tables needed for the date range
$tables_needed = getTablesForDateRange($conn, $compID, $from_date, $to_date);

// Store cumulative stock data for each item
$cumulative_stock_data = [];

// Fetch TP Nos from tblpurchases
$tpQuery = "SELECT TPNO, DATE FROM tblpurchases WHERE CompID = ? AND DATE BETWEEN ? AND ?";
$tpStmt = $conn->prepare($tpQuery);
$tpStmt->bind_param("iss", $compID, $from_date, $to_date);
$tpStmt->execute();
$tpResult = $tpStmt->get_result();

$tp_nos_by_date = [];
while ($row = $tpResult->fetch_assoc()) {
    if (!isset($tp_nos_by_date[$row['DATE']])) {
        $tp_nos_by_date[$row['DATE']] = [];
    }
    if (!empty($row['TPNO'])) {
        $tp_nos_by_date[$row['DATE']][] = $row['TPNO'];
    }
}
$tpStmt->close();

// Process each table
foreach ($tables_needed as $table_name => $table_info) {
    $months = $table_info['months'];
    $dates = $table_info['dates'];
    
    // Process each month in this table
    foreach ($months as $month) {
        // Get all dates in this month that are in our date range
        $month_dates = array_filter($dates, function($date) use ($month) {
            return date('Y-m', strtotime($date)) == $month;
        });
        
        if (empty($month_dates)) continue;
        
        // For each date in this month, fetch the purchase data
        foreach ($month_dates as $current_date) {
            $day = date('d', strtotime($current_date));
            
            // Check if this specific table has columns for this specific day
            if (!tableHasDayColumns($conn, $table_name, $day)) {
                continue;
            }
            
            // Fetch all stock data for this month and day
            $stockQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                          DAY_{$day}_OPEN as opening, 
                          DAY_{$day}_PURCHASE as purchase, 
                          DAY_{$day}_SALES as sales, 
                          DAY_{$day}_CLOSING as closing 
                          FROM $table_name 
                          WHERE STK_MONTH = ?";
            
            $stockStmt = $conn->prepare($stockQuery);
            $stockStmt->bind_param("s", $month);
            $stockStmt->execute();
            $stockResult = $stockStmt->get_result();
            
            while ($row = $stockResult->fetch_assoc()) {
                $item_code = $row['ITEM_CODE'];
                
                // Skip if item not found in master or not allowed by license
                if (!isset($items[$item_code])) continue;
                
                // Initialize item data if not exists
                if (!isset($cumulative_stock_data[$item_code])) {
                    $cumulative_stock_data[$item_code] = [
                        'purchase' => 0,
                        'sales' => 0,
                        'closing' => 0,
                        'liq_flag' => $row['LIQ_FLAG'],
                        'last_date' => $current_date
                    ];
                }
                
                // Accumulate purchase (cumulative)
                $cumulative_stock_data[$item_code]['purchase'] += $row['purchase'];
                
                // Accumulate sales (cumulative)
                $cumulative_stock_data[$item_code]['sales'] += $row['sales'];
                
                // For closing balance, always take the latest value (last day in range)
                $cumulative_stock_data[$item_code]['closing'] = $row['closing'];
                $cumulative_stock_data[$item_code]['last_date'] = $current_date;
                
                // Update LIQ_FLAG if not set
                if (empty($cumulative_stock_data[$item_code]['liq_flag'])) {
                    $cumulative_stock_data[$item_code]['liq_flag'] = $row['LIQ_FLAG'];
                }
                
                // Store TP Nos ONLY if there was a purchase on this date
                if ($row['purchase'] > 0 && isset($tp_nos_by_date[$current_date])) {
                    if (!isset($brand_tp_nos[$item_code])) {
                        $brand_tp_nos[$item_code] = [];
                    }
                    foreach ($tp_nos_by_date[$current_date] as $tp_no) {
                        if (!in_array($tp_no, $brand_tp_nos[$item_code])) {
                            $brand_tp_nos[$item_code][] = $tp_no;
                        }
                    }
                }
            }
            
            $stockStmt->close();
        }
    }
}

// Process cumulative stock data - Only process items with non-zero stock
foreach ($cumulative_stock_data as $item_code => $stock_data) {
    // Skip items with zero purchase, sales AND closing
    if ($stock_data['purchase'] == 0 && $stock_data['sales'] == 0 && $stock_data['closing'] == 0) {
        continue; // Skip items with no stock activity
    }
    
    $item_details = $items[$item_code];
    $size = $item_details['DETAILS2'];
    $class = $item_details['CLASS'];
    $liq_flag = $stock_data['liq_flag'];
    
    // Extract brand name
    $brandName = getBrandName($item_details['DETAILS']);
    if (empty($brandName)) continue;
    
    // Determine liquor type using the improved logic from excise_register.php
    $liquor_type = getLiquorType($class, $liq_flag, $classData[$class]['DESC'] ?? '');
    
    // Map database size to display size
    $display_size = isset($size_mapping[$size]) ? $size_mapping[$size] : getBaseSize($size);
    
    // Get grouped size based on liquor type
    $grouped_size = getGroupedSize($display_size, $liquor_type);
    
    // Initialize brand data if not exists
    if (!isset($brand_data_by_category[$liquor_type][$brandName])) {
        $brand_data_by_category[$liquor_type][$brandName] = [
            'tp_nos' => [],
            'sizes' => array_fill_keys($all_display_sizes, [
                'purchase' => 0,
                'sales' => 0,
                'closing' => 0
            ])
        ];
    }
    
    // Add TP Nos (only for items with purchases)
    if (isset($brand_tp_nos[$item_code])) {
        foreach ($brand_tp_nos[$item_code] as $tp_no) {
            if (!in_array($tp_no, $brand_data_by_category[$liquor_type][$brandName]['tp_nos'])) {
                $brand_data_by_category[$liquor_type][$brandName]['tp_nos'][] = $tp_no;
            }
        }
    }
    
    // Add to brand data
    if (isset($brand_data_by_category[$liquor_type][$brandName]['sizes'][$grouped_size])) {
        $brand_data_by_category[$liquor_type][$brandName]['sizes'][$grouped_size]['purchase'] += $stock_data['purchase'];
        $brand_data_by_category[$liquor_type][$brandName]['sizes'][$grouped_size]['sales'] += $stock_data['sales'];
        $brand_data_by_category[$liquor_type][$brandName]['sizes'][$grouped_size]['closing'] = $stock_data['closing'];
        
        // Update category totals
        $category_totals[$liquor_type]['purchase'][$grouped_size] += $stock_data['purchase'];
        $category_totals[$liquor_type]['sales'][$grouped_size] += $stock_data['sales'];
        $category_totals[$liquor_type]['closing'][$grouped_size] += $stock_data['closing'];
    }
}

// Filter out brands with zero stock across all sizes
foreach ($brand_data_by_category as $category => $brands) {
    foreach ($brands as $brand => $brand_info) {
        $hasStock = false;
        
        // Check if brand has any non-zero values in purchase, sales, or closing
        foreach ($brand_info['sizes'] as $size => $values) {
            if ($values['purchase'] > 0 || $values['sales'] > 0 || $values['closing'] > 0) {
                $hasStock = true;
                break;
            }
        }
        
        // Remove brand if no stock
        if (!$hasStock) {
            unset($brand_data_by_category[$category][$brand]);
        }
    }
}

// Calculate column positions for double lines
$received_start = 3; // After Sr No, Brand Name, TP NO
$received_end = $received_start + count($all_display_sizes);
$sold_start = $received_end;
$sold_end = $sold_start + count($all_display_sizes);
$closing_start = $sold_end;
$closing_end = $closing_start + count($all_display_sizes);
$total_columns = count($all_display_sizes) * 3; // Received, Sold, Closing
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FLR-3A Brandwise Register - liqoursoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <!-- Include shortcuts functionality -->
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    /* Screen styles */
    body {
      font-size: 12px;
      background-color: #f8f9fa;
      overflow-x: hidden;
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
    
    /* FIXED SCROLLING CONTAINER */
    .table-container {
      width: 100%;
      overflow-x: auto;
      overflow-y: visible;
      position: relative;
      margin-bottom: 15px;
      border: 1px solid #dee2e6;
      max-height: calc(100vh - 300px);
    }

    /* Fixed horizontal scrollbar container */
    .scrollbar-container {
      position: sticky;
      bottom: 0;
      left: 0;
      width: 100%;
      height: 20px;
      background-color: #f8f9fa;
      border-top: 1px solid #dee2e6;
      z-index: 1000;
      overflow-x: auto;
      overflow-y: hidden;
    }

    .scrollbar-content {
      height: 1px;
      min-width: 100%;
    }
    
    .report-table {
      width: auto;
      min-width: 100%;
      border-collapse: collapse;
      font-size: 10px;
      margin-bottom: 0;
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
    
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
    }
    
    .category-header {
      background-color: #d1ecf1;
      font-weight: bold;
      text-align: left;
      padding-left: 10px;
      border-bottom: double 3px #000;
    }

    .category-total-row {
      background-color: #f8f9fa;
      font-weight: bold;
      border-top: double 3px #000;
    }

    /* Double line separators */
    .report-table td:nth-child(<?= $received_end ?>),
    .report-table th:nth-child(<?= $received_end ?>) {
      border-right: double 3px #000 !important;
    }

    .report-table td:nth-child(<?= $sold_end ?>),
    .report-table th:nth-child(<?= $sold_end ?>) {
      border-right: double 3px #000 !important;
    }

    .filter-card {
      background-color: #f8f9fa;
    }
    
    .action-controls {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    
    .no-print {
      display: block;
    }
    
    .print-content {
      display: none;
    }

    /* Show report on screen when needed */
    .print-content.screen-display {
        display: block !important;
        margin-top: 20px;
        background: white;
        padding: 15px;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    @media screen {
        .print-content.screen-display .table-container {
            max-height: 70vh;
        }
    }

    /* TP Nos styling */
    .tp-nos-list {
      font-size: 9px;
      line-height: 1.2;
      text-align: left;
      padding: 2px;
      max-width: 100px;
      white-space: normal;
      word-wrap: break-word;
    }
    .tp-nos-list span {
      display: inline-block;
      margin-right: 3px;
      background-color: #e9ecef;
      padding: 1px 3px;
      border-radius: 3px;
      font-size: 8px;
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
      }
      
      .no-print {
        display: none !important;
      }
      
      .print-content {
        display: block !important;
      }
      
      .company-header {
        text-align: center;
        margin-bottom: 5px;
        padding: 2px;
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
      
      .table-container {
        overflow: visible !important;
        width: 100% !important;
        border: none !important;
      }
      
      .report-table {
        width: 100% !important;
        font-size: 6px !important;
        table-layout: fixed;
        border-collapse: collapse;
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
      
      .date-col, .permit-col {
        width: 25px !important;
        min-width: 25px !important;
        max-width: 25px !important;
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
      
      .category-header {
        background-color: #d1ecf1 !important;
        font-weight: bold;
      }
      
      .category-total-row {
        background-color: #f8f9fa !important;
        font-weight: bold;
      }
      
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 6px;
      }

      /* Double lines in print */
      .report-table td:nth-child(<?= $received_end ?>),
      .report-table th:nth-child(<?= $received_end ?>),
      .report-table td:nth-child(<?= $sold_end ?>),
      .report-table th:nth-child(<?= $sold_end ?>) {
        border-right: double 3px #000 !important;
      }

      .tp-nos-list {
        font-size: 5px !important;
        line-height: 1;
      }
      .tp-nos-list span {
        padding: 0px 1px;
        margin-right: 1px;
      }
    }

    /* Stock info note */
    .stock-info-note {
        background-color: #e7f3ff;
        border-left: 4px solid #007bff;
        padding: 8px;
        margin: 10px 0;
        font-size: 0.9em;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">FLR-3A Brandwise Register</h3>

      <!-- License Restriction Info -->
      <div class="alert alert-info mb-3 no-print">
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
                <label class="form-label">From Date:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate
              </button>
              <button type="button" class="btn btn-success" onclick="generateReport()">
                <i class="fas fa-print me-1"></i> Print Report
              </button>
              <button type="button" class="btn btn-info" onclick="exportToExcel()">
                <i class="fas fa-file-excel me-1"></i> Export to Excel
              </button>
              <button type="button" class="btn btn-warning" onclick="exportToCSV()">
                <i class="fas fa-file-csv me-1"></i> Export to CSV
              </button>
              <button type="button" class="btn btn-danger" onclick="exportToPDF()">
                <i class="fas fa-file-pdf me-1"></i> Export to PDF
              </button>
              <a href="dashboard.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-times me-1"></i> Exit
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Report Results -->
      <div id="reportContent" class="<?= $show_report ? 'print-content screen-display' : 'print-content' ?>">
        <div class="company-header">
          <h1>Form F.L.R. 3A - Brandwise Register (See Rule 15)</h1>
          <h5>REGISTER OF TRANSACTION OF FOREIGN LIQUOR EFFECTED BY HOLDER OF VENDOR'S/HOTEL/CLUB LICENCE</h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
        </div>
        
        <!-- Stock Info Note -->
        <div class="stock-info-note">
          <strong><i class="fas fa-info-circle"></i> Note:</strong> Only brands with stock > 0 are displayed in this report. TP Nos shown only for purchases made during the period.
        </div>
        
        <!-- FIXED SCROLLING CONTAINER -->
        <div class="table-container">
          <table class="report-table">
            <thead>
              <tr>
                <th rowspan="2" class="date-col">Sr. No.</th>
                <th rowspan="2" class="permit-col">Brand Name</th>
                <th rowspan="2" class="permit-col">TP NO</th>
                <th colspan="<?= count($all_display_sizes) ?>">RECEIVED</th>
                <th colspan="<?= count($all_display_sizes) ?>">SOLD</th>
                <th colspan="<?= count($all_display_sizes) ?>">CLOSING BALANCE</th>
              </tr>
              <tr>
                <!-- Sizes row - Display all sizes once for each section -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <th class="size-col"><?= $size ?></th>
                <?php endforeach; ?>
                
                <?php foreach ($all_display_sizes as $size): ?>
                  <th class="size-col"><?= $size ?></th>
                <?php endforeach; ?>
                
                <?php foreach ($all_display_sizes as $size): ?>
                  <th class="size-col"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php $sr_no = 1; ?>

              <!-- Spirits Section -->
              <?php if (!empty($brand_data_by_category['Spirits'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">SPIRITS</td>
              </tr>
              <?php foreach ($brand_data_by_category['Spirits'] as $brand => $brand_info): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  <td class="tp-nos-list">
                    <?php if (!empty($brand_info['tp_nos'])): ?>
                      <?php foreach (array_slice($brand_info['tp_nos'], 0, 3) as $tp_no): ?>
                        <span><?= htmlspecialchars($tp_no) ?></span>
                      <?php endforeach; ?>
                      <?php if (count($brand_info['tp_nos']) > 3): ?>
                        <span>...</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>

                  <!-- RECEIVED Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) ? $brand_info['sizes'][$size]['purchase'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) ? $brand_info['sizes'][$size]['sales'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) ? $brand_info['sizes'][$size]['closing'] : 0 ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- Spirits Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Spirits']['purchase'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Spirits']['sales'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Spirits']['closing'][$size] ?? 0 ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- Imported Spirit Section -->
              <?php if (!empty($brand_data_by_category['Imported Spirit'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">IMPORTED SPIRIT</td>
              </tr>
              <?php foreach ($brand_data_by_category['Imported Spirit'] as $brand => $brand_info): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  <td class="tp-nos-list">
                    <?php if (!empty($brand_info['tp_nos'])): ?>
                      <?php foreach (array_slice($brand_info['tp_nos'], 0, 3) as $tp_no): ?>
                        <span><?= htmlspecialchars($tp_no) ?></span>
                      <?php endforeach; ?>
                      <?php if (count($brand_info['tp_nos']) > 3): ?>
                        <span>...</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>

                  <!-- RECEIVED Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) ? $brand_info['sizes'][$size]['purchase'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) ? $brand_info['sizes'][$size]['sales'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) ? $brand_info['sizes'][$size]['closing'] : 0 ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- Imported Spirit Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Imported Spirit']['purchase'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Imported Spirit']['sales'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Imported Spirit']['closing'][$size] ?? 0 ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- Wines Section -->
              <?php if (!empty($brand_data_by_category['Wines'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">WINES</td>
              </tr>
              <?php foreach ($brand_data_by_category['Wines'] as $brand => $brand_info): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  <td class="tp-nos-list">
                    <?php if (!empty($brand_info['tp_nos'])): ?>
                      <?php foreach (array_slice($brand_info['tp_nos'], 0, 3) as $tp_no): ?>
                        <span><?= htmlspecialchars($tp_no) ?></span>
                      <?php endforeach; ?>
                      <?php if (count($brand_info['tp_nos']) > 3): ?>
                        <span>...</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>

                  <!-- RECEIVED Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) ? $brand_info['sizes'][$size]['purchase'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) ? $brand_info['sizes'][$size]['sales'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) ? $brand_info['sizes'][$size]['closing'] : 0 ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- Wines Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Wines']['purchase'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Wines']['sales'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Wines']['closing'][$size] ?? 0 ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- Wine Imp Section -->
              <?php if (!empty($brand_data_by_category['Wine Imp'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">WINE IMP</td>
              </tr>
              <?php foreach ($brand_data_by_category['Wine Imp'] as $brand => $brand_info): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  <td class="tp-nos-list">
                    <?php if (!empty($brand_info['tp_nos'])): ?>
                      <?php foreach (array_slice($brand_info['tp_nos'], 0, 3) as $tp_no): ?>
                        <span><?= htmlspecialchars($tp_no) ?></span>
                      <?php endforeach; ?>
                      <?php if (count($brand_info['tp_nos']) > 3): ?>
                        <span>...</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>

                  <!-- RECEIVED Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) ? $brand_info['sizes'][$size]['purchase'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) ? $brand_info['sizes'][$size]['sales'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) ? $brand_info['sizes'][$size]['closing'] : 0 ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- Wine Imp Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Wine Imp']['purchase'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Wine Imp']['sales'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Wine Imp']['closing'][$size] ?? 0 ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- Fermented Beer Section -->
              <?php if (!empty($brand_data_by_category['Fermented Beer'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">FERMENTED BEER</td>
              </tr>
              <?php foreach ($brand_data_by_category['Fermented Beer'] as $brand => $brand_info): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  <td class="tp-nos-list">
                    <?php if (!empty($brand_info['tp_nos'])): ?>
                      <?php foreach (array_slice($brand_info['tp_nos'], 0, 3) as $tp_no): ?>
                        <span><?= htmlspecialchars($tp_no) ?></span>
                      <?php endforeach; ?>
                      <?php if (count($brand_info['tp_nos']) > 3): ?>
                        <span>...</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>

                  <!-- RECEIVED Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) ? $brand_info['sizes'][$size]['purchase'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) ? $brand_info['sizes'][$size]['sales'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) ? $brand_info['sizes'][$size]['closing'] : 0 ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- Fermented Beer Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Fermented Beer']['purchase'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Fermented Beer']['sales'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Fermented Beer']['closing'][$size] ?? 0 ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>
              
              <!-- Mild Beer Section -->
              <?php if (!empty($brand_data_by_category['Mild Beer'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">MILD BEER</td>
              </tr>
              <?php foreach ($brand_data_by_category['Mild Beer'] as $brand => $brand_info): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  <td class="tp-nos-list">
                    <?php if (!empty($brand_info['tp_nos'])): ?>
                      <?php foreach (array_slice($brand_info['tp_nos'], 0, 3) as $tp_no): ?>
                        <span><?= htmlspecialchars($tp_no) ?></span>
                      <?php endforeach; ?>
                      <?php if (count($brand_info['tp_nos']) > 3): ?>
                        <span>...</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>

                  <!-- RECEIVED Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) ? $brand_info['sizes'][$size]['purchase'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) ? $brand_info['sizes'][$size]['sales'] : 0 ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) ? $brand_info['sizes'][$size]['closing'] : 0 ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- Mild Beer Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Mild Beer']['purchase'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Mild Beer']['sales'][$size] ?? 0 ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= $category_totals['Mild Beer']['closing'][$size] ?? 0 ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
          <div class="scrollbar-container">
            <div class="scrollbar-content"></div>
          </div>
        </div>
        
        <div class="footer-info">
          <div class="row mt-4">
            <div class="col-md-4">
              <p>Prepared By: ____________________</p>
            </div>
            <div class="col-md-4">
              <p>Verified By: ____________________</p>
            </div>
            <div class="col-md-4">
              <p>Date: <?= date('d/m/Y') ?></p>
            </div>
          </div>
          <p class="mt-3">Note: This register is maintained under FLR-3A format for excise compliance.</p>
          <p>License Type: <?= htmlspecialchars($license_type) ?></p>
          <p>Generated by liqoursoft on <?= date('d-M-Y h:i A') ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Function for print button
function generateReport() {
    window.print();
}

function exportToExcel() {
    // Get the table element
    var table = document.querySelector('.report-table');

    // Create a new workbook
    var wb = XLSX.utils.book_new();

    // Clone the table to avoid modifying the original
    var tableClone = table.cloneNode(true);

    // Convert table to worksheet
    var ws = XLSX.utils.table_to_sheet(tableClone);

    // Add worksheet to workbook
    XLSX.utils.book_append_sheet(wb, ws, 'Brand Register');

    // Generate Excel file and download
    var fileName = 'Brand_Register_<?= date('Y-m-d') ?>.xlsx';
    XLSX.writeFile(wb, fileName);
}

function exportToCSV() {
    // Get the table element
    var table = document.querySelector('.report-table');

    // Convert table to worksheet
    var ws = XLSX.utils.table_to_sheet(table);

    // Generate CSV file and download
    var fileName = 'Brand_Register_<?= date('Y-m-d') ?>.csv';
    XLSX.writeFile(ws, fileName);
}

function exportToPDF() {
    // Use html2pdf library to convert the report section to PDF
    const element = document.getElementById('reportContent');
    const opt = {
        margin: 0.5,
        filename: 'Brand_Register_<?= date('Y-m-d') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
    };

    // New Promise-based usage:
    html2pdf().set(opt).from(element).save();
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

// Load html2pdf library dynamically
if (typeof html2pdf === 'undefined') {
    var script2 = document.createElement('script');
    script2.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
    script2.onload = function() {
        console.log('html2pdf library loaded');
    };
    document.head.appendChild(script2);
}

// Show report immediately if filters were submitted
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('generate') || urlParams.has('from_date')) {
        const reportContent = document.getElementById('reportContent');
        if (reportContent) {
            reportContent.style.display = 'block';
            reportContent.classList.add('screen-display');
        }
    }
});
</script>
</body>
</html>