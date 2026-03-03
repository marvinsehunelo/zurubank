<?php
// zurubank_get_balances.php — fetch balances for specific account types in ZuruBank
declare(strict_types=1);
require_once __DIR__ . "/../config/db.php";
header("Content-Type: application/json; charset=utf-8");

function jsonResponse(string $status, string $message, array $extra = []): void {
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra));
    exit;
}

try {
    // -------------------------
    // AUTHENTICATION
    // -------------------------
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $apiKey = $headers['X-API-Key'] ?? ($_POST['api_key'] ?? null);
    $token  = $headers['Authorization'] ?? ($_POST['token'] ?? null);
    $request_user_id = null;

    if ($apiKey) {
        $validApiKeys = ['ZURU_LOCAL_KEY_ABC123'];
        if (!in_array($apiKey, $validApiKeys, true)) {
            jsonResponse('error', 'Invalid API key');
        }
        $request_user_id = 2; // System/middleman user
    } elseif ($token) {
        if (str_starts_with($token, 'Bearer ')) $token = substr($token, 7);
        session_start();

        $stmt = $pdo->prepare("
            SELECT user_id 
            FROM sessions 
            WHERE token = ? AND (expires_at IS NULL OR expires_at > NOW()) 
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) jsonResponse('error', 'Invalid or expired token');
        $request_user_id = $user['user_id'];
    } else {
        jsonResponse('error', 'Token or API key required');
    }

    // -------------------------
    // INPUT (optional user filter)
    // -------------------------
    $data = json_decode(file_get_contents("php://input"), true) ?? $_POST;
    $user_id_filter = isset($data['user_id']) ? (int)$data['user_id'] : null;

    // -------------------------
    // FETCH BALANCES
    // -------------------------
    $account_types = ['partner_bank_settlement', 'middleman_revenue', 'middleman_escrow'];

    $query = "SELECT account_id, user_id, account_number, account_type, balance, currency, status, created_at 
              FROM accounts 
              WHERE account_type IN (" . implode(',', array_fill(0, count($account_types), '?')) . ")";
    $params = $account_types;

    if ($user_id_filter) {
        $query .= " AND user_id = ?";
        $params[] = $user_id_filter;
    }

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);

    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse('success', 'ZuruBank account balances retrieved', ['accounts' => $accounts]);

} catch (Exception $e) {
    jsonResponse('error', $e->getMessage());
}

