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

// Get company's license type and available classes
$license_type = getCompanyLicenseType($compID, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
if (is_array($available_classes) && !empty($available_classes)) {
    foreach ($available_classes as $class) {
        if (is_array($class) && isset($class['SGROUP'])) {
            $allowed_classes[] = $class['SGROUP'];
        } elseif (is_string($class)) {
            $allowed_classes[] = $class;
        }
    }
}

// Cache for hierarchy data
$hierarchy_cache = [];

/**
 * Get complete hierarchy information for an item (copied from excise_register.php)
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
        'display_type' => 'OTHER',
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
                    
                    // Determine spirit type based on class name
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

// Function to get volume label
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

// Default values
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// Validate date range
if (strtotime($from_date) > strtotime($to_date)) {
    $from_date = $to_date;
}

// Fetch company name and license number
$companyName = "Digvijay WINE SHOP";
$licenseNo = "3";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
if ($companyStmt) {
    $companyStmt->bind_param("i", $compID);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    if ($row = $companyResult->fetch_assoc()) {
        $companyName = $row['COMP_NAME'];
        $licenseNo = $row['COMP_FLNO'] ? $row['COMP_FLNO'] : $licenseNo;
    }
    $companyStmt->close();
}

// Define display categories for main table (EXCLUDING MML)
$main_display_categories = [
    'IMFL',
    'IMPORTED', 
    'INDIAN WINE',
    'IMPORTED WINE',
    'FERMENTED BEER',
    'MILD BEER'
];

$category_display_names = [
    'IMFL' => 'IMFL',
    'IMPORTED' => 'IMPORTED',
    'INDIAN WINE' => 'INDIAN WINE',
    'IMPORTED WINE' => 'IMPORTED WINE',
    'FERMENTED BEER' => 'FERMENTED BEER',
    'MILD BEER' => 'MILD BEER'
];

// MML specific categories for second table
$mml_categories = ['MML', 'WINE MML'];
$mml_display_names = [
    'MML' => 'Spirit MML',
    'WINE MML' => 'Wine MML'
];

// Define size columns for each category
$spirit_sizes = [
    '50 ML', '60 ML', '90 ML', '180 ML', '200 ML', '275 ML', '330 ML', 
    '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML', '2000 ML'
];

$wine_sizes = [
    '90 ML', '180 ML', '275 ML', '330 ML', '375 ML', '500 ML', '650 ML', '750 ML', '1000 ML'
];

$beer_sizes = [
    '250 ML', '275 ML', '330 ML', '375 ML', '500 ML', '650 ML', '750 ML', '1000 ML'
];

$size_columns = [
    'IMFL' => $spirit_sizes,
    'IMPORTED' => $spirit_sizes,
    'MML' => $spirit_sizes,
    'INDIAN WINE' => $wine_sizes,
    'IMPORTED WINE' => $wine_sizes,
    'WINE MML' => $wine_sizes,
    'FERMENTED BEER' => $beer_sizes,
    'MILD BEER' => $beer_sizes
];

// Function to get table name for a specific date
function getTableForDate($conn, $compID, $date) {
    $current_month = date('Y-m');
    $target_month = date('Y-m', strtotime($date));
    
    // If current month, use main table
    if ($target_month == $current_month) {
        $tableName = "tbldailystock_" . $compID;
    } else {
        // For previous months, use archive table format
        $month = date('m', strtotime($date));
        $year = date('y', strtotime($date));
        $tableName = "tbldailystock_" . $compID . "_" . $month . "_" . $year;
    }
    
    // Check if table exists
    $tableCheckQuery = "SHOW TABLES LIKE '$tableName'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult && $tableCheckResult->num_rows == 0) {
        // If archive table doesn't exist, fall back to main table
        $tableName = "tbldailystock_" . $compID;
        
        // Check if main table exists
        $tableCheckQuery2 = "SHOW TABLES LIKE '$tableName'";
        $tableCheckResult2 = $conn->query($tableCheckQuery2);
        if ($tableCheckResult2 && $tableCheckResult2->num_rows == 0) {
            $tableName = "tbldailystock_1";
        }
    }
    
    return $tableName;
}

// Function to check if table has specific day columns
function tableHasDayColumns($conn, $tableName, $day) {
    $day_padded = sprintf('%02d', $day);
    
    $columns_to_check = [
        "DAY_{$day_padded}_OPEN",
        "DAY_{$day_padded}_PURCHASE", 
        "DAY_{$day_padded}_SALES",
        "DAY_{$day_padded}_CLOSING"
    ];
    
    foreach ($columns_to_check as $column) {
        $checkColumnQuery = "SHOW COLUMNS FROM $tableName LIKE '$column'";
        $columnResult = $conn->query($checkColumnQuery);
        if ($columnResult && $columnResult->num_rows == 0) {
            return false;
        }
    }
    
    return true;
}

// Initialize dates array
$dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// Fetch item master data with hierarchy information
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, LIQ_FLAG 
                  FROM tblitemmaster 
                  WHERE CLASS IN ($class_placeholders)";
    
    $itemStmt = $conn->prepare($itemQuery);
    if ($itemStmt) {
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
}

// Initialize main report data structure (excluding MML)
$main_daily_data = [];
$main_totals = [];
$main_opening_balance = [];

foreach ($main_display_categories as $category) {
    $main_totals[$category] = [
        'purchase' => array_fill_keys($size_columns[$category], 0),
        'sales' => array_fill_keys($size_columns[$category], 0),
        'closing' => array_fill_keys($size_columns[$category], 0)
    ];
    $main_opening_balance[$category] = array_fill_keys($size_columns[$category], 0);
}

// Initialize MML report data structure (only MML categories)
$mml_daily_data = [];
$mml_totals = [];
$mml_opening_balance = [];

foreach ($mml_categories as $category) {
    $mml_totals[$category] = [
        'purchase' => array_fill_keys($size_columns[$category], 0),
        'sales' => array_fill_keys($size_columns[$category], 0),
        'closing' => array_fill_keys($size_columns[$category], 0)
    ];
    $mml_opening_balance[$category] = array_fill_keys($size_columns[$category], 0);
}

// Process each date
foreach ($dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    $day_padded = sprintf('%02d', $day);
    
    // Initialize daily data for this date
    $main_daily_data[$date] = [];
    $mml_daily_data[$date] = [];
    
    foreach ($main_display_categories as $category) {
        $main_daily_data[$date][$category] = [
            'purchase' => array_fill_keys($size_columns[$category], 0),
            'sales' => array_fill_keys($size_columns[$category], 0),
            'closing' => array_fill_keys($size_columns[$category], 0)
        ];
    }
    
    foreach ($mml_categories as $category) {
        $mml_daily_data[$date][$category] = [
            'purchase' => array_fill_keys($size_columns[$category], 0),
            'sales' => array_fill_keys($size_columns[$category], 0),
            'closing' => array_fill_keys($size_columns[$category], 0)
        ];
    }
    
    // Get appropriate table for this date
    $dailyStockTable = getTableForDate($conn, $compID, $date);
    
    // Check if table has columns for this day
    if (!tableHasDayColumns($conn, $dailyStockTable, $day)) {
        continue;
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
    if ($stockStmt) {
        $stockStmt->bind_param("s", $month);
        $stockStmt->execute();
        $stockResult = $stockStmt->get_result();
        
        while ($row = $stockResult->fetch_assoc()) {
            $item_code = $row['ITEM_CODE'];
            
            // Skip if item not found in master
            if (!isset($items[$item_code])) continue;
            
            $item = $items[$item_code];
            $hierarchy = $item['hierarchy'];
            $display_type = $hierarchy['display_type'];
            
            // Get volume label for size matching
            $volume_label = getVolumeLabel($hierarchy['ml_volume']);
            
            // Find matching size column
            $matched_size = null;
            if (isset($size_columns[$display_type])) {
                // Try exact match
                if (in_array($volume_label, $size_columns[$display_type])) {
                    $matched_size = $volume_label;
                } else {
                    // Try numeric matching
                    foreach ($size_columns[$display_type] as $size_col) {
                        preg_match('/(\d+\.?\d*)\s*(ML|L)/i', $volume_label, $vol_parts);
                        preg_match('/(\d+\.?\d*)\s*(ML|L)/i', $size_col, $col_parts);
                        
                        if (isset($vol_parts[1]) && isset($col_parts[1])) {
                            $vol_num = floatval($vol_parts[1]);
                            $col_num = floatval($col_parts[1]);
                            
                            $vol_unit = strtoupper($vol_parts[2]);
                            $col_unit = strtoupper($col_parts[2]);
                            
                            // Convert to ML for comparison
                            if ($vol_unit == 'L' && $col_unit == 'ML') {
                                $vol_num *= 1000;
                            } elseif ($vol_unit == 'ML' && $col_unit == 'L') {
                                $col_num *= 1000;
                            }
                            
                            if (abs($vol_num - $col_num) < 1) {
                                $matched_size = $size_col;
                                break;
                            }
                        }
                    }
                }
            }
            
            if (!$matched_size && !empty($size_columns[$display_type])) {
                $matched_size = $size_columns[$display_type][0];
            }
            
            // Check if this is an MML category
            $is_mml = in_array($display_type, $mml_categories);
            
            // Add to appropriate data structure
            if ($matched_size) {
                if (!$is_mml && in_array($display_type, $main_display_categories)) {
                    // Add to main table (non-MML)
                    if ($date == $from_date) {
                        $main_opening_balance[$display_type][$matched_size] += (int)$row['opening'];
                    }
                    
                    $main_daily_data[$date][$display_type]['purchase'][$matched_size] += (int)$row['purchase'];
                    $main_daily_data[$date][$display_type]['sales'][$matched_size] += (int)$row['sales'];
                    $main_daily_data[$date][$display_type]['closing'][$matched_size] += (int)$row['closing'];
                    
                    $main_totals[$display_type]['purchase'][$matched_size] += (int)$row['purchase'];
                    $main_totals[$display_type]['sales'][$matched_size] += (int)$row['sales'];
                    $main_totals[$display_type]['closing'][$matched_size] += (int)$row['closing'];
                    
                } elseif ($is_mml) {
                    // Add to MML table
                    if ($date == $from_date) {
                        $mml_opening_balance[$display_type][$matched_size] += (int)$row['opening'];
                    }
                    
                    $mml_daily_data[$date][$display_type]['purchase'][$matched_size] += (int)$row['purchase'];
                    $mml_daily_data[$date][$display_type]['sales'][$matched_size] += (int)$row['sales'];
                    $mml_daily_data[$date][$display_type]['closing'][$matched_size] += (int)$row['closing'];
                    
                    $mml_totals[$display_type]['purchase'][$matched_size] += (int)$row['purchase'];
                    $mml_totals[$display_type]['sales'][$matched_size] += (int)$row['sales'];
                    $mml_totals[$display_type]['closing'][$matched_size] += (int)$row['closing'];
                }
            }
        }
        
        $stockStmt->close();
    }
}

// Calculate total columns for main table
$total_main_columns = 0;
foreach ($main_display_categories as $category) {
    $total_main_columns += count($size_columns[$category]);
}

// Calculate total columns for MML table
$total_mml_columns = 0;
foreach ($mml_categories as $category) {
    $total_mml_columns += count($size_columns[$category]);
}
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
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
    }
    .closing-balance {
      font-weight: bold !important;
      color: #000 !important;
      background-color: #d3d3d3 !important;
    }
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
    .license-info {
        background-color: #d1ecf1;
        border: 1px solid #bee5eb;
        border-radius: 5px;
        padding: 10px;
        margin-bottom: 15px;
    }
    .classification-note {
        background-color: #fff3cd;
        border: 1px solid #ffeeba;
        border-radius: 5px;
        padding: 8px;
        margin-bottom: 10px;
        font-size: 11px;
    }
    .type-col {
      width: 40px;
      min-width: 40px;
    }
    .date-col {
      width: 30px;
      min-width: 30px;
    }
    .permit-col, .signature-col {
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
    .mml-section {
      margin-top: 30px;
      page-break-before: always;
    }
    .mml-header {
      background-color: #d4edda;
      color: #155724;
      padding: 10px;
      margin-bottom: 15px;
      border-radius: 5px;
      font-weight: bold;
    }

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
      
      .vertical-text {
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
        font-size: 7px;
        page-break-before: avoid;
      }
      
      tr {
        page-break-inside: avoid;
        page-break-after: auto;
      }
      
      .mml-section {
        margin-top: 20px;
        page-break-before: always;
      }
      
      .mml-header {
        background-color: #d4edda !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
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

      <!-- License Restriction Info -->
      <div class="license-info no-print">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
              <?php 
              if (!empty($available_classes)) {
                  $class_names = [];
                  foreach ($available_classes as $class) {
                      if (is_array($class) && isset($class['DESC']) && isset($class['SGROUP'])) {
                          $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                      }
                  }
                  echo implode(', ', $class_names);
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
      </div>

      <!-- Classification Note -->
      <div class="classification-note no-print">
          <strong>Classification Logic (as per Excise Register):</strong><br>
          • <strong>IMFL:</strong> Indian Made Foreign Liquor - Spirits<br>
          • <strong>IMPORTED:</strong> Imported Spirits<br>
          • <strong>INDIAN WINE:</strong> Indian Made Wine<br>
          • <strong>IMPORTED WINE:</strong> Imported Wine<br>
          • <strong>FERMENTED BEER:</strong> Fermented Beer (Class F)<br>
          • <strong>MILD BEER:</strong> Mild Beer (Class M)<br>
          • <strong>MML Items (Spirit & Wine):</strong> Shown in separate table below
      </div>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
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

      <!-- Main Report Table (Without MML) -->
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
                <th colspan="<?= $total_main_columns ?>">Received</th>
                <th colspan="<?= $total_main_columns ?>">Sold</th>
                <th colspan="<?= $total_main_columns ?>">Closing Balance</th>
                <th rowspan="3" class="signature-col">Signature</th>
              </tr>
              <tr>
                <?php foreach ($main_display_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
                <?php foreach ($main_display_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
                <?php foreach ($main_display_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $category_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
              <tr>
                <!-- Received sizes -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- Sold sizes -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- Closing sizes -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dates as $date): ?>
                <?php if (!isset($main_daily_data[$date])) continue; ?>
                <tr>
                  <td class="date-col"><?= date('d-M', strtotime($date)) ?></td>
                  <td class="permit-col"></td>
                  
                  <!-- Received -->
                  <?php foreach ($main_display_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                        <?= isset($main_daily_data[$date][$category]['purchase'][$size]) && $main_daily_data[$date][$category]['purchase'][$size] > 0 ? $main_daily_data[$date][$category]['purchase'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>

                  <!-- Sold -->
                  <?php foreach ($main_display_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                        <?= isset($main_daily_data[$date][$category]['sales'][$size]) && $main_daily_data[$date][$category]['sales'][$size] > 0 ? $main_daily_data[$date][$category]['sales'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>

                  <!-- Closing -->
                  <?php foreach ($main_display_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?> <?= ($size_index == $last_index) ? 'closing-balance' : '' ?>">
                        <?= isset($main_daily_data[$date][$category]['closing'][$size]) && $main_daily_data[$date][$category]['closing'][$size] > 0 ? $main_daily_data[$date][$category]['closing'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                  
                  <td class="signature-col"></td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Opening Balance Row -->
              <tr class="summary-row">
                <td>Opening Balance</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Opening Balance values in Closing section -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_opening_balance[$category][$size]) && $main_opening_balance[$category][$size] > 0 ? $main_opening_balance[$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>

              <!-- Total Received Row -->
              <tr class="summary-row">
                <td>Total Received</td>
                <td></td>
                
                <!-- Received totals -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_totals[$category]['purchase'][$size]) && $main_totals[$category]['purchase'][$size] > 0 ? $main_totals[$category]['purchase'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Closing -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <td></td>
              </tr>

              <!-- Total Sold Row -->
              <tr class="summary-row">
                <td>Total Sold</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Sold totals -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_totals[$category]['sales'][$size]) && $main_totals[$category]['sales'][$size] > 0 ? $main_totals[$category]['sales'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <!-- Empty for Closing -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <td></td>
              </tr>

              <!-- Grand Total Row -->
              <?php 
              $last_date = end($dates);
              reset($dates);
              ?>
              <tr class="summary-row">
                <td>Grand Total</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_main_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Last day closing in Closing section -->
                <?php foreach ($main_display_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($main_display_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($main_daily_data[$last_date][$category]['closing'][$size]) && $main_daily_data[$last_date][$category]['closing'][$size] > 0 ? $main_daily_data[$last_date][$category]['closing'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info">
          <p>Note: This is a computer generated report and does not require signature.</p>
          <p>Generated on: <?= date('d-M-Y h:i A') ?> | Total Days: <?= count($dates) ?></p>
        </div>
      </div>

      <!-- MML Section - Second Table (Only MML Data) -->
      <div class="mml-section print-section">
        <div class="mml-header">
          <h4 class="mb-0">MML Summary Report (Spirit MML & Wine MML Only)</h4>
        </div>
        
        <div class="company-header">
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table" id="mml-datewise-table">
            <thead>
              <tr>
                <th rowspan="3" class="date-col">Date</th>
                <th rowspan="3" class="permit-col">Permit No</th>
                <th colspan="<?= $total_mml_columns ?>">Received</th>
                <th colspan="<?= $total_mml_columns ?>">Sold</th>
                <th colspan="<?= $total_mml_columns ?>">Closing Balance</th>
                <th rowspan="3" class="signature-col">Signature</th>
              </tr>
              <tr>
                <?php foreach ($mml_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $mml_display_names[$category] ?></th>
                <?php endforeach; ?>
                <?php foreach ($mml_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $mml_display_names[$category] ?></th>
                <?php endforeach; ?>
                <?php foreach ($mml_categories as $category): ?>
                  <th colspan="<?= count($size_columns[$category]) ?>"><?= $mml_display_names[$category] ?></th>
                <?php endforeach; ?>
              </tr>
              <tr>
                <!-- Received sizes -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- Sold sizes -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>

                <!-- Closing sizes -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <th class="size-col vertical-text <?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dates as $date): ?>
                <?php if (!isset($mml_daily_data[$date])) continue; ?>
                <tr>
                  <td class="date-col"><?= date('d-M', strtotime($date)) ?></td>
                  <td class="permit-col"></td>
                  
                  <!-- Received -->
                  <?php foreach ($mml_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                        <?= isset($mml_daily_data[$date][$category]['purchase'][$size]) && $mml_daily_data[$date][$category]['purchase'][$size] > 0 ? $mml_daily_data[$date][$category]['purchase'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>

                  <!-- Sold -->
                  <?php foreach ($mml_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                        <?= isset($mml_daily_data[$date][$category]['sales'][$size]) && $mml_daily_data[$date][$category]['sales'][$size] > 0 ? $mml_daily_data[$date][$category]['sales'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>

                  <!-- Closing -->
                  <?php foreach ($mml_categories as $cat_index => $category): ?>
                    <?php 
                    $sizes = $size_columns[$category];
                    $last_index = count($sizes) - 1;
                    foreach ($sizes as $size_index => $size): 
                    ?>
                      <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?> <?= ($size_index == $last_index) ? 'closing-balance' : '' ?>">
                        <?= isset($mml_daily_data[$date][$category]['closing'][$size]) && $mml_daily_data[$date][$category]['closing'][$size] > 0 ? $mml_daily_data[$date][$category]['closing'][$size] : '' ?>
                      </td>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                  
                  <td class="signature-col"></td>
                </tr>
              <?php endforeach; ?>
              
              <!-- MML Opening Balance Row -->
              <tr class="summary-row">
                <td>Opening Balance</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Opening Balance values in Closing section -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_opening_balance[$category][$size]) && $mml_opening_balance[$category][$size] > 0 ? $mml_opening_balance[$category][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>

              <!-- MML Total Received Row -->
              <tr class="summary-row">
                <td>Total Received (MML)</td>
                <td></td>
                
                <!-- Received totals -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_totals[$category]['purchase'][$size]) && $mml_totals[$category]['purchase'][$size] > 0 ? $mml_totals[$category]['purchase'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Closing -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <td></td>
              </tr>

              <!-- MML Total Sold Row -->
              <tr class="summary-row">
                <td>Total Sold (MML)</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Sold totals -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_totals[$category]['sales'][$size]) && $mml_totals[$category]['sales'][$size] > 0 ? $mml_totals[$category]['sales'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <!-- Empty for Closing -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <td></td>
              </tr>

              <!-- MML Grand Total Row -->
              <tr class="summary-row">
                <td>Grand Total (MML)</td>
                <td></td>
                
                <!-- Empty for Received -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Empty for Sold -->
                <?php for ($i = 0; $i < $total_mml_columns; $i++): ?>
                  <td></td>
                <?php endfor; ?>
                
                <!-- Last day closing -->
                <?php foreach ($mml_categories as $cat_index => $category): ?>
                  <?php 
                  $sizes = $size_columns[$category];
                  $last_index = count($sizes) - 1;
                  foreach ($sizes as $size_index => $size): 
                  ?>
                    <td class="<?= ($size_index == $last_index && $cat_index < count($mml_categories) - 1) ? 'double-line-right' : '' ?>">
                      <?= isset($mml_daily_data[$last_date][$category]['closing'][$size]) && $mml_daily_data[$last_date][$category]['closing'][$size] > 0 ? $mml_daily_data[$last_date][$category]['closing'][$size] : '' ?>
                    </td>
                  <?php endforeach; ?>
                <?php endforeach; ?>
                
                <td></td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info mt-3">
          <p><strong>MML Summary:</strong> 
             Spirit MML Total Received: <?= array_sum($mml_totals['MML']['purchase'] ?? []) ?> | 
             Spirit MML Total Sold: <?= array_sum($mml_totals['MML']['sales'] ?? []) ?> | 
             Spirit MML Closing: <?= array_sum($mml_daily_data[$last_date]['MML']['closing'] ?? []) ?><br>
             Wine MML Total Received: <?= array_sum($mml_totals['WINE MML']['purchase'] ?? []) ?> | 
             Wine MML Total Sold: <?= array_sum($mml_totals['WINE MML']['sales'] ?? []) ?> | 
             Wine MML Closing: <?= array_sum($mml_daily_data[$last_date]['WINE MML']['closing'] ?? []) ?>
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function exportToExcel() {
    var mainTable = document.getElementById('flr-datewise-table');
    var mmlTable = document.getElementById('mml-datewise-table');
    var wb = XLSX.utils.book_new();
    
    // Add main table
    var mainClone = mainTable.cloneNode(true);
    var ws1 = XLSX.utils.table_to_sheet(mainClone);
    XLSX.utils.book_append_sheet(wb, ws1, 'FLR Datewise');
    
    // Add MML table
    var mmlClone = mmlTable.cloneNode(true);
    var ws2 = XLSX.utils.table_to_sheet(mmlClone);
    XLSX.utils.book_append_sheet(wb, ws2, 'MML Summary');
    
    var fileName = 'FLR_Datewise_MML_<?= date('Y-m-d') ?>.xlsx';
    XLSX.writeFile(wb, fileName);
}

function exportToCSV() {
    var table = document.getElementById('flr-datewise-table');
    var ws = XLSX.utils.table_to_sheet(table);
    var fileName = 'FLR_Datewise_<?= date('Y-m-d') ?>.csv';
    XLSX.writeFile(ws, fileName);
}

function exportToPDF() {
    const element = document.querySelector('.print-section');
    const opt = {
        margin: 0.5,
        filename: 'FLR_Datewise_MML_<?= date('Y-m-d') ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'in', format: 'a4', orientation: 'landscape' }
    };
    html2pdf().set(opt).from(element).save();
}

// Load XLSX library dynamically
if (typeof XLSX === 'undefined') {
    var script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    document.head.appendChild(script);
}

// Load html2pdf library dynamically
if (typeof html2pdf === 'undefined') {
    var script2 = document.createElement('script');
    script2.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js';
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