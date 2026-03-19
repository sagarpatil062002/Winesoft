/**
 * Date Range Handler for WineSoft
 * Handles:
 * 1. Financial year date constraints (min/max)
 * 2. Tab-to-copy functionality: when user tabs from start date, copies to end date
 * 
 * This file should be included after the financial_year.php component
 */

(function() {
    'use strict';
    
    // Configuration - will be set by PHP
    var finYearStart = '';
    var finYearEnd = '';
    var finYearDisplay = '';
    
    // Mapping of start/end date field name patterns
    var dateFieldMappings = [
        { start: 'start_date', end: 'end_date' },
        { start: 'from_date', end: 'to_date' },
        { start: 'StartDate', end: 'EndDate' },
        { start: 'fromDate', end: 'toDate' }
    ];
    
    /**
     * Initialize with financial year dates from PHP
     */
    function init(startDate, endDate, displayText) {
        finYearStart = startDate;
        finYearEnd = endDate;
        finYearDisplay = displayText;
        
        // Process all existing date inputs
        processDateInputs(document.body);
        
        // Monitor for dynamically added date inputs
        setupMutationObserver();
        
        // Also process after a short delay for late-loading forms
        setTimeout(function() {
            processDateInputs(document.body);
        }, 500);
    }
    
    /**
     * Find the corresponding end date input for a start date input
     */
    function findEndDateInput(startInput) {
        var name = startInput.name || startInput.id || '';
        var endInput = null;
        
        // Try to find by name pattern
        for (var i = 0; i < dateFieldMappings.length; i++) {
            var mapping = dateFieldMappings[i];
            if (name.toLowerCase().includes(mapping.start.toLowerCase())) {
                var endName = name.replace(new RegExp(mapping.start, 'i'), mapping.end);
                endInput = document.querySelector('input[name="' + endName + '"]');
                if (endInput) break;
                
                // Try with id
                var endId = startInput.id.replace(new RegExp(mapping.start, 'i'), mapping.end);
                endInput = document.getElementById(endId);
                if (endInput) break;
            }
        }
        
        // If not found by pattern, look for adjacent date input
        if (!endInput) {
            var parent = startInput.closest('.row, .col-md, .form-group, .mb-3, .col-md-3, .col-md-4');
            if (parent) {
                var allDateInputs = parent.querySelectorAll('input[type="date"]');
                for (var j = 0; j < allDateInputs.length; j++) {
                    if (allDateInputs[j] !== startInput) {
                        endInput = allDateInputs[j];
                        break;
                    }
                }
            }
        }
        
        return endInput;
    }
    
    /**
     * Apply financial year constraints to a date input
     */
    function applyDateConstraints(input) {
        if (!finYearStart || !finYearEnd) return;
        input.min = finYearStart;
        input.max = finYearEnd;
    }
    
    /**
     * Validate that a date is within the financial year
     */
    function validateFinancialYearDate(input) {
        if (!input.value || !finYearStart || !finYearEnd) return true;
        
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
    
    /**
     * Handle Tab key from start date - copy to end date
     */
    function handleTabFromStartDate(startInput) {
        if (!startInput.value) return;
        
        var endInput = findEndDateInput(startInput);
        if (endInput && !endInput.value) {
            // Copy the start date to end date
            endInput.value = startInput.value;
            
            // Trigger change event on end input
            endInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    
    /**
     * Process all date inputs within a container
     */
    function processDateInputs(container) {
        if (!container) return;
        
        var dateInputs;
        if (container.querySelectorAll) {
            dateInputs = container.querySelectorAll('input[type="date"]');
        } else if (container.matches && container.matches('input[type="date"]')) {
            dateInputs = [container];
        } else {
            return;
        }
        
        dateInputs.forEach(function(input) {
            // Skip if already processed
            if (input.dataset.dateHandlerInitialized) return;
            input.dataset.dateHandlerInitialized = 'true';
            
            // Apply constraints
            applyDateConstraints(input);
            
            // Add change event for validation
            input.addEventListener('change', function() {
                validateFinancialYearDate(this);
            });
            
            // Add keydown event for Tab key - copy start to end date
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Tab') {
                    // Determine if this looks like a start date field
                    var name = (this.name || this.id || '').toLowerCase();
                    var isStartDate = name.includes('start') || name.includes('from');
                    
                    if (isStartDate && !e.shiftKey) {
                        // Small delay to allow focus to move
                        var self = this;
                        setTimeout(function() {
                            handleTabFromStartDate(self);
                        }, 10);
                    }
                }
            });
        });
    }
    
    /**
     * Setup MutationObserver for dynamically added date inputs
     */
    function setupMutationObserver() {
        var observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.addedNodes) {
                    mutation.addedNodes.forEach(function(node) {
                        if (node.nodeType === 1) { // Element node
                            processDateInputs(node);
                        }
                    });
                }
            });
        });
        
        observer.observe(document.body, { childList: true, subtree: true });
    }
    
    // Expose init function globally so PHP can call it
    window.initDateRangeHandler = init;
    
})();
