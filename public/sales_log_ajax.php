<?php
// sales_log_ajax.php
session_start();
require_once '../config/db.php'; // Adjust path as needed
require_once 'components/financial_year.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">Please login to view sales log.</div>';
    exit;
}

// Get sort parameter - default to 'latest'
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'latest';
// Validate sort value
if (!in_array($sort, ['asc', 'desc', 'latest'])) {
    $sort = 'latest';
}

// Get financial year dates
$finYearModule = FinancialYearModule::getInstance();
$finYearStart = $finYearModule->getStartDate();
$finYearEnd = $finYearModule->getEndDate();

// Use financial year dates, or fallback to last 30 days if not set
if ($finYearStart && $finYearEnd) {
    $startDate = $finYearStart;
    $endDate = $finYearEnd;
} else {
    $startDate = date('Y-m-d', strtotime('-30 days'));
    $endDate = date('Y-m-d');
}

// Fetch data from tblsaleheader for the date range
$saleHeaderQuery = "SELECT BILL_DATE, CREATED_DATE FROM tblsaleheader 
                    WHERE BILL_DATE BETWEEN '$startDate' AND '$endDate'";
$saleHeaderResult = mysqli_query($conn, $saleHeaderQuery);
$postedDates = [];
$lastUpdatedDate = null;
$lastUpdatedTime = '0000-00-00 00:00:00';

while ($row = mysqli_fetch_assoc($saleHeaderResult)) {
    $postedDates[$row['BILL_DATE']] = true;
    // Track the latest updated date based on CREATED_DATE
    if (!empty($row['CREATED_DATE']) && $row['CREATED_DATE'] > $lastUpdatedTime) {
        $lastUpdatedTime = $row['CREATED_DATE'];
        $lastUpdatedDate = $row['BILL_DATE'];
    }
}

// Fetch data from tbl_pending_sales for the date range
$pendingSalesQuery = "SELECT start_date, created_at FROM tbl_pending_sales 
                      WHERE start_date BETWEEN '$startDate' AND '$endDate'";
$pendingSalesResult = mysqli_query($conn, $pendingSalesQuery);
$pendingDates = [];

while ($row = mysqli_fetch_assoc($pendingSalesResult)) {
    $pendingDates[$row['start_date']] = true;
    // Check if this is more recent than our last updated
    if (!empty($row['created_at']) && $row['created_at'] > $lastUpdatedTime) {
        $lastUpdatedTime = $row['created_at'];
        $lastUpdatedDate = $row['start_date'];
    }
}

// Generate an array of dates in the range (chronological order: April 1 -> March 31)
$dates = [];
$current = strtotime($startDate);
$end = strtotime($endDate);
while ($current <= $end) {
    $date = date('Y-m-d', $current);
    $dates[] = $date;
    $current = strtotime('+1 day', $current);
}

// Sort dates based on the selected sort option
$sortedDates = [];
$datesWithActivity = [];
$datesWithoutActivity = [];

foreach ($dates as $date) {
    $hasActivity = isset($postedDates[$date]) || isset($pendingDates[$date]);
    if ($hasActivity) {
        $datesWithActivity[] = $date;
    } else {
        $datesWithoutActivity[] = $date;
    }
}

if ($sort === 'latest') {
    // Sort dates: Most recently updated first, then chronological
    // Sort dates with activity: most recent (last updated) first, then chronological within that group
    usort($datesWithActivity, function($a, $b) use ($lastUpdatedDate) {
        if ($a === $lastUpdatedDate) return -1;
        if ($b === $lastUpdatedDate) return 1;
        return strcmp($a, $b);
    });
    // Combine: dates with activity first (most recent at top), then dates without activity
    $sortedDates = array_merge($datesWithActivity, $datesWithoutActivity);
} elseif ($sort === 'desc') {
    // Descending from 31 March (reverse chronological order)
    rsort($datesWithActivity);
    rsort($datesWithoutActivity);
    $sortedDates = array_merge($datesWithActivity, $datesWithoutActivity);
} else {
    // Ascending from 1 April (chronological order)
    sort($datesWithActivity);
    sort($datesWithoutActivity);
    $sortedDates = array_merge($datesWithActivity, $datesWithoutActivity);
}

// Prepare the log data
$logData = [];
foreach ($sortedDates as $date) {
    $salesYes = isset($postedDates[$date]) || isset($pendingDates[$date]);
    $postedYes = isset($postedDates[$date]);
    $isLatest = ($date === $lastUpdatedDate);
    
    $logData[] = [
        'date' => $date,
        'sales' => $salesYes ? 'Yes' : 'No',
        'posted' => $postedYes ? 'Yes' : 'No',
        'isLatest' => $isLatest
    ];
}

$finYearDisplay = $finYearModule->getDisplayText();

// Get sort label for display
$sortLabels = [
    'asc' => 'Ascending (1 Apr)',
    'desc' => 'Descending (31 Mar)',
    'latest' => 'Latest Activity'
];
$currentSortLabel = $sortLabels[$sort] ?? 'Latest Activity';
?>

<div class="mb-3">
    <label for="sortOrder" class="form-label">Sort Order:</label>
    <select class="form-select form-select-sm" id="sortOrder" onchange="loadSalesLog(this.value)" style="width: auto;">
        <option value="latest" <?php echo $sort === 'latest' ? 'selected' : ''; ?>>Latest Activity (Default)</option>
        <option value="asc" <?php echo $sort === 'asc' ? 'selected' : ''; ?>>Ascending (From 1 April)</option>
        <option value="desc" <?php echo $sort === 'desc' ? 'selected' : ''; ?>>Descending (From 31 March)</option>
    </select>
</div>

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
            <tr<?php echo $log['isLatest'] ? ' class="table-warning"' : ''; ?>>
                <td<?php echo $log['isLatest'] ? ' class="fw-bold"' : ''; ?>><?php echo date('d/m/Y', strtotime($log['date'])); ?><?php echo $log['isLatest'] ? ' <span class="badge bg-primary">Latest</span>' : ''; ?></td>
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
    <i class="fas fa-info-circle"></i> Showing Financial Year: <?php echo $finYearDisplay; ?> (<?php echo $startDate; ?> to <?php echo $endDate; ?>). Latest update: <?php echo $lastUpdatedDate ? date('d/m/Y', strtotime($lastUpdatedDate)) : 'N/A'; ?>. Generated on <?php echo date('d/m/Y H:i:s'); ?>
</div>