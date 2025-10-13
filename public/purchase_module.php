<?php
session_start();

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

include_once "../config/db.php";

// Handle success message
$success = isset($_GET['success']) ? $_GET['success'] : 0;

// Build query with filters - FIXED: Include all purchases regardless of payment status
$whereConditions = ["p.CompID = ?", "p.PUR_FLAG = ?"];
$params = [$companyId, $mode];
$paramTypes = "is";

// Apply filters if they exist
if (isset($_GET['from_date']) && !empty($_GET['from_date'])) {
    $whereConditions[] = "p.DATE >= ?";
    $params[] = $_GET['from_date'];
    $paramTypes .= "s";
}

if (isset($_GET['to_date']) && !empty($_GET['to_date'])) {
    $whereConditions[] = "p.DATE <= ?";
    $params[] = $_GET['to_date'];
    $paramTypes .= "s";
}

if (isset($_GET['voc_no']) && !empty($_GET['voc_no'])) {
    $whereConditions[] = "p.VOC_NO LIKE ?";
    $params[] = '%' . $_GET['voc_no'] . '%';
    $paramTypes .= "s";
}

if (isset($_GET['supplier']) && !empty($_GET['supplier'])) {
    $whereConditions[] = "s.DETAILS LIKE ?";
    $params[] = '%' . $_GET['supplier'] . '%';
    $paramTypes .= "s";
}

// Get all purchases for this company with filters - FIXED: Removed payment status condition
$purchases = [];
$purchaseQuery = "SELECT p.*, s.DETAILS as supplier_name 
                  FROM tblpurchases p 
                  LEFT JOIN tblsupplier s ON p.SUBCODE = s.CODE
                  WHERE " . implode(" AND ", $whereConditions) . "
                  ORDER BY p.DATE DESC, p.VOC_NO DESC";
                  
$purchaseStmt = $conn->prepare($purchaseQuery);
$purchaseStmt->bind_param($paramTypes, ...$params);
$purchaseStmt->execute();
$purchaseResult = $purchaseStmt->get_result();
if ($purchaseResult) $purchases = $purchaseResult->fetch_all(MYSQLI_ASSOC);
$purchaseStmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Purchase Module - <?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?></title>
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
  .status-pending{background:#fef3c7;color:#92400e}
  .status-paid{background:#dbeafe;color:#1e40af}
  
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
</style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <h4>Purchase Module - <?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?></h4>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#purchaseSummaryModal">
            <i class="fas fa-chart-bar"></i> Purchase Summary
          </button>
          <a href="purchases.php?mode=<?=$mode?>" class="btn btn-primary">
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
                    // Determine status
                    $status = 'Completed';
                    $statusClass = 'status-completed';
                    if (isset($purchase['PAYMENT_STATUS']) && $purchase['PAYMENT_STATUS'] === 'paid') {
                        $status = 'Paid';
                        $statusClass = 'status-paid';
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
              <a href="purchases.php?mode=<?=$mode?>" class="btn btn-primary mt-2">
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
                <h5 class="modal-title" id="purchaseSummaryModalLabel">Purchase Summary - <?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Summary filters -->
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">From Date</label>
                        <input type="date" id="purchaseFromDate" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">To Date</label>
                        <input type="date" id="purchaseToDate" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">&nbsp;</label>
                        <button type="button" class="btn btn-primary w-100" onclick="loadPurchaseSummary()">
                            <i class="fas fa-refresh"></i> Update Summary
                        </button>
                    </div>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="purchaseSummaryTable">
                        <thead class="table-light">
                            <tr>
                                <th>Category</th>
                                <!-- ML Sizes -->
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
                                <!-- Liter Sizes -->
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
                            <!-- Will be populated by JavaScript -->
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
    const mode = '<?= $mode ?>';
    
    // Show loading
    $('#purchaseSummaryTable tbody').html(`
        <tr>
            <td colspan="26" class="text-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading purchase summary...</p>
            </td>
        </tr>
    `);
    
    $.ajax({
        url: 'purchase_summary_ajax.php',
        type: 'GET',
        data: {
            mode: mode,
            from_date: fromDate,
            to_date: toDate,
            comp_id: '<?= $companyId ?>'
        },
        success: function(response) {
            try {
                const summaryData = JSON.parse(response);
                updatePurchaseSummaryTable(summaryData);
            } catch (e) {
                $('#purchaseSummaryTable tbody').html(`
                    <tr>
                        <td colspan="26" class="text-center text-danger">
                            Error loading purchase summary
                        </td>
                    </tr>
                `);
            }
        },
        error: function() {
            $('#purchaseSummaryTable tbody').html(`
                <tr>
                    <td colspan="26" class="text-center text-danger">
                        Failed to load purchase summary
                    </td>
                </tr>
            `);
        }
    });
}

// Function to update the purchase summary table
function updatePurchaseSummaryTable(summaryData) {
    const tbody = $('#purchaseSummaryTable tbody');
    tbody.empty();
    
    const allSizes = [
        '50 ML', '60 ML', '90 ML', '170 ML', '180 ML', '200 ML', '250 ML', '275 ML', 
        '330 ML', '355 ML', '375 ML', '500 ML', '650 ML', '700 ML', '750 ML', '1000 ML',
        '1.5L', '1.75L', '2L', '3L', '4.5L', '15L', '20L', '30L', '50L'
    ];
    
    const categories = ['SPIRITS', 'WINE', 'FERMENTED BEER', 'MILD BEER'];
    
    categories.forEach(category => {
        const row = $('<tr>');
        row.append($('<td>').text(category));
        
        allSizes.forEach(size => {
            const value = summaryData[category]?.[size] || 0;
            const cell = $('<td>').text(value > 0 ? value : '');
            
            if (value > 0) {
                cell.addClass('table-success');
            }
            
            row.append(cell);
        });
        
        tbody.append(row);
    });
}

// Function to print purchase summary
function printPurchaseSummary() {
    const printContent = $('#purchaseSummaryTable').parent().html();
    const printWindow = window.open('', '_blank');
    const mode = '<?= $mode === 'F' ? 'Foreign Liquor' : 'Country Liquor' ?>';
    
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Purchase Summary - ${mode}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; font-size: 12px; }
                th, td { border: 1px solid #ddd; padding: 6px; text-align: center; }
                th { background-color: #f8f9fa; font-weight: bold; }
                .table-success { background-color: #d1edff !important; }
                .text-center { text-align: center; }
                h2 { text-align: center; margin-bottom: 20px; }
                .summary-info { text-align: center; margin-bottom: 15px; color: #666; }
            </style>
        </head>
        <body>
            <h2>Purchase Summary - ${mode}</h2>
            <div class="summary-info">
                Date Range: ${$('#purchaseFromDate').val()} to ${$('#purchaseToDate').val()}
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
    }, 250);
}

// Initialize modal
$('#purchaseSummaryModal').on('show.bs.modal', function() {
    // Set default dates if not set
    if (!$('#purchaseFromDate').val()) {
        $('#purchaseFromDate').val('<?= date('Y-m-01') ?>'); // First day of current month
    }
    if (!$('#purchaseToDate').val()) {
        $('#purchaseToDate').val('<?= date('Y-m-d') ?>'); // Today
    }
    
    // Load initial summary
    loadPurchaseSummary();
});
</script>
</body>
</html>