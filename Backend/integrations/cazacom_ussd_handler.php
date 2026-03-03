<?php
// ussm_gateway.php — send SMS via USSM REST API and log to DB
// Usage: require_once __DIR__ . '/ussm_gateway.php'; 
// and call sendSmsToUSSM($to, $message, $sender);

require_once __DIR__ . '/../config/db.php';
$config = require __DIR__ . '/../config/integration.php';

/**
 * Send SMS to USSM REST API, log result to 'sms' table.
 * Returns array with status and provider response.
 */
function sendSmsToUSSM(string $to, string $message, string $sender = null, int $attempt = 0): array {
    global $pdo, $config;

    $url = $config['USSM_SMS_URL'];
    $apiKey = $config['USSM_API_KEY'];
    $from = $sender ?? $config['SYSTEM_SENDER_NUMBER'];

    $payload = [
        'to' => $to,
        'from' => $from,
        'text' => $message
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $success = ($http >= 200 && $http < 300);

    // Log attempt to sms table
    try {
        $stmt = $pdo->prepare("
            INSERT INTO sms (user_id, sender_number, target_number, message, cost, status, provider_response, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $statusText = $success ? 'sent' : 'failed';
        $stmt->execute([null, $from, $to, $message, 0.00, $statusText, $resp ?? $err]);
    } catch (Exception $e) {
        error_log("Failed to log SMS: " . $e->getMessage());
    }

    if ($success) {
        return ['status' => 'success', 'http' => $http, 'response' => $resp];
    }

    // Retry with exponential backoff up to 3 attempts
    if ($attempt < 3) {
        sleep(pow(2, $attempt));
        return sendSmsToUSSM($to, $message, $sender, $attempt + 1);
    }

    return ['status' => 'error', 'http' => $http, 'error' => $err, 'response' => $resp];
}
