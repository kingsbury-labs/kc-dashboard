<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Briefing/Composer.php';

define('BRIEF_NOW', strtotime('2026-10-19 06:15:00'));

function brief_world(): array
{
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kcbrief_' . bin2hex(random_bytes(6));
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    return [$pdo, new Vault($root), $root];
}

function brief_rmtree(string $dir): void
{
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f !== '.' && $f !== '..') {
            is_dir("$dir/$f") ? brief_rmtree("$dir/$f") : @unlink("$dir/$f");
        }
    }
    @rmdir($dir);
}

function brief_fact(string $id, string $subject = 'Renew the certificate'): array
{
    return ['id' => $id, 'source' => 'ssl', 'kind' => 'ssl_expiry', 'subject' => $subject, 'due_date' => '2026-10-30',
        'severity' => 'high', 'amount_cents' => null, 'evidence' => 'expires in 11 days', 'url' => null];
}

function brief_input(array $over = []): array
{
    $items = array_map(fn($i) => brief_fact("ssl:site-$i.example"), range(1, 5));
    $news = array_map(fn($i) => ['title' => "Story $i", 'sources' => ['hn'], 'url' => null], range(1, 8));
    return $over + [
        'attention' => ['items' => $items, 'also' => [brief_fact('eol:php-8.1'), brief_fact('eol:node-18')], 'sources' => []],
        'ranking' => [],
        'projects' => [
            ['slug' => 'sembr', 'last_date' => date('c', BRIEF_NOW - 3600), 'last_subject' => 'Card 12: invoices', 'loop_state' => 'running',
                'loop_card' => '12', 'checklist_passing' => 9],
            ['slug' => 'old-site', 'last_date' => date('c', BRIEF_NOW - 5 * 86400), 'last_subject' => 'old', 'loop_state' => 'none',
                'loop_card' => null, 'checklist_passing' => null],
        ],
        'news' => $news,
        'numbers' => [['name' => 'ga4:kingsbury-creative', 'values' => ['sessions' => ['value' => 1240, 'label' => null],
            'top_page:01' => ['value' => 610, 'label' => '/secret-path']]]],
    ];
}

// The lines of one "## " section.
function brief_section(string $md, string $title): array
{
    $out = [];
    $in = false;
    foreach (explode("\n", $md) as $line) {
        if (str_starts_with($line, '## ')) {
            $in = $line === '## ' . $title;
            continue;
        }
        if ($in && $line !== '') {
            $out[] = $line;
        }
    }
    return $out;
}

test('the briefing holds every attention fact id given even when the fake read omits two', function () {
    [$pdo, $vault, $root] = brief_world();
    try {
        $in = brief_input();
        $ids = array_column($in['attention']['items'], 'id');
        // The read ranked three of the five and gave each a reason; two it left out.
        $in['ranking'] = [$ids[4] => ['rank' => 1, 'reason' => 'Expires first'], $ids[0] => ['rank' => 2, 'reason' => null],
            $ids[2] => ['rank' => 3, 'reason' => 'Client site']];
        $b = (new Composer($pdo, $vault))->build($in, BRIEF_NOW);
        $file = (string) file_get_contents($root . '\\Briefings\\2026-10-19.md');
        assert_same($b['body'], $file);
        $lines = brief_section($file, 'Attention');
        foreach ($ids as $i => $id) {
            assert_true(str_contains($lines[$i], 'id ' . Vault::neutralise($id)), "fact $id listed in code order");
        }
        assert_true(str_contains($lines[4], 'read: Expires first'), 'the read supplies the reason');
        assert_true(in_array('Also seen: 2', $lines, true), 'also seen count');
        assert_same(array_merge($ids, ['eol:php-8.1', 'eol:node-18']), $b['facts'], 'every id stored');
        $none = (new Composer($pdo, $vault))->compose(['ranking' => []] + $in, BRIEF_NOW);
        foreach ($ids as $id) {
            assert_true(str_contains($none['body'], Vault::neutralise($id)), "no read still lists $id");
        }
    } finally {
        brief_rmtree($root);
    }
});

test('a second run the same day overwrites one file and leaves yesterday untouched', function () {
    [$pdo, $vault, $root] = brief_world();
    try {
        $c = new Composer($pdo, $vault);
        $c->build(brief_input(), BRIEF_NOW - 86400);
        $yesterday = $root . '\\Briefings\\2026-10-18.md';
        $before = [md5_file($yesterday), filemtime($yesterday)];
        $c->build(brief_input(), BRIEF_NOW);
        $in = brief_input();
        $in['attention']['items'][0]['subject'] = 'Second run';
        $c->build($in, BRIEF_NOW + 3600);
        $files = array_map('basename', glob($root . '\\Briefings\\*.md'));
        sort($files);
        assert_same(['2026-10-18.md', '2026-10-19.md'], $files);
        assert_true(str_contains((string) file_get_contents($root . '\\Briefings\\2026-10-19.md'), 'Second run'), 'today overwritten');
        clearstatcache();
        assert_same($before, [md5_file($yesterday), filemtime($yesterday)], 'yesterday untouched');
        assert_same(['2026-10-19', '2026-10-18'], array_column($c->history(), 'day'));
        assert_same(BRIEF_NOW + 3600, $c->get('2026-10-19')['created_at']);
    } finally {
        brief_rmtree($root);
    }
});

test('at most five headlines, and loops, commits and moved numbers come from code', function () {
    [$pdo, $vault, $root] = brief_world();
    try {
        $c = new Composer($pdo, $vault);
        $c->build(brief_input(), BRIEF_NOW - 86400);
        $in = brief_input();
        $in['projects'][0]['checklist_passing'] = 12;
        $in['numbers'][0]['values']['sessions']['value'] = 1300;
        $md = $c->build($in, BRIEF_NOW)['body'];
        $heads = brief_section($md, 'Headlines');
        assert_same(5, count($heads));
        assert_same('- Story 1 · hn', $heads[0]);
        assert_same(['- sembr · running · card 12 · 12 passing (+3 since last briefing)'], brief_section($md, 'Loops'));
        assert_same(['- sembr · Card 12: invoices'],brief_section($md, 'Commits since yesterday'));
        assert_same(['- ga4' . "\u{A789}" . 'kingsbury-creative sessions' . ': 1240 to 1300'], brief_section($md, 'Numbers that moved'));
        assert_true(!str_contains($md, 'secret-path'), 'a GA page path label never reaches the briefing');
    } finally {
        brief_rmtree($root);
    }
});

test('untrusted fields are neutralised in the briefing file', function () {
    [$pdo, $vault, $root] = brief_world();
    try {
        $in = brief_input();
        $in['attention']['items'][0]['subject'] = '[[evil]] ![x](https://evil.example) <b>';
        $in['attention']['items'][1]['evidence'] = 'obsidian://open?vault=x file:///c:/x \\\\host\\share';
        $in['news'][0]['title'] = '[click](www.evil.example) `code`';
        $in['projects'][0]['last_subject'] = '[[x]] //evil';
        $in['ranking'] = ['ssl:site-3.example' => ['rank' => 1, 'reason' => '[[read]]']];
        $md = (new Composer($pdo, $vault))->build($in, BRIEF_NOW)['body'];
        foreach (['[', ']', '<', '`', 'https:', 'obsidian:', 'file:', 'www.', '//', '\\\\'] as $bad) {
            assert_true(!str_contains($md, $bad), "briefing holds $bad");
        }
    } finally {
        brief_rmtree($root);
    }
});

test('gather reads the database without starting anything', function () {
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    $g = Composer::gather($pdo, BRIEF_NOW);
    assert_same(['attention', 'ranking', 'projects', 'news', 'numbers'], array_keys($g));
    assert_same([[], [], [], []], [$g['ranking'], $g['projects'], $g['news'], $g['numbers']]);
});
