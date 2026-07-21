<?php
// --------------------------------------------------
// notify_debit.php
// Release held funds and record interbank settlement
// UPDATED: Certificate-based verification + ACCOUNT/VOUCHER branch
// FIXED: Matches actual table structures
// FIXED: Removed double-decrement of `balance` in the ACCOUNT path —
//        hold.php already subtracts the hold amount from `balance`
//        at PLACE_HOLD time. This endpoint only finalizes the hold's
//        status; it must NOT touch `balance` again, or every debit
//        silently charges the account twice.
// FIXED: Removed redeemed_by_cert_verified column (does not exist in table)
// --------------------------------------------------

header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../helpers/crypto.php';
require_once __DIR__ . '/../../../helpers/CertificateManager.php';

$input = json_decode(file_get_contents('php://input'), true);

// Log incoming for debugging
error_log("ZURUBANK notify_debit.php received: " . json_encode($input));

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED)
// ============================================================

if (!isset($input['certificate'])) {
    error_log("ZURUBANK NOTIFY_DEBIT: No certificate provided");
    echo json_encode([
        "success" => false,
        "status" => "ERROR", 
        "debited" => false,
        "message" => "Certificate required - please upgrade to certificate-based authentication"
    ]);
    exit;
}

$certManager = new CertificateManager('ZURUBANK');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("ZURUBANK NOTIFY_DEBIT: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));
error_log("ZURUBANK NOTIFY_DEBIT: Requester: {$requester}");

if (!$isValid) {
    error_log("ZURUBANK NOTIFY_DEBIT: Certificate verification failed");
    echo json_encode([
        "success" => false,
        "status" => "ERROR",
        "debited" => false,
        "message" => "Certificate verification failed: " . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("ZURUBANK NOTIFY_DEBIT: Request verified from {$requester} using certificate");

// ============================================================
// PROCESS DEBIT
// ============================================================

// Handle different payload formats
$holdReference = $input['hold_reference'] ?? $input['reference'] ?? null;
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
                       'SET' . time() . '_' . rand(100, 999);

if (!$holdReference || !$amount) {
    error_log("ZURUBANK NOTIFY_DEBIT: Missing required fields - holdReference: $holdReference, amount: $amount");
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

    // ============================================================
    // BRANCH 1: Try to find as ACCOUNT hold first
    // ============================================================
    $stmt = $pdo->prepare("
        SELECT id, account_id, amount, status
        FROM financial_holds
        WHERE hold_reference = :hold_reference
        AND status = 'HELD'
        FOR UPDATE
    ");
    $stmt->execute(['hold_reference' => $holdReference]);
    $accountHold = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($accountHold) {
        // ============================================================
        // ACCOUNT DEBIT PATH
        // FIXED: hold.php's PLACE_HOLD already did
        //   UPDATE accounts SET balance = balance - :amount
        // at hold-placement time. That money is already gone from
        // `balance`. This finalization step must ONLY flip the
        // hold's status to DEBITED — it does not touch `balance`
        // again. The previous version re-ran the same subtraction
        // here, silently charging the account 2x per debit.
        // ============================================================
        error_log("ZURUBANK NOTIFY_DEBIT: Found ACCOUNT hold ID={$accountHold['id']}, account_id={$accountHold['account_id']}");

        if (abs(floatval($accountHold['amount']) - floatval($amount)) > 0.01) {
            throw new Exception("Amount mismatch. Hold: {$accountHold['amount']}, Requested: $amount");
        }

        // Read current balance for the response payload only —
        // no mutation of `balance` here, it was already applied at
        // hold time.
        $stmt = $pdo->prepare("
            SELECT balance FROM accounts WHERE account_id = :account_id
        ");
        $stmt->execute(['account_id' => $accountHold['account_id']]);
        $currentAccount = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentAccount) {
            throw new Exception("Account not found during debit finalization: {$accountHold['account_id']}");
        }

        // Finalize the hold
        $stmt = $pdo->prepare("
            UPDATE financial_holds
            SET status = 'DEBITED', 
                debited_at = NOW(),
                requester = :requester,
                signature_verified = :sig_verified
            WHERE id = :hold_id
        ");
        $stmt->execute([
            'hold_id' => $accountHold['id'],
            'requester' => $requester,
            'sig_verified' => $isValid ? 1 : 0
        ]);

        // Record in audit
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs 
            (entity, entity_id, action, category, severity, 
             old_value, new_value, performed_by, performed_at,
             ip_address, user_agent)
            VALUES 
            ('financial_holds', :entity_id, 'DEBIT', 'financial', 'info',
             :old_value, :new_value, :performed_by, NOW(),
             :ip_address, :user_agent)
        ");
        $stmt->execute([
            'entity_id' => $accountHold['id'],
            'performed_by' => $requester,
            'old_value' => json_encode(['status' => 'HELD', 'amount' => $accountHold['amount']]),
            'new_value' => json_encode(['status' => 'DEBITED', 'amount' => $amount, 'signature_verified' => $isValid]),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent
        ]);

        $pdo->commit();

        error_log("ZURUBANK NOTIFY_DEBIT: ACCOUNT debit finalized (no balance mutation here) - account_id={$accountHold['account_id']}, amount={$amount}, balance={$currentAccount['balance']}");

        $responsePayload = [
            "success" => true,
            "status" => "SUCCESS",
            "debited" => true,
            "message" => "Account hold finalized as debited",
            "hold_reference" => $holdReference,
            "amount" => (float)$amount,
            "account_id" => $accountHold['account_id'],
            "total_balance" => floatval($currentAccount['balance']),
            "available_balance" => floatval($currentAccount['balance']),
            "requester" => $requester,
            "signature_verified" => $isValid,
            "verification_method" => "certificate",
            "timestamp" => time(),
            "data" => [
                "debited" => true,
                "transaction_reference" => $settlementReference,
                "hold_reference" => $holdReference
            ]
        ];

        send_signed_response($responsePayload);
        exit;
    }

    // ============================================================
    // BRANCH 2: VOUCHER DEBIT PATH (unchanged — vouchers never had
    // the balance-column double-decrement issue; this path debits
    // a voucher record, not an accounts.balance column)
    // ============================================================
    error_log("ZURUBANK NOTIFY_DEBIT: No ACCOUNT hold found, trying VOUCHER path");

    // First, find the voucher by source_hold_reference
    $stmt = $pdo->prepare("
        SELECT voucher_id, voucher_number, amount, status, holding_account, created_by,
               external_reference, source_institution, source_hold_reference,
               currency, recipient_phone
        FROM instant_money_vouchers 
        WHERE source_hold_reference = :hold_reference
        FOR UPDATE
    ");
    $stmt->execute(['hold_reference' => $holdReference]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    // If not found, try external_reference
    if (!$voucher) {
        $stmt = $pdo->prepare("
            SELECT voucher_id, voucher_number, amount, status, holding_account, created_by,
                   external_reference, source_institution, source_hold_reference,
                   currency, recipient_phone
            FROM instant_money_vouchers 
            WHERE external_reference = :hold_reference
            FOR UPDATE
        ");
        $stmt->execute(['hold_reference' => $holdReference]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // If still not found, try to find by voucher_number as last resort
    if (!$voucher) {
        $stmt = $pdo->prepare("
            SELECT voucher_id, voucher_number, amount, status, holding_account, created_by,
                   external_reference, source_institution, source_hold_reference,
                   currency, recipient_phone
            FROM instant_money_vouchers 
            WHERE voucher_number = :hold_reference
            FOR UPDATE
        ");
        $stmt->execute(['hold_reference' => $holdReference]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$voucher) {
        // Log all possible matches for debugging
        error_log("ZURUBANK NOTIFY_DEBIT: No voucher found for hold_reference: $holdReference");
        
        // Check what's in the database
        $checkStmt = $pdo->query("
            SELECT voucher_number, source_hold_reference, external_reference, status 
            FROM instant_money_vouchers 
            WHERE status IN ('hold', 'active')
            LIMIT 5
        ");
        $availableVouchers = $checkStmt->fetchAll(PDO::FETCH_ASSOC);
        error_log("ZURUBANK NOTIFY_DEBIT: Available vouchers: " . json_encode($availableVouchers));
        
        throw new Exception("Voucher not found for hold reference: $holdReference");
    }

    error_log("ZURUBANK NOTIFY_DEBIT: Found voucher {$voucher['voucher_number']} for hold reference $holdReference");

    // Check status - allow both 'hold' and 'active' to be debited
    if ($voucher['status'] !== 'hold' && $voucher['status'] !== 'active') {
        throw new Exception("Voucher cannot be debited (status: {$voucher['status']})");
    }

    // Verify amount matches (allow small floating point difference)
    if (abs(floatval($voucher['amount']) - floatval($amount)) > 0.01) {
        throw new Exception("Amount mismatch. Voucher: {$voucher['amount']}, Requested: $amount");
    }

    // ============================================================
    // FIXED: REMOVED redeemed_by_cert_verified column
    // The column does not exist in instant_money_vouchers table
    // Certificate verification is tracked via redeemed_by and audit logs
    // ============================================================
    
    // Mark voucher as redeemed (used)
    $stmt = $pdo->prepare("
        UPDATE instant_money_vouchers 
        SET status = 'redeemed', 
            redeemed_at = NOW(),
            redeemed_by = :requester,
            settlement_reference = :settlement_ref,
            updated_at = NOW()
        WHERE voucher_id = :voucher_id
    ");
    $stmt->execute([
        'requester' => $requester,
        'settlement_ref' => $settlementReference,
        'voucher_id' => $voucher['voucher_id']
    ]);

    // Get or create counterparty bank in swap_linked_banks
    $stmt = $pdo->prepare("
        SELECT id, bank_code FROM swap_linked_banks 
        WHERE bank_code = :bank_code OR bank_name = :bank_name
        LIMIT 1
    ");
    $stmt->execute([
        'bank_code' => $counterpartyBank,
        'bank_name' => $counterpartyBank
    ]);
    $bank = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bank) {
        // Insert the counterparty bank if not exists
        $stmt = $pdo->prepare("
            INSERT INTO swap_linked_banks (bank_code, bank_name, status, created_at)
            VALUES (:bank_code, :bank_name, 'active', NOW())
            RETURNING id, bank_code
        ");
        $stmt->execute([
            'bank_code' => $counterpartyBank,
            'bank_name' => $counterpartyBank
        ]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);
        error_log("ZURUBANK NOTIFY_DEBIT: Created new counterparty bank record for: {$counterpartyBank}");
    }

    // Create a journal entry for this settlement
    $stmt = $pdo->prepare("
        INSERT INTO journals (reference, description, created_by, created_at)
        VALUES (:reference, :description, :created_by, NOW())
        RETURNING journal_id
    ");
    $stmt->execute([
        'reference' => $settlementReference,
        'description' => "Settlement of voucher {$voucher['voucher_number']} used at {$counterpartyBank} (requested by {$requester})",
        'created_by' => $requester
    ]);
    $journalId = $stmt->fetchColumn();

    // Record in swap_ledger
    $holdingAccount = $voucher['holding_account'] ?? 'VOUCHER-SUSPENSE';
    
    $stmt = $pdo->prepare("
        INSERT INTO swap_ledger 
        (reference_id, journal_id, debit_account, credit_account, amount, currency, description, created_at) 
        VALUES (:reference_id, :journal_id, :debit_account, :credit_account, :amount, :currency, :description, NOW())
    ");
    $stmt->execute([
        'reference_id' => $settlementReference,
        'journal_id' => $journalId,
        'debit_account' => $holdingAccount,
        'credit_account' => 'INTERBANK:' . $counterpartyBank,
        'amount' => $amount,
        'currency' => $voucher['currency'] ?? 'BWP',
        'description' => "Voucher {$voucher['voucher_number']} cashed at {$counterpartyBank} (verified by {$requester})"
    ]);

    // Create transaction record
    $stmt = $pdo->prepare("
        INSERT INTO transactions 
        (user_id, account_id, from_account, to_account, type, amount, reference, description, status, 
         requester, signature_verified, verification_method, created_at)
        VALUES 
        (:user_id, :account_id, :from_account, :to_account, :type, :amount, :reference, :description, 'completed', 
         :requester, :sig_verified, :verification_method, NOW())
    ");
    $stmt->execute([
        'user_id' => $voucher['created_by'] ?? 1,
        'account_id' => 0,
        'from_account' => $holdingAccount,
        'to_account' => "BANK:{$counterpartyBank}",
        'type' => 'interbank_settlement',
        'amount' => $amount,
        'reference' => $settlementReference,
        'description' => "Voucher {$voucher['voucher_number']} settlement (authorized by {$requester})",
        'requester' => $requester,
        'sig_verified' => $isValid ? 1 : 0,
        'verification_method' => 'certificate'
    ]);

    // Log in audit with signature info
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (entity, entity_id, action, category, severity, 
         old_value, new_value, performed_by, performed_at,
         ip_address, user_agent)
        VALUES 
        ('instant_money_vouchers', :entity_id, 'DEBIT', 'financial', 'info',
         :old_value, :new_value, :performed_by, NOW(),
         :ip_address, :user_agent)
    ");
    $stmt->execute([
        'entity_id' => $voucher['voucher_id'],
        'performed_by' => $requester,
        'old_value' => json_encode(['status' => $voucher['status'], 'amount' => $voucher['amount']]),
        'new_value' => json_encode(['status' => 'redeemed', 'amount' => $amount, 'signature_verified' => $isValid]),
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent
    ]);

    $pdo->commit();

    error_log("ZURUBANK NOTIFY_DEBIT: VOUCHER debit completed - Voucher: {$voucher['voucher_number']}, Settlement: {$settlementReference}");

    // ============================================================
    // VOUCHER RESPONSE
    // ============================================================
    $responsePayload = [
        "success" => true,
        "status" => "SUCCESS",
        "debited" => true,
        "message" => "Voucher released and interbank settlement recorded",
        "voucher_number" => $voucher['voucher_number'],
        "hold_reference" => $holdReference,
        "amount" => (float)$amount,
        "currency" => $voucher['currency'] ?? 'BWP',
        "counterparty_bank" => $counterpartyBank,
        "settlement_reference" => $settlementReference,
        "journal_id" => $journalId,
        "requester" => $requester,
        "signature_verified" => $isValid,
        "verification_method" => "certificate",
        "timestamp" => time(),
        "data" => [
            "debited" => true,
            "transaction_reference" => $settlementReference,
            "hold_reference" => $holdReference
        ]
    ];
    
    // Send signed response
    send_signed_response($responsePayload);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("ZURUBANK NOTIFY_DEBIT ERROR: " . $e->getMessage());
    error_log("ZURUBANK NOTIFY_DEBIT Trace: " . $e->getTraceAsString());
    error_log("ZURUBANK NOTIFY_DEBIT Input: " . json_encode($input ?? []));
    
    // Return error with timestamp
    echo json_encode([
        "success" => false,
        "status" => "ERROR", 
        "debited" => false,
        "message" => $e->getMessage(),
        "timestamp" => time()
    ]);
}
