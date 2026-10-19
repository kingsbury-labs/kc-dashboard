<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/../src/Attention/Selector.php';
require_once __DIR__ . '/Support/DomSinks.php';

define('AAPI_TOKEN', str_repeat('b4', 32));
define('AAPI_NOW', strtotime('2026-10-19 12:00:00'));
const AAPI_PORTS = [8787];
const AAPI_XSS = '<img src=x onerror=alert(1)>';

function aapi_fact(string $id, array $over = []): array
{
    return $over + ['id' => ($over['source'] ?? 'ssl') . ':' . $id, 'source' => 'ssl', 'kind' => 'cert_expiry', 'subject' => "Renew $id", 'salient_hash' => "hash-$id",
        'due_date' => '2026-10-25', 'severity' => 'high', 'evidence' => 'notAfter', 'url' => 'https://example.com/' . $id,
        'parser_version' => 'ssl/1'];
}

// A database with three facts, a fresh ssl source and a finance source last good 27 hours ago.
function aapi_db(): PDO
{
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    $s = new FactStore($pdo);
    $s->record(aapi_fact('good'), AAPI_NOW);
    $s->record(aapi_fact('js', ['url' => 'javascript:alert(1)']), AAPI_NOW);
    $s->record(aapi_fact('port', ['url' => 'http://127.0.0.1:9999/']), AAPI_NOW);
    $s->record(aapi_fact('local', ['url' => 'http://127.0.0.1:8787/wall.php']), AAPI_NOW);
    $s->sourceOk('ssl', AAPI_NOW - 600);
    $s->sourceOk('finance', AAPI_NOW - 27 * 3600);
    return $pdo;
}

function aapi_server(string $method, array $over): array
{
    $base = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => $method,
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => AAPI_TOKEN];
    if ($method === 'POST') {
        $base['HTTP_ORIGIN'] = 'http://127.0.0.1:8787';
    }
    return array_filter($over + $base, fn($v) => $v !== null);
}

// Runs $call(server, tokenPath, pdo closure, log) with a temp token; $opened says whether the database was asked for.
function aapi_call(Closure $call, string $method, array $over, PDO $pdo, bool &$opened = false): array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcaapi_' . bin2hex(random_bytes(4));
    file_put_contents($tmp . '.token', AAPI_TOKEN);
    try {
        return $call(aapi_server($method, $over), $tmp . '.token', function () use ($pdo, &$opened): PDO {
            $opened = true;
            return $pdo;
        }, new Log($tmp . '.log'));
    } finally {
        @unlink($tmp . '.token');
        @unlink($tmp . '.log');
    }
}

function aapi_get(array $over, PDO $pdo, bool &$opened = false): array
{
    return aapi_call(fn($srv, $tok, $db, $log) => AttentionApi::get($srv, $tok, $db, $log, AAPI_PORTS, AAPI_NOW), 'GET', $over,
        $pdo, $opened);
}

function aapi_post(array $body, array $over, PDO $pdo, bool &$opened = false): array
{
    return aapi_call(fn($srv, $tok, $db, $log) => AttentionApi::feedback($srv, json_encode($body), $tok, $db, $log, AAPI_NOW),
        'POST', $over, $pdo, $opened);
}

function aapi_labels(PDO $pdo): array
{
    return $pdo->query('SELECT fact_id, salient_hash, reason FROM feedback ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

test('attention.php and feedback.php: a foreign Host is rejected before the database opens', function () {
    $pdo = aapi_db();
    foreach (['evil.com', '127.0.0.1:8788', 'localhost'] as $host) {
        $opened = false;
        [$status, , $body] = aapi_get(['HTTP_HOST' => $host], $pdo, $opened);
        assert_same([403, '{"error":"forbidden"}', false], [$status, $body, $opened], "GET $host");
        [$status, , $body] = aapi_post(['id' => 'ssl:good', 'reason' => 'useful'], ['HTTP_HOST' => $host], $pdo, $opened);
        assert_same([403, '{"error":"forbidden"}', false], [$status, $body, $opened], "POST $host");
    }
    assert_same([], aapi_labels($pdo));
});

test('attention.php: no token, a navigation or a POST is refused', function () {
    $pdo = aapi_db();
    foreach (['no token' => [RequestGuard::TOKEN_HEADER => null], 'navigate' => ['HTTP_SEC_FETCH_MODE' => 'navigate'],
        'POST' => ['REQUEST_METHOD' => 'POST']] as $name => $over) {
        $opened = false;
        [$status] = aapi_get($over, $pdo, $opened);
        assert_same([403, false], [$status, $opened], $name);
    }
});

test('feedback.php: a POST without the token, with a wrong Origin or as a GET saves nothing', function () {
    $pdo = aapi_db();
    $refused = ['no token' => [RequestGuard::TOKEN_HEADER => null], 'wrong token' => [RequestGuard::TOKEN_HEADER => str_repeat('0', 64)],
        'wrong Origin' => ['HTTP_ORIGIN' => 'http://evil.com'], 'no Origin' => ['HTTP_ORIGIN' => null],
        'GET' => ['REQUEST_METHOD' => 'GET']];
    foreach ($refused as $name => $over) {
        $opened = false;
        [$status, , $body] = aapi_post(['id' => 'ssl:good', 'reason' => 'useful'], $over, $pdo, $opened);
        assert_same([403, '{"error":"forbidden"}', false], [$status, $body, $opened], $name);
    }
    assert_same([], aapi_labels($pdo));
    assert_same('new', (new FactStore($pdo))->get('ssl:good')['state']);
});

test('feedback.php: a valid POST saves one label with the salient hash', function () {
    $pdo = aapi_db();
    [$status, , $body] = aapi_post(['id' => 'ssl:good', 'reason' => 'not actionable'], [], $pdo);
    assert_same([200, '{"ok":true}'], [$status, $body]);
    assert_same([['fact_id' => 'ssl:good', 'salient_hash' => 'hash-good', 'reason' => 'not actionable']], aapi_labels($pdo));
    assert_same('dismissed', (new FactStore($pdo))->get('ssl:good')['state']);
});

test('feedback.php: an unknown reason, a bad body or an unknown fact is refused and saves nothing', function () {
    $pdo = aapi_db();
    [$status, , $body] = aapi_post(['id' => 'ssl:good', 'reason' => 'meh'], [], $pdo);
    assert_same([400, '{"error":"bad reason"}'], [$status, $body]);
    [$status] = aapi_post(['id' => 'ssl:good', 'reason' => ['useful']], [], $pdo);
    assert_same(400, $status, 'reason as a list');
    [$status] = aapi_post(['reason' => 'useful'], [], $pdo);
    assert_same(400, $status, 'no id');
    [$status] = aapi_post(['id' => 'nope', 'reason' => 'useful'], [], $pdo);
    assert_same(404, $status, 'unknown fact');
    [$status] = aapi_post(['id' => 'ssl:good', 'snooze_days' => 3], [], $pdo);
    assert_same(400, $status, 'snooze length not offered');
    assert_same([], aapi_labels($pdo));
    assert_same('new', (new FactStore($pdo))->get('ssl:good')['state']);
});

test('feedback.php: a snooze hides the fact for that many days', function () {
    $pdo = aapi_db();
    [$status] = aapi_post(['id' => 'ssl:good', 'snooze_days' => 7], [], $pdo);
    assert_same(200, $status);
    $s = new FactStore($pdo);
    assert_true(!in_array('ssl:good', array_column($s->visible(AAPI_NOW), 'id'), true), 'hidden');
    assert_true(in_array('ssl:good', array_column($s->visible(AAPI_NOW + 7 * 86400), 'id'), true), 'back after a week');
});

test('attention.php: a javascript: url and an unlisted 127.0.0.1 port come back as null', function () {
    [$status, , $body] = aapi_get([], aapi_db());
    assert_same(200, $status);
    $urls = array_column(json_decode($body, true)['items'], 'url', 'id');
    assert_same(['ssl:good' => 'https://example.com/good', 'ssl:js' => null, 'ssl:local' => 'http://127.0.0.1:8787/wall.php',
        'ssl:port' => null], $urls);
    assert_true(!str_contains($body, 'salient_hash') && !str_contains($body, 'hash-good'), 'no hash in the response');
    foreach (['http://example.com', 'HTTPS://x.com', 'https://', 'https://a.com/"onmouseover=x', 'http://127.0.0.1:87870/',
        'http://127.0.0.1:8787@evil.com/', 'http://localhost:8787/', ' https://a.com'] as $bad) {
        assert_same(null, AttentionApi::safeUrl($bad, AAPI_PORTS, 'ssl'), $bad);
    }
});

test('safeUrl: https to this machine is refused under any spelling, so it cannot get round the port list', function () {
    foreach (['https://127.0.0.1:9999/', 'https://127.0.0.1/', 'https://localhost:9999/', 'https://LOCALHOST/',
        'https://localhost./', 'https://app.localhost/', 'https://[::1]:9999/', 'https://[::ffff:127.0.0.1]/', 'https://0.0.0.0:9999/',
        'https://2130706433/', 'https://0x7f.1/', 'https://017700000001/'] as $bad) {
        assert_same(null, AttentionApi::safeUrl($bad, [9999], 'ssl'), $bad);
    }
    foreach (['https://example.com/a', 'https://127.example.com/'] as $ok) {
        assert_same($ok, AttentionApi::safeUrl($ok, [], 'ssl'), $ok);
    }
});

test('safeUrl: a finance url links only under http://127.0.0.1:8000/, even when other urls would pass', function () {
    assert_same('http://127.0.0.1:8000/invoices/7', AttentionApi::safeUrl('http://127.0.0.1:8000/invoices/7', [8000], 'finance'));
    foreach (['https://example.com/pay', 'http://127.0.0.1:8001/', 'http://127.0.0.1:8000', 'http://127.0.0.1:80001/',
        'http://127.0.0.1:8000@evil.com/', 'http://127.0.0.1:8000/"x', 'https://127.0.0.1:8000/'] as $bad) {
        assert_same(null, AttentionApi::safeUrl($bad, [8000, 8001], 'finance'), $bad);
    }
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    $s = new FactStore($pdo);
    $s->record(aapi_fact('inv', ['source' => 'finance', 'url' => 'https://example.com/pay']), AAPI_NOW);
    $s->record(aapi_fact('ok', ['source' => 'finance', 'url' => 'http://127.0.0.1:8000/invoices/7']), AAPI_NOW);
    $s->record(aapi_fact('other', ['url' => 'https://example.com/pay']), AAPI_NOW);
    assert_same(['finance:inv' => null, 'finance:ok' => 'http://127.0.0.1:8000/invoices/7', 'ssl:other' => 'https://example.com/pay'],
        array_column(AttentionApi::data($s, [8000], AAPI_NOW)['items'], 'url', 'id'));
});

test('linkPorts reads link_ports and gives none when the key is missing or holds junk', function () {
    assert_same([8000], AttentionApi::linkPorts());
    assert_same([], AttentionApi::linkPorts([]));
    assert_same([8000], AttentionApi::linkPorts(['link_ports' => ['8787', 8000, 70000, 0]]));
});

test('with config link_ports, an http://127.0.0.1:8000 url renders as a link and 8001 and 9999 do not', function () {
    $ports = (require __DIR__ . '/../config/running.php')['link_ports'] ?? null;
    assert_same([8000], $ports, 'config link_ports');
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    $s = new FactStore($pdo);
    foreach (['8000' => 'http://127.0.0.1:8000/invoices/7', '8001' => 'http://127.0.0.1:8001/x', '9999' => 'http://127.0.0.1:9999/'] as $id => $url) {
        $s->record(aapi_fact("p$id", ['url' => $url, 'subject' => "Fact $id"]), AAPI_NOW);
    }
    $data = AttentionApi::data($s, $ports, AAPI_NOW);
    assert_same(['ssl:p8000' => 'http://127.0.0.1:8000/invoices/7', 'ssl:p8001' => null, 'ssl:p9999' => null],
        array_column($data['items'], 'url', 'id'));
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcaapi_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', AAPI_TOKEN);
    try {
        [, , $page] = View::page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
            $dir . '/token', new Log($dir . '/log'), ['attention' => fn() => $data]);
    } finally {
        @unlink($dir . '/token');
        @unlink($dir . '/log');
        @rmdir($dir);
    }
    assert_true(str_contains($page, '<a href="http://127.0.0.1:8000/invoices/7"'), '8000 linked');
    assert_true(str_contains($page, '<p class="subject">Fact 8001</p>') && str_contains($page, '<p class="subject">Fact 9999</p>'),
        '8001 and 9999 as text');
    assert_true(!str_contains($page, '127.0.0.1:8001') && !str_contains($page, '127.0.0.1:9999'), 'no unlisted port on the page');
});

test('attention.php: a source past its maximum age is flagged stale, a fresh one is not', function () {
    [, , $body] = aapi_get([], aapi_db());
    $stale = array_column(json_decode($body, true)['sources'], 'stale', 'name');
    assert_same(['finance' => true, 'ssl' => false], $stale);
});

test('the page renders the attention list escaped, links only safe urls and shows stale sources', function () {
    $pdo = aapi_db();
    $s = new FactStore($pdo);
    $s->record(aapi_fact('xss', ['subject' => AAPI_XSS, 'evidence' => AAPI_XSS, 'source' => AAPI_XSS]), AAPI_NOW);
    $s->record(aapi_fact('sixth', ['severity' => 'low']), AAPI_NOW);
    $s->sourceFailed(AAPI_XSS, AAPI_XSS, new Log(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcaapi_unused.log'));
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcaapi_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', AAPI_TOKEN);
    try {
        [$status, , $page] = View::page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
            $dir . '/token', new Log($dir . '/log'), ['attention' => fn() => AttentionApi::data($s, AAPI_PORTS, AAPI_NOW)]);
    } finally {
        @unlink($dir . '/token');
        @unlink($dir . '/log');
        @rmdir($dir);
    }
    assert_same(200, $status);
    assert_true(!str_contains($page, '<img'), 'raw markup reached the page');
    assert_true(substr_count($page, '&lt;img src=x onerror=alert(1)&gt;') >= 5, 'escaped copies');
    assert_true(str_contains($page, '<a href="https://example.com/good"'), 'safe url linked');
    assert_true(!str_contains($page, 'javascript:') && !str_contains($page, '127.0.0.1:9999'), 'unsafe urls not on the page');
    assert_same(5, substr_count(explode('id="attention-sources"', $page)[0], '<li class="fact"'), 'top five');
    assert_true(str_contains($page, 'Also seen (1)'), 'the fold');
    assert_true(str_contains($page, 'finance · ok · stale'), 'stale source shown');
    assert_true(str_contains($page, '/assets/attention.js'), 'script');
});

test('the wall says attention is unavailable when its source fails, never an empty list', function () {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcaapi_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', AAPI_TOKEN);
    $wall = fn(array $deps) => View::wall(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
        $dir . '/token', new Log($dir . '/log'), $deps + ['panels' => ['attention' => true]])[2];
    try {
        $none = $wall([]);
        $broken = $wall(['attention' => function () {
            throw new RuntimeException('database locked');
        }]);
        $s = new FactStore(aapi_db());
        $working = $wall(['attention' => fn() => AttentionApi::data($s, AAPI_PORTS, AAPI_NOW)]);
    } finally {
        @unlink($dir . '/token');
        @unlink($dir . '/log');
        @rmdir($dir);
    }
    foreach (['no data' => $none, 'source throws' => $broken] as $name => $page) {
        assert_true(str_contains($page, '<ul id="attention" class="list"><li class="quiet" data-unavailable="1">Attention unavailable.</li></ul>'),
            $name);
        assert_true(!str_contains($page, 'database locked'), "$name: error text on the page");
    }
    assert_same(4, substr_count($working, '<li class="fact"'), 'facts render on the wall');
    assert_true(!str_contains($working, 'Attention unavailable.'), 'no unavailable note when it works');
    $js = (string) file_get_contents(__DIR__ . '/../public/assets/attention.js');
    assert_true(str_contains($js, "'Attention unavailable.'") && str_contains($js, 'unavailable();'), 'attention.js says so on a failed read');
});

test('attention.js writes with textContent only and sends X-KC-Token on every fetch', function () {
    assert_same([], DomSinks::find(__DIR__ . '/../public/assets'));
    $src = (string) file_get_contents(__DIR__ . '/../public/assets/attention.js');
    assert_true(str_contains($src, "from './app.js'"), 'uses app.js el()');
    preg_match_all('/\bfetch\s*\(([^;]*)/', $src, $m);
    assert_same(2, count($m[1]), 'attention and feedback');
    foreach ($m[1] as $call) {
        assert_true(str_contains($call, "'X-KC-Token': token"), "fetch without the token: $call");
    }
});
