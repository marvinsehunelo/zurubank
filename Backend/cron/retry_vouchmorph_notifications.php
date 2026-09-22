<?php
// Resends cash-dispensed notifications VouchMorph has not yet confirmed (every 5 minutes via run_expiry.php).
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/vouchmorph_webhook.php';
echo json_encode(vm_retry_notifications($pdo, 'ZURUBANK')) . PHP_EOL;
