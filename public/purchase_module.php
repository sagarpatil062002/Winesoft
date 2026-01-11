<?php
session_start();

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'ALL'; // Changed default to ALL

// Enable debug logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'purchase_module_debug.log');

// Log session and initial data
error_log("=== PURCHASE MODULE DEBUG START ===");
error_log("Company ID: " . $companyId);
error_log("Mode: " . $mode);
error_log("Session CompID: " . ($_SESSION['CompID'] ?? 'NOT SET'));
error_log("Session User ID: " . ($_SESSION['user_id'] ?? 'NOT SET'));

include_once "../config/db.php";

// Check database connection
if (!$conn) {
    error_log("DATABASE CONNECTION FAILED");
    $dbError = "Database connection failed";
} else {
    error_log("Database connection successful");
}

// Handle success message
$success = isset($_GET['success']) ? $_GET['success'] : 0;
$import_success = isset($_GET['import_success']) ? $_GET['import_success'] : 0;
$import_error = isset($_GET['import_error']) ? $_GET['import_error'] : '';

// Build query with filters
$whereConditions = ["p.CompID = ?"];
$params = [$companyId];
$paramTypes = "i";

// Log filter parameters
error_log("Initial filters - Company: $companyId, Mode: $mode");

// Apply filters if they exist
if (isset($_GET['from_date']) && !empty($_GET['from_date'])) {
    $whereConditions[] = "p.DATE >= ?";
    $params[] = $_GET['from_date'];
    $paramTypes .= "s";
    error_log("From Date filter: " . $_GET['from_date']);
}

if (isset($_GET['to_date']) && !empty($_GET['to_date'])) {
    $whereConditions[] = "p.DATE <= ?";
    $params[] = $_GET['to_date'];
    $paramTypes .= "s";
    error_log("To Date filter: " . $_GET['to_date']);
}

if (isset($_GET['voc_no']) && !empty($_GET['voc_no'])) {
    $whereConditions[] = "p.VOC_NO LIKE ?";
    $params[] = '%' . $_GET['voc_no'] . '%';
    $paramTypes .= "s";
    error_log("VOC No filter: " . $_GET['voc_no']);
}

if (isset($_GET['supplier']) && !empty($_GET['supplier'])) {
    $whereConditions[] = "s.DETAILS LIKE ?";
    $params[] = '%' . $_GET['supplier'] . '%';
    $paramTypes .= "s";
    error_log("Supplier filter: " . $_GET['supplier']);
}

if (isset($_GET['tp_no']) && !empty($_GET['tp_no'])) {
    $whereConditions[] = "COALESCE(p.TPNO, p.AUTO_TPNO) LIKE ?";
    $params[] = '%' . $_GET['tp_no'] . '%';
    $paramTypes .= "s";
    error_log("TP No filter: " . $_GET['tp_no']);
}

// Handle sorting
$sortColumn = isset($_GET['sort']) ? $_GET['sort'] : 'p.VOC_NO';
$sortOrder = isset($_GET['order']) ? $_GET['order'] : 'DESC';

// Validate sort column to prevent SQL injection
$allowedColumns = ['p.VOC_NO', 'p.DATE', 'TP_NO', 'p.INV_NO', 'p.INV_DATE', 's.DETAILS', 'p.TAMT', 'p.PUR_FLAG'];
if (!in_array($sortColumn, $allowedColumns)) {
    $sortColumn = 'p.VOC_NO';
}

// Validate sort order
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

// Get all purchases for this company with filters and sorting
$purchases = [];
$purchaseQuery = "SELECT p.*, s.DETAILS as supplier_name,
               COALESCE(p.TPNO, p.AUTO_TPNO) as TP_NO
               FROM tblpurchases p
               LEFT JOIN tblsupplier s ON TRIM(p.SUBCODE) = TRIM(s.CODE)
               WHERE " . implode(" AND ", $whereConditions) . "
               ORDER BY $sortColumn $sortOrder";
                  
error_log("Final Query: " . $purchaseQuery);
error_log("Parameters: " . print_r($params, true));
error_log("Parameter Types: " . $paramTypes);
error_log("Sort Column: $sortColumn, Sort Order: $sortOrder");

// Query execution with error handling
$purchaseStmt = $conn->prepare($purchaseQuery);
if (!$purchaseStmt) {
    error_log("QUERY PREPARE FAILED: " . $conn->error);
    $queryError = "Query preparation failed: " . $conn->error;
} else {
    // Only bind parameters if we have them
    if (!empty($params)) {
        $bindResult = $purchaseStmt->bind_param($paramTypes, ...$params);
        if (!$bindResult) {
            error_log("PARAMETER BINDING FAILED: " . $purchaseStmt->error);
        }
    }
    
    if (!$purchaseStmt->execute()) {
        error_log("QUERY EXECUTE FAILED: " . $purchaseStmt->error);
        $executeError = "Query execution failed: " . $purchaseStmt->error;
    } else {
        $purchaseResult = $purchaseStmt->get_result();
        if ($purchaseResult) {
            $purchases = $purchaseResult->fetch_all(MYSQLI_ASSOC);
            error_log("Found " . count($purchases) . " purchase records");
        } else {
            error_log("GET RESULT FAILED: " . $purchaseStmt->error);
        }
    }
    $purchaseStmt->close();
}

error_log("=== PURCHASE MODULE DEBUG END ===");

// Function to generate sort link
function getSortLink($column, $label) {
    global $sortColumn, $sortOrder;
    $newOrder = 'ASC';
    
    if ($sortColumn === $column) {
        $newOrder = $sortOrder === 'ASC' ? 'DESC' : 'ASC';
    }
    
    // Get current URL parameters
    $params = $_GET;
    $params['sort'] = $column;
    $params['order'] = $newOrder;
    
    $queryString = http_build_query($params);
    $sortIcon = '';
    
    if ($sortColumn === $column) {
        $sortIcon = $sortOrder === 'ASC' ? ' <i class="fas fa-sort-up"></i>' : ' <i class="fas fa-sort-down"></i>';
    } else {
        $sortIcon = ' <i class="fas fa-sort"></i>';
    }
    
    return '<a href="?' . $queryString . '" class="text-decoration-none text-dark">' . $label . $sortIcon . '</a>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Purchase Module - All Purchases</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
<style>
  /* Remove table container scrolling for single line display */
  .table-container {
    overflow-x: auto;
    max-height: none;
    min-height: 520px;
  }
  
  table.styled-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 16px; /* Increased font size for better visibility */
  }

  .styled-table th,
  .styled-table td {
    border: 1px solid #e5e7eb;
    padding: 8px 12px; /* Increased padding */
    white-space: nowrap; /* Prevent text wrapping */
  }

  .styled-table thead th {
    position: sticky;
    top: 0;
    background: #f8fafc;
    z-index: 1;
    font-size: 14px; /* Increased font size for headers */
    padding: 6px 10px;
    cursor: pointer;
    user-select: none;
  }
  
  .styled-table thead th:hover {
    background: #e9ecef;
  }
  
  .styled-table thead th a {
    display: block;
    width: 100%;
  }
  
  .action-buttons {
    display: flex;
    gap: 3px;
    flex-wrap: nowrap;
  }

  .action-buttons .btn {
    padding: 4px 8px;
    font-size: 12px;
  }

  .status-badge {
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    white-space: nowrap;
  }
  
  .status-completed { background: #d1fae5; color: #065f46; }
  .status-unpaid { background: #fef3c7; color: #92400e; }
  .status-partial { background: #dbeafe; color: #1e40af; }
  
  /* Purchase Summary Table Styles with >1L grouping */
  #purchaseSummaryTable {
    width: auto;
    min-width: 100%;
    table-layout: fixed;
    font-size: 9px;
  }
  
  #purchaseSummaryTable th {
    font-size: 8px;
    padding: 2px 3px;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: middle;
    background-color: #f8f9fa;
  }

  #purchaseSummaryTable td {
    font-size: 8px;
    padding: 2px 3px;
    text-align: center;
    white-space: nowrap;
    overflow: hidden;
    vertical-align: middle;
    border: 1px solid #dee2e6;
  }
  
  /* Adjust column widths for >1L grouping */
  #purchaseSummaryTable th.fixed-column,
  #purchaseSummaryTable td.fixed-column {
    width: 70px;
    min-width: 70px;
    max-width: 70px;
    position: sticky;
    left: 0;
    background-color: white;
    z-index: 3;
    border-right: 2px solid #dee2e6;
  }
  
  /* Size columns width */
  #purchaseSummaryTable th.size-column,
  #purchaseSummaryTable td.size-column {
    width: 35px;
    min-width: 35px;
    max-width: 35px;
  }
  
  /* >1L column slightly wider */
  #purchaseSummaryTable th.size-column:first-child,
  #purchaseSummaryTable td.size-column:first-child {
    width: 40px;
    min-width: 40px;
    max-width: 40px;
    background-color: #e3f2fd;
  }
  
  /* Modal adjustments */
  #purchaseSummaryModal .modal-dialog {
    max-width: 98vw;
    margin: 5px auto;
    width: auto;
  }
  
  #purchaseSummaryModal .modal-content {
    max-height: 95vh;
    overflow: hidden;
  }
  
  #purchaseSummaryModal .modal-body {
    padding: 10px;
    overflow: hidden;
  }
  
  /* Make the summary table container horizontally scrollable */
  #purchaseSummaryModal .table-responsive {
    max-height: 70vh;
    overflow-y: auto;
    overflow-x: auto;
    border: 1px solid #dee2e6;
    border-radius: 4px;
  }
  
  .summary-header-group th {
    background-color: #e9ecef !important;
    font-weight: bold;
    border-bottom: 2px solid #adb5bd;
    color: #212529;
    font-size: 9px;
  }
  
  .summary-size-header th {
    background-color: #f8f9fa !important;
    border-top: 1px solid #dee2e6;
    font-weight: 600;
    font-size: 8px;
  }
  
  .table-success {
    background-color: #d1edff !important;
    font-weight: bold;
  }
  
  /* >1L column highlight */
  .large-size-column {
    background-color: #e3f2fd !important;
    font-weight: bold !important;
  }
  
  /* Category separator lines */
  .category-border-left {
    border-left: 3px solid #495057 !important;
  }
  
  .category-border-right {
    border-right: 3px solid #495057 !important;
  }
  
  /* Category background colors */
  .category-spirits {
    background-color: #e9ecef !important;
  }
  
  .category-wine {
    background-color: #d1ecf1 !important;
  }
  
  .category-fermented {
    background-color: #d4edda !important;
  }
  
  .category-mild {
    background-color: #f8d7da !important;
  }
  
  /* Responsive adjustments */
  @media (max-width: 1800px) {
    #purchaseSummaryTable th.size-column,
    #purchaseSummaryTable td.size-column {
      width: 32px;
      min-width: 32px;
    }
    
    #purchaseSummaryTable th.size-column:first-child,
    #purchaseSummaryTable td.size-column:first-child {
      width: 38px;
      min-width: 38px;
    }
  }
  
  @media (max-width: 1600px) {
    #purchaseSummaryTable {
      font-size: 8px;
    }
    
    #purchaseSummaryTable th.size-column,
    #purchaseSummaryTable td.size-column {
      width: 30px;
      min-width: 30px;
    }
    
    #purchaseSummaryTable th.size-column:first-child,
    #purchaseSummaryTable td.size-column:first-child {
      width: 35px;
      min-width: 35px;
    }
    
    #purchaseSummaryTable th.fixed-column,
    #purchaseSummaryTable td.fixed-column {
      width: 65px;
      min-width: 65px;
    }
  }
  
  @media (max-width: 1400px) {
    #purchaseSummaryTable th.size-column,
    #purchaseSummaryTable td.size-column {
      width: 28px;
      min-width: 28px;
    }
    
    #purchaseSummaryTable th.size-column:first-child,
    #purchaseSummaryTable td.size-column:first-child {
      width: 33px;
      min-width: 33px;
    }
  }
  
  /* Column width adjustments for better fit */
  .col-voucher { width: 80px; }
  .col-date { width: 80px; }
  .col-tp { width: 80px; }
  .col-invoice { width: 100px; }
  .col-inv-date { width: 80px; }
  .col-supplier { width: 150px; min-width: 150px; }
  .col-total { width: 90px; }
  .col-status { width: 70px; }
  .col-actions { width: 70px; }
  
  /* Action buttons like opening_balance.php */
  .action-btn {
    position: sticky;
    bottom: 0;
    background-color: white;
    padding: 10px 0;
    border-top: 1px solid #dee2e6;
    z-index: 100;
  }
  
  .import-export-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
  }

  /* Ensure table fits without horizontal scroll on typical screens */
  @media (min-width: 1200px) {
    .styled-table {
      table-layout: auto;
    }
  }

  /* For smaller screens, allow horizontal scroll */
  @media (max-width: 1199px) {
    .table-container {
      overflow-x: auto;
    }
  }

  /* Sticky table header */
  .sticky-header {
    position: sticky;
    top: 0;
    background-color: white;
    z-index: 100;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
  }
  
  /* Summary table specific styles */
  .summary-header-group {
    background-color: #f1f5f9 !important;
    border-bottom: 2px solid #94a3b8;
  }
  
  .summary-size-header {
    background-color: #f8fafc !important;
    border-top: 1px solid #e2e8f0;
  }
  
  .fixed-column {
    position: sticky;
    left: 0;
    background-color: white;
    z-index: 2;
    box-shadow: 2px 0 4px rgba(0,0,0,0.1);
  }
  
  /* Import modal styles */
  .import-template-info {
    font-size: 12px;
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 5px;
    margin-bottom: 15px;
  }
  
  .import-template-info ul {
    margin-bottom: 0;
  }
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Purchase Records Management</h3>

      <!-- Import/Export Buttons like opening_balance.php -->
      <div class="import-export-buttons">
        <div class="btn-group">
          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#purchaseSummaryModal">
            <i class="fas fa-chart-bar me-2"></i> Purchase Summary
          </button>
          <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importPurchaseModal">
            <i class="fas fa-file-import me-2"></i> Import from Excel/CSV
          </button>
          <a href="purchases.php?mode=<?=$mode === 'ALL' ? 'F' : $mode?>" class="btn btn-primary">
            <i class="fa-solid fa-plus me-2"></i> New Purchase
          </a>
        </div>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> Purchase saved successfully!
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <?php if ($import_success): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
          <i class="fa-solid fa-file-csv me-2"></i> Purchase data imported successfully from CSV!
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <?php if ($import_error): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
          <i class="fa-solid fa-exclamation-triangle me-2"></i> Import error: <?= htmlspecialchars($import_error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Filter Section like opening_balance.php -->
      <form method="GET" class="search-control mb-3">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn); ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder); ?>">

        <div class="row g-3">
          <div class="col-md-2">
            <label class="form-label">From Date</label>
            <input type="date" class="form-control" name="from_date" value="<?=isset($_GET['from_date']) ? $_GET['from_date'] : ''?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">To Date</label>
            <input type="date" class="form-control" name="to_date" value="<?=isset($_GET['to_date']) ? $_GET['to_date'] : ''?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Voucher No.</label>
            <input type="text" class="form-control" name="voc_no" value="<?=isset($_GET['voc_no']) ? $_GET['voc_no'] : ''?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Supplier</label>
            <input type="text" class="form-control" name="supplier" value="<?=isset($_GET['supplier']) ? $_GET['supplier'] : ''?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">TP No.</label>
            <input type="text" class="form-control" name="tp_no" value="<?=isset($_GET['tp_no']) ? $_GET['tp_no'] : ''?>">
          </div>
          <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">
              <i class="fa-solid fa-filter me-2"></i> Apply
            </button>
          </div>
        </div>
      </form>
        
      <!-- Purchases List -->
      <div class="table-container">
        <table class="table table-striped table-bordered table-hover styled-table">
          <thead class="sticky-header">
            <tr>
              <th class="col-voucher"><?=getSortLink('p.VOC_NO', 'Voucher No.')?></th>
              <th class="col-date"><?=getSortLink('p.DATE', 'Date')?></th>
              <th class="col-tp"><?=getSortLink('TP_NO', 'TP No.')?></th>
              <th class="col-invoice"><?=getSortLink('p.INV_NO', 'Invoice No.')?></th>
              <th class="col-inv-date"><?=getSortLink('p.INV_DATE', 'Invoice Date')?></th>
              <th class="col-supplier"><?=getSortLink('s.DETAILS', 'Supplier')?></th>
              <th class="col-total"><?=getSortLink('p.TAMT', 'Total Amount')?></th>
              <th class="col-status"><?=getSortLink('p.PUR_FLAG', 'Status')?></th>
              <th class="col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (count($purchases) > 0): ?>
              <?php foreach($purchases as $purchase): 
                // Status mapping
                $status = 'Unknown';
                $statusClass = 'status-unpaid';
                
                if ($purchase['PUR_FLAG'] === 'C') {
                    $status = 'Completed';
                    $statusClass = 'status-completed';
                } elseif ($purchase['PUR_FLAG'] === 'T') {
                    $status = 'Unpaid';
                    $statusClass = 'status-unpaid';
                } elseif ($purchase['PUR_FLAG'] === 'P') {
                    $status = 'Partial';
                    $statusClass = 'status-partial';
                } elseif ($purchase['PUR_FLAG'] === 'F') {
                    $status = 'Final';
                    $statusClass = 'status-completed';
                }
              ?>
                <tr>
                  <td class="col-voucher"><?=htmlspecialchars($purchase['VOC_NO'])?></td>
                  <td class="col-date"><?=htmlspecialchars($purchase['DATE'])?></td>
                  <td class="col-tp"><?=htmlspecialchars($purchase['TP_NO'])?></td>
                  <td class="col-invoice"><?=htmlspecialchars($purchase['INV_NO'])?></td>
                  <td class="col-inv-date"><?=htmlspecialchars($purchase['INV_DATE'])?></td>
                  <td class="col-supplier"><?=htmlspecialchars($purchase['supplier_name'])?></td>
                  <td class="col-total">₹<?=number_format($purchase['TAMT'], 2)?></td>
                  <td class="col-status">
                    <span class="status-badge <?=$statusClass?>"><?=$status?></span>
                  </td>
                  <td class="col-actions">
                    <div class="action-buttons">
                      <a href="purchase_edit.php?id=<?=htmlspecialchars($purchase['ID'])?>&mode=<?=htmlspecialchars($mode)?>" 
                         class="btn btn-sm btn-warning" title="Edit">
                        <i class="fa-solid fa-edit"></i>
                      </a>
                      <button class="btn btn-sm btn-danger" 
                              title="Delete" 
                              onclick="confirmDelete(<?=htmlspecialchars($purchase['ID'])?>, '<?=htmlspecialchars($mode)?>', '<?=htmlspecialchars($purchase['DATE'])?>', '<?=htmlspecialchars($purchase['TP_NO'])?>')">
                        <i class="fa-solid fa-trash"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" class="text-center">No purchases found for the selected filters.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Action buttons at bottom like opening_balance.php -->
      <div class="action-btn mt-3 d-flex gap-2">
        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#purchaseSummaryModal">
          <i class="fas fa-chart-bar me-2"></i> Purchase Summary
        </button>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importPurchaseModal">
          <i class="fas fa-file-import me-2"></i> Import from CSV
        </button>
        <div class="ms-auto d-flex gap-2">
          <a href="dashboard.php" class="btn btn-secondary">
            <i class="fas fa-sign-out-alt me-2"></i> Exit
          </a>
          <a href="purchases.php?mode=<?=$mode === 'ALL' ? 'F' : $mode?>" class="btn btn-primary">
            <i class="fa-solid fa-plus me-2"></i> New Purchase
          </a>
        </div>
      </div>

    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Purchase Summary Modal -->
<div class="modal fade" id="purchaseSummaryModal" tabindex="-1" aria-labelledby="purchaseSummaryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="purchaseSummaryModalLabel">Purchase Summary - TP Wise</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Summary filters -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">From Date</label>
                        <input type="date" id="purchaseFromDate" class="form-control" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">To Date</label>
                        <input type="date" id="purchaseToDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12">
                        <button type="button" class="btn btn-primary w-100" onclick="loadPurchaseSummary()">
                            <i class="fas fa-refresh"></i> Update Summary
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-sm table-striped" id="purchaseSummaryTable">
                        <thead class="table-light sticky-top">
                            <tr id="sizeHeaders">
                                <!-- Headers will be dynamically generated -->
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="52" class="text-center text-muted py-4">
                                    <i class="fas fa-info-circle fa-2x mb-3"></i><br>
                                    <h5>Ready to Load Data</h5>
                                    <p class="mb-0">Click "Update Summary" to load purchase summary data</p>
                                    <small class="text-info">Note: Sizes >1L are grouped together</small>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printPurchaseSummary()">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Import Purchase Modal -->
<div class="modal fade" id="importPurchaseModal" tabindex="-1" aria-labelledby="importPurchaseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="importPurchaseModalLabel">Import Purchases from CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="import_purchase.php" enctype="multipart/form-data" id="importForm">
                <div class="modal-body">
                    <div class="import-template-info">
                        <strong><i class="fas fa-info-circle me-2"></i>CSV File Format Requirements:</strong>
                        <ul class="mt-2">
                            <li>File format: .csv (Comma Separated Values)</li>
                            <li>Required columns: Date, TP No., Supplier, Item Code, Item Name, Size, Cases, Bottles, Free Cases, Free Bottles, Case Rate, MRP</li>
                            <li>Date format: YYYY-MM-DD (e.g., 2025-12-07)</li>
                            <li>Make sure item codes match your database (with or without SCM prefix)</li>
                            <li>First row should contain column headers</li>
                            <li>Save your Excel file as CSV: File → Save As → CSV (Comma delimited)</li>
                        </ul>
                        <p class="mt-2 mb-0">
                            <a href="generate_template.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-download me-1"></i> Download CSV Template
                            </a>
                        </p>
                    </div>

                    <div class="mb-3">
                        <label for="excelFile" class="form-label">Select CSV File</label>
                        <input type="file" name="excel_file" id="excelFile" class="form-control" accept=".csv" required>
                        <div class="form-text">Allowed file type: .csv (Max 10MB). Please use CSV format for reliable import.</div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <label class="form-label">Purchase Mode</label>
                            <select name="import_mode" class="form-select" required>
                                <option value="F">Foreign (F)</option>
                                <option value="C">Country (C)</option>
                                <option value="ALL" selected>All</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Default Status</label>
                            <select name="default_status" class="form-select" required>
                                <option value="T" selected>Temporary (T)</option>
                                <option value="F">Final (F)</option>
                                <option value="C">Completed (C)</option>
                                <option value="P">Partial (P)</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="update_mrp" id="updateMRP" checked>
                        <label class="form-check-label" for="updateMRP">
                            Update MRP prices in item master
                        </label>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="update_stock" id="updateStock" checked>
                        <label class="form-check-label" for="updateStock">
                            Update stock levels
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="importSubmit">
                        <i class="fas fa-upload me-2"></i> Import CSV Data
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete this purchase? This action will:</p>
        <ul>
          <li>Delete the purchase record from tblpurchases</li>
          <li>Delete all purchase details from tblpurchasedetails</li>
          <li>Update item stock in tblitemstock</li>
          <li>Update daily stock records from the purchase date until today</li>
        </ul>
        <p class="text-danger"><strong>Warning:</strong> This action cannot be undone and will affect stock calculations.</p>
        <div class="alert alert-warning">
          <i class="fas fa-exclamation-triangle"></i> 
          <strong>Note:</strong> Daily stock records will be recalculated from <span id="deleteStartDate"></span> to today using the formula:<br>
          <small>day_x_closing = day_x_open + day_x_purchase - day_x_sales</small>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <a href="#" id="deleteConfirm" class="btn btn-danger">Yes, Delete</a>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Delete Confirmation Function with enhanced parameters
function confirmDelete(purchaseId, mode, purchaseDate, tpNo) {
  // Set the delete URL with all necessary parameters
  const deleteUrl = `purchase_delete.php?id=${purchaseId}&mode=${mode}&purchase_date=${purchaseDate}&tp_no=${encodeURIComponent(tpNo)}`;
  $('#deleteConfirm').attr('href', deleteUrl);
  $('#deleteStartDate').text(purchaseDate);
  $('#deleteModal').modal('show');
}

// Apply filters with date range validation
$('form').on('submit', function(e) {
  const fromDate = $('input[name="from_date"]').val();
  const toDate = $('input[name="to_date"]').val();
  
  if (fromDate && toDate && fromDate > toDate) {
    e.preventDefault();
    alert('From date cannot be greater than To date');
    return false;
  }
});

// Categories based on CLASS field mapping
const categories = [
    { 
        name: 'SPIRITS',
        sizes: [
            '>1L',
            '1L', '750 ML', '700 ML', '650 ML', '500 ML', '375 ML', '355 ML', '330 ML',
            '275 ML', '250 ML', '200 ML', '180 ML', '170 ML', '90 ML', '60 ML', '50 ML'
        ],
        columnClass: 'size-column',
        bgColor: '#e9ecef',
        borderColor: '#495057'
    },
    { 
        name: 'WINE', 
        sizes: [
            '>1L',
            '1L W', '750 W', '700 W', '500 W', '375 W', '330 W',
            '250 W', '180 W', '100 W'
        ],
        columnClass: 'size-column',
        bgColor: '#d1ecf1',
        borderColor: '#17a2b8'
    },
    { 
        name: 'FERMENTED BEER', 
        sizes: [
            '>1L',
            '1L', '750 ML', '650 ML', '500 ML', '375 ML', '330 ML', 
            '275 ML', '250 ML', '180 ML', '90 ML', '60 ML'
        ],
        columnClass: 'size-column',
        bgColor: '#d4edda',
        borderColor: '#28a745'
    },
    { 
        name: 'MILD BEER', 
        sizes: [
            '>1L',
            '1L', '750 ML', '650 ML', '500 ML', '375 ML', '330 ML', 
            '275 ML', '250 ML', '180 ML', '90 ML', '60 ML'
        ],
        columnClass: 'size-column',
        bgColor: '#f8d7da',
        borderColor: '#dc3545'
    }
];

// Function to load purchase summary via AJAX
function loadPurchaseSummary() {
    const fromDate = $('#purchaseFromDate').val();
    const toDate = $('#purchaseToDate').val();
    const purchaseType = 'ALL';

    let totalSizeColumns = 0;
    categories.forEach(cat => totalSizeColumns += cat.sizes.length);
    const totalColumns = totalSizeColumns + 1; // +1 for TP column
    
    // Show loading state
    $('#purchaseSummaryTable tbody').html(`
        <tr>
            <td colspan="${totalColumns}" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading purchase summary data...</p>
            </td>
        </tr>
    `);
    
    $.ajax({
        url: 'purchase_summary_ajax.php',
        type: 'GET',
        data: {
            mode: purchaseType,
            from_date: fromDate,
            to_date: toDate,
            comp_id: '<?= $companyId ?>'
        },
        success: function(response) {
            try {
                let summaryData;
                if (typeof response === 'string') {
                    if (response.trim() === '') {
                        throw new Error('Empty response from server');
                    }
                    summaryData = JSON.parse(response);
                } else {
                    summaryData = response;
                }
                
                if (summaryData.error) {
                    throw new Error(summaryData.error);
                }
                
                updatePurchaseSummaryTable(summaryData);
            } catch (e) {
                console.error('Error parsing response:', e);
                $('#purchaseSummaryTable tbody').html(`
                    <tr>
                        <td colspan="${totalColumns}" class="text-center text-danger py-4">
                            <i class="fas fa-exclamation-triangle"></i><br>
                            Error loading purchase summary<br>
                            <small>${e.message}</small>
                        </td>
                    </tr>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            
            let errorMessage = 'Failed to load purchase summary';
            if (xhr.responseText) {
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse.error) {
                        errorMessage = errorResponse.error;
                    }
                } catch (e) {
                    errorMessage = 'Server error: ' + xhr.responseText.substring(0, 100);
                }
            }
            
            $('#purchaseSummaryTable tbody').html(`
                <tr>
                    <td colspan="${totalColumns}" class="text-center text-danger py-4">
                        <i class="fas fa-exclamation-triangle"></i><br>
                        ${errorMessage}<br>
                        <small>Status: ${status}, Error: ${error}</small>
                    </td>
                </tr>
            `);
        }
    });
}

// Function to update the purchase summary table with TP-wise data
function updatePurchaseSummaryTable(summaryData) {
    const tbody = $('#purchaseSummaryTable tbody');
    
    // Clear existing table
    $('#purchaseSummaryTable thead').empty();
    tbody.empty();

    // Create main header row with category groups
    const mainHeaderRow = $('<tr>').addClass('summary-header-group');
    
    // TP No column
    mainHeaderRow.append($('<th>')
        .text('TP No.')
        .attr('rowspan', '2')
        .addClass('fixed-column')
        .css({
            'font-weight': 'bold',
            'background-color': '#343a40',
            'color': 'white',
            'border': '2px solid #495057'
        }));
    
    // Add category headers with colspan and distinct colors
    categories.forEach((category, index) => {
        const headerCell = $('<th>')
            .attr('colspan', category.sizes.length)
            .text(category.name)
            .addClass('text-center')
            .addClass('category-' + category.name.toLowerCase().replace(' ', '-'))
            .css({
                'font-weight': 'bold',
                'background-color': category.bgColor,
                'border': '2px solid ' + category.borderColor,
                'border-left': index === 0 ? '2px solid ' + category.borderColor : '3px solid #495057',
                'color': '#212529'
            });
        
        mainHeaderRow.append(headerCell);
    });
    
    // Create size header row
    const sizeHeaderRow = $('<tr>').addClass('summary-size-header');
    
    categories.forEach((category, catIndex) => {
        category.sizes.forEach((size, sizeIndex) => {
            const isLargeSizeColumn = sizeIndex === 0;
            const isFirstColumnInCategory = sizeIndex === 0;
            const isLastColumnInCategory = sizeIndex === category.sizes.length - 1;
            
            const sizeCell = $('<th>')
                .text(size)
                .addClass('text-center ' + category.columnClass)
                .addClass('category-' + category.name.toLowerCase().replace(' ', '-'))
                .css({
                    'font-weight': '600',
                    'font-size': '9px',
                    'background-color': isLargeSizeColumn ? '#e3f2fd' : category.bgColor,
                    'border-top': '1px solid #dee2e6',
                    'border-left': isFirstColumnInCategory ? '3px solid #495057' : '1px solid #dee2e6',
                    'border-right': isLastColumnInCategory && catIndex === categories.length - 1 ? '1px solid #dee2e6' : '1px solid #dee2e6'
                });
            
            sizeHeaderRow.append(sizeCell);
        });
    });
    
    $('#purchaseSummaryTable thead').append(mainHeaderRow, sizeHeaderRow);

    // Calculate total columns
    let totalSizeColumns = 0;
    categories.forEach(cat => totalSizeColumns += cat.sizes.length);
    const totalColumns = totalSizeColumns + 1;

    // Check if we have data
    if (!summaryData || typeof summaryData !== 'object' || Object.keys(summaryData).length === 0) {
        tbody.html(`
            <tr>
                <td colspan="${totalColumns}" class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-3"></i><br>
                    <h5>No Data Found</h5>
                    <p class="mb-0">No purchase data found for the selected date range</p>
                </td>
            </tr>
        `);
        return;
    }

    // Create rows for each TP number
    let serialNumber = 1;
    Object.keys(summaryData).forEach((tpNo) => {
        const tpData = summaryData[tpNo];
        const row = $('<tr>');
        
        // TP number cell (fixed column)
        row.append($('<td>')
            .addClass('fixed-column fw-bold')
            .css({
                'background-color': serialNumber % 2 === 0 ? '#f8f9fa' : 'white',
                'border-right': '2px solid #495057'
            })
            .text(tpNo)
            .attr('title', 'TP No: ' + tpNo));
        
        // Add data for each category and size
        categories.forEach((category, catIndex) => {
            let categoryTotal = 0;
            category.sizes.forEach((size, sizeIndex) => {
                const isLargeSizeColumn = sizeIndex === 0;
                const isFirstColumnInCategory = sizeIndex === 0;
                
                let value = 0;
                
                // Check if data exists for this category and size
                if (tpData.categories && 
                    tpData.categories[category.name] && 
                    tpData.categories[category.name][size]) {
                    value = tpData.categories[category.name][size];
                    categoryTotal += value;
                }
                
                const cell = $('<td>')
                    .addClass('text-center ' + category.columnClass)
                    .addClass('category-' + category.name.toLowerCase().replace(' ', '-'))
                    .css({
                        'background-color': serialNumber % 2 === 0 ? '#f8f9fa' : 'white',
                        'font-size': '9px',
                        'padding': '2px 3px',
                        'border-left': isFirstColumnInCategory ? '3px solid #495057' : '1px solid #dee2e6'
                    });
                
                if (isLargeSizeColumn) {
                    cell.addClass('large-size-column');
                }
                
                if (value > 0) {
                    cell.text(value)
                        .addClass('table-success')
                        .css('font-weight', 'bold');
                } else {
                    cell.text('-')
                        .css('color', '#adb5bd');
                }
                
                row.append(cell);
            });
        });
        
        tbody.append(row);
        serialNumber++;
    });

    // Add a total row
    addTotalRow(summaryData, categories);
}

// Function to add total row
function addTotalRow(summaryData, categories) {
    const totals = {};
    
    // Initialize totals
    categories.forEach(category => {
        totals[category.name] = {};
        category.sizes.forEach(size => {
            totals[category.name][size] = 0;
        });
    });
    
    // Calculate totals
    Object.values(summaryData).forEach(tpData => {
        if (tpData && tpData.categories) {
            categories.forEach(category => {
                category.sizes.forEach(size => {
                    if (tpData.categories[category.name] && tpData.categories[category.name][size]) {
                        totals[category.name][size] += tpData.categories[category.name][size];
                    }
                });
            });
        }
    });
    
    // Check if we have any totals
    let hasTotals = false;
    categories.forEach(category => {
        category.sizes.forEach(size => {
            if (totals[category.name][size] > 0) {
                hasTotals = true;
            }
        });
    });
    
    if (hasTotals) {
        const totalRow = $('<tr>').addClass('table-primary fw-bold');
        totalRow.append($('<td>')
            .addClass('fixed-column')
            .css({
                'background-color': '#495057',
                'color': 'white',
                'border': '2px solid #343a40',
                'font-weight': 'bold'
            })
            .text('TOTAL'));
        
        categories.forEach((category, catIndex) => {
            category.sizes.forEach((size, sizeIndex) => {
                const isLargeSizeColumn = sizeIndex === 0;
                const isFirstColumnInCategory = sizeIndex === 0;
                const value = totals[category.name][size];
                const cell = $('<td>')
                    .addClass('text-center ' + category.columnClass)
                    .css({
                        'background-color': '#495057',
                        'color': 'white',
                        'border': '1px solid #343a40',
                        'border-left': isFirstColumnInCategory ? '3px solid #343a40' : '1px solid #343a40',
                        'font-size': '9px',
                        'padding': '2px 3px',
                        'font-weight': 'bold'
                    });
                
                if (isLargeSizeColumn) {
                    cell.addClass('large-size-column');
                }
                
                if (value > 0) {
                    cell.text(value);
                } else {
                    cell.text('-')
                        .css('opacity', '0.7');
                }
                
                totalRow.append(cell);
            });
        });
        
        $('#purchaseSummaryTable tbody').append(totalRow);
    }
}

// Function to print purchase summary
function printPurchaseSummary() {
    const fromDate = $('#purchaseFromDate').val();
    const toDate = $('#purchaseToDate').val();
    const currentDate = new Date().toLocaleDateString();
    const currentTime = new Date().toLocaleTimeString();
    
    // Clone the table for printing
    const printTable = $('#purchaseSummaryTable').clone();
    
    // Remove fixed column class for print
    printTable.find('.fixed-column').removeClass('fixed-column');
    printTable.find('th, td').css({
        'position': 'static',
        'width': 'auto'
    });
    
    const printContent = printTable.parent().html();
    const printWindow = window.open('', '_blank');
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Purchase Summary - TP Wise</title>
            <style>
                body { 
                    font-family: Arial, sans-serif; 
                    margin: 15px; 
                    font-size: 12px;
                }
                h2 { 
                    text-align: center; 
                    margin-bottom: 5px; 
                    color: #333;
                }
                .summary-info { 
                    text-align: center; 
                    margin-bottom: 15px; 
                    color: #666; 
                    font-size: 14px;
                    border-bottom: 1px solid #ddd;
                    padding-bottom: 10px;
                }
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    font-size: 8px;
                    margin-top: 10px;
                }
                th, td { 
                    border: 1px solid #ddd; 
                    padding: 3px; 
                    text-align: center;
                    page-break-inside: avoid;
                }
                th { 
                    background-color: #f2f2f2 !important; 
                    font-weight: bold;
                    -webkit-print-color-adjust: exact;
                }
                .table-success { 
                    background-color: #e3f2fd !important;
                    -webkit-print-color-adjust: exact;
                }
                .total-row th,
                .total-row td {
                    background-color: #007bff !important;
                    color: white !important;
                    -webkit-print-color-adjust: exact;
                }
                .large-size-column {
                    background-color: #e3f2fd !important;
                    font-weight: bold !important;
                    -webkit-print-color-adjust: exact;
                }
                .print-footer { 
                    margin-top: 20px; 
                    border-top: 1px solid #ddd; 
                    padding-top: 10px; 
                    font-size: 11px; 
                    color: #666;
                    text-align: center;
                }
                @media print {
                    body { margin: 5mm; }
                    table { font-size: 7px; }
                    .no-print { display: none; }
                    @page { margin: 0.5cm; size: landscape; }
                }
                .category-header {
                    background-color: #e9ecef !important;
                    font-weight: bold;
                }
                .note {
                    font-size: 10px;
                    color: #666;
                    font-style: italic;
                    margin-top: 5px;
                }
                .category-border {
                    border-left: 3px solid #333 !important;
                }
                .category-spirits { background-color: #e9ecef !important; }
                .category-wine { background-color: #d1ecf1 !important; }
                .category-fermented-beer { background-color: #d4edda !important; }
                .category-mild-beer { background-color: #f8d7da !important; }
            </style>
        </head>
        <body>
            <div style="margin-bottom: 20px;">
                <h2>Purchase Summary - TP Wise</h2>
                <div class="summary-info">
                    Date Range: ${fromDate} to ${toDate}<br>
                    Company ID: <?= $companyId ?><br>
                    Printed on: ${currentDate} at ${currentTime}
                </div>
                <div class="note">
                    Note: All sizes greater than 1 liter are grouped in ">1L" column<br>
                    Categories: SPIRITS | WINE | FERMENTED BEER | MILD BEER
                </div>
            </div>
            ${printContent}
            <div class="print-footer">
                User: <?= $_SESSION['user_id'] ?? 'Unknown' ?> | 
                Report generated by WineSoft Purchase Module
            </div>
            <script>
                window.onload = function() {
                    window.print();
                    setTimeout(function() {
                        window.close();
                    }, 500);
                };
            <\/script>
        </body>
        </html>
    `);
    
    printWindow.document.close();
}

// File upload functionality
$(document).ready(function() {
    const fileInput = $('#excelFile');
    const importForm = $('#importForm');
    const importSubmit = $('#importSubmit');
    
    // File selected - show validation
    fileInput.on('change', function() {
        const file = this.files[0];
        if (file) {
            const fileSize = (file.size / 1024 / 1024).toFixed(2); // MB
            
            // Check file size (10MB max)
            if (fileSize > 10) {
                alert('File size exceeds 10MB limit. Please select a smaller file.');
                $(this).val(''); // Clear file input
                return;
            }
            
            // Check file extension - ONLY CSV
            const fileName = file.name.toLowerCase();
            if (!fileName.match(/\.csv$/)) {
                alert('Please select only CSV files (.csv). Save your Excel file as CSV format first.');
                $(this).val(''); // Clear file input
                return;
            }
            
            console.log('File selected:', file.name, 'Size:', fileSize + 'MB');
        }
    });
    
    // Form submission
    importForm.on('submit', function(e) {
        const file = fileInput[0].files[0];
        if (!file) {
            e.preventDefault();
            alert('Please select a CSV file to upload');
            fileInput.focus();
            return;
        }
        
        // Show loading
        importSubmit.html('<i class="fas fa-spinner fa-spin me-2"></i> Importing...').prop('disabled', true);
        
        // You can also validate file size here again
        const fileSize = (file.size / 1024 / 1024).toFixed(2);
        if (fileSize > 10) {
            e.preventDefault();
            alert('File size exceeds 10MB limit. Please select a smaller file.');
            importSubmit.html('<i class="fas fa-upload me-2"></i> Import Data').prop('disabled', false);
            fileInput.val('');
            return;
        }
    });
    
    // Reset button state when modal is hidden
    $('#importPurchaseModal').on('hidden.bs.modal', function() {
        importSubmit.html('<i class="fas fa-upload me-2"></i> Import Data').prop('disabled', false);
        // Clear file input
        fileInput.val('');
    });
    
    // Initialize modal
    $('#purchaseSummaryModal').on('show.bs.modal', function() {
        if (!$('#purchaseFromDate').val()) {
            $('#purchaseFromDate').val('<?= date('Y-m-01') ?>');
        }
        if (!$('#purchaseToDate').val()) {
            $('#purchaseToDate').val('<?= date('Y-m-d') ?>');
        }
        
        // Load initial summary
        loadPurchaseSummary();
    });
});

// Add hover effects to table headers
$(document).ready(function() {
    $('.styled-table thead th').hover(
        function() {
            $(this).css('background', '#e9ecef');
        },
        function() {
            if (!$(this).find('i').hasClass('fa-sort-up') && !$(this).find('i').hasClass('fa-sort-down')) {
                $(this).css('background', '#f8fafc');
            }
        }
    );
});

// Auto-hide alerts after 5 seconds
setTimeout(() => {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        const bsAlert = new bootstrap.Alert(alert);
        bsAlert.close();
    });
}, 5000);
</script>
</body>
</html>