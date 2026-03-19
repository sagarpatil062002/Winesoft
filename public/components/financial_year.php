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
     * Includes: min/max constraints + tab-to-copy functionality
     */
    public function getDateValidationJS() {
        $constraints = $this->getDatePickerConstraints();
        $hasConstraints = $this->start_date && $this->end_date;
        
        $minDate = $hasConstraints ? $constraints['min'] : '';
        $maxDate = $hasConstraints ? $constraints['max'] : '';
        $displayText = $hasConstraints ? $this->display_text : 'Not Set';
        
        return "
            <script>
            (function() {
                'use strict';
                
                var finYearStart = '{$minDate}';
                var finYearEnd = '{$maxDate}';
                var finYearDisplay = '{$displayText}';
                var hasConstraints = " . ($hasConstraints ? 'true' : 'false') . ";
                
                console.log('Date Range Handler: Initializing, hasConstraints:', hasConstraints);
                
                var dateFieldMappings = [
                    { start: 'start_date', end: 'end_date' },
                    { start: 'from_date', end: 'to_date' },
                    { start: 'StartDate', end: 'EndDate' },
                    { start: 'fromDate', end: 'toDate' }
                ];
                
                function findEndDateInput(startInput) {
                    var name = startInput.name || startInput.id || '';
                    var endInput = null;
                    
                    for (var i = 0; i < dateFieldMappings.length; i++) {
                        var mapping = dateFieldMappings[i];
                        if (name.toLowerCase().includes(mapping.start.toLowerCase())) {
                            var endName = name.replace(new RegExp(mapping.start, 'i'), mapping.end);
                            endInput = document.querySelector('input[name=\"' + endName + '\"]');
                            if (endInput) break;
                            
                            var endId = startInput.id.replace(new RegExp(mapping.start, 'i'), mapping.end);
                            endInput = document.getElementById(endId);
                            if (endInput) break;
                        }
                    }
                    
                    if (!endInput) {
                        var parent = startInput.parentElement;
                        while (parent && !parent.classList.contains('row') && !parent.classList.contains('form-row')) {
                            parent = parent.parentElement;
                        }
                        if (parent) {
                            var allDateInputs = parent.querySelectorAll('input[type=\"date\"]');
                            for (var j = 0; j < allDateInputs.length; j++) {
                                if (allDateInputs[j] !== startInput) {
                                    endInput = allDateInputs[j];
                                    break;
                                }
                            }
                        }
                    }
                    
                    console.log('findEndDateInput: start=', name, 'found end=', endInput ? (endInput.name || endInput.id) : 'null');
                    return endInput;
                }
                
                function applyDateConstraints(input) {
                    if (!hasConstraints || !finYearStart || !finYearEnd) return;
                    input.min = finYearStart;
                    input.max = finYearEnd;
                }
                
                function validateFinancialYearDate(input) {
                    if (!input.value || !hasConstraints || !finYearStart || !finYearEnd) return true;
                    
                    var minDate = new Date(finYearStart + 'T00:00:00');
                    var maxDate = new Date(finYearEnd + 'T00:00:00');
                    var selectedDate = new Date(input.value + 'T00:00:00');
                    
                    if (selectedDate < minDate || selectedDate > maxDate) {
                        alert('Date must be between ' + finYearStart + ' and ' + finYearEnd + ' (Financial Year: ' + finYearDisplay + ')');
                        input.value = '';
                        input.focus();
                        return false;
                    }
                    return true;
                }
                
                function handleTabFromStartDate(startInput) {
                    console.log('handleTabFromStartDate called:', {
                        startInput: startInput.name || startInput.id,
                        startInputValue: startInput.value,
                        startInputValueEmpty: !startInput.value
                    });
                    
                    if (!startInput.value) {
                        console.log('No value in start input, not copying');
                        return;
                    }
                    
                    var endInput = findEndDateInput(startInput);
                    console.log('endInput found:', endInput ? (endInput.name || endInput.id) : 'null');
                    
                    if (endInput) {
                        console.log('endInput.value before:', endInput.value);
                    }
                    
                    // Always copy when tabbing from start date, regardless of existing value
                    // This allows user to quickly set both dates to the same value
                    if (endInput) {
                        endInput.value = startInput.value;
                        console.log('Copied date from', startInput.name || startInput.id, 'to', endInput.name || endInput.id, 'value:', startInput.value);
                        endInput.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
                
                function processDateInputs(container) {
                    if (!container) return;
                    
                    var dateInputs;
                    if (container.querySelectorAll) {
                        dateInputs = container.querySelectorAll('input[type=\"date\"]');
                    } else if (container.matches && container.matches('input[type=\"date\"]')) {
                        dateInputs = [container];
                    } else {
                        return;
                    }
                    
                    dateInputs.forEach(function(input) {
                        if (input.dataset.dateHandlerInitialized) return;
                        input.dataset.dateHandlerInitialized = 'true';
                        
                        applyDateConstraints(input);
                        
                        input.addEventListener('change', function() {
                            validateFinancialYearDate(this);
                        });
                        
                        input.addEventListener('keydown', function(e) {
                            if (e.key === 'Tab') {
                                var name = (this.name || this.id || '').toLowerCase();
                                var isStartDate = name.includes('start') || name.includes('from');
                                
                                if (isStartDate && !e.shiftKey) {
                                    handleTabFromStartDate(this);
                                }
                            }
                        });
                    });
                }
                
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function() {
                        processDateInputs(document.body);
                    });
                } else {
                    processDateInputs(document.body);
                }
                
                setTimeout(function() {
                    processDateInputs(document.body);
                }, 1000);
                
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.addedNodes) {
                            mutation.addedNodes.forEach(function(node) {
                                if (node.nodeType === 1) {
                                    processDateInputs(node);
                                }
                            });
                        }
                    });
                });
                
                try {
                    observer.observe(document.body, { childList: true, subtree: true });
                } catch(e) {}
            })();
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

// Auto-apply constraints should be called explicitly in the HTML section where needed
// Use: FinancialYearModule::autoApplyConstraints();
// This prevents output before header() calls
?>