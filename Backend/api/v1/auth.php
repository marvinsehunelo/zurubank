<?php
// /opt/lampp/htdocs/zurubank/Backend/api/v1/auth.php
require_once __DIR__ . '/../../config/db.php';

function validate_api_key() {
    $headers = getallheaders();
    
    // Convert headers to case-insensitive lookup
    $headers = array_change_key_case($headers, CASE_LOWER);
    
    $api_key = $headers['x-api-key'] ?? null;
    
    if (!$api_key) {
        // Try Authorization header
        $auth_header = $headers['authorization'] ?? '';
        if (strpos($auth_header, 'Bearer ') === 0) {
            $api_key = substr($auth_header, 7);
        }
    }
    
    if (!$api_key) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'API key missing'
        ]);
        exit;
    }
    
    global $pdo;
    
    try {
        // FIRST: Check Railway Vault for valid API keys
        $vaultApiKeys = getValidKeysFromVault();
        
        // Check if the provided key matches any vault key
        if (in_array($api_key, $vaultApiKeys)) {
            error_log("[Auth] API key validated from Railway Vault");
            return [
                'client_name' => 'VouchMorphn System',
                'api_key' => $api_key,
                'active' => true,
                'source' => 'railway_vault'
            ];
        }
        
        // SECOND: Fallback to database for legacy keys
        $stmt = $pdo->prepare("SELECT * FROM api_keys WHERE api_key = ? AND active = TRUE");
        $stmt->execute([$api_key]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($key) {
            error_log("[Auth] API key validated from database");
            return $key;
        }
        
        // No valid key found
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or inactive API key'
        ]);
        exit;
        
    } catch (PDOException $e) {
        error_log("Auth DB Error: " . $e->getMessage());
        
        // When database is unavailable, ONLY use vault keys
        $vaultApiKeys = getValidKeysFromVault();
        if (in_array($api_key, $vaultApiKeys)) {
            error_log("[Auth] API key validated from Railway Vault (DB fallback)");
            return [
                'client_name' => 'VouchMorphn System',
                'api_key' => $api_key,
                'active' => true,
                'source' => 'railway_vault'
            ];
        }
        
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => 'Authentication service unavailable'
        ]);
        exit;
    }
}

/**
 * Get valid API keys from Railway Vault
 * Railway injects these as environment variables
 */
function getValidKeysFromVault(): array {
    $validKeys = [];
    
    // Check for ZURUBANK's own API key from vault
    $zurubankKey = getenv('ZURUBANK_API_KEY');
    if ($zurubankKey) {
        $validKeys[] = $zurubankKey;
    }
    
    // Check for UPSTREAM pattern (Railway vault)
    $upstreamKey = getenv('UPSTREAM_ZURUBANK_KEY');
    if ($upstreamKey) {
        $validKeys[] = $upstreamKey;
    }
    
    // Check for system API keys that might call ZURUBANK
    $systemKey = getenv('API_KEY_SYSTEM');
    if ($systemKey) {
        $validKeys[] = $systemKey;
    }
    
    // Check for VouchMorph API key
    $vouchKey = getenv('VOUCHMORPH_API_KEY');
    if ($vouchKey) {
        $validKeys[] = $vouchKey;
    }
    
    // Check for any environment variable that looks like an API key
    foreach ($_SERVER as $key => $value) {
        if (preg_match('/API_KEY|_KEY$|UPSTREAM/', $key) && !empty($value)) {
            if (strlen($value) > 20) { // Likely an API key
                $validKeys[] = $value;
            }
        }
    }
    
    return array_unique(array_filter($validKeys));
}

/**
 * Log authentication attempts (for security auditing)
 */
function log_auth_attempt($api_key, $success, $source = null) {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'api_key_prefix' => substr($api_key, 0, 10) . '...',
        'success' => $success,
        'source' => $source,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    error_log("[Auth] " . json_encode($log_entry));
    
    // Optionally store in database for audit trail
    global $pdo;
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO auth_log (api_key_hash, success, source, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                hash('sha256', $api_key),
                $success ? 1 : 0,
                $source,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // Silently fail logging
        }
    }
}

function generate_trace() {
    return 'TXN' . time() . rand(1000, 9999);
}

function generate_auth_code() {
    return 'AUTH' . rand(100000, 999999);
}

function generate_voucher_token() {
    return str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT);
}
