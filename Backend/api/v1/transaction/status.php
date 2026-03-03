<?php
// /opt/lampp/htdocs/zurubank/Backend/api/v1/transaction/status.php
header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';

$client = validate_api_key();
$trace = $_GET['trace'] ?? null;

if (!$trace) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Trace number required']);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    // Check in transactions table
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE trace_number = ?");
    $stmt->execute([$trace]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($transaction) {
        echo json_encode([
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $transaction['status'],
            'amount' => $transaction['amount'],
            'type' => $transaction['type'],
            'timestamp' => $transaction['created_at']
        ]);
        exit;
    }
    
    // Check in instant_money_vouchers
    $stmt = $pdo->prepare("SELECT * FROM instant_money_vouchers WHERE voucher_number = ?");
    $stmt->execute([$trace]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($voucher) {
        echo json_encode([
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $voucher['status'],
            'amount' => $voucher['amount'],
            'type' => 'VOUCHER',
            'timestamp' => $voucher['created_at']
        ]);
        exit;
    }
    
    // Check in incoming_pre_advice
    $stmt = $pdo->prepare("SELECT * FROM incoming_pre_advice WHERE trace_number = ?");
    $stmt->execute([$trace]);
    $advice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($advice) {
        echo json_encode([
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $advice['status'],
            'amount' => $advice['amount'],
            'type' => 'PRE_ADVICE',
            'timestamp' => $advice['created_at']
        ]);
        exit;
    }
    
    echo json_encode([
        'status' => 'error',
        'message' => 'Transaction not found'
    ]);
    
} catch (Exception $e) {
    error_log("Status Query Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Status query failed']);
}
