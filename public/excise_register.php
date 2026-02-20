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

// Cache for hierarchy data (same as opening_balance.php)
$hierarchy_cache = [];

/**
 * Get complete hierarchy information for an item (copied from opening_balance.php)
 */
function getItemHierarchy($class_code, $subclass_code, $size_code, $conn) {
    global $hierarchy_cache;
    
    // Create cache key
    $cache_key = $class_code . '|' . $subclass_code . '|' . $size_code;
    
    if (isset($hierarchy_cache[$cache_key])) {
        return $hierarchy_cache[$cache_key];
    }
    
    $hierarchy = [
        'class_code' => $class_code,
        'class_name' => '',
        'subclass_code' => $subclass_code,
        'subclass_name' => '',
        'category_code' => '',
        'category_name' => '',
        'display_category' => 'OTHER',
        'display_type' => 'OTHER', // New field for IMFL/Imported/MML differentiation
        'size_code' => $size_code,
        'size_desc' => '',
        'ml_volume' => 0,
        'full_hierarchy' => ''
    ];
    
    try {
        // Get class and category information
        if (!empty($class_code)) {
            $query = "SELECT cn.CLASS_NAME, cn.CATEGORY_CODE, cat.CATEGORY_NAME 
                      FROM tblclass_new cn
                      LEFT JOIN tblcategory cat ON cn.CATEGORY_CODE = cat.CATEGORY_CODE
                      WHERE cn.CLASS_CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $class_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $hierarchy['class_name'] = $row['CLASS_NAME'];
                $hierarchy['category_code'] = $row['CATEGORY_CODE'];
                $hierarchy['category_name'] = $row['CATEGORY_NAME'] ?? '';
                
                // Map category name to display category
                $category_name = strtoupper($row['CATEGORY_NAME'] ?? '');
                $display_category = 'OTHER';
                
                if ($category_name == 'SPIRIT') {
                    $display_category = 'SPIRITS';
                    
                    // Determine spirit type based on class name or other criteria
                    $class_name_upper = strtoupper($row['CLASS_NAME'] ?? '');
                    if (strpos($class_name_upper, 'IMPORTED') !== false || strpos($class_name_upper, 'IMP') !== false) {
                        $hierarchy['display_type'] = 'IMPORTED';
                    } elseif (strpos($class_name_upper, 'MML') !== false) {
                        $hierarchy['display_type'] = 'MML';
                    } else {
                        $hierarchy['display_type'] = 'IMFL';
                    }
                } elseif ($category_name == 'WINE') {
                    $display_category = 'WINE';
                    
                    // Determine wine type based on class name
                    $class_name_upper = strtoupper($row['CLASS_NAME'] ?? '');
                    if (strpos($class_name_upper, 'IMPORTED') !== false || strpos($class_name_upper, 'IMP') !== false) {
                        $hierarchy['display_type'] = 'IMPORTED WINE';
                    } elseif (strpos($class_name_upper, 'MML') !== false) {
                        $hierarchy['display_type'] = 'WINE MML';
                    } else {
                        $hierarchy['display_type'] = 'INDIAN WINE';
                    }
                } elseif ($category_name == 'FERMENTED BEER') {
                    $display_category = 'FERMENTED BEER';
                    $hierarchy['display_type'] = 'FERMENTED BEER';
                } elseif ($category_name == 'MILD BEER') {
                    $display_category = 'MILD BEER';
                    $hierarchy['display_type'] = 'MILD BEER';
                } elseif ($category_name == 'COUNTRY LIQUOR') {
                    $display_category = 'COUNTRY LIQUOR';
                    $hierarchy['display_type'] = 'COUNTRY LIQUOR';
                }
                
                $hierarchy['display_category'] = $display_category;
            }
            $stmt->close();
        }
        
        // Get subclass information
        if (!empty($subclass_code)) {
            $query = "SELECT SUBCLASS_NAME FROM tblsubclass_new WHERE SUBCLASS_CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $subclass_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $hierarchy['subclass_name'] = $row['SUBCLASS_NAME'];
            }
            $stmt->close();
        }
        
        // Get size information
        if (!empty($size_code)) {
            $query = "SELECT SIZE_DESC, ML_VOLUME FROM tblsize WHERE SIZE_CODE = ? LIMIT 1";
            $stmt = $conn->prepare($query);
            $stmt->bind_param("s", $size_code);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($row = $result->fetch_assoc()) {
                $hierarchy['size_desc'] = $row['SIZE_DESC'];
                $hierarchy['ml_volume'] = (int)($row['ML_VOLUME'] ?? 0);
            }
            $stmt->close();
        }
        
        // Build full hierarchy string
        $parts = [];
        if (!empty($hierarchy['category_name'])) $parts[] = $hierarchy['category_name'];
        if (!empty($hierarchy['class_name'])) $parts[] = $hierarchy['class_name'];
        if (!empty($hierarchy['subclass_name'])) $parts[] = $hierarchy['subclass_name'];
        if (!empty($hierarchy['size_desc'])) $parts[] = $hierarchy['size_desc'];
        
        $hierarchy['full_hierarchy'] = !empty($parts) ? implode(' > ', $parts) : 'N/A';
        
    } catch (Exception $e) {
        error_log("Error in getItemHierarchy: " . $e->getMessage());
    }
    
    $hierarchy_cache[$cache_key] = $hierarchy;
    return $hierarchy;
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

// Define display categories based on mode
if ($mode == 'Country Liquor') {
    $display_categories = ['COUNTRY LIQUOR'];
    $category_display_names = ['COUNTRY LIQUOR' => 'COUNTRY LIQUOR'];
} else {
    // Updated categories: IMFL, Imported, MML for spirits, and three types for wine
    $display_categories = [
        'IMFL',
        'IMPORTED', 
        'MML',
        'INDIAN WINE',
        'IMPORTED WINE',
        'WINE MML',
        'FERMENTED BEER',
        'MILD BEER'
    ];
    $category_display_names = [
        'IMFL' => 'IMFL',
        'IMPORTED' => 'IMPORTED',
        'MML' => 'MML',
        'INDIAN WINE' => 'INDIAN WINE',
        'IMPORTED WINE' => 'IMPORTED WINE',
        'WINE MML' => 'WINE MML',
        'FERMENTED BEER' => 'FERMENTED BEER',
        'MILD BEER' => 'MILD BEER'
    ];
}

// Define size columns for each category - all spirit types use same sizes
$spirit_sizes = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

$wine_sizes = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

$beer_sizes = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

$size_columns = [
    'IMFL' => $spirit_sizes,
    'IMPORTED' => $spirit_sizes,
    'MML' => $spirit_sizes,
    'INDIAN WINE' => $wine_sizes,
    'IMPORTED WINE' => $wine_sizes,
    'WINE MML' => $wine_sizes,
    'FERMENTED BEER' => $beer_sizes,
    'MILD BEER' => $beer_sizes,
    'COUNTRY LIQUOR' => $spirit_sizes
];

// Function to get volume label (copied from opening_balance.php)
function getVolumeLabel($volume) {
    static $volume_label_cache = [];
    
    if (isset($volume_label_cache[$volume])) {
        return $volume_label_cache[$volume];
    }
    
    // Format volume based on size
    if ($volume >= 1000) {
        $liters = $volume / 1000;
        // Check if it's a whole number
        if ($liters == intval($liters)) {
            $label = intval($liters) . 'L';
        } else {
            $label = rtrim(rtrim(number_format($liters, 1), '0'), '.') . 'L';
        }
    } else {
        $label = $volume . ' ML';
    }
    
    $volume_label_cache[$volume] = $label;
    return $label;
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

// Fetch item master data with size information - FILTERED BY LICENSE TYPE AND MODE
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // Updated query to use new hierarchy tables (CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE)
    if ($mode == 'Country Liquor') {
        $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, LIQ_FLAG 
                      FROM tblitemmaster 
                      WHERE CLASS IN ($class_placeholders) AND LIQ_FLAG = 'C'";
    } else {
        // For Foreign Liquor, include all items from allowed classes
        $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, LIQ_FLAG 
                      FROM tblitemmaster 
                      WHERE CLASS IN ($class_placeholders)";
    }
    
    $itemStmt = $conn->prepare($itemQuery);
    $itemStmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    
    while ($row = $itemResult->fetch_assoc()) {
        // Get hierarchy information
        $hierarchy = getItemHierarchy(
            $row['CLASS_CODE_NEW'], 
            $row['SUBCLASS_CODE_NEW'], 
            $row['SIZE_CODE'], 
            $conn
        );
        
        $items[$row['CODE']] = [
            'code' => $row['CODE'],
            'details' => $row['DETAILS'],
            'details2' => $row['DETAILS2'],
            'class' => $row['CLASS'],
            'class_code_new' => $row['CLASS_CODE_NEW'],
            'subclass_code_new' => $row['SUBCLASS_CODE_NEW'],
            'size_code' => $row['SIZE_CODE'],
            'liq_flag' => $row['LIQ_FLAG'],
            'hierarchy' => $hierarchy
        ];
    }
    $itemStmt->close();
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

// Initialize daily data structure with all categories and sizes
foreach ($dates as $date) {
    $daily_data[$date] = [];
    
    foreach ($display_categories as $category) {
        $daily_data[$date][$category] = [
            'opening' => array_fill_keys($size_columns[$category], 0),
            'purchase' => array_fill_keys($size_columns[$category], 0),
            'sales' => array_fill_keys($size_columns[$category], 0),
            'closing' => array_fill_keys($size_columns[$category], 0)
        ];
    }
}

// Process each date in the range with month-aware logic
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
    
    while ($row = $stockResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        // Skip if item not found in master (due to license filtering)
        if (!isset($items[$item_code])) continue;
        
        $item = $items[$item_code];
        $hierarchy = $item['hierarchy'];
        $display_type = $hierarchy['display_type'];
        
        // For Country Liquor mode, force category to COUNTRY LIQUOR
        if ($mode == 'Country Liquor') {
            $display_type = 'COUNTRY LIQUOR';
        }
        
        // Skip if display type is not in our categories
        if (!in_array($display_type, $display_categories)) {
            continue;
        }
        
        // Get volume label for size grouping
        $volume_label = getVolumeLabel($hierarchy['ml_volume']);
        
        // Find matching size column
        $matched_size = null;
        if (isset($size_columns[$display_type])) {
            // Try exact match first
            if (in_array($volume_label, $size_columns[$display_type])) {
                $matched_size = $volume_label;
            } else {
                // Try partial match
                foreach ($size_columns[$display_type] as $size_col) {
                    // Extract numeric part for comparison
                    preg_match('/(\d+\.?\d*)\s*(ML|L)/i', $volume_label, $vol_parts);
                    preg_match('/(\d+\.?\d*)\s*(ML|L)/i', $size_col, $col_parts);
                    
                    if (isset($vol_parts[1]) && isset($col_parts[1])) {
                        $vol_num = floatval($vol_parts[1]);
                        $col_num = floatval($col_parts[1]);
                        
                        // Check if units match (ML vs L)
                        $vol_unit = strtoupper($vol_parts[2]);
                        $col_unit = strtoupper($col_parts[2]);
                        
                        // Convert to ML for comparison if needed
                        if ($vol_unit == 'L' && $col_unit == 'ML') {
                            $vol_num *= 1000;
                        } elseif ($vol_unit == 'ML' && $col_unit == 'L') {
                            $col_num *= 1000;
                        }
                        
                        // Allow small rounding differences
                        if (abs($vol_num - $col_num) < 1) {
                            $matched_size = $size_col;
                            break;
                        }
                    }
                }
            }
        }
        
        // If still no match, use a default size or skip
        if (!$matched_size && !empty($size_columns[$display_type])) {
            // Use first size as fallback
            $matched_size = $size_columns[$display_type][0];
        }
        
        // Add to daily data if we have a matching size
        if ($matched_size && isset($daily_data[$date][$display_type])) {
            $daily_data[$date][$display_type]['opening'][$matched_size] += (int)$row['opening'];
            $daily_data[$date][$display_type]['purchase'][$matched_size] += (int)$row['purchase'];
            $daily_data[$date][$display_type]['sales'][$matched_size] += (int)$row['sales'];
            $daily_data[$date][$display_type]['closing'][$matched_size] += (int)$row['closing'];
        }
    }
    
    $stockStmt->close();
}

// Calculate total columns count for table formatting
$total_columns = 0;
foreach ($display_categories as $category) {
    $total_columns += count($size_columns[$category]);
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
    
    .closing-balance {
      font-weight: bold !important;
      color: #000 !important;
      background-color: #d3d3d3 !important;
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
        
        <?php if (empty($dates) || empty($daily_data)): ?>
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
                  
                  <?php foreach ($display_categories as $category): ?>
                    <th colspan="<?= count($size_columns[$category]) ?>"><?= $category_display_names[$category] ?></th>
                  <?php endforeach; ?>
                </tr>
                <tr>
                  <?php foreach ($display_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <th class="size-col vertical-text-full <?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php 
                $date_count = 0;
                $first_date = true;
                foreach ($dates as $date): 
                  // Skip if this date was not processed due to missing columns
                  if (!isset($daily_data[$date]) || empty(array_filter($daily_data[$date], function($cat_data) {
                      return array_sum($cat_data['opening']) > 0 || array_sum($cat_data['purchase']) > 0 || 
                             array_sum($cat_data['sales']) > 0 || array_sum($cat_data['closing']) > 0;
                  }))) continue;
                  
                  $day_num = date('d', strtotime($date));
                  $month_num = date('m', strtotime($date));
                  $year_num = date('y', strtotime($date));
                  $tp_nos = $tp_nos_data[$date] ?? [];
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
                      
                      <?php foreach ($display_categories as $cat_index => $category): ?>
                        <?php 
                        $sizes = $size_columns[$category];
                        $last_index = count($sizes) - 1;
                        foreach ($sizes as $size_index => $size): 
                        ?>
                          <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                            <?= $daily_data[$date][$category]['opening'][$size] > 0 ? $daily_data[$date][$category]['opening'][$size] : '' ?>
                          </td>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </tr>
                    
                    <tr>
                      <td>Rec.</td>
                      
                      <?php foreach ($display_categories as $cat_index => $category): ?>
                        <?php 
                        $sizes = $size_columns[$category];
                        $last_index = count($sizes) - 1;
                        foreach ($sizes as $size_index => $size): 
                        ?>
                          <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                            <?= $daily_data[$date][$category]['purchase'][$size] > 0 ? $daily_data[$date][$category]['purchase'][$size] : '' ?>
                          </td>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </tr>
                    
                    <tr>
                      <td>Sale</td>
                      
                      <?php foreach ($display_categories as $cat_index => $category): ?>
                        <?php 
                        $sizes = $size_columns[$category];
                        $last_index = count($sizes) - 1;
                        foreach ($sizes as $size_index => $size): 
                        ?>
                          <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                            <?= $daily_data[$date][$category]['sales'][$size] > 0 ? $daily_data[$date][$category]['sales'][$size] : '' ?>
                          </td>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </tr>
                    
                    <tr>
                      <td>Clo.</td>
                      
                      <?php foreach ($display_categories as $cat_index => $category): ?>
                        <?php 
                        $sizes = $size_columns[$category];
                        $last_index = count($sizes) - 1;
                        foreach ($sizes as $size_index => $size): 
                        ?>
                          <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?> closing-balance">
                            <?= $daily_data[$date][$category]['closing'][$size] > 0 ? $daily_data[$date][$category]['closing'][$size] : '' ?>
                          </td>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
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
                      
                      <?php foreach ($display_categories as $cat_index => $category): ?>
                        <?php 
                        $sizes = $size_columns[$category];
                        $last_index = count($sizes) - 1;
                        foreach ($sizes as $size_index => $size): 
                        ?>
                          <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                            <?= $daily_data[$date][$category]['purchase'][$size] > 0 ? $daily_data[$date][$category]['purchase'][$size] : '' ?>
                          </td>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </tr>
                    
                    <tr>
                      <td>Sale</td>
                      
                      <?php foreach ($display_categories as $cat_index => $category): ?>
                        <?php 
                        $sizes = $size_columns[$category];
                        $last_index = count($sizes) - 1;
                        foreach ($sizes as $size_index => $size): 
                        ?>
                          <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                            <?= $daily_data[$date][$category]['sales'][$size] > 0 ? $daily_data[$date][$category]['sales'][$size] : '' ?>
                          </td>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
                    </tr>
                    
                    <tr>
                      <td>Clo.</td>
                      
                      <?php foreach ($display_categories as $cat_index => $category): ?>
                        <?php 
                        $sizes = $size_columns[$category];
                        $last_index = count($sizes) - 1;
                        foreach ($sizes as $size_index => $size): 
                        ?>
                          <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?> closing-balance">
                            <?= $daily_data[$date][$category]['closing'][$size] > 0 ? $daily_data[$date][$category]['closing'][$size] : '' ?>
                          </td>
                        <?php endforeach; ?>
                      <?php endforeach; ?>
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