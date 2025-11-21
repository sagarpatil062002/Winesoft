// Global Barcode Listener for WineSoft
// This script captures barcode scans across all pages and stores them in localStorage
// Excludes barcode_master.php and edit_barcode.php

// Check if current page should have barcode scanning disabled
const currentURL = window.location.href;
const disabledPages = ['barcode_master.php', 'edit_barcode.php'];
const isDisabledPage = disabledPages.some(page => currentURL.includes(page));

if (!isDisabledPage) {
    // Only run barcode scanning on allowed pages
    let barcodeBuffer = '';
    let barcodeTimeout;

document.addEventListener('keydown', function(e) {
    // Clear previous timeout
    clearTimeout(barcodeTimeout);

    if (e.key === 'Enter') {
        // Check if buffer contains a potential barcode (not empty and reasonable length)
        if (barcodeBuffer.trim() && barcodeBuffer.length >= 3) {
            // Store the barcode in localStorage
            let pendingBarcodes = JSON.parse(localStorage.getItem('pendingBarcodes') || '[]');
            pendingBarcodes.push({
                barcode: barcodeBuffer.trim(),
                timestamp: Date.now()
            });
            localStorage.setItem('pendingBarcodes', JSON.stringify(pendingBarcodes));

            // Clear buffer
            barcodeBuffer = '';

            // Optional: Show a notification that barcode was captured
            console.log('Barcode captured globally:', barcodeBuffer.trim());
        }
    } else if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
        // Add character to buffer (ignore modifier keys)
        barcodeBuffer += e.key;
    } else if (e.key === 'Backspace' && barcodeBuffer.length > 0) {
        // Allow backspace to correct
        barcodeBuffer = barcodeBuffer.slice(0, -1);
    }

    // Set timeout to clear buffer if no Enter pressed within 2 seconds
    barcodeTimeout = setTimeout(() => {
        barcodeBuffer = '';
    }, 2000);
});

// Clean up old pending barcodes (older than 1 hour)
function cleanupOldBarcodes() {
    let pendingBarcodes = JSON.parse(localStorage.getItem('pendingBarcodes') || '[]');
    const oneHourAgo = Date.now() - (60 * 60 * 1000);
    pendingBarcodes = pendingBarcodes.filter(item => item.timestamp > oneHourAgo);
    localStorage.setItem('pendingBarcodes', JSON.stringify(pendingBarcodes));
}

} // Close the if (!isDisabledPage) block

// Run cleanup on page load (runs on all pages)
cleanupOldBarcodes();