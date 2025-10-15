<?php
session_start();

// Enable comprehensive debug logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'purchase_summary_ajax_debug.log');

// Log the request
error_log("=== PURCHASE SUMMARY AJAX DEBUG ===");
error_log("Request Time: " . date('Y-m-d H:i:s'));
error_log("GET Parameters: " . print_r($_GET, true));
error_log("Session CompID: " . ($_SESSION['CompID'] ?? 'NOT SET'));

// Check if required session variables exist
if (!isset($_SESSION['CompID'])) {
    error_log("Purchase Summary: No CompID in session");
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Include database connection
require_once "../config/db.php";

// Check if database connection is successful
if (!$conn) {
    error_log("Purchase Summary: Database connection failed");
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get parameters with validation
$companyId = $_SESSION['CompID'];
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'ALL'; // Changed default to ALL
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-01');
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');

error_log("Purchase Summary Request: Company=$companyId, Mode=$mode, From=$fromDate, To=$toDate");

// Initialize summary structure - UPDATED: All categories
$purchaseSummary = [
    'SPIRITS' => [],
    'WINE' => [],
    'FERMENTED BEER' => [],
    'MILD BEER' => [],
    'COUNTRY LIQUOR' => []
];

// All possible sizes
$allSizes = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML', 
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

// Initialize all sizes to 0 for each category
foreach (array_keys($purchaseSummary) as $category) {
    foreach ($allSizes as $size) {
        $purchaseSummary[$category][$size] = 0;
    }
}

// DEBUG: Log initial summary structure
error_log("Initialized summary structure with zeros");

try {
    // UPDATED QUERY: Include item class and 'C' status when mode is 'ALL'
    $query = "
        SELECT 
            pd.ItemCode,
            pd.Size,
            pd.Cases,
            pd.Bottles,
            pd.BottlesPerCase,
            pd.ItemName,
            p.ID as PurchaseID,
            p.DATE as PurchaseDate,
            p.PUR_FLAG,
            im.CLASS as ItemClass
        FROM tblpurchasedetails pd
        INNER JOIN tblpurchases p ON pd.PurchaseID = p.ID
        LEFT JOIN tblitemmaster im ON pd.ItemCode = im.CODE
        WHERE p.CompID = ?
        AND p.DATE BETWEEN ? AND ?
    ";
    
    // Add PUR_FLAG condition if not 'ALL'
    if ($mode !== 'ALL') {
        $query .= " AND p.PUR_FLAG = ?";
    } else {
        $query .= " AND p.PUR_FLAG IN ('F', 'T', 'P', 'C')";
    }

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
    $rawData = [];
    
    while ($row = $result->fetch_assoc()) {
        $rawData[] = $row; // Store for debugging
        
        // Use ItemClass to determine product type - UPDATED LOGIC
        $productType = getProductTypeFromClass($row['ItemClass'], $row['ItemName']);
        $volume = extractVolume($row['Size'], $row['ItemName']);
        
        // Calculate total quantity
        $totalQty = 0;
        $bottlesPerCase = $row['BottlesPerCase'] ?: 12; // Default to 12 if not set
        
        if ($bottlesPerCase > 0) {
            $totalQty = ($row['Cases'] * $bottlesPerCase) + $row['Bottles'];
        } else {
            $totalQty = $row['Cases'] + $row['Bottles'];
        }
        
        $volumeColumn = getVolumeColumn($volume);
        
        // DEBUG: Log each item processing
        error_log("Item $processedItems: " . $row['ItemName']);
        error_log("  - Size: " . $row['Size']);
        error_log("  - Class: " . ($row['ItemClass'] ?? 'NOT SET'));
        error_log("  - Extracted Volume: " . $volume);
        error_log("  - Volume Column: " . ($volumeColumn ?: 'NOT FOUND'));
        error_log("  - Product Type: " . $productType);
        error_log("  - Cases: " . $row['Cases'] . ", Bottles: " . $row['Bottles'] . ", BPC: " . $bottlesPerCase);
        error_log("  - Total Qty: " . $totalQty);
        error_log("  - PUR_FLAG: " . $row['PUR_FLAG']);
        
        if ($volumeColumn && isset($purchaseSummary[$productType]) && isset($purchaseSummary[$productType][$volumeColumn])) {
            $purchaseSummary[$productType][$volumeColumn] += $totalQty;
            error_log("  - ADDED to $productType -> $volumeColumn: +$totalQty");
            $processedItems++;
        } else {
            error_log("  - SKIPPED - Volume column not found or invalid product type");
        }
        error_log("  ---");
    }
    
    // DEBUG: Log raw data and final summary
    error_log("Raw data from query: " . print_r($rawData, true));
    error_log("Final summary data: " . print_r($purchaseSummary, true));
    error_log("Processed $processedItems items successfully");
    
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
echo json_encode($purchaseSummary);

// UPDATED: Helper function to determine product type from class - MATCHING SALE LOGIC
function getProductTypeFromClass($itemClass, $itemName) {
    error_log("getProductTypeFromClass: Class='$itemClass', Name='$itemName'");
    
    // Classification based on CLASS field (matching sale_for_date_range.php logic)
    $classMappings = [
        // Spirits
        'W' => 'SPIRITS', // Whisky
        'G' => 'SPIRITS', // Gin
        'D' => 'SPIRITS', // Brandy
        'K' => 'SPIRITS', // ??? 
        'R' => 'SPIRITS', // Rum
        'O' => 'SPIRITS', // Other spirits
        
        // Wine
        'V' => 'WINE',    // Wine
        
        // Beer
        'F' => 'FERMENTED BEER', // Fermented beer
        'M' => 'MILD BEER',      // Mild beer
        
        // Country Liquor
        'L' => 'COUNTRY LIQUOR', // Country Liquor
        'C' => 'COUNTRY LIQUOR', // Country Liquor (alternative)
        
        // Default fallback
        'B' => 'FERMENTED BEER', // Beer (default)
    ];
    
    // Priority: Use class mapping first
    if (!empty($itemClass) && isset($classMappings[$itemClass])) {
        $result = $classMappings[$itemClass];
        error_log("  - Classified as $result (Class: $itemClass)");
        return $result;
    }
    
    // Fallback to name-based classification if class not available or not mapped
    error_log("  - Falling back to name-based classification");
    return getProductTypeFromName($itemName);
}

// Fallback helper function for name-based classification
function getProductTypeFromName($itemName) {
    if (empty($itemName)) {
        error_log("getProductTypeFromName: Empty item name, defaulting to SPIRITS");
        return 'SPIRITS';
    }
    
    $name = strtoupper($itemName);
    error_log("getProductTypeFromName: Analyzing '$name'");
    
    // Country Liquor detection from name (fallback)
    if (strpos($name, 'COUNTRY') !== false || strpos($name, 'DESI') !== false || 
        strpos($name, 'LOCAL') !== false || strpos($name, 'INDIGENOUS') !== false) {
        error_log("  - Classified as COUNTRY LIQUOR (Name-based)");
        return 'COUNTRY LIQUOR';
    }
    
    // Wine detection
    if (strpos($name, 'WINE') !== false || strpos($name, 'PORT') !== false || strpos($name, 'SHERRY') !== false) {
        error_log("  - Classified as WINE");
        return 'WINE';
    }
    
    // Beer detection
    if (strpos($name, 'BEER') !== false) {
        if (strpos($name, 'MILD') !== false) {
            error_log("  - Classified as MILD BEER");
            return 'MILD BEER';
        }
        error_log("  - Classified as FERMENTED BEER");
        return 'FERMENTED BEER';
    }
    
    // Spirits detection
    if (strpos($name, 'VODKA') !== false || strpos($name, 'RUM') !== false || 
        strpos($name, 'WHISKY') !== false || strpos($name, 'WHISKEY') !== false || 
        strpos($name, 'GIN') !== false || strpos($name, 'BRANDY') !== false) {
        error_log("  - Classified as SPIRITS");
        return 'SPIRITS';
    }
    
    error_log("  - Defaulting to SPIRITS");
    return 'SPIRITS'; // Default
}

function extractVolume($size, $itemName) {
    error_log("extractVolume: Size='$size', ItemName='$itemName'");
    
    // Priority: Size column first
    if (!empty($size)) {
        error_log("  - Checking size column: '$size'");
        // Handle liter sizes with decimal points
        if (preg_match('/(\d+\.?\d*)\s*L\b/i', $size, $matches)) {
            $volume = floatval($matches[1]);
            error_log("  - Found L size: {$matches[1]}L = " . ($volume * 1000) . "ML");
            return round($volume * 1000);
        }
        
        // Handle ML sizes
        if (preg_match('/(\d+)\s*ML\b/i', $size, $matches)) {
            error_log("  - Found ML size: {$matches[1]}ML");
            return intval($matches[1]);
        }
        
        // Handle special cases like "90 ML (Pet)-96"
        if (preg_match('/(\d+)\s*ML/i', $size, $matches)) {
            error_log("  - Found ML size (with extra text): {$matches[1]}ML");
            return intval($matches[1]);
        }
    }
    
    // Fallback: parse item name
    if (!empty($itemName)) {
        error_log("  - Checking item name for size: '$itemName'");
        // Handle liter sizes
        if (preg_match('/(\d+\.?\d*)\s*L\b/i', $itemName, $matches)) {
            $volume = floatval($matches[1]);
            error_log("  - Found L size in name: {$matches[1]}L = " . ($volume * 1000) . "ML");
            return round($volume * 1000);
        }
        
        // Handle ML sizes
        if (preg_match('/(\d+)\s*ML\b/i', $itemName, $matches)) {
            error_log("  - Found ML size in name: {$matches[1]}ML");
            return intval($matches[1]);
        }
    }
    
    error_log("  - No volume found, returning 0");
    return 0;
}

function getVolumeColumn($volume) {
    $volumeMap = [
        50 => '50 ML',
        60 => '60 ML', 
        90 => '90 ML',
        170 => '170 ML',
        180 => '180 ML',
        200 => '200 ML',
        250 => '250 ML',
        275 => '275 ML',
        330 => '330 ML',
        355 => '355 ML',
        375 => '375 ML',
        500 => '500 ML',
        650 => '650 ML',
        700 => '700 ML',
        750 => '750 ML',
        1000 => '1000 ML',
        1500 => '1.5L',
        1750 => '1.75L',
        2000 => '2L',
        3000 => '3L',
        4500 => '4.5L',
        15000 => '15L',
        20000 => '20L',
        30000 => '30L',
        50000 => '50L'
    ];
    
    $result = isset($volumeMap[$volume]) ? $volumeMap[$volume] : null;
    error_log("getVolumeColumn: $volume -> " . ($result ?: 'NOT FOUND'));
    
    return $result;
}
?>