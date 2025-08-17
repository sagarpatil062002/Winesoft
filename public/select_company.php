<?php
session_start();
require '../config/db.php';

// Ensure user is logged in
if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit;
}

// Fetch companies and financial years
$companyResult = mysqli_query($conn, "SELECT CompID, COMP_NAME FROM tblCompany ORDER BY COMP_NAME");
$yearResult = mysqli_query($conn, "SELECT DISTINCT FIN_YEAR FROM tblCompany ORDER BY FIN_YEAR DESC");

// Handle form submission
if(isset($_POST['submit'])){
    $_SESSION['CompID'] = $_POST['company'];       // Company ID
    $_SESSION['FIN_YEAR'] = $_POST['financial_year']; // Financial Year

    // Optional: store human-readable company name for easy access
    $compRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COMP_NAME FROM tblCompany WHERE CompID = ".$_SESSION['CompID']));
    $_SESSION['COMP_NAME'] = $compRow['COMP_NAME'];

    header("Location: dashboard.php");
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Company & Financial Year</title>
<!-- Add version parameter to force cache refresh -->
<link rel="stylesheet" href="css/style.css?v=<?=time()?>">
<link rel="stylesheet" href="css/navbar.css?v=<?=time()?>"></head>
<body>

<div class="container">
    <h2>Select Company and Financial Year</h2>

    <form method="post" action="">
        <label for="company">Company:</label>
        <select name="company" id="company" required>
            <option value="">-- Select Company --</option>
            <?php while($company = mysqli_fetch_assoc($companyResult)) { ?>
                <option value="<?= $company['CompID'] ?>"><?= $company['COMP_NAME'] ?></option>
            <?php } ?>
        </select>
        <br><br>

        <label for="financial_year">Financial Year:</label>
        <select name="financial_year" id="financial_year" required>
            <option value="">-- Select Financial Year --</option>
            <?php while($year = mysqli_fetch_assoc($yearResult)) { ?>
                <option value="<?= $year['FIN_YEAR'] ?>"><?= $year['FIN_YEAR'] ?></option>
            <?php } ?>
        </select>
        <br><br>

        <button type="submit" name="submit">Continue</button>
    </form>
</div>

</body>
</html>
