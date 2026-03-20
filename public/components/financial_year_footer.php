<?php
// components/financial_year_footer.php

// Get financial year data from global
$fyData = $GLOBALS['financial_year_data'] ?? null;

if ($fyData && $fyData['has_financial_year']):
?>
<!-- Financial Year Handler Initialization -->
<script src="components/date_range_handler.js"></script>
<script>
// Initialize immediately
(function() {
    var startDate = '<?php echo $fyData['start']; ?>';
    var endDate = '<?php echo $fyData['end']; ?>';
    var displayText = '<?php echo $fyData['display']; ?>';
    
    console.log('FinancialYear: Initializing with', startDate, 'to', endDate);
    
    function initHandler() {
        if (window.FinancialYearHandler) {
            FinancialYearHandler.init(startDate, endDate, displayText);
        } else {
            setTimeout(initHandler, 50);
        }
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initHandler);
    } else {
        initHandler();
    }
})();
</script>
<?php endif; ?>