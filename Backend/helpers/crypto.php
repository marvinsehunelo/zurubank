<?php
// zurubank/Backend/helpers/crypto.php

require_once __DIR__ . '/CertificateManager.php';

/**
 * Get requester public key (now extracts from certificate)
 */
function get_requester_public_key($requester, $pdo)
{
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['certificate'])) {
        $certManager = new CertificateManager();
        $publicKey = $certManager->extractPublicKeyFromCert($input['certificate']);
        if ($publicKey) {
            error_log("Extracted public key from certificate for {$requester}");
            return $publicKey;
        }
    }
    
    // Legacy fallback
    $envKeyName = strtoupper($requester) . '_PUBLIC_KEY';
    $envKey = getenv($envKeyName);
    if ($envKey) {
        return str_replace(['\\n', '\n'], "\n", $envKey);
    }
    
    return null;
}

/**
 * JSON canonicalization - used for VERIFYING signatures
 * This matches what VOUCHMORPH uses to verify
 */
function canonicalize_payload(array $payload): string
{
    // For VERIFYING incoming requests - remove signature fields
    unset($payload['signature']);
    unset($payload['certificate']);
    // Keep requester for verification
    ksort($payload);
    return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Sign payload for outgoing response
 * CRITICAL: Must match exactly what VOUCHMORPH expects to verify
 * FOLLOWS SACCUSSALIS PATTERN EXACTLY
 */
function sign_payload($payload, $privateKey = null)
{
    if (!$privateKey) {
        // Get the private key from CertificateManager (already loaded and working)
        $certManager = new CertificateManager();
        $privateKeyString = $certManager->myPrivateKey;
        
        if (!$privateKeyString) {
            error_log("ZURUBANK: No private key available from CertificateManager");
            return null;
        }
        
        error_log("ZURUBANK: Private key from CertificateManager length: " . strlen($privateKeyString));
        error_log("ZURUBANK: Private key first 50 chars: " . substr($privateKeyString, 0, 50));
        
        // The key should already be in the correct format from CertificateManager
        $privateKey = openssl_pkey_get_private($privateKeyString);
        
        if (!$privateKey) {
            error_log("ZURUBANK: Failed to load private key from CertificateManager: " . openssl_error_string());
            
            // Try to clean it more aggressively
            $cleaned = trim($privateKeyString);
            $cleaned = str_replace(['\\n', '\\r', '\r'], "\n", $cleaned);
            $cleaned = str_replace("\r", "", $cleaned);
            
            // Ensure proper PEM format
            if (strpos($cleaned, '-----BEGIN PRIVATE KEY-----') !== false) {
                // Extract the body
                preg_match('/-----BEGIN PRIVATE KEY-----\n?(.*?)\n?-----END PRIVATE KEY-----/s', $cleaned, $matches);
                if (isset($matches[1])) {
                    $body = preg_replace('/\s/', '', $matches[1]);
                    $chunked = chunk_split($body, 64, "\n");
                    $cleaned = "-----BEGIN PRIVATE KEY-----\n" . trim($chunked) . "\n-----END PRIVATE KEY-----\n";
                    error_log("ZURUBANK: Reformatted private key");
                }
            }
            
            $privateKey = openssl_pkey_get_private($cleaned);
            if (!$privateKey) {
                error_log("ZURUBANK: Still failed after reformatting: " . openssl_error_string());
                return null;
            }
        }
        error_log("ZURUBANK: Private key loaded successfully from CertificateManager");
    }
    
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
    ksort($payloadWithTimestamp);
    
    $payloadJson = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    error_log("ZURUBANK: Signing payload length: " . strlen($payloadJson));
    
    $signature = '';
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
 * This is what generate_code.php calls
 */
function send_signed_response($payload, $httpCode = 200)
{
    // Sign the payload
    $signed = sign_payload($payload);
    
    if (!$signed) {
        error_log("ZURUBANK: Failed to sign response - sending unsigned");
        http_response_code($httpCode);
        echo json_encode($payload);
        exit;
    }
    
    // Get certificate
    $certContent = getenv('ZURUBANK_CERT_CONTENT');
    if ($certContent) {
        $certContent = str_replace(['\\n', '\n'], "\n", $certContent);
        error_log("ZURUBANK: Certificate loaded, length: " . strlen($certContent));
    } else {
        error_log("ZURUBANK: WARNING - No certificate content found in environment");
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
    error_log("ZURUBANK: Sending signed response: " . json_encode($logResponse));
    
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}

/**
 * Verify a signed response (for ZURUBANK to verify VOUCHMORPH responses)
 */
function verify_signed_response($response, $expectedRequester = 'VOUCHMORPH')
{
    $certificate = $response['certificate'] ?? null;
    $signature = $response['signature'] ?? null;
    $requester = $response['requester'] ?? 'UNKNOWN';
    
    if (!$certificate || !$signature) {
        error_log("ZURUBANK: Missing certificate or signature in response");
        return false;
    }
    
    $certManager = new CertificateManager();
    
    // Verify certificate
    if (!$certManager->verifyCertificate($certificate)) {
        error_log("ZURUBANK: Certificate verification failed for {$requester}");
        return false;
    }
    
    // Extract public key
    $publicKey = $certManager->extractPublicKeyFromCert($certificate);
    if (!$publicKey) {
        error_log("ZURUBANK: Cannot extract public key");
        return false;
    }
    
    // Build payload for verification - keep requester and timestamp
    $payloadToVerify = $response;
    unset($payloadToVerify['signature']);
    unset($payloadToVerify['certificate']);
    ksort($payloadToVerify);
    
    $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $decodedSig = base64_decode($signature);
    
    $keyResource = openssl_pkey_get_public($publicKey);
    $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
    $isValid = ($result === 1);
    
    error_log("ZURUBANK: Response from {$requester} - Signature: " . ($isValid ? "VALID" : "INVALID"));
    
    return $isValid;
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
    if (!in_array($key, ['signature', 'certificate', 'requester'], true)) {
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
