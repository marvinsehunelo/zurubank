<?php
/**
 * Backend/lib/account_holds.php — ZURUBANK
 *
 * How an ACCOUNT (or CARD, which resolves to its account) hold moves money.
 *
 *   accounts.balance       what the customer has, held money included
 *   accounts.held_balance  the part of balance set aside by live holds
 *   available              balance - held_balance: what can be spent
 *
 *   place    held += amount                       (balance untouched)
 *   debit    balance -= amount, held -= amount
 *   release  held -= amount                       (balance untouched)
 *   partial  held -= amount, balance -= captured  (captured = amount - released)
 *   expiry   same as release
 *
 * LEGACY HOLDS. Before this model a hold was taken OUT of balance when it
 * was placed. financial_holds.in_held_balance says which model a hold was
 * placed under: TRUE = the amount is inside held_balance (above); FALSE =
 * it was subtracted from balance and is in neither figure. A FALSE hold is
 * settled the old way (debit moves nothing, release adds back to balance),
 * so a hold placed by the old code is never counted twice or lost, whether
 * or not database/migrations/2026_11_12_held_balance.sql has converted it yet.
 *
 * The hold_delta_* functions are pure arithmetic (tests/hold_arithmetic_test.php).
 * The account_hold_* functions apply them; call those INSIDE a transaction.
 */

declare(strict_types=1);

const HOLD_MONEY_SCALE = 4; // accounts.balance and financial_holds.amount are NUMERIC(20,4)

function hold_money($value): float
{
    return round((float)$value, HOLD_MONEY_SCALE);
}

/** What can be spent: balance minus held. Works on a row without the column (treated as nothing held). */
function hold_available($balance, $held = 0): float
{
    return hold_money(hold_money($balance) - hold_money($held ?? 0));
}

/**
 * Place a hold. Returns the change to make: ['balance' => 0, 'held' => +amount].
 * Throws if the amount is not positive or more than is available.
 */
function hold_delta_place($balance, $held, $amount): array
{
    $amount = hold_money($amount);
    if ($amount <= 0) {
        throw new InvalidArgumentException('Valid amount required');
    }
    $available = hold_available($balance, $held);
    if ($available < $amount) {
        throw new RuntimeException("Insufficient balance. Available: {$available}, Requested: {$amount}");
    }
    return ['balance' => 0.0, 'held' => $amount];
}

/** Debit a held amount: it leaves the account. */
function hold_delta_debit($amount, bool $inHeldBalance = true): array
{
    $amount = hold_money($amount);
    if (!$inHeldBalance) {
        return ['balance' => 0.0, 'held' => 0.0]; // legacy: already out of balance
    }
    return ['balance' => -$amount, 'held' => -$amount];
}

/**
 * How much of a hold a release gives back. A positive amount strictly below
 * the hold is a partial release (the rest is captured); anything else
 * releases the whole hold.
 */
function hold_release_split($holdAmount, $requested = 0): array
{
    $full      = hold_money($holdAmount);
    $requested = hold_money($requested);
    $released  = ($requested > 0 && $requested < $full) ? $requested : $full;

    return [
        'released'   => $released,
        'captured'   => hold_money($full - $released),
        'is_partial' => $released < $full,
    ];
}

/** Release (or expire) a hold; $requested as in hold_release_split(). */
function hold_delta_release($holdAmount, $requested = 0, bool $inHeldBalance = true): array
{
    $split = hold_release_split($holdAmount, $requested);
    if (!$inHeldBalance) {
        return ['balance' => $split['released'], 'held' => 0.0]; // legacy: give it back
    }
    return ['balance' => -$split['captured'], 'held' => -hold_money($holdAmount)];
}

/** Apply a change to figures in memory (used by the tests and for reporting). */
function hold_apply_delta($balance, $held, array $delta): array
{
    $b = hold_money(hold_money($balance) + $delta['balance']);
    $h = hold_money(hold_money($held) + $delta['held']);
    return ['balance' => $b, 'held_balance' => $h, 'available_balance' => hold_available($b, $h)];
}

// ---------------------------------------------------------------------
// Database side
// ---------------------------------------------------------------------

function hold_sql_amount(float $value): string
{
    return number_format($value, HOLD_MONEY_SCALE, '.', '');
}

function hold_figures(array $row): array
{
    $b = hold_money($row['balance']);
    $h = hold_money($row['held_balance']);
    return ['balance' => $b, 'held_balance' => $h, 'available_balance' => hold_available($b, $h)];
}

/** Lock the account row and return balance / held_balance / available_balance. */
function account_hold_lock(PDO $pdo, $accountId): array
{
    $stmt = $pdo->prepare("SELECT balance, held_balance FROM accounts WHERE account_id = :id FOR UPDATE");
    $stmt->execute([':id' => $accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException("Account not found: account_id={$accountId}");
    }
    return hold_figures($row);
}

/**
 * Apply a change to the account row. The WHERE clause is the same rule as
 * the accounts_held_within_balance CHECK: it refuses any change that would
 * leave held_balance negative, or balance below a held_balance above zero.
 * (An account with nothing held may still be overdrawn, as before.)
 */
function account_hold_apply(PDO $pdo, $accountId, array $delta): array
{
    if ($delta['balance'] == 0.0 && $delta['held'] == 0.0) {
        return account_hold_lock($pdo, $accountId);
    }

    $stmt = $pdo->prepare("
        UPDATE accounts
        SET    balance      = balance      + CAST(:b1 AS NUMERIC),
               held_balance = held_balance + CAST(:h1 AS NUMERIC)
        WHERE  account_id = :id
          AND  held_balance + CAST(:h2 AS NUMERIC) >= 0
          AND  (held_balance + CAST(:h3 AS NUMERIC) = 0
                OR balance + CAST(:b2 AS NUMERIC) >= held_balance + CAST(:h4 AS NUMERIC))
        RETURNING balance, held_balance
    ");
    $b = hold_sql_amount($delta['balance']);
    $h = hold_sql_amount($delta['held']);
    $stmt->execute([':b1' => $b, ':b2' => $b, ':h1' => $h, ':h2' => $h, ':h3' => $h, ':h4' => $h, ':id' => $accountId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException("Account figures do not allow this hold operation (account_id={$accountId})");
    }
    return hold_figures($row);
}

/** Was this hold placed inside held_balance? Read from the row itself, never assumed. */
function account_hold_is_in_held_balance(PDO $pdo, array $hold): bool
{
    if (array_key_exists('in_held_balance', $hold)) {
        $flag = $hold['in_held_balance'];
    } else {
        $stmt = $pdo->prepare("SELECT in_held_balance FROM financial_holds WHERE id = :id");
        $stmt->execute([':id' => $hold['id']]);
        $flag = $stmt->fetchColumn();
    }
    return $flag === true || $flag === 1 || $flag === '1' || $flag === 't' || $flag === 'true';
}

/** Set an amount aside. Caller has locked the account row. Returns the figures after. */
function account_hold_place(PDO $pdo, $accountId, $amount): array
{
    $now = account_hold_lock($pdo, $accountId);
    return account_hold_apply($pdo, $accountId, hold_delta_place($now['balance'], $now['held_balance'], $amount));
}

/** Debit a HELD hold (row with id, account_id, amount). Does not touch financial_holds. */
function account_hold_debit(PDO $pdo, array $hold): array
{
    account_hold_lock($pdo, $hold['account_id']);
    return account_hold_apply($pdo, $hold['account_id'],
        hold_delta_debit($hold['amount'], account_hold_is_in_held_balance($pdo, $hold)));
}

/** Release a HELD hold, wholly or in part. Does not touch financial_holds. */
function account_hold_release(PDO $pdo, array $hold, $requested = 0): array
{
    account_hold_lock($pdo, $hold['account_id']);
    $figures = account_hold_apply($pdo, $hold['account_id'],
        hold_delta_release($hold['amount'], $requested, account_hold_is_in_held_balance($pdo, $hold)));
    return $figures + hold_release_split($hold['amount'], $requested);
}

// ---------------------------------------------------------------------
// Refusals from the database
// ---------------------------------------------------------------------

const HOLD_SPEND_REFUSED = 'Part of this money is on hold and cannot be spent until the hold is released or settled';

/** Was this error the accounts_held_within_balance CHECK refusing to spend held money? */
function hold_is_held_money_refusal(Throwable $e): bool
{
    return $e instanceof PDOException
        && (string)$e->getCode() === '23514'
        && strpos($e->getMessage(), 'accounts_held_within_balance') !== false;
}

/** The message to show for an error: plain words for a held-money refusal, the error's own text otherwise. */
function hold_refusal_message(Throwable $e): string
{
    return hold_is_held_money_refusal($e) ? HOLD_SPEND_REFUSED : $e->getMessage();
}
