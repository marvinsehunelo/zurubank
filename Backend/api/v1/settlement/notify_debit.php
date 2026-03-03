<?php
// --------------------------------------------------
// notify_debit.php
// Release held voucher and record interbank settlement
// Fixed: Look up voucher by hold_reference
// --------------------------------------------------

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/db.php';

$input = json_decode(file_get_contents('php://input'), true);

// Log incoming for debugging
error_log("ZURUBANK notify_debit.php received: " . json_encode($input));

// Handle different payload formats
$holdReference = $input['hold_reference'] ?? null;
$amount = $input['amount'] ?? null;
$transactionReference = $input['transaction_reference'] ?? null;

// Get counterparty bank
$counterpartyBank = $input['counterparty_bank'] ?? 
                    $input['source_institution'] ?? 
                    $input['destination_institution'] ?? 
                    'SACCUSSALIS';

// Generate settlement reference
$settlementReference = $input['settlement_reference'] ?? 
                       $transactionReference ?? 
                       'SET' . time() . rand(100, 999);

if (!$holdReference || !$amount) {
    error_log("ZURUBANK: Missing required fields - holdReference: $holdReference, amount: $amount");
    echo json_encode([
        "success" => false,
        "status" => "ERROR", 
        "debited" => false,
        "message" => "Hold reference and amount required",
        "received" => $input
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // First, find the voucher by source_hold_reference (this is where the hold_reference is stored)
    $stmt = $pdo->prepare("
        SELECT voucher_id, voucher_number, amount, status, holding_account, created_by,
               external_reference, source_institution, source_hold_reference
        FROM instant_money_vouchers 
        WHERE source_hold_reference = ? 
        FOR UPDATE
    ");
    $stmt->execute([$holdReference]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    // If not found, try external_reference
    if (!$voucher) {
        $stmt = $pdo->prepare("
            SELECT voucher_id, voucher_number, amount, status, holding_account, created_by,
                   external_reference, source_institution, source_hold_reference
            FROM instant_money_vouchers 
            WHERE external_reference = ?
            FOR UPDATE
        ");
        $stmt->execute([$holdReference]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // If still not found, try to find by voucher_number as last resort
    if (!$voucher) {
        $stmt = $pdo->prepare("
            SELECT voucher_id, voucher_number, amount, status, holding_account, created_by,
                   external_reference, source_institution, source_hold_reference
            FROM instant_money_vouchers 
            WHERE voucher_number = ?
            FOR UPDATE
        ");
        $stmt->execute([$holdReference]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$voucher) {
        // Log all possible matches for debugging
        error_log("ZURUBANK: No voucher found for hold_reference: $holdReference");
        
        // Check what's in the database
        $checkStmt = $pdo->query("
            SELECT voucher_number, source_hold_reference, external_reference, status 
            FROM instant_money_vouchers 
            WHERE status IN ('hold', 'active')
            LIMIT 5
        ");
        $availableVouchers = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("ZURUBANK: Available vouchers: " . json_encode($availableVouchers));
        
        throw new Exception("Voucher not found for hold reference: $holdReference");
    }

    error_log("ZURUBANK: Found voucher {$voucher['voucher_number']} for hold reference $holdReference");

    // Check status - allow both 'hold' and 'active' to be debited
    if ($voucher['status'] !== 'hold' && $voucher['status'] !== 'active') {
        throw new Exception("Voucher cannot be debited (status: {$voucher['status']})");
    }

    // Verify amount matches (allow small floating point difference)
    if (abs(floatval($voucher['amount']) - floatval($amount)) > 0.01) {
        throw new Exception("Amount mismatch. Voucher: {$voucher['amount']}, Requested: $amount");
    }

    // Mark voucher as redeemed (used)
    $stmt = $pdo->prepare("
        UPDATE instant_money_vouchers 
        SET status = 'redeemed', 
            redeemed_at = NOW(),
            external_reference = COALESCE(external_reference, ?)
        WHERE voucher_id = ?
    ");
    $stmt->execute([
        $settlementReference,
        $voucher['voucher_id']
    ]);

    // Get or create counterparty bank in swap_linked_banks
    $stmt = $pdo->prepare("
        SELECT id, bank_code FROM swap_linked_banks 
        WHERE bank_code = ? OR bank_name = ?
        LIMIT 1
    ");
    $stmt->execute([$counterpartyBank, $counterpartyBank]);
    $bank = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bank) {
        // Insert the counterparty bank if not exists
        $stmt = $pdo->prepare("
            INSERT INTO swap_linked_banks (bank_code, bank_name, status)
            VALUES (?, ?, 'active')
            RETURNING id, bank_code
        ");
        $stmt->execute([$counterpartyBank, $counterpartyBank]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Create a journal entry for this settlement
    $stmt = $pdo->prepare("
        INSERT INTO journals (reference, description, created_at)
        VALUES (?, ?, NOW())
        RETURNING journal_id
    ");
    $stmt->execute([
        $settlementReference,
        "Settlement of voucher {$voucher['voucher_number']} used at {$counterpartyBank}"
    ]);
    $journalId = $stmt->fetchColumn();

    // Record in swap_ledger
    $holdingAccount = $voucher['holding_account'] ?? 'VOUCHER-SUSPENSE';
    
    $stmt = $pdo->prepare("
        INSERT INTO swap_ledger 
        (reference_id, journal_id, debit_account, credit_account, amount, currency, description, created_at) 
        VALUES (?, ?, ?, ?, ?, 'BWP', ?, NOW())
    ");
    $stmt->execute([
        $settlementReference,
        $journalId,
        $holdingAccount,
        'INTERBANK:' . $counterpartyBank,
        $amount,
        "Voucher {$voucher['voucher_number']} cashed at {$counterpartyBank}"
    ]);

    // Create transaction record
    $stmt = $pdo->prepare("
        INSERT INTO transactions 
        (user_id, account_id, from_account, to_account, type, amount, reference, description, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW())
    ");
    $stmt->execute([
        $voucher['created_by'] ?? 1,
        0,
        $holdingAccount,
        "BANK:{$counterpartyBank}",
        'interbank_settlement',
        $amount,
        $settlementReference,
        "Voucher {$voucher['voucher_number']} settlement"
    ]);

    // Log in audit
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (entity, entity_id, action, category, severity, performed_by, performed_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        'instant_money_vouchers',
        $voucher['voucher_id'],
        'DEBIT',
        'financial',
        'info',
        $voucher['created_by'] ?? 1
    ]);

    $pdo->commit();

    // Return success in format expected by GenericBankClient
    echo json_encode([
        "success" => true,
        "status" => "SUCCESS",
        "debited" => true,
        "message" => "Voucher released and interbank settlement recorded",
        "voucher_number" => $voucher['voucher_number'],
        "hold_reference" => $holdReference,
        "amount" => $amount,
        "counterparty_bank" => $counterpartyBank,
        "settlement_reference" => $settlementReference,
        "journal_id" => $journalId,
        "data" => [
            "debited" => true,
            "transaction_reference" => $settlementReference,
            "hold_reference" => $holdReference
        ]
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ZURUBANK Release and settle error: " . $e->getMessage());
    
    // Return error in format expected by GenericBankClient
    echo json_encode([
        "success" => false,
        "status" => "ERROR", 
        "debited" => false,
        "message" => $e->getMessage()
    ]);
}
