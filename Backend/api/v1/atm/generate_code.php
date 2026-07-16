<?php
// --------------------------------------------------
// generate_code.php
// ZuruBank Instant Money Voucher Generator
// INCLUDES created_by = 2, EXCLUDES redeemed_by
// --------------------------------------------------

// Force PHP to route all warnings/deprecations to logs only, maintaining pure JSON responses
ini_set('display_errors', '0');
ini_set('memory_limit', '512M');
ini_set('output_buffering', '4096');

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

header('Content-Type: application/json');

if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    error_log("=== ZURUBANK generate_code.php RECEIVED ===");
    error_log("Payload: " . json_encode($input));

    // ============================================================
    // CERTIFICATE-BASED VERIFICATION (REQUIRED)
    // ============================================================
    
    if (!isset($input['certificate'])) {
        error_log("ZURUBANK generate_code: No certificate provided");
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => false,
            'token_generated' => false,
            'error' => 'Certificate required - please upgrade to certificate-based authentication',
            'timestamp' => time()
        ]);
        exit;
    }
    
    $certManager = new CertificateManager('ZURUBANK');
    $verification = $certManager->verifySignedRequest($input);
    $isValid = $verification['verified'];
    $requester = $verification['requester'];
    
    error_log("ZURUBANK generate_code: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
    error_log("ZURUBANK generate_code: Requester: {$requester}");
    
    if (!$isValid) {
        error_log("ZURUBANK generate_code: Certificate verification failed");
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => false,
            'token_generated' => false,
            'error' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error'),
            'timestamp' => time()
        ]);
        exit;
    }
    
    error_log("ZURUBANK generate_code: Verified from {$requester}");

    // ============================================================
    // PROCESS TOKEN GENERATION
    // ============================================================

    $pdo->beginTransaction();

    // Safe PHP 8.4 scalar extractions bypassing null trim deprecations
    $beneficiaryPhone = trim((string)($input['beneficiary_phone'] ?? $input['phone'] ?? ''));
    $amount = floatval($input['amount'] ?? 0);
    $currency = trim((string)($input['currency'] ?? 'BWP'));
    $reference = trim((string)($input['reference'] ?? ''));
    
    // ============================================================
    // FIX: Get source institution and hold reference correctly
    // source_hold array contains {payload, signature, source, timestamp, is_hooked}
    // The hold_reference is a top-level field, NOT inside source_hold
    // ============================================================
    $sourceInstitution = null;
    $sourceHoldReference = null;

    // Get source institution from source_hold or top-level
    if (isset($input['source_hold']) && is_array($input['source_hold'])) {
        $sourceInstitution = $input['source_hold']['source'] ?? null;
    }

    if (empty($sourceInstitution)) {
        $sourceInstitution = trim((string)($input['source_institution'] ?? 'SACCUSSALIS'));
    } else {
        $sourceInstitution = trim((string)$sourceInstitution);
    }

    // Get hold reference from top-level fields, NOT from source_hold
    // The hold reference is sent as a top-level field by SwapService
    $sourceHoldReference = trim((string)($input['hold_reference'] ?? $input['source_hold_reference'] ?? $input['reference'] ?? ''));
    if ($sourceHoldReference === '') {
        $sourceHoldReference = null;
    }

    $origin = 'swap';
    $sourceAssetType = trim((string)($input['source_asset_type'] ?? ''));
    $codeHash = trim((string)($input['code_hash'] ?? ''));
    $idempotencyKey = $input['idempotency_key'] ?? $input['idempotencyKey'] ?? $reference;
    $satPurchased = $input['sat_purchased'] ?? null;
    $satFeePaidBy = $input['sat_fee_paid_by'] ?? null;
    $noteBreakdown = $input['note_breakdown'] ?? [];

    if ($beneficiaryPhone === '' || $amount <= 0) {
        throw new Exception("beneficiary_phone and valid amount are required");
    }

    // Normalize phone number
    $normalizedPhone = $beneficiaryPhone;
    if (!str_starts_with($normalizedPhone, '+')) {
        $normalizedPhone = '+' . $normalizedPhone;
    }
    error_log("ZURUBANK: Normalized phone: {$normalizedPhone}");

    // Check idempotency
    if ($idempotencyKey) {
        $checkStmt = $pdo->prepare("SELECT voucher_id FROM instant_money_vouchers WHERE reference = :reference LIMIT 1");
        $checkStmt->execute(['reference' => $reference]);
        if ($checkStmt->fetch()) {
            $duplicateResponse = [
                'status' => 'SUCCESS',
                'token_generated' => true,
                'duplicate' => true,
                'message' => 'Duplicate request - voucher already generated',
                'requester' => $requester,
                'timestamp' => time()
            ];
            
            if (ob_get_length()) ob_clean();
            error_log("ZURUBANK: Signing duplicate response");
            $signedPayload = $certManager->createSignedRequest($duplicateResponse, 'ZURUBANK');
            error_log("ZURUBANK: Duplicate response signed, has signature: " . (isset($signedPayload['signature']) ? 'YES' : 'NO'));
            echo json_encode($signedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Expiry
    $voucherExpiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $satExpiresAt = $satPurchased ? date('Y-m-d H:i:s', strtotime('+24 hours')) : null;

    // Generate codes
    $voucherNumber = str_pad((string)random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
    $voucherPin    = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $authCode = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $qrCode = "ZURUBANK:{$voucherNumber}:{$authCode}";
    $barcode = $voucherNumber;

    // INSERT with created_by = 2, excluding redeemed_by
    $stmt = $pdo->prepare("
        INSERT INTO instant_money_vouchers (
            amount,
            currency,
            created_by,
            recipient_phone,
            voucher_number,
            voucher_pin,
            voucher_created_at,
            voucher_expires_at,
            status,
            origin,
            reference,
            source_institution,
            source_hold_reference,
            code_hash,
            created_at
        )
        VALUES (
            :amount,
            :currency,
            2,
            :recipient_phone,
            :voucher_number,
            :voucher_pin,
            NOW(),
            :voucher_expires_at,
            'active',
            :origin,
            :reference,
            :source_institution,
            :source_hold_reference,
            :code_hash,
            NOW()
        )
        RETURNING voucher_id
    ");

    $stmt->execute([
        ':amount'                 => $amount,
        ':currency'               => $currency,
        ':recipient_phone'        => $normalizedPhone,
        ':voucher_number'         => $voucherNumber,
        ':voucher_pin'            => $voucherPin,
        ':voucher_expires_at'     => $voucherExpiresAt,
        ':origin'                 => $origin,
        ':reference'              => $reference,
        ':source_institution'     => $sourceInstitution,
        ':source_hold_reference'  => $sourceHoldReference,
        ':code_hash'              => $codeHash ?: (string)$idempotencyKey
    ]);

    $voucherId = $stmt->fetchColumn();

    if (!$voucherId) {
        throw new Exception("Failed to create voucher");
    }

    // Create voucher_cashout_details
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voucher_cashout_details (
            id SERIAL PRIMARY KEY,
            voucher_number VARCHAR(255) NOT NULL UNIQUE,
            auth_code VARCHAR(50) UNIQUE NOT NULL,
            qr_code TEXT,
            barcode VARCHAR(255),
            amount NUMERIC(20,4) NOT NULL,
            currency VARCHAR(10) DEFAULT 'BWP',
            recipient_phone VARCHAR(50),
            instructions TEXT,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT NOW(),
            reference VARCHAR(255),
            source_institution VARCHAR(100)
        )
    ");

    $instructions = "🔐 **ZuruBank Cashout Voucher**\n\n"
        . "**Amount:** {$currency} {$amount}\n"
        . "**Voucher:** {$voucherNumber}\n"
        . "**PIN:** {$voucherPin}\n"
        . "**Auth Code:** {$authCode}\n"
        . "**Expires:** " . date('d M Y H:i', strtotime($voucherExpiresAt)) . "\n\n"
        . "**How to cash out:**\n\n"
        . "🏧 Go to ANY ZuruBank ATM, select 'Cardless Cashout'\n"
        . "📱 Use ZuruBank Mobile App\n"
        . "👤 Visit ANY ZuruBank Agent\n\n"
        . "⏰ Valid for 24 hours only\n"
        . "🔒 Keep this information secure!";

    $stmtDetails = $pdo->prepare("
        INSERT INTO voucher_cashout_details (
            voucher_number,
            auth_code,
            qr_code,
            barcode,
            amount,
            currency,
            recipient_phone,
            instructions,
            expires_at,
            created_at,
            reference,
            source_institution
        )
        VALUES (
            :voucher_number,
            :auth_code,
            :qr_code,
            :barcode,
            :amount,
            :currency,
            :recipient_phone,
            :instructions,
            :expires_at,
            NOW(),
            :reference,
            :source_institution
        )
        RETURNING id
    ");

    $stmtDetails->execute([
        ':voucher_number'  => $voucherNumber,
        ':auth_code'       => $authCode,
        ':qr_code'         => $qrCode,
        ':barcode'         => $barcode,
        ':amount'          => $amount,
        ':currency'        => $currency,
        ':recipient_phone' => $normalizedPhone,
        ':instructions'    => $instructions,
        ':expires_at'      => $voucherExpiresAt,
        ':reference'       => $reference,
        ':source_institution' => $sourceInstitution
    ]);

    $pdo->commit();

    error_log("ZURUBANK: Voucher generated successfully - Number: {$voucherNumber}");

    // Response Structure
    $responsePayload = [
        'status' => 'SUCCESS',
        'token_generated' => true,
        'atm_pin' => $voucherPin,
        'voucher_number' => $voucherNumber,
        'auth_code' => $authCode,
        'qr_code' => $qrCode,
        'barcode' => $barcode,
        'amount' => $amount,
        'currency' => $currency,
        'expires_at' => $voucherExpiresAt,
        'reference' => $reference,
        'requester' => $requester,
        'signature_verified' => $isValid,
        'message' => 'ATM token generated successfully',
        'instructions' => $instructions,
        'timestamp' => time()
    ];
    
    error_log("=== BEFORE CREATING SIGNED RESPONSE ===");
    error_log("Response payload keys: " . implode(', ', array_keys($responsePayload)));
    error_log("Response payload preview: " . json_encode($responsePayload));
    
    // Clear out any structural warning buffer artifacts right before printing response
    if (ob_get_length()) {
        ob_clean();
    }
    
    error_log("ZURUBANK: Calling createSignedRequest...");
    $signedPayload = $certManager->createSignedRequest($responsePayload, 'ZURUBANK');
    
    error_log("=== AFTER CREATING SIGNED RESPONSE ===");
    error_log("Has signature: " . (isset($signedPayload['signature']) ? 'YES' : 'NO'));
    error_log("Has certificate: " . (isset($signedPayload['certificate']) ? 'YES' : 'NO'));
    if (isset($signedPayload['signature'])) {
        error_log("Signature preview: " . substr($signedPayload['signature'], 0, 50) . "...");
        error_log("Signature length: " . strlen($signedPayload['signature']));
    }
    if (isset($signedPayload['certificate'])) {
        error_log("Certificate length: " . strlen($signedPayload['certificate']));
    }
    error_log("Full signed payload (truncated): " . json_encode($signedPayload));
    
    echo json_encode($signedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    error_log("ZURUBANK: Response sent successfully");
    exit;

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("ZURUBANK generate_code.php ERROR: " . $e->getMessage());
    error_log("ERROR trace: " . $e->getTraceAsString());
    
    $errorResponse = [
        'status' => 'ERROR',
        'token_generated' => false,
        'error' => $e->getMessage(),
        'timestamp' => time()
    ];
    
    if (ob_get_length()) {
        ob_clean();
    }
    
    try {
        error_log("ZURUBANK: Attempting to sign error response");
        $fallbackCertManager = new CertificateManager('ZURUBANK');
        $signedErrorPayload = $fallbackCertManager->createSignedRequest($errorResponse, 'ZURUBANK');
        error_log("ZURUBANK: Error response signed successfully");
        echo json_encode($signedErrorPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Exception $sigError) {
        error_log("ZURUBANK: Failed to sign error response: " . $sigError->getMessage());
        echo json_encode($errorResponse);
    }
    http_response_code(400);
} finally {
    if (ob_get_length() !== false) {
        ob_end_flush();
    }
}
