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

// Default values
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'detailed';
$counter = isset($_GET['counter']) ? $_GET['counter'] : 'all';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$rate_type = isset($_GET['rate_type']) ? $_GET['rate_type'] : 'mrp';
$sale_type = isset($_GET['sale_type']) ? $_GET['sale_type'] : 'total';
$sequence = isset($_GET['sequence']) ? $_GET['sequence'] : 'system';

// Fetch company name
$companyName = "DIAMOND WINE SHOP"; // Default name
$companyQuery = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$companyStmt = $conn->prepare($companyQuery);
$companyStmt->bind_param("i", $compID);
$companyStmt->execute();
$companyResult = $companyStmt->get_result();
if ($row = $companyResult->fetch_assoc()) {
    $companyName = $row['COMP_NAME'];
}
$companyStmt->close();

// Fetch counters (users) from users table - only those associated with the current company
$counters = [];
$counterQuery = "SELECT id, username 
                 FROM users 
                 WHERE company_id = ?
                 ORDER BY username";
$counterStmt = $conn->prepare($counterQuery);
$counterStmt->bind_param("i", $compID);
$counterStmt->execute();
$counterResult = $counterStmt->get_result();
while ($row = $counterResult->fetch_assoc()) {
    $counters[$row['id']] = $row['username'];
}
$counterStmt->close();

// Generate report data based on filters
$report_data = [];
$total_amount = 0;

if (isset($_GET['generate'])) {
    // For total sales, we need to combine data from both retail and customer sales
    if ($sale_type === 'total') {
        // Get retail sales data
        $retail_data = getSalesData('retail', $report_type, $counter, $date_from, $date_to, $rate_type, $sequence, $compID, $conn);
        $retail_total = calculateTotalAmount('retail', $counter, $date_from, $date_to, $rate_type, $compID, $conn);
        
        // Get customer sales data
        $customer_data = getSalesData('customer', $report_type, $counter, $date_from, $date_to, $rate_type, $sequence, $compID, $conn);
        $customer_total = calculateTotalAmount('customer', $counter, $date_from, $date_to, $rate_type, $compID, $conn);
        
        // Combine the data
        $report_data = array_merge($retail_data, $customer_data);
        $total_amount = $retail_total + $customer_total;
    } else {
        // For retail or customer sales only
        $report_data = getSalesData($sale_type, $report_type, $counter, $date_from, $date_to, $rate_type, $sequence, $compID, $conn);
        $total_amount = calculateTotalAmount($sale_type, $counter, $date_from, $date_to, $rate_type, $compID, $conn);
    }
}

// Function to get sales data based on type and parameters
function getSalesData($sale_type, $report_type, $counter, $date_from, $date_to, $rate_type, $sequence, $compID, $conn) {
    if ($sale_type === 'retail') {
        $table_name = "tblsaledetails";
        $header_table = "tblsaleheader";
        $bill_no_field = "BILL_NO";
        $date_field = "BILL_DATE";
        $amount_field = "AMOUNT";
        $rate_field = "RATE";
        $qty_field = "QTY";
        $item_code_field = "ITEM_CODE";
        $comp_id_field = "COMP_ID";
        
        // For retail sales, we need to join with itemmaster to get proper names and prices
        $item_join = "LEFT JOIN tblitemmaster im ON sd.$item_code_field = im.CODE
                      LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP";
        $item_name_field = "COALESCE(im.DETAILS, 'Unknown Item')";
        $item_size_field = "COALESCE(im.DETAILS2, '')";
        $item_group_field = "im.ITEM_GROUP";
        $cc_field = "COALESCE(sc.CC, 0)";
        
        // Select rate based on rate_type
        if ($rate_type === 'brate') {
            $rate_select = "COALESCE(im.BPRICE, sd.$rate_field)";
        } else {
            $rate_select = "COALESCE(im.MPRICE, sd.$rate_field)";
        }
        
        // Build the query based on selected filters
        if ($report_type === 'detailed') {
            $query = "SELECT 
                        sh.$date_field as DATE, 
                        sd.$bill_no_field as BillNo, 
                        $item_name_field as ItemName, 
                        $item_size_field as ItemSize, 
                        $item_group_field as ItemGroup,
                        $cc_field as CC,
                        $rate_select as Rate, 
                        sd.$qty_field as Quantity, 
                        ($rate_select * sd.$qty_field) as Amount,
                        sh.CUST_CODE as Customer_Code,
                        'Retail Sale' as Sale_Type,
                        u.username as Counter_Name
                    FROM $table_name sd
                    $item_join
                    INNER JOIN $header_table sh ON sd.$bill_no_field = sh.$bill_no_field AND sd.$comp_id_field = sh.COMP_ID
                    LEFT JOIN users u ON sh.CREATED_BY = u.id
                    WHERE sh.$date_field BETWEEN ? AND ? AND sd.$comp_id_field = ?";
        } else {
            // Summary report - group by item and size
            $query = "SELECT 
                        $item_name_field as ItemName, 
                        $item_size_field as ItemSize, 
                        $item_group_field as ItemGroup,
                        $cc_field as CC,
                        SUM(sd.$qty_field) as TotalQty,
                        SUM($rate_select * sd.$qty_field) as TotalAmount,
                        u.username as Counter_Name
                    FROM $table_name sd
                    $item_join
                    INNER JOIN $header_table sh ON sd.$bill_no_field = sh.$bill_no_field AND sd.$comp_id_field = sh.COMP_ID
                    LEFT JOIN users u ON sh.CREATED_BY = u.id
                    WHERE sh.$date_field BETWEEN ? AND ? AND sd.$comp_id_field = ?";
        }
    } else {
        // Customer sale
        $table_name = "tblcustomersales";
        $bill_no_field = "BillNo";
        $date_field = "BillDate";
        $amount_field = "Amount";
        $rate_field = "Rate";
        $qty_field = "Quantity";
        $item_code_field = "ItemCode";
        $comp_id_field = "CompID";
        $item_name_field = "ItemName";
        $item_size_field = "ItemSize";
        $item_join = "LEFT JOIN tblitemmaster im ON cs.$item_code_field = im.CODE
                      LEFT JOIN tblsubclass sc ON im.ITEM_GROUP = sc.ITEM_GROUP";
        $item_group_field = "COALESCE(im.ITEM_GROUP, '')";
        $cc_field = "COALESCE(sc.CC, 0)";
        
        // For customer sales, use prices from itemmaster instead of billed rates
        if ($rate_type === 'brate') {
            $rate_select = "COALESCE(im.BPRICE, cs.$rate_field)";
        } else {
            $rate_select = "COALESCE(im.MPRICE, cs.$rate_field)";
        }
        
        // Build the query based on selected filters
        if ($report_type === 'detailed') {
            $query = "SELECT 
                        cs.$date_field as DATE, 
                        cs.$bill_no_field as BillNo, 
                        cs.$item_name_field as ItemName, 
                        cs.$item_size_field as ItemSize, 
                        $item_group_field as ItemGroup,
                        $cc_field as CC,
                        $rate_select as Rate, 
                        cs.$qty_field as Quantity, 
                        ($rate_select * cs.$qty_field) as Amount,
                        lh.LHEAD as Customer_Name,
                        'Customer Sale' as Sale_Type,
                        u.username as Counter_Name
                    FROM $table_name cs
                    $item_join
                    INNER JOIN tbllheads lh ON cs.LCode = lh.LCODE
                    LEFT JOIN users u ON cs.UserID = u.id
                    WHERE cs.$date_field BETWEEN ? AND ? AND cs.$comp_id_field = ?";
        } else {
            // Summary report - group by item and size
            $query = "SELECT 
                        cs.$item_name_field as ItemName, 
                        cs.$item_size_field as ItemSize, 
                        $item_group_field as ItemGroup,
                        $cc_field as CC,
                        SUM(cs.$qty_field) as TotalQty,
                        SUM($rate_select * cs.$qty_field) as TotalAmount,
                        u.username as Counter_Name
                    FROM $table_name cs
                    $item_join
                    LEFT JOIN users u ON cs.UserID = u.id
                    WHERE cs.$date_field BETWEEN ? AND ? AND cs.$comp_id_field = ?";
        }
    }
    
    $params = [$date_from, $date_to, $compID];
    $types = "ssi";
    
    // Add counter filter if not 'all'
    if ($counter !== 'all') {
        if ($sale_type === 'retail') {
            $query .= " AND sh.CREATED_BY = ?";
        } else {
            $query .= " AND cs.UserID = ?";
        }
        $params[] = $counter;
        $types .= "i";
    }
    
    if ($report_type === 'detailed') {
        if ($sequence === 'system') {
            if ($sale_type === 'retail') {
                $query .= " ORDER BY sh.$date_field, sd.$bill_no_field";
            } else {
                $query .= " ORDER BY cs.$date_field, cs.$bill_no_field";
            }
        } else {
            $query .= " ORDER BY ItemName";
        }
    } else {
        // Fix the GROUP BY clause for summary report
        if ($sale_type === 'retail') {
            $query .= " GROUP BY im.DETAILS, im.DETAILS2, im.ITEM_GROUP, sc.CC, u.username";
        } else {
            $query .= " GROUP BY cs.ItemName, cs.ItemSize, im.ITEM_GROUP, sc.CC, u.username";
        }
        $query .= " ORDER BY ItemName";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $data;
}

// Function to calculate total amount
function calculateTotalAmount($sale_type, $counter, $date_from, $date_to, $rate_type, $compID, $conn) {
    if ($sale_type === 'retail') {
        $table_name = "tblsaledetails";
        $header_table = "tblsaleheader";
        $bill_no_field = "BILL_NO";
        $date_field = "BILL_DATE";
        $qty_field = "QTY";
        $item_code_field = "ITEM_CODE";
        $comp_id_field = "COMP_ID";
        
        $item_join = "LEFT JOIN tblitemmaster im ON sd.$item_code_field = im.CODE";
        
        // Select rate based on rate_type
        if ($rate_type === 'brate') {
            $rate_select = "COALESCE(im.BPRICE, sd.RATE)";
        } else {
            $rate_select = "COALESCE(im.MPRICE, sd.RATE)";
        }
        
        $query = "SELECT SUM($rate_select * sd.$qty_field) as Total_Amount 
                  FROM $table_name sd
                  $item_join
                  INNER JOIN $header_table sh ON sd.$bill_no_field = sh.$bill_no_field AND sd.$comp_id_field = sh.COMP_ID
                  WHERE sh.$date_field BETWEEN ? AND ? AND sd.$comp_id_field = ?";
        
        $params = [$date_from, $date_to, $compID];
        $types = "ssi";
        
        // Add counter filter if not 'all'
        if ($counter !== 'all') {
            $query .= " AND sh.CREATED_BY = ?";
            $params[] = $counter;
            $types .= "i";
        }
    } else {
        // Customer sale
        $table_name = "tblcustomersales";
        $date_field = "BillDate";
        $qty_field = "Quantity";
        $item_code_field = "ItemCode";
        $comp_id_field = "CompID";
        
        $item_join = "LEFT JOIN tblitemmaster im ON cs.$item_code_field = im.CODE";
        
        // Select rate based on rate_type
        if ($rate_type === 'brate') {
            $rate_select = "COALESCE(im.BPRICE, cs.Rate)";
        } else {
            $rate_select = "COALESCE(im.MPRICE, cs.Rate)";
        }
        
        $query = "SELECT SUM($rate_select * cs.$qty_field) as Total_Amount 
                  FROM $table_name cs
                  $item_join
                  WHERE cs.$date_field BETWEEN ? AND ? AND cs.$comp_id_field = ?";
        
        $params = [$date_from, $date_to, $compID];
        $types = "ssi";
        
        // Add counter filter if not 'all'
        if ($counter !== 'all') {
            $query .= " AND cs.UserID = ?";
            $params[] = $counter;
            $types .= "i";
        }
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $total = $row['Total_Amount'] ?? 0;
    $stmt->close();
    
    return $total;
}

// Define size categories for summary report based on CC values from tblsubclass
$size_categories = [
    '4.5 L' => 4500,
    '3 L' => 3000, 
    '2 L' => 2000,
    '1 Ltr' => 1000,
    '750 ML' => 750,
    '860 ML' => 860,
    '800 ML' => 800,
    '375 ML' => 375,
    '330 ML' => 330,
    '325 ML' => 325,
    '180 ML' => 180,
    '90 ML' => 90,
    '60 ML' => 60
];

// Function to categorize item by CC value
function categorizeByCC($cc) {
    global $size_categories;
    
    $cc = (int)$cc;
    if ($cc === 0) return 'Other';
    
    // Find the closest matching size category
    foreach ($size_categories as $size_name => $size_cc) {
        if ($cc === $size_cc) {
            return $size_name;
        }
    }
    
    // If no exact match, find the closest
    $closest = null;
    $min_diff = PHP_INT_MAX;
    
    foreach ($size_categories as $size_name => $size_cc) {
        $diff = abs($cc - $size_cc);
        if ($diff < $min_diff) {
            $min_diff = $diff;
            $closest = $size_name;
        }
    }
    
    return $closest ?? 'Other';
}

// Function to get subclass description
function getSubclassDescription($item_group) {
    global $conn;
    
    if (empty($item_group)) return '';
    
    $query = "SELECT `DESC` FROM tblsubclass WHERE ITEM_GROUP = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $item_group);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        return $row['DESC'];
    }
    
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Total Sales Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
  
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Total Sales Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-2">
                <label class="form-label">Counters:</label>
                <select name="counter" class="form-select">
                  <option value="all" <?= $counter === 'all' ? 'selected' : '' ?>>All Counters</option>
                  <?php foreach ($counters as $id => $username): ?>
                    <option value="<?= $id ?>" <?= $counter == $id ? 'selected' : '' ?>>
                      <?= htmlspecialchars($username) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Date From:</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Date To:</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
              </div>
              <div class="col-md-2">
                <label class="form-label">Rates:</label>
                <select name="rate_type" class="form-select">
                  <option value="mrp" <?= $rate_type === 'mrp' ? 'selected' : '' ?>>MRP Rate</option>
                  <option value="brate" <?= $rate_type === 'brate' ? 'selected' : ' ' ?>>B. Rate</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Mode:</label>
                <select name="sale_type" class="form-select">
                  <option value="total" <?= $sale_type === 'total' ? 'selected' : '' ?>>Total Sale</option>
                  <option value="retail" <?= $sale_type === 'retail' ? 'selected' : '' ?>>Retail Sale</option>
                  <option value="customer" <?= $sale_type === 'customer' ? 'selected' : '' ?>>Customer Sale</option>
                </select>
              </div>
              <div class="col-md-2">
                <label class="form-label">Report:</label>
                <select name="report_type" class="form-select">
                  <option value="detailed" <?= $report_type === 'detailed' ? 'selected' : '' ?>>Detailed</option>
                  <option value="summary" <?= $report_type === 'summary' ? 'selected' : '' ?>>Summary</option>
                </select>
              </div>
            </div>
            <div class="row mb-3">
              <div class="col-md-2">
                <label class="form-label">Sequence:</label>
                <select name="sequence" class="form-select">
                  <option value="system" <?= $sequence === 'system' ? 'selected' : '' ?>>System Defined</option>
                  <option value="user" <?= $sequence === 'user' ? 'selected' : '' ?>>User Defined</option>
                </select>
              </div>
            </div>
            
            <div class="action-controls">
              <button type="submit" name="generate" class="btn btn-primary">
                <i class="fas fa-cog me-1"></i> Generate
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
      <?php if (!empty($report_data)): ?>
        <div class="print-section">
          <div class="company-header">
            <h1><?= htmlspecialchars($companyName) ?></h1>
            <h5>Total Sales Report From <?= date('d-M-Y', strtotime($date_from)) ?> To <?= date('d-M-Y', strtotime($date_to)) ?></h5>
            <h6>Counter: <?= $counter === 'all' ? 'All Counters' : htmlspecialchars($counters[$counter] ?? 'Unknown') ?></h6>
            <h6>Rate Type: <?= $rate_type === 'mrp' ? 'MRP Rate' : 'B. Rate' ?></h6>
            <h6>Sale Type: <?= 
                $sale_type === 'retail' ? 'Retail Sale' : 
                ($sale_type === 'customer' ? 'Customer Sale' : 'Total Sale (Retail + Customer)')
            ?></h6>
          </div>
          
          <div class="table-container">
            <?php if ($report_type === 'detailed'): ?>
              <table class="report-table">
                <thead>
                  <tr>
                    <th>S. No</th>
                    <th>Item Description</th>
                    <th>Rate</th>
                    <th>Qty.</th>
                    <th>Tot. Amt.</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $sno = 1;
                  $current_bill = 0;
                  foreach ($report_data as $row): 
                    if ($current_bill != $row['BillNo']):
                      $current_bill = $row['BillNo'];
                  ?>
                    <tr class="bill-header">
                      <td colspan="5">
                        <strong>Bill No:</strong> <?= htmlspecialchars($row['BillNo']) ?> | 
                        <strong>Date:</strong> <?= date('d/m/Y', strtotime($row['DATE'])) ?> | 
                        <strong>Customer:</strong> <?= htmlspecialchars($row['Customer_Name'] ?? $row['Customer_Code'] ?? 'N/A') ?>
                        <?php if (isset($row['Sale_Type'])): ?>
                          | <span class="sale-type-badge"><?= htmlspecialchars($row['Sale_Type']) ?></span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endif; ?>
                  
                  <tr>
                    <td><?= $sno++ ?></td>
                    <td><?= htmlspecialchars($row['ItemName']) ?> <?= !empty($row['ItemSize']) ? '(' . htmlspecialchars($row['ItemSize']) . ')' : '' ?></td>
                    <td class="text-right"><?= number_format($row['Rate'], 2) ?></td>
                    <td class="text-right"><?= htmlspecialchars($row['Quantity']) ?></td>
                    <td class="text-right"><?= number_format($row['Amount'], 2) ?></td>
                  </tr>
                  <?php endforeach; ?>
                  
                  <tr class="total-row">
                    <td colspan="4" class="text-end"><strong>Total Amount :</strong></td>
                    <td class="text-right"><strong><?= number_format($total_amount, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
            <?php else: ?>
              <!-- Summary Report -->
              <table class="report-table">
                <thead>
                  <tr>
                    <th>Item Description</th>
                    <?php foreach (array_keys($size_categories) as $size_name): ?>
                      <th class="size-column"><?= $size_name ?></th>
                    <?php endforeach; ?>
                    <th>Total</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  // Group items by name and subclass for summary report
                  $grouped_items = [];
                  foreach ($report_data as $row) {
                      $item_name = $row['ItemName'];
                      $item_group = $row['ItemGroup'] ?? '';
                      $cc = $row['CC'] ?? 0;
                      
                      $size_category = categorizeByCC($cc);
                      
                      if (!isset($grouped_items[$item_group])) {
                          $grouped_items[$item_group] = [
                              'name' => getSubclassDescription($item_group),
                              'items' => []
                          ];
                      }
                      
                      if (!isset($grouped_items[$item_group]['items'][$item_name])) {
                          $grouped_items[$item_group]['items'][$item_name] = array_fill_keys(array_keys($size_categories), '');
                          $grouped_items[$item_group]['items'][$item_name]['Total'] = 0;
                      }
                      
                      if (in_array($size_category, array_keys($size_categories))) {
                          $grouped_items[$item_group]['items'][$item_name][$size_category] = $row['TotalQty'];
                      }
                      
                      $grouped_items[$item_group]['items'][$item_name]['Total'] += $row['TotalAmount'];
                  }
                  
                  // Display grouped items
                  foreach ($grouped_items as $item_group => $group_data):
                    if (!empty($group_data['name'])):
                  ?>
                    <tr class="subclass-header">
                      <td colspan="<?= count($size_categories) + 2 ?>"><?= htmlspecialchars($group_data['name']) ?></td>
                    </tr>
                  <?php endif; ?>
                  
                  <?php foreach ($group_data['items'] as $item_name => $sizes): ?>
                    <tr>
                      <td><?= htmlspecialchars($item_name) ?></td>
                      <?php foreach (array_keys($size_categories) as $size_name): ?>
                        <td class="text-right"><?= $sizes[$size_name] ? htmlspecialchars($sizes[$size_name]) : '' ?></td>
                      <?php endforeach; ?>
                      <td class="text-right"><?= number_format($sizes['Total'], 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  
                  <?php endforeach; ?>
                  
                  <tr class="total-row">
                    <td><strong>Total</strong></td>
                    <?php foreach (array_keys($size_categories) as $size_name): ?>
                      <td class="text-right"></td>
                    <?php endforeach; ?>
                    <td class="text-right"><strong><?= number_format($total_amount, 2) ?></strong></td>
                  </tr>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
          
          <div class="footer-info">
            Generated on: <?= date('d-M-Y h:i A') ?> | Generated by: <?= $_SESSION['username'] ?? 'System' ?>
          </div>
        </div>
      <?php elseif (isset($_GET['generate'])): ?>
        <div class="alert alert-info">
          <i class="fas fa-info-circle me-2"></i> No sales records found for the selected criteria.
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