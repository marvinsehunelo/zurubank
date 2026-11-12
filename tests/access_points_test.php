<?php
// tests/access_points_test.php
//
// Checks the rules behind GET /Backend/api/v1/access_points.php (Backend/lib/access_points.php):
// what is listed, cash_available, services, and the shape of each access point and of the
// whole answer. No database. CLI only:
//     php tests/access_points_test.php
// Every name, place and figure below is made up.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../Backend/lib/access_points.php';

$failed = 0;
$total  = 0;

function check(string $name, mixed $got, mixed $expected): void
{
    global $failed, $total;
    $total++;
    $ok = $got === $expected;
    $failed += $ok ? 0 : 1;
    printf("%s  %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (!$ok) {
        printf("      expected %s\n      got      %s\n", json_encode($expected), json_encode($got));
    }
}

// A directory row as access_points_fetch_rows() returns it. PDO hands numeric columns over
// as strings, so the cash figures and coordinates are strings here too.
function row(array $overrides = []): array
{
    return $overrides + [
        'id' => 12, 'kind' => 'ATM', 'name' => 'Zurubank Demo ATM - Test Mall', 'town' => 'Demo Town',
        'address' => 'Test Mall, ground floor', 'hours' => '24 hours',
        'deposit' => false, 'cash_out' => false, 'latitude' => null, 'longitude' => null,
        'in_service' => true,
        'terminal_id' => null, 'terminal_status' => null, 'terminal_total_cash' => null,
        'terminal_reserved_cash' => null, 'terminal_last_sync' => null,
    ];
}

// The same ATM with a terminal record whose cash figures have been synced.
function terminal(array $overrides = []): array
{
    return row($overrides + [
        'terminal_id' => 'DEMO-ATM-01', 'terminal_status' => 'ACTIVE', 'terminal_total_cash' => '43210.0000',
        'terminal_reserved_cash' => '0.0000', 'terminal_last_sync' => '2026-01-01 08:00:00',
    ]);
}

function branch(array $overrides = []): array
{
    return row($overrides + [
        'id' => 3, 'kind' => 'BRANCH', 'name' => 'Zurubank Demo Branch - Test Town', 'hours' => 'Mon-Fri 08:30-16:30',
    ]);
}

// ---- cash_available: true, false, or null when not reported --------------------------------
$cash = [
    'ATM, cash left'                              => [true,  terminal()],
    'ATM, cash left after what is reserved'       => [true,  terminal(['terminal_reserved_cash' => '43209.9900'])],
    'ATM, reserved figure missing'                => [true,  terminal(['terminal_reserved_cash' => null])],
    'ATM, empty'                                  => [false, terminal(['terminal_total_cash' => '0.0000'])],
    'ATM, all of it reserved'                     => [false, terminal(['terminal_reserved_cash' => '43210.0000'])],
    'ATM, more reserved than it holds'            => [false, terminal(['terminal_reserved_cash' => '50000.0000'])],
    'ATM, no terminal record'                     => [null,  row()],
    'ATM, figures never synced (column default 0)' => [null, terminal(['terminal_total_cash' => '0.0000', 'terminal_last_sync' => null])],
    'ATM, figures never synced (a total is set)'  => [null,  terminal(['terminal_last_sync' => null])],
    'ATM, total missing'                          => [null,  terminal(['terminal_total_cash' => null])],
    'ATM, total is not a number'                  => [null,  terminal(['terminal_total_cash' => 'n/a'])],
    'branch'                                      => [null,  branch()],
    'branch, even with terminal columns filled'   => [null,  branch(['terminal_id' => 'DEMO-ATM-01', 'terminal_total_cash' => '43210.0000', 'terminal_last_sync' => '2026-01-01 08:00:00'])],
];
foreach ($cash as $name => [$expected, $r]) {
    check('cash_available: ' . $name, access_point_cash_available($r), $expected);
}

// ---- listed only when in service --------------------------------------------------------
$inService = [
    'ATM, terminal ACTIVE'                        => [true,  terminal()],
    'ATM, terminal status in lower case, spaces'  => [true,  terminal(['terminal_status' => ' active '])],
    'ATM, no terminal record'                     => [true,  row()],
    'ATM, terminal OUT_OF_SERVICE'                => [false, terminal(['terminal_status' => 'OUT_OF_SERVICE'])],
    'ATM, terminal status missing'                => [false, terminal(['terminal_status' => null])],
    'ATM, taken out of the directory'             => [false, terminal(['in_service' => false])],
    'ATM, in_service missing'                     => [false, row(['in_service' => null])],
    'branch'                                      => [true,  branch()],
    'branch, taken out of the directory'          => [false, branch(['in_service' => false])],
    'branch, ignores terminal columns'            => [true,  branch(['terminal_id' => 'DEMO-ATM-01', 'terminal_status' => 'OUT_OF_SERVICE'])],
    'in_service as "t"'                           => [true,  row(['in_service' => 't'])],
    'in_service as "f"'                           => [false, row(['in_service' => 'f'])],
    'in_service as 1'                             => [true,  row(['in_service' => 1])],
    'in_service as "0"'                           => [false, row(['in_service' => '0'])],
];
foreach ($inService as $name => [$expected, $r]) {
    check('in service: ' . $name, access_point_is_in_service($r), $expected);
}

// ---- services, in the contract's order ----------------------------------------------------
$services = [
    'ATM'                                         => [['cash_out', 'atm_voucher'], row()],
    'ATM that takes deposits'                     => [['cash_out', 'atm_voucher', 'deposit'], row(['deposit' => true])],
    'ATM, cash_out column changes nothing'        => [['cash_out', 'atm_voucher'], row(['cash_out' => true])],
    'branch'                                      => [['deposit', 'account_opening'], branch()],
    'branch that pays out cash-outs'              => [['cash_out', 'deposit', 'account_opening'], branch(['cash_out' => true])],
    'branch, deposit column changes nothing'      => [['deposit', 'account_opening'], branch(['deposit' => true])],
    'unknown kind'                                => [[], row(['kind' => 'AGENT'])],
];
foreach ($services as $name => [$expected, $r]) {
    check('services: ' . $name, access_point_services($r), $expected);
}

// ---- one row as the contract's access point --------------------------------------------
check('shape: ATM with a terminal record', access_point_to_json(terminal()), [
    'id' => 'ATM-12', 'kind' => 'ATM', 'name' => 'Zurubank Demo ATM - Test Mall', 'town' => 'Demo Town',
    'address' => 'Test Mall, ground floor', 'hours' => '24 hours',
    'services' => ['cash_out', 'atm_voucher'], 'cash_available' => true,
    'latitude' => null, 'longitude' => null,
]);
check('shape: branch with coordinates', access_point_to_json(branch(['cash_out' => true, 'latitude' => '-1.250000', 'longitude' => '2.500000'])), [
    'id' => 'BRANCH-3', 'kind' => 'BRANCH', 'name' => 'Zurubank Demo Branch - Test Town', 'town' => 'Demo Town',
    'address' => 'Test Mall, ground floor', 'hours' => 'Mon-Fri 08:30-16:30',
    'services' => ['cash_out', 'deposit', 'account_opening'], 'cash_available' => null,
    'latitude' => -1.25, 'longitude' => 2.5,
]);

$sparse = access_point_to_json(row(['kind' => ' atm ', 'name' => '  Zurubank Demo ATM - Test Mall ', 'address' => null, 'hours' => null]));
check('shape: kind is upper-cased, name trimmed', [$sparse['kind'], $sparse['id'], $sparse['name']], ['ATM', 'ATM-12', 'Zurubank Demo ATM - Test Mall']);
check('shape: missing address and hours are empty strings', [$sparse['address'], $sparse['hours']], ['', '']);
check('shape: only the contract\'s keys, in its order', array_keys(access_point_to_json(terminal())), [
    'id', 'kind', 'name', 'town', 'address', 'hours', 'services', 'cash_available', 'latitude', 'longitude',
]);

$coordinates = [
    'neither'                                     => [[null, null], row()],
    'latitude only'                               => [[null, null], row(['latitude' => '-1.250000'])],
    'longitude only'                              => [[null, null], row(['longitude' => '2.500000'])],
    'latitude out of range'                       => [[null, null], row(['latitude' => '91', 'longitude' => '2.5'])],
    'longitude out of range'                      => [[null, null], row(['latitude' => '-1.25', 'longitude' => '181'])],
    'not numbers'                                 => [[null, null], row(['latitude' => 'north', 'longitude' => 'east'])],
    'both, as strings'                            => [[-1.25, 2.5], row(['latitude' => '-1.250000', 'longitude' => '2.500000'])],
    'both, zero'                                  => [[0.0, 0.0],   row(['latitude' => '0.000000', 'longitude' => '0.000000'])],
];
foreach ($coordinates as $name => [$expected, $r]) {
    $json = access_point_to_json($r);
    check('coordinates: ' . $name, [$json['latitude'], $json['longitude']], $expected);
}

$undescribable = [
    'unknown kind'                                => row(['kind' => 'AGENT']),
    'no kind'                                     => row(['kind' => null]),
    'no name'                                     => row(['name' => '   ']),
    'no town'                                     => row(['town' => '']),
    'no id'                                       => row(['id' => null]),
];
foreach ($undescribable as $name => $r) {
    check('left out: ' . $name, access_point_to_json($r), null);
}

// ---- the whole answer ----------------------------------------------------------------------
$readAt  = 1767225600; // 2026-01-01T00:00:00Z
$payload = access_points_payload([
    terminal(),
    terminal(['id' => 13, 'terminal_status' => 'OUT_OF_SERVICE']),
    row(['id' => 14, 'in_service' => false]),
    row(['id' => 15, 'town' => '']),
    branch(),
], $readAt);

check('answer: top-level keys', array_keys($payload), ['institution', 'updated_at', 'access_points']);
check('answer: institution', $payload['institution'], 'ZURUBANK');
check('answer: updated_at is ISO 8601 UTC', $payload['updated_at'], '2026-01-01T00:00:00Z');
check('answer: only what is in service and describable', array_column($payload['access_points'], 'id'), ['ATM-12', 'BRANCH-3']);

$json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
check('answer: cash_available is a JSON boolean or null', [
    str_contains($json, '"cash_available":true'), str_contains($json, '"cash_available":null'),
], [true, true]);
check('answer: no cash figure and no terminal column is sent', [
    str_contains($json, '43210'), str_contains($json, 'terminal'), str_contains($json, 'total_cash'),
    str_contains($json, 'reserved'), str_contains($json, 'DEMO-ATM-01'),
], [false, false, false, false, false]);

$empty = access_points_payload([], $readAt);
check('answer: an empty list is still a list', json_encode($empty), '{"institution":"ZURUBANK","updated_at":"2026-01-01T00:00:00Z","access_points":[]}');
check('answer: nothing in service is an empty list too', access_points_payload([row(['in_service' => false])], $readAt)['access_points'], []);

echo $failed === 0 ? "\nAll {$total} passed.\n" : "\n{$failed} of {$total} failed.\n";
exit($failed === 0 ? 0 : 1);
