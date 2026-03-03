<?php
$client_name = 'VouchMorph Sandbox';
$api_key = bin2hex(random_bytes(32)); // 64-character key
echo "API Key for $client_name: $api_key\n";
