<?php
// --------------------------------------------------
// generate_code.php
// ZuruBank Instant Money Voucher Generator
// ALIGNED with SwapService expectations
// ADDED: Cryptographic signature verification
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';  // Add signature functions

function generateVoucherSwap(PDO $pdo, array $payload, string $requester, bool $signatureVerified): array
{
    try {
        $pdo->beginTransaction();

        // Log incoming payload
        error_log("ZURUBANK generate_code.php received from: {$requester}");
        error_log("Signature verified: " . ($signatureVerified ? 'YES' : 'NO'));
        error_log("Payload: " . json_encode($payload));

        // ------------------------------------------------------------
        // EXACTLY MATCHING SWAPSERVICE FIELDS
        // ------------------------------------------------------------
        
        $beneficiaryPhone = trim($payload['beneficiary_phone'] ?? '');
        $amount = floatval($payload['amount'] ?? 0);
        $reference = trim($payload['reference'] ?? '');
        $sourceInstitution = trim($payload['source_institution'] ?? 'SACCUSSALIS');
        $sourceHoldReference = trim($payload['source_hold_reference'] ?? '');
        $sourceAssetType = trim($payload['source_asset_type'] ?? '');
        $codeHash = trim($payload['code_hash'] ?? '');
        $idempotencyKey = $payload['idempotency_key'] ?? $payload['idempotencyKey'] ?? null;

        if ($beneficiaryPhone === '' || $amount <= 0) {
            throw new Exception("beneficiary_phone and valid amount are required");
        }

        // Check idempotency to prevent duplicate voucher creation
        if ($idempotencyKey) {
            $checkStmt = $pdo->prepare("
                SELECT voucher_id FROM instant_money_vouchers 
                WHERE reference = :reference OR code_hash = :code_hash
                LIMIT 1
            ");
            $checkStmt->execute([
                'reference' => $reference,
                'code_hash' => $idempotencyKey
            ]);
            
            if ($checkStmt->fetch()) {
                error_log("ZURUBANK: Duplicate voucher request prevented (reference: {$reference})");
                return [
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'Duplicate request - voucher already generated'
                ];
            }
        }

        // Create tables if not exists (unchanged)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS instant_money_vouchers (
                voucher_id SERIAL PRIMARY KEY,
                amount NUMERIC(20,4) NOT NULL,
                created_by INTEGER,
                recipient_phone VARCHAR(50),
                voucher_number VARCHAR(255) UNIQUE NOT NULL,
                voucher_pin VARCHAR(10) NOT NULL,
                voucher_expires_at TIMESTAMP NOT NULL,
                status VARCHAR(20) DEFAULT 'active',
                holding_account VARCHAR(50),
                created_at TIMESTAMP DEFAULT NOW(),
                reference VARCHAR(255),
                source_institution VARCHAR(100),
                source_hold_reference VARCHAR(255),
                source_asset_type VARCHAR(50),
                code_hash VARCHAR(255),
                created_by_requester VARCHAR(100),
                signature_verified BOOLEAN DEFAULT FALSE
            )
        ");

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
                signature_verified BOOLEAN DEFAULT FALSE
            )
        ");

        // Expiry (24 hours from now)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // Generate voucher number and PIN
        $voucherNumber = str_pad(random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $voucherPin    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Generate auth code
        $authCode = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        // Insert into instant_money_vouchers (with requester info)
        $stmt = $pdo->prepare("
            INSERT INTO instant_money_vouchers (
                amount,
                created_by,
                recipient_phone,
                voucher_number,
                voucher_pin,
                voucher_expires_at,
                status,
                holding_account,
                created_at,
                reference,
                source_institution,
                source_hold_reference,
                source_asset_type,
                code_hash,
                created_by_requester,
                signature_verified
            )
            VALUES (
                :amount,
                1,
                :recipient_phone,
                :voucher_number,
                :voucher_pin,
                :expires_at,
                'active',
                'VOUCHER-SUSPENSE',
                NOW(),
                :reference,
                :source_institution,
                :source_hold_reference,
                :source_asset_type,
                :code_hash,
                :requester,
                :signature_verified
            )
            RETURNING voucher_id
        ");

        $stmt->execute([
            ':amount'                 => $amount,
            ':recipient_phone'        => $beneficiaryPhone,
            ':voucher_number'         => $voucherNumber,
            ':voucher_pin'            => $voucherPin,
            ':expires_at'             => $expiresAt,
            ':reference'              => $reference,
            ':source_institution'     => $sourceInstitution,
            ':source_hold_reference'  => $sourceHoldReference,
            ':source_asset_type'      => $sourceAssetType,
            ':code_hash'              => $idempotencyKey ?: $codeHash,
            ':requester'              => $requester,
            ':signature_verified'     => $signatureVerified ? 1 : 0
        ]);

        $voucherId = $stmt->fetchColumn();

        if (!$voucherId) {
            throw new Exception("Failed to create swap voucher");
        }

        // Generate universal instructions
        $instructions = "🔐 **ZuruBank Cashout Voucher**\n\n"
            . "**Amount:** BWP {$amount}\n"
            . "**Voucher:** {$voucherNumber}\n"
            . "**PIN:** {$voucherPin}\n"
            . "**Expires:** " . date('d M Y H:i', strtotime($expiresAt)) . "\n\n"
            . "**How to cash out:**\n\n"
            . "🏧 **ATMs:**\n"
            . "1. Go to ANY ZuruBank ATM\n"
            . "2. Select 'Cardless Cashout'\n"
            . "3. Enter voucher number: {$voucherNumber}\n"
            . "4. Enter PIN: {$voucherPin}\n"
            . "5. Enter amount: BWP {$amount}\n"
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
                reference,
                source_institution,
                requester,
                signature_verified
            )
            VALUES (
                :voucher_number,
                :auth_code,
                :amount,
                'BWP',
                :recipient_phone,
                :instructions,
                :expires_at,
                :reference,
                :source_institution,
                :requester,
                :signature_verified
            )
            RETURNING id
        ");

        $stmtDetails->execute([
            ':voucher_number'  => $voucherNumber,
            ':auth_code'       => $authCode,
            ':amount'          => $amount,
            ':recipient_phone' => $beneficiaryPhone,
            ':instructions'    => $instructions,
            ':expires_at'      => $expiresAt,
            ':reference'       => $reference,
            ':source_institution' => $sourceInstitution,
            ':requester'       => $requester,
            ':signature_verified' => $signatureVerified ? 1 : 0
        ]);

        $detailsId = $stmtDetails->fetchColumn();

        // Audit log
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (entity, entity_id, action, category, severity, performed_by, metadata, performed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $auditStmt->execute([
            'instant_money_vouchers',
            $voucherId,
            'GENERATE',
            'financial',
            'info',
            $requester,
            json_encode([
                'signature_verified' => $signatureVerified,
                'amount' => $amount,
                'reference' => $reference,
                'beneficiary' => $beneficiaryPhone
            ])
        ]);

        $pdo->commit();

        // ============================================================
        // SEND SIGNED RESPONSE
        // ============================================================
        $responsePayload = [
            'success' => true,
            'token_generated' => true,
            'token_reference' => $voucherNumber,
            'atm_pin' => $voucherPin,
            'voucher_number' => $voucherNumber,
            'pin' => $voucherPin,
            'expiry' => $expiresAt,
            'expires_at' => $expiresAt,
            'amount' => $amount,
            'currency' => 'BWP',
            'requester' => $requester,
            'signature_verified' => $signatureVerified,
            'message' => 'ATM token generated successfully',
            'metadata' => [
                'voucher_id' => $voucherId,
                'reference' => $reference,
                'source_institution' => $sourceInstitution,
                'source_hold_reference' => $sourceHoldReference,
                'code_hash' => $codeHash
            ]
        ];
        
        send_signed_response($responsePayload);
        // Note: send_signed_response will echo and exit, so no need to return

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("ZURUBANK Generation Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

        return [
            'success' => false,
            'token_generated' => false,
            'error' => $e->getMessage()
        ];
    }
}

// -------------------------
// Endpoint Execution
// -------------------------
$payload = json_decode(file_get_contents('php://input'), true);

if (!$payload) {
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => 'Invalid JSON payload',
        'timestamp' => time()
    ]);
    exit;
}

// ============================================================
// VERIFY SIGNATURE FROM REQUESTER
// ============================================================
$signature = $payload['signature'] ?? null;
$timestamp = $payload['timestamp'] ?? null;

$payloadToVerify = [
    'reference' => $payload['reference'] ?? null,
    'beneficiary_phone' => $payload['beneficiary_phone'] ?? null,
    'amount' => $payload['amount'] ?? null,
    'source_institution' => $payload['source_institution'] ?? null,
    'currency' => $payload['currency'] ?? 'BWP'
];
$payloadToVerify = array_filter($payloadToVerify);

if (!$signature) {
    error_log("ZURUBANK generate_code: Missing signature");
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => 'Missing signature - voucher generation requests must be signed',
        'timestamp' => time()
    ]);
    exit;
}

// Determine who is requesting
$requester = $payload['requester'] ?? 'VOUCHMORPH';
$publicKey = get_requester_public_key($requester, $pdo);

if (!$publicKey) {
    error_log("ZURUBANK generate_code: No public key for requester: {$requester}");
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => "No public key found for requester: {$requester}",
        'timestamp' => time()
    ]);
    exit;
}

// Verify signature
$isValid = verify_signature($payloadToVerify, $signature, $publicKey, $timestamp);

if (!$isValid) {
    error_log("ZURUBANK generate_code: Invalid signature from {$requester}");
    echo json_encode([
        'success' => false,
        'token_generated' => false,
        'error' => 'Invalid signature - voucher generation cannot be trusted',
        'timestamp' => time()
    ]);
    exit;
}

error_log("ZURUBANK generate_code: Signature verified from {$requester}");

// Execute generation with verified requester
$result = generateVoucherSwap($pdo, $payload, $requester, true);
echo json_encode($result);

/**
 * Get public key for a requester
 */
function get_requester_public_key($requester, $pdo) {
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
    
    $envKey = strtoupper($requester) . '_PUBLIC_KEY';
    return getenv($envKey) ?: null;
}
