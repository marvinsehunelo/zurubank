<?php
// zurubank/Backend/helpers/crypto.php

require_once __DIR__ . '/CertificateManager.php';

/**
 * Get requester public key (now extracts from certificate)
 * Matches SACCUSSALIS implementation
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
    
    // Legacy fallback - database lookup
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
            error_log("Found public key for {$requester} in trusted_partners table");
            return $row['public_key'];
        }
    } catch (Exception $e) {
        error_log("Database error getting public key: " . $e->getMessage());
    }
    
    // Environment fallback
    $envKeyName = strtoupper($requester) . '_PUBLIC_KEY';
    $envKey = getenv($envKeyName);
    if ($envKey) {
        error_log("Using public key from env for {$requester}");
        return str_replace(['\\n', '\n'], "\n", $envKey);
    }
    
    error_log("No public key found for requester: {$requester}");
    return null;
}

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
 * Clean and format private key properly
 */
function clean_private_key($rawKey)
{
    // Replace literal \n and \\n with actual newlines
    $cleaned = str_replace(['\\n', '\n'], "\n", $rawKey);
    
    // Remove any extra whitespace at beginning/end
    $cleaned = trim($cleaned);
    
    // Check if key already has PEM headers
    if (strpos($cleaned, '-----BEGIN PRIVATE KEY-----') === false && 
        strpos($cleaned, '-----BEGIN RSA PRIVATE KEY-----') === false) {
        // No headers, assume it's just the base64 content
        // Add proper PEM headers
        $cleaned = "-----BEGIN PRIVATE KEY-----\n" . 
                   chunk_split($cleaned, 64, "\n") . 
                   "-----END PRIVATE KEY-----\n";
    }
    
    return $cleaned;
}

/**
 * Sign payload for outgoing response
 * CRITICAL: Must match exactly what VOUCHMORPH expects to verify
 * Matches SACCUSSALIS implementation
 */
function sign_payload($payload, $privateKey = null)
{
    if (!$privateKey) {
        $privateKeyContent = getenv('ZURUBANK_PRIVATE_KEY_CONTENT');
        if (!$privateKeyContent) {
            error_log("ZURUBANK: ZURUBANK_PRIVATE_KEY_CONTENT not found");
            return null;
        }
        
        // Clean and format the private key properly
        $privateKeyContent = clean_private_key($privateKeyContent);
        
        error_log("ZURUBANK: Private key length after cleaning: " . strlen($privateKeyContent));
        error_log("ZURUBANK: Private key starts with: " . substr($privateKeyContent, 0, 50));
        
        // Try multiple formats
        $privateKey = openssl_pkey_get_private($privateKeyContent);
        
        if (!$privateKey) {
            // Try with RSA specific header
            $rsaKeyContent = str_replace('-----BEGIN PRIVATE KEY-----', '-----BEGIN RSA PRIVATE KEY-----', $privateKeyContent);
            $rsaKeyContent = str_replace('-----END PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----', $rsaKeyContent);
            $privateKey = openssl_pkey_get_private($rsaKeyContent);
            
            if (!$privateKey) {
                $error = openssl_error_string();
                error_log("ZURUBANK: Failed to load private key: " . $error);
                return null;
            }
        }
        error_log("ZURUBANK: Private key loaded successfully");
    }
    
    // CRITICAL: VOUCHMORPH expects timestamp to be included in the signed payload
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
    
    // CRITICAL: VOUCHMORPH uses ksort before verification
    ksort($payloadWithTimestamp);
    
    // CRITICAL: Must use EXACT same JSON encoding as VOUCHMORPH
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
 * Matches SACCUSSALIS implementation
 */
function send_signed_response($payload, $httpCode = 200)
{
    // Clear any existing output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Sign the payload
    $signed = sign_payload($payload);
    
    if (!$signed) {
        error_log("ZURUBANK: Failed to sign response - sending unsigned");
        header('Content-Type: application/json');
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
    
    header('Content-Type: application/json');
    http_response_code($httpCode);
    echo json_encode($response);
    exit;
}

/**
 * Verify incoming request signature from requester
 * Matches SACCUSSALIS implementation
 */
function verify_requester_signature($input, $pdo)
{
    $signature = $input['signature'] ?? null;
    $timestamp = $input['timestamp'] ?? null;
    $requester = $input['requester'] ?? 'VOUCHMORPH';
    $certificate = $input['certificate'] ?? null;
    
    if (!$signature) {
        error_log("ZURUBANK: Missing signature from {$requester}");
        return ['valid' => false, 'message' => 'Missing signature'];
    }
    
    // If certificate is provided, use CertificateManager (bank-grade trust)
    if ($certificate) {
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
        
        // Build payload for verification (remove signature, certificate, keep timestamp)
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
        
        if ($isValid) {
            return ['valid' => true, 'message' => 'Certificate verified', 'requester' => $requester];
        } else {
            return ['valid' => false, 'message' => 'Invalid signature'];
        }
    }
    
    // Fallback: Get public key from database or env (legacy)
    $publicKey = get_requester_public_key($requester, $pdo);
    
    if (!$publicKey) {
        error_log("ZURUBANK: No public key found for requester: {$requester}");
        return ['valid' => false, 'message' => "No public key found for {$requester}"];
    }
    
    // Build payload for verification
    $payloadToVerify = [];
    foreach ($input as $key => $value) {
        if (!in_array($key, ['signature', 'certificate', 'timestamp', '_timestamp'])) {
            $payloadToVerify[$key] = $value;
        }
    }
    
    // If timestamp was in payload (not removed), include it
    if ($timestamp && !isset($payloadToVerify['timestamp'])) {
        $payloadToVerify['timestamp'] = $timestamp;
    }
    
    ksort($payloadToVerify);
    $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $decodedSig = base64_decode($signature);
    
    $keyResource = openssl_pkey_get_public($publicKey);
    $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
    $isValid = ($result === 1);
    
    error_log("ZURUBANK: Signature verification from {$requester}: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
    
    if ($result === -1) {
        error_log("ZURUBANK: Signature verification error: " . openssl_error_string());
    }
    
    return [
        'valid' => $isValid,
        'message' => $isValid ? 'Signature verified' : 'Invalid signature',
        'requester' => $requester
    ];
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
// UTILITY FUNCTIONS (preserved from original)
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
