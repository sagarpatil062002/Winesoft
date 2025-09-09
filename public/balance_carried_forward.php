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

// Initialize variables
$balanceData = [];
$currentBalance = 0;
$successMessage = '';
$errorMessage = '';

// Get current balance
$balanceQuery = "SELECT BCAMOUNT FROM tblBaLCrdF WHERE CompID = ? ORDER BY BCDATE DESC, ID DESC LIMIT 1";
$stmt = $conn->prepare($balanceQuery);
$stmt->bind_param("i", $_SESSION['CompID']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $balanceData = $result->fetch_assoc();
    $currentBalance = $balanceData['BCAMOUNT'];
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_balance'])) {
        $balanceAmount = trim($_POST['balance_amount']);
        $balanceDate = $_POST['balance_date'];
        
        // Validate input
        if (empty($balanceAmount)) {
            $errorMessage = "Balance amount is required";
        } elseif (!is_numeric($balanceAmount)) {
            $errorMessage = "Balance amount must be a valid number";
        } else {
            // Check if balance already exists for this date - FIXED QUERY
            $checkQuery = "SELECT ID FROM tblBaLCrdF WHERE CompID = ? AND DATE(BCDATE) = DATE(?)";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("is", $_SESSION['CompID'], $balanceDate);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            
            if ($checkResult->num_rows > 0) {
                $errorMessage = "Entry for this date already exists. Please select a different date.";
            } else {
                // Insert new balance record
                $insertQuery = "INSERT INTO tblBaLCrdF (BCDATE, BCAMOUNT, CompID) VALUES (?, ?, ?)";
                $insertStmt = $conn->prepare($insertQuery);
                
                $compId = $_SESSION['CompID'];
                $balanceAmountFloat = (float)$balanceAmount;
                
                $insertStmt->bind_param("sdi", $balanceDate, $balanceAmountFloat, $compId);
                
                if ($insertStmt->execute()) {
                    $successMessage = "Balance carried forward saved successfully!";
                    $currentBalance = $balanceAmountFloat;
                    
                    // Refresh the page to show updated data
                    header("Location: balance_carried_forward.php?success=1");
                    exit;
                } else {
                    $errorMessage = "Server busy, please try again later.";
                }
            }
        }
    }
}

// Get balance history
$historyQuery = "SELECT ID, BCDATE, BCAMOUNT FROM tblBaLCrdF WHERE CompID = ? ORDER BY BCDATE DESC, ID DESC";
$historyStmt = $conn->prepare($historyQuery);
$historyStmt->bind_param("i", $_SESSION['CompID']);
$historyStmt->execute();
$historyResult = $historyStmt->get_result();
$balanceHistory = [];

if ($historyResult) {
    $balanceHistory = $historyResult->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Balance Carried Forward - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome@6.4.0/css/all.min.css">
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
        <h4><i class="fa-solid fa-scale-balanced me-2"></i>Balance Carried Forward</h4>
      </div>

      <?php if (isset($_GET['success']) && $_GET['success'] == 1): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-check me-2"></i> Balance carried forward saved successfully!
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      
      <?php if (!empty($errorMessage)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fa-solid fa-circle-exclamation me-2"></i> <?= $errorMessage ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>

      <div class="row">
        <!-- Current Balance Card -->
        <div class="col-md-6 mb-4">
          <div class="card h-100">
            <div class="card-header fw-semibold">
              <i class="fa-solid fa-wallet me-2"></i>Current Balance
            </div>
            <div class="card-body text-center py-4">
              <h1 class="display-4 <?= $currentBalance >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                ₹<?= number_format($currentBalance, 2) ?>
              </h1>
              <p class="text-muted">Last updated balance</p>
            </div>
          </div>
        </div>

        <!-- Set New Balance Card -->
        <div class="col-md-6 mb-4">
          <div class="card h-100">
            <div class="card-header fw-semibold">
              <i class="fa-solid fa-pen-to-square me-2"></i>Set New Balance
            </div>
            <div class="card-body">
              <form method="POST" id="balanceForm">
                <div class="mb-3">
                  <label for="balance_date" class="form-label">Date</label>
                  <input type="date" class="form-control" id="balance_date" name="balance_date" 
                         value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-3">
                  <label for="balance_amount" class="form-label">Balance Amount (₹)</label>
                  <input type="number" step="0.01" class="form-control" id="balance_amount" 
                         name="balance_amount" value="<?= $currentBalance ?>" required>
                </div>
                <div class="d-flex gap-2">
                  <button type="submit" name="save_balance" class="btn btn-danger flex-fill">
                    <i class="fas fa-save me-1"></i> Save
                  </button>
                  <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-times me-1"></i> Exit
                  </a>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>

      <!-- Balance History -->
      <div class="card">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
          <span><i class="fa-solid fa-clock-rotate-left me-2"></i>Balance History</span>
          <span class="badge bg-danger rounded-pill"><?= count($balanceHistory) ?> records</span>
        </div>
        <div class="card-body p-0">
          <?php if (!empty($balanceHistory)): ?>
            <div class="table-container">
              <table class="styled-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th class="text-end">Balance Amount (₹)</th>
                  </tr>
                </thead>
                <tbody>
                  <?php 
                  $processedDates = [];
                  foreach ($balanceHistory as $index => $history): 
                    $dateOnly = date('Y-m-d', strtotime($history['BCDATE']));
                    $isDuplicate = in_array($dateOnly, $processedDates);
                    $processedDates[] = $dateOnly;
                  ?>
                    <tr class="<?= $isDuplicate ? 'duplicate-date' : '' ?>">
                      <td><?= $index + 1 ?></td>
                      <td>
                        <?= date('d M Y', strtotime($history['BCDATE'])) ?>
                        <?php if ($isDuplicate): ?>
                          <span class="badge bg-warning ms-2">Duplicate</span>
                        <?php endif; ?>
                      </td>
                      <td class="text-end fw-bold <?= $history['BCAMOUNT'] >= 0 ? 'balance-positive' : 'balance-negative' ?>">
                        ₹<?= number_format($history['BCAMOUNT'], 2) ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <div class="text-center py-5">
              <i class="fa-solid fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
              <h5 class="text-muted">No balance history found</h5>
              <p class="text-muted">Set a new balance to start tracking</p>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Footer -->
      </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
  // Focus on amount field when page loads
  $('#balance_amount').focus();
  
  // Set today's date as default if not already set
  if (!$('#balance_date').val()) {
    var today = new Date();
    var dd = String(today.getDate()).padStart(2, '0');
    var mm = String(today.getMonth() + 1).padStart(2, '0');
    var yyyy = today.getFullYear();
    today = yyyy + '-' + mm + '-' + dd;
    $('#balance_date').val(today);
  }
  
  // Update current time and date
  function updateDateTime() {
    var now = new Date();
    var time = now.toLocaleTimeString('en-US', {hour: '2-digit', minute:'2-digit', hour12: true});
    var date = now.toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'});
    $('#current-time').text(time);
    $('#current-date').text(date);
  }
  
  updateDateTime();
  setInterval(updateDateTime, 60000);
  
  // Client-side validation to check for duplicate dates
  $('#balanceForm').on('submit', function(e) {
    const selectedDate = $('#balance_date').val();
    let isDuplicate = false;
    
    // Check all dates in the history table
    $('table.styled-table tbody tr').each(function() {
      const rowDate = $(this).find('td:eq(1)').text().trim();
      const dateParts = rowDate.split(' ');
      if (dateParts.length === 3) {
        // Format date to match input format (YYYY-MM-DD)
        const months = {
          'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04',
          'May': '05', 'Jun': '06', 'Jul': '07', 'Aug': '08',
          'Sep': '09', 'Oct': '10', 'Nov': '11', 'Dec': '12'
        };
        const formattedDate = dateParts[2] + '-' + months[dateParts[1]] + '-' + dateParts[0].padStart(2, '0');
        
        if (formattedDate === selectedDate) {
          isDuplicate = true;
          return false; // Break out of the loop
        }
      }
    });
    
    if (isDuplicate) {
      e.preventDefault();
      alert('Entry for this date already exists. Please select a different date.');
      $('#balance_date').focus();
    }
  });
});
</script>
</body>
</html>