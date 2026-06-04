<?php
// Zurubank OAuth Revoke Endpoint

header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . '/../../../config/db.php';

$database = new Database();
$db = $database->getConnection();

$input = json_decode(file_get_contents("php://input"), true);
$token = $input['token'] ?? null;
$tokenTypeHint = $input['token_type_hint'] ?? 'access_token';

if (!$token) {
    http_response_code(400);
    echo json_encode(["error" => "invalid_request", "error_description" => "Token required"]);
    exit;
}

// Authenticate client
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$clientId = null;
$clientSecret = null;

if (preg_match('/Basic\s+(.*)$/i', $authHeader, $matches)) {
    $credentials = base64_decode($matches[1]);
    list($clientId, $clientSecret) = explode(':', $credentials, 2);
}

if (!$clientId) {
    http_response_code(401);
    echo json_encode(["error" => "invalid_client", "error_description" => "Client authentication required"]);
    exit;
}

$stmt = $db->prepare("SELECT id FROM oauth_clients WHERE client_id = :id AND client_secret = :secret");
$stmt->execute(['id' => $clientId, 'secret' => $clientSecret]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    http_response_code(401);
    echo json_encode(["error" => "invalid_client", "error_description" => "Invalid client credentials"]);
    exit;
}

$db->beginTransaction();

try {
    if ($tokenTypeHint === 'access_token') {
        $stmt = $db->prepare("UPDATE oauth_access_tokens SET revoked = 1, revoked_at = NOW() WHERE token = :token");
        $stmt->execute(['token' => $token]);
        
        $stmt = $db->prepare("UPDATE oauth_refresh_tokens SET revoked = 1, revoked_at = NOW() WHERE access_token = :token");
        $stmt->execute(['token' => $token]);
        
    } elseif ($tokenTypeHint === 'refresh_token') {
        $stmt = $db->prepare("UPDATE oauth_refresh_tokens SET revoked = 1, revoked_at = NOW() WHERE token = :token");
        $stmt->execute(['token' => $token]);
        
        $stmt = $db->prepare("SELECT access_token FROM oauth_refresh_tokens WHERE token = :token");
        $stmt->execute(['token' => $token]);
        $refreshToken = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($refreshToken) {
            $stmt = $db->prepare("UPDATE oauth_access_tokens SET revoked = 1, revoked_at = NOW() WHERE token = :token");
            $stmt->execute(['token' => $refreshToken['access_token']]);
        }
    }
    
    $db->commit();
    
    echo json_encode(["status" => "success", "message" => "Token revoked successfully"]);
    
} catch (Exception $e) {
    $db->rollBack();
    http_response_code(500);
    echo json_encode(["error" => "server_error", "error_description" => $e->getMessage()]);
}
