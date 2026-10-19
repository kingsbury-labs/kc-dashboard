<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Jobs/Queue.php';

const BINCLI_CGI = 'C:\\xampp\\php\\php-cgi.exe';

function bincli_copy_tree(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        is_dir($from . '/' . $f) ? bincli_copy_tree($from . '/' . $f, $to . '/' . $f) : copy($from . '/' . $f, $to . '/' . $f);
    }
}

function bincli_rmtree(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir($dir . '/' . $f) ? bincli_rmtree($dir . '/' . $f) : @unlink($dir . '/' . $f);
        }
    }
    @rmdir($dir);
}

// A copy of the app in a temp folder, so a script that wrongly runs under CGI touches only the copy:
// its default database holds one queued probe job, and its tests folder one test that leaves a marker.
function bincli_world(): array
{
    $app = dirname(__DIR__);
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcbin_' . bin2hex(random_bytes(6));
    mkdir($t);
    bincli_copy_tree($app . '/src', $t . '/src');
    bincli_copy_tree($app . '/config', $t . '/config');
    mkdir($t . '/bin');
    foreach (glob($app . '/bin/*.php') as $f) {
        copy($f, $t . '/bin/' . basename($f));
    }
    mkdir($t . '/tests');
    copy($app . '/tests/run.php', $t . '/tests/run.php');
    file_put_contents($t . '/tests/ProbeTest.php',
        "<?php\nfile_put_contents(__DIR__ . '/../data/ran.txt', 'ran');\ntest('probe', function () {});\n");
    mkdir($t . '/data');
    $pdo = Db::open($t . '/data/dashboard.sqlite');
    Db::migrate($pdo);
    $job = (new Queue($pdo))->enqueue('kc.bincli.probe');
    $pdo = null;
    return [$t, $job];
}

function bincli_cgi(string $script): array
{
    $proc = proc_open([BINCLI_CGI, '-q', str_replace('/', '\\', $script)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert_true(is_resource($proc), 'could not start php-cgi');
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), $out . $err];
}

test('every bin script and tests/run.php refuse to run under php-cgi', function () {
    [$t, $job] = bincli_world();
    try {
        $scripts = array_map(fn($f) => 'bin/' . basename($f), glob(dirname(__DIR__) . '/bin/*.php'));
        assert_true(in_array('bin/worker.php', $scripts, true), 'worker found');
        $scripts[] = 'tests/run.php';
        foreach ($scripts as $s) {
            [$code, $out] = bincli_cgi($t . '/' . $s);
            assert_true($code !== 0, $s . ' exited 0');
            assert_same('', $out, $s . ' output');
            $pdo = Db::open($t . '/data/dashboard.sqlite');
            $row = (new Queue($pdo))->get($job);
            $pdo = null;
            assert_same('queued', $row['status'], $s . ' claimed the probe job');
            assert_same(0, (int) $row['attempts'], $s . ' attempts');
            assert_true(!file_exists($t . '/data/ran.txt'), $s . ' loaded a test file');
        }
    } finally {
        gc_collect_cycles();
        bincli_rmtree($t);
    }
});

test('the same copies do run from the CLI, so the CGI refusal is the guard', function () {
    [$t, $job] = bincli_world();
    try {
        $proc = proc_open([PHP_BINARY, $t . '/bin/worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        assert_same(0, proc_close($proc), 'worker exit');
        $pdo = Db::open($t . '/data/dashboard.sqlite');
        assert_same('failed', (new Queue($pdo))->get($job)['status'], 'unknown probe job failed, so it was claimed');
        $pdo = null;
    } finally {
        gc_collect_cycles();
        bincli_rmtree($t);
    }
});
