<?php
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

// Ensure all errors are logged but not output
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function jsonResponse($success, $message, $extra = []) {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

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

            // Fetch sender phone
            $stmt = $pdo->prepare("SELECT phone FROM users WHERE user_id=?");
            $stmt->execute([$user_id]);
            $sender = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sender) throw new Exception("Sender not found");
            $sender_phone = $sender['phone'];

            // Check balance
            $stmt = $pdo->prepare("SELECT balance FROM accounts WHERE account_id=? AND user_id=?");
            $stmt->execute([$from_account_id, $user_id]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$acc) throw new Exception("Account not found");
            if ($acc['balance'] < $amount) throw new Exception("Insufficient funds");

            $pdo->beginTransaction();

            // Deduct account
            $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?");
            $stmt->execute([$amount, $from_account_id]);

            // Create voucher (numeric only)
            $voucher_number = str_pad(mt_rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
            $voucher_pin = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);

            // Store voucher with recipient phone
            $stmt = $pdo->prepare("
                INSERT INTO instant_money_vouchers
                (voucher_number, voucher_pin, amount, created_by, redeemed_by, status, created_at, recipient_phone)
                VALUES (?,?,?,?,NULL,'active',NOW(),?)
            ");
            $stmt->execute([$voucher_number, $voucher_pin, $amount, $user_id, $recipient_phone]);

            $pdo->commit();

            // Prepare messages
            $recipient_msg = "You have received an Instant Money Voucher.\nCode: $voucher_number\nPIN: $voucher_pin\nAmount: P$amount";
            $sender_msg = "Your voucher P$amount has been sent to $recipient_phone.\nCode: $voucher_number\nPIN: $voucher_pin";

            // ---------------- SMS PART ----------------
            try {
                $cazacom_pdo = new PDO("mysql:host=localhost;dbname=cazacom;charset=utf8", "root", "");
                $cazacom_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Recipient SMS
                $stmt = $cazacom_pdo->prepare("
                    INSERT INTO sms (user_id, sender_number, target_number, message, cost, direction, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$user_id, $sender_phone, $recipient_phone, $recipient_msg, 0.00, 'sent']);

                // Sender SMS
                $stmt->execute([$user_id, $sender_phone, $sender_phone, $sender_msg, 0.00, 'sent']);

            } catch (Exception $e) {
                error_log("Failed to log SMS to Cazacom: " . $e->getMessage());
            }

            jsonResponse(true, "Voucher created successfully!", [
                'voucher_number' => $voucher_number,
                'voucher_pin' => $voucher_pin
            ]);
            break;

        // ---------------- LIST USER VOUCHERS ----------------
        case 'list_vouchers':
            $stmt = $pdo->prepare("
                SELECT voucher_number, voucher_pin, amount, status, created_at, redeemed_by, recipient_phone,
                DATE_ADD(created_at, INTERVAL 7 DAY) AS expires_at
                FROM instant_money_vouchers
                WHERE created_by=?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$user_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $vouchers = array_map(fn($r) => [
                'voucher_number'   => $r['voucher_number'],
                'voucher_pin'      => $r['voucher_pin'],
                'amount'           => $r['amount'],
                'status'           => $r['status'],
                'redeemed_by'      => $r['redeemed_by'] ?? null,
                'recipient_phone'  => $r['recipient_phone'] ?? null,
                'expires_at'       => $r['expires_at']
            ], $rows);

            jsonResponse(true, 'Vouchers fetched successfully', ['vouchers' => $vouchers]);
            break;

        // ---------------- REVERSE VOUCHER ----------------
        case 'reverse':
            $voucher_number = $_POST['voucher_number'] ?? '';
            if (!$voucher_number) throw new Exception("Voucher code missing");

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT amount, status FROM instant_money_vouchers WHERE voucher_number=? AND created_by=? FOR UPDATE");
            $stmt->execute([$voucher_number, $user_id]);
            $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$voucher) throw new Exception("Voucher not found");
            if ($voucher['status'] !== 'active') throw new Exception("Voucher cannot be reversed");

            // Refund to first account of user
            $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE user_id=? LIMIT 1");
            $stmt->execute([$voucher['amount'], $user_id]);

            // Mark voucher as reversed
            $stmt = $pdo->prepare("UPDATE instant_money_vouchers SET status='reversed' WHERE voucher_number=?");
            $stmt->execute([$voucher_number]);

            $pdo->commit();

            jsonResponse(true, 'Voucher reversed successfully');
            break;

        default:
            jsonResponse(false, 'Invalid action');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    jsonResponse(false, $e->getMessage());
}
