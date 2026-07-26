<?php
// zurubank/Backend/helpers/CertificateManager.php
// FIXED: Accept requester in both top-level and nested objects

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
            
            $cleanKey = str_replace(['\\n', '\n', "\r"], "\n", $privateKeyContent);
            $cleanKey = trim($cleanKey);
            
            $base64Content = preg_replace('/-----BEGIN PRIVATE KEY-----/', '', $cleanKey);
            $base64Content = preg_replace('/-----END PRIVATE KEY-----/', '', $base64Content);
            $base64Content = preg_replace('/\s/', '', $base64Content);
            
            error_log("Base64 length: " . strlen($base64Content));
            error_log("Base64 valid: " . (preg_match('/^[A-Za-z0-9+\/=]+$/', $base64Content) ? 'YES' : 'NO'));
            
            $base64Content = preg_replace('/[^A-Za-z0-9+\/=]/', '', $base64Content);
            
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
        
        $tempCert = tempnam(sys_get_temp_dir(), 'cert_');
        $tempCA = tempnam(sys_get_temp_dir(), 'ca_');
        
        file_put_contents($tempCert, $certificatePem);
        file_put_contents($tempCA, $this->caCert);
        
        $cmd = "openssl verify -CAfile " . escapeshellarg($tempCA) . " " . escapeshellarg($tempCert) . " 2>&1";
        exec($cmd, $output, $returnCode);
        $result = ($returnCode === 0);
        
        unlink($tempCert);
        unlink($tempCA);

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
     * ✅ FIXED: Accept requester in both top-level AND nested objects
     * VouchMorph may include requester in source_verification.payload
     */
    public function verifySignedRequest(array $request): array
    {
        $certificate = $request['certificate'] ?? null;
        $signature = $request['signature'] ?? null;
        $requester = $request['requester'] ?? 'UNKNOWN';
        
        error_log("=== ZURUBANK CertificateManager::verifySignedRequest START ===");
        error_log("Requester: {$requester}");
        error_log("Has certificate: " . ($certificate ? 'YES' : 'NO'));
        error_log("Has signature: " . ($signature ? 'YES' : 'NO'));
        error_log("Request keys: " . implode(', ', array_keys($request)));
        
        if (!$certificate) {
            error_log("CertificateManager: No certificate provided from {$requester}");
            return ['verified' => false, 'message' => 'No certificate provided', 'requester' => $requester];
        }
        
        if (!$signature) {
            error_log("CertificateManager: No signature provided from {$requester}");
            return ['verified' => false, 'message' => 'No signature provided', 'requester' => $requester];
        }
        
        if (!$this->verifyCertificate($certificate)) {
            error_log("CertificateManager: Certificate not trusted from {$requester}");
            return ['verified' => false, 'message' => 'Certificate not trusted', 'requester' => $requester];
        }
        
        $publicKey = $this->extractPublicKeyFromCert($certificate);
        if (!$publicKey) {
            error_log("CertificateManager: Cannot extract public key from certificate");
            return ['verified' => false, 'message' => 'Cannot extract public key', 'requester' => $requester];
        }
        
        // ✅ FIXED: Try verification with requester FIRST
        // (since VouchMorph includes it in nested objects)
        $payloadWithRequester = $request;
        unset($payloadWithRequester['signature']);
        unset($payloadWithRequester['certificate']);
        // ✅ KEEP requester at all levels
        ksort($payloadWithRequester);
        
        $jsonWithRequester = json_encode($payloadWithRequester, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        error_log("ZURUBANK CertificateManager: VERIFYING JSON (WITH requester): " . $jsonWithRequester);
        error_log("ZURUBANK CertificateManager: JSON length: " . strlen($jsonWithRequester));
        error_log("ZURUBANK CertificateManager: Signature decoded length: " . strlen($decodedSig));
        
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            error_log("CertificateManager: Invalid public key format");
            return ['verified' => false, 'message' => 'Invalid public key', 'requester' => $requester];
        }
        
        // Try verification with requester included
        $result = openssl_verify($jsonWithRequester, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        // ✅ If fails with requester, try without requester (backward compatibility)
        if (!$isValid) {
            error_log("ZURUBANK CertificateManager: Retrying without requester (backward compatibility)");
            
            $payloadWithoutRequester = $this->removeRequesterRecursive($payloadWithRequester);
            ksort($payloadWithoutRequester);
            
            $jsonWithoutRequester = json_encode($payloadWithoutRequester, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            // Get fresh key resource
            $keyResource2 = openssl_pkey_get_public($publicKey);
            if ($keyResource2) {
                $result2 = openssl_verify($jsonWithoutRequester, $decodedSig, $keyResource2, OPENSSL_ALGO_SHA256);
                if ($result2 === 1) {
                    $isValid = true;
                    $result = $result2;
                    error_log("ZURUBANK CertificateManager: ✅ VERIFIED WITHOUT requester");
                }
            }
        }
        
        error_log("ZURUBANK CertificateManager: openssl_verify result: " . $result . " (1=valid, 0=invalid, -1=error)");
        error_log("ZURUBANK CertificateManager: Request from {$requester} - Signature: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
        
        if ($result === -1) {
            error_log("ZURUBANK CertificateManager: OpenSSL error: " . openssl_error_string());
        }
        
        if (!$isValid) {
            error_log("ZURUBANK CertificateManager: VERIFICATION FAILED");
            error_log("ZURUBANK CertificateManager: JSON with requester: " . $jsonWithRequester);
            error_log("ZURUBANK CertificateManager: JSON without requester: " . $jsonWithoutRequester ?? 'N/A');
        }
        
        return [
            'verified' => $isValid,
            'requester' => $requester,
            'message' => $isValid ? 'Signature verified' : 'Invalid signature'
        ];
    }
    
    /**
     * ✅ NEW: Recursively remove all 'requester' fields
     */
    private function removeRequesterRecursive(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if ($key === 'requester') {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->removeRequesterRecursive($value);
            } else {
                $clean[$key] = $value;
            }
        }
        return $clean;
    }
    
    /**
     * Create signed request with certificate (for outgoing)
     */
    public function createSignedRequest(array $payload, string $requester): array
    {
        error_log("=== ZURUBANK CREATE SIGNED REQUEST START ===");
        error_log("Requester: {$requester}");
        error_log("MyName: {$this->myName}");
        error_log("Has private key: " . ($this->myPrivateKey ? 'YES' : 'NO'));
        error_log("Has certificate: " . ($this->myCertificate ? 'YES' : 'NO'));
        error_log("Payload keys: " . implode(', ', array_keys($payload)));
        
        if (!$this->myPrivateKey || !$this->myCertificate) {
            error_log("CertificateManager: Cannot sign request - missing private key or certificate");
            return $payload;
        }
        
        // ✅ Remove requester from payload before signing
        $cleanPayload = $this->removeRequesterRecursive($payload);
        $payloadWithTimestamp = array_merge($cleanPayload, ['timestamp' => time()]);
        ksort($payloadWithTimestamp);
        
        $jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log("ZURUBANK JSON to sign length: " . strlen($jsonToSign));
        error_log("ZURUBANK JSON to sign preview: " . substr($jsonToSign, 0, 200));
        
        $signature = '';
        $keyResource = openssl_pkey_get_private($this->myPrivateKey);
        
        if (!$keyResource) {
            error_log("CertificateManager: Private key load FAILED");
            return $payload;
        }
        
        $signResult = openssl_sign($jsonToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        
        if (!$signResult) {
            error_log("CertificateManager: Failed to create signature");
            return $payload;
        }
        
        // ✅ Add requester AFTER signing
        $signedPayload = array_merge($payloadWithTimestamp, [
            'signature' => base64_encode($signature),
            'requester' => $requester,
            'certificate' => $this->myCertificate
        ]);
        
        error_log("=== ZURUBANK CREATE SIGNED REQUEST COMPLETE ===");
        error_log("Signature base64 length: " . strlen(base64_encode($signature)));
        error_log("Certificate length: " . strlen($this->myCertificate));
        
        return $signedPayload;
    }
    
    public function isConfigured(): bool
    {
        return ($this->caCert !== null && $this->myPrivateKey !== null && $this->myCertificate !== null);
    }
}
