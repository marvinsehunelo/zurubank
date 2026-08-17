<?php
/**
 * backend/api/v1/hold.php
 * Unified hold endpoint for Zurubank
 * Handles VOUCHER, ACCOUNT, WALLET, and CARD holds
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

    // Determine action
    $action = strtoupper(trim($input['action'] ?? $input['type'] ?? 'PLACE'));
    
    // Get asset type
    $assetType = strtoupper($input['asset_type'] ?? $input['type'] ?? '');
    
    // ============================================================
    // SMART VOUCHER NUMBER EXTRACTION (MIRRORS verify_asset_zurubank.php)
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
    
    // Get other identifiers
    $accountNumber = $input['account_number'] ?? $input['account'] ?? $input['source_identifier'] ?? null;
    $phone = $input['phone'] ?? $input['wallet_phone'] ?? $input['ewallet_phone'] ?? null;
    
    // ============================================================
    // SMART FALLBACK: If asset_type is VOUCHER but voucher_number is missing,
    // check if source_identifier is actually the voucher number
    // Mirrors verify_asset_zurubank.php exactly - NO length restriction.
    // (Previously required a 12-15 digit match here, which rejected
    // shorter valid identifiers like "535400455" that verify_asset.php
    // accepted without issue - causing verify to succeed and hold to
    // fail on the very same identifier.)
    // ============================================================
    if (($assetType === 'VOUCHER' || $assetType === 'CASHOUT-VOUCHER') && empty($voucherNumber)) {
        if (!empty($accountNumber)) {
            $voucherNumber = trim($accountNumber);
            error_log("ZURUBANK HOLD: Using source_identifier as voucher_number: $voucherNumber");
        }
    }
    
    // ============================================================
    // ADDITIONAL FALLBACK: Check reference for voucher number
    // ============================================================
    if (empty($voucherNumber) && !empty($input['reference'])) {
        if (preg_match('/HOLD_(\d+)_/', $input['reference'], $matches)) {
            $voucherNumber = $matches[1];
            error_log("ZURUBANK HOLD: Extracted voucher_number from reference: $voucherNumber");
        }
    }
    
    // ============================================================
    // FINAL FALLBACK: Check any field that looks like a number
    // Loosened to match any numeric string (mirrors verify_asset_zurubank.php approach)
    // ============================================================
    if (empty($voucherNumber)) {
        foreach ($input as $key => $value) {
            if (is_string($value) && preg_match('/^\d+$/', $value) && strlen($value) >= 6) {
                $voucherNumber = trim($value);
                error_log("ZURUBANK HOLD: Auto-detected voucher_number from field '$key': $voucherNumber");
                break;
            }
        }
    }
    
    // ============================================================
    // EXTRACT PIN FROM MULTIPLE SOURCES (mirrors verify_asset_zurubank.php)
    // ============================================================
    $voucherPin = $input['voucher_pin'] ?? 
                  $input['voucherPIN'] ?? 
                  $input['voucherPin'] ?? 
                  $input['pin'] ?? 
                  $input['pin_code'] ?? 
                  $input['voucher']['pin'] ?? 
                  $input['source']['pin'] ?? 
                  $input['source']['voucher_pin'] ?? 
                  $input['asset_fields']['pin'] ??
                  $input['asset_fields']['voucher_pin'] ??
                  null;
    
    // ============================================================
    // AUTO-DETECT ASSET TYPE (mirrors verify_asset_zurubank.php)
    // ============================================================
    if (empty($assetType)) {
        if ($voucherNumber) {
            $assetType = 'VOUCHER';
            error_log("ZURUBANK HOLD: Auto-detected asset type: VOUCHER from voucher_number");
        } elseif ($accountNumber) {
            $assetType = 'ACCOUNT';
            error_log("ZURUBANK HOLD: Auto-detected asset type: ACCOUNT from account_number");
        } elseif ($phone) {
            $assetType = 'WALLET';
            error_log("ZURUBANK HOLD: Auto-detected asset type: WALLET from phone");
        } else {
            throw new Exception("Could not determine asset type");
        }
    }

    // ============================================================
    // CARD RESOLUTION — mirrors verify_asset.php's approach exactly.
    // financial_holds has NO asset_type or card_id column at all —
    // holds are distinguished purely by which of account_id/
    // wallet_id is populated (see the CREATE TABLE below in the
    // ACCOUNT branch). A card resolves to its linked account and is
    // then handled by the existing ACCOUNT branch — for PLACE_HOLD,
    // RELEASE, and DEBIT alike — with zero further changes needed.
    // This also means notify_debit.php needs NO changes at all: it
    // finds holds purely by hold_reference + status='HELD', with no
    // asset_type awareness whatsoever.
    // ============================================================
    if ($assetType === 'CARD') {
        $cardNumber = $accountNumber ?? $input['card_number'] ?? null;

        if (empty($cardNumber)) {
            throw new Exception("card_number (or source_identifier) required for CARD hold");
        }

        $cardStmt = $pdo->prepare("
            SELECT c.card_id, c.status AS card_status, a.account_number
            FROM cards c
            JOIN accounts a ON a.account_id = c.account_id
            WHERE c.card_number = :card_number
            LIMIT 1
        ");
        $cardStmt->execute(['card_number' => $cardNumber]);
        $cardRow = $cardStmt->fetch(PDO::FETCH_ASSOC);

        if (!$cardRow) {
            throw new Exception("Card not found: " . substr($cardNumber, -4));
        }
        if ($cardRow['card_status'] !== 'ACTIVE') {
            throw new Exception("Card is not active (status: {$cardRow['card_status']})");
        }

        error_log("ZURUBANK HOLD: CARD resolved to account_number={$cardRow['account_number']}, card_id={$cardRow['card_id']}");
        $accountNumber = $cardRow['account_number'];
        $assetType = 'ACCOUNT';
    }
    
    $amount = floatval($input['amount'] ?? $input['value'] ?? 0);
    $holdReference = $input['reference'] ?? $input['hold_reference'] ?? null;
    
    if (!$holdReference) {
        throw new Exception("Hold reference is required");
    }
    
    error_log("ZURUBANK HOLD: Action: $action, AssetType: $assetType, HoldRef: $holdReference, Amount: $amount, VoucherNum: $voucherNumber");

    // RELEASE_HOLD releases by hold_reference lookup - it doesn't need a
    // fresh amount supplied by the caller (there's nothing to supply; the
    // amount being released is whatever was already held). Requiring a
    // positive amount here made every release fail when the caller
    // correctly omitted it, leaving holds stuck ACTIVE - see VouchMorph's
    // rollback path, which never sends 'amount' on a release for exactly
    // this reason.
    if ($amount <= 0 && $action !== 'RELEASE_HOLD') {
        throw new Exception("Valid amount required");
    }

    // Start transaction
    $pdo->beginTransaction();

    $responsePayload = [];
    $assetId = null;
    $holdPlaced = false;

    // ============================================================
    // VOUCHER HANDLING
    // ============================================================
    if ($assetType === 'VOUCHER' || $assetType === 'CASHOUT-VOUCHER') {
        if (!$voucherNumber) {
            error_log("ZURUBANK HOLD: Voucher number missing. Available fields: " . implode(', ', array_keys($input)));
            throw new Exception("Voucher number required. Available fields: " . implode(', ', array_keys($input)));
        }

        $stmt = $pdo->prepare("
            SELECT voucher_id, amount, status, recipient_phone, currency, voucher_pin
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
            $stmt = $pdo->prepare("
                SELECT * FROM instant_money_vouchers
                WHERE LOWER(TRIM(voucher_number)) = LOWER(TRIM(:voucher_number))
                LIMIT 1
            ");
            $stmt->execute(['voucher_number' => $voucherNumber]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$voucher) {
            error_log("ZURUBANK HOLD: Voucher not found: $voucherNumber");
            throw new Exception("Voucher not found: $voucherNumber");
        }

        if ($voucherPin && isset($voucher['voucher_pin'])) {
            if ($voucher['voucher_pin'] !== $voucherPin) {
                error_log("ZURUBANK HOLD: Invalid PIN for voucher $voucherNumber");
                throw new Exception("Invalid voucher PIN");
            }
            error_log("ZURUBANK HOLD: PIN verified for voucher $voucherNumber");
        }

        if (in_array($action, ['HOLD', 'PLACE', 'PLACE_HOLD'])) {
            if ($voucher['status'] === 'hold') {
                throw new Exception("Voucher is already on hold");
            }
            if ($voucher['status'] !== 'active') {
                throw new Exception("Voucher cannot be held (status: {$voucher['status']})");
            }
            
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
    // ACCOUNT HANDLING (also handles resolved CARD requests)
    // ============================================================
    } elseif ($assetType === 'ACCOUNT') {
        if (!$accountNumber) {
            throw new Exception("Account number required");
        }

        $stmt = $pdo->prepare("
            SELECT 
                account_id,
                account_number,
                balance,
                status,
                currency,
                user_id
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
            // Ensure financial_holds table exists
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
                    debited_at TIMESTAMP,
                    released_at TIMESTAMP,
                    created_at TIMESTAMP DEFAULT NOW(),
                    updated_at TIMESTAMP DEFAULT NOW()
                )
            ");

            $balance = floatval($account['balance'] ?? 0);
            
            error_log("ZURUBANK HOLD: Account balance: $balance, Requested: $amount");
            
            if ($balance < $amount) {
                throw new Exception(
                    "Insufficient balance. "
                    . "Available: $balance, Requested: $amount"
                );
            }

            // Insert hold record
            $stmt = $pdo->prepare("
                INSERT INTO financial_holds 
                    (account_id, amount, hold_reference, status, requester, signature_verified, expires_at)
                VALUES (?, ?, ?, 'HELD', ?, ?, NOW() + INTERVAL '24 hours')
                RETURNING id
            ");
            $stmt->execute([$account['account_id'], $amount, $holdReference, $requester, $isValid ? 1 : 0]);
            $holdId = $stmt->fetchColumn();

            // Update account balance - reduce balance directly
            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET balance = balance - :amount
                WHERE account_id = :account_id 
                AND balance >= :amount
                RETURNING balance
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
                'hold_id' => $holdId
            ];
            
        } elseif ($action === 'DEBIT' || $action === 'DEBIT_HOLD' || $action === 'DEBIT_FUNDS') {
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
                SET balance = balance - :amount
                WHERE account_id = :account_id 
                AND balance >= :amount
                RETURNING balance
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
                UPDATE financial_holds SET status = 'DEBITED', debited_at = NOW()
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
                'total_balance' => floatval($updated['balance'])
            ];
            
        } elseif (in_array($action, ['RELEASE', 'RELEASE_HOLD', 'UNHOLD'])) {
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

            $fullHeldAmount = floatval($hold['amount']);

            // ============================================================
            // PARTIAL RELEASE: if the caller supplies a positive amount
            // strictly less than the full held amount, release only that
            // much back to available balance. The difference stays
            // captured - it was already removed from balance when the
            // hold was placed, and is simply never returned. This is
            // standard authorization/capture behavior (the same way a
            // card issuer releases the unused portion of a hotel
            // pre-auth while keeping the actual charge) - not a special
            // case invented for this system.
            //
            // If $amount is 0/absent, or >= the full held amount, this
            // behaves exactly as before: release the FULL amount. Every
            // existing caller that never sends an amount on release is
            // completely unaffected.
            // ============================================================
            $releaseAmount = ($amount > 0 && $amount < $fullHeldAmount) ? $amount : $fullHeldAmount;
            $isPartial = $releaseAmount < $fullHeldAmount;
            $capturedAmount = round($fullHeldAmount - $releaseAmount, 2);

            $stmt = $pdo->prepare("
                UPDATE accounts 
                SET balance = balance + :amount
                WHERE account_id = :account_id 
                RETURNING balance
            ");
            $stmt->execute([
                'amount' => $releaseAmount,
                'account_id' => $account['account_id']
            ]);
            $updated = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$updated) {
                throw new Exception("Failed to release hold");
            }

            // ============================================================
            // FIX: Ambiguous-parameter crash on release.
            //
            // ROOT CAUSE: :status was bound once but referenced TWICE in
            // this query - once in the plain SET assignment, and again
            // inside the CASE...WHEN comparison. PDO/Postgres's type
            // inference for a repeated named placeholder deduced two
            // different types across those two occurrences (text vs.
            // character varying), throwing:
            //   SQLSTATE[42P08]: Ambiguous parameter: inconsistent types
            //   deduced for parameter $1 - text versus character varying
            // This fired on every hold release, so RELEASE_HOLD always
            // reported ERROR back to the caller even though the actual
            // balance UPDATE just above had already succeeded - the hold
            // row itself was never marked RELEASED/PARTIALLY_RELEASED,
            // leaving it stuck HELD forever in financial_holds.
            //
            // FIX: cast every occurrence of :status to the same type
            // (::varchar) so Postgres never has to infer - purely
            // additive, the bound value was always a plain string already.
            //
            // ALSO FIXED: the execute() array below was missing the
            // leading ':' on the hold_id key ('hold_id' => ... instead of
            // ':hold_id' => ...), which does not bind to the :hold_id
            // placeholder in the query at all.
            // ============================================================
            $stmt = $pdo->prepare("
                UPDATE financial_holds 
                SET status = :status::varchar,
                    released_at = NOW(),
                    debited_at = CASE WHEN :status::varchar = 'PARTIALLY_RELEASED' THEN NOW() ELSE debited_at END
                WHERE id = :hold_id
            ");
            $stmt->execute([
                ':status' => $isPartial ? 'PARTIALLY_RELEASED' : 'RELEASED',
                ':hold_id' => $hold['id']
            ]);

            $responsePayload = [
                'status' => 'SUCCESS',
                'hold_placed' => false,
                'message' => $isPartial
                    ? "Hold partially released: {$releaseAmount} returned to balance, {$capturedAmount} captured"
                    : 'Hold released',
                'hold_reference' => $holdReference,
                'asset_type' => 'ACCOUNT',
                'asset_id' => $account['account_id'],
                'amount' => $releaseAmount,
                'captured_amount' => $capturedAmount,
                'full_held_amount' => $fullHeldAmount,
                'is_partial' => $isPartial,
                'total_balance' => floatval($updated['balance'])
            ];
            
        } else {
            throw new Exception("Unsupported action: $action");
        }

    // ============================================================
    // WALLET HANDLING
    // ============================================================
    } elseif ($assetType === 'WALLET' || $assetType === 'E-WALLET' || $assetType === 'EWALLET') {
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
            ");

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
                    released_at = NOW()
                WHERE hold_reference = :hold_reference 
                AND status = 'HELD'
                AND wallet_id = :wallet_id
                RETURNING id, wallet_id
            ");
            $stmt->execute([
                'hold_reference' => $holdReference,
                'wallet_id' => $wallet['wallet_id']
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
?>
