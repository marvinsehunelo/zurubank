<?php
// Backend/api/v1/accounts/balance.php
// Get account balance (requires valid access token)

require_once '../../config/db.php';
require_once '../../config/jwt.php';

header('Content-Type: application/json');

// Verify Bearer token
$headers = getallheaders();
$auth_header = $headers['Authorization'] ?? '';
$token = str_replace('Bearer ', '', $auth_header);

$payload = verifyJWT($token);
if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$account_id = $_GET['account_id'] ?? $_POST['account_id'] ?? '';

// Get balance for the authenticated user's account
$stmt = $db->prepare("SELECT account_number, balance, currency FROM accounts WHERE user_id = ? AND account_number = ?");
$stmt->execute([$payload['user_id'], $account_id]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    http_response_code(404);
    echo json_encode(['error' => 'account_not_found']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'account_number' => $account['account_number'],
        'balance' => $account['balance'],
        'currency' => $account['currency']
    ]
]);
