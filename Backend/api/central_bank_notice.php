<?php
// zurubank/Backend/api/central_bank_notice.php
//
// Shared handling for settlement notices from the central bank, used by
// bank_callback.php (ZuruBank's customers) and absa_central_bank_callback.php
// (ABSA's customers, simulated on this server).
//
// Every notice must carry the central bank's signature:
//   X-CB-Callback-Timestamp: <UTC ISO 8601>, at most five minutes old
//   X-CB-Callback-Signature: sha256=<HMAC-SHA256 of "<timestamp>.<raw body>"
//                            with CENTRAL_BANK_CALLBACK_SECRET>
// Each (transfer, role, bank) is processed once, so a repeated notice can
// never credit or refund twice.
//   role sender    approved -> the outgoing transfer is completed
//                  rejected -> the customer is refunded
//   role recipient approved -> the named account is credited

function cbn_reply(int $code, string $status, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => $status, 'message' => $message]);
    exit;
}

/** Verifies the signature and returns [raw body, decoded notice]. Exits on failure. */
function cbn_read_verified(): array {
    $raw = file_get_contents('php://input');
    $h = array_change_key_case(function_exists('getallheaders') ? getallheaders() : [], CASE_LOWER);
    $ts = (string)($h['x-cb-callback-timestamp'] ?? ($_SERVER['HTTP_X_CB_CALLBACK_TIMESTAMP'] ?? ''));
    $sig = (string)($h['x-cb-callback-signature'] ?? ($_SERVER['HTTP_X_CB_CALLBACK_SIGNATURE'] ?? ''));
    $secret = getenv('CENTRAL_BANK_CALLBACK_SECRET');
    if (!$secret) { error_log('[central_bank_notice] CENTRAL_BANK_CALLBACK_SECRET not set'); cbn_reply(503, 'error', 'Not configured'); }
    $when = strtotime($ts);
    if (!$when || abs(time() - $when) > 300) cbn_reply(401, 'error', 'Missing or stale timestamp');
    if (!str_starts_with($sig, 'sha256=') || !hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secret), substr($sig, 7))) {
        cbn_reply(401, 'error', 'Invalid signature');
    }
    $n = json_decode($raw, true);
    if (!is_array($n) || empty($n['transfer_id']) || empty($n['status']) || empty($n['role'])) cbn_reply(400, 'error', 'Invalid notice');
    return [$raw, $n];
}

/** Records the notice once. Returns false if this bank already processed it. Call inside a transaction. */
function cbn_claim(PDO $pdo, string $bank, array $n, string $raw): bool {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS central_bank_notices (
            bank_code VARCHAR(20) NOT NULL,
            transfer_id BIGINT NOT NULL,
            role VARCHAR(10) NOT NULL,
            status VARCHAR(20) NOT NULL,
            payload JSONB,
            processed_at TIMESTAMP NOT NULL DEFAULT NOW(),
            PRIMARY KEY (bank_code, transfer_id, role)
        )
    ");
    $stmt = $pdo->prepare("INSERT INTO central_bank_notices (bank_code, transfer_id, role, status, payload) VALUES (?, ?, ?, ?, ?::jsonb) ON CONFLICT DO NOTHING");
    $stmt->execute([$bank, (int)$n['transfer_id'], $n['role'] === 'recipient' ? 'recipient' : 'sender', (string)$n['status'], $raw]);
    return $stmt->rowCount() === 1;
}
