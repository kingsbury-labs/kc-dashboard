<?php
// Plain test runner. Test files call test('name', fn) and the assert_* helpers.
// Output contract: one "FAIL <name>" line per failure, last line "Tests: N passed, M failed".

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$name, $fn, $GLOBALS['__file'] ?? ''];
}

function assert_true($cond, string $msg = 'expected true'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function assert_same($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg !== '' ? $msg . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$filter = '';
$dir = __DIR__;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--filter' && isset($argv[$i + 1])) {
        $filter = $argv[++$i];
    } elseif ($argv[$i] === '--dir' && isset($argv[$i + 1])) {
        $dir = $argv[++$i];
    }
}

require_once __DIR__ . '/../src/bootstrap.php';

$files = glob(rtrim($dir, '/' . chr(92)) . '/*Test.php') ?: [];
sort($files);
foreach ($files as $file) {
    $GLOBALS['__file'] = basename($file, '.php');
    require $file;
}

$passed = 0;
$failed = 0;
foreach ($GLOBALS['__tests'] as [$name, $fn, $file]) {
    $label = $file . ': ' . $name;
    if ($filter !== '' && stripos($label, $filter) === false) {
        continue;
    }
    try {
        $fn();
        $passed++;
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL ' . $label . "\n    " . str_replace("\n", "\n    ", $e->getMessage()) . "\n";
    }
}

echo "Tests: $passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
