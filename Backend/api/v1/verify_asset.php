<?php 
/**
 * /Backend/api/v1/verify_asset_zurubank.php
 * FIXED: Handles destination_asset_type, account_identifier, and VERIFY_ACCOUNT action
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../helpers/crypto.php';

header("Content-Type: application/json");

error_log("=== ZURUBANK verify_asset.php CALLED ===");
error_log("RAW POST: " . file_get_contents("php://input"));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "verified" => false, 
        "message" => "Method not allowed"
    ]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

error_log("Parsed input: " . json_encode($input));

// ============================================================
// FIX 1: Support destination_asset_type for account verification
// ============================================================
$assetType = strtoupper(
    $input['asset_type'] ?? 
    $input['destination_asset_type'] ??   // <-- ADDED
    $input['type'] ?? 
    $input['source']['asset_type'] ?? 
    ''
);

// ============================================================
// FIX 2: Support VERIFY_ACCOUNT action
// ============================================================
$action = strtoupper($input['action'] ?? 'VERIFY_ASSET');
if ($action === 'VERIFY_ACCOUNT' && empty($assetType)) {
    $assetType = 'ACCOUNT';
    error_log("verify_asset: VERIFY_ACCOUNT action detected, setting asset_type=ACCOUNT");
}

$pin = $input['pin'] ?? 
       $input['wallet_pin'] ?? 
       $input['atm_pin'] ?? 
       $input['voucher_pin'] ?? 
       $input['card_pin'] ?? 
       $input['asset_fields']['wallet_pin'] ?? 
       $input['asset_fields']['pin'] ?? 
       $input['asset_fields']['atm_pin'] ?? 
       $input['asset_fields']['voucher_pin'] ?? 
       $input['source']['wallet_pin'] ?? 
       $input['source']['pin'] ?? 
       null;

$accessToken = $input['access_token'] ?? null;
$sourceReference = $input['source_reference'] ?? null;
$isHooked = isset($input['_is_hooked']) && $input['_is_hooked'] === true;

error_log("verify_asset: Auth methods - PIN: " . ($pin ? 'present' : 'null') . 
          ", AccessToken: " . ($accessToken ? 'present' : 'null') . 
          ", SourceRef: " . ($sourceReference ? 'present' : 'null') . 
          ", IsHooked: " . ($isHooked ? 'true' : 'false'));

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

$voucherPin = $input['voucher_pin'] ?? 
              $input['voucherPin'] ?? 
              $input['voucherPIN'] ?? 
              $input['pin'] ?? 
              $input['source']['voucher']['voucher_pin'] ??
              $input['source']['pin'] ??
              null;

// ============================================================
// FIX 3: Support account_identifier from VouchMorph payload
// ============================================================
$accountNumber = $input['source_identifier'] ?? 
                 $input['account_identifier'] ??          // <-- ADDED
                 $input['destination_identifier'] ??      // <-- ADDED
                 $input['account_number'] ?? 
                 $input['source']['account_number'] ??
                 $input['source']['identifier'] ??
                 null;

$claimantPhone = $input['claimant_phone'] ?? 
                 $input['phone'] ?? 
                 $input['source']['voucher']['claimant_phone'] ??
                 $input['source']['phone'] ??
                 null;

$amount = floatval($input['amount'] ?? $input['value'] ?? 0);
$reference = $input['reference'] ?? $input['transaction_reference'] ?? null;

if (($assetType === 'VOUCHER' || $assetType === 'CASHOUT-VOUCHER') && empty($voucherNumber)) {
    if (!empty($accountNumber)) {
        $voucherNumber = trim($accountNumber);
        error_log("Using source_identifier as voucher_number: {$voucherNumber}");
    }
}

error_log("Normalized - Type: $assetType, Voucher: $voucherNumber, Account: $accountNumber, Phone: $claimantPhone, Amount: $amount");

if ($assetType !== 'VOUCHER' && $assetType !== 'CASHOUT-VOUCHER' && $assetType !== 'ACCOUNT') {
    error_log("ERROR: Unsupported asset type for ZURUBANK: $assetType");
    echo json_encode([
        "success" => true,
        "verified" => false,
        "message" => "ZURUBANK only supports VOUCHER, CASHOUT-VOUCHER, or ACCOUNT asset type",
        "debug" => [
            "received_type" => $assetType,
            "supported_types" => ["VOUCHER", "CASHOUT-VOUCHER", "ACCOUNT"]
        ]
    ]);
    exit;
}

try {
    if (!isset($pdo)) {
        throw new Exception("Database connection failed to initialize.");
    }

    if ($assetType === 'ACCOUNT') {
        error_log("Processing ACCOUNT verification for: $accountNumber");
        
        if (empty($accountNumber)) {
            throw new Exception("Account number required for ACCOUNT asset type");
        }

        $stmt = $pdo->prepare("
            SELECT 
                account_id,
                user_id,
                account_number,
                account_type,
                balance,
                currency,
                status,
                created_at
            FROM accounts
            WHERE account_number = :account_number
            LIMIT 1
        ");
        $stmt->execute(['account_number' => $accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            throw new Exception("Account not found: $accountNumber");
        }

        if ($account['status'] !== 'active') {
            throw new Exception("Account is not active (status: {$account['status']})");
        }

        $availableBalance = floatval($account['balance']);
        
        if ($amount > 0 && $availableBalance < $amount) {
            throw new Exception("Insufficient balance. Available: $availableBalance, Requested: $amount");
        }

        $pinVerified = false;
        if ($pin) {
            error_log("verify_asset: Optional PIN provided for account: " . substr($pin, -4));
            
            $pinStmt = $pdo->prepare("
                SELECT id, pin, amount, is_redeemed, hold_status
                FROM ewallet_pins 
                WHERE pin = :pin 
                AND is_redeemed = false 
                AND (expires_at IS NULL OR expires_at > NOW())
                LIMIT 1
            ");
            $pinStmt->execute(['pin' => $pin]);
            if ($pinStmt->fetch()) {
                $pinVerified = true;
                error_log("verify_asset: Optional PIN verified for account");
            } else {
                error_log("verify_asset: Optional PIN invalid - proceeding with account verification only");
            }
        }

        $holderName = "Account Holder";
        if ($account['user_id']) {
            $userStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
            $userStmt->execute([$account['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($user && $user['full_name']) {
                $holderName = $user['full_name'];
            }
        }

        $authMethod = 'account_only';
        if ($pinVerified) {
            $authMethod = 'account_pin';
        } elseif ($accessToken) {
            $authMethod = 'account_token';
        } elseif ($sourceReference) {
            $authMethod = 'account_source_ref';
        } elseif ($isHooked) {
            $authMethod = 'account_hooked';
        }

        $responsePayload = [
            "success" => true,
            "verified" => true,
            "asset_id" => $account['account_id'],
            "asset_type" => "ACCOUNT",
            "account_number" => $account['account_number'],
            "available_balance" => $availableBalance,
            "balance" => $availableBalance,
            "holder_name" => $holderName,
            "currency" => $account['currency'] ?? 'BWP',
            "account_type" => $account['account_type'],
            "auth_method" => $authMethod,
            "pin_verified" => $pinVerified,
            "metadata" => [
                "account_id" => $account['account_id'],
                "user_id" => $account['user_id'],
                "status" => $account['status'],
                "created_at" => $account['created_at'],
                "is_hooked" => $isHooked,
                "source_reference" => $sourceReference
            ]
        ];

        send_signed_response($responsePayload);
        exit;
    }

    if (empty($voucherNumber)) {
        error_log("Voucher number missing. Available fields: " . implode(', ', array_keys($input)));
        throw new Exception("Voucher number required. Available fields: " . implode(', ', array_keys($input)));
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT 
            voucher_id,
            voucher_number,
            voucher_pin,
            amount,
            currency,
            status,
            recipient_phone,
            created_by,
            redeemed_by,
            created_at,
            voucher_created_at,
            voucher_expires_at,
            redeemed_at,
            sat_purchased,
            sat_fee_paid_by,
            sat_expires_at,
            external_reference,
            source_institution,
            source_hold_reference
        FROM instant_money_vouchers
        WHERE voucher_number = :voucher_number
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->execute(['voucher_number' => $voucherNumber]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) {
        $stmt = $pdo->prepare("
            SELECT 
                voucher_id,
                voucher_number,
                voucher_pin,
                amount,
                currency,
                status,
                recipient_phone,
                created_by,
                redeemed_by,
                created_at,
                voucher_created_at,
                voucher_expires_at,
                redeemed_at,
                sat_purchased,
                sat_fee_paid_by,
                sat_expires_at,
                external_reference,
                source_institution,
                source_hold_reference
            FROM instant_money_vouchers
            WHERE TRIM(voucher_number) = TRIM(:voucher_number)
            LIMIT 1
        ");
        $stmt->execute(['voucher_number' => $voucherNumber]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$voucher) {
        throw new Exception("Voucher not found: $voucherNumber");
    }
    
    if ($voucher['status'] !== 'active') {
        throw new Exception("Voucher is not active (status: {$voucher['status']})");
    }

    if (!is_null($voucher['redeemed_at'])) {
        throw new Exception("Voucher has already been redeemed");
    }

    if (!is_null($voucher['voucher_expires_at'])) {
        $expiryTimestamp = strtotime($voucher['voucher_expires_at']);
        if ($expiryTimestamp < time()) {
            throw new Exception("Voucher has expired");
        }
    }

    $pinVerified = false;
    
    if ($voucher['voucher_pin']) {
        if ($voucherPin || $pin) {
            $pinToCheck = $voucherPin ?? $pin;
            if ($pinToCheck === $voucher['voucher_pin']) {
                $pinVerified = true;
                error_log("verify_asset: Voucher PIN verified");
            } else {
                error_log("verify_asset: Voucher PIN invalid - proceeding with voucher verification only");
            }
        } else {
            error_log("verify_asset: No PIN provided for voucher - proceeding without PIN");
        }
    } else {
        error_log("verify_asset: Voucher has no PIN set - proceeding");
    }

    $authMethod = 'voucher_only';
    if ($pinVerified) {
        $authMethod = 'voucher_pin';
    } elseif ($isHooked) {
        $authMethod = 'voucher_hooked';
        error_log("verify_asset: Voucher authenticated via hooked source");
    }

    if ($amount > 0 && floatval($voucher['amount']) !== $amount) {
        throw new Exception("Voucher amount mismatch. Expected: {$voucher['amount']}, Requested: $amount");
    }

    if ($claimantPhone && $voucher['recipient_phone']) {
        $normalizedClaimant = preg_replace('/[^0-9]/', '', $claimantPhone);
        $normalizedRecipient = preg_replace('/[^0-9]/', '', $voucher['recipient_phone']);
        
        if ($normalizedClaimant !== $normalizedRecipient) {
            error_log("Phone mismatch: Claimant $normalizedClaimant vs Recipient $normalizedRecipient");
        }
    }

    $pdo->commit();
    
    $holderName = "Voucher Holder";
    if ($voucher['recipient_phone'] && $voucher['created_by']) {
        $userStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
        $userStmt->execute([$voucher['created_by']]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($user && $user['full_name']) {
            $holderName = $user['full_name'];
        }
    }
    
    $responsePayload = [
        "success" => true,
        "verified" => true,
        "asset_id" => $voucher['voucher_id'],
        "asset_type" => "VOUCHER",
        "voucher_number" => $voucher['voucher_number'],
        "available_balance" => floatval($voucher['amount']),
        "balance" => floatval($voucher['amount']),
        "holder_name" => $holderName,
        "recipient_phone" => $voucher['recipient_phone'],
        "expiry_date" => $voucher['voucher_expires_at'],
        "status" => $voucher['status'],
        "auth_method" => $authMethod,
        "pin_verified" => $pinVerified,
        "metadata" => [
            "voucher_id" => $voucher['voucher_id'],
            "currency" => $voucher['currency'] ?? 'BWP',
            "sat_purchased" => $voucher['sat_purchased'] ?? false,
            "status" => $voucher['status'],
            "created_at" => $voucher['created_at'],
            "external_reference" => $voucher['external_reference'],
            "source_institution" => $voucher['source_institution'],
            "is_hooked" => $isHooked
        ]
    ];
    
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("ZURUBANK verify_asset error: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());
    
    http_response_code(200);
    echo json_encode([
        "success" => true,
        "verified" => false,
        "message" => $e->getMessage(),
        "timestamp" => time(),
        "debug" => [
            "asset_type" => $assetType,
            "voucher_number" => $voucherNumber,
            "account_number" => $accountNumber,
            "all_fields" => array_keys($input)
        ]
    ]);
}
