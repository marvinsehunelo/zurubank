<?php
// test_dashboard_run.php — mimic dashboard include of unused_swap_slips.php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/dashboard_test_error_log.txt');

echo "<pre>🔍 Dashboard test for unused_swap_slips.php...</pre>";

try {
    $filePath = __DIR__ . '/unused_swap_slips.php';
    if (!file_exists($filePath)) {
        throw new Exception("File not found at: $filePath");
    }
    echo "<pre>✅ File found. Including...\n</pre>";
    include_once $filePath;

    if (!function_exists('processExpiredSwaps')) {
        throw new Exception("Function 'processExpiredSwaps' not found in $filePath");
    }

    // Include DB and config for dashboard context
    require_once __DIR__ . '/../../Backend/config/db.php';
    $config = require __DIR__ . '/../../Backend/config/settings.php';

    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception("PDO connection not available.");
    }

    echo "<pre>✅ Running processExpiredSwaps()...</pre>";
    processExpiredSwaps($pdo, $config);
    echo "<pre>✅ Function executed successfully. Check DB/logs.</pre>";

} catch (Throwable $e) {
    echo "<pre>❌ ERROR: " . $e->getMessage() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "</pre>";
}

echo "<pre>🔚 Test complete. Check dashboard_test_error_log.txt for more details.</pre>";
