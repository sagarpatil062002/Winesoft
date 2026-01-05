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

// Check for success message in URL (ADD THIS SECTION)
if (isset($_GET['success'])) {
    $success_message = urldecode($_GET['success']);
}

// Default view selection - show all records initially
$view_type = isset($_GET['view_type']) ? $_GET['view_type'] : 'all';

// Date selection (default to today)
$Closing_Stock = isset($_GET['Closing_Stock']) ? $_GET['Closing_Stock'] : date('Y-m-d');

// Date range selection (default to current month)
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// Fetch sales records based on selected view
if ($view_type === 'date') {
    // Fetch sales for a specific date
    $query = "SELECT 
                sh.BILL_NO,
                sh.BILL_DATE,
                sh.TOTAL_AMOUNT,
                sh.DISCOUNT,
                sh.NET_AMOUNT,
                sh.LIQ_FLAG,
                COUNT(sd.ITEM_CODE) as item_count
              FROM tblsaleheader sh
              LEFT JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
              WHERE sh.COMP_ID = ? AND sh.BILL_DATE = ?
              GROUP BY sh.BILL_NO
              ORDER BY sh.BILL_NO DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $compID, $Closing_Stock);
    $stmt->execute();
    $result = $stmt->get_result();
    $sales = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} elseif ($view_type === 'range') {
    // Fetch sales for a date range
    $query = "SELECT 
                sh.BILL_NO,
                sh.BILL_DATE,
                sh.TOTAL_AMOUNT,
                sh.DISCOUNT,
                sh.NET_AMOUNT,
                sh.LIQ_FLAG,
                COUNT(sd.ITEM_CODE) as item_count
              FROM tblsaleheader sh
              LEFT JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
              WHERE sh.COMP_ID = ? AND sh.BILL_DATE BETWEEN ? AND ?
              GROUP BY sh.BILL_NO
              ORDER BY sh.BILL_NO DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iss", $compID, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $sales = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // Fetch all sales records (default view) - UPDATED ORDER BY CLAUSE
    $query = "SELECT 
                sh.BILL_NO,
                sh.BILL_DATE,
                sh.TOTAL_AMOUNT,
                sh.DISCOUNT,
                sh.NET_AMOUNT,
                sh.LIQ_FLAG,
                COUNT(sd.ITEM_CODE) as item_count
              FROM tblsaleheader sh
              LEFT JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
              WHERE sh.COMP_ID = ?
              GROUP BY sh.BILL_NO
              ORDER BY sh.BILL_NO DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $compID);
    $stmt->execute();
    $result = $stmt->get_result();
    $sales = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Handle delete action via new delete_bill.php
if (isset($_GET['delete_success'])) {
    $success_message = urldecode($_GET['delete_success']);
}

if (isset($_GET['delete_error'])) {
    $error_message = urldecode($_GET['delete_error']);
}

// Handle success/error messages from session
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error_message = $_SESSION['error'];
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sales Management - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  
  <style>
    /* Enhanced delete confirmation modal styling */
    .delete-confirmation-list {
        max-height: 200px;
        overflow-y: auto;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        padding: 10px;
    }
    
    .delete-confirmation-item {
        padding: 8px 12px;
        margin-bottom: 5px;
        background-color: #f8f9fa;
        border-left: 4px solid #dc3545;
        border-radius: 3px;
    }
    
    .stock-warning-box {
        background-color: #fff3cd;
        border: 1px solid #ffeaa7;
        border-left: 4px solid #f39c12;
        padding: 12px;
        border-radius: 4px;
        margin-top: 15px;
    }
    
    .stock-formula {
        font-family: monospace;
        background-color: #f8f9fa;
        padding: 5px 10px;
        border-radius: 3px;
        font-size: 13px;
        color: #495057;
    }
    
    /* Checkbox selection styles */
    .table-checkbox-cell {
        width: 40px;
        text-align: center;
        vertical-align: middle;
    }
    
    .selected-counter {
        background-color: #198754;
        color: white;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 14px;
        font-weight: 600;
    }
    
    /* Action buttons like purchase_module.php */
    .action-buttons {
        display: flex;
        gap: 3px;
        flex-wrap: nowrap;
    }

    .action-buttons .btn {
        padding: 4px 8px;
        font-size: 12px;
    }
    
    /* Status badges like purchase_module.php */
    .status-badge {
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 12px;
        white-space: nowrap;
    }
    
    .status-foreign { background: #d1fae5; color: #065f46; }
    .status-country { background: #fef3c7; color: #92400e; }
    .status-others { background: #dbeafe; color: #1e40af; }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Create New Sale:</h4>
        <div class="btn-group">
          <a href="closing_stock_for_date_range.php" class="btn btn-primary">
            <i class="fa-solid fa-calendar-day me-1"></i> Closing Stock for Date Range
          </a>
          <a href="sale_for_date_range.php" class="btn btn-primary">
            <i class="fa-solid fa-calendar-week me-1"></i> Sale for Date Range
          </a>

          <!-- NEW: Export to Excel Button -->
          <button type="button" class="btn btn-success" id="exportExcelBtn">
            <i class="fa-solid fa-file-excel me-1"></i> Export to Excel
          </button>
        </div>
      </div>

      <!-- Success/Error Messages Section (UPDATED) -->
      <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> <?= htmlspecialchars($success_message) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-exclamation me-2"></i> <?= htmlspecialchars($error_message) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <!-- View Type Selector -->
      <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="fa-solid fa-filter me-2"></i>View Options</div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-12">
              <label class="form-label">View Type:</label>
              <div class="btn-group view-selector" role="group">
                <a href="?view_type=all" 
                   class="btn btn-outline-primary <?= $view_type === 'all' ? 'view-active' : '' ?>">
                  <i class="fa-solid fa-list me-1"></i> All Sales
                </a>
                <a href="?view_type=date&Closing_Stock=<?= $Closing_Stock ?>" 
                   class="btn btn-outline-primary <?= $view_type === 'date' ? 'view-active' : '' ?>">
                  <i class="fa-solid fa-calendar-day me-1"></i> Closing Stock
                </a>
                <a href="?view_type=range&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" 
                   class="btn btn-outline-primary <?= $view_type === 'range' ? 'view-active' : '' ?>">
                  <i class="fa-solid fa-calendar-week me-1"></i> Sale for Date Range
                </a>
              </div>
            </div>
            
            <?php if ($view_type === 'date'): ?>
            <div class="col-md-4">
              <form method="GET" class="date-selector">
                <input type="hidden" name="view_type" value="date">
                <label class="form-label">Sale Date</label>
                <div class="input-group">
                  <input type="date" name="Closing_Stock" class="form-control" 
                         value="<?= htmlspecialchars($Closing_Stock); ?>" required>
                  <button type="submit" class="btn btn-primary">Go</button>
                </div>
              </form>
            </div>
            <?php elseif ($view_type === 'range'): ?>
            <div class="col-md-8">
              <form method="GET" class="row g-3">
                <input type="hidden" name="view_type" value="range">
                <div class="col-md-4">
                  <label class="form-label">Start Date</label>
                  <input type="date" name="start_date" class="form-control" 
                         value="<?= htmlspecialchars($start_date); ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">End Date</label>
                  <input type="date" name="end_date" class="form-control" 
                         value="<?= htmlspecialchars($end_date); ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">&nbsp;</label>
                  <button type="submit" class="btn btn-primary w-100">Apply Range</button>
                </div>
              </form>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- NEW: Bulk Delete Options -->
      <div class="card mb-4">
        <div class="card-header fw-semibold bg-warning text-dark">
          <i class="fa-solid fa-trash-can me-2"></i>Bulk Delete Options
        </div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="d-flex align-items-center mb-3">
                <div class="form-check me-3">
                  <input class="form-check-input" type="checkbox" id="selectAllBills">
                  <label class="form-check-label fw-semibold" for="selectAllBills">
                    Select All Visible Bills
                  </label>
                </div>
                <button class="btn btn-danger btn-sm" id="deleteSelectedBtn" disabled>
                  <i class="fa-solid fa-trash me-1"></i> Delete Selected
                </button>
              </div>
              <p class="text-muted small mb-0">
                <i class="fa-solid fa-info-circle me-1"></i>
                Select individual bills using checkboxes below, then click "Delete Selected"
              </p>
            </div>
            <div class="col-md-6">
              <form id="deleteByDateForm" class="row g-3">
                <div class="col-md-6">
                  <label class="form-label fw-semibold">Delete Bills by Date</label>
                  <input type="date" name="delete_date" class="form-control" 
                         value="<?= htmlspecialchars($Closing_Stock); ?>">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                  <button type="button" class="btn btn-danger w-100" id="deleteByDateBtn">
                    <i class="fa-solid fa-calendar-xmark me-1"></i> Delete All Bills for Date
                  </button>
                </div>
              </form>
              <p class="text-muted small mb-0 mt-2">
                <i class="fa-solid fa-triangle-exclamation me-1 text-danger"></i>
                This will delete ALL bills for the selected date and renumber subsequent bills
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Sales Records -->
      <div class="card">
        <div class="card-header fw-semibold">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <i class="fa-solid fa-list me-2"></i>
              <?php 
              if ($view_type === 'date') {
                  echo 'Sales Records for ' . date('d-M-Y', strtotime($Closing_Stock));
              } elseif ($view_type === 'range') {
                  echo 'Sales Records from ' . date('d-M-Y', strtotime($start_date)) . ' to ' . date('d-M-Y', strtotime($end_date));
              } else {
                  echo 'All Sales Records';
              }
              ?>
              <span class="badge bg-primary ms-2"><?= count($sales) ?> bills</span>
            </div>
            <div id="selectedCount" class="selected-counter" style="display: none;">
              <i class="fa-solid fa-check-circle me-1"></i>
              <span id="countText">0</span> selected
            </div>
          </div>
        </div>
        <div class="card-body">
          <?php if (count($sales) > 0): ?>
            <div class="table-container">
              <table class="table table-striped table-bordered table-hover styled-table">
                <thead class="sticky-header">
                  <tr>
                    <th class="table-checkbox-cell">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="selectAllTable">
                      </div>
                    </th>
                    <th>Bill No.</th>
                    <th>Date</th>
                    <th>Items</th>
                    <th>Total Amount</th>
                    <th>Discount</th>
                    <th>Net Amount</th>
                    <th>Type</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($sales as $sale): 
                    $liquorType = "Foreign Liquor";
                    $statusClass = "status-foreign";
                    if ($sale['LIQ_FLAG'] === 'C') {
                        $liquorType = "Country Liquor";
                        $statusClass = "status-country";
                    } elseif ($sale['LIQ_FLAG'] === 'O') {
                        $liquorType = "Others";
                        $statusClass = "status-others";
                    }
                  ?>
                    <tr>
                      <td class="table-checkbox-cell">
                        <div class="form-check">
                          <input class="form-check-input bill-checkbox" type="checkbox" 
                                 value="<?= htmlspecialchars($sale['BILL_NO']) ?>"
                                 data-billno="<?= htmlspecialchars($sale['BILL_NO']) ?>"
                                 data-billdate="<?= htmlspecialchars($sale['BILL_DATE']) ?>">
                        </div>
                      </td>
                      <td class="fw-bold"><?= htmlspecialchars($sale['BILL_NO']) ?></td>
                      <td><?= date('d-M-Y', strtotime($sale['BILL_DATE'])) ?></td>
                      <td><span class="badge bg-secondary"><?= htmlspecialchars($sale['item_count']) ?> items</span></td>
                      <td class="fw-bold">₹<?= number_format($sale['TOTAL_AMOUNT'], 2) ?></td>
                      <td>₹<?= number_format($sale['DISCOUNT'], 2) ?></td>
                      <td class="fw-bold text-success">₹<?= number_format($sale['NET_AMOUNT'], 2) ?></td>
                      <td>
                        <span class="status-badge <?= $statusClass ?>"><?= $liquorType ?></span>
                      </td>
                      <td>
                        <div class="action-buttons">
                          <!-- Edit Button - Redirects to edit form -->
                          <a href="edit_bill_form.php?bill_no=<?= $sale['BILL_NO'] ?>" 
                             class="btn btn-sm btn-warning" title="Edit Bill">
                            <i class="fa-solid fa-pen-to-square"></i>
                          </a>
                          
                          <!-- Delete Button - Enhanced like purchase_module.php -->
                          <button class="btn btn-sm btn-danger delete-single-btn" 
                                  title="Delete Bill" 
                                  data-billno="<?= htmlspecialchars($sale['BILL_NO']) ?>"
                                  data-billdate="<?= htmlspecialchars($sale['BILL_DATE']) ?>">
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
              <i class="fa-solid fa-receipt fa-3x text-muted mb-3"></i>
              <h5 class="text-muted">No sales records found</h5>
              <p class="text-muted">Try adjusting your date selection or create a new sale</p>
              <div class="mt-3">
                <a href="sale_for_date.php" class="btn btn-primary me-2">
                  <i class="fa-solid fa-calendar-day me-1"></i> Create Sale for Date
                </a>
                <a href="sale_for_date_range.php" class="btn btn-primary">
                  <i class="fa-solid fa-calendar-week me-1"></i> Create Sale for Date Range
                </a>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Delete Confirmation Modal (Enhanced like purchase_module.php) -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete <strong id="deleteBillCount"></strong>?</p>
        
        <div id="selectedBillsList" class="delete-confirmation-list mb-3"></div>
        
        <!-- Stock Reversal Warning like purchase_module.php -->
        <div class="stock-warning-box">
          <i class="fas fa-exclamation-triangle text-warning me-2"></i> 
          <strong>Stock Reversal Warning:</strong> This action will:
          <ul class="mb-2 mt-2">
            <li>Delete the sale record from tblsaleheader</li>
            <li>Delete all sale details from tblsaledetails</li>
            <li>Update item stock in tblitemstock (add back sold quantities)</li>
            <li>Update daily stock records from the sale date until today</li>
          </ul>
          <p class="text-danger mb-1"><strong>Warning:</strong> This action cannot be undone and will affect stock calculations.</p>
          <div class="stock-formula">
            <strong>Stock Formula:</strong> day_x_closing = day_x_open + day_x_purchase - day_x_sales
          </div>
          <div id="dateRangeWarning" class="mt-2">
            <small><i class="fa-solid fa-calendar me-1"></i> Daily stock will be recalculated from <span id="deleteStartDate"></span> to today</small>
          </div>
        </div>
        
        <div class="alert alert-info mt-3">
          <i class="fa-solid fa-info-circle me-2"></i>
          <strong>Note:</strong> Subsequent bills will be automatically renumbered to maintain sequence.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
          <i class="fa-solid fa-trash me-2"></i>Yes, Delete & Update Stock
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Delete Date Confirmation Modal (Enhanced) -->
<div class="modal fade" id="deleteDateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fa-solid fa-calendar-xmark me-2"></i>Delete All Bills for Date</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete <strong>ALL bills</strong> for <strong id="deleteDateText"></strong>?</p>
        
        <!-- Enhanced stock reversal warning -->
        <div class="stock-warning-box">
          <i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>
          <strong>This will affect stock for ALL items sold on this date:</strong>
          <ul class="mb-2 mt-2">
            <li>All bills for the selected date will be deleted</li>
            <li>All associated sale details will be removed</li>
            <li>Stock will be updated (quantities added back to inventory)</li>
            <li>Daily stock records will be recalculated from this date forward</li>
            <li>Subsequent bills will be renumbered</li>
          </ul>
          <div class="stock-formula">
            <strong>Stock Recovery Formula:</strong> closing_stock = current_stock + sold_quantity
          </div>
          <div class="mt-2">
            <small><i class="fa-solid fa-calendar me-1"></i> Stock will be updated from <span id="deleteDateStart"></span> to today</small>
          </div>
        </div>
        
        <p class="text-danger mt-3">
          <i class="fa-solid fa-skull-crossbones me-2"></i>
          <strong>Extreme Warning:</strong> This action is irreversible. Make sure you have backups.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteDateBtn">
          <i class="fa-solid fa-fire me-2"></i>Delete All & Update Stock
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Loading Spinner Modal -->
<div class="modal fade" id="loadingModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-body text-center py-4">
        <div class="spinner-border text-primary mb-3" role="status">
          <span class="visually-hidden">Loading...</span>
        </div>
        <h6>Processing Stock Update...</h6>
        <p class="text-muted small mb-0" id="loadingMessage">Updating stock and renumbering bills. This may take a moment.</p>
      </div>
    </div>
  </div>
</div>

<!-- Export Options Modal -->
<div class="modal fade" id="exportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-file-excel me-2 text-success"></i>Export Sales Data</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="fa-solid fa-info-circle me-2"></i>
                    Export will include: Sale Date, Local Item Code, Brand Name, Size, and Quantity
                </div>

                <form id="exportForm">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Export Range</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="export_range" id="export_current" value="current" checked>
                            <label class="form-check-label" for="export_current">
                                Current view (<?php
                                if ($view_type === 'date') {
                                    echo date('d-M-Y', strtotime($Closing_Stock));
                                } elseif ($view_type === 'range') {
                                    echo date('d-M-Y', strtotime($start_date)) . ' to ' . date('d-M-Y', strtotime($end_date));
                                } else {
                                    echo 'All Records';
                                }
                                ?>)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="export_range" id="export_custom" value="custom">
                            <label class="form-check-label" for="export_custom">Custom date range</label>
                        </div>
                    </div>

                    <div id="customDateRange" class="mb-3" style="display: none;">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="export_start_date" class="form-control"
                                       value="<?= htmlspecialchars($start_date) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">End Date</label>
                                <input type="date" name="export_end_date" class="form-control"
                                       value="<?= htmlspecialchars($end_date) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">File Name</label>
                        <input type="text" name="export_filename" class="form-control"
                               value="sales_report_<?= date('Y-m-d') ?>" placeholder="Enter file name">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmExport">
                    <i class="fa-solid fa-file-export me-1"></i> Export to Excel
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let selectedBills = new Map(); // Map to store billNo: billDate pairs
let currentBillToDelete = '';
let deleteDate = '';
let earliestDeleteDate = '';

// Update selected count display
function updateSelectedCount() {
    const count = selectedBills.size;
    const countText = $('#countText');
    const deleteSelectedBtn = $('#deleteSelectedBtn');
    const selectedCountDiv = $('#selectedCount');
    
    if (count > 0) {
        countText.text(count);
        selectedCountDiv.show();
        deleteSelectedBtn.prop('disabled', false);
        
        // Find earliest date among selected bills for stock recalculation
        updateEarliestDate();
    } else {
        selectedCountDiv.hide();
        deleteSelectedBtn.prop('disabled', true);
        earliestDeleteDate = '';
    }
}

// Update earliest delete date for stock recalculation
function updateEarliestDate() {
    if (selectedBills.size === 0) {
        earliestDeleteDate = '';
        return;
    }
    
    const dates = Array.from(selectedBills.values());
    earliestDeleteDate = dates.reduce((earliest, current) => {
        return current < earliest ? current : earliest;
    });
}

// Handle individual bill checkbox
$(document).on('change', '.bill-checkbox', function() {
    const billNo = $(this).val();
    const billDate = $(this).data('billdate');
    
    if ($(this).is(':checked')) {
        selectedBills.set(billNo, billDate);
    } else {
        selectedBills.delete(billNo);
        $('#selectAllBills').prop('checked', false);
        $('#selectAllTable').prop('checked', false);
    }
    
    updateSelectedCount();
});

// Select all bills (global checkbox)
$('#selectAllBills').on('change', function() {
    const isChecked = $(this).is(':checked');
    $('.bill-checkbox').prop('checked', isChecked);
    
    if (isChecked) {
        $('.bill-checkbox').each(function() {
            selectedBills.set($(this).val(), $(this).data('billdate'));
        });
    } else {
        selectedBills.clear();
    }
    
    updateSelectedCount();
});

// Select all bills (table header checkbox)
$('#selectAllTable').on('change', function() {
    const isChecked = $(this).is(':checked');
    $('.bill-checkbox').prop('checked', isChecked);
    $('#selectAllBills').prop('checked', isChecked);
    
    if (isChecked) {
        $('.bill-checkbox').each(function() {
            selectedBills.set($(this).val(), $(this).data('billdate'));
        });
    } else {
        selectedBills.clear();
    }
    
    updateSelectedCount();
});

// Delete selected bills
$('#deleteSelectedBtn').on('click', function() {
    if (selectedBills.size === 0) return;
    
    // Build bills list for display
    let billsList = '';
    selectedBills.forEach((billDate, billNo) => {
        const formattedDate = new Date(billDate).toLocaleDateString('en-IN', {
            day: '2-digit',
            month: 'short',
            year: 'numeric'
        });
        billsList += `<div class="delete-confirmation-item">
            <strong>Bill No:</strong> ${billNo} | <strong>Date:</strong> ${formattedDate}
        </div>`;
    });
    
    $('#selectedBillsList').html(billsList);
    $('#deleteBillCount').text(`${selectedBills.size} selected bill(s)`);
    $('#deleteStartDate').text(formatDate(earliestDeleteDate));
    $('#deleteModal').modal('show');
});

// Single bill delete
$(document).on('click', '.delete-single-btn', function() {
    currentBillToDelete = $(this).data('billno');
    const billDate = $(this).data('billdate');
    
    // Build bills list for display
    const formattedDate = new Date(billDate).toLocaleDateString('en-IN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
    
    let billsList = `<div class="delete-confirmation-item">
        <strong>Bill No:</strong> ${currentBillToDelete} | <strong>Date:</strong> ${formattedDate}
    </div>`;
    
    $('#selectedBillsList').html(billsList);
    $('#deleteBillCount').text(`bill ${currentBillToDelete}`);
    $('#deleteStartDate').text(formatDate(billDate));
    $('#deleteModal').modal('show');
});

// Delete all bills for date
$('#deleteByDateBtn').on('click', function() {
    deleteDate = $('input[name="delete_date"]').val();
    
    if (!deleteDate) {
        showAlert('warning', 'Please select a date');
        return;
    }
    
    const formattedDate = new Date(deleteDate).toLocaleDateString('en-IN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
    
    $('#deleteDateText').text(formattedDate);
    $('#deleteDateStart').text(formattedDate);
    $('#deleteDateModal').modal('show');
});

// Confirm delete selected bills
$('#confirmDeleteBtn').on('click', function() {
    $('#deleteModal').modal('hide');
    $('#loadingModal').modal('show');
    
    if (currentBillToDelete) {
        // Single bill delete
        $('#loadingMessage').text('Deleting bill and updating stock...');
        
        // Send POST request for single bill
        $.ajax({
            url: 'delete_bill.php',
            method: 'POST',
            data: {
                bill_no: currentBillToDelete,
                single_delete: 'true'
            },
            dataType: 'json',
            success: function(data) {
                $('#loadingModal').modal('hide');
                
                if (data.success) {
                    showAlert('success', data.message);
                    currentBillToDelete = '';
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('danger', data.message || 'Error deleting bill.');
                }
            },
            error: function(xhr, status, error) {
                $('#loadingModal').modal('hide');
                
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                
                let errorMsg = 'Network error occurred.';
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response && response.message) {
                        errorMsg = response.message;
                    }
                } catch (e) {
                    errorMsg = xhr.responseText || 'Server error occurred';
                }
                
                showAlert('danger', 'Delete failed: ' + errorMsg);
            }
        });
        
    } else if (selectedBills.size > 0) {
        // Bulk delete
        $('#loadingMessage').text('Deleting selected bills and updating stock...');
        
        const billsArray = Array.from(selectedBills.keys());
        
        // Send POST request for bulk delete
        $.ajax({
            url: 'delete_bill.php',
            method: 'POST',
            data: {
                bill_nos: JSON.stringify(billsArray),
                bulk_delete: 'true'
            },
            dataType: 'json',
            success: function(data) {
                $('#loadingModal').modal('hide');
                
                if (data.success) {
                    showAlert('success', data.message);
                    selectedBills.clear();
                    updateSelectedCount();
                    
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    showAlert('danger', data.message || 'Error deleting bills.');
                }
            },
            error: function(xhr, status, error) {
                $('#loadingModal').modal('hide');
                
                console.error('AJAX Error:', status, error);
                console.error('Response:', xhr.responseText);
                
                let errorMsg = 'Network error occurred.';
                try {
                    const response = JSON.parse(xhr.responseText);
                    if (response && response.message) {
                        errorMsg = response.message;
                    }
                } catch (e) {
                    errorMsg = xhr.responseText || 'Server error occurred';
                }
                
                showAlert('danger', 'Delete failed: ' + errorMsg);
            }
        });
    }
});

// Confirm delete all bills for date
$('#confirmDeleteDateBtn').on('click', function() {
    $('#deleteDateModal').modal('hide');
    $('#loadingModal').modal('show');
    $('#loadingMessage').text(`Deleting all bills for ${deleteDate} and updating stock...`);
    
    // Send POST request for date-based deletion
    $.ajax({
        url: 'delete_bill.php',
        method: 'POST',
        data: {
            delete_date: deleteDate,
            delete_by_date: 'true'
        },
        dataType: 'json',
        success: function(data) {
            $('#loadingModal').modal('hide');
            
            if (data.success) {
                showAlert('success', data.message);
                
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            } else {
                showAlert('danger', data.message || 'Error deleting bills for date.');
            }
        },
        error: function(xhr, status, error) {
            $('#loadingModal').modal('hide');
            
            console.error('AJAX Error:', status, error);
            console.error('Response:', xhr.responseText);
            
            let errorMsg = 'Network error occurred.';
            try {
                const response = JSON.parse(xhr.responseText);
                if (response && response.message) {
                    errorMsg = response.message;
                }
            } catch (e) {
                errorMsg = xhr.responseText || 'Server error occurred';
            }
            
            showAlert('danger', 'Delete failed: ' + errorMsg);
        }
    });
});

// Format date for display
function formatDate(dateString) {
    if (!dateString) return 'N/A';
    
    const date = new Date(dateString);
    return date.toLocaleDateString('en-IN', {
        day: '2-digit',
        month: 'short',
        year: 'numeric'
    });
}

// Show alert function
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
    
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
}

// Reset modals when closed
$('#deleteModal').on('hidden.bs.modal', function() {
    $('#selectedBillsList').empty();
    currentBillToDelete = '';
});

$('#deleteDateModal').on('hidden.bs.modal', function() {
    deleteDate = '';
});

// Apply filters with date range validation
$('form').on('submit', function(e) {
    const startDate = $('input[name="start_date"]');
    const endDate = $('input[name="end_date"]');
    
    if (startDate.length && endDate.length && startDate.val() && endDate.val() && startDate.val() > endDate.val()) {
        e.preventDefault();
        showAlert('warning', 'Start date cannot be greater than End date');
        return false;
    }
});

// Auto-dismiss alerts after 5 seconds
$(document).ready(function() {
    setTimeout(function() {
        $('.alert').alert('close');
    }, 5000);
});

// Enhanced export functionality with modal
$('#exportExcelBtn').on('click', function() {
    $('#exportModal').modal('show');
});

// Toggle custom date range
$('input[name="export_range"]').on('change', function() {
    if ($(this).val() === 'custom') {
        $('#customDateRange').slideDown();
    } else {
        $('#customDateRange').slideUp();
    }
});

$('#confirmExport').on('click', function() {
    const exportRange = $('input[name="export_range"]:checked').val();
    let exportUrl = 'export_sales_excel.php?';

    if (exportRange === 'current') {
        const viewType = '<?= $view_type ?>';
        if (viewType === 'date') {
            exportUrl += `view_type=date&Closing_Stock=<?= $Closing_Stock ?>`;
        } else if (viewType === 'range') {
            exportUrl += `view_type=range&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>`;
        } else {
            exportUrl += `view_type=all`;
        }
    } else {
        const startDate = $('input[name="export_start_date"]').val();
        const endDate = $('input[name="export_end_date"]').val();

        if (!startDate || !endDate) {
            showAlert('warning', 'Please select both start and end dates');
            return;
        }

        if (startDate > endDate) {
            showAlert('warning', 'Start date cannot be greater than end date');
            return;
        }

        exportUrl += `view_type=range&start_date=${startDate}&end_date=${endDate}`;
    }

    const filename = $('input[name="export_filename"]').val();
    if (filename) {
        exportUrl += `&filename=${encodeURIComponent(filename)}`;
    }

    $(this).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Exporting...');
    $(this).prop('disabled', true);

    $('#exportModal').modal('hide');

    const downloadFrame = document.createElement('iframe');
    downloadFrame.style.display = 'none';
    downloadFrame.src = exportUrl;
    document.body.appendChild(downloadFrame);

    setTimeout(() => {
        $(this).html('<i class="fa-solid fa-file-export me-1"></i> Export to Excel');
        $(this).prop('disabled', false);
        document.body.removeChild(downloadFrame);
    }, 3000);
});

$('#exportModal').on('hidden.bs.modal', function() {
    $('#confirmExport').html('<i class="fa-solid fa-file-export me-1"></i> Export to Excel');
    $('#confirmExport').prop('disabled', false);
    $('input[name="export_range"][value="current"]').prop('checked', true);
    $('#customDateRange').hide();
});
</script>
</body>
</html>