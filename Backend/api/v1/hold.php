<?php
/**
 * backend/api/v1/hold.php
 * Unified hold endpoint for Zurubank
 * Handles VOUCHER, ACCOUNT, and WALLET holds
 * UPDATED: Certificate-based verification (Visa/Mastercard model)
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/crypto.php';
require_once __DIR__ . '/../../helpers/CertificateManager.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    error_log("=== ZURUBANK hold.php received ===");
    error_log(json_encode($input));

    // ============================================================
    // CERTIFICATE-BASED VERIFICATION (REQUIRED)
    // ============================================================
    
    if (!isset($input['certificate'])) {
        error_log("ZURUBANK HOLD: No certificate provided");
        echo json_encode([
            'status' => 'ERROR',
            'hold_placed' => false,
            'message' => 'Certificate required - please upgrade to certificate-based authentication'
        ]);
        exit;
    }
    
    $certManager = new CertificateManager('ZURUBANK');
    $verification = $certManager->verifySignedRequest($input);
    $isValid = $verification['verified'];
    $requester = $verification['requester'];
    
    error_log("ZURUBANK HOLD: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
    error_log("ZURUBANK HOLD: Requester: {$requester}");
    
    if (!$isValid) {
        error_log("ZURUBANK HOLD: Certificate verification failed");
        echo json_encode([
            'status' => 'ERROR',
            'hold_placed' => false,
            'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
        ]);
        exit;
    }
    
    error_log("ZURUBANK HOLD: Request verified from {$requester} using certificate");

    // ============================================================
    // PROCESS HOLD
    // ============================================================

    // Determine action
    $action = strtoupper(trim($input['action'] ?? $input['type'] ?? 'PLACE'));
    
    // Get asset type
    $assetType = strtoupper($input['asset_type'] ?? $input['type'] ?? '');
    
    // Extract identifiers based on asset type
    $voucherNumber = $input['voucher_number'] ?? $input['voucher'] ?? null;
    $accountNumber = $input['account_number'] ?? $input['account'] ?? null;
    $phone = $input['phone'] ?? $input['wallet_phone'] ?? $input['ewallet_phone'] ?? null;
    
    $amount = floatval($input['amount'] ?? $input['value'] ?? 0);
    
    // IMPORTANT: Get the hold reference from the payload
    // SwapService sends it as 'reference' in the payload
    $holdReference = $input['reference'] ?? $input['hold_reference'] ?? null;
    
    if (!$holdReference) {
        throw new Exception("Hold reference is required");
    }
    
    // Validate based on asset type
    if (empty($assetType)) {
        // Try to infer from provided identifiers
        if ($voucherNumber) $assetType = 'VOUCHER';
        elseif ($accountNumber) $assetType = 'ACCOUNT';
        elseif ($phone) $assetType = 'WALLET';
        else throw new Exception("Could not determine asset type");
    }
    
    error_log("ZURUBANK HOLD: Action: $action, AssetType: $assetType, HoldRef: $holdReference, Amount: $amount");

    // Validate required fields
    if ($amount <= 0) {
        throw new Exception("Valid amount required");
    }

    // Start transaction
    $pdo->beginTransaction();

    $responsePayload = [];
    $assetId = null;
    $holdPlaced = false;

    // Process based on asset type and action
    if ($assetType === 'VOUCHER') {
        if (!$voucherNumber) {
            throw new Exception("Voucher number required");
        }

        // Lock the voucher row for update
        $stmt = $pdo->prepare("
            SELECT voucher_id, amount, status, recipient_phone, currency
            FROM instant_money_vouchers
            WHERE voucher_number = :voucher_number
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['voucher_number' => $voucherNumber]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$voucher) {
            throw new Exception("Voucher not found");
        }

        if (in_array($action, ['HOLD', 'PLACE', 'PLACE_HOLD'])) {
            if ($voucher['status'] === 'hold') {
                throw new Exception("Voucher is already on hold");
            }
            if ($voucher['status'] !== 'active') {
                throw new Exception("Voucher cannot be held (status: {$voucher['status']})");
            }
            
            // Store the hold_reference in source_hold_reference column
            $stmt = $pdo->prepare("
                UPDATE instant_money_vouchers
                SET status = 'hold',
                    source_hold_reference = :hold_reference,
                    hold_expires_at = NOW() + INTERVAL '1 hour',
                    held_by = :requester,
                    hold_signature_verified = :sig_verified,
                    updated_at = NOW()
                WHERE voucher_id = :voucher_id
            ");
            $stmt->execute([
                'hold_reference' => $holdReference,
                'voucher_id' => $voucher['voucher_id'],
                'requester' => $requester,
                'sig_verified' => $isValid ? 1 : 0
            ]);
            
            $assetId = $voucher['voucher_id'];
            $holdPlaced = true;
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => true,
                'message' => 'Voucher is now on hold',
                'hold_reference' => $holdReference,
                'asset_type' => 'VOUCHER',
                'asset_id' => $voucher['voucher_id'],
                'voucher_number' => $voucherNumber,
                'amount' => floatval($voucher['amount']),
                'currency' => $voucher['currency'] ?? 'BWP',
                'requester' => $requester,
                'signature_verified' => $isValid
            ];
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD', 'UNHOLD'])) {
            if ($voucher['status'] !== 'hold') {
                throw new Exception("Voucher is not currently on hold");
            }
            
            // Clear the hold reference when releasing
            $stmt = $pdo->prepare("
                UPDATE instant_money_vouchers
                SET status = 'active',
                    source_hold_reference = NULL,
                    hold_expires_at = NULL,
                    released_by = :requester,
                    released_at = NOW(),
                    updated_at = NOW()
                WHERE voucher_id = :voucher_id
                AND source_hold_reference = :hold_reference
            ");
            $stmt->execute([
                'voucher_id' => $voucher['voucher_id'],
                'hold_reference' => $holdReference,
                'requester' => $requester
            ]);
            
            $assetId = $voucher['voucher_id'];
            $holdPlaced = false;
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => false,
                'message' => 'Voucher hold released',
                'hold_reference' => $holdReference,
                'asset_type' => 'VOUCHER',
                'asset_id' => $voucher['voucher_id'],
                'voucher_number' => $voucherNumber,
                'amount' => floatval($voucher['amount']),
                'requester' => $requester,
                'signature_verified' => $isValid
            ];
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

    } elseif ($assetType === 'ACCOUNT') {
        if (!$accountNumber) {
            throw new Exception("Account number required");
        }

        // Lock the account row
        $stmt = $pdo->prepare("
            SELECT account_id, balance, status, currency
            FROM accounts
            WHERE account_number = :account_number
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['account_number' => $accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception("Account not found");
        }

        if ($account['status'] !== 'active') {
            throw new Exception("Account is not active");
        }

        if (in_array($action, ['HOLD', 'PLACE', 'PLACE_HOLD'])) {
            // Create financial_holds table if not exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS financial_holds (
                    id BIGSERIAL PRIMARY KEY,
                    account_id BIGINT,
                    wallet_id BIGINT,
                    amount DECIMAL(20,4) NOT NULL,
                    hold_reference VARCHAR(100) UNIQUE NOT NULL,
                    status VARCHAR(30) DEFAULT 'HELD',
                    requester VARCHAR(100),
                    signature_verified BOOLEAN DEFAULT FALSE,
                    expires_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW()
                )
            ");

            // Check sufficient balance
            if ($account['balance'] < $amount) {
                throw new Exception("Insufficient funds");
            }

            // Create hold record
            $stmt = $pdo->prepare("
                INSERT INTO financial_holds 
                    (account_id, amount, hold_reference, status, requester, signature_verified, expires_at, created_at, updated_at)
                VALUES 
                    (?, ?, ?, 'HELD', ?, ?, NOW() + INTERVAL '24 hours', NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([$account['account_id'], $amount, $holdReference, $requester, $isValid ? 1 : 0]);
            $holdId = $stmt->fetchColumn();
            
            $assetId = $account['account_id'];
            $holdPlaced = true;
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => true,
                'message' => 'Hold placed on account',
                'hold_reference' => $holdReference,
                'asset_type' => 'ACCOUNT',
                'asset_id' => $account['account_id'],
                'account_number' => $accountNumber,
                'amount' => $amount,
                'currency' => $account['currency'] ?? 'BWP',
                'requester' => $requester,
                'signature_verified' => $isValid
            ];
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD'])) {
            // Release hold
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED', 
                    released_by = :requester,
                    released_at = NOW(),
                    updated_at = NOW()
                WHERE hold_reference = ? AND status = 'HELD'
                RETURNING account_id
            ");
            $stmt->execute(['hold_reference' => $holdReference, 'requester' => $requester]);
            $released = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $assetId = $released['account_id'] ?? null;
            $holdPlaced = false;
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => false,
                'message' => 'Hold released from account',
                'hold_reference' => $holdReference,
                'asset_type' => 'ACCOUNT',
                'asset_id' => $assetId,
                'account_number' => $accountNumber,
                'requester' => $requester,
                'signature_verified' => $isValid
            ];
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

    } elseif ($assetType === 'WALLET' || $assetType === 'E-WALLET' || $assetType === 'EWALLET') {
        if (!$phone) {
            throw new Exception("Phone number required for wallet hold");
        }

        // Normalize phone
        $normalizedPhone = ltrim($phone, '+');

        // Find wallet via users table
        $stmt = $pdo->prepare("
            SELECT w.wallet_id, w.balance, w.status, w.currency, u.user_id
            FROM instant_money_wallets w
            JOIN users u ON w.user_id = u.user_id
            WHERE u.phone = :phone OR u.phone = :phone_with_plus
            AND w.status = 'active'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            'phone' => $normalizedPhone,
            'phone_with_plus' => $phone
        ]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$wallet) {
            throw new Exception("Wallet not found for phone: $phone");
        }

        if (in_array($action, ['HOLD', 'PLACE', 'PLACE_HOLD'])) {
            // Create financial_holds table if not exists
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS financial_holds (
                    id BIGSERIAL PRIMARY KEY,
                    account_id BIGINT,
                    wallet_id BIGINT,
                    amount DECIMAL(20,4) NOT NULL,
                    hold_reference VARCHAR(100) UNIQUE NOT NULL,
                    status VARCHAR(30) DEFAULT 'HELD',
                    requester VARCHAR(100),
                    signature_verified BOOLEAN DEFAULT FALSE,
                    expires_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW()
                )
            ");

            // Check sufficient balance
            if ($wallet['balance'] < $amount) {
                throw new Exception("Insufficient funds in wallet");
            }

            // Create hold record
            $stmt = $pdo->prepare("
                INSERT INTO financial_holds 
                    (wallet_id, amount, hold_reference, status, requester, signature_verified, expires_at, created_at, updated_at)
                VALUES 
                    (?, ?, ?, 'HELD', ?, ?, NOW() + INTERVAL '24 hours', NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([$wallet['wallet_id'], $amount, $holdReference, $requester, $isValid ? 1 : 0]);
            $holdId = $stmt->fetchColumn();
            
            $assetId = $wallet['wallet_id'];
            $holdPlaced = true;
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => true,
                'message' => 'Hold placed on wallet',
                'hold_reference' => $holdReference,
                'asset_type' => 'WALLET',
                'asset_id' => $wallet['wallet_id'],
                'phone' => $phone,
                'amount' => $amount,
                'currency' => $wallet['currency'] ?? 'BWP',
                'requester' => $requester,
                'signature_verified' => $isValid
            ];
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD'])) {
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED', 
                    released_by = :requester,
                    released_at = NOW(),
                    updated_at = NOW()
                WHERE hold_reference = ? AND status = 'HELD'
                RETURNING wallet_id
            ");
            $stmt->execute(['hold_reference' => $holdReference, 'requester' => $requester]);
            $released = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $assetId = $released['wallet_id'] ?? null;
            $holdPlaced = false;
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => false,
                'message' => 'Hold released from wallet',
                'hold_reference' => $holdReference,
                'asset_type' => 'WALLET',
                'asset_id' => $assetId,
                'phone' => $phone,
                'requester' => $requester,
                'signature_verified' => $isValid
            ];
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

    } else {
        throw new Exception("Unsupported asset type: $assetType");
    }

    $pdo->commit();
    
    error_log("ZURUBANK HOLD: Hold processed successfully - Ref: {$holdReference}, AssetType: {$assetType}");
    
    // ============================================================
    // SEND SIGNED RESPONSE WITH CERTIFICATE
    // ============================================================
    
    // Add missing fields if needed
    if ($assetId && !isset($responsePayload['asset_id'])) {
        $responsePayload['asset_id'] = $assetId;
    }
    
    $responsePayload['verification_method'] = 'certificate';
    $responsePayload['timestamp'] = time();
    
    // Send signed response (adds signature and timestamp automatically)
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ZURUBANK hold.php ERROR: " . $e->getMessage());
    error_log("ZURUBANK hold.php Trace: " . $e->getTraceAsString());
    error_log("ZURUBANK hold.php Input: " . json_encode($input ?? []));
    
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'hold_placed' => false,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
