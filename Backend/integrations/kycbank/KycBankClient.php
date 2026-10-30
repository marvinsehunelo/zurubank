<?php
// Backend/integrations/kycbank/KycBankClient.php
//
// Client for KYC Bank, the shared KYC service. ZuruBank asks for a
// customer's KYC by mobile number or Omang; the customer approves in the
// KYC Bank app; KYC Bank then calls Backend/integrations/kycbank/webhook.php
// and ZuruBank fetches the verified profile.
//
// Configuration comes only from the environment (never commit values):
//   KYCBANK_API_URL        e.g. the staging API base URL
//   KYCBANK_CLIENT_ID      OAuth client id issued to ZuruBank
//   KYCBANK_CLIENT_SECRET  OAuth client secret issued to ZuruBank
//   KYCBANK_WEBHOOK_SECRET signing secret for incoming webhooks
//
// Errors from KYC Bank come back as KycBankException with KYC Bank's own
// { code, message, requestId }. Those carry no personal data, so staff may
// see them. Nothing here logs request or response bodies.

class KycBankException extends RuntimeException
{
    /** KYC Bank error code (validation_failed, duplicate_request, ...) or a local one (network_error, bad_response, not_configured). */
    public string $errorCode;
    public int $httpStatus;
    public ?string $requestId;

    public function __construct(string $errorCode, string $message, int $httpStatus = 0, ?string $requestId = null)
    {
        parent::__construct($message, $httpStatus);
        $this->errorCode  = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->requestId  = $requestId;
    }

    /** True when trying again later may succeed (network trouble, 5xx, rate limit). */
    public function isTransient(): bool
    {
        return $this->httpStatus === 0 || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}

class KycBankClient
{
    const CONNECT_TIMEOUT    = 5;   // seconds
    const TOTAL_TIMEOUT      = 15;  // seconds
    const TOKEN_EARLY_EXPIRY = 60;  // refresh this many seconds before the token expires
    const SIGNATURE_TOLERANCE = 300; // webhook timestamps more than 5 minutes off are rejected

    private string $apiUrl;
    private string $clientId;
    private string $clientSecret;

    public function __construct(string $apiUrl, string $clientId, string $clientSecret)
    {
        $this->apiUrl       = rtrim($apiUrl, '/');
        $this->clientId     = $clientId;
        $this->clientSecret = $clientSecret;
    }

    private static function env(string $name): string
    {
        $v = getenv($name);
        if ($v === false || $v === '') {
            $v = $_ENV[$name] ?? $_SERVER[$name] ?? '';
        }
        return trim((string)$v);
    }

    /** Names of the API variables that are not set. Empty when the client can be built. */
    public static function missingConfig(): array
    {
        $missing = [];
        foreach (['KYCBANK_API_URL', 'KYCBANK_CLIENT_ID', 'KYCBANK_CLIENT_SECRET'] as $name) {
            if (self::env($name) === '') {
                $missing[] = $name;
            }
        }
        return $missing;
    }

    public static function isConfigured(): bool
    {
        return self::missingConfig() === [];
    }

    /** A client built from the environment, or null when KYC Bank is not configured. */
    public static function fromEnv(): ?self
    {
        if (!self::isConfigured()) {
            return null;
        }
        return new self(self::env('KYCBANK_API_URL'), self::env('KYCBANK_CLIENT_ID'), self::env('KYCBANK_CLIENT_SECRET'));
    }

    public static function webhookSecret(): string
    {
        return self::env('KYCBANK_WEBHOOK_SECRET');
    }

    // ------------------------------------------------------------------
    // Access token (client credentials), cached until shortly before expiry
    // ------------------------------------------------------------------

    private function cacheKey(): string
    {
        return 'zurubank_kycbank_token_' . hash('sha256', $this->apiUrl . '|' . $this->clientId);
    }

    private function cacheFile(): string
    {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $this->cacheKey() . '.json';
    }

    private function readCachedToken(): ?string
    {
        if (function_exists('apcu_fetch') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            $hit = apcu_fetch($this->cacheKey(), $ok);
            if ($ok && is_array($hit) && ($hit['expires_at'] ?? 0) > time()) {
                return (string)$hit['access_token'];
            }
            return null;
        }
        $file = $this->cacheFile();
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (is_array($data) && !empty($data['access_token']) && ($data['expires_at'] ?? 0) > time()) {
            return (string)$data['access_token'];
        }
        return null;
    }

    private function storeToken(string $token, int $expiresAt): void
    {
        $entry = ['access_token' => $token, 'expires_at' => $expiresAt];
        if (function_exists('apcu_store') && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
            apcu_store($this->cacheKey(), $entry, max(1, $expiresAt - time()));
            return;
        }
        $file = $this->cacheFile();
        $tmp  = @tempnam(dirname($file), 'kyctok');
        if ($tmp === false) {
            return; // no cache: every request asks for a token, which still works
        }
        @chmod($tmp, 0600);
        if (@file_put_contents($tmp, json_encode($entry)) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            return;
        }
        @chmod($file, 0600);
    }

    public function forgetToken(): void
    {
        if (function_exists('apcu_delete')) {
            @apcu_delete($this->cacheKey());
        }
        @unlink($this->cacheFile());
    }

    /** A valid access token, from the cache or freshly issued. */
    public function token(): string
    {
        $cached = $this->readCachedToken();
        if ($cached !== null) {
            return $cached;
        }
        [$status, $body] = $this->http('POST', '/oauth/token', [
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        if ($status < 200 || $status >= 300) {
            throw $this->errorFrom($status, $body);
        }
        if (empty($body['access_token']) || !is_string($body['access_token'])) {
            throw new KycBankException('bad_response', 'KYC Bank returned no access token.', $status);
        }
        $ttl = (int)($body['expires_in'] ?? 900);
        $this->storeToken($body['access_token'], time() + max(0, $ttl - self::TOKEN_EARLY_EXPIRY));
        return $body['access_token'];
    }

    // ------------------------------------------------------------------
    // API calls
    // ------------------------------------------------------------------

    /**
     * Asks KYC Bank for a customer's KYC. Reuse the same $idempotencyKey when
     * retrying a request: KYC Bank then returns the first result.
     *
     * @param string $identifierKind 'mobile' (+26770123456 form) or 'omang' (9 digits)
     * @param string $level          'basic' or 'standard'
     * @return array the consent
     */
    public function createConsentRequest(
        string $identifierKind,
        string $value,
        string $level,
        string $purpose,
        string $idempotencyKey,
        int $durationDays = 365,
        bool $updates = true,
        ?array $fields = null
    ): array {
        $body = [
            'identifier'   => ['kind' => $identifierKind, 'value' => $value],
            'level'        => $level,
            'purpose'      => $purpose,
            'durationDays' => $durationDays,
            'updates'      => $updates,
        ];
        if ($fields !== null) {
            $body['fields'] = array_values($fields);
        }
        return $this->authed('POST', '/v1/consent-requests', $body, ['Idempotency-Key: ' . $idempotencyKey]);
    }

    /** The consent, with its current state. */
    public function getConsentRequest(string $consentId): array
    {
        return $this->authed('GET', '/v1/consent-requests/' . rawurlencode($consentId));
    }

    /** The verified profile shared under an active consent. 403 consent_not_active otherwise. */
    public function getProfile(string $consentId): array
    {
        return $this->authed('GET', '/v1/consents/' . rawurlencode($consentId) . '/profile');
    }

    /** A call with the bearer token; on 401 the cached token is dropped and the call is tried once more. */
    private function authed(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        for ($attempt = 0; ; $attempt++) {
            $token = $this->token();
            [$status, $body] = $this->http($method, $path, $json, array_merge($headers, ['Authorization: Bearer ' . $token]));
            if ($status === 401 && $attempt === 0) {
                $this->forgetToken();
                continue;
            }
            if ($status < 200 || $status >= 300) {
                throw $this->errorFrom($status, $body);
            }
            if (!is_array($body)) {
                throw new KycBankException('bad_response', 'KYC Bank returned an unreadable response.', $status);
            }
            return $body;
        }
    }

    /** @return array{0:int,1:mixed} HTTP status and decoded JSON body (null if not JSON). */
    private function http(string $method, string $path, ?array $json = null, array $headers = []): array
    {
        $ch = curl_init($this->apiUrl . $path);
        $headers[] = 'Accept: application/json';
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_SLASHES);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $raw    = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            // The curl message names only the host, never anything we sent.
            throw new KycBankException('network_error', 'Could not reach KYC Bank (curl error ' . $errno . ').', 0);
        }
        return [$status, json_decode((string)$raw, true)];
    }

    private function errorFrom(int $status, $body): KycBankException
    {
        $code      = is_array($body) && isset($body['code']) ? (string)$body['code'] : 'http_' . $status;
        $message   = is_array($body) && isset($body['message']) ? (string)$body['message'] : 'KYC Bank answered HTTP ' . $status . '.';
        $requestId = is_array($body) && isset($body['requestId']) ? (string)$body['requestId'] : null;
        return new KycBankException($code, $message, $status, $requestId);
    }

    // ------------------------------------------------------------------
    // Webhook signatures
    // ------------------------------------------------------------------

    /**
     * Checks an x-kyc-signature header ("t=<unix seconds>,v1=<hex>") against
     * the raw request body. v1 is hex HMAC-SHA256 of "<t>.<raw body>". The
     * timestamp must be within 5 minutes of $now, in either direction.
     */
    public static function verifyWebhookSignature(string $rawBody, ?string $header, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || $header === null || trim($header) === '') {
            return false;
        }
        $now = $now ?? time();
        $t = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $t = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = strtolower($kv[1]);
            }
        }
        if ($t === null || !ctype_digit($t) || $signatures === []) {
            return false;
        }
        if (abs($now - (int)$t) > self::SIGNATURE_TOLERANCE) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }
}
