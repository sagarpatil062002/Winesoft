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
// Sizes ordered from SMALL to LARGE as requested by user
// Note: Wine sizes use "ML" suffix for display but internal data uses "W" suffix
$categorySizes = [
    'SPIRITS' => [
        '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
        '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1L', '>1L'
    ],
    'WINE' => [
        '100 ML', '180 ML', '250 ML', '330 ML', '375 ML', '500 ML', '700 ML', '750 ML', '1L', '>1L'
    ],
    'FERMENTED BEER' => [
        '60 ML', '90 ML', '180 ML', '250 ML', '275 ML', '330 ML', '375 ML', '500 ML', '650 ML', '750 ML', '1L', '>1L'
    ],
    'MILD BEER' => [
        '60 ML', '90 ML', '180 ML', '250 ML', '275 ML', '330 ML', '375 ML', '500 ML', '650 ML', '750 ML', '1L', '>1L'
    ]
];

// Wine internal size mapping (for data lookup)
$wineInternalSizes = [
    '100 ML' => '100 W',
    '180 ML' => '180 W',
    '250 ML' => '250 W',
    '330 ML' => '330 W',
    '375 ML' => '375 W',
    '500 ML' => '500 W',
    '700 ML' => '700 W',
    '750 ML' => '750 W',
    '1L' => '1L W',
    '>1L' => '>1L'
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
    // Query to get purchase details with new schema - using tblsize, tblclass_new, tblsubclass_new
    $query = "
        SELECT 
            pd.ItemCode,
            pd.Size as PurchaseSize,
            pd.Cases,
            pd.Bottles,
            pd.BottlesPerCase,
            pd.ItemName,
            pd.TotBott,
            p.ID as PurchaseID,
            p.DATE as PurchaseDate,
            p.PUR_FLAG,
            COALESCE(NULLIF(TRIM(p.TPNO), ''), p.AUTO_TPNO) as TP_NO,
            -- Get size information from tblsize
            sz.ML_VOLUME,
            sz.SIZE_DESC as SizeDescription,
            sz.BOTTLE_PER_CASE as SizeBottlesPerCase,
            -- Get class and category information from new tables
            cn.CLASS_NAME,
            cn.CATEGORY_CODE as ClassCategoryCode,
            sn.SUBCLASS_NAME,
            cat.CATEGORY_NAME,
            -- Also get old fields for fallback
            im.CLASS as OldClass,
            im.CLASS_CODE_NEW,
            im.SUBCLASS_CODE_NEW,
            im.SIZE_CODE,
            im.ITEM_GROUP
        FROM tblpurchasedetails pd
        INNER JOIN tblpurchases p ON pd.PurchaseID = p.ID
        LEFT JOIN tblitemmaster im ON TRIM(pd.ItemCode) = TRIM(im.CODE)
        -- Join with new size table using SIZE_CODE (preferred) or ITEM_GROUP (fallback)
        LEFT JOIN tblsize sz ON (im.SIZE_CODE = sz.SIZE_CODE) OR (im.ITEM_GROUP = sz.OLD_ITEM_GROUP)
        -- Join with new class tables using CLASS_CODE_NEW
        LEFT JOIN tblclass_new cn ON im.CLASS_CODE_NEW = cn.CLASS_CODE
        LEFT JOIN tblsubclass_new sn ON im.SUBCLASS_CODE_NEW = sn.SUBCLASS_CODE
        LEFT JOIN tblcategory cat ON cn.CATEGORY_CODE = cat.CATEGORY_CODE
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
        
        // Get item details from new schema
        $itemName = $row['ItemName'] ?? '';
        $itemCode = $row['ItemCode'] ?? '';
        
        // Get category from new tables - CATEGORY_NAME from tblcategory via tblclass_new
        $categoryName = $row['CATEGORY_NAME'] ?? '';
        $className = $row['CLASS_NAME'] ?? '';
        $classCategoryCode = $row['ClassCategoryCode'] ?? '';
        $oldClass = $row['OldClass'] ?? '';
        
        // Log item information for debugging
        error_log("Item: {$itemName}, Code: {$itemCode}, Category: '{$categoryName}', ClassName: '{$className}', CategoryCode: '{$classCategoryCode}', OldClass: '{$oldClass}'");
        
        // Determine product category based on new CATEGORY_NAME or CATEGORY_CODE
        $productType = 'SPIRITS'; // Default
        
        if (!empty($categoryName)) {
            $categoryUpper = strtoupper($categoryName);
            if (strpos($categoryUpper, 'WINE') !== false) {
                $productType = 'WINE';
            } elseif (strpos($categoryUpper, 'MILD') !== false || strpos($categoryUpper, 'BEER') !== false) {
                // Check if it's MILD BEER or FERMENTED BEER
                if (strpos($categoryUpper, 'MILD') !== false) {
                    $productType = 'MILD BEER';
                } else {
                    $productType = 'FERMENTED BEER';
                }
            } else {
                $productType = 'SPIRITS';
            }
        } elseif (!empty($classCategoryCode)) {
            // Fallback to category code mapping
            $catCodeUpper = strtoupper($classCategoryCode);
            if (strpos($catCodeUpper, 'WINE') !== false) {
                $productType = 'WINE';
            } elseif (strpos($catCodeUpper, 'MILD') !== false) {
                $productType = 'MILD BEER';
            } elseif (strpos($catCodeUpper, 'FB') !== false || strpos($catCodeUpper, 'BEER') !== false) {
                $productType = 'FERMENTED BEER';
            }
        } else {
            // Fallback to old class mapping
            $productType = getProductTypeFromOldClass($oldClass ?? '');
        }
        
        // Get volume - PRIORITY: 1) ML_VOLUME from tblsize, 2) parse from strings
        $mlVolume = $row['ML_VOLUME'] ?? null;
        $sizeDescription = $row['SizeDescription'] ?? '';
        $purchaseSize = $row['PurchaseSize'] ?? '';
        
        // Use ML_VOLUME from tblsize if available
        $volume = 0;
        if (!empty($mlVolume) && floatval($mlVolume) > 0) {
            $volume = floatval($mlVolume);
            error_log("Using ML_VOLUME from tblsize: {$volume}");
        } else {
            // Fall back to parsing from strings
            $volume = extractVolumeFromSize($purchaseSize, $sizeDescription, $itemName);
        }
        
        // Debug log for 1L items
        if ($volume == 1000 || $volume == 1000.0) {
            error_log("1L item found: {$itemName}, Volume: {$volume}, Category: {$productType}");
        }
        
        // === FIXED: Calculate total bottles properly ===
        // Check if TotBott column has valid value
        if (isset($row['TotBott']) && $row['TotBott'] > 0) {
            $totalQty = intval($row['TotBott']);
            error_log("Using TotBott column: {$totalQty}");
        } else {
            // Calculate manually from Cases and Bottles
            $cases = floatval($row['Cases'] ?? 0);
            $bottles = intval($row['Bottles'] ?? 0);
            // Use BottlesPerCase from tblsize if available, otherwise from row
            $bottlesPerCase = intval($row['SizeBottlesPerCase'] ?? $row['BottlesPerCase'] ?? 12);
            
            // Handle special case where BottlesPerCase is 0 or negative
            if ($bottlesPerCase <= 0) {
                $bottlesPerCase = 1; // Default to 1 if invalid
                error_log("Warning: Invalid BottlesPerCase={$bottlesPerCase}, using 1");
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
            // Check if this is a large size (>1L) - use normalized volume for accurate comparison
            $normalizedVolume = normalizeVolume($volume);
            $isLargeSize = isVolumeLargeSize($normalizedVolume);
            $targetColumn = $isLargeSize ? '>1L' : $volumeColumn;
            
            // For wine, get internal size key (with 'W' suffix)
            if ($productType === 'WINE') {
                $targetColumn = getInternalSizeKey($targetColumn, $productType);
            }
            
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
    // Use >= 1001 to be more precise - 1000ml (1L) should NOT be considered >1L
    return floatval($volume) >= 1001;
}

// Helper function to normalize volume value (handles 1000ml = 1L)
function normalizeVolume($volume) {
    $v = floatval($volume);
    // If volume is between 999 and 1001, treat as 1000 (1L)
    if ($v >= 999 && $v <= 1001) {
        return 1000;
    }
    return $v;
}

// Helper function to get product type from old single-character CLASS field
function getProductTypeFromOldClass($oldClass) {
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
    
    $classUpper = strtoupper(trim($oldClass ?? ''));
    
    if (isset($classToCategory[$classUpper])) {
        return $classToCategory[$classUpper];
    }
    
    // Check for patterns
    if (strpos($classUpper, 'WINE') !== false || strpos($classUpper, 'WN') !== false) {
        return 'WINE';
    } elseif (strpos($classUpper, 'M') !== false) {
        return 'MILD BEER';
    } elseif (strpos($classUpper, 'F') !== false || strpos($classUpper, 'B') !== false) {
        return 'FERMENTED BEER';
    }
    
    return 'SPIRITS'; // Default
}

// Helper function to extract volume from size field
function extractVolumeFromSize($size, $details2, $itemName) {
    // Clean inputs - preserve original for debugging
    $originalSize = $size ?? '';
    $originalDetails2 = $details2 ?? '';
    
    $size = trim($size ?? '');
    $details2 = trim($details2 ?? '');
    $itemName = trim($itemName ?? '');
    
    // Check if details2 is empty or whitespace-only, set to empty string
    if ($details2 === '' || $details2 === null) {
        $details2 = '';
    }
    
    // Try DETAILS2 first (usually contains size like "330 ML") - only if not empty
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
    
    // Try Size column - more robust parsing for formats like "180 ML-(18)" or "330 ML(12)"
    if (!empty($size)) {
        // First try: Check for patterns like "90 ML-(96)" or "330 ML(12)" or "180 ML - 18"
        if (preg_match('/(\d+)\s*ML/i', $size, $matches)) {
            return intval($matches[1]);
        }
        
        // Check for liter sizes with various formats
        if (preg_match('/(\d+\.?\d*)\s*L/i', $size, $matches)) {
            return floatval($matches[1]) * 1000;
        }
        
        // Also try just matching any number followed by ML/L anywhere in the string
        if (preg_match('/(\d+)\s*(?:ML|L)/i', $size, $matches)) {
            $value = intval($matches[1]);
            if (stripos($size, 'L') !== false && stripos($size, 'ML') === false) {
                // It's in liters (e.g., "1L")
                return $value * 1000;
            }
            return $value; // It's in ML
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
    
    // Log failed extraction for debugging
    error_log("Volume extraction failed - Size: '{$originalSize}', Details2: '{$originalDetails2}', ItemName: '{$itemName}'");
    return 0;
}

// Helper function to get volume column for a category
// Returns display size (with ML suffix) for all categories including wine
function getVolumeColumnForCategory($volume, $category) {
    if ($volume == 0) {
        return null; // Cannot determine size
    }
    
    // Check for exactly 1L (1000 ML) FIRST - before checking > 1000
    if ($volume == 1000) {
        return '1L';
    }
    
    // For volumes > 1000 ML (but not exactly 1000)
    if ($volume > 1000) {
        // All other sizes > 1L go to >1L column
        return '>1L';
    }
    
    // Standard size mappings (display format - ML suffix)
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
    
    // Wine size mappings (display format - ML suffix)
    $wineMap = [
        750 => '750 ML',
        700 => '700 ML',
        500 => '500 ML',
        375 => '375 ML',
        330 => '330 ML',
        250 => '250 ML',
        180 => '180 ML',
        100 => '100 ML'
    ];
    
    if ($category === 'WINE') {
        return $wineMap[$volume] ?? null;
    } else {
        return $standardMap[$volume] ?? null;
    }
}

// Helper function to get internal size key for data storage
// Wine uses 'W' suffix internally, others use 'ML'
function getInternalSizeKey($displaySize, $category) {
    global $wineInternalSizes;
    
    if ($category === 'WINE' && isset($wineInternalSizes[$displaySize])) {
        return $wineInternalSizes[$displaySize];
    }
    return $displaySize;
}
?>