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

// IMPROVED: Function to categorize size based on your SQL logic
function getSizeCategory($size) {
    $size = strtoupper(trim($size));
    
    // Spirits & Imported Spirit sizes
    if (preg_match('/(2000|2L|2 L)/', $size)) return '2000 ML';
    if (preg_match('/(1000|1L|1 L)/', $size)) return '1000 ML';
    if (preg_match('/(750|750ML)/', $size)) return '750 ML';
    if (preg_match('/(700|700ML)/', $size)) return '700 ML';
    if (preg_match('/(500|500ML)/', $size)) return '500 ML';
    if (preg_match('/(375|375ML)/', $size)) return '375 ML';
    if (preg_match('/(200|200ML)/', $size)) return '200 ML';
    if (preg_match('/(180|180ML)/', $size)) return '180 ML';
    if (preg_match('/(90|90ML)/', $size)) return '90 ML';
    if (preg_match('/(60|60ML)/', $size)) return '60 ML';
    if (preg_match('/(50|50ML)/', $size)) return '50 ML';
    
    // Beer sizes
    if (preg_match('/(650|650ML)/', $size)) return '650 ML';
    if (preg_match('/(330|330ML)/', $size)) return '330 ML';
    if (preg_match('/(275|275ML)/', $size)) return '275 ML';
    if (preg_match('/(250|250ML)/', $size)) return '250 ML';
    
    return 'Other';
}

// Define display sizes for each liquor type based on your SQL results
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

// Initialize report data structure
$dates = [];
$current_date = $from_date;
while (strtotime($current_date) <= strtotime($to_date)) {
    $dates[] = $current_date;
    $current_date = date('Y-m-d', strtotime($current_date . ' +1 day'));
}

// Initialize daily data structure for each date - NEW ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer
$daily_data = [];

// Initialize totals for each size column - NEW ORDER
$totals = [
    'Spirits' => [
        'purchase' => array_fill_keys($display_sizes_s, 0),
        'sales' => array_fill_keys($display_sizes_s, 0),
        'closing' => array_fill_keys($display_sizes_s, 0),
        'opening' => array_fill_keys($display_sizes_s, 0)
    ],
    'Imported Spirit' => [
        'purchase' => array_fill_keys($display_sizes_imported, 0),
        'sales' => array_fill_keys($display_sizes_imported, 0),
        'closing' => array_fill_keys($display_sizes_imported, 0),
        'opening' => array_fill_keys($display_sizes_imported, 0)
    ],
    'Wines' => [
        'purchase' => array_fill_keys($display_sizes_w, 0),
        'sales' => array_fill_keys($display_sizes_w, 0),
        'closing' => array_fill_keys($display_sizes_w, 0),
        'opening' => array_fill_keys($display_sizes_w, 0)
    ],
    'Wine Imp' => [
        'purchase' => array_fill_keys($display_sizes_wine_imp, 0),
        'sales' => array_fill_keys($display_sizes_wine_imp, 0),
        'closing' => array_fill_keys($display_sizes_wine_imp, 0),
        'opening' => array_fill_keys($display_sizes_wine_imp, 0)
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

// IMPROVED: Function to determine liquor type based on CLASS and LIQ_FLAG
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

// Process each date in the range with month-aware logic and archive table support
foreach ($dates as $date) {
    $day = date('d', strtotime($date));
    $month = date('Y-m', strtotime($date));
    $days_in_month = date('t', strtotime($date));
    
    // Get the appropriate table for this date (current or archive)
    $dailyStockTable = getTableForDate($conn, $compID, $date);
    
    // Check if this specific table has columns for this day
    $hasDayColumns = tableHasDayColumns($conn, $dailyStockTable, $day);
    
    // Skip if day exceeds month length OR table doesn't have columns for this day
    if ($day > $days_in_month || !$hasDayColumns) {
        // Initialize empty data for this date
        $daily_data[$date] = [
            'Spirits' => [
                'purchase' => array_fill_keys($display_sizes_s, 0),
                'sales' => array_fill_keys($display_sizes_s, 0),
                'closing' => array_fill_keys($display_sizes_s, 0),
                'opening' => array_fill_keys($display_sizes_s, 0)
            ],
            'Imported Spirit' => [
                'purchase' => array_fill_keys($display_sizes_imported, 0),
                'sales' => array_fill_keys($display_sizes_imported, 0),
                'closing' => array_fill_keys($display_sizes_imported, 0),
                'opening' => array_fill_keys($display_sizes_imported, 0)
            ],
            'Wines' => [
                'purchase' => array_fill_keys($display_sizes_w, 0),
                'sales' => array_fill_keys($display_sizes_w, 0),
                'closing' => array_fill_keys($display_sizes_w, 0),
                'opening' => array_fill_keys($display_sizes_w, 0)
            ],
            'Wine Imp' => [
                'purchase' => array_fill_keys($display_sizes_wine_imp, 0),
                'sales' => array_fill_keys($display_sizes_wine_imp, 0),
                'closing' => array_fill_keys($display_sizes_wine_imp, 0),
                'opening' => array_fill_keys($display_sizes_wine_imp, 0)
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
        continue;
    }
    
    // Initialize daily data for this date
    $daily_data[$date] = [
        'Spirits' => [
            'purchase' => array_fill_keys($display_sizes_s, 0),
            'sales' => array_fill_keys($display_sizes_s, 0),
            'closing' => array_fill_keys($display_sizes_s, 0),
            'opening' => array_fill_keys($display_sizes_s, 0)
        ],
        'Imported Spirit' => [
            'purchase' => array_fill_keys($display_sizes_imported, 0),
            'sales' => array_fill_keys($display_sizes_imported, 0),
            'closing' => array_fill_keys($display_sizes_imported, 0),
            'opening' => array_fill_keys($display_sizes_imported, 0)
        ],
        'Wines' => [
            'purchase' => array_fill_keys($display_sizes_w, 0),
            'sales' => array_fill_keys($display_sizes_w, 0),
            'closing' => array_fill_keys($display_sizes_w, 0),
            'opening' => array_fill_keys($display_sizes_w, 0)
        ],
        'Wine Imp' => [
            'purchase' => array_fill_keys($display_sizes_wine_imp, 0),
            'sales' => array_fill_keys($display_sizes_wine_imp, 0),
            'closing' => array_fill_keys($display_sizes_wine_imp, 0),
            'opening' => array_fill_keys($display_sizes_wine_imp, 0)
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
        
        // Skip if item not found in master OR if item class is not allowed by license
        if (!isset($items[$item_code])) continue;
        
        $item_details = $items[$item_code];
        $size = $item_details['DETAILS2'];
        $class = $item_details['CLASS'];
        $liq_flag = $item_details['LIQ_FLAG'];
        
        // Determine liquor type
        $liquor_type = getLiquorType($class, $liq_flag, $classData[$class]['DESC'] ?? '');
        
        // IMPROVED: Get size category using the same logic as your SQL
        $size_category = getSizeCategory($size);
        
        // Add to daily data and totals based on liquor type and size category
        switch ($liquor_type) {
            case 'Spirits':
                if (in_array($size_category, $display_sizes_s)) {
                    $daily_data[$date]['Spirits']['purchase'][$size_category] += $row['purchase'];
                    $daily_data[$date]['Spirits']['sales'][$size_category] += $row['sales'];
                    $daily_data[$date]['Spirits']['closing'][$size_category] += $row['closing'];
                    $daily_data[$date]['Spirits']['opening'][$size_category] += $row['opening'];

                    $totals['Spirits']['purchase'][$size_category] += $row['purchase'];
                    $totals['Spirits']['sales'][$size_category] += $row['sales'];
                    $totals['Spirits']['closing'][$size_category] += $row['closing'];
                    $totals['Spirits']['opening'][$size_category] += $row['opening'];
                }
                break;

            case 'Imported Spirit':
                if (in_array($size_category, $display_sizes_imported)) {
                    $daily_data[$date]['Imported Spirit']['purchase'][$size_category] += $row['purchase'];
                    $daily_data[$date]['Imported Spirit']['sales'][$size_category] += $row['sales'];
                    $daily_data[$date]['Imported Spirit']['closing'][$size_category] += $row['closing'];
                    $daily_data[$date]['Imported Spirit']['opening'][$size_category] += $row['opening'];

                    $totals['Imported Spirit']['purchase'][$size_category] += $row['purchase'];
                    $totals['Imported Spirit']['sales'][$size_category] += $row['sales'];
                    $totals['Imported Spirit']['closing'][$size_category] += $row['closing'];
                    $totals['Imported Spirit']['opening'][$size_category] += $row['opening'];
                }
                break;

            case 'Wines':
                if (in_array($size_category, $display_sizes_w)) {
                    $daily_data[$date]['Wines']['purchase'][$size_category] += $row['purchase'];
                    $daily_data[$date]['Wines']['sales'][$size_category] += $row['sales'];
                    $daily_data[$date]['Wines']['closing'][$size_category] += $row['closing'];
                    $daily_data[$date]['Wines']['opening'][$size_category] += $row['opening'];

                    $totals['Wines']['purchase'][$size_category] += $row['purchase'];
                    $totals['Wines']['sales'][$size_category] += $row['sales'];
                    $totals['Wines']['closing'][$size_category] += $row['closing'];
                    $totals['Wines']['opening'][$size_category] += $row['opening'];
                }
                break;

            case 'Wine Imp':
                if (in_array($size_category, $display_sizes_wine_imp)) {
                    $daily_data[$date]['Wine Imp']['purchase'][$size_category] += $row['purchase'];
                    $daily_data[$date]['Wine Imp']['sales'][$size_category] += $row['sales'];
                    $daily_data[$date]['Wine Imp']['closing'][$size_category] += $row['closing'];
                    $daily_data[$date]['Wine Imp']['opening'][$size_category] += $row['opening'];

                    $totals['Wine Imp']['purchase'][$size_category] += $row['purchase'];
                    $totals['Wine Imp']['sales'][$size_category] += $row['sales'];
                    $totals['Wine Imp']['closing'][$size_category] += $row['closing'];
                    $totals['Wine Imp']['opening'][$size_category] += $row['opening'];
                }
                break;

            case 'Fermented Beer':
                if (in_array($size_category, $display_sizes_fb)) {
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
                if (in_array($size_category, $display_sizes_mb)) {
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

// Calculate total columns count for table formatting
$total_columns = count($display_sizes_s) + count($display_sizes_imported) + count($display_sizes_w) + count($display_sizes_wine_imp) + count($display_sizes_fb) + count($display_sizes_mb);
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
    /* Double line separators after each subcategory ends and after category changes */
    /* FLR Datewise structure: Date(1), Permit(2), Received[Spirits(11)+Imported Spirit(11)+Wine(4)+Wine Imp(4)+FB(6)+MB(6)=42], Sold[42], Closing[42] */

    /* After Spirits (50ml) in Received section - column 2+11=13 */
    .report-table td:nth-child(13),
    .report-table th:nth-child(13) {
      border-right: double 3px #000 !important;
    }

    /* After Imported Spirit (50ml) in Received section - column 13+11=24 */
    .report-table td:nth-child(24),
    .report-table th:nth-child(24) {
      border-right: double 3px #000 !important;
    }

    /* After Wine (90ml) in Received section - column 24+4=28 */
    .report-table td:nth-child(28),
    .report-table th:nth-child(28) {
      border-right: double 3px #000 !important;
    }

    /* After Wine Imp (90ml) in Received section - column 28+4=32 */
    .report-table td:nth-child(32),
    .report-table th:nth-child(32) {
      border-right: double 3px #000 !important;
    }

    /* After Fermented Beer (250ml) in Received section - column 32+6=38 */
    .report-table td:nth-child(38),
    .report-table th:nth-child(38) {
      border-right: double 3px #000 !important;
    }

    /* After Mild Beer (250ml) in Received section - column 38+6=44 */
    .report-table td:nth-child(44),
    .report-table th:nth-child(44) {
      border-right: double 3px #000 !important;
    }

    /* After Received section (before Sold) - column 44 */
    .report-table td:nth-child(44),
    .report-table th:nth-child(44) {
      border-right: double 3px #000 !important;
    }

    /* After Spirits (50ml) in Sold section - column 44+11=55 */
    .report-table td:nth-child(55),
    .report-table th:nth-child(55) {
      border-right: double 3px #000 !important;
    }

    /* After Imported Spirit (50ml) in Sold section - column 55+11=66 */
    .report-table td:nth-child(66),
    .report-table th:nth-child(66) {
      border-right: double 3px #000 !important;
    }

    /* After Wine (90ml) in Sold section - column 66+4=70 */
    .report-table td:nth-child(70),
    .report-table th:nth-child(70) {
      border-right: double 3px #000 !important;
    }

    /* After Wine Imp (90ml) in Sold section - column 70+4=74 */
    .report-table td:nth-child(74),
    .report-table th:nth-child(74) {
      border-right: double 3px #000 !important;
    }

    /* After Fermented Beer (250ml) in Sold section - column 74+6=80 */
    .report-table td:nth-child(80),
    .report-table th:nth-child(80) {
      border-right: double 3px #000 !important;
    }

    /* After Mild Beer (250ml) in Sold section - column 80+6=86 */
    .report-table td:nth-child(86),
    .report-table th:nth-child(86) {
      border-right: double 3px #000 !important;
    }

    /* After Sold section (before Closing) - column 86 */
    .report-table td:nth-child(86),
    .report-table th:nth-child(86) {
      border-right: double 3px #000 !important;
    }

    /* After Spirits (50ml) in Closing section - column 86+11=97 */
    .report-table td:nth-child(97),
    .report-table th:nth-child(97) {
      border-right: double 3px #000 !important;
    }

    /* After Imported Spirit (50ml) in Closing section - column 97+11=108 */
    .report-table td:nth-child(108),
    .report-table th:nth-child(108) {
      border-right: double 3px #000 !important;
    }

    /* After Wine (90ml) in Closing section - column 108+4=112 */
    .report-table td:nth-child(112),
    .report-table th:nth-child(112) {
      border-right: double 3px #000 !important;
    }

    /* After Wine Imp (90ml) in Closing section - column 112+4=116 */
    .report-table td:nth-child(116),
    .report-table th:nth-child(116) {
      border-right: double 3px #000 !important;
    }

    /* After Fermented Beer (250ml) in Closing section - column 116+6=122 */
    .report-table td:nth-child(122),
    .report-table th:nth-child(122) {
      border-right: double 3px #000 !important;
    }

    /* After Mild Beer (250ml) in Closing section - column 122+6=128 */
    .report-table td:nth-child(128),
    .report-table th:nth-child(128) {
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

  /* Print styles - ONLY MODIFIED HEIGHT AND SCALE */
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
        height: 14px; /* KEEP AS BEFORE */
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
      
      /* ONLY INCREASE HEIGHT FOR SIZE COLUMNS */
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
        height: 25px !important; /* INCREASED HEIGHT */
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
      <h3 class="mb-4">FLR 1A/2A/3A Datewise Register</h3>

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
          <!-- ADDED: License info in print view -->
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
                <!-- NEW ORDER: Spirit, Imported Spirit, Wine, Wine Imp, Fermented Beer, Mild Beer -->
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_imported) ?>">Imported Spirit</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wine</th>
                <th colspan="<?= count($display_sizes_wine_imp) ?>">Wine Imp</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_imported) ?>">Imported Spirit</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wine</th>
                <th colspan="<?= count($display_sizes_wine_imp) ?>">Wine Imp</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
                <th colspan="<?= count($display_sizes_s) ?>">Spirits</th>
                <th colspan="<?= count($display_sizes_imported) ?>">Imported Spirit</th>
                <th colspan="<?= count($display_sizes_w) ?>">Wine</th>
                <th colspan="<?= count($display_sizes_wine_imp) ?>">Wine Imp</th>
                <th colspan="<?= count($display_sizes_fb) ?>">Fermented Beer</th>
                <th colspan="<?= count($display_sizes_mb) ?>">Mild Beer</th>
              </tr>
              <tr>
                <!-- Spirits Received - Vertical text for print -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Imported Spirit Received -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wines Received -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wine Imp Received -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
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

                <!-- Imported Spirit Sold -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wines Sold -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wine Imp Sold -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
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

                <!-- Spirits Closing -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Imported Spirit Closing -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wines Closing -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Wine Imp Closing -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Fermented Beer Closing -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>

                <!-- Mild Beer Closing -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <th class="size-col vertical-text"><?= $size ?></th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dates as $date): ?>
                <tr>
                  <td class="date-col"><?= date('d-M', strtotime($date)) ?></td>
                  <td class="permit-col"></td>
                  
                  <!-- Spirits Received -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $daily_data[$date]['Spirits']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Imported Spirit Received -->
                  <?php foreach ($display_sizes_imported as $size): ?>
                    <td><?= $daily_data[$date]['Imported Spirit']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Wines Received -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $daily_data[$date]['Wines']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Wine Imp Received -->
                  <?php foreach ($display_sizes_wine_imp as $size): ?>
                    <td><?= $daily_data[$date]['Wine Imp']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Fermented Beer Received -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $daily_data[$date]['Fermented Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Mild Beer Received -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $daily_data[$date]['Mild Beer']['purchase'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Spirits Sold -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $daily_data[$date]['Spirits']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Imported Spirit Sold -->
                  <?php foreach ($display_sizes_imported as $size): ?>
                    <td><?= $daily_data[$date]['Imported Spirit']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Wines Sold -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $daily_data[$date]['Wines']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Wine Imp Sold -->
                  <?php foreach ($display_sizes_wine_imp as $size): ?>
                    <td><?= $daily_data[$date]['Wine Imp']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Fermented Beer Sold -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $daily_data[$date]['Fermented Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Mild Beer Sold -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $daily_data[$date]['Mild Beer']['sales'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Spirits Closing -->
                  <?php foreach ($display_sizes_s as $size): ?>
                    <td><?= $daily_data[$date]['Spirits']['closing'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Imported Spirit Closing -->
                  <?php foreach ($display_sizes_imported as $size): ?>
                    <td><?= $daily_data[$date]['Imported Spirit']['closing'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Wines Closing -->
                  <?php foreach ($display_sizes_w as $size): ?>
                    <td><?= $daily_data[$date]['Wines']['closing'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Wine Imp Closing -->
                  <?php foreach ($display_sizes_wine_imp as $size): ?>
                    <td><?= $daily_data[$date]['Wine Imp']['closing'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Fermented Beer Closing -->
                  <?php foreach ($display_sizes_fb as $size): ?>
                    <td><?= $daily_data[$date]['Fermented Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>

                  <!-- Mild Beer Closing -->
                  <?php foreach ($display_sizes_mb as $size): ?>
                    <td><?= $daily_data[$date]['Mild Beer']['closing'][$size] ?></td>
                  <?php endforeach; ?>
                  
                  <td class="signature-col"></td>
                </tr>
              <?php endforeach; ?>
              
              <!-- Summary Row -->
              <tr class="summary-row">
                <td colspan="2">Total</td>
                
                <!-- Spirits Received Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Imported Spirit Received Total -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <td><?= $totals['Imported Spirit']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wines Received Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine Imp Received Total -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
                  <td><?= $totals['Wine Imp']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer Received Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer Received Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['purchase'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Spirits Sold Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Imported Spirit Sold Total -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <td><?= $totals['Imported Spirit']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wines Sold Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine Imp Sold Total -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
                  <td><?= $totals['Wine Imp']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer Sold Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer Sold Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['sales'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Spirits Closing Total -->
                <?php foreach ($display_sizes_s as $size): ?>
                  <td><?= $totals['Spirits']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Imported Spirit Closing Total -->
                <?php foreach ($display_sizes_imported as $size): ?>
                  <td><?= $totals['Imported Spirit']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wines Closing Total -->
                <?php foreach ($display_sizes_w as $size): ?>
                  <td><?= $totals['Wines']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Wine Imp Closing Total -->
                <?php foreach ($display_sizes_wine_imp as $size): ?>
                  <td><?= $totals['Wine Imp']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Fermented Beer Closing Total -->
                <?php foreach ($display_sizes_fb as $size): ?>
                  <td><?= $totals['Fermented Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>

                <!-- Mild Beer Closing Total -->
                <?php foreach ($display_sizes_mb as $size): ?>
                  <td><?= $totals['Mild Beer']['closing'][$size] ?></td>
                <?php endforeach; ?>
                
                <td></td>
              </tr>
              <!-- Summary rows - Updated for new order and only in Closing Balance column -->
<tr class="summary-row">
  <td>Received</td>
  <td></td>

  <!-- Received Section - Empty for Received summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Sold Section - Empty for Received summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Closing Balance Section - Show purchase totals -->
  <!-- Spirits Closing -->
  <?php foreach ($display_sizes_s as $size): ?>
    <td><?= $totals['Spirits']['purchase'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Imported Spirit Closing -->
  <?php foreach ($display_sizes_imported as $size): ?>
    <td><?= $totals['Imported Spirit']['purchase'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wines Closing -->
  <?php foreach ($display_sizes_w as $size): ?>
    <td><?= $totals['Wines']['purchase'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wine Imp Closing -->
  <?php foreach ($display_sizes_wine_imp as $size): ?>
    <td><?= $totals['Wine Imp']['purchase'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Fermented Beer Closing -->
  <?php foreach ($display_sizes_fb as $size): ?>
    <td><?= $totals['Fermented Beer']['purchase'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Mild Beer Closing -->
  <?php foreach ($display_sizes_mb as $size): ?>
    <td><?= $totals['Mild Beer']['purchase'][$size] ?></td>
  <?php endforeach; ?>

  <td>Received</td>
</tr>

<tr class="summary-row">
  <td>Opening Balance</td>
  <td></td>

  <!-- Received Section - Empty for Opening Balance summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Sold Section - Empty for Opening Balance summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Closing Balance Section - Show opening totals -->
  <!-- Spirits Opening -->
  <?php foreach ($display_sizes_s as $size): ?>
    <td><?= $totals['Spirits']['opening'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Imported Spirit Opening -->
  <?php foreach ($display_sizes_imported as $size): ?>
    <td><?= $totals['Imported Spirit']['opening'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wines Opening -->
  <?php foreach ($display_sizes_w as $size): ?>
    <td><?= $totals['Wines']['opening'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wine Imp Opening -->
  <?php foreach ($display_sizes_wine_imp as $size): ?>
    <td><?= $totals['Wine Imp']['opening'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Fermented Beer Opening -->
  <?php foreach ($display_sizes_fb as $size): ?>
    <td><?= $totals['Fermented Beer']['opening'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Mild Beer Opening -->
  <?php foreach ($display_sizes_mb as $size): ?>
    <td><?= $totals['Mild Beer']['opening'][$size] ?></td>
  <?php endforeach; ?>

  <td>Opening Balance</td>
</tr>

<tr class="summary-row">
  <td>Grand Total</td>
  <td></td>

  <!-- Received Section - Empty for Grand Total summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Sold Section - Empty for Grand Total summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Closing Balance Section - Show closing balance of last date -->
  <?php $last_date = end($dates); ?>
  <!-- Spirits Closing -->
  <?php foreach ($display_sizes_s as $size): ?>
    <td><?= $daily_data[$last_date]['Spirits']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Imported Spirit Closing -->
  <?php foreach ($display_sizes_imported as $size): ?>
    <td><?= $daily_data[$last_date]['Imported Spirit']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wines Closing -->
  <?php foreach ($display_sizes_w as $size): ?>
    <td><?= $daily_data[$last_date]['Wines']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wine Imp Closing -->
  <?php foreach ($display_sizes_wine_imp as $size): ?>
    <td><?= $daily_data[$last_date]['Wine Imp']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Fermented Beer Closing -->
  <?php foreach ($display_sizes_fb as $size): ?>
    <td><?= $daily_data[$last_date]['Fermented Beer']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Mild Beer Closing -->
  <?php foreach ($display_sizes_mb as $size): ?>
    <td><?= $daily_data[$last_date]['Mild Beer']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <td>Grand Total</td>
</tr>

<tr class="summary-row">
  <td>Sold</td>
  <td></td>

  <!-- Received Section - Empty for Sold summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Sold Section - Empty for Sold summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Closing Balance Section - Show sales totals -->
  <!-- Spirits Sold -->
  <?php foreach ($display_sizes_s as $size): ?>
    <td><?= $totals['Spirits']['sales'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Imported Spirit Sold -->
  <?php foreach ($display_sizes_imported as $size): ?>
    <td><?= $totals['Imported Spirit']['sales'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wines Sold -->
  <?php foreach ($display_sizes_w as $size): ?>
    <td><?= $totals['Wines']['sales'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wine Imp Sold -->
  <?php foreach ($display_sizes_wine_imp as $size): ?>
    <td><?= $totals['Wine Imp']['sales'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Fermented Beer Sold -->
  <?php foreach ($display_sizes_fb as $size): ?>
    <td><?= $totals['Fermented Beer']['sales'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Mild Beer Sold -->
  <?php foreach ($display_sizes_mb as $size): ?>
    <td><?= $totals['Mild Beer']['sales'][$size] ?></td>
  <?php endforeach; ?>

  <td>Sold</td>
</tr>

<tr class="summary-row">
  <td>Closing Balance</td>
  <td></td>

  <!-- Received Section - Empty for Closing Balance summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Sold Section - Empty for Closing Balance summary -->
  <?php for ($i = 0; $i < $total_columns; $i++): ?>
    <td></td>
  <?php endfor; ?>

  <!-- Closing Balance Section - Show closing balance of last date -->
  <?php $last_date = end($dates); ?>
  <!-- Spirits Closing -->
  <?php foreach ($display_sizes_s as $size): ?>
    <td><?= $daily_data[$last_date]['Spirits']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Imported Spirit Closing -->
  <?php foreach ($display_sizes_imported as $size): ?>
    <td><?= $daily_data[$last_date]['Imported Spirit']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wines Closing -->
  <?php foreach ($display_sizes_w as $size): ?>
    <td><?= $daily_data[$last_date]['Wines']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Wine Imp Closing -->
  <?php foreach ($display_sizes_wine_imp as $size): ?>
    <td><?= $daily_data[$last_date]['Wine Imp']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Fermented Beer Closing -->
  <?php foreach ($display_sizes_fb as $size): ?>
    <td><?= $daily_data[$last_date]['Fermented Beer']['closing'][$size] ?></td>
  <?php endforeach; ?>

  <!-- Mild Beer Closing -->
  <?php foreach ($display_sizes_mb as $size): ?>
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
