<?php
require_once __DIR__ . '/../src/Cockpit/CockpitApi.php';

define('CN_NOW', (int) strtotime('2026-11-01T10:05:00Z'));

// A temp database holding the fixture rows; each row's last field is its age in seconds.
function cn_pdo(): PDO
{
    $t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kccn_' . bin2hex(random_bytes(6));
    mkdir($t, 0777, true);
    $pdo = Db::open($t . '/dashboard.sqlite');
    Db::migrate($pdo);
    $ins = $pdo->prepare('INSERT INTO news_items (id, source, topic, title, url, canon, published_at, points, first_seen, last_seen) VALUES (?, ?, ?, ?, ?, NULL, ?, NULL, ?, ?)');
    foreach (json_decode((string) file_get_contents(__DIR__ . '/fixtures/cockpit-news/items.json'), true) as $i => $r) {
        $ts = CN_NOW - $r[4];
        $ins->execute(["id$i", $r[0], $r[1], $r[2], $r[3], $ts, $ts, $ts]);
    }
    return $pdo;
}

test('CockpitNews: at most 6 ai stories, newest first for equal sources, other topics out', function () {
    $news = CockpitDaily::news(cn_pdo(), CN_NOW);
    assert_same(6, count($news));
    assert_same(['Claude gets a bigger context window', 'OpenAI ships a new reasoning model'],
        array_slice(array_column($news, 'title'), 0, 2));
    assert_same([], array_filter(array_column($news, 'url'), fn($u) => $u === 'https://example.com/w1'));
});

test('CockpitNews: slugs, versions, bare names and Quoting titles are dropped', function () {
    $titles = array_column(CockpitDaily::news(cn_pdo(), CN_NOW), 'title');
    foreach (['Quoting Kyle Kingsbury', 'dbreunig/nesformer', 'datasette-llm 0.1a9', 'nemotron-halloween'] as $bad) {
        assert_same(false, in_array($bad, $titles, true), "$bad dropped");
    }
});

test('CockpitNews: TitleFilter keeps real headlines', function () {
    assert_same(true, TitleFilter::keep('Claude 4.5 is out today'));
    assert_same(true, TitleFilter::keep('Why v2.0 matters'));
    assert_same(false, TitleFilter::keep('tool v1.2.3'));
});

test('CockpitNews: the feed list no longer uses atom/everything', function () {
    assert_same(false, str_contains((string) file_get_contents(__DIR__ . '/../config/feeds.php'), 'atom/everything'));
});
