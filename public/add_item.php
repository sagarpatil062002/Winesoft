<?php
session_start();

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR'])) {
    header("Location: select_company.php");
    exit;
}

include_once "../config/db.php"; // MySQLi connection in $conn

// Resolve current page path for reload links
$currentPage = basename($_SERVER['PHP_SELF']);

// Get mode (prefer POST for submission, else GET, default 'F')
$mode = isset($_POST['mode']) ? $_POST['mode'] : (isset($_GET['mode']) ? $_GET['mode'] : 'F');
// Sanitize to allowed values
$allowedModes = ['F','C','O'];
if (!in_array($mode, $allowedModes, true)) {
    $mode = 'F';
}

// Fetch classes and subclasses from database (by mode)
$classes = [];
$subclasses = [];

// Classes
if ($stmt = $conn->prepare("SELECT DISTINCT `DESC` AS class_name FROM tblclass WHERE LIQ_FLAG = ? ORDER BY `DESC`")) {
    $stmt->bind_param("s", $mode);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) $classes = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Subclasses - Include ITEM_GROUP in the query
if ($stmt = $conn->prepare("SELECT DISTINCT `DESC` AS subclass_name, ITEM_GROUP FROM tblsubclass WHERE LIQ_FLAG = ? ORDER BY `DESC`")) {
    $stmt->bind_param("s", $mode);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) $subclasses = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Initialize variables
$code = $new_code = $details = $details2 = $class = $sub_class = $BARCODE = '';
$pprice = $bprice = $mprice = $vprice = $GOB = $OB = $OB2 = 0;
$success = $error = '';

// Handle submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect POST data safely
    $code = trim($_POST['code'] ?? '');
    $new_code = trim($_POST['new_code'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $class = trim($_POST['class'] ?? '');
    $sub_class = trim($_POST['sub_class'] ?? '');
    $details2 = trim($_POST['details2'] ?? '');
    $BARCODE = trim($_POST['BARCODE'] ?? '');
    $pprice = floatval($_POST['pprice'] ?? 0);
    $bprice = floatval($_POST['bprice'] ?? 0);
    $mprice = floatval($_POST['mprice'] ?? 0);
    $vprice = floatval($_POST['vprice'] ?? 0);
    $GOB = floatval($_POST['GOB'] ?? 0);
    $OB = floatval($_POST['OB'] ?? 0);
    $OB2 = floatval($_POST['OB2'] ?? 0);
    $liq_flag = $mode; // use current mode
    
    // Get ITEM_GROUP from selected subclass
    $item_group = '';
    if (!empty($sub_class)) {
        $stmt = $conn->prepare("SELECT ITEM_GROUP FROM tblsubclass WHERE `DESC` = ? AND LIQ_FLAG = ? LIMIT 1");
        $stmt->bind_param("ss", $sub_class, $mode);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $item_group = $row['ITEM_GROUP'];
        }
        $stmt->close();
    }

    // Basic validation
    if ($code === '' || $details === '') {
        $error = "Item Code and Item Name are required.";
    } else {
        // Insert into tblitemmaster - Fixed: Added ITEM_GROUP column
        $sql = "INSERT INTO tblitemmaster 
            (CODE, NEW_CODE, DETAILS, DETAILS2, CLASS, SUB_CLASS, ITEM_GROUP, PPRICE, BPRICE, MPRICE, VPRICE, 
             GOB, OB, OB2, BARCODE, LIQ_FLAG) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $error = "Prepare failed: " . $conn->error;
        } else {
            // Correct bind types: 9 strings, 5 doubles, 1 string
            $stmt->bind_param(
                "sssssssdddddddss",
                $code, $new_code, $details, $details2, $class, $sub_class, $item_group,
                $pprice, $bprice, $mprice, $vprice,
                $GOB, $OB, $OB2,
                $BARCODE, $liq_flag
            );

            if ($stmt->execute()) {
                $success = "Item added successfully!";
                // Reset form
                $code = $new_code = $details = $details2 = $class = $sub_class = $BARCODE = '';
                $pprice = $bprice = $mprice = $vprice = $GOB = $OB = $OB2 = 0;
            } else {
                $error = "Error: " . $stmt->error;
            }
            $stmt->close();
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
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    <div class="main-content">
        <?php include 'components/header.php'; ?>

        <div class="content-area">
            <h3 class="mb-4">Add New Item</h3>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" class="row g-3">
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
                    <label for="new_code" class="form-label">New Code</label>
                    <input type="text" id="new_code" name="new_code" class="form-control"
                           value="<?= htmlspecialchars($new_code) ?>">
                </div>

                <!-- Item Name -->
                <div class="col-md-3">
                    <label for="details" class="form-label">Item Name *</label>
                    <input type="text" id="details" name="details" class="form-control"
                           value="<?= htmlspecialchars($details) ?>" required>
                </div>

                <!-- Class Dropdown -->
                <div class="col-md-3">
                    <label for="class" class="form-label">Class</label>
                    <select id="class" name="class" class="form-select">
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $class_item): ?>
                            <option value="<?= htmlspecialchars($class_item['class_name']) ?>"
                                <?= ($class === $class_item['class_name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($class_item['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Sub Class Dropdown -->
                <div class="col-md-3">
                    <label for="sub_class" class="form-label">Sub Class</label>
                    <select id="sub_class" name="sub_class" class="form-select" onchange="updateDetails2()">
                        <option value="">-- Select Sub Class --</option>
                        <?php foreach ($subclasses as $subclass_item): ?>
                            <option value="<?= htmlspecialchars($subclass_item['subclass_name']) ?>"
                                data-item-group="<?= htmlspecialchars($subclass_item['ITEM_GROUP']) ?>"
                                <?= ($sub_class === $subclass_item['subclass_name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($subclass_item['subclass_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Additional Details -->
                <div class="col-md-3">
                    <label for="details2" class="form-label">Additional Details</label>
                    <input type="text" id="details2" name="details2" class="form-control"
                           value="<?= htmlspecialchars($details2) ?>">
                </div>

                <!-- Barcode -->
                <div class="col-md-3">
                    <label for="BARCODE" class="form-label">Barcode</label>
                    <input type="text" id="BARCODE" name="BARCODE" class="form-control"
                           value="<?= htmlspecialchars($BARCODE) ?>">
                </div>

                <!-- Opening Stock (G) -->
                <div class="col-md-3">
                    <label for="GOB" class="form-label">Op. Stk. (G)</label>
                    <input type="number" step="0.001" id="GOB" name="GOB" class="form-control"
                           value="<?= htmlspecialchars($GOB) ?>">
                </div>

                <!-- Opening Stock (C1) -->
                <div class="col-md-3">
                    <label for="OB" class="form-label">Op. Stk. (C1)</label>
                    <input type="number" step="0.001" id="OB" name="OB" class="form-control"
                           value="<?= htmlspecialchars($OB) ?>">
                </div>

                <!-- Opening Stock (C2) -->
                <div class="col-md-3">
                    <label for="OB2" class="form-label">Op. Stk. (C2)</label>
                    <input type="number" step="0.001" id="OB2" name="OB2" class="form-control"
                           value="<?= htmlspecialchars($OB2) ?>">
                </div>

                <!-- P. Price -->
                <div class="col-md-3">
                    <label for="pprice" class="form-label">P. Price</label>
                    <input type="number" step="0.001" id="pprice" name="pprice" class="form-control"
                           value="<?= htmlspecialchars($pprice) ?>">
                </div>

                <!-- B. Price -->
                <div class="col-md-3">
                    <label for="bprice" class="form-label">B. Price</label>
                    <input type="number" step="0.001" id="bprice" name="bprice" class="form-control"
                           value="<?= htmlspecialchars($bprice) ?>">
                </div>

                <!-- M. Price -->
                <div class="col-md-3">
                    <label for="mprice" class="form-label">M. Price</label>
                    <input type="number" step="0.001" id="mprice" name="mprice" class="form-control"
                           value="<?= htmlspecialchars($mprice) ?>">
                </div>

                <!-- V. Price -->
                <div class="col-md-3">
                    <label for="vprice" class="form-label">V. Price</label>
                    <input type="number" step="0.001" id="vprice" name="vprice" class="form-control"
                           value="<?= htmlspecialchars($vprice) ?>">
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
<script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
<script>
    function updateDetails2() {
        const subClassSelect = document.getElementById('sub_class');
        const details2Input = document.getElementById('details2');
        const selectedOption = subClassSelect.options[subClassSelect.selectedIndex];
        
        if (selectedOption && selectedOption.value !== '') {
            details2Input.value = selectedOption.value;
        } else {
            details2Input.value = '';
        }
    }
    
    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        updateDetails2();
    });
</script>
</body>
</html>