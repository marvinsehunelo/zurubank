<?php
/**
 * Runner for processExpiredSwaps()
 * Works for both CLI and AJAX/dashboard requests
 */

header('Content-Type: application/json');

try {
    // Include function
    require_once __DIR__ . '/processExpiredSwaps.php';
    // Include DB & config
    require_once __DIR__ . '/../../backend/config/db.php';
    $config = require __DIR__ . '/../../backend/config/settings.php';

    if (!function_exists('processExpiredSwaps')) {
        throw new Exception('processExpiredSwaps function not loaded');
    }

    // Run function
    processExpiredSwaps($pdo, $config);

    if (php_sapi_name() === 'cli') {
        echo "✅ CLI: Expired swaps processed successfully.\n";
    } else {
        echo json_encode([
            'status' => 'success',
            'message' => 'Expired swaps processed successfully.'
        ]);
    }

} catch (Throwable $e) {
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, "❌ CLI ERROR: " . $e->getMessage() . "\n");
        exit(1);
    } else {
        http_response_code(500);
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}
