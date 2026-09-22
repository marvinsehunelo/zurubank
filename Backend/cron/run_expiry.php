<?php
// Backend/cron/run_expiry.php - ZuruBank's scheduled work, for a Railway cron service.
// Railway has no crontab: schedule this service (e.g. every 5 minutes) with
//   start command: php Backend/cron/run_expiry.php
// Order matters: codes die before the money behind them is released.
// unused_swap_slips.php moves money (escrow -> partner 60% / middleman 40%) and has
// never run; it stays off until someone reviews what it will move, then set
// ENABLE_SWAP_SLIP_JOB=1 on this service.
$jobs = ['expire_codes.php', 'expire_holds.php', 'retry_vouchmorph_notifications.php'];
if (in_array(strtolower((string)getenv('ENABLE_SWAP_SLIP_JOB')), ['1', 'true', 'yes'], true)) {
    $jobs[] = 'unused_swap_slips.php';
}
foreach ($jobs as $job) {
    $start = microtime(true);
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/' . $job), $code);
    fwrite(STDOUT, sprintf("[run_expiry] %s exit=%d %.1fs\n", $job, $code, microtime(true) - $start));
}
