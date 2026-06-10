<?php
/**
 * verify_asset_zurubank.php
 * Asset Verification for Zurubank - VOUCHER ONLY
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../helpers/crypto.php';  // Add this line

header("Content-Type: application/json");

error_log("=== ZURUBANK verify_asset.php CALLED ===");
error_log("RAW POST: " . file_get_contents("php://input"));

// -------------------------
// 1. Method Guard
// -------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "verified" => false, 
        "message" => "Method not allowed"
    ]);
    exit;
}

// -------------------------
// 2. Read Input
// -------------------------
$input = json_decode(file_get_contents("php://input"), true);

error_log("Parsed input: " . json_encode($input));

$assetType = strtoupper(
    $input['asset_type'] ?? 
    $input['type'] ?? 
    $input['source']['asset_type'] ?? 
    ''
);

$voucherNumber = $input['voucher_number'] ?? 
                 $input['voucher'] ?? 
                 $input['source']['voucher']['voucher_number'] ?? 
                 $input['source']['voucher_number'] ??
                 null;

$voucherPin = $input['voucher_pin'] ?? 
              $input['pin'] ?? 
              $input['source']['voucher']['voucher_pin'] ??
              $input['source']['pin'] ??
              null;

$claimantPhone = $input['claimant_phone'] ?? 
                 $input['phone'] ?? 
                 $input['source']['voucher']['claimant_phone'] ??
                 $input['source']['phone'] ??
                 null;

$amount = floatval($input['amount'] ?? $input['value'] ?? 0);
$reference = $input['reference'] ?? $input['transaction_reference'] ?? null;

error_log("Normalized - Type: $assetType, Voucher: $voucherNumber, Phone: $claimantPhone, Amount: $amount");

// ZURUBANK ONLY HANDLES VOUCHERS
if ($assetType !== 'VOUCHER') {
    error_log("ERROR: Unsupported asset type for ZURUBANK: $assetType");
    echo json_encode([
        "success" => true,
        "verified" => false,
        "message" => "ZURUBANK only supports VOUCHER asset type",
        "debug" => [
            "received_type" => $assetType,
            "supported_types" => ["VOUCHER"]
        ]
    ]);
    exit;
}

try {
    if (!isset($pdo)) {
        throw new Exception("Database connection failed to initialize.");
    }

    if (empty($voucherNumber)) {
        throw new Exception("Voucher number required");
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

    if ($voucher['voucher_pin']) {
        if (!$voucherPin) {
            throw new Exception("Voucher PIN required");
        }
        if ($voucherPin !== $voucher['voucher_pin']) {
            throw new Exception("Invalid voucher PIN");
        }
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
    
    // ============================================================
    // BUILD SIGNED RESPONSE USING THE NEW crypto.php FUNCTIONS
    // ============================================================
    
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
        "metadata" => [
            "voucher_id" => $voucher['voucher_id'],
            "currency" => $voucher['currency'] ?? 'BWP',
            "sat_purchased" => $voucher['sat_purchased'] ?? false,
            "status" => $voucher['status'],
            "created_at" => $voucher['created_at'],
            "external_reference" => $voucher['external_reference'],
            "source_institution" => $voucher['source_institution']
        ]
    ];
    
    // Send signed response (this adds signature and timestamp automatically)
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
            "voucher_number" => $voucherNumber
        ]
    ]);
}
