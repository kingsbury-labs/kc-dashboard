<?php
require_once __DIR__ . '/../src/Process/ProcessRunner.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

const PR_PHP = 'C:\\xampp\\php\\php.exe';

function pr_temp(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcpr_' . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

function pr_refused(callable $fn, string $msg): void
{
    try {
        $fn();
    } catch (ProcessRefused $e) {
        return;
    }
    throw new RuntimeException('not refused: ' . $msg);
}

function pr_junction(string $link, string $target): void
{
    $proc = proc_open(
        ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command',
         'New-Item -ItemType Junction -Path $env:KCPR_LINK -Target $env:KCPR_TARGET | Out-Null'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        getenv() + ['KCPR_LINK' => $link, 'KCPR_TARGET' => $target]
    );
    assert_true(is_resource($proc), 'could not start powershell');
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    assert_true($code === 0 && @lstat($link) !== false, 'junction not created: ' . trim($err));
}

test('php.exe round-trips awkward arguments unchanged', function () {
    $runner = new ProcessRunner(['php' => PR_PHP]);
    $args = ['two words', 'say "hi"', 'a&b', 'a|b', '%PATH%', 'x^y', '..\\..', 'end\\', '"', ''];
    $r = $runner->run(array_merge([PR_PHP, '-r', 'echo json_encode(array_slice($argv, 1));', '--'], $args), null, 20);
    assert_same(0, $r['code'], 'exit code; stderr ' . $r['stderr']);
    assert_same($args, json_decode($r['stdout'], true));
});

test('a .bat in the allowlist is refused and never runs', function () {
    $dir = pr_temp();
    $bat = $dir . '\\evil.bat';
    $marker = $dir . '\\marker.txt';
    try {
        file_put_contents($bat, "@echo off\r\necho x > \"$marker\"\r\n");
        $runner = new ProcessRunner(['bat' => $bat]);
        pr_refused(fn() => $runner->run([$bat], null, 5), '.bat');
        usleep(300000);
        assert_same(false, file_exists($marker), 'marker written');
    } finally {
        @unlink($marker);
        @unlink($bat);
        @rmdir($dir);
    }
});

test('.cmd, relative, unlisted and disguised paths are refused', function () {
    $dir = pr_temp();
    $cmd = $dir . '\\x.cmd';
    try {
        file_put_contents($cmd, "@echo off\r\n");
        $list = ['cmd' => $cmd, 'php' => PR_PHP, 'rel' => 'php.exe', 'rel2' => 'xampp\\php\\php.exe',
            'dot' => $cmd . '.', 'space' => $cmd . ' ', 'ads' => $cmd . '::$DATA'];
        $runner = new ProcessRunner($list);
        foreach ([$cmd, 'php.exe', 'xampp\\php\\php.exe', $cmd . '.', $cmd . ' ', $cmd . '::$DATA',
                  'C:\\Windows\\System32\\whoami.exe', '\\\\?\\' . PR_PHP] as $path) {
            pr_refused(fn() => $runner->run([$path, '-v'], null, 5), $path);
        }
    } finally {
        @unlink($cmd);
        @rmdir($dir);
    }
});

test('an allowlisted .exe that does not exist is refused', function () {
    $missing = 'C:\\nope\\' . bin2hex(random_bytes(4)) . '.exe';
    $runner = new ProcessRunner(['m' => $missing]);
    pr_refused(fn() => $runner->run([$missing]), 'missing');
});

test('the extension check is case-insensitive', function () {
    $runner = new ProcessRunner(['php' => strtoupper(PR_PHP)]);
    $r = $runner->run([strtoupper(PR_PHP), '-r', 'echo "ok";'], null, 20);
    assert_same('ok', $r['stdout']);
});

test('a cwd swapped for a junction after validation is refused', function () {
    $root = pr_temp();
    $outside = pr_temp();
    try {
        mkdir($root . '\\swap');
        $runner = new ProcessRunner(['php' => PR_PHP], $root);
        $r = $runner->run([PR_PHP, '-r', 'echo getcwd();'], 'swap', 20);
        assert_same(strtolower(realpath($root) . '\\swap'), strtolower($r['stdout']), 'cwd before swap');
        // Swap from another process, as a second request would.
        $proc = proc_open(['cmd.exe', '/c', 'rmdir', $root . '\\swap'], [], $pipes);
        proc_close($proc);
        pr_junction($root . '\\swap', $outside);
        pr_refused(fn() => $runner->run([PR_PHP, '-r', 'echo getcwd();'], 'swap', 20), 'junction cwd');
        pr_refused(fn() => $runner->run([PR_PHP, '-v'], '..', 20), 'dot-dot cwd');
    } finally {
        @rmdir($root . '\\swap');
        @rmdir($outside);
        @rmdir($root);
    }
});

test('a sleeping child is killed within 5 s of a 1 s timeout', function () {
    $runner = new ProcessRunner(['php' => PR_PHP]);
    $start = microtime(true);
    $r = $runner->run([PR_PHP, '-r', 'sleep(30);'], null, 1);
    $took = microtime(true) - $start;
    assert_true($r['timedOut'], 'not marked timed out');
    assert_true($took < 5, 'took ' . round($took, 2) . ' s');
});

test('a child that never reads 1 MB of stdin still times out within 5 s', function () {
    $runner = new ProcessRunner(['php' => PR_PHP]);
    $start = microtime(true);
    $r = $runner->run([PR_PHP, '-r', 'sleep(30);'], null, 1, str_repeat('x', 1 << 20));
    $took = microtime(true) - $start;
    assert_true($r['timedOut'], 'not marked timed out');
    assert_true($took < 5, 'took ' . round($took, 2) . ' s');
});

test('stdin reaches the child', function () {
    $runner = new ProcessRunner(['php' => PR_PHP]);
    $r = $runner->run([PR_PHP, '-r', 'echo strlen(stream_get_contents(STDIN));'], null, 20, str_repeat('y', 5000));
    assert_same('5000', $r['stdout']);
});

test('the child sees only allowlisted environment variables', function () {
    putenv('KCPR_NOT_ALLOWED=1');
    try {
        $runner = new ProcessRunner(['php' => PR_PHP]);
        $r = $runner->run([PR_PHP, '-r', 'echo json_encode(array_keys(getenv()));'], null, 20);
        $names = json_decode($r['stdout'], true);
        assert_true(is_array($names) && $names !== [], 'no env listing: ' . $r['stderr']);
        $allowed = array_map('strtolower', ProcessRunner::DEFAULT_ENV);
        foreach ($names as $name) {
            assert_true(in_array(strtolower($name), $allowed, true), 'unexpected variable ' . $name);
        }
    } finally {
        putenv('KCPR_NOT_ALLOWED');
    }
});

test('the fake records calls and starts nothing', function () {
    $fake = new FakeProcessRunner();
    $fake->willReturn(3, 'out');
    $r = $fake->run(['C:\\x.bat', 'a'], 'proj', 9);
    assert_same(3, $r['code']);
    assert_same('out', $r['stdout']);
    assert_same([['argv' => ['C:\\x.bat', 'a'], 'cwd' => 'proj', 'timeout' => 9, 'stdin' => null]], $fake->calls);
});
