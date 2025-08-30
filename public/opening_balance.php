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

$comp_id = $_SESSION['CompID'];
$fin_year = $_SESSION['FIN_YEAR_ID'];

include_once "../config/db.php"; // MySQLi connection in $conn

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Get current company details
$company_query = "SELECT CompID, Comp_Name FROM tblcompany WHERE CompID = ?";
$company_stmt = $conn->prepare($company_query);
$company_stmt->bind_param("i", $comp_id);
$company_stmt->execute();
$company_result = $company_stmt->get_result();
$current_company = $company_result->fetch_assoc();
$company_stmt->close();

// Check if company columns exist in tblitem_stock, if not create them
$check_columns_query = "SELECT COUNT(*) as count FROM information_schema.columns 
                       WHERE table_name = 'tblitem_stock' 
                       AND column_name IN ('OPENING_STOCK$comp_id', 'CURRENT_STOCK$comp_id')";
$check_result = $conn->query($check_columns_query);
$columns_exist = $check_result->fetch_assoc()['count'] == 2;

if (!$columns_exist) {
    // Add company-specific columns
    $add_col1_query = "ALTER TABLE tblitem_stock ADD COLUMN OPENING_STOCK$comp_id DECIMAL(10,3) DEFAULT 0.000";
    $add_col2_query = "ALTER TABLE tblitem_stock ADD COLUMN CURRENT_STOCK$comp_id DECIMAL(10,3) DEFAULT 0.000";
    
    $conn->query($add_col1_query);
    $conn->query($add_col2_query);
}

// Check if company daily stock table exists, if not create it
$check_table_query = "SELECT COUNT(*) as count FROM information_schema.tables 
                     WHERE table_schema = DATABASE() 
                     AND table_name = 'tbldailystock_$comp_id'";
$check_table_result = $conn->query($check_table_query);
$table_exists = $check_table_result->fetch_assoc()['count'] > 0;

if (!$table_exists) {
    // Create company-specific daily stock table
    $create_table_query = "CREATE TABLE tbldailystock_$comp_id (
        `DailyStockID` int(11) NOT NULL AUTO_INCREMENT,
        `STK_DATE` date NOT NULL,
        `FIN_YEAR` year(4) NOT NULL,
        `ITEM_CODE` varchar(20) NOT NULL,
        `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
        `OPENING_QTY` decimal(10,3) DEFAULT 0.000,
        `PURCHASE_QTY` decimal(10,3) DEFAULT 0.000,
        `SALES_QTY` decimal(10,3) DEFAULT 0.000,
        `ADJUSTMENT_QTY` decimal(10,3) DEFAULT 0.000,
        `CLOSING_QTY` decimal(10,3) DEFAULT 0.000,
        `STOCK_TYPE` varchar(10) DEFAULT 'REGULAR',
        `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`DailyStockID`),
        UNIQUE KEY `unique_daily_stock_$comp_id` (`STK_DATE`,`ITEM_CODE`,`FIN_YEAR`),
        KEY `ITEM_CODE_$comp_id` (`ITEM_CODE`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    
    $conn->query($create_table_query);
}

// Function to get yesterday's closing stock for today's opening
function getYesterdayClosingStock($conn, $comp_id, $item_code, $fin_year, $mode) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $table_name = "tbldailystock_$comp_id";
    
    $query = "SELECT CLOSING_QTY FROM $table_name 
              WHERE STK_DATE = ? AND ITEM_CODE = ? AND FIN_YEAR = ? AND LIQ_FLAG = ? 
              ORDER BY STK_DATE DESC LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssis", $yesterday, $item_code, $fin_year, $mode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['CLOSING_QTY'];
    }
    
    return 0; // Return 0 if no record found for yesterday
}

// Function to update daily stock
function updateDailyStock($conn, $comp_id, $item_code, $fin_year, $mode, $opening_stock, $closing_stock) {
    $today = date('Y-m-d');
    $table_name = "tbldailystock_$comp_id";
    
    // Check if record exists
    $check_query = "SELECT COUNT(*) as count FROM $table_name 
                   WHERE STK_DATE = ? AND ITEM_CODE = ? AND FIN_YEAR = ? AND LIQ_FLAG = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ssis", $today, $item_code, $fin_year, $mode);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    if ($exists) {
        // Update existing record
        $update_query = "UPDATE $table_name 
                        SET OPENING_QTY = ?, CLOSING_QTY = ?, LAST_UPDATED = CURRENT_TIMESTAMP 
                        WHERE STK_DATE = ? AND ITEM_CODE = ? AND FIN_YEAR = ? AND LIQ_FLAG = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ddssis", $opening_stock, $closing_stock, $today, $item_code, $fin_year, $mode);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new record - FIXED THE BIND PARAM ISSUE HERE
        $insert_query = "INSERT INTO $table_name 
                        (STK_DATE, FIN_YEAR, ITEM_CODE, LIQ_FLAG, OPENING_QTY, CLOSING_QTY) 
                        VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        // Changed "sisiidd" to "sisidd" - removed one 'i' since we have 6 parameters, not 7
        $insert_stmt->bind_param("sisidd", $today, $fin_year, $item_code, $mode, $opening_stock, $closing_stock);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
}

// Handle CSV import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
    $csv_file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($csv_file, "r");
    
    // Skip header row
    fgetcsv($handle);
    
    $imported_count = 0;
    $error_messages = [];
    
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        if (count($data) >= 4) {
            $code = trim($data[0]);
            $details = trim($data[1]);
            $details2 = trim($data[2]);
            $balance = floatval(trim($data[3]));
            
            // Validate item code exists and matches details
            $check_item_stmt = $conn->prepare("SELECT COUNT(*) as count FROM tblitemmaster WHERE CODE = ? AND DETAILS = ? AND DETAILS2 = ?");
            $check_item_stmt->bind_param("sss", $code, $details, $details2);
            $check_item_stmt->execute();
            $item_result = $check_item_stmt->get_result();
            $item_exists = $item_result->fetch_assoc()['count'] > 0;
            $check_item_stmt->close();
            
            if ($item_exists) {
                // Check if ANY record exists for this item (regardless of financial year)
                $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?");
                $checkStmt->bind_param("s", $code);
                $checkStmt->execute();
                $checkResult = $checkStmt->get_result();
                $exists = $checkResult->fetch_assoc()['count'] > 0;
                $checkStmt->close();
                
                if ($exists) {
                    // Update existing record - only the columns for this company
                    $updateStmt = $conn->prepare("UPDATE tblitem_stock SET OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ?, LAST_UPDATED = CURRENT_TIMESTAMP WHERE ITEM_CODE = ?");
                    $updateStmt->bind_param("dds", $balance, $balance, $code);
                    $updateStmt->execute();
                    $updateStmt->close();
                } else {
                    // Insert new record - only set columns for this company
                    $insertStmt = $conn->prepare("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id) VALUES (?, ?, ?, ?)");
                    $insertStmt->bind_param("sidd", $code, $fin_year, $balance, $balance);
                    $insertStmt->execute();
                    $insertStmt->close();
                }
                
                // Update daily stock - get yesterday's closing for today's opening
                $yesterday_closing = getYesterdayClosingStock($conn, $comp_id, $code, $fin_year, $mode);
                $today_opening = ($yesterday_closing > 0) ? $yesterday_closing : $balance;
                
                updateDailyStock($conn, $comp_id, $code, $fin_year, $mode, $today_opening, $balance);
                
                $imported_count++;
            } else {
                $error_messages[] = "Item validation failed for '$code' - '$details' - '$details2'. Please check the item details.";
            }
        }
    }
    
    fclose($handle);
    
    $_SESSION['import_message'] = [
        'success' => true,
        'message' => "Successfully imported $imported_count opening balances",
        'errors' => $error_messages
    ];
    
    header("Location: opening_balance.php?mode=" . $mode . "&search=" . urlencode($search));
    exit;
}

// Handle template download
if (isset($_GET['download_template'])) {
    // Fetch all items from tblitemmaster for the current liquor mode
    $template_query = "SELECT CODE, DETAILS, DETAILS2 FROM tblitemmaster WHERE LIQ_FLAG = ? ORDER BY DETAILS ASC";
    $template_stmt = $conn->prepare($template_query);
    $template_stmt->bind_param("s", $mode);
    $template_stmt->execute();
    $template_result = $template_stmt->get_result();
    $template_items = $template_result->fetch_all(MYSQLI_ASSOC);
    $template_stmt->close();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=opening_balance_template_' . $mode . '.csv');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write header row
    fputcsv($output, ['Item_Code', 'Item_Name', 'Category', 'Opening_Stock']);
    
    // Write data rows
    foreach ($template_items as $item) {
        fputcsv($output, [
            $item['CODE'],
            $item['DETAILS'],
            $item['DETAILS2'],
            '' // Empty opening stock column for user to fill
        ]);
    }
    
    fclose($output);
    exit;
}

// Fetch items from tblitemmaster with opening balance for the current company only
$query = "SELECT 
            im.CODE, 
            im.Print_Name, 
            im.DETAILS, 
            im.DETAILS2, 
            im.CLASS, 
            im.SUB_CLASS, 
            im.ITEM_GROUP,
            COALESCE(st.OPENING_STOCK$comp_id, 0) as OPENING_STOCK,
            COALESCE(st.CURRENT_STOCK$comp_id, 0) as CURRENT_STOCK
          FROM tblitemmaster im
          LEFT JOIN tblitem_stock st ON im.CODE = st.ITEM_CODE 
            AND st.FIN_YEAR = ?
          WHERE im.LIQ_FLAG = ?";
$params = [$fin_year, $mode];
$types = "is";

if ($search !== '') {
    $query .= " AND (im.DETAILS LIKE ? OR im.CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " ORDER BY im.DETAILS ASC";

$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle form submission for updating opening balances
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_balances'])) {
    if (isset($_POST['opening_stock'])) {
        foreach ($_POST['opening_stock'] as $code => $balance) {
            $balance = floatval($balance);
            
            // Check if record exists
            $checkStmt = $conn->prepare("SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?");
            $checkStmt->bind_param("s", $code);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $exists = $checkResult->fetch_assoc()['count'] > 0;
            $checkStmt->close();
            
            if ($exists) {
                // Update existing record - only the columns for this company
                $updateStmt = $conn->prepare("UPDATE tblitem_stock SET OPENING_STOCK$comp_id = ?, CURRENT_STOCK$comp_id = ?, LAST_UPDATED = CURRENT_TIMESTAMP WHERE ITEM_CODE = ?");
                $updateStmt->bind_param("dds", $balance, $balance, $code);
                $updateStmt->execute();
                $updateStmt->close();
            } else {
                // Insert new record - only set columns for this company
                $insertStmt = $conn->prepare("INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, OPENING_STOCK$comp_id, CURRENT_STOCK$comp_id) VALUES (?, ?, ?, ?)");
                $insertStmt->bind_param("sidd", $code, $fin_year, $balance, $balance);
                $insertStmt->execute();
                $insertStmt->close();
            }
            
            // Update daily stock - get yesterday's closing for today's opening
            $yesterday_closing = getYesterdayClosingStock($conn, $comp_id, $code, $fin_year, $mode);
            $today_opening = ($yesterday_closing > 0) ? $yesterday_closing : $balance;
            
            updateDailyStock($conn, $comp_id, $code, $fin_year, $mode, $today_opening, $balance);
        }
    }
    
    // Refresh the page to show updated values
    header("Location: opening_balance.php?mode=" . $mode . "&search=" . urlencode($search));
    exit;
}

// Show import message if exists
$import_message = null;
if (isset($_SESSION['import_message'])) {
    $import_message = $_SESSION['import_message'];
    unset($_SESSION['import_message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Opening Balance Management - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .company-info {
      background-color: #f8f9fa;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
    }
    .table-container {
      max-height: 70vh;
      overflow-x: auto;
      overflow-y: auto;
    }
    .opening-balance-input {
      max-width: 100px;
    }
    .sticky-header {
      position: sticky;
      top: 0;
      background-color: white;
      z-index: 100;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .company-column {
      min-width: 120px;
      text-align: center;
    }
    .import-section {
      background-color: #e9ecef;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    .download-template {
      margin-top: 10px;
    }
    .csv-format {
      font-size: 0.9rem;
      color: #6c757d;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Opening Balance Management</h3>
      
      <!-- Company and Financial Year Info -->
      <div class="company-info mb-3">
        <strong>Financial Year:</strong> <?= htmlspecialchars($fin_year) ?> | 
        <strong>Current Company:</strong> <?= htmlspecialchars($current_company['Comp_Name']) ?>
      </div>

      <!-- Import from CSV Section -->
      <div class="import-section mb-4">
        <h5><i class="fas fa-file-import"></i> Import Opening Balances from CSV</h5>
        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
          <div class="col-md-6">
            <label for="csv_file" class="form-label">CSV File</label>
            <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
            <div class="csv-format">
              <strong>CSV format:</strong> Item_Code, Item_Name, Category, Opening_Stock<br>
              <strong>Note:</strong> Do not modify the first three columns. Only fill the Opening_Stock column.
            </div>
          </div>
          <div class="col-md-3">
            <button type="submit" class="btn btn-primary w-100">
              <i class="fas fa-upload"></i> Import CSV
            </button>
          </div>
          <div class="col-md-3">
            <a href="?download_template=1&mode=<?= $mode ?>" class="btn btn-outline-secondary w-100 download-template">
              <i class="fas fa-download"></i> Download Template
            </a>
          </div>
        </form>
        
        <?php if ($import_message): ?>
          <div class="alert alert-<?= $import_message['success'] ? 'success' : 'danger' ?> mt-3">
            <?= $import_message['message'] ?>
            <?php if (!empty($import_message['errors'])): ?>
              <ul class="mb-0 mt-2">
                <?php foreach ($import_message['errors'] as $error): ?>
                  <li><?= $error ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            </div>
        <?php endif; ?>
      </div>

      <!-- Liquor Mode Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Liquor Mode:</label>
        <div class="btn-group" role="group">
          <a href="?mode=F&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
            Foreign Liquor
          </a>
          <a href="?mode=C&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
            Country Liquor
          </a>
          <a href="?mode=O&search=<?= urlencode($search) ?>"
             class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
            Others
          </a>
        </div>
      </div>

      <!-- Search -->
      <form method="GET" class="search-control mb-3">
        <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
        <div class="input-group">
          <input type="text" name="search" class="form-control"
                 placeholder="Search by item name or code..." value="<?= htmlspecialchars($search); ?>">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-search"></i> Find
          </button>
          <?php if ($search !== ''): ?>
            <a href="?mode=<?= $mode ?>" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </div>
      </form>

      <!-- Balance Management Form -->
      <form method="POST" id="balanceForm">
        <div class="action-btn mb-3 d-flex gap-2">
          <button type="submit" name="update_balances" class="btn btn-success">
            <i class="fas fa-save"></i> Save Opening Balances
          </button>
          <a href="dashboard.php" class="btn btn-secondary ms-auto">
            <i class="fas fa-sign-out-alt"></i> Exit
          </a>
        </div>

        <!-- Items Table -->
        <div class="table-container">
          <table class="table table-striped table-bordered">
            <thead class="sticky-header">
              <tr>
                <th>Code</th>
                <th>Item Name</th>
                <th>Category</th>
                <th class="company-column">
                  <?= htmlspecialchars($current_company['Comp_Name']) ?><br>
                  <small>Opening Stock</small>
                </th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= htmlspecialchars($item['CODE']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                  <td class="company-column">
                    <input type="number" step="1" min="0"
                           name="opening_stock[<?= htmlspecialchars($item['CODE']); ?>]" 
                           value="<?= number_format($item['OPENING_STOCK'], 0); ?>" 
                           class="form-control form-control-sm opening-balance-input">
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="4" class="text-center text-muted">No items found.</td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Add confirmation before saving
document.getElementById('balanceForm').addEventListener('submit', function(e) {
    if (!confirm('Are you sure you want to update the opening balances for <?= htmlspecialchars($current_company['Comp_Name']) ?>?')) {
        e.preventDefault();
    }
});

// Update time display every minute
function updateTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    const dateString = now.toLocaleDateString('en-US', { day: '2-digit', month: 'short', year: 'numeric' });
    
    document.querySelector('.badge.bg-dark:nth-child(4)').textContent = timeString;
    document.querySelector('.badge.bg-dark:nth-child(5)').textContent = dateString;
}

setInterval(updateTime, 60000);
</script>
</body>
</html>