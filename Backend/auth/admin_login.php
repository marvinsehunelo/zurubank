<?php
/**
 * Admin Authentication Handler (Login/Logout)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

// NOTE: In a production environment, credentials MUST be stored securely (e.g., hashed, in environment variables).
// Hardcoded for development simplicity.
const ADMIN_USERNAME = 'admin_user';
const ADMIN_PASSWORD = 'supersecurepassword'; // Replace this!

// Helper for JSON response
function jsonResponse($success, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

$action = $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user_id'] = 1; // Arbitrary ID for tracking
            jsonResponse(true, 'Login successful', ['user' => $username]);
        } else {
            // Delay response slightly to deter brute-force attempts
            sleep(1); 
            jsonResponse(false, 'Invalid credentials');
        }
        break;

    case 'logout':
        // Destroy only the admin session variables
        unset($_SESSION['admin_logged_in']);
        unset($_SESSION['admin_user_id']);
        jsonResponse(true, 'Logged out successfully');
        break;

    case 'check':
        // Used by the frontend to verify if the admin is already logged in
        if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
            jsonResponse(true, 'Authenticated');
        } else {
            jsonResponse(false, 'Not authenticated');
        }
        break;

    default:
        jsonResponse(false, 'Invalid action');
}
