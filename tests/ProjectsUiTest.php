<?php
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/Support/DomSinks.php';

const PUI_ASSETS = __DIR__ . '/../public/assets';
const PUI_XSS = '<img src=x onerror=alert(1)>';

// The command center by default; 'projectsPage' renders /projects.php, where the map table lives.
function pui_render(array $deps, string $page = 'page'): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcpui_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', str_repeat('3c', 32));
    try {
        [$status, , $body] = View::$page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
            $dir . '/token', new Log($dir . '/log'), $deps, strtotime('2026-10-19T12:00:00+00:00'));
    } finally {
        @unlink($dir . '/token');
        @unlink($dir . '/log');
        @rmdir($dir);
    }
    assert_same(200, $status);
    return $body;
}

// Rows as View::projectRows returns them.
function pui_rows(): array
{
    $row = fn(string $slug, array $over = []) => $over + ['slug' => $slug, 'branch' => 'main', 'ahead' => 0, 'behind' => 0,
        'dirty' => 0, 'last_sha' => str_repeat('2', 40), 'last_date' => '2026-10-19T09:00:00+00:00', 'last_subject' => 'tidy',
        'loop_state' => 'none', 'loop_card' => null, 'loop_note' => null, 'checklist_cards' => null,
        'checklist_passing' => null, 'scanned_at' => 1000, 'error' => null];
    return [
        $row('sembr', ['loop_state' => 'running', 'loop_card' => '41', 'checklist_cards' => 5, 'checklist_passing' => 3]),
        $row('henike', ['dirty' => 3, 'last_date' => '2026-10-16T12:00:00+00:00']),
        $row('kc-site'),
    ];
}

function pui_tiles(string $body): array
{
    preg_match_all('/<tr data-slug="([^"]*)">/', $body, $m);
    return $m[1];
}

function pui_strip(string $body): string
{
    assert_same(1, preg_match('#<ul id="running" class="strip-list">(.*?)</ul>#s', $body, $m), 'strip list');
    return $m[1];
}

test('the no-JS projects page has one row per fixture project in the order given, and the strip', function () {
    $deps = ['map' => fn() => pui_rows(), 'now' => fn() => [
        'sessions' => [['project' => 'kc-site', 'status' => 'busy'], ['project' => 'idle-one', 'status' => 'idle'],
            ['project' => 'henike', 'status' => 'waiting', 'waitingFor' => 'permission']],
        'loops' => [['project' => 'sembr', 'state' => 'running', 'card' => '41']],
        'tasks' => [['name' => 'collect', 'failed' => true, 'lastResult' => '1'], ['name' => 'fine', 'failed' => false]],
        'ports' => [['port' => 5173, 'program' => 'node.exe', 'pid' => 7]],
    ]];
    $map = pui_render($deps, 'projectsPage');
    assert_same(['sembr', 'henike', 'kc-site'], pui_tiles($map));
    assert_true(str_contains($map, '<td class="n">3</td>') && str_contains($map, '3d ago') && str_contains($map, '3h ago'), 'dirty and age');
    assert_true(str_contains($map, 'running, card 41') && str_contains($map, '3 of 5'), 'loop and checklist');
    assert_true(str_contains($map, '<tbody id="projects">') && str_contains($map, '/assets/projects.js'), 'table body and script');
    assert_true(str_contains(pui_strip($map), 'card 41'), 'strip on the projects page');
    $body = pui_render($deps);
    assert_same([], pui_tiles($body), 'the map left the command center');
    $strip = pui_strip($body);
    foreach (['kc-site', 'waiting: permission', 'card 41', 'collect', ':5173', 'node.exe'] as $want) {
        assert_true(str_contains($strip, $want), "strip has $want");
    }
    foreach (['idle-one', 'fine'] as $not) {
        assert_true(!str_contains($strip, $not), "strip leaves out $not");
    }
    // The strip sits in the footer bar on the command center.
    assert_true(strpos($body, 'id="running"') > strpos($body, 'id="footer"'), 'strip in the footer');
});

test('with no snapshot the strip shows running loops from the projects table', function () {
    $strip = pui_strip(pui_render(['map' => fn() => pui_rows()]));
    assert_true(str_contains($strip, 'sembr') && str_contains($strip, 'card 41'), 'loop from the table');
    assert_true(str_contains(pui_strip(pui_render([])), 'Nothing running.'), 'empty strip says so');
});

test('the onerror payload renders escaped in every repo-controlled column and strip field', function () {
    $x = PUI_XSS;
    $body = pui_render(['map' => fn() => [[
        'slug' => $x, 'branch' => $x, 'dirty' => 1, 'last_date' => '2026-10-19T09:00:00+00:00', 'last_subject' => $x,
        'loop_state' => 'running', 'loop_card' => $x, 'loop_note' => $x, 'checklist_cards' => 2, 'checklist_passing' => 1,
        'error' => $x,
    ]], 'now' => fn() => [
        'sessions' => [['project' => $x, 'status' => 'waiting', 'waitingFor' => $x]],
        'loops' => [['project' => $x, 'state' => 'running', 'card' => $x]],
        'tasks' => [['name' => $x, 'failed' => true, 'lastResult' => $x]],
        'ports' => [['port' => $x, 'program' => $x]],
    ]], 'projectsPage');
    assert_true(!str_contains($body, '<img'), 'raw markup reached the page');
    $escaped = '&lt;img src=x onerror=alert(1)&gt;';
    // slug (row heading and data-slug), branch, subject, card, note, error; then six strip fields.
    assert_true(substr_count($body, $escaped) >= 13, 'escaped copies: ' . substr_count($body, $escaped));
    foreach (['<td class="subject">' . $escaped, 'class="error">' . $escaped, '<td>' . $escaped . '</td><td class="n">1</td>',
        'card ' . $escaped . ': ' . $escaped] as $want) {
        assert_true(str_contains($body, $want), "escaped in place: $want");
    }
});

test('now.js polls every 5 seconds and stops on visibilitychange', function () {
    $src = (string) file_get_contents(PUI_ASSETS . '/now.js');
    assert_true(str_contains($src, 'visibilitychange'), 'visibilitychange');
    assert_true(str_contains($src, '5000'), '5000');
    assert_true(str_contains($src, 'clearInterval'), 'stops');
    assert_true(str_contains((string) file_get_contents(PUI_ASSETS . '/projects.js'), '60000'), 'projects every 60s');
});

test('now.js and projects.js write with textContent only and send X-KC-Token on every fetch', function () {
    assert_same([], DomSinks::find(PUI_ASSETS));
    foreach (['now.js', 'projects.js'] as $name) {
        $src = (string) file_get_contents(PUI_ASSETS . '/' . $name);
        assert_true(str_contains($src, "from './app.js'"), "$name uses app.js el()");
        preg_match_all('/\bfetch\s*\(([^;]*)/', $src, $m);
        assert_true(count($m[1]) > 0, "$name has no fetch");
        foreach ($m[1] as $call) {
            assert_true(str_contains($call, "'X-KC-Token': token"), "$name: fetch without the token: $call");
        }
    }
});

test('app.css has no max-width media query and the map table scrolls inside its panel on a phone', function () {
    $css = (string) file_get_contents(PUI_ASSETS . '/app.css');
    assert_true(preg_match('/@media[^{]*max-width/i', $css) === 0, 'max-width media query');
    assert_true(preg_match('/\.table-wrap\s*\{[^}]*overflow-x:\s*auto/', $css) === 1, 'table scrolls in its own box');
    assert_true(preg_match('/\.map-table\s*\{[^}]*border-collapse/', $css) === 1, 'dense table');
});

test('a snapshot with arrays where strings belong still renders the page', function () {
    $deps = ['map' => fn() => pui_rows(), 'now' => fn() => [
        'sessions' => [['project' => ['x'], 'status' => 'busy', 'name' => 'fallback-name'], 'junk', ['status' => ['busy']]],
        'loops' => 'not a list',
        'tasks' => [['name' => ['a'], 'failed' => true, 'lastResult' => ['b']]],
        'ports' => [['port' => ['p'], 'program' => ['q']], 7],
    ]];
    $body = pui_render($deps, 'projectsPage');
    assert_same(['sembr', 'henike', 'kc-site'], pui_tiles($body));
    assert_true(str_contains(pui_strip($body), 'fallback-name'), 'the good field in a bad entry survives');
    $cc = pui_render($deps);
    assert_true(str_contains(pui_strip($cc), 'fallback-name') && str_contains($cc, 'id="agents"'), 'the command center renders too');
});

test('a snapshot over the byte cap, missing or not JSON counts as no snapshot', function () {
    $f = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcsnap_' . bin2hex(random_bytes(6));
    try {
        $small = json_encode(['sessions' => []]);
        file_put_contents($f, $small);
        assert_same(['sessions' => []], View::readSnapshot($f, strlen($small)));
        assert_same([], View::readSnapshot($f, strlen($small) - 1), 'one byte over the cap');
        file_put_contents($f, '{"x":"' . str_repeat('a', 500) . '"}');
        assert_same([], View::readSnapshot($f, 100), 'large file');
        file_put_contents($f, 'not json');
        assert_same([], View::readSnapshot($f));
        assert_same([], View::readSnapshot($f . '.missing'));
    } finally {
        @unlink($f);
    }
});
