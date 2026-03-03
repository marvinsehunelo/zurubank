<?php
/**
 * Function to process expired swaps from instant_money_vouchers table.
 *
 * @param PDO $pdo
 * @param array $config
 */
function processExpiredSwaps(PDO $pdo, array $config): void
{
    $creation_fee = $config['fees']['creation_fee'] ?? 10.0;
    $bank_share_ratio = $config['split']['used_swap']['bank_share'] ?? 0.6;
    $mid_share_ratio = $config['split']['used_swap']['middleman_share'] ?? 0.4;

    if ($creation_fee <= 0 || ($bank_share_ratio + $mid_share_ratio) == 0) {
        error_log('processExpiredSwaps: invalid config');
        return;
    }

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Fetch expired swaps
        $stmt_v = $pdo->prepare("
            SELECT voucher_id, voucher_number
            FROM instant_money_vouchers
            WHERE swap_enabled = 1
              AND swap_expires_at < NOW()
            FOR UPDATE
        ");
        $stmt_v->execute();
        $expired = $stmt_v->fetchAll(PDO::FETCH_ASSOC);
        if (empty($expired)) return;

        // Get ledger accounts
        $types = ['middleman_escrow','partner_bank_settlement','middleman_revenue'];
        $ledger = [];
        $stmt_acc = $pdo->prepare('SELECT account_id FROM accounts WHERE account_type = ? LIMIT 1');
        foreach ($types as $t) {
            $stmt_acc->execute([$t]);
            $r = $stmt_acc->fetch(PDO::FETCH_ASSOC);
            if (!$r || empty($r['account_id'])) throw new Exception("Missing ledger account: $t");
            $ledger[$t] = (int)$r['account_id'];
        }

        $bank_amt = round($creation_fee * $bank_share_ratio, 6);
        $mid_amt  = round($creation_fee * $mid_share_ratio, 6);

        $stmt_debit = $pdo->prepare('UPDATE accounts SET balance = balance - ? WHERE account_id = ?');
        $stmt_credit = $pdo->prepare('UPDATE accounts SET balance = balance + ? WHERE account_id = ?');
        $stmt_ledger = $pdo->prepare("
            INSERT INTO swap_ledger (ref_voucher_id, debit_account, credit_account, amount, description, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt_disable = $pdo->prepare('UPDATE instant_money_vouchers SET swap_enabled = 0 WHERE voucher_id = ?');

        foreach ($expired as $v) {
            $pdo->beginTransaction();
            try {
                $vid = (int)$v['voucher_id'];
                $vnum = $v['voucher_number'];

                $stmt_debit->execute([$creation_fee, $ledger['middleman_escrow']]);
                $stmt_credit->execute([$bank_amt, $ledger['partner_bank_settlement']]);
                $stmt_ledger->execute([$vid, $ledger['middleman_escrow'], $ledger['partner_bank_settlement'], $bank_amt, "UNUSED-SWAP-BANK-{$vnum}"]);
                $stmt_credit->execute([$mid_amt, $ledger['middleman_revenue']]);
                $stmt_ledger->execute([$vid, $ledger['middleman_escrow'], $ledger['middleman_revenue'], $mid_amt, "UNUSED-SWAP-MID-{$vnum}"]);
                $stmt_disable->execute([$vid]);

                $pdo->commit();
                error_log("processExpiredSwaps: processed {$vnum}");
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("processExpiredSwaps: failed {$vnum} - {$ex->getMessage()}");
            }
        }
    } catch (Exception $e) {
        error_log('processExpiredSwaps fatal: ' . $e->getMessage());
    }
}
