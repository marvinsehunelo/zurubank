<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
header("Content-Type: application/json; charset=utf-8");

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . '/../config/integration.php';

// LOGGING
$logDir = __DIR__ . '/../../APP_LAYER/logs';
if (!is_dir($logDir)) mkdir($logDir, 0777, true);
$debugLog = $logDir . '/voucher_redeem_reverse_debug.log';

function jsonResponse(string $status, string $message, array $extra = []): void {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

function dbg(string $line): void {
    global $debugLog;
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " | " . $line . PHP_EOL, FILE_APPEND);
}

function sendSmsToCazaComDirect(string $recipient, string $message, ?int $userId = null): array {
    $url = "http://localhost/CazaCom/backend/routes/send_sms.php";
    $payload = ['target_number' => $recipient, 'message' => $message, 'user_id' => $userId ?? 9999];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: CAZACOM_LOCAL_KEY_123'],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true) ?: ['status' => 'error'];
}

try {
    // ----------------------
    // 1. DYNAMIC SYSTEM USER
    // ----------------------
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = 'system@zurubank.com' LIMIT 1");
    $stmt->execute();
    $sysUser = $stmt->fetch();
    $system_user_id = $sysUser ? (int)$sysUser['user_id'] : 2;

    // ----------------------
    // 2. AUTHENTICATION
    // ----------------------
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $bearer = $headers['Authorization'] ?? ($_GET['token'] ?? null);
    $apiKey = $headers['X-API-Key'] ?? ($_POST['api_key'] ?? null);

    if ($bearer && str_starts_with($bearer, 'Bearer ')) $bearer = trim(substr($bearer, 7));

    $reverser_id = null;
    if ($apiKey === 'ZURU_LOCAL_KEY_ABC123') {
        $reverser_id = $system_user_id;
    } elseif ($bearer) {
        $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? AND (expires_at > NOW()) LIMIT 1");
        $stmt->execute([$bearer]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new Exception("Invalid token");
        $reverser_id = (int)$s['user_id'];
    } else {
        throw new Exception("Token or API key required");
    }

    // ----------------------
    // 3. INPUT
    // ----------------------
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $voucher_number = trim($input['voucher_number'] ?? '');
    if (!$voucher_number) throw new Exception('voucher_number required for reversal.');

    $pdo->beginTransaction();

    // ----------------------
    // 4. LOCK VOUCHER
    // ----------------------
    $stmt = $pdo->prepare("SELECT * FROM instant_money_vouchers WHERE voucher_number = ? AND status = 'redeemed' FOR UPDATE");
    $stmt->execute([$voucher_number]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) throw new Exception("Voucher not found or not currently redeemed.");

    $amount = (float)$voucher['amount'];
    $recipient_phone = $voucher['recipient_phone'] ?? '';

    // ----------------------
    // 5. ACCOUNT MOVEMENTS (SETTLEMENT -> SUSPENSE)
    // ----------------------
    
    // Lock Settlement (Source of recovery)
    $stmt = $pdo->prepare("SELECT account_id, balance FROM accounts WHERE account_type = 'partner_bank_settlement' FOR UPDATE");
    $stmt->execute();
    $settlement = $stmt->fetch();
    if (!$settlement) throw new Exception("Settlement account not found");
    if ((float)$settlement['balance'] < $amount) throw new Exception("Insufficient settlement balance to perform reversal");

    // Lock Suspense (Destination for restoration)
    $stmt = $pdo->prepare("SELECT account_id FROM accounts WHERE account_type = 'voucher_suspense' FOR UPDATE");
    $stmt->execute();
    $suspense = $stmt->fetch();
    if (!$suspense) throw new Exception("Suspense account not found");

    // EXECUTE UPDATES
    $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?")
        ->execute([$amount, $settlement['account_id']]);

    $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")
        ->execute([$amount, $suspense['account_id']]);

    // ----------------------
    // 6. RESTORE VOUCHER STATUS
    // ----------------------
    $pdo->prepare("UPDATE instant_money_vouchers SET status = 'active', redeemed_by = NULL, redeemed_at = NULL WHERE voucher_number = ?")
        ->execute([$voucher_number]);

    // ----------------------
    // 7. RECORD LEDGER
    // ----------------------
    $stmt = $pdo->prepare("
        INSERT INTO transactions
        (user_id, account_id, from_account, to_account, amount, type, status, description, created_at)
        VALUES (?, ?, ?, ?, ?, 'voucher_reverse', 'completed', ?, NOW())
        RETURNING transaction_id
    ");
    $stmt->execute([
        $reverser_id,
        $suspense['account_id'],
        'SETTLEMENT_ACC',
        'SUSPENSE_ACC',
        $amount,
        "Reversal of redemption for voucher $voucher_number"
    ]);
    $reverse_transaction_id = (int)$stmt->fetchColumn();

    $pdo->commit();

    // ----------------------
    // 8. NOTIFICATION
    // ----------------------
    $smsMessage = "ZuruBank: Voucher redemption for BWP {$amount} (Voucher: {$voucher_number}) has been reversed. Voucher is now ACTIVE.";
    $smsResult = sendSmsToCazaComDirect($recipient_phone, $smsMessage, $reverser_id);

    jsonResponse('success', 'Voucher reversal successful. Funds moved from settlement to suspense.', [
        'voucher_number' => $voucher_number,
        'reversed_amount' => $amount,
        'reverse_transaction_id' => $reverse_transaction_id,
        'sms' => $smsResult
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    dbg("ERROR: " . $e->getMessage());
    jsonResponse('error', $e->getMessage());
}
