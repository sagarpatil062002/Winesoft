<?php
// license_functions.php

// Function to get available classes based on license type
function getClassesByLicenseType($license_code, $conn) {
    $classes = [];
    
    switch($license_code) {
        case 'FL-III':
            $class_ids = [1, 2, 3, 4, 5, 6, 7, 8, 11, 12, 13, 14];
            break;
        case 'FLBR-II':
            $class_ids = [2, 4, 11, 12, 13, 14];
            break;
        case 'FL-II':
            $class_ids = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14];
            break;
        case 'CL-III':
            $class_ids = [9, 10, 11, 12, 13];
            break;
        case 'CL-FL-III':
            $class_ids = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14];
            break;
        default:
            $class_ids = [];
            break;
    }
    
    if (!empty($class_ids)) {
        $ids = implode(',', array_map('intval', $class_ids));
        $result = $conn->query("SELECT SRNO, SGROUP, `DESC`, LIQ_FLAG FROM tblclass WHERE SRNO IN ($ids) ORDER BY SRNO");
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $classes[] = $row;
            }
        }
    }
    
    return $classes;
}

// Function to get company license type
function getCompanyLicenseType($company_id, $conn) {
    $stmt = $conn->prepare("
        SELECT lt.license_code 
        FROM tblcompany c 
        JOIN license_types lt ON c.license_type_id = lt.id 
        WHERE c.CompID = ?
    ");
    
    if (!$stmt) {
        return null;
    }
    
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $company = $result->fetch_assoc();
        return $company['license_code'];
    }
    
    return null;
}

// Function to check if a company has access to a specific class
function hasAccessToClass($company_id, $class_id, $conn) {
    $license_type = getCompanyLicenseType($company_id, $conn);
    $available_classes = getClassesByLicenseType($license_type, $conn);
    
    foreach ($available_classes as $class) {
        if ($class['SRNO'] == $class_id) {
            return true;
        }
    }
    
    return false;
}

// Function to get filtered classes for current company (automatically uses session)
function getFilteredClasses($conn) {
    if (!isset($_SESSION['company_id'])) {
        return [];
    }
    
    $company_id = $_SESSION['company_id'];
    $license_type = getCompanyLicenseType($company_id, $conn);
    
    return getClassesByLicenseType($license_type, $conn);
}

// Function to create filtered dropdown (automatically uses session)
function createFilteredClassDropdown($conn, $select_name = 'class_id', $selected_id = null) {
    $classes = getFilteredClasses($conn);
    
    $html = "<select name='$select_name' class='form-control'>";
    $html .= "<option value=''>Select Class</option>";
    
    foreach ($classes as $class) {
        $selected = ($selected_id == $class['SRNO']) ? 'selected' : '';
        $html .= "<option value='{$class['SRNO']}' $selected>{$class['DESC']}</option>";
    }
    
    $html .= "</select>";
    return $html;
}

// Function to validate if current company has access to a class (for form processing)
function validateClassAccess($class_id, $conn) {
    if (!isset($_SESSION['company_id'])) {
        return false;
    }
    
    return hasAccessToClass($_SESSION['company_id'], $class_id, $conn);
}

// Function to redirect if no access to class
function redirectIfNoClassAccess($class_id, $conn, $redirect_url = 'unauthorized.php') {
    if (!validateClassAccess($class_id, $conn)) {
        header("Location: $redirect_url");
        exit();
    }
}

// Function to filter SQL query based on company's class access
function filterQueryByClassAccess($conn, $base_query, $class_id_column = 'class_id') {
    if (!isset($_SESSION['company_id'])) {
        return $base_query . " WHERE 1=0"; // Return empty result if no session
    }
    
    $company_id = $_SESSION['company_id'];
    $license_type = getCompanyLicenseType($company_id, $conn);
    $available_classes = getClassesByLicenseType($license_type, $conn);
    
    if (empty($available_classes)) {
        return $base_query . " WHERE 1=0"; // Return empty result if no access
    }
    
    $class_ids = array_column($available_classes, 'SRNO');
    $ids_string = implode(',', array_map('intval', $class_ids));
    
    // Check if query already has WHERE clause
    if (stripos($base_query, 'WHERE') !== false) {
        return $base_query . " AND $class_id_column IN ($ids_string)";
    } else {
        return $base_query . " WHERE $class_id_column IN ($ids_string)";
    }
}
?>