<?php
// secure_api_header.php
header("Content-Type: application/json; charset=utf-8");
require_once(__DIR__ . "/../db.php");

// --- Get Authorization token from headers or GET ---
$headers = getallheaders();
$token = $headers['Authorization'] ?? ($_GET['token'] ?? null);
$token = trim($token ?? '');

if (!$token) {
    echo json_encode(["status" => "error", "message" => "Token required"]);
    exit;
}

// --- Verify token from sessions table ---
try {
    $stmt = $pdo->prepare("SELECT user_id, expires_at FROM sessions WHERE token = ? LIMIT 1");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(["status" => "error", "message" => "Invalid token"]);
        exit;
    }

    // --- Optional: Check expiration ---
    if (!empty($session['expires_at']) && strtotime($session['expires_at']) < time()) {
        echo json_encode(["status" => "error", "message" => "Session expired"]);
        exit;
    }

    // --- Valid token ---
    $user_id = $session['user_id'];

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    exit;
}
?>
