<?php
// Card 112: the cockpit laid out for portrait, and kc-finance facts with no subject.
require_once __DIR__ . '/../src/Cockpit/CockpitDaily.php';
require_once __DIR__ . '/../src/Collectors/Finance.php';

function cp_views(): string
{
    return (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/js/views.js');
}

function cp_alerts(): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccp_' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($path, json_encode(['schema' => 1, 'generated_at' => '2026-11-01T08:00:00Z', 'facts' => [
        ['id' => 'r1', 'kind' => 'payment-review', 'subject' => null, 'severity' => 'med', 'due_date' => '2026-11-05',
            'amount_cents' => 12550, 'url' => null, 'evidence' => 'one payment unmatched'],
        ['id' => 'r2', 'kind' => 'offsite_stale', 'subject' => '', 'severity' => 'low', 'due_date' => null, 'amount_cents' => null],
    ]]));
    return $path;
}

test('a finance fact with a null subject is collected with a label from its kind', function () {
    $path = cp_alerts();
    $facts = (new Finance($path))->collect(strtotime('2026-11-01T10:00:00Z'));
    assert_same('Payment to review · $125.50 · 2026-11-05', $facts[0]['subject']);
    assert_same('Offsite stale', $facts[1]['subject'], 'an empty subject is labelled too');
    assert_same('payment-review', $facts[0]['kind'], 'the kind is unchanged');
    @unlink($path);
});

test('the cockpit finance reader labels a null subject from its kind and the panel shows it', function () {
    $path = cp_alerts();
    $f = CockpitDaily::finance($path, strtotime('2026-11-01T10:00:00Z'));
    assert_same(2, $f['alerts']);
    assert_same('Payment to review · $125.50 · 2026-11-05', $f['lines'][0]['subject']);
    assert_same('Payment to review · $125.50 · 2026-11-05', $f['next_due']['subject']);
    assert_true(str_contains(cp_views(), "el('b', '', x.subject)"), 'the panel draws each line subject');
    @unlink($path);
});

test('Agent talk renders at most 10 items until expanded and Working now at most 8', function () {
    $src = cp_views();
    assert_true(str_contains($src, 'const TALK_SHOWN = 10;'), 'ten talk items');
    assert_true(str_contains($src, 'items.slice(0, talkOpen ? items.length : TALK_SHOWN)'), 'talk sliced until open');
    assert_true(str_contains($src, '`Show ${items.length - TALK_SHOWN} more`'), 'the show more button');
    assert_true(str_contains($src, 'const WORKING_SHOWN = 8;') && str_contains($src, 'working.slice(0, WORKING_SHOWN)'), 'eight working');
    assert_true(str_contains($src, 'const EMAIL_SHOWN = 6;') && str_contains($src, 'rows.slice(0, emailOpen ? rows.length : EMAIL_SHOWN)'), 'six emails');
});

test('the portrait layout draws every overview card once, in the fit grid, with plan limits in the hero', function () {
    $src = cp_views();
    assert_true(preg_match('/function renderFit\(page\) \{(.*?)\r?\n\}\r?\n/s', $src, $m) === 1, 'portrait branch found');
    assert_true(preg_match('/add\(grid, ([^;]*)\);/', $m[1], $g) === 1, 'cards added to the grid');
    $placed = array_map('trim', explode(',', str_replace('()', '', $g[1])));
    sort($placed);
    $want = ['agentTalkCard', 'agentsCard', 'calendarCard', 'careCard', 'emailCard', 'financeCard', 'followCard', 'needsCard', 'newsCard'];
    assert_same($want, $placed, 'each card once');
    assert_true(str_contains($m[1], 'add(side, weatherCard(), planCard());'), 'plan limits beside the weather in the hero');
});
