<?php
// Plain test runner. Test files call test('name', fn) and the assert_* helpers.
// Output contract: one "FAIL <name>" line per failure, last line "Tests: N passed, M failed".

// Only ever from the command line: under a web server this file must do nothing at all.
if (PHP_SAPI !== 'cli') {
    exit(1);
}

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
$passed = 0;
$failed = 0;
$loading = null;
register_shutdown_function(function () use (&$loading, &$passed, &$failed) {
    if ($loading !== null) {
        echo 'FAIL ' . $loading . "\n    fatal error while loading\n";
        echo 'Tests: ' . $passed . ' passed, ' . ($failed + 1) . " failed\n";
        exit(1);
    }
});
foreach ($files as $file) {
    $GLOBALS['__file'] = basename($file, '.php');
    $loading = $GLOBALS['__file'];
    $before = count($GLOBALS['__tests']);
    try {
        require $file;
        if (count($GLOBALS['__tests']) === $before) {
            $failed++;
            echo 'FAIL ' . $GLOBALS['__file'] . "\n    file registers no tests\n";
        }
    } catch (Throwable $e) {
        $failed++;
        echo 'FAIL ' . $GLOBALS['__file'] . "\n    load error: "
            . str_replace("\n", "\n    ", $e->getMessage()) . "\n";
    }
    $loading = null;
}

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
