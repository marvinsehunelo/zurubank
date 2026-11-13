-- 2026_11_12_held_balance_verify.sql — ZURUBANK. Read-only; safe to run any time.
--
-- Every query below must return NO ROWS once the migration has run and the
-- new code is live. See docs/HELD_BALANCE.md for what to do if one does not.

-- 1. Account holds still waiting to be converted (placed the old way).
--    Rows here: run 2026_11_12_held_balance.sql again.
SELECT 'unconverted hold' AS problem, id, hold_reference, account_id, amount, created_at
FROM   financial_holds
WHERE  status = 'HELD' AND account_id IS NOT NULL AND NOT in_held_balance
ORDER  BY id;

-- 2. held_balance must equal the sum of the account's live, converted holds.
SELECT 'held_balance does not match live holds' AS problem,
       a.account_id, a.account_number, a.balance, a.held_balance,
       COALESCE(h.total, 0)                  AS live_holds_total,
       a.held_balance - COALESCE(h.total, 0) AS difference
FROM   accounts a
LEFT   JOIN (SELECT account_id, SUM(amount) AS total
             FROM   financial_holds
             WHERE  status = 'HELD' AND in_held_balance
             GROUP  BY account_id) h USING (account_id)
WHERE  a.held_balance <> COALESCE(h.total, 0)
ORDER  BY a.account_id;

-- 3. Nothing held beyond the balance, nothing negative held.
SELECT 'held exceeds balance' AS problem, account_id, account_number, balance, held_balance
FROM   accounts
WHERE  held_balance < 0 OR (held_balance > 0 AND balance < held_balance)
ORDER  BY account_id;
