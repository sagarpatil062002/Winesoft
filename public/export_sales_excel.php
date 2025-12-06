<?php
session_start();

// Ensure user is logged in and company is selected
if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    header("Location: index.php");
    exit;
}

include_once "../config/db.php";

// Get company ID from session
$compID = $_SESSION['CompID'];

// Get date parameters from request
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$view_type = isset($_GET['view_type']) ? $_GET['view_type'] : 'range';

// Adjust dates based on view type
if ($view_type === 'date') {
    $single_date = isset($_GET['Closing_Stock']) ? $_GET['Closing_Stock'] : date('Y-m-d');
    $start_date = $single_date;
    $end_date = $single_date;
} elseif ($view_type === 'all') {
    // For "all" view, use a wide date range
    $start_date = '2000-01-01';
    $end_date = '2099-12-31';
}

// Fetch sales data with item details - GROUPED by date and item
$query = "SELECT
            DATE_FORMAT(sh.BILL_DATE, '%m/%d/%Y') as 'Sale Date',
            sd.ITEM_CODE as 'Local Item Code',
            COALESCE(im.DETAILS, 'Unknown Brand') as 'Brand Name',
            COALESCE(im.DETAILS2, 'N/A') as 'Size',
            '' as 'Quantity(Case)',  -- Empty as per your format
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

// Set document properties
$spreadsheet->getProperties()
    ->setCreator("WineSoft")
    ->setLastModifiedBy("WineSoft")
    ->setTitle("Sales Report")
    ->setSubject("Sales Data Export")
    ->setDescription("Sales report generated from WineSoft");

// Set headers
$headers = ['Sale Date', 'Local Item Code', 'Brand Name', 'Size', 'Quantity(Case)', 'Quantity(Loose Bottle)'];
$sheet->fromArray($headers, NULL, 'A1');

// Style the header row
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '4472C4'],
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
        ],
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
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
        
        // Add to total quantity
        $total_qty += $sale['Quantity(Loose Bottle)'];
        $row++;
    }
    
    // Add total row
    $sheet->setCellValue('A' . $row, 'TOTAL');
    $sheet->mergeCells('A' . $row . ':E' . $row);
    $sheet->setCellValue('F' . $row, $total_qty);
    
    // Style the total row
    $totalStyle = [
        'font' => [
            'bold' => true,
        ],
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E2EFDA'],
        ],
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
            ],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_RIGHT,
        ],
    ];
    
    $sheet->getStyle('A' . $row . ':F' . $row)->applyFromArray($totalStyle);
    
    // Style the data rows
    $dataStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
            ],
        ],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
        ],
    ];

    $lastRow = count($sales_data) + 1; // +1 for header
    $sheet->getStyle('A2:F' . $lastRow)->applyFromArray($dataStyle);

    // Auto-size columns
    foreach (range('A', 'F') as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    // Freeze the header row
    $sheet->freezePane('A2');
} else {
    // No data message
    $sheet->setCellValue('A2', 'No sales data found for the selected period.');
    $sheet->mergeCells('A2:F2');
    $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
}

// Get filename from parameters
$filename = isset($_GET['filename']) ? $_GET['filename'] : 'sales_report_' . date('Y-m-d_H-i-s');

// Set headers for download
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '.xlsx"');
header('Cache-Control: max-age=0');
header('Pragma: no-cache');

// Create writer and output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>