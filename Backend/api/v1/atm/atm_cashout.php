<?php
// --------------------------------------------------
// atm_cashout_voucher.php
// ZuruBank ATM Voucher Cashout (Swap Origin Supported)
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../helpers/response.php';

// -------------------------
// Read Input
// -------------------------
$data = json_decode(file_get_contents("php://input"), true);

$voucherNumber = trim($data['voucher_number'] ?? '');
$voucherPin    = trim($data['voucher_pin'] ?? '');
$atmId         = $data['atm_id'] ?? 'ATM001';

if (!$voucherNumber || !$voucherPin) {
    json_response("DECLINED", ["message" => "Missing voucher_number or voucher_pin"]);
    exit;
}

try {
    $pdo->beginTransaction();

    // -------------------------
    // 1️⃣ Check if atm_dispenses table exists and has correct structure
    // -------------------------
    try {
        // Try to create table if not exists (without currency column first)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS atm_dispenses (
                id SERIAL PRIMARY KEY,
                atm_id VARCHAR(50) NOT NULL,
                trace_number VARCHAR(255) NOT NULL UNIQUE,
                amount NUMERIC(20,4) NOT NULL,
                status VARCHAR(50) DEFAULT 'DISPENSED',
                created_at TIMESTAMP DEFAULT NOW()
            )
        ");
        
        // Check if currency column exists, add if not
        $stmtCheck = $pdo->query("
            SELECT column_name 
            FROM information_schema.columns 
            WHERE table_name = 'atm_dispenses' AND column_name = 'currency'
        ");
        if (!$stmtCheck->fetch()) {
            $pdo->exec("ALTER TABLE atm_dispenses ADD COLUMN currency VARCHAR(10) DEFAULT 'BWP'");
        }
    } catch (Exception $e) {
        // Table might already exist with different structure
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

    // -------------------------
    // 5️⃣ Check Expiry
    // -------------------------
    if ($voucher['voucher_expires_at'] && strtotime($voucher['voucher_expires_at']) < time()) {
        throw new Exception("Voucher expired");
    }

    $amount = floatval($voucher['amount']);

    // -------------------------
    // 6️⃣ Get ATM user ID
    // -------------------------
    $stmtAtmUser = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
    $stmtAtmUser->execute(['atm@zurubank.com']);
    $atmUser = $stmtAtmUser->fetch(PDO::FETCH_ASSOC);

    if (!$atmUser) {
        // Create ATM system user
        $randomPassword = bin2hex(random_bytes(16));
        $passwordHash = password_hash($randomPassword, PASSWORD_DEFAULT);
        
        $stmtCreateUser = $pdo->prepare("
            INSERT INTO users (full_name, email, password_hash, phone, role, status, created_at)
            VALUES (:full_name, :email, :password_hash, :phone, :role, :status, NOW())
            RETURNING user_id
        ");
        $stmtCreateUser->execute([
            ':full_name' => 'ATM System',
            ':email' => 'atm@zurubank.com',
            ':password_hash' => $passwordHash,
            ':phone' => 'ATM_SYSTEM',
            ':role' => 'system',
            ':status' => 'active'
        ]);
        $atmUserId = $stmtCreateUser->fetchColumn();
    } else {
        $atmUserId = $atmUser['user_id'];
    }

    // -------------------------
    // 7️⃣ Mark Voucher as Redeemed
    // -------------------------
    $update = $pdo->prepare("
        UPDATE instant_money_vouchers
        SET status = 'redeemed',
            redeemed_at = NOW(),
            redeemed_by = :redeemed_by
        WHERE voucher_id = :voucher_id
    ");
    $update->execute([
        ':voucher_id' => $voucher['voucher_id'],
        ':redeemed_by' => $atmUserId
    ]);

    // -------------------------
    // 8️⃣ Update or Create Cashout Record
    // -------------------------
    $checkCashout = $pdo->prepare("SELECT cashout_id FROM cashouts WHERE trace_number = ?");
    $checkCashout->execute([$voucherNumber]);
    $existingCashout = $checkCashout->fetch(PDO::FETCH_ASSOC);

    if ($existingCashout) {
        $updateCashout = $pdo->prepare("
            UPDATE cashouts
            SET status = 'COMPLETED',
                dispensed_at = NOW(),
                atm_id = :atm_id
            WHERE trace_number = :trace_number
        ");
        $updateCashout->execute([
            ':trace_number' => $voucherNumber,
            ':atm_id' => $atmId
        ]);
        $cashoutId = $existingCashout['cashout_id'];
    } else {
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
                NOW(),
                NOW()
            )
            RETURNING cashout_id
        ");
        
        $cashoutReference = 'CASHOUT-' . time() . '-' . substr($voucherNumber, -6);
        
        $insertCashout->execute([
            ':trace_number' => $voucherNumber,
            ':cashout_reference' => $cashoutReference,
            ':destination_bank_id' => 2,
            ':user_id' => $voucher['created_by'] ?? 1,
            ':amount' => $amount,
            ':atm_id' => $atmId
        ]);
        $cashoutId = $insertCashout->fetchColumn();
    }

    // -------------------------
    // 9️⃣ Insert ATM Dispense Record (without currency column)
    // -------------------------
    // First check what columns exist
    $stmtCols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'atm_dispenses'");
    $columns = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
    
    if (in_array('currency', $columns)) {
        // If currency column exists, include it
        $insert = $pdo->prepare("
            INSERT INTO atm_dispenses (
                atm_id,
                trace_number,
                amount,
                currency,
                status,
                created_at
            )
            VALUES (?, ?, ?, 'BWP', 'DISPENSED', NOW())
            ON CONFLICT (trace_number) DO NOTHING
        ");
        $insert->execute([
            $atmId,
            $voucherNumber,
            $amount
        ]);
    } else {
        // If no currency column, insert without it
        $insert = $pdo->prepare("
            INSERT INTO atm_dispenses (
                atm_id,
                trace_number,
                amount,
                status,
                created_at
            )
            VALUES (?, ?, ?, 'DISPENSED', NOW())
            ON CONFLICT (trace_number) DO NOTHING
        ");
        $insert->execute([
            $atmId,
            $voucherNumber,
            $amount
        ]);
    }

    // -------------------------
    // 🔟 Create Transaction Record
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
            NOW()
        )
    ");

    $stmtTx->execute([
        ':user_id' => $voucher['created_by'] ?? 1,
        ':from_account' => 'VOUCHER-SUSPENSE',
        ':to_account' => 'CASH',
        ':amount' => $amount,
        ':reference' => $voucherNumber,
        ':description' => "ATM cashout of voucher {$voucherNumber} at {$atmId}"
    ]);

    // -------------------------
    // 1️⃣1️⃣ Record in swap_ledger
    // -------------------------
    $stmtLedger = $pdo->prepare("
        INSERT INTO swap_ledger (
            reference_id,
            debit_account,
            credit_account,
            amount,
            currency,
            description,
            created_at
        )
        VALUES (
            :reference_id,
            :debit_account,
            :credit_account,
            :amount,
            'BWP',
            :description,
            NOW()
        )
    ");

    $stmtLedger->execute([
        ':reference_id' => $voucherNumber,
        ':debit_account' => 'VOUCHER-SUSPENSE',
        ':credit_account' => 'ATM:' . $atmId,
        ':amount' => $amount,
        ':description' => "ATM cashout settlement for voucher {$voucherNumber}"
    ]);

    $pdo->commit();

    json_response("CASHOUT_SUCCESS", [
        "voucher_number" => $voucherNumber,
        "amount"         => $amount,
        "atm_id"         => $atmId,
        "cashout_id"     => $cashoutId ?? null,
        "timestamp"      => date("Y-m-d H:i:s"),
        "message"        => "Cashout successful"
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("ATM Cashout Error: " . $e->getMessage());
    
    json_response("DECLINED", [
        "message" => $e->getMessage()
    ]);
}
