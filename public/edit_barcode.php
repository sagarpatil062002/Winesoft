<?php
session_start();

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
if (!isset($_SESSION['CompID']) || !isset($_SESSION['FIN_YEAR'])) {
    header("Location: select_company.php");
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
    <title>Edit Barcode - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
    
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    <div class="main-content">
        <?php include 'components/header.php'; ?>

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

                <!-- Enhanced Barcode Scanner Simulation -->
                <div class="scanner-container mt-4">
                    <h5><i class="fas fa-barcode"></i> Barcode Scanner Simulation</h5>
                    
                    <div class="scanner-window" id="scanner-window">
                        <div class="text-muted">Click to focus scanner</div>
                    </div>
                    
                    <div class="scanner-light"></div>
                    
                    <div class="row">
                        <div class="col-md-8">
                            <div class="input-group mb-3">
                                <input type="text" id="scanner-input" class="form-control" 
                                       placeholder="Type barcode or click simulate scan">
                                <button class="btn btn-primary" type="button" id="simulate-scan">
                                    <i class="fas fa-camera"></i> Simulate Scan
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-success w-100" id="generate-barcode">
                                <i class="fas fa-random"></i> Generate Barcode
                            </button>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i> Tip: Press Enter after typing to simulate scan
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Audio elements
    const beepSound = new Audio('https://assets.mixkit.co/sfx/preview/mixkit-arcade-game-jump-coin-216.mp3');
    const errorSound = new Audio('https://assets.mixkit.co/sfx/preview/mixkit-arcade-retro-game-over-213.mp3');
    
    // Scanner simulation
    $('#simulate-scan').click(function() {
        const $scanner = $('.scanner-container');
        const $input = $('#scanner-input');
        const $barcodeField = $('#barcode');
        const $scannerWindow = $('#scanner-window');
        
        if ($input.val().trim() === '') {
            showError("Please enter a barcode to simulate");
            return;
        }
        
        // Start scanning animation
        $scanner.addClass('scanning');
        $scannerWindow.html('<div class="scan-animation"></div>');
        
        // Play scan sound
        beepSound.play().catch(e => console.log('Audio error:', e));
        
        // Simulate scan delay
        setTimeout(function() {
            // Validate barcode
            if (!isValidBarcode($input.val())) {
                errorSound.play();
                showError("Invalid barcode format");
                $scanner.removeClass('scanning');
                $scannerWindow.html('<div class="text-danger"><i class="fas fa-times-circle"></i> Invalid barcode</div>');
                return;
            }
            
            // Update barcode field
            $barcodeField.val($input.val().trim()).trigger('change');
            
            // Show success
            $scannerWindow.html('<div class="text-success"><i class="fas fa-check-circle"></i> Scan successful!</div>');
            $scanner.removeClass('scanning');
            
            // Clear input after short delay
            setTimeout(() => {
                $input.val('');
                $scannerWindow.html('<div class="text-muted">Click to focus scanner</div>');
            }, 1000);
            
        }, 800);
    });
    
    // Generate random EAN-13 barcode
    $('#generate-barcode').click(function() {
        // Generate first 12 digits
        let barcode = '2' + Math.floor(10000000000 + Math.random() * 90000000000).toString();
        
        // Calculate check digit
        let sum = 0;
        for (let i = 0; i < 12; i++) {
            sum += parseInt(barcode[i]) * (i % 2 === 0 ? 1 : 3);
        }
        const checkDigit = (10 - (sum % 10)) % 10;
        barcode += checkDigit;
        
        // Fill fields
        $('#scanner-input').val(barcode);
        $('#barcode').val(barcode);
    });
    
    // Keyboard support
    $('#scanner-input').keypress(function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#simulate-scan').click();
        }
    });
    
    // Focus scanner when clicking window
    $('#scanner-window').click(function() {
        $('#scanner-input').focus();
    });
    
    // Helper functions
    function isValidBarcode(barcode) {
        // Basic validation - adjust as needed
        return /^[0-9]{8,15}$/.test(barcode);
    }
    
    function showError(message) {
        const $alert = $(`<div class="alert alert-danger alert-dismissible fade show mt-2">
            <i class="fas fa-exclamation-circle"></i> ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>`);
        $('.scanner-container').append($alert);
        setTimeout(() => $alert.alert('close'), 3000);
    }
    
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