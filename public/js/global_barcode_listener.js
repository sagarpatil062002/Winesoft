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
    let lastKeyTime = Date.now();

    document.addEventListener('keydown', function(e) {
        const currentTime = Date.now();
        const timeSinceLastKey = currentTime - lastKeyTime;
        lastKeyTime = currentTime;

        // If too much time passed since last key, reset buffer (not a barcode scan)
        if (timeSinceLastKey > 100) { // 100ms between keystrokes max for barcode scanner
            barcodeBuffer = '';
        }

        // Clear previous timeout
        clearTimeout(barcodeTimeout);

        if (e.key === 'Enter') {
            // Check if buffer contains a potential barcode (not empty and reasonable length)
            if (barcodeBuffer.trim() && barcodeBuffer.length >= 3) {
                const scannedBarcode = barcodeBuffer.trim();
                
                // Store in localStorage for barcode_sale.php
                let pendingBarcodes = JSON.parse(localStorage.getItem('pendingBarcodes') || '[]');
                
                // CRITICAL FIX: Create a completely unique entry for each scan
                // Use a combination of timestamp, random value, and barcode to ensure uniqueness
                const scanId = Date.now() + '_' + Math.random().toString(36).substr(2, 9) + '_' + scannedBarcode;
                
                // Calculate sequence number for this barcode
                const sameBarcodeScans = pendingBarcodes.filter(b => b.barcode === scannedBarcode);
                const sequenceNumber = sameBarcodeScans.length + 1;

                pendingBarcodes.push({
                    barcode: scannedBarcode,
                    timestamp: Date.now(),
                    page: window.location.pathname,
                    scanId: scanId,
                    sequence: sequenceNumber, // Track sequence for identical barcodes
                    uniqueId: Date.now() + '_' + Math.random().toString(36).substr(2, 16) // Extremely unique ID
                });
                
                localStorage.setItem('pendingBarcodes', JSON.stringify(pendingBarcodes));
                
                // Show a subtle notification
                showBarcodeNotification(scannedBarcode, pendingBarcodes.length);
                console.log('Barcode stored for barcode_sale.php:', scannedBarcode, 'Total pending:', pendingBarcodes.length, 'Sequence:', sequenceNumber);
                
                // Clear buffer
                barcodeBuffer = '';
                
                // Prevent default if it's a barcode scan (not user typing)
                e.preventDefault();
                e.stopPropagation();
            }
        } else if (e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
            // Add character to buffer (ignore modifier keys)
            barcodeBuffer += e.key;
            
            // If typing in a form field, don't interfere with user input
            const activeElement = document.activeElement;
            const isTypingInField = activeElement && 
                (activeElement.tagName === 'INPUT' || 
                 activeElement.tagName === 'TEXTAREA' ||
                 activeElement.isContentEditable);
            
            if (isTypingInField) {
                // User is typing in a field, don't capture as barcode
                barcodeBuffer = '';
                return;
            }
        } else if (e.key === 'Backspace' && barcodeBuffer.length > 0) {
            // Allow backspace to correct
            barcodeBuffer = barcodeBuffer.slice(0, -1);
        }

        // Set timeout to clear buffer if no Enter pressed within 1 second
        barcodeTimeout = setTimeout(() => {
            barcodeBuffer = '';
        }, 1000);
    });

    // Show a notification when barcode is stored
    function showBarcodeNotification(barcode, totalCount) {
        // Remove any existing notification
        const existingNotification = document.getElementById('barcode-notification');
        if (existingNotification) {
            existingNotification.remove();
        }
        
        // Create notification
        const notification = document.createElement('div');
        notification.id = 'barcode-notification';
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 15px 20px;
            border-radius: 5px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 9999;
            font-family: Arial, sans-serif;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease-out;
        `;
        
        notification.innerHTML = `
            <i class="fas fa-check-circle" style="font-size: 18px;"></i>
            <div>
                <strong>Barcode Scanned! (${totalCount} items)</strong><br>
                <small>Code: ${barcode}</small><br>
                <small>Will be added to POS when opened</small>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 3 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOut 0.3s ease-out';
                setTimeout(() => notification.remove(), 300);
            }
        }, 3000);
        
        // Add CSS animations
        if (!document.getElementById('barcode-notification-styles')) {
            const style = document.createElement('style');
            style.id = 'barcode-notification-styles';
            style.textContent = `
                @keyframes slideIn {
                    from { transform: translateX(100%); opacity: 0; }
                    to { transform: translateX(0); opacity: 1; }
                }
                @keyframes slideOut {
                    from { transform: translateX(0); opacity: 1; }
                    to { transform: translateX(100%); opacity: 0; }
                }
            `;
            document.head.appendChild(style);
        }
    }

} // Close the if (!isDisabledPage) block

// Clean up old pending barcodes (older than 1 hour)
function cleanupOldBarcodes() {
    let pendingBarcodes = JSON.parse(localStorage.getItem('pendingBarcodes') || '[]');
    const oneHourAgo = Date.now() - (60 * 60 * 1000);
    pendingBarcodes = pendingBarcodes.filter(item => item.timestamp > oneHourAgo);
    localStorage.setItem('pendingBarcodes', JSON.stringify(pendingBarcodes));
}

// Run cleanup on page load (runs on all pages)
cleanupOldBarcodes();