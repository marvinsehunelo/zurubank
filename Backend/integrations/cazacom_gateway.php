<?php
// cazacom_gateway.php — send SMS via CazaCom REST API and log to DB
// Usage: require_once __DIR__ . '/cazacom_gateway.php'; 
// and call sendSmsToCazaCom($to, $message, $sender);

require_once __DIR__ . '/../config/db.php';
$config = require __DIR__ . '/../config/integration.php';

/**
 * Send SMS to CazaCom REST API, log result to 'sms' table.
 * Returns array with status and provider response.
 */
// sendSmsToCazaCom for local CazaCom XAMPP
function sendSmsToCazaCom(string $to, string $message, string $sender = null, int $user_id = 0, int $attempt = 0): array {
    global $config;

    $url = $config['CAZACOM_SMS_URL'];

    // Payload matches CazaCom API
    $payload = [
        'recipient_number' => $to,
        'message' => $message
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $success = ($http >= 200 && $http < 300);

    // Log SMS locally in your instant_money_vouchers table or CazaCom sms table
    try {
        $pdo = $GLOBALS['pdo']; // make sure $pdo is global
        $stmt = $pdo->prepare("
            INSERT INTO sms (user_id, sender_number, target_number, message, cost, status, provider_response, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $statusText = $success ? 'sent' : 'failed';
        $stmt->execute([
            $user_id,
            $sender ?? 'SYSTEM',
            $to,
            $message,
            0.00,
            $statusText,
            $resp ?? $err
        ]);
    } catch (Exception $e) {
        error_log("Failed to log SMS: " . $e->getMessage());
    }

    if ($success) return ['status' => 'success', 'http' => $http, 'response' => $resp];

    // Retry up to 3 times
    if ($attempt < 3) {
        sleep(pow(2, $attempt));
        return sendSmsToCazaCom($to, $message, $sender, $user_id, $attempt + 1);
    }

    return ['status' => 'error', 'http' => $http, 'error' => $err, 'response' => $resp];
}
