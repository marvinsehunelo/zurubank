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
        // Use VOUCHMORPH_PARTNER_NAME as the primary source
        $this->myName = $memberName ?? getenv('VOUCHMORPH_PARTNER_NAME') ?: (getenv('MEMBER_NAME') ?: 'ZURUBANK');
        
        error_log("CertificateManager: Initialized for {$this->myName}");
        
        // Load CA certificate (trust anchor)
        $caContent = getenv('VOUCHMORPH_CA_CERT_CONTENT');
        if ($caContent) {
            $this->caCert = $this->normalizePemContent($caContent);
            error_log("CertificateManager: CA certificate loaded");
        } else {
            error_log("CertificateManager: WARNING - No CA certificate found");
        }
        
        // Load this member's private key (supports encrypted keys)
        $privateKeyContent = $this->getPrivateKeyFromEnv();
        $passphrase = $this->getPrivateKeyPassphrase();
        
        if ($privateKeyContent) {
            $this->myPrivateKey = $this->loadPrivateKey($privateKeyContent, $passphrase);
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
     * Get private key passphrase from environment
     */
    private function getPrivateKeyPassphrase(): ?string
    {
        $possibleNames = [
            $this->myName . '_PRIVATE_KEY_PASSPHRASE',
            $this->myName . '_KEY_PASSWORD',
            'ZURUBANK_PRIVATE_KEY_PASSPHRASE',
            'ZURUBANK_KEY_PASSWORD',
            'PRIVATE_KEY_PASSPHRASE',
            'KEY_PASSPHRASE'
        ];
        
        foreach ($possibleNames as $name) {
            $value = getenv($name);
            if ($value) {
                error_log("CertificateManager: Found private key passphrase in env: {$name}");
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * Get private key from environment (supports multiple formats)
     */
    private function getPrivateKeyFromEnv(): ?string
    {
        // Try different possible environment variable names
        $possibleNames = [
            $this->myName . '_PRIVATE_KEY_CONTENT',
            $this->myName . '_PRIVATE_KEY',
            'ZURUBANK_PRIVATE_KEY_CONTENT',
            'ZURUBANK_PRIVATE_KEY',
            'ZURUBANK_PRIVATE_KEY_BASE64',
            'PRIVATE_KEY_CONTENT',
            'PRIVATE_KEY'
        ];
        
        foreach ($possibleNames as $name) {
            $value = getenv($name);
            if ($value) {
                error_log("CertificateManager: Found private key in env: {$name}");
                
                // Check if it's base64 encoded
                if (strpos($name, 'BASE64') !== false || (strpos($value, '-----BEGIN') === false && strlen($value) > 100 && !preg_match('/\n/', $value))) {
                    error_log("CertificateManager: Attempting to decode as base64");
                    $decoded = base64_decode($value);
                    if ($decoded && (strpos($decoded, 'BEGIN PRIVATE KEY') !== false || strpos($decoded, 'BEGIN RSA PRIVATE KEY') !== false || strpos($decoded, 'BEGIN ENCRYPTED PRIVATE KEY') !== false)) {
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
                
                if (strpos($name, 'BASE64') !== false || (strpos($value, '-----BEGIN') === false && strlen($value) > 100 && !preg_match('/\n/', $value))) {
                    $decoded = base64_decode($value);
                    if ($decoded && strpos($decoded, 'BEGIN CERTIFICATE') !== false) {
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
        $content = str_replace("\r", "", $content);
        $content = trim($content);
        
        // Check if it's already in PEM format
        if (preg_match('/-----BEGIN (.*?)-----/', $content)) {
            $content = preg_replace('/-----BEGIN [^-]+-----/', "$0\n", $content);
            $content = preg_replace('/-----END [^-]+-----/', "\n$0\n", $content);
            
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
     * Load private key with OpenSSL 3.x compatibility (supports encrypted keys)
     */
    private function loadPrivateKey(string $keyContent, ?string $passphrase = null)
    {
        // First, normalize the content
        $keyContent = $this->normalizePemContent($keyContent);
        
        error_log("CertificateManager: Private key length: " . strlen($keyContent));
        error_log("CertificateManager: Private key first 50 chars: " . substr($keyContent, 0, 50));
        
        // Check if this is an encrypted private key
        if (strpos($keyContent, 'ENCRYPTED PRIVATE KEY') !== false) {
            error_log("CertificateManager: Detected ENCRYPTED private key format");
            
            if (!$passphrase) {
                error_log("CertificateManager: ERROR - Encrypted private key requires a passphrase but none provided");
                error_log("CertificateManager: Please set " . $this->myName . "_PRIVATE_KEY_PASSPHRASE environment variable");
                return null;
            }
            
            error_log("CertificateManager: Using passphrase to decrypt private key");
            $privateKey = openssl_pkey_get_private($keyContent, $passphrase);
            if ($privateKey) {
                error_log("CertificateManager: Encrypted private key loaded successfully with passphrase");
                return $privateKey;
            }
            error_log("CertificateManager: Failed to load encrypted private key: " . openssl_error_string());
        }
        
        // Check for PKCS#8 with BEGIN PRIVATE KEY (might be encrypted or not)
        if (strpos($keyContent, 'BEGIN PRIVATE KEY') !== false) {
            // Try without passphrase first
            $privateKey = openssl_pkey_get_private($keyContent);
            if ($privateKey) {
                error_log("CertificateManager: Private key loaded as PKCS#8 (unencrypted)");
                return $privateKey;
            }
            
            // If that fails and we have a passphrase, try with passphrase
            if ($passphrase) {
                $privateKey = openssl_pkey_get_private($keyContent, $passphrase);
                if ($privateKey) {
                    error_log("CertificateManager: PKCS#8 private key loaded with passphrase");
                    return $privateKey;
                }
            }
            
            error_log("CertificateManager: PKCS#8 load failed: " . openssl_error_string());
        }
        
        // Try as PKCS#1 (RSA PRIVATE KEY)
        if (strpos($keyContent, 'BEGIN RSA PRIVATE KEY') !== false) {
            $privateKey = openssl_pkey_get_private($keyContent);
            if ($privateKey) {
                error_log("CertificateManager: Private key loaded as PKCS#1 (RSA)");
                return $privateKey;
            }
            
            if ($passphrase) {
                $privateKey = openssl_pkey_get_private($keyContent, $passphrase);
                if ($privateKey) {
                    error_log("CertificateManager: PKCS#1 private key loaded with passphrase");
                    return $privateKey;
                }
            }
            
            error_log("CertificateManager: PKCS#1 load failed: " . openssl_error_string());
        }
        
        // Try using openssl command line to convert
        $tempFile = tempnam(sys_get_temp_dir(), 'zurukey_');
        file_put_contents($tempFile, $keyContent);
        
        // Try to convert encrypted PKCS#8 to unencrypted PKCS#1
        if ($passphrase) {
            $cmd = "openssl pkcs8 -in " . escapeshellarg($tempFile) . " -nocrypt -out " . escapeshellarg($tempFile . '_conv') . " -passin pass:" . escapeshellarg($passphrase) . " 2>&1";
            exec($cmd, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($tempFile . '_conv')) {
                $converted = file_get_contents($tempFile . '_conv');
                $privateKey = openssl_pkey_get_private($converted);
                if ($privateKey) {
                    error_log("CertificateManager: Private key loaded after pkcs8 conversion");
                    unlink($tempFile);
                    unlink($tempFile . '_conv');
                    return $privateKey;
                }
            }
        }
        
        // Try RSA conversion
        $cmd = "openssl rsa -in " . escapeshellarg($tempFile) . " -out " . escapeshellarg($tempFile . '_conv') . " 2>&1";
        if ($passphrase) {
            $cmd = "openssl rsa -in " . escapeshellarg($tempFile) . " -out " . escapeshellarg($tempFile . '_conv') . " -passin pass:" . escapeshellarg($passphrase) . " 2>&1";
        }
        exec($cmd, $output, $returnCode);
        
        if ($returnCode === 0 && file_exists($tempFile . '_conv')) {
            $converted = file_get_contents($tempFile . '_conv');
            $privateKey = openssl_pkey_get_private($converted);
            if ($privateKey) {
                error_log("CertificateManager: Private key loaded after RSA conversion");
                unlink($tempFile);
                unlink($tempFile . '_conv');
                return $privateKey;
            }
        }
        
        unlink($tempFile);
        if (file_exists($tempFile . '_conv')) unlink($tempFile . '_conv');
        
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
        
        $tempCert = tempnam(sys_get_temp_dir(), 'cert_');
        $tempCA = tempnam(sys_get_temp_dir(), 'ca_');
        
        file_put_contents($tempCert, $certificatePem);
        file_put_contents($tempCA, $this->caCert);
        
        $cmd = "openssl verify -CAfile " . escapeshellarg($tempCA) . " " . escapeshellarg($tempCert) . " 2>&1";
        exec($cmd, $output, $returnCode);
        $result = ($returnCode === 0);
        
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
            return ['verified' => false, 'message' => 'No certificate provided', 'requester' => $requester];
        }
        
        if (!$signature) {
            return ['verified' => false, 'message' => 'No signature provided', 'requester' => $requester];
        }
        
        $certificate = $this->normalizePemContent($certificate);
        
        if (!$this->verifyCertificate($certificate)) {
            return ['verified' => false, 'message' => 'Certificate not trusted', 'requester' => $requester];
        }
        
        $publicKey = $this->extractPublicKeyFromCert($certificate);
        if (!$publicKey) {
            return ['verified' => false, 'message' => 'Cannot extract public key', 'requester' => $requester];
        }
        
        $payloadToVerify = [];
        foreach ($request as $key => $value) {
            if (!in_array($key, ['signature', 'certificate'])) {
                $payloadToVerify[$key] = $value;
            }
        }
        
        ksort($payloadToVerify);
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            return ['verified' => false, 'message' => 'Invalid public key', 'requester' => $requester];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        error_log("CertificateManager: Request from {$requester} - Signature: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
        
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
            error_log("CertificateManager: Cannot sign request - missing private key or certificate");
            return $payload;
        }
        
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp, 'requester' => $requester]);
        ksort($payloadWithTimestamp);
        
        $jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
        $signature = '';
        $signResult = openssl_sign($jsonToSign, $signature, $this->myPrivateKey, OPENSSL_ALGO_SHA256);
        
        if (!$signResult) {
            error_log("CertificateManager: Failed to sign payload - " . openssl_error_string());
            return $payload;
        }
        
        $encodedSignature = base64_encode($signature);
        
        return array_merge($payloadWithTimestamp, [
            'signature' => $encodedSignature,
            'certificate' => $this->myCertificate
        ]);
    }
}
