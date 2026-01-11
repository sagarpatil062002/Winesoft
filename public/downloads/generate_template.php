<?php
session_start();

// ---- Auth / company guards ----
if (!isset($_SESSION['user_id'])) { 
    header("Location: index.php"); 
    exit; 
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=purchase_import_template.csv');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fwrite($output, "\xEF\xBB\xBF");

// CSV headers matching your exact structure
$headers = [
    'Received Date',
    'Auto TP No',
    'Manual TP No', 
    'TP Date',
    'District',
    'SCM Party Code',
    'Party Name',
    'SrNo',
    'SCM Item Code',
    'Item Name',
    'Size',
    'Qty (Cases)',
    'Qty (Bottles)',
    'Batch No',
    'Mfg. Month',
    'MRP',
    'B.L.',
    'V/v %',
    'Total Bot. Qty'
];

// Write headers
fputcsv($output, $headers);

// Add sample data rows
$sampleData = [
    [
        '2025-12-07',
        'FL07122025/1234',
        '1234',
        '2025-12-06',
        'Mumbai',
        'SCM123',
        'Supplier Name',
        '1',
        'SCM001',
        'Brand Name 750ML',
        '750 ML',
        '10',
        '0',
        'BATCH001',
        'DEC2025',
        '1800.00',
        '7.5',
        '42.8',
        '120'
    ],
    [
        '2025-12-07',
        'FL07122025/1234',
        '1234',
        '2025-12-06',
        'Mumbai',
        'SCM123',
        'Supplier Name',
        '2',
        'SCM002',
        'Brand Name 375ML',
        '375 ML',
        '5',
        '6',
        'BATCH002',
        'NOV2025',
        '950.00',
        '2.25',
        '42.8',
        '66'
    ]
];

foreach ($sampleData as $row) {
    fputcsv($output, $row);
}

// Close output
fclose($output);
exit;
?>