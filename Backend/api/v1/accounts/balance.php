<?php
// Backend/api/v1/accounts/balance.php
// Get account balance (supports JWT for users OR certificate for institution-to-institution)
// NOW ALSO CHECKS VOUCHER BALANCE

require_once '../../../../config/db.php';
require_once '../../../../config/jwt.php';
require_once '../../../../helpers/crypto.php';
require_once '../../../../helpers/CertificateManager.php';

header('Content-Type: application/json');

// Determine authentication method
$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $auth_header);

// Check for certificate (institution-to-institution communication)
$input = json_decode(file_get_contents('php://input'), true);
$certificate = $input['certificate'] ?? $_SERVER['HTTP_X_CERTIFICATE'] ?? null;

$isCertValid = false;
$requester = null;
$verificationMethod = null;
$userId = null;

// ============================================================
// METHOD 1: Certificate-based verification (Preferred for inter-bank)
// ============================================================
if ($certificate) {
    $certManager = new CertificateManager('ZURUBANK');
    
    // Create verification request
    $verifyRequest = [
        'certificate' => $certificate,
        'signature' => $input['signature'] ?? $_SERVER['HTTP_X_SIGNATURE'] ?? null,
        'requester' => $input['requester'] ?? $_SERVER['HTTP_X_REQUESTER'] ?? 'UNKNOWN',
        'timestamp' => $input['timestamp'] ?? time(),
        'account_id' => $_GET['account_id'] ?? $_POST['account_id'] ?? $input['account_id'] ?? ''
    ];
    
    $verification = $certManager->verifySignedRequest($verifyRequest);
    $isCertValid = $verification['verified'];
    $requester = $verification['requester'];
    $verificationMethod = 'certificate';
    
    if ($isCertValid) {
        error_log("ZURUBANK BALANCE: Certificate verified from {$requester}");
    } else {
        error_log("ZURUBANK BALANCE: Certificate verification failed from {$requester}");
    }
}
// ============================================================
// METHOD 2: JWT Authentication (for user-facing access)
// ============================================================
elseif ($token) {
    $jwtPayload = verifyJWT($token);
    if ($jwtPayload) {
        $userId = $jwtPayload['user_id'];
        $requester = $jwtPayload['username'] ?? 'USER_' . $userId;
        $verificationMethod = 'jwt';
        $isCertValid = true;
        error_log("ZURUBANK BALANCE: JWT authenticated for user {$userId}");
    } else {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'message' => 'Unauthorized - valid token or certificate required',
            'timestamp' => time()
        ]);
        exit;
    }
}
// ============================================================
// METHOD 3: No authentication provided
// ============================================================
else {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Authentication required - provide JWT token or certificate',
        'timestamp' => time()
    ]);
    exit;
}

// Get identifier from request
$account_id = $_GET['account_id'] ?? $_POST['account_id'] ?? $input['account_id'] ?? $input['source_identifier'] ?? $input['identifier'] ?? '';
$asset_type = $_GET['asset_type'] ?? $_POST['asset_type'] ?? $input['asset_type'] ?? 'ACCOUNT';
$pin = $input['pin'] ?? $input['wallet_pin'] ?? null;

if (empty($account_id)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'account_id or source_identifier required',
        'timestamp' => time()
    ]);
    exit;
}

try {
    $balance = 0;
    $currency = 'BWP';
    $holderName = null;
    $accountNumber = null;
    $accountStatus = 'active';
    $voucherData = null;
    
    // ============================================================
    // CHECK VOUCHER TABLE FIRST (for voucher asset types)
    // ============================================================
    if ($asset_type === 'VOUCHER' || $asset_type === 'CASHOUT-VOUCHER' || strpos($account_id, 'VOUCHER_') === 0) {
        
        // Clean the identifier - if it's a voucher number or voucher_id
        $voucherIdentifier = $account_id;
        if (strpos($voucherIdentifier, 'VOUCHER_') === 0) {
            $voucherIdentifier = substr($voucherIdentifier, 8);
        }
        
        // Query instant_money_vouchers table
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
                created_by
            FROM instant_money_vouchers 
            WHERE 
                (voucher_number = :identifier OR voucher_id = :identifier OR voucher_id = :account_id)
                AND (status = 'active' OR status = 'pending' OR status = 'PENDING')
                AND voucher_expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $voucherStmt->execute([
            'identifier' => $voucherIdentifier,
            'account_id' => (int)$voucherIdentifier
        ]);
        
        $voucher = $voucherStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($voucher) {
            $balance = (float)$voucher['amount'];
            $currency = $voucher['currency'] ?? 'BWP';
            $holderName = $voucher['recipient_phone'] ?? 'Voucher Holder';
            $accountNumber = $voucher['voucher_number'] ?? $account_id;
            $accountStatus = $voucher['status'] ?? 'active';
            $voucherData = [
                'voucher_id' => $voucher['voucher_id'],
                'voucher_number' => $voucher['voucher_number'],
                'voucher_pin' => $voucher['voucher_pin'],
                'expires_at' => $voucher['voucher_expires_at'],
                'created_at' => $voucher['created_at'],
                'source_institution' => $voucher['source_institution'],
                'reference' => $voucher['reference']
            ];
            
            error_log("ZURUBANK BALANCE: Found voucher {$account_id} with amount {$balance}");
        } else {
            // Check if it's a voucher by trying to find it
            $voucherStmt2 = $pdo->prepare("
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
                    created_by
                FROM instant_money_vouchers 
                WHERE voucher_number = :identifier AND status != 'redeemed' AND status != 'expired'
                LIMIT 1
            ");
            $voucherStmt2->execute(['identifier' => $account_id]);
            $voucher2 = $voucherStmt2->fetch(PDO::FETCH_ASSOC);
            
            if ($voucher2) {
                $balance = (float)$voucher2['amount'];
                $currency = $voucher2['currency'] ?? 'BWP';
                $holderName = $voucher2['recipient_phone'] ?? 'Voucher Holder';
                $accountNumber = $voucher2['voucher_number'];
                $accountStatus = $voucher2['status'] ?? 'active';
                $voucherData = [
                    'voucher_id' => $voucher2['voucher_id'],
                    'voucher_number' => $voucher2['voucher_number'],
                    'voucher_pin' => $voucher2['voucher_pin'],
                    'expires_at' => $voucher2['voucher_expires_at'],
                    'created_at' => $voucher2['created_at'],
                    'source_institution' => $voucher2['source_institution'],
                    'reference' => $voucher2['reference']
                ];
                error_log("ZURUBANK BALANCE: Found voucher by number {$account_id} with amount {$balance}");
            }
        }
    }
    
    // ============================================================
    // CHECK ACCOUNT TABLE (for account/wallet asset types)
    // ============================================================
    if ($balance === 0 && empty($voucherData)) {
        
        // Build query based on authentication method
        if ($userId) {
            // JWT: User owns the account
            $stmt = $pdo->prepare("
                SELECT a.account_number, a.balance, a.currency, a.account_name, a.status, a.user_id
                FROM accounts a
                WHERE a.user_id = :user_id AND (a.account_number = :account_number OR a.phone = :account_number OR a.account_number = :identifier)
                LIMIT 1
            ");
            $stmt->execute([
                'user_id' => $userId,
                'account_number' => $account_id,
                'identifier' => $account_id
            ]);
        } else {
            // Certificate: Check if account exists (institution can view any account)
            $stmt = $pdo->prepare("
                SELECT a.account_number, a.balance, a.currency, a.account_name, a.status, a.user_id
                FROM accounts a
                WHERE a.account_number = :account_number OR a.phone = :account_number
                LIMIT 1
            ");
            $stmt->execute(['account_number' => $account_id]);
        }
        
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            $balance = (float)$account['balance'];
            $currency = $account['currency'] ?? 'BWP';
            $holderName = $account['account_name'] ?? null;
            $accountNumber = $account['account_number'];
            $accountStatus = $account['status'] ?? 'active';
            
            // Get user info for the account
            $userStmt = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE user_id = ?");
            $userStmt->execute([$account['user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            if ($user && !$holderName) {
                $holderName = $user['full_name'] ?? null;
            }
            
            error_log("ZURUBANK BALANCE: Found account {$account_id} with balance {$balance}");
        }
    }

    // ============================================================
    // AUDIT LOG
    // ============================================================
    $auditStmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (entity_type, entity_id, action, category, severity, performed_by, 
         performed_by_cert_verified, verification_method, metadata, performed_at)
        VALUES 
        ('accounts', :entity_id, 'BALANCE_CHECK', 'inquiry', 'info', :performed_by,
         :cert_verified, :verification_method, :metadata, NOW())
    ");
    $auditStmt->execute([
        'entity_id' => $account_id,
        'performed_by' => $requester,
        'cert_verified' => ($verificationMethod === 'certificate' && $isCertValid) ? 1 : 0,
        'verification_method' => $verificationMethod,
        'metadata' => json_encode([
            'account_number' => $accountNumber,
            'asset_type' => $asset_type,
            'is_voucher' => !empty($voucherData),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ])
    ]);

    // ============================================================
    // PREPARE RESPONSE
    // ============================================================
    $responseData = [
        'status' => 'success',
        'verified' => true,
        'data' => [
            'account_number' => $accountNumber ?? $account_id,
            'account_name' => $holderName ?? 'Account Holder',
            'holder_name' => $holderName ?? null,
            'balance' => $balance,
            'available_balance' => $balance,
            'currency' => $currency,
            'asset_type' => $asset_type,
            'is_voucher' => !empty($voucherData),
            'status' => $accountStatus,
            'timestamp' => time()
        ],
        'requester' => $requester,
        'verification_method' => $verificationMethod,
        'signature_verified' => ($verificationMethod === 'certificate') ? $isCertValid : true
    ];
    
    // Add voucher details if found
    if ($voucherData) {
        $responseData['data']['voucher_details'] = $voucherData;
    }

    // If certificate was used, send signed response
    if ($verificationMethod === 'certificate' && $isCertValid) {
        send_signed_response($responseData);
    } else {
        // JWT or no certificate - return unsigned response
        echo json_encode($responseData);
    }

} catch (Exception $e) {
    error_log("ZURUBANK BALANCE ERROR: " . $e->getMessage());
    error_log("ZURUBANK BALANCE Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal server error',
        'timestamp' => time()
    ]);
}
