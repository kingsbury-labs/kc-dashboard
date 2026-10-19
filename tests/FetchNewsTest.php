<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/News/FetchNews.php';
require_once __DIR__ . '/Fakes/FakeHttpClient.php';

const FN_FIX = __DIR__ . '/fixtures/feeds';
define('FN_NOW', strtotime('2026-10-19 12:00:00 UTC'));

function fn_db(): PDO
{
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    return $pdo;
}

function fn_feed(string $name, string $url, string $topic = 'dev'): array
{
    return ['name' => $name, 'url' => $url, 'topic' => $topic];
}

function fn_rows(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM news_items ORDER BY source, title')->fetchAll(PDO::FETCH_ASSOC);
}

function fn_feed_row(PDO $pdo, string $name): array
{
    $st = $pdo->prepare('SELECT * FROM news_feeds WHERE name = ?');
    $st->execute([$name]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

test('items are stored once across two runs, keeping first_seen and taking the new points', function () {
    $pdo = fn_db();
    $http = new FakeHttpClient();
    $http->willReturn('https://rss.example.test/feed', (string) file_get_contents(FN_FIX . '/rss.xml'));
    $http->willReturn('https://hn.example.test/search', (string) file_get_contents(FN_FIX . '/algolia.json'));
    $feeds = [fn_feed('RSS', 'https://rss.example.test/feed', 'php'),
        fn_feed('HN', 'https://hn.example.test/search') + ['format' => 'algolia']];
    $first = (new FetchNews($http, $pdo, $feeds))->run(FN_NOW);
    assert_same(['RSS' => 2, 'HN' => 2], $first);
    $http->willReturn('https://hn.example.test/search',
        str_replace('"points": 412', '"points": 500', (string) file_get_contents(FN_FIX . '/algolia.json')));
    (new FetchNews($http, $pdo, $feeds))->run(FN_NOW + 1800);
    $rows = fn_rows($pdo);
    assert_same(4, count($rows));
    $hn = array_values(array_filter($rows, fn($r) => $r['title'] === 'PHP 8.5 released'))[0];
    assert_same(500, (int) $hn['points']);
    assert_same(FN_NOW, (int) $hn['first_seen']);
    assert_same(FN_NOW + 1800, (int) $hn['last_seen']);
    assert_same('ok', fn_feed_row($pdo, 'HN')['status']);
});

test('a file:// or ftp:// feed url is refused without a fetch, and the next feed still runs', function () {
    $pdo = fn_db();
    $http = new FakeHttpClient();
    $http->willReturn('https://json.example.test/feed.json', (string) file_get_contents(FN_FIX . '/feed.json'));
    $feeds = [fn_feed('Local', 'file:///C:/Windows/win.ini'), fn_feed('Ftp', 'ftp://ftp.example.test/feed.xml'),
        fn_feed('Plain', 'http://plain.example.test/feed'), fn_feed('Json', 'https://json.example.test/feed.json')];
    $out = (new FetchNews($http, $pdo, $feeds))->run(FN_NOW);
    assert_same(['https://json.example.test/feed.json'], $http->calls);
    foreach (['Local', 'Ftp', 'Plain'] as $name) {
        assert_true(is_string($out[$name]), "$name was not refused");
        assert_same('error', fn_feed_row($pdo, $name)['status'], $name);
    }
    assert_same(1, $out['Json']);
});

test('an oversized response is skipped and records its error', function () {
    $pdo = fn_db();
    $http = new FakeHttpClient();
    $rss = (string) file_get_contents(FN_FIX . '/rss.xml');
    $http->willReturn('https://big.example.test/feed', str_replace('</channel>', str_repeat(' ', 5000) . '</channel>', $rss));
    $http->willReturn('https://small.example.test/feed', $rss);
    $out = (new FetchNews($http, $pdo, [fn_feed('Big', 'https://big.example.test/feed'),
        fn_feed('Small', 'https://small.example.test/feed')], 4096))->run(FN_NOW);
    assert_true(is_string($out['Big']), 'oversized feed was stored');
    assert_same(2, $out['Small']);
    assert_same(['Small'], array_values(array_unique(array_column(fn_rows($pdo), 'source'))));
    assert_same('error', fn_feed_row($pdo, 'Big')['status']);
});

test('a story url starting javascript: or data: is stored as null; a malformed feed stores nothing', function () {
    $pdo = fn_db();
    $http = new FakeHttpClient();
    $http->willReturn('https://links.example.test/feed', (string) file_get_contents(FN_FIX . '/bad-links.xml'));
    $http->willReturn('https://bad.example.test/feed', (string) file_get_contents(FN_FIX . '/malformed.xml'));
    $out = (new FetchNews($http, $pdo, [fn_feed('Links', 'https://links.example.test/feed'),
        fn_feed('Bad', 'https://bad.example.test/feed')]))->run(FN_NOW);
    assert_true(is_string($out['Bad']), 'malformed feed not recorded as an error');
    $urls = array_column(fn_rows($pdo), 'url', 'title');
    assert_same(3, count($urls));
    assert_same(null, $urls['A story whose link runs script']);
    assert_same(null, $urls['A story whose link is a data url']);
    assert_same('https://links.example.test/ok', $urls['A story with an ordinary link']);
});

test('a non-200 answer records an error, and items past the keep window are pruned', function () {
    $pdo = fn_db();
    $http = new FakeHttpClient();
    $http->willReturn('https://rss.example.test/feed', (string) file_get_contents(FN_FIX . '/rss.xml'));
    (new FetchNews($http, $pdo, [fn_feed('RSS', 'https://rss.example.test/feed')]))->run(FN_NOW);
    $http->willReturn('https://rss.example.test/feed', 'gone', 404);
    $out = (new FetchNews($http, $pdo, [fn_feed('RSS', 'https://rss.example.test/feed')]))->run(FN_NOW + FetchNews::KEEP + 1);
    assert_same('feed answered 404', $out['RSS']);
    assert_same(0, count(fn_rows($pdo)));
});

test('an item a feed still lists after its keep window is pruned on the same run, not stored fresh again', function () {
    $pdo = fn_db();
    $http = new FakeHttpClient();
    $http->willReturn('https://rss.example.test/feed', (string) file_get_contents(FN_FIX . '/rss.xml'));
    $later = strtotime('2026-10-17 09:00:00 UTC') + FetchNews::KEEP + 3600;
    $out = (new FetchNews($http, $pdo, [fn_feed('RSS', 'https://rss.example.test/feed')]))->run($later);
    assert_same(2, $out['RSS']);
    assert_same(['PHP 8.5 released with the pipe operator'], array_column(fn_rows($pdo), 'title'),
        'the item dated past the window came back fresh');
});
