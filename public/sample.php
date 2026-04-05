<?php
// Database configuration
$host = 'localhost';
$user = 'your_username';
$password = 'your_password';
$database = 'winesoft';

// Create connection
$conn = new mysqli($host, $user, $password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if file was uploaded
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['excel_file'])) {
    
    // Include PHPExcel library (download from https://github.com/PHPOffice/PHPExcel)
    require_once 'PHPExcel/Classes/PHPExcel/IOFactory.php';
    
    $file = $_FILES['excel_file']['tmp_name'];
    $fileName = $_FILES['excel_file']['name'];
    
    // Load the Excel file
    $objPHPExcel = PHPExcel_IOFactory::load($file);
    $sheet = $objPHPExcel->getActiveSheet();
    $rows = $sheet->getHighestRow();
    
    // Prepare statement for tblpurchases
    $stmtPurchase = $conn->prepare("INSERT INTO tblpurchases 
        (DATE, SUBCODE, VOC_NO, INV_NO, INV_DATE, TAMT, TPNO, TP_DATE, 
         SCHDIS, CASHDIS, OCTROI, FREIGHT, STAX_PER, STAX_AMT, 
         TCS_PER, TCS_AMT, MISC_CHARG, PUR_FLAG, CompID, AUTO_TPNO) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Prepare statement for tblpurchasedetails (if you have details)
    $stmtDetail = $conn->prepare("INSERT INTO tblpurchasedetails 
        (PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, 
         FreeCases, FreeBottles, CaseRate, MRP, Amount, BottlesPerCase) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Loop through rows (assuming row 1 is headers)
        for ($row = 2; $row <= $rows; $row++) {
            
            // Read data from each column (adjust column letters as per your Excel)
            $date = $sheet->getCell('A' . $row)->getValue();
            $subcode = $sheet->getCell('B' . $row)->getValue();
            $voc_no = $sheet->getCell('C' . $row)->getValue();
            $inv_no = $sheet->getCell('D' . $row)->getValue();
            $inv_date = $sheet->getCell('E' . $row)->getValue();
            $tamt = $sheet->getCell('F' . $row)->getValue();
            $tpno = $sheet->getCell('G' . $row)->getValue();
            $tp_date = $sheet->getCell('H' . $row)->getValue();
            $auto_tpno = $sheet->getCell('I' . $row)->getValue();
            
            // Convert Excel dates to MySQL format
            $date = ($date && is_numeric($date)) ? date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($date)) : $date;
            $inv_date = ($inv_date && is_numeric($inv_date)) ? date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($inv_date)) : $inv_date;
            $tp_date = ($tp_date && is_numeric($tp_date)) ? date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($tp_date)) : $tp_date;
            
            // Insert into tblpurchases
            $stmtPurchase->bind_param(
                "ssisssssdddddddddds",
                $date, $subcode, $voc_no, $inv_no, $inv_date, $tamt, $tpno, $tp_date,
                $schdis, $cashdis, $octroi, $freight, $stax_per, $stax_amt,
                $tcs_per, $tcs_amt, $misc_charg, $pur_flag, $compID, $auto_tpno
            );
            $stmtPurchase->execute();
            
            // Get the last inserted PurchaseID
            $purchaseId = $conn->insert_id;
            
            // If you have detail rows in another sheet or columns, extract them here
            // This part depends on your Excel structure
            
        }
        
        // Commit transaction
        $conn->commit();
        echo "Data imported successfully!";
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
    
    $stmtPurchase->close();
    $stmtDetail->close();
}

$conn->close();
?>

<!-- HTML Form for file upload -->
<!DOCTYPE html>
<html>
<head>
    <title>Import Excel to Database</title>
</head>
<body>
    <form method="post" enctype="multipart/form-data">
        <label>Select Excel File (.xls):</label>
        <input type="file" name="excel_file" accept=".xls,.xlsx" required>
        <button type="submit">Import</button>
    </form>
</body>
</html>