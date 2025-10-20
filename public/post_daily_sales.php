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
include_once "stock_functions.php";

// Include volume limit utilities
include_once "volume_limit_utils.php";
// Include cash memo functions
require_once 'cash_memo_functions.php';

$comp_id = $_SESSION['CompID'];
$fin_year_id = $_SESSION['FIN_YEAR_ID'];
$user_id = $_SESSION['user_id'];

// Get pending sales dates - CORRECTED: using start_date instead of sale_date
$pending_dates_query = "SELECT DISTINCT start_date as sale_date FROM tbl_pending_sales 
                        WHERE comp_id = ? AND status = 'pending' 
                        ORDER BY start_date";
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
        
        // Initialize counters for cash memos
        $cash_memos_generated = 0;
        $cash_memo_errors = [];
        $total_bills_generated = 0;
        
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Process each selected date
            foreach ($selected_dates as $sale_date) {
                // Get all pending sales for this date
                $pending_sales_query = "SELECT ps.*, im.RPRICE as rate, im.LIQ_FLAG as mode, im.DETAILS, im.DETAILS2
                                       FROM tbl_pending_sales ps
                                       JOIN tblitemmaster im ON ps.item_code = im.CODE
                                       WHERE ps.comp_id = ? AND ps.start_date = ? AND ps.status = 'pending'";
                $pending_sales_stmt = $conn->prepare($pending_sales_query);
                $pending_sales_stmt->bind_param("is", $comp_id, $sale_date);
                $pending_sales_stmt->execute();
                $pending_sales_result = $pending_sales_stmt->get_result();
                $pending_sales = $pending_sales_result->fetch_all(MYSQLI_ASSOC);
                $pending_sales_stmt->close();
                
                // Prepare items data for bill generation with volume limits
                $items_data = [];
                foreach ($pending_sales as $sale) {
                    $items_data[$sale['item_code']] = [
                        'name' => $sale['DETAILS'],
                        'rate' => $sale['rate'],
                        'total_qty' => $sale['quantity'],
                        'details2' => $sale['DETAILS2']
                    ];
                }
                
                // Generate bills with volume limits
                $daily_sales_data = [];
                foreach ($items_data as $item_code => $item_data) {
                    $daily_sales_data[$item_code] = distributeSales($item_data['total_qty'], 1); // 1 day only
                }
                
                // Get mode from first item (assuming all items have same mode)
                $mode = !empty($pending_sales) ? $pending_sales[0]['mode'] : 'F';
                
                // Generate bills with the same logic as in the main file
                $bills = generateBillsWithLimits($conn, $items_data, [$sale_date], $daily_sales_data, $mode, $comp_id, $user_id, $fin_year_id);
                $total_bills_generated += count($bills);
                
                // Get next bill number for this batch
                $next_bill_number = getNextBillNumberForGenerate($conn, $comp_id);
                
                // Process each bill with proper sequential bill numbers
                foreach ($bills as $bill) {
                    // Use sequential bill number instead of TEMP
                    $sequential_bill_no = $next_bill_number;
                    
                    // Increment for next bill
                    $next_bill_number = incrementBillNumber($next_bill_number);
                    
                    // Insert sale header
                    $header_query = "INSERT INTO tblsaleheader (BILL_NO, BILL_DATE, TOTAL_AMOUNT, DISCOUNT, NET_AMOUNT, LIQ_FLAG, COMP_ID, CREATED_BY) 
                                     VALUES (?, ?, ?, 0, ?, ?, ?, ?)";
                    $header_stmt = $conn->prepare($header_query);
                    $header_stmt->bind_param("ssddssi", $sequential_bill_no, $bill['bill_date'], $bill['total_amount'], 
                                            $bill['total_amount'], $bill['mode'], $bill['comp_id'], $bill['user_id']);
                    $header_stmt->execute();
                    $header_stmt->close();
                    
                    // Insert sale details for each item in the bill
                    foreach ($bill['items'] as $item) {
                        $detail_query = "INSERT INTO tblsaledetails (BILL_NO, ITEM_CODE, QTY, RATE, AMOUNT, LIQ_FLAG, COMP_ID) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $detail_stmt = $conn->prepare($detail_query);
                        $detail_stmt->bind_param("ssddssi", $sequential_bill_no, $item['code'], $item['qty'], 
                                                $item['rate'], $item['amount'], $bill['mode'], $bill['comp_id']);
                        $detail_stmt->execute();
                        $detail_stmt->close();
                        
                        // Update stock using cascading logic
                        updateItemStock($conn, $item['code'], $item['qty'], $comp_id, $fin_year_id);

                        // Update daily stock using cascading logic
                        updateCascadingDailyStock($conn, $item['code'], $bill['bill_date'], $comp_id, 'sale', $item['qty']);
                    }
                    
                    // AUTO-GENERATE CASH MEMO FOR THIS BILL (NEW ADDITION)
                    if (autoGenerateCashMemoForBill($conn, $sequential_bill_no, $comp_id, $user_id)) {
                        $cash_memos_generated++;
                        logCashMemoGeneration($sequential_bill_no, true);
                    } else {
                        $cash_memo_errors[] = $sequential_bill_no;
                        logCashMemoGeneration($sequential_bill_no, false, "Cash memo generation failed in post daily sales");
                    }
                }
                
                // Mark all pending sales for this date as processed
                $update_query = "UPDATE tbl_pending_sales SET status = 'processed', processed_at = NOW() 
                                WHERE comp_id = ? AND start_date = ? AND status = 'pending'";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bind_param("is", $comp_id, $sale_date);
                $update_stmt->execute();
                $update_stmt->close();
            }
            
            // Commit transaction
            $conn->commit();
            
            // Build success message with cash memo info
            $success_message = "Successfully processed " . count($selected_dates) . " date(s) and generated " . $total_bills_generated . " bills!";
            
            // Add cash memo information
            if ($cash_memos_generated > 0) {
                $success_message .= " | Cash Memos Generated: " . $cash_memos_generated;
            }
            
            if (!empty($cash_memo_errors)) {
                $success_message .= " | Failed to generate cash memos for bills: " . implode(", ", array_slice($cash_memo_errors, 0, 5));
                if (count($cash_memo_errors) > 5) {
                    $success_message .= " and " . (count($cash_memo_errors) - 5) . " more";
                }
            }
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error_message = "Error processing sales: " . $e->getMessage();
        }
    } else {
        $error_message = "Please select at least one date to process.";
    }
}

// Function to update item stock
function updateItemStock($conn, $item_code, $quantity, $comp_id, $fin_year_id) {
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

// Function to get next bill number with proper zero-padding for generate
function getNextBillNumberForGenerate($conn, $comp_id) {
    // Get the highest existing bill number numerically
    $sql = "SELECT BILL_NO FROM tblsaleheader 
            WHERE COMP_ID = ? 
            ORDER BY CAST(SUBSTRING(BILL_NO, 3) AS UNSIGNED) DESC 
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $comp_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $nextNumber = 1; // Default starting number
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lastBillNo = $row['BILL_NO'];
        
        // Extract numeric part and increment
        if (preg_match('/BL(\d+)/', $lastBillNo, $matches)) {
            $nextNumber = intval($matches[1]) + 1;
        }
    }
    
    $stmt->close();
    return 'BL' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
}

// Function to increment bill number
function incrementBillNumber($bill_no) {
    if (preg_match('/BL(\d+)/', $bill_no, $matches)) {
        $nextNumber = intval($matches[1]) + 1;
        return 'BL' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
    return $bill_no; // fallback
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Post Daily Sales - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
                Cash memos will be automatically generated for all bills.
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
                                     WHERE ps.comp_id = ? AND ps.start_date = ? AND ps.status = 'pending'";
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