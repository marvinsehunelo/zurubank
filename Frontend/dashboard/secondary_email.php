<?php
// Frontend/dashboard/secondary_email.php
//
// Add and verify a secondary email address. The email the account was
// opened with may be a placeholder; a verified address here is where
// ZuruBank sends the code when VouchMorph asks to link this account.

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['authToken']) || !isset($_SESSION['user']['id'])) {
    header("Location: ../auth/login.php");
    exit;
}
$user_id = (int)$_SESSION['user']['id'];

require_once __DIR__ . '/../../Backend/config/db.php';
require_once __DIR__ . '/../../Backend/helpers/secondary_email.php';

if (empty($_SESSION['se_csrf'])) {
    $_SESSION['se_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['se_csrf'];

$error   = '';
$notice  = '';
$pending = $_SESSION['se_pending_email'] ?? null; // address a code was sent to, until it is verified
$emailIn = trim((string)($_POST['email'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            $error = 'Your session expired. Please try again.';
        } elseif ($action === 'send') {
            $email = se_normalize($emailIn);
            if ($email === null) {
                $error = 'Please enter a valid email address.';
            } else {
                $result = se_send_code($pdo, $user_id, $email, 'verify_email');
                if ($result['ok']) {
                    $pending = $_SESSION['se_pending_email'] = $email;
                    $notice  = $result['message'];
                } else {
                    $error = $result['message'];
                }
            }
        } elseif ($action === 'resend' && $pending) {
            $result = se_send_code($pdo, $user_id, $pending, 'verify_email');
            $result['ok'] ? $notice = $result['message'] : $error = $result['message'];
        } elseif ($action === 'verify' && $pending) {
            $result = se_confirm_email($pdo, $user_id, (string)($_POST['code'] ?? ''));
            if ($result['ok']) {
                unset($_SESSION['se_pending_email']);
                $pending = null;
                $notice  = $result['message'] . ' ZuruBank will send codes for linking your account to VouchMorph here.';
            } else {
                $error = $result['message'];
            }
        } elseif ($action === 'cancel') {
            unset($_SESSION['se_pending_email']);
            $pending = null;
        } elseif ($action === 'remove') {
            se_remove($pdo, $user_id);
            unset($_SESSION['se_pending_email']);
            $pending = null;
            $notice  = 'Your secondary email was removed.';
        }
    } catch (Throwable $e) {
        error_log('[SecondaryEmail page] ' . $action . ' failed: ' . $e->getMessage());
        $error = 'Something went wrong. Please try again.';
    }
}

$verified = null;
try {
    $verified = se_verified_email($pdo, $user_id);
} catch (Throwable $e) {
    error_log('[SecondaryEmail page] lookup failed: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ZuruBank - Secondary Email</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
body { font-family: 'Inter', sans-serif; font-size: 14px; }
.color-primary { background-color: #0B1D51; }
.text-primary { color: #0B1D51; }
.btn-primary { background-color: #0B1D51; color: white; border: 1px solid #0B1D51; }
.btn-primary:hover { background-color: #1a3466; }
.input-field { border: 1px solid #E5E7EB; padding: 8px 10px; font-size: 14px; width: 100%; }
.input-field:focus { border-color: #0B1D51; box-shadow: 0 0 0 1px #0B1D51; outline: none; }
</style>
</head>
<body class="min-h-screen bg-gray-50">

<header class="color-primary py-3 px-8 flex justify-between items-center text-white shadow-md">
    <h1 class="text-xl font-extrabold tracking-widest">ZURUBANK</h1>
    <a href="user_dashboard.php" class="text-sm text-gray-300 hover:text-white">&larr; Back to dashboard</a>
</header>

<main class="max-w-xl mx-auto px-4 py-10">
    <div class="bg-white p-6 shadow-lg border-t-4" style="border-top-color:#0B1D51">
        <h2 class="text-lg font-bold text-primary mb-1">Secondary email</h2>
        <p class="text-gray-600 mb-5">Add an email address you can reach. When you link this ZuruBank account to VouchMorph, we email the confirmation code here.</p>

        <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-50 text-red-700 border-l-4 border-red-600"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($notice): ?>
        <div class="mb-4 p-3 bg-green-50 text-green-800 border-l-4 border-green-600"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>

        <?php if ($verified && !$pending): ?>
        <div class="mb-5 p-4 bg-gray-50 border border-gray-200 flex justify-between items-center">
            <div>
                <p class="font-semibold text-gray-900"><?= htmlspecialchars($verified['email']) ?></p>
                <p class="text-xs text-green-700 font-semibold">&#10003; Verified <?= htmlspecialchars(date('j M Y', strtotime($verified['verified_at']))) ?></p>
            </div>
            <form method="POST" onsubmit="return confirm('Remove this email? Linking this account to VouchMorph will then only ask for your ZuruBank login.');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="remove">
                <button class="text-xs text-red-600 hover:underline">Remove</button>
            </form>
        </div>
        <p class="text-sm font-semibold text-gray-700 mb-2">Change to a different address</p>
        <?php endif; ?>

        <?php if ($pending): ?>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="verify">
            <label class="block text-sm font-semibold text-gray-700" for="code">Enter the 6-digit code sent to <?= htmlspecialchars($pending) ?></label>
            <input class="input-field tracking-widest" id="code" name="code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required autofocus>
            <button class="btn-primary w-full py-2 font-semibold">VERIFY EMAIL</button>
        </form>
        <div class="flex justify-between mt-4 text-sm">
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="resend">
                <button class="text-primary hover:underline">Send a new code</button>
            </form>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="cancel">
                <button class="text-gray-500 hover:underline">Use a different address</button>
            </form>
        </div>
        <?php else: ?>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="send">
            <?php if (!$verified): ?><label class="block text-sm font-semibold text-gray-700" for="email">Email address</label><?php endif; ?>
            <input class="input-field" type="email" id="email" name="email" value="<?= htmlspecialchars($emailIn) ?>" placeholder="you@example.com" autocomplete="email" required>
            <button class="btn-primary w-full py-2 font-semibold">SEND VERIFICATION CODE</button>
        </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
