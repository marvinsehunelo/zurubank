-- KYC Bank integration.
--
-- kycbank_requests: one row per KYC request ZuruBank sends to KYC Bank,
-- with the consent's state and, once the customer approves and the profile
-- is fetched, the profile itself (profile_json, ZuruBank's own KYC record).
-- The phone/Omang number used to ask is NOT stored, only its kind.
-- A withdrawn row keeps profile_json for legal record-keeping only; the
-- dashboard never shows it and nothing fetches it again.
--
-- kycbank_webhook_events: ids of KYC Bank webhook events already processed,
-- so a redelivered event is acknowledged without being processed twice.
--
-- users.kyc_status: 'pending' | 'approved' | 'rejected' (default 'pending'),
-- as at SaccusSalis. Only a staff decision in the admin dashboard changes it.
-- No CHECK constraint: Backend/helpers/virtual_accounts.php writes 'verified'
-- for its system customer when the column exists.
--
-- Backend/integrations/kycbank/kycbank.php creates these on first use. This
-- file is the same SQL, for applying by hand:
--     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_10_30_kycbank.sql
--
-- Idempotent, safe to re-run.

BEGIN;

CREATE TABLE IF NOT EXISTS kycbank_requests (
    id                       BIGSERIAL PRIMARY KEY,
    user_id                  INTEGER NOT NULL REFERENCES users(user_id),
    consent_id               VARCHAR(64) UNIQUE,              -- cns_..., null until KYC Bank confirms
    idempotency_key          VARCHAR(64) NOT NULL UNIQUE,     -- generated once, reused on retry
    identifier_kind          VARCHAR(10) NOT NULL CHECK (identifier_kind IN ('mobile', 'omang')),
    purpose                  VARCHAR(200) NOT NULL,
    level                    VARCHAR(10) NOT NULL CHECK (level IN ('basic', 'standard')),
    -- creating | failed (local) or KYC Bank's: requested, active, rejected,
    -- lapsed, withdrawn, expired, suspended
    state                    VARCHAR(20) NOT NULL DEFAULT 'creating',
    suspended_reason         VARCHAR(40),                     -- profile_not_verified | profile_stale | profile_on_hold
    requested_at             TIMESTAMPTZ,
    decided_at               TIMESTAMPTZ,
    starts_at                TIMESTAMPTZ,
    ends_at                  TIMESTAMPTZ,
    lapses_at                TIMESTAMPTZ,
    latest_profile_version   INTEGER,
    profile_version          INTEGER,
    profile_json             JSONB,
    profile_fetched_at       TIMESTAMPTZ,
    needs_review             BOOLEAN NOT NULL DEFAULT FALSE,
    review_reason            VARCHAR(20),                     -- new_profile | profile_updated
    reviewed_by              INTEGER,                         -- admin id from the admin session
    reviewed_at              TIMESTAMPTZ,
    review_decision          VARCHAR(10) CHECK (review_decision IN ('approved', 'rejected')),
    reviewed_profile_version INTEGER,
    requested_by             INTEGER,                         -- admin id that sent the request
    last_error_code          VARCHAR(64),
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    withdrawn_at             TIMESTAMPTZ
);

CREATE INDEX IF NOT EXISTS idx_kycbank_requests_user
    ON kycbank_requests (user_id, created_at DESC);

CREATE TABLE IF NOT EXISTS kycbank_webhook_events (
    event_id     VARCHAR(80) PRIMARY KEY,                     -- evt_<uuid>, unique
    event        VARCHAR(40) NOT NULL,
    consent_id   VARCHAR(64),
    received_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    processed_at TIMESTAMPTZ
);

ALTER TABLE users ADD COLUMN IF NOT EXISTS kyc_status VARCHAR(32) DEFAULT 'pending';

COMMIT;
