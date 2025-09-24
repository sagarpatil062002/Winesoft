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

// Mode selection (default to Foreign Liquor = 'F')
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Fetch data
$stmt = $conn->prepare("SELECT `ITEM_GROUP`, `CLASS`, `DESC`, `CC` 
                        FROM tblsubclass 
                        WHERE LIQ_FLAG = ? 
                        ORDER BY SRNO ASC");
$stmt->bind_param("s", $mode);
$stmt->execute();
$result = $stmt->get_result();
$categories = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Brand Category - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Add version parameter to force cache refresh -->
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>">
</head>
<body>

<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>

    <div class="main-content">

        <div class="content-area">
            <h3 class="mb-4">Brand Category</h3>

            <!-- Mode Selector -->
            <form method="GET" class="mode-selector">
                <label for="mode">Mode:</label>
                <select name="mode" id="mode" onchange="this.form.submit()">
                    <option value="F" <?php echo $mode === 'F' ? 'selected' : ''; ?>>Foreign Liquor</option>
                    <option value="C" <?php echo $mode === 'C' ? 'selected' : ''; ?>>Country Liquor</option>
                </select>
            </form>

            <!-- Category Table -->
            <div class="table-container">
                <table class="styled-table">
                    <thead>
                        <tr>
                            <th>Group</th>
                            <th>Class</th>
                            <th>Description</th>
                            <th>Size (ml)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($categories)): ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cat['ITEM_GROUP']); ?></td>
                                    <td><?php echo htmlspecialchars($cat['CLASS']); ?></td>
                                    <td><?php echo htmlspecialchars($cat['DESC']); ?></td>
                                    <td><?php echo htmlspecialchars($cat['CC']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">No data found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

</body>
</html>
