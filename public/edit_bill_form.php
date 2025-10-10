<?php
// edit_bill_form.php - Form for editing bills with item selection
session_start();
include_once "../config/db.php";

if (!isset($_SESSION['user_id']) || !isset($_SESSION['CompID'])) {
    header("Location: index.php");
    exit;
}

if (!isset($_GET['bill_no'])) {
    header("Location: retail_sale.php");
    exit;
}

$bill_no = $_GET['bill_no'];
$comp_id = $_SESSION['CompID'];

// Include license functions
require_once 'license_functions.php';

// Get company's license type and available classes
$license_type = getCompanyLicenseType($comp_id, $conn);
$available_classes = getClassesByLicenseType($license_type, $conn);

// Extract class SGROUP values for filtering
$allowed_classes = [];
foreach ($available_classes as $class) {
    $allowed_classes[] = $class['SGROUP'];
}

// Fetch bill data
$header_sql = "SELECT * FROM tblsaleheader WHERE BILL_NO = ? AND COMP_ID = ?";
$header_stmt = $conn->prepare($header_sql);
$header_stmt->bind_param("si", $bill_no, $comp_id);
$header_stmt->execute();
$header_result = $header_stmt->get_result();
$header = $header_result->fetch_assoc();

if (!$header) {
    $_SESSION['error'] = "Bill not found!";
    header("Location: retail_sale.php");
    exit;
}

// Fetch bill items with license filtering
$items_sql = "SELECT sd.*, im.DETAILS as item_name, im.DETAILS2 as item_details, im.RPRICE as default_rate, im.CLASS
              FROM tblsaledetails sd 
              JOIN tblitemmaster im ON sd.ITEM_CODE = im.CODE 
              WHERE sd.BILL_NO = ? AND sd.COMP_ID = ?";
$items_stmt = $conn->prepare($items_sql);
$items_stmt->bind_param("si", $bill_no, $comp_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = $items_result->fetch_all(MYSQLI_ASSOC);

// Fetch all items for dropdown with license filtering
if (!empty($allowed_classes)) {
    $class_placeholders = implode(',', array_fill(0, count($allowed_classes), '?'));
    $all_items_sql = "SELECT CODE, DETAILS, DETAILS2, RPRICE, CLASS FROM tblitemmaster 
                     WHERE CLASS IN ($class_placeholders) 
                     ORDER BY DETAILS";
    $all_items_stmt = $conn->prepare($all_items_sql);
    $all_items_stmt->bind_param(str_repeat('s', count($allowed_classes)), ...$allowed_classes);
    $all_items_stmt->execute();
    $all_items_result = $all_items_stmt->get_result();
} else {
    // If no classes allowed, return empty result
    $all_items_sql = "SELECT CODE, DETAILS, DETAILS2, RPRICE, CLASS FROM tblitemmaster WHERE 1 = 0 ORDER BY DETAILS";
    $all_items_result = $conn->query($all_items_sql);
}

$all_items = [];
if ($all_items_result) {
    $all_items = $all_items_result->fetch_all(MYSQLI_ASSOC);
}

// Include volume utilities to calculate sizes
include_once "volume_limit_utils.php";
$category_limits = getCategoryLimits($conn, $comp_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php include 'common_header.php'; ?>
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
</head>
<body>
<div class="dashboard-container">
    <?php include 'components/navbar.php'; ?>

    <div class="main-content">
        <?php include 'components/header.php'; ?>

        <div class="content-area p-3 p-md-4">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4>Edit Bill: <?= htmlspecialchars($bill_no) ?></h4>
                <a href="retail_sale.php" class="btn btn-secondary">
                    <i class="fa-solid fa-arrow-left me-2"></i>Back to Sales
                </a>
            </div>

            <!-- License Restriction Info -->
            <div class="alert alert-info mb-3">
                <strong>License Type: <?= htmlspecialchars($license_type) ?></strong>
                <p class="mb-0">Showing items for classes: 
                    <?php 
                    if (!empty($available_classes)) {
                        $class_names = [];
                        foreach ($available_classes as $class) {
                            $class_names[] = $class['DESC'] . ' (' . $class['SGROUP'] . ')';
                        }
                        echo implode(', ', $class_names);
                    } else {
                        echo 'No classes available for your license type';
                    }
                    ?>
                </p>
            </div>

            <!-- Volume Limits Info -->
            <div class="alert alert-info mb-4">
                <h6 class="alert-heading"><i class="fa-solid fa-info-circle me-2"></i>Volume Limits</h6>
                <div class="row mt-2">
                    <div class="col-md-4">
                        <strong>IMFL Limit:</strong> <?= $category_limits['IMFL'] ?> ml
                    </div>
                    <div class="col-md-4">
                        <strong>BEER Limit:</strong> <?= $category_limits['BEER'] ?> ml
                    </div>
                    <div class="col-md-4">
                        <strong>CL Limit:</strong> <?= $category_limits['CL'] ?> ml
                    </div>
                </div>
                <p class="mb-0 mt-2 text-warning">
                    <i class="fa-solid fa-exclamation-triangle me-1"></i>
                    If total volume exceeds limits, the bill will be automatically split into multiple bills.
                </p>
            </div>

            <div class="card">
                <div class="card-header fw-semibold">
                    <i class="fa-solid fa-receipt me-2"></i>Bill Information
                </div>
                <div class="card-body">
                    <form id="editBillForm">
                        <input type="hidden" name="bill_no" value="<?= htmlspecialchars($bill_no) ?>">
                        <input type="hidden" name="bill_date" value="<?= htmlspecialchars($header['BILL_DATE']) ?>">
                        
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Bill Number</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($bill_no) ?>" readonly>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Bill Date</label>
                                <input type="date" class="form-control" value="<?= htmlspecialchars($header['BILL_DATE']) ?>" readonly>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Total Amount</label>
                                <input type="text" class="form-control fw-bold text-success" id="displayTotalAmount" 
                                       value="₹<?= number_format($header['TOTAL_AMOUNT'], 2) ?>" readonly>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fw-semibold">Liquor Type</label>
                                <input type="text" class="form-control" 
                                       value="<?= $header['LIQ_FLAG'] === 'F' ? 'Foreign Liquor' : ($header['LIQ_FLAG'] === 'C' ? 'Country Liquor' : 'Others') ?>" readonly>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-semibold mb-0">
                                <i class="fa-solid fa-list me-2"></i>Bill Items
                                <span class="badge bg-primary ms-2" id="itemCount"><?= count($items) ?> items</span>
                            </h5>
                            <button type="button" class="btn btn-success btn-sm" id="addItemBtn">
                                <i class="fa-solid fa-plus me-1"></i>Add Item
                            </button>
                        </div>
                        
                        <div class="table-container">
                            <table class="styled-table">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Size</th>
                                        <th>Quantity</th>
                                        <th>Rate (₹)</th>
                                        <th>Amount (₹)</th>
                                        <th>Volume (ml)</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="itemsTable">
                                    <?php 
                                    $total_volume = 0;
                                    $total_amount = 0;
                                    foreach($items as $index => $item): 
                                        $size = getItemSize($conn, $item['ITEM_CODE'], $header['LIQ_FLAG']);
                                        $item_volume = $item['QTY'] * $size;
                                        $total_volume += $item_volume;
                                        $total_amount += $item['AMOUNT'];
                                    ?>
                                    <tr data-item-code="<?= htmlspecialchars($item['ITEM_CODE']) ?>" data-size="<?= $size ?>">
                                        <td>
                                            <select class="form-control form-control-sm item-select select2-item" name="items[<?= $index ?>][code]" required>
                                                <option value="">Select Item</option>
                                                <?php foreach($all_items as $item_option): ?>
                                                <option value="<?= htmlspecialchars($item_option['CODE']) ?>" 
                                                        data-rate="<?= $item_option['RPRICE'] ?>"
                                                        data-details="<?= htmlspecialchars($item_option['DETAILS2'] ?? '') ?>"
                                                        data-size="<?= htmlspecialchars($item_option['DETAILS2'] ?? '') ?>"
                                                        data-class="<?= htmlspecialchars($item_option['CLASS'] ?? '') ?>"
                                                        <?= $item_option['CODE'] == $item['ITEM_CODE'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($item_option['CODE']) ?> - <?= htmlspecialchars($item_option['DETAILS']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="item-details text-muted"><?= htmlspecialchars($item['item_details'] ?? '') ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info volume-badge item-size"><?= $size ?> ml</span>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control form-control-sm quantity" 
                                                   name="items[<?= $index ?>][qty]" 
                                                   value="<?= htmlspecialchars($item['QTY']) ?>" 
                                                   step="0.01" min="0.01" required>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control form-control-sm rate" 
                                                   name="items[<?= $index ?>][rate]" 
                                                   value="<?= htmlspecialchars($item['RATE']) ?>" 
                                                   step="0.01" min="0" required>
                                        </td>
                                        <td>
                                            <input type="number" class="form-control form-control-sm amount" 
                                                   value="<?= htmlspecialchars($item['AMOUNT']) ?>" readonly>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary volume-badge item-volume">
                                                <?= number_format($item_volume, 0) ?> ml
                                            </span>
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-danger btn-sm remove-item">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot class="fw-bold">
                                    <tr>
                                        <td colspan="2" class="text-end">Total:</td>
                                        <td id="totalQty"><?= number_format(array_sum(array_column($items, 'QTY')), 2) ?></td>
                                        <td>-</td>
                                        <td id="totalAmount">₹<?= number_format($total_amount, 2) ?></td>
                                        <td id="totalVolume"><?= number_format($total_volume, 0) ?> ml</td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <!-- Volume Warning -->
                        <div class="alert alert-warning mt-3 <?= $total_volume > $category_limits['IMFL'] ? '' : 'd-none' ?>" id="volumeWarning">
                            <i class="fa-solid fa-exclamation-triangle me-2"></i>
                            <strong>Volume Limit Warning!</strong> 
                            Total volume (<span id="warningVolume"><?= number_format($total_volume, 0) ?></span> ml) exceeds IMFL limit (<?= $category_limits['IMFL'] ?> ml). 
                            This bill will be automatically split into multiple bills when saved.
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fa-solid fa-save me-2"></i>Update Bill
                            </button>
                            <a href="retail_sale.php" class="btn btn-secondary btn-lg">Cancel</a>
                            
                            <div class="form-check mt-3">
                                <input class="form-check-input" type="checkbox" id="forceUpdate">
                                <label class="form-check-label text-muted" for="forceUpdate">
                                    Force update even if volume exceeds limits (may cause data issues)
                                </label>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php include 'components/footer.php'; ?>
    </div>
</div>

<!-- Loading Overlay -->
<div class="loading-overlay d-none" id="loadingOverlay">
    <div class="loading-spinner">
        <div class="spinner-border text-primary mb-3" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h6>Updating Bill...</h6>
        <p class="text-muted small mb-0">Please wait while we process your changes</p>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    let categoryLimits = <?= json_encode($category_limits) ?>;
    let itemCounter = <?= count($items) ?>;
    let allItems = <?= json_encode($all_items) ?>;
    
    // Initialize Select2 for all item selects
    function initializeSelect2(selector) {
        $(selector).select2({
            theme: 'bootstrap-5',
            placeholder: "Type to search items...",
            allowClear: true,
            templateResult: formatItem,
            templateSelection: formatItemSelection,
            dropdownParent: $(selector).closest('tr')
        });
    }

    // Initialize existing select2 elements
    $('.select2-item').each(function() {
        initializeSelect2(this);
    });
    
    // Format how items appear in the dropdown
    function formatItem(item) {
        if (!item.id) {
            return item.text;
        }
        
        var $element = $(item.element);
        var size = $element.data('size') || '';
        var itemClass = $element.data('class') || '';
        var rate = $element.data('rate') || 0;
        
        var $item = $(
            '<div class="item-option">' +
                '<div class="fw-semibold">' + item.text + '</div>' +
                '<div class="small text-muted">' +
                    '<span class="me-3">Size: ' + size + '</span>' +
                    '<span class="me-3">Class: ' + itemClass + '</span>' +
                    '<span>Rate: ₹' + parseFloat(rate).toFixed(2) + '</span>' +
                '</div>' +
            '</div>'
        );
        return $item;
    }
    
    // Format how the selected item appears
    function formatItemSelection(item) {
        if (!item.id) {
            return item.text;
        }
        // Show just the item name in the selection
        return item.text.split(' (₹')[0];
    }
    
    // Initialize item sizes data
    let itemSizes = {};
    <?php 
    foreach($all_items as $item) {
        $size = getItemSize($conn, $item['CODE'], $header['LIQ_FLAG']);
        echo "itemSizes['{$item['CODE']}'] = {$size};\n";
    }
    ?>

    // Add new item row
    $('#addItemBtn').on('click', function() {
        const newIndex = itemCounter++;
        const newRow = `
            <tr data-item-code="" data-size="0">
                <td>
                    <select class="form-control form-control-sm item-select select2-item" name="items[${newIndex}][code]" required>
                        <option value="">Select Item</option>
                        ${allItems.map(item => `
                            <option value="${item.CODE}" 
                                    data-rate="${item.RPRICE}"
                                    data-details="${item.DETAILS2 || ''}"
                                    data-size="${item.DETAILS2 || ''}"
                                    data-class="${item.CLASS || ''}">
                                ${item.CODE} - ${item.DETAILS}
                            </option>
                        `).join('')}
                    </select>
                    <small class="item-details text-muted"></small>
                </td>
                <td>
                    <span class="badge bg-info volume-badge item-size">0 ml</span>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm quantity" 
                           name="items[${newIndex}][qty]" 
                           value="1" step="0.01" min="0.01" required>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm rate" 
                           name="items[${newIndex}][rate]" 
                           value="0" step="0.01" min="0" required>
                </td>
                <td>
                    <input type="number" class="form-control form-control-sm amount" value="0" readonly>
                </td>
                <td>
                    <span class="badge bg-secondary volume-badge item-volume">0 ml</span>
                </td>
                <td>
                    <button type="button" class="btn btn-danger btn-sm remove-item">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            </tr>
        `;
        $('#itemsTable').append(newRow);
        
        // Initialize Select2 for the new row
        initializeSelect2($('#itemsTable tr:last .select2-item'));
        
        updateItemCount();
    });

    // Handle item selection change
    $(document).on('change', '.item-select', function() {
        const row = $(this).closest('tr');
        const selectedOption = $(this).find('option:selected');
        const itemCode = selectedOption.val();
        const defaultRate = selectedOption.data('rate');
        const itemDetails = selectedOption.data('details');
        const itemSize = selectedOption.data('size');
        
        if (itemCode) {
            // Update row data
            row.attr('data-item-code', itemCode);
            
            // Update size
            const size = itemSizes[itemCode] || 0;
            row.attr('data-size', size);
            row.find('.item-size').text(size + ' ml');
            
            // Update rate with default rate
            if (defaultRate > 0) {
                row.find('.rate').val(defaultRate);
            }
            
            // Update item details
            row.find('.item-details').text(itemDetails || itemSize || '');
            
            // Recalculate row
            calculateRowAmount(row);
            calculateTotals();
            checkVolumeLimits();
        } else {
            // Clear row data if no item selected
            row.attr('data-item-code', '').attr('data-size', '0');
            row.find('.item-size').text('0 ml');
            row.find('.item-details').text('');
            row.find('.rate').val('0');
            row.find('.amount').val('0');
            row.find('.item-volume').text('0 ml');
            calculateTotals();
            checkVolumeLimits();
        }
    });

    // Calculate amount when quantity or rate changes
    $(document).on('input', '.quantity, .rate', function() {
        const row = $(this).closest('tr');
        calculateRowAmount(row);
        calculateTotals();
        checkVolumeLimits();
    });

    // Calculate row amount and volume
    function calculateRowAmount(row) {
        const qty = parseFloat(row.find('.quantity').val()) || 0;
        const rate = parseFloat(row.find('.rate').val()) || 0;
        const amount = qty * rate;
        const size = parseFloat(row.attr('data-size')) || 0;
        const volume = qty * size;
        
        row.find('.amount').val(amount.toFixed(2));
        row.find('.item-volume').text(volume.toFixed(0) + ' ml');
    }

    // Remove item row
    $(document).on('click', '.remove-item', function() {
        if ($('#itemsTable tr').length > 1) {
            $(this).closest('tr').remove();
            calculateTotals();
            checkVolumeLimits();
            updateItemCount();
        } else {
            alert('At least one item is required in the bill.');
        }
    });

    // Calculate totals
    function calculateTotals() {
        let totalQty = 0;
        let totalAmount = 0;
        let totalVolume = 0;
        
        $('#itemsTable tr').each(function() {
            const qty = parseFloat($(this).find('.quantity').val()) || 0;
            const amount = parseFloat($(this).find('.amount').val()) || 0;
            const volume = parseFloat($(this).find('.item-volume').text().replace(' ml', '')) || 0;
            
            totalQty += qty;
            totalAmount += amount;
            totalVolume += volume;
        });
        
        $('#totalQty').text(totalQty.toFixed(2));
        $('#totalAmount').text('₹' + totalAmount.toFixed(2));
        $('#displayTotalAmount').val('₹' + totalAmount.toFixed(2));
        $('#totalVolume').text(totalVolume.toFixed(0) + ' ml');
    }

    // Update item count badge
    function updateItemCount() {
        const count = $('#itemsTable tr').length;
        $('#itemCount').text(count + ' items');
    }

    // Check volume limits
    function checkVolumeLimits() {
        const totalVolume = parseFloat($('#totalVolume').text().replace(' ml', '')) || 0;
        const limit = categoryLimits.IMFL;
        
        if (totalVolume > limit) {
            $('#volumeWarning').removeClass('d-none');
            $('#warningVolume').text(totalVolume.toFixed(0));
        } else {
            $('#volumeWarning').addClass('d-none');
        }
    }

    // Handle form submission
    $('#editBillForm').on('submit', function(e) {
        e.preventDefault();
        
        // Validate form
        let valid = true;
        let hasItems = false;
        
        $('.item-select').each(function() {
            if (!$(this).val()) {
                valid = false;
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
                hasItems = true;
            }
        });
        
        $('.quantity, .rate').each(function() {
            if (!$(this).val() || parseFloat($(this).val()) <= 0) {
                valid = false;
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
        });
        
        if (!valid) {
            alert('Please select valid items and enter positive values for all quantities and rates.');
            return;
        }
        
        if (!hasItems) {
            alert('Please add at least one item to the bill.');
            return;
        }

        // Show loading overlay
        $('#loadingOverlay').removeClass('d-none');

        // Prepare FormData for POST
        const formData = new FormData();
        formData.append('bill_no', $('input[name="bill_no"]').val());
        formData.append('bill_date', $('input[name="bill_date"]').val());
        formData.append('force_update', $('#forceUpdate').is(':checked') ? '1' : '0');

        // Add items as array
        $('#itemsTable tr').each(function(index) {
            const code = $(this).find('.item-select').val();
            const qty = parseFloat($(this).find('.quantity').val());
            const rate = parseFloat($(this).find('.rate').val());
            const name = $(this).find('.item-select option:selected').text();
            
            if (code && !isNaN(qty) && !isNaN(rate)) {
                formData.append(`items[${index}][code]`, code);
                formData.append(`items[${index}][qty]`, qty);
                formData.append(`items[${index}][rate]`, rate);
                formData.append(`items[${index}][name]`, name);
            }
        });

        console.log('Sending FormData with items:', $('#itemsTable tr').length);

        // Send as regular POST data - FIXED FILENAME
        fetch('edit_bills.php', {
            method: 'POST',
            body: formData  // No Content-Type header needed for FormData
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            // Hide loading overlay
            $('#loadingOverlay').addClass('d-none');
            
            if (data.success) {
                // Show success message
                const successMsg = data.message || 'Bill updated successfully!';
                if (data.new_bills && data.new_bills.length > 0) {
                    alert('Success: ' + successMsg + '\nNew bills created: ' + data.new_bills.join(', '));
                } else {
                    alert('Success: ' + successMsg);
                }
                window.location.href = 'retail_sale.php?success=' + encodeURIComponent(successMsg);
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            // Hide loading overlay
            $('#loadingOverlay').addClass('d-none');
            console.error('Fetch error:', error);
            alert('Network error: ' + error.message);
        });
    });

    // Set focus on quantity field after item selection
    $(document).on('select2:select', '.item-select', function() {
        $(this).closest('tr').find('.quantity').focus().select();
    });

    // Initial calculations
    calculateTotals();
    checkVolumeLimits();
    updateItemCount();
});
</script>
</body>
</html>
