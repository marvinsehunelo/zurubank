<?php
// zurubank/Backend/helpers/CertificateManager.php
// UPDATED - Automatic private key repair for invalid base64

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
        
        // Load and repair this member's private key
        $privateKeyContent = getenv($this->myName . '_PRIVATE_KEY_CONTENT');
        
        if ($privateKeyContent) {
            error_log("=== PRIVATE KEY DIAGNOSTIC ===");
            error_log("Length: " . strlen($privateKeyContent));
            error_log("First 40 chars: " . substr($privateKeyContent, 0, 40));
            error_log("Last 40 chars: " . substr($privateKeyContent, -40));
            
            // Step 1: Fix literal newlines
            $cleanKey = str_replace(['\\n', '\n', "\r"], "\n", $privateKeyContent);
            $cleanKey = trim($cleanKey);
            
            // Step 2: Extract base64 content between markers
            $base64Content = preg_replace('/-----BEGIN PRIVATE KEY-----/', '', $cleanKey);
            $base64Content = preg_replace('/-----END PRIVATE KEY-----/', '', $base64Content);
            $base64Content = preg_replace('/\s/', '', $base64Content);
            
            error_log("Base64 length: " . strlen($base64Content));
            error_log("Base64 valid: " . (preg_match('/^[A-Za-z0-9+\/=]+$/', $base64Content) ? 'YES' : 'NO'));
            
            // Step 3: Remove any invalid characters from base64
            $base64Content = preg_replace('/[^A-Za-z0-9+\/=]/', '', $base64Content);
            
            // Step 4: Re-wrap properly in 64-character chunks
            $chunks = str_split($base64Content, 64);
            $this->myPrivateKey = "-----BEGIN PRIVATE KEY-----\n" . implode("\n", $chunks) . "\n-----END PRIVATE KEY-----";
            
            error_log("CertificateManager: Private key loaded and repaired for {$this->myName}");
            error_log("Repaired key length: " . strlen($this->myPrivateKey));
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
 * Uses repaired key with automatic retry
 */
public function createSignedRequest(array $payload, string $requester): array
{
    error_log("=== CREATE SIGNED REQUEST START ===");
    error_log("Requester: {$requester}");
    error_log("MyName: {$this->myName}");
    error_log("Has private key: " . ($this->myPrivateKey ? 'YES' : 'NO'));
    error_log("Has certificate: " . ($this->myCertificate ? 'YES' : 'NO'));
    error_log("Payload keys: " . implode(', ', array_keys($payload)));
    
    if (!$this->myPrivateKey || !$this->myCertificate) {
        error_log("CertificateManager: Cannot sign request - missing private key or certificate for {$this->myName}");
        error_log("Private key missing: " . ($this->myPrivateKey ? 'NO' : 'YES'));
        error_log("Certificate missing: " . ($this->myCertificate ? 'NO' : 'YES'));
        return $payload;
    }
    
    $timestamp = time();
    $payloadWithTimestamp = array_merge($payload, ['timestamp' => $timestamp]);
    ksort($payloadWithTimestamp);
    
    $jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    error_log("JSON to sign length: " . strlen($jsonToSign));
    error_log("JSON to sign preview: " . substr($jsonToSign, 0, 200));
    
    $signature = '';
    
    // Load private key - try multiple times with different formats
    error_log("Attempting to load private key...");
    error_log("Private key format check - starts with BEGIN: " . (strpos($this->myPrivateKey, 'BEGIN PRIVATE KEY') !== false ? 'YES' : 'NO'));
    
    $keyResource = openssl_pkey_get_private($this->myPrivateKey);
    
    if (!$keyResource) {
        error_log("CertificateManager: Primary key load FAILED");
        
        // Log OpenSSL errors
        $opensslErrors = [];
        while ($err = openssl_error_string()) {
            $opensslErrors[] = $err;
            error_log("OpenSSL Error: " . $err);
        }
        
        error_log("CertificateManager: Attempting emergency repair...");
        
        // Emergency repair - extract and rebuild again
        $base64Content = preg_replace('/-----BEGIN PRIVATE KEY-----/', '', $this->myPrivateKey);
        $base64Content = preg_replace('/-----END PRIVATE KEY-----/', '', $base64Content);
        $base64Content = preg_replace('/[^A-Za-z0-9+\/=]/', '', $base64Content);
        
        error_log("Emergency repair - Base64 length: " . strlen($base64Content));
        
        $chunks = str_split($base64Content, 64);
        $repairedKey = "-----BEGIN PRIVATE KEY-----\n" . implode("\n", $chunks) . "\n-----END PRIVATE KEY-----";
        
        error_log("Emergency repair - New key length: " . strlen($repairedKey));
        error_log("Emergency repair - Key preview: " . substr($repairedKey, 0, 100));
                
        if ($keyResource) {
            // Save the repaired key for future use
            $this->myPrivateKey = $repairedKey;
            error_log("CertificateManager: Emergency repair SUCCESSFUL");
        } else {
            error_log("CertificateManager: Emergency repair FAILED");
            while ($err = openssl_error_string()) {
                error_log("OpenSSL Error after repair: " . $err);
            }
        }
    } else {
        error_log("CertificateManager: Primary key load SUCCESSFUL");
    }
    
    if (!$keyResource) {
        error_log("CertificateManager: Private key load FAILED after all attempts");
        error_log("Key first 40 chars: " . substr($this->myPrivateKey, 0, 40));
        error_log("Key last 40 chars: " . substr($this->myPrivateKey, -40));
        return $payload;
    }
    
    error_log("Calling openssl_sign...");
    $signResult = openssl_sign($jsonToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
    error_log("openssl_sign result: " . ($signResult ? 'SUCCESS' : 'FAILURE'));
    error_log("Generated signature length: " . strlen($signature));
    
    // Clean up key resource
    openssl_free_key($keyResource);
    
    $signedPayload = array_merge($payloadWithTimestamp, [
        'signature' => base64_encode($signature),
        'requester' => $requester,
        'certificate' => $this->myCertificate
    ]);
    
    error_log("=== CREATE SIGNED REQUEST COMPLETE ===");
    error_log("Signature base64 length: " . strlen(base64_encode($signature)));
    error_log("Certificate length: " . strlen($this->myCertificate));
    
    return $signedPayload;
}
    
    public function isConfigured(): bool
    {
        return ($this->caCert !== null && $this->myPrivateKey !== null && $this->myCertificate !== null);
    }
}
