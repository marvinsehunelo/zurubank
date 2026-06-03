<?php
// Backend/api/v1/oauth/authorize.php
// Bank's OAuth Authorization Server

session_start();
require_once '../../config/db.php';
require_once '../../config/jwt.php';

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

// Check if user is logged into bank
if (!isset($_SESSION['bank_user_id'])) {
    // Show bank login page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Authorize VouchMorph</title>
        <style>
            body {
                background: #000;
                color: #fff;
                font-family: monospace;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
            }
            .auth-container {
                border: 1px solid rgba(255,255,255,0.1);
                padding: 40px;
                width: 400px;
            }
            .logo { font-size: 24px; margin-bottom: 30px; }
            input {
                width: 100%;
                background: transparent;
                border: 1px solid rgba(255,255,255,0.2);
                padding: 12px;
                color: #fff;
                margin: 10px 0;
                font-family: monospace;
            }
            button {
                width: 100%;
                background: #fff;
                border: none;
                padding: 12px;
                color: #000;
                font-weight: bold;
                cursor: pointer;
                margin-top: 20px;
            }
            .permission-list { margin: 20px 0; }
            .permission { padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        </style>
    </head>
    <body>
        <div class="auth-container">
            <div class="logo">🏦 YOUR BANK</div>
            <h3>Login to continue</h3>
            <form method="POST">
                <input type="text" name="username" placeholder="Username" required>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit">Login →</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// If logged in, show consent screen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consent'])) {
    // Generate authorization code
    $auth_code = bin2hex(random_bytes(32));
    
    // Store code with user ID and scope
    $stmt = $db->prepare("INSERT INTO oauth_auth_codes (code, user_id, client_id, scope, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
    $stmt->execute([$auth_code, $_SESSION['bank_user_id'], $client_id, $scope]);
    
    // Redirect back to VouchMorph
    header("Location: $redirect_uri?code=$auth_code&state=$state");
    exit;
}

// Show consent screen
?>
<!DOCTYPE html>
<html>
<head>
    <title>Authorize VouchMorph</title>
    <style>
        body {
            background: #000;
            color: #fff;
            font-family: monospace;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
        }
        .consent-container {
            border: 1px solid rgba(255,255,255,0.1);
            padding: 40px;
            width: 450px;
        }
        .app-name { font-size: 20px; color: #fff; margin-bottom: 10px; }
        .permission-list { margin: 30px 0; }
        .permission { padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .permission strong { color: #fff; }
        .button-group { display: flex; gap: 12px; margin-top: 30px; }
        .allow-btn { flex: 1; background: #fff; border: none; padding: 14px; color: #000; font-weight: bold; cursor: pointer; }
        .deny-btn { flex: 1; background: transparent; border: 1px solid rgba(255,255,255,0.3); padding: 14px; color: #fff; cursor: pointer; }
    </style>
</head>
<body>
    <div class="consent-container">
        <div class="app-name">↔ VOUCHMORPH</div>
        <p style="color: rgba(255,255,255,0.6); margin: 10px 0 20px;">is requesting access to your account</p>
        
        <form method="POST">
            <div class="permission-list">
                <div class="permission">
                    <strong>✓ View account balance</strong><br>
                    <span style="font-size: 12px; color: rgba(255,255,255,0.4);">Check available funds for swaps</span>
                </div>
                <div class="permission">
                    <strong>✓ View transaction history</strong><br>
                    <span style="font-size: 12px; color: rgba(255,255,255,0.4);">Verify recent transactions</span>
                </div>
                <div class="permission">
                    <strong>✓ Initiate payments</strong><br>
                    <span style="font-size: 12px; color: rgba(255,255,255,0.4);">Execute swaps from your account</span>
                </div>
            </div>
            
            <input type="hidden" name="consent" value="1">
            <div class="button-group">
                <button type="submit" class="allow-btn">ALLOW ACCESS →</button>
                <button type="button" class="deny-btn" onclick="window.close()">DENY</button>
            </div>
        </form>
        
        <p style="font-size: 11px; color: rgba(255,255,255,0.3); margin-top: 30px;">
            VouchMorph will never share your credentials. You can revoke access anytime in bank settings.
        </p>
    </div>
</body>
</html>
<?php
