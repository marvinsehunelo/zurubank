<?php
/**
 * ZURUBANK Reservation Account Creation
 * POST /api/v1/accounts/reservation/create.php
 *
 * Opens (or returns) an identity's virtual reservation account: one per
 * identity per currency, owned by the bank's VouchMorph Identity Holding
 * customer, like a mobile-number eWallet. See helpers/virtual_accounts.php. Mirrors hold.php / deposit/direct.php:
 * certificate-based request verification, flat JSON response (no "data"
 * wrapper), signed via send_signed_response().
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../helpers/crypto.php';
require_once __DIR__ . '/../../../../helpers/CertificateManager.php';
require_once __DIR__ . '/../../../../helpers/virtual_accounts.php';

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

// ============================================================
// Identity virtual account (VouchMorph identity reservation account).
// Input: bank_reference (idempotency key), currency, and the identity
// (identity_type + identity_value). A legacy request carrying only a
// VouchMorph user_id is keyed as identity "vouchmorph_user".
// One virtual account per identity per currency: a repeat request for the
// same identity returns the same account.
// ============================================================
$bankReference = $input['bank_reference'] ?? $input['reference'] ?? null;
$reference = $input['reference'] ?? $bankReference;
$currency = strtoupper($input['currency'] ?? 'BWP');
$identityType = $input['identity_type'] ?? null;
$identityValue = $input['identity_value'] ?? null;
if ((!$identityType || !$identityValue) && !empty($input['user_id'])) {
    $identityType = 'vouchmorph_user';
    $identityValue = (string)$input['user_id'];
}
if (!$bankReference || !$identityType || $identityValue === null || $identityValue === '') {
    echo json_encode(['success' => false, 'message' => 'bank_reference and identity_type + identity_value are required']);
    exit;
}

try {
    va_ensure_schema($pdo);

    $st = $pdo->prepare("SELECT * FROM reservation_accounts WHERE bank_reference = ? LIMIT 1");
    $st->execute([$bankReference]);
    if ($existing = $st->fetch(PDO::FETCH_ASSOC)) {
        send_signed_response([
            'success' => true, 'status' => $existing['status'] ?? 'active',
            'account_identifier' => $existing['account_number'] ?? $existing['account_identifier'],
            'account_identifier_type' => 'account_number', 'virtual_account' => true,
            'message' => 'Already processed for this bank_reference',
        ]);
        exit;
    }

    $va = va_open_or_get($pdo, (string)$identityType, (string)$identityValue, $currency, (string)($requester ?? 'VOUCHMORPH'));

    $pdo->prepare("
        INSERT INTO reservation_accounts (bank_reference, reference, user_id, identity_type, identity_value, account_id, account_number,
                                          account_identifier, account_identifier_type, currency, status, requester, signature_verified)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'account_number', ?, 'active', ?, ?)
        ON CONFLICT (bank_reference) DO NOTHING
    ")->execute([
        $bankReference, $reference, is_numeric($input['user_id'] ?? null) ? (int)$input['user_id'] : null,
        strtolower((string)$identityType), (string)$identityValue, $va['account_id'], $va['account_number'], $va['account_number'],
        $currency, (string)($requester ?? 'VOUCHMORPH'), !empty($isValid) ? 'true' : 'false',
    ]);

    error_log("RESERVATION CREATE: identity {$identityType}={$identityValue} {$currency} -> virtual account {$va['account_number']} (" . ($va['opened'] ? 'opened' : 'existing') . ")");
    send_signed_response([
        'success' => true,
        'status' => 'active',
        'account_identifier' => $va['account_number'],
        'account_identifier_type' => 'account_number',
        'virtual_account' => true,
        'opened' => $va['opened'],
        'message' => $va['opened'] ? 'Identity virtual account opened' : 'Identity virtual account already open',
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('RESERVATION CREATE ERROR: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
