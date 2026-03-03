<?php
require_once __DIR__ . "/../config/db.php";

header("Content-Type: application/json");

try {
    // Create a dummy test user
    $full_name = "Test User";
    $email = "testuser@example.com";
    $phone = "700000001";
    $password = password_hash("password123", PASSWORD_BCRYPT);

    // Start transaction
    $pdo->beginTransaction();

    // Insert into users table
    $stmt = $pdo->prepare("
        INSERT INTO users (full_name, email, phone, password, role, created_at)
        VALUES (?, ?, ?, ?, 'customer', NOW())
    ");
    $stmt->execute([$full_name, $email, $phone, $password]);
    $user_id = $pdo->lastInsertId();

    // Create accounts
    $savingsAcc = "SAV" . str_pad($user_id, 8, "0", STR_PAD_LEFT);
    $currentAcc = "CUR" . str_pad($user_id, 8, "0", STR_PAD_LEFT);

    $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, account_type) VALUES (?, ?, 'savings')");
    $stmt->execute([$user_id, $savingsAcc]);

    $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_number, account_type) VALUES (?, ?, 'current')");
    $stmt->execute([$user_id, $currentAcc]);

    // Create Instant Money wallet
    $stmt = $pdo->prepare("
        INSERT INTO instant_money_wallets (user_id, balance, currency, status, created_at)
        VALUES (?, 0.00, 'BWP', 'active', NOW())
    ");
    $stmt->execute([$user_id]);
    $wallet_id = $pdo->lastInsertId();

    $pdo->commit();

    echo json_encode([
        "success" => true,
        "message" => "Test registration successful",
        "user_id" => $user_id,
        "accounts" => [
            "savings" => $savingsAcc,
            "current" => $currentAcc
        ],
        "instant_money_wallet_id" => $wallet_id
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode([
        "success" => false,
        "message" => "Test failed: " . $e->getMessage()
    ]);
}
