<?php
session_start();

// Handle AJAX requests first
if (isset($_GET['ajax'])) {
    include_once "../config/db.php";
    
    switch ($_GET['ajax']) {
        case 'get_classes':
            $category_code = $_GET['category'] ?? '';
            $mode = $_GET['mode'] ?? 'F';
            $classes = [];
            
            if ($category_code) {
                $stmt = $conn->prepare("
                    SELECT CLASS_CODE, CLASS_NAME 
                    FROM tblclass_new 
                    WHERE CATEGORY_CODE = ? AND LIQ_FLAG = ?
                    ORDER BY CLASS_NAME
                ");
                $stmt->bind_param("ss", $category_code, $mode);
                $stmt->execute();
                $result = $stmt->get_result();
                $classes = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            
            header('Content-Type: application/json');
            echo json_encode($classes);
            exit;
            
        case 'get_subclasses':
            $class_code = $_GET['class'] ?? '';
            $subclasses = [];
            
            if ($class_code) {
                $stmt = $conn->prepare("
                    SELECT SUBCLASS_CODE, SUBCLASS_NAME 
                    FROM tblsubclass_new 
                    WHERE CLASS_CODE = ?
                    ORDER BY SUBCLASS_NAME
                ");
                $stmt->bind_param("s", $class_code);
                $stmt->execute();
                $result = $stmt->get_result();
                $subclasses = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            
            header('Content-Type: application/json');
            echo json_encode($subclasses);
            exit;
            
        case 'get_sizes':
            $subclass_code = $_GET['subclass'] ?? '';
            $sizes = [];
            
            if ($subclass_code) {
                // Get all sizes for the current mode
                $mode = $_GET['mode'] ?? 'F';
                $stmt = $conn->prepare("
                    SELECT SIZE_CODE, SIZE_DESC 
                    FROM tblsize 
                    WHERE LIQ_FLAG = ?
                    ORDER BY ML_VOLUME
                ");
                $stmt->bind_param("s", $mode);
                $stmt->execute();
                $result = $stmt->get_result();
                $sizes = $result->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
            }
            
            header('Content-Type: application/json');
            echo json_encode($sizes);
            exit;
    }
}

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";
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

// Get item code and mode from URL
$item_code = isset($_GET['code']) ? $_GET['code'] : null;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Fetch categories based on mode
$categories = [];
if ($stmt = $conn->prepare("
    SELECT CATEGORY_CODE, CATEGORY_NAME 
    FROM tblcategory 
    WHERE LIQ_FLAG = ? 
    ORDER BY CATEGORY_NAME
")) {
    $stmt->bind_param("s", $mode);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) $categories = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Fetch item details
$item = null;
$opening_balance = 0;
$item_category_code = $item_class_code = $item_subclass_code = $item_size_code = '';
$category_name = $class_name = $subclass_name = $size_desc = '';

if ($item_code) {
    $stmt = $conn->prepare("SELECT * FROM tblitemmaster WHERE CODE = ?");
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
    
    // Get opening balance from tblitem_stock
    if ($item) {
        $stock_query = "SELECT OPENING_STOCK{$company_id} as opening 
                       FROM tblitem_stock 
                       WHERE ITEM_CODE = ?";
        $stock_stmt = $conn->prepare($stock_query);
        $stock_stmt->bind_param("s", $item_code);
        $stock_stmt->execute();
        $stock_result = $stock_stmt->get_result();
        
        if ($stock_result->num_rows > 0) {
            $stock_row = $stock_result->fetch_assoc();
            $opening_balance = $stock_row['opening'];
        }
        $stock_stmt->close();
        
        // Get category, class, subclass and size information for the item
        // First, find the class details to get category
        if (!empty($item['CLASS_CODE_NEW'])) {
            // Get the class details
            $stmt = $conn->prepare("
                SELECT c.CLASS_CODE, c.CLASS_NAME, c.CATEGORY_CODE, cat.CATEGORY_NAME 
                FROM tblclass_new c
                JOIN tblcategory cat ON c.CATEGORY_CODE = cat.CATEGORY_CODE
                WHERE c.CLASS_CODE = ?
            ");
            $stmt->bind_param("s", $item['CLASS_CODE_NEW']);
            $stmt->execute();
            $class_result = $stmt->get_result();
            if ($class_result->num_rows > 0) {
                $class_row = $class_result->fetch_assoc();
                $item_class_code = $class_row['CLASS_CODE'];
                $class_name = $class_row['CLASS_NAME'];
                $item_category_code = $class_row['CATEGORY_CODE'];
                $category_name = $class_row['CATEGORY_NAME'];
            }
            $stmt->close();
            
            // Get subclass details
            if (!empty($item['SUBCLASS_CODE_NEW'])) {
                $stmt = $conn->prepare("
                    SELECT SUBCLASS_CODE, SUBCLASS_NAME 
                    FROM tblsubclass_new 
                    WHERE SUBCLASS_CODE = ?
                ");
                $stmt->bind_param("s", $item['SUBCLASS_CODE_NEW']);
                $stmt->execute();
                $subclass_result = $stmt->get_result();
                if ($subclass_result->num_rows > 0) {
                    $subclass_row = $subclass_result->fetch_assoc();
                    $item_subclass_code = $subclass_row['SUBCLASS_CODE'];
                    $subclass_name = $subclass_row['SUBCLASS_NAME'];
                }
                $stmt->close();
            }
            
            // Get size details
            if (!empty($item['SIZE_CODE'])) {
                $stmt = $conn->prepare("
                    SELECT SIZE_CODE, SIZE_DESC 
                    FROM tblsize 
                    WHERE SIZE_CODE = ?
                ");
                $stmt->bind_param("s", $item['SIZE_CODE']);
                $stmt->execute();
                $size_result = $stmt->get_result();
                if ($size_result->num_rows > 0) {
                    $size_row = $size_result->fetch_assoc();
                    $item_size_code = $size_row['SIZE_CODE'];
                    $size_desc = $size_row['SIZE_DESC'];
                }
                $stmt->close();
            }
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $Print_Name = $_POST['Print_Name'];
    $details = $_POST['details'];
    $BARCODE = $_POST['BARCODE'];
    $category_code = $_POST['category_code'];
    $class_code = $_POST['class_code'];
    $subclass_code = $_POST['subclass_code'];
    $size_code = $_POST['size_code']; // This will save to SIZE_CODE column
    $pprice = floatval($_POST['pprice'] ?? 0);
    $bprice = floatval($_POST['bprice'] ?? 0);
    $mprice = floatval($_POST['mprice'] ?? 0);
    $RPRICE = floatval($_POST['RPRICE'] ?? 0);
    $opening_balance = intval($_POST['opening_balance'] ?? 0);

    // Get names for hidden fields
    $category_name = $_POST['category_name'] ?? '';
    $class_name = $_POST['class_name'] ?? '';
    $subclass_name = $_POST['subclass_name'] ?? '';
    $size_desc = $_POST['size_desc'] ?? '';

    // Get the OLD_CLASS_CODE from tblclass_new
    $class = ''; // This will be the single-letter class code (W, V, D, etc.)
    if (!empty($class_code)) {
        $stmt = $conn->prepare("SELECT OLD_CLASS_CODE FROM tblclass_new WHERE CLASS_CODE = ?");
        $stmt->bind_param("s", $class_code);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $class = $row['OLD_CLASS_CODE'];
        }
        $stmt->close();
    }
    
    // If OLD_CLASS_CODE is not available, use a mapping based on class name
    if (empty($class) && !empty($class_name)) {
        $class_name_upper = strtoupper($class_name);
        if (strpos($class_name_upper, 'WHISKY') !== false || strpos($class_name_upper, 'IMFL') !== false) {
            $class = 'W';
        } elseif (strpos($class_name_upper, 'WINE') !== false) {
            $class = 'V';
        } elseif (strpos($class_name_upper, 'BRANDY') !== false) {
            $class = 'D';
        } elseif (strpos($class_name_upper, 'VODKA') !== false) {
            $class = 'K';
        } elseif (strpos($class_name_upper, 'GIN') !== false) {
            $class = 'G';
        } elseif (strpos($class_name_upper, 'RUM') !== false) {
            $class = 'R';
        } elseif (strpos($class_name_upper, 'FERMENTED') !== false) {
            $class = 'F';
        } elseif (strpos($class_name_upper, 'MILD') !== false) {
            $class = 'M';
        } elseif (strpos($class_name_upper, 'COUNTRY') !== false) {
            $class = 'C';
        } else {
            $class = 'O'; // Default to Others
        }
    }

    // Validate class against license restrictions
    if (!in_array($class, $allowed_classes)) {
        $_SESSION['error_message'] = "Selected class is not allowed for your license type.";
        header("Location: edit_item.php?code=" . urlencode($item_code) . "&mode=" . $mode);
        exit;
    }

    // Get ITEM_GROUP from selected subclass (default)
    $item_group = 'O'; // Default to Others
    if (!empty($subclass_code)) {
        $stmt = $conn->prepare("SELECT OLD_ITEM_GROUP FROM tblsubclass_new WHERE SUBCLASS_CODE = ? LIMIT 1");
        $stmt->bind_param("s", $subclass_code);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $item_group = $row['OLD_ITEM_GROUP'];
        }
        $stmt->close();
    }
    
    // Override with size's item group if size is selected
    if (!empty($size_code)) {
        $stmt = $conn->prepare("SELECT OLD_ITEM_GROUP FROM tblsize WHERE SIZE_CODE = ? LIMIT 1");
        $stmt->bind_param("s", $size_code);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $item_group = $row['OLD_ITEM_GROUP'];
        }
        $stmt->close();
    }

    // For SUB_CLASS, use the first character of subclass or a default
    $subClassField = !empty($subclass_name) ? substr($subclass_name, 0, 1) : 'O';

    // Build DETAILS2 from hierarchy
    $details2 = $category_name . " > " . $class_name . " > " . $subclass_name;
    if (!empty($size_desc)) {
        $details2 .= " > " . $size_desc;
    }

    // Update tblitemmaster with all fields including SIZE_CODE
    $stmt = $conn->prepare("UPDATE tblitemmaster SET 
                          Print_Name = ?, 
                          DETAILS = ?, 
                          DETAILS2 = ?,
                          CLASS = ?, 
                          SUB_CLASS = ?, 
                          ITEM_GROUP = ?,
                          PPRICE = ?, 
                          BPRICE = ?,
                          MPRICE = ?,
                          RPRICE = ?,
                          BARCODE = ?,
                          CATEGORY_CODE = ?,
                          CLASS_CODE_NEW = ?,
                          SUBCLASS_CODE_NEW = ?,
                          SIZE_CODE = ?  /* This column name is correct as per database */
                          WHERE CODE = ?");
    
    $stmt->bind_param("ssssssddddssssss", 
        $Print_Name, 
        $details, 
        $details2, 
        $class, 
        $subClassField, 
        $item_group, 
        $pprice, 
        $bprice, 
        $mprice, 
        $RPRICE, 
        $BARCODE, 
        $category_code, 
        $class_code, 
        $subclass_code, 
        $size_code,  // This maps to SIZE_CODE column
        $item_code
    );
    
    if ($stmt->execute()) {
        // Update stock information using the same function as in add_item.php
        updateItemStockAllTables($conn, $company_id, $item_code, $mode, $opening_balance);
        
        $_SESSION['success_message'] = "Item updated successfully!";
        header("Location: item_master.php?mode=" . $mode);
        exit;
    } else {
        $_SESSION['error_message'] = "Error updating item: " . $conn->error;
    }
    $stmt->close();
}

// Function to update item stock information for all tables (same as in add_item.php)
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
    <title>Edit Item - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?= time() ?>">
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
        .form-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
        }
        .form-section h5 {
            color: #2c3e50;
            margin-bottom: 15px;
        }
        .hierarchy-info {
            font-size: 0.9rem;
            color: #666;
            font-style: italic;
        }
        .dropdown-loading {
            color: #6c757d;
            font-style: italic;
        }
        .selected-class {
            background-color: #e7f3ff;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #3498db;
            font-weight: 500;
        }
        .current-selection-info {
            background-color: #fff3cd;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            border-left: 4px solid #ffc107;
        }
        .required-field::after {
            content: " *";
            color: red;
        }
        .size-info {
            font-size: 0.85rem;
            color: #28a745;
            margin-top: 5px;
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    <div class="main-content">

        <div class="content-area">
            <h3 class="mb-4">Edit Item</h3>

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


            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger"><?= $_SESSION['error_message'] ?></div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?= $_SESSION['success_message'] ?></div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>

            <?php if ($item): ?>
            <form method="POST" class="row g-3" id="edit_item_form">
                <!-- Hidden fields for names and original values -->
                <input type="hidden" id="category_name" name="category_name" value="<?= htmlspecialchars($category_name) ?>">
                <input type="hidden" id="class_name" name="class_name" value="<?= htmlspecialchars($class_name) ?>">
                <input type="hidden" id="subclass_name" name="subclass_name" value="<?= htmlspecialchars($subclass_name) ?>">
                <input type="hidden" id="size_desc" name="size_desc" value="<?= htmlspecialchars($size_desc) ?>">
                
                <!-- Mode -->
                <div class="col-md-3">
                    <label for="mode" class="form-label">Mode</label>
                    <select
                        id="mode"
                        name="mode"
                        class="form-select"
                        onchange="window.location.href='edit_item.php?code=<?= htmlspecialchars($item_code) ?>&mode='+this.value;">
                        <option value="F" <?= $mode === 'F' ? 'selected' : '' ?>>Foreign Liquor</option>
                        <option value="C" <?= $mode === 'C' ? 'selected' : '' ?>>Country Liquor</option>
                        <option value="O" <?= $mode === 'O' ? 'selected' : '' ?>>Others</option>
                    </select>
                </div>

                <!-- Item Code -->
                <div class="col-md-3">
                    <label for="code" class="form-label">Item Code</label>
                    <input type="text" id="code" class="form-control" 
                           value="<?= htmlspecialchars($item['CODE']) ?>" readonly disabled>
                </div>

                <!-- Print Name -->
                <div class="col-md-3">
                    <label for="Print_Name" class="form-label">Print Name</label>
                    <input type="text" id="Print_Name" name="Print_Name" class="form-control"
                           value="<?= htmlspecialchars($item['Print_Name']) ?>">
                </div>

                <!-- Item Name -->
                <div class="col-md-3">
                    <label for="details" class="form-label required-field">Item Name</label>
                    <input type="text" id="details" name="details" class="form-control"
                           value="<?= htmlspecialchars($item['DETAILS']) ?>" required>
                </div>

                <!-- Selected Class Display -->
                <div class="col-md-3">
                    <label class="form-label">Selected Class</label>
                    <div id="selected_class_display" class="selected-class">
                        <?php if (!empty($class_name)): ?>
                            <?= htmlspecialchars($class_name) ?>
                        <?php else: ?>
                            <span class="text-muted">Select Class to see here</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Barcode -->
                <div class="col-md-3">
                    <label for="BARCODE" class="form-label">Barcode</label>
                    <input type="text" id="BARCODE" name="BARCODE" class="form-control"
                           value="<?= htmlspecialchars($item['BARCODE'] ?? '') ?>">
                </div>

                <!-- Category Section -->
                <div class="col-12 form-section">
                    <h5>Category & Classification</h5>
                    <p class="hierarchy-info mb-3">Select in order: Category → Class → Subclass → Size</p>
                    
                    <div class="row g-3">
                        <!-- Category -->
                        <div class="col-md-3">
                            <label for="category_code" class="form-label required-field">Category</label>
                            <div class="custom-dropdown">
                                <select id="category_code" name="category_code" class="form-select" required
                                        onchange="loadClasses(this.value)">
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?= htmlspecialchars($category['CATEGORY_CODE']) ?>"
                                            <?= $item_category_code === $category['CATEGORY_CODE'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($category['CATEGORY_NAME']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Class -->
                        <div class="col-md-3">
                            <label for="class_code" class="form-label required-field">Class</label>
                            <div class="custom-dropdown">
                                <select id="class_code" name="class_code" class="form-select" required
                                        onchange="updateSelectedClass(); loadSubclasses(this.value)">
                                    <option value="">-- Select Class --</option>
                                    <?php if (!empty($item_class_code)): ?>
                                        <option value="<?= htmlspecialchars($item_class_code) ?>" selected>
                                            <?= htmlspecialchars($class_name) ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Subclass -->
                        <div class="col-md-3">
                            <label for="subclass_code" class="form-label required-field">Subclass</label>
                            <div class="custom-dropdown">
                                <select id="subclass_code" name="subclass_code" class="form-select" required
                                        onchange="loadSizes(this.value); updateSubclassName(this)">
                                    <option value="">-- Select Subclass --</option>
                                    <?php if (!empty($item_subclass_code)): ?>
                                        <option value="<?= htmlspecialchars($item_subclass_code) ?>" selected>
                                            <?= htmlspecialchars($subclass_name) ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Size (Optional) -->
                        <div class="col-md-3">
                            <label for="size_code" class="form-label">Size (Optional)</label>
                            <div class="custom-dropdown">
                                <select id="size_code" name="size_code" class="form-select"
                                        onchange="updateSizeDesc(this)">
                                    <option value="">-- Select Size --</option>
                                    <?php if (!empty($item_size_code)): ?>
                                        <option value="<?= htmlspecialchars($item_size_code) ?>" selected>
                                            <?= htmlspecialchars($size_desc) ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div id="selected_size_display" class="size-info">
                                <?php if (!empty($size_desc)): ?>
                                    Selected: <?= htmlspecialchars($size_desc) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stock & Pricing Section -->
                <div class="col-12 form-section">
                    <h5>Stock & Pricing</h5>
                    <div class="row g-3">
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
                                   value="<?= htmlspecialchars($item['PPRICE']) ?>">
                        </div>

                        <!-- B. Price -->
                        <div class="col-md-3">
                            <label for="bprice" class="form-label">Base Price</label>
                            <input type="number" step="0.001" id="bprice" name="bprice" class="form-control"
                                   value="<?= htmlspecialchars($item['BPRICE']) ?>">
                        </div>

                        <!-- M. Price -->
                        <div class="col-md-3">
                            <label for="mprice" class="form-label">MRP Price</label>
                            <input type="number" step="0.001" id="mprice" name="mprice" class="form-control"
                                   value="<?= htmlspecialchars($item['MPRICE'] ?? '') ?>">
                        </div>

                        <!-- R. Price -->
                        <div class="col-md-3">
                            <label for="RPRICE" class="form-label">Retail Price</label>
                            <input type="number" step="0.001" id="RPRICE" name="RPRICE" class="form-control"
                                   value="<?= htmlspecialchars($item['RPRICE'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="col-12 action-btn mb-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Item
                    </button>
                    <a href="item_master.php?mode=<?= htmlspecialchars($mode) ?>" class="btn btn-secondary ms-auto">
                        <i class="fas fa-arrow-left"></i> Back to Item Master
                    </a>
                </div>
            </form>
            <?php else: ?>
                <div class="alert alert-danger">Item not found.</div>
            <?php endif; ?>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Update selected class display
function updateSelectedClass() {
    const classSelect = document.getElementById('class_code');
    const selectedClassDisplay = document.getElementById('selected_class_display');
    
    if (classSelect.value) {
        const selectedOption = classSelect.options[classSelect.selectedIndex];
        selectedClassDisplay.textContent = selectedOption.textContent;
        selectedClassDisplay.classList.remove('text-muted');
        
        // Update hidden field
        document.getElementById('class_name').value = selectedOption.textContent;
    } else {
        selectedClassDisplay.innerHTML = '<span class="text-muted">Select Class to see here</span>';
        document.getElementById('class_name').value = '';
    }
}

// Update subclass name hidden field
function updateSubclassName(selectElement) {
    if (selectElement.value) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        document.getElementById('subclass_name').value = selectedOption.textContent;
    } else {
        document.getElementById('subclass_name').value = '';
    }
}

// Update size description hidden field and display
function updateSizeDesc(selectElement) {
    const sizeDisplay = document.getElementById('selected_size_display');
    
    if (selectElement.value) {
        const selectedOption = selectElement.options[selectElement.selectedIndex];
        document.getElementById('size_desc').value = selectedOption.textContent;
        sizeDisplay.innerHTML = 'Selected: ' + selectedOption.textContent;
        sizeDisplay.style.color = '#28a745';
    } else {
        document.getElementById('size_desc').value = '';
        sizeDisplay.innerHTML = '';
    }
}

// AJAX function to load classes
function loadClasses(categoryCode) {
    if (!categoryCode) {
        document.getElementById('class_code').innerHTML = '<option value="">-- Select Class --</option>';
        document.getElementById('subclass_code').innerHTML = '<option value="">-- Select Subclass --</option>';
        document.getElementById('size_code').innerHTML = '<option value="">-- Select Size --</option>';
        document.getElementById('selected_size_display').innerHTML = '';
        updateSelectedClass();
        
        // Update hidden field
        document.getElementById('category_name').value = '';
        return;
    }

    // Show loading
    const classSelect = document.getElementById('class_code');
    classSelect.innerHTML = '<option value="" class="dropdown-loading">Loading...</option>';
    
    // Update hidden field for category name
    const categorySelect = document.getElementById('category_code');
    const selectedCategoryOption = categorySelect.options[categorySelect.selectedIndex];
    document.getElementById('category_name').value = selectedCategoryOption.textContent;
    
    fetch('edit_item.php?ajax=get_classes&category=' + categoryCode + '&mode=<?= $mode ?>')
        .then(response => response.json())
        .then(data => {
            classSelect.innerHTML = '<option value="">-- Select Class --</option>';
            
            if (data.length > 0) {
                data.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.CLASS_CODE;
                    option.textContent = cls.CLASS_NAME;
                    
                    // Check if this is the current class
                    const currentClassCode = '<?= $item_class_code ?>';
                    if (cls.CLASS_CODE === currentClassCode) {
                        option.selected = true;
                        // Trigger subclass loading
                        setTimeout(() => {
                            updateSelectedClass();
                            loadSubclasses(cls.CLASS_CODE);
                        }, 100);
                    }
                    
                    // Auto-select for specific cases (like Fermented Beer)
                    if (cls.CLASS_NAME === 'Fermented Beer' && !currentClassCode) {
                        option.selected = true;
                        // Trigger subclass loading
                        setTimeout(() => {
                            updateSelectedClass();
                            loadSubclasses(cls.CLASS_CODE);
                        }, 100);
                    }
                    
                    classSelect.appendChild(option);
                });
                
                // Update selected class display
                updateSelectedClass();
            }
            
            // Clear subclass and size dropdowns
            document.getElementById('subclass_code').innerHTML = '<option value="">-- Select Subclass --</option>';
            document.getElementById('size_code').innerHTML = '<option value="">-- Select Size --</option>';
            document.getElementById('selected_size_display').innerHTML = '';
            document.getElementById('subclass_name').value = '';
            document.getElementById('size_desc').value = '';
        })
        .catch(error => {
            console.error('Error loading classes:', error);
            classSelect.innerHTML = '<option value="">-- Select Class --</option>';
            updateSelectedClass();
        });
}

// AJAX function to load subclasses
function loadSubclasses(classCode) {
    if (!classCode) {
        document.getElementById('subclass_code').innerHTML = '<option value="">-- Select Subclass --</option>';
        document.getElementById('size_code').innerHTML = '<option value="">-- Select Size --</option>';
        document.getElementById('selected_size_display').innerHTML = '';
        document.getElementById('subclass_name').value = '';
        return;
    }

    // Show loading
    const subclassSelect = document.getElementById('subclass_code');
    subclassSelect.innerHTML = '<option value="" class="dropdown-loading">Loading...</option>';
    
    fetch('edit_item.php?ajax=get_subclasses&class=' + classCode)
        .then(response => response.json())
        .then(data => {
            subclassSelect.innerHTML = '<option value="">-- Select Subclass --</option>';
            
            if (data.length > 0) {
                data.forEach(subclass => {
                    const option = document.createElement('option');
                    option.value = subclass.SUBCLASS_CODE;
                    option.textContent = subclass.SUBCLASS_NAME;
                    
                    // Check if this is the current subclass
                    const currentSubclassCode = '<?= $item_subclass_code ?>';
                    if (subclass.SUBCLASS_CODE === currentSubclassCode) {
                        option.selected = true;
                        // Update hidden field
                        document.getElementById('subclass_name').value = subclass.SUBCLASS_NAME;
                        // Trigger size loading
                        setTimeout(() => loadSizes(subclass.SUBCLASS_CODE), 100);
                    }
                    
                    // Auto-select for specific cases
                    const classSelect = document.getElementById('class_code');
                    const selectedClass = classSelect.options[classSelect.selectedIndex].text;
                    
                    if (!currentSubclassCode) {
                        if (selectedClass === 'Fermented Beer' && subclass.SUBCLASS_NAME === 'Fermented Beer') {
                            option.selected = true;
                            document.getElementById('subclass_name').value = subclass.SUBCLASS_NAME;
                            // Trigger size loading
                            setTimeout(() => loadSizes(subclass.SUBCLASS_CODE), 100);
                        }
                        // Auto-select "Indian" for "Indian" class in Wine category
                        else if (selectedClass === 'Indian' && subclass.SUBCLASS_NAME === 'Indian') {
                            option.selected = true;
                            document.getElementById('subclass_name').value = subclass.SUBCLASS_NAME;
                            // Trigger size loading
                            setTimeout(() => loadSizes(subclass.SUBCLASS_CODE), 100);
                        }
                        // Auto-select same name for Country Liquor
                        else if (selectedClass === 'Country Liquor' && subclass.SUBCLASS_NAME === 'Country Liquor') {
                            option.selected = true;
                            document.getElementById('subclass_name').value = subclass.SUBCLASS_NAME;
                            // Trigger size loading
                            setTimeout(() => loadSizes(subclass.SUBCLASS_CODE), 100);
                        }
                    }
                    
                    subclassSelect.appendChild(option);
                });
                
                // If no subclass selected, clear hidden field
                if (!subclassSelect.value) {
                    document.getElementById('subclass_name').value = '';
                }
            }
            
            // Clear size dropdown
            document.getElementById('size_code').innerHTML = '<option value="">-- Select Size --</option>';
            document.getElementById('selected_size_display').innerHTML = '';
            document.getElementById('size_desc').value = '';
        })
        .catch(error => {
            console.error('Error loading subclasses:', error);
            subclassSelect.innerHTML = '<option value="">-- Select Subclass --</option>';
            document.getElementById('subclass_name').value = '';
        });
}

// AJAX function to load sizes
function loadSizes(subclassCode) {
    if (!subclassCode) {
        document.getElementById('size_code').innerHTML = '<option value="">-- Select Size --</option>';
        document.getElementById('selected_size_display').innerHTML = '';
        return;
    }

    // Show loading
    const sizeSelect = document.getElementById('size_code');
    sizeSelect.innerHTML = '<option value="" class="dropdown-loading">Loading...</option>';
    
    fetch('edit_item.php?ajax=get_sizes&subclass=' + subclassCode + '&mode=<?= $mode ?>')
        .then(response => response.json())
        .then(data => {
            sizeSelect.innerHTML = '<option value="">-- Select Size --</option>';
            
            if (data.length > 0) {
                data.forEach(size => {
                    const option = document.createElement('option');
                    option.value = size.SIZE_CODE;
                    option.textContent = size.SIZE_DESC;
                    
                    // Check if this is the current size
                    const currentSizeCode = '<?= $item_size_code ?>';
                    if (size.SIZE_CODE === currentSizeCode) {
                        option.selected = true;
                        document.getElementById('size_desc').value = size.SIZE_DESC;
                        
                        // Update size display
                        const sizeDisplay = document.getElementById('selected_size_display');
                        sizeDisplay.innerHTML = 'Selected: ' + size.SIZE_DESC;
                        sizeDisplay.style.color = '#28a745';
                    }
                    
                    sizeSelect.appendChild(option);
                });
                
                // If size is selected, update hidden field
                if (sizeSelect.value) {
                    const selectedOption = sizeSelect.options[sizeSelect.selectedIndex];
                    document.getElementById('size_desc').value = selectedOption.textContent;
                    const sizeDisplay = document.getElementById('selected_size_display');
                    sizeDisplay.innerHTML = 'Selected: ' + selectedOption.textContent;
                    sizeDisplay.style.color = '#28a745';
                } else {
                    document.getElementById('size_desc').value = '';
                    document.getElementById('selected_size_display').innerHTML = '';
                }
            }
        })
        .catch(error => {
            console.error('Error loading sizes:', error);
            sizeSelect.innerHTML = '<option value="">-- Select Size --</option>';
            document.getElementById('size_desc').value = '';
            document.getElementById('selected_size_display').innerHTML = '';
        });
}

// Initialize dropdowns if values are already selected
document.addEventListener('DOMContentLoaded', function() {
    const categoryCode = document.getElementById('category_code').value;
    const classCode = document.getElementById('class_code').value;
    const subclassCode = document.getElementById('subclass_code').value;
    const sizeCode = document.getElementById('size_code').value;
    const sizeDesc = document.getElementById('size_desc').value;
    
    // Update selected class display
    updateSelectedClass();
    
    // Update size display if size is selected
    if (sizeCode && sizeDesc) {
        const sizeDisplay = document.getElementById('selected_size_display');
        sizeDisplay.innerHTML = 'Selected: ' + sizeDesc;
        sizeDisplay.style.color = '#28a745';
    }
    
    // If we have category code but no class code loaded, load classes
    if (categoryCode && (!classCode || document.getElementById('class_code').options.length <= 2)) {
        // Set a timeout to ensure DOM is ready
        setTimeout(() => {
            loadClasses(categoryCode);
            
            // If we have class code, set it after loading
            if (classCode) {
                setTimeout(() => {
                    document.getElementById('class_code').value = classCode;
                    updateSelectedClass();
                    loadSubclasses(classCode);
                    
                    // If we have subclass code, set it after loading
                    if (subclassCode) {
                        setTimeout(() => {
                            document.getElementById('subclass_code').value = subclassCode;
                            
                            // Update subclass hidden field
                            const subclassSelect = document.getElementById('subclass_code');
                            if (subclassSelect.value) {
                                const selectedOption = subclassSelect.options[subclassSelect.selectedIndex];
                                document.getElementById('subclass_name').value = selectedOption.textContent;
                            }
                            
                            loadSizes(subclassCode);
                            
                            // If we have size code, set it after loading
                            if (sizeCode) {
                                setTimeout(() => {
                                    document.getElementById('size_code').value = sizeCode;
                                    // Update size_desc hidden field and display
                                    const sizeSelect = document.getElementById('size_code');
                                    if (sizeSelect.value) {
                                        const selectedOption = sizeSelect.options[sizeSelect.selectedIndex];
                                        document.getElementById('size_desc').value = selectedOption.textContent;
                                        const sizeDisplay = document.getElementById('selected_size_display');
                                        sizeDisplay.innerHTML = 'Selected: ' + selectedOption.textContent;
                                        sizeDisplay.style.color = '#28a745';
                                    }
                                }, 100);
                            }
                        }, 100);
                    }
                }, 100);
            }
        }, 100);
    }
});

// Show loading overlay during form submission
document.getElementById('edit_item_form').addEventListener('submit', function(e) {
    // Validate required fields
    const requiredFields = ['category_code', 'class_code', 'subclass_code'];
    for (const fieldId of requiredFields) {
        const field = document.getElementById(fieldId);
        if (!field.value) {
            e.preventDefault();
            alert(`Please select ${field.previousElementSibling.textContent.replace('*', '').trim()}`);
            field.focus();
            return;
        }
    }
    
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
            <p class="mt-2">Updating item, please wait...</p>
        </div>
    `;
    document.body.appendChild(loadingOverlay);
});
</script>
</body>
</html>