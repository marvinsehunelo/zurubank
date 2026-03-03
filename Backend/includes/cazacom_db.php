<?php
// backend/includes/cazacom_db.php

try {
    $cazacom_pdo = new PDO(
        "mysql:host=localhost;dbname=cazacom;charset=utf8mb4",
        "root",
        ""
    );
    $cazacom_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Cazacom DB connection failed: " . $e->getMessage()
    ]);
    exit;
}
?>
