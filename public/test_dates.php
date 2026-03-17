<?php
require_once 'components/financial_year_auto.php';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Date Fields</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h3>Test Date Fields - All Naming Conventions</h3>
        
        <!-- Convention 1: start_date / end_date -->
        <div class="card mb-3">
            <div class="card-header">Convention 1: start_date / end_date</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <label>Start Date:</label>
                        <input type="date" name="start_date" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>End Date:</label>
                        <input type="date" name="end_date" class="form-control">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Convention 2: from_date / to_date -->
        <div class="card mb-3">
            <div class="card-header">Convention 2: from_date / to_date</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <label>From Date:</label>
                        <input type="date" name="from_date" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>To Date:</label>
                        <input type="date" name="to_date" class="form-control">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Convention 3: StartDate / EndDate -->
        <div class="card mb-3">
            <div class="card-header">Convention 3: StartDate / EndDate</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <label>StartDate:</label>
                        <input type="date" name="StartDate" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>EndDate:</label>
                        <input type="date" name="EndDate" class="form-control">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Convention 4: FromDate / ToDate -->
        <div class="card mb-3">
            <div class="card-header">Convention 4: FromDate / ToDate</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <label>FromDate:</label>
                        <input type="date" name="FromDate" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>ToDate:</label>
                        <input type="date" name="ToDate" class="form-control">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Mixed convention in same row -->
        <div class="card mb-3">
            <div class="card-header">Mixed Convention</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <label>From:</label>
                        <input type="date" name="from" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>To:</label>
                        <input type="date" name="to" class="form-control">
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php require_once 'components/financial_year_footer.php'; ?>
</body>
</html>