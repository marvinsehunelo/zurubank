<?php
// zurubank/Backend/helpers/crypto.php

function hash_token($token, $pin) {
    return hash('sha256', $token . $pin);
}

function generate_auth_code() {
    return random_int(100000, 999999);
}

function generate_trace() {
    return uniqid("ZRUBNK");
}

// ============================================================
// NEW: SIGNATURE FUNCTIONS FOR BANK-TO-BANK TRUST
// ============================================================

/**
 * Generate HMAC signature for a payload
 * This proves the response came from Zurubank
 */
function sign_payload($payload, $privateKey = null)
{
    if (!$privateKey) {
        // Get from environment (Railway vault)
        $privateKey = getenv('ZURUBANK_PRIVATE_KEY');
    }
    
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['_timestamp' => $timestamp]);
    $payloadJson = json_encode($payloadWithTimestamp);
    $signature = hash_hmac('sha256', $payloadJson, $privateKey);
    
    return [
        'signature' => base64_encode($signature),
        'timestamp' => $timestamp,
        'signed_payload' => $payloadWithTimestamp
    ];
}

/**
 * Send a signed JSON response
 * Use this for all responses that other banks need to trust
 */
function send_signed_response($payload, $httpCode = 200)
{
    $signed = sign_payload($payload);
    
    $response = array_merge($payload, [
        'signature' => $signed['signature'],
        'timestamp' => $signed['timestamp']
    ]);
    
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}

/**
 * Verify a signature from another institution
 * Used when receiving webhooks/callbacks
 */
function verify_signature($payload, $signature, $publicKey, $timestamp = null, $maxAgeSeconds = 300)
{
    // Prevent replay attacks (optional)
    if ($timestamp && abs(time() - $timestamp) > $maxAgeSeconds) {
        return false;
    }
    
    // If timestamp was in the payload, include it
    if ($timestamp) {
        $payloadToVerify = array_merge($payload, ['_timestamp' => $timestamp]);
    } else {
        $payloadToVerify = $payload;
    }
    
    $payloadJson = json_encode($payloadToVerify);
    $expectedSignature = base64_encode(hash_hmac('sha256', $payloadJson, $publicKey, true));
    
    return hash_equals($expectedSignature, $signature);
}
