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
require_once 'license_functions.php'; // ADDED: Include license functions

// Get company ID from session
$compID = $_SESSION['CompID'];

// ADDED: Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// ADDED: Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Default values - Monthly register (current month)
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
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

// Function to group sizes by base size (remove suffixes after ML and trim)
function getBaseSize($size) {
    // Extract the base size (everything before any special characters after ML)
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize); // Remove trailing - numbers
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize); // Remove trailing (numbers)
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize); // Remove anything in parentheses
    return trim($baseSize);
}

// Function to extract ML value from size string
function getMlFromSize($size) {
    preg_match('/(\d+(?:\.\d+)?)\s*ML/i', $size, $matches);
    return isset($matches[1]) ? (float)$matches[1] : 0;
}

// Function to convert bottles to liters
function convertBottlesToLiters($bottles, $size) {
    $ml = getMlFromSize($size);
    return $bottles * ($ml / 1000);
}

// Define size columns exactly as they appear in FLR Datewise
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

// Get display sizes (base sizes) for each liquor type - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
$display_sizes_s = ['2000 ML', '1000 ML', '750 ML', '700 ML', '500 ML', '375 ML', '200 ML', '180 ML', '90 ML', '60 ML', '50 ML'];
$display_sizes_imported = $display_sizes_s; // Imported uses same sizes as Spirit
$display_sizes_w = ['750 ML', '375 ML', '180 ML', '90 ML'];
$display_sizes_wine_imp = $display_sizes_w; // Wine Imp uses same sizes as Wine
$display_sizes_fb = ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];
$display_sizes_mb = ['1000 ML', '650 ML', '500 ML', '330 ML', '275 ML', '250 ML'];

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

// MODIFIED: Fetch item master data with size information - FILTERED BY LICENSE TYPE
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, LIQ_FLAG FROM tblitemmaster WHERE CLASS IN ($class_placeholders)";
    
    $itemStmt = $conn->prepare($itemQuery);
    $types = str_repeat('s', count($allowed_classes));
    $itemStmt->bind_param($types, ...$allowed_classes);
    $itemStmt->execute();
    $itemResult = $itemStmt->get_result();
    while ($row = $itemResult->fetch_assoc()) {
        $items[$row['CODE']] = $row;
    }
    $itemStmt->close();
} else {
    // If no classes allowed, items array will remain empty
}

// Initialize monthly data structure - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
$monthly_data = [
    'Spirits' => [
        'opening' => array_fill_keys($display_sizes_s, 0),
        'received' => array_fill_keys($display_sizes_s, 0),
        'sold' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0),
        'breakages' => array_fill_keys($display_sizes_s, 0)
    ],
    'Imported Spirit' => [
        'opening' => array_fill_keys($display_sizes_imported, 0),
        'received' => array_fill_keys($display_sizes_imported, 0),
        'sold' => array_fill_keys($display_sizes_imported, 0),
        'closing' => array_fill_keys($display_sizes_imported, 0),
        'breakages' => array_fill_keys($display_sizes_imported, 0)
    ],
    'Wines' => [
        'opening' => array_fill_keys($display_sizes_w, 0),
        'received' => array_fill_keys($display_sizes_w, 0),
        'sold' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0),
        'breakages' => array_fill_keys($display_sizes_w, 0)
    ],
    'Wine Imp' => [
        'opening' => array_fill_keys($display_sizes_wine_imp, 0),
        'received' => array_fill_keys($display_sizes_wine_imp, 0),
        'sold' => array_fill_keys($display_sizes_wine_imp, 0),
        'closing' => array_fill_keys($display_sizes_wine_imp, 0),
        'breakages' => array_fill_keys($display_sizes_wine_imp, 0)
    ],
    'Fermented Beer' => [
        'opening' => array_fill_keys($display_sizes_fb, 0),
        'received' => array_fill_keys($display_sizes_fb, 0),
        'sold' => array_fill_keys($display_sizes_fb, 0),
        'closing' => array_fill_keys($display_sizes_fb, 0),
        'breakages' => array_fill_keys($display_sizes_fb, 0)
    ],
    'Mild Beer' => [
        'opening' => array_fill_keys($display_sizes_mb, 0),
        'received' => array_fill_keys($display_sizes_mb, 0),
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
    // First check the class directly for Imported and Wine Imp
    if ($class == 'I') return 'Imported Spirit';
    if ($class == 'W') return 'Wine Imp';

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

    // Check if this base size exists in the appropriate group - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
    switch ($liquor_type) {
        case 'Spirits':
        case 'Imported Spirit': // Imported Spirit uses same grouping as Spirits
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

// Process monthly data
$month_start = $month . '-01';
$month_end = date('Y-m-t', strtotime($month_start));
$days_in_month = date('t', strtotime($month_start));

// Fetch all stock data for this month
for ($day = 1; $day <= $days_in_month; $day++) {
    $padded_day = str_pad($day, 2, '0', STR_PAD_LEFT);
    $current_date = date('Y-m-d', strtotime($month_start . ' + ' . ($day - 1) . ' days'));
    
    // Get appropriate table for this date
    $dailyStockTable = getTableForDate($conn, $compID, $current_date);
    
    // Check if this specific table has columns for this specific day
    if (!tableHasDayColumns($conn, $dailyStockTable, $day)) {
        // Skip this day as the table doesn't have columns for this day
        continue;
    }
    
    $stockQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                  DAY_{$padded_day}_OPEN as opening, 
                  DAY_{$padded_day}_PURCHASE as purchase, 
                  DAY_{$padded_day}_SALES as sales, 
                  DAY_{$padded_day}_CLOSING as closing 
                  FROM $dailyStockTable 
                  WHERE STK_MONTH = ?";
    
    $stockStmt = $conn->prepare($stockQuery);
    $stockStmt->bind_param("s", $month);
    $stockStmt->execute();
    $stockResult = $stockStmt->get_result();
    
    while ($row = $stockResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];
        
        // MODIFIED: Skip if item not found in master OR if item class is not allowed by license
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
        
        // Add to monthly data based on liquor type and grouped size - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
        switch ($liquor_type) {
            case 'Spirits':
                if (in_array($grouped_size, $display_sizes_s)) {
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Spirits']['received'][$grouped_size] += $row['purchase'];

                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Spirits']['sold'][$grouped_size] += $row['sales'];
                }
                break;

            case 'Imported Spirit':
                if (in_array($grouped_size, $display_sizes_imported)) {
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Imported Spirit']['received'][$grouped_size] += $row['purchase'];

                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Imported Spirit']['sold'][$grouped_size] += $row['sales'];
                }
                break;

            case 'Wines':
                if (in_array($grouped_size, $display_sizes_w)) {
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Wines']['received'][$grouped_size] += $row['purchase'];

                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Wines']['sold'][$grouped_size] += $row['sales'];
                }
                break;

            case 'Wine Imp':
                if (in_array($grouped_size, $display_sizes_wine_imp)) {
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Wine Imp']['received'][$grouped_size] += $row['purchase'];

                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Wine Imp']['sold'][$grouped_size] += $row['sales'];
                }
                break;

            case 'Fermented Beer':
                if (in_array($grouped_size, $display_sizes_fb)) {
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Fermented Beer']['received'][$grouped_size] += $row['purchase'];

                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Fermented Beer']['sold'][$grouped_size] += $row['sales'];
                }
                break;

            case 'Mild Beer':
                if (in_array($grouped_size, $display_sizes_mb)) {
                    // Received during month (all DAY_X_PURCHASE)
                    $monthly_data['Mild Beer']['received'][$grouped_size] += $row['purchase'];

                    // Sold during month (all DAY_X_SALES)
                    $monthly_data['Mild Beer']['sold'][$grouped_size] += $row['sales'];
                }
                break;
        }
    }
    
    $stockStmt->close();
}

// Fetch breakages data for the selected month
$breakages_data = [
    'Spirits' => array_fill_keys($display_sizes_s, 0),
    'Imported Spirit' => array_fill_keys($display_sizes_imported, 0),
    'Wines' => array_fill_keys($display_sizes_w, 0),
    'Wine Imp' => array_fill_keys($display_sizes_wine_imp, 0),
    'Fermented Beer' => array_fill_keys($display_sizes_fb, 0),
    'Mild Beer' => array_fill_keys($display_sizes_mb, 0)
];

$breakagesQuery = "SELECT b.Code, b.BRK_Qty, i.DETAILS2, i.CLASS, i.LIQ_FLAG 
                   FROM tblbreakages b 
                   JOIN tblitemmaster i ON b.Code = i.CODE 
                   WHERE b.CompID = ? AND DATE_FORMAT(b.BRK_Date, '%Y-%m') = ?";
$breakagesStmt = $conn->prepare($breakagesQuery);
$breakagesStmt->bind_param("is", $compID, $month);
$breakagesStmt->execute();
$breakagesResult = $breakagesStmt->get_result();

while ($row = $breakagesResult->fetch_assoc()) {
    $item_code = $row['Code'];
    
    // MODIFIED: Skip if item not found in master OR if item class is not allowed by license
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
    
    // Add to breakages data based on liquor type and grouped size - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
    switch ($liquor_type) {
        case 'Spirits':
            if (in_array($grouped_size, $display_sizes_s)) {
                $breakages_data['Spirits'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Spirits']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;

        case 'Imported Spirit':
            if (in_array($grouped_size, $display_sizes_imported)) {
                $breakages_data['Imported Spirit'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Imported Spirit']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;

        case 'Wines':
            if (in_array($grouped_size, $display_sizes_w)) {
                $breakages_data['Wines'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Wines']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;

        case 'Wine Imp':
            if (in_array($grouped_size, $display_sizes_wine_imp)) {
                $breakages_data['Wine Imp'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Wine Imp']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;

        case 'Fermented Beer':
            if (in_array($grouped_size, $display_sizes_fb)) {
                $breakages_data['Fermented Beer'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Fermented Beer']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;

        case 'Mild Beer':
            if (in_array($grouped_size, $display_sizes_mb)) {
                $breakages_data['Mild Beer'][$grouped_size] += $row['BRK_Qty'];
                $monthly_data['Mild Beer']['breakages'][$grouped_size] += $row['BRK_Qty'];
            }
            break;
    }
}
$breakagesStmt->close();

// Fetch opening balance for the current date (report generation date)
$current_date = date('Y-m-d');
$current_table = getTableForDate($conn, $compID, $current_date);

// Check if current table has columns for current day
$current_day = date('d');
if (tableHasDayColumns($conn, $current_table, $current_day)) {
    $openingQuery = "SELECT ITEM_CODE, LIQ_FLAG,
                    DAY_{$current_day}_OPEN as opening
                    FROM $current_table
                    WHERE STK_MONTH = ?";

    $openingStmt = $conn->prepare($openingQuery);
    $openingStmt->bind_param("s", $month);
    $openingStmt->execute();
    $openingResult = $openingStmt->get_result();

    while ($row = $openingResult->fetch_assoc()) {
        $item_code = $row['ITEM_CODE'];

        // Skip if item not found in master OR if item class is not allowed by license
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

        // Add opening balance based on liquor type and grouped size - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
        switch ($liquor_type) {
            case 'Spirits':
                if (in_array($grouped_size, $display_sizes_s) && $row['opening'] > 0) {
                    $monthly_data['Spirits']['opening'][$grouped_size] += $row['opening'];
                }
                break;

            case 'Imported Spirit':
                if (in_array($grouped_size, $display_sizes_imported) && $row['opening'] > 0) {
                    $monthly_data['Imported Spirit']['opening'][$grouped_size] += $row['opening'];
                }
                break;

            case 'Wines':
                if (in_array($grouped_size, $display_sizes_w) && $row['opening'] > 0) {
                    $monthly_data['Wines']['opening'][$grouped_size] += $row['opening'];
                }
                break;

            case 'Wine Imp':
                if (in_array($grouped_size, $display_sizes_wine_imp) && $row['opening'] > 0) {
                    $monthly_data['Wine Imp']['opening'][$grouped_size] += $row['opening'];
                }
                break;

            case 'Fermented Beer':
                if (in_array($grouped_size, $display_sizes_fb) && $row['opening'] > 0) {
                    $monthly_data['Fermented Beer']['opening'][$grouped_size] += $row['opening'];
                }
                break;

            case 'Mild Beer':
                if (in_array($grouped_size, $display_sizes_mb) && $row['opening'] > 0) {
                    $monthly_data['Mild Beer']['opening'][$grouped_size] += $row['opening'];
                }
                break;
        }
    }

    $openingStmt->close();
}

// Calculate closing balance using the formula: Opening + Received - Sold - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
foreach ($display_sizes_s as $size) {
    $monthly_data['Spirits']['closing'][$size] =
        $monthly_data['Spirits']['opening'][$size] +
        $monthly_data['Spirits']['received'][$size] -
        $monthly_data['Spirits']['sold'][$size];
}

foreach ($display_sizes_imported as $size) {
    $monthly_data['Imported Spirit']['closing'][$size] =
        $monthly_data['Imported Spirit']['opening'][$size] +
        $monthly_data['Imported Spirit']['received'][$size] -
        $monthly_data['Imported Spirit']['sold'][$size];
}

foreach ($display_sizes_w as $size) {
    $monthly_data['Wines']['closing'][$size] =
        $monthly_data['Wines']['opening'][$size] +
        $monthly_data['Wines']['received'][$size] -
        $monthly_data['Wines']['sold'][$size];
}

foreach ($display_sizes_wine_imp as $size) {
    $monthly_data['Wine Imp']['closing'][$size] =
        $monthly_data['Wine Imp']['opening'][$size] +
        $monthly_data['Wine Imp']['received'][$size] -
        $monthly_data['Wine Imp']['sold'][$size];
}

foreach ($display_sizes_fb as $size) {
    $monthly_data['Fermented Beer']['closing'][$size] =
        $monthly_data['Fermented Beer']['opening'][$size] +
        $monthly_data['Fermented Beer']['received'][$size] -
        $monthly_data['Fermented Beer']['sold'][$size];
}

foreach ($display_sizes_mb as $size) {
    $monthly_data['Mild Beer']['closing'][$size] =
        $monthly_data['Mild Beer']['opening'][$size] +
        $monthly_data['Mild Beer']['received'][$size] -
        $monthly_data['Mild Beer']['sold'][$size];
}

// Calculate summary in liters - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
$summary_liters = [
    'Spirits' => [
        'opening' => 0,
        'receipts' => 0,
        'sold' => 0,
        'closing' => 0
    ],
    'Imported Spirit' => [
        'opening' => 0,
        'receipts' => 0,
        'sold' => 0,
        'closing' => 0
    ],
    'Wines' => [
        'opening' => 0,
        'receipts' => 0,
        'sold' => 0,
        'closing' => 0
    ],
    'Wine Imp' => [
        'opening' => 0,
        'receipts' => 0,
        'sold' => 0,
        'closing' => 0
    ],
    'Fermented' => [
        'opening' => 0,
        'receipts' => 0,
        'sold' => 0,
        'closing' => 0
    ],
    'Mild' => [
        'opening' => 0,
        'receipts' => 0,
        'sold' => 0,
        'closing' => 0
    ]
];

// Convert Spirits data to liters - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
foreach ($display_sizes_s as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;

    $summary_liters['Spirits']['opening'] += $monthly_data['Spirits']['opening'][$size] * $liters_factor;
    $summary_liters['Spirits']['receipts'] += $monthly_data['Spirits']['received'][$size] * $liters_factor;
    $summary_liters['Spirits']['sold'] += $monthly_data['Spirits']['sold'][$size] * $liters_factor;
    $summary_liters['Spirits']['closing'] += $monthly_data['Spirits']['closing'][$size] * $liters_factor;
}

// Convert Imported Spirit data to liters
foreach ($display_sizes_imported as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;

    $summary_liters['Imported Spirit']['opening'] += $monthly_data['Imported Spirit']['opening'][$size] * $liters_factor;
    $summary_liters['Imported Spirit']['receipts'] += $monthly_data['Imported Spirit']['received'][$size] * $liters_factor;
    $summary_liters['Imported Spirit']['sold'] += $monthly_data['Imported Spirit']['sold'][$size] * $liters_factor;
    $summary_liters['Imported Spirit']['closing'] += $monthly_data['Imported Spirit']['closing'][$size] * $liters_factor;
}

// Convert Wines data to liters
foreach ($display_sizes_w as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;

    $summary_liters['Wines']['opening'] += $monthly_data['Wines']['opening'][$size] * $liters_factor;
    $summary_liters['Wines']['receipts'] += $monthly_data['Wines']['received'][$size] * $liters_factor;
    $summary_liters['Wines']['sold'] += $monthly_data['Wines']['sold'][$size] * $liters_factor;
    $summary_liters['Wines']['closing'] += $monthly_data['Wines']['closing'][$size] * $liters_factor;
}

// Convert Wine Imp data to liters
foreach ($display_sizes_wine_imp as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;

    $summary_liters['Wine Imp']['opening'] += $monthly_data['Wine Imp']['opening'][$size] * $liters_factor;
    $summary_liters['Wine Imp']['receipts'] += $monthly_data['Wine Imp']['received'][$size] * $liters_factor;
    $summary_liters['Wine Imp']['sold'] += $monthly_data['Wine Imp']['sold'][$size] * $liters_factor;
    $summary_liters['Wine Imp']['closing'] += $monthly_data['Wine Imp']['closing'][$size] * $liters_factor;
}

// Convert Fermented Beer data to liters
foreach ($display_sizes_fb as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;

    $summary_liters['Fermented']['opening'] += $monthly_data['Fermented Beer']['opening'][$size] * $liters_factor;
    $summary_liters['Fermented']['receipts'] += $monthly_data['Fermented Beer']['received'][$size] * $liters_factor;
    $summary_liters['Fermented']['sold'] += $monthly_data['Fermented Beer']['sold'][$size] * $liters_factor;
    $summary_liters['Fermented']['closing'] += $monthly_data['Fermented Beer']['closing'][$size] * $liters_factor;
}

// Convert Mild Beer data to liters
foreach ($display_sizes_mb as $size) {
    $ml = getMlFromSize($size);
    $liters_factor = $ml / 1000;

    $summary_liters['Mild']['opening'] += $monthly_data['Mild Beer']['opening'][$size] * $liters_factor;
    $summary_liters['Mild']['receipts'] += $monthly_data['Mild Beer']['received'][$size] * $liters_factor;
    $summary_liters['Mild']['sold'] += $monthly_data['Mild Beer']['sold'][$size] * $liters_factor;
    $summary_liters['Mild']['closing'] += $monthly_data['Mild Beer']['closing'][$size] * $liters_factor;
}

// Format liters to 2 decimal places
foreach ($summary_liters as $category => $data) {
    foreach ($data as $key => $value) {
        $summary_liters[$category][$key] = number_format($value, 2);
    }
}

// Calculate total columns count for table formatting - ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
$total_columns = count($display_sizes_s) + count($display_sizes_imported) + count($display_sizes_w) + count($display_sizes_wine_imp) + count($display_sizes_fb) + count($display_sizes_mb);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Monthly Register (FLR-4) - WineSoft</title>
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
    /* Double line separators after each subcategory ends */
    /* Monthly register structure: Description(1), Sizes[Spirits(11)+Imported Spirit(11)+Wine(4)+Wine Imp(4)+FB(6)+MB(6)=43] */

    /* After Spirits (50ml) - column 1+11=12 */
    .report-table td:nth-child(12),
    .report-table th:nth-child(12) {
      border-right: double 3px #000 !important;
    }
    /* After Imported Spirit (50ml) - column 12+11=23 */
    .report-table td:nth-child(23),
    .report-table th:nth-child(23) {
      border-right: double 3px #000 !important;
    }
    /* After Wine (90ml) - column 23+4=27 */
    .report-table td:nth-child(27),
    .report-table th:nth-child(27) {
      border-right: double 3px #000 !important;
    }
    /* After Wine Imp (90ml) - column 27+4=31 */
    .report-table td:nth-child(31),
    .report-table th:nth-child(31) {
      border-right: double 3px #000 !important;
    }
    /* After Fermented Beer (250ml) - column 31+6=37 */
    .report-table td:nth-child(37),
    .report-table th:nth-child(37) {
      border-right: double 3px #000 !important;
    }
    /* After Mild Beer (250ml) - column 37+6=43 */
    .report-table td:nth-child(43),
    .report-table th:nth-child(43) {
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
    .license-info { /* ADDED: Style for license info */
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
      <h3 class="mb-4">Monthly Register (FLR-4)</h3>

      <!-- ADDED: License Restriction Info -->
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
                <label class="form-label">Month:</label>
                <select name="month" class="form-control">
                  <?php
                  $months = [
                    '01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
                    '05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
                    '09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
                  ];
                  foreach ($months as $key => $name) {
                    $selected = ($month == date('Y') . '-' . $key) ? 'selected' : '';
                    echo "<option value=\"" . date('Y') . "-$key\" $selected>$name</option>";
                  }
                  ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Year:</label>
                <select name="year" class="form-control">
                  <?php
                  $current_year = date('Y');
                  for ($y = $current_year - 5; $y <= $current_year + 1; $y++) {
                    $selected = ($year == $y) ? 'selected' : '';
                    echo "<option value=\"$y\" $selected>$y</option>";
                  }
                  ?>
                </select>
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
          <h5>License to <?= htmlspecialchars($companyName) ?> (<?= $year ?> - <?= $year + 1 ?>), Pune.</h5>
          <h5>[Monthly Register]</h5>
          <h6><?= htmlspecialchars($companyName) ?></h6>
          <h6>LICENCE NO. :- <?= htmlspecialchars($licenseNo) ?> | </h6>
          <h6>Month: <?= date('F Y', strtotime($month . '-01')) ?></h6>
          <!-- ADDED: License info in print view -->
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
        </div>
        
        <div class="table-responsive">
          <table class="report-table">
            <thead>
              <tr>
                <th rowspan="3" class="description-col">Description</th>
                <th colspan="<?= count($display_sizes_s) ?>">SPIRITS</th>
                <th colspan="<?= count($display_sizes_imported) ?>">IMPORTED SPIRIT</th>
                <th colspan="<?= count($display_sizes_w) ?>">WINE</th>
                <th colspan="<?= count($display_sizes_wine_imp) ?>">WINE IMP</th>
                <th colspan="<?= count($display_sizes_fb) ?>">FERMENTED BEER</th>
                <th colspan="<?= count($display_sizes_mb) ?>">MILD BEER</th>
              </tr>
              <tr>
                <!-- Spirits Size Columns -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Imported Spirit Size Columns -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wines Size Columns -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wine Imp Size Columns -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
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
              <tr>
                <!-- SOL D IND. OF BOTTLES (in min.) header -->
                <th colspan="<?= $total_columns ?>">SOL D IND. OF BOTTLES (in min.)</th>
              </tr>
            </thead>
            <tbody>
              <!-- Opening Balance -->
              <tr>
                <td class="description-col">Opening Balance of the Beginning of the Month :-</td>
                
                <!-- Spirits Opening -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['opening'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Imported Spirit Opening -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <td><?= $monthly_data['Imported Spirit']['opening'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wines Opening -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['opening'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine Imp Opening -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
                  <td><?= $monthly_data['Wine Imp']['opening'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer Opening -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer Opening -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['opening'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Received during the Current Month -->
              <tr>
                <td class="description-col">Received during the Current Month :-</td>
                
                <!-- Spirits Received -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['received'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Imported Spirit Received -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <td><?= $monthly_data['Imported Spirit']['received'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wines Received -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['received'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine Imp Received -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
                  <td><?= $monthly_data['Wine Imp']['received'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer Received -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['received'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer Received -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['received'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Sold during the Current Month -->
              <tr>
                <td class="description-col">Sold during the Current Month :-</td>
                
                <!-- Spirits Sold -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['sold'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Imported Spirit Sold -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <td><?= $monthly_data['Imported Spirit']['sold'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['sold'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine Imp Sold -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
                  <td><?= $monthly_data['Wine Imp']['sold'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer Sold -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['sold'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer Sold -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['sold'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Breakages during the Current Month -->
              <tr>
                <td class="description-col">Breakages during the Current Month :-</td>
                
                <!-- Spirits Breakages -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['breakages'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Imported Spirit Breakages -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <td><?= $monthly_data['Imported Spirit']['breakages'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wines Breakages -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['breakages'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine Imp Breakages -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
                  <td><?= $monthly_data['Wine Imp']['breakages'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer Breakages -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['breakages'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer Breakages -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['breakages'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
              
              <!-- Closing Balance at the End of the Month -->
              <tr>
                <td class="description-col">Closing Balance at the End of the Month :-</td>
                
                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $monthly_data['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Imported Spirit Closing -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <td><?= $monthly_data['Imported Spirit']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $monthly_data['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine Imp Closing -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
                  <td><?= $monthly_data['Wine Imp']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $monthly_data['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $monthly_data['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
              </tr>
            </tbody>
          </table>
        </div>
        
        <!-- Summary Section in Liters -->
        <div class="table-responsive mt-3">
          <table class="report-table">
            <thead>
              <tr>
                <th colspan="5" style="text-align: center;">SUMMARY (IN LITERS)</th>
              </tr>
              <tr>
                <th></th>
                <th>SPIRITS</th>
                <th>IMPORTED SPIRIT</th>
                <th>WINE</th>
                <th>WINE IMP</th>
                <th>FERMENTED</th>
                <th>MILD</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><strong>Op. Stk. (Ltrs.)</strong></td>
                <td><?= $summary_liters['Spirits']['opening'] ?></td>
                <td><?= $summary_liters['Imported Spirit']['opening'] ?></td>
                <td><?= $summary_liters['Wines']['opening'] ?></td>
                <td><?= $summary_liters['Wine Imp']['opening'] ?></td>
                <td><?= $summary_liters['Fermented']['opening'] ?></td>
                <td><?= $summary_liters['Mild']['opening'] ?></td>
              </tr>
              <tr>
                <td><strong>Receipts (Ltrs.)</strong></td>
                <td><?= $summary_liters['Spirits']['receipts'] ?></td>
                <td><?= $summary_liters['Imported Spirit']['receipts'] ?></td>
                <td><?= $summary_liters['Wines']['receipts'] ?></td>
                <td><?= $summary_liters['Wine Imp']['receipts'] ?></td>
                <td><?= $summary_liters['Fermented']['receipts'] ?></td>
                <td><?= $summary_liters['Mild']['receipts'] ?></td>
              </tr>
              <tr>
                <td><strong>Sold (Ltrs.)</strong></td>
                <td><?= $summary_liters['Spirits']['sold'] ?></td>
                <td><?= $summary_liters['Imported Spirit']['sold'] ?></td>
                <td><?= $summary_liters['Wines']['sold'] ?></td>
                <td><?= $summary_liters['Wine Imp']['sold'] ?></td>
                <td><?= $summary_liters['Fermented']['sold'] ?></td>
                <td><?= $summary_liters['Mild']['sold'] ?></td>
              </tr>
              <tr>
                <td><strong>Cl. Stk. (Ltrs.)</strong></td>
                <td><?= $summary_liters['Spirits']['closing'] ?></td>
                <td><?= $summary_liters['Imported Spirit']['closing'] ?></td>
                <td><?= $summary_liters['Wines']['closing'] ?></td>
                <td><?= $summary_liters['Wine Imp']['closing'] ?></td>
                <td><?= $summary_liters['Fermented']['closing'] ?></td>
                <td><?= $summary_liters['Mild']['closing'] ?></td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="footer-info" style="text-align: right;">
           <p>Authorised Signature</p>
           <p>Generated on: <?= date('d/m/Y h:i A') ?> | User: <?= $_SESSION['user_name'] ?? 'System' ?></p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
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
    XLSX.utils.book_append_sheet(wb, ws, 'Monthly Register');

    // Generate Excel file and download
    var fileName = 'Monthly_Register_<?= date('Y-m-d') ?>.xlsx';
    XLSX.writeFile(wb, fileName);
}

function exportToCSV() {
    // Get the table element
    var table = document.querySelector('.report-table');

    // Convert table to worksheet
    var ws = XLSX.utils.table_to_sheet(table);

    // Generate CSV file and download
    var fileName = 'Monthly_Register_<?= date('Y-m-d') ?>.csv';
    XLSX.writeFile(ws, fileName);
}

function exportToPDF() {
    // Use html2pdf library to convert the report section to PDF
    const element = document.querySelector('.print-section');
    const opt = {
        margin: 0.5,
        filename: 'Monthly_Register_<?= date('Y-m-d') ?>.pdf',
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
</script>
</body>
</html>
<?php $conn->close(); ?>