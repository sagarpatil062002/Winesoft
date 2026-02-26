<?php
session_start();

// Configuration
define('BASE_URL', 'https://scmexcise.mahaonline.gov.in/Retailer/');
define('LOGIN_URL', BASE_URL . 'Login.aspx');
define('UPLOAD_URL', BASE_URL . 'DispatchMultipleEntryAE.aspx');

// Your credentials (store these securely!)
$credentials = [
    'username' => 'YOUR_USERNAME', // Replace with actual
    'password' => 'YOUR_PASSWORD', // Replace with actual
    'period' => '7' // Apr 2025 - Mar 2026
];

/**
 * Main function to upload Excel file
 */
function uploadSalesExcel($excelFilePath, $credentials) {
    // Step 1: Get login page and extract tokens
    echo "Getting login page...\n";
    $loginTokens = getLoginPageTokens(LOGIN_URL);
    
    // Step 2: Perform login
    echo "Logging in...\n";
    $cookies = login($loginTokens, $credentials);
    
    if (!$cookies) {
        return ['success' => false, 'message' => 'Login failed'];
    }
    
    // Step 3: Get upload page tokens
    echo "Getting upload page...\n";
    $uploadTokens = getUploadPageTokens(UPLOAD_URL, $cookies);
    
    // Step 4: Upload the file
    echo "Uploading file...\n";
    $result = uploadFile(UPLOAD_URL, $excelFilePath, $uploadTokens, $cookies);
    
    return $result;
}

/**
 * Get login page and extract VIEWSTATE and EVENTVALIDATION
 */
function getLoginPageTokens($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_HEADER, true);
    
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $html = substr($response, $headerSize);
    curl_close($ch);
    
    // Extract tokens
    preg_match('/<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="([^"]+)" \/>/', $html, $viewstate);
    preg_match('/<input type="hidden" name="__EVENTVALIDATION" id="__EVENTVALIDATION" value="([^"]+)" \/>/', $html, $eventvalidation);
    preg_match('/<input type="hidden" name="hfID" id="hfID" value="([^"]+)" \/>/', $html, $hfID);
    preg_match('/<input type="hidden" name="hfVal" id="hfVal" value="([^"]+)" \/>/', $html, $hfVal);
    
    return [
        'viewstate' => $viewstate[1] ?? '',
        'eventvalidation' => $eventvalidation[1] ?? '',
        'hfID' => $hfID[1] ?? '',
        'hfVal' => $hfVal[1] ?? ''
    ];
}

/**
 * Perform login and get session cookies
 */
function login($tokens, $credentials) {
    $ch = curl_init(LOGIN_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    // Encrypt password (simplified - you need to implement actual encryption)
    $encryptedPassword = encryptPassword($credentials['password'], $tokens['hfVal'], $tokens['hfID']);
    
    // Prepare login POST data
    $postData = [
        '__VIEWSTATE' => $tokens['viewstate'],
        '__EVENTVALIDATION' => $tokens['eventvalidation'],
        'txtUserName' => $credentials['username'],
        'txtPwd' => $encryptedPassword,
        'DDPeriod' => $credentials['period'],
        'txtCaptcha' => 'MANUAL_CAPTCHA', // PROBLEM: Captcha required!
        'BtnLogin' => 'Login',
        'hfID' => $tokens['hfID'],
        'hfVal' => $tokens['hfVal']
    ];
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Check if login successful (look for redirect to ShopHome.aspx)
    if ($httpCode == 302 || strpos($response, 'ShopHome.aspx') !== false) {
        return true; // Cookies saved in cookies.txt
    }
    
    return false;
}

/**
 * Get upload page tokens
 */
function getUploadPageTokens($url, $cookies) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt');
    
    $html = curl_exec($ch);
    curl_close($ch);
    
    // Extract tokens
    preg_match('/<input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="([^"]+)" \/>/', $html, $viewstate);
    preg_match('/<input type="hidden" name="__EVENTVALIDATION" id="__EVENTVALIDATION" value="([^"]+)" \/>/', $html, $eventvalidation);
    
    return [
        'viewstate' => $viewstate[1] ?? '',
        'eventvalidation' => $eventvalidation[1] ?? ''
    ];
}

/**
 * Upload the Excel file
 */
function uploadFile($url, $filePath, $tokens, $cookies) {
    if (!file_exists($filePath)) {
        return ['success' => false, 'message' => 'File not found'];
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt');
    
    // Prepare multipart form data with file
    $postFields = [
        '__VIEWSTATE' => $tokens['viewstate'],
        '__EVENTVALIDATION' => $tokens['eventvalidation'],
        'WizardImport$FileUpload1' => new CURLFile($filePath),
        'WizardImport$btnImportFile' => 'Import File'
    ];
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Check for success message in response
    if (strpos($response, 'success') !== false || strpos($response, 'uploaded') !== false) {
        return ['success' => true, 'message' => 'File uploaded successfully'];
    } else {
        return ['success' => false, 'message' => 'Upload failed', 'response' => $response];
    }
}

/**
 * Encrypt password (YOU NEED TO IMPLEMENT THIS)
 * This needs to replicate the JavaScript encryption from the login page
 */
function encryptPassword($password, $seed1, $seed) {
    // TODO: Implement the exact encryption logic from the website
    // This needs to match: getCharCodes(password, seed1) + seed, then AES encrypt
    
    // For now, this is a placeholder - you need to reverse engineer the actual encryption
    return $password; // THIS WILL NOT WORK!
}

// Example usage (add this to your retail_sale.php)
if (isset($_POST['upload_to_excise'])) {
    $excelFile = 'path/to/your/generated/excel/file.xlsx';
    $result = uploadSalesExcel($excelFile, $credentials);
    
    if ($result['success']) {
        $_SESSION['success'] = $result['message'];
    } else {
        $_SESSION['error'] = $result['message'];
    }
    
    header('Location: retail_sale.php');
    exit;
}
?>