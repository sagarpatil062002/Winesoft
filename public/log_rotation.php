// log_rotation.php - Run this daily via cron
<?php
$logDir = '../logs/';
$maxDays = 30; // Keep logs for 30 days

foreach (glob($logDir . "sales_*.log") as $logFile) {
    $fileDate = substr(basename($logFile), 6, 10); // Extract date from filename
    $fileAge = (time() - strtotime($fileDate)) / (60 * 60 * 24);
    
    if ($fileAge > $maxDays) {
        unlink($logFile);
    }
}