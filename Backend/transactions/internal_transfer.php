<?php
header("Content-Type: application/json");
require_once __DIR__ . "/../config/db.php";

try {
    // ------------------ TOKEN HANDLING ------------------
    $headers = getallheaders();
    $token = null;

    if (isset($headers['Authorization'])) {
        $token = trim(str_replace('Bearer', '', $headers['Authorization']));
    } elseif (isset($_POST['token'])) {
        $token = $_POST['token'];
    }

    if (!$token) throw new Exception("Authorization token required");

    // ------------------ SESSION VALIDATION ------------------
    $stmt = $pdo->prepare("
        SELECT s.*, u.full_name 
        FROM sessions s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.token = ? AND s.expires_at > NOW()
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) throw new Exception("Invalid or expired token");

    $userId = $session['user_id'];

    // ------------------ INPUT VALIDATION ------------------
    $source = $_POST['source'] ?? null;
    $recipientAccountNumber = $_POST['target'] ?? ($_POST['recipient'] ?? null);
    $amount = floatval($_POST['amount'] ?? 0);

    if (!$source || !$recipientAccountNumber || $amount <= 0) {
        throw new Exception("Invalid input: check accounts and amount");
    }

    // ------------------ BEGIN TRANSACTION ------------------
    $pdo->beginTransaction();

    // Source account (owned by user)
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_id = ? AND user_id = ? FOR UPDATE");
    $stmt->execute([$source, $userId]);
    $srcAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$srcAcc) throw new Exception("Source account not found");
    if ($srcAcc['balance'] < $amount) throw new Exception("Insufficient funds in source account");

    // Recipient account (internal, can be any user)
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_number = ? FOR UPDATE");
    $stmt->execute([$recipientAccountNumber]);
    $tgtAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tgtAcc) throw new Exception("Recipient account not found");

    // ------------------ UPDATE BALANCES ------------------
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?");
    $stmt->execute([$amount, $srcAcc['account_id']]);

    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?");
    $stmt->execute([$amount, $tgtAcc['account_id']]);

    // ------------------ LOG TRANSACTIONS ------------------
    $stmt = $pdo->prepare("
        INSERT INTO transactions (user_id, account_id, amount, type, description, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([$userId, $srcAcc['account_id'], -$amount, 'Internal Transfer', "Transferred to {$tgtAcc['account_number']}"]);
    $stmt->execute([$tgtAcc['user_id'], $tgtAcc['account_id'], $amount, 'Internal Transfer Received', "Received from {$srcAcc['account_number']}"]);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Transferred P" . number_format($amount,2) . " to internal account {$recipientAccountNumber}."
    ]);

} catch(Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
