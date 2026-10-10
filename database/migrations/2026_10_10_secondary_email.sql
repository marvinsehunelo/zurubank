-- Verified secondary email addresses for customers.
--
-- users.email holds a placeholder for many customers, so it cannot be used
-- to reach them. A customer adds a real address from their dashboard and
-- confirms it with an emailed 6-digit code; only then is it stored in
-- user_secondary_emails. email_verification_codes holds those codes,
-- hashed, with an attempt count, an expiry and a used marker.
--
-- Backend/helpers/secondary_email.php creates these tables itself on first use. This file is
-- the same SQL, for applying by hand:
--     psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_10_10_secondary_email.sql
--
-- Idempotent, safe to re-run.

BEGIN;

CREATE TABLE IF NOT EXISTS user_secondary_emails (
    user_id     INTEGER PRIMARY KEY,
    email       VARCHAR(255) NOT NULL,
    verified_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS email_verification_codes (
    id         BIGSERIAL PRIMARY KEY,
    user_id    INTEGER NOT NULL,
    email      VARCHAR(255) NOT NULL,
    purpose    VARCHAR(30) NOT NULL,
    code_hash  VARCHAR(255) NOT NULL,
    attempts   INTEGER NOT NULL DEFAULT 0,
    expires_at TIMESTAMPTZ NOT NULL,
    used_at    TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_email_verification_codes_user
    ON email_verification_codes (user_id, purpose, created_at DESC);

COMMIT;
