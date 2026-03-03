<?php
header("Content-Type: application/json");
require_once __DIR__ . "/../config/db.php";

try {
    // ------------------ TOKEN HANDLING ------------------
    $headers = getallheaders();
    $token = null;

    if (isset($headers['Authorization'])) {
        // Remove "Bearer " prefix if present
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
    $target = $_POST['target'] ?? null;
    $amount = floatval($_POST['amount'] ?? 0);

    if (!$source || !$target || $source === $target || $amount <= 0) {
        throw new Exception("Invalid input: check accounts and amount");
    }

    // ------------------ BEGIN TRANSACTION ------------------
    $pdo->beginTransaction();

    // Fetch source account (FOR UPDATE locks row)
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_id = ? AND user_id = ? FOR UPDATE");
    $stmt->execute([$source, $userId]);
    $srcAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$srcAcc) throw new Exception("Source account not found");

    // Fetch target account (must also belong to the same user for own transfer)
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_id = ? AND user_id = ? FOR UPDATE");
    $stmt->execute([$target, $userId]);
    $tgtAcc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tgtAcc) throw new Exception("Target account not found");

    if ($srcAcc['balance'] < $amount) throw new Exception("Insufficient funds in source account");

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

    $stmt->execute([$userId, $srcAcc['account_id'], -$amount, 'Own Transfer', "Transferred to {$tgtAcc['account_number']}"]);
    $stmt->execute([$userId, $tgtAcc['account_id'], $amount, 'Own Transfer Received', "Received from {$srcAcc['account_number']}"]);

    $pdo->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Transfer of P" . number_format($amount, 2) . " between your accounts completed."
    ]);

} catch(Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
