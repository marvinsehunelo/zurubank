<?php
// /opt/lampp/htdocs/zurubank/Backend/api/v1/transaction/reverse.php
header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';

$client = validate_api_key();
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['original_trace']) || !isset($data['reason'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    // First, find the original transaction
    $findStmt = $pdo->prepare("
        SELECT t.*, a.account_number, a.user_id 
        FROM transactions t
        JOIN accounts a ON t.account_id = a.account_id
        WHERE t.trace_number = ?
    ");
    $findStmt->execute([$data['original_trace']]);
    $original = $findStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$original) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Original transaction not found']);
        exit;
    }
    
    // Check if already reversed
    $checkStmt = $pdo->prepare("SELECT id FROM transaction_reversals WHERE original_trace = ?");
    $checkStmt->execute([$data['original_trace']]);
    if ($checkStmt->fetch()) {
        echo json_encode([
            'status' => 'duplicate',
            'message' => 'Transaction already reversed'
        ]);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Reverse the amount (credit back)
    if ($original['type'] === 'deposit' || $original['type'] === 'credit') {
        // For deposits, we need to debit the account
        $updateStmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?");
        $updateStmt->execute([$original['amount'], $original['account_id']]);
    } else {
        // For debits/withdrawals, credit back
        $updateStmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
        $updateStmt->execute([$original['amount'], $original['account_id']]);
    }
    
    // Create reversal record
    $reversal_trace = 'REV-' . time() . rand(1000, 9999);
    $revStmt = $pdo->prepare("
        INSERT INTO transaction_reversals (original_trace, reversal_trace, reason, reversed_at) 
        VALUES (?, ?, ?, NOW())
    ");
    $revStmt->execute([$data['original_trace'], $reversal_trace, $data['reason']]);
    
    // Update original transaction status
    $updateTransStmt = $pdo->prepare("UPDATE transactions SET status = 'reversed' WHERE trace_number = ?");
    $updateTransStmt->execute([$data['original_trace']]);
    
    $pdo->commit();
    
    echo json_encode([
        'status' => 'success',
        'reversal_trace' => $reversal_trace,
        'amount' => $original['amount'],
        'reversed_at' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Reverse Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Reversal failed: ' . $e->getMessage()
    ]);
}
