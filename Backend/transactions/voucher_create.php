<?php
// voucher_create.php — creates a ZuruBank voucher moving funds from Settlement to Suspense
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/integration.php';

$db = $pdo;

/**
 * Sends a JSON response and terminates the script.
 */
function jsonResponse(string $status, string $message, array $extra = []): void {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

// --- Send SMS via CazaCom API ---
function sendSmsToCazaCom(string $recipient, string $message, string $apiKey): array {
    $url = "http://localhost/CazaCom/backend/routes/send_sms.php";
    $payload = ['target_number' => $recipient, 'message' => $message];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey
        ],
        CURLOPT_POSTFIELDS      => json_encode($payload),
        CURLOPT_TIMEOUT         => 5
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['status' => 'error', 'error' => $error];
    return json_decode($response, true) ?: ['status' => 'success'];
}

try {
    $cazacom_api_key = 'CAZACOM_LOCAL_KEY_123';

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?: $_POST;

    $recipient_phone = $input['recipient_phone'] ?? $input['to_phone'] ?? null;
    $amount = (float) ($input['amount'] ?? 0);
    $sat_purchased = !empty($input['sat_purchased']); 

    // --- 1. Identify System User dynamically ---
    $stmt = $db->prepare("SELECT user_id FROM users WHERE email = 'system@zurubank.com' LIMIT 1");
    $stmt->execute();
    $sysUser = $stmt->fetch();
    $system_user_id = $sysUser ? (int)$sysUser['user_id'] : 2;

    // --- 2. AUTH ---
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = trim($headers['Authorization'] ?? '');
    $apiKeyHeader = $headers['X-API-Key'] ?? null;

    if ($apiKeyHeader === 'ZURU_LOCAL_KEY_ABC123') {
        $request_user_id = $system_user_id;
    } elseif ($token) {
        if (str_starts_with($token, 'Bearer ')) $token = substr($token, 7);
        $stmt = $db->prepare("SELECT user_id, phone FROM sessions WHERE token = ? AND (expires_at > NOW()) LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) jsonResponse('error', 'Invalid token');
        $request_user_id = (int) $user['user_id'];
        if (!$recipient_phone) $recipient_phone = $user['phone'];
    } else {
        jsonResponse('error', 'Auth required');
    }

    if (!$recipient_phone || $amount <= 0) jsonResponse('error', 'Invalid phone or amount');
    $recipient_phone_norm = '+' . ltrim(preg_replace('/\D/', '', $recipient_phone), '+');

    // --- 3. TRANSACTION START ---
    $db->beginTransaction();

    // Lock Settlement Account (Source)
    $stmt = $db->prepare("SELECT account_id, balance FROM accounts WHERE account_type = 'partner_bank_settlement' FOR UPDATE");
    $stmt->execute();
    $settlement = $stmt->fetch();
    if (!$settlement) throw new Exception('Settlement account not found');

    // Lock Suspense Account (Destination)
    $stmt = $db->prepare("SELECT account_id, balance FROM accounts WHERE account_type = 'voucher_suspense' FOR UPDATE");
    $stmt->execute();
    $suspense = $stmt->fetch();
    if (!$suspense) throw new Exception('Suspense account not found');

    if ((float)$settlement['balance'] < $amount) throw new Exception('Insufficient balance in settlement account');

    // MOVE MONEY: Settlement (-) -> Suspense (+)
    $new_settlement_balance = (float)$settlement['balance'] - $amount;
    $db->prepare("UPDATE accounts SET balance = ? WHERE account_id = ?")->execute([$new_settlement_balance, $settlement['account_id']]);
    $db->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")->execute([$amount, $suspense['account_id']]);

    // --- 4. RECORD TRANSACTION ---
    $stmt = $db->prepare("
        INSERT INTO transactions
        (user_id, account_id, from_account, to_account, amount, type, status, description, created_at)
        VALUES (?, ?, ?, ?, ?, 'voucher_send', 'completed', ?, NOW())
        RETURNING transaction_id
    ");
    $stmt->execute([
        $request_user_id,
        $suspense['account_id'],
        'SETTLEMENT_ACC',
        'SUSPENSE_ACC',
        $amount,
        "Voucher funding held in suspense for $recipient_phone_norm"
    ]);
    $transaction_id = (int) $stmt->fetchColumn();

    // --- 5. GENERATE VOUCHER ---
    $voucher_number = str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT);
    $voucher_pin = str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

    $stmt = $db->prepare("
        INSERT INTO instant_money_vouchers
        (voucher_number, voucher_pin, amount, created_by, status, sat_purchased, created_at, recipient_phone, holding_account)
        VALUES (?, ?, ?, ?, 'active', ?, NOW(), ?, 'VOUCHER-SUSPENSE')
    ");
    $stmt->execute([
        $voucher_number,
        $voucher_pin,
        $amount,
        $request_user_id,
        $sat_purchased,
        $recipient_phone_norm
    ]);

    $db->commit();

    // --- 6. SEND NOTIFICATION ---
    $smsText = "ZuruBank: You received BWP {$amount}. Voucher: {$voucher_number}, PIN: {$voucher_pin}.";
    $smsResult = sendSmsToCazaCom($recipient_phone_norm, $smsText, $cazacom_api_key);

    jsonResponse('success', 'Voucher created and funds held in suspense', [
        'transaction_id'     => $transaction_id,
        'voucher_number'     => $voucher_number,
        'amount'             => $amount,
        'settlement_balance' => $new_settlement_balance,
        'sms'                => $smsResult
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    jsonResponse('error', $e->getMessage());
}
