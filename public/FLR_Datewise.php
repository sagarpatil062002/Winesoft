<?php
session_start();

// Increase execution time for large reports
set_time_limit(180); // 3 minutes

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
require_once 'license_functions.php'; // Include license functions

// Get company ID from session
$compID = $_SESSION['CompID'];

// ADDED: Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// ADDED: Extract class codes for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Default values
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

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
    
    // Base table name
    $baseTable = "tbldailystock_" . $compID;
    
    // If target month is current month, use base table
    if ($target_month === $current_month) {
        return $baseTable;
    }
    
    // For previous months, use archive table format: tbldailystock_compid_mm_yy
    $month = date('m', strtotime($date));
    $year = date('y', strtotime($date));
    $archiveTable = $baseTable . "_" . $month . "_" . $year;
    
    // Check if archive table exists
    $tableCheckQuery = "SHOW TABLES LIKE '$archiveTable'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult->num_rows > 0) {
        return $archiveTable;
    }
    
    // If archive table doesn't exist, fall back to base table
    return $baseTable;
}

// Function to check if specific day columns exist in a table
function tableHasDayColumns($conn, $tableName, $day) {
    $checkDayQuery = "SHOW COLUMNS FROM $tableName LIKE 'DAY_{$day}_OPEN'";
    $dayResult = $conn->query($checkDayQuery);
    return ($dayResult->num_rows > 0);
}

// PRESERVED OLD LOGIC: Function to categorize size based on DETAILS2
function getSizeCategory($size) {
    $size = strtoupper(trim($size));
    
    if (empty($size)) return 'Other';
    
    // Check for patterns in order of priority
    if (preg_match('/(2000|2L|2 L)/', $size)) return '2000 ML';
    if (preg_match('/(1000|1L|1 L)/', $size)) return '1000 ML';
    if (preg_match('/(750|750ML)/', $size)) return '750 ML';
    if (preg_match('/(700|700ML)/', $size)) return '700 ML';
    if (preg_match('/(650|650ML)/', $size)) return '650 ML';
    if (preg_match('/(500|500ML)/', $size)) return '500 ML';
    if (preg_match('/(375|375ML)/', $size)) return '375 ML';
    if (preg_match('/(330|330ML)/', $size)) return '330 ML';
    if (preg_match('/(275|275ML)/', $size)) return '275 ML';
    if (preg_match('/(250|250ML)/', $size)) return '250 ML';
    if (preg_match('/(200|200ML)/', $size)) return '200 ML';
    if (preg_match('/(180|180ML)/', $size)) return '180 ML';
    if (preg_match('/(90|90ML)/', $size)) return '90 ML';
    if (preg_match('/(60|60ML)/', $size)) return '60 ML';
    if (preg_match('/(50|50ML)/', $size)) return '50 ML';
    
    return 'Other';
}

// Define display sizes for each liquor type (PRESERVED FROM OLD CODE)
$display_sizes_spirit = ['2000 ML', '1000 ML', '750 ML', '700 ML', '500 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_wine = ['750 ML', '375 ML', '180 ML', '90 ML'];
$display_sizes_beer = ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];

// NEW: Function to map new CLASS_CODE to old liquor class for compatibility
function getLiquorClassFromNewCode($class_code, $conn) {
    static $class_map_cache = [];
    
    if (isset($class_map_cache[$class_code])) {
        return $class_map_cache[$class_code];
    }
    
    // First, get the category for this class
    $query = "SELECT cat.CATEGORY_NAME 
              FROM tblclass_new c 
              JOIN tblcategory cat ON c.CATEGORY_CODE = cat.CATEGORY_CODE 
              WHERE c.CLASS_CODE = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $class_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $category = 'Spirit'; // Default
    if ($row = $result->fetch_assoc()) {
        $category = $row['CATEGORY_NAME'];
    }
    $stmt->close();
    
    // Map new category to old liquor class
    $category_mapping = [
        'Spirit' => 'Spirit',
        'Wine' => 'Wine',
        'Fermented Beer' => 'Fermented Beer',
        'Mild Beer' => 'Mild Beer',
        'Country Liquor' => 'Spirit',
        'Cold Drinks' => 'Other',
        'Soda' => 'Other',
        'General' => 'Other'
    ];
    
    $liquor_class = isset($category_mapping[$category]) ? $category_mapping[$category] : 'Spirit';
    $class_map_cache[$class_code] = $liquor_class;
    
    return $liquor_class;
}

// Fetch class descriptions for reference
$classDescriptions = [];
$classQuery = "SELECT CLASS_CODE, CLASS_NAME FROM tblclass_new";
$classStmt = $conn->prepare($classQuery);
$classStmt->execute();
$classResult = $classStmt->get_result();
while ($row = $classResult->fetch_assoc()) {
    $classDescriptions[$row['CLASS_CODE']] = $row['CLASS_NAME'];
}
$classStmt->close();

// MODIFIED: Fetch item master data - FILTERED BY LICENSE TYPE
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // Use the old format query - DETAILS2 contains size information
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG 
                  FROM tblitemmaster 
                  WHERE CLASS IN ($class_placeholders)";
    
    $itemStmt = $conn->prepare($itemQuery);
    $types = str_repeat('s', count($allowed_classes));
    $itemStmt->bind_param($types, ...$allowed_classes);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    
    while ($row = $itemResult->fetch_assoc()) {
        // PRESERVE OLD LOGIC: Use original size categorization
        $row['size_category'] = getSizeCategory($row['DETAILS2']);
        
        // NEW: Map new CLASS_CODE to old liquor class
        $row['liquor_class'] = getLiquorClassFromNewCode($row['CLASS'], $conn);
        
        $items[$row['CODE']] = $row;
    }
    $itemStmt->close();
} else {
    // If no classes allowed, items array will remain empty
}

// Initialize report data structure
$dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// Initialize daily data structure for each date - PRESERVED FROM OLD CODE
$daily_data = [];

// Initialize totals for each size column - PRESERVED FROM OLD CODE
$totals = [
    'Spirit' => [
        'purchase' => array_fill_keys($display_sizes_spirit, 0),
        'sales' => array_fill_keys($display_sizes_spirit, 0),
        'closing' => array_fill_keys($display_sizes_spirit, 0),
        'opening' => array_fill_keys($display_sizes_spirit, 0)
    ],
    'Wine' => [
        'purchase' => array_fill_keys($display_sizes_wine, 0),
        'sales' => array_fill_keys($display_sizes_wine, 0),
        'closing' => array_fill_keys($display_sizes_wine, 0),
        'opening' => array_fill_keys($display_sizes_wine, 0)
    ],
    'Fermented Beer' => [
        'purchase' => array_fill_keys($display_sizes_beer, 0),
        'sales' => array_fill_keys($display_sizes_beer, 0),
        'closing' => array_fill_keys($display_sizes_beer, 0),
        'opening' => array_fill_keys($display_sizes_beer, 0)
    ],
    'Mild Beer' => [
        'purchase' => array_fill_keys($display_sizes_beer, 0),
        'sales' => array_fill_keys($display_sizes_beer, 0),
        'closing' => array_fill_keys($display_sizes_beer, 0),
        'opening' => array_fill_keys($display_sizes_beer, 0)
    ]
];

// Initialize opening balance data
$opening_balance_data = [
    'Spirit' => array_fill_keys($display_sizes_spirit, 0),
    'Wine' => array_fill_keys($display_sizes_wine, 0),
    'Fermented Beer' => array_fill_keys($display_sizes_beer, 0),
    'Mild Beer' => array_fill_keys($display_sizes_beer, 0)
];

// Process each date in the range - PRESERVED OLD LOGIC
foreach ($dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    $days_in_month = date('t', strtotime($date));
    
    // Get the appropriate table for this date
    $dailyStockTable = getTableForDate($conn, $compID, $date);
    
    // Check if this specific table has columns for this day
    $hasDayColumns = tableHasDayColumns($conn, $dailyStockTable, $day);
    
    // Skip if day exceeds month length OR table doesn't have columns for this day
    if ($day > $days_in_month || !$hasDayColumns) {
        // Initialize empty data for this date
        $daily_data[$date] = [
            'Spirit' => [
                'purchase' => array_fill_keys($display_sizes_spirit, 0),
                'sales' => array_fill_keys($display_sizes_spirit, 0),
                'closing' => array_fill_keys($display_sizes_spirit, 0),
                'opening' => array_fill_keys($display_sizes_spirit, 0)
            ],
            'Wine' => [
                'purchase' => array_fill_keys($display_sizes_wine, 0),
                'sales' => array_fill_keys($display_sizes_wine, 0),
                'closing' => array_fill_keys($display_sizes_wine, 0),
                'opening' => array_fill_keys($display_sizes_wine, 0)
            ],
            'Fermented Beer' => [
                'purchase' => array_fill_keys($display_sizes_beer, 0),
                'sales' => array_fill_keys($display_sizes_beer, 0),
                'closing' => array_fill_keys($display_sizes_beer, 0),
                'opening' => array_fill_keys($display_sizes_beer, 0)
            ],
            'Mild Beer' => [
                'purchase' => array_fill_keys($display_sizes_beer, 0),
                'sales' => array_fill_keys($display_sizes_beer, 0),
                'closing' => array_fill_keys($display_sizes_beer, 0),
                'opening' => array_fill_keys($display_sizes_beer, 0)
            ]
        ];
        continue;
    }
    
    // Initialize daily data for this date
    $daily_data[$date] = [
        'Spirit' => [
            'purchase' => array_fill_keys($display_sizes_spirit, 0),
            'sales' => array_fill_keys($display_sizes_spirit, 0),
            'closing' => array_fill_keys($display_sizes_spirit, 0),
            'opening' => array_fill_keys($display_sizes_spirit, 0)
        ],
        'Wine' => [
            'purchase' => array_fill_keys($display_sizes_wine, 0),
            'sales' => array_fill_keys($display_sizes_wine, 0),
            'closing' => array_fill_keys($display_sizes_wine, 0),
            'opening' => array_fill_keys($display_sizes_wine, 0)
        ],
        'Fermented Beer' => [
            'purchase' => array_fill_keys($display_sizes_beer, 0),
            'sales' => array_fill_keys($display_sizes_beer, 0),
            'closing' => array_fill_keys($display_sizes_beer, 0),
            'opening' => array_fill_keys($display_sizes_beer, 0)
        ],
        'Mild Beer' => [
            'purchase' => array_fill_keys($display_sizes_beer, 0),
            'sales' => array_fill_keys($display_sizes_beer, 0),
            'closing' => array_fill_keys($display_sizes_beer, 0),
            'opening' => array_fill_keys($display_sizes_beer, 0)
        ]
    ];
    
    // Fetch all stock data for this month from the appropriate table
    $stockQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                  DAY_{$day}_OPEN as opening, 
                  DAY_{$day}_PURCHASE as purchase, 
                  DAY_{$day}_SALES as sales, 
                  DAY_{$day}_CLOSING as closing 
                  FROM $dailyStockTable 
                  WHERE STK_MONTH = ?";
    
    $stockStmt = $conn->prepare($stockQuery);
    $stockStmt->bind_param("s", $month);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    
    while ($row = $stockResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        // Skip if item not found in master
        if (!isset($items[$item_code])) continue;
        
        $item_details = $items[$item_code];
        $liquor_class = $item_details['liquor_class'];
        $size_category = $item_details['size_category'];
        
        // PRESERVED OLD LOGIC: Add to daily data and totals based on liquor class and size category
        switch ($liquor_class) {
            case 'Spirit':
                if (in_array($size_category, $display_sizes_spirit)) {
                    $daily_data[$date]['Spirit']['purchase'][$size_category] += $row['purchase'];
                    $daily_data[$date]['Spirit']['sales'][$size_category] += $row['sales'];
                    $daily_data[$date]['Spirit']['closing'][$size_category] += $row['closing'];
                    $daily_data[$date]['Spirit']['opening'][$size_category] += $row['opening'];

                    $totals['Spirit']['purchase'][$size_category] += $row['purchase'];
                    $totals['Spirit']['sales'][$size_category] += $row['sales'];
                    $totals['Spirit']['closing'][$size_category] += $row['closing'];
                    $totals['Spirit']['opening'][$size_category] += $row['opening'];
                }
                break;

            case 'Wine':
                if (in_array($size_category, $display_sizes_wine)) {
                    $daily_data[$date]['Wine']['purchase'][$size_category] += $row['purchase'];
                    $daily_data[$date]['Wine']['sales'][$size_category] += $row['sales'];
                    $daily_data[$date]['Wine']['closing'][$size_category] += $row['closing'];
                    $daily_data[$date]['Wine']['opening'][$size_category] += $row['opening'];

                    $totals['Wine']['purchase'][$size_category] += $row['purchase'];
                    $totals['Wine']['sales'][$size_category] += $row['sales'];
                    $totals['Wine']['closing'][$size_category] += $row['closing'];
                    $totals['Wine']['opening'][$size_category] += $row['opening'];
                }
                break;

            case 'Fermented Beer':
                if (in_array($size_category, $display_sizes_beer)) {
                    $daily_data[$date]['Fermented Beer']['purchase'][$size_category] += $row['purchase'];
                    $daily_data[$date]['Fermented Beer']['sales'][$size_category] += $row['sales'];
                    $daily_data[$date]['Fermented Beer']['closing'][$size_category] += $row['closing'];
                    $daily_data[$date]['Fermented Beer']['opening'][$size_category] += $row['opening'];

                    $totals['Fermented Beer']['purchase'][$size_category] += $row['purchase'];
                    $totals['Fermented Beer']['sales'][$size_category] += $row['sales'];
                    $totals['Fermented Beer']['closing'][$size_category] += $row['closing'];
                    $totals['Fermented Beer']['opening'][$size_category] += $row['opening'];
                }
                break;

            case 'Mild Beer':
                if (in_array($size_category, $display_sizes_beer)) {
                    $daily_data[$date]['Mild Beer']['purchase'][$size_category] += $row['purchase'];
                    $daily_data[$date]['Mild Beer']['sales'][$size_category] += $row['sales'];
                    $daily_data[$date]['Mild Beer']['closing'][$size_category] += $row['closing'];
                    $daily_data[$date]['Mild Beer']['opening'][$size_category] += $row['opening'];

                    $totals['Mild Beer']['purchase'][$size_category] += $row['purchase'];
                    $totals['Mild Beer']['sales'][$size_category] += $row['sales'];
                    $totals['Mild Beer']['closing'][$size_category] += $row['closing'];
                    $totals['Mild Beer']['opening'][$size_category] += $row['opening'];
                }
                break;
        }
    }
    
    $stockStmt->close();
}

// Get opening balance for the start date
$start_day = date('d', strtotime($from_date));
$start_month = date('Y-m', strtotime($from_date));
$start_dailyStockTable = getTableForDate($conn, $compID, $from_date);
$hasStartDayColumns = tableHasDayColumns($conn, $start_dailyStockTable, $start_day);

if ($hasStartDayColumns) {
    $openingQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                     DAY_{$start_day}_OPEN as opening
                     FROM $start_dailyStockTable 
                     WHERE STK_MONTH = ?";
    
    $openingStmt = $conn->prepare($openingQuery);
    $openingStmt->bind_param("s", $start_month);
    $openingStmt->execute();
    $openingResult = $openingStmt->get_result();
    
    while ($row = $openingResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        // Skip if item not found in master
        if (!isset($items[$item_code])) continue;
        
        $item_details = $items[$item_code];
        $liquor_class = $item_details['liquor_class'];
        $size_category = $item_details['size_category'];
        
        // Add to opening balance data
        switch ($liquor_class) {
            case 'Spirit':
                if (in_array($size_category, $display_sizes_spirit)) {
                    $opening_balance_data['Spirit'][$size_category] += $row['opening'];
                }
                break;
            case 'Wine':
                if (in_array($size_category, $display_sizes_wine)) {
                    $opening_balance_data['Wine'][$size_category] += $row['opening'];
                }
                break;
            case 'Fermented Beer':
                if (in_array($size_category, $display_sizes_beer)) {
                    $opening_balance_data['Fermented Beer'][$size_category] += $row['opening'];
                }
                break;
            case 'Mild Beer':
                if (in_array($size_category, $display_sizes_beer)) {
                    $opening_balance_data['Mild Beer'][$size_category] += $row['opening'];
                }
                break;
        }
    }
    
    $openingStmt->close();
}

// Calculate total columns count for table formatting
$total_columns = count($display_sizes_spirit) + count($display_sizes_wine) + (count($display_sizes_beer) * 2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FLR 1A/2A/3A Datewise Register - liqoursoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    /* [All CSS styles remain exactly the same as original] */
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
    /* Double line separators */
    .report-table td:nth-child(14),
    .report-table th:nth-child(14) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(18),
    .report-table th:nth-child(18) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(24),
    .report-table th:nth-child(24) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(30),
    .report-table th:nth-child(30) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(44),
    .report-table th:nth-child(44) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(48),
    .report-table th:nth-child(48) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(54),
    .report-table th:nth-child(54) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(60),
    .report-table th:nth-child(60) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(74),
    .report-table th:nth-child(74) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(78),
    .report-table th:nth-child(78) {
      border-right: double 3px #000 !important;
    }
    .report-table td:nth-child(84),
    .report-table th:nth-child(84) {
      border-right: double 3px #000 !important;
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
    .license-info {
        background-color: #d1ecf1;
        border: 1px solid #bee5eb;
        border-radius: 5px;
        padding: 10px;
        margin-bottom: 15px;
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
      
      .date-col, .permit-col, .signature-col {
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
      
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 6px;
        page-break-before: avoid;
      }
      
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
      <h3 class="mb-4">FLR 1A/2A/3A Datewise Register</h3>

      <!-- ADDED: License Restriction Info -->
      <div class="license-info no-print">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
              <?php 
              if (!empty($available_classes)) {
                  $class_names = [];
                  foreach ($available_classes as $class) {
                      $class_name = isset($classDescriptions[$class['SGROUP']]) ? 
                                   $classDescriptions[$class['SGROUP']] : $class['SGROUP'];
                      $class_names[] = $class_name . ' (' . $class['SGROUP'] . ')';
                  }
                  echo implode(', ', $class_names);
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
          <p class="mb-0 mt-2"><strong>Categorization:</strong> Using original size extraction from DETAILS2 field</p>
          <p class="mb-0"><strong>Spirit Sizes:</strong> <?= implode(', ', $display_sizes_spirit) ?></p>
          <p class="mb-0"><strong>Wine Sizes:</strong> <?= implode(', ', $display_sizes_wine) ?></p>
          <p class="mb-0"><strong>Beer Sizes:</strong> <?= implode(', ', $display_sizes_beer) ?></p>
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
              <button type="button" class="btn btn-success" onclick="window.print()">
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
      <div class="print-section">
        <div class="company-header">
          <h1>Form F.L.R. 1A/2A/3A (See Rule 15)</h1>
          <h5>REGISTER OF TRANSACTION OF FOREIGN LIQUOR EFFECTED BY HOLDER OF VENDOR'S/HOTEL/CLUB LICENCE</h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table" id="flr-datewise-table">
            <thead>
              <tr>
                <th rowspan="3" class="date-col">Date</th>
                <th rowspan="3" class="permit-col">Permit No</th>
                <th colspan="<?= $total_columns ?>">Received</th>
                <th colspan="<?= $total_columns ?>">Sold</th>
                <th colspan="<?= $total_columns ?>">Closing Balance</th>
                <th rowspan="3" class="signature-col">Signature</th>
              </tr>
              <tr>
                <!-- SIMPLIFIED: Only 4 classes (PRESERVED FROM OLD CODE) -->
                <th colspan="<?= count($display_sizes_spirit) ?>">Spirit</th>
                <th colspan="<?= count($display_sizes_wine) ?>">Wine</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_spirit) ?>">Spirit</th>
                <th colspan="<?= count($display_sizes_wine) ?>">Wine</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_spirit) ?>">Spirit</th>
                <th colspan="<?= count($display_sizes_wine) ?>">Wine</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_beer) ?>">Mild Beer</th>
              </tr>
              <tr>
                <!-- Spirit Received -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wine Received -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Fermented Beer Received -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Mild Beer Received -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Spirit Sold -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wine Sold -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Spirit Closing -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wine Closing -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dates as $date): ?>
                <tr>
                  <td class="date-col"><?= date('d-M', strtotime($date)) ?></td>
                  <td class="permit-col"></td>
                  
                  <!-- Spirit Received -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= $daily_data[$date]['Spirit']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Wine Received -->
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= $daily_data[$date]['Wine']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= $daily_data[$date]['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= $daily_data[$date]['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Spirit Sold -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= $daily_data[$date]['Spirit']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Wine Sold -->
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= $daily_data[$date]['Wine']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= $daily_data[$date]['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= $daily_data[$date]['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Spirit Closing -->
                  <?php foreach ($display_sizes_spirit as $size): ?>
                    <td><?= $daily_data[$date]['Spirit']['closing'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Wine Closing -->
                  <?php foreach ($display_sizes_wine as $size): ?>
                    <td><?= $daily_data[$date]['Wine']['closing'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= $daily_data[$date]['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_beer as $size): ?>
                    <td><?= $daily_data[$date]['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <td class="signature-col"></td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Summary rows (PRESERVED FROM OLD CODE) -->
              <?php $last_date = end($dates); reset($dates); ?>
              <tr class="summary-row">
                <td>Received</td>
                <td></td>

                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Closing Balance Section - Show purchase totals -->
                <!-- Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= $totals['Spirit']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= $totals['Wine']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $totals['Fermented Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $totals['Mild Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <td>Received</td>
              </tr>

              <tr class="summary-row">
                <td>Opening Balance</td>
                <td></td>

                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Closing Balance Section - Show opening balance -->
                <!-- Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= $opening_balance_data['Spirit'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= $opening_balance_data['Wine'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $opening_balance_data['Fermented Beer'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $opening_balance_data['Mild Beer'][$size] ?></td>
                <?php endforeach; ?>

                <td>Opening Balance</td>
              </tr>

              <tr class="summary-row">
                <td>Grand Total</td>
                <td></td>

                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Closing Balance Section - Show closing balance of last date -->
                <!-- Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= $daily_data[$last_date]['Spirit']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= $daily_data[$last_date]['Wine']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $daily_data[$last_date]['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $daily_data[$last_date]['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <td>Grand Total</td>
              </tr>

              <tr class="summary-row">
                <td>Sold</td>
                <td></td>

                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Closing Balance Section - Show sales totals -->
                <!-- Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= $totals['Spirit']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= $totals['Wine']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $totals['Fermented Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $totals['Mild Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <td>Sold</td>
              </tr>

              <tr class="summary-row">
                <td>Closing Balance</td>
                <td></td>

                <!-- Received Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Sold Section - Empty -->
                <?php for ($i = 0; $i < $total_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>

                <!-- Closing Balance Section - Show closing balance of last date -->
                <!-- Spirit -->
                <?php foreach ($display_sizes_spirit as $size): ?>
                  <td><?= $daily_data[$last_date]['Spirit']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine -->
                <?php foreach ($display_sizes_wine as $size): ?>
                  <td><?= $daily_data[$last_date]['Wine']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $daily_data[$last_date]['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer -->
                <?php foreach ($display_sizes_beer as $size): ?>
                  <td><?= $daily_data[$last_date]['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <td>Closing Balance</td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <p>Note: This is a computer generated report and does not require signature.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportToExcel() {
    // Get the table element
    var table = document.getElementById('flr-datewise-table');

    // Create a new workbook
    var wb = XLSX.utils.book_new();

    // Clone the table to avoid modifying the original
    var tableClone = table.cloneNode(true);

    // Convert table to worksheet
    var ws = XLSX.utils.table_to_sheet(tableClone);

    // Add worksheet to workbook
    XLSX.utils.book_append_sheet(wb, ws, 'FLR Datewise');

    // Generate Excel file and download
    var fileName = 'FLR_Datewise_<?= date('Y-m-d') ?>.xlsx';
    XLSX.writeFile(wb, fileName);
}

function exportToCSV() {
    // Get the table element
    var table = document.getElementById('flr-datewise-table');

    // Convert table to worksheet
    var ws = XLSX.utils.table_to_sheet(table);

    // Generate CSV file and download
    var fileName = 'FLR_Datewise_<?= date('Y-m-d') ?>.csv';
    XLSX.writeFile(ws, fileName);
}

function exportToPDF() {
    // Use html2pdf library to convert the report section to PDF
    const element = document.querySelector('.print-section');
    const opt = {
        margin: 0.5,
        filename: 'FLR_Datewise_<?= date('Y-m-d') ?>.pdf',
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

// Auto-submit form when dates change
document.querySelectorAll('input[type="date"]').forEach(input => {
  input.addEventListener('change', function() {
    document.querySelector('.report-filters').submit();
  });
});
</script>
</body>
</html>