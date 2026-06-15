<?php
// test_fix_key.php

$privateKey = getenv('ZURUBANK_PRIVATE_KEY_CONTENT');

echo "=== RAW KEY DEBUG ===\n";
echo "Length: " . strlen($privateKey) . "\n";
echo "Contains literal \\n: " . (strpos($privateKey, '\\n') !== false ? 'YES' : 'NO') . "\n";
echo "Contains actual newlines: " . (strpos($privateKey, "\n") !== false ? 'YES' : 'NO') . "\n";

// Fix 1: Replace literal \n with actual newlines
$fixed = str_replace(['\\n', '\n'], "\n", $privateKey);

// Fix 2: Ensure proper formatting
$fixed = preg_replace('/\r/', '', $fixed);
$fixed = trim($fixed);

// Extract the base64 content
$base64Content = preg_replace('/-----BEGIN PRIVATE KEY-----/', '', $fixed);
$base64Content = preg_replace('/-----END PRIVATE KEY-----/', '', $base64Content);
$base64Content = preg_replace('/\s/', '', $base64Content);

echo "\n=== BASE64 VALIDATION ===\n";
echo "Base64 length: " . strlen($base64Content) . "\n";
echo "Base64 valid: " . (preg_match('/^[A-Za-z0-9+\/=]+$/', $base64Content) ? 'YES' : 'NO') . "\n";

if (preg_match('/^[A-Za-z0-9+\/=]+$/', $base64Content)) {
    // Re-wrap properly
    $chunks = str_split($base64Content, 64);
    $reconstructed = "-----BEGIN PRIVATE KEY-----\n" . implode("\n", $chunks) . "\n-----END PRIVATE KEY-----";
    
    echo "\n=== RECONSTRUCTED KEY ===\n";
    echo $reconstructed . "\n";
    
    // Test loading
    $key = openssl_pkey_get_private($reconstructed);
    if ($key) {
        echo "\n✅ KEY LOADS SUCCESSFULLY!\n";
        openssl_free_key($key);
    } else {
        echo "\n❌ KEY STILL FAILS\n";
        while ($err = openssl_error_string()) {
            echo "OpenSSL: $err\n";
        }
    }
} else {
    echo "\n❌ BASE64 CONTAINS INVALID CHARACTERS\n";
    // Find invalid characters
    preg_match_all('/[^A-Za-z0-9+\/=]/', $base64Content, $matches);
    echo "Invalid chars found: " . implode(', ', array_unique($matches[0])) . "\n";
}
