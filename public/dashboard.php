<?php
session_start();

// Ensure user is logged in and company is selected
if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

// Database connection
include_once "../config/db.php";
require_once 'license_functions.php';

// =============================================================================
// GAP DETECTION AND FIXING LOGIC (UPDATED FOR ACTUAL TABLE STRUCTURE)
// =============================================================================

/**
 * Get current month days (28, 29, 30, or 31)
 */
function getCurrentMonthDays() {
    return (int)date('t');
}

/**
 * Get current month in YYYY-MM format
 */
function getCurrentMonth() {
    return date('Y-m');
}

/**
 * Check if day columns exist for a specific day
 */
function doesDayColumnsExist($conn, $tableName, $day) {
    if ($day > 31) return false;
    
    $columnPrefix = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT);
    $openCol = $columnPrefix . "_OPEN";
    
    $stmt = $conn->prepare("
        SELECT COUNT(*) as column_exists 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = ? 
        AND COLUMN_NAME = ?
    ");
    $stmt->bind_param("ss", $tableName, $openCol);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['column_exists'] > 0;
}

/**
 * Detect gaps in a company's live daily stock table
 */
function detectGapsInLiveTable($conn, $tableName, $companyId) {
    $currentDay = (int)date('j');
    $daysInMonth = getCurrentMonthDays();
    $currentMonth = getCurrentMonth();
    
    // Safety: never exceed current month
    $currentDay = min($currentDay, $daysInMonth);
    
    // Find the last day that has data
    $lastCompleteDay = 0;
    $gaps = [];
    
    for ($day = 1; $day <= $currentDay; $day++) {
        // Check if columns exist for this day
        if (!doesDayColumnsExist($conn, $tableName, $day)) {
            continue; // Skip if columns don't exist
        }
        
        $closingCol = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        // Check if this day has any non-zero, non-null closing data
        // Note: tbldailystock_1 doesn't have CompID, so we check by STK_MONTH only
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) as has_data 
            FROM {$tableName} 
            WHERE {$closingCol} IS NOT NULL 
            AND {$closingCol} != 0 
            AND STK_MONTH = ?
            LIMIT 1
        ");
        $checkStmt->bind_param("s", $currentMonth);
        $checkStmt->execute();
        $result = $checkStmt->get_result();
        $row = $result->fetch_assoc();
        $checkStmt->close();
        
        if ($row['has_data'] > 0) {
            $lastCompleteDay = $day;
        } else {
            // If no data but columns exist, and we have a previous complete day, it's a gap
            if ($lastCompleteDay > 0 && $day > $lastCompleteDay) {
                $gaps[] = $day;
            }
        }
    }
    
    // Special case: if no data found at all, all days are gaps
    if ($lastCompleteDay === 0 && $currentDay > 1) {
        for ($day = 1; $day <= $currentDay; $day++) {
            if (doesDayColumnsExist($conn, $tableName, $day)) {
                $gaps[] = $day;
            }
        }
    }
    
    return [
        'last_complete_day' => $lastCompleteDay,
        'gaps' => $gaps,
        'current_day' => $currentDay,
        'days_in_month' => $daysInMonth,
        'current_month' => $currentMonth
    ];
}

/**
 * Auto-populate gaps in live table
 */
function autoPopulateLiveTable($conn, $tableName, $companyId, $gaps, $lastCompleteDay) {
    $results = [];
    $currentMonth = getCurrentMonth();
    
    // If no last complete day, we can't populate gaps
    if ($lastCompleteDay === 0) {
        foreach ($gaps as $day) {
            $results[$day] = [
                'success' => false,
                'error' => 'No source data available to copy from'
            ];
        }
        return $results;
    }
    
    foreach ($gaps as $day) {
        // Safety check - don't exceed current month
        if ($day > getCurrentMonthDays()) {
            continue;
        }
        
        // Check if target columns exist
        if (!doesDayColumnsExist($conn, $tableName, $day)) {
            $results[$day] = [
                'success' => false,
                'error' => "Columns for day {$day} do not exist"
            ];
            continue;
        }
        
        // Column names
        $targetOpen = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_OPEN";
        $targetPurchase = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_PURCHASE";
        $targetSales = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_SALES";
        $targetClosing = "DAY_" . str_pad($day, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        $sourceClosing = "DAY_" . str_pad($lastCompleteDay, 2, '0', STR_PAD_LEFT) . "_CLOSING";
        
        try {
            // Copy data: Opening = Previous day's closing, Purchase/Sales = 0, Closing = Opening
            // Note: tbldailystock_1 doesn't have CompID, so we update by STK_MONTH only
            $updateStmt = $conn->prepare("
                UPDATE {$tableName} 
                SET 
                    {$targetOpen} = {$sourceClosing},
                    {$targetPurchase} = 0,
                    {$targetSales} = 0,
                    {$targetClosing} = {$sourceClosing}
                WHERE STK_MONTH = ?
                AND {$sourceClosing} IS NOT NULL
            ");
            $updateStmt->bind_param("s", $currentMonth);
            $updateStmt->execute();
            $affectedRows = $updateStmt->affected_rows;
            $updateStmt->close();
            
            $results[$day] = [
                'success' => true,
                'affected_rows' => $affectedRows,
                'source_day' => $lastCompleteDay
            ];
            
        } catch (Exception $e) {
            $results[$day] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    return $results;
}

/**
 * Detect gaps for all companies
 */
function detectAllCompanyGaps($conn) {
    $companyGaps = [];
    
    // Define company tables - only check tables that exist for current company
    $currentCompanyId = $_SESSION['CompID'] ?? 1;
    $companyTables = [
        '1' => 'tbldailystock_1',
        '2' => 'tbldailystock_2', 
        '3' => 'tbldailystock_3'
    ];
    
    // Only check the table for current company
    if (isset($companyTables[$currentCompanyId])) {
        $tableName = $companyTables[$currentCompanyId];
        
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        if ($tableCheck->num_rows > 0) {
            $gapInfo = detectGapsInLiveTable($conn, $tableName, $currentCompanyId);
            if (!empty($gapInfo['gaps'])) {
                $companyGaps[$currentCompanyId] = [
                    'table_name' => $tableName,
                    'company_id' => $currentCompanyId,
                    'last_complete_day' => $gapInfo['last_complete_day'],
                    'gaps' => $gapInfo['gaps'],
                    'current_day' => $gapInfo['current_day'],
                    'days_in_month' => $gapInfo['days_in_month'],
                    'current_month' => $gapInfo['current_month']
                ];
            }
        }
    }
    
    return $companyGaps;
}

/**
 * Fix gaps for all companies
 */
function fixAllCompanyGaps($conn) {
    $results = [];
    $currentCompanyId = $_SESSION['CompID'] ?? 1;
    
    // Define company tables
    $companyTables = [
        '1' => 'tbldailystock_1',
        '2' => 'tbldailystock_2',
        '3' => 'tbldailystock_3'
    ];
    
    // Only process the table for current company
    if (isset($companyTables[$currentCompanyId])) {
        $tableName = $companyTables[$currentCompanyId];
        
        $tableCheck = $conn->query("SHOW TABLES LIKE '{$tableName}'");
        if ($tableCheck->num_rows > 0) {
            $gapInfo = detectGapsInLiveTable($conn, $tableName, $currentCompanyId);
            
            if (!empty($gapInfo['gaps'])) {
                $fixResults = autoPopulateLiveTable(
                    $conn, 
                    $tableName, 
                    $currentCompanyId, 
                    $gapInfo['gaps'], 
                    $gapInfo['last_complete_day']
                );
                
                $results[$currentCompanyId] = [
                    'company_id' => $currentCompanyId,
                    'table_name' => $tableName,
                    'gaps_fixed' => count($gapInfo['gaps']),
                    'details' => $fixResults,
                    'status' => 'fixed'
                ];
            } else {
                $results[$currentCompanyId] = [
                    'company_id' => $currentCompanyId,
                    'table_name' => $tableName,
                    'gaps_fixed' => 0,
                    'status' => 'no_gaps'
                ];
            }
        }
    }
    
    return $results;
}

// Handle gap fixing request
$gapFixResults = null;
if (isset($_POST['fix_data_gaps']) && $_POST['fix_data_gaps'] === '1') {
    $gapFixResults = fixAllCompanyGaps($conn);
    $_SESSION['gap_fix_message'] = "Data gaps have been automatically filled!";
}

// Detect current gaps
$companyGaps = detectAllCompanyGaps($conn);

// Show success message if available
$successMessage = '';
if (isset($_SESSION['gap_fix_message'])) {
    $successMessage = $_SESSION['gap_fix_message'];
    unset($_SESSION['gap_fix_message']);
}

// =============================================================================
// EXISTING DASHBOARD STATISTICS LOGIC (Keep everything as is)
// =============================================================================

// Initialize stats array with default values
$stats = [
    'total_items' => 0,
    'total_customers' => 0,
    'total_suppliers' => 0,
    'total_permits' => 0,
    'total_dry_days' => 0,
    'whisky_items' => 0,
    'wine_items' => 0,
    'gin_items' => 0,
    'fermented_beer_items' => 0,
    'mild_beer_items' => 0,
    'total_beer_items' => 0,
    'brandy_items' => 0,
    'vodka_items' => 0,
    'rum_items' => 0,
    'other_items' => 0
];

// Fetch statistics data (your existing code)
try {
    // Check database connection
    if(!isset($conn) || !$conn instanceof mysqli) {
        throw new Exception("Database connection not established");
    }

    // Total Items
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['total_items'] = number_format($row['total']);
        $result->free();
    }

    // Total Customers (from tbllheads with GCODE 32 - Sundry Debtors)
    $result = $conn->query("SELECT COUNT(*) as total FROM tbllheads WHERE GCODE = 32");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['total_customers'] = number_format($row['total']);
        $result->free();
    }

    // Total Suppliers (from tblsupplier)
    $result = $conn->query("SELECT COUNT(DISTINCT CODE) as total FROM tblsupplier WHERE CODE IS NOT NULL");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['total_suppliers'] = number_format($row['total']);
        $result->free();
    }

    // Total Permits (active)
    $currentDate = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tblpermit WHERE P_EXP_DT >= ? AND PRMT_FLAG = 1");
    $stmt->bind_param("s", $currentDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_permits'] = number_format($row['total']);
    $stmt->close();

    // Total Dry Days (current year)
    $currentYear = date('Y');
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tbldrydays WHERE YEAR(DDATE) = ?");
    $stmt->bind_param("s", $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_dry_days'] = number_format($row['total']);
    $stmt->close();

    // Whisky Items (CLASS = 'W')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'W'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['whisky_items'] = number_format($row['total']);
        $result->free();
    }

    // Wine Items (CLASS = 'V')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'V'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['wine_items'] = number_format($row['total']);
        $result->free();
    }

    // Gin Items (CLASS = 'G')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'G'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['gin_items'] = number_format($row['total']);
        $result->free();
    }

    // Fermented Beer Items (CLASS = 'F')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'F'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['fermented_beer_items'] = number_format($row['total']);
        $result->free();
    }

    // Mild Beer Items (CLASS = 'M')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'M'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['mild_beer_items'] = number_format($row['total']);
        $result->free();
    }

    // Total Beer Items (F + M)
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS IN ('F', 'M')");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['total_beer_items'] = number_format($row['total']);
        $result->free();
    }

    // Brandy Items (CLASS = 'D')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'D'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['brandy_items'] = number_format($row['total']);
        $result->free();
    }

    // Vodka Items (CLASS = 'K')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'K'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['vodka_items'] = number_format($row['total']);
        $result->free();
    }

    // Rum Items (CLASS = 'R')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'R'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['rum_items'] = number_format($row['total']);
        $result->free();
    }

    // Other Items (CLASS = 'O')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'O'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['other_items'] = number_format($row['total']);
        $result->free();
    }

} catch (Exception $e) {
    // Handle error
    $error = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - WineSoft</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
  <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
  <script src="components/shortcuts.js?v=<?= time() ?>"></script>
  <style>
    /* Enhanced Card Styles */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .stat-card {
        background: white;
        border-radius: 10px;
        padding: 20px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        transition: transform 0.3s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
    }
    
    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 15px;
        color: white;
        font-size: 24px;
    }
    
    .stat-info h4 {
        margin: 0;
        font-size: 14px;
        color: #718096;
    }
    
    .stat-info p {
        margin: 5px 0 0;
        font-size: 24px;
        font-weight: bold;
        color: #2D3748;
    }
    
    .alert {
        padding: 15px;
        background-color: #fed7d7;
        color: #c53030;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    
    /* Gap Detection Styles */
    .gap-alert {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
    }
    
    .gap-company {
        background: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        padding: 15px;
        margin: 10px 0;
    }
    
    .gap-days {
        display: inline-block;
        background: rgba(255, 255, 255, 0.2);
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 14px;
        margin: 5px 5px 5px 0;
    }
    
    .btn-gap-fix {
        background: #ff6b6b;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 6px;
        font-weight: bold;
        transition: all 0.3s ease;
    }
    
    .btn-gap-fix:hover {
        background: #ff5252;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
    }
    
    .success-alert {
        background: linear-gradient(135deg, #4ecdc4 0%, #44a08d 100%);
        color: white;
        border: none;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
    }
    
    .month-info {
        background: rgba(255, 255, 255, 0.15);
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 14px;
        margin-left: 10px;
    }
  </style>
</head>
<body>
<div class="dashboard-container">
  <?php include 'components/navbar.php'; ?>

  <div class="main-content">
    <div class="content-area">
      <h3 class="mb-4">Dashboard Overview</h3>
      
      <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
      <?php endif; ?>
      
      <?php if($successMessage): ?>
        <div class="success-alert">
          <i class="fas fa-check-circle"></i> <strong>Success!</strong> <?php echo $successMessage; ?>
        </div>
      <?php endif; ?>
      
      <?php if(!empty($companyGaps)): ?>
        <!-- Data Gap Detection Alert -->
        <div class="gap-alert">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h4 class="mb-0">
                <i class="fas fa-exclamation-triangle"></i> 
                Data Gaps Detected
              </h4>
              <div class="month-info">
                <?php 
                $firstCompany = reset($companyGaps);
                echo $firstCompany['current_month'] . ' • ' . $firstCompany['days_in_month'] . ' days • Today: Day ' . $firstCompany['current_day'];
                ?>
              </div>
            </div>
            <form method="POST" style="display: inline;">
              <button type="submit" name="fix_data_gaps" value="1" class="btn-gap-fix">
                <i class="fas fa-magic"></i> Auto-Fill All Gaps
              </button>
            </form>
          </div>
          
          <p class="mb-3">We found missing stock data from system downtime. Click "Auto-Fill All Gaps" to automatically populate missing days.</p>
          
          <div class="row">
            <?php foreach($companyGaps as $companyId => $gapInfo): ?>
              <div class="col-md-6 mb-2">
                <div class="gap-company">
                  <div class="d-flex justify-content-between align-items-center">
                    <strong>🏢 Company <?php echo $companyId; ?></strong>
                    <span class="badge bg-warning"><?php echo count($gapInfo['gaps']); ?> missing days</span>
                  </div>
                  <div class="mt-2">
                    <small>Missing: 
                      <?php 
                      $gapDisplay = [];
                      foreach($gapInfo['gaps'] as $day) {
                          $gapDisplay[] = 'Day ' . $day;
                      }
                      echo implode(', ', $gapDisplay);
                      ?>
                    </small>
                  </div>
                  <div class="mt-1">
                    <small class="text-light">Last complete data: Day <?php echo $gapInfo['last_complete_day']; ?></small>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      
      <?php if($gapFixResults): ?>
        <!-- Gap Fix Results -->
        <div class="alert alert-info">
          <h5><i class="fas fa-tasks"></i> Gap Fixing Results</h5>
          <?php foreach($gapFixResults as $companyId => $result): ?>
            <div class="mb-2">
              <strong>Company <?php echo $companyId; ?>:</strong>
              <?php if($result['status'] === 'fixed'): ?>
                <span class="text-success">✅ Fixed <?php echo $result['gaps_fixed']; ?> gaps</span>
                <?php 
                $successCount = 0;
                foreach($result['details'] as $dayResult) {
                    if($dayResult['success']) $successCount++;
                }
                ?>
                <small class="text-muted">(<?php echo $successCount; ?> successful)</small>
              <?php else: ?>
                <span class="text-muted">✅ No gaps found</span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <!-- Existing Statistics Grid -->
      <div class="stats-grid">
        <!-- Item Statistics Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #4299E1;">
            <i class="fas fa-wine-bottle"></i>
          </div>
          <div class="stat-info">
            <h4>Total Items</h4>
            <p><?php echo $stats['total_items']; ?></p>
          </div>
        </div>
        
        <!-- Customer Statistics Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #48BB78;">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-info">
            <h4>Total Customers</h4>
            <p>
              <?php 
              // Get the current company ID from session
              $companyId = $_SESSION['CompID'] ?? 0;
              
              // Query to count customers for the current company only
              $customerCountQuery = "SELECT COUNT(*) as total_customers FROM tbllheads WHERE REF_CODE = 'CUST' AND CompID = ?";
              $customerCountStmt = $conn->prepare($customerCountQuery);
              $customerCountStmt->bind_param("i", $companyId);
              $customerCountStmt->execute();
              $customerCountResult = $customerCountStmt->get_result();
              $customerCount = $customerCountResult->fetch_assoc();
              
              echo $customerCount['total_customers'];
              
              $customerCountStmt->close();
              ?>
            </p>
          </div>
        </div>
        
        <!-- Supplier Statistics Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #9F7AEA;">
            <i class="fas fa-truck"></i>
          </div>
          <div class="stat-info">
            <h4>Total Suppliers</h4>
            <p><?php echo $stats['total_suppliers']; ?></p>
          </div>
        </div>
        
        <!-- Dry Days Statistics Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #F56565;">
            <i class="fas fa-calendar-times"></i>
          </div>
          <div class="stat-info">
            <h4>Dry Days (<?php echo date('Y'); ?>)</h4>
            <p><?php echo $stats['total_dry_days']; ?></p>
          </div>
        </div>
        
        <!-- Whisky Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #8B4513;">
            <i class="fas fa-glass-whiskey"></i>
          </div>
          <div class="stat-info">
            <h4>Whisky Items</h4>
            <p><?php echo $stats['whisky_items']; ?></p>
          </div>
        </div>
        
        <!-- Wine Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #8B0000;">
            <i class="fas fa-wine-glass-alt"></i>
          </div>
          <div class="stat-info">
            <h4>Wine Items</h4>
            <p><?php echo $stats['wine_items']; ?></p>
          </div>
        </div>
        
        <!-- Gin Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #87CEEB;">
            <i class="fas fa-cocktail"></i>
          </div>
          <div class="stat-info">
            <h4>Gin Items</h4>
            <p><?php echo $stats['gin_items']; ?></p>
          </div>
        </div>
        
        <!-- Total Beer Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #FFD700;">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>Total Beer Items</h4>
            <p><?php echo $stats['total_beer_items']; ?></p>
          </div>
        </div>
        
        <!-- Fermented Beer Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #DAA520;">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>Fermented Beer Items</h4>
            <p><?php echo $stats['fermented_beer_items']; ?></p>
          </div>
        </div>
        
        <!-- Mild Beer Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #FFA500;">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>Mild Beer Items</h4>
            <p><?php echo $stats['mild_beer_items']; ?></p>
          </div>
        </div>
        
        <!-- Brandy Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #D2691E;">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bottle-wine-icon lucide-bottle-wine"><path d="M10 3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a6 6 0 0 0 1.2 3.6l.6.8A6 6 0 0 1 17 13v8a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1v-8a6 6 0 0 1 1.2-3.6l.6-.8A6 6 0 0 0 10 5z"/><path d="M17 13h-4a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h4"/></svg>
          </div>
          <div class="stat-info">
            <h4>Brandy Items</h4>
            <p><?php echo $stats['brandy_items']; ?></p>
          </div>
        </div>
        
        <!-- Vodka Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #0ebcbcff;">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-martini">
              <path d="M8 22h8"/>
              <path d="M12 11v11"/>
              <path d="m19 3-7 8-7-8Z"/>
            </svg>
          </div>
          <div class="stat-info">
            <h4>Vodka Items</h4>
            <p><?php echo $stats['vodka_items']; ?></p>
          </div>
        </div>
        
        <!-- Rum Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #8B4513;">
            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-wine-icon lucide-wine"><path d="M8 22h8"/><path d="M7 10h10"/><path d="M12 15v7"/><path d="M12 15a5 5 0 0 0 5-5c0-2-.5-4-2-8H9c-1.5 4-2 6-2 8a5 5 0 0 0 5 5Z"/></svg>
          </div>
          <div class="stat-info">
            <h4>Rum Items</h4>
            <p><?php echo $stats['rum_items']; ?></p>
          </div>
        </div>
        
        <!-- Other Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #A9A9A9;">
            <i class="fas fa-box"></i>
          </div>
          <div class="stat-info">
            <h4>Other Items</h4>
            <p><?php echo $stats['other_items']; ?></p>
          </div>
        </div>
      </div>
    </div>

    <?php include 'components/footer.php'; ?>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>