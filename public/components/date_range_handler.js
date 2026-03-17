/**
 * date_range_handler.js - Complete solution for all naming conventions
 */

(function() {
    'use strict';
    
    var FinancialYearHandler = {
        startDate: '',
        endDate: '',
        displayText: '',
        initialized: false,
        
        // Complete mapping of start/end field patterns
        fieldMappings: [
            // Common patterns
            { start: 'start_date', end: 'end_date' },
            { start: 'from_date', end: 'to_date' },
            { start: 'StartDate', end: 'EndDate' },
            { start: 'FromDate', end: 'ToDate' },
            { start: 'startdate', end: 'enddate' },
            { start: 'fromdate', end: 'todate' },
            { start: 'start', end: 'end' },
            { start: 'from', end: 'to' },
            
            // Variations
            { start: 'date_from', end: 'date_to' },
            { start: 'datefrom', end: 'dateto' },
            { start: 'DateFrom', end: 'DateTo' },
            { start: 'START_DATE', end: 'END_DATE' },
            { start: 'FROM_DATE', end: 'TO_DATE' },
            
            // With prefixes
            { start: 'txtStartDate', end: 'txtEndDate' },
            { start: 'txtFromDate', end: 'txtToDate' },
            { start: 'startDate', end: 'endDate' },
            { start: 'fromDate', end: 'toDate' }
        ],
        
        init: function(start, end, display) {
            console.log('FinancialYearHandler: Initializing with:', {start, end, display});
            this.startDate = start;
            this.endDate = end;
            this.displayText = display;
            
            if (!this.startDate || !this.endDate) {
                console.warn('FinancialYearHandler: No financial year set');
                return;
            }
            
            this.initialized = true;
            
            // Process immediately
            this.processAllDateInputs();
            
            // Process after DOM is fully loaded
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => this.processAllDateInputs());
            }
            
            // Process multiple times for dynamically loaded content
            setTimeout(() => this.processAllDateInputs(), 100);
            setTimeout(() => this.processAllDateInputs(), 500);
            setTimeout(() => this.processAllDateInputs(), 1000);
            
            // Watch for new inputs
            this.setupMutationObserver();
        },
        
        processAllDateInputs: function() {
            var inputs = document.querySelectorAll('input[type="date"]');
            console.log('FinancialYearHandler: Processing', inputs.length, 'date inputs');
            
            inputs.forEach(input => this.processDateInput(input));
        },
        
        processDateInput: function(input) {
            if (!input || input.dataset.fyProcessed) return;
            
            // Set min/max attributes
            input.setAttribute('min', this.startDate);
            input.setAttribute('max', this.endDate);
            
            // Add data attributes for debugging
            input.setAttribute('data-fy-start', this.startDate);
            input.setAttribute('data-fy-end', this.endDate);
            
            // Mark as processed
            input.dataset.fyProcessed = 'true';
            
            // Remove existing listeners to avoid duplicates
            input.removeEventListener('change', this.validateDate);
            input.removeEventListener('keydown', this.handleKeyDown);
            input.removeEventListener('blur', this.validateDate);
            
            // Add new listeners
            input.addEventListener('change', this.validateDate.bind(this));
            input.addEventListener('keydown', this.handleKeyDown.bind(this));
            input.addEventListener('blur', this.validateDate.bind(this));
            
            // Validate existing value
            if (input.value) {
                this.validateDate.call(this, { target: input });
            }
            
            console.log('Processed:', input.name || input.id || 'unnamed input');
        },
        
        validateDate: function(e) {
            var input = e.target;
            if (!input || !input.value || !FinancialYearHandler.initialized) return true;
            
            var selectedDate = new Date(input.value + 'T00:00:00');
            var minDate = new Date(FinancialYearHandler.startDate + 'T00:00:00');
            var maxDate = new Date(FinancialYearHandler.endDate + 'T00:00:00');
            
            if (selectedDate < minDate || selectedDate > maxDate) {
                alert('Date must be between ' + FinancialYearHandler.startDate + 
                      ' and ' + FinancialYearHandler.endDate + 
                      ' (Financial Year: ' + FinancialYearHandler.displayText + ')');
                input.value = '';
                input.focus();
                return false;
            }
            return true;
        },
        
        isStartField: function(fieldName) {
            if (!fieldName) return false;
            fieldName = fieldName.toLowerCase();
            
            // Check against all start patterns
            return this.fieldMappings.some(mapping => 
                fieldName.includes(mapping.start.toLowerCase())
            ) || fieldName.includes('start') || fieldName.includes('from');
        },
        
        isEndField: function(fieldName) {
            if (!fieldName) return false;
            fieldName = fieldName.toLowerCase();
            
            // Check against all end patterns
            return this.fieldMappings.some(mapping => 
                fieldName.includes(mapping.end.toLowerCase())
            ) || fieldName.includes('end') || fieldName.includes('to');
        },
        
        findEndDateInput: function(startInput) {
            if (!startInput) return null;
            
            var startName = startInput.name || startInput.id || '';
            var endInput = null;
            
            console.log('Looking for end field matching:', startName);
            
            // Method 1: Try all mappings
            for (var i = 0; i < this.fieldMappings.length; i++) {
                var mapping = this.fieldMappings[i];
                
                if (startName.toLowerCase().includes(mapping.start.toLowerCase())) {
                    var endName = startName.replace(
                        new RegExp(mapping.start, 'i'), 
                        mapping.end
                    );
                    
                    // Try by name
                    endInput = document.querySelector(`input[name="${endName}"], input[id="${endName}"]`);
                    if (endInput) {
                        console.log('Found by mapping:', mapping.start, '->', mapping.end);
                        break;
                    }
                }
            }
            
            // Method 2: Try common replacements
            if (!endInput) {
                var replacements = [
                    ['start', 'end'], ['from', 'to'], ['Start', 'End'], ['From', 'To'],
                    ['START', 'END'], ['FROM', 'TO'], ['_date', '_date'], ['Date', 'Date']
                ];
                
                for (var i = 0; i < replacements.length; i++) {
                    var from = replacements[i][0];
                    var to = replacements[i][1];
                    
                    if (startName.toLowerCase().includes(from.toLowerCase())) {
                        var possibleName = startName.replace(new RegExp(from, 'i'), to);
                        endInput = document.querySelector(`input[name="${possibleName}"], input[id="${possibleName}"]`);
                        if (endInput) {
                            console.log('Found by replacement:', from, '->', to);
                            break;
                        }
                    }
                }
            }
            
            // Method 3: Look in the same container
            if (!endInput) {
                var parent = startInput.closest('div.row, div.col, div[class*="col-"], form, fieldset, .form-group, .input-group');
                if (parent) {
                    var dateInputs = parent.querySelectorAll('input[type="date"]');
                    
                    // First, look for fields that look like end fields
                    for (var j = 0; j < dateInputs.length; j++) {
                        var other = dateInputs[j];
                        if (other !== startInput) {
                            var otherName = (other.name || other.id || '').toLowerCase();
                            if (this.isEndField(otherName)) {
                                endInput = other;
                                console.log('Found end field in container by pattern');
                                break;
                            }
                        }
                    }
                    
                    // If still not found, take the next date input
                    if (!endInput) {
                        for (var j = 0; j < dateInputs.length; j++) {
                            if (dateInputs[j] !== startInput) {
                                endInput = dateInputs[j];
                                console.log('Found next date input in container');
                                break;
                            }
                        }
                    }
                }
            }
            
            // Method 4: Look anywhere in the form
            if (!endInput && startInput.form) {
                var formInputs = startInput.form.querySelectorAll('input[type="date"]');
                for (var k = 0; k < formInputs.length; k++) {
                    if (formInputs[k] !== startInput) {
                        var formFieldName = (formInputs[k].name || formInputs[k].id || '').toLowerCase();
                        if (this.isEndField(formFieldName)) {
                            endInput = formInputs[k];
                            console.log('Found end field in same form');
                            break;
                        }
                    }
                }
            }
            
            if (endInput) {
                console.log('End field found:', endInput.name || endInput.id);
            } else {
                console.log('No end field found for:', startName);
            }
            
            return endInput;
        },
        
        handleKeyDown: function(e) {
            if (e.key === 'Tab' && !e.shiftKey) {
                var input = e.target;
                var fieldName = (input.name || input.id || '').toLowerCase();
                
                // Check if this is a start field using any convention
                if (this.isStartField(fieldName)) {
                    console.log('Tab detected on start field:', fieldName);
                    
                    // Small delay to ensure the value is updated
                    setTimeout(() => {
                        if (input.value) {
                            var endInput = this.findEndDateInput(input);
                            
                            if (endInput) {
                                console.log('Copying value:', input.value, 'to', endInput.name || endInput.id);
                                
                                // Set the value
                                endInput.value = input.value;
                                
                                // Trigger events
                                var events = ['change', 'input', 'blur'];
                                events.forEach(eventType => {
                                    var event = new Event(eventType, { bubbles: true });
                                    endInput.dispatchEvent(event);
                                });
                                
                                // For jQuery if present
                                if (window.jQuery) {
                                    try {
                                        jQuery(endInput).trigger('change').trigger('input');
                                    } catch(err) {}
                                }
                                
                                console.log('Date copied successfully');
                            }
                        }
                    }, 50);
                }
            }
        },
        
        setupMutationObserver: function() {
            var observer = new MutationObserver(mutations => {
                mutations.forEach(mutation => {
                    if (mutation.addedNodes.length) {
                        mutation.addedNodes.forEach(node => {
                            if (node.nodeType === 1) {
                                // Check if node itself is a date input
                                if (node.tagName === 'INPUT' && node.type === 'date') {
                                    this.processDateInput(node);
                                }
                                // Check for date inputs inside node
                                var inputs = node.querySelectorAll ? node.querySelectorAll('input[type="date"]') : [];
                                inputs.forEach(input => this.processDateInput(input));
                            }
                        });
                    }
                });
            });
            
            observer.observe(document.body, { childList: true, subtree: true });
        },
        
        // Manual method to trigger copy (for testing)
        manualCopy: function(startFieldName) {
            var startInput = document.querySelector(`[name="${startFieldName}"], #${startFieldName}`);
            if (startInput) {
                this.handleKeyDown({ key: 'Tab', shiftKey: false, target: startInput });
            }
        }
    };
    
    // Make it globally available
    window.FinancialYearHandler = FinancialYearHandler;
    
    // For backward compatibility
    window.initDateRangeHandler = function(start, end, display) {
        FinancialYearHandler.init(start, end, display);
    };
    
    // Auto-initialize if data is available
    if (window.finYearData) {
        FinancialYearHandler.init(
            window.finYearData.start,
            window.finYearData.end,
            window.finYearData.display
        );
    }
    
})();