<?php
// drydays_functions.php - Complete dry days functionality with auto-restriction

class DryDaysManager {
    private $conn;
    
    public function __construct($conn) {
        $this->conn = $conn;
    }
    
    public function getDryDaysInRange($start_date, $end_date) {
        $dry_days = [];
        $query = "SELECT dry_date, description FROM dry_days 
                 WHERE dry_date BETWEEN ? AND ? ORDER BY dry_date";
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
    
    public function isDryDay($date, $company_id = null) {
        $query = "SELECT COUNT(*) as count FROM dry_days 
                 WHERE dry_date = ? AND (company_id IS NULL OR company_id = ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $date, $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        return $row['count'] > 0;
    }
    
    public function validateDateRangeExcludingDryDays($start_date, $end_date, $company_id = null) {
        $dry_days = $this->getDryDaysInRange($start_date, $end_date, $company_id);
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
    private $companyId;
    
    public function __construct($conn, $companyId) {
        $this->conn = $conn;
        $this->companyId = $companyId;
    }
    
    public function autoCheck() {
        // Only check for POST requests (form submissions)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return true;
        }
        
        $scriptName = basename($_SERVER['SCRIPT_NAME']);
        
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
            $isDryDay = $dryDaysManager->isDryDay($operationDate, $this->companyId);
            
            if ($isDryDay) {
                $this->handleDryDayError($operationDate);
            }
        }
        
        return true;
    }
    
    private function isDateRangeOperation($scriptName) {
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
    $company_id = $_SESSION['CompID'] ?? null;
    
    // Extract date range from query if possible, or use session/default
    $start_date = $_SESSION['report_start_date'] ?? date('Y-m-01');
    $end_date = $_SESSION['report_end_date'] ?? date('Y-m-d');
    
    $dry_days = $dryDaysManager->getDryDaysInRange($start_date, $end_date, $company_id);
    
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