<?php
// import_purchase.php - MODIFIED VERSION 1 (Single File Upload, CSV/Excel Support)
session_start();

// Include PhpSpreadsheet autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Increase PHP limits for file uploads
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '100M');
ini_set('max_file_uploads', '1');
ini_set('memory_limit', '512M');
set_time_limit(0);

// ============================================================================
// VOUCHER NUMBER RENUMBERING FUNCTION
// ============================================================================
function renumberVoucherNumbers($conn, $companyId) {
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

// Enable debug logging
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
include_once "stock_functions.php";
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

// ============================================================================
// ALL HELPER FUNCTIONS FROM VERSION 1
// ============================================================================

function cleanItemCode($code) {
    $cleaned = preg_replace('/^SCM/i', '', trim($code));
    debugLog("cleanItemCode: '$code' -> '$cleaned'");
    return $cleaned;
}

function updateItemMRP($conn, $itemCode, $mrp) {
    $cleanCode = cleanItemCode($itemCode);
    
    debugLog("Updating MRP for item", [
        'item_code' => $cleanCode,
        'mrp' => $mrp
    ]);
    
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

function normalizeSupplierName($name) {
    if (empty($name)) return '';
    
    $normalized = strtolower(trim($name));
    
    $removeWords = [
        'private', 'limited', 'ltd', 'pvt', 'ltd.', 'pvt.', 'llp', 'llp.',
        'traders', 'trading', 'company', 'co', 'co.', 'corporation', 'corp',
        'and', '&', 'the', 'ind.', 'industries', 'industry'
    ];
    
    foreach ($removeWords as $word) {
        $normalized = preg_replace('/\b' . preg_quote($word, '/') . '\b/', '', $normalized);
    }
    
    $normalized = preg_replace('/[^a-z0-9]/', ' ', $normalized);
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    $normalized = trim($normalized);
    
    $normalized = preg_replace('/\s+\d+$/', '', $normalized);
    
    return $normalized;
}

function stringSimilarity($str1, $str2) {
    $str1 = normalizeSupplierName($str1);
    $str2 = normalizeSupplierName($str2);
    
    if (empty($str1) || empty($str2)) return 0;
    
    if ($str1 === $str2) return 100;
    
    $len1 = strlen($str1);
    $len2 = strlen($str2);
    $maxLen = max($len1, $len2);
    
    if ($maxLen == 0) return 0;
    
    $distance = levenshtein($str1, $str2);
    $similarity = (1 - $distance / $maxLen) * 100;
    
    return max(0, $similarity);
}

function findBestSupplierMatch($supplierName, $conn) {
    debugLog("Finding supplier match for", $supplierName);
    
    if (empty($supplierName)) {
        return null;
    }
    
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
    
    $bestMatch = null;
    $bestScore = 0;
    $inputNormalized = normalizeSupplierName($supplierName);
    
    foreach ($allSuppliers as $supplier) {
        $dbName = $supplier['DETAILS'];
        $dbCode = $supplier['CODE'];
        $dbNormalized = normalizeSupplierName($dbName);
        
        $score = 0;
        
        if ($inputNormalized === $dbNormalized) {
            $score = 100;
        }
        elseif (strpos($inputNormalized, $dbNormalized) !== false || 
                strpos($dbNormalized, $inputNormalized) !== false) {
            $score = 85;
        }
        elseif (strpos($supplierName, $dbCode) !== false) {
            $score = 80;
        }
        else {
            $similarity = stringSimilarity($supplierName, $dbName);
            if ($similarity > 70) {
                $score = $similarity;
            }
        }
        
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
    
    if ($bestScore >= 60) {
        debugLog("Best supplier match selected", [
            'input' => $supplierName,
            'matched_name' => $bestMatch['DETAILS'],
            'matched_code' => $bestMatch['CODE'],
            'match_score' => $bestScore
        ]);
        return $bestMatch;
    }
    
    if (!$bestMatch || $bestScore < 60) {
        $searchTerms = [];
        
        $cleanName = preg_replace('/\b(?:traders|trading|limited|ltd|private|pvt|company|co|corporation|corp|and|&|the)\b/i', '', $supplierName);
        $cleanName = trim(preg_replace('/\s+/', ' ', $cleanName));
        
        if (!empty($cleanName)) {
            $searchTerms[] = $cleanName;
        }
        
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

function findItem($itemCode, $conn, $allowed_classes) {
    $cleanCode = cleanItemCode($itemCode);
    
    debugLog("Finding item", [
        'original_code' => $itemCode,
        'clean_code' => $cleanCode
    ]);
    
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

// ============================================================================
// STOCK UPDATE FUNCTIONS (Complete from Version 1)
// ============================================================================

function isMonthArchived($conn, $comp_id, $month, $year) {
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    
    $check_archive_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                           WHERE table_schema = DATABASE() 
                           AND table_name = '$archive_table'";
    $check_result = $conn->query($check_archive_query);
    $exists = $check_result->fetch_assoc()['count'] > 0;
    
    return $exists;
}

function updateSubsequentDaysInTable($conn, $table, $monthYear, $itemCode, $purchaseDay) {
    $timestamp = strtotime($monthYear . "-01");
    $daysInMonth = date('t', $timestamp);
    
    for ($day = $purchaseDay + 1; $day <= $daysInMonth; $day++) {
        $prevDay = $day - 1;
        $prevDayClosing = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        $currentDayOpening = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $currentDayPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $currentDaySales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $currentDayClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        $checkColumnsQuery = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                            WHERE TABLE_SCHEMA = DATABASE() 
                            AND TABLE_NAME = '$table' 
                            AND COLUMN_NAME IN ('$currentDayOpening', '$currentDayPurchase', '$currentDaySales', '$currentDayClosing')";
        
        $checkResult = $conn->query($checkColumnsQuery);
        $columnsExist = $checkResult->num_rows >= 4;
        
        if ($columnsExist) {
            $updateQuery = "UPDATE $table 
                           SET $currentDayOpening = $prevDayClosing,
                               $currentDayClosing = $prevDayClosing + $currentDayPurchase - $currentDaySales
                           WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("ss", $monthYear, $itemCode);
            $stmt->execute();
            $stmt->close();
        }
        $checkResult->free();
    }
}

function updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    debugLog("Updating archived month stock", [
        'table' => $archive_table,
        'monthYear' => $monthYear,
        'itemCode' => $itemCode,
        'dayOfMonth' => $dayOfMonth,
        'totalBottles' => $totalBottles
    ]);
    
    $check_query = "SELECT COUNT(*) as count FROM $archive_table 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $itemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        $update_query = "UPDATE $archive_table 
                        SET $purchaseColumn = $purchaseColumn + ?,
                            $closingColumn = $openingColumn + $purchaseColumn - $saleColumn
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
        $result = $update_stmt->execute();
        $update_stmt->close();
        
        if ($result) {
            updateSubsequentDaysInTable($conn, $archive_table, $monthYear, $itemCode, $dayOfMonth);
        }
    } else {
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
    
    $checkDailyStockQuery = "SELECT COUNT(*) as count FROM $dailyStockTable 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $checkStmt = $conn->prepare($checkDailyStockQuery);
    $checkStmt->bind_param("ss", $monthYear, $itemCode);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $row = $result->fetch_assoc();
    $checkStmt->close();
    
    if ($row['count'] > 0) {
        $updateDailyStockQuery = "UPDATE $dailyStockTable 
                                 SET $purchaseColumn = $purchaseColumn + ?,
                                     $closingColumn = $openingColumn + $purchaseColumn - $saleColumn
                                 WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $dailyStmt = $conn->prepare($updateDailyStockQuery);
        $dailyStmt->bind_param("iss", $totalBottles, $monthYear, $itemCode);
        $result = $dailyStmt->execute();
        $dailyStmt->close();
        
        if ($result) {
            updateSubsequentDaysInTable($conn, $dailyStockTable, $monthYear, $itemCode, $dayOfMonth);
        }
    } else {
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

function continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate, $fy_end_date = null) {
    if ($fy_end_date === null || $fy_end_date === $purchaseDate) {
        if (isPreviousFinancialYear($purchaseDate)) {
            $fy_end_date = getFinancialYearEndDate($purchaseDate);
        } else {
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
    
    $fyEndMonth = (int)date('n', strtotime($fy_end_date));
    $fyEndYear = (int)date('Y', strtotime($fy_end_date));
    
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
    
    if ($purchaseMonth == $currentMonth && $purchaseYear == $currentYear) {
        debugLog("Purchase is in current month, cascading already handled");
        return;
    }
    
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
    
    while (($startYear < $fyEndYear) || ($startYear == $fyEndYear && $startMonth <= $fyEndMonth)) {
        $month_2digit = str_pad($startMonth, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($startYear, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        
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
            $daysInMonth = date('t', strtotime("$startYear-$startMonth-01"));
            
            if ($startMonth == $purchaseMonth + 1 || ($startMonth == 1 && $purchaseMonth == 12)) {
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
                
                $updateOpeningQuery = "UPDATE $archive_table 
                                      SET DAY_01_OPEN = ?,
                                          DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                      WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $openingStmt = $conn->prepare($updateOpeningQuery);
                $openingStmt->bind_param("iss", $openingValue, $monthYear, $itemCode);
                $openingStmt->execute();
                $openingStmt->close();
                
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
                updateSubsequentDaysInTable($conn, $archive_table, $monthYear, $itemCode, 1);
            }
        }
        
        $startMonth++;
        if ($startMonth > 12) {
            $startMonth = 1;
            $startYear++;
        }
    }
    
    if (($fyEndYear < $currentYear) || ($fyEndYear == $currentYear && $fyEndMonth < $currentMonth)) {
        $cascadeEndMonth = $fyEndMonth;
        $cascadeEndYear = $fyEndYear;
    } else {
        $cascadeEndMonth = $currentMonth;
        $cascadeEndYear = $currentYear;
    }
    
    if ($cascadeEndMonth != $purchaseMonth || $cascadeEndYear != $purchaseYear) {
        $dailyStockTable = "tbldailystock_" . $comp_id;
        $currentMonthYear = date('Y-m');
        
        $checkCurrentQuery = "SELECT COUNT(*) as count FROM $dailyStockTable 
                             WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $checkCurrentStmt = $conn->prepare($checkCurrentQuery);
        $checkCurrentStmt->bind_param("ss", $currentMonthYear, $itemCode);
        $checkCurrentStmt->execute();
        $currentResult = $checkCurrentStmt->get_result();
        $currentExists = $currentResult->fetch_assoc()['count'] > 0;
        $checkCurrentStmt->close();
        
        if ($currentExists) {
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
                
                $updateCurrentOpeningQuery = "UPDATE $dailyStockTable 
                                            SET DAY_01_OPEN = ?,
                                                DAY_01_CLOSING = DAY_01_OPEN + DAY_01_PURCHASE - DAY_01_SALES
                                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $currentOpeningStmt = $conn->prepare($updateCurrentOpeningQuery);
                $currentOpeningStmt->bind_param("iss", $openingValue, $currentMonthYear, $itemCode);
                $currentOpeningStmt->execute();
                $currentOpeningStmt->close();
            }
            
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

function updateItemStock($conn, $itemCode, $totalBottles, $companyId) {
    $stockColumn = "CURRENT_STOCK" . $companyId;
    
    $checkQuery = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("s", $itemCode);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    $exists = $checkResult->fetch_assoc()['count'] > 0;
    $checkStmt->close();
    
    if ($exists) {
        $updateItemStockQuery = "UPDATE tblitem_stock 
                                SET $stockColumn = $stockColumn + ? 
                                WHERE ITEM_CODE = ?";
        
        $itemStmt = $conn->prepare($updateItemStockQuery);
        $itemStmt->bind_param("is", $totalBottles, $itemCode);
        $result = $itemStmt->execute();
        $itemStmt->close();
    } else {
        $insertItemStockQuery = "INSERT INTO tblitem_stock (ITEM_CODE, $stockColumn) 
                                VALUES (?, ?)";
        
        $itemStmt = $conn->prepare($insertItemStockQuery);
        $itemStmt->bind_param("si", $itemCode, $totalBottles);
        $result = $itemStmt->execute();
        $itemStmt->close();
    }
    
    return $result;
}

function cascadeToFinancialYearEnd($conn, $comp_id, $item_code, $purchase_date, $total_bottles) {
    $purchase_timestamp = strtotime($purchase_date);
    $purchase_month = date('n', $purchase_timestamp);
    $purchase_year = date('Y', $purchase_timestamp);
    
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
    
    $start_month = $purchase_month + 1;
    $start_year = $purchase_year;
    
    if ($start_month > 12) {
        $start_month = 1;
        $start_year++;
    }
    
    debugLog("Starting cascade from", ['start_month' => $start_month, 'start_year' => $start_year]);
    
    $purchase_month_2digit = str_pad($purchase_month, 2, '0', STR_PAD_LEFT);
    $purchase_year_2digit = substr($purchase_year, -2);
    $purchase_table = "tbldailystock_{$comp_id}_{$purchase_month_2digit}_{$purchase_year_2digit}";
    
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
    
    while ($start_year < $fy_end_year || ($start_year == $fy_end_year && $start_month <= $fy_end_month)) {
        $month_2digit = str_pad($start_month, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($start_year, -2);
        $archive_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        
        debugLog("Processing month", ['table' => $archive_table, 'month' => $start_month, 'year' => $start_year]);
        
        $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name = '$archive_table'";
        $check_result = $conn->query($check_table_query);
        $table_exists = $check_result ? $check_result->fetch_assoc()['count'] > 0 : false;
        
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
            
            $checkRecordQuery = "SELECT COUNT(*) as count FROM $archive_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $checkRecordStmt = $conn->prepare($checkRecordQuery);
            $checkRecordStmt->bind_param("ss", $monthYear, $item_code);
            $checkRecordStmt->execute();
            $recordResult = $checkRecordStmt->get_result();
            $recordExists = $recordResult->fetch_assoc()['count'] > 0;
            $checkRecordStmt->close();
            
            if (!$recordExists) {
                $insertQuery = "INSERT INTO $archive_table (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                               VALUES (?, ?, 'F', ?, 0, 0, ?)";
                $insertStmt = $conn->prepare($insertQuery);
                $insertStmt->bind_param("ssii", $monthYear, $item_code, $closing_value, $closing_value);
                $insertStmt->execute();
                $insertStmt->close();
                
                debugLog("Inserted new record in $archive_table with opening=$closing_value");
            } else {
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
        
        $start_month++;
        if ($start_month > 12) {
            $start_month = 1;
            $start_year++;
        }
    }
    
    debugLog("Cascading completed to FY end: " . $fy_end_date);
}

function updatePreviousYearStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    debugLog("Updating previous year stock", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate
    ]);
    
    updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
    cascadeToFinancialYearEnd($conn, $comp_id, $itemCode, $purchaseDate, $totalBottles);
    updateItemStock($conn, $itemCode, $totalBottles, $comp_id);
}

function updateCurrentYearStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    debugLog("Updating current year stock", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate
    ]);
    
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $isArchived = isMonthArchived($conn, $comp_id, $month, $year);
    
    if ($isArchived) {
        debugLog("Month is archived, updating archive table with cascading");
        updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
        continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate, null);
    } else {
        debugLog("Month is current, updating current table with cascading");
        updateCurrentMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
    }
    
    updateItemStock($conn, $itemCode, $totalBottles, $comp_id);
}

function updateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn) {
    debugLog("updateStock called", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'company_id' => $companyId
    ]);
    
    if (isPreviousFinancialYear($purchaseDate)) {
        debugLog("Using PREVIOUS YEAR logic for stock update");
        updatePreviousYearStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    } else {
        debugLog("Using CURRENT YEAR logic for stock update");
        updateCurrentYearStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    }
}

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
    
    if (isPreviousFinancialYear($purchaseDate)) {
        debugLog("Using PREVIOUS YEAR logic for batch stock update");
        
        $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($year, -2);
        $archive_table = "tbldailystock_{$companyId}_{$month_2digit}_{$year_2digit}";
        
        $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
        $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                             WHERE table_schema = DATABASE() 
                             AND table_name = '$archive_table'";
        $check_result = $conn->query($check_table_query);
        $table_exists = $check_result ? $check_result->fetch_assoc()['count'] > 0 : false;
        
        if (!$table_exists) {
            createArchiveTable($conn, $companyId, $month, $year);
        }
        
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
        
        cascadeSubsequentDaysInMonth($conn, $archive_table, $monthYear, $itemCode, $dayOfMonth);
        cascadeToFinancialYearEnd($conn, $companyId, $itemCode, $purchaseDate, $totalBottles);
        
    } else {
        debugLog("Using CURRENT YEAR logic for batch stock update");
        
        $isArchived = isMonthArchived($conn, $companyId, $month, $year);
        
        if ($isArchived) {
            $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
            $year_2digit = substr($year, -2);
            $archive_table = "tbldailystock_{$companyId}_{$month_2digit}_{$year_2digit}";
            
            $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
            $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
            $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
            $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
            
            $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                                 WHERE table_schema = DATABASE() 
                                 AND table_name = '$archive_table'";
            $check_result = $conn->query($check_table_query);
            $table_exists = $check_result ? $check_result->fetch_assoc()['count'] > 0 : false;
            
            if (!$table_exists) {
                createArchiveTable($conn, $companyId, $month, $year);
            }
            
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
            
            cascadeSubsequentDaysInMonth($conn, $archive_table, $monthYear, $itemCode, $dayOfMonth);
            continueCascadingToCurrentMonth($conn, $companyId, $itemCode, $purchaseDate, null);
            
        } else {
            $dailyStockTable = "tbldailystock_" . $companyId;
            
            $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
            $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
            $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
            $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
            
            $check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                                 WHERE table_schema = DATABASE() 
                                 AND table_name = '$dailyStockTable'";
            $check_result = $conn->query($check_table_query);
            $table_exists = $check_result ? $check_result->fetch_assoc()['count'] > 0 : false;
            
            if (!$table_exists) {
                createCurrentMonthTable($conn, $companyId);
            }
            
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
            
            cascadeSubsequentDaysInMonth($conn, $dailyStockTable, $monthYear, $itemCode, $dayOfMonth);
        }
    }
    
    updateItemStock($conn, $itemCode, $totalBottles, $companyId);
}

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
// CSV PROCESSING FUNCTION (Headers in row 3 - matching working version)
// ============================================================================

function processSingleCSVFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName = '') {
    debugLog("Processing CSV file: " . $fileName);
    
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return [
            'successCount' => 0,
            'errorCount' => 1,
            'errors' => ["Cannot open file: $fileName"]
        ];
    }
    
    $rowNum = 0;
    $headers = [];
    $tpGroups = [];
    $headersFound = false;
    
    while (($data = fgetcsv($handle)) !== false) {
        $rowNum++;
        
        if (empty($data) || (count($data) == 1 && empty(trim($data[0])))) {
            continue;
        }
        
        // Row 3 should contain headers (matching working version)
        if ($rowNum == 3) {
            $headers = array_map(function($h) {
                $h = trim($h);
                $h = strtolower($h);
                $h = preg_replace('/[^a-z0-9\s]/', '', $h);
                $h = str_replace(' ', '_', $h);
                return $h;
            }, $data);
            
            debugLog("CSV Headers found at row 3", $headers);
            $headersFound = true;
            continue;
        }
        
        // Process data rows (row 4 onwards)
        if ($headersFound && $rowNum > 3) {
            $rowData = [];
            foreach ($headers as $index => $header) {
                if (isset($data[$index])) {
                    $rowData[$header] = trim($data[$index]);
                } else {
                    $rowData[$header] = '';
                }
            }
            
            if (empty($rowData['scm_item_code']) && empty($rowData['item_name'])) {
                continue;
            }
            
            $receivedDate = $rowData['received_date'] ?? '';
            $autoTpNo = $rowData['auto_tp_no'] ?? '';
            $manualTpNo = $rowData['manual_tp_no'] ?? '';
            $tpDate = $rowData['tp_date'] ?? '';
            $partyName = $rowData['party_name'] ?? '';
            $scmItemCode = $rowData['scm_item_code'] ?? '';
            $itemName = $rowData['item_name'] ?? '';
            $size = $rowData['size'] ?? '';
            $cases = floatval($rowData['qty_cases'] ?? 0);
            $bottles = intval($rowData['qty_bottles'] ?? 0);
            $mrp = floatval($rowData['mrp'] ?? 0);
            
            $purchaseDate = !empty($receivedDate) ? date('Y-m-d', strtotime($receivedDate)) : date('Y-m-d');
            if ($purchaseDate == '1970-01-01') $purchaseDate = date('Y-m-d');
            
            $formattedTpDate = !empty($tpDate) ? date('Y-m-d', strtotime($tpDate)) : '0000-00-00';
            if ($formattedTpDate == '1970-01-01') $formattedTpDate = '0000-00-00';
            
            $tpNo = !empty($manualTpNo) ? $manualTpNo : $autoTpNo;
            if (empty($tpNo)) continue;
            
            if (!isset($tpGroups[$tpNo])) {
                $tpGroups[$tpNo] = [
                    'date' => $purchaseDate,
                    'supplier' => $partyName,
                    'auto_tp_no' => $autoTpNo,
                    'manual_tp_no' => $manualTpNo,
                    'tp_date' => $formattedTpDate,
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
                'mrp' => $mrp
            ];
        }
    }
    fclose($handle);
    
    debugLog("Found TP groups", ['count' => count($tpGroups)]);
    
    if (count($tpGroups) == 0) {
        return [
            'successCount' => 0,
            'errorCount' => 1,
            'errors' => ["No valid TP data found in CSV. Headers should be in row 3."]
        ];
    }
    
    return processTPGroups($tpGroups, $companyId, $conn, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $importMode);
}

// ============================================================================
// EXCEL PROCESSING FUNCTION
// ============================================================================

function processSingleExcelFile($filePath, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName = '') {
    debugLog("Processing Excel file: " . $fileName);
    
    if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        return [
            'successCount' => 0,
            'errorCount' => 1,
            'errors' => ["Excel import is not available. Please install PhpSpreadsheet."]
        ];
    }
    
    try {
        $previousErrorReporting = error_reporting();
        error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
        
        $inputFileType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filePath);
        debugLog("Identified file type: " . $inputFileType);
        
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);
        $reader->setReadDataOnly(true);
        $spreadsheet = @$reader->load($filePath);
        
        if (!$spreadsheet) {
            throw new Exception("Failed to load Excel file");
        }
        
        $sheet = $spreadsheet->getActiveSheet();
        
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $rowData = [];
            foreach ($cellIterator as $cell) {
                $rowData[] = trim((string)$cell->getFormattedValue());
            }
            if (!empty(array_filter($rowData))) {
                $rows[] = $rowData;
            }
        }
        
        error_reporting($previousErrorReporting);
        
        if (empty($rows)) {
            throw new Exception("Excel file is empty");
        }
        
        // Find header row (try row 3 first)
        $headers = [];
        $headerRowNum = 0;
        $headersFound = false;
        
        // Try row 3 first (matching CSV logic)
        if (count($rows) >= 3) {
            $rowStr = array_map('strtoupper', $rows[2]);
            if (preg_grep('/RECEIVED|TP_NO|ITEM_CODE/', $rowStr)) {
                $headerRowNum = 3;
                $headers = array_map(function($h) {
                    $h = is_null($h) ? '' : trim($h);
                    $h = strtolower($h);
                    $h = preg_replace('/[^a-z0-9\s]/', '', $h);
                    $h = str_replace(' ', '_', $h);
                    return $h;
                }, $rows[2]);
                $headers = array_filter($headers);
                $headersFound = true;
                debugLog("Headers found at row $headerRowNum", $headers);
            }
        }
        
        // If not found, search other rows
        if (!$headersFound) {
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
                    $headers = array_filter($headers);
                    $headersFound = true;
                    debugLog("Headers found at row $headerRowNum", $headers);
                    break;
                }
            }
        }
        
        if (!$headersFound) {
            return [
                'successCount' => 0,
                'errorCount' => 1,
                'errors' => ["No header row found in Excel. Headers should be in row 3."]
            ];
        }
        
        // Process data rows
        $tpGroups = [];
        $processedRows = 0;
        $rowsWithTp = 0;
        
        foreach ($rows as $rowIndex => $row) {
            $rowNum = $rowIndex + 1;
            if ($rowNum <= $headerRowNum) continue;
            if (empty($row) || (count($row) == 1 && empty(trim($row[0])))) continue;
            
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
                continue;
            }
            
            $receivedDate = $rowData['received_date'] ?? '';
            $autoTpNo = $rowData['auto_tp_no'] ?? '';
            $manualTpNo = $rowData['manual_tp_no'] ?? '';
            $tpDate = $rowData['tp_date'] ?? '';
            $partyName = $rowData['party_name'] ?? '';
            $size = $rowData['size'] ?? '';
            $cases = floatval($rowData['qty_cases'] ?? 0);
            $bottles = intval($rowData['qty_bottles'] ?? 0);
            $mrp = floatval($rowData['mrp'] ?? 0);
            
            $purchaseDate = !empty($receivedDate) ? date('Y-m-d', strtotime($receivedDate)) : date('Y-m-d');
            if ($purchaseDate == '1970-01-01') $purchaseDate = date('Y-m-d');
            
            $formattedTpDate = !empty($tpDate) ? date('Y-m-d', strtotime($tpDate)) : '0000-00-00';
            if ($formattedTpDate == '1970-01-01') $formattedTpDate = '0000-00-00';
            
            $tpNo = !empty($manualTpNo) ? $manualTpNo : $autoTpNo;
            if (empty($tpNo)) continue;
            
            $rowsWithTp++;
            
            if (!isset($tpGroups[$tpNo])) {
                $tpGroups[$tpNo] = [
                    'date' => $purchaseDate,
                    'supplier' => $partyName,
                    'auto_tp_no' => $autoTpNo,
                    'manual_tp_no' => $manualTpNo,
                    'tp_date' => $formattedTpDate,
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
                'mrp' => $mrp
            ];
        }
        
        debugLog("Processed $processedRows rows, $rowsWithTp with TP numbers, found " . count($tpGroups) . " TP groups");
        
        if (count($tpGroups) == 0) {
            return [
                'successCount' => 0,
                'errorCount' => 1,
                'errors' => ["No valid data found in Excel"]
            ];
        }
        
        return processTPGroups($tpGroups, $companyId, $conn, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $importMode);
        
    } catch (Exception $e) {
        debugLog("Excel processing error: " . $e->getMessage());
        return [
            'successCount' => 0,
            'errorCount' => 1,
            'errors' => ["Excel error: " . $e->getMessage()]
        ];
    }
}

// ============================================================================
// TP GROUP PROCESSING
// ============================================================================

function processTPGroups($tpGroups, $companyId, $conn, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $importMode) {
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    
    debugLog("Processing TP groups", [
        'total_tps' => count($tpGroups)
    ]);
    
    // Batch load all items for performance
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
        if ($itemsStmt) {
            $itemsStmt->bind_param($types, ...$params);
            $itemsStmt->execute();
            $itemsResult = $itemsStmt->get_result();
            while ($item = $itemsResult->fetch_assoc()) {
                $allItems[$item['CODE']] = $item;
                $allItems[$item['SCM_CODE']] = $item;
            }
            $itemsStmt->close();
        }
    }
    
    debugLog("Loaded items for batch lookup", [
        'item_count' => count($allItems)
    ]);
    
    foreach ($tpGroups as $tpNo => $tpData) {
        debugLog("=== Processing TP: $tpNo ===");
        
        try {
            $conn->begin_transaction();
            
            $supplierInfo = findBestSupplierMatch($tpData['supplier'], $conn);
            $supplierCode = $supplierInfo ? $supplierInfo['CODE'] : '';
            
            debugLog("Supplier match result", [
                'input' => $tpData['supplier'],
                'found_code' => $supplierCode,
                'found_name' => $supplierInfo ? $supplierInfo['DETAILS'] : 'Not found'
            ]);
            
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
            
            $totalAmount = 0;
            $validItems = [];
            
            foreach ($tpData['items'] as $item) {
                $itemInfo = null;
                $cleanCode = cleanItemCode($item['scm_item_code']);
                
                if (isset($allItems[$item['scm_item_code']])) {
                    $itemInfo = $allItems[$item['scm_item_code']];
                } elseif (isset($allItems[$cleanCode])) {
                    $itemInfo = $allItems[$cleanCode];
                }
                
                if (!$itemInfo) {
                    debugLog("Item not found or license restricted", [
                        'scm_item_code' => $item['scm_item_code'],
                        'clean_code' => $cleanCode,
                        'allowed_classes' => $allowed_classes
                    ]);
                    continue;
                }
                
                $bottlesPerCase = $itemInfo ? intval($itemInfo['BOTTLE_PER_CASE']) : 12;
                $caseRate = $itemInfo ? floatval($itemInfo['PPRICE']) : 0;
                $amount = ($item['cases'] * $caseRate) + ($item['bottles'] * ($caseRate / $bottlesPerCase));
                $totalAmount += $amount;
                $totalBottles = ($item['cases'] * $bottlesPerCase) + $item['bottles'];
                
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
            
            $invNo = '';
            $invDate = '0000-00-00';
            $vocNoInt = (int)$nextVoc;
            $totalAmountStr = (string)$totalAmount;
            
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
            $stockUpdates = [];
            
            foreach ($validItems as $validItem) {
                $item = $validItem['data'];
                $itemInfo = $validItem['info'];
                $bottlesPerCase = $validItem['bottles_per_case'];
                $caseRate = $validItem['case_rate'];
                $amount = $validItem['amount'];
                $totalBottles = $validItem['total_bottles'];
                
                $bl = $item['bl'] > 0 ? $item['bl'] : 0.00;
                $vv = $item['vv'] > 0 ? $item['vv'] : 0.00;
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
            
            // Batch stock updates
            if ($updateStockFlag && !empty($stockUpdates)) {
                debugLog("Batch stock updates for TP", [
                    'tp_no' => $tpNo,
                    'date' => $tpData['date'],
                    'items' => $stockUpdates
                ]);
                
                foreach ($stockUpdates as $itemCode => $totalBottles) {
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
            
            $conn->commit();
            $successCount++;
            
            debugLog("Successfully imported TP", [
                'tp_no' => $tpNo,
                'purchase_id' => $purchaseId,
                'voucher_no' => $nextVoc,
                'items_inserted' => $itemsInserted
            ]);
            
        } catch (Exception $e) {
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

// ============================================================================
// MAIN - SINGLE FILE UPLOAD HANDLER (MODIFIED FROM MULTIPLE TO SINGLE)
// ============================================================================

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
    
    $fileName = $_FILES['excel_file']['name'];
    $fileSize = $_FILES['excel_file']['size'];
    $fileTmp = $_FILES['excel_file']['tmp_name'];
    
    debugLog("Processing single file: $fileName, Size: $fileSize");
    
    if ($fileSize > 10 * 1024 * 1024) {
        header("Location: purchase_module.php?mode=$importMode&import_error=File size exceeds 10MB");
        exit;
    }
    
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExtensions = ['csv', 'xls', 'xlsx'];
    
    if (!in_array($fileExt, $allowedExtensions)) {
        header("Location: purchase_module.php?mode=$importMode&import_error=Invalid file type. Please upload .csv, .xls, or .xlsx files.");
        exit;
    }
    
    if ($fileExt === 'csv') {
        $result = processSingleCSVFile($fileTmp, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName);
    } else {
        $result = processSingleExcelFile($fileTmp, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName);
    }
    
    // Renumber vouchers after successful import
    if ($result['successCount'] > 0) {
        renumberVoucherNumbers($conn, $companyId);
    }
    
    if ($result['errorCount'] > 0) {
        $errorMessage = "Imported {$result['successCount']} purchases. Errors: {$result['errorCount']}. ";
        if (count($result['errors']) > 0) {
            $errorMessage .= "First error: " . $result['errors'][0];
        }
        header("Location: purchase_module.php?mode=$importMode&import_error=" . urlencode($errorMessage));
    } else {
        header("Location: purchase_module.php?mode=$importMode&import_success=1");
    }
    exit;
}

header("Location: purchase_module.php");
exit;
?>