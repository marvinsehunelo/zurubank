<?php
header('Content-Type: application/json');

echo json_encode([
    'pdo_pgsql_loaded' => extension_loaded('pdo_pgsql'),
    'pgsql_loaded' => extension_loaded('pgsql'),
    'pdo_drivers' => PDO::getAvailableDrivers(),
    'database_url' => getenv('DATABASE_URL') ? 'set' : 'not set'
], JSON_PRETTY_PRINT);
