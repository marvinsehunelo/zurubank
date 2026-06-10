<?php
// /opt/lampp/htdocs/zurubank/Backend/api/v1/transaction/reverse.php

header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers/crypto.php';  // Add signature functions

// Validate API key first
$client = validate_api_key();

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['original_trace']) || !isset($data['reason'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing required fields']);
    exit;
}

// ============================================================
// VERIFY SIGNATURE FROM REQUESTER (VouchMorph or Source Bank)
// ============================================================
$signature = $data['signature'] ?? null;
$timestamp = $data['timestamp'] ?? null;
$requesterPayload = [
    'original_trace' => $data['original_trace'],
    'reason' => $data['reason'],
    'amount' => $data['amount'] ?? null
];

if (!$signature) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing signature - reversal requests must be signed'
    ]);
    exit;
}

// Determine which public key to use based on who is requesting
// If request comes from VouchMorph, use VouchMorph's public key
// If from another bank, use that bank's public key
$requester = $data['requester'] ?? 'VOUCHMORPH';
$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => "No public key found for requester: {$requester}"
    ]);
    exit;
}

// Verify the signature
$isValid = verify_signature(
    $requesterPayload,
    $signature,
    $publicKey,
    $timestamp
);

if (!$isValid) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid signature - reversal request cannot be trusted'
    ]);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    // First, find the original transaction
    $findStmt = $pdo->prepare("
        SELECT t.*, a.account_number, a.user_id, a.currency
        FROM transactions t
        JOIN accounts a ON t.account_id = a.account_id
        WHERE t.trace_number = ?
    ");
    $findStmt->execute([$data['original_trace']]);
    $original = $findStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$original) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Original transaction not found'
        ]);
        exit;
    }
    
    // Check if already reversed
    $checkStmt = $pdo->prepare("SELECT id FROM transaction_reversals WHERE original_trace = ?");
    $checkStmt->execute([$data['original_trace']]);
    if ($checkStmt->fetch()) {
        echo json_encode([
            'status' => 'duplicate',
            'message' => 'Transaction already reversed'
        ]);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Reverse the amount (credit back)
    if ($original['type'] === 'deposit' || $original['type'] === 'credit') {
        // For deposits, we need to debit the account
        $updateStmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?");
        $updateStmt->execute([$original['amount'], $original['account_id']]);
    } else {
        // For debits/withdrawals, credit back
        $updateStmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
        $updateStmt->execute([$original['amount'], $original['account_id']]);
    }
    
    // Create reversal record
    $reversal_trace = 'REV-' . time() . rand(1000, 9999);
    $revStmt = $pdo->prepare("
        INSERT INTO transaction_reversals (original_trace, reversal_trace, reason, reversed_by, reversed_at) 
        VALUES (?, ?, ?, ?, NOW())
    ");
    $revStmt->execute([
        $data['original_trace'], 
        $reversal_trace, 
        $data['reason'],
        $requester
    ]);
    
    // Update original transaction status
    $updateTransStmt = $pdo->prepare("UPDATE transactions SET status = 'reversed', reversed_at = NOW() WHERE trace_number = ?");
    $updateTransStmt->execute([$data['original_trace']]);
    
    $pdo->commit();
    
    // ============================================================
    // SEND SIGNED RESPONSE
    // ============================================================
    $responsePayload = [
        'status' => 'success',
        'reversal_trace' => $reversal_trace,
        'original_trace' => $data['original_trace'],
        'amount' => $original['amount'],
        'currency' => $original['currency'] ?? 'BWP',
        'reversed_at' => date('Y-m-d H:i:s'),
        'reversed_by' => $requester
    ];
    
    // Send signed response (proves Zurubank executed the reversal)
    send_signed_response($responsePayload);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Reverse Error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Reversal failed: ' . $e->getMessage()
    ]);
}

/**
 * Get public key for a requester (VouchMorph or partner bank)
 */
function get_requester_public_key($requester, $pdo) {
    // First check database
    $stmt = $pdo->prepare("
        SELECT public_key FROM trusted_partners 
        WHERE name = :requester AND is_active = true
        LIMIT 1
    ");
    $stmt->execute(['requester' => $requester]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && !empty($result['public_key'])) {
        return $result['public_key'];
    }
    
    // Fallback to environment variable
    $envKey = strtoupper($requester) . '_PUBLIC_KEY';
    $publicKey = getenv($envKey);
    
    if ($publicKey) {
        return $publicKey;
    }
    
    return null;
}
