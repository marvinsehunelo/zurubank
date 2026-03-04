<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$response = [
    'pdo_pgsql_loaded' => extension_loaded('pdo_pgsql'),
    'pgsql_loaded' => extension_loaded('pgsql'),
    'pdo_drivers' => PDO::getAvailableDrivers(),
    'database_url' => getenv('DATABASE_URL') ? 'set' : 'not set',
    'php_version' => phpversion()
];

// Try to connect using DATABASE_URL
if (getenv('DATABASE_URL')) {
    try {
        $pdo = new PDO(getenv('DATABASE_URL'));
        $response['connection_test'] = 'success';
        $response['version'] = $pdo->query("SELECT version()")->fetchColumn();
    } catch (PDOException $e) {
        $response['connection_test'] = 'failed';
        $response['connection_error'] = $e->getMessage();
    }
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
