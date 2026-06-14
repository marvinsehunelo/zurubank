<?php
// zurubank/Backend/helpers/crypto.php

require_once __DIR__ . '/CertificateManager.php';

/**
 * JSON canonicalization - used for VERIFYING signatures
 * This matches what VOUCHMORPH uses to verify
 * CRITICAL: Must match VOUCHMORPH's canonicalization exactly
 */
function canonicalize_payload(array $payload): string
{
    // For VERIFYING incoming requests - remove signature fields
    unset($payload['signature']);
    unset($payload['certificate']);
    // Keep requester and timestamp for verification
    ksort($payload);
    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Sign payload for outgoing response using CertificateManager
 * CRITICAL: Must match exactly what VOUCHMORPH expects to verify
 */
function sign_payload($payload)
{
    $certManager = new CertificateManager();
    
    if (!$certManager->isConfigured()) {
        error_log("ZURUBANK: CertificateManager not configured - cannot sign response");
        return null;
    }
    
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
    ksort($payloadWithTimestamp);
    
    $payloadJson = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    error_log("ZURUBANK: Signing payload length: " . strlen($payloadJson));
    
    $signature = '';
    $privateKey = $certManager->getMyPrivateKey();
    
    if (!$privateKey) {
        error_log("ZURUBANK: No private key available for signing");
        return null;
    }
    
    $signResult = openssl_sign($payloadJson, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    
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
 * Uses certificate-based signing only (no RSA fallback)
 */
function send_signed_response($payload, $httpCode = 200)
{
    // Clear any existing output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $certManager = new CertificateManager();
    
    // Check if CertificateManager is properly configured
    if (!$certManager->isConfigured()) {
        error_log("ZURUBANK: CertificateManager not configured - cannot send signed response");
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'CertificateManager not configured'
        ]);
        exit;
    }
    
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
    
    // Get certificate
    $certContent = $certManager->getMyCertificate();
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
    if (isset($logResponse['signature'])) {
        $logResponse['signature'] = '[SIGNATURE LENGTH: ' . strlen($logResponse['signature']) . ']';
    }
    error_log("ZURUBANK: Sending signed response: " . json_encode($logResponse));
    
    header('Content-Type: application/json');
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}

/**
 * Verify incoming request signature from requester
 * Uses certificate-based verification only (no RSA fallback)
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
        return ['valid' => false, 'message' => 'Certificate required - please upgrade to certificate-based authentication'];
    }
    
    $certManager = new CertificateManager();
    
    // Verify the certificate is valid and signed by trusted CA
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
    
    // Build payload for verification (remove signature and certificate, keep all other fields)
    $payloadToVerify = [];
    foreach ($input as $key => $value) {
        if (!in_array($key, ['signature', 'certificate'])) {
            $payloadToVerify[$key] = $value;
        }
    }
    
    // CRITICAL: Must use same canonicalization as VOUCHMORPH
    ksort($payloadToVerify);
    $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $decodedSig = base64_decode($signature);
    
    $keyResource = openssl_pkey_get_public($publicKey);
    if (!$keyResource) {
        error_log("ZURUBANK: Invalid public key resource");
        return ['valid' => false, 'message' => 'Invalid public key'];
    }
    
    $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
    $isValid = ($result === 1);
    
    error_log("ZURUBANK: Certificate verification from {$requester} - Signature: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
    
    if ($result === -1) {
        error_log("ZURUBANK: OpenSSL verification error: " . openssl_error_string());
    }
    
    if ($isValid) {
        return ['valid' => true, 'message' => 'Certificate verified', 'requester' => $requester];
    } else {
        return ['valid' => false, 'message' => 'Invalid signature'];
    }
}

/**
 * Helper: Verify and get authenticated requester
 * Use this at the start of all API endpoints
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

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

function hash_token($token, $pin) {
    return hash('sha256', $token . $pin);
}

function generate_auth_code() {
    return random_int(100000, 999999);
}

function generate_trace() {
    return uniqid("ZRUBNK");
}
