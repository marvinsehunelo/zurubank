<?php
// zurubank/Backend/helpers/CertificateManager.php
// ORIGINAL SACCUSSALIS VERSION - Fully fixed syntax & private key normalization

/**
 * Certificate Manager - Visa/Mastercard style PKI
 * * Members present their certificate with each request.
 * Receivers verify against the trusted CA root.
 * NO manual key exchange needed for new members!
 */
class CertificateManager
{
    public ?string $caCert = null;           
    public ?string $myPrivateKey = null;      
    public ?string $myCertificate = null;     
    private ?string $myName = null;
    
    public function __construct(?string $memberName = null)
    {
        $this->myName = $memberName ?? getenv('VOUCHMORPH_PARTNER_NAME') ?: 'ZURUBANK';
        
        // Load CA certificate (trust anchor)
        $caContent = getenv('VOUCHMORPH_CA_CERT_CONTENT');
        if ($caContent) {
            $this->caCert = str_replace(['\\n', '\n'], "\n", $caContent);
            error_log("CertificateManager: CA certificate loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No CA certificate found for {$this->myName}");
        }
        
        // Load and normalize this member's private key
        $privateKeyContent = getenv($this->myName . '_PRIVATE_KEY_CONTENT');
        if ($privateKeyContent) {
            // 1. Clean out literal '\n' text strings and windows-style returns
            $cleanKey = str_replace(['\\n', '\n', "\r"], "\n", $privateKeyContent);
            
            // 2. Strip headers/footers and any existing whitespace to normalize it
            $cleanKey = preg_replace('/-----BEGIN [A-Z ]+-----/', '', $cleanKey);
            $cleanKey = preg_replace('/-----END [A-Z ]+-----/', '', $cleanKey);
            $cleanKey = preg_replace('/\s+/', '', $cleanKey);
            
            // 3. Re-wrap perfectly into standard 64-character chunks
            $chunks = str_split($cleanKey, 64);
            $this->myPrivateKey = "-----BEGIN PRIVATE KEY-----\n" . implode("\n", $chunks) . "\n-----END PRIVATE KEY-----";
            
            error_log("CertificateManager: Private key loaded and normalized for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No private key found for {$this->myName}");
        }
        
        // Load this member's certificate
        $certContent = getenv($this->myName . '_CERT_CONTENT');
        if ($certContent) {
            $this->myCertificate = str_replace(['\\n', '\n'], "\n", $certContent);
            error_log("CertificateManager: Certificate loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No certificate found for {$this->myName}");
        }
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
        
        // Step 1: Trust Anchor validation via Temp Files
        $tempCert = tempnam(sys_get_temp_dir(), 'cert_');
        $tempCA = tempnam(sys_get_temp_dir(), 'ca_');
        
        file_put_contents($tempCert, $certificatePem);
        file_put_contents($tempCA, $this->caCert);
        
        // Verify certificate chains to our trusted CA
        $cmd = "openssl verify -CAfile " . escapeshellarg($tempCA) . " " . escapeshellarg($tempCert) . " 2>&1";
        exec($cmd, $output, $returnCode);
        $result = ($returnCode === 0);
        
        unlink($tempCert);
        unlink($tempCA);

        // Step 2: Native PHP Expiry Check
        try {
            $certData = openssl_x509_parse($certificatePem);
            if ($certData && isset($certData['validTo_time_t'])) {
                $expiryTimestamp = (int)$certData['validTo_time_t'];
                if ($expiryTimestamp < time()) {
                    error_log("CertificateManager: Certificate has expired natively on " . date('Y-m-d H:i:s', $expiryTimestamp));
                    $result = false;
                }
            } else {
                error_log("CertificateManager: Failed to parse X509 certificate metadata structure.");
                $result = false;
            }
        } catch (Exception $e) {
            error_log("CertificateManager: Exception during native validation: " . $e->getMessage());
            $result = false;
        }
        
        error_log("CertificateManager: Certificate verification ultimate status: " . ($result ? "PASSED" : "FAILED"));
        return $result;
    }
    
    /**
     * Extract public key from a certificate
     */
    public function extractPublicKeyFromCert(string $certificatePem): ?string
    {
        $tempCert = tempnam(sys_get_temp_dir(), 'extract_');
        file_put_contents($tempCert, $certificatePem);
        
        $cmd = "openssl x509 -in " . escapeshellarg($tempCert) . " -pubkey -noout 2>&1";
        $publicKey = shell_exec($cmd);
        
        unlink($tempCert);
        
        if ($publicKey && strpos($publicKey, 'BEGIN PUBLIC KEY') !== false) {
            return $publicKey;
        }
        
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
        
        // Step 4: Verify signature
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
     * FIXED: Enhanced private key loading with multiple format support
     */
    public function createSignedRequest(array $payload, string $requester): array
    {
        if (!$this->myPrivateKey || !$this->myCertificate) {
            error_log("CertificateManager: Cannot sign request - missing private key or certificate for {$this->myName}");
            return $payload;
        }
        
        $timestamp = time();
        $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
        ksort($payloadWithTimestamp);
        
        $jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = '';
        
        // FIX: Enhanced private key loading with multiple format support
        $keyResource = null;
        
        // Method 1: Try direct loading first
        $keyResource = openssl_pkey_get_private($this->myPrivateKey);
        
        // Method 2: Ensure proper PKCS#8 formatting
        if (!$keyResource) {
            error_log("CertificateManager: Attempting PKCS#8 reformat...");
            
            $cleanKey = trim($this->myPrivateKey);
            $cleanKey = preg_replace('/-----BEGIN PRIVATE KEY-----/', '', $cleanKey);
            $cleanKey = preg_replace('/-----END PRIVATE KEY-----/', '', $cleanKey);
            $cleanKey = preg_replace('/\s+/', '', $cleanKey);
            
            $chunks = str_split($cleanKey, 64);
            $formattedKey = "-----BEGIN PRIVATE KEY-----\n" . implode("\n", $chunks) . "\n-----END PRIVATE KEY-----";
            
            $keyResource = openssl_pkey_get_private($formattedKey);
            error_log("CertificateManager: PKCS#8 reformat result: " . ($keyResource ? "SUCCESS" : "FAILED"));
        }
        
        // Method 3: Try PKCS#1 format
        if (!$keyResource && strpos($this->myPrivateKey, 'BEGIN RSA PRIVATE KEY') === false) {
            error_log("CertificateManager: Attempting PKCS#1 conversion...");
            
            $tempKey = tempnam(sys_get_temp_dir(), 'pkcs8_');
            file_put_contents($tempKey, $this->myPrivateKey);
            
            $tempKeyPkcs1 = tempnam(sys_get_temp_dir(), 'pkcs1_');
            $cmd = "openssl pkcs8 -in " . escapeshellarg($tempKey) . " -out " . escapeshellarg($tempKeyPkcs1) . " -nocrypt -topk8 2>&1";
            exec($cmd, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($tempKeyPkcs1)) {
                $pkcs1Content = file_get_contents($tempKeyPkcs1);
                if ($pkcs1Content) {
                    $keyResource = openssl_pkey_get_private($pkcs1Content);
                    error_log("CertificateManager: PKCS#1 conversion result: " . ($keyResource ? "SUCCESS" : "FAILED"));
                }
            }
            
            @unlink($tempKey);
            @unlink($tempKeyPkcs1);
        }
        
        // Method 4: Temp file as last resort
        if (!$keyResource) {
            error_log("CertificateManager: Last resort - temp file method...");
            $tempKey = tempnam(sys_get_temp_dir(), 'privkey_');
            file_put_contents($tempKey, $this->myPrivateKey);
            $keyResource = openssl_pkey_get_private('file://' . $tempKey);
            unlink($tempKey);
        }
        
        if (!$keyResource) {
            error_log("CertificateManager: CRITICAL ERROR - Private key cannot be loaded by OpenSSL. OpenSSL error: " . openssl_error_string());
            return $payload;
        }
        
        openssl_sign($jsonToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        
        return array_merge($payloadWithTimestamp, [
            'signature' => base64_encode($signature),
            'requester' => $requester,
            'certificate' => $this->myCertificate
        ]);
    }
    
    public function isConfigured(): bool
    {
        return ($this->caCert !== null && $this->myPrivateKey !== null && $this->myCertificate !== null);
    }
}
