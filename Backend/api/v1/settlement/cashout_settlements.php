<?php
// --------------------------------------------------
// cashout_settlement.php
// ZuruBank Cashout Settlement - Bill the issuing bank
// When customer cashes out at ZuruBank ATM using 
// another bank's voucher/wallet, we bill that bank
// --------------------------------------------------

header('Content-Type: application/json');
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/../../../helpers/response.php';

// -------------------------
// Read Input
// -------------------------
$input = json_decode(file_get_contents("php://input"), true);

// -------------------------
// Idempotency (optional)
// -------------------------
$idempotencyKey = $_SERVER['HTTP_X_IDEMPOTENCY_KEY'] ?? $input['request_id'] ?? $input['batch_reference'] ?? null;
if (!$idempotencyKey) {
    http_response_code(400);
    echo json_encode(["status" => "ERROR", "message" => "Idempotency key required"]);
    exit;
}

try {
    $pdo->beginTransaction();

    // Create necessary tables if they don't exist
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
            created_at TIMESTAMP DEFAULT NOW()
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
            status VARCHAR(50) DEFAULT 'PENDING',
            created_at TIMESTAMP DEFAULT NOW(),
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
            status VARCHAR(50) DEFAULT 'PENDING',
            due_date DATE,
            created_at TIMESTAMP DEFAULT NOW(),
            settled_at TIMESTAMP
        )
    ");

    // Get today's cashouts that need to be billed to other banks
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
          AND NOT EXISTS (
              SELECT 1 FROM interbank_billing ib 
              WHERE ib.cashout_trace = c.trace_number
          )
        FOR UPDATE
    ");
    $stmt->execute();
    $cashouts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $billingRecords = [];
    $totalBillingAmount = 0;

    if ($cashouts) {
        foreach ($cashouts as $cashout) {
            $billingRef = 'BILL' . time() . rand(1000, 9999) . substr($cashout['trace_number'], -4);
            
            // Calculate any fees (e.g., ATM usage fee, interchange fee)
            $cashoutAmount = floatval($cashout['amount']);
            $interchangeFee = $cashoutAmount * 0.01; // 1% interchange fee
            $atmFee = 5.00; // Fixed ATM usage fee
            $totalBilling = $cashoutAmount + $interchangeFee + $atmFee;

            // Insert billing record - this is what we'll send to the source bank
            $insert = $pdo->prepare("
                INSERT INTO interbank_billing
                (billing_reference, debtor_bank_id, creditor_bank_id, amount, currency, 
                 cashout_trace, status, due_date, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'PENDING', NOW() + INTERVAL '1 day', NOW())
                RETURNING id
            ");
            $insert->execute([
                $billingRef,
                $cashout['source_bank_id'],      // The bank that issued the voucher/wallet (debtor)
                1,                                // ZuruBank internal ID as creditor
                $totalBilling,
                $cashout['currency'] ?? 'BWP',
                $cashout['trace_number']
            ]);

            $billingId = $insert->fetchColumn();

            // Insert pre-advice notification for the source bank
            $preAdvice = $pdo->prepare("
                INSERT INTO incoming_pre_advice
                (trace_number, issuer_bank_id, destination_bank_id, user_id, amount, currency, cashout_reference, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW())
            ");
            $preAdvice->execute([
                $cashout['trace_number'],
                1, // ZuruBank as issuer (we're sending the bill)
                $cashout['source_bank_id'],
                $cashout['user_id'],
                $totalBilling,
                $cashout['currency'] ?? 'BWP',
                $cashout['cashout_reference'] ?? $billingRef
            ]);

            // Update cashout status
            $update = $pdo->prepare("
                UPDATE cashouts
                SET status = 'BILLED',
                    updated_at = NOW()
                WHERE cashout_id = ?
            ");
            $update->execute([$cashout['cashout_id']]);

            // Record in swap_ledger for accounting
            $stmt = $pdo->prepare("
                INSERT INTO swap_ledger 
                (reference_id, debit_account, credit_account, amount, currency, description, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $billingRef,
                'INTERBANK_RECEIVABLE:' . ($cashout['source_bank_code'] ?? $cashout['source_bank_id']),
                'ATM_CASHOUT_REVENUE',
                $totalBilling,
                $cashout['currency'] ?? 'BWP',
                "Billing for cashout {$cashout['trace_number']} - Amount: {$cashoutAmount}, Fee: " . ($interchangeFee + $atmFee)
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
        $batchRef = 'BATCH' . time();
        $stmt = $pdo->prepare("
            INSERT INTO journals (reference, description, created_at)
            VALUES (?, ?, NOW())
            RETURNING journal_id
        ");
        $stmt->execute([
            $batchRef,
            "Interbank billing batch - " . count($billingRecords) . " items, total: " . $totalBillingAmount
        ]);
        $journalId = $stmt->fetchColumn();

        // Create summary ledger entry
        $stmt = $pdo->prepare("
            INSERT INTO swap_ledger 
            (reference_id, journal_id, debit_account, credit_account, amount, currency, description, created_at) 
            VALUES (?, ?, ?, ?, ?, 'BWP', ?, NOW())
        ");
        $stmt->execute([
            $batchRef,
            $journalId,
            'INTERBANK_RECEIVABLE_SUMMARY',
            'INCOME_ACCRUAL',
            $totalBillingAmount,
            "Batch billing for " . count($billingRecords) . " cashouts"
        ]);

    }

    $pdo->commit();

    // Return response
    if (count($billingRecords) > 0) {
        echo json_encode([
            "status" => "SUCCESS",
            "message" => count($billingRecords) . " cashout(s) billed to issuing banks",
            "batch_reference" => $idempotencyKey,
            "total_billing_amount" => $totalBillingAmount,
            "billing_details" => $billingRecords
        ]);
    } else {
        echo json_encode([
            "status" => "SUCCESS",
            "message" => "No cashouts pending billing",
            "batch_reference" => $idempotencyKey,
            "billing_details" => []
        ]);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Cashout settlement error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "ERROR", 
        "message" => $e->getMessage()
    ]);
}
