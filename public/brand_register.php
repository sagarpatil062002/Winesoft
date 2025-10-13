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
        $month_year = date('m_y', strtotime($date));
        $table_name = "tbldailystock_" . $compID . "_" . $month_year;
        
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
    $current_date = $from_date;
    
    while (strtotime($current_date) <= strtotime($to_date)) {
        $table_name = getTableForDate($conn, $compID, $current_date);
        $month_year = date('Y-m', strtotime($current_date));
        
        if (!isset($tables[$table_name])) {
            $tables[$table_name] = [
                'table_name' => $table_name,
                'months' => []
            ];
        }
        
        if (!in_array($month_year, $tables[$table_name]['months'])) {
            $tables[$table_name]['months'][] = $month_year;
        }
        
        $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
    }
    
    return $tables;
}

// NEW: Check if specific day columns exist in a table
function tableHasDayColumns($conn, $table_name, $day) {
    $checkOpenQuery = "SHOW COLUMNS FROM $table_name LIKE 'DAY_{$day}_OPEN'";
    $checkPurchaseQuery = "SHOW COLUMNS FROM $table_name LIKE 'DAY_{$day}_PURCHASE'";
    $checkSalesQuery = "SHOW COLUMNS FROM $table_name LIKE 'DAY_{$day}_SALES'";
    $checkClosingQuery = "SHOW COLUMNS FROM $table_name LIKE 'DAY_{$day}_CLOSING'";
    
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

// Define size columns for each liquor type exactly as they appear in Excel
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

// Get display sizes (base sizes) for each liquor type - NEW ORDER: Spirit, Wine, Fermented Beer, Mild Beer
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
    
    // Check if this base size exists in the appropriate group - NEW ORDER
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

// NEW: Restructure data by liquor type -> brand
$brand_data_by_category = [
    'Spirits' => [],
    'Wines' => [],
    'Fermented Beer' => [],
    'Mild Beer' => []
];

// Initialize grand totals
$grand_totals = [
    'Spirits' => [
        'purchase' => array_fill_keys($display_sizes_s, 0),
        'sales' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0),
        'opening' => array_fill_keys($display_sizes_s, 0)
    ],
    'Wines' => [
        'purchase' => array_fill_keys($display_sizes_w, 0),
        'sales' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0),
        'opening' => array_fill_keys($display_sizes_w, 0)
    ],
    'Fermented Beer' => [
        'purchase' => array_fill_keys($display_sizes_fb, 0),
        'sales' => array_fill_keys($display_sizes_fb, 0),
        'closing' => array_fill_keys($display_sizes_fb, 0),
        'opening' => array_fill_keys($display_sizes_fb, 0)
    ],
    'Mild Beer' => [
        'purchase' => array_fill_keys($display_sizes_mb, 0),
        'sales' => array_fill_keys($display_sizes_mb, 0),
        'closing' => array_fill_keys($display_sizes_mb, 0),
        'opening' => array_fill_keys($display_sizes_mb, 0)
    ]
];

// Get all tables needed for the date range
$tables_needed = getTablesForDateRange($conn, $compID, $from_date, $to_date);

// Store cumulative stock data for each item
$cumulative_stock_data = [];

// Process each table
foreach ($tables_needed as $table_info) {
    $table_name = $table_info['table_name'];
    $months = $table_info['months'];
    
    // Process each month in this table
    foreach ($months as $month) {
        // Process each date in the range for this month
        $current_date = $from_date;
        while (strtotime($current_date) <= strtotime($to_date)) {
            $current_month = date('Y-m', strtotime($current_date));
            
            // Only process dates that belong to this month and table
            if ($current_month == $month) {
                $day = date('d', strtotime($current_date));
                
                // Check if this specific table has columns for this specific day
                if (!tableHasDayColumns($conn, $table_name, $day)) {
                    // Skip this date if columns don't exist
                    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
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
                            'opening' => 0,
                            'last_date' => $current_date
                        ];
                    }
                    
                    // NEW LOGIC: Accumulate purchase and sales (cumulative)
                    $cumulative_stock_data[$item_code]['purchase'] += $row['purchase'];
                    $cumulative_stock_data[$item_code]['sales'] += $row['sales'];
                    
                    // For closing balance, always take the latest value (last day in range)
                    $cumulative_stock_data[$item_code]['closing'] = $row['closing'];
                    $cumulative_stock_data[$item_code]['last_date'] = $current_date;
                    
                    // For opening balance, take the first value (first day in range)
                    if ($cumulative_stock_data[$item_code]['opening'] == 0) {
                        $cumulative_stock_data[$item_code]['opening'] = $row['opening'];
                    }
                    
                    // Store LIQ_FLAG for later use
                    $cumulative_stock_data[$item_code]['liq_flag'] = $row['LIQ_FLAG'];
                }
                
                $stockStmt->close();
            }
            
            $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
        }
    }
}

// Process cumulative stock data
foreach ($cumulative_stock_data as $item_code => $stock_data) {
    $item_details = $items[$item_code];
    $size = $item_details['DETAILS2'];
    $class = $item_details['CLASS'];
    $liq_flag = $stock_data['liq_flag'];
    
    // Extract brand name
    $brandName = getBrandName($item_details['DETAILS']);
    if (empty($brandName)) continue;
    
    // Determine liquor type
    $liquor_type = getLiquorType($class, $liq_flag);
    
    // Map database size to Excel size
    $excel_size = isset($size_mapping[$size]) ? $size_mapping[$size] : $size;
    
    // Get grouped size for display
    $grouped_size = getGroupedSize($excel_size, $liquor_type);
    
    // Initialize brand data if not exists
    if (!isset($brand_data_by_category[$liquor_type][$brandName])) {
        $brand_data_by_category[$liquor_type][$brandName] = [
            'Spirits' => [
                'purchase' => array_fill_keys($display_sizes_s, 0),
                'sales' => array_fill_keys($display_sizes_s, 0),
                'closing' => array_fill_keys($display_sizes_s, 0),
                'opening' => array_fill_keys($display_sizes_s, 0)
            ],
            'Wines' => [
                'purchase' => array_fill_keys($display_sizes_w, 0),
                'sales' => array_fill_keys($display_sizes_w, 0),
                'closing' => array_fill_keys($display_sizes_w, 0),
                'opening' => array_fill_keys($display_sizes_w, 0)
            ],
            'Fermented Beer' => [
                'purchase' => array_fill_keys($display_sizes_fb, 0),
                'sales' => array_fill_keys($display_sizes_fb, 0),
                'closing' => array_fill_keys($display_sizes_fb, 0),
                'opening' => array_fill_keys($display_sizes_fb, 0)
            ],
            'Mild Beer' => [
                'purchase' => array_fill_keys($display_sizes_mb, 0),
                'sales' => array_fill_keys($display_sizes_mb, 0),
                'closing' => array_fill_keys($display_sizes_mb, 0),
                'opening' => array_fill_keys($display_sizes_mb, 0)
            ]
        ];
    }
    
    // Add to brand data and grand totals based on liquor type and grouped size
    // USING CUMULATIVE DATA for purchase and sales, LATEST for closing
    switch ($liquor_type) {
        case 'Spirits':
            if (in_array($grouped_size, $display_sizes_s)) {
                $brand_data_by_category[$liquor_type][$brandName]['Spirits']['purchase'][$grouped_size] += $stock_data['purchase'];
                $brand_data_by_category[$liquor_type][$brandName]['Spirits']['sales'][$grouped_size] += $stock_data['sales'];
                $brand_data_by_category[$liquor_type][$brandName]['Spirits']['closing'][$grouped_size] = $stock_data['closing'];
                $brand_data_by_category[$liquor_type][$brandName]['Spirits']['opening'][$grouped_size] += $stock_data['opening'];
                
                $grand_totals['Spirits']['purchase'][$grouped_size] += $stock_data['purchase'];
                $grand_totals['Spirits']['sales'][$grouped_size] += $stock_data['sales'];
                $grand_totals['Spirits']['closing'][$grouped_size] += $stock_data['closing'];
                $grand_totals['Spirits']['opening'][$grouped_size] += $stock_data['opening'];
            }
            break;
            
        case 'Wines':
            if (in_array($grouped_size, $display_sizes_w)) {
                $brand_data_by_category[$liquor_type][$brandName]['Wines']['purchase'][$grouped_size] += $stock_data['purchase'];
                $brand_data_by_category[$liquor_type][$brandName]['Wines']['sales'][$grouped_size] += $stock_data['sales'];
                $brand_data_by_category[$liquor_type][$brandName]['Wines']['closing'][$grouped_size] = $stock_data['closing'];
                $brand_data_by_category[$liquor_type][$brandName]['Wines']['opening'][$grouped_size] += $stock_data['opening'];
                
                $grand_totals['Wines']['purchase'][$grouped_size] += $stock_data['purchase'];
                $grand_totals['Wines']['sales'][$grouped_size] += $stock_data['sales'];
                $grand_totals['Wines']['closing'][$grouped_size] += $stock_data['closing'];
                $grand_totals['Wines']['opening'][$grouped_size] += $stock_data['opening'];
            }
            break;
            
        case 'Fermented Beer':
            if (in_array($grouped_size, $display_sizes_fb)) {
                $brand_data_by_category[$liquor_type][$brandName]['Fermented Beer']['purchase'][$grouped_size] += $stock_data['purchase'];
                $brand_data_by_category[$liquor_type][$brandName]['Fermented Beer']['sales'][$grouped_size] += $stock_data['sales'];
                $brand_data_by_category[$liquor_type][$brandName]['Fermented Beer']['closing'][$grouped_size] = $stock_data['closing'];
                $brand_data_by_category[$liquor_type][$brandName]['Fermented Beer']['opening'][$grouped_size] += $stock_data['opening'];
                
                $grand_totals['Fermented Beer']['purchase'][$grouped_size] += $stock_data['purchase'];
                $grand_totals['Fermented Beer']['sales'][$grouped_size] += $stock_data['sales'];
                $grand_totals['Fermented Beer']['closing'][$grouped_size] += $stock_data['closing'];
                $grand_totals['Fermented Beer']['opening'][$grouped_size] += $stock_data['opening'];
            }
            break;
            
        case 'Mild Beer':
            if (in_array($grouped_size, $display_sizes_mb)) {
                $brand_data_by_category[$liquor_type][$brandName]['Mild Beer']['purchase'][$grouped_size] += $stock_data['purchase'];
                $brand_data_by_category[$liquor_type][$brandName]['Mild Beer']['sales'][$grouped_size] += $stock_data['sales'];
                $brand_data_by_category[$liquor_type][$brandName]['Mild Beer']['closing'][$grouped_size] = $stock_data['closing'];
                $brand_data_by_category[$liquor_type][$brandName]['Mild Beer']['opening'][$grouped_size] += $stock_data['opening'];
                
                $grand_totals['Mild Beer']['purchase'][$grouped_size] += $stock_data['purchase'];
                $grand_totals['Mild Beer']['sales'][$grouped_size] += $stock_data['sales'];
                $grand_totals['Mild Beer']['closing'][$grouped_size] += $stock_data['closing'];
                $grand_totals['Mild Beer']['opening'][$grouped_size] += $stock_data['opening'];
            }
            break;
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
  <title>FLR-3A Brandwise Register - WineSoft</title>
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
    
    /* FIXED SCROLLING CONTAINER - Similar to closing_stock.php */
    .table-container {
  width: 100%;
  overflow-x: auto;
  overflow-y: visible;
  position: relative;
  margin-bottom: 15px;
  border: 1px solid #dee2e6;
  max-height: calc(100vh - 300px); /* Limit height to viewport */
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
  height: 1px; /* Minimal height, just to enable scrolling */
  min-width: 100%; /* Ensure it's at least as wide as the table */
}
.report-table {
  width: auto;
  min-width: 100%;
  border-collapse: collapse;
  font-size: 10px;
  margin-bottom: 0; /* Remove bottom margin */
}    .report-table th, .report-table td {
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
    .category-header {
      background-color: #d1ecf1;
      font-weight: bold;
      text-align: left;
      padding-left: 10px;
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

    /* Print styles - UPDATED TO MATCH CLOSING_STOCK.PHP */
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
      
      .category-header {
        background-color: #d1ecf1 !important;
        font-weight: bold;
      }
      
      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 6px;
      }
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
        
        <!-- FIXED SCROLLING CONTAINER - Similar to closing_stock.php -->
        <div class="table-container">
          <table class="report-table">
            <thead>
              <tr>
                <th rowspan="3" class="date-col">Sr. No.</th>
                <th rowspan="3" class="permit-col">TP NO</th>
                <th rowspan="3" class="permit-col">Brand Name</th>
                <th colspan="<?= $total_columns ?>">Received</th>
                <th colspan="<?= $total_columns ?>">Sold</th>
                <th colspan="<?= $total_columns ?>">Closing Balance</th>
                <th rowspan="3" class="signature-col">Total</th>
              </tr>
              <tr>
                <!-- NEW ORDER: Spirit, Wine, Fermented Beer, Mild Beer -->
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wines</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wines</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wines</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
              </tr>
              <tr>
                <!-- Spirits Received - Vertical text for print -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Received -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Received -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Received -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Spirits Closing Balance -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Wines Closing Balance -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Closing Balance -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing Balance -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php $sr_no = 1; ?>
              
              <!-- Spirits Section -->
              <?php if (!empty($brand_data_by_category['Spirits'])): ?>
              <tr class="category-header">
                <td colspan="<?= ($total_columns * 3) + 4 ?>">SPIRITS</td>
              </tr>
              <?php foreach ($brand_data_by_category['Spirits'] as $brand => $brand_categories): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td></td> <!-- TP NO -->
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  
                  <!-- Received Section - NEW ORDER -->
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - NEW ORDER -->
                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section - NEW ORDER -->
                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Total column for the brand -->
                  <td>
                    <?php
                    $brand_total = 0;
                    foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                        $brand_total += array_sum($brand_categories[$category]['closing']);
                    }
                    echo $brand_total;
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php endif; ?>
              
              <!-- Wines Section -->
              <?php if (!empty($brand_data_by_category['Wines'])): ?>
              <tr class="category-header">
                <td colspan="<?= ($total_columns * 3) + 4 ?>">WINES</td>
              </tr>
              <?php foreach ($brand_data_by_category['Wines'] as $brand => $brand_categories): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td></td> <!-- TP NO -->
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  
                  <!-- Received Section - NEW ORDER -->
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - NEW ORDER -->
                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section - NEW ORDER -->
                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Total column for the brand -->
                  <td>
                    <?php
                    $brand_total = 0;
                    foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                        $brand_total += array_sum($brand_categories[$category]['closing']);
                    }
                    echo $brand_total;
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php endif; ?>
              
              <!-- Fermented Beer Section -->
              <?php if (!empty($brand_data_by_category['Fermented Beer'])): ?>
              <tr class="category-header">
                <td colspan="<?= ($total_columns * 3) + 4 ?>">FERMENTED BEER</td>
              </tr>
              <?php foreach ($brand_data_by_category['Fermented Beer'] as $brand => $brand_categories): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td></td> <!-- TP NO -->
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  
                  <!-- Received Section - NEW ORDER -->
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - NEW ORDER -->
                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section - NEW ORDER -->
                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Total column for the brand -->
                  <td>
                    <?php
                    $brand_total = 0;
                    foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                        $brand_total += array_sum($brand_categories[$category]['closing']);
                    }
                    echo $brand_total;
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php endif; ?>
              
              <!-- Mild Beer Section -->
              <?php if (!empty($brand_data_by_category['Mild Beer'])): ?>
              <tr class="category-header">
                <td colspan="<?= ($total_columns * 3) + 4 ?>">MILD BEER</td>
              </tr>
              <?php foreach ($brand_data_by_category['Mild Beer'] as $brand => $brand_categories): ?>
                <tr>
                  <td><?= $sr_no++ ?></td>
                  <td></td> <!-- TP NO -->
                  <td style="text-align: left;"><?= htmlspecialchars($brand) ?></td>
                  
                  <!-- Received Section - NEW ORDER -->
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Sold Section - NEW ORDER -->
                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Closing Balance Section - NEW ORDER -->
                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $brand_categories['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $brand_categories['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $brand_categories['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $brand_categories['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <!-- Total column for the brand -->
                  <td>
                    <?php
                    $brand_total = 0;
                    foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                        $brand_total += array_sum($brand_categories[$category]['closing']);
                    }
                    echo $brand_total;
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php endif; ?>
              
              <!-- Grand Total row -->
              <tr class="summary-row">
                <td colspan="3">Grand Total</td>
                
                <!-- Received Section - NEW ORDER -->
                <!-- Spirits Received Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $grand_totals['Spirits']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Received Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $grand_totals['Wines']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Received Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $grand_totals['Fermented Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Received Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $grand_totals['Mild Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Sold Section - NEW ORDER -->
                <!-- Spirits Sold Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $grand_totals['Spirits']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Sold Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $grand_totals['Wines']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Sold Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $grand_totals['Fermented Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Sold Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $grand_totals['Mild Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Closing Balance Section - NEW ORDER -->
                <!-- Spirits Closing Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $grand_totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Wines Closing Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $grand_totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Fermented Beer Closing Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $grand_totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Mild Beer Closing Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $grand_totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <!-- Grand Total column -->
                <td>
                  <?php
                  $grand_total = 0;
                  foreach (['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer'] as $category) {
                      $grand_total += array_sum($grand_totals[$category]['closing']);
                  }
                  echo $grand_total;
                  ?>
                </td>
              </tr>
            </tbody>
          </table>
            <div class="scrollbar-container">
    <div class="scrollbar-content"></div>

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
          <p>Generated by WineSoft on <?= date('d-M-Y h:i A') ?></p>
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