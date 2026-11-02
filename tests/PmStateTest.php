<?php
require_once __DIR__ . '/../src/Pm/PmState.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

const PMS_NOW = 1800000000;
const PMS_TASKLIST = 'C:\\Windows\\System32\\tasklist.exe';
const PMS_DECOY = 'INVENTED-DECOY-9f3a77';

function pms_rmtree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir($dir . '\\' . $f) ? pms_rmtree($dir . '\\' . $f) : @unlink($dir . '\\' . $f);
        }
    }
    @rmdir($dir);
}

function pms_put(string $path, string $body): void
{
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $body);
}

/**
 * Builds a fixture root with a pm folder, a sessions folder and one project `alpha`, runs PmState, returns
 * [state, opened, root]. $o: asks, refusals, project_md, lock_age (seconds since loop.log), brief, brief_dir,
 * status (array), session (array|null).
 */
function pms_run(array $o): array
{
    $root = sys_get_temp_dir() . '\\kcpm_' . bin2hex(random_bytes(6));
    $pm = $root . '\\pm';
    $alpha = $root . '\\projects\\alpha';
    $sess = $root . '\\sessions';
    mkdir($pm, 0777, true);
    mkdir($sess, 0777, true);
    pms_put($pm . '\\asks.md', $o['asks'] ?? '');
    if (isset($o['refusals'])) {
        pms_put($pm . '\\conch-refusals.jsonl', $o['refusals']);
    }
    pms_put($pm . '\\conch.json', '{"project":"alpha","path":"D:\\\\x","since":"2026-11-01","by":"voice"}');
    // Decoys: files a reader must never open, one in every folder it touches.
    foreach (['go.key', 'voice-token', 'settings.json'] as $d) {
        foreach ([$pm, $sess, $alpha . '\\.claude'] as $where) {
            pms_put($where . '\\' . $d, file_get_contents(__DIR__ . '/fixtures/pm-state/decoys/' . $d));
        }
    }
    $loop = $alpha . '\\.claude\\work\\loop';
    mkdir($loop, 0777, true);
    $loopRunner = new FakeProcessRunner();
    if (isset($o['lock_age'])) {
        pms_put($loop . '\\lock', '4242');
        pms_put($loop . '\\loop.log', 'x');
        touch($loop . '\\loop.log', PMS_NOW - $o['lock_age']);
        pms_put($loop . '\\status.json', json_encode($o['status'] ?? ['card' => '7']));
        $loopRunner->willReturn(0, file_get_contents(__DIR__ . '/fixtures/projects/tasklist-node.csv'));
    }
    if (isset($o['brief'])) {
        pms_put($alpha . '\\.claude\\work\\' . ($o['brief_dir'] ?? '7-thing') . '\\brief.md', $o['brief']);
    }
    pms_put($alpha . '\\.claude\\project.md', $o['project_md'] ?? "# alpha\n\n**Risky paths**: `src/Http/`, `ops/`.\n\n**Never**: x\n");
    $sessRunner = new FakeProcessRunner();
    if (isset($o['session'])) {
        pms_put($sess . '\\4001.json', json_encode($o['session'] + ['cwd' => $alpha, 'procStartFt' => '100']));
        $sessRunner->willReturn(0, "4001,0,100\r\n");
    }
    try {
        $state = new PmState(
            ['asks' => $pm . '\\asks.md', 'conch' => $pm . '\\conch.json', 'refusals' => $pm . '\\conch-refusals.jsonl',
                'go_pending' => $root . '\\go-pending.json'],
            ['alpha' => $alpha],
            new LoopState($loopRunner, PMS_TASKLIST),
            new SessionRegistry($sess, $sessRunner, 'C:\\Windows\\System32\\WindowsPowerShell\\v1.0\\powershell.exe', ['alpha' => $alpha]));
        $out = $state->build(PMS_NOW);
        return [$out, $state->opened, $root];
    } finally {
        pms_rmtree($root);
    }
}

function pms_types(array $state, string $type): array
{
    return array_values(array_filter($state['findings'], fn($f) => $f['type'] === $type));
}

test('a live lock with loop.log 31 minutes old is loop_stalled', function () {
    [$s] = pms_run(['lock_age' => 31 * 60]);
    $f = pms_types($s, 'loop_stalled');
    assert_same(1, count($f));
    assert_same('alpha', $f[0]['project']);
});

test('a live lock with loop.log 29 minutes old is not loop_stalled', function () {
    [$s] = pms_run(['lock_age' => 29 * 60]);
    assert_same([], pms_types($s, 'loop_stalled'));
    assert_same('running', $s['loops'][0]['state']);
});

test('a Normal card at 61 minutes is card_oversized, at 59 it is not', function () {
    $brief = "# 7. Thing (Normal)\n\nNothing risky.\n";
    [$s] = pms_run(['lock_age' => 60, 'brief' => $brief, 'status' => ['card' => '7', 'started_at' => PMS_NOW - 61 * 60]]);
    $f = pms_types($s, 'card_oversized');
    assert_same(1, count($f));
    assert_same(['Normal', 60], [$f[0]['size'], $f[0]['limit']]);
    [$s] = pms_run(['lock_age' => 60, 'brief' => $brief, 'status' => ['card' => '7', 'started_at' => PMS_NOW - 59 * 60]]);
    assert_same([], pms_types($s, 'card_oversized'));
});

test('a Small brief naming src/Http/ is card_oversized', function () {
    [$s] = pms_run(['lock_age' => 60, 'brief' => "# 7. Thing (Small)\n\nEdit `src/Http/Guard.php`.\n",
        'status' => ['card' => '7', 'started_at' => PMS_NOW - 60]]);
    $f = pms_types($s, 'card_oversized');
    assert_same(1, count($f));
    assert_same('src/Http/', $f[0]['risky_path']);
});

test('a Small brief naming no risky path is not card_oversized', function () {
    [$s] = pms_run(['lock_age' => 60, 'brief' => "# 7. Thing (Small)\n\nEdit src/Pm/X.php.\n",
        'status' => ['card' => '7', 'started_at' => PMS_NOW - 60]]);
    assert_same([], pms_types($s, 'card_oversized'));
});

test('a conch refusal 5 minutes ago is chrome_without_conch, one 20 minutes ago is not', function () {
    $line = fn(int $ago) => json_encode(['at' => gmdate('c', PMS_NOW - $ago), 'cwd' => 'D:\\x', 'session_id' => 's', 'tool' => 'mcp__chrome__tabs']) . "\n";
    [$s] = pms_run(['refusals' => $line(300)]);
    $f = pms_types($s, 'chrome_without_conch');
    assert_same(1, count($f));
    assert_same('mcp__chrome__tabs', $f[0]['tool']);
    [$s] = pms_run(['refusals' => $line(20 * 60)]);
    assert_same([], pms_types($s, 'chrome_without_conch'));
});

test('a missing conch refusal log is no finding', function () {
    [$s] = pms_run([]);
    assert_same([], pms_types($s, 'chrome_without_conch'));
});

test('a 35 minute idle session in a project with an open ask is idle_with_open_work', function () {
    $session = ['status' => 'idle', 'updatedAt' => (PMS_NOW - 35 * 60) * 1000];
    [$s] = pms_run(['asks' => "fix the alpha footer\n", 'session' => $session]);
    $f = pms_types($s, 'idle_with_open_work');
    assert_same(1, count($f));
    assert_same(35, $f[0]['idle_minutes']);
    [$s] = pms_run(['asks' => "something about beta\n", 'session' => $session]);
    assert_same([], pms_types($s, 'idle_with_open_work'), 'no ask for alpha');
    [$s] = pms_run(['asks' => "fix the alpha footer\n", 'session' => ['updatedAt' => (PMS_NOW - 10 * 60) * 1000] + $session]);
    assert_same([], pms_types($s, 'idle_with_open_work'), 'only 10 minutes idle');
});

test('CLOSED asks are left out and no idle finding comes from them', function () {
    $session = ['status' => 'idle', 'updatedAt' => (PMS_NOW - 35 * 60) * 1000];
    [$s] = pms_run(['asks' => "alpha footer ask\nalpha old ask CLOSED 2026-10-30\n\n", 'session' => $session]);
    assert_same(['alpha footer ask'], $s['asks']);
    [$s] = pms_run(['asks' => "alpha old ask CLOSED\n", 'session' => $session]);
    assert_same([], $s['asks']);
    assert_same([], pms_types($s, 'idle_with_open_work'));
});

test('the conch holder and unpassed checklist cards are reported', function () {
    [$s] = pms_run([]);
    assert_same('alpha', $s['conch']['project']);
    assert_same(null, $s['go_pending']);
});

test('nothing outside the fixture roots is opened and no key, token or settings file is read', function () {
    $session = ['status' => 'idle', 'updatedAt' => (PMS_NOW - 35 * 60) * 1000];
    [$s, $opened, $root] = pms_run(['asks' => "alpha ask\n", 'lock_age' => 60, 'session' => $session,
        'brief' => "# 7. T (Small)\n", 'status' => ['card' => '7'],
        'refusals' => json_encode(['at' => PMS_NOW - 60, 'cwd' => 'x', 'tool' => 't']) . "\n"]);
    assert_true(count($opened) > 4, 'it opened something');
    foreach ($opened as $path) {
        assert_true(stripos($path, $root . '\\') === 0, 'outside the root: ' . $path);
        assert_true(!preg_match('/\.key$|token|settings/i', basename($path)), 'opened a secret file: ' . $path);
    }
    assert_true(!str_contains(json_encode($s), PMS_DECOY), 'a decoy value reached the output');
});
