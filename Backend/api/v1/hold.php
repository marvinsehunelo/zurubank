<?php
/**
 * backend/api/v1/hold.php
 * Unified hold endpoint for Zurubank
 * Handles VOUCHER, ACCOUNT, and WALLET holds
 * UPDATED: Certificate-based verification
 * UPDATED: Same voucher number detection as verify_asset.php
 * FIXED: Only uses columns that exist in instant_money_vouchers table
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
        http_response_code(200);
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
        http_response_code(200);
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

    $action = strtoupper(trim($input['action'] ?? $input['type'] ?? 'PLACE'));
    $assetType = strtoupper($input['asset_type'] ?? $input['type'] ?? '');
    
    // ============================================================
    // Extract voucher_number from MULTIPLE locations
    // ============================================================
    $voucherNumber = $input['voucher_number'] ?? 
                     $input['voucher'] ?? 
                     $input['voucherNumber'] ?? 
                     $input['voucher_no'] ?? 
                     $input['voucherId'] ?? 
                     $input['source']['voucher']['voucher_number'] ?? 
                     $input['source']['voucher_number'] ??
                     $input['source']['voucherNumber'] ??
                     $input['source']['voucher'] ??
                     $input['certificate_data']['voucher_number'] ??
                     $input['certificate_data']['voucher'] ??
                     null;
    
    $accountNumber = $input['account_number'] ?? $input['account'] ?? $input['source_identifier'] ?? null;
    $phone = $input['phone'] ?? $input['wallet_phone'] ?? $input['ewallet_phone'] ?? null;
    
    // ============================================================
    // If asset_type is VOUCHER but voucher_number is missing,
    // check if source_identifier is actually the voucher number
    // ============================================================
    if (($assetType === 'VOUCHER' || $assetType === 'CASHOUT-VOUCHER') && empty($voucherNumber)) {
        if (!empty($accountNumber) && preg_match('/^\d{12,15}$/', $accountNumber)) {
            $voucherNumber = $accountNumber;
            error_log("Using source_identifier as voucher_number: $voucherNumber");
        }
    }
    
    $amount = floatval($input['amount'] ?? $input['value'] ?? 0);
    $holdReference = $input['reference'] ?? $input['hold_reference'] ?? null;
    
    if (!$holdReference) {
        throw new Exception("Hold reference is required");
    }
    
    if (empty($assetType)) {
        if ($voucherNumber) $assetType = 'VOUCHER';
        elseif ($accountNumber) $assetType = 'ACCOUNT';
        elseif ($phone) $assetType = 'WALLET';
        else throw new Exception("Could not determine asset type");
    }
    
    error_log("ZURUBANK HOLD: Action: $action, AssetType: $assetType, HoldRef: $holdReference, Amount: $amount");

    if ($amount <= 0) {
        throw new Exception("Valid amount required");
    }

    $pdo->beginTransaction();

    $responsePayload = [];
    $assetId = null;
    $holdPlaced = false;

    // ============================================================
    // VOUCHER HANDLING - FIXED: Only uses columns that exist
    // ============================================================
    if ($assetType === 'VOUCHER') {
        if (!$voucherNumber) {
            error_log("Voucher number missing. Available fields: " . implode(', ', array_keys($input)));
            throw new Exception("Voucher number required. Available fields: " . implode(', ', array_keys($input)));
        }

        $stmt = $pdo->prepare("
            SELECT voucher_id, amount, status, recipient_phone, currency, voucher_pin, voucher_expires_at
            FROM instant_money_vouchers
            WHERE voucher_number = :voucher_number
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['voucher_number' => $voucherNumber]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$voucher) {
            $stmt = $pdo->prepare("
                SELECT * FROM instant_money_vouchers
                WHERE TRIM(voucher_number) = TRIM(:voucher_number)
                LIMIT 1
            ");
            $stmt->execute(['voucher_number' => $voucherNumber]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$voucher) {
            throw new Exception("Voucher not found: $voucherNumber");
        }

        if (in_array($action, ['HOLD', 'PLACE', 'PLACE_HOLD'])) {
            if ($voucher['status'] === 'hold') {
                throw new Exception("Voucher is already on hold");
            }
            if ($voucher['status'] !== 'active') {
                throw new Exception("Voucher cannot be held (status: {$voucher['status']})");
            }
            
            // ✅ FIX: Only use columns that exist
            $stmt = $pdo->prepare("
                UPDATE instant_money_vouchers
                SET status = 'hold',
                    source_hold_reference = :hold_reference,
                    reference = :reference,
                    updated_at = NOW()
                WHERE voucher_id = :voucher_id
            ");
            $stmt->execute([
                'hold_reference' => $holdReference,
                'reference' => $holdReference,
                'voucher_id' => $voucher['voucher_id']
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
                'recipient_phone' => $voucher['recipient_phone']
            ];
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD', 'UNHOLD'])) {
            if ($voucher['status'] !== 'hold') {
                throw new Exception("Voucher is not currently on hold");
            }
            
            // ✅ FIX: Only use columns that exist
            $stmt = $pdo->prepare("
                UPDATE instant_money_vouchers
                SET status = 'active',
                    source_hold_reference = NULL,
                    reference = NULL,
                    updated_at = NOW()
                WHERE voucher_id = :voucher_id
                AND source_hold_reference = :hold_reference
            ");
            $stmt->execute([
                'voucher_id' => $voucher['voucher_id'],
                'hold_reference' => $holdReference
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
                'amount' => floatval($voucher['amount'])
            ];
            
        } else {
            throw new Exception("Unsupported action for voucher: $action");
        }

    // ============================================================
    // ACCOUNT HANDLING
    // ============================================================
    } elseif ($assetType === 'ACCOUNT') {
        // ... (ACCOUNT handling code remains the same as before) ...
        if (!$accountNumber) {
            throw new Exception("Account number required");
        }

        $stmt = $pdo->prepare("
            SELECT 
                account_id, account_number, balance, available_balance, held_amount,
                status, currency, user_id
            FROM accounts
            WHERE account_number = :account_number
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute(['account_number' => $accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception("Account not found: $accountNumber");
        }

        if ($account['status'] !== 'active') {
            throw new Exception("Account is not active (status: {$account['status']})");
        }

        if (in_array($action, ['HOLD', 'PLACE', 'PLACE_HOLD'])) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS financial_holds (
                    id BIGSERIAL PRIMARY KEY,
                    account_id BIGINT,
                    amount DECIMAL(20,4) NOT NULL,
                    hold_reference VARCHAR(100) UNIQUE NOT NULL,
                    status VARCHAR(30) DEFAULT 'HELD',
                    requester VARCHAR(100),
                    signature_verified BOOLEAN DEFAULT FALSE,
                    expires_at TIMESTAMP,
                    debited_at TIMESTAMP,
                    released_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW()
                )
            ");

            $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS available_balance DECIMAL(20,4) DEFAULT 0");
            $pdo->exec("ALTER TABLE accounts ADD COLUMN IF NOT EXISTS held_amount DECIMAL(20,4) DEFAULT 0");

            $availableBalance = floatval($account['available_balance'] ?? $account['balance']);
            
            if ($availableBalance < $amount) {
                throw new Exception(
                    "Insufficient available balance. "
                    . "Available: $availableBalance, Requested: $amount"
                );
            }

            $stmt = $pdo->prepare("
                INSERT INTO financial_holds 
                    (account_id, amount, hold_reference, status, requester, signature_verified, expires_at)
                VALUES (?, ?, ?, 'HELD', ?, ?, NOW() + INTERVAL '24 hours')
                RETURNING id
            ");
            $stmt->execute([$account['account_id'], $amount, $holdReference, $requester, $isValid ? 1 : 0]);
            $holdId = $stmt->fetchColumn();

            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET available_balance = available_balance - :amount,
                    held_amount = held_amount + :amount,
                    updated_at = NOW()
                WHERE account_id = :account_id 
                AND available_balance >= :amount
                RETURNING balance, available_balance, held_amount
            ");
            $stmt->execute([
                'amount' => $amount,
                'account_id' => $account['account_id']
            ]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$updated) {
                throw new Exception("Failed to reserve funds");
            }
            
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
                'total_balance' => floatval($updated['balance']),
                'available_balance' => floatval($updated['available_balance']),
                'held_amount' => floatval($updated['held_amount']),
                'hold_id' => $holdId
            ];
            
        } elseif ($action === 'DEBIT' || $action === 'DEBIT_HOLD' || $action === 'DEBIT_FUNDS') {
            // ... (DEBIT handling remains the same) ...
            $stmt = $pdo->prepare("
                SELECT id, account_id, amount, status
                FROM financial_holds
                WHERE hold_reference = :hold_reference 
                AND status = 'HELD'
                AND account_id = :account_id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([
                'hold_reference' => $holdReference,
                'account_id' => $account['account_id']
            ]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                throw new Exception("No active hold found");
            }

            if (floatval($hold['amount']) !== $amount) {
                throw new Exception("Hold amount mismatch");
            }

            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET balance = balance - :amount,
                    held_amount = held_amount - :amount,
                    updated_at = NOW()
                WHERE account_id = :account_id 
                AND balance >= :amount AND held_amount >= :amount
                RETURNING balance, available_balance, held_amount
            ");
            $stmt->execute([
                'amount' => $amount,
                'account_id' => $account['account_id']
            ]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$updated) {
                throw new Exception("Insufficient balance for debit");
            }

            $stmt = $pdo->prepare("
                UPDATE financial_holds SET status = 'DEBITED', debited_at = NOW(), updated_at = NOW()
                WHERE id = :hold_id
            ");
            $stmt->execute(['hold_id' => $hold['id']]);

            $responsePayload = [
                'status' => 'SUCCESS',
                'debited' => true,
                'hold_reference' => $holdReference,
                'asset_type' => 'ACCOUNT',
                'asset_id' => $account['account_id'],
                'amount' => $amount,
                'total_balance' => floatval($updated['balance']),
                'available_balance' => floatval($updated['available_balance']),
                'held_amount' => floatval($updated['held_amount'])
            ];
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD', 'UNHOLD'])) {
            // ... (RELEASE handling remains the same) ...
            $stmt = $pdo->prepare("
                SELECT id, account_id, amount, status
                FROM financial_holds
                WHERE hold_reference = :hold_reference 
                AND status = 'HELD'
                AND account_id = :account_id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([
                'hold_reference' => $holdReference,
                'account_id' => $account['account_id']
            ]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                throw new Exception("No active hold found");
            }

            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET available_balance = available_balance + :amount,
                    held_amount = held_amount - :amount,
                    updated_at = NOW()
                WHERE account_id = :account_id 
                AND held_amount >= :amount
                RETURNING balance, available_balance, held_amount
            ");
            $stmt->execute([
                'amount' => $hold['amount'],
                'account_id' => $account['account_id']
            ]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$updated) {
                throw new Exception("Failed to release hold");
            }

            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED', released_by = :requester, released_at = NOW(), updated_at = NOW()
                WHERE id = :hold_id
            ");
            $stmt->execute([
                'hold_id' => $hold['id'],
                'requester' => $requester
            ]);

            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => false,
                'message' => 'Hold released',
                'hold_reference' => $holdReference,
                'asset_type' => 'ACCOUNT',
                'asset_id' => $account['account_id'],
                'amount' => floatval($hold['amount']),
                'total_balance' => floatval($updated['balance']),
                'available_balance' => floatval($updated['available_balance']),
                'held_amount' => floatval($updated['held_amount'])
            ];
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

    // ============================================================
    // WALLET HANDLING
    // ============================================================
    } elseif ($assetType === 'WALLET' || $assetType === 'E-WALLET' || $assetType === 'EWALLET') {
        // ... (WALLET handling code remains the same as before) ...
        if (!$phone) {
            throw new Exception("Phone number required for wallet hold");
        }

        $normalizedPhone = ltrim($phone, '+');

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
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS financial_holds (
                    id BIGSERIAL PRIMARY KEY,
                    wallet_id BIGINT,
                    amount DECIMAL(20,4) NOT NULL,
                    hold_reference VARCHAR(100) UNIQUE NOT NULL,
                    status VARCHAR(30) DEFAULT 'HELD',
                    requester VARCHAR(100),
                    signature_verified BOOLEAN DEFAULT FALSE,
                    expires_at TIMESTAMP,
                    debited_at TIMESTAMP,
                    released_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW()
                )
            });

            if ($wallet['balance'] < $amount) {
                throw new Exception("Insufficient funds in wallet");
            }

            $stmt = $pdo->prepare("
                INSERT INTO financial_holds 
                    (wallet_id, amount, hold_reference, status, requester, signature_verified, expires_at)
                VALUES (?, ?, ?, 'HELD', ?, ?, NOW() + INTERVAL '24 hours')
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
                'hold_id' => $holdId
            ];
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD', 'UNHOLD'])) {
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = 'RELEASED', 
                    released_by = :requester,
                    released_at = NOW(),
                    updated_at = NOW()
                WHERE hold_reference = :hold_reference 
                AND status = 'HELD'
                AND wallet_id = :wallet_id
                RETURNING id, wallet_id
            ");
            $stmt->execute([
                'hold_reference' => $holdReference,
                'wallet_id' => $wallet['wallet_id'],
                'requester' => $requester
            ]);
            $released = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$released) {
                throw new Exception("No active hold found");
            }
            
            $assetId = $released['wallet_id'] ?? null;
            $holdPlaced = false;
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
    
    error_log("ZURUBANK HOLD: Hold processed successfully - Ref: {$holdReference}, AssetType: {$assetType}");
    
    $responsePayload['verification_method'] = 'certificate';
    $responsePayload['timestamp'] = time();
    $responsePayload['requester'] = $requester;
    $responsePayload['signature_verified'] = $isValid;
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ZURUBANK hold.php ERROR: " . $e->getMessage());
    error_log("ZURUBANK hold.php Trace: " . $e->getTraceAsString());
    
    http_response_code(200);
    echo json_encode([
        'status' => 'ERROR',
        'hold_placed' => false,
        'message' => $e->getMessage(),
        'timestamp' => time(),
        'debug' => [
            'asset_type' => $assetType ?? 'unknown',
            'voucher_number' => $voucherNumber ?? null,
            'account_number' => $accountNumber ?? null,
            'phone' => $phone ?? null,
            'hold_reference' => $holdReference ?? null
        ]
    ]);
}
