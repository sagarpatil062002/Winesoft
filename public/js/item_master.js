document.addEventListener('DOMContentLoaded', function() {
    // Mode selector
    document.querySelectorAll('input[name="liquor_mode"]').forEach(radio => {
        radio.addEventListener('change', function() {
            console.log('Selected mode:', this.value);
        });
    });

    // Search filter
    document.getElementById('searchInput').addEventListener('keyup', function() {
        let filter = this.value.toLowerCase();
        let rows = document.querySelectorAll('#itemTableBody tr');
        rows.forEach(row => {
            let text = row.textContent.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });

    // Add new item
    document.getElementById('add-item-btn').addEventListener('click', function() {
        alert('Add new item functionality goes here');
    });

    // Edit buttons
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            let itemCode = this.closest('tr').cells[0].textContent;
            alert('Edit item ' + itemCode);
        });
    });

    // Delete buttons
    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            let row = this.closest('tr');
            let itemCode = row.cells[0].textContent;
            if(confirm('Delete item ' + itemCode + '?')) {
                row.remove();
            }
        });
    });
});
