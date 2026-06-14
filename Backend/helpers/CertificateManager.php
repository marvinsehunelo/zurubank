<?php
// zurubank/Backend/helpers/CertificateManager.php

/**
 * Certificate Manager - Visa/Mastercard style PKI
 * Uses certificate-based authentication, NOT RSA key exchange
 */
class CertificateManager
{
    private ?string $caCert = null;
    private $myPrivateKey = null;
    private ?string $myCertificate = null;
    private ?string $myName = null;
    
    public function __construct(?string $memberName = null)
    {
        // Get partner name from environment
        $this->myName = $memberName ?? getenv('VOUCHMORPH_PARTNER_NAME') ?: 'ZURUBANK';
        
        error_log("CertificateManager: Initialized for {$this->myName}");
        
        // Load CA certificate (trust anchor) - REQUIRED
        $caContent = getenv('VOUCHMORPH_CA_CERT_CONTENT');
        if (!$caContent) {
            error_log("CertificateManager: FATAL - No VOUCHMORPH_CA_CERT_CONTENT found");
            $this->caCert = null;
        } else {
            $this->caCert = $this->normalizePemContent($caContent);
            error_log("CertificateManager: CA certificate loaded");
        }
        
        // Load this member's private key - use CERTIFICATE method variables
        $privateKeyContent = getenv($this->myName . '_PRIVATE_KEY_CONTENT');
        if (!$privateKeyContent) {
            error_log("CertificateManager: ERROR - No {$this->myName}_PRIVATE_KEY_CONTENT found");
            $this->myPrivateKey = null;
        } else {
            error_log("CertificateManager: Found private key in {$this->myName}_PRIVATE_KEY_CONTENT");
            $privateKeyContent = $this->normalizePemContent($privateKeyContent);
            $this->myPrivateKey = openssl_pkey_get_private($privateKeyContent);
            
            if (!$this->myPrivateKey) {
                $error = openssl_error_string();
                error_log("CertificateManager: Failed to load private key: " . $error);
                
                // Check if it's encrypted
                if (strpos($privateKeyContent, 'ENCRYPTED') !== false || strpos($privateKeyContent, 'Proc-Type: 4,ENCRYPTED') !== false) {
                    error_log("CertificateManager: Private key is ENCRYPTED. You need to decrypt it or provide passphrase.");
                    error_log("CertificateManager: Run: openssl rsa -in encrypted.key -out decrypted.key");
                }
            } else {
                error_log("CertificateManager: Private key loaded successfully");
            }
        }
        
        // Load this member's certificate
        $certContent = getenv($this->myName . '_CERT_CONTENT');
        if (!$certContent) {
            error_log("CertificateManager: ERROR - No {$this->myName}_CERT_CONTENT found");
            $this->myCertificate = null;
        } else {
            $this->myCertificate = $this->normalizePemContent($certContent);
            error_log("CertificateManager: Certificate loaded for {$this->myName}");
        }
        
        // Log configuration status
        error_log("CertificateManager: CA Cert: " . ($this->caCert ? "YES" : "NO"));
        error_log("CertificateManager: Private Key: " . ($this->myPrivateKey ? "YES" : "NO"));
        error_log("CertificateManager: Certificate: " . ($this->myCertificate ? "YES" : "NO"));
    }
    
    /**
     * Normalize PEM content - ensures proper line breaks
     */
    private function normalizePemContent(string $content): string
    {
        // Replace escaped newlines
        $content = str_replace(['\\n', '\\r', '\r'], "\n", $content);
        $content = str_replace("\r", "", $content);
        $content = trim($content);
        
        // Ensure proper PEM format
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
     * Check if CertificateManager is properly configured
     */
    public function isConfigured(): bool
    {
        $configured = ($this->caCert !== null && $this->myPrivateKey !== null && $this->myCertificate !== null);
        if (!$configured) {
            error_log("CertificateManager: NOT fully configured");
            error_log("  - caCert: " . ($this->caCert ? "YES" : "NO"));
            error_log("  - myPrivateKey: " . ($this->myPrivateKey ? "YES" : "NO"));
            error_log("  - myCertificate: " . ($this->myCertificate ? "YES" : "NO"));
        }
        return $configured;
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
        
        // Check expiration
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
        
        // Prepare payload for verification (remove signature and certificate)
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
