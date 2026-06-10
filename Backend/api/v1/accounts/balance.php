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
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized - valid token required',
        'timestamp' => time()
    ]);
    exit;
}

$account_id = $_GET['account_id'] ?? $_POST['account_id'] ?? '';

if (empty($account_id)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'account_id required',
        'timestamp' => time()
    ]);
    exit;
}

try {
    // Get balance for the authenticated user's account
    $stmt = $pdo->prepare("
        SELECT account_number, balance, currency, account_name, status 
        FROM accounts 
        WHERE user_id = ? AND account_number = ?
        LIMIT 1
    ");
    $stmt->execute([$payload['user_id'], $account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Account not found or not owned by user',
            'timestamp' => time()
        ]);
        exit;
    }

    if ($account['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Account is not active',
            'timestamp' => time()
        ]);
        exit;
    }

    // Optional: Add audit log for balance check (good for compliance)
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (entity, entity_id, action, category, severity, performed_by, performed_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $auditStmt->execute([
        'accounts',
        $account_id,
        'BALANCE_CHECK',
        'inquiry',
        'info',
        $payload['user_id']
    ]);

    // Return balance (no signature needed for read-only)
    echo json_encode([
        'status' => 'success',
        'data' => [
            'account_number' => $account['account_number'],
            'account_name' => $account['account_name'],
            'balance' => floatval($account['balance']),
            'currency' => $account['currency'],
            'timestamp' => time()
        ]
    ]);

} catch (Exception $e) {
    error_log("Balance check error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error',
        'timestamp' => time()
    ]);
}
