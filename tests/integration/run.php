<?php
/**
 * tests/integration/run.php — integration tests against a disposable sandbox install.
 * Usage: EB_TEST_DB_HOST=127.0.0.1 EB_TEST_DB_PORT=33306 EB_TEST_DB_NAME=ebtest EB_TEST_DB_USER=eb EB_TEST_DB_PASS=… \
 *        php tests/integration/run.php [filter]
 * Synthetic data only. Never point EB_TEST_DB_* at a real database (it is dropped).
 */
declare(strict_types=1);
error_reporting(E_ALL);
require __DIR__ . '/Sandbox.php';

$GLOBALS['__it'] = [];
function it(string $name, callable $fn): void { $GLOBALS['__it'][] = [$name, $fn]; }
final class ItFail extends Exception {}
function eq($e, $a, string $m = ''): void { if ($e !== $a) throw new ItFail(($m ? "$m: " : '') . 'expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function ok($c, string $m = 'expected true'): void { if (!$c) throw new ItFail($m); }

try { $SB = new Sandbox(dirname(__DIR__, 2)); }
catch (RuntimeException $e) { echo "SKIPPED: " . $e->getMessage() . "\n"; exit(0); }
$GLOBALS['SB'] = $SB;

foreach (glob(__DIR__ . '/*IT.php') as $f) require $f;

$filter = $argv[1] ?? ''; $pass = $fail = 0;
foreach ($GLOBALS['__it'] as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) continue;
    try { $fn($SB); $pass++; echo "  ok    $name\n"; }
    catch (Throwable $e) { $fail++; echo "  FAIL  $name\n        " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
if ($fail) { echo "\n--- server log tail ---\n" . substr($SB->serverLog(), -3000) . "\n"; }
echo "\n$pass passed, $fail failed  (sandbox: synthetic data, throwaway DB)\n";
exit($fail ? 1 : 0);
