<?php
// tests/hold_arithmetic_test.php
//
// Checks the hold arithmetic in Backend/lib/account_holds.php: placing a
// hold leaves the balance alone and adds to the held amount; debit reduces
// both; release (and expiry) reduces the held amount only; a partial
// release captures the rest. CLI only, no database:
//     php tests/hold_arithmetic_test.php
// All figures are made up.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../Backend/lib/account_holds.php';

/** Run a sequence of steps from a starting [balance, held] and return "balance/held/available". */
function run(array $start, array $steps): string
{
    [$balance, $held] = $start;
    try {
        foreach ($steps as $step) {
            $op = array_shift($step);
            $delta = match ($op) {
                'place'          => hold_delta_place($balance, $held, ...$step),
                'debit'          => hold_delta_debit(...$step),
                'release'        => hold_delta_release(...$step),
                'legacy_debit'   => hold_delta_debit($step[0], false),
                'legacy_release' => hold_delta_release($step[0], $step[1] ?? 0, false),
            };
            $after   = hold_apply_delta($balance, $held, $delta);
            $balance = $after['balance'];
            $held    = $after['held_balance'];
        }
    } catch (Throwable $e) {
        return 'refused: ' . $e->getMessage();
    }
    return sprintf('%.2f/%.2f/%.2f', $balance, $held, hold_available($balance, $held));
}

$cases = [
    // name => [expected "balance/held/available", start, steps]
    'place leaves balance alone'            => ['1000.00/300.00/700.00', [1000, 0],   [['place', 300]]],
    'place the whole available amount'      => ['1000.00/1000.00/0.00',  [1000, 0],   [['place', 1000]]],
    'second hold stacks on the first'       => ['1000.00/450.00/550.00', [1000, 0],   [['place', 300], ['place', 150]]],
    'place then debit'                      => ['700.00/0.00/700.00',    [1000, 0],   [['place', 300], ['debit', 300]]],
    'place then release'                    => ['1000.00/0.00/1000.00',  [1000, 0],   [['place', 300], ['release', 300]]],
    'release with amount = hold is full'    => ['1000.00/0.00/1000.00',  [1000, 0],   [['place', 300], ['release', 300, 300]]],
    'release with amount > hold is full'    => ['1000.00/0.00/1000.00',  [1000, 0],   [['place', 300], ['release', 300, 999]]],
    'partial release captures the rest'     => ['800.00/0.00/800.00',    [1000, 0],   [['place', 300], ['release', 300, 100]]],
    'expiry is a full release'              => ['1000.00/0.00/1000.00',  [1000, 0],   [['place', 250], ['release', 250, 0]]],
    'two holds: debit one, release other'   => ['850.00/0.00/850.00',    [1000, 0],   [['place', 300], ['place', 150], ['release', 300], ['debit', 150]]],
    'debit leaves the other hold held'      => ['700.00/150.00/550.00',  [1000, 0],   [['place', 300], ['place', 150], ['debit', 300]]],
    'cents survive'                         => ['99.90/0.00/99.90',      [100.10, 0], [['place', 0.20], ['debit', 0.20]]],
    'four decimal places survive'           => ['10.00/0.00/10.00',      [10.0001, 0], [['place', 0.0001], ['debit', 0.0001]]],
    'legacy hold: debit moves nothing'      => ['700.00/0.00/700.00',    [700, 0],    [['legacy_debit', 300]]],
    'legacy hold: release gives it back'    => ['1000.00/0.00/1000.00',  [700, 0],    [['legacy_release', 300]]],
    'legacy hold: partial release'          => ['800.00/0.00/800.00',    [700, 0],    [['legacy_release', 300, 100]]],
    'legacy and new holds side by side'     => ['900.00/0.00/900.00',    [700, 0],    [['place', 100], ['legacy_release', 300], ['debit', 100]]],
    'cannot hold more than the balance'     => ['refused: Insufficient balance. Available: 1000, Requested: 1000.01', [1000, 0], [['place', 1000.01]]],
    'cannot hold money that is held'        => ['refused: Insufficient balance. Available: 700, Requested: 701',      [1000, 300], [['place', 701]]],
    'held money cannot be held twice'       => ['refused: Insufficient balance. Available: 0, Requested: 1',          [1000, 0], [['place', 1000], ['place', 1]]],
    'zero amount refused'                   => ['refused: Valid amount required', [1000, 0], [['place', 0]]],
    'negative amount refused'               => ['refused: Valid amount required', [1000, 0], [['place', -5]]],
];

$failed = 0;
foreach ($cases as $name => [$expected, $start, $steps]) {
    $got = run($start, $steps);
    $ok  = $got === $expected;
    $failed += $ok ? 0 : 1;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $ok ? '' : "  (expected {$expected}, got {$got})");
}

// The split a release reports back to the caller.
$splits = [
    'split: no amount = full'      => [['released' => 300.0, 'captured' => 0.0,   'is_partial' => false], hold_release_split(300)],
    'split: partial'               => [['released' => 100.0, 'captured' => 200.0, 'is_partial' => true],  hold_release_split(300, 100)],
    'split: amount = hold is full' => [['released' => 300.0, 'captured' => 0.0,   'is_partial' => false], hold_release_split(300, 300)],
    'split: string amounts'        => [['released' => 0.5,   'captured' => 0.25,  'is_partial' => true],  hold_release_split('0.7500', '0.5')],
];
foreach ($splits as $name => [$expected, $got]) {
    $ok = $got === $expected;
    $failed += $ok ? 0 : 1;
    printf("%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $ok ? '' : '  (got ' . json_encode($got) . ')');
}

$total = count($cases) + count($splits);
echo $failed === 0 ? "\nAll {$total} passed.\n" : "\n{$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
