<?php
// security/KeyVault.php

namespace Security\Encryption;

class KeyVault
{
    private static $instance = null;
    private $keys = [];
    private $encryptionKey;
    
    private function __construct()
    {
        $this->encryptionKey = getenv('ENCRYPTION_KEY') ?: bin2hex(random_bytes(32));
    }
    
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getKey($keyId)
    {
        if (isset($this->keys[$keyId])) {
            return $this->keys[$keyId];
        }
        
        $stmt = $this->db->prepare("SELECT key_value FROM encryption_keys WHERE key_id = :id AND active = true");
        $stmt->execute(['id' => $keyId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $this->keys[$keyId] = $result['key_value'];
            return $this->keys[$keyId];
        }
        
        return null;
    }
    
    public function encrypt($data, $context = 'default')
    {
        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public function decrypt($encryptedData, $context = 'default')
    {
        $data = base64_decode($encryptedData);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $this->encryptionKey, 0, $iv);
    }
    
    public function rotateKey($keyId)
    {
        $newKey = bin2hex(random_bytes(32));
        
        $stmt = $this->db->prepare("
            UPDATE encryption_keys 
            SET active = false, 
                retired_at = NOW() 
            WHERE key_id = :id AND active = true
        ");
        $stmt->execute(['id' => $keyId]);
        
        $stmt = $this->db->prepare("
            INSERT INTO encryption_keys (key_id, key_value, active, created_at)
            VALUES (:id, :value, true, NOW())
        ");
        $stmt->execute(['id' => $keyId, 'value' => $newKey]);
        
        $this->keys[$keyId] = $newKey;
        
        return $newKey;
    }
}
