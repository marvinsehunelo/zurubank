<?php
/**
 * tests/hold_flow_pg_test.php
 *
 * Run it through tests/hold_flow_pg_test.sh, which starts a THROWAWAY
 * PostgreSQL 16 cluster, test-only certificates and PHP's built-in server,
 * then calls this file. It refuses to run against anything else.
 *
 * Part A proves database/migrations/2026_11_12_held_balance*.sql on data in
 * the old shape: available money unchanged for every account, a second run
 * changes nothing, verify returns no rows, rollback restores the old
 * figures, the migration can run again, and it refuses an account that
 * would hold more than it has.
 *
 * Part B sends signed requests to the real endpoints and checks balance,
 * held and available in the database after every step: place, over-place,
 * the CHECK, debit, full and partial release, expiry through the cron, a
 * card hold, old-style holds, balance.php, verify_asset.php, reverse.php,
 * and two holds placed at the same moment.
 *
 * Every name, number and key here is made up.
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__);
require_once $ROOT . '/Backend/helpers/CertificateManager.php';
require_once $ROOT . '/Backend/lib/account_holds.php';
require_once $ROOT . '/Backend/lib/held_balance_schema.php';

$BASE     = getenv('HOLD_TEST_BASE_URL') ?: '';
$BASE2    = getenv('HOLD_TEST_BASE_URL_2') ?: '';
$DB_URL   = getenv('DATABASE_URL') ?: '';
$PSQL     = getenv('HOLD_TEST_PSQL_BIN') ?: '';
$PGPORT   = getenv('HOLD_TEST_PGPORT') ?: '';
$DB       = getenv('HOLD_TEST_DB') ?: '';
$API_KEY  = getenv('ZURUBANK_API_KEY') ?: '';
$ERRLOG   = getenv('HOLD_TEST_ERROR_LOG') ?: '';
$MIG      = $ROOT . '/database/migrations/2026_11_12_held_balance.sql';
$VERIFY   = $ROOT . '/database/migrations/2026_11_12_held_balance_verify.sql';
$ROLLBACK = $ROOT . '/database/migrations/2026_11_12_held_balance_rollback.sql';
$FIXTURE  = $ROOT . '/tests/fixtures/hold_flow_schema.sql';

$u = parse_url($DB_URL);
if (getenv('HOLD_TEST_THROWAWAY') !== '1' || ($u['host'] ?? '') !== '127.0.0.1' || $BASE === '' || $BASE2 === '' || $PSQL === '') {
    fwrite(STDERR, "Refusing to run: start this through tests/hold_flow_pg_test.sh (throwaway local database only).\n");
    exit(2);
}

// ---------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------

$PASS = 0;
$FAIL = 0;

function check(string $name, bool $ok, $detail = null): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  ok   {$name}\n";
    } else {
        $FAIL++;
        echo "  FAIL {$name}" . ($detail === null ? '' : ' -- ' . (is_string($detail) ? $detail : json_encode($detail))) . "\n";
    }
}

function section(string $title): void
{
    echo "\n== {$title}\n";
}

function same_money($a, $b): bool
{
    return abs((float)$a - (float)$b) < 0.00005;
}

function db(string $dbname = ''): PDO
{
    global $u, $DB;
    $name = $dbname !== '' ? $dbname : $DB;
    return new PDO("pgsql:host={$u['host']};port={$u['port']};dbname={$name}", $u['user'] ?? 'postgres', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** Run a SQL file with psql. Returns [exit code, output]. */
function psql_file(string $file, string $dbname = ''): array
{
    global $PSQL, $PGPORT, $DB;
    $name = $dbname !== '' ? $dbname : $DB;
    $cmd = escapeshellarg($PSQL) . ' -X -q -t -A -h 127.0.0.1 -p ' . escapeshellarg($PGPORT)
        . ' -U postgres -d ' . escapeshellarg($name) . ' -v ON_ERROR_STOP=1 -f ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
}

/** psql output without NOTICE lines (the "already exists, skipping" ones). */
function without_notices(string $out): string
{
    $lines = array_filter(explode("\n", $out), fn($l) => trim($l) !== '' && strpos($l, 'NOTICE:') === false);
    return implode("\n", $lines);
}

function has_column(PDO $pdo, string $table, string $column): bool
{
    $s = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?");
    $s->execute([$table, $column]);
    return (bool)$s->fetchColumn();
}

function has_check(PDO $pdo): bool
{
    return (bool)$pdo->query("SELECT 1 FROM pg_constraint WHERE conname = 'accounts_held_within_balance'")->fetchColumn();
}

/** Every account's figures and every hold's state, for comparing before and after. */
function snapshot(PDO $pdo): array
{
    $held = has_column($pdo, 'accounts', 'held_balance') ? 'held_balance' : '0';
    $flag = has_column($pdo, 'financial_holds', 'in_held_balance') ? 'in_held_balance' : 'FALSE';
    $accounts = [];
    foreach ($pdo->query("SELECT account_id, balance, {$held} AS held FROM accounts ORDER BY account_id") as $r) {
        $accounts[$r['account_id']] = ['balance' => hold_money($r['balance']), 'held' => hold_money($r['held']),
            'available' => hold_available($r['balance'], $r['held'])];
    }
    $holds = [];
    foreach ($pdo->query("SELECT id, status, {$flag} AS flag FROM financial_holds ORDER BY id") as $r) {
        $holds[$r['id']] = ['status' => $r['status'], 'flag' => (bool)$r['flag']];
    }
    return ['accounts' => $accounts, 'holds' => $holds];
}

function figures(PDO $pdo, string $accountNumber): array
{
    $s = $pdo->prepare("SELECT balance, held_balance FROM accounts WHERE account_number = ?");
    $s->execute([$accountNumber]);
    $r = $s->fetch();
    return ['balance' => hold_money($r['balance']), 'held' => hold_money($r['held_balance']),
        'available' => hold_available($r['balance'], $r['held_balance'])];
}

function expect_figures(PDO $pdo, string $label, string $accountNumber, float $balance, float $held): void
{
    $f = figures($pdo, $accountNumber);
    check("{$label}: balance {$balance}, held {$held}, available " . ($balance - $held),
        same_money($f['balance'], $balance) && same_money($f['held'], $held), $f);
}

function hold_row(PDO $pdo, string $ref): ?array
{
    $s = $pdo->prepare("SELECT * FROM financial_holds WHERE hold_reference = ?");
    $s->execute([$ref]);
    $r = $s->fetch();
    return $r ?: null;
}

function signed(array $payload): array
{
    return (new CertificateManager('VOUCHMORPH'))->createSignedRequest($payload, 'VOUCHMORPH');
}

/** POST (or GET with $body = null) to the test server. Returns [http code, decoded body, raw body]. */
function http(string $method, string $path, ?array $body = null, array $headers = []): array
{
    global $BASE;
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true), $raw];
}

function hold_request(array $payload): array
{
    return http('POST', '/Backend/api/v1/hold.php', signed($payload))[1] ?? [];
}

function debit_request(array $payload): array
{
    return http('POST', '/Backend/api/v1/settlement/notify_debit.php', signed($payload))[1] ?? [];
}

/**
 * Is the response signed by the ZURUBANK test certificate the way
 * send_signed_response() signs it (every field but signature and
 * certificate, keys sorted)?
 */
function response_signature_ok(array $resp): bool
{
    $cert = getenv('ZURUBANK_CERT_CONTENT');
    if (empty($resp['signature']) || ($resp['certificate'] ?? null) !== $cert) {
        return false;
    }
    $payload = $resp;
    unset($payload['signature'], $payload['certificate']);
    ksort($payload);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return openssl_verify($json, base64_decode($resp['signature']), openssl_pkey_get_public($cert), OPENSSL_ALGO_SHA256) === 1;
}

function has_keys(array $resp, array $keys): array
{
    return array_values(array_diff($keys, array_keys($resp)));
}

// Fields VouchMorph could read before this change; they must all still be there.
const PLACE_KEYS = ['status', 'hold_placed', 'message', 'hold_reference', 'asset_type', 'asset_id', 'account_number',
    'amount', 'currency', 'total_balance', 'hold_id', 'verification_method', 'timestamp', 'requester',
    'signature_verified', 'signature', 'certificate'];
const RELEASE_KEYS = ['status', 'hold_placed', 'message', 'hold_reference', 'asset_type', 'asset_id', 'amount',
    'captured_amount', 'full_held_amount', 'is_partial', 'total_balance', 'verification_method', 'timestamp',
    'requester', 'signature_verified', 'signature', 'certificate'];
const HOLD_DEBIT_KEYS = ['status', 'debited', 'hold_reference', 'asset_type', 'asset_id', 'amount', 'total_balance',
    'verification_method', 'timestamp', 'requester', 'signature_verified', 'signature', 'certificate'];
const NOTIFY_KEYS = ['success', 'status', 'debited', 'message', 'hold_reference', 'amount', 'account_id',
    'total_balance', 'available_balance', 'requester', 'signature_verified', 'verification_method', 'timestamp',
    'data', 'signature', 'certificate'];

$pdo = db();

/** Data in the shape the OLD code leaves it: live holds already taken out of balance. */
function seed_old_shape(PDO $p): void
{
    $p->exec("
        INSERT INTO users (user_id, full_name, phone) VALUES
            (1, 'Test Person One', '0000000001'),
            (2, 'Test Person Two', '0000000002'),
            (3, 'Test Bank System', '0000000003');
        SELECT setval('users_user_id_seq', 3);

        INSERT INTO accounts (account_id, user_id, account_number, account_type, balance) VALUES
            (1, 1, 'TEST-MIG-001', 'checking', 700.00),   -- had 1000, live hold 300
            (2, 1, 'TEST-MIG-002', 'savings',    0.00),   -- had 100, live holds 80 + 20
            (3, 2, 'TEST-MIG-003', 'checking', 500.00),   -- nothing held
            (4, 3, 'TEST-MIG-004', 'internal', -40.00);   -- overdrawn bank account, nothing held
        SELECT setval('accounts_account_id_seq', 4);

        INSERT INTO instant_money_wallets (wallet_id, user_id, balance) VALUES (1, 2, 250.00);

        INSERT INTO financial_holds (account_id, wallet_id, amount, hold_reference, status, expires_at) VALUES
            (1,    NULL, 300.00, 'TEST-OLD-HELD-1',     'HELD',     NOW() + INTERVAL '1 day'),
            (1,    NULL,  50.00, 'TEST-OLD-DEBITED-1',  'DEBITED',  NOW() + INTERVAL '1 day'),
            (1,    NULL,  25.00, 'TEST-OLD-RELEASED-1', 'RELEASED', NOW() + INTERVAL '1 day'),
            (2,    NULL,  80.00, 'TEST-OLD-HELD-2',     'HELD',     NOW() + INTERVAL '1 day'),
            (2,    NULL,  20.00, 'TEST-OLD-HELD-3',     'HELD',     NOW() + INTERVAL '1 day'),
            (NULL, 1,     30.00, 'TEST-OLD-WALLET-1',   'HELD',     NOW() + INTERVAL '1 day');
    ");
}

/** What the migration must turn seed_old_shape() into: [balance, held] per account. */
const MIGRATED = [1 => [1000, 300], 2 => [100, 100], 3 => [500, 0], 4 => [-40, 0]];

function check_migrated_figures(array $snap, string $label, array $extraHeld = []): void
{
    foreach (MIGRATED as $id => [$bal, $held]) {
        $held += $extraHeld[$id] ?? 0;
        check("{$label}: account {$id} balance {$bal}, held {$held}",
            same_money($snap['accounts'][$id]['balance'], $bal) && same_money($snap['accounts'][$id]['held'], $held),
            $snap['accounts'][$id]);
    }
}

function log_count(string $needle): int
{
    global $ERRLOG;
    return substr_count(is_file($ERRLOG) ? (string)file_get_contents($ERRLOG) : '', $needle);
}

function run_cron(string $script, string $databaseUrl = ''): array
{
    global $ROOT, $ERRLOG;
    $env = $databaseUrl !== '' ? 'DATABASE_URL=' . escapeshellarg($databaseUrl) . ' ' : '';
    exec($env . 'php -d log_errors=1 -d error_log=' . escapeshellarg($ERRLOG) . ' '
        . escapeshellarg($ROOT . '/Backend/cron/' . $script) . ' 2>&1', $out, $rc);
    return [$rc, implode("\n", $out)];
}

function db_url(string $dbname, string $user = 'postgres'): string
{
    global $u;
    return "postgresql://{$user}:unused@{$u['host']}:{$u['port']}/{$dbname}";
}

$MODE = getenv('HOLD_TEST_MODE') ?: 'manual';
echo "mode: {$MODE}\n";

if ($MODE === 'manual') {
    // =================================================================
    // PART A (manual): the SQL files run by hand on old-shape data
    // =================================================================
    section('A. migration run by hand on data in the old shape (holds already taken out of balance)');
    seed_old_shape($pdo);
    $before = snapshot($pdo);
    check('old shape has no held_balance column', !has_column($pdo, 'accounts', 'held_balance'));
    
    [$rc, $out] = psql_file($MIG);
    check('migration runs', $rc === 0, $out);
    $after = snapshot($pdo);
    
    $unchanged = true;
    foreach ($before['accounts'] as $id => $b) {
        if (!same_money($b['available'], $after['accounts'][$id]['available'])) {
            $unchanged = false;
        }
    }
    check('available money unchanged for every account', $unchanged, ['before' => $before['accounts'], 'after' => $after['accounts']]);
    $expect = [1 => [1000, 300], 2 => [100, 100], 3 => [500, 0], 4 => [-40, 0]];
    foreach ($expect as $id => [$bal, $held]) {
        check("account {$id}: balance {$bal}, held {$held}",
            same_money($after['accounts'][$id]['balance'], $bal) && same_money($after['accounts'][$id]['held'], $held),
            $after['accounts'][$id]);
    }
    $flags = array_map(fn($h) => $h['flag'], $after['holds']);
    check('live account holds marked in_held_balance, settled and wallet holds not',
        $flags === [1 => true, 2 => false, 3 => false, 4 => true, 5 => true, 6 => false], $flags);
    check('CHECK accounts_held_within_balance exists', has_check($pdo));
    
    [$rc, $out] = psql_file($MIG);
    check('second run succeeds', $rc === 0, $out);
    check('second run reports nothing converted', without_notices($out) === '', $out);
    check('second run changes nothing', snapshot($pdo) === $after);
    
    [$rc, $out] = psql_file($VERIFY);
    check('verify returns no rows', $rc === 0 && trim($out) === '', $out);
    
    [$rc, $out] = psql_file($ROLLBACK);
    check('rollback runs', $rc === 0, $out);
    $rolled = snapshot($pdo);
    check('rollback restores every balance and hold flag', $rolled === $before, ['before' => $before, 'rolled back' => $rolled]);
    check('rollback drops the CHECK', !has_check($pdo));
    [$rc, $out] = psql_file($ROLLBACK);
    check('rollback twice changes nothing', $rc === 0 && snapshot($pdo) === $before, $out);
    
    [$rc, $out] = psql_file($MIG);
    check('migration runs again after rollback', $rc === 0, $out);
    check('...and gives the same figures as the first run', snapshot($pdo) === $after);
    
    // The code goes live straight after the migration, but the old code may
    // place one more hold in between. The second migration run picks it up.
    $pdo->exec("UPDATE accounts SET balance = balance - 50 WHERE account_id = 3");
    $pdo->exec("INSERT INTO financial_holds (account_id, amount, hold_reference, status, expires_at)
                VALUES (3, 50.00, 'TEST-GAP-HELD-1', 'HELD', NOW() + INTERVAL '1 day')");
    [$rc, $out] = psql_file($VERIFY);
    check('verify lists a hold the old code placed after the migration', strpos($out, 'TEST-GAP-HELD-1') !== false, $out);
    [$rc, $out] = psql_file($MIG);
    check('migration run again converts it', $rc === 0 && strpos(without_notices($out), '3|1|50.0000') === 0, $out);
    expect_figures($pdo, 'account 3 after the second run', 'TEST-MIG-003', 500, 50);
    [$rc, $out] = psql_file($VERIFY);
    check('verify returns no rows again', $rc === 0 && trim($out) === '', $out);
    
    section('A2. migration refuses an overdrawn account with a live hold');
    $abortDb = $DB . '_abort';
    $pdo->exec("CREATE DATABASE {$abortDb}");
    [$rc, $out] = psql_file($FIXTURE, $abortDb);
    check('second throwaway database ready', $rc === 0, $out);
    $pdoAbort = db($abortDb);
    $pdoAbort->exec("
        INSERT INTO accounts (account_id, user_id, account_number, balance) VALUES (1, 1, 'TEST-ABORT-001', -5.00), (2, 1, 'TEST-ABORT-002', 90.00);
        INSERT INTO financial_holds (account_id, amount, hold_reference, status) VALUES (1, 10.00, 'TEST-ABORT-HELD-1', 'HELD'), (2, 10.00, 'TEST-ABORT-HELD-2', 'HELD');
    ");
    $abortBefore = snapshot($pdoAbort);
    [$rc, $out] = psql_file($MIG, $abortDb);
    check('migration stops with a clear message', $rc !== 0 && strpos($out, 'would hold more than their balance') !== false, $out);
    check('...and changes nothing (no column, same figures)',
        !has_column($pdoAbort, 'accounts', 'held_balance') && snapshot($pdoAbort) === $abortBefore);
    $pdoAbort = null;
    $pdo->exec("DROP DATABASE {$abortDb}");
} else {
    // =================================================================
    // PART A (auto): nobody runs SQL; the code migrates on first use
    // =================================================================
    section('A. no SQL run by hand: first, a migration that cannot be applied');
    seed_old_shape($pdo);
    // An overdrawn account with a live old-style hold: the migration must refuse.
    $pdo->exec("
        INSERT INTO accounts (account_id, user_id, account_number, balance) VALUES (5, 3, 'TEST-MIG-005', -5.00);
        SELECT setval('accounts_account_id_seq', 5);
        INSERT INTO financial_holds (account_id, amount, hold_reference, status, expires_at)
            VALUES (5, 10.00, 'TEST-OLD-HELD-5', 'HELD', NOW() + INTERVAL '1 day');
        INSERT INTO instant_money_vouchers (voucher_number, voucher_pin, amount, recipient_phone, status)
            VALUES ('900000000001', '1234', 75.00, '0000000002', 'active');
    ");
    $before = snapshot($pdo);
    check('old shape has no held_balance column', !has_column($pdo, 'accounts', 'held_balance'));

    $r = hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => 'TEST-MIG-003',
        'reference' => 'TEST-AUTO-0', 'amount' => 10]);
    check('account hold refused in plain words', ($r['hold_placed'] ?? null) === false && ($r['message'] ?? '') === HELD_BALANCE_UNAVAILABLE, $r);
    check('...nothing changed (no column, same figures)', !has_column($pdo, 'accounts', 'held_balance') && snapshot($pdo) === $before);
    check('...and a clear log line says why', log_count('[held_balance] FAILED CLOSED') >= 1 && log_count('would hold more than their balance') >= 1);
    [$code, $b] = http('POST', '/Backend/api/v1/verify_asset.php', ['asset_type' => 'ACCOUNT', 'source_identifier' => 'TEST-MIG-003', 'amount' => 1]);
    check('verify_asset.php refuses an account in plain words', ($b['verified'] ?? null) === false && ($b['message'] ?? '') === HELD_BALANCE_UNAVAILABLE, $b);
    [$code, $b] = http('GET', '/Backend/api/v1/accounts/balance.php?account_number=TEST-MIG-003', null, ['X-API-KEY: ' . $API_KEY]);
    check('balance.php answers 503 in plain words for an account', $code === 503 && ($b['message'] ?? '') === HELD_BALANCE_UNAVAILABLE, [$code, $b]);
    $r = hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'VOUCHER', 'voucher_number' => '900000000001', 'reference' => 'TEST-V1', 'amount' => 75]);
    check('the rest keeps working: voucher hold placed', ($r['hold_placed'] ?? null) === true, $r);
    $r = hold_request(['action' => 'RELEASE_HOLD', 'asset_type' => 'VOUCHER', 'voucher_number' => '900000000001', 'reference' => 'TEST-V1']);
    check('...and released', ($r['status'] ?? '') === 'SUCCESS', $r);
    [$rc, $out] = run_cron('expire_holds.php');
    check('expiry cron fails closed too, nothing changed', $rc !== 0 && snapshot($pdo) === $before, $out);

    // Someone settles the bad account the old way; the next request migrates.
    $pdo->exec("UPDATE financial_holds SET status = 'RELEASED', released_at = NOW() WHERE hold_reference = 'TEST-OLD-HELD-5';
                UPDATE accounts SET balance = balance + 10 WHERE account_id = 5");
    $before = snapshot($pdo);

    section('A1. two first requests at the same moment');
    // The test holds the migration lock, sends one request to each server,
    // waits until the database shows BOTH waiting for it, then lets go.
    $locker = db();
    $locker->query('SELECT pg_advisory_lock(' . HELD_BALANCE_MIGRATION_LOCK . ')')->fetchColumn();
    $mh = curl_multi_init();
    $handles = [];
    foreach ([['TEST-AUTO-1', 'TEST-MIG-003', 35, $BASE], ['TEST-AUTO-2', 'TEST-MIG-001', 100, $BASE2]] as [$ref, $acct, $amt, $server]) {
        $ch = curl_init($server . '/Backend/api/v1/hold.php');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(signed(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT',
                'account_number' => $acct, 'reference' => $ref, 'amount' => $amt]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
        curl_multi_add_handle($mh, $ch);
        $handles[$ref] = $ch;
    }
    $watcher = db();
    $deadline = microtime(true) + 20;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.05);
        $waiting = (int)$watcher->query("SELECT COUNT(*) FROM pg_stat_activity
            WHERE wait_event_type = 'Lock' AND wait_event = 'advisory'")->fetchColumn();
    } while ($waiting < 2 && microtime(true) < $deadline);
    check('both first requests were waiting for the migration lock at the same time', $waiting === 2, "waiting={$waiting}");
    $applied = log_count('[held_balance] migration 2026_11_12_held_balance.sql applied');
    $locker->query('SELECT pg_advisory_unlock(' . HELD_BALANCE_MIGRATION_LOCK . ')')->fetchColumn();
    $locker = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.05);
    } while ($running > 0);
    $answers = [];
    foreach ($handles as $ref => $ch) {
        $answers[$ref] = json_decode((string)curl_multi_getcontent($ch), true) ?? [];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    check('both holds placed', ($answers['TEST-AUTO-1']['hold_placed'] ?? null) === true && ($answers['TEST-AUTO-2']['hold_placed'] ?? null) === true, $answers);
    check('the migration was applied exactly once', log_count('[held_balance] migration 2026_11_12_held_balance.sql applied') - $applied === 1);
    $after = snapshot($pdo);
    check_migrated_figures($after, 'after first use', [1 => 100, 3 => 35]);
    $ok = true;
    foreach ($before['accounts'] as $id => $b) {
        $placed = [1 => 100, 3 => 35][$id] ?? 0;
        $ok = $ok && same_money($after['accounts'][$id]['available'], $b['available'] - $placed);
    }
    check('available money unchanged for every account, apart from the two new holds', $ok, ['before' => $before['accounts'], 'after' => $after['accounts']]);
    check('CHECK accounts_held_within_balance exists', has_check($pdo));
    [$rc, $out] = psql_file($VERIFY);
    check('verify returns no rows', $rc === 0 && trim($out) === '', $out);

    section('A2. a hold the old code places during the changeover: the cron converts it');
    $pdo->exec("UPDATE accounts SET balance = balance - 15 WHERE account_id = 3");
    $pdo->exec("INSERT INTO financial_holds (account_id, amount, hold_reference, status, expires_at)
                VALUES (3, 15.00, 'TEST-GAP-HELD-1', 'HELD', NOW() + INTERVAL '1 day')");
    [$rc, $out] = psql_file($VERIFY);
    check('verify lists it', strpos($out, 'TEST-GAP-HELD-1') !== false, $out);
    [$rc, $out] = run_cron('expire_holds.php');
    check('expiry cron runs', $rc === 0, $out);
    check('...and converted it', log_count('applied (converted leftover old-style holds)') === 1 && hold_row($pdo, 'TEST-GAP-HELD-1')['in_held_balance'] === true);
    expect_figures($pdo, 'account 3', 'TEST-MIG-003', 500, 50);
    [$rc, $out] = psql_file($VERIFY);
    check('verify returns no rows again', $rc === 0 && trim($out) === '', $out);
    [$rc, $out] = run_cron('expire_holds.php');
    check('next cron run has nothing to convert', $rc === 0 && log_count('applied (converted leftover old-style holds)') === 1, $out);

    section('A3. the cron is the first to need it');
    $cronDb = $DB . '_cronfirst';
    $pdo->exec("CREATE DATABASE {$cronDb}");
    [$rc, $out] = psql_file($FIXTURE, $cronDb);
    $pdoCron = db($cronDb);
    seed_old_shape($pdoCron);
    [$rc, $out] = run_cron('expire_holds.php', db_url($cronDb));
    check('expiry cron applies the migration and runs', $rc === 0 && has_column($pdoCron, 'accounts', 'held_balance'), $out);
    check_migrated_figures(snapshot($pdoCron), 'cron first');
    [$rc, $out] = psql_file($VERIFY, $cronDb);
    check('verify returns no rows', $rc === 0 && trim($out) === '', $out);
    $pdoCron = null;
    $pdo->exec("DROP DATABASE {$cronDb}");

    section('A4. no permission to alter the tables');
    $permDb = $DB . '_noperm';
    $pdo->exec("CREATE DATABASE {$permDb}");
    psql_file($FIXTURE, $permDb);
    $pdoPerm = db($permDb);
    seed_old_shape($pdoPerm);
    $pdoPerm->exec("DROP ROLE IF EXISTS zuru_test_limited; CREATE ROLE zuru_test_limited LOGIN;
        GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO zuru_test_limited;
        GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO zuru_test_limited;");
    $permBefore = snapshot($pdoPerm);
    $limited = new PDO("pgsql:host={$u['host']};port={$u['port']};dbname={$permDb}", 'zuru_test_limited', '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    try {
        held_balance_ensure($limited);
        check('refused without permission', false, 'held_balance_ensure() returned');
    } catch (HeldBalanceUnavailable $e) {
        check('refused without permission, in plain words', $e->getMessage() === HELD_BALANCE_UNAVAILABLE);
    }
    check('...nothing changed and the lock was let go',
        snapshot($pdoPerm) === $permBefore && !has_column($pdoPerm, 'accounts', 'held_balance')
        && (int)$pdoPerm->query("SELECT COUNT(*) FROM pg_locks l JOIN pg_database d ON d.oid = l.database
                                 WHERE l.locktype = 'advisory' AND d.datname = current_database()")->fetchColumn() === 0);
    check('...and the connection is usable again', (int)$limited->query('SELECT 1')->fetchColumn() === 1);
    $limited = null;
    $pdoPerm->exec("REVOKE ALL ON ALL TABLES IN SCHEMA public FROM zuru_test_limited; REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM zuru_test_limited;");
    $pdoPerm = null;
    $pdo->exec("DROP DATABASE {$permDb}");
    $pdo->exec("DROP ROLE zuru_test_limited");
}

// =====================================================================
// PART B: the endpoints, after the migration
// =====================================================================
$pdo->exec("
    INSERT INTO accounts (user_id, account_number, account_type, balance) VALUES
        (1, 'TEST-HOLD-001', 'checking', 1000.00),
        (2, 'TEST-HOLD-002', 'checking',  500.00),
        (2, 'TEST-HOLD-003', 'checking',  100.00);
    INSERT INTO cards (account_id, card_number, status)
        SELECT account_id, '9990000000000001', 'ACTIVE' FROM accounts WHERE account_number = 'TEST-HOLD-002';
");
$A = 'TEST-HOLD-001';

section('B1. place');
$r = hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A,
    'reference' => 'TEST-H1', 'amount' => 300]);
check('hold placed', ($r['hold_placed'] ?? null) === true && ($r['hold_reference'] ?? null) === 'TEST-H1', $r);
check('every field the old answer had is still there', has_keys($r, PLACE_KEYS) === [], has_keys($r, PLACE_KEYS));
check('answer is signed by the Zurubank certificate', response_signature_ok($r));
check('answer reports total 1000, held 300, available 700',
    same_money($r['total_balance'] ?? -1, 1000) && same_money($r['held_balance'] ?? -1, 300) && same_money($r['available_balance'] ?? -1, 700), $r);
expect_figures($pdo, 'after place', $A, 1000, 300);
$h = hold_row($pdo, 'TEST-H1');
check('hold row HELD and counted in held_balance', $h && $h['status'] === 'HELD' && $h['in_held_balance'] === true, $h);

section('B2. over-place');
$r = hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A,
    'reference' => 'TEST-H2', 'amount' => 700.01]);
check('refused: more than available', ($r['hold_placed'] ?? null) === false && stripos($r['message'] ?? '', 'Insufficient') !== false, $r);
check('no hold row written', hold_row($pdo, 'TEST-H2') === null);
expect_figures($pdo, 'unchanged', $A, 1000, 300);
$r = hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A,
    'reference' => 'TEST-H1', 'amount' => 10]);
check('refused: same reference twice', ($r['hold_placed'] ?? null) === false, $r);
expect_figures($pdo, 'unchanged', $A, 1000, 300);

section('B3. the CHECK constraint');
foreach (["UPDATE accounts SET balance = 299.99 WHERE account_number = '{$A}'" => 'spending held money',
          "UPDATE accounts SET held_balance = -1 WHERE account_number = 'TEST-MIG-003'" => 'a negative held_balance']
         as $sql => $what) {
    try {
        $pdo->exec($sql);
        check("database refuses {$what}", false, 'UPDATE succeeded');
    } catch (PDOException $e) {
        check("database refuses {$what}", hold_is_held_money_refusal($e), $e->getMessage());
        check("...and the code turns it into plain words", hold_refusal_message($e) === HOLD_SPEND_REFUSED);
    }
}
$pdo->exec("UPDATE accounts SET balance = 300 WHERE account_number = '{$A}'");
check('spending down to exactly the held amount is allowed', same_money(figures($pdo, $A)['balance'], 300));
$pdo->exec("UPDATE accounts SET balance = 1000 WHERE account_number = '{$A}'");
$pdo->exec("UPDATE accounts SET balance = -41 WHERE account_number = 'TEST-MIG-004'");
check('an account with nothing held can still go negative', same_money(figures($pdo, 'TEST-MIG-004')['balance'], -41));
$pdo->exec("UPDATE accounts SET balance = -40 WHERE account_number = 'TEST-MIG-004'");

section('B4. debit (notify_debit.php)');
$r = debit_request(['hold_reference' => 'TEST-H1', 'amount' => 300, 'transaction_reference' => 'TEST-TX-1']);
check('debited', ($r['debited'] ?? null) === true && ($r['hold_reference'] ?? null) === 'TEST-H1', $r);
check('every field the old answer had is still there', has_keys($r, NOTIFY_KEYS) === [], has_keys($r, NOTIFY_KEYS));
check('answer is signed', response_signature_ok($r));
check('answer reports total 700, held 0, available 700',
    same_money($r['total_balance'] ?? -1, 700) && same_money($r['held_balance'] ?? -1, 0) && same_money($r['available_balance'] ?? -1, 700), $r);
expect_figures($pdo, 'after debit (money left once)', $A, 700, 0);
check('hold row DEBITED', (hold_row($pdo, 'TEST-H1')['status'] ?? '') === 'DEBITED');
$audit = $pdo->query("SELECT a.performed_by, a.new_value FROM audit_logs a JOIN financial_holds h ON h.id = a.entity_id
                      WHERE a.entity = 'financial_holds' AND a.action = 'DEBIT' AND h.hold_reference = 'TEST-H1'")->fetch();
check('audit row written with the requester (text column)', $audit && $audit['performed_by'] === 'VOUCHMORPH', $audit);
$r = debit_request(['hold_reference' => 'TEST-H1', 'amount' => 300]);
check('second debit of the same hold refused', ($r['debited'] ?? null) === false, $r);
expect_figures($pdo, 'unchanged', $A, 700, 0);

section('B5. full release');
hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H3', 'amount' => 200]);
expect_figures($pdo, 'after place', $A, 700, 200);
$r = hold_request(['action' => 'RELEASE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H3']);
check('released', ($r['status'] ?? '') === 'SUCCESS' && ($r['hold_placed'] ?? null) === false && ($r['is_partial'] ?? null) === false, $r);
check('every field the old answer had is still there', has_keys($r, RELEASE_KEYS) === [], has_keys($r, RELEASE_KEYS));
check('answer is signed', response_signature_ok($r));
check('answer reports 200 released, nothing captured', same_money($r['amount'] ?? -1, 200) && same_money($r['captured_amount'] ?? -1, 0), $r);
expect_figures($pdo, 'after release (balance never moved)', $A, 700, 0);
check('hold row RELEASED', (hold_row($pdo, 'TEST-H3')['status'] ?? '') === 'RELEASED');
$r = hold_request(['action' => 'RELEASE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H3']);
check('second release refused', ($r['status'] ?? '') === 'ERROR', $r);
expect_figures($pdo, 'unchanged', $A, 700, 0);

section('B6. partial release');
hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H4', 'amount' => 100]);
$r = hold_request(['action' => 'RELEASE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H4', 'amount' => 40]);
check('partially released: 40 back, 60 captured',
    ($r['is_partial'] ?? null) === true && same_money($r['amount'] ?? -1, 40) && same_money($r['captured_amount'] ?? -1, 60), $r);
expect_figures($pdo, 'after partial release', $A, 640, 0);
check('hold row PARTIALLY_RELEASED', (hold_row($pdo, 'TEST-H4')['status'] ?? '') === 'PARTIALLY_RELEASED');

section('B7. expiry (Backend/cron/expire_holds.php), new and old-style holds');
hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H5', 'amount' => 50]);
expect_figures($pdo, 'after place', $A, 640, 50);
// An old-style hold on account 3, as the old code would have left it: out of balance, flag FALSE.
$pdo->exec("UPDATE accounts SET balance = balance - 30 WHERE account_number = 'TEST-MIG-003'");
$pdo->exec("INSERT INTO financial_holds (account_id, amount, hold_reference, status, expires_at, in_held_balance)
            VALUES (3, 30.00, 'TEST-LEGACY-EXP', 'HELD', NOW() + INTERVAL '1 day', FALSE)");
expect_figures($pdo, 'account 3 with an old-style hold', 'TEST-MIG-003', 470, 50);
$pdo->exec("UPDATE financial_holds SET expires_at = NOW() - INTERVAL '1 minute' WHERE hold_reference IN ('TEST-H5', 'TEST-LEGACY-EXP')");
exec('php -d error_log=' . escapeshellarg($ERRLOG) . ' ' . escapeshellarg($ROOT . '/Backend/cron/expire_holds.php') . ' 2>&1', $cronOut, $cronRc);
check('cron exits 0', $cronRc === 0, implode("\n", $cronOut));
expect_figures($pdo, 'after expiry (balance never moved)', $A, 640, 0);
expect_figures($pdo, 'old-style hold expired: added back to balance', 'TEST-MIG-003', 500, 50);
$h = hold_row($pdo, 'TEST-H5');
check('hold row EXPIRED, reason EXPIRED', $h && $h['status'] === 'EXPIRED' && $h['release_reason'] === 'EXPIRED', $h);
$log = $pdo->query("SELECT balance_before, balance_after FROM hold_release_log WHERE hold_reference = 'TEST-H5'")->fetch();
check('release log records available 590 -> 640', $log && same_money($log['balance_before'], 590) && same_money($log['balance_after'], 640), $log);
$log = $pdo->query("SELECT balance_before, balance_after FROM hold_release_log WHERE hold_reference = 'TEST-LEGACY-EXP'")->fetch();
check('release log records available 420 -> 450 for the old-style hold', $log && same_money($log['balance_before'], 420) && same_money($log['balance_after'], 450), $log);
check('cron run logged', (int)$pdo->query("SELECT COUNT(*) FROM cron_runs WHERE job = 'expire_holds' AND processed = 2 AND failed = 0")->fetchColumn() === 1);

section('B8. card hold, debited through hold.php');
$card = '9990000000000001';
$r = hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'CARD', 'source_identifier' => $card, 'reference' => 'TEST-C1', 'amount' => 120]);
check('card hold placed on the linked account', ($r['hold_placed'] ?? null) === true && ($r['account_number'] ?? '') === 'TEST-HOLD-002', $r);
expect_figures($pdo, 'after card hold', 'TEST-HOLD-002', 500, 120);
$r = hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'CARD', 'source_identifier' => $card, 'reference' => 'TEST-C2', 'amount' => 380.01]);
check('second card hold above available refused', ($r['hold_placed'] ?? null) === false, $r);
$r = hold_request(['action' => 'DEBIT', 'asset_type' => 'CARD', 'source_identifier' => $card, 'reference' => 'TEST-C1', 'amount' => 120]);
check('debited through hold.php', ($r['debited'] ?? null) === true, $r);
check('every field the old answer had is still there', has_keys($r, HOLD_DEBIT_KEYS) === [], has_keys($r, HOLD_DEBIT_KEYS));
check('answer is signed', response_signature_ok($r));
expect_figures($pdo, 'after debit (taken once, not twice)', 'TEST-HOLD-002', 380, 0);

section('B9. old-style holds settled by the new code');
// Two holds as the old code leaves them between migration and deploy.
$pdo->exec("UPDATE accounts SET balance = balance - 100 WHERE account_number = 'TEST-MIG-003'");
$pdo->exec("INSERT INTO financial_holds (account_id, amount, hold_reference, status, expires_at, in_held_balance) VALUES
            (3, 60.00, 'TEST-LEGACY-1', 'HELD', NOW() + INTERVAL '1 day', FALSE),
            (3, 40.00, 'TEST-LEGACY-2', 'HELD', NOW() + INTERVAL '1 day', FALSE)");
expect_figures($pdo, 'account 3 with two old-style holds', 'TEST-MIG-003', 400, 50);
$r = debit_request(['hold_reference' => 'TEST-LEGACY-1', 'amount' => 60]);
check('old-style hold debited', ($r['debited'] ?? null) === true, $r);
expect_figures($pdo, 'nothing moves (it was already out of balance)', 'TEST-MIG-003', 400, 50);
$r = hold_request(['action' => 'RELEASE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => 'TEST-MIG-003', 'reference' => 'TEST-LEGACY-2']);
check('old-style hold released', ($r['status'] ?? '') === 'SUCCESS', $r);
expect_figures($pdo, 'its amount comes back to balance', 'TEST-MIG-003', 440, 50);
// An old-style hold on an overdrawn bank account: giving it back still
// leaves the account below zero, which is allowed while nothing is held.
$pdo->exec("UPDATE accounts SET balance = balance - 10 WHERE account_number = 'TEST-MIG-004'");
$pdo->exec("INSERT INTO financial_holds (account_id, amount, hold_reference, status, expires_at, in_held_balance)
            VALUES (4, 10.00, 'TEST-LEGACY-3', 'HELD', NOW() + INTERVAL '1 day', FALSE)");
$r = hold_request(['action' => 'RELEASE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => 'TEST-MIG-004', 'reference' => 'TEST-LEGACY-3']);
check('old-style hold on an overdrawn account released', ($r['status'] ?? '') === 'SUCCESS', $r);
expect_figures($pdo, 'back to where it was, still overdrawn', 'TEST-MIG-004', -40, 0);

section('B10. balance.php, verify_asset.php and reverse.php see held money');
hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H6', 'amount' => 100]);
expect_figures($pdo, 'after place', $A, 640, 100);
[$code, $b] = http('GET', '/Backend/api/v1/accounts/balance.php?account_number=' . $A, null, ['X-API-KEY: ' . $API_KEY]);
$d = $b['data'] ?? [];
check('balance.php answers 200', $code === 200, $b);
check('balance.php: balance 640, held 100, available 540',
    same_money($d['balance'] ?? -1, 640) && same_money($d['held_balance'] ?? -1, 100)
    && same_money($d['held_amount'] ?? -1, 100) && same_money($d['available_balance'] ?? -1, 540), $d);
[$code, $b] = http('GET', '/Backend/api/v1/accounts/balance.php?account_number=' . $A, null, ['X-API-KEY: wrong-key']);
check('balance.php refuses a wrong key', $code === 401, $b);

[$code, $b] = http('POST', '/Backend/api/v1/verify_asset.php', ['asset_type' => 'ACCOUNT', 'source_identifier' => $A, 'amount' => 540.01]);
check('verify_asset.php refuses more than available', ($b['verified'] ?? null) === false && stripos($b['message'] ?? '', 'Insufficient') !== false, $b);
[$code, $b] = http('POST', '/Backend/api/v1/verify_asset.php', ['asset_type' => 'ACCOUNT', 'source_identifier' => $A, 'amount' => 540]);
check('verify_asset.php accepts exactly what is available', ($b['verified'] ?? null) === true, $b);
check('verify_asset.php: balance/available 540, total 640, held 100',
    same_money($b['balance'] ?? -1, 540) && same_money($b['available_balance'] ?? -1, 540)
    && same_money($b['total_balance'] ?? -1, 640) && same_money($b['held_balance'] ?? -1, 100), $b);

$pdo->exec("INSERT INTO transactions (user_id, account_id, type, amount, reference, status, trace_number)
            SELECT 1, account_id, 'deposit', 600.00, 'TEST-DEP-1', 'completed', 'TEST-TRACE-1' FROM accounts WHERE account_number = '{$A}'");
[$code, $b] = http('POST', '/Backend/api/v1/transaction/reverse.php',
    signed(['original_trace' => 'TEST-TRACE-1', 'reason' => 'test']), ['X-API-KEY: ' . $API_KEY]);
check('reverse.php refuses to take back a deposit out of held money (409, plain words)',
    $code === 409 && strpos($b['message'] ?? '', HOLD_SPEND_REFUSED) !== false, [$code, $b]);
expect_figures($pdo, 'unchanged', $A, 640, 100);
check('original transaction untouched', $pdo->query("SELECT status FROM transactions WHERE trace_number = 'TEST-TRACE-1'")->fetchColumn() === 'completed');
check('no reversal recorded', (int)$pdo->query("SELECT COUNT(*) FROM transaction_reversals WHERE original_trace = 'TEST-TRACE-1'")->fetchColumn() === 0);
hold_request(['action' => 'RELEASE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H6']);
expect_figures($pdo, 'after release', $A, 640, 0);

// A reversal that is allowed, on the live column set (backup.sql).
$pdo->exec("INSERT INTO transactions (user_id, account_id, type, amount, reference, description, status, trace_number)
            SELECT 2, account_id, 'deposit', 100.00, 'TEST-DEP-2', 'test deposit', 'completed', 'TEST-TRACE-2'
            FROM accounts WHERE account_number = 'TEST-HOLD-002'");
[$code, $b] = http('POST', '/Backend/api/v1/transaction/reverse.php',
    signed(['original_trace' => 'TEST-TRACE-2', 'reason' => 'test reversal']), ['X-API-KEY: ' . $API_KEY]);
check('reverse.php reverses a deposit with nothing held', $code === 200 && ($b['status'] ?? '') === 'success' && response_signature_ok($b ?? []), [$code, $b]);
expect_figures($pdo, 'deposit taken back', 'TEST-HOLD-002', 280, 0);
$t = $pdo->query("SELECT status, description FROM transactions WHERE trace_number = 'TEST-TRACE-2'")->fetch();
check('transaction marked reversed, details in its description', $t && $t['status'] === 'reversed' && strpos($t['description'], 'Reversed') !== false, $t);
check('reversal row recorded', (int)$pdo->query("SELECT COUNT(*) FROM transaction_reversals WHERE original_trace = 'TEST-TRACE-2'")->fetchColumn() === 1);
check('audit row recorded', (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE entity = 'transactions' AND action = 'REVERSE'")->fetchColumn() === 1);
[$code, $b] = http('POST', '/Backend/api/v1/transaction/reverse.php',
    signed(['original_trace' => 'TEST-TRACE-2', 'reason' => 'again']), ['X-API-KEY: ' . $API_KEY]);
check('reversing it again answers duplicate', ($b['status'] ?? '') === 'duplicate', [$code, $b]);
expect_figures($pdo, 'unchanged', 'TEST-HOLD-002', 280, 0);
[$code, $b] = http('GET', '/Backend/api/v1/transaction/status.php?trace=TEST-TRACE-2', null, ['X-API-KEY: ' . $API_KEY]);
check('status.php loads and reports the reversal', $code === 200 && ($b['transaction_status'] ?? '') === 'reversed'
    && strpos((string)($b['reversal_info']['reversal_trace'] ?? ''), 'REV-') === 0, [$code, $b]);

section('B11. two holds at the same moment');
// The test holds the account row lock, sends the two requests to two
// separate server processes, waits until the database shows BOTH waiting
// for that lock, then lets go. So both requests are inside hold.php at the
// same time, whatever the timing; if they are not, the check fails.
$C = 'TEST-HOLD-003';
$locker = db();
$locker->beginTransaction();
$locker->prepare("SELECT account_id FROM accounts WHERE account_number = ? FOR UPDATE")->execute([$C]);

$mh = curl_multi_init();
$handles = [];
foreach (['TEST-R1' => $BASE, 'TEST-R2' => $BASE2] as $ref => $server) {
    $ch = curl_init($server . '/Backend/api/v1/hold.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(signed(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT',
            'account_number' => $C, 'reference' => $ref, 'amount' => 60]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    curl_multi_add_handle($mh, $ch);
    $handles[$ref] = $ch;
}
$watcher = db();
$waiting = 0;
$deadline = microtime(true) + 20;
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
    $waiting = (int)$watcher->query("SELECT COUNT(*) FROM pg_stat_activity
        WHERE wait_event_type = 'Lock' AND query ILIKE '%FROM accounts%FOR UPDATE%'")->fetchColumn();
} while ($waiting < 2 && microtime(true) < $deadline);
check('both requests were waiting on the account at the same time', $waiting === 2,
    ['waiting' => $waiting, 'sessions' => $watcher->query("SELECT pid, state, wait_event_type, wait_event,
        left(regexp_replace(query, '\\s+', ' ', 'g'), 90) AS query FROM pg_stat_activity
        WHERE datname = current_database() AND pid <> pg_backend_pid()")->fetchAll()]);
$locker->commit();
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh, 0.05);
} while ($running > 0);
$placed = 0;
$refusedPlainly = 0;
$answers = [];
foreach ($handles as $ref => $ch) {
    $resp = json_decode((string)curl_multi_getcontent($ch), true) ?? [];
    $answers[$ref] = $resp;
    if (($resp['hold_placed'] ?? null) === true) {
        $placed++;
    } elseif (stripos($resp['message'] ?? '', 'Insufficient') !== false) {
        $refusedPlainly++;
    }
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);
check('exactly one of the two holds placed, the other refused as insufficient', $placed === 1 && $refusedPlainly === 1, $answers);
expect_figures($pdo, 'held once', $C, 100, 60);
check('one live hold row on the account',
    (int)$pdo->query("SELECT COUNT(*) FROM financial_holds f JOIN accounts a USING (account_id)
                      WHERE a.account_number = '{$C}' AND f.status = 'HELD'")->fetchColumn() === 1);

section('B12. notify_debit.php whatever type audit_logs.performed_by has');
// backup.sql has audit_logs.performed_by INTEGER NOT NULL; the fixture has
// text. The audit write must never undo the debit, whichever it is.
hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H7', 'amount' => 50]);
hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H8', 'amount' => 40]);
expect_figures($pdo, 'after two holds', $A, 640, 90);
$pdo->exec("ALTER TABLE audit_logs RENAME TO audit_logs_fixture");
$pdo->exec("CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY, entity VARCHAR(255) NOT NULL, entity_id INTEGER NOT NULL, action VARCHAR(255) NOT NULL,
    category VARCHAR(50) DEFAULT 'system', severity VARCHAR(50) DEFAULT 'info', old_value TEXT, new_value TEXT,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, performed_by INTEGER NOT NULL, ip_address VARCHAR(50),
    user_agent TEXT, geo_location VARCHAR(255))");
$r = debit_request(['hold_reference' => 'TEST-H7', 'amount' => 50]);
check('integer column (backup.sql shape): debit goes through', ($r['debited'] ?? null) === true, $r);
expect_figures($pdo, 'after debit', $A, 590, 40);
$audit = $pdo->query("SELECT performed_by, new_value FROM audit_logs WHERE entity = 'financial_holds' AND action = 'DEBIT'")->fetch();
$nv = $audit ? json_decode((string)$audit['new_value'], true) : null;
check('...audit row kept: performed_by 0, requester inside new_value',
    $audit && (int)$audit['performed_by'] === 0 && ($nv['performed_by'] ?? '') === 'VOUCHMORPH' && ($nv['status'] ?? '') === 'DEBITED', $audit);
$pdo->exec("DROP TABLE audit_logs");
// Same columns, but neither the name nor the stand-in 0 is accepted.
$pdo->exec("CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY, entity VARCHAR(255) NOT NULL, entity_id INTEGER NOT NULL, action VARCHAR(255) NOT NULL,
    category VARCHAR(50), severity VARCHAR(50), old_value TEXT, new_value TEXT, performed_at TIMESTAMP,
    performed_by INTEGER NOT NULL CHECK (performed_by > 0), ip_address VARCHAR(50), user_agent TEXT)");
$r = debit_request(['hold_reference' => 'TEST-H8', 'amount' => 40]);
check('audit write refused outright: debit still goes through', ($r['debited'] ?? null) === true, $r);
expect_figures($pdo, 'after debit', $A, 550, 0);
check('...and it is logged', log_count('[audit_log] audit row not written for financial_holds') === 1);
$pdo->exec("DROP TABLE audit_logs");
$pdo->exec("ALTER TABLE audit_logs_fixture RENAME TO audit_logs");

section('C. afterwards');
[$rc, $out] = psql_file($VERIFY);
check('verify returns no rows after every flow', $rc === 0 && trim($out) === '', $out);
$log = $ERRLOG !== '' && is_file($ERRLOG) ? (string)file_get_contents($ERRLOG) : '';
preg_match_all('/PHP (Fatal error|Warning|Notice|Deprecated|Parse error):.*/', $log, $m);
check('no PHP errors, warnings or notices in the server log', $m[0] === [], array_slice(array_unique($m[0]), 0, 10));
check('no key material in the log', strpos($log, 'PRIVATE KEY') === false && strpos($log, 'first 50 chars') === false);

echo "\n[{$MODE}] " . ($FAIL === 0 ? "All {$PASS} passed.\n" : "{$FAIL} FAILED, {$PASS} passed.\n");
exit($FAIL === 0 ? 0 : 1);
