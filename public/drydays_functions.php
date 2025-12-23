<?php
// drydays_functions.php - Complete dry days functionality with auto-restriction

class DryDaysManager {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function getDryDaysInRange($start_date, $end_date) { // ✅ Only 2 parameters
        $dry_days = [];
        
        $query = "SELECT dry_date, description FROM dry_days 
                 WHERE dry_date BETWEEN ? AND ? 
                 ORDER BY dry_date";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ss", $start_date, $end_date);
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $dry_days[$row['dry_date']] = $row['description'];
        }
        $stmt->close();
        
        return $dry_days;
    }
    
    public function isDryDay($date) { // ✅ Only 1 parameter
        $query = "SELECT COUNT(*) as count FROM dry_days 
                 WHERE dry_date = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("s", $date);
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row['count'] > 0;
    }
    
    public function validateDateRangeExcludingDryDays($start_date, $end_date) { // ✅ Only 2 parameters
        $dry_days = $this->getDryDaysInRange($start_date, $end_date);
        $has_dry_days = !empty($dry_days);
        
        return [
            'has_dry_days' => $has_dry_days,
            'dry_days_excluded' => $dry_days,
            'message' => $has_dry_days ? 
                'Dry days have been automatically excluded from the date range.' : 
                'No dry days in the selected date range.'
        ];
    }
}

// Dry Day Auto-Restriction Class
class DryDayAutoRestrict {
    private $conn;
    private $companyId; // Still need companyId for session/redirect purposes
    private $alwaysRestrictPages = [
        'closing_stock_for_date_range.php',
        'sale_for_date_range.php',
        'purchases.php'
    ];

    public function __construct($conn, $companyId) {
        $this->conn = $conn;
        $this->companyId = $companyId;
    }

    public function autoCheck() {
        // Only check for POST requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }

        $scriptName = basename($_SERVER['SCRIPT_NAME']);

        // NEW: Check if this is one of our special pages first
        if (in_array($scriptName, $this->alwaysRestrictPages)) {
            return $this->checkForDryDayOnSpecialPages($scriptName);
        }

        // ... rest of existing autoCheck() code ...
        // Skip restriction for reports and date range operations
        if ($this->isDateRangeOperation($scriptName)) {
            return true;
        }

        // Skip restriction for specific operations that need date ranges
        if ($this->isExcludedOperation($scriptName, $_POST)) {
            return true;
        }

        $operationDate = $this->extractOperationDate();

        if ($operationDate && !$this->isReportGeneration($_POST)) {
            $dryDaysManager = new DryDaysManager($this->conn);
            $isDryDay = $dryDaysManager->isDryDay($operationDate);

            if ($isDryDay) {
                $this->handleDryDayError($operationDate);
            }
        }

        return true;
    }
    
    private function isDateRangeOperation($scriptName) {
        // NEW: Exclude our special pages first
        if (in_array($scriptName, $this->alwaysRestrictPages)) {
            return false;
        }

        $rangeOperations = [
            'reports', 'report', 'sales_report', 'purchase_report', 'stock_report',
            'ledger', 'statement', 'analysis', 'summary', 'analytics'
        ];

        foreach ($rangeOperations as $op) {
            if (stripos($scriptName, $op) !== false) {
                return true;
            }
        }

        return false;
    }
    
    private function isExcludedOperation($scriptName, $postData) {
        // NEW: Exclude our special pages first
        if (in_array($scriptName, $this->alwaysRestrictPages)) {
            return false;
        }

        // Operations that work with date ranges (not single date operations)
        $excludedOps = [
            'generate_report', 'export_data', 'view_statement', 'search',
            'filter', 'get_data', 'analytics', 'statistics', 'dashboard_data'
        ];

        // Check script name
        foreach ($excludedOps as $op) {
            if (stripos($scriptName, $op) !== false) {
                return true;
            }
        }

        // Check for date range fields in POST
        if (isset($postData['start_date']) && isset($postData['end_date'])) {
            return true;
        }

        // Check for report-specific actions
        if (isset($postData['action']) && in_array($postData['action'], ['report', 'export', 'search', 'filter'])) {
            return true;
        }

        return false;
    }
    
    private function isReportGeneration($postData) {
        // Detect if this is a report generation request
        $reportIndicators = [
            'start_date', 'end_date', 'report_type', 'export_format',
            'generate_pdf', 'download_excel', 'print_report'
        ];
        
        foreach ($reportIndicators as $indicator) {
            if (isset($postData[$indicator])) {
                return true;
            }
        }
        
        return false;
    }
    
    private function extractOperationDate() {
        // Single date operations (transactions, purchases, sales)
        $singleDateFields = [
            'date', 'transaction_date', 'purchase_date', 'sale_date',
            'entry_date', 'voucher_date', 'invoice_date', 'bill_date'
        ];
        
        foreach ($singleDateFields as $field) {
            if (!empty($_POST[$field])) {
                return $_POST[$field];
            }
        }
        
        // Check for date fields in arrays (like items[0][date])
        foreach ($_POST as $key => $value) {
            if (is_array($value) && isset($value['date'])) {
                return $value['date'];
            }
            
            // Check for keys containing 'date'
            if (strpos($key, 'date') !== false && !empty($value)) {
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * NEW: Special check for pages that use date ranges but should still be restricted
     */
    private function checkForDryDayOnSpecialPages($scriptName) {
        $dryDaysManager = new DryDaysManager($this->conn);

        // Different extraction logic for each special page
        $date = null;

        switch ($scriptName) {
            case 'closing_stock_for_date_range.php':
                // This page has start_date and end_date, but we need to check ALL dates in the range
                if (isset($_POST['start_date']) && isset($_POST['end_date'])) {
                    $start_date = $_POST['start_date'];
                    $end_date = $_POST['end_date'];

                    // Get all dry days in the range
                    $dry_days = $dryDaysManager->getDryDaysInRange($start_date, $end_date);

                    if (!empty($dry_days)) {
                        $first_dry_day = array_key_first($dry_days);
                        $this->handleDryDayError($first_dry_day);
                    }
                }
                return true;

            case 'sale_for_date_range.php':
                $date = $this->extractDateForSaleRange();
                break;

            case 'purchases.php':
                $date = $this->extractDateForPurchases();
                break;
        }

        // For other special pages, check the specific date
        if ($date) {
            $isDryDay = $dryDaysManager->isDryDay($date);
            if ($isDryDay) {
                $this->handleDryDayError($date);
            }
        }

        return true;
    }

    /**
     * NEW: Extract date for closing stock page
     */
    private function extractDateForClosingStock() {
        // Try common field names for closing stock
        $fields = ['as_on_date', 'closing_date', 'stock_date', 'date', 'sale_date'];

        foreach ($fields as $field) {
            if (!empty($_POST[$field])) {
                return $_POST[$field];
            }
        }

        // If no single date, check for start_date (beginning of range)
        if (!empty($_POST['start_date'])) {
            return $_POST['start_date'];
        }

        return null;
    }

    /**
     * NEW: Extract date for sale range page
     */
    private function extractDateForSaleRange() {
        // Try common field names for sales
        $fields = ['sale_date', 'transaction_date', 'invoice_date', 'date'];

        foreach ($fields as $field) {
            if (!empty($_POST[$field])) {
                return $_POST[$field];
            }
        }

        // Check for start_date as fallback
        if (!empty($_POST['start_date'])) {
            return $_POST['start_date'];
        }

        return null;
    }

    /**
     * NEW: Extract date for purchases page
     */
    private function extractDateForPurchases() {
        // Try common field names for purchases
        $fields = ['purchase_date', 'bill_date', 'grn_date', 'date', 'entry_date'];

        foreach ($fields as $field) {
            if (!empty($_POST[$field])) {
                return $_POST[$field];
            }
        }

        return null;
    }

    private function handleDryDayError($operationDate) {
        $formattedDate = date('d-m-Y', strtotime($operationDate));
        $_SESSION['error'] = "Operations not allowed on dry day: $formattedDate";

        // Redirect back to previous page
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'dashboard.php'));
        exit;
    }
}

// Query filtering function for dry days exclusion
function filterQueryByClassAccessAndDryDays($conn, $base_query, $class_id_field = 'class_id', $date_field = 'transaction_date') {
    // Add class access filtering if user class restrictions exist
    $filtered_query = $base_query;
    
    if (isset($_SESSION['user_class_access']) && !empty($_SESSION['user_class_access'])) {
        $class_ids = implode(",", array_map('intval', $_SESSION['user_class_access']));
        $filtered_query .= " AND $class_id_field IN ($class_ids)";
    }
    
    // Add dry days exclusion
    $dryDaysManager = new DryDaysManager($conn);
    
    // Extract date range from query if possible, or use session/default
    $start_date = $_SESSION['report_start_date'] ?? date('Y-m-01');
    $end_date = $_SESSION['report_end_date'] ?? date('Y-m-d');
    
    $dry_days = $dryDaysManager->getDryDaysInRange($start_date, $end_date);
    
    if (!empty($dry_days)) {
        $dry_dates = array_keys($dry_days);
        $quoted_dates = array_map(function($date) use ($conn) {
            return "'" . $conn->real_escape_string($date) . "'";
        }, $dry_dates);
        $dry_dates_string = implode(",", $quoted_dates);
        $filtered_query .= " AND $date_field NOT IN ($dry_dates_string)";
    }
    
    return $filtered_query;
}

// Auto-initialize dry day restriction
function initializeDryDayRestriction($conn) {
    if (session_status() === PHP_SESSION_ACTIVE && 
        isset($_SESSION['CompID']) && 
        !empty($_SESSION['CompID'])) {
        
        $restrictor = new DryDayAutoRestrict($conn, $_SESSION['CompID']);
        $restrictor->autoCheck();
    }
}

// Initialize automatically when this file is included
if (isset($conn)) {
    initializeDryDayRestriction($conn);
}

?>