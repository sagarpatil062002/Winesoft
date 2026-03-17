<?php
// components/financial_year_init.php

// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include the core module
require_once __DIR__ . '/financial_year.php';

// Get financial year data
$fyModule = FinancialYearModule::getInstance();
$fyData = $fyModule->getFinancialYearData();

// AUTO-FIX: If this is a GET request with no date parameters and financial year exists
// Then automatically set default dates to financial year dates
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $fyModule->hasFinancialYear()) {
    $needsDefault = true;
    
    // Check if this is a report page with date parameters
    foreach ($_GET as $key => $value) {
        if (strpos(strtolower($key), 'date') !== false || 
            strpos(strtolower($key), 'from') !== false || 
            strpos(strtolower($key), 'to') !== false) {
            $needsDefault = false;
            break;
        }
    }
    
    // If no date parameters found, set default financial year dates in GET
    if ($needsDefault) {
        $_GET['date_from'] = $fyModule->getStartDate();
        $_GET['date_to'] = $fyModule->getEndDate();
        $_GET['from_date'] = $fyModule->getStartDate();
        $_GET['to_date'] = $fyModule->getEndDate();
    }
}

// Store in global for later use
$GLOBALS['financial_year_data'] = $fyData;
?>