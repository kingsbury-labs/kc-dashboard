<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Briefing/Composer.php';
require_once __DIR__ . '/../src/Memory/MemoryStore.php';
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/Support/DomSinks.php';

define('BAPI_TOKEN', str_repeat('7c', 32));
define('BAPI_NOW', strtotime('2026-10-19 07:00:00'));

// A temp token, vault and database. $opened counts every time an endpoint opened the database or vault.
function bapi_world(): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcbapi_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '\\token', BAPI_TOKEN);
    $pdo = Db::open($dir . '\\db.sqlite');
    Db::migrate($pdo);
    return ['dir' => $dir, 'pdo' => $pdo, 'vault' => new Vault($dir . '\\vault'), 'log' => new Log($dir . '\\log')];
}

function bapi_rmtree(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") ? bapi_rmtree("$dir/$f") : @unlink("$dir/$f");
        }
    }
    @rmdir($dir);
}

function bapi_server(string $method, array $over = []): array
{
    $base = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => $method,
        'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors', RequestGuard::TOKEN_HEADER => BAPI_TOKEN];
    if ($method === 'POST') {
        $base['HTTP_ORIGIN'] = 'http://127.0.0.1:8787';
    }
    return array_filter($over + $base, fn($v) => $v !== null);
}

function bapi_calls(array $w, int &$opened): array
{
    $open = function () use ($w, &$opened): array {
        $opened++;
        return [$w['pdo'], $w['vault']];
    };
    $pdo = function () use ($w, &$opened): PDO {
        $opened++;
        return $w['pdo'];
    };
    $tok = $w['dir'] . '\\token';
    return [
        'brief' => fn(array $s) => BriefApi::post($s, $tok, $open, $w['log'], BAPI_NOW),
        'remember' => fn(array $s, string $body = '{"text":"call Henike"}') => MemoryApi::remember($s, $body, $tok, $open, $w['log'], BAPI_NOW),
        'recall' => fn(array $s) => MemoryApi::recall($s, ['q' => 'henike'], $tok, $pdo, $w['log']),
        'page' => fn(array $s, array $q = []) => View::briefingsPage($s, $tok, $w['log'], function () use ($w, &$opened): Composer {
            $opened++;
            return new Composer($w['pdo']);
        }, $q, BAPI_NOW),
    ];
}

test('foreign Host is rejected by every briefing and memory endpoint before anything opens', function () {
    $w = bapi_world();
    try {
        $opened = 0;
        $c = bapi_calls($w, $opened);
        foreach (['brief' => 'POST', 'remember' => 'POST', 'recall' => 'GET', 'page' => 'GET'] as $name => $method) {
            [$status, , $body] = $c[$name](bapi_server($method, ['HTTP_HOST' => 'evil.com']));
            assert_same([403, '{"error":"forbidden"}'], [$status, $body], $name);
        }
        foreach (['navigate' => ['HTTP_SEC_FETCH_MODE' => 'navigate'], 'cross-site' => ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
            'no token' => [RequestGuard::TOKEN_HEADER => null], 'POST' => ['REQUEST_METHOD' => 'POST']] as $why => $over) {
            assert_same(403, $c['recall'](bapi_server('GET', $over))[0], "recall $why");
        }
        foreach (['no token' => [RequestGuard::TOKEN_HEADER => null], 'foreign Origin' => ['HTTP_ORIGIN' => 'http://evil.com'],
            'no Origin' => ['HTTP_ORIGIN' => null]] as $why => $over) {
            assert_same(403, $c['brief'](bapi_server('POST', $over))[0], "brief $why");
        }
        assert_same(0, $opened, 'nothing opened');
        assert_same([], glob($w['dir'] . '\\vault\\Briefings\\*'), 'nothing built');
    } finally {
        $w['pdo'] = null;
        bapi_rmtree($w['dir']);
    }
});

test('brief by GET with the token is refused and writes nothing', function () {
    $w = bapi_world();
    try {
        $opened = 0;
        $c = bapi_calls($w, $opened);
        foreach (['fetch' => [], 'with Origin' => ['HTTP_ORIGIN' => 'http://127.0.0.1:8787']] as $why => $over) {
            assert_same([403, '{"error":"forbidden"}'], array_values(array_diff_key($c['brief'](bapi_server('GET', $over)), [1 => 1])), "GET $why");
        }
        assert_same(0, $opened, 'nothing opened');
        assert_same(0, (int) $w['pdo']->query('SELECT COUNT(*) FROM briefings')->fetchColumn(), 'no briefing stored');
        assert_same([], glob($w['dir'] . '\\vault\\Briefings\\*') ?: [], 'no briefing file');
    } finally {
        $w['pdo'] = null;
        bapi_rmtree($w['dir']);
    }
});

test('remember without the token, from another Origin or by GET saves nothing', function () {
    $w = bapi_world();
    try {
        $opened = 0;
        $c = bapi_calls($w, $opened);
        $refused = ['no token' => bapi_server('POST', [RequestGuard::TOKEN_HEADER => null]),
            'wrong token' => bapi_server('POST', [RequestGuard::TOKEN_HEADER => str_repeat('00', 32)]),
            'foreign Origin' => bapi_server('POST', ['HTTP_ORIGIN' => 'http://evil.com']),
            'GET' => bapi_server('GET')];
        foreach ($refused as $why => $s) {
            assert_same(403, $c['remember']($s)[0], $why);
        }
        assert_same(0, $opened);
        assert_same(0, (new MemoryStore($w['pdo']))->count());
        assert_true(!file_exists($w['dir'] . '\\vault\\Memory\\2026-10.md'), 'no memory file');
        assert_same(400, $c['remember'](bapi_server('POST'), json_encode(['text' => str_repeat('a', 2001)]))[0], 'too long');
        assert_same(0, $opened, 'too long is refused before anything opens');
        [$status, , $body] = $c['remember'](bapi_server('POST'));
        assert_same([200, '- 07:00 call Henike'], [$status, json_decode($body, true)['saved']]);
        [$status, , $body] = $c['recall'](bapi_server('GET'));
        assert_same([200, [['date' => '2026-10-19', 'time' => '07:00', 'text' => 'call Henike']]], [$status, json_decode($body, true)['matches']]);
    } finally {
        $w['pdo'] = null;
        bapi_rmtree($w['dir']);
    }
});

test('brief builds today on demand once, and history is newest first', function () {
    $w = bapi_world();
    try {
        $opened = 0;
        $c = bapi_calls($w, $opened);
        $comp = new Composer($w['pdo'], $w['vault']);
        $comp->build(['attention' => ['items' => [['id' => 'old-fact', 'severity' => 'low', 'kind' => 'k', 'subject' => 'Day one']]]], BAPI_NOW - 2 * 86400);
        $comp->build([], BAPI_NOW - 86400);
        [$status, , $body] = $c['brief'](bapi_server('POST'));
        $first = json_decode($body, true);
        assert_same([200, '2026-10-19'], [$status, $first['day']]);
        assert_true(is_file($w['dir'] . '\\vault\\Briefings\\2026-10-19.md'), 'built on demand');
        $again = json_decode($c['brief'](bapi_server('POST'))[2], true);
        assert_same($first, $again, 'the second call shows the stored one');
        assert_same(['2026-10-19', '2026-10-18', '2026-10-17'], array_column($comp->history(), 'day'));

        $nav = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET',
            'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_MODE' => 'navigate'];
        [$status, , $html] = $c['page']($nav);
        assert_same(200, $status);
        preg_match_all('#\?day=(\d{4}-\d{2}-\d{2})#', $html, $m);
        assert_same(['2026-10-19', '2026-10-18', '2026-10-17'], $m[1], 'history newest first');
        [, , $old] = $c['page']($nav, ['day' => '2026-10-17']);
        assert_true(str_contains($old, 'Day one') && str_contains($old, '<h2 id="briefing-h">Briefing 2026-10-17</h2>'), 'opens as it was that day');
        [, , $bad] = $c['page']($nav, ['day' => '../x']);
        assert_true(str_contains($bad, '<h2 id="briefing-h">Briefing 2026-10-19</h2>'), 'a bad day falls back to today');
    } finally {
        $w['pdo'] = null;
        bapi_rmtree($w['dir']);
    }
});

test('the briefings page escapes what it shows, and a navigation never builds', function () {
    $w = bapi_world();
    try {
        $w['pdo']->prepare('INSERT INTO briefings (day, created_at, body, facts, snapshot) VALUES (?, ?, ?, ?, ?)')
            ->execute(['2026-10-18', 1, "# x\n## <script>\n- <img src=x onerror=alert(1)>\ntext & more", '[]', '{}']);
        $opened = 0;
        $c = bapi_calls($w, $opened);
        [, , $html] = $c['page'](['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET']);
        assert_true(!str_contains($html, '<img src=x') && !str_contains($html, '<h3><script>'), 'escaped');
        assert_true(str_contains($html, '&lt;img src=x onerror=alert(1)&gt;'), 'shown as text');
        assert_true(!is_file($w['dir'] . '\\vault\\Briefings\\2026-10-19.md'), 'no build');
    } finally {
        $w['pdo'] = null;
        bapi_rmtree($w['dir']);
    }
});

test('the page wires Brief me and the executive briefing, brief.js sends the token, DomSinks clean', function () {
    $w = bapi_world();
    try {
        [, , $page] = View::page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
            $w['dir'] . '\\token', $w['log'], [], BAPI_NOW);
        assert_true(str_contains($page, '<script type="module" src="/assets/brief.js"></script>'), 'brief.js loaded');
        assert_true(str_contains($page, 'data-command="brief" data-brief'), 'Brief me wired');
        assert_true(str_contains($page, '<a class="brief" id="exec-brief" href="/briefings.php">'), 'executive briefing links with JS off');
        assert_true(!str_contains($page, 'Brief me <span class="nc-tag">'), 'Brief me no longer not connected');
    } finally {
        $w['pdo'] = null;
        bapi_rmtree($w['dir']);
    }
    $src = (string) file_get_contents(__DIR__ . '/../public/assets/brief.js');
    preg_match_all('/\bfetch\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
    assert_same(3, count($m[0]), 'three fetches');
    foreach ($m[0] as [, $at]) {
        assert_true(str_contains(substr($src, $at, 300), "'X-KC-Token': token"), 'fetch without the token at ' . $at);
    }
    assert_true(str_contains($src, "/^(remember|recall) "), 'remember is a code match');
    assert_same([], DomSinks::find(__DIR__ . '/../public/assets'));
});
