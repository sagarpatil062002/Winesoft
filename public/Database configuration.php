<?php
// Database configuration
$host = 'localhost';
$dbname = 'winesoft';
$username = 'root';
$password = '';

// Array of company IDs to process
$compIds = [1, 2, 3, 4, 5]; // Add all your company IDs here

// Get current month and year
$currentDate = new DateTime();
$currentYearMonth = $currentDate->format('Y-m');

try {
    // Create database connection
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Current month: $currentYearMonth\n";
    echo "Archiving previous months only...\n\n";

    foreach ($compIds as $compId) {
        echo "Processing Company ID: $compId\n";
        
        // Main table name based on company ID
        $mainTable = "tbldailystock_$compId";
        
        // Check if main table exists
        $checkMainTable = $pdo->query("SHOW TABLES LIKE '$mainTable'")->rowCount();
        
        if ($checkMainTable === 0) {
            echo "Main table $mainTable does not exist. Skipping.\n\n";
            continue;
        }
        
        // Get all unique STK_MONTH values from the main table that are before current month
        $stmt = $pdo->prepare("SELECT DISTINCT STK_MONTH FROM $mainTable WHERE STK_MONTH < ? ORDER BY STK_MONTH");
        $stmt->execute([$currentYearMonth]);
        $months = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($months)) {
            echo "No previous months found to archive for company $compId\n\n";
            continue;
        }

        echo "Months to archive for company $compId: " . implode(', ', $months) . "\n";

        foreach ($months as $stkMonth) {
            // Parse the month and year from STK_MONTH (format: YYYY-MM)
            $date = DateTime::createFromFormat('Y-m', $stkMonth);
            $month = $date->format('m');
            $year = $date->format('y');
            $fullYear = $date->format('Y');
            $fullMonth = $date->format('m');
            
            // Get the number of days in this month
            $daysInMonth = $date->format('t');
            
            // Create archive table name with company ID
            $archiveTable = "tbldailystock_{$compId}_{$month}_{$year}";
            
            // Check if archive table already exists
            $checkTable = $pdo->query("SHOW TABLES LIKE '$archiveTable'")->rowCount();
            
            if ($checkTable === 0) {
                // Build the CREATE TABLE SQL dynamically based on days in month
                $createTableSQL = "
                CREATE TABLE `$archiveTable` (
                  `DailyStockID` int(11) NOT NULL,
                  `STK_MONTH` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
                  `ITEM_CODE` varchar(20) NOT NULL,
                  `LIQ_FLAG` char(1) NOT NULL DEFAULT 'F',
                  `LAST_UPDATED` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                ";
                
                // Add day columns based on the number of days in the month
                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $dayPadded = str_pad($day, 2, '0', STR_PAD_LEFT);
                    $createTableSQL .= "
                  `DAY_{$dayPadded}_OPEN` int(11) DEFAULT 0,
                  `DAY_{$dayPadded}_PURCHASE` int(11) DEFAULT 0,
                  `DAY_{$dayPadded}_SALES` int(11) DEFAULT 0,
                  `DAY_{$dayPadded}_CLOSING` int(11) DEFAULT 0,";
                }
                
                // Remove the last comma and complete the SQL
                $createTableSQL = rtrim($createTableSQL, ',');
                $createTableSQL .= "
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;";
                
                // Create the archive table
                $pdo->exec($createTableSQL);
                echo "  ✓ Created archive table: $archiveTable with $daysInMonth days\n";
            } else {
                echo "  ✓ Archive table already exists: $archiveTable\n";
            }
            
            // Copy data from main table to archive table
            // We'll copy only the basic fields and set all day columns to 0
            $copySQL = "INSERT INTO `$archiveTable` 
                        (`DailyStockID`, `STK_MONTH`, `ITEM_CODE`, `LIQ_FLAG`, `LAST_UPDATED`) 
                        SELECT `DailyStockID`, `STK_MONTH`, `ITEM_CODE`, `LIQ_FLAG`, `LAST_UPDATED` 
                        FROM `$mainTable` 
                        WHERE `STK_MONTH` = ?";
            
            $stmt = $pdo->prepare($copySQL);
            $stmt->execute([$stkMonth]);
            $rowCount = $stmt->rowCount();
            
            echo "  ✓ Copied $rowCount records to $archiveTable for month $stkMonth\n";
            
            // Add indexes to archive table (same as original)
            $indexSQL = [
                "ALTER TABLE `$archiveTable` ADD PRIMARY KEY (`DailyStockID`)",
                "ALTER TABLE `$archiveTable` ADD UNIQUE KEY `unique_daily_stock_$compId` (`STK_MONTH`,`ITEM_CODE`)",
                "ALTER TABLE `$archiveTable` ADD KEY `ITEM_CODE_$compId` (`ITEM_CODE`)",
                "ALTER TABLE `$archiveTable` MODIFY `DailyStockID` int(11) NOT NULL AUTO_INCREMENT"
            ];
            
            foreach ($indexSQL as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    // Ignore index creation errors if they already exist
                    if (strpos($e->getMessage(), 'Duplicate key') === false) {
                        throw $e;
                    }
                }
            }
            
            echo "  ✓ Indexes added to $archiveTable\n";
        }
        echo "\n";
    }
    
    echo "Archive process completed for all company IDs (previous months only)!\n";
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>