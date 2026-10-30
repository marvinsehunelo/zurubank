<?php
// Backend/integrations/kycbank/kycbank.php
//
// ZuruBank's side of KYC Bank: the stored requests, the state machine the
// webhook and the admin dashboard share, and small helpers for the UI.
//
//   kycbank_requests        one row per request ZuruBank made. Holds the
//                           consent's state and, once fetched, the profile
//                           (ZuruBank's own KYC record). The phone/Omang used
//                           to ask is never stored, only its kind.
//   kycbank_webhook_events  ids of webhook events already processed, so a
//                           redelivery is acknowledged without re-processing.
//
// Tables are created on first use; the same SQL is in
// database/migrations/2026_10_30_kycbank.sql.
//
// Logs written here carry ids only (request id, consent id, user id, event
// id). Never log a profile, a phone number or an Omang number.

require_once __DIR__ . '/KycBankClient.php';

const KB_DEFAULT_PURPOSE = 'Open and verify your Zuru Bank account';
const KB_DURATION_DAYS   = 365;
const KB_OPEN_STATES     = ['creating', 'requested', 'active', 'suspended'];

function kb_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kycbank_requests (
            id                       BIGSERIAL PRIMARY KEY,
            user_id                  INTEGER NOT NULL REFERENCES users(user_id),
            consent_id               VARCHAR(64) UNIQUE,
            idempotency_key          VARCHAR(64) NOT NULL UNIQUE,
            identifier_kind          VARCHAR(10) NOT NULL CHECK (identifier_kind IN ('mobile', 'omang')),
            purpose                  VARCHAR(200) NOT NULL,
            level                    VARCHAR(10) NOT NULL CHECK (level IN ('basic', 'standard')),
            state                    VARCHAR(20) NOT NULL DEFAULT 'creating',
            suspended_reason         VARCHAR(40),
            requested_at             TIMESTAMPTZ,
            decided_at               TIMESTAMPTZ,
            starts_at                TIMESTAMPTZ,
            ends_at                  TIMESTAMPTZ,
            lapses_at                TIMESTAMPTZ,
            latest_profile_version   INTEGER,
            profile_version          INTEGER,
            profile_json             JSONB,
            profile_fetched_at       TIMESTAMPTZ,
            needs_review             BOOLEAN NOT NULL DEFAULT FALSE,
            review_reason            VARCHAR(20),
            reviewed_by              INTEGER,
            reviewed_at              TIMESTAMPTZ,
            review_decision          VARCHAR(10) CHECK (review_decision IN ('approved', 'rejected')),
            reviewed_profile_version INTEGER,
            requested_by             INTEGER,
            last_error_code          VARCHAR(64),
            created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            withdrawn_at             TIMESTAMPTZ
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_kycbank_requests_user ON kycbank_requests (user_id, created_at DESC)");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS kycbank_webhook_events (
            event_id     VARCHAR(80) PRIMARY KEY,
            event        VARCHAR(40) NOT NULL,
            consent_id   VARCHAR(64),
            received_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            processed_at TIMESTAMPTZ
        )
    ");
    // users.kyc_status ('pending' | 'approved' | 'rejected'), as at SaccusSalis.
    // Checked first so the users table is not locked on every request.
    $has = $pdo->query("
        SELECT 1 FROM information_schema.columns
         WHERE table_schema = current_schema() AND table_name = 'users' AND column_name = 'kyc_status'
    ")->fetchColumn();
    if (!$has) {
        $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS kyc_status VARCHAR(32) DEFAULT 'pending'");
    }
    $ready = true;
}

// ----------------------------------------------------------------------
// Input helpers
// ----------------------------------------------------------------------

/** A mobile number in international form (+26771234567), or null. Accepts local Botswana numbers (71234567). */
function kb_normalize_mobile(?string $raw): ?string
{
    $n = preg_replace('/[\s().\-]/', '', (string)$raw);
    if ($n === null || $n === '') {
        return null;
    }
    if (strncmp($n, '00', 2) === 0) {
        $n = '+' . substr($n, 2);
    } elseif (preg_match('/^7\d{7}$/', $n)) {
        $n = '+267' . $n;
    } elseif (preg_match('/^267\d{8}$/', $n)) {
        $n = '+' . $n;
    }
    return preg_match('/^\+[1-9]\d{6,14}$/', $n) ? $n : null;
}

/** A 9-digit Omang number, or null. */
function kb_normalize_omang(?string $raw): ?string
{
    $n = preg_replace('/\s+/', '', (string)$raw);
    return preg_match('/^\d{9}$/', (string)$n) ? $n : null;
}

function kb_strlen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function kb_state_label(?string $state): string
{
    $labels = [
        'creating'  => 'Not yet confirmed by KYC Bank',
        'failed'    => 'Not sent (KYC Bank refused the request)',
        'requested' => 'Waiting for the customer to approve',
        'active'    => 'Active: customer approved sharing',
        'rejected'  => 'Declined by the customer',
        'lapsed'    => 'Lapsed: the customer did not answer in time',
        'withdrawn' => 'Withdrawn by the customer',
        'expired'   => 'Expired',
        'suspended' => 'Suspended',
    ];
    return $labels[$state ?? ''] ?? ucfirst((string)$state);
}

function kb_suspended_reason_label(?string $reason): string
{
    $labels = [
        'profile_not_verified' => 'The customer has not finished verifying their profile with KYC Bank yet. Sharing starts automatically when they do.',
        'profile_stale'        => 'The customer\'s KYC Bank profile is out of date and must be re-verified.',
        'profile_on_hold'      => 'KYC Bank has put the customer\'s profile on hold.',
    ];
    return $labels[$reason ?? ''] ?? ($reason ? 'Reason: ' . $reason : '');
}

// ----------------------------------------------------------------------
// Rows
// ----------------------------------------------------------------------

function kb_request(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM kycbank_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function kb_request_by_consent(PDO $pdo, string $consentId): ?array
{
    $st = $pdo->prepare('SELECT * FROM kycbank_requests WHERE consent_id = ?');
    $st->execute([$consentId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** @return array<int, array> the customer's requests, newest first */
function kb_requests_for_user(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare('SELECT * FROM kycbank_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC');
    $st->execute([$userId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function kb_set_error(PDO $pdo, int $requestId, ?string $code): void
{
    $pdo->prepare('UPDATE kycbank_requests SET last_error_code = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$code, $requestId]);
}

/** Best-effort entry in audit_logs. Ids only. Never breaks the caller. */
function kb_audit(PDO $pdo, string $action, int $requestId, int $performedBy, array $ids = []): void
{
    // Inside a transaction a failed insert would abort it, so guard with a savepoint.
    $inTx = $pdo->inTransaction();
    try {
        if ($inTx) {
            $pdo->exec('SAVEPOINT kb_audit');
        }
        $pdo->prepare("
            INSERT INTO audit_logs (entity, entity_id, action, category, severity, new_value, performed_by, ip_address)
            VALUES ('kycbank_request', ?, ?, 'kyc', 'info', ?, ?, ?)
        ")->execute([$requestId, $action, json_encode($ids), $performedBy, substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 50)]);
        if ($inTx) {
            $pdo->exec('RELEASE SAVEPOINT kb_audit');
        }
    } catch (Throwable $e) {
        if ($inTx) {
            try { $pdo->exec('ROLLBACK TO SAVEPOINT kb_audit'); } catch (Throwable $ignored) {}
        }
        error_log('[KYCBank] audit write skipped for request ' . $requestId . ': ' . get_class($e));
    }
}

// ----------------------------------------------------------------------
// Applying what KYC Bank says
// ----------------------------------------------------------------------

function kb_ts($v): ?string
{
    return is_string($v) && $v !== '' ? $v : null;
}

/** Copies the consent's state and dates onto the row. A withdrawn row never changes again. */
function kb_apply_consent(PDO $pdo, int $requestId, array $consent): void
{
    $state = (string)($consent['state'] ?? '');
    if ($state === '') {
        throw new KycBankException('bad_response', 'KYC Bank returned a consent without a state.', 200);
    }
    $pdo->prepare("
        UPDATE kycbank_requests
           SET consent_id             = COALESCE(consent_id, ?),
               state                  = ?,
               suspended_reason       = ?,
               requested_at           = COALESCE(?::timestamptz, requested_at),
               decided_at             = COALESCE(?::timestamptz, decided_at),
               starts_at              = COALESCE(?::timestamptz, starts_at),
               ends_at                = COALESCE(?::timestamptz, ends_at),
               lapses_at              = COALESCE(?::timestamptz, lapses_at),
               latest_profile_version = COALESCE(?, latest_profile_version),
               withdrawn_at           = CASE WHEN ? = 'withdrawn' THEN COALESCE(withdrawn_at, NOW()) ELSE withdrawn_at END,
               last_error_code        = NULL,
               updated_at             = NOW()
         WHERE id = ? AND state <> 'withdrawn'
    ")->execute([
        isset($consent['id']) ? (string)$consent['id'] : null,
        $state,
        $state === 'suspended' ? (isset($consent['suspendedReason']) ? (string)$consent['suspendedReason'] : null) : null,
        kb_ts($consent['requestedAt'] ?? null),
        kb_ts($consent['decidedAt'] ?? null),
        kb_ts($consent['startsAt'] ?? null),
        kb_ts($consent['endsAt'] ?? null),
        kb_ts($consent['lapsesAt'] ?? null),
        isset($consent['latestProfileVersion']) ? (int)$consent['latestProfileVersion'] : null,
        $state,
        $requestId,
    ]);
}

/**
 * Stores a fetched profile. The first profile, or a newer version of one,
 * is flagged for staff review; the customer's kyc_status is never changed here.
 */
function kb_store_profile(PDO $pdo, int $requestId, array $profile): void
{
    $version = isset($profile['profileVersion']) ? (int)$profile['profileVersion'] : null;
    $pdo->prepare("
        UPDATE kycbank_requests
           SET needs_review       = CASE WHEN profile_json IS NULL OR COALESCE(profile_version, -1) <> COALESCE(?::int, -1) THEN TRUE ELSE needs_review END,
               review_reason      = CASE WHEN profile_json IS NULL THEN 'new_profile'
                                         WHEN COALESCE(profile_version, -1) <> COALESCE(?::int, -1) THEN 'profile_updated'
                                         ELSE review_reason END,
               profile_version    = ?::int,
               latest_profile_version = GREATEST(COALESCE(latest_profile_version, 0), COALESCE(?::int, 0)),
               profile_json       = ?::jsonb,
               profile_fetched_at = NOW(),
               last_error_code    = NULL,
               updated_at         = NOW()
         WHERE id = ? AND state <> 'withdrawn'
    ")->execute([$version, $version, $version, $version, json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $requestId]);
}

/**
 * Asks KYC Bank for the consent's current state and, when it is active and
 * ZuruBank does not hold the latest profile (or $forceProfile), fetches the
 * profile too. Never fetches anything for a withdrawn request.
 *
 * @return string the state after syncing
 */
function kb_sync(PDO $pdo, KycBankClient $client, array $row, bool $forceProfile = false): string
{
    if ($row['state'] === 'withdrawn' || empty($row['consent_id'])) {
        return (string)$row['state'];
    }
    $id = (int)$row['id'];
    $consent = $client->getConsentRequest((string)$row['consent_id']);
    kb_apply_consent($pdo, $id, $consent);
    $state = (string)$consent['state'];
    if ($state !== 'active') {
        return $state;
    }
    $latest = isset($consent['latestProfileVersion']) ? (int)$consent['latestProfileVersion'] : null;
    $have   = $row['profile_json'] !== null ? (int)$row['profile_version'] : null;
    if ($forceProfile || $have === null || ($latest !== null && $latest > $have)) {
        try {
            kb_store_profile($pdo, $id, $client->getProfile((string)$row['consent_id']));
        } catch (KycBankException $e) {
            if ($e->errorCode !== 'consent_not_active') {
                throw $e;
            }
            // The consent changed between the two calls; record where it is now.
            kb_apply_consent($pdo, $id, $client->getConsentRequest((string)$row['consent_id']));
            $state = (string)(kb_request($pdo, $id)['state'] ?? $state);
        }
    }
    return $state;
}

/**
 * Creates (or, with an existing row, retries) a request at KYC Bank. The
 * row and its idempotency key are saved before calling, so a retry after a
 * timeout gets the same consent back instead of a second request.
 *
 * @return array the row after the call
 * @throws KycBankException when KYC Bank refuses or cannot be reached (the row keeps the error code)
 */
function kb_create_request(
    PDO $pdo,
    KycBankClient $client,
    int $userId,
    string $kind,
    string $value,
    string $level,
    string $purpose,
    int $staffId,
    ?array $retryRow = null
): array {
    if ($retryRow) {
        $requestId = (int)$retryRow['id'];
        $key       = (string)$retryRow['idempotency_key'];
        $kind      = (string)$retryRow['identifier_kind'];
        $level     = (string)$retryRow['level'];
        $purpose   = (string)$retryRow['purpose'];
    } else {
        $key = 'zuru-' . bin2hex(random_bytes(16));
        $st = $pdo->prepare("
            INSERT INTO kycbank_requests (user_id, idempotency_key, identifier_kind, purpose, level, state, requested_by)
            VALUES (?, ?, ?, ?, ?, 'creating', ?)
            RETURNING id
        ");
        $st->execute([$userId, $key, $kind, $purpose, $level, $staffId]);
        $requestId = (int)$st->fetchColumn();
    }

    try {
        $consent = $client->createConsentRequest($kind, $value, $level, $purpose, $key, KB_DURATION_DAYS, true);
    } catch (KycBankException $e) {
        // A refusal (validation, duplicate, forbidden) spends the key; trouble reaching KYC Bank does not.
        $pdo->prepare("UPDATE kycbank_requests SET state = ?, last_error_code = ?, updated_at = NOW() WHERE id = ? AND consent_id IS NULL")
            ->execute([$e->isTransient() ? 'creating' : 'failed', $e->errorCode, $requestId]);
        error_log('[KYCBank] create request ' . $requestId . ' for user ' . $userId . ' failed: ' . $e->errorCode . ' (HTTP ' . $e->httpStatus . ')');
        kb_audit($pdo, 'KYCBANK_REQUEST_FAILED', $requestId, $staffId, ['user_id' => $userId, 'error' => $e->errorCode]);
        throw $e;
    }

    if (empty($consent['id'])) {
        kb_set_error($pdo, $requestId, 'bad_response');
        throw new KycBankException('bad_response', 'KYC Bank returned a consent without an id.', 200);
    }
    // Same consent id already on another row means this key was answered before (e.g. a double submit).
    $other = kb_request_by_consent($pdo, (string)$consent['id']);
    if ($other && (int)$other['id'] !== $requestId) {
        $pdo->prepare("DELETE FROM kycbank_requests WHERE id = ? AND consent_id IS NULL")->execute([$requestId]);
        return $other;
    }
    $pdo->prepare('UPDATE kycbank_requests SET consent_id = ? WHERE id = ?')->execute([(string)$consent['id'], $requestId]);
    kb_apply_consent($pdo, $requestId, $consent);
    error_log('[KYCBank] request ' . $requestId . ' for user ' . $userId . ' sent as consent ' . $consent['id']);
    kb_audit($pdo, 'KYCBANK_REQUESTED', $requestId, $staffId, ['user_id' => $userId, 'consent_id' => (string)$consent['id']]);
    return kb_request($pdo, $requestId) ?? [];
}

// ----------------------------------------------------------------------
// Webhook events
// ----------------------------------------------------------------------

const KB_HANDLED_EVENTS = [
    'consent.approved', 'consent.rejected', 'consent.lapsed', 'consent.withdrawn',
    'consent.suspended', 'consent.resumed', 'consent.expired',
    'profile.updated', 'profile.stale',
];

/**
 * Acts on one verified webhook event. Throws (KycBankException that
 * isTransient(), PDOException) only when KYC Bank should deliver it again.
 *
 * @return array{result: string, detail: string} result is 'processed' or 'ignored'
 */
function kb_handle_event(PDO $pdo, ?KycBankClient $client, array $event): array
{
    $type      = (string)($event['event'] ?? '');
    $consentId = (string)($event['consentId'] ?? '');

    if (!in_array($type, KB_HANDLED_EVENTS, true)) {
        return ['result' => 'ignored', 'detail' => 'unhandled_event'];
    }
    $row = $consentId !== '' ? kb_request_by_consent($pdo, $consentId) : null;
    if (!$row) {
        return ['result' => 'ignored', 'detail' => 'unknown_consent'];
    }
    $id = (int)$row['id'];
    if ($row['state'] === 'withdrawn') {
        // The customer withdrew: ZuruBank no longer uses or fetches this data.
        return ['result' => 'ignored', 'detail' => 'already_withdrawn'];
    }

    switch ($type) {
        case 'consent.withdrawn':
            $at = kb_ts($event['occurredAt'] ?? null);
            $pdo->prepare("
                UPDATE kycbank_requests
                   SET state = 'withdrawn', suspended_reason = NULL, needs_review = FALSE,
                       withdrawn_at = COALESCE(?::timestamptz, NOW()), updated_at = NOW()
                 WHERE id = ?
            ")->execute([$at, $id]);
            kb_audit($pdo, 'KYCBANK_WITHDRAWN', $id, 0, ['consent_id' => $consentId, 'event_id' => (string)($event['id'] ?? '')]);
            return ['result' => 'processed', 'detail' => 'withdrawn'];

        case 'consent.rejected':
        case 'consent.lapsed':
        case 'consent.expired':
            $state = substr($type, strlen('consent.'));
            $pdo->prepare("UPDATE kycbank_requests SET state = ?, suspended_reason = NULL, decided_at = COALESCE(decided_at, ?::timestamptz), updated_at = NOW() WHERE id = ?")
                ->execute([$state, kb_ts($event['occurredAt'] ?? null), $id]);
            return ['result' => 'processed', 'detail' => $state];
    }

    // The rest need KYC Bank: approved, resumed, suspended, profile.updated, profile.stale.
    if (!$client) {
        throw new KycBankException('not_configured', 'KYC Bank API is not configured.', 503);
    }
    if ($type === 'consent.suspended') {
        // Mark it unusable straight away, then ask KYC Bank for the reason.
        $pdo->prepare("UPDATE kycbank_requests SET state = 'suspended', updated_at = NOW() WHERE id = ?")->execute([$id]);
    }
    $state = kb_sync($pdo, $client, kb_request($pdo, $id) ?? $row, $type === 'profile.updated');
    if (in_array($type, ['consent.approved', 'consent.resumed', 'profile.updated'], true)) {
        kb_audit($pdo, 'KYCBANK_SYNCED', $id, 0, ['consent_id' => $consentId, 'event_id' => (string)($event['id'] ?? ''), 'state' => $state]);
    }
    return ['result' => 'processed', 'detail' => $state];
}

// ----------------------------------------------------------------------
// Staff review
// ----------------------------------------------------------------------

/**
 * Staff decision on a fetched, active profile. Only this changes users.kyc_status.
 * Throws DomainException (message safe to show staff) when the request cannot be reviewed.
 */
function kb_review(PDO $pdo, int $requestId, string $decision, int $staffId, ?int $expectedVersion = null): void
{
    if (!in_array($decision, ['approved', 'rejected'], true)) {
        throw new InvalidArgumentException('decision');
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM kycbank_requests WHERE id = ? FOR UPDATE');
        $st->execute([$requestId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['state'] !== 'active' || $row['profile_json'] === null) {
            throw new DomainException('Only an active consent with a fetched profile can be reviewed. Refresh the status first.');
        }
        if ($expectedVersion !== null && (int)$row['profile_version'] !== $expectedVersion) {
            throw new DomainException('A newer profile arrived while you were reviewing. Please review the latest version.');
        }
        $pdo->prepare("
            UPDATE kycbank_requests
               SET review_decision = ?, reviewed_by = ?, reviewed_at = NOW(),
                   reviewed_profile_version = profile_version, needs_review = FALSE, updated_at = NOW()
             WHERE id = ?
        ")->execute([$decision, $staffId, $requestId]);
        $pdo->prepare('UPDATE users SET kyc_status = ? WHERE user_id = ?')->execute([$decision, (int)$row['user_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    kb_audit($pdo, $decision === 'approved' ? 'KYC_APPROVED_FROM_KYCBANK' : 'KYC_REJECTED_FROM_KYCBANK', $requestId, $staffId, [
        'user_id' => (int)$row['user_id'], 'consent_id' => (string)$row['consent_id'], 'profile_version' => $row['profile_version'],
    ]);
}
