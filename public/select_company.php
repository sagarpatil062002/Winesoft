<?php
session_start();
require '../config/db.php';

if(!isset($_SESSION['user_id'])){
    header("Location: index.php");
    exit;
}

$companyResult = mysqli_query($conn, "SELECT CompID, COMP_NAME FROM tblCompany ORDER BY COMP_NAME");
$yearResult = mysqli_query($conn, "SELECT DISTINCT FIN_YEAR FROM tblCompany ORDER BY FIN_YEAR DESC");

if(isset($_POST['submit'])){
    $_SESSION['CompID'] = $_POST['company'];
    $_SESSION['FIN_YEAR'] = $_POST['financial_year'];
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2B6CB0;
            --primary-hover: #4299E1;
            --secondary-color: #F6AD55;
            --background-color: #F7FAFC;
            --text-color: #2D3748;
            --light-text: #718096;
            --error-color: #E53E3E;
            --white: #FFFFFF;
            --border-radius: 6px;
            --box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            --transition: all 0.2s ease;
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            background-color: var(--background-color);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .selection-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 500px;
            padding: 2.5rem;
            transition: transform 0.3s ease;
        }
        
        .selection-card:hover {
            transform: translateY(-5px);
        }
        
        .selection-card h2 {
            color: var(--primary-color);
            margin-bottom: 1.5rem;
            text-align: center;
            font-size: 1.5rem;
        }
        
        .selection-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .form-group label {
            color: var(--text-color);
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .form-select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
            background-color: var(--white);
            transition: var(--transition);
        }
        
        .form-select:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 2px rgba(43, 108, 176, 0.2);
        }
        
        .btn-submit {
            background-color: var(--primary-color);
            color: var(--white);
            border: none;
            padding: 0.75rem;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 0.5rem;
        }
        
        .btn-submit:hover {
            background-color: var(--primary-hover);
        }
        
        @media (max-width: 480px) {
            .selection-card {
                padding: 1.5rem;
            }
            
            .selection-card h2 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>
<body>
    <div class="selection-card">
        <h2>Select Company and Financial Year</h2>
        
        <form class="selection-form" method="post" action="">
            <div class="form-group">
                <label for="company">Company</label>
                <select name="company" id="company" class="form-select" required>
                    <option value="">-- Select Company --</option>
                    <?php while($company = mysqli_fetch_assoc($companyResult)) { ?>
                        <option value="<?= $company['CompID'] ?>"><?= $company['COMP_NAME'] ?></option>
                    <?php } ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="financial_year">Financial Year</label>
                <select name="financial_year" id="financial_year" class="form-select" required>
                    <option value="">-- Select Financial Year --</option>
                    <?php while($year = mysqli_fetch_assoc($yearResult)) { ?>
                        <option value="<?= $year['FIN_YEAR'] ?>"><?= $year['FIN_YEAR'] ?></option>
                    <?php } ?>
                </select>
            </div>
            
            <button type="submit" name="submit" class="btn-submit">Continue</button>
        </form>
    </div>
</body>
</html>