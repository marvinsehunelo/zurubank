<?php
/**
 * Identity virtual accounts (VouchMorph identity reservation accounts).
 *
 * Works like a mobile-number eWallet: every identity (national ID, phone,
 * email ...) gets ONE virtual account per currency at this bank, opened
 * automatically the first time VouchMorph needs to park money for it, and
 * reused on every later request. An identity may have one at every bank.
 *
 * Each virtual account is a real row in `accounts` (type
 * identity_reservation), so the bank's existing verify, deposit, hold,
 * debit and release endpoints work on it unchanged. All of them belong to
 * one system customer, "VouchMorph Identity Holding" - the bank's identity
 * holding account - and identity_virtual_accounts records which identity
 * each one belongs to. No customer record is created per identity.
 *
 * Shared, identical file in ZuruBank (Backend/helpers) and SaccusSalis
 * (backend/helpers); it adapts to each bank's own column names.
 */

const VA_HOLDING_EMAIL = 'identity-holding@vouchmorph.internal';

function va_columns(PDO $pdo, string $table): array
{
    $st = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_name = ?");
    $st->execute([$table]);
    return array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}

function va_ensure_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS identity_virtual_accounts (
            id              SERIAL PRIMARY KEY,
            identity_type   VARCHAR(40)  NOT NULL,
            identity_value  VARCHAR(120) NOT NULL,
            currency        VARCHAR(10)  NOT NULL DEFAULT 'BWP',
            account_id      INTEGER      NOT NULL,
            account_number  VARCHAR(64)  NOT NULL UNIQUE,
            holding_user_id INTEGER      NOT NULL,
            status          VARCHAR(20)  NOT NULL DEFAULT 'active',
            opened_by       VARCHAR(100),
            created_at      TIMESTAMP    NOT NULL DEFAULT NOW(),
            UNIQUE (identity_type, identity_value, currency)
        )
    ");
    // Request log keyed by bank_reference (idempotency, and what status.php reads).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS reservation_accounts (
            id SERIAL PRIMARY KEY,
            bank_reference VARCHAR(150) UNIQUE NOT NULL,
            status VARCHAR(30) DEFAULT 'active',
            created_at TIMESTAMP DEFAULT NOW()
        )
    ");
    foreach ([
        'reference VARCHAR(150)', 'user_id INTEGER', 'identity_type VARCHAR(40)', 'identity_value VARCHAR(120)',
        'account_id INTEGER', 'account_number VARCHAR(255)', 'account_identifier VARCHAR(64)',
        "account_identifier_type VARCHAR(32) DEFAULT 'account_number'", "currency VARCHAR(10) DEFAULT 'BWP'",
        'requester VARCHAR(100)', 'signature_verified BOOLEAN DEFAULT FALSE', 'updated_at TIMESTAMP DEFAULT NOW()',
    ] as $col) {
        $pdo->exec("ALTER TABLE reservation_accounts ADD COLUMN IF NOT EXISTS {$col}");
    }
    // Older definitions made these mandatory; an identity account has no customer id.
    foreach (['user_id'] as $c) {
        try { $pdo->exec("ALTER TABLE reservation_accounts ALTER COLUMN {$c} DROP NOT NULL"); } catch (Throwable $e) {}
    }
}

/** The bank's single system customer that owns every identity virtual account. */
function va_holding_customer(PDO $pdo): int
{
    $st = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $st->execute([VA_HOLDING_EMAIL]);
    $id = $st->fetchColumn();
    if ($id) return (int)$id;

    $cols = va_columns($pdo, 'users');
    $values = ['full_name' => 'VouchMorph Identity Holding', 'email' => VA_HOLDING_EMAIL];
    $secret = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);   // nobody logs in as this customer
    if (isset($cols['password_hash'])) $values['password_hash'] = $secret;
    if (isset($cols['password'])) $values['password'] = $secret;
    if (isset($cols['phone'])) $values['phone'] = '+267-IDHOLD';          // unique, never a real number
    if (isset($cols['role'])) $values['role'] = 'system';
    if (isset($cols['status'])) $values['status'] = 'active';
    if (isset($cols['kyc_status'])) $values['kyc_status'] = 'verified';
    $names = implode(', ', array_keys($values));
    $marks = implode(', ', array_fill(0, count($values), '?'));
    $ins = $pdo->prepare("INSERT INTO users ({$names}) VALUES ({$marks}) RETURNING user_id");
    $ins->execute(array_values($values));
    $id = $ins->fetchColumn();
    if ($id) return (int)$id;
    $st->execute([VA_HOLDING_EMAIL]);
    return (int)$st->fetchColumn();
}

function va_find(PDO $pdo, string $type, string $value, string $currency): ?array
{
    $st = $pdo->prepare("SELECT * FROM identity_virtual_accounts WHERE identity_type = ? AND identity_value = ? AND currency = ? AND status = 'active'");
    $st->execute([$type, $value, $currency]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Returns the identity's virtual account at this bank, opening it on first use.
 * @return array{account_number: string, account_id: int, opened: bool}
 */
function va_open_or_get(PDO $pdo, string $type, string $value, string $currency, string $requester): array
{
    $type = strtolower(trim($type));
    $value = trim($value);
    $currency = strtoupper($currency ?: 'BWP');
    if ($existing = va_find($pdo, $type, $value, $currency)) {
        return ['account_number' => $existing['account_number'], 'account_id' => (int)$existing['account_id'], 'opened' => false];
    }
    $holder = va_holding_customer($pdo);
    $cols = va_columns($pdo, 'accounts');
    // 8-digit numbers starting with 9 (90000000-99999999) mark virtual accounts
    // at a glance and stay within the integer range some bank endpoints
    // compare account numbers against (a 10-digit number overflowed there).
    do {
        $number = '9' . str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $chk = $pdo->prepare("SELECT 1 FROM accounts WHERE account_number = ?");
        $chk->execute([$number]);
    } while ($chk->fetchColumn());
    $values = ['user_id' => $holder, 'account_number' => $number, 'account_type' => 'identity_reservation', 'balance' => 0, 'currency' => $currency];
    if (isset($cols['status'])) $values['status'] = 'active';
    if (isset($cols['available_balance'])) $values['available_balance'] = 0;
    if (isset($cols['held_amount'])) $values['held_amount'] = 0;
    if (isset($cols['held_balance'])) $values['held_balance'] = 0;
    if (isset($cols['is_frozen'])) $values['is_frozen'] = 'false';
    $names = implode(', ', array_keys($values));
    $marks = implode(', ', array_fill(0, count($values), '?'));
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO accounts ({$names}) VALUES ({$marks}) RETURNING account_id");
        $ins->execute(array_values($values));
        $accountId = (int)$ins->fetchColumn();
        $map = $pdo->prepare("
            INSERT INTO identity_virtual_accounts (identity_type, identity_value, currency, account_id, account_number, holding_user_id, opened_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT (identity_type, identity_value, currency) DO NOTHING
            RETURNING id
        ");
        $map->execute([$type, $value, $currency, $accountId, $number, $holder, $requester]);
        if (!$map->fetchColumn()) {
            // Opened concurrently by another request: keep theirs, drop ours.
            $pdo->rollBack();
            $e = va_find($pdo, $type, $value, $currency);
            return ['account_number' => $e['account_number'], 'account_id' => (int)$e['account_id'], 'opened' => false];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['account_number' => $number, 'account_id' => $accountId, 'opened' => true];
}
