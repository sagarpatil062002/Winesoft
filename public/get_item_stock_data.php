<?php
session_start();
require_once "../config/db.php";

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$item_code = $data['item_code'] ?? '';
$start_date = $data['start_date'] ?? '';
$end_date = $data['end_date'] ?? '';

if (empty($item_code) || empty($start_date) || empty($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

$comp_id = $_SESSION['CompID'];

try {
    // Create date array
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end = $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $date_range = new DatePeriod($begin, $interval, $end);
    
    // Group dates by month
    $dates_by_month = [];
    foreach ($date_range as $date) {
        $date_str = $date->format('Y-m-d');
        $month_year = $date->format('Y-m');
        if (!isset($dates_by_month[$month_year])) {
            $dates_by_month[$month_year] = [];
        }
        $dates_by_month[$month_year][] = $date_str;
    }
    
    $stock_data = [];
    $current_month = date('Y-m');
    
    // For each month, fetch stock data
    foreach ($dates_by_month as $month_year => $month_dates) {
        // Determine which table to use for this month
        if ($month_year === $current_month) {
            $table = "tbldailystock_" . $comp_id;
        } else {
            $month_short = date('m', strtotime($month_year . '-01'));
            $year_short = date('y', strtotime($month_year . '-01'));
            $table = "tbldailystock_" . $comp_id . "_" . $month_short . "_" . $year_short;
        }
        
        // Check if table exists
        $check_table = $conn->query("SHOW TABLES LIKE '$table'");
        if ($check_table->num_rows === 0) {
            // Table doesn't exist - set all dates in this month to 0
            foreach ($month_dates as $date) {
                $stock_data[$date] = 0;
            }
            continue;
        }
        
        // Query stock for this item and month
        $query = "SELECT * FROM $table WHERE ITEM_CODE = ? AND STK_MONTH = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("ss", $item_code, $month_year);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stock_row = null;
        if ($row = $result->fetch_assoc()) {
            $stock_row = $row;
        }
        $stmt->close();
        
        // Extract closing stock for each date in this month
        foreach ($month_dates as $date) {
            $day_num = sprintf('%02d', date('d', strtotime($date)));
            $closing_column = "DAY_{$day_num}_CLOSING";
            
            if ($stock_row && isset($stock_row[$closing_column])) {
                $stock_data[$date] = (float)$stock_row[$closing_column];
            } else {
                $stock_data[$date] = 0;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'stock_data' => $stock_data
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
