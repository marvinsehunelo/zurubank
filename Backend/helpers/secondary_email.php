<?php
// Backend/helpers/secondary_email.php
//
// A verified secondary email address for customers.
//
// The email on users.email was a placeholder for many customers, so it
// cannot be trusted to reach anybody. A customer adds a real address from
// their dashboard (Frontend/dashboard/secondary_email.php) and proves it
// is theirs with a 6-digit code. Only then is it stored, and only a
// verified address is ever used:
//   - Backend/api/v1/oauth/authorize.php emails a code before the customer
//     can allow VouchMorph to link their account as a source.
//
// Tables are created on first use; the same SQL is in
// database/migrations/2026_10_10_secondary_email.sql.
//
// Email goes out through Resend's HTTPS API: set RESEND_API_KEY, and
// SMTP_FROM_EMAIL / SMTP_FROM_NAME for the sender (default
// "ZuruBank <zurubank@vouchmorphn.com>"). The sender's domain must be
// verified in Resend.

const SE_BANK_NAME          = 'ZuruBank';
const SE_DEFAULT_FROM_EMAIL = 'zurubank@vouchmorphn.com';
const SE_BRAND_COLOR        = '#0B1D51';
const SE_CODE_TTL           = 600;  // seconds a code is valid
const SE_MAX_ATTEMPTS       = 5;    // wrong codes before a code is spent
const SE_RESEND_COOLDOWN    = 60;   // seconds between two codes
const SE_MAX_SENDS_PER_HOUR = 5;    // per customer, per purpose

function se_ensure_tables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    // Only verified addresses live here. An address being verified exists
    // only on its code row until the code comes back, so changing to a new
    // address never loses the old one before the new one is proven.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_secondary_emails (
            user_id     INTEGER PRIMARY KEY,
            email       VARCHAR(255) NOT NULL,
            verified_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_verification_codes (
            id         BIGSERIAL PRIMARY KEY,
            user_id    INTEGER NOT NULL,
            email      VARCHAR(255) NOT NULL,
            purpose    VARCHAR(30) NOT NULL,
            code_hash  VARCHAR(255) NOT NULL,
            attempts   INTEGER NOT NULL DEFAULT 0,
            expires_at TIMESTAMPTZ NOT NULL,
            used_at    TIMESTAMPTZ NULL,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )
    ");
    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_email_verification_codes_user
            ON email_verification_codes (user_id, purpose, created_at DESC)
    ");
    $ready = true;
}

/** The address in one shape (trimmed, lower case), or null if it is not an address. */
function se_normalize(string $raw): ?string
{
    $email = strtolower(trim($raw));
    if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return $email;
}

function se_mask(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return substr($local, 0, 1) . str_repeat('•', max(1, strlen($local) - 1)) . '@' . $domain;
}

/** The customer's verified secondary email, or null. */
function se_verified_email(PDO $pdo, int $userId): ?array
{
    se_ensure_tables($pdo);
    $stmt = $pdo->prepare('SELECT email, verified_at FROM user_secondary_emails WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Emails a fresh code for $purpose ('verify_email' to prove a new address,
 * 'source_link' when VouchMorph asks to link the account).
 *
 * @return array{ok: bool, message: string}
 */
function se_send_code(PDO $pdo, int $userId, string $email, string $purpose): array
{
    se_ensure_tables($pdo);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FILTER (WHERE created_at > NOW() - INTERVAL '1 hour') AS last_hour,
               EXTRACT(EPOCH FROM (NOW() - MAX(created_at)))::int AS since_last
          FROM email_verification_codes
         WHERE user_id = ? AND purpose = ?
    ");
    $stmt->execute([$userId, $purpose]);
    $recent = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if ($recent['since_last'] !== null && (int)$recent['since_last'] < SE_RESEND_COOLDOWN) {
        $wait = SE_RESEND_COOLDOWN - (int)$recent['since_last'];
        return ['ok' => false, 'message' => "Please wait {$wait} seconds before asking for another code."];
    }
    if ((int)($recent['last_hour'] ?? 0) >= SE_MAX_SENDS_PER_HOUR) {
        return ['ok' => false, 'message' => 'Too many codes have been sent. Please try again in an hour.'];
    }

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $pdo->prepare('UPDATE email_verification_codes SET used_at = NOW() WHERE user_id = ? AND purpose = ? AND used_at IS NULL')
        ->execute([$userId, $purpose]);
    $insert = $pdo->prepare("
        INSERT INTO email_verification_codes (user_id, email, purpose, code_hash, expires_at)
        VALUES (?, ?, ?, ?, NOW() + make_interval(secs => ?))
        RETURNING id
    ");
    $insert->execute([$userId, $email, $purpose, password_hash($code, PASSWORD_DEFAULT), SE_CODE_TTL]);
    $codeId = (int)$insert->fetchColumn();

    $what = $purpose === 'source_link'
        ? 'to confirm that you want VouchMorph to link your ' . SE_BANK_NAME . ' account'
        : 'to verify this email address for your ' . SE_BANK_NAME . ' account';

    if (!se_email_code($email, $code, $what, SE_CODE_TTL)) {
        // Nobody will see this code; it should not count against them.
        $pdo->prepare('DELETE FROM email_verification_codes WHERE id = ?')->execute([$codeId]);
        return ['ok' => false, 'message' => "We couldn't send an email right now. Please try again in a few minutes."];
    }

    return ['ok' => true, 'message' => 'We sent a 6-digit code to ' . se_mask($email) . '.'];
}

/**
 * Checks a code for $purpose and spends it on success.
 *
 * @return array{ok: bool, message: string, email?: string}
 */
function se_check_code(PDO $pdo, int $userId, string $purpose, string $code): array
{
    se_ensure_tables($pdo);

    $stmt = $pdo->prepare("
        SELECT id, email, code_hash, attempts, (expires_at <= NOW()) AS expired
          FROM email_verification_codes
         WHERE user_id = ? AND purpose = ? AND used_at IS NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 1
    ");
    $stmt->execute([$userId, $purpose]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => 'That code is no longer valid. Please ask for a new one.'];
    }
    if ($row['expired'] === true || $row['expired'] === 't' || $row['expired'] === 1) {
        return ['ok' => false, 'message' => 'That code has expired. Please ask for a new one.'];
    }
    if ((int)$row['attempts'] >= SE_MAX_ATTEMPTS) {
        return ['ok' => false, 'message' => 'Too many wrong codes. Please ask for a new one.'];
    }

    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6 || !password_verify($code, (string)$row['code_hash'])) {
        $attempts = (int)$row['attempts'] + 1;
        $pdo->prepare('UPDATE email_verification_codes SET attempts = ? WHERE id = ?')->execute([$attempts, $row['id']]);
        $left = SE_MAX_ATTEMPTS - $attempts;
        return ['ok' => false, 'message' => $left > 0
            ? "That code is not right. You have {$left} " . ($left === 1 ? 'try' : 'tries') . ' left.'
            : 'Too many wrong codes. Please ask for a new one.'];
    }

    $pdo->prepare('UPDATE email_verification_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    return ['ok' => true, 'message' => 'Code accepted.', 'email' => (string)$row['email']];
}

/** Checks the 'verify_email' code and saves the address it was sent to as verified. */
function se_confirm_email(PDO $pdo, int $userId, string $code): array
{
    $result = se_check_code($pdo, $userId, 'verify_email', $code);
    if (!$result['ok']) {
        return $result;
    }
    $pdo->prepare("
        INSERT INTO user_secondary_emails (user_id, email, verified_at, updated_at)
        VALUES (?, ?, NOW(), NOW())
        ON CONFLICT (user_id) DO UPDATE
            SET email = EXCLUDED.email, verified_at = NOW(), updated_at = NOW()
    ")->execute([$userId, $result['email']]);
    error_log("[SecondaryEmail] user {$userId} verified a secondary email");
    return ['ok' => true, 'message' => se_mask($result['email']) . ' is verified.', 'email' => $result['email']];
}

function se_remove(PDO $pdo, int $userId): void
{
    se_ensure_tables($pdo);
    $pdo->prepare('DELETE FROM user_secondary_emails WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('UPDATE email_verification_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
    error_log("[SecondaryEmail] user {$userId} removed their secondary email");
}

/** Emails a verification code; $what finishes "Use this code ...". */
function se_email_code(string $email, string $code, string $what, int $ttlSeconds): bool
{
    $minutes = max(1, intdiv($ttlSeconds, 60));
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;padding:24px;color:#111;">'
        . '<div style="font-size:13px;letter-spacing:3px;font-weight:700;color:' . SE_BRAND_COLOR . ';margin-bottom:16px;">' . htmlspecialchars(strtoupper(SE_BANK_NAME)) . '</div>'
        . '<p>Use this code ' . htmlspecialchars($what) . ':</p>'
        . '<p style="font-size:28px;font-weight:700;letter-spacing:6px;margin:16px 0;">' . htmlspecialchars($code) . '</p>'
        . "<p>It expires in {$minutes} minutes.</p>"
        . '<p><strong>Never share this code with anyone, including ' . htmlspecialchars(SE_BANK_NAME) . ' staff.</strong></p>'
        . '<p style="color:#666;font-size:13px;">If you did not ask for this, ignore this email and your account stays as it is.</p>'
        . '</div>';
    return se_send_mail($email, 'Your ' . SE_BANK_NAME . ' verification code', $html);
}

/** Sends one email through Resend. Never throws. */
function se_send_mail(string $to, string $subject, string $html): bool
{
    $apiKey = getenv('RESEND_API_KEY') ?: '';
    if ($apiKey === '') {
        error_log('[SecondaryEmail] RESEND_API_KEY is not set; cannot send email');
        return false;
    }
    $fromEmail = trim((string)getenv('SMTP_FROM_EMAIL')) ?: SE_DEFAULT_FROM_EMAIL;
    $fromName  = trim((string)getenv('SMTP_FROM_NAME')) ?: SE_BANK_NAME;
    $from      = "{$fromName} <{$fromEmail}>";

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'from'    => $from,
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
            'text'    => trim(html_entity_decode(strip_tags(str_replace(['</p>', '</div>'], "\n", $html)), ENT_QUOTES, 'UTF-8')),
        ]),
    ]);
    $body   = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        error_log("[SecondaryEmail] Resend send from {$fromEmail} to " . se_mask($to) . ' failed: '
            . ($body === false ? $error : "HTTP {$status} " . substr((string)$body, 0, 300)));
        return false;
    }
    $id = json_decode((string)$body, true)['id'] ?? '?';
    error_log("[SecondaryEmail] Resend accepted email from {$fromEmail} to " . se_mask($to) . " (id {$id})");
    return true;
}
