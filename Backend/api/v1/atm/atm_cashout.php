<?php
// --------------------------------------------------
// atm_cashout_voucher.php
// ZuruBank ATM Voucher Cashout (Swap Origin Supported)
// ADDED: Cryptographic signature verification
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../helpers/response.php';
require __DIR__ . '/../../helpers/crypto.php';  // Add signature functions

// -------------------------
// Read Input
// -------------------------
$data = json_decode(file_get_contents("php://input"), true);

$voucherNumber = trim($data['voucher_number'] ?? '');
$voucherPin    = trim($data['voucher_pin'] ?? '');
$atmId         = $data['atm_id'] ?? 'ATM001';
$signature     = $data['signature'] ?? null;
$timestamp     = $data['timestamp'] ?? null;
$requester     = $data['requester'] ?? 'ATM_SYSTEM';

if (!$voucherNumber || !$voucherPin) {
    json_response("DECLINED", ["message" => "Missing voucher_number or voucher_pin"]);
    exit;
}

// ============================================================
// VERIFY SIGNATURE FROM REQUESTER (VouchMorph or ATM)
// ============================================================
$payloadToVerify = [
    'voucher_number' => $voucherNumber,
    'voucher_pin' => $voucherPin,
    'atm_id' => $atmId,
    'timestamp' => $timestamp
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("ATM Cashout: Missing signature");
    json_response("DECLINED", [
        "message" => "Missing signature - cashout requests must be signed",
        "timestamp" => time()
    ]);
    exit;
}

// Get public key for requester
$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("ATM Cashout: No public key for requester: {$requester}");
    json_response("DECLINED", [
        "message" => "No public key found for requester: {$requester}",
        "timestamp" => time()
    ]);
    exit;
}

// Verify signature
$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("ATM Cashout: Invalid signature from {$requester}");
    json_response("DECLINED", [
        "message" => "Invalid signature - cashout request cannot be trusted",
        "timestamp" => time()
    ]);
    exit;
}

error_log("ATM Cashout: Signature verified from {$requester}");

try {
    $pdo->beginTransaction();

    // -------------------------
    // Check if atm_dispenses table exists
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
                signature_verified BOOLEAN DEFAULT FALSE,
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
        
        // Add requester and signature_verified columns if not exists
        $stmtCheckReq = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'atm_dispenses' AND column_name = 'requester'
        ");
        if (!$stmtCheckReq->fetch()) {
            $pdo->exec("ALTER TABLE atm_dispenses ADD COLUMN requester VARCHAR(100)");
            $pdo->exec("ALTER TABLE atm_dispenses ADD COLUMN signature_verified BOOLEAN DEFAULT FALSE");
        }
    } catch (Exception $e) {
        error_log("Table setup warning: " . $e->getMessage());
    }

    // -------------------------
    // 2️⃣ Fetch Voucher (FOR UPDATE prevents race condition)
    // -------------------------
    $stmt = $pdo->prepare("
        SELECT * FROM instant_money_vouchers
        WHERE voucher_number = ?
        FOR UPDATE
    ");
    $stmt->execute([$voucherNumber]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) {
        throw new Exception("Voucher not found");
    }

    // -------------------------
    // 3️⃣ Validate PIN
    // -------------------------
    if ($voucher['voucher_pin'] !== $voucherPin) {
        throw new Exception("Invalid PIN");
    }

    // -------------------------
    // 4️⃣ Validate Status
    // -------------------------
    $allowedStatuses = ['active', 'hold'];
    if (!in_array($voucher['status'], $allowedStatuses)) {
        throw new Exception("Voucher cannot be cashed out (status: {$voucher['status']})");
    }

    // Check if already redeemed (double spend prevention)
    if ($voucher['status'] === 'redeemed' || !is_null($voucher['redeemed_at'])) {
        throw new Exception("Voucher has already been redeemed");
    }

    // -------------------------
    // 5️⃣ Check Expiry
    // -------------------------
    if ($voucher['voucher_expires_at'] && strtotime($voucher['voucher_expires_at']) < time()) {
        throw new Exception("Voucher expired");
    }

    $amount = floatval($voucher['amount']);

    // -------------------------
    // 6️⃣ Mark Voucher as Redeemed (with requester info)
    // -------------------------
    $update = $pdo->prepare("
        UPDATE instant_money_vouchers
        SET status = 'redeemed',
            redeemed_at = NOW(),
            redeemed_by = :requester,
            redeemed_via = 'ATM',
            redemption_signature_verified = :signature_verified
        WHERE voucher_id = :voucher_id
    ");
    $update->execute([
        ':voucher_id' => $voucher['voucher_id'],
        ':requester' => $requester,
        ':signature_verified' => $isValid ? 1 : 0
    ]);

    // -------------------------
    // 7️⃣ Create Cashout Record
    // -------------------------
    $cashoutReference = 'CASHOUT-' . time() . '-' . substr($voucherNumber, -6);
    
    // Check if cashouts table has the required columns
    try {
        $stmtCheckCols = $pdo->query("
            SELECT column_name FROM information_schema.columns 
            WHERE table_name = 'cashouts' AND column_name = 'requester'
        ");
        if (!$stmtCheckCols->fetch()) {
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
            created_at,
            dispensed_at
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
            :signature_verified,
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
        ':signature_verified' => $isValid ? 1 : 0
    ]);
    $cashoutId = $insertCashout->fetchColumn();

    // -------------------------
    // 8️⃣ Insert ATM Dispense Record
    // -------------------------
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
                created_at
            )
            VALUES (?, ?, ?, 'BWP', 'DISPENSED', ?, ?, NOW())
            ON CONFLICT (trace_number) DO NOTHING
        ");
        $insert->execute([
            $atmId,
            $voucherNumber,
            $amount,
            $requester,
            $isValid ? 1 : 0
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
                created_at
            )
            VALUES (?, ?, ?, 'DISPENSED', ?, ?, NOW())
            ON CONFLICT (trace_number) DO NOTHING
        ");
        $insert->execute([
            $atmId,
            $voucherNumber,
            $amount,
            $requester,
            $isValid ? 1 : 0
        ]);
    }

    // -------------------------
    // 9️⃣ Create Transaction Record
    // -------------------------
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
            created_at
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
            :signature_verified,
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
        ':signature_verified' => $isValid ? 1 : 0
    ]);

    // -------------------------
    // 🔟 Record in swap_ledger
    // -------------------------
    $stmtLedger = $pdo->prepare("
        INSERT INTO swap_ledger (
            reference_id,
            debit_account,
            credit_account,
            amount,
            currency,
            description,
            requester,
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
            NOW()
        )
    ");

    $stmtLedger->execute([
        ':reference_id' => $voucherNumber,
        ':debit_account' => 'VOUCHER-SUSPENSE',
        ':credit_account' => 'ATM:' . $atmId,
        ':amount' => $amount,
        ':description' => "ATM cashout settlement for voucher {$voucherNumber} (authorized by {$requester})",
        ':requester' => $requester
    ]);

    // -------------------------
    // 1️⃣1️⃣ Audit Log
    // -------------------------
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (entity, entity_id, action, category, severity, performed_by, metadata, performed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $auditStmt->execute([
        'instant_money_vouchers',
        $voucher['voucher_id'],
        'CASHOUT',
        'financial',
        'info',
        $requester,
        json_encode([
            'signature_verified' => $isValid,
            'amount' => $amount,
            'atm_id' => $atmId,
            'voucher_number' => $voucherNumber
        ])
    ]);

    $pdo->commit();

    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        "status" => "CASHOUT_SUCCESS",
        "voucher_number" => $voucherNumber,
        "amount" => $amount,
        "atm_id" => $atmId,
        "cashout_id" => $cashoutId,
        "requester" => $requester,
        "signature_verified" => $isValid,
        "timestamp" => date("Y-m-d H:i:s"),
        "message" => "Cashout successful"
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("ATM Cashout Error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    json_response("DECLINED", [
        "message" => $e->getMessage(),
        "timestamp" => time()
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
