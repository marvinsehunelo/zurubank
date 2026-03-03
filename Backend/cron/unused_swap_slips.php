<?php
/**
 * backend/cron/unused_swap_slips.php
 *
 * Safely process expired swaps:
 * - find vouchers where swap_enabled = 1 and swap_expires_at < NOW()
 * - debit creation_fee from middleman_escrow
 * - credit 60% to partner_bank_settlement and 40% to middleman_revenue
 * - insert swap_ledger rows and set swap_enabled = 0
 */

function processExpiredSwaps(PDO $pdo, array $config): void
{
    $creation_fee = isset($config['fees']['creation_fee']) ? floatval($config['fees']['creation_fee']) : 10.00;
    $bank_share_ratio = isset($config['split']['used_swap']['bank_share']) ? floatval($config['split']['used_swap']['bank_share']) : 0.6;
    $middleman_share_ratio = isset($config['split']['used_swap']['middleman_share']) ? floatval($config['split']['used_swap']['middleman_share']) : 0.4;

    if ($creation_fee <= 0 || ($bank_share_ratio + $middleman_share_ratio) == 0) {
        error_log('processExpiredSwaps: invalid configuration');
        return;
    }

    try {
        // We set this to ensure any transaction failure throws an exception immediately
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // find expired swaps
        $stmt_v = $pdo->prepare(
            "SELECT voucher_id, voucher_number
             FROM instant_money_vouchers
             WHERE swap_enabled = 1
               AND swap_expires_at < NOW()
             FOR UPDATE"
        );
        $stmt_v->execute();
        $expired = $stmt_v->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expired)) {
            error_log('processExpiredSwaps: no expired swaps');
            return;
        }

        // load ledger account ids
        $types = ['middleman_escrow', 'partner_bank_settlement', 'middleman_revenue'];
        $ledger = [];
        $stmt_acc = $pdo->prepare("SELECT account_id FROM accounts WHERE account_type = ? LIMIT 1");
        foreach ($types as $t) {
            $stmt_acc->execute([$t]);
            $r = $stmt_acc->fetch(PDO::FETCH_ASSOC);
            if (!$r || empty($r['account_id'])) {
                throw new Exception("Missing ledger account: $t");
            }
            $ledger[$t] = (int)$r['account_id'];
        }

        $escrow_amount = $creation_fee;
        $bank_amount = round($creation_fee * $bank_share_ratio, 6);
        $mid_amount = round($creation_fee * $middleman_share_ratio, 6);

        // prepared statements
        $stmt_debit = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?");
        $stmt_credit = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
        $stmt_ledger = $pdo->prepare(
            "INSERT INTO swap_ledger (ref_voucher_id, debit_account, credit_account, amount, description, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt_disable = $pdo->prepare("UPDATE instant_money_vouchers SET swap_enabled = 0 WHERE voucher_id = ?");

        foreach ($expired as $v) {
            $pdo->beginTransaction(); // Start transaction for each individual voucher process
            try {
                $vid = (int)$v['voucher_id'];
                $vnum = $v['voucher_number'];

                // debit escrow
                $stmt_debit->execute([$escrow_amount, $ledger['middleman_escrow']]);

                // credit bank
                $stmt_credit->execute([$bank_amount, $ledger['partner_bank_settlement']]);
                $stmt_ledger->execute([$vid, $ledger['middleman_escrow'], $ledger['partner_bank_settlement'], $bank_amount, "UNUSED-SWAP-BANK-{$vnum}"]);

                // credit middleman
                $stmt_credit->execute([$mid_amount, $ledger['middleman_revenue']]);
                $stmt_ledger->execute([$vid, $ledger['middleman_escrow'], $ledger['middleman_revenue'], $mid_amount, "UNUSED-SWAP-MID-{$vnum}"]);

                // disable swap
                $stmt_disable->execute([$vid]);

                $pdo->commit();
                error_log("processExpiredSwaps: processed {$vnum}");
            } catch (Exception $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("processExpiredSwaps: failed {$v['voucher_number']} - " . $ex->getMessage());
            }
        }
    } catch (Exception $e) {
        // This catches errors outside the per-voucher transaction (like DB connection or ledger account lookup failure)
        error_log('processExpiredSwaps: fatal - ' . $e->getMessage());
    }
}

// Auto-run when executed from CLI
if (php_sapi_name() === 'cli') {
    $dbPath = __DIR__ . '/../../backend/config/db.php';
    $cfgPath = __DIR__ . '/../../backend/config/settings.php';
    if (!file_exists($dbPath) || !file_exists($cfgPath)) {
        fwrite(STDERR, "Missing config/db files\n");
        exit(1);
    }
    require_once $dbPath;   // expects $pdo
    $config = require $cfgPath;
    processExpiredSwaps($pdo, $config);
}

