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
    'beer_items' => 0,
    'brandy_items' => 0,
    'vodka_items' => 0,
    'rum_items' => 0,
    'other_items' => 0
];

// Fetch statistics data
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

    // Beer Items (CLASS = 'B')
    $result = $conn->query("SELECT COUNT(*) as total FROM tblitemmaster WHERE CLASS = 'B'");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['beer_items'] = number_format($row['total']);
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
  <!-- Include shortcuts functionality -->
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
        
        <!-- Beer Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #FFD700;">
            <i class="fas fa-beer"></i>
          </div>
          <div class="stat-info">
            <h4>Beer Items</h4>
            <p><?php echo $stats['beer_items']; ?></p>
          </div>
        </div>
        
        <!-- Brandy Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #D2691E;">
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-bottle-wine-icon lucide-bottle-wine"><path d="M10 3a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2a6 6 0 0 0 1.2 3.6l.6.8A6 6 0 0 1 17 13v8a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1v-8a6 6 0 0 1 1.2-3.6l.6-.8A6 6 0 0 0 10 5z"/><path d="M17 13h-4a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h4"/></svg>          </div>
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
</svg>          </div>
          <div class="stat-info">
            <h4>Vodka Items</h4>
            <p><?php echo $stats['vodka_items']; ?></p>
          </div>
        </div>
        
        <!-- Rum Items Card -->
        <div class="stat-card">
          <div class="stat-icon" style="background-color: #8B4513;">
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-wine-icon lucide-wine"><path d="M8 22h8"/><path d="M7 10h10"/><path d="M12 15v7"/><path d="M12 15a5 5 0 0 0 5-5c0-2-.5-4-2-8H9c-1.5 4-2 6-2 8a5 5 0 0 0 5 5Z"/></svg>          </div>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js">
</script>
</body>
</html>