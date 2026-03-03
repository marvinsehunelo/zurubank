<?php
/**
 * backend/api/v1/hold.php
 * Unified hold endpoint for Zurubank
 * Handles VOUCHER, ACCOUNT, and WALLET holds
 * FIXED: Stores hold reference in source_hold_reference column
 */

require_once __DIR__ . '/../../config/db.php';

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

    // Process based on asset type and action
    if ($assetType === 'VOUCHER') {
        if (!$voucherNumber) {
            throw new Exception("Voucher number required");
        }

        // Lock the voucher row for update
        $stmt = $pdo->prepare("
            SELECT voucher_id, amount, status, recipient_phone
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
            
            // FIXED: Store the hold_reference in source_hold_reference column
            $stmt = $pdo->prepare("
                UPDATE instant_money_vouchers
                SET status = 'hold',
                    source_hold_reference = :hold_reference
                WHERE voucher_id = :voucher_id
            ");
            $stmt->execute([
                'hold_reference' => $holdReference,
                'voucher_id' => $voucher['voucher_id']
            ]);
            $message = "Voucher is now on hold";
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD', 'UNHOLD'])) {
            if ($voucher['status'] !== 'hold') {
                throw new Exception("Voucher is not currently on hold");
            }
            
            // Clear the hold reference when releasing
            $stmt = $pdo->prepare("
                UPDATE instant_money_vouchers
                SET status = 'active',
                    source_hold_reference = NULL
                WHERE voucher_id = :voucher_id
                AND source_hold_reference = :hold_reference
            ");
            $stmt->execute([
                'voucher_id' => $voucher['voucher_id'],
                'hold_reference' => $holdReference
            ]);
            $message = "Voucher hold released";
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

        $pdo->commit();
        
        echo json_encode([
            'status' => 'SUCCESS',
            'hold_placed' => ($action !== 'RELEASE'),
            'message' => $message,
            'hold_reference' => $holdReference,
            'asset_type' => 'VOUCHER',
            'asset_id' => $voucher['voucher_id'],
            'voucher_number' => $voucherNumber,
            'amount' => $voucher['amount']
        ]);

    } elseif ($assetType === 'ACCOUNT') {
        if (!$accountNumber) {
            throw new Exception("Account number required");
        }

        // Lock the account row
        $stmt = $pdo->prepare("
            SELECT account_id, balance, status
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
            // Check if we have a financial_holds table, if not create it
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS financial_holds (
                        id BIGSERIAL PRIMARY KEY,
                        account_id BIGINT NOT NULL,
                        amount DECIMAL(20,4) NOT NULL,
                        hold_reference VARCHAR(50) UNIQUE NOT NULL,
                        status VARCHAR(30) DEFAULT 'HELD',
                        expires_at TIMESTAMP,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ");
            } catch (Exception $e) {
                // Table might already exist
            }

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
            
            $message = "Hold placed on account";
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD'])) {
            // Release hold logic would go here
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED' 
                WHERE hold_reference = ? AND status = 'HELD'
            ");
            $stmt->execute([$holdReference]);
            
            $message = "Hold released from account";
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

        $pdo->commit();
        
        echo json_encode([
            'status' => 'SUCCESS',
            'hold_placed' => ($action !== 'RELEASE'),
            'message' => $message,
            'hold_reference' => $holdReference,
            'asset_type' => 'ACCOUNT',
            'asset_id' => $account['account_id'],
            'account_number' => $accountNumber,
            'amount' => $amount
        ]);

    } elseif ($assetType === 'WALLET' || $assetType === 'E-WALLET' || $assetType === 'EWALLET') {
        if (!$phone) {
            throw new Exception("Phone number required for wallet hold");
        }

        // Find wallet via users table
        $stmt = $pdo->prepare("
            SELECT w.wallet_id, w.balance, w.status, u.user_id
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
            try {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS financial_holds (
                        id BIGSERIAL PRIMARY KEY,
                        wallet_id BIGINT NOT NULL,
                        amount DECIMAL(20,4) NOT NULL,
                        hold_reference VARCHAR(50) UNIQUE NOT NULL,
                        status VARCHAR(30) DEFAULT 'HELD',
                        expires_at TIMESTAMP,
                        created_at TIMESTAMP DEFAULT NOW()
                    )
                ");
            } catch (Exception $e) {
                // Table might already exist
            }

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
            
            $message = "Hold placed on wallet";
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD'])) {
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED' 
                WHERE hold_reference = ? AND status = 'HELD'
            ");
            $stmt->execute([$holdReference]);
            
            $message = "Hold released from wallet";
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

        $pdo->commit();
        
        echo json_encode([
            'status' => 'SUCCESS',
            'hold_placed' => ($action !== 'RELEASE'),
            'message' => $message,
            'hold_reference' => $holdReference,
            'asset_type' => 'WALLET',
            'asset_id' => $wallet['wallet_id'],
            'phone' => $phone,
            'amount' => $amount
        ]);

    } else {
        throw new Exception("Unsupported asset type: $assetType");
    }

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ZURUBANK hold.php error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'status' => 'ERROR',
        'hold_placed' => false,
        'message' => $e->getMessage()
    ]);
}
