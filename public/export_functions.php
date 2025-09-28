<?php
// export_functions.php

/**
 * Export data to Excel format
 */
function exportToExcel($data, $filename, $headers = []) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<!--[if gte mso 9]>';
    echo '<xml>';
    echo '<x:ExcelWorkbook>';
    echo '<x:ExcelWorksheets>';
    echo '<x:ExcelWorksheet>';
    echo '<x:Name>Sheet1</x:Name>';
    echo '<x:WorksheetOptions>';
    echo '<x:DisplayGridlines/>';
    echo '</x:WorksheetOptions>';
    echo '</x:ExcelWorksheet>';
    echo '</x:ExcelWorksheets>';
    echo '</x:ExcelWorkbook>';
    echo '</xml>';
    echo '<![endif]-->';
    echo '<style>';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th, td { border: 1px solid black; padding: 4px; text-align: center; }';
    echo 'th { background-color: #f0f0f0; font-weight: bold; }';
    echo '.summary-row { background-color: #e9ecef; font-weight: bold; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    
    echo $data;
    
    echo '</body>';
    echo '</html>';
    exit;
}

/**
 * Export data to CSV format
 */
function exportToCSV($data, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // For simplicity, we'll use a basic CSV conversion
    // In a real implementation, you'd parse the HTML table and convert to CSV
    $lines = explode("\n", strip_tags($data));
    foreach ($lines as $line) {
        if (trim($line)) {
            $row = array_map('trim', explode('|', str_replace(['  ', '\t'], '|', $line)));
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

/**
 * Export data to PDF format (basic HTML to PDF)
 */
function exportToPDF($data, $filename, $title = '') {
    // This is a simplified version - in production, use a proper PDF library like Dompdf
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    
    // For now, we'll output HTML that can be printed as PDF
    // In production, integrate with a PDF library
    echo '<!DOCTYPE html>';
    echo '<html>';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<title>' . $title . '</title>';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; font-size: 10px; }';
    echo 'table { border-collapse: collapse; width: 100%; }';
    echo 'th, td { border: 1px solid black; padding: 4px; text-align: center; }';
    echo 'th { background-color: #f0f0f0; font-weight: bold; }';
    echo '.summary-row { background-color: #e9ecef; font-weight: bold; }';
    echo '@media print { @page { size: landscape; margin: 0.5in; } }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<h2 style="text-align: center;">' . $title . '</h2>';
    echo $data;
    echo '</body>';
    echo '</html>';
    exit;
}
?>