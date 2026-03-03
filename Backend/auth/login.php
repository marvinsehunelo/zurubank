<?php
error_reporting(E_ERROR | E_PARSE);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config/db.php";
header("Content-Type: application/json");

// Enable PDO exceptions
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? '');
$password = $data['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(["status" => "error", "message" => "Email and password required"]);
    exit;
}

try {
    // Fetch user by email
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email=?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        echo json_encode(["status" => "error", "message" => "No user found with that email"]);
        exit;
    }

    // Verify password
    if (!password_verify($password, $user['password'])) {
        echo json_encode(["status" => "error", "message" => "Invalid password"]);
        exit;
    }

    // Generate token
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+12 hours'));

    // Insert session
    $stmtToken = $pdo->prepare("
        INSERT INTO sessions (user_id, token, expires_at)
        VALUES (?, ?, ?)
    ");
    $stmtToken->execute([$user['user_id'], $token, $expires_at]);

    // Set PHP session
    $_SESSION['user'] = [
        'id' => $user['user_id'],
        'full_name' => $user['full_name']
    ];
    $_SESSION['authToken'] = $token;

    echo json_encode([
        "status" => "success",
        "message" => "Login successful",
        "token" => $token,
        "full_name" => $user['full_name']
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "debug" => "Exception: " . $e->getMessage()
    ]);
}
