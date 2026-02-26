<?php
session_start();
header('Content-Type: application/json');

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

include_once "../config/db.php";

$compID = $_SESSION['CompID'];

// Get parameters from POST
$view_type = isset($_POST['view_type']) ? $_POST['view_type'] : 'all';
$start_date = isset($_POST['start_date']) ? $_POST['start_date'] : date('Y-m-01');
$end_date = isset($_POST['end_date']) ? $_POST['end_date'] : date('Y-m-t');
$Closing_Stock = isset($_POST['Closing_Stock']) ? $_POST['Closing_Stock'] : date('Y-m-d');

// Adjust dates based on view type
if ($view_type === 'date') {
    $start_date = $Closing_Stock;
    $end_date = $Closing_Stock;
} elseif ($view_type === 'all') {
    $start_date = '2000-01-01';
    $end_date = '2099-12-31';
}

// Fetch sales data
$query = "SELECT
            DATE_FORMAT(sh.BILL_DATE, '%m/%d/%Y') as 'Sale Date',
            sd.ITEM_CODE as 'Local Item Code',
            COALESCE(im.DETAILS, 'Unknown Brand') as 'Brand Name',
            COALESCE(im.DETAILS2, 'N/A') as 'Size',
            '' as 'Quantity(Case)',
            SUM(sd.QTY) as 'Quantity(Loose Bottle)'
          FROM tblsaleheader sh
          INNER JOIN tblsaledetails sd ON sh.BILL_NO = sd.BILL_NO AND sh.COMP_ID = sd.COMP_ID
          LEFT JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE
          WHERE sh.COMP_ID = ?
          AND sh.BILL_DATE BETWEEN ? AND ?
          GROUP BY DATE(sh.BILL_DATE), sd.ITEM_CODE, im.DETAILS, im.DETAILS2
          ORDER BY sh.BILL_DATE, sd.ITEM_CODE";

$stmt = $conn->prepare($query);
$stmt->bind_param("iss", $compID, $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();
$sales_data = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Include PhpSpreadsheet
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Create new Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set headers
$headers = ['Sale Date', 'Local Item Code', 'Brand Name', 'Size', 'Quantity(Case)', 'Quantity(Loose Bottle)'];
$sheet->fromArray($headers, NULL, 'A1');

// Style header row
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
];
$sheet->getStyle('A1:F1')->applyFromArray($headerStyle);

// Add data
if (count($sales_data) > 0) {
    $row = 2;
    $total_qty = 0;
    
    foreach ($sales_data as $sale) {
        $sheet->setCellValue('A' . $row, $sale['Sale Date']);
        $sheet->setCellValue('B' . $row, $sale['Local Item Code']);
        $sheet->setCellValue('C' . $row, $sale['Brand Name']);
        $sheet->setCellValue('D' . $row, $sale['Size']);
        $sheet->setCellValue('E' . $row, $sale['Quantity(Case)']);
        $sheet->setCellValue('F' . $row, $sale['Quantity(Loose Bottle)']);
        $total_qty += $sale['Quantity(Loose Bottle)'];
        $row++;
    }
    
    // Add total row
    $sheet->setCellValue('A' . $row, 'TOTAL');
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $sheet->setCellValue('F' . $row, $total_qty);
    
    // Style total row
    $totalStyle = [
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
    ];
    $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray($totalStyle);
    
    // Style data rows
    $dataStyle = [
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
    ];
    $sheet->getStyle('A2:F' . ($row-1))->applyFromArray($dataStyle);
    
    // Auto-size columns
    foreach (range('A', 'F') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }
}

// Generate unique filename
$filename = 'sales_export_' . date('Y-m-d_H-i-s') . '.xlsx';
$filepath = '../temp_exports/' . $filename;

// Create directory if not exists
if (!file_exists('../temp_exports')) {
    mkdir('../temp_exports', 0777, true);
}

// Save file
$writer = new Xlsx($spreadsheet);
$writer->save($filepath);

// Clean up old files (keep only last 10)
$files = glob('../temp_exports/*.xlsx');
if (count($files) > 10) {
    usort($files, function($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    $filesToDelete = array_slice($files, 0, count($files) - 10);
    foreach ($filesToDelete as $file) {
        unlink($file);
    }
}

echo json_encode([
    'success' => true,
    'filename' => $filename,
    'filepath' => $filepath,
    'records' => count($sales_data)
]);
exit;
?>