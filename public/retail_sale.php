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

// Default view selection - show all records initially
$view_type = isset($_GET['view_type']) ? $_GET['view_type'] : 'all';

// Date selection (default to today)
$sale_date = isset($_GET['sale_date']) ? $_GET['sale_date'] : date('Y-m-d');

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
              ORDER BY sh.BILL_DATE DESC, sh.BILL_NO DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $compID, $sale_date);
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
              ORDER BY sh.BILL_DATE DESC, sh.BILL_NO DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iss", $compID, $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $sales = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    // Fetch all sales records (default view)
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
              ORDER BY sh.BILL_DATE DESC, sh.BILL_NO DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $compID);
    $stmt->execute();
    $result = $stmt->get_result();
    $sales = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Handle delete action
if (isset($_GET['delete_bill'])) {
    $bill_no = $_GET['delete_bill'];
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Delete from sale details first
        $deleteDetailsQuery = "DELETE FROM tblsaledetails WHERE BILL_NO = ? AND COMP_ID = ?";
        $deleteDetailsStmt = $conn->prepare($deleteDetailsQuery);
        $deleteDetailsStmt->bind_param("si", $bill_no, $compID);
        $deleteDetailsStmt->execute();
        
        // Delete from sale header
        $deleteHeaderQuery = "DELETE FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
        $deleteHeaderStmt = $conn->prepare($deleteHeaderQuery);
        $deleteHeaderStmt->bind_param("si", $bill_no, $compID);
        $deleteHeaderStmt->execute();
        
        if ($deleteHeaderStmt->affected_rows > 0) {
            $conn->commit();
            $_SESSION['success'] = "Bill #$bill_no deleted successfully!";
        } else {
            throw new Exception("No records found to delete");
        }
        
        $deleteDetailsStmt->close();
        $deleteHeaderStmt->close();
        
        // Redirect to avoid resubmission
        header("Location: sales_management.php?" . http_build_query($_GET));
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error deleting bill: " . $e->getMessage();
    }
}

// Handle success/error messages
if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error_message = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Handle bill preview request
if (isset($_GET['preview_bill'])) {
    $bill_no = $_GET['preview_bill'];
    $auto_print = isset($_GET['print']) && $_GET['print'] === 'true';
    
    // Fetch bill details
    $billQuery = "SELECT 
                    sh.BILL_NO, 
                    sh.BILL_DATE, 
                    sh.TOTAL_AMOUNT,
                    sh.DISCOUNT,
                    sh.NET_AMOUNT,
                    sh.LIQ_FLAG,
                    sd.ITEM_CODE,
                    im.DETAILS as ITEM_NAME,
                    sd.QTY,
                    sd.RATE,
                    sd.AMOUNT
                  FROM tblsaleheader sh
                  JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
                  JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
                  WHERE sh.BILL_NO = ? AND sh.COMP_ID = ?
                  ORDER BY im.DETAILS";
    
    $billStmt = $conn->prepare($billQuery);
    $billStmt->bind_param("si", $bill_no, $compID);
    $billStmt->execute();
    $billResult = $billStmt->get_result();
    
    if ($billResult->num_rows > 0) {
        $billItems = $billResult->fetch_all(MYSQLI_ASSOC);
        
        // Get header info from first row
        $firstRow = $billItems[0];
        $billDate = $firstRow['BILL_DATE'];
        $totalAmount = $firstRow['TOTAL_AMOUNT'];
        $discount = $firstRow['DISCOUNT'];
        $netAmount = $firstRow['NET_AMOUNT'];
        $liquorFlag = $firstRow['LIQ_FLAG'];
        
        // Determine liquor type
        $liquorType = "Foreign Liquor";
        if ($liquorFlag === 'C') {
            $liquorType = "Country Liquor";
        } elseif ($liquorFlag === 'O') {
            $liquorType = "Others";
        }
        
        // Generate bill preview HTML
        echo '<!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Bill Preview - WineSoft</title>
          <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
          <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
          <style>
            @media print {
              body * {
                visibility: hidden;
              }
              .bill-preview, .bill-preview * {
                visibility: visible;
              }
              .bill-preview {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
              }
              .no-print {
                display: none !important;
              }
            }
            .bill-preview {
              width: 80mm;
              margin: 0 auto;
              padding: 5px;
              font-family: monospace;
              font-size: 12px;
            }
            .text-center {
              text-align: center;
            }
            .text-right {
              text-align: right;
            }
            .bill-header {
              border-bottom: 1px dashed #000;
              padding-bottom: 5px;
              margin-bottom: 5px;
            }
            .bill-footer {
              border-top: 1px dashed #000;
              padding-top: 5px;
              margin-top: 5px;
            }
            .bill-table {
              width: 100%;
              border-collapse: collapse;
            }
            .bill-table th, .bill-table td {
              padding: 2px 0;
            }
            .bill-table .text-right {
              text-align: right;
            }
          </style>
        </head>
        <body>
          <div class="bill-preview">
            <div class="bill-header text-center">
              <h1>WineSoft</h1>
              <p style="margin: 2px 0; font-size: 10px;">Liquor Store Management System</p>
              <p style="margin: 2px 0; font-size: 10px;">' . $liquorType . '</p>
            </div>
            
            <div style="margin: 5px 0;">
              <p style="margin: 2px 0;"><strong>Bill No:</strong> ' . $bill_no . '</p>
              <p style="margin: 2px 0;"><strong>Date:</strong> ' . date('d/m/Y', strtotime($billDate)) . '</p>
            </div>
            
            <table class="bill-table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th class="text-right">Qty</th>
                  <th class="text-right">Rate</th>
                  <th class="text-right">Amount</th>
                </tr>
              </thead>
              <tbody>';
        
        foreach ($billItems as $item) {
            echo '<tr>
                    <td>' . substr($item['ITEM_NAME'], 0, 15) . '</td>
                    <td class="text-right">' . $item['QTY'] . '</td>
                    <td class="text-right">' . number_format($item['RATE'], 2) . '</td>
                    <td class="text-right">' . number_format($item['AMOUNT'], 2) . '</td>
                  </tr>';
        }
        
        echo '</tbody>
            </table>
            
            <div class="bill-footer">
              <table class="bill-table">
                <tr>
                  <td>Sub Total:</td>
                  <td class="text-right">₹' . number_format($totalAmount, 2) . '</td>
                </tr>
                <tr>
                  <td>Discount:</td>
                  <td class="text-right">₹' . number_format($discount, 2) . '</td>
                </tr>
                <tr>
                  <td><strong>Net Amount:</strong></td>
                  <td class="text-right"><strong>₹' . number_format($netAmount, 2) . '</strong></td>
                </tr>
              </table>
              
              <p style="margin: 5px 0; text-align: center;">Thank you for your business!</p>
            </div>
            
            <div class="no-print text-center" style="margin-top: 15px;">
              <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
              <a href="sales_management.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Back to Sales</a>
            </div>
          </div>
          
          <script>
            document.addEventListener("DOMContentLoaded", function() {
                ' . ($auto_print ? 'window.print();' : '') . '
            });
          </script>
        </body>
        </html>';
        exit;
    } else {
        $_SESSION['error'] = "Bill not found!";
        header("Location: sales_management.php");
        exit;
    }
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
    .table-container{overflow-x:auto;max-height:520px}
    table.styled-table{width:100%;border-collapse:collapse}
    .styled-table th,.styled-table td{border:1px solid #2f5bb1ff;padding:8px 10px}
    .styled-table thead th{position:sticky;top:0;background:#f8fafc;z-index:1}
    .action-buttons{display:flex;gap:5px}
    .view-selector .btn {border-radius: 0;}
    .view-selector .btn:first-child {border-top-left-radius: 6px; border-bottom-left-radius: 6px;}
    .view-selector .btn:last-child {border-top-right-radius: 6px; border-bottom-right-radius: 6px;}
    .view-active {background-color: #0d6efd; }
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
          <a href="sale_for_date.php" class="btn btn-primary">
            <i class="fa-solid fa-calendar-day me-1"></i> Sale for Date
          </a>
          <a href="sale_for_date_range.php" class="btn btn-primary">
            <i class="fa-solid fa-calendar-week me-1"></i> Sale for Date Range
          </a>
        </div>
      </div>

      <?php if (isset($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> <?= $success_message ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <?php if (isset($error_message)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-exclamation me-2"></i> <?= $error_message ?>
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
                <a href="?view_type=date&sale_date=<?= $sale_date ?>" 
                   class="btn btn-outline-primary <?= $view_type === 'date' ? 'view-active' : '' ?>">
                  <i class="fa-solid fa-calendar-day me-1"></i> Sale for Date
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
                  <input type="date" name="sale_date" class="form-control" 
                         value="<?= htmlspecialchars($sale_date); ?>" required>
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
              echo 'Sales Records for ' . date('d-M-Y', strtotime($sale_date));
          } elseif ($view_type === 'range') {
              echo 'Sales Records from ' . date('d-M-Y', strtotime($start_date)) . ' to ' . date('d-M-Y', strtotime($end_date));
          } else {
              echo 'All Sales Records';
          }
          ?>
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
                      <td><?= htmlspecialchars($sale['BILL_NO']) ?></td>
                      <td><?= date('d-M-Y', strtotime($sale['BILL_DATE'])) ?></td>
                      <td><?= htmlspecialchars($sale['item_count']) ?></td>
                      <td>₹<?= number_format($sale['TOTAL_AMOUNT'], 2) ?></td>
                      <td>₹<?= number_format($sale['DISCOUNT'], 2) ?></td>
                      <td>₹<?= number_format($sale['NET_AMOUNT'], 2) ?></td>
                      <td><?= $liquorType ?></td>
                      <td>
                        <div class="action-buttons">
                          <!-- Print Button - Directly opens print dialog -->
                          <a href="retail_sale.php?preview_bill=<?= $sale['BILL_NO'] ?>&print=true" 
                             class="btn btn-sm btn-primary" title="Print Bill" target="_blank">
                            <i class="fa-solid fa-print"></i>
                          </a>
                          
                          
                          <!-- Delete Button -->
                          <button class="btn btn-sm btn-danger" 
                                  title="Delete" 
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
        <p>Are you sure you want to delete this sale bill? This action cannot be undone.</p>
        <p class="text-danger"><strong>Warning:</strong> All items in this bill will be permanently deleted.</p>
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
function confirmDelete(billNo) {
  // Build the delete URL with current filter parameters
  const params = new URLSearchParams(window.location.search);
  params.set('delete_bill', billNo);
  
  $('#deleteConfirm').attr('href', 'sales_management.php?' + params.toString());
  $('#deleteModal').modal('show');
}

// Apply filters with date range validation
$('form').on('submit', function(e) {
  const startDate = $('input[name="start_date"]');
  const endDate = $('input[name="end_date"]');
  
  if (startDate.length && endDate.length && startDate.val() && endDate.val() && startDate.val() > endDate.val()) {
    e.preventDefault();
    alert('Start date cannot be greater than End date');
    return false;
  }
});
</script>
</body>
</html>