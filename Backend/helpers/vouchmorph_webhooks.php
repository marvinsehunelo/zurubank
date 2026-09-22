<?php
/**
 * Signed, reliable notifications to VouchMorph (cash dispensed at an ATM /
 * agent). Shared, identical file in ZuruBank and SaccusSalis.
 *
 * - Signed: X-Bank-Code, X-Timestamp and X-Signature = HMAC-SHA256 of
 *   "<timestamp>.<body>" with VOUCHMORPH_WEBHOOK_SECRET (a secret shared only
 *   between this bank and VouchMorph). VouchMorph rejects anything unsigned.
 * - Reliable: waits up to 45 s; if VouchMorph does not confirm, the
 *   notification is queued in vouchmorph_notifications and resent by
 *   vm_retry_notifications() (scheduled every 5 minutes) until it is
 *   confirmed. VouchMorph completes a code exactly once, so resending is safe.
 */

function vm_webhook_ensure_table(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vouchmorph_notifications (
            id           BIGSERIAL PRIMARY KEY,
            url          TEXT NOT NULL,
            payload      JSONB NOT NULL,
            status       VARCHAR(12) NOT NULL DEFAULT 'PENDING',
            attempts     INT NOT NULL DEFAULT 0,
            last_error   TEXT,
            created_at   TIMESTAMP NOT NULL DEFAULT NOW(),
            next_try_at  TIMESTAMP NOT NULL DEFAULT NOW(),
            confirmed_at TIMESTAMP
        )
    ");
}

function vm_webhook_send(string $url, array $payload, string $bankCode): array
{
    $secret = getenv('VOUCHMORPH_WEBHOOK_SECRET') ?: '';
    $body = json_encode($payload);
    $ts = (string)time();
    $headers = ['Content-Type: application/json', 'X-Bank-Code: ' . $bankCode, 'X-Timestamp: ' . $ts];
    if ($secret !== '') $headers[] = 'X-Signature: ' . hash_hmac('sha256', $ts . '.' . $body, $secret);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['success' => false, 'message' => $err];
    if ($code < 200 || $code >= 300) return ['success' => false, 'message' => "HTTP {$code}", 'response' => $response];
    return ['success' => true, 'response' => json_decode((string)$response, true)];
}

/** Sends now; on failure queues it for retry. Returns whether VouchMorph confirmed now. */
function vm_notify(PDO $pdo, string $url, array $payload, string $bankCode): array
{
    $r = vm_webhook_send($url, $payload, $bankCode);
    if (!$r['success']) {
        try {
            vm_webhook_ensure_table($pdo);
            $pdo->prepare("INSERT INTO vouchmorph_notifications (url, payload, attempts, last_error, next_try_at) VALUES (?, ?, 1, ?, NOW() + INTERVAL '2 minutes')")
                ->execute([$url, json_encode($payload), (string)$r['message']]);
            $r['queued_for_retry'] = true;
        } catch (Throwable $e) {
            error_log('[vouchmorph_webhook] could not queue notification: ' . $e->getMessage());
        }
    }
    return $r;
}

/** Resends queued notifications (scheduled job). */
function vm_retry_notifications(PDO $pdo, string $bankCode, int $limit = 50): array
{
    vm_webhook_ensure_table($pdo);
    $rows = $pdo->query("SELECT * FROM vouchmorph_notifications WHERE status = 'PENDING' AND next_try_at <= NOW() ORDER BY id LIMIT " . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
    $stats = ['due' => count($rows), 'confirmed' => 0, 'still_failing' => 0];
    foreach ($rows as $n) {
        $r = vm_webhook_send($n['url'], json_decode($n['payload'], true) ?: [], $bankCode);
        if ($r['success']) {
            $pdo->prepare("UPDATE vouchmorph_notifications SET status = 'CONFIRMED', confirmed_at = NOW(), attempts = attempts + 1 WHERE id = ?")->execute([$n['id']]);
            $stats['confirmed']++;
        } else {
            // back off: 5 min, then longer, up to 1 hour between tries; give up after 48 attempts (flagged FAILED)
            $pdo->prepare("UPDATE vouchmorph_notifications SET attempts = attempts + 1, last_error = ?,
                             next_try_at = NOW() + LEAST(INTERVAL '1 hour', INTERVAL '5 minutes' * (attempts + 1)),
                             status = CASE WHEN attempts + 1 >= 48 THEN 'FAILED' ELSE 'PENDING' END WHERE id = ?")
                ->execute([(string)$r['message'], $n['id']]);
            $stats['still_failing']++;
        }
    }
    return $stats;
}
