<?php
declare(strict_types=1);
require_once __DIR__ . '/api/FnbbAcquirerMock.php';

header('Content-Type: application/json');

try {
    $mock = new FnbbAcquirerMock($pdo);
    $rawBody = file_get_contents('php://input');
    $input = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode($rawBody, true) ?? []) : $_GET;
    $headers = getallheaders() ?: [];
    $mock->run($input, $rawBody, $headers, 'preauth');
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    error_log("[FNBB_MOCK preauth] " . $e->getMessage());
}
