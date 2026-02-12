<?php
// license_functions.php

// Function to get allowed categories based on license type
function getAllowedCategoriesByLicenseType($license_code, $conn) {
    $categories = [];
    
    switch($license_code) {
        case 'FL-III':
            // Spirit, Wine, Fermented Beer, Mild Beer
            $allowed_codes = ['CAT001', 'CAT002', 'CAT003', 'CAT004'];
            break;
        case 'FLBR-II':
            // Wine, Fermented Beer, Mild Beer
            $allowed_codes = ['CAT002', 'CAT003', 'CAT004'];
            break;
        case 'FL-II':
            // All categories allowed
            $allowed_codes = ['CAT001', 'CAT002', 'CAT003', 'CAT004', 'CAT005', 'CAT006', 'CAT007', 'CAT008'];
            break;
        case 'CL-III':
            // Only Country Liquor
            $allowed_codes = ['CAT005'];
            break;
        case 'CL-FL-III':
            // All categories allowed
            $allowed_codes = ['CAT001', 'CAT002', 'CAT003', 'CAT004', 'CAT005', 'CAT006', 'CAT007', 'CAT008'];
            break;
        case 'IMPORTED':
            // Imported spirits
            $allowed_codes = ['CAT001']; // Spirit category for imported
            break;
        case 'WINE-IMP':
            // Imported wines
            $allowed_codes = ['CAT002']; // Wine category for imported
            break;
        default:
            $allowed_codes = [];
            break;
    }
    
    if (!empty($allowed_codes)) {
        $codes = implode("','", array_map([$conn, 'real_escape_string'], $allowed_codes));
        $result = $conn->query("SELECT CATEGORY_CODE, CATEGORY_NAME, LIQ_FLAG FROM tblcategory WHERE CATEGORY_CODE IN ('$codes')");
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }
        }
    }
    
    return $categories;
}

// Function to get company license type (unchanged)
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

// Function to check if a company has access to a specific category
function hasAccessToCategory($company_id, $category_code, $conn) {
    $license_type = getCompanyLicenseType($company_id, $conn);
    $allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);
    
    foreach ($allowed_categories as $category) {
        if ($category['CATEGORY_CODE'] == $category_code) {
            return true;
        }
    }
    
    return false;
}

// Function to check if a company has access to a specific class (using new system)
function hasAccessToClassNew($company_id, $class_code, $conn) {
    // First get the category for this class
    $stmt = $conn->prepare("
        SELECT c.CATEGORY_CODE 
        FROM tblclass_new cn
        LEFT JOIN tblcategory c ON cn.CATEGORY_CODE = c.CATEGORY_CODE
        WHERE cn.CLASS_CODE = ?
    ");
    
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("s", $class_code);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        return hasAccessToCategory($company_id, $row['CATEGORY_CODE'], $conn);
    }
    
    return false;
}

// Function to get filtered classes for current company (using new system)
function getFilteredClassesNew($conn) {
    if (!isset($_SESSION['company_id'])) {
        return [];
    }
    
    $company_id = $_SESSION['company_id'];
    $license_type = getCompanyLicenseType($company_id, $conn);
    $allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);
    
    if (empty($allowed_categories)) {
        return [];
    }
    
    $category_codes = array_column($allowed_categories, 'CATEGORY_CODE');
    $codes_string = implode("','", array_map([$conn, 'real_escape_string'], $category_codes));
    
    $classes = [];
    $result = $conn->query("
        SELECT cn.CLASS_CODE, cn.CLASS_NAME, cn.CATEGORY_CODE, cn.LIQ_FLAG 
        FROM tblclass_new cn
        WHERE cn.CATEGORY_CODE IN ('$codes_string')
        ORDER BY cn.CLASS_NAME
    ");
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }
    }
    
    return $classes;
}

// Function to get filtered items for current company
function getFilteredItems($conn) {
    if (!isset($_SESSION['company_id'])) {
        return [];
    }
    
    $company_id = $_SESSION['company_id'];
    $license_type = getCompanyLicenseType($company_id, $conn);
    $allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);
    
    if (empty($allowed_categories)) {
        return [];
    }
    
    $category_codes = array_column($allowed_categories, 'CATEGORY_CODE');
    $codes_string = implode("','", array_map([$conn, 'real_escape_string'], $category_codes));
    
    $items = [];
    $result = $conn->query("
        SELECT im.CODE, im.DETAILS, im.DETAILS2, im.CLASS_CODE_NEW, im.SUBCLASS_CODE_NEW, im.SIZE_CODE, im.CATEGORY_CODE
        FROM tblitemmaster im
        WHERE im.CATEGORY_CODE IN ('$codes_string')
        ORDER BY im.DETAILS
    ");
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
    }
    
    return $items;
}

// Function to create filtered class dropdown (using new system)
function createFilteredClassDropdownNew($conn, $select_name = 'class_code', $selected_code = null) {
    $classes = getFilteredClassesNew($conn);
    
    $html = "<select name='$select_name' class='form-control'>";
    $html .= "<option value=''>Select Class</option>";
    
    foreach ($classes as $class) {
        $selected = ($selected_code == $class['CLASS_CODE']) ? 'selected' : '';
        $html .= "<option value='{$class['CLASS_CODE']}' $selected>{$class['CLASS_NAME']}</option>";
    }
    
    $html .= "</select>";
    return $html;
}

// Function to create filtered category dropdown
function createFilteredCategoryDropdown($conn, $select_name = 'category_code', $selected_code = null) {
    if (!isset($_SESSION['company_id'])) {
        return "<select name='$select_name' class='form-control'><option value=''>No Session</option></select>";
    }
    
    $company_id = $_SESSION['company_id'];
    $license_type = getCompanyLicenseType($company_id, $conn);
    $allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);
    
    $html = "<select name='$select_name' class='form-control'>";
    $html .= "<option value=''>Select Category</option>";
    
    foreach ($allowed_categories as $category) {
        $selected = ($selected_code == $category['CATEGORY_CODE']) ? 'selected' : '';
        $html .= "<option value='{$category['CATEGORY_CODE']}' $selected>{$category['CATEGORY_NAME']}</option>";
    }
    
    $html .= "</select>";
    return $html;
}

// Function to validate if current company has access to a category
function validateCategoryAccess($category_code, $conn) {
    if (!isset($_SESSION['company_id'])) {
        return false;
    }
    
    return hasAccessToCategory($_SESSION['company_id'], $category_code, $conn);
}

// Function to validate if current company has access to a class (new system)
function validateClassAccessNew($class_code, $conn) {
    if (!isset($_SESSION['company_id'])) {
        return false;
    }
    
    return hasAccessToClassNew($_SESSION['company_id'], $class_code, $conn);
}

// Function to redirect if no access to category
function redirectIfNoCategoryAccess($category_code, $conn, $redirect_url = 'unauthorized.php') {
    if (!validateCategoryAccess($category_code, $conn)) {
        header("Location: $redirect_url");
        exit();
    }
}

// Function to filter SQL query based on company's category access
function filterQueryByCategoryAccess($conn, $base_query, $category_column = 'CATEGORY_CODE') {
    if (!isset($_SESSION['company_id'])) {
        return $base_query . " WHERE 1=0"; // Return empty result if no session
    }
    
    $company_id = $_SESSION['company_id'];
    $license_type = getCompanyLicenseType($company_id, $conn);
    $allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);
    
    if (empty($allowed_categories)) {
        return $base_query . " WHERE 1=0"; // Return empty result if no access
    }
    
    $category_codes = array_column($allowed_categories, 'CATEGORY_CODE');
    $codes_string = implode("','", array_map([$conn, 'real_escape_string'], $category_codes));
    
    // Check if query already has WHERE clause
    if (stripos($base_query, 'WHERE') !== false) {
        return $base_query . " AND $category_column IN ('$codes_string')";
    } else {
        return $base_query . " WHERE $category_column IN ('$codes_string')";
    }
}

// Function to filter items query with category access
function filterItemsQuery($conn, $additional_conditions = '') {
    $base_query = "SELECT * FROM tblitemmaster";
    
    if (!isset($_SESSION['company_id'])) {
        return $base_query . " WHERE 1=0";
    }
    
    $company_id = $_SESSION['company_id'];
    $license_type = getCompanyLicenseType($company_id, $conn);
    $allowed_categories = getAllowedCategoriesByLicenseType($license_type, $conn);
    
    if (empty($allowed_categories)) {
        return $base_query . " WHERE 1=0";
    }
    
    $category_codes = array_column($allowed_categories, 'CATEGORY_CODE');
    $codes_string = implode("','", array_map([$conn, 'real_escape_string'], $category_codes));
    
    $query = $base_query . " WHERE CATEGORY_CODE IN ('$codes_string')";
    
    if (!empty($additional_conditions)) {
        $query .= " AND $additional_conditions";
    }
    
    return $query;
}

// Function to get license summary (for display purposes)
function getLicenseSummary($license_code) {
    $summary = [
        'FL-III' => 'Foreign Liquor Retail: Spirit, Wine, Fermented Beer, Mild Beer',
        'FLBR-II' => 'Foreign Liquor Bar Restaurant: Wine, Fermented Beer, Mild Beer',
        'FL-II' => 'Full Foreign Liquor: All categories',
        'CL-III' => 'Country Liquor Retail: Country Liquor only',
        'CL-FL-III' => 'Combined Country & Foreign Liquor: All categories',
        'IMPORTED' => 'Imported Products: Spirit category',
        'WINE-IMP' => 'Imported Wines: Wine category'
    ];
    
    return isset($summary[$license_code]) ? $summary[$license_code] : 'Unknown License Type';
}

// Function to get all classes for a specific license (for reference)
function getClassesForLicense($license_code, $conn) {
    $allowed_categories = getAllowedCategoriesByLicenseType($license_code, $conn);
    
    if (empty($allowed_categories)) {
        return [];
    }
    
    $category_codes = array_column($allowed_categories, 'CATEGORY_CODE');
    $codes_string = implode("','", array_map([$conn, 'real_escape_string'], $category_codes));
    
    $classes = [];
    $result = $conn->query("
        SELECT cn.CLASS_CODE, cn.CLASS_NAME, c.CATEGORY_NAME, cn.LIQ_FLAG 
        FROM tblclass_new cn
        LEFT JOIN tblcategory c ON cn.CATEGORY_CODE = c.CATEGORY_CODE
        WHERE cn.CATEGORY_CODE IN ('$codes_string')
        ORDER BY c.CATEGORY_NAME, cn.CLASS_NAME
    ");
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }
    }
    
    return $classes;
}

// Function to get classes by license type (returns SGROUP values)
function getClassesByLicenseType($license_type, $conn) {
    $classes = [];
    
    // Define allowed SGROUP values based on license type
    switch($license_type) {
        case 'FL-III':
            // Foreign Liquor: Spirit, Wine, Fermented Beer, Mild Beer
            // SGROUP: W(Wine-Spirit/Spirit), V(Wines), M(Mild Beer), F(Strong/Regular Beer)
            $allowed_sgroups = ['W', 'V', 'M', 'F', 'D', 'K', 'G', 'R']; // Spirit classes + Wine + Beer
            break;
        case 'FLBR-II':
            // Foreign Liquor Bar Restaurant: Wine, Fermented Beer, Mild Beer
            $allowed_sgroups = ['V', 'M', 'F'];
            break;
        case 'FL-II':
            // Full Foreign Liquor: All categories
            $allowed_sgroups = ['W', 'V', 'M', 'F', 'D', 'K', 'G', 'R', 'O'];
            break;
        case 'CL-III':
            // Country Liquor: Only Country Liquor
            $allowed_sgroups = ['L'];
            break;
        case 'CL-FL-III':
            // Combined Country & Foreign Liquor: All categories
            $allowed_sgroups = ['W', 'V', 'M', 'F', 'D', 'K', 'G', 'R', 'O', 'L'];
            break;
        case 'IMPORTED':
            // Imported spirits
            $allowed_sgroups = ['W']; // Imported Whisky/Spirit
            break;
        case 'WINE-IMP':
            // Imported wines
            $allowed_sgroups = ['V'];
            break;
        default:
            // Default: allow all for unknown license types
            $allowed_sgroups = ['W', 'V', 'M', 'F', 'D', 'K', 'G', 'R', 'O', 'L'];
            break;
    }
    
    // Build the classes array with SGROUP and DESC values
    foreach ($allowed_sgroups as $sgroup) {
        $desc = getSGroupDescription($sgroup);
        $classes[] = [
            'SGROUP' => $sgroup,
            'DESC' => $desc
        ];
    }
    
    return $classes;
}

// Helper function to get SGROUP description
function getSGroupDescription($sgroup) {
    $descriptions = [
        'W' => 'Whisky/Spirit',
        'V' => 'Wine',
        'D' => 'Brandy/Cognac',
        'K' => 'Vodka',
        'G' => 'Gin',
        'R' => 'Rum',
        'F' => 'Strong Beer',
        'M' => 'Mild Beer',
        'O' => 'Others',
        'L' => 'Country Liquor'
    ];
    
    return isset($descriptions[$sgroup]) ? $descriptions[$sgroup] : 'Unknown';
}

// Legacy compatibility function (maps old class IDs to new categories if needed)
function getOldClassToCategoryMapping() {
    return [
        1 => 'CAT001', // WHISKY -> Spirit
        2 => 'CAT002', // WINES -> Wine
        3 => 'CAT001', // GIN -> Spirit
        4 => 'CAT003', // FERMENTED BEER -> Fermented Beer
        5 => 'CAT001', // BRANDY -> Spirit
        6 => 'CAT001', // VODKA -> Spirit
        7 => 'CAT001', // RUM -> Spirit
        8 => 'CAT001', // OTHERS/GENERAL (Foreign) -> Spirit
        9 => 'CAT005', // LIQUORS -> Country Liquor
        10 => 'CAT005', // OTHERS/GENERAL (Country) -> Country Liquor
        11 => 'CAT006', // COLD DRINKS -> Cold Drinks
        12 => 'CAT007', // SODA -> Soda
        13 => 'CAT008', // EXTRA CLASS -> General
        14 => 'CAT004', // Mild Beer -> Mild Beer
        15 => 'CAT001', // Imported -> Spirit (Imported)
        16 => 'CAT002'  // Wine Imp -> Wine (Imported)
    ];
}
?>