<?php
// test_zurubank_certificate.php
// Run on ZuruBank server

require_once __DIR__ . '/../../Backend/helpers/CertificateManager.php';

echo "=== ZURUBANK CERTIFICATE VERIFICATION TEST ===\n\n";

// Test payloads that ZuruBank would receive from VouchMorph
$testPayloads = [
    'VERIFY_ASSET' => [
        'action' => 'VERIFY_ASSET',
        'reference' => 'SWAP_1784973884251',
        'asset_type' => 'ACCOUNT',
        'amount' => 1000,
        'currency' => 'BWP',
        'source_identifier' => '10000001',
        'source_identifier_type' => 'auto',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'institution' => 'ZURUBANK',
        'swap_type' => 'DEPOSIT',
        'requester' => 'VOUCHMORPH',
        'timestamp' => time()
    ],
    'PLACE_HOLD (ACCOUNT)' => [
        'action' => 'PLACE_HOLD',
        'reference' => 'SWAP_1784973884251',
        'asset_type' => 'ACCOUNT',
        'asset_id' => 10,
        'amount' => 1000,
        'currency' => 'BWP',
        'source_identifier' => '10000001',
        'source_identifier_type' => 'auto',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'destination_institution' => 'SACCUSSALIS',
        'hold_reason' => 'PENDING_SWAP',
        'user_id' => 12,
        'expiry' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        'requester' => 'VOUCHMORPH',
        'timestamp' => time()
    ],
    'PLACE_HOLD (WALLET)' => [
        'action' => 'PLACE_HOLD',
        'reference' => 'SWAP_1784973884252',
        'asset_type' => 'WALLET',
        'amount' => 500,
        'currency' => 'BWP',
        'phone' => '+26770000000',
        'wallet_phone' => '+26770000000',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'destination_institution' => 'SACCUSSALIS',
        'hold_reason' => 'PENDING_SWAP',
        'user_id' => 42,
        'expiry' => date('Y-m-d H:i:s', strtotime('+24 hours')),
        'requester' => 'VOUCHMORPH',
        'timestamp' => time()
    ],
    'PROCESS_DEPOSIT_WITH_PROOF' => [
        '_skip_hold' => true,
        'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
        'reference' => 'SWAP_1784973884251',
        'amount' => 994,
        'currency' => 'BWP',
        'asset_type' => 'WALLET',
        'destination_asset_type' => 'WALLET',
        'destination_identifier' => '+26770000000',
        'destination_identifier_type' => 'phone',
        'destination_institution' => 'SACCUSSALIS',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'to_institution' => 'SACCUSSALIS',
        'hold_reference' => 'SWAP_1784973884251',
        'user_id' => 42,
        'bank' => 'SACCUSSALIS',
        'requester' => 'VOUCHMORPH',
        'timestamp' => time()
    ],
    'PROCESS_DEPOSIT (with source_hold)' => [
        '_skip_hold' => true,
        'action' => 'PROCESS_DEPOSIT_WITH_PROOF',
        'reference' => 'SWAP_1784973884251',
        'amount' => 994,
        'currency' => 'BWP',
        'asset_type' => 'WALLET',
        'destination_asset_type' => 'WALLET',
        'destination_identifier' => '+26770000000',
        'destination_identifier_type' => 'phone',
        'destination_institution' => 'SACCUSSALIS',
        'from_institution' => 'ZURUBANK',
        'source_institution' => 'ZURUBANK',
        'to_institution' => 'SACCUSSALIS',
        'hold_reference' => 'SWAP_1784973884251',
        'user_id' => 42,
        'bank' => 'SACCUSSALIS',
        'requester' => 'VOUCHMORPH',
        'timestamp' => time(),
        'source_hold' => [
            'payload' => ['action' => 'PLACE_HOLD', 'hold_reference' => 'SWAP_1784973884251'],
            'signature' => 'test_signature'
        ],
        'source_verification' => [
            'payload' => ['action' => 'VERIFY_ASSET'],
            'signature' => 'test_signature'
        ]
    ]
];

$cm = new CertificateManager('ZURUBANK');

echo "CertificateManager configured: " . ($cm->isConfigured() ? "YES" : "NO") . "\n";

// Simulate a signed request from VouchMorph
$vouchmorphSigner = new CertificateManager('VOUCHMORPH');

foreach ($testPayloads as $name => $payload) {
    echo "\n=== TESTING $name ===\n";
    echo "Original payload keys: " . implode(', ', array_keys($payload)) . "\n";
    
    // Sign as VouchMorph would
    $signed = $vouchmorphSigner->createSignedRequest($payload, 'VOUCHMORPH');
    
    echo "Signed payload keys: " . implode(', ', array_keys($signed)) . "\n";
    echo "Has signature: " . (isset($signed['signature']) ? 'YES' : 'NO') . "\n";
    echo "Has certificate: " . (isset($signed['certificate']) ? 'YES' : 'NO') . "\n";
    echo "Has requester: " . (isset($signed['requester']) ? 'YES' : 'NO') . "\n";
    
    // Verify as ZuruBank would
    $verification = $cm->verifySignedRequest($signed);
    echo "Verification result: " . ($verification['verified'] ? "VALID ✓" : "INVALID ✗") . "\n";
    echo "Message: " . $verification['message'] . "\n";
    
    if (!$verification['verified']) {
        echo "\n=== DEBUG: What ZuruBank is verifying ===\n";
        $payloadToVerify = $signed;
        unset($payloadToVerify['signature']);
        unset($payloadToVerify['certificate']);
        unset($payloadToVerify['requester']);
        ksort($payloadToVerify);
        echo "JSON verified: " . json_encode($payloadToVerify, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        
        echo "\n=== What was signed by VouchMorph ===\n";
        $signedPayload = $signed;
        unset($signedPayload['signature']);
        unset($signedPayload['certificate']);
        // requester IS included in VouchMorph's signed payload
        ksort($signedPayload);
        echo "JSON signed: " . json_encode($signedPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        
        echo "\n=== DIFFERENCES ===\n";
        $signedKeys = array_keys($signedPayload);
        $verifyKeys = array_keys($payloadToVerify);
        echo "Signed keys: " . implode(', ', $signedKeys) . "\n";
        echo "Verify keys: " . implode(', ', $verifyKeys) . "\n";
        
        $missingFromVerify = array_diff($signedKeys, $verifyKeys);
        $extraInVerify = array_diff($verifyKeys, $signedKeys);
        
        if (!empty($missingFromVerify)) {
            echo "Keys in signed but NOT in verify: " . implode(', ', $missingFromVerify) . "\n";
        }
        if (!empty($extraInVerify)) {
            echo "Keys in verify but NOT in signed: " . implode(', ', $extraInVerify) . "\n";
        }
    }
}

echo "\n=== TEST COMPLETE ===\n";
