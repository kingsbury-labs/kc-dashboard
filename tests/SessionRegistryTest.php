<?php
require_once __DIR__ . '/../src/Running/SessionRegistry.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

const SR_PS = 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
const SR_KEY_MARKER = 'KEY-FILE-MARKER-74';

// A temp copy of the session fixtures plus a `.key` file, so the key never exists in the repo.
function sr_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcsr_' . bin2hex(random_bytes(6));
    mkdir($dir);
    foreach (glob(__DIR__ . '/fixtures/running/sessions/*.json') as $f) {
        copy($f, $dir . '\\' . basename($f));
    }
    file_put_contents($dir . '\\5555.' . str_repeat('ab', 32) . '.key', SR_KEY_MARKER);
    return $dir;
}

function sr_read(?FakeProcessRunner &$runner = null, ?SessionRegistry &$reg = null): array
{
    $runner = new FakeProcessRunner();
    $runner->willReturn(0, (string) file_get_contents(__DIR__ . '/fixtures/running/powershell-starts.txt'));
    $reg = new SessionRegistry(sr_dir(), $runner, SR_PS,
        // `alp` first: a folder-name prefix of alpha must not claim alpha's sessions.
        ['alp' => 'D:\\Dev\\htdocs\\alp', 'alpha' => 'D:\\Dev\\htdocs\\alpha', 'beta' => 'D:\\Dev\\htdocs\\beta'],
        ['C:\\xampp\\htdocs' => 'D:\\Dev\\htdocs']);
    return $reg->read();
}

function sr_by_pid(array $sessions, int $pid): ?array
{
    foreach ($sessions as $s) {
        if ($s['pid'] === $pid) {
            return $s;
        }
    }
    return null;
}

test('a live session gives its project slug and status busy', function () {
    $s = sr_by_pid(sr_read(), 1111);
    assert_true($s !== null, 'live session missing');
    assert_same('alpha', $s['project']);
    assert_same('busy', $s['status']);
    assert_same('alpha card', $s['name']);
    assert_same(1760000000000, $s['startedAt']);
});

test('a session started under C:\\xampp\\htdocs maps through the alias and matches on procStartFt', function () {
    $s = sr_by_pid(sr_read(), 6666);
    assert_true($s !== null, 'FILETIME session missing');
    assert_same('beta', $s['project']);
    assert_same('waiting', $s['status']);
    assert_same('permission', $s['waitingFor']);
});

test('a dead PID is skipped', function () {
    assert_same(null, sr_by_pid(sr_read(), 2222));
});

test('a reused PID (procStart mismatch) is skipped', function () {
    assert_same(null, sr_by_pid(sr_read(), 3333));
});

test('the .key file is never opened and its marker is absent from the output', function () {
    $out = sr_read($runner, $reg);
    assert_true($reg->opened !== [], 'nothing opened');
    foreach ($reg->opened as $path) {
        assert_true(preg_match('/\\\\\d+\.json$/', $path) === 1, 'opened ' . $path);
        assert_true(stripos($path, '.key') === false, 'opened a key file: ' . $path);
    }
    assert_true(!str_contains(json_encode($out), SR_KEY_MARKER), 'key marker in output');
});

test('unknown fields are dropped', function () {
    $out = sr_read();
    $keys = ['pid', 'cwd', 'project', 'status', 'waitingFor', 'name', 'startedAt', 'updatedAt'];
    foreach ($out as $s) {
        assert_same($keys, array_keys($s));
    }
    assert_true(!str_contains(json_encode($out), 'UNKNOWN-FIELD-MARKER-74'), 'unknown field leaked');
    assert_true(!str_contains(json_encode($out), 'aaaaaaaa-0000'), 'sessionId leaked');
});

test('the dead and reused fixtures parse: alive at their own start, they are listed', function () {
    $runner = new FakeProcessRunner();
    $runner->willReturn(0, "2222,638950000000000000,1\r\n3333,638950000000000000,1\r\n");
    $out = (new SessionRegistry(sr_dir(), $runner, SR_PS, []))->read();
    assert_same([2222, 3333], array_column($out, 'pid'));
    assert_same([null, null], array_column($out, 'project'));
});

test('malformed JSON is skipped without an exception', function () {
    $out = sr_read();
    assert_same(null, sr_by_pid($out, 4444));
    assert_same([1111, 6666], array_column($out, 'pid'));
});

test('one process lookup per read, with a fixed command and no PID in argv', function () {
    sr_read($runner);
    assert_same(1, count($runner->calls));
    $argv = $runner->calls[0]['argv'];
    assert_same(SR_PS, $argv[0]);
    assert_true(!str_contains(implode(' ', $argv), '1111'), 'a PID reached argv');
});

test('a failing process lookup throws instead of reporting everyone dead', function () {
    $runner = new FakeProcessRunner();
    $runner->willReturn(1, '', 'boom');
    $reg = new SessionRegistry(sr_dir(), $runner, SR_PS, []);
    $threw = false;
    try {
        $reg->read();
    } catch (RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'no exception');
});
