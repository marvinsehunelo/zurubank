<?php
// Backend/auth/admin_users.php
//
// Staff accounts for the admin dashboard. Each member of staff signs in with their own username
// and password (stored as a password_hash), so reviews and audit entries name a real person.
//
// No password lives in the code. The very first admin is created from two environment variables
// the first time someone signs in with them while the table is still empty:
//   ZURUBANK_ADMIN_USERNAME, ZURUBANK_ADMIN_PASSWORD   (12+ characters)
// After that, admins add and deactivate staff on Frontend/dashboard/admin_staff.php, and the two
// variables can be removed.

const ZB_ADMIN_ROLES = ['admin', 'staff'];
const ZB_ADMIN_MIN_PASSWORD = 12;

/** Creates the table on first use, like the repo's other helpers (see database/migrations). */
function zb_admin_users_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec("
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
        )
    ");
    $done = true;
}

function zb_admin_find(PDO $pdo, int $id): ?array
{
    zb_admin_users_ensure_table($pdo);
    $st = $pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function zb_admin_find_by_username(PDO $pdo, string $username): ?array
{
    zb_admin_users_ensure_table($pdo);
    $st = $pdo->prepare('SELECT * FROM admin_users WHERE LOWER(username) = LOWER(?)');
    $st->execute([trim($username)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function zb_admin_list(PDO $pdo): array
{
    zb_admin_users_ensure_table($pdo);
    return $pdo->query(
        'SELECT id, username, full_name, role, active, created_at, last_login_at FROM admin_users ORDER BY username'
    )->fetchAll(PDO::FETCH_ASSOC);
}

/** Returns an error message, or null when the input is acceptable. */
function zb_admin_validate(string $username, string $fullName, string $password, string $role): ?string
{
    if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
        return 'Username: 3 to 64 letters, digits, dots, dashes or underscores.';
    }
    if (mb_strlen(trim($fullName)) < 2 || mb_strlen($fullName) > 120) {
        return 'Full name: 2 to 120 characters.';
    }
    if (strlen($password) < ZB_ADMIN_MIN_PASSWORD) {
        return 'Password: at least ' . ZB_ADMIN_MIN_PASSWORD . ' characters.';
    }
    if (!in_array($role, ZB_ADMIN_ROLES, true)) {
        return 'Role must be admin or staff.';
    }
    return null;
}

function zb_admin_create(PDO $pdo, string $username, string $fullName, string $password, string $role, ?int $createdBy): int
{
    zb_admin_users_ensure_table($pdo);
    $error = zb_admin_validate($username, $fullName, $password, $role);
    if ($error !== null) throw new InvalidArgumentException($error);
    if (zb_admin_find_by_username($pdo, $username)) {
        throw new InvalidArgumentException('That username is already taken.');
    }
    $st = $pdo->prepare('
        INSERT INTO admin_users (username, full_name, password_hash, role, created_by)
        VALUES (?, ?, ?, ?, ?) RETURNING id
    ');
    $st->execute([$username, trim($fullName), password_hash($password, PASSWORD_DEFAULT), $role, $createdBy]);
    return (int)$st->fetchColumn();
}

function zb_admin_set_active(PDO $pdo, int $id, bool $active): void
{
    zb_admin_users_ensure_table($pdo);
    $pdo->prepare('UPDATE admin_users SET active = ? WHERE id = ?')->execute([$active ? 'true' : 'false', $id]);
}

function zb_admin_set_password(PDO $pdo, int $id, string $password): void
{
    if (strlen($password) < ZB_ADMIN_MIN_PASSWORD) {
        throw new InvalidArgumentException('Password: at least ' . ZB_ADMIN_MIN_PASSWORD . ' characters.');
    }
    $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
}

function zb_admin_active_admins(PDO $pdo): int
{
    zb_admin_users_ensure_table($pdo);
    return (int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND active")->fetchColumn();
}

/**
 * Checks a username and password. Returns the staff row, or null. While no staff exist yet, the
 * ZURUBANK_ADMIN_USERNAME / ZURUBANK_ADMIN_PASSWORD pair creates the first admin.
 */
function zb_admin_authenticate(PDO $pdo, string $username, string $password): ?array
{
    zb_admin_users_ensure_table($pdo);
    $row = zb_admin_find_by_username($pdo, $username);
    if ($row === null) {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
        $envUser = (string)getenv('ZURUBANK_ADMIN_USERNAME');
        $envPass = (string)getenv('ZURUBANK_ADMIN_PASSWORD');
        if ($count === 0 && $envUser !== '' && strlen($envPass) >= ZB_ADMIN_MIN_PASSWORD
            && hash_equals(strtolower($envUser), strtolower(trim($username)))
            && hash_equals($envPass, $password)) {
            $id = zb_admin_create($pdo, $envUser, 'First administrator', $envPass, 'admin', null);
            error_log("[admin] first admin #{$id} created from ZURUBANK_ADMIN_USERNAME");
            $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
            return zb_admin_find($pdo, $id);
        }
        // Same work as a real check, so a missing username is not quicker to spot.
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        return null;
    }
    if (!$row['active'] || !password_verify($password, $row['password_hash'])) return null;
    if (password_needs_rehash($row['password_hash'], PASSWORD_DEFAULT)) {
        zb_admin_set_password($pdo, (int)$row['id'], $password);
    }
    $pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
    return $row;
}

/** Starts the signed-in session for a staff member. */
function zb_admin_sign_in(array $row): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_user_id'] = (int)$row['id'];
    $_SESSION['admin_username'] = (string)$row['username'];
    $_SESSION['admin_name'] = (string)$row['full_name'];
    $_SESSION['admin_role'] = (string)$row['role'];
    $_SESSION['admin_last_seen'] = time();
}

function zb_admin_sign_out(): void
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    foreach (['admin_logged_in', 'admin_user_id', 'admin_username', 'admin_name', 'admin_role', 'admin_last_seen'] as $key) {
        unset($_SESSION[$key]);
    }
    session_regenerate_id(true);
}

/**
 * The signed-in staff member, re-read from the database on every request so a deactivated
 * account stops working at once. Sessions end after 30 minutes without activity.
 */
function zb_admin_current(PDO $pdo): ?array
{
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_user_id'])) return null;
    if (time() - (int)($_SESSION['admin_last_seen'] ?? 0) > 1800) {
        zb_admin_sign_out();
        return null;
    }
    $row = zb_admin_find($pdo, (int)$_SESSION['admin_user_id']);
    if ($row === null || !$row['active']) {
        zb_admin_sign_out();
        return null;
    }
    $_SESSION['admin_last_seen'] = time();
    $_SESSION['admin_role'] = (string)$row['role'];
    return $row;
}
