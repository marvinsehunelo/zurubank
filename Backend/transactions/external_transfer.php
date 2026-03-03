<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

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
    $recipientBankName = $_POST['recipient_bank_name'] ?? null;
    $recipientBankCode = $_POST['recipient_bank_code'] ?? null;
    $recipientAccountNumber = $_POST['recipient_account_number'] ?? null;
    $amount = floatval($_POST['amount'] ?? 0);

    if (!$source || !$recipientBankName || !$recipientBankCode || !$recipientAccountNumber || $amount <= 0) {
        throw new Exception("Invalid input data");
    }

    // ------------------ FETCH SOURCE ACCOUNT ------------------
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_id=? AND user_id=? FOR UPDATE");
    $stmt->execute([$source, $userId]);
    $srcAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$srcAcc) throw new Exception("Source account not found");
    if ($srcAcc['balance'] < $amount) throw new Exception("Insufficient funds in source account");

    // ------------------ BEGIN TRANSACTION ------------------
    $pdo->beginTransaction();

    // ------------------ DEDUCT FROM SOURCE ------------------
    $stmt = $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id=?");
    $stmt->execute([$amount, $srcAcc['account_id']]);

    // ------------------ LOG EXTERNAL TRANSFER ------------------
    $description = "External transfer to $recipientBankName ($recipientBankCode:$recipientAccountNumber)";
    $stmt = $pdo->prepare("
        INSERT INTO transactions (account_id, user_id, type, amount, description, status, created_at)
        VALUES (?, ?, 'External Transfer', ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$srcAcc['account_id'], $userId, $amount, $description]);

    $pdo->commit();

    // ------------------ RESPONSE ------------------
    echo json_encode([
        'status' => 'success',
        'message' => "P" . number_format($amount,2) . " transferred from account {$srcAcc['account_number']} to $recipientBankName account $recipientAccountNumber."
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
