<?php
// zurubank/Backend/helpers/crypto.php

require_once __DIR__ . '/CertificateManager.php';

/**
 * Sign payload for outgoing response
 * Uses the private key string directly (like SACCUSSALIS)
 */
function sign_payload($payload, $privateKey = null)
{
    if (!$privateKey) {
        $certManager = new CertificateManager();
        $privateKey = $certManager->myPrivateKey;  // Direct property access
        if (!$privateKey) {
            error_log("ZURUBANK: No private key available for signing");
            return null;
        }
    }
    
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
    ksort($payloadWithTimestamp);
    
    $payloadJson = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    error_log("ZURUBANK: Signing payload length: " . strlen($payloadJson));
    
    $signature = '';
    $keyResource = openssl_pkey_get_private($privateKey);
    if (!$keyResource) {
        error_log("ZURUBANK: Failed to load private key resource: " . openssl_error_string());
        return null;
    }
    
    $signResult = openssl_sign($payloadJson, $signature, $keyResource, OPENSSL_ALGO_SHA256);
    
    if (!$signResult) {
        error_log("ZURUBANK: Failed to sign payload - " . openssl_error_string());
        return null;
    }
    
    $encodedSignature = base64_encode($signature);
    error_log("ZURUBANK: Generated signature length: " . strlen($encodedSignature));
    
    return [
        'signature' => $encodedSignature,
        'timestamp' => $timestamp
    ];
}

/**
 * Send signed response with certificate
 */
function send_signed_response($payload, $httpCode = 200)
{
    // Clear any existing output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $certManager = new CertificateManager();
    
    // Sign the payload
    $signed = sign_payload($payload);
    
    if (!$signed) {
        error_log("ZURUBANK: Failed to sign response - sending error");
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Failed to sign response'
        ]);
        exit;
    }
    
    // Get certificate directly from property
    $certContent = $certManager->myCertificate;
    if (!$certContent) {
        error_log("ZURUBANK: No certificate available");
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'No certificate available'
        ]);
        exit;
    }
    
    // Build response exactly as VOUCHMORPH expects
    $response = array_merge($payload, [
        'signature' => $signed['signature'],
        'timestamp' => $signed['timestamp'],
        'certificate' => $certContent
    ]);
    
    // Log the response structure (without full cert for brevity)
    $logResponse = $response;
    if (isset($logResponse['certificate'])) {
        $logResponse['certificate'] = '[CERTIFICATE LENGTH: ' . strlen($logResponse['certificate']) . ']';
    }
    error_log("ZURUBANK: Sending signed response");
    
    header('Content-Type: application/json');
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}

/**
 * Verify incoming request signature from requester
 */
function verify_requester_signature($input, $pdo)
{
    $signature = $input['signature'] ?? null;
    $certificate = $input['certificate'] ?? null;
    $requester = $input['requester'] ?? 'UNKNOWN';
    
    if (!$signature) {
        error_log("ZURUBANK: Missing signature from {$requester}");
        return ['valid' => false, 'message' => 'Missing signature'];
    }
    
    if (!$certificate) {
        error_log("ZURUBANK: Missing certificate from {$requester}");
        return ['valid' => false, 'message' => 'Certificate required'];
    }
    
    $certManager = new CertificateManager();
    
    // Verify the certificate
    if (!$certManager->verifyCertificate($certificate)) {
        error_log("ZURUBANK: Invalid certificate from {$requester}");
        return ['valid' => false, 'message' => 'Invalid certificate'];
    }
    
    // Extract public key from certificate
    $publicKey = $certManager->extractPublicKeyFromCert($certificate);
    if (!$publicKey) {
        error_log("ZURUBANK: Cannot extract public key from certificate");
        return ['valid' => false, 'message' => 'Cannot extract public key'];
    }
    
    // Build payload for verification
    $payloadToVerify = [];
    foreach ($input as $key => $value) {
        if (!in_array($key, ['signature', 'certificate'])) {
            $payloadToVerify[$key] = $value;
        }
    }
    
    ksort($payloadToVerify);
    $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $decodedSig = base64_decode($signature);
    
    $keyResource = openssl_pkey_get_public($publicKey);
    if (!$keyResource) {
        return ['valid' => false, 'message' => 'Invalid public key'];
    }
    
    $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
    $isValid = ($result === 1);
    
    error_log("ZURUBANK: Signature verification from {$requester}: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
    
    return [
        'valid' => $isValid,
        'message' => $isValid ? 'Signature verified' : 'Invalid signature',
        'requester' => $requester
    ];
}

/**
 * Helper: Verify and get authenticated requester
 */
function authenticate_request($pdo, $requiredRole = null)
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $verification = verify_requester_signature($input, $pdo);
    
    if (!$verification['valid']) {
        send_signed_response([
            'success' => false,
            'error' => $verification['message'],
            'verified' => false
        ], 401);
        exit;
    }
    
    return $verification['requester'];
}

// Utility functions
function hash_token($token, $pin) {
    return hash('sha256', $token . $pin);
}

function generate_auth_code() {
    return random_int(100000, 999999);
}

function generate_trace() {
    return uniqid("ZRUBNK");
}
