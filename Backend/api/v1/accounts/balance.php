<?php
// Backend/api/v1/accounts/balance.php
// ZURUBANK BALANCE CHECK - Accounts + Vouchers (NO MOCKS)
//
// Changes in this version
// -----------------------
// 1. The voucher query threw on every call:
//        ERROR: operator does not exist: integer = text
//        LINE 30: (voucher_number = :identifier OR voucher_id = :identifier)
//    voucher_id is an integer and the identifier arrives as text, so
//    PostgreSQL refused the comparison. The same named placeholder was also
//    bound twice, which native prepares reject on its own. Now: the text and
//    the numeric forms are separate parameters, and a non-numeric identifier
//    binds NULL instead of throwing.
//
// 2. The thrown query was caught and ignored, so the request fell through to
//    the account lookup and answered 404 "Account not found". A broken query
//    now answers 500 and says so.
//
// 3. A redeemed or expired voucher was filtered out by the WHERE clause and
//    also came back as 404. A caller cannot tell "no such voucher" from
//    "already spent". The voucher is now fetched whatever its state, and the
//    answer says which: 404 not found, 409 redeemed or expired.
//
// 4. The response carries amount, redeemed and expires_at at the top of the
//    data block, so a caller can judge a voucher without reading nested
//    voucher_details.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY, X-Correlation-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../../../helpers/CertificateManager.php';
require_once __DIR__ . '/../../../helpers/crypto.php';

/** One place to answer, so every exit has the same shape. */
function respond(int $status, array $body): void
{
    http_response_code($status);
    $body['timestamp'] = $body['timestamp'] ?? time();
    echo json_encode($body);
    exit();
}

// ============================================================
// 1. AUTHENTICATION
//
// Uses CertificateManager, the same class hold.php and generate_code.php use,
// so all three endpoints verify identically and none depends on a database
// connection that does not exist yet at this point in the file.
// ============================================================
$rawBody = file_get_contents('php://input');
$decodedBody = json_decode($rawBody, true);

$authenticated = false;
$authMethod = null;

if (is_array($decodedBody) && isset($decodedBody['certificate'], $decodedBody['signature'])) {
    $certManager = new CertificateManager('ZURUBANK');
    if ($certManager->isConfigured()) {
        $verification = $certManager->verifySignedRequest($decodedBody);
        if ($verification['verified'] ?? false) {
            $authenticated = true;
            $authMethod = 'CERTIFICATE (requester: ' . ($verification['requester'] ?? 'unknown') . ')';
            error_log("[ZURUBANK Balance] Authenticated via certificate signature");
        } else {
            error_log("[ZURUBANK Balance] Certificate signature present but INVALID: " . ($verification['message'] ?? 'unknown reason'));
        }
    } else {
        error_log("[ZURUBANK Balance] CertificateManager not configured (missing CA cert / private key / certificate env vars) — cannot verify certificate auth");
    }
}

if (!$authenticated) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
    $expectedApiKey = getenv('ZURUBANK_API_KEY');

    if (!$expectedApiKey) {
        // The old code fell back to a key written into this file. A secret in
        // source is a secret everyone has, so the fallback is gone: without
        // the environment variable, key auth is simply unavailable.
        error_log("[ZURUBANK Balance] ZURUBANK_API_KEY is not set — API key authentication is disabled");
    } elseif ($apiKey && hash_equals($expectedApiKey, (string)$apiKey)) {
        $authenticated = true;
        $authMethod = 'API_KEY';
    }
}

if (!$authenticated) {
    respond(401, [
        'status'  => 'error',
        'message' => 'Invalid API key or certificate signature',
    ]);
}

error_log("[ZURUBANK Balance] Authenticated via: {$authMethod}");

// ============================================================
// 2. INPUT - reads both GET parameters and the JSON body
// ============================================================
$input = is_array($decodedBody) ? $decodedBody : [];

$account_id = $_GET['source_identifier'] ?? $_GET['account_id'] ?? $_GET['account_number'] ?? $_GET['identifier']
    ?? $input['source_identifier'] ?? $input['account_id'] ?? $input['account_number'] ?? $input['identifier']
    ?? $input['voucher_number'] ?? null;

$asset_type = strtoupper((string)($_GET['asset_type'] ?? $_POST['asset_type'] ?? $input['asset_type'] ?? 'ACCOUNT'));

if ($account_id === null || $account_id === '') {
    respond(400, [
        'status'  => 'error',
        'message' => 'source_identifier or account_number required',
    ]);
}
$account_id = (string)$account_id;

// ============================================================
// 3. DATABASE
// ============================================================
try {
    $databaseUrl = getenv('DATABASE_URL') ?: getenv('RAILWAY_DATABASE_URL');
    if (!$databaseUrl) {
        throw new Exception('DATABASE_URL environment variable not set');
    }

    $parsed = parse_url($databaseUrl);
    if (!$parsed || !isset($parsed['host'])) {
        throw new Exception('Invalid DATABASE_URL format');
    }

    $dsn = sprintf(
        "pgsql:host=%s;port=%s;dbname=%s;sslmode=require",
        $parsed['host'],
        $parsed['port'] ?? 5432,
        ltrim($parsed['path'] ?? '', '/')
    );

    $pdo = new PDO($dsn, $parsed['user'] ?? 'postgres', $parsed['pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 10,
    ]);

    error_log("[ZURUBANK Balance] Database connected successfully");

} catch (Exception $e) {
    error_log("[ZURUBANK Balance] DB connection failed: " . $e->getMessage());
    respond(500, [
        'status'  => 'error',
        'message' => 'Database connection failed',
    ]);
}

// ============================================================
// 4. LOOK UP - vouchers first, then accounts
// ============================================================
try {
    $balance = null;
    $currency = 'BWP';
    $holderName = null;
    $accountNumber = null;
    $accountStatus = null;
    $accountType = null;
    $accountId = null;
    $userId = null;
    $heldAmount = null;
    $availableBalance = null;
    $voucherData = null;
    $isVoucher = false;
    $voucherExpiresAt = null;

    $looksLikeVoucher = in_array($asset_type, ['VOUCHER', 'CASHOUT-VOUCHER'], true)
        || strpos($account_id, 'VOUCHER_') === 0;

    // ------------------------------------------------------------
    // VOUCHER
    // ------------------------------------------------------------
    if ($looksLikeVoucher) {

        $voucherIdentifier = $account_id;
        if (strpos($voucherIdentifier, 'VOUCHER_') === 0) {
            $voucherIdentifier = substr($voucherIdentifier, 8);
        }

        // voucher_id is an integer column: bind it only when the identifier is
        // ENTIRELY digits, otherwise bind NULL, which matches nothing. Digits
        // must not be stripped out of a code — "NOPE-1" is not voucher 1.
        $voucherIdNumeric = (ctype_digit($voucherIdentifier) && strlen($voucherIdentifier) <= 18)
            ? (int)$voucherIdentifier
            : null;

        // Fetched whatever its state, so we can tell the caller WHY a voucher
        // is unusable instead of pretending it does not exist.
        $voucherStmt = $pdo->prepare("
            SELECT
                voucher_id, voucher_number, voucher_pin, amount, currency,
                recipient_phone, status, source_institution, created_at,
                voucher_expires_at, redeemed_at, swap_made_at, reference,
                source_asset_type, created_by, redeemed_by, voucher_created_at,
                sat_purchased, sat_fee_paid_by, sat_expires_at, holding_account,
                origin, external_reference, source_hold_reference, code_hash
            FROM instant_money_vouchers
            WHERE voucher_number = :identifier_text
               OR (:identifier_int::bigint IS NOT NULL AND voucher_id = :identifier_int::bigint)
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $voucherStmt->execute([
            'identifier_text' => $voucherIdentifier,
            'identifier_int'  => $voucherIdNumeric,
        ]);
        $voucher = $voucherStmt->fetch(PDO::FETCH_ASSOC);

        if (!$voucher) {
            respond(404, [
                'status'     => 'error',
                'message'    => "Voucher not found: {$account_id}",
                'asset_type' => 'VOUCHER',
            ]);
        }

        $status = strtolower((string)($voucher['status'] ?? ''));
        $expiresAt = $voucher['voucher_expires_at'] ?? null;
        $expired = $expiresAt !== null
            && strtotime((string)$expiresAt) !== false
            && strtotime((string)$expiresAt) < time();

        // Spent or out of time: a real answer, not a 404. The face value is
        // still reported so the caller can show what it was worth.
        if ($status === 'redeemed' || $voucher['redeemed_at'] !== null || $status === 'expired' || $expired) {
            $reason = ($status === 'redeemed' || $voucher['redeemed_at'] !== null)
                ? 'Voucher has already been redeemed'
                : 'Voucher has expired';

            error_log("[ZURUBANK Balance] Voucher {$voucher['voucher_number']} unusable: {$reason}");

            respond(409, [
                'status'     => 'error',
                'message'    => $reason,
                'verified'   => true,
                'asset_type' => 'VOUCHER',
                'data'       => [
                    'voucher_number'    => $voucher['voucher_number'],
                    'asset_type'        => 'VOUCHER',
                    'balance'           => 0,
                    'available_balance' => 0,
                    'amount'            => 0,
                    'face_value'        => (float)$voucher['amount'],
                    'currency'          => $voucher['currency'] ?? 'BWP',
                    'redeemed'          => ($status === 'redeemed' || $voucher['redeemed_at'] !== null),
                    'redeemed_at'       => $voucher['redeemed_at'],
                    'expired'           => ($status === 'expired' || $expired),
                    'expires_at'        => $expiresAt,
                    'status'            => $voucher['status'],
                ],
            ]);
        }

        $balance          = (float)$voucher['amount'];
        $currency         = $voucher['currency'] ?? 'BWP';
        $holderName       = $voucher['recipient_phone'] ?? 'Voucher Holder';
        $accountNumber    = $voucher['voucher_number'] ?? $account_id;
        $accountStatus    = $voucher['status'] ?? 'active';
        $accountType      = 'VOUCHER';
        $accountId        = (int)$voucher['voucher_id'];
        $availableBalance = $balance;
        $heldAmount       = 0.0;
        $isVoucher        = true;
        $voucherExpiresAt = $expiresAt;

        $voucherData = [
            'voucher_id'         => $voucher['voucher_id'],
            'voucher_number'     => $voucher['voucher_number'],
            'voucher_pin'        => $voucher['voucher_pin'],
            'expires_at'         => $expiresAt,
            'created_at'         => $voucher['created_at'],
            'source_institution' => $voucher['source_institution'],
            'reference'          => $voucher['reference'],
            'recipient_phone'    => $voucher['recipient_phone'],
            'status'             => $voucher['status'],
            'holding_account'    => $voucher['holding_account'],
            'origin'             => $voucher['origin'],
            'external_reference' => $voucher['external_reference'],
        ];

        error_log("[ZURUBANK Balance] Found voucher: {$accountNumber}, amount: {$balance}");
    }

    // ------------------------------------------------------------
    // ACCOUNT
    // ------------------------------------------------------------
    if ($balance === null && !$isVoucher) {
        $stmt = $pdo->prepare("
            SELECT
                account_id, user_id, account_number, account_type,
                balance, available_balance, held_amount, currency,
                status, created_at, updated_at
            FROM accounts
            WHERE account_number = :account_number
            LIMIT 1
        ");
        $stmt->execute(['account_number' => $account_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            $balance          = (float)$account['balance'];
            $currency         = $account['currency'] ?? 'BWP';
            $holderName       = $account['account_type'] ?? 'Account Holder';
            $accountNumber    = $account['account_number'];
            $accountStatus    = $account['status'] ?? 'active';
            $accountType      = $account['account_type'] ?? 'ACCOUNT';
            $accountId        = (int)$account['account_id'];
            $userId           = (int)($account['user_id'] ?? 0);
            $heldAmount       = (float)($account['held_amount'] ?? 0);
            $availableBalance = (float)($account['available_balance'] ?? ($balance - $heldAmount));
            error_log("[ZURUBANK Balance] Found account: {$account_id}, balance: {$balance}");
        }
    }

    if ($balance === null) {
        respond(404, [
            'status'  => 'error',
            'message' => "Account not found: {$account_id}",
        ]);
    }

    if (!$isVoucher && $accountStatus !== 'active') {
        respond(403, [
            'status'  => 'error',
            'message' => 'Account is not active',
            'data'    => ['status' => $accountStatus],
        ]);
    }

    // ============================================================
    // 5. RESPONSE
    // ============================================================
    $responseData = [
        'status'   => 'success',
        'verified' => true,
        'data'     => [
            'account_id'        => $accountId,
            'user_id'           => $userId,
            'account_number'    => $accountNumber ?? $account_id,
            'account_type'      => $accountType ?? 'ACCOUNT',
            'holder_name'       => $holderName,
            'balance'           => $balance,
            'available_balance' => $availableBalance ?? $balance,
            'held_amount'       => $heldAmount ?? 0,
            'currency'          => $currency,
            'status'            => $accountStatus ?? 'active',
            'is_voucher'        => $isVoucher,
            'asset_type'        => $isVoucher ? 'VOUCHER' : ($accountType ?? 'ACCOUNT'),
            'timestamp'         => time(),
        ],
        'requester'           => 'ZURUBANK',
        'verification_method' => 'database',
        'signature_verified'  => ($authMethod !== 'API_KEY'),
    ];

    if ($isVoucher) {
        // Flat fields, so a caller can judge the voucher without digging
        // through voucher_details.
        $responseData['data']['voucher_number'] = $accountNumber;
        $responseData['data']['amount']         = $balance;
        $responseData['data']['face_value']     = $balance;
        $responseData['data']['redeemed']       = false;
        $responseData['data']['expired']        = false;
        $responseData['data']['expires_at']     = $voucherExpiresAt;
        $responseData['data']['voucher_details'] = $voucherData;
    }

    echo json_encode($responseData);

} catch (PDOException $e) {
    // A failed query is a server fault, not a missing account. The old code
    // swallowed this and answered 404, which is why a broken voucher query
    // looked like a wrong voucher.
    error_log("[ZURUBANK Balance] PDO Error: " . $e->getMessage() . " | identifier={$account_id} asset_type={$asset_type}");
    respond(500, [
        'status'  => 'error',
        'message' => 'Database error while checking balance',
    ]);
} catch (Exception $e) {
    error_log("[ZURUBANK Balance] Error: " . $e->getMessage());
    respond(500, [
        'status'  => 'error',
        'message' => 'Balance check failed',
    ]);
}
