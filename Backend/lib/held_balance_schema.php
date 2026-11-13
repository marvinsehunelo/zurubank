<?php
/**
 * Backend/lib/held_balance_schema.php — ZURUBANK
 *
 * Makes sure the database has the held-balance schema before code that
 * needs it runs (accounts.held_balance, financial_holds.in_held_balance and
 * the accounts_held_within_balance CHECK).
 *
 * The first time it is needed, the code applies
 * database/migrations/2026_11_12_held_balance.sql itself, so merging (which
 * deploys) is enough and nobody has to run SQL by hand first:
 *
 *   1. cheap check: is accounts.held_balance there? (one catalogue lookup,
 *      once per request);
 *   2. if not, take a PostgreSQL advisory lock, so only one request or cron
 *      run does the work while the others wait for it;
 *   3. check again under the lock (someone else may have just done it);
 *   4. run the migration file: one transaction, its own lock timeout, and it
 *      rolls itself back if any account's available money would change.
 *
 * If it cannot (lock timeout, an overdrawn account with a live hold, no
 * permission to alter the tables), held_balance_ensure() throws
 * HeldBalanceUnavailable with a plain message and writes one clear log
 * line. Callers fail closed on the paths that need held_balance; nothing
 * else on the site depends on it.
 *
 * Call it OUTSIDE a transaction: the migration file runs its own.
 * See docs/HELD_BALANCE.md.
 */

declare(strict_types=1);

const HELD_BALANCE_MIGRATION_LOCK = 8583110; // pg advisory lock key; the crons use 8583101 and 8583102
const HELD_BALANCE_LOCK_WAIT      = '20s';   // how long a request waits for another one's migration
const HELD_BALANCE_UNAVAILABLE    = 'Holds on accounts are unavailable for a moment while the bank finishes an upgrade. Please try again shortly.';

class HeldBalanceUnavailable extends RuntimeException
{
}

function held_balance_migration_file(): string
{
    return dirname(__DIR__, 2) . '/database/migrations/2026_11_12_held_balance.sql';
}

/** Is the held-balance schema in place? One catalogue lookup. */
function held_balance_schema_present(PDO $pdo): bool
{
    $stmt = $pdo->query("
        SELECT EXISTS (
            SELECT 1 FROM pg_attribute
            WHERE  attrelid = to_regclass('accounts') AND attname = 'held_balance' AND NOT attisdropped
        )
    ");
    $v = $stmt->fetchColumn();
    return $v === true || $v === 't' || $v === 1 || $v === '1';
}

/** Are there account holds still placed the old way (taken out of balance)? */
function held_balance_has_unconverted_holds(PDO $pdo): bool
{
    $v = $pdo->query("
        SELECT EXISTS (
            SELECT 1 FROM financial_holds
            WHERE  status = 'HELD' AND account_id IS NOT NULL AND NOT in_held_balance
        )
    ")->fetchColumn();
    return $v === true || $v === 't' || $v === 1 || $v === '1';
}

/**
 * Make sure the schema is there, applying the migration once if not.
 *
 * $convertLeftovers: also run the migration again when old-style account
 * holds are still HELD (placed by the old code while the deploy was
 * switching over). The expiry cron passes TRUE, so those are converted
 * within a minute; requests pass FALSE, so they never pay for it.
 *
 * @throws HeldBalanceUnavailable
 */
function held_balance_ensure(PDO $pdo, bool $convertLeftovers = false): void
{
    static $readyFor = [];
    $key = spl_object_id($pdo);
    if (!empty($readyFor[$key]) && !$convertLeftovers) {
        return;
    }
    if ($pdo->inTransaction()) {
        throw new LogicException('held_balance_ensure() must be called outside a transaction');
    }

    try {
        $present = held_balance_schema_present($pdo);
        if ($present && !($convertLeftovers && held_balance_has_unconverted_holds($pdo))) {
            $readyFor[$key] = true;
            return;
        }
    } catch (PDOException $e) {
        held_balance_fail('could not check the schema', $e);
    }

    $locked = false;
    try {
        // Wait (bounded) for another request that is migrating right now.
        $pdo->exec("SET lock_timeout = '" . HELD_BALANCE_LOCK_WAIT . "'");
        $pdo->query('SELECT pg_advisory_lock(' . HELD_BALANCE_MIGRATION_LOCK . ')')->fetchColumn();
        $locked = true;
        $pdo->exec('SET lock_timeout TO DEFAULT');

        $present = held_balance_schema_present($pdo);
        if ($present && !($convertLeftovers && held_balance_has_unconverted_holds($pdo))) {
            $readyFor[$key] = true; // done by someone else while we waited
            return;
        }

        $sql = @file_get_contents(held_balance_migration_file());
        if ($sql === false || $sql === '') {
            throw new RuntimeException('migration file not found: ' . held_balance_migration_file());
        }

        $started = microtime(true);
        try {
            $pdo->exec($sql); // BEGIN ... COMMIT; rolls itself back on any check failing
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK'); } catch (Throwable $ignore) {}
            throw $e;
        }
        error_log(sprintf('[held_balance] migration %s applied (%s) in %.2fs',
            basename(held_balance_migration_file()), $present ? 'converted leftover old-style holds' : 'first use',
            microtime(true) - $started));
        $readyFor[$key] = true;

    } catch (HeldBalanceUnavailable $e) {
        throw $e;
    } catch (Throwable $e) {
        try { $pdo->exec('SET lock_timeout TO DEFAULT'); } catch (Throwable $ignore) {}
        if ($present) {
            // The schema is there; only the leftover conversion failed.
            // Old-style holds are still settled correctly, so carry on.
            error_log('[held_balance] converting leftover old-style holds failed, will retry: ' . held_balance_reason($e));
            $readyFor[$key] = true;
            return;
        }
        held_balance_fail('migration could not be applied', $e);
    } finally {
        if ($locked) {
            try { $pdo->query('SELECT pg_advisory_unlock(' . HELD_BALANCE_MIGRATION_LOCK . ')')->fetchColumn(); } catch (Throwable $ignore) {}
        }
    }
}

/** held_balance_ensure() as a yes/no, for callers that switch a path off instead of failing the request. */
function held_balance_ready(PDO $pdo): bool
{
    try {
        held_balance_ensure($pdo);
        return true;
    } catch (HeldBalanceUnavailable $e) {
        return false;
    }
}

function held_balance_reason(Throwable $e): string
{
    // The database's own message: no credentials or key material in it.
    $msg = preg_replace('/\s+/', ' ', $e->getMessage());
    return get_class($e) . ': ' . substr((string)$msg, 0, 400);
}

/** @return never */
function held_balance_fail(string $what, Throwable $e): void
{
    error_log('[held_balance] FAILED CLOSED: ' . $what . ' - account holds stay off until it is fixed. '
        . 'Reason: ' . held_balance_reason($e) . '. See docs/HELD_BALANCE.md.');
    throw new HeldBalanceUnavailable(HELD_BALANCE_UNAVAILABLE, 0, $e);
}
