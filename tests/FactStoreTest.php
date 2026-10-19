<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Attention/FactStore.php';

// 2026-10-01 noon, local time: every test runs on this clock or a day count from it.
define('FS_NOW', strtotime('2026-10-01 12:00:00'));

function fs_store(): FactStore
{
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    return new FactStore($pdo);
}

function fs_fact(array $over = []): array
{
    return $over + ['id' => 'ssl:mail.sembr.co', 'source' => 'ssl', 'kind' => 'cert_expiry', 'subject' => 'Renew mail.sembr.co',
        'salient_hash' => 'h1', 'due_date' => '2026-12-31', 'severity' => 'med', 'evidence' => 'notAfter 2026-12-31',
        'url' => 'https://mail.sembr.co', 'parser_version' => 'ssl/1'];
}

function fs_day(int $days): int
{
    return FS_NOW + $days * 86400;
}

test('the same id twice is one row, and each collection is an observation', function () {
    $s = fs_store();
    $s->record(fs_fact(), FS_NOW);
    $s->record(fs_fact(['subject' => 'Renew it now']), fs_day(1));
    $pdo = (new ReflectionProperty($s, 'pdo'))->getValue($s);
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM facts')->fetchColumn());
    assert_same(2, (int) $pdo->query('SELECT COUNT(*) FROM observations')->fetchColumn());
    assert_same('Renew it now', $s->get('ssl:mail.sembr.co')['subject'], 'updated in place');
});

test('an unchanged hash keeps a dismissed fact dismissed; a changed hash returns it to new', function () {
    $s = fs_store();
    $s->record(fs_fact(['due_date' => null]), FS_NOW);
    $s->setState('ssl:mail.sembr.co', 'dismissed');
    $s->record(fs_fact(['due_date' => null, 'evidence' => 'same thing, new words']), fs_day(1));
    assert_same('dismissed', $s->get('ssl:mail.sembr.co')['state']);
    $s->record(fs_fact(['due_date' => null, 'salient_hash' => 'h2']), fs_day(2));
    assert_same('new', $s->get('ssl:mail.sembr.co')['state']);
});

test('a severity rise re-surfaces a dismissed fact; a fall does not', function () {
    $s = fs_store();
    $s->record(fs_fact(['due_date' => null]), FS_NOW);
    $s->setState('ssl:mail.sembr.co', 'dismissed');
    $s->record(fs_fact(['due_date' => null, 'severity' => 'low']), fs_day(1));
    assert_same('dismissed', $s->get('ssl:mail.sembr.co')['state'], 'fall');
    $s->record(fs_fact(['due_date' => null, 'severity' => 'high']), fs_day(2));
    assert_same('new', $s->get('ssl:mail.sembr.co')['state'], 'rise');
});

test('a snooze hides a fact until snoozed_until on a fixed clock', function () {
    $s = fs_store();
    $s->record(fs_fact(['due_date' => null]), FS_NOW);
    $s->snooze('ssl:mail.sembr.co', fs_day(3));
    assert_same([], $s->visible(FS_NOW), 'hidden now');
    assert_same([], $s->visible(fs_day(3) - 1), 'hidden one second before');
    assert_same(['ssl:mail.sembr.co'], array_column($s->visible(fs_day(3)), 'id'), 'back at snoozed_until');
    $s->setState('ssl:mail.sembr.co', 'dismissed');
    assert_same([], $s->visible(fs_day(10)), 'dismissed stays hidden');
});

test('the 30, 7 and 1 days-out crossings re-surface a fact once each', function () {
    $s = fs_store();
    $id = 'ssl:mail.sembr.co';
    $due = date('Y-m-d', fs_day(40));
    $s->record(fs_fact(['due_date' => $due]), FS_NOW);
    // [days after FS_NOW, state after re-collecting]. Dismissed again after every step.
    $steps = [[1, 'dismissed'], [10, 'new'], [11, 'dismissed'], [33, 'new'], [35, 'dismissed'], [39, 'new'], [40, 'dismissed'],
        [45, 'dismissed']];
    foreach ($steps as [$day, $want]) {
        $s->setState($id, 'dismissed');
        $s->record(fs_fact(['due_date' => $due]), fs_day($day));
        assert_same($want, $s->get($id)['state'], "day $day (" . (40 - $day) . ' days out)');
    }
});

test('feedback saves the label with the hash and refuses an unknown reason or fact', function () {
    $s = fs_store();
    $s->record(fs_fact(), FS_NOW);
    assert_true(!$s->feedback('ssl:mail.sembr.co', 'meh', FS_NOW), 'unknown reason');
    assert_true(!$s->feedback('nope', 'useful', FS_NOW), 'unknown fact');
    assert_same([], $s->labels('ssl:mail.sembr.co'));
    assert_true($s->feedback('ssl:mail.sembr.co', 'useful', FS_NOW));
    assert_same([['fact_id' => 'ssl:mail.sembr.co', 'salient_hash' => 'h1', 'reason' => 'useful', 'created_at' => FS_NOW]],
        array_map(fn($r) => ['fact_id' => $r['fact_id'], 'salient_hash' => $r['salient_hash'], 'reason' => $r['reason'],
            'created_at' => (int) $r['created_at']], $s->labels('ssl:mail.sembr.co')));
    assert_same('new', $s->get('ssl:mail.sembr.co')['state'], 'useful changes nothing');
    $s->feedback('ssl:mail.sembr.co', 'resolved', FS_NOW);
    assert_same('acted', $s->get('ssl:mail.sembr.co')['state']);
});

function fs_log(): Log
{
    return new Log(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcfs_unused.log');
}

function fs_refused(FactStore $s, array $fact, string $why): void
{
    try {
        $s->record($fact, FS_NOW);
    } catch (InvalidArgumentException $e) {
        return;
    }
    throw new RuntimeException("recorded: $why");
}

test('one source cannot write or take over another source\'s fact', function () {
    $s = fs_store();
    $s->record(fs_fact(), FS_NOW);
    fs_refused($s, fs_fact(['source' => 'finance']), 'ssl id from finance');
    fs_refused($s, fs_fact(['id' => 'finance:x']), 'finance id from ssl');
    fs_refused($s, fs_fact(['id' => 'sslx']), 'no colon');
    $pdo = (new ReflectionProperty($s, 'pdo'))->getValue($s);
    $pdo->exec("UPDATE facts SET source = 'other' WHERE id = 'ssl:mail.sembr.co'");
    fs_refused($s, fs_fact(['salient_hash' => 'h9']), 'stored source differs');
    assert_same(['other', 'h1'], [$s->get('ssl:mail.sembr.co')['source'], $s->get('ssl:mail.sembr.co')['salient_hash']]);
    assert_same(1, (int) $pdo->query('SELECT COUNT(*) FROM observations')->fetchColumn(), 'refused records leave no observation');
});

test('an id that could never be labelled or cleared is refused', function () {
    $s = fs_store();
    fs_refused($s, fs_fact(['id' => 'ssl:' . str_repeat('a', 509)]), '513 bytes');
    fs_refused($s, fs_fact(['id' => "ssl:caf\xc3"]), 'invalid UTF-8');
    fs_refused($s, fs_fact(['id' => "ssl:a\nb"]), 'newline');
    fs_refused($s, fs_fact(['id' => "ssl:a\x7fb"]), 'DEL');
    $s->record(fs_fact(['id' => 'ssl:' . str_repeat('a', 508)]), FS_NOW);
    $s->record(fs_fact(['id' => 'ssl:café']), FS_NOW);
    assert_same(2, count($s->visible(FS_NOW)), '512 bytes and UTF-8 accepted');
});

test('a due date must be a real date with nothing after it', function () {
    $s = fs_store();
    foreach (['2026-02-30', '2026-13-01', "2026-10-01\n", '2026-10-1', '26-10-01'] as $bad) {
        fs_refused($s, fs_fact(['due_date' => $bad]), var_export($bad, true));
    }
    $s->record(fs_fact(['due_date' => '2028-02-29']), FS_NOW);
    assert_same('2028-02-29', $s->get('ssl:mail.sembr.co')['due_date']);
});

test('a due date moved later starts its marks over: 5 days out, pushed to 90, re-surfaces at 30', function () {
    $s = fs_store();
    $id = 'ssl:mail.sembr.co';
    $s->record(fs_fact(['due_date' => date('Y-m-d', fs_day(5))]), FS_NOW);
    $s->setState($id, 'dismissed');
    $later = date('Y-m-d', fs_day(90));
    $s->record(fs_fact(['due_date' => $later]), fs_day(1));
    assert_same('dismissed', $s->get($id)['state'], 'the move alone');
    $s->record(fs_fact(['due_date' => $later]), fs_day(59));
    assert_same('dismissed', $s->get($id)['state'], '31 days out');
    $s->record(fs_fact(['due_date' => $later]), fs_day(60));
    assert_same('new', $s->get($id)['state'], '30 days out');
});

test('a last good run dated more than five minutes ahead is stale', function () {
    $s = fs_store();
    $s->sourceOk('ahead', FS_NOW + 301);
    $s->sourceOk('slack', FS_NOW + 300);
    assert_same(['ahead' => true, 'slack' => false], array_column($s->sources(FS_NOW), 'stale', 'name'));
});

test('a failed run\'s error is redacted before it is stored', function () {
    $s = fs_store();
    $log = fs_log();
    $secret = str_repeat('9f', 32);
    $log->addSecret($secret);
    $s->sourceFailed('finance', "auth failed for $secret", $log);
    $stored = array_column($s->sources(FS_NOW), 'last_error', 'name')['finance'];
    assert_true(!str_contains($stored, $secret) && str_contains($stored, 'auth failed for'), $stored);
});

test('a source with no good run, or one older than its maximum age, is stale', function () {
    $s = fs_store();
    $s->sourceOk('finance', FS_NOW);
    $s->sourceOk('ssl', FS_NOW);
    $s->sourceFailed('dashboard', 'file missing', fs_log());
    $by = fn(int $now) => array_column($s->sources($now), 'stale', 'name');
    assert_same(['dashboard' => true, 'finance' => false, 'ssl' => false], $by(FS_NOW + 3600));
    assert_same(['dashboard' => true, 'finance' => false, 'ssl' => true], $by(FS_NOW + 25 * 3600));
    assert_same(true, $by(FS_NOW + 26 * 3600 + 1)['finance'], 'finance after 26h');
    $s->sourceFailed('ssl', 'timeout', fs_log());
    $ssl = array_column($s->sources(FS_NOW), null, 'name')['ssl'];
    assert_same([FS_NOW, 'timeout', 'error'], [$ssl['last_ok_at'], $ssl['last_error'], $ssl['status']], 'a failure keeps the last good time');
});
