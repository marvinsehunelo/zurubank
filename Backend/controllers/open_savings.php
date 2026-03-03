<?php
// CRITICAL: Ensure all statements end with a semicolon (;)

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Line 5: The missing semicolon was likely on the line above this one.
require_once __DIR__ . '/../config/db.php'; 
require_once __DIR__ . '/accounts.php'; // Assuming 'accounts.php' has the createAccount function

// Set content type to JSON
header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// 1. Check if user is logged in
if (!isset($_SESSION['user']['id'])) {
    $response['message'] = 'Authentication failed. Please log in.';
    echo json_encode($response);
    exit;
}

// 2. Check for POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method.';
    echo json_encode($response);
    exit;
}

$user_id = $_SESSION['user']['id'];
$account_type = 'savings'; // We hardcode this, as this file's purpose is to open a savings account

try {
    // 3. Check if user already has a savings account
    if (userHasAccountType($user_id, $account_type)) {
        $response['message'] = 'You already have a Savings Account.';
        echo json_encode($response);
        exit;
    }
    
    // 4. Create the new savings account (assuming a function like createAccount exists in accounts.php)
    $account_id = createAccount($user_id, $account_type, 0.00); // Start with 0 balance

    if ($account_id) {
        $response['success'] = true;
        $response['message'] = 'Savings Account opened successfully! Please refresh your dashboard.';
    } else {
        $response['message'] = 'Failed to create the account due to a database error.';
    }

} catch (Exception $e) {
    $response['message'] = 'Server Error: ' . $e->getMessage();
}

echo json_encode($response);
?>
