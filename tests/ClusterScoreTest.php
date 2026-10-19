<?php
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/News/Clusterer.php';
require_once __DIR__ . '/../src/News/Scorer.php';

define('CS_NOW', strtotime('2026-10-19 12:00:00 UTC'));

// first_seen is the date unless $seenHoursAgo says otherwise.
function cs_item(string $source, string $title, ?string $url, int $hoursAgo = 1, ?int $points = null, string $topic = 'ai',
    ?int $seenHoursAgo = null): array
{
    return ['source' => $source, 'topic' => $topic, 'title' => $title, 'url' => $url, 'canon' => Clusterer::canonical($url),
        'date' => CS_NOW - $hoursAgo * 3600, 'points' => $points, 'first_seen' => CS_NOW - ($seenHoursAgo ?? $hoursAgo) * 3600];
}

function cs_story(int $sources, int $points, int $hoursAgo): array
{
    return ['sources' => array_map(fn($i) => "s$i", range(1, $sources)), 'points' => $points, 'date' => CS_NOW - $hoursAgo * 3600];
}

test('three sources on one story form one cluster', function () {
    $stories = Clusterer::cluster([
        cs_item('OpenAI', 'Introducing GPT-6 with a new reasoning mode', 'https://openai.example.test/gpt-6', 5),
        cs_item('Simon Willison', 'GPT-6 introduces a new reasoning mode', 'https://simon.example.test/2026/gpt-6', 3),
        cs_item('Hacker News', 'OpenAI introducing GPT-6 reasoning mode', 'https://news.example.test/item?id=1', 2, 300, 'dev'),
    ]);
    assert_same(1, count($stories));
    assert_same(['OpenAI', 'Simon Willison', 'Hacker News'], $stories[0]['sources']);
    assert_same('https://openai.example.test/gpt-6', $stories[0]['url'], 'the item seen first fronts the story');
    assert_same(300, $stories[0]['points']);
    assert_same(['ai', 'dev'], $stories[0]['topics']);
});

test('two unrelated stories form two clusters', function () {
    $stories = Clusterer::cluster([
        cs_item('PHP', 'PHP 8.5 released with the pipe operator', 'https://php.example.test/85'),
        cs_item('WebKit', 'Safari ships anchor positioning for CSS', 'https://webkit.example.test/anchor'),
    ]);
    assert_same(2, count($stories));
});

test('the same url with utm_* stripped is one cluster, whatever the titles say', function () {
    $stories = Clusterer::cluster([
        cs_item('Smashing', 'A guide to container queries', 'https://www.css.example.test/guide/?utm_source=rss&utm_medium=feed'),
        cs_item('Frontend Focus', 'This week: layouts', 'https://css.example.test/guide?utm_campaign=issue-700#top'),
    ]);
    assert_same(1, count($stories));
    assert_same('css.example.test/guide', Clusterer::canonical('https://www.css.example.test/guide/?utm_source=rss'));
    assert_same('css.example.test/guide?a=1&b=2', Clusterer::canonical('http://CSS.example.test/guide?b=2&utm_term=x&a=1'));
    assert_same(null, Clusterer::canonical('javascript:alert(1)'));
});

test('two items from one source count once', function () {
    $stories = Clusterer::cluster([
        cs_item('Laravel News', 'Laravel 13 released today', 'https://laravel.example.test/13'),
        cs_item('Laravel News', 'Laravel 13 released: upgrade notes', 'https://laravel.example.test/13?utm_source=twitter'),
    ]);
    assert_same(1, count($stories));
    assert_same(['Laravel News'], $stories[0]['sources']);
    assert_same(1, count($stories[0]['items']), 'a source gives a story one item');
    $one = Scorer::score($stories[0], CS_NOW);
    assert_same(Scorer::score(cs_story(1, 0, 1), CS_NOW), $one, 'scored as one source');
});

test('score rises with sources and points and falls with age, on fixed clocks', function () {
    assert_true(Scorer::score(cs_story(3, 0, 2), CS_NOW) > Scorer::score(cs_story(2, 0, 2), CS_NOW), 'more sources');
    assert_true(Scorer::score(cs_story(1, 400, 2), CS_NOW) > Scorer::score(cs_story(1, 40, 2), CS_NOW), 'more points');
    assert_true(Scorer::score(cs_story(2, 100, 2), CS_NOW) > Scorer::score(cs_story(2, 100, 2), CS_NOW + 6 * 3600), 'older');
    assert_true(Scorer::score(cs_story(2, 100, 1), CS_NOW) > Scorer::score(cs_story(2, 100, 30), CS_NOW), 'older story');
});

test('a future-dated item scores no higher than one seen at the same moment, and an old one never looks fresh', function () {
    $pdo = Db::open(':memory:');
    Db::migrate($pdo);
    $ins = $pdo->prepare('INSERT INTO news_items (id, source, topic, title, url, canon, published_at, points, first_seen, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $seen = CS_NOW - 2 * 3600;
    $ins->execute(['f', 'Future', 'ai', 'Dated a year ahead', 'https://f.example.test/', 'f.example.test', CS_NOW + 365 * 86400, null, $seen, $seen]);
    $ins->execute(['n', 'Plain', 'ai', 'Dated when seen', 'https://n.example.test/', 'n.example.test', $seen, null, $seen, $seen]);
    $ins->execute(['o', 'Old', 'ai', 'Dated last year', 'https://o.example.test/', 'o.example.test', CS_NOW - 365 * 86400, null, CS_NOW, CS_NOW]);
    $data = NewsApi::data(fn() => $pdo, ['ai'], '', 1, CS_NOW, 10);
    $scores = array_column($data['stories'], 'score', 'title');
    assert_true(isset($scores['Dated a year ahead'], $scores['Dated when seen']), 'both in the window');
    assert_true($scores['Dated a year ahead'] <= $scores['Dated when seen'], 'future date scored higher');
    assert_true(!isset($scores['Dated last year']), 'an old item seen just now is in the window');
});

test('an attacker feed with a long title and an earlier date takes over neither of two real stories', function () {
    $long = 'Introducing GPT-6 reasoning mode and PHP 8.5 released with pipe operator plus much more';
    $stories = Clusterer::cluster([
        cs_item('OpenAI', 'Introducing GPT-6 reasoning mode', 'https://openai.example.test/gpt-6', 3, null, 'ai', 3),
        cs_item('PHP', 'PHP 8.5 released with pipe operator', 'https://php.example.test/85', 3, null, 'php', 3),
        cs_item('Attacker', $long, 'https://evil.example.test/x', 400, 2000, 'ai', 2),
        cs_item('Simon Willison', 'GPT-6 introducing a reasoning mode', 'https://simon.example.test/gpt-6', 1, null, 'ai', 1),
        cs_item('Laravel News', 'PHP 8.5 released: the pipe operator', 'https://laravel.example.test/85', 1, null, 'php', 1),
    ]);
    $byUrl = array_column($stories, null, 'url');
    assert_same(3, count($stories));
    assert_same(['OpenAI', 'Simon Willison'], $byUrl['https://openai.example.test/gpt-6']['sources']);
    assert_same(['PHP', 'Laravel News'], $byUrl['https://php.example.test/85']['sources']);
    assert_same(['Attacker'], $byUrl['https://evil.example.test/x']['sources']);
    // Seen first, the attacker still fronts only its own story.
    $first = Clusterer::cluster([
        cs_item('Attacker', $long, 'https://evil.example.test/x', 400, 2000, 'ai', 9),
        cs_item('OpenAI', 'Introducing GPT-6 reasoning mode', 'https://openai.example.test/gpt-6', 3, null, 'ai', 3),
        cs_item('PHP', 'PHP 8.5 released with pipe operator', 'https://php.example.test/85', 3, null, 'php', 3),
    ]);
    assert_same(3, count($first));
});
