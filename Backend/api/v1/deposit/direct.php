<?php
/**
 * ZURUBANK Direct Deposit - Compatible with SwapService
 * UPDATED: Certificate-based verification (Visa/Mastercard model)
 * FIXED: Removed requester column (doesn't exist in transactions table)
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

$input = json_decode(file_get_contents("php://input"), true);

error_log("=== ZURUBANK DEPOSIT ENDPOINT ===");
error_log("Input: " . json_encode($input));

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($input['certificate'])) {
    error_log("ZURUBANK DEPOSIT: No certificate provided");
    echo json_encode([
        'processed' => false,
        'message' => 'Certificate required - please upgrade to certificate-based authentication',
        'timestamp' => time()
    ]);
    exit;
}

$certManager = new CertificateManager('ZURUBANK');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("ZURUBANK DEPOSIT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("ZURUBANK DEPOSIT: Requester: {$requester}");

if (!$isValid) {
    error_log("ZURUBANK DEPOSIT: Certificate verification failed");
    echo json_encode([
        'processed' => false,
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error'),
        'timestamp' => time()
    ]);
    exit;
}

error_log("ZURUBANK DEPOSIT: Request verified from {$requester} using certificate");

// ============================================================
// PROCESS DEPOSIT
// ============================================================

// Map SwapService fields to internal fields
$reference = $input['reference'] ?? $input['depositRef'] ?? uniqid('DEP-');
$sourceInstitution = $input['source_institution'] ?? $input['from_bank'] ?? 'UNKNOWN';
$sourceHoldReference = $input['source_hold_reference'] ?? null;
$destinationAccount = $input['destination_account'] ?? $input['account_number'] ?? null;
$amount = (float)($input['amount'] ?? 0);
$action = $input['action'] ?? 'PROCESS_DEPOSIT';
$currency = $input['currency'] ?? 'BWP';
$idempotencyKey = $input['idempotency_key'] ?? $input['idempotencyKey'] ?? null;

if (!$destinationAccount || $amount <= 0) {
    error_log("ZURUBANK DEPOSIT: Missing required fields - account: {$destinationAccount}, amount: {$amount}");
    echo json_encode([
        'processed' => false,
        'message' => 'destination_account and valid amount are required',
        'timestamp' => time()
    ]);
    exit;
}

// Check idempotency to prevent replay attacks
if ($idempotencyKey) {
    // Create idempotency table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS processed_deposits (
            id SERIAL PRIMARY KEY,
            deposit_ref VARCHAR(100) UNIQUE,
            account_number VARCHAR(50),
            amount DECIMAL(20,4),
            idempotency_key VARCHAR(255) UNIQUE NOT NULL,
            requester VARCHAR(100),
            signature_verified BOOLEAN DEFAULT FALSE,
            verification_method VARCHAR(50),
            processed_at TIMESTAMP DEFAULT NOW(),
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");
    
    $checkStmt = $pdo->prepare("
        SELECT id, deposit_ref FROM processed_deposits 
        WHERE idempotency_key = :key
        LIMIT 1
    ");
    $checkStmt->execute(['key' => $idempotencyKey]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        error_log("ZURUBANK DEPOSIT: Duplicate request prevented (idempotency key: {$idempotencyKey})");
        echo json_encode([
            'processed' => true,
            'duplicate' => true,
            'deposit_ref' => $existing['deposit_ref'],
            'message' => 'Duplicate request - already processed',
            'timestamp' => time()
        ]);
        exit;
    }
}

try {
    // Ensure accounts table has required columns
    $pdo->exec("
        ALTER TABLE accounts ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT NOW();
        ALTER TABLE accounts ADD COLUMN IF NOT EXISTS currency VARCHAR(3) DEFAULT 'BWP';
    ");

    $pdo->beginTransaction();

    // Lock and get account
    $stmt = $pdo->prepare("
        SELECT account_id, user_id, balance, currency, account_number 
        FROM accounts 
        WHERE account_number = :account_number OR account_number = :account_number_alt
        FOR UPDATE
    ");
    $stmt->execute([
        'account_number' => $destinationAccount,
        'account_number_alt' => ltrim($destinationAccount, '0')
    ]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception("Account not found: {$destinationAccount}");
    }

    // Check currency match
    if ($account['currency'] !== $currency) {
        throw new Exception("Currency mismatch. Account: {$account['currency']}, Requested: {$currency}");
    }

    // Record old balance for audit
    $oldBalance = (float)$account['balance'];
    $newBalance = $oldBalance + $amount;

    // Update balance
    $updateStmt = $pdo->prepare("
        UPDATE accounts 
        SET balance = balance + :amount,
            updated_at = NOW()
        WHERE account_number = :account_number
    ");
    $updateStmt->execute([
        'amount' => $amount,
        'account_number' => $destinationAccount
    ]);

    // Generate trace number
    $trace = 'DEP_' . time() . '_' . rand(100, 999) . '_' . substr(md5($reference), 0, 6);

    // FIX: Only use columns that exist in transactions table
    // transaction_id, user_id, account_id, from_account, to_account, type, amount, 
    // reference, description, status, created_at, swap_fee, creation_fee, admin_fee, 
    // sms_fee, rounding_adjustment, is_deleted, is_large_transaction, is_suspicious, 
    // reported_to_regulator, regulator_report_reference, trace_number
    $transStmt = $pdo->prepare("
        INSERT INTO transactions
        (user_id, account_id, from_account, to_account,
         type, amount, reference, description, status, trace_number)
        VALUES 
        (:user_id, :account_id, :from_account, :to_account,
         'deposit', :amount, :reference, :description, 'completed', :trace_number)
    ");
    $transStmt->execute([
        'user_id' => $account['user_id'],
        'account_id' => $account['account_id'],
        'from_account' => $sourceInstitution,
        'to_account' => $destinationAccount,
        'amount' => $amount,
        'reference' => $reference,
        'description' => "Deposit from {$sourceInstitution} (verified by {$requester})",
        'trace_number' => $trace
    ]);

    // Store idempotency with signature info
    if ($idempotencyKey) {
        $idempotencyStmt = $pdo->prepare("
            INSERT INTO processed_deposits
            (deposit_ref, account_number, amount, idempotency_key, 
             requester, signature_verified, verification_method, processed_at)
            VALUES 
            (:deposit_ref, :account_number, :amount, :key, 
             :requester, :sig_verified, :verification_method, NOW())
            ON CONFLICT (idempotency_key) DO NOTHING
        ");
        $idempotencyStmt->execute([
            'deposit_ref' => $trace,
            'account_number' => $destinationAccount,
            'amount' => $amount,
            'key' => $idempotencyKey,
            'requester' => $requester,
            'sig_verified' => $isValid ? 1 : 0,
            'verification_method' => 'certificate'
        ]);
    }

    // Create audit logs table if needed
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id SERIAL PRIMARY KEY,
            entity_type VARCHAR(50),
            entity_id INTEGER,
            action VARCHAR(50),
            category VARCHAR(50),
            severity VARCHAR(20),
            performed_by VARCHAR(100),
            performed_by_cert_verified BOOLEAN DEFAULT FALSE,
            verification_method VARCHAR(50),
            old_value JSONB,
            new_value JSONB,
            metadata JSONB,
            performed_at TIMESTAMP DEFAULT NOW()
        )
    ");

    // Audit log
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (entity_type, entity_id, action, category, severity, performed_by, 
         performed_by_cert_verified, verification_method, old_value, new_value, metadata, performed_at)
        VALUES 
        ('accounts', :entity_id, 'DEPOSIT', 'financial', 'info', :performed_by,
         :cert_verified, :verification_method, :old_value, :new_value, :metadata, NOW())
    ");
    $auditStmt->execute([
        'entity_id' => $account['account_id'],
        'performed_by' => $requester,
        'cert_verified' => $isValid ? 1 : 0,
        'verification_method' => 'certificate',
        'old_value' => json_encode(['balance' => $oldBalance]),
        'new_value' => json_encode(['balance' => $newBalance, 'amount' => $amount]),
        'metadata' => json_encode([
            'signature_verified' => $isValid,
            'reference' => $reference,
            'trace' => $trace,
            'source_institution' => $sourceInstitution
        ])
    ]);

    $pdo->commit();

    error_log("ZURUBANK DEPOSIT: Deposit completed - Trace: {$trace}, Amount: {$amount}, Account: {$destinationAccount}");

    // Get final balance
    $balanceStmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_number = ?");
    $balanceStmt->execute([$destinationAccount]);
    $finalBalance = (float)$balanceStmt->fetchColumn();

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        'processed' => true,
        'transaction_id' => $trace,
        'reference' => $reference,
        'amount' => $amount,
        'currency' => $currency,
        'new_balance' => $finalBalance,
        'old_balance' => $oldBalance,
        'account_number' => $destinationAccount,
        'requester' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate',
        'message' => 'Deposit processed successfully',
        'timestamp' => time()
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("ZURUBANK DEPOSIT ERROR: " . $e->getMessage());
    error_log("ZURUBANK DEPOSIT Trace: " . $e->getTraceAsString());
    error_log("ZURUBANK DEPOSIT Input: " . json_encode($input ?? []));
    
    http_response_code(400);
    echo json_encode([
        'processed' => false,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
