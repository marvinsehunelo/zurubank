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
    private $myPrivateKey = null;  // Store as resource
    private ?string $myCertificate = null;
    private ?string $myName = null;
    
    public function __construct(?string $memberName = null)
    {
        // Use VOUCHMORPH_PARTNER_NAME as the primary source, fallback to MEMBER_NAME for compatibility
        $this->myName = $memberName ?? getenv('VOUCHMORPH_PARTNER_NAME') ?: (getenv('MEMBER_NAME') ?: 'ZURUBANK');
        
        error_log("CertificateManager: Initialized for {$this->myName}");
        
        // Load CA certificate (trust anchor) - same for all members
        $caContent = getenv('VOUCHMORPH_CA_CERT_CONTENT');
        if ($caContent) {
            $this->caCert = $this->normalizePemContent($caContent);
            error_log("CertificateManager: CA certificate loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No CA certificate found for {$this->myName}");
        }
        
        // Load this member's private key - use partner name
        $privateKeyContent = $this->getPrivateKeyFromEnv();
        
        if ($privateKeyContent) {
            $this->myPrivateKey = $this->loadPrivateKey($privateKeyContent);
            if ($this->myPrivateKey) {
                error_log("CertificateManager: Private key loaded successfully for {$this->myName}");
            } else {
                error_log("CertificateManager: ERROR - Failed to load private key for {$this->myName}");
            }
        } else {
            error_log("CertificateManager: WARNING - No private key found for {$this->myName}");
        }
        
        // Load this member's certificate
        $certContent = $this->getCertificateFromEnv();
        
        if ($certContent) {
            $this->myCertificate = $this->normalizePemContent($certContent);
            error_log("CertificateManager: Certificate loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No certificate found for {$this->myName}");
        }
    }
    
    /**
     * Get private key from environment (supports multiple formats)
     */
    private function getPrivateKeyFromEnv(): ?string
    {
        // Try different possible environment variable names based on VOUCHMORPH_PARTNER_NAME
        $possibleNames = [
            // Primary: Using VOUCHMORPH_PARTNER_NAME value (e.g., ZURUBANK_PRIVATE_KEY_CONTENT)
            $this->myName . '_PRIVATE_KEY_CONTENT',
            $this->myName . '_PRIVATE_KEY',
            
            // Direct fallbacks
            'ZURUBANK_PRIVATE_KEY_CONTENT',
            'ZURUBANK_PRIVATE_KEY',
            'ZURUBANK_PRIVATE_KEY_BASE64',
            
            // Generic fallbacks
            'PRIVATE_KEY_CONTENT',
            'PRIVATE_KEY'
        ];
        
        foreach ($possibleNames as $name) {
            $value = getenv($name);
            if ($value) {
                error_log("CertificateManager: Found private key in env: {$name}");
                
                // Check if it's base64 encoded (usually a single line without BEGIN/END)
                if (strpos($name, 'BASE64') !== false || (strpos($value, '-----BEGIN') === false && strlen($value) > 100 && !preg_match('/\n/', $value))) {
                    error_log("CertificateManager: Attempting to decode as base64");
                    $decoded = base64_decode($value);
                    if ($decoded && (strpos($decoded, 'BEGIN PRIVATE KEY') !== false || strpos($decoded, 'BEGIN RSA PRIVATE KEY') !== false)) {
                        error_log("CertificateManager: Successfully decoded base64 private key");
                        return $decoded;
                    }
                }
                
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * Get certificate from environment
     */
    private function getCertificateFromEnv(): ?string
    {
        $possibleNames = [
            $this->myName . '_CERT_CONTENT',
            $this->myName . '_CERT',
            'ZURUBANK_CERT_CONTENT',
            'ZURUBANK_CERT',
            'ZURUBANK_CERT_BASE64',
            'CERTIFICATE_CONTENT',
            'CERTIFICATE'
        ];
        
        foreach ($possibleNames as $name) {
            $value = getenv($name);
            if ($value) {
                error_log("CertificateManager: Found certificate in env: {$name}");
                
                // Check if it's base64 encoded
                if (strpos($name, 'BASE64') !== false || (strpos($value, '-----BEGIN') === false && strlen($value) > 100 && !preg_match('/\n/', $value))) {
                    $decoded = base64_decode($value);
                    if ($decoded && (strpos($decoded, 'BEGIN CERTIFICATE') !== false)) {
                        error_log("CertificateManager: Successfully decoded base64 certificate");
                        return $decoded;
                    }
                }
                
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * Normalize PEM content - ensures proper line breaks
     */
    private function normalizePemContent(string $content): string
    {
        // First, replace any escaped newlines
        $content = str_replace(['\\n', '\\r', '\r'], "\n", $content);
        
        // Remove any Windows line endings
        $content = str_replace("\r", "", $content);
        
        // Trim whitespace
        $content = trim($content);
        
        // Check if it's already in PEM format
        if (preg_match('/-----BEGIN (.*?)-----/', $content)) {
            // Ensure proper line breaks after headers and footers
            $content = preg_replace('/-----BEGIN [^-]+-----/', "$0\n", $content);
            $content = preg_replace('/-----END [^-]+-----/', "\n$0\n", $content);
            
            // Ensure body lines are 64 chars
            preg_match('/-----BEGIN (.*?)-----\n(.*?)\n-----END \\1-----/s', $content, $matches);
            if (isset($matches[2])) {
                $body = preg_replace('/\s/', '', $matches[2]);
                $chunked = chunk_split($body, 64, "\n");
                $content = "-----BEGIN {$matches[1]}-----\n" . trim($chunked) . "\n-----END {$matches[1]}-----\n";
            }
        }
        
        return $content;
    }
    
    /**
     * Load private key with OpenSSL 3.x compatibility
     */
    private function loadPrivateKey(string $keyContent)
    {
        // First, normalize the content
        $keyContent = $this->normalizePemContent($keyContent);
        
        error_log("CertificateManager: Private key length: " . strlen($keyContent));
        error_log("CertificateManager: Private key first 50 chars: " . substr($keyContent, 0, 50));
        
        // Check if it's a proper PEM format
        if (strpos($keyContent, '-----BEGIN') === false) {
            error_log("CertificateManager: Key missing PEM headers - adding them");
            // Assume it's raw base64 without headers
            $keyContent = "-----BEGIN PRIVATE KEY-----\n" . 
                          chunk_split(trim($keyContent), 64, "\n") . 
                          "-----END PRIVATE KEY-----\n";
        }
        
        // Try direct load first (PKCS#8 format)
        $privateKey = openssl_pkey_get_private($keyContent);
        if ($privateKey) {
            error_log("CertificateManager: Private key loaded as PKCS#8");
            return $privateKey;
        }
        
        $error1 = openssl_error_string();
        error_log("CertificateManager: PKCS#8 load failed: " . $error1);
        
        // Try as PKCS#1 (RSA PRIVATE KEY)
        $pkcs1Key = str_replace('-----BEGIN PRIVATE KEY-----', '-----BEGIN RSA PRIVATE KEY-----', $keyContent);
        $pkcs1Key = str_replace('-----END PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----', $pkcs1Key);
        
        $privateKey = openssl_pkey_get_private($pkcs1Key);
        if ($privateKey) {
            error_log("CertificateManager: Private key loaded as PKCS#1 (RSA)");
            return $privateKey;
        }
        
        $error2 = openssl_error_string();
        error_log("CertificateManager: PKCS#1 load failed: " . $error2);
        
        // Try using openssl command line to convert and fix the key
        $tempFile = tempnam(sys_get_temp_dir(), 'zurukey_');
        file_put_contents($tempFile, $keyContent);
        
        // Try to convert PKCS#8 to PKCS#1
        $cmd = "openssl rsa -in " . escapeshellarg($tempFile) . " -out " . escapeshellarg($tempFile . '_conv') . " 2>&1";
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($tempFile . '_conv')) {
            $converted = file_get_contents($tempFile . '_conv');
            $privateKey = openssl_pkey_get_private($converted);
            if ($privateKey) {
                error_log("CertificateManager: Private key loaded after openssl conversion");
                unlink($tempFile);
                unlink($tempFile . '_conv');
                return $privateKey;
            }
        }
        
        // Try to extract public key to verify it's at least valid
        $cmd = "openssl rsa -in " . escapeshellarg($tempFile) . " -pubout -out " . escapeshellarg($tempFile . '_pub') . " 2>&1";
        exec($cmd, $output, $returnCode);
        if ($returnCode === 0) {
            error_log("CertificateManager: Private key file is valid (can extract public key)");
        } else {
            error_log("CertificateManager: Private key file validation failed: " . implode("\n", $output));
        }
        
        unlink($tempFile);
        if (file_exists($tempFile . '_conv')) unlink($tempFile . '_conv');
        if (file_exists($tempFile . '_pub')) unlink($tempFile . '_pub');
        
        error_log("CertificateManager: All private key loading attempts failed");
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
     * Check if CertificateManager is properly configured
     */
    public function isConfigured(): bool
    {
        $configured = ($this->caCert !== null && $this->myPrivateKey !== null && $this->myCertificate !== null);
        error_log("CertificateManager: isConfigured = " . ($configured ? "YES" : "NO"));
        if (!$configured) {
            error_log("CertificateManager: caCert: " . ($this->caCert ? "YES" : "NO"));
            error_log("CertificateManager: myPrivateKey: " . ($this->myPrivateKey ? "YES" : "NO"));
            error_log("CertificateManager: myCertificate: " . ($this->myCertificate ? "YES" : "NO"));
        }
        return $configured;
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
        
        // Step 3: Prepare payload for verification (remove signature and certificate, keep timestamp and requester)
        $payloadToVerify = [];
        foreach ($request as $key => $value) {
            if (!in_array($key, ['signature', 'certificate'])) {
                $payloadToVerify[$key] = $value;
            }
        }
        
        // CRITICAL: Sort keys alphabetically (same as VOUCHMORPH)
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
     */
    public function createSignedRequest(array $payload, string $requester): array
    {
        if (!$this->myPrivateKey || !$this->myCertificate) {
            error_log("CertificateManager: Cannot sign request - missing private key or certificate for {$this->myName}");
            error_log("CertificateManager: myPrivateKey exists: " . ($this->myPrivateKey ? "YES" : "NO"));
            error_log("CertificateManager: myCertificate exists: " . ($this->myCertificate ? "YES" : "NO"));
            return $payload;
        }
        
        // CRITICAL: Must match what VOUCHMORPH expects for verification
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp, 'requester' => $requester]);
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
            'certificate' => $this->myCertificate
        ]);
    }
}
