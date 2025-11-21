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

include_once "../config/db.php"; // MySQLi connection in $conn

// Get company ID from session
$compID = $_SESSION['CompID'];

// Get company name from session or set default
$companyName = isset($_SESSION['company_name']) ? $_SESSION['company_name'] : "Company Name";

// Get filter parameters
$from_date = isset($_GET['from_date']) ? $_GET['from_date'] : date('Y-m-d');
$to_date = isset($_GET['to_date']) ? $_GET['to_date'] : date('Y-m-d');
$supplier = isset($_GET['supplier']) ? $_GET['supplier'] : 'all';
$show_details = isset($_GET['show_details']) ? $_GET['show_details'] : false;

// Format dates for display
$from_date_display = date('d-M-Y', strtotime($from_date));
$to_date_display = date('d-M-Y', strtotime($to_date));

// Fetch suppliers from tblsupplier for dropdown (filtered by company if needed)
$suppliers_query = "SELECT CODE, DETAILS FROM tblsupplier ORDER BY DETAILS";
$suppliers_result = $conn->query($suppliers_query);
$suppliers = [];
while ($row = $suppliers_result->fetch_assoc()) {
    $suppliers[$row['CODE']] = $row['DETAILS'];
}

// Fetch tax rates from tblcompany
$tax_rates_query = "SELECT 
                    sales_tax_percent, 
                    cl_tax, 
                    imfl_tax, 
                    wine_tax, 
                    mid_beer_tax, 
                    strong_beer_tax,
                    tcs_percent,
                    surcharges_percent,
                    educ_cess_percent,
                    court_fees
                  FROM tblcompany 
                  WHERE CompID = ?";
$tax_stmt = $conn->prepare($tax_rates_query);
$tax_stmt->bind_param("i", $compID);
$tax_stmt->execute();
$tax_result = $tax_stmt->get_result();
$tax_rates = $tax_result->fetch_assoc();
$tax_stmt->close();

// Set default values if not found
if (!$tax_rates) {
    $tax_rates = [
        'sales_tax_percent' => 0.00,
        'cl_tax' => 0.00,
        'imfl_tax' => 0.00,
        'wine_tax' => 0.00,
        'mid_beer_tax' => 0.00,
        'strong_beer_tax' => 0.00,
        'tcs_percent' => 1.00,
        'surcharges_percent' => 0.00,
        'educ_cess_percent' => 0.00,
        'court_fees' => 10.00
    ];
}

// Function to calculate taxes based on item type
function calculateTaxes($amount, $itemType, $tax_rates) {
    $taxes = [
        'cl_tax' => 0,
        'imfl_tax' => 0,
        'wine_tax' => 0,
        'mid_beer_tax' => 0,
        'strong_beer_tax' => 0,
        'sales_tax' => 0,
        'surcharges' => 0,
        'educ_cess' => 0,
        'total_tax' => 0
    ];
    
    // Determine which tax rate to apply based on item type
    $tax_rate = 0;
    switch(strtoupper($itemType)) {
        case 'CL':
            $tax_rate = $tax_rates['cl_tax'];
            $taxes['cl_tax'] = ($amount * $tax_rate) / 100;
            break;
        case 'IMFL':
            $tax_rate = $tax_rates['imfl_tax'];
            $taxes['imfl_tax'] = ($amount * $tax_rate) / 100;
            break;
        case 'WINE':
            $tax_rate = $tax_rates['wine_tax'];
            $taxes['wine_tax'] = ($amount * $tax_rate) / 100;
            break;
        case 'MID_BEER':
        case 'BEER':
            $tax_rate = $tax_rates['mid_beer_tax'];
            $taxes['mid_beer_tax'] = ($amount * $tax_rate) / 100;
            break;
        case 'STRONG_BEER':
            $tax_rate = $tax_rates['strong_beer_tax'];
            $taxes['strong_beer_tax'] = ($amount * $tax_rate) / 100;
            break;
        default:
            $tax_rate = 0;
    }
    
    // Calculate sales tax
    $taxes['sales_tax'] = ($amount * $tax_rates['sales_tax_percent']) / 100;
    
    // Calculate surcharges
    $taxes['surcharges'] = ($amount * $tax_rates['surcharges_percent']) / 100;
    
    // Calculate education cess
    $taxes['educ_cess'] = ($amount * $tax_rates['educ_cess_percent']) / 100;
    
    // Calculate total tax
    $taxes['total_tax'] = $taxes['cl_tax'] + $taxes['imfl_tax'] + $taxes['wine_tax'] + 
                          $taxes['mid_beer_tax'] + $taxes['strong_beer_tax'] + 
                          $taxes['sales_tax'] + $taxes['surcharges'] + $taxes['educ_cess'];
    
    return $taxes;
}

// Build query to fetch purchase data with CompID filter - UPDATED FOR YOUR TABLE STRUCTURE
$query = "SELECT 
            p.ID, 
            p.DATE, 
            p.SUBCODE, 
            p.VOC_NO, 
            p.INV_NO, 
            p.TPNO, 
            p.TAMT as net_amt, 
            p.CASHDIS as cash_disc, 
            p.SCHDIS as sch_disc, 
            p.OCTROI as oct, 
            p.STAX_AMT as sales_tax, 
            p.TCS_AMT as tc_amt, 
            p.MISC_CHARG as sarc_amt, 
            p.FREIGHT as frieght,
            (p.TAMT - p.CASHDIS - p.SCHDIS + p.OCTROI + p.STAX_AMT + p.TCS_AMT + p.MISC_CHARG + p.FREIGHT) as total
          FROM tblpurchases p
          WHERE p.DATE BETWEEN ? AND ? AND p.CompID = ?";

$params = [$from_date, $to_date, $compID];
$types = "ssi";

if ($supplier !== 'all') {
    $query .= " AND p.SUBCODE = ?";
    $params[] = $supplier;
    $types .= "s";
}

$query .= " ORDER BY p.DATE, p.VOC_NO";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$purchases = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// If show_details is enabled, fetch purchase details for each purchase - UPDATED FOR YOUR TABLE STRUCTURE
$purchase_details = [];
$purchase_taxes = []; // Store tax calculations for each purchase

if ($show_details) {
    $purchase_ids = array_column($purchases, 'ID');
    if (!empty($purchase_ids)) {
        $placeholders = str_repeat('?,', count($purchase_ids) - 1) . '?';
        $details_query = "SELECT 
                        pd.PurchaseID,
                        pd.ItemCode,
                        pd.ItemName,
                        pd.Size,
                        pd.Cases,
                        pd.Bottles,
                        pd.FreeCases,
                        pd.FreeBottles,
                        pd.CaseRate,
                        pd.MRP,
                        pd.Amount,
                        pd.BottlesPerCase,
                        pd.BatchNo,
                        pd.AutoBatch,
                        pd.MfgMonth,
                        pd.BL,
                        pd.VV,
                        pd.TotBott,
                        pd.AUTO_TPNO,
                        i.ItemType  -- Add item type for tax calculation
                    FROM tblpurchasedetails pd
                    LEFT JOIN tblitems i ON pd.ItemCode = i.ITEM_CODE  -- Join with items table to get item type
                    WHERE pd.PurchaseID IN ($placeholders)
                    ORDER BY pd.PurchaseID, pd.DetailID";
        
        $stmt = $conn->prepare($details_query);
        $stmt->bind_param(str_repeat('i', count($purchase_ids)), ...$purchase_ids);
        $stmt->execute();
        $details_result = $stmt->get_result();
        
        while ($row = $details_result->fetch_assoc()) {
            $purchase_details[$row['PurchaseID']][] = $row;
            
            // Calculate taxes for each item
            $itemType = $row['ItemType'] ?? 'CL';
            $taxes = calculateTaxes($row['Amount'], $itemType, $tax_rates);
            
            // Initialize purchase taxes array if not exists
            if (!isset($purchase_taxes[$row['PurchaseID']])) {
                $purchase_taxes[$row['PurchaseID']] = [
                    'total_amount' => 0,
                    'total_tax' => 0,
                    'cl_tax' => 0,
                    'imfl_tax' => 0,
                    'wine_tax' => 0,
                    'mid_beer_tax' => 0,
                    'strong_beer_tax' => 0,
                    'sales_tax' => 0,
                    'surcharges' => 0,
                    'educ_cess' => 0
                ];
            }
            
            // Update purchase taxes totals
            $purchase_taxes[$row['PurchaseID']]['total_amount'] += $row['Amount'];
            $purchase_taxes[$row['PurchaseID']]['total_tax'] += $taxes['total_tax'];
            $purchase_taxes[$row['PurchaseID']]['cl_tax'] += $taxes['cl_tax'];
            $purchase_taxes[$row['PurchaseID']]['imfl_tax'] += $taxes['imfl_tax'];
            $purchase_taxes[$row['PurchaseID']]['wine_tax'] += $taxes['wine_tax'];
            $purchase_taxes[$row['PurchaseID']]['mid_beer_tax'] += $taxes['mid_beer_tax'];
            $purchase_taxes[$row['PurchaseID']]['strong_beer_tax'] += $taxes['strong_beer_tax'];
            $purchase_taxes[$row['PurchaseID']]['sales_tax'] += $taxes['sales_tax'];
            $purchase_taxes[$row['PurchaseID']]['surcharges'] += $taxes['surcharges'];
            $purchase_taxes[$row['PurchaseID']]['educ_cess'] += $taxes['educ_cess'];
        }
        $stmt->close();
    }
}

// Calculate totals
$totals = [
    'net_amt' => 0,
    'cash_disc' => 0,
    'sch_disc' => 0,
    'oct' => 0,
    'sales_tax' => 0,
    'tc_amt' => 0,
    'sarc_amt' => 0,
    'frieght' => 0,
    'total' => 0,
    'calculated_tax' => 0
];

foreach ($purchases as $purchase) {
    $totals['net_amt'] += floatval($purchase['net_amt']);
    $totals['cash_disc'] += floatval($purchase['cash_disc']);
    $totals['sch_disc'] += floatval($purchase['sch_disc']);
    $totals['oct'] += floatval($purchase['oct']);
    $totals['sales_tax'] += floatval($purchase['sales_tax']);
    $totals['tc_amt'] += floatval($purchase['tc_amt']);
    $totals['sarc_amt'] += floatval($purchase['sarc_amt']);
    $totals['frieght'] += floatval($purchase['frieght']);
    $totals['total'] += floatval($purchase['total']);
    
    // Add calculated tax if available
    if (isset($purchase_taxes[$purchase['ID']])) {
        $totals['calculated_tax'] += $purchase_taxes[$purchase['ID']]['total_tax'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Purchase Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  <style>
    .text-right {
        text-align: right;
    }
    .text-center {
        text-align: center;
    }
    .total-row {
        font-weight: bold;
        background-color: #e9ecef;
    }
    .voucher-header {
        background-color: #e3f2fd;
        border-left: 4px solid #2196f3;
        padding: 10px 15px;
        margin: 15px 0 5px 0;
        font-weight: bold;
        border-radius: 4px;
    }
    .detailed-view .items-details {
        margin-bottom: 20px;
    }
    .tax-breakdown {
        background-color: #f8f9fa;
        border-left: 3px solid #28a745;
        padding: 8px 12px;
        margin: 5px 0;
        font-size: 0.9em;
    }
    .table-sm th, .table-sm td {
        padding: 0.3rem;
        font-size: 0.875rem;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Purchase Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-3">
                <label class="form-label">Date From:</label>
                <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($from_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Date To:</label>
                <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($to_date) ?>">
              </div>
              <div class="col-md-3">
                <label class="form-label">Supplier:</label>
                <select class="form-select" name="supplier">
                  <option value="all" <?= $supplier === 'all' ? 'selected' : '' ?>>All Suppliers</option>
                  <?php foreach ($suppliers as $code => $name): ?>
                    <option value="<?= htmlspecialchars($code) ?>" <?= $supplier === $code ? 'selected' : '' ?>>
                      <?= htmlspecialchars($name) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Report Type:</label>
                <select class="form-select" name="show_details">
                  <option value="0" <?= !$show_details ? 'selected' : '' ?>>Summary</option>
                  <option value="1" <?= $show_details ? 'selected' : '' ?>>Detailed</option>
                </select>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate Report
              </button>
              <button type="button" class="btn btn-success" onclick="window.print()">
                <i class="fas fa-print me-1"></i> Print Report
              </button>
              <a href="dashboard.php" class="btn btn-secondary ms-auto">
                <i class="fas fa-times me-1"></i> Exit
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Report Results -->
      <?php if (isset($_GET['from_date']) || isset($_GET['to_date']) || isset($_GET['supplier'])): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Purchase Report From <?= $from_date_display ?> To <?= $to_date_display ?></h5>
            <?php if ($supplier !== 'all'): ?>
              <p class="text-muted">Supplier: <?= htmlspecialchars($suppliers[$supplier] ?? $supplier) ?></p>
            <?php endif; ?>
            <p class="text-muted"><strong>Report Type:</strong> <?= $show_details ? 'Detailed' : 'Summary' ?></p>
            
            <!-- Display Current Tax Rates -->
            <div class="tax-rates-info mt-3 p-3 bg-light rounded">
              <h6 class="mb-2">Current Tax Rates:</h6>
              <div class="row">
                <div class="col-md-3">CL Tax: <?= number_format($tax_rates['cl_tax'], 2) ?>%</div>
                <div class="col-md-3">IMFL Tax: <?= number_format($tax_rates['imfl_tax'], 2) ?>%</div>
                <div class="col-md-3">Wine Tax: <?= number_format($tax_rates['wine_tax'], 2) ?>%</div>
                <div class="col-md-3">Sales Tax: <?= number_format($tax_rates['sales_tax_percent'], 2) ?>%</div>
              </div>
            </div>
          </div>
          
          <?php if (!$show_details): ?>
            <!-- Summary Report View -->
            <div class="table-container">
              <table class="report-table table table-bordered">
                <thead class="table-light">
                  <tr>
                    <th>Date</th>
                    <th>Supplier Name</th>
                    <th>V. No.</th>
                    <th>Bill No.</th>
                    <th>T.P. No.</th>
                    <th class="text-right">Net Amt.</th>
                    <th class="text-right">Cash Disc.</th>
                    <th class="text-right">Sch. Disc.</th>
                    <th class="text-right">Oct.</th>
                    <th class="text-right">Sales Tax</th>
                    <th class="text-right">TC $ Amt.</th>
                    <th class="text-right">Sarc. Amt.</th>
                    <th class="text-right">Frieght</th>
                    <th class="text-right">Calculated Tax</th>
                    <th class="text-right">Total Bill Amt.</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!empty($purchases)): ?>
                    <?php foreach ($purchases as $purchase): ?>
                      <tr>
                        <td><?= date('d-M-Y', strtotime($purchase['DATE'])) ?></td>
                        <td><?= isset($suppliers[$purchase['SUBCODE']]) ? htmlspecialchars($suppliers[$purchase['SUBCODE']]) : htmlspecialchars($purchase['SUBCODE']) ?></td>
                        <td><?= htmlspecialchars($purchase['VOC_NO']) ?></td>
                        <td><?= htmlspecialchars($purchase['INV_NO']) ?></td>
                        <td><?= htmlspecialchars($purchase['TPNO']) ?></td>
                        <td class="text-right"><?= number_format($purchase['net_amt'], 2) ?></td>
                        <td class="text-right"><?= number_format($purchase['cash_disc'], 2) ?></td>
                        <td class="text-right"><?= number_format($purchase['sch_disc'], 2) ?></td>
                        <td class="text-right"><?= number_format($purchase['oct'], 2) ?></td>
                        <td class="text-right"><?= number_format($purchase['sales_tax'], 2) ?></td>
                        <td class="text-right"><?= number_format($purchase['tc_amt'], 2) ?></td>
                        <td class="text-right"><?= number_format($purchase['sarc_amt'], 2) ?></td>
                        <td class="text-right"><?= number_format($purchase['frieght'], 2) ?></td>
                        <td class="text-right">
                          <?= isset($purchase_taxes[$purchase['ID']]) ? number_format($purchase_taxes[$purchase['ID']]['total_tax'], 2) : '0.00' ?>
                        </td>
                        <td class="text-right"><?= number_format($purchase['total'], 2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                      <td colspan="5" class="text-end"><strong>Total:</strong></td>
                      <td class="text-right"><strong><?= number_format($totals['net_amt'], 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($totals['cash_disc'], 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($totals['sch_disc'], 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($totals['oct'], 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($totals['sales_tax'], 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($totals['tc_amt'], 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($totals['sarc_amt'], 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($totals['frieght'], 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($totals['calculated_tax'], 2) ?></strong></td>
                      <td class="text-right"><strong><?= number_format($totals['total'], 2) ?></strong></td>
                    </tr>
                  <?php else: ?>
                    <tr>
                      <td colspan="15" class="text-center text-muted">No purchases found for the selected period.</td>
                    </tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <!-- Detailed Report View -->
            <div class="detailed-view">
              <?php if (!empty($purchases)): ?>
                <?php foreach ($purchases as $purchase): ?>
                  <!-- Voucher Header -->
                  <div class="voucher-header">
                    Voucher No: <?= htmlspecialchars($purchase['VOC_NO']) ?> | 
                    Date: <?= date('d-M-Y', strtotime($purchase['DATE'])) ?> | 
                    Supplier: <?= isset($suppliers[$purchase['SUBCODE']]) ? htmlspecialchars($suppliers[$purchase['SUBCODE']]) : htmlspecialchars($purchase['SUBCODE']) ?> | 
                    Bill No: <?= htmlspecialchars($purchase['INV_NO']) ?> | 
                    T.P. No: <?= htmlspecialchars($purchase['TPNO']) ?>
                  </div>

                  <!-- Items Details Table -->
                  <?php if (isset($purchase_details[$purchase['ID']])): ?>
                    <div class="items-details">
                      <table class="table table-sm table-bordered">
                        <thead class="table-light">
                          <tr>
                            <th>Item Code</th>
                            <th>Item Name</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th class="text-right">Cases</th>
                            <th class="text-right">Bottles</th>
                            <th class="text-right">Free Cases</th>
                            <th class="text-right">Free Bottles</th>
                            <th class="text-right">Case Rate</th>
                            <th class="text-right">MRP</th>
                            <th class="text-right">Amount</th>
                            <th class="text-right">Tax Amount</th>
                            <th>Batch No</th>
                            <th>Mfg Month</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php 
                          $voucher_totals = [
                              'amount' => 0,
                              'tax_amount' => 0,
                              'cl_tax' => 0,
                              'imfl_tax' => 0,
                              'wine_tax' => 0,
                              'mid_beer_tax' => 0,
                              'strong_beer_tax' => 0,
                              'sales_tax' => 0,
                              'surcharges' => 0,
                              'educ_cess' => 0
                          ];
                          ?>
                          <?php foreach ($purchase_details[$purchase['ID']] as $item): ?>
                            <?php
                            $itemType = $item['ItemType'] ?? 'CL'; // Default to CL if not specified
                            $taxes = calculateTaxes($item['Amount'], $itemType, $tax_rates);
                            
                            // Update voucher totals
                            $voucher_totals['amount'] += $item['Amount'];
                            $voucher_totals['tax_amount'] += $taxes['total_tax'];
                            $voucher_totals['cl_tax'] += $taxes['cl_tax'];
                            $voucher_totals['imfl_tax'] += $taxes['imfl_tax'];
                            $voucher_totals['wine_tax'] += $taxes['wine_tax'];
                            $voucher_totals['mid_beer_tax'] += $taxes['mid_beer_tax'];
                            $voucher_totals['strong_beer_tax'] += $taxes['strong_beer_tax'];
                            $voucher_totals['sales_tax'] += $taxes['sales_tax'];
                            $voucher_totals['surcharges'] += $taxes['surcharges'];
                            $voucher_totals['educ_cess'] += $taxes['educ_cess'];
                            ?>
                            <tr>
                              <td><?= htmlspecialchars($item['ItemCode']) ?></td>
                              <td><?= htmlspecialchars($item['ItemName']) ?></td>
                              <td><?= htmlspecialchars($itemType) ?></td>
                              <td><?= htmlspecialchars($item['Size']) ?></td>
                              <td class="text-right"><?= number_format($item['Cases'], 2) ?></td>
                              <td class="text-right"><?= number_format($item['Bottles'], 0) ?></td>
                              <td class="text-right"><?= number_format($item['FreeCases'], 2) ?></td>
                              <td class="text-right"><?= number_format($item['FreeBottles'], 0) ?></td>
                              <td class="text-right"><?= number_format($item['CaseRate'], 3) ?></td>
                              <td class="text-right"><?= number_format($item['MRP'], 2) ?></td>
                              <td class="text-right"><?= number_format($item['Amount'], 2) ?></td>
                              <td class="text-right"><?= number_format($taxes['total_tax'], 2) ?></td>
                              <td><?= htmlspecialchars($item['BatchNo']) ?></td>
                              <td><?= htmlspecialchars($item['MfgMonth']) ?></td>
                            </tr>
                          <?php endforeach; ?>
                          <!-- Voucher Total Row -->
                          <tr class="table-info">
                            <td colspan="10" class="text-end"><strong>Voucher Total:</strong></td>
                            <td class="text-right"><strong><?= number_format($voucher_totals['amount'], 2) ?></strong></td>
                            <td class="text-right"><strong><?= number_format($voucher_totals['tax_amount'], 2) ?></strong></td>
                            <td colspan="2"></td>
                          </tr>
                        </tbody>
                      </table>
                      
                      <!-- Tax Breakdown -->
                      <div class="tax-breakdown">
                        <strong>Tax Breakdown:</strong>
                        <?php if ($voucher_totals['cl_tax'] > 0): ?>
                          <span class="ms-3">CL Tax: <?= number_format($voucher_totals['cl_tax'], 2) ?></span>
                        <?php endif; ?>
                        <?php if ($voucher_totals['imfl_tax'] > 0): ?>
                          <span class="ms-3">IMFL Tax: <?= number_format($voucher_totals['imfl_tax'], 2) ?></span>
                        <?php endif; ?>
                        <?php if ($voucher_totals['wine_tax'] > 0): ?>
                          <span class="ms-3">Wine Tax: <?= number_format($voucher_totals['wine_tax'], 2) ?></span>
                        <?php endif; ?>
                        <?php if ($voucher_totals['mid_beer_tax'] > 0): ?>
                          <span class="ms-3">Beer Tax: <?= number_format($voucher_totals['mid_beer_tax'], 2) ?></span>
                        <?php endif; ?>
                        <?php if ($voucher_totals['strong_beer_tax'] > 0): ?>
                          <span class="ms-3">Strong Beer Tax: <?= number_format($voucher_totals['strong_beer_tax'], 2) ?></span>
                        <?php endif; ?>
                        <?php if ($voucher_totals['sales_tax'] > 0): ?>
                          <span class="ms-3">Sales Tax: <?= number_format($voucher_totals['sales_tax'], 2) ?></span>
                        <?php endif; ?>
                        <?php if ($voucher_totals['surcharges'] > 0): ?>
                          <span class="ms-3">Surcharges: <?= number_format($voucher_totals['surcharges'], 2) ?></span>
                        <?php endif; ?>
                        <?php if ($voucher_totals['educ_cess'] > 0): ?>
                          <span class="ms-3">Education Cess: <?= number_format($voucher_totals['educ_cess'], 2) ?></span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="alert alert-warning mb-4">
                      <i class="fas fa-exclamation-triangle me-2"></i> No items found for this voucher.
                    </div>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="alert alert-info">
                  <i class="fas fa-info-circle me-2"></i> No purchases found for the selected period.
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php elseif (isset($_GET['from_date']) && empty($purchases)): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No purchases found for the selected criteria.
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