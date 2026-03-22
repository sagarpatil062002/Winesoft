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
include_once "components/financial_year.php";

// Extract financial year variables from session
$fin_year_start = $_SESSION['FIN_YEAR_START'] ?? null;
$fin_year_end = $_SESSION['FIN_YEAR_END'] ?? null;
$fin_year_id = $_SESSION['FIN_YEAR_ID'] ?? null;

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

// Handle delete success/error messages
if (isset($_GET['delete_success'])) {
    $delete_success = urldecode($_GET['delete_success']);
}

if (isset($_GET['delete_error'])) {
    $delete_error = urldecode($_GET['delete_error']);
}

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
  
  /* Modal adjustments for purchase summary - FIXED SCROLLING */
  #purchaseSummaryModal .modal-dialog {
    max-width: 98vw;
    margin: 5px auto;
    width: auto;
  }
  
  #purchaseSummaryModal .modal-content {
    max-height: 95vh;
    overflow: hidden;
  }
  
  #purchaseSummaryModal .modal-header {
    position: sticky;
    top: 0;
    background-color: white;
    z-index: 1050;
    border-bottom: 1px solid #dee2e6;
  }
  
  #purchaseSummaryModal .modal-body {
    padding: 10px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    height: calc(95vh - 120px);
  }
  
  /* Make the summary table container BOTH horizontally and vertically scrollable */
  #purchaseSummaryModal .table-responsive {
    flex: 1;
    overflow: auto;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    position: relative;
  }
  
  /* Double scrollbar - vertical on right, horizontal on bottom */
  #purchaseSummaryModal .table-responsive::-webkit-scrollbar {
    width: 10px;
    height: 10px;
  }
  
  #purchaseSummaryModal .table-responsive::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
  }
  
  #purchaseSummaryModal .table-responsive::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 4px;
  }
  
  #purchaseSummaryModal .table-responsive::-webkit-scrollbar-thumb:hover {
    background: #555;
  }
  
  /* Horizontal scroll indicator */
  .scroll-hint {
    position: absolute;
    bottom: 5px;
    right: 5px;
    background: rgba(0, 0, 0, 0.7);
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 10px;
    z-index: 1000;
    animation: fadeInOut 3s infinite;
  }
  
  @keyframes fadeInOut {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
  }
  
  /* Summary table header styles */
  .summary-header-group th {
    background-color: #e9ecef !important;
    font-weight: bold;
    border-bottom: 2px solid #adb5bd;
    color: #212529;
    font-size: 9px;
    position: sticky;
    top: 0;
    z-index: 10;
    height: 30px;
    line-height: 20px;
    padding: 4px 8px;
  }
  
  .summary-size-header th {
    background-color: #f8f9fa !important;
    border-top: 1px solid #dee2e6;
    font-weight: 600;
    font-size: 8px;
    position: sticky;
    top: 30px; /* Height of first header row */
    z-index: 9;
    height: 24px;
    line-height: 14px;
    padding: 2px 4px;
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
  
  .category-fermented-beer {
    background-color: #d4edda !important;
  }
  
  .category-mild-beer {
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
  
  .summary-header-group th,
  .summary-size-header th {
    vertical-align: middle !important;
  }
  
  /* Ensure data rows have proper spacing from headers */
  #purchaseSummaryTable tbody tr:first-child td {
    padding-top: 8px;
  }
  
  .fixed-column {
    position: sticky;
    left: 0;
    background-color: white;
    z-index: 4; /* Higher than other sticky elements */
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
  
  /* File drop zone styles */
  .file-drop-zone {
    border: 2px dashed #6c757d;
    border-radius: 8px;
    padding: 30px;
    text-align: center;
    background-color: #f8f9fa;
    transition: all 0.3s ease;
    cursor: pointer;
  }
  
  .file-drop-zone:hover,
  .file-drop-zone.dragover {
    border-color: #0d6efd;
    background-color: #e7f1ff;
  }
  
  .file-drop-zone input[type="file"] {
    display: none;
  }
  
  .drop-zone-content {
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  
  /* Bulk delete selection styles */
  .selection-count {
    background-color: #28a745;
    color: white;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    margin-left: 10px;
  }
  
  .select-checkbox {
    width: 50px;
    text-align: center;
  }
  
  /* Alert styles */
  .alert {
    margin-bottom: 15px;
  }
  
  /* Modal footer sticky */
  #purchaseSummaryModal .modal-footer {
    position: sticky;
    bottom: 0;
    background-color: white;
    border-top: 1px solid #dee2e6;
    z-index: 1050;
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

      <!-- Success/Error Messages -->
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

      <?php if (isset($delete_success)): ?>
        <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
          <i class="fa-solid fa-trash-check me-2"></i> <?= htmlspecialchars($delete_success) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <?php if (isset($delete_error)): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
          <i class="fa-solid fa-trash-xmark me-2"></i> <?= htmlspecialchars($delete_error) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Import/Export Buttons -->
      <div class="import-export-buttons">
        <div class="btn-group">
          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#purchaseSummaryModal">
            <i class="fas fa-chart-bar me-2"></i> Purchase Summary
          </button>
          <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importPurchaseModal">
            <i class="fas fa-file-import me-2"></i> Import from Excel/CSV
          </button>
          <button type="button" class="btn btn-danger" id="bulkDeleteBtn" disabled>
            <i class="fa-solid fa-trash me-2"></i> Delete Selected
          </button>
          <a href="purchases.php?mode=<?=$mode === 'ALL' ? 'F' : $mode?>" class="btn btn-primary">
            <i class="fa-solid fa-plus me-2"></i> New Purchase
          </a>
        </div>
      </div>

      <!-- Filter Section -->
      <form method="GET" class="search-control mb-3">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <input type="hidden" name="sort" value="<?= htmlspecialchars($sortColumn); ?>">
        <input type="hidden" name="order" value="<?= htmlspecialchars($sortOrder); ?>">

        <div class="row g-3">
          <div class="col-md-2">
            <label class="form-label">From Date</label>
            <input type="date" class="form-control" name="from_date" 
                   value="<?=isset($_GET['from_date']) ? $_GET['from_date'] : (isset($_SESSION['FIN_YEAR_START']) ? $_SESSION['FIN_YEAR_START'] : '')?>"
                   min="<?= htmlspecialchars($_SESSION['FIN_YEAR_START'] ?? '') ?>"
                   max="<?= htmlspecialchars($_SESSION['FIN_YEAR_END'] ?? '') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">To Date</label>
            <input type="date" class="form-control" name="to_date" 
                   value="<?=isset($_GET['to_date']) ? $_GET['to_date'] : (isset($_SESSION['FIN_YEAR_END']) ? $_SESSION['FIN_YEAR_END'] : '')?>"
                   min="<?= htmlspecialchars($_SESSION['FIN_YEAR_START'] ?? '') ?>"
                   max="<?= htmlspecialchars($_SESSION['FIN_YEAR_END'] ?? '') ?>">
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
              <th class="select-checkbox">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="selectAllPurchases">
                </div>
              </th>
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
                <tr data-id="<?= htmlspecialchars($purchase['ID']) ?>">
                  <td class="select-checkbox">
                    <div class="form-check">
                      <input class="form-check-input purchase-checkbox" type="checkbox" 
                             value="<?= htmlspecialchars($purchase['ID']) ?>">
                    </div>
                   </td>
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
                      <button class="btn btn-sm btn-danger delete-single-btn" 
                              title="Delete" 
                              data-id="<?= htmlspecialchars($purchase['ID']) ?>"
                              data-date="<?= htmlspecialchars($purchase['DATE']) ?>"
                              data-tpno="<?= htmlspecialchars($purchase['TP_NO']) ?>">
                        <i class="fa-solid fa-trash"></i>
                      </button>
                    </div>
                   </td>
                 </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="10" class="text-center">No purchases found for the selected filters.</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Action buttons at bottom -->
      <div class="action-btn mt-3 d-flex gap-2">
        <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#purchaseSummaryModal">
          <i class="fas fa-chart-bar me-2"></i> Purchase Summary
        </button>
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importPurchaseModal">
          <i class="fas fa-file-import me-2"></i> Import from CSV
        </button>
        <button type="button" class="btn btn-danger" id="bulkDeleteBottomBtn" disabled>
          <i class="fa-solid fa-trash me-2"></i> Delete Selected
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

<!-- Purchase Summary Modal with Improved Scrolling -->
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
                    <div class="col-md-4">
                        <label class="form-label">From Date</label>
                        <input type="date" id="purchaseFromDate" class="form-control" 
                               value="<?= isset($_SESSION['FIN_YEAR_START']) ? $_SESSION['FIN_YEAR_START'] : date('Y-m-01') ?>"
                               min="<?= htmlspecialchars($_SESSION['FIN_YEAR_START'] ?? '') ?>"
                               max="<?= htmlspecialchars($_SESSION['FIN_YEAR_END'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">To Date</label>
                        <input type="date" id="purchaseToDate" class="form-control" 
                               value="<?= isset($_SESSION['FIN_YEAR_END']) ? min($_SESSION['FIN_YEAR_END'], date('Y-m-d')) : date('Y-m-d') ?>"
                               min="<?= htmlspecialchars($_SESSION['FIN_YEAR_START'] ?? '') ?>"
                               max="<?= htmlspecialchars($_SESSION['FIN_YEAR_END'] ?? '') ?>">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn btn-primary w-100" onclick="loadPurchaseSummary()">
                            <i class="fas fa-refresh me-2"></i> Update Summary
                        </button>
                    </div>
                </div>
                
                <!-- Scroll hint -->
                <div class="alert alert-info py-2 mb-2">
                    <i class="fas fa-info-circle me-2"></i>
                    Use scrollbars below to view all TP numbers and sizes. TP column stays fixed while scrolling.
                </div>
                
                <!-- Scrollable table container -->
                <div class="table-responsive" id="summaryTableContainer">
                    <div class="scroll-hint" id="horizontalHint" style="display: none;">
                        <i class="fas fa-arrows-left-right me-1"></i> Scroll horizontally
                    </div>
                    <div class="scroll-hint" id="verticalHint" style="display: none; bottom: 20px;">
                        <i class="fas fa-arrows-up-down me-1"></i> Scroll vertically
                    </div>
                    <table class="table table-bordered table-sm table-striped" id="purchaseSummaryTable">
                        <thead class="table-light">
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
                <div class="d-flex justify-content-between w-100">
                    <div>
                        <span class="text-muted small" id="summaryStats">
                            <i class="fas fa-chart-bar me-1"></i>
                            <span id="tpCount">0</span> TP(s) loaded
                        </span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary me-2" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i> Close
                        </button>
                        <button type="button" class="btn btn-primary" onclick="printPurchaseSummary()">
                            <i class="fas fa-print me-2"></i> Print
                        </button>
                    </div>
                </div>
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
                        <label for="excelFile" class="form-label">Select CSV Files (Multiple)</label>
                        <div class="file-drop-zone" id="fileDropZone">
                            <div class="drop-zone-content">
                                <i class="fas fa-cloud-upload-alt fa-3x text-primary mb-2"></i>
                                <p class="mb-1">Drag & drop CSV files here</p>
                                <p class="text-muted small mb-2">or</p>
                                <input type="file" name="excel_files[]" id="excelFile" class="form-control" accept=".csv" multiple required>
                                <label for="excelFile" class="btn btn-outline-primary btn-sm mt-2">Browse Files</label>
                            </div>
                        </div>
                        <div class="form-text">Allowed file type: .csv (Max 10MB each). Hold Ctrl/Cmd to select multiple files. Max 50 files at a time.</div>
                        <div id="selectedFiles" class="mt-2"></div>
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
        <button type="button" class="btn btn-danger" id="deleteConfirmBtn">
          <i class="fas fa-trash me-2"></i> Yes, Delete
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Bulk Delete Confirmation Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Bulk Delete</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="deletePurchaseCount">0</strong> selected purchase(s)?</p>
                <div class="alert alert-warning">
                    <i class="fa-solid fa-exclamation-triangle me-2"></i>
                    <strong>This action will:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Reduce item stock levels</li>
                        <li>Update DAY_XX_PURCHASE columns in daily stock tables</li>
                        <li>Recalculate closing stock for affected dates</li>
                        <li>Delete purchase records permanently</li>
                    </ul>
                </div>
                <p class="text-danger">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>
                    <strong>Warning:</strong> This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmBulkDelete">
                    <i class="fa-solid fa-trash me-2"></i> Delete Selected
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading Modal -->
<div class="modal fade" id="loadingModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div class="spinner-border text-primary mb-3" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <h6>Processing Deletion...</h6>
                <p class="text-muted small mb-0">Please wait while we update stock records</p>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Categories based on CLASS field mapping
// Sizes ordered from LARGEST to SMALLEST (descending order)
const categories = [
    { 
        name: 'SPIRITS',
        sizes: [
            '>1L', '1L', '750 ML', '700 ML', '650 ML', '500 ML', '375 ML', '355 ML',
            '330 ML', '275 ML', '250 ML', '200 ML', '180 ML', '170 ML', '90 ML', '60 ML', '50 ML'
        ],
        columnClass: 'size-column',
        bgColor: '#e9ecef',
        borderColor: '#495057'
    },
    { 
        name: 'WINE', 
        sizes: [
            '>1L', '1L', '750 ML', '700 ML', '500 ML', '375 ML', '330 ML', 
            '250 ML', '180 ML', '100 ML'
        ],
        internalSizes: [
            '>1L', '1L W', '750 W', '700 W', '500 W', '375 W', '330 W', 
            '250 W', '180 W', '100 W'
        ],
        columnClass: 'size-column',
        bgColor: '#d1ecf1',
        borderColor: '#17a2b8'
    },
    { 
        name: 'FERMENTED BEER', 
        sizes: [
            '>1L', '1L', '750 ML', '650 ML', '500 ML', '375 ML', '330 ML', 
            '275 ML', '250 ML', '180 ML', '90 ML', '60 ML'
        ],
        columnClass: 'size-column',
        bgColor: '#d4edda',
        borderColor: '#28a745'
    },
    { 
        name: 'MILD BEER', 
        sizes: [
            '>1L', '1L', '750 ML', '650 ML', '500 ML', '375 ML', '330 ML', 
            '275 ML', '250 ML', '180 ML', '90 ML', '60 ML'
        ],
        columnClass: 'size-column',
        bgColor: '#f8d7da',
        borderColor: '#dc3545'
    }
];

// Bulk deletion functionality
let selectedPurchases = new Set();
let currentPurchaseId = null;

// Function to update selected count
function updateSelectedPurchaseCount() {
    const count = selectedPurchases.size;
    const deleteBtn = $('#bulkDeleteBtn');
    const deleteBottomBtn = $('#bulkDeleteBottomBtn');
    
    if (count > 0) {
        deleteBtn.prop('disabled', false);
        deleteBtn.html(`<i class="fa-solid fa-trash me-2"></i> Delete Selected (${count})`);
        deleteBottomBtn.prop('disabled', false);
        deleteBottomBtn.html(`<i class="fa-solid fa-trash me-2"></i> Delete Selected (${count})`);
    } else {
        deleteBtn.prop('disabled', true);
        deleteBtn.html('<i class="fa-solid fa-trash me-2"></i> Delete Selected');
        deleteBottomBtn.prop('disabled', true);
        deleteBottomBtn.html('<i class="fa-solid fa-trash me-2"></i> Delete Selected');
    }
}

// Select all purchases
$('#selectAllPurchases').on('change', function() {
    const isChecked = $(this).is(':checked');
    $('.purchase-checkbox').prop('checked', isChecked);
    
    if (isChecked) {
        $('.purchase-checkbox').each(function() {
            selectedPurchases.add($(this).val());
        });
    } else {
        selectedPurchases.clear();
    }
    
    updateSelectedPurchaseCount();
});

// Individual checkbox selection
$(document).on('change', '.purchase-checkbox', function() {
    const purchaseId = $(this).val();
    
    if ($(this).is(':checked')) {
        selectedPurchases.add(purchaseId);
    } else {
        selectedPurchases.delete(purchaseId);
        $('#selectAllPurchases').prop('checked', false);
    }
    
    updateSelectedPurchaseCount();
});

// Bulk delete button click
$('#bulkDeleteBtn, #bulkDeleteBottomBtn').on('click', function() {
    if (selectedPurchases.size === 0) return;
    
    // Show confirmation modal
    $('#deletePurchaseCount').text(selectedPurchases.size);
    $('#bulkDeleteModal').modal('show');
});

// Single delete button click
$(document).on('click', '.delete-single-btn', function() {
    currentPurchaseId = $(this).data('id');
    const purchaseDate = $(this).data('date');
    $('#deleteStartDate').text(purchaseDate);
    $('#deleteModal').modal('show');
});

// Confirm bulk delete
$('#confirmBulkDelete').on('click', function() {
    if (selectedPurchases.size === 0) return;
    
    const purchaseIds = Array.from(selectedPurchases);
    const mode = '<?= $mode ?>';
    
    $('#bulkDeleteModal').modal('hide');
    $('#loadingModal').modal('show');
    
    // Send AJAX request
    $.ajax({
        url: 'purchase_delete.php',
        type: 'POST',
        data: {
            bulk_delete: true,
            purchase_ids: JSON.stringify(purchaseIds),
            mode: mode
        },
        success: function(response) {
            $('#loadingModal').modal('hide');
            
            if (response.success) {
                showAlert('success', response.message);
                selectedPurchases.clear();
                updateSelectedPurchaseCount();
                
                // Reload page after delay
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showAlert('danger', response.message);
            }
        },
        error: function(xhr, status, error) {
            $('#loadingModal').modal('hide');
            showAlert('danger', 'Error deleting purchases: ' + error);
        }
    });
});

// Confirm single delete
$('#deleteConfirmBtn').on('click', function() {
    if (!currentPurchaseId) return;
    
    const mode = '<?= $mode ?>';
    
    $('#deleteModal').modal('hide');
    $('#loadingModal').modal('show');
    
    $.ajax({
        url: 'purchase_delete.php',
        type: 'POST',
        data: {
            purchase_id: currentPurchaseId,
            mode: mode
        },
        success: function(response) {
            $('#loadingModal').modal('hide');
            
            if (response.success) {
                showAlert('success', response.message);
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showAlert('danger', response.message);
            }
        },
        error: function(xhr, status, error) {
            $('#loadingModal').modal('hide');
            showAlert('danger', 'Error deleting purchase: ' + error);
        }
    });
});

// Reset currentPurchaseId when modal is closed
$('#deleteModal').on('hidden.bs.modal', function() {
    currentPurchaseId = null;
});

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
                <small class="text-muted">This may take a moment for large date ranges</small>
             </td>
         </tr>
    `);
    
    // Show scroll hints
    $('#horizontalHint, #verticalHint').hide();
    
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

// Function to update the purchase summary table with TP-wise data (dynamic filtering)
function updatePurchaseSummaryTable(summaryData) {
    const tbody = $('#purchaseSummaryTable tbody');
    
    // Clear existing table
    $('#purchaseSummaryTable thead').empty();
    tbody.empty();

    // First, analyze which categories and sizes actually have data
    const activeCategories = {};
    const activeSizesByCategory = {};
    
    // Initialize tracking for all categories
    categories.forEach(category => {
        activeSizesByCategory[category.name] = new Set();
        activeCategories[category.name] = false;
    });
    
    // Analyze data to find active categories and sizes
    Object.values(summaryData).forEach(tpData => {
        if (tpData && tpData.categories) {
            categories.forEach(category => {
                const catData = tpData.categories[category.name];
                if (catData) {
                    let hasDataInCategory = false;
                    
                    category.sizes.forEach((size, sizeIndex) => {
                        // For wine, use internal size key for data lookup
                        let dataSize = size;
                        if (category.internalSizes && category.internalSizes[sizeIndex]) {
                            dataSize = category.internalSizes[sizeIndex];
                        }
                        
                        const value = catData[dataSize] || 0;
                        if (value > 0) {
                            hasDataInCategory = true;
                            activeSizesByCategory[category.name].add(size);
                        }
                    });
                    
                    if (hasDataInCategory) {
                        activeCategories[category.name] = true;
                    }
                }
            });
        }
    });
    
    // Filter categories to only those with data
    const filteredCategories = categories.filter(category => activeCategories[category.name]);
    
    // If no categories have data, show empty state
    if (filteredCategories.length === 0) {
        const totalColumns = 1; // Just TP column
        tbody.html(`
             <tr>
                <td colspan="${totalColumns}" class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-3"></i><br>
                    <h5>No Data Found</h5>
                    <p class="mb-0">No purchase data found for the selected date range</p>
                 </td>
             </tr>
        `);
        $('#tpCount').text('0');
        return;
    }
    
    // Calculate total columns based on filtered categories
    let totalSizeColumns = 0;
    filteredCategories.forEach(cat => {
        const activeSizes = activeSizesByCategory[cat.name];
        const sizeCount = cat.sizes.filter(size => activeSizes.has(size)).length;
        totalSizeColumns += sizeCount;
    });
    const totalColumns = totalSizeColumns + 1; // +1 for TP column
    
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
    
    // Add category headers with colspan (only for categories with data)
    filteredCategories.forEach((category, index) => {
        const activeSizes = activeSizesByCategory[category.name];
        const sizeCount = category.sizes.filter(size => activeSizes.has(size)).length;
        
        if (sizeCount > 0) {
            const headerCell = $('<th>')
                .attr('colspan', sizeCount)
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
        }
    });
    
    // Create size header row (only for active sizes)
    const sizeHeaderRow = $('<tr>').addClass('summary-size-header');
    
    filteredCategories.forEach((category, catIndex) => {
        const activeSizes = activeSizesByCategory[category.name];
        const categorySizes = category.sizes.filter(size => activeSizes.has(size));
        
        categorySizes.forEach((size, sizeIndex) => {
            const isLargeSizeColumn = size === '>1L';
            const isFirstColumnInCategory = sizeIndex === 0;
            const isLastColumnInCategory = sizeIndex === categorySizes.length - 1;
            
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
                    'border-right': isLastColumnInCategory && catIndex === filteredCategories.length - 1 ? '1px solid #dee2e6' : '1px solid #dee2e6'
                });
            
            sizeHeaderRow.append(sizeCell);
        });
    });
    
    $('#purchaseSummaryTable thead').append(mainHeaderRow, sizeHeaderRow);
    
    // Create rows for each TP number
    let serialNumber = 1;
    const tpNumbers = Object.keys(summaryData);
    
    tpNumbers.forEach((tpNo) => {
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
        
        // Add data for each filtered category and active size
        filteredCategories.forEach((category, catIndex) => {
            const activeSizes = activeSizesByCategory[category.name];
            const categorySizes = category.sizes.filter(size => activeSizes.has(size));
            
            categorySizes.forEach((size, sizeIndex) => {
                const isLargeSizeColumn = size === '>1L';
                const isFirstColumnInCategory = sizeIndex === 0;
                
                let value = 0;
                
                // For wine, use internal size key for data lookup
                let dataSize = size;
                const sizeIndexInFullList = category.sizes.indexOf(size);
                if (category.internalSizes && category.internalSizes[sizeIndexInFullList]) {
                    dataSize = category.internalSizes[sizeIndexInFullList];
                }
                
                // Check if data exists for this category and size
                if (tpData.categories && 
                    tpData.categories[category.name] && 
                    tpData.categories[category.name][dataSize]) {
                    value = tpData.categories[category.name][dataSize];
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
    
    // Add total row (only for active categories and sizes)
    addTotalRowDynamic(summaryData, filteredCategories, activeSizesByCategory);
    
    // Update statistics
    $('#tpCount').text(tpNumbers.length);
    
    // Show scroll hints if table is large
    setTimeout(() => {
        const tableContainer = $('#summaryTableContainer');
        const table = $('#purchaseSummaryTable');
        
        if (table.width() > tableContainer.width()) {
            $('#horizontalHint').show();
        }
        
        if (table.height() > tableContainer.height()) {
            $('#verticalHint').show();
        }
        
        // Auto-hide hints after 5 seconds
        setTimeout(() => {
            $('#horizontalHint, #verticalHint').fadeOut();
        }, 5000);
    }, 500);
}

// Function to add total row dynamically for active categories and sizes
function addTotalRowDynamic(summaryData, filteredCategories, activeSizesByCategory) {
    const totals = {};
    
    // Initialize totals for active categories and sizes
    filteredCategories.forEach(category => {
        totals[category.name] = {};
        const activeSizes = activeSizesByCategory[category.name];
        category.sizes.forEach(size => {
            if (activeSizes.has(size)) {
                totals[category.name][size] = 0;
            }
        });
    });
    
    // Calculate totals
    Object.values(summaryData).forEach(tpData => {
        if (tpData && tpData.categories) {
            filteredCategories.forEach(category => {
                const activeSizes = activeSizesByCategory[category.name];
                
                category.sizes.forEach((size, sizeIndex) => {
                    if (!activeSizes.has(size)) return;
                    
                    // For wine, use internal size key for data lookup
                    let dataSize = size;
                    if (category.internalSizes && category.internalSizes[sizeIndex]) {
                        dataSize = category.internalSizes[sizeIndex];
                    }
                    
                    if (tpData.categories[category.name] && tpData.categories[category.name][dataSize]) {
                        totals[category.name][size] += tpData.categories[category.name][dataSize];
                    }
                });
            });
        }
    });
    
    // Check if we have any totals
    let hasTotals = false;
    filteredCategories.forEach(category => {
        const activeSizes = activeSizesByCategory[category.name];
        category.sizes.forEach(size => {
            if (activeSizes.has(size) && totals[category.name][size] > 0) {
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
        
        filteredCategories.forEach((category, catIndex) => {
            const activeSizes = activeSizesByCategory[category.name];
            const categorySizes = category.sizes.filter(size => activeSizes.has(size));
            
            categorySizes.forEach((size, sizeIndex) => {
                const isLargeSizeColumn = size === '>1L';
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
                    Categories: SPIRITS | WINE | FERMENTED BEER | MILD BEER<br>
                    Only categories and sizes with data are displayed
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
    const fileDropZone = $('#fileDropZone');
    const importForm = $('#importForm');
    const importSubmit = $('#importSubmit');
    const selectedFilesDiv = $('#selectedFiles');
    
    // Drag and drop events
    fileDropZone.on('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).addClass('dragover');
    });
    
    fileDropZone.on('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('dragover');
    });
    
    fileDropZone.on('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        $(this).removeClass('dragover');
        
        const files = e.originalEvent.dataTransfer.files;
        if (files.length > 0) {
            // Use DataTransfer to set files to the input
            const dataTransfer = new DataTransfer();
            for (let i = 0; i < files.length; i++) {
                if (files[i].name.toLowerCase().endsWith('.csv')) {
                    dataTransfer.items.add(files[i]);
                }
            }
            fileInput[0].files = dataTransfer.files;
            
            // Trigger change event
            fileInput.trigger('change');
        }
    });
    
    // Click on drop zone also opens file dialog
    fileDropZone.on('click', function(e) {
        if (e.target !== fileInput[0] && !$(e.target).is('label')) {
            fileInput.trigger('click');
        }
    });
    
    // File selected - show validation
    fileInput.on('change', function() {
        const files = this.files;
        const maxFiles = 50; // Match PHP max_file_uploads
        
        // Check if too many files selected
        if (files.length > maxFiles) {
            alert('You can only select up to ' + maxFiles + ' files at a time. Please select fewer files.');
            $(this).val('');
            selectedFilesDiv.html('<small class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Please select up to ' + maxFiles + ' files</small>');
            return;
        }
        
        selectedFilesDiv.empty();
        
        if (files.length > 0) {
            let validFiles = true;
            let fileListHtml = '<ul class="list-group mb-2" style="max-height: 150px; overflow-y: auto;">';
            
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                const fileSize = (file.size / 1024 / 1024).toFixed(2); // MB
                
                // Check file size (10MB max)
                if (fileSize > 10) {
                    alert('File "' + file.name + '" exceeds 10MB limit. Please select smaller files.');
                    validFiles = false;
                    continue;
                }
                
                // Check file extension - ONLY CSV
                const fileName = file.name.toLowerCase();
                if (!fileName.match(/\.csv$/)) {
                    alert('Please select only CSV files (.csv). File "' + file.name + '" is not a CSV.');
                    validFiles = false;
                    continue;
                }
                
                fileListHtml += '<li class="list-group-item d-flex justify-content-between align-items-center py-1">';
                fileListHtml += '<small><i class="fas fa-file-csv me-2"></i>' + file.name + '</small>';
                fileListHtml += '<span class="badge bg-secondary rounded-pill">' + fileSize + ' MB</span>';
                fileListHtml += '</li>';
                
                console.log('File selected:', file.name, 'Size:', fileSize + 'MB');
            }
            
            fileListHtml += '</ul>';
            
            if (validFiles && files.length > 0) {
                selectedFilesDiv.html(fileListHtml + '<small class="text-success"><i class="fas fa-check-circle me-1"></i>' + files.length + ' file(s) selected ready for import</small>');
            } else {
                $(this).val(''); // Clear file input if invalid
                selectedFilesDiv.html('<small class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>Please select valid CSV files</small>');
            }
        }
    });
    
    // Form submission
    importForm.on('submit', function(e) {
        const files = fileInput[0].files;
        if (!files || files.length === 0) {
            e.preventDefault();
            alert('Please select at least one CSV file to upload');
            fileInput.focus();
            return;
        }
        
        // Validate all files
        for (let i = 0; i < files.length; i++) {
            const fileSize = (files[i].size / 1024 / 1024).toFixed(2);
            if (fileSize > 10) {
                e.preventDefault();
                alert('File "' + files[i].name + '" exceeds 10MB limit. Please select smaller files.');
                importSubmit.html('<i class="fas fa-upload me-2"></i> Import Data').prop('disabled', false);
                return;
            }
        }
        
        // Show loading
        importSubmit.html('<i class="fas fa-spinner fa-spin me-2"></i> Importing ' + files.length + ' file(s)...').prop('disabled', true);
    });
    
    // Reset button state when modal is hidden
    $('#importPurchaseModal').on('hidden.bs.modal', function() {
        importSubmit.html('<i class="fas fa-upload me-2"></i> Import CSV Data').prop('disabled', false);
        // Clear file input
        fileInput.val('');
        selectedFilesDiv.empty();
    });
    
    // Initialize purchase summary modal
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
    
    // Reset scroll hints when modal is shown
    $('#purchaseSummaryModal').on('shown.bs.modal', function() {
        $('#horizontalHint, #verticalHint').hide();
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

// Apply filters with date range validation
$('form').on('submit', function(e) {
    const fromDate = $('input[name="from_date"]').val();
    const toDate = $('input[name="to_date"]').val();
    
    if (fromDate && toDate && fromDate > toDate) {
        e.preventDefault();
        showAlert('warning', 'From date cannot be greater than To date');
        return false;
    }
});

// Alert function
function showAlert(type, message) {
    $('.alert').alert('close');
    
    const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            <i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'} me-2"></i> 
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    $('.content-area').prepend(alertHtml);
}

// Add keyboard navigation for purchase summary table
$(document).on('keydown', function(e) {
    // Only handle when purchase summary modal is open
    if ($('#purchaseSummaryModal').hasClass('show')) {
        const tableContainer = $('#summaryTableContainer')[0];
        
        if (!tableContainer) return;
        
        switch(e.key) {
            case 'ArrowRight':
                tableContainer.scrollLeft += 50;
                e.preventDefault();
                break;
            case 'ArrowLeft':
                tableContainer.scrollLeft -= 50;
                e.preventDefault();
                break;
            case 'ArrowDown':
                tableContainer.scrollTop += 50;
                e.preventDefault();
                break;
            case 'ArrowUp':
                tableContainer.scrollTop -= 50;
                e.preventDefault();
                break;
            case 'Home':
                if (e.ctrlKey) {
                    tableContainer.scrollTop = 0;
                } else {
                    tableContainer.scrollLeft = 0;
                }
                e.preventDefault();
                break;
            case 'End':
                if (e.ctrlKey) {
                    tableContainer.scrollTop = tableContainer.scrollHeight;
                } else {
                    tableContainer.scrollLeft = tableContainer.scrollWidth;
                }
                e.preventDefault();
                break;
        }
    }
});
</script>
</body>
</html>