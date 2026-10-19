<?php
require_once __DIR__ . '/../src/Running/NowService.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

const NA_FIX = __DIR__ . '/fixtures/running';
const NA_TOKEN = 'fake-now-token-0123456789abcdef0123456789abcdef0123456789abcd';

// Answers each program from its fixture by the executable's basename and records every call. Starts nothing.
class NowFakeRunner extends ProcessRunner
{
    /** @var array<int,array> */
    public array $calls = [];

    public function __construct()
    {
        parent::__construct([]);
    }

    public function run(array $argv, ?string $cwdName = null, int $timeoutSeconds = 60, ?string $stdin = null): array
    {
        $this->calls[] = $argv;
        $file = ['powershell.exe' => 'powershell-starts.txt', 'netstat.exe' => 'netstat.txt',
            'tasklist.exe' => 'tasklist.csv', 'schtasks.exe' => 'schtasks.csv'][basename($argv[0])] ?? null;
        return ['code' => $file === null ? 1 : 0, 'stdout' => $file === null ? '' : (string) file_get_contents(NA_FIX . '/' . $file),
            'stderr' => '', 'timedOut' => false];
    }
}

function na_temp(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcna_' . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

// A service over the fixtures, one project with a live node loop (PID 9100), and a clock the test moves.
function na_service(NowFakeRunner $runner, int &$clock, ?string &$cache = null): NowService
{
    $tmp = na_temp();
    $cache = $tmp . '\\now-cache.json';
    $project = $tmp . '\\alpha';
    mkdir($project . '\\.claude\\work\\loop', 0777, true);
    file_put_contents($project . '\\.claude\\work\\loop\\lock', "9100\n");
    file_put_contents($project . '\\.claude\\work\\loop\\status.json', '{"card":"74","note":"building"}');
    mkdir($tmp . '\\sessions');
    copy(NA_FIX . '/sessions/1111.json', $tmp . '\\sessions\\1111.json');
    $projects = ['alpha' => $project];
    $exe = fn(string $n) => 'C:\\Windows\\System32\\' . $n . '.exe';
    return new NowService(
        // The session fixture's cwd is under D:\Dev\htdocs\alpha; the loop's project lives in the temp folder.
        new SessionRegistry($tmp . '\\sessions', $runner, $exe('powershell'), ['alpha' => 'D:\\Dev\\htdocs\\alpha']),
        new LoopState($runner, $exe('tasklist')),
        new Tasks($runner, $exe('schtasks'), '\\KC Dashboard\\'),
        new Ports($runner, $exe('netstat'), $exe('tasklist')),
        $projects, $cache, 3, function () use (&$clock): int {
            return $clock;
        });
}

// A same-origin fetch from the dashboard page, with the token.
function na_server(string $host): array
{
    return ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => $host, 'REQUEST_METHOD' => 'GET',
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => NA_TOKEN];
}

function na_token(): Closure
{
    return fn(): string => NA_TOKEN;
}

test('foreign Host is refused, nothing is scanned and the token is never read', function () {
    $runner = new NowFakeRunner();
    $clock = 1000;
    $read = false;
    [$status, , $body] = na_service($runner, $clock)->handle(na_server('evil.com'), function () use (&$read): string {
        $read = true;
        return NA_TOKEN;
    });
    assert_same(403, $status);
    assert_same([], $runner->calls);
    assert_true(!$read, 'token read for a refused request');
    assert_true(!str_contains($body, 'alpha'), 'data leaked');
});

test('a navigation, a cross-site request, no token and a wrong token each start no process', function () {
    $ok = na_server('127.0.0.1:8787');
    $cases = [
        'navigate' => ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate'] + $ok,
        'same-origin navigate' => ['HTTP_SEC_FETCH_MODE' => 'navigate'] + $ok,
        'cross-site cors' => ['HTTP_SEC_FETCH_SITE' => 'cross-site'] + $ok,
        'same-site cors' => ['HTTP_SEC_FETCH_SITE' => 'same-site'] + $ok,
        'wrong token' => [RequestGuard::TOKEN_HEADER => str_repeat('0', 64)] + $ok,
        'POST' => ['REQUEST_METHOD' => 'POST'] + $ok,
    ];
    $none = $ok;
    unset($none[RequestGuard::TOKEN_HEADER]);
    $cases['no token'] = $none;
    $noMode = $ok;
    unset($noMode['HTTP_SEC_FETCH_MODE']);
    $cases['site with no mode'] = $noMode;
    foreach ($cases as $name => $server) {
        $runner = new NowFakeRunner();
        $clock = 1000;
        [$status] = na_service($runner, $clock)->handle($server, na_token());
        assert_same(403, $status, $name);
        assert_same([], $runner->calls, $name);
    }
});

test('a same-origin request with the token scans', function () {
    $runner = new NowFakeRunner();
    $clock = 1000;
    [$status] = na_service($runner, $clock)->handle(na_server('127.0.0.1:8787'), na_token());
    assert_same(200, $status);
    assert_true($runner->calls !== [], 'nothing scanned');
});

test('a scan that outlasts the window is stamped when it ends, so the next request is served from cache', function () {
    $runner = new class extends NowFakeRunner {
        public $tick;
        public function run(array $argv, ?string $cwdName = null, int $timeoutSeconds = 60, ?string $stdin = null): array
        {
            ($this->tick)();
            return parent::run($argv, $cwdName, $timeoutSeconds, $stdin);
        }
    };
    $clock = 1000;
    $runner->tick = function () use (&$clock): void {
        $clock += 2;
    };
    $svc = na_service($runner, $clock);
    $data = json_decode($svc->handle(na_server('127.0.0.1:8787'), na_token())[2], true);
    assert_true($clock - 1000 >= 3, 'the fake scan did not outlast the window');
    assert_same($clock, $data['at']);
    $calls = count($runner->calls);
    $svc->handle(na_server('127.0.0.1:8787'), na_token());
    assert_same($calls, count($runner->calls), 'rescanned right after a long scan');
});

test('while another request holds the scan lock, a request gets the last snapshot without scanning', function () {
    $runner = new NowFakeRunner();
    $clock = 1000;
    $svc = na_service($runner, $clock, $cache);
    $held = fopen($cache . '.lock', 'c');
    assert_true(flock($held, LOCK_EX | LOCK_NB), 'test could not take the lock');
    try {
        $empty = json_decode($svc->handle(na_server('127.0.0.1:8787'), na_token())[2], true);
        assert_same([], $runner->calls, 'scanned while locked');
        assert_same(true, $empty['stale']);
        assert_same([[], [], [], []], [$empty['sessions'], $empty['loops'], $empty['tasks'], $empty['ports']]);
        file_put_contents($cache, json_encode(['sessions' => [], 'loops' => [], 'tasks' => [['name' => 'last']], 'ports' => [],
            'at' => 999, 'errors' => [], 'stale' => false]));
        $last = json_decode($svc->handle(na_server('127.0.0.1:8787'), na_token())[2], true);
        assert_same([], $runner->calls, 'scanned while locked');
        assert_same('last', $last['tasks'][0]['name']);
    } finally {
        flock($held, LOCK_UN);
        fclose($held);
    }
});

test('a TaskName in another field and a newline inside a quoted field forge no task', function () {
    $runner = new FakeProcessRunner();
    $runner->willReturn(0, (string) file_get_contents(NA_FIX . '/schtasks-forged.csv'));
    $tasks = (new Tasks($runner, 'C:\\Windows\\System32\\schtasks.exe', '\\KC Dashboard\\'))->read();
    assert_same(['collect-projects', 'daily-read'], array_column($tasks, 'name'));
    assert_same(['Ready', 'Ready'], array_column($tasks, 'status'));
    assert_same([false, true], array_column($tasks, 'failed'));
    assert_true(!str_contains(json_encode($tasks), 'CMDLINE-MARKER-74'), 'forged row leaked');
});

test('the four keys are present with the fixture data', function () {
    $runner = new NowFakeRunner();
    $clock = 1000;
    [$status, $headers, $body] = na_service($runner, $clock)->handle(na_server('127.0.0.1:8787'), na_token());
    assert_same(200, $status);
    assert_same('application/json', $headers['Content-Type']);
    $data = json_decode($body, true);
    foreach (['sessions', 'loops', 'tasks', 'ports', 'at'] as $k) {
        assert_true(array_key_exists($k, $data), 'missing ' . $k);
    }
    assert_same([], $data['errors']);
    assert_same(1000, $data['at']);
    assert_same('alpha', $data['sessions'][0]['project']);
    assert_same([['project' => 'alpha', 'state' => 'running', 'card' => '74', 'note' => 'building']], $data['loops']);
    assert_same(['collect-projects', 'daily-read'], array_column($data['tasks'], 'name'));
    assert_same([false, true], array_column($data['tasks'], 'failed'));
    assert_same([
        ['port' => 135, 'program' => 'svchost.exe', 'pid' => 1200],
        ['port' => 5173, 'program' => 'node.exe', 'pid' => 7001],
        ['port' => 8787, 'program' => 'httpd.exe', 'pid' => 7002],
    ], $data['ports']);
});

test('a command-line marker in the fixtures never appears in the response', function () {
    $runner = new NowFakeRunner();
    $clock = 1000;
    [, , $body] = na_service($runner, $clock)->handle(na_server('localhost:8787'), na_token());
    assert_true(str_contains((string) file_get_contents(NA_FIX . '/schtasks.csv'), 'CMDLINE-MARKER-74'), 'fixture lost its marker');
    assert_true(!str_contains($body, 'CMDLINE-MARKER-74'), 'command line leaked');
    assert_true(!str_contains($body, 'php.exe'), 'Task To Run leaked');
});

test('two calls within 3 seconds on a fake clock run the scan once', function () {
    $runner = new NowFakeRunner();
    $clock = 1000;
    $svc = na_service($runner, $clock);
    $first = $svc->handle(na_server('127.0.0.1:8787'), na_token());
    $calls = count($runner->calls);
    assert_true($calls > 0, 'first call scanned nothing');
    $clock = 1002;
    $second = $svc->handle(na_server('127.0.0.1:8787'), na_token());
    assert_same($calls, count($runner->calls), 'second call rescanned');
    assert_same($first[2], $second[2]);
    $clock = 1003;
    $svc->handle(na_server('127.0.0.1:8787'), na_token());
    assert_true(count($runner->calls) > $calls, 'a stale cache was served');
});

test('a failing source is named in errors and the others still come back', function () {
    $runner = new class extends NowFakeRunner {
        public function run(array $argv, ?string $cwdName = null, int $timeoutSeconds = 60, ?string $stdin = null): array
        {
            if (basename($argv[0]) === 'schtasks.exe') {
                return ['code' => 1, 'stdout' => '', 'stderr' => 'x', 'timedOut' => false];
            }
            return parent::run($argv, $cwdName, $timeoutSeconds, $stdin);
        }
    };
    $clock = 1000;
    $data = json_decode(na_service($runner, $clock)->handle(na_server('127.0.0.1:8787'), na_token())[2], true);
    assert_same(['tasks'], $data['errors']);
    assert_same([], $data['tasks']);
    assert_same(3, count($data['ports']));
});
