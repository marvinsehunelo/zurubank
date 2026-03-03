<?php
// zurubank/backend/cron/retry_callbacks.php
require_once __DIR__ . '/../config/db.php';
// This assumes you logged failed callbacks into table central_callbacks with columns id, url, payload, attempts, next_attempt_at, status
$stmt = $pdo->prepare("SELECT * FROM central_callbacks WHERE status = 'pending' AND next_attempt_at <= NOW() LIMIT 50");
$stmt->execute();
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    $ch = curl_init($r['url']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $r['payload']);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-CB-Callback-Timestamp: '.gmdate('Y-m-d\TH:i:s\Z')]);
    $resp = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($err || !$resp) {
        $attempts = $r['attempts'] + 1;
        $next = date('Y-m-d H:i:s', time() + pow(2, min($attempts,6)) * 60); // exponential backoff minutes
        $stmtUpd = $pdo->prepare("UPDATE central_callbacks SET attempts = ?, next_attempt_at = ?, last_error = ?, updated_at = NOW() WHERE id = ?");
        $stmtUpd->execute([$attempts, $next, $err ?: $resp, $r['id']]);
    } else {
        $stmtUpd = $pdo->prepare("UPDATE central_callbacks SET status='sent', last_response = ?, updated_at = NOW() WHERE id = ?");
        $stmtUpd->execute([$resp, $r['id']]);
    }
}
