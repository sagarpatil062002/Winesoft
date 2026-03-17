<?php
// import_purchase.php - UPDATED FOR SCM CSV FORMAT WITH FIXED CASCADING
// Includes VOC_NO renumbering based on TP_DATE
// FIXED: Item codes stored with SCM prefix in ALL tables for consistency
session_start();

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

// ============================================================================
// FIXED: Function to get full SCM code - ALWAYS returns code with SCM prefix
// ============================================================================
function getFullItemCode($code) {
    $code = trim($code);
    // Remove any existing SCM prefix first to avoid duplication
    $code = preg_replace('/^SCM/i', '', $code);
    // Add SCM prefix
    return 'SCM' . $code;
}

// ============================================================================
// FIXED: Function to get clean code without SCM prefix (for lookup only)
// ============================================================================
function getCleanItemCode($code) {
    return preg_replace('/^SCM/i', '', trim($code));
}

// ============================================================================
// FIXED: Update MRP in tblitemmaster - uses SCM prefix for lookup
// ============================================================================
function updateItemMRP($conn, $itemCode, $mrp) {
    // Get clean code for database lookup (tblitemmaster stores without SCM)
    $cleanCode = getCleanItemCode($itemCode);
    
    debugLog("Updating MRP for item", [
        'full_code' => $itemCode,
        'clean_code' => $cleanCode,
        'mrp' => $mrp
    ]);
    
    // Update MPRICE in tblitemmaster
    $updateQuery = "UPDATE tblitemmaster SET MPRICE = ? WHERE CODE = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("ds", $mrp, $cleanCode);
    
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

// ============================================================================
// FIXED: Find item by code - ALWAYS returns item with FULL SCM code in the result
// ============================================================================
function findItem($itemCode, $conn, $allowed_classes) {
    $fullCode = getFullItemCode($itemCode);
    $cleanCode = getCleanItemCode($itemCode);
    
    debugLog("Finding item", [
        'original_code' => $itemCode,
        'full_code' => $fullCode,
        'clean_code' => $cleanCode
    ]);
    
    // Try exact match with clean code (tblitemmaster stores without SCM)
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
        
        // Add the FULL SCM code to the result for consistency
        $item['FULL_CODE'] = $fullCode;
        $item['STORAGE_CODE'] = $fullCode; // This is what we'll store in other tables
        
        debugLog("Item found", [
            'clean_code' => $item['CODE'],
            'full_code' => $item['FULL_CODE'],
            'details' => $item['DETAILS']
        ]);
        
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

// ============================================================================
// FIXED: UPDATED ARCHIVED MONTH STOCK WITH PROPER CASCADING
// Uses FULL item code with SCM prefix
// ============================================================================
function updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    // Ensure we're using the FULL code with SCM prefix
    $fullItemCode = getFullItemCode($itemCode);
    
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
    $daysInMonth = date('t', strtotime($purchaseDate));
    
    debugLog("Updating archived month stock", [
        'table' => $archive_table,
        'monthYear' => $monthYear,
        'full_item_code' => $fullItemCode,
        'dayOfMonth' => $dayOfMonth,
        'totalBottles' => $totalBottles,
        'daysInMonth' => $daysInMonth
    ]);
    
    // STEP 1: Ensure table exists
    ensureDailyStockTableExists($conn, $comp_id, $month, $year);
    
    // STEP 2: Get current record if exists
    $check_query = "SELECT * FROM $archive_table 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $fullItemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->num_rows > 0;
    $existing_data = $exists ? $result->fetch_assoc() : null;
    $check_stmt->close();
    
    // STEP 3: Calculate correct opening value for the purchase day
    $openingValue = 0;
    if ($dayOfMonth > 1) {
        // Opening comes from previous day's closing
        $prevDay = $dayOfMonth - 1;
        $prevDayClosingCol = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        if ($exists && isset($existing_data[$prevDayClosingCol])) {
            $openingValue = (int)$existing_data[$prevDayClosingCol];
        } else {
            // Need to get previous day's closing by calculating from day 1
            // This will be handled in the cascade
            $openingValue = 0;
        }
    } else {
        // For day 1, opening comes from previous month's last day
        // This will be handled by cascadeToFinancialYearEnd
        $openingValue = 0;
    }
    
    // STEP 4: Update or insert the purchase day
    if ($exists) {
        // Get current values
        $currentPurchase = isset($existing_data[$purchaseColumn]) ? (int)$existing_data[$purchaseColumn] : 0;
        $currentSales = isset($existing_data[$saleColumn]) ? (int)$existing_data[$saleColumn] : 0;
        
        // Calculate new values
        $newPurchase = $currentPurchase + $totalBottles;
        $newClosing = $openingValue + $newPurchase - $currentSales;
        
        // Update the record
        $update_query = "UPDATE $archive_table 
                        SET $purchaseColumn = ?,
                            $closingColumn = ?
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iiss", $newPurchase, $newClosing, $monthYear, $fullItemCode);
        $update_stmt->execute();
        $update_stmt->close();
        
        debugLog("Updated existing record", [
            'day' => $dayOfMonth,
            'opening' => $openingValue,
            'old_purchase' => $currentPurchase,
            'new_purchase' => $newPurchase,
            'sales' => $currentSales,
            'new_closing' => $newClosing
        ]);
    } else {
        // Insert new record
        $insert_query = "INSERT INTO $archive_table 
                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                        VALUES (?, ?, 'F', 0, ?, 0, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssii", $monthYear, $fullItemCode, $totalBottles, $totalBottles);
        $insert_stmt->execute();
        $insert_stmt->close();
        
        $newClosing = $totalBottles;
        
        debugLog("Inserted new record", [
            'day' => $dayOfMonth,
            'purchase' => $totalBottles,
            'closing' => $newClosing
        ]);
    }
    
    // STEP 5: CRITICAL - CASCADE THROUGH ALL SUBSEQUENT DAYS IN THIS MONTH
    $running_closing = $newClosing;
    
    for ($day = $dayOfMonth + 1; $day <= $daysInMonth; $day++) {
        $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
        $prev_day_str = str_pad($day - 1, 2, '0', STR_PAD_LEFT);
        
        $currentDayOpening = "DAY_{$day_str}_OPEN";
        $currentDayPurchase = "DAY_{$day_str}_PURCHASE";
        $currentDaySales = "DAY_{$day_str}_SALES";
        $currentDayClosing = "DAY_{$day_str}_CLOSING";
        
        // Get current values for this day
        $get_values_query = "SELECT $currentDayPurchase, $currentDaySales 
                            FROM $archive_table 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $values_stmt = $conn->prepare($get_values_query);
        $values_stmt->bind_param("ss", $monthYear, $fullItemCode);
        $values_stmt->execute();
        $values_result = $values_stmt->get_result();
        $values_row = $values_result->fetch_assoc();
        $values_stmt->close();
        
        $dayPurchase = $values_row ? (int)$values_row[$currentDayPurchase] : 0;
        $daySales = $values_row ? (int)$values_row[$currentDaySales] : 0;
        
        // Opening = previous day's closing
        $dayOpening = $running_closing;
        $dayClosing = $dayOpening + $dayPurchase - $daySales;
        
        // Update the day
        $update_day_query = "UPDATE $archive_table 
                            SET $currentDayOpening = ?,
                                $currentDayClosing = ?
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $day_stmt = $conn->prepare($update_day_query);
        $day_stmt->bind_param("iiss", $dayOpening, $dayClosing, $monthYear, $fullItemCode);
        $day_stmt->execute();
        $day_stmt->close();
        
        debugLog("Cascaded day $day", [
            'opening' => $dayOpening,
            'purchase' => $dayPurchase,
            'sales' => $daySales,
            'closing' => $dayClosing
        ]);
        
        $running_closing = $dayClosing;
    }
    
    debugLog("Month-end closing value for $monthYear: $running_closing");
    
    return $running_closing;
}

// ============================================================================
// FIXED: CASCADE TO FINANCIAL YEAR END WITH PROPER DAY-BY-DAY CASCADING
// Uses FULL item code with SCM prefix
// ============================================================================
function cascadeToFinancialYearEnd($conn, $comp_id, $item_code, $purchase_date, $total_bottles) {
    // Ensure we're using the FULL code with SCM prefix
    $fullItemCode = getFullItemCode($item_code);
    
    $purchase_timestamp = strtotime($purchase_date);
    $purchase_month = date('n', $purchase_timestamp);
    $purchase_year = date('Y', $purchase_timestamp);
    
    // Get financial year end date (March 31 of next year)
    $fy_end_date = ($purchase_month <= 3) ? 
                   date('Y-m-d', strtotime("$purchase_year-03-31")) : 
                   date('Y-m-d', strtotime(($purchase_year + 1) . "-03-31"));
    
    $fy_end_month = 3;
    $fy_end_year = ($purchase_month <= 3) ? $purchase_year : ($purchase_year + 1);
    
    debugLog("Cascading to FY end for previous year purchase", [
        'full_item_code' => $fullItemCode,
        'purchase_date' => $purchase_date,
        'purchase_month' => $purchase_month,
        'purchase_year' => $purchase_year,
        'fy_end_date' => $fy_end_date,
        'fy_end_month' => $fy_end_month,
        'fy_end_year' => $fy_end_year
    ]);
    
    // STEP 1: Get the CORRECT closing value from END of purchase month
    $purchase_month_2digit = str_pad($purchase_month, 2, '0', STR_PAD_LEFT);
    $purchase_year_2digit = substr($purchase_year, -2);
    $purchase_table = "tbldailystock_{$comp_id}_{$purchase_month_2digit}_{$purchase_year_2digit}";
    
    $days_in_purchase_month = date('t', $purchase_timestamp);
    $last_day_str = str_pad($days_in_purchase_month, 2, '0', STR_PAD_LEFT);
    $closing_col = "DAY_{$last_day_str}_CLOSING";
    
    // Get the month-end closing value
    $get_closing_query = "SELECT $closing_col as closing FROM $purchase_table 
                         WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $closing_stmt = $conn->prepare($get_closing_query);
    $purchase_month_year = date('Y-m', $purchase_timestamp);
    $closing_stmt->bind_param("ss", $fullItemCode, $purchase_month_year);
    $closing_stmt->execute();
    $closing_result = $closing_stmt->get_result();
    
    $carry_forward = 0;
    if ($closing_result->num_rows > 0) {
        $closing_row = $closing_result->fetch_assoc();
        $carry_forward = (int)$closing_row['closing'];
    }
    $closing_stmt->close();
    
    debugLog("Month-end closing value", [
        'month' => $purchase_month_year,
        'last_day' => $days_in_purchase_month,
        'closing' => $carry_forward
    ]);
    
    // STEP 2: Start from next month
    $current_month = $purchase_month + 1;
    $current_year = $purchase_year;
    
    if ($current_month > 12) {
        $current_month = 1;
        $current_year++;
    }
    
    // STEP 3: Loop through each month until FY end
    while ($current_year < $fy_end_year || 
           ($current_year == $fy_end_year && $current_month <= $fy_end_month)) {
        
        $month_2digit = str_pad($current_month, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($current_year, -2);
        $target_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        $month_year = date('Y-m', strtotime("$current_year-$current_month-01"));
        $days_in_month = date('t', strtotime("$current_year-$current_month-01"));
        
        debugLog("Processing month", [
            'table' => $target_table,
            'month' => $current_month,
            'year' => $current_year,
            'carry_forward' => $carry_forward,
            'days_in_month' => $days_in_month
        ]);
        
        // Ensure table exists
        ensureDailyStockTableExists($conn, $comp_id, $current_month, $current_year);
        
        // Check if record exists for this item
        $check_query = "SELECT * FROM $target_table 
                       WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ss", $month_year, $fullItemCode);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $exists = $check_result->num_rows > 0;
        $existing_data = $exists ? $check_result->fetch_assoc() : null;
        $check_stmt->close();
        
        // STEP 4: Set up day 1 with opening = carry_forward
        if (!$exists) {
            // Insert new record with opening = carry_forward
            $insert_query = "INSERT INTO $target_table 
                            (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                            VALUES (?, ?, 'F', ?, 0, 0, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssii", $month_year, $fullItemCode, $carry_forward, $carry_forward);
            $insert_stmt->execute();
            $insert_stmt->close();
            
            debugLog("Inserted new record with opening=$carry_forward");
            
            // Set day 1 closing
            $day1_closing = $carry_forward;
        } else {
            // Update existing record
            $update_query = "UPDATE $target_table 
                            SET DAY_01_OPEN = ?,
                                DAY_01_CLOSING = ? + DAY_01_PURCHASE - DAY_01_SALES
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("iiss", $carry_forward, $carry_forward, $month_year, $fullItemCode);
            $update_stmt->execute();
            $update_stmt->close();
            
            // Get day 1 closing after update
            $get_day1_query = "SELECT DAY_01_CLOSING as closing FROM $target_table 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $day1_stmt = $conn->prepare($get_day1_query);
            $day1_stmt->bind_param("ss", $month_year, $fullItemCode);
            $day1_stmt->execute();
            $day1_result = $day1_stmt->get_result();
            $day1_row = $day1_result->fetch_assoc();
            $day1_closing = $day1_row ? (int)$day1_row['closing'] : $carry_forward;
            $day1_stmt->close();
            
            debugLog("Updated existing record with opening=$carry_forward, day1 closing=$day1_closing");
        }
        
        // STEP 5: CASCADE THROUGH ALL DAYS OF THIS MONTH
        $running_closing = $day1_closing;
        
        // Get all purchase/sales values for this month
        $columns = [];
        for ($day = 1; $day <= $days_in_month; $day++) {
            $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
            $columns["DAY_{$day_str}_PURCHASE"] = 0;
            $columns["DAY_{$day_str}_SALES"] = 0;
        }
        
        // Get actual values from database
        $select_query = "SELECT ";
        $select_fields = [];
        for ($day = 1; $day <= $days_in_month; $day++) {
            $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
            $select_fields[] = "DAY_{$day_str}_PURCHASE";
            $select_fields[] = "DAY_{$day_str}_SALES";
        }
        $select_query .= implode(', ', $select_fields) . " FROM $target_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        
        $select_stmt = $conn->prepare($select_query);
        $select_stmt->bind_param("ss", $month_year, $fullItemCode);
        $select_stmt->execute();
        $select_result = $select_stmt->get_result();
        if ($select_result->num_rows > 0) {
            $row = $select_result->fetch_assoc();
            foreach ($row as $key => $value) {
                $columns[$key] = (int)$value;
            }
        }
        $select_stmt->close();
        
        // Now update day by day
        for ($day = 2; $day <= $days_in_month; $day++) {
            $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
            $prev_day_str = str_pad($day - 1, 2, '0', STR_PAD_LEFT);
            
            $dayPurchase = $columns["DAY_{$day_str}_PURCHASE"];
            $daySales = $columns["DAY_{$day_str}_SALES"];
            
            // Opening = previous day's closing
            $dayOpening = $running_closing;
            $dayClosing = $dayOpening + $dayPurchase - $daySales;
            
            // Update the day
            $update_day_query = "UPDATE $target_table 
                                SET DAY_{$day_str}_OPEN = ?,
                                    DAY_{$day_str}_CLOSING = ?
                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $day_stmt = $conn->prepare($update_day_query);
            $day_stmt->bind_param("iiss", $dayOpening, $dayClosing, $month_year, $fullItemCode);
            $day_stmt->execute();
            $day_stmt->close();
            
            debugLog("Month $current_month day $day", [
                'opening' => $dayOpening,
                'purchase' => $dayPurchase,
                'sales' => $daySales,
                'closing' => $dayClosing
            ]);
            
            $running_closing = $dayClosing;
        }
        
        // This month's closing becomes next month's opening
        $carry_forward = $running_closing;
        
        debugLog("Month $current_month completed with closing: $carry_forward");
        
        // Move to next month
        $current_month++;
        if ($current_month > 12) {
            $current_month = 1;
            $current_year++;
        }
    }
    
    debugLog("Cascading completed to FY end: $fy_end_date with final closing: $carry_forward");
}

// ============================================================================
// FIXED: UPDATE CURRENT MONTH STOCK WITH PROPER CASCADING
// Uses FULL item code with SCM prefix
// ============================================================================
function updateCurrentMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    // Ensure we're using the FULL code with SCM prefix
    $fullItemCode = getFullItemCode($itemCode);
    
    $dayOfMonth = date('j', strtotime($purchaseDate));
    $month = date('n', strtotime($purchaseDate));
    $year = date('Y', strtotime($purchaseDate));
    $monthYear = date('Y-m', strtotime($purchaseDate));
    $dailyStockTable = "tbldailystock_" . $comp_id;
    $daysInMonth = date('t', strtotime($purchaseDate));
    
    $purchaseColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
    $saleColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_SALES";
    $openingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_OPEN";
    $closingColumn = "DAY_" . str_pad($dayOfMonth, 2, '0', STR_PAD_LEFT) . "_CLOSING";
    
    debugLog("Updating current month stock", [
        'table' => $dailyStockTable,
        'monthYear' => $monthYear,
        'full_item_code' => $fullItemCode,
        'dayOfMonth' => $dayOfMonth,
        'totalBottles' => $totalBottles,
        'daysInMonth' => $daysInMonth
    ]);
    
    // STEP 1: Get current record if exists
    $check_query = "SELECT * FROM $dailyStockTable 
                   WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $monthYear, $fullItemCode);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    $exists = $result->num_rows > 0;
    $existing_data = $exists ? $result->fetch_assoc() : null;
    $check_stmt->close();
    
    // STEP 2: Calculate correct opening value for the purchase day
    $openingValue = 0;
    if ($dayOfMonth > 1) {
        // Opening comes from previous day's closing
        $prevDay = $dayOfMonth - 1;
        $prevDayClosingCol = "DAY_" . str_pad($prevDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        if ($exists && isset($existing_data[$prevDayClosingCol])) {
            $openingValue = (int)$existing_data[$prevDayClosingCol];
        }
    }
    
    // STEP 3: Update or insert the purchase day
    if ($exists) {
        $currentPurchase = isset($existing_data[$purchaseColumn]) ? (int)$existing_data[$purchaseColumn] : 0;
        $currentSales = isset($existing_data[$saleColumn]) ? (int)$existing_data[$saleColumn] : 0;
        
        $newPurchase = $currentPurchase + $totalBottles;
        $newClosing = $openingValue + $newPurchase - $currentSales;
        
        $update_query = "UPDATE $dailyStockTable 
                        SET $purchaseColumn = ?,
                            $closingColumn = ?
                        WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iiss", $newPurchase, $newClosing, $monthYear, $fullItemCode);
        $update_stmt->execute();
        $update_stmt->close();
        
        debugLog("Updated existing record", [
            'day' => $dayOfMonth,
            'opening' => $openingValue,
            'old_purchase' => $currentPurchase,
            'new_purchase' => $newPurchase,
            'sales' => $currentSales,
            'new_closing' => $newClosing
        ]);
    } else {
        $insert_query = "INSERT INTO $dailyStockTable 
                        (STK_MONTH, ITEM_CODE, LIQ_FLAG, $openingColumn, $purchaseColumn, $saleColumn, $closingColumn) 
                        VALUES (?, ?, 'F', 0, ?, 0, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssii", $monthYear, $fullItemCode, $totalBottles, $totalBottles);
        $insert_stmt->execute();
        $insert_stmt->close();
        
        $newClosing = $totalBottles;
        
        debugLog("Inserted new record", [
            'day' => $dayOfMonth,
            'purchase' => $totalBottles,
            'closing' => $newClosing
        ]);
    }
    
    // STEP 4: CASCADE THROUGH ALL SUBSEQUENT DAYS IN THIS MONTH
    $running_closing = $newClosing;
    
    for ($day = $dayOfMonth + 1; $day <= $daysInMonth; $day++) {
        $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
        
        $currentDayOpening = "DAY_{$day_str}_OPEN";
        $currentDayPurchase = "DAY_{$day_str}_PURCHASE";
        $currentDaySales = "DAY_{$day_str}_SALES";
        $currentDayClosing = "DAY_{$day_str}_CLOSING";
        
        // Get current values for this day
        $get_values_query = "SELECT $currentDayPurchase, $currentDaySales 
                            FROM $dailyStockTable 
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $values_stmt = $conn->prepare($get_values_query);
        $values_stmt->bind_param("ss", $monthYear, $fullItemCode);
        $values_stmt->execute();
        $values_result = $values_stmt->get_result();
        $values_row = $values_result->fetch_assoc();
        $values_stmt->close();
        
        $dayPurchase = $values_row ? (int)$values_row[$currentDayPurchase] : 0;
        $daySales = $values_row ? (int)$values_row[$currentDaySales] : 0;
        
        // Opening = previous day's closing
        $dayOpening = $running_closing;
        $dayClosing = $dayOpening + $dayPurchase - $daySales;
        
        // Update the day
        $update_day_query = "UPDATE $dailyStockTable 
                            SET $currentDayOpening = ?,
                                $currentDayClosing = ?
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $day_stmt = $conn->prepare($update_day_query);
        $day_stmt->bind_param("iiss", $dayOpening, $dayClosing, $monthYear, $fullItemCode);
        $day_stmt->execute();
        $day_stmt->close();
        
        debugLog("Cascaded day $day", [
            'opening' => $dayOpening,
            'purchase' => $dayPurchase,
            'sales' => $daySales,
            'closing' => $dayClosing
        ]);
        
        $running_closing = $dayClosing;
    }
    
    debugLog("Month-end closing value for $monthYear: $running_closing");
    
    return $running_closing;
}

// ============================================================================
// HELPER FUNCTION TO ENSURE DAILY STOCK TABLE EXISTS
// ============================================================================
function ensureDailyStockTableExists($conn, $comp_id, $month, $year) {
    $month_2digit = str_pad($month, 2, '0', STR_PAD_LEFT);
    $year_2digit = substr($year, -2);
    $table_name = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
    
    $check_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                   WHERE table_schema = DATABASE() AND table_name = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $table_name);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if (!$exists) {
        $days_in_month = date('t', strtotime("$year-$month-01"));
        
        $create_query = "CREATE TABLE $table_name (
            `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
            `STK_DATE` date NOT NULL,
            `STK_MONTH` varchar(7) NOT NULL,
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
            debugLog("Created table: $table_name");
        } else {
            debugLog("Failed to create table: $table_name - " . $conn->error);
        }
    }
    
    return $table_name;
}

// ============================================================================
// FIXED: Function to update item stock in tblitem_stock
// Uses FULL item code with SCM prefix
// ============================================================================
function updateItemStock($conn, $itemCode, $totalBottles, $companyId) {
    // Ensure we're using the FULL code with SCM prefix
    $fullItemCode = getFullItemCode($itemCode);
    
    $stockColumn = "CURRENT_STOCK" . $companyId;
    
    debugLog("Updating item stock", [
        'full_item_code' => $fullItemCode,
        'total_bottles' => $totalBottles,
        'company_id' => $companyId,
        'stock_column' => $stockColumn
    ]);
    
    // Check if record exists
    $checkQuery = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("s", $fullItemCode);
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
        $itemStmt->bind_param("is", $totalBottles, $fullItemCode);
        $result = $itemStmt->execute();
        $itemStmt->close();
        
        debugLog("Updated existing stock record", [
            'result' => $result,
            'added' => $totalBottles
        ]);
    } else {
        // Insert new stock record
        $insertItemStockQuery = "INSERT INTO tblitem_stock (ITEM_CODE, $stockColumn) 
                                VALUES (?, ?)";
        
        $itemStmt = $conn->prepare($insertItemStockQuery);
        $itemStmt->bind_param("si", $fullItemCode, $totalBottles);
        $result = $itemStmt->execute();
        $itemStmt->close();
        
        debugLog("Inserted new stock record", [
            'result' => $result,
            'initial_stock' => $totalBottles
        ]);
    }
    
    return $result;
}

// Function to update previous year stock
function updatePreviousYearStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate) {
    debugLog("Updating previous year stock", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate
    ]);
    
    // Update archived month stock (this now handles full month cascading)
    updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
    
    // Cascade through all remaining months until FY end
    cascadeToFinancialYearEnd($conn, $comp_id, $itemCode, $purchaseDate, $totalBottles);
    
    // Update tblitem_stock
    updateItemStock($conn, $itemCode, $totalBottles, $comp_id);
}

// Function to update current year stock
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
        debugLog("Month is archived, updating archive table");
        updateArchivedMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
        // Continue cascading to current month
        continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate);
    } else {
        debugLog("Month is current, updating current table");
        updateCurrentMonthStock($conn, $comp_id, $itemCode, $totalBottles, $purchaseDate);
    }
    
    // Update tblitem_stock
    updateItemStock($conn, $itemCode, $totalBottles, $comp_id);
}

// Function to continue cascading from archived month to current month
function continueCascadingToCurrentMonth($conn, $comp_id, $itemCode, $purchaseDate) {
    // Ensure we're using the FULL code with SCM prefix
    $fullItemCode = getFullItemCode($itemCode);
    
    $purchase_month = date('n', strtotime($purchaseDate));
    $purchase_year = date('Y', strtotime($purchaseDate));
    $current_month = date('n');
    $current_year = date('Y');
    
    debugLog("Continuing cascading to current month", [
        'comp_id' => $comp_id,
        'full_item_code' => $fullItemCode,
        'purchase_date' => $purchaseDate,
        'purchase_month' => $purchase_month,
        'purchase_year' => $purchase_year,
        'current_month' => $current_month,
        'current_year' => $current_year
    ]);
    
    // If purchase is in current month, no need to continue
    if ($purchase_month == $current_month && $purchase_year == $current_year) {
        debugLog("Purchase is in current month, no continuation needed");
        return;
    }
    
    // Get the closing value from purchase month end
    $purchase_month_2digit = str_pad($purchase_month, 2, '0', STR_PAD_LEFT);
    $purchase_year_2digit = substr($purchase_year, -2);
    $purchase_table = "tbldailystock_{$comp_id}_{$purchase_month_2digit}_{$purchase_year_2digit}";
    
    $days_in_purchase_month = date('t', strtotime($purchaseDate));
    $last_day_str = str_pad($days_in_purchase_month, 2, '0', STR_PAD_LEFT);
    $closing_col = "DAY_{$last_day_str}_CLOSING";
    
    $get_closing_query = "SELECT $closing_col as closing FROM $purchase_table 
                         WHERE ITEM_CODE = ? AND STK_MONTH = ?";
    $closing_stmt = $conn->prepare($get_closing_query);
    $purchase_month_year = date('Y-m', strtotime($purchaseDate));
    $closing_stmt->bind_param("ss", $fullItemCode, $purchase_month_year);
    $closing_stmt->execute();
    $closing_result = $closing_stmt->get_result();
    
    $carry_forward = 0;
    if ($closing_result->num_rows > 0) {
        $closing_row = $closing_result->fetch_assoc();
        $carry_forward = (int)$closing_row['closing'];
    }
    $closing_stmt->close();
    
    debugLog("Carrying forward from purchase month end", [
        'month' => $purchase_month_year,
        'closing' => $carry_forward
    ]);
    
    // Start from next month after purchase
    $start_month = $purchase_month + 1;
    $start_year = $purchase_year;
    if ($start_month > 12) {
        $start_month = 1;
        $start_year++;
    }
    
    // Loop through months until current month
    while ($start_year < $current_year || ($start_year == $current_year && $start_month <= $current_month)) {
        $month_2digit = str_pad($start_month, 2, '0', STR_PAD_LEFT);
        $year_2digit = substr($start_year, -2);
        $target_table = "tbldailystock_{$comp_id}_{$month_2digit}_{$year_2digit}";
        $month_year = date('Y-m', strtotime("$start_year-$start_month-01"));
        $days_in_month = date('t', strtotime("$start_year-$start_month-01"));
        
        debugLog("Processing continuation month", [
            'table' => $target_table,
            'month' => $start_month,
            'year' => $start_year,
            'carry_forward' => $carry_forward
        ]);
        
        // Ensure table exists
        ensureDailyStockTableExists($conn, $comp_id, $start_month, $start_year);
        
        // Check if record exists
        $check_query = "SELECT * FROM $target_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bind_param("ss", $month_year, $fullItemCode);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $exists = $check_result->num_rows > 0;
        $check_stmt->close();
        
        if (!$exists) {
            // Insert new record
            $insert_query = "INSERT INTO $target_table 
                            (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_01_OPEN, DAY_01_PURCHASE, DAY_01_SALES, DAY_01_CLOSING) 
                            VALUES (?, ?, 'F', ?, 0, 0, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssii", $month_year, $fullItemCode, $carry_forward, $carry_forward);
            $insert_stmt->execute();
            $insert_stmt->close();
            
            debugLog("Inserted new record with opening=$carry_forward");
        } else {
            // Update existing record
            $update_query = "UPDATE $target_table 
                            SET DAY_01_OPEN = ?,
                                DAY_01_CLOSING = ? + DAY_01_PURCHASE - DAY_01_SALES
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("iiss", $carry_forward, $carry_forward, $month_year, $fullItemCode);
            $update_stmt->execute();
            $update_stmt->close();
            
            debugLog("Updated existing record with opening=$carry_forward");
        }
        
        // Get day 1 closing
        $get_day1_query = "SELECT DAY_01_CLOSING as closing FROM $target_table 
                          WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $day1_stmt = $conn->prepare($get_day1_query);
        $day1_stmt->bind_param("ss", $month_year, $fullItemCode);
        $day1_stmt->execute();
        $day1_result = $day1_stmt->get_result();
        $day1_row = $day1_result->fetch_assoc();
        $day1_closing = $day1_row ? (int)$day1_row['closing'] : $carry_forward;
        $day1_stmt->close();
        
        // Cascade through all days of this month
        $running_closing = $day1_closing;
        
        // Get all purchase/sales values
        $columns = [];
        $select_query = "SELECT ";
        $select_fields = [];
        for ($day = 1; $day <= $days_in_month; $day++) {
            $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
            $select_fields[] = "DAY_{$day_str}_PURCHASE";
            $select_fields[] = "DAY_{$day_str}_SALES";
        }
        $select_query .= implode(', ', $select_fields) . " FROM $target_table WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        
        $select_stmt = $conn->prepare($select_query);
        $select_stmt->bind_param("ss", $month_year, $fullItemCode);
        $select_stmt->execute();
        $select_result = $select_stmt->get_result();
        if ($select_result->num_rows > 0) {
            $row = $select_result->fetch_assoc();
            foreach ($row as $key => $value) {
                $columns[$key] = (int)$value;
            }
        }
        $select_stmt->close();
        
        // Update days 2 onwards
        for ($day = 2; $day <= $days_in_month; $day++) {
            $day_str = str_pad($day, 2, '0', STR_PAD_LEFT);
            
            $dayPurchase = $columns["DAY_{$day_str}_PURCHASE"] ?? 0;
            $daySales = $columns["DAY_{$day_str}_SALES"] ?? 0;
            
            $dayOpening = $running_closing;
            $dayClosing = $dayOpening + $dayPurchase - $daySales;
            
            $update_day_query = "UPDATE $target_table 
                                SET DAY_{$day_str}_OPEN = ?,
                                    DAY_{$day_str}_CLOSING = ?
                                WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $day_stmt = $conn->prepare($update_day_query);
            $day_stmt->bind_param("iiss", $dayOpening, $dayClosing, $month_year, $fullItemCode);
            $day_stmt->execute();
            $day_stmt->close();
            
            $running_closing = $dayClosing;
        }
        
        // This month's closing becomes next month's opening
        $carry_forward = $running_closing;
        
        debugLog("Month $start_month completed with closing: $carry_forward");
        
        // Move to next month
        $start_month++;
        if ($start_month > 12) {
            $start_month = 1;
            $start_year++;
        }
    }
}

// Function to update stock after purchase - Main Entry Point
function updateStock($itemCode, $totalBottles, $purchaseDate, $companyId, $conn) {
    debugLog("updateStock called", [
        'item_code' => $itemCode,
        'total_bottles' => $totalBottles,
        'purchase_date' => $purchaseDate,
        'company_id' => $companyId
    ]);
    
    // Determine if purchase is in current or previous financial year
    // This function is now coming from stock_functions.php
    if (isPreviousFinancialYear($purchaseDate)) {
        debugLog("Using PREVIOUS YEAR logic for stock update");
        updatePreviousYearStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    } else {
        debugLog("Using CURRENT YEAR logic for stock update");
        updateCurrentYearStock($conn, $companyId, $itemCode, $totalBottles, $purchaseDate);
    }
}

// ============================================================================
// REMOVED: isPreviousFinancialYear function - now using from stock_functions.php
// ============================================================================

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
    
    $allowedExtensions = ['csv'];
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
            $allErrors[] = "File '$fileName': Invalid file type. Please upload .csv files only.";
            $totalErrorCount++;
            continue;
        }
        
        debugLog("Processing file " . ($i + 1) . ": $fileName");
        
        // Process this CSV file
        $result = processSingleCSVFile($fileTmp, $companyId, $conn, $importMode, $defaultStatus, $updateMRP, $updateStockFlag, $allowed_classes, $fileName);
        
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

// Function to process a single CSV file
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
            $vv = floatval($rowData['vv_'] ?? 0);
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
    
    debugLog("Found TP groups in $fileName", [
        'count' => count($tpGroups),
        'tps' => array_keys($tpGroups)
    ]);
    
    // If no TP groups were found, show error
    if (count($tpGroups) == 0) {
        return [
            'successCount' => 0,
            'errorCount' => 1,
            'errors' => ["No valid TP data found in CSV. Please check that your CSV has the correct format."]
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
            // Store by both clean code and SCM code for easy lookup
            $allItems[$item['CODE']] = $item;
            $allItems['SCM' . $item['CODE']] = $item; // Also index by SCM code
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
                $fullCode = getFullItemCode($item['scm_item_code']);
                $cleanCode = getCleanItemCode($item['scm_item_code']);
                
                // Try to find by full SCM code first
                if (isset($allItems[$fullCode])) {
                    $itemInfo = $allItems[$fullCode];
                    debugLog("Item found by full code", [
                        'full_code' => $fullCode,
                        'clean_code' => $itemInfo['CODE']
                    ]);
                }
                // Try by clean code
                elseif (isset($allItems[$cleanCode])) {
                    $itemInfo = $allItems[$cleanCode];
                    debugLog("Item found by clean code", [
                        'clean_code' => $cleanCode,
                        'full_code' => $fullCode
                    ]);
                }
                
                if (!$itemInfo) {
                    debugLog("Item not found or license restricted", [
                        'scm_item_code' => $item['scm_item_code'],
                        'full_code' => $fullCode,
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
                
                // Use the FULL SCM code for storage in other tables
                $storageCode = $fullCode;
                
                $validItems[] = [
                    'data' => $item,
                    'info' => $itemInfo,
                    'storage_code' => $storageCode,
                    'bottles_per_case' => $bottlesPerCase,
                    'case_rate' => $caseRate,
                    'amount' => $amount,
                    'total_bottles' => $totalBottles
                ];
                
                debugLog("Item calculation", [
                    'scm_item_code' => $item['scm_item_code'],
                    'storage_code' => $storageCode,
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
                $tpData['date'],        // DATE (s)
                $supplierCode,          // SUBCODE (s)
                $autoTpNo,              // AUTO_TPNO (s)
                $vocNoInt,              // VOC_NO (i)
                $invNo,                 // INV_NO (s)
                $invDate,               // INV_DATE (s)
                $totalAmountStr,        // TAMT (s)
                $tpNo,                  // TPNO (s)
                $tpData['tp_date'],     // TP_DATE (s)
                $defaultStatus,         // PUR_FLAG (s)
                $companyId              // CompID (i)
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
                $storageCode = $validItem['storage_code'];
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
                $autoBatch = ''; // Empty for AutoBatch
                
                debugLog("Inserting item detail", [
                    'purchase_id' => $purchaseId,
                    'item_code' => $storageCode, // Using full SCM code
                    'total_bottles' => $totalBottles,
                    'bl' => $bl,
                    'vv' => $vv,
                    'amount' => $amount
                ]);
                
                // Bind parameters with correct types
                $detailStmt->bind_param(
                    "isssdddddddsssdddi",
                    $purchaseId,            // PurchaseID (i)
                    $storageCode,           // ItemCode (s) - NOW USING FULL SCM CODE
                    $item['item_name'],     // ItemName (s)
                    $item['size'],          // Size (s)
                    $item['cases'],         // Cases (d)
                    $item['bottles'],       // Bottles (d)
                    $item['free_cases'],    // FreeCases (d)
                    $item['free_bottles'],  // FreeBottles (d)
                    $caseRate,              // CaseRate (d)
                    $item['mrp'],           // MRP (d)
                    $amount,                // Amount (d)
                    $bottlesPerCase,        // BottlesPerCase (d)
                    $batchNo,               // BatchNo (s)
                    $autoBatch,             // AutoBatch (s)
                    $mfgMonth,              // MfgMonth (s)
                    $bl,                    // BL (d)
                    $vv,                    // VV (d)
                    $totalBottles           // TotBott (i)
                );
                
                if (!$detailStmt->execute()) {
                    throw new Exception("Error inserting purchase detail for item {$storageCode}: " . $detailStmt->error);
                }
                
                $itemsInserted++;
                
                // Update MRP if requested
                if ($updateMRP && $item['mrp'] > 0) {
                    updateItemMRP($conn, $storageCode, $item['mrp']);
                }
                
                // Update stock if requested
                if ($updateStockFlag) {
                    updateStock($storageCode, $totalBottles, $tpData['date'], $companyId, $conn);
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