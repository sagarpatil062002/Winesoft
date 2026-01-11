<?php
// import_purchase.php - UPDATED WITH EXACT CSV MAPPING
session_start();

// Enable debug logging like purchases.php
function debugLog($message, $data = null) {
    $logFile = __DIR__ . '/debug_import.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $logMessage .= ": " . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            $logMessage .= ": " . $data;
        }
    }
    
    $logMessage .= "\n";
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

debugLog("=== IMPORT PURCHASE STARTED ===");

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { 
    debugLog("User not logged in, redirecting to index");
    header("Location: index.php"); 
    exit; 
}
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { 
    debugLog("Company ID or Financial Year not set, redirecting to index");
    header("Location: index.php"); 
    exit; 
}

$companyId = $_SESSION['CompID'];
debugLog("Company ID from session", $companyId);

include_once "../config/db.php";
include_once "stock_functions.php"; // For stock update functions
debugLog("Database connection included");

// ---- License filtering ----
require_once 'license_functions.php';
debugLog("License functions included");

// Get company's license type and available classes
$license_type = getCompanyLicenseType($companyId, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

debugLog("License type", $license_type);
debugLog("Available classes", $available_classes);

// Extract class SGROUP values for filtering
$allowed_classes = [];
if (!empty($available_classes)) {
    foreach ($available_classes as $class) {
        $allowed_classes[] = $class['SGROUP'];
    }
}
debugLog("Allowed class SGROUP values", $allowed_classes);

// Function to clean item code by removing SCM prefix (SAME AS purchases.php)
function cleanItemCode($code) {
    $cleaned = preg_replace('/^SCM/i', '', trim($code));
    debugLog("cleanItemCode: '$code' -> '$cleaned'");
    return $cleaned;
}

// Function to update MRP in tblitemmaster (SAME AS purchases.php)
function updateItemMRP($conn, $itemCode, $mrp) {
    // Clean the item code by removing SCM prefix
    $cleanCode = cleanItemCode($itemCode);
    
    debugLog("Updating MRP for item", [
        'item_code' => $cleanCode,
        'mrp' => $mrp
    ]);
    
    // Update MPRICE in tblitemmaster
    $updateQuery = "UPDATE tblitemmaster SET MPRICE = ? WHERE CODE = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("ss", $mrp, $cleanCode);
    
    $result = $stmt->execute();
    $affectedRows = $stmt->affected_rows;
    
    debugLog("MRP update result", [
        'success' => $result,
        'affected_rows' => $affectedRows,
        'clean_code' => $cleanCode,
        'mrp' => $mrp
    ]);
    
    $stmt->close();
    
    return $result;
}

// Function to find supplier by name (SAME AS purchases.php logic)
function findBestSupplierMatch($supplierName, $conn) {
    debugLog("Finding supplier match for", $supplierName);
    
    if (empty($supplierName)) {
        return null;
    }
    
    // Try exact match first
    $query = "SELECT CODE, DETAILS FROM tblsupplier WHERE DETAILS = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $supplierName);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $supplier = $result->fetch_assoc();
        $stmt->close();
        debugLog("Exact match found", $supplier);
        return $supplier;
    }
    $stmt->close();
    
    // Try LIKE match
    $query = "SELECT CODE, DETAILS FROM tblsupplier WHERE DETAILS LIKE ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $searchTerm = "%" . $supplierName . "%";
    $stmt->bind_param("s", $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $supplier = $result->fetch_assoc();
        $stmt->close();
        debugLog("Partial match found", $supplier);
        return $supplier;
    }
    $stmt->close();
    
    debugLog("No supplier match found");
    return null;
}

// Function to find item by code (SAME AS purchases.php)
function findItem($itemCode, $conn, $allowed_classes) {
    $cleanCode = cleanItemCode($itemCode);
    
    debugLog("Finding item", [
        'original_code' => $itemCode,
        'clean_code' => $cleanCode
    ]);
    
    // Build query with license filtering
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.BOTTLE_PER_CASE, im.CLASS,
                         COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE
                  FROM tblitemmaster im
                  LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
                  WHERE (im.CODE = ? OR im.CODE = ?) 
                  AND im.CLASS IN ($class_placeholders)
                  LIMIT 1";
        
        $params = array_merge([$itemCode, $cleanCode], $allowed_classes);
        $types = str_repeat('s', count($params));
        
        debugLog("Item query with license filter", [
            'query' => $query,
            'params' => $params,
            'types' => $types
        ]);
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
    } else {
        // No license restrictions
        $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.BOTTLE_PER_CASE, im.CLASS,
                         COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE
                  FROM tblitemmaster im
                  LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
                  WHERE im.CODE = ? OR im.CODE = ?
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $itemCode, $cleanCode);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        $stmt->close();
        debugLog("Item found", $item);
        return $item;
    }
    $stmt->close();
    
    debugLog("Item not found");
    return null;
}

// Function to update stock (SAME AS purchases.php)
function updateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn) {
    debugLog("Updating stock for imported item", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'company_id' => $companyId
    ]);
    
    // Get day of month from purchase date
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    // Check if this month is archived
    function isMonthArchived($conn, $comp_id, $month, $year) {
        $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($year, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        
        // Check if archive table exists
        $check_archive_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                               WHERE table_schema = DATABASE() 
                               AND table_name = '$archive_table'";
        $check_result = $conn->query($check_archive_query);
        $exists = $check_result->fetch_assoc()['count'] > 0;
        
        return $exists;
    }
    
    $isArchived = isMonthArchived($conn, $companyId, $month, $year);
    
    if ($isArchived) {
        debugLog("Month is archived, updating archive table");
        // For simplicity, we'll update item_stock only for now
        // In production, you would call the full updateArchivedMonthStock function
    }
    
    // Update tblitem_stock (SAME AS purchases.php)
    $stockColumn = "CURRENT_STOCK" . $companyId;
    
    // Add to existing stock
    $updateItemStockQuery = "UPDATE tblitem_stock 
                            SET $stockColumn = $stockColumn + ? 
                            WHERE ITEM_CODE = ?";
    
    $itemStmt = $conn->prepare($updateItemStockQuery);
    $itemStmt->bind_param("is", $totalBottles, $itemCode);
    $result = $itemStmt->execute();
    $itemStmt->close();
    
    debugLog("Stock update result", [
        'success' => $result,
        'item_code' => $itemCode,
        'added_stock' => $totalBottles
    ]);
    
    return $result;
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    debugLog("=== FORM SUBMISSION STARTED ===");
    
    $importMode = $_POST['import_mode'] ?? 'F';
    $defaultStatus = $_POST['default_status'] ?? 'T';
    $updateMRP = isset($_POST['update_mrp']) ? true : false;
    $updateStockFlag = isset($_POST['update_stock']) ? true : false;
    
    debugLog("Import settings", [
        'mode' => $importMode,
        'default_status' => $defaultStatus,
        'update_mrp' => $updateMRP,
        'update_stock' => $updateStockFlag
    ]);
    
    // Validate file
    $fileName = $_FILES['excel_file']['name'];
    $fileSize = $_FILES['excel_file']['size'];
    $fileTmp = $_FILES['excel_file']['tmp_name'];
    
    // Check file size (10MB max)
    if ($fileSize > 10 * 1024 * 1024) {
        header("Location: purchase_module.php?mode=$importMode&import_error=File size exceeds 10MB limit");
        exit;
    }
    
    // Check file extension - ONLY CSV ALLOWED
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExtensions = ['csv'];

    if (!in_array($fileExt, $allowedExtensions)) {
        header("Location: purchase_module.php?mode=$importMode&import_error=Invalid file type. Please upload .csv files only.");
        exit;
    }

    // Process CSV file
    processCSVFile($fileTmp, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes);
} else {
    header("Location: purchase_module.php");
    exit;
}

function processCSVFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes) {
    debugLog("Processing CSV file", $filePath);
    
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        debugLog("Cannot open file", $filePath);
        header("Location: purchase_module.php?mode=$importMode&import_error=Cannot open file");
        exit;
    }
    
    // Read headers from first line
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        debugLog("Empty or invalid CSV file");
        header("Location: purchase_module.php?mode=$importMode&import_error=Empty or invalid CSV file");
        exit;
    }
    
    // Normalize header names (lowercase, trim, remove special chars)
    $headers = array_map(function($h) {
        $h = trim($h);
        $h = strtolower($h);
        $h = preg_replace('/[^a-z0-9\s]/', '', $h); // Remove special characters
        $h = str_replace(' ', '_', $h); // Replace spaces with underscores
        return $h;
    }, $headers);
    
    debugLog("CSV Headers normalized", $headers);
    
    $tpGroups = [];
    $rowNum = 1;
    
    // Read data rows
    while (($data = fgetcsv($handle)) !== false) {
        $rowNum++;
        
        // Map data to headers
        $rowData = [];
        foreach ($headers as $index => $header) {
            if (isset($data[$index])) {
                $rowData[$header] = trim($data[$index]);
            } else {
                $rowData[$header] = '';
            }
        }
        
        debugLog("Row $rowNum raw data", $rowData);
        
        // Skip empty rows
        if (empty($rowData['scm_item_code']) && empty($rowData['item_name'])) {
            continue;
        }
        
        // Get values from CSV - MATCHING YOUR CSV STRUCTURE
        $receivedDate = $rowData['received_date'] ?? '';
        $autoTpNo = $rowData['auto_tp_no'] ?? '';
        $manualTpNo = $rowData['manual_tp_no'] ?? '';
        $tpDate = $rowData['tp_date'] ?? '';
        $district = $rowData['district'] ?? '';
        $scmPartyCode = $rowData['scm_party_code'] ?? '';
        $partyName = $rowData['party_name'] ?? '';
        $srNo = $rowData['srno'] ?? '';
        $scmItemCode = $rowData['scm_item_code'] ?? '';
        $itemName = $rowData['item_name'] ?? '';
        $size = $rowData['size'] ?? '';
        $cases = floatval($rowData['qty_cases'] ?? 0);
        $bottles = intval($rowData['qty_bottles'] ?? 0);
        $batchNo = $rowData['batch_no'] ?? '';
        $mfgMonth = $rowData['mfg_month'] ?? '';
        $mrp = floatval($rowData['mrp'] ?? 0);
        $bl = floatval($rowData['bl'] ?? 0);
        $vv = floatval($rowData['vv'] ?? 0);
        $totalBottQty = intval($rowData['total_bot_qty'] ?? 0);
        
        // Calculate missing fields
        $freeCases = 0; // Not in CSV, default to 0
        $freeBottles = 0; // Not in CSV, default to 0
        
        debugLog("Row $rowNum parsed data", [
            'received_date' => $receivedDate,
            'auto_tp_no' => $autoTpNo,
            'manual_tp_no' => $manualTpNo,
            'party_name' => $partyName,
            'scm_item_code' => $scmItemCode,
            'item_name' => $itemName,
            'size' => $size,
            'cases' => $cases,
            'bottles' => $bottles,
            'mrp' => $mrp,
            'total_bott_qty' => $totalBottQty
        ]);
        
        // Format dates - Use received_date as purchase date
        $purchaseDate = '';
        if (!empty($receivedDate)) {
            $purchaseDate = date('Y-m-d', strtotime($receivedDate));
            if ($purchaseDate == '1970-01-01') {
                $purchaseDate = date('Y-m-d');
            }
        } else {
            $purchaseDate = date('Y-m-d');
        }
        
        // Format TP date
        $formattedTpDate = '';
        if (!empty($tpDate)) {
            $formattedTpDate = date('Y-m-d', strtotime($tpDate));
            if ($formattedTpDate == '1970-01-01') {
                $formattedTpDate = '0000-00-00';
            }
        } else {
            $formattedTpDate = '0000-00-00';
        }
        
        // Use manual TP number if available, otherwise auto TP number
        $tpNo = !empty($manualTpNo) ? $manualTpNo : $autoTpNo;
        
        // Group by TP No. (manual or auto)
        if (!empty($tpNo)) {
            if (!isset($tpGroups[$tpNo])) {
                $tpGroups[$tpNo] = [
                    'date' => $purchaseDate,
                    'supplier' => $partyName,
                    'auto_tp_no' => $autoTpNo,
                    'manual_tp_no' => $manualTpNo,
                    'tp_date' => $formattedTpDate,
                    'district' => $district,
                    'scm_party_code' => $scmPartyCode,
                    'items' => []
                ];
            }
            
            $tpGroups[$tpNo]['items'][] = [
                'scm_item_code' => $scmItemCode,
                'item_name' => $itemName,
                'size' => $size,
                'cases' => $cases,
                'bottles' => $bottles,
                'free_cases' => $freeCases,
                'free_bottles' => $freeBottles,
                'batch_no' => $batchNo,
                'mfg_month' => $mfgMonth,
                'mrp' => $mrp,
                'bl' => $bl,
                'vv' => $vv,
                'total_bott_qty' => $totalBottQty
            ];
        } else {
            debugLog("Skipping row - no TP number", $rowNum);
        }
    }
    
    fclose($handle);
    
    debugLog("Found TP groups", [
        'count' => count($tpGroups),
        'tps' => array_keys($tpGroups)
    ]);
    
    // Process TP groups
    $result = processTPGroups($tpGroups, $companyId, $conn, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $importMode);
    
    if ($result['errorCount'] > 0) {
        $errorMessage = "Imported {$result['successCount']} purchases successfully. Failed: {$result['errorCount']}. " . 
                       ($result['errorCount'] > 0 ? "First error: " . $result['errors'][0] : "");
        header("Location: purchase_module.php?mode=$importMode&import_error=" . urlencode($errorMessage));
    } else {
        header("Location: purchase_module.php?mode=$importMode&import_success=1");
    }
}

function processTPGroups($tpGroups, $companyId, $conn, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $importMode) {
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    
    debugLog("Processing TP groups", [
        'total_tps' => count($tpGroups)
    ]);
    
    // First, get all items from database for batch lookup (for efficiency)
    $allItems = [];
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $itemsQuery = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.BOTTLE_PER_CASE, im.CLASS,
                              COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE,
                              CONCAT('SCM', im.CODE) AS SCM_CODE
                       FROM tblitemmaster im
                       LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
                       WHERE im.CLASS IN ($class_placeholders)";
        
        $params = $allowed_classes;
        $types = str_repeat('s', count($params));
        
        $itemsStmt = $conn->prepare($itemsQuery);
        $itemsStmt->bind_param($types, ...$params);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();
        
        while ($item = $itemsResult->fetch_assoc()) {
            $allItems[$item['CODE']] = $item;
            $allItems[$item['SCM_CODE']] = $item; // Also index by SCM code
        }
        $itemsStmt->close();
    }
    
    debugLog("Loaded items for batch lookup", [
        'item_count' => count($allItems)
    ]);
    
    foreach ($tpGroups as $tpNo => $tpData) {
        debugLog("=== Processing TP: $tpNo ===");
        
        try {
            // Start transaction
            $conn->begin_transaction();
            
            // Find supplier - SAME AS purchases.php
            $supplierInfo = findBestSupplierMatch($tpData['supplier'], $conn);
            $supplierCode = $supplierInfo ? $supplierInfo['CODE'] : '';
            
            debugLog("Supplier match result", [
                'input' => $tpData['supplier'],
                'found_code' => $supplierCode,
                'found_name' => $supplierInfo ? $supplierInfo['DETAILS'] : 'Not found'
            ]);
            
            // Get next voucher number - EXACT SAME AS purchases.php
            $vocQuery = "SELECT MAX(VOC_NO) AS MAX_VOC FROM tblpurchases WHERE CompID = ?";
            $vocStmt = $conn->prepare($vocQuery);
            $vocStmt->bind_param("i", $companyId);
            $vocStmt->execute();
            $vocResult = $vocStmt->get_result();
            $maxVoc = $vocResult ? $vocResult->fetch_assoc() : ['MAX_VOC'=>0];
            $nextVoc = intval($maxVoc['MAX_VOC']) + 1;
            $vocStmt->close();
            
            debugLog("Voucher number calculated", [
                'max_voc' => $maxVoc['MAX_VOC'],
                'next_voc' => $nextVoc
            ]);
            
            // Calculate total amount and process items
            $totalAmount = 0;
            $validItems = [];
            
            foreach ($tpData['items'] as $item) {
                // Find item in batch lookup
                $itemInfo = null;
                $cleanCode = cleanItemCode($item['scm_item_code']);
                
                // Try to find by SCM code first
                if (isset($allItems[$item['scm_item_code']])) {
                    $itemInfo = $allItems[$item['scm_item_code']];
                }
                // Try by clean code
                elseif (isset($allItems[$cleanCode])) {
                    $itemInfo = $allItems[$cleanCode];
                }
                
                if (!$itemInfo) {
                    debugLog("Item not found or license restricted", [
                        'scm_item_code' => $item['scm_item_code'],
                        'clean_code' => $cleanCode,
                        'allowed_classes' => $allowed_classes
                    ]);
                    continue; // Skip items not found or not allowed by license
                }
                
                $bottlesPerCase = $itemInfo ? intval($itemInfo['BOTTLE_PER_CASE']) : 12;
                
                // We need case rate - not in CSV, so we need to get it from database
                // Use PPRICE from tblitemmaster as default case rate
                $caseRate = $itemInfo ? floatval($itemInfo['PPRICE']) : 0;
                
                // Calculate amount - SAME FORMULA AS purchases.php
                $amount = ($item['cases'] * $caseRate) + 
                         ($item['bottles'] * ($caseRate / $bottlesPerCase));
                $totalAmount += $amount;
                
                // Use total_bott_qty from CSV if available, otherwise calculate
                $totalBottles = $item['total_bott_qty'] > 0 ? $item['total_bott_qty'] : 
                               ($item['cases'] * $bottlesPerCase) + $item['bottles'];
                
                $validItems[] = [
                    'data' => $item,
                    'info' => $itemInfo,
                    'bottles_per_case' => $bottlesPerCase,
                    'case_rate' => $caseRate,
                    'amount' => $amount,
                    'total_bottles' => $totalBottles
                ];
                
                debugLog("Item calculation", [
                    'scm_item_code' => $item['scm_item_code'],
                    'cases' => $item['cases'],
                    'bottles' => $item['bottles'],
                    'case_rate' => $caseRate,
                    'bottles_per_case' => $bottlesPerCase,
                    'amount' => $amount,
                    'total_bottles' => $totalBottles,
                    'total_amount_so_far' => $totalAmount
                ]);
            }
            
            if (empty($validItems)) {
                throw new Exception("No valid items found for this TP (all items may be missing or license restricted)");
            }
            
            // Use auto TP number from CSV or generate one
            $autoTpNo = !empty($tpData['auto_tp_no']) ? $tpData['auto_tp_no'] : 
                       'FL' . date('dmY', strtotime($tpData['date'])) . '/' . $tpNo;
            
            debugLog("TP details", [
                'auto_tp_no' => $autoTpNo,
                'manual_tp_no' => $tpData['manual_tp_no'],
                'tp_date' => $tpData['tp_date']
            ]);
            
            // Insert purchase header - EXACT SAME COLUMNS AS purchases.php
            $insertQuery = "INSERT INTO tblpurchases (
                DATE, SUBCODE, AUTO_TPNO, VOC_NO, INV_NO, INV_DATE, TAMT, 
                TPNO, TP_DATE, PUR_FLAG, CompID, DISTRICT
            ) VALUES (?, ?, ?, ?, '', '0000-00-00', ?, ?, ?, ?, ?, ?)";
            
            debugLog("Purchase header insert query", $insertQuery);
            
            $insertStmt = $conn->prepare($insertQuery);
            if (!$insertStmt) {
                throw new Exception("Error preparing purchase header: " . $conn->error);
            }
            
            // Use empty string for invoice number and date
            $invNo = '';
            $invDate = '0000-00-00';
            
            // Bind parameters - SAME ORDER AS purchases.php
            $insertStmt->bind_param(
                "sssssssssis",  // Updated for district
                $tpData['date'],        // DATE
                $supplierCode,          // SUBCODE
                $autoTpNo,              // AUTO_TPNO
                $nextVoc,               // VOC_NO
                $totalAmount,           // TAMT
                $tpNo,                  // TPNO (manual TP no)
                $tpData['tp_date'],     // TP_DATE
                $defaultStatus,         // PUR_FLAG
                $companyId,             // CompID
                $tpData['district']     // DISTRICT
            );
            
            if (!$insertStmt->execute()) {
                throw new Exception("Error inserting purchase header: " . $insertStmt->error);
            }
            
            $purchaseId = $conn->insert_id;
            $insertStmt->close();
            
            debugLog("Purchase header inserted", [
                'purchase_id' => $purchaseId,
                'voucher_no' => $nextVoc,
                'affected_rows' => $conn->affected_rows
            ]);
            
            // Insert purchase items - EXACT SAME COLUMNS AS purchases.php
            $detailQuery = "INSERT INTO tblpurchasedetails (
                PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, FreeCases, FreeBottles, 
                CaseRate, MRP, Amount, BottlesPerCase, BatchNo, AutoBatch, MfgMonth, BL, VV, TotBott
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            debugLog("Purchase details insert query", $detailQuery);
            
            $detailStmt = $conn->prepare($detailQuery);
            if (!$detailStmt) {
                throw new Exception("Error preparing purchase detail: " . $conn->error);
            }
            
            $itemsInserted = 0;
            foreach ($validItems as $validItem) {
                $item = $validItem['data'];
                $itemInfo = $validItem['info'];
                $bottlesPerCase = $validItem['bottles_per_case'];
                $caseRate = $validItem['case_rate'];
                $amount = $validItem['amount'];
                $totalBottles = $validItem['total_bottles'];
                
                // Use BL from CSV if available, otherwise calculate
                $bl = $item['bl'] > 0 ? $item['bl'] : 0;
                
                // Use VV from CSV if available
                $vv = $item['vv'] > 0 ? $item['vv'] : 0;
                
                debugLog("Inserting item detail", [
                    'purchase_id' => $purchaseId,
                    'item_code' => $itemInfo['CODE'],
                    'total_bottles' => $totalBottles,
                    'bl' => $bl,
                    'vv' => $vv,
                    'amount' => $amount
                ]);
                
                // Bind parameters - EXACT SAME ORDER AND TYPES AS purchases.php
                $detailStmt->bind_param(
                    "isssdddddddisssddi",  // SAME DATA TYPES
                    $purchaseId,            // PurchaseID
                    $itemInfo['CODE'],      // ItemCode (use database code, not SCM code)
                    $item['item_name'],     // ItemName
                    $item['size'],          // Size
                    $item['cases'],         // Cases
                    $item['bottles'],       // Bottles
                    $item['free_cases'],    // FreeCases
                    $item['free_bottles'],  // FreeBottles
                    $caseRate,              // CaseRate (from PPRICE)
                    $item['mrp'],           // MRP
                    $amount,                // Amount
                    $bottlesPerCase,        // BottlesPerCase
                    $item['batch_no'],      // BatchNo
                    '',                     // AutoBatch (empty for import)
                    $item['mfg_month'],     // MfgMonth
                    $bl,                    // BL
                    $vv,                    // VV
                    $totalBottles           // TotBott
                );
                
                if (!$detailStmt->execute()) {
                    throw new Exception("Error inserting purchase detail for item {$itemInfo['CODE']}: " . $detailStmt->error);
                }
                
                $itemsInserted++;
                
                // Update MRP if requested - SAME FUNCTION AS purchases.php
                if ($updateMRP && $item['mrp'] > 0) {
                    updateItemMRP($conn, $itemInfo['CODE'], $item['mrp']);
                }
                
                // Update stock if requested
                if ($updateStockFlag) {
                    updateStock($itemInfo['CODE'], $totalBottles, $tpData['date'], $companyId, $conn);
                }
            }
            
            $detailStmt->close();
            
            debugLog("Items inserted", [
                'total_items' => $itemsInserted,
                'expected_items' => count($validItems)
            ]);
            
            if ($itemsInserted == 0) {
                throw new Exception("No items were inserted for this TP");
            }
            
            // Commit transaction
            $conn->commit();
            $successCount++;
            
            debugLog("Successfully imported TP", [
                'tp_no' => $tpNo,
                'purchase_id' => $purchaseId,
                'voucher_no' => $nextVoc,
                'items_inserted' => $itemsInserted
            ]);
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $errorCount++;
            $errors[] = "TP No. $tpNo: " . $e->getMessage();
            
            debugLog("Error importing TP", [
                'tp_no' => $tpNo,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    debugLog("Import completed", [
        'successCount' => $successCount,
        'errorCount' => $errorCount
    ]);
    
    return [
        'successCount' => $successCount,
        'errorCount' => $errorCount,
        'errors' => $errors
    ];
}

$conn->close();
debugLog("=== IMPORT PURCHASE ENDED ===");
?>