<?php
// Backend/auth/admin_auth_handler.php
//
// JSON sign-in, sign-out and session check for the admin dashboard, against the admin_users
// table (Backend/auth/admin_users.php). Used by Frontend/dashboard/admin_login.php and
// Backend/auth/admin_login.php. Responses never say whether a username exists.

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/admin_users.php';

function zb_auth_json(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    zb_auth_json(false, 'POST only');
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $row = ($username !== '' && $password !== '') ? zb_admin_authenticate($pdo, $username, $password) : null;
        if ($row === null) {
            sleep(1); // slows down guessing
            zb_auth_json(false, 'Invalid credentials');
        }
        zb_admin_sign_in($row);
        error_log('[admin] staff #' . (int)$row['id'] . ' signed in');
        zb_auth_json(true, 'Login successful', [
            'user' => $row['username'],
            'name' => $row['full_name'],
            'role' => $row['role'],
        ]);
        break;

    case 'logout':
        zb_admin_sign_out();
        zb_auth_json(true, 'Logged out successfully');
        break;

    case 'check':
        $row = zb_admin_current($pdo);
        if ($row === null) zb_auth_json(false, 'Not authenticated');
        zb_auth_json(true, 'Authenticated', [
            'user' => $row['username'],
            'name' => $row['full_name'],
            'role' => $row['role'],
        ]);
        break;

    default:
        zb_auth_json(false, 'Invalid action');
}
