<?php
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/Support/DomSinks.php';

const WALL_ASSETS = __DIR__ . '/../public/assets';
const WALL_XSS = '<img src=x onerror=alert(1)>';

function wall_page(array $deps = [], array $server = []): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcwall_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', str_repeat('3c', 32));
    try {
        return View::wall($server + ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
            $dir . '/token', new Log($dir . '/log'), $deps, strtotime('2026-10-19T12:00:00+00:00'));
    } finally {
        @unlink($dir . '/token');
        @unlink($dir . '/log');
        @rmdir($dir);
    }
}

function wall_row(array $over = []): array
{
    return $over + ['slug' => 'alpha', 'branch' => 'main', 'ahead' => 0, 'behind' => 0, 'dirty' => 2, 'last_sha' => 'abc',
        'last_date' => '2026-10-19T11:00:00+00:00', 'last_subject' => 'Card 1', 'loop_state' => 'running', 'loop_card' => '9',
        'loop_note' => null, 'checklist_cards' => 5, 'checklist_passing' => 3, 'scanned_at' => 0, 'error' => null];
}

test('the no-JS wall is the command center without the rail, with a 30 second refresh and the script', function () {
    [$status, $headers, $body] = wall_page(['map' => fn() => [wall_row(), wall_row(['slug' => 'beta', 'loop_state' => 'none'])],
        'now' => fn() => ['sessions' => [['status' => 'busy', 'project' => 'alpha']], 'ports' => [['port' => 8787, 'program' => 'httpd.exe']]]]);
    assert_same(200, $status);
    assert_true(str_starts_with($headers['Content-Type'], 'text/html'), 'html');
    assert_true(str_contains($body, '<noscript><meta http-equiv="refresh" content="30"></noscript>'), 'no-JS refresh');
    assert_true(str_contains($body, '<body class="wall">'), 'wall body');
    assert_true(str_contains($body, '<canvas id="orb"') && str_contains($body, 'id="orb-static"'), 'orb and its no-JS fallback');
    foreach (['running', 'core', 'agents', 'timeline', 'monitor', 'llm', 'talk'] as $id) {
        assert_true(str_contains($body, 'id="' . $id . '"'), "wall has #$id");
    }
    assert_true(!str_contains($body, 'id="rail"'), 'no rail on the wall');
    assert_same(2, substr_count($body, '<li class="agent"'), 'the session and the running loop');
    assert_true(str_contains($body, 'httpd.exe') && str_contains($body, 'card 9'), 'strip items');
    assert_true(str_contains($body, '<button type="button" id="wall-full" hidden>Fullscreen</button>'), 'fullscreen button waits for JS');
    foreach (['/assets/wall.js', '/assets/wall.css', '/assets/orb.js', '/assets/core.js', '/assets/hud.css', '<meta name="kc-token"'] as $want) {
        assert_true(str_contains($body, $want), "page has $want");
    }
    assert_true(!str_contains($body, '/assets/now.js'), 'the wall streams; it does not poll now.php');
    preg_match_all('#<h[1-6][^>]*>([^<]*)</h[1-6]>#', $body, $h);
    foreach ($h[1] as $heading) {
        assert_true(!str_contains($heading, ','), "comma in heading: $heading");
    }
});

test('the attention and headline panels show only when their API exists', function () {
    [, , $none] = wall_page();
    assert_true(!str_contains($none, 'id="attention"') && !str_contains($none, 'id="news"'), 'hidden without their APIs');
    assert_true(!str_contains($none, 'attention.js') && !str_contains($none, 'news.js'), 'no scripts without their APIs');
    [, , $both] = wall_page(['panels' => ['attention' => true, 'news' => true]]);
    assert_true(str_contains($both, '<ul id="attention"') && str_contains($both, '/assets/attention.js'), 'attention panel');
    assert_true(str_contains($both, '<ul id="news"') && str_contains($both, '/assets/news.js'), 'headline panel');
});

test('wall.php gives each panel only when its API and script are both shipped', function () {
    $src = (string) file_get_contents(__DIR__ . '/../public/wall.php');
    assert_true(str_contains($src, "'/api/' . \$name . '.php'") && str_contains($src, "'/assets/' . \$name . '.js'"), 'both checked');
    assert_true(str_contains($src, "'attention' => \$has('attention')") && str_contains($src, "'news' => \$has('news')"), 'both panels');
});

test('values from the map and the snapshot are escaped', function () {
    [, , $body] = wall_page(['map' => fn() => [wall_row(['last_subject' => WALL_XSS, 'slug' => WALL_XSS])],
        'now' => fn() => ['ports' => [['port' => 1, 'program' => WALL_XSS]]]]);
    assert_true(!str_contains($body, WALL_XSS), 'raw payload on the page');
    assert_true(str_contains($body, '&lt;img src=x onerror=alert(1)&gt;'), 'escaped payload');
});

test('a foreign Host is refused before any source runs', function () {
    $ran = false;
    [$status, , $body] = wall_page(['map' => function () use (&$ran) {
        $ran = true;
        return [];
    }], ['HTTP_HOST' => 'evil.com']);
    assert_same(403, $status);
    assert_same('{"error":"forbidden"}', $body);
    assert_true(!$ran, 'map source ran');
});

test('wall.js streams, holds a wake lock and sends the token on every fetch', function () {
    $src = (string) file_get_contents(WALL_ASSETS . '/wall.js');
    foreach (['EventSource', 'wakeLock', 'visibilitychange', 'requestFullscreen', 'textContent', 'applyNow(data)'] as $want) {
        assert_true(str_contains($src, $want), "wall.js holds $want");
    }
    assert_true(preg_match("/visibilityState === 'visible'\) connect\(\);\s*else disconnect\(\);/", $src) === 1,
        'stream closes while hidden and reopens when visible');
    assert_true(preg_match('/function disconnect\(\) \{[^}]*source\?\.close\(\);/', $src) === 1, 'disconnect closes the EventSource');
    preg_match_all('/\bfetch\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
    assert_true(count($m[0]) > 0, 'wall.js has no fetch');
    foreach ($m[0] as [$match, $at]) {
        $depth = 0;
        for ($i = $at + strlen($match) - 1; $i < strlen($src); $i++) {
            $depth += $src[$i] === '(' ? 1 : ($src[$i] === ')' ? -1 : 0);
            if ($depth === 0) {
                break;
            }
        }
        $call = substr($src, $at, $i - $at + 1);
        assert_true(str_contains($call, "'X-KC-Token': token"), "fetch without the token: $call");
    }
});

test('wall.css is phone first with no max-width query', function () {
    $css = (string) file_get_contents(WALL_ASSETS . '/wall.css');
    assert_true(preg_match('/@media[^{]*max-width/i', $css) === 0, 'max-width media query');
    assert_true(preg_match('/@media\s*\(min-width:/', $css) === 1, 'wide layout through min-width');
    assert_true(preg_match('/#f3efe7/i', $css) === 0, 'cream colour');
});

test('DomSinks finds nothing in the assets, wall.js included', function () {
    assert_true(is_file(WALL_ASSETS . '/wall.js'), 'wall.js exists');
    assert_same([], DomSinks::find(WALL_ASSETS));
});
