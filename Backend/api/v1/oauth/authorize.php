<?php
// Backend/api/v1/oauth/authorize.php
// Bank's OAuth Authorization Server

// Start session properly
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/jwt.php';

// ============================================================
// CHECK: Is $pdo available from db.php?
// ============================================================
if (!isset($pdo) || $pdo === null) {
    error_log("OAuth authorize: Database connection not available");
    die("Database connection error");
}

// Get request parameters
$client_id = $_GET['client_id'] ?? '';        // VouchMorph's App ID
$redirect_uri = $_GET['redirect_uri'] ?? '';  // VouchMorph callback URL
$scope = $_GET['scope'] ?? 'read_balance read_transactions';
$state = $_GET['state'] ?? '';
$response_type = $_GET['response_type'] ?? 'code';

// Validate client_id
if ($client_id !== 'VOUCHMORPH_APP_ID') {
    die('Invalid client');
}

// ============================================================
// CHECK IF USER IS LOGGED IN
// If not, redirect to bank's login page with return URL
// ============================================================
if (!isset($_SESSION['user']['id']) && !isset($_SESSION['bank_user_id'])) {
    // Save the current URL to return after login
    $return_url = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /Frontend/auth/login.php?return_url=" . $return_url);
    exit;
}

// Get user ID from session (support both session variable names)
$user_id = $_SESSION['user']['id'] ?? $_SESSION['bank_user_id'] ?? null;

if (!$user_id) {
    // Something went wrong - redirect to login
    $return_url = urlencode($_SERVER['REQUEST_URI']);
    header("Location: /Frontend/auth/login.php?return_url=" . $return_url);
    exit;
}

// ============================================================
// HANDLE LOGIN FORM SUBMISSION (if login is embedded here)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
    // Validate credentials
    try {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? AND password_hash = ?");
        $stmt->execute([$_POST['username'], md5($_POST['password'])]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $_SESSION['user']['id'] = $user['user_id'];
            $_SESSION['bank_user_id'] = $user['user_id'];
            // Redirect back to OAuth with same parameters
            header("Location: " . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $login_error = "Invalid credentials";
        }
    } catch (PDOException $e) {
        error_log("OAuth login error: " . $e->getMessage());
        $login_error = "Database error";
    }
}

// ============================================================
// HANDLE CONSENT
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consent']) && $_POST['consent'] == 1) {
    // Get parameters from POST (or fallback to GET)
    $client_id = $_POST['client_id'] ?? $client_id;
    $redirect_uri = $_POST['redirect_uri'] ?? $redirect_uri;
    $state = $_POST['state'] ?? $state;
    $scope = $_POST['scope'] ?? $scope;
    
    // Generate authorization code
    $auth_code = bin2hex(random_bytes(32));
    
    try {
        // FIX: PostgreSQL syntax with 'used' column
        $stmt = $pdo->prepare("
            INSERT INTO oauth_auth_codes (code, user_id, client_id, scope, expires_at, used) 
            VALUES (?, ?, ?, ?, NOW() + INTERVAL '10 minutes', false)
        ");
        $stmt->execute([$auth_code, $user_id, $client_id, $scope]);
        
        // Redirect back to VouchMorph
        header("Location: $redirect_uri?code=$auth_code&state=$state");
        exit;
    } catch (PDOException $e) {
        error_log("OAuth code storage error: " . $e->getMessage());
        die("Error generating authorization code");
    }
}

// ============================================================
// SHOW CONSENT SCREEN (User is logged in)
// ============================================================
?>
<!DOCTYPE html>
<html>
<head>
    <title>Authorize VouchMorph</title>
    <style>
        body {
            background: #000;
            color: #fff;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }
        .consent-container {
            border: 1px solid rgba(255,255,255,0.1);
            padding: 40px;
            width: 450px;
            max-width: 100%;
        }
        .app-name { 
            font-size: 24px; 
            color: #fff; 
            margin-bottom: 6px;
            font-weight: 800;
        }
        .app-sub {
            color: rgba(255,255,255,0.4);
            font-size: 14px;
            margin-bottom: 30px;
        }
        .permission-list { 
            margin: 30px 0; 
        }
        .permission { 
            padding: 12px 0; 
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .permission strong { 
            color: #fff; 
            display: block;
            margin-bottom: 2px;
        }
        .permission span {
            font-size: 12px;
            color: rgba(255,255,255,0.4);
        }
        .button-group { 
            display: flex; 
            gap: 12px; 
            margin-top: 30px; 
        }
        .allow-btn { 
            flex: 1; 
            background: #fff; 
            border: none; 
            padding: 14px; 
            color: #000; 
            font-weight: 700; 
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
        }
        .allow-btn:hover {
            background: #e0e0e0;
        }
        .deny-btn { 
            flex: 1; 
            background: transparent; 
            border: 1px solid rgba(255,255,255,0.3); 
            padding: 14px; 
            color: #fff; 
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .deny-btn:hover {
            background: rgba(255,255,255,0.05);
        }
        .footer-text {
            font-size: 11px;
            color: rgba(255,255,255,0.3);
            margin-top: 30px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="consent-container">
        <div class="app-name">↔ VOUCHMORPH</div>
        <div class="app-sub">is requesting access to your ZuruBank account</div>
        
        <form method="POST">
            <input type="hidden" name="client_id" value="<?php echo htmlspecialchars($client_id); ?>">
            <input type="hidden" name="redirect_uri" value="<?php echo htmlspecialchars($redirect_uri); ?>">
            <input type="hidden" name="state" value="<?php echo htmlspecialchars($state); ?>">
            <input type="hidden" name="scope" value="<?php echo htmlspecialchars($scope); ?>">
            
            <div class="permission-list">
                <div class="permission">
                    <strong>✓ View account balance</strong>
                    <span>Check available funds for swaps</span>
                </div>
                <div class="permission">
                    <strong>✓ View transaction history</strong>
                    <span>Verify recent transactions</span>
                </div>
                <div class="permission">
                    <strong>✓ Initiate payments</strong>
                    <span>Execute swaps from your account</span>
                </div>
            </div>
            
            <div class="button-group">
                <button type="submit" name="consent" value="1" class="allow-btn">ALLOW ACCESS →</button>
                <button type="button" class="deny-btn" onclick="window.location.href='https://vouchmorphn-production.up.railway.app'">DENY</button>
            </div>
        </form>
        
        <div class="footer-text">
            VouchMorph will never share your credentials. You can revoke access anytime in ZuruBank settings.
        </div>
    </div>
</body>
</html>
