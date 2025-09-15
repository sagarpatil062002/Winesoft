<?php
require '../config/db.php';

$company_id = isset($_GET['company_id']) ? intval($_GET['company_id']) : 0;

if ($company_id) {
    // Get the company's financial year
    $company_query = "SELECT FIN_YEAR FROM tblCompany WHERE CompID = $company_id";
    $company_result = mysqli_query($conn, $company_query);
    $company = mysqli_fetch_assoc($company_result);
    
    // Get financial year details
    $year_query = "SELECT ID, START_DATE, END_DATE FROM tblfinyear WHERE ID = " . $company['FIN_YEAR'];
    $year_result = mysqli_query($conn, $year_query);
    $year = mysqli_fetch_assoc($year_result);
    
    header('Content-Type: application/json');
    echo json_encode([$year]);
} else {
    header('Content-Type: application/json');
    echo json_encode([]);
}