<?php
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit;
}

function generateAccountNumber($length = 10) {
    return substr(str_shuffle("0123456789"), 0, $length);
}

function requireAuth() {
    session_start();
    if (!isset($_SESSION['user'])) {
        jsonResponse(["error" => "Unauthorized access"], 401);
    }
    return $_SESSION['user'];
}
?>
