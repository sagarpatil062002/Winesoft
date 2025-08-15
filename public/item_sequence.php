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

// Mode selection (default Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';
$sequence_type = isset($_GET['type']) ? $_GET['type'] : 'user';

// Sample data - in a real application, you would fetch this from your database
$items = [
    ['description' => 'NIRWANA RUM', 'category' => 'QUART', 'serial_no' => '', 'new_seq' => ''],
    ['description' => 'NIRWANA RUM', 'category' => 'NIP', 'serial_no' => '', 'new_seq' => ''],
    ['description' => 'NIRWANA RUM', 'category' => 'I LTR', 'serial_no' => '', 'new_seq' => ''],
    ['description' => 'NIRWANA RUM', 'category' => '90 ML', 'serial_no' => '', 'new_seq' => ''],
    ['description' => 'INDICA', 'category' => 'NIP', 'serial_no' => '', 'new_seq' => ''],
    ['description' => 'INDICA', 'category' => 'I LTR', 'serial_no' => '', 'new_seq' => ''],
    ['description' => 'INDICA', 'category' => '30 ML', 'serial_no' => '', 'new_seq' => ''],
    // Add more items as needed
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Item Sequence - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/action-buttons.css">
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>

    <div class="main-content">
        <?php include 'components/header.php'; ?>

        <div class="content-area">
            <h3 class="mb-4">Item Sequence Module</h3>
<div class="action-controls d-flex align-items-center mb-3 gap-3 flex-wrap">
    <!-- Liquor Mode Selector -->
    <div class="d-flex align-items-center me-3">
        <label for="mode" class="form-label mb-0 me-2">Liquor Mode:</label>
        <select id="mode" name="mode" class="form-select mode-select" style="width: 200px;"
                onchange="window.location.href='?mode='+this.value+'&type=<?= $sequence_type ?>'">
            <option value="F" <?= $mode === 'F' ? 'selected' : '' ?>>Foreign Liquor</option>
            <option value="C" <?= $mode === 'C' ? 'selected' : '' ?>>Country Liquor</option>
        </select>
    </div>

    <!-- Sequence Type Selector -->
    <div class="d-flex align-items-center me-3">
        <label for="type" class="form-label mb-0 me-2">Sequence Type:</label>
        <select id="type" name="type" class="form-select mode-select" style="width: 200px;"
                onchange="window.location.href='?mode=<?= $mode ?>&type='+this.value">
            <option value="user" <?= $sequence_type === 'user' ? 'selected' : '' ?>>User Defined</option>
            <option value="system" <?= $sequence_type === 'system' ? 'selected' : '' ?>>System Defined</option>
            <option value="group" <?= $sequence_type === 'group' ? 'selected' : '' ?>>Group Defined</option>
        </select>
    </div>

    <!-- Save Button -->
    <button type="button" class="btn action-btn edit ms-auto" id="saveSequence">
        <i class="fas fa-save"></i> Save Sequence
    </button>
</div>

            <!-- Items Table -->
            <div class="table-container">
                <table class="styled-table table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Item Description</th>
                            <th>Category</th>
                            <th>Serial No.</th>
                            <th>New Seq.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($items)): ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= htmlspecialchars($item['description']) ?></td>
                                    <td><?= htmlspecialchars($item['category']) ?></td>
                                    <td>
                                        <input type="text" class="form-control serial-no" 
                                               value="<?= htmlspecialchars($item['serial_no']) ?>" 
                                               style="max-width: 100px;">
                                    </td>
                                    <td>
                                        <input type="text" class="form-control new-seq" 
                                               value="<?= htmlspecialchars($item['new_seq']) ?>" 
                                               style="max-width: 100px;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">No items found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('saveSequence').addEventListener('click', function() {
    // Collect all sequence data
    const sequenceData = [];
    const rows = document.querySelectorAll('.styled-table tbody tr');
    
    rows.forEach(row => {
        const description = row.cells[0].textContent.trim();
        const category = row.cells[1].textContent.trim();
        const serialNo = row.querySelector('.serial-no').value;
        const newSeq = row.querySelector('.new-seq').value;
        
        sequenceData.push({
            description,
            category,
            serialNo,
            newSeq
        });
    });
    
    // Here you would typically send this data to the server via AJAX
    console.log('Sequence data to save:', sequenceData);
    
    alert('Sequence data saved successfully!');
    // In a real application, you would use fetch() or AJAX to send the data to your server
});
</script>
</body>
</html>