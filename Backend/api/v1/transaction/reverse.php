<?php
// /opt/lampp/htdocs/zurubank/Backend/api/v1/transaction/reverse.php

header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

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
    $lockStmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_id = ? FOR UPDATE");
    $lockStmt->execute([$original['account_id']]);
    $currentBalance = $lockStmt->fetchColumn();
    
    // Reverse the amount (credit back or debit based on original type)
    $newBalance = $currentBalance;
    
    if ($original['type'] === 'deposit' || $original['type'] === 'credit') {
        // For deposits/credits, we need to debit the account
        $updateStmt = $pdo->prepare("UPDATE accounts SET balance = balance - ?, updated_at = NOW() WHERE account_id = ?");
        $updateStmt->execute([$original['amount'], $original['account_id']]);
        $newBalance = $currentBalance - $original['amount'];
        error_log("ZURUBANK REVERSE: Debited {$original['amount']} from account {$original['account_id']}");
    } else {
        // For debits/withdrawals, credit back
        $updateStmt = $pdo->prepare("UPDATE accounts SET balance = balance + ?, updated_at = NOW() WHERE account_id = ?");
        $updateStmt->execute([$original['amount'], $original['account_id']]);
        $newBalance = $currentBalance + $original['amount'];
        error_log("ZURUBANK REVERSE: Credited {$original['amount']} to account {$original['account_id']}");
    }
    
    // Create reversal record
    $reversal_trace = 'REV-' . time() . '-' . rand(1000, 9999);
    $revStmt = $pdo->prepare("
        INSERT INTO transaction_reversals (
            original_trace, 
            reversal_trace, 
            reason, 
            reversed_by, 
            reversed_by_cert_verified,
            verification_method,
            amount,
            reversed_at,
            created_at
        ) VALUES (
            :original_trace, 
            :reversal_trace, 
            :reason, 
            :reversed_by, 
            :cert_verified,
            :verification_method,
            :amount,
            NOW(),
            NOW()
        )
    ");
    $revStmt->execute([
        'original_trace' => $data['original_trace'],
        'reversal_trace' => $reversal_trace,
        'reason' => $data['reason'],
        'reversed_by' => $requester,
        'cert_verified' => $isValid ? 1 : 0,
        'verification_method' => 'certificate',
        'amount' => $original['amount']
    ]);
    
    // Update original transaction status
    $updateTransStmt = $pdo->prepare("
        UPDATE transactions 
        SET status = 'reversed', 
            reversed_at = NOW(),
            reversed_by = :reversed_by,
            reversal_reason = :reason,
            reversal_trace = :reversal_trace,
            updated_at = NOW()
        WHERE trace_number = :trace_number OR reference = :trace_number
    ");
    $updateTransStmt->execute([
        'trace_number' => $data['original_trace'],
        'reversed_by' => $requester,
        'reason' => $data['reason'],
        'reversal_trace' => $reversal_trace
    ]);
    
    // Create audit log entry
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs (
            entity_type,
            entity_id,
            action,
            old_value,
            new_value,
            performed_by,
            signature_verified,
            verification_method,
            created_at
        ) VALUES (
            'transaction',
            :trace,
            'REVERSE',
            :old_value,
            :new_value,
            :performed_by,
            :sig_verified,
            :verification_method,
            NOW()
        )
    ");
    $auditStmt->execute([
        'trace' => $data['original_trace'],
        'old_value' => json_encode(['status' => $original['status'], 'balance' => $currentBalance]),
        'new_value' => json_encode(['status' => 'reversed', 'balance' => $newBalance]),
        'performed_by' => $requester,
        'sig_verified' => $isValid ? 1 : 0,
        'verification_method' => 'certificate'
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
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Reversal failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
