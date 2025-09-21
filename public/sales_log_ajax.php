<?php
// sales_log_ajax.php
session_start();
require_once '../config/db.php'; // Adjust path as needed

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Please login to view sales log.</div>';
    exit;
}

// Define the number of days to show in the log
$daysToShow = 30;
$endDate = date('Y-m-d'); // Today
$startDate = date('Y-m-d', strtotime("-$daysToShow days"));

// Fetch data from tblsaleheader for the date range
$saleHeaderQuery = "SELECT BILL_DATE FROM tblsaleheader 
                    WHERE BILL_DATE BETWEEN '$startDate' AND '$endDate'";
$saleHeaderResult = mysqli_query($conn, $saleHeaderQuery);
$postedDates = [];
while ($row = mysqli_fetch_assoc($saleHeaderResult)) {
    $postedDates[$row['BILL_DATE']] = true;
}

// Fetch data from tbl_pending_sales for the date range
$pendingSalesQuery = "SELECT start_date FROM tbl_pending_sales 
                      WHERE start_date BETWEEN '$startDate' AND '$endDate'";
$pendingSalesResult = mysqli_query($conn, $pendingSalesQuery);
$pendingDates = [];
while ($row = mysqli_fetch_assoc($pendingSalesResult)) {
    $pendingDates[$row['start_date']] = true;
}

// Generate an array of dates in the range
$dates = [];
$current = strtotime($startDate);
$end = strtotime($endDate);
while ($current <= $end) {
    $date = date('Y-m-d', $current);
    $dates[] = $date;
    $current = strtotime('+1 day', $current);
}
$dates = array_reverse($dates); // Most recent first

// Prepare the log data
$logData = [];
foreach ($dates as $date) {
    $salesYes = isset($postedDates[$date]) || isset($pendingDates[$date]);
    $postedYes = isset($postedDates[$date]);
    
    $logData[] = [
        'date' => $date,
        'sales' => $salesYes ? 'Yes' : 'No',
        'posted' => $postedYes ? 'Yes' : 'No'
    ];
}
?>

<div class="table-responsive">
    <table class="table table-bordered table-sm">
        <thead class="table-light">
            <tr>
                <th>Date</th>
                <th>Sales</th>
                <th>Posted</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($logData as $log): ?>
            <tr>
                <td><?php echo date('d/m/Y', strtotime($log['date'])); ?></td>
                <td class="<?php echo $log['sales'] === 'Yes' ? 'text-success fw-bold' : 'text-muted'; ?>">
                    <?php echo $log['sales']; ?>
                </td>
                <td class="<?php echo $log['posted'] === 'Yes' ? 'text-success fw-bold' : 'text-muted'; ?>">
                    <?php echo $log['posted']; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="mt-3 small text-muted">
    <i class="fas fa-info-circle"></i>
    Showing last <?php echo $daysToShow; ?> days. Generated on <?php echo date('d/m/Y H:i:s'); ?>
</div>