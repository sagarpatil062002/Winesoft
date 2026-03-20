<?php
// components/financial_year_auto.php - Updated to handle both naming conventions

// Include the init file
require_once __DIR__ . '/financial_year_init.php';

// Register a shutdown function to handle the footer and fix date inputs
register_shutdown_function(function() {
    // Check if we're in an HTML page
    $isHTML = false;
    foreach (headers_list() as $header) {
        if (stripos($header, 'content-type: text/html') !== false) {
            $isHTML = true;
            break;
        }
    }
    
    if (!$isHTML) {
        $isHTML = true;
    }
    
    if ($isHTML) {
        // Get the output buffer content
        $content = ob_get_clean();
        
        // Get financial year data
        $fyModule = FinancialYearModule::getInstance();
        
        // If financial year exists, fix any date inputs that have default values outside the range
        if ($fyModule->hasFinancialYear()) {
            $fyStart = $fyModule->getStartDate();
            $fyEnd = $fyModule->getEndDate();
            $fyDisplay = $fyModule->getDisplayText();
            
            // Add JavaScript to handle both naming conventions
            $fixScript = "<script>
            (function() {
                // Fix any date inputs on page load
                document.addEventListener('DOMContentLoaded', function() {
                    var dateInputs = document.querySelectorAll('input[type=\"date\"]');
                    var fyStart = '$fyStart';
                    var fyEnd = '$fyEnd';
                    var fyDisplay = '$fyDisplay';
                    
                    console.log('FinancialYear: Auto-fixing date inputs with range', fyStart, 'to', fyEnd);
                    
                    dateInputs.forEach(function(input) {
                        // Always set min/max attributes
                        input.setAttribute('min', fyStart);
                        input.setAttribute('max', fyEnd);
                        
                        // Get field name to determine if it's start or end
                        var fieldName = (input.name || input.id || '').toLowerCase();
                        
                        // If input has a value outside financial year, fix it
                        if (input.value) {
                            var selectedDate = new Date(input.value + 'T00:00:00');
                            var minDate = new Date(fyStart + 'T00:00:00');
                            var maxDate = new Date(fyEnd + 'T00:00:00');
                            
                            if (selectedDate < minDate || selectedDate > maxDate) {
                                console.log('Fixing invalid date in', fieldName);
                                
                                // Handle different naming conventions
                                if (fieldName.includes('start') || fieldName.includes('from')) {
                                    input.value = fyStart; // Start/From date gets FY start
                                } else if (fieldName.includes('end') || fieldName.includes('to')) {
                                    input.value = fyEnd; // End/To date gets FY end
                                } else {
                                    // For single date fields or unknown, clear it
                                    input.value = '';
                                }
                            }
                        }
                        
                        // Add validation on change
                        input.addEventListener('change', function() {
                            if (!this.value) return;
                            
                            var selectedDate = new Date(this.value + 'T00:00:00');
                            var minDate = new Date(fyStart + 'T00:00:00');
                            var maxDate = new Date(fyEnd + 'T00:00:00');
                            
                            if (selectedDate < minDate || selectedDate > maxDate) {
                                alert('Date must be between ' + fyStart + ' and ' + fyEnd + ' (Financial Year: ' + fyDisplay + ')');
                                this.value = '';
                                this.focus();
                            }
                        });
                    });
                });
            })();
            </script>";
            
            // Insert the fix script before closing head
            $content = str_replace('</head>', $fixScript . "\n</head>", $content);
        }
        
        // Add the footer JS before closing body
        $footerFile = __DIR__ . '/financial_year_footer.php';
        if (file_exists($footerFile)) {
            ob_start();
            include $footerFile;
            $footerContent = ob_get_clean();
            $content = str_replace('</body>', $footerContent . "\n</body>", $content);
        }
        
        // Output the modified content
        echo $content;
    } else {
        ob_end_flush();
    }
});
?>