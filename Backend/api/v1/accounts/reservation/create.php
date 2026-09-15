<?php
/**
 * ZURUBANK Reservation Account Creation
 * POST /api/v1/accounts/reservation/create.php
 *
 * Opens a real, dedicated account for a VouchMorph beneficiary who has
 * received funds before linking a bank account (replaces the old shared
 * pooled-account fallback). Mirrors hold.php / deposit/direct.php:
 * certificate-based request verification, flat JSON response (no "data"
 * wrapper), signed via send_signed_response().
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../helpers/crypto.php';
require_once __DIR__ . '/../../../../helpers/CertificateManager.php';

$input = json_decode(file_get_contents('php://input'), true);

error_log("=== ZURUBANK reservation/create.php CALLED ===");
error_log("Input: " . json_encode($input));

// ============================================================
// CERTIFICATE-BASED VERIFICATION (REQUIRED) — same convention as
// hold.php and deposit/direct.php.
// ============================================================
if (!isset($input['certificate'])) {
    error_log("ZURUBANK RESERVATION CREATE: No certificate provided");
    echo json_encode([
        'success' => false,
        'message' => 'Certificate required - please upgrade to certificate-based authentication'
    ]);
    exit;
}

$certManager = new CertificateManager('ZURUBANK');
$verification = $certManager->verifySignedRequest($input);
$isValid = $verification['verified'];
$requester = $verification['requester'];

error_log("ZURUBANK RESERVATION CREATE: Certificate verification: " . ($isValid ? "VALID ✓" : "INVALID ✗"));

if (!$isValid) {
    error_log("ZURUBANK RESERVATION CREATE: Certificate verification failed");
    echo json_encode([
        'success' => false,
        'message' => 'Certificate verification failed: ' . ($verification['message'] ?? 'Unknown error')
    ]);
    exit;
}

error_log("ZURUBANK RESERVATION CREATE: Request verified from {$requester} using certificate");

$bankReference = $input['bank_reference'] ?? $input['reference'] ?? null;
$reference = $input['reference'] ?? $bankReference;
$userId = $input['user_id'] ?? null;
$currency = $input['currency'] ?? 'BWP';

if (!$bankReference) {
    echo json_encode([
        'success' => false,
        'message' => 'bank_reference is required'
    ]);
    exit;
}

if (!$userId) {
    echo json_encode([
        'success' => false,
        'message' => 'user_id is required'
    ]);
    exit;
}

try {
    if (!isset($pdo)) {
        throw new Exception("Database connection failed to initialize.");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reservation_accounts (
            id SERIAL PRIMARY KEY,
            bank_reference VARCHAR(150) UNIQUE NOT NULL,
            reference VARCHAR(150),
            user_id INTEGER,
            account_id INTEGER,
            account_number VARCHAR(255),
            currency VARCHAR(10) DEFAULT 'BWP',
            status VARCHAR(30) DEFAULT 'active',
            requester VARCHAR(100),
            signature_verified BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT NOW(),
            updated_at TIMESTAMP DEFAULT NOW()
        )
    ");

    // ============================================================
    // IDEMPOTENCY: VouchMorph retries with the SAME bank_reference after
    // a timeout or ambiguous response. Look it up first and return the
    // existing account rather than creating a second one.
    // ============================================================
    $stmt = $pdo->prepare("SELECT * FROM reservation_accounts WHERE bank_reference = :bank_reference LIMIT 1");
    $stmt->execute(['bank_reference' => $bankReference]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        error_log("ZURUBANK RESERVATION CREATE: Duplicate bank_reference ({$bankReference}), returning existing account: {$existing['account_number']}");
        send_signed_response([
            'success' => true,
            'status' => $existing['status'],
            'account_identifier' => $existing['account_number'],
            'account_identifier_type' => 'account_number',
            'message' => 'Reservation account already exists for this bank_reference'
        ]);
        exit;
    }

    $pdo->beginTransaction();

    // Generate a unique account number (same generation approach as
    // controllers/accounts.php::createAccount(), plus a uniqueness check).
    do {
        $accountNumber = strval(mt_rand(1000000000, 9999999999));
        $checkStmt = $pdo->prepare("SELECT 1 FROM accounts WHERE account_number = :account_number LIMIT 1");
        $checkStmt->execute(['account_number' => $accountNumber]);
    } while ($checkStmt->fetchColumn());

    $stmt = $pdo->prepare("
        INSERT INTO accounts (user_id, account_number, account_type, balance, currency, status)
        VALUES (:user_id, :account_number, 'reservation', 0, :currency, 'active')
        RETURNING account_id
    ");
    $stmt->execute([
        'user_id' => $userId,
        'account_number' => $accountNumber,
        'currency' => $currency
    ]);
    $accountId = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        INSERT INTO reservation_accounts
            (bank_reference, reference, user_id, account_id, account_number, currency, status, requester, signature_verified)
        VALUES
            (:bank_reference, :reference, :user_id, :account_id, :account_number, :currency, 'active', :requester, :signature_verified)
    ");
    $stmt->execute([
        'bank_reference' => $bankReference,
        'reference' => $reference,
        'user_id' => $userId,
        'account_id' => $accountId,
        'account_number' => $accountNumber,
        'currency' => $currency,
        'requester' => $requester,
        'signature_verified' => $isValid ? 1 : 0
    ]);

    $pdo->commit();

    error_log("ZURUBANK RESERVATION CREATE: Created account {$accountNumber} for user {$userId}, bank_reference={$bankReference}");

    send_signed_response([
        'success' => true,
        'status' => 'active',
        'account_identifier' => $accountNumber,
        'account_identifier_type' => 'account_number',
        'message' => 'Reservation account created'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("ZURUBANK RESERVATION CREATE ERROR: " . $e->getMessage());
    error_log("Trace: " . $e->getTraceAsString());

    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
