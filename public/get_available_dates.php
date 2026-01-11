<?php
session_start();
require_once '../config/db.php';

// Logging
function logMessage($message) {
    $logFile = '../logs/available_dates_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message" . PHP_EOL, FILE_APPEND);
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Get parameters
$item_code = $_POST['item_code'] ?? '';
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';
$comp_id = $_SESSION['CompID'];

if (empty($item_code) || empty($start_date) || empty($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

// Function to get available dates (after latest existing sale)
function getAvailableDatesForItem($conn, $item_code, $start_date, $end_date, $comp_id) {
    // Query to get all sales for this item in or after the date range
    $query = "SELECT sh.BILL_DATE
              FROM tblsaleheader sh
              JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO 
                AND sh.COMP_ID = sd.COMP_ID
              WHERE sd.ITEM_CODE = ? 
              AND sh.BILL_DATE >= ? 
              AND sh.COMP_ID = ?
              ORDER BY sh.BILL_DATE ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssi", $item_code, $start_date, $comp_id);
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
        
        foreach ($all_dates as $date) {
            $current_date = new DateTime($date);
            if ($current_date > $latest_existing_date) {
                $available_dates[] = $date;
            }
        }
        
        logMessage("Item $item_code: Latest existing sale: $latest_existing");
        logMessage("Available dates: " . implode(', ', $available_dates));
        
        return $available_dates;
    }
    
    // If no existing sales, all dates are available
    return $all_dates;
}

try {
    $available_dates = getAvailableDatesForItem($conn, $item_code, $start_date, $end_date, $comp_id);
    
    echo json_encode([
        'success' => true,
        'available_dates' => $available_dates,
        'item_code' => $item_code
    ]);
    
} catch (Exception $e) {
    logMessage("Error getting available dates for $item_code: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error getting available dates'
    ]);
}
?>