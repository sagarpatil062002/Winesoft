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

// Sequence selection (default User Defined = 'U')
$sequence = isset($_GET['sequence']) ? $_GET['sequence'] : 'U';

// Fetch company name from tblcompany
$companyQuery = "SELECT COMP_NAME FROM tblcompany WHERE CompID = ?";
$stmt = $conn->prepare($companyQuery);
$stmt->bind_param("i", $_SESSION['CompID']);
$stmt->execute();
$companyResult = $stmt->get_result();
$company = $companyResult->fetch_assoc();
$companyName = $company['COMP_NAME'] ?? 'DIAMOND WINE SHOP';

// Fetch items from tblitemmaster for brandwise listing
$query = "SELECT CODE, NEW_CODE, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, LIQ_FLAG
          FROM tblitemmaster
          ORDER BY " . ($sequence === 'U' ? "DETAILS ASC" : "CODE ASC");

$result = $conn->query($query);
$items = $result->fetch_all(MYSQLI_ASSOC);

// Group items by brand (first word in DETAILS)
$brands = [];
foreach ($items as $item) {
    $brand = trim(explode(' ', $item['DETAILS'])[0]);
    if (!isset($brands[$brand])) {
        $brands[$brand] = [];
    }
    $brands[$brand][] = $item;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Brandwise Listing Report - WineSoft</title>
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
    <?php include 'components/header.php'; ?>

    <div class="content-area">
      <h3 class="mb-4">Brandwise Listing Report</h3>

      <!-- Sequence Selector -->
      <div class="mode-selector mb-3">
        <label class="form-label">Sequence:</label>
        <div class="btn-group" role="group">
          <a href="?sequence=U" class="btn btn-outline-primary <?= $sequence === 'U' ? 'sequence-active' : '' ?>">
            User Defined
          </a>
          <a href="?sequence=S" class="btn btn-outline-primary <?= $sequence === 'S' ? 'sequence-active' : '' ?>">
            System Defined
          </a>
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
            <p>Brandwise Listing Report</p>
            <p>DATE: <?= date('d-M-Y') ?></p>
          </div>
        </div>

        <?php
        $categories = [
          'SPIRITS' => ['W', 'G', 'D', 'K', 'R'],
          'WINE' => ['V'],
          'FERMENTED BEER' => ['B'],
          'MILD BEER' => ['B'], // filter by "mild"
          'COUNTRY LIQUOR' => [] // filtered by LIQ_FLAG = 'C'
        ];
        
        foreach ($categories as $category => $classes): 
          $categoryBrands = [];
          
          foreach ($brands as $brand => $items) {
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
                $categoryBrands[$brand][] = $item;
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
                  <th class="size-column">L</th>
                  <th class="size-column">Q</th>
                  <th class="size-column">P</th>
                  <th class="size-column">N</th>
                  <th class="size-column">90</th>
                  <th class="size-column">60</th>
                  <th class="size-column">L</th>
                  <th class="size-column">Q</th>
                  <th class="size-column">P</th>
                  <th class="size-column">N</th>
                  <th class="size-column">90</th>
                  <th class="size-column">60</th>
                </tr>
              </thead>
              <tbody>
                <?php
                ksort($categoryBrands);
                foreach ($categoryBrands as $brand => $brandItems):
                    // initialize sizes
                    $sizes = [
                        'L' => '', 'Q' => '', 'P' => '', 'N' => '', '90' => '', '60' => '',
                        'L2' => '', 'Q2' => '', 'P2' => '', 'N2' => '', '902' => '', '602' => ''
                    ];
                    
                    // fill sizes based on ITEM_GROUP
                    foreach ($brandItems as $item) {
                        switch ($item['ITEM_GROUP']) {
                            case 'D': $sizes['L'] = '1'; break;
                            case '1': $sizes['Q'] = '1'; break;
                            case '2': $sizes['P'] = '1'; break;
                            case '3': $sizes['N'] = '1'; break;
                            case 'R': $sizes['90'] = '1'; break;
                            case 'I': $sizes['60'] = '1'; break;
                            // map more if needed for 2nd set
                        }
                    }
                ?>
                <tr>
                  <td><?= htmlspecialchars($brand) ?></td>
                  <td class="size-column"><?= $sizes['L'] ?></td>
                  <td class="size-column"><?= $sizes['Q'] ?></td>
                  <td class="size-column"><?= $sizes['P'] ?></td>
                  <td class="size-column"><?= $sizes['N'] ?></td>
                  <td class="size-column"><?= $sizes['90'] ?></td>
                  <td class="size-column"><?= $sizes['60'] ?></td>
                  <td class="size-column"><?= $sizes['L2'] ?></td>
                  <td class="size-column"><?= $sizes['Q2'] ?></td>
                  <td class="size-column"><?= $sizes['P2'] ?></td>
                  <td class="size-column"><?= $sizes['N2'] ?></td>
                  <td class="size-column"><?= $sizes['902'] ?></td>
                  <td class="size-column"><?= $sizes['602'] ?></td>
                </tr>
                <?php endforeach; ?>
                <tr>
                  <td style="font-weight: bold;">Total</td>
                  <td colspan="12"></td>
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
</script>
</body>
</html>
