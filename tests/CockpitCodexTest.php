<?php
require_once __DIR__ . '/../src/Cockpit/CockpitApi.php';

// Uses CockpitTest.php's helpers (ck_world, ck_deps, ck_get); they are functions, resolved when a test runs.
const CX_FIX = __DIR__ . '/fixtures/cockpit-codex';

test('plan limits carry the newest Codex file last reading and nothing else from it', function () {
    [, $db, $dir] = ck_world();
    $deps = ck_deps($db, $dir);
    $deps['daily'] = ['codex' => CX_FIX];
    [$s, $body] = ck_get($deps);
    $codex = $s['daily']['usage']['codex'] ?? null;
    assert_true($codex !== null, 'no codex entry: ' . json_encode($s['daily']['usage']));
    assert_same(['percent' => 23, 'resets' => 1793568898], $codex['five_hour']);
    assert_same(['percent' => 4, 'resets' => 1793900000], $codex['seven_day']);
    foreach (['CODEX-OLD', 'CODEX-NEW', 'CODEX-', 'credits', 'instructions', 'rollout-'] as $marker) {
        assert_true(!str_contains($body, $marker), "response carries $marker");
    }
    assert_same(['at', 'age', 'stale', 'five_hour', 'seven_day'], array_keys($codex));
});

test('no Codex logs leaves the Claude reading alone and no codex entry', function () {
    assert_same(null, CockpitDaily::codex(CX_FIX . '/nothing-here', CK_NOW));
    $u = CockpitDaily::build(['codex' => CX_FIX . '/nothing-here'], CK_NOW)['usage'];
    assert_same(null, $u);
});

test('a loop whose lock pid is dead and a session whose process is gone are not working', function () {
    [, $db, $dir] = ck_world();
    [$base] = ck_get(ck_deps($db, $dir));
    assert_same('working', $base['sessions'][0]['state'], 'the fixture loop pass is working without live data');
    $dead = ['sessions' => [], 'loops' => [['project' => 'alpha', 'state' => 'stale', 'card' => null, 'note' => null]]];
    [$s] = ck_get(ck_deps($db, $dir, $dead));
    assert_same('done', $s['sessions'][0]['state'], 'dead-pid loop');
    foreach ($s['agents'] as $a) {
        assert_true($a['state'] !== 'working', 'agent ' . $a['key'] . ' still working');
    }
    $alive = ['sessions' => [], 'loops' => [['project' => 'alpha', 'state' => 'running', 'card' => null, 'note' => null]]];
    [$s] = ck_get(ck_deps($db, $dir, $alive));
    assert_same('working', $s['sessions'][0]['state'], 'live loop stays working');
});

test('an interactive session with no registered process leaves Working now', function () {
    [$t, $db, $dir] = ck_world();
    // Drop the loop pass files so the alpha session is an interactive one.
    $proj = $t . '/projects';
    ck_copy(CK_FIX . '/projects', $proj);
    foreach (glob($proj . '/alpha/.claude/work/loop/*.jsonl') as $f) {
        unlink($f);
    }
    $deps = ck_deps($db, $dir, ['sessions' => [], 'loops' => []]);
    $deps['projects'] = fn(): array => ['alpha' => $proj . '/alpha'];
    [$s] = ck_get($deps);
    assert_same('interactive', $s['sessions'][0]['kind']);
    assert_same('idle', $s['sessions'][0]['state'], 'process gone');
    $deps = ck_deps($db, $dir, ['sessions' => [['project' => 'alpha', 'status' => 'idle']], 'loops' => []]);
    $deps['projects'] = fn(): array => ['alpha' => $proj . '/alpha'];
    [$s] = ck_get($deps);
    assert_same('working', $s['sessions'][0]['state'], 'registered process, recent write');
});
