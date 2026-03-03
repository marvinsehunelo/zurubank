<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Security: block access if session missing
if (empty($_SESSION['admin_id']) || empty($_SESSION['userRole'])) {
    header("Content-Type: application/json");
    echo json_encode([
        "status" => "error",
        "message" => "Not authenticated"
    ]);
    exit;
}
