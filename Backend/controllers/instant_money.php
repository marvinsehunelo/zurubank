<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php'; // $pdo

// Load settings
$config = require_once __DIR__ . '/../config/settings.php';
$currency = $config['currency'] ?? 'USD';
$creation_fee = $config['fees']['creation_fee'] ?? 0.00;
$swap_fee     = $config['fees']['swap_fee'] ?? 0.00;
$admin_fee    = $config['fees']['admin_fee'] ?? 0.00;
$sms_fee      = $config['fees']['sms_fee'] ?? 0.00;

$used_swap_split_bank      = $config['split']['used_swap']['bank_share'] ?? 0.6;
$used_swap_split_middleman = $config['split']['used_swap']['middleman_share'] ?? 0.4;

// Ensure errors are logged but not output
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// JSON helper
function jsonResponse($success, $message, $extra = []) {
    error_log("API Response: $message");
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Check user session
if (!isset($_SESSION['user']['id'])) {
    jsonResponse(false, 'Not authenticated');
}
$user_id = $_SESSION['user']['id'];
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ---------------- CREATE VOUCHER ----------------
        case 'transfer':
            $from_account_id = $_POST['from_account_id'] ?? null;
            $recipient_phone = trim($_POST['recipient_phone'] ?? '');
            $amount = floatval($_POST['amount'] ?? 0);

            if (!$from_account_id || !$recipient_phone || $amount <= 0) {
                throw new Exception("Invalid input data");
            }

            // Fetch sender account
            $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_id=? AND user_id=?");
            $stmt->execute([$from_account_id, $user_id]);
            $sender_acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sender_acc) throw new Exception("Account not found");

            $total_fees = $creation_fee + $swap_fee + $admin_fee + $sms_fee;
            $total_deduction = $amount + $total_fees;
            if ($sender_acc['balance'] < $total_deduction) throw new Exception("Insufficient funds");

            $pdo->beginTransaction();

            // Deduct total from sender
            $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id=?");
            $stmt->execute([$total_deduction, $from_account_id]);

            // Generate unique voucher
            do {
                $voucher_number = str_pad(mt_rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("SELECT voucher_id FROM instant_money_vouchers WHERE voucher_number=? LIMIT 1");
                $stmt->execute([$voucher_number]);
                $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            } while ($exists);

            $voucher_pin = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

            // Insert voucher
            $stmt = $pdo->prepare("
                INSERT INTO instant_money_vouchers
                (voucher_number, voucher_pin, amount, currency, status, created_by, recipient_phone, redeemed_by, 
                 voucher_created_at, voucher_expires_at, swap_enabled, swap_made_at, swap_expires_at, swap_fee_paid_by)
                VALUES (?, ?, ?, ?, 'active', ?, ?, NULL, NOW(), NOW() + INTERVAL '7 days', TRUE, NOW(), NOW() + INTERVAL '1 day', 'sender')
            ");
            $stmt->execute([$voucher_number, $voucher_pin, $amount, $currency, $user_id, $recipient_phone]);
            $voucher_id = $pdo->lastInsertId();

            // Fetch ledger accounts
            $needed_types = ['middleman_escrow','partner_bank_settlement','middleman_revenue','sms_provider_settlement'];
            $ledger_accounts = [];
            foreach ($needed_types as $type) {
                $stmt_acc = $pdo->prepare("SELECT account_id FROM accounts WHERE account_type=? LIMIT 1");
                $stmt_acc->execute([$type]);
                $acc = $stmt_acc->fetch(PDO::FETCH_ASSOC);
                if (!$acc) throw new Exception("Ledger account missing: $type");
                $ledger_accounts[$type] = $acc['account_id'];
            }

            // Allocate fees
            $bank_share      = ($swap_fee + $admin_fee) * $used_swap_split_bank;
            $middleman_share = ($swap_fee + $admin_fee) * $used_swap_split_middleman;
            $sms_share       = $sms_fee;
            $creation_share  = $creation_fee;

            $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id=?");
            $stmt->execute([$bank_share, $ledger_accounts['partner_bank_settlement']]);
            $stmt->execute([$middleman_share, $ledger_accounts['middleman_revenue']]);
            $stmt->execute([$sms_share, $ledger_accounts['sms_provider_settlement']]);
            $stmt->execute([$creation_share, $ledger_accounts['middleman_escrow']]);

            // Ledger entries using voucher_number as reference_id
            $swap_ledger = [
                ['debit'=>$from_account_id,'credit'=>$ledger_accounts['middleman_escrow'],'amount'=>$total_fees,'ref'=>"CREATION-FEE-$voucher_number"],
                ['debit'=>$ledger_accounts['middleman_escrow'],'credit'=>$ledger_accounts['partner_bank_settlement'],'amount'=>$bank_share,'ref'=>"USED-BANK-$voucher_number"],
                ['debit'=>$ledger_accounts['middleman_escrow'],'credit'=>$ledger_accounts['middleman_revenue'],'amount'=>$middleman_share,'ref'=>"USED-MID-$voucher_number"],
                ['debit'=>$ledger_accounts['middleman_escrow'],'credit'=>$ledger_accounts['sms_provider_settlement'],'amount'=>$sms_share,'ref'=>"USED-SMS-$voucher_number"],
            ];

            $stmt_ledger_insert = $pdo->prepare("
                INSERT INTO swap_ledger (reference_id, debit_account, credit_account, amount, description, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            foreach ($swap_ledger as $e) {
                $stmt_ledger_insert->execute([$voucher_number, $e['debit'], $e['credit'], $e['amount'], $e['ref']]);
            }

            $pdo->commit();
            jsonResponse(true, "Voucher created successfully", [
                'voucher_number' => $voucher_number,
                'voucher_pin' => $voucher_pin,
                'amount' => $amount
            ]);
            break;

        // ---------------- LIST VOUCHERS ----------------
        case 'list_vouchers':
            $stmt = $pdo->prepare("
                SELECT voucher_id, voucher_number, voucher_pin, amount, currency, status, created_by, recipient_phone,
                       redeemed_by, voucher_created_at, voucher_expires_at, swap_enabled, swap_fee_paid_by, swap_expires_at
                FROM instant_money_vouchers
                WHERE created_by=?
                ORDER BY voucher_created_at DESC
            ");
            $stmt->execute([$user_id]);
            $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(true, 'Vouchers loaded', ['vouchers' => $vouchers]);
            break;

        // ---------------- REVERSE VOUCHER ----------------
        case 'reverse':
            $voucher_number = $_POST['voucher_number'] ?? '';
            if (!$voucher_number) throw new Exception('Voucher code missing');

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT voucher_id, amount, status FROM instant_money_vouchers WHERE voucher_number=? AND created_by=? FOR UPDATE");
            $stmt->execute([$voucher_number, $user_id]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$voucher) throw new Exception("Voucher not found");
            if ($voucher['status'] !== 'active') throw new Exception("Voucher cannot be reversed");

            $stmt = $pdo->prepare("SELECT account_id FROM accounts WHERE user_id=? LIMIT 1");
            $stmt->execute([$user_id]);
            $from_acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$from_acc) throw new Exception('Sender account not found');

            $stmt_acc = $pdo->prepare("SELECT account_id FROM accounts WHERE account_type=? LIMIT 1");
            $stmt_acc->execute(['middleman_escrow']);
            $escrow_acc = $stmt_acc->fetch(PDO::FETCH_ASSOC);
            if (!$escrow_acc) throw new Exception("Ledger account missing: middleman_escrow");
            $escrow_account_id = $escrow_acc['account_id'];

            $refund_amount = $voucher['amount'] + $creation_fee + $swap_fee + $admin_fee + $sms_fee;
            $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id=?");
            $stmt->execute([$refund_amount, $from_acc['account_id']]);

            $stmt = $pdo->prepare("UPDATE instant_money_vouchers SET status='reversed' WHERE voucher_id=?");
            $stmt->execute([$voucher['voucher_id']]);

            $stmt = $pdo->prepare("
                INSERT INTO swap_ledger (reference_id, debit_account, credit_account, amount, description, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$voucher_number, $escrow_account_id, $from_acc['account_id'], $refund_amount, "REVERSAL-$voucher_number"]);

            $pdo->commit();
            jsonResponse(true, "Voucher reversed successfully");
            break;

        default:
            jsonResponse(false, 'Invalid action');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Voucher API Error: " . $e->getMessage() . " on line " . $e->getLine());
    jsonResponse(false, "Transaction failed: " . $e->getMessage());
}

