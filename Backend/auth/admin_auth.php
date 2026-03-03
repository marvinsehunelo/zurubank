<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header("Content-Type: application/json; charset=utf-8");
require_once("../db.php"); // your PDO $pdo connection

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("POST required");
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // Accept either username or email
    $login = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (!$login || !$password) {
        throw new Exception("Username/email and password required");
    }

    // Lookup admin user by email or username
    $stmt = $pdo->prepare("
        SELECT user_id, full_name, username, email, password_hash, role, status
        FROM users
        WHERE (email = ? OR username = ?) AND role != 'client'
        LIMIT 1
    ");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        throw new Exception("Invalid credentials");
    }

    if ($user['status'] !== 'active') {
        throw new Exception("Account not active");
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        sleep(1); // optional: slow down brute-force attempts
        throw new Exception("Invalid credentials");
    }

    // Generate token
    $token = bin2hex(random_bytes(32));
    $expiresAt = (new DateTime('+12 hours'))->format('Y-m-d H:i:s');

    // Save token in sessions table
    $stmt = $pdo->prepare("INSERT INTO sessions (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)");
    $stmt->execute([$user['user_id'], $token, $expiresAt]);

    // Return JSON
    echo json_encode([
        "status" => "success",
        "token" => $token,
        "full_name" => $user['full_name'],
        "role" => $user['role'],
        "expires_at" => $expiresAt
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
