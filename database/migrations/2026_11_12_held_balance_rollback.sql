-- 2026_11_12_held_balance_rollback.sql — ZURUBANK
--
-- ONLY for going back to the code from before held_balance. That code
-- expects a live hold to be OUT of balance, so this takes every live,
-- converted hold back out of balance and held_balance and marks it as an
-- old-style hold again. Available money is unchanged for every account, or
-- nothing is changed. The columns stay (the old code ignores them); the
-- CHECK is dropped. Run it BEFORE the old code goes live again, and do not
-- run it while the new code is serving requests.
--
--   psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_11_12_held_balance_rollback.sql

BEGIN;

LOCK TABLE accounts, financial_holds IN SHARE ROW EXCLUSIVE MODE;

CREATE TEMP TABLE _held_rollback_before ON COMMIT DROP AS
    SELECT account_id, balance - held_balance AS available FROM accounts;

CREATE TEMP TABLE _held_rollback_reverted ON COMMIT DROP AS
    WITH reverted AS (
        UPDATE financial_holds h
        SET    in_held_balance = FALSE
        WHERE  h.status = 'HELD' AND h.account_id IS NOT NULL AND h.in_held_balance
          AND  EXISTS (SELECT 1 FROM accounts a WHERE a.account_id = h.account_id)
        RETURNING h.account_id, h.amount
    )
    SELECT account_id, COUNT(*) AS holds, SUM(amount) AS total FROM reverted GROUP BY account_id;

UPDATE accounts a
SET    balance      = a.balance      - r.total,
       held_balance = a.held_balance - r.total
FROM   _held_rollback_reverted r
WHERE  a.account_id = r.account_id;

DO $$
DECLARE
    changed INTEGER;
    left_over INTEGER;
BEGIN
    SELECT COUNT(*) INTO changed
    FROM   accounts a JOIN _held_rollback_before b USING (account_id)
    WHERE  a.balance - a.held_balance IS DISTINCT FROM b.available;
    IF changed > 0 THEN
        RAISE EXCEPTION 'held_balance rollback: available money changed for % account(s); nothing was changed', changed;
    END IF;

    SELECT COUNT(*) INTO left_over FROM accounts WHERE held_balance <> 0;
    IF left_over > 0 THEN
        RAISE EXCEPTION 'held_balance rollback: % account(s) still have a held_balance with no live hold behind it; nothing was changed. Run 2026_11_12_held_balance_verify.sql and settle those first', left_over;
    END IF;
END $$;

ALTER TABLE accounts DROP CONSTRAINT IF EXISTS accounts_held_within_balance;

SELECT * FROM _held_rollback_reverted ORDER BY account_id;

COMMIT;
