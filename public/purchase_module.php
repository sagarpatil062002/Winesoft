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
  
  /* Purchase Summary Table Styles */
  #purchaseSummaryTable th {
    font-size: 10px;
    padding: 2px 1px;
    text-align: center;
    white-space: nowrap;
  }

  #purchaseSummaryTable td {
    font-size: 10px;
    padding: 2px 1px;
    text-align: center;
  }
  
  .table-success {
    background-color: #d1edff !important;
    font-weight: bold;
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
                              onclick="confirmDelete(<?=htmlspecialchars($purchase['ID'])?>, '<?=htmlspecialchars($mode)?>')">
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
                <h5 class="modal-title" id="purchaseSummaryModalLabel">Purchase Summary - All Purchases</h5>
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
                                <th>Category</th>
                                <!-- Size headers will be dynamically generated -->
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="26" class="text-center text-muted">
                                    Click "Update Summary" to load data
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

<!-- Delete Confirmation Modal - ADDED FROM COPY -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete this purchase? This action cannot be undone.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <a href="#" id="deleteConfirm" class="btn btn-danger">Delete</a>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Delete Confirmation Function - ADDED FROM COPY
function confirmDelete(purchaseId, mode) {
  $('#deleteConfirm').attr('href', 'purchase_delete.php?id=' + purchaseId + '&mode=' + mode);
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

// Function to load purchase summary via AJAX
function loadPurchaseSummary() {
    const fromDate = $('#purchaseFromDate').val();
    const toDate = $('#purchaseToDate').val();
    const purchaseType = 'ALL';

    // Show loading state
    const loadingColspan = 26; // 1 category + 25 sizes
    $('#purchaseSummaryTable tbody').html(`
        <tr>
            <td colspan="${loadingColspan}" class="text-center">
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
                        <td colspan="26" class="text-center text-danger">
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
                    <td colspan="26" class="text-center text-danger">
                        <i class="fas fa-exclamation-triangle"></i><br>
                        ${errorMessage}<br>
                        <small>Status: ${status}, Error: ${error}</small>
                    </td>
                </tr>
            `);
        }
    });
}

// Function to convert size string to milliliters for sorting
function sizeToMl(size) {
    if (size.includes('L')) {
        const liters = parseFloat(size.replace('L', ''));
        return liters * 1000;
    } else if (size.includes('ML')) {
        return parseInt(size.replace(' ML', ''));
    }
    return 0;
}

// Function to update the purchase summary table
function updatePurchaseSummaryTable(summaryData) {
    const tbody = $('#purchaseSummaryTable tbody');
    const headerRow = $('#sizeHeaders');
    tbody.empty();

    const allSizes = [
        '50L', '30L', '20L', '15L', '4.5L', '3L', '2L', '1.75L', '1.5L',
        '1000 ML', '750 ML', '700 ML', '650 ML', '500 ML', '375 ML', '355 ML', '330 ML',
        '275 ML', '250 ML', '200 ML', '180 ML', '170 ML', '90 ML', '60 ML', '50 ML'
    ];

    // Update table headers dynamically
    headerRow.empty();
    headerRow.append($('<th>').text('Category'));
    allSizes.forEach(size => {
        headerRow.append($('<th>').text(size));
    });
    
    const categories = ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER', 'COUNTRY LIQUOR'];
    
    if (!summaryData || typeof summaryData !== 'object') {
        tbody.html(`
            <tr>
                <td colspan="${allSizes.length + 1}" class="text-center text-danger">
                    <i class="fas fa-exclamation-triangle"></i><br>
                    Invalid data structure received
                </td>
            </tr>
        `);
        return;
    }
    
    // Check if we have any data
    let hasData = false;
    categories.forEach(category => {
        if (summaryData[category]) {
            allSizes.forEach(size => {
                if (summaryData[category][size] > 0) {
                    hasData = true;
                }
            });
        }
    });
    
    if (!hasData) {
        tbody.html(`
            <tr>
                <td colspan="${allSizes.length + 1}" class="text-center text-muted">
                    <i class="fas fa-info-circle"></i><br>
                    No purchase data found for the selected date range and filters
                </td>
            </tr>
        `);
        return;
    }
    
    // Create rows for each category
    categories.forEach(category => {
        const row = $('<tr>');
        row.append($('<td>').addClass('fw-semibold').text(category));
        
        allSizes.forEach(size => {
            const value = summaryData[category] && summaryData[category][size] ? summaryData[category][size] : 0;
            const cell = $('<td>').text(value > 0 ? value.toLocaleString() : '');
            
            if (value > 0) {
                cell.addClass('table-success');
            }
            
            row.append(cell);
        });
        
        tbody.append(row);
    });
    
    // Add a total row
    addTotalRow(summaryData, allSizes, categories);
}

// Function to add total row
function addTotalRow(summaryData, allSizes, categories) {
    const totals = {};
    
    allSizes.forEach(size => {
        totals[size] = 0;
        categories.forEach(category => {
            if (summaryData[category] && summaryData[category][size]) {
                totals[size] += summaryData[category][size];
            }
        });
    });
    
    const hasTotals = allSizes.some(size => totals[size] > 0);
    
    if (hasTotals) {
        const totalRow = $('<tr>').addClass('table-primary fw-bold');
        totalRow.append($('<td>').text('TOTAL'));
        
        allSizes.forEach(size => {
            const cell = $('<td>').text(totals[size] > 0 ? totals[size].toLocaleString() : '');
            totalRow.append(cell);
        });
        
        $('#purchaseSummaryTable tbody').append(totalRow);
    }
}

// Function to print purchase summary
function printPurchaseSummary() {
    const printContent = $('#purchaseSummaryTable').parent().html();
    const printWindow = window.open('', '_blank');
    const typeLabel = 'All Purchases';
    const fromDate = $('#purchaseFromDate').val();
    const toDate = $('#purchaseToDate').val();
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Purchase Summary - ${typeLabel}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; font-size: 16px; }
                th, td { border: 1px solid #ddd; padding: 6px; text-align: center; }
                th { background-color: #f8f9fa; font-weight: bold; }
                .table-success { background-color: #d1edff !important; }
                .text-center { text-align: center; }
                h2 { text-align: center; margin-bottom: 20px; }
                .summary-info { text-align: center; margin-bottom: 15px; color: #666; }
                @media print {
                    body { margin: 0; }
                    table { font-size: 10px; }
                }
            </style>
        </head>
        <body>
            <h2>Purchase Summary - ${typeLabel}</h2>
            <div class="summary-info">
                Date Range: ${fromDate} to ${toDate}<br>
                Printed on: ${new Date().toLocaleDateString()}
            </div>
            ${printContent}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
        printWindow.print();
        printWindow.close();
    }, 500);
}

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