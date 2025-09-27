<?php
session_start();

if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";

$company_id = $_SESSION['CompID'];
$message = '';

// Handle form submission
if ($_POST) {
    if (isset($_POST['add_shortcut'])) {
        $shortcut_key = trim($_POST['shortcut_key']);
        $action_name = trim($_POST['action_name']);
        $action_url = trim($_POST['action_url']);
        
        try {
            $stmt = $conn->prepare("INSERT INTO tbl_shortcuts (company_id, shortcut_key, action_name, action_url) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $company_id, $shortcut_key, $action_name, $action_url);
            $stmt->execute();
            $message = "Shortcut added successfully!";
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error adding shortcut: " . $e->getMessage();
        }
    }
}

// Get current shortcuts
$shortcuts = [];
$stmt = $conn->prepare("SELECT * FROM tbl_shortcuts WHERE company_id = ? ORDER BY shortcut_key");
$stmt->bind_param("i", $company_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $shortcuts[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shortcut Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="container mt-4">
    <h2>Keyboard Shortcuts Manager</h2>
    
    <?php if ($message): ?>
        <div class="alert alert-info"><?php echo $message; ?></div>
    <?php endif; ?>
    
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Add New Shortcut</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Shortcut Key</label>
                            <input type="text" class="form-control" name="shortcut_key" 
                                   placeholder="e.g., ctrl+q, alt+s" required>
                            <small class="form-text text-muted">Use format: ctrl+key, alt+key, etc.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Action Name</label>
                            <input type="text" class="form-control" name="action_name" 
                                   placeholder="e.g., Sales Report" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Action URL</label>
                            <input type="text" class="form-control" name="action_url" 
                                   placeholder="e.g., sales_report.php" required>
                        </div>
                        <button type="submit" name="add_shortcut" class="btn btn-primary">Add Shortcut</button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h5>Current Shortcuts</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($shortcuts)): ?>
                        <p>No shortcuts defined yet.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Shortcut</th>
                                        <th>Action</th>
                                        <th>URL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($shortcuts as $shortcut): ?>
                                        <tr>
                                            <td><kbd><?php echo htmlspecialchars($shortcut['shortcut_key']); ?></kbd></td>
                                            <td><?php echo htmlspecialchars($shortcut['action_name']); ?></td>
                                            <td><?php echo htmlspecialchars($shortcut['action_url']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>