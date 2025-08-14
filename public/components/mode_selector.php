<?php
// Get selected mode from URL or default to Foreign Liquor
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'F';

// Determine button states
$isForeign = $mode === 'F';
$isCountry = $mode === 'O';
?>

<div class="button-group" style="margin-bottom: 15px;">
    <a href="?mode=F" class="btn <?php echo $isForeign ? 'btn-purple' : 'btn-outline'; ?>">Foreign Liquor</a>
    <a href="?mode=O" class="btn <?php echo $isCountry ? 'btn-purple' : 'btn-outline'; ?>">Country Liquor</a>
</div>
