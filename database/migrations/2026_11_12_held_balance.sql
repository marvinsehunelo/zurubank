-- 2026_11_12_held_balance.sql — ZURUBANK
--
-- Held money stays in accounts.balance and is set aside in a new column,
-- accounts.held_balance (available = balance - held_balance). Until now a
-- hold was SUBTRACTED from balance when placed and existed only as a
-- financial_holds row.
--
-- What this does, in ONE transaction:
--   1. adds accounts.held_balance and financial_holds.in_held_balance
--      (FALSE = "this hold was taken out of balance the old way");
--   2. for every account hold that is HELD and still FALSE, adds its amount
--      back to balance AND to held_balance, and marks it TRUE;
--   3. proves that balance - held_balance (the money a customer can spend)
--      is the same for every account as it was before, or rolls back;
--   4. adds a CHECK so balance can never be taken below held_balance.
--
-- Idempotent: a hold is converted only while its flag is FALSE, so a second
-- run converts nothing (or only holds the old code placed in the meantime).
-- Wallet and voucher holds are not touched.
--
-- The code applies this file itself the first time it needs it
-- (Backend/lib/held_balance_schema.php), so merging is enough. By hand:
-- Run:     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_11_12_held_balance.sql
-- Verify:  psql "$DATABASE_URL" -f database/migrations/2026_11_12_held_balance_verify.sql
-- Steps and order with the code deploy: docs/HELD_BALANCE.md

BEGIN;

-- If another session holds accounts or financial_holds for longer than this
-- (a long report, a stuck transaction), stop and change nothing rather than
-- make every request queue behind this script. Just run it again.
SET LOCAL lock_timeout = '15s';

-- Same definition hold.php creates on first use, in case no hold was ever placed.
CREATE TABLE IF NOT EXISTS financial_holds (
    id BIGSERIAL PRIMARY KEY,
    account_id BIGINT,
    wallet_id BIGINT,
    amount DECIMAL(20,4) NOT NULL,
    hold_reference VARCHAR(100) UNIQUE NOT NULL,
    status VARCHAR(30) DEFAULT 'HELD',
    requester VARCHAR(100),
    signature_verified BOOLEAN DEFAULT FALSE,
    expires_at TIMESTAMP,
    debited_at TIMESTAMP,
    released_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Nothing may place, debit or release a hold, or move a balance, while the
-- figures are being converted. Waits for requests in flight, then holds
-- writers off until COMMIT (a fraction of a second).
LOCK TABLE accounts, financial_holds IN SHARE ROW EXCLUSIVE MODE;

ALTER TABLE accounts        ADD COLUMN IF NOT EXISTS held_balance    NUMERIC(20,4) NOT NULL DEFAULT 0;
ALTER TABLE financial_holds ADD COLUMN IF NOT EXISTS in_held_balance BOOLEAN       NOT NULL DEFAULT FALSE;

CREATE TEMP TABLE _held_migration_before ON COMMIT DROP AS
    SELECT account_id, balance, held_balance, balance - held_balance AS available
    FROM   accounts;

CREATE TEMP TABLE _held_migration_converted ON COMMIT DROP AS
    WITH converted AS (
        UPDATE financial_holds h
        SET    in_held_balance = TRUE
        WHERE  h.status = 'HELD'
          AND  h.account_id IS NOT NULL
          AND  NOT h.in_held_balance
          AND  EXISTS (SELECT 1 FROM accounts a WHERE a.account_id = h.account_id)
        RETURNING h.account_id, h.amount
    )
    SELECT account_id, COUNT(*) AS holds, SUM(amount) AS total
    FROM   converted
    GROUP  BY account_id;

UPDATE accounts a
SET    balance      = a.balance      + c.total,
       held_balance = a.held_balance + c.total
FROM   _held_migration_converted c
WHERE  a.account_id = c.account_id;

DO $$
DECLARE
    changed  INTEGER;
    negative INTEGER;
BEGIN
    SELECT COUNT(*) INTO changed
    FROM   accounts a
    JOIN   _held_migration_before b USING (account_id)
    WHERE  a.balance - a.held_balance IS DISTINCT FROM b.available;

    IF changed > 0 THEN
        RAISE EXCEPTION 'held_balance migration: available money changed for % account(s); rolled back, nothing was changed', changed;
    END IF;

    SELECT COUNT(*) INTO negative
    FROM   accounts
    WHERE  held_balance < 0 OR (held_balance > 0 AND balance < held_balance);

    IF negative > 0 THEN
        RAISE EXCEPTION 'held_balance migration: % account(s) would hold more than their balance (overdrawn account with a live hold?); rolled back, nothing was changed. List them: SELECT a.account_id, a.balance, SUM(h.amount) FROM accounts a JOIN financial_holds h USING (account_id) WHERE h.status = ''HELD'' GROUP BY 1, 2 HAVING a.balance < 0', negative;
    END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_constraint
                   WHERE conname = 'accounts_held_within_balance' AND conrelid = 'accounts'::regclass) THEN
        -- Held money can never be spent, whatever code moves the balance.
        -- Accounts with nothing held are not restricted (internal accounts
        -- may run negative as before).
        ALTER TABLE accounts ADD CONSTRAINT accounts_held_within_balance
            CHECK (held_balance >= 0 AND (held_balance = 0 OR balance >= held_balance));
    END IF;
END $$;

-- What this run did (no rows = nothing needed converting).
SELECT c.account_id,
       c.holds                          AS holds_converted,
       c.total                          AS amount_moved_into_held,
       b.balance                        AS balance_before,
       a.balance                        AS balance_after,
       a.held_balance                   AS held_after,
       b.available                      AS available_before,
       a.balance - a.held_balance       AS available_after
FROM   _held_migration_converted c
JOIN   _held_migration_before b USING (account_id)
JOIN   accounts a USING (account_id)
ORDER  BY c.account_id;

COMMIT;
