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

include_once "../config/db.php";

// Get item code and mode from URL
$item_code = isset($_GET['code']) ? $_GET['code'] : null;
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Fetch item details
$item = null;
if ($item_code) {
    $stmt = $conn->prepare("SELECT * FROM tblitemmaster WHERE CODE = ?");
    $stmt->bind_param("s", $item_code);
    $stmt->execute();
    $result = $stmt->get_result();
    $item = $result->fetch_assoc();
    $stmt->close();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_code = $_POST['new_code'];
    $details = $_POST['details'];
    $details2 = $_POST['details2'];
    $class = $_POST['class'];
    $sub_class = $_POST['sub_class'];
    $pprice = $_POST['pprice'];
    $bprice = $_POST['bprice'];

    $stmt = $conn->prepare("UPDATE tblitemmaster SET 
                            NEW_CODE = ?, 
                            DETAILS = ?, 
                            DETAILS2 = ?, 
                            CLASS = ?, 
                            SUB_CLASS = ?, 
                            PPRICE = ?, 
                            BPRICE = ? 
                            WHERE CODE = ?");
    $stmt->bind_param("ssssddds", $new_code, $details, $details2, $class, $sub_class, $pprice, $bprice, $item_code);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Item updated successfully!";
        header("Location: item_master.php?mode=" . $mode);
        exit;
    } else {
        $_SESSION['error_message'] = "Error updating item: " . $conn->error;
    }
    $stmt->close();
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
<!-- Add version parameter to force cache refresh -->
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">    <style>
        .card {
            background: #ffffff;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 1000px;
            text-align: center;
            margin: 0 auto;
        }
        
        /* Responsive adjustments */
        @media (max-width: 992px) {
            .dashboard-container {
                padding-left: 0;
            }
            
            .card {
                padding: 1rem;
            }
        }
        
        @media (max-width: 768px) {
            .row.mb-3 > div {
                margin-bottom: 1rem;
            }
            
            .btn-secondary {
                margin-top: 1rem;
            }
            
            .d-flex {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 576px) {
            .card {
                padding: 0.75rem;
            }
            
            .form-label {
                font-size: 0.9rem;
            }
            
            .btn {
                width: 100%;
                margin-top: 0.5rem;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>

    <div class="main-content">
        <?php include 'components/header.php'; ?>

        <div class="content-area">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3>Edit Item</h3>
                <a href="item_master.php?mode=<?= $mode ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>

            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger"><?= $_SESSION['error_message'] ?></div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <?php if ($item): ?>
            <div class="card">
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="mode" value="<?= $mode ?>">
                        
                        <div class="row mb-3">
                            <div class="col-md-6 col-12">
                                <label for="code" class="form-label">Item Code</label>
                                <input type="text" class="form-control" id="code" value="<?= htmlspecialchars($item['CODE']) ?>" readonly>
                            </div>
                            <div class="col-md-6 col-12">
                                <label for="new_code" class="form-label">New Code</label>
                                <input type="text" class="form-control" id="new_code" name="new_code" value="<?= htmlspecialchars($item['NEW_CODE']) ?>">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6 col-12">
                                <label for="details" class="form-label">Item Name</label>
                                <input type="text" class="form-control" id="details" name="details" value="<?= htmlspecialchars($item['DETAILS']) ?>" required>
                            </div>
                            <div class="col-md-6 col-12">
                                <label for="details2" class="form-label">Description</label>
                                <input type="text" class="form-control" id="details2" name="details2" value="<?= htmlspecialchars($item['DETAILS2']) ?>">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6 col-12">
                                <label for="class" class="form-label">Class</label>
                                <input type="text" class="form-control" id="class" name="class" value="<?= htmlspecialchars($item['CLASS']) ?>">
                            </div>
                            <div class="col-md-6 col-12">
                                <label for="sub_class" class="form-label">Sub Class</label>
                                <input type="text" class="form-control" id="sub_class" name="sub_class" value="<?= htmlspecialchars($item['SUB_CLASS']) ?>">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6 col-12">
                                <label for="pprice" class="form-label">P. Price</label>
                                <input type="number" step="0.001" class="form-control" id="pprice" name="pprice" value="<?= htmlspecialchars($item['PPRICE']) ?>">
                            </div>
                            <div class="col-md-6 col-12">
                                <label for="bprice" class="form-label">B. Price</label>
                                <input type="number" step="0.001" class="form-control" id="bprice" name="bprice" value="<?= htmlspecialchars($item['BPRICE']) ?>">
                            </div>
                        </div>

                        <div class="text-end mt-4">
                            <button type="submit" class="btn action-btn edit">
                                <i class="fas fa-save"></i> Update Item
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php else: ?>
                <div class="alert alert-danger">Item not found!</div>
            <?php endif; ?>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>