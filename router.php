<?php
// router.php - front controller for PHP's built-in server:
//   php -S 0.0.0.0:$PORT -t . router.php
//
// The repository root is the web root, so without this every file in the repo could be
// downloaded: database dumps, logs, migrations, config and include files, tests and debug pages.
// This refuses those paths with a plain 404 and lets the built-in server handle everything else
// exactly as before (pages, assets, API endpoints, webhooks).

$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$lower = strtolower(str_replace('\\', '/', $path));

$deny = [
    '#/\.#',                                                         // .git, .env, .claude, any dotfile
    '#\.\.#',                                                        // no climbing out of the web root
    '#^/(database|tests|vendor|cron|lib|docs|tmp|node_modules)(/|$)#', // never web content
    '#^/backend/(config|cron|tmp|lib|includes/tcpdf)(/|$)#',         // includes, jobs and scratch files
    '#\.(sql|log|txt|md|sh|bak|old|orig|swp|pem|key|crt|csr|p12|pfx|env|ini|toml|lock|ya?ml|dist)$#',
    '#(^|/)(composer\.(json|lock)|package(-lock)?\.json|dockerfile|railpack\.json|nixpacks\.toml|router\.php|phpinfo\.php)$#',
    '#(^|/)[^/]*test[^/]*\.(php|html)$#',                            // test.php, test1.php, *test*.html debug pages
];
foreach ($deny as $pattern) {
    if (preg_match($pattern, $lower)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
        return true;
    }
}
return false; // serve the file or run the script as usual
