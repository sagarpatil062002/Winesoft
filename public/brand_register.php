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
                        $hierarchy['display_type'] = 'IMPORTED SPIRIT';
                    } elseif (strpos($class_name_upper, 'MML') !== false) {
                        $hierarchy['display_type'] = 'MML';
                    } else {
                        $hierarchy['display_type'] = 'SPIRITS';
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
                        $hierarchy['display_type'] = 'WINES';
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

// Define display categories based on hierarchy
$display_categories = [
    'SPIRITS',
    'IMPORTED SPIRIT',
    'MML',
    'WINES',
    'IMPORTED WINE',
    'WINE MML',
    'FERMENTED BEER',
    'MILD BEER'
];

$category_display_names = [
    'SPIRITS' => 'SPIRITS',
    'IMPORTED SPIRIT' => 'IMPORTED SPIRIT',
    'MML' => 'MML',
    'WINES' => 'WINES',
    'IMPORTED WINE' => 'IMPORTED WINE',
    'WINE MML' => 'WINE MML',
    'FERMENTED BEER' => 'FERMENTED BEER',
    'MILD BEER' => 'MILD BEER'
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

// Define all display sizes (from opening_balance.php volume summary)
$all_display_sizes = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

// Function to extract brand name from item details
function getBrandName($details) {
    // Remove size patterns (ML, CL, L, etc. with numbers)
    $brandName = preg_replace('/\s*\d+\s*(ML|CL|L).*$/i', '', $details);
    $brandName = preg_replace('/\s*\([^)]*\)\s*$/', '', $brandName); // Remove trailing parentheses
    $brandName = preg_replace('/\s*-\s*\d+$/', '', $brandName); // Remove trailing - numbers
    return trim($brandName);
}

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

// Fetch item master data with size information - USING NEW HIERARCHY FIELDS
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    // Updated query to use CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, LIQ_FLAG 
                  FROM tblitemmaster 
                  WHERE CLASS IN ($class_placeholders)";
    
    $stmt = $conn->prepare($itemQuery);
    $stmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
    $stmt->execute();
    $itemResult = $stmt->get_result();
} else {
    // If no classes allowed, return empty result
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, LIQ_FLAG 
                  FROM tblitemmaster WHERE 1 = 0";
    $itemResult = $conn->query($itemQuery);
}

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
if (isset($stmt)) {
    $stmt->close();
}

// NEW: Restructure data by display type -> brand
$brand_data_by_category = [];
foreach ($display_categories as $category) {
    $brand_data_by_category[$category] = [];
}

// Initialize category totals
$category_totals = [];
foreach ($display_categories as $category) {
    $category_totals[$category] = [
        'purchase' => array_fill_keys($all_display_sizes, 0),
        'sales' => array_fill_keys($all_display_sizes, 0),
        'closing' => array_fill_keys($all_display_sizes, 0)
    ];
}

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
    
    $item = $items[$item_code];
    $hierarchy = $item['hierarchy'];
    $display_type = $hierarchy['display_type'];
    
    // Skip if display type is not in our categories
    if (!in_array($display_type, $display_categories)) {
        continue;
    }
    
    // Extract brand name
    $brandName = getBrandName($item['details']);
    if (empty($brandName)) continue;
    
    // Get volume label for size grouping
    $volume_label = getVolumeLabel($hierarchy['ml_volume']);
    
    // Find matching size in all_display_sizes
    $matched_size = null;
    
    // Try exact match first
    if (in_array($volume_label, $all_display_sizes)) {
        $matched_size = $volume_label;
    } else {
        // Try partial match
        foreach ($all_display_sizes as $size_col) {
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
    
    // If still no match, use first size as fallback
    if (!$matched_size && !empty($all_display_sizes)) {
        $matched_size = $all_display_sizes[0];
    }
    
    // Initialize brand data if not exists
    if (!isset($brand_data_by_category[$display_type][$brandName])) {
        $brand_data_by_category[$display_type][$brandName] = [
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
            if (!in_array($tp_no, $brand_data_by_category[$display_type][$brandName]['tp_nos'])) {
                $brand_data_by_category[$display_type][$brandName]['tp_nos'][] = $tp_no;
            }
        }
    }
    
    // Add to brand data
    if (isset($brand_data_by_category[$display_type][$brandName]['sizes'][$matched_size])) {
        $brand_data_by_category[$display_type][$brandName]['sizes'][$matched_size]['purchase'] += $stock_data['purchase'];
        $brand_data_by_category[$display_type][$brandName]['sizes'][$matched_size]['sales'] += $stock_data['sales'];
        $brand_data_by_category[$display_type][$brandName]['sizes'][$matched_size]['closing'] = $stock_data['closing'];
        
        // Update category totals
        $category_totals[$display_type]['purchase'][$matched_size] += $stock_data['purchase'];
        $category_totals[$display_type]['sales'][$matched_size] += $stock_data['sales'];
        $category_totals[$display_type]['closing'][$matched_size] += $stock_data['closing'];
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

              <!-- SPIRITS Section -->
              <?php if (!empty($brand_data_by_category['SPIRITS'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">SPIRITS</td>
              </tr>
              <?php 
              ksort($brand_data_by_category['SPIRITS']); // Sort brands alphabetically
              foreach ($brand_data_by_category['SPIRITS'] as $brand => $brand_info): 
              ?>
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
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) && $brand_info['sizes'][$size]['purchase'] > 0 ? $brand_info['sizes'][$size]['purchase'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) && $brand_info['sizes'][$size]['sales'] > 0 ? $brand_info['sizes'][$size]['sales'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) && $brand_info['sizes'][$size]['closing'] > 0 ? $brand_info['sizes'][$size]['closing'] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- SPIRITS Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['SPIRITS']['purchase'][$size] ?? 0) > 0 ? $category_totals['SPIRITS']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['SPIRITS']['sales'][$size] ?? 0) > 0 ? $category_totals['SPIRITS']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['SPIRITS']['closing'][$size] ?? 0) > 0 ? $category_totals['SPIRITS']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- IMPORTED SPIRIT Section -->
              <?php if (!empty($brand_data_by_category['IMPORTED SPIRIT'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">IMPORTED SPIRIT</td>
              </tr>
              <?php 
              ksort($brand_data_by_category['IMPORTED SPIRIT']); // Sort brands alphabetically
              foreach ($brand_data_by_category['IMPORTED SPIRIT'] as $brand => $brand_info): 
              ?>
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
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) && $brand_info['sizes'][$size]['purchase'] > 0 ? $brand_info['sizes'][$size]['purchase'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) && $brand_info['sizes'][$size]['sales'] > 0 ? $brand_info['sizes'][$size]['sales'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) && $brand_info['sizes'][$size]['closing'] > 0 ? $brand_info['sizes'][$size]['closing'] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- IMPORTED SPIRIT Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['IMPORTED SPIRIT']['purchase'][$size] ?? 0) > 0 ? $category_totals['IMPORTED SPIRIT']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['IMPORTED SPIRIT']['sales'][$size] ?? 0) > 0 ? $category_totals['IMPORTED SPIRIT']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['IMPORTED SPIRIT']['closing'][$size] ?? 0) > 0 ? $category_totals['IMPORTED SPIRIT']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- MML Section -->
              <?php if (!empty($brand_data_by_category['MML'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">MML</td>
              </tr>
              <?php 
              ksort($brand_data_by_category['MML']); // Sort brands alphabetically
              foreach ($brand_data_by_category['MML'] as $brand => $brand_info): 
              ?>
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
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) && $brand_info['sizes'][$size]['purchase'] > 0 ? $brand_info['sizes'][$size]['purchase'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) && $brand_info['sizes'][$size]['sales'] > 0 ? $brand_info['sizes'][$size]['sales'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) && $brand_info['sizes'][$size]['closing'] > 0 ? $brand_info['sizes'][$size]['closing'] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- MML Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['MML']['purchase'][$size] ?? 0) > 0 ? $category_totals['MML']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['MML']['sales'][$size] ?? 0) > 0 ? $category_totals['MML']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['MML']['closing'][$size] ?? 0) > 0 ? $category_totals['MML']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- WINES Section -->
              <?php if (!empty($brand_data_by_category['WINES'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">WINES</td>
              </tr>
              <?php 
              ksort($brand_data_by_category['WINES']); // Sort brands alphabetically
              foreach ($brand_data_by_category['WINES'] as $brand => $brand_info): 
              ?>
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
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) && $brand_info['sizes'][$size]['purchase'] > 0 ? $brand_info['sizes'][$size]['purchase'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) && $brand_info['sizes'][$size]['sales'] > 0 ? $brand_info['sizes'][$size]['sales'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) && $brand_info['sizes'][$size]['closing'] > 0 ? $brand_info['sizes'][$size]['closing'] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- WINES Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['WINES']['purchase'][$size] ?? 0) > 0 ? $category_totals['WINES']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['WINES']['sales'][$size] ?? 0) > 0 ? $category_totals['WINES']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['WINES']['closing'][$size] ?? 0) > 0 ? $category_totals['WINES']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- IMPORTED WINE Section -->
              <?php if (!empty($brand_data_by_category['IMPORTED WINE'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">IMPORTED WINE</td>
              </tr>
              <?php 
              ksort($brand_data_by_category['IMPORTED WINE']); // Sort brands alphabetically
              foreach ($brand_data_by_category['IMPORTED WINE'] as $brand => $brand_info): 
              ?>
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
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) && $brand_info['sizes'][$size]['purchase'] > 0 ? $brand_info['sizes'][$size]['purchase'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) && $brand_info['sizes'][$size]['sales'] > 0 ? $brand_info['sizes'][$size]['sales'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) && $brand_info['sizes'][$size]['closing'] > 0 ? $brand_info['sizes'][$size]['closing'] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- IMPORTED WINE Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['IMPORTED WINE']['purchase'][$size] ?? 0) > 0 ? $category_totals['IMPORTED WINE']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['IMPORTED WINE']['sales'][$size] ?? 0) > 0 ? $category_totals['IMPORTED WINE']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['IMPORTED WINE']['closing'][$size] ?? 0) > 0 ? $category_totals['IMPORTED WINE']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- WINE MML Section -->
              <?php if (!empty($brand_data_by_category['WINE MML'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">WINE MML</td>
              </tr>
              <?php 
              ksort($brand_data_by_category['WINE MML']); // Sort brands alphabetically
              foreach ($brand_data_by_category['WINE MML'] as $brand => $brand_info): 
              ?>
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
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) && $brand_info['sizes'][$size]['purchase'] > 0 ? $brand_info['sizes'][$size]['purchase'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) && $brand_info['sizes'][$size]['sales'] > 0 ? $brand_info['sizes'][$size]['sales'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) && $brand_info['sizes'][$size]['closing'] > 0 ? $brand_info['sizes'][$size]['closing'] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- WINE MML Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['WINE MML']['purchase'][$size] ?? 0) > 0 ? $category_totals['WINE MML']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['WINE MML']['sales'][$size] ?? 0) > 0 ? $category_totals['WINE MML']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['WINE MML']['closing'][$size] ?? 0) > 0 ? $category_totals['WINE MML']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>

              <!-- FERMENTED BEER Section -->
              <?php if (!empty($brand_data_by_category['FERMENTED BEER'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">FERMENTED BEER</td>
              </tr>
              <?php 
              ksort($brand_data_by_category['FERMENTED BEER']); // Sort brands alphabetically
              foreach ($brand_data_by_category['FERMENTED BEER'] as $brand => $brand_info): 
              ?>
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
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) && $brand_info['sizes'][$size]['purchase'] > 0 ? $brand_info['sizes'][$size]['purchase'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) && $brand_info['sizes'][$size]['sales'] > 0 ? $brand_info['sizes'][$size]['sales'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) && $brand_info['sizes'][$size]['closing'] > 0 ? $brand_info['sizes'][$size]['closing'] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- FERMENTED BEER Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['FERMENTED BEER']['purchase'][$size] ?? 0) > 0 ? $category_totals['FERMENTED BEER']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['FERMENTED BEER']['sales'][$size] ?? 0) > 0 ? $category_totals['FERMENTED BEER']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['FERMENTED BEER']['closing'][$size] ?? 0) > 0 ? $category_totals['FERMENTED BEER']['closing'][$size] : '' ?></td>
                <?php endforeach; ?>
              </tr>
              <?php endif; ?>
              
              <!-- MILD BEER Section -->
              <?php if (!empty($brand_data_by_category['MILD BEER'])): ?>
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>">MILD BEER</td>
              </tr>
              <?php 
              ksort($brand_data_by_category['MILD BEER']); // Sort brands alphabetically
              foreach ($brand_data_by_category['MILD BEER'] as $brand => $brand_info): 
              ?>
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
                    <td><?= isset($brand_info['sizes'][$size]['purchase']) && $brand_info['sizes'][$size]['purchase'] > 0 ? $brand_info['sizes'][$size]['purchase'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['sales']) && $brand_info['sizes'][$size]['sales'] > 0 ? $brand_info['sizes'][$size]['sales'] : '' ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($all_display_sizes as $size): ?>
                    <td><?= isset($brand_info['sizes'][$size]['closing']) && $brand_info['sizes'][$size]['closing'] > 0 ? $brand_info['sizes'][$size]['closing'] : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
              
              <!-- MILD BEER Category Total -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- RECEIVED Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['MILD BEER']['purchase'][$size] ?? 0) > 0 ? $category_totals['MILD BEER']['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['MILD BEER']['sales'][$size] ?? 0) > 0 ? $category_totals['MILD BEER']['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($all_display_sizes as $size): ?>
                  <td><?= ($category_totals['MILD BEER']['closing'][$size] ?? 0) > 0 ? $category_totals['MILD BEER']['closing'][$size] : '' ?></td>
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