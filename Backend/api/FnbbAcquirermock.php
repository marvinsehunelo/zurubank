<?php
declare(strict_types=1);

/**
 * FNBB Card Acquirer -- MOCK shared logic class.
 * Deployed on Zurubank's infrastructure as a stand-in for FNBB's real
 * acquiring sandbox while access is pending.
 *
 * Field shapes are taken directly from
 * src/Infrastructure/Banks/CardAcquirerBankClient.php -- the real
 * client code that calls this. Endpoint PATHS (preauth.php,
 * authorize.php, capture.php, void.php) intentionally mirror real
 * card-acquirer API conventions (Visa/Mastercard-style: preauth ->
 * authorize -> capture -> void) rather than a single file with
 * ?action= query params, so that swapping to FNBB's real endpoint
 * later is a small, predictable diff:
 *   1. base_url -> https://api.fnbbotswana.co.bw/acquiring/v1
 *   2. drop the .php suffix from each endpoint path
 *   3. auth.type / secret_source -> whatever FNBB's real API requires
 * The .php suffix exists only because this mock's host may be a PHP
 * dev server (php -S), which -- confirmed with CAZACOM earlier this
 * session -- cannot do .htaccess-based extension-less rewriting. If
 * ZURUBANK's real host supports clean routing, drop the suffix --
 * no other change needed.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/crypto.php';

class FnbbAcquirerMock
{
    private PDO $db;

    private const DECLINED_PANS = [
        '4000000000000002',
        '5105105105105100',
    ];

    private const DEFAULT_TEST_LIMIT = 100000.00;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureTables();
    }

    public function run(array $input, string $rawBody, array $headers, string $action): void
    {
        if (!$this->verifyIncomingAuth($input, $rawBody, $headers)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication failed']);
            return;
        }

        $result = match ($action) {
            'preauth' => $this->preAuthCheck($input),
            'authorize' => $this->authorize($input),
            'capture' => $this->capture($input),
            'void' => $this->void($input),
            'card_load' => $this->cardLoad($input),
            default => ['success' => false, 'message' => "Unknown action: {$action}"],
        };

        echo json_encode($result);
    }

    private function verifyIncomingAuth(array $input, string $rawBody, array $headers): bool
    {
        if (isset($input['certificate'], $input['signature'])) {
            $verification = verify_requester_signature($input, $this->db);
            if ($verification['valid'] ?? false) {
                error_log("[FNBB_MOCK] Authenticated via CERTIFICATE (requester: " . ($verification['requester'] ?? 'unknown') . ")");
                return true;
            }
            error_log("[FNBB_MOCK] Certificate signature INVALID: " . ($verification['message'] ?? 'unknown reason'));
        }
        return false;
    }

    // /preauth -> CardAcquirerBankClient::verifyAssetSigned()
    private function preAuthCheck(array $payload): array
    {
        $pan = $this->extractPan($payload);
        if (!$pan) {
            return ['success' => false, 'verified' => false, 'message' => 'card token/PAN required', 'data' => []];
        }

        $card = $this->lookupOrRegisterCard($pan);

        return [
            'success' => $card['active'],
            'verified' => $card['active'],
            'data' => [
                'message' => $card['active'] ? 'Card active and passed pre-auth check' : 'Card declined',
                'verified' => $card['active'],
            ],
        ];
    }

    // /authorize -> CardAcquirerBankClient::placeHold()
    private function authorize(array $payload): array
    {
        $pan = $this->extractPan($payload);
        $amount = (float)($payload['amount'] ?? 0);
        $reference = $payload['reference'] ?? ('AUTH_' . uniqid());

        if (!$pan || $amount <= 0) {
            return ['success' => false, 'message' => 'card token/PAN and amount required', 'data' => []];
        }

        $card = $this->lookupOrRegisterCard($pan);

        if (!$card['active']) {
            return [
                'success' => false,
                'message' => 'Card declined -- issuer refused authorization',
                'data' => ['decline_code' => 'DO_NOT_HONOR'],
            ];
        }

        if ($card['balance'] < $amount) {
            return [
                'success' => false,
                'message' => 'Card declined -- insufficient funds',
                'data' => ['decline_code' => 'INSUFFICIENT_FUNDS'],
            ];
        }

        $authRef = 'FNBBAUTH_' . $reference;
        $authCode = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $expiresAt = $payload['expiry'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));

        $stmt = $this->db->prepare("
            INSERT INTO fnbb_mock_authorizations
            (authorization_reference, authorization_code, pan_last4, amount, currency, status, expires_at)
            VALUES (?, ?, ?, ?, ?, 'ACTIVE', ?)
        ");
        $stmt->execute([$authRef, $authCode, substr($pan, -4), $amount, $payload['currency'] ?? 'BWP', $expiresAt]);

        $this->db->prepare("UPDATE fnbb_mock_cards SET held_balance = held_balance + ? WHERE pan = ?")
            ->execute([$amount, $pan]);

        return [
            'success' => true,
            'message' => 'Authorized',
            'data' => [
                'authorization_reference' => $authRef,
                'authorization_code' => $authCode,
                'status' => 'ACTIVE',
                'expires_at' => $expiresAt,
            ],
        ];
    }

    // /capture -> CardAcquirerBankClient::debitFunds()
    private function capture(array $payload): array
    {
        $authRef = $payload['authorization_reference'] ?? null;
        $amount = (float)($payload['amount'] ?? 0);

        if (!$authRef) {
            return ['success' => false, 'message' => 'authorization_reference required', 'data' => []];
        }

        $stmt = $this->db->prepare("SELECT * FROM fnbb_mock_authorizations WHERE authorization_reference = ? AND status = 'ACTIVE'");
        $stmt->execute([$authRef]);
        $auth = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$auth) {
            return ['success' => false, 'message' => 'Authorization not found or not active', 'data' => []];
        }

        if (strtotime($auth['expires_at']) < time()) {
            $this->db->prepare("UPDATE fnbb_mock_authorizations SET status = 'EXPIRED' WHERE authorization_reference = ?")->execute([$authRef]);
            return ['success' => false, 'message' => 'Authorization expired', 'data' => []];
        }

        $captureAmount = $amount > 0 ? $amount : (float)$auth['amount'];
        $txRef = 'FNBBCAP_' . uniqid();

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE fnbb_mock_authorizations SET status = 'CAPTURED', captured_at = NOW() WHERE authorization_reference = ?")
                ->execute([$authRef]);

            $this->db->prepare("
                INSERT INTO fnbb_mock_captures (transaction_reference, authorization_reference, amount, currency, status)
                VALUES (?, ?, ?, ?, 'COMPLETED')
            ")->execute([$txRef, $authRef, $captureAmount, $payload['currency'] ?? 'BWP']);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Capture failed: ' . $e->getMessage(), 'data' => []];
        }

        return [
            'success' => true,
            'message' => 'Capture successful',
            'data' => [
                'transaction_reference' => $txRef,
                'status' => 'COMPLETED',
            ],
        ];
    }

    // /void -> CardAcquirerBankClient::releaseHold()
    private function void(array $payload): array
    {
        $authRef = $payload['authorization_reference'] ?? null;
        if (!$authRef) {
            return ['success' => false, 'message' => 'authorization_reference required', 'data' => []];
        }

        $stmt = $this->db->prepare("SELECT * FROM fnbb_mock_authorizations WHERE authorization_reference = ?");
        $stmt->execute([$authRef]);
        $auth = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$auth) {
            return ['success' => false, 'message' => 'Authorization not found', 'data' => []];
        }

        if ($auth['status'] !== 'ACTIVE') {
            return ['success' => true, 'message' => "Authorization already {$auth['status']}, nothing to void", 'data' => ['status' => $auth['status']]];
        }

        $this->db->prepare("UPDATE fnbb_mock_authorizations SET status = 'VOIDED' WHERE authorization_reference = ?")->execute([$authRef]);

        $stmt = $this->db->prepare("SELECT pan FROM fnbb_mock_cards WHERE pan_last4 = ? LIMIT 1");
        $stmt->execute([$auth['pan_last4']]);
        if ($card = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->db->prepare("UPDATE fnbb_mock_cards SET held_balance = held_balance - ? WHERE pan = ?")
                ->execute([$auth['amount'], $card['pan']]);
        }

        return ['success' => true, 'message' => 'Authorization voided', 'data' => ['status' => 'VOIDED']];
    }

    // card_load -> CardAcquirerBankClient::processDepositWithProof()
    private function cardLoad(array $payload): array
    {
        $pan = $payload['destination_card_token'] ?? null;
        $amount = (float)($payload['amount'] ?? 0);

        if (!$pan || $amount <= 0) {
            return ['success' => false, 'message' => 'destination_card_token and amount required', 'data' => []];
        }

        $card = $this->lookupOrRegisterCard($pan);
        $txRef = 'FNBBLOAD_' . uniqid();

        $this->db->beginTransaction();
        try {
            $this->db->prepare("UPDATE fnbb_mock_cards SET balance = balance + ? WHERE pan = ?")
                ->execute([$amount, $pan]);

            $this->db->prepare("
                INSERT INTO fnbb_mock_loads (transaction_reference, pan_last4, amount, currency, status)
                VALUES (?, ?, ?, ?, 'COMPLETED')
            ")->execute([$txRef, substr($pan, -4), $amount, $payload['currency'] ?? 'BWP']);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Card load failed: ' . $e->getMessage(), 'data' => []];
        }

        $stmt = $this->db->prepare("SELECT balance FROM fnbb_mock_cards WHERE pan = ?");
        $stmt->execute([$pan]);
        $newBalance = (float)($stmt->fetchColumn() ?: 0);

        return [
            'success' => true,
            'message' => 'Card loaded successfully',
            'data' => [
                'transaction_reference' => $txRef,
                'status' => 'COMPLETED',
                'new_balance' => $newBalance,
            ],
        ];
    }

    private function extractPan(array $payload): ?string
    {
        return $payload['card_token'] ?? $payload['source_identifier'] ?? null;
    }

    private function lookupOrRegisterCard(string $pan): array
    {
        $stmt = $this->db->prepare("SELECT * FROM fnbb_mock_cards WHERE pan = ?");
        $stmt->execute([$pan]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            $active = !in_array($pan, self::DECLINED_PANS, true);
            $balance = $active ? self::DEFAULT_TEST_LIMIT : 0;

            $this->db->prepare("
                INSERT INTO fnbb_mock_cards (pan, pan_last4, active, balance, held_balance)
                VALUES (?, ?, ?, ?, 0)
            ")->execute([$pan, substr($pan, -4), $active ? 1 : 0, $balance]);

            return ['pan' => $pan, 'active' => $active, 'balance' => $balance];
        }

        return [
            'pan' => $card['pan'],
            'active' => (bool)$card['active'],
            'balance' => (float)$card['balance'] - (float)$card['held_balance'],
        ];
    }

    private function ensureTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fnbb_mock_cards (
                pan VARCHAR(32) PRIMARY KEY,
                pan_last4 VARCHAR(4) NOT NULL,
                active BOOLEAN NOT NULL DEFAULT TRUE,
                balance NUMERIC(15,2) NOT NULL DEFAULT 0,
                held_balance NUMERIC(15,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fnbb_mock_authorizations (
                authorization_reference VARCHAR(64) PRIMARY KEY,
                authorization_code VARCHAR(16),
                pan_last4 VARCHAR(4) NOT NULL,
                amount NUMERIC(15,2) NOT NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'BWP',
                status VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
                expires_at TIMESTAMP,
                captured_at TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fnbb_mock_captures (
                transaction_reference VARCHAR(64) PRIMARY KEY,
                authorization_reference VARCHAR(64) NOT NULL,
                amount NUMERIC(15,2) NOT NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'BWP',
                status VARCHAR(16) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS fnbb_mock_loads (
                transaction_reference VARCHAR(64) PRIMARY KEY,
                pan_last4 VARCHAR(4) NOT NULL,
                amount NUMERIC(15,2) NOT NULL,
                currency VARCHAR(8) NOT NULL DEFAULT 'BWP',
                status VARCHAR(16) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
    }
}
