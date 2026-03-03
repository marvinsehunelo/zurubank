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
        // Validate API key against database - using your schema
        $stmt = $pdo->prepare("SELECT * FROM api_keys WHERE api_key = ? AND active = TRUE");
        $stmt->execute([$api_key]);
        $key = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$key) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid or inactive API key'
            ]);
            exit;
        }
        
        return $key;
        
    } catch (PDOException $e) {
        // Log error but don't expose to client
        error_log("Auth DB Error: " . $e->getMessage());
        
        // For testing only - accept test key
        if ($api_key === 'test_key_123') {
            return [
                'client_name' => 'Test Client',
                'api_key' => 'test_key_123',
                'active' => true
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

function generate_trace() {
    return 'TXN' . time() . rand(1000, 9999);
}

function generate_auth_code() {
    return 'AUTH' . rand(100000, 999999);
}

function generate_voucher_token() {
    return str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT);
}
