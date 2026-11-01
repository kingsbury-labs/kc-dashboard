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

test('the portrait layout places every overview card in one of two columns', function () {
    $src = cp_views();
    assert_true(preg_match('/if \(portrait\) \{(.*?)\} else \{/s', $src, $m) === 1, 'portrait branch found');
    preg_match_all('/add\((a|b), ([^;]*)\);/', $m[1], $cols, PREG_SET_ORDER);
    $placed = [];
    foreach ($cols as [, $col, $args]) {
        foreach (array_map('trim', explode(',', $args)) as $x) {
            $placed[$x] = $col;
        }
    }
    foreach (['needsCard()', 'careCard()', 'calendarCard()', 'email', 'follow', 'agentTalkCard()', 'agentsCard()', 'finance', 'news'] as $card) {
        assert_true(isset($placed[$card]), "$card is not in a portrait column");
    }
    assert_same(9, count($placed), 'each card once');
    $css = (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/cockpit.css');
    assert_true(str_contains($css, '@media(min-width:1000px) and (max-aspect-ratio:4/5){.daily-cols{grid-template-columns:repeat(2,minmax(0,1fr))}'), 'two columns in portrait');
    assert_true(str_contains($css, '.daily-top .plan-card{grid-column:2;grid-row:3'), 'plan limits under the weather');
});
