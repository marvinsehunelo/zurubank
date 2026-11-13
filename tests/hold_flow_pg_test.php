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
$SKIPPED = [];

// Older than the held-balance change and left alone on purpose: auth.php and
// helpers/crypto.php both define generate_auth_code() (differently), so any
// script that includes both, such as transaction/reverse.php, dies before
// running. Choosing which definition wins is for a person to decide.
const KNOWN_REDECLARE = 'Cannot redeclare generate_auth_code()';

function skip(string $name, string $why): void
{
    global $SKIPPED;
    $SKIPPED[] = $name;
    echo "  SKIP {$name} -- {$why}\n";
}

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

// =====================================================================
// PART A: the migration on old-shape data
// =====================================================================
section('A. migration on data in the old shape (holds already taken out of balance)');

$pdo->exec("
    INSERT INTO users (user_id, full_name, phone) VALUES
        (1, 'Test Person One', '0000000001'),
        (2, 'Test Person Two', '0000000002'),
        (3, 'Test Bank System', '0000000003');
    SELECT setval('users_user_id_seq', 3);

    -- Old model: balance already excludes live holds.
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
$serverLog = $ERRLOG !== '' && is_file($ERRLOG) ? (string)file_get_contents($ERRLOG) : '';
if ($code === 500 && $b === null && strpos($serverLog, KNOWN_REDECLARE) !== false) {
    skip('reverse.php refuses to take back a deposit out of held money (409, plain words)',
        'reverse.php dies before running: ' . KNOWN_REDECLARE . ' (auth.php and helpers/crypto.php), older than this change');
} else {
    check('reverse.php refuses to take back a deposit out of held money (409, plain words)',
        $code === 409 && strpos($b['message'] ?? '', HOLD_SPEND_REFUSED) !== false, [$code, $b]);
}
expect_figures($pdo, 'unchanged', $A, 640, 100);
check('original transaction untouched', $pdo->query("SELECT status FROM transactions WHERE trace_number = 'TEST-TRACE-1'")->fetchColumn() === 'completed');
hold_request(['action' => 'RELEASE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H6']);
expect_figures($pdo, 'after release', $A, 640, 0);

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

section('B12. notify_debit.php on the audit_logs shape in backup.sql');
// backup.sql has audit_logs.performed_by INTEGER NOT NULL, but notify_debit.php
// writes the requester's name there. The fixture uses text so the flows above
// can run; this checks what happens if live really has the backup.sql shape:
// the debit must be refused with nothing moved, never half done.
hold_request(['action' => 'PLACE_HOLD', 'asset_type' => 'ACCOUNT', 'account_number' => $A, 'reference' => 'TEST-H7', 'amount' => 50]);
expect_figures($pdo, 'after place', $A, 640, 50);
$pdo->exec("ALTER TABLE audit_logs RENAME TO audit_logs_fixture");
$pdo->exec("CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY, entity VARCHAR(255) NOT NULL, entity_id INTEGER NOT NULL, action VARCHAR(255) NOT NULL,
    category VARCHAR(50) DEFAULT 'system', severity VARCHAR(50) DEFAULT 'info', old_value TEXT, new_value TEXT,
    performed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, performed_by INTEGER NOT NULL, ip_address VARCHAR(50),
    user_agent TEXT, geo_location VARCHAR(255))");
$r = debit_request(['hold_reference' => 'TEST-H7', 'amount' => 50]);
check('debit refused (performed_by is an integer there)',
    ($r['debited'] ?? null) === false && stripos($r['message'] ?? '', 'integer') !== false, $r);
expect_figures($pdo, 'nothing moved', $A, 640, 50);
check('hold still HELD', (hold_row($pdo, 'TEST-H7')['status'] ?? '') === 'HELD');
$pdo->exec("DROP TABLE audit_logs");
$pdo->exec("ALTER TABLE audit_logs_fixture RENAME TO audit_logs");
$r = debit_request(['hold_reference' => 'TEST-H7', 'amount' => 50]);
check('same debit goes through with a text performed_by', ($r['debited'] ?? null) === true, $r);
expect_figures($pdo, 'after debit', $A, 590, 0);

section('C. afterwards');
[$rc, $out] = psql_file($VERIFY);
check('verify returns no rows after every flow', $rc === 0 && trim($out) === '', $out);
$log = $ERRLOG !== '' && is_file($ERRLOG) ? (string)file_get_contents($ERRLOG) : '';
preg_match_all('/PHP (Fatal error|Warning|Notice|Deprecated|Parse error):.*/', $log, $m);
$unexpected = array_values(array_filter(array_unique($m[0]), fn($l) => strpos($l, KNOWN_REDECLARE) === false));
check('no PHP errors, warnings or notices in the server log (other than the known redeclare)', $unexpected === [], array_slice($unexpected, 0, 10));

$skips = count($SKIPPED) ? ', ' . count($SKIPPED) . ' skipped (see SKIP lines)' : '';
echo "\n" . ($FAIL === 0 ? "All {$PASS} passed{$skips}.\n" : "{$FAIL} FAILED, {$PASS} passed{$skips}.\n");
exit($FAIL === 0 ? 0 : 1);
