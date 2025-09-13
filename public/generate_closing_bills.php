<?php 
// Add this at the very top to handle undefined variables
$search = $search ?? '';
$all_sizes = $all_sizes ?? [];
?>

<?php if ($search !== ''): ?>
    <a href="?mode=<?= $mode ?>&sequence_type=<?= $sequence_type ?>&start_date=<?= $start_date ?>&end_date=<?= $end_date ?>" class="btn btn-secondary">Clear</a>
<?php endif; ?>
</div>
</form>
</div>
<div class="col-md-6 text-end">
<button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#saleModuleModal">
    <i class="fas fa-table"></i> Sale Module View
</button>
</div>
</div>

<!-- Sales Form -->
<form method="POST" id="salesForm">
<input type="hidden" name="start_date" value="<?= htmlspecialchars($start_date); ?>">
<input type="hidden" name="end_date" value="<?= htmlspecialchars($end_date); ?>">

<!-- Action Buttons -->
<div class="d-flex gap-2 mb-3">
<button type="button" id="shuffleBtn" class="btn btn-warning btn-action">
    <i class="fas fa-random"></i> Shuffle All
</button>
<button type="button" id="generateBillsBtn" class="btn btn-success btn-action">
    <i class="fas fa-save"></i> Generate Bills
</button>

<a href="dashboard.php" class="btn btn-secondary ms-auto">
    <i class="fas fa-sign-out-alt"></i> Exit
</a>
</div>

<div class="alert alert-info mt-3">
<i class="fas fa-info-circle"></i> 
Enter the desired closing stock for each item. The system will calculate sales as: <strong>Sales = Current Stock - Closing Stock</strong>
<br>Sales quantities will be uniformly distributed across the selected date range as whole numbers.
<br><strong>Distribution:</strong> Enter closing stock values to see the sales distribution across dates. Click "Shuffle All" to regenerate all distributions.
</div>

<!-- Items Table with Integrated Distribution Preview -->
<div class="table-container">
<table class="styled-table table-striped" id="itemsTable">
<thead class="table-header">
<tr>
    <th>Item Code</th>
    <th>Item Name</th>
    <th>Category</th>
    <th>Rate (₹)</th>
    <th>Current Stock</th>
    <th>Closing Stock</th>
    <th class="sale-qty-header">Sale Qty</th>
    <th class="action-column">Action</th>
    
    <!-- Date Distribution Headers (will be populated by JavaScript) -->
    
    <th class="hidden-columns">Amount (₹)</th>
</tr>
</thead>
<tbody>
<?php if (!empty($items)): ?>
    <?php foreach ($items as $item): 
    $item_code = $item['CODE'];
    $closing_qty = isset($closing_quantities[$item_code]) ? $closing_quantities[$item_code] : $item['CURRENT_STOCK'];
    $sale_qty = $item['CURRENT_STOCK'] - $closing_qty;
    $item_total = $sale_qty * $item['RPRICE'];
    
    // Extract size from item details
    $size = 0;
    if (preg_match('/(\d+)\s*ML/i', $item['DETAILS'], $matches)) {
        $size = $matches[1];
    }
    ?>
    <tr>
    <td><?= htmlspecialchars($item_code); ?></td>
    <td><?= htmlspecialchars($item['DETAILS']); ?></td>
    <td><?= htmlspecialchars($item['DETAILS2']); ?></td>
    <td><?= number_format($item['RPRICE'], 2); ?></td>
    <td>
        <span class="stock-info"><?= number_format($item['CURRENT_STOCK'], 3); ?></span>
    </td>
    <td>
        <input type="number" name="closing_qty[<?= htmlspecialchars($item_code); ?>]" 
                class="form-control closing-input" min="0" max="<?= $item['CURRENT_STOCK']; ?>" 
                step="1" value="<?= $closing_qty ?>" 
                data-rate="<?= $item['RPRICE'] ?>"
                data-code="<?= htmlspecialchars($item_code); ?>"
                data-stock="<?= $item['CURRENT_STOCK'] ?>"
                data-size="<?= $size ?>">
    </td>
    <td class="sale-qty-cell sale-qty" id="sale_qty_<?= htmlspecialchars($item_code); ?>">
        <?= number_format($sale_qty, 3) ?>
    </td>
    <td class="action-column">
        <button type="button" class="btn btn-sm btn-outline-secondary btn-shuffle-item" 
                data-code="<?= htmlspecialchars($item_code); ?>">
        <i class="fas fa-random"></i> Shuffle
        </button>
    </td>
    
    <!-- Date distribution cells will be inserted here by JavaScript -->
    
    <td class="amount-cell hidden-columns" id="amount_<?= htmlspecialchars($item_code); ?>">
        <?= number_format($item_total, 2) ?>
    </td>
    </tr>
    <?php endforeach; ?>
<?php else: ?>
    <tr>
    <td colspan="9" class="text-center text-muted">No items found.</td>
    </tr>
<?php endif; ?>
</tbody>
<tfoot>
<tr>
    <td colspan="7" class="text-end"><strong>Total Amount:</strong></td>
    <td class="action-column"><strong id="totalAmount">0.00</strong></td>
    <td class="hidden-columns"></td>
</tr>
</tfoot>
</table>
</div>

<!-- Ajax Loader -->
<div id="ajaxLoader" class="ajax-loader">
<div class="loader"></div>
<p>Calculating distribution...</p>
</div>
</form>
</div>

<?php include 'components/footer.php'; ?>
</div>
</div>

<!-- Sale Module View Modal -->
<div class="modal fade sale-module-modal" id="saleModuleModal" tabindex="-1" aria-labelledby="saleModuleModalLabel" aria-hidden="true">
<div class="modal-dialog">
<div class="modal-content">
<div class="modal-header">
<h5 class="modal-title" id="saleModuleModalLabel">Sale Module View</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
</div>
<div class="modal-body">
<div class="table-responsive">
    <table class="table table-bordered sale-module-table">
    <thead>
        <tr>
        <th>Category</th>
        <?php if (!empty($all_sizes)): ?>
            <?php foreach ($all_sizes as $size): ?>
            <th><?= $size ?> ML</th>
            <?php endforeach; ?>
        <?php else: ?>
            <th colspan="4" class="text-center">No sizes available</th>
        <?php endif; ?>
        </tr>
    </thead>
    <tbody>
        <?php 
        // Define categories with their IDs
        $categories = [
            'WHISKY,GIN,BRANDY,VODKA,RUM,LIQUORS,OTHERS/GENERAL' => 'SPRITS',
            'WINES' => 'WINE',
            'FERMENTED BEER' => 'FERMENTED BEER',
            'MILD BEER' => 'MILD BEER'
        ];
        
        foreach ($categories as $category_id => $category_name): 
        ?>
        <tr>
        <td><?= $category_name ?></td>
        <?php if (!empty($all_sizes)): ?>
            <?php foreach ($all_sizes as $size): ?>
            <td id="module_<?= $category_id ?>_<?= $size ?>">0</td>
            <?php endforeach; ?>
        <?php else: ?>
            <td colspan="4" class="text-center">-</td>
        <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
        <td><strong>Total</strong></td>
        <?php if (!empty($all_sizes)): ?>
            <?php foreach ($all_sizes as $size): ?>
            <td id="module_total_<?= $size ?>">0</td>
            <?php endforeach; ?>
        <?php else: ?>
            <td colspan="4" class="text-center">-</td>
        <?php endif; ?>
        </tr>
    </tfoot>
    </table>
</div>
</div>
<div class="modal-footer">
<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
</div>
</div>
</div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Global variables
const dateArray = <?= json_encode($date_array) ?>;
const daysCount = <?= $days_count ?>;

// Function to distribute sales uniformly (client-side version)
function distributeSales(total_qty, days_count) {
    if (total_qty <= 0 || days_count <= 0) return new Array(days_count).fill(0);
    
    const base_qty = Math.floor(total_qty / days_count);
    const remainder = total_qty % days_count;
    
    const daily_sales = new Array(days_count).fill(base_qty);
    
    // Distribute remainder evenly across days
    for (let i = 0; i < remainder; i++) {
        daily_sales[i]++;
    }
    
    // Shuffle the distribution to make it look more natural
    for (let i = daily_sales.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [daily_sales[i], daily_sales[j]] = [daily_sales[j], daily_sales[i]];
    }
    
    return daily_sales;
}

// Function to update the distribution preview for a specific item
function updateDistributionPreview(itemCode, closingQty) {
    const currentStock = parseFloat($(`input[data-code="${itemCode}"]`).data('stock'));
    const saleQty = currentStock - closingQty;
    const rate = parseFloat($(`input[data-code="${itemCode}"]`).data('rate'));
    const itemRow = $(`input[data-code="${itemCode}"]`).closest('tr');
    
    // Update sale quantity display
    $(`#sale_qty_${itemCode}`).text(saleQty.toFixed(3));
    
    // Remove any existing distribution cells
    itemRow.find('.date-distribution-cell').remove();
    
    if (saleQty > 0) {
        const dailySales = distributeSales(saleQty, daysCount);
        
        // Add date distribution cells after the action column
        let totalDistributed = 0;
        dailySales.forEach((qty, index) => {
            totalDistributed += qty;
            // Insert distribution cells after the action column
            $(`<td class="date-distribution-cell">${qty}</td>`).insertAfter(itemRow.find('.action-column'));
        });
        
        // Show date columns if they're hidden
        $('.date-header, .date-distribution-cell').show();
    } else {
        // Hide date columns if no items have quantity
        if ($('.closing-input').filter(function() { 
            const code = $(this).data('code');
            const currentStock = parseFloat($(this).data('stock'));
            const closingQty = parseFloat($(this).val());
            return (currentStock - closingQty) > 0; 
        }).length === 0) {
            $('.date-header, .date-distribution-cell').hide();
        }
    }
    
    // Update amount
    const amount = saleQty * rate;
    $(`#amount_${itemCode}`).text(amount.toFixed(2));
    
    return saleQty;
}

// Function to categorize item based on its category and size
function categorizeItem(itemCategory, itemName, itemSize) {
    const category = (itemCategory || '').toUpperCase();
    const name = (itemName || '').toUpperCase();
    
    // Check for wine first
    if (category.includes('WINE') || name.includes('WINE')) {
        return 'WINES';
    }
    // Check for mild beer
    else if ((category.includes('BEER') || name.includes('BEER')) && 
            (category.includes('MILD') || name.includes('MILD'))) {
        return 'MILD BEER';
    }
    // Check for regular beer
    else if (category.includes('BEER') || name.includes('BEER')) {
        return 'FERMENTED BEER';
    }
    // Everything else is spirits (WHISKY, GIN, BRANDY, VODKA, RUM, LIQUORS, OTHERS/GENERAL)
    else {
        return 'WHISKY,GIN,BRANDY,VODKA,RUM,LIQUORS,OTHERS/GENERAL';
    }
}

// Function to update sale module view
function updateSaleModuleView() {
    // Reset all values to 0
    $('.sale-module-table td').not(':first-child').text('0');
    
    // Calculate quantities for each category and size
    $('.closing-input').each(function() {
        const closingQty = parseFloat($(this).val()) || 0;
        const itemCode = $(this).data('code');
        const itemRow = $(this).closest('tr');
        const itemName = itemRow.find('td:eq(1)').text();
        const itemCategory = itemRow.find('td:eq(2)').text();
        const size = $(this).data('size');
        const currentStock = parseFloat($(this).data('stock'));
        
        const saleQty = currentStock - closingQty;
        
        if (saleQty > 0) {
            // Determine the category type
            const categoryType = categorizeItem(itemCategory, itemName, size);
            
            // Update the corresponding cell using the ID pattern
            if (size > 0) {
                const cellId = `module_${categoryType}_${size}`;
                const targetCell = $(`#${cellId}`);
                if (targetCell.length) {
                    const currentValue = parseInt(targetCell.text()) || 0;
                    targetCell.text(currentValue + saleQty);
                }
            }
        }
    });
    
    // Calculate totals
    <?php if (!empty($all_sizes)): ?>
        <?php foreach ($all_sizes as $size): ?>
            let total_<?= $size ?> = 0;
            <?php foreach ($categories as $category_id => $category_name): ?>
                total_<?= $size ?> += parseInt($('#module_<?= $category_id ?>_<?= $size ?>').text()) || 0;
            <?php endforeach; ?>
            $('#module_total_<?= $size ?>').text(total_<?= $size ?>);
        <?php endforeach; ?>
    <?php endif; ?>
}

// Function to calculate total amount
function calculateTotalAmount() {
    let total = 0;
    $('.amount-cell').each(function() {
        total += parseFloat($(this).text()) || 0;
    });
    $('#totalAmount').text(total.toFixed(2));
}

// Function to initialize date headers and closing balance column
function initializeTableHeaders() {
    // Remove existing date headers if any
    $('.date-header').remove();
    
    // Add date headers after the action column header
    dateArray.forEach(date => {
        const dateObj = new Date(date);
        const day = dateObj.getDate();
        const month = dateObj.toLocaleString('default', { month: 'short' });
        
        // Insert date headers after the action column header
        $(`<th class="date-header" title="${date}" style="display: none;">${day}<br>${month}</th>`).insertAfter($('.table-header tr th.action-column'));
    });
}

// Function to handle row navigation with arrow keys
function setupRowNavigation() {
    const closingInputs = $('input.closing-input');
    let currentRowIndex = -1;
    
    // Highlight row when input is focused
    $(document).on('focus', 'input.closing-input', function() {
        // Remove highlight from all rows
        $('tr').removeClass('highlight-row');
        
        // Add highlight to current row
        $(this).closest('tr').addClass('highlight-row');
        
        // Update current row index
        currentRowIndex = closingInputs.index(this);
    });
    
    // Handle arrow key navigation
    $(document).on('keydown', 'input.closing-input', function(e) {
        // Only handle arrow keys
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        
        e.preventDefault(); // Prevent default scrolling behavior
        
        // Calculate new row index
        let newIndex;
        if (e.key === 'ArrowUp') {
            newIndex = currentRowIndex - 1;
        } else { // ArrowDown
            newIndex = currentRowIndex + 1;
        }
        
        // Check if new index is valid
        if (newIndex >= 0 && newIndex < closingInputs.length) {
            // Focus the input in the new row
            $(closingInputs[newIndex]).focus().select();
        }
    });
}

// Function to save to pending sales
function saveToPendingSales() {
    // Show loader
    $('#ajaxLoader').show();
    
    // Collect all the data
    const formData = new FormData();
    formData.append('save_pending', 'true');
    formData.append('start_date', $('input[name="start_date"]').val());
    formData.append('end_date', $('input[name="end_date"]').val());
    
    // Add closing quantities
    $('.closing-input').each(function() {
        const code = $(this).data('code');
        const value = $(this).val();
        formData.append(`closing_qty[${code}]`, value);
    });
    
    // Send AJAX request
    $.ajax({
        url: '',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            // Hide loader
            $('#ajaxLoader').hide();
            
            // Show success message
            alert('Sales data saved to pending successfully!');
        },
        error: function() {
            // Hide loader
            $('#ajaxLoader').hide();
            
            // Show error message
            alert('Error saving to pending sales. Please try again.');
        }
    });
}

// Initialize when document is ready
$(document).ready(function() {
    // Initialize table headers
    initializeTableHeaders();
    
    // Set up row navigation
    setupRowNavigation();
    
    // Update all distributions on page load
    $('.closing-input').each(function() {
        const closingQty = parseFloat($(this).val()) || 0;
        const itemCode = $(this).data('code');
        updateDistributionPreview(itemCode, closingQty);
    });
    
    // Calculate total amount
    calculateTotalAmount();
    
    // Update sale module view
    updateSaleModuleView();
    
    // Event handler for closing stock input changes
    $(document).on('input', '.closing-input', function() {
        const closingQty = parseFloat($(this).val()) || 0;
        const itemCode = $(this).data('code');
        const currentStock = parseFloat($(this).data('stock'));
        
        // Validate input
        if (closingQty < 0) {
            $(this).val(0);
        } else if (closingQty > currentStock) {
            $(this).val(currentStock);
        }
        
        // Update distribution preview
        updateDistributionPreview(itemCode, $(this).val());
        
        // Update sale module view
        updateSaleModuleView();
        
        // Calculate total amount
        calculateTotalAmount();
    });
    
    // Event handler for shuffle all button
    $('#shuffleBtn').click(function() {
        $('.closing-input').each(function() {
            const currentStock = parseFloat($(this).data('stock'));
            const randomQty = Math.floor(Math.random() * (currentStock + 1));
            $(this).val(randomQty);
            
            const itemCode = $(this).data('code');
            updateDistributionPreview(itemCode, randomQty);
        });
        
        // Update sale module view
        updateSaleModuleView();
        
        // Calculate total amount
        calculateTotalAmount();
    });
    
    // Event handler for individual item shuffle buttons
    $(document).on('click', '.btn-shuffle-item', function() {
        const itemCode = $(this).data('code');
        const inputField = $(`input[data-code="${itemCode}"]`);
        const currentStock = parseFloat(inputField.data('stock'));
        const randomQty = Math.floor(Math.random() * (currentStock + 1));
        
        inputField.val(randomQty);
        updateDistributionPreview(itemCode, randomQty);
        
        // Update sale module view
        updateSaleModuleView();
        
        // Calculate total amount
        calculateTotalAmount();
    });
    
    // Event handler for generate bills button
    $('#generateBillsBtn').click(function() {
        // Show confirmation dialog
        if (confirm('Are you sure you want to generate bills? This action cannot be undone.')) {
            // Show loader
            $('#ajaxLoader').show();
            
            // Submit the form
            $('#salesForm').submit();
        }
    });
    
    // Event handler for save to pending button
    $('#savePendingBtn').click(function() {
        saveToPendingSales();
    });
    
    // Event handler for modal show to update sale module view
    $('#saleModuleModal').on('show.bs.modal', function() {
        updateSaleModuleView();
    });
    
    // Event handler for Enter key to move to next row
    $(document).on('keydown', '.closing-input', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            
            // Find all closing inputs
            const inputs = $('.closing-input');
            const currentIndex = inputs.index(this);
            
            // If not the last input, focus the next one
            if (currentIndex < inputs.length - 1) {
                inputs.eq(currentIndex + 1).focus().select();
            }
        }
    });
});
</script>
</body>
</html>