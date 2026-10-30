<?php
// Backend/integrations/kycbank/webhook.php
//
// Public endpoint KYC Bank POSTs consent and profile events to:
//     https://<zurubank domain>/Backend/integrations/kycbank/webhook.php
// No admin session; every request must carry a valid x-kyc-signature
// (HMAC-SHA256 with KYCBANK_WEBHOOK_SECRET over "<t>.<raw body>").
//
// Replies: 200 for events processed, already processed (same event id) or
// deliberately ignored (test ping, unknown consent); 401 for a missing or
// bad signature; 5xx only for a transient failure KYC Bank should retry.
// Replies and logs carry ids only, never profile content.

// The raw body, exactly as signed, before anything decodes it.
$raw = (string)file_get_contents('php://input');

header('Content-Type: application/json');

function kb_webhook_reply(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    kb_webhook_reply(405, ['error' => 'method_not_allowed']);
}

require_once __DIR__ . '/KycBankClient.php';

$secret = KycBankClient::webhookSecret();
if ($secret === '') {
    error_log('[KYCBank webhook] KYCBANK_WEBHOOK_SECRET is not set; refusing delivery so KYC Bank retries later');
    kb_webhook_reply(503, ['error' => 'not_configured']);
}

$signature = $_SERVER['HTTP_X_KYC_SIGNATURE'] ?? null;
if (!KycBankClient::verifyWebhookSignature($raw, $signature, $secret, time())) {
    error_log('[KYCBank webhook] rejected delivery ' . substr(preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_SERVER['HTTP_X_KYC_DELIVERY'] ?? '')), 0, 80) . ': bad or missing signature');
    kb_webhook_reply(401, ['error' => 'invalid_signature']);
}

$event = json_decode($raw, true);
$eventId   = is_array($event) ? (string)($event['id'] ?? '') : '';
$eventType = is_array($event) ? (string)($event['event'] ?? '') : '';
$consentId = is_array($event) ? (string)($event['consentId'] ?? '') : '';
if ($eventId === '' || $eventType === '' || strlen($eventId) > 80 || strlen($consentId) > 64) {
    kb_webhook_reply(400, ['error' => 'bad_payload']);
}

// Test pings from the KYC Bank portal: acknowledge, change nothing.
if (!empty($event['test']) || $consentId === 'cns_test') {
    error_log('[KYCBank webhook] test ping ' . $eventId . ' acknowledged');
    kb_webhook_reply(200, ['received' => true, 'eventId' => $eventId, 'result' => 'ignored', 'detail' => 'test']);
}

require_once __DIR__ . '/../../config/db.php';   // exits with 500 if the database is down (KYC Bank retries)
require_once __DIR__ . '/kycbank.php';

try {
    kb_ensure_schema($pdo);
} catch (Throwable $e) {
    error_log('[KYCBank webhook] schema check failed for event ' . $eventId . ': ' . get_class($e));
    kb_webhook_reply(500, ['error' => 'temporary_failure']);
}

$requestId = null;
try {
    $pdo->beginTransaction();

    // Claim the event id. The row lock makes a parallel redelivery wait, then see it processed.
    $pdo->prepare('INSERT INTO kycbank_webhook_events (event_id, event, consent_id) VALUES (?, ?, ?) ON CONFLICT (event_id) DO NOTHING')
        ->execute([$eventId, $eventType, $consentId !== '' ? $consentId : null]);
    $st = $pdo->prepare('SELECT processed_at FROM kycbank_webhook_events WHERE event_id = ? FOR UPDATE');
    $st->execute([$eventId]);
    if ($st->fetchColumn()) {
        $pdo->commit();
        kb_webhook_reply(200, ['received' => true, 'eventId' => $eventId, 'result' => 'duplicate']);
    }

    $row = $consentId !== '' ? kb_request_by_consent($pdo, $consentId) : null;
    $requestId = $row ? (int)$row['id'] : null;

    try {
        $outcome = kb_handle_event($pdo, KycBankClient::fromEnv(), $event);
    } catch (KycBankException $e) {
        if ($e->isTransient()) {
            throw $e;
        }
        // KYC Bank refused the follow-up call (e.g. not_found); retrying will not help. Keep what was recorded.
        if ($requestId) {
            kb_set_error($pdo, $requestId, $e->errorCode);
        }
        $outcome = ['result' => 'processed', 'detail' => 'error:' . $e->errorCode];
    }

    $pdo->prepare('UPDATE kycbank_webhook_events SET processed_at = NOW() WHERE event_id = ?')->execute([$eventId]);
    $pdo->commit();

    error_log('[KYCBank webhook] event ' . $eventId . ' (' . $eventType . ') consent ' . $consentId
        . ($requestId ? ' request ' . $requestId : '') . ': ' . $outcome['result'] . ' ' . $outcome['detail']);
    kb_webhook_reply(200, ['received' => true, 'eventId' => $eventId, 'result' => $outcome['result'], 'detail' => $outcome['detail']]);
} catch (KycBankException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($requestId) {
        try { kb_set_error($pdo, $requestId, $e->errorCode); } catch (Throwable $ignored) {}
    }
    error_log('[KYCBank webhook] event ' . $eventId . ' consent ' . $consentId . ' will be retried: ' . $e->errorCode . ' (HTTP ' . $e->httpStatus . ')');
    kb_webhook_reply(503, ['error' => 'temporary_failure', 'eventId' => $eventId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[KYCBank webhook] event ' . $eventId . ' consent ' . $consentId . ' failed: ' . get_class($e));
    kb_webhook_reply(500, ['error' => 'temporary_failure', 'eventId' => $eventId]);
}
