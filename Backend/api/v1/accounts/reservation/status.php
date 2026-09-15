<?php
/**
 * ZURUBANK Reservation Account Status
 * POST /api/v1/accounts/reservation/status.php
 *
 * Looks up a reservation account previously created via
 * accounts/reservation/create.php, keyed by bank_reference.
 *
 * NOTE ON AUTH: VouchMorph's GET_RESERVATION_ACCOUNT_STATUS payload
 * carries no certificate/signature (unlike create.php's request). This
 * mirrors accounts/balance.php's convention instead: certificate-based
 * verification when a certificate+signature pair is present, otherwise
 * fall back to the shared X-API-KEY header.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY, X-Correlation-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../helpers/crypto.php';
require_once __DIR__ . '/../../../../helpers/CertificateManager.php';

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);

error_log("=== ZURUBANK reservation/status.php CALLED ===");
error_log("Input: " . json_encode($input));

// ============================================================
// 1. AUTHENTICATION
// ============================================================
$authenticated = false;

if (is_array($input) && isset($input['certificate'], $input['signature'])) {
    $certManager = new CertificateManager('ZURUBANK');
    if ($certManager->isConfigured()) {
        $verification = $certManager->verifySignedRequest($input);
        if ($verification['verified'] ?? false) {
            $authenticated = true;
            error_log("[ZURUBANK Reservation Status] Authenticated via certificate signature");
        } else {
            error_log("[ZURUBANK Reservation Status] Certificate signature present but INVALID: " . ($verification['message'] ?? 'unknown reason'));
        }
    }
}

if (!$authenticated) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
    $expectedApiKey = getenv('ZURUBANK_API_KEY') ?: 'zurubank_live_3uV4wX5yZ6aB7cD8';

    if ($apiKey && $apiKey === $expectedApiKey) {
        $authenticated = true;
    }
}

if (!$authenticated) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid API key or certificate signature'
    ]);
    exit();
}

// ============================================================
// 2. GET INPUT
// ============================================================
$bankReference = $input['bank_reference'] ?? $_GET['bank_reference'] ?? null;

if (!$bankReference) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'bank_reference is required'
    ]);
    exit();
}

// ============================================================
// 3. LOOK UP RESERVATION ACCOUNT
// ============================================================
try {
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

    $stmt = $pdo->prepare("SELECT * FROM reservation_accounts WHERE bank_reference = :bank_reference LIMIT 1");
    $stmt->execute(['bank_reference' => $bankReference]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => "Reservation account not found for bank_reference: {$bankReference}"
        ]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'status' => $reservation['status'],
        'account_identifier' => $reservation['account_number'],
        'account_identifier_type' => 'account_number',
        'message' => 'Reservation account status retrieved'
    ]);

} catch (Exception $e) {
    error_log("ZURUBANK RESERVATION STATUS ERROR: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
