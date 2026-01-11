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

include_once "../config/db.php";

// Get company ID from session
$compID = $_SESSION['CompID'];

// Handle license functions safely
$license_type = 'FL';
$allowed_classes = [];
$available_classes = [];

if (file_exists('license_functions.php')) {
    require_once 'license_functions.php';
    $company_id = $_SESSION['CompID'];
    
    if (function_exists('getCompanyLicenseType')) {
        $license_type = getCompanyLicenseType($company_id, $conn);
    }
    
    if (function_exists('getClassesByLicenseType')) {
        $available_classes = getClassesByLicenseType($license_type, $conn);
        foreach ($available_classes as $class) {
            if (isset($class['SGROUP'])) {
                $allowed_classes[] = $class['SGROUP'];
            }
        }
    }
} else {
    $license_type = 'FL';
    $allowed_classes = ['W', 'D', 'G', 'K', 'R', 'I', 'F', 'M', 'V', 'Q'];
}

// Default values
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Fetch company information
$companyName = "Company Name";
$licenseNo = "N/A";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
if ($companyStmt = $conn->prepare($companyQuery)) {
    $companyStmt->bind_param("i", $compID);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    if ($row = $companyResult->fetch_assoc()) {
        $companyName = $row['COMP_NAME'] ?? $companyName;
        $licenseNo = $row['COMP_FLNO'] ?? $licenseNo;
    }
    $companyStmt->close();
}

// Function to get available months from all tables - IMPROVED
function getAvailableMonths($conn, $compID) {
    $available_months = [];
    $tablePrefix = "tbldailystock_" . $compID;
    
    // Check for all tables matching the pattern
    $checkQuery = "SHOW TABLES LIKE '{$tablePrefix}%'";
    $result = $conn->query($checkQuery);
    
    if ($result) {
        while ($row = $result->fetch_array()) {
            $tableName = $row[0];
            
            // Check if this is the main table (no month/year suffix)
            if ($tableName == $tablePrefix) {
                // Main table - get all unique months from it
                $monthQuery = "SELECT DISTINCT STK_MONTH FROM $tableName WHERE STK_MONTH IS NOT NULL AND STK_MONTH != ''";
                $monthResult = $conn->query($monthQuery);
                if ($monthResult) {
                    while ($monthRow = $monthResult->fetch_assoc()) {
                        $stk_month = trim($monthRow['STK_MONTH']);
                        
                        // Try multiple date formats
                        $formats = ['Y-m', 'Y-m-d', 'Y/m', 'Y/m/d'];
                        $found = false;
                        
                        foreach ($formats as $format) {
                            $date = DateTime::createFromFormat($format, $stk_month);
                            if ($date !== false) {
                                $available_months[] = $date->format('Y-m');
                                $found = true;
                                break;
                            }
                        }
                        
                        // If no format matched, try regex
                        if (!$found) {
                            if (preg_match('/(\d{4}-\d{2})/', $stk_month, $matches)) {
                                $available_months[] = $matches[1];
                            } elseif (preg_match('/(\d{4}\d{2})/', $stk_month, $matches)) {
                                // Handle YYYYMM format
                                $available_months[] = substr($matches[1], 0, 4) . '-' . substr($matches[1], 4, 2);
                            }
                        }
                    }
                } else {
                    // If query fails, at least add current month
                    $available_months[] = date('Y-m');
                }
            } else {
                // Archive table - extract from table name
                // Pattern: tbldailystock_123_MM_YY or tbldailystock_123_MM_YYYY
                if (preg_match('/_(\d{2})_(\d{2,4})$/', $tableName, $matches)) {
                    $month_num = $matches[1];
                    $year_part = $matches[2];
                    
                    if (strlen($year_part) == 2) {
                        // Convert YY to YYYY (assuming 2000s)
                        $year_full = '20' . $year_part;
                    } else {
                        $year_full = $year_part;
                    }
                    
                    $available_months[] = $year_full . '-' . $month_num;
                }
            }
        }
    }
    
    // Always include current month even if table exists but has no data yet
    $current_month = date('Y-m');
    if (!in_array($current_month, $available_months)) {
        $available_months[] = $current_month;
    }
    
    // Check if main table exists at all
    $checkMain = "SHOW TABLES LIKE '$tablePrefix'";
    $mainResult = $conn->query($checkMain);
    
    if ($mainResult && $mainResult->num_rows > 0) {
        // Main table exists - ensure we have months from it
        $distinctQuery = "SELECT DISTINCT STK_MONTH FROM $tablePrefix WHERE STK_MONTH IS NOT NULL AND STK_MONTH != ''";
        $distinctResult = $conn->query($distinctQuery);
        if ($distinctResult) {
            while ($distinctRow = $distinctResult->fetch_assoc()) {
                $stk_month = trim($distinctRow['STK_MONTH']);
                if (preg_match('/(\d{4}-\d{2})/', $stk_month, $matches)) {
                    if (!in_array($matches[1], $available_months)) {
                        $available_months[] = $matches[1];
                    }
                }
            }
        }
    }
    
    // Remove duplicates and sort descending (newest first)
    $available_months = array_unique($available_months);
    
    // Custom sort to ensure YYYY-MM format sorts correctly (newest first)
    usort($available_months, function($a, $b) {
        return strtotime($b . '-01') - strtotime($a . '-01');
    });
    
    return $available_months;
}

// Get all available months
$available_months = getAvailableMonths($conn, $compID);

// If no month is selected or selected month not available, use current month if available, otherwise first available
if (empty($available_months)) {
    // No data available at all
    $month = date('Y-m');
} else {
    // Check if we have a valid month in GET
    if (isset($_GET['month']) && in_array($_GET['month'], $available_months)) {
        $month = $_GET['month'];
    } else {
        // No valid month selected, try current month first
        $current_month = date('Y-m');
        if (in_array($current_month, $available_months)) {
            $month = $current_month;
        } else {
            // Current month not available, use the most recent available month
            $month = $available_months[0]; // First one is newest due to sorting
        }
    }
}

// Extract year from selected month
$year = date('Y', strtotime($month . '-01'));
if ($year === false) {
    $year = date('Y'); // Fallback to current year
}

// IMPROVED Function to get correct table name with better archive detection
function getTableForMonth($conn, $compID, $month) {
    $current_month = date('Y-m');
    $tablePrefix = "tbldailystock_" . $compID;
    
    // First check if it's current month and main table exists
    if ($month == $current_month) {
        $tableName = $tablePrefix;
        
        $tableCheckQuery = "SHOW TABLES LIKE '$tableName'";
        $tableCheckResult = $conn->query($tableCheckQuery);
        
        if ($tableCheckResult && $tableCheckResult->num_rows > 0) {
            return $tableName;
        }
    }
    
    // For archive months - try multiple naming patterns
    $month_num = date('m', strtotime($month . '-01'));
    $year_full = date('Y', strtotime($month . '-01'));
    $year_short = date('y', strtotime($month . '-01'));
    
    // Common archive table naming patterns
    $patterns = [
        $tablePrefix . "_" . $month_num . "_" . $year_short,           // tbldailystock_123_11_25
        $tablePrefix . "_" . sprintf("%02d", $month_num) . "_" . $year_short,
        $tablePrefix . "_" . $month_num . "_" . $year_full,            // tbldailystock_123_11_2025
        $tablePrefix . "_m" . $month_num . "_y" . $year_short,
        $tablePrefix . "_" . $month_num . $year_short,                 // tbldailystock_123_1125
        $tablePrefix . "_archive_" . $month_num . "_" . $year_full,    // tbldailystock_123_archive_11_2025
    ];
    
    foreach ($patterns as $pattern) {
        $tableCheckQuery = "SHOW TABLES LIKE '$pattern'";
        $tableCheckResult = $conn->query($tableCheckQuery);
        
        if ($tableCheckResult && $tableCheckResult->num_rows > 0) {
            return $pattern;
        }
    }
    
    // Fallback to current table (even for archive months if archive table doesn't exist)
    $tableCheckQuery = "SHOW TABLES LIKE '$tablePrefix'";
    $tableCheckResult = $conn->query($tableCheckQuery);
    
    if ($tableCheckResult && $tableCheckResult->num_rows > 0) {
        return $tablePrefix;
    }
    
    return false;
}

// DEBUG: Check table availability
function checkAvailableTables($conn, $compID) {
    $tablePrefix = "tbldailystock_" . $compID;
    $tables = [];
    
    // Check for all tables matching the pattern
    $checkQuery = "SHOW TABLES LIKE '{$tablePrefix}%'";
    $result = $conn->query($checkQuery);
    
    if ($result) {
        while ($row = $result->fetch_array()) {
            $tables[] = $row[0];
        }
    }
    
    return $tables;
}

// Fixed Categorization Function
function getLiquorType($class) {
    $class = strtoupper(trim($class));
    
    // SPIRITS: W, D, G, K, R, I
    if (in_array($class, ['W', 'D', 'G', 'K', 'R', 'I'])) {
        return 'Spirits';
    }
    // MILD BEER: M class
    elseif ($class == 'M') {
        return 'Mild Beer';
    }
    // FERMENTED BEER: F class
    elseif ($class == 'F') {
        return 'Fermented Beer';
    }
    // WINE: V, Q classes
    elseif (in_array($class, ['V', 'Q'])) {
        return 'Wines';
    }
    // COUNTRY LIQUOR: C, B, S
    elseif (in_array($class, ['C', 'B', 'S'])) {
        return 'Country Liquor';
    }
    
    return 'Other';
}

// FIXED Size Normalization Function - Improved to handle various formats
function normalizeSize($size, $class = '') {
    $size = trim($size);
    $class = strtoupper(trim($class));
    
    // First, extract just the numeric ML value - more robust regex
    preg_match('/(\d+(?:\.\d+)?)\s*ML/i', $size, $matches);
    if (!isset($matches[1])) {
        // Try alternative patterns
        preg_match('/(\d+)\s*LTR/i', $size, $matches_ltr);
        if (isset($matches_ltr[1])) {
            $ml = (int)$matches_ltr[1] * 1000;
        } else {
            // Try to extract any numbers
            preg_match('/(\d+)/', $size, $matches_num);
            if (isset($matches_num[1])) {
                $ml = (int)$matches_num[1];
                // Assume it's ML if no unit specified
            } else {
                return 'Other';
            }
        }
    } else {
        $ml = (int)$matches[1];
    }
    
    // Special handling for beer sizes
    if ($class == 'M' || $class == 'F') {
        if ($ml == 330) return '330 ML';
        if ($ml == 500) return '500 ML';
        if ($ml == 650) return '650 ML';
        if ($ml == 1000) return '1000 ML';
        if ($ml == 275) return '275 ML';
        if ($ml == 250) return '250 ML';
        if ($ml == 750) return '750 ML';
        if ($ml == 700) return '700 ML';
        if ($ml == 375) return '375 ML';
        if ($ml == 200) return '200 ML';
        if ($ml == 180) return '180 ML';
        if ($ml == 90) return '90 ML';
        if ($ml == 60) return '60 ML';
        if ($ml == 50) return '50 ML';
    }
    
    // Special handling for wine sizes
    if ($class == 'V' || $class == 'Q') {
        if ($ml == 750) return '750 ML';
        if ($ml == 375) return '375 ML';
        if ($ml == 180) return '180 ML';
        if ($ml == 90) return '90 ML';
        if ($ml == 1000) return '1000 ML';
    }
    
    // General size grouping
    if ($ml >= 2000) return '2000 ML';
    elseif ($ml >= 1000) return '1000 ML';
    elseif ($ml >= 750) return '750 ML';
    elseif ($ml >= 700) return '700 ML';
    elseif ($ml >= 650) return '650 ML';
    elseif ($ml >= 500) return '500 ML';
    elseif ($ml >= 375) return '375 ML';
    elseif ($ml >= 330) return '330 ML';
    elseif ($ml >= 275) return '275 ML';
    elseif ($ml >= 250) return '250 ML';
    elseif ($ml >= 200) return '200 ML';
    elseif ($ml >= 180) return '180 ML';
    elseif ($ml >= 90) return '90 ML';
    elseif ($ml >= 60) return '60 ML';
    elseif ($ml >= 50) return '50 ML';
    
    return 'Other';
}

// Function to extract ML value from size string
function getMlFromSize($size) {
    preg_match('/(\d+(?:\.\d+)?)\s*ML/i', $size, $matches);
    if (isset($matches[1])) {
        return (float)$matches[1];
    }
    
    // Try LTR pattern
    preg_match('/(\d+)\s*LTR/i', $size, $matches_ltr);
    if (isset($matches_ltr[1])) {
        return (float)$matches_ltr[1] * 1000;
    }
    
    // Try to extract any number
    preg_match('/(\d+)/', $size, $matches_num);
    if (isset($matches_num[1])) {
        return (float)$matches_num[1];
    }
    
    return 0;
}

// Define display sizes
$display_sizes_s = ['2000 ML', '1000 ML', '750 ML', '700 ML', '500 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_w = ['1000 ML', '750 ML', '375 ML', '180 ML', '90 ML'];
$display_sizes_fb = ['1000 ML', '750 ML', '700 ML', '650 ML', '500 ML', '375 ML', '330 ML', '275 ML', '250 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_mb = ['1000 ML', '750 ML', '700 ML', '650 ML', '500 ML', '375 ML', '330 ML', '275 ML', '250 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_cl = ['1000 ML', '750 ML', '500 ML', '375 ML', '250 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];

// Get days in the selected month
$month_days = date('t', strtotime($month . '-01'));
$month_year = date('Y', strtotime($month . '-01'));
$month_number = date('m', strtotime($month . '-01'));

// Initialize monthly data structure
$monthly_data = [
    'Spirits' => [
        'opening' => array_fill_keys($display_sizes_s, 0),
        'received' => array_fill_keys($display_sizes_s, 0),
        'sold' => array_fill_keys($display_sizes_s, 0),
        'breakages' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0)
    ],
    'Wines' => [
        'opening' => array_fill_keys($display_sizes_w, 0),
        'received' => array_fill_keys($display_sizes_w, 0),
        'sold' => array_fill_keys($display_sizes_w, 0),
        'breakages' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0)
    ],
    'Fermented Beer' => [
        'opening' => array_fill_keys($display_sizes_fb, 0),
        'received' => array_fill_keys($display_sizes_fb, 0),
        'sold' => array_fill_keys($display_sizes_fb, 0),
        'breakages' => array_fill_keys($display_sizes_fb, 0),
        'closing' => array_fill_keys($display_sizes_fb, 0)
    ],
    'Mild Beer' => [
        'opening' => array_fill_keys($display_sizes_mb, 0),
        'received' => array_fill_keys($display_sizes_mb, 0),
        'sold' => array_fill_keys($display_sizes_mb, 0),
        'breakages' => array_fill_keys($display_sizes_mb, 0),
        'closing' => array_fill_keys($display_sizes_mb, 0)
    ],
    'Country Liquor' => [
        'opening' => array_fill_keys($display_sizes_cl, 0),
        'received' => array_fill_keys($display_sizes_cl, 0),
        'sold' => array_fill_keys($display_sizes_cl, 0),
        'breakages' => array_fill_keys($display_sizes_cl, 0),
        'closing' => array_fill_keys($display_sizes_cl, 0)
    ]
];

// Fetch item master data
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2 as SIZE, CLASS FROM tblitemmaster WHERE CLASS IN ($class_placeholders)";
    
    if ($itemStmt = $conn->prepare($itemQuery)) {
        $types = str_repeat('s', count($allowed_classes));
        $itemStmt->bind_param($types, ...$allowed_classes);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        while ($row = $itemResult->fetch_assoc()) {
            $items[$row['CODE']] = $row;
        }
        $itemStmt->close();
    }
} else {
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2 as SIZE, CLASS FROM tblitemmaster";
    if ($itemStmt = $conn->prepare($itemQuery)) {
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        while ($row = $itemResult->fetch_assoc()) {
            $items[$row['CODE']] = $row;
        }
        $itemStmt->close();
    }
}

// Fetch breakages data for the month
$breakages_data = [];
$breakagesQuery = "SELECT Code, SUM(BRK_Qty) as total_breakage 
                  FROM tblbreakages 
                  WHERE DATE_FORMAT(BRK_Date, '%Y-%m') = ? 
                  AND CompID = ? 
                  GROUP BY Code";
if ($breakagesStmt = $conn->prepare($breakagesQuery)) {
    $breakagesStmt->bind_param("si", $month, $compID);
    $breakagesStmt->execute();
    $breakagesResult = $breakagesStmt->get_result();
    while ($row = $breakagesResult->fetch_assoc()) {
        $breakages_data[$row['Code']] = (float)$row['total_breakage'];
    }
    $breakagesStmt->close();
}

// Get daily stock table - IMPROVED with debugging
$dailyStockTable = getTableForMonth($conn, $compID, $month);
$data_fetched = false;
$total_opening = 0;
$other_size_items = [];

// DEBUG: Show available tables
$available_tables = checkAvailableTables($conn, $compID);

if ($dailyStockTable) {
    // DEBUG: Check table structure
    $structure_query = "SHOW COLUMNS FROM $dailyStockTable";
    $structure_result = $conn->query($structure_query);
    $column_count = $structure_result ? $structure_result->num_rows : 0;
    
    // Build dynamic query for all days of the month
    $day_columns = [];
    for ($day = 1; $day <= $month_days; $day++) {
        $day_str = sprintf('%02d', $day);
        $day_columns[] = "DAY_{$day_str}_OPEN";
        $day_columns[] = "DAY_{$day_str}_PURCHASE";
        $day_columns[] = "DAY_{$day_str}_SALES";
        $day_columns[] = "DAY_{$day_str}_CLOSING";
    }
    $columns_str = implode(', ', $day_columns);
    
    // FIXED: Handle different STK_MONTH formats in archive tables
    // Try multiple date matching patterns
    $stockQuery = "SELECT ds.ITEM_CODE, $columns_str 
                  FROM $dailyStockTable ds 
                  WHERE (ds.STK_MONTH = ? OR 
                         ds.STK_MONTH LIKE ? OR 
                         DATE_FORMAT(ds.STK_MONTH, '%Y-%m') = ?)";
    
    if ($stockStmt = $conn->prepare($stockQuery)) {
        $month_param = $month;
        $month_like = $month . '%';
        $stockStmt->bind_param("sss", $month_param, $month_like, $month_param);
        $stockStmt->execute();
        $stockResult = $stockStmt->get_result();
        
        $row_count = 0;
        
        while ($row = $stockResult->fetch_assoc()) {
            $row_count++;
            $item_code = $row['ITEM_CODE'];
            
            if (!isset($items[$item_code])) {
                continue;
            }
            
            $item_details = $items[$item_code];
            $size = $item_details['SIZE'];
            $class = $item_details['CLASS'];
            
            // Determine category based on mode
            if ($mode == 'Country Liquor') {
                $category = getLiquorType($class);
                if ($category != 'Country Liquor') {
                    continue;
                }
            } else {
                $category = getLiquorType($class);
                if ($category == 'Country Liquor' || $category == 'Other') {
                    continue;
                }
            }
            
            $normalized_size = normalizeSize($size, $class);
            
            if ($normalized_size == 'Other') {
                $other_size_items[] = [
                    'code' => $item_code,
                    'class' => $class,
                    'size' => $size,
                    'normalized' => $normalized_size
                ];
                continue;
            }
            
            // Process data for each day
            $item_opening = 0;
            $item_received = 0;
            $item_sold = 0;
            $item_closing = 0;
            
            for ($day = 1; $day <= $month_days; $day++) {
                $day_str = sprintf('%02d', $day);
                
                // Day 1 opening
                if ($day == 1) {
                    $open_key = "DAY_{$day_str}_OPEN";
                    $item_opening = isset($row[$open_key]) ? (int)$row[$open_key] : 0;
                }
                
                // Purchases for the day
                $purchase_key = "DAY_{$day_str}_PURCHASE";
                $item_received += isset($row[$purchase_key]) ? (int)$row[$purchase_key] : 0;
                
                // Sales for the day
                $sales_key = "DAY_{$day_str}_SALES";
                $item_sold += isset($row[$sales_key]) ? (int)$row[$sales_key] : 0;
                
                // Last day closing
                if ($day == $month_days) {
                    $closing_key = "DAY_{$day_str}_CLOSING";
                    $item_closing = isset($row[$closing_key]) ? (int)$row[$closing_key] : 0;
                }
            }
            
            // Add breakages if any
            $item_breakages = $breakages_data[$item_code] ?? 0;
            
            // Update monthly data
            if (isset($monthly_data[$category]['opening'][$normalized_size])) {
                $monthly_data[$category]['opening'][$normalized_size] += $item_opening;
                $monthly_data[$category]['received'][$normalized_size] += $item_received;
                $monthly_data[$category]['sold'][$normalized_size] += $item_sold;
                $monthly_data[$category]['breakages'][$normalized_size] += $item_breakages;
                $monthly_data[$category]['closing'][$normalized_size] += $item_closing;
            } else {
                // If normalized size is not in display array, find appropriate size
                $ml = getMlFromSize($size);
                if ($ml > 0) {
                    $display_sizes = [];
                    switch ($category) {
                        case 'Spirits': $display_sizes = $display_sizes_s; break;
                        case 'Wines': $display_sizes = $display_sizes_w; break;
                        case 'Fermented Beer': $display_sizes = $display_sizes_fb; break;
                        case 'Mild Beer': $display_sizes = $display_sizes_mb; break;
                        case 'Country Liquor': $display_sizes = $display_sizes_cl; break;
                    }
                    
                    foreach ($display_sizes as $display_size) {
                        $display_ml = getMlFromSize($display_size);
                        if ($ml >= $display_ml) {
                            $monthly_data[$category]['opening'][$display_size] += $item_opening;
                            $monthly_data[$category]['received'][$display_size] += $item_received;
                            $monthly_data[$category]['sold'][$display_size] += $item_sold;
                            $monthly_data[$category]['breakages'][$display_size] += $item_breakages;
                            $monthly_data[$category]['closing'][$display_size] += $item_closing;
                            break;
                        }
                    }
                }
            }
            
            $total_opening += $item_opening;
        }
        $stockStmt->close();
        $data_fetched = ($row_count > 0);
    }
}

// Calculate totals
$spirits_total = array_sum($monthly_data['Spirits']['opening']) + 
                 array_sum($monthly_data['Spirits']['received']) -
                 array_sum($monthly_data['Spirits']['sold']) -
                 array_sum($monthly_data['Spirits']['breakages']);

$wine_total = array_sum($monthly_data['Wines']['opening']) + 
              array_sum($monthly_data['Wines']['received']) -
              array_sum($monthly_data['Wines']['sold']) -
              array_sum($monthly_data['Wines']['breakages']);

$fermented_beer_total = array_sum($monthly_data['Fermented Beer']['opening']) + 
                        array_sum($monthly_data['Fermented Beer']['received']) -
                        array_sum($monthly_data['Fermented Beer']['sold']) -
                        array_sum($monthly_data['Fermented Beer']['breakages']);

$mild_beer_total = array_sum($monthly_data['Mild Beer']['opening']) + 
                   array_sum($monthly_data['Mild Beer']['received']) -
                   array_sum($monthly_data['Mild Beer']['sold']) -
                   array_sum($monthly_data['Mild Beer']['breakages']);

$country_liquor_total = array_sum($monthly_data['Country Liquor']['opening']) + 
                        array_sum($monthly_data['Country Liquor']['received']) -
                        array_sum($monthly_data['Country Liquor']['sold']) -
                        array_sum($monthly_data['Country Liquor']['breakages']);

if ($mode == 'Country Liquor') {
    $calculated_total = $country_liquor_total;
} else {
    $calculated_total = $spirits_total + $wine_total + $fermented_beer_total + $mild_beer_total;
}

// Calculate summary in liters
$summary_liters = [
    'Spirits' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'breakages' => 0, 'closing' => 0],
    'Wines' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'breakages' => 0, 'closing' => 0],
    'Fermented Beer' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'breakages' => 0, 'closing' => 0],
    'Mild Beer' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'breakages' => 0, 'closing' => 0],
    'Country Liquor' => ['opening' => 0, 'receipts' => 0, 'sold' => 0, 'breakages' => 0, 'closing' => 0]
];

// Convert to liters for each category
$categories = ['Spirits', 'Wines', 'Fermented Beer', 'Mild Beer', 'Country Liquor'];
foreach ($categories as $category) {
    $display_sizes = [];
    switch ($category) {
        case 'Spirits': $display_sizes = $display_sizes_s; break;
        case 'Wines': $display_sizes = $display_sizes_w; break;
        case 'Fermented Beer': $display_sizes = $display_sizes_fb; break;
        case 'Mild Beer': $display_sizes = $display_sizes_mb; break;
        case 'Country Liquor': $display_sizes = $display_sizes_cl; break;
    }
    
    foreach ($display_sizes as $size) {
        $ml = getMlFromSize($size);
        $liters_factor = $ml / 1000;
        
        $summary_liters[$category]['opening'] += $monthly_data[$category]['opening'][$size] * $liters_factor;
        $summary_liters[$category]['receipts'] += $monthly_data[$category]['received'][$size] * $liters_factor;
        $summary_liters[$category]['sold'] += $monthly_data[$category]['sold'][$size] * $liters_factor;
        $summary_liters[$category]['breakages'] += $monthly_data[$category]['breakages'][$size] * $liters_factor;
        $summary_liters[$category]['closing'] += $monthly_data[$category]['closing'][$size] * $liters_factor;
    }
}

// Format liters
foreach ($summary_liters as $category => $data) {
    foreach ($data as $key => $value) {
        $summary_liters[$category][$key] = number_format($value, 2);
    }
}

// Calculate column counts
if ($mode == 'Country Liquor') {
    $total_columns = count($display_sizes_cl);
} else {
    $total_columns = count($display_sizes_s) + count($display_sizes_w) + count($display_sizes_fb) + count($display_sizes_mb);
}

// Group available months by year for dropdown display
$years_with_months = [];
foreach ($available_months as $avail_month) {
    $year = date('Y', strtotime($avail_month . '-01'));
    $month_name = date('F', strtotime($avail_month . '-01'));
    
    if (!isset($years_with_months[$year])) {
        $years_with_months[$year] = [];
    }
    $years_with_months[$year][] = [
        'value' => $avail_month,
        'name' => $month_name
    ];
}

// Get all available years
$available_years = array_keys($years_with_months);
rsort($available_years); // Show newest years first

// Determine current year for dropdown
$current_year_for_dropdown = $year;
if (!in_array($current_year_for_dropdown, $available_years) && !empty($available_years)) {
    $current_year_for_dropdown = $available_years[0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Monthly Register (FLR-4) - <?= htmlspecialchars($companyName) ?></title>
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
      <h3 class="mb-4">Monthly Register (FLR-4)</h3>

      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters" id="reportForm">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
            
            <div class="row mb-3">
              <div class="col-md-4">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-control" id="modeSelect">
                  <option value="Foreign Liquor" <?= $mode == 'Foreign Liquor' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="Country Liquor" <?= $mode == 'Country Liquor' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              
              <div class="col-md-4">
                <label class="form-label">Year:</label>
                <select name="year" class="form-control" id="yearSelect">
                  <?php
                  if (empty($available_years)) {
                    echo '<option value="">No data available</option>';
                  } else {
                    foreach ($available_years as $avail_year) {
                      $selected = ($current_year_for_dropdown == $avail_year) ? 'selected' : '';
                      echo "<option value=\"$avail_year\" $selected>$avail_year</option>";
                    }
                  }
                  ?>
                </select>
              </div>
              
              <div class="col-md-4">
                <label class="form-label">Month:</label>
                <select name="month" class="form-control" id="monthSelect">
                  <?php
                  if (isset($years_with_months[$current_year_for_dropdown])) {
                    foreach ($years_with_months[$current_year_for_dropdown] as $month_info) {
                      $selected = ($month == $month_info['value']) ? 'selected' : '';
                      echo "<option value=\"{$month_info['value']}\" $selected>{$month_info['name']} $current_year_for_dropdown</option>";
                    }
                  } elseif (!empty($available_months)) {
                    // Fallback: show all available months
                    foreach ($available_months as $avail_month) {
                      $selected = ($month == $avail_month) ? 'selected' : '';
                      $month_name = date('F Y', strtotime($avail_month . '-01'));
                      echo "<option value=\"$avail_month\" $selected>$month_name</option>";
                    }
                  } else {
                    echo '<option value="">No months available</option>';
                  }
                  ?>
                </select>
              </div>
            </div>
            
            <div class="row mb-3">
              <div class="col-md-12">
                <div class="form-check">
                  <input type="checkbox" class="form-check-input" name="debug" value="1" <?= isset($_GET['debug']) ? 'checked' : '' ?>>
                  <label class="form-check-label">Show Debug Info</label>
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

      <?php if (empty($available_months)): ?>
        <div class="alert alert-warning">
          <h5>No Data Available</h5>
          <p>There are no tables available for company ID: <?= $compID ?></p>
          <p>Please check if:</p>
          <ol>
            <li>Daily stock tables exist in the database</li>
            <li>Tables follow the naming pattern: tbldailystock_<?= $compID ?> or tbldailystock_<?= $compID ?>_MM_YY</li>
            <li>You have proper database permissions</li>
          </ol>
        </div>
      <?php else: ?>
        <div class="print-section">
          <div class="company-header">
            <h5>License to <?= htmlspecialchars($companyName) ?> (<?= $year ?> - <?= $year + 1 ?>), Pune.</h5>
            <h5>[Monthly Register]</h5>
            <h6><?= htmlspecialchars($companyName) ?></h6>
            <h6>LICENCE NO. :- <?= htmlspecialchars($licenseNo) ?></h6>
            <h6>Month: <?= date('F Y', strtotime($month . '-01')) ?></h6>
            <h6>Mode: <?= htmlspecialchars($mode) ?></h6>
            <h6>Days in Month: <?= $month_days ?></h6>
          </div>
          
          <?php if ($mode == 'Country Liquor'): ?>
            <!-- Country Liquor Table -->
            <div class="table-responsive">
              <table class="report-table">
                <thead>
                  <tr>
                    <th rowspan="3" class="description-col">Description</th>
                    <th colspan="<?= count($display_sizes_cl) ?>">COUNTRY LIQUOR</th>
                  </tr>
                  <tr>
                    <?php foreach ($display_sizes_cl as $size): ?>
                      <th class="size-col vertical-text"><?= $size ?></th>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <th colspan="<?= $total_columns ?>">SOL D IND. OF BOTTLES (in min.)</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="description-col">Opening Balance of the Beginning of the Month :-</td>
                    <?php foreach ($display_sizes_cl as $size): ?>
                      <td><?= $monthly_data['Country Liquor']['opening'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <td class="description-col">Received during the Current Month :-</td>
                    <?php foreach ($display_sizes_cl as $size): ?>
                      <td><?= $monthly_data['Country Liquor']['received'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <td class="description-col">Sold during the Current Month :-</td>
                    <?php foreach ($display_sizes_cl as $size): ?>
                      <td><?= $monthly_data['Country Liquor']['sold'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <td class="description-col">Breakages during the Current Month :-</td>
                    <?php foreach ($display_sizes_cl as $size): ?>
                      <td><?= $monthly_data['Country Liquor']['breakages'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <td class="description-col">Closing Balance at the End of the Month :-</td>
                    <?php foreach ($display_sizes_cl as $size): ?>
                      <td><?= $monthly_data['Country Liquor']['closing'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                </tbody>
              </table>
            </div>
            
            <!-- Summary Section for Country Liquor -->
            <div class="table-responsive mt-3">
              <table class="report-table">
                <thead>
                  <tr>
                    <th colspan="3" style="text-align: center;">SUMMARY (IN LITERS) - COUNTRY LIQUOR</th>
                  </tr>
                  <tr>
                    <th></th>
                    <th>COUNTRY LIQUOR</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><strong>Op. Stk. (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Country Liquor']['opening'] ?></td>
                  </tr>
                  <tr>
                    <td><strong>Receipts (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Country Liquor']['receipts'] ?></td>
                  </tr>
                  <tr>
                    <td><strong>Sold (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Country Liquor']['sold'] ?></td>
                  </tr>
                  <tr>
                    <td><strong>Breakages (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Country Liquor']['breakages'] ?></td>
                  </tr>
                  <tr>
                    <td><strong>Cl. Stk. (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Country Liquor']['closing'] ?></td>
                  </tr>
                </tbody>
              </table>
            </div>
            
          <?php else: ?>
            <!-- Foreign Liquor Table -->
            <div class="table-responsive">
              <table class="report-table">
                <thead>
                  <tr>
                    <th rowspan="3" class="description-col">Description</th>
                    <th colspan="<?= count($display_sizes_s) ?>">SPIRITS</th>
                    <th colspan="<?= count($display_sizes_w) ?>">WINE</th>
                    <th colspan="<?= count($display_sizes_fb) ?>">FERMENTED BEER</th>
                    <th colspan="<?= count($display_sizes_mb) ?>">MILD BEER</th>
                  </tr>
                  <tr>
                    <?php foreach ($display_sizes_s as $size): ?>
                      <th class="size-col vertical-text"><?= $size ?></th>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_w as $size): ?>
                      <th class="size-col vertical-text"><?= $size ?></th>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <th class="size-col vertical-text"><?= $size ?></th>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <th class="size-col vertical-text"><?= $size ?></th>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <th colspan="<?= $total_columns ?>">SOL D IND. OF BOTTLES (in min.)</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="description-col">Opening Balance of the Beginning of the Month :-</td>
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td><?= $monthly_data['Spirits']['opening'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td><?= $monthly_data['Wines']['opening'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td><?= $monthly_data['Fermented Beer']['opening'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td><?= $monthly_data['Mild Beer']['opening'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <td class="description-col">Received during the Current Month :-</td>
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td><?= $monthly_data['Spirits']['received'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td><?= $monthly_data['Wines']['received'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td><?= $monthly_data['Fermented Beer']['received'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td><?= $monthly_data['Mild Beer']['received'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <td class="description-col">Sold during the Current Month :-</td>
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td><?= $monthly_data['Spirits']['sold'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td><?= $monthly_data['Wines']['sold'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td><?= $monthly_data['Fermented Beer']['sold'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td><?= $monthly_data['Mild Beer']['sold'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <td class="description-col">Breakages during the Current Month :-</td>
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td><?= $monthly_data['Spirits']['breakages'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td><?= $monthly_data['Wines']['breakages'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td><?= $monthly_data['Fermented Beer']['breakages'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td><?= $monthly_data['Mild Beer']['breakages'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <td class="description-col">Closing Balance at the End of the Month :-</td>
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td><?= $monthly_data['Spirits']['closing'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td><?= $monthly_data['Wines']['closing'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td><?= $monthly_data['Fermented Beer']['closing'][$size] ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td><?= $monthly_data['Mild Beer']['closing'][$size] ?></td>
                    <?php endforeach; ?>
                  </tr>
                </tbody>
              </table>
            </div>
            
            <!-- Summary Section for Foreign Liquor -->
            <div class="table-responsive mt-3">
              <table class="report-table">
                <thead>
                  <tr>
                    <th colspan="5" style="text-align: center;">SUMMARY (IN LITERS) - FOREIGN LIQUOR</th>
                  </tr>
                  <tr>
                    <th></th>
                    <th>SPIRITS</th>
                    <th>WINE</th>
                    <th>FERMENTED BEER</th>
                    <th>MILD BEER</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><strong>Op. Stk. (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Spirits']['opening'] ?></td>
                    <td><?= $summary_liters['Wines']['opening'] ?></td>
                    <td><?= $summary_liters['Fermented Beer']['opening'] ?></td>
                    <td><?= $summary_liters['Mild Beer']['opening'] ?></td>
                  </tr>
                  <tr>
                    <td><strong>Receipts (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Spirits']['receipts'] ?></td>
                    <td><?= $summary_liters['Wines']['receipts'] ?></td>
                    <td><?= $summary_liters['Fermented Beer']['receipts'] ?></td>
                    <td><?= $summary_liters['Mild Beer']['receipts'] ?></td>
                  </tr>
                  <tr>
                    <td><strong>Sold (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Spirits']['sold'] ?></td>
                    <td><?= $summary_liters['Wines']['sold'] ?></td>
                    <td><?= $summary_liters['Fermented Beer']['sold'] ?></td>
                    <td><?= $summary_liters['Mild Beer']['sold'] ?></td>
                  </tr>
                  <tr>
                    <td><strong>Breakages (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Spirits']['breakages'] ?></td>
                    <td><?= $summary_liters['Wines']['breakages'] ?></td>
                    <td><?= $summary_liters['Fermented Beer']['breakages'] ?></td>
                    <td><?= $summary_liters['Mild Beer']['breakages'] ?></td>
                  </tr>
                  <tr>
                    <td><strong>Cl. Stk. (Ltrs.)</strong></td>
                    <td><?= $summary_liters['Spirits']['closing'] ?></td>
                    <td><?= $summary_liters['Wines']['closing'] ?></td>
                    <td><?= $summary_liters['Fermented Beer']['closing'] ?></td>
                    <td><?= $summary_liters['Mild Beer']['closing'] ?></td>
                  </tr>
                </tbody>
              </table>
            </div>
            
          <?php endif; ?>
          
          <div class="footer-info" style="text-align: right;">
             <p>Authorised Signature</p>
             <p>Generated on: <?= date('d/m/Y h:i A') ?> | User: <?= $_SESSION['user_name'] ?? 'System' ?></p>
             <p>Total Units: <?= number_format($calculated_total) ?> | Days in Month: <?= $month_days ?></p>
             <p>Table: <?= $dailyStockTable ?: 'Main Table' ?></p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Month data from PHP
const monthData = <?= json_encode($years_with_months) ?>;
let formSubmitted = false;

function updateMonthOptions() {
    const yearSelect = document.getElementById('yearSelect');
    const monthSelect = document.getElementById('monthSelect');
    const selectedYear = yearSelect.value;
    
    // Clear current options
    monthSelect.innerHTML = '';
    
    if (monthData[selectedYear]) {
        // Add options for selected year
        monthData[selectedYear].forEach(month => {
            const option = document.createElement('option');
            option.value = month.value;
            option.textContent = month.name + ' ' + selectedYear;
            monthSelect.appendChild(option);
        });
        
        // Select first month if none selected
        if (monthSelect.options.length > 0 && !formSubmitted) {
            monthSelect.selectedIndex = 0;
        }
    } else {
        // No months for this year
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No months available';
        monthSelect.appendChild(option);
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Update month options based on selected year
    updateMonthOptions();
    
    // Handle form submission
    const reportForm = document.getElementById('reportForm');
    reportForm.addEventListener('submit', function() {
        formSubmitted = true;
    });
    
    // Auto-submit when year changes (but not on initial load)
    const yearSelect = document.getElementById('yearSelect');
    yearSelect.addEventListener('change', function() {
        if (this.value) {
            // Update month options first
            updateMonthOptions();
            // Small delay to ensure month options are updated
            setTimeout(() => {
                reportForm.submit();
            }, 100);
        }
    });
    
    // Auto-submit when mode changes
    const modeSelect = document.getElementById('modeSelect');
    modeSelect.addEventListener('change', function() {
        reportForm.submit();
    });
});
</script>
</body>
</html>
<?php $conn->close(); ?>