<?php
require_once __DIR__ . '/../src/View.php';
require_once __DIR__ . '/Support/DomSinks.php';

const CC_XSS = '<img src=x onerror=alert(1)>';
const CC_PANELS = ['rail', 'systems', 'clock', 'core', 'overview', 'feed', 'agents', 'timeline', 'commands', 'monitor', 'memory',
    'llm', 'talk'];

function cc_page(array $deps = []): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccc_' . bin2hex(random_bytes(6));
    mkdir($dir);
    file_put_contents($dir . '/token', str_repeat('6a', 32));
    try {
        [$status, , $body] = View::page(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => '127.0.0.1:8787', 'REQUEST_METHOD' => 'GET'],
            $dir . '/token', new Log($dir . '/log'), $deps, strtotime('2026-10-19T12:00:00+00:00'));
    } finally {
        @unlink($dir . '/token');
        @unlink($dir . '/log');
        @rmdir($dir);
    }
    assert_same(200, $status);
    return $body;
}

function cc_snapshot(): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/fixtures/running/command-center.json'), true);
}

// [kind, state, name] for every agent tile, in page order.
function cc_agents(string $body): array
{
    preg_match_all('#<li class="agent" data-kind="([^"]*)" data-state="([^"]*)">.*?<p class="a-name">([^<]*)</p>#', $body, $m, PREG_SET_ORDER);
    return array_map(fn($x) => [$x[1], $x[2], $x[3]], $m);
}

// The inner HTML of the element with this id, up to its matching section close.
function cc_panel(string $body, string $id): string
{
    assert_same(1, preg_match('#id="' . $id . '"(.*?)</section>#s', $body, $m), "panel $id");
    return $m[1];
}

test('the no-JS page has every panel of the brief by id', function () {
    $body = cc_page(['now' => fn() => cc_snapshot()]);
    foreach (CC_PANELS as $id) {
        assert_same(1, substr_count($body, 'id="' . $id . '"'), "#$id");
    }
    assert_true(str_contains($body, '<title>KC Command Center</title>'), 'named KC Command Center');
    assert_true(preg_match('/<script(?![^>]*type="module")/', $body) === 0 && !str_contains($body, 'style='), 'no inline script or style');
    preg_match_all('#<h[1-6][^>]*>(.*?)</h[1-6]>#s', $body, $h);
    foreach ($h[1] as $heading) {
        assert_true(!str_contains(strip_tags($heading), ','), "comma in heading: $heading");
    }
});

test('two sessions, one loop and one failed collector render four agent tiles with the right states', function () {
    $body = cc_page(['now' => fn() => cc_snapshot()]);
    assert_same([
        ['session', 'busy', 'alpha'],
        ['session', 'waiting', 'beta'],
        ['loop', 'busy', 'sembr'],
        ['collector', 'failed', 'collect-facts'],
    ], cc_agents($body));
    $agents = cc_panel($body, 'agents');
    assert_true(str_contains($agents, 'waiting: permission') && str_contains($agents, 'card 41: building'), 'tasks shown');
    assert_true(str_contains($agents, '2 busy of 4'), 'busy count');
    assert_same(4, substr_count($agents, '<span class="wave"'), 'one waveform per tile');
    assert_true(str_contains($body, 'stroke-dasharray="15 100"') && str_contains($body, '>54%<') && str_contains($body, '>40%<'), 'gauges');
    assert_true(str_contains(cc_panel($body, 'llm'), '<p class="p-state">Off</p>'), 'llama-server off');
    $stale = cc_snapshot();
    $stale['stale'] = true;
    assert_same('stale', cc_agents(cc_page(['now' => fn() => $stale]))[0][1], 'a stale snapshot marks sessions stale');
});

test('a panel with no source renders not connected', function () {
    $body = cc_page();
    foreach (['monitor', 'memory', 'llm', 'overview'] as $id) {
        assert_true(str_contains(cc_panel($body, $id), 'not connected'), "#$id says not connected");
    }
    assert_true(str_contains($body, 'id="f-traffic">not connected'), 'traffic');
    assert_same(3, substr_count(cc_panel($body, 'monitor'), '>--<'), 'no invented gauge values');
    assert_true(str_contains(cc_panel($body, 'systems'), 'Fault'), 'no attention data is a fault');
    $broken = cc_page(['now' => fn() => throw new RuntimeException('x'), 'core' => fn() => throw new RuntimeException('y')]);
    assert_true(str_contains(cc_panel($broken, 'monitor'), 'not connected'), 'failing sources');
});

test('every agent name and feed title holding markup renders escaped', function () {
    $x = CC_XSS;
    $snap = ['sessions' => [['project' => $x, 'status' => 'busy', 'name' => $x], ['name' => $x, 'status' => 'waiting', 'waitingFor' => $x]],
        'loops' => [['project' => $x, 'state' => 'running', 'card' => $x, 'note' => $x]],
        'tasks' => [['name' => $x, 'failed' => true, 'lastResult' => $x, 'nextRun' => $x]],
        'ports' => [['port' => 1, 'program' => $x]]];
    $facts = ['items' => [['id' => 'a', 'source' => $x, 'subject' => $x, 'severity' => 'high', 'due_date' => '2026-10-20', 'evidence' => $x]],
        'also' => [], 'sources' => [['name' => $x, 'status' => 'ok', 'last_ok_at' => 1, 'last_error' => $x, 'stale' => false]]];
    $body = cc_page(['now' => fn() => $snap, 'attention' => fn() => $facts,
        'clients' => fn() => ['clients' => [['client' => $x, 'milestone' => $x, 'due' => '2026-10-21', 'links' => []]], 'leads' => []]]);
    assert_true(!str_contains($body, '<img'), 'raw markup reached the page');
    $escaped = '&lt;img src=x onerror=alert(1)&gt;';
    assert_same(4, substr_count(cc_panel($body, 'agents'), '<p class="a-name">' . $escaped . '</p>'), 'agent names');
    assert_true(str_contains(cc_panel($body, 'feed'), '<p class="subject">' . $escaped . '</p>'), 'feed title');
    assert_true(str_contains(cc_panel($body, 'timeline'), '<p class="m-what">' . $escaped . '</p>'), 'timeline title');
    assert_true(str_contains(cc_panel($body, 'feed'), 'data-tag="WARN">WARN</span>'), 'high is WARN');
});

test('quick commands are buttons or forms that post, never GET links that start work', function () {
    $cmds = cc_panel(cc_page(), 'commands');
    preg_match_all('#<a\b[^>]*href="([^"]*)"#', $cmds, $links);
    foreach ($links[1] as $href) {
        assert_true(in_array($href, ['#ask-question', '/wall.php'], true), "link that may start work: $href");
    }
    preg_match_all('#<form\b[^>]*>#', $cmds, $forms);
    foreach ($forms[0] as $form) {
        assert_true(str_contains($form, 'method="post"'), "form without POST: $form");
    }
    assert_same(2, substr_count($cmds, '<button type="button"'), 'brief me and run collectors are buttons');
    assert_true(!str_contains($cmds, '/api/'), 'an API url in quick commands');
});

test('the timeline lists due dates and scheduled runs with countdowns', function () {
    $snap = cc_snapshot();
    $snap['tasks'][] = ['name' => 'daily-read', 'failed' => false, 'status' => 'Ready', 'nextRun' => '2026-10-19 12:42:00 UTC'];
    $body = cc_page(['now' => fn() => $snap, 'attention' => fn() => ['items' => [['id' => 'b', 'subject' => 'Invoice due',
        'severity' => 'med', 'due_date' => '2026-10-22', 'source' => 'finance']], 'also' => [], 'sources' => []]]);
    $tl = cc_panel($body, 'timeline');
    assert_true(str_contains($tl, 'daily-read') && str_contains($tl, 'in 42 min'), 'scheduled run with countdown');
    assert_true(str_contains($tl, 'Invoice due') && strpos($tl, 'daily-read') < strpos($tl, 'Invoice due'), 'soonest first');
});

test('the systems pill follows coverage health', function () {
    $src = fn(array $s) => ['items' => [], 'also' => [], 'sources' => $s];
    $ok = ['name' => 'ssl', 'status' => 'ok', 'last_ok_at' => 1, 'last_error' => null, 'stale' => false];
    $pill = fn(array $d) => cc_panel(cc_page(['attention' => fn() => $d]), 'systems');
    assert_true(str_contains($pill($src([$ok])), 'data-health="optimal"'), 'optimal');
    assert_true(str_contains($pill($src([$ok, ['stale' => true] + $ok])), 'data-health="degraded"'), 'degraded');
    assert_true(str_contains($pill($src([['stale' => true] + $ok])), 'data-health="fault"'), 'fault');
});

test('coreData reads the version beside claude.exe, the vault counts, and nulls what it cannot read', function () {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccore_' . bin2hex(random_bytes(6));
    mkdir($dir . '\\cc\\bin', 0777, true);
    mkdir($dir . '\\vault\\Projects', 0777, true);
    mkdir($dir . '\\vault\\Briefings', 0777, true);
    file_put_contents($dir . '\\cc\\package.json', '{"version":"2.1.9"}');
    file_put_contents($dir . '\\cc\\bin\\claude.exe', '');
    file_put_contents($dir . '\\vault\\Projects\\a.md', 'x');
    file_put_contents($dir . '\\vault\\Briefings\\2026-10-19.md', 'x');
    try {
        $d = View::coreData(null, $dir . '\\cc\\bin\\claude.exe', $dir . '\\vault');
        assert_same(['present' => true, 'version' => '2.1.9'], $d['claude']);
        assert_same(2, $d['vault']['notes']);
        assert_same(1, $d['vault']['briefings']);
        assert_same(null, $d['read']);
        assert_same(null, $d['memory']);
        $none = View::coreData(fn() => throw new RuntimeException('no db'), $dir . '\\missing\\bin\\claude.exe', $dir . '\\nope');
        assert_same(['present' => false, 'version' => null], $none['claude']);
        assert_same([null, null], [$none['vault'], $none['queue']]);
    } finally {
        foreach (['\\cc\\package.json', '\\cc\\bin\\claude.exe', '\\vault\\Projects\\a.md', '\\vault\\Briefings\\2026-10-19.md'] as $f) {
            @unlink($dir . $f);
        }
        foreach (['\\cc\\bin', '\\cc', '\\vault\\Projects', '\\vault\\Briefings', '\\vault', ''] as $d) {
            @rmdir($dir . $d);
        }
    }
});

test('core.js rebuilds agents with textContent, and DomSinks is clean', function () {
    $src = (string) file_get_contents(__DIR__ . '/../public/assets/core.js');
    foreach (['export function applyNow', 'setAgents', "from './app.js'", 'visibilitychange'] as $want) {
        assert_true(str_contains($src, $want), "core.js holds $want");
    }
    assert_true(str_contains((string) file_get_contents(__DIR__ . '/../public/assets/now.js'), 'applyNow(data)'), 'now.js feeds it');
    assert_same([], DomSinks::find(__DIR__ . '/../public/assets'));
});
