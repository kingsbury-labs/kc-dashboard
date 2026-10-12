<?php
function run_runner(string $filter = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/run.php')
        . ' --dir ' . escapeshellarg(__DIR__ . '/fixtures/runner')
        . ($filter !== '' ? ' --filter ' . escapeshellarg($filter) : '');
    exec($cmd . ' 2>&1', $out, $code);
    return [$out, $code];
}

test('fixture dir: exit 1, one FAIL line, summary', function () {
    [$out, $code] = run_runner();
    assert_same(1, $code);
    $fails = array_values(array_filter($out, fn($l) => preg_match('/^FAIL\s/', $l)));
    assert_same(1, count($fails));
    assert_true(strpos($fails[0], 'fails on purpose') !== false, 'FAIL line names the test');
    assert_same('Tests: 1 passed, 1 failed', end($out));
});

test('filter Passing exits 0', function () {
    [$out, $code] = run_runner('Passing');
    assert_same(0, $code);
    assert_same('Tests: 1 passed, 0 failed', end($out));
});
