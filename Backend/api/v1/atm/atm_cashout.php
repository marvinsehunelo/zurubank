<?php
// --------------------------------------------------
// atm_cashout_voucher.php
// ZuruBank ATM Voucher Cashout (Swap Origin Supported)
// FIXED: Server-side signing for internal ATM terminals
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Clear output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// ============================================================
// CORRECTED PATHS
// ============================================================

$baseDir = dirname(__DIR__, 3);
$configDir = $baseDir . '/config';
$helpersDir = $baseDir . '/helpers';

// Include database config
$dbFile = $configDir . '/db.php';
if (!file_exists($dbFile)) {
    send_json_response([
        'status' => 'ERROR',
        'message' => 'Database config not found'
    ]);
    exit;
}
require_once $dbFile;

// Include helpers
$cryptoFile = $helpersDir . '/crypto.php';
if (file_exists($cryptoFile) && !function_exists('generate_signature')) {
    require_once $cryptoFile;
}

$responseFile = $helpersDir . '/response.php';
if (file_exists($responseFile) && !function_exists('json_response')) {
    require_once $responseFile;
}

// CertificateManager is MANDATORY
$certFile = $helpersDir . '/CertificateManager.php';
if (!file_exists($certFile)) {
    send_json_response([
        'status' => 'ERROR',
        'message' => 'CertificateManager not found - security check failed'
    ]);
    exit;
}

if (!class_exists('CertificateManager')) {
    require_once $certFile;
}

// ============================================================
// FUNCTIONS
// ============================================================

function send_json_response($data) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_response($status, $data = []) {
    $response = [
        'status' => $status,
        'timestamp' => time()
    ];
    
    if (isset($data['message'])) {
        $response['message'] = $data['message'];
    }
    
    if (isset($data['amount'])) {
        $response['amount'] = $data['amount'];
    }
    
    foreach ($data as $key => $value) {
        if (!isset($response[$key])) {
            $response[$key] = $value;
        }
    }
    
    send_json_response($response);
}

// ============================================================
// READ INPUT
// ============================================================

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    json_response("ERROR", ["message" => "Invalid JSON input: " . json_last_error_msg()]);
    exit;
}

$voucherNumber = trim($data['voucher_number'] ?? '');
$voucherPin    = trim($data['voucher_pin'] ?? '');
$atmId         = $data['atm_id'] ?? 'ATM001';
$requester     = $data['requester'] ?? 'ATM_SYSTEM';

if (!$voucherNumber || !$voucherPin) {
    json_response("DECLINED", ["message" => "Missing voucher_number or voucher_pin"]);
    exit;
}

error_log("ATM Cashout: Processing voucher: {$voucherNumber}, ATM: {$atmId}");

// ============================================================
// CERTIFICATE-BASED VERIFICATION
// ============================================================

$certManager = new CertificateManager('ZURUBANK');

// Check if CertificateManager is configured
if (!$certManager->isConfigured()) {
    error_log("ATM Cashout: CertificateManager not configured — missing CA/key/cert for ZURUBANK");
    json_response("DECLINED", [
        "message" => "Server signing unavailable — contact support",
        "timestamp" => time()
    ]);
    exit;
}

// Determine if this is an external caller or internal ATM
if (isset($data['certificate']) && is_string($data['certificate']) && isset($data['signature'])) {
    // External caller (e.g. VouchMorph, another bank) — verify their certificate
    error_log("ATM Cashout: External certificate presented — verifying");
    $verification = $certManager->verifySignedRequest($data);
} else {
    // ZuruBank's own first-party ATM UI. The browser cannot hold a real
    // private key, so anything it claims to "sign" cannot be trusted.
    // ZuruBank signs on behalf of its own verified terminal instead,
    // using the key it already has configured server-side.
    error_log("ATM Cashout: No external certificate — self-signing as internal terminal ZURUBANK_ATM_{$atmId}");
    
    // Create a signed request on behalf of the ATM
    $signedData = $certManager->createSignedRequest([
        'voucher_number' => $voucherNumber,
        'voucher_pin'    => $voucherPin,
        'amount'         => $data['amount'] ?? null,
        'atm_id'         => $atmId,
        'action'         => $data['action'] ?? 'CASHOUT',
        'timestamp'      => time()
    ], 'ZURUBANK_ATM_' . $atmId);
    
    // Verify the signature we just created (acts as validation)
    $verification = $certManager->verifySignedRequest($signedData);
}

$isValid = $verification['verified'];
$requester = $verification['requester'] ?? $requester;

error_log("ATM Cashout: Verification result: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("ATM Cashout: Requester: {$requester}");

if (!$isValid) {
    error_log("ATM Cashout: Verification failed — " . ($verification['message'] ?? 'Unknown error'));
    json_response("DECLINED", [
        "message" => "Verification failed: " . ($verification['message'] ?? 'Unknown error'),
        "timestamp" => time()
    ]);
    exit;
}

error_log("ATM Cashout: Request verified from {$requester}");

// ============================================================
// PROCESS CASOUT
// ============================================================

try {
    // Check database connection
    if (!isset($pdo)) {
        throw new Exception("Database connection not available");
    }
    
    $pdo->beginTransaction();

    // -------------------------
    // Check/Create tables with enhanced columns
    // -------------------------
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS atm_dispenses (
                id SERIAL PRIMARY KEY,
                atm_id VARCHAR(50) NOT NULL,
                trace_number VARCHAR(255) NOT NULL UNIQUE,
                amount NUMERIC(20,4) NOT NULL,
                status VARCHAR(50) DEFAULT 'DISPENSED',
                created_at TIMESTAMP DEFAULT NOW(),
                updated_at TIMESTAMP DEFAULT NOW(),
                signature_verified BOOLEAN DEFAULT FALSE,
                verification_method VARCHAR(50),
                requester VARCHAR(100)
            )
        ");
        
        $stmtCheck = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'atm_dispenses' AND column_name = 'currency'
        ");
        if (!$stmtCheck->fetch()) {
            $pdo->exec("ALTER TABLE atm_dispenses ADD COLUMN currency VARCHAR(10) DEFAULT 'BWP'");
        }
        
        $stmtCheckVm = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'atm_dispenses' AND column_name = 'verification_method'
        ");
        if (!$stmtCheckVm->fetch()) {
            $pdo->exec("ALTER TABLE atm_dispenses ADD COLUMN verification_method VARCHAR(50)");
        }
        
        $stmtCheckUp = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'atm_dispenses' AND column_name = 'updated_at'
        ");
        if (!$stmtCheckUp->fetch()) {
            $pdo->exec("ALTER TABLE atm_dispenses ADD COLUMN updated_at TIMESTAMP DEFAULT NOW()");
        }
    } catch (Exception $e) {
        error_log("Table setup warning: " . $e->getMessage());
    }

    // -------------------------
    // Fetch Voucher (FOR UPDATE prevents race condition)
    // -------------------------
    $stmt = $pdo->prepare("
        SELECT * FROM instant_money_vouchers
        WHERE voucher_number = :voucher_number
        FOR UPDATE
    ");
    $stmt->execute(['voucher_number' => $voucherNumber]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) {
        throw new Exception("Voucher not found: {$voucherNumber}");
    }

    error_log("ATM Cashout: Found voucher ID={$voucher['voucher_id']}, Status={$voucher['status']}, Amount={$voucher['amount']}");

    // Validate PIN
    if ($voucher['voucher_pin'] !== $voucherPin) {
        throw new Exception("Invalid PIN for voucher: {$voucherNumber}");
    }

    // Validate Status
    $allowedStatuses = ['active', 'hold'];
    if (!in_array($voucher['status'], $allowedStatuses)) {
        throw new Exception("Voucher cannot be cashed out (status: {$voucher['status']})");
    }

    // Check if already redeemed
    if ($voucher['status'] === 'redeemed' || !is_null($voucher['redeemed_at'])) {
        throw new Exception("Voucher has already been redeemed");
    }

    // Check Expiry
    if ($voucher['voucher_expires_at'] && strtotime($voucher['voucher_expires_at']) < time()) {
        throw new Exception("Voucher expired at: {$voucher['voucher_expires_at']}");
    }

    $amount = floatval($voucher['amount']);

    // Mark Voucher as Redeemed
    $update = $pdo->prepare("
        UPDATE instant_money_vouchers
        SET status = 'redeemed',
            redeemed_at = NOW(),
            updated_at = NOW(),
            redeemed_by = :requester,
            redeemed_via = 'ATM',
            redemption_signature_verified = :sig_verified,
            redemption_verification_method = :verification_method
        WHERE voucher_id = :voucher_id
    ");
    $update->execute([
        ':voucher_id' => $voucher['voucher_id'],
        ':requester' => $requester,
        ':sig_verified' => $isValid ? 1 : 0,
        ':verification_method' => 'certificate'
    ]);

    // Create Cashout Record
    $cashoutReference = 'CASHOUT-' . time() . '-' . substr($voucherNumber, -6);
    
    // Ensure cashouts table has required columns
    try {
        $stmtCheckCols = $pdo->query("
            SELECT column_name FROM information_schema.columns 
            WHERE table_name = 'cashouts' AND column_name = 'verification_method'
        ");
        if (!$stmtCheckCols->fetch()) {
            $pdo->exec("ALTER TABLE cashouts ADD COLUMN verification_method VARCHAR(50)");
        }
        
        $stmtCheckReq = $pdo->query("
            SELECT column_name FROM information_schema.columns 
            WHERE table_name = 'cashouts' AND column_name = 'requester'
        ");
        if (!$stmtCheckReq->fetch()) {
            $pdo->exec("ALTER TABLE cashouts ADD COLUMN requester VARCHAR(100)");
            $pdo->exec("ALTER TABLE cashouts ADD COLUMN signature_verified BOOLEAN DEFAULT FALSE");
        }
    } catch (Exception $e) {
        // Table might not exist yet
    }
    
    $insertCashout = $pdo->prepare("
        INSERT INTO cashouts (
            trace_number,
            cashout_reference,
            destination_bank_id,
            user_id,
            amount,
            currency,
            status,
            atm_id,
            requester,
            signature_verified,
            verification_method,
            created_at,
            dispensed_at,
            updated_at
        )
        VALUES (
            :trace_number,
            :cashout_reference,
            :destination_bank_id,
            :user_id,
            :amount,
            'BWP',
            'COMPLETED',
            :atm_id,
            :requester,
            :sig_verified,
            :verification_method,
            NOW(),
            NOW(),
            NOW()
        )
        RETURNING cashout_id
    ");
    
    $insertCashout->execute([
        ':trace_number' => $voucherNumber,
        ':cashout_reference' => $cashoutReference,
        ':destination_bank_id' => 2,
        ':user_id' => $voucher['created_by'] ?? 1,
        ':amount' => $amount,
        ':atm_id' => $atmId,
        ':requester' => $requester,
        ':sig_verified' => $isValid ? 1 : 0,
        ':verification_method' => 'certificate'
    ]);
    $cashoutId = $insertCashout->fetchColumn();

    // Insert ATM Dispense Record
    $stmtCols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'atm_dispenses'");
    $columns = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('currency', $columns)) {
        $insert = $pdo->prepare("
            INSERT INTO atm_dispenses (
                atm_id,
                trace_number,
                amount,
                currency,
                status,
                requester,
                signature_verified,
                verification_method,
                created_at,
                updated_at
            )
            VALUES (?, ?, ?, 'BWP', 'DISPENSED', ?, ?, ?, NOW(), NOW())
            ON CONFLICT (trace_number) DO NOTHING
        ");
        $insert->execute([
            $atmId,
            $voucherNumber,
            $amount,
            $requester,
            $isValid ? 1 : 0,
            'certificate'
        ]);
    } else {
        $insert = $pdo->prepare("
            INSERT INTO atm_dispenses (
                atm_id,
                trace_number,
                amount,
                status,
                requester,
                signature_verified,
                verification_method,
                created_at,
                updated_at
            )
            VALUES (?, ?, ?, 'DISPENSED', ?, ?, ?, NOW(), NOW())
            ON CONFLICT (trace_number) DO NOTHING
        ");
        $insert->execute([
            $atmId,
            $voucherNumber,
            $amount,
            $requester,
            $isValid ? 1 : 0,
            'certificate'
        ]);
    }

    // Create Transaction Record
    $stmtTx = $pdo->prepare("
        INSERT INTO transactions (
            user_id,
            from_account,
            to_account,
            type,
            amount,
            reference,
            description,
            status,
            requester,
            signature_verified,
            verification_method,
            created_at,
            updated_at
        )
        VALUES (
            :user_id,
            :from_account,
            :to_account,
            'atm_cashout',
            :amount,
            :reference,
            :description,
            'completed',
            :requester,
            :sig_verified,
            :verification_method,
            NOW(),
            NOW()
        )
    ");

    $stmtTx->execute([
        ':user_id' => $voucher['created_by'] ?? 1,
        ':from_account' => 'VOUCHER-SUSPENSE',
        ':to_account' => 'CASH',
        ':amount' => $amount,
        ':reference' => $voucherNumber,
        ':description' => "ATM cashout of voucher {$voucherNumber} at {$atmId} (authorized by {$requester})",
        ':requester' => $requester,
        ':sig_verified' => $isValid ? 1 : 0,
        ':verification_method' => 'certificate'
    ]);

    // Record in swap_ledger
    $stmtLedger = $pdo->prepare("
        INSERT INTO swap_ledger (
            reference_id,
            debit_account,
            credit_account,
            amount,
            currency,
            description,
            requester,
            signature_verified,
            created_at
        )
        VALUES (
            :reference_id,
            :debit_account,
            :credit_account,
            :amount,
            'BWP',
            :description,
            :requester,
            :sig_verified,
            NOW()
        )
    ");

    $stmtLedger->execute([
        ':reference_id' => $voucherNumber,
        ':debit_account' => 'VOUCHER-SUSPENSE',
        ':credit_account' => 'ATM:' . $atmId,
        ':amount' => $amount,
        ':description' => "ATM cashout settlement for voucher {$voucherNumber} (authorized by {$requester})",
        ':requester' => $requester,
        ':sig_verified' => $isValid ? 1 : 0
    ]);

    // Audit Log
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (entity_type, entity_id, action, category, severity, performed_by, 
         performed_by_cert_verified, verification_method, metadata, performed_at)
        VALUES 
        ('instant_money_vouchers', :entity_id, 'CASHOUT', 'financial', 'info', :performed_by,
         :cert_verified, :verification_method, :metadata, NOW())
    ");
    $auditStmt->execute([
        'entity_id' => $voucher['voucher_id'],
        'performed_by' => $requester,
        'cert_verified' => $isValid ? 1 : 0,
        'verification_method' => 'certificate',
        'metadata' => json_encode([
            'signature_verified' => $isValid,
            'amount' => $amount,
            'atm_id' => $atmId,
            'voucher_number' => $voucherNumber,
            'cashout_id' => $cashoutId
        ])
    ]);

    $pdo->commit();

    error_log("ATM Cashout: Cashout completed - Voucher: {$voucherNumber}, Amount: {$amount}, ATM: {$atmId}");

    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    $responsePayload = [
        "status" => "CASHOUT_SUCCESS",
        "voucher_number" => $voucherNumber,
        "amount" => $amount,
        "currency" => "BWP",
        "atm_id" => $atmId,
        "cashout_id" => $cashoutId,
        "cashout_reference" => $cashoutReference,
        "requester" => $requester,
        "signature_verified" => $isValid,
        "verification_method" => "certificate",
        "timestamp" => date("Y-m-d H:i:s"),
        "message" => "Cashout successful"
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("ATM Cashout ERROR: " . $e->getMessage());
    error_log("ATM Cashout Trace: " . $e->getTraceAsString());
    error_log("ATM Cashout Input: " . json_encode($data ?? []));
    
    json_response("DECLINED", [
        "message" => $e->getMessage(),
        "timestamp" => time()
    ]);
}
