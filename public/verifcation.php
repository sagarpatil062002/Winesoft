<?php
// verify_direct_comparison.php - Fixed version
session_start();
include_once "../config/db.php";

$compID = $_SESSION['CompID'] ?? 5;

// Set verification date (you can change this or make it dynamic)
$verification_date = isset($_GET['date']) ? $_GET['date'] : '2021-04-09';
$month = date('Y-m', strtotime($verification_date));
$day_num = date('d', strtotime($verification_date));
$day_padded = sprintf('%02d', $day_num);

// Get table name
$current_month = date('Y-m');
if ($month == $current_month) {
    $tableName = "tbldailystock_" . $compID;
} else {
    $month_num = date('m', strtotime($verification_date));
    $year_short = date('y', strtotime($verification_date));
    $tableName = "tbldailystock_" . $compID . "_" . $month_num . "_" . $year_short;
}

// Define categories in display order (FIXED - moved to global scope)
$display_categories = [
    'IMFL', 'IMPORTED', 'MML',
    'INDIAN WINE', 'IMPORTED WINE', 'WINE MML',
    'FERMENTED BEER', 'MILD BEER'
];

// Define size order for sorting (largest to smallest)
$size_order = [
    '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML',
    '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1L',
    '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
];

// Function to get size label from ML
function getSizeLabel($ml) {
    if ($ml >= 1000) {
        $liters = $ml / 1000;
        return ($liters == (int)$liters) ? (int)$liters . 'L' : rtrim(rtrim(number_format($liters, 1), '0'), '.') . 'L';
    }
    return $ml . ' ML';
}

// Function to get display type from category and class
function getDisplayType($category_name, $class_name) {
    $category_name = strtoupper($category_name ?? '');
    $class_name = strtoupper($class_name ?? '');
    
    if ($category_name == 'SPIRIT') {
        if (strpos($class_name, 'IMPORTED') !== false || strpos($class_name, 'IMP') !== false) return 'IMPORTED';
        if (strpos($class_name, 'MML') !== false) return 'MML';
        return 'IMFL';
    }
    if ($category_name == 'WINE') {
        if (strpos($class_name, 'IMPORTED') !== false || strpos($class_name, 'IMP') !== false) return 'IMPORTED WINE';
        if (strpos($class_name, 'MML') !== false) return 'WINE MML';
        return 'INDIAN WINE';
    }
    if ($category_name == 'FERMENTED BEER') return 'FERMENTED BEER';
    if ($category_name == 'MILD BEER') return 'MILD BEER';
    if ($category_name == 'COUNTRY LIQUOR') return 'COUNTRY LIQUOR';
    return $category_name;
}

// Function to get size volume in ML for sorting
function getSizeVolume($size_label) {
    if (preg_match('/(\d+(?:\.\d+)?)\s*(ML|L)/i', $size_label, $matches)) {
        $value = (float)$matches[1];
        $unit = strtoupper($matches[2]);
        if ($unit == 'L') {
            return $value * 1000;
        }
        return $value;
    }
    return 0;
}

// ============================================
// QUERY 1: Get AGGREGATED DATA from DATABASE (what the report SHOULD show)
// ============================================
$db_aggregated = [];

// First check if the table exists
$tableCheck = $conn->query("SHOW TABLES LIKE '$tableName'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    
    // Check if the day column exists
    $colCheck = $conn->query("SHOW COLUMNS FROM $tableName LIKE 'DAY_{$day_padded}_OPEN'");
    if ($colCheck && $colCheck->num_rows > 0) {
        
        $dbQuery = "
            SELECT 
                im.CODE,
                im.DETAILS,
                cn.CLASS_NAME,
                cat.CATEGORY_NAME,
                sz.ML_VOLUME,
                COALESCE(s.DAY_{$day_padded}_OPEN, 0) as opening,
                COALESCE(s.DAY_{$day_padded}_PURCHASE, 0) as purchase,
                COALESCE(s.DAY_{$day_padded}_SALES, 0) as sales,
                COALESCE(s.DAY_{$day_padded}_CLOSING, 0) as closing
            FROM $tableName s
            INNER JOIN tblitemmaster im ON s.ITEM_CODE = im.CODE
            LEFT JOIN tblclass_new cn ON im.CLASS_CODE_NEW = cn.CLASS_CODE
            LEFT JOIN tblcategory cat ON cn.CATEGORY_CODE = cat.CATEGORY_CODE
            LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
            WHERE s.STK_MONTH = '$month'
        ";
        
        $dbResult = $conn->query($dbQuery);
        
        if ($dbResult && $dbResult->num_rows > 0) {
            while ($row = $dbResult->fetch_assoc()) {
                $opening = (int)$row['opening'];
                $purchase = (int)$row['purchase'];
                $sales = (int)$row['sales'];
                $closing = (int)$row['closing'];
                
                // Skip if all zeros
                if ($opening == 0 && $purchase == 0 && $sales == 0 && $closing == 0) {
                    continue;
                }
                
                $display_type = getDisplayType($row['CATEGORY_NAME'], $row['CLASS_NAME']);
                $size_label = getSizeLabel($row['ML_VOLUME'] ?? 0);
                
                $key = $display_type . '|' . $size_label;
                
                if (!isset($db_aggregated[$key])) {
                    $db_aggregated[$key] = [
                        'category' => $display_type,
                        'size' => $size_label,
                        'opening' => 0,
                        'purchase' => 0,
                        'sales' => 0,
                        'closing' => 0,
                        'item_count' => 0
                    ];
                }
                
                $db_aggregated[$key]['opening'] += $opening;
                $db_aggregated[$key]['purchase'] += $purchase;
                $db_aggregated[$key]['sales'] += $sales;
                $db_aggregated[$key]['closing'] += $closing;
                $db_aggregated[$key]['item_count']++;
            }
        }
    }
}

// ============================================
// QUERY 2: Get INDIVIDUAL ITEM DATA for detailed verification
// ============================================
$individual_items = [];

if ($tableCheck && $tableCheck->num_rows > 0 && $colCheck && $colCheck->num_rows > 0) {
    $itemQuery = "
        SELECT 
            im.CODE,
            im.DETAILS,
            cn.CLASS_NAME,
            cat.CATEGORY_NAME,
            sz.ML_VOLUME,
            COALESCE(s.DAY_{$day_padded}_OPEN, 0) as opening,
            COALESCE(s.DAY_{$day_padded}_PURCHASE, 0) as purchase,
            COALESCE(s.DAY_{$day_padded}_SALES, 0) as sales,
            COALESCE(s.DAY_{$day_padded}_CLOSING, 0) as closing
        FROM $tableName s
        INNER JOIN tblitemmaster im ON s.ITEM_CODE = im.CODE
        LEFT JOIN tblclass_new cn ON im.CLASS_CODE_NEW = cn.CLASS_CODE
        LEFT JOIN tblcategory cat ON cn.CATEGORY_CODE = cat.CATEGORY_CODE
        LEFT JOIN tblsize sz ON im.SIZE_CODE = sz.SIZE_CODE
        WHERE s.STK_MONTH = '$month'
        ORDER BY cn.CLASS_NAME, sz.ML_VOLUME DESC
    ";
    
    $itemResult = $conn->query($itemQuery);
    
    if ($itemResult && $itemResult->num_rows > 0) {
        while ($row = $itemResult->fetch_assoc()) {
            $opening = (int)$row['opening'];
            $purchase = (int)$row['purchase'];
            $sales = (int)$row['sales'];
            $closing = (int)$row['closing'];
            
            // Skip if all zeros
            if ($opening == 0 && $purchase == 0 && $sales == 0 && $closing == 0) {
                continue;
            }
            
            $display_type = getDisplayType($row['CATEGORY_NAME'], $row['CLASS_NAME']);
            $size_label = getSizeLabel($row['ML_VOLUME'] ?? 0);
            $calculated_closing = $opening + $purchase - $sales;
            $is_accurate = ($calculated_closing == $closing);
            
            $individual_items[] = [
                'code' => $row['CODE'],
                'details' => $row['DETAILS'],
                'category' => $display_type,
                'size' => $size_label,
                'opening' => $opening,
                'purchase' => $purchase,
                'sales' => $sales,
                'closing' => $closing,
                'calculated_closing' => $calculated_closing,
                'is_accurate' => $is_accurate
            ];
        }
    }
}

// ============================================
// CALCULATE SUMMARY STATISTICS
// ============================================
$total_items = count($individual_items);
$accurate_items = 0;
$inaccurate_items = 0;

foreach ($individual_items as $item) {
    if ($item['is_accurate']) {
        $accurate_items++;
    } else {
        $inaccurate_items++;
    }
}

$accuracy_percentage = $total_items > 0 ? ($accurate_items / $total_items) * 100 : 100;

// Sort aggregated data by category order and size
$sorted_aggregated = $db_aggregated;
uasort($sorted_aggregated, function($a, $b) use ($display_categories) {
    // Sort by category order
    $cat_order_a = array_search($a['category'], $display_categories);
    $cat_order_b = array_search($b['category'], $display_categories);
    if ($cat_order_a === false) $cat_order_a = 999;
    if ($cat_order_b === false) $cat_order_b = 999;
    if ($cat_order_a != $cat_order_b) return $cat_order_a - $cat_order_b;
    
    // Sort by size volume (largest to smallest)
    $vol_a = getSizeVolume($a['size']);
    $vol_b = getSizeVolume($b['size']);
    return $vol_b - $vol_a;
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Direct Comparison - Database vs Excise Register</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-size: 13px; }
        .comparison-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
        .table-clubbed th { background-color: #343a40; color: white; }
        .table-clubbed td { vertical-align: middle; }
        .table-details th { background-color: #2c3e50; color: white; }
        .match-row { background-color: #d4edda !important; }
        .mismatch-row { background-color: #f8d7da !important; }
        .badge-match { background-color: #28a745; }
        .badge-mismatch { background-color: #dc3545; }
        .summary-card { border-radius: 10px; padding: 15px; margin-bottom: 15px; }
        .summary-card h2 { font-size: 2rem; margin: 0; }
        .value-match { color: #28a745; font-weight: bold; }
        .value-mismatch { color: #dc3545; font-weight: bold; text-decoration: underline; }
        .section-title { font-size: 1.2rem; font-weight: bold; padding: 10px; margin-top: 20px; margin-bottom: 15px; border-left: 4px solid; }
        .section-title.excise { border-left-color: #007bff; background-color: #e3f2fd; }
        .section-title.database { border-left-color: #28a745; background-color: #d4edda; }
        .no-data { text-align: center; padding: 40px; color: #6c757d; }
    </style>
</head>
<body>
<div class="container-fluid mt-3">
    <!-- Header -->
    <div class="comparison-header">
        <div class="row">
            <div class="col-8">
                <h2><i class="fas fa-balance-scale me-2"></i>Direct Comparison: Database vs Excise Register</h2>
                <p class="mb-0">Verification Date: <strong><?= date('d-m-Y', strtotime($verification_date)) ?></strong> | Table: <code><?= $tableName ?></code></p>
                <p class="mb-0 mt-2">
                    <span class="badge bg-light text-dark me-3"><i class="fas fa-chart-bar"></i> CLUBBED VIEW: Same categories & sizes are aggregated</span>
                    <span class="badge bg-light text-dark"><i class="fas fa-check-circle text-success"></i> Green = Accurate | <i class="fas fa-times-circle text-danger"></i> Red = Mismatch</span>
                </p>
            </div>
            <div class="col-4 text-end">
                <form method="GET" class="d-flex gap-2">
                    <input type="date" name="date" class="form-control" value="<?= $verification_date ?>">
                    <button type="submit" class="btn btn-light"><i class="fas fa-search"></i> Verify</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card text-white bg-primary summary-card">
                <div class="card-body text-center">
                    <h6 class="card-title">Total Items in Database</h6>
                    <h2><?= $total_items ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-success summary-card">
                <div class="card-body text-center">
                    <h6 class="card-title">Accurate Records</h6>
                    <h2><?= $accurate_items ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-danger summary-card">
                <div class="card-body text-center">
                    <h6 class="card-title">Inaccurate Records</h6>
                    <h2><?= $inaccurate_items ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-white bg-info summary-card">
                <div class="card-body text-center">
                    <h6 class="card-title">Database Accuracy Rate</h6>
                    <h2><?= number_format($accuracy_percentage, 1) ?>%</h2>
                </div>
            </div>
        </div>
    </div>

    <!-- CLUBBED VIEW: What Your Excise Register Shows -->
    <div class="section-title excise">
        <i class="fas fa-chart-line me-2"></i>EXCISE REGISTER VIEW (Clubbed by Category & Size)
        <small class="text-muted ms-3">This is exactly what your excise register displays</small>
    </div>
    
    <div class="table-responsive mb-5">
        <table class="table table-bordered table-hover table-clubbed">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Category</th>
                    <th>Size</th>
                    <th class="text-center">Opening Balance</th>
                    <th class="text-center">Received</th>
                    <th class="text-center">Sold</th>
                    <th class="text-center">Closing Balance</th>
                    <th class="text-center">Items Count</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                if (!empty($sorted_aggregated)):
                    foreach ($sorted_aggregated as $data): 
                ?>
                <tr>
                    <td><?= $counter++ ?></td>
                    <td><strong><?= htmlspecialchars($data['category']) ?></strong></td>
                    <td><?= htmlspecialchars($data['size']) ?></td>
                    <td class="text-center"><?= $data['opening'] > 0 ? number_format($data['opening']) : '-' ?></td>
                    <td class="text-center"><?= $data['purchase'] > 0 ? number_format($data['purchase']) : '-' ?></td>
                    <td class="text-center"><?= $data['sales'] > 0 ? number_format($data['sales']) : '-' ?></td>
                    <td class="text-center"><strong><?= $data['closing'] > 0 ? number_format($data['closing']) : '-' ?></strong></td>
                    <td class="text-center"><?= $data['item_count'] ?> item(s)</td>
                </tr>
                <?php 
                    endforeach;
                else: 
                ?>
                <tr>
                    <td colspan="8" class="text-center text-muted">No data found for this date</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- DETAILED VIEW: Individual Items from Database -->
    <div class="section-title database">
        <i class="fas fa-database me-2"></i>DATABASE DETAIL VIEW (Individual Item Records)
        <small class="text-muted ms-3">Each row shows actual database record with verification status</small>
    </div>
    
    <div class="table-responsive">
        <table class="table table-bordered table-hover table-details">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Item Code</th>
                    <th>Description</th>
                    <th>Category</th>
                    <th>Size</th>
                    <th class="text-center">Opening</th>
                    <th class="text-center">Purchase</th>
                    <th class="text-center">Sales</th>
                    <th class="text-center">Table Closing</th>
                    <th class="text-center">Calculated Closing</th>
                    <th class="text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                if (!empty($individual_items)):
                    foreach ($individual_items as $item):
                        $row_class = $item['is_accurate'] ? 'match-row' : 'mismatch-row';
                ?>
                <tr class="<?= $row_class ?>">
                    <td><?= $counter++ ?></td>
                    <td><code><?= htmlspecialchars($item['code']) ?></code></td>
                    <td><?= htmlspecialchars(substr($item['details'], 0, 50)) ?>...</td>
                    <td><?= htmlspecialchars($item['category']) ?></td>
                    <td><?= htmlspecialchars($item['size']) ?></td>
                    <td class="text-center"><?= $item['opening'] ?></td>
                    <td class="text-center"><?= $item['purchase'] ?></td>
                    <td class="text-center"><?= $item['sales'] ?></td>
                    <td class="text-center"><strong><?= $item['closing'] ?></strong></td>
                    <td class="text-center"><?= $item['calculated_closing'] ?></td>
                    <td class="text-center">
                        <?php if ($item['is_accurate']): ?>
                            <span class="badge bg-success"><i class="fas fa-check"></i> MATCH</span>
                        <?php else: ?>
                            <span class="badge bg-danger"><i class="fas fa-times"></i> MISMATCH</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php 
                    endforeach;
                else: 
                ?>
                <tr>
                    <td colspan="11" class="text-center text-muted">No data found for this date</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Verification Summary -->
    <?php if ($inaccurate_items > 0): ?>
    <div class="alert alert-danger mt-4">
        <h5><i class="fas fa-exclamation-triangle"></i> Verification Failed!</h5>
        <p>Found <strong><?= $inaccurate_items ?></strong> item(s) where the table closing balance does NOT match the calculated closing balance.</p>
        <p class="mb-0">Formula used: <code>Closing = Opening + Purchase - Sales</code></p>
        <hr>
        <p class="mb-0">Please check the red highlighted rows above and correct the data in your database.</p>
    </div>
    <?php elseif ($total_items > 0): ?>
    <div class="alert alert-success mt-4">
        <h5><i class="fas fa-check-circle"></i> Verification Passed!</h5>
        <p>All <strong><?= $total_items ?></strong> item(s) in the database have accurate closing balances.</p>
        <p class="mb-0">The excise register is displaying the correct aggregated data.</p>
    </div>
    <?php endif; ?>

    <!-- Legend -->
    <div class="card mt-4">
        <div class="card-header bg-light">
            <strong><i class="fas fa-info-circle"></i> Understanding This Report</strong>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6>📊 CLUBBED VIEW (Top Table)</h6>
                    <ul>
                        <li>Same <strong>categories</strong> are grouped together</li>
                        <li>Same <strong>sizes</strong> are summed/aggregated</li>
                        <li>This matches EXACTLY what your Excise Register shows</li>
                        <li>One row per Category + Size combination</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6>🔍 DETAILED VIEW (Bottom Table)</h6>
                    <ul>
                        <li>Shows <strong>each individual item</strong> from database</li>
                        <li>Calculated Closing = Opening + Purchase - Sales</li>
                        <li>🟢 Green row = Database closing matches calculation</li>
                        <li>🔴 Red row = Database has incorrect closing balance</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Export Buttons -->
    <div class="mt-4 mb-4 text-center">
        <button class="btn btn-success" onclick="exportToCSV()"><i class="fas fa-file-excel"></i> Export to CSV</button>
        <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
        <button class="btn btn-secondary" onclick="location.href='?date=<?= date('Y-m-d', strtotime($verification_date . ' -1 day')) ?>'"><i class="fas fa-arrow-left"></i> Previous Day</button>
        <button class="btn btn-secondary" onclick="location.href='?date=<?= date('Y-m-d', strtotime($verification_date . ' +1 day')) ?>'"><i class="fas fa-arrow-right"></i> Next Day</button>
    </div>
</div>

<script>
function exportToCSV() {
    let csv = [];
    
    // Export Clubbed View
    csv.push(['=== EXCISE REGISTER VIEW (Clubbed by Category & Size) ===']);
    csv.push(['Date: <?= $verification_date ?>']);
    csv.push(['']);
    csv.push(['#', 'Category', 'Size', 'Opening', 'Received', 'Sold', 'Closing', 'Items Count']);
    
    let rows = document.querySelectorAll('.table-clubbed tbody tr');
    rows.forEach(row => {
        let rowData = [];
        row.querySelectorAll('td').forEach(cell => {
            rowData.push('"' + cell.innerText.trim() + '"');
        });
        csv.push(rowData.join(','));
    });
    
    csv.push(['']);
    csv.push(['=== DATABASE DETAIL VIEW (Individual Items) ===']);
    csv.push(['']);
    csv.push(['#', 'Item Code', 'Description', 'Category', 'Size', 'Opening', 'Purchase', 'Sales', 'Table Closing', 'Calculated Closing', 'Status']);
    
    let detailRows = document.querySelectorAll('.table-details tbody tr');
    detailRows.forEach(row => {
        let rowData = [];
        row.querySelectorAll('td').forEach(cell => {
            rowData.push('"' + cell.innerText.trim() + '"');
        });
        csv.push(rowData.join(','));
    });
    
    let blob = new Blob([csv.join('\n')], { type: 'text/csv' });
    let link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'verification_<?= $verification_date ?>.csv';
    link.click();
}
</script>

</body>
</html>
<?php $conn->close(); ?>