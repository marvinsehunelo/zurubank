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
// RSA SIGNATURE FUNCTIONS FOR BANK-TO-BANK TRUST
// Uses RSA keys (asymmetric) - Same as Visa/Mastercard
// trusted_partners table stores RSA PUBLIC KEYS
// ============================================================

/**
 * Get public key for a requester from trusted_partners table
 * Used to verify incoming signatures from VouchMorph or other banks
 */
function get_requester_public_key($requester, $pdo)
{
    error_log("get_requester_public_key called for: {$requester}");
    
    try {
        $stmt = $pdo->prepare("
            SELECT public_key 
            FROM trusted_partners 
            WHERE name = :name 
            AND is_active = true
            LIMIT 1
        ");
        $stmt->execute([':name' => $requester]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && !empty($row['public_key'])) {
            error_log("Found RSA public key for {$requester} in trusted_partners table");
            return $row['public_key'];
        }
        
        error_log("No public key found for requester: {$requester}");
        return null;
        
    } catch (Exception $e) {
        error_log("Database error getting public key: " . $e->getMessage());
        return null;
    }
}

/**
 * Sign a payload with RSA private key
 * This proves the response came from Zurubank (non-repudiation)
 */
function sign_payload($payload, $privateKey = null)
{
    if (!$privateKey) {
        // Get RSA private key from environment (Railway vault)
        $privateKeyContent = getenv('ZURUBANK_PRIVATE_KEY');
        if (!$privateKeyContent) {
            error_log("ZURUBANK_PRIVATE_KEY not found in environment");
            return null;
        }
        $privateKey = openssl_pkey_get_private($privateKeyContent);
        if (!$privateKey) {
            error_log("Failed to load private key: " . openssl_error_string());
            return null;
        }
    }
    
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['_timestamp' => $timestamp]);
    $payloadJson = json_encode($payloadWithTimestamp);
    
    // Generate RSA signature
    $signature = '';
    $success = openssl_sign($payloadJson, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    
    if (!$success) {
        error_log("Failed to sign payload: " . openssl_error_string());
        return null;
    }
    
    return [
        'signature' => base64_encode($signature),
        'timestamp' => $timestamp,
        'signed_payload' => $payloadWithTimestamp
    ];
}

/**
 * Send a signed JSON response using RSA
 * Use this for all responses that other banks need to trust
 */
function send_signed_response($payload, $httpCode = 200)
{
    $signed = sign_payload($payload);
    
    if (!$signed) {
        // Fallback to unsigned response if signing fails
        error_log("WARNING: Sending unsigned response due to signing failure");
        http_response_code($httpCode);
        echo json_encode($payload);
        exit;
    }
    
    $response = array_merge($payload, [
        'signature' => $signed['signature'],
        'timestamp' => $signed['timestamp']
    ]);
    
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}

/**
 * Verify an incoming RSA signature from another institution
 * Used when receiving requests from VouchMorph or other banks
 */
function verify_signature($payload, $signature, $publicKey, $timestamp = null, $maxAgeSeconds = 300)
{
    // Prevent replay attacks (like Visa does)
    if ($timestamp && abs(time() - $timestamp) > $maxAgeSeconds) {
        error_log("Signature rejected: timestamp too old (age: " . abs(time() - $timestamp) . "s)");
        return false;
    }
    
    // If timestamp was in the payload, include it for verification
    if ($timestamp) {
        $payloadToVerify = array_merge($payload, ['_timestamp' => $timestamp]);
    } else {
        $payloadToVerify = $payload;
    }
    
    $payloadJson = json_encode($payloadToVerify);
    
    // Verify RSA signature using openssl
    $result = openssl_verify(
        $payloadJson,
        base64_decode($signature),
        $publicKey,
        OPENSSL_ALGO_SHA256
    );
    
    $isValid = ($result === 1);
    error_log("RSA Signature verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
    
    if ($result === -1) {
        error_log("Signature verification error: " . openssl_error_string());
    }
    
    return $isValid;
}

/**
 * Verify incoming request signature from requester (wrapper for verify_signature)
 * This is called by verify_asset.php, hold.php, etc.
 */
function verify_requester_signature($input, $pdo)
{
    $signature = $input['signature'] ?? null;
    $timestamp = $input['timestamp'] ?? null;
    $requester = $input['requester'] ?? 'VOUCHMORPH';
    
    // Extract the actual payload (without signature and timestamp)
    $payloadToVerify = [];
    foreach ($input as $key => $value) {
        if (!in_array($key, ['signature', 'timestamp', '_timestamp'])) {
            $payloadToVerify[$key] = $value;
        }
    }
    
    if (!$signature) {
        error_log("Missing signature from {$requester}");
        return ['valid' => false, 'message' => 'Missing signature'];
    }
    
    $publicKey = get_requester_public_key($requester, $pdo);
    
    if (!$publicKey) {
        error_log("No public key found for requester: {$requester}");
        return ['valid' => false, 'message' => "No public key found for {$requester}"];
    }
    
    $isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);
    
    if (!$isValid) {
        error_log("Invalid signature from {$requester}");
        return ['valid' => false, 'message' => 'Invalid signature'];
    }
    
    error_log("Signature verified from {$requester}");
    return ['valid' => true, 'message' => 'Signature verified'];
}
