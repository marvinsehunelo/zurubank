<?php
// Backend/cron/run_expiry.php - ZuruBank's scheduled work, for a Railway cron service.
// Railway has no crontab: schedule this service (e.g. every 5 minutes) with
//   start command: php Backend/cron/run_expiry.php
// Order matters: codes die before the money behind them is released.
foreach (['expire_codes.php', 'expire_holds.php', 'unused_swap_slips.php'] as $job) {
    $start = microtime(true);
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/' . $job), $code);
    fwrite(STDOUT, sprintf("[run_expiry] %s exit=%d %.1fs\n", $job, $code, microtime(true) - $start));
}
