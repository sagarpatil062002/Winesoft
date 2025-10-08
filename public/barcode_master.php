<?php
session_start();

// Authentication and validation
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

// Get parameters
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch items - FILTERED BY LICENSE TYPE
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $query = "SELECT CODE, DETAILS, DETAILS2, CLASS, BPRICE, BARCODE FROM tblitemmaster WHERE LIQ_FLAG = ? AND CLASS IN ($class_placeholders)";
    $params = array_merge([$mode], $allowed_classes);
    $types = str_repeat('s', count($params));
} else {
    // If no classes allowed, show empty result
    $query = "SELECT CODE, DETAILS, DETAILS2, CLASS, BPRICE, BARCODE FROM tblitemmaster WHERE 1 = 0";
    $params = [];
    $types = "";
}

if ($search !== '') {
    $query .= " AND (DETAILS LIKE ? OR CODE LIKE ? OR BARCODE LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $types .= "sss";
}

$query .= " ORDER BY DETAILS ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$items = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barcode Master - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?=time()?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
    <!-- Barcode font -->
    <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+128&display=swap" rel="stylesheet">
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    <div class="main-content">
        <?php include 'components/header.php'; ?>

        <div class="content-area">
            <h3 class="mb-4">Barcode Master</h3>

            <!-- Mode Selector -->
            <div class="mode-selector mb-3 no-print">
                <label class="form-label">Liquor Mode:</label>
                <div class="btn-group" role="group">
                    <a href="?mode=F&search=<?= urlencode($search) ?>"
                       class="btn btn-outline-primary <?= $mode === 'F' ? 'mode-active' : '' ?>">
                        Foreign Liquor
                    </a>
                    <a href="?mode=C&search=<?= urlencode($search) ?>"
                       class="btn btn-outline-primary <?= $mode === 'C' ? 'mode-active' : '' ?>">
                        Country Liquor
                    </a>
                    <a href="?mode=O&search=<?= urlencode($search) ?>"
                       class="btn btn-outline-primary <?= $mode === 'O' ? 'mode-active' : '' ?>">
                        Others
                    </a>
                </div>
            </div>

            <!-- Search -->
            <form method="GET" class="search-control mb-3 no-print">
                <input type="hidden" name="mode" value="<?= htmlspecialchars($mode); ?>">
                <div class="input-group">
                    <input type="text" name="search" class="form-control"
                           placeholder="Search by item name, code or barcode..." value="<?= htmlspecialchars($search); ?>">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Find
                    </button>
                    <?php if ($search !== ''): ?>
                        <a href="?mode=<?= $mode ?>" class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Items Table -->
            <div class="table-container">
                <table class="styled-table table-striped">
                    <thead class="table-header">
                        <tr>
                            <th>#</th>
                            <th>Item Name</th>
                            <th>Size</th>
                            <th>S. Rate</th>
                            <th class="barcode-col">Bar Code</th>
                            <th class="action-col no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $index => $item): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($item['DETAILS']); ?></td>
                                <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
                                <td><?= number_format($item['BPRICE'], 2); ?></td>
                                <td><?= htmlspecialchars($item['BARCODE']); ?></td>
                                <td class="no-print">
                                    <a href="edit_barcode.php?code=<?= urlencode($item['CODE']) ?>&mode=<?= $mode ?>"
                                       class="btn btn-sm btn-primary" title="Edit Barcode">
                                        <i class="fas fa-barcode"></i> Edit
                                    </a>
                                    <button class="btn btn-sm btn-success print-barcode" 
                                            data-barcode="<?= htmlspecialchars($item['BARCODE']) ?>"
                                            data-item="<?= htmlspecialchars($item['DETAILS']) ?>"
                                            data-price="<?= number_format($item['BPRICE'], 2) ?>"
                                            title="Print Barcode">
                                        <i class="fas fa-print"></i> Print
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">No items found.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

<!-- Hidden print template -->
<div id="print-template" style="display:none;">
    <div class="print-content">
        <div class="print-container">
            <div class="print-item-name"></div>
            <div class="barcode-print"></div>
            <div class="print-price"></div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    // Print barcode functionality
    $('.print-barcode').click(function() {
        const barcode = $(this).data('barcode');
        const itemName = $(this).data('item');
        const price = $(this).data('price');
        
        if (!barcode) {
            alert('No barcode available for this item');
            return;
        }
        
        // Prepare print content
        const $printTemplate = $('#print-template').clone();
        $printTemplate.find('.print-item-name').text(itemName);
        $printTemplate.find('.barcode-print').text(barcode);
        $printTemplate.find('.print-price').text('₹' + price);
        $printTemplate.css('display', 'block');
        
        // Open print dialog
        const printWindow = window.open('', '_blank');
        printWindow.document.write(`
            <html>
                <head>
                    <title>Print Barcode - ${itemName}</title>
                    <link href="https://fonts.googleapis.com/css2?family=Libre+Barcode+128&display=swap" rel="stylesheet">
                    <style>
                        body { font-family: Arial, sans-serif; margin: 0; padding: 10px; }
                        .barcode-print {
                            font-family: 'Libre Barcode 128', cursive;
                            font-size: 48px;
                            text-align: center;
                            margin: 20px 0;
                        }
                        .print-container {
                            text-align: center;
                            padding: 20px;
                        }
                        .print-item-name {
                            font-weight: bold;
                            margin-bottom: 10px;
                            font-size: 16px;
                        }
                        .print-price {
                            font-size: 18px;
                            margin-top: 5px;
                        }
                        @media print {
                            @page { size: auto; margin: 5mm; }
                            body { -webkit-print-color-adjust: exact; }
                        }
                    </style>
                </head>
                <body>
                    ${$printTemplate.html()}
                    <script>
                        window.onload = function() {
                            setTimeout(function() {
                                window.print();
                                setTimeout(function() {
                                    window.close();
                                }, 500);
                            }, 200);
                        };
                    <\/script>
                </body>
            </html>
        `);
        printWindow.document.close();
    });
});
</script>
</body>
</html>