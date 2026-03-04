<?php
// db.php - ZuruBank using DATABASE_URL (Railway standard)

// First try DATABASE_URL (Railway standard)
$database_url = getenv('DATABASE_URL');

if ($database_url) {
    // Parse DATABASE_URL
    // Format: postgresql://user:password@host:port/database
    $parts = parse_url($database_url);
    
    $host = $parts['host'] ?? 'postgres.railway.internal';
    $port = $parts['port'] ?? 5432;
    $username = $parts['user'] ?? 'postgres';
    $password = $parts['pass'] ?? '';
    $dbname = ltrim($parts['path'] ?? '/railway', '/');
    
    error_log("Using DATABASE_URL: $host:$port/$dbname");
} else {
    // Fallback to individual PG variables or defaults
    $host = getenv('PGHOST') ?: 'postgres.railway.internal';
    $port = getenv('PGPORT') ?: '5432';
    $dbname = getenv('PGDATABASE') ?: 'railway';
    $username = getenv('PGUSER') ?: 'postgres';
    $password = getenv('PGPASSWORD') ?: 'KZjtysMtAaUcBpzPXjsqeYmgOuGBmPKx';
    
    error_log("Using PG variables: $host:$port/$dbname");
}

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
    error_log("✅ ZuruBank Database connected successfully");
} catch (PDOException $e) {
    error_log("❌ ZuruBank Database connection failed: " . $e->getMessage());
    $pdo = null;
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}
?>
