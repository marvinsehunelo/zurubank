<?php
/**
 * Backend/lib/hold_release.php — ZURUBANK
 *
 * Shared release path for cron/expire_holds.php and cron/expire_codes.php.
 *
 * ZURUBANK-SPECIFIC BEHAVIOUR
 *   ACCOUNT: hold.php SUBTRACTS from accounts.balance when the hold is
 *            placed, so release ADDS it back. There is no held_balance
 *            column in this schema.
 *   WALLET:  hold.php inserts the hold row but NEVER touches
 *            instant_money_wallets.balance. So release must NOT credit
 *            anything — crediting here would create money. Release only
 *            flips the hold status. See README for why that is a bug on
 *            the PLACE side, not here.
 *   VOUCHER: no financial_holds row exists at all. The hold lives as
 *            instant_money_vouchers.status = 'hold'. Release sets it
 *            back to 'active' and clears source_hold_reference.
 */

declare(strict_types=1);

/**
 * Release one financial_holds row. Call INSIDE a transaction, after
 * selecting the hold FOR UPDATE.
 *
 * @param string $reason EXPIRED | CODE_EXPIRED
 */
function release_hold(PDO $pdo, array $hold, string $reason, string $actor): array
{
    $ref    = $hold['hold_reference'];
    $status = strtoupper((string)$hold['status']);

    // DEBITED / RELEASED / PARTIALLY_RELEASED / EXPIRED are terminal.
    if ($status !== 'HELD') {
        return ['released' => false, 'reason' => 'ALREADY_RESOLVED',
                'hold_reference' => $ref, 'status' => $status];
    }

    $amount        = (float)$hold['amount'];
    $balanceBefore = null;
    $balanceAfter  = null;

    if (!empty($hold['account_id'])) {
        $subjectType = 'ACCOUNT';

        $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_id = :id FOR UPDATE");
        $stmt->execute([':id' => $hold['account_id']]);
        $balanceBefore = $stmt->fetchColumn();

        if ($balanceBefore === false) {
            throw new RuntimeException("Hold {$ref}: account_id={$hold['account_id']} not found");
        }
        $balanceBefore = (float)$balanceBefore;

        // Give the money back. PLACE removed it from balance outright.
        $stmt = $pdo->prepare("
            UPDATE accounts SET balance = balance + :amount
            WHERE account_id = :id
            RETURNING balance
        ");
        $stmt->execute([':amount' => $amount, ':id' => $hold['account_id']]);
        $balanceAfter = (float)$stmt->fetchColumn();

    } elseif (!empty($hold['wallet_id'])) {
        $subjectType = 'WALLET';

        // Deliberately no balance movement — PLACE never took it.
        // Crediting here would mint funds out of nothing.
        $stmt = $pdo->prepare("SELECT balance FROM instant_money_wallets WHERE wallet_id = :id FOR UPDATE");
        $stmt->execute([':id' => $hold['wallet_id']]);
        $balanceBefore = $balanceAfter = (float)$stmt->fetchColumn();

        error_log("HOLD_RELEASE: wallet hold {$ref} released (status only — PLACE never debited the wallet)");

    } else {
        throw new RuntimeException("Hold {$ref}: neither account_id nor wallet_id set");
    }

    $newStatus = ($reason === 'EXPIRED') ? 'EXPIRED' : 'RELEASED';

    $stmt = $pdo->prepare("
        UPDATE financial_holds
        SET status = :status::varchar,
            released_at = NOW(),
            release_reason = :reason,
            released_by = :actor,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        ':status' => $newStatus, ':reason' => $reason,
        ':actor'  => $actor,     ':id'     => $hold['id'],
    ]);

    $latency = !empty($hold['expires_at'])
        ? max(0, time() - strtotime((string)$hold['expires_at']))
        : null;

    log_release($pdo, [
        'hold_reference' => $ref,
        'hold_id'        => $hold['id'],
        'subject_type'   => $subjectType,
        'account_id'     => $hold['account_id'] ?: null,
        'wallet_id'      => $hold['wallet_id'] ?: null,
        'voucher_id'     => null,
        'amount'         => $amount,
        'balance_before' => $balanceBefore,
        'balance_after'  => $balanceAfter,
        'reason'         => $reason,
        'released_by'    => $actor,
        'expires_at'     => $hold['expires_at'] ?? null,
        'latency'        => $latency,
    ]);

    return [
        'released' => true, 'hold_reference' => $ref, 'status' => $newStatus,
        'amount' => $amount, 'subject_type' => $subjectType,
        'balance_before' => $balanceBefore, 'balance_after' => $balanceAfter,
        'latency_seconds' => $latency,
    ];
}

/**
 * Release a voucher held by hold.php (status 'hold'), returning it to
 * 'active'. Voucher holds have no financial_holds row.
 */
function release_voucher_hold(PDO $pdo, array $voucher, string $reason, string $actor): array
{
    if (strtolower((string)$voucher['status']) !== 'hold') {
        return ['released' => false, 'reason' => 'ALREADY_RESOLVED',
                'voucher_number' => $voucher['voucher_number'], 'status' => $voucher['status']];
    }

    $stmt = $pdo->prepare("
        UPDATE instant_money_vouchers
        SET status = 'active', source_hold_reference = NULL,
            hold_placed_at = NULL, hold_expires_at = NULL
        WHERE voucher_id = :id AND status = 'hold'
    ");
    $stmt->execute([':id' => $voucher['voucher_id']]);

    $latency = !empty($voucher['hold_expires_at'])
        ? max(0, time() - strtotime((string)$voucher['hold_expires_at']))
        : null;

    log_release($pdo, [
        'hold_reference' => $voucher['source_hold_reference'] ?? ('VOUCHER-' . $voucher['voucher_id']),
        'hold_id'        => null,
        'subject_type'   => 'VOUCHER',
        'account_id'     => null,
        'wallet_id'      => null,
        'voucher_id'     => $voucher['voucher_id'],
        'amount'         => (float)$voucher['amount'],
        'balance_before' => null,
        'balance_after'  => null,
        'reason'         => $reason,
        'released_by'    => $actor,
        'expires_at'     => $voucher['hold_expires_at'] ?? null,
        'latency'        => $latency,
    ]);

    return ['released' => true, 'voucher_number' => $voucher['voucher_number'],
            'amount' => (float)$voucher['amount'], 'latency_seconds' => $latency];
}

/** Kill any live voucher code issued against a hold. Same transaction. */
function expire_codes_for_hold(PDO $pdo, ?string $holdReference, string $reason): int
{
    if (empty($holdReference)) {
        return 0;
    }

    $stmt = $pdo->prepare("
        SELECT voucher_id, voucher_number, status
        FROM   instant_money_vouchers
        WHERE  source_hold_reference = :ref AND status IN ('active','hold','pending')
        FOR UPDATE
    ");
    $stmt->execute([':ref' => $holdReference]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $n = 0;
    foreach ($rows as $v) {
        $pdo->prepare("
            UPDATE instant_money_vouchers
            SET status = 'expired', expired_at = NOW(),
                hold_expires_at = NULL, source_hold_reference = NULL
            WHERE voucher_id = :id
        ")->execute([':id' => $v['voucher_id']]);

        error_log("HOLD_RELEASE: expired voucher {$v['voucher_number']} ({$reason})");
        $n++;
    }

    return $n;
}

function log_release(PDO $pdo, array $r): void
{
    $stmt = $pdo->prepare("
        INSERT INTO hold_release_log
            (hold_reference, hold_id, subject_type, account_id, wallet_id, voucher_id,
             amount, balance_before, balance_after, reason, released_by, expires_at, latency_seconds)
        VALUES
            (:hold_reference, :hold_id, :subject_type, :account_id, :wallet_id, :voucher_id,
             :amount, :balance_before, :balance_after, :reason, :released_by, :expires_at, :latency)
    ");
    $stmt->execute([
        ':hold_reference' => $r['hold_reference'],
        ':hold_id'        => $r['hold_id'],
        ':subject_type'   => $r['subject_type'],
        ':account_id'     => $r['account_id'],
        ':wallet_id'      => $r['wallet_id'],
        ':voucher_id'     => $r['voucher_id'],
        ':amount'         => $r['amount'],
        ':balance_before' => $r['balance_before'],
        ':balance_after'  => $r['balance_after'],
        ':reason'         => $r['reason'],
        ':released_by'    => $r['released_by'],
        ':expires_at'     => $r['expires_at'],
        ':latency'        => $r['latency'],
    ]);
}

function log_cron_run(PDO $pdo, string $job, float $startedAt, int $processed, int $failed, string $detail = ''): void
{
    $stmt = $pdo->prepare("
        INSERT INTO cron_runs (job, started_at, processed, failed, detail)
        VALUES (:job, to_timestamp(:started), :processed, :failed, :detail)
    ");
    $stmt->execute([
        ':job' => $job, ':started' => $startedAt,
        ':processed' => $processed, ':failed' => $failed, ':detail' => $detail,
    ]);
}
