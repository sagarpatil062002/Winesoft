<?php
session_start();

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if(!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR_ID'])) {
    header("Location: index.php");
    exit;
}


include_once "../config/db.php";

// Get parameters
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
$item_code = isset($_GET['code']) ? trim($_GET['code']) : '';

// Initialize variables
$item_details = '';
$current_barcode = '';
$success = $error = '';

// Fetch item details
if ($item_code !== '') {
    $stmt = $conn->prepare("SELECT DETAILS, BARCODE FROM tblitemmaster WHERE CODE = ?");
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $stmt->bind_result($item_details, $current_barcode);
    $stmt->fetch();
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_barcode = trim($_POST['barcode']);
    $item_code = trim($_POST['item_code']);

    if ($item_code === '') {
        $error = "Item code is required.";
    } else {
        $stmt = $conn->prepare("UPDATE tblitemmaster SET BARCODE = ? WHERE CODE = ?");
        $stmt->bind_param("ss", $new_barcode, $item_code);

        if ($stmt->execute()) {
            $success = "Barcode updated successfully!";
            $current_barcode = $new_barcode;
        } else {
            $error = "Error updating barcode: " . $stmt->error;
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
    <title>Edit Barcode - liqoursoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
    <script src="components/shortcuts.js?v=<?= time() ?>"></script>

    
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    <div class="main-content">

        <div class="content-area">
            <h3 class="mb-4">Edit Barcode</h3>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div>
            <?php endif; ?>

            <?php if ($item_code === ''): ?>
                <div class="alert alert-danger">No item code specified.</div>
            <?php else: ?>
                <form method="POST" class="row g-3">
                    <input type="hidden" name="item_code" value="<?= htmlspecialchars($item_code) ?>">
                    <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">

                    <div class="col-md-6">
                        <label class="form-label">Item Code</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($item_code) ?>" readonly>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">Item Name</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($item_details) ?>" readonly>
                    </div>

                    <div class="col-md-12">
                        <label for="barcode" class="form-label">Barcode</label>
                        <input type="text" id="barcode" name="barcode" class="form-control" 
                               value="<?= htmlspecialchars($current_barcode) ?>" maxlength="15">
                        <small class="text-muted">Maximum 15 characters</small>
                    </div>

                    <div class="col-12 mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="barcode_master.php?mode=<?= $mode ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Barcode Master
                        </a>
                    </div>
                </form>

            <?php endif; ?>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Real scanner detection (for actual hardware)
    let barcodeBuffer = '';
    let lastKeyTime = 0;
    
    $(document).keypress(function(e) {
        const now = new Date().getTime();
        const timeBetweenKeys = now - lastKeyTime;
        
        // Reset buffer if time between keys is too long
        if (timeBetweenKeys > 100) {
            barcodeBuffer = '';
        }
        
        // Add character to buffer
        barcodeBuffer += String.fromCharCode(e.which);
        lastKeyTime = now;
        
        // If Enter is pressed, process barcode
        if (e.which === 13 && barcodeBuffer.length > 3) {
            $('#barcode').val(barcodeBuffer.trim());
            barcodeBuffer = '';
        }
    });
});
</script>
</body>
</html>