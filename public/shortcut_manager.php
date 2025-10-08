<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";

$message = '';

// Handle form submission
if ($_POST) {
    if (isset($_POST['add_shortcut'])) {
        $shortcut_key = trim($_POST['shortcut_key']);
        $action_name = trim($_POST['action_name']);
        $action_url = trim($_POST['action_url']);
        
        try {
            $stmt = $conn->prepare("INSERT INTO tbl_shortcuts (shortcut_key, action_name, action_url) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $shortcut_key, $action_name, $action_url);
            $stmt->execute();
            $message = "Shortcut added successfully!";
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error adding shortcut: " . $e->getMessage();
        }
    }
    
    // Handle update shortcut
    if (isset($_POST['update_shortcut'])) {
        $shortcut_id = $_POST['shortcut_id'];
        $shortcut_key = trim($_POST['shortcut_key']);
        $action_name = trim($_POST['action_name']);
        $action_url = trim($_POST['action_url']);
        
        try {
            $stmt = $conn->prepare("UPDATE tbl_shortcuts SET shortcut_key = ?, action_name = ?, action_url = ? WHERE id = ?");
            $stmt->bind_param("sssi", $shortcut_key, $action_name, $action_url, $shortcut_id);
            $stmt->execute();
            $message = "Shortcut updated successfully!";
            $stmt->close();
        } catch (Exception $e) {
            $message = "Error updating shortcut: " . $e->getMessage();
        }
    }
}

// Handle delete shortcut
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    
    try {
        $stmt = $conn->prepare("DELETE FROM tbl_shortcuts WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $message = "Shortcut deleted successfully!";
        $stmt->close();
    } catch (Exception $e) {
        $message = "Error deleting shortcut: " . $e->getMessage();
    }
}

// Get current shortcuts (common for all companies)
$shortcuts = [];
$stmt = $conn->prepare("SELECT * FROM tbl_shortcuts ORDER BY shortcut_key");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $shortcuts[] = $row;
}
$stmt->close();

// Get shortcut for editing if edit_id is set
$edit_shortcut = null;
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $stmt = $conn->prepare("SELECT * FROM tbl_shortcuts WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_shortcut = $result->fetch_assoc();
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shortcut Manager - WineSoft</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="css/navbar.css?v=<?= time() ?>">
    <style>
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            background-color: #f5f7fa;
        }
        
        .main-content {
            flex: 1;
            padding: 20px;
            margin-left: 250px;
            transition: margin-left 0.3s ease;
        }
        
        .content-area {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            padding: 25px;
            margin-bottom: 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eaeaea;
        }
        
        .page-title {
            color: #2c3e50;
            font-size: 1.8rem;
            font-weight: 600;
            margin: 0;
        }
        
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: #3498db;
            color: white;
            border-radius: 8px 8px 0 0 !important;
            padding: 15px 20px;
            border: none;
        }
        
        .card-header h5 {
            margin: 0;
            font-weight: 600;
        }
        
        .form-label {
            font-weight: 500;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .form-control, .form-select {
            border-radius: 6px;
            padding: 10px 12px;
            border: 1px solid #ced4da;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 0.2rem rgba(52, 152, 219, 0.25);
        }
        
        .btn-primary {
            background-color: #3498db;
            border-color: #3498db;
            border-radius: 6px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
            border-color: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-success {
            background-color: #2ecc71;
            border-color: #2ecc71;
            border-radius: 6px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-success:hover {
            background-color: #27ae60;
            border-color: #27ae60;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-warning {
            background-color: #f39c12;
            border-color: #f39c12;
            border-radius: 6px;
            padding: 8px 15px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-warning:hover {
            background-color: #e67e22;
            border-color: #e67e22;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-danger {
            background-color: #e74c3c;
            border-color: #e74c3c;
            border-radius: 6px;
            padding: 8px 15px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-danger:hover {
            background-color: #c0392b;
            border-color: #c0392b;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary {
            background-color: #95a5a6;
            border-color: #95a5a6;
            border-radius: 6px;
            padding: 10px 20px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background-color: #7f8c8d;
            border-color: #7f8c8d;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .shortcut-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .shortcut-btn {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 8px 15px;
            font-weight: 500;
            transition: all 0.2s ease;
            cursor: pointer;
        }
        
        .shortcut-btn:hover {
            background-color: #e9ecef;
            transform: translateY(-2px);
        }
        
        .shortcut-btn.active {
            background-color: #3498db;
            color: white;
            border-color: #3498db;
        }
        
        .table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
        }
        
        kbd {
            background-color: #2c3e50;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 0.9rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #bdc3c7;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .page-title {
                margin-bottom: 15px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>
    <div class="main-content">
        <div class="content-area">
            <div class="page-header">
                <h1 class="page-title">Keyboard Shortcuts Manager</h1>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    Shortcuts are common for all companies
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-info d-flex align-items-center" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <div><?php echo $message; ?></div>
                </div>
            <?php endif; ?>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5><?php echo $edit_shortcut ? 'Edit Shortcut' : 'Add New Shortcut'; ?></h5>
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <?php if ($edit_shortcut): ?>
                                    <input type="hidden" name="shortcut_id" value="<?php echo $edit_shortcut['id']; ?>">
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label class="form-label">Shortcut Key</label>
                                    <div class="shortcut-buttons">
                                        <button type="button" class="shortcut-btn" data-prefix="ctrl+">Ctrl</button>
                                        <button type="button" class="shortcut-btn" data-prefix="alt+">Alt</button>
                                        <button type="button" class="shortcut-btn" data-prefix="shift+">Shift</button>
                                    </div>
                                    <input type="text" class="form-control" name="shortcut_key" 
                                           id="shortcut_key" placeholder="e.g., ctrl+q, alt+s" 
                                           value="<?php echo $edit_shortcut ? htmlspecialchars($edit_shortcut['shortcut_key']) : ''; ?>" required>
                                    <small class="form-text text-muted">Click buttons above or type manually. Use format: ctrl+key, alt+key, etc.</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Action Name</label>
                                    <input type="text" class="form-control" name="action_name" 
                                           placeholder="e.g., Sales Report" 
                                           value="<?php echo $edit_shortcut ? htmlspecialchars($edit_shortcut['action_name']) : ''; ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Action URL</label>
                                    <input type="text" class="form-control" name="action_url" 
                                           placeholder="e.g., sales_report.php" 
                                           value="<?php echo $edit_shortcut ? htmlspecialchars($edit_shortcut['action_url']) : ''; ?>" required>
                                </div>
                                <div class="d-flex gap-2">
                                    <?php if ($edit_shortcut): ?>
                                        <button type="submit" name="update_shortcut" class="btn btn-success">
                                            <i class="fas fa-save me-2"></i> Update Shortcut
                                        </button>
                                        <a href="shortcut_manager.php" class="btn btn-secondary">
                                            <i class="fas fa-times me-2"></i> Cancel
                                        </a>
                                    <?php else: ?>
                                        <button type="submit" name="add_shortcut" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i> Add Shortcut
                                        </button>
                                    <?php endif; ?>
                                </div>
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
                                <div class="empty-state">
                                    <i class="fas fa-keyboard"></i>
                                    <h5>No shortcuts defined yet</h5>
                                    <p>Add your first shortcut using the form on the left</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Shortcut</th>
                                                <th>Action</th>
                                                <th>URL</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($shortcuts as $shortcut): ?>
                                                <tr>
                                                    <td><kbd><?php echo htmlspecialchars($shortcut['shortcut_key']); ?></kbd></td>
                                                    <td><?php echo htmlspecialchars($shortcut['action_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($shortcut['action_url']); ?></td>
                                                    <td>
                                                        <div class="action-buttons">
                                                            <a href="shortcut_manager.php?edit_id=<?php echo $shortcut['id']; ?>" class="btn btn-warning btn-sm">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <a href="shortcut_manager.php?delete_id=<?php echo $shortcut['id']; ?>" 
                                                               class="btn btn-danger btn-sm" 
                                                               onclick="return confirm('Are you sure you want to delete this shortcut?')">
                                                                <i class="fas fa-trash"></i>
                                                            </a>
                                                        </div>
                                                    </td>
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
        
        <?php include 'components/footer.php'; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Shortcut button functionality
document.addEventListener('DOMContentLoaded', function() {
    const shortcutButtons = document.querySelectorAll('.shortcut-btn');
    const shortcutInput = document.getElementById('shortcut_key');
    
    shortcutButtons.forEach(button => {
        button.addEventListener('click', function() {
            const prefix = this.getAttribute('data-prefix');
            const currentValue = shortcutInput.value;
            
            // Remove any existing prefixes
            let cleanValue = currentValue.replace(/(ctrl\+|alt\+|shift\+)/g, '');
            
            // Add the new prefix
            shortcutInput.value = prefix + cleanValue;
            
            // Update button states
            shortcutButtons.forEach(btn => {
                btn.classList.remove('active');
            });
            this.classList.add('active');
        });
    });
    
    // Update button states based on input value
    shortcutInput.addEventListener('input', function() {
        const value = this.value;
        
        shortcutButtons.forEach(button => {
            const prefix = button.getAttribute('data-prefix');
            if (value.startsWith(prefix)) {
                button.classList.add('active');
            } else {
                button.classList.remove('active');
            }
        });
    });
    
    // Initialize button states if editing
    const initialValue = shortcutInput.value;
    if (initialValue) {
        shortcutButtons.forEach(button => {
            const prefix = button.getAttribute('data-prefix');
            if (initialValue.startsWith(prefix)) {
                button.classList.add('active');
            }
        });
    }
});
</script>
</body>
</html>
