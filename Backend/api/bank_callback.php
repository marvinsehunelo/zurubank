<?php
// zurubank/Backend/api/bank_callback.php
//
// Settlement notices from the central bank for ZuruBank customers.
// See central_bank_notice.php for the signature and the roles.
//
// Before: fell back to a secret written in the code, and looked for
// tables and columns ZuruBank does not have (external_transfer_queue,
// origin_transaction_id, completed_at), so it could not process a notice
// and could never credit an incoming payment. Now it completes, refunds
// or credits against ZuruBank's real accounts and transactions tables.
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/central_bank_notice.php';

[$raw, $n] = cbn_read_verified();
$role = $n['role'] === 'recipient' ? 'recipient' : 'sender';
$status = (string)$n['status'];
$amount = round((float)($n['amount'] ?? 0), 2);
$transferId = (int)$n['transfer_id'];

try {
    $pdo->beginTransaction();
    if (!cbn_claim($pdo, 'ZURUBANK', $n, $raw)) { $pdo->rollBack(); cbn_reply(200, 'success', 'Already processed'); }

    if ($role === 'recipient') {
        if ($status !== 'approved') { $pdo->commit(); cbn_reply(200, 'success', 'Nothing to credit'); }
        if ($amount <= 0) throw new DomainException('Invalid amount');
        $accountNo = (string)($n['recipient_account_number'] ?? '');
        $stmt = $pdo->prepare("SELECT account_id, user_id, status FROM accounts WHERE account_number = ? LIMIT 1 FOR UPDATE");
        $stmt->execute([$accountNo]);
        $acc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$acc) throw new DomainException('Recipient account not found at ZuruBank');
        if (($acc['status'] ?? 'active') !== 'active') throw new DomainException('Recipient account is not active');

        $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")->execute([$amount, $acc['account_id']]);
        $pdo->prepare("
            INSERT INTO transactions (user_id, account_id, from_account, to_account, type, amount, reference, description, status)
            VALUES (?, ?, ?, ?, 'interbank_credit', ?, ?, ?, 'completed')
        ")->execute([$acc['user_id'], $acc['account_id'], (string)($n['from_account'] ?? ''), $accountNo, $amount, 'CB-' . $transferId,
                     'From ' . ($n['from_bank_code'] ?? '?') . ' via central bank transfer ' . $transferId]);
        $pdo->commit();
        cbn_reply(200, 'success', 'Recipient credited');
    }

    // Sender: one of our own outgoing transfers, found by the reference we sent.
    $stmt = $pdo->prepare("SELECT transaction_id, account_id, from_account, amount, status FROM transactions WHERE reference = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([(string)($n['reference_code'] ?? '')]);
    $tx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tx) throw new DomainException('No outgoing transfer with reference ' . ($n['reference_code'] ?? '?'));
    if ($tx['status'] !== 'pending') { $pdo->commit(); cbn_reply(200, 'success', 'Transfer already ' . $tx['status']); }

    if ($status === 'approved') {
        $pdo->prepare("UPDATE transactions SET status = 'completed' WHERE transaction_id = ?")->execute([$tx['transaction_id']]);
        $msg = 'Transfer completed';
    } elseif ($status === 'rejected') {
        $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")->execute([(float)$tx['amount'], $tx['account_id']]);
        $pdo->prepare("UPDATE transactions SET status = 'failed', description = COALESCE(description, '') || ? WHERE transaction_id = ?")
            ->execute([' | Rejected by central bank: ' . substr((string)($n['message'] ?? ''), 0, 200) . '; refunded', $tx['transaction_id']]);
        $msg = 'Transfer rejected; customer refunded';
    } else {
        throw new DomainException('Unknown status ' . $status);
    }
    $pdo->commit();
    cbn_reply(200, 'success', $msg);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    cbn_reply(422, 'error', $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[bank_callback] ' . $e->getMessage());
    cbn_reply(500, 'error', 'Notice could not be processed');
}
