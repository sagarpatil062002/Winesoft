<?php
session_start();

// Enable error reporting for debugging (disable in production for performance)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'purchase_summary_ajax_debug.log');

// Check if required session variables exist
if (!isset($_SESSION['CompID'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Include database connection
require_once "../config/db.php";

// Check if database connection is successful
if (!$conn) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get parameters with validation
$companyId = $_SESSION['CompID'];
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'ALL';
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

// Initialize summary structure for TP-wise data
$tpWiseSummary = [];

// Categories in order: SPIRITS, WINE, FERMENTED BEER, MILD BEER
$categorySizes = [
    'SPIRITS' => [
        '>1L',
        '1L', '750 ML', '700 ML', '650 ML', '500 ML', '375 ML', '355 ML', '330 ML',
        '275 ML', '250 ML', '200 ML', '180 ML', '170 ML', '90 ML', '60 ML', '50 ML'
    ],
    'WINE' => [
        '>1L',
        '1L W', '750 W', '700 W', '500 W', '375 W', '330 W',
        '250 W', '180 W', '100 W'
    ],
    'FERMENTED BEER' => [
        '>1L',
        '1L', '750 ML', '650 ML', '500 ML', '375 ML', '330 ML', 
        '275 ML', '250 ML', '180 ML', '90 ML', '60 ML'
    ],
    'MILD BEER' => [
        '>1L',
        '1L', '750 ML', '650 ML', '500 ML', '375 ML', '330 ML', 
        '275 ML', '250 ML', '180 ML', '90 ML', '60 ML'
    ]
];

// Class to category mapping based on tblitemmaster CLASS field
$classToCategory = [
    // SPIRITS - Whisky, Brandy, Rum, Vodka, Gin, etc.
    'W' => 'SPIRITS', // Whisky
    'D' => 'SPIRITS', // Brandy
    'R' => 'SPIRITS', // Rum
    'V' => 'SPIRITS', // Vodka
    'G' => 'SPIRITS', // Gin
    'S' => 'SPIRITS', // Scotch
    'I' => 'SPIRITS', // Imported Spirits
    'O' => 'SPIRITS', // Other Spirits
    'L' => 'SPIRITS', // Liquor
    'P' => 'SPIRITS', // Port
    'K' => 'SPIRITS', // Other spirits
    
    // WINE
    'WINE' => 'WINE',
    'WN' => 'WINE',
    'VW' => 'WINE',
    'V' => 'WINE',  // Sometimes V is used for wine
    
    // BEER
    'M' => 'MILD BEER',    // Mild Beer
    'F' => 'FERMENTED BEER', // Fermented Beer
    'B' => 'FERMENTED BEER', // Beer
    'BEER' => 'FERMENTED BEER',
    
    // Default to SPIRITS for unknown classes
    '' => 'SPIRITS',
    NULL => 'SPIRITS',
    'UNKNOWN' => 'SPIRITS'
];

// Initialize all sizes to 0 for each TP number
function initializeTPEntry($tpNo) {
    global $categorySizes;
    $entry = [
        'tp_no' => $tpNo,
        'tp_details' => [],
        'categories' => []
    ];
    
    foreach ($categorySizes as $category => $sizes) {
        $entry['categories'][$category] = [];
        foreach ($sizes as $size) {
            $entry['categories'][$category][$size] = 0;
        }
    }
    
    return $entry;
}

try {
    // Query to get purchase details with class from tblitemmaster
    $query = "
        SELECT 
            pd.ItemCode,
            pd.Size,
            pd.Cases,
            pd.Bottles,
            pd.BottlesPerCase,
            pd.ItemName,
            pd.TotBott,
            p.ID as PurchaseID,
            p.DATE as PurchaseDate,
            p.PUR_FLAG,
            COALESCE(NULLIF(TRIM(p.TPNO), ''), p.AUTO_TPNO) as TP_NO,
            COALESCE(NULLIF(TRIM(im.CLASS), ''), 'UNKNOWN') as ItemClass,
            im.DETAILS as ItemDetails,
            im.DETAILS2 as ItemDetails2
        FROM tblpurchasedetails pd
        INNER JOIN tblpurchases p ON pd.PurchaseID = p.ID
        LEFT JOIN tblitemmaster im ON TRIM(pd.ItemCode) = TRIM(im.CODE)
        WHERE p.CompID = ?
        AND p.DATE BETWEEN ? AND ?
        AND (p.TPNO IS NOT NULL OR p.AUTO_TPNO IS NOT NULL)
        AND (p.TPNO != '' OR p.AUTO_TPNO != '')
    ";
    
    // Add PUR_FLAG condition if not 'ALL'
    if ($mode !== 'ALL') {
        $query .= " AND p.PUR_FLAG = ?";
    } else {
        $query .= " AND p.PUR_FLAG IN ('F', 'T', 'P', 'C')";
    }

    $query .= " ORDER BY CAST(COALESCE(NULLIF(TRIM(p.TPNO), ''), p.AUTO_TPNO) AS UNSIGNED), COALESCE(NULLIF(TRIM(p.TPNO), ''), p.AUTO_TPNO)";

    error_log("Executing query: " . $query);
    
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    // Bind parameters based on mode
    if ($mode !== 'ALL') {
        $stmt->bind_param("iss", $companyId, $fromDate, $toDate, $mode);
    } else {
        $stmt->bind_param("iss", $companyId, $fromDate, $toDate);
    }
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    
    $result = $stmt->get_result();
    
    if (!$result) {
        throw new Exception("Get result failed: " . $stmt->error);
    }

    $processedItems = 0;
    $tpNumbers = [];
    $unclassifiedItems = [];
    $missingItems = [];
    
    while ($row = $result->fetch_assoc()) {
        // Get TP number - use AUTO_TPNO if TPNO is empty
        $tpNo = !empty(trim($row['TP_NO'] ?? '')) ? trim($row['TP_NO']) : 'UNKNOWN';
        
        if ($tpNo === 'UNKNOWN') {
            continue; // Skip if no TPNO
        }
        
        if (!in_array($tpNo, $tpNumbers)) {
            $tpNumbers[] = $tpNo;
        }
        
        // Initialize TP entry if not exists
        if (!isset($tpWiseSummary[$tpNo])) {
            $tpWiseSummary[$tpNo] = initializeTPEntry($tpNo);
            $tpWiseSummary[$tpNo]['tp_details'] = [
                'purchase_date' => $row['PurchaseDate'],
                'pur_flag' => $row['PUR_FLAG']
            ];
        }
        
        // Get ItemClass from tblitemmaster
        $itemClass = $row['ItemClass'] ?? 'UNKNOWN';
        $itemName = $row['ItemName'] ?? '';
        $itemCode = $row['ItemCode'] ?? '';
        $itemDetails = $row['ItemDetails'] ?? '';
        $itemDetails2 = $row['ItemDetails2'] ?? '';
        
        // Log item information for debugging
        error_log("Item: {$itemName}, Code: {$itemCode}, Class: '{$itemClass}', Details: '{$itemDetails}', Details2: '{$itemDetails2}'");
        
        // Check if item was found in tblitemmaster
        if ($itemClass === 'UNKNOWN') {
            $missingItems[] = [
                'item_code' => $itemCode,
                'item_name' => $itemName
            ];
            error_log("WARNING: Item not found in tblitemmaster: {$itemCode} - {$itemName}");
        }
        
        // Determine product category based on CLASS field
        $productType = 'SPIRITS'; // Default
        
        // First, check direct mapping
        if (isset($classToCategory[$itemClass])) {
            $productType = $classToCategory[$itemClass];
        } else {
            // Check for patterns in class code
            $itemClassUpper = strtoupper($itemClass);
            
            if (strpos($itemClassUpper, 'WINE') !== false || 
                strpos($itemClassUpper, 'WN') !== false ||
                strpos($itemClassUpper, 'VW') !== false) {
                $productType = 'WINE';
            } elseif (strpos($itemClassUpper, 'M') !== false) {
                $productType = 'MILD BEER';
            } elseif (strpos($itemClassUpper, 'F') !== false || 
                      strpos($itemClassUpper, 'B') !== false ||
                      strpos($itemClassUpper, 'BEER') !== false) {
                $productType = 'FERMENTED BEER';
            } else {
                // Default to SPIRITS
                $productType = 'SPIRITS';
            }
            
            $unclassifiedItems[] = [
                'item' => $itemName,
                'code' => $itemCode,
                'class' => $itemClass,
                'assigned_category' => $productType
            ];
        }
        
        // Extract volume from Size or ItemDetails2
        $size = $row['Size'] ?? '';
        $volume = extractVolumeFromSize($size, $itemDetails2, $itemName);
        
        // === FIXED: Calculate total bottles properly ===
        // Check if TotBott column has valid value
        if (isset($row['TotBott']) && $row['TotBott'] > 0) {
            $totalQty = intval($row['TotBott']);
            error_log("Using TotBott column: {$totalQty}");
        } else {
            // Calculate manually from Cases and Bottles
            $cases = floatval($row['Cases'] ?? 0);
            $bottles = intval($row['Bottles'] ?? 0);
            $bottlesPerCase = intval($row['BottlesPerCase'] ?? 12);
            
            // Handle special case where BottlesPerCase is 0 or negative
            if ($bottlesPerCase <= 0) {
                $bottlesPerCase = 1; // Default to 1 if invalid
                error_log("Warning: Invalid BottlesPerCase={$row['BottlesPerCase']}, using 1");
            }
            
            // Calculate total bottles: (cases × bottles per case) + loose bottles
            $totalQty = intval(round($cases * $bottlesPerCase)) + $bottles;
            
            // Log the calculation for debugging
            error_log("Calculated manually: Cases={$cases}, Bottles={$bottles}, BPC={$bottlesPerCase}, Total={$totalQty}");
        }
        
        // Get the column for this volume
        $volumeColumn = getVolumeColumnForCategory($volume, $productType);
        
        // Log categorization for debugging
        error_log("Categorized as: {$productType}, Volume: {$volume}, Column: {$volumeColumn}, Qty: {$totalQty}, Class: {$itemClass}");
        
        // Map the product to the correct category
        if ($volumeColumn && isset($tpWiseSummary[$tpNo]['categories'][$productType])) {
            // Check if this is a large size (>1L)
            $isLargeSize = isVolumeLargeSize($volume);
            $targetColumn = $isLargeSize ? '>1L' : $volumeColumn;
            
            if (isset($tpWiseSummary[$tpNo]['categories'][$productType][$targetColumn])) {
                $tpWiseSummary[$tpNo]['categories'][$productType][$targetColumn] += $totalQty;
                $processedItems++;
                error_log("Added to TP {$tpNo}, Category {$productType}, Size {$targetColumn}: {$totalQty}");
            } else {
                error_log("ERROR: Column not found: {$targetColumn} in category {$productType}");
            }
        } else {
            error_log("ERROR: Category not found: {$productType} or invalid volume column: {$volumeColumn}");
        }
    }
    
    // Log unclassified and missing items
    if (!empty($unclassifiedItems)) {
        error_log("Unclassified items (using pattern matching): " . json_encode($unclassifiedItems, JSON_PRETTY_PRINT));
    }
    
    if (!empty($missingItems)) {
        error_log("Items not found in tblitemmaster: " . json_encode($missingItems, JSON_PRETTY_PRINT));
    }
    
    // Sort TP numbers
    uksort($tpWiseSummary, function($a, $b) {
        // Extract numeric part
        preg_match('/\d+/', $a, $matchesA);
        preg_match('/\d+/', $b, $matchesB);
        
        $numA = $matchesA[0] ?? $a;
        $numB = $matchesB[0] ?? $b;
        
        if (is_numeric($numA) && is_numeric($numB)) {
            return $numA - $numB;
        }
        return strnatcasecmp($a, $b);
    });
    
    error_log("Processed $processedItems items into " . count($tpWiseSummary) . " TP numbers");
    error_log("Unique TP Numbers found: " . implode(', ', $tpNumbers));
    
    // Log final summary structure for debugging
    error_log("Final Summary Structure: " . json_encode($tpWiseSummary, JSON_PRETTY_PRINT));
    
    $stmt->close();

} catch (Exception $e) {
    error_log("Purchase Summary Error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($tpWiseSummary);

// Helper function to check if volume is >1L
function isVolumeLargeSize($volume) {
    return $volume > 1000;
}

// Helper function to extract volume from size field
function extractVolumeFromSize($size, $details2, $itemName) {
    // Clean inputs
    $size = trim($size ?? '');
    $details2 = trim($details2 ?? '');
    $itemName = trim($itemName ?? '');
    
    // Try DETAILS2 first (usually contains size like "330 ML")
    if (!empty($details2)) {
        // Check for liter sizes
        if (preg_match('/(\d+\.?\d*)\s*L/i', $details2, $matches)) {
            return floatval($matches[1]) * 1000; // Convert to ML
        }
        
        // Check for ML sizes
        if (preg_match('/(\d+)\s*ML/i', $details2, $matches)) {
            return intval($matches[1]);
        }
    }
    
    // Try Size column
    if (!empty($size)) {
        // Check for patterns like "90 ML-(96)" or "330 ML(12)"
        if (preg_match('/(\d+)\s*ML/i', $size, $matches)) {
            return intval($matches[1]);
        }
        
        // Check for liter sizes
        if (preg_match('/(\d+\.?\d*)\s*L/i', $size, $matches)) {
            return floatval($matches[1]) * 1000;
        }
    }
    
    // Try item name as last resort
    if (!empty($itemName)) {
        if (preg_match('/(\d+)\s*ML/i', $itemName, $matches)) {
            return intval($matches[1]);
        }
        
        if (preg_match('/(\d+\.?\d*)\s*L/i', $itemName, $matches)) {
            return floatval($matches[1]) * 1000;
        }
    }
    
    return 0;
}

// Helper function to get volume column for a category
function getVolumeColumnForCategory($volume, $category) {
    if ($volume == 0) {
        return null; // Cannot determine size
    }
    
    // For volumes > 1000 ML
    if ($volume > 1000) {
        // Check for exactly 1L (1000 ML)
        if ($volume == 1000) {
            return ($category === 'WINE') ? '1L W' : '1L';
        }
        // All other sizes > 1L go to >1L column
        return '>1L';
    }
    
    // Standard size mappings
    $standardMap = [
        750 => '750 ML',
        700 => '700 ML',
        650 => '650 ML',
        500 => '500 ML',
        375 => '375 ML',
        355 => '355 ML',
        330 => '330 ML',
        275 => '275 ML',
        250 => '250 ML',
        200 => '200 ML',
        180 => '180 ML',
        170 => '170 ML',
        90 => '90 ML',
        60 => '60 ML',
        50 => '50 ML'
    ];
    
    // Wine specific mappings
    $wineMap = [
        1000 => '1L W',
        750 => '750 W',
        700 => '700 W',
        500 => '500 W',
        375 => '375 W',
        330 => '330 W',
        250 => '250 W',
        180 => '180 W',
        100 => '100 W'
    ];
    
    if ($category === 'WINE') {
        return $wineMap[$volume] ?? null;
    } else {
        return $standardMap[$volume] ?? null;
    }
}
?>