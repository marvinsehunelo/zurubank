<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once("../db.php");
header("Content-Type: application/json; charset=utf-8");

try {
    // --- Only accept POST ---
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception("POST method required");
    }

    // --- Get Authorization header ---
    $authHeader = '';
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
    }
    if (!$authHeader) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    }

    $token = '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
    }
    if (!$token) throw new Exception("Authorization token required");

    // --- Validate token ---
    $stmt = $pdo->prepare("
        SELECT s.user_id, u.role, u.full_name 
        FROM sessions s 
        JOIN users u ON s.user_id = u.user_id 
        WHERE s.token = ? AND s.expires_at > NOW() 
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new Exception("Invalid or expired token");

    $logged_in_role = strtolower($user['role']);

    // --- Get input ---
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $name = trim($input['full_name'] ?? '');
    $email = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    $role_to_add = strtolower(trim($input['role'] ?? ''));

    // --- Handle phone properly (optional) ---
    $phone = $input['phone'] ?? null;
    $phone = ($phone === '' || $phone === null) ? null : trim($phone);

    // --- Input validation ---
    if (!$name || !$email || !$password || !$role_to_add) {
        throw new Exception("All fields are required (phone is optional)");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception("Invalid email format for username.");
    }

    // --- Role hierarchy check ---
    $role_hierarchy = [
        'superadmin' => 5,
        'admin'      => 4,
        'manager'    => 3,
        'teller'     => 2,
        'auditor'    => 2,
        'compliance' => 2,
        'customer'   => 1
    ];

    if (!isset($role_hierarchy[$logged_in_role]) || !isset($role_hierarchy[$role_to_add])) {
        throw new Exception("Invalid role specified.");
    }

    if ($role_to_add === 'customer') {
        throw new Exception("Cannot use this endpoint to add a customer.");
    }

    if ($role_hierarchy[$logged_in_role] <= $role_hierarchy[$role_to_add]) {
        throw new Exception("You do not have sufficient permission to add this role.");
    }

    // --- Hash password ---
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // --- Begin transaction ---
    $pdo->beginTransaction();

    // --- Insert into users table ---
    $stmtUser = $pdo->prepare("
        INSERT INTO users (full_name, email, password_hash, role, status, phone)
        VALUES (?, ?, ?, ?, 'active', ?)
    ");
    if (!$stmtUser->execute([$name, $email, $hashed_password, $role_to_add, $phone])) {
        $pdo->rollBack();
        if ($pdo->errorCode() === '23000') {
            throw new Exception("User with this email or phone already exists.");
        }
        throw new Exception("Failed to add staff to users table.");
    }
    $new_user_id = $pdo->lastInsertId();

    // --- Insert default account for staff ---
    $account_number = 'STAFF' . $new_user_id;
    $stmtAdmin = $pdo->prepare("
        INSERT INTO accounts (user_id, account_type, account_number)
        VALUES (?, 'current', ?)
    ");
    if (!$stmtAdmin->execute([$new_user_id, $account_number])) {
        $pdo->rollBack();
        throw new Exception("Failed to create default account for staff.");
    }

    $pdo->commit();

    echo json_encode([
        'status' => 'success',
        'message' => "Staff '$name' added successfully"
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $httpCode = ($e->getMessage() === "Invalid or expired token" || $e->getMessage() === "Authorization token required") ? 401 : 400;
    http_response_code($httpCode);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
