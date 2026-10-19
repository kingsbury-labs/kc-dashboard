<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Attention/DailyRead.php';
require_once __DIR__ . '/../src/Attention/Selector.php';
require_once __DIR__ . '/Fakes/FakeProcessRunner.php';

// 2026-10-01 07:00 local time, the hour the read runs.
define('DR_NOW', strtotime('2026-10-01 07:00:00'));
const DR_BOUNDARY = 'KC-DATA-DAILYREADTEST';

/** @return array{0: PDO, 1: FakeProcessRunner, 2: DailyRead} */
function dr_world(): array
{
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    $fake = new FakeProcessRunner();
    $runner = new ClaudeRunner(fn(?string $root, array $env): ProcessRunner => $fake, 'C:\\fake\\claude.exe');
    return [$pdo, $fake, new DailyRead($pdo, $runner, fn(): string => DR_BOUNDARY)];
}

function dr_fact(PDO $pdo, string $name, int $now, array $over = []): void
{
    (new FactStore($pdo))->record($over + ['id' => "ssl:$name", 'source' => 'ssl', 'kind' => 'cert_expiry',
        'subject' => "Renew $name", 'salient_hash' => "h-$name", 'due_date' => '2026-10-20', 'severity' => 'med',
        'evidence' => "notAfter for $name", 'url' => "https://$name.example/", 'parser_version' => 'ssl/1'], $now);
}

// What claude prints for a reply whose result text is $result.
function dr_reply(FakeProcessRunner $fake, string $result, array $usage = ['input_tokens' => 120, 'output_tokens' => 45]): void
{
    $fake->willReturn(0, json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => $result,
        'usage' => $usage]));
}

function dr_items(array $items): string
{
    return json_encode(['items' => $items]);
}

// The JSON inside the data block of the $n-th call.
function dr_sent(FakeProcessRunner $fake, int $n): array
{
    $stdin = $fake->calls[$n]['stdin'];
    $parts = explode(DR_BOUNDARY . "\n", $stdin);
    assert_same(3, count($parts), 'one data block');
    return json_decode(trim($parts[1]), true);
}

function dr_reads(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM reads ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
}

test('only facts newer than the last read are sent, the rest by id and state, in untrusted mode', function () {
    [$pdo, $fake, $read] = dr_world();
    dr_fact($pdo, 'alpha', DR_NOW - 3600);
    dr_fact($pdo, 'beta', DR_NOW - 3600);
    dr_fact($pdo, 'gone', DR_NOW - 3600);
    (new FactStore($pdo))->setState('ssl:gone', 'dismissed');
    dr_reply($fake, dr_items([['id' => 'ssl:alpha', 'rank' => 1, 'reason' => 'Due soonest.']]));
    assert_same('ok', $read->run(DR_NOW)['status']);
    $first = dr_sent($fake, 0);
    assert_same(['ssl:alpha', 'ssl:beta'], array_column($first['new'], 'id'), 'first read sends every visible fact');
    assert_same([], $first['running']);
    assert_same('Renew alpha', $first['new'][0]['subject']);
    assert_true(!array_key_exists('url', $first['new'][0]), 'no url is sent');

    // A day later: alpha re-collected unchanged, beta's severity rose, one new fact.
    $next = DR_NOW + 86400;
    dr_fact($pdo, 'alpha', $next - 600);
    dr_fact($pdo, 'beta', $next - 600, ['severity' => 'high']);
    dr_fact($pdo, 'gamma', $next - 600);
    dr_reply($fake, dr_items([]));
    $r = $read->run($next);
    assert_same('ok', $r['status']);
    assert_same(3, $r['sent']);
    $second = dr_sent($fake, 1);
    assert_same(['ssl:beta', 'ssl:gamma'], array_column($second['new'], 'id'));
    assert_same([['id' => 'ssl:alpha', 'state' => 'new', 'severity' => 'med', 'due_date' => '2026-10-20']], $second['running']);
    assert_true(!str_contains($fake->calls[1]['stdin'], 'gone'), 'a dismissed fact is never sent');
    assert_same(['ssl:beta', 'ssl:gamma', 'ssl:alpha'], json_decode(dr_reads($pdo)[1]['ids_sent'], true));

    $argv = $fake->calls[0]['argv'];
    $tools = array_search('--tools', $argv, true);
    assert_true($tools !== false && $argv[$tools + 1] === '', '--tools ""');
    assert_true(in_array('--restricted', $argv, true) && in_array('--strict-mcp-config', $argv, true), 'untrusted flags');
    assert_true(str_starts_with($fake->calls[0]['stdin'], Prompt::INSTRUCTION), 'Prompt instruction first');

    // Nothing new: no call at all.
    assert_same('nothing new', $read->run($next + 3600)['status']);
    assert_same(2, count($fake->calls));
});

test('an invented id in the reply is dropped, and a repeat keeps its first rank', function () {
    [$pdo, $fake, $read] = dr_world();
    dr_fact($pdo, 'alpha', DR_NOW - 60);
    dr_fact($pdo, 'beta', DR_NOW - 60);
    dr_reply($fake, dr_items([
        ['id' => 'ssl:invented', 'rank' => 1, 'reason' => 'Made up.'],
        ['id' => 'ssl:beta', 'rank' => 2, 'reason' => 'Second.'],
        ['id' => 'ssl:alpha', 'rank' => 3],
        ['id' => 'ssl:beta', 'rank' => 4, 'reason' => 'Again.'],
        ['id' => 'ssl:alpha ', 'rank' => 5],
    ]));
    $read->run(DR_NOW);
    assert_same(['ssl:beta' => ['rank' => 1, 'reason' => 'Second.'], 'ssl:alpha' => ['rank' => 2, 'reason' => null]], $read->ranking());
    assert_true(!str_contains((string) dr_reads($pdo)[0]['ranking'], 'invented'), 'stored ranking');
    $annotated = $read->annotate((new FactStore($pdo))->visible(DR_NOW));
    assert_same(['ssl:alpha', 'ssl:beta'], array_column($annotated, 'id'), 'no invented fact appears');
});

test('a fact the reply omits or ranks last is still returned by Selector', function () {
    [$pdo, $fake, $read] = dr_world();
    foreach (['a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7'] as $n) {
        dr_fact($pdo, $n, DR_NOW - 60);
    }
    dr_reply($fake, dr_items([['id' => 'ssl:a1', 'rank' => 1, 'reason' => 'Only this.'], ['id' => 'ssl:a7', 'rank' => 99]]));
    $read->run(DR_NOW);
    $store = new FactStore($pdo);
    $picked = Selector::select($read->annotate($store->visible(DR_NOW)));
    $ids = array_merge(array_column($picked['shown'], 'id'), array_column($picked['also'], 'id'));
    sort($ids);
    assert_same(['ssl:a1', 'ssl:a2', 'ssl:a3', 'ssl:a4', 'ssl:a5', 'ssl:a6', 'ssl:a7'], $ids);
    assert_same(5, count($picked['shown']));
    foreach ($ids as $id) {
        assert_same('new', $store->get($id)['state'], $id . ' state untouched by the read');
    }
});

test('a URL in a reason is not stored as a url, and the reason is dropped while the fact stays', function () {
    [$pdo, $fake, $read] = dr_world();
    dr_fact($pdo, 'alpha', DR_NOW - 60);
    dr_fact($pdo, 'beta', DR_NOW - 60, ['url' => null]);
    dr_reply($fake, dr_items([
        ['id' => 'ssl:alpha', 'rank' => 1, 'reason' => 'Renew at https://evil.example/pay now.', 'url' => 'https://evil.example/'],
        ['id' => 'ssl:beta', 'rank' => 2, 'reason' => 'Click javascript:alert(1) or www.evil.example/x'],
    ]));
    $read->run(DR_NOW);
    $store = new FactStore($pdo);
    assert_same('https://alpha.example/', $store->get('ssl:alpha')['url']);
    assert_same(null, $store->get('ssl:beta')['url']);
    $ranking = $read->ranking();
    assert_same(['rank' => 1, 'reason' => null], $ranking['ssl:alpha']);
    assert_same(['rank' => 2, 'reason' => null], $ranking['ssl:beta']);
    assert_true(!array_key_exists('url', $ranking['ssl:alpha']), 'a model url field is dropped');
    $stored = (string) dr_reads($pdo)[0]['ranking'];
    assert_true(!str_contains($stored, 'evil.example') && !str_contains($stored, 'javascript:'), 'stored ranking');
    foreach ($read->annotate($store->visible(DR_NOW)) as $f) {
        assert_true(!str_contains((string) $f['url'], 'evil'), 'annotated url');
    }
});

test('every link shape fails the reason closed, and a plain reason survives', function () {
    $shapes = [
        '_https://x.example/_', '_www.x.example', 'pay at https:x.example/pay', 'https:/x.example', 'http:\\\\x.example',
        'see //x.example/x', 'open search-ms:query=x', 'run ms-msdt:/id', 'call tel:5551234', 'open \\\\host\\share',
        'visit evil.example today', 'WWW.EVIL', "split\x00https://x.example",
    ];
    foreach ($shapes as $s) {
        assert_same(null, DailyRead::scrubReason($s), $s);
    }
    assert_same('Due in 6 days, renew before Friday (high).', DailyRead::scrubReason('Due in 6 days, renew before Friday (high).'));
    assert_same('Overdue: invoice 1042 from last month.', DailyRead::scrubReason("Overdue: invoice 1042\nfrom last month."));

    // Through a whole read: the bad reason goes, the fact and its rank stay.
    [$pdo, $fake, $read] = dr_world();
    dr_fact($pdo, 'alpha', DR_NOW - 60);
    dr_fact($pdo, 'beta', DR_NOW - 60);
    dr_reply($fake, dr_items([
        ['id' => 'ssl:alpha', 'rank' => 1, 'reason' => 'Open \\\\host\\share now.'],
        ['id' => 'ssl:beta', 'rank' => 2, 'reason' => 'Renew this week.'],
    ]));
    $read->run(DR_NOW);
    assert_same(['ssl:alpha' => ['rank' => 1, 'reason' => null], 'ssl:beta' => ['rank' => 2, 'reason' => 'Renew this week.']],
        $read->ranking());
});

test('the data block is capped, and a fact over the cap goes in the next read and stays on the page', function () {
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    $fake = new FakeProcessRunner();
    $runner = new ClaudeRunner(fn(?string $root, array $env): ProcessRunner => $fake, 'C:\\fake\\claude.exe');
    $read = new DailyRead($pdo, $runner, fn(): string => DR_BOUNDARY, 2000);
    $long = str_repeat('k', 300);
    foreach (['a1', 'a2', 'a3', 'a4', 'a5'] as $n) {
        dr_fact($pdo, $n, DR_NOW - 60, ['kind' => $long, 'subject' => str_repeat('s', 300), 'evidence' => str_repeat('e', 900)]);
    }
    dr_fact($pdo, 'urgent', DR_NOW - 60, ['severity' => 'high']);
    dr_reply($fake, dr_items([]));
    $r = $read->run(DR_NOW);
    $data = trim(explode(DR_BOUNDARY . "\n", $fake->calls[0]['stdin'])[1]);
    assert_true(strlen($data) <= 2000, 'data block is ' . strlen($data) . ' bytes');
    $first = dr_sent($fake, 0)['new'];
    assert_same('ssl:urgent', $first[0]['id'], 'most pressing first');
    assert_same(200, mb_strlen($first[1]['kind']), 'kind capped');
    assert_same(200, mb_strlen($first[1]['subject']), 'subject capped');
    assert_same(500, mb_strlen($first[1]['evidence']), 'evidence capped');
    $sent1 = array_column($first, 'id');
    assert_true(count($sent1) < 6, 'some facts are over the cap');
    assert_same(count($sent1), $r['sent']);
    $picked = Selector::select($read->annotate((new FactStore($pdo))->visible(DR_NOW)));
    assert_same(6, count($picked['shown']) + count($picked['also']), 'every fact is still on the page');

    // Each later read sends what did not fit, until every fact has been sent once.
    $seen = $sent1;
    for ($day = 1; $day <= 5 && count($seen) < 6; $day++) {
        dr_reply($fake, dr_items([]));
        $read->run(DR_NOW + $day * 86400);
        $more = array_column(dr_sent($fake, $day)['new'], 'id');
        assert_true($more !== [] && array_intersect($more, $seen) === [], 'day ' . $day . ' sends only what was left');
        $seen = array_merge($seen, $more);
    }
    sort($seen);
    assert_same(['ssl:a1', 'ssl:a2', 'ssl:a3', 'ssl:a4', 'ssl:a5', 'ssl:urgent'], $seen);
});

test('the prompt hash, the ids sent and usage are saved', function () {
    [$pdo, $fake, $read] = dr_world();
    dr_fact($pdo, 'alpha', DR_NOW - 60);
    dr_reply($fake, dr_items([['id' => 'ssl:alpha', 'rank' => 1]]), ['input_tokens' => 321, 'output_tokens' => 7]);
    $read->run(DR_NOW);
    $row = dr_reads($pdo)[0];
    assert_same(hash('sha256', $fake->calls[0]['stdin']), $row['prompt_hash']);
    assert_same(['input_tokens' => 321, 'output_tokens' => 7], json_decode($row['usage'], true));
    assert_same(['ssl:alpha'], json_decode($row['ids_sent'], true));
    assert_same(DR_NOW, (int) $row['created_at']);
    assert_same(null, $row['error']);
});

test('invalid JSON leaves the previous ranking, and the next read sends the same facts again', function () {
    [$pdo, $fake, $read] = dr_world();
    dr_fact($pdo, 'alpha', DR_NOW - 60);
    dr_reply($fake, dr_items([['id' => 'ssl:alpha', 'rank' => 1, 'reason' => 'First.']]));
    $read->run(DR_NOW);
    $before = $read->ranking();
    $next = DR_NOW + 86400;
    dr_fact($pdo, 'beta', $next - 60);
    foreach (['{"items": [', '[{"id": "ssl:beta", "rank": 1}]', '{"items": "ssl:beta"}'] as $i => $bad) {
        dr_reply($fake, $bad);
        $r = $read->run($next + $i);
        assert_same('failed', $r['status'], $bad);
        assert_same($before, $read->ranking(), $bad . ' kept the ranking');
    }
    $rows = dr_reads($pdo);
    assert_same(4, count($rows), 'each failed read is stored');
    assert_same(null, $rows[3]['ranking']);
    assert_true($rows[3]['error'] !== null, 'error stored');
    // A failed call is not a read that knew anything: beta is still new.
    dr_reply($fake, dr_items([]));
    $read->run($next + 10);
    assert_same(['ssl:beta'], array_column(dr_sent($fake, 4)['new'], 'id'));
});

test('due is false once a read, good or failed, has run today', function () {
    [$pdo, $fake, $read] = dr_world();
    assert_true($read->due(DR_NOW), 'no read yet');
    dr_fact($pdo, 'alpha', DR_NOW - 60);
    $fake->willReturn(1, 'boom');
    assert_same('failed', $read->run(DR_NOW)['status']);
    assert_true(!$read->due(DR_NOW + 3600), 'same day');
    assert_true($read->due(strtotime('2026-10-02 00:00:01')), 'next day');
});
