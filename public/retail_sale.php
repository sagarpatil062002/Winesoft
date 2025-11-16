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

      <!-- Sales Records -->
      <div class="card">
        <div class="card-header fw-semibold">
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
        <div class="card-body">
          <?php if (count($sales) > 0): ?>
            <div class="table-container">
              <table class="styled-table">
                <thead>
                  <tr>
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
                    if ($sale['LIQ_FLAG'] === 'C') {
                        $liquorType = "Country Liquor";
                    } elseif ($sale['LIQ_FLAG'] === 'O') {
                        $liquorType = "Others";
                    }
                  ?>
                    <tr>
                      <td class="fw-bold"><?= htmlspecialchars($sale['BILL_NO']) ?></td>
                      <td><?= date('d-M-Y', strtotime($sale['BILL_DATE'])) ?></td>
                      <td><span class="badge bg-secondary"><?= htmlspecialchars($sale['item_count']) ?> items</span></td>
                      <td class="fw-bold">₹<?= number_format($sale['TOTAL_AMOUNT'], 2) ?></td>
                      <td>₹<?= number_format($sale['DISCOUNT'], 2) ?></td>
                      <td class="fw-bold text-success">₹<?= number_format($sale['NET_AMOUNT'], 2) ?></td>
                      <td><span class="badge bg-info"><?= $liquorType ?></span></td>
                      <td>
                        <div class="action-buttons">
                          <!-- Edit Button - Redirects to edit form -->
                          <a href="edit_bill_form.php?bill_no=<?= $sale['BILL_NO'] ?>" 
                             class="btn btn-sm btn-warning" title="Edit Bill">
                            <i class="fa-solid fa-pen-to-square"></i>
                          </a>
                          
                          <!-- Delete Button - Uses new AJAX method -->
                          <button class="btn btn-sm btn-danger" 
                                  title="Delete Bill" 
                                  onclick="confirmDelete('<?= $sale['BILL_NO'] ?>')">
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

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirm Delete</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>Are you sure you want to delete bill <strong id="deleteBillNumber"></strong>?</p>
        <p class="text-info">
          <i class="fa-solid fa-info-circle me-2"></i>
          Subsequent bills will be automatically renumbered to maintain sequence.
        </p>
        <p class="text-danger">
          <i class="fa-solid fa-exclamation-triangle me-2"></i>
          <strong>Warning:</strong> This action cannot be undone.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn" onclick="proceedWithDelete()">
          <i class="fa-solid fa-trash me-2"></i>Delete & Renumber
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
        <h6>Processing...</h6>
        <p class="text-muted small mb-0">Please wait while we update the bill sequence</p>
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
let currentBillToDelete = '';

function confirmDelete(billNo) {
  currentBillToDelete = billNo;
  $('#deleteBillNumber').text(billNo);
  $('#deleteModal').modal('show');
}

function proceedWithDelete() {
  // Close confirmation modal
  $('#deleteModal').modal('hide');
  
  // Show loading modal
  $('#loadingModal').modal('show');
  
  // Disable delete button to prevent multiple clicks
  $('#confirmDeleteBtn').prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin me-2"></i>Deleting...');
  
  // Send delete request to dedicated delete_bill.php
  // Send delete request to dedicated delete_bill.php
const formData = new FormData();
formData.append('bill_no', currentBillToDelete);

fetch('delete_bill.php', {
  method: 'POST',
  body: formData
})
  .then(response => {
    if (!response.ok) {
      throw new Error('Network response was not ok');
    }
    return response.json();
  })
  .then(data => {
    // Hide loading modal
    $('#loadingModal').modal('hide');
    
    if (data.success) {
      showAlert('success', data.message || 'Bill deleted successfully! Subsequent bills have been renumbered.');
      
      // Reload page after short delay to show success message
      setTimeout(() => {
        window.location.reload();
      }, 2000);
    } else {
      showAlert('danger', data.message || 'Error deleting bill. Please try again.');
      $('#confirmDeleteBtn').prop('disabled', false).html('<i class="fa-solid fa-trash me-2"></i>Delete & Renumber');
    }
  })
  .catch(error => {
    // Hide loading modal
    $('#loadingModal').modal('hide');
    
    console.error('Error:', error);
    showAlert('danger', 'Network error: ' + error.message);
    $('#confirmDeleteBtn').prop('disabled', false).html('<i class="fa-solid fa-trash me-2"></i>Delete & Renumber');
  });
}

function showAlert(type, message) {
  // Remove any existing alerts
  $('.alert').alert('close');
  
  // Create new alert
  const alertHtml = `
    <div class="alert alert-${type} alert-dismissible fade show" role="alert">
      <i class="fa-solid ${type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'} me-2"></i> 
      ${message}
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  `;
  
  // Prepend to content area
  $('.content-area').prepend(alertHtml);
  
  // Auto-dismiss after 5 seconds
  setTimeout(() => {
    $('.alert').alert('close');
  }, 5000);
}

// Reset delete button when modal is closed
$('#deleteModal').on('hidden.bs.modal', function () {
  $('#confirmDeleteBtn').prop('disabled', false).html('<i class="fa-solid fa-trash me-2"></i>Delete & Renumber');
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

// Edit bill function
function editBill(billNo) {
  window.location.href = 'edit_bill_form.php?bill_no=' + billNo;
}

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
        // Use current view parameters
        const viewType = '<?= $view_type ?>';
        if (viewType === 'date') {
            exportUrl += `view_type=date&Closing_Stock=<?= $Closing_Stock ?>`;
        } else if (viewType === 'range') {
            exportUrl += `view_type=range&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>`;
        } else {
            exportUrl += `view_type=all`;
        }
    } else {
        // Use custom dates
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

    // Add filename if provided
    const filename = $('input[name="export_filename"]').val();
    if (filename) {
        exportUrl += `&filename=${encodeURIComponent(filename)}`;
    }

    // Show loading state
    $(this).html('<i class="fa-solid fa-spinner fa-spin me-1"></i> Exporting...');
    $(this).prop('disabled', true);

    // Close modal and trigger download
    $('#exportModal').modal('hide');

    const downloadFrame = document.createElement('iframe');
    downloadFrame.style.display = 'none';
    downloadFrame.src = exportUrl;
    document.body.appendChild(downloadFrame);

    // Reset button
    setTimeout(() => {
        $(this).html('<i class="fa-solid fa-file-export me-1"></i> Export to Excel');
        $(this).prop('disabled', false);
        document.body.removeChild(downloadFrame);
    }, 3000);
});

// Reset modal when closed
$('#exportModal').on('hidden.bs.modal', function() {
    $('#confirmExport').html('<i class="fa-solid fa-file-export me-1"></i> Export to Excel');
    $('#confirmExport').prop('disabled', false);
    $('input[name="export_range"][value="current"]').prop('checked', true);
    $('#customDateRange').hide();
});
</script>
</body>
</html>