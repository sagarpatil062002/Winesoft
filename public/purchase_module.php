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

// Build query with filters - FIXED: Better handling of ALL mode
$whereConditions = ["p.CompID = ?"];
$params = [$companyId];
$paramTypes = "i";

// Handle mode filter - FIXED: Better logic for mode handling
if ($mode !== 'ALL') {
    $whereConditions[] = "p.PUR_FLAG = ?";
    $params[] = $mode;
    $paramTypes .= "s";
} else {
    // For ALL mode, include all possible PUR_FLAG values
    $whereConditions[] = "p.PUR_FLAG IN ('F', 'T', 'P', 'C')";
}

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

// Get all purchases for this company with filters - FIXED: Better query construction
$purchases = [];
$purchaseQuery = "SELECT p.*, s.DETAILS as supplier_name 
                  FROM tblpurchases p 
                  LEFT JOIN tblsupplier s ON p.SUBCODE = s.CODE
                  WHERE " . implode(" AND ", $whereConditions) . "
                  ORDER BY CAST(SUBSTRING(p.VOC_NO, 4) AS UNSIGNED) DESC, p.VOC_NO DESC";
                  
error_log("Final Query: " . $purchaseQuery);
error_log("Parameters: " . print_r($params, true));
error_log("Parameter Types: " . $paramTypes);
error_log("Number of where conditions: " . count($whereConditions));

// FIXED: Better query execution with error handling
$purchaseStmt = $conn->prepare($purchaseQuery);
if (!$purchaseStmt) {
    error_log("QUERY PREPARE FAILED: " . $conn->error);
    $queryError = "Query preparation failed: " . $conn->error;
} else {
    // Only bind parameters if we have them
    if (!empty($params)) {
        // Debug the binding
        error_log("Binding parameters: " . print_r($params, true));
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
            
            // Log first few purchases for debugging
            if (count($purchases) > 0) {
                error_log("Sample purchase data (first 3):");
                for ($i = 0; $i < min(3, count($purchases)); $i++) {
                    error_log("Purchase " . ($i+1) . ": ID=" . $purchases[$i]['ID'] . 
                             ", VOC_NO=" . $purchases[$i]['VOC_NO'] . 
                             ", PUR_FLAG=" . $purchases[$i]['PUR_FLAG'] . 
                             ", DATE=" . $purchases[$i]['DATE']);
                }
            } else {
                error_log("No purchases found with current filters");
                
                // Debug: Let's check what's actually in the database
                $debugQuery = "SELECT COUNT(*) as total_count, 
                              SUM(CASE WHEN PUR_FLAG = 'T' THEN 1 ELSE 0 END) as unpaid_count,
                              SUM(CASE WHEN PUR_FLAG = 'P' THEN 1 ELSE 0 END) as partial_count,
                              SUM(CASE WHEN PUR_FLAG = 'C' THEN 1 ELSE 0 END) as completed_count,
                              SUM(CASE WHEN PUR_FLAG = 'F' THEN 1 ELSE 0 END) as final_count
                              FROM tblpurchases WHERE CompID = ?";
                $debugStmt = $conn->prepare($debugQuery);
                $debugStmt->bind_param("i", $companyId);
                $debugStmt->execute();
                $debugResult = $debugStmt->get_result();
                $debugData = $debugResult->fetch_assoc();
                error_log("Database counts - Total: " . $debugData['total_count'] . 
                         ", Unpaid(T): " . $debugData['unpaid_count'] . 
                         ", Partial(P): " . $debugData['partial_count'] . 
                         ", Completed(C): " . $debugData['completed_count'] . 
                         ", Final(F): " . $debugData['final_count']);
                $debugStmt->close();
            }
        } else {
            error_log("GET RESULT FAILED: " . $purchaseStmt->error);
        }
    }
    $purchaseStmt->close();
}

error_log("=== PURCHASE MODULE DEBUG END ===");
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Purchase Module - <?= 
    $mode === 'ALL' ? 'All Purchases' : 
    ($mode === 'F' ? 'Foreign Liquor' : 
    ($mode === 'T' ? 'Unpaid Purchases' : 
    ($mode === 'P' ? 'Partial Payments' : 
    ($mode === 'C' ? 'Completed Purchases' : 'Unknown')))) 
?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
<style>
  .table-container{overflow-x:auto;max-height:520px}
  table.styled-table{width:100%;border-collapse:collapse}
  .styled-table th,.styled-table td{border:1px solid #e5e7eb;padding:8px 10px}
  .styled-table thead th{position:sticky;top:0;background:#f8fafc;z-index:1}
  .action-buttons{display:flex;gap:5px}
  .status-badge{padding:4px 8px;border-radius:4px;font-size:0.8rem}
  .status-completed{background:#d1fae5;color:#065f46}
  .status-unpaid{background:#fef3c7;color:#92400e}
  .status-partial{background:#dbeafe;color:#1e40af}
  
  /* Purchase type navigation styles */
  .purchase-type-nav {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
  }
  .purchase-type-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
  }
  .purchase-type-btn {
    padding: 10px 20px;
    border: 2px solid #dee2e6;
    border-radius: 6px;
    background: white;
    color: #495057;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.3s ease;
    min-width: 140px;
    text-align: center;
  }
  .purchase-type-btn:hover {
    border-color: #0d6efd;
    color: #0d6efd;
    background: #f8f9ff;
  }
  .purchase-type-btn.active {
    background: #0d6efd;
    border-color: #0d6efd;
    color: white;
  }
  
  /* Purchase Summary Table Styles */
  #purchaseSummaryTable th {
    font-size: 11px;
    padding: 4px 2px;
    text-align: center;
    white-space: nowrap;
  }
  #purchaseSummaryTable td {
    font-size: 11px;
    padding: 4px 2px;
    text-align: center;
  }
  .table-success {
    background-color: #d1edff !important;
    font-weight: bold;
  }
  
  /* Debug panel styles */
  .debug-panel {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 20px;
    font-family: monospace;
    font-size: 12px;
  }
  .debug-toggle {
    cursor: pointer;
    color: #0d6efd;
    text-decoration: underline;
  }
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <!-- Debug Information Panel -->
      <div class="debug-panel">
        <div class="debug-toggle" onclick="toggleDebug()">
          <i class="fa-solid fa-bug"></i> Debug Information (Click to toggle)
        </div>
        <div id="debugContent" style="display: none; margin-top: 10px;">
          <strong>Debug Info:</strong><br>
          Company ID: <?= $companyId ?><br>
          Mode: <?= $mode ?><br>
          Purchases Found: <?= count($purchases) ?><br>
          <?php if (isset($queryError)): ?>Query Error: <?= $queryError ?><br><?php endif; ?>
          <?php if (isset($executeError)): ?>Execute Error: <?= $executeError ?><br><?php endif; ?>
        </div>
      </div>

      <!-- Purchase Type Navigation -->
      <div class="purchase-type-nav">
        <div class="purchase-type-buttons">
          <a href="purchase_module.php?mode=ALL" class="purchase-type-btn <?= $mode === 'ALL' ? 'active' : '' ?>">
            <i class="fa-solid fa-list me-1"></i> All Purchases
          </a>
          <a href="purchase_module.php?mode=F" class="purchase-type-btn <?= $mode === 'F' ? 'active' : '' ?>">
            <i class="fa-solid fa-wine-bottle me-1"></i> Foreign Liquor
          </a>
          <a href="purchase_module.php?mode=T" class="purchase-type-btn <?= $mode === 'T' ? 'active' : '' ?>">
            <i class="fa-solid fa-clock me-1"></i> Unpaid
          </a>
          <a href="purchase_module.php?mode=P" class="purchase-type-btn <?= $mode === 'P' ? 'active' : '' ?>">
            <i class="fa-solid fa-percent me-1"></i> Partial
          </a>
          <a href="purchase_module.php?mode=C" class="purchase-type-btn <?= $mode === 'C' ? 'active' : '' ?>">
            <i class="fa-solid fa-check me-1"></i> Completed
          </a>
        </div>
      </div>

      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
          <?= 
            $mode === 'ALL' ? 'All Purchase Records' : 
            ($mode === 'F' ? 'Foreign Liquor Purchases' : 
            ($mode === 'T' ? 'Unpaid Purchases' : 
            ($mode === 'P' ? 'Partial Payment Purchases' : 
            ($mode === 'C' ? 'Completed Purchases' : 'Purchase Records')))) 
          ?>
        </h4>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#purchaseSummaryModal">
            <i class="fas fa-chart-bar"></i> Purchase Summary
          </button>
          <a href="purchases.php?mode=<?=$mode === 'ALL' ? 'F' : $mode?>" class="btn btn-primary">
            <i class="fa-solid fa-plus me-1"></i> New Purchase
          </a>
        </div>
      </div>

      <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> Purchase saved successfully!
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- Filter Section -->
      <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="fa-solid fa-filter me-2"></i>Filters</div>
        <div class="card-body">
          <form method="GET" class="row g-3">
            <input type="hidden" name="mode" value="<?=$mode?>">
            <div class="col-md-3">
              <label class="form-label">From Date</label>
              <input type="date" class="form-control" name="from_date" value="<?=isset($_GET['from_date']) ? $_GET['from_date'] : ''?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">To Date</label>
              <input type="date" class="form-control" name="to_date" value="<?=isset($_GET['to_date']) ? $_GET['to_date'] : ''?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Voucher No.</label>
              <input type="text" class="form-control" name="voc_no" value="<?=isset($_GET['voc_no']) ? $_GET['voc_no'] : ''?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">Supplier</label>
              <input type="text" class="form-control" name="supplier" value="<?=isset($_GET['supplier']) ? $_GET['supplier'] : ''?>">
            </div>
            <div class="col-12">
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-filter me-1"></i> Apply Filters</button>
              <a href="purchase_module.php?mode=<?=$mode?>" class="btn btn-secondary"><i class="fa-solid fa-times me-1"></i> Clear</a>
            </div>
          </form>
        </div>
      </div>

      <!-- Purchases List -->
      <div class="card">
        <div class="card-header fw-semibold"><i class="fa-solid fa-list me-2"></i>Purchase Records</div>
        <div class="card-body">
          <?php if (count($purchases) > 0): ?>
            <div class="table-container">
              <table class="styled-table">
                <thead>
                  <tr>
                    <th>Voucher No.</th>
                    <th>Date</th>
                    <th>Supplier</th>
                    <th>Invoice No.</th>
                    <th>Invoice Date</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($purchases as $purchase): 
                    // UPDATED: Correct status mapping
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
                      <td><?=htmlspecialchars($purchase['VOC_NO'])?></td>
                      <td><?=htmlspecialchars($purchase['DATE'])?></td>
                      <td><?=htmlspecialchars($purchase['supplier_name'])?></td>
                      <td><?=htmlspecialchars($purchase['INV_NO'])?></td>
                      <td><?=htmlspecialchars($purchase['INV_DATE'])?></td>
                      <td>₹<?=number_format($purchase['TAMT'], 2)?></td>
                      <td>
                        <span class="status-badge <?=$statusClass?>"><?=$status?></span>
                      </td>
                      <td>
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
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-center py-4">
              <i class="fa-solid fa-inbox fa-3x text-muted mb-3"></i>
              <h5 class="text-muted">No purchases found</h5>
              <p class="text-muted">Get started by creating your first purchase</p>
              <a href="purchases.php?mode=F" class="btn btn-primary mt-2">
                <i class="fa-solid fa-plus me-1"></i> Create Purchase
              </a>
            </div>
          <?php endif; ?>
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
                    <div class="col-md-4">
                        <label class="form-label">From Date</label>
                        <input type="date" id="purchaseFromDate" class="form-control" value="<?= date('Y-m-01') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">To Date</label>
                        <input type="date" id="purchaseToDate" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Purchase Type</label>
                        <select class="form-select" id="purchaseType">
                            <option value="ALL">All Types</option>
                            <option value="F">Final (F)</option>
                            <option value="T">Unpaid (T)</option>
                            <option value="P">Partial (P)</option>
                            <option value="C">Completed (C)</option>
                        </select>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12">
                        <button type="button" class="btn btn-primary w-100" onclick="loadPurchaseSummary()">
                            <i class="fas fa-refresh"></i> Update Summary
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive" style="max-height: 400px;">
                    <table class="table table-bordered table-sm table-striped" id="purchaseSummaryTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Category</th>
                                <th>50 ML</th>
                                <th>60 ML</th>
                                <th>90 ML</th>
                                <th>170 ML</th>
                                <th>180 ML</th>
                                <th>200 ML</th>
                                <th>250 ML</th>
                                <th>275 ML</th>
                                <th>330 ML</th>
                                <th>355 ML</th>
                                <th>375 ML</th>
                                <th>500 ML</th>
                                <th>650 ML</th>
                                <th>700 ML</th>
                                <th>750 ML</th>
                                <th>1000 ML</th>
                                <th>1.5L</th>
                                <th>1.75L</th>
                                <th>2L</th>
                                <th>3L</th>
                                <th>4.5L</th>
                                <th>15L</th>
                                <th>20L</th>
                                <th>30L</th>
                                <th>50L</th>
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

<!-- Delete Confirmation Modal -->
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
function toggleDebug() {
    $('#debugContent').toggle();
}

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

// Enhanced debug function for AJAX
function debugAjax(message, data = null) {
    console.log('DEBUG: ' + message, data || '');
}

// Function to load purchase summary via AJAX
function loadPurchaseSummary() {
    const fromDate = $('#purchaseFromDate').val();
    const toDate = $('#purchaseToDate').val();
    const purchaseType = $('#purchaseType').val();
    
    debugAjax('Loading purchase summary', {fromDate, toDate, purchaseType});
    
    // Show proper loading state
    $('#purchaseSummaryTable tbody').html(`
        <tr>
            <td colspan="26" class="text-center">
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
            comp_id: '<?= $companyId ?>',
            debug: true
        },
        success: function(response) {
            debugAjax('AJAX Response received', response);
            
            try {
                // Handle case where response might be a string that needs parsing
                let summaryData;
                if (typeof response === 'string') {
                    // Try to parse JSON if it's a string
                    if (response.trim() === '') {
                        throw new Error('Empty response from server');
                    }
                    summaryData = JSON.parse(response);
                } else {
                    // It's already an object
                    summaryData = response;
                }
                
                if (summaryData.error) {
                    throw new Error(summaryData.error);
                }
                
                debugAjax('Parsed summary data', summaryData);
                updatePurchaseSummaryTable(summaryData);
            } catch (e) {
                console.error('Error parsing response:', e);
                debugAjax('Error parsing response', e.message);
                $('#purchaseSummaryTable tbody').html(`
                    <tr>
                        <td colspan="26" class="text-center text-danger">
                            <i class="fas fa-exclamation-triangle"></i><br>
                            Error loading purchase summary<br>
                            <small>${e.message}</small>
                            <br><small>Check browser console for details</small>
                        </td>
                    </tr>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', status, error);
            debugAjax('AJAX Error', {status: status, error: error, responseText: xhr.responseText});
            
            let errorMessage = 'Failed to load purchase summary';
            if (xhr.responseText) {
                // Try to extract error message from response
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse.error) {
                        errorMessage = errorResponse.error;
                    }
                } catch (e) {
                    // If not JSON, show raw response
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

// Function to update the purchase summary table
function updatePurchaseSummaryTable(summaryData) {
    debugAjax('Updating summary table', summaryData);
    
    const tbody = $('#purchaseSummaryTable tbody');
    tbody.empty();
    
    const allSizes = [
        '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML', 
        '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
        '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
    ];
    
    // UPDATED: Added COUNTRY LIQUOR category
    const categories = ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER', 'COUNTRY LIQUOR'];
    
    // Check if we have valid data structure
    if (!summaryData || typeof summaryData !== 'object') {
        tbody.html(`
            <tr>
                <td colspan="26" class="text-center text-danger">
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
        debugAjax('No data found in summary');
        tbody.html(`
            <tr>
                <td colspan="26" class="text-center text-muted">
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
    
    debugAjax('Summary table updated successfully');
    
    // Add a total row if needed
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
    
    // Check if we have any totals
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
    const purchaseType = $('#purchaseType').val();
    const typeLabel = purchaseType === 'ALL' ? 'All Purchases' : 
                     purchaseType === 'F' ? 'Foreign Liquor' : 
                     purchaseType === 'T' ? 'Unpaid Purchases' : 
                     purchaseType === 'P' ? 'Partial Payments' : 'Completed Purchases';
    const fromDate = $('#purchaseFromDate').val();
    const toDate = $('#purchaseToDate').val();
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Purchase Summary - ${typeLabel}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; font-size: 12px; }
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
    // Set default dates if not set
    if (!$('#purchaseFromDate').val()) {
        $('#purchaseFromDate').val('<?= date('Y-m-01') ?>');
    }
    if (!$('#purchaseToDate').val()) {
        $('#purchaseToDate').val('<?= date('Y-m-d') ?>');
    }
    
    // Load initial summary
    loadPurchaseSummary();
});

// Log page load completion
debugAjax('Page loaded successfully');
</script>
</body>
</html>