<?php
// --------------------------------------------------
// atm_cashout_voucher.php
// ZuruBank ATM Voucher Cashout (Swap Origin Supported)
// UPDATED: Certificate-based verification (Visa/Mastercard model)
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../helpers/response.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

// -------------------------
// Read Input
// -------------------------
$data = json_decode(file_get_contents("php://input"), true);

$voucherNumber = trim($data['voucher_number'] ?? '');
$voucherPin    = trim($data['voucher_pin'] ?? '');
$atmId         = $data['atm_id'] ?? 'ATM001';
$timestamp     = $data['timestamp'] ?? null;
$requester     = $data['requester'] ?? 'ATM_SYSTEM';

if (!$voucherNumber || !$voucherPin) {
    json_response("DECLINED", ["message" => "Missing voucher_number or voucher_pin"]);
    exit;
}

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($data['certificate'])) {
    error_log("ATM Cashout: No certificate provided");
    json_response("DECLINED", [
        "message" => "Certificate required - please upgrade to certificate-based authentication",
        "timestamp" => time()
    ]);
    exit;
}

$certManager = new CertificateManager('ZURUBANK');
$verification = $certManager->verifySignedRequest($data);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("ATM Cashout: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("ATM Cashout: Requester: {$requester}");

if (!$isValid) {
    error_log("ATM Cashout: Certificate verification failed");
    json_response("DECLINED", [
        "message" => "Certificate verification failed: " . ($verification['message'] ?? 'Unknown error'),
        "timestamp" => time()
    ]);
    exit;
}

error_log("ATM Cashout: Request verified from {$requester} using certificate");

try {
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
        
        // Add verification_method column if not exists
        $stmtCheckVm = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'atm_dispenses' AND column_name = 'verification_method'
        ");
        if (!$stmtCheckVm->fetch()) {
            $pdo->exec("ALTER TABLE atm_dispenses ADD COLUMN verification_method VARCHAR(50)");
        }
        
        // Add updated_at column if not exists
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
    // 2️⃣ Fetch Voucher (FOR UPDATE prevents race condition)
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

    // -------------------------
    // 3️⃣ Validate PIN
    // -------------------------
    if ($voucher['voucher_pin'] !== $voucherPin) {
        throw new Exception("Invalid PIN for voucher: {$voucherNumber}");
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
        throw new Exception("Voucher expired at: {$voucher['voucher_expires_at']}");
    }

    $amount = floatval($voucher['amount']);

    // -------------------------
    // 6️⃣ Mark Voucher as Redeemed (with requester info)
    // -------------------------
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

    // -------------------------
    // 7️⃣ Create Cashout Record
    // -------------------------
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

    // -------------------------
    // 1️⃣1️⃣ Audit Log
    // -------------------------
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
