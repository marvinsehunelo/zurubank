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

    // FIX: Enhanced decline codes for better diagnostics
    private const DECLINE_REASONS = [
        '4000000000000002' => 'DO_NOT_HONOR',
        '5105105105105100' => 'DO_NOT_HONOR',
    ];

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
            return [
                'success' => false,
                'verified' => false,
                'message' => 'card token/PAN required',
                'data' => [
                    'error_code' => 'MISSING_PAN',
                    'provided_fields' => array_keys($payload),
                ]
            ];
        }

        // Validate PAN format (basic Luhn check)
        if (!$this->isValidPan($pan)) {
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Invalid PAN format',
                'data' => [
                    'error_code' => 'INVALID_PAN_FORMAT',
                    'pan_length' => strlen($pan),
                    'pan_last4' => substr($pan, -4),
                ]
            ];
        }

        $card = $this->lookupOrRegisterCard($pan);

        // FIX: Enhanced response with detailed diagnostic information
        if (!$card['active']) {
            $declineCode = self::DECLINE_REASONS[$pan] ?? 'DO_NOT_HONOR';
            return [
                'success' => false,
                'verified' => false,
                'message' => 'Card declined',
                'data' => [
                    'message' => 'Card declined -- issuer refused authorization',
                    'verified' => false,
                    'decline_code' => $declineCode,
                    'pan_last4' => substr($pan, -4),
                    'card_active' => $card['active'],
                    'card_balance' => $card['balance'],
                    'card_exists' => $card['exists'] ?? true,
                    'test_card' => in_array($pan, self::DECLINED_PANS, true),
                    'error_code' => $declineCode,
                ],
            ];
        }

        return [
            'success' => true,
            'verified' => true,
            'data' => [
                'message' => 'Card active and passed pre-auth check',
                'verified' => true,
                'pan_last4' => substr($pan, -4),
                'card_balance' => $card['balance'],
                'card_active' => $card['active'],
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
            return [
                'success' => false,
                'message' => 'card token/PAN and amount required',
                'data' => [
                    'error_code' => 'MISSING_REQUIRED_FIELDS',
                    'has_pan' => !empty($pan),
                    'has_amount' => $amount > 0,
                    'provided_fields' => array_keys($payload),
                ]
            ];
        }

        // Validate PAN format
        if (!$this->isValidPan($pan)) {
            return [
                'success' => false,
                'message' => 'Invalid PAN format',
                'data' => [
                    'error_code' => 'INVALID_PAN_FORMAT',
                    'pan_length' => strlen($pan),
                    'pan_last4' => substr($pan, -4),
                ]
            ];
        }

        $card = $this->lookupOrRegisterCard($pan);

        if (!$card['active']) {
            $declineCode = self::DECLINE_REASONS[$pan] ?? 'DO_NOT_HONOR';
            return [
                'success' => false,
                'message' => 'Card declined -- issuer refused authorization',
                'data' => [
                    'decline_code' => $declineCode,
                    'pan_last4' => substr($pan, -4),
                    'card_active' => $card['active'],
                    'error_code' => $declineCode,
                ],
            ];
        }

        if ($card['balance'] < $amount) {
            return [
                'success' => false,
                'message' => 'Card declined -- insufficient funds',
                'data' => [
                    'decline_code' => 'INSUFFICIENT_FUNDS',
                    'pan_last4' => substr($pan, -4),
                    'available_balance' => $card['balance'],
                    'requested_amount' => $amount,
                    'error_code' => 'INSUFFICIENT_FUNDS',
                ],
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
                'pan_last4' => substr($pan, -4),
                'amount' => $amount,
                'currency' => $payload['currency'] ?? 'BWP',
            ],
        ];
    }

    // /capture -> CardAcquirerBankClient::debitFunds()
    private function capture(array $payload): array
    {
        $authRef = $payload['authorization_reference'] ?? null;
        $amount = (float)($payload['amount'] ?? 0);

        if (!$authRef) {
            return [
                'success' => false,
                'message' => 'authorization_reference required',
                'data' => [
                    'error_code' => 'MISSING_AUTH_REF',
                    'provided_fields' => array_keys($payload),
                ]
            ];
        }

        $stmt = $this->db->prepare("SELECT * FROM fnbb_mock_authorizations WHERE authorization_reference = ? AND status = 'ACTIVE'");
        $stmt->execute([$authRef]);
        $auth = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$auth) {
            return [
                'success' => false,
                'message' => 'Authorization not found or not active',
                'data' => [
                    'error_code' => 'AUTH_NOT_FOUND',
                    'authorization_reference' => $authRef,
                ]
            ];
        }

        if (strtotime($auth['expires_at']) < time()) {
            $this->db->prepare("UPDATE fnbb_mock_authorizations SET status = 'EXPIRED' WHERE authorization_reference = ?")->execute([$authRef]);
            return [
                'success' => false,
                'message' => 'Authorization expired',
                'data' => [
                    'error_code' => 'AUTH_EXPIRED',
                    'authorization_reference' => $authRef,
                    'expires_at' => $auth['expires_at'],
                ]
            ];
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
            return [
                'success' => false,
                'message' => 'Capture failed: ' . $e->getMessage(),
                'data' => [
                    'error_code' => 'CAPTURE_FAILED',
                    'authorization_reference' => $authRef,
                    'error_details' => $e->getMessage(),
                ]
            ];
        }

        return [
            'success' => true,
            'message' => 'Capture successful',
            'data' => [
                'transaction_reference' => $txRef,
                'status' => 'COMPLETED',
                'authorization_reference' => $authRef,
                'amount' => $captureAmount,
                'currency' => $payload['currency'] ?? 'BWP',
            ],
        ];
    }

    // /void -> CardAcquirerBankClient::releaseHold()
    private function void(array $payload): array
    {
        $authRef = $payload['authorization_reference'] ?? null;
        if (!$authRef) {
            return [
                'success' => false,
                'message' => 'authorization_reference required',
                'data' => [
                    'error_code' => 'MISSING_AUTH_REF',
                    'provided_fields' => array_keys($payload),
                ]
            ];
        }

        $stmt = $this->db->prepare("SELECT * FROM fnbb_mock_authorizations WHERE authorization_reference = ?");
        $stmt->execute([$authRef]);
        $auth = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$auth) {
            return [
                'success' => false,
                'message' => 'Authorization not found',
                'data' => [
                    'error_code' => 'AUTH_NOT_FOUND',
                    'authorization_reference' => $authRef,
                ]
            ];
        }

        if ($auth['status'] !== 'ACTIVE') {
            return [
                'success' => true,
                'message' => "Authorization already {$auth['status']}, nothing to void",
                'data' => [
                    'status' => $auth['status'],
                    'authorization_reference' => $authRef,
                ]
            ];
        }

        $this->db->prepare("UPDATE fnbb_mock_authorizations SET status = 'VOIDED' WHERE authorization_reference = ?")->execute([$authRef]);

        $stmt = $this->db->prepare("SELECT pan FROM fnbb_mock_cards WHERE pan_last4 = ? LIMIT 1");
        $stmt->execute([$auth['pan_last4']]);
        if ($card = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->db->prepare("UPDATE fnbb_mock_cards SET held_balance = held_balance - ? WHERE pan = ?")
                ->execute([$auth['amount'], $card['pan']]);
        }

        return [
            'success' => true,
            'message' => 'Authorization voided',
            'data' => [
                'status' => 'VOIDED',
                'authorization_reference' => $authRef,
                'pan_last4' => $auth['pan_last4'],
                'amount' => (float)$auth['amount'],
            ]
        ];
    }

    // card_load -> CardAcquirerBankClient::processDepositWithProof()
    private function cardLoad(array $payload): array
    {
        $pan = $payload['destination_card_token'] ?? null;
        $amount = (float)($payload['amount'] ?? 0);

        if (!$pan || $amount <= 0) {
            return [
                'success' => false,
                'message' => 'destination_card_token and amount required',
                'data' => [
                    'error_code' => 'MISSING_REQUIRED_FIELDS',
                    'has_pan' => !empty($pan),
                    'has_amount' => $amount > 0,
                    'provided_fields' => array_keys($payload),
                ]
            ];
        }

        // Validate PAN format
        if (!$this->isValidPan($pan)) {
            return [
                'success' => false,
                'message' => 'Invalid PAN format',
                'data' => [
                    'error_code' => 'INVALID_PAN_FORMAT',
                    'pan_length' => strlen($pan),
                    'pan_last4' => substr($pan, -4),
                ]
            ];
        }

        $card = $this->lookupOrRegisterCard($pan);

        if (!$card['active']) {
            return [
                'success' => false,
                'message' => 'Card is not active',
                'data' => [
                    'error_code' => 'CARD_INACTIVE',
                    'pan_last4' => substr($pan, -4),
                    'card_active' => $card['active'],
                ]
            ];
        }

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
            return [
                'success' => false,
                'message' => 'Card load failed: ' . $e->getMessage(),
                'data' => [
                    'error_code' => 'LOAD_FAILED',
                    'pan_last4' => substr($pan, -4),
                    'error_details' => $e->getMessage(),
                ]
            ];
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
                'pan_last4' => substr($pan, -4),
                'amount' => $amount,
                'currency' => $payload['currency'] ?? 'BWP',
            ],
        ];
    }

    private function extractPan(array $payload): ?string
    {
        return $payload['card_token'] ?? $payload['source_identifier'] ?? null;
    }

    /**
     * FIX: Added basic Luhn algorithm validation for PAN
     * This helps distinguish between "invalid card number" and "declined card"
     */
    private function isValidPan(string $pan): bool
    {
        // Basic format: 16-19 digits
        if (!preg_match('/^\d{16,19}$/', $pan)) {
            return false;
        }

        // Luhn algorithm check
        $sum = 0;
        $alt = false;
        for ($i = strlen($pan) - 1; $i >= 0; $i--) {
            $n = (int)$pan[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        return ($sum % 10) === 0;
    }

    private function lookupOrRegisterCard(string $pan): array
    {
        $stmt = $this->db->prepare("SELECT * FROM fnbb_mock_cards WHERE pan = ?");
        $stmt->execute([$pan]);
        $card = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$card) {
            $active = !in_array($pan, self::DECLINED_PANS, true);
            $balance = $active ? self::DEFAULT_TEST_LIMIT : 0;

            // FIX: Validate PAN before registering
            $isValidPan = $this->isValidPan($pan);
            if (!$isValidPan) {
                // Don't register invalid PANs
                return [
                    'pan' => $pan,
                    'active' => false,
                    'balance' => 0,
                    'exists' => false,
                    'valid_format' => false,
                ];
            }

            $this->db->prepare("
                INSERT INTO fnbb_mock_cards (pan, pan_last4, active, balance, held_balance)
                VALUES (?, ?, ?, ?, 0)
            ")->execute([$pan, substr($pan, -4), $active ? 1 : 0, $balance]);

            return [
                'pan' => $pan,
                'active' => $active,
                'balance' => $balance,
                'exists' => false,
                'valid_format' => true,
            ];
        }

        return [
            'pan' => $card['pan'],
            'active' => (bool)$card['active'],
            'balance' => (float)$card['balance'] - (float)$card['held_balance'],
            'exists' => true,
            'valid_format' => true,
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
