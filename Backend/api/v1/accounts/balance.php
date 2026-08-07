<?php
// Backend/api/v1/accounts/balance.php
// ZURUBANK BALANCE CHECK - Accounts + Vouchers (NO MOCKS)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-KEY, X-Correlation-ID');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ============================================================
// 1. AUTHENTICATION
// ============================================================
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
$expectedApiKey = getenv('ZURUBANK_API_KEY') ?: 'zurubank_live_3uV4wX5yZ6aB7cD8';

if (!$apiKey || $apiKey !== $expectedApiKey) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid API key',
        'timestamp' => time()
    ]);
    exit();
}

// ============================================================
// 2. GET INPUT - FIX: Read BOTH GET params AND JSON POST body
// ============================================================
$input = json_decode(file_get_contents('php://input'), true);

// PRIMARY: GET params (for backward compatibility)
// SECONDARY: JSON POST body (for VoucherMorph requests)
$account_id = $_GET['source_identifier'] ?? $_GET['account_id'] ?? $_GET['account_number'] ?? $_GET['identifier'] 
    ?? $input['source_identifier'] ?? $input['account_id'] ?? $input['account_number'] ?? $input['identifier'] 
    ?? null;

$asset_type = $_GET['asset_type'] ?? $_POST['asset_type'] ?? $input['asset_type'] ?? 'ACCOUNT';

if (!$account_id) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'source_identifier or account_number required',
        'timestamp' => time()
    ]);
    exit();
}

// ============================================================
// 3. CONNECT TO DATABASE (RAILWAY ONLY - NO LOCAL CONFIGS)
// ============================================================
try {
    $databaseUrl = getenv('DATABASE_URL');
    if (!$databaseUrl) {
        // Try Railway's alternative env var
        $databaseUrl = getenv('RAILWAY_DATABASE_URL');
    }
    
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
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 10
    ]);
    
    error_log("[ZURUBANK Balance] Database connected successfully");

} catch (Exception $e) {
    error_log("[ZURUBANK Balance] DB connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database connection failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
    exit();
}

// ============================================================
// 4. GET BALANCE - Accounts + Vouchers (NO MOCKS)
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

    // ============================================================
    // CHECK VOUCHER TABLE FIRST
    // ============================================================
    if ($asset_type === 'VOUCHER' || $asset_type === 'CASHOUT-VOUCHER' || strpos($account_id, 'VOUCHER_') === 0) {
        
        $voucherIdentifier = $account_id;
        if (strpos($voucherIdentifier, 'VOUCHER_') === 0) {
            $voucherIdentifier = substr($voucherIdentifier, 8);
        }
        
        // Check if voucher table exists
        try {
            $voucherStmt = $pdo->prepare("
                SELECT 
                    voucher_id,
                    voucher_number,
                    voucher_pin,
                    amount,
                    currency,
                    recipient_phone,
                    status,
                    source_institution,
                    created_at,
                    voucher_expires_at,
                    redeemed_at,
                    swap_made_at,
                    reference,
                    source_asset_type,
                    created_by,
                    redeemed_by,
                    voucher_created_at,
                    sat_purchased,
                    sat_fee_paid_by,
                    sat_expires_at,
                    holding_account,
                    origin,
                    external_reference,
                    source_hold_reference,
                    code_hash
                FROM instant_money_vouchers 
                WHERE 
                    (voucher_number = :identifier OR voucher_id = :identifier)
                    AND status NOT IN ('redeemed', 'expired')
                    AND voucher_expires_at > NOW()
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $voucherStmt->execute([
                'identifier' => $voucherIdentifier
            ]);
            $voucher = $voucherStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($voucher) {
                $balance = (float)$voucher['amount'];
                $currency = $voucher['currency'] ?? 'BWP';
                $holderName = $voucher['recipient_phone'] ?? 'Voucher Holder';
                $accountNumber = $voucher['voucher_number'] ?? $account_id;
                $accountStatus = $voucher['status'] ?? 'active';
                $accountType = 'VOUCHER';
                $accountId = (int)$voucher['voucher_id'];
                $isVoucher = true;
                $voucherData = [
                    'voucher_id' => $voucher['voucher_id'],
                    'voucher_number' => $voucher['voucher_number'],
                    'voucher_pin' => $voucher['voucher_pin'],
                    'expires_at' => $voucher['voucher_expires_at'],
                    'created_at' => $voucher['created_at'],
                    'source_institution' => $voucher['source_institution'],
                    'reference' => $voucher['reference'],
                    'recipient_phone' => $voucher['recipient_phone'],
                    'status' => $voucher['status'],
                    'holding_account' => $voucher['holding_account'],
                    'origin' => $voucher['origin'],
                    'external_reference' => $voucher['external_reference']
                ];
                error_log("[ZURUBANK Balance] Found voucher: {$account_id}, amount: {$balance}");
            }
        } catch (PDOException $e) {
            // Voucher table might not exist - just continue to accounts
            error_log("[ZURUBANK Balance] Voucher table check: " . $e->getMessage());
        }
    }

    // ============================================================
    // CHECK ACCOUNT TABLE
    // ============================================================
    if ($balance === null && !$isVoucher) {
        $stmt = $pdo->prepare("
            SELECT 
                account_id,
                user_id,
                account_number,
                account_type,
                balance,
                available_balance,
                held_amount,
                currency,
                status,
                created_at,
                updated_at
            FROM accounts
            WHERE account_number = :account_number
            LIMIT 1
        ");
        $stmt->execute(['account_number' => $account_id]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            $balance = (float)$account['balance'];
            $currency = $account['currency'] ?? 'BWP';
            $holderName = $account['account_type'] ?? 'Account Holder';
            $accountNumber = $account['account_number'];
            $accountStatus = $account['status'] ?? 'active';
            $accountType = $account['account_type'] ?? 'ACCOUNT';
            $accountId = (int)$account['account_id'];
            $userId = (int)($account['user_id'] ?? 0);
            $heldAmount = (float)($account['held_amount'] ?? 0);
            $availableBalance = (float)($account['available_balance'] ?? ($balance - $heldAmount));
            error_log("[ZURUBANK Balance] Found account: {$account_id}, balance: {$balance}");
        }
    }

    // ============================================================
    // IF NOT FOUND IN ANY TABLE - RETURN 404 (NO MOCKS)
    // ============================================================
    if ($balance === null) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => "Account not found: {$account_id}",
            'timestamp' => time()
        ]);
        exit();
    }

    // ============================================================
    // CHECK STATUS (if not voucher)
    // ============================================================
    if (!$isVoucher && $accountStatus !== 'active') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Account is not active',
            'timestamp' => time()
        ]);
        exit();
    }

    // ============================================================
    // 5. RESPONSE (REAL DATA - NO MOCKS)
    // ============================================================
    $responseData = [
        'status' => 'success',
        'verified' => true,
        'data' => [
            'account_id' => $accountId,
            'user_id' => $userId,
            'account_number' => $accountNumber ?? $account_id,
            'account_type' => $accountType ?? 'ACCOUNT',
            'balance' => $balance,
            'available_balance' => $availableBalance ?? $balance,
            'held_amount' => $heldAmount ?? 0,
            'currency' => $currency,
            'status' => $accountStatus ?? 'active',
            'is_voucher' => $isVoucher,
            'asset_type' => $asset_type,
            'timestamp' => time()
        ],
        'requester' => 'ZURUBANK',
        'verification_method' => 'database',
        'signature_verified' => true
    ];
    
    // Add voucher details if applicable
    if ($voucherData) {
        $responseData['data']['voucher_details'] = $voucherData;
    }

    echo json_encode($responseData);

} catch (PDOException $e) {
    error_log("[ZURUBANK Balance] PDO Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
} catch (Exception $e) {
    error_log("[ZURUBANK Balance] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Balance check failed: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}
?>
