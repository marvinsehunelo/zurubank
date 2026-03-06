<?php
// --------------------------------------------------
// generate_code.php
// ZuruBank Instant Money Voucher Generator
// ALIGNED with SwapService expectations
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config/db.php';

function generateVoucherSwap(PDO $pdo, array $payload): array
{
    try {
        $pdo->beginTransaction();

        // Log incoming payload
        error_log("ZURUBANK generate_code.php received: " . json_encode($payload));

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

        if ($beneficiaryPhone === '' || $amount <= 0) {
            throw new Exception("beneficiary_phone and valid amount are required");
        }

        // Create tables if not exists
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
                code_hash VARCHAR(255)
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
                source_institution VARCHAR(100)
            )
        ");

        // Expiry (24 hours from now)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // Generate voucher number and PIN
        $voucherNumber = str_pad(random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $voucherPin    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Generate auth code
        $authCode = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        // Insert into instant_money_vouchers
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
                code_hash
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
                :code_hash
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
            ':code_hash'              => $codeHash
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
                source_institution
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
                :source_institution
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
            ':source_institution' => $sourceInstitution
        ]);

        $detailsId = $stmtDetails->fetchColumn();

        $pdo->commit();

        // Return in format SwapService expects
        return [
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
            'message' => 'ATM token generated successfully',
            'metadata' => [
                'voucher_id' => $voucherId,
                'reference' => $reference,
                'source_institution' => $sourceInstitution,
                'source_hold_reference' => $sourceHoldReference,
                'code_hash' => $codeHash
            ]
        ];

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
        'error' => 'Invalid JSON payload'
    ]);
    exit;
}

$result = generateVoucherSwap($pdo, $payload);
echo json_encode($result);
