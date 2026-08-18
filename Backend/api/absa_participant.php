<?php
declare(strict_types=1);

/**
 * ABSA Participant — standalone HTTP receiver, deployed on Zurubank's
 * infrastructure as its own file. Deliberately self-contained: does
 * NOT require any VouchMorph-repo files (src/Infrastructure/...) —
 * this runs on Zurubank's server, which has no access to VouchMorph's
 * codebase. Auth verification is inlined here rather than importing
 * VouchMorph's AuthSchemeRegistry class.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/crypto.php';

header('Content-Type: application/json');

class AbsaParticipant
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ============================================================
    // ENTRY POINT / ROUTING
    // ============================================================
    public function handleRequest(string $action, array $input, string $rawBody, array $headers): array
    {
        if ($action === 'switch_webhook') {
            return $this->handleSwitchWebhook($input, $rawBody, $headers);
        }

        if (!$this->verifyIncomingAuth($input, $rawBody, $headers)) {
            http_response_code(401);
            return ['success' => false, 'message' => 'Authentication failed'];
        }

        return match ($action) {
            'verify_asset' => $this->verifyAsset($input),
            'place_hold' => $this->placeHold($input),
            'debit_funds' => $this->debitFunds($input),
            'release_hold' => $this->releaseHold($input),
            'process_deposit' => $this->processDeposit($input),
            'verify_account' => $this->verifyAccount($input),
            'generate_token' => $this->generateToken($input),
            'verify_token' => $this->verifyToken($input),
            'confirm_cashout' => $this->confirmCashout($input),
            'check_status' => $this->checkStatus($input['reference'] ?? ''),
            'account_balance' => $this->getBalanceAction($input['account_number'] ?? ''),
            default => ['success' => false, 'message' => "Unknown action: {$action}"],
        };
    }

    /**
     * Self-contained auth check — certificate-based authentication
     * (VouchMorph's real mechanism) plus two legacy paths.
     *
     * ============================================================
     * CRITICAL FIX (previously a fail-open auth bypass):
     * verify_requester_signature() returns an ARRAY -
     * ['valid' => bool, 'message' => string, ...] - in EVERY code path,
     * whether the signature is valid or not. The previous version of
     * this method did `if (verify_requester_signature(...))`, checking
     * the ARRAY ITSELF for truthiness rather than its 'valid' key. In
     * PHP, any non-empty array is truthy - so ['valid' => false, ...]
     * evaluated to true here, meaning ANY request with a certificate
     * and signature field present - valid or garbage - was accepted.
     * Confirmed live: ZURUBANK's own log showed "Signature verification
     * from VOUCHMORPH: INVALID" immediately followed by this code
     * logging "Authenticated via CERTIFICATE" and returning a 200.
     * Always check $verification['valid'] explicitly - see
     * authenticate_request() in helpers/crypto.php for the correct
     * reference pattern this should have matched from the start.
     * ============================================================
     */
    private function verifyIncomingAuth(array $input, string $rawBody, array $headers): bool
    {
        $headersLower = array_change_key_case($headers, CASE_LOWER);

        // Path 1: Certificate-based auth (VouchMorph's real mechanism)
        if (isset($input['certificate'], $input['signature'])) {
            $verification = verify_requester_signature($input, $this->db);
            if ($verification['valid'] ?? false) {
                error_log("[ABSA] Authenticated via CERTIFICATE (VouchMorph, requester: " . ($verification['requester'] ?? 'unknown') . ")");
                return true;
            }
            error_log("[ABSA] Certificate signature INVALID: " . ($verification['message'] ?? 'unknown reason'));
            // Deliberately fall through to the other paths rather than
            // returning false immediately here.
        }

        // Path 2: VouchMorph API key (legacy)
        $providedKey = $headersLower['x-api-key'] ?? null;
        $expectedKey = getenv('ZURUBANK_API_KEY') ?: '';
        if ($providedKey && $expectedKey && hash_equals($expectedKey, $providedKey)) {
            error_log("[ABSA] Authenticated via VOUCHMORPH API key");
            return true;
        }

        // Path 3: centralswitch HMAC shared secret (legacy)
        $timestamp = $headersLower['x-api-timestamp'] ?? null;
        $signature = $headersLower['x-api-signature'] ?? null;
        $secret = getenv('ABSA_SWITCH_SECRET') ?: '';

        if ($timestamp && $signature && $secret) {
            if (abs(time() - (int)$timestamp) <= 300) {
                $expected = hash_hmac('sha256', $timestamp . $rawBody, $secret);
                if (hash_equals($expected, $signature)) {
                    error_log("[ABSA] Authenticated via CENTRALSWITCH HMAC");
                    return true;
                }
            }
        }

        return false;
    }

    // ============================================================
    // SOURCE ROLE — real hold/debit/release
    // ============================================================

    public function verifyAsset(array $payload): array
    {
        $accountNumber = $payload['source_identifier'] ?? $payload['account_number'] ?? null;
        $amount = (float)($payload['amount'] ?? 0);

        if (!$accountNumber) {
            return ['success' => false, 'verified' => false, 'message' => 'source_identifier (account_number) required'];
        }

        $account = $this->getOrCreateAccount($accountNumber);

        if ($account['status'] !== 'ACTIVE') {
            return ['success' => false, 'verified' => false, 'message' => 'Account not active'];
        }

        $available = (float)$account['balance'] - (float)$account['held_balance'];

        return [
            'success' => true,
            'verified' => true,
            'balance' => $available,
            'currency' => $account['currency'],
            'account_name' => $account['account_name'],
            'message' => $available >= $amount ? 'Verified' : 'Verified — available balance may be insufficient at hold time',
            'data' => [],
        ];
    }

    public function placeHold(array $payload): array
    {
        $accountNumber = $payload['source_identifier'] ?? $payload['account_number'] ?? null;
        $amount = (float)($payload['amount'] ?? 0);
        $currency = $payload['currency'] ?? 'BWP';
        $holdReference = 'ABSAHOLD_' . ($payload['reference'] ?? uniqid());

        if (!$accountNumber || $amount <= 0) {
            return [
                'success' => false,
                'hold_placed' => false,
                'message' => 'source_identifier and amount required'
            ];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM absa_accounts WHERE account_number = ? FOR UPDATE");
            $stmt->execute([$accountNumber]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$account) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'hold_placed' => false,
                    'message' => 'Account not found'
                ];
            }

            $available = (float)$account['balance'] - (float)$account['held_balance'];
            if ($available < $amount) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'hold_placed' => false,
                    'message' => 'Insufficient available balance'
                ];
            }

            $this->db->prepare("UPDATE absa_accounts SET held_balance = held_balance + ? WHERE account_number = ?")
                ->execute([$amount, $accountNumber]);

            $expiresAt = $payload['expiry'] ?? date('Y-m-d H:i:s', strtotime('+24 hours'));

            $this->db->prepare("
                INSERT INTO absa_holds (hold_reference, account_number, amount, currency, status, reason, expires_at)
                VALUES (?, ?, ?, ?, 'ACTIVE', ?, ?)
            ")->execute([$holdReference, $accountNumber, $amount, $currency, $payload['hold_reason'] ?? 'VouchMorph swap', $expiresAt]);

            $this->db->commit();

            return [
                // FIX: was missing this key — same gap found and fixed
                // in CAZACOM's hold.php/credit.php this session, and
                // the confirmed cause of the "Hold failed: Hold placed
                // successfully" contradiction. Whatever wraps this call
                // reads $result['success'] as the pass/fail signal.
                'success' => true,
                'hold_placed' => true,
                'hold_reference' => $holdReference,
                'status' => 'ACTIVE',
                'message' => 'Hold placed successfully',
                'data' => [],
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'hold_placed' => false,
                'message' => 'Hold failed: ' . $e->getMessage()
            ];
        }
    }

    public function debitFunds(array $payload): array
    {
        $holdReference = $payload['hold_reference'] ?? $payload['reference'] ?? null;
        if (!$holdReference) {
            return ['debited' => false, 'message' => 'hold_reference required'];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM absa_holds WHERE hold_reference = ? FOR UPDATE");
            $stmt->execute([$holdReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                $this->db->rollBack();
                return ['debited' => false, 'message' => 'Hold not found'];
            }
            if ($hold['status'] !== 'ACTIVE') {
                $this->db->rollBack();
                return ['debited' => false, 'message' => "Hold status is {$hold['status']}, cannot debit"];
            }

            $this->db->prepare("
                UPDATE absa_accounts
                SET balance = balance - ?, held_balance = held_balance - ?
                WHERE account_number = ?
            ")->execute([$hold['amount'], $hold['amount'], $hold['account_number']]);

            $this->db->prepare("UPDATE absa_holds SET status = 'DEBITED', debited_at = NOW() WHERE hold_reference = ?")
                ->execute([$holdReference]);

            $txnRef = 'ABSADEBIT_' . uniqid();
            $this->db->prepare("
                INSERT INTO absa_transfers (transaction_reference, account_number, amount, currency, direction, status)
                VALUES (?, ?, ?, ?, 'DEBIT', 'COMPLETED')
            ")->execute([$txnRef, $hold['account_number'], $hold['amount'], $hold['currency']]);

            $this->db->commit();

            return [
                'debited' => true,
                'transaction_reference' => $txnRef,
                'message' => 'Debit successful',
                'data' => [],
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['debited' => false, 'message' => 'Debit failed: ' . $e->getMessage()];
        }
    }

    public function releaseHold(array $payload): array
    {
        $holdReference = $payload['hold_reference'] ?? null;
        if (!$holdReference) {
            return ['released' => false, 'message' => 'hold_reference required'];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM absa_holds WHERE hold_reference = ? FOR UPDATE");
            $stmt->execute([$holdReference]);
            $hold = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$hold) {
                $this->db->rollBack();
                return ['released' => false, 'message' => 'Hold not found'];
            }
            if ($hold['status'] !== 'ACTIVE') {
                $this->db->rollBack();
                return ['released' => true, 'message' => "Hold already {$hold['status']}, nothing to release"];
            }

            $this->db->prepare("UPDATE absa_accounts SET held_balance = held_balance - ? WHERE account_number = ?")
                ->execute([$hold['amount'], $hold['account_number']]);

            $this->db->prepare("UPDATE absa_holds SET status = 'RELEASED', released_at = NOW() WHERE hold_reference = ?")
                ->execute([$holdReference]);

            $this->db->commit();

            return ['released' => true, 'message' => 'Hold released successfully'];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['released' => false, 'message' => 'Release failed: ' . $e->getMessage()];
        }
    }

    // ============================================================
    // DESTINATION ROLE — deposit + ATM cashout
    // ============================================================

    public function processDeposit(array $payload): array
    {
        $accountNumber = $payload['destination_identifier'] ?? $payload['account_number'] ?? null;
        $amount = (float)($payload['amount'] ?? 0);
        $currency = $payload['currency'] ?? 'BWP';
        $reference = 'ABSADEP_' . ($payload['reference'] ?? uniqid());

        if (!$accountNumber || $amount <= 0) {
            return ['credited' => false, 'message' => 'destination_identifier and amount required'];
        }

        $stmt = $this->db->prepare("SELECT * FROM absa_transfers WHERE transaction_reference = ?");
        $stmt->execute([$reference]);
        if ($existing = $stmt->fetch(PDO::FETCH_ASSOC)) {
            return ['credited' => true, 'transaction_reference' => $reference, 'message' => 'Duplicate reference — already processed', 'data' => $existing];
        }

        $this->db->beginTransaction();
        try {
            $this->getOrCreateAccount($accountNumber);

            $this->db->prepare("UPDATE absa_accounts SET balance = balance + ? WHERE account_number = ?")
                ->execute([$amount, $accountNumber]);

            $this->db->prepare("
                INSERT INTO absa_transfers (transaction_reference, account_number, amount, currency, direction, status, switch_transaction_reference)
                VALUES (?, ?, ?, ?, 'CREDIT', 'COMPLETED', ?)
            ")->execute([$reference, $accountNumber, $amount, $currency, $payload['reference'] ?? null]);

            $this->db->commit();

            return ['credited' => true, 'transaction_reference' => $reference, 'message' => 'Deposit successful', 'data' => []];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['credited' => false, 'message' => 'Deposit failed: ' . $e->getMessage()];
        }
    }

    public function verifyAccount(array $payload): array
    {
        $accountNumber = $payload['account_identifier'] ?? $payload['destination_identifier'] ?? $payload['account_number'] ?? null;
        if (!$accountNumber) {
            return ['success' => false, 'verified' => false, 'message' => 'account_identifier required'];
        }

        $account = $this->getOrCreateAccount($accountNumber);

        return [
            'success' => true,
            'verified' => $account['status'] === 'ACTIVE',
            'account_name' => $account['account_name'] ?? $accountNumber,
            'account_type' => 'BUSINESS',
            'currency' => $account['currency'],
            'status' => $account['status'],
            'message' => 'Account verified',
            'data' => [],
        ];
    }

    public function generateToken(array $payload): array
    {
        $holdReference = $payload['hold_reference'] ?? null;
        $amount = (float)($payload['amount'] ?? 0);
        $currency = $payload['currency'] ?? 'BWP';

        if (!$holdReference) {
            return ['success' => false, 'message' => 'hold_reference required'];
        }

        $stmt = $this->db->prepare("SELECT * FROM absa_holds WHERE hold_reference = ? AND status = 'ACTIVE'");
        $stmt->execute([$holdReference]);
        $hold = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$hold) {
            return ['success' => false, 'message' => 'No active hold found for this reference'];
        }

        $tokenReference = 'ABSATOKEN_' . uniqid();
        $atmPin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $this->db->prepare("
            INSERT INTO absa_cashout_tokens (token_reference, hold_reference, account_number, amount, currency, atm_pin, status, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', ?)
        ")->execute([$tokenReference, $holdReference, $hold['account_number'], $amount ?: $hold['amount'], $currency, $atmPin, $expiresAt]);

        return [
            'success' => true,
            'cashout_code' => $tokenReference,
            'voucher_number' => $tokenReference,
            'swap_code' => $tokenReference,
            'atm_pin' => $atmPin,
            'expires_at' => $expiresAt,
            'transaction_reference' => $tokenReference,
            'message' => 'ATM code generated',
            'data' => [],
        ];
    }

    public function verifyToken(array $payload): array
    {
        $code = $payload['code'] ?? $payload['swap_code'] ?? null;
        if (!$code) {
            return ['success' => false, 'verified' => false, 'message' => 'code required'];
        }

        $stmt = $this->db->prepare("SELECT * FROM absa_cashout_tokens WHERE token_reference = ? AND status = 'ACTIVE'");
        $stmt->execute([$code]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$token) {
            return ['success' => false, 'verified' => false, 'message' => 'Token not found or already used'];
        }
        if (strtotime($token['expires_at']) < time()) {
            return ['success' => false, 'verified' => false, 'message' => 'Token expired'];
        }

        return [
            'success' => true,
            'verified' => true,
            'amount' => (float)$token['amount'],
            'beneficiary' => $token['account_number'],
            'message' => 'Token verified',
            'data' => [],
        ];
    }

    public function confirmCashout(array $payload): array
    {
        $code = $payload['code'] ?? $payload['swap_code'] ?? null;
        if (!$code) {
            return ['success' => false, 'confirmed' => false, 'message' => 'code required'];
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM absa_cashout_tokens WHERE token_reference = ? AND status = 'ACTIVE' FOR UPDATE");
            $stmt->execute([$code]);
            $token = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$token) {
                $this->db->rollBack();
                return ['success' => false, 'confirmed' => false, 'message' => 'Token not found or already used'];
            }

            $this->db->prepare("UPDATE absa_cashout_tokens SET status = 'CONFIRMED', confirmed_at = NOW() WHERE token_reference = ?")
                ->execute([$code]);

            $this->db->commit();

            return [
                'success' => true,
                'confirmed' => true,
                'transaction_reference' => $code,
                'settlement_triggered' => true,
                'message' => 'Cashout confirmed — cash dispensed',
                'data' => [],
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'confirmed' => false, 'message' => 'Confirmation failed: ' . $e->getMessage()];
        }
    }

    private function getBalanceAction(string $accountNumber): array
    {
        if (!$accountNumber) return ['success' => false, 'message' => 'account_number required'];
        $account = $this->getOrCreateAccount($accountNumber);
        return [
            'success' => true,
            'balance' => (float)$account['balance'] - (float)$account['held_balance'],
            'data' => $account,
        ];
    }

    private function getOrCreateAccount(string $accountNumber): array
    {
        $stmt = $this->db->prepare("SELECT * FROM absa_accounts WHERE account_number = ?");
        $stmt->execute([$accountNumber]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$account) {
            $this->db->prepare("
                INSERT INTO absa_accounts (account_number, account_name, balance, currency)
                VALUES (?, ?, 1000000, 'BWP')
            ")->execute([$accountNumber, 'ABSA Customer ' . $accountNumber]);

            $stmt->execute([$accountNumber]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        return $account;
    }

    // ============================================================
    // SWITCH WEBHOOK
    // ============================================================
    private function handleSwitchWebhook(array $input, string $rawBody, array $headers): array
    {
        if (!$this->verifyIncomingAuth($input, $rawBody, $headers)) {
            http_response_code(401);
            return ['success' => false, 'message' => 'Invalid signature'];
        }

        $required = ['transaction_reference', 'status'];
        foreach ($required as $r) {
            if (empty($input[$r])) return ['success' => false, 'message' => "Missing field: {$r}"];
        }
        if ($input['status'] !== 'COMPLETED') {
            return ['success' => true, 'message' => 'Acknowledged, no account action for non-COMPLETED status'];
        }
        if (empty($input['amount']) || empty($input['currency']) || empty($input['destination_account_number'])) {
            error_log("[ABSA] Webhook verified but missing amount/currency/destination_account_number for {$input['transaction_reference']}");
            return ['success' => true, 'message' => 'Signature verified, payload incomplete for account credit'];
        }

        return $this->processDeposit([
            'reference' => $input['transaction_reference'],
            'destination_identifier' => $input['destination_account_number'],
            'amount' => (float)$input['amount'],
            'currency' => $input['currency'],
        ]);
    }

    public function checkStatus(string $reference): array
    {
        $stmt = $this->db->prepare("SELECT * FROM absa_transfers WHERE transaction_reference = ?");
        $stmt->execute([$reference]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? ['success' => true, 'data' => $result] : ['success' => false, 'message' => 'Reference not found'];
    }
}

// ============================================================
// ROUTING
// ============================================================
try {
    $participant = new AbsaParticipant($pdo);   // $pdo is already set by require_once __DIR__.'/../config/db.php' at the top of this file

    $action = $_GET['action'] ?? '';
    $rawBody = file_get_contents('php://input');
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode($rawBody, true) ?? []) : $_GET;
    $headers = getallheaders() ?: [];

    echo json_encode($participant->handleRequest($action, $input, $rawBody, $headers));
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    error_log("[ABSA] " . $e->getMessage());
}
