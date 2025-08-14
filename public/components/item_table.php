<table class="item-table">
    <thead>
        <tr>
            <th>Item Code</th>
            <th>Item Description</th>
            <th>Class</th>
            <th>Actions</th>
        </tr>
    </thead>
    <tbody id="itemTableBody">
        <?php if(!empty($items)): ?>
            <?php foreach($items as $item): ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['CODE']); ?></td>
                    <td><?php echo htmlspecialchars($item['DETAILS']); ?></td>
                    <td><?php echo htmlspecialchars($item['CLASS']); ?></td>
                    <td>
                        <button class="edit-btn">Edit</button>
                        <button class="delete-btn">Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="4">No items found.</td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>
