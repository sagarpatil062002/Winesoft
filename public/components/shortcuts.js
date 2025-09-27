// components/shortcuts.js
document.addEventListener('DOMContentLoaded', function() {
    let shortcuts = [];
    let isInitialized = false;

    // Initialize shortcuts system
    function initShortcuts() {
        if (isInitialized) return;
        
        fetchShortcuts().then(() => {
            if (shortcuts.length > 0) {
                setupKeyboardListeners();
                isInitialized = true;
                console.log('Shortcuts system initialized with', shortcuts.length, 'shortcuts');
            }
        }).catch(error => {
            console.error('Failed to initialize shortcuts:', error);
        });
    }

    // Fetch shortcuts from server
    async function fetchShortcuts() {
        try {
            const response = await fetch('get_shortcuts.php');
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            shortcuts = await response.json();
        } catch (error) {
            console.error('Error fetching shortcuts:', error);
            shortcuts = [];
        }
    }

    // Setup keyboard event listeners
    function setupKeyboardListeners() {
        document.addEventListener('keydown', function(event) {
            // Only handle events on the body/document, not input fields
            if (event.target.tagName === 'INPUT' || 
                event.target.tagName === 'TEXTAREA' || 
                event.target.isContentEditable) {
                return;
            }

            const keyCombination = getKeyCombination(event);
            
            // Find matching shortcut
            const shortcut = shortcuts.find(s => 
                s.shortcut_key.toLowerCase() === keyCombination.toLowerCase()
            );

            if (shortcut) {
                event.preventDefault();
                event.stopPropagation();
                executeShortcut(shortcut);
            }
        });
    }

    // Get key combination string from keyboard event
    function getKeyCombination(event) {
        let keys = [];
        
        if (event.ctrlKey || event.metaKey) keys.push('ctrl');
        if (event.altKey) keys.push('alt');
        if (event.shiftKey) keys.push('shift');
        
        // Exclude modifier keys from the main key
        if (!['Control', 'Alt', 'Shift', 'Meta'].includes(event.key)) {
            keys.push(event.key.toLowerCase());
        }
        
        return keys.join('+');
    }

    // Execute shortcut action
    function executeShortcut(shortcut) {
        console.log('Executing shortcut:', shortcut.shortcut_key, '-', shortcut.action_name);
        
        // Show notification
        showShortcutNotification(shortcut.action_name);
        
        // Navigate to the URL
        if (shortcut.action_url) {
            setTimeout(() => {
                window.location.href = shortcut.action_url;
            }, 500);
        }
    }

    // Show notification for shortcut execution
    function showShortcutNotification(actionName) {
        // Remove existing notification if any
        const existingNotification = document.getElementById('shortcut-notification');
        if (existingNotification) {
            existingNotification.remove();
        }

        // Create notification element
        const notification = document.createElement('div');
        notification.id = 'shortcut-notification';
        notification.innerHTML = `
            <div style="
                position: fixed;
                top: 20px;
                right: 20px;
                background: #4CAF50;
                color: white;
                padding: 15px 20px;
                border-radius: 5px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                z-index: 10000;
                font-family: Arial, sans-serif;
                font-size: 14px;
                animation: slideIn 0.3s ease-out;
            ">
                <i class="fas fa-bolt" style="margin-right: 8px;"></i>
                Shortcut activated: ${actionName}
            </div>
        `;

        // Add styles for animation
        const style = document.createElement('style');
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

        document.body.appendChild(notification);

        // Auto-remove after 3 seconds
        setTimeout(() => {
            if (notification.parentNode) {
                notification.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.remove();
                    }
                }, 300);
            }
        }, 3000);
    }

    // Public method to reload shortcuts
    window.reloadShortcuts = function() {
        isInitialized = false;
        initShortcuts();
    };

    // Public method to get current shortcuts
    window.getCurrentShortcuts = function() {
        return shortcuts;
    };

    // Initialize when DOM is ready
    initShortcuts();

    // Also initialize when page fully loads (as backup)
    window.addEventListener('load', function() {
        if (!isInitialized) {
            setTimeout(initShortcuts, 100);
        }
    });
});