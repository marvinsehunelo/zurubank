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
- **Old holds keep working.** A hold with `in_held_balance = FALSE` is settled
  the old way: debiting it moves nothing, and releasing or expiring it adds
  its amount back to `balance`. So a hold placed by the old code is right
  whether or not the migration has converted it yet.
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
  transfer and instant-money endpoints now answer "Part of this money is on
  hold and cannot be spent until the hold is released or settled" instead of
  the database's text, which also printed the whole account row.
  `transaction/reverse.php` refuses to take back a deposit out of held money
  with HTTP 409. (See "Known, not fixed" about `reverse.php` itself.)
- **Not changed:** voucher holds (`instant_money_vouchers.status = 'hold'`,
  no money moves) and wallet holds (a `financial_holds` row with `wallet_id`
  that reserves nothing).

## Putting it live

**Check first** (read-only):

```sql
SELECT data_type FROM information_schema.columns
WHERE  table_name = 'audit_logs' AND column_name = 'performed_by';
```

`notify_debit.php` writes the requester's name ("VOUCHMORPH") into
`audit_logs.performed_by`. In `backup.sql` that column is `integer`. If live
says `integer` too, every account debit through `notify_debit.php` is
refused and rolled back, with the old code as with the new one: nothing
moves, and the hold stays until it is released or expires (test B12 shows
this). The hold model still works, but no debit completes until that is
fixed. See "Known, not fixed".

Then do these four steps back to back. Run the scripts with `ON_ERROR_STOP`, so any
failure stops the script and changes nothing.

1. **Migration.**
   `psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_11_12_held_balance.sql`
   It runs in one transaction and briefly locks `accounts` and
   `financial_holds` against writes (a fraction of a second on this data). For
   every account hold that is still `HELD`, it adds the amount back to
   `balance` and to `held_balance`, then marks the hold `in_held_balance =
   TRUE`. It checks that available money is the same for every account as it
   was before, and rolls everything back if not. It prints one row per account
   it changed.
2. **Deploy the code**, straight away.
3. **Migration again**, the same command. It converts only the holds the old
   code placed between steps 1 and 2. It is safe to run any number of times.
4. **Verify.**
   `psql "$DATABASE_URL" -f database/migrations/2026_11_12_held_balance_verify.sql`
   Every query must return no rows. See below.

Why this order: the new code reads `held_balance`. Deployed before the
migration, `hold.php`, `verify_asset.php` and `balance.php` fail on the
missing column. That is safe, because no money moves, but every VouchMorph
payment from Zurubank fails until the migration runs. Migrating first leaves
a short gap where the old code runs on the new figures:

- a hold the old code **places** in the gap is an old-style hold, and step 3
  converts it;
- a converted hold the old code **debits, releases or expires** in the gap
  leaves `held_balance` too high (and, for a release or a `notify_debit`
  debit, `balance` too high as well). Available money is still right, but
  the account stays partly frozen until repaired. Verify query 2 lists these;
- the old code trying to spend held money is refused by the CHECK.

**To avoid the gap altogether**, pause VouchMorph traffic to Zurubank (or stop
the web service) before step 1 and resume after step 4. If you can, do that.

If the migration stops with "would hold more than their balance", an account
with a live hold is overdrawn (in the old model its balance went below zero
after the hold was taken out). Nothing was changed. The message includes a
query that lists those accounts. Settle or release those holds, or correct
the balance, then run it again. If it stops with a lock timeout, another
session was holding the tables. Nothing was changed; run it again.

## The verify queries

All three are read-only and safe to run at any time.

**1. "unconverted hold"**: an account hold that is `HELD` but still old-style.
*Repair:* run the migration again. If a row stays, its `account_id` points at
no account. Find out what it was and mark it `RELEASED` by hand.

**2. "held_balance does not match live holds"**: `held_balance` is not the sum
of the account's live converted holds. `difference` is how much too high
(positive) or too low (negative) it is. A positive difference almost always
means a converted hold was settled by the **old** code in the gap. Find
those holds:

```sql
SELECT h.id, h.hold_reference, h.amount, h.status, h.debited_at, h.released_at
FROM   financial_holds h
WHERE  h.account_id = :account_id AND h.in_held_balance AND h.status <> 'HELD'
  AND  COALESCE(h.released_at, h.debited_at) BETWEEN :migration_time AND :deploy_time;
```

For a `DEBITED` one, find out which endpoint debited it: search the
application log for its `hold_reference`. The old `notify_debit.php` logs
"ACCOUNT debit finalized (no balance mutation here)". The old `hold.php`
DEBIT branch logs only "Hold processed successfully - Ref: ...". (An
`audit_logs` row with `entity = 'financial_holds'`, `action = 'DEBIT'` and
`entity_id` = the hold's id also means `notify_debit.php`, but that row only
exists if the audit write works on live.)

Then, for each such hold, in one transaction:

- `RELEASED`, `PARTIALLY_RELEASED` or `EXPIRED` (old release or old expiry
  cron), or `DEBITED` by `notify_debit.php`: the old code gave the money back
  to `balance`, or never took it out. Run
  `UPDATE accounts SET balance = balance - <amount>, held_balance = held_balance - <amount> WHERE account_id = <id>`
  (for a partial release, the full amount of the hold, not the part released).
- `DEBITED` by the old `hold.php` DEBIT branch: `balance` is already right.
  Only run
  `UPDATE accounts SET held_balance = held_balance - <amount> WHERE account_id = <id>`.

If you cannot tell which endpoint debited it, do not guess. Pausing traffic
for the four steps avoids this case entirely.

Run the verify file again afterwards. A negative difference should not
happen. If you see one, do not change anything; find which hold is missing
from `held_balance` before touching the account.

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

The order mirrors going live: pause VouchMorph traffic if you can, then
1. `psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_11_12_held_balance_rollback.sql`;
2. deploy the old code straight away;
3. run the rollback again (it catches holds the new code placed in between;
   safe to repeat);
4. check: `SELECT count(*) FROM accounts WHERE held_balance <> 0` must be 0.

## Tests

- `php tests/hold_arithmetic_test.php`: the arithmetic alone, no database.
- `bash tests/hold_flow_pg_test.sh`: starts a throwaway PostgreSQL 16 cluster
  in a temp directory, test-only certificates and two PHP built-in servers,
  then runs `tests/hold_flow_pg_test.php`. It proves the migration on old-shape
  data (available unchanged, second run changes nothing, verify empty,
  rollback restores, refuses an overdrawn account with a live hold). It then
  sends signed requests through every hold flow, and places two holds at the
  same moment against the real database. It needs the PostgreSQL 16 binaries,
  php with pdo_pgsql and curl, and openssl. As root it runs the cluster as the
  `postgres` user. Set `HOLD_TEST_SHOW_LOG=1` to see the PHP log on failure.

## Known, not fixed

- `audit_logs.performed_by` is `integer NOT NULL` in `backup.sql`, but
  `notify_debit.php` (account and voucher paths) writes the requester's name
  into it. If live matches `backup.sql`, those debits are always refused and
  rolled back (see "Check first"). The fix is a choice: make the column text,
  or write the audit row inside a savepoint so a failed audit write cannot
  undo the debit (as `integrations/kycbank/kycbank.php` does). Either one
  makes debits start going through on live.
- `transaction/reverse.php` and `transaction/status.php` die on every request
  with "Cannot redeclare generate_auth_code()". `api/v1/auth.php` and
  `helpers/crypto.php` both define `generate_auth_code()` and
  `generate_trace()`, differently. Even without that, `reverse.php` writes
  columns the live schema does not have (`accounts.updated_at`, several in
  `transaction_reversals` and `audit_logs`). Its held-money refusal is in
  place and was tested with the clash patched out for one run. The test
  marks that check SKIP until the clash is fixed.
- The cron jobs that move money between internal accounts
  (`cron/unused_swap_slips.php`, `Frontend/dashboard/processExpiredSwaps.php`)
  and `api/settlement_stores.php` do no held-money check of their own. If a
  hold were ever placed on one of those internal accounts, the CHECK would
  refuse the move. The cron logs the failure and leaves the item for its next
  run; the settlement desk gets an error.
- `verify_asset.php` has no authentication.
- `notify_debit.php` looks up any `HELD` row by reference, including wallet
  holds, which then fail as "Account not found". This was the same before.
