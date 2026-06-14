<?php
// zurubank/Backend/helpers/CertificateManager.php

/**
 * Certificate Manager - Visa/Mastercard style PKI
 * 
 * Members present their certificate with each request.
 * Receivers verify against the trusted CA root.
 * NO manual key exchange needed for new members!
 */
class CertificateManager
{
    private ?string $caCert = null;
    private $myPrivateKey = null;  // Changed from ?string to store resource
    private ?string $myCertificate = null;
    private ?string $myName = null;
    
    public function __construct(?string $vouchmorphpartnerName = null)
    {
        $this->myName = $vouchmorphpartnerName ?? getenv('VOUCHMORPH_PARTNER_NAME') ?: 'ZURUBANK';
        
        // Load CA certificate (trust anchor)
        $caContent = getenv('VOUCHMORPH_CA_CERT_CONTENT');
        if ($caContent) {
            $this->caCert = $this->normalizePemContent($caContent);
            error_log("CertificateManager: CA certificate loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No CA certificate found for {$this->myName}");
        }
        
        // Load this member's private key
        $privateKeyContent = getenv($this->myName . '_PRIVATE_KEY_CONTENT');
        if ($privateKeyContent) {
            $normalizedKey = $this->normalizePemContent($privateKeyContent);
            // Pre-load private key as resource
            $this->myPrivateKey = $this->loadPrivateKey($normalizedKey);
            if ($this->myPrivateKey) {
                error_log("CertificateManager: Private key loaded for {$this->myName}");
            } else {
                error_log("CertificateManager: ERROR - Failed to load private key for {$this->myName}");
            }
        } else {
            error_log("CertificateManager: WARNING - No private key found for {$this->myName}");
        }
        
        // Load this member's certificate
        $certContent = getenv($this->myName . '_CERT_CONTENT');
        if ($certContent) {
            $this->myCertificate = $this->normalizePemContent($certContent);
            error_log("CertificateManager: Certificate loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No certificate found for {$this->myName}");
        }
    }
    
    /**
     * Normalize PEM content - FIX for long keys
     * Ensures proper line breaks and removes any corruption
     */
    private function normalizePemContent(string $content): string
    {
        // First, replace any escaped newlines
        $content = str_replace(['\\n', '\n', '\\r', '\r'], "\n", $content);
        
        // Remove any carriage returns
        $content = str_replace("\r", "", $content);
        
        // Trim whitespace
        $content = trim($content);
        
        // Check if it's a valid PEM format
        if (preg_match('/-----BEGIN (.*?)-----/', $content, $matches)) {
            // Extract the PEM type
            $pemType = $matches[1];
            
            // Extract body between BEGIN and END
            preg_match('/-----BEGIN ' . preg_quote($pemType) . '-----(.*?)-----END ' . preg_quote($pemType) . '-----/s', $content, $bodyMatches);
            
            if (isset($bodyMatches[1])) {
                // Remove all whitespace from the body
                $body = preg_replace('/\s/', '', $bodyMatches[1]);
                
                // Re-chunk into 64-character lines
                $chunkedBody = chunk_split($body, 64, "\n");
                
                // Rebuild the PEM
                $content = "-----BEGIN {$pemType}-----\n" . trim($chunkedBody) . "\n-----END {$pemType}-----\n";
            }
        }
        
        return $content;
    }
    
    /**
     * Load private key with multiple attempts for different formats
     */
    private function loadPrivateKey(string $keyContent)
    {
        // Attempt 1: Direct load
        $key = openssl_pkey_get_private($keyContent);
        if ($key) {
            return $key;
        }
        
        // Attempt 2: Try with explicit null passphrase
        $key = openssl_pkey_get_private($keyContent, null);
        if ($key) {
            return $key;
        }
        
        // Attempt 3: Extract from PEM if it's wrapped in a certificate
        if (strpos($keyContent, 'BEGIN CERTIFICATE') !== false) {
            $tempCert = tempnam(sys_get_temp_dir(), 'cert_');
            file_put_contents($tempCert, $keyContent);
            $cmd = "openssl x509 -in " . escapeshellarg($tempCert) . " -pubkey -noout 2>&1";
            $pubKey = shell_exec($cmd);
            unlink($tempCert);
            if ($pubKey) {
                $key = openssl_pkey_get_public($pubKey);
                if ($key) {
                    error_log("CertificateManager: Extracted public key from certificate");
                    return $key;
                }
            }
        }
        
        // Attempt 4: Try PKCS#8 conversion
        $tempKey = tempnam(sys_get_temp_dir(), 'key_');
        file_put_contents($tempKey, $keyContent);
        
        // Try to convert to PKCS#1 if it's PKCS#8
        $cmd = "openssl rsa -in " . escapeshellarg($tempKey) . " -out " . escapeshellarg($tempKey . '.pem') . " 2>&1";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($tempKey . '.pem')) {
            $convertedKey = file_get_contents($tempKey . '.pem');
            $key = openssl_pkey_get_private($convertedKey);
            if ($key) {
                error_log("CertificateManager: Converted PKCS#8 to PKCS#1 successfully");
                unlink($tempKey);
                unlink($tempKey . '.pem');
                return $key;
            }
        }
        
        // Attempt 5: Increase memory limit and try again
        ini_set('memory_limit', '1G');
        $key = openssl_pkey_get_private($keyContent);
        if ($key) {
            error_log("CertificateManager: Loaded private key with increased memory");
            return $key;
        }
        
        // Log the error for debugging
        $errorMsg = openssl_error_string();
        error_log("CertificateManager: All private key loading attempts failed. Last error: " . ($errorMsg ?: 'Unknown'));
        
        return null;
    }
    
    /**
     * Get CA certificate (trust anchor)
     */
    public function getCACertificate(): ?string
    {
        return $this->caCert;
    }
    
    /**
     * Get this member's certificate
     */
    public function getMyCertificate(): ?string
    {
        return $this->myCertificate;
    }
    
    /**
     * Get this member's private key resource
     */
    public function getMyPrivateKey()
    {
        return $this->myPrivateKey;
    }
    
    /**
     * Get private key as PEM string (if needed)
     */
    public function getMyPrivateKeyAsString(): ?string
    {
        if ($this->myPrivateKey) {
            openssl_pkey_export($this->myPrivateKey, $output);
            return $output;
        }
        return null;
    }
    
    /**
     * Check if CertificateManager is properly configured
     */
    public function isConfigured(): bool
    {
        return ($this->caCert !== null && $this->myPrivateKey !== null && $this->myCertificate !== null);
    }
    
    /**
     * Verify a certificate against the trusted CA root
     */
    public function verifyCertificate(string $certificatePem): bool
    {
        if (!$this->caCert) {
            error_log("CertificateManager: No CA certificate to verify against");
            return false;
        }
        
        $certificatePem = $this->normalizePemContent($certificatePem);
        
        // Write to temp files for openssl
        $tempCert = tempnam(sys_get_temp_dir(), 'cert_');
        $tempCA = tempnam(sys_get_temp_dir(), 'ca_');
        
        file_put_contents($tempCert, $certificatePem);
        file_put_contents($tempCA, $this->caCert);
        
        // Verify certificate chains to our trusted CA
        $cmd = "openssl verify -CAfile " . escapeshellarg($tempCA) . " " . escapeshellarg($tempCert) . " 2>&1";
        exec($cmd, $output, $returnCode);
        $result = ($returnCode === 0);
        
        // Also check certificate is not expired
        $expiryCmd = "openssl x509 -in " . escapeshellarg($tempCert) . " -noout -enddate 2>&1";
        exec($expiryCmd, $expiryOutput);
        foreach ($expiryOutput as $line) {
            if (preg_match('/notAfter=(.*)/', $line, $matches)) {
                $expiryDate = strtotime($matches[1]);
                if ($expiryDate < time()) {
                    error_log("CertificateManager: Certificate has expired");
                    $result = false;
                }
            }
        }
        
        unlink($tempCert);
        unlink($tempCA);
        
        error_log("CertificateManager: Certificate verification: " . ($result ? "PASSED" : "FAILED"));
        return $result;
    }
    
    /**
     * Extract public key from a certificate
     */
    public function extractPublicKeyFromCert(string $certificatePem): ?string
    {
        $certificatePem = $this->normalizePemContent($certificatePem);
        
        $tempCert = tempnam(sys_get_temp_dir(), 'extract_');
        file_put_contents($tempCert, $certificatePem);
        
        $cmd = "openssl x509 -in " . escapeshellarg($tempCert) . " -pubkey -noout 2>&1";
        $publicKey = shell_exec($cmd);
        
        unlink($tempCert);
        
        if ($publicKey && strpos($publicKey, 'BEGIN PUBLIC KEY') !== false) {
            return $this->normalizePemContent($publicKey);
        }
        
        error_log("CertificateManager: Failed to extract public key from certificate");
        return null;
    }
    
    /**
     * Verify a signed request using certificate
     * This matches what VOUCHMORPH does for verification
     */
    public function verifySignedRequest(array $request): array
    {
        $certificate = $request['certificate'] ?? null;
        $signature = $request['signature'] ?? null;
        $requester = $request['requester'] ?? 'UNKNOWN';
        
        if (!$certificate) {
            error_log("CertificateManager: No certificate provided from {$requester}");
            return ['verified' => false, 'message' => 'No certificate provided', 'requester' => $requester];
        }
        
        if (!$signature) {
            error_log("CertificateManager: No signature provided from {$requester}");
            return ['verified' => false, 'message' => 'No signature provided', 'requester' => $requester];
        }
        
        // Normalize certificate content
        $certificate = $this->normalizePemContent($certificate);
        
        // Step 1: Verify certificate chains to trusted CA
        if (!$this->verifyCertificate($certificate)) {
            error_log("CertificateManager: Certificate not trusted from {$requester}");
            return ['verified' => false, 'message' => 'Certificate not trusted', 'requester' => $requester];
        }
        
        // Step 2: Extract public key from certificate
        $publicKey = $this->extractPublicKeyFromCert($certificate);
        if (!$publicKey) {
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
        
        // Step 4: Verify signature using openssl
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            error_log("CertificateManager: Invalid public key format");
            return ['verified' => false, 'message' => 'Invalid public key', 'requester' => $requester];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        error_log("CertificateManager: Request from {$requester} - Signature: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
        
        if ($result === -1) {
            error_log("CertificateManager: OpenSSL error: " . openssl_error_string());
        }
        
        return [
            'verified' => $isValid,
            'requester' => $requester,
            'message' => $isValid ? 'Signature verified' : 'Invalid signature'
        ];
    }
    
    /**
     * Create signed request with certificate (for outgoing)
     * This is used when ZURUBANK sends requests to other institutions
     */
    public function createSignedRequest(array $payload, string $requester): array
    {
        if (!$this->myPrivateKey || !$this->myCertificate) {
            error_log("CertificateManager: Cannot sign request - missing private key or certificate for {$this->myName}");
            return $payload;
        }
        
        // CRITICAL: Must match what VOUCHMORPH expects for verification
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
        ksort($payloadWithTimestamp);
        
        $jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        error_log("CertificateManager: Signing payload for {$requester}");
        
        $signature = '';
        $signResult = openssl_sign($jsonToSign, $signature, $this->myPrivateKey, OPENSSL_ALGO_SHA256);
        
        if (!$signResult) {
            error_log("CertificateManager: Failed to sign payload - " . openssl_error_string());
            return $payload;
        }
        
        $encodedSignature = base64_encode($signature);
        error_log("CertificateManager: Generated signature length: " . strlen($encodedSignature));
        
        return array_merge($payloadWithTimestamp, [
            'signature' => $encodedSignature,
            'requester' => $requester,
            'certificate' => $this->myCertificate
        ]);
    }
}

// Helper function for sending signed responses (add this at the end of the file if not exists elsewhere)
if (!function_exists('send_signed_response')) {
    function send_signed_response($data) {
        $certManager = new CertificateManager('ZURUBANK');
        $signedData = $certManager->createSignedRequest($data, 'ZURUBANK');
        echo json_encode($signedData);
        exit;
    }
}
