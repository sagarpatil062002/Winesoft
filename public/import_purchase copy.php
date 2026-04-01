<?php
// import_purchase.php - UPDATED FOR SCM CSV FORMAT WITH FIXES
// Includes VOC_NO renumbering based on TP_DATE
session_start();

// Include PhpSpreadsheet autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Increase PHP limits for file uploads - set to unlimited execution time
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '100M');
ini_set('max_file_uploads', '50');
ini_set('memory_limit', '512M');
set_time_limit(0); // No execution time limit

// ============================================================================
// VOUCHER NUMBER RENUMBERING FUNCTION
// Renumbers all VOC_NO for the company based on TP_DATE (or DATE if TP_DATE is empty)
// Called after importing purchases to ensure VOC_NO reflects date order
// ============================================================================
function renumberVoucherNumbers($conn, $companyId) {
    // Use ROW_NUMBER() to assign new VOC_NO based on date ordering
    // TP_DATE takes precedence over DATE
    // For tie-breaking, use ID
    $renumberQuery = "
        UPDATE tblpurchases p
        INNER JOIN (
            SELECT ID, ROW_NUMBER() OVER (ORDER BY 
                COALESCE(NULLIF(TP_DATE, '0000-00-00'), DATE) ASC,
                ID ASC
            ) AS new_voc_no
            FROM tblpurchases 
            WHERE CompID = ?
        ) AS ranked ON p.ID = ranked.ID
        SET p.VOC_NO = ranked.new_voc_no";
    
    $renumberStmt = $conn->prepare($renumberQuery);
    if ($renumberStmt) {
        $renumberStmt->bind_param("i", $companyId);
        $renumberStmt->execute();
        $affectedRows = $renumberStmt->affected_rows;
        $renumberStmt->close();
        
        debugLog("VOC_NO renumbered for company $companyId after import, affected rows: $affectedRows");
        return $affectedRows;
    } else {
        debugLog("VOC_NO renumbering failed: " . $conn->error);
        return -1;
    }
}

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

// Function to clean item code by removing SCM prefix
function cleanItemCode($code) {
    $cleaned = preg_replace('/^SCM/i', '', trim($code));
    debugLog("cleanItemCode: '$code' -> '$cleaned'");
    return $cleaned;
}

// Function to update MRP in tblitemmaster
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

// Function to normalize supplier names for better matching
function normalizeSupplierName($name) {
    if (empty($name)) return '';
    
    // Convert to lowercase
    $normalized = strtolower(trim($name));
    
    // Remove common suffixes and prefixes
    $removeWords = [
        'private', 'limited', 'ltd', 'pvt', 'ltd.', 'pvt.', 'llp', 'llp.',
        'traders', 'trading', 'company', 'co', 'co.', 'corporation', 'corp',
        'and', '&', 'the', 'ind.', 'industries', 'industry'
    ];
    
    foreach ($removeWords as $word) {
        $normalized = preg_replace('/\b' . preg_quote($word, '/') . '\b/', '', $normalized);
    }
    
    // Remove extra spaces and punctuation
    $normalized = preg_replace('/[^a-z0-9]/', ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);
    
    // Remove numbers at the end (common in supplier names like "ABC 123")
    $normalized = preg_replace('/\s+\d+$/', '', $normalized);
    
    return $normalized;
}

// Function to calculate similarity between two strings
function stringSimilarity($str1, $str2) {
    $str1 = normalizeSupplierName($str1);
    $str2 = normalizeSupplierName($str2);
    
    if (empty($str1) || empty($str2)) return 0;
    
    // Exact match after normalization
    if ($str1 === $str2) return 100;
    
    // Calculate Levenshtein distance
    $len1 = strlen($str1);
    $len2 = strlen($str2);
    $maxLen = max($len1, $len2);
    
    if ($maxLen == 0) return 0;
    
    $distance = levenshtein($str1, $str2);
    $similarity = (1 - $distance / $maxLen) * 100;
    
    return max(0, $similarity);
}

// Function to find supplier by name with improved matching
function findBestSupplierMatch($supplierName, $conn) {
    debugLog("Finding supplier match for", $supplierName);
    
    if (empty($supplierName)) {
        return null;
    }
    
    // First, try to get all suppliers for better matching
    $allSuppliers = [];
    $query = "SELECT CODE, DETAILS FROM tblsupplier";
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $allSuppliers[] = $row;
        }
    }
    
    debugLog("Total suppliers in database", count($allSuppliers));
    
    if (empty($allSuppliers)) {
        return null;
    }
    
    // Try different matching strategies
    $bestMatch = null;
    $bestScore = 0;
    $inputNormalized = normalizeSupplierName($supplierName);
    
    foreach ($allSuppliers as $supplier) {
        $dbName = $supplier['DETAILS'];
        $dbCode = $supplier['CODE'];
        $dbNormalized = normalizeSupplierName($dbName);
        
        $score = 0;
        
        // Strategy 1: Exact match (after normalization)
        if ($inputNormalized === $dbNormalized) {
            $score = 100;
        }
        // Strategy 2: Contains match (either way)
        elseif (strpos($inputNormalized, $dbNormalized) !== false || 
                strpos($dbNormalized, $inputNormalized) !== false) {
            $score = 85;
        }
        // Strategy 3: SCM code match (if supplier name contains SCM code)
        elseif (strpos($supplierName, $dbCode) !== false) {
            $score = 80;
        }
        // Strategy 4: String similarity
        else {
            $similarity = stringSimilarity($supplierName, $dbName);
            if ($similarity > 70) {
                $score = $similarity;
            }
        }
        
        // Strategy 5: Check for common abbreviations
        if ($score < 70) {
            $commonAbbreviations = [
                'traders' => 'tr',
                'trading' => 'tr',
                'limited' => 'ltd',
                'private' => 'pvt',
                'company' => 'co',
                'corporation' => 'corp'
            ];
            
            $inputTest = $inputNormalized;
            $dbTest = $dbNormalized;
            
            foreach ($commonAbbreviations as $full => $abbr) {
                $inputTest = str_replace($full, $abbr, $inputTest);
                $dbTest = str_replace($full, $abbr, $dbTest);
            }
            
            if ($inputTest === $dbTest) {
                $score = 75;
            }
        }
        
        // Strategy 6: Word-by-word matching
        if ($score < 60) {
            $inputWords = explode(' ', $inputNormalized);
            $dbWords = explode(' ', $dbNormalized);
            
            $matchingWords = 0;
            $totalWords = max(count($inputWords), count($dbWords));
            
            foreach ($inputWords as $inputWord) {
                foreach ($dbWords as $dbWord) {
                    if (strlen($inputWord) > 3 && strlen($dbWord) > 3) {
                        if (strpos($inputWord, $dbWord) !== false || 
                            strpos($dbWord, $inputWord) !== false) {
                            $matchingWords++;
                            break;
                        }
                    }
                }
            }
            
            if ($totalWords > 0) {
                $wordScore = ($matchingWords / $totalWords) * 100;
                if ($wordScore > 60) {
                    $score = max($score, $wordScore * 0.8);
                }
            }
        }
        
        // Update best match if this supplier has a higher score
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = $supplier;
            
            debugLog("New best match found", [
                'supplier' => $dbName,
                'code' => $dbCode,
                'score' => $score,
                'input_normalized' => $inputNormalized,
                'db_normalized' => $dbNormalized
            ]);
        }
    }
    
    // If we have a decent match (score > 60), return it
    if ($bestScore >= 60) {
        debugLog("Best supplier match selected", [
            'input' => $supplierName,
            'matched_name' => $bestMatch['DETAILS'],
            'matched_code' => $bestMatch['CODE'],
            'match_score' => $bestScore
        ]);
        return $bestMatch;
    }
    
    // Try partial matching in database as fallback
    if (!$bestMatch || $bestScore < 60) {
        $searchTerms = [];
        
        // Try without common words
        $cleanName = preg_replace('/\b(?:traders|trading|limited|ltd|private|pvt|company|co|corporation|corp|and|&|the)\b/i', '', $supplierName);
        $cleanName = trim(preg_replace('/\s+/', ' ', $cleanName));
        
        if (!empty($cleanName)) {
            $searchTerms[] = $cleanName;
        }
        
        // Try with first few words
        $words = explode(' ', $supplierName);
        if (count($words) > 2) {
            $searchTerms[] = implode(' ', array_slice($words, 0, 2));
        }
        
        foreach ($searchTerms as $term) {
            if (strlen($term) < 3) continue;
            
            $query = "SELECT CODE, DETAILS FROM tblsupplier WHERE DETAILS LIKE ? LIMIT 5";
            $stmt = $conn->prepare($query);
            $searchPattern = "%" . $term . "%";
            $stmt->bind_param("s", $searchPattern);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $suppliers = [];
                while ($row = $result->fetch_assoc()) {
                    $suppliers[] = $row;
                }
                
                // Find best among these partial matches
                foreach ($suppliers as $supplier) {
                    $score = stringSimilarity($supplierName, $supplier['DETAILS']);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = $supplier;
                    }
                }
            }
            $stmt->close();
            
            if ($bestScore >= 60) break;
        }
    }
    
    if ($bestMatch) {
        debugLog("Supplier match found via fallback", [
            'input' => $supplierName,
            'matched_name' => $bestMatch['DETAILS'],
            'matched_code' => $bestMatch['CODE'],
            'match_score' => $bestScore
        ]);
    } else {
        debugLog("No supplier match found for", $supplierName);
    }
    
    return $bestMatch;
}

// Function to find item by code
function findItem($itemCode, $conn, $allowed_classes) {
    $cleanCode = cleanItemCode($itemCode);
    
    debugLog("Finding item", [
        'original_code' => $itemCode,
        'clean_code' => $cleanCode
    ]);
    
    // First try exact match with SCM prefix
    $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.ITEM_GROUP, im.LIQ_FLAG, im.CLASS,
                     COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE,
                     CONCAT('SCM', im.CODE) AS SCM_CODE
              FROM tblitemmaster im
              LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
              WHERE CONCAT('SCM', im.CODE) = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $itemCode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        $stmt->close();
        debugLog("Item found by SCM code", $item);
        
        // Check if item class is allowed
        if (!empty($allowed_classes) && !in_array($item['CLASS'], $allowed_classes)) {
            debugLog("Item class not allowed by license", [
                'item_class' => $item['CLASS'],
                'allowed_classes' => $allowed_classes
            ]);
            return null;
        }
        
        return $item;
    }
    $stmt->close();
    
    // Try with clean code (without SCM prefix)
    if (!empty($allowed_classes)) {
        $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
        $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.ITEM_GROUP, im.LIQ_FLAG, im.CLASS,
                         COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE,
                         CONCAT('SCM', im.CODE) AS SCM_CODE
                  FROM tblitemmaster im
                  LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
                  WHERE im.CODE = ? 
                  AND im.CLASS IN ($class_placeholders)
                  LIMIT 1";
        
        $params = array_merge([$cleanCode], $allowed_classes);
        $types = str_repeat('s', count($params));
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
    } else {
        $query = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.ITEM_GROUP, im.LIQ_FLAG, im.CLASS,
                         COALESCE(sc.BOTTLE_PER_CASE, 12) AS BOTTLE_PER_CASE,
                         CONCAT('SCM', im.CODE) AS SCM_CODE
                  FROM tblitemmaster im
                  LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP AND im.LIQ_FLAG = sc.LIQ_FLAG
                  WHERE im.CODE = ?
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $cleanCode);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $item = $result->fetch_assoc();
        $stmt->close();
        debugLog("Item found by clean code", $item);
        return $item;
    }
    $stmt->close();
    
    debugLog("Item not found in database");
    return null;
}

// Function to check if a month is archived
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

// Function to update archived month stock with complete calculation including cascading
function updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    debugLog("Updating archived month stock", [
        'table' => $archive_table,
        'monthYear' => $monthYear,
        'itemCode' => $itemCode,
        'dayOfMonth' => $dayOfMonth,
        'totalBottles' => $totalBottles
    ]);
    
    // Check if record exists in archive table
    $check_query = "SELECT COUNT(*) as count FROM $archive_table 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Update existing record with complete calculation including sales
        $update_query = "UPDATE $archive_table 
                        SET $purchaseColumn = $purchaseColumn + ?,
                            $closingColumn = $openingColumn + $purchaseColumn - $saleColumn
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
        $result = $update_stmt->execute();
        $update_stmt->close();
        
        if ($result) {
            // Now update all subsequent days in the archived month (cascading effect)
            updateSubsequentDaysInTable($conn, $archive_table, $monthYear, $itemCode, $dayOfMonth);
        }
    } else {
        // For new record, opening is 0, so closing = purchase (no sales initially)
        $insert_query = "INSERT INTO $archive_table 
                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                        VALUES (?, ?, 'F', 0, ?, 0, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
        $result = $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    return $result;
}

// Function to update current month stock with proper cascading updates
function updateCurrentMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    $dailyStockTable = "tbldailystock_" . $comp_id;
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    debugLog("Updating current month stock", [
        'table' => $dailyStockTable,
        'monthYear' => $monthYear,
        'itemCode' => $itemCode,
        'dayOfMonth' => $dayOfMonth,
        'totalBottles' => $totalBottles
    ]);
    
    // Check if daily stock record exists for this month and item
    $checkDailyStockQuery = "SELECT COUNT(*) as count FROM $dailyStockTable 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $checkStmt = $conn->prepare($checkDailyStockQuery);
    $checkStmt->bind_param("ss", $monthYear, $itemCode);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $row = $result->fetch_assoc();
    $checkStmt->close();
    
    if ($row['count'] > 0) {
        // Update existing record with complete calculation including sales
        $updateDailyStockQuery = "UPDATE $dailyStockTable 
                                 SET $purchaseColumn = $purchaseColumn + ?,
                                     $closingColumn = $openingColumn + $purchaseColumn - $saleColumn
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $dailyStmt = $conn->prepare($updateDailyStockQuery);
        $dailyStmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
        $result = $dailyStmt->execute();
        $dailyStmt->close();
        
        if ($result) {
            // Now update all subsequent days' opening and closing values with cascading effect
            updateSubsequentDaysInTable($conn, $dailyStockTable, $monthYear, $itemCode, $dayOfMonth);
        }
    } else {
        // For new record, opening is 0, so closing = purchase (no sales initially)
        $insertDailyStockQuery = "INSERT INTO $dailyStockTable 
                                 (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                                 VALUES (?, ?, 'F', 0, ?, 0, ?)";
        $dailyStmt = $conn->prepare($insertDailyStockQuery);
        $dailyStmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
        $result = $dailyStmt->execute();
        $dailyStmt->close();
    }
    
    return $result;
}

// Universal function to update subsequent days' opening and closing values with cascading effect
// Works for both current and archived tables
function updateSubsequentDaysInTable($conn, $table, $monthYear, $itemCode, $purchaseDay) {
    debugLog("Starting cascading updates in table", [
        'table' => $table,
        'monthYear' => $monthYear,
        'itemCode' => $itemCode,
        'purchaseDay' => $purchaseDay
    ]);
    
    // Get the number of days in the month
    $timestamp = strtotime($monthYear . "-01");
    $daysInMonth = date('t', $timestamp); // 28, 29, 30, or 31
    
    debugLog("Month has $daysInMonth days", [
        'timestamp' => date('Y-m-d', $timestamp),
        'daysInMonth' => $daysInMonth
    ]);
    
    // Update opening for next day (carry forward from previous day's closing)
    // Only iterate through actual days in the month
    for ($day = $purchaseDay + 1; $day <= $daysInMonth; $day++) {
        $prevDay = $day - 1;
        $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        // Check if the columns exist in the table
        $checkColumnsQuery = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = '$table' 
                            AND COLUMN_NAME IN ('$currentDayOpening', '$currentDayPurchase', '$currentDaySales', '$currentDayClosing')";
        
        $checkResult = $conn->query($checkColumnsQuery);
        $columnsExist = $checkResult->num_rows >= 4; // All 4 columns should exist
        
        if ($columnsExist) {
            // Update opening to previous day's closing, and recalculate closing
            $updateQuery = "UPDATE $table 
                           SET $currentDayOpening = $prevDayClosing,
                               $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            
            debugLog("Cascading update for day $day", [
                'query' => $updateQuery,
                'prevDayClosing' => $prevDayClosing,
                'columns_exist' => true
            ]);
            
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("ss", $monthYear, $itemCode);
            $stmt->execute();
            $stmt->close();
        } else {
            debugLog("Skipping day $day - columns don't exist", [
                'columns_checked' => [$currentDayOpening, $currentDayPurchase, $currentDaySales, $currentDayClosing],
                'columns_found' => $checkResult->num_rows
            ]);
        }
        $checkResult->free();
    }
    
    debugLog("Cascading updates completed for all days after purchase day");
}

// Function to continue cascading from archived month to current month
function continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate, $fy_end_date = null) {
    // Determine the correct end date based on whether it's a previous FY or current FY
    // If fy_end_date is provided as null or equals purchase date, calculate based on purchase date
    if ($fy_end_date === null || $fy_end_date === $purchaseDate) {
        // Check if purchase is in previous financial year
        if (isPreviousFinancialYear($purchaseDate)) {
            $fy_end_date = getFinancialYearEndDate($purchaseDate);
        } else {
            // For current year, use today's date
            $fy_end_date = date('Y-m-d');
        }
    }
    
    debugLog("Continuing cascading to FY end", [
        'comp_id' => $comp_id,
        'itemCode' => $itemCode,
        'purchaseDate' => $purchaseDate,
        'fy_end_date' => $fy_end_date
    ]);
    
    $purchaseDay = date('j', strtotime($purchaseDate));
    $purchaseMonth = date('n', strtotime($purchaseDate));
    $purchaseYear = date('Y', strtotime($purchaseDate));
    $currentDay = date('j');
    $currentMonth = date('n');
    $currentYear = date('Y');
    
    // Calculate the end month/year based on financial year
    $fyEndMonth = (int)date('n', strtotime($fy_end_date));
    $fyEndYear = (int)date('Y', strtotime($fy_end_date));
    
    // Get current date components for comparison
    $currentDay = (int)date('j');
    $currentMonth = (int)date('n');
    $currentYear = (int)date('Y');
    
    debugLog("FY end info vs current date", [
        'fyEndMonth' => $fyEndMonth,
        'fyEndYear' => $fyEndYear,
        'currentMonth' => $currentMonth,
        'currentYear' => $currentYear,
        'purchaseMonth' => $purchaseMonth,
        'purchaseYear' => $purchaseYear
    ]);
    
    // If purchase is in current month and year, cascading has already been handled in that month's table
    // For previous months in current FY, we need to cascade
    if ($purchaseMonth == $currentMonth && $purchaseYear == $currentYear) {
        debugLog("Purchase is in current month, cascading already handled");
        return;
    }
    
    // Start from the next month after purchase
    $startMonth = $purchaseMonth + 1;
    $startYear = $purchaseYear;
    if ($startMonth > 12) {
        $startMonth = 1;
        $startYear++;
    }
    
    debugLog("Starting cascading from month", [
        'startMonth' => $startMonth,
        'startYear' => $startYear,
        'fyEndMonth' => $fyEndMonth,
        'fyEndYear' => $fyEndYear
    ]);
    
    // Loop through months from purchase month+1 to end of financial year
    while (($startYear < $fyEndYear) || ($startYear == $fyEndYear && $startMonth <= $fyEndMonth)) {
        $month_2digit = str_pad($startMonth, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($startYear, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        
        // Check if this month's table exists (archived or current)
        $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name = '$archive_table'";
        $check_result = $conn->query($check_table_query);
        $table_exists = $check_result->fetch_assoc()['count'] > 0;
        
        if ($table_exists) {
            debugLog("Found table for cascading", [
                'table' => $archive_table,
                'month' => $startMonth,
                'year' => $startYear
            ]);
            
            $monthYear = date('Y-m', strtotime("$startYear-$startMonth-01"));
            
            // Get days in this month
            $daysInMonth = date('t', strtotime("$startYear-$startMonth-01"));
            
            // For the first month after purchase, opening should come from previous month's last day
            if ($startMonth == $purchaseMonth + 1 || ($startMonth == 1 && $purchaseMonth == 12)) {
                // Get previous month's last day closing
                $prevMonth = $purchaseMonth;
                $prevYear = $purchaseYear;
                $prevMonthDays = date('t', strtotime("$prevYear-$prevMonth-01"));
                
                $prevMonth_2digit = str_pad($prevMonth, 2, '0', STR_PAD_LEFT);
                $prevYear_2digit = substr($prevYear, -2);
                $prevTable = "tbldailystock_{$comp_id}_{$prevMonth_2digit}_{$prevYear_2digit}";
                
                $prevClosingColumn = "DAY_" . str_pad($prevMonthDays, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                
                $getPrevClosingQuery = "SELECT $prevClosingColumn as closing FROM $prevTable 
                                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prevStmt = $conn->prepare($getPrevClosingQuery);
                $prevMonthYear = date('Y-m', strtotime("$prevYear-$prevMonth-01"));
                $prevStmt->bind_param("ss", $prevMonthYear, $itemCode);
                $prevStmt->execute();
                $prevResult = $prevStmt->get_result();
                $prevRow = $prevResult->fetch_assoc();
                $prevStmt->close();
                
                $openingValue = $prevRow ? $prevRow['closing'] : 0;
                
                debugLog("Got opening value from previous month", [
                    'prevTable' => $prevTable,
                    'prevClosingColumn' => $prevClosingColumn,
                    'openingValue' => $openingValue
                ]);
                
                // Update the first day of this month with the opening value
                $updateOpeningQuery = "UPDATE $archive_table 
                                      SET DAY_01_OPEN = ?,
                                          DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $openingStmt = $conn->prepare($updateOpeningQuery);
                $openingStmt->bind_param("iss", $openingValue, $monthYear, $itemCode);
                $openingStmt->execute();
                $openingStmt->close();
                
                // Now cascade through the rest of this month
                for ($day = 2; $day <= $daysInMonth; $day++) {
                    $prevDay = $day - 1;
                    $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                    $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
                    $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
                    $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
                    $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                    
                    $updateDayQuery = "UPDATE $archive_table 
                                      SET $currentDayOpening = $prevDayClosing,
                                          $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                    
                    $dayStmt = $conn->prepare($updateDayQuery);
                    $dayStmt->bind_param("ss", $monthYear, $itemCode);
                    $dayStmt->execute();
                    $dayStmt->close();
                }
            } else {
                // For subsequent months, cascade from day 1
                updateSubsequentDaysInTable($conn, $archive_table, $monthYear, $itemCode, 1);
            }
        }
        
        // Move to next month
        $startMonth++;
        if ($startMonth > 12) {
            $startMonth = 1;
            $startYear++;
        }
    }
    
    // If we've reached end of financial year, ensure that month is also updated
    // (only if financial year end is before current month/year)
    if (($fyEndYear < $currentYear) || ($fyEndYear == $currentYear && $fyEndMonth < $currentMonth)) {
        // We need to update up to fy end month, not current month
        $cascadeEndMonth = $fyEndMonth;
        $cascadeEndYear = $fyEndYear;
    } else {
        // Financial year hasn't ended yet, use current month
        $cascadeEndMonth = $currentMonth;
        $cascadeEndYear = $currentYear;
    }
    
    if ($cascadeEndMonth != $purchaseMonth || $cascadeEndYear != $purchaseYear) {
        $dailyStockTable = "tbldailystock_" . $comp_id;
        $currentMonthYear = date('Y-m');
        
        // Check if record exists in current month table
        $checkCurrentQuery = "SELECT COUNT(*) as count FROM $dailyStockTable 
                             WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $checkCurrentStmt = $conn->prepare($checkCurrentQuery);
        $checkCurrentStmt->bind_param("ss", $currentMonthYear, $itemCode);
        $checkCurrentStmt->execute();
        $currentResult = $checkCurrentStmt->get_result();
        $currentExists = $currentResult->fetch_assoc()['count'] > 0;
        $checkCurrentStmt->close();
        
        if ($currentExists) {
            // Get previous month's last day closing for opening value
            $prevMonth = $currentMonth - 1;
            $prevYear = $currentYear;
            if ($prevMonth < 1) {
                $prevMonth = 12;
                $prevYear--;
            }
            
            $prevMonthDays = date('t', strtotime("$prevYear-$prevMonth-01"));
            $prevMonth_2digit = str_pad($prevMonth, 2, '0', STR_PAD_LEFT);
            $prevYear_2digit = substr($prevYear, -2);
            $prevTable = "tbldailystock_{$comp_id}_{$prevMonth_2digit}_{$prevYear_2digit}";
            
            // Check if previous table exists
            $checkPrevTableQuery = "SELECT COUNT(*) as count FROM information_schema.tables 
                                   WHERE table_schema = DATABASE() 
                                   AND table_name = '$prevTable'";
            $checkPrevResult = $conn->query($checkPrevTableQuery);
            $prevTableExists = $checkPrevResult->fetch_assoc()['count'] > 0;
            
            if ($prevTableExists) {
                $prevClosingColumn = "DAY_" . str_pad($prevMonthDays, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                $prevMonthYear = date('Y-m', strtotime("$prevYear-$prevMonth-01"));
                
                $getPrevClosingQuery = "SELECT $prevClosingColumn as closing FROM $prevTable 
                                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $prevStmt = $conn->prepare($getPrevClosingQuery);
                $prevStmt->bind_param("ss", $prevMonthYear, $itemCode);
                $prevStmt->execute();
                $prevResult = $prevStmt->get_result();
                $prevRow = $prevResult->fetch_assoc();
                $prevStmt->close();
                
                $openingValue = $prevRow ? $prevRow['closing'] : 0;
                
                // Update current month's day 1 opening
                $updateCurrentOpeningQuery = "UPDATE $dailyStockTable 
                                            SET DAY_01_OPEN = ?,
                                                DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $currentOpeningStmt = $conn->prepare($updateCurrentOpeningQuery);
                $currentOpeningStmt->bind_param("iss", $openingValue, $currentMonthYear, $itemCode);
                $currentOpeningStmt->execute();
                $currentOpeningStmt->close();
            }
            
            // Cascade through current month up to today (or end of month)
            $daysInCurrentMonth = date('t');
            $cascadeTo = min($currentDay, $daysInCurrentMonth);
            
            for ($day = 2; $day <= $cascadeTo; $day++) {
                $prevDay = $day - 1;
                $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
                $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
                $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
                $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                
                $updateDayQuery = "UPDATE $dailyStockTable 
                                  SET $currentDayOpening = $prevDayClosing,
                                      $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                                  WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                
                $dayStmt = $conn->prepare($updateDayQuery);
                $dayStmt->bind_param("ss", $currentMonthYear, $itemCode);
                $dayStmt->execute();
                $dayStmt->close();
            }
        }
    }
    
    debugLog("Cascading completed up to financial year end: " . $fy_end_date);
}

// Function to update item stock
function updateItemStock($conn, $itemCode, $totalBottles, $companyId) {
    $stockColumn = "CURRENT_STOCK" . $companyId;
    
    // Check if record exists
    $checkQuery = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("s", $itemCode);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $exists = $checkResult->fetch_assoc()['count'] > 0;
    $checkStmt->close();
    
    if ($exists) {
        // Add to existing stock
        $updateItemStockQuery = "UPDATE tblitem_stock 
                                SET $stockColumn = $stockColumn + ? 
                                WHERE ITEM_CODE = ?";
        
        $itemStmt = $conn->prepare($updateItemStockQuery);
        $itemStmt->bind_param("is", $totalBottles, $itemCode);
        $result = $itemStmt->execute();
        $itemStmt->close();
    } else {
        // Insert new stock record
        $insertItemStockQuery = "INSERT INTO tblitem_stock (ITEM_CODE, $stockColumn) 
                                VALUES (?, ?)";
        
        $itemStmt = $conn->prepare($insertItemStockQuery);
        $itemStmt->bind_param("si", $itemCode, $totalBottles);
        $result = $itemStmt->execute();
        $itemStmt->close();
    }
    
    return $result;
}

// Function to cascade stock through all months of a previous financial year
// Updates from purchase month+1 until March of that FY
function cascadeToFinancialYearEnd($conn, $comp_id, $item_code, $purchase_date, $total_bottles) {
    $purchase_timestamp = strtotime($purchase_date);
    $purchase_month = date('n', $purchase_timestamp);
    $purchase_year = date('Y', $purchase_timestamp);
    
    // Get financial year end date (use function from stock_functions.php)
    $fy_end_date = getFinancialYearEndDate($purchase_date);
    $fy_end_month = (int)date('n', strtotime($fy_end_date));
    $fy_end_year = (int)date('Y', strtotime($fy_end_date));
    
    debugLog("Cascading to FY end for previous year purchase", [
        'item_code' => $item_code,
        'purchase_date' => $purchase_date,
        'purchase_month' => $purchase_month,
        'purchase_year' => $purchase_year,
        'fy_end_date' => $fy_end_date,
        'fy_end_month' => $fy_end_month,
        'fy_end_year' => $fy_end_year
    ]);
    
    // Start from the next month after purchase
    $start_month = $purchase_month + 1;
    $start_year = $purchase_year;
    
    if ($start_month > 12) {
        $start_month = 1;
        $start_year++;
    }
    
    debugLog("Starting cascade from", ['start_month' => $start_month, 'start_year' => $start_year]);
    
    // Get the closing value from the purchase month to use as opening for next month
    $purchase_month_2digit = str_pad($purchase_month, 2, '0', STR_PAD_LEFT);
    $purchase_year_2digit = substr($purchase_year, -2);
    $purchase_table = "tbldailystock_{$comp_id}_{$purchase_month_2digit}_{$purchase_year_2digit}";
    
    // Find the last day of purchase month with the purchase
    $days_in_purchase_month = date('t', $purchase_timestamp);
    
    $last_day_with_purchase = 0;
    for ($day = $days_in_purchase_month; $day >= 1; $day--) {
        $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
        $purchase_col = "DAY_{$day_str}_PURCHASE";
        $check_query = "SELECT $purchase_col as purch FROM $purchase_table WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $check_stmt = $conn->prepare($check_query);
        $purchase_month_year = date('Y-m', strtotime($purchase_date));
        $check_stmt->bind_param("ss", $item_code, $purchase_month_year);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result->num_rows > 0) {
            $row = $check_result->fetch_assoc();
            if ($row['purch'] > 0) {
                $last_day_with_purchase = $day;
                break;
            }
        }
        $check_stmt->close();
    }
    
    // Get the closing from the last day with purchase
    $closing_value = 0;
    if ($last_day_with_purchase > 0) {
        $last_day_str = str_pad($last_day_with_purchase, 2, '0', STR_PAD_LEFT);
        $closing_col = "DAY_{$last_day_str}_CLOSING";
        $get_closing_query = "SELECT $closing_col as closing FROM $purchase_table WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $closing_stmt = $conn->prepare($get_closing_query);
        $closing_stmt->bind_param("ss", $item_code, $purchase_month_year);
        $closing_stmt->execute();
        $closing_result = $closing_stmt->get_result();
        if ($closing_result->num_rows > 0) {
            $closing_row = $closing_result->fetch_assoc();
            $closing_value = (int)$closing_row['closing'];
        }
        $closing_stmt->close();
    }
    
    debugLog("Last day with purchase: $last_day_with_purchase, closing value: $closing_value");
    
    // Loop through months from purchase month+1 to end of financial year (March)
    while ($start_year < $fy_end_year || ($start_year == $fy_end_year && $start_month <= $fy_end_month)) {
        $month_2digit = str_pad($start_month, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($start_year, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        
        debugLog("Processing month", ['table' => $archive_table, 'month' => $start_month, 'year' => $start_year]);
        
        // Check if this month's table exists
        $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name = '$archive_table'";
        $check_result = $conn->query($check_table_query);
        $table_exists = $check_result ? $check_result->fetch_assoc()['count'] > 0 : false;
        
        // If table doesn't exist, create it
        if (!$table_exists) {
            $days_in_month = date('t', strtotime("$start_year-$start_month-01"));
            
            $create_query = "CREATE TABLE $archive_table (
                `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
                `STK_DATE` date NOT NULL,
                `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
                `ITEM_CODE` varchar(20) NOT NULL,
                `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
                `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),";
            
            for ($day = 1; $day <= $days_in_month; $day++) {
                $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
                $create_query .= "
                `DAY_{$day_padded}_OPEN` int(11) DEFAULT 0,
                `DAY_{$day_padded}_PURCHASE` int(11) DEFAULT 0,
                `DAY_{$day_padded}_SALES` int(11) DEFAULT 0,
                `DAY_{$day_padded}_CLOSING` int(11) DEFAULT 0,";
            }
            
            $create_query .= "
                PRIMARY KEY (`DailyStockID`),
                UNIQUE KEY `unique_daily_stock` (`STK_DATE`, `ITEM_CODE`),
                KEY `idx_item_code` (`ITEM_CODE`),
                KEY `idx_liq_flag` (`LIQ_FLAG`),
                KEY `idx_stk_month` (`STK_MONTH`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
            
            if ($conn->query($create_query)) {
                debugLog("Created archive table: $archive_table");
                $table_exists = true;
            }
        }
        
        if ($table_exists) {
            $monthYear = date('Y-m', strtotime("$start_year-$start_month-01"));
            $daysInMonth = date('t', strtotime("$start_year-$start_month-01"));
            
            // Check if record exists
            $checkRecordQuery = "SELECT COUNT(*) as count FROM $archive_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $checkRecordStmt = $conn->prepare($checkRecordQuery);
            $checkRecordStmt->bind_param("ss", $monthYear, $item_code);
            $checkRecordStmt->execute();
            $recordResult = $checkRecordStmt->get_result();
            $recordExists = $recordResult->fetch_assoc()['count'] > 0;
            $checkRecordStmt->close();
            
            if (!$recordExists) {
                // Insert new record with opening value from previous month
                $insertQuery = "INSERT INTO $archive_table (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                               VALUES (?, ?, 'F', ?, 0, 0, ?)";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("ssii", $monthYear, $item_code, $closing_value, $closing_value);
                $insertStmt->execute();
                $insertStmt->close();
                
                debugLog("Inserted new record in $archive_table with opening=$closing_value");
            } else {
                // Update existing record - set day 1 opening from previous month
                $updateOpeningQuery = "UPDATE $archive_table 
                                      SET DAY_01_OPEN = ?,
                                          DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $openingStmt = $conn->prepare($updateOpeningQuery);
                $openingStmt->bind_param("iss", $closing_value, $monthYear, $item_code);
                $openingStmt->execute();
                $openingStmt->close();
                
                debugLog("Updated existing record in $archive_table with opening=$closing_value");
            }
            
            // Cascade through all days of this month
            for ($day = 2; $day <= $daysInMonth; $day++) {
                $prevDay = $day - 1;
                $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
                $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
                $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
                $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
                
                $updateDayQuery = "UPDATE $archive_table 
                                  SET $currentDayOpening = $prevDayClosing,
                                      $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                                  WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                
                $dayStmt = $conn->prepare($updateDayQuery);
                $dayStmt->bind_param("ss", $monthYear, $item_code);
                $dayStmt->execute();
                $dayStmt->close();
            }
            
            // Get the closing value from this month to use for next month
            $lastDayStr = str_pad($daysInMonth, 2, '0', STR_PAD_LEFT);
            $lastDayClosingCol = "DAY_{$lastDayStr}_CLOSING";
            $getClosingQuery = "SELECT $lastDayClosingCol as closing FROM $archive_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $getClosingStmt = $conn->prepare($getClosingQuery);
            $getClosingStmt->bind_param("ss", $monthYear, $item_code);
            $getClosingStmt->execute();
            $closingResult = $getClosingStmt->get_result();
            if ($closingResult->num_rows > 0) {
                $closingRow = $closingResult->fetch_assoc();
                $closing_value = (int)$closingRow['closing'];
            }
            $getClosingStmt->close();
            
            debugLog("Got closing value for next month: $closing_value");
        }
        
        // Move to next month
        $start_month++;
        if ($start_month > 12) {
            $start_month = 1;
            $start_year++;
        }
    }
    
    debugLog("Cascading completed to FY end: " . $fy_end_date);
}

// Function to update stock for purchases in previous financial years
function updatePreviousYearStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    debugLog("Updating previous year stock", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate
    ]);
    
    // Update archived month stock
    updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
    
    // Cascade through all remaining months until FY end (March)
    // This handles all months from purchase month+1 through March of that FY
    cascadeToFinancialYearEnd($conn, $comp_id, $itemCode, $purchaseDate, $totalBottles);
    
    // Update tblitem_stock
    updateItemStock($conn, $itemCode, $totalBottles, $comp_id);
}

// Function to update stock for purchases in current financial year
function updateCurrentYearStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    debugLog("Updating current year stock", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate
    ]);
    
    // Check if this month is archived
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $isArchived = isMonthArchived($conn, $comp_id, $month, $year);
    
    if ($isArchived) {
        debugLog("Month is archived, updating archive table with cascading");
        // Update archived month data with cascading
        updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
        
        // Continue cascading to current month (within same FY)
        continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate, null);
    } else {
        debugLog("Month is current, updating current table with cascading");
        // Update current month data with cascading
        updateCurrentMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
    }
    
    // Update tblitem_stock
    updateItemStock($conn, $itemCode, $totalBottles, $comp_id);
}

// Function to update stock after purchase - Main Entry Point
// DETERMINES whether to use current year or previous year logic (matching purchases.php)
function updateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn) {
    debugLog("updateStock called", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'company_id' => $companyId
    ]);
    
    // Determine if purchase is in current or previous financial year
    // Use the function from stock_functions.php
    if (isPreviousFinancialYear($purchaseDate)) {
        // USE NEW LOGIC for previous financial years
        debugLog("Using PREVIOUS YEAR logic for stock update");
        updatePreviousYearStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    } else {
        // USE EXISTING LOGIC for current financial year
        debugLog("Using CURRENT YEAR logic for stock update");
        updateCurrentYearStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    }
}

// Batch stock update function - adds bottles first, then cascades once
// This avoids the issue of cascading being done multiple times for each item
function batchUpdateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn) {
    debugLog("batchUpdateStock called", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'company_id' => $companyId
    ]);
    
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    // Check if purchase is in previous financial year
    if (isPreviousFinancialYear($purchaseDate)) {
        debugLog("Using PREVIOUS YEAR logic for batch stock update");
        
        // For previous year: update archived month first
        $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($year, -2);
        $archive_table = "tbldailystock_{$companyId}_{$month_2digit}_{$year_2digit}";
        
        $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
        $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        // Check if archive table exists
        $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name = '$archive_table'";
        $check_result = $conn->query($check_table_query);
        $table_exists = $check_result ? $check_result->fetch_assoc()['count'] > 0 : false;
        
        if (!$table_exists) {
            // Create archive table
            createArchiveTable($conn, $companyId, $month, $year);
        }
        
        // Check if record exists
        $check_record_query = "SELECT COUNT(*) as count FROM $archive_table 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $check_record_stmt = $conn->prepare($check_record_query);
        $check_record_stmt->bind_param("ss", $monthYear, $itemCode);
        $check_record_stmt->execute();
        $record_result = $check_record_stmt->get_result();
        $record_exists = $record_result->fetch_assoc()['count'] > 0;
        $check_record_stmt->close();
        
        if ($record_exists) {
            // Update existing record - add to purchase and recalculate closing
            $update_query = "UPDATE $archive_table 
                            SET $purchaseColumn = $purchaseColumn + ?,
                                $closingColumn = $openingColumn + $purchaseColumn - $saleColumn
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
            $update_stmt->execute();
            $update_stmt->close();
        } else {
            // Insert new record
            $insert_query = "INSERT INTO $archive_table 
                            (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                            VALUES (?, ?, 'F', 0, ?, 0, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
            $insert_stmt->execute();
            $insert_stmt->close();
        }
        
        // Now cascade once to all subsequent days in this month
        cascadeSubsequentDaysInMonth($conn, $archive_table, $monthYear, $itemCode, $dayOfMonth);
        
        // Cascade to all months until FY end (only once)
        cascadeToFinancialYearEnd($conn, $companyId, $itemCode, $purchaseDate, $totalBottles);
        
    } else {
        debugLog("Using CURRENT YEAR logic for batch stock update");
        
        // Check if month is archived
        $isArchived = isMonthArchived($conn, $companyId, $month, $year);
        
        if ($isArchived) {
            // Update archived month
            $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
            $year_2digit = substr($year, -2);
            $archive_table = "tbldailystock_{$companyId}_{$month_2digit}_{$year_2digit}";
            
            $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
            $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
            $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
            $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
            
            // Check if table exists
            $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                                 WHERE table_schema = DATABASE() 
                                 AND table_name = '$archive_table'";
            $check_result = $conn->query($check_table_query);
            $table_exists = $check_result ? $check_result->fetch_assoc()['count'] > 0 : false;
            
            if (!$table_exists) {
                createArchiveTable($conn, $companyId, $month, $year);
            }
            
            // Check if record exists
            $check_record_query = "SELECT COUNT(*) as count FROM $archive_table 
                                  WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $check_record_stmt = $conn->prepare($check_record_query);
            $check_record_stmt->bind_param("ss", $monthYear, $itemCode);
            $check_record_stmt->execute();
            $record_result = $check_record_stmt->get_result();
            $record_exists = $record_result->fetch_assoc()['count'] > 0;
            $check_record_stmt->close();
            
            if ($record_exists) {
                $update_query = "UPDATE $archive_table 
                                SET $purchaseColumn = $purchaseColumn + ?,
                                    $closingColumn = $openingColumn + $purchaseColumn - $saleColumn
                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                $insert_query = "INSERT INTO $archive_table 
                                (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                                VALUES (?, ?, 'F', 0, ?, 0, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            
            // Cascade once to subsequent days
            cascadeSubsequentDaysInMonth($conn, $archive_table, $monthYear, $itemCode, $dayOfMonth);
            
            // Continue cascading to current month
            continueCascadingToCurrentMonth($conn, $companyId, $itemCode, $purchaseDate, null);
            
        } else {
            // Update current month table
            $dailyStockTable = "tbldailystock_" . $companyId;
            
            $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
            $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
            $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
            $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
            
            // Check if table exists
            $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                                 WHERE table_schema = DATABASE() 
                                 AND table_name = '$dailyStockTable'";
            $check_result = $conn->query($check_table_query);
            $table_exists = $check_result ? $check_result->fetch_assoc()['count'] > 0 : false;
            
            if (!$table_exists) {
                // Create current month table
                createCurrentMonthTable($conn, $companyId);
            }
            
            // Check if record exists
            $check_record_query = "SELECT COUNT(*) as count FROM $dailyStockTable 
                                  WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $check_record_stmt = $conn->prepare($check_record_query);
            $check_record_stmt->bind_param("ss", $monthYear, $itemCode);
            $check_record_stmt->execute();
            $record_result = $check_record_stmt->get_result();
            $record_exists = $record_result->fetch_assoc()['count'] > 0;
            $check_record_stmt->close();
            
            if ($record_exists) {
                $update_query = "UPDATE $dailyStockTable 
                                SET $purchaseColumn = $purchaseColumn + ?,
                                    $closingColumn = $openingColumn + $purchaseColumn - $saleColumn
                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
                $update_stmt->execute();
                $update_stmt->close();
            } else {
                $insert_query = "INSERT INTO $dailyStockTable 
                                (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                                VALUES (?, ?, 'F', 0, ?, 0, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("ssii", $monthYear, $itemCode, $totalBottles, $totalBottles);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            
            // Cascade once to subsequent days in current month
            cascadeSubsequentDaysInMonth($conn, $dailyStockTable, $monthYear, $itemCode, $dayOfMonth);
        }
    }
    
    // Update tblitem_stock
    updateItemStock($conn, $itemCode, $totalBottles, $companyId);
}

// Helper function to cascade subsequent days in a month (single pass)
function cascadeSubsequentDaysInMonth($conn, $table, $monthYear, $itemCode, $purchaseDay) {
    $timestamp = strtotime($monthYear . "-01");
    $daysInMonth = date('t', $timestamp);
    
    debugLog("Cascading subsequent days in month (batch)", [
        'table' => $table,
        'monthYear' => $monthYear,
        'itemCode' => $itemCode,
        'purchaseDay' => $purchaseDay,
        'daysInMonth' => $daysInMonth
    ]);
    
    for ($day = $purchaseDay + 1; $day <= $daysInMonth; $day++) {
        $prevDay = $day - 1;
        $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        $updateQuery = "UPDATE $table 
                       SET $currentDayOpening = $prevDayClosing,
                           $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        
        $stmt = $conn->prepare($updateQuery);
        $stmt->bind_param("ss", $monthYear, $itemCode);
        $stmt->execute();
        $stmt->close();
    }
    
    debugLog("Batch cascading completed for month");
}

// Helper function to create archive table
function createArchiveTable($conn, $comp_id, $month, $year) {
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    $days_in_month = date('t', strtotime("$year-$month-01"));
    
    $create_query = "CREATE TABLE $archive_table (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_DATE` date NOT NULL,
        `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),";
    
    for ($day = 1; $day <= $days_in_month; $day++) {
        $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
        $create_query .= "
        `DAY_{$day_padded}_OPEN` int(11) DEFAULT 0,
        `DAY_{$day_padded}_PURCHASE` int(11) DEFAULT 0,
        `DAY_{$day_padded}_SALES` int(11) DEFAULT 0,
        `DAY_{$day_padded}_CLOSING` int(11) DEFAULT 0,";
    }
    
    $create_query .= "
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock` (`STK_DATE`, `ITEM_CODE`),
        KEY `idx_item_code` (`ITEM_CODE`),
        KEY `idx_liq_flag` (`LIQ_FLAG`),
        KEY `idx_stk_month` (`STK_MONTH`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $conn->query($create_query);
    debugLog("Created archive table: $archive_table");
}

// Helper function to create current month table
function createCurrentMonthTable($conn, $comp_id) {
    $dailyStockTable = "tbldailystock_" . $comp_id;
    $days_in_month = date('t');
    
    $create_query = "CREATE TABLE $dailyStockTable (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_DATE` date NOT NULL,
        `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),";
    
    for ($day = 1; $day <= $days_in_month; $day++) {
        $day_padded = str_pad($day, 2, '0', STR_PAD_LEFT);
        $create_query .= "
        `DAY_{$day_padded}_OPEN` int(11) DEFAULT 0,
        `DAY_{$day_padded}_PURCHASE` int(11) DEFAULT 0,
        `DAY_{$day_padded}_SALES` int(11) DEFAULT 0,
        `DAY_{$day_padded}_CLOSING` int(11) DEFAULT 0,";
    }
    
    $create_query .= "
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock` (`STK_DATE`, `ITEM_CODE`),
        KEY `idx_item_code` (`ITEM_CODE`),
        KEY `idx_liq_flag` (`LIQ_FLAG`),
        KEY `idx_stk_month` (`STK_MONTH`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $conn->query($create_query);
    debugLog("Created current month table: $dailyStockTable");
}

// ============================================================================
// HTML TABLE PROCESSOR - For .xls files that contain HTML table data
// ============================================================================
function processHTMLTableFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName = '') {
    debugLog("Processing HTML table file: " . $fileName);
    
    // Read the entire file content
    $content = file_get_contents($filePath);
    if (!$content) {
        return ['successCount' => 0, 'errorCount' => 1, 'errors' => ["Cannot read file: $fileName"]];
    }
    
    // Parse the HTML
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($content);
    
    // Find all tables
    $tables = $dom->getElementsByTagName('table');
    if ($tables->length == 0) {
        return ['successCount' => 0, 'errorCount' => 1, 'errors' => ["No table found in file: $fileName"]];
    }
    
    $table = $tables->item(0);
    $rows = $table->getElementsByTagName('tr');
    
    // Extract all data rows
    $allRows = [];
    $headers = [];
    $foundHeaders = false;
    
    foreach ($rows as $row) {
        $cells = $row->getElementsByTagName('td');
        $rowData = [];
        
        foreach ($cells as $cell) {
            $cellText = trim($cell->textContent);
            $rowData[] = $cellText;
        }
        
        if (empty($rowData)) continue;
        
        // Check if this is the header row
        $rowStr = array_map('strtoupper', $rowData);
        $hasReceivedDate = false;
        $hasAutoTP = false;
        $hasItemCode = false;
        
        foreach ($rowStr as $cell) {
            if (strpos($cell, 'RECEIVED DATE') !== false) $hasReceivedDate = true;
            if (strpos($cell, 'AUTO TP NO') !== false) $hasAutoTP = true;
            if (strpos($cell, 'SCM ITEM CODE') !== false) $hasItemCode = true;
        }
        
        if ($hasReceivedDate && $hasAutoTP && $hasItemCode && !$foundHeaders) {
            $headers = $rowData;
            $foundHeaders = true;
            debugLog("Found header row with " . count($headers) . " columns");
            continue;
        }
        
        // If we found headers, add data rows
        if ($foundHeaders && count($rowData) >= 10) {
            $nonEmptyCount = count(array_filter($rowData, function($val) {
                return !empty($val) && trim($val) !== '';
            }));
            
            if ($nonEmptyCount >= 5) {
                $allRows[] = $rowData;
            }
        }
    }
    
    if (!$foundHeaders) {
        return ['successCount' => 0, 'errorCount' => 1, 'errors' => ["No header row found in file: $fileName"]];
    }
    
    if (empty($allRows)) {
        return ['successCount' => 0, 'errorCount' => 1, 'errors' => ["No data rows found in file: $fileName"]];
    }
    
    debugLog("Extracted " . count($allRows) . " data rows from HTML table");
    
    // Map header names to our expected field names
    $headerMap = [];
    $expectedFields = [
        'received_date' => ['RECEIVED DATE', 'RECEIVED_DATE', 'RECEIVED'],
        'auto_tp_no' => ['AUTO TP NO', 'AUTO_TP_NO', 'AUTO TP'],
        'manual_tp_no' => ['MANUAL TP NO', 'MANUAL_TP_NO', 'MANUAL TP'],
        'tp_date' => ['TP DATE', 'TP_DATE'],
        'district' => ['DISTRICT'],
        'scm_party_code' => ['SCM PARTY CODE', 'SCM_PARTY_CODE', 'PARTY CODE'],
        'party_name' => ['PARTY NAME', 'PARTY_NAME'],
        'srno' => ['SRNO', 'SR NO', 'SR NO.'],
        'scm_item_code' => ['SCM ITEM CODE', 'SCM_ITEM_CODE', 'ITEM CODE'],
        'item_name' => ['ITEM NAME', 'ITEM_NAME'],
        'size' => ['SIZE'],
        'qty_cases' => ['QTY (CASES)', 'QTY CASES', 'CASES'],
        'qty_bottles' => ['QTY (BOTTLES)', 'QTY BOTTLES', 'BOTTLES'],
        'batch_no' => ['BATCH NO', 'BATCH_NO'],
        'mfg_month' => ['MFG. MONTH', 'MFG MONTH', 'MFG_MONTH'],
        'mrp' => ['MRP'],
        'bl' => ['B.L.', 'BL'],
        'vv' => ['V/V %', 'VV'],
        'total_bot_qty' => ['TOTAL BOT. QTY', 'TOTAL BOT QTY', 'TOTAL_BOT_QTY']
    ];
    
    // Build header mapping
    foreach ($headers as $index => $header) {
        $headerUpper = strtoupper(trim($header));
        foreach ($expectedFields as $field => $patterns) {
            foreach ($patterns as $pattern) {
                if (strpos($headerUpper, $pattern) !== false) {
                    $headerMap[$field] = $index;
                    break 2;
                }
            }
        }
    }
    
    debugLog("Header mapping", $headerMap);
    
    // Check required fields
    $requiredFields = ['received_date', 'auto_tp_no', 'scm_item_code'];
    foreach ($requiredFields as $required) {
        if (!isset($headerMap[$required])) {
            return ['successCount' => 0, 'errorCount' => 1, 'errors' => ["Required field '$required' not found in headers"]];
        }
    }
    
    // Group by TP number
    $tpGroups = [];
    
    foreach ($allRows as $rowIndex => $row) {
        $receivedDate = isset($headerMap['received_date']) ? $row[$headerMap['received_date']] : '';
        $autoTpNo = isset($headerMap['auto_tp_no']) ? $row[$headerMap['auto_tp_no']] : '';
        $manualTpNo = isset($headerMap['manual_tp_no']) ? $row[$headerMap['manual_tp_no']] : '';
        $tpDate = isset($headerMap['tp_date']) ? $row[$headerMap['tp_date']] : '';
        $district = isset($headerMap['district']) ? $row[$headerMap['district']] : '';
        $scmPartyCode = isset($headerMap['scm_party_code']) ? $row[$headerMap['scm_party_code']] : '';
        $partyName = isset($headerMap['party_name']) ? $row[$headerMap['party_name']] : '';
        $scmItemCode = isset($headerMap['scm_item_code']) ? $row[$headerMap['scm_item_code']] : '';
        $itemName = isset($headerMap['item_name']) ? $row[$headerMap['item_name']] : '';
        $size = isset($headerMap['size']) ? $row[$headerMap['size']] : '';
        $cases = isset($headerMap['qty_cases']) ? floatval($row[$headerMap['qty_cases']]) : 0;
        $bottles = isset($headerMap['qty_bottles']) ? intval($row[$headerMap['qty_bottles']]) : 0;
        $batchNo = isset($headerMap['batch_no']) ? $row[$headerMap['batch_no']] : '';
        $mfgMonth = isset($headerMap['mfg_month']) ? $row[$headerMap['mfg_month']] : '';
        $mrp = isset($headerMap['mrp']) ? floatval($row[$headerMap['mrp']]) : 0;
        $bl = isset($headerMap['bl']) ? floatval($row[$headerMap['bl']]) : 0;
        $vv = isset($headerMap['vv']) ? floatval($row[$headerMap['vv']]) : 0;
        $totalBotQty = isset($headerMap['total_bot_qty']) ? intval($row[$headerMap['total_bot_qty']]) : 0;
        
        if (empty($scmItemCode) && empty($itemName)) {
            continue;
        }
        
        $purchaseDate = !empty($receivedDate) ? date('Y-m-d', strtotime($receivedDate)) : date('Y-m-d');
        if ($purchaseDate == '1970-01-01') $purchaseDate = date('Y-m-d');
        
        $formattedTpDate = !empty($tpDate) ? date('Y-m-d', strtotime($tpDate)) : '0000-00-00';
        if ($formattedTpDate == '1970-01-01') $formattedTpDate = '0000-00-00';
        
        $tpNo = !empty($manualTpNo) ? $manualTpNo : $autoTpNo;
        
        if (empty($tpNo)) {
            debugLog("Skipping row " . ($rowIndex + 1) . " - no TP number");
            continue;
        }
        
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
            'free_cases' => 0,
            'free_bottles' => 0,
            'batch_no' => $batchNo,
            'mfg_month' => $mfgMonth,
            'mrp' => $mrp,
            'bl' => $bl,
            'vv' => $vv,
            'total_bott_qty' => $totalBotQty > 0 ? $totalBotQty : ($cases * 12 + $bottles)
        ];
    }
    
    debugLog("Grouped into " . count($tpGroups) . " TP groups");
    
    if (count($tpGroups) == 0) {
        return ['successCount' => 0, 'errorCount' => 1, 'errors' => ["No valid TP data found in file"]];
    }
    
    return processTPGroups($tpGroups, $companyId, $conn, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $importMode);
}

// ============================================================================
// FIXED EXCEL PROCESSING FUNCTION WITH CSV FALLBACK AND HTML TABLE SUPPORT
// ============================================================================
function processSingleExcelFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName = '') {
    debugLog("Processing Excel file: " . $fileName, $filePath);
    
    // FIRST: Check if the file is actually CSV content (NOT HTML masquerading as CSV)
    $content = file_get_contents($filePath);
    $hasCommas = false;
    $hasMultipleLines = false;
    
    if ($content) {
        // Check for CSV indicators - but exclude HTML content
        $lines = explode("\n", $content);
        $firstLine = isset($lines[0]) ? strtoupper($lines[0]) : '';
        
        // Check if first line contains typical CSV headers (only if NOT HTML)
        $hasCSVHeaders = false;
        if (stripos($content, '<table') === false && 
            stripos($content, '<html') === false &&
            stripos($content, '<!DOCTYPE') === false) {
            $hasCSVHeaders = (strpos($firstLine, 'RECEIVED') !== false || 
                              strpos($firstLine, 'AUTO_TP') !== false ||
                              strpos($firstLine, 'SCM_ITEM') !== false ||
                              strpos($firstLine, 'LICENSEE') !== false);
        }
        
        $hasCommas = strpos($content, ',') !== false;
        $hasMultipleLines = count($lines) > 3;
        
        // If it looks like CSV AND is not HTML, process as CSV directly
        if (($hasCommas && $hasMultipleLines) && $hasCSVHeaders) {
            debugLog("File appears to be pure CSV format. Processing as CSV directly.");
            return processSingleCSVFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName);
        }
    }
    
    // If not CSV, try Excel parsing with error suppression
    try {
        // Check if PhpSpreadsheet is available
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            throw new Exception("Excel import is not available. Please install PhpSpreadsheet.");
        }
        
        // Suppress all warnings during Excel parsing
        $previousErrorReporting = error_reporting();
        error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
        
        // Try to identify the file type
        $inputFileType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filePath);
        debugLog("Identified file type: " . $inputFileType);
        
        // For all file types (including HTML), use row-by-row Excel iteration
        // This ensures proper reading of old .xls files with merged cells and formatted layout
        // Just use the appropriate reader based on file extension/identification
        
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
        $reader->setReadDataOnly(true);
        
        // Suppress DOMDocument warnings
        $spreadsheet = @$reader->load($filePath);
        
        if (!$spreadsheet) {
            throw new Exception("Failed to load Excel file");
        }
        
        $sheet = $spreadsheet->getActiveSheet();
        
        // Read Excel file using row-by-row iteration
        // This ensures proper reading of old .xls files with merged cells and formatted layout
        // (same logic as CSV line-by-line parsing)
        $rows = [];
        $iterRowCount = 0;
        foreach ($sheet->getRowIterator() as $row) {
            $iterRowCount++;
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $rowData = [];

            foreach ($cellIterator as $cell) {
                $rowData[] = trim((string)$cell->getFormattedValue());
            }

            // skip fully empty rows
            if (!empty(array_filter($rowData))) {
                $rows[] = $rowData;
            }
        }

        debugLog("Row-by-row iteration returned " . count($rows) . " rows from $iterRowCount iterations");
        
        // Debug: Show first 15 rows from iteration (same as CSV debug output)
        debugLog("=== FIRST 15 ROWS FROM ROW-BY-ROW ITERATION ===");
        for ($i = 0; $i < min(15, count($rows)); $i++) {
            $rowData = array_slice($rows[$i], 0, 30);
            $rowDataFiltered = array_filter($rowData, function($val) {
                return !empty(trim($val));
            });
            debugLog("Iter Row " . ($i + 1) . ": " . json_encode(array_values($rowDataFiltered), JSON_UNESCAPED_UNICODE));
        }
        debugLog("===========================================");
        
        // Restore error reporting
        error_reporting($previousErrorReporting);
        
        if (empty($rows)) {
            throw new Exception("Excel file is empty");
        }
        
        debugLog("Excel file loaded, total rows count: " . count($rows));
        
        // Try to find header row
        $headers = [];
        $headerRowNum = 0;
        $headersFound = false;
        
        // Search for header row
        foreach ($rows as $searchRowIndex => $searchRow) {
            $searchRowNum = $searchRowIndex + 1;
            if (empty($searchRow)) continue;
            
            $rowStr = array_map(function($v) { 
                return strtoupper(trim($v ?? '')); 
            }, $searchRow);
            
            $foundReceived = false;
            $foundTpNo = false;
            $foundItemCode = false;
            
            foreach ($rowStr as $cell) {
                if (strpos($cell, 'RECEIVED') !== false) $foundReceived = true;
                if (strpos($cell, 'TP_NO') !== false || strpos($cell, 'TP ') !== false) $foundTpNo = true;
                if (strpos($cell, 'SCM_ITEM') !== false || strpos($cell, 'ITEM_CODE') !== false) $foundItemCode = true;
            }
            
            if ($foundReceived && $foundTpNo && $foundItemCode) {
                $headerRowNum = $searchRowNum;
                $headers = array_map(function($h) {
                    $h = is_null($h) ? '' : trim($h);
                    $h = strtolower($h);
                    $h = preg_replace('/[^a-z0-9\s]/', '', $h);
                    $h = str_replace(' ', '_', $h);
                    return $h;
                }, $searchRow);
                
                // Filter out empty headers
                $headers = array_filter($headers, function($h) {
                    return !empty($h);
                });
                
                debugLog("Headers found at row $headerRowNum", $headers);
                $headersFound = true;
                break;
            }
        }
        
        // If no headers found, try using row 3
        if (!$headersFound && count($rows) >= 3) {
            debugLog("Using row 3 as headers");
            $headerRowNum = 3;
            $headers = array_map(function($h) {
                $h = is_null($h) ? '' : trim($h);
                $h = strtolower($h);
                $h = preg_replace('/[^a-z0-9\s]/', '', $h);
                $h = str_replace(' ', '_', $h);
                return $h;
            }, $rows[2]);
            
            $headers = array_filter($headers, function($h) {
                return !empty($h);
            });
            
            $headersFound = true;
        }
        
        if (!$headersFound) {
            // Try CSV fallback
            debugLog("No headers found in Excel, trying CSV fallback");
            return processSingleCSVFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName);
        }
        
        // Process data rows
        $tpGroups = [];
        $processedRows = 0;
        $rowsWithTp = 0;
        
        foreach ($rows as $rowIndex => $row) {
            $rowNum = $rowIndex + 1;
            
            if ($rowNum <= $headerRowNum) continue;
            if (empty($row) || (count($row) == 1 && empty(trim($row[0])))) continue;
            
            // Map data to headers
            $rowData = [];
            $headerKeys = array_keys($headers);
            foreach ($headerKeys as $index) {
                if (isset($row[$index])) {
                    $rowData[$headers[$index]] = trim($row[$index]);
                }
            }
            
            $processedRows++;
            
            $scmItemCode = $rowData['scm_item_code'] ?? '';
            $itemName = $rowData['item_name'] ?? '';
            
            if (empty($scmItemCode) && empty($itemName)) {
                debugLog("Skipping row $rowNum - no item data");
                continue;
            }
            
            // Extract values
            $receivedDate = $rowData['received_date'] ?? '';
            $autoTpNo = $rowData['auto_tp_no'] ?? '';
            $manualTpNo = $rowData['manual_tp_no'] ?? '';
            $tpDate = $rowData['tp_date'] ?? '';
            $district = $rowData['district'] ?? '';
            $scmPartyCode = $rowData['scm_party_code'] ?? '';
            $partyName = $rowData['party_name'] ?? '';
            $size = $rowData['size'] ?? '';
            $cases = floatval($rowData['qty_cases'] ?? 0);
            $bottles = intval($rowData['qty_bottles'] ?? 0);
            $batchNo = $rowData['batch_no'] ?? '';
            $mfgMonth = $rowData['mfg_month'] ?? '';
            $mrp = floatval($rowData['mrp'] ?? 0);
            $bl = floatval($rowData['bl'] ?? 0);
            $vv = floatval($rowData['vv'] ?? 0);
            $totalBottQty = intval($rowData['total_bot_qty'] ?? 0);
            
            // Format dates
            $purchaseDate = !empty($receivedDate) ? date('Y-m-d', strtotime($receivedDate)) : date('Y-m-d');
            if ($purchaseDate == '1970-01-01') $purchaseDate = date('Y-m-d');
            
            $formattedTpDate = !empty($tpDate) ? date('Y-m-d', strtotime($tpDate)) : '0000-00-00';
            if ($formattedTpDate == '1970-01-01') $formattedTpDate = '0000-00-00';
            
            // Use manual TP number if available, otherwise auto TP number
            $tpNo = !empty($manualTpNo) ? $manualTpNo : $autoTpNo;
            
            // If still no TP number, try to generate one from date and supplier
            if (empty($tpNo) && !empty($partyName)) {
                $tpNo = 'IMP_' . date('Ymd', strtotime($purchaseDate)) . '_' . preg_replace('/[^a-z0-9]/i', '', substr($partyName, 0, 5));
                debugLog("Generated TP number from date and supplier: $tpNo");
            }
            
            if (empty($tpNo)) {
                debugLog("Skipping row $rowNum - no TP number");
                continue;
            }
            
            $rowsWithTp++;
            
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
                'free_cases' => 0,
                'free_bottles' => 0,
                'batch_no' => $batchNo,
                'mfg_month' => $mfgMonth,
                'mrp' => $mrp,
                'bl' => $bl,
                'vv' => $vv,
                'total_bott_qty' => $totalBottQty
            ];
        }
        
        debugLog("Processed $processedRows rows, $rowsWithTp with TP numbers, found " . count($tpGroups) . " TP groups");
        
        if (count($tpGroups) == 0) {
            // Try CSV fallback
            debugLog("No TP groups found in Excel, trying CSV fallback");
            return processSingleCSVFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName);
        }
        
        return processTPGroups($tpGroups, $companyId, $conn, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $importMode);
        
    } catch (Exception $e) {
        debugLog("Excel processing error: " . $e->getMessage());
        
        // Fall back to CSV processing
        debugLog("Falling back to CSV processor");
        return processSingleCSVFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName);
    }
}

// ============================================================================
// ENHANCED CSV PROCESSING FUNCTION
// ============================================================================
function processSingleCSVFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName = '') {
    debugLog("Processing CSV file: " . $fileName, $filePath);
    
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        debugLog("Cannot open file", $filePath);
        return [
            'successCount' => 0,
            'errorCount' => 1,
            'errors' => ["Cannot open file: $fileName"]
        ];
    }
    
    $rowNum = 0;
    $headersFound = false;
    $headers = [];
    $tpGroups = [];
    $headerRowNum = 0;
    
    // First pass: Read all data into array for dynamic header detection
    $allRows = [];
    while (($data = fgetcsv($handle)) !== false) {
        $allRows[] = $data;
    }
    fclose($handle);
    
    debugLog("CSV file loaded, total rows count: " . count($allRows));
    
    // Debug: Show first 15 rows
    debugLog("=== FIRST 15 ROWS OF CSV FILE ===");
    for ($i = 0; $i < min(15, count($allRows)); $i++) {
        $rowData = array_slice($allRows[$i], 0, 15);
        debugLog("Row " . ($i + 1) . ": " . json_encode($rowData, JSON_UNESCAPED_UNICODE));
    }
    debugLog("====================================");
    
    // Find header row dynamically
    foreach ($allRows as $searchRowIndex => $searchRow) {
        $searchRowNum = $searchRowIndex + 1;
        if (empty($searchRow)) continue;
        
        // Convert to uppercase for case-insensitive matching
        $rowStr = array_map(function($v) { 
            return strtoupper(trim($v ?? '')); 
        }, $searchRow);
        
        $foundReceived = false;
        $foundTpNo = false;
        $foundItemCode = false;
        
        foreach ($rowStr as $cell) {
            if (strpos($cell, 'RECEIVED_DATE') !== false || 
                strpos($cell, 'RECEIVED') !== false) {
                $foundReceived = true;
                debugLog("Found RECEIVED in row $searchRowNum, cell: $cell");
            }
                
            if (strpos($cell, 'AUTO_TP_NO') !== false || 
                strpos($cell, 'TP_NO') !== false ||
                strpos($cell, 'TPNUMBER') !== false ||
                strpos($cell, 'TP NUMBER') !== false) {
                $foundTpNo = true;
                debugLog("Found TP_NO in row $searchRowNum, cell: $cell");
            }
                
            if (strpos($cell, 'SCM_ITEM_CODE') !== false || 
                strpos($cell, 'ITEM_CODE') !== false ||
                strpos($cell, 'ITEMCODE') !== false) {
                $foundItemCode = true;
                debugLog("Found ITEM_CODE in row $searchRowNum, cell: $cell");
            }
        }
        
        // If we found key columns, this is the header row
        if ($foundReceived && $foundTpNo && $foundItemCode) {
            $headerRowNum = $searchRowNum;
            // Clean headers: remove special chars, trim, lowercase
            $headers = array_map(function($h) {
                $h = is_null($h) ? '' : trim($h);
                $h = strtolower($h);
                $h = preg_replace('/[^a-z0-9\s]/', '', $h);
                $h = str_replace(' ', '_', $h);
                return $h;
            }, $searchRow);
            
            debugLog("CSV Headers found dynamically at row $headerRowNum", $headers);
            $headersFound = true;
            break;
        }
    }
    
    // If dynamic detection fails, try using row 3 as default
    if (!$headersFound && count($allRows) >= 3) {
        debugLog("No headers found, trying row 3 as default header row");
        $headerRowNum = 3;
        $headers = array_map(function($h) {
            $h = is_null($h) ? '' : trim($h);
            $h = strtolower($h);
            $h = preg_replace('/[^a-z0-9\s]/', '', $h);
            $h = str_replace(' ', '_', $h);
            return $h;
        }, $allRows[2]);
        
        debugLog("Default headers from row 3", $headers);
        $headersFound = true;
    }
    
    if (!$headersFound) {
        debugLog("No header row found in CSV file");
        return [
            'successCount' => 0,
            'errorCount' => 1,
            'errors' => ["No valid header row found in CSV. Please ensure your CSV has columns like RECEIVED_DATE, TP_NO, SCM_ITEM_CODE"]
        ];
    }
    
    // Process data rows (after header row)
    debugLog("Processing data rows from row " . ($headerRowNum + 1));
    $processedRows = 0;
    $rowsWithTp = 0;
    
    foreach ($allRows as $rowIndex => $row) {
        $rowNum = $rowIndex + 1;
        
        // Skip rows before header row
        if ($rowNum <= $headerRowNum) {
            continue;
        }
        
        // Skip empty rows
        if (empty($row) || (count($row) == 1 && empty(trim($row[0])))) {
            continue;
        }
        
        // Map data to headers
        $rowData = [];
        foreach ($headers as $index => $header) {
            if (isset($row[$index])) {
                $rowData[$header] = trim($row[$index]);
            } else {
                $rowData[$header] = '';
            }
        }
        
        $processedRows++;
        debugLog("Processed row $rowNum data (first 10 fields)", array_slice($rowData, 0, 10));
        
        // Skip rows without essential data
        if (empty($rowData['scm_item_code']) && empty($rowData['item_name'])) {
            debugLog("Skipping row $rowNum - no item code or name");
            continue;
        }
        
        // Get values from CSV
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
        
        // Default values for missing fields
        $freeCases = 0;
        $freeBottles = 0;
        
        // Format dates
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
        
        // If still no TP number, try to generate one from date and supplier
        if (empty($tpNo) && !empty($partyName)) {
            $tpNo = 'IMP_' . date('Ymd', strtotime($purchaseDate)) . '_' . preg_replace('/[^a-z0-9]/i', '', substr($partyName, 0, 5));
            debugLog("Generated TP number from date and supplier: $tpNo");
        }
        
        // Group by TP No. (manual or auto)
        if (!empty($tpNo)) {
            $rowsWithTp++;
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
                
                debugLog("Created new TP group", [
                    'tp_no' => $tpNo,
                    'date' => $purchaseDate,
                    'supplier' => $partyName
                ]);
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
            debugLog("Skipping row $rowNum - no TP number found");
        }
    }
    
    debugLog("Processed $processedRows data rows, $rowsWithTp rows had TP numbers, found " . count($tpGroups) . " TP groups");
    
    // If no TP groups were found, show error with details
    if (count($tpGroups) == 0) {
        $errorMsg = "No valid TP data found in CSV. Processed $processedRows rows but found no TP numbers. ";
        $errorMsg .= "Check that your CSV has columns: RECEIVED_DATE, TP_NO (or AUTO_TP_NO), and SCM_ITEM_CODE. ";
        $errorMsg .= "Headers found: " . json_encode(array_values($headers));
        debugLog($errorMsg);
        return [
            'successCount' => 0,
            'errorCount' => 1,
            'errors' => [$errorMsg]
        ];
    }
    
    // Process TP groups
    return processTPGroups($tpGroups, $companyId, $conn, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $importMode);
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
        $itemsQuery = "SELECT im.CODE, im.DETAILS, im.DETAILS2, im.PPRICE, im.ITEM_GROUP, im.LIQ_FLAG, im.CLASS,
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
            
            // Find supplier with improved matching
            $supplierInfo = findBestSupplierMatch($tpData['supplier'], $conn);
            $supplierCode = $supplierInfo ? $supplierInfo['CODE'] : '';
            
            debugLog("Supplier match result", [
                'input' => $tpData['supplier'],
                'found_code' => $supplierCode,
                'found_name' => $supplierInfo ? $supplierInfo['DETAILS'] : 'Not found'
            ]);
            
            // Get next voucher number
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
                
                // Use PPRICE from tblitemmaster as default case rate
                $caseRate = $itemInfo ? floatval($itemInfo['PPRICE']) : 0;
                
                // Calculate amount
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
            
            // Use auto TP number from CSV
            $autoTpNo = !empty($tpData['auto_tp_no']) ? $tpData['auto_tp_no'] : 
                       'FL' . date('dmY', strtotime($tpData['date'])) . '/' . $tpNo;
            
            debugLog("TP details", [
                'auto_tp_no' => $autoTpNo,
                'manual_tp_no' => $tpData['manual_tp_no'],
                'tp_date' => $tpData['tp_date']
            ]);
            
            // Insert purchase header
            $insertQuery = "INSERT INTO tblpurchases (
                DATE, SUBCODE, AUTO_TPNO, VOC_NO, INV_NO, INV_DATE, TAMT, 
                TPNO, TP_DATE, PUR_FLAG, CompID
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            debugLog("Purchase header insert query", $insertQuery);
            
            $insertStmt = $conn->prepare($insertQuery);
            if (!$insertStmt) {
                throw new Exception("Error preparing purchase header: " . $conn->error);
            }
            
            // Set empty values for invoice fields
            $invNo = '';
            $invDate = '0000-00-00';
            
            // Convert VOC_NO to integer
            $vocNoInt = (int)$nextVoc;
            // Convert TAMT to string for binding
            $totalAmountStr = (string)$totalAmount;
            
            // Bind parameters for 11 placeholders
            $insertStmt->bind_param(
                "sssissssssi",
                $tpData['date'],
                $supplierCode,
                $autoTpNo,
                $vocNoInt,
                $invNo,
                $invDate,
                $totalAmountStr,
                $tpNo,
                $tpData['tp_date'],
                $defaultStatus,
                $companyId
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
            
            // Insert purchase items
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
            
            // Collect stock updates for batch processing (to avoid cascading issues)
            $stockUpdates = [];
            
            foreach ($validItems as $validItem) {
                $item = $validItem['data'];
                $itemInfo = $validItem['info'];
                $bottlesPerCase = $validItem['bottles_per_case'];
                $caseRate = $validItem['case_rate'];
                $amount = $validItem['amount'];
                $totalBottles = $validItem['total_bottles'];
                
                // Use BL from CSV if available, otherwise calculate
                $bl = $item['bl'] > 0 ? $item['bl'] : 0.00;
                
                // Use VV from CSV if available
                $vv = $item['vv'] > 0 ? $item['vv'] : 0.00;
                
                // Ensure string values
                $batchNo = $item['batch_no'] ?? '';
                $mfgMonth = $item['mfg_month'] ?? '';
                $autoBatch = '';
                
                debugLog("Inserting item detail", [
                    'purchase_id' => $purchaseId,
                    'item_code' => $itemInfo['CODE'],
                    'total_bottles' => $totalBottles,
                    'bl' => $bl,
                    'vv' => $vv,
                    'amount' => $amount
                ]);
                
                // Bind parameters with correct types
                $detailStmt->bind_param(
                    "isssdddddddsssdddi",
                    $purchaseId,
                    $itemInfo['CODE'],
                    $item['item_name'],
                    $item['size'],
                    $item['cases'],
                    $item['bottles'],
                    $item['free_cases'],
                    $item['free_bottles'],
                    $caseRate,
                    $item['mrp'],
                    $amount,
                    $bottlesPerCase,
                    $batchNo,
                    $autoBatch,
                    $mfgMonth,
                    $bl,
                    $vv,
                    $totalBottles
                );
                
                if (!$detailStmt->execute()) {
                    throw new Exception("Error inserting purchase detail for item {$itemInfo['CODE']}: " . $detailStmt->error);
                }
                
                $itemsInserted++;
                
                // Update MRP if requested
                if ($updateMRP && $item['mrp'] > 0) {
                    updateItemMRP($conn, $itemInfo['CODE'], $item['mrp']);
                }
                
                // Collect stock updates for batch processing
                if ($updateStockFlag && $totalBottles > 0) {
                    $itemCode = $itemInfo['CODE'];
                    if (!isset($stockUpdates[$itemCode])) {
                        $stockUpdates[$itemCode] = 0;
                    }
                    $stockUpdates[$itemCode] += $totalBottles;
                }
            }
            
            $detailStmt->close();
            
            // Perform batch stock updates with single cascading per item
            if ($updateStockFlag && !empty($stockUpdates)) {
                debugLog("Batch stock updates for TP", [
                    'tp_no' => $tpNo,
                    'date' => $tpData['date'],
                    'items' => $stockUpdates
                ]);
                
                foreach ($stockUpdates as $itemCode => $totalBottles) {
                    // Update stock with batching - this does the cascading only once per item
                    batchUpdateStock($itemCode, $totalBottles, $tpData['date'], $companyId, $conn);
                }
            }
            
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

// Handle file upload - supports multiple files
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_files'])) {
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
    
    $files = $_FILES['excel_files'];
    $fileCount = count($files['name']);
    
    debugLog("Number of files uploaded", $fileCount);
    
    // Validate that files were uploaded
    if ($fileCount === 0 || ($fileCount === 1 && $files['error'][0] === UPLOAD_ERR_NO_FILE)) {
        header("Location: purchase_module.php?mode=$importMode&import_error=No file selected");
        exit;
    }
    
    $allowedExtensions = ['csv', 'xls', 'xlsx'];
    $totalSuccessCount = 0;
    $totalErrorCount = 0;
    $allErrors = [];
    $importedFiles = [];
    
    // Process each file one by one
    for ($i = 0; $i < $fileCount; $i++) {
        // Skip if no file uploaded for this index
        if ($files['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        
        // Check for upload errors
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $allErrors[] = "File " . ($i + 1) . ": Upload error code " . $files['error'][$i];
            $totalErrorCount++;
            continue;
        }
        
        $fileName = $files['name'][$i];
        $fileSize = $files['size'][$i];
        $fileTmp = $files['tmp_name'][$i];
        
        // Check file size (10MB max)
        if ($fileSize > 10 * 1024 * 1024) {
            $allErrors[] = "File '$fileName': Size exceeds 10MB limit";
            $totalErrorCount++;
            continue;
        }
        
        // Check file extension
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($fileExt, $allowedExtensions)) {
            $allErrors[] = "File '$fileName': Invalid file type. Please upload .csv, .xls, or .xlsx files.";
            $totalErrorCount++;
            continue;
        }
        
        debugLog("Processing file " . ($i + 1) . ": $fileName");
        
        // Process file based on type
        if ($fileExt === 'csv') {
            // Process CSV file
            $result = processSingleCSVFile($fileTmp, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName);
        } else {
            // Process Excel file
            $result = processSingleExcelFile($fileTmp, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName);
        }
        
        $totalSuccessCount += $result['successCount'];
        $totalErrorCount += $result['errorCount'];
        
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $allErrors[] = "File '$fileName': $error";
            }
        }
        
        if ($result['successCount'] > 0) {
            $importedFiles[] = $fileName;
        }
    }
    
    // After importing all files, renumber voucher numbers
    if ($totalSuccessCount > 0) {
        renumberVoucherNumbers($conn, $companyId);
    }
    
    // Redirect with results
    if ($totalErrorCount > 0) {
        $errorMessage = "Imported $totalSuccessCount purchases from " . count($importedFiles) . " file(s). Errors: $totalErrorCount. ";
        if (count($allErrors) > 0) {
            $errorMessage .= "First error: " . $allErrors[0];
        }
        header("Location: purchase_module.php?mode=$importMode&import_error=" . urlencode($errorMessage));
    } else {
        header("Location: purchase_module.php?mode=$importMode&import_success=1");
    }
} else {
    header("Location: purchase_module.php");
    exit;
}

$conn->close();
debugLog("=== IMPORT PURCHASE ENDED ===");
?>