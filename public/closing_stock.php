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

// Get parameters
$date_as_on = isset($_GET['date_as_on']) ? $_GET['date_as_on'] : date('d/m/Y');
$sequence = isset($_GET['sequence']) ? $_GET['sequence'] : 'U';
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'D'; // D for Detailed, S for Summary

// Convert date format for database
$date_parts = explode('/', $date_as_on);
$db_date = count($date_parts) === 3 ? $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0] : date('Y-m-d');
$month_year = date('Y-m', strtotime($db_date));
$day = date('d', strtotime($db_date));

// Get daily stock table name based on company ID
$daily_stock_table = "tbldailystock_" . $_SESSION['CompID'];

// Fetch company name from tblcompany
$companyQuery = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$stmt = $conn->prepare($companyQuery);
$stmt->bind_param("i", $_SESSION['CompID']);
$stmt->execute();
$companyResult = $stmt->get_result();
$company = $companyResult->fetch_assoc();
$companyName = $company['COMP_NAME'] ?? 'DIAMOND WINE SHOP';

// Fetch items with closing stock
$query = "SELECT im.CODE, im.Print_Name, im.DETAILS, im.DETAILS2, im.CLASS, im.SUB_CLASS, 
                 im.ITEM_GROUP, im.PPRICE, im.BPRICE, im.LIQ_FLAG,
                 ds.DAY_{$day}_CLOSING as CLOSING_STOCK
          FROM tblitemmaster im
          LEFT JOIN $daily_stock_table ds ON im.CODE = ds.ITEM_CODE AND ds.STK_MONTH = ?
          ORDER BY " . ($sequence === 'U' ? "im.DETAILS ASC, im.DETAILS2 ASC" : "im.CODE ASC");

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $month_year);
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);

// Group items by brand (first word in DETAILS) and size (DETAILS2)
$brands = [];
foreach ($items as $item) {
    $brand = trim(explode(' ', $item['DETAILS'])[0]);
    $size = $item['DETAILS2'] ?? '';
    
    if (!isset($brands[$brand])) {
        $brands[$brand] = [];
    }
    
    // Normalize size format for grouping
    $normalized_size = normalizeSize($size);
    
    if (!isset($brands[$brand][$normalized_size])) {
        $brands[$brand][$normalized_size] = [];
    }
    
    $brands[$brand][$normalized_size][] = $item;
}

// Function to normalize size format
function normalizeSize($size) {
    $size = trim($size);
    $size = strtoupper($size);
    
    // Convert variations to standard format
    if (strpos($size, 'ML') !== false) {
        $size = preg_replace('/\s*ML.*/', ' ML', $size);
    } elseif (strpos($size, 'LTR') !== false || strpos($size, 'LITR') !== false) {
        $size = preg_replace('/\s*LTR.*/', ' Ltr', $size);
        $size = preg_replace('/\s*LITR.*/', ' Ltr', $size);
    } elseif (strpos($size, 'L') !== false && !strpos($size, 'ML')) {
        $size = preg_replace('/\s*L.*/', ' Ltr', $size);
    }
    
    return trim($size);
}

// Define all possible size columns
$size_columns = ['4.5 L', '3 L', '2 L', '1 Ltr', '750 ML', '860 ML', '800 ML', '375 ML', '330 ML', '325 ML', '180 ML', '90 ML', '60 ML'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Closing Stock Statement - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>"> 
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>"> 
  <link rel="stylesheet" href="css/reports.css?v=<?=time()?>"> 
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>
  <div class="main-content">

    <div class="content-area">
      <h3 class="mb-4">Closing Stock Statement</h3>

      <!-- Filter Form -->
      <div class="card mb-4">
        <div class="card-body">
          <form method="GET" class="row g-3">
            <div class="col-md-3">
              <label class="form-label">Date As On:</label>
              <input type="text" name="date_as_on" value="<?= htmlspecialchars($date_as_on) ?>" 
                     class="form-control datepicker" placeholder="DD/MM/YYYY">
            </div>
            
            <div class="col-md-3">
              <label class="form-label">Sequence:</label>
              <div class="btn-group w-100" role="group">
                <button type="submit" name="sequence" value="U" 
                        class="btn btn-outline-primary <?= $sequence === 'U' ? 'sequence-active' : '' ?>">
                  User Defined
                </button>
                <button type="submit" name="sequence" value="S" 
                        class="btn btn-outline-primary <?= $sequence === 'S' ? 'sequence-active' : '' ?>">
                  System Defined
                </button>
              </div>
            </div>
            
            <div class="col-md-3">
              <label class="form-label">Mode:</label>
              <div class="btn-group w-100" role="group">
                <button type="submit" name="mode" value="D" 
                        class="btn btn-outline-primary <?= $mode === 'D' ? 'sequence-active' : '' ?>">
                  Detailed
                </button>
                <button type="submit" name="mode" value="S" 
                        class="btn btn-outline-primary <?= $mode === 'S' ? 'sequence-active' : '' ?>">
                  Summary
                </button>
              </div>
            </div>
            
            <div class="col-md-3 d-flex align-items-end">
              <button type="submit" class="btn btn-primary me-2">
                <i class="fas fa-filter"></i> Apply
              </button>
              <a href="closing_stock.php" class="btn btn-secondary">
                <i class="fas fa-sync"></i> Reset
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="action-btn mb-3 d-flex gap-2">
        <button onclick="generateReport()" class="btn btn-primary">
          <i class="fas fa-file-alt"></i> Generate
        </button>
        <button onclick="window.print()" class="btn btn-secondary">
          <i class="fas fa-print"></i> Print
        </button>
        <a href="dashboard.php" class="btn btn-secondary ms-auto">
          <i class="fas fa-sign-out-alt"></i> Exit
        </a>
      </div>

      <!-- Report Content -->
      <div id="reportContent" class="print-content" style="display: none;">
        <div class="report-header">
          <div class="print-header">
            <h2><?= htmlspecialchars($companyName) ?></h2>
            <p>Item Wise Closing Stock Statement As On <?= date('d-M-Y', strtotime($db_date)) ?></p>
          </div>
        </div>

        <?php
        $categories = [
          'SPIRITS' => ['W', 'G', 'D', 'K', 'R'],
          'WINE' => ['V'],
          'FERMENTED BEER' => ['B'],
          'MILD BEER' => ['B'],
          'COUNTRY LIQUOR' => []
        ];
        
        foreach ($categories as $category => $classes): 
          $categoryBrands = [];
          $categoryTotal = array_fill_keys($size_columns, 0);
          
          foreach ($brands as $brand => $sizes) {
            foreach ($sizes as $size => $items) {
              foreach ($items as $item) {
                $includeItem = false;
                
                if ($category === 'MILD BEER') {
                  if ($item['CLASS'] === 'B' && stripos($item['DETAILS'], 'mild') !== false) {
                    $includeItem = true;
                  }
                } elseif ($category === 'COUNTRY LIQUOR') {
                  if ($item['LIQ_FLAG'] === 'C') {
                    $includeItem = true;
                  }
                } elseif (in_array($item['CLASS'], $classes)) {
                  $includeItem = true;
                }
                
                if ($includeItem) {
                  if (!isset($categoryBrands[$brand])) {
                    $categoryBrands[$brand] = [];
                  }
                  
                  // Map the normalized size to our standard size columns
                  $mapped_size = mapToStandardSize($size);
                  
                  if (!isset($categoryBrands[$brand][$mapped_size])) {
                    $categoryBrands[$brand][$mapped_size] = 0;
                  }
                  
                  $categoryBrands[$brand][$mapped_size] += (float)$item['CLOSING_STOCK'];
                  
                  // Add to category total if it's a standard size
                  if (in_array($mapped_size, $size_columns)) {
                    $categoryTotal[$mapped_size] += (float)$item['CLOSING_STOCK'];
                  }
                }
              }
            }
          }
          
          if (!empty($categoryBrands)):
        ?>
        <div class="category-section">
          <h4 class="brand-header"><?= $category ?></h4>
          <div class="table-container">
            <table class="report-table">
              <thead>
                <tr>
                  <th>Item Description</th>
                  <?php foreach ($size_columns as $col): ?>
                    <th class="size-column"><?= $col ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php
                ksort($categoryBrands);
                foreach ($categoryBrands as $brand => $brandSizes):
                  // Initialize all sizes with empty values
                  $sizes = array_fill_keys($size_columns, '');
                  
                  // Fill in the actual values
                  foreach ($brandSizes as $size => $quantity) {
                    if (in_array($size, $size_columns) && $quantity > 0) {
                      $sizes[$size] = number_format($quantity, 0);
                    }
                  }
                ?>
                <tr>
                  <td><?= htmlspecialchars($brand) ?></td>
                  <?php foreach ($size_columns as $col): ?>
                    <td class="size-column"><?= $sizes[$col] ?></td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                
                <!-- Total Row -->
                <tr class="total-row">
                  <td style="font-weight: bold;">Total</td>
                  <?php foreach ($size_columns as $col): ?>
                    <td class="size-column" style="font-weight: bold;"><?= $categoryTotal[$col] > 0 ? number_format($categoryTotal[$col], 0) : '' ?></td>
                  <?php endforeach; ?>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; endforeach; ?>
        
        
      </div>
    </div>
    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function generateReport() {
  document.getElementById('reportContent').style.display = 'block';
  window.scrollTo(0, document.getElementById('reportContent').offsetTop);
}

// Initialize datepicker if you have one
$(document).ready(function() {
  $('.datepicker').datepicker({
    format: 'dd/mm/yyyy',
    autoclose: true
  });
});
</script>
</body>
</html>

<?php
// Function to map various size formats to standard size columns
function mapToStandardSize($size) {
    $size_mapping = [
        '4.5 L' => ['4.5 L', '4.5 LTR', '4.5 LITR', '4.5L'],
        '3 L' => ['3 L', '3 LTR', '3 LITR', '3L'],
        '2 L' => ['2 L', '2 LTR', '2 LITR', '2L'],
        '1 Ltr' => ['1 L', '1 LTR', '1 LITR', '1L'],
        '750 ML' => ['750 ML', '750ML'],
        '860 ML' => ['860 ML', '860ML'],
        '800 ML' => ['800 ML', '800ML'],
        '375 ML' => ['375 ML', '375ML'],
        '330 ML' => ['330 ML', '330ML'],
        '325 ML' => ['325 ML', '325ML'],
        '180 ML' => ['180 ML', '180ML'],
        '90 ML' => ['90 ML', '90ML'],
        '60 ML' => ['60 ML', '60ML']
    ];
    
    $size = trim(strtoupper($size));
    
    foreach ($size_mapping as $standard => $variations) {
        if (in_array($size, $variations)) {
            return $standard;
        }
    }
    
    // If no exact match, try partial matching
    if (strpos($size, 'ML') !== false) {
        $ml_value = preg_replace('/[^0-9]/', '', $size);
        if ($ml_value) {
            foreach ($size_mapping as $standard => $variations) {
                if (strpos($standard, $ml_value . ' ML') !== false) {
                    return $standard;
                }
            }
        }
        return '330 ML'; // Default for ML sizes
    } elseif (strpos($size, 'L') !== false) {
        $l_value = preg_replace('/[^0-9.]/', '', $size);
        if ($l_value) {
            foreach ($size_mapping as $standard => $variations) {
                if (strpos($standard, $l_value . ' L') !== false) {
                    return $standard;
                }
            }
        }
        return '1 Ltr'; // Default for L sizes
    }
    
    return '1 Ltr'; // Final default
}
?>