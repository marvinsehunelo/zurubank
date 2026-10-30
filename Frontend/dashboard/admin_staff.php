<?php
// Frontend/dashboard/admin_staff.php
//
// Staff accounts (admins only): add a member of staff with their own username and password,
// deactivate or reactivate them, and reset a password. Every review and audit entry then names
// the person who did it. The last active admin cannot be deactivated or demoted.

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../Backend/config/db.php';
require_once __DIR__ . '/../../Backend/auth/admin_users.php';

$me = zb_admin_current($pdo);
if ($me === null) {
    header('Location: admin_dashboard.php');
    exit;
}
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

function st_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['staff_csrf'])) $_SESSION['staff_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['staff_csrf'];
$isAdmin = $me['role'] === 'admin';
$flash = $_SESSION['staff_flash'] ?? null;
unset($_SESSION['staff_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $done = function (string $type, string $msg): void {
        $_SESSION['staff_flash'] = ['type' => $type, 'msg' => $msg];
        header('Location: admin_staff.php');
        exit;
    };
    if (!$isAdmin) $done('error', 'Only admins can change staff accounts.');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) $done('error', 'The form expired. Please try again.');

    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'add') {
            $id = zb_admin_create(
                $pdo,
                trim((string)($_POST['username'] ?? '')),
                (string)($_POST['full_name'] ?? ''),
                (string)($_POST['password'] ?? ''),
                (string)($_POST['role'] ?? 'staff'),
                (int)$me['id']
            );
            error_log("[admin] staff #{$id} added by #{$me['id']}");
            $done('ok', 'Staff account added. Give them their password privately; they can sign in now.');
        }
        $targetId = (int)($_POST['id'] ?? 0);
        $target = zb_admin_find($pdo, $targetId);
        if ($target === null) $done('error', 'No such staff account.');
        if ($action === 'deactivate' || $action === 'activate') {
            $activate = $action === 'activate';
            if (!$activate && $target['role'] === 'admin' && $target['active'] && zb_admin_active_admins($pdo) <= 1) {
                $done('error', 'You cannot deactivate the last active admin.');
            }
            zb_admin_set_active($pdo, $targetId, $activate);
            error_log("[admin] staff #{$targetId} " . ($activate ? 'activated' : 'deactivated') . " by #{$me['id']}");
            $done('ok', $activate ? 'Account reactivated.' : 'Account deactivated. It stops working at once.');
        }
        if ($action === 'password') {
            zb_admin_set_password($pdo, $targetId, (string)($_POST['password'] ?? ''));
            error_log("[admin] password of staff #{$targetId} reset by #{$me['id']}");
            $done('ok', 'Password changed.');
        }
        $done('error', 'Unknown action.');
    } catch (InvalidArgumentException $e) {
        $done('error', $e->getMessage());
    }
}

$staff = zb_admin_list($pdo);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zurubank Admin - Staff</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .bg-zurubank { background-color: #0d2847; }
        .text-zurubank { color: #0d2847; }
        .text-accent { color: #facc15; }
        .btn-primary { background-color: #facc15; color: #0d2847; transition: all 0.2s; }
        .btn-primary:hover { background-color: #eab308; }
        .card { background-color: rgba(255, 255, 255, 0.95); }
        .input { border: 1px solid #d1d5db; border-radius: 0.5rem; padding: 0.5rem 0.75rem; width: 100%; }
        .input:focus { outline: none; border-color: #facc15; box-shadow: 0 0 0 2px rgba(250, 204, 21, 0.4); }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="w-full max-w-5xl mx-auto p-4 sm:p-6 lg:p-8">
    <header class="bg-zurubank p-4 sm:p-6 rounded-lg shadow-lg flex justify-between items-center mb-6">
        <h2 class="text-2xl sm:text-3xl font-bold text-white">Staff accounts</h2>
        <a href="admin_dashboard.php" class="text-white hover:text-accent font-medium text-sm">&larr; Admin dashboard</a>
    </header>

    <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded-lg <?= $flash['type'] === 'ok' ? 'bg-green-50 text-green-800 border-l-4 border-green-600' : 'bg-red-50 text-red-700 border-l-4 border-red-600' ?>"><?= st_h($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="card p-6 rounded-xl shadow-lg mb-6 overflow-x-auto">
        <p class="text-sm text-gray-600 mb-4">Signed in as <strong><?= st_h($me['full_name']) ?></strong> (<?= st_h($me['username']) ?>, <?= st_h($me['role']) ?>).</p>
        <table class="w-full text-sm">
            <thead>
            <tr class="text-left text-gray-500 border-b">
                <th class="py-2 pr-4">Name</th><th class="py-2 pr-4">Username</th><th class="py-2 pr-4">Role</th>
                <th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Last sign-in</th><?php if ($isAdmin): ?><th class="py-2">Actions</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($staff as $s): ?>
            <tr class="border-b align-top">
                <td class="py-2 pr-4"><?= st_h($s['full_name']) ?></td>
                <td class="py-2 pr-4 font-mono"><?= st_h($s['username']) ?></td>
                <td class="py-2 pr-4"><?= st_h($s['role']) ?></td>
                <td class="py-2 pr-4"><?= $s['active'] ? '<span class="text-green-700">Active</span>' : '<span class="text-gray-500">Deactivated</span>' ?></td>
                <td class="py-2 pr-4"><?= $s['last_login_at'] ? st_h(date('j M Y, H:i', strtotime($s['last_login_at']))) : '—' ?></td>
                <?php if ($isAdmin): ?>
                <td class="py-2">
                    <form method="post" class="inline">
                        <input type="hidden" name="csrf" value="<?= st_h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <input type="hidden" name="action" value="<?= $s['active'] ? 'deactivate' : 'activate' ?>">
                        <button class="underline text-zurubank" <?= (int)$s['id'] === (int)$me['id'] ? 'disabled title="You cannot deactivate yourself"' : '' ?>><?= $s['active'] ? 'Deactivate' : 'Reactivate' ?></button>
                    </form>
                    <form method="post" class="mt-2 flex gap-2">
                        <input type="hidden" name="csrf" value="<?= st_h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <input type="hidden" name="action" value="password">
                        <input type="password" name="password" minlength="<?= ZB_ADMIN_MIN_PASSWORD ?>" required placeholder="New password" class="input" autocomplete="new-password" aria-label="New password for <?= st_h($s['username']) ?>">
                        <button class="btn-primary px-3 rounded-lg whitespace-nowrap">Set</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($isAdmin): ?>
    <div class="card p-6 rounded-xl shadow-lg">
        <h3 class="text-xl font-semibold text-zurubank mb-4">Add a member of staff</h3>
        <form method="post" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <input type="hidden" name="csrf" value="<?= st_h($csrf) ?>">
            <input type="hidden" name="action" value="add">
            <label class="text-sm font-medium text-gray-700">Full name<input name="full_name" required maxlength="120" class="input mt-1"></label>
            <label class="text-sm font-medium text-gray-700">Username<input name="username" required pattern="[A-Za-z0-9._\-]{3,64}" class="input mt-1" autocomplete="off"></label>
            <label class="text-sm font-medium text-gray-700">Password (<?= ZB_ADMIN_MIN_PASSWORD ?>+ characters)<input type="password" name="password" required minlength="<?= ZB_ADMIN_MIN_PASSWORD ?>" class="input mt-1" autocomplete="new-password"></label>
            <label class="text-sm font-medium text-gray-700">Role
                <select name="role" class="input mt-1"><option value="staff">Staff (KYC and customers)</option><option value="admin">Admin (also manages staff)</option></select>
            </label>
            <div class="sm:col-span-2"><button class="btn-primary py-2 px-6 rounded-lg font-semibold">Add staff account</button></div>
        </form>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
