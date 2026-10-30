-- Staff accounts for the admin dashboard (Backend/auth/admin_users.php creates this table on
-- first use too). Safe to run more than once:
--   psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f database/migrations/2026_10_30_admin_users.sql
-- No account is created here. The first admin comes from ZURUBANK_ADMIN_USERNAME and
-- ZURUBANK_ADMIN_PASSWORD on first sign-in; admins then add staff on admin_staff.php.
CREATE TABLE IF NOT EXISTS admin_users (
    id            SERIAL PRIMARY KEY,
    username      VARCHAR(64)  NOT NULL UNIQUE,
    full_name     VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          VARCHAR(16)  NOT NULL DEFAULT 'staff' CHECK (role IN ('admin', 'staff')),
    active        BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by    INTEGER      REFERENCES admin_users(id),
    created_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
    last_login_at TIMESTAMP
);
