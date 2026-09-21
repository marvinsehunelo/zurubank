<?php
/**
 * Backend/cron/expire_holds.php — ZURUBANK
 *
 * Releases every expired hold, restoring the amount to the customer's
 * balance, and returns any voucher stuck on hold to 'active'.
 *
 * Nothing here calls VouchMorph or waits for it. That is the point: a
 * hold must come off the customer's money whether or not the orchestrator
 * is running. It is what makes the non-custodial claim true rather than
 * merely asserted.
 *
 * Expiry wins: hold.php DEBIT selects `status = 'HELD' ... FOR UPDATE`.
 * Once this job flips the row to EXPIRED, the debit finds nothing and is
 * rejected. Whichever gets the row lock first wins; never both.
 *
 * Run every minute:
 *   * * * * * sleep 20; /usr/bin/php /var/www/zurubank/Backend/cron/expire_holds.php >> /var/log/zurubank/expire_holds.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/hold_release.php';

const JOB        = 'expire_holds';
const BATCH_SIZE = 200;
const LOCK_KEY   = 8583101;

$startedAt = microtime(true);
$released  = 0;
$vouchers  = 0;
$skipped   = 0;
$failed    = 0;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('[' . JOB . '] no database connection');
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$lock = $pdo->prepare('SELECT pg_try_advisory_lock(:k)');
$lock->execute([':k' => LOCK_KEY]);

if (!$lock->fetchColumn()) {
    error_log('[' . JOB . '] another instance is running — exiting');
    exit(0);
}

try {
    // ---------------------------------------------------------------
    // 1. financial_holds past expiry
    // ---------------------------------------------------------------
    while (true) {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, hold_reference, account_id, wallet_id, amount, status, expires_at
            FROM   financial_holds
            WHERE  status = 'HELD' AND expires_at < NOW()
            ORDER  BY expires_at
            LIMIT  :limit
            FOR UPDATE SKIP LOCKED
        ");
        $stmt->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
        $stmt->execute();
        $holds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$holds) {
            $pdo->commit();
            break;
        }

        foreach ($holds as $hold) {
            try {
                // Each item is independent: a failure rolls back only this item.
                $pdo->exec('SAVEPOINT expiry_item');
                // A live code against a dead hold is cash with nothing
                // behind it. Kill the code first, release second.
                expire_codes_for_hold($pdo, $hold['hold_reference'], 'HOLD_EXPIRED');

                $r = release_hold($pdo, $hold, 'EXPIRED', 'cron:' . JOB);

                if ($r['released']) {
                    $released++;
                    error_log(sprintf('[%s] released %s %s amount=%.2f balance %.2f->%.2f latency=%ss',
                        JOB, $hold['hold_reference'], $r['subject_type'], $r['amount'],
                        $r['balance_before'], $r['balance_after'], $r['latency_seconds'] ?? '?'));
                } else {
                    $skipped++;
                }
                $pdo->exec('RELEASE SAVEPOINT expiry_item');
            } catch (Throwable $e) {
                try { $pdo->exec('ROLLBACK TO SAVEPOINT expiry_item'); } catch (Throwable $ignore) {}
                $failed++;
                error_log('[' . JOB . '] FAILED ' . $hold['hold_reference'] . ': ' . $e->getMessage());
            }
        }

        $pdo->commit();

        if (count($holds) < BATCH_SIZE || $failed >= BATCH_SIZE) {
            break;
        }
    }

    // ---------------------------------------------------------------
    // 2. Vouchers stuck on hold. These have no financial_holds row —
    //    hold.php only flips instant_money_vouchers.status to 'hold'.
    // ---------------------------------------------------------------
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        SELECT voucher_id, voucher_number, amount, status,
               source_hold_reference, hold_expires_at
        FROM   instant_money_vouchers
        WHERE  status = 'hold'
          AND  hold_expires_at IS NOT NULL
          AND  hold_expires_at < NOW()
        ORDER  BY hold_expires_at
        LIMIT  :limit
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
    $stmt->execute();
    $stale = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($stale as $v) {
        try {
            // Each item is independent: a failure rolls back only this item.
            $pdo->exec('SAVEPOINT expiry_item');
            // Only release the voucher if no live financial hold still
            // claims it — otherwise the swap is still in flight.
            if (!empty($v['source_hold_reference'])) {
                $chk = $pdo->prepare("
                    SELECT status FROM financial_holds
                    WHERE hold_reference = :ref LIMIT 1
                ");
                $chk->execute([':ref' => $v['source_hold_reference']]);
                $linked = $chk->fetchColumn();

                if ($linked === 'HELD') {
                    $skipped++;
                    error_log('[' . JOB . '] voucher ' . $v['voucher_number']
                        . ' left on hold — linked hold ' . $v['source_hold_reference'] . ' is still HELD');
                    continue;
                }
            }

            $r = release_voucher_hold($pdo, $v, 'STALE_VOUCHER_HOLD', 'cron:' . JOB);

            if ($r['released']) {
                $vouchers++;
                error_log(sprintf('[%s] voucher %s returned to active (%.2f)',
                    JOB, $v['voucher_number'], $r['amount']));
            }
            $pdo->exec('RELEASE SAVEPOINT expiry_item');
        } catch (Throwable $e) {
            try { $pdo->exec('ROLLBACK TO SAVEPOINT expiry_item'); } catch (Throwable $ignore) {}
            $failed++;
            error_log('[' . JOB . '] FAILED voucher ' . $v['voucher_number'] . ': ' . $e->getMessage());
        }
    }

    $pdo->commit();

    // ---------------------------------------------------------------
    // 3. Alert on anything left behind
    // ---------------------------------------------------------------
    $orphans     = (int)$pdo->query('SELECT count(*) FROM v_orphaned_holds')->fetchColumn();
    $staleLeft   = (int)$pdo->query('SELECT count(*) FROM v_stale_voucher_holds')->fetchColumn();

    if ($orphans > 0) {
        error_log('[' . JOB . '] ALERT ' . $orphans . ' hold(s) still withholding funds past expiry');
    }
    if ($staleLeft > 0) {
        error_log('[' . JOB . '] ALERT ' . $staleLeft . ' voucher(s) still on hold past expiry');
    }

    log_cron_run($pdo, JOB, $startedAt, $released + $vouchers, $failed,
        sprintf('holds=%d vouchers=%d skipped=%d orphans=%d stale=%d',
            $released, $vouchers, $skipped, $orphans, $staleLeft));

    error_log(sprintf('[%s] done: holds=%d vouchers=%d skipped=%d failed=%d in %.2fs',
        JOB, $released, $vouchers, $skipped, $failed, microtime(true) - $startedAt));

    exit($failed > 0 || $orphans > 0 || $staleLeft > 0 ? 1 : 0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[' . JOB . '] FATAL: ' . $e->getMessage());
    log_cron_run($pdo, JOB, $startedAt, $released, $failed + 1, 'fatal: ' . $e->getMessage());
    exit(1);

} finally {
    $pdo->prepare('SELECT pg_advisory_unlock(:k)')->execute([':k' => LOCK_KEY]);
}
