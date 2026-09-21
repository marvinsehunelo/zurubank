<?php
/**
 * Backend/cron/expire_codes.php — ZURUBANK
 *
 * Expires instant money vouchers (the cashout codes) and, optionally,
 * releases the source hold behind them straight away.
 *
 * WHY CODES DIE AN HOUR EARLY
 * atm_cashout_voucher.php accepts a voucher in status active, hold or
 * pending. If a voucher outlives its source hold, a customer can present
 * a valid PIN at an ATM against funds that were already returned — cash
 * dispensed with nothing behind it. The one-hour margin covers clock skew
 * between institutions, a dispense already in flight, and the settling
 * time of expire_holds.php.
 *
 * The lead is applied AT ISSUE in generate_code.php (see README). This job
 * enforces what was written and alerts on codes that were issued without it.
 *
 * Run every minute, before expire_holds:
 *   * * * * * /usr/bin/php /var/www/zurubank/Backend/cron/expire_codes.php >> /var/log/zurubank/expire_codes.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/hold_release.php';

const JOB        = 'expire_codes';
const BATCH_SIZE = 200;
const LOCK_KEY   = 8583102;

/** Hand the money back the moment the code dies, rather than an hour later. */
const RELEASE_HOLD_ON_CODE_EXPIRY = true;

$startedAt = microtime(true);
$expired   = 0;
$released  = 0;
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
    while (true) {
        $pdo->beginTransaction();

        // Past its own expiry, or its source hold is already resolved.
        $stmt = $pdo->prepare("
            SELECT v.voucher_id, v.voucher_number, v.amount, v.status,
                   v.voucher_expires_at, v.source_hold_reference,
                   h.id AS hold_id, h.status AS hold_status, h.account_id,
                   h.wallet_id, h.amount AS hold_amount, h.expires_at AS hold_expires_at,
                   h.hold_reference
            FROM   instant_money_vouchers v
            LEFT   JOIN financial_holds h ON h.hold_reference = v.source_hold_reference
            WHERE  v.status IN ('active','hold','pending')
              AND  ( (v.voucher_expires_at IS NOT NULL AND v.voucher_expires_at < NOW())
                     OR (h.hold_reference IS NOT NULL AND h.status <> 'HELD') )
            ORDER  BY v.voucher_expires_at NULLS LAST
            LIMIT  :limit
            FOR UPDATE OF v SKIP LOCKED
        ");
        $stmt->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            $pdo->commit();
            break;
        }

        foreach ($rows as $v) {
            try {
                // Each item is independent: a failure rolls back only this item.
                $pdo->exec('SAVEPOINT expiry_item');
                // Already redeemed between the SELECT and now? Leave it.
                $recheck = $pdo->prepare("
                    SELECT status, redeemed_at FROM instant_money_vouchers
                    WHERE voucher_id = :id FOR UPDATE
                ");
                $recheck->execute([':id' => $v['voucher_id']]);
                $current = $recheck->fetch(PDO::FETCH_ASSOC);

                if (!$current || !in_array($current['status'], ['active','hold','pending'], true)
                    || !empty($current['redeemed_at'])) {
                    continue;
                }

                $pdo->prepare("
                    UPDATE instant_money_vouchers
                    SET status = 'expired', expired_at = NOW(),
                        hold_expires_at = NULL
                    WHERE voucher_id = :id
                ")->execute([':id' => $v['voucher_id']]);

                $expired++;
                error_log(sprintf('[%s] expired voucher %s amount=%.2f hold=%s',
                    JOB, $v['voucher_number'], (float)$v['amount'],
                    $v['source_hold_reference'] ?? 'none'));

                // Return the customer's money now, not in an hour.
                if (RELEASE_HOLD_ON_CODE_EXPIRY
                    && !empty($v['hold_id'])
                    && strtoupper((string)$v['hold_status']) === 'HELD') {

                    $lk = $pdo->prepare("
                        SELECT id, hold_reference, account_id, wallet_id, amount, status, expires_at
                        FROM   financial_holds WHERE id = :id FOR UPDATE
                    ");
                    $lk->execute([':id' => $v['hold_id']]);
                    $hold = $lk->fetch(PDO::FETCH_ASSOC);

                    if ($hold) {
                        $r = release_hold($pdo, $hold, 'CODE_EXPIRED', 'cron:' . JOB);
                        if ($r['released']) {
                            $released++;
                            error_log(sprintf('[%s] released hold %s (%.2f) after code expiry',
                                JOB, $hold['hold_reference'], $r['amount']));
                        }
                    }
                }
                $pdo->exec('RELEASE SAVEPOINT expiry_item');

            } catch (Throwable $e) {
                try { $pdo->exec('ROLLBACK TO SAVEPOINT expiry_item'); } catch (Throwable $ignore) {}
                $failed++;
                error_log('[' . JOB . '] FAILED voucher ' . ($v['voucher_number'] ?? '?')
                    . ': ' . $e->getMessage());
            }
        }

        $pdo->commit();

        if (count($rows) < BATCH_SIZE || $failed >= BATCH_SIZE) {
            break;
        }
    }

    // Codes issued without the one-hour lead — means the generate_code
    // patch is missing or was reverted.
    $violations = $pdo->query('SELECT voucher_number, voucher_expires_at, hold_expires_at, lead_seconds
                               FROM v_code_lead_violations')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($violations as $b) {
        error_log(sprintf('[%s] ALERT voucher %s expires %s, hold expires %s (lead only %ss)',
            JOB, $b['voucher_number'], $b['voucher_expires_at'],
            $b['hold_expires_at'], $b['lead_seconds']));
    }

    $orphanCodes = (int)$pdo->query('SELECT count(*) FROM v_orphaned_codes')->fetchColumn();

    if ($orphanCodes > 0) {
        error_log('[' . JOB . '] ALERT ' . $orphanCodes . ' live code(s) with no live hold behind them');
    }

    log_cron_run($pdo, JOB, $startedAt, $expired, $failed,
        sprintf('released=%d lead_violations=%d orphan_codes=%d',
            $released, count($violations), $orphanCodes));

    error_log(sprintf('[%s] done: expired=%d released=%d failed=%d in %.2fs',
        JOB, $expired, $released, $failed, microtime(true) - $startedAt));

    exit($failed > 0 || $orphanCodes > 0 || $violations ? 1 : 0);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[' . JOB . '] FATAL: ' . $e->getMessage());
    log_cron_run($pdo, JOB, $startedAt, $expired, $failed + 1, 'fatal: ' . $e->getMessage());
    exit(1);

} finally {
    $pdo->prepare('SELECT pg_advisory_unlock(:k)')->execute([':k' => LOCK_KEY]);
}
