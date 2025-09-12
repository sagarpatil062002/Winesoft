<?php
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

include_once "../config/db.php";

$comp_id = $_SESSION['CompID'];
$fin_year_id = $_SESSION['FIN_YEAR_ID'];
$user_id = $_SESSION['user_id'];

// Get pending sales dates
$pending_dates_query = "SELECT DISTINCT sale_date FROM tbl_pending_sales 
                        WHERE comp_id = ? AND status = 'pending' 
                        ORDER BY sale_date";
$pending_stmt = $conn->prepare($pending_dates_query);
$pending_stmt->bind_param("i", $comp_id);
$pending_stmt->execute();
$pending_result = $pending_stmt->get_result();
$pending_dates = [];
while ($row = $pending_result->fetch_assoc()) {
    $pending_dates[] = $row['sale_date'];
}
$pending_stmt->close();

// Process selected dates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_dates'])) {
    if (isset($_POST['selected_dates']) && !empty($_POST['selected_dates'])) {
        $selected_dates = $_POST['selected_dates'];
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Get next bill number
            $next_bill = getNextBillNumber($conn);
            
            // Process each selected date
            foreach ($selected_dates as $sale_date) {
                // Get all pending sales for this date
                $pending_sales_query = "SELECT ps.*, im.RPRICE as rate, im.LIQ_FLAG as mode 
                                       FROM tbl_pending_sales ps
                                       JOIN tblitemmaster im ON ps.item_code = im.CODE
                                       WHERE ps.comp_id = ? AND ps.sale_date = ? AND ps.status = 'pending'";
                $pending_sales_stmt = $conn->prepare($pending_sales_query);
                $pending_sales_stmt->bind_param("is", $comp_id, $sale_date);
                $pending_sales_stmt->execute();
                $pending_sales_result = $pending_sales_stmt->get_result();
                $pending_sales = $pending_sales_result->fetch_all(MYSQLI_ASSOC);
                $pending_sales_stmt->close();
                
                // Group by customer/restriction rules (simplified example)
                $customer_sales = groupSalesByRestriction($pending_sales);
                
                // Process each customer group
                foreach ($customer_sales as $customer_group) {
                    // Check bulk liter restrictions
                    if (checkBulkRestrictions($customer_group)) {
                        // Create bill for this customer group
                        $bill_no = "BL" . $next_bill;
                        $next_bill++;
                        
                        $total_amount = 0;
                        foreach ($customer_group as $sale) {
                            $amount = $sale['quantity'] * $sale['rate'];
                            $total_amount += $amount;
                        }
                        
                        // Insert sale header
                        $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                         VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                        $header_stmt = $conn->prepare($header_query);
                        $header_stmt->bind_param("ssddssi", $bill_no, $sale_date, $total_amount, $total_amount, $sale['mode'], $comp_id, $user_id);
                        $header_stmt->execute();
                        $header_stmt->close();
                        
                        // Insert sale details and update stock
                        foreach ($customer_group as $sale) {
                            $amount = $sale['quantity'] * $sale['rate'];
                            
                            // Insert sale details
                            $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                             VALUES (?, ?, ?, ?, ?, ?, ?)";
                            $detail_stmt = $conn->prepare($detail_query);
                            $detail_stmt->bind_param("ssddssi", $bill_no, $sale['item_code'], $sale['quantity'], $sale['rate'], $amount, $sale['mode'], $comp_id);
                            $detail_stmt->execute();
                            $detail_stmt->close();
                            
                            // Update stock
                            updateStock($conn, $sale['item_code'], $sale['quantity'], $sale['mode'], $comp_id, $fin_year_id);
                            
                            // Update daily stock
                            updateDailyStock($conn, "tbldailystock_" . $comp_id, $sale['item_code'], $sale_date, $sale['quantity'], $comp_id);
                        }
                    } else {
                        // Handle restriction violation (split into multiple bills or show error)
                        // This would be a more complex implementation based on your specific rules
                        throw new Exception("Bulk restriction violation for date: " . $sale_date);
                    }
                }
                
                // Mark all pending sales for this date as processed
                $update_query = "UPDATE tbl_pending_sales SET status = 'processed', processed_at = NOW() 
                                WHERE comp_id = ? AND sale_date = ? AND status = 'pending'";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("is", $comp_id, $sale_date);
                $update_stmt->execute();
                $update_stmt->close();
            }
            
            // Commit transaction
            $conn->commit();
            
            $success_message = "Successfully processed " . count($selected_dates) . " date(s) and generated bills!";
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error processing sales: " . $e->getMessage();
        }
    } else {
        $error_message = "Please select at least one date to process.";
    }
}

// Function to get next bill number
function getNextBillNumber($conn) {
    $query = "SELECT MAX(CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED)) as max_bill FROM tblsaleheader";
    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    return ($row['max_bill'] ? $row['max_bill'] + 1 : 1);
}

// Function to group sales by restriction rules (simplified example)
function groupSalesByRestriction($sales) {
    // This is a simplified example - you would implement your specific bulk restriction logic here
    // For example, group by customer type, item category, or other criteria
    
    $groups = [];
    $current_group = [];
    $current_liters = 0;
    
    foreach ($sales as $sale) {
        // Calculate liters (assuming size is stored somewhere)
        $liters = calculateLiters($sale['item_code'], $sale['quantity']);
        
        // Check if adding this sale would exceed restrictions
        if ($current_liters + $liters > 10) { // Example: 10 liter limit per customer
            $groups[] = $current_group;
            $current_group = [];
            $current_liters = 0;
        }
        
        $current_group[] = $sale;
        $current_liters += $liters;
    }
    
    if (!empty($current_group)) {
        $groups[] = $current_group;
    }
    
    return $groups;
}

// Function to check bulk restrictions (simplified example)
function checkBulkRestrictions($sales_group) {
    $total_liters = 0;
    
    foreach ($sales_group as $sale) {
        $liters = calculateLiters($sale['item_code'], $sale['quantity']);
        $total_liters += $liters;
    }
    
    // Example: Maximum 15 liters per customer per day
    return $total_liters <= 15;
}

// Function to calculate liters from item code and quantity
function calculateLiters($item_code, $quantity) {
    // This would need to be implemented based on your item size data
    // For now, return a placeholder value
    return $quantity * 0.75; // Assuming standard bottle size
}

// Function to update stock
function updateStock($conn, $item_code, $quantity, $mode, $comp_id, $fin_year_id) {
    $opening_stock_column = "Opening_Stock" . $comp_id;
    $current_stock_column = "Current_Stock" . $comp_id;
    
    // Check if stock record exists
    $check_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $stock_exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($stock_exists) {
        // Update existing stock
        $stock_query = "UPDATE tblitem_stock SET $current_stock_column = $current_stock_column - ? WHERE ITEM_CODE = ?";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("ds", $quantity, $item_code);
        $stock_stmt->execute();
        $stock_stmt->close();
    } else {
        // Insert new stock record
        $insert_stock_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_stock_column, $current_stock_column) 
                               VALUES (?, ?, ?, ?)";
        $insert_stock_stmt = $conn->prepare($insert_stock_query);
        $current_stock = -$quantity;
        $insert_stock_stmt->bind_param("ssdd", $item_code, $fin_year_id, $current_stock, $current_stock);
        $insert_stock_stmt->execute();
        $insert_stock_stmt->close();
    }
}

// Function to update daily stock
function updateDailyStock($conn, $daily_stock_table, $item_code, $sale_date, $qty, $comp_id) {
    // Extract day number from date (e.g., 2025-09-03 -> day 03)
    $day_num = sprintf('%02d', date('d', strtotime($sale_date)));
    $sales_column = "DAY_{$day_num}_SALES";
    $closing_column = "DAY_{$day_num}_CLOSING";
    $opening_column = "DAY_{$day_num}_OPEN";
    $purchase_column = "DAY_{$day_num}_PURCHASE";
    
    $month_year = date('Y-m', strtotime($sale_date));
    
    // Check if record exists for this month and item
    $check_query = "SELECT COUNT(*) as count FROM $daily_stock_table 
                    WHERE STK_MONTH = ? AND ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ss", $month_year, $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Get current values to calculate closing properly
        $select_query = "SELECT $opening_column, $purchase_column, $sales_column 
                         FROM $daily_stock_table 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $select_stmt = $conn->prepare($select_query);
        $select_stmt->bind_param("ss", $month_year, $item_code);
        $select_stmt->execute();
        $select_result = $select_stmt->get_result();
        $current_values = $select_result->fetch_assoc();
        $select_stmt->close();
        
        $opening = $current_values[$opening_column] ?? 0;
        $purchase = $current_values[$purchase_column] ?? 0;
        $current_sales = $current_values[$sales_column] ?? 0;
        
        // Calculate new sales and closing
        $new_sales = $current_sales + $qty;
        $new_closing = $opening + $purchase - $new_sales;
        
        // Update existing record with correct closing calculation
        $update_query = "UPDATE $daily_stock_table 
                         SET $sales_column = ?, 
                             $closing_column = ?,
                             LAST_UPDATED = CURRENT_TIMESTAMP 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ddss", $new_sales, $new_closing, $month_year, $item_code);
        $update_stmt->execute();
        $update_stmt->close();
        
        // Update next day's opening stock if it exists
        $next_day = intval($day_num) + 1;
        if ($next_day <= 31) {
            $next_day_num = sprintf('%02d', $next_day);
            $next_opening_column = "DAY_{$next_day_num}_OPEN";
            
            // Check if next day exists in the table
            $check_next_day_query = "SHOW COLUMNS FROM $daily_stock_table LIKE '$next_opening_column'";
            $next_day_result = $conn->query($check_next_day_query);
            
            if ($next_day_result->num_rows > 0) {
                // Update next day's opening to match current day's closing
                $update_next_query = "UPDATE $daily_stock_table 
                                     SET $next_opening_column = ?,
                                         LAST_UPDATED = CURRENT_TIMESTAMP 
                                     WHERE STK_MONTH = ? AND ITEM_CODE = ?";
                $update_next_stmt = $conn->prepare($update_next_query);
                $update_next_stmt->bind_param("dss", $new_closing, $month_year, $item_code);
                $update_next_stmt->execute();
                $update_next_stmt->close();
            }
        }
    } else {
        // For new records, opening and purchase are typically 0 unless specified otherwise
        $closing = 0 - $qty; // Since opening and purchase are 0
        
        // Create new record
        $insert_query = "INSERT INTO $daily_stock_table 
                         (STK_MONTH, ITEM_CODE, LIQ_FLAG, $opening_column, $purchase_column, $sales_column, $closing_column) 
                         VALUES (?, ?, 'F', 0, 0, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ssdd", $month_year, $item_code, $qty, $closing);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Post Daily Sales - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome@6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Post Daily Sales</h3>

      <!-- Success/Error Messages -->
      <?php if (isset($success_message)): ?>
      <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
      <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <?php if (empty($pending_dates)): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle"></i> No pending sales to process.
        </div>
      <?php else: ?>
        <form method="POST">
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Select Dates to Process</h5>
            </div>
            <div class="card-body">
              <div class="row">
                <?php foreach ($pending_dates as $date): ?>
                  <div class="col-md-3 mb-2">
                    <div class="form-check">
                      <input class="form-check-input" type="checkbox" name="selected_dates[]" 
                             value="<?= htmlspecialchars($date) ?>" id="date_<?= str_replace('-', '_', $date) ?>">
                      <label class="form-check-label" for="date_<?= str_replace('-', '_', $date) ?>">
                        <?= date('d-M-Y', strtotime($date)) ?>
                      </label>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="card-footer">
              <button type="submit" name="process_dates" class="btn btn-success">
                <i class="fas fa-check-circle"></i> Process Selected Dates
              </button>
              <div class="form-text">
                <i class="fas fa-info-circle"></i> 
                Sales will be processed with bulk liter restrictions applied. 
                Each date may generate multiple bills based on restriction rules.
              </div>
            </div>
          </div>
        </form>

        <div class="card">
          <div class="card-header">
            <h5 class="mb-0">Pending Sales Summary</h5>
          </div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-striped">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Number of Items</th>
                    <th>Total Quantity</th>
                    <th>Estimated Amount</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  foreach ($pending_dates as $date): 
                    // Get summary for each date
                    $summary_query = "SELECT COUNT(*) as item_count, SUM(quantity) as total_qty, 
                                     SUM(quantity * im.RPRICE) as total_amount 
                                     FROM tbl_pending_sales ps
                                     JOIN tblitemmaster im ON ps.item_code = im.CODE
                                     WHERE ps.comp_id = ? AND ps.sale_date = ? AND ps.status = 'pending'";
                    $summary_stmt = $conn->prepare($summary_query);
                    $summary_stmt->bind_param("is", $comp_id, $date);
                    $summary_stmt->execute();
                    $summary_result = $summary_stmt->get_result();
                    $summary = $summary_result->fetch_assoc();
                    $summary_stmt->close();
                  ?>
                    <tr>
                      <td><?= date('d-M-Y', strtotime($date)) ?></td>
                      <td><?= $summary['item_count'] ?></td>
                      <td><?= number_format($summary['total_qty'], 3) ?></td>
                      <td>₹<?= number_format($summary['total_amount'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>