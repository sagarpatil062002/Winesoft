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

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Search keyword
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch class descriptions from tblclass
$classDescriptions = [];
$classQuery = "SELECT SGROUP, `DESC` FROM tblclass";
$classResult = $conn->query($classQuery);
while ($row = $classResult->fetch_assoc()) {
    $classDescriptions[$row['SGROUP']] = $row['DESC'];
}

// Fetch subclass descriptions from tblsubclass
$subclassDescriptions = [];
$validItemGroups = []; // To store valid ITEM_GROUP values for each LIQ_FLAG
$subclassQuery = "SELECT ITEM_GROUP, `DESC`, LIQ_FLAG FROM tblsubclass";
$subclassResult = $conn->query($subclassQuery);
while ($row = $subclassResult->fetch_assoc()) {
    $subclassDescriptions[$row['ITEM_GROUP']][$row['LIQ_FLAG']] = $row['DESC'];
    $validItemGroups[$row['LIQ_FLAG']][] = $row['ITEM_GROUP'];
}

// Function to get ITEM_GROUP based on Subclass description and LIQ_FLAG
function getValidItemGroup($subclass, $liqFlag, $conn) {
    if (empty($subclass)) {
        // Get default ITEM_GROUP for the given LIQ_FLAG (usually 'O' for Others)
        $query = "SELECT ITEM_GROUP FROM tblsubclass WHERE LIQ_FLAG = ? AND ITEM_GROUP = 'O' LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $liqFlag);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['ITEM_GROUP'];
        }
        return 'O'; // Fallback
    }
    
    // Query tblsubclass to find a matching description with the same LIQ_FLAG
    $query = "SELECT ITEM_GROUP FROM tblsubclass WHERE `DESC` = ? AND LIQ_FLAG = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ss", $subclass, $liqFlag);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['ITEM_GROUP'];
    }
    
    // If no exact match found, try partial matching with same LIQ_FLAG
    $query = "SELECT ITEM_GROUP FROM tblsubclass WHERE `DESC` LIKE ? AND LIQ_FLAG = ? LIMIT 1";
    $stmt = $conn->prepare($query);
    $searchTerm = "%" . $subclass . "%";
    $stmt->bind_param("ss", $searchTerm, $liqFlag);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['ITEM_GROUP'];
    }
    
    // Fallback to 'O' for Others with the same LIQ_FLAG
    $query = "SELECT ITEM_GROUP FROM tblsubclass WHERE LIQ_FLAG = ? AND ITEM_GROUP = 'O' LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $liqFlag);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return $row['ITEM_GROUP'];
    }
    
    return 'O'; // Final fallback
}

// Handle export requests
if (isset($_GET['export'])) {
    $exportType = $_GET['export'];
    
    // Fetch items from tblitemmaster
    $query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, LIQ_FLAG
              FROM tblitemmaster
              WHERE LIQ_FLAG = ?";
    $params = [$mode];
    $types = "s";
    
    if ($search !== '') {
        $query .= " AND (DETAILS LIKE ? OR CODE LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $types .= "ss";
    }
    
    $query .= " ORDER BY DETAILS ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    if ($exportType === 'csv') {
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=items_' . $mode . '_' . date('Y-m-d') . '.csv');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add CSV headers with user-friendly names
        fputcsv($output, array('Code', 'ItemName', 'PrintName', 'Class', 'Subclass', 'PPrice', 'BPrice', 'LIQFLAG'));
        
        // Add data rows with user-friendly mapping
        foreach ($items as $item) {
            $exportRow = [
                'Code' => $item['CODE'],
                'ItemName' => $item['DETAILS'],
                'PrintName' => $item['Print_Name'],
                'Class' => $item['CLASS'],
                'Subclass' => $item['DETAILS2'],
                'PPrice' => $item['PPRICE'],
                'BPrice' => $item['BPRICE'],
                'LIQFLAG' => $item['LIQ_FLAG']
            ];
            fputcsv($output, $exportRow);
        }
        
        fclose($output);
        exit();
    }
}

// Handle import if form submitted
$importMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file']) && isset($_POST['import_type'])) {
    $importType = $_POST['import_type'];
    $file = $_FILES['import_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $filePath = $file['tmp_name'];
        $fileName = $file['name'];
        $fileExt = pathinfo($fileName, PATHINFO_EXTENSION);
        
        try {
            if ($importType === 'csv' && $fileExt === 'csv') {
                // Process CSV file
                $handle = fopen($filePath, 'r');
                if ($handle !== FALSE) {
                    // Get header row to determine column mapping
                    $headers = fgetcsv($handle);
                    $headerMap = array_flip($headers);
                    
                    // Map user-friendly column names to database fields
                    $codeCol = isset($headerMap['Code']) ? $headerMap['Code'] : (isset($headerMap['CODE']) ? $headerMap['CODE'] : 0);
                    $itemNameCol = isset($headerMap['ItemName']) ? $headerMap['ItemName'] : (isset($headerMap['DETAILS']) ? $headerMap['DETAILS'] : 1);
                    $printNameCol = isset($headerMap['PrintName']) ? $headerMap['PrintName'] : (isset($headerMap['Print_Name']) ? $headerMap['Print_Name'] : 2);
                    $classCol = isset($headerMap['Class']) ? $headerMap['Class'] : (isset($headerMap['CLASS']) ? $headerMap['CLASS'] : 3);
                    $subclassCol = isset($headerMap['Subclass']) ? $headerMap['Subclass'] : (isset($headerMap['DETAILS2']) ? $headerMap['DETAILS2'] : 4);
                    $ppriceCol = isset($headerMap['PPrice']) ? $headerMap['PPrice'] : (isset($headerMap['PPRICE']) ? $headerMap['PPRICE'] : 5);
                    $bpriceCol = isset($headerMap['BPrice']) ? $headerMap['BPrice'] : (isset($headerMap['BPRICE']) ? $headerMap['BPRICE'] : 6);
                    $liqFlagCol = isset($headerMap['LIQFLAG']) ? $headerMap['LIQFLAG'] : (isset($headerMap['LIQ_FLAG']) ? $headerMap['LIQ_FLAG'] : 7);
                    
                    $imported = 0;
                    $updated = 0;
                    $errors = 0;
                    $errorDetails = [];
                    
                    while (($data = fgetcsv($handle)) !== FALSE) {
                        if (count($data) >= 7) { // At least 7 required columns
                            $code = $conn->real_escape_string(trim($data[$codeCol]));
                            $itemName = $conn->real_escape_string(trim($data[$itemNameCol]));
                            $printName = $conn->real_escape_string(trim($data[$printNameCol]));
                            $class = $conn->real_escape_string(trim($data[$classCol]));
                            $subclass = $conn->real_escape_string(trim($data[$subclassCol]));
                            $pprice = floatval(trim($data[$ppriceCol]));
                            $bprice = floatval(trim($data[$bpriceCol]));
                            $liqFlag = isset($data[$liqFlagCol]) ? $conn->real_escape_string(trim($data[$liqFlagCol])) : $mode;
                            
                            // Validate LIQ_FLAG exists in tblsubclass
                            $checkLiqFlagQuery = "SELECT COUNT(*) as count FROM tblsubclass WHERE LIQ_FLAG = '$liqFlag'";
                            $liqFlagResult = $conn->query($checkLiqFlagQuery);
                            $liqFlagExists = $liqFlagResult->fetch_assoc()['count'] > 0;
                            
                            if (!$liqFlagExists) {
                                $errors++;
                                $errorDetails[] = "LIQ_FLAG '$liqFlag' does not exist in tblsubclass for item $code";
                                continue; // Skip this row
                            }
                            
                            // Get valid ITEM_GROUP based on Subclass description and LIQ_FLAG
                            $itemGroupField = getValidItemGroup($subclass, $liqFlag, $conn);
                            
                            // For SUB_CLASS, use the first character of subclass or a default
                            $subClassField = !empty($subclass) ? substr($subclass, 0, 1) : 'O';
                            
                            // Check if item exists
                            $checkQuery = "SELECT CODE FROM tblitemmaster WHERE CODE = '$code' AND LIQ_FLAG = '$liqFlag'";
                            $checkResult = $conn->query($checkQuery);
                            
                            if ($checkResult->num_rows > 0) {
                                // Update existing item
                                $updateQuery = "UPDATE tblitemmaster SET 
                                    Print_Name = '$printName',
                                    DETAILS = '$itemName',
                                    DETAILS2 = '$subclass',
                                    CLASS = '$class',
                                    SUB_CLASS = '$subClassField',
                                    ITEM_GROUP = '$itemGroupField',
                                    PPRICE = $pprice,
                                    BPRICE = $bprice
                                    WHERE CODE = '$code' AND LIQ_FLAG = '$liqFlag'";
                                
                                if ($conn->query($updateQuery)) {
                                    $updated++;
                                } else {
                                    $errors++;
                                    $errorDetails[] = "Error updating $code: " . $conn->error;
                                }
                            } else {
                                // Insert new item
                                $insertQuery = "INSERT INTO tblitemmaster 
                                    (CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, LIQ_FLAG) 
                                    VALUES ('$code', '$printName', '$itemName', '$subclass', '$class', '$subClassField', '$itemGroupField', $pprice, $bprice, '$liqFlag')";
                                
                                if ($conn->query($insertQuery)) {
                                    $imported++;
                                } else {
                                    $errors++;
                                    $errorDetails[] = "Error inserting $code: " . $conn->error;
                                }
                            }
                        } else {
                            $errors++;
                            $errorDetails[] = "Row with insufficient data: " . implode(',', $data);
                        }
                    }
                    fclose($handle);
                    
                    $importMessage = "Import completed: $imported new items added, $updated items updated, $errors errors.";
                    if (!empty($errorDetails)) {
                        $importMessage .= " Error details: " . implode('; ', array_slice($errorDetails, 0, 5));
                        if (count($errorDetails) > 5) {
                            $importMessage .= " and " . (count($errorDetails) - 5) . " more errors.";
                        }
                    }
                }
            } else {
                $importMessage = "Error: Only CSV files are supported for import.";
            }
        } catch (Exception $e) {
            $importMessage = "Error during import: " . $e->getMessage();
        }
    } else {
        $importMessage = "Error uploading file. Please try again.";
    }
}

// Fetch items from tblitemmaster for display
$query = "SELECT CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE
          FROM tblitemmaster
          WHERE LIQ_FLAG = ?";
$params = [$mode];
$types = "s";

if ($search !== '') {
    $query .= " AND (DETAILS LIKE ? OR CODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "ss";
}

$query .= " ORDER BY DETAILS ASC";

$stmt = $conn->prepare($query);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Function to get class description
function getClassDescription($code, $classDescriptions) {
    return $classDescriptions[$code] ?? $code;
}

// Function to get subclass description
function getSubclassDescription($itemGroup, $liqFlag, $subclassDescriptions) {
    return $subclassDescriptions[$itemGroup][$liqFlag] ?? $itemGroup;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Excise Item Master - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <style>
    .import-export-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
    }
    .import-template {
        font-size: 0.9rem;
        color: #6c757d;
        margin-top: 10px;
        padding: 10px;
        background-color: #f8f9fa;
        border-radius: 5px;
    }
    .import-template ul {
        margin-bottom: 0;
        padding-left: 20px;
    }
    .download-template {
        margin-top: 10px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Excise Item Master</h3>

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
      <!-- Import/Export Buttons -->
      <div class="import-export-buttons">
        <div class="btn-group">
          <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="fas fa-file-import"></i> Import
          </button>
          <a href="?mode=<?= $mode ?>&search=<?= urlencode($search) ?>&export=csv" class="btn btn-info">
            <i class="fas fa-file-export"></i> Export CSV
          </a>
        </div>
      </div>

      <!-- Import Template Info -->
      <div class="import-template">
        <p><strong>Import file requirements:</strong></p>
        <ul>
          <li>File must contain these columns in order: <strong>Code, ItemName, PrintName, Class, Subclass(DESC), PPrice, BPrice, LIQFLAG</strong></li>
          <li>Only CSV files are supported for import</li>
        </ul>
        <div class="download-template">
          <a href="javascript:void(0);" onclick="downloadTemplate()" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-download"></i> Download Template
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

      
      <!-- Add Item Button -->
      <div class="action-btn mb-3 d-flex gap-2">
        <a href="add_item.php" class="btn btn-primary">
          <i class="fas fa-plus"></i> New
        </a>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Import Result Message -->
      <?php if (!empty($importMessage)): ?>
      <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= $importMessage ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
      <?php endif; ?>

      <!-- Items Table -->
      <div class="table-container">
        <table class="styled-table table-striped">
          <thead class="table-header">
            <tr>
              <th>Code</th>
              <th>Item Name</th>
              <th>Print Name</th>
              <th>Class</th>
              <th>Sub Class</th>
              <th>P. Price</th>
              <th>B. Price</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($items)): ?>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?= htmlspecialchars($item['CODE']); ?></td>
                <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                <td><?= htmlspecialchars($item['Print_Name']); ?></td>
                <td><?= htmlspecialchars(getClassDescription($item['CLASS'], $classDescriptions)); ?></td>
                <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                <td><?= number_format($item['PPRICE'], 3); ?></td>
                <td><?= number_format($item['BPRICE'], 3); ?></td>
                <td>
                  <a href="edit_item.php?code=<?= urlencode($item['CODE']) ?>&mode=<?= $mode ?>"
                     class="btn btn-sm btn-primary" title="Edit">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="9" class="text-center text-muted">No items found.</td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" aria-labelledby="importModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="importModalLabel">Import Items</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" enctype="multipart/form-data">
        <div class="modal-body">
          <div class="mb-3">
            <label for="importFile" class="form-label">Select CSV file to import</label>
            <input class="form-control" type="file" id="importFile" name="import_file" required accept=".csv">
            <div class="form-text">Only CSV files are supported</div>
          </div>
          <input type="hidden" name="import_type" value="csv">
          <div class="alert alert-info">
            <strong>Note:</strong> 
            <ul class="mb-0">
              <li>LIQFLAG must be one of: F, C, O</li>
              <li>Subclass must match exactly with subclass master descriptions</li>
              <li>Existing items with matching Code and LIQFLAG will be updated</li>
              <li>ITEM_GROUP will be determined by matching Subclass with database descriptions</li>
            </ul>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Import</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function downloadTemplate() {
    // Create a simple CSV template for download
    const headers = ['Code', 'ItemName', 'PrintName', 'Class', 'Subclass', 'PPrice', 'BPrice', 'LIQFLAG'];
    const exampleRow = ['ITEM001', 'Sample Item', 'Sample Print Name', 'W', '180ML', '100.000', '90.000', '<?= $mode ?>'];
    const validLiqFlags = ['F', 'C', 'O'];
    
    let csvContent = headers.join(',') + '\n';
    csvContent += exampleRow.join(',') + '\n';
    
    // Create download link
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.setAttribute('href', url);
    link.setAttribute('download', 'item_import_template.csv');
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
</body>
</html>