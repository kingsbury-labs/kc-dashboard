<?php
function runner_child(string $case): array
{
    $cmd = [PHP_BINARY, __DIR__ . '/run.php', '--dir', __DIR__ . '/fixtures/runner/' . $case];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out];
}

test('runner fails on a file with a syntax error', function () {
    [$code, $out] = runner_child('syntax');
    assert_true($code !== 0, 'exit code should be non-zero');
    assert_true(str_contains($out, 'FAIL BrokenTest'), 'FAIL line names the file: ' . $out);
});

test('runner fails on a file that throws at load', function () {
    [$code, $out] = runner_child('throws');
    assert_true($code !== 0, 'exit code should be non-zero');
    assert_true(str_contains($out, 'FAIL ThrowsTest'), 'FAIL line names the file: ' . $out);
});

test('runner fails on a file that defines no tests', function () {
    [$code, $out] = runner_child('empty');
    assert_true($code !== 0, 'exit code should be non-zero');
    assert_true(str_contains($out, 'FAIL EmptyTest'), 'FAIL line names the file: ' . $out);
});

test('runner fails on a file with a failing test', function () {
    [$code, $out] = runner_child('failing');
    assert_true($code !== 0, 'exit code should be non-zero');
    assert_true(str_contains($out, 'FAIL FailingTest'), 'FAIL line: ' . $out);
});

test('runner passes a clean file', function () {
    [$code, $out] = runner_child('clean');
    assert_same(0, $code, $out);
    assert_true(str_contains($out, 'Tests: 1 passed, 0 failed'), $out);
});
