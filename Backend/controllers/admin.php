<?php
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/transactions.php';
require_once __DIR__ . '/../config/db.php';

// Get all users
function getAllUsers() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users ORDER BY created_at DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all accounts
function getAllAccounts() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT a.*, u.full_name, u.email FROM accounts a JOIN users u ON a.user_id = u.user_id");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get all transactions
function getAllTransactions() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT t.*, u.full_name, u.email, a.account_type 
                           FROM transactions t
                           JOIN accounts a ON t.account_id = a.account_id
                           JOIN users u ON a.user_id = u.user_id
                           ORDER BY t.created_at DESC");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Freeze/Unfreeze account
function toggleAccountStatus($account_id, $status) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE accounts SET status = ? WHERE account_id = ?");
    return $stmt->execute([$status, $account_id]);
}
?>
