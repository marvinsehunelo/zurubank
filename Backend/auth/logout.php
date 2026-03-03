<?php
session_start();

// If logout via URL
if (isset($_GET['action']) && $_GET['action'] === 'session_logout') {
    session_unset();
    session_destroy();
    header("Location: ../../Frontend/auth/login.php");
    exit;
}

// Default JSON API logout (for JS calls)
header('Content-Type: application/json');
$data = json_decode(file_get_contents("php://input"), true);
$token = $data['token'] ?? null;

try {
    require_once __DIR__ . "/../config/db.php";

    if ($token) {
        $stmt = $pdo->prepare("DELETE FROM sessions WHERE token = ?");
        $stmt->execute([$token]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_unset();
        session_destroy();
    }

    echo json_encode(["status" => "success", "message" => "Logged out successfully"]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
