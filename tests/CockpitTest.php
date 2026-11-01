<?php
require_once __DIR__ . '/../src/Cockpit/CockpitApi.php';
require_once __DIR__ . '/../src/Cockpit/CockpitPage.php';

const CK_FIX = __DIR__ . '/fixtures/cockpit';
const CK_SESSION = '11111111-aaaa-4bbb-8ccc-000000000001';
define('CK_TOKEN', str_repeat('7c', 32));
// 2026-11-01 10:05 UTC: five minutes after the fixture session started.
define('CK_NOW', (int) strtotime('2026-11-01T10:05:00Z'));

function ck_copy(string $from, string $to): void
{
    mkdir($to, 0777, true);
    foreach (scandir($from) as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$from/$f") ? ck_copy("$from/$f", "$to/$f") : copy("$from/$f", "$to/$f");
        }
    }
}

// The fixture transcripts in a temp folder (so the index never writes beside them), with beta's session last
// touched 30 days before the clock: older than the first run's 7 days.
function ck_world(): array
{
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcck_' . bin2hex(random_bytes(6));
    ck_copy(CK_FIX . '/transcripts', $t . '/transcripts');
    touch($t . '/transcripts/D--Dev-htdocs-beta/22222222-aaaa-4bbb-8ccc-000000000002.jsonl', CK_NOW - 30 * 86400);
    return [$t, $t . '/cockpit.sqlite', $t . '/transcripts'];
}

function ck_deps(string $db, string $dir, array $now = [], ?bool &$opened = null): array
{
    $opened = false;
    return [
        'index' => function () use ($db, $dir, &$opened): TranscriptIndex {
            $opened = true;
            return new TranscriptIndex($db, $dir, null, fn(): int => CK_NOW);
        },
        'projects' => fn(): array => ['alpha' => realpath(CK_FIX . '/projects/alpha')],
        'now' => fn(): array => $now,
        'clock' => fn(): int => CK_NOW,
        'budget' => 5.0,
    ];
}

function ck_server(array $extra = []): array
{
    return $extra + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET',
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => CK_TOKEN];
}

function ck_get(array $deps): array
{
    [$status, , $body] = CockpitApi::handle(ck_server(), fn(): string => CK_TOKEN, $deps);
    assert_same(200, $status, 'snapshot status: ' . substr($body, 0, 200));
    return [json_decode($body, true), $body];
}

test('the snapshot API refuses a request without the token and opens nothing', function () {
    [, $db, $dir] = ck_world();
    $none = ck_server();
    unset($none[RequestGuard::TOKEN_HEADER]);
    $cases = [
        'no token' => $none,
        'wrong token' => ck_server([RequestGuard::TOKEN_HEADER => str_repeat('0', 64)]),
        'navigation' => ck_server(['HTTP_SEC_FETCH_MODE' => 'navigate']),
        'cross-site' => ck_server(['HTTP_SEC_FETCH_SITE' => 'cross-site']),
        'POST' => ck_server(['REQUEST_METHOD' => 'POST']),
        'foreign Host' => ck_server(['HTTP_HOST' => 'evil.com']),
    ];
    foreach ($cases as $name => $server) {
        $read = false;
        [$status, , $body] = CockpitApi::handle($server, function () use (&$read): string {
            $read = true;
            return CK_TOKEN;
        }, ck_deps($db, $dir, [], $opened));
        assert_same(403, $status, $name);
        assert_same('{"error":"forbidden"}', $body, $name);
        assert_true(!$opened, "$name opened the index");
        assert_true(!is_file($db), "$name created the database");
        if ($name === 'foreign Host') {
            assert_true(!$read, 'token read for a foreign Host');
        }
    }
});

test('the snapshot returns projects, sessions, agents with parent links, pipeline steps and agent talk', function () {
    [, $db, $dir] = ck_world();
    [$s] = ck_get(ck_deps($db, $dir));
    $session = $s['sessions'][0];
    assert_same(1, count($s['sessions']), 'sessions');
    assert_same(CK_SESSION, $session['session']);
    assert_same('alpha', $session['project']);
    assert_same('loop', $session['kind']);
    assert_same('opus-5-5', $session['model']);
    assert_same('Alpha card seven', $session['title']);
    assert_same(['7', 2], [$session['card'], $session['step']], 'card 7 at its gate');
    assert_same('AskUserQuestion', $session['tool']);
    $agents = array_column($s['agents'], null, 'key');
    $impl = $agents[CK_SESSION . ':aaa111'] ?? null;
    $nested = $agents[CK_SESSION . ':bbb222'] ?? null;
    assert_true($impl !== null && $nested !== null, 'both agents: ' . implode(', ', array_keys($agents)));
    assert_same(CK_SESSION, $impl['parent']);
    assert_same(CK_SESSION . ':aaa111', $nested['parent'], 'a depth-2 agent hangs off its parent agent');
    assert_same(['implementer', 'Explore'], [$impl['type'], $nested['type']]);
    assert_same('done', $impl['state'], 'handed back');
    assert_same(2, $impl['step'], 'an agent inherits its session pipeline step');
    assert_same(['Grep', 'D:\\Dev\\htdocs\\alpha\\src'], [$nested['tool'], $nested['target']]);
    $p = array_column($s['projects'], null, 'name')['alpha'];
    assert_same(['card' => '7', 'status' => 'gate', 'note' => 'Gating card seven', 'step' => 2], $p['loop']);
    assert_same(['cards' => 3, 'passing' => 1], $p['checklist']);
    assert_same([['id' => '8', 'title' => 'Needs a decision']], $p['parked']);
    $talk = array_column($s['talk'], null, 'id');
    assert_same(['agent', CK_SESSION . ':aaa111'], [$talk['toolu_A1']['kind'], $talk['toolu_A1']['toKey']], 'brief links to its child');
    assert_same(['handback', CK_SESSION, 'Card 7 done: Gamma.php edited, tests green.', 'Cas routed it'],
        [$talk['toolu_H1']['kind'], $talk['toolu_H1']['toKey'], $talk['toolu_H1']['text'], $talk['toolu_H1']['cas']]);
    assert_same(['question', 'Rob', 'Needs Rob'], [$talk['toolu_Q1']['kind'], $talk['toolu_Q1']['to'], $talk['toolu_Q1']['cas']]);
    assert_same('Cas routed it', $talk['toolu_B1']['cas'], 'the implementer acted after its nested brief');
    assert_same('Cas routed it', $talk['toolu_A1']['cas']);
    $needs = array_column($s['needs'], 'kind');
    assert_true(in_array('parked', $needs, true) && in_array('question', $needs, true), 'needs: ' . implode(', ', $needs));
    $files = array_map(fn($f) => $f['op'] . ' ' . $f['path'], $s['files']);
    foreach (['read D:\\Dev\\htdocs\\alpha\\src\\Alpha.php', 'write D:\\Dev\\htdocs\\alpha\\src\\Beta.php', 'write D:\\Dev\\htdocs\\alpha\\src\\Gamma.php'] as $want) {
        assert_true(in_array($want, $files, true), "missing $want in " . implode(' | ', $files));
    }
});

test('a waiting session from the live snapshot shows in Needs you and on its session', function () {
    [, $db, $dir] = ck_world();
    [$s] = ck_get(ck_deps($db, $dir, ['sessions' => [['project' => 'gamma', 'status' => 'waiting', 'waitingFor' => 'approve Bash']]]));
    $titles = array_column($s['needs'], 'title');
    assert_true(in_array('Waiting: approve Bash', $titles, true), implode(' | ', $titles));
});

test('never a never-show path, file contents, tool output or message text over 300 characters', function () {
    [, $db, $dir] = ck_world();
    [$s, $body] = ck_get(ck_deps($db, $dir));
    foreach (['NEVERSHOW', 'FILE-CONTENT-MARKER-107', 'TOOL-OUTPUT-MARKER-107', 'FAKE-VALUE-MARKER-107', 'LONG-PROMPT-TAIL-MARKER',
        'API-REQUEST-MARKER', 'alpha\\\\.env', '.credentials'] as $marker) {
        assert_true(!str_contains($body, $marker), "response carries $marker");
    }
    // Filtered before storing, not only before returning.
    $stored = (string) file_get_contents($db) . (string) @file_get_contents($db . '-wal');
    foreach (['NEVERSHOW', 'FILE-CONTENT-MARKER-107', 'TOOL-OUTPUT-MARKER-107', 'FAKE-VALUE-MARKER-107'] as $marker) {
        assert_true(!str_contains($stored, $marker), "database stores $marker");
    }
    assert_true(count($s['talk']) >= 4, 'talk is empty');
    foreach ($s['talk'] as $t) {
        assert_true(mb_strlen($t['text'], 'UTF-8') <= 300, 'over 300: ' . mb_strlen($t['text'], 'UTF-8'));
    }
    $brief = array_column($s['talk'], null, 'id')['toolu_A1']['text'];
    assert_same(300, mb_strlen($brief, 'UTF-8'), 'the long brief is cut to 300');
    assert_true(str_contains($brief, '[hidden path]') && str_contains($brief, '[redacted]'), $brief);
});

test('NeverShow blocks the credential paths and passes ordinary ones', function () {
    foreach (['D:\\Dev\\htdocs\\.credentials\\x.md', 'C:/app/.env', 'C:\\app\\.env.local', 'C:\\certs\\server.key', 'C:\\x\\id.pem',
        'C:\\Users\\roban\\.claude\\settings.json', 'C:\\Users\\roban\\.ssh\\id_ed25519', 'C:\\Users\\roban\\.aws\\credentials',
        'C:\\p\\.mcp.json', 'C:\\p\\.npmrc', 'C:\\p\\secrets.php', 'C:\\p\\credentials.json'] as $p) {
        assert_true(NeverShow::blocked($p), "not blocked: $p");
    }
    foreach (['D:\\Dev\\htdocs\\alpha\\src\\Alpha.php', 'C:\\app\\.env.example', 'C:\\app\\keys.md', 'C:\\Users\\roban\\.claude\\CLAUDE.md'] as $p) {
        assert_true(!NeverShow::blocked($p), "blocked: $p");
    }
});

test('the first run skips transcripts older than 7 days', function () {
    [, $db, $dir] = ck_world();
    [$s, $body] = ck_get(ck_deps($db, $dir));
    assert_true(!str_contains($body, '22222222'), 'old session indexed');
    assert_true(!in_array('beta', array_column($s['projects'], 'name'), true), 'old project listed');
});

test('the index reads only new complete lines and leaves a half-written one for later', function () {
    [, $db, $dir] = ck_world();
    $idx = new TranscriptIndex($db, $dir, null, fn(): int => CK_NOW);
    $idx->run(5.0);
    $file = $dir . '/D--Dev-htdocs-alpha/' . CK_SESSION . '.jsonl';
    $line = '{"type":"assistant","sessionId":"' . CK_SESSION . '","timestamp":"2026-11-01T10:04:30.000Z","message":{"model":"claude-opus-5-5",'
        . '"content":[{"type":"tool_use","id":"toolu_L1","name":"Read","input":{"file_path":"D:\\\\Dev\\\\htdocs\\\\alpha\\\\Late.php"}}]}}';
    file_put_contents($file, substr($line, 0, 40), FILE_APPEND);
    $idx->run(5.0);
    $tool = fn() => $idx->pdo()->query("SELECT tool FROM sessions WHERE key = '" . CK_SESSION . "'")->fetchColumn();
    assert_same('AskUserQuestion', $tool(), 'a half line was read');
    file_put_contents($file, substr($line, 40) . "\n", FILE_APPEND);
    assert_same(['read' => 1, 'pending' => false], $idx->run(5.0), 'only the grown file is read');
    assert_same('Read', $tool());
    assert_same(['read' => 0, 'pending' => false], $idx->run(5.0), 'nothing new, nothing read');
});

test('a zero budget answers at once with what is stored and says more is pending', function () {
    [, $db, $dir] = ck_world();
    $deps = ['budget' => 0.0] + ck_deps($db, $dir);
    [$s] = ck_get($deps);
    assert_same(true, $s['pending']);
    assert_same([], $s['sessions']);
});

test('the page is checkRead, carries the token and sends the security headers plus only the voice connect-src', function () {
    [$t] = ck_world();
    file_put_contents($t . '/token', CK_TOKEN);
    $log = new Log($t . '/app.log');
    [$status] = CockpitPage::handle(ck_server(['HTTP_HOST' => 'evil.com']), $t . '/token', $log);
    assert_same(403, $status);
    [$status, $headers, $body] = CockpitPage::handle(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787',
        'HTTP_SEC_FETCH_SITE' => 'none', 'HTTP_SEC_FETCH_MODE' => 'navigate'], $t . '/token', $log, $t . '/no-voice-token');
    assert_same(200, $status);
    $want = ['Content-Type' => 'text/html; charset=utf-8'] + Response::SECURITY_HEADERS;
    $want['Content-Security-Policy'] .= "; connect-src 'self' ws://127.0.0.1:8766";
    assert_same($want, $headers);
    assert_true(str_contains($body, '<meta name="kc-token" content="' . CK_TOKEN . '">'), 'token meta');
    assert_true(str_contains($body, '<script type="module" src="/cockpit/js/main.js"></script>'), 'main module');
    assert_true(!preg_match('~https?://~', $body), 'the page loads nothing from another origin');
});

test('no file under public/cockpit uses innerHTML, insertAdjacentHTML, Math.random or a remote script', function () {
    $root = dirname(__DIR__) . '/public/cockpit';
    $files = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!in_array(strtolower($f->getExtension()), ['js', 'css', 'html', 'txt'], true)) {
            continue;
        }
        $files++;
        $src = (string) file_get_contents($f->getPathname());
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'Math.random', 'document.write', 'eval('] as $bad) {
            assert_true(!str_contains($src, $bad), $f->getFilename() . " uses $bad");
        }
        assert_true(!preg_match('~(import\s[^;]*from\s*|import\s*\(\s*|src\s*=\s*)[\'"]https?://~i', $src), $f->getFilename() . ' loads a remote script');
    }
    assert_true($files >= 7, "only $files files checked");
    foreach (['cockpit.css', 'js/main.js', 'js/data.js', 'js/fuzzy.js', 'js/views.js', 'js/dom.js', 'js/voice.js', 'fonts/VENDOR.txt'] as $want) {
        assert_true(is_file("$root/$want"), "missing $want");
    }
});

test('data.js reads api/cockpit.php and api/wall-stream.php with the token', function () {
    $src = (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/js/data.js');
    assert_true(str_contains($src, "'/api/cockpit.php'"), 'snapshot URL');
    assert_true(str_contains($src, "'/api/wall-stream.php'"), 'stream URL');
    assert_true(str_contains($src, "addEventListener('now'"), 'listens to event: now');
    assert_same(2, substr_count($src, "'X-KC-Token': token"), 'both fetches send the token');
    assert_true(str_contains($src, 'POLL_MS = 10000'), 'polls every 10 s');
});

test('the fonts are vendored with their URL and sha256', function () {
    $dir = dirname(__DIR__) . '/public/cockpit/fonts';
    $vendor = (string) file_get_contents("$dir/VENDOR.txt");
    foreach (glob("$dir/*.woff2") as $f) {
        assert_true(str_contains($vendor, basename($f)) && str_contains($vendor, hash_file('sha256', $f)), basename($f) . ' not pinned');
    }
    assert_same(2, count(glob("$dir/*.woff2")));
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/cockpit.css');
    assert_true(!str_contains($css, 'googleapis') && str_contains($css, 'url(fonts/manrope-latin-var.woff2)'), 'fonts load locally');
});
