<?php

/**
 * Send SMS via CazaCom Telecom
 */

function sendSms(string $to, string $message): bool
{
    $config = [
        'base_url' => 'https://cazacom-production.up.railway.app',
        'endpoint' => '/api.php?path=sms/send',
        'sender' => 'SACCUSSALIS',
        'timeout' => 30,
        'api_key_header' => 'X-API-Key',
        'api_key' => getenv('CAZACOM_API_KEY')
    ];

    if (empty($config['api_key'])) {
        error_log('[SMS] Missing CAZACOM_API_KEY');
        return false;
    }

    $reference = 'SMS_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));

    $payload = [
        'recipient_number' => $to,
        'message'          => $message,
        'sender'           => $config['sender'],
        'reference'        => $reference
    ];

    $url = rtrim($config['base_url'], '/') . $config['endpoint'];

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            $config['api_key_header'] . ': ' . $config['api_key']
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $config['timeout']
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        error_log('[SMS] CURL Error: ' . curl_error($ch));
        curl_close($ch);
        return false;
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    error_log("[SMS] HTTP $httpCode");
    error_log("[SMS] Response: $response");

    $json = json_decode($response, true);

    if (!$json) {
        error_log('[SMS] Invalid JSON');
        return false;
    }

    if (
        isset($json['status']) &&
        strtolower($json['status']) === 'success'
    ) {

        error_log('[SMS] Sent successfully');

        if (!empty($json['message_id'])) {
            error_log('[SMS] Message ID: ' . $json['message_id']);
        }

        return true;
    }

    error_log('[SMS] Gateway Error: ' . $response);

    return false;
}
