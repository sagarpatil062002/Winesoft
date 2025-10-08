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

$companyId = $_SESSION['CompID'];
$fin_year = $_SESSION['FIN_YEAR_ID'];

include_once "../config/db.php"; // MySQLi connection in $conn
require_once 'license_functions.php';

// Get company's license type and available classes
$license_type = getCompanyLicenseType($companyId, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Handle price updates when Save button is clicked
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle manual price updates
    if (isset($_POST['save_prices'])) {
        $updated = false;
        foreach ($_POST['prices'] as $code => $priceData) {
            $bprice = floatval($priceData['bprice']);
            $pprice = floatval($priceData['pprice']);
            $rprice = floatval($priceData['rprice']);
            $mprice = floatval($priceData['mprice']);
            $code = $conn->real_escape_string($code);
            
            $updateQuery = "UPDATE tblitemmaster SET BPRICE = ?, PPRICE = ?, RPRICE = ?, MPRICE = ? WHERE CODE = ?";
            $stmt = $conn->prepare($updateQuery);
            $stmt->bind_param("dddds", $bprice, $pprice, $rprice, $mprice, $code);
            if ($stmt->execute()) {
                $updated = true;
            }
            $stmt->close();
        }
        
        if ($updated) {
            $_SESSION['price_update_message'] = "Prices saved successfully!";
        }
        header("Location: ".$_SERVER['PHP_SELF']."?mode=".$_GET['mode']);
        exit;
    }
    
    // Handle CSV import
    if (isset($_POST['import_csv'])) {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $imported = 0;
            $errors = [];
            
            $csvFile = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($csvFile, 'r');
            
            // Skip header row
            fgetcsv($handle);
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                if (count($data) >= 5) {
                    $code = $conn->real_escape_string(trim($data[0]));
                    $bprice = floatval(trim($data[1]));
                    $pprice = floatval(trim($data[2]));
                    $rprice = floatval(trim($data[3]));
                    $mprice = floatval(trim($data[4]));
                    
                    // Check if item exists
                    $checkQuery = "SELECT CODE FROM tblitemmaster WHERE CODE = ?";
                    $checkStmt = $conn->prepare($checkQuery);
                    $checkStmt->bind_param("s", $code);
                    $checkStmt->execute();
                    $checkResult = $checkStmt->get_result();
                    
                    if ($checkResult->num_rows > 0) {
                        // Update existing item
                        $updateQuery = "UPDATE tblitemmaster SET BPRICE = ?, PPRICE = ?, RPRICE = ?, MPRICE = ? WHERE CODE = ?";
                        $updateStmt = $conn->prepare($updateQuery);
                        $updateStmt->bind_param("dddds", $bprice, $pprice, $rprice, $mprice, $code);
                        if ($updateStmt->execute()) {
                            $imported++;
                        } else {
                            $errors[] = "Failed to update item: $code";
                        }
                        $updateStmt->close();
                    } else {
                        $errors[] = "Item not found: $code";
                    }
                    $checkStmt->close();
                }
            }
            fclose($handle);
            
            if ($imported > 0) {
                $_SESSION['price_update_message'] = "CSV import completed! $imported items updated successfully.";
            }
            if (!empty($errors)) {
                $_SESSION['import_errors'] = $errors;
            }
        } else {
            $_SESSION['price_update_message'] = "Error uploading CSV file.";
        }
        header("Location: ".$_SERVER['PHP_SELF']."?mode=".$_GET['mode']);
        exit;
    }
}

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch items from tblitemmaster - FILTERED BY LICENSE TYPE
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT CODE, DETAILS, DETAILS2, CLASS, PPRICE, BPRICE, RPRICE, MPRICE
              FROM tblitemmaster
              WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
    
    $params = array_merge([$mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
    // If no classes allowed, show empty result
    $query = "SELECT CODE, DETAILS, DETAILS2, CLASS, PPRICE, BPRICE, RPRICE, MPRICE
              FROM tblitemmaster
              WHERE 1 = 0"; // Always false condition
    $params = [];
    $types = "";
}

if ($search !== '') {
    $query .= " AND (DETAILS LIKE ? OR CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " ORDER BY DETAILS ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Item-wise Price List - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .price-input {
      width: 100px;
      text-align: right;
    }
    .table-container {
      max-height: 600px;
      overflow-y: auto;
    }
    .import-section {
      background: #f8f9fa;
      padding: 15px;
      border-radius: 5px;
      margin-bottom: 20px;
    }
    .license-info {
      background-color: #e7f3ff;
      border-left: 4px solid #0d6efd;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Item-wise Price List</h3>

      <!-- License Restriction Info -->
      <div class="alert alert-info mb-3 license-info">
          <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
          <p class="mb-0">Showing items for classes: 
              <?php 
              if (!empty($available_classes)) {
                  $class_names = [];
                  foreach ($available_classes as $class) {
                      $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                  }
                  echo implode(', ', $class_names);
              } else {
                  echo 'No classes available for your license type';
              }
              ?>
          </p>
      </div>

      <?php if (isset($_SESSION['price_update_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          <?= $_SESSION['price_update_message'] ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['price_update_message']); ?>
      <?php endif; ?>

      <?php if (isset($_SESSION['import_errors'])): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
          <strong>Import completed with some errors:</strong>
          <ul class="mb-0">
            <?php foreach ($_SESSION['import_errors'] as $error): ?>
              <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
          </ul>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['import_errors']); ?>
      <?php endif; ?>

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

      <!-- CSV Import Section -->
      <div class="import-section mb-4">
        <h5><i class="fas fa-file-import"></i> Import Prices from CSV</h5>
        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
          <div class="col-md-6">
            <label for="csv_file" class="form-label">Select CSV File</label>
            <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
          </div>
          <div class="col-md-4">
            <button type="submit" name="import_csv" class="btn btn-info">
              <i class="fas fa-upload"></i> Import CSV
            </button>
            <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#csvFormatModal">
              <i class="fas fa-question-circle"></i> Format Help
            </button>
          </div>
        </form>
        <small class="text-muted">CSV format: CODE,BPRICE,PPRICE,RPRICE,MPRICE (Download sample: <a href="#" id="downloadSample">sample.csv</a>)</small>
      </div>

      <!-- User/System Defined Radio Buttons -->
      <div class="mb-3">
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="priceType" id="userDefined" checked>
          <label class="form-check-label" for="userDefined">User Defined</label>
        </div>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="radio" name="priceType" id="systemDefined">
          <label class="form-check-label" for="systemDefined">System Defined</label>
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

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2">
        <button type="submit" form="priceForm" class="btn btn-success">
          <i class="fas fa-save"></i> Save Manual Changes
        </button>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Items Table -->
      <form id="priceForm" method="POST">
        <input type="hidden" name="save_prices" value="1">
        <div class="table-container">
          <table class="styled-table table-striped">
            <thead class="table-header">
              <tr>
                <th>S.No</th>
                <th>Item Description</th>
                <th>Category</th>
                <th>Class</th>
                <th>Code</th>
                <th>Base Price</th>
                <th>Purchase Price</th>
                <th>Retail Price</th>
                <th>MRP Price</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!empty($items)): ?>
              <?php $s_no = 1; ?>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><?= $s_no++; ?></td>
                  <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                  <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                  <td><?= htmlspecialchars($item['CLASS']); ?></td>
                  <td><small class="text-muted"><?= htmlspecialchars($item['CODE']); ?></small></td>
                  <td>
                    <input type="number" step="0.001" 
                           name="prices[<?= htmlspecialchars($item['CODE']) ?>][bprice]" 
                           class="form-control price-input bprice-input" 
                           value="<?= number_format($item['BPRICE'], 3, '.', '') ?>">
                  </td>
                  <td>
                    <input type="number" step="0.001" 
                           name="prices[<?= htmlspecialchars($item['CODE']) ?>][pprice]" 
                           class="form-control price-input pprice-input" 
                           value="<?= number_format($item['PPRICE'], 3, '.', '') ?>">
                  </td>
                  <td>
                    <input type="number" step="0.001" 
                           name="prices[<?= htmlspecialchars($item['CODE']) ?>][rprice]" 
                           class="form-control price-input rprice-input" 
                           value="<?= number_format($item['RPRICE'], 3, '.', '') ?>">
                  </td>
                  <td>
                    <input type="number" step="0.001" 
                           name="prices[<?= htmlspecialchars($item['CODE']) ?>][mprice]" 
                           class="form-control price-input mprice-input" 
                           value="<?= number_format($item['MPRICE'], 3, '.', '') ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="9" class="text-center text-muted">
                  <?php if (empty($allowed_classes)): ?>
                    No classes available for your license type (<?= htmlspecialchars($license_type) ?>)
                  <?php else: ?>
                    No items found.
                  <?php endif; ?>
                </td>
              </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </form>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- CSV Format Help Modal -->
<div class="modal fade" id="csvFormatModal" tabindex="-1" aria-labelledby="csvFormatModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="csvFormatModalLabel">CSV Import Format</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Your CSV file should have the following format:</p>
        <table class="table table-bordered">
          <thead>
            <tr>
              <th>CODE</th>
              <th>Base PRICE</th>
              <th>Purchase PRICE</th>
              <th>Retail PRICE</th>
              <th>MRP PRICE</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>SCMBR0009735</td>
              <td>70.000</td>
              <td>80.000</td>
              <td>100.000</td>
              <td>120.000</td>
            </tr>
            <tr>
              <td>SCMBR0009736</td>
              <td>90.000</td>
              <td>100.000</td>
              <td>110.000</td>
              <td>120.000</td>
            </tr>
          </tbody>
        </table>
        <p><strong>Note:</strong> The first row should be the header row with column names.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Generate and download sample CSV
document.getElementById('downloadSample').addEventListener('click', function(e) {
    e.preventDefault();
    
    const sampleData = [
        ['CODE', 'BPRICE', 'PPRICE', 'RPRICE', 'MPRICE'],
        ['SCMBR0009735', '70.000', '80.000', '100.000', '120.000'],
        ['SCMBR0009736', '90.000', '100.000', '110.000', '120.000'],
        ['SCMBR0009787', '70.000', '80.000', '100.000', '120.000']
    ];
    
    let csvContent = "data:text/csv;charset=utf-8,";
    sampleData.forEach(function(rowArray) {
        let row = rowArray.join(",");
        csvContent += row + "\r\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "price_import_sample.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
});
</script>
</body>
</html>