<?php
// zurubank/Backend/api/settlement_stores.php
// How the settlement desk reaches the books of the two banks on this server.
require_once __DIR__ . '/settlement_desk.php';

/** ZuruBank: accounts + transactions. Internal accounts belong to the bank's own system/admin user. */
function zurubank_settlement_store(PDO $pdo): array {
    static $bankUser = null;
    if ($bankUser === null) {
        $bankUser = (int)$pdo->query("
            SELECT COALESCE(
                (SELECT user_id FROM users WHERE role IN ('system', 'admin', 'superadmin') ORDER BY user_id LIMIT 1),
                (SELECT MIN(user_id) FROM users))
        ")->fetchColumn();
        if ($bankUser <= 0) throw new RuntimeException('ZuruBank has no user to own internal settlement accounts');
    }
    $accountId = function (string $no, bool $lock) use ($pdo): ?int {
        $s = $pdo->prepare("SELECT account_id FROM accounts WHERE account_number = ? ORDER BY account_id LIMIT 1" . ($lock ? " FOR UPDATE" : ""));
        $s->execute([$no]);
        $id = $s->fetchColumn();
        return $id === false ? null : (int)$id;
    };
    return [
        'ensure_account' => function (string $no, string $name) use ($pdo, $bankUser): void {
            $pdo->prepare("INSERT INTO accounts (user_id, account_number, account_type, balance, currency, status)
                           SELECT ?, ?, 'internal', 0, 'BWP', 'active' WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE account_number = ?)")
                ->execute([$bankUser, $no, $no]);
        },
        'move' => function (string $no, float $delta) use ($pdo, $accountId): void {
            $id = $accountId($no, true);
            if ($id === null) throw new RuntimeException("Account {$no} not found at ZuruBank");
            $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")->execute([$delta, $id]);
        },
        'log' => function (array $e) use ($pdo, $accountId, $bankUser): void {
            $pdo->prepare("INSERT INTO transactions (user_id, account_id, from_account, to_account, type, amount, reference, description, status)
                           VALUES (?, ?, ?, ?, 'vm_settlement', ?, ?, ?, ?)")
                ->execute([$bankUser, $accountId($e['from'], false) ?? 0, $e['from'], $e['to'], $e['amount'], $e['reference'], $e['note'], $e['status']]);
        },
    ];
}

/** ABSA (simulated here): absa_accounts + absa_settlement_log. */
function absa_settlement_store(PDO $pdo): array {
    $pdo->exec("CREATE TABLE IF NOT EXISTS absa_accounts (id SERIAL PRIMARY KEY, account_number VARCHAR(64) NOT NULL UNIQUE, account_name VARCHAR(150),
                balance NUMERIC(15,2) NOT NULL DEFAULT 0, currency VARCHAR(3) NOT NULL DEFAULT 'BWP', created_at TIMESTAMP NOT NULL DEFAULT NOW())");
    $pdo->exec("CREATE TABLE IF NOT EXISTS absa_settlement_log (id SERIAL PRIMARY KEY, reference VARCHAR(64), from_account VARCHAR(64), to_account VARCHAR(64),
                amount NUMERIC(15,2), status VARCHAR(20), note TEXT, created_at TIMESTAMP NOT NULL DEFAULT NOW())");
    return [
        'ensure_account' => function (string $no, string $name) use ($pdo): void {
            $pdo->prepare("INSERT INTO absa_accounts (account_number, account_name, balance, currency) VALUES (?, ?, 0, 'BWP') ON CONFLICT (account_number) DO NOTHING")
                ->execute([$no, $name]);
        },
        'move' => function (string $no, float $delta) use ($pdo): void {
            $s = $pdo->prepare("SELECT account_number FROM absa_accounts WHERE account_number = ? FOR UPDATE");
            $s->execute([$no]);
            if (!$s->fetchColumn()) throw new RuntimeException("Account {$no} not found at ABSA");
            $pdo->prepare("UPDATE absa_accounts SET balance = balance + ? WHERE account_number = ?")->execute([$delta, $no]);
        },
        'log' => function (array $e) use ($pdo): void {
            $pdo->prepare("INSERT INTO absa_settlement_log (reference, from_account, to_account, amount, status, note) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$e['reference'], $e['from'], $e['to'], $e['amount'], $e['status'], $e['note']]);
        },
    ];
}

function zurubank_public_url(): string { return rtrim(getenv('ZURUBANK_PUBLIC_URL') ?: 'https://zurubank-production.up.railway.app', '/'); }

function zurubank_desk(PDO $pdo): SettlementDesk {
    return new SettlementDesk($pdo, 'ZURUBANK', zurubank_settlement_store($pdo), (string)getenv('CENTRAL_BANK_API_SECRET'),
        zurubank_public_url() . '/Backend/api/bank_callback.php');
}
function absa_desk(PDO $pdo): SettlementDesk {
    return new SettlementDesk($pdo, 'ABSA', absa_settlement_store($pdo), (string)getenv('CENTRAL_BANK_ABSA_API_SECRET'),
        zurubank_public_url() . '/Backend/api/absa_central_bank_callback.php');
}
