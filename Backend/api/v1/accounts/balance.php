<?php
// Backend/api/v1/accounts/balance.php
// Get account balance (supports JWT for users OR certificate for institution-to-institution)

require_once '../../config/db.php';
require_once '../../config/jwt.php';
require_once '../../helpers/crypto.php';
require_once '../../helpers/CertificateManager.php';

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
        
        // For institution requests, we need to find user by account (no direct user_id)
        // We'll validate account ownership separately
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
        $isCertValid = true; // Treat JWT as valid for this context
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

// Get account_id from request
$account_id = $_GET['account_id'] ?? $_POST['account_id'] ?? $input['account_id'] ?? '';

if (empty($account_id)) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'account_id required',
        'timestamp' => time()
    ]);
    exit;
}

try {
    // Build query based on authentication method
    if ($userId) {
        // JWT: User owns the account
        $stmt = $pdo->prepare("
            SELECT a.account_number, a.balance, a.currency, a.account_name, a.status, a.user_id
            FROM accounts a
            WHERE a.user_id = :user_id AND a.account_number = :account_number
            LIMIT 1
        ");
        $stmt->execute([
            'user_id' => $userId,
            'account_number' => $account_id
        ]);
    } else {
        // Certificate: Check if account exists (institution can view any account)
        $stmt = $pdo->prepare("
            SELECT a.account_number, a.balance, a.currency, a.account_name, a.status, a.user_id
            FROM accounts a
            WHERE a.account_number = :account_number
            LIMIT 1
        ");
        $stmt->execute(['account_number' => $account_id]);
    }
    
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Account not found',
            'timestamp' => time()
        ]);
        exit;
    }

    if ($account['status'] !== 'active') {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Account is not active',
            'timestamp' => time()
        ]);
        exit;
    }

    // Get user info for the account
    $userStmt = $pdo->prepare("SELECT full_name, email, phone FROM users WHERE user_id = ?");
    $userStmt->execute([$account['user_id']]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Audit log for balance check
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
            'account_number' => $account['account_number'],
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ])
    ]);

    // Prepare response
    $responseData = [
        'status' => 'success',
        'data' => [
            'account_number' => $account['account_number'],
            'account_name' => $account['account_name'],
            'holder_name' => $user['full_name'] ?? null,
            'balance' => floatval($account['balance']),
            'currency' => $account['currency'],
            'timestamp' => time()
        ],
        'requester' => $requester,
        'verification_method' => $verificationMethod,
        'signature_verified' => ($verificationMethod === 'certificate') ? $isCertValid : true
    ];

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
