<?php
// financial_year.php - Save this in your components directory
/**
 * Financial Year Validation Module
 * Provides comprehensive financial year validation for the entire application
 */

class FinancialYearModule {
    private static $instance = null;
    private $start_date;
    private $end_date;
    private $year_id;
    private $display_text;
    
    /**
     * Private constructor - use getInstance() instead
     */
    private function __construct() {
        $this->initializeFromSession();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Initialize from session data
     */
    private function initializeFromSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['FIN_YEAR_START']) && isset($_SESSION['FIN_YEAR_END'])) {
            $this->start_date = new DateTime($_SESSION['FIN_YEAR_START']);
            $this->end_date = new DateTime($_SESSION['FIN_YEAR_END']);
            $this->year_id = $_SESSION['FIN_YEAR_ID'] ?? null;
            
            $start_year = $this->start_date->format('Y');
            $end_year = $this->end_date->format('Y');
            $this->display_text = $start_year . '-' . $end_year;
        }
    }
    
    /**
     * Check if a date is within the financial year range
     */
    public function isDateInRange($date) {
        if (!$this->start_date || !$this->end_date) {
            return false;
        }
        
        try {
            $check_date = new DateTime($date);
            return ($check_date >= $this->start_date && $check_date <= $this->end_date);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Validate and return date if in range, otherwise return false
     */
    public function validateDate($date) {
        return $this->isDateInRange($date) ? $date : false;
    }
    
    /**
     * Get financial year start date
     */
    public function getStartDate($format = 'Y-m-d') {
        return $this->start_date ? $this->start_date->format($format) : null;
    }
    
    /**
     * Get financial year end date
     */
    public function getEndDate($format = 'Y-m-d') {
        return $this->end_date ? $this->end_date->format($format) : null;
    }
    
    /**
     * Get financial year ID
     */
    public function getYearId() {
        return $this->year_id;
    }
    
    /**
     * Get financial year display text
     */
    public function getDisplayText() {
        return $this->display_text;
    }
    
    /**
     * Validate SQL WHERE clause for date filtering
     */
    public function getDateWhereClause($date_column = 'date') {
        if (!$this->start_date || !$this->end_date) {
            return "1=1";
        }
        
        $start = $this->getStartDate();
        $end = $this->getEndDate();
        return "$date_column BETWEEN '$start' AND '$end'";
    }
    
    /**
     * Get date picker constraints for HTML forms
     */
    public function getDatePickerConstraints() {
        return [
            'min' => $this->getStartDate('Y-m-d'),
            'max' => $this->getEndDate('Y-m-d')
        ];
    }
    
    /**
     * Generate JavaScript for date validation that automatically applies to all date inputs
     */
    public function getDateValidationJS() {
        if (!$this->start_date || !$this->end_date) {
            return "";
        }
        
        $constraints = $this->getDatePickerConstraints();
        return "
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Apply constraints to all existing date inputs
                const dateInputs = document.querySelectorAll('input[type=\"date\"]');
                dateInputs.forEach(input => {
                    input.min = '{$constraints['min']}';
                    input.max = '{$constraints['max']}';
                    
                    // Validate on change
                    input.addEventListener('change', function() {
                        validateFinancialYearDate(this);
                    });
                });
                
                // Monitor for dynamically added date inputs
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.addedNodes) {
                            mutation.addedNodes.forEach(function(node) {
                                if (node.nodeType === 1) { // Element node
                                    const newDateInputs = node.querySelectorAll ? 
                                        node.querySelectorAll('input[type=\"date\"]') : 
                                        (node.matches && node.matches('input[type=\"date\"]') ? [node] : []);
                                    
                                    newDateInputs.forEach(input => {
                                        input.min = '{$constraints['min']}';
                                        input.max = '{$constraints['max']}';
                                        input.addEventListener('change', function() {
                                            validateFinancialYearDate(this);
                                        });
                                    });
                                }
                            });
                        }
                    });
                });
                
                observer.observe(document.body, { childList: true, subtree: true });
            });
            
            function validateFinancialYearDate(input) {
                const minDate = new Date('{$constraints['min']}');
                const maxDate = new Date('{$constraints['max']}');
                const selectedDate = new Date(input.value);
                
                if (input.value && (selectedDate < minDate || selectedDate > maxDate)) {
                    alert('Date must be between {$constraints['min']} and {$constraints['max']} (Financial Year: {$this->display_text})');
                    input.value = '';
                    input.focus();
                    return false;
                }
                return true;
            }
            </script>
        ";
    }
    
    /**
     * Redirect if no financial year is set
     */
    public static function redirectIfNotSet($redirect_url = 'login.php') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['FIN_YEAR_START']) || !isset($_SESSION['FIN_YEAR_END'])) {
            header("Location: $redirect_url");
            exit;
        }
    }
    
    /**
     * Automatically output the validation JavaScript when the module is included
     */
    public static function autoApplyConstraints() {
        $instance = self::getInstance();
        if ($instance->start_date && $instance->end_date) {
            echo $instance->getDateValidationJS();
        }
    }
}

/**
 * Global helper functions for easy access
 */

function isDateInFinancialYear($date) {
    try {
        $module = FinancialYearModule::getInstance();
        return $module->isDateInRange($date);
    } catch (Exception $e) {
        return false;
    }
}

function validateFinancialYearDate($date) {
    try {
        $module = FinancialYearModule::getInstance();
        return $module->validateDate($date);
    } catch (Exception $e) {
        return false;
    }
}

function getFinancialYearDisplay() {
    try {
        $module = FinancialYearModule::getInstance();
        return $module->getDisplayText();
    } catch (Exception $e) {
        return "Financial Year Not Set";
    }
}

function getFinancialYearWhereClause($date_column = 'date') {
    try {
        $module = FinancialYearModule::getInstance();
        return $module->getDateWhereClause($date_column);
    } catch (Exception $e) {
        return "1=1";
    }
}

// Auto-apply constraints when this file is included
FinancialYearModule::autoApplyConstraints();
?>