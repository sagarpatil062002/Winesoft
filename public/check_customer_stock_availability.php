<?php
session_start();
include_once "../config/db.php";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit;
}

$sale_date = $input['sale_date'];
$mode = $input['mode'];
$comp_id = $input['comp_id'];
$quantities = $input['quantities'];
$daily_stock_table = $input['daily_stock_table'];
$sale_month = $input['sale_month'];

try {
    $validation_errors = [];

    // Check stock for each item with quantity > 0 for the single date
    foreach ($quantities as $item_code => $qty) {
        if ($qty > 0) {
            $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
            $closing_column = "DAY_{$day_num}_CLOSING";
            $month_year = date('Y-m', strtotime($sale_date));

            // Check if record exists
            $check_query = "SELECT $closing_column
                            FROM $daily_stock_table
                            WHERE STK_MONTH = ? AND ITEM_CODE = ?";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bind_param("ss", $month_year, $item_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows == 0) {
                $validation_errors[] = "No stock record found for item $item_code on $sale_date";
            } else {
                $stock_data = $check_result->fetch_assoc();
                $current_stock = $stock_data[$closing_column] ?? 0;

                if ($current_stock < $qty) {
                    $validation_errors[] = "Insufficient stock for item $item_code on $sale_date. Available: $current_stock, Required: $qty";
                }
            }
            $check_stmt->close();

            // Stop checking if we have too many errors
            if (count($validation_errors) >= 5) {
                break;
            }
        }
    }

    if (!empty($validation_errors)) {
        echo json_encode([
            'success' => false,
            'message' => 'Stock validation failed:\n' . implode('\n', array_slice($validation_errors, 0, 3))
        ]);
    } else {
        echo json_encode(['success' => true, 'message' => 'Stock validation passed']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error checking stock: ' . $e->getMessage()]);
}
?>