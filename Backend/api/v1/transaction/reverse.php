<?php
// /opt/lampp/htdocs/zurubank/Backend/api/v1/transaction/reverse.php

header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';
require_once __DIR__ . '/../../../lib/account_holds.php';
require_once __DIR__ . '/../../../lib/audit_log.php';

// Validate API key first
$client = validate_api_key();

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['original_trace']) || !isset($data['reason'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields: original_trace, reason']);
    exit;
}

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($data['certificate'])) {
    error_log("ZURUBANK REVERSE: No certificate provided");
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Certificate required - please upgrade to certificate-based authentication'
    ]);
    exit;
}

$certManager = new CertificateManager('ZURUBANK');
$verification = $certManager->verifySignedRequest($data);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("ZURUBANK REVERSE: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("ZURUBANK REVERSE: Requester: {$requester}");

if (!$isValid) {
    error_log("ZURUBANK REVERSE: Certificate verification failed");
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("ZURUBANK REVERSE: Request verified from {$requester} using certificate");

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    // First, find the original transaction
    $findStmt = $pdo->prepare("
        SELECT t.*, a.account_number, a.user_id, a.currency, a.account_id
        FROM transactions t
        JOIN accounts a ON t.account_id = a.account_id
        WHERE t.trace_number = :trace_number OR t.reference = :trace_number
    ");
    $findStmt->execute(['trace_number' => $data['original_trace']]);
    $original = $findStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$original) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Original transaction not found: ' . $data['original_trace']
        ]);
        exit;
    }
    
    error_log("ZURUBANK REVERSE: Found original transaction - Type: {$original['type']}, Amount: {$original['amount']}, Status: {$original['status']}");
    
    // Check if already reversed
    $checkStmt = $pdo->prepare("
        SELECT id FROM transaction_reversals 
        WHERE original_trace = :trace OR original_trace = :reference
    ");
    $checkStmt->execute([
        'trace' => $data['original_trace'],
        'reference' => $data['original_trace']
    ]);
    if ($checkStmt->fetch()) {
        echo json_encode([
            'status' => 'duplicate',
            'message' => 'Transaction already reversed',
            'original_trace' => $data['original_trace']
        ]);
        exit;
    }
    
    // Check if transaction is already in a terminal state
    if (in_array($original['status'], ['reversed', 'failed', 'cancelled'])) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Transaction cannot be reversed - status: ' . $original['status']
        ]);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Lock account for update
    // SELECT *: before the held-balance migration there is no held_balance (and nothing held).
    $lockStmt = $pdo->prepare("SELECT * FROM accounts WHERE account_id = ? FOR UPDATE");
    $lockStmt->execute([$original['account_id']]);
    $lockedAccount = $lockStmt->fetch(PDO::FETCH_ASSOC);
    $currentBalance = $lockedAccount['balance'];
    
    // Reverse the amount (credit back or debit based on original type)
    $newBalance = $currentBalance;
    
    if ($original['type'] === 'deposit' || $original['type'] === 'credit') {
        // Taking a credit back must not spend money that is on hold: held
        // money stays in balance but belongs to the hold (see
        // Backend/lib/account_holds.php). Refuse plainly instead of letting
        // the database CHECK fail the request with a 500.
        $heldNow = $lockedAccount['held_balance'] ?? 0;
        $available = hold_available($lockedAccount['balance'], $heldNow);
        if ((float)$heldNow > 0 && $available < hold_money($original['amount'])) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'status' => 'error',
                'message' => 'Reversal refused: ' . HOLD_SPEND_REFUSED,
                'original_trace' => $data['original_trace'],
                'amount' => (float)$original['amount'],
                'available_balance' => $available,
                'held_balance' => hold_money($heldNow),
                'timestamp' => time()
            ]);
            exit;
        }
        // For deposits/credits, we need to debit the account
        $updateStmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?");
        $updateStmt->execute([$original['amount'], $original['account_id']]);
        $newBalance = $currentBalance - $original['amount'];
        error_log("ZURUBANK REVERSE: Debited {$original['amount']} from account {$original['account_id']}");
    } else {
        // For debits/withdrawals, credit back
        $updateStmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
        $updateStmt->execute([$original['amount'], $original['account_id']]);
        $newBalance = $currentBalance + $original['amount'];
        error_log("ZURUBANK REVERSE: Credited {$original['amount']} to account {$original['account_id']}");
    }
    
    // Only columns the live schema has (backup.sql): transaction_reversals is
    // (original_trace, reversal_trace, reason, reversed_at), and accounts and
    // transactions have no updated_at or reversal columns. Who reversed it and
    // how it was verified go into the transaction's description and the audit row.
    $reversal_trace = 'REV-' . time() . '-' . rand(1000, 9999);
    $revStmt = $pdo->prepare("
        INSERT INTO transaction_reversals (original_trace, reversal_trace, reason, reversed_at)
        VALUES (:original_trace, :reversal_trace, :reason, NOW())
    ");
    $revStmt->execute([
        'original_trace' => $data['original_trace'],
        'reversal_trace' => $reversal_trace,
        'reason' => $data['reason'],
    ]);
    
    // Update original transaction status
    $updateTransStmt = $pdo->prepare("
        UPDATE transactions 
        SET status = 'reversed', 
            description = COALESCE(description, '') || :note
        WHERE trace_number = :trace_number OR reference = :trace_number
    ");
    $updateTransStmt->execute([
        'trace_number' => $data['original_trace'],
        'note' => ' | Reversed ' . date('Y-m-d H:i:s') . ' by ' . $requester . ' (' . $reversal_trace . '): '
            . substr((string)$data['reason'], 0, 200),
    ]);
    
    // Audit row; never able to undo the reversal (Backend/lib/audit_log.php).
    audit_log_write($pdo, [
        'entity' => 'transactions',
        'entity_id' => $original['transaction_id'],
        'action' => 'REVERSE',
        'performed_by' => $requester,
        'old_value' => ['status' => $original['status'], 'balance' => $currentBalance, 'trace' => $data['original_trace']],
        'new_value' => ['status' => 'reversed', 'balance' => $newBalance, 'reversal_trace' => $reversal_trace,
                        'signature_verified' => $isValid, 'verification_method' => 'certificate'],
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
    ]);
    
    $pdo->commit();
    
    error_log("ZURUBANK REVERSE: Reversal completed - Original: {$data['original_trace']}, Reversal: {$reversal_trace}");
    
    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'reversal_trace' => $reversal_trace,
        'original_trace' => $data['original_trace'],
        'amount' => (float)$original['amount'],
        'currency' => $original['currency'] ?? 'BWP',
        'new_balance' => (float)$newBalance,
        'reversed_at' => date('Y-m-d H:i:s'),
        'reversed_by' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate',
        'timestamp' => time()
    ];
    
    // Send signed response (proves Zurubank executed the reversal)
    send_signed_response($responsePayload);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ZURUBANK REVERSE ERROR: " . $e->getMessage());
    error_log("ZURUBANK REVERSE Trace: " . $e->getTraceAsString());
    error_log("ZURUBANK REVERSE Input: " . json_encode($data ?? []));
    
    // The held-money CHECK is a refusal, not a server fault.
    http_response_code(hold_is_held_money_refusal($e) ? 409 : 500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Reversal failed: ' . hold_refusal_message($e),
        'timestamp' => time()
    ]);
}
