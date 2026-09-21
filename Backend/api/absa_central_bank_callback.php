<?php
// zurubank/Backend/api/absa_central_bank_callback.php
//
// Settlement notices from the central bank for ABSA customers. ABSA is
// simulated on ZuruBank's server; its customers are in absa_accounts and
// its movements in absa_central_bank_credits, both in ZuruBank's database.
// See central_bank_notice.php for the signature and the roles. ABSA does
// not send interbank transfers yet, so only incoming credits apply.
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/central_bank_notice.php';
require_once __DIR__ . '/settlement_stores.php';

[$raw, $n] = cbn_read_verified();
$transferId = (int)$n['transfer_id'];
$amount = round((float)($n['amount'] ?? 0), 2);

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS absa_accounts (
            id SERIAL PRIMARY KEY,
            account_number VARCHAR(64) NOT NULL UNIQUE,
            account_name VARCHAR(150),
            balance NUMERIC(15,2) NOT NULL DEFAULT 0,
            currency VARCHAR(3) NOT NULL DEFAULT 'BWP',
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS absa_central_bank_credits (
            id SERIAL PRIMARY KEY,
            transfer_id BIGINT NOT NULL UNIQUE,
            account_number VARCHAR(64) NOT NULL,
            amount NUMERIC(15,2) NOT NULL,
            from_bank_code VARCHAR(20),
            from_account VARCHAR(64),
            created_at TIMESTAMP NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->beginTransaction();
    if (!cbn_claim($pdo, 'ABSA', $n, $raw)) { $pdo->rollBack(); cbn_reply(200, 'success', 'Already processed'); }

    if ($n['role'] !== 'recipient') {
        // One of ABSA's own VouchMorph settlement payments: complete it, or put the money back.
        $ref = (string)($n['reference_code'] ?? '');
        $line = $pdo->prepare("SELECT amount, status FROM settlement_advice_inbox WHERE line_ref = ? AND bank_code = 'ABSA' FOR UPDATE");
        $line->execute([$ref]);
        $l = $line->fetch(PDO::FETCH_ASSOC);
        if (!$l) throw new DomainException("No ABSA payment with reference {$ref}");
        if ($l['status'] === 'SUBMITTED' && $n['status'] === 'rejected') {
            absa_settlement_store($pdo)['move'](SettlementDesk::clearingAccount('ABSA'), (float)$l['amount']);
        }
        absa_desk($pdo)->onSenderNotice($n);
        $pdo->commit();
        cbn_reply(200, 'success', $n['status'] === 'rejected' ? 'Payment rejected; clearing account refunded' : 'Payment completed');
    }
    if ($n['status'] !== 'approved') { $pdo->commit(); cbn_reply(200, 'success', 'Nothing to credit'); }
    if ($amount <= 0) throw new DomainException('Invalid amount');

    $accountNo = (string)($n['recipient_account_number'] ?? '');
    if (SettlementDesk::isInternal($accountNo)) {
        absa_settlement_store($pdo)['ensure_account']($accountNo, 'VouchMorph settlement');
    }
    $stmt = $pdo->prepare("SELECT account_number FROM absa_accounts WHERE account_number = ? FOR UPDATE");
    $stmt->execute([$accountNo]);
    if (!$stmt->fetchColumn()) throw new DomainException('Recipient account not found at ABSA');

    $pdo->prepare("UPDATE absa_accounts SET balance = balance + ? WHERE account_number = ?")->execute([$amount, $accountNo]);
    $pdo->prepare("INSERT INTO absa_central_bank_credits (transfer_id, account_number, amount, from_bank_code, from_account) VALUES (?, ?, ?, ?, ?)")
        ->execute([$transferId, $accountNo, $amount, (string)($n['from_bank_code'] ?? ''), (string)($n['from_account'] ?? '')]);
    absa_desk($pdo)->recordReceipt((string)($n['reference_code'] ?? ('CB-' . $transferId)), $amount,
        (string)($n['from_bank_code'] ?? ''), $accountNo, 'CENTRAL_BANK', 'CB-' . $transferId);
    $pdo->commit();
    cbn_reply(200, 'success', 'ABSA recipient credited');
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cbn_reply(422, 'error', $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[absa_central_bank_callback] ' . $e->getMessage());
    cbn_reply(500, 'error', 'Notice could not be processed');
}
