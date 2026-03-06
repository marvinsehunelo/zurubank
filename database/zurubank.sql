

---------------------------------------------------------------------------------------------------
-- 1. Core User & Account Tables
----------------------------------------------------------------------------------------------------

-- Table: users
CREATE TABLE users (
    user_id SERIAL PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(255),
    role VARCHAR(50) DEFAULT 'customer',
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: accounts
CREATE TABLE accounts (
    account_id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    account_number VARCHAR(255),
    account_type VARCHAR(50) DEFAULT 'checking',
    balance NUMERIC(15, 2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'USD',
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: sessions
CREATE TABLE sessions (
    session_id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

----------------------------------------------------------------------------------------------------
-- 2. Transaction & Audit Tables
----------------------------------------------------------------------------------------------------

-- Table: transactions
CREATE TABLE transactions (
    transaction_id SERIAL PRIMARY KEY,
    user_id INTEGER DEFAULT 0,
    account_id INTEGER NOT NULL DEFAULT 0,
    from_account VARCHAR(255),
    to_account VARCHAR(255),
    type VARCHAR(50) NOT NULL,
    amount NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    reference VARCHAR(255),
    description TEXT,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    swap_fee NUMERIC(15, 2) DEFAULT 0.00,
    creation_fee NUMERIC(15, 2) DEFAULT 0.00,
    admin_fee NUMERIC(15, 2) DEFAULT 0.00,
    sms_fee NUMERIC(15, 2) DEFAULT 0.00,
    rounding_adjustment NUMERIC(15, 2) DEFAULT 0.00
);

-- Table: audit_logs
CREATE TABLE audit_logs (
    id SERIAL PRIMARY KEY,
    entity VARCHAR(255) NOT NULL,
    entity_id INTEGER NOT NULL,
    action VARCHAR(255) NOT NULL,
    category VARCHAR(50) DEFAULT 'system',
    severity VARCHAR(50) DEFAULT 'info',
    old_value TEXT,
    new_value TEXT,
    performed_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    performed_by INTEGER NOT NULL,
    ip_address VARCHAR(50),
    user_agent TEXT,
    geo_location VARCHAR(255)
);

-- Table: account_freezes (Inferred, based on table list)
CREATE TABLE account_freezes (
    freeze_id SERIAL PRIMARY KEY,
    account_id INTEGER NOT NULL,
    reason TEXT,
    frozen_by INTEGER,
    start_time TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    end_time TIMESTAMP WITHOUT TIME ZONE
);

----------------------------------------------------------------------------------------------------
-- 3. Ledger & Bank Integration Tables
----------------------------------------------------------------------------------------------------

-- Table: ledger_accounts
CREATE TABLE ledger_accounts (
    id SERIAL PRIMARY KEY,
    account_name VARCHAR(255) NOT NULL,
    account_number VARCHAR(255) NOT NULL UNIQUE,
    account_type VARCHAR(50) NOT NULL,
    balance NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(10) NOT NULL DEFAULT 'BWP',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: swap_linked_banks
CREATE TABLE swap_linked_banks (
    id SERIAL PRIMARY KEY,
    bank_code VARCHAR(50) NOT NULL UNIQUE,
    bank_name VARCHAR(255) NOT NULL,
    api_endpoint VARCHAR(255),
    public_key TEXT,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: central_bank_link (Inferred, based on table list)
CREATE TABLE central_bank_link (
    id SERIAL PRIMARY KEY,
    bank_id INTEGER NOT NULL,
    link_status VARCHAR(50) DEFAULT 'connected',
    last_sync TIMESTAMP WITHOUT TIME ZONE
);

-- Table: external_banks (Inferred, based on table list)
CREATE TABLE external_banks (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    bank_name VARCHAR(255) NOT NULL,
    account_number VARCHAR(255) NOT NULL
);

----------------------------------------------------------------------------------------------------
-- 4. Swap System Tables
----------------------------------------------------------------------------------------------------

-- Table: swap_internal_accounts
CREATE TABLE swap_internal_accounts (
    id SERIAL PRIMARY KEY,
    account_code VARCHAR(255) NOT NULL UNIQUE,
    purpose VARCHAR(50) NOT NULL,
    balance NUMERIC(15, 2) DEFAULT 0.00,
    currency CHAR(3) DEFAULT 'BWP',
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: swap_ledger
CREATE TABLE swap_ledger (
    ledger_id SERIAL PRIMARY KEY,
    reference_id VARCHAR(255) NOT NULL,
    ref_voucher_id INTEGER,
    debit_account VARCHAR(255) NOT NULL,
    credit_account VARCHAR(255) NOT NULL,
    amount NUMERIC(15, 2) NOT NULL,
    currency CHAR(3) DEFAULT 'BWP',
    description TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: swap_ledgers
CREATE TABLE swap_ledgers (
    ledger_id BIGSERIAL PRIMARY KEY,
    swap_reference VARCHAR(255) NOT NULL UNIQUE,
    from_participant VARCHAR(255) NOT NULL,
    to_participant VARCHAR(255) NOT NULL,
    from_type VARCHAR(255) NOT NULL,
    to_type VARCHAR(255) NOT NULL,
    from_account VARCHAR(255),
    to_account VARCHAR(255),
    original_amount NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    final_amount NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    currency_code VARCHAR(10) NOT NULL DEFAULT 'BWP',
    swap_fee NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    creation_fee NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    admin_fee NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    sms_fee NUMERIC(15, 2) NOT NULL DEFAULT 0.00,
    token VARCHAR(255),
    status VARCHAR(50) NOT NULL DEFAULT 'completed',
    reverse_logic BOOLEAN NOT NULL DEFAULT FALSE,
    performed_by INTEGER NOT NULL DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table: swap_transactions
CREATE TABLE swap_transactions (
    id SERIAL PRIMARY KEY,
    middleman_id INTEGER,
    source VARCHAR(255) NOT NULL,
    destination VARCHAR(255),
    type VARCHAR(50) NOT NULL,
    amount NUMERIC(15, 2) NOT NULL,
    reference VARCHAR(255),
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

----------------------------------------------------------------------------------------------------
-- 5. Middleman, Settings, and Other Tables
----------------------------------------------------------------------------------------------------

-- Table: swap_middleman
CREATE TABLE swap_middleman (
    id SERIAL PRIMARY KEY,
    account_number VARCHAR(255) NOT NULL UNIQUE,
    api_key VARCHAR(255) NOT NULL,
    webhook_url VARCHAR(255),
    encryption_key VARCHAR(255),
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: zurubank_middleman
CREATE TABLE zurubank_middleman (
    id SERIAL PRIMARY KEY,
    account_number VARCHAR(255) NOT NULL UNIQUE,
    api_key VARCHAR(255) NOT NULL,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: swap_audit
CREATE TABLE swap_audit (
    id SERIAL PRIMARY KEY,
    action_type VARCHAR(50) NOT NULL,
    actor VARCHAR(255),
    reference VARCHAR(255),
    details TEXT,
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: swap_settings
CREATE TABLE swap_settings (
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(255) NOT NULL UNIQUE,
    setting_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- Table: system_settings
CREATE TABLE system_settings (
    setting_key VARCHAR(255) PRIMARY KEY,
    setting_value VARCHAR(255),
    updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

----------------------------------------------------------------------------------------------------
-- 6. Instant Money/Voucher Tables (Wallet System)
----------------------------------------------------------------------------------------------------

-- Table: instant_money_wallets
CREATE TABLE instant_money_wallets (
    wallet_id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    balance NUMERIC(15, 2) DEFAULT 0.00,
    currency CHAR(3) DEFAULT 'BWP',
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: instant_money_transactions
CREATE TABLE instant_money_transactions (
    transaction_id SERIAL PRIMARY KEY,
    wallet_id INTEGER NOT NULL,
    type VARCHAR(50) NOT NULL,
    amount NUMERIC(15, 2) NOT NULL,
    reference VARCHAR(255),
    related_account_id INTEGER,
    status VARCHAR(50) DEFAULT 'completed',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: instant_money_transfers
CREATE TABLE instant_money_transfers (
    transfer_id SERIAL PRIMARY KEY,
    from_wallet_id INTEGER NOT NULL,
    to_wallet_id INTEGER NOT NULL,
    amount NUMERIC(15, 2) NOT NULL,
    reference VARCHAR(255),
    status VARCHAR(50) DEFAULT 'completed',
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Table: instant_money_vouchers
CREATE TABLE instant_money_vouchers (
    voucher_id SERIAL PRIMARY KEY,
    amount NUMERIC(15, 2) NOT NULL,
    currency CHAR(3) DEFAULT 'BWP',
    status VARCHAR(50) NOT NULL DEFAULT 'active',
    created_by INTEGER NOT NULL,
    recipient_phone VARCHAR(255),
    created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    voucher_number VARCHAR(255),
    voucher_pin VARCHAR(255),
    redeemed_by INTEGER,
    voucher_created_at TIMESTAMP WITHOUT TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    voucher_expires_at TIMESTAMP WITHOUT TIME ZONE,
    swap_enabled BOOLEAN DEFAULT FALSE,
    swap_fee_paid_by VARCHAR(50) DEFAULT 'sender',
    swap_expires_at TIMESTAMP WITHOUT TIME ZONE,
    redeemed_at TIMESTAMP WITHOUT TIME ZONE
);

----------------------------------------------------------------------------------------------------
-- 7. Foreign Key Constraints (Recommended for Data Integrity)
----------------------------------------------------------------------------------------------------

-- Accounts
ALTER TABLE accounts ADD CONSTRAINT fk_accounts_user_id FOREIGN KEY (user_id) REFERENCES users(user_id);

-- Sessions
ALTER TABLE sessions ADD CONSTRAINT fk_sessions_user_id FOREIGN KEY (user_id) REFERENCES users(user_id);

-- Transactions (Note: If user_id/account_id default to 0, FK may fail unless 0 is a valid ID)
-- ALTER TABLE transactions ADD CONSTRAINT fk_transactions_user_id FOREIGN KEY (user_id) REFERENCES users(user_id);
-- ALTER TABLE transactions ADD CONSTRAINT fk_transactions_account_id FOREIGN KEY (account_id) REFERENCES accounts(account_id);

-- Account Freezes
ALTER TABLE account_freezes ADD CONSTRAINT fk_freezes_account_id FOREIGN KEY (account_id) REFERENCES accounts(account_id);

-- Central Bank Link
ALTER TABLE central_bank_link ADD CONSTRAINT fk_cbl_bank_id FOREIGN KEY (bank_id) REFERENCES swap_linked_banks(id);

-- External Banks
ALTER TABLE external_banks ADD CONSTRAINT fk_eb_user_id FOREIGN KEY (user_id) REFERENCES users(user_id);

-- Instant Money Wallets
ALTER TABLE instant_money_wallets ADD CONSTRAINT fk_imw_user_id FOREIGN KEY (user_id) REFERENCES users(user_id);

-- Instant Money Transactions
ALTER TABLE instant_money_transactions ADD CONSTRAINT fk_imt_wallet_id FOREIGN KEY (wallet_id) REFERENCES instant_money_wallets(wallet_id);

-- Instant Money Transfers
ALTER TABLE instant_money_transfers ADD CONSTRAINT fk_imtf_from_wallet_id FOREIGN KEY (from_wallet_id) REFERENCES instant_money_wallets(wallet_id);
ALTER TABLE instant_money_transfers ADD CONSTRAINT fk_imtf_to_wallet_id FOREIGN KEY (to_wallet_id) REFERENCES instant_money_wallets(wallet_id);

-- Instant Money Vouchers
ALTER TABLE instant_money_vouchers ADD CONSTRAINT fk_imv_created_by FOREIGN KEY (created_by) REFERENCES users(user_id);
ALTER TABLE instant_money_vouchers ADD CONSTRAINT fk_imv_redeemed_by FOREIGN KEY (redeemed_by) REFERENCES users(user_id);

-- Add the soft-delete column
ALTER TABLE transactions ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE;
ALTER TABLE swap_ledger ADD COLUMN is_deleted BOOLEAN DEFAULT FALSE;

-- Create the safety function
CREATE OR REPLACE FUNCTION prevent_hard_delete()
RETURNS trigger AS $$
BEGIN
  RAISE EXCEPTION 'Hard deletes are forbidden on financial records per BoB/ECB standards';
END;
$$ LANGUAGE plpgsql;

-- Apply triggers to ZuruBank tables
CREATE TRIGGER no_delete_transactions
BEFORE DELETE ON transactions
FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE TRIGGER no_delete_swap_ledger
BEFORE DELETE ON swap_ledger
FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

--------------------------------------------------------------------------------
-- 1. Precision & Timestamp Updates (ZuruBank Specific Table Names)
--------------------------------------------------------------------------------

-- ZuruBank uses 'swap_ledger' instead of 'ledger_entries'
ALTER TABLE swap_ledger ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT NOW();

-- Updating Balance Precision to 4 decimal places (ECB/BoB Audit Standard)
ALTER TABLE accounts ALTER COLUMN balance TYPE NUMERIC(20,4);
ALTER TABLE ledger_accounts ALTER COLUMN balance TYPE NUMERIC(20,4);

-- ZuruBank uses 'instant_money_wallets' instead of 'wallets'
ALTER TABLE instant_money_wallets ALTER COLUMN balance TYPE NUMERIC(20,4);

--------------------------------------------------------------------------------
-- 2. Regulatory Control Tables (The "Inspector's Choice" Tables)
--------------------------------------------------------------------------------

-- Chart of Accounts: Mandatory to prove you aren't hiding "slush funds"
CREATE TABLE chart_of_accounts (
    coa_code VARCHAR(20) PRIMARY KEY,
    coa_name VARCHAR(255) NOT NULL,
    coa_type VARCHAR(20) CHECK (coa_type IN ('asset','liability','equity','income','expense')),
    parent_coa_code VARCHAR(20),
    is_customer_account BOOLEAN DEFAULT FALSE,
    is_trust_account BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Accounting Closures: Proves the Bank "balances its books" daily (EOD)
CREATE TABLE accounting_closures (
    closure_date DATE PRIMARY KEY,
    closed_by INTEGER,
    closed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    closure_type VARCHAR(20) CHECK (closure_type IN ('EOD','EOM','EOY')),
    remarks TEXT
);

-- Data Retention: Specifically for Bank of Botswana 7-10 year rules
CREATE TABLE data_retention_policies (
    entity_name VARCHAR(100) PRIMARY KEY,
    retention_years INT NOT NULL,
    legal_basis TEXT, -- e.g., 'Botswana Banking Act Section X'
    last_reviewed DATE
);

-- Disaster Recovery: To prove the bank can recover from a crash/fire
CREATE TABLE disaster_recovery_tests (
    test_id BIGSERIAL PRIMARY KEY,
    test_date DATE NOT NULL,
    test_type VARCHAR(50),
    systems_tested TEXT[],
    result VARCHAR(20) CHECK (result IN ('pass','fail','partial')),
    issues_found TEXT,
    resolved BOOLEAN DEFAULT FALSE,
    signed_off_by INTEGER
);

--------------------------------------------------------------------------------
-- 3. Initializing the Chart of Accounts (ZuruBank Setup)
--------------------------------------------------------------------------------

INSERT INTO chart_of_accounts (coa_code, coa_name, coa_type, is_trust_account)
VALUES 
('1000', 'Cash & Central Bank Reserves', 'asset', TRUE),
('2000', 'Customer Deposit Liabilities', 'liability', FALSE),
('2100', 'Voucher Suspense Liability', 'liability', FALSE),
('4000', 'Transaction Fee Income', 'income', FALSE);

ALTER TABLE users RENAME COLUMN password TO password_hash;

ALTER TABLE users
  ADD COLUMN password_changed_at TIMESTAMP,
  ADD COLUMN failed_login_attempts INT DEFAULT 0,
  ADD COLUMN last_failed_login TIMESTAMP;
CREATE TABLE kyc_profiles (
  id SERIAL PRIMARY KEY,
  user_id INT NOT NULL UNIQUE,
  kyc_level VARCHAR(20) CHECK (kyc_level IN ('LOW','MEDIUM','HIGH')),
  risk_rating VARCHAR(20) CHECK (risk_rating IN ('LOW','MEDIUM','HIGH')),
  source_of_funds TEXT NOT NULL,
  pep BOOLEAN DEFAULT FALSE,
  sanctions_checked BOOLEAN DEFAULT FALSE,
  last_reviewed_at TIMESTAMP,
  created_at TIMESTAMP DEFAULT NOW(),
  FOREIGN KEY (user_id) REFERENCES users(user_id)
);

ALTER TABLE transactions
  ADD COLUMN is_large_transaction BOOLEAN DEFAULT FALSE,
  ADD COLUMN is_suspicious BOOLEAN DEFAULT FALSE,
  ADD COLUMN reported_to_regulator BOOLEAN DEFAULT FALSE,
  ADD COLUMN regulator_report_reference VARCHAR(255);

CREATE TABLE journals (
  journal_id BIGSERIAL PRIMARY KEY,
  reference VARCHAR(255) UNIQUE NOT NULL,
  description TEXT,
  created_at TIMESTAMP DEFAULT NOW()
);

ALTER TABLE swap_ledger
  ADD COLUMN journal_id BIGINT,
  ADD CONSTRAINT fk_swap_journal
  FOREIGN KEY (journal_id) REFERENCES journals(journal_id);

CREATE OR REPLACE FUNCTION enforce_balanced_journal()
RETURNS trigger AS $$
DECLARE
  total NUMERIC(20,4);
BEGIN
  SELECT SUM(
    CASE WHEN debit_account IS NOT NULL THEN amount ELSE -amount END
  )
  INTO total
  FROM swap_ledger
  WHERE journal_id = NEW.journal_id;

  IF total <> 0 THEN
    RAISE EXCEPTION 'Journal % is not balanced', NEW.journal_id;
  END IF;

  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_enforce_journal_balance
AFTER INSERT OR UPDATE ON swap_ledger
FOR EACH ROW EXECUTE FUNCTION enforce_balanced_journal();

INSERT INTO ledger_accounts (account_name, account_number, account_type)
VALUES ('Voucher Suspense Account','VOUCHER-SUSPENSE','liability')
ON CONFLICT DO NOTHING;

ALTER TABLE instant_money_vouchers
  ADD COLUMN holding_account VARCHAR(64) DEFAULT 'VOUCHER-SUSPENSE';
  
  -- Make atm_id and agent_id nullable
ALTER TABLE cashouts ALTER COLUMN atm_id DROP NOT NULL;
ALTER TABLE cashouts ADD COLUMN agent_id INTEGER;

-- Add source_bank_id if it doesn't exist
ALTER TABLE cashouts ADD COLUMN IF NOT EXISTS source_bank_id INTEGER;

-- Check current structure of atm_dispenses
\d atm_dispenses

-- If the table exists but missing currency, either:
-- Option 1: Drop and recreate (if no important data)
DROP TABLE IF EXISTS atm_dispenses;

CREATE TABLE atm_dispenses (
    id SERIAL PRIMARY KEY,
    atm_id VARCHAR(50) NOT NULL,
    trace_number VARCHAR(255) NOT NULL UNIQUE,
    amount NUMERIC(20,4) NOT NULL,
    currency VARCHAR(10) DEFAULT 'BWP',
    status VARCHAR(50) DEFAULT 'DISPENSED',
    created_at TIMESTAMP DEFAULT NOW()
);

-- Option 2: Add currency column if table exists but missing it
ALTER TABLE atm_dispenses ADD COLUMN IF NOT EXISTS currency VARCHAR(10) DEFAULT 'BWP';

ALTER TABLE instant_money_vouchers
ADD COLUMN IF NOT EXISTS external_reference VARCHAR(255);

ALTER TABLE instant_money_vouchers
ADD COLUMN IF NOT EXISTS source_institution VARCHAR(100);

ALTER TABLE instant_money_vouchers
ADD COLUMN IF NOT EXISTS source_hold_reference VARCHAR(255);

-- Add missing reference column to instant_money_vouchers
ALTER TABLE instant_money_vouchers 
ADD COLUMN IF NOT EXISTS reference VARCHAR(255);

-- Also ensure all other columns that might be missing
ALTER TABLE instant_money_vouchers 
ADD COLUMN IF NOT EXISTS source_institution VARCHAR(100),
ADD COLUMN IF NOT EXISTS source_hold_reference VARCHAR(255),
ADD COLUMN IF NOT EXISTS source_asset_type VARCHAR(50),
ADD COLUMN IF NOT EXISTS code_hash VARCHAR(255);
-- =========================================================
-- FIX 1: Add missing columns to instant_money_vouchers
-- =========================================================
ALTER TABLE instant_money_vouchers 
ADD COLUMN IF NOT EXISTS reference VARCHAR(255),
ADD COLUMN IF NOT EXISTS source_institution VARCHAR(100),
ADD COLUMN IF NOT EXISTS source_hold_reference VARCHAR(255),
ADD COLUMN IF NOT EXISTS source_asset_type VARCHAR(50),
ADD COLUMN IF NOT EXISTS code_hash VARCHAR(255);

-- =========================================================
-- FIX 2: Add missing columns to voucher_cashout_details
-- =========================================================
ALTER TABLE voucher_cashout_details 
ADD COLUMN IF NOT EXISTS reference VARCHAR(255),
ADD COLUMN IF NOT EXISTS source_institution VARCHAR(100);

-- =========================================================
-- OPTIONAL: If voucher_cashout_details doesn't exist at all
-- =========================================================
CREATE TABLE IF NOT EXISTS voucher_cashout_details (
    id SERIAL PRIMARY KEY,
    voucher_number VARCHAR(255) NOT NULL UNIQUE,
    auth_code VARCHAR(50) UNIQUE NOT NULL,
    amount NUMERIC(20,4) NOT NULL,
    currency VARCHAR(10) DEFAULT 'BWP',
    recipient_phone VARCHAR(50),
    instructions TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    reference VARCHAR(255),
    source_institution VARCHAR(100)
);

