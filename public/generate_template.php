<?php
// generate_template.php - Generate Excel Template for Purchase Import
// This creates a downloadable .xlsx template file

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator('WineSoft')
    ->setTitle('Purchase Import Template')
    ->setSubject('Template for importing purchase data')
    ->setDescription('Excel template for purchase import - WineSoft');

// Set page setup
$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(PageSetup::PAPERSIZE_A4);

// Set column widths
$sheet->getColumnDimension('A')->setWidth(15); // RECEIVED_DATE
$sheet->getColumnDimension('B')->setWidth(18);  // AUTO_TP_NO
$sheet->getColumnDimension('C')->setWidth(18);  // MANUAL_TP_NO
$sheet->getColumnDimension('D')->setWidth(12);  // TP_DATE
$sheet->getColumnDimension('E')->setWidth(15);  // DISTRICT
$sheet->getColumnDimension('F')->setWidth(15);  // SCM_PARTY_CODE
$sheet->getColumnDimension('G')->setWidth(30);  // PARTY_NAME
$sheet->getColumnDimension('H')->setWidth(10);  // SRNO
$sheet->getColumnDimension('I')->setWidth(15);  // SCM_ITEM_CODE
$sheet->getColumnDimension('J')->setWidth(35); // ITEM_NAME
$sheet->getColumnDimension('K')->setWidth(12); // SIZE
$sheet->getColumnDimension('L')->setWidth(12); // QTY_CASES
$sheet->getColumnDimension('M')->setWidth(12); // QTY_BOTTLES
$sheet->getColumnDimension('N')->setWidth(12); // BATCH_NO
$sheet->getColumnDimension('O')->setWidth(12); // MFG_MONTH
$sheet->getColumnDimension('P')->setWidth(12); // MRP
$sheet->getColumnDimension('Q')->setWidth(12); // BL
$sheet->getColumnDimension('R')->setWidth(12); // VV
$sheet->getColumnDimension('S')->setWidth(15); // TOTAL_BOT_QTY

// Title row
$sheet->setCellValue('A1', 'WineSoft Purchase Import Template');
$sheet->mergeCells('A1:S1');
$sheet->getStyle('A1')->getFont()->setSize(16)->setBold(true);
$sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('44738e');

// Empty row
$sheet->setCellValue('A2', '');

// Header row - Row 3
$headers = [
    'RECEIVED_DATE',
    'AUTO_TP_NO',
    'MANUAL_TP_NO',
    'TP_DATE',
    'DISTRICT',
    'SCM_PARTY_CODE',
    'PARTY_NAME',
    'SRNO',
    'SCM_ITEM_CODE',
    'ITEM_NAME',
    'SIZE',
    'QTY_CASES',
    'QTY_BOTTLES',
    'BATCH_NO',
    'MFG_MONTH',
    'MRP',
    'BL',
    'VV',
    'TOTAL_BOT_QTY'
];

$headerRow = 3;
$headerCol = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($headerCol . $headerRow, $header);
    $headerCol++;
}

// Style header row
$headerStyle = [
    'font' => [
        'bold' => true,
        'size' => 11,
        'color' => ['rgb' => 'FFFFFF']
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '2563eb']
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'FFFFFF']
        ]
    ]
];
$sheet->getStyle('A3:S3')->applyFromArray($headerStyle);

// Sample data row - Row 4 (with example data)
$sheet->setCellValue('A4', date('Y-m-d'));              // RECEIVED_DATE - Today's date
$sheet->setCellValue('B4', 'TP/2026/001');          // AUTO_TP_NO - Example TP number
$sheet->setCellValue('C4', '');                       // MANUAL_TP_NO - Leave empty if using auto
$sheet->setCellValue('D4', date('Y-m-d'));          // TP_DATE - Today's date
$sheet->setCellValue('E4', 'KOLKATA');               // DISTRICT
$sheet->setCellValue('F4', 'SCM001');               // SCM_PARTY_CODE
$sheet->setCellValue('G4', 'Sample Supplier Pvt Ltd'); // PARTY_NAME
$sheet->setCellValue('H4', '1');                   // SRNO
$sheet->setCellValue('I4', 'SCM1001');             // SCM_ITEM_CODE - Your item code with SCM prefix
$sheet->setCellValue('J4', 'Royal Whisky 750ml');  // ITEM_NAME
$sheet->setCellValue('K4', '750 ML');             // SIZE
$sheet->setCellValue('L4', '10');                 // QTY_CASES
$sheet->setCellValue('M4', '6');                   // QTY_BOTTLES
$sheet->setCellValue('N4', 'BAT001');             // BATCH_NO
$sheet->setCellValue('O4', '03/26');             // MFG_MONTH
$sheet->setCellValue('P4', '3500.00');            // MRP
$sheet->setCellValue('Q4', '42.8');                // BL
$sheet->setCellValue('R4', '0');                 // VV
$sheet->setCellValue('S4', '126');               // TOTAL_BOT_QTY - 10 cases * 12 + 6 bottles

// Style data row
$dataStyle = [
    'font' => ['size' => 10],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_LEFT,
        'vertical' => Alignment::VERTICAL_CENTER
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'cbd5e1']
        ]
    ]
];
$sheet->getStyle('A4:S4')->applyFromArray($dataStyle);

// Set more sample rows - Row 5 and 6 (with more examples)
$sheet->setCellValue('A5', date('Y-m-d'));
$sheet->setCellValue('B5', 'TP/2026/001');
$sheet->setCellValue('C5', '');
$sheet->setCellValue('D5', date('Y-m-d'));
$sheet->setCellValue('E5', 'KOLKATA');
$sheet->setCellValue('F5', 'SCM001');
$sheet->setCellValue('G5', 'Sample Supplier Pvt Ltd');
$sheet->setCellValue('H5', '2');
$sheet->setCellValue('I5', 'SCM1002');
$sheet->setCellValue('J5', 'Premium Vodka 1L');
$sheet->setCellValue('K5', '1L');
$sheet->setCellValue('L5', '5');
$sheet->setCellValue('M5', '0');
$sheet->setCellValue('N5', 'BAT002');
$sheet->setCellValue('O5', '03/26');
$sheet->setCellValue('P5', '2800.00');
$sheet->setCellValue('Q5', '0');
$sheet->setCellValue('R5', '40.0');
$sheet->setCellValue('S5', '60');

$sheet->setCellValue('A6', date('Y-m-d'));
$sheet->setCellValue('B6', 'TP/2026/002');
$sheet->setCellValue('C6', 'New TP/001');
$sheet->setCellValue('D6', date('Y-m-d'));
$sheet->setCellValue('E6', 'MUMBAI');
$sheet->setCellValue('F6', 'SCM002');
$sheet->setCellValue('G6', 'Another Supplier Co');
$sheet->setCellValue('H6', '1');
$sheet->setCellValue('I6', 'SCM2001');
$sheet->setCellValue('J6', 'Wine Red 750ml');
$sheet->setCellValue('K6', '750 ML');
$sheet->setCellValue('L6', '3');
$sheet->setCellValue('M6', '12');
$sheet->setCellValue('N6', 'BAT003');
$sheet->setCellValue('O6', '02/26');
$sheet->setCellValue('P6', '1500.00');
$sheet->setCellValue('Q6', '12.0');
$sheet->setCellValue('R6', '0');
$sheet->setCellValue('S6', '48');

$sheet->getStyle('A5:S6')->applyFromArray($dataStyle);

// Add empty row 7
$sheet->setRowDimension(7)->setCollapsed(true);

// Add instructions section starting from row 8
$instructionsRow = 8;
$sheet->setCellValue('A' . $instructionsRow, 'INSTRUCTIONS:');
$sheet->mergeCells('A' . $instructionsRow . ':S' . $instructionsRow);
$sheet->getStyle('A' . $instructionsRow)->getFont()->setSize(12)->setBold(true);
$sheet->getStyle('A' . $instructionsRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('f59e0b');
$sheet->getStyle('A' . $instructionsRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$instructions = [
    '1. File Format: Save this file as .xlsx or .xls (Excel format). Do NOT save as .csv.',
    '2. Date Format: Use YYYY-MM-DD format (e.g., 2026-03-29).',
    '3. Required Fields: RECEIVED_DATE, AUTO_TP_NO or MANUAL_TP_NO, PARTY_NAME, SCM_ITEM_CODE, ITEM_NAME, QTY_CASES.',
    '4. Item Code: Use SCM prefix (e.g., SCM1001) or without prefix (e.g., 1001).',
    '5. Size Format: Use standard sizes like 750 ML, 1L, 375 ML, 200 ML, etc.',
    '6. Case Quantity: Number of full cases (1 case = bottles per case as per configuration).',
    '7. Bottles: Loose bottles outside of cases.',
    '8. MRP: Maximum Retail Price per case. Updates item MRP if option selected during import.',
    '9. TOTAL_BOT_QTY: Total bottles (cases x bottles_per_case + loose bottles). Leave empty for auto-calculation.',
    '10. Multiple TPs: Each TP number is processed as a separate purchase record.',
    '11. Multiple Items: Add more rows for same TP (auto-grouped by TP number).',
    '12. Free Items: Use QTY_CASES=0 and QTY_BOTTLES for free quantities.',
    '',
    'Example of filled data:',
    'Row 4 shows example data that you can modify and use as reference.',
    '',
    'For support, contact your system administrator.'
];

$instructionsRow++;
foreach ($instructions as $instruction) {
    $sheet->setCellValue('A' . $instructionsRow, $instruction);
    $sheet->mergeCells('A' . $instructionsRow . ':S' . $instructionsRow);
    $sheet->getStyle('A' . $instructionsRow)->getFont()->setSize(10);
    $instructionsRow++;
}

// Set print area
$sheet->setPrintArea('A1:S' . $instructionsRow);

// Freeze pane - keep header visible
$sheet->freezePane('A4');

// Set active cell to A1
$sheet->setSelectedCell('A1');

// Output the file
$filename = 'purchase_import_template.xlsx';

// Redirect output to browser
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Create the writer
$writer = new Xlsx($spreadsheet);

// Save to php://output
$writer->save('php://output');
exit;
?>