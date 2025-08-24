<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
if (!isset($_SESSION['CompID'])) { header("Location: index.php"); exit; }

$companyId = $_SESSION['CompID'];
include_once "../config/db.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $purchaseId = $_POST['purchase_id'];
    $mode = $_POST['mode'];
    $date = $_POST['date'];
    $voc_no = $_POST['voc_no'];
    $inv_no = $_POST['inv_no'] ?? '';
    $inv_date = $_POST['inv_date'] ?? '';
    $tp_no = $_POST['tp_no'] ?? '';
    $tp_date = $_POST['tp_date'] ?? '';
    
    // Charges and taxes
    $cash_discount = floatval($_POST['cash_discount'] ?? 0);
    $trade_discount = floatval($_POST['trade_discount'] ?? 0);
    $octroi = floatval($_POST['octroi'] ?? 0);
    $freight = floatval($_POST['freight'] ?? 0);
    $stax_per = floatval($_POST['stax_per'] ?? 0);
    $stax_amt = floatval($_POST['stax_amt'] ?? 0);
    $tcs_per = floatval($_POST['tcs_per'] ?? 0);
    $tcs_amt = floatval($_POST['tcs_amt'] ?? 0);
    $misc_charges = floatval($_POST['misc_charges'] ?? 0);
    $total_amt = floatval($_POST['total_amt'] ?? 0);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update purchase header
        $updateQuery = "UPDATE tblpurchases SET 
            DATE = ?, VOC_NO = ?, INV_NO = ?, INV_DATE = ?, TPNO = ?, TP_DATE = ?,
            SCHDIS = ?, CASHDIS = ?, OCTROI = ?, FREIGHT = ?, 
            STAX_PER = ?, STAX_AMT = ?, TCS_PER = ?, TCS_AMT = ?, 
            MISC_CHARG = ?, TAMT = ?
            WHERE ID = ? AND CompID = ?";
        
        $updateStmt = $conn->prepare($updateQuery);
        $updateStmt->bind_param(
            "sissssddddddddddii",
            $date, $voc_no, $inv_no, $inv_date, $tp_no, $tp_date,
            $trade_discount, $cash_discount, $octroi, $freight,
            $stax_per, $stax_amt, $tcs_per, $tcs_amt,
            $misc_charges, $total_amt,
            $purchaseId, $companyId
        );
        
        if (!$updateStmt->execute()) {
            throw new Exception("Error updating purchase header: " . $updateStmt->error);
        }
        $updateStmt->close();
        
        // Handle items
        if (isset($_POST['item_id']) && is_array($_POST['item_id'])) {
            $itemIds = $_POST['item_id'];
            $itemCodes = $_POST['item_code'];
            $itemNames = $_POST['item_name'];
            $sizes = $_POST['size'];
            $cases = $_POST['cases'];
            $bottles = $_POST['bottles'];
            $caseRates = $_POST['case_rate'];
            $mrps = $_POST['mrp'];
            $amounts = $_POST['amount'];
            
            // Update existing items
            for ($i = 0; $i < count($itemIds); $i++) {
                if (!empty($itemIds[$i])) {
                    // Convert values to proper types first
                    $itemId = intval($itemIds[$i]);
                    $itemCode = $itemCodes[$i];
                    $itemName = $itemNames[$i];
                    $size = $sizes[$i];
                    $caseVal = floatval($cases[$i]);
                    $bottleVal = intval($bottles[$i]);
                    $caseRateVal = floatval($caseRates[$i]);
                    $mrpVal = floatval($mrps[$i]);
                    $amountVal = floatval($amounts[$i]);
                    
                    $updateItemQuery = "UPDATE tblpurchasedetails SET 
                        ItemCode = ?, ItemName = ?, Size = ?, Cases = ?, Bottles = ?, 
                        CaseRate = ?, MRP = ?, Amount = ?
                        WHERE DetailID = ? AND PurchaseID = ?";
                    
                    $updateItemStmt = $conn->prepare($updateItemQuery);
                    $updateItemStmt->bind_param(
                        "sssdiddiii",
                        $itemCode, $itemName, $size,
                        $caseVal, $bottleVal,
                        $caseRateVal, $mrpVal, $amountVal,
                        $itemId, $purchaseId
                    );
                    
                    if (!$updateItemStmt->execute()) {
                        throw new Exception("Error updating item: " . $updateItemStmt->error);
                    }
                    $updateItemStmt->close();
                }
            }
        }
        
        // Handle new items (if any)
        if (isset($_POST['new_item_code']) && is_array($_POST['new_item_code'])) {
            $newItemCodes = $_POST['new_item_code'];
            $newItemNames = $_POST['new_item_name'];
            $newSizes = $_POST['new_size'];
            $newCases = $_POST['new_cases'];
            $newBottles = $_POST['new_bottles'];
            $newCaseRates = $_POST['new_case_rate'];
            $newMrps = $_POST['new_mrp'];
            $newAmounts = $_POST['new_amount'];
            
            $insertItemQuery = "INSERT INTO tblpurchasedetails 
                (PurchaseID, ItemCode, ItemName, Size, Cases, Bottles, CaseRate, MRP, Amount, BottlesPerCase)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 12)";
            
            $insertItemStmt = $conn->prepare($insertItemQuery);
            
            for ($i = 0; $i < count($newItemCodes); $i++) {
                if (!empty($newItemCodes[$i])) {
                    // Convert values to proper types first
                    $newItemCode = $newItemCodes[$i];
                    $newItemName = $newItemNames[$i];
                    $newSize = $newSizes[$i];
                    $newCaseVal = floatval($newCases[$i]);
                    $newBottleVal = intval($newBottles[$i]);
                    $newCaseRateVal = floatval($newCaseRates[$i]);
                    $newMrpVal = floatval($newMrps[$i]);
                    $newAmountVal = floatval($newAmounts[$i]);
                    
                    $insertItemStmt->bind_param(
                        "isssdiddd",
                        $purchaseId, $newItemCode, $newItemName, $newSize,
                        $newCaseVal, $newBottleVal,
                        $newCaseRateVal, $newMrpVal, $newAmountVal
                    );
                    
                    if (!$insertItemStmt->execute()) {
                        throw new Exception("Error inserting new item: " . $insertItemStmt->error);
                    }
                }
            }
            $insertItemStmt->close();
        }
        
        // Commit transaction
        $conn->commit();
        
        header("Location: purchase_edit.php?id=" . $purchaseId . "&mode=" . $mode . "&success=1");
        exit;
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        error_log("Purchase update error: " . $e->getMessage());
        header("Location: purchase_edit.php?id=" . $purchaseId . "&mode=" . $mode . "&error=1");
        exit;
    }
} else {
    header("Location: purchase_module.php?mode=" . ($_GET['mode'] ?? 'F'));
    exit;
}
?>