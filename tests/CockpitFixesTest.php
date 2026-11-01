<?php
// Card 111: fixes from the first live look at the cockpit. Uses CockpitTest's ck_* world and server helpers.
require_once __DIR__ . '/../src/Cockpit/CockpitApi.php';

function cf_js(string $file): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/js/' . $file);
}

test('the snapshot API answers from cache without re-indexing: a second call does no transcript read', function () {
    [$t, $db, $dir] = ck_world();
    $deps = ck_deps($db, $dir, [], $opened) + ['cache' => $t . '/cockpit-cache.json'];
    [$first, $body] = ck_get($deps);
    assert_true($opened, 'the first call, with no cache, builds it');
    assert_true(is_file($t . '/cockpit-cache.json'), 'cache written');
    assert_true(count($first['sessions']) > 0, 'the build has sessions');
    $opened = false;
    [, $again] = ck_get($deps);
    assert_true(!$opened, 'the second call opened the index');
    assert_same($body, $again, 'the second call is the cached snapshot');
    assert_true(!CockpitApi::stale($deps), 'fresh inside the ttl');
    $later = ['clock' => fn(): int => CK_NOW + CockpitApi::TTL + 1] + $deps;
    assert_true(CockpitApi::stale($later), 'stale past the ttl');
    ck_get($later);
    assert_true(!$opened, 'a stale cache is still served without indexing');
    CockpitApi::refresh($later);
    assert_true($opened, 'refresh rebuilds it');
    assert_same(CK_NOW + CockpitApi::TTL + 1, json_decode((string) file_get_contents($t . '/cockpit-cache.json'), true)['at']);
});

test('a finance file with facts missing fields returns the good facts and no exception', function () {
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccf_' . bin2hex(random_bytes(6));
    mkdir($t);
    file_put_contents($t . '/alerts.json', json_encode(['schema' => 1, 'generated_at' => '2026-11-01T08:00:00Z', 'facts' => [
        ['id' => 'a', 'kind' => 'payment-review', 'subject' => null, 'due_date' => null, 'amount_cents' => null],
        ['id' => 'b', 'kind' => 'payment-split', 'subject' => 'Split Fixture', 'due_date' => '2026-11-03', 'amount_cents' => 5000, 'severity' => 'low'],
        ['kind' => 'todo', 'subject' => 'No id'],
        'not a fact',
        ['id' => 'c', 'kind' => 'offsite-stale', 'subject' => 'Offsite backup', 'due_date' => 'soon', 'amount_cents' => '12'],
    ]]));
    $f = CockpitDaily::finance($t . '/alerts.json', CK_NOW);
    assert_same(3, $f['alerts']);
    assert_same([['kind' => 'payment review', 'subject' => 'Payment to review', 'date' => null],
        ['kind' => 'payment split', 'subject' => 'Split Fixture', 'date' => '2026-11-03'],
        ['kind' => 'offsite stale', 'subject' => 'Offsite backup', 'date' => null]], $f['lines']);
    assert_same([null, null], [$f['receivables_cents'], $f['reserve_cents']], 'figures it lacks are null, a dash on the page');
    assert_same(['subject' => 'Split Fixture', 'due' => '2026-11-03', 'amount_cents' => 5000], $f['next_due']);
    $log = new Log($t . '/app.log');
    assert_true(CockpitDaily::build(['finance' => $t . '/alerts.json'], CK_NOW, $log)['finance'] !== null, 'the panel gets its block');
    assert_true(!is_file($t . '/app.log') || !str_contains((string) file_get_contents($t . '/app.log'), 'finance fact'), 'nothing logged');
});

test('an unknown 5-hour reading renders as an empty bar', function () {
    $src = cf_js('views.js');
    assert_true(str_contains($src, "fill.style.width = w ? `\${Math.min(100, Math.max(0, w.percent))}%` : '0%';"), 'unknown is 0%');
    assert_true(!str_contains($src, 'if (w) fill.style.width'), 'no width left to the CSS default of full');
});

test('a message starting with a brief path is shown with the card label in its place', function () {
    assert_same('card 14o, client message mail · Do the Round 2 section.',
        CockpitApi::briefLabel("D:\\Dev\\htdocs\\kc-portal\\.claude\\work\\14o-client-message-mail\\brief.md Do the Round 2 section."));
    assert_same('card 111, cockpit fixes', CockpitApi::briefLabel('D:/Dev/htdocs/_kc-dashboard/.claude/work/111-cockpit-fixes/brief.md'));
    assert_same('Read D:\\x\\.claude\\work\\9-a\\brief.md first', CockpitApi::briefLabel('Read D:\\x\\.claude\\work\\9-a\\brief.md first'), 'only a leading path');
    assert_same('Plain text', CockpitApi::briefLabel('Plain text'));
});

test('the top bar markup has no clock pill or avatar', function () {
    $src = cf_js('main.js');
    foreach (["'avatar'", "'RB'", 'range-btn', 'second:'] as $bad) {
        assert_true(!str_contains($src, $bad), "main.js still has $bad");
    }
    assert_true(str_contains($src, "el('time', 'topbar-time')"), 'plain text time');
    assert_true(str_contains($src, "hour: 'numeric', minute: '2-digit', hour12: true"), '12-hour, no leading zero, no seconds');
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/cockpit.css');
    assert_true(str_contains($css, '.topbar-time{margin-left:auto;color:var(--muted)') && str_contains($css, 'border:0;background:none}'), 'muted, no border or background');
});

test('Needs you shows at most 6 items until expanded, newest first, and panels stop on skeletons after 4 seconds', function () {
    $src = cf_js('views.js');
    assert_true(str_contains($src, 'const NEEDS_SHOWN = 6;'), 'six shown');
    assert_true(str_contains($src, 's.needs.slice(0, needsOpen ? s.needs.length : NEEDS_SHOWN)'), 'sliced until open');
    assert_true(str_contains($src, '`${s.needs.length} waiting, show all`'), 'the show all button');
    $data = cf_js('data.js');
    foreach (['const SKELETON_MS = 4000;', "'Could not load, retrying'", "'Still loading'", 'return !state.snap && !state.late && !state.error;'] as $want) {
        assert_true(str_contains($data, $want), "data.js lacks $want");
    }
    assert_true(!str_contains($src, 'skeleton(c, !state.snap)'), 'every panel uses skeletonOn');
    [, $db, $dir] = ck_world();
    $deps = ck_deps($db, $dir, ['sessions' => [['project' => 'alpha', 'status' => 'waiting', 'waitingFor' => 'a yes']]]);
    $ts = array_column(ck_get($deps)[0]['needs'], 'ts');
    $sorted = $ts;
    rsort($sorted);
    assert_same($sorted, $ts, 'newest first');
});
