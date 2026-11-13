# Held balance

When VouchMorph places a hold on a Zurubank account (or on a card, which
resolves to its account), the money no longer leaves the balance. It stays in
`accounts.balance`, is shown as "on hold", and cannot be spent.

| figure | meaning |
|---|---|
| `accounts.balance` | everything the customer has, held money included |
| `accounts.held_balance` | the part of `balance` set aside by live holds |
| available | `balance - held_balance`: what can be spent |

| step | balance | held_balance | available |
|---|---|---|---|
| place a hold of A | unchanged | + A | − A |
| debit it | − A | − A | unchanged |
| release it, or it expires | unchanged | − A | + A |
| partial release, R given back | − (A − R) | − A | + R |

The arithmetic lives in `Backend/lib/account_holds.php`. Every endpoint that
places, debits, releases or expires an account hold uses it:
`api/v1/hold.php`, `api/v1/settlement/notify_debit.php`, `lib/hold_release.php`
(called by `cron/expire_holds.php` and `cron/expire_codes.php`).

## What changed

- **Schema** (`database/migrations/2026_11_12_held_balance.sql`):
  - `accounts.held_balance NUMERIC(20,4) NOT NULL DEFAULT 0`;
  - `financial_holds.in_held_balance BOOLEAN NOT NULL DEFAULT FALSE`: TRUE means the
    hold is counted in `held_balance`, FALSE means it was placed the old way
    (already taken out of `balance`);
  - the CHECK `accounts_held_within_balance`:
    `held_balance >= 0 AND (held_balance = 0 OR balance >= held_balance)`. No
    code path can spend held money, whether or not it checks first. An account
    with nothing held can still go negative, as internal accounts do today.
- **The code applies the migration itself** the first time it needs it
  (`Backend/lib/held_balance_schema.php`); see "Putting it live".
- **Old holds keep working.** A hold with `in_held_balance = FALSE` is settled
  the old way: debiting it moves nothing, and releasing or expiring it adds
  its amount back to `balance`. So a hold placed by the old code is right
  whether or not it has been converted yet.
- **`hold.php`'s own DEBIT branch** used to take the amount out of `balance` a
  second time. It now does the same single debit as `notify_debit.php`.
- **Answers to VouchMorph.** Every field they had is still there with the same
  meaning (`hold_placed`, `hold_reference`, `debited`, `signature`,
  `certificate`, ...). New fields: `held_balance` and `available_balance`.
  `total_balance` is now the true total, held money included.
  `verify_asset.php` still reports spendable money in `balance` and
  `available_balance`, and adds `total_balance` and `held_balance`.
  `accounts/balance.php` now works for accounts. It used to select columns
  that do not exist (`available_balance`, `held_amount`, `updated_at`), so it
  could only answer 500. It reports `balance` (total), `held_balance` and
  `held_amount` (the same number), and `available_balance`.
- **Spending checks** use `balance - held_balance` in `verify_asset.php`, the
  instant-money controllers, the internal, external and own transfers, and
  swap-to-account. The customer dashboard shows "On hold" and "Available".
- **Refusals in plain words.** If the CHECK refuses a change, the voucher,
  transfer and instant-money endpoints answer "Part of this money is on hold
  and cannot be spent until the hold is released or settled" instead of the
  database's text, which also printed the whole account row.
  `transaction/reverse.php` refuses with HTTP 409 to take back a deposit out
  of held money.
- **Audit writes can no longer undo the money movement**
  (`Backend/lib/audit_log.php`). `notify_debit.php` (account and voucher
  debits) and `reverse.php` write their `audit_logs` row in a savepoint.
  `backup.sql` has `audit_logs.performed_by` as `integer NOT NULL`, but the
  actor is a name ("VOUCHMORPH"). If the column refuses the name, the row is
  written again with `performed_by = 0` and the name inside `new_value` as
  `"performed_by"`. If even that is refused, one line is logged and the debit
  stands. The release log (`hold_release_log`) and `cron_runs` writes of the
  expiry jobs are guarded the same way.
- **`reverse.php` and `transaction/status.php` load again.** `api/v1/auth.php`
  and `helpers/crypto.php` both defined `generate_auth_code()` and
  `generate_trace()`, so every request to a script loading both died with
  "Cannot redeclare". The copies in `crypto.php` stay: the only callers
  (`network/acquirer_confirm.php`, `network/issuer_finalize.php`) load
  `crypto.php` alone, so its formats (a 6-digit auth code, a `ZRUBNK...`
  trace) are what is already stored and sent to VouchMorph. `auth.php`'s
  copies were never called and are gone. `status.php` also had its own
  `get_requester_public_key()`, now `status_legacy_public_key()`.
  `reverse.php` now writes only columns the live schema has (no
  `accounts.updated_at`; `transaction_reversals` is `original_trace,
  reversal_trace, reason, reversed_at`; the transaction's reversal details go
  into its `description`, and who did it into the audit row). `status.php`
  no longer selects `transaction_reversals.reversed_by`, which does not exist.
- **No key material in the logs.** `sign_payload()` no longer logs the start
  of the private key.
- **Not changed:** voucher holds (`instant_money_vouchers.status = 'hold'`,
  no money moves) and wallet holds (a `financial_holds` row with `wallet_id`
  that reserves nothing).

## Putting it live

**Merge, the code migrates on first use, then run the verify script.**

1. Merge. Merging deploys.
2. The first request that needs the new schema applies it: an account or card
   hold, a debit of one, `verify_asset.php` or `balance.php` for an account,
   or the expiry cron, whichever comes first. It checks for
   `accounts.held_balance` (one catalogue lookup). If it is missing, it takes
   a PostgreSQL advisory lock (key 8583110), so only one request does the
   work and any others wait up to 20 seconds. It checks again, then runs
   `database/migrations/2026_11_12_held_balance.sql`: one transaction, its own
   15-second lock timeout, and it rolls itself back if any account's
   available money would change. The log says
   `[held_balance] migration 2026_11_12_held_balance.sql applied (first use)`.
3. Holds the old code places while the deploy is switching over are
   converted by the expiry cron on its next run (it checks every run; one
   cheap query when there are none). The log says `applied (converted
   leftover old-style holds)`. Until then the new code settles them
   correctly the old way.
4. **Run the verify script** once things are quiet:
   `psql "$DATABASE_URL" -f database/migrations/2026_11_12_held_balance_verify.sql`
   Every query must return no rows. See below.

**If the migration cannot be applied** (another session holds the tables
past the lock timeout, an account with a live hold is overdrawn, or the
database user may not alter the tables), nothing is changed and it fails
closed. Account and card holds, their debits, `verify_asset.php` and
`balance.php` for accounts answer "Holds on accounts are unavailable for a
moment while the bank finishes an upgrade. Please try again shortly." The
expiry crons stop for that run. The log has one line starting
`[held_balance] FAILED CLOSED` with the reason. Everything else keeps
working: voucher and wallet holds, voucher debits, transfers (nothing is
held yet, so nothing can be overspent), the dashboard. Every new request
tries again, so fix the cause and it carries on by itself:

- overdrawn account with a live hold: the reason includes a query that lists
  them. Settle or release those holds, or correct the balance;
- lock timeout: something held `accounts` or `financial_holds` for a long
  time (a long report, a stuck transaction). End it;
- no permission: run the migration by hand as the table owner (below).

**By hand, as a fallback** (or to choose the moment yourself): run the same
four steps the code would, back to back.

1. `psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_11_12_held_balance.sql`
2. Deploy (merge).
3. Run step 1 again: it converts holds the old code placed in between. It is
   safe to run any number of times.
4. Run the verify script.

Pausing VouchMorph traffic to Zurubank around the switch-over avoids the
case below altogether, but it is no longer required.

### Holds the old code settled during the changeover

While the old code is still serving (the deploy switching over, or between
steps 1 and 2 by hand), it runs on the new figures:

- a hold the old code **places** is an old-style hold, and is converted
  later (cron or step 3);
- the old code trying to spend held money is refused by the CHECK;
- a converted hold the old code **debits, releases or expires** leaves
  `held_balance` too high (and, for a release or a `notify_debit.php` debit,
  `balance` too high as well). Available money is still right, but the
  account stays partly frozen until repaired. Verify query 2 lists these
  accounts; the repair is below.

## The verify queries

All three are read-only and safe to run at any time.

**1. "unconverted hold"**: an account hold that is `HELD` but still old-style.
*Repair:* wait for the next expiry cron run, or run the migration again. If a
row stays, its `account_id` points at no account. Find out what it was and
mark it `RELEASED` by hand.

**2. "held_balance does not match live holds"**: `held_balance` is not the sum
of the account's live converted holds. `difference` is how much too high
(positive) or too low (negative) it is. A positive difference means a
converted hold was settled by the **old** code during the changeover. Find
those holds (the times are when the migration ran, from its log line, and
when the old code stopped serving):

```sql
SELECT h.id, h.hold_reference, h.amount, h.status, h.debited_at, h.released_at
FROM   financial_holds h
WHERE  h.account_id = :account_id AND h.in_held_balance AND h.status <> 'HELD'
  AND  COALESCE(h.released_at, h.debited_at) BETWEEN :migration_time AND :old_code_stopped;
```

For a `DEBITED` one, find out which endpoint debited it: search the
application log for its `hold_reference`. The old `notify_debit.php` logs
"ACCOUNT debit finalized (no balance mutation here)". The old `hold.php`
DEBIT branch logs only "Hold processed successfully - Ref: ...".

Then, for each such hold, in one transaction:

- `RELEASED`, `PARTIALLY_RELEASED` or `EXPIRED` (old release or old expiry
  cron), or `DEBITED` by `notify_debit.php`: the old code gave the money back
  to `balance`, or never took it out. Run
  `UPDATE accounts SET balance = balance - <amount>, held_balance = held_balance - <amount> WHERE account_id = <id>`
  (for a partial release, the full amount of the hold, not the part released).
- `DEBITED` by the old `hold.php` DEBIT branch: `balance` is already right.
  Only run
  `UPDATE accounts SET held_balance = held_balance - <amount> WHERE account_id = <id>`.

If you cannot tell which endpoint debited it, do not guess. Run the verify
file again afterwards. A negative difference should not happen. If you see
one, do not change anything; find which hold is missing from `held_balance`
before touching the account.

**3. "held exceeds balance"**: cannot happen while the CHECK is in place. If
it appears, the CHECK was dropped. Find out why before you add it back.

## Rolling back

Only to go back to the code from before this change. The old code expects a
live hold to be out of `balance`, so
`database/migrations/2026_11_12_held_balance_rollback.sql` takes every live
converted hold back out of `balance` and `held_balance` and marks it
old-style again. Available money is unchanged for every account, or nothing
changes. The columns stay (the old code ignores them); the CHECK is dropped.
It refuses to run if any account has a `held_balance` with no live hold
behind it. Fix those with verify query 2 first.

The columns stay, so the new code does not migrate again. But a hold it
places while it is still serving goes into `held_balance`, which the old code
does not know about. So pause VouchMorph traffic if you can, then:
1. deploy the old code;
2. straight away, `psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_11_12_held_balance_rollback.sql`;
3. run the rollback again a minute later (it catches holds the new code
   placed while the deploy was switching over; safe to repeat);
4. check: `SELECT count(*) FROM accounts WHERE held_balance <> 0` must be 0.

## Tests

- `php tests/hold_arithmetic_test.php`: the arithmetic alone, no database.
- `bash tests/hold_flow_pg_test.sh`: starts a throwaway PostgreSQL 16 cluster
  in a temp directory and test-only certificates. It then runs
  `tests/hold_flow_pg_test.php` twice, each time on a fresh database in the
  OLD shape with its own two PHP built-in servers:
  - **manual**: the SQL files by hand. Available money unchanged, a second
    run changes nothing, verify empty, rollback restores, and it refuses an
    overdrawn account with a live hold;
  - **auto**: no SQL by hand. First a migration that cannot be applied (it
    fails closed in plain words, the rest keeps working). Then two first
    requests at the same moment (applied exactly once), a hold the old code
    places during the changeover (the cron converts it), the cron being
    first, and no permission to alter tables.

  Both phases then send signed requests through every hold flow: place,
  over-place, the CHECK, debit, full and partial release, expiry through the
  cron, card hold, old-style holds, `balance.php`, `verify_asset.php`,
  `reverse.php` and `status.php`, `notify_debit.php` with a text, an integer
  and a refusing `audit_logs.performed_by`, and two holds at the same moment.
  It needs the PostgreSQL 16 binaries, php with pdo_pgsql and curl, and
  openssl. As root it runs the cluster as the `postgres` user. Set
  `HOLD_TEST_SHOW_LOG=1` to see the PHP log on failure.

## Known, not fixed

- `verify_asset.php` has no authentication. It is left as it is on purpose:
  VouchMorph also uses it to verify a destination account, and requiring a
  signature could break deposits if any of those calls are unsigned.
- The cron jobs that move money between internal accounts
  (`cron/unused_swap_slips.php`, `Frontend/dashboard/processExpiredSwaps.php`)
  and `api/settlement_stores.php` do no held-money check of their own. If a
  hold were ever placed on one of those internal accounts, the CHECK would
  refuse the move. The cron logs the failure and leaves the item for its next
  run; the settlement desk gets an error.
- `notify_debit.php` looks up any `HELD` row by reference, including wallet
  holds, which then fail as "Account not found". This was the same before.
- `status.php`'s legacy-signature branch (a signature without a
  certificate) calls a `verify_signature()` that exists nowhere. It now
  reports the query as unverified instead of dying. Verification is optional
  for status queries.
- `hold.php` logs every request body, which includes a voucher PIN when one
  is sent.
