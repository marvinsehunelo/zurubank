<?php
// zurubank/Backend/helpers/CertificateManager.php

class CertificateManager
{
    public ?string $caCert = null;           
    public ?string $myPrivateKey = null;      
    public ?string $myCertificate = null;     
    private ?string $myName = null;
    
    public function __construct(?string $memberName = null)
    {
        $this->myName = $memberName ?? getenv('VOUCHMORPH_PARTNER_NAME') ?: 'ZURUBANK';
        
        $caContent = getenv('VOUCHMORPH_CA_CERT_CONTENT');
        if ($caContent) {
            $this->caCert = str_replace(['\\n', '\n'], "\n", $caContent);
            error_log("CertificateManager: CA certificate loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No CA certificate found for {$this->myName}");
        }
        
        $privateKeyContent = getenv($this->myName . '_PRIVATE_KEY_CONTENT');
        if ($privateKeyContent) {
            $cleanKey = str_replace(['\\n', '\n', "\r"], "\n", $privateKeyContent);
            $cleanKey = trim($cleanKey);
            $base64Content = preg_replace('/-----BEGIN PRIVATE KEY-----/', '', $cleanKey);
            $base64Content = preg_replace('/-----END PRIVATE KEY-----/', '', $base64Content);
            $base64Content = preg_replace('/\s/', '', $base64Content);
            $base64Content = preg_replace('/[^A-Za-z0-9+\/=]/', '', $base64Content);
            $chunks = str_split($base64Content, 64);
            $this->myPrivateKey = "-----BEGIN PRIVATE KEY-----\n" . implode("\n", $chunks) . "\n-----END PRIVATE KEY-----";
            error_log("CertificateManager: Private key loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No private key found for {$this->myName}");
        }
        
        $certContent = getenv($this->myName . '_CERT_CONTENT');
        if ($certContent) {
            $this->myCertificate = str_replace(['\\n', '\n'], "\n", $certContent);
            error_log("CertificateManager: Certificate loaded for {$this->myName}");
        } else {
            error_log("CertificateManager: WARNING - No certificate found for {$this->myName}");
        }
    }
    
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
     * ✅ MATCHES SACCUSSALIS's WORKING IMPLEMENTATION
     * Recursively remove ALL 'requester' fields before verification
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
        
        if (!$certificate) {
            return ['verified' => false, 'message' => 'No certificate provided', 'requester' => $requester];
        }
        
        if (!$signature) {
            return ['verified' => false, 'message' => 'No signature provided', 'requester' => $requester];
        }
        
        if (!$this->verifyCertificate($certificate)) {
            return ['verified' => false, 'message' => 'Certificate not trusted', 'requester' => $requester];
        }
        
        $publicKey = $this->extractPublicKeyFromCert($certificate);
        if (!$publicKey) {
            return ['verified' => false, 'message' => 'Cannot extract public key', 'requester' => $requester];
        }
        
        // ✅ Recursively remove ALL 'requester' fields (matching SACCUSSALIS)
        $payloadToVerify = $request;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['certificate']);
        $payloadToVerify = $this->removeRequesterRecursive($payloadToVerify);
        ksort($payloadToVerify);
        
        $jsonToVerify = json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $decodedSig = base64_decode($signature);
        
        error_log("ZURUBANK CertificateManager: VERIFYING JSON (no requester): " . $jsonToVerify);
        error_log("ZURUBANK CertificateManager: JSON length: " . strlen($jsonToVerify));
        
        $keyResource = openssl_pkey_get_public($publicKey);
        if (!$keyResource) {
            return ['verified' => false, 'message' => 'Invalid public key', 'requester' => $requester];
        }
        
        $result = openssl_verify($jsonToVerify, $decodedSig, $keyResource, OPENSSL_ALGO_SHA256);
        $isValid = ($result === 1);
        
        error_log("ZURUBANK CertificateManager: openssl_verify result: " . $result . " (1=valid, 0=invalid, -1=error)");
        error_log("ZURUBANK CertificateManager: Request from {$requester} - Signature: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
        
        return [
            'verified' => $isValid,
            'requester' => $requester,
            'message' => $isValid ? 'Signature verified' : 'Invalid signature'
        ];
    }
    
    /**
     * Recursively remove all 'requester' fields (matches SACCUSSALIS)
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
    
    public function createSignedRequest(array $payload, string $requester): array
    {
        if (!$this->myPrivateKey || !$this->myCertificate) {
            error_log("CertificateManager: Cannot sign request - missing private key or certificate");
            return $payload;
        }
        
        // ✅ Remove ALL requester fields before signing (matches SACCUSSALIS)
        $cleanPayload = $this->removeRequesterRecursive($payload);
        $payloadWithTimestamp = array_merge($cleanPayload, ['timestamp' => time()]);
        ksort($payloadWithTimestamp);
        
        $jsonToSign = json_encode($payloadWithTimestamp, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $signature = '';
        $keyResource = openssl_pkey_get_private($this->myPrivateKey);
        openssl_sign($jsonToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        
        error_log("ZURUBANK CertificateManager: SIGNING JSON (no requester): " . $jsonToSign);
        
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
