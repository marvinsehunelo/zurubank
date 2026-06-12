<?php
// --------------------------------------------------
// cashout_settlement.php
// ZuruBank Cashout Settlement - Bill the issuing bank
// When customer cashes out at ZuruBank ATM using 
// another bank's voucher/wallet, we bill that bank
// UPDATED: Certificate-based verification
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../helpers/response.php';
require __DIR__ . '/../../../helpers/crypto.php';
require __DIR__ . '/../../../helpers/CertificateManager.php';

// -------------------------
// Read Input
// -------------------------
$input = json_decode(file_get_contents("php://input"), true);

// -------------------------
// Certificate-Based Verification (Required for settlement)
// -------------------------
if (!isset($input['certificate'])) {
    error_log("ZURUBANK SETTLEMENT: No certificate provided");
    http_response_code(401);
    echo json_encode([
        "status" => "ERROR", 
        "message" => "Certificate required - please upgrade to certificate-based authentication"
    ]);
    exit;
}

$certManager = new CertificateManager('ZURUBANK');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("ZURUBANK SETTLEMENT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("ZURUBANK SETTLEMENT: Requester: {$requester}");

if (!$isValid) {
    error_log("ZURUBANK SETTLEMENT: Certificate verification failed");
    http_response_code(401);
    echo json_encode([
        "status" => "ERROR",
        "message" => "Certificate verification failed: " . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("ZURUBANK SETTLEMENT: Request verified from {$requester} using certificate");

// -------------------------
// Idempotency
// -------------------------
$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $input['request_id'] ?? $input['batch_reference'] ?? null;
if (!$idempotencyKey) {
    http_response_code(400);
    echo json_encode(["status" => "ERROR", "message" => "Idempotency key required"]);
    exit;
}

// Check idempotency
$idempotencyStmt = $pdo->prepare("
    SELECT result FROM idempotency_keys 
    WHERE key = :key AND created_at > NOW() - INTERVAL '24 hours'
");
$idempotencyStmt->execute(['key' => $idempotencyKey]);
$existing = $idempotencyStmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    echo json_encode([
        "status" => "SUCCESS",
        "message" => "Duplicate request - already processed",
        "batch_reference" => $idempotencyKey,
        "cached_result" => json_decode($existing['result'], true)
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Create necessary tables if they don't exist (with enhanced columns)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cashouts (
            cashout_id SERIAL PRIMARY KEY,
            trace_number VARCHAR(100) UNIQUE NOT NULL,
            user_id INTEGER,
            source_bank_id INTEGER,
            destination_bank_id INTEGER,
            amount NUMERIC(20,4) NOT NULL,
            currency VARCHAR(10) DEFAULT 'BWP',
            cashout_reference VARCHAR(255),
            status VARCHAR(50) DEFAULT 'PENDING',
            dispensed_at TIMESTAMP,
            interchange_fee NUMERIC(20,4) DEFAULT 0,
            atm_fee NUMERIC(20,4) DEFAULT 0,
            total_billed NUMERIC(20,4) DEFAULT 0,
            billing_reference VARCHAR(100),
            billed_by VARCHAR(100),
            billed_by_cert_verified BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS incoming_pre_advice (
            id SERIAL PRIMARY KEY,
            trace_number VARCHAR(100) NOT NULL,
            issuer_bank_id INTEGER NOT NULL,
            destination_bank_id INTEGER NOT NULL,
            user_id INTEGER,
            amount NUMERIC(20,4) NOT NULL,
            currency VARCHAR(10) DEFAULT 'BWP',
            cashout_reference VARCHAR(255),
            billing_reference VARCHAR(100),
            status VARCHAR(50) DEFAULT 'PENDING',
            requester VARCHAR(100),
            signature_verified BOOLEAN DEFAULT FALSE,
            verification_method VARCHAR(50),
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW(),
            UNIQUE(trace_number, issuer_bank_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS interbank_billing (
            id SERIAL PRIMARY KEY,
            billing_reference VARCHAR(100) UNIQUE NOT NULL,
            debtor_bank_id INTEGER NOT NULL,
            creditor_bank_id INTEGER NOT NULL,
            amount NUMERIC(20,4) NOT NULL,
            currency VARCHAR(10) DEFAULT 'BWP',
            cashout_trace VARCHAR(100),
            original_amount NUMERIC(20,4),
            interchange_fee NUMERIC(20,4) DEFAULT 0,
            atm_fee NUMERIC(20,4) DEFAULT 0,
            status VARCHAR(50) DEFAULT 'PENDING',
            due_date DATE,
            requester VARCHAR(100),
            signature_verified BOOLEAN DEFAULT FALSE,
            verification_method VARCHAR(50),
            created_at TIMESTAMP DEFAULT NOW(),
            settled_at TIMESTAMP,
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    // Get cashouts that need to be billed to other banks
    // Only include those not already billed
    $stmt = $pdo->prepare("
        SELECT 
            c.cashout_id,
            c.trace_number,
            c.user_id,
            c.source_bank_id,
            c.destination_bank_id,
            c.amount,
            c.currency,
            c.cashout_reference,
            c.dispensed_at,
            sb.bank_name as source_bank_name,
            sb.bank_code as source_bank_code,
            db.bank_name as dest_bank_name
        FROM cashouts c
        LEFT JOIN swap_linked_banks sb ON c.source_bank_id = sb.id
        LEFT JOIN swap_linked_banks db ON c.destination_bank_id = db.id
        WHERE c.status IN ('COMPLETED', 'DISPENSED')
          AND c.dispensed_at IS NOT NULL
          AND c.source_bank_id IS NOT NULL
          AND (c.billing_reference IS NULL OR c.status != 'BILLED')
        FOR UPDATE
    ");
    $stmt->execute();
    $cashouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $billingRecords = [];
    $totalBillingAmount = 0;

    if ($cashouts) {
        foreach ($cashouts as $cashout) {
            $billingRef = 'BILL_' . time() . '_' . rand(1000, 9999) . '_' . substr($cashout['trace_number'], -6);
            
            // Calculate fees
            $cashoutAmount = floatval($cashout['amount']);
            $interchangeFee = $cashoutAmount * 0.01; // 1% interchange fee
            $atmFee = 5.00; // Fixed ATM usage fee
            $totalBilling = $cashoutAmount + $interchangeFee + $atmFee;

            // Insert billing record - this is what we'll send to the source bank
            $insert = $pdo->prepare("
                INSERT INTO interbank_billing
                (billing_reference, debtor_bank_id, creditor_bank_id, amount, currency, 
                 cashout_trace, original_amount, interchange_fee, atm_fee, status, due_date,
                 requester, signature_verified, verification_method, created_at, updated_at)
                VALUES 
                (:billing_ref, :debtor_bank_id, :creditor_bank_id, :amount, :currency,
                 :cashout_trace, :original_amount, :interchange_fee, :atm_fee, 'PENDING', 
                 NOW() + INTERVAL '1 day',
                 :requester, :sig_verified, :verification_method, NOW(), NOW())
                RETURNING id
            ");
            $insert->execute([
                'billing_ref' => $billingRef,
                'debtor_bank_id' => $cashout['source_bank_id'],      // The bank that issued the voucher/wallet (debtor)
                'creditor_bank_id' => 1,                               // ZuruBank internal ID as creditor
                'amount' => $totalBilling,
                'currency' => $cashout['currency'] ?? 'BWP',
                'cashout_trace' => $cashout['trace_number'],
                'original_amount' => $cashoutAmount,
                'interchange_fee' => $interchangeFee,
                'atm_fee' => $atmFee,
                'requester' => $requester,
                'sig_verified' => $isValid ? 1 : 0,
                'verification_method' => 'certificate'
            ]);

            $billingId = $insert->fetchColumn();

            // Insert pre-advice notification for the source bank
            $preAdvice = $pdo->prepare("
                INSERT INTO incoming_pre_advice
                (trace_number, issuer_bank_id, destination_bank_id, user_id, amount, currency, 
                 cashout_reference, billing_reference, status, requester, signature_verified, 
                 verification_method, created_at, updated_at)
                VALUES 
                (:trace_number, :issuer_bank_id, :destination_bank_id, :user_id, :amount, :currency,
                 :cashout_reference, :billing_reference, 'PENDING', :requester, :sig_verified,
                 :verification_method, NOW(), NOW())
            ");
            $preAdvice->execute([
                'trace_number' => $cashout['trace_number'],
                'issuer_bank_id' => 1, // ZuruBank as issuer (we're sending the bill)
                'destination_bank_id' => $cashout['source_bank_id'],
                'user_id' => $cashout['user_id'],
                'amount' => $totalBilling,
                'currency' => $cashout['currency'] ?? 'BWP',
                'cashout_reference' => $cashout['cashout_reference'] ?? $billingRef,
                'billing_reference' => $billingRef,
                'requester' => $requester,
                'sig_verified' => $isValid ? 1 : 0,
                'verification_method' => 'certificate'
            ]);

            // Update cashout status with billing info
            $update = $pdo->prepare("
                UPDATE cashouts
                SET status = 'BILLED',
                    billing_reference = :billing_ref,
                    interchange_fee = :interchange_fee,
                    atm_fee = :atm_fee,
                    total_billed = :total_billed,
                    billed_by = :requester,
                    billed_by_cert_verified = :sig_verified,
                    updated_at = NOW()
                WHERE cashout_id = :cashout_id
            ");
            $update->execute([
                'cashout_id' => $cashout['cashout_id'],
                'billing_ref' => $billingRef,
                'interchange_fee' => $interchangeFee,
                'atm_fee' => $atmFee,
                'total_billed' => $totalBilling,
                'requester' => $requester,
                'sig_verified' => $isValid ? 1 : 0
            ]);

            // Record in swap_ledger for accounting
            $stmt = $pdo->prepare("
                INSERT INTO swap_ledger 
                (reference_id, debit_account, credit_account, amount, currency, description, 
                 requester, signature_verified, created_at) 
                VALUES 
                (:reference_id, :debit_account, :credit_account, :amount, :currency, :description,
                 :requester, :sig_verified, NOW())
            ");
            $stmt->execute([
                'reference_id' => $billingRef,
                'debit_account' => 'INTERBANK_RECEIVABLE:' . ($cashout['source_bank_code'] ?? $cashout['source_bank_id']),
                'credit_account' => 'ATM_CASHOUT_REVENUE',
                'amount' => $totalBilling,
                'currency' => $cashout['currency'] ?? 'BWP',
                'description' => "Billing for cashout {$cashout['trace_number']} - Amount: {$cashoutAmount}, Interchange: {$interchangeFee}, ATM Fee: {$atmFee}",
                'requester' => $requester,
                'sig_verified' => $isValid ? 1 : 0
            ]);

            $billingRecords[] = [
                "cashout_id" => $cashout['cashout_id'],
                "trace_number" => $cashout['trace_number'],
                "billing_reference" => $billingRef,
                "billing_id" => $billingId,
                "source_bank" => $cashout['source_bank_name'] ?? $cashout['source_bank_code'] ?? 'Unknown',
                "original_amount" => $cashoutAmount,
                "interchange_fee" => $interchangeFee,
                "atm_fee" => $atmFee,
                "total_billing" => $totalBilling
            ];

            $totalBillingAmount += $totalBilling;
        }

        // Log the batch settlement
        $batchRef = 'BATCH_' . time() . '_' . rand(1000, 9999);
        $stmt = $pdo->prepare("
            INSERT INTO journals (reference, description, created_by, created_at)
            VALUES (:reference, :description, :created_by, NOW())
            RETURNING journal_id
        ");
        $stmt->execute([
            'reference' => $batchRef,
            'description' => "Interbank billing batch - " . count($billingRecords) . " items, total: " . $totalBillingAmount,
            'created_by' => $requester
        ]);
        $journalId = $stmt->fetchColumn();

        // Create summary ledger entry
        $stmt = $pdo->prepare("
            INSERT INTO swap_ledger 
            (reference_id, journal_id, debit_account, credit_account, amount, currency, description, 
             requester, signature_verified, created_at) 
            VALUES 
            (:reference_id, :journal_id, :debit_account, :credit_account, :amount, :currency, :description,
             :requester, :sig_verified, NOW())
        ");
        $stmt->execute([
            'reference_id' => $batchRef,
            'journal_id' => $journalId,
            'debit_account' => 'INTERBANK_RECEIVABLE_SUMMARY',
            'credit_account' => 'INCOME_ACCRUAL',
            'amount' => $totalBillingAmount,
            'currency' => 'BWP',
            'description' => "Batch billing for " . count($billingRecords) . " cashouts",
            'requester' => $requester,
            'sig_verified' => $isValid ? 1 : 0
        ]);

        // Audit log
        $auditStmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (entity_type, entity_id, action, category, severity, performed_by, 
             performed_by_cert_verified, verification_method, metadata, performed_at)
            VALUES 
            ('settlement_batch', :batch_id, 'BILLING_BATCH', 'financial', 'info', :performed_by,
             :cert_verified, :verification_method, :metadata, NOW())
        ");
        $auditStmt->execute([
            'batch_id' => $batchRef,
            'performed_by' => $requester,
            'cert_verified' => $isValid ? 1 : 0,
            'verification_method' => 'certificate',
            'metadata' => json_encode([
                'cashout_count' => count($billingRecords),
                'total_amount' => $totalBillingAmount,
                'billing_records' => $billingRecords
            ])
        ]);
    }

    $pdo->commit();

    // Store idempotency result
    $resultData = [
        "status" => "SUCCESS",
        "message" => (count($billingRecords) > 0 ? count($billingRecords) . " cashout(s) billed to issuing banks" : "No cashouts pending billing"),
        "batch_reference" => $idempotencyKey,
        "total_billing_amount" => $totalBillingAmount,
        "billing_details" => $billingRecords,
        "processed_by" => $requester,
        "verification_method" => "certificate",
        "timestamp" => time()
    ];

    $storeStmt = $pdo->prepare("
        INSERT INTO idempotency_keys (key, operation, result, created_at)
        VALUES (:key, 'cashout_settlement', :result::jsonb, NOW())
    ");
    $storeStmt->execute([
        'key' => $idempotencyKey,
        'result' => json_encode($resultData)
    ]);

    error_log("ZURUBANK SETTLEMENT: Billing completed - " . count($billingRecords) . " cashouts, total: {$totalBillingAmount}");

    // Return response
    echo json_encode($resultData);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ZURUBANK SETTLEMENT ERROR: " . $e->getMessage());
    error_log("ZURUBANK SETTLEMENT Trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        "status" => "ERROR", 
        "message" => "Cashout settlement failed: " . $e->getMessage(),
        "timestamp" => time()
    ]);
}
