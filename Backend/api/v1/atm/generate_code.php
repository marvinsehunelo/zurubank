<?php
// --------------------------------------------------
// generate_code.php
// ZuruBank Instant Money Voucher Generator
// ALIGNED with SwapService expectations
// CERTIFICATE-BASED VERIFICATION (Visa/Mastercard model)
// BENCHMARKED against SACCUSSALIS hold.php
// --------------------------------------------------

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

// Increase memory and buffer limits for large responses
ini_set('memory_limit', '512M');
ini_set('output_buffering', '4096');
ini_set('zlib.output_compression', 'On');
ini_set('zlib.output_compression_level', '6');

header('Content-Type: application/json');
header('Content-Encoding: gzip');

if (ob_get_level()) {
    ob_end_clean();
}
ob_start();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    error_log("=== ZURUBANK generate_code.php RECEIVED ===");
    error_log("Payload: " . json_encode($input));

    // ============================================================
    // CERTIFICATE-BASED VERIFICATION (REQUIRED)
    // MATCHES SACCUSSALIS hold.php EXACTLY
    // ============================================================
    
    if (!isset($input['certificate'])) {
        error_log("ZURUBANK generate_code: No certificate provided");
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

    // Extract payload fields (matching SwapService expectations)
    $beneficiaryPhone = trim($input['beneficiary_phone'] ?? $input['phone'] ?? '');
    $amount = floatval($input['amount'] ?? 0);
    $currency = trim($input['currency'] ?? 'BWP');
    $reference = trim($input['reference'] ?? '');
    $origin = trim($input['origin'] ?? $input['source_institution'] ?? 'SACCUSSALIS');
    $sourceInstitution = trim($input['source_institution'] ?? $input['from_institution'] ?? 'SACCUSSALIS');
    $sourceHoldReference = trim($input['source_hold_reference'] ?? $input['hold_reference'] ?? '');
    $sourceAssetType = trim($input['source_asset_type'] ?? $input['asset_type'] ?? '');
    $codeHash = trim($input['code_hash'] ?? '');
    $idempotencyKey = $input['idempotency_key'] ?? $input['idempotencyKey'] ?? null;
    $satPurchased = $input['sat_purchased'] ?? null;
    $satFeePaidBy = $input['sat_fee_paid_by'] ?? null;
    
    // Also check for note_breakdown (ATM denomination info)
    $noteBreakdown = $input['note_breakdown'] ?? [];

    if ($beneficiaryPhone === '' || $amount <= 0) {
        throw new Exception("beneficiary_phone and valid amount are required");
    }

    // Normalize phone number (remove + if present)
    $normalizedPhone = ltrim($beneficiaryPhone, '+');
    error_log("ZURUBANK: Normalized phone: {$normalizedPhone}");

    // Check idempotency to prevent duplicate voucher creation
    if ($idempotencyKey) {
        $checkStmt = $pdo->prepare("
            SELECT voucher_id FROM instant_money_vouchers 
            WHERE reference = :reference OR external_reference = :idempotency_key
            LIMIT 1
        ");
        $checkStmt->execute([
            'reference' => $reference,
            'idempotency_key' => $idempotencyKey
        ]);
        
        if ($checkStmt->fetch()) {
            error_log("ZURUBANK: Duplicate voucher request prevented (reference: {$reference})");
            
            // Send signed response for duplicate
            $duplicateResponse = [
                'success' => true,
                'token_generated' => true,
                'duplicate' => true,
                'message' => 'Duplicate request - voucher already generated',
                'requester' => $requester,
                'signature_verified' => $isValid,
                'timestamp' => time()
            ];
            
            send_signed_response($duplicateResponse);
            // send_signed_response exits, so no need to continue
        }
    }

    // Verify table exists with correct structure (but don't recreate)
    try {
        $pdo->query("SELECT 1 FROM instant_money_vouchers LIMIT 1");
    } catch (PDOException $e) {
        error_log("ZURUBANK: instant_money_vouchers table missing - please run migration");
        throw new Exception("Database table not properly initialized");
    }

    // Expiry (24 hours from now)
    $voucherExpiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $satExpiresAt = $satPurchased ? date('Y-m-d H:i:s', strtotime('+72 hours')) : null;

    // Generate voucher number and PIN
    $voucherNumber = str_pad(random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
    $voucherPin    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Generate auth code for cashout details
    $authCode = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

    // Insert into instant_money_vouchers (using ACTUAL column names)
    $stmt = $pdo->prepare("
        INSERT INTO instant_money_vouchers (
            amount,
            currency,
            created_by,
            recipient_phone,
            created_at,
            voucher_number,
            voucher_pin,
            voucher_created_at,
            voucher_expires_at,
            sat_purchased,
            sat_fee_paid_by,
            sat_expires_at,
            holding_account,
            status,
            origin,
            external_reference,
            source_institution,
            source_hold_reference,
            reference,
            source_asset_type,
            code_hash
        )
        VALUES (
            :amount,
            :currency,
            1,
            :recipient_phone,
            NOW(),
            :voucher_number,
            :voucher_pin,
            NOW(),
            :voucher_expires_at,
            :sat_purchased,
            :sat_fee_paid_by,
            :sat_expires_at,
            'VOUCHER-SUSPENSE',
            'active',
            :origin,
            :external_reference,
            :source_institution,
            :source_hold_reference,
            :reference,
            :source_asset_type,
            :code_hash
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
        ':sat_purchased'          => $satPurchased,
        ':sat_fee_paid_by'        => $satFeePaidBy,
        ':sat_expires_at'         => $satExpiresAt,
        ':origin'                 => $origin,
        ':external_reference'     => $idempotencyKey ?: $reference,
        ':source_institution'     => $sourceInstitution,
        ':source_hold_reference'  => $sourceHoldReference,
        ':reference'              => $reference,
        ':source_asset_type'      => $sourceAssetType,
        ':code_hash'              => $codeHash ?: $idempotencyKey
    ]);

    $voucherId = $stmt->fetchColumn();

    if (!$voucherId) {
        throw new Exception("Failed to create swap voucher");
    }

    // Create voucher_cashout_details table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS voucher_cashout_details (
            id SERIAL PRIMARY KEY,
            voucher_number VARCHAR(255) NOT NULL UNIQUE,
            auth_code VARCHAR(50) UNIQUE NOT NULL,
            amount NUMERIC(20,4) NOT NULL,
            currency VARCHAR(10) DEFAULT 'BWP',
            recipient_phone VARCHAR(50),
            instructions TEXT,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT NOW(),
            reference VARCHAR(255),
            source_institution VARCHAR(100),
            requester VARCHAR(100),
            signature_verified BOOLEAN DEFAULT FALSE,
            verification_method VARCHAR(50)
        )
    ");

    // Generate universal instructions
    $instructions = "🔐 **ZuruBank Cashout Voucher**\n\n"
        . "**Amount:** {$currency} {$amount}\n"
        . "**Voucher:** {$voucherNumber}\n"
        . "**PIN:** {$voucherPin}\n"
        . "**Expires:** " . date('d M Y H:i', strtotime($voucherExpiresAt)) . "\n\n"
        . "**How to cash out:**\n\n"
        . "🏧 **ATMs:**\n"
        . "1. Go to ANY ZuruBank ATM\n"
        . "2. Select 'Cardless Cashout'\n"
        . "3. Enter voucher number: {$voucherNumber}\n"
        . "4. Enter PIN: {$voucherPin}\n"
        . "5. Enter amount: {$currency} {$amount}\n"
        . "6. Collect your cash\n\n"
        . "👤 **Agents:**\n"
        . "1. Visit ANY ZuruBank Agent\n"
        . "2. Tell them you want to cashout a voucher\n"
        . "3. Provide voucher number: {$voucherNumber}\n"
        . "4. Provide PIN: {$voucherPin} when asked\n"
        . "5. Agent will process the cashout\n"
        . "6. Collect your cash and sign receipt\n\n"
        . "⏰ **Valid for 24 hours only**\n"
        . "🔒 Keep this information secure!";

    $stmtDetails = $pdo->prepare("
        INSERT INTO voucher_cashout_details (
            voucher_number,
            auth_code,
            amount,
            currency,
            recipient_phone,
            instructions,
            expires_at,
            created_at,
            reference,
            source_institution,
            requester,
            signature_verified,
            verification_method
        )
        VALUES (
            :voucher_number,
            :auth_code,
            :amount,
            :currency,
            :recipient_phone,
            :instructions,
            :expires_at,
            NOW(),
            :reference,
            :source_institution,
            :requester,
            :signature_verified,
            :verification_method
        )
        RETURNING id
    ");

    $stmtDetails->execute([
        ':voucher_number'  => $voucherNumber,
        ':auth_code'       => $authCode,
        ':amount'          => $amount,
        ':currency'        => $currency,
        ':recipient_phone' => $normalizedPhone,
        ':instructions'    => $instructions,
        ':expires_at'      => $voucherExpiresAt,
        ':reference'       => $reference,
        ':source_institution' => $sourceInstitution,
        ':requester'       => $requester,
        ':signature_verified' => $isValid ? 1 : 0,
        ':verification_method' => 'certificate'
    ]);

    $detailsId = $stmtDetails->fetchColumn();

    // Create audit logs table if needed
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
            id SERIAL PRIMARY KEY,
            entity_type VARCHAR(100),
            entity_id INTEGER,
            action VARCHAR(50),
            category VARCHAR(50),
            severity VARCHAR(20),
            performed_by VARCHAR(100),
            performed_by_cert_verified BOOLEAN DEFAULT FALSE,
            verification_method VARCHAR(50),
            metadata JSONB,
            performed_at TIMESTAMP DEFAULT NOW()
        )
    ");

    // Audit log
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (entity_type, entity_id, action, category, severity, performed_by, 
         performed_by_cert_verified, verification_method, metadata, performed_at)
        VALUES 
        ('instant_money_vouchers', :entity_id, 'GENERATE', 'financial', 'info', :performed_by,
         :cert_verified, :verification_method, :metadata, NOW())
    ");
    $auditStmt->execute([
        'entity_id' => $voucherId,
        'performed_by' => $requester,
        'cert_verified' => $isValid ? 1 : 0,
        'verification_method' => 'certificate',
        'metadata' => json_encode([
            'signature_verified' => $isValid,
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference,
            'beneficiary' => $beneficiaryPhone,
            'voucher_number' => $voucherNumber,
            'origin' => $origin,
            'source_institution' => $sourceInstitution,
            'source_hold_reference' => $sourceHoldReference
        ])
    ]);

    $pdo->commit();

    error_log("ZURUBANK: Voucher generated successfully - Number: {$voucherNumber}, Amount: {$amount}, Reference: {$reference}");

    // ============================================================
    // BUILD RESPONSE PAYLOAD (MATCHES SACCUSSALIS STRUCTURE)
    // ============================================================
    $responsePayload = [
        'status' => 'SUCCESS',
        'token_generated' => true,
        'atm_pin' => $voucherPin,
        'voucher_number' => $voucherNumber,
        'auth_code' => $authCode,
        'amount' => $amount,
        'currency' => $currency,
        'expiry' => $voucherExpiresAt,
        'expires_at' => $voucherExpiresAt,
        'reference' => $reference,
        'requester' => $requester,
        'signature_verified' => $isValid,
        'verification_method' => 'certificate',
        'message' => 'ATM token generated successfully',
        'instructions' => $instructions,
        'timestamp' => time(),
        'metadata' => [
            'voucher_id' => $voucherId,
            'reference' => $reference,
            'origin' => $origin,
            'source_institution' => $sourceInstitution,
            'source_hold_reference' => $sourceHoldReference,
            'source_asset_type' => $sourceAssetType,
            'note_breakdown' => $noteBreakdown
        ]
    ];
    
    error_log("ZURUBANK: Sending signed response");
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("ZURUBANK generate_code.php ERROR: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    $errorResponse = [
        'status' => 'ERROR',
        'token_generated' => false,
        'error' => $e->getMessage(),
        'timestamp' => time()
    ];
    
    // Try to send signed error response
    try {
        send_signed_response($errorResponse);
    } catch (Exception $sigError) {
        echo json_encode($errorResponse);
    }
    http_response_code(400);
} finally {
    if (ob_get_length() !== false) {
        ob_end_flush();
    }
}
