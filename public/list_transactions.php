<?php
// list_transactions.php
require_once 'config/database.php';
require_once 'license_functions.php';
require_once 'drydays_functions.php';

// Default date range (last 7 days)
$default_start = date('Y-m-d', strtotime('-7 days'));
$default_end = date('Y-m-d');

$start_date = $_GET['start_date'] ?? $default_start;
$end_date = $_GET['end_date'] ?? $default_end;

// Build query with automatic dry day exclusion
$base_query = "SELECT t.*, c.DESC as class_name 
               FROM transactions t 
               JOIN tblclass c ON t.class_id = c.SRNO 
               WHERE t.transaction_date BETWEEN '$start_date' AND '$end_date'";

$filtered_query = filterQueryByClassAccessAndDryDays($conn, $base_query, 't.class_id', 't.transaction_date');

// Add ordering
$filtered_query .= " ORDER BY t.transaction_date DESC";

$result = $conn->query($filtered_query);

// Check if any dry days were excluded
$dryDaysManager = new DryDaysManager($conn);
$excluded_dry_days = $dryDaysManager->getDryDaysInRange($start_date, $end_date);
?>

<div class="card">
    <div class="card-header">
        <h5>Transactions</h5>
        
        <?php if (!empty($excluded_dry_days)): ?>
        <div class="alert alert-info mt-2">
            <i class="fas fa-info-circle"></i>
            Dry days are automatically excluded from results. 
            Excluded: 
            <?php 
            $dry_days_display = [];
            foreach ($excluded_dry_days as $date => $desc) {
                $dry_days_display[] = date('d-m-Y', strtotime($date)) . " ($desc)";
            }
            echo implode(', ', $dry_days_display);
            ?>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="card-body">
        <!-- Date filter form -->
        <form method="GET" class="row g-3 mb-4">
            <div class="col-md-4">
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label>End Date</label>
                <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label>&nbsp;</label>
                <button type="submit" class="btn btn-primary form-control">Filter</button>
            </div>
        </form>
        
        <!-- Transactions table -->
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Class</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo date('d-m-Y', strtotime($row['transaction_date'])); ?></td>
                    <td><?php echo $row['class_name']; ?></td>
                    <td><?php echo $row['amount']; ?></td>
                    <td><span class="badge bg-success">Completed</span></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>