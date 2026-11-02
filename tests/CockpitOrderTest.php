<?php
require_once __DIR__ . '/../src/Cockpit/CockpitDay.php';
require_once __DIR__ . '/../src/Cockpit/CockpitDaily.php';
require_once __DIR__ . '/../src/Cockpit/CockpitCare.php';

// Card 120: every list Rob reads top down comes newest first; agenda next-up first, follow-ups newest first (no item has a due date).
const CORD_NOW = 1_800_000_000;

test('agenda: on now and upcoming soonest first, finished events below, latest first', function () {
    $ev = fn($s, $e, $t) => ['start' => CORD_NOW + $s, 'end' => CORD_NOW + $e, 'title' => $t];
    $out = CockpitDay::agenda([$ev(-7200, -3600, 'early'), $ev(7200, 9000, 'later'), $ev(-1800, -900, 'just done'),
        $ev(1800, 3600, 'next'), $ev(-600, 600, 'on now')], CORD_NOW);
    assert_same(['on now', 'next', 'later', 'just done', 'early'], array_column($out, 'title'));
});

test('follow-ups: replies newest first, open items before ticked ones, newest first', function () {
    $out = CockpitDay::followOrder(
        [['subject' => 'new', 'date' => CORD_NOW - 60], ['subject' => 'old', 'date' => CORD_NOW - 86400], ['subject' => 'undated', 'date' => null]],
        [['id' => 'a', 'at' => CORD_NOW - 500, 'done' => null], ['id' => 'b', 'at' => CORD_NOW - 900, 'done' => CORD_NOW - 10],
            ['id' => 'c', 'at' => CORD_NOW - 100, 'done' => null], ['id' => 'd', 'at' => CORD_NOW - 800, 'done' => CORD_NOW - 5]]);
    assert_same(['new', 'old', 'undated'], array_column($out['threads'], 'subject'));
    assert_same(['c', 'a', 'd', 'b'], array_column($out['items'], 'id'));
});

test('email summaries newest first, undated last', function () {
    $out = CockpitDay::newestFirst([['s' => 'mid', 'date' => 2], ['s' => 'none', 'date' => null], ['s' => 'new', 'date' => 3], ['s' => 'old', 'date' => 1]], 'date');
    assert_same(['new', 'mid', 'old', 'none'], array_column($out, 's'));
});

test('AI news newest hour first, Scorer order only within the same hour', function () {
    $h = 3600 * 500_000;
    $ranked = [['title' => 'top score, old', 'date' => $h - 7200], ['title' => 'this hour B', 'date' => $h + 100],
        ['title' => 'last hour', 'date' => $h - 10], ['title' => 'this hour A', 'date' => $h + 3000]];
    assert_same(['this hour B', 'this hour A', 'last hour', 'top score, old'], array_column(CockpitDaily::newestHour($ranked), 'title'));
});

test('care alerts newest first', function () {
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcco_' . bin2hex(random_bytes(6));
    mkdir($t);
    $src = (string) file_get_contents(dirname(__DIR__) . '/src/Cockpit/CockpitCare.php');
    assert_true(str_contains($src, "usort(\$incidents, fn(\$a, \$b) => (\$b['since'] ?? PHP_INT_MIN) <=> (\$a['since'] ?? PHP_INT_MIN));"), 'incidents unsorted');
});

test('agent talk, needs you, files and session talk come newest first from the server and stay so in the view', function () {
    $api = (string) file_get_contents(dirname(__DIR__) . '/src/Cockpit/CockpitApi.php');
    assert_true(str_contains($api, 'FROM messages WHERE ts >= ? ORDER BY ts DESC'), 'talk');
    assert_true(str_contains($api, 'GROUP BY path, op ORDER BY ts DESC'), 'files');
    assert_true(str_contains($api, "usort(\$out, fn(\$a, \$b) => \$b['ts'] <=> \$a['ts']);"), 'needs');
    $views = (string) file_get_contents(dirname(__DIR__) . '/public/cockpit/js/views.js');
    assert_true(!str_contains($views, '.reverse()'), 'a view reverses a newest-first list');
});
