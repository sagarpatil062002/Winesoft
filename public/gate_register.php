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

// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values
$selected_date = isset($_GET['selected_date']) ? $_GET['selected_date'] : date('Y-m-d');
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'Foreign Liquor';

// Fetch company name and license number
$companyName = "WANGANHAN HOTEL";
$licenseNo = "FL-II 3";
$companyQuery = "SELECT COMP_NAME, COMP_FLNO FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
    $licenseNo = $row['COMP_FLNO'] ? $row['COMP_FLNO'] : $licenseNo;
}
$companyStmt->close();

// Function to get base size for grouping
function getBaseSize($size) {
    $baseSize = preg_replace('/\s*ML.*$/i', ' ML', $size);
    $baseSize = preg_replace('/\s*-\s*\d+$/', '', $baseSize);
    $baseSize = preg_replace('/\s*\(\d+\)$/', '', $baseSize);
    $baseSize = preg_replace('/\s*\([^)]*\)/', '', $baseSize);
    $baseSize = trim($baseSize);

    // Map specific sizes to display columns
    $size_mapping = [
        '170 ML' => '180 ML'
    ];

    return isset($size_mapping[$baseSize]) ? $size_mapping[$baseSize] : $baseSize;
}

// Define size columns for each liquor type
$size_columns_s = [
    '2000 ML Pet (6)', '2000 ML(4)', '2000 ML(6)', '1000 ML(Pet)', '1000 ML',
    '750 ML(6)', '750 ML (Pet)', '750 ML', '700 ML', '700 ML(6)',
    '375 ML (12)', '375 ML', '375 ML (Pet)', '350 ML (12)', '275 ML(24)',
    '200 ML (48)', '200 ML (24)', '200 ML (30)', '200 ML (12)', '180 ML(24)',
    '180 ML (Pet)', '180 ML', '170 ML (48)', '90 ML(100)', '90 ML (Pet)-100', '90 ML (Pet)-96',
    '90 ML-(96)', '90 ML', '60 ML', '60 ML (75)', '50 ML(120)', '50 ML (180)',
    '50 ML (24)', '50 ML (192)'
];
$size_columns_w = ['750 ML(6)', '750 ML', '650 ML', '375 ML', '330 ML', '180 ML'];
$size_columns_fb = ['650 ML', '500 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];
$size_columns_mb = ['650 ML', '500 ML (CAN)', '330 ML', '330 ML (CAN)'];

// For Country Liquor - use Spirits sizes
$size_columns_country = $size_columns_s;

// Group sizes by base size for each liquor type
function groupSizes($sizes) {
    $grouped = [];
    foreach ($sizes as $size) {
        $baseSize = getBaseSize($size);
        if (!isset($grouped[$baseSize])) {
            $grouped[$baseSize] = [];
        }
        $grouped[$baseSize][] = $size;
    }
    return $grouped;
}

$grouped_sizes_s = groupSizes($size_columns_s);
$grouped_sizes_w = groupSizes($size_columns_w);
$grouped_sizes_fb = groupSizes($size_columns_fb);
$grouped_sizes_mb = groupSizes($size_columns_mb);
$grouped_sizes_country = groupSizes($size_columns_country);

// Get display sizes (base sizes) for each liquor type
$display_sizes_s = array_keys($grouped_sizes_s);
$display_sizes_w = array_keys($grouped_sizes_w);
$display_sizes_fb = array_keys($grouped_sizes_fb);
$display_sizes_mb = array_keys($grouped_sizes_mb);
$display_sizes_country = array_keys($grouped_sizes_country);

// Fetch gate register data from tbl_cash_memo_prints with permit details
$gate_data = [];
$liquor_summary = [];

// Initialize liquor summary based on mode
if ($mode == 'Country Liquor') {
    $liquor_summary['Country Liquor'] = array_fill_keys($display_sizes_country, 0);
} else {
    $liquor_summary['Spirits'] = array_fill_keys($display_sizes_s, 0);
    $liquor_summary['Wines'] = array_fill_keys($display_sizes_w, 0);
    $liquor_summary['Fermented Beer'] = array_fill_keys($display_sizes_fb, 0);
    $liquor_summary['Mild Beer'] = array_fill_keys($display_sizes_mb, 0);
}

$gateQuery = "SELECT
                cmp.bill_date,
                cmp.bill_no,
                cmp.customer_name,
                cmp.permit_no,
                cmp.permit_place,
                cmp.permit_exp_date,
                cmp.items_json,
                cmp.total_amount,
                tp.DETAILS as permit_holder_name,
                tp.P_ISSDT as permit_issue_date,
                tp.P_EXP_DT as permit_expiry_date,
                tp.PLACE_ISS as permit_issue_place,
                tp.LIQ_FLAG as permit_liq_flag
              FROM tbl_cash_memo_prints cmp
              LEFT JOIN tblpermit tp ON cmp.permit_no = tp.P_NO
              WHERE cmp.comp_id = ?
              AND cmp.bill_date = ?";

// Add mode filter based on permit LIQ_FLAG
if ($mode == 'Country Liquor') {
    $gateQuery .= " AND (tp.LIQ_FLAG = 'C' OR tp.LIQ_FLAG IS NULL)";
} else {
    $gateQuery .= " AND (tp.LIQ_FLAG = 'F' OR tp.LIQ_FLAG IS NULL)";
}

$gateQuery .= " ORDER BY cmp.bill_no";

$gateStmt = $conn->prepare($gateQuery);
$gateStmt->bind_param("is", $compID, $selected_date);
$gateStmt->execute();
$gateResult = $gateStmt->get_result();

$serial_no = 1;
$total_amount = 0;

while ($row = $gateResult->fetch_assoc()) {
    $total_amount += $row['total_amount'];

    // Use permit holder name from tblpermit if available, otherwise use customer name
    $permit_holder_name = $row['permit_holder_name'] ?: $row['customer_name'];

    // Format permit validity
    $permit_validity = '';
    if ($row['permit_expiry_date']) {
        $permit_validity = date('d/m/Y', strtotime($row['permit_expiry_date']));
    } elseif ($row['permit_exp_date']) {
        $permit_validity = date('d/m/Y', strtotime($row['permit_exp_date']));
    }

    // Get permit district
    $permit_district = $row['permit_issue_place'] ?: $row['permit_place'] ?: 'N/A';

    // Process items for liquor summary
    $items = json_decode($row['items_json'], true);
    if (is_array($items)) {
        foreach ($items as $item) {
            $baseSize = getBaseSize($item['DETAILS2']);
            $qty = floatval($item['QTY']);

            if ($mode == 'Country Liquor') {
                // For Country Liquor mode, add all to Country Liquor category
                if (isset($liquor_summary['Country Liquor'][$baseSize])) {
                    $liquor_summary['Country Liquor'][$baseSize] += $qty;
                }
            } else {
                // For Foreign Liquor mode, categorize properly
                $liquor_type = 'Spirits'; // Default
                $item_name = strtolower($item['DETAILS']);
                if (strpos($item_name, 'beer') !== false) {
                    if (strpos($item_name, 'mild') !== false) {
                        $liquor_type = 'Mild Beer';
                    } else {
                        $liquor_type = 'Fermented Beer';
                    }
                } elseif (strpos($item_name, 'wine') !== false) {
                    $liquor_type = 'Wines';
                }

                // Add to liquor summary
                if (isset($liquor_summary[$liquor_type][$baseSize])) {
                    $liquor_summary[$liquor_type][$baseSize] += $qty;
                }
            }
        }
    }

    $gate_data[] = [
        'serial_no' => $serial_no++,
        'bill_no' => $row['bill_no'],
        'permit_no' => $row['permit_no'],
        'permit_holder_name' => $permit_holder_name,
        'permit_validity' => $permit_validity,
        'permit_district' => $permit_district,
        'amount' => $row['total_amount'],
        'liq_flag' => $row['permit_liq_flag']
    ];
}
$gateStmt->close();

$total_records = count($gate_data);

// Calculate number of liquor columns for table structure
$liquor_columns_count = $mode == 'Country Liquor' ? count($display_sizes_country) + 1 : count($display_sizes_s) + count($display_sizes_w) + count($display_sizes_fb) + count($display_sizes_mb) + 1;

// Calculate liquor category totals
if ($mode == 'Country Liquor') {
    $liquor_totals = [
        'Country Liquor' => array_sum($liquor_summary['Country Liquor'])
    ];
} else {
    $liquor_totals = [
        'Spirits' => array_sum($liquor_summary['Spirits']),
        'Wines' => array_sum($liquor_summary['Wines']),
        'Fermented Beer' => array_sum($liquor_summary['Fermented Beer']),
        'Mild Beer' => array_sum($liquor_summary['Mild Beer'])
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gate Register (FLR-3) - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

  <style>
    /* Screen styles */
    body {
      font-size: 14px;
      background-color: #f8f9fa;
      font-family: Arial, sans-serif;
    }
    .company-header {
      text-align: center;
      margin-bottom: 20px;
      padding: 15px;
      border-bottom: 2px solid #000;
    }
    .company-header h1 {
      font-size: 20px;
      font-weight: bold;
      margin-bottom: 8px;
      text-transform: uppercase;
    }
    .company-header h5 {
      font-size: 16px;
      margin-bottom: 5px;
      font-weight: bold;
    }
    .company-header h6 {
      font-size: 14px;
      margin-bottom: 8px;
    }
    .report-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 20px;
      font-size: 13px;
      table-layout: fixed;
    }
    .report-table th, .report-table td {
      border: 1px solid #000;
      padding: 8px 6px;
      text-align: left;
      line-height: 1.3;
      vertical-align: top;
      word-wrap: break-word;
    }
    .report-table th {
      background-color: #e0e0e0;
      font-weight: bold;
      text-align: center;
      font-size: 12px;
    }
    .liquor-col {
      width: 40px;
      text-align: center;
      font-size: 11px;
      padding: 4px 2px;
    }
    .filter-card {
      background-color: #f8f9fa;
      margin-bottom: 20px;
    }
    .serial-col {
      width: 50px;
      text-align: center;
    }
    .billno-col {
      width: 80px;
      text-align: center;
    }
    .permitno-col {
      width: 80px;
      text-align: center;
    }
    .name-col {
      width: 150px;
    }
    .validity-col {
      width: 80px;
      text-align: center;
    }
    .district-col {
      width: 100px;
      text-align: center;
    }
    .summary-row {
      background-color: #d0d0d0;
      font-weight: bold;
      font-size: 14px;
    }
    /* Double line separators after each subcategory ends */
    /* Gate register structure: SrNo(1), BillNo(2), PermitNo(3), Name(4), Validity(5), District(6), Sizes[Spirits(11)+Wine(4)+FB(6)+MB(6)=27], Total(28) */

    /* After Spirits (50ml) - column 6+11=17 */
    .report-table td:nth-child(17) {
      border-right: double 3px #000;
    }
    /* After Wine (90ml) - column 17+4=21 */
    .report-table td:nth-child(21) {
      border-right: double 3px #000;
    }
    /* After Fermented Beer (250ml) - column 21+6=27 */
    .report-table td:nth-child(27) {
      border-right: double 3px #000;
    }
    /* After Mild Beer (250ml) - column 27+6=33 */
    .report-table td:nth-child(33) {
      border-right: double 3px #000;
    }
    .liquor-category {
      background-color: #f8f9fa;
      font-weight: bold;
    }
    .vertical-text {
      writing-mode: vertical-lr;
      transform: rotate(180deg);
      text-align: center;
      white-space: nowrap;
      padding: 8px 2px;
      font-size: 10px;
    }
    .no-print {
      display: block;
    }

    /* Print styles */
    @media print {
      @page {
        size: legal landscape;
        margin: 0.2in;
      }

      body {
        margin: 0;
        padding: 0;
        font-size: 8px;
        line-height: 1;
        background: white;
        width: 100%;
        height: 100%;
        transform: scale(0.8);
        transform-origin: 0 0;
        width: 125%;
      }

      .no-print {
        display: none !important;
      }

      body * {
        visibility: hidden;
      }

      .print-section, .print-section * {
        visibility: visible;
      }

      .print-section {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
      }

      .company-header {
        text-align: center;
        margin-bottom: 5px;
        padding: 2px;
        page-break-after: avoid;
      }

      .company-header h1 {
        font-size: 12px !important;
        margin-bottom: 1px !important;
      }

      .company-header h5 {
        font-size: 9px !important;
        margin-bottom: 1px !important;
      }

      .company-header h6 {
        font-size: 8px !important;
        margin-bottom: 2px !important;
      }

      .table-responsive {
        overflow: visible;
        width: 100%;
        height: auto;
      }

      .report-table {
        width: 100% !important;
        font-size: 6px !important;
        table-layout: fixed;
        border-collapse: collapse;
        page-break-inside: avoid;
      }

      .report-table th, .report-table td {
        padding: 1px !important;
        line-height: 1;
        height: 14px;
        min-width: 18px;
        max-width: 22px;
        font-size: 6px !important;
        border: 0.5px solid #000 !important;
      }

      .report-table th {
        background-color: #f0f0f0 !important;
        padding: 2px 1px !important;
        font-weight: bold;
      }

      .vertical-text {
        writing-mode: vertical-lr;
        transform: rotate(180deg);
        text-align: center;
        white-space: nowrap;
        padding: 1px !important;
        font-size: 5px !important;
        min-width: 15px;
        max-width: 18px;
        line-height: 1;
        height: 25px !important;
      }

      .serial-col, .billno-col, .permitno-col, .validity-col, .district-col {
        width: 25px !important;
        min-width: 25px !important;
        max-width: 25px !important;
      }

      .name-col {
        width: 60px !important;
        min-width: 60px !important;
        max-width: 60px !important;
      }

      .liquor-col {
        width: 18px !important;
        min-width: 18px !important;
        max-width: 18px !important;
        font-size: 5px !important;
      }

      .summary-row {
        background-color: #f8f9fa !important;
        font-weight: bold;
      }

      .footer-info {
        text-align: center;
        margin-top: 3px;
        font-size: 6px;
        page-break-before: avoid;
      }

      tr {
        page-break-inside: avoid;
        page-break-after: auto;
      }

      .alert {
        display: none !important;
      }
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Gate Register (FLR-3) Printing Module</h3>

      <!-- Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="filter-form">
            <div class="row">
              <div class="col-md-3">
                <label class="form-label">Mode:</label>
                <select name="mode" class="form-control">
                  <option value="Foreign Liquor" <?= $mode == 'Foreign Liquor' ? 'selected' : '' ?>>Foreign Liquor</option>
                  <option value="Country Liquor" <?= $mode == 'Country Liquor' ? 'selected' : '' ?>>Country Liquor</option>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label">Select Date:</label>
                <input type="date" name="selected_date" class="form-control" value="<?= htmlspecialchars($selected_date) ?>" max="<?= date('Y-m-d') ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label">Report Info:</label>
                <div class="form-control-plaintext">
                  <small class="text-muted">
                    Mode: <?= htmlspecialchars($mode) ?><br>
                    Date: <?= date('d-M-Y', strtotime($selected_date)) ?> | Records: <?= $total_records ?>
                  </small>
                </div>
              </div>
            </div>

            <div class="action-controls mt-3">
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
      <div class="print-section">
        <div class="company-header">
          <h1>GATE REGISTER (FLR-3)</h1>
          <h5>Mode: <?= htmlspecialchars($mode) ?></h5>
          <h6><?= htmlspecialchars($companyName) ?> (LIC. NO:<?= htmlspecialchars($licenseNo) ?>)</h6>
          <h6>Date: <?= date('d-M-Y', strtotime($selected_date)) ?></h6>
        </div>

        <?php if (empty($gate_data)): ?>
          <div class="alert alert-warning text-center no-print">
            <i class="fas fa-exclamation-triangle me-2"></i>
            No gate register data available for the selected date and mode.
          </div>
        <?php else: ?>
          <!-- Combined Gate Register and Liquor Summary Table -->
          <div class="table-responsive">
            <table class="report-table" id="gate-register-table">
              <thead>
                <tr>
                  <th class="serial-col">Sr No.</th>
                  <th class="billno-col">Bill No.</th>
                  <th class="permitno-col">Permit No.</th>
                  <th class="name-col">Permit Holder Name</th>
                  <th class="validity-col">Permit Validity</th>
                  <th class="district-col">Permit District</th>
                  <?php if ($mode == 'Country Liquor'): ?>
                    <?php foreach ($display_sizes_country as $size): ?>
                      <th class="liquor-col vertical-text"><?= $size ?></th>
                    <?php endforeach; ?>
                    <th class="liquor-col">Total</th>
                  <?php else: ?>
                    <?php foreach ($display_sizes_s as $size): ?>
                      <th class="liquor-col vertical-text">S: <?= $size ?></th>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_w as $size): ?>
                      <th class="liquor-col vertical-text">W: <?= $size ?></th>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <th class="liquor-col vertical-text">FB: <?= $size ?></th>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <th class="liquor-col vertical-text">MB: <?= $size ?></th>
                    <?php endforeach; ?>
                    <th class="liquor-col">Total</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($gate_data as $entry): ?>
                  <tr>
                    <td class="serial-col"><?= $entry['serial_no'] ?></td>
                    <td class="billno-col"><?= htmlspecialchars($entry['bill_no']) ?></td>
                    <td class="permitno-col"><?= htmlspecialchars($entry['permit_no']) ?></td>
                    <td class="name-col"><?= htmlspecialchars($entry['permit_holder_name']) ?></td>
                    <td class="validity-col"><?= htmlspecialchars($entry['permit_validity']) ?></td>
                    <td class="district-col"><?= htmlspecialchars($entry['permit_district']) ?></td>
                    <?php
                    // Initialize liquor quantities for this entry
                    $entry_liquor_summary = [];
                    if ($mode == 'Country Liquor') {
                      $entry_liquor_summary['Country Liquor'] = array_fill_keys($display_sizes_country, 0);
                    } else {
                      $entry_liquor_summary['Spirits'] = array_fill_keys($display_sizes_s, 0);
                      $entry_liquor_summary['Wines'] = array_fill_keys($display_sizes_w, 0);
                      $entry_liquor_summary['Fermented Beer'] = array_fill_keys($display_sizes_fb, 0);
                      $entry_liquor_summary['Mild Beer'] = array_fill_keys($display_sizes_mb, 0);
                    }

                    // Get items for this specific bill
                    $bill_items = [];
                    $billQuery = "SELECT items_json FROM tbl_cash_memo_prints WHERE bill_no = ? AND comp_id = ?";
                    $billStmt = $conn->prepare($billQuery);
                    $billStmt->bind_param("si", $entry['bill_no'], $compID);
                    $billStmt->execute();
                    $billResult = $billStmt->get_result();
                    if ($billRow = $billResult->fetch_assoc()) {
                      $bill_items = json_decode($billRow['items_json'], true);
                    }
                    $billStmt->close();

                    // Process items for this bill
                    if (is_array($bill_items)) {
                      foreach ($bill_items as $item) {
                        $baseSize = getBaseSize($item['DETAILS2']);
                        $qty = floatval($item['QTY']);

                        if ($mode == 'Country Liquor') {
                          if (isset($entry_liquor_summary['Country Liquor'][$baseSize])) {
                            $entry_liquor_summary['Country Liquor'][$baseSize] += $qty;
                          }
                        } else {
                          $liquor_type = 'Spirits';
                          $item_name = strtolower($item['DETAILS']);
                          if (strpos($item_name, 'beer') !== false) {
                            if (strpos($item_name, 'mild') !== false) {
                              $liquor_type = 'Mild Beer';
                            } else {
                              $liquor_type = 'Fermented Beer';
                            }
                          } elseif (strpos($item_name, 'wine') !== false) {
                            $liquor_type = 'Wines';
                          }

                          if (isset($entry_liquor_summary[$liquor_type][$baseSize])) {
                            $entry_liquor_summary[$liquor_type][$baseSize] += $qty;
                          }
                        }
                      }
                    }

                    // Display liquor quantities for this entry
                    if ($mode == 'Country Liquor') {
                      foreach ($display_sizes_country as $size) {
                        echo '<td class="liquor-col">' . ($entry_liquor_summary['Country Liquor'][$size] > 0 ? $entry_liquor_summary['Country Liquor'][$size] : '') . '</td>';
                      }
                      echo '<td class="liquor-col liquor-category">' . array_sum($entry_liquor_summary['Country Liquor']) . '</td>';
                    } else {
                      foreach ($display_sizes_s as $size) {
                        echo '<td class="liquor-col">' . ($entry_liquor_summary['Spirits'][$size] > 0 ? $entry_liquor_summary['Spirits'][$size] : '') . '</td>';
                      }
                      foreach ($display_sizes_w as $size) {
                        echo '<td class="liquor-col">' . ($entry_liquor_summary['Wines'][$size] > 0 ? $entry_liquor_summary['Wines'][$size] : '') . '</td>';
                      }
                      foreach ($display_sizes_fb as $size) {
                        echo '<td class="liquor-col">' . ($entry_liquor_summary['Fermented Beer'][$size] > 0 ? $entry_liquor_summary['Fermented Beer'][$size] : '') . '</td>';
                      }
                      foreach ($display_sizes_mb as $size) {
                        echo '<td class="liquor-col">' . ($entry_liquor_summary['Mild Beer'][$size] > 0 ? $entry_liquor_summary['Mild Beer'][$size] : '') . '</td>';
                      }
                      $entry_total = array_sum($entry_liquor_summary['Spirits']) + array_sum($entry_liquor_summary['Wines']) + array_sum($entry_liquor_summary['Fermented Beer']) + array_sum($entry_liquor_summary['Mild Beer']);
                      echo '<td class="liquor-col liquor-category">' . $entry_total . '</td>';
                    }
                    ?>
                  </tr>
                <?php endforeach; ?>

                <!-- Summary Row -->
                <tr class="summary-row">
                  <td colspan="6" class="text-center">
                    <strong>Total Records: <?= $total_records ?> | Date: <?= date('d-M-Y', strtotime($selected_date)) ?> | Mode: <?= htmlspecialchars($mode) ?></strong>
                  </td>
                  <?php if ($mode == 'Country Liquor'): ?>
                    <?php foreach ($display_sizes_country as $size): ?>
                      <td class="liquor-col liquor-category"><?= $liquor_summary['Country Liquor'][$size] > 0 ? $liquor_summary['Country Liquor'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    <td class="liquor-col liquor-category"><strong><?= $liquor_totals['Country Liquor'] ?></strong></td>
                  <?php else: ?>
                    <?php foreach ($display_sizes_s as $size): ?>
                      <td class="liquor-col liquor-category"><?= $liquor_summary['Spirits'][$size] > 0 ? $liquor_summary['Spirits'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_w as $size): ?>
                      <td class="liquor-col liquor-category"><?= $liquor_summary['Wines'][$size] > 0 ? $liquor_summary['Wines'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_fb as $size): ?>
                      <td class="liquor-col liquor-category"><?= $liquor_summary['Fermented Beer'][$size] > 0 ? $liquor_summary['Fermented Beer'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    <?php foreach ($display_sizes_mb as $size): ?>
                      <td class="liquor-col liquor-category"><?= $liquor_summary['Mild Beer'][$size] > 0 ? $liquor_summary['Mild Beer'][$size] : '' ?></td>
                    <?php endforeach; ?>
                    <td class="liquor-col liquor-category"><strong><?= array_sum($liquor_totals) ?></strong></td>
                  <?php endif; ?>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="footer-info">
            <p>Generated on: <?= date('d-M-Y h:i A') ?></p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>