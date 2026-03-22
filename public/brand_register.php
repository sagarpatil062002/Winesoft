<?php
session_start();
require_once 'components/financial_year_init.php';// Ensure user is logged in and company is selected

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
include_once "components/financial_year.php";
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

// Get financial year dates from session
$fin_year_start = $_SESSION['FIN_YEAR_START'] ?? date('Y-04-01');
$fin_year_end = $_SESSION['FIN_YEAR_END'] ?? date('Y-03-31');

// Default values - constrained to financial year
$default_from = max($fin_year_start, date('Y-m-01')); // Start of month or financial year start
$default_to = min($fin_year_end, date('Y-m-d')); // Today or financial year end

$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : $default_from;
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : $default_to;

// Validate dates are within financial year
if (strtotime($from_date) < strtotime($fin_year_start)) {
    $from_date = $fin_year_start;
}
if (strtotime($to_date) > strtotime($fin_year_end)) {
    $to_date = $fin_year_end;
}
if (strtotime($from_date) > strtotime($to_date)) {
    $to_date = $from_date;
}

// Check if report should be shown
$show_report = isset($_GET['generate']) || (isset($_GET['from_date']) && isset($_GET['to_date']));

// Add pagination - limit number of days to process at once
$max_days_per_request = 31; // Maximum 31 days per request to prevent timeout
$date_diff = floor((strtotime($to_date) - strtotime($from_date)) / (60 * 60 * 24));

if ($date_diff > $max_days_per_request) {
    // If range too large, show warning and limit
    $to_date = date('Y-m-d', strtotime($from_date . ' + ' . $max_days_per_request . ' days'));
    $range_limited = true;
} else {
    $range_limited = false;
}

// Cache for hierarchy data
$hierarchy_cache = [];

/**
 * Get complete hierarchy information for an item
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
                
                if ($category_name == 'SPIRIT') {
                    $class_name_upper = strtoupper($row['CLASS_NAME'] ?? '');
                    if (strpos($class_name_upper, 'IMPORTED') !== false || strpos($class_name_upper, 'IMP') !== false) {
                        $hierarchy['display_type'] = 'IMPORTED SPIRIT';
                    } elseif (strpos($class_name_upper, 'MML') !== false) {
                        $hierarchy['display_type'] = 'MML';
                    } else {
                        $hierarchy['display_type'] = 'SPIRITS';
                    }
                } elseif ($category_name == 'WINE') {
                    $class_name_upper = strtoupper($row['CLASS_NAME'] ?? '');
                    if (strpos($class_name_upper, 'IMPORTED') !== false || strpos($class_name_upper, 'IMP') !== false) {
                        $hierarchy['display_type'] = 'IMPORTED WINE';
                    } elseif (strpos($class_name_upper, 'MML') !== false) {
                        $hierarchy['display_type'] = 'WINE MML';
                    } else {
                        $hierarchy['display_type'] = 'INDIAN WINE';
                    }
                } elseif ($category_name == 'FERMENTED BEER') {
                    $hierarchy['display_type'] = 'FERMENTED BEER';
                } elseif ($category_name == 'MILD BEER') {
                    $hierarchy['display_type'] = 'MILD BEER';
                } elseif ($category_name == 'COUNTRY LIQUOR') {
                    $hierarchy['display_type'] = 'COUNTRY LIQUOR';
                }
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

// Fetch company name and license number
$companyName = "";
$licenseNo = "";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
    $licenseNo = $row['COMP_FLNO'] ? $row['COMP_FLNO'] : '';
}
$companyStmt->close();

// Define display categories (match excise register)
$display_categories = [
    'SPIRITS',
    'IMPORTED SPIRIT',
    'MML',
    'INDIAN WINE',
    'IMPORTED WINE',
    'WINE MML',
    'FERMENTED BEER',
    'MILD BEER'
];

$category_display_names = [
    'SPIRITS' => 'SPIRITS',
    'IMPORTED SPIRIT' => 'IMPORTED SPIRIT',
    'MML' => 'MML',
    'INDIAN WINE' => 'INDIAN WINE',
    'IMPORTED WINE' => 'IMPORTED WINE',
    'WINE MML' => 'WINE MML',
    'FERMENTED BEER' => 'FERMENTED BEER',
    'MILD BEER' => 'MILD BEER'
];

// Function to get standardized size label (same as excise register)
function getStandardizedSizeLabel($size_desc, $ml_volume) {
    if ($ml_volume >= 1000) {
        $liters = $ml_volume / 1000;
        if ($liters == intval($liters)) {
            return intval($liters) . 'L';
        }
        return rtrim(rtrim(number_format($liters, 1), '0'), '.') . 'L';
    }
    return $ml_volume . ' ML';
}

// Function to extract brand name from item details
function getBrandName($details) {
    // Remove size patterns (ML, CL, L, etc. with numbers)
    $brandName = preg_replace('/\s*\d+\s*(ML|CL|L).*$/i', '', $details);
    $brandName = preg_replace('/\s*\([^)]*\)\s*$/', '', $details);
    $brandName = preg_replace('/\s*-\s*\d+$/', '', $brandName);
    return trim($brandName);
}

// Function to get table name for a specific date
function getTableForDate($conn, $compID, $date) {
    static $table_cache = [];
    
    $current_month = date('Y-m');
    $target_month = date('Y-m', strtotime($date));
    $cache_key = $compID . '_' . $target_month;
    
    if (isset($table_cache[$cache_key])) {
        return $table_cache[$cache_key];
    }
    
    if ($target_month == $current_month) {
        $tableName = "tbldailystock_" . $compID;
    } else {
        $month = date('m', strtotime($date));
        $year = date('y', strtotime($date));
        $tableName = "tbldailystock_" . $compID . "_" . $month . "_" . $year;
    }
    
    $tableCheckQuery = "SHOW TABLES LIKE '$tableName'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult->num_rows == 0) {
        $tableName = "tbldailystock_" . $compID;
        $tableCheckQuery2 = "SHOW TABLES LIKE '$tableName'";
        $tableCheckResult2 = $conn->query($tableCheckQuery2);
        if ($tableCheckResult2->num_rows == 0) {
            $tableName = "tbldailystock_1";
        }
    }
    
    $table_cache[$cache_key] = $tableName;
    return $tableName;
}

// Function to check if table has specific day columns
function tableHasDayColumns($conn, $tableName, $day) {
    static $column_cache = [];
    
    $cache_key = $tableName . '_' . $day;
    
    if (isset($column_cache[$cache_key])) {
        return $column_cache[$cache_key];
    }
    
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
        if ($columnResult->num_rows == 0) {
            $column_cache[$cache_key] = false;
            return false;
        }
    }
    
    $column_cache[$cache_key] = true;
    return true;
}

// ============================================================================
// STEP 1: Get ALL dates from April 1st to To Date for CALCULATIONS
// ============================================================================
$financial_year = date('Y', strtotime($from_date));
$april_first = $financial_year . '-04-01';
$calculation_dates = [];

$current_date = $april_first;
while (strtotime($current_date) <= strtotime($to_date)) {
    $calculation_dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// ============================================================================
// STEP 2: Get the dates the user wants to DISPLAY
// ============================================================================
$display_dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $display_dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// ============================================================================
// STEP 3: Fetch item master data
// ============================================================================
$items = [];
$all_size_labels = [];

if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    $itemQuery = "SELECT CODE, DETAILS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE 
                  FROM tblitemmaster 
                  WHERE CLASS IN ($class_placeholders)";
    
    $stmt = $conn->prepare($itemQuery);
    $stmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
    $stmt->execute();
    $itemResult = $stmt->get_result();
    
    while ($row = $itemResult->fetch_assoc()) {
        $hierarchy = getItemHierarchy(
            $row['CLASS_CODE_NEW'], 
            $row['SUBCLASS_CODE_NEW'], 
            $row['SIZE_CODE'], 
            $conn
        );
        
        // Only store if display type is in our categories
        if (in_array($hierarchy['display_type'], $display_categories)) {
            $size_label = getStandardizedSizeLabel($hierarchy['size_desc'], $hierarchy['ml_volume']);
            $all_size_labels[$size_label] = true;
            
            $items[$row['CODE']] = [
                'code' => $row['CODE'],
                'details' => $row['DETAILS'],
                'hierarchy' => $hierarchy,
                'size_label' => $size_label
            ];
        }
    }
    $stmt->close();
}

// ============================================================================
// STEP 4: Initialize data structures for ALL calculation dates
// ============================================================================
$all_daily_data = [];
foreach ($calculation_dates as $date) {
    $all_daily_data[$date] = [];
}

// ============================================================================
// STEP 5: Fetch TP Nos for display dates only
// ============================================================================
$tp_nos_data = [];
foreach ($display_dates as $date) {
    $tpQuery = "SELECT DISTINCT TPNO FROM tblpurchases WHERE DATE = ? AND CompID = ? AND TPNO IS NOT NULL AND TPNO != ''";
    $tpStmt = $conn->prepare($tpQuery);
    $tpStmt->bind_param("si", $date, $compID);
    $tpStmt->execute();
    $tpResult = $tpStmt->get_result();
    
    $tp_nos = [];
    while ($row = $tpResult->fetch_assoc()) {
        $tp_nos[] = $row['TPNO'];
    }
    
    $tp_nos_data[$date] = $tp_nos;
    $tpStmt->close();
}

// ============================================================================
// STEP 6: Fetch raw data for ALL calculation dates
// ============================================================================
$dates_by_month = [];
foreach ($calculation_dates as $date) {
    $month = date('Y-m', strtotime($date));
    if (!isset($dates_by_month[$month])) {
        $dates_by_month[$month] = [];
    }
    $dates_by_month[$month][] = $date;
}

foreach ($dates_by_month as $month => $dates) {
    $table_name = getTableForDate($conn, $compID, $dates[0]);
    
    $valid_dates = [];
    $day_columns = [];
    foreach ($dates as $date) {
        $day = date('d', strtotime($date));
        if (tableHasDayColumns($conn, $table_name, $day)) {
            $valid_dates[] = $date;
            $day_padded = sprintf('%02d', $day);
            $day_columns[] = "DAY_{$day_padded}_OPEN as open_$day";
            $day_columns[] = "DAY_{$day_padded}_PURCHASE as purchase_$day";
            $day_columns[] = "DAY_{$day_padded}_SALES as sales_$day";
        }
    }
    
    if (empty($valid_dates)) continue;
    
    $columns_sql = implode(', ', $day_columns);
    $stockQuery = "SELECT ITEM_CODE, $columns_sql FROM $table_name WHERE STK_MONTH = ?";
    
    $stockStmt = $conn->prepare($stockQuery);
    $stockStmt->bind_param("s", $month);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    
    while ($row = $stockResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        if (!isset($items[$item_code])) continue;
        
        $item = $items[$item_code];
        $display_type = $item['hierarchy']['display_type'];
        $size_label = $item['size_label'];
        $brandName = getBrandName($item['details']);
        
        if (empty($brandName)) continue;
        
        foreach ($valid_dates as $date) {
            $day = date('d', strtotime($date));
            
            $opening = (int)($row["open_$day"] ?? 0);
            $purchase = (int)($row["purchase_$day"] ?? 0);
            $sales = (int)($row["sales_$day"] ?? 0);
            
            // Skip if all zeros (like excise register)
            if ($opening == 0 && $purchase == 0 && $sales == 0) {
                continue;
            }
            
            // Initialize data structure for this brand, category, and size
            if (!isset($all_daily_data[$date][$brandName])) {
                $all_daily_data[$date][$brandName] = [
                    'display_type' => $display_type,
                    'sizes' => []
                ];
            }
            
            if (!isset($all_daily_data[$date][$brandName]['sizes'][$size_label])) {
                $all_daily_data[$date][$brandName]['sizes'][$size_label] = [
                    'opening' => 0,
                    'purchase' => 0,
                    'sales' => 0,
                    'closing' => 0
                ];
            }
            
            // Aggregate data (multiple items might have same brand/size)
            $all_daily_data[$date][$brandName]['sizes'][$size_label]['opening'] += $opening;
            $all_daily_data[$date][$brandName]['sizes'][$size_label]['purchase'] += $purchase;
            $all_daily_data[$date][$brandName]['sizes'][$size_label]['sales'] += $sales;
        }
    }
    $stockStmt->close();
}

// ============================================================================
// STEP 7: Calculate running balances (EXACTLY like excise register)
// ============================================================================
$running_closing = []; // [brand][size] = closing balance

foreach ($calculation_dates as $index => $date) {
    if (!isset($all_daily_data[$date])) continue;
    
    foreach ($all_daily_data[$date] as $brand => &$brand_info) {
        foreach ($brand_info['sizes'] as $size => &$data) {
            // Get opening balance (same logic as excise register)
            if ($index == 0) {
                // First day (April 1st) - use actual opening from table
                $opening = $data['opening'];
            } else {
                // Use previous day's closing as opening
                $opening = $running_closing[$brand][$size] ?? 0;
            }
            
            $purchase = $data['purchase'];
            $sales = $data['sales'];
            
            // Calculate closing
            $closing = $opening + $purchase - $sales;
            $closing = max(0, $closing);
            
            // Update data with calculated values
            $data['opening'] = $opening;
            $data['closing'] = $closing;
            
            // Store for next day
            if (!isset($running_closing[$brand])) {
                $running_closing[$brand] = [];
            }
            $running_closing[$brand][$size] = $closing;
        }
    }
}

// ============================================================================
// STEP 8: Filter to only include display dates
// ============================================================================
$daily_data = [];
foreach ($display_dates as $date) {
    if (isset($all_daily_data[$date])) {
        $daily_data[$date] = $all_daily_data[$date];
    } else {
        $daily_data[$date] = [];
    }
}

// ============================================================================
// STEP 9: Determine which sizes have data across all dates
// ============================================================================
$active_sizes = [];
foreach ($daily_data as $date => $date_data) {
    foreach ($date_data as $brand => $brand_info) {
        foreach ($brand_info['sizes'] as $size => $values) {
            if ($values['opening'] > 0 || $values['purchase'] > 0 || 
                $values['sales'] > 0 || $values['closing'] > 0) {
                $active_sizes[$size] = true;
            }
        }
    }
}

// Sort sizes by volume (largest to smallest) like excise register
$all_possible_sizes = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1L',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

function getSizeVolumeInML($size_str) {
    if (preg_match('/(\d+(?:\.\d+)?)\s*(ML|L)/i', $size_str, $matches)) {
        $value = (float)$matches[1];
        $unit = strtoupper($matches[2]);
        if ($unit == 'L') {
            return $value * 1000;
        }
        return $value;
    }
    return 0;
}

// Build display sizes from active sizes, sorted largest to smallest
$display_sizes = [];
foreach ($all_possible_sizes as $size) {
    if (isset($active_sizes[$size])) {
        $display_sizes[] = $size;
    }
}

// Sort by volume (largest to smallest)
usort($display_sizes, function($a, $b) {
    $vol_a = getSizeVolumeInML($a);
    $vol_b = getSizeVolumeInML($b);
    if ($vol_a == $vol_b) return 0;
    return ($vol_a > $vol_b) ? -1 : 1;
});

// If no sizes found, use at least one default
if (empty($display_sizes)) {
    $display_sizes = ['1L'];
}

// ============================================================================
// STEP 10: Filter out brands with zero stock and ensure all sizes exist
// ============================================================================
foreach ($daily_data as $date => &$date_data) {
    foreach ($date_data as $brand => &$brand_info) {
        // Ensure all display sizes exist for this brand (with zeros)
        foreach ($display_sizes as $size) {
            if (!isset($brand_info['sizes'][$size])) {
                $brand_info['sizes'][$size] = [
                    'opening' => 0,
                    'purchase' => 0,
                    'sales' => 0,
                    'closing' => 0
                ];
            }
        }
        
        // Sort sizes according to display_sizes order
        $sorted_sizes = [];
        foreach ($display_sizes as $size) {
            $sorted_sizes[$size] = $brand_info['sizes'][$size];
        }
        $brand_info['sizes'] = $sorted_sizes;
        
        // Check if brand has any non-zero values
        $hasStock = false;
        foreach ($brand_info['sizes'] as $size => $values) {
            if ($values['opening'] > 0 || $values['purchase'] > 0 || 
                $values['sales'] > 0 || $values['closing'] > 0) {
                $hasStock = true;
                break;
            }
        }
        
        // Remove brand if no stock
        if (!$hasStock) {
            unset($date_data[$brand]);
        }
    }
    
    // Sort brands by display type and alphabetically
    $sorted_brands = [];
    foreach ($display_categories as $category) {
        $category_brands = array_filter($date_data, function($brand_info) use ($category) {
            return $brand_info['display_type'] == $category;
        });
        
        if (!empty($category_brands)) {
            ksort($category_brands); // Sort brands alphabetically
            $sorted_brands = array_merge($sorted_brands, $category_brands);
        }
    }
    
    $date_data = $sorted_brands;
}

// Calculate column positions for double lines
$total_columns = count($display_sizes) * 4; // Opening, Received, Sold, Closing

// Ensure the report is shown correctly
$show_report = $show_report && !empty($display_dates) && !empty($display_sizes);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FLR-3A Brandwise Register (Date-wise) - liqoursoft</title>
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
      border-bottom: double 3px #000;
    }

    .date-header-row {
      background-color: #e2e3e5;
      font-weight: bold;
      font-size: 12px;
      border-top: 3px solid #000;
      border-bottom: 3px solid #000;
    }
    
    .date-header-row td {
      background-color: #e2e3e5;
      font-weight: bold;
      font-size: 12px;
    }

    /* Double line separators between sections */
    <?php if (!empty($display_sizes)): ?>
    .report-table td:nth-child(<?= 3 + count($display_sizes) ?>),
    .report-table th:nth-child(<?= 3 + count($display_sizes) ?>) {
      border-right: double 3px #000 !important;
    }

    .report-table td:nth-child(<?= 3 + (count($display_sizes) * 2) ?>),
    .report-table th:nth-child(<?= 3 + (count($display_sizes) * 2) ?>) {
      border-right: double 3px #000 !important;
    }

    .report-table td:nth-child(<?= 3 + (count($display_sizes) * 3) ?>),
    .report-table th:nth-child(<?= 3 + (count($display_sizes) * 3) ?>) {
      border-right: double 3px #000 !important;
    }
    <?php endif; ?>

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
      min-width: 120px;
      max-width: 150px;
      white-space: normal;
      word-wrap: break-word;
    }
    .tp-nos-list span {
      display: inline-block;
      margin-right: 3px;
      margin-bottom: 2px;
      background-color: #e9ecef;
      padding: 1px 3px;
      border-radius: 3px;
      font-size: 8px;
    }

    /* Warning banner */
    .range-warning {
      background-color: #fff3cd;
      border: 1px solid #ffeeba;
      color: #856404;
      padding: 10px;
      margin-bottom: 15px;
      border-radius: 5px;
    }

    /* Financial year info */
    .fin-year-info {
      background-color: #d1ecf1;
      border-left: 4px solid #0c5460;
      padding: 8px;
      margin-bottom: 15px;
      font-size: 0.9em;
    }

    /* Size info note */
    .size-info-note {
      background-color: #f0f7ff;
      border-left: 4px solid #0066cc;
      padding: 8px;
      margin: 10px 0;
      font-size: 0.9em;
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
        background: white;
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
      
      .size-col {
        width: 18px !important;
        min-width: 18px !important;
        max-width: 18px !important;
      }
      
      .category-header {
        background-color: #d1ecf1 !important;
        font-weight: bold;
      }
      
      .category-total-row {
        background-color: #f8f9fa !important;
        font-weight: bold;
      }
      
      .date-header-row {
        background-color: #e2e3e5 !important;
        font-weight: bold;
      }
      
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 6px;
      }

      .tp-nos-list {
        font-size: 5px !important;
        line-height: 1;
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
      <h3 class="mb-4">FLR-3A Brandwise Register (Date-wise)</h3>

      <!-- Financial Year Info -->
      <div class="fin-year-info no-print">
        <strong><i class="fas fa-calendar-alt"></i> Financial Year:</strong> 
        <?= date('d-m-Y', strtotime($fin_year_start)) ?> to <?= date('d-m-Y', strtotime($fin_year_end)) ?>
      </div>

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
                  echo htmlspecialchars(implode(', ', $class_names));
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
      </div>

      <!-- Range Warning -->
      <?php if ($range_limited): ?>
      <div class="range-warning no-print">
        <i class="fas fa-exclamation-triangle"></i>
        <strong>Note:</strong> Date range too large. Showing only first <?= $max_days_per_request ?> days 
        (<?= date('d-m-Y', strtotime($from_date)) ?> to <?= date('d-m-Y', strtotime($to_date)) ?>). 
        Please select a smaller date range for complete data.
      </div>
      <?php endif; ?>

      <!-- Size Info Note -->
      <div class="size-info-note no-print">
        <strong><i class="fas fa-flask"></i> Note:</strong> Only sizes with data are displayed. 
        Sizes shown: <?= implode(', ', $display_sizes) ?>
        <br><strong>Opening balances are carried forward from <?= date('d-M-Y', strtotime($april_first)) ?></strong>
        <br><strong>Note:</strong> Opening balance for each date is the closing balance of the previous day. Closing = Opening + Received - Sold.
      </div>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters" id="reportForm">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">From Date:</label>
                <input type="date" name="from_date" class="form-control" 
                       value="<?= htmlspecialchars($from_date) ?>"
                       min="<?= htmlspecialchars($fin_year_start) ?>" 
                       max="<?= htmlspecialchars($fin_year_end) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date:</label>
                <input type="date" name="to_date" class="form-control" 
                       value="<?= htmlspecialchars($to_date) ?>"
                       min="<?= htmlspecialchars($fin_year_start) ?>" 
                       max="<?= htmlspecialchars($fin_year_end) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date Range Info:</label>
                <div class="form-control-plaintext">
                  <small class="text-muted">Selected: <?= count($display_dates) ?> day(s)</small>
                </div>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary" onclick="return validateDates()">
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
          <h6>Financial Year: <?= date('d-m-Y', strtotime($fin_year_start)) ?> to <?= date('d-m-Y', strtotime($fin_year_end)) ?></h6>
          <h6>From Date : <?= date('d-M-Y', strtotime($from_date)) ?> To Date : <?= date('d-M-Y', strtotime($to_date)) ?></h6>
          <h6><em>Opening balances carried forward from <?= date('d-M-Y', strtotime($april_first)) ?></em></h6>
        </div>
        
        <!-- FIXED SCROLLING CONTAINER -->
        <div class="table-container">
          <table class="report-table" id="brand-register-table">
            <thead>
              报
                <th rowspan="2" class="date-col">Sr. No.</th>
                <th rowspan="2" class="permit-col">Brand Name</th>
                <th rowspan="2" class="permit-col">TP NO</th>
                <th colspan="<?= count($display_sizes) ?>">OPENING BALANCE</th>
                <th colspan="<?= count($display_sizes) ?>">RECEIVED</th>
                <th colspan="<?= count($display_sizes) ?>">SOLD</th>
                <th colspan="<?= count($display_sizes) ?>">CLOSING BALANCE</th>
              </tr>
              <tr>
                <!-- Sizes row - Display all sizes once for each section -->
                <?php foreach ($display_sizes as $size): ?>
                  <th class="size-col"><?= htmlspecialchars($size) ?></th>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes as $size): ?>
                  <th class="size-col"><?= htmlspecialchars($size) ?></th>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes as $size): ?>
                  <th class="size-col"><?= htmlspecialchars($size) ?></th>
                <?php endforeach; ?>
                
                <?php foreach ($display_sizes as $size): ?>
                  <th class="size-col"><?= htmlspecialchars($size) ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php 
              $grand_sr_no = 1;
              $date_count = 0;
              
              foreach ($display_dates as $date): 
                if (empty($daily_data[$date])) continue;
                
                $date_count++;
                $date_sr_no = 1;
                $date_tp_nos = $tp_nos_data[$date] ?? [];
                
                // Initialize category totals for this date
                $date_category_totals = [];
                foreach ($display_categories as $category) {
                    $date_category_totals[$category] = [
                        'opening' => array_fill_keys($display_sizes, 0),
                        'purchase' => array_fill_keys($display_sizes, 0),
                        'sales' => array_fill_keys($display_sizes, 0),
                        'closing' => array_fill_keys($display_sizes, 0)
                    ];
                }
              ?>
              
              <!-- Date Header Row -->
              <tr class="date-header-row">
                <td colspan="<?= (3 + ($total_columns)) ?>" style="text-align: left; padding-left: 20px;">
                  <strong>Date: <?= date('d-m-Y', strtotime($date)) ?></strong>
                  <?php if (!empty($date_tp_nos)): ?>
                    <span style="margin-left: 20px;">TP Nos: <?= htmlspecialchars(implode(', ', array_slice($date_tp_nos, 0, 5))) ?><?= count($date_tp_nos) > 5 ? '...' : '' ?></span>
                  <?php endif; ?>
                </td>
              </tr>

              <!-- Group brands by display type -->
              <?php 
              $brands_by_category = [];
              
              foreach ($daily_data[$date] as $brand => $brand_info) {
                  $category = $brand_info['display_type'];
                  if (!isset($brands_by_category[$category])) {
                      $brands_by_category[$category] = [];
                  }
                  $brands_by_category[$category][$brand] = $brand_info;
              }
              
              // Display each category in order
              foreach ($display_categories as $category):
                  if (!isset($brands_by_category[$category])) continue;
              ?>
              
              <!-- Category Header -->
              <tr class="category-header">
                <td colspan="<?= (3 + ($total_columns)) ?>"><?= $category_display_names[$category] ?></td>
              </tr>
              
              <?php 
              // Display brands in this category
              foreach ($brands_by_category[$category] as $brand => $brand_info): 
              ?>
                <tr>
                  <td><?= $date_sr_no++ ?></td>
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  <td class="tp-nos-list">
                    <?php if (!empty($date_tp_nos)): ?>
                      <?php foreach (array_slice($date_tp_nos, 0, 3) as $tp_no): ?>
                        <span><?= htmlspecialchars($tp_no) ?></span>
                      <?php endforeach; ?>
                      <?php if (count($date_tp_nos) > 3): ?>
                        <span class="tp-more">+<?= count($date_tp_nos) - 3 ?> more</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>

                  <!-- OPENING BALANCE Section -->
                  <?php foreach ($display_sizes as $size): 
                      $opening = $brand_info['sizes'][$size]['opening'] ?? 0;
                      if ($opening > 0) {
                          $date_category_totals[$category]['opening'][$size] += $opening;
                      }
                  ?>
                    <td><?= $opening > 0 ? $opening : '' ?></td>
                  <?php endforeach; ?>

                  <!-- RECEIVED Section -->
                  <?php foreach ($display_sizes as $size): 
                      $purchase = $brand_info['sizes'][$size]['purchase'] ?? 0;
                      if ($purchase > 0) {
                          $date_category_totals[$category]['purchase'][$size] += $purchase;
                      }
                  ?>
                    <td><?= $purchase > 0 ? $purchase : '' ?></td>
                  <?php endforeach; ?>

                  <!-- SOLD Section -->
                  <?php foreach ($display_sizes as $size): 
                      $sales = $brand_info['sizes'][$size]['sales'] ?? 0;
                      if ($sales > 0) {
                          $date_category_totals[$category]['sales'][$size] += $sales;
                      }
                  ?>
                    <td><?= $sales > 0 ? $sales : '' ?></td>
                  <?php endforeach; ?>

                  <!-- CLOSING BALANCE Section -->
                  <?php foreach ($display_sizes as $size): 
                      $closing = $brand_info['sizes'][$size]['closing'] ?? 0;
                      if ($closing > 0) {
                          $date_category_totals[$category]['closing'][$size] += $closing;
                      }
                  ?>
                    <td><?= $closing > 0 ? $closing : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; // brands ?>
              
              <!-- Category Total for this date -->
              <tr class="category-total-row">
                <td colspan="3" style="text-align: right; font-weight: bold;">Category Total:</td>
                
                <!-- OPENING BALANCE Section Totals -->
                <?php foreach ($display_sizes as $size): ?>
                  <td><?= ($date_category_totals[$category]['opening'][$size] ?? 0) > 0 ? $date_category_totals[$category]['opening'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- RECEIVED Section Totals -->
                <?php foreach ($display_sizes as $size): ?>
                  <td><?= ($date_category_totals[$category]['purchase'][$size] ?? 0) > 0 ? $date_category_totals[$category]['purchase'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- SOLD Section Totals -->
                <?php foreach ($display_sizes as $size): ?>
                  <td><?= ($date_category_totals[$category]['sales'][$size] ?? 0) > 0 ? $date_category_totals[$category]['sales'][$size] : '' ?></td>
                <?php endforeach; ?>

                <!-- CLOSING BALANCE Section Totals -->
                <?php foreach ($display_sizes as $size): ?>
                  <td><?= ($date_category_totals[$category]['closing'][$size] ?? 0) > 0 ? $date_category_totals[$category]['closing'][$size] : '' ?></td>
                <?php endforeach; ?>
              </tr>
              
              <?php endforeach; // categories ?>
              
              <!-- Empty row between dates for better separation -->
              <tr style="height: 10px; background-color: transparent;">
                <td colspan="<?= (3 + ($total_columns)) ?>" style="border: none;">&nbsp;</td>
              </tr>
              
              <?php endforeach; // dates ?>
              
              <?php if ($date_count == 0): ?>
                <tr>
                  <td colspan="<?= (3 + ($total_columns)) ?>" class="text-center">No data available for the selected date range.</td>
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
          <p>Generated by liqoursoft on <?= date('d-M-Y h:i A') ?> | Total Days: <?= $date_count ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Date validation function
function validateDates() {
    const fromDate = document.querySelector('input[name="from_date"]').value;
    const toDate = document.querySelector('input[name="to_date"]').value;
    const finYearStart = '<?= $fin_year_start ?>';
    const finYearEnd = '<?= $fin_year_end ?>';
    
    if (fromDate && toDate) {
        const from = new Date(fromDate);
        const to = new Date(toDate);
        const start = new Date(finYearStart);
        const end = new Date(finYearEnd);
        
        if (from < start || from > end) {
            alert('From Date must be within the financial year');
            return false;
        }
        if (to < start || to > end) {
            alert('To Date must be within the financial year');
            return false;
        }
        if (from > to) {
            alert('From Date cannot be after To Date');
            return false;
        }
        
        // Calculate days difference
        const diffTime = Math.abs(to - from);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays > <?= $max_days_per_request ?>) {
            return confirm('Date range is large (' + diffDays + ' days). This may take some time to load. Continue?');
        }
    }
    return true;
}

function exportToExcel() {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    btn.disabled = true;
    
    setTimeout(function() {
        var table = document.getElementById('brand-register-table');
        var wb = XLSX.utils.book_new();
        var tableClone = table.cloneNode(true);
        var ws = XLSX.utils.table_to_sheet(tableClone);
        XLSX.utils.book_append_sheet(wb, ws, 'Brand Register');
        var fileName = 'Brand_Register_<?= date('Y-m-d') ?>.xlsx';
        XLSX.writeFile(wb, fileName);
        
        btn.innerHTML = originalText;
        btn.disabled = false;
    }, 100);
}

function exportToCSV() {
    const btn = event.target;
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    btn.disabled = true;
    
    setTimeout(function() {
        var table = document.getElementById('brand-register-table');
        var wb = XLSX.utils.book_new();
        var ws = XLSX.utils.table_to_sheet(table);
        var fileName = 'Brand_Register_<?= date('Y-m-d') ?>.csv';
        XLSX.writeFile(ws, fileName);
        
        btn.innerHTML = originalText;
        btn.disabled = false;
    }, 100);
}

// Load XLSX library dynamically
if (typeof XLSX === 'undefined') {
    var script = document.createElement('script');
    script.src = 'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js';
    document.head.appendChild(script);
}

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('generate') || urlParams.has('from_date')) {
        const reportContent = document.getElementById('reportContent');
        if (reportContent) {
            reportContent.classList.add('screen-display');
        }
    }
    
    const fromInput = document.querySelector('input[name="from_date"]');
    const toInput = document.querySelector('input[name="to_date"]');
    const finYearEnd = '<?= $fin_year_end ?>';
    
    if (fromInput && toInput) {
        fromInput.max = finYearEnd;
        toInput.max = finYearEnd;
    }
});
</script>
<?php require_once 'components/financial_year_footer.php'; ?>

</body>
</html>