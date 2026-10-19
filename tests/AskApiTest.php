<?php
require_once __DIR__ . '/../src/Jobs/AskHandler.php';
require_once __DIR__ . '/../src/Jobs/Registry.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

define('ASK_TOKEN', str_repeat('a5', 32));
const ASK_GIT = 'C:\\fake\\git.exe';

function ask_mklink(string $link, string $target): void
{
    $proc = proc_open('mklink /J "' . $link . '" "' . $target . '"', [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    assert_true(is_resource($proc), 'could not start cmd');
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    assert_true($code === 0 && @lstat($link) !== false, 'junction not created: ' . trim($err));
}

function ask_server(array $over = []): array
{
    return $over + [
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost:8787',
        'REQUEST_METHOD' => 'POST',
        RequestGuard::TOKEN_HEADER => ASK_TOKEN,
        'HTTP_ORIGIN' => 'http://localhost:8787',
    ];
}

// A temp world: projects root with alpha/, an extra fin/ that is busy when scripts/ is dirty, a home folder,
// a token file, a database and a fake that stands in for both git and claude.
function ask_world(): array
{
    [$db, $pdo] = queue_db();
    $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcask_' . bin2hex(random_bytes(6));
    foreach (['', '\\root', '\\root\\alpha', '\\extra', '\\extra\\fin', '\\home', '\\outside'] as $d) {
        mkdir($base . $d);
    }
    file_put_contents($base . '\\token', ASK_TOKEN);
    $fake = new FakeProcessRunner();
    $made = [];
    $claude = new ClaudeRunner(function (?string $root, array $env) use ($fake, &$made): ProcessRunner {
        $made[] = $root;
        return $fake;
    }, 'C:\\fake\\claude.exe');
    $log = new Log($base . '\\app.log');
    return [
        'db' => $db, 'pdo' => $pdo, 'base' => $base, 'fake' => $fake, 'log' => $log,
        'projects' => new ProjectList($base . '\\root',
            [['path' => $base . '\\extra\\fin', 'busy_if_dirty' => 'scripts/']], $base . '\\home', $log),
        'handler' => new AskHandler($pdo, $claude, $fake, ASK_GIT),
    ];
}

function ask_cleanup(array &$w): void
{
    $db = $w['db'];
    $base = $w['base'];
    $w = [];
    gc_collect_cycles();
    queue_cleanup($db);
    @rmdir($base . '\\root\\alpha');
    foreach (['\\root', '\\extra\\fin', '\\extra', '\\home', '\\outside'] as $d) {
        @rmdir($base . $d);
    }
    @unlink($base . '\\token');
    @unlink($base . '\\app.log');
    @rmdir($base);
}

function ask_post(array $w, array $body, array $server = []): array
{
    return AskApi::ask(ask_server($server), json_encode($body), $w['base'] . '\\token', $w['log'], $w['pdo'], $w['projects']);
}

// A same-origin fetch of answers.php from the page, with the token unless $over removes it.
function ask_get_server(array $over = []): array
{
    return array_filter($over + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost:8787', 'REQUEST_METHOD' => 'GET',
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => ASK_TOKEN],
        fn($v) => $v !== null);
}

function ask_answers(array $w, array $over = [], ?bool &$opened = null): array
{
    $opened = false;
    return AskApi::answersApi(ask_get_server($over), $w['base'] . '\\token', $w['log'], function () use ($w, &$opened): PDO {
        $opened = true;
        return $w['pdo'];
    });
}

function ask_jobs(array $w): int
{
    return (int) $w['pdo']->query('SELECT COUNT(*) FROM jobs')->fetchColumn();
}

function ask_row(array $w, int $id): ?array
{
    $s = $w['pdo']->prepare('SELECT * FROM asks WHERE id = ?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ask_claude_json(string $result): string
{
    return json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => $result,
        'usage' => ['output_tokens' => 7]]);
}

test('every guard rejection and an unknown project create zero jobs', function () {
    $w = ask_world();
    try {
        ask_mklink($w['base'] . '\\root\\linked', $w['base'] . '\\outside');
        $rejected = [
            'remote addr' => ['REMOTE_ADDR' => '192.168.1.5'],
            'foreign host' => ['HTTP_HOST' => 'evil.com', 'HTTP_ORIGIN' => 'http://evil.com'],
            'GET' => ['REQUEST_METHOD' => 'GET'],
            'wrong token' => [RequestGuard::TOKEN_HEADER => 'fake-wrong'],
            'empty token' => [RequestGuard::TOKEN_HEADER => ''],
            'foreign origin' => ['HTTP_ORIGIN' => 'http://localhost:8787.evil.com'],
            'null origin' => ['HTTP_ORIGIN' => 'null'],
        ];
        foreach ($rejected as $name => $over) {
            [$status] = ask_post($w, ['question' => 'hi', 'project' => 'alpha'], $over);
            assert_same(403, $status, $name);
        }
        $noToken = ask_server();
        unset($noToken[RequestGuard::TOKEN_HEADER]);
        $noOrigin = ask_server();
        unset($noOrigin['HTTP_ORIGIN']);
        foreach (['no token' => $noToken, 'no origin' => $noOrigin] as $name => $server) {
            [$status] = AskApi::ask($server, '{"question":"hi"}', $w['base'] . '\\token', $w['log'], $w['pdo'], $w['projects']);
            assert_same(403, $status, $name);
        }
        foreach (['nope', '..', 'alpha/..', 'C:\\Windows', $w['base'] . '\\root\\alpha', 'linked', '.credentials'] as $p) {
            [$status, , $body] = ask_post($w, ['question' => 'hi', 'project' => $p]);
            assert_same(400, $status, 'project ' . $p);
            assert_true(str_contains($body, 'unknown project'), 'body for ' . $p);
        }
        foreach ([['project' => 'alpha'], ['question' => '  ', 'project' => 'alpha'], ['question' => ['x']],
                  ['question' => 'hi', 'project' => ['alpha']], ['question' => str_repeat('x', 8001)]] as $bad) {
            [$status] = ask_post($w, $bad);
            assert_same(400, $status, json_encode($bad));
        }
        assert_same(0, ask_jobs($w), 'jobs created');
        assert_same(0, (int) $w['pdo']->query('SELECT COUNT(*) FROM asks')->fetchColumn(), 'asks created');
    } finally {
        @rmdir($w['base'] . '\\root\\linked');
        ask_cleanup($w);
    }
});

test('a valid request creates one job and the handler stores the fake answer', function () {
    $w = ask_world();
    try {
        [$status, , $body] = ask_post($w, ['question' => 'what changed today?', 'project' => 'alpha']);
        assert_same(202, $status, $body);
        assert_same(1, ask_jobs($w));
        $id = json_decode($body, true)['id'];
        $job = (new Queue($w['pdo']))->claim();
        assert_same('ask', $job['type']);
        assert_same(['ask_id' => $id], $job['payload']);
        $w['fake']->willReturn(0, ask_claude_json('nothing much'));
        $w['handler']->handle($job['payload']);
        $row = ask_row($w, $id);
        assert_same('done', $row['status']);
        assert_same('nothing much', $row['answer']);
        assert_same(['output_tokens' => 7], json_decode($row['usage'], true));
        $call = $w['fake']->calls[0];
        assert_same('alpha', $call['cwd']);
        assert_same('what changed today?', $call['stdin']);
    } finally {
        ask_cleanup($w);
    }
});

test('a blank project runs in the home folder', function () {
    $w = ask_world();
    try {
        [$status, , $body] = ask_post($w, ['question' => 'hello', 'project' => '']);
        assert_same(202, $status, $body);
        $w['fake']->willReturn(0, ask_claude_json('hi'));
        $w['handler']->handle(['ask_id' => json_decode($body, true)['id']]);
        assert_same('home', $w['fake']->calls[0]['cwd']);
    } finally {
        ask_cleanup($w);
    }
});

test('a 21st answer drops the oldest', function () {
    $w = ask_world();
    try {
        $ids = [];
        for ($i = 1; $i <= 21; $i++) {
            [, , $body] = ask_post($w, ['question' => 'q' . $i, 'project' => 'alpha']);
            $ids[] = json_decode($body, true)['id'];
            $w['fake']->willReturn(0, ask_claude_json('a' . $i));
            $w['handler']->handle(['ask_id' => end($ids)]);
        }
        assert_same(20, (int) $w['pdo']->query('SELECT COUNT(*) FROM asks')->fetchColumn());
        assert_same(null, ask_row($w, $ids[0]), 'oldest dropped');
        assert_same('a2', ask_row($w, $ids[1])['answer'], 'second kept');
        [$status, , $out] = ask_answers($w);
        assert_same(200, $status);
        $answers = json_decode($out, true)['answers'];
        assert_same(20, count($answers));
        assert_same('a21', $answers[0]['answer'], 'newest first');
    } finally {
        ask_cleanup($w);
    }
});

test('Registry maps ask to the AskHandler factory', function () {
    assert_true((new Registry())->has('ask'), 'ask registered');
    assert_same([AskHandler::class, 'fromConfig'], Registry::DEFAULT_MAP['ask']);
    // A factory entry builds its handler with the worker's PDO; without one it refuses.
    [$db, $pdo] = queue_db();
    try {
        $r = new Registry(['ask' => [AskHandler::class, 'fromConfig']]);
        $threw = false;
        try {
            $r->run('ask', ['ask_id' => 1]);
        } catch (RuntimeException $e) {
            $threw = str_contains($e->getMessage(), 'needs the database');
        }
        assert_true($threw, 'factory without PDO refused');
        $pdo = null;
    } finally {
        queue_cleanup($db);
    }
});

test('a project swapped for a junction after enqueue is refused at launch', function () {
    $w = ask_world();
    try {
        [$status, , $body] = ask_post($w, ['question' => 'hi', 'project' => 'alpha']);
        assert_same(202, $status);
        $id = json_decode($body, true)['id'];
        rmdir($w['base'] . '\\root\\alpha');
        ask_mklink($w['base'] . '\\root\\alpha', $w['base'] . '\\outside');
        $w['fake']->willReturn(0, ask_claude_json('should not run'));
        $w['handler']->handle(['ask_id' => $id]);
        $row = ask_row($w, $id);
        assert_same('failed', $row['status']);
        assert_true(str_contains((string) $row['error'], 'safety check'), 'error ' . $row['error']);
        assert_same([], $w['fake']->calls, 'nothing started');
    } finally {
        ask_cleanup($w);
    }
});

test('a dirty busy_if_dirty path stores finance busy and starts no claude', function () {
    $w = ask_world();
    try {
        [$status, , $body] = ask_post($w, ['question' => 'post the invoices', 'project' => 'fin']);
        assert_same(202, $status, $body);
        $id = json_decode($body, true)['id'];
        $w['fake']->willReturn(0, " M scripts/run.php\n");
        $w['handler']->handle(['ask_id' => $id]);
        $row = ask_row($w, $id);
        assert_same('finance busy, try later', $row['answer']);
        assert_same(1, count($w['fake']->calls), 'only git ran');
        assert_same([ASK_GIT, '-C', $w['base'] . '\\extra\\fin', 'status', '--porcelain', '--', 'scripts/'],
            $w['fake']->calls[0]['argv']);
        // A clean path goes on to claude, in the extra folder.
        [, , $body] = ask_post($w, ['question' => 'post the invoices', 'project' => 'fin']);
        $w['fake']->willReturn(0, '');
        $w['fake']->willReturn(0, ask_claude_json('posted'));
        $w['handler']->handle(['ask_id' => json_decode($body, true)['id']]);
        assert_same(3, count($w['fake']->calls), 'git then claude');
        assert_same('C:\\fake\\claude.exe', $w['fake']->calls[2]['argv'][0]);
        assert_same('fin', $w['fake']->calls[2]['cwd']);
    } finally {
        ask_cleanup($w);
    }
});

test('a git that fails counts as busy', function () {
    $w = ask_world();
    try {
        [, , $body] = ask_post($w, ['question' => 'x', 'project' => 'fin']);
        $w['fake']->willReturn(128, '', 'fatal: not a git repository');
        $w['handler']->handle(['ask_id' => json_decode($body, true)['id']]);
        assert_same('finance busy, try later', ask_row($w, json_decode($body, true)['id'])['answer']);
        assert_same(1, count($w['fake']->calls));
    } finally {
        ask_cleanup($w);
    }
});

// Runs a callback in the middle of the first process start, as a second worker would while claude is running.
class AskMidRunFake extends FakeProcessRunner
{
    /** @var ?callable */
    public $during = null;

    public function run(array $argv, ?string $cwdName = null, int $timeoutSeconds = 60, ?string $stdin = null): array
    {
        $during = $this->during;
        $this->during = null;
        if ($during !== null) {
            $during();
        }
        return parent::run($argv, $cwdName, $timeoutSeconds, $stdin);
    }
}

test('a second handle of the same ask starts no claude', function () {
    $w = ask_world();
    try {
        $fake = new AskMidRunFake();
        $claude = new ClaudeRunner(fn(?string $root, array $env): ProcessRunner => $fake, 'C:\\fake\\claude.exe');
        $handler = new AskHandler($w['pdo'], $claude, $fake, ASK_GIT);
        [, , $body] = ask_post($w, ['question' => 'once only', 'project' => 'alpha']);
        $id = json_decode($body, true)['id'];
        $fake->willReturn(0, ask_claude_json('first'));
        $fake->willReturn(0, ask_claude_json('second'));
        // While the first run is inside claude, a recovered copy of the job arrives.
        $second = null;
        $fake->during = function () use ($handler, $id, &$second) {
            try {
                $handler->handle(['ask_id' => $id]);
                $second = 'returned';
            } catch (RuntimeException $e) {
                $second = $e->getMessage();
            }
        };
        $handler->handle(['ask_id' => $id]);
        assert_true(str_contains((string) $second, 'already running'), 'second run refused: ' . var_export($second, true));
        assert_same(1, count($fake->calls), 'claude started once');
        // After it finishes, a third copy is a no-op too.
        assert_true(str_contains($handler->handle(['ask_id' => $id]), 'already'), 'done ask not re-run');
        assert_same(1, count($fake->calls), 'still once');
    } finally {
        ask_cleanup($w);
    }
});

test('a recovered job whose ask is still running fails without starting anything', function () {
    $w = ask_world();
    try {
        [, , $body] = ask_post($w, ['question' => 'x', 'project' => 'alpha']);
        $id = json_decode($body, true)['id'];
        // The first worker claimed the ask and died before finishing.
        $w['pdo']->prepare("UPDATE asks SET status = 'running' WHERE id = ?")->execute([$id]);
        $threw = false;
        try {
            $w['handler']->handle(['ask_id' => $id]);
        } catch (RuntimeException $e) {
            $threw = true;
        }
        assert_true($threw, 'job failed');
        assert_same([], $w['fake']->calls, 'nothing started');
        assert_same('failed', ask_row($w, $id)['status']);
    } finally {
        ask_cleanup($w);
    }
});

test('answers.php refuses a foreign Host', function () {
    $w = ask_world();
    try {
        foreach (['evil.com', 'localhost', 'localhost:8787.evil.com'] as $host) {
            [$status, , $body] = ask_answers($w, ['HTTP_HOST' => $host], $opened);
            assert_same(403, $status, $host);
            assert_true(!str_contains($body, 'answers'), 'no data for ' . $host);
            assert_true(!$opened, 'database opened for ' . $host);
        }
        [$status, $headers, $body] = ask_answers($w);
        assert_same(200, $status);
        assert_same('application/json', $headers['Content-Type']);
        assert_same(['answers' => []], json_decode($body, true));
    } finally {
        ask_cleanup($w);
    }
});

test('answers.php without the token or as a navigation is refused before the database opens', function () {
    $w = ask_world();
    try {
        [, , $body] = ask_post($w, ['question' => 'private question', 'project' => 'alpha']);
        $refused = [
            'no token' => [RequestGuard::TOKEN_HEADER => null],
            'wrong token' => [RequestGuard::TOKEN_HEADER => 'fake-wrong'],
            'no token, curl' => [RequestGuard::TOKEN_HEADER => null, 'HTTP_SEC_FETCH_SITE' => null, 'HTTP_SEC_FETCH_MODE' => null],
            'cross-site navigate' => ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate'],
            'same-origin navigate' => ['HTTP_SEC_FETCH_MODE' => 'navigate'],
        ];
        foreach ($refused as $name => $over) {
            [$status, , $out] = ask_answers($w, $over, $opened);
            assert_same(403, $status, $name);
            assert_same('{"error":"forbidden"}', $out, $name);
            assert_true(!$opened, 'database opened: ' . $name);
        }
        // With the token the rows are the same ones answers() gives index.php.
        [$status, , $out] = ask_answers($w, [], $opened);
        assert_same(200, $status);
        assert_true($opened, 'database opened');
        assert_same(AskApi::answers(ask_get_server(), $w['pdo'])[2], $out);
        assert_same('private question', json_decode($out, true)['answers'][0]['question']);
    } finally {
        ask_cleanup($w);
    }
});
