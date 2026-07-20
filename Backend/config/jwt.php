<?php
// Backend/api/v1/oauth/token.php
// Exchange authorization code for access token
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/jwt.php';
header('Content-Type: application/json');

// ============================================================
// FIX: Use $pdo (not $db) - matches your db.php
// ============================================================
if (!isset($pdo) || $pdo === null) {
    http_response_code(500);
    echo json_encode(['error' => 'database_error']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$grant_type = $input['grant_type'] ?? $_POST['grant_type'] ?? '';
$code = $input['code'] ?? $_POST['code'] ?? '';
$client_id = $input['client_id'] ?? $_POST['client_id'] ?? '';
$client_secret = $input['client_secret'] ?? $_POST['client_secret'] ?? '';
$refresh_token = $input['refresh_token'] ?? $_POST['refresh_token'] ?? '';

// Validate client
if ($client_id !== 'VOUCHMORPH_APP_ID' || $client_secret !== 'YOUR_BANK_SECRET') {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_client']);
    exit;
}

if ($grant_type === 'authorization_code') {
    // ============================================================
    // Postgres-compatible boolean check (was: used = 0)
    // ============================================================
    $stmt = $pdo->prepare("SELECT * FROM oauth_auth_codes WHERE code = ? AND expires_at > NOW() AND used = false");
    $stmt->execute([$code]);
    $auth_code = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$auth_code) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant']);
        exit;
    }

    // Mark code as used — boolean, not integer
    $stmt = $pdo->prepare("UPDATE oauth_auth_codes SET used = true WHERE code = ?");
    $stmt->execute([$code]);

    // ============================================================
    // FIX: jwt.php defines createToken($user_id, $role), not
    // generateJWT($claims). Use it as-is — it already sets iss,
    // aud, iat, and a 24h exp internally.
    // ============================================================
    $access_token = createToken($auth_code['user_id'], $auth_code['scope'] ?? 'read_balance');

    // Generate refresh token — Postgres interval syntax, not MySQL's DATE_ADD
    $refresh_token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO oauth_refresh_tokens (token, user_id, client_id, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL '30 days')");
    $stmt->execute([$refresh_token, $auth_code['user_id'], $client_id]);

    echo json_encode([
        'access_token' => $access_token,
        'token_type' => 'Bearer',
        'expires_in' => 86400, // matches jwt.php's hardcoded 24h exp
        'refresh_token' => $refresh_token,
        'scope' => $auth_code['scope']
    ]);

} elseif ($grant_type === 'refresh_token') {
    // ============================================================
    // Postgres-compatible boolean check (was: revoked = 0)
    // ============================================================
    $stmt = $pdo->prepare("SELECT * FROM oauth_refresh_tokens WHERE token = ? AND expires_at > NOW() AND revoked = false");
    $stmt->execute([$refresh_token]);
    $token = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$token) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_grant']);
        exit;
    }

    $new_access_token = createToken($token['user_id'], $token['scope'] ?? 'read_balance');

    echo json_encode([
        'access_token' => $new_access_token,
        'token_type' => 'Bearer',
        'expires_in' => 86400
    ]);
}
