-- tests/fixtures/hold_flow_schema.sql
--
-- The smallest schema the hold endpoints and the expiry job touch, in the
-- shape the live database has BEFORE 2026_11_12_held_balance.sql
-- (accounts as in backup.sql: no held_balance). Test use only.
--
-- hold_release_log, cron_runs, v_orphaned_holds, v_stale_voucher_holds and
-- the release_reason / released_by / hold_expires_at / expired_at columns
-- are used by Backend/cron/expire_holds.php and Backend/lib/hold_release.php
-- but are defined nowhere in this repository; the definitions here are the
-- minimum those files need, not a copy of the live ones.

CREATE TABLE users (
    user_id   SERIAL PRIMARY KEY,
    full_name VARCHAR(255),
    phone     VARCHAR(50)
);

CREATE TABLE accounts (
    account_id     SERIAL PRIMARY KEY,
    user_id        INTEGER NOT NULL,
    account_number VARCHAR(255),
    account_type   VARCHAR(50) DEFAULT 'checking',
    balance        NUMERIC(20,4) DEFAULT 0.00,
    currency       VARCHAR(10) DEFAULT 'USD',
    status         VARCHAR(50) DEFAULT 'active',
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE cards (
    card_id     SERIAL PRIMARY KEY,
    account_id  INTEGER NOT NULL,
    card_number VARCHAR(32) NOT NULL,
    status      VARCHAR(20) DEFAULT 'ACTIVE'
);

CREATE TABLE instant_money_wallets (
    wallet_id SERIAL PRIMARY KEY,
    user_id   INTEGER NOT NULL,
    balance   NUMERIC(20,4) DEFAULT 0,
    currency  VARCHAR(10) DEFAULT 'BWP',
    status    VARCHAR(20) DEFAULT 'active'
);

CREATE TABLE instant_money_vouchers (
    voucher_id            SERIAL PRIMARY KEY,
    voucher_number        VARCHAR(64),
    voucher_pin           VARCHAR(32),
    amount                NUMERIC(20,4),
    currency              VARCHAR(10) DEFAULT 'BWP',
    recipient_phone       VARCHAR(50),
    status                VARCHAR(20) DEFAULT 'active',
    source_hold_reference VARCHAR(100),
    hold_placed_at        TIMESTAMP,
    hold_expires_at       TIMESTAMP,
    expired_at            TIMESTAMP,
    created_at            TIMESTAMP DEFAULT NOW()
);

-- As hold.php created it before this change, plus the two columns
-- hold_release.php writes.
CREATE TABLE financial_holds (
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
    updated_at TIMESTAMP DEFAULT NOW(),
    release_reason VARCHAR(40),
    released_by VARCHAR(100)
);

CREATE TABLE hold_release_log (
    id              BIGSERIAL PRIMARY KEY,
    hold_reference  VARCHAR(100),
    hold_id         BIGINT,
    subject_type    VARCHAR(20),
    account_id      BIGINT,
    wallet_id       BIGINT,
    voucher_id      BIGINT,
    amount          NUMERIC(20,4),
    balance_before  NUMERIC(20,4),
    balance_after   NUMERIC(20,4),
    reason          VARCHAR(40),
    released_by     VARCHAR(100),
    expires_at      TIMESTAMP,
    latency_seconds INTEGER,
    created_at      TIMESTAMP DEFAULT NOW()
);

CREATE TABLE cron_runs (
    id         BIGSERIAL PRIMARY KEY,
    job        VARCHAR(60),
    started_at TIMESTAMP,
    processed  INTEGER,
    failed     INTEGER,
    detail     TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);

CREATE VIEW v_orphaned_holds AS
    SELECT * FROM financial_holds WHERE status = 'HELD' AND expires_at < NOW();

CREATE VIEW v_stale_voucher_holds AS
    SELECT * FROM instant_money_vouchers
    WHERE status = 'hold' AND hold_expires_at IS NOT NULL AND hold_expires_at < NOW();

-- performed_by is text here, NOT the INTEGER NOT NULL of backup.sql:
-- notify_debit.php writes the requester's name into it. Test B12 in
-- tests/hold_flow_pg_test.php runs a debit against the backup.sql shape.
CREATE TABLE audit_logs (
    id           BIGSERIAL PRIMARY KEY,
    entity       VARCHAR(100),
    entity_id    BIGINT,
    action       VARCHAR(50),
    category     VARCHAR(50),
    severity     VARCHAR(20),
    old_value    TEXT,
    new_value    TEXT,
    performed_by VARCHAR(100),
    performed_at TIMESTAMP,
    ip_address   VARCHAR(64),
    user_agent   TEXT
);

CREATE TABLE swap_internal_accounts (
    id           SERIAL PRIMARY KEY,
    account_code VARCHAR(255) NOT NULL UNIQUE,
    purpose      VARCHAR(50) NOT NULL DEFAULT 'settlement',
    balance      NUMERIC(15,2) DEFAULT 0.00
);
INSERT INTO swap_internal_accounts (account_code) VALUES ('IDENTITY-SETTLEMENT');

-- For Backend/api/v1/transaction/reverse.php, columns as in backup.sql
-- (trimmed to the ones that file reads before it moves money).
CREATE TABLE transactions (
    transaction_id SERIAL PRIMARY KEY,
    user_id        INTEGER DEFAULT 0,
    account_id     INTEGER DEFAULT 0 NOT NULL,
    from_account   VARCHAR(255),
    to_account     VARCHAR(255),
    type           VARCHAR(50) NOT NULL,
    amount         NUMERIC(15,2) DEFAULT 0.00 NOT NULL,
    reference      VARCHAR(255),
    description    TEXT,
    status         VARCHAR(50) DEFAULT 'pending' NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    trace_number   VARCHAR(255)
);

CREATE TABLE transaction_reversals (
    id             SERIAL PRIMARY KEY,
    original_trace VARCHAR(100) NOT NULL,
    reversal_trace VARCHAR(100) NOT NULL,
    reason         TEXT,
    reversed_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
