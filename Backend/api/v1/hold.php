<?php
/**
 * backend/api/v1/hold.php
 * Unified hold endpoint for Zurubank
 * Handles VOUCHER, ACCOUNT, and WALLET holds
 * FIXED: Stores hold reference in source_hold_reference column
 * ADDED: Cryptographic signatures for bank-to-bank trust
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../helpers/crypto.php';  // Add signature functions

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    error_log("=== ZURUBANK hold.php received ===");
    error_log(json_encode($input));

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
    
    error_log("Action: $action, AssetType: $assetType, HoldRef: $holdReference");

    // Validate required fields
    if ($amount <= 0) {
        throw new Exception("Valid amount required");
    }

    // Start transaction
    $pdo->beginTransaction();

    $responsePayload = [];
    $assetId = null;

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
                    hold_expires_at = NOW() + INTERVAL '1 hour'
                WHERE voucher_id = :voucher_id
            ");
            $stmt->execute([
                'hold_reference' => $holdReference,
                'voucher_id' => $voucher['voucher_id']
            ]);
            
            $assetId = $voucher['voucher_id'];
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => true,
                'message' => 'Voucher is now on hold',
                'hold_reference' => $holdReference,
                'asset_type' => 'VOUCHER',
                'asset_id' => $voucher['voucher_id'],
                'voucher_number' => $voucherNumber,
                'amount' => floatval($voucher['amount']),
                'currency' => $voucher['currency'] ?? 'BWP'
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
                    hold_expires_at = NULL
                WHERE voucher_id = :voucher_id
                AND source_hold_reference = :hold_reference
            ");
            $stmt->execute([
                'voucher_id' => $voucher['voucher_id'],
                'hold_reference' => $holdReference
            ]);
            
            $assetId = $voucher['voucher_id'];
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => false,
                'message' => 'Voucher hold released',
                'hold_reference' => $holdReference,
                'asset_type' => 'VOUCHER',
                'asset_id' => $voucher['voucher_id'],
                'voucher_number' => $voucherNumber,
                'amount' => floatval($voucher['amount'])
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
                    hold_reference VARCHAR(50) UNIQUE NOT NULL,
                    status VARCHAR(30) DEFAULT 'HELD',
                    expires_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");

            // Check sufficient balance
            if ($account['balance'] < $amount) {
                throw new Exception("Insufficient funds");
            }

            // Create hold record
            $stmt = $pdo->prepare("
                INSERT INTO financial_holds 
                    (account_id, amount, hold_reference, status, expires_at)
                VALUES 
                    (?, ?, ?, 'HELD', NOW() + INTERVAL '24 hours')
                RETURNING id
            ");
            $stmt->execute([$account['account_id'], $amount, $holdReference]);
            $holdId = $stmt->fetchColumn();
            
            $assetId = $account['account_id'];
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => true,
                'message' => 'Hold placed on account',
                'hold_reference' => $holdReference,
                'asset_type' => 'ACCOUNT',
                'asset_id' => $account['account_id'],
                'account_number' => $accountNumber,
                'amount' => $amount,
                'currency' => $account['currency'] ?? 'BWP'
            ];
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD'])) {
            // Release hold
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED' 
                WHERE hold_reference = ? AND status = 'HELD'
                RETURNING account_id
            ");
            $stmt->execute([$holdReference]);
            $released = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $assetId = $released['account_id'] ?? null;
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => false,
                'message' => 'Hold released from account',
                'hold_reference' => $holdReference,
                'asset_type' => 'ACCOUNT',
                'asset_id' => $assetId,
                'account_number' => $accountNumber
            ];
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

    } elseif ($assetType === 'WALLET' || $assetType === 'E-WALLET' || $assetType === 'EWALLET') {
        if (!$phone) {
            throw new Exception("Phone number required for wallet hold");
        }

        // Find wallet via users table
        $stmt = $pdo->prepare("
            SELECT w.wallet_id, w.balance, w.status, w.currency, u.user_id
            FROM instant_money_wallets w
            JOIN users u ON w.user_id = u.user_id
            WHERE u.phone = :phone
            AND w.status = 'active'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['phone' => $phone]);
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
                    hold_reference VARCHAR(50) UNIQUE NOT NULL,
                    status VARCHAR(30) DEFAULT 'HELD',
                    expires_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW()
                )
            ");

            // Check sufficient balance
            if ($wallet['balance'] < $amount) {
                throw new Exception("Insufficient funds in wallet");
            }

            // Create hold record
            $stmt = $pdo->prepare("
                INSERT INTO financial_holds 
                    (wallet_id, amount, hold_reference, status, expires_at)
                VALUES 
                    (?, ?, ?, 'HELD', NOW() + INTERVAL '24 hours')
                RETURNING id
            ");
            $stmt->execute([$wallet['wallet_id'], $amount, $holdReference]);
            $holdId = $stmt->fetchColumn();
            
            $assetId = $wallet['wallet_id'];
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => true,
                'message' => 'Hold placed on wallet',
                'hold_reference' => $holdReference,
                'asset_type' => 'WALLET',
                'asset_id' => $wallet['wallet_id'],
                'phone' => $phone,
                'amount' => $amount,
                'currency' => $wallet['currency'] ?? 'BWP'
            ];
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD'])) {
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED' 
                WHERE hold_reference = ? AND status = 'HELD'
                RETURNING wallet_id
            ");
            $stmt->execute([$holdReference]);
            $released = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $assetId = $released['wallet_id'] ?? null;
            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => false,
                'message' => 'Hold released from wallet',
                'hold_reference' => $holdReference,
                'asset_type' => 'WALLET',
                'asset_id' => $assetId,
                'phone' => $phone
            ];
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

    } else {
        throw new Exception("Unsupported asset type: $assetType");
    }

    $pdo->commit();
    
    // ============================================================
    // SEND SIGNED RESPONSE USING CRYPTO.PHP
    // ============================================================
    
    // Add the asset_id to response payload if not already there
    if ($assetId && !isset($responsePayload['asset_id'])) {
        $responsePayload['asset_id'] = $assetId;
    }
    
    // Send signed response (adds signature and timestamp automatically)
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ZURUBANK hold.php error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'hold_placed' => false,
        'message' => $e->getMessage(),
        'timestamp' => time()
    ]);
}
