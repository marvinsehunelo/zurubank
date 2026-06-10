<?php
// /opt/lampp/htdocs/zurubank/Backend/api/v1/transaction/status.php

header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../helpers/crypto.php';  // Add signature functions

$client = validate_api_key();
$trace = $_GET['trace'] ?? null;

if (!$trace) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Trace number required']);
    exit;
}

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    $responsePayload = null;
    
    // Check in transactions table
    $stmt = $pdo->prepare("SELECT * FROM transactions WHERE trace_number = ?");
    $stmt->execute([$trace]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($transaction) {
        $responsePayload = [
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $transaction['status'],
            'amount' => $transaction['amount'],
            'currency' => $transaction['currency'] ?? 'BWP',
            'type' => $transaction['type'],
            'timestamp' => $transaction['created_at']
        ];
    }
    
    // Check in instant_money_vouchers
    if (!$responsePayload) {
        $stmt = $pdo->prepare("SELECT * FROM instant_money_vouchers WHERE voucher_number = ?");
        $stmt->execute([$trace]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($voucher) {
            $responsePayload = [
                'status' => 'success',
                'trace_number' => $trace,
                'transaction_status' => $voucher['status'],
                'amount' => $voucher['amount'],
                'currency' => $voucher['currency'] ?? 'BWP',
                'type' => 'VOUCHER',
                'timestamp' => $voucher['created_at']
            ];
        }
    }
    
    // Check in incoming_pre_advice
    if (!$responsePayload) {
        $stmt = $pdo->prepare("SELECT * FROM incoming_pre_advice WHERE trace_number = ?");
        $stmt->execute([$trace]);
        $advice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($advice) {
            $responsePayload = [
                'status' => 'success',
                'trace_number' => $trace,
                'transaction_status' => $advice['status'],
                'amount' => $advice['amount'],
                'currency' => $advice['currency'] ?? 'BWP',
                'type' => 'PRE_ADVICE',
                'timestamp' => $advice['created_at']
            ];
        }
    }
    
    if (!$responsePayload) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Transaction not found',
            'timestamp' => time()
        ]);
        exit;
    }
    
    // ============================================================
    // SEND SIGNED RESPONSE (proves status came from Zurubank)
    // ============================================================
    send_signed_response($responsePayload);
    
} catch (Exception $e) {
    error_log("Status Query Error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Status query failed',
        'timestamp' => time()
    ]);
}
