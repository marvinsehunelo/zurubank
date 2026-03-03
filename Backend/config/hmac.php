<?php
function generateHmac($data, $secret) {
    if (is_array($data)) {
        // Ensure consistent JSON formatting
        $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    return hash_hmac('sha256', $data, $secret);
}

function verifyHmac($data, $signature, $secret) {
    $expected = generateHmac($data, $secret);
    return hash_equals($expected, $signature);
}
