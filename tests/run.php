<?php
/**
 * tests/run.php — dependency-free test runner (dev only; never deployed).
 * Usage (from repo root):  php tests/run.php [filter]
 * Each tests/*Test.php registers cases with test('name', fn() => ...).
 * Tests must not touch a real database, network or provider.
 */
declare(strict_types=1);
error_reporting(E_ALL);
define('EB_TEST', true);
define('EB_ROOT_DIR', dirname(__DIR__));

$GLOBALS['__tests'] = [];
function test(string $name, callable $fn): void { $GLOBALS['__tests'][] = [$name, $fn]; }
final class AssertFail extends Exception {}
function eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) throw new AssertFail(($msg ? "$msg: " : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function ok($cond, string $msg = 'expected true'): void { if (!$cond) throw new AssertFail($msg); }

foreach (glob(__DIR__ . '/*Test.php') as $f) require $f;

$filter = $argv[1] ?? '';
$pass = $fail = 0;
foreach ($GLOBALS['__tests'] as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) continue;
    try { $fn(); $pass++; echo "  ok    $name\n"; }
    catch (Throwable $e) { $fail++; echo "  FAIL  $name\n        " . get_class($e) . ': ' . $e->getMessage() . "\n"; }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
