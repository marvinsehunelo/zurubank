<?php
// --------------------------------------------------
// generate_code.php
// ZuruBank Instant Money Voucher Generator
// Updated to work with SwapService
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config/db.php';

/**
 * Generate a voucher that can be cashed out at ANY ZuruBank ATM/agent
 */
function generateVoucherSwap(PDO $pdo, array $payload): array
{
    try {
        $pdo->beginTransaction();

        // Log incoming payload
        error_log("generate_voucher_swap payload: " . json_encode($payload));

        // ------------------------------------------------------------
        // MAP SWAPSERVICE FIELDS TO INTERNAL FIELDS
        // ------------------------------------------------------------
        
        // SwapService sends: beneficiary_phone, amount, reference, source_institution, source_hold_reference
        $beneficiaryPhone = trim($payload['beneficiary_phone'] ?? $payload['recipient_phone'] ?? '');
        $amount = floatval($payload['amount'] ?? 0);
        $externalReference = $payload['reference'] ?? $payload['external_ref'] ?? uniqid('VCH-');
        $sourceInstitution = $payload['source_institution'] ?? 'SACCUSSALIS';
        $sourceHoldReference = $payload['source_hold_reference'] ?? null;
        
        // Internal fields
        $createdBy = 1; // Default system user
        $destinationBankId = 2; // ZURUBANK ID
        $sourceBankId = 1; // Source bank ID

        if ($beneficiaryPhone === '' || $amount <= 0) {
            throw new Exception("beneficiary_phone and valid amount are required");
        }

        // Check if users table exists and create system user if needed
        try {
            $stmtUser = $pdo->prepare("SELECT user_id FROM users WHERE user_id = ?");
            $stmtUser->execute([$createdBy]);
            if (!$stmtUser->fetch()) {
                // Create system user
                $pdo->prepare("INSERT INTO users (user_id, username, email, created_at) VALUES (?, 'system', 'system@zurubank.com', NOW()) ON CONFLICT DO NOTHING")->execute([$createdBy]);
            }
        } catch (Exception $e) {
            // Users table might not exist, continue anyway
            error_log("Note: " . $e->getMessage());
        }

        // Create tables if not exists
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
                redeemed_at TIMESTAMP,
                redeemed_by_user_id INTEGER,
                redeemed_by_atm VARCHAR(100),
                redeemed_by_agent VARCHAR(100)
            )
        ");

        // Create instant_money_vouchers table if it doesn't exist (with correct schema)
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
                external_reference VARCHAR(255),
                source_institution VARCHAR(100),
                source_hold_reference VARCHAR(255)
            )
        ");

        // Check if cashouts table needs alteration
        try {
            $pdo->exec("ALTER TABLE cashouts ALTER COLUMN atm_id DROP NOT NULL");
            $pdo->exec("ALTER TABLE cashouts ALTER COLUMN agent_id DROP NOT NULL");
        } catch (Exception $e) {
            // Columns might already be nullable or not exist
        }

        // Expiry (24 hours from now)
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        // Generate voucher number and PIN
        $voucherNumber = str_pad(random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        $voucherPin    = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Generate auth code
        $authCode = str_pad(random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        
        // Generate barcode
        $barcode = 'ZCB' . time() . substr($voucherNumber, -8);

        // Insert into instant_money_vouchers - NOW INCLUDING external_reference
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
                external_reference,
                source_institution,
                source_hold_reference
            )
            VALUES (
                :amount,
                :created_by,
                :recipient_phone,
                :voucher_number,
                :voucher_pin,
                :expires_at,
                'active',
                'VOUCHER-SUSPENSE',
                NOW(),
                :external_reference,
                :source_institution,
                :source_hold_reference
            )
            RETURNING voucher_id
        ");

        $stmt->execute([
            ':amount'                 => $amount,
            ':created_by'             => $createdBy,
            ':recipient_phone'        => $beneficiaryPhone,
            ':voucher_number'         => $voucherNumber,
            ':voucher_pin'            => $voucherPin,
            ':expires_at'             => $expiresAt,
            ':external_reference'     => $externalReference,
            ':source_institution'     => $sourceInstitution,
            ':source_hold_reference'  => $sourceHoldReference
        ]);

        $voucherId = $stmt->fetchColumn();

        if (!$voucherId) {
            throw new Exception("Failed to create swap voucher");
        }

        // Create cashout record
        $cashoutReference = 'CASHOUT-' . time() . '-' . substr($voucherNumber, -6);
        
        // Check what columns exist in cashouts table
        $stmtCols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'cashouts'");
        $columns = $stmtCols->fetchAll(PDO::FETCH_COLUMN);
        
        // Build dynamic insert based on existing columns
        $insertCols = ['trace_number', 'cashout_reference', 'source_bank_id', 'destination_bank_id', 
                      'user_id', 'amount', 'currency', 'status', 'created_at'];
        $insertVals = [':trace_number', ':cashout_reference', ':source_bank_id', ':destination_bank_id', 
                      ':user_id', ':amount', ':currency', ':status', 'NOW()'];
        
        // Add optional columns if they exist
        if (in_array('atm_id', $columns)) {
            $insertCols[] = 'atm_id';
            $insertVals[] = 'NULL';
        }
        if (in_array('agent_id', $columns)) {
            $insertCols[] = 'agent_id';
            $insertVals[] = 'NULL';
        }
        if (in_array('external_reference', $columns)) {
            $insertCols[] = 'external_reference';
            $insertVals[] = ':external_reference';
        }
        if (in_array('source_hold_reference', $columns)) {
            $insertCols[] = 'source_hold_reference';
            $insertVals[] = ':source_hold_reference';
        }
        
        $sql = "INSERT INTO cashouts (" . implode(', ', $insertCols) . ") 
                VALUES (" . implode(', ', $insertVals) . ") 
                RETURNING cashout_id";
        
        $stmtCashout = $pdo->prepare($sql);
        
        $params = [
            ':trace_number'        => $voucherNumber,
            ':cashout_reference'   => $cashoutReference,
            ':source_bank_id'      => $sourceBankId,
            ':destination_bank_id' => $destinationBankId,
            ':user_id'             => $createdBy,
            ':amount'              => $amount,
            ':currency'            => 'BWP',
            ':status'              => 'READY'
        ];
        
        if (in_array('external_reference', $columns)) {
            $params[':external_reference'] = $externalReference;
        }
        if (in_array('source_hold_reference', $columns)) {
            $params[':source_hold_reference'] = $sourceHoldReference;
        }
        
        $stmtCashout->execute($params);
        $cashoutId = $stmtCashout->fetchColumn();

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
                barcode,
                amount,
                currency,
                recipient_phone,
                instructions,
                expires_at
            )
            VALUES (
                :voucher_number,
                :auth_code,
                :barcode,
                :amount,
                'BWP',
                :recipient_phone,
                :instructions,
                :expires_at
            )
            RETURNING id
        ");

        $stmtDetails->execute([
            ':voucher_number'  => $voucherNumber,
            ':auth_code'       => $authCode,
            ':barcode'         => $barcode,
            ':amount'          => $amount,
            ':recipient_phone' => $beneficiaryPhone,
            ':instructions'    => $instructions,
            ':expires_at'      => $expiresAt
        ]);

        $detailsId = $stmtDetails->fetchColumn();

        $pdo->commit();

        // Return in format SwapService expects
        return [
            'token_generated' => true,
            'token_reference' => 'VCH-' . $voucherNumber,
            'atm_pin' => $voucherPin,
            'voucher_number' => $voucherNumber,
            'expiry' => $expiresAt,
            'message' => 'ATM token generated successfully',
            'debug' => [
                'voucher_id' => $voucherId,
                'cashout_id' => $cashoutId,
                'reference' => $externalReference
            ]
        ];

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log("Cashout Voucher Generation Error: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());

        return [
            'token_generated' => false,
            'message' => $e->getMessage()
        ];
    }
}

// -------------------------
// Endpoint Execution
// -------------------------
$payload = json_decode(file_get_contents('php://input'), true);

if (!$payload) {
    echo json_encode([
        'token_generated' => false,
        'message' => 'Invalid JSON payload'
    ], JSON_PRETTY_PRINT);
    exit;
}

$result = generateVoucherSwap($pdo, $payload);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
