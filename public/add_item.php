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

// Get mode from URL (default 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Fetch classes and subclasses from database
$classes = [];
$subclasses = [];

// Get all classes - using correct column names from tblclass
$class_result = $conn->query("SELECT DISTINCT `DESC` AS class_name FROM tblclass WHERE LIQ_FLAG = '$mode' ORDER BY `DESC`");
if ($class_result) {
    $classes = $class_result->fetch_all(MYSQLI_ASSOC);
    $class_result->free();
}

// Get all subclasses - using correct column names from tblsubclass
$subclass_result = $conn->query("SELECT DISTINCT `DESC` AS subclass_name FROM tblsubclass WHERE LIQ_FLAG = '$mode' ORDER BY `DESC`");
if ($subclass_result) {
    $subclasses = $subclass_result->fetch_all(MYSQLI_ASSOC);
    $subclass_result->free();
}

// Initialize variables
$code = $new_code = $details = $details2 = $class = $sub_class = '';
$pprice = $bprice = 0;
$success = $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect POST data safely
    $code = trim($_POST['code']);
    $new_code = trim($_POST['new_code']);
    $details = trim($_POST['details']);
    $details2 = trim($_POST['details2']);
    $class = trim($_POST['class']);
    $sub_class = trim($_POST['sub_class']);
    $pprice = floatval($_POST['pprice']);
    $bprice = floatval($_POST['bprice']);
    $liq_flag = $_POST['mode'];

    // Basic validation
    if ($code === '' || $details === '') {
        $error = "Item Code and Item Name are required.";
    } else {
        // Insert into tblitemmaster
        $stmt = $conn->prepare("INSERT INTO tblitemmaster 
            (CODE, NEW_CODE, DETAILS, DETAILS2, CLASS, SUB_CLASS, PPRICE, BPRICE, LIQ_FLAG) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssdds", $code, $new_code, $details, $details2, $class, $sub_class, $pprice, $bprice, $liq_flag);

        if ($stmt->execute()) {
            $success = "Item added successfully!";
            // Reset form
            $code = $new_code = $details = $details2 = $class = $sub_class = '';
            $pprice = $bprice = 0;
        } else {
            $error = "Error: " . $stmt->error;
        }
        $stmt->close();
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
<!-- Add version parameter to force cache refresh -->
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>"></head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    <div class="main-content">
        <?php include 'components/header.php'; ?>

        <div class="content-area">
            <h3 class="mb-4">Add New Item</h3>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" class="row g-3">
                <!-- Mode -->
                <div class="col-md-3">
                    <label for="mode" class="form-label">Mode</label>
                    <select id="mode" name="mode" class="form-select" readonly>
                        <option value="F" <?= $mode === 'F' ? 'selected' : '' ?>>Foreign Liquor</option>
                        <option value="C" <?= $mode === 'C' ? 'selected' : '' ?>>Country Liquor</option>
                        <option value="O" <?= $mode === 'O' ? 'selected' : '' ?>>Others</option>
                    </select>
                </div>

                <!-- Code -->
                <div class="col-md-3">
                    <label for="code" class="form-label">Item Code *</label>
                    <input type="text" id="code" name="code" class="form-control" value="<?= htmlspecialchars($code) ?>" required>
                </div>

                <!-- New Code -->
                <div class="col-md-3">
                    <label for="new_code" class="form-label">New Code</label>
                    <input type="text" id="new_code" name="new_code" class="form-control" value="<?= htmlspecialchars($new_code) ?>">
                </div>

                <!-- Item Name -->
                <div class="col-md-3">
                    <label for="details" class="form-label">Item Name *</label>
                    <input type="text" id="details" name="details" class="form-control" value="<?= htmlspecialchars($details) ?>" required>
                </div>

                <!-- Description -->
                <div class="col-md-6">
                    <label for="details2" class="form-label">Description</label>
                    <input type="text" id="details2" name="details2" class="form-control" value="<?= htmlspecialchars($details2) ?>">
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
                    <select id="sub_class" name="sub_class" class="form-select">
                        <option value="">-- Select Sub Class --</option>
                        <?php foreach ($subclasses as $subclass_item): ?>
                            <option value="<?= htmlspecialchars($subclass_item['subclass_name']) ?>" 
                                <?= ($sub_class === $subclass_item['subclass_name']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($subclass_item['subclass_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- P.Price -->
                <div class="col-md-3">
                    <label for="pprice" class="form-label">P. Price</label>
                    <input type="number" step="0.001" id="pprice" name="pprice" class="form-control" value="<?= htmlspecialchars($pprice) ?>">
                </div>

                <!-- B.Price -->
                <div class="col-md-3">
                    <label for="bprice" class="form-label">B. Price</label>
                    <input type="number" step="0.001" id="bprice" name="bprice" class="form-control" value="<?= htmlspecialchars($bprice) ?>">
                </div>

                <!-- Buttons -->
                <div class="col-12 mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-plus"></i> Add Item
                    </button>
                    <a href="item_master.php?mode=<?= $mode ?>" class="btn btn-secondary">
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
</body>
</html>