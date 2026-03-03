<?php
/**
 * ZURUBANK Direct Deposit - Compatible with SwapService
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/db.php';

$input = json_decode(file_get_contents("php://input"), true);

error_log("=== ZURUBANK DEPOSIT ENDPOINT ===");
error_log("Input: " . json_encode($input));

try {
    // Map SwapService fields to internal fields
    $reference = $input['reference'] ?? uniqid('DEP-');
    $sourceInstitution = $input['source_institution'] ?? 'UNKNOWN';
    $sourceHoldReference = $input['source_hold_reference'] ?? null;
    $destinationAccount = $input['destination_account'] ?? null;
    $amount = (float)($input['amount'] ?? 0);
    $action = $input['action'] ?? 'PROCESS_DEPOSIT';

    if (!$destinationAccount || $amount <= 0) {
        throw new Exception("destination_account and valid amount are required");
    }

    $pdo->beginTransaction();

    // Lock and get account
    $stmt = $pdo->prepare("
        SELECT account_id, user_id, balance 
        FROM accounts 
        WHERE account_number = ? 
        FOR UPDATE
    ");
    $stmt->execute([$destinationAccount]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception("Account not found: $destinationAccount");
    }

    // Update balance
    $pdo->prepare("
        UPDATE accounts 
        SET balance = balance + ?
        WHERE account_number = ?
    ")->execute([$amount, $destinationAccount]);

    // Generate trace number
    $trace = 'DEP' . time() . rand(100, 999);

    // Record transaction
    $pdo->prepare("
        INSERT INTO transactions
        (user_id, account_id, from_account, to_account,
         type, amount, reference, trace_number, status)
        VALUES (?, ?, ?, ?, 'deposit', ?, ?, ?, 'completed')
    ")->execute([
        $account['user_id'],
        $account['account_id'],
        $sourceInstitution,
        $destinationAccount,
        $amount,
        $reference,
        $trace
    ]);

    // Store idempotency if key provided
    $idemKey = $input['idempotency_key'] ?? null;
    if ($idemKey) {
        $pdo->prepare("
            INSERT INTO processed_deposits
            (deposit_ref, account_number, amount, idempotency_key)
            VALUES (?, ?, ?, ?)
            ON CONFLICT DO NOTHING
        ")->execute([$trace, $destinationAccount, $amount, $idemKey]);
    }

    $pdo->commit();

    // Get new balance
    $newBalance = $pdo->query("
        SELECT balance FROM accounts 
        WHERE account_number = '$destinationAccount'
    ")->fetchColumn();

    // Return in format SwapService expects
    echo json_encode([
        'processed' => true,
        'transaction_id' => $trace,
        'reference' => $reference,
        'amount' => $amount,
        'currency' => 'BWP',
        'new_balance' => $newBalance,
        'message' => 'Deposit processed successfully'
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Deposit error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'processed' => false,
        'message' => $e->getMessage()
    ]);
}
