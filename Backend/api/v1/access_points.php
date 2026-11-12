<?php
// Backend/api/v1/access_points.php
//
// GET /Backend/api/v1/access_points.php
//
// The public list of ZuruBank's ATMs and branches that are in service, for VouchMorph's access
// point locator (VouchMorph fetches it on a schedule and caches it; another bank answers the
// same contract). Public information only: no sign-in, no customer data. Nothing is read from
// the request but its method; the query string is ignored. Nothing is written, except that
// the two tables are created when they are missing.
//
//   200 {"institution":"ZURUBANK","updated_at":"<ISO 8601 UTC>","access_points":[...]}
//       An empty list is a valid answer.
//   405 {"error":"..."}  any method other than GET
//   503 {"error":"..."}  the database could not be reached or read; no detail is given
//
// What is listed, cash_available and services are decided in Backend/lib/access_points.php.

ini_set('display_errors', '0');

require_once __DIR__ . '/../../lib/access_points.php';

$accessPointsAnswered = false;

function access_points_answer(int $status, array $body, string $cacheControl): void
{
    global $accessPointsAnswered;

    $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $status = 503;
        $cacheControl = 'no-store';
        $json = '{"error":"Access points are temporarily unavailable"}';
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $accessPointsAnswered = true;

    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: ' . $cacheControl);
    echo $json;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    access_points_answer(405, ['error' => 'Method not allowed. Use GET.'], 'no-store');
}

// config/db.php answers a failed connection itself, with HTTP 500, and stops the request.
// This endpoint's contract says 503, so whatever db.php prints is held back and, if the
// request stops before an answer was sent, replaced with the 503.
ob_start();
register_shutdown_function(function (): void {
    global $accessPointsAnswered;

    if ($accessPointsAnswered) {
        return;
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
    }
    echo '{"error":"Access points are temporarily unavailable"}';
});

require_once __DIR__ . '/../../config/db.php';

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('no database connection');
    }

    access_points_ensure_tables($pdo);
    $payload = access_points_payload(access_points_fetch_rows($pdo), time());
} catch (Throwable $e) {
    error_log('[access_points] could not read access points: ' . $e->getMessage());
    access_points_answer(503, ['error' => 'Access points are temporarily unavailable'], 'no-store');
}

access_points_answer(200, $payload, 'public, max-age=300');
