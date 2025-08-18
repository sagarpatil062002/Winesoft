<?php
session_start();

// Ensure user is logged in and company is selected
if(!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR'])) {
    header("Location: select_company.php");
    exit;
}

// Database connection
include_once "../config/db.php";

// Initialize stats array with default values
$stats = [
    'total_items' => 0,
    'total_permits' => 0,
    'total_companies' => 0,
    'total_employees' => 0,
    'total_branches' => 0
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

    // Total Permits (active)
    $currentDate = date('Y-m-d');
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tblpermit WHERE P_EXP_DT >= ? AND PRMT_FLAG = 1");
    $stmt->bind_param("s", $currentDate);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_permits'] = number_format($row['total']);
    $stmt->close();

    // Total Companies
    $result = $conn->query("SELECT COUNT(*) as total FROM tblcompany");
    if($result) {
        $row = $result->fetch_assoc();
        $stats['total_companies'] = number_format($row['total']);
        $result->free();
    }

    // Total Employees
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tblemployee WHERE CompID = ?");
    $stmt->bind_param("i", $_SESSION['CompID']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_employees'] = number_format($row['total']);
    $stmt->close();

    // Total Branches
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tblbranch WHERE CompID = ?");
    $stmt->bind_param("i", $_SESSION['CompID']);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stats['total_branches'] = number_format($row['total']);
    $stmt->close();

} catch (Exception $e) {
    // Handle error
    $error = "Database error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - WineSoft</title>
    <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
    <style>
        /* Enhanced Card Styles */
        
    </style>
</head>
<body>

<div class="dashboard-container">
    <!-- Side Navbar -->
    <?php include 'components/navbar.php'; ?>

    <!-- Main Content Area -->
    <div class="main-content">
        <?php include 'components/header.php'; ?>

        <div class="content-area">
            <h3>Dashboard Overview</h3>
            
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
                
                <!-- Permit Statistics Card -->
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #38A169;">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Active Permits</h4>
                        <p><?php echo $stats['total_permits']; ?></p>
                    </div>
                </div>
                
                <!-- Company Statistics Card -->
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #9F7AEA;">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Total Companies</h4>
                        <p><?php echo $stats['total_companies']; ?></p>
                    </div>
                </div>
                
                <!-- Employee Statistics Card -->
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #ED8936;">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Total Employees</h4>
                        <p><?php echo $stats['total_employees']; ?></p>
                    </div>
                </div>
                
                <!-- Branch Statistics Card -->
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #F56565;">
                        <i class="fas fa-store"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Total Branches</h4>
                        <p><?php echo $stats['total_branches']; ?></p>
                    </div>
                </div>
                
                <!-- Placeholder for future stats -->
                <div class="stat-card">
                    <div class="stat-icon" style="background-color: #667EEA;">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="stat-info">
                        <h4>Revenue</h4>
                        <p>Coming Soon</p>
                    </div>
                </div>
            </div>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

<!-- Font Awesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

</body>
</html>