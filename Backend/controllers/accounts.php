<?php
require_once __DIR__ . '/../config/db.php';

/**
 * Retrieves all bank accounts for a specific user ID.
 * @param int $user_id The ID of the user.
 * @return array Array of account records or an empty array.
 */
function getUserAccounts($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieves a single account by its ID.
 * @param int $account_id The ID of the account.
 * @return array|false The account record or false if not found.
 */
function getAccountById($account_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE account_id = ?");
    $stmt->execute([$account_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Checks if a user already has an account of a specific type (e.g., 'savings').
 * @param int $user_id The ID of the user.
 * @param string $account_type The type of account to check for.
 * @return bool True if the user has the account type, false otherwise.
 */
function userHasAccountType($user_id, $account_type) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT account_id FROM accounts WHERE user_id = ? AND account_type = ?");
    $stmt->execute([$user_id, $account_type]);
    return $stmt->rowCount() > 0;
}

/**
 * Creates a new account for a user.
 * * NOTE: For simplicity, the account number is a random 10-digit string.
 * In a real application, you would ensure its uniqueness with a loop.
 * * @param int $user_id The ID of the user.
 * @param string $account_type The type of account ('savings', 'checking', etc.).
 * @param float $initial_balance The starting balance.
 * @return int|false The new account's ID or false on failure.
 */
function createAccount($user_id, $account_type, $initial_balance = 0.00) {
    global $pdo;

    // Generate a unique (enough) 10-digit account number
    $account_number = strval(mt_rand(1000000000, 9999999999));
    
    // Set default currency and status
    $currency = 'PHP'; // Assuming Philippine Peso, based on your previous examples
    $status = 'active';

    try {
        $stmt = $pdo->prepare("
            INSERT INTO accounts 
            (user_id, account_number, account_type, balance, currency, status) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user_id, 
            $account_number, 
            $account_type, 
            $initial_balance, 
            $currency, 
            $status
        ]);
        
        // Return the ID of the newly created account
        return $pdo->lastInsertId();

    } catch (PDOException $e) {
        // Log error and return failure
        error_log("Account creation failed: " . $e->getMessage());
        return false;
    }
}
?>
