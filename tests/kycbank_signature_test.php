<?php
// tests/kycbank_signature_test.php
//
// Checks KYC Bank webhook signature verification. CLI only:
//     php tests/kycbank_signature_test.php
// Uses a made-up secret and payload.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../Backend/integrations/kycbank/KycBankClient.php';

$secret = 'test-only-not-a-real-secret';
$body   = '{"id":"evt_00000000-0000-4000-8000-000000000001","event":"consent.approved","occurredAt":"2026-01-01T00:00:00Z","consentId":"cns_test0001","profileVersion":1}';
$now    = 1767225600;

function sign(string $body, string $secret, int $t): string
{
    return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
}

$cases = [
    'valid signature'             => [true,  $body,                        sign($body, $secret, $now),        $secret],
    'valid, 4 minutes old'        => [true,  $body,                        sign($body, $secret, $now - 240),  $secret],
    'valid, uppercase hex'        => [true,  $body,                        't=' . $now . ',v1=' . strtoupper(hash_hmac('sha256', $now . '.' . $body, $secret)), $secret],
    'tampered body'               => [false, str_replace('"profileVersion":1', '"profileVersion":2', $body), sign($body, $secret, $now), $secret],
    'wrong secret'                => [false, $body,                        sign($body, 'some-other-secret', $now), $secret],
    'stale t (6 minutes old)'     => [false, $body,                        sign($body, $secret, $now - 360),  $secret],
    't 6 minutes in the future'   => [false, $body,                        sign($body, $secret, $now + 360),  $secret],
    'missing header'              => [false, $body,                        null,                              $secret],
    'empty header'                => [false, $body,                        '',                                $secret],
    'no v1'                       => [false, $body,                        't=' . $now,                       $secret],
    'no t'                        => [false, $body,                        'v1=' . hash_hmac('sha256', $now . '.' . $body, $secret), $secret],
    'secret not configured'       => [false, $body,                        sign($body, $secret, $now),        ''],
];

$failed = 0;
foreach ($cases as $name => [$expected, $b, $header, $s]) {
    $got = KycBankClient::verifyWebhookSignature($b, $header, $s, $now);
    $ok  = $got === $expected;
    $failed += $ok ? 0 : 1;
    printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
}

echo $failed === 0 ? "\nAll " . count($cases) . " passed.\n" : "\n{$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
