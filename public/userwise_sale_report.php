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
require_once 'license_functions.php';

// Get company's license type and available classes
$company_id = $_SESSION['CompID'];
$license_type = getCompanyLicenseType($company_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);
// Get company ID from session
$compID = $_SESSION['CompID'];

// Default values
$sales_date = isset($_GET['sales_date']) ? $_GET['sales_date'] : date('Y-m-d');
$user_id = isset($_GET['user_id']) ? $_GET['user_id'] : 'all';

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

// Fetch users for dropdown - using the correct table name 'users'
$users = [];
$userQuery = "SELECT id, username FROM users WHERE company_id = ? ORDER BY username";
$userStmt = $conn->prepare($userQuery);
$userStmt->bind_param("i", $compID);
$userStmt->execute();
$userResult = $userStmt->get_result();
while ($row = $userResult->fetch_assoc()) {
    $users[] = $row;
}
$userStmt->close();

$report_data = [];
$total_amount = 0;

if (isset($_GET['generate'])) {
    // Build query to get sales from both tblsaleheader (retail) and tblcustomersales (customer)
    $query = "SELECT 
        u.username as UserName,
        COALESCE(SUM(retail_sales.TotalAmount), 0) + COALESCE(SUM(customer_sales.TotalAmount), 0) as TotalAmount
    FROM users u
    LEFT JOIN (
        -- Retail sales from tblsaleheader and tblsaledetails
        SELECT 
            sh.CREATED_BY as UserID,
            SUM(sd.AMOUNT) as TotalAmount
        FROM tblsaleheader sh
        INNER JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
        WHERE sh.BILL_DATE = ? AND sh.COMP_ID = ?
        GROUP BY sh.CREATED_BY
    ) as retail_sales ON u.id = retail_sales.UserID
    LEFT JOIN (
        -- Customer sales from tblcustomersales
        SELECT 
            cs.UserID,
            SUM(cs.Amount) as TotalAmount
        FROM tblcustomersales cs
        WHERE cs.BillDate = ? AND cs.CompID = ?
        GROUP BY cs.UserID
    ) as customer_sales ON u.id = customer_sales.UserID
    WHERE u.company_id = ?";
    
    $params = [$sales_date, $compID, $sales_date, $compID, $compID];
    $types = "sisii"; // Fixed the type definition string
    
    if ($user_id !== 'all') {
        $query .= " AND u.id = ?";
        $params[] = $user_id;
        $types .= "i";
    }
    
    $query .= " GROUP BY u.id, u.username ORDER BY u.username";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $report_data[] = $row;
        $total_amount += $row['TotalAmount'];
    }
    
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>User Wise Sales Report - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>">
 <script src="components/shortcuts.js?v=<?= time() ?>"></script>

</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">User Wise Sales Report</h3>

      <!-- Report Filters -->
      <div class="card filter-card mb-4 no-print">
        <div class="card-header">Report Filters</div>
        <div class="card-body">
          <form method="GET" class="report-filters">
            <div class="row mb-3">
              <div class="col-md-4">
                <label class="form-label">Sales Date:</label>
                <input type="date" name="sales_date" class="form-control" value="<?= htmlspecialchars($sales_date) ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">User:</label>
                <select name="user_id" class="form-select">
                  <option value="all" <?= $user_id === 'all' ? 'selected' : '' ?>>All Users</option>
                  <?php foreach ($users as $user): ?>
                    <option value="<?= htmlspecialchars($user['id']) ?>" <?= $user_id == $user['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($user['username']) ?>
                    </option>
                  <?php endforeach; ?>
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
            <h5>User Wise Sales Report For <?= date('d-M-Y', strtotime($sales_date)) ?></h5>
          </div>
          
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th class="text-center">S. No.</th>
                  <th>User Name</th>
                  <th class="text-right">Tot. Amt.</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $sno = 1;
                foreach ($report_data as $row): 
                ?>
                  <tr>
                    <td class="text-center"><?= $sno++ ?></td>
                    <td><?= htmlspecialchars($row['UserName']) ?></td>
                    <td class="text-right"><?= number_format($row['TotalAmount'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
                
                <tr class="total-row">
                  <td colspan="2" class="text-end"><strong>Total :</strong></td>
                  <td class="text-right"><strong><?= number_format($total_amount, 2) ?></strong></td>
                </tr>
              </tbody>
            </table>
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

<!-- System Info Bar (similar to your screenshot) -->
<div class="system-info no-print">
  <div>
    <span><?= $_SESSION['user_name'] ?? 'ADMIN' ?></span>
  </div>
  <div>
    <span>CAPS</span> | 
    <span>NUM</span> | 
    <span><?= date('g:i A') ?></span> | 
    <span><?= date('d-M-Y') ?></span>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>