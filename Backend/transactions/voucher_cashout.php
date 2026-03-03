<?php
declare(strict_types=1);

require_once __DIR__ . "/../config/db.php";
header("Content-Type: application/json; charset=utf-8");

// LOGGING
$logDir = __DIR__ . '/../config';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$debugLog = $logDir . '/voucher_cashout_debug.log';

function jsonResponse(string $status, string $message, array $extra = []): void {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

try {
    // -------------------------
    // AUTHENTICATION
    // -------------------------
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $apiKey = $headers['X-API-Key'] ?? ($_POST['api_key'] ?? null);
    $token  = $headers['Authorization'] ?? ($_POST['token'] ?? null);
    $request_user_id = null;

    if ($apiKey === 'ZURU_LOCAL_KEY_ABC123') {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = 'system@zurubank.com' LIMIT 1");
        $stmt->execute();
        $sysUser = $stmt->fetch();
        $request_user_id = $sysUser ? (int)$sysUser['user_id'] : 2;
    } elseif ($token) {
        if (str_starts_with($token, 'Bearer ')) $token = substr($token, 7);
        $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? AND (expires_at > NOW()) LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) jsonResponse('error', 'Invalid or expired token');
        $request_user_id = $user['user_id'];
    } else {
        jsonResponse('error', 'Token or API key required');
    }

    // -------------------------
    // INPUT
    // -------------------------
    $data = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $voucher_number = trim($data['voucher_number'] ?? '');
    $pin = trim($data['pin'] ?? '');

    if (!$voucher_number || !$pin) {
        jsonResponse('error', 'Voucher number and PIN are required');
    }

    $pdo->beginTransaction();

    // -------------------------
    // LOCK AND FETCH VOUCHER
    // -------------------------
    $stmt = $pdo->prepare("
        SELECT * FROM instant_money_vouchers 
        WHERE voucher_number = ? AND voucher_pin = ? 
        FOR UPDATE
    ");
    $stmt->execute([$voucher_number, $pin]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) throw new Exception("Voucher not found or PIN incorrect");
    if ($voucher['status'] !== 'active') throw new Exception("Voucher is already " . $voucher['status']);

    $amount = (float)$voucher['amount'];

    // -------------------------
    // ACCOUNT MOVEMENTS
    // -------------------------
    $stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE account_type = 'voucher_suspense' FOR UPDATE");
    $stmt->execute();
    $suspense = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$suspense) throw new Exception("Voucher Suspense account not found");
    if ((float)$suspense['balance'] < $amount) throw new Exception("Insufficient funds in Suspense account");

    $stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE account_type = 'partner_bank_settlement' FOR UPDATE");
    $stmt->execute();
    $settlement = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$settlement) throw new Exception("Bank settlement account not found");

    $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?")
        ->execute([$amount, $suspense['account_id']]);

    $new_settlement_balance = (float)$settlement['balance'] + $amount;
    $pdo->prepare("UPDATE accounts SET balance = ? WHERE account_id = ?")
        ->execute([$new_settlement_balance, $settlement['account_id']]);

    // -------------------------
    // MARK VOUCHER REDEEMED
    // -------------------------
    $stmt = $pdo->prepare("
        UPDATE instant_money_vouchers
        SET status = 'redeemed', redeemed_by = ?, redeemed_at = NOW()
        WHERE voucher_number = ? AND voucher_pin = ?
        RETURNING voucher_number, sat_purchased
    ");
    $stmt->execute([$request_user_id, $voucher_number, $pin]);
    $redeemedVoucher = $stmt->fetch(PDO::FETCH_ASSOC);

    // -------------------------
    // LOG TRANSACTION & GET REFERENCE
    // -------------------------
    $stmt = $pdo->prepare("
        INSERT INTO transactions 
            (account_id, user_id, from_account, to_account, amount, type, status, description, created_at)
        VALUES 
            (?, ?, ?, ?, ?, 'voucher_redeem', 'completed', ?, NOW())
    ");
    $stmt->execute([
        $settlement['account_id'],
        $request_user_id,
        'SUSPENSE_ACC',
        'SETTLEMENT_ACC',
        $amount,
        "Redeemed voucher: " . $voucher_number
    ]);

    // This is the Step 1 Reference logic you requested
    $step1Reference = $pdo->lastInsertId();

    $pdo->commit();

    jsonResponse('success', 'Voucher redeemed successfully', [
        'voucher_number' => $redeemedVoucher['voucher_number'],
        'sat_purchased' => $redeemedVoucher['sat_purchased'],
        'amount' => $amount,
        'settlement_balance' => $new_settlement_balance,
        'transaction_id' => $step1Reference,
        'step1_reference' => $step1Reference
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " | ERROR: " . $e->getMessage() . PHP_EOL, FILE_APPEND);
    jsonResponse('error', $e->getMessage());
}
