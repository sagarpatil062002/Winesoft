<?php
// import_purchase.php - UPDATED FOR SCM CSV FORMAT WITH FIXES
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
function continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate) {
    debugLog("Continuing cascading to current month", [
        'comp_id' => $comp_id,
        'itemCode' => $itemCode,
        'purchaseDate' => $purchaseDate
    ]);
    
    $purchaseDay = date('j', strtotime($purchaseDate));
    $purchaseMonth = date('n', strtotime($purchaseDate));
    $purchaseYear = date('Y', strtotime($purchaseDate));
    $currentDay = date('j');
    $currentMonth = date('n');
    $currentYear = date('Y');
    
    // If purchase is in current month, cascading has already been handled
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
        'startYear' => $startYear
    ]);
    
    // Loop through months from purchase month+1 to current month
    while (($startYear < $currentYear) || ($startYear == $currentYear && $startMonth <= $currentMonth)) {
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
    
    // If we've reached current month, ensure current month table is also updated
    if ($currentMonth != $purchaseMonth || $currentYear != $purchaseYear) {
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
    
    debugLog("Cascading completed up to current date");
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

// Function to update stock after purchase (complete version matching purchases.php)
function updateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn) {
    // Get day of month from purchase date
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    debugLog("Updating stock for item", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'day_of_month' => $dayOfMonth,
        'month' => $month,
        'year' => $year
    ]);
    
    // Check if this month is archived
    $isArchived = isMonthArchived($conn, $companyId, $month, $year);
    
    if ($isArchived) {
        debugLog("Month is archived, updating archive table with cascading");
        // Update archived month data with cascading
        updateArchivedMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
        
        // Continue cascading to current month
        continueCascadingToCurrentMonth($conn, $companyId, $itemCode, $purchaseDate);
    } else {
        debugLog("Month is current, updating current table with cascading");
        // Update current month data with cascading
        updateCurrentMonthStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    }
    
    // Update tblitem_stock
    updateItemStock($conn, $itemCode, $totalBottles, $companyId);
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
    
    // Read and skip metadata rows
    $rowNum = 0;
    $headersFound = false;
    $headers = [];
    $tpGroups = [];
    
    // Read file line by line
    while (($data = fgetcsv($handle)) !== false) {
        $rowNum++;
        
        // Skip empty rows
        if (empty($data) || (count($data) == 1 && empty(trim($data[0])))) {
            continue;
        }
        
        // Skip the first two metadata rows
        if ($rowNum <= 2) {
            debugLog("Skipping metadata row $rowNum", $data[0]);
            continue;
        }
        
        // Row 3 should contain headers
        if ($rowNum == 3) {
            // Clean headers: remove special chars, trim, lowercase
            $headers = array_map(function($h) {
                $h = trim($h);
                $h = strtolower($h);
                $h = preg_replace('/[^a-z0-9\s]/', '', $h); // Remove special characters
                $h = str_replace(' ', '_', $h); // Replace spaces with underscores
                return $h;
            }, $data);
            
            debugLog("CSV Headers found", $headers);
            $headersFound = true;
            continue;
        }
        
        // Process data rows (row 4 onwards)
        if ($headersFound) {
            // Map data to headers
            $rowData = [];
            foreach ($headers as $index => $header) {
                if (isset($data[$index])) {
                    $rowData[$header] = trim($data[$index]);
                } else {
                    $rowData[$header] = '';
                }
            }
            
            // Skip rows without essential data
            if (empty($rowData['scm_item_code']) && empty($rowData['item_name'])) {
                debugLog("Skipping empty row $rowNum");
                continue;
            }
            
            // Get values from CSV - using actual column names from your file
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
                debugLog("Skipping row - no TP number", $rowNum);
            }
        }
    }
    
    fclose($handle);
    
    debugLog("Found TP groups", [
        'count' => count($tpGroups),
        'tps' => array_keys($tpGroups)
    ]);
    
    // If no TP groups were found, show error
    if (count($tpGroups) == 0) {
        $errorMessage = "No valid TP data found in CSV. Please check that your CSV has the correct format with headers in row 3.";
        debugLog("No TP groups found");
        header("Location: purchase_module.php?mode=$importMode&import_error=" . urlencode($errorMessage));
        exit;
    }
    
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
            
            // Insert purchase header - FIXED: Correct parameter binding
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
            
            // Bind parameters for 11 placeholders - FIXED TYPE STRING
            $insertStmt->bind_param(
                "sssissssssi", // 11 characters: s=string, i=integer
                $tpData['date'],        // DATE (s) - 1
                $supplierCode,          // SUBCODE (s) - 2
                $autoTpNo,              // AUTO_TPNO (s) - 3
                $vocNoInt,              // VOC_NO (i) - 4
                $invNo,                 // INV_NO (s) - 5
                $invDate,               // INV_DATE (s) - 6
                $totalAmountStr,        // TAMT (s) - 7
                $tpNo,                  // TPNO (s) - 8
                $tpData['tp_date'],     // TP_DATE (s) - 9
                $defaultStatus,         // PUR_FLAG (s) - 10
                $companyId              // CompID (i) - 11
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
                $autoBatch = ''; // FIX: Create variable for AutoBatch
                
                debugLog("Inserting item detail", [
                    'purchase_id' => $purchaseId,
                    'item_code' => $itemInfo['CODE'],
                    'total_bottles' => $totalBottles,
                    'bl' => $bl,
                    'vv' => $vv,
                    'amount' => $amount
                ]);
                
                // Bind parameters with correct types - FIXED: 18 parameters with correct type string
                $detailStmt->bind_param(
                    "isssdddddddsssdddi",  // FIXED: 18 characters for 18 parameters
                    $purchaseId,            // PurchaseID (i) - 1
                    $itemInfo['CODE'],      // ItemCode (s) - 2
                    $item['item_name'],     // ItemName (s) - 3
                    $item['size'],          // Size (s) - 4
                    $item['cases'],         // Cases (d) - 5
                    $item['bottles'],       // Bottles (d) - 6
                    $item['free_cases'],    // FreeCases (d) - 7
                    $item['free_bottles'],  // FreeBottles (d) - 8
                    $caseRate,              // CaseRate (d) - 9
                    $item['mrp'],           // MRP (d) - 10
                    $amount,                // Amount (d) - 11
                    $bottlesPerCase,        // BottlesPerCase (d) - 12
                    $batchNo,               // BatchNo (s) - 13
                    $autoBatch,             // AutoBatch (s) - 14
                    $mfgMonth,              // MfgMonth (s) - 15
                    $bl,                    // BL (d) - 16
                    $vv,                    // VV (d) - 17
                    $totalBottles           // TotBott (i) - 18
                );
                
                if (!$detailStmt->execute()) {
                    throw new Exception("Error inserting purchase detail for item {$itemInfo['CODE']}: " . $detailStmt->error);
                }
                
                $itemsInserted++;
                
                // Update MRP if requested
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