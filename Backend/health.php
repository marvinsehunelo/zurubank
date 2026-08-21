<?php
/**
 * /Backend/health.php
 * Lightweight liveness check for VouchMorph reachability monitoring.
 * Intentionally does not touch the database — a slow DB should not
 * flip this to unhealthy. No auth: this is meant to be pinged freely.
 */

require_once __DIR__ . '/helpers/response.php';

header("Content-Type: application/json");
http_response_code(200);

json_response("ok", [
    "service"   => "zurubank",
    "timestamp" => date('c'),
]);
