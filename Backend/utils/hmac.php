<?php
// zurubank/backend/utils/hmac.php
function get_header($name) {
    foreach (getallheaders() as $k => $v) {
        if (strcasecmp($k, $name) === 0) return $v;
    }
    return null;
}
function verify_request_hmac($rawBody, $signatureHeader, $timestampHeader, $secret, $max_skew_seconds = 300) {
    if (!$signatureHeader || !$timestampHeader || !$secret) return false;
    $ts = strtotime($timestampHeader);
    if ($ts === false) return false;
    $now = time();
    if (abs($now - $ts) > $max_skew_seconds) return false;
    if (strpos($signatureHeader, 'sha256=') !== 0) return false;
    $sig = substr($signatureHeader, 7);
    $computed = hash_hmac('sha256', $timestampHeader . '.' . $rawBody, $secret);
    return hash_equals($computed, $sig);
}
function sign_payload($rawBody, $secret) {
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $sig = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return ['signature'=>'sha256='.$sig,'timestamp'=>$timestamp];
}
