<?php
// config/shortcut_config.php
session_start();
require_once 'db.php';

// Get user ID (modify according to your authentication system)
if (!isset($_SESSION['user_id'])) {
    logMessage('User not logged in, redirecting to index.php', 'WARNING');
    header("Location: index.php");
    exit;
}


// List of browser shortcuts (for warning only - not restriction)
$restrictedShortcuts = [
    'ctrl+s', 'ctrl+o', 'ctrl+p', 'ctrl+n', 'ctrl+w', 'ctrl+t',
    'ctrl+tab', 'ctrl+shift+tab', 'ctrl+r', 'ctrl+a', 'ctrl+c',
    'ctrl+v', 'ctrl+x', 'ctrl+z', 'ctrl+y', 'ctrl+f', 'ctrl+g',
    'f5', 'ctrl+f5', 'alt+f4', 'ctrl+shift+delete', 'f3', 'f11', 'f12'
];

// Safe shortcut suggestions for common pages
$suggestedShortcuts = [
    'dashboard.php' => ['ctrl+alt+d', 'alt+1', 'shift+d'],
    'profile.php' => ['ctrl+alt+p', 'alt+2', 'shift+p'],
    'reports.php' => ['ctrl+alt+r', 'alt+3', 'shift+r'],
    'settings.php' => ['ctrl+alt+s', 'alt+4', 'shift+s'],
    'users.php' => ['ctrl+alt+u', 'alt+5', 'shift+u'],
    'products.php' => ['ctrl+alt+m', 'alt+6', 'shift+m'],
    'add_item.php' => ['ctrl+alt+a', 'alt+7', 'shift+a']
];

function validateShortcutFormat($shortcut) {
    $pattern = '/^(ctrl\+|alt\+|shift\+)+[a-z0-9]|f[1-9][0-2]?$/i';
    return preg_match($pattern, $shortcut);
}
?>