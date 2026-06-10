<?php
/**
 * ZURUBANK Direct Deposit - Compatible with SwapService
 * ADDED: Cryptographic signature verification
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../helpers/crypto.php';  // Add signature functions

$input = json_decode(file_get_contents("php://input"), true);

error_log("=== ZURUBANK DEPOSIT ENDPOINT ===");
error_log("Input: " . json_encode($input));

// ============================================================
// VERIFY SIGNATURE FROM REQUESTER (VouchMorph or Source Bank)
// ============================================================
$signature = $input['signature'] ?? null;
$timestamp = $input['timestamp'] ?? null;
$idempotencyKey = $input['idempotency_key'] ?? $input['idempotencyKey'] ?? null;

$payloadToVerify = [
    'reference' => $input['reference'] ?? null,
    'source_institution' => $input['source_institution'] ?? null,
    'destination_account' => $input['destination_account'] ?? null,
    'amount' => $input['amount'] ?? null,
    'currency' => $input['currency'] ?? 'BWP',
    'idempotency_key' => $idempotencyKey
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("ZURUBANK DEPOSIT: Missing signature");
    echo json_encode([
        'processed' => false,
        'message' => 'Missing signature - deposit requests must be signed',
        'timestamp' => time()
    ]);
    exit;
}

// Determine who is requesting
$requester = $input['requester'] ?? 'VOUCHMORPH';
$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("ZURUBANK DEPOSIT: No public key for requester: {$requester}");
    echo json_encode([
        'processed' => false,
        'message' => "No public key found for requester: {$requester}",
        'timestamp' => time()
    ]);
    exit;
}

// Verify signature
$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("ZURUBANK DEPOSIT: Invalid signature from {$requester}");
    echo json_encode([
        'processed' => false,
        'message' => 'Invalid signature - deposit request cannot be trusted',
        'timestamp' => time()
    ]);
    exit;
}

error_log("ZURUBANK DEPOSIT: Signature verified from {$requester}");

// Check idempotency to prevent replay attacks
if ($idempotencyKey) {
    $checkStmt = $pdo->prepare("
        SELECT id FROM processed_deposits 
        WHERE idempotency_key = :key AND processed_at > NOW() - INTERVAL '24 hours'
        LIMIT 1
    ");
    $checkStmt->execute(['key' => $idempotencyKey]);
    
    if ($checkStmt->fetch()) {
        error_log("ZURUBANK DEPOSIT: Duplicate request prevented (idempotency key: {$idempotencyKey})");
        echo json_encode([
            'processed' => true,
            'duplicate' => true,
            'message' => 'Duplicate request - already processed',
            'timestamp' => time()
        ]);
        exit;
    }
}

try {
    // Map SwapService fields to internal fields
    $reference = $input['reference'] ?? uniqid('DEP-');
    $sourceInstitution = $input['source_institution'] ?? 'UNKNOWN';
    $sourceHoldReference = $input['source_hold_reference'] ?? null;
    $destinationAccount = $input['destination_account'] ?? null;
    $amount = (float)($input['amount'] ?? 0);
    $action = $input['action'] ?? 'PROCESS_DEPOSIT';
    $currency = $input['currency'] ?? 'BWP';

    if (!$destinationAccount || $amount <= 0) {
        throw new Exception("destination_account and valid amount are required");
    }

    $pdo->beginTransaction();

    // Lock and get account
    $stmt = $pdo->prepare("
        SELECT account_id, user_id, balance, currency 
        FROM accounts 
        WHERE account_number = ? 
        FOR UPDATE
    ");
    $stmt->execute([$destinationAccount]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception("Account not found: $destinationAccount");
    }

    // Check currency match
    if ($account['currency'] !== $currency) {
        throw new Exception("Currency mismatch. Account: {$account['currency']}, Requested: $currency");
    }

    // Record old balance for audit
    $oldBalance = $account['balance'];
    $newBalance = $oldBalance + $amount;

    // Update balance
    $pdo->prepare("
        UPDATE accounts 
        SET balance = balance + ?
        WHERE account_number = ?
    ")->execute([$amount, $destinationAccount]);

    // Generate trace number
    $trace = 'DEP' . time() . rand(100, 999);

    // Record transaction with signature info
    $pdo->prepare("
        INSERT INTO transactions
        (user_id, account_id, from_account, to_account,
         type, amount, reference, trace_number, status, 
         requester, signature_verified, created_at)
        VALUES (?, ?, ?, ?, 'deposit', ?, ?, ?, 'completed', ?, ?, NOW())
    ")->execute([
        $account['user_id'],
        $account['account_id'],
        $sourceInstitution,
        $destinationAccount,
        $amount,
        $reference,
        $trace,
        $requester,
        true  // signature_verified
    ]);

    // Store idempotency with signature info
    if ($idempotencyKey) {
        $pdo->prepare("
            INSERT INTO processed_deposits
            (deposit_ref, account_number, amount, idempotency_key, 
             requester, signature_verified, processed_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON CONFLICT (idempotency_key) DO NOTHING
        ")->execute([$trace, $destinationAccount, $amount, $idempotencyKey, $requester, true]);
    }

    // Audit log
    $pdo->prepare("
        INSERT INTO audit_logs 
        (entity, entity_id, action, category, severity, performed_by, 
         old_value, new_value, metadata, performed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        'accounts',
        $account['account_id'],
        'DEPOSIT',
        'financial',
        'info',
        $requester,
        json_encode(['balance' => $oldBalance]),
        json_encode(['balance' => $newBalance, 'amount' => $amount]),
        json_encode([
            'signature_verified' => true,
            'timestamp' => $timestamp,
            'reference' => $reference,
            'trace' => $trace
        ])
    ]);

    $pdo->commit();

    // Get new balance
    $newBalance = $pdo->query("
        SELECT balance FROM accounts 
        WHERE account_number = '$destinationAccount'
    ")->fetchColumn();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'processed' => true,
        'transaction_id' => $trace,
        'reference' => $reference,
        'amount' => $amount,
        'currency' => $currency,
        'new_balance' => $newBalance,
        'old_balance' => $oldBalance,
        'requester' => $requester,
        'message' => 'Deposit processed successfully'
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("ZURUBANK DEPOSIT error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    http_response_code(400);
    echo json_encode([
        'processed' => false,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}

/**
 * Get public key for a requester
 */
function get_requester_public_key($requester, $pdo) {
    $stmt = $pdo->prepare("
        SELECT public_key FROM trusted_partners 
        WHERE name = :requester AND is_active = true
        LIMIT 1
    ");
    $stmt->execute(['requester' => $requester]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['public_key'])) {
        return $result['public_key'];
    }
    
    $envKey = strtoupper($requester) . '_PUBLIC_KEY';
    return getenv($envKey) ?: null;
}
