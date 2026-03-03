<?php
declare(strict_types=1);

// --- CRITICAL: Error Suppression for Clean JSON Output ---
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

header("Content-Type: application/json; charset=utf-8");
require_once __DIR__ . "/../config/db.php";

/**
 * Sends a structured JSON response and terminates the script.
 */
function jsonResponse(string $status, string $message, array $extra = []): void {
    echo json_encode(array_merge(["status" => $status, "message" => $message], $extra));
    exit;
}

// Ensure PDO is available via the required file
global $pdo;
if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse("error", "Database connection failed or PDO object not available.");
}

try {
    // 1. INPUT HANDLING & AUTHENTICATION (API Style)
    $input = json_decode(file_get_contents("php://input"), true);
    if (is_array($input)) $_POST = array_merge($_POST, $input);

    // --- AUTHENTICATION ---
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = $headers["Authorization"] ?? $_POST["token"] ?? null;
    $apiKey = $headers["X-API-Key"] ?? $_POST["api_key"] ?? null;
    $userId = null; 

    if ($apiKey) {
        if (!in_array($apiKey, ["ZURU_LOCAL_KEY_ABC123"], true)) {
            throw new Exception("Invalid API key");
        }
        $userId = 2; // Middleman Account (System User)
    } else {
        if (is_string($token) && str_starts_with($token, "Bearer ")) { 
            $token = trim(substr($token, 7));
        }

        if (empty($token)) {
             throw new Exception("Authentication token missing");
        }
        
        $stmt = $pdo->prepare("
            SELECT s.*, u.user_id
            FROM sessions s JOIN users u ON s.user_id = u.user_id
            WHERE s.token = ? AND (s.expires_at IS NULL OR s.expires_at > NOW())
        ");
        $stmt->execute([$token]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) throw new Exception("Invalid or expired token");
        $userId = (int)$session["user_id"];
    }

    // --------------------------------------------------------------------
    // INPUT
    // --------------------------------------------------------------------
    // We only use the ESCROW_ACCOUNT_TYPE now, no hardcoded number needed here.
    $ESCROW_ACCOUNT_TYPE = "middleman_escrow"; 
    
    // Recipient account comes from the SwapService payload
    $recipientAccount = $_POST["recipient_account"] ?? $_POST["target"] ?? $_POST["recipient"] ?? null;
    $amount = (float)($_POST["amount"] ?? 0);
    $step1Reference = $_POST["step1_reference"] ?? null;
    $transactionReference = "TRANSFER_" . uniqid(); 

    if (!$recipientAccount || $amount <= 0) {
        throw new Exception("Invalid input: recipient account or amount missing");
    }

    // Begin DB transaction
    $pdo->beginTransaction();

    // --------------------------------------------------------------------
    // 2. FETCH ESCROW ACCOUNT (Source) AND LOCK IT - LOOKING UP BY TYPE
    // --------------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT * FROM accounts
        WHERE account_type = ? 
        LIMIT 1 -- Assuming only one primary escrow account exists
        FOR UPDATE
    ");
    $stmt->execute([$ESCROW_ACCOUNT_TYPE]);
    $srcAcc = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get the account number dynamically for checks and logging
    $sourceAccountNumber = $srcAcc['account_number'] ?? null;

    if (!$srcAcc) throw new Exception("Source escrow account ({$ESCROW_ACCOUNT_TYPE}) not configured.");
    
    // Now that we have the dynamic number, we check if the destination is the same.
    if ($recipientAccount === $sourceAccountNumber) {
        throw new Exception("Cannot transfer from escrow account to itself.");
    }

    if ((bool)($srcAcc['is_frozen'] ?? 0)) throw new Exception("Source Escrow account is frozen.");

    if ($srcAcc["balance"] < $amount) {
        throw new Exception("Insufficient escrow funds (Available: " . number_format($srcAcc["balance"], 2) . ")");
    }

    // --------------------------------------------------------------------
    // 3. FETCH RECIPIENT ACCOUNT (Target) AND LOCK IT
    // --------------------------------------------------------------------
    $stmt = $pdo->prepare("
        SELECT * FROM accounts
        WHERE account_number = ?
        FOR UPDATE
    ");
    $stmt->execute([$recipientAccount]);
    $tgtAcc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tgtAcc) throw new Exception("Recipient account not found: " . $recipientAccount);
    if ((bool)($tgtAcc['is_frozen'] ?? 0)) throw new Exception("Recipient account is frozen.");

    $targetUserId = (int)$tgtAcc["user_id"];

    // --------------------------------------------------------------------
    // 4. UPDATE BALANCES (Debit Escrow, Credit Recipient)
    // --------------------------------------------------------------------
    $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE account_id = ?")
        ->execute([$amount, $srcAcc["account_id"]]);

    $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE account_id = ?")
        ->execute([$amount, $tgtAcc["account_id"]]);

    // --------------------------------------------------------------------
    // 5. TRANSACTION LOGS (Debit and Credit)
    // --------------------------------------------------------------------
    $stmt = $pdo->prepare("
        INSERT INTO transactions 
            (user_id, account_id, reference, from_account, to_account, type, amount, description, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    // Debit Log (Escrow Source Account)
    $stmt->execute([
        $userId, // User initiating (System/Session)
        $srcAcc["account_id"], // Account ID being debited
        $transactionReference,
        $srcAcc["account_number"],
        $tgtAcc["account_number"],
        "transfer",
        $amount,
        "Zurubank internal transfer (Step 3) DEBIT from escrow related to Step 1 ref: {$step1Reference}",
        "completed"
    ]);

    // Credit Log (Recipient Target Account)
    $stmt->execute([
        $targetUserId, // Recipient's User ID
        $tgtAcc["account_id"], // Account ID being credited
        $transactionReference,
        $srcAcc["account_number"],
        $tgtAcc["account_number"],
        "transfer",
        $amount,
        "Zurubank internal transfer (Step 3) CREDIT to recipient from escrow",
        "completed"
    ]);

    $pdo->commit();

    // --------------------------------------------------------------------
    // SUCCESS RESPONSE
    // --------------------------------------------------------------------
    jsonResponse("success", "Internal transfer of P" . number_format($amount, 2) . " completed.", [
        "transaction_reference" => $transactionReference,
        "step3_reference" => $transactionReference,
        "final_amount" => $amount
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    
    jsonResponse("error", $e->getMessage());
}
