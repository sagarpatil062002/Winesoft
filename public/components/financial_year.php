<?php
// financial_year.php - Core Financial Year Module
// Save in: components/financial_year.php

class FinancialYearModule {
    private static $instance = null;
    private $start_date;
    private $end_date;
    private $year_id;
    private $display_text;
    
    private function __construct() {
        $this->initializeFromSession();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
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
    
    public function getStartDate($format = 'Y-m-d') {
        return $this->start_date ? $this->start_date->format($format) : null;
    }
    
    public function getEndDate($format = 'Y-m-d') {
        return $this->end_date ? $this->end_date->format($format) : null;
    }
    
    public function getDisplayText() {
        return $this->display_text;
    }
    
    public function getYearId() {
        return $this->year_id;
    }
    
    public function hasFinancialYear() {
        return ($this->start_date && $this->end_date);
    }
    
    public function getDateWhereClause($date_column = 'date') {
        if (!$this->start_date || !$this->end_date) {
            return "1=1";
        }
        $start = $this->getStartDate();
        $end = $this->getEndDate();
        return "$date_column BETWEEN '$start' AND '$end'";
    }
    
    public function getFinancialYearData() {
        return [
            'start' => $this->getStartDate(),
            'end' => $this->getEndDate(),
            'display' => $this->display_text,
            'has_financial_year' => $this->hasFinancialYear()
        ];
    }
    
    public static function redirectIfNotSet($redirect_url = 'login.php') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['FIN_YEAR_START']) || !isset($_SESSION['FIN_YEAR_END'])) {
            header("Location: $redirect_url");
            exit;
        }
    }
}

// Global helper functions
function isDateInFinancialYear($date) {
    try {
        $module = FinancialYearModule::getInstance();
        if (!$module->hasFinancialYear()) return true;
        $check_date = new DateTime($date);
        $start = new DateTime($module->getStartDate());
        $end = new DateTime($module->getEndDate());
        return ($check_date >= $start && $check_date <= $end);
    } catch (Exception $e) {
        return false;
    }
}

function getFinancialYearDisplay() {
    try {
        return FinancialYearModule::getInstance()->getDisplayText() ?? 'Not Set';
    } catch (Exception $e) {
        return 'Not Set';
    }
}

function getFinancialYearWhereClause($date_column = 'date') {
    try {
        return FinancialYearModule::getInstance()->getDateWhereClause($date_column);
    } catch (Exception $e) {
        return "1=1";
    }
}
?>