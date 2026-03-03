<?php
require_once __DIR__ . '/../../vendor/autoload.php'; // Composer autoload

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$jwt_secret = "ZURU_BANK_SECRET_KEY_123"; // Change to something strong

function createToken($user_id, $role) {
    global $jwt_secret;

    $payload = [
        'iss' => 'zurubank',     // Issuer
        'aud' => 'zurubank_users',
        'iat' => time(),          // Issued at
        'exp' => time() + (3600 * 24), // Expires in 24 hours
        'user_id' => $user_id,
        'role' => $role
    ];

    return JWT::encode($payload, $jwt_secret, 'HS256');
}

function verifyToken($token) {
    global $jwt_secret;

    try {
        $decoded = JWT::decode($token, new Key($jwt_secret, 'HS256'));
        return (array)$decoded;
    } catch (Exception $e) {
        return false;
    }
}
