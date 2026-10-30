# Admin accounts and what the web server serves

## Staff accounts

Each member of staff signs in to the admin dashboard with their own account (table
`admin_users`, passwords stored with `password_hash`). Every KYC request, review and audit entry
records who did it.

1. **First admin.** Set two variables on the `zurubank` service in Railway and redeploy:
   `ZURUBANK_ADMIN_USERNAME` and `ZURUBANK_ADMIN_PASSWORD` (12+ characters). The first sign-in
   with them, while there are no staff accounts yet, creates that admin. After that they are
   ignored; remove them.
2. **More staff.** Admin dashboard → **Staff accounts** → Add a member of staff (role _staff_ for
   KYC and customers, _admin_ to also manage staff). Hand them the password privately.
3. **Leavers.** Deactivate the account. It stops working at once, including open sessions.
   Sessions also end after 30 minutes without activity.

The old built-in `admin_user` login no longer exists. Only admins can run "Process Expired Swaps"
from the dashboard.

## Database password

`Backend/config/db.php` reads `DATABASE_URL` (or `PG*` variables). There is no password in the
code any more. The password that used to be there is in this public repository's history, so
rotate it: in Railway, Postgres service → change the `postgres` user's password, then update
`DATABASE_URL` (and `PGPASSWORD`) on every service that uses it.

## What the web server serves

The app runs with `php -S 0.0.0.0:$PORT -t . router.php`. `router.php` answers 404 for database
dumps and migrations (`*.sql`, `database/`), logs and text files, tests and debug pages (any
`*test*.php`), config, cron and include folders, dotfiles (`.git`, `.env`), Composer and build
files. Everything else is served as before. If you change the start command in Railway, keep
`router.php` at the end.
