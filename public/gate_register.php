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

/**
 * Group sizes - volumes above 1000ml are grouped together
 */
function getGroupedSizeLabel($volume) {
    if ($volume >= 1000) {
        return 'ABOVE 1000 ML';
    }
    
    // Format volume based on size
    if ($volume >= 1000) {
        $liters = $volume / 1000;
        if ($liters == intval($liters)) {
            return intval($liters) . 'L';
        } else {
            return rtrim(rtrim(number_format($liters, 1), '0'), '.') . 'L';
        }
    } else {
        return $volume . ' ML';
    }
}

/**
 * Get volume label (maintains original for exact matching)
 */
function getVolumeLabel($volume) {
    static $volume_label_cache = [];
    
    if (isset($volume_label_cache[$volume])) {
        return $volume_label_cache[$volume];
    }
    
    // Format volume based on size
    if ($volume >= 1000) {
        $liters = $volume / 1000;
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
$selected_date = isset($_GET['selected_date']) ? $_GET['selected_date'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

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

// Set register name
$registerName = "FLR-5 Gate Register";

// Define display categories based on mode
if ($mode == 'Country Liquor') {
    $display_categories = ['COUNTRY LIQUOR'];
    $category_display_names = ['COUNTRY LIQUOR' => 'COUNTRY LIQUOR'];
} else {
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

// Define size columns - include ABOVE 1000 ML as a grouped column
$size_columns_def = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
    'ABOVE 1000 ML' // Grouped column for all sizes above 1000ml
];

// All categories use the same size columns with grouped above 1000ml
$size_columns = [];
foreach ($display_categories as $category) {
    $size_columns[$category] = $size_columns_def;
}

// Fetch item master data with hierarchy information
$items = [];
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    
    if ($mode == 'Country Liquor') {
        $itemQuery = "SELECT CODE, DETAILS, DETAILS2, CLASS, CLASS_CODE_NEW, SUBCLASS_CODE_NEW, SIZE_CODE, LIQ_FLAG 
                      FROM tblitemmaster 
                      WHERE CLASS IN ($class_placeholders) AND LIQ_FLAG = 'C'";
    } else {
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

// Initialize liquor summary based on categories
$liquor_summary = [];
foreach ($display_categories as $category) {
    $liquor_summary[$category] = array_fill_keys($size_columns_def, 0);
}

// Fetch gate register data from tbl_cash_memo_prints
$gate_data = [];
$total_amount = 0;

$gateQuery = "SELECT
                cmp.bill_date,
                cmp.bill_no,
                cmp.customer_name,
                cmp.permit_no,
                cmp.permit_place,
                cmp.permit_exp_date,
                cmp.items_json,
                cmp.total_amount,
                tp.DETAILS as permit_holder_name,
                tp.P_ISSDT as permit_issue_date,
                tp.P_EXP_DT as permit_expiry_date,
                tp.PLACE_ISS as permit_issue_place,
                tp.LIQ_FLAG as permit_liq_flag
              FROM tbl_cash_memo_prints cmp
              LEFT JOIN tblpermit tp ON cmp.permit_no = tp.P_NO
              WHERE cmp.comp_id = ?
              AND cmp.bill_date = ?";

if ($mode == 'Country Liquor') {
    $gateQuery .= " AND (tp.LIQ_FLAG = 'C' OR tp.LIQ_FLAG IS NULL)";
} else {
    $gateQuery .= " AND (tp.LIQ_FLAG = 'F' OR tp.LIQ_FLAG IS NULL)";
}

$gateQuery .= " ORDER BY cmp.bill_no";

$gateStmt = $conn->prepare($gateQuery);
$gateStmt->bind_param("is", $compID, $selected_date);
$gateStmt->execute();
$gateResult = $gateStmt->get_result();

$serial_no = 1;

while ($row = $gateResult->fetch_assoc()) {
    $total_amount += $row['total_amount'];

    // Use permit holder name from tblpermit if available
    $permit_holder_name = $row['permit_holder_name'] ?: $row['customer_name'];

    // Format permit validity
    $permit_validity = '';
    if ($row['permit_expiry_date']) {
        $permit_validity = date('d/m/Y', strtotime($row['permit_expiry_date']));
    } elseif ($row['permit_exp_date']) {
        $permit_validity = date('d/m/Y', strtotime($row['permit_exp_date']));
    }

    // Get permit district
    $permit_district = $row['permit_issue_place'] ?: $row['permit_place'] ?: 'N/A';

    // Process items for this entry
    $entry_summary = [];
    foreach ($display_categories as $category) {
        $entry_summary[$category] = array_fill_keys($size_columns_def, 0);
    }
    
    $items_json = $row['items_json'];
    $bill_items = json_decode($items_json, true);
    
    if (is_array($bill_items)) {
        foreach ($bill_items as $item) {
            // Try to find item by code first
            $item_code = $item['CODE'] ?? '';
            $item_found = false;
            
            if (isset($items[$item_code])) {
                $item_found = true;
                $hierarchy = $items[$item_code]['hierarchy'];
                $display_type = $hierarchy['display_type'];
                $ml_volume = $hierarchy['ml_volume'];
            } else {
                // If item not found in master, try to determine from DETAILS
                // This is a fallback - you might want to adjust based on your data structure
                $details = $item['DETAILS'] ?? '';
                $details2 = $item['DETAILS2'] ?? '';
                
                // Default values
                $display_type = 'IMFL'; // Default
                $ml_volume = 0;
                
                // Try to extract volume from DETAILS2
                if (preg_match('/(\d+)\s*ML/i', $details2, $matches)) {
                    $ml_volume = intval($matches[1]);
                }
                
                // Determine type from item name
                $item_name = strtolower($details);
                if (strpos($item_name, 'beer') !== false) {
                    if (strpos($item_name, 'mild') !== false) {
                        $display_type = 'MILD BEER';
                    } else {
                        $display_type = 'FERMENTED BEER';
                    }
                } elseif (strpos($item_name, 'wine') !== false) {
                    if (strpos($item_name, 'imported') !== false) {
                        $display_type = 'IMPORTED WINE';
                    } else {
                        $display_type = 'INDIAN WINE';
                    }
                }
            }
            
            if ($mode == 'Country Liquor') {
                $display_type = 'COUNTRY LIQUOR';
            }
            
            if (!in_array($display_type, $display_categories)) {
                continue;
            }
            
            // Get volume and determine display size
            $ml_volume = isset($hierarchy) ? $hierarchy['ml_volume'] : $ml_volume;
            $qty = floatval($item['QTY'] ?? 0);
            
            // Determine which size column to use
            $size_key = '';
            if ($ml_volume >= 1000) {
                $size_key = 'ABOVE 1000 ML';
            } else {
                $size_key = $ml_volume . ' ML';
                // Check if this exact size exists in our columns, otherwise find closest match
                if (!in_array($size_key, $size_columns_def)) {
                    // Find closest standard size
                    $standard_sizes = [50, 60, 90, 170, 180, 200, 250, 275, 330, 355, 375, 500, 650, 700, 750, 1000];
                    $closest = 750; // Default
                    $min_diff = PHP_INT_MAX;
                    
                    foreach ($standard_sizes as $std_size) {
                        $diff = abs($ml_volume - $std_size);
                        if ($diff < $min_diff) {
                            $min_diff = $diff;
                            $closest = $std_size;
                        }
                    }
                    $size_key = $closest . ' ML';
                }
            }
            
            // Add to entry summary and overall summary
            if (isset($entry_summary[$display_type][$size_key])) {
                $entry_summary[$display_type][$size_key] += $qty;
                $liquor_summary[$display_type][$size_key] += $qty;
            }
        }
    }

    $gate_data[] = [
        'serial_no' => $serial_no++,
        'bill_no' => $row['bill_no'],
        'permit_no' => $row['permit_no'],
        'permit_holder_name' => $permit_holder_name,
        'permit_validity' => $permit_validity,
        'permit_district' => $permit_district,
        'amount' => $row['total_amount'],
        'items_json' => $row['items_json'],
        'entry_summary' => $entry_summary,
        'liq_flag' => $row['permit_liq_flag']
    ];
}
$gateStmt->close();

$total_records = count($gate_data);

// Calculate total columns count for table formatting
$total_columns = 0;
foreach ($display_categories as $category) {
    $total_columns += count($size_columns[$category]);
}

// Calculate category totals
$category_totals = [];
foreach ($display_categories as $category) {
    $category_totals[$category] = array_sum($liquor_summary[$category]);
}
$grand_total = array_sum($category_totals);

// Debug output (remove in production)
// echo "<!-- Total Records: $total_records -->";
// echo "<!-- Categories: " . implode(', ', $display_categories) . " -->";
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gate Register - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

  <style>
    /* Screen styles - matching excise register */
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
    /* Double line separators */
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
    .serial-col {
      width: 40px;
      min-width: 40px;
    }
    .billno-col {
      width: 70px;
      min-width: 70px;
    }
    .permitno-col {
      width: 80px;
      min-width: 80px;
    }
    .name-col {
      width: 150px;
      min-width: 150px;
    }
    .validity-col {
      width: 70px;
      min-width: 70px;
    }
    .district-col {
      width: 80px;
      min-width: 80px;
    }
    .category-header {
      font-weight: bold;
      background-color: #e9ecef !important;
    }
    .summary-row {
      background-color: #e9ecef;
      font-weight: bold;
    }
    .license-info {
      margin-bottom: 15px;
      padding: 8px;
      background-color: #e9ecef;
      border-left: 4px solid #0d6efd;
    }

    /* Print styles - matching excise register */
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
      
      .serial-col, .billno-col, .permitno-col, .validity-col, .district-col {
        width: 30px !important;
        min-width: 30px !important;
        max-width: 30px !important;
      }
      
      .name-col {
        width: 80px !important;
        min-width: 80px !important;
        max-width: 80px !important;
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
      <h3 class="mb-4">Gate Register Printing Module</h3>

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

      <!-- Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="filter-form">
            <div class="row">
              <div class="col-md-3">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-control">
                  <option value="Foreign Liquor" <?= $mode == 'Foreign Liquor' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="Country Liquor" <?= $mode == 'Country Liquor' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Select Date:</label>
                <input type="date" name="selected_date" class="form-control" value="<?= htmlspecialchars($selected_date) ?>" max="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Report Info:</label>
                <div class="form-control-plaintext">
                  <small class="text-muted">
                    Mode: <?= htmlspecialchars($mode) ?><br>
                    Date: <?= date('d-M-Y', strtotime($selected_date)) ?> | Records: <?= $total_records ?>
                  </small>
                </div>
              </div>
            </div>

            <div class="action-controls mt-3">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate Report
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

      <!-- Report Results -->
      <div class="print-section">
        <div class="company-header">
          <h1><?= htmlspecialchars($registerName) ?></h1>
          <h5>Mode: <?= htmlspecialchars($mode) ?></h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>License Type: <?= htmlspecialchars($license_type) ?></h6>
          <h6>Date: <?= date('d-M-Y', strtotime($selected_date)) ?></h6>
        </div>

        <?php if (empty($gate_data)): ?>
          <div class="alert alert-warning text-center no-print">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No gate register data available for the selected date and mode.
          </div>
        <?php else: ?>
          <div class="table-responsive">
            <table class="report-table" id="gate-register-table">
              <thead>
                <tr>
                  <th rowspan="2" class="serial-col">Sr No.</th>
                  <th rowspan="2" class="billno-col">Bill No.</th>
                  <th rowspan="2" class="permitno-col">Permit No.</th>
                  <th rowspan="2" class="name-col">Permit Holder Name</th>
                  <th rowspan="2" class="validity-col">Permit Validity</th>
                  <th rowspan="2" class="district-col">Permit District</th>
                  
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
                      <th class="vertical-text-full <?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>"><?= $size ?></th>
                    <?php endforeach; ?>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($gate_data as $entry): ?>
                  <tr>
                    <td class="serial-col"><?= $entry['serial_no'] ?></td>
                    <td class="billno-col"><?= htmlspecialchars($entry['bill_no']) ?></td>
                    <td class="permitno-col"><?= htmlspecialchars($entry['permit_no']) ?></td>
                    <td class="name-col text-start"><?= htmlspecialchars($entry['permit_holder_name']) ?></td>
                    <td class="validity-col"><?= htmlspecialchars($entry['permit_validity']) ?></td>
                    <td class="district-col"><?= htmlspecialchars($entry['permit_district']) ?></td>
                    
                    <?php
                    // Display entry quantities using pre-calculated entry_summary
                    foreach ($display_categories as $cat_index => $category):
                        $sizes = $size_columns[$category];
                        $last_index = count($sizes) - 1;
                        foreach ($sizes as $size_index => $size):
                            $value = isset($entry['entry_summary'][$category][$size]) ? $entry['entry_summary'][$category][$size] : 0;
                    ?>
                            <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                                <?= $value > 0 ? $value : '' ?>
                            </td>
                    <?php
                        endforeach;
                    endforeach;
                    ?>
                  </tr>
                <?php endforeach; ?>

                <!-- Summary Row -->
                <tr class="summary-row">
                  <td colspan="6" class="text-start">
                    <strong>Total Records: <?= $total_records ?> | Date: <?= date('d-M-Y', strtotime($selected_date)) ?></strong>
                  </td>
                  <?php 
                  foreach ($display_categories as $cat_index => $category):
                      $sizes = $size_columns[$category];
                      $last_index = count($sizes) - 1;
                      foreach ($sizes as $size_index => $size):
                          $value = isset($liquor_summary[$category][$size]) ? $liquor_summary[$category][$size] : 0;
                  ?>
                          <td class="<?= ($size_index == $last_index && $cat_index < count($display_categories) - 1) ? 'double-line-right' : '' ?>">
                              <?= $value > 0 ? $value : '' ?>
                          </td>
                  <?php
                      endforeach;
                  endforeach;
                  ?>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="footer-info">
            <p>Generated on: <?= date('d-M-Y h:i A') ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>