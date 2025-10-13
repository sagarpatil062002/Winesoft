<?php
session_start();
require_once "../config/db.php";

// Auth check
if (!isset($_SESSION['CompID'])) {
    echo json_encode([]);
    exit;
}

$companyId = $_SESSION['CompID'];
$mode = $_GET['mode'] ?? 'F';
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');

// Query to get purchase data with item details
$query = "
    SELECT 
        pd.ITEM_CODE,
        pd.QTY,
        im.DETAILS,
        im.DETAILS2, 
        im.CLASS
    FROM tblpurchasedetails pd
    INNER JOIN tblpurchases p ON pd.VOC_NO = p.VOC_NO AND pd.CompID = p.CompID
    INNER JOIN tblitemmaster im ON pd.ITEM_CODE = im.CODE
    WHERE p.CompID = ?
    AND p.PUR_FLAG = ?
    AND p.DATE BETWEEN ? AND ?
    ORDER BY p.DATE DESC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("isss", $companyId, $mode, $fromDate, $toDate);
$stmt->execute();
$result = $stmt->get_result();

$purchaseSummary = [
    'SPIRITS' => [],
    'WINE' => [],
    'FERMENTED BEER' => [],
    'MILD BEER' => []
];

// Initialize all sizes to 0
$allSizes = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML', 
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

foreach (array_keys($purchaseSummary) as $category) {
    foreach ($allSizes as $size) {
        $purchaseSummary[$category][$size] = 0;
    }
}

while ($row = $result->fetch_assoc()) {
    $productType = getProductType($row['CLASS']);
    $volume = extractVolume($row['DETAILS'], $row['DETAILS2']);
    $volumeColumn = getVolumeColumn($volume);
    
    if ($volumeColumn && isset($purchaseSummary[$productType][$volumeColumn])) {
        $purchaseSummary[$productType][$volumeColumn] += $row['QTY'];
    }
}

echo json_encode($purchaseSummary);

// Helper functions
function getProductType($classCode) {
    $spirits = ['W', 'G', 'D', 'K', 'R', 'O'];
    if (in_array($classCode, $spirits)) return 'SPIRITS';
    if ($classCode === 'V') return 'WINE';
    if ($classCode === 'F') return 'FERMENTED BEER';
    if ($classCode === 'M') return 'MILD BEER';
    return 'OTHER';
}

function extractVolume($details, $details2) {
    // Priority: details2 column first
    if ($details2) {
        // Handle liter sizes with decimal points (1.5L, 2.0L, etc.)
        $literMatch = preg_match('/(\d+\.?\d*)\s*L\b/i', $details2, $matches);
        if ($literMatch) {
            $volume = floatval($matches[1]);
            return round($volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        $mlMatch = preg_match('/(\d+)\s*ML\b/i', $details2, $matches);
        if ($mlMatch) {
            return intval($matches[1]);
        }
    }
    
    // Fallback: parse details column
    if ($details) {
        // Handle special cases
        if (stripos($details, 'QUART') !== false) return 750;
        if (stripos($details, 'PINT') !== false) return 375;
        if (stripos($details, 'NIP') !== false) return 90;
        
        // Handle liter sizes with decimal points
        $literMatch = preg_match('/(\d+\.?\d*)\s*L\b/i', $details, $matches);
        if ($literMatch) {
            $volume = floatval($matches[1]);
            return round($volume * 1000); // Convert liters to ML
        }
        
        // Handle ML sizes
        $mlMatch = preg_match('/(\d+)\s*ML\b/i', $details, $matches);
        if ($mlMatch) {
            return intval($matches[1]);
        }
    }
    
    return 0; // Unknown volume
}

function getVolumeColumn($volume) {
    $volumeMap = [
        // ML sizes
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
        
        // Liter sizes (converted to ML for consistency)
        1500 => '1.5L',    // 1.5L = 1500ML
        1750 => '1.75L',   // 1.75L = 1750ML
        2000 => '2L',      // 2L = 2000ML
        3000 => '3L',      // 3L = 3000ML
        4500 => '4.5L',    // 4.5L = 4500ML
        15000 => '15L',    // 15L = 15000ML
        20000 => '20L',    // 20L = 20000ML
        30000 => '30L',    // 30L = 30000ML
        50000 => '50L'     // 50L = 50000ML
    ];
    
    return $volumeMap[$volume] ?? null;
}
?>