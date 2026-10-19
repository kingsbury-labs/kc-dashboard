<?php
require_once __DIR__ . '/../src/Projects/LoopState.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

const LS_TASKLIST = 'C:\\Windows\\System32\\tasklist.exe';

// A temp project with the given files under .claude/work/, keyed by path relative to it.
function ls_project(array $files): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcls_' . bin2hex(random_bytes(6));
    mkdir($dir . '\\.claude\\work\\loop', 0777, true);
    foreach ($files as $rel => $body) {
        file_put_contents($dir . '\\.claude\\work\\' . $rel, $body);
    }
    return $dir;
}

function ls_rmtree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir($dir . '\\' . $f) ? ls_rmtree($dir . '\\' . $f) : @unlink($dir . '\\' . $f);
        }
    }
    @rmdir($dir);
}

function ls_tasklist(string $fixture): FakeProcessRunner
{
    $runner = new FakeProcessRunner();
    $runner->willReturn(0, file_get_contents(__DIR__ . '/fixtures/projects/' . $fixture));
    return $runner;
}

function ls_read(array $files, FakeProcessRunner $runner): array
{
    $dir = ls_project($files);
    try {
        return (new LoopState($runner, LS_TASKLIST))->read($dir);
    } finally {
        ls_rmtree($dir);
    }
}

test('a lock with a live node.exe PID and no STOP is running with the card from status.json', function () {
    $runner = ls_tasklist('tasklist-node.csv');
    $s = ls_read(['loop\\lock' => '4242', 'loop\\status.json' => '{"status":"gate","card":"73","note":"gating"}'], $runner);
    assert_same('running', $s['loop_state']);
    assert_same('73', $s['loop_card']);
    assert_same('gating', $s['loop_note']);
    assert_same([LS_TASKLIST, '/fo', 'csv', '/nh', '/fi', 'PID eq 4242'], $runner->calls[0]['argv'] ?? null, 'tasklist argv');
});

test('a dead PID is stale', function () {
    $s = ls_read(['loop\\lock' => '4242', 'loop\\status.json' => '{"card":"73"}'], ls_tasklist('tasklist-none.txt'));
    assert_same('stale', $s['loop_state']);
    assert_same(null, $s['loop_card'], 'a stale loop names no card');
});

test('a live PID that is not node.exe is stale', function () {
    assert_same('stale', ls_read(['loop\\lock' => '4242'], ls_tasklist('tasklist-php.csv'))['loop_state']);
});

test('lock plus STOP is stopped', function () {
    assert_same('stopped', ls_read(['loop\\lock' => '4242', 'loop\\STOP' => ''], ls_tasklist('tasklist-node.csv'))['loop_state']);
});

test('lock plus STOP with a dead PID is stale, as henike-contracting has it', function () {
    assert_same('stale', ls_read(['loop\\lock' => '34704', 'loop\\STOP' => ''], ls_tasklist('tasklist-none.txt'))['loop_state']);
});

test('no lock is none and starts no tasklist', function () {
    $runner = new FakeProcessRunner();
    $s = ls_read(['loop\\STOP' => '', 'loop\\status.json' => '{"card":"9"}'], $runner);
    assert_same('none', $s['loop_state']);
    assert_same(null, $s['loop_card']);
    assert_same([], $runner->calls);
});

test('a lock that is not a PID is stale and starts no tasklist', function () {
    $runner = new FakeProcessRunner();
    assert_same('stale', ls_read(['loop\\lock' => '42 & calc'], $runner)['loop_state']);
    assert_same([], $runner->calls);
});

test('a checklist of 5 entries with 3 passing gives 5 and 3', function () {
    $list = json_encode([['id' => '1', 'passes' => true], ['id' => '2', 'passes' => false], ['id' => '3', 'passes' => true],
        ['id' => '4'], ['id' => '5', 'passes' => true]]);
    $s = ls_read(['checklist.json' => $list], new FakeProcessRunner());
    assert_same(5, $s['checklist_cards']);
    assert_same(3, $s['checklist_passing']);
    $none = ls_read([], new FakeProcessRunner());
    assert_same([null, null], [$none['checklist_cards'], $none['checklist_passing']], 'no checklist');
});

test('malformed status.json gives running with no card, not an exception', function () {
    $s = ls_read(['loop\\lock' => '4242', 'loop\\status.json' => '{"card": "7'], ls_tasklist('tasklist-node.csv'));
    assert_same('running', $s['loop_state']);
    assert_same(null, $s['loop_card']);
    assert_same(null, $s['loop_note']);
});

test('a tasklist that fails throws instead of calling the loop dead', function () {
    $runner = new FakeProcessRunner();
    $runner->willReturn(1, '', 'ERROR');
    $threw = false;
    try {
        ls_read(['loop\\lock' => '4242'], $runner);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    assert_true($threw, 'expected a throw');
});
