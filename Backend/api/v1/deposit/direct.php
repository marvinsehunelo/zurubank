<?php
/**
 * ZURUBANK Direct Deposit - Compatible with SwapService
 * UPDATED: Certificate-based verification (Visa/Mastercard model)
 * FIXED: Correct audit_logs table structure matching your schema
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

// Get client IP and user agent for audit
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

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

    // Lock and get account - FIXED: Only use account_number without alt
    $stmt = $pdo->prepare("
        SELECT account_id, user_id, balance, currency, account_number 
        FROM accounts 
        WHERE account_number = :account_number
        FOR UPDATE
    ");
    $stmt->execute(['account_number' => $destinationAccount]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        throw new Exception("Account not found: {$destinationAccount}");
    }

    error_log("ZURUBANK DEPOSIT: Account data: " . json_encode($account));

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

    // ============================================================
    // FIX: Get user_id directly from the account record
    // ============================================================
    $userId = $account['user_id'] ?? null;
    
    // Ensure user_id is a valid integer
    if (!is_numeric($userId) || $userId <= 0) {
        // If user_id is NULL or invalid, use the account_identifier as fallback
        if (is_numeric($destinationAccount) && $destinationAccount > 0) {
            $checkStmt = $pdo->prepare("SELECT 1 FROM users WHERE user_id = :user_id LIMIT 1");
            $checkStmt->execute(['user_id' => (int)$destinationAccount]);
            if ($checkStmt->fetchColumn()) {
                $userId = (int)$destinationAccount;
            } else {
                // Fallback to first user
                $fallbackStmt = $pdo->query("SELECT user_id FROM users LIMIT 1");
                $fallback = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
                $userId = $fallback ? (int)$fallback['user_id'] : 1;
            }
        } else {
            // Fallback to first user
            $fallbackStmt = $pdo->query("SELECT user_id FROM users LIMIT 1");
            $fallback = $fallbackStmt->fetch(PDO::FETCH_ASSOC);
            $userId = $fallback ? (int)$fallback['user_id'] : 1;
        }
    } else {
        $userId = (int)$userId;
    }
    
    error_log("ZURUBANK DEPOSIT: Using user_id: {$userId} for account: {$destinationAccount}");

    // Insert into transactions table
    $transStmt = $pdo->prepare("
        INSERT INTO transactions
        (user_id, account_id, from_account, to_account,
         type, amount, reference, description, status, trace_number)
        VALUES 
        (:user_id, :account_id, :from_account, :to_account,
         'deposit', :amount, :reference, :description, 'completed', :trace_number)
    ");
    $transStmt->execute([
        'user_id' => $userId,
        'account_id' => $account['account_id'],
        'from_account' => $sourceInstitution,
        'to_account' => $destinationAccount,
        'amount' => $amount,
        'reference' => $reference,
        'description' => "Deposit from {$sourceInstitution}",
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

    // ============================================================
    // AUDIT LOG - EXACTLY MATCHING YOUR TABLE STRUCTURE
    // ============================================================
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id SERIAL PRIMARY KEY,
            entity VARCHAR(50),
            entity_id INTEGER,
            action VARCHAR(50),
            category VARCHAR(50),
            severity VARCHAR(20),
            old_value JSONB,
            new_value JSONB,
            performed_at TIMESTAMP DEFAULT NOW(),
            performed_by VARCHAR(100),
            ip_address VARCHAR(45),
            user_agent TEXT,
            geo_location VARCHAR(100)
        )
    ");

    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (entity, entity_id, action, category, severity, 
         old_value, new_value, performed_at, performed_by, 
         ip_address, user_agent, geo_location)
        VALUES 
        (:entity, :entity_id, :action, :category, :severity,
         :old_value, :new_value, NOW(), :performed_by,
         :ip_address, :user_agent, :geo_location)
    ");
    
    $auditStmt->execute([
        'entity' => 'accounts',
        'entity_id' => $account['account_id'],
        'action' => 'DEPOSIT',
        'category' => 'financial',
        'severity' => 'info',
        'old_value' => json_encode(['balance' => $oldBalance, 'currency' => $currency]),
        'new_value' => json_encode(['balance' => $newBalance, 'amount' => $amount, 'currency' => $currency]),
        'performed_by' => $userId,
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
        'geo_location' => null
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
?>
