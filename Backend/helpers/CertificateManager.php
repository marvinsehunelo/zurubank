<?php
// zurubank/Backend/helpers/CertificateManager.php
// ENHANCED DEBUGGING VERSION - Complete visibility into every operation

/**
 * Certificate Manager - Visa/Mastercard style PKI
 * Members present their certificate with each request.
 * Receivers verify against the trusted CA root.
 * NO manual key exchange needed for new members!
 */
class CertificateManager
{
    public ?string $caCert = null;           
    public ?string $myPrivateKey = null;      
    public ?string $myCertificate = null;     
    private ?string $myName = null;
    private array $debugLog = [];
    
    private function debug(string $message, array $context = []): void
    {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s.u'),
            'message' => $message,
            'context' => $context
        ];
        $this->debugLog[] = $logEntry;
        error_log("[CertificateManager DEBUG] " . $message . (!empty($context) ? " | " . json_encode($context) : ""));
    }
    
    public function getDebugLog(): array
    {
        return $this->debugLog;
    }
    
    public function __construct(?string $memberName = null)
    {
        $this->debug("Initializing CertificateManager", ['memberName' => $memberName]);
        
        $this->myName = $memberName ?? getenv('VOUCHMORPH_PARTNER_NAME') ?: 'ZURUBANK';
        $this->debug("Member name set", ['myName' => $this->myName]);
        
        // Load CA certificate (trust anchor)
        $caContent = getenv('VOUCHMORPH_CA_CERT_CONTENT');
        $this->debug("CA certificate env check", [
            'env_var' => 'VOUCHMORPH_CA_CERT_CONTENT',
            'exists' => $caContent !== false,
            'length' => $caContent ? strlen($caContent) : 0
        ]);
        
        if ($caContent) {
            $originalLength = strlen($caContent);
            $this->caCert = str_replace(['\\n', '\n'], "\n", $caContent);
            $this->debug("CA certificate loaded", [
                'original_length' => $originalLength,
                'after_normalization' => strlen($this->caCert),
                'has_begin_marker' => strpos($this->caCert, 'BEGIN CERTIFICATE') !== false,
                'has_end_marker' => strpos($this->caCert, 'END CERTIFICATE') !== false
            ]);
        } else {
            $this->debug("WARNING - No CA certificate found", ['member' => $this->myName]);
            error_log("CertificateManager: WARNING - No CA certificate found for {$this->myName}");
        }
        
        // Load and normalize this member's private key
        $envKeyName = $this->myName . '_PRIVATE_KEY_CONTENT';
        $privateKeyContent = getenv($envKeyName);
        
        $this->debug("Private key env check", [
            'env_var' => $envKeyName,
            'exists' => $privateKeyContent !== false,
            'length' => $privateKeyContent ? strlen($privateKeyContent) : 0,
            'has_newlines' => $privateKeyContent ? strpos($privateKeyContent, "\n") !== false : false,
            'has_literal_n' => $privateKeyContent ? strpos($privateKeyContent, '\\n') !== false : false
        ]);
        
        if ($privateKeyContent) {
            // Detailed key diagnostics
            $this->debug("=== PRIVATE KEY DIAGNOSTIC ===", [
                'raw_length' => strlen($privateKeyContent),
                'first_40_chars' => substr($privateKeyContent, 0, 40),
                'last_40_chars' => substr($privateKeyContent, -40),
                'has_begin_private_key' => strpos($privateKeyContent, 'BEGIN PRIVATE KEY') !== false,
                'has_begin_rsa_private_key' => strpos($privateKeyContent, 'BEGIN RSA PRIVATE KEY') !== false,
                'has_begin_encrypted' => strpos($privateKeyContent, 'BEGIN ENCRYPTED PRIVATE KEY') !== false,
                'has_literal_n' => strpos($privateKeyContent, '\\n') !== false,
                'has_actual_newlines' => strpos($privateKeyContent, "\n") !== false,
                'has_carriage_returns' => strpos($privateKeyContent, "\r") !== false,
                'starts_with_dashes' => strpos($privateKeyContent, '-----') === 0
            ]);
            
            // Check for common issues
            if (strpos($privateKeyContent, '\\n') !== false) {
                $this->debug("Found literal \\n sequences - will convert to actual newlines");
            }
            
            if (strpos($privateKeyContent, '-----BEGIN PRIVATE KEY-----') === false && 
                strpos($privateKeyContent, 'BEGIN PRIVATE KEY') !== false) {
                $this->debug("BEGIN PRIVATE KEY marker found but missing dashes - potential formatting issue");
            }
            
            // ONLY fix line endings - no reformatting or rebuilding
            $beforeFix = strlen($privateKeyContent);
            $this->myPrivateKey = trim(
                str_replace(['\\n', "\r"], ["\n", ""], $privateKeyContent)
            );
            $afterFix = strlen($this->myPrivateKey);
            
            $this->debug("Private key normalization", [
                'before_length' => $beforeFix,
                'after_length' => $afterFix,
                'difference' => $beforeFix - $afterFix,
                'has_proper_format' => strpos($this->myPrivateKey, "-----BEGIN PRIVATE KEY-----\n") !== false,
                'key_preview' => substr($this->myPrivateKey, 0, 100) . "..."
            ]);
            
            // Validate key format without loading (just check structure)
            $lineCount = substr_count($this->myPrivateKey, "\n");
            $this->debug("Private key structure", [
                'line_count' => $lineCount,
                'starts_correctly' => strpos($this->myPrivateKey, '-----BEGIN PRIVATE KEY-----') === 0,
                'ends_correctly' => strpos($this->myPrivateKey, '-----END PRIVATE KEY-----') !== false,
                'has_valid_base64' => preg_match('/[^A-Za-z0-9+\/=]/', substr($this->myPrivateKey, 27, -25)) === 0
            ]);
            
            error_log("CertificateManager: Private key loaded for {$this->myName}");
        } else {
            $this->debug("WARNING - No private key found", ['member' => $this->myName]);
            error_log("CertificateManager: WARNING - No private key found for {$this->myName}");
        }
        
        // Load this member's certificate
        $envCertName = $this->myName . '_CERT_CONTENT';
        $certContent = getenv($envCertName);
        
        $this->debug("Certificate env check", [
            'env_var' => $envCertName,
            'exists' => $certContent !== false,
            'length' => $certContent ? strlen($certContent) : 0
        ]);
        
        if ($certContent) {
            $originalLength = strlen($certContent);
            $this->myCertificate = str_replace(['\\n', '\n'], "\n", $certContent);
            $this->debug("Certificate loaded", [
                'original_length' => $originalLength,
                'after_normalization' => strlen($this->myCertificate),
                'has_begin_marker' => strpos($this->myCertificate, 'BEGIN CERTIFICATE') !== false,
                'has_end_marker' => strpos($this->myCertificate, 'END CERTIFICATE') !== false
            ]);
            error_log("CertificateManager: Certificate loaded for {$this->myName}");
        } else {
            $this->debug("WARNING - No certificate found", ['member' => $this->myName]);
            error_log("CertificateManager: WARNING - No certificate found for {$this->myName}");
        }
        
        $this->debug("CertificateManager initialization complete", [
            'has_ca' => $this->caCert !== null,
            'has_private_key' => $this->myPrivateKey !== null,
            'has_certificate' => $this->myCertificate !== null,
            'is_configured' => $this->isConfigured()
        ]);
    }
    
    /**
     * Verify a certificate against the trusted CA root
     */
    public function verifyCertificate(string $certificatePem): bool
    {
        $this->debug("Starting certificate verification", [
            'cert_length' => strlen($certificatePem),
            'has_begin_marker' => strpos($certificatePem, 'BEGIN CERTIFICATE') !== false
        ]);
        
        if (!$this->caCert) {
            $this->debug("Certificate verification FAILED - No CA certificate", []);
            error_log("CertificateManager: No CA certificate to verify against");
            return false;
        }
        
        // Step 1: Trust Anchor validation via Temp Files
        $tempCert = tempnam(sys_get_temp_dir(), 'cert_');
        $tempCA = tempnam(sys_get_temp_dir(), 'ca_');
        
        $this->debug("Created temp files for verification", [
            'temp_cert' => $tempCert,
            'temp_ca' => $tempCA
        ]);
        
        file_put_contents($tempCert, $certificatePem);
        file_put_contents($tempCA, $this->caCert);
        
        // Verify certificate chains to our trusted CA
        $cmd = "openssl verify -CAfile " . escapeshellarg($tempCA) . " " . escapeshellarg($tempCert) . " 2>&1";
        $this->debug("Running OpenSSL verify command", ['cmd' => $cmd]);
        
        exec($cmd, $output, $returnCode);
        $result = ($returnCode === 0);
        
        $this->debug("OpenSSL verify result", [
            'return_code' => $returnCode,
            'output' => $output,
            'result' => $result ? 'PASSED' : 'FAILED'
        ]);
        
        unlink($tempCert);
        unlink($tempCA);
        
        // Step 2: Native PHP Expiry Check
        try {
            $certData = openssl_x509_parse($certificatePem);
            $this->debug("X509 parsing result", [
                'success' => $certData !== false,
                'has_validTo' => isset($certData['validTo_time_t'])
            ]);
            
            if ($certData && isset($certData['validTo_time_t'])) {
                $expiryTimestamp = (int)$certData['validTo_time_t'];
                $currentTime = time();
                $isExpired = $expiryTimestamp < $currentTime;
                
                $this->debug("Certificate expiry check", [
                    'expires_at' => date('Y-m-d H:i:s', $expiryTimestamp),
                    'current_time' => date('Y-m-d H:i:s', $currentTime),
                    'is_expired' => $isExpired,
                    'seconds_until_expiry' => $expiryTimestamp - $currentTime
                ]);
                
                if ($isExpired) {
                    error_log("CertificateManager: Certificate has expired natively on " . date('Y-m-d H:i:s', $expiryTimestamp));
                    $result = false;
                }
            } else {
                $this->debug("Failed to parse X509 certificate metadata", [
                    'certData' => $certData ? array_keys($certData) : null
                ]);
                error_log("CertificateManager: Failed to parse X509 certificate metadata structure.");
                $result = false;
            }
        } catch (Exception $e) {
            $this->debug("Exception during native validation", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            error_log("CertificateManager: Exception during native validation: " . $e->getMessage());
            $result = false;
        }
        
        $this->debug("Certificate verification ultimate status", [
            'status' => $result ? "PASSED" : "FAILED"
        ]);
        error_log("CertificateManager: Certificate verification ultimate status: " . ($result ? "PASSED" : "FAILED"));
        return $result;
    }
    
    /**
     * Extract public key from a certificate
     */
    public function extractPublicKeyFromCert(string $certificatePem): ?string
    {
        $this->debug("Extracting public key from certificate", [
            'cert_length' => strlen($certificatePem)
        ]);
        
        $tempCert = tempnam(sys_get_temp_dir(), 'extract_');
        file_put_contents($tempCert, $certificatePem);
        
        $cmd = "openssl x509 -in " . escapeshellarg($tempCert) . " -pubkey -noout 2>&1";
        $this->debug("Running public key extraction", ['cmd' => $cmd]);
        
        $publicKey = shell_exec($cmd);
        
        unlink($tempCert);
        
        $hasPublicKey = $publicKey && strpos($publicKey, 'BEGIN PUBLIC KEY') !== false;
        $this->debug("Public key extraction result", [
            'success' => $hasPublicKey,
            'key_length' => $publicKey ? strlen($publicKey) : 0,
            'key_preview' => $publicKey ? substr($publicKey, 0, 100) : null
        ]);
        
        if ($hasPublicKey) {
            return $publicKey;
        }
        
        return null;
    }
    
    /**
     * Verify a signed request using certificate
     */
    public function verifySignedRequest(array $request): array
    {
        $this->debug("Starting signed request verification", [
            'request_keys' => array_keys($request),
            'has_certificate' => isset($request['certificate']),
            'has_signature' => isset($request['signature']),
            'has_requester' => isset($request['requester'])
        ]);
        
        $certificate = $request['certificate'] ?? null;
        $signature = $request['signature'] ?? null;
        $requester = $request['requester'] ?? 'UNKNOWN';
        
        if (!$certificate) {
            $this->debug("Verification FAILED - No certificate provided", ['requester' => $requester]);
            error_log("CertificateManager: No certificate provided from {$requester}");
            return ['verified' => false, 'message' => 'No certificate provided', 'requester' => $requester];
        }
        
        if (!$signature) {
            $this->debug("Verification FAILED - No signature provided", ['requester' => $requester]);
            error_log("CertificateManager: No signature provided from {$requester}");
            return ['verified' => false, 'message' => 'No signature provided', 'requester' => $requester];
        }
        
        // Step 1: Verify certificate chains to trusted CA
        $this->debug("Step 1: Verifying certificate chain", ['requester' => $requester]);
        if (!$this->verifyCertificate($certificate)) {
            $this->debug("Verification FAILED - Certificate not trusted", ['requester' => $requester]);
            error_log("CertificateManager: Certificate not trusted from {$requester}");
            return ['verified' => false, 'message' => 'Certificate not trusted', 'requester' => $requester];
        }
        
        // Step 2: Extract public key from certificate
        $this->debug("Step 2: Extracting public key", ['requester' => $requester]);
        $publicKey = $this->extractPublicKeyFromCert($certificate);
        if (!$publicKey) {
            $this->debug("Verification FAILED - Cannot extract public key", ['requester' => $requester]);
            error_log("CertificateManager: Cannot extract public key from certificate");
            return ['verified' => false, 'message' => 'Cannot extract public key', 'requester' => $requester];
        }
        
        // Step 3: Prepare payload for verification
        $payloadToVerify = $request;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['certificate']);
        unset($payloadToVerify['requester']);
        ksort($payloadToVerify);
        
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        $this->debug("Step 3: Prepared payload for verification", [
            'requester' => $requester,
            'payload_keys' => array_keys($payloadToVerify),
            'json_length' => strlen($jsonToVerify),
            'signature_length' => strlen($decodedSig),
            'json_preview' => substr($jsonToVerify, 0, 200)
        ]);
        
        // Step 4: Verify signature
        $this->debug("Step 4: Verifying signature with public key", ['requester' => $requester]);
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            $this->debug("Verification FAILED - Invalid public key format", [
                'requester' => $requester,
                'public_key_preview' => substr($publicKey, 0, 100)
            ]);
            error_log("CertificateManager: Invalid public key format");
            return ['verified' => false, 'message' => 'Invalid public key', 'requester' => $requester];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        $this->debug("Signature verification result", [
            'requester' => $requester,
            'openssl_result' => $result,
            'is_valid' => $isValid,
            'result_meaning' => $result === 1 ? 'VALID' : ($result === 0 ? 'INVALID' : 'ERROR')
        ]);
        
        error_log("CertificateManager: Request from {$requester} - Signature: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
        
        if ($result === -1) {
            $opensslError = openssl_error_string();
            $this->debug("OpenSSL error during verification", [
                'requester' => $requester,
                'error' => $opensslError
            ]);
            error_log("CertificateManager: OpenSSL error: " . $opensslError);
        }
        
        // Clean up
        openssl_free_key($keyResource);
        
        return [
            'verified' => $isValid,
            'requester' => $requester,
            'message' => $isValid ? 'Signature verified' : 'Invalid signature'
        ];
    }
    
    /**
     * Create signed request with certificate (for outgoing)
     * SIMPLIFIED: Direct key loading without multiple recovery methods
     */
    public function createSignedRequest(array $payload, string $requester): array
    {
        $this->debug("Starting signed request creation", [
            'requester' => $requester,
            'payload_keys' => array_keys($payload),
            'has_private_key' => $this->myPrivateKey !== null,
            'has_certificate' => $this->myCertificate !== null
        ]);
        
        if (!$this->myPrivateKey || !$this->myCertificate) {
            $this->debug("Signing FAILED - Missing private key or certificate", [
                'requester' => $requester,
                'myName' => $this->myName,
                'has_private_key' => $this->myPrivateKey !== null,
                'has_certificate' => $this->myCertificate !== null
            ]);
            error_log("CertificateManager: Cannot sign request - missing private key or certificate for {$this->myName}");
            return $payload;
        }
        
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
        ksort($payloadWithTimestamp);
        
        $jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $this->debug("Prepared payload for signing", [
            'requester' => $requester,
            'timestamp' => $timestamp,
            'json_length' => strlen($jsonToSign),
            'json_preview' => substr($jsonToSign, 0, 200),
            'payload_keys' => array_keys($payloadWithTimestamp)
        ]);
        
        $signature = '';
        
        // Load private key directly - NO complex recovery methods
        $this->debug("Loading private key", [
            'requester' => $requester,
            'key_length' => strlen($this->myPrivateKey),
            'key_format' => strpos($this->myPrivateKey, 'BEGIN PRIVATE KEY') !== false ? 'PKCS#8' : 
                           (strpos($this->myPrivateKey, 'BEGIN RSA PRIVATE KEY') !== false ? 'PKCS#1' : 'UNKNOWN')
        ]);
        
        $keyResource = openssl_pkey_get_private($this->myPrivateKey);
        
        if (!$keyResource) {
            $this->debug("Private key load FAILED", [
                'requester' => $requester,
                'myName' => $this->myName,
                'key_preview_first' => substr($this->myPrivateKey, 0, 100),
                'key_preview_last' => substr($this->myPrivateKey, -100)
            ]);
            
            error_log("CertificateManager: Private key load FAILED");
            
            $opensslErrors = [];
            while ($err = openssl_error_string()) {
                $opensslErrors[] = $err;
                error_log("OpenSSL Error: " . $err);
            }
            
            $this->debug("OpenSSL errors", ['errors' => $opensslErrors]);
            
            error_log("Key first 40 chars: " . substr($this->myPrivateKey, 0, 40));
            error_log("Key last 40 chars: " . substr($this->myPrivateKey, -40));
            
            return $payload;
        }
        
        $this->debug("Private key loaded successfully", ['requester' => $requester]);
        
        $signResult = openssl_sign($jsonToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        
        $this->debug("OpenSSL sign operation", [
            'requester' => $requester,
            'result' => $signResult,
            'signature_length' => strlen($signature)
        ]);
        
        if (!$signResult) {
            $this->debug("Signing operation FAILED", [
                'requester' => $requester,
                'openssl_error' => openssl_error_string()
            ]);
        }
        
        // Clean up key resource
        openssl_free_key($keyResource);
        
        $signedPayload = array_merge($payloadWithTimestamp, [
            'signature' => base64_encode($signature),
            'requester' => $requester,
            'certificate' => $this->myCertificate
        ]);
        
        $this->debug("Signed request created successfully", [
            'requester' => $requester,
            'signature_b64_length' => strlen(base64_encode($signature)),
            'certificate_length' => strlen($this->myCertificate),
            'final_payload_keys' => array_keys($signedPayload)
        ]);
        
        return $signedPayload;
    }
    
    public function isConfigured(): bool
    {
        $configured = ($this->caCert !== null && $this->myPrivateKey !== null && $this->myCertificate !== null);
        $this->debug("Configuration check", [
            'has_ca' => $this->caCert !== null,
            'has_private_key' => $this->myPrivateKey !== null,
            'has_certificate' => $this->myCertificate !== null,
            'is_configured' => $configured
        ]);
        return $configured;
    }
}
