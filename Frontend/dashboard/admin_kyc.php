<?php
// Frontend/dashboard/admin_kyc.php
//
// Customers & KYC (admin only). Staff ask KYC Bank for a customer's KYC,
// follow the request's state, read the verified profile once the customer
// approves, and decide. Only a staff decision changes users.kyc_status;
// what KYC Bank shares is evidence, ZuruBank still makes its own decision.
//
//   admin_kyc.php                                     customer list
//   admin_kyc.php?user_id=N                           one customer: request form + requests
//   admin_kyc.php?user_id=N&request_id=M&view=profile the fetched profile + approve / reject
//
// Same admin session as admin_dashboard.php (Frontend/dashboard/admin_login.php).

if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: admin_dashboard.php');
    exit;
}
$staffId = (int)($_SESSION['admin_user_id'] ?? 0);

require_once __DIR__ . '/../../Backend/config/db.php';
require_once __DIR__ . '/../../Backend/integrations/kycbank/kycbank.php';

header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

if (empty($_SESSION['kb_csrf'])) {
    $_SESSION['kb_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['kb_csrf'];

function kb_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function kb_date(?string $ts, bool $withTime = true): string
{
    if (!$ts) return '—';
    $t = strtotime($ts);
    return $t ? date($withTime ? 'j M Y, H:i' : 'j M Y', $t) : '—';
}

function kb_flash(string $type, string $msg): void
{
    $_SESSION['kb_flash'] = ['type' => $type, 'msg' => $msg];
}

function kb_api_error_text(KycBankException $e): string
{
    $text = 'KYC Bank: ' . $e->getMessage() . ' (' . $e->errorCode . ')';
    if ($e->errorCode === 'duplicate_request') {
        $text .= ' An open request already exists for this customer and purpose. Refresh that one, or use a different purpose.';
    } elseif ($e->isTransient()) {
        $text .= ' Nothing was lost: try again, the same request will be reused.';
    }
    return $text;
}

function kb_self(array $params): string
{
    return 'admin_kyc.php' . ($params ? '?' . http_build_query($params) : '');
}

$schemaError = '';
try {
    kb_ensure_schema($pdo);
} catch (Throwable $e) {
    error_log('[KYCBank admin] schema check failed: ' . get_class($e));
    $schemaError = 'The KYC Bank tables could not be prepared. Apply database/migrations/2026_10_30_kycbank.sql.';
}

$client     = KycBankClient::fromEnv();
$configured = $client !== null;

// ----------------------------------------------------------------------
// Actions (POST, then redirect back)
// ----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = (string)($_POST['action'] ?? '');
    $userId    = (int)($_POST['user_id'] ?? 0);
    $requestId = (int)($_POST['request_id'] ?? 0);
    $back      = ['user_id' => $userId];

    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            kb_flash('error', 'Your session expired. Please try again.');
        } elseif ($schemaError !== '') {
            kb_flash('error', $schemaError);
        } elseif ($action === 'request' || $action === 'retry') {
            $row = null;
            if ($action === 'retry') {
                $row = kb_request($pdo, $requestId);
                if (!$row || (int)$row['user_id'] !== $userId || $row['state'] !== 'creating' || $row['consent_id'] !== null) {
                    throw new DomainException('That request cannot be retried.');
                }
                $kind = (string)$row['identifier_kind'];
            } else {
                $kind = (string)($_POST['kind'] ?? '');
            }
            $level   = (string)($_POST['level'] ?? ($row['level'] ?? ''));
            $purpose = trim((string)($_POST['purpose'] ?? ($row['purpose'] ?? '')));
            $value   = $kind === 'mobile' ? kb_normalize_mobile($_POST['mobile'] ?? '') : ($kind === 'omang' ? kb_normalize_omang($_POST['omang'] ?? '') : null);

            $u = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ?');
            $u->execute([$userId]);

            if (!$configured) {
                kb_flash('error', 'KYC Bank is not configured.');
            } elseif (!$u->fetchColumn()) {
                kb_flash('error', 'Customer not found.');
            } elseif (!in_array($kind, ['mobile', 'omang'], true)) {
                kb_flash('error', 'Choose mobile number or Omang.');
            } elseif ($value === null) {
                kb_flash('error', $kind === 'mobile'
                    ? 'Enter the mobile number in international form, e.g. +26770000001.'
                    : 'Enter the 9-digit Omang number.');
            } elseif (!in_array($level, ['basic', 'standard'], true)) {
                kb_flash('error', 'Choose a level: basic or standard.');
            } elseif ($purpose === '' || kb_strlen($purpose) > 200) {
                kb_flash('error', 'The purpose is required and may be at most 200 characters.');
            } else {
                try {
                    $created = kb_create_request($pdo, $client, $userId, $kind, $value, $level, $purpose, $staffId, $row);
                    kb_flash('ok', 'Request sent to KYC Bank (consent ' . ($created['consent_id'] ?? '?') . '). The customer approves it in the KYC Bank app.');
                } catch (KycBankException $e) {
                    kb_flash('error', kb_api_error_text($e));
                }
            }
        } elseif ($action === 'refresh') {
            $row = kb_request($pdo, $requestId);
            if (!$row || (int)$row['user_id'] !== $userId) {
                kb_flash('error', 'Request not found.');
            } elseif ($row['state'] === 'withdrawn') {
                kb_flash('error', 'This consent was withdrawn by the customer. ZuruBank no longer fetches its data.');
            } elseif (!$configured) {
                kb_flash('error', 'KYC Bank is not configured.');
            } elseif (empty($row['consent_id'])) {
                kb_flash('error', 'KYC Bank has not confirmed this request yet; send it again below.');
            } else {
                try {
                    $state = kb_sync($pdo, $client, $row);
                    kb_audit($pdo, 'KYCBANK_REFRESHED', $requestId, $staffId, ['user_id' => $userId, 'consent_id' => (string)$row['consent_id'], 'state' => $state]);
                    kb_flash('ok', 'Status refreshed: ' . kb_state_label($state) . '.');
                } catch (KycBankException $e) {
                    kb_set_error($pdo, $requestId, $e->errorCode);
                    kb_flash('error', kb_api_error_text($e));
                }
            }
        } elseif ($action === 'approve' || $action === 'reject') {
            $row = kb_request($pdo, $requestId);
            if (!$row || (int)$row['user_id'] !== $userId) {
                kb_flash('error', 'Request not found.');
            } else {
                try {
                    $seen = isset($_POST['profile_version']) && $_POST['profile_version'] !== '' ? (int)$_POST['profile_version'] : null;
                    kb_review($pdo, $requestId, $action === 'approve' ? 'approved' : 'rejected', $staffId, $seen);
                    kb_flash('ok', $action === 'approve'
                        ? 'KYC approved for this customer, based on KYC Bank profile version ' . ($row['profile_version'] ?? '?') . '.'
                        : 'KYC rejected for this customer.');
                } catch (DomainException $e) {
                    kb_flash('error', $e->getMessage());
                }
                $back += ['request_id' => $requestId, 'view' => 'profile'];
            }
        } else {
            kb_flash('error', 'Unknown action.');
        }
    } catch (Throwable $e) {
        error_log('[KYCBank admin] ' . $action . ' failed for user ' . $userId . ' request ' . $requestId . ': ' . get_class($e));
        kb_flash('error', $e instanceof DomainException ? $e->getMessage() : 'Something went wrong. Please try again.');
    }
    header('Location: ' . kb_self($userId ? $back : []));
    exit;
}

$flash = $_SESSION['kb_flash'] ?? null;
unset($_SESSION['kb_flash']);

// ----------------------------------------------------------------------
// Data for the views
// ----------------------------------------------------------------------
$userId    = (int)($_GET['user_id'] ?? 0);
$requestId = (int)($_GET['request_id'] ?? 0);
$view      = (string)($_GET['view'] ?? '');
$q         = trim((string)($_GET['q'] ?? ''));

$customer = null;
$requests = [];
$profileRow = null;
$customers = [];
$loadError = '';

try {
    if ($schemaError === '' && $userId > 0) {
        $st = $pdo->prepare('SELECT user_id, full_name, email, phone, kyc_status, status, created_at FROM users WHERE user_id = ?');
        $st->execute([$userId]);
        $customer = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($customer) {
            $requests = kb_requests_for_user($pdo, $userId);
            if ($view === 'profile' && $requestId > 0) {
                foreach ($requests as $r) {
                    if ((int)$r['id'] === $requestId) $profileRow = $r;
                }
                if ($profileRow && $profileRow['state'] !== 'withdrawn' && $profileRow['profile_json'] !== null) {
                    kb_audit($pdo, 'KYCBANK_PROFILE_VIEWED', $requestId, $staffId, ['user_id' => $userId, 'consent_id' => (string)$profileRow['consent_id']]);
                }
            }
        }
    } elseif ($schemaError === '') {
        $sql = "
            SELECT u.user_id, u.full_name, u.email, u.phone, u.kyc_status,
                   r.state, r.needs_review, r.review_decision, r.review_reason,
                   EXISTS (SELECT 1 FROM kycbank_requests w
                            WHERE w.user_id = u.user_id AND w.state = 'withdrawn' AND w.review_decision = 'approved') AS basis_withdrawn
              FROM users u
              LEFT JOIN LATERAL (
                    SELECT state, needs_review, review_decision, review_reason
                      FROM kycbank_requests k
                     WHERE k.user_id = u.user_id
                     ORDER BY (k.state = 'failed'), k.created_at DESC, k.id DESC
                     LIMIT 1
              ) r ON TRUE
             WHERE COALESCE(u.role, 'customer') IN ('customer', 'client')";
        $params = [];
        if ($q !== '') {
            $sql .= " AND (u.full_name ILIKE ? OR u.email ILIKE ? OR u.phone ILIKE ? OR u.user_id::text = ?)";
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            $params = [$like, $like, $like, $q];
        }
        $sql .= ' ORDER BY u.user_id DESC LIMIT 100';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $customers = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('[KYCBank admin] load failed: ' . get_class($e));
    $loadError = 'Could not load customers. Please try again.';
}

// ----------------------------------------------------------------------
// Rendering helpers
// ----------------------------------------------------------------------
function kb_badge(?string $state): string
{
    $colors = [
        'active'    => 'bg-green-100 text-green-800',
        'requested' => 'bg-blue-100 text-blue-800',
        'creating'  => 'bg-gray-200 text-gray-700',
        'suspended' => 'bg-yellow-100 text-yellow-800',
        'withdrawn' => 'bg-red-100 text-red-800',
        'rejected'  => 'bg-red-100 text-red-800',
        'failed'    => 'bg-red-100 text-red-800',
        'lapsed'    => 'bg-gray-200 text-gray-700',
        'expired'   => 'bg-gray-200 text-gray-700',
    ];
    $cls = $colors[$state ?? ''] ?? 'bg-gray-100 text-gray-700';
    return '<span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold ' . $cls . '">' . kb_h($state ?: 'none') . '</span>';
}

function kb_kyc_badge(?string $status): string
{
    $status = $status ?: 'pending';
    $cls = $status === 'approved' ? 'bg-green-600 text-white' : ($status === 'rejected' ? 'bg-red-600 text-white' : 'bg-gray-300 text-gray-800');
    return '<span class="inline-block px-2 py-0.5 rounded text-xs font-bold uppercase ' . $cls . '">' . kb_h($status) . '</span>';
}

function kb_label(string $key): string
{
    $words = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', str_replace('_', ' ', $key));
    return ucfirst(strtolower((string)$words));
}

function kb_is_list(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

/** Renders any JSON value as nested key/value HTML. Every scalar is escaped. */
function kb_render($value): string
{
    if ($value === null || $value === '') return '<span class="text-gray-400">—</span>';
    if (is_bool($value)) return $value ? 'Yes' : 'No';
    if (!is_array($value)) return kb_h($value);
    if ($value === []) return '<span class="text-gray-400">—</span>';
    if (kb_is_list($value)) {
        $scalars = array_filter($value, fn($v) => !is_array($v));
        if (count($scalars) === count($value)) {
            return kb_h(implode(', ', array_map(fn($v) => is_bool($v) ? ($v ? 'Yes' : 'No') : (string)$v, $value)));
        }
        $out = '<div class="space-y-2">';
        foreach ($value as $item) {
            $out .= '<div class="border border-gray-200 rounded-lg p-3 bg-white">' . kb_render($item) . '</div>';
        }
        return $out . '</div>';
    }
    $out = '<dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-4 gap-y-1 text-sm">';
    foreach ($value as $k => $v) {
        $out .= '<dt class="text-gray-500">' . kb_h(kb_label((string)$k)) . '</dt>'
              . '<dd class="sm:col-span-2 text-gray-900 font-medium break-words">' . kb_render($v) . '</dd>';
    }
    return $out . '</dl>';
}

$sectionOrder = [
    'personal'          => 'Personal details',
    'identityDocuments' => 'Identity documents',
    'addresses'         => 'Addresses',
    'contacts'          => 'Contacts',
    'nextOfKin'         => 'Next of kin',
    'employment'        => 'Employment',
    'sourceOfFunds'     => 'Source of funds',
    'screening'         => 'Screening',
];
$metaKeys = ['consentId', 'profileVersion', 'level', 'verifiedAt', 'verificationMethod', 'snapshotHash'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zurubank Admin - Customers &amp; KYC</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@100..900&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .bg-zurubank { background-color: #0d2847; }
        .text-zurubank { color: #0d2847; }
        .text-accent { color: #facc15; }
        .btn-primary { background-color: #facc15; color: #0d2847; transition: all 0.2s; }
        .btn-primary:hover { background-color: #eab308; }
        .btn-dark { background-color: #0d2847; color: #fff; transition: all 0.2s; }
        .btn-dark:hover { background-color: #163a63; }
        .card { backdrop-filter: blur(10px); background-color: rgba(255, 255, 255, 0.9); border: 1px solid rgba(255, 255, 255, 0.2); }
        .input { border: 1px solid #d1d5db; border-radius: 0.5rem; padding: 0.5rem 0.75rem; width: 100%; }
        .input:focus { outline: none; border-color: #facc15; box-shadow: 0 0 0 2px rgba(250, 204, 21, 0.4); }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="w-full max-w-5xl mx-auto p-4 sm:p-6 lg:p-8">

    <header class="bg-zurubank p-4 sm:p-6 rounded-lg shadow-lg flex justify-between items-center mb-6">
        <h2 class="text-2xl sm:text-3xl font-bold text-white">Customers &amp; KYC</h2>
        <div class="flex gap-4 text-sm">
            <?php if ($userId): ?><a href="admin_kyc.php" class="text-white hover:text-accent font-medium">All customers</a><?php endif; ?>
            <a href="admin_dashboard.php" class="text-white hover:text-accent font-medium">&larr; Admin dashboard</a>
        </div>
    </header>

    <?php if ($flash): ?>
    <div class="mb-4 p-3 rounded-lg <?= $flash['type'] === 'ok' ? 'bg-green-50 text-green-800 border-l-4 border-green-600' : 'bg-red-50 text-red-700 border-l-4 border-red-600' ?>"><?= kb_h($flash['msg']) ?></div>
    <?php endif; ?>
    <?php if (!$configured): ?>
    <div class="mb-4 p-3 rounded-lg bg-yellow-50 text-yellow-800 border-l-4 border-yellow-500">
        KYC Bank is not configured. Set <?= kb_h(implode(', ', KycBankClient::missingConfig())) ?> to send requests. Stored requests are still shown.
    </div>
    <?php endif; ?>
    <?php if ($schemaError || $loadError): ?>
    <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-700 border-l-4 border-red-600"><?= kb_h($schemaError ?: $loadError) ?></div>
    <?php endif; ?>

<?php if (!$userId): /* ============================ customer list */ ?>
    <div class="card p-6 rounded-xl shadow-lg">
        <form method="GET" class="flex gap-2 mb-4">
            <input class="input" type="search" name="q" value="<?= kb_h($q) ?>" placeholder="Search by name, email, phone or customer id">
            <button class="btn-dark px-4 rounded-lg font-semibold">Search</button>
        </form>
        <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-2 pr-3">ID</th><th class="py-2 pr-3">Customer</th><th class="py-2 pr-3">Phone</th>
                    <th class="py-2 pr-3">KYC status</th><th class="py-2 pr-3">KYC Bank</th><th class="py-2"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($customers as $c): ?>
                <tr class="border-b last:border-0">
                    <td class="py-2 pr-3 text-gray-500"><?= (int)$c['user_id'] ?></td>
                    <td class="py-2 pr-3"><div class="font-semibold text-gray-900"><?= kb_h($c['full_name']) ?></div><div class="text-xs text-gray-500"><?= kb_h($c['email']) ?></div></td>
                    <td class="py-2 pr-3"><?= kb_h($c['phone'] ?: '—') ?></td>
                    <td class="py-2 pr-3"><?= kb_kyc_badge($c['kyc_status']) ?></td>
                    <td class="py-2 pr-3">
                        <?= $c['state'] ? kb_badge($c['state']) : '<span class="text-gray-400 text-xs">no request</span>' ?>
                        <?php if ($c['basis_withdrawn'] && $c['kyc_status'] === 'approved'): ?>
                            <span class="block text-xs font-semibold text-red-700 mt-1">KYC basis withdrawn</span>
                        <?php elseif ($c['state'] === 'active' && $c['needs_review']): ?>
                            <span class="block text-xs font-semibold text-yellow-700 mt-1"><?= $c['review_reason'] === 'profile_updated' ? 'Updated profile — needs review' : 'Needs staff review' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 text-right"><a class="text-zurubank font-semibold hover:underline" href="<?= kb_h(kb_self(['user_id' => (int)$c['user_id']])) ?>">Open &rarr;</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$customers && !$loadError && !$schemaError): ?>
                <tr><td colspan="6" class="py-6 text-center text-gray-500">No customers found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <p class="text-xs text-gray-500 mt-3">Showing up to 100 customers, newest first.</p>
    </div>

<?php elseif (!$customer): ?>
    <div class="card p-6 rounded-xl shadow-lg text-gray-700">Customer not found. <a class="text-zurubank font-semibold hover:underline" href="admin_kyc.php">Back to customers</a></div>

<?php elseif ($view === 'profile'): /* ============================ profile view */ ?>
    <?php
    $r = $profileRow;
    $profile = ($r && $r['profile_json'] !== null && $r['state'] !== 'withdrawn') ? json_decode((string)$r['profile_json'], true) : null;
    ?>
    <div class="mb-4"><a class="text-zurubank font-semibold hover:underline text-sm" href="<?= kb_h(kb_self(['user_id' => $userId])) ?>">&larr; <?= kb_h($customer['full_name']) ?></a></div>

    <?php if (!$r): ?>
        <div class="card p-6 rounded-xl shadow-lg text-gray-700">Request not found.</div>
    <?php elseif ($r['state'] === 'withdrawn'): ?>
        <div class="card p-6 rounded-xl shadow-lg border-l-4 border-red-600">
            <h3 class="text-xl font-semibold text-red-700 mb-2">Withdrawn by the customer on <?= kb_h(kb_date($r['withdrawn_at'], false)) ?> — data no longer in use</h3>
            <p class="text-gray-700">ZuruBank keeps the copy it received only for legal record-keeping. It is not shown and is not fetched again.</p>
        </div>
    <?php elseif (!is_array($profile)): ?>
        <div class="card p-6 rounded-xl shadow-lg text-gray-700">No profile has been fetched for this request yet. Once the customer approves, it arrives automatically; you can also refresh the status.</div>
    <?php else: ?>
        <?php $usable = $r['state'] === 'active'; ?>
        <?php if (!$usable): ?>
        <div class="mb-4 p-4 rounded-lg bg-yellow-50 text-yellow-900 border-l-4 border-yellow-500">
            <p class="font-semibold">Not usable: the consent is <?= kb_h($r['state']) ?>.</p>
            <p class="text-sm"><?= kb_h(kb_state_label($r['state'])) ?>. <?= kb_h($r['state'] === 'suspended' ? kb_suspended_reason_label($r['suspended_reason']) : '') ?> Do not rely on this profile for a KYC decision.</p>
        </div>
        <?php elseif ($r['needs_review']): ?>
        <div class="mb-4 p-4 rounded-lg bg-yellow-50 text-yellow-900 border-l-4 border-yellow-500 font-semibold">
            <?= $r['review_reason'] === 'profile_updated' ? 'Updated profile — needs staff review.' : 'New profile — needs staff review.' ?>
            <?php if ($r['review_decision']): ?><span class="font-normal">Previously <?= kb_h($r['review_decision']) ?> on <?= kb_h(kb_date($r['reviewed_at'])) ?> (profile version <?= kb_h($r['reviewed_profile_version'] ?? '?') ?>).</span><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card p-6 rounded-xl shadow-lg mb-6">
            <div class="flex flex-wrap justify-between gap-2 mb-4">
                <h3 class="text-2xl font-semibold text-zurubank">KYC Bank profile</h3>
                <div class="text-sm text-gray-600">Customer KYC status: <?= kb_kyc_badge($customer['kyc_status']) ?></div>
            </div>
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm bg-gray-50 rounded-lg p-3 mb-6">
                <?php foreach ($metaKeys as $k): if (!array_key_exists($k, $profile)) continue; ?>
                <div><dt class="text-gray-500 text-xs"><?= kb_h(kb_label($k)) ?></dt><dd class="font-semibold text-gray-900 break-all"><?= in_array($k, ['verifiedAt'], true) ? kb_h(kb_date((string)$profile[$k])) : kb_render($profile[$k]) ?></dd></div>
                <?php endforeach; ?>
                <div><dt class="text-gray-500 text-xs">Fetched</dt><dd class="font-semibold text-gray-900"><?= kb_h(kb_date($r['profile_fetched_at'])) ?></dd></div>
            </dl>

            <div class="space-y-6">
            <?php foreach ($sectionOrder as $k => $title): if (!array_key_exists($k, $profile)) continue; ?>
                <section>
                    <h4 class="text-lg font-semibold text-gray-800 mb-2 border-b pb-1"><?= kb_h($title) ?></h4>
                    <?= kb_render($profile[$k]) ?>
                </section>
            <?php endforeach; ?>
            <?php foreach ($profile as $k => $v): if (isset($sectionOrder[$k]) || in_array($k, $metaKeys, true)) continue; ?>
                <section>
                    <h4 class="text-lg font-semibold text-gray-800 mb-2 border-b pb-1"><?= kb_h(kb_label((string)$k)) ?></h4>
                    <?= kb_render($v) ?>
                </section>
            <?php endforeach; ?>
            </div>
        </div>

        <?php if ($usable): ?>
        <div class="card p-6 rounded-xl shadow-lg">
            <h3 class="text-lg font-semibold text-zurubank mb-1">Staff decision</h3>
            <p class="text-sm text-gray-600 mb-4">KYC Bank's verification is evidence; ZuruBank makes its own decision. The customer's KYC status changes only when you choose.</p>
            <div class="flex flex-wrap gap-3">
                <form method="POST" onsubmit="return confirm('Approve this customer\'s KYC using this KYC Bank profile?');">
                    <input type="hidden" name="csrf" value="<?= kb_h($csrf) ?>">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="profile_version" value="<?= kb_h($r['profile_version'] ?? '') ?>">
                    <button class="py-2 px-5 rounded-lg font-semibold text-white bg-green-600 hover:bg-green-700 shadow">Approve KYC using this profile</button>
                </form>
                <form method="POST" onsubmit="return confirm('Reject this customer\'s KYC?');">
                    <input type="hidden" name="csrf" value="<?= kb_h($csrf) ?>">
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="profile_version" value="<?= kb_h($r['profile_version'] ?? '') ?>">
                    <button class="py-2 px-5 rounded-lg font-semibold text-white bg-red-600 hover:bg-red-700 shadow">Reject</button>
                </form>
            </div>
            <?php if ($r['review_decision']): ?>
            <p class="text-xs text-gray-500 mt-3">Last decision: <?= kb_h($r['review_decision']) ?> by admin #<?= kb_h($r['reviewed_by']) ?> on <?= kb_h(kb_date($r['reviewed_at'])) ?>.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<?php else: /* ============================ one customer */ ?>
    <?php
    $phoneOnFile = (string)($customer['phone'] ?? '');
    $prefill     = kb_normalize_mobile($phoneOnFile) ?? ($phoneOnFile !== '' ? $phoneOnFile : '+267');
    ?>
    <div class="card p-6 rounded-xl shadow-lg mb-6 flex flex-wrap justify-between gap-3">
        <div>
            <h3 class="text-2xl font-semibold text-zurubank"><?= kb_h($customer['full_name']) ?></h3>
            <p class="text-sm text-gray-600">Customer #<?= (int)$customer['user_id'] ?> · <?= kb_h($customer['email']) ?> · <?= kb_h($phoneOnFile ?: 'no phone on file') ?></p>
        </div>
        <div class="text-sm text-gray-600">KYC status: <?= kb_kyc_badge($customer['kyc_status']) ?></div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        <!-- Request form -->
        <div class="card p-6 rounded-xl shadow-lg lg:col-span-2 self-start">
            <h3 class="text-lg font-semibold text-zurubank mb-1">Request KYC from KYC Bank</h3>
            <p class="text-xs text-gray-500 mb-4">The customer approves in the KYC Bank app. If they have no KYC Bank profile the request simply stays waiting. The number is sent to KYC Bank and not stored by ZuruBank.</p>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf" value="<?= kb_h($csrf) ?>">
                <input type="hidden" name="action" value="request">
                <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium text-gray-700 mb-1">Identify the customer by</legend>
                    <label class="flex items-center gap-2 text-sm"><input type="radio" name="kind" value="mobile" checked> Mobile number</label>
                    <input class="input" name="mobile" value="<?= kb_h($prefill) ?>" placeholder="+26770000001" inputmode="tel" autocomplete="off">
                    <label class="flex items-center gap-2 text-sm pt-1"><input type="radio" name="kind" value="omang"> Omang</label>
                    <input class="input" name="omang" value="" placeholder="9 digits" inputmode="numeric" maxlength="11" autocomplete="off">
                </fieldset>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="purpose">Purpose (shown to the customer)</label>
                    <input class="input mt-1" id="purpose" name="purpose" maxlength="200" required value="<?= kb_h(KB_DEFAULT_PURPOSE) ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700" for="level">Level</label>
                    <select class="input mt-1" id="level" name="level">
                        <option value="basic">Basic</option>
                        <option value="standard">Standard</option>
                    </select>
                </div>
                <button class="w-full py-2 px-4 rounded-lg font-semibold btn-primary shadow disabled:opacity-50" <?= $configured ? '' : 'disabled' ?>>Request KYC from KYC Bank</button>
            </form>
        </div>

        <!-- Requests -->
        <div class="lg:col-span-3 space-y-4">
            <?php if (!$requests): ?>
                <div class="card p-6 rounded-xl shadow-lg text-gray-600">No KYC Bank requests for this customer yet.</div>
            <?php endif; ?>
            <?php foreach ($requests as $r): ?>
            <div class="card p-5 rounded-xl shadow-lg <?= $r['state'] === 'withdrawn' ? 'border-l-4 border-red-600' : '' ?>">
                <div class="flex flex-wrap justify-between items-start gap-2 mb-2">
                    <div>
                        <div class="flex items-center gap-2"><?= kb_badge($r['state']) ?><span class="font-semibold text-gray-900"><?= kb_h(kb_state_label($r['state'])) ?></span></div>
                        <p class="text-xs text-gray-500 mt-1"><?= kb_h($r['consent_id'] ?: 'no consent id yet') ?> · <?= kb_h(ucfirst($r['level'])) ?> · by <?= kb_h($r['identifier_kind']) ?></p>
                    </div>
                    <span class="text-xs text-gray-500">Created <?= kb_h(kb_date($r['created_at'])) ?></span>
                </div>
                <p class="text-sm text-gray-700 mb-2">&ldquo;<?= kb_h($r['purpose']) ?>&rdquo;</p>

                <?php if ($r['state'] === 'withdrawn'): ?>
                    <p class="text-sm font-semibold text-red-700">Withdrawn by the customer on <?= kb_h(kb_date($r['withdrawn_at'], false)) ?> — data no longer in use.</p>
                    <?php if ($r['review_decision'] === 'approved'): ?>
                    <div class="mt-2 p-3 rounded-lg bg-red-50 text-red-800 border border-red-200 text-sm">
                        <strong>Warning: KYC basis withdrawn.</strong> Staff approved this customer's KYC on <?= kb_h(kb_date($r['reviewed_at'], false)) ?> using this consent. The customer has withdrawn it; their KYC status was not changed automatically. Review the customer's KYC.
                    </div>
                    <?php endif; ?>
                <?php elseif ($r['state'] === 'suspended'): ?>
                    <p class="text-sm text-yellow-800"><?= kb_h(kb_suspended_reason_label($r['suspended_reason'])) ?></p>
                <?php endif; ?>

                <?php if ($r['state'] === 'active' && $r['needs_review']): ?>
                    <p class="text-sm font-semibold text-yellow-700"><?= $r['review_reason'] === 'profile_updated' ? 'Updated profile — needs staff review.' : 'Profile received — needs staff review.' ?></p>
                <?php elseif ($r['review_decision'] && $r['state'] !== 'withdrawn'): ?>
                    <p class="text-sm text-gray-700">Staff <?= kb_h($r['review_decision']) ?> KYC on <?= kb_h(kb_date($r['reviewed_at'])) ?> (profile version <?= kb_h($r['reviewed_profile_version'] ?? '?') ?>).</p>
                <?php endif; ?>

                <dl class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1 text-xs text-gray-600 mt-3">
                    <div><dt class="text-gray-400">Requested</dt><dd><?= kb_h(kb_date($r['requested_at'])) ?></dd></div>
                    <div><dt class="text-gray-400">Decided</dt><dd><?= kb_h(kb_date($r['decided_at'])) ?></dd></div>
                    <?php if ($r['state'] === 'requested'): ?>
                    <div><dt class="text-gray-400">Lapses</dt><dd><?= kb_h(kb_date($r['lapses_at'])) ?></dd></div>
                    <?php else: ?>
                    <div><dt class="text-gray-400">Sharing</dt><dd><?= kb_h(kb_date($r['starts_at'], false)) ?> – <?= kb_h(kb_date($r['ends_at'], false)) ?></dd></div>
                    <?php endif; ?>
                    <?php if ($r['state'] !== 'withdrawn'): ?>
                    <div><dt class="text-gray-400">Profile</dt><dd><?= $r['profile_json'] !== null ? 'v' . kb_h($r['profile_version'] ?? '?') . ', ' . kb_h(kb_date($r['profile_fetched_at'])) : 'not fetched' ?></dd></div>
                    <?php endif; ?>
                    <?php if ($r['last_error_code']): ?>
                    <div><dt class="text-gray-400">Last error</dt><dd class="text-red-700"><?= kb_h($r['last_error_code']) ?></dd></div>
                    <?php endif; ?>
                </dl>

                <div class="flex flex-wrap gap-2 mt-4">
                    <?php if ($r['state'] !== 'withdrawn' && $r['profile_json'] !== null): ?>
                        <a class="py-1.5 px-4 rounded-lg text-sm font-semibold btn-dark" href="<?= kb_h(kb_self(['user_id' => $userId, 'request_id' => (int)$r['id'], 'view' => 'profile'])) ?>">View KYC Bank profile</a>
                    <?php endif; ?>
                    <?php if ($r['state'] !== 'withdrawn' && $r['consent_id']): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= kb_h($csrf) ?>">
                        <input type="hidden" name="action" value="refresh">
                        <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                        <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                        <button class="py-1.5 px-4 rounded-lg text-sm font-semibold border border-gray-300 bg-white hover:bg-gray-50 disabled:opacity-50" <?= $configured ? '' : 'disabled' ?>>Refresh status</button>
                    </form>
                    <?php endif; ?>
                </div>

                <?php if ($r['state'] === 'creating' && !$r['consent_id']): ?>
                <form method="POST" class="mt-4 p-3 bg-gray-50 rounded-lg space-y-2">
                    <p class="text-xs text-gray-600">KYC Bank did not confirm this request (<?= kb_h($r['last_error_code'] ?: 'no answer') ?>). Send it again; the same request is reused, so the customer is not asked twice. Re-enter the <?= $r['identifier_kind'] === 'mobile' ? 'mobile number' : 'Omang number' ?> (it is not stored).</p>
                    <input type="hidden" name="csrf" value="<?= kb_h($csrf) ?>">
                    <input type="hidden" name="action" value="retry">
                    <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <?php if ($r['identifier_kind'] === 'mobile'): ?>
                        <input class="input" name="mobile" value="<?= kb_h($prefill) ?>" inputmode="tel" autocomplete="off" required>
                    <?php else: ?>
                        <input class="input" name="omang" value="" placeholder="9 digits" inputmode="numeric" maxlength="11" autocomplete="off" required>
                    <?php endif; ?>
                    <button class="py-1.5 px-4 rounded-lg text-sm font-semibold btn-primary disabled:opacity-50" <?= $configured ? '' : 'disabled' ?>>Send again</button>
                </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

</div>
</body>
</html>
