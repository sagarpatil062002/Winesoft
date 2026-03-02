<?php
// Start session at the very beginning
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

include_once "../config/db.php"; // MySQLi connection in $conn
require_once 'drydays_functions.php'; // Single include
require_once 'license_functions.php'; // ADDED: Include license 
require_once 'cash_memo_functions.php'; // ADDED: Include cash memo functions

// Initialize variables to prevent undefined variable warnings
$totalBills = 0;
$totalAmount = 0.00;
$bill_data = [];
$bill_items = [];
$bill_total = 0;
$all_bills = [];
$showPrintSection = false;
$billsWithoutCashMemo = 0;

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'foreign';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$bill_no = isset($_GET['bill_no']) ? $_GET['bill_no'] : '';

// Function to generate cash memo text exactly as shown in image - UPDATED for license type
if (!function_exists('generateCashMemoText')) {
function generateCashMemoText($companyData, $billData, $billItems, $permitData) {
    $text = "";
    
    // License info with type - centered - FIXED: Now uses license_type and licenseNumber
    $licenseText = "License No: ";
    if (!empty($companyData['license_type'])) {
        $licenseText .= $companyData['license_type'] . " ";
    }
    $licenseText .= !empty($companyData['licenseNumber']) ? $companyData['licenseNumber'] : "FL-II 3";
    $text .= str_pad($licenseText, 40, " ", STR_PAD_BOTH) . "\n";
    
    // GST and MVAT info if available
    if (!empty($companyData['gst_no']) || !empty($companyData['mvat_no'])) {
        $taxInfo = [];
        if (!empty($companyData['gst_no'])) {
            $taxInfo[] = "GST: " . $companyData['gst_no'];
        }
        if (!empty($companyData['mvat_no'])) {
            $taxInfo[] = "MVAT: " . $companyData['mvat_no'];
        }
        $text .= str_pad(implode(" | ", $taxInfo), 40, " ", STR_PAD_BOTH) . "\n";
    }
    
    // Shop name and address - centered
    $text .= str_pad($companyData['name'], 40, " ", STR_PAD_BOTH) . "\n";
    $text .= str_pad($companyData['address'], 40, " ", STR_PAD_BOTH) . "\n\n";
    
    // Bill number and date
    $billNoShort = $billData['BILL_NO']; // Keep full bill number
    $billDate = date('d/m/Y', strtotime($billData['BILL_DATE']));
    $text .= "No : " . $billNoShort . str_repeat(" ", 5) . "CASH MEMO" . str_repeat(" ", 5) . "Date: " . $billDate . "\n\n";
    
    // Customer name with permit on same line
    $customerName = 'A.N. PARAB'; // Default
    $permitNo = '';
    if (!empty($permitData) && !empty($permitData['DETAILS'])) {
        $customerName = $permitData['DETAILS'];
        $permitNo = $permitData['P_NO'] ?? '';
    } elseif (!empty($billData['CUST_CODE']) && $billData['CUST_CODE'] != 'RETAIL') {
        $customerName = $billData['CUST_CODE'];
    }
    
    $text .= "Name: " . $customerName;
    if (!empty($permitNo)) {
        $text .= str_repeat(" ", 15) . "Permit No: " . $permitNo;
    }
    $text .= "\n";
    
    // Place and Expiry on next line
    if (!empty($permitData)) {
        $permitPlace = $permitData['PLACE_ISS'] ?? 'SANGLI';
        $permitExpDate = !empty($permitData['P_EXP_DT']) ? date('d/m/Y', strtotime($permitData['P_EXP_DT'])) : '04/11/2026';
        
        $text .= "Place: " . $permitPlace . str_repeat(" ", 15) . "Exp.Dt.: " . $permitExpDate . "\n";
    }
    $text .= "\n";
    
    // Table header
    $text .= str_pad("Particulars", 30) . str_pad("Qty", 10) . str_pad("Size", 15) . str_pad("Amount", 10) . "\n";
    $text .= str_repeat("-", 65) . "\n";
    
    // Items
    foreach ($billItems as $item) {
        $particulars = !empty($item['Print_Name']) ? substr($item['Print_Name'], 0, 30) : substr($item['DETAILS'] ?? '', 0, 30);
        $qty = number_format($item['QTY'], 3);
        $size = substr($item['DETAILS2'] ?? '', 0, 15);
        $amount = number_format($item['AMOUNT'], 2);

        $text .= str_pad($particulars, 30);
        $text .= str_pad($qty, 10);
        $text .= str_pad($size, 15);
        $text .= str_pad($amount, 10) . "\n";
    }
    
    $text .= "\n";
    $text .= str_repeat(" ", 45) . "Total: ₹" . number_format($billData['NET_AMOUNT'], 2) . "\n";
    
    return $text;
}
}

// Function to save complete cash memo data
if (!function_exists('saveCompleteCashMemo')) {
function saveCompleteCashMemo($conn, $billData, $companyData, $billItems, $permitData, $compID, $userID) {
    $billNo = $billData['BILL_NO'];
    $printDate = date('Y-m-d H:i:s');
    
    // Check if already printed today
    $checkQuery = "SELECT id FROM tbl_cash_memo_prints 
                   WHERE bill_no = ? AND comp_id = ? AND DATE(print_date) = CURDATE()";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bind_param("si", $billNo, $compID);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        $checkStmt->close();
        return false; // Already printed today
    }
    $checkStmt->close();
    
    // Prepare data - FIXED: Now includes license_type
    $licenseNumber = !empty($companyData['licenseNumber']) ? $companyData['licenseNumber'] : "FL-II 3";
    $licenseType = !empty($companyData['license_type']) ? $companyData['license_type'] : "";
    $shopName = $companyData['name'];
    $shopAddress = $companyData['address'];
    $billDate = $billData['BILL_DATE'];
    
    $customerName = 'A.N. PARAB';
    $permitNo = null;
    if (!empty($permitData) && !empty($permitData['DETAILS'])) {
        $customerName = $permitData['DETAILS'];
        $permitNo = $permitData['P_NO'] ?? null;
    } elseif (!empty($billData['CUST_CODE']) && $billData['CUST_CODE'] != 'RETAIL') {
        $customerName = $billData['CUST_CODE'];
    }
    
    $permitPlace = $permitData['PLACE_ISS'] ?? null;
    $permitExpDate = !empty($permitData['P_EXP_DT']) ? $permitData['P_EXP_DT'] : null;
    
    $itemsJson = json_encode($billItems);
    $totalAmount = $billData['NET_AMOUNT'];
    
    // Generate the exact cash memo text
    $cashMemoText = generateCashMemoText($companyData, $billData, $billItems, $permitData);
    
    // Insert complete data
    $insertQuery = "INSERT INTO tbl_cash_memo_prints 
                   (bill_no, comp_id, print_date, printed_by, 
                    license_number, license_type, shop_name, shop_address, bill_date, 
                    customer_name, permit_no, permit_place, permit_exp_date,
                    items_json, total_amount, cash_memo_text) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $insertStmt = $conn->prepare($insertQuery);
    $insertStmt->bind_param("sisisssssssssssds", 
        $billNo, $compID, $printDate, $userID,
        $licenseNumber, $licenseType, $shopName, $shopAddress, $billDate,
        $customerName, $permitNo, $permitPlace, $permitExpDate,
        $itemsJson, $totalAmount, $cashMemoText
    );
    
    $result = $insertStmt->execute();
    $insertStmt->close();
    
    return $result;
}
}

// ============================================================================
// ENHANCED CHRONOLOGICAL INTEGRITY CHECK: GLOBAL BLOCKING
// ============================================================================

/**
 * Check if ANY sales exist for ANY item within or after the given date range
 * Returns array with allowed dates (after latest global sale)
 */
function checkGlobalBackdatedSales($conn, $start_date, $end_date, $comp_id) {
    // Query to get all sales in or after the date range for ANY item
    $query = "SELECT DISTINCT sh.BILL_DATE
              FROM tblsaleheader sh
              WHERE sh.BILL_DATE >= ? 
              AND sh.COMP_ID = ?
              ORDER BY sh.BILL_DATE ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $start_date, $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $existing_dates = [];
    while ($row = $result->fetch_assoc()) {
        $existing_dates[] = $row['BILL_DATE'];
    }
    $stmt->close();
    
    // Create date range array
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day'); // Include end date
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($begin, $interval, $end);
    
    $all_dates = [];
    foreach ($date_range as $date) {
        $all_dates[] = $date->format("Y-m-d");
    }
    
    if (!empty($existing_dates)) {
        // Find the latest existing sale date
        $latest_existing = max($existing_dates);
        $latest_existing_date = new DateTime($latest_existing);
        
        // Determine which dates are available (after latest sale date)
        $available_dates = [];
        $unavailable_dates = [];
        
        foreach ($all_dates as $date) {
            $current_date = new DateTime($date);
            if ($current_date > $latest_existing_date) {
                $available_dates[] = $date;
            } else {
                $unavailable_dates[] = $date;
            }
        }
        
        return [
            'restricted' => !empty($unavailable_dates), // Restricted if ANY dates are unavailable
            'latest_existing_sale' => $latest_existing,
            'available_dates' => $available_dates,
            'unavailable_dates' => $unavailable_dates,
            'all_existing_dates' => $existing_dates,
            'message' => !empty($unavailable_dates) ? 
                "Global sales exist on: " . implode(', ', $unavailable_dates) . ". Available dates: " . implode(', ', $available_dates) :
                "No sales restrictions"
        ];
    }
    
    return [
        'restricted' => false,
        'latest_existing_sale' => null,
        'available_dates' => $all_dates, // All dates available if no existing sales
        'unavailable_dates' => [],
        'all_existing_dates' => [],
        'message' => "No global sales restrictions"
    ];
}

// ============================================================================
// DRY DAY VALIDATION
// ============================================================================

/**
 * Check if any dry days fall within the date range
 */
function checkDryDaysInRange($conn, $start_date, $end_date) {
    $dryDaysManager = new DryDaysManager($conn);
    $dry_days = $dryDaysManager->getDryDaysInRange($start_date, $end_date);
    
    return [
        'has_dry_days' => !empty($dry_days),
        'dry_days' => $dry_days,
        'dry_dates' => array_keys($dry_days),
        'message' => !empty($dry_days) ? 
            "Dry days found: " . implode(', ', array_keys($dry_days)) : 
            "No dry days in selected range"
    ];
}

/**
 * Validate both global sales and dry days restrictions
 */
function validateDateRangeRestrictions($conn, $start_date, $end_date, $comp_id) {
    // Check global sales restrictions
    $global_check = checkGlobalBackdatedSales($conn, $start_date, $end_date, $comp_id);
    
    // Check dry days
    $dry_days_check = checkDryDaysInRange($conn, $start_date, $end_date);
    
    // Combine restrictions - a date is unavailable if it has sales OR is a dry day
    $all_unavailable_dates = array_merge(
        $global_check['unavailable_dates'],
        $dry_days_check['dry_dates']
    );
    
    // Remove duplicates
    $all_unavailable_dates = array_unique($all_unavailable_dates);
    sort($all_unavailable_dates);
    
    // Calculate available dates (all dates minus unavailable)
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($begin, $interval, $end);
    
    $all_dates = [];
    foreach ($date_range as $date) {
        $all_dates[] = $date->format("Y-m-d");
    }
    
    $available_dates = array_diff($all_dates, $all_unavailable_dates);
    $available_dates = array_values($available_dates); // Re-index
    
    // Prepare messages
    $messages = [];
    if ($global_check['restricted']) {
        $messages[] = "Existing sales on: " . implode(', ', $global_check['unavailable_dates']);
    }
    if ($dry_days_check['has_dry_days']) {
        $messages[] = "Dry days: " . implode(', ', $dry_days_check['dry_dates']);
    }
    
    return [
        'restricted' => !empty($all_unavailable_dates),
        'global_restricted' => $global_check['restricted'],
        'has_dry_days' => $dry_days_check['has_dry_days'],
        'latest_existing_sale' => $global_check['latest_existing_sale'],
        'available_dates' => $available_dates,
        'unavailable_dates' => $all_unavailable_dates,
        'unavailable_sales_dates' => $global_check['unavailable_dates'],
        'dry_dates' => $dry_days_check['dry_dates'],
        'dry_days_info' => $dry_days_check['dry_days'],
        'message' => !empty($messages) ? implode(' | ', $messages) : "No restrictions",
        'full_message' => !empty($messages) ? 
            "<strong>Date Range Restrictions:</strong><br>" . implode('<br>', $messages) . 
            "<br><strong>Available dates:</strong> " . (empty($available_dates) ? 'None' : implode(', ', $available_dates)) :
            "No date range restrictions"
    ];
}

// Function to check if bills have cash memos
function getBillsWithoutCashMemo($conn, $compID, $date_from, $date_to, $bill_no = '') {
    $billsWithoutMemo = [];
    
    if (!empty($bill_no)) {
        // Check specific bill
        $query = "SELECT sh.BILL_NO 
                  FROM tblsaleheader sh 
                  LEFT JOIN tbl_cash_memo_prints cmp ON sh.BILL_NO = cmp.bill_no AND cmp.comp_id = ? AND DATE(cmp.print_date) = CURDATE()
                  WHERE sh.BILL_NO = ? AND sh.COMP_ID = ? AND cmp.id IS NULL";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("isi", $compID, $bill_no, $compID);
    } else {
        // Check bills in date range
        $query = "SELECT sh.BILL_NO 
                  FROM tblsaleheader sh 
                  LEFT JOIN tbl_cash_memo_prints cmp ON sh.BILL_NO = cmp.bill_no AND cmp.comp_id = ? AND DATE(cmp.print_date) = CURDATE()
                  WHERE sh.BILL_DATE BETWEEN ? AND ? AND sh.COMP_ID = ? AND cmp.id IS NULL";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("issi", $compID, $date_from, $date_to, $compID);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $billsWithoutMemo[] = $row['BILL_NO'];
    }
    $stmt->close();
    
    return $billsWithoutMemo;
}

// Handle generating cash memos when Generate is clicked
if (isset($_GET['generate'])) {
    $print_date = date('Y-m-d H:i:s');
    $bill_numbers_to_store = [];
    
    // Fetch company details for saving - UPDATED to include License_Type
    $companyDataForSave = [
        'name' => "DIAMOND WINE SHOP",
        'address' => "Ishvanbag Sangli Tal Hiraj Dist Sangli",
        'licenseNumber' => "",
        'gst_no' => "",
        'mvat_no' => "",
        'license_type' => ""
    ];
    
    // Updated query to include GST_NO, MVAT_NO, and License_Type
    $companyQuery = "SELECT COMP_NAME, COMP_ADDR, COMP_FLNO, CF_LINE, CS_LINE, GST_NO, MVAT_NO, License_Type FROM tblcompany WHERE CompID = ?";
    $companyStmt = $conn->prepare($companyQuery);
    $companyStmt->bind_param("i", $compID);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    if ($row = $companyResult->fetch_assoc()) {
        $companyDataForSave['name'] = $row['COMP_NAME'];
        $companyDataForSave['address'] = $row['COMP_ADDR'] ?? $companyDataForSave['address'];
        $companyDataForSave['licenseNumber'] = $row['COMP_FLNO'] ?? "";
        $companyDataForSave['gst_no'] = $row['GST_NO'] ?? "";
        $companyDataForSave['mvat_no'] = $row['MVAT_NO'] ?? "";
        $companyDataForSave['license_type'] = $row['License_Type'] ?? ""; // FIXED: Get license type
        $addressLine = $row['CF_LINE'] ?? "";
        if (!empty($row['CS_LINE'])) {
            $addressLine .= (!empty($addressLine) ? " " : "") . $row['CS_LINE'];
        }
        if (!empty($addressLine)) {
            $companyDataForSave['address'] = $addressLine;
        }
    }
    $companyStmt->close();
    
    // Fetch all available permit numbers with customer names
    $permitQuery = "SELECT P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, DETAILS FROM tblpermit WHERE P_NO IS NOT NULL AND P_NO != ''";
    $permitResult = $conn->query($permitQuery);
    $allPermits = [];
    if ($permitResult) {
        while ($row = $permitResult->fetch_assoc()) {
            $allPermits[] = $row;
        }
    }
    
    // Function to get a random permit - moved outside the generate block
    function getRandomPermit($permits) {
        if (empty($permits)) {
            return null;
        }
        return $permits[array_rand($permits)];
    }
    
    // If specific bill number is provided
    if (!empty($bill_no)) {
        // Get bill header data
        $billQuery = "SELECT BILL_NO, BILL_DATE, CUST_CODE, TOTAL_AMOUNT, 
                             DISCOUNT, NET_AMOUNT, LIQ_FLAG
                      FROM tblsaleheader 
                      WHERE BILL_NO = ? AND COMP_ID = ?";
        $billStmt = $conn->prepare($billQuery);
        $billStmt->bind_param("si", $bill_no, $compID);
        $billStmt->execute();
        $billResult = $billStmt->get_result();
        
        if ($billResult->num_rows > 0) {
            $bill_data = $billResult->fetch_assoc();
            $bill_numbers_to_store[] = $bill_no;
            
            // Get bill details with bottle size information (DETAILS2 column)
            $detailsQuery = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, im.DETAILS, im.DETAILS2, im.Print_Name
                             FROM tblsaledetails sd
                             LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                             WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
            $detailsStmt = $conn->prepare($detailsQuery);
            $detailsStmt->bind_param("si", $bill_no, $compID);
            $detailsStmt->execute();
            $detailsResult = $detailsStmt->get_result();
            
            while ($row = $detailsResult->fetch_assoc()) {
                $bill_items[] = $row;
            }
            $detailsStmt->close();
            
            $bill_total = $bill_data['NET_AMOUNT'] ?? 0;
            
            // Assign permit
            if (!empty($allPermits)) {
                $bill_data['permit'] = getRandomPermit($allPermits);
            }
            
            // Save complete cash memo data
            saveCompleteCashMemo($conn, $bill_data, $companyDataForSave, $bill_items, 
                               $bill_data['permit'] ?? null, $compID, $_SESSION['user_id']);
        }
        $billStmt->close();
    } 
    // If no specific bill number, get all bills for the date range
    else {
        // Get all bills for the date range
        $billsQuery = "SELECT BILL_NO, BILL_DATE, CUST_CODE, TOTAL_AMOUNT, 
                              DISCOUNT, NET_AMOUNT, LIQ_FLAG
                       FROM tblsaleheader 
                       WHERE BILL_DATE BETWEEN ? AND ? AND COMP_ID = ?
                       ORDER BY BILL_DATE, BILL_NO";
        $billsStmt = $conn->prepare($billsQuery);
        $billsStmt->bind_param("ssi", $date_from, $date_to, $compID);
        $billsStmt->execute();
        $billsResult = $billsStmt->get_result();
        
        $availablePermits = $allPermits; // Copy for unique assignment
        
        while ($row = $billsResult->fetch_assoc()) {
            $bill_numbers_to_store[] = $row['BILL_NO'];
            
            // Get bill details for each bill with bottle size (DETAILS2 column)
            $detailsQuery = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, im.DETAILS, im.DETAILS2, im.Print_Name
                             FROM tblsaledetails sd
                             LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                             WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
            $detailsStmt = $conn->prepare($detailsQuery);
            $detailsStmt->bind_param("si", $row['BILL_NO'], $compID);
            $detailsStmt->execute();
            $detailsResult = $detailsStmt->get_result();
            
            $items = [];
            while ($itemRow = $detailsResult->fetch_assoc()) {
                $items[] = $itemRow;
            }
            $detailsStmt->close();
            
            // Assign unique permit if available
            $permit = null;
            if (!empty($availablePermits)) {
                $permit = array_shift($availablePermits);
            } elseif (!empty($allPermits)) {
                $permit = getRandomPermit($allPermits);
            }
            
            $all_bills[] = [
                'header' => $row,
                'items' => $items,
                'permit' => $permit
            ];
            
            // Save complete cash memo data for this bill
            saveCompleteCashMemo($conn, $row, $companyDataForSave, $items, $permit, $compID, $_SESSION['user_id']);
        }
        $billsStmt->close();
    }
    
    // Set success message
    $_SESSION['success_message'] = count($bill_numbers_to_store) . " cash memo(s) generated and saved successfully!";
    
    // Show print section
    $_SESSION['show_print_section'] = true;
    $_SESSION['generated_bills_count'] = count($bill_numbers_to_store);
    $_SESSION['all_bills_data'] = $all_bills; // Store bills in session for print
}

// Handle showing print section without generating
if (isset($_GET['show_print'])) {
    // If specific bill number is provided
    if (!empty($bill_no)) {
        // Get bill header data
        $billQuery = "SELECT BILL_NO, BILL_DATE, CUST_CODE, TOTAL_AMOUNT, 
                             DISCOUNT, NET_AMOUNT, LIQ_FLAG
                      FROM tblsaleheader 
                      WHERE BILL_NO = ? AND COMP_ID = ?";
        $billStmt = $conn->prepare($billQuery);
        $billStmt->bind_param("si", $bill_no, $compID);
        $billStmt->execute();
        $billResult = $billStmt->get_result();
        
        if ($billResult->num_rows > 0) {
            $bill_data = $billResult->fetch_assoc();
            
            // Get bill details with bottle size information (DETAILS2 column)
            $detailsQuery = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, im.DETAILS, im.DETAILS2, im.Print_Name
                             FROM tblsaledetails sd
                             LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                             WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
            $detailsStmt = $conn->prepare($detailsQuery);
            $detailsStmt->bind_param("si", $bill_no, $compID);
            $detailsStmt->execute();
            $detailsResult = $detailsStmt->get_result();
            
            while ($row = $detailsResult->fetch_assoc()) {
                $bill_items[] = $row;
            }
            $detailsStmt->close();
            
            // Get permit data for this bill
            $permitQuery = "SELECT P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, DETAILS FROM tblpermit WHERE P_NO IS NOT NULL AND P_NO != ''";
            $permitResult = $conn->query($permitQuery);
            $allPermits = [];
            if ($permitResult) {
                while ($row = $permitResult->fetch_assoc()) {
                    $allPermits[] = $row;
                }
            }
            
            if (!empty($allPermits)) {
                $bill_data['permit'] = getRandomPermit($allPermits);
            }
        }
        $billStmt->close();
    } 
    // If no specific bill number, get all bills for the date range
    else {
        // Get all bills for the date range
        $billsQuery = "SELECT BILL_NO, BILL_DATE, CUST_CODE, TOTAL_AMOUNT, 
                              DISCOUNT, NET_AMOUNT, LIQ_FLAG
                       FROM tblsaleheader 
                       WHERE BILL_DATE BETWEEN ? AND ? AND COMP_ID = ?
                       ORDER BY BILL_DATE, BILL_NO";
        $billsStmt = $conn->prepare($billsQuery);
        $billsStmt->bind_param("ssi", $date_from, $date_to, $compID);
        $billsStmt->execute();
        $billsResult = $billsStmt->get_result();
        
        // Get all permits
        $permitQuery = "SELECT P_NO, P_ISSDT, P_EXP_DT, PLACE_ISS, DETAILS FROM tblpermit WHERE P_NO IS NOT NULL AND P_NO != ''";
        $permitResult = $conn->query($permitQuery);
        $allPermits = [];
        if ($permitResult) {
            while ($row = $permitResult->fetch_assoc()) {
                $allPermits[] = $row;
            }
        }
        
        $availablePermits = $allPermits; // Copy for unique assignment
        
        while ($row = $billsResult->fetch_assoc()) {
            // Get bill details for each bill with bottle size (DETAILS2 column)
            $detailsQuery = "SELECT sd.ITEM_CODE, sd.QTY, sd.RATE, sd.AMOUNT, im.DETAILS, im.DETAILS2, im.Print_Name
                             FROM tblsaledetails sd
                             LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                             WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
            $detailsStmt = $conn->prepare($detailsQuery);
            $detailsStmt->bind_param("si", $row['BILL_NO'], $compID);
            $detailsStmt->execute();
            $detailsResult = $detailsStmt->get_result();
            
            $items = [];
            while ($itemRow = $detailsResult->fetch_assoc()) {
                $items[] = $itemRow;
            }
            $detailsStmt->close();
            
            // Assign unique permit if available
            $permit = null;
            if (!empty($availablePermits)) {
                $permit = array_shift($availablePermits);
            } elseif (!empty($allPermits)) {
                $permit = getRandomPermit($allPermits);
            }
            
            $all_bills[] = [
                'header' => $row,
                'items' => $items,
                'permit' => $permit
            ];
        }
        $billsStmt->close();
    }
    
    // Show print section
    $_SESSION['show_print_section'] = true;
    $_SESSION['all_bills_data'] = $all_bills; // Store bills in session for print
}

// Check if we should show the print section
$showPrintSection = isset($_SESSION['show_print_section']) && $_SESSION['show_print_section'];

// If we have stored bills in session, use them for printing
if (isset($_SESSION['all_bills_data']) && !empty($_SESSION['all_bills_data'])) {
    $all_bills = $_SESSION['all_bills_data'];
}

// Fetch company details for display - UPDATED to include GST_NO, MVAT_NO, and License_Type
$companyName = "DIAMOND WINE SHOP";
$companyAddress = "Ishvanbag Sangli Tal Hiraj Dist Sangli";
$licenseNumber = "";
$companyAddress2 = "";
$companyGST = '';
$companyMVAT = '';
$licenseDetails = [];
$licenseType = ''; // Add license type

// Updated query to include GST_NO and MVAT_NO and License_Type
$companyQuery = "SELECT COMP_NAME, COMP_ADDR, COMP_FLNO, CF_LINE, CS_LINE, GST_NO, MVAT_NO, License_Type FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
if ($companyStmt) {
    $companyStmt->bind_param("i", $compID);
    $companyStmt->execute();
    $companyResult = $companyStmt->get_result();
    if ($row = $companyResult->fetch_assoc()) {
        $companyName = $row['COMP_NAME'];
        $companyAddress = $row['COMP_ADDR'] ?? $companyAddress;
        $licenseNumber = $row['COMP_FLNO'] ?? "";
        $companyGST = $row['GST_NO'] ?? '';
        $companyMVAT = $row['MVAT_NO'] ?? '';
        $licenseType = $row['License_Type'] ?? ''; // Get license type
        $companyAddress2 = $row['CF_LINE'] ?? "";
        if (!empty($row['CS_LINE'])) {
            $companyAddress2 .= (!empty($companyAddress2) ? " " : "") . $row['CS_LINE'];
        }
        
        // Collect license details (FLII, FLIII, FLBRII, CLIII, etc.)
        if (!empty($licenseNumber)) {
            $licenseDetails[] = $licenseNumber;
        }
    }
    $companyStmt->close();
}

// Also fetch additional license descriptions from tblclass - FIXED with backticks around DESC
$licenseQuery = "SELECT DISTINCT SGROUP, `DESC` FROM tblclass WHERE SGROUP IN ('FLII', 'FLIII', 'FLBRII', 'CLIII') LIMIT 4";
$licenseResult = $conn->query($licenseQuery);
if ($licenseResult && $licenseResult->num_rows > 0) {
    while ($licRow = $licenseResult->fetch_assoc()) {
        if (!empty($licRow['DESC'])) {
            $licenseDetails[] = $licRow['DESC'];
        }
    }
}

// Get total bills and amount for the date range
if (!isset($_GET['generate']) || empty($bill_no)) {
    $summaryQuery = "SELECT COUNT(*) as total_bills, SUM(NET_AMOUNT) as total_amount
                     FROM tblsaleheader 
                     WHERE BILL_DATE BETWEEN ? AND ? AND COMP_ID = ?";
    $summaryStmt = $conn->prepare($summaryQuery);
    $summaryStmt->bind_param("ssi", $date_from, $date_to, $compID);
    $summaryStmt->execute();
    $summaryResult = $summaryStmt->get_result();

    if ($row = $summaryResult->fetch_assoc()) {
        $totalBills = $row['total_bills'];
        $totalAmount = $row['total_amount'] ?? 0.00;
    }
    $summaryStmt->close();
}

// Check which bills don't have cash memos
$billsWithoutCashMemo = getBillsWithoutCashMemo($conn, $compID, $date_from, $date_to, $bill_no);
$showGenerateButton = !empty($billsWithoutCashMemo);

// Clear print section when new filters are applied without generate/show_print
if (isset($_GET['date_from']) || isset($_GET['date_to']) || isset($_GET['bill_no']) || isset($_GET['mode'])) {
    if (!isset($_GET['generate']) && !isset($_GET['show_print'])) {
        $_SESSION['show_print_section'] = false;
        $showPrintSection = false;
        unset($_SESSION['all_bills_data']);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cash Memo Printing - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <!-- Include shortcuts functionality -->
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    /* SCREEN STYLES */
    body {
        font-family: 'Courier New', monospace;
        background-color: #f8f9fa;
        margin: 0;
        padding: 0;
        overflow-x: hidden;
    }
    
    .cash-memo-container {
        width: 280px;
        margin: 8px;
        padding: 6px;
        border: 1px solid #000;
        background: white;
        font-size: 11px;
        line-height: 1.1;
        display: inline-block;
        vertical-align: top;
        box-sizing: border-box;
    }
    
    .cash-memo-header {
        text-align: center;
        margin-bottom: 4px;
        padding-bottom: 2px;
        border-bottom: 1px solid #000;
    }
    
    .license-info {
        text-align: center;
        font-weight: bold;
        margin-bottom: 2px;
        font-size: 10px;
    }
    
    .license-type {
        text-align: center;
        font-weight: bold;
        margin-bottom: 2px;
        font-size: 10px;
        text-transform: uppercase;
    }
    
    .shop-name {
        font-weight: bold;
        text-transform: uppercase;
        margin-bottom: 1px;
        font-size: 12px;
    }
    
    .shop-address {
        font-size: 9px;
        margin-bottom: 4px;
        line-height: 1;
    }
    
    .memo-info {
        display: flex;
        justify-content: space-between;
        margin-bottom: 5px;
        padding-bottom: 2px;
        border-bottom: 1px solid #000;
        font-size: 10px;
    }
    
    .customer-info {
        margin-bottom: 5px;
        font-size: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .customer-info span {
        white-space: nowrap;
    }
    
    .permit-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 5px;
        font-size: 9px;
        border-bottom: 1px solid #000;
        padding-bottom: 2px;
    }
    
    .items-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 5px;
        font-size: 10px;
    }
    
    .items-table td {
        padding: 1px 0;
        vertical-align: top;
        border-bottom: 1px dotted #ccc;
    }
    
    .total-section {
        border-top: 2px solid #000;
        padding-top: 2px;
        text-align: right;
        font-weight: bold;
        margin-bottom: 2px;
        font-size: 11px;
        padding-right: 4px;
    }
    
    .memos-container {
        display: block;
        text-align: center;
        margin: 0 auto;
        width: 100%;
    }
    
    .qty-col {
        width: 15%;
        text-align: center;
    }
    
    .particulars-col {
        width: 40%;
        text-align: left;
        padding-left: 2px;
    }
    
    .size-col {
        width: 25%;
        text-align: center;
    }
    
    .amount-col {
        width: 20%;
        text-align: right;
        padding-right: 4px;
    }
    
    .table-header {
        display: flex;
        justify-content: space-between;
        text-align: center;
        margin-bottom: 2px;
        font-weight: bold;
        font-size: 10px;
        line-height: 1;
        border-bottom: 1px solid #000;
        padding-bottom: 1px;
    }
    
    .header-particulars {
        width: 40%;
        text-align: left;
        padding-left: 2px;
    }
    
    .header-qty {
        width: 15%;
    }
    
    .header-size {
        width: 25%;
        text-align: center;
    }
    
    .header-amount {
        width: 20%;
        text-align: right;
        padding-right: 4px;
    }
    
    .no-print {
        margin-bottom: 20px;
    }

    /* Show report on screen when needed */
    .print-content.screen-display {
        display: block !important;
        margin-top: 20px;
        background: white;
        padding: 15px;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    @media screen {
        .print-content.screen-display .memos-container {
            max-height: 70vh;
            overflow-y: auto;
        }
    }

    /* PRINT STYLES - SAME SIZE AS SCREEN */
    @media print {
        @page {
            size: A4 landscape;
            margin: 10mm;
        }
        
        body {
            margin: 0;
            padding: 0;
            background: white;
            width: 100%;
            font-family: 'Courier New', monospace !important;
        }
        
        .no-print {
            display: none !important;
        }
        
        .print-content {
            display: block !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        
        .memos-container {
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            display: block !important;
            text-align: center !important;
            page-break-inside: avoid !important;
        }
        
        .cash-memo-container {
            width: 280px !important;
            height: auto !important;
            margin: 8px !important;
            padding: 6px !important;
            border: 1px solid #000 !important;
            background: white !important;
            display: inline-block !important;
            vertical-align: top !important;
            page-break-inside: avoid !important;
            break-inside: avoid !important;
            font-size: 11px !important;
            line-height: 1.1 !important;
            box-sizing: border-box !important;
            float: none !important;
        }
        
        .cash-memo-container:nth-child(4n+1) {
            page-break-before: always;
        }
        
        .license-info, .license-type {
            font-size: 10px !important;
            margin-bottom: 2px !important;
        }
        
        .shop-name {
            font-size: 12px !important;
            margin-bottom: 1px !important;
        }
        
        .shop-address {
            font-size: 9px !important;
            margin-bottom: 4px !important;
            line-height: 1 !important;
        }
        
        .memo-info {
            font-size: 10px !important;
            margin-bottom: 5px !important;
        }
        
        .customer-info {
            font-size: 10px !important;
            margin-bottom: 5px !important;
        }
        
        .permit-row {
            font-size: 9px !important;
            margin-bottom: 5px !important;
        }
        
        .items-table {
            font-size: 10px !important;
            margin-bottom: 5px !important;
        }
        
        .items-table td {
            padding: 1px 0 !important;
            line-height: 1 !important;
        }
        
        .total-section {
            font-size: 11px !important;
            margin-bottom: 2px !important;
            padding-right: 4px !important;
        }
        
        @page {
            margin-header: 0;
            margin-footer: 0;
        }
    }

    @media screen and (max-width: 767px) {
        .cash-memo-container {
            width: 100%;
            margin: 10px 0;
        }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Cash Memo Printing</h3>

      <!-- Display success message if set -->
      <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
          <?= $_SESSION['success_message'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
      <?php endif; ?>

      <!-- Cash Memo Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Cash Memo Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters" id="cashMemoForm">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-select">
                  <option value="foreign" <?= $mode === 'foreign' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="country" <?= $mode === 'country' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">From Date:</label>
                <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">To Date:</label>
                <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>" required>
              </div>
              <div class="col-md-3">
                <label class="form-label">Bill No (Optional):</label>
                <input type="text" name="bill_no" class="form-control" value="<?= $bill_no ?>" placeholder="Enter specific bill no">
              </div>
            </div>
            
            <div class="row">
              <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center">
                  <div>
                    <?php if ($totalBills > 0): ?>
                      <span class="badge bg-primary">Total Bills: <?= $totalBills ?></span>
                      <span class="badge bg-success ms-2">Total Amount: ₹<?= number_format($totalAmount, 2) ?></span>
                      <?php if ($showGenerateButton): ?>
                        <span class="badge bg-warning ms-2">Bills without cash memo: <?= count($billsWithoutCashMemo) ?></span>
                      <?php else: ?>
                        <span class="badge bg-info ms-2">All bills have cash memos</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                  <div>
                    <?php if ($showGenerateButton): ?>
                      <button type="submit" name="generate" value="1" class="btn btn-success me-2">
                        <i class="fas fa-plus-circle me-1"></i> Generate Cash Memos
                      </button>
                    <?php endif; ?>
                    <button type="submit" name="show_print" value="1" class="btn btn-primary">
                      <i class="fas fa-print me-1"></i> Show for Printing
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Print Section -->
      <?php if ($showPrintSection && (!empty($all_bills) || !empty($bill_data))): ?>
        <div id="reportContent" class="<?= $showPrintSection ? 'print-content screen-display' : 'print-content' ?>">
          <div class="print-section no-print">
            <div class="d-flex justify-content-between align-items-center mb-3">
              <h4>Cash Memos Ready for Printing</h4>
              <div>
                <button class="btn btn-success me-2" onclick="generateReport()">
                  <i class="fas fa-print me-1"></i> Print All
                </button>
                <button class="btn btn-secondary" onclick="window.location.href='cash_memo.php'">
                  <i class="fas fa-times me-1"></i> Close
                </button>
              </div>
            </div>
            
            <p class="text-muted mb-3">
              Showing <?= count($all_bills) ?: 1 ?> cash memo(s). Layout: 4 cash memos per page in landscape orientation.
            </p>
          </div>

          <!-- Cash Memos Container -->
          <div class="memos-container">
            <?php
            // FIXED: Function to display a single cash memo - with license type before number
            function displayCashMemo($billData, $companyName, $companyAddress, $licenseNumber, $billItems, $permitData = null, $companyGST = '', $companyMVAT = '', $licenseDetails = [], $licenseType = '') {
                $billNo = $billData['BILL_NO']; // Keep full bill number
                $billDate = date('d/m/Y', strtotime($billData['BILL_DATE']));
                $totalAmount = $billData['NET_AMOUNT'];
                
                // Customer name logic - from permit or bill
                $customerName = 'A.N. PARAB'; // Default
                $permitNo = '';
                if (!empty($permitData) && !empty($permitData['DETAILS'])) {
                    $customerName = $permitData['DETAILS'];
                    $permitNo = $permitData['P_NO'] ?? '';
                } elseif (!empty($billData['CUST_CODE']) && $billData['CUST_CODE'] != 'RETAIL') {
                    $customerName = $billData['CUST_CODE'];
                }
                
                // Permit information for place and expiry
                $permitPlace = $permitData['PLACE_ISS'] ?? 'SANGLI';
                $permitExpDate = !empty($permitData['P_EXP_DT']) ? date('d/m/Y', strtotime($permitData['P_EXP_DT'])) : '04/11/2026';
                
                // FIXED: License details display with heading - NOW INCLUDES LICENSE TYPE BEFORE NUMBER
                $licenseDisplay = !empty($licenseNumber) ? $licenseNumber : "FL-II 3";
                if (!empty($licenseDetails)) {
                    $licenseDisplay = implode(', ', $licenseDetails);
                }
                ?>
                <div class="cash-memo-container">
                  <div class="cash-memo-header">
                    <!-- FIXED: License No heading with type and number combined -->
                    <div class="license-info">
                        <strong>License No: 
                        <?php 
                        if (!empty($licenseType)) {
                            echo htmlspecialchars($licenseType) . ' ';
                        }
                        echo htmlspecialchars($licenseDisplay); 
                        ?>
                        </strong>
                    </div>
                    
                    <!-- GST and MVAT info -->
                    <?php if (!empty($companyGST) || !empty($companyMVAT)): ?>
                    <div class="license-info" style="font-size: 9px;">
                        <?php if (!empty($companyGST)): ?>
                            GST: <?= htmlspecialchars($companyGST) ?>
                        <?php endif; ?>
                        <?php if (!empty($companyMVAT)): ?>
                            <?php if (!empty($companyGST)): ?> | <?php endif; ?>
                            MVAT: <?= htmlspecialchars($companyMVAT) ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Shop name and address -->
                    <div class="shop-name"><?= htmlspecialchars($companyName) ?></div>
                    <div class="shop-address"><?= htmlspecialchars($companyAddress) ?></div>
                  </div>
                  
                  <!-- Bill number with full value -->
                  <div class="memo-info">
                    <span>No: <?= $billNo ?></span>
                    <span>CASH MEMO</span>
                    <span>Date: <?= $billDate ?></span>
                  </div>
                  
                  <!-- Customer Name with Permit No on same line -->
                  <div class="customer-info">
                    <span><strong>Name:</strong> <?= htmlspecialchars($customerName) ?></span>
                    <?php if (!empty($permitNo)): ?>
                        <span><strong>Permit No:</strong> <?= htmlspecialchars($permitNo) ?></span>
                    <?php endif; ?>
                  </div>
                  
                  <!-- Place and Expiry Date line -->
                  <?php if (!empty($permitData)): ?>
                  <div class="permit-row">
                    <span>Place: <?= htmlspecialchars($permitPlace) ?></span>
                    <span>Exp.Dt.: <?= $permitExpDate ?></span>
                  </div>
                  <?php endif; ?>
                  
                  <!-- Table header -->
                  <div class="table-header">
                    <div class="header-particulars">Particulars</div>
                    <div class="header-qty">Qty</div>
                    <div class="header-size">Size</div>
                    <div class="header-amount">Amount</div>
                  </div>
                  
                  <!-- Items table -->
                  <table class="items-table">
                    <?php foreach ($billItems as $item): ?>
                    <tr>
                      <td class="particulars-col"><?= htmlspecialchars(substr(!empty($item['Print_Name']) ? $item['Print_Name'] : ($item['DETAILS'] ?? ''), 0, 25)) ?></td>
                      <td class="qty-col"><?= number_format($item['QTY'], 3) ?></td>
                      <td class="size-col"><?= htmlspecialchars(substr($item['DETAILS2'] ?? '', 0, 12)) ?></td>
                      <td class="amount-col"><?= number_format($item['AMOUNT'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </table>
                  
                  <!-- Total amount -->
                  <div class="total-section">
                    Total: ₹<?= number_format($totalAmount, 2) ?>
                  </div>
                </div>
                <?php
            }
            
            // Display single bill or multiple bills
            if (!empty($bill_data)) {
                displayCashMemo($bill_data, $companyName, $companyAddress, $licenseNumber, $bill_items, $bill_data['permit'] ?? null, $companyGST ?? '', $companyMVAT ?? '', $licenseDetails, $licenseType);
            } elseif (!empty($all_bills)) {
                foreach ($all_bills as $bill) {
                    displayCashMemo($bill['header'], $companyName, $companyAddress, $licenseNumber, $bill['items'], $bill['permit'], $companyGST ?? '', $companyMVAT ?? '', $licenseDetails, $licenseType);
                }
            }
            ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if (!$showPrintSection): ?>
        <div class="card no-print">
          <div class="card-body text-center text-muted">
            <i class="fas fa-receipt fa-3x mb-3"></i>
            <h5>No Cash Memos to Display</h5>
            <p>Use the filters above to find bills and generate cash memos.</p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Function for print button
function generateReport() {
    window.print();
}

// Show report immediately if filters were submitted
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('generate') || urlParams.has('show_print')) {
        const reportContent = document.getElementById('reportContent');
        if (reportContent) {
            reportContent.style.display = 'block';
            reportContent.classList.add('screen-display');
        }
    }
});

// Auto-submit form when dates change to update summary
document.addEventListener('DOMContentLoaded', function() {
    const dateFrom = document.querySelector('input[name="date_from"]');
    const dateTo = document.querySelector('input[name="date_to"]');
    const billNo = document.querySelector('input[name="bill_no"]');
    
    function updateSummary() {
        // Remove generate/show_print parameters to avoid auto-generating
        const form = document.getElementById('cashMemoForm');
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.delete('generate');
        urlParams.delete('show_print');
        
        // Update form action to maintain other parameters
        form.action = 'cash_memo.php?' + urlParams.toString();
        
        // Submit form to update summary
        form.submit();
    }
    
    dateFrom.addEventListener('change', updateSummary);
    dateTo.addEventListener('change', updateSummary);
    billNo.addEventListener('blur', updateSummary);
    
    // Print handling
    window.addEventListener('afterprint', function() {
        console.log('Printing completed');
    });
});
</script>
</body>
</html>
<?php
// Clear session data after use
if (isset($_SESSION['show_print_section'])) {
    unset($_SESSION['show_print_section']);
}
if (isset($_SESSION['all_bills_data'])) {
    unset($_SESSION['all_bills_data']);
}
?>