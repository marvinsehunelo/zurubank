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
    private ?string $myPrivateKey = null;
    private ?string $myCertificate = null;
    private ?string $myName = null;
    
    public function __construct(?string $memberName = null)
    {
        $this->myName = $memberName ?? getenv('MEMBER_NAME') ?: 'ZURUBANK';
        
        // Load CA certificate (trust anchor)
        $caContent = getenv('VOUCHMORPH_CA_CERT_CONTENT');
        if ($caContent) {
            $this->caCert = str_replace(['\\n', '\n'], "\n", $caContent);
            error_log("CertificateManager: CA certificate loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No CA certificate found for {$this->myName}");
        }
        
        // Load this member's private key
        $privateKeyContent = getenv($this->myName . '_PRIVATE_KEY_CONTENT');
        if ($privateKeyContent) {
            $this->myPrivateKey = str_replace(['\\n', '\n'], "\n", $privateKeyContent);
            error_log("CertificateManager: Private key loaded for {$this->myName}");
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
     * Get this member's private key
     */
    public function getMyPrivateKey(): ?string
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
        $tempCert = tempnam(sys_get_temp_dir(), 'extract_');
        file_put_contents($tempCert, $certificatePem);
        
        $cmd = "openssl x509 -in " . escapeshellarg($tempCert) . " -pubkey -noout 2>&1";
        $publicKey = shell_exec($cmd);
        
        unlink($tempCert);
        
        if ($publicKey && strpos($publicKey, 'BEGIN PUBLIC KEY') !== false) {
            return $publicKey;
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
        // IMPORTANT: Remove signature, certificate, and requester before verification
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
        
        error_log("CertificateManager: Signing payload for {$requester}: " . $jsonToSign);
        
        $signature = '';
        $keyResource = openssl_pkey_get_private($this->myPrivateKey);
        if (!$keyResource) {
            error_log("CertificateManager: Failed to load private key for signing");
            return $payload;
        }
        
        $signResult = openssl_sign($jsonToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        
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
