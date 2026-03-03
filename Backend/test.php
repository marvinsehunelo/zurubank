<?php
declare(strict_types=1);

// === Strict error handling & logging ===
error_reporting(E_ALL);
ini_set('display_errors', '1');

// === Log file ===
$logFile = '/tmp/test_status_truncate.log';
file_put_contents($logFile, "=== Status Truncate Test ===\n", FILE_APPEND);

try {
    // === Connect to swap_system DB ===
    $pdo = new PDO(
        "mysql:host=localhost;dbname=swap_system;charset=utf8mb4",
        "root",       // change to your DB username
        "",           // change to your DB password
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Tables to test
    $tables = [
        'swap_ledgers' => [
            'columns' => [
                'swap_reference' => 'TEST123',
                'from_institution' => 'TEST_FROM',
                'to_institution' => 'TEST_TO',
                'from_account' => 0,
                'to_account' => 0,
                'amount' => 100,
                'currency_code' => 'BWP',
                'swap_fee' => 0,
                'direction' => 'out',
                'notes' => 'Test insert',
                'status' => 'pending', // test the enum
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ],
        'swap_requests' => [
            'columns' => [
                'user_id' => 0,
                'from_currency' => 'BWP',
                'to_currency' => 'BWP',
                'amount' => 100,
                'status' => 'pending',
                'fraud_check_status' => 'pending',
                'processor_reference' => 'TEST123',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ]
        ]
    ];

    foreach ($tables as $table => $info) {
        $cols = array_keys($info['columns']);
        $placeholders = array_fill(0, count($cols), '?');
        $sql = "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($info['columns']));
        file_put_contents($logFile, "SUCCESS: Inserted dummy row into $table\n", FILE_APPEND);
    }

    file_put_contents($logFile, "Test completed. Check above for any errors.\n", FILE_APPEND);
    echo "Test completed. Log: $logFile\n";

} catch (PDOException $e) {
    file_put_contents($logFile, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    echo "Test failed. Check log: $logFile\n";
}

