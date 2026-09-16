-- ============================================================
-- Identity-swap internal accounts
--
-- Backs VouchMorph's identity-swap-to-cashout/deposit feature.
-- swap_internal_accounts already exists in the base schema
-- (database/zurubank.sql) for exactly this purpose but has never
-- been populated or wired into application code until now.
--
-- IDENTITY-RECEIVING  — where consolidated identity-claim proceeds
--                       land first when ZURUBANK is the destination
--                       of an identity swap.
-- IDENTITY-HOLDING    — swept to from IDENTITY-RECEIVING before final
--                       payout (cashout code generation or deposit).
-- IDENTITY-SETTLEMENT — records the interbank settlement leg when
--                       ZURUBANK is the source of an identity swap,
--                       after the source hold has been debited.
--
-- Safe to re-run: account_code is UNIQUE, so this only inserts rows
-- that don't already exist.
-- ============================================================

INSERT INTO swap_internal_accounts (account_code, purpose, currency, status)
VALUES
    ('IDENTITY-RECEIVING', 'identity_swap_receiving', 'BWP', 'active'),
    ('IDENTITY-HOLDING', 'identity_swap_holding', 'BWP', 'active'),
    ('IDENTITY-SETTLEMENT', 'identity_swap_settlement', 'BWP', 'active')
ON CONFLICT (account_code) DO NOTHING;
