<?php
// /opt/lampp/htdocs/zurubank/Backend/api/v1/transaction/status.php

header('Content-Type: application/json');
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

// Validate API key first
$client = validate_api_key();

// Get parameters from GET request (or POST body for certificate)
$trace = $_GET['trace'] ?? $_POST['trace'] ?? null;

// Also check for certificate in POST body (if called via POST)
$input = json_decode(file_get_contents('php://input'), true);
$certificate = $input['certificate'] ?? null;
$signature = $input['signature'] ?? $_GET['signature'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? null;
$timestamp = $input['timestamp'] ?? $_GET['timestamp'] ?? $_SERVER['HTTP_X_TIMESTAMP'] ?? null;
$requester = $input['requester'] ?? $_GET['requester'] ?? $_SERVER['HTTP_X_REQUESTER'] ?? 'ZURUBANK';

if (!$trace) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Trace number required']);
    exit;
}

// ============================================================
// VERIFY WITH CERTIFICATE OR SIGNATURE (Optional for queries)
// ============================================================
$signatureVerified = false;
$verificationMethod = 'none';

// Method 1: Certificate-based verification (preferred)
if ($certificate) {
    $certManager = new CertificateManager('ZURUBANK');
    
    // Create a verification payload
    $verifyRequest = [
        'certificate' => $certificate,
        'signature' => $signature,
        'requester' => $requester,
        'timestamp' => $timestamp,
        'trace' => $trace
    ];
    
    $verification = $certManager->verifySignedRequest($verifyRequest);
    $signatureVerified = $verification['verified'];
    $requester = $verification['requester'] ?? $requester;
    $verificationMethod = 'certificate';
    
    if ($signatureVerified) {
        error_log("ZURUBANK STATUS: Certificate verified from {$requester}");
    } else {
        error_log("ZURUBANK STATUS: Certificate verification failed from {$requester}: " . ($verification['message'] ?? 'Unknown'));
    }
}
// Method 2: Legacy signature verification (backward compatible)
else if ($signature) {
    $payloadToVerify = ['trace' => $trace];
    $publicKey = get_requester_public_key($requester, $pdo);
    if ($publicKey) {
        $signatureVerified = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);
        $verificationMethod = 'legacy_signature';
        if ($signatureVerified) {
            error_log("ZURUBANK STATUS: Legacy signature verified from {$requester}");
        } else {
            error_log("ZURUBANK STATUS: Invalid legacy signature from {$requester}");
        }
    }
} else {
    error_log("ZURUBANK STATUS: No verification provided from {$requester}");
}

try {
    require_once __DIR__ . '/../../../config/db.php';
    
    $responsePayload = null;
    
    // Check in transactions table
    $stmt = $pdo->prepare("
        SELECT t.*, a.account_number, a.currency 
        FROM transactions t
        LEFT JOIN accounts a ON t.account_id = a.account_id
        WHERE t.trace_number = :trace OR t.reference = :trace
    ");
    $stmt->execute(['trace' => $trace]);
    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($transaction) {
        $responsePayload = [
            'status' => 'success',
            'trace_number' => $trace,
            'transaction_status' => $transaction['status'],
            'amount' => (float)$transaction['amount'],
            'currency' => $transaction['currency'] ?? 'BWP',
            'type' => $transaction['type'],
            'account_number' => $transaction['account_number'] ?? null,
            'timestamp' => $transaction['created_at'],
            'updated_at' => $transaction['updated_at'] ?? $transaction['created_at']
        ];
        
        // Add reversal info if reversed
        if ($transaction['status'] === 'reversed') {
            $revStmt = $pdo->prepare("
                SELECT reversal_trace, reason, reversed_by, reversed_at 
                FROM transaction_reversals 
                WHERE original_trace = :trace
                LIMIT 1
            ");
            $revStmt->execute(['trace' => $trace]);
            $reversal = $revStmt->fetch(PDO::FETCH_ASSOC);
            if ($reversal) {
                $responsePayload['reversal_info'] = [
                    'reversal_trace' => $reversal['reversal_trace'],
                    'reason' => $reversal['reason'],
                    'reversed_by' => $reversal['reversed_by'],
                    'reversed_at' => $reversal['reversed_at']
                ];
            }
        }
    }
    
    // Check in instant_money_vouchers
    if (!$responsePayload) {
        $stmt = $pdo->prepare("
            SELECT v.*, u.phone
            FROM instant_money_vouchers v
            LEFT JOIN users u ON v.recipient_phone = u.phone
            WHERE v.voucher_number = :trace OR v.voucher_id::text = :trace
        ");
        $stmt->execute(['trace' => $trace]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($voucher) {
            $responsePayload = [
                'status' => 'success',
                'trace_number' => $trace,
                'transaction_status' => $voucher['status'],
                'amount' => (float)$voucher['amount'],
                'currency' => $voucher['currency'] ?? 'BWP',
                'type' => 'VOUCHER',
                'recipient_phone' => $voucher['recipient_phone'],
                'timestamp' => $voucher['created_at'],
                'expires_at' => $voucher['expires_at'] ?? null
            ];
            
            // Add hold info if on hold
            if ($voucher['status'] === 'hold') {
                $responsePayload['hold_info'] = [
                    'source_hold_reference' => $voucher['source_hold_reference'],
                    'hold_expires_at' => $voucher['hold_expires_at'],
                    'held_by' => $voucher['held_by'] ?? null
                ];
            }
        }
    }
    
    // Check in incoming_pre_advice
    if (!$responsePayload) {
        $stmt = $pdo->prepare("
            SELECT * FROM incoming_pre_advice 
            WHERE trace_number = :trace OR reference = :trace
        ");
        $stmt->execute(['trace' => $trace]);
        $advice = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($advice) {
            $responsePayload = [
                'status' => 'success',
                'trace_number' => $trace,
                'transaction_status' => $advice['status'],
                'amount' => (float)$advice['amount'],
                'currency' => $advice['currency'] ?? 'BWP',
                'type' => 'PRE_ADVICE',
                'from_bank' => $advice['from_bank'] ?? null,
                'timestamp' => $advice['created_at']
            ];
        }
    }
    
    // Check in financial_holds
    if (!$responsePayload) {
        $stmt = $pdo->prepare("
            SELECT fh.*, 
                   CASE WHEN fh.account_id IS NOT NULL THEN 'ACCOUNT' ELSE 'WALLET' END as hold_type
            FROM financial_holds fh
            WHERE fh.hold_reference = :trace
        ");
        $stmt->execute(['trace' => $trace]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($hold) {
            $responsePayload = [
                'status' => 'success',
                'trace_number' => $trace,
                'transaction_status' => $hold['status'],
                'amount' => (float)$hold['amount'],
                'currency' => 'BWP',
                'type' => 'FINANCIAL_HOLD',
                'hold_type' => $hold['hold_type'],
                'expires_at' => $hold['expires_at'],
                'timestamp' => $hold['created_at']
            ];
        }
    }
    
    if (!$responsePayload) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Transaction not found: ' . $trace,
            'timestamp' => time()
        ]);
        exit;
    }
    
    // Add verification info
    $responsePayload['requester'] = $requester;
    $responsePayload['signature_verified'] = $signatureVerified;
    $responsePayload['verification_method'] = $verificationMethod;
    $responsePayload['response_timestamp'] = time();
    
    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    if ($signatureVerified || $verificationMethod === 'certificate') {
        send_signed_response($responsePayload);
    } else {
        // If no verification provided, still return data but without signature
        echo json_encode($responsePayload);
    }
    
} catch (Exception $e) {
    error_log("ZURUBANK STATUS ERROR: " . $e->getMessage());
    error_log("ZURUBANK STATUS Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'status' => 'error', 
        'message' => 'Status query failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}

/**
 * Get public key for a requester (legacy fallback)
 */
function get_requester_public_key($requester, $pdo) {
    // First check environment variable
    $envKey = strtoupper($requester) . '_PUBLIC_KEY';
    $publicKey = getenv($envKey);
    if ($publicKey) {
        return str_replace(['\\n', '\n'], "\n", $publicKey);
    }
    
    // Then check database
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
    
    return null;
}
