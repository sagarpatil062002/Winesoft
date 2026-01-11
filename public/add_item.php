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

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Resolve current page path for reload links
$currentPage = basename($_SERVER['PHP_SELF']);

// Get mode (prefer POST for submission, else GET, default 'F')
$mode = isset($_POST['mode']) ? $_POST['mode'] : (isset($_GET['mode']) ? $_GET['mode'] : 'F');
// Sanitize to allowed values
$allowedModes = ['F','C','O'];
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'F';
}

// Fetch subclasses from database (by mode)
$subclasses = [];

// Subclasses - Include ITEM_GROUP in the query
if ($stmt = $conn->prepare("SELECT DISTINCT `DESC` AS subclass_name, ITEM_GROUP FROM tblsubclass WHERE LIQ_FLAG = ? ORDER BY `DESC`")) {
    $stmt->bind_param("s", $mode);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) $subclasses = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Initialize variables
$code = $Print_Name = $details = $details2 = $class = $sub_class = $BARCODE = '';
$pprice = $bprice = $mprice = $RPRICE = $opening_balance = 0;
$success = $error = '';

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect POST data safely
    $code = trim($_POST['code'] ?? '');
    $Print_Name = trim($_POST['Print_Name'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $details2 = trim($_POST['details2'] ?? '');
    $BARCODE = trim($_POST['BARCODE'] ?? '');
    $pprice = floatval($_POST['pprice'] ?? 0);
    $bprice = floatval($_POST['bprice'] ?? 0);
    $mprice = floatval($_POST['mprice'] ?? 0);
    $RPRICE = floatval($_POST['RPRICE'] ?? 0);
    $opening_balance = intval($_POST['opening_balance'] ?? 0);
    $liq_flag = $mode; // use current mode
    
    // Auto-detect class from item name (same function as in item_master.php)
    $class = detectClassFromItemName($details);
    
    // Validate class against license restrictions
    if (!in_array($class, $allowed_classes)) {
        $error = "Class '$class' detected from item name is not allowed for your license type '$license_type'.";
    } else if ($code === '' || $details === '') {
        $error = "Item Code and Item Name are required.";
    } else {
        // Get ITEM_GROUP from selected subclass
        $item_group = 'O'; // Default to Others
        if (!empty($details2)) {
            $stmt = $conn->prepare("SELECT ITEM_GROUP FROM tblsubclass WHERE `DESC` = ? AND LIQ_FLAG = ? LIMIT 1");
            $stmt->bind_param("ss", $details2, $mode);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $item_group = $row['ITEM_GROUP'];
            }
            $stmt->close();
        }

        // For SUB_CLASS, use the first character of subclass or a default
        $subClassField = !empty($details2) ? substr($details2, 0, 1) : 'O';

        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert into tblitemmaster
            $sql = "INSERT INTO tblitemmaster 
                (CODE, Print_Name, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, MPRICE, RPRICE, BARCODE, LIQ_FLAG) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            
            $stmt->bind_param(
                "sssssssddddss",
                $code, $Print_Name, $details, $details2, $class, $subClassField, $item_group,
                $pprice, $bprice, $mprice, $RPRICE, $BARCODE, $liq_flag
            );

            if (!$stmt->execute()) {
                throw new Exception("Error inserting into tblitemmaster: " . $stmt->error);
            }
            $stmt->close();
            
            // Update stock information for all tables
            updateItemStockAllTables($conn, $company_id, $code, $liq_flag, $opening_balance);
            
            // Commit transaction
            $conn->commit();
            
            $success = "Item added successfully!";
            // Reset form
            $code = $Print_Name = $details = $details2 = $BARCODE = '';
            $pprice = $bprice = $mprice = $RPRICE = $opening_balance = 0;
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
}

// Function to detect class from item name (same as in item_master.php)
function detectClassFromItemName($itemName) {
    $itemName = strtoupper($itemName);
    
    // WHISKY Detection
    if (strpos($itemName, 'WHISKY') !== false || 
        strpos($itemName, 'WHISKEY') !== false ||
        strpos($itemName, 'SCOTCH') !== false ||
        strpos($itemName, 'SINGLE MALT') !== false ||
        strpos($itemName, 'BLENDED') !== false ||
        strpos($itemName, 'BOURBON') !== false ||
        strpos($itemName, 'RYE') !== false ||
        preg_match('/\b(JOHNNIE WALKER|JACK DANIEL|CHIVAS|ROYAL CHALLENGE|8PM|OFFICER\'S CHOICE|MCDOWELL\'S|SIGNATURE|IMPERIAL BLUE)\b/', $itemName) ||
        preg_match('/\b(\d+ YEARS?|AGED)\b/', $itemName)) {
        return 'W';
    }
    
    // WINE Detection
    if (strpos($itemName, 'WINE') !== false ||
        strpos($itemName, 'PORT') !== false ||
        strpos($itemName, 'SHERRY') !== false ||
        strpos($itemName, 'CHAMPAGNE') !== false ||
        strpos($itemName, 'SPARKLING') !== false ||
        strpos($itemName, 'MERLOT') !== false ||
        strpos($itemName, 'CABERNET') !== false ||
        strpos($itemName, 'CHARDONNAY') !== false ||
        strpos($itemName, 'SAUVIGNON') !== false ||
        strpos($itemName, 'RED WINE') !== false ||
        strpos($itemName, 'WHITE WINE') !== false ||
        strpos($itemName, 'ROSE WINE') !== false ||
        strpos($itemName, 'DESSERT WINE') !== false ||
        strpos($itemName, 'FORTIFIED WINE') !== false ||
        preg_match('/\b(SULA|GROVER|FRATELLI|BORDEAUX|CHATEAU)\b/', $itemName)) {
        return 'V';
    }
    
    // BRANDY Detection
    if (strpos($itemName, 'BRANDY') !== false ||
        strpos($itemName, 'COGNAC') !== false ||
        strpos($itemName, 'VSOP') !== false ||
        strpos($itemName, 'XO') !== false ||
        strpos($itemName, 'NAPOLEON') !== false ||
        preg_match('/\b(HENNESSY|REMY MARTIN|MARTELL|COURVOISIER|MANSION HOUSE|OLD ADMIRAL|DUNHILL)\b/', $itemName) ||
        strpos($itemName, 'VS ') !== false) {
        return 'D';
    }
    
    // VODKA Detection
    if (strpos($itemName, 'VODKA') !== false ||
        preg_match('/\b(SMIRNOFF|ABSOLUT|ROMANOV|GREY GOOSE|BELVEDERE|CIROC|FINLANDIA)\b/', $itemName) ||
        strpos($itemName, 'LEMON VODKA') !== false ||
        strpos($itemName, 'ORANGE VODKA') !== false ||
        strpos($itemName, 'FLAVORED VODKA') !== false) {
        return 'K';
    }
    
    // GIN Detection
    if (strpos($itemName, 'GIN') !== false ||
        strpos($itemName, 'LONDON DRY') !== false ||
        strpos($itemName, 'NAVY STRENGTH') !== false ||
        preg_match('/\b(BOMBAY|GORDON\'S|TANQUERAY|BEEFEATER|HENDRICK\'S|BLUE RIBAND)\b/', $itemName) ||
        strpos($itemName, 'JUNIPER') !== false ||
        strpos($itemName, 'BOTANICAL GIN') !== false ||
        strpos($itemName, 'DRY GIN') !== false) {
        return 'G';
    }
    
    // RUM Detection
    if (strpos($itemName, 'RUM') !== false ||
        strpos($itemName, 'DARK RUM') !== false ||
        strpos($itemName, 'WHITE RUM') !== false ||
        strpos($itemName, 'SPICED RUM') !== false ||
        strpos($itemName, 'AGED RUM') !== false ||
        preg_match('/\b(BACARDI|CAPTAIN MORGAN|OLD MONK|HAVANA CLUB|MCDOWELL\'S RUM|CONTESSA RUM)\b/', $itemName) ||
        strpos($itemName, 'GOLD RUM') !== false ||
        strpos($itemName, 'NAVY RUM') !== false) {
        return 'R';
    }
    
    // BEER Detection
    if (strpos($itemName, 'BEER') !== false || 
        strpos($itemName, 'LAGER') !== false ||
        strpos($itemName, 'ALE') !== false ||
        strpos($itemName, 'STOUT') !== false ||
        strpos($itemName, 'PILSNER') !== false ||
        strpos($itemName, 'DRAUGHT') !== false ||
        preg_match('/\b(KINGFISHER|TUBORG|CARLSBERG|BUDWEISER|HEINEKEN|CORONA|FOSTER\'S)\b/', $itemName)) {
        
        $strongIndicators = ['STRONG', 'SUPER STRONG', 'EXTRA STRONG', 'BOLD', 'HIGH', 'POWER', 'XXX', '5000', '8000', '9000', '10000'];
        $mildIndicators = ['MILD', 'SMOOTH', 'LIGHT', 'DRAUGHT', 'LAGER', 'PILSNER', 'REGULAR', 'PREMIUM', 'LITE'];
        
        $isStrongBeer = false;
        foreach ($strongIndicators as $indicator) {
            if (strpos($itemName, $indicator) !== false) {
                $isStrongBeer = true;
                break;
            }
        }
        
        if ($isStrongBeer) {
            return 'F'; // FERMENTED BEER (Strong)
        } else {
            return 'M'; // MILD BEER
        }
    }
    
    // Default to Others if no match found
    return 'O';
}

// Function to update item stock information for all tables
function updateItemStockAllTables($conn, $comp_id, $item_code, $liqFlag, $opening_balance) {
    $current_year = date('Y');
    
    // 1. Update tblitem_stock
    $check_stock_query = "SELECT COUNT(*) as count FROM tblitem_stock WHERE ITEM_CODE = ?";
    $check_stmt = $conn->prepare($check_stock_query);
    $check_stmt->bind_param("s", $item_code);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $stock_exists = $check_result->fetch_assoc()['count'] > 0;
    $check_stmt->close();
    
    $opening_col = "OPENING_STOCK$comp_id";
    $current_col = "CURRENT_STOCK$comp_id";
    
    if ($stock_exists) {
        // Update existing stock record
        $update_query = "UPDATE tblitem_stock SET $opening_col = ?, $current_col = ?, FIN_YEAR = ? WHERE ITEM_CODE = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("iiis", $opening_balance, $opening_balance, $current_year, $item_code);
        $update_stmt->execute();
        $update_stmt->close();
    } else {
        // Insert new stock record
        $insert_query = "INSERT INTO tblitem_stock (ITEM_CODE, FIN_YEAR, $opening_col, $current_col) VALUES (?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("siii", $item_code, $current_year, $opening_balance, $opening_balance);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
    
    // 2. Update current month's daily stock table (tbldailystock_$comp_id)
    $today = date('d');
    $today_padded = str_pad($today, 2, '0', STR_PAD_LEFT);
    $current_month = date('Y-m');
    
    // Check if current month daily stock record exists
    $check_daily_query = "SELECT COUNT(*) as count FROM tbldailystock_$comp_id 
                         WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
    $check_daily_stmt = $conn->prepare($check_daily_query);
    $check_daily_stmt->bind_param("sss", $current_month, $item_code, $liqFlag);
    $check_daily_stmt->execute();
    $daily_result = $check_daily_stmt->get_result();
    $daily_exists = $daily_result->fetch_assoc()['count'] > 0;
    $check_daily_stmt->close();
    
    if ($daily_exists) {
        // Update existing daily record
        $update_daily_query = "UPDATE tbldailystock_$comp_id 
                              SET DAY_{$today_padded}_OPEN = ?, 
                                  DAY_{$today_padded}_CLOSING = ?,
                                  LAST_UPDATED = CURRENT_TIMESTAMP 
                              WHERE STK_MONTH = ? AND ITEM_CODE = ? AND LIQ_FLAG = ?";
        $update_daily_stmt = $conn->prepare($update_daily_query);
        $update_daily_stmt->bind_param("iisss", $opening_balance, $opening_balance, $current_month, $item_code, $liqFlag);
        $update_daily_stmt->execute();
        $update_daily_stmt->close();
    } else {
        // Insert new daily record
        $insert_daily_query = "INSERT INTO tbldailystock_$comp_id 
                              (STK_MONTH, ITEM_CODE, LIQ_FLAG, DAY_{$today_padded}_OPEN, DAY_{$today_padded}_CLOSING) 
                              VALUES (?, ?, ?, ?, ?)";
        $insert_daily_stmt = $conn->prepare($insert_daily_query);
        $insert_daily_stmt->bind_param("sssii", $current_month, $item_code, $liqFlag, $opening_balance, $opening_balance);
        $insert_daily_stmt->execute();
        $insert_daily_stmt->close();
    }
    
    // 3. Update monthly table (tbldailystock_{comp_id}_{mm_yy})
    $month_short = date('m_y'); // Format: 01_26 for January 2026
    $monthly_table_name = "tbldailystock_{$comp_id}_{$month_short}";
    
    // Check if monthly table exists
    $check_table_query = "SHOW TABLES LIKE '$monthly_table_name'";
    $table_result = $conn->query($check_table_query);
    
    if ($table_result->num_rows > 0) {
        // Monthly table exists, update it
        $check_monthly_query = "SELECT COUNT(*) as count FROM $monthly_table_name 
                               WHERE ITEM_CODE = ? AND LIQ_FLAG = ?";
        $check_monthly_stmt = $conn->prepare($check_monthly_query);
        $check_monthly_stmt->bind_param("ss", $item_code, $liqFlag);
        $check_monthly_stmt->execute();
        $monthly_result = $check_monthly_stmt->get_result();
        $monthly_exists = $monthly_result->fetch_assoc()['count'] > 0;
        $check_monthly_stmt->close();
        
        if ($monthly_exists) {
            // Update existing monthly record
            $update_monthly_query = "UPDATE $monthly_table_name 
                                    SET DAY_{$today_padded}_OPEN = ?, 
                                        DAY_{$today_padded}_CLOSING = ?,
                                        LAST_UPDATED = CURRENT_TIMESTAMP 
                                    WHERE ITEM_CODE = ? AND LIQ_FLAG = ?";
            $update_monthly_stmt = $conn->prepare($update_monthly_query);
            $update_monthly_stmt->bind_param("iiss", $opening_balance, $opening_balance, $item_code, $liqFlag);
            $update_monthly_stmt->execute();
            $update_monthly_stmt->close();
        } else {
            // Insert new monthly record
            $insert_monthly_query = "INSERT INTO $monthly_table_name 
                                    (ITEM_CODE, LIQ_FLAG, DAY_{$today_padded}_OPEN, DAY_{$today_padded}_CLOSING) 
                                    VALUES (?, ?, ?, ?)";
            $insert_monthly_stmt = $conn->prepare($insert_monthly_query);
            $insert_monthly_stmt->bind_param("ssii", $item_code, $liqFlag, $opening_balance, $opening_balance);
            $insert_monthly_stmt->execute();
            $insert_monthly_stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Item - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?= time() ?>">
    <!-- Include shortcuts functionality -->
    <script src="components/shortcuts.js?v=<?= time() ?>"></script>
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .content-area {
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .form-label {
            font-weight: 500;
        }
        .action-btn {
            margin-top: 20px;
        }
        h3 {
            color: #2c3e50;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        .license-info {
            background-color: #e7f3ff;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        .class-detection-info {
            background-color: #fff3cd;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
        }
        .custom-dropdown {
            position: relative;
        }
        .custom-dropdown select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            padding-right: 30px;
        }
        .custom-dropdown:after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #6c757d;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    <div class="main-content">

        <div class="content-area">
            <h3 class="mb-4">Add New Item</h3>

            <!-- License Restriction Info -->
            <div class="license-info mb-3">
                <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
                <p class="mb-0">Available classes: 
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

            <!-- Class Detection Info -->
            <div class="class-detection-info mb-3">
                <strong>Note:</strong> Class will be automatically detected from the Item Name using intelligent pattern matching.
                You don't need to select a class manually.
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="row g-3" id="add_item_form">
                <!-- Mode -->
                <div class="col-md-3">
                    <label for="mode" class="form-label">Mode</label>
                    <select
                        id="mode"
                        name="mode"
                        class="form-select"
                        onchange="window.location.href='<?= htmlspecialchars($currentPage) ?>?mode='+this.value;">
                        <option value="F" <?= $mode === 'F' ? 'selected' : '' ?>>Foreign Liquor</option>
                        <option value="C" <?= $mode === 'C' ? 'selected' : '' ?>>Country Liquor</option>
                        <option value="O" <?= $mode === 'O' ? 'selected' : '' ?>>Others</option>
                    </select>
                </div>

                <!-- Code -->
                <div class="col-md-3">
                    <label for="code" class="form-label">Item Code *</label>
                    <input type="text" id="code" name="code" class="form-control"
                           value="<?= htmlspecialchars($code) ?>" required>
                </div>

                <!-- New Code -->
                <div class="col-md-3">
                    <label for="Print_Name" class="form-label">Print Name</label>
                    <input type="text" id="Print_Name" name="Print_Name" class="form-control"
                           value="<?= htmlspecialchars($Print_Name) ?>">
                </div>

                <!-- Item Name -->
                <div class="col-md-3">
                    <label for="details" class="form-label">Item Name *</label>
                    <input type="text" id="details" name="details" class="form-control"
                           value="<?= htmlspecialchars($details) ?>" required 
                           onblur="detectClass()">
                    <small class="text-muted">Class will be auto-detected from this field</small>
                </div>

                <!-- Detected Class Display (Readonly) -->
                <div class="col-md-3">
                    <label class="form-label">Detected Class</label>
                    <input type="text" id="detected_class" class="form-control" readonly 
                           placeholder="Class will appear here">
                </div>

                <!-- Additional Details (Subclass) - Changed to Dropdown -->
                <div class="col-md-3">
                    <label for="details2" class="form-label">Subclass/Description</label>
                    <div class="custom-dropdown">
                        <select id="details2" name="details2" class="form-select">
                            <option value="">-- Select Subclass --</option>
                            <?php foreach ($subclasses as $subclass): ?>
                                <option value="<?= htmlspecialchars($subclass['subclass_name']) ?>" 
                                    <?= $details2 === $subclass['subclass_name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subclass['subclass_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <small class="text-muted">Select from predefined subclasses</small>
                </div>

                <!-- Barcode -->
                <div class="col-md-3">
                    <label for="BARCODE" class="form-label">Barcode</label>
                    <input type="text" id="BARCODE" name="BARCODE" class="form-control"
                           value="<?= htmlspecialchars($BARCODE) ?>">
                </div>

                <!-- Opening Balance -->
                <div class="col-md-3">
                    <label for="opening_balance" class="form-label">Opening Balance</label>
                    <input type="number" id="opening_balance" name="opening_balance" class="form-control"
                           value="<?= htmlspecialchars($opening_balance) ?>" min="0" step="1">
                </div>

                <!-- P. Price -->
                <div class="col-md-3">
                    <label for="pprice" class="form-label">Purchase Price</label>
                    <input type="number" step="0.001" id="pprice" name="pprice" class="form-control"
                           value="<?= htmlspecialchars($pprice) ?>">
                </div>

                <!-- B. Price -->
                <div class="col-md-3">
                    <label for="bprice" class="form-label">Base Price</label>
                    <input type="number" step="0.001" id="bprice" name="bprice" class="form-control"
                           value="<?= htmlspecialchars($bprice) ?>">
                </div>

                <!-- M. Price -->
                <div class="col-md-3">
                    <label for="mprice" class="form-label">MRP Price</label>
                    <input type="number" step="0.001" id="mprice" name="mprice" class="form-control"
                           value="<?= htmlspecialchars($mprice) ?>">
                </div>

                <!-- R. Price -->
                <div class="col-md-3">
                    <label for="RPRICE" class="form-label">Retail Price</label>
                    <input type="number" step="0.001" id="RPRICE" name="RPRICE" class="form-control"
                           value="<?= htmlspecialchars($RPRICE) ?>">
                </div>

                <!-- Buttons -->
                <div class="col-12 action-btn mb-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                    <a href="item_master.php?mode=<?= htmlspecialchars($mode) ?>" class="btn btn-secondary ms-auto">
                        <i class="fas fa-arrow-left"></i> Back to Item Master
                    </a>
                </div>
            </form>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Simple client-side class detection (basic version)
function detectClass() {
    const itemName = document.getElementById('details').value.toUpperCase();
    const detectedClassField = document.getElementById('detected_class');

    if (!itemName) {
        detectedClassField.value = '';
        return;
    }

    // Basic detection logic (simplified version of server-side logic)
    let detectedClass = 'O'; // Default to Others

    if (itemName.includes('WHISKY') || itemName.includes('WHISKEY') || itemName.includes('SCOTCH')) {
        detectedClass = 'W (Whisky)';
    } else if (itemName.includes('WINE') || itemName.includes('CHAMPAGNE')) {
        detectedClass = 'V (Wine)';
    } else if (itemName.includes('BRANDY') || itemName.includes('COGNAC') || itemName.includes('VSOP')) {
        detectedClass = 'D (Brandy)';
    } else if (itemName.includes('VODKA')) {
        detectedClass = 'K (Vodka)';
    } else if (itemName.includes('GIN')) {
        detectedClass = 'G (Gin)';
    } else if (itemName.includes('RUM')) {
        detectedClass = 'R (Rum)';
    } else if (itemName.includes('BEER')) {
        if (itemName.includes('STRONG') || itemName.includes('5000') || itemName.includes('8000')) {
            detectedClass = 'F (Fermented Beer - Strong)';
        } else {
            detectedClass = 'M (Mild Beer)';
        }
    }

    detectedClassField.value = detectedClass;
}

// Show loading overlay during form submission
document.getElementById('add_item_form').addEventListener('submit', function() {
    // Show loading overlay
    const loadingOverlay = document.createElement('div');
    loadingOverlay.id = 'loading_overlay';
    loadingOverlay.style.position = 'fixed';
    loadingOverlay.style.top = '0';
    loadingOverlay.style.left = '0';
    loadingOverlay.style.width = '100%';
    loadingOverlay.style.height = '100%';
    loadingOverlay.style.backgroundColor = 'rgba(255,255,255,0.8)';
    loadingOverlay.style.zIndex = '9999';
    loadingOverlay.style.display = 'flex';
    loadingOverlay.style.justifyContent = 'center';
    loadingOverlay.style.alignItems = 'center';
    loadingOverlay.innerHTML = `
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2">Adding item, please wait...</p>
        </div>
    `;
    document.body.appendChild(loadingOverlay);
});
</script>
</body>
</html>