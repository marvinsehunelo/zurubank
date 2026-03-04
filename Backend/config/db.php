<?php
// db.php - ZuruBank Railway PostgreSQL Connection

// Railway PostgreSQL environment variables
$host = getenv('PGHOST') ?: 'postgres.railway.internal';
$port = getenv('PGPORT') ?: '5432';
$dbname = getenv('PGDATABASE') ?: 'railway';  // Railway default DB name
$username = getenv('PGUSER') ?: 'postgres';
$password = getenv('PGPASSWORD') ?: 'KZjtysMtAaUcBpzPXjsqeYmgOuGBmPKx';

// Log connection attempt (remove in production)
error_log("Connecting to ZuruBank DB: $host:$port/$dbname as $username");

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]
    );
    error_log("ZuruBank Database connected successfully");
} catch (PDOException $e) {
    error_log("ZuruBank Database connection failed: " . $e->getMessage());
    // Don't exit - set $pdo to null for graceful handling
    $pdo = null;
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}
?>
